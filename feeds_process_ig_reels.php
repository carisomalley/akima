<?php
// feeds_process_ig_reels.php

// ─── 0) Bootstrap ───────────────────────────────────────────────────────
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/auth_check.php';
require __DIR__ . '/secure/db_connection.php';

// ─── 1) Fetch RSS item ─────────────────────────────────────────────────
$itemId = (int)($_REQUEST['item_id'] ?? 0);
if (!$itemId) {
    die('No item specified.');
}

$stmt = $pdo->prepare("
  SELECT 
    feed_name,
    DATE_FORMAT(pub_date, '%Y-%m-%d %H:%i:%s') AS date,
    title, link, excerpt, content
  FROM rss_items
  WHERE id = :id
");
$stmt->execute(['id' => $itemId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    die('Item not found.');
}

$feedName   = $item['feed_name'];
$date       = $item['date'];
$title      = $item['title'];
$link       = $item['link'];
$excerpt    = $item['excerpt'];
$rawContent = $item['content'] ?: $excerpt;

// ─── 2) Pull API keys ───────────────────────────────────────────────────
$keyStmt = $pdo->prepare("
  SELECT setting_key, setting_value
    FROM app_settings
   WHERE setting_key IN (
     'openai_api_key',
     'unsplash_api_key',
     'pexels_api_key',
     'pixabay_api_key',
     'instagram_access_token',
     'instagram_user_id'
   )
");
$keyStmt->execute();
$keys = $keyStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$openaiKey        = $keys['openai_api_key']        ?? null;
$unsplashKey      = $keys['unsplash_api_key']      ?? null;
$pexelsKey        = $keys['pexels_api_key']        ?? null;
$pixabayKey       = $keys['pixabay_api_key']       ?? null;
$instaAccessToken = $keys['instagram_access_token'] ?? null;
$instaUserId      = $keys['instagram_user_id']     ?? null;

if (!$openaiKey) {
    die('No OpenAI API key found. Please configure it in Settings.');
}

// ─── 3) Helpers ─────────────────────────────────────────────────────────
function chatgpt_request(array $payload, string $key): string {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$key}",
        'Content-Type: application/json',
      ],
      CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return trim($data['choices'][0]['message']['content'] ?? '');
}

function getArticleSummary(string $text, string $key): string {
    return chatgpt_request([
      'model'=>'gpt-3.5-turbo',
      'messages'=>[
        ['role'=>'system','content'=>'You are a succinct summarizer. Condense the following into 2–3 sentences.'],
        ['role'=>'user','content'=>$text]
      ],
      'max_tokens'=>150,
      'temperature'=>0.3
    ], $key);
}

/**
 * Suggest 5 specific image-search phrases (1–2 words each).
 */
function getSearchSuggestions(string $title, string $summary, string $key): array {
    $resp = chatgpt_request([
      'model'=>'gpt-3.5-turbo',
      'messages'=>[
        [
          'role'=>'system',
          'content'=>
            "You are a visual designer assistant. Given an article's title and summary, "
          . "suggest 5 specific image-search phrases (1–2 words each) that a designer could "
          . "enter into a royalty-free photo site to find relevant pictures. "
          . "Focus on concrete objects, settings, or emotions described in the text."
        ],
        [
          'role'=>'user',
          'content'=>
            "Title: {$title}\n"
          . "Summary: {$summary}\n\n"
          . "Return exactly 5 phrases, comma separated. Each phrase "
          . "should be 1–2 words long and reflect an idea from the article."
        ]
      ],
      'max_tokens'=>80,
      'temperature'=>0.7
    ], $key);

    $terms = preg_split('/[,\r\n]+/', $resp);
    return array_values(array_filter(array_map(
        fn($t) => trim(preg_replace('/^\d+\.\s*/','',$t)),
        $terms
    )));
}

// Generate 5 DALL·E prompts
function getImagePromptOptions(string $title, string $summary, string $key): array {
    $resp = chatgpt_request([
      'model'=>'gpt-3.5-turbo',
      'messages'=>[
        ['role'=>'system','content'=>'You are an expert at crafting evocative DALL·E prompts for Instagram Reels.'],
        ['role'=>'user','content'=>
          "Title: {$title}\nSummary: {$summary}\n\n"
         ."Generate five DALL·E prompts (8–20 words), numbered 1–5, that:\n"
         ."- Name an artistic style\n"
         ."- Embrace minimalist composition\n"
         ."- Evoke the story above as a Reel carousel sequence."
        ]
      ],
      'max_tokens'=>300,
      'temperature'=>0.8
    ], $key);

    preg_match_all('/\d+\.\s*(.+?)(?:\r?\n|$)/', $resp, $m);
    return array_slice($m[1] ?? [], 0, 5);
}

// Call DALL·E
function callDalle(string $prompt, string $key): string {
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$key}",
        'Content-Type: application/json',
      ],
      CURLOPT_POSTFIELDS     => json_encode(['prompt'=>$prompt,'n'=>1,'size'=>'1024x1024']),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($resp, true);
    return $d['data'][0]['url'] ?? '';
}

// ─── 4) Restore session state ───────────────────────────────────────────
$imageOptions      = $_SESSION['image_options']      ?? [];
$searchSuggestions = $_SESSION['search_suggestions'] ?? [];
$rf_results        = $_SESSION['rf_results']         ?? [];
$selectedImages    = $_SESSION['selected_rf_images'] ?? [];

// ─── 5) Read form inputs up front ───────────────────────────────────────
$stage        = $_REQUEST['stage']         ?? 'initial';
$length       = intval($_REQUEST['length'] ?? 60);
$tone         = $_REQUEST['tone']         ?? 'neutral'; // neutral / agree / disagree
$userThoughts = trim($_REQUEST['user_thoughts'] ?? '');
$summary      = '';

// sanitize tone
if (!in_array($tone, ['neutral','agree','disagree'])) {
    $tone = 'neutral';
}

// determine summary (edited or AI‐generated)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['content'])) {
    $summary = trim($_POST['content']);
} else {
    $summary = getArticleSummary($rawContent, $openaiKey);
}

$error          = '';
$essay          = '';
$imageUrl       = '';
$successMessage = '';

// ─── 6) POST handler ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {

  // a) Handle user-uploaded image file
  if (isset($_FILES['user_image']) && $_FILES['user_image']['error'] === UPLOAD_ERR_OK) {
      $tmpFile  = $_FILES['user_image']['tmp_name'];
      $origName = basename($_FILES['user_image']['name']);
      $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      $allowed  = ['jpg','jpeg','png','gif'];
      if (!in_array($ext, $allowed)) {
          $error = 'Invalid file type. Only JPG, PNG, or GIF allowed.';
      } else {
          $uploadDir = __DIR__ . '/uploads/';
          if (!is_dir($uploadDir)) {
              mkdir($uploadDir, 0755, true);
          }
          $newName     = uniqid('upload_', true) . '.' . $ext;
          $destination = $uploadDir . $newName;
          if (move_uploaded_file($tmpFile, $destination)) {
              $uploadedUrl = 'uploads/' . $newName;
              if (!in_array($uploadedUrl, $selectedImages)) {
                  $selectedImages[] = $uploadedUrl;
              }
              $_SESSION['selected_rf_images'] = $selectedImages;
          } else {
              $error = 'Failed to move uploaded file.';
          }
      }
  }

  // b) Remove a selected image
  elseif (isset($_POST['remove_url'])) {
      $u = $_POST['remove_url'];
      if (($idx = array_search($u, $selectedImages)) !== false) {
          unset($selectedImages[$idx]);
          $selectedImages = array_values($selectedImages);
      }
      $_SESSION['selected_rf_images'] = $selectedImages;
  }

  // c) Add a royalty-free image
  elseif (isset($_POST['rf_add_url'])) {
      $u = $_POST['rf_add_url'];
      if ($u && !in_array($u, $selectedImages)) {
          $selectedImages[] = $u;
      }
      $_SESSION['selected_rf_images'] = $selectedImages;
  }

  // d) RF search
  elseif (isset($_POST['rf_site'])) {
      $query = trim($_POST['rf_query'] ?? '');
      $site  = $_POST['rf_site'];
      $rf_results = [];

      if (!$query) {
          $error = 'Please enter a search term.';
      } else {
          switch ($site) {
              case 'unsplash':
                  if ($unsplashKey) {
                      $u = "https://api.unsplash.com/search/photos?query="
                         . urlencode($query)
                         . "&per_page=6&client_id={$unsplashKey}";
                      $d = @json_decode(file_get_contents($u), true);
                      foreach (($d['results'] ?? []) as $img) {
                          $rf_results[] = $img['urls']['small'];
                      }
                  } else {
                      $error = 'Unsplash key missing.';
                  }
                  break;
              case 'pexels':
                  if ($pexelsKey) {
                      $ch = curl_init("https://api.pexels.com/v1/search?query="
                                   . urlencode($query) . "&per_page=6");
                      curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER     => ["Authorization: {$pexelsKey}"]
                      ]);
                      $d = @json_decode(curl_exec($ch), true);
                      curl_close($ch);
                      foreach (($d['photos'] ?? []) as $img) {
                          $rf_results[] = $img['src']['medium'];
                      }
                  } else {
                      $error = 'Pexels key missing.';
                  }
                  break;
              case 'pixabay':
                  if ($pixabayKey) {
                      $u = "https://pixabay.com/api/?key={$pixabayKey}"
                         . "&q=" . urlencode($query) . "&per_page=6";
                      $d = @json_decode(file_get_contents($u), true);
                      foreach (($d['hits'] ?? []) as $img) {
                          $rf_results[] = $img['previewURL'];
                      }
                  } else {
                      $error = 'Pixabay key missing.';
                  }
                  break;
              default:
                  $error = 'Unknown site.';
          }
      }
      $_SESSION['rf_results'] = $rf_results;
  }

  // e) INITIAL: generate the Reel script
  elseif ($stage === 'initial') {
      if ($length < 1 || $length > 180) {
          $error = 'Length must be between 1 and 180 seconds.';
      } elseif (!$summary) {
          $error = 'Please edit the summary before generating.';
      }

      if (!$error) {
          // System prompt for Instagram Reels
          $systemMsg = [
            'role'=>'system',
            'content'=>
              "You are an expert persuasive essay writer for Instagram Reels.\n"
             . "Always weave in the user’s comments and follow the specified TONE exactly:\n"
             . "- neutral: balanced, factual\n"
             . "- agree: supportive, positive\n"
             . "- disagree: critical, questioning"
          ];
          $userMsg = [
            'role'=>'user',
            'content'=>
              "Title: {$title}\n"
             . "Summary: {$summary}\n"
             . "User Thoughts: {$userThoughts}\n"
             . "Length: {$length} seconds\n"
             . "Tone: {$tone}\n\n"
             . "Produce a persuasive essay that fits the above."
          ];

          $essay = chatgpt_request([
            'model'=>'gpt-3.5-turbo',
            'messages'=>[$systemMsg,$userMsg],
            'max_tokens'=>800,
            'temperature'=>0.8
          ], $openaiKey);

          if ($essay) {
              // Ensure "#ReelFamilies" is present
              if (strpos($essay, '#ReelFamilies') === false) {
                  $essay = trim($essay) . " #ReelFamilies";
              }
              // Save essay into session
              $_SESSION['post_body'] = $essay;

              // Generate AI image prompts
              $imageOptions = getImagePromptOptions($title, $summary, $openaiKey);
              $_SESSION['image_options'] = $imageOptions;

              // RF search suggestions
              $searchSuggestions = getSearchSuggestions($title, $summary, $openaiKey);
              $_SESSION['search_suggestions'] = $searchSuggestions;

              $stage = 'choose_image';
          }
      }
  }

  // f) CHOOSE_IMAGE: generate AI image (stay in choose_image)
  elseif ($stage === 'choose_image' && isset($_POST['generate_image_index'])) {
      $i      = (int) $_POST['generate_image_index'];
      $prompt = $_POST['image_prompts'][$i] ?? '';
      if ($prompt) {
          $imageUrl = callDalle($prompt, $openaiKey);
          if ($imageUrl && !in_array($imageUrl, $selectedImages)) {
              $selectedImages[] = $imageUrl;
              $_SESSION['selected_rf_images'] = $selectedImages;
          }
      } else {
          $error = 'No prompt provided.';
      }
      $stage = 'choose_image';
  }

  // g) PUBLISH TO INSTAGRAM REELS
  elseif (isset($_POST['publish_ig_reels'])) {
      if (!$instaAccessToken || !$instaUserId) {
          $error = 'Instagram Access Token or User ID not configured.';
      } else {
          $essayText = $_SESSION['post_body'] ?? '';
          $caption   = trim($essayText);

          // Collect absolute URLs for each selected image
          $absoluteUrls = [];
          foreach ($selectedImages as $imgUrl) {
              if (strpos($imgUrl, 'http') === 0) {
                  $absoluteUrls[] = $imgUrl;
              } else {
                  // Convert relative path to absolute
                  $absoluteUrls[] = 'https://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($imgUrl, '/');
              }
          }

          // If no images selected, error
          if (empty($absoluteUrls)) {
              $error = 'Please select at least one image before publishing.';
          } else {
              // If only one image, create single REEL media container
              if (count($absoluteUrls) === 1) {
                  $imageUrl = $absoluteUrls[0];
                  // 1) Create media container
                  $endpoint = "https://graph.facebook.com/{$instaUserId}/media";
                  $postFields = [
                      'image_url'    => $imageUrl,
                      'caption'      => $caption,
                      'media_type'   => 'IMAGE',
                      'is_reel'      => 'true',
                      'access_token' => $instaAccessToken
                  ];
                  $ch = curl_init($endpoint);
                  curl_setopt_array($ch, [
                      CURLOPT_RETURNTRANSFER => true,
                      CURLOPT_POST           => true,
                      CURLOPT_POSTFIELDS     => $postFields
                  ]);
                  $resp = curl_exec($ch);
                  if (curl_errno($ch)) {
                      $error = 'cURL error creating media container: ' . curl_error($ch);
                      curl_close($ch);
                  } else {
                      curl_close($ch);
                      $j = json_decode($resp, true);
                      if (isset($j['id'])) {
                          $creationId = $j['id'];
                          // 2) Publish media container
                          $publishEndpoint = "https://graph.facebook.com/{$instaUserId}/media_publish";
                          $publishFields = [
                              'creation_id'  => $creationId,
                              'access_token' => $instaAccessToken
                          ];
                          $ch2 = curl_init($publishEndpoint);
                          curl_setopt_array($ch2, [
                              CURLOPT_RETURNTRANSFER => true,
                              CURLOPT_POST           => true,
                              CURLOPT_POSTFIELDS     => $publishFields
                          ]);
                          $resp2 = curl_exec($ch2);
                          if (curl_errno($ch2)) {
                              $error = 'cURL error publishing media: ' . curl_error($ch2);
                              curl_close($ch2);
                          } else {
                              curl_close($ch2);
                              $j2 = json_decode($resp2, true);
                              if (isset($j2['id'])) {
                                  $successMessage = "Posted to Instagram Reels successfully (Media ID: {$j2['id']}).";
                              } else {
                                  $error = 'Instagram Reels publish failed: ' . ($j2['error']['message'] ?? $resp2);
                              }
                          }
                      } else {
                          $error = 'Instagram media container creation failed: ' . ($j['error']['message'] ?? $resp);
                      }
                  }
              }
              // If multiple images, create a carousel REEL
              else {
                  // 1) Create individual carousel children
                  $childrenIds = [];
                  foreach ($absoluteUrls as $imgUrl) {
                      $endpoint = "https://graph.facebook.com/{$instaUserId}/media";
                      $postFields = [
                          'image_url'       => $imgUrl,
                          'is_carousel_item'=> 'true',
                          'access_token'    => $instaAccessToken
                      ];
                      $ch = curl_init($endpoint);
                      curl_setopt_array($ch, [
                          CURLOPT_RETURNTRANSFER => true,
                          CURLOPT_POST           => true,
                          CURLOPT_POSTFIELDS     => $postFields
                      ]);
                      $resp = curl_exec($ch);
                      if (curl_errno($ch)) {
                          $error = 'cURL error creating carousel child: ' . curl_error($ch);
                          curl_close($ch);
                          break;
                      }
                      curl_close($ch);
                      $j = json_decode($resp, true);
                      if (isset($j['id'])) {
                          $childrenIds[] = $j['id'];
                      } else {
                          $error = 'Instagram child media creation failed: ' . ($j['error']['message'] ?? $resp);
                          break;
                      }
                  }

                  // 2) If no error, create the carousel container
                  if (!$error) {
                      $endpoint = "https://graph.facebook.com/{$instaUserId}/media";
                      $postFields = [
                          'caption'       => $caption,
                          'children'      => implode(',', $childrenIds),
                          'media_type'    => 'CAROUSEL',
                          'is_reel'       => 'true',
                          'access_token'  => $instaAccessToken
                      ];
                      $ch2 = curl_init($endpoint);
                      curl_setopt_array($ch2, [
                          CURLOPT_RETURNTRANSFER => true,
                          CURLOPT_POST           => true,
                          CURLOPT_POSTFIELDS     => $postFields
                      ]);
                      $resp2 = curl_exec($ch2);
                      if (curl_errno($ch2)) {
                          $error = 'cURL error creating carousel container: ' . curl_error($ch2);
                          curl_close($ch2);
                      } else {
                          curl_close($ch2);
                          $j2 = json_decode($resp2, true);
                          if (isset($j2['id'])) {
                              $carouselCreationId = $j2['id'];
                              // 3) Publish carousel
                              $publishEndpoint = "https://graph.facebook.com/{$instaUserId}/media_publish";
                              $publishFields = [
                                  'creation_id'  => $carouselCreationId,
                                  'access_token' => $instaAccessToken
                              ];
                              $ch3 = curl_init($publishEndpoint);
                              curl_setopt_array($ch3, [
                                  CURLOPT_RETURNTRANSFER => true,
                                  CURLOPT_POST           => true,
                                  CURLOPT_POSTFIELDS     => $publishFields
                              ]);
                              $resp3 = curl_exec($ch3);
                              if (curl_errno($ch3)) {
                                  $error = 'cURL error publishing carousel: ' . curl_error($ch3);
                                  curl_close($ch3);
                              } else {
                                  curl_close($ch3);
                                  $j3 = json_decode($resp3, true);
                                  if (isset($j3['id'])) {
                                      $successMessage = "Posted carousel to Instagram Reels successfully (Media ID: {$j3['id']}).";
                                  } else {
                                      $error = 'Instagram carousel publish failed: ' . ($j3['error']['message'] ?? $resp3);
                                  }
                              }
                          } else {
                              $error = 'Instagram carousel container creation failed: ' . ($j2['error']['message'] ?? $resp2);
                          }
                      }
                  }
              }
          }
      }
      // Stay in choose_image so we can show errors/success
      $stage = 'choose_image';
  }

} // end POST handler

// ─── 7) Reload session arrays ───────────────────────────────────────────
$rf_results        = $_SESSION['rf_results']         ?? [];
$selectedImages    = $_SESSION['selected_rf_images'] ?? [];
$searchSuggestions = $_SESSION['search_suggestions'] ?? [];

// ─── 8) Render HTML ────────────────────────────────────────────────────
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Instagram Reel Essay Builder</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .suggestion-btn { margin: .25em; }
    .thumb-container { position: relative; }
    .thumb-container img { display: block; width: 100%; height: auto; }
    .thumb-checkbox {
      position: absolute;
      top: 5px;
      right: 5px;
      background: rgba(255,255,255,0.8);
      border-radius: 3px;
      padding: 2px;
    }
  </style>
</head>
<body class="container py-4">

  <h1 class="h4 mb-3">Instagram Reel Essay Builder</h1>

  <!-- Feed item card -->
  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title"><?= htmlspecialchars($title) ?></h5>
      <h6 class="card-subtitle text-muted mb-2">
        <?= htmlspecialchars($feedName) ?> — <?= htmlspecialchars($date) ?>
      </h6>
      <p class="card-text"><?= nl2br(htmlspecialchars($excerpt)) ?></p>
      <?php if ($link): ?>
        <a href="<?= htmlspecialchars($link) ?>" target="_blank" class="card-link">Original</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($successMessage)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
  <?php elseif (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- INITIAL STAGE -->
  <?php if ($stage === 'initial'): ?>
    <form method="post" class="mb-4">
      <input type="hidden" name="stage"   value="initial">
      <input type="hidden" name="item_id" value="<?= $itemId ?>">

      <div class="mb-3">
        <label class="form-label">Summary</label>
        <textarea name="content" rows="5" class="form-control" required><?= htmlspecialchars($summary) ?></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label">Your Comments</label>
        <textarea name="user_thoughts" rows="2" class="form-control"
          placeholder="Add your angle…"><?= htmlspecialchars($userThoughts) ?></textarea>
      </div>

      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Length (seconds)</label>
          <input type="number" name="length" class="form-control" min="1" max="180" value="<?= $length ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Tone</label>
          <select name="tone" class="form-select">
            <?php foreach (['neutral','agree','disagree'] as $opt): ?>
              <option value="<?= $opt ?>" <?= $tone === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button class="btn btn-primary">Generate Reel Script</button>
    </form>
  <?php endif; ?>

  <!-- CHOOSE_IMAGE STAGE -->
  <?php if ($stage === 'choose_image'): ?>
    <a href="?item_id=<?= $itemId ?>" class="btn btn-outline-secondary mb-4">← Change Settings</a>

    <div class="mb-3">
      <label class="form-label"><strong>Reel Script</strong></label>
      <textarea class="form-control" rows="10" readonly><?= htmlspecialchars($_SESSION['post_body'] ?? '') ?></textarea>
    </div>

    <!-- Selected Images Gallery (with Download) -->
    <?php if (!empty($selectedImages)): ?>
      <div class="mb-4">
        <h5>Selected Images</h5>
        <div class="row g-2">
          <?php foreach ($selectedImages as $u): ?>
            <div class="col-4 col-md-2">
              <div class="thumb-container position-relative">
                <!-- 1) Thumbnail image -->
                <img src="<?= htmlspecialchars($u) ?>" class="img-fluid rounded">

                <!-- 2) Remove button in the top-right -->
                <form method="post" style="position:absolute; top:5px; right:5px; z-index:10;">
                  <input type="hidden" name="item_id"    value="<?= $itemId ?>">
                  <input type="hidden" name="stage"      value="choose_image">
                  <input type="hidden" name="remove_url" value="<?= htmlspecialchars($u) ?>">
                  <button type="submit" class="btn btn-sm btn-danger thumb-checkbox">&times;</button>
                </form>

                <!-- 3) Download button in the bottom-left -->
                <a
                  href="download.php?url=<?= urlencode($u) ?>"
                  class="btn btn-sm btn-outline-light position-absolute"
                  style="bottom:5px; left:5px; z-index:10;"
                >
                  Download
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Upload your own image -->
    <div class="mb-4">
      <h5>Upload your own image</h5>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <input type="hidden" name="stage"   value="choose_image">
        <input type="file" name="user_image" accept="image/*" class="form-control mb-2">
        <button type="submit" class="btn btn-outline-secondary">Upload Image</button>
      </form>
    </div>

    <!-- RF Search Suggestions -->
    <?php if ($searchSuggestions): ?>
      <div class="mb-3">
        <small class="text-muted">Try these search terms:</small><br>
        <?php foreach ($searchSuggestions as $term): ?>
          <button type="button" class="btn btn-sm btn-outline-info suggestion-btn mb-1"><?= htmlspecialchars($term) ?></button>
        <?php endforeach; ?>
      </div>
      <script>
        document.querySelectorAll('.suggestion-btn').forEach(btn =>
          btn.addEventListener('click', () => {
            document.querySelector('input[name="rf_query"]').value = btn.textContent;
          })
        );
      </script>
    <?php endif; ?>

    <!-- Royalty-Free Search -->
    <div class="mb-4">
      <h5>Search royalty-free images</h5>
      <form method="post" class="row g-2 align-items-center">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <input type="hidden" name="stage"   value="choose_image">
        <div class="col">
          <input name="rf_query" class="form-control" placeholder="Enter keywords…">
        </div>
        <?php if ($unsplashKey): ?>
          <div class="col-auto">
            <button name="rf_site" value="unsplash" class="btn btn-outline-primary">Unsplash</button>
          </div>
        <?php endif; ?>
        <?php if ($pexelsKey): ?>
          <div class="col-auto">
            <button name="rf_site" value="pexels" class="btn btn-outline-secondary">Pexels</button>
          </div>
        <?php endif; ?>
        <?php if ($pixabayKey): ?>
          <div class="col-auto">
            <button name="rf_site" value="pixabay" class="btn btn-outline-success">Pixabay</button>
          </div>
        <?php endif; ?>
      </form>
    </div>

    <!-- RF Results -->
    <?php if ($rf_results): ?>
      <div class="row g-2 mb-4">
        <?php foreach ($rf_results as $url): ?>
          <?php if (!in_array($url, $selectedImages)): ?>
            <div class="col-4 col-md-2">
              <div class="thumb-container position-relative">
                <img src="<?= htmlspecialchars($url) ?>" class="img-fluid rounded">
                <form method="post" style="position:absolute; top:5px; right:5px; z-index:10;">
                  <input type="hidden" name="item_id"    value="<?= $itemId ?>">
                  <input type="hidden" name="stage"      value="choose_image">
                  <input type="hidden" name="rf_add_url" value="<?= htmlspecialchars($url) ?>">
                  <button type="submit" class="btn btn-sm btn-primary thumb-checkbox">＋</button>
                </form>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- AI-Generated Prompts -->
    <?php if ($imageOptions): ?>
      <form method="post">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <input type="hidden" name="stage"   value="choose_image">
        <?php foreach ($imageOptions as $i => $opt): ?>
          <div class="mb-3 row gx-2 align-items-start">
            <div class="col">
              <textarea name="image_prompts[<?= $i ?>]" class="form-control" rows="2"><?= htmlspecialchars($opt) ?></textarea>
            </div>
            <div class="col-auto">
              <button name="generate_image_index" value="<?= $i ?>" class="btn btn-success mt-2">
                Generate Image
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </form>
    <?php endif; ?>

    <!-- Publish to Instagram Reels button -->
    <div class="mt-4">
      <form method="post" style="display:inline-block;">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <input type="hidden" name="stage"   value="choose_image">
        <button name="publish_ig_reels" value="1" class="btn btn-success">
          Publish to Instagram Reels
        </button>
      </form>
    </div>
  <?php endif; ?>

  <!-- GENERATED STAGE -->
  <?php if ($stage === 'generated'): ?>
    <?php if (!empty($imageUrl)): ?>
      <div class="mb-4">
        <label class="form-label"><strong>Generated Image</strong></label><br>
        <img src="<?= htmlspecialchars($imageUrl) ?>" class="img-fluid rounded">
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <a href="feeds_view.php" class="btn btn-secondary mt-4">← Back to List</a>
</body>
</html>
