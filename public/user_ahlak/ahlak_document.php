<?php
declare(strict_types=1);

require_once '../error_handler.php';
require_once __DIR__ . '/../../app/config/koneksi.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$token = (string)($_GET['t'] ?? '');
$entry = $_SESSION['ahlak_document_tokens'][$token] ?? null;
$loggedIn = ($_SESSION['logged_in'] ?? false) === true;
$role = (string)($_SESSION['role'] ?? '');
$isAhlak = !empty($_SESSION['id_puskes']) || ($_SESSION['user_type'] ?? '') === 'puskesmas';

if (!$loggedIn || !$isAhlak || !in_array($role, ['user', 'operator'], true)
    || !preg_match('/^[a-f0-9]{36}$/', $token) || !is_array($entry)
    || ($entry['expires'] ?? 0) < time()) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$file = (string)($entry['path'] ?? '');
$root = realpath(__DIR__ . '/uploads');
$realFile = realpath($file);
$rootPrefix = $root === false ? '' : rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if ($realFile === false || !is_file($realFile) || $rootPrefix === ''
    || !in_array(strtolower(pathinfo((string)$realFile, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'jfif', 'jpe', 'png'], true)
    || strncmp($realFile, $rootPrefix, strlen($rootPrefix)) !== 0) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($entry['name'] ?? basename($realFile)));
$mime = function_exists('mime_content_type') ? mime_content_type($realFile) : 'application/octet-stream';
$disposition = isset($_GET['download']) ? 'attachment' : 'inline';

session_write_close();
header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($realFile));
header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
readfile($realFile);
