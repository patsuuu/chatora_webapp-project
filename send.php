<?php
session_start();
header('Content-Type: application/json');

$payload = json_decode(file_get_contents('php://input'), true);
$author = isset($payload['author']) ? trim($payload['author']) : 'Guest';
$text = isset($payload['text']) ? trim($payload['text']) : '';
if (!empty($_SESSION['username'])) {
    $author = $_SESSION['username'];
}

if ($text === '') {
    echo json_encode(['success' => false, 'error' => 'Message is empty.']);
    exit;
}

$author = htmlspecialchars($author, ENT_QUOTES, 'UTF-8');
$text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$timestamp = time();
$message = ['author' => $author, 'text' => $text, 'timestamp' => $timestamp];

$file = __DIR__ . '/messages.json';
if (!file_exists($file)) {
    file_put_contents($file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$lock = fopen($file, 'c+');
if ($lock === false) {
    echo json_encode(['success' => false, 'error' => 'Unable to open storage.']);
    exit;
}

flock($lock, LOCK_EX);
$data = stream_get_contents($lock);
$items = [];
if ($data !== false && strlen(trim($data)) > 0) {
    $decoded = json_decode($data, true);
    if (is_array($decoded)) {
        $items = $decoded;
    }
}
$items[] = $message;
if (count($items) > 200) {
    $items = array_slice($items, -200);
}

rewind($lock);
ftruncate($lock, 0);
fwrite($lock, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
flock($lock, LOCK_UN);
fclose($lock);

echo json_encode(['success' => true]);
