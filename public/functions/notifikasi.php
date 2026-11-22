<?php
function buatNotifikasi($koneksi, $user_id, $judul, $pesan, $tipe = 'info', $targetUrl = null, $sourceTable = null, $permohonanId = null) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return false;
    }

    $allowedTypes = ['info', 'success', 'warning', 'danger'];
    if (!in_array($tipe, $allowedTypes, true)) {
        $tipe = 'info';
    }

    $stmt = $koneksi->prepare("INSERT INTO tb_notifikasi (user_id, judul, pesan, tipe, target_url, source_table, permohonan_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('isssssi', $user_id, $judul, $pesan, $tipe, $targetUrl, $sourceTable, $permohonanId);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Migrasi sql/20260918_notifikasi_dilihat.sql menambah kolom `dilihat`; tanpa migrasi badge memakai `dibaca`.
function notifikasiPunyaKolomDilihat($koneksi): bool {
    static $cache = [];
    $key = spl_object_id($koneksi);
    if (!isset($cache[$key])) {
        $result = $koneksi->query("SHOW COLUMNS FROM tb_notifikasi LIKE 'dilihat'");
        $cache[$key] = $result && $result->num_rows > 0;
    }
    return $cache[$key];
}

// Mengembalikan notifikasi terbaru (atau sebelum $beforeId untuk "Muat lebih banyak").
// $role dan $scope (folder halaman) dipakai untuk menentukan tautan; tanpa keduanya tautan tersimpan dipakai apa adanya.
function daftarNotifikasi($koneksi, int $userId, int $limit = 10, int $beforeId = 0, string $role = '', string $scope = ''): array {
    $limit = max(1, min($limit, 50));
    $beforeId = max(0, $beforeId);
    $fetch = $limit + 1;
    $stmt = $koneksi->prepare(
        "SELECT id, judul, pesan, tipe, dibaca, target_url, source_table, permohonan_id, created_at
         FROM tb_notifikasi
         WHERE user_id = ? AND (? = 0 OR id < ?)
         ORDER BY id DESC
         LIMIT ?"
    );
    if (!$stmt) return ['items' => [], 'has_more' => false];

    $stmt->bind_param('iiii', $userId, $beforeId, $beforeId, $fetch);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['items' => [], 'has_more' => false];
    }

    $items = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['dibaca'] = (bool)$row['dibaca'];
        $srcTbl = (string)($row['source_table'] ?? '');
        $permId = (int)($row['permohonan_id'] ?? 0);
        if ($srcTbl === 'tb_pengajuan_kia' && $permId > 0) {
            $chkKia = $koneksi->prepare("SELECT id_user FROM tb_pengajuan_kia WHERE id = ? LIMIT 1");
            if ($chkKia) {
                $chkKia->bind_param('i', $permId);
                $chkKia->execute();
                $kiaOwnerRow = $chkKia->get_result()->fetch_assoc();
                $chkKia->close();
                if (!$kiaOwnerRow) {
                    continue;
                }
                if ($role === 'user' && (int)$kiaOwnerRow['id_user'] !== $userId) {
                    continue;
                }
            }
        }
        $targetUrl = (string)($row['target_url'] ?? '');
        $computed = notifikasiTautanPenerima($role, $row['source_table'] ?? null, (int)($row['permohonan_id'] ?? 0));
        if ($computed !== null) {
            $row['target_url'] = $computed;
        } elseif ($targetUrl === '' || !preg_match('#^(?:\.\./user(?:_kua|_rs|_ahlak)?/)?(?:proses_pengajuan|status_permohonan|petugas_desa_status_permohonan|dokumen_email_belum_masuk|whistleblowing|(?:kua|rs|ahlak)_(?:status_permohonan|proses_pengajuan))\.php\?[a-z0-9_=&%.-]+$#i', $targetUrl)
            // Tautan relatif tanpa folder hanya benar dari folder user; `jenis` kosong tidak membuka detail.
            || ($scope !== '' && $scope !== 'user' && strncmp($targetUrl, '../', 3) !== 0)
            || preg_match('/[?&]jenis=(?:&|$)/', $targetUrl)) {
            $row['target_url'] = notifikasiHalamanDaftar($role, $scope);
        }
        $items[] = $row;
    }
    $stmt->close();
    $hasMore = count($items) > $limit;
    return ['items' => array_slice($items, 0, $limit), 'has_more' => $hasMore];
}

function notifikasiLayananMap(): array {
    return [
        'tb_kartukeluarga' => ['layanan' => 'Kartu Keluarga', 'jenis' => 'kk', 'subjek' => 'nama_kepalaKeluarga'],
        'tb_akta_kelahiran' => ['layanan' => 'Akta Kelahiran', 'jenis' => 'kelahiran', 'subjek' => 'nama_bayi'],
        'tb_akta_kematian' => ['layanan' => 'Akta Kematian', 'jenis' => 'kematian', 'subjek' => 'nama_meninggal'],
        'tb_pindah_alamat' => ['layanan' => 'Pindah Alamat', 'jenis' => 'pindah', 'subjek' => 'nama_kepalaKeluarga'],
        'tb_pindah_online' => ['layanan' => 'Pindah Masuk', 'jenis' => 'pindah_online', 'subjek' => 'nama_kepalaKeluarga'],
        'tb_kartukeluarga_kua' => ['layanan' => 'Kartu Keluarga KUA', 'jenis' => 'kua_kk', 'subjek' => 'nama_kepalaKeluarga'],
        'tb_akta_kelahiran_rs' => ['layanan' => 'Akta Kelahiran RS', 'jenis' => 'rs_kelahiran', 'subjek' => 'nama_bayi'],
        'tb_akta_kematian_rs' => ['layanan' => 'Akta Kematian RS', 'jenis' => 'rs_kematian', 'subjek' => 'nama_meninggal'],
        'tb_akta_kelahiran_ahlak' => ['layanan' => 'Akta Kelahiran AHLAK', 'jenis' => 'ahlak_kelahiran', 'subjek' => 'nama_bayi'],
        'tb_pengajuan_kia' => ['layanan' => 'Kartu Identitas Anak', 'jenis' => 'kia', 'subjek' => 'no_kk'],
    ];
}

// Tautan detail dihitung saat dibaca, sehingga notifikasi lama yang tautannya salah/kosong tetap menuju halaman yang benar.
function notifikasiTautanPenerima(string $role, ?string $sourceTable, int $permohonanId): ?string {
    $map = notifikasiLayananMap();
    if ($sourceTable === null || $permohonanId <= 0 || !isset($map[$sourceTable])) return null;
    $units = [
        'tb_kartukeluarga_kua' => ['../user_kua/kua_status_permohonan.php', 'kk', '../user_kua/kua_proses_pengajuan.php'],
        'tb_akta_kelahiran_rs' => ['../user_rs/rs_status_permohonan.php', 'kelahiran', '../user_rs/rs_proses_pengajuan.php'],
        'tb_akta_kematian_rs' => ['../user_rs/rs_status_permohonan.php', 'kematian', '../user_rs/rs_proses_pengajuan.php'],
        'tb_akta_kelahiran_ahlak' => ['../user_ahlak/ahlak_status_permohonan.php', 'kelahiran', '../user_ahlak/ahlak_proses_pengajuan.php'],
    ];
    $table = rawurlencode($sourceTable);
    if (isset($units[$sourceTable])) {
        [$statusPage, $jenis, $processPage] = $units[$sourceTable];
        if ($role === 'operator') return $processPage . '?detail_id=' . $permohonanId . '&table=' . $table;
        return $role === 'user' ? $statusPage . '?detail_id=' . $permohonanId . '&jenis=' . $jenis : null;
    }
    $jenis = rawurlencode($map[$sourceTable]['jenis']);
    return match ($role) {
        'operator' => '../user/proses_pengajuan.php?detail_id=' . $permohonanId . '&table=' . $table,
        'petugas_desa' => '../user/petugas_desa_status_permohonan.php?detail_id=' . $permohonanId . '&jenis=' . $jenis,
        'user' => '../user/status_permohonan.php?detail_id=' . $permohonanId . '&jenis=' . $jenis,
        default => null,
    };
}

// Notifikasi lama tanpa data permohonan diarahkan ke halaman daftar sesuai role dan folder halaman.
function notifikasiHalamanDaftar(string $role, string $scope): ?string {
    $pages = [
        'user' => ['operator' => 'proses_pengajuan.php', 'petugas_desa' => 'petugas_desa_status_permohonan.php', 'user' => 'status_permohonan.php'],
        'user_kua' => ['operator' => 'kua_proses_pengajuan.php', 'user' => 'kua_status_permohonan.php'],
        'user_rs' => ['operator' => 'rs_proses_pengajuan.php', 'user' => 'rs_status_permohonan.php'],
        'user_ahlak' => ['operator' => 'ahlak_proses_pengajuan.php', 'user' => 'ahlak_status_permohonan.php'],
    ];
    $page = $pages[$scope][$role] ?? null;
    return $page === null ? null : '../' . $scope . '/' . $page;
}

function notifikasiPermohonanContext($koneksi, string $sourceTable, int $permohonanId): ?array {
    $map = notifikasiLayananMap();
    if ($permohonanId <= 0 || !isset($map[$sourceTable])) return null;

    $stmt = $koneksi->prepare("SELECT * FROM `$sourceTable` WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $permohonanId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $config = $map[$sourceTable];
    return [
        'id_user' => (int)($row['id_user'] ?? 0),
        'id_kecamatan' => (int)($row['id_kecamatan'] ?? 0),
        'nama_kecamatan' => trim((string)($row['nama_kecamatan'] ?? '')),
        'layanan' => $config['layanan'],
        'jenis' => $config['jenis'],
        'nama_pemohon' => trim((string)($row['nama_pemohon'] ?? '-')),
        'nama_subjek' => trim((string)($row[$config['subjek']] ?? '-')),
        'id_desa' => (int)($row['id_desa'] ?? 0),
        'id_kua' => (int)($row['id_kua'] ?? 0),
        'id_rs' => (int)($row['id_rs'] ?? 0),
        'id_puskes' => (int)($row['id_puskes'] ?? 0),
    ];
}

function notifikasiTargetDetailPemohon($koneksi, int $userId, int $permohonanId, string $jenis, string $sourceTable = ''): string {
    // Pengaju KUA/RS/AHLAK memakai halaman status di folder unitnya sendiri.
    $unitPages = [
        'tb_kartukeluarga_kua' => ['../user_kua/kua_status_permohonan.php', 'kk'],
        'tb_akta_kelahiran_rs' => ['../user_rs/rs_status_permohonan.php', 'kelahiran'],
        'tb_akta_kematian_rs' => ['../user_rs/rs_status_permohonan.php', 'kematian'],
        'tb_akta_kelahiran_ahlak' => ['../user_ahlak/ahlak_status_permohonan.php', 'kelahiran'],
    ];
    if (isset($unitPages[$sourceTable])) {
        [$unitPage, $unitJenis] = $unitPages[$sourceTable];
        return $unitPage . '?detail_id=' . $permohonanId . '&jenis=' . $unitJenis;
    }

    $stmtRole = $koneksi->prepare("SELECT role FROM tb_users WHERE id = ? LIMIT 1");
    $role = 'user';
    if ($stmtRole) {
        $stmtRole->bind_param('i', $userId);
        $stmtRole->execute();
        $roleRow = $stmtRole->get_result()->fetch_assoc();
        $stmtRole->close();
        $role = (string)($roleRow['role'] ?? 'user');
    }

    $page = $role === 'petugas_desa' ? 'petugas_desa_status_permohonan.php' : 'status_permohonan.php';
    return $page . '?detail_id=' . $permohonanId . '&jenis=' . rawurlencode($jenis);
}

function buatNotifikasiBalasanKomentarOperator($koneksi, int $userId, string $sourceTable, int $permohonanId): bool {
    $context = notifikasiPermohonanContext($koneksi, $sourceTable, $permohonanId);
    if (!$context) return false;

    $targetUrl = notifikasiTargetDetailPemohon($koneksi, $userId, $permohonanId, $context['jenis'], $sourceTable);
    $pesan = 'Jenis: ' . $context['layanan']
        . ' | Pemohon: ' . $context['nama_pemohon']
        . ' | Subjek: ' . $context['nama_subjek']
        . '. Operator telah membalas komentar Anda.';

    return buatNotifikasi(
        $koneksi,
        $userId,
        'Balasan Komentar ' . $context['layanan'],
        $pesan,
        'info',
        $targetUrl,
        $sourceTable,
        $permohonanId
    );
}

function buatNotifikasiStatusPermohonan($koneksi, int $userId, string $sourceTable, int $permohonanId, string $status, string $alasan = ''): bool {
    $context = notifikasiPermohonanContext($koneksi, $sourceTable, $permohonanId);
    if (!$context) return false;

    $targetUrl = notifikasiTargetDetailPemohon($koneksi, $userId, $permohonanId, $context['jenis'], $sourceTable);
    $statusLabel = ucfirst($status);
    $pesan = 'Jenis: ' . $context['layanan'] . ' | Pemohon: ' . $context['nama_pemohon'] . ' | Subjek: ' . $context['nama_subjek'] . '. Status menjadi ' . $statusLabel . '.';
    if ($alasan !== '') $pesan .= ' Catatan: ' . $alasan;
    $tipe = $status === 'ditolak' ? 'danger' : ($status === 'selesai' ? 'success' : 'info');

    return buatNotifikasi($koneksi, $userId, 'Update ' . $context['layanan'] . ': ' . $statusLabel, $pesan, $tipe, $targetUrl, $sourceTable, $permohonanId);
}

// Angka badge: notifikasi yang belum dilihat (lonceng belum dibuka) dan belum dibaca.
function jumlahNotifikasiBelumDilihat($koneksi, int $userId): int {
    $unseen = notifikasiPunyaKolomDilihat($koneksi) ? ' AND dilihat = 0' : '';
    $stmt = $koneksi->prepare("SELECT COUNT(*) AS total FROM tb_notifikasi WHERE user_id = ? AND dibaca = 0" . $unseen);
    if (!$stmt) return 0;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0);
}

// Membuka lonceng: hanya notifikasi yang sudah dimuat (id <= $maxId) yang dianggap dilihat.
function tandaiNotifikasiDilihat($koneksi, int $userId, int $maxId): bool {
    if ($maxId <= 0 || !notifikasiPunyaKolomDilihat($koneksi)) return false;
    $stmt = $koneksi->prepare("UPDATE tb_notifikasi SET dilihat = 1 WHERE user_id = ? AND id <= ? AND dilihat = 0");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $userId, $maxId);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Mengklik satu notifikasi; dibatasi user_id agar tidak bisa menandai milik akun lain.
function tandaiNotifikasiDibaca($koneksi, int $userId, int $notifikasiId): bool {
    if ($notifikasiId <= 0) return false;
    $seen = notifikasiPunyaKolomDilihat($koneksi) ? ', dilihat = 1' : '';
    $stmt = $koneksi->prepare("UPDATE tb_notifikasi SET dibaca = 1" . $seen . " WHERE id = ? AND user_id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $notifikasiId, $userId);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

function buatNotifikasiOperatorPermohonan(
    $koneksi,
    string $sourceTable,
    int $permohonanId,
    string $judul,
    string $pesan,
    string $tipe = 'info'
): int {
    $allowedTables = ['tb_kartukeluarga', 'tb_akta_kelahiran', 'tb_akta_kematian', 'tb_pindah_alamat', 'tb_pindah_online'];
    if ($permohonanId <= 0 || !in_array($sourceTable, $allowedTables, true)) return 0;

    $context = notifikasiPermohonanContext($koneksi, $sourceTable, $permohonanId);
    if (!$context) return 0;
    $kecamatanId = (int)$context['id_kecamatan'];
    $namaKecamatan = trim((string)($context['nama_kecamatan'] ?? ''));
    if ($kecamatanId <= 0 && $namaKecamatan === '') return 0;

    $stmtOperator = $koneksi->prepare(
        "SELECT DISTINCT u.id
         FROM tb_users u
         INNER JOIN tb_operator_kecamatan ok ON ok.operator_id = u.id
         LEFT JOIN tb_kecamatan kec ON kec.id = ok.kecamatan_id
         WHERE u.role = 'operator'
           AND (
             (? > 0 AND ok.kecamatan_id = ?)
             OR (? <> '' AND LOWER(TRIM(kec.nama_kecamatan)) = LOWER(TRIM(?)))
           )"
    );
    if (!$stmtOperator) return 0;
    $stmtOperator->bind_param('iiss', $kecamatanId, $kecamatanId, $namaKecamatan, $namaKecamatan);
    $stmtOperator->execute();
    $result = $stmtOperator->get_result();
    $sent = 0;
    $richMessage = 'Jenis: ' . $context['layanan'] . ' | Pemohon: ' . $context['nama_pemohon'] . ' | Subjek: ' . $context['nama_subjek'] . '. ' . $pesan;
    $targetUrl = 'proses_pengajuan.php?detail_id=' . $permohonanId . '&table=' . rawurlencode($sourceTable);
    while ($operator = $result->fetch_assoc()) {
        if (buatNotifikasi($koneksi, (int)$operator['id'], $judul, $richMessage, $tipe, $targetUrl, $sourceTable, $permohonanId)) $sent++;
    }
    $stmtOperator->close();
    return $sent;
}

// Pengajuan baru/diperbaiki dari akun KUA, RS, atau AHLAK diteruskan ke operator unit yang ditugaskan.
function buatNotifikasiOperatorUnit($koneksi, string $sourceTable, int $permohonanId, string $judul, string $pesan, string $tipe = 'info'): int {
    $units = [
        'tb_kartukeluarga_kua' => ['kua_id', 'id_kua', '../user_kua/kua_proses_pengajuan.php'],
        'tb_akta_kelahiran_rs' => ['rs_id', 'id_rs', '../user_rs/rs_proses_pengajuan.php'],
        'tb_akta_kematian_rs' => ['rs_id', 'id_rs', '../user_rs/rs_proses_pengajuan.php'],
        'tb_akta_kelahiran_ahlak' => ['puskes_id', 'id_puskes', '../user_ahlak/ahlak_proses_pengajuan.php'],
    ];
    if ($permohonanId <= 0 || !isset($units[$sourceTable])) return 0;
    [$assignmentColumn, $unitColumn, $processPage] = $units[$sourceTable];

    $context = notifikasiPermohonanContext($koneksi, $sourceTable, $permohonanId);
    $unitId = (int)($context[$unitColumn] ?? 0);
    if (!$context || $unitId <= 0) return 0;

    $stmtOperator = $koneksi->prepare(
        "SELECT DISTINCT u.id
         FROM tb_users u
         INNER JOIN tb_operator_kecamatan ok ON ok.operator_id = u.id
         WHERE u.role = 'operator' AND u.is_active = 1 AND ok.`{$assignmentColumn}` = ?"
    );
    if (!$stmtOperator) return 0;
    $stmtOperator->bind_param('i', $unitId);
    $stmtOperator->execute();
    $result = $stmtOperator->get_result();
    $sent = 0;
    $richMessage = 'Jenis: ' . $context['layanan'] . ' | Pemohon: ' . $context['nama_pemohon'] . ' | Subjek: ' . $context['nama_subjek'] . '. ' . $pesan;
    $targetUrl = $processPage . '?detail_id=' . $permohonanId . '&table=' . rawurlencode($sourceTable);
    while ($operator = $result->fetch_assoc()) {
        if (buatNotifikasi($koneksi, (int)$operator['id'], $judul, $richMessage, $tipe, $targetUrl, $sourceTable, $permohonanId)) $sent++;
    }
    $stmtOperator->close();
    return $sent;
}
?>
