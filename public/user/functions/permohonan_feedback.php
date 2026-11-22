<?php
declare(strict_types=1);

require_once __DIR__ . '/../../functions/email_delivery.php';

function permohonan_feedback_identity(mysqli $koneksi, string $sourceTable, int $permohonanId): ?array
{
    $role = (string)($_SESSION['role'] ?? '');
    $userId = (int)($_SESSION['id_user'] ?? 0);
    $context = email_delivery_context($koneksi, $sourceTable, $permohonanId);
    if (!$context || $userId <= 0 || !in_array($role, ['user', 'petugas_desa'], true)) {
        return null;
    }

    if ($role === 'user' && !email_delivery_requester_can_access($koneksi, $context, $sourceTable, $userId)) {
        return null;
    }

    if ($role === 'petugas_desa') {
        $stmt = $koneksi->prepare("SELECT id_desa FROM tb_users WHERE id=? AND role='petugas_desa' LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user || (int)$user['id_desa'] <= 0 || (int)$user['id_desa'] !== (int)$context['id_desa']) {
            return null;
        }
    }

    return [
        'user_id' => $userId,
        'role' => $role === 'user' ? 'pemohon' : 'petugas_registrasi',
        'layanan' => (string)$context['layanan'],
        'nama_pemohon' => (string)$context['nama_pemohon'],
        'nama_subjek' => (string)$context['nama_subjek'],
    ];
}

function permohonan_feedback_get(mysqli $koneksi, string $sourceTable, int $permohonanId, string $role): ?array
{
    $stmt = $koneksi->prepare('SELECT * FROM tb_permohonan_feedback WHERE source_table=? AND permohonan_id=? AND respondent_role=? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('sis', $sourceTable, $permohonanId, $role);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
