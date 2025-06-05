<?php
// api/add_feed.php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../secure/db_connection.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Only name & URL are required
    $name = trim($_POST['name']  ?? '');
    $url  = filter_var($_POST['url'] ?? '', FILTER_VALIDATE_URL);

    if (!$name || !$url) {
        throw new Exception('Feed Name and a valid URL are required.', 400);
    }

    // Insert name, url, and let other columns default
    $stmt = $pdo->prepare("
      INSERT INTO rss_feeds 
        (name, url) 
      VALUES 
        (?, ?)
    ");
    $stmt->execute([$name, $url]);

    echo json_encode([
      'success' => true,
      'id'      => $pdo->lastInsertId()
    ]);
}
catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
catch (Exception $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode(['error' => $e->getMessage()]);
}
