<?php
// Database configuration for the Live-Chat app.
// Update the credentials below if your MySQL setup differs.

$serverHost = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$useLocalDb = preg_match('/^(localhost|127\.0\.0\.1|::1)(:\d+)?$/', $serverHost);

if ($useLocalDb) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'Live-Chat');
} else {
    define('DB_HOST', 'sql108.infinityfree.com');
    define('DB_USER', 'if0_42305549');
    define('DB_PASS', '8BuT9qQViCFB4T2');
    define('DB_NAME', 'if0_42305549_live_chat');
}

function getDbConnection() {
    static $initialized = false;

    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_error) {
        $msg = date('[Y-m-d H:i:s] ') . "DB connect error: " . $mysqli->connect_error . PHP_EOL;
        @file_put_contents(__DIR__ . '/debug.log', $msg, FILE_APPEND);
        return null;
    }

    if (!$initialized) {
        $initMarker = __DIR__ . '/.db_initialized';
        if (!file_exists($initMarker)) {
            ensureDatabaseSchema($mysqli);
            ensureAdminUser($mysqli);
            @file_put_contents($initMarker, date('c'));
        }
        $initialized = true;
    }

    return $mysqli;
}

function ensureDatabaseSchema($mysqli) {
    $createUsers = "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(20) NOT NULL DEFAULT 'user',
        `last_active` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $createFriends = "CREATE TABLE IF NOT EXISTS `friends` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `friend_id` INT UNSIGNED NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_friend_pair` (`user_id`, `friend_id`),
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`friend_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $createFriendRequests = "CREATE TABLE IF NOT EXISTS `friend_requests` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sender_id` INT UNSIGNED NOT NULL,
        `receiver_id` INT UNSIGNED NOT NULL,
        `status` ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_request_pair` (`sender_id`, `receiver_id`),
        FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $createMessages = "CREATE TABLE IF NOT EXISTS `messages` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sender_id` INT UNSIGNED NOT NULL,
        `receiver_id` INT UNSIGNED NOT NULL,
        `text` TEXT NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        INDEX `conversation_index` (`sender_id`, `receiver_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $createImages = "CREATE TABLE IF NOT EXISTS `images` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `message_id` INT UNSIGNED NOT NULL,
        `filename` VARCHAR(255) NOT NULL,
        `mime_type` VARCHAR(100) NOT NULL,
        `data` LONGBLOB NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $createMessageDeletions = "CREATE TABLE IF NOT EXISTS `message_deletions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `message_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `deleted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_message_user` (`message_id`, `user_id`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $mysqli->query($createUsers);
    $mysqli->query($createFriends);
    $mysqli->query($createFriendRequests);
    $mysqli->query($createMessages);
    $mysqli->query($createImages);
    $mysqli->query($createMessageDeletions);

    $columnCheck = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'last_active'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $mysqli->query("ALTER TABLE `users` ADD COLUMN `last_active` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }

    $columnCheck = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'role'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $mysqli->query("ALTER TABLE `users` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'user'");
    }

    $columnCheck = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'is_online'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $mysqli->query("ALTER TABLE `users` ADD COLUMN `is_online` TINYINT(1) NOT NULL DEFAULT 0");
    }

    $columnCheck = $mysqli->query("SHOW COLUMNS FROM `messages` LIKE 'is_read'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $mysqli->query("ALTER TABLE `messages` ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0");
    }
}

function ensureAdminUser($mysqli) {
    $roleColumnCheck = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'role'");
    if ($roleColumnCheck && $roleColumnCheck->num_rows === 0) {
        $mysqli->query("ALTER TABLE `users` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'user'");
    }

    $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $username = 'admin';
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    if ($exists) {
        $stmt = $mysqli->prepare('UPDATE users SET role = ? WHERE username = ?');
        $role = 'admin';
        $stmt->bind_param('ss', $role, $username);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
    $role = 'admin';
    $stmt->bind_param('sss', $username, $passwordHash, $role);
    $stmt->execute();
    $stmt->close();
}

function isAdminUser($mysqli, $userId) {
    if (!$userId) {
        return false;
    }

    $stmt = $mysqli->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($role);
    $isAdmin = false;
    if ($stmt->fetch()) {
        $isAdmin = ($role === 'admin');
    }
    $stmt->close();
    return $isAdmin;
}

function touchUserActivity($mysqli, $userId) {
    if (!$userId) {
        return;
    }
    $stmt = $mysqli->prepare('UPDATE users SET last_active = NOW(), is_online = 1 WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function setUserOffline($mysqli, $userId) {
    if (!$userId) {
        return;
    }
    $stmt = $mysqli->prepare('UPDATE users SET last_active = DATE_SUB(NOW(), INTERVAL 1 HOUR), is_online = 0 WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function getCurrentUserId($mysqli) {
    if (empty($_SESSION['username'])) {
        return null;
    }

    $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->bind_param('s', $_SESSION['username']);
    $stmt->execute();
    $stmt->bind_result($id);
    $userId = null;
    if ($stmt->fetch()) {
        $userId = $id;
    }
    $stmt->close();
    return $userId;
}

function findUserByIdentifier($mysqli, $identifier) {
    if ($identifier === '') {
        return null;
    }

    if (ctype_digit($identifier)) {
        $stmt = $mysqli->prepare('SELECT id, username FROM users WHERE id = ?');
        $id = (int) $identifier;
        $stmt->bind_param('i', $id);
    } else {
        $stmt = $mysqli->prepare('SELECT id, username FROM users WHERE username = ?');
        $stmt->bind_param('s', $identifier);
    }

    $stmt->execute();
    $stmt->bind_result($id, $username);
    $user = null;
    if ($stmt->fetch()) {
        $user = ['id' => $id, 'username' => $username];
    }
    $stmt->close();
    return $user;
}
