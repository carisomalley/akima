<?php
session_start();
require 'secure/db_connection.php'; // your PDO or mysqli connection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['username'];
    $p = $_POST['password'];

    // Use a prepared statement to avoid injection
    $stmt = $pdo->prepare('SELECT id, password_hash, role FROM users WHERE username = ?');
    $stmt->execute([$u]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($p, $user['password_hash'])) {
        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $u;
        $_SESSION['role']      = $user['role'];
        header('Location: index.html'); 
        exit;
    }

    $_SESSION['login_error'] = 'Invalid username or password.';
    header('Location: login.php');
    exit;
}
