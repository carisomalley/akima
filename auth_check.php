<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// redirect if not logged in
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
// Optionally enforce roles:
// if ($_SESSION['role'] !== 'admin') { /* 403 Forbidden */ }
?>
