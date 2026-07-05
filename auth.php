<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$mysqli = getDbConnection();
if (!$mysqli) {
    echo json_encode(['success' => false, 'error' => 'Unable to connect to the database.']);
    exit;
}

$currentUserId = getCurrentUserId($mysqli);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'status') {
    echo json_encode([
        'success' => true,
        'loggedIn' => !empty($_SESSION['username']),
        'username' => $_SESSION['username'] ?? '',
        'isAdmin' => !empty($_SESSION['is_admin'])
    ]);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$action = $payload['action'] ?? '';
$username = trim($payload['username'] ?? '');
$password = $payload['password'] ?? '';

if (!in_array($action, ['login', 'register', 'logout', 'heartbeat', 'offline'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

if ($action === 'logout') {
    if ($currentUserId) {
        setUserOffline($mysqli, $currentUserId);
    }
    session_unset();
    session_destroy();
    echo json_encode(['success' => true, 'loggedIn' => false]);
    exit;
}

if ($action === 'heartbeat') {
    if ($currentUserId) {
        touchUserActivity($mysqli, $currentUserId);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'offline') {
    if ($currentUserId) {
        setUserOffline($mysqli, $currentUserId);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($username === '' || $password === '') {
    echo json_encode(['success' => false, 'error' => 'Username and password are required.']);
    exit;
}

if ($action === 'register') {
    $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Username is already taken.']);
        exit;
    }

    $stmt->close();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
    $stmt->bind_param('ss', $username, $hash);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Unable to register user.']);
        exit;
    }

    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Registration complete. Please login.']);
    exit;
}

if ($action === 'login') {
    $stmt = $mysqli->prepare('SELECT id, password, role FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($userId, $hash, $role);

    if (!$stmt->fetch()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Invalid username or password.']);
        exit;
    }

    $stmt->close();
    if (!password_verify($password, $hash)) {
        echo json_encode(['success' => false, 'error' => 'Invalid username or password.']);
        exit;
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $role;
    $_SESSION['is_admin'] = ($role === 'admin');
    touchUserActivity($mysqli, $userId);
    echo json_encode(['success' => true, 'loggedIn' => true, 'username' => $username, 'isAdmin' => $_SESSION['is_admin']]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unhandled auth request.']);
