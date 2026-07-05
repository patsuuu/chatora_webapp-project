<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['ok'])) {
    $_SESSION['ok'] = time();
    echo "Session set. Reload to check persistence.";
} else {
    echo "Session exists. Value: " . $_SESSION['ok'];
}
