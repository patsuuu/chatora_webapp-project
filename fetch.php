<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

$file = __DIR__ . '/messages.json';
if (!file_exists($file)) {
    echo json_encode([]);
    exit;
}

$data = file_get_contents($file);
if ($data === false) {
    echo json_encode([]);
    exit;
}

$messages = json_decode($data, true);
if (!is_array($messages)) {
    echo json_encode([]);
    exit;
}

// Keep the latest 200 messages and sort by timestamp if needed.
usort($messages, function ($a, $b) {
    return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
});

echo json_encode($messages, JSON_UNESCAPED_UNICODE);
