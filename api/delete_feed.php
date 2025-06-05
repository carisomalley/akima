<?php
// api/delete_feed.php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../secure/db_connection.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if (!$id) {
        throw new Exception('Invalid ID.', 400);
    }

    $stmt = $pdo->prepare("DELETE FROM rss_feeds WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['error' => $e->getMessage()]);
}
