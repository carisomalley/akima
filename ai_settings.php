<?php
// ai_settings.php

// DEV: show all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/auth_check.php';
require __DIR__ . '/secure/db_connection.php';

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey            = trim($_POST['api_key'] ?? '');
    $instaToken        = trim($_POST['insta_token'] ?? '');
    $instaUserId       = trim($_POST['insta_user_id'] ?? '');
    $unsplashKey       = trim($_POST['unsplash_api_key'] ?? '');
    $pexelsKey         = trim($_POST['pexels_api_key'] ?? '');
    $pixabayKey        = trim($_POST['pixabay_api_key'] ?? '');
    $fbPageAccessToken = trim($_POST['fb_page_access_token'] ?? ''); // <<< ADDED
    $fbPageId          = trim($_POST['fb_page_id'] ?? '');           // <<< ADDED

    try {
        // Upsert OpenAI key
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES ('openai_api_key', :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute(['val' => $apiKey]);

        // Upsert Instagram Access Token
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES ('instagram_access_token', :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute(['val' => $instaToken]);

        // Upsert Instagram User ID
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES ('instagram_user_id', :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute(['val' => $instaUserId]);

        // Upsert Unsplash API Key
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES ('unsplash_api_key', :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute(['val' => $unsplashKey]);

        // Upsert Pexels API Key
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES ('pexels_api_key', :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute(['val' => $pexelsKey]);

        // Upsert Pixabay API Key
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES ('pixabay_api_key', :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute(['val' => $pixabayKey]);

        // Upsert Facebook Page Access Token  <<< ADDED
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES ('fb_page_access_token', :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute(['val' => $fbPageAccessToken]);  // <<< ADDED

        // Upsert Facebook Page ID  <<< ADDED
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES ('fb_page_id', :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute(['val' => $fbPageId]);            // <<< ADDED

        $message = 'Settings saved successfully.';
    } catch (\PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Fetch current settings for form prefilling
function fetchSetting(PDO $pdo, string $key): string {
    $stmt = $pdo->prepare("
      SELECT setting_value
        FROM app_settings
       WHERE setting_key = :key
    ");
    $stmt->execute(['key' => $key]);
    return $stmt->fetchColumn() ?: '';
}

$currentKey            = fetchSetting($pdo, 'openai_api_key');
$currentInstaToken     = fetchSetting($pdo, 'instagram_access_token');
$currentInstaUser      = fetchSetting($pdo, 'instagram_user_id');
$currentUnsplashKey    = fetchSetting($pdo, 'unsplash_api_key');
$currentPexelsKey      = fetchSetting($pdo, 'pexels_api_key');
$currentPixabayKey     = fetchSetting($pdo, 'pixabay_api_key');
$currentFbPageToken    = fetchSetting($pdo, 'fb_page_access_token'); // <<< ADDED
$currentFbPageId       = fetchSetting($pdo, 'fb_page_id');           // <<< ADDED
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>AI & Image API Settings</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">
  <h1 class="h3 mb-4">AI & Image API Settings</h1>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <!-- OpenAI Key -->
    <div class="mb-3">
      <label for="api_key" class="form-label">OpenAI API Key</label>
      <input
        type="text"
        class="form-control"
        id="api_key"
        name="api_key"
        value="<?= htmlspecialchars($currentKey) ?>"
        placeholder="sk-…"
        required
      >
      <div class="form-text">
        Enter your ChatGPT/OpenAI API key (sk-…). This will be used by all AI integrations.
      </div>
    </div>

    <!-- Instagram Access Token -->
    <div class="mb-3">
      <label for="insta_token" class="form-label">Instagram Access Token</label>
      <input
        type="text"
        class="form-control"
        id="insta_token"
        name="insta_token"
        value="<?= htmlspecialchars($currentInstaToken) ?>"
        placeholder="EAA…"
      >
      <div class="form-text">
        Long-lived token with <code>instagram_content_publish</code> scope.
      </div>
    </div>

    <!-- Instagram User ID -->
    <div class="mb-3">
      <label for="insta_user_id" class="form-label">Instagram User ID</label>
      <input
        type="text"
        class="form-control"
        id="insta_user_id"
        name="insta_user_id"
        value="<?= htmlspecialchars($currentInstaUser) ?>"
        placeholder="1234567890"
      >
      <div class="form-text">
        Numeric ID of your Instagram Business/Creator account.
      </div>
    </div>

    <!-- Unsplash API Key -->
    <div class="mb-3">
      <label for="unsplash_api_key" class="form-label">Unsplash API Key</label>
      <input
        type="text"
        class="form-control"
        id="unsplash_api_key"
        name="unsplash_api_key"
        value="<?= htmlspecialchars($currentUnsplashKey) ?>"
        placeholder="Your Unsplash Access Key"
      >
      <div class="form-text">
        Access Key from your Unsplash developer app.
      </div>
    </div>

    <!-- Pexels API Key -->
    <div class="mb-3">
      <label for="pexels_api_key" class="form-label">Pexels API Key</label>
      <input
        type="text"
        class="form-control"
        id="pexels_api_key"
        name="pexels_api_key"
        value="<?= htmlspecialchars($currentPexelsKey) ?>"
        placeholder="Your Pexels API Key"
      >
      <div class="form-text">
        API Key from your Pexels account.
      </div>
    </div>

    <!-- Pixabay API Key -->
    <div class="mb-3">
      <label for="pixabay_api_key" class="form-label">Pixabay API Key</label>
      <input
        type="text"
        class="form-control"
        id="pixabay_api_key"
        name="pixabay_api_key"
        value="<?= htmlspecialchars($currentPixabayKey) ?>"
        placeholder="Your Pixabay API Key"
      >
      <div class="form-text">
        API Key from your Pixabay account.
      </div>
    </div>

    <!-- Facebook Page Access Token -->
    <div class="mb-3">
      <label for="fb_page_access_token" class="form-label">Facebook Page Access Token</label>
      <input
        type="text"
        class="form-control"
        id="fb_page_access_token"
        name="fb_page_access_token"
        value="<?= htmlspecialchars($currentFbPageToken) ?>"
        placeholder="EAAGm0PX4ZCpsBA..."
      >
      <div class="form-text">
        Page Access Token with <code>pages_manage_posts</code> scope, used for posting to your Facebook Page.
      </div>
    </div>

    <!-- Facebook Page ID -->
    <div class="mb-3">
      <label for="fb_page_id" class="form-label">Facebook Page ID</label>
      <input
        type="text"
        class="form-control"
        id="fb_page_id"
        name="fb_page_id"
        value="<?= htmlspecialchars($currentFbPageId) ?>"
        placeholder="123456789012345"
      >
      <div class="form-text">
        Numeric ID of the Facebook Page where posts will be published.
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Save Settings</button>
    <a href="feeds_view.php" class="btn btn-secondary ms-2">← Back to Feeds</a>
  </form>
</body>
</html>
