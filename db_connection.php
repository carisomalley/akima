<?php
// secure/db_connection.php

$host     = 'localhost';           // or 'localhost'
$db       = 'db_name';            // your full DB name
$user     = 'db_username';       // your full DB user (with prefix)
$password = 'db password;       // the password you set in hPanel
$charset  = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (\PDOException $e) {
    // in dev you can echo, in prod you’d log this
    echo 'Connection failed: ' . $e->getMessage();
    exit;
}
