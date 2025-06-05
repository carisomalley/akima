<?php
// sync_feeds.php
require __DIR__ . '/auth_check.php';
require __DIR__ . '/secure/db_connection.php';

// 1) (Optional) Remove old items from feeds you just deleted
$pdo->query("DELETE FROM rss_items WHERE feed_name NOT IN (SELECT name FROM rss_feeds)");

// 2) Always re-import everything (INSERT IGNORE avoids duplicates)
$feeds = $pdo
  ->query("SELECT name AS feed_name, url FROM rss_feeds")
  ->fetchAll(PDO::FETCH_ASSOC);

$insert = $pdo->prepare("
  INSERT IGNORE INTO rss_items
    (feed_name, pub_date, title, link, excerpt, content)
  VALUES
    (:feed_name, :pub_date, :title, :link, :excerpt, :content)
");

foreach ($feeds as $feed) {
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_file($feed['url'], 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml) continue;

    $entries = isset($xml->channel->item)
             ? $xml->channel->item
             : (isset($xml->entry) ? $xml->entry : []);

    foreach ($entries as $entry) {
        $title    = trim((string)$entry->title);
        $link     = (string)($entry->link['href'] ?? $entry->link ?? '');
        $rawDate  = (string)($entry->pubDate ?? $entry->updated ?? '');
        $ts       = $rawDate ? strtotime($rawDate) : 0;
        $pubDate  = $ts ? date('Y-m-d H:i:s', $ts) : null;

        $desc   = (string)($entry->description ?? $entry->summary ?? '');
        $clean  = strip_tags($desc);
        $words  = preg_split('/\s+/', $clean);
        if (count($words) > 100) {
            $clean = implode(' ', array_slice($words,0,100)) . '…';
        }
        $contentNode = $entry->children('content', true)->encoded ?? '';
        $fullContent = strip_tags((string)$contentNode) ?: $desc;

        $insert->execute([
          'feed_name' => $feed['feed_name'],
          'pub_date'  => $pubDate,
          'title'     => $title,
          'link'      => $link,
          'excerpt'   => $clean,
          'content'   => $fullContent,
        ]);
    }
}

// 3) When done, redirect back to feeds_view.php (or send JSON “success” if you prefer AJAX)
header("Location: feeds_view.php");
exit;
