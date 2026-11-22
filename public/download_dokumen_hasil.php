<?php
declare(strict_types=1);

require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/../app/config/koneksi.php';
require_once __DIR__ . '/functions/dokumen_hasil.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['id_user'])) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID dokumen hasil tidak valid.');
}

function dokumen_hasil_petugas_desa_can_access(mysqli $koneksi, array $document): bool
{
    if (($_SESSION['role'] ?? '') !== 'petugas_desa') {
        return false;
    }

    $sourceTable = (string)($document['source_table'] ?? '');
    $permohonanId = (int)($document['permohonan_id'] ?? 0);
    $userId = (int)($_SESSION['id_user'] ?? 0);
    if ($userId <= 0 || $permohonanId <= 0 || !dokumen_hasil_is_allowed_source_table($sourceTable)) {
        return false;
    }

    $stmtUser = $koneksi->prepare(
        "SELECT u.id_desa, d.nama_desa
         FROM tb_users u
         LEFT JOIN tb_desa d ON u.id_desa = d.id
         WHERE u.id = ? AND u.role = 'petugas_desa'
         LIMIT 1"
    );
    if (!$stmtUser) {
        return false;
    }

    $stmtUser->bind_param('i', $userId);
    if (!$stmtUser->execute()) {
        return false;
    }

    $user = $stmtUser->get_result()->fetch_assoc();
    if (!$user) {
        return false;
    }

    $desaId = (int)($user['id_desa'] ?? 0);
    $namaDesa = trim((string)($user['nama_desa'] ?? ''));

    $query = "SELECT id FROM `$sourceTable` WHERE id = ? AND (id_desa = ? OR nama_desa = ?) LIMIT 1";
    $stmt = $koneksi->prepare($query);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('iis', $permohonanId, $desaId, $namaDesa);
    if (!$stmt->execute()) {
        return false;
    }

    return $stmt->get_result()->num_rows > 0;
}

dokumen_hasil_download_by_id($koneksi, $id, function (array $document) use ($koneksi): bool {
    return dokumen_hasil_petugas_desa_can_access($koneksi, $document);
});
