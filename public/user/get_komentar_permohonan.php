<?php
require_once '../error_handler.php';
csrf_start_session();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/komentar_permohonan.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metode tidak diizinkan.']);
    exit;
}

$userId = (int)($_SESSION['id_user'] ?? 0);
$role = komentar_permohonan_normalize_role((string)($_SESSION['role'] ?? 'user'));
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired.']);
    exit;
}
if (!in_array($role, ['user', 'petugas_desa'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Role tidak diizinkan.']);
    exit;
}

$permohonanId = (int)($_GET['permohonan_id'] ?? 0);
$jenis = strtolower(trim((string)($_GET['jenis'] ?? '')));
$sourceTable = komentar_permohonan_map_jenis($jenis);
if ($permohonanId <= 0 || $sourceTable === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Data permohonan tidak valid.']);
    exit;
}

if (!komentar_permohonan_user_can_access($koneksi, $sourceTable, $permohonanId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Anda tidak memiliki akses ke permohonan ini.']);
    exit;
}

$comments = komentar_permohonan_list($koneksi, $sourceTable, $permohonanId);
foreach ($comments as &$comment) {
    $comment['is_mine'] = ((int)$comment['id_user'] === $userId);
}
unset($comment);

$lastComment = !empty($comments) ? end($comments) : null;
echo json_encode([
    'success' => true,
    'komentar' => $comments,
    'last_comment_id' => (int)($lastComment['id'] ?? 0),
]);
