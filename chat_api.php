<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$mysqli = getDbConnection();
if (!$mysqli) {
    echo json_encode(['success' => false, 'error' => 'Unable to connect to the database.']);
    exit;
}

$currentUserId = getCurrentUserId($mysqli);
if (!$currentUserId) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

// Release session lock early so concurrent AJAX requests aren't blocked
if (function_exists('session_write_close')) {
    session_write_close();
}

function debugLog($message) {
    $msg = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/debug.log', $msg, FILE_APPEND);
}

touchUserActivity($mysqli, $currentUserId);

$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true);
$payload = is_array($body) ? $body : $_POST;
$action = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $payload['action'] ?? $_POST['action'] ?? null;
} else {
    $action = $_GET['action'] ?? null;
}

debugLog('chat_api action=' . ($action ?? 'null') . ' method=' . $_SERVER['REQUEST_METHOD'] . ' rawInput=' . substr($rawInput, 0, 500));

if ($action === 'messages') {
    $otherId = isset($_GET['otherId']) ? (int) $_GET['otherId'] : 0;
    if ($otherId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid chat partner.']);
        exit;
    }

    $stmt = $mysqli->prepare(
        'SELECT m.id, s.username AS sender, r.username AS receiver, m.text, m.created_at,
         i.filename, i.mime_type, i.data
         FROM messages m
         JOIN users s ON m.sender_id = s.id
         JOIN users r ON m.receiver_id = r.id
         LEFT JOIN images i ON m.id = i.message_id
         LEFT JOIN message_deletions md ON md.message_id = m.id AND md.user_id = ?
         WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
           AND md.message_id IS NULL
         ORDER BY m.created_at ASC'
    );
    $stmt->bind_param('iiiii', $currentUserId, $currentUserId, $otherId, $otherId, $currentUserId);
    $stmt->execute();
    $stmt->bind_result($id, $sender, $receiver, $text, $created_at, $imageFilename, $imageMime, $imageData);

    $messages = [];
    while ($stmt->fetch()) {
        $message = [
            'id' => $id,
            'sender' => $sender,
            'receiver' => $receiver,
            'text' => $text,
            'created_at' => $created_at,
        ];
        if ($imageFilename !== null && $imageMime !== null && $imageData !== null) {
            $message['image'] = [
                'filename' => $imageFilename,
                'mime' => $imageMime,
                'data' => base64_encode($imageData),
            ];
        }
        $messages[] = $message;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'delete') {
    $messageId = isset($payload['messageId']) ? (int) $payload['messageId'] : 0;
    $mode = $payload['mode'] ?? '';

    if ($messageId <= 0 || !in_array($mode, ['me', 'everyone'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid delete request.']);
        exit;
    }

    if ($mode === 'me') {
        $stmt = $mysqli->prepare('INSERT INTO message_deletions (message_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE deleted_at = NOW()');
        $stmt->bind_param('ii', $messageId, $currentUserId);
        if (!$stmt->execute()) {
            $stmt->close();
            echo json_encode(['success' => false, 'error' => 'Unable to delete message for you.']);
            exit;
        }
        $stmt->close();
        echo json_encode(['success' => true]);
        exit;
    }

    // Delete for everyone only allowed for sender
    $stmt = $mysqli->prepare('SELECT sender_id FROM messages WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $stmt->bind_result($senderId);
    if (!$stmt->fetch()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Message not found.']);
        exit;
    }
    $stmt->close();

    // Allow admin to delete any message for everyone
    $isAdmin = isAdminUser($mysqli, $currentUserId);
    if ($senderId !== $currentUserId && !$isAdmin) {
        echo json_encode(['success' => false, 'error' => 'You can only delete your own message for everyone.']);
        exit;
    }

    // remove associated image row if exists
    $stmt = $mysqli->prepare('DELETE FROM images WHERE message_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $mysqli->prepare('DELETE FROM messages WHERE id = ?');
    $stmt->bind_param('i', $messageId);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Unable to delete message for everyone.']);
        exit;
    }
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'unread') {
    $stmt = $mysqli->prepare(
        'SELECT sender_id, COUNT(*) AS count FROM messages WHERE receiver_id = ? AND is_read = 0 GROUP BY sender_id'
    );
    $stmt->bind_param('i', $currentUserId);
    $stmt->execute();
    $stmt->bind_result($senderId, $count);

    $unread = [];
    while ($stmt->fetch()) {
        $unread[] = ['friendId' => $senderId, 'count' => $count];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'unread' => $unread]);
    exit;
}

if ($action === 'mark_read') {
    $otherId = isset($payload['otherId']) ? (int) $payload['otherId'] : 0;
    if ($otherId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid chat partner.']);
        exit;
    }

    $stmt = $mysqli->prepare('UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0');
    $stmt->bind_param('ii', $currentUserId, $otherId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'send') {
    $text = trim($payload['text'] ?? '');
    $otherId = isset($payload['otherId']) ? (int) $payload['otherId'] : 0;

    if ($otherId <= 0 || $text === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid message payload.']);
        exit;
    }

    // Ensure the other user is a friend, unless current user is admin
    $isAdmin = isAdminUser($mysqli, $currentUserId);
    if (!$isAdmin) {
        $stmt = $mysqli->prepare('SELECT 1 FROM friends WHERE user_id = ? AND friend_id = ? LIMIT 1');
        $stmt->bind_param('ii', $currentUserId, $otherId);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 0) {
            $stmt->close();
            echo json_encode(['success' => false, 'error' => 'You can only message friends.']);
            exit;
        }
        $stmt->close();
    }

    $stmt = $mysqli->prepare('INSERT INTO messages (sender_id, receiver_id, text) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $currentUserId, $otherId, $text);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Unable to send message.']);
        exit;
    }
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'send_image') {
    $otherId = isset($_POST['otherId']) ? (int) $_POST['otherId'] : 0;
    if ($otherId <= 0 || !isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Invalid image upload.']);
        exit;
    }

    $image = $_FILES['image'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($image['type'], $allowedTypes, true)) {
        echo json_encode(['success' => false, 'error' => 'Unsupported image type.']);
        exit;
    }

    if ($image['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Image is too large. Maximum 5MB.']);
        exit;
    }

    // Ensure the other user is a friend, unless current user is admin
    $isAdmin = isAdminUser($mysqli, $currentUserId);
    if (!$isAdmin) {
        $stmt = $mysqli->prepare('SELECT 1 FROM friends WHERE user_id = ? AND friend_id = ? LIMIT 1');
        $stmt->bind_param('ii', $currentUserId, $otherId);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 0) {
            $stmt->close();
            echo json_encode(['success' => false, 'error' => 'You can only message friends.']);
            exit;
        }
        $stmt->close();
    }

    $imageData = file_get_contents($image['tmp_name']);
    $filename = basename($image['name']);
    $mimeType = $image['type'];

    $stmt = $mysqli->prepare('INSERT INTO messages (sender_id, receiver_id, text) VALUES (?, ?, "")');
    $stmt->bind_param('ii', $currentUserId, $otherId);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Unable to create message.']);
        exit;
    }
    $messageId = $stmt->insert_id;
    $stmt->close();

    $stmt = $mysqli->prepare('INSERT INTO images (message_id, filename, mime_type, data) VALUES (?, ?, ?, ?)');
    $null = null;
    $stmt->bind_param('issb', $messageId, $filename, $mimeType, $null);
    $stmt->send_long_data(3, $imageData);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Unable to store image.']);
        exit;
    }
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action.']);
