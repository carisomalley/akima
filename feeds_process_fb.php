<?php
// feeds_process_fb.php

session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/auth_check.php';
require __DIR__ . '/secure/db_connection.php';

// ─── 1) Load item ────────────────────────────────────────────────────────
$itemId = (int)($_REQUEST['item_id'] ?? 0);
if (!$itemId) {
    die('No item specified.');
}
$stmt = $pdo->prepare("
  SELECT feed_name,
         DATE_FORMAT(pub_date,'%Y-%m-%d %H:%i:%s') AS date,
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
$rawContent = trim($item['content'] ?: $excerpt);

// ─── 2) Load API keys ────────────────────────────────────────────────────
$keyStmt = $pdo->prepare("
  SELECT setting_key, setting_value
    FROM app_settings
   WHERE setting_key IN (
     'openai_api_key',
     'unsplash_api_key',
     'pexels_api_key',
     'pixabay_api_key',
     'fb_page_access_token',
     'fb_page_id'
   )
");
$keyStmt->execute();
$keys = $keyStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$openaiKey         = $keys['openai_api_key']         ?? null;
$unsplashKey       = $keys['unsplash_api_key']       ?? null;
$pexelsKey         = $keys['pexels_api_key']         ?? null;
$pixabayKey        = $keys['pixabay_api_key']        ?? null;
$fbPageAccessToken = $keys['fb_page_access_token']   ?? null;
$fbPageId          = $keys['fb_page_id']             ?? null;

if (!$openaiKey) {
    die('No OpenAI API key found. Please configure it in Settings.');
}

// ─── 3) ChatGPT + DALL·E helpers ─────────────────────────────────────────
function chatgpt_request(array $payload, string $key): string {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$key}",
        'Content-Type: application/json'
      ],
      CURLOPT_POSTFIELDS     => json_encode($payload)
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($resp, true);
    return trim($j['choices'][0]['message']['content'] ?? '');
}

function getArticleSummary(string $text, string $key): string {
    return chatgpt_request([
      'model' => 'gpt-3.5-turbo',
      'messages' => [
        ['role' => 'system', 'content' => 'You are a succinct summarizer. Condense the following into 2–3 sentences.'],
        ['role' => 'user', 'content' => $text]
      ],
      'max_tokens' => 150,
      'temperature' => 0.3
    ], $key);
}

/**
 * Suggest 5 specific, 2–4 word image-search phrases.
 */
function getSearchSuggestions(string $title, string $summary, string $key): array {
    $resp = chatgpt_request([
      'model' => 'gpt-3.5-turbo',
      'messages' => [
        [
          'role' => 'system',
          'content' =>
            "You are a visual designer assistant.  Given an article's title and summary, "
          . "suggest 5 specific image-search phrases (1–2 words each) that a designer could "
          . "enter into a royalty-free photo site to find relevant pictures.  "
          . "Focus on concrete objects, settings, or emotions described in the text."
        ],
        [
          'role' => 'user',
          'content' =>
            "Title: {$title}\n"
          . "Summary: {$summary}\n\n"
          . "Return exactly 5 phrases, comma separated.  Each phrase "
          . "should be 1–2 words long and reflect a real idea from the article."
        ]
      ],
      'max_tokens' => 100,
      'temperature' => 0.7
    ], $key);

    $terms = preg_split('/[,\r\n]+/', $resp);
    return array_values(array_filter(array_map(
        fn($t) => trim(preg_replace('/^\d+\.\s*/','',$t)),
        $terms
    )));
}

function getImagePromptOptions(string $title, string $summary, string $key): array {
    $resp = chatgpt_request([
      'model' => 'gpt-3.5-turbo',
      'messages' => [
        ['role' => 'system', 'content' => 'You are an expert at crafting evocative DALL·E prompts.'],
        ['role' => 'user', 'content' =>
           "Title: {$title}\nSummary: {$summary}\n\n"
          ."Generate 5 short image prompts (8–20 words), numbered 1–5, that:\n"
          ."- Name an artistic style\n"
          ."- Use minimalist composition\n"
          ."- Evoke the story above"
        ]
      ],
      'max_tokens' => 300,
      'temperature' => 0.8
    ], $key);

    preg_match_all('/\d+\.\s*(.+?)(?:\r?\n|$)/', $resp, $m);
    return array_slice($m[1] ?? [], 0, 5);
}

function callDalle(string $prompt, string $key): string {
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$key}",
        'Content-Type: application/json'
      ],
      CURLOPT_POSTFIELDS     => json_encode([
        'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024'
      ])
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($resp, true);
    return $j['data'][0]['url'] ?? '';
}

// ─── 4) Clear old session on fresh GET ──────────────────────────────────
if (empty($_REQUEST['stage'])) {
    unset(
      $_SESSION['post_body'],
      $_SESSION['hashtags'],
      $_SESSION['image_options'],
      $_SESSION['search_suggestions'],
      $_SESSION['rf_results'],
      $_SESSION['selected_rf_images']
    );
}

// ─── 5) Restore session state ───────────────────────────────────────────
$postBody          = $_SESSION['post_body']          ?? '';
$hashtags          = $_SESSION['hashtags']           ?? '';
$imageOptions      = $_SESSION['image_options']      ?? [];
$searchSuggestions = $_SESSION['search_suggestions'] ?? [];
$rf_results        = $_SESSION['rf_results']         ?? [];
$selectedImages    = $_SESSION['selected_rf_images'] ?? [];

// ─── 6) Read inputs up front ────────────────────────────────────────────
$stage        = $_REQUEST['stage']       ?? 'initial';
$userThoughts = trim($_REQUEST['user_thoughts'] ?? '');
$tone         = $_REQUEST['tone']        ?? 'inform';

if (!in_array($tone, ['inform','agree','disagree'])) {
    $tone = 'inform';
}

// summary = edited on POST or AI summary on GET
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
    $summary = trim($_POST['content']);
} else {
    $summary = getArticleSummary($rawContent, $openaiKey);
}

$error = '';
$successMessage = '';

// ─── 7) Handle POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // <<< UPLOAD START >>>
  // a) Handle user-uploaded image file
  if (isset($_FILES['user_image']) && $_FILES['user_image']['error'] === UPLOAD_ERR_OK) {
    $tmpFile  = $_FILES['user_image']['tmp_name'];
    $origName = basename($_FILES['user_image']['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed  = ['jpg','jpeg','png','gif'];

    if (!in_array($ext, $allowed)) {
      $error = 'Invalid file type. Only JPG, PNG or GIF allowed.';
    } else {
      // Create uploads directory if needed
      $uploadDir = __DIR__ . '/uploads/';
      if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
      }
      // Generate a unique filename
      $newName    = uniqid('upload_', true) . '.' . $ext;
      $destination = $uploadDir . $newName;

      if (move_uploaded_file($tmpFile, $destination)) {
        // Build a publicly‐accessible URL
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
  // <<< UPLOAD END >>>

  // b) Remove a selected image
  elseif (isset($_POST['remove_url'])) {
    $u = $_POST['remove_url'];
    if (($idx = array_search($u, $selectedImages)) !== false) {
      unset($selectedImages[$idx]);
      $selectedImages = array_values($selectedImages);
    }
    $_SESSION['selected_rf_images'] = $selectedImages;
  }

  // c) Add an RF image to selection
  elseif (isset($_POST['rf_add_url'])) {
    $u = $_POST['rf_add_url'];
    if ($u && !in_array($u, $selectedImages)) {
      $selectedImages[] = $u;
    }
    $_SESSION['selected_rf_images'] = $selectedImages;
  }

  // d) RF search (use full-resolution URLs)
  elseif (isset($_POST['rf_site'])) {
    $q = trim($_POST['rf_query'] ?? '');
    $rf_results = [];
    if (!$q) {
      $error = 'Please enter a search term.';
    } else {
      switch ($_POST['rf_site']) {
        case 'unsplash':
          if ($unsplashKey) {
            $url = "https://api.unsplash.com/search/photos?query=" . urlencode($q)
                 . "&per_page=6&client_id={$unsplashKey}";
            $d = @json_decode(file_get_contents($url), true);
            foreach ($d['results'] ?? [] as $img) {
              // use 'full' instead of 'small'
              $rf_results[] = $img['urls']['full'];
            }
          } else {
            $error = 'Unsplash key missing.';
          }
          break;

        case 'pexels':
          if ($pexelsKey) {
            $ch = curl_init("https://api.pexels.com/v1/search?query=" . urlencode($q) . "&per_page=6");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization:{$pexelsKey}"]);
            $d = json_decode(curl_exec($ch), true);
            curl_close($ch);
            foreach ($d['photos'] ?? [] as $img) {
              // use 'original' instead of 'medium'
              $rf_results[] = $img['src']['original'];
            }
          } else {
            $error = 'Pexels key missing.';
          }
          break;

        case 'pixabay':
          if ($pixabayKey) {
            $u = "https://pixabay.com/api/?key={$pixabayKey}&q=" . urlencode($q) . "&per_page=6";
            $d = @json_decode(file_get_contents($u), true);
            foreach ($d['hits'] ?? [] as $img) {
              // use 'largeImageURL' instead of 'previewURL'
              $rf_results[] = $img['largeImageURL'];
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
    $rf_results = $rf_results;
  }

  // e) INITIAL: generate FB post + hashtags + prompts + suggestions
  elseif ($stage === 'initial') {
    if ($summary === '') {
      $error = 'Please edit the summary before generating.';
    }
    if (!$error) {
      // 1) Post + hashtags — make system prompt explicit about user_thoughts + tone
      $sys = [
        'role' => 'system',
        'content' =>
          "You are a social-media assistant specialized in crafting Facebook posts.\n"
         ."Always weave in the user’s comments and follow the specified TONE exactly:\n"
         ."- inform: neutral, factual, balanced\n"
         ."- agree: supportive, positive\n"
         ."- disagree: critical, questioning\n\n"
         ."Your post should be structured as:\n"
         ."- Opening sentence (hook)\n"
         ."- 1–2 short paragraphs expanding on the topic and incorporating user comments\n"
         ."- A concluding line that invites engagement or provides a call to action\n"
         ."- End with 3–5 relevant hashtags.\n"
      ];
      $usr = [
        'role' => 'user',
        'content' =>
          "Title: {$title}\n"
        . "Summary: {$summary}\n"
        . "User Comments: {$userThoughts}\n"
        . "Tone: {$tone}\n\n"
        . "Write the Facebook post accordingly, then append hashtags."
      ];
      $resp = chatgpt_request([
        'model' => 'gpt-3.5-turbo',
        'messages' => [$sys, $usr],
        'max_tokens' => 300,
        'temperature' => 0.7
      ], $openaiKey);

      if (!$resp) {
        $error = 'OpenAI returned empty.';
      } else {
        if (preg_match('/^(.*?)(?=\s#)/s', $resp, $m)) {
          $postBody = trim($m[1]);
          $hashtags = trim(substr($resp, strlen($m[1])));
        } else {
          $postBody = trim($resp);
          $hashtags = '';
        }
        // Ensure "#ReelFamilies" is always present:
        if (str_contains($hashtags, '#ReelFamilies') === false) {
          $hashtags = trim($hashtags) . ' #ReelFamilies';
        }
        // Now save both into the session
        $_SESSION['post_body'] = $postBody;
        $_SESSION['hashtags']  = $hashtags;
      }

      // 2) DALL·E prompts (unchanged)
      if (!$error) {
        $imageOptions = getImagePromptOptions($title, $summary, $openaiKey);
        $_SESSION['image_options'] = $imageOptions;
      }

      // 3) RF search suggestions (unchanged)
      if (!$error) {
        $searchSuggestions = getSearchSuggestions($title, $summary, $openaiKey);
        $_SESSION['search_suggestions'] = $searchSuggestions;
      }

      $stage = 'choose_image';
    }
  }

  // f) CHOOSE_IMAGE: generate AI image (no longer sets stage = 'generated')
  elseif ($stage === 'choose_image' && isset($_POST['generate_image_index'])) {
    $i = (int)$_POST['generate_image_index'];
    $p = $_POST['image_prompts'][$i] ?? '';
    if ($p) {
      $url = callDalle($p, $openaiKey);
      if ($url && !in_array($url, $selectedImages)) {
        $selectedImages[] = $url;
      }
      $_SESSION['selected_rf_images'] = $selectedImages;
      // stay in 'choose_image' so the new AI image appears in the gallery immediately
    } else {
      $error = 'No prompt provided.';
    }
  }

  // g) PUBLISH TO FACEBOOK
  elseif (isset($_POST['publish_fb'])) {
    // Ensure we have FB credentials
    if (!$fbPageAccessToken || !$fbPageId) {
      $error = 'Facebook Page Token or Page ID is not configured.';
    } else {
      // 1) Build the message
      $message = trim($postBody) . "\n\n" . trim($hashtags);

      // 2) Upload each selected image as unpublished to get back a photo_id
      $photo_ids = [];
      foreach ($selectedImages as $imgUrl) {
        $fbPhotoEndpoint = "https://graph.facebook.com/{$fbPageId}/photos";

        // Convert relative URLs (“uploads/…”) to absolute:
        $absoluteUrl = $imgUrl;
        if (strpos($imgUrl, 'http') !== 0) {
          $absoluteUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($imgUrl, '/');
        }

        $postFields = [
          'url'           => $absoluteUrl,
          'published'     => 'false',
          'access_token'  => $fbPageAccessToken,
        ];

        $ch = curl_init($fbPhotoEndpoint);
        curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST           => true,
          CURLOPT_POSTFIELDS     => $postFields,
        ]);
        $resp = curl_exec($ch);
        if (curl_errno($ch)) {
          $error = 'cURL error while uploading image: ' . curl_error($ch);
          curl_close($ch);
          break;
        }
        curl_close($ch);

        $j = json_decode($resp, true);
        if (isset($j['id'])) {
          $photo_ids[] = $j['id'];
        } else {
          $error = 'Facebook Photo upload failed: ' . ($j['error']['message'] ?? $resp);
          break;
        }
      } // end foreach

      // 3) If no error so far, create a feed post referencing all photo IDs
      if (!$error) {
        $feedEndpoint = "https://graph.facebook.com/{$fbPageId}/feed";

        $attached_media = array_map(
          fn($pid) => ['media_fbid' => $pid],
          $photo_ids
        );

        $postFields = [
          'message'        => $message,
          'attached_media'=> json_encode($attached_media),
          'access_token'   => $fbPageAccessToken,
        ];

        $ch2 = curl_init($feedEndpoint);
        curl_setopt_array($ch2, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST           => true,
          CURLOPT_POSTFIELDS     => $postFields,
        ]);
        $resp2 = curl_exec($ch2);
        if (curl_errno($ch2)) {
          $error = 'cURL error posting to feed: ' . curl_error($ch2);
          curl_close($ch2);
        } else {
          curl_close($ch2);
          $j2 = json_decode($resp2, true);
          if (isset($j2['id'])) {
            $successMessage = "Posted to Facebook successfully (Post ID: {$j2['id']}).";
          } else {
            $error = 'Facebook Feed post failed: ' . ($j2['error']['message'] ?? $resp2);
          }
        }
      }
    }
    // Stay in choose_image so we can show errors/success
    $stage = 'choose_image';
  }

} // end POST handler

// ─── 8) Reload persisted state ───────────────────────────────────────────
$postBody          = $_SESSION['post_body']          ?? '';
$hashtags          = $_SESSION['hashtags']           ?? '';
$imageOptions      = $_SESSION['image_options']      ?? [];
$searchSuggestions = $_SESSION['search_suggestions'] ?? [];
$rf_results        = $_SESSION['rf_results']         ?? [];
$selectedImages    = $_SESSION['selected_rf_images'] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Facebook Post Builder</title>
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
  <h1 class="h4 mb-3">Facebook Post Builder</h1>

  <!-- Feed card -->
  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title"><?= htmlspecialchars($title) ?></h5>
      <h6 class="card-subtitle text-muted mb-2"><?= htmlspecialchars($feedName) ?> — <?= htmlspecialchars($date) ?></h6>
      <p class="card-text"><?= nl2br(htmlspecialchars($excerpt)) ?></p>
      <?php if ($link): ?>
        <a href="<?= htmlspecialchars($link) ?>" target="_blank" class="card-link">Original</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Show success or error messages right here -->
  <?php if (!empty($successMessage)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
  <?php elseif (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- INITIAL: summary/comments/tone -->
  <?php if ($stage === 'initial'): ?>
    <form method="post" class="mb-4">
      <input type="hidden" name="item_id" value="<?= $itemId ?>">
      <div class="mb-3">
        <label class="form-label">Summary</label>
        <textarea name="content" rows="5" class="form-control" required><?= htmlspecialchars($summary) ?></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Your Comments</label>
        <textarea name="user_thoughts" rows="2" class="form-control" placeholder="Add your angle…"><?= htmlspecialchars($userThoughts) ?></textarea>
      </div>
      <div class="mb-3 w-25">
        <label class="form-label">Tone</label>
        <select name="tone" class="form-select">
          <option value="inform" <?= $tone === 'inform' ? 'selected' : '' ?>>Inform</option>
          <option value="agree" <?= $tone === 'agree' ? 'selected' : '' ?>>Agree</option>
          <option value="disagree" <?= $tone === 'disagree' ? 'selected' : '' ?>>Disagree</option>
        </select>
      </div>
      <button class="btn btn-primary">Generate Facebook Post</button>
      <a href="feeds_view.php" class="btn btn-secondary ms-2">← Back to List</a>
    </form>
  <?php endif; ?>

  <!-- CHOOSE_IMAGE: show post, gallery, upload form, and other image tools -->
  <?php if ($stage === 'choose_image'): ?>
    <div class="mb-3">
      <label class="form-label"><strong>Post</strong></label>
      <textarea class="form-control" rows="4" readonly><?= htmlspecialchars($postBody) ?></textarea>
    </div>
    <?php if ($hashtags): ?>
      <div class="mb-3">
        <label class="form-label"><strong>Hashtags</strong></label>
        <input class="form-control" readonly value="<?= htmlspecialchars($hashtags) ?>">
      </div>
    <?php endif; ?>

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

    <!-- <<< UPLOAD FORM >>> -->
    <div class="mb-4">
      <h5>Upload your own image</h5>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <input type="hidden" name="stage"   value="choose_image">
        <input type="file" name="user_image" accept="image/*" class="form-control mb-2">
        <button type="submit" class="btn btn-outline-secondary">Upload Image</button>
      </form>
    </div>
    <!-- <<< /UPLOAD FORM >>> -->

    <!-- Search suggestions -->
    <?php if ($searchSuggestions): ?>
      <div class="mb-3">
        <small class="text-muted">Try these search terms:</small><br>
        <?php foreach ($searchSuggestions as $t): ?>
          <button type="button" class="btn btn-sm btn-outline-info suggestion-btn"><?= htmlspecialchars($t) ?></button>
        <?php endforeach; ?>
      </div>
      <script>
        document.querySelectorAll('.suggestion-btn').forEach(b =>
          b.addEventListener('click', () => document.querySelector('input[name="rf_query"]').value = b.textContent)
        );
      </script>
    <?php endif; ?>

    <!-- RF search form -->
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

    <!-- RF results -->
    <?php if ($rf_results): ?>
      <div class="row g-2 mb-4">
        <?php foreach ($rf_results as $u): ?>
          <?php if (!in_array($u, $selectedImages)): /** only show unselected */ ?>
            <div class="col-4 col-md-2">
              <div class="thumb-container position-relative">
                <img src="<?= htmlspecialchars($u) ?>" class="img-fluid rounded">
                <form method="post" style="position:absolute; top:5px; right:5px; z-index:10;">
                  <input type="hidden" name="item_id"    value="<?= $itemId ?>">
                  <input type="hidden" name="stage"      value="choose_image">
                  <input type="hidden" name="rf_add_url" value="<?= htmlspecialchars($u) ?>">
                  <button type="submit" class="btn btn-sm btn-primary thumb-checkbox">＋</button>
                </form>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- AI prompts -->
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

    <!-- Publish to Facebook button -->
    <div class="mt-4">
      <form method="post" style="display:inline-block;">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <input type="hidden" name="stage"   value="choose_image">
        <button name="publish_fb" value="1" class="btn btn-success">
          Publish to Facebook
        </button>
      </form>
      <a href="?item_id=<?= $itemId ?>" class="btn btn-outline-secondary ms-2">← Change Summary/Tone</a>
    </div>
  <?php endif; ?>

  <!-- GENERATED: final gallery (if you still want to use this stage) -->
  <?php if ($stage === 'generated'): ?>
    <div class="mb-4">
      <h5>Selected Images</h5>
      <div class="row g-2">
        <?php foreach ($selectedImages as $u): ?>
          <div class="col-4 col-md-2"><img src="<?= htmlspecialchars($u) ?>" class="img-fluid rounded"></div>
        <?php endforeach; ?>
      </div>
    </div>
    <a href="?item_id=<?= $itemId ?>" class="btn btn-outline-primary">← Back to Images</a>
  <?php endif; ?>

  <a href="feeds_view.php" class="btn btn-secondary mt-4">← Back to List</a>
</body>
</html>
