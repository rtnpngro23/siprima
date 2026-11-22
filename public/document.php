<?php
declare(strict_types=1);
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/../app/config/koneksi.php';
require_once __DIR__ . '/functions/document_token.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$token = (string)($_GET['t'] ?? '');
$tokens = $_SESSION['document_tokens'] ?? [];
$entry = is_array($tokens) ? ($tokens[$token] ?? null) : null;
if (($_SESSION['logged_in'] ?? false) !== true || !preg_match('/^[a-f0-9]{36}$/', $token)
    || !is_array($entry) || ($entry['expires'] ?? 0) < time()
    || !hash_equals((string)($entry['actor'] ?? ''), siprima_document_actor_signature())) {
    http_response_code(404); exit('Dokumen tidak ditemukan.');
}
$file = realpath((string)($entry['path'] ?? ''));
$root = realpath((string)($entry['root'] ?? ''));
$extension = strtolower(pathinfo((string)$file, PATHINFO_EXTENSION));
$prefix = $root === false ? '' : rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if ($file === false || !is_file($file) || $prefix === ''
    || strncmp($file, $prefix, strlen($prefix)) !== 0
    || !in_array($extension, ['pdf','doc','docx','xls','xlsx','jpg','jpeg','jfif','jpe','png','gif'], true)) {
    http_response_code(404); exit('Dokumen tidak ditemukan.');
}
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($entry['name'] ?? basename($file)));
$mime = function_exists('mime_content_type') ? mime_content_type($file) : 'application/octet-stream';
session_write_close();
header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
readfile($file);
