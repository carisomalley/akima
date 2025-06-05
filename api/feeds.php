<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../secure/db_connection.php';

try {
    $stmt = $pdo->query("
      SELECT
        id,
        name,
        url,
        date_entered,
        owner,
        status,
        concept,
        tone_voice,
        post_style,
        post_type,
        platform,
        notes,
        date_posted
      FROM rss_feeds
      ORDER BY date_entered DESC
    ");
    $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data' => $feeds]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['data'=>[], 'error'=>'DB error: '.$e->getMessage()]);
}
