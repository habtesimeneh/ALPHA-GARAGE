<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — SECURE MEDIA STREAMING CONTROLLER
 * Streams uploaded video files and custom thumbnails from protected storage.
 * Enforces strict basename isolation, MIME whitelisting, path traversal defenses,
 * and HTTP 206 Partial Content range requests for video seeking.
 */

require __DIR__ . '/../app/bootstrap.php';

$type = (string) ($_GET['type'] ?? 'video');
$filename = (string) ($_GET['file'] ?? '');

// Strict filename validation: allow only alphanumeric, underscores, hyphens, and dot
$safeName = basename($filename);
if ($safeName === '' || $safeName !== $filename || !preg_match('/^[a-zA-Z0-9_\-]+\.[a-zA-Z0-9]{3,4}$/', $safeName)) {
    abort(404, 'Invalid media file reference.');
}

$ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));

$allowedMimes = [
    'video' => [
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'ogg'  => 'video/ogg',
        'mov'  => 'video/quicktime',
    ],
    'thumbnail' => [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ],
];

if (!isset($allowedMimes[$type][$ext])) {
    abort(403, 'Unauthorized media format.');
}

$subDir = $type === 'video' ? 'uploads/videos' : 'uploads/thumbnails';
$baseDir = storage_path($subDir);
$filePath = $baseDir . DIRECTORY_SEPARATOR . $safeName;

$realBase = realpath($baseDir);
$realFile = realpath($filePath);

if (!$realBase || !$realFile || !str_starts_with($realFile, $realBase) || !is_file($realFile)) {
    abort(404, 'Media asset not found.');
}

$fileSize = (int) filesize($realFile);
$mimeType = $allowedMimes[$type][$ext];

// Disable output buffering
while (ob_get_level()) {
    ob_end_clean();
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=604800, immutable');
header('Content-Type: ' . $mimeType);
header('Accept-Ranges: bytes');

// Handle HTTP Range Requests (RFC 7233) for HTML5 video seeking
$start = 0;
$end = $fileSize - 1;
$status = 200;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $matches)) {
        if (!empty($matches[1])) {
            $start = (int) $matches[1];
        }
        if (!empty($matches[2])) {
            $end = (int) $matches[2];
        }
        if ($start > $end || $start >= $fileSize) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header("Content-Range: bytes */$fileSize");
            exit;
        }
        $status = 206;
    }
}

$length = $end - $start + 1;

if ($status === 206) {
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$fileSize");
} else {
    http_response_code(200);
}

header("Content-Length: $length");

$fp = fopen($realFile, 'rb');
if (!$fp) {
    abort(500, 'Unable to stream media file.');
}

fseek($fp, $start);
$chunkSize = 64 * 1024; // 64KB chunks
$bytesRemaining = $length;

while (!feof($fp) && $bytesRemaining > 0 && !connection_aborted()) {
    $readLen = min($chunkSize, $bytesRemaining);
    $data = fread($fp, $readLen);
    if ($data === false) {
        break;
    }
    echo $data;
    flush();
    $bytesRemaining -= strlen($data);
}

fclose($fp);
exit;
