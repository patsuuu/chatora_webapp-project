<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';

echo "<h2>DB Connection Test</h2>";
$mysqli = getDbConnection();
if (!$mysqli) {
    echo "<p style='color:red'>DB connection failed. See debug.log below (if present):</p>";
    $log = @file_get_contents(__DIR__ . '/debug.log');
    if ($log === false) {
        echo "<p>No debug.log found or not readable.</p>";
    } else {
        echo '<pre>' . htmlspecialchars($log) . '</pre>';
    }
    echo "<p>Check your credentials in <strong>db.php</strong> and InfinityFree control panel.</p>";
    exit;
}

echo "<p style='color:green'>DB connection successful.</p>";
echo "<p>MySQL server info: " . htmlspecialchars($mysqli->server_info) . "</p>";
echo "<p>Using database: " . htmlspecialchars(DB_NAME) . "</p>";

$res = $mysqli->query('SHOW TABLES');
if ($res) {
    echo "<p>Tables in database:</p><ul>";
    while ($row = $res->fetch_array()) {
        echo '<li>' . htmlspecialchars($row[0]) . '</li>';
    }
    echo "</ul>";
} else {
    echo "<p>Unable to list tables: " . htmlspecialchars($mysqli->error) . "</p>";
}

$mysqli->close();

echo "<p>Also test session persistence by opening <a href=\"session_test.php\">session_test.php</a>.</p>";
