<?php
require __DIR__ . '/auth_check.php';
// process_selection.php
require 'secure/db_connection.php';

if (empty($_POST['feed_ids'])) {
    die('No feeds selected.');
}

$ids = array_map('intval', $_POST['feed_ids']);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

// Example: Mark selected feeds as “in review”
$sql = "UPDATE rss_feeds SET status = 'in review' WHERE id IN ($placeholders)";
$stmt = $pdo->prepare($sql);
$stmt->execute($ids);

header('Location: feeds_manager.php?updated=' . count($ids));
exit;
