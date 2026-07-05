<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';
$mysqli = getDbConnection();
if (!$mysqli) {
    echo "DB connection failed. See debug.log.";
    exit;
}

function fetchRows($mysqli, $sql, $limit = 10) {
    $res = $mysqli->query($sql);
    $out = [];
    if ($res) {
        $count = 0;
        while (($row = $res->fetch_assoc()) && $count++ < $limit) {
            $out[] = $row;
        }
        $res->free();
    }
    return $out;
}

echo "<h2>Admin Debug</h2>";

// Users
$users = fetchRows($mysqli, 'SELECT id, username, created_at FROM users ORDER BY id ASC', 50);
echo "<h3>Users (" . count($users) . ")</h3>";
if (empty($users)) echo "<p>No users found.</p>";
else echo '<pre>' . htmlspecialchars(json_encode($users, JSON_PRETTY_PRINT)) . '</pre>';

// Friends
$friends = fetchRows($mysqli, 'SELECT * FROM friends ORDER BY id DESC', 50);
echo "<h3>Friends (" . count($friends) . ")</h3>";
if (empty($friends)) echo "<p>No friends found.</p>";
else echo '<pre>' . htmlspecialchars(json_encode($friends, JSON_PRETTY_PRINT)) . '</pre>';

// Messages table
$messages = fetchRows($mysqli, 'SELECT id, sender_id, receiver_id, text, created_at FROM messages ORDER BY id ASC', 200);
echo "<h3>Messages (" . count($messages) . ")</h3>";
if (empty($messages)) echo "<p>No messages in DB.</p>";
else echo '<pre>' . htmlspecialchars(json_encode($messages, JSON_PRETTY_PRINT)) . '</pre>';

// messages.json file
$messagesFile = __DIR__ . '/messages.json';
if (file_exists($messagesFile)) {
    $data = file_get_contents($messagesFile);
    $decoded = json_decode($data, true);
    echo "<h3>messages.json (" . (is_array($decoded) ? count($decoded) : 'invalid') . ")</h3>";
    echo '<pre>' . htmlspecialchars(substr($data, 0, 10000)) . '</pre>';
} else {
    echo "<h3>messages.json</h3><p>File not found.</p>";
}

$mysqli->close();
