<?php
require_once '../error_handler.php';
include '../../app/middleware/operator_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/notifikasi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_validate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permintaan tidak valid.']);
    exit;
}

$operatorId = (int)($_SESSION['id_user'] ?? 0);
$count = max(1, min(100, (int)($_POST['count'] ?? 1)));
$title = 'Pengajuan Baru Masuk';

// Hindari notifikasi ganda jika halaman operator terbuka pada beberapa tab.
$duplicate = false;
$check = $koneksi->prepare("SELECT id FROM tb_notifikasi WHERE user_id=? AND judul=? AND created_at>=DATE_SUB(NOW(),INTERVAL 50 SECOND) LIMIT 1");
if ($check) {
    $check->bind_param('is', $operatorId, $title);
    $check->execute();
    $duplicate = $check->get_result()->num_rows > 0;
    $check->close();
}

$success = $duplicate || buatNotifikasi(
    $koneksi,
    $operatorId,
    $title,
    'Ada ' . $count . ' permohonan baru yang menunggu pemeriksaan operator.',
    'info',
    'proses_pengajuan.php?page=1'
);

echo json_encode(['success' => (bool)$success, 'duplicate' => $duplicate]);
