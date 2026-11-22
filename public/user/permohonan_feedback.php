<?php
declare(strict_types=1);

require_once '../error_handler.php';
require_once '../../app/config/koneksi.php';
require_once __DIR__ . '/functions/permohonan_feedback.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function feedback_response(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data + ['csrf_token' => csrf_token()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate()) {
    feedback_response(403, ['success'=>false, 'message'=>'Permintaan tidak valid.']);
}

$action = (string)($_POST['action'] ?? '');
$sourceTable = trim((string)($_POST['source_table'] ?? ''));
$permohonanId = (int)($_POST['permohonan_id'] ?? 0);
$identity = permohonan_feedback_identity($koneksi, $sourceTable, $permohonanId);
if (!$identity) feedback_response(403, ['success'=>false, 'message'=>'Akses permohonan ditolak.']);

$role = $identity['role'];
if ($action === 'trigger') {
    $trigger = (string)($_POST['trigger_type'] ?? '');
    if (!in_array($trigger, ['konfirmasi', 'download'], true)) {
        feedback_response(422, ['success'=>false, 'message'=>'Pemicu feedback tidak valid.']);
    }
    $stmt = $koneksi->prepare("INSERT IGNORE INTO tb_permohonan_feedback (source_table,permohonan_id,respondent_user_id,respondent_role,jenis_layanan,nama_pemohon,nama_subjek,trigger_type) VALUES (?,?,?,?,?,?,?,?)");
    if (!$stmt) feedback_response(503, ['success'=>false, 'message'=>'Fitur feedback belum tersedia.']);
    $stmt->bind_param('siisssss', $sourceTable, $permohonanId, $identity['user_id'], $role, $identity['layanan'], $identity['nama_pemohon'], $identity['nama_subjek'], $trigger);
    $stmt->execute();
    $stmt->close();
    $record = permohonan_feedback_get($koneksi, $sourceTable, $permohonanId, $role);
    feedback_response(200, ['success'=>true, 'should_prompt'=>($record['status'] ?? '') === 'pending']);
}

$record = permohonan_feedback_get($koneksi, $sourceTable, $permohonanId, $role);
if (!$record || $record['status'] !== 'pending') {
    feedback_response(200, ['success'=>true, 'saved'=>false]);
}

if ($action === 'skip') {
    $stmt = $koneksi->prepare("UPDATE tb_permohonan_feedback SET status='skipped',skipped_at=NOW(),updated_at=NOW() WHERE id=? AND status='pending'");
    $stmt->bind_param('i', $record['id']);
    $stmt->execute();
    $saved = $stmt->affected_rows > 0;
    $stmt->close();
    feedback_response(200, ['success'=>true, 'saved'=>$saved, 'show_skm'=>$saved && $role === 'pemohon']);
}

if ($action !== 'submit') feedback_response(422, ['success'=>false, 'message'=>'Aksi tidak valid.']);
require_once __DIR__ . '/functions/feedback_validation.php';
try {
    $feedback = permohonan_feedback_validate($_POST);
} catch (InvalidArgumentException $e) {
    feedback_response(422, ['success'=>false, 'message'=>$e->getMessage()]);
}
$type = $feedback['type'];
$ratingValue = $feedback['rating'];
$serviceRating = $feedback['service_rating'];
$message = $feedback['message'];
try {
    $stmt = $koneksi->prepare("UPDATE tb_permohonan_feedback SET status='submitted',jenis_feedback=?,rating=?,service_rating=?,pesan=?,submitted_at=NOW(),updated_at=NOW() WHERE id=? AND status='pending'");
    if (!$stmt) throw new RuntimeException('Feedback storage unavailable');
    $stmt->bind_param('siisi', $type, $ratingValue, $serviceRating, $message, $record['id']);
    if (!$stmt->execute()) throw new RuntimeException('Feedback storage failed');
    $saved = $stmt->affected_rows > 0;
    $stmt->close();
} catch (Throwable $e) {
    feedback_response(503, ['success'=>false, 'message'=>'Tanggapan belum dapat disimpan. Silakan coba lagi atau hubungi administrator.']);
}
feedback_response(200, ['success'=>true, 'saved'=>$saved, 'show_skm'=>$saved && $role === 'pemohon']);