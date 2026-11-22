<?php

if (!defined('KOMENTAR_PERMOHONAN_MAX_LENGTH')) {
    define('KOMENTAR_PERMOHONAN_MAX_LENGTH', 1000);
}

function komentar_permohonan_allowed_source_tables(): array
{
    return [
        'tb_kartukeluarga',
        'tb_akta_kelahiran',
        'tb_akta_kematian',
        'tb_pindah_alamat',
        'tb_pindah_online',
        'tb_laporan_peristiwa',
        'tb_akta_kelahiran_rs',
        'tb_akta_kematian_rs',
        'tb_kartukeluarga_kua',
        'tb_akta_kelahiran_ahlak',
    ];
}

function komentar_permohonan_map_jenis(string $jenis): ?string
{
    return match ($jenis) {
        'kelahiran' => 'tb_akta_kelahiran',
        'kematian' => 'tb_akta_kematian',
        'kk' => 'tb_kartukeluarga',
        'pindah' => 'tb_pindah_alamat',
        'pindah_online' => 'tb_pindah_online',
        'laporan' => 'tb_laporan_peristiwa',
        'kelahiran_rs' => 'tb_akta_kelahiran_rs',
        'kematian_rs' => 'tb_akta_kematian_rs',
        'kk_kua' => 'tb_kartukeluarga_kua',
        'kelahiran_ahlak' => 'tb_akta_kelahiran_ahlak',
        default => null,
    };
}

function komentar_permohonan_id_column(string $sourceTable): string
{
    return $sourceTable === 'tb_laporan_peristiwa' ? 'id_lapor' : 'id';
}

function komentar_permohonan_is_allowed_source_table(string $sourceTable): bool
{
    return in_array($sourceTable, komentar_permohonan_allowed_source_tables(), true);
}

function komentar_permohonan_has_submitted_files(array $files = null): bool
{
    $files = $files ?? $_FILES;
    if (empty($files)) {
        return false;
    }

    $containsFile = function ($value) use (&$containsFile): bool {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($containsFile($item)) {
                    return true;
                }
            }
            return false;
        }

        return trim((string)$value) !== '';
    };

    foreach ($files as $file) {
        if (!is_array($file)) {
            return true;
        }

        if (isset($file['error'])) {
            $errors = is_array($file['error']) ? $file['error'] : [$file['error']];
            foreach ($errors as $error) {
                if ((int)$error !== UPLOAD_ERR_NO_FILE) {
                    return true;
                }
            }
        }

        if (isset($file['name']) && $containsFile($file['name'])) {
            return true;
        }
    }

    return false;
}

function komentar_permohonan_normalize_text(string $komentar): string
{
    $komentar = str_replace(["\r\n", "\r"], "\n", $komentar);
    $komentar = preg_replace('/[^\P{C}\n\t]/u', '', $komentar);
    $komentar = preg_replace("/[ \t]+/u", ' ', $komentar);
    $komentar = preg_replace("/\n{4,}/u", "\n\n\n", $komentar);

    return trim($komentar);
}

function komentar_permohonan_validate_text(string $komentar, ?string &$error = null): bool
{
    $length = function_exists('mb_strlen') ? mb_strlen($komentar, 'UTF-8') : strlen($komentar);
    if ($length < 1) {
        $error = 'Komentar wajib diisi.';
        return false;
    }

    if ($length > KOMENTAR_PERMOHONAN_MAX_LENGTH) {
        $error = 'Komentar maksimal ' . KOMENTAR_PERMOHONAN_MAX_LENGTH . ' karakter.';
        return false;
    }

    return true;
}

function komentar_permohonan_normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    $allowed = ['admin', 'operator', 'user', 'petugas_desa', 'rs', 'kua', 'ahlak'];

    return in_array($role, $allowed, true) ? $role : 'user';
}

function komentar_permohonan_user_can_access(mysqli $koneksi, string $sourceTable, int $permohonanId, int $userId, string $role): bool
{
    if ($permohonanId <= 0 || $userId <= 0 || !komentar_permohonan_is_allowed_source_table($sourceTable)) {
        return false;
    }

    $idColumn = komentar_permohonan_id_column($sourceTable);
    $role = komentar_permohonan_normalize_role($role);

    if ($role === 'user') {
        $stmt = $koneksi->prepare("SELECT 1 FROM `$sourceTable` WHERE `$idColumn` = ? AND id_user = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $permohonanId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $allowed = $result && $result->num_rows > 0;
        $stmt->close();

        return $allowed;
    }

    if ($role === 'petugas_desa') {
        $stmtUser = $koneksi->prepare("
            SELECT u.id_desa, d.nama_desa
            FROM tb_users u
            LEFT JOIN tb_desa d ON u.id_desa = d.id
            WHERE u.id = ?
            LIMIT 1
        ");
        if (!$stmtUser) {
            return false;
        }
        $stmtUser->bind_param('i', $userId);
        $stmtUser->execute();
        $userData = $stmtUser->get_result()->fetch_assoc();
        $stmtUser->close();

        $idDesa = (int)($userData['id_desa'] ?? 0);
        $namaDesa = trim((string)($userData['nama_desa'] ?? ''));
        if ($idDesa <= 0 && $namaDesa === '') {
            return false;
        }

        $stmt = $koneksi->prepare("SELECT 1 FROM `$sourceTable` WHERE `$idColumn` = ? AND (id_desa = ? OR nama_desa = ?) LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iis', $permohonanId, $idDesa, $namaDesa);
        $stmt->execute();
        $result = $stmt->get_result();
        $allowed = $result && $result->num_rows > 0;
        $stmt->close();

        return $allowed;
    }

    return in_array($role, ['admin', 'operator'], true);
}

function komentar_permohonan_create(mysqli $koneksi, string $sourceTable, int $permohonanId, int $userId, string $role, string $komentar, ?string &$error = null, ?int $parentId = null): ?int
{
    if (komentar_permohonan_has_submitted_files()) {
        $error = 'Komentar tidak menerima upload file.';
        return null;
    }

    if (!komentar_permohonan_user_can_access($koneksi, $sourceTable, $permohonanId, $userId, $role)) {
        $error = 'Anda tidak memiliki akses ke permohonan ini.';
        return null;
    }

    $role = komentar_permohonan_normalize_role($role);
    $komentar = komentar_permohonan_normalize_text($komentar);
    if (!komentar_permohonan_validate_text($komentar, $error)) {
        return null;
    }

    if ($parentId !== null && $parentId <= 0) {
        $parentId = null;
    }

    if ($parentId !== null) {
        $stmtParent = $koneksi->prepare("
            SELECT 1
            FROM tb_permohonan_komentar
            WHERE id = ?
              AND source_table = ?
              AND permohonan_id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");
        if (!$stmtParent) {
            $error = 'Gagal memeriksa komentar yang akan dibalas.';
            return null;
        }

        $stmtParent->bind_param('isi', $parentId, $sourceTable, $permohonanId);
        $stmtParent->execute();
        $parentExists = $stmtParent->get_result()->num_rows > 0;
        $stmtParent->close();

        if (!$parentExists) {
            $error = 'Komentar yang akan dibalas tidak ditemukan.';
            return null;
        }
    }

    if ($parentId === null) {
        $stmt = $koneksi->prepare("
            INSERT INTO tb_permohonan_komentar
                (source_table, permohonan_id, id_user, user_role, komentar)
            VALUES (?, ?, ?, ?, ?)
        ");
    } else {
        $stmt = $koneksi->prepare("
            INSERT INTO tb_permohonan_komentar
                (source_table, permohonan_id, id_user, user_role, parent_id, komentar)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
    }

    if (!$stmt) {
        $error = 'Gagal menyiapkan penyimpanan komentar.';
        return null;
    }

    if ($parentId === null) {
        $stmt->bind_param('siiss', $sourceTable, $permohonanId, $userId, $role, $komentar);
    } else {
        $stmt->bind_param('siisis', $sourceTable, $permohonanId, $userId, $role, $parentId, $komentar);
    }

    if (!$stmt->execute()) {
        $error = 'Gagal menyimpan komentar.';
        $stmt->close();
        return null;
    }

    $newId = (int)$stmt->insert_id;
    $stmt->close();

    return $newId;
}

function komentar_permohonan_list(mysqli $koneksi, string $sourceTable, int $permohonanId, int $limit = 50): array
{
    if ($permohonanId <= 0 || !komentar_permohonan_is_allowed_source_table($sourceTable)) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $stmt = $koneksi->prepare("
        SELECT
            k.id,
            k.source_table,
            k.permohonan_id,
            k.id_user,
            k.user_role,
            k.parent_id,
            k.komentar,
            k.created_at,
            DATE_FORMAT(k.created_at, '%d-%m-%Y %H:%i') AS created_at_formatted,
            u.nama AS nama_user
        FROM tb_permohonan_komentar k
        LEFT JOIN tb_users u ON k.id_user = u.id
        WHERE k.source_table = ?
          AND k.permohonan_id = ?
          AND k.deleted_at IS NULL
        ORDER BY k.created_at ASC, k.id ASC
        LIMIT ?
    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('sii', $sourceTable, $permohonanId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'source_table' => $row['source_table'],
            'permohonan_id' => (int)$row['permohonan_id'],
            'id_user' => (int)$row['id_user'],
            'user_role' => $row['user_role'],
            'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            'komentar' => $row['komentar'],
            'created_at' => $row['created_at'],
            'created_at_formatted' => $row['created_at_formatted'],
            'nama_user' => $row['nama_user'] ?: ucfirst(str_replace('_', ' ', $row['user_role'])),
        ];
    }
    $stmt->close();

    return $items;
}
