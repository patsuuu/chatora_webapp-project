<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

session_start();
$info = [];
$info['request_uri'] = $_SERVER['REQUEST_URI'] ?? null;
$info['cookies'] = $_COOKIE;
$info['session_username'] = $_SESSION['username'] ?? null;
$info['php_session_id'] = session_id();

$mysqli = getDbConnection();
if (!$mysqli) {
    $info['db'] = 'unavailable';
    echo json_encode($info, JSON_PRETTY_PRINT);
    exit;
}
$info['db'] = 'ok';

// Try to resolve current user id via function from db.php
$info['current_user_id'] = getCurrentUserId($mysqli);

// Also show a sample friends list lookup if authenticated
if ($info['current_user_id']) {
    $stmt = $mysqli->prepare('SELECT u.id, u.username FROM friends f JOIN users u ON f.friend_id = u.id WHERE f.user_id = ? ORDER BY u.username ASC');
    $stmt->bind_param('i', $info['current_user_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $friends = [];
    while ($row = $res->fetch_assoc()) {
        $friends[] = $row;
    }
    $info['friends_sample'] = $friends;
    $stmt->close();
}

$mysqli->close();

echo json_encode($info, JSON_PRETTY_PRINT);
