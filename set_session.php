<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';

// ensure cookie path and flags so other endpoints receive the session cookie
session_set_cookie_params(0, '/try/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
session_start();
session_regenerate_id(true);

$username = $_GET['user'] ?? '';
if ($username === '') {
    echo "<h2>Set Session</h2>";
    echo "<p>Usage: ?user=USERNAME (example: ?user=ZELLE)</p>";
    echo "<p>Available users:</p><ul>";
    $mysqli = getDbConnection();
    if ($mysqli) {
        $res = $mysqli->query('SELECT username FROM users ORDER BY id ASC');
        while ($row = $res->fetch_assoc()) {
            echo '<li>' . htmlspecialchars($row['username']) . '</li>';
        }
        $res->free();
        $mysqli->close();
    } else {
        echo '<li>DB unavailable</li>';
    }
    echo "</ul>";
    exit;
}

$mysqli = getDbConnection();
if (!$mysqli) {
    echo "DB connection failed.";
    exit;
}

$stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->bind_result($id);
if ($stmt->fetch()) {
    $_SESSION['username'] = $username;
    // ensure cookie is explicitly set for current path/domain
    setcookie(session_name(), session_id(), 0, '/try/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
    session_write_close();
    $stmt->close();
    $mysqli->close();
    header('Location: index.php');
    exit;
}
$stmt->close();
$mysqli->close();

echo "User not found: " . htmlspecialchars($username);
