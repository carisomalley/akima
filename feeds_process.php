<?php
// feeds_process.php
require __DIR__ . '/auth_check.php';
require __DIR__ . '/secure/db_connection.php';

// 1) Require item_id via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['item_id'])) {
    die('No item specified.');
}
$itemId = (int)$_POST['item_id'];

// 2) Lookup the RSS item in the database
$stmt = $pdo->prepare("
  SELECT 
    feed_name,
    DATE_FORMAT(pub_date, '%Y-%m-%d %H:%i:%s') AS date,
    title,
    link,
    excerpt,
    content
  FROM rss_items
  WHERE id = :id
");
$stmt->execute(['id' => $itemId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    die('Item not found.');
}

// 3) Unpack for display (fall back to excerpt if no content)
$feedName = $item['feed_name'];
$date     = $item['date'];
$title    = $item['title'];
$link     = $item['link'];
$content  = trim($item['content'] ?: $item['excerpt']);

// 4) Pull your OpenAI key
$keyStmt = $pdo->prepare("
  SELECT setting_value 
    FROM app_settings 
   WHERE setting_key = 'openai_api_key'
");
$keyStmt->execute();
$apiKey = $keyStmt->fetchColumn();

// 5) Generate both the summary and the key-points list
$summary   = '';
$keyPoints = '';
if ($apiKey) {
    $summary   = getArticleSummary($content, $apiKey);
    $keyPoints = getArticleKeyPoints($content, $apiKey);
}

/**
 * Summarize into 2–3 sentences.
 */
function getArticleSummary($text, $apiKey) {
    $system = [
      'role'    => 'system',
      'content' => 'You are a succinct summarizer. Condense the following into 2–3 sentences.'
    ];
    $user = [
      'role'    => 'user',
      'content' => $text
    ];
    $payload = [
      'model'       => 'gpt-3.5-turbo',
      'messages'    => [$system, $user],
      'max_tokens'  => 150,
      'temperature' => 0.3,
    ];
    return chatgpt_request($payload, $apiKey);
}

/**
 * Pull out five key points as a bulleted list.
 */
function getArticleKeyPoints($text, $apiKey) {
    $system = [
      'role'    => 'system',
      'content' => 'You are a helpful assistant. Extract the five most important key points from the following article and return them as a plain-text bulleted list (one point per line, prefaced with "- ").'
    ];
    $user = [
      'role'    => 'user',
      'content' => $text
    ];
    $payload = [
      'model'       => 'gpt-3.5-turbo',
      'messages'    => [$system, $user],
      'max_tokens'  => 200,
      'temperature' => 0.3,
    ];
    return chatgpt_request($payload, $apiKey);
}

/**
 * Generic cURL helper for ChatGPT calls.
 */
function chatgpt_request(array $payload, string $apiKey): string {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$apiKey}",
        'Content-Type: application/json',
      ],
      CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return trim($data['choices'][0]['message']['content'] ?? '');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">

  <!-- 1) Article Title -->
  <h1 class="mb-3"><?= htmlspecialchars($title) ?></h1>

  <!-- 2) Five Key Points -->
  <?php if ($keyPoints): 
    // split on newlines and strip "- "
    $points = array_filter(array_map('trim', explode("\n", $keyPoints)));
  ?>
    <ul class="mb-4">
      <?php foreach ($points as $pt): 
        $pt = preg_replace('/^[-*]\s*/', '', $pt);
      ?>
        <li><?= htmlspecialchars($pt) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>


  <!-- 3) Free‐form thoughts + Tone picker -->
  <form action="feeds_process_ig.php" method="post" class="mb-5">
    <input type="hidden" name="item_id"     value="<?= $itemId ?>">
    <input type="hidden" name="key_points"  value="<?= htmlspecialchars($keyPoints) ?>">

    <div class="mb-3">
      <label class="form-label"><strong>Add some thoughts:</strong></label>
      <textarea
        name="user_thoughts"
        rows="4"
        class="form-control"
        placeholder="Your own spin or angle…"
      ></textarea>
    </div>

    <!-- How to present the response -->
    <div class="mb-3 w-25">
      <label class="form-label"><strong>How to present the response:</strong></label>
      <select name="tone" class="form-select">
        <option value="agree" selected>Agree</option>
        <option value="disagree">Disagree</option>
        <option value="inform">Inform</option>
      </select>
    </div>


    <button type="submit" class="btn btn-primary">Generate Instagram Post</button>
    <button
      type="submit"
      formaction="feeds_process_ig_reels.php"
      class="btn btn-primary ms-2"
    >Generate Instagram Reel Script</button>
    <button
      type="submit"
      formaction="feeds_process_fb.php"
      class="btn btn-primary ms-2"
    >Generate Facebook Post</button>
    <a href="feeds_view.php" class="btn btn-secondary ms-2">← Back to List</a>
  </form>

  <hr>

  <!-- 4) Source Article Section -->
  <h2 class="h5">Source Article:</h2>
  <dl class="row mb-4">
    <dt class="col-sm-2">Title</dt>
    <dd class="col-sm-10">
      <?php if ($link): ?>
        <a href="<?= htmlspecialchars($link) ?>" target="_blank" rel="noopener">
          <?= htmlspecialchars($title) ?>
        </a>
      <?php else: ?>
        <?= htmlspecialchars($title) ?>
      <?php endif; ?>
    </dd>

    <dt class="col-sm-2">Date</dt>
    <dd class="col-sm-10"><?= htmlspecialchars($date) ?></dd>

    <dt class="col-sm-2">Feed</dt>
    <dd class="col-sm-10"><?= htmlspecialchars($feedName) ?></dd>
  </dl>

  <div class="mb-5">
    <?= nl2br(htmlspecialchars($content)) ?>
  </div>

</body>
</html>
```
