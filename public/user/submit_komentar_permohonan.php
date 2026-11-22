<?php
require_once '../error_handler.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/komentar_permohonan.php';
require_once __DIR__ . '/../functions/notifikasi.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metode tidak diizinkan.']);
    exit;
}

if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired.']);
    exit;
}

$userId = (int)$_SESSION['id_user'];
$role = komentar_permohonan_normalize_role((string)($_SESSION['role'] ?? 'user'));
if (!in_array($role, ['user', 'petugas_desa'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Role tidak diizinkan mengirim komentar dari halaman ini.']);
    exit;
}

$permohonanId = (int)($_POST['permohonan_id'] ?? 0);
$jenis = strtolower(trim((string)($_POST['jenis'] ?? '')));
$sourceTable = komentar_permohonan_map_jenis($jenis);
$komentar = (string)($_POST['komentar'] ?? '');
$parentIdRaw = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
$parentId = $parentIdRaw && $parentIdRaw > 0 ? $parentIdRaw : null;

if ($permohonanId <= 0 || $sourceTable === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Data permohonan tidak valid.']);
    exit;
}

$error = null;
$commentId = komentar_permohonan_create(
    $koneksi,
    $sourceTable,
    $permohonanId,
    $userId,
    $role,
    $komentar,
    $error,
    $parentId
);

if ($commentId === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $error ?: 'Komentar gagal disimpan.']);
    exit;
}

$notificationCount = buatNotifikasiOperatorPermohonan(
    $koneksi,
    $sourceTable,
    $permohonanId,
    'Komentar Baru pada Permohonan',
    'Pemohon mengirim komentar atau klarifikasi baru. Silakan buka halaman proses pengajuan.',
    'info'
);
if ($notificationCount <= 0) {
    error_log(sprintf(
        'Komentar #%d tersimpan, tetapi operator penerima notifikasi tidak ditemukan untuk %s #%d.',
        $commentId,
        $sourceTable,
        $permohonanId
    ));
}

$comments = komentar_permohonan_list($koneksi, $sourceTable, $permohonanId);
foreach ($comments as &$comment) {
    $comment['is_mine'] = ((int)$comment['id_user'] === $userId);
}
unset($comment);

echo json_encode([
    'success' => true,
    'message' => 'Komentar berhasil dikirim.',
    'comment_id' => $commentId,
    'komentar' => $comments,
    'csrf_token' => function_exists('csrf_token') ? csrf_token() : '',
]);
