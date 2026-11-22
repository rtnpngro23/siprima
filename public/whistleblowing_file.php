<?php

declare(strict_types=1);

require_once __DIR__.'/error_handler.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__.'/functions/whistleblowing.php';

$fileId = (int) ($_GET['id'] ?? 0);
$userId = (int) ($_SESSION['id_user'] ?? 0);
$role = (string) ($_SESSION['role'] ?? '');
if ($fileId <= 0 || $userId <= 0 || ! in_array($role, ['user', 'admin'], true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

require_once __DIR__.'/../app/config/koneksi.php';

$stmt = $koneksi->prepare('SELECT l.*,a.pemohon_id,t.visibility FROM tb_whistleblowing_lampiran l INNER JOIN tb_whistleblowing_aduan a ON a.id=l.aduan_id LEFT JOIN tb_whistleblowing_tindak_lanjut t ON t.id=l.tindak_lanjut_id WHERE l.id=? LIMIT 1');
if (! $stmt) {
    http_response_code(503);
    exit('Berkas belum tersedia.');
}
$stmt->bind_param('i', $fileId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (! $file) {
    http_response_code(404);
    exit('Berkas tidak ditemukan.');
}

if ($role === 'user') {
    wbs_require_regular_applicant($koneksi);
    if ((int) $file['pemohon_id'] !== $userId || (string) ($file['visibility'] ?? 'pemohon') === 'internal') {
        http_response_code(403);
        exit('Akses berkas ditolak.');
    }
}

$storageRoot = realpath(dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'whistleblowing');
$relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $file['file_path']);
$absolutePath = realpath(dirname(__DIR__).DIRECTORY_SEPARATOR.$relativePath);
if (! $storageRoot || ! $absolutePath || ! str_starts_with($absolutePath, $storageRoot.DIRECTORY_SEPARATOR) || ! is_file($absolutePath)) {
    http_response_code(404);
    exit('Berkas tidak ditemukan.');
}

$details = wbs_image_upload_details($absolutePath);
$mime = upload_security_detect_mime($absolutePath);
if ($details === null || $mime !== $details['mime'] || ! in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit('Berkas tidak ditemukan.');
}
$original = basename(str_replace(["\0", "\r", "\n"], '', (string) $file['original_name']));
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($absolutePath));
header('Content-Disposition: attachment; filename="lampiran"; filename*=UTF-8\'\''.rawurlencode($original));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('X-Frame-Options: DENY');
header('Cache-Control: private, no-store, max-age=0');
readfile($absolutePath);
exit;
