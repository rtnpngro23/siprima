<?php
require_once '../error_handler.php';

// Mulai session HANYA jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/../../app/config/koneksi.php';
include __DIR__ . '/../../app/middleware/admin_only.php';
require_once __DIR__ . '/../../app/Laporan/LaporanRepository.php';

$exportFilter = LaporanFilter::fromArray($_GET);
$exportRepository = new LaporanRepository($koneksi);
$exportStatsRows = $exportRepository->statistics($exportFilter);
$exportLocationRows = $exportRepository->locationAndRoleRows($exportFilter);

$exportProgram = [
    'PASUTRI' => ['total' => 0, 'selesai' => 0, 'ditolak' => 0],
    'AHLAK' => ['total' => 0, 'selesai' => 0, 'ditolak' => 0],
    'ST SEHATI' => ['total' => 0, 'selesai' => 0, 'ditolak' => 0],
];
$programByLayanan = [
    'KK KUA (PASUTRI)' => 'PASUTRI',
    'Akta Kelahiran (AHLAK)' => 'AHLAK',
    'Akta Kelahiran RS (ST SEHATI)' => 'ST SEHATI',
    'Akta Kematian RS (ST SEHATI)' => 'ST SEHATI',
];
foreach ($exportStatsRows as $row) {
    $program = $programByLayanan[(string)($row['jenis_dokumen'] ?? '')] ?? '';
    if ($program === '') {
        continue;
    }
    $exportProgram[$program]['total'] += (int)($row['total'] ?? 0);
    $exportProgram[$program]['selesai'] += (int)($row['selesai'] ?? 0);
    $exportProgram[$program]['ditolak'] += (int)($row['ditolak'] ?? 0);
}

$exportRoleLabels = [
    'user' => 'User',
    'petugas_desa' => 'Petugas Desa',
    'petugas_pasutri' => 'Petugas PASUTRI',
];
$exportRoles = [
    'user' => ['label' => 'User', 'total' => 0, 'selesai' => 0, 'ditolak' => 0],
    'petugas_desa' => ['label' => 'Petugas Desa', 'total' => 0, 'selesai' => 0, 'ditolak' => 0],
    'petugas_pasutri' => ['label' => 'Petugas PASUTRI', 'total' => 0, 'selesai' => 0, 'ditolak' => 0],
];

$exportKecamatan = [];
$exportKecamatanCanonical = [];
foreach ($exportRepository->masterKecamatan() as $row) {
    $name = trim((string)($row['nama_kecamatan'] ?? ''));
    if ($name !== '') {
        $exportKecamatan[$name] = ['total' => 0, 'selesai' => 0, 'ditolak' => 0];
        $exportKecamatanCanonical[strtolower($name)] = $name;
    }
}

$exportProgramKecamatan = [
    'AHLAK' => [],
    'PASUTRI' => [],
    'ST SEHATI' => [],
];
$exportOnlineDesaKecamatan = [];
$exportStSehatiUsers = [];
foreach (array_keys($exportKecamatan) as $kecamatan) {
    $exportOnlineDesaKecamatan[$kecamatan] = ['total' => 0, 'selesai' => 0, 'ditolak' => 0];
    $exportProgramKecamatan['AHLAK'][$kecamatan] = ['total' => 0, 'selesai' => 0, 'ditolak' => 0];
    $exportProgramKecamatan['PASUTRI'][$kecamatan] = ['total' => 0, 'selesai' => 0, 'ditolak' => 0];
    $exportProgramKecamatan['ST SEHATI'][$kecamatan] = [
        'kelahiran' => 0,
        'kematian' => 0,
        'total' => 0,
        'selesai' => 0,
        'ditolak' => 0,
    ];
}

$exportDesa = [];
foreach ($exportRepository->masterDesa() as $row) {
    $desa = trim((string)($row['nama_desa'] ?? ''));
    $kecamatanRaw = trim((string)($row['nama_kecamatan'] ?? ''));
    $kecamatan = $kecamatanRaw === ''
        ? 'Tidak diketahui'
        : ($exportKecamatanCanonical[strtolower($kecamatanRaw)] ?? $kecamatanRaw);
    if ($desa !== '') {
        $key = strtolower($kecamatan . '|' . $desa);
        $exportDesa[$key] = [
            'desa' => $desa,
            'kecamatan' => $kecamatan,
            'total' => 0,
            'selesai' => 0,
            'ditolak' => 0,
        ];
    }
}

foreach ($exportLocationRows as $row) {
    $kecamatan = trim((string)($row['nama_kecamatan'] ?? '')) ?: 'Tidak diketahui';
    $desa = trim((string)($row['nama_desa'] ?? '')) ?: 'Tidak diketahui';
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $rawRole = strtolower(trim((string)($row['requester_role'] ?? ''))) ?: 'tidak_diketahui';
    if ($rawRole === 'user' && (int)($row['requester_id_kua'] ?? 0) > 0) {
        $rawRole = 'petugas_pasutri';
    }
    $role = $rawRole;

    if ($role !== 'tidak_diketahui') {
        if (!isset($exportRoles[$role])) {
            $exportRoles[$role] = [
                'label' => $exportRoleLabels[$role] ?? ucwords(str_replace('_', ' ', $role)),
                'total' => 0,
                'selesai' => 0,
                'ditolak' => 0,
            ];
        }
        $exportRoles[$role]['total']++;
        if ($status === 'selesai' || $status === 'ditolak') {
            $exportRoles[$role][$status]++;
        }
    }
    if ($role === 'user' || $role === 'petugas_desa') {
        if (!isset($exportOnlineDesaKecamatan[$kecamatan])) {
            $exportOnlineDesaKecamatan[$kecamatan] = ['total' => 0, 'selesai' => 0, 'ditolak' => 0];
        }
        $exportOnlineDesaKecamatan[$kecamatan]['total']++;
        if ($status === 'selesai' || $status === 'ditolak') {
            $exportOnlineDesaKecamatan[$kecamatan][$status]++;
        }
    }

    $jenisDokumen = (string)($row['jenis_dokumen'] ?? '');
    $program = $programByLayanan[$jenisDokumen] ?? '';
    if ($program !== '') {
        if (!isset($exportProgramKecamatan[$program][$kecamatan])) {
            $exportProgramKecamatan[$program][$kecamatan] = [
                'total' => 0,
                'selesai' => 0,
                'ditolak' => 0,
            ];
            if ($program === 'ST SEHATI') {
                $exportProgramKecamatan[$program][$kecamatan]['kelahiran'] = 0;
                $exportProgramKecamatan[$program][$kecamatan]['kematian'] = 0;
            }
        }
        $exportProgramKecamatan[$program][$kecamatan]['total']++;
        if ($status === 'selesai' || $status === 'ditolak') {
            $exportProgramKecamatan[$program][$kecamatan][$status]++;
        }
        if ($program === 'ST SEHATI') {
            $key = $jenisDokumen === 'Akta Kelahiran RS (ST SEHATI)' ? 'kelahiran' : 'kematian';
            $exportProgramKecamatan[$program][$kecamatan][$key]++;

            $namaUser = trim((string)($row['operator_nama'] ?? '')) ?: 'User tidak diketahui';
            $namaRs = trim((string)($row['nama_rs'] ?? '')) ?: 'RS tidak diketahui';
            $userRsKey = strtolower($namaRs . '|' . $namaUser);
            if (!isset($exportStSehatiUsers[$userRsKey])) {
                $exportStSehatiUsers[$userRsKey] = [
                    'nama_user' => $namaUser,
                    'nama_rs' => $namaRs,
                    'kelahiran' => 0,
                    'kematian' => 0,
                    'total' => 0,
                    'selesai' => 0,
                    'ditolak' => 0,
                ];
            }
            $exportStSehatiUsers[$userRsKey]['total']++;
            $exportStSehatiUsers[$userRsKey][$key]++;
            if ($status === 'selesai' || $status === 'ditolak') {
                $exportStSehatiUsers[$userRsKey][$status]++;
            }
        }
    }

    if (!isset($exportKecamatan[$kecamatan])) {
        $exportKecamatan[$kecamatan] = ['total' => 0, 'selesai' => 0, 'ditolak' => 0];
    }
    $exportKecamatan[$kecamatan]['total']++;
    if ($status === 'selesai' || $status === 'ditolak') {
        $exportKecamatan[$kecamatan][$status]++;
    }

    $desaKey = strtolower($kecamatan . '|' . $desa);
    if (!isset($exportDesa[$desaKey])) {
        $exportDesa[$desaKey] = [
            'desa' => $desa,
            'kecamatan' => $kecamatan,
            'total' => 0,
            'selesai' => 0,
            'ditolak' => 0,
        ];
    }
    $exportDesa[$desaKey]['total']++;
    if ($status === 'selesai' || $status === 'ditolak') {
        $exportDesa[$desaKey][$status]++;
    }
}

uksort($exportKecamatan, static function (string $nameA, string $nameB) use ($exportKecamatan): int {
    $byTotal = $exportKecamatan[$nameB]['total'] <=> $exportKecamatan[$nameA]['total'];
    return $byTotal !== 0 ? $byTotal : strnatcasecmp($nameA, $nameB);
});
uasort($exportDesa, static function (array $a, array $b): int {
    $byTotal = $b['total'] <=> $a['total'];
    if ($byTotal !== 0) {
        return $byTotal;
    }
    $byKecamatan = strnatcasecmp($a['kecamatan'], $b['kecamatan']);
    return $byKecamatan !== 0 ? $byKecamatan : strnatcasecmp($a['desa'], $b['desa']);
});
uasort($exportRoles, static function (array $a, array $b): int {
    $byTotal = $b['total'] <=> $a['total'];
    return $byTotal !== 0 ? $byTotal : strnatcasecmp($a['label'], $b['label']);
});
foreach ($exportProgramKecamatan as &$programRows) {
    uksort($programRows, static function (string $nameA, string $nameB) use ($programRows): int {
        $byTotal = $programRows[$nameB]['total'] <=> $programRows[$nameA]['total'];
        return $byTotal !== 0 ? $byTotal : strnatcasecmp($nameA, $nameB);
    });
}
unset($programRows);
uksort($exportOnlineDesaKecamatan, static function (string $nameA, string $nameB) use ($exportOnlineDesaKecamatan): int {
    $byTotal = $exportOnlineDesaKecamatan[$nameB]['total'] <=> $exportOnlineDesaKecamatan[$nameA]['total'];
    return $byTotal !== 0 ? $byTotal : strnatcasecmp($nameA, $nameB);
});
uasort($exportStSehatiUsers, static function (array $a, array $b): int {
    $byTotal = $b['total'] <=> $a['total'];
    if ($byTotal !== 0) {
        return $byTotal;
    }
    $byRs = strnatcasecmp($a['nama_rs'], $b['nama_rs']);
    return $byRs !== 0 ? $byRs : strnatcasecmp($a['nama_user'], $b['nama_user']);
});

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="laporan_admin_' . date('Y-m-d_His') . '.xls"');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$exportEsc = static function ($value): string {
    return htmlspecialchars((string)($value ?? '-'), ENT_QUOTES, 'UTF-8');
};
$exportPercentage = static function (int $value, int $total): string {
    return $total > 0 ? number_format(($value / $total) * 100, 2, ',', '.') . '%' : '0,00%';
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Ekspor Laporan Admin</title>
    <style>
        table { border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #777; padding: 6px; }
        th { background: #e8eef5; }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>
    <h2>Laporan Administrasi Kependudukan</h2>
    <p>
        Periode: <?= $exportEsc($exportFilter->periode ?: 'Semua') ?> |
        Tahun: <?= $exportFilter->tahun ?> |
        Bulan: <?= sprintf('%02d', $exportFilter->bulan) ?> |
        Layanan: <?= $exportEsc($exportFilter->layanan ?: 'Semua') ?> |
        Status: <?= $exportEsc($exportFilter->status ?: 'Semua') ?> |
        Rentang: <?= $exportEsc($exportFilter->tanggalDari ?: '-') ?> s.d. <?= $exportEsc($exportFilter->tanggalSampai ?: '-') ?> |
        Pencarian: <?= $exportEsc($exportFilter->q ?: '-') ?>
    </p>

    <h3>Statistik per Layanan</h3>
    <table>
        <thead>
            <tr><th>Layanan</th><th>Total</th><th>Diajukan</th><th>Diproses</th><th>Selesai</th><th>Ditolak</th></tr>
        </thead>
        <tbody>
        <?php foreach ($exportStatsRows as $row): ?>
            <tr>
                <td><?= $exportEsc($row['jenis_dokumen']) ?></td>
                <td><?= (int)$row['total'] ?></td>
                <td><?= (int)$row['diajukan'] ?></td>
                <td><?= (int)$row['diproses'] ?></td>
                <td><?= (int)$row['selesai'] ?></td>
                <td><?= (int)$row['ditolak'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Total Permohonan Program Inovasi</h3>
    <table>
        <thead>
            <tr>
                <th>Program</th><th>Total Permohonan</th>
                <th>Selesai</th><th>Persentase Selesai</th>
                <th>Ditolak</th><th>Persentase Ditolak</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($exportProgram as $program => $row): ?>
            <tr>
                <td><?= $exportEsc($program) ?></td>
                <td><?= (int)$row['total'] ?></td>
                <td><?= (int)$row['selesai'] ?></td>
                <td><?= $exportPercentage((int)$row['selesai'], (int)$row['total']) ?></td>
                <td><?= (int)$row['ditolak'] ?></td>
                <td><?= $exportPercentage((int)$row['ditolak'], (int)$row['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Total Pemohon Berdasarkan Role</h3>
    <table>
        <thead>
            <tr>
                <th>Role Pemohon</th><th>Total Permohonan</th>
                <th>Selesai</th><th>Persentase Selesai</th>
                <th>Ditolak</th><th>Persentase Ditolak</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($exportRoles as $row): ?>
            <tr>
                <td><?= $exportEsc($row['label']) ?></td>
                <td><?= (int)$row['total'] ?></td>
                <td><?= (int)$row['selesai'] ?></td>
                <td><?= $exportPercentage((int)$row['selesai'], (int)$row['total']) ?></td>
                <td><?= (int)$row['ditolak'] ?></td>
                <td><?= $exportPercentage((int)$row['ditolak'], (int)$row['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Total Permohonan Online + Petugas Desa per Kecamatan</h3>
    <table>
        <thead>
            <tr>
                <th>No</th><th>Kecamatan</th><th>Total</th>
                <th>Selesai</th><th>Persentase Selesai</th>
                <th>Ditolak</th><th>Persentase Ditolak</th>
            </tr>
        </thead>
        <tbody>
        <?php $number = 1; foreach ($exportOnlineDesaKecamatan as $kecamatan => $row): ?>
            <tr>
                <td><?= $number++ ?></td>
                <td><?= $exportEsc($kecamatan) ?></td>
                <td><?= (int)$row['total'] ?></td>
                <td><?= (int)$row['selesai'] ?></td>
                <td><?= $exportPercentage((int)$row['selesai'], (int)$row['total']) ?></td>
                <td><?= (int)$row['ditolak'] ?></td>
                <td><?= $exportPercentage((int)$row['ditolak'], (int)$row['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php foreach ($exportProgramKecamatan as $program => $programRows): ?>
        <h3>Total Permohonan <?= $exportEsc($program) ?> per Kecamatan</h3>
        <table>
            <thead>
                <tr>
                    <th>No</th><th>Kecamatan</th>
                    <?php if ($program === 'ST SEHATI'): ?>
                        <th>Kelahiran</th><th>Kematian</th>
                    <?php endif; ?>
                    <th>Total</th><th>Selesai</th><th>Persentase Selesai</th>
                    <th>Ditolak</th><th>Persentase Ditolak</th>
                </tr>
            </thead>
            <tbody>
            <?php $number = 1; foreach ($programRows as $kecamatan => $row): ?>
                <tr>
                    <td><?= $number++ ?></td>
                    <td><?= $exportEsc($kecamatan) ?></td>
                    <?php if ($program === 'ST SEHATI'): ?>
                        <td><?= (int)($row['kelahiran'] ?? 0) ?></td>
                        <td><?= (int)($row['kematian'] ?? 0) ?></td>
                    <?php endif; ?>
                    <td><?= (int)$row['total'] ?></td>
                    <td><?= (int)$row['selesai'] ?></td>
                    <td><?= $exportPercentage((int)$row['selesai'], (int)$row['total']) ?></td>
                    <td><?= (int)$row['ditolak'] ?></td>
                    <td><?= $exportPercentage((int)$row['ditolak'], (int)$row['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

    <h3>Rincian ST SEHATI Berdasarkan User Rumah Sakit</h3>
    <table>
        <thead>
            <tr>
                <th>No</th><th>Nama User</th><th>Rumah Sakit</th>
                <th>Kelahiran</th><th>Kematian</th><th>Total</th>
                <th>Selesai</th><th>Persentase Selesai</th>
                <th>Ditolak</th><th>Persentase Ditolak</th>
            </tr>
        </thead>
        <tbody>
        <?php $number = 1; foreach ($exportStSehatiUsers as $row): ?>
            <tr>
                <td><?= $number++ ?></td>
                <td><?= $exportEsc($row['nama_user']) ?></td>
                <td><?= $exportEsc($row['nama_rs']) ?></td>
                <td><?= (int)$row['kelahiran'] ?></td>
                <td><?= (int)$row['kematian'] ?></td>
                <td><?= (int)$row['total'] ?></td>
                <td><?= (int)$row['selesai'] ?></td>
                <td><?= $exportPercentage((int)$row['selesai'], (int)$row['total']) ?></td>
                <td><?= (int)$row['ditolak'] ?></td>
                <td><?= $exportPercentage((int)$row['ditolak'], (int)$row['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Total Permohonan per Kecamatan</h3>
    <table>
        <thead>
            <tr>
                <th>No</th><th>Kecamatan</th><th>Total Permohonan</th>
                <th>Selesai</th><th>Persentase Selesai</th>
                <th>Ditolak</th><th>Persentase Ditolak</th>
            </tr>
        </thead>
        <tbody>
        <?php $number = 1; foreach ($exportKecamatan as $name => $row): ?>
            <tr>
                <td><?= $number++ ?></td>
                <td><?= $exportEsc($name) ?></td>
                <td><?= (int)$row['total'] ?></td>
                <td><?= (int)$row['selesai'] ?></td>
                <td><?= $exportPercentage((int)$row['selesai'], (int)$row['total']) ?></td>
                <td><?= (int)$row['ditolak'] ?></td>
                <td><?= $exportPercentage((int)$row['ditolak'], (int)$row['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Total Permohonan per Desa</h3>
    <table>
        <thead>
            <tr>
                <th>No</th><th>Desa</th><th>Kecamatan</th><th>Total Permohonan</th>
                <th>Selesai</th><th>Persentase Selesai</th>
                <th>Ditolak</th><th>Persentase Ditolak</th>
            </tr>
        </thead>
        <tbody>
        <?php $number = 1; foreach ($exportDesa as $row): ?>
            <tr>
                <td><?= $number++ ?></td>
                <td><?= $exportEsc($row['desa']) ?></td>
                <td><?= $exportEsc($row['kecamatan']) ?></td>
                <td><?= (int)$row['total'] ?></td>
                <td><?= (int)$row['selesai'] ?></td>
                <td><?= $exportPercentage((int)$row['selesai'], (int)$row['total']) ?></td>
                <td><?= (int)$row['ditolak'] ?></td>
                <td><?= $exportPercentage((int)$row['ditolak'], (int)$row['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
<?php
exit;

