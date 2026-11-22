<?php
require_once __DIR__ . '/../error_handler.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include __DIR__ . '/../../app/middleware/admin_only.php';
include __DIR__ . '/../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/email_delivery.php';

$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0) { http_response_code(400); exit('Permintaan tidak valid.'); }
$stmt = $koneksi->prepare("SELECT f.* FROM tb_konfirmasi_dokumen_email_file f INNER JOIN tb_konfirmasi_dokumen_email k ON k.id=f.konfirmasi_id WHERE f.id=? AND k.status_konfirmasi IN ('diunggah_operator','sebagian_diunggah_perlu_pengajuan_baru') LIMIT 1");
$stmt->bind_param('i', $id); $stmt->execute(); $file = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$file) { http_response_code(404); exit('Dokumen tidak ditemukan.'); }
$path = email_delivery_resolve_file($file);
if ($path === false) { http_response_code(404); exit('File tidak ditemukan.'); }
$name = preg_replace('/[^\w\s.\-()]/u', '_', basename((string)$file['original_name'])) ?: 'dokumen.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.str_replace('"','',$name).'"');
header('Content-Length: '.filesize($path));
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Referrer-Policy: no-referrer');
readfile($path); exit;
