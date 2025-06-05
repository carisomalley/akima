<?php
// feeds_view.php
require __DIR__ . '/auth_check.php';
require __DIR__ . '/secure/db_connection.php';

// ─── 0) ONE-TIME IMPORT: seed rss_items if empty ────────────────────────
$count = (int)$pdo->query("SELECT COUNT(*) FROM rss_items")->fetchColumn();
if ($count === 0) {
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

            // excerpt = first 100 words
            $desc   = (string)($entry->description ?? $entry->summary ?? '');
            $clean  = strip_tags($desc);
            $words  = preg_split('/\s+/', $clean);
            if (count($words) > 100) {
                $clean = implode(' ', array_slice($words,0,100)) . '…';
            }

            // full content
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
}

// ─── 1) Fetch up to 1,000 items ─────────────────────────────────────────
$stmt  = $pdo->query("
  SELECT 
    id,
    feed_name,
    DATE_FORMAT(pub_date, '%Y-%m-%d %H:%i:%s') AS date,
    title,
    link,
    excerpt
  FROM rss_items
  ORDER BY pub_date DESC
  LIMIT 1000
");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Latest RSS Items</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Bootstrap 5 CSS -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css"
    rel="stylesheet"
  >
  <!-- Bootstrap Icons (for the Home icon) -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    rel="stylesheet"
  >
  <!-- DataTables CSS -->
  <link
    href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css"
    rel="stylesheet"
  >
</head>
<body class="bg-light">
  <div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="d-flex align-items-center">
        <!-- Home button (back to index.php) -->
        <a href="index.php" class="btn btn-outline-secondary me-3">
          <i class="bi bi-house"></i> Home
        </a>
        <h1 class="h3 mb-0">Latest RSS Items</h1>
      </div>
      <!-- Existing Manage Feeds button -->
      <a href="feeds_manager.php" class="btn btn-outline-secondary">
        ← Manage Feeds
      </a>
    </div>

    <!-- Filters -->
    <div class="row g-2 mb-3">
      <div class="col-md-4">
        <input type="text" id="globalSearch" class="form-control" placeholder="Search…">
      </div>
      <div class="col-auto">
        <input type="date" id="minDate" class="form-control" placeholder="From">
      </div>
      <div class="col-auto">
        <input type="date" id="maxDate" class="form-control" placeholder="To">
      </div>
    </div>

    <!-- Table -->
    <div class="table-responsive shadow-sm bg-white rounded">
      <table id="itemsTable" class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Feed</th>
            <th>Date</th>
            <th>Title</th>
            <th>Excerpt</th>
            <th>Process</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= htmlspecialchars($it['feed_name']) ?></td>
              <td data-order="<?= strtotime($it['date']) ?>">
                <?= htmlspecialchars($it['date']) ?>
              </td>
              <td>
                <?php if ($it['link']): ?>
                  <a href="<?= htmlspecialchars($it['link']) ?>"
                     target="_blank" rel="noopener">
                    <?= htmlspecialchars($it['title']) ?>
                  </a>
                <?php else: ?>
                  <?= htmlspecialchars($it['title']) ?>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($it['excerpt']) ?></td>
              <td>
                <form action="feeds_process.php" method="post" style="display:inline">
                  <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                  <button type="submit" class="btn btn-primary btn-sm">
                    Create New Post!
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- JS deps -->
  <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
  <script>
    $(function(){
      $.fn.dataTable.ext.search.push((settings,data)=>{
        const min = $('#minDate').val(),
              max = $('#maxDate').val(),
              date= data[1]||'';
        return (!min||date>=min) && (!max||date<=max+' 23:59:59');
      });

      const table = $('#itemsTable').DataTable({
        lengthMenu:[10,25,50,100],
        pageLength:25,
        order:[[1,'desc']],
        dom:'t<"d-flex justify-content-between"lp>',
      });

      $('#globalSearch').on('keyup',()=>table.search($('#globalSearch').val()).draw());
      $('#minDate,#maxDate').on('change',()=>table.draw());
    });
  </script>
</body>
</html>
