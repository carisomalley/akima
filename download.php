<?php
// download.php

// 1) Make sure a URL was passed
if (empty($_GET['url'])) {
    http_response_code(400);
    exit('No URL specified.');
}

$url = $_GET['url'];

// 2) Check if it's a remote URL (starts with http:// or https://)
if (preg_match('#^https?://#i', $url)) {
    // ─── Remote image via cURL ───────────────────────────────────────
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        // (you can add a time‐out or other options here, if desired)
    ]);
    $data = curl_exec($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($data === false) {
        http_response_code(404);
        exit('Failed to fetch image.');
    }

    // Derive a filename from the URL path (fallback to “image” if none)
    $parsed = parse_url($url, PHP_URL_PATH);
    $filename = basename($parsed);
    if (empty($filename)) {
        $filename = 'image';
    }

    header("Content-Type: {$contentType}");
    // Force download
    header("Content-Disposition: attachment; filename=\"" . htmlspecialchars($filename) . "\"");
    echo $data;
    exit;
}

// 3) Otherwise, assume it’s a local file under this project’s directory
// Prevent directory traversal by resolving real paths:
$baseDir = realpath(__DIR__);              // e.g. /home/…/public_html
$path    = realpath($baseDir . '/' . $url); // e.g. /home/…/public_html/uploads/abcd.png

if ($path === false || strpos($path, $baseDir) !== 0) {
    // Either file doesn’t exist, or it’s outside of our base directory
    http_response_code(400);
    exit('Invalid local path.');
}

if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

// Determine the MIME type (e.g. image/png, image/jpeg) 
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $path);
finfo_close($finfo);

// Get a safe filename for the “Save As” prompt
$filename = basename($path);

header("Content-Type: {$mime}");
header("Content-Disposition: attachment; filename=\"" . htmlspecialchars($filename) . "\"");

// Stream the file directly
readfile($path);
exit;
