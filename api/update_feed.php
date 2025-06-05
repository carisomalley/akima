<?php
// api/update_feed.php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../secure/db_connection.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    $id   = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $name = trim($_POST['name'] ?? '');
    $url  = filter_var($_POST['url'] ?? '', FILTER_VALIDATE_URL);

    if (!$id || !$name || !$url) {
        throw new Exception('ID, Name and valid URL are required.', 400);
    }

    $stmt = $pdo->prepare("
      UPDATE rss_feeds
      SET name = ?, url = ?
      WHERE id = ?
    ");
    $stmt->execute([$name, $url, $id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['error' => $e->getMessage()]);
}
