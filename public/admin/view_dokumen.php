<?php
require_once '../error_handler.php';

// Wajib start session sebelum middleware admin_only melakukan validasi.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/../../app/middleware/admin_only.php';

$rawPath = (string)($_GET['path'] ?? '');
if ($rawPath === '') {
    http_response_code(400);
    exit('Path dokumen tidak valid.');
}

$decodedPath = rawurldecode($rawPath);
$decodedPath = str_replace("\0", '', $decodedPath);
$decodedPath = str_replace('\\', '/', $decodedPath);

while (strpos($decodedPath, '../') === 0) {
    $decodedPath = substr($decodedPath, 3);
}
$decodedPath = ltrim($decodedPath, '/');

if (strpos($decodedPath, 'uploads/') === false) {
    http_response_code(400);
    exit('Path dokumen di luar direktori yang diizinkan.');
}

$uploadsPos = strpos($decodedPath, 'uploads/');
$decodedPath = substr($decodedPath, $uploadsPos);

if (strpos($decodedPath, 'uploads/') !== 0) {
    http_response_code(400);
    exit('Path dokumen di luar direktori yang diizinkan.');
}

$baseDir = realpath(__DIR__ . '/../user/uploads');
if ($baseDir === false) {
    http_response_code(500);
    exit('Direktori upload tidak ditemukan.');
}

$targetPath = realpath(__DIR__ . '/../user/' . $decodedPath);
if ($targetPath === false || !is_file($targetPath)) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$basePrefix = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
$targetNormalized = str_replace('\\', '/', $targetPath);
if (strpos($targetNormalized, $basePrefix) !== 0) {
    http_response_code(403);
    exit('Akses path tidak diizinkan.');
}

$allowedViewExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];
$targetExtension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
if (!in_array($targetExtension, $allowedViewExtensions, true)) {
    http_response_code(403);
    exit('Jenis dokumen tidak diizinkan.');
}

$downloadName = trim((string)($_GET['name'] ?? ''));
if ($downloadName === '') {
    $downloadName = basename($targetPath);
}
$downloadName = preg_replace('/[^\w\s\.\-()]/u', '_', $downloadName);
if ($downloadName === '') {
    $downloadName = basename($targetPath);
}

$mimeType = function_exists('mime_content_type') ? mime_content_type($targetPath) : false;
if (!$mimeType) {
    $mimeType = 'application/octet-stream';
}

$forceDownload = isset($_GET['download']) && $_GET['download'] === '1';
$disposition = $forceDownload ? 'attachment' : 'inline';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . filesize($targetPath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');

if (ob_get_level()) {
    ob_end_clean();
}
readfile($targetPath);
exit;
