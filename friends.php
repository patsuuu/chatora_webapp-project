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
if (!$currentUserId) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

touchUserActivity($mysqli, $currentUserId);

function isFriend($mysqli, $userId, $targetId) {
    $stmt = $mysqli->prepare('SELECT 1 FROM friends WHERE user_id = ? AND friend_id = ? LIMIT 1');
    $stmt->bind_param('ii', $userId, $targetId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function getPendingRequestBetween($mysqli, $userA, $userB) {
    $stmt = $mysqli->prepare(
        'SELECT id, sender_id, receiver_id, status FROM friend_requests WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) LIMIT 1'
    );
    $stmt->bind_param('iiii', $userA, $userB, $userB, $userA);
    $stmt->execute();
    $stmt->bind_result($id, $senderId, $receiverId, $status);
    $request = null;
    if ($stmt->fetch()) {
        $request = [
            'id' => $id,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'status' => $status,
        ];
    }
    $stmt->close();
    return $request;
}

$action = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? $_POST['action'] ?? $_GET['action'] ?? null;
} else {
    $action = $_GET['action'] ?? null;
}

if (!$action) {
    echo json_encode(['success' => false, 'error' => 'Missing action.']);
    exit;
}

if ($action === 'list') {
    $stmt = $mysqli->prepare(
        'SELECT DISTINCT u.id, u.username, u.last_active, u.is_online, u.role = "admin" AS is_admin
         FROM users u
         LEFT JOIN friends f ON f.friend_id = u.id AND f.user_id = ?
         WHERE f.user_id IS NOT NULL OR (u.role = "admin" AND u.id <> ?)
         ORDER BY u.username ASC'
    );
    $stmt->bind_param('ii', $currentUserId, $currentUserId);
    $stmt->execute();
    $stmt->bind_result($id, $username, $lastActive, $isOnline, $isAdminFlag);

    $friends = [];
    while ($stmt->fetch()) {
        $friends[] = [
            'id' => $id,
            'username' => $username,
            'online' => ($isOnline == 1),
            'isAdmin' => ($isAdminFlag == 1),
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'friends' => $friends]);
    exit;
}

if ($action === 'pending') {
    $incoming = [];
    $stmt = $mysqli->prepare(
        'SELECT fr.id, u.id AS sender_id, u.username FROM friend_requests fr JOIN users u ON fr.sender_id = u.id WHERE fr.receiver_id = ? AND fr.status = "pending" ORDER BY fr.created_at DESC'
    );
    $stmt->bind_param('i', $currentUserId);
    $stmt->execute();
    $stmt->bind_result($requestId, $senderId, $senderName);
    while ($stmt->fetch()) {
        $incoming[] = ['requestId' => $requestId, 'senderId' => $senderId, 'senderName' => $senderName];
    }
    $stmt->close();

    $outgoing = [];
    $stmt = $mysqli->prepare(
        'SELECT fr.id, u.id AS receiver_id, u.username FROM friend_requests fr JOIN users u ON fr.receiver_id = u.id WHERE fr.sender_id = ? AND fr.status = "pending" ORDER BY fr.created_at DESC'
    );
    $stmt->bind_param('i', $currentUserId);
    $stmt->execute();
    $stmt->bind_result($requestId, $receiverId, $receiverName);
    while ($stmt->fetch()) {
        $outgoing[] = ['requestId' => $requestId, 'receiverId' => $receiverId, 'receiverName' => $receiverName];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'incoming' => $incoming, 'outgoing' => $outgoing]);
    exit;
}

if ($action === 'add') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $identifier = trim($payload['identifier'] ?? '');

    file_put_contents(__DIR__ . '/debug.log', date('[Y-m-d H:i:s] ') . "friends.php add action started: user={$currentUserId} identifier={$identifier}\n", FILE_APPEND);

    if ($identifier === '') {
        echo json_encode(['success' => false, 'error' => 'Friend identifier is required.']);
        exit;
    }

    $target = findUserByIdentifier($mysqli, $identifier);
    if (!$target) {
        file_put_contents(__DIR__ . '/debug.log', date('[Y-m-d H:i:s] ') . "friends.php add target not found: identifier={$identifier}\n", FILE_APPEND);
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }

    if ($target['id'] === $currentUserId) {
        echo json_encode(['success' => false, 'error' => 'You cannot add yourself as a friend.']);
        exit;
    }

    if (isFriend($mysqli, $currentUserId, $target['id'])) {
        echo json_encode(['success' => false, 'error' => 'You are already friends with this user.']);
        exit;
    }

    $existingRequest = getPendingRequestBetween($mysqli, $currentUserId, $target['id']);
    if ($existingRequest) {
        if ($existingRequest['status'] === 'pending') {
            if ($existingRequest['sender_id'] === $currentUserId) {
                echo json_encode(['success' => false, 'error' => 'Friend request already sent.']);
            } else {
                echo json_encode(['success' => false, 'error' => 'This user has already sent you a friend request.']);
            }
            exit;
        }

        if ($existingRequest['status'] === 'rejected' && $existingRequest['sender_id'] === $currentUserId) {
            $stmt = $mysqli->prepare('UPDATE friend_requests SET status = "pending", created_at = NOW() WHERE id = ?');
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => 'Unable to resend friend request.']);
                exit;
            }
            $stmt->bind_param('i', $existingRequest['id']);
            if (!$stmt->execute()) {
                $stmt->close();
                echo json_encode(['success' => false, 'error' => 'Unable to resend friend request.']);
                exit;
            }
            $stmt->close();

            echo json_encode(['success' => true, 'requestId' => $existingRequest['id'], 'receiver' => $target]);
            exit;
        }
    }

    $stmt = $mysqli->prepare('INSERT INTO friend_requests (sender_id, receiver_id) VALUES (?, ?)');
    $stmt->bind_param('ii', $currentUserId, $target['id']);
    if (!$stmt->execute()) {
        $error = $mysqli->error;
        $stmt->close();
        file_put_contents(__DIR__ . '/debug.log', date('[Y-m-d H:i:s] ') . "friends.php add insert failed: user={$currentUserId} target={$target['id']} error={$error}\n", FILE_APPEND);
        echo json_encode(['success' => false, 'error' => 'Unable to send friend request.']);
        exit;
    }
    $requestId = $stmt->insert_id;
    $stmt->close();

    echo json_encode(['success' => true, 'requestId' => $requestId, 'receiver' => $target]);
    exit;
}

if ($action === 'respond') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $requestId = isset($payload['requestId']) ? (int) $payload['requestId'] : 0;
    $decision = $payload['decision'] ?? '';

    if ($requestId <= 0 || !in_array($decision, ['accept', 'reject'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid request response.']);
        exit;
    }

    $stmt = $mysqli->prepare('SELECT sender_id, receiver_id, status FROM friend_requests WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $stmt->bind_result($senderId, $receiverId, $status);
    if (!$stmt->fetch()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Friend request not found.']);
        exit;
    }
    $stmt->close();

    if ($receiverId !== $currentUserId || $status !== 'pending') {
        echo json_encode(['success' => false, 'error' => 'Unable to respond to this request.']);
        exit;
    }

    if ($decision === 'accept') {
        $stmt = $mysqli->prepare('INSERT IGNORE INTO friends (user_id, friend_id) VALUES (?, ?), (?, ?)');
        $stmt->bind_param('iiii', $currentUserId, $senderId, $senderId, $currentUserId);
        $stmt->execute();
        $stmt->close();
        $stmt = $mysqli->prepare('UPDATE friend_requests SET status = "accepted" WHERE id = ?');
    } else {
        $stmt = $mysqli->prepare('UPDATE friend_requests SET status = "rejected" WHERE id = ?');
    }
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
    exit;
}
if ($action === 'remove') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $friendId = isset($payload['friendId']) ? (int) $payload['friendId'] : 0;

    if ($friendId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid friend selection.']);
        exit;
    }

    $stmt = $mysqli->prepare('DELETE FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)');
    $stmt->bind_param('iiii', $currentUserId, $friendId, $friendId, $currentUserId);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Unable to remove friend.']);
        exit;
    }
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        echo json_encode(['success' => false, 'error' => 'You are not friends with this user.']);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
