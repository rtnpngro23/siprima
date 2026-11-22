<?php
require_once '../error_handler.php';
include '../../app/middleware/operator_only.php';

// Parameter path lama membocorkan struktur folder dan tidak lagi didukung.
if (array_key_exists('path', $_GET)) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    http_response_code(403);
    exit('Akses ditolak.');
}

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

// Normalisasi prefix path yang mungkin tersimpan beda-beda antar modul
if (strpos($decodedPath, 'user/uploads/') === 0) {
    $decodedPath = substr($decodedPath, 5); // jadi uploads/...
} elseif (strpos($decodedPath, 'public/user/uploads/') === 0) {
    $decodedPath = substr($decodedPath, 12); // jadi uploads/...
} elseif (strpos($decodedPath, 'public/uploads/') === 0) {
    $decodedPath = substr($decodedPath, 7); // jadi uploads/...
}

if (strpos($decodedPath, 'uploads/') !== 0 || trim($decodedPath) === 'uploads/') {
    http_response_code(400);
    exit('Path dokumen di luar direktori yang diizinkan.');
}

// Path relatif di bawah folder uploads/
$relativeUploadPath = substr($decodedPath, strlen('uploads/'));

$baseDirs = array_filter([
    realpath(__DIR__ . '/uploads'),               // public/user/uploads
    realpath(dirname(__DIR__) . '/uploads'),      // public/uploads
    realpath(dirname(__DIR__, 2) . '/uploads')    // project-root/uploads (fallback)
]);

$targetPath = false;
foreach ($baseDirs as $baseDir) {
    $candidatePath = realpath(rtrim($baseDir, '/\\') . '/' . ltrim($relativeUploadPath, '/'));
    if ($candidatePath === false || !is_file($candidatePath)) {
        continue;
    }

    $basePrefix = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
    $candidateNormalized = str_replace('\\', '/', $candidatePath);
    if (strpos($candidateNormalized, $basePrefix) === 0) {
        $targetPath = $candidatePath;
        break;
    }
}

if ($targetPath === false) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$allowedDownloadExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];
$targetExtension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
if (!in_array($targetExtension, $allowedDownloadExtensions, true)) {
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

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . filesize($targetPath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');

if (ob_get_level()) {
    ob_end_clean();
}
readfile($targetPath);
exit;
