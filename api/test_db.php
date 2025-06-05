<?php
require __DIR__ . '/../secure/db_connection.php';
try {
  $pdo->query('SELECT 1');
  echo '✅ DB connection successful!';
} catch (PDOException $e) {
  echo '❌ DB connection failed: ' . $e->getMessage();
}
