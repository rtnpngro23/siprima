<?php
require_once __DIR__ . '/../../public/user/service_tracking.php';

require_once __DIR__ . '/LaporanRepository.php';

if (!function_exists('esc')) {
    function esc($value): string
    {
        return htmlspecialchars((string)($value ?? '-'), ENT_QUOTES, 'UTF-8');
    }
}

function laporanCounter(): array
{
    return ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0];
}

function laporanIncrement(array &$counter, string $status): void
{
    $counter['total']++;
    if (array_key_exists($status, $counter)) {
        $counter[$status]++;
    }
}

function laporanPercentage(int $value, int $total): string
{
    return $total > 0 ? number_format(($value / $total) * 100, 2, ',', '.') . '%' : '0,00%';
}

$laporanFilter = LaporanFilter::fromArray($_GET);
$laporanRepository = new LaporanRepository($koneksi);

$periode = $laporanFilter->periode;
$tahun = (string)$laporanFilter->tahun;
$bulan = sprintf('%02d', $laporanFilter->bulan);
$layanan_filter = $laporanFilter->layanan;
$status_filter = $laporanFilter->status;
$search_filter = $laporanFilter->q;
$tanggal_dari = $laporanFilter->tanggalDari;
$tanggal_sampai = $laporanFilter->tanggalSampai;
$current_page = $laporanFilter->page;
$per_page = $laporanFilter->perPage;
$offset = ($current_page - 1) * $per_page;
$layanan_options = LaporanFilter::LAYANAN;
$status_options = LaporanFilter::STATUS;

if (isset($_GET['detail_fetch']) && $_GET['detail_fetch'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
    ]);
    $jenis = trim((string)($_GET['jenis'] ?? ''));
    $jenisMap = [
        'Kartu Keluarga' => ['table' => 'tb_kartukeluarga', 'pk' => 'id', 'judul' => 'nama_kepalaKeluarga', 'nik' => 'nik_pemohon'],
        'Cetak Biodata' => ['table' => 'tb_kartukeluarga', 'pk' => 'id', 'judul' => 'nama_kepalaKeluarga', 'nik' => 'nik_pemohon'],
        'KK KUA (PASUTRI)' => ['table' => 'tb_kartukeluarga_kua', 'pk' => 'id', 'judul' => 'nama_kepalaKeluarga', 'nik' => 'nik_pemohon'],
        'Akta Kelahiran' => ['table' => 'tb_akta_kelahiran', 'pk' => 'id', 'judul' => 'nama_bayi', 'nik' => 'nik_pemohon'],
        'Akta Kelahiran (AHLAK)' => ['table' => 'tb_akta_kelahiran_ahlak', 'pk' => 'id', 'judul' => 'nama_bayi', 'nik' => 'nik_pemohon'],
        'Akta Kelahiran RS (ST SEHATI)' => ['table' => 'tb_akta_kelahiran_rs', 'pk' => 'id', 'judul' => 'nama_bayi', 'nik' => 'nik_pemohon'],
        'Akta Kematian' => ['table' => 'tb_akta_kematian', 'pk' => 'id', 'judul' => 'Nama_meninggal', 'nik' => 'nik_pemohon'],
        'Akta Kematian RS (ST SEHATI)' => ['table' => 'tb_akta_kematian_rs', 'pk' => 'id', 'judul' => 'nama_meninggal', 'nik' => 'nik_pemohon'],
        'Pindah Alamat' => ['table' => 'tb_pindah_alamat', 'pk' => 'id', 'judul' => 'nama_kepalaKeluarga', 'nik' => 'nik_pemohon'],
        'Pindah Masuk' => ['table' => 'tb_pindah_online', 'pk' => 'id', 'judul' => 'nama_kepalaKeluarga', 'nik' => 'nik_pemohon'],
        'SIP PKK' => ['table' => 'tb_laporan_peristiwa', 'pk' => 'id_lapor', 'judul' => 'nama_subjek', 'nik' => 'nik_pelapor'],
        'KIA' => ['table' => 'tb_pengajuan_kia', 'pk' => 'id', 'judul' => 'no_kk', 'nik' => 'no_kk'],
    ];

    if ($id === false || !isset($jenisMap[$jenis])) {
        echo json_encode(['success' => false, 'message' => 'Parameter detail tidak valid.']);
        exit;
    }

    $config = $jenisMap[$jenis];
    if ($jenis === 'KIA') {
        $stmt = $koneksi->prepare("
            SELECT k.id_user, k.status, kc.nama_kecamatan, ds.nama_desa, k.created_at,
                   k.updated_at,
                   COALESCE((SELECT a.nik_anak FROM tb_anak_kia a WHERE a.id_pengajuan = k.id ORDER BY a.id ASC LIMIT 1), k.no_kk) AS nik,
                   k.alasan_penolakan AS alasan,
                   k.keterangan_permohonan AS keterangan,
                   NULL AS dokumen,
                   COALESCE((SELECT GROUP_CONCAT(a.nama_anak SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = k.id), k.no_kk) AS judul
            FROM tb_pengajuan_kia k
            LEFT JOIN tb_kecamatan kc ON k.id_kecamatan = kc.id
            LEFT JOIN tb_desa ds ON k.id_desa = ds.id
            WHERE k.id = ? LIMIT 1
        ");
    } else {
        $stmt = $koneksi->prepare("SELECT id_user, status, nama_kecamatan, nama_desa, created_at,
                                      updated_at, `{$config['nik']}` AS nik, alasan, keterangan,
                                      dokumen, `{$config['judul']}` AS judul
                               FROM `{$config['table']}` WHERE `{$config['pk']}` = ? LIMIT 1");
    }
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Detail belum dapat dimuat.']);
        exit;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        echo json_encode(['success' => false, 'message' => 'Data permohonan tidak ditemukan.']);
        exit;
    }

    $detail = [
        ['label' => 'Jenis Layanan', 'value' => $jenis],
        ['label' => 'Judul', 'value' => (string)($record['judul'] ?? '-')],
        ['label' => 'Status', 'value' => (string)($record['status'] ?? '-')],
        ['label' => 'Kecamatan', 'value' => (string)($record['nama_kecamatan'] ?? '-')],
        ['label' => 'Desa', 'value' => (string)($record['nama_desa'] ?? '-')],
        ['label' => 'Tanggal Pengajuan', 'value' => (string)($record['created_at'] ?? '-')],
        ['label' => 'Update Terakhir', 'value' => (string)($record['updated_at'] ?? '-')],
        ['label' => 'NIK', 'value' => (string)($record['nik'] ?? '-')],
        ['label' => 'Alasan', 'value' => (string)($record['alasan'] ?? '-')],
        ['label' => 'Keterangan', 'value' => (string)($record['keterangan'] ?? '-')],
    ];

    $userInfo = null;
    $idUser = (int)($record['id_user'] ?? 0);
    if ($idUser > 0) {
        $userStmt = $koneksi->prepare('SELECT nama, nik, no_hp, email, role FROM tb_users WHERE id = ? LIMIT 1');
        if ($userStmt) {
            $userStmt->bind_param('i', $idUser);
            $userStmt->execute();
            $userInfo = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
        }
    }

    $dokumen = [];
    if ($jenis === 'KIA') {
        $stmtDok = $koneksi->prepare("SELECT original_name, file_path, jenis_dokumen FROM tb_dokumen_kia WHERE id_pengajuan = ? AND status_dokumen = 'valid'");
        if ($stmtDok) {
            $stmtDok->bind_param('i', $id);
            $stmtDok->execute();
            $dokRes = $stmtDok->get_result();
            while ($dRow = $dokRes->fetch_assoc()) {
                $dokumen[] = [
                    'nama' => (string)($dRow['original_name'] ?? 'Dokumen KIA'),
                    'path' => (string)($dRow['file_path'] ?? ''),
                    'tipe' => (string)($dRow['jenis_dokumen'] ?? 'kia'),
                ];
            }
            $stmtDok->close();
        }
    } elseif (!empty($record['dokumen'])) {
        $parsed = json_decode((string)$record['dokumen'], true);
        $items = is_array($parsed) ? $parsed : [['path' => (string)$record['dokumen']]];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $path = (string)($item['path'] ?? '');
            $dokumen[] = [
                'nama' => (string)($item['original_name'] ?? basename($path ?: 'Dokumen')),
                'path' => $path,
                'tipe' => (string)($item['type'] ?? '-'),
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'detail' => $detail,
        'status_history_html' => serviceTrackingHtml(serviceTrackingHistory($koneksi, $config['table'], (int)$id, true)),
        'user_info' => $userInfo,
        'dokumen' => $dokumen,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$total_records = $laporanRepository->count($laporanFilter);
$total_pages = (int)ceil($total_records / $per_page);
if ($total_pages > 0 && $current_page > $total_pages) {
    $params = $laporanFilter->toQueryArray();
    $params['page'] = (string)$total_pages;
    header('Location: ?' . http_build_query($params));
    exit;
}
$reports = $laporanRepository->page($laporanFilter);

$stats = laporanCounter();
$stats_per_jenis = [];
foreach (LaporanFilter::LAYANAN as $label) {
    $stats_per_jenis[$label] = array_merge(['nama' => $label], laporanCounter());
}
foreach ($laporanRepository->statistics($laporanFilter) as $row) {
    $label = (string)$row['jenis_dokumen'];
    if (!isset($stats_per_jenis[$label])) {
        continue;
    }
    foreach (array_keys(laporanCounter()) as $key) {
        $stats_per_jenis[$label][$key] = (int)($row[$key] ?? 0);
        $stats[$key] += (int)($row[$key] ?? 0);
    }
}

$stats_per_kecamatan = [];
$kecamatanCanonical = [];
foreach ($laporanRepository->masterKecamatan() as $row) {
    $name = trim((string)$row['nama_kecamatan']);
    if ($name !== '') {
        $stats_per_kecamatan[$name] = laporanCounter();
        $kecamatanCanonical[strtolower($name)] = $name;
    }
}

$stats_per_desa = [];
foreach ($laporanRepository->masterDesa() as $row) {
    $desa = trim((string)$row['nama_desa']);
    $kec = trim((string)($row['nama_kecamatan'] ?? '-'));
    if ($desa !== '') {
        $stats_per_desa[strtolower($kec . '|' . $desa)] = array_merge(
            ['label' => $desa . ' (' . $kec . ')'],
            laporanCounter()
        );
    }
}

$stats_per_role = [
    'user' => array_merge(['label' => 'User'], laporanCounter()),
    'petugas_desa' => array_merge(['label' => 'Petugas Desa'], laporanCounter()),
    'petugas_pasutri' => array_merge(['label' => 'Petugas PASUTRI'], laporanCounter()),
];
$stats_pasutri_per_kecamatan = [];
$stats_online_desa_per_kecamatan = [];
$stats_ahlak_per_kecamatan = [];
$stats_st_sehati_per_kecamatan = [];
$stats_st_sehati_per_user = [];
foreach (array_keys($stats_per_kecamatan) as $kecamatan) {
    $stats_pasutri_per_kecamatan[$kecamatan] = laporanCounter();
    $stats_online_desa_per_kecamatan[$kecamatan] = laporanCounter();
    $stats_ahlak_per_kecamatan[$kecamatan] = laporanCounter();
    $stats_st_sehati_per_kecamatan[$kecamatan] = array_merge(
        ['kelahiran' => 0, 'kematian' => 0],
        laporanCounter()
    );
}

foreach ($laporanRepository->locationAndRoleRows($laporanFilter) as $row) {
    $status = strtolower((string)($row['status'] ?? ''));
    $kecamatanRaw = trim((string)($row['nama_kecamatan'] ?? ''));
    $kecamatan = $kecamatanRaw === ''
        ? 'Tidak diketahui'
        : ($kecamatanCanonical[strtolower($kecamatanRaw)] ?? $kecamatanRaw);
    $desa = trim((string)($row['nama_desa'] ?? '')) ?: 'Tidak diketahui';

    if (!isset($stats_per_kecamatan[$kecamatan])) {
        $stats_per_kecamatan[$kecamatan] = laporanCounter();
    }
    laporanIncrement($stats_per_kecamatan[$kecamatan], $status);

    $desaKey = strtolower($kecamatan . '|' . $desa);
    if (!isset($stats_per_desa[$desaKey])) {
        $stats_per_desa[$desaKey] = array_merge(['label' => $desa . ' (' . $kecamatan . ')'], laporanCounter());
    }
    laporanIncrement($stats_per_desa[$desaKey], $status);

    $role = (string)($row['requester_role'] ?? '');
    if ($role === 'user' && (int)($row['requester_id_kua'] ?? 0) > 0) {
        $role = 'petugas_pasutri';
    }
    if (isset($stats_per_role[$role])) {
        laporanIncrement($stats_per_role[$role], $status);
    }

    if ($role === 'user' || $role === 'petugas_desa') {
        if (!isset($stats_online_desa_per_kecamatan[$kecamatan])) {
            $stats_online_desa_per_kecamatan[$kecamatan] = laporanCounter();
        }
        laporanIncrement($stats_online_desa_per_kecamatan[$kecamatan], $status);
    }

    if (($row['jenis_dokumen'] ?? '') === 'Akta Kelahiran (AHLAK)') {
        if (!isset($stats_ahlak_per_kecamatan[$kecamatan])) {
            $stats_ahlak_per_kecamatan[$kecamatan] = laporanCounter();
        }
        laporanIncrement($stats_ahlak_per_kecamatan[$kecamatan], $status);
    }

    if (($row['jenis_dokumen'] ?? '') === 'KK KUA (PASUTRI)') {
        if (!isset($stats_pasutri_per_kecamatan[$kecamatan])) {
            $stats_pasutri_per_kecamatan[$kecamatan] = laporanCounter();
        }
        laporanIncrement($stats_pasutri_per_kecamatan[$kecamatan], $status);
    }

    $jenisDokumen = (string)($row['jenis_dokumen'] ?? '');
    if ($jenisDokumen === 'Akta Kelahiran RS (ST SEHATI)'
        || $jenisDokumen === 'Akta Kematian RS (ST SEHATI)') {
        if (!isset($stats_st_sehati_per_kecamatan[$kecamatan])) {
            $stats_st_sehati_per_kecamatan[$kecamatan] = array_merge(
                ['kelahiran' => 0, 'kematian' => 0],
                laporanCounter()
            );
        }
        laporanIncrement($stats_st_sehati_per_kecamatan[$kecamatan], $status);
        if ($jenisDokumen === 'Akta Kelahiran RS (ST SEHATI)') {
            $stats_st_sehati_per_kecamatan[$kecamatan]['kelahiran']++;
        } else {
            $stats_st_sehati_per_kecamatan[$kecamatan]['kematian']++;
        }

        $namaUser = trim((string)($row['operator_nama'] ?? '')) ?: 'User tidak diketahui';
        $namaRs = trim((string)($row['nama_rs'] ?? '')) ?: 'RS tidak diketahui';
        $userRsKey = strtolower($namaRs . '|' . $namaUser);
        if (!isset($stats_st_sehati_per_user[$userRsKey])) {
            $stats_st_sehati_per_user[$userRsKey] = array_merge(
                ['nama_user' => $namaUser, 'nama_rs' => $namaRs, 'kelahiran' => 0, 'kematian' => 0],
                laporanCounter()
            );
        }
        laporanIncrement($stats_st_sehati_per_user[$userRsKey], $status);
        if ($jenisDokumen === 'Akta Kelahiran RS (ST SEHATI)') {
            $stats_st_sehati_per_user[$userRsKey]['kelahiran']++;
        } else {
            $stats_st_sehati_per_user[$userRsKey]['kematian']++;
        }
    }
}
uksort($stats_per_kecamatan, static function (string $nameA, string $nameB) use ($stats_per_kecamatan): int {
    $byTotal = $stats_per_kecamatan[$nameB]['total'] <=> $stats_per_kecamatan[$nameA]['total'];
    return $byTotal !== 0 ? $byTotal : strnatcasecmp($nameA, $nameB);
});
uasort($stats_per_desa, static function (array $a, array $b): int {
    $byTotal = $b['total'] <=> $a['total'];
    return $byTotal !== 0 ? $byTotal : strnatcasecmp($a['label'], $b['label']);
});
$sortProgramKecamatan = static function (array &$data): void {
    uksort($data, static function (string $nameA, string $nameB) use ($data): int {
        $byTotal = $data[$nameB]['total'] <=> $data[$nameA]['total'];
        return $byTotal !== 0 ? $byTotal : strnatcasecmp($nameA, $nameB);
    });
};
$sortProgramKecamatan($stats_online_desa_per_kecamatan);
$sortProgramKecamatan($stats_ahlak_per_kecamatan);
$sortProgramKecamatan($stats_pasutri_per_kecamatan);
$sortProgramKecamatan($stats_st_sehati_per_kecamatan);
uasort($stats_st_sehati_per_user, static function (array $a, array $b): int {
    $byTotal = $b['total'] <=> $a['total'];
    if ($byTotal !== 0) {
        return $byTotal;
    }
    $byRs = strnatcasecmp($a['nama_rs'], $b['nama_rs']);
    return $byRs !== 0 ? $byRs : strnatcasecmp($a['nama_user'], $b['nama_user']);
});

$chartRows = $laporanRepository->chart($laporanFilter);
$chartMap = [];
foreach ($chartRows as $row) {
    $chartMap[(string)$row['bucket']] = (int)$row['total'];
}
$labels = [];
$data = [];
if ($periode === 'hari_ini') {
    for ($i = 0; $i < 24; $i++) {
        $key = sprintf('%02d', $i);
        $labels[] = $key . ':00';
        $data[] = $chartMap[$key] ?? 0;
    }
} elseif ($periode === 'bulan_ini') {
    $days = cal_days_in_month(CAL_GREGORIAN, $laporanFilter->bulan, $laporanFilter->tahun);
    for ($i = 1; $i <= $days; $i++) {
        $key = sprintf('%02d', $i);
        $labels[] = (string)$i;
        $data[] = $chartMap[$key] ?? 0;
    }
} elseif ($periode === 'tahun_ini') {
    for ($i = 1; $i <= 12; $i++) {
        $key = sprintf('%02d', $i);
        $labels[] = date('M', mktime(0, 0, 0, $i, 1));
        $data[] = $chartMap[$key] ?? 0;
    }
} else {
    foreach ($chartRows as $row) {
        $labels[] = (string)$row['bucket'];
        $data[] = (int)$row['total'];
    }
}

$years = $laporanRepository->years();
if (!$years) {
    $years = [['tahun' => $laporanFilter->tahun]];
}
