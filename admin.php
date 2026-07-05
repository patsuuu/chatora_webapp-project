<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['username']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo '<h2>Access denied</h2><p>Admin access only.</p>';
    exit;
}

$mysqli = getDbConnection();
if (!$mysqli) {
    die('Database connection failed.');
}

$currentUserId = getCurrentUserId($mysqli);
if (!$currentUserId || !isAdminUser($mysqli, $currentUserId)) {
    http_response_code(403);
    echo '<h2>Access denied</h2><p>Admin access only.</p>';
    exit;
}

$search = trim($_GET['search'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$export = $_GET['export'] ?? '';

$deleteSuccess = false;
$deleteError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
  $messageId = (int) $_POST['delete_message'];
  $isAjax = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
    || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

  if ($messageId <= 0) {
    $deleteError = 'Invalid message selected for deletion.';
  } else {
    // remove any associated image row first
    $stmt = $mysqli->prepare('DELETE FROM images WHERE message_id = ?');
    if ($stmt) {
      $stmt->bind_param('i', $messageId);
      $stmt->execute();
      $stmt->close();
    }

    $stmt = $mysqli->prepare('DELETE FROM messages WHERE id = ?');
    if ($stmt) {
      $stmt->bind_param('i', $messageId);
      if ($stmt->execute()) {
        $deleteSuccess = true;
      } else {
        $deleteError = 'Unable to delete the selected message.';
      }
      $stmt->close();
    } else {
      $deleteError = 'Unable to prepare message deletion.';
    }
  }

  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $deleteSuccess, 'error' => $deleteError]);
    exit;
  }

  if ($deleteSuccess) {
    $queryString = http_build_query(array_filter([
      'search' => $search,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'status' => 'deleted'
    ], fn($value) => $value !== '' && $value !== null));
    header('Location: admin.php' . ($queryString ? '?' . $queryString : ''));
    exit;
  }
}

function fetchRows($mysqli, $sql) {
    $result = $mysqli->query($sql);
    if (!$result) {
        return [];
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
    return $rows;
}

$users = fetchRows($mysqli, "SELECT id, username, role, is_online, last_active, created_at FROM users ORDER BY id ASC");
$messages = fetchRows($mysqli, "SELECT m.id, m.sender_id, m.receiver_id, s.username AS sender_name, r.username AS receiver_name, m.text, m.created_at, i.filename AS image_filename, i.mime_type AS image_mime, i.data AS image_data FROM messages m JOIN users s ON s.id = m.sender_id JOIN users r ON r.id = m.receiver_id LEFT JOIN images i ON i.message_id = m.id ORDER BY m.created_at DESC LIMIT 500");
foreach ($messages as &$message) {
    if (!empty($message['image_data'])) {
        $message['image_data'] = base64_encode($message['image_data']);
    }
    if (!empty($message['created_at'])) {
        try {
            $message['created_at'] = (new DateTime($message['created_at'], new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        } catch (Exception $e) {
            $message['created_at'] = (string) $message['created_at'];
        }
    }
}
unset($message);

// Provide a JSON endpoint for polling (AJAX)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['success' => true, 'users' => $users, 'messages' => $messages]);
  exit;
}

if ($search !== '') {
    $term = strtolower($search);
    $users = array_values(array_filter($users, static function ($user) use ($term): bool {
        return strpos(strtolower((string) $user['username']), $term) !== false || strpos((string) $user['id'], $term) !== false;
    }));

    $messages = array_values(array_filter($messages, static function ($message) use ($term): bool {
        return strpos(strtolower((string) $message['sender_name']), $term) !== false
            || strpos(strtolower((string) $message['receiver_name']), $term) !== false
            || strpos(strtolower((string) $message['text']), $term) !== false
            || strpos((string) $message['id'], $term) !== false;
    }));
}

if ($dateFrom !== '') {
    $start = strtotime($dateFrom . ' 00:00:00');
    $messages = array_values(array_filter($messages, static function ($message) use ($start): bool {
        return strtotime($message['created_at']) >= $start;
    }));
}

if ($dateTo !== '') {
    $end = strtotime($dateTo . ' 23:59:59');
    $messages = array_values(array_filter($messages, static function ($message) use ($end): bool {
        return strtotime($message['created_at']) <= $end;
    }));
}

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=chat-admin-messages.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'from', 'to', 'message', 'created_at']);
    foreach ($messages as $message) {
        fputcsv($out, [(int) $message['id'], $message['sender_name'], $message['receiver_name'], $message['text'], $message['created_at']]);
    }
    fclose($out);
    exit;
}

if ($export === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=chat-admin-messages.json');
    echo json_encode($messages, JSON_PRETTY_PRINT);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard</title>
  <style>
    :root {
      color-scheme: light;
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      color: #111827;
      background: #eef2ff;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: linear-gradient(180deg, #eef2ff 0%, #f9fafb 100%);
      color: #111827;
    }

    a {
      color: #2563eb;
      text-decoration: none;
    }

    a:hover {
      text-decoration: underline;
    }

    button {
      font: inherit;
      cursor: pointer;
    }

    .wrap {
      width: min(1280px, calc(100% - 32px));
      margin: 0 auto;
      padding: 28px 0 48px;
    }

    .topbar {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      margin-bottom: 24px;
    }

    .topbar h1 {
      margin: 0 0 8px;
      font-size: 2rem;
      line-height: 1.1;
    }

    .topbar p {
      margin: 0;
      color: #475569;
      max-width: 680px;
      line-height: 1.6;
    }

    .card {
      background: #ffffff;
      border: 1px solid rgba(148, 163, 184, 0.16);
      border-radius: 20px;
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
    }

    .card h2 {
      margin: 0 0 18px;
      font-size: 1.15rem;
      color: #0f172a;
    }

    .filters {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      align-items: end;
      margin-bottom: 16px;
    }

    .filters label {
      display: grid;
      gap: 6px;
      font-size: 0.95rem;
      color: #334155;
    }

    .filters input,
    .filters button,
    .filters a {
      width: 100%;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid #cbd5e1;
      background: #ffffff;
      color: #0f172a;
    }

    .filters button,
    .filters a {
      border-color: transparent;
      background: #2563eb;
      color: #ffffff;
      transition: background-color 0.2s ease;
    }

    .filters button:hover,
    .filters a:hover {
      background: #1d4ed8;
    }

    .filters a.secondary {
      background: #475569;
    }

    .count {
      color: #475569;
      font-size: 0.9rem;
      margin-bottom: 12px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 640px;
    }

    th,
    td {
      padding: 14px 16px;
      border-bottom: 1px solid #e2e8f0;
      text-align: left;
      vertical-align: middle;
    }

    th {
      background: #f8fafc;
      color: #334155;
      font-size: 0.9rem;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      font-weight: 700;
    }

    tbody tr:hover {
      background: #f8fafc;
    }

    td {
      color: #475569;
      font-size: 0.95rem;
    }

    .pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 700;
      letter-spacing: 0.01em;
      text-transform: capitalize;
    }

    .admin {
      background: #d1fae5;
      color: #065f46;
    }

    .user {
      background: #dbeafe;
      color: #1d4ed8;
    }

    .online {
      background: #dcfce7;
      color: #166534;
    }

    .offline {
      background: #e2e8f0;
      color: #475569;
    }

    .muted {
      color: #64748b;
    }

    .admin-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 12px 18px;
      border-radius: 14px;
      background: #2563eb;
      color: #ffffff;
      font-weight: 700;
      transition: background 0.2s ease;
      text-decoration: none;
    }

    .admin-link:hover {
      background: #1d4ed8;
    }

    .export-links {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      justify-content: flex-start;
      margin-bottom: 12px;
    }

    .export-links a {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 12px 16px;
      border-radius: 14px;
      background: #2563eb;
      color: #ffffff;
      text-decoration: none;
      font-weight: 700;
      transition: background 0.2s ease;
    }

    .export-links a.secondary {
      background: #475569;
    }

    .export-links a:hover {
      background: #1d4ed8;
    }

    .status-banner {
      padding: 16px 20px;
      border-radius: 16px;
      margin-bottom: 20px;
      font-weight: 600;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    }

    .status-banner.hidden {
      display: none;
    }

    .status-banner.success {
      background: #d1fae5;
      color: #065f46;
      border: 1px solid #86efac;
    }

    .status-banner.error {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fca5a5;
    }

    .message-image img {
      max-width: 220px;
      max-height: 160px;
      border-radius: 14px;
      border: 1px solid #cbd5e1;
      object-fit: cover;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
    }

    .delete-button {
      width: 100%;
      border: none;
      background: #ef4444;
      color: #ffffff;
      border-radius: 12px;
      padding: 10px 14px;
      font-weight: 700;
      transition: background-color 0.2s ease;
    }

    .delete-button:hover {
      background: #dc2626;
    }

    .delete-form {
      margin: 0;
    }

    .table-container {
      overflow-x: auto;
    }

    .admin-chat-panel {
      display: grid;
      grid-template-columns: 280px minmax(0, 1fr);
      gap: 20px;
      margin-top: 24px;
    }

    .admin-chat-panel .chat-sidebar {
      border: 1px solid rgba(148, 163, 184, 0.16);
      border-radius: 20px;
      background: #f8fafc;
      padding: 16px;
      max-height: 560px;
      overflow-y: auto;
    }

    .admin-chat-panel .chat-sidebar h3 {
      margin: 0 0 12px;
      font-size: 1rem;
      color: #0f172a;
    }

    .admin-chat-panel .chat-user-row {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 10px;
      align-items: center;
      padding: 12px;
      border-radius: 14px;
      border: 1px solid transparent;
      margin-bottom: 10px;
      transition: background-color 0.2s ease, border-color 0.2s ease;
      cursor: pointer;
      background: #ffffff;
    }

    .admin-chat-panel .chat-user-row:hover {
      border-color: #cbd5e1;
      background: #eff6ff;
    }

    .admin-chat-panel .chat-user-row.selected {
      border-color: #2563eb;
      background: #e0e7ff;
    }

    .admin-chat-panel .chat-user-row .user-title {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .admin-chat-panel .chat-user-row .user-title strong {
      font-size: 0.95rem;
      color: #0f172a;
    }

    .admin-chat-panel .chat-user-row .user-title span {
      font-size: 0.85rem;
      color: #475569;
    }

    .admin-chat-panel .chat-window {
      border: 1px solid rgba(148, 163, 184, 0.16);
      border-radius: 20px;
      background: #ffffff;
      display: flex;
      flex-direction: column;
      min-height: 560px;
    }

    .admin-chat-panel .chat-window-header {
      padding: 18px 20px;
      border-bottom: 1px solid #e2e8f0;
      font-size: 1rem;
      font-weight: 700;
      color: #0f172a;
    }

    .admin-chat-panel .chat-window-body {
      flex: 1;
      padding: 20px;
      overflow-y: auto;
      display: grid;
      gap: 12px;
      background: #f8fafc;
    }

    .admin-chat-panel .chat-message {
      padding: 14px 16px;
      border-radius: 16px;
      background: #ffffff;
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
    }

    .admin-chat-panel .chat-message .message-meta {
      margin-bottom: 8px;
      color: #64748b;
      font-size: 0.85rem;
    }

    .admin-chat-panel .chat-window-footer {
      padding: 16px 20px;
      border-top: 1px solid #e2e8f0;
      background: #ffffff;
    }

    .admin-chat-panel .chat-window-footer form {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 12px;
    }

    .admin-chat-panel .chat-window-footer input {
      width: 100%;
      padding: 14px 16px;
      border-radius: 14px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #0f172a;
      font-size: 0.95rem;
    }

    .admin-chat-panel .chat-window-footer button {
      padding: 14px 18px;
      border-radius: 14px;
      border: none;
      background: #2563eb;
      color: #ffffff;
      font-weight: 700;
      transition: background 0.2s ease;
    }

    .admin-chat-panel .chat-window-footer button:hover {
      background: #1d4ed8;
    }

    @media (max-width: 860px) {
      .filters {
        grid-template-columns: 1fr;
      }

      .topbar {
        align-items: start;
      }

      .card {
        padding: 20px;
      }

      table {
        min-width: 0;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div>
        <h1>Admin Dashboard</h1>
        <p class="muted">View created accounts, search users, filter messages by date, and export conversations.</p>
      </div>
      <a href="index.php">Back to chat</a>
    </div>

    <div id="admin-status-banner" class="status-banner hidden"></div>

    <?php if (!empty($_GET['status']) && $_GET['status'] === 'deleted'): ?>
      <div class="card" style="border-left:4px solid #16a34a; background: #dcfce7; color: #166534; margin-bottom: 24px;">
        <p style="margin:0; padding:16px;">Message deleted successfully.</p>
      </div>
    <?php endif; ?>

    <?php if (!empty($deleteError)): ?>
      <div class="card" style="border-left:4px solid #ef4444; background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
        <p style="margin:0; padding:16px;"><?= htmlspecialchars($deleteError) ?></p>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Accounts</h2>
      <form class="filters" method="get">
        <label>
          Search account
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="username or ID" />
        </label>
        <label>
          From
          <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" />
        </label>
        <label>
          To
          <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" />
        </label>
        <button type="submit">Apply</button>
        <a class="secondary" href="admin.php">Reset</a>
      </form>
      <div class="count">Showing <?= count($users) ?> account(s)</div>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Role</th>
            <th>Status</th>
            <th>Last Active</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody id="users-tbody">
          <?php foreach ($users as $user): ?>
            <tr>
              <td><?= (int) $user['id'] ?></td>
              <td><?= htmlspecialchars($user['username']) ?></td>
              <td><span class="pill <?= $user['role'] === 'admin' ? 'admin' : 'user' ?>"><?= htmlspecialchars($user['role'] ?: 'user') ?></span></td>
              <td><span class="pill <?= (int) $user['is_online'] ? 'online' : 'offline' ?>"><?= (int) $user['is_online'] ? 'Online' : 'Offline' ?></span></td>
              <td><?= htmlspecialchars($user['last_active'] ?: '-') ?></td>
              <td><?= htmlspecialchars($user['created_at'] ?: '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>Admin Chat</h2>
      <div class="admin-chat-panel">
        <div class="chat-sidebar">
          <h3>Available Users</h3>
          <div id="admin-user-list">
            <div class="muted">Select a user from the Accounts list above or any Chat button to start chatting.</div>
          </div>
        </div>
        <div class="chat-window">
          <div class="chat-window-header">Chat with <span id="admin-chat-username">no one selected</span></div>
          <div id="admin-chat-body" class="chat-window-body">
            <div class="muted">Select a user to see the conversation and send messages.</div>
          </div>
          <div class="chat-window-footer">
            <form id="admin-chat-form">
              <input id="admin-message-input" type="text" placeholder="Type a message..." disabled />
              <button id="admin-send-button" type="submit" disabled>Send</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Recent Messages</h2>
      <div class="filters">
        <a href="admin.php?search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&export=csv">Export CSV</a>
        <a class="secondary" href="admin.php?search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&export=json">Export JSON</a>
      </div>
      <div class="count">Showing <?= count($messages) ?> message(s)</div>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>From</th>
            <th>To</th>
            <th>Message</th>
            <th>Image</th>
            <th>Time</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="messages-tbody">
          <?php foreach ($messages as $message): ?>
            <tr>
              <td><?= (int) $message['id'] ?></td>
              <td><?= htmlspecialchars($message['sender_name']) ?></td>
              <td><?= htmlspecialchars($message['receiver_name']) ?></td>
              <td><?= nl2br(htmlspecialchars($message['text'])) ?></td>
              <td>
                <?php if (!empty($message['image_data'])): ?>
                  <div class="message-image">
                    <img src="data:<?= htmlspecialchars($message['image_mime']) ?>;base64,<?= htmlspecialchars($message['image_data']) ?>" alt="<?= htmlspecialchars($message['image_filename']) ?>" />
                  </div>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
              <td class="admin-created-at" data-created-at="<?= htmlspecialchars($message['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message['created_at']) ?></td>
              <td>
                <button type="button" class="delete-button delete-message-button" data-message-id="<?= (int) $message['id'] ?>">Delete (Everyone)</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <script>
    (function() {
      const banner = document.getElementById('admin-status-banner');
      const usersTbody = document.getElementById('users-tbody');
      const messagesTbody = document.getElementById('messages-tbody');
      const adminChatUserName = document.getElementById('admin-chat-username');
      const adminChatBody = document.getElementById('admin-chat-body');
      const adminMessageInput = document.getElementById('admin-message-input');
      const adminSendButton = document.getElementById('admin-send-button');
      const adminChatForm = document.getElementById('admin-chat-form');
      const adminUserList = document.getElementById('admin-user-list');
      const currentAdminId = <?= (int) $currentUserId ?>;
      let selectedChatUserId = null;
      let selectedChatUsername = '';

      function showBanner(message, type) {
        if (!banner) return;
        banner.textContent = message;
        banner.className = 'status-banner ' + type;
        banner.hidden = false;
        window.setTimeout(() => {
          banner.hidden = true;
        }, 5000);
      }

      function escapeHtml(s) {
        return String(s)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function formatMessageTimestamp(value) {
        if (!value) return '';
        const numericValue = Number(value);
        const date = Number.isFinite(numericValue)
          ? new Date(numericValue < 1e12 ? numericValue * 1000 : numericValue)
          : new Date(value);

        if (Number.isNaN(date.getTime())) {
          const fallbackDate = new Date(String(value).replace(' ', 'T'));
          if (Number.isNaN(fallbackDate.getTime())) {
            return String(value);
          }
          return formatDateParts(fallbackDate);
        }

        return formatDateParts(date);
      }

      function formatDateParts(date) {
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${day}/${month}/${year} ${hours}:${minutes}`;
      }

      function renderUsers(users) {
        if (!usersTbody || !adminUserList) return;

        usersTbody.innerHTML = users.map(u => {
          const roleClass = (u.role === 'admin') ? 'admin' : 'user';
          const statusClass = (parseInt(u.is_online, 10) ? 'online' : 'offline');
          return `
            <tr class="admin-user-row${selectedChatUserId === parseInt(u.id, 10) ? ' selected' : ''}" data-user-id="${escapeHtml(u.id)}" data-user-name="${escapeHtml(u.username)}">
              <td>${escapeHtml(u.id)}</td>
              <td>${escapeHtml(u.username)}</td>
              <td><span class="pill ${roleClass}">${escapeHtml(u.role || 'user')}</span></td>
              <td><span class="pill ${statusClass}">${parseInt(u.is_online,10) ? 'Online' : 'Offline'}</span></td>
              <td>${escapeHtml(u.last_active || '-')}</td>
              <td>${escapeHtml(u.created_at || '-')}</td>
            </tr>`;
        }).join('');

        adminUserList.innerHTML = users.map(u => {
          const isSelf = parseInt(u.id, 10) === currentAdminId;
          return `
            <div class="chat-user-row${selectedChatUserId === parseInt(u.id, 10) ? ' selected' : ''}" data-user-id="${escapeHtml(u.id)}" data-user-name="${escapeHtml(u.username)}">
              <div class="user-title">
                <strong>${escapeHtml(u.username)}</strong>
                <span>${isSelf ? 'Admin account' : `Status: ${parseInt(u.is_online,10) ? 'Online' : 'Offline'}`}</span>
              </div>
              <button type="button">${isSelf ? 'Me' : 'Chat'}</button>
            </div>`;
        }).join('');
      }

      function renderRecentMessages(messages) {
        if (!messagesTbody) return;
        messagesTbody.innerHTML = messages.map(m => {
          const sender = escapeHtml(m.sender_name || m.sender || 'Unknown');
          const receiver = escapeHtml(m.receiver_name || m.receiver || 'Unknown');
          const imageData = (m.image && m.image.data) ? m.image.data : m.image_data;
          const imageMime = (m.image && m.image.mime) ? m.image.mime : m.image_mime;
          const imageFilename = (m.image && m.image.filename) ? m.image.filename : m.image_filename;
          const imageHtml = imageData ? `<div class="message-image"><img src="data:${escapeHtml(imageMime)};base64,${escapeHtml(imageData)}" alt="${escapeHtml(imageFilename)}" /></div>` : '<span class="muted">-</span>';
          const createdAt = escapeHtml(m.created_at || '');
          return `
            <tr>
              <td>${escapeHtml(m.id)}</td>
              <td>${sender}</td>
              <td>${receiver}</td>
              <td>${escapeHtml(m.text).replace(/\n/g, '<br/>')}</td>
              <td>${imageHtml}</td>
              <td class="admin-created-at" data-created-at="${createdAt}">${escapeHtml(formatMessageTimestamp(m.created_at || ''))}</td>
              <td><button type="button" class="delete-button delete-message-button" data-message-id="${escapeHtml(m.id)}">Delete</button></td>
            </tr>`;
        }).join('');
      }

      async function pollAdmin() {
        try {
          const resp = await fetch('admin.php?ajax=1', { cache: 'no-store', credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
          if (!resp.ok) return;
          const data = await resp.json();
          if (!data.success) return;
          renderUsers(data.users || []);
          renderRecentMessages(data.messages || []);

          if (selectedChatUserId) {
            await loadAdminConversation(selectedChatUserId);
          }
        } catch (e) {
          console.error('Admin poll failed', e);
        }
      }

      async function loadAdminConversation(userId) {
        if (!userId) return;
        try {
          const resp = await fetch('api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'messages', otherId: userId }),
          });
          if (!resp.ok) throw new Error('Unable to load conversation.');
          const data = await resp.json();
          if (!data.success) throw new Error(data.error || 'Unable to load conversation.');
          renderAdminChatMessages(data.messages || []);
        } catch (error) {
          console.error(error);
          renderAdminChatMessages([]);
        }
      }

      function renderAdminChatMessages(messages) {
        if (!adminChatBody) return;
        if (!Array.isArray(messages) || messages.length === 0) {
          adminChatBody.innerHTML = '<div class="muted">No conversation yet. Send the first message.</div>';
          return;
        }
        adminChatBody.innerHTML = messages.map(msg => {
          const sender = escapeHtml(msg.sender || msg.sender_name || 'Unknown');
          const time = escapeHtml(msg.created_at || '');
          const text = escapeHtml(msg.text || '');
          const imageData = (msg.image && msg.image.data) ? msg.image.data : msg.image_data;
          const imageMime = (msg.image && msg.image.mime) ? msg.image.mime : msg.image_mime;
          const imageFilename = (msg.image && msg.image.filename) ? msg.image.filename : msg.image_filename;
          const imageHtml = imageData ? `<div class="message-image"><img src="data:${escapeHtml(imageMime)};base64,${escapeHtml(imageData)}" alt="${escapeHtml(imageFilename)}" /></div>` : '';
          const formattedTime = formatMessageTimestamp(msg.created_at || '');
          return `
            <div class="chat-message">
              <div class="message-meta"><strong>${sender}</strong> · ${escapeHtml(formattedTime || time)}</div>
              <div>${text || '<span class="muted">(No message content)</span>'}</div>
              ${imageHtml}
            </div>`;
        }).join('');
      }

      function updateAdminChatState() {
        const enabled = selectedChatUserId !== null;
        adminMessageInput.disabled = !enabled;
        adminSendButton.disabled = !enabled;
        adminChatUserName.textContent = enabled ? selectedChatUsername : 'no one selected';
        if (!enabled) {
          adminChatBody.innerHTML = '<div class="muted">Select a user to see the conversation and send messages.</div>';
        }
      }

      function selectChatUser(userId, username) {
        selectedChatUserId = userId;
        selectedChatUsername = username;
        updateAdminChatState();
        highlightSelectedUserRow();
        loadAdminConversation(userId);
      }

      function highlightSelectedUserRow() {
        if (usersTbody) {
          usersTbody.querySelectorAll('.admin-user-row').forEach(row => {
            const rowUserId = parseInt(row.dataset.userId, 10);
            row.classList.toggle('selected', rowUserId === selectedChatUserId);
          });
        }
        if (adminUserList) {
          adminUserList.querySelectorAll('.chat-user-row').forEach(row => {
            const rowUserId = parseInt(row.dataset.userId, 10);
            row.classList.toggle('selected', rowUserId === selectedChatUserId);
          });
        }
      }

      async function sendAdminMessage(text) {
        if (!selectedChatUserId || !text.trim()) return;
        try {
          const resp = await fetch('api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send', otherId: selectedChatUserId, text: text.trim() }),
          });
          if (!resp.ok) throw new Error('Unable to send message.');
          const result = await resp.json();
          if (!result.success) throw new Error(result.error || 'Unable to send message.');
          adminMessageInput.value = '';
          await loadAdminConversation(selectedChatUserId);
        } catch (error) {
          console.error(error);
          showBanner(error.message || 'Unable to send message.', 'error');
        }
      }

      document.addEventListener('click', async (ev) => {
        const row = ev.target.closest('.admin-user-row');
        if (row && row.dataset.userId) {
          const userId = parseInt(row.dataset.userId, 10);
          const username = row.dataset.userName || '';
          if (userId && userId !== currentAdminId) {
            selectChatUser(userId, username);
          }
          return;
        }

        const button = ev.target.closest('.chat-user-row button');
        if (button) {
          const parent = button.closest('.chat-user-row');
          if (parent && parent.dataset.userId) {
            const userId = parseInt(parent.dataset.userId, 10);
            const username = parent.dataset.userName || '';
            if (userId && userId !== currentAdminId) {
              selectChatUser(userId, username);
            }
          }
          return;
        }

        const deleteBtn = ev.target.closest('.delete-message-button');
        if (!deleteBtn) return;
        const messageId = deleteBtn.dataset.messageId;
        if (!messageId || !confirm('Delete this message permanently?')) return;

        deleteBtn.disabled = true;
        const originalText = deleteBtn.textContent;
        deleteBtn.textContent = 'Deleting...';

        try {
          const formData = new FormData();
          formData.append('delete_message', messageId);
          formData.append('ajax', '1');

          const res = await fetch('admin.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
          });
          const json = await res.json();
          if (!res.ok || !json.success) {
            showBanner(json.error || 'Unable to delete message.', 'error');
            deleteBtn.disabled = false;
            deleteBtn.textContent = originalText;
            return;
          }

          showBanner('Message deleted successfully.', 'success');
          const row = deleteBtn.closest('tr');
          if (row) row.remove();
        } catch (err) {
          showBanner(err.message || 'Unable to delete message.', 'error');
          deleteBtn.disabled = false;
          deleteBtn.textContent = originalText;
        }
      });

      adminChatForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        await sendAdminMessage(adminMessageInput.value);
      });

      document.querySelectorAll('.admin-created-at').forEach((cell) => {
        const value = cell.getAttribute('data-created-at');
        if (value) {
          cell.textContent = formatMessageTimestamp(value);
        }
      });

      updateAdminChatState();
      pollAdmin();
      setInterval(pollAdmin, 5000);
    })();
  </script>
</body>
</html>
