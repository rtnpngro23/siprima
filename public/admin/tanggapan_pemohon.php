<?php
declare(strict_types=1);

require_once __DIR__ . '/../error_handler.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../app/config/koneksi.php';
require_once __DIR__ . '/../../app/middleware/admin_only.php';
require_once __DIR__ . '/../../app/Models/KiaModel.php';

function esc_adm($str): string {
    return htmlspecialchars((string)($str ?? '-'), ENT_QUOTES, 'UTF-8');
}

$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'ringkasan')));
$validTabs = ['ringkasan', 'skm', 'spkp', 'spak', 'excel', 'unduh', 'saran', 'tanggapan'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'ringkasan';
}
if ($activeTab === 'unduh' || $activeTab === 'saran') {
    $activeTab = 'excel';
}

$tglMulai = !empty($_GET['tgl_mulai']) ? trim((string)$_GET['tgl_mulai']) : (!empty($_GET['custom_start']) ? trim((string)$_GET['custom_start']) : '');
$tglAkhir = !empty($_GET['tgl_akhir']) ? trim((string)$_GET['tgl_akhir']) : (!empty($_GET['custom_end']) ? trim((string)$_GET['custom_end']) : '');
$filterLayanan = trim((string)($_GET['layanan'] ?? ''));
$filterJenisSurvei = strtolower(trim((string)($_GET['jenis_survei'] ?? 'semua')));
if (!in_array($filterJenisSurvei, ['semua', 'skm', 'spkp', 'spak'], true)) {
    $filterJenisSurvei = 'semua';
}
$filterSearch = trim((string)($_GET['search'] ?? ($_GET['q'] ?? '')));
$respPage = max(1, (int)($_GET['resp_page'] ?? 1));

$periode = strtolower(trim((string)($_GET['periode'] ?? '')));
if ($tglMulai !== '' || $tglAkhir !== '') {
    $periode = 'custom';
}
if ($periode === '' || !in_array($periode, ['semua', 'hari_ini', 'kemarin', '7_hari', 'bulan_ini', 'bulan_sebelumnya', 'custom'], true)) {
    $periode = ($tglMulai !== '' || $tglAkhir !== '') ? 'custom' : 'semua';
}

$customStart = $tglMulai !== '' ? $tglMulai : date('Y-01-01');
$customEnd   = $tglAkhir !== '' ? $tglAkhir : date('Y-m-d');
$exportExcel = strtolower(trim((string)($_GET['export_excel'] ?? '')));

$kiaModel = new \Siprima\Models\KiaModel($koneksi);

// Handler Ekspor Excel Resmi (Gabungan 7 Sheet atau Per Instrumen)
if ($exportExcel === 'semua') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $xlsxBytes = $kiaModel->generateAllSurveiExcelXlsx($periode, $customStart, $customEnd, $filterJenisSurvei, $filterLayanan ?: 'semua');
    $fileName = 'Laporan_Lengkap_Survei_SIPRIMA_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($xlsxBytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $xlsxBytes;
    exit;
}

if (in_array($exportExcel, ['skm', 'spkp', 'spak'], true)) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $xlsxBytes = $kiaModel->generateSurveiExcelXlsx($exportExcel, $periode, $customStart, $customEnd);
    $fileName = 'Laporan_Survei_' . strtoupper($exportExcel) . '_KIA_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($xlsxBytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $xlsxBytes;
    exit;
}

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    $summary = $kiaModel->getSurveiAdminSummary($periode, $customStart, $customEnd);
    $analytics = $kiaModel->getSurveiAnalyticsData($periode, $customStart, $customEnd, $filterJenisSurvei, $filterLayanan ?: 'semua');
    $skmExportDetail  = $kiaModel->getSurveiExportData('skm', $periode, $customStart, $customEnd);
    $spkpExportDetail = $kiaModel->getSurveiExportData('spkp', $periode, $customStart, $customEnd);
    $spakExportDetail = $kiaModel->getSurveiExportData('spak', $periode, $customStart, $customEnd);
} catch (\Throwable $e) {
    $summary = $kiaModel->getSurveiAdminSummary('semua');
    $analytics = $kiaModel->getSurveiAnalyticsData('semua', null, null, $filterJenisSurvei, $filterLayanan ?: 'semua');
    $skmExportDetail  = $kiaModel->getSurveiExportData('skm', 'semua');
    $spkpExportDetail = $kiaModel->getSurveiExportData('spkp', 'semua');
    $spakExportDetail = $kiaModel->getSurveiExportData('spak', 'semua');
}

$allRespondenRows = $analytics['daftar_responden'] ?? [];
$filteredRespondenList = $allRespondenRows;
if ($filterSearch !== '') {
    $qLower = mb_strtolower($filterSearch);
    $filteredRespondenList = array_values(array_filter($allRespondenRows, static function ($r) use ($qLower) {
        $haystack = mb_strtolower(
            ($r['nama_lengkap'] ?? '') . ' ' .
            ($r['no_hp'] ?? '') . ' ' .
            ($r['email'] ?? '') . ' ' .
            ($r['pekerjaan'] ?? '') . ' ' .
            ($r['nama_kecamatan'] ?? '') . ' ' .
            ($r['nama_desa'] ?? '')
        );
        return mb_strpos($haystack, $qLower) !== false;
    }));
}
$respPerPage = 10;
$respTotalItems = count($filteredRespondenList);
$respTotalPages = max(1, (int)ceil($respTotalItems / $respPerPage));
if ($respPage > $respTotalPages) {
    $respPage = $respTotalPages;
}
$pagedRespondenList = array_slice($filteredRespondenList, ($respPage - 1) * $respPerPage, $respPerPage);

// Data Tanggapan Umum
$feedbackItems = [];
try {
    $resFb = $koneksi->query("SELECT * FROM tb_permohonan_feedback WHERE status='submitted' ORDER BY submitted_at DESC, id DESC LIMIT 100");
    if ($resFb) {
        $feedbackItems = $resFb->fetch_all(MYSQLI_ASSOC);
    }
} catch (\Throwable $e) {
    $feedbackItems = [];
}

$skmData  = $summary['skm'] ?? [];
$spkpData = $summary['spkp'] ?? [];
$spakData = $summary['spak'] ?? [];

$skmTotalResp  = (int)($skmData['total_responden'] ?? 0);
$spkpTotalResp = (int)($spkpData['total_responden'] ?? 0);
$spakTotalResp = (int)($spakData['total_responden'] ?? 0);
$totalRespondenUnik = (int)($summary['total_responden_unik'] ?? max($skmTotalResp, $spkpTotalResp, $spakTotalResp));

$skmRata  = (float)($skmData['rata_rata'] ?? 0.0);
$skmNilai = (float)($skmData['nilai_skm'] ?? 0.0);
$skmMutu  = $skmData['mutu_pelayanan'] ?? ($skmTotalResp > 0 ? 'B' : '-');
$skmKinerja = $skmData['kinerja_pelayanan'] ?? ($skmTotalResp > 0 ? 'Baik' : 'Belum Ada Data');

$spkpRata   = (float)($spkpData['rata_rata'] ?? 0.0);
$spkpPersen = (float)($spkpData['skor_persen'] ?? 0.0);

$spakRata   = (float)($spakData['rata_rata'] ?? 0.0);
$spakPersen = (float)($spakData['skor_persen'] ?? 0.0);

$respondenTerbaru = $summary['responden_terbaru'] ?? [];
$saranList        = $summary['saran_list'] ?? [];
$trenBulanan      = $summary['tren_bulanan'] ?? [];

// Hitung proporsi responden per jenis layanan untuk Doughnut Chart
$layananCounts = [];
if ($totalRespondenUnik > 0) {
    $layananCounts['KIA'] = $totalRespondenUnik;
}
if (!empty($feedbackItems)) {
    foreach ($feedbackItems as $fb) {
        $jL = trim((string)($fb['jenis_layanan'] ?? 'Layanan Lain'));
        if ($jL === '') $jL = 'Layanan Lain';
        $layananCounts[$jL] = ($layananCounts[$jL] ?? 0) + 1;
    }
}
if (empty($layananCounts)) {
    $layananCounts['KIA'] = max(1, $totalRespondenUnik);
}

// Filter masukan masyarakat / saran jika ada input pencarian atau layanan
$filteredSaranList = $saranList;
if ($filterSearch !== '') {
    $needle = mb_strtolower($filterSearch);
    $filteredSaranList = array_filter($filteredSaranList, function ($item) use ($needle) {
        return str_contains(mb_strtolower((string)($item['saran'] ?? '')), $needle)
            || str_contains(mb_strtolower((string)($item['nama_lengkap'] ?? '')), $needle)
            || str_contains(mb_strtolower((string)($item['no_hp'] ?? '')), $needle);
    });
}
if ($filterLayanan !== '' && strtolower($filterLayanan) !== 'semua layanan') {
    if (strtolower($filterLayanan) !== 'kia') {
        $filteredSaranList = [];
    }
}

// Konversi nilai skala SPKP/SPAK (1-6) ke predikat
function getPredikatSkala6(float $skor, int $total, bool $isSpak = false): string {
    if ($total <= 0) return 'Belum Ada Data';
    if ($isSpak) {
        if ($skor >= 5.0) return 'Sangat Bersih';
        if ($skor >= 4.0) return 'Anti Korupsi';
        if ($skor >= 3.0) return 'Cukup Bersih';
        return 'Perlu Perbaikan';
    }
    if ($skor >= 5.0) return 'Sangat Baik';
    if ($skor >= 4.0) return 'Kualitas';
    if ($skor >= 3.0) return 'Cukup Baik';
    return 'Kurang';
}

// Label Periode Aktif
$periodeLabels = [
    'semua'             => 'Semua Periode',
    'hari_ini'          => 'Hari Ini (' . date('d/m/Y') . ')',
    'kemarin'           => 'Kemarin (' . date('d/m/Y', strtotime('-1 day')) . ')',
    '7_hari'            => '7 Hari Terakhir',
    'bulan_ini'         => 'Bulan Ini (' . date('F Y') . ')',
    'bulan_sebelumnya'  => 'Bulan Lalu (' . date('F Y', strtotime('first day of last month')) . ')',
    'custom'            => 'Periode ' . ($tglMulai ?: $customStart) . ' s/d ' . ($tglAkhir ?: $customEnd)
];
$periodeLabelActive = $periodeLabels[$periode] ?? 'Semua Periode';

// Metadata unsur untuk detail instrumen
$skmElementsMeta = [
    'U1' => 'Persyaratan Pelayanan',
    'U2' => 'Sistem, Mekanisme, dan Prosedur',
    'U3' => 'Waktu Penyelesaian Pelayanan',
    'U4' => 'Biaya / Tarif Pelayanan (Gratis)',
    'U5' => 'Produk Spesifikasi Jenis Pelayanan',
    'U6' => 'Kompetensi Pelaksana',
    'U7' => 'Perilaku Pelaksana',
    'U8' => 'Penanganan Pengaduan, Saran, dan Masukan',
    'U9' => 'Kualitas Sarana dan Prasarana'
];

$spkpElementsMeta = [
    'P1' => 'Informasi Layanan',
    'P2' => 'Kemudahan Persyaratan',
    'P3' => 'Kejelasan Prosedur',
    'P4' => 'Ketepatan Waktu',
    'P5' => 'Kesesuaian Biaya (Gratis)',
    'P6' => 'Kualitas Hasil KIA',
    'P7' => 'Sikap & Keramahan Petugas',
    'P8' => 'Fasilitas & Sistem Online'
];

$spakElementsMeta = [
    'A1' => 'Tidak Adanya Praktik Pungutan Liar (Pungli)',
    'A2' => 'Tidak Adanya Praktik Calo / Perantara Liar',
    'A3' => 'Petugas Tidak Meminta / Mengharapkan Imbalan',
    'A4' => 'Tidak Adanya Perlakuan Khusus / Diskriminatif',
    'A5' => 'Kemudahan Akses Tanpa Biaya Tambahan'
];

$skmDbMap = [];
$skmSourceList = $skmData['pertanyaan'] ?? ($skmData['unsur_skm'] ?? []);
foreach ($skmSourceList as $u) {
    $k = $u['kode_pertanyaan'] ?? ($u['kode_unsur'] ?? '');
    if ($k !== '') {
        $skmDbMap[$k] = $u;
    }
}
$spkpDbMap = [];
$spkpSourceList = $spkpData['pertanyaan'] ?? ($spkpData['pernyataan_spkp'] ?? []);
foreach ($spkpSourceList as $p) {
    $k = $p['kode_pertanyaan'] ?? ($p['kode_pernyataan'] ?? '');
    if ($k !== '') {
        $spkpDbMap[$k] = $p;
    }
}
$spakDbMap = [];
$spakSourceList = $spakData['pertanyaan'] ?? ($spakData['indikator_spak'] ?? []);
foreach ($spakSourceList as $a) {
    $k = $a['kode_pertanyaan'] ?? ($a['kode_indikator'] ?? '');
    if ($k !== '') {
        $spakDbMap[$k] = $a;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Survei Masyarakat - Admin SIPRIMA</title>
    <link rel="icon" type="image/webp" href="../assets/prima.webp">
    <link rel="stylesheet" href="dashboard_admin.css">
    <link rel="stylesheet" href="common.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Tampilan Modern Dashboard Survei Masyarakat Sesuai Acuan SIPRIMA */
        .survei-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            padding: 18px 28px;
            background: #f2f6f5;
            border-top: 3px solid #0b6e75;
            border-bottom: 1px solid #c6d8d4;
            position: sticky;
            top: 0;
            z-index: 20;
        }
        .survei-header .title-box h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .survei-header .title-box p {
            margin: 4px 0 0 0;
            font-size: 13px;
            color: #64748b;
        }
        .survei-header .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .btn-unduh-excel-top {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #059669;
            color: #ffffff;
            font-size: 12.5px;
            font-weight: 700;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.15s ease;
            box-shadow: 0 1px 3px rgba(5, 150, 105, 0.25);
        }
        .btn-unduh-excel-top:hover {
            background: #047857;
            color: #ffffff;
        }

        /* Navigasi Tab */
        .survei-nav-tabs {
            display: flex;
            gap: 6px;
            padding: 12px 28px 0 28px;
            background: #f2f6f5;
            border-bottom: 1px solid #c6d8d4;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .survei-nav-tabs::-webkit-scrollbar { display: none; }
        .survei-nav-tab {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            text-decoration: none;
            border-radius: 8px 8px 0 0;
            border-bottom: 2px solid transparent;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .survei-nav-tab:hover {
            color: #0b6e75;
            background: #dceae7;
        }
        .survei-nav-tab.active {
            color: #0b6e75;
            background: #dceae7;
            border-bottom-color: #0b6e75;
            font-weight: 700;
        }

        /* Filter Bar */
        .survei-filter-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 18px;
            margin: 20px 28px 18px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 12.5px;
        }
        .survei-filter-form {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            width: 100%;
        }
        .filter-field-inline {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .filter-field-inline label {
            font-weight: 700;
            color: #475569;
            white-space: nowrap;
        }
        .filter-input-date, .filter-select-inline, .filter-input-search {
            height: 34px;
            padding: 4px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 12px;
            color: #1e293b;
            background: #ffffff;
            outline: none;
        }
        .filter-input-date:focus, .filter-select-inline:focus, .filter-input-search:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }
        .filter-input-search {
            width: 220px;
        }
        .btn-filter-submit {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #0284c7;
            color: #ffffff;
            font-weight: 700;
            font-size: 12px;
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-filter-submit:hover { background: #0369a1; }
        .btn-filter-reset {
            display: inline-flex;
            align-items: center;
            background: #ffffff;
            color: #64748b;
            border: 1px solid #cbd5e1;
            font-weight: 600;
            font-size: 12px;
            padding: 8px 14px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-filter-reset:hover { background: #f8fafc; color: #0f172a; }

        /* 4 Kartu Ringkasan Statistik */
        .stats-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin: 0 28px 22px 28px;
        }
        .stat-card-modern {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .stat-card-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .stat-card-modern .stat-meta h4 {
            margin: 0 0 6px 0;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card-modern .stat-meta .stat-val-row {
            display: flex;
            align-items: baseline;
            gap: 8px;
        }
        .stat-card-modern .stat-meta .stat-number {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }
        .stat-card-modern .stat-meta p {
            margin: 6px 0 0 0;
            font-size: 11px;
            color: #94a3b8;
        }
        .badge-pill-inline {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            display: inline-block;
        }
        .badge-pill-inline.blue { background: #eff6ff; color: #1d4ed8; }
        .badge-pill-inline.green { background: #ecfdf5; color: #047857; }
        .badge-pill-inline.purple { background: #f5f3ff; color: #6d28d9; }

        .stat-card-modern .stat-icon-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .stat-icon-circle.blue { background: #e0f2fe; color: #0284c7; }
        .stat-icon-circle.indigo { background: #eff6ff; color: #2563eb; }
        .stat-icon-circle.green { background: #ecfdf5; color: #059669; }
        .stat-icon-circle.purple { background: #f5f3ff; color: #7c3aed; }

        /* Grafik Berdampingan */
        .charts-row-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 18px;
            margin: 0 28px 24px 28px;
        }
        .chart-panel-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .chart-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .chart-panel-header h3 {
            margin: 0;
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
        }
        .chart-panel-header .header-note {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 600;
        }

        /* Bagian Masukan Masyarakat Terbaru */
        .feedback-section-wrap {
            margin: 0 28px 32px 28px;
        }
        .feedback-section-wrap .section-title {
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 14px 0;
        }
        .feedback-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 14px;
        }
        .feedback-item-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            transition: all 0.15s ease;
        }
        .feedback-item-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 10px rgba(0,0,0,0.04);
        }
        .feedback-item-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .badge-service-pill {
            background: #e0f2fe;
            color: #0369a1;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
        }
        .feedback-item-top .feedback-date {
            font-size: 11.5px;
            color: #94a3b8;
        }
        .feedback-item-body {
            font-size: 12.5px;
            color: #334155;
            line-height: 1.5;
            font-style: italic;
        }
        .feedback-item-footer {
            font-size: 11.5px;
            color: #64748b;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-summary-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-row-container { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .stats-summary-grid { grid-template-columns: 1fr; }
            .survei-header, .survei-nav-tabs, .survei-filter-card, .stats-summary-grid, .charts-row-container, .feedback-section-wrap {
                margin-left: 14px;
                margin-right: 14px;
                padding-left: 14px;
                padding-right: 14px;
            }
        }
        @media print {
            .sidebar, .menu-toggle, .survei-header .header-actions, .survei-nav-tabs, .survei-filter-card, .no-print {
                display: none !important;
            }
            .main { margin: 0 !important; width: 100% !important; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-12">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar Resmi Admin SIPRIMA -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan</div>
        <nav>
            <ul>
                <li><a href="dashboard_admin.php">Dashboard</a></li>
                <li><a href="manajemen_operator.php">Manajemen Pengguna</a></li>
                <li><a href="reset_password.php">Reset Password Pengguna</a></li>
                <li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li>
                <li><a href="laporan_admin.php">Laporan &amp; Analisis</a></li>
                <li><a href="kinerja_operator.php">Kinerja Operator</a></li>
                <li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li class="active"><a href="tanggapan_pemohon.php">Hasil Survei</a></li>
                <li><a href="whistleblowing.php">Whistleblowing</a></li>
                <li><a href="formulir.php">Formulir Kependudukan</a></li>
                <li><a href="manajemen_formulir_digital.php">Manajemen Formulir Digital</a></li>
                <li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
                <li class="logout-item">
                    <button id="logoutBtn" class="sidebar-btn">Logout</button>
                </li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">&#9776;</div>

    <main class="main" style="background:#e9f0ee; min-height: 100vh;">
        <!-- Header Halaman Rapi Sesuai Acuan -->
        <header class="survei-header">
            <div class="title-box">
                <h1>Dashboard Survei Masyarakat</h1>
                <p>Evaluasi Terpadu SKM, SPKP, dan SPAK</p>
            </div>
            <div class="header-actions">
                <a href="tanggapan_pemohon.php?export_excel=semua&amp;periode=<?= urlencode($periode) ?>&amp;tgl_mulai=<?= urlencode($tglMulai) ?>&amp;tgl_akhir=<?= urlencode($tglAkhir) ?>&amp;jenis_survei=<?= urlencode($filterJenisSurvei) ?>&amp;layanan=<?= urlencode($filterLayanan) ?>" class="btn-unduh-excel-top" style="background:#0b6e75;">
                    <i class="fas fa-file-excel"></i>
                    <span>Unduh Semua Hasil Survei (.xlsx)</span>
                </a>
                <span style="font-size: 13.5px; font-weight: 600; color: #334155;">Hai, <?= esc_adm($_SESSION['nama'] ?? 'Admin') ?></span>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <?php
        $navQuerySuffix = '&periode=' . urlencode($periode)
            . ($tglMulai !== '' ? '&tgl_mulai=' . urlencode($tglMulai) : '')
            . ($tglAkhir !== '' ? '&tgl_akhir=' . urlencode($tglAkhir) : '')
            . ($filterJenisSurvei !== 'semua' ? '&jenis_survei=' . urlencode($filterJenisSurvei) : '')
            . ($filterLayanan !== '' ? '&layanan=' . urlencode($filterLayanan) : '')
            . ($filterSearch !== '' ? '&search=' . urlencode($filterSearch) : '');
        ?>
        <!-- Navigasi Tab Sesuai Acuan -->
        <nav class="survei-nav-tabs no-print" aria-label="Tab Instrumen Survei">
            <a href="tanggapan_pemohon.php?tab=ringkasan<?= esc_adm($navQuerySuffix) ?>" class="survei-nav-tab <?= $activeTab === 'ringkasan' ? 'active' : '' ?>">
                <i class="fas fa-chart-pie"></i> Ringkasan Survei
            </a>
            <a href="tanggapan_pemohon.php?tab=skm<?= esc_adm($navQuerySuffix) ?>" class="survei-nav-tab <?= $activeTab === 'skm' ? 'active' : '' ?>">
                <i class="fas fa-smile"></i> Hasil SKM (9 Unsur)
            </a>
            <a href="tanggapan_pemohon.php?tab=spkp<?= esc_adm($navQuerySuffix) ?>" class="survei-nav-tab <?= $activeTab === 'spkp' ? 'active' : '' ?>">
                <i class="fas fa-star"></i> Hasil SPKP (8 Unsur)
            </a>
            <a href="tanggapan_pemohon.php?tab=spak<?= esc_adm($navQuerySuffix) ?>" class="survei-nav-tab <?= $activeTab === 'spak' ? 'active' : '' ?>">
                <i class="fas fa-shield-alt"></i> Hasil SPAK (5 Unsur)
            </a>
            <a href="tanggapan_pemohon.php?tab=excel<?= esc_adm($navQuerySuffix) ?>" class="survei-nav-tab <?= $activeTab === 'excel' ? 'active' : '' ?>">
                <i class="fas fa-file-excel"></i> Pusat Unduh Excel
            </a>
        </nav>

        <!-- Filter Bar Terpadu -->
        <div class="survei-filter-card no-print">
            <form method="GET" action="tanggapan_pemohon.php" class="survei-filter-form">
                <input type="hidden" name="tab" value="<?= esc_adm($activeTab) ?>">

                <div class="filter-field-inline">
                    <label>Periode:</label>
                    <input type="date" name="tgl_mulai" class="filter-input-date" value="<?= esc_adm($tglMulai) ?>" title="Tanggal Awal">
                    <span style="color:#94a3b8; font-size:12px;">s/d</span>
                    <input type="date" name="tgl_akhir" class="filter-input-date" value="<?= esc_adm($tglAkhir) ?>" title="Tanggal Akhir">
                </div>

                <div class="filter-field-inline">
                    <label>Jenis Survei:</label>
                    <select name="jenis_survei" class="filter-select-inline">
                        <option value="semua" <?= $filterJenisSurvei === 'semua' ? 'selected' : '' ?>>Semua Survei</option>
                        <option value="skm" <?= $filterJenisSurvei === 'skm' ? 'selected' : '' ?>>SKM</option>
                        <option value="spkp" <?= $filterJenisSurvei === 'spkp' ? 'selected' : '' ?>>SPKP</option>
                        <option value="spak" <?= $filterJenisSurvei === 'spak' ? 'selected' : '' ?>>SPAK</option>
                    </select>
                </div>

                <div class="filter-field-inline">
                    <label>Layanan:</label>
                    <select name="layanan" class="filter-select-inline">
                        <option value="">Semua Layanan</option>
                        <option value="KIA" <?= strtolower($filterLayanan) === 'kia' ? 'selected' : '' ?>>KIA (Kartu Identitas Anak)</option>
                        <option value="Akta Kelahiran" <?= strtolower($filterLayanan) === 'akta kelahiran' ? 'selected' : '' ?>>Akta Kelahiran</option>
                        <option value="Kartu Keluarga" <?= strtolower($filterLayanan) === 'kartu keluarga' ? 'selected' : '' ?>>Kartu Keluarga (KK)</option>
                    </select>
                </div>

                <div class="filter-field-inline" style="flex: 1; min-width: 190px;">
                    <input type="text" name="search" class="filter-input-search" style="width:100%;" placeholder="Cari nama responden / HP / desa..." value="<?= esc_adm($filterSearch) ?>">
                </div>

                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="submit" class="btn-filter-submit">
                        <i class="fas fa-filter"></i> Terapkan Filter
                    </button>
                    <a href="tanggapan_pemohon.php?tab=<?= esc_adm($activeTab) ?>" class="btn-filter-reset">
                        Reset Filter
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================== -->
        <!-- TAB 1: RINGKASAN SURVEI (DEFAULT TAMPILAN)                     -->
        <!-- ============================================================== -->
        <?php if ($activeTab === 'ringkasan'): ?>
            <!-- 4 Kartu Ringkasan Statistik -->
            <div class="stats-summary-grid">
                <div class="stat-card-modern">
                    <div class="stat-meta">
                        <h4>Total Responden Unik</h4>
                        <div class="stat-val-row">
                            <span class="stat-number"><?= (int)($analytics['total_responden_unik'] ?? $totalRespondenUnik) ?></span>
                            <span class="badge-pill-inline blue"><?= (int)($analytics['total_pengisian'] ?? 0) ?> Pengisian</span>
                        </div>
                        <p>Dihitung unik per permohonan survei</p>
                    </div>
                    <div class="stat-icon-circle blue">
                        <i class="fas fa-users"></i>
                    </div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-meta">
                        <h4>Indeks SKM (1-100)</h4>
                        <div class="stat-val-row">
                            <span class="stat-number"><?= number_format($skmNilai, 2) ?></span>
                            <span class="badge-pill-inline blue"><?= $skmMutu ?> (<?= $skmKinerja ?>)</span>
                        </div>
                        <p>Permenpan RB No. 14/2017</p>
                    </div>
                    <div class="stat-icon-circle indigo">
                        <i class="fas fa-smile"></i>
                    </div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-meta">
                        <h4>Rata-Rata SPKP (1-6)</h4>
                        <div class="stat-val-row">
                            <span class="stat-number"><?= number_format($spkpRata, 2) ?></span>
                            <span class="badge-pill-inline green">Kualitas</span>
                        </div>
                        <p>Skala 1 - 6 (8 Unsur)</p>
                    </div>
                    <div class="stat-icon-circle green">
                        <i class="fas fa-star"></i>
                    </div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-meta">
                        <h4>Indeks SPAK (1-6)</h4>
                        <div class="stat-val-row">
                            <span class="stat-number"><?= number_format($spakRata, 2) ?></span>
                            <span class="badge-pill-inline purple">Anti Korupsi</span>
                        </div>
                        <p>Skala 1 - 6 (5 Unsur)</p>
                    </div>
                    <div class="stat-icon-circle purple">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                </div>
            </div>

            <!-- Dua Grafik Berdampingan -->
            <div class="charts-row-container">
                <div class="chart-panel-card">
                    <div class="chart-panel-header">
                        <h3>Perbandingan Capaian Tiga Instrumen Survei</h3>
                        <span class="header-note">Skala Konversi 100%</span>
                    </div>
                    <div style="height: 290px; position: relative;">
                        <canvas id="summaryComparisonChart"></canvas>
                    </div>
                </div>

                <div class="chart-panel-card">
                    <div class="chart-panel-header">
                        <h3>Responden per Layanan</h3>
                        <span class="header-note">Distribusi Data</span>
                    </div>
                    <div style="height: 290px; position: relative;">
                        <canvas id="servicesDoughnutChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Bagian Masukan Masyarakat Terbaru -->
            <div class="feedback-section-wrap">
                <div class="section-title">Masukan Masyarakat Terbaru</div>
                <?php if (empty($filteredSaranList)): ?>
                    <div class="empty-state" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:32px; text-align:center;">
                        <i class="fas fa-comments" style="font-size:32px; color:#cbd5e1; margin-bottom:8px;"></i>
                        <h4 style="margin:0 0 4px 0; color:#334155; font-size:14px;">Belum Ada Masukan Tersimpan</h4>
                        <p style="margin:0; color:#94a3b8; font-size:12px;">Saran dan masukan dari pemohon survei akan otomatis tampil pada bagian ini.</p>
                    </div>
                <?php else: ?>
                    <div class="feedback-grid">
                        <?php foreach (array_slice($filteredSaranList, 0, 6) as $sr): 
                            $dateStr = !empty($sr['waktu_pengisian']) ? date('d/m/Y', strtotime((string)$sr['waktu_pengisian'])) : date('d/m/Y', strtotime((string)($sr['tanggal_survei'] ?? 'now')));
                            $saranText = trim((string)($sr['saran'] ?? ''));
                            if ($saranText === '') $saranText = 'Pelayanan berjalan baik, memuaskan, dan cepat.';
                            $serviceName = !empty($sr['jenis_layanan']) ? $sr['jenis_layanan'] : 'KIA';
                        ?>
                            <div class="feedback-item-card">
                                <div class="feedback-item-top">
                                    <span class="badge-service-pill"><?= esc_adm($serviceName) ?></span>
                                    <span class="feedback-date"><?= esc_adm($dateStr) ?></span>
                                </div>
                                <div class="feedback-item-body">
                                    &ldquo;<?= esc_adm($saranText) ?>&rdquo;
                                </div>
                                <div class="feedback-item-footer">
                                    &mdash; <?= esc_adm($sr['nama_lengkap'] ?? 'Pemohon') ?> (Loket Online)
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ============================================================== -->
            <!-- GRAFIK KARAKTERISTIK DEMOGRAFI RESPONDEN (UNIK)                -->
            <!-- ============================================================== -->
            <?php $demog = $analytics['demografi'] ?? []; ?>
            <div style="margin: 0 28px 24px 28px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
                    <div>
                        <h2 style="margin:0; font-size:17px; font-weight:800; color:#0f172a;">Karakteristik Responden</h2>
                        <p style="margin:3px 0 0 0; font-size:12.5px; color:#475569;">Berdasarkan <?= (int)($analytics['total_responden_unik'] ?? 0) ?> responden unik (tanpa penghitungan ganda antar-survei)</p>
                    </div>
                    <span style="font-size:11.5px; color:#0b6e75; background:#dceae7; border:1px solid #c6d8d4; padding:4px 10px; border-radius:999px; font-weight:600;">
                        Interval Usia: &lt;20, 20-29, 30-39, 40-49, 50+ Tahun
                    </span>
                </div>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:18px;">
                    <div class="chart-panel-card">
                        <div class="chart-panel-header">
                            <h3>A. Jenis Kelamin</h3>
                            <span class="header-note">Responden Unik</span>
                        </div>
                        <div style="height:220px; position:relative;">
                            <canvas id="demogJkChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-panel-card">
                        <div class="chart-panel-header">
                            <h3>B. Pendidikan Terakhir</h3>
                            <span class="header-note">Data Tersimpan</span>
                        </div>
                        <div style="height:220px; position:relative;">
                            <canvas id="demogPendidikanChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-panel-card">
                        <div class="chart-panel-header">
                            <h3>C. Kelompok Usia</h3>
                            <span class="header-note">Interval Konsisten</span>
                        </div>
                        <div style="height:220px; position:relative;">
                            <canvas id="demogUsiaChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-panel-card">
                        <div class="chart-panel-header">
                            <h3>D. Pekerjaan</h3>
                            <span class="header-note">Data Tersimpan</span>
                        </div>
                        <div style="height:220px; position:relative;">
                            <canvas id="demogPekerjaanChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================== -->
            <!-- ANALISIS PERBANDINGAN SKOR BERDASARKAN DEMOGRAFI               -->
            <!-- ============================================================== -->
            <?php $perbAll = $analytics['perbandingan'] ?? []; ?>
            <div style="margin: 0 28px 24px 28px;">
                <div class="chart-panel-card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
                        <div>
                            <h3 style="margin:0; font-size:16px; font-weight:800; color:#0f172a;">
                                <i class="fas fa-balance-scale" style="color:#0b6e75;"></i> Analisis Perbandingan Skor Berdasarkan Demografi
                            </h3>
                            <p style="margin:4px 0 0 0; font-size:12.5px; color:#475569;">
                                Indikasi perbandingan hasil penilaian antarkelompok masyarakat. Setiap jenis survei dipisahkan agar skala penilaian (SKM 1&ndash;4, SPKP &amp; SPAK 1&ndash;6) tidak tercampur.
                            </p>
                        </div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;" id="perbSurveyTabs">
                            <button type="button" class="btn-perb-tab active" data-survey="skm" style="padding:6px 12px; border-radius:8px; border:1px solid #0b6e75; background:#0b6e75; color:#fff; font-size:12px; font-weight:700; cursor:pointer;">SKM (Skala 1-4 / IKM)</button>
                            <button type="button" class="btn-perb-tab" data-survey="spkp" style="padding:6px 12px; border-radius:8px; border:1px solid #c6d8d4; background:#f2f6f5; color:#0f172a; font-size:12px; font-weight:700; cursor:pointer;">SPKP (Skala 1-6 / %)</button>
                            <button type="button" class="btn-perb-tab" data-survey="spak" style="padding:6px 12px; border-radius:8px; border:1px solid #c6d8d4; background:#f2f6f5; color:#0f172a; font-size:12px; font-weight:700; cursor:pointer;">SPAK (Skala 1-6 / %)</button>
                        </div>
                    </div>

                    <div id="perbDimTabs" style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;">
                        <button type="button" class="btn-dim-tab active" data-dim="jenis_kelamin" style="padding:5px 11px; border-radius:999px; border:1px solid #0b6e75; background:#dceae7; color:#0b6e75; font-size:12px; font-weight:700; cursor:pointer;">Jenis Kelamin</button>
                        <button type="button" class="btn-dim-tab" data-dim="pendidikan" style="padding:5px 11px; border-radius:999px; border:1px solid #cbd5e1; background:#ffffff; color:#475569; font-size:12px; font-weight:600; cursor:pointer;">Pendidikan</button>
                        <button type="button" class="btn-dim-tab" data-dim="kelompok_usia" style="padding:5px 11px; border-radius:999px; border:1px solid #cbd5e1; background:#ffffff; color:#475569; font-size:12px; font-weight:600; cursor:pointer;">Kelompok Usia</button>
                        <button type="button" class="btn-dim-tab" data-dim="pekerjaan" style="padding:5px 11px; border-radius:999px; border:1px solid #cbd5e1; background:#ffffff; color:#475569; font-size:12px; font-weight:600; cursor:pointer;">Pekerjaan</button>
                    </div>

                    <div id="perbWarningBanner" style="display:none; margin-bottom:14px; padding:10px 14px; border-radius:8px; background:#fef3c7; border:1px solid #f59e0b; color:#92400e; font-size:12.5px; line-height:1.5;">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Catatan Representativitas Sampel:</strong> Jumlah responden pada kelompok yang dibandingkan masih sedikit (&lt; 30 responden total atau &lt; 5 per kelompok), sehingga hasil ini baru merupakan <em>indikasi awal</em> perbandingan skor dan belum cukup representatif untuk menarik kesimpulan umum atau hubungan sebab-akibat.
                    </div>

                    <div id="perbHighlightBox" style="margin-bottom:14px; padding:10px 14px; border-radius:8px; background:#dceae7; border:1px solid #c6d8d4; color:#0f172a; font-size:12.5px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                        <span id="perbHighlightText">Memuat ringkasan perbandingan...</span>
                    </div>

                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:18px; align-items:start;">
                        <div style="height:240px; position:relative;">
                            <canvas id="demografiCompareChart"></canvas>
                        </div>
                        <div class="table-container" style="overflow-x:auto;">
                            <table style="width:100%; border-collapse:collapse; font-size:12.5px;">
                                <thead>
                                    <tr style="background:#f2f6f5; border-bottom:2px solid #c6d8d4;">
                                        <th style="padding:8px 10px; text-align:left;">Kelompok</th>
                                        <th style="padding:8px 10px; text-align:center;">Responden</th>
                                        <th style="padding:8px 10px; text-align:center;">Rata-Rata</th>
                                        <th style="padding:8px 10px; text-align:center;">Indeks / %</th>
                                    </tr>
                                </thead>
                                <tbody id="perbTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================== -->
            <!-- DAFTAR RESPONDEN SURVEI & MODAL DETAIL                         -->
            <!-- ============================================================== -->
            <div style="margin: 0 28px 30px 28px;" id="daftar-responden">
                <div class="table-section" style="background:#ffffff; border:1px solid #c6d8d4; border-radius:12px; padding:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                        <div>
                            <h3 style="margin:0; font-size:16px; font-weight:800; color:#0f172a;">
                                <i class="fas fa-address-book" style="color:#0b6e75;"></i> Daftar Responden Survei
                            </h3>
                            <p style="margin:3px 0 0 0; font-size:12px; color:#64748b;">
                                Menampilkan <?= count($pagedRespondenList) ?> dari <?= $respTotalItems ?> pengisian survei (<?= (int)($analytics['total_responden_unik'] ?? 0) ?> responden unik)
                            </p>
                        </div>
                    </div>

                    <?php if (empty($pagedRespondenList)): ?>
                        <div style="text-align:center; padding:32px; color:#64748b; font-size:13px;">
                            Belum ada data responden yang sesuai dengan filter terpilih.
                        </div>
                    <?php else: ?>
                        <div class="table-container" style="overflow-x:auto;">
                            <table style="width:100%; border-collapse:collapse; font-size:12.5px;">
                                <thead>
                                    <tr style="background:#f2f6f5; border-bottom:2px solid #c6d8d4; color:#0f172a;">
                                        <th style="padding:10px 10px; text-align:left;">No</th>
                                        <th style="padding:10px 10px; text-align:left;">Nama Responden</th>
                                        <th style="padding:10px 10px; text-align:left;">Nomor HP</th>
                                        <th style="padding:10px 10px; text-align:left;">Email</th>
                                        <th style="padding:10px 10px; text-align:center;">JK</th>
                                        <th style="padding:10px 10px; text-align:center;">Usia</th>
                                        <th style="padding:10px 10px; text-align:left;">Pendidikan</th>
                                        <th style="padding:10px 10px; text-align:left;">Pekerjaan</th>
                                        <th style="padding:10px 10px; text-align:left;">Kecamatan</th>
                                        <th style="padding:10px 10px; text-align:left;">Desa</th>
                                        <th style="padding:10px 10px; text-align:center;">Survei</th>
                                        <th style="padding:10px 10px; text-align:left;">Tanggal</th>
                                        <th style="padding:10px 10px; text-align:center;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $rowStartNo = (($respPage - 1) * $respPerPage) + 1;
                                    foreach ($pagedRespondenList as $idxR => $resp):
                                        $respJson = htmlspecialchars(json_encode($resp, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr style="border-bottom:1px solid #e2e8f0;">
                                            <td style="padding:9px 10px;"><?= $rowStartNo + $idxR ?></td>
                                            <td style="padding:9px 10px; font-weight:700; color:#0f172a;"><?= esc_adm($resp['nama_lengkap']) ?></td>
                                            <td style="padding:9px 10px;"><?= esc_adm($resp['no_hp']) ?></td>
                                            <td style="padding:9px 10px;"><?= esc_adm($resp['email']) ?></td>
                                            <td style="padding:9px 10px; text-align:center;"><?= esc_adm($resp['jenis_kelamin_kode'] ?: $resp['jenis_kelamin']) ?></td>
                                            <td style="padding:9px 10px; text-align:center;"><?= $resp['usia'] > 0 ? (int)$resp['usia'] . ' thn' : '-' ?></td>
                                            <td style="padding:9px 10px;"><?= esc_adm($resp['pendidikan']) ?></td>
                                            <td style="padding:9px 10px;"><?= esc_adm($resp['pekerjaan']) ?></td>
                                            <td style="padding:9px 10px;"><?= esc_adm($resp['nama_kecamatan']) ?></td>
                                            <td style="padding:9px 10px;"><?= esc_adm($resp['nama_desa']) ?></td>
                                            <td style="padding:9px 10px; text-align:center;">
                                                <span class="badge-service-pill"><?= esc_adm($resp['jenis_survei']) ?></span>
                                            </td>
                                            <td style="padding:9px 10px; white-space:nowrap;"><?= esc_adm(date('d/m/Y H:i', strtotime((string)$resp['waktu_pengisian']))) ?></td>
                                            <td style="padding:9px 10px; text-align:center;">
                                                <button type="button" class="btn-detail-responden" data-responden="<?= $respJson ?>" style="padding:5px 10px; border-radius:6px; border:1px solid #0b6e75; background:#dceae7; color:#0b6e75; font-size:11.5px; font-weight:700; cursor:pointer; white-space:nowrap;">
                                                    <i class="fas fa-eye"></i> Detail
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($respTotalPages > 1): ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; flex-wrap:wrap; gap:10px;">
                                <span style="font-size:12px; color:#64748b;">Halaman <?= $respPage ?> dari <?= $respTotalPages ?></span>
                                <div style="display:flex; gap:6px;">
                                    <?php for ($p = 1; $p <= $respTotalPages; $p++): ?>
                                        <a href="tanggapan_pemohon.php?tab=ringkasan<?= esc_adm($navQuerySuffix) ?>&amp;resp_page=<?= $p ?>#daftar-responden" style="padding:5px 10px; border-radius:6px; border:1px solid <?= $p === $respPage ? '#0b6e75' : '#cbd5e1' ?>; background:<?= $p === $respPage ? '#0b6e75' : '#ffffff' ?>; color:<?= $p === $respPage ? '#ffffff' : '#0f172a' ?>; text-decoration:none; font-size:12px; font-weight:600;"><?= $p ?></a>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================================================== -->
        <!-- TAB 2: DETAIL SKM (9 UNSUR SESUAI PERMENPANRB NO. 14/2017)     -->
        <!-- ============================================================== -->
        <?php if ($activeTab === 'skm'): ?>
            <div style="margin: 0 28px 30px 28px;">
                <div class="chart-panel-card" style="margin-bottom: 20px;">
                    <div class="chart-panel-header">
                        <h3><i class="fas fa-smile text-blue-600"></i> Hasil Survei Kepuasan Masyarakat (SKM) &mdash; 9 Unsur</h3>
                        <span class="header-note">Indeks: <?= number_format($skmNilai, 2) ?> (Mutu <?= $skmMutu ?> &mdash; <?= $skmKinerja ?>)</span>
                    </div>
                    <div style="height: 280px; position: relative;">
                        <canvas id="skmElementsChart"></canvas>
                    </div>
                </div>

                <div class="table-section" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <h3 style="margin:0; font-size:15px; font-weight:700;"><i class="fas fa-table"></i> Rincian Nilai 9 Unsur Pelayanan SKM</h3>
                        <a href="tanggapan_pemohon.php?export_excel=skm&amp;custom_start=<?= urlencode($customStart) ?>&amp;custom_end=<?= urlencode($customEnd) ?>" class="btn-unduh-excel-top" style="font-size:11.5px; padding:6px 12px;">
                            <i class="fas fa-file-excel"></i> Unduh Excel SKM
                        </a>
                    </div>
                    <div class="table-container">
                        <table style="min-width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                    <th style="padding:10px 14px; text-align:left;">Kode</th>
                                    <th style="padding:10px 14px; text-align:left;">Unsur Pelayanan SKM</th>
                                    <th style="padding:10px 14px; text-align:center;">Jumlah Responden</th>
                                    <th style="padding:10px 14px; text-align:center;">NRR Unsur</th>
                                    <th style="padding:10px 14px; text-align:center;">NRR Tertimbang (x 0.111)</th>
                                    <th style="padding:10px 14px; text-align:center;">Mutu</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($skmElementsMeta as $code => $title):
                                    $rowU = $skmDbMap[$code] ?? ['rata_rata' => 0.0, 'jumlah_respons' => 0];
                                    $nrr = (float)$rowU['rata_rata'];
                                    $nrrT = round($nrr * 0.111, 3);
                                    $jml = (int)$rowU['jumlah_respons'];
                                ?>
                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                        <td style="padding:10px 14px; font-weight:700; color:#2563eb;"><?= $code ?></td>
                                        <td style="padding:10px 14px;"><?= esc_adm($title) ?></td>
                                        <td style="padding:10px 14px; text-align:center;"><?= $jml ?></td>
                                        <td style="padding:10px 14px; text-align:center; font-weight:700;"><?= number_format($nrr, 2) ?></td>
                                        <td style="padding:10px 14px; text-align:center;"><?= number_format($nrrT, 3) ?></td>
                                        <td style="padding:10px 14px; text-align:center;">
                                            <span class="badge-pill-inline blue"><?= $jml > 0 ? $skmMutu : '-' ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================================================== -->
        <!-- TAB 3: DETAIL SPKP (8 UNSUR KUALITAS PELAYANAN)                -->
        <!-- ============================================================== -->
        <?php if ($activeTab === 'spkp'): ?>
            <div style="margin: 0 28px 30px 28px;">
                <div class="chart-panel-card" style="margin-bottom: 20px;">
                    <div class="chart-panel-header">
                        <h3><i class="fas fa-star text-emerald-600"></i> Hasil Survei Persepsi Kualitas Pelayanan (SPKP) &mdash; 8 Unsur</h3>
                        <span class="header-note">Rata-Rata: <?= number_format($spkpRata, 2) ?> / 6.00 (<?= number_format($spkpPersen, 1) ?>%)</span>
                    </div>
                    <div style="height: 280px; position: relative;">
                        <canvas id="spkpElementsChart"></canvas>
                    </div>
                </div>

                <div class="table-section" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <h3 style="margin:0; font-size:15px; font-weight:700;"><i class="fas fa-table"></i> Rincian Nilai 8 Pernyataan SPKP</h3>
                        <a href="tanggapan_pemohon.php?export_excel=spkp&amp;custom_start=<?= urlencode($customStart) ?>&amp;custom_end=<?= urlencode($customEnd) ?>" class="btn-unduh-excel-top" style="font-size:11.5px; padding:6px 12px; background:#0284c7;">
                            <i class="fas fa-file-excel"></i> Unduh Excel SPKP
                        </a>
                    </div>
                    <div class="table-container">
                        <table style="min-width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                    <th style="padding:10px 14px; text-align:left;">Kode</th>
                                    <th style="padding:10px 14px; text-align:left;">Pernyataan Kualitas Pelayanan</th>
                                    <th style="padding:10px 14px; text-align:center;">Jumlah Respons</th>
                                    <th style="padding:10px 14px; text-align:center;">Skor Rata-Rata (Skala 1-6)</th>
                                    <th style="padding:10px 14px; text-align:center;">Capaian Indeks (%)</th>
                                    <th style="padding:10px 14px; text-align:center;">Kategori</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($spkpElementsMeta as $code => $title):
                                    $rowP = $spkpDbMap[$code] ?? ['rata_rata' => 0.0, 'skor_persen' => 0.0, 'jumlah_respons' => 0];
                                    $val = (float)$rowP['rata_rata'];
                                    $pct = (float)$rowP['skor_persen'];
                                    $jml = (int)$rowP['jumlah_respons'];
                                ?>
                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                        <td style="padding:10px 14px; font-weight:700; color:#0284c7;"><?= $code ?></td>
                                        <td style="padding:10px 14px;"><?= esc_adm($title) ?></td>
                                        <td style="padding:10px 14px; text-align:center;"><?= $jml ?></td>
                                        <td style="padding:10px 14px; text-align:center; font-weight:700;"><?= number_format($val, 2) ?></td>
                                        <td style="padding:10px 14px; text-align:center; font-weight:700;"><?= number_format($pct, 1) ?>%</td>
                                        <td style="padding:10px 14px; text-align:center;">
                                            <span class="badge-pill-inline green"><?= esc_adm(getPredikatSkala6($val, $jml, false)) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================================================== -->
        <!-- TAB 4: DETAIL SPAK (5 UNSUR ANTI KORUPSI)                      -->
        <!-- ============================================================== -->
        <?php if ($activeTab === 'spak'): ?>
            <div style="margin: 0 28px 30px 28px;">
                <div class="chart-panel-card" style="margin-bottom: 20px;">
                    <div class="chart-panel-header">
                        <h3><i class="fas fa-shield-alt text-purple-600"></i> Hasil Survei Persepsi Anti Korupsi (SPAK) &mdash; 5 Unsur</h3>
                        <span class="header-note">Indeks Integritas: <?= number_format($spakRata, 2) ?> / 6.00 (<?= number_format($spakPersen, 1) ?>%)</span>
                    </div>
                    <div style="height: 280px; position: relative;">
                        <canvas id="spakElementsChart"></canvas>
                    </div>
                </div>

                <div class="table-section" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <h3 style="margin:0; font-size:15px; font-weight:700;"><i class="fas fa-table"></i> Rincian Nilai 5 Indikator SPAK</h3>
                        <a href="tanggapan_pemohon.php?export_excel=spak&amp;custom_start=<?= urlencode($customStart) ?>&amp;custom_end=<?= urlencode($customEnd) ?>" class="btn-unduh-excel-top" style="font-size:11.5px; padding:6px 12px; background:#7c3aed;">
                            <i class="fas fa-file-excel"></i> Unduh Excel SPAK
                        </a>
                    </div>
                    <div class="table-container">
                        <table style="min-width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                    <th style="padding:10px 14px; text-align:left;">Kode</th>
                                    <th style="padding:10px 14px; text-align:left;">Indikator Persepsi Anti Korupsi</th>
                                    <th style="padding:10px 14px; text-align:center;">Jumlah Respons</th>
                                    <th style="padding:10px 14px; text-align:center;">Skor Rata-Rata (Skala 1-6)</th>
                                    <th style="padding:10px 14px; text-align:center;">Indeks Integritas (%)</th>
                                    <th style="padding:10px 14px; text-align:center;">Predikat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($spakElementsMeta as $code => $title):
                                    $rowA = $spakDbMap[$code] ?? ['rata_rata' => 0.0, 'skor_persen' => 0.0, 'jumlah_respons' => 0];
                                    $val = (float)$rowA['rata_rata'];
                                    $pct = (float)$rowA['skor_persen'];
                                    $jml = (int)$rowA['jumlah_respons'];
                                ?>
                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                        <td style="padding:10px 14px; font-weight:700; color:#7c3aed;"><?= $code ?></td>
                                        <td style="padding:10px 14px;"><?= esc_adm($title) ?></td>
                                        <td style="padding:10px 14px; text-align:center;"><?= $jml ?></td>
                                        <td style="padding:10px 14px; text-align:center; font-weight:700;"><?= number_format($val, 2) ?></td>
                                        <td style="padding:10px 14px; text-align:center; font-weight:700;"><?= number_format($pct, 1) ?>%</td>
                                        <td style="padding:10px 14px; text-align:center;">
                                            <span class="badge-pill-inline purple"><?= esc_adm(getPredikatSkala6($val, $jml, true)) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================================================== -->
        <!-- TAB 5: PUSAT UNDUH EXCEL TERPADU                               -->
        <!-- ============================================================== -->
        <?php if ($activeTab === 'excel'): ?>
            <div style="margin: 0 28px 30px 28px;">
                <div style="background:#dceae7; border:1px solid #0b6e75; border-radius:12px; padding:20px 24px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                    <div style="max-width:680px;">
                        <span style="display:inline-block; background:#0b6e75; color:#fff; font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px; margin-bottom:8px;">PAKET LAPORAN LENGKAP (7 WORKSHEET)</span>
                        <h3 style="margin:0 0 6px 0; font-size:16px; font-weight:800; color:#0f172a;">Unduh Semua Hasil Survei (.xlsx)</h3>
                        <p style="margin:0; font-size:12.5px; color:#334155; line-height:1.5;">
                            Satu workbook Excel berisi 7 worksheet terintegrasi: <strong>Ringkasan Survei</strong>, <strong>Data Responden</strong>, <strong>Hasil SKM</strong>, <strong>Hasil SPKP</strong>, <strong>Hasil SPAK</strong>, <strong>Rincian Jawaban</strong>, dan <strong>Rekapitulasi Demografi</strong> sesuai filter aktif.
                        </p>
                    </div>
                    <a href="tanggapan_pemohon.php?export_excel=semua&amp;periode=<?= urlencode($periode) ?>&amp;tgl_mulai=<?= urlencode($tglMulai) ?>&amp;tgl_akhir=<?= urlencode($tglAkhir) ?>&amp;jenis_survei=<?= urlencode($filterJenisSurvei) ?>&amp;layanan=<?= urlencode($filterLayanan) ?>" class="btn-unduh-excel-top" style="background:#0b6e75; padding:11px 20px; font-size:13px;">
                        <i class="fas fa-download"></i> Unduh Semua Hasil Survei (.xlsx)
                    </a>
                </div>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:18px; margin-bottom:24px;">
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:22px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                        <div style="width:40px; height:40px; border-radius:8px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:20px; margin-bottom:12px;">
                            <i class="fas fa-file-excel"></i>
                        </div>
                        <h4 style="margin:0 0 6px 0; font-size:15px; color:#0f172a;">Laporan Excel SKM</h4>
                        <p style="margin:0 0 16px 0; font-size:12px; color:#64748b; line-height:1.5;">Ekspor rekapitulasi data mentah dan perhitungan nilai IKM 9 unsur pelayanan.</p>
                        <a href="tanggapan_pemohon.php?export_excel=skm&amp;periode=<?= urlencode($periode) ?>&amp;custom_start=<?= urlencode($customStart) ?>&amp;custom_end=<?= urlencode($customEnd) ?>" class="btn-unduh-excel-top" style="display:flex; justify-content:center;">
                            Unduh File SKM (.xlsx)
                        </a>
                    </div>

                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:22px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                        <div style="width:40px; height:40px; border-radius:8px; background:#ecfdf5; color:#059669; display:flex; align-items:center; justify-content:center; font-size:20px; margin-bottom:12px;">
                            <i class="fas fa-file-excel"></i>
                        </div>
                        <h4 style="margin:0 0 6px 0; font-size:15px; color:#0f172a;">Laporan Excel SPKP</h4>
                        <p style="margin:0 0 16px 0; font-size:12px; color:#64748b; line-height:1.5;">Ekspor lembar evaluasi kualitas pelayanan 8 pernyataan skala 1-6.</p>
                        <a href="tanggapan_pemohon.php?export_excel=spkp&amp;periode=<?= urlencode($periode) ?>&amp;custom_start=<?= urlencode($customStart) ?>&amp;custom_end=<?= urlencode($customEnd) ?>" class="btn-unduh-excel-top" style="display:flex; justify-content:center; background:#0284c7;">
                            Unduh File SPKP (.xlsx)
                        </a>
                    </div>

                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:22px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                        <div style="width:40px; height:40px; border-radius:8px; background:#f5f3ff; color:#7c3aed; display:flex; align-items:center; justify-content:center; font-size:20px; margin-bottom:12px;">
                            <i class="fas fa-file-excel"></i>
                        </div>
                        <h4 style="margin:0 0 6px 0; font-size:15px; color:#0f172a;">Laporan Excel SPAK</h4>
                        <p style="margin:0 0 16px 0; font-size:12px; color:#64748b; line-height:1.5;">Ekspor indikator survei persepsi anti korupsi 5 butir pertanyaan.</p>
                        <a href="tanggapan_pemohon.php?export_excel=spak&amp;periode=<?= urlencode($periode) ?>&amp;custom_start=<?= urlencode($customStart) ?>&amp;custom_end=<?= urlencode($customEnd) ?>" class="btn-unduh-excel-top" style="display:flex; justify-content:center; background:#7c3aed;">
                            Unduh File SPAK (.xlsx)
                        </a>
                    </div>
                </div>

                <!-- Tabel Responden & Saran Tersimpan -->
                <div class="table-section" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
                    <h3 style="margin:0 0 16px 0; font-size:15px; font-weight:700;"><i class="fas fa-comments"></i> Riwayat Masukan &amp; Pengisian Responden</h3>
                    <?php if (empty($filteredSaranList)): ?>
                        <div style="text-align:center; padding:32px; color:#94a3b8; font-size:13px;">Belum ada saran atau pengisian survei pada filter ini.</div>
                    <?php else: ?>
                        <div class="table-container">
                            <table style="min-width:100%; border-collapse:collapse;">
                                <thead>
                                    <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                        <th style="padding:10px 14px; text-align:left;">No</th>
                                        <th style="padding:10px 14px; text-align:left;">Tanggal</th>
                                        <th style="padding:10px 14px; text-align:left;">Nama Responden</th>
                                        <th style="padding:10px 14px; text-align:left;">Layanan</th>
                                        <th style="padding:10px 14px; text-align:left;">Saran &amp; Masukan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; foreach ($filteredSaranList as $sr): ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;">
                                            <td style="padding:10px 14px;"><?= $no++ ?></td>
                                            <td style="padding:10px 14px;"><?= esc_adm(!empty($sr['waktu_pengisian']) ? date('d/m/Y H:i', strtotime((string)$sr['waktu_pengisian'])) : $sr['tanggal_survei']) ?></td>
                                            <td style="padding:10px 14px; font-weight:700;"><?= esc_adm($sr['nama_lengkap']) ?></td>
                                            <td style="padding:10px 14px;"><span class="badge-service-pill">KIA</span></td>
                                            <td style="padding:10px 14px; font-style:italic;"><?= esc_adm($sr['saran'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </main>
</div>

<!-- Modal Konfirmasi Logout Resmi Sesuai Pola Asli SIPRIMA -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3><i class="fas fa-sign-out-alt"></i> Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
            <button id="cancelLogout" class="btn-no">Batal</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target) && sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            }
        });
    }

    // Logout Modal Sesuai Pola Asli SIPRIMA
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');

    function showLogoutModal() {
        if (logoutModal) {
            logoutModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }
    function hideLogoutModal() {
        if (logoutModal) {
            logoutModal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', function() {
        window.location.href = '../logout.php';
    });
    if (logoutModal) {
        logoutModal.addEventListener('click', function(e) {
            if (e.target === logoutModal) hideLogoutModal();
        });
    }

    // Chart Inisialisasi
    if (typeof Chart !== 'undefined') {
        // 1. Chart Ringkasan: Perbandingan Capaian Tiga Instrumen Survei
        const sumCanvas = document.getElementById('summaryComparisonChart');
        if (sumCanvas) {
            new Chart(sumCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['SKM (Konversi 100)', 'SPKP (Konversi 100)', 'SPAK (Konversi 100)'],
                    datasets: [{
                        label: 'Skala Konversi 100%',
                        data: [<?= round($skmNilai, 2) ?>, <?= round($spkpPersen, 2) ?>, <?= round($spakPersen, 2) ?>],
                        backgroundColor: ['#1e293b', '#059669', '#7c3aed'],
                        borderRadius: 6,
                        maxBarThickness: 110
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: { stepSize: 10, color: '#94a3b8', font: { size: 11 } },
                            grid: { color: '#f1f5f9' }
                        },
                        x: {
                            ticks: { color: '#334155', font: { size: 11.5, weight: '600' } },
                            grid: { display: false }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ' Nilai: ' + ctx.raw + '%'
                            }
                        }
                    }
                }
            });
        }

        // 2. Chart Ringkasan: Responden per Layanan (Doughnut)
        const srvCanvas = document.getElementById('servicesDoughnutChart');
        if (srvCanvas) {
            new Chart(srvCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_keys($layananCounts)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_values($layananCounts)) ?>,
                        backgroundColor: ['#0284c7', '#2563eb', '#10b981', '#f59e0b', '#8b5cf6'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 14,
                                font: { size: 11.5, weight: '600' },
                                color: '#475569'
                            }
                        }
                    }
                }
            });
        }

        // 3. Chart Detail SKM
        const skmCanvas = document.getElementById('skmElementsChart');
        if (skmCanvas) {
            new Chart(skmCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_keys($skmElementsMeta)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_map(fn($k) => (float)($skmDbMap[$k]['rata_rata'] ?? 0.0), array_keys($skmElementsMeta))) ?>,
                        backgroundColor: '#2563eb',
                        borderRadius: 6,
                        maxBarThickness: 45
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, max: 4, ticks: { stepSize: 1 } } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        // 4. Chart Detail SPKP
        const spkpCanvas = document.getElementById('spkpElementsChart');
        if (spkpCanvas) {
            new Chart(spkpCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_keys($spkpElementsMeta)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_map(fn($k) => (float)($spkpDbMap[$k]['rata_rata'] ?? 0.0), array_keys($spkpElementsMeta))) ?>,
                        backgroundColor: '#0284c7',
                        borderRadius: 6,
                        maxBarThickness: 45
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, max: 6, ticks: { stepSize: 1 } } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        // 5. Chart Detail SPAK
        const spakCanvas = document.getElementById('spakElementsChart');
        if (spakCanvas) {
            new Chart(spakCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_keys($spakElementsMeta)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_map(fn($k) => (float)($spakDbMap[$k]['rata_rata'] ?? 0.0), array_keys($spakElementsMeta))) ?>,
                        backgroundColor: '#7c3aed',
                        borderRadius: 6,
                        maxBarThickness: 45
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, max: 6, ticks: { stepSize: 1 } } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        // 6. Empat Grafik Karakteristik Demografi Responden (Responden Unik)
        const demogData = <?= json_encode($analytics['demografi'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
        const makeDemogBarChart = (canvasId, items, color) => {
            const el = document.getElementById(canvasId);
            if (!el) return;
            const list = Array.isArray(items) ? items : [];
            new Chart(el.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: list.map(i => i.kategori + ' (' + i.persen + '%)'),
                    datasets: [{
                        label: 'Jumlah Responden',
                        data: list.map(i => i.jumlah),
                        backgroundColor: color,
                        borderRadius: 6,
                        maxBarThickness: 42
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ' ' + ctx.raw + ' responden (' + (list[ctx.dataIndex]?.persen ?? 0) + '%)'
                            }
                        }
                    }
                }
            });
        };

        const jkCanvas = document.getElementById('demogJkChart');
        if (jkCanvas) {
            const jkList = Array.isArray(demogData.jenis_kelamin) ? demogData.jenis_kelamin : [];
            new Chart(jkCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: jkList.map(i => i.kategori + ' (' + i.jumlah + ' / ' + i.persen + '%)'),
                    datasets: [{
                        data: jkList.map(i => i.jumlah),
                        backgroundColor: ['#0b6e75', '#0284c7', '#94a3b8'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11.5 } } }
                    }
                }
            });
        }

        makeDemogBarChart('demogPendidikanChart', demogData.pendidikan, '#0b6e75');
        makeDemogBarChart('demogUsiaChart', demogData.kelompok_usia, '#0284c7');
        makeDemogBarChart('demogPekerjaanChart', demogData.pekerjaan, '#059669');

        // 7. Analisis Perbandingan Skor Berdasarkan Demografi (Terpisah per Instrumen)
        const perbData = <?= json_encode($analytics['perbandingan'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
        const perbCanvas = document.getElementById('demografiCompareChart');
        let perbChartInstance = null;
        let activePerbSurvey = 'skm';
        let activePerbDim = 'jenis_kelamin';

        const renderPerbandingan = () => {
            if (!perbCanvas) return;
            const surveyObj = perbData[activePerbSurvey] || {};
            const dimObj = surveyObj[activePerbDim] || { items: [], kelompok_tertinggi: '-', indeks_tertinggi: 0, total_responden: 0, kurang_representatif: true };
            const items = Array.isArray(dimObj.items) ? dimObj.items : [];

            const warnEl = document.getElementById('perbWarningBanner');
            if (warnEl) {
                warnEl.style.display = dimObj.kurang_representatif ? 'block' : 'none';
            }

            const hiEl = document.getElementById('perbHighlightText');
            if (hiEl) {
                const skalaLabel = activePerbSurvey === 'skm' ? 'IKM (Skala 1-4)' : 'Persentase (Skala 1-6)';
                if (items.length > 0 && dimObj.kelompok_tertinggi !== '-') {
                    hiEl.innerHTML = '<strong>Indikasi Skor Tertinggi (' + activePerbSurvey.toUpperCase() + ' &mdash; ' + skalaLabel + '):</strong> Kelompok <strong>' + dimObj.kelompok_tertinggi + '</strong> dengan nilai <strong>' + dimObj.indeks_tertinggi + (activePerbSurvey === 'skm' ? '' : '%') + '</strong> (dari total ' + dimObj.total_responden + ' responden).';
                } else {
                    hiEl.textContent = 'Belum ada data responden untuk perbandingan pada dimensi dan filter ini.';
                }
            }

            const tbody = document.getElementById('perbTableBody');
            if (tbody) {
                if (items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="padding:14px; text-align:center; color:#64748b;">Belum ada data pada kategori ini.</td></tr>';
                } else {
                    tbody.innerHTML = items.map(it => {
                        const isTop = it.kelompok === dimObj.kelompok_tertinggi;
                        const badge = isTop ? ' <span style="background:#0b6e75; color:#fff; font-size:10px; padding:2px 6px; border-radius:999px; margin-left:4px;">Tertinggi</span>' : '';
                        const idxSuffix = activePerbSurvey === 'skm' ? '' : '%';
                        return '<tr style="border-bottom:1px solid #e2e8f0;">'
                            + '<td style="padding:8px 10px; font-weight:600; color:#0f172a;">' + it.kelompok + badge + '</td>'
                            + '<td style="padding:8px 10px; text-align:center;">' + it.jumlah_responden + '</td>'
                            + '<td style="padding:8px 10px; text-align:center; font-weight:700;">' + Number(it.rata_rata).toFixed(2) + '</td>'
                            + '<td style="padding:8px 10px; text-align:center; font-weight:700; color:#0b6e75;">' + Number(it.indeks).toFixed(2) + idxSuffix + '</td>'
                            + '</tr>';
                    }).join('');
                }
            }

            if (perbChartInstance) {
                perbChartInstance.destroy();
            }
            perbChartInstance = new Chart(perbCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: items.map(i => i.kelompok + ' (n=' + i.jumlah_responden + ')'),
                    datasets: [{
                        label: activePerbSurvey === 'skm' ? 'Indeks IKM (0-100)' : 'Capaian (%)',
                        data: items.map(i => i.indeks),
                        backgroundColor: activePerbSurvey === 'skm' ? '#0b6e75' : (activePerbSurvey === 'spkp' ? '#0284c7' : '#7c3aed'),
                        borderRadius: 6,
                        maxBarThickness: 48
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, max: 100 }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => {
                                    const row = items[ctx.dataIndex];
                                    return ' Rata-rata: ' + row.rata_rata + ' | Indeks: ' + row.indeks + (activePerbSurvey === 'skm' ? '' : '%') + ' (n=' + row.jumlah_responden + ')';
                                }
                            }
                        }
                    }
                }
            });
        };

        document.querySelectorAll('.btn-perb-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                activePerbSurvey = btn.dataset.survey || 'skm';
                document.querySelectorAll('.btn-perb-tab').forEach(b => {
                    const active = b === btn;
                    b.style.background = active ? '#0b6e75' : '#f2f6f5';
                    b.style.color = active ? '#ffffff' : '#0f172a';
                    b.style.borderColor = active ? '#0b6e75' : '#c6d8d4';
                });
                renderPerbandingan();
            });
        });

        document.querySelectorAll('.btn-dim-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                activePerbDim = btn.dataset.dim || 'jenis_kelamin';
                document.querySelectorAll('.btn-dim-tab').forEach(b => {
                    const active = b === btn;
                    b.style.background = active ? '#dceae7' : '#ffffff';
                    b.style.color = active ? '#0b6e75' : '#475569';
                    b.style.borderColor = active ? '#0b6e75' : '#cbd5e1';
                    b.style.fontWeight = active ? '700' : '600';
                });
                renderPerbandingan();
            });
        });

        renderPerbandingan();
    }

    // Modal Detail Responden
    const detailModal = document.getElementById('respondenDetailModal');
    const detailCloseBtn = document.getElementById('closeRespondenModal');
    const detailBody = document.getElementById('respondenDetailBody');

    const escapeHtml = (str) => String(str ?? '-')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    document.querySelectorAll('.btn-detail-responden').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!detailModal || !detailBody) return;
            let r = {};
            try {
                r = JSON.parse(btn.getAttribute('data-responden') || '{}');
            } catch (e) {
                r = {};
            }
            const meta = r.pertanyaan_meta || {};
            const ans = r.jawaban || {};
            let ansRows = '';
            Object.keys(meta).forEach(code => {
                const val = ans[code] !== undefined ? ans[code] : '-';
                ansRows += '<tr style="border-bottom:1px solid #e2e8f0;">'
                    + '<td style="padding:7px 10px; font-weight:700; color:#0b6e75; width:60px;">' + escapeHtml(code) + '</td>'
                    + '<td style="padding:7px 10px;">' + escapeHtml(meta[code]) + '</td>'
                    + '<td style="padding:7px 10px; text-align:center; font-weight:700;">' + escapeHtml(val) + '</td>'
                    + '</tr>';
            });

            detailBody.innerHTML = '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap:10px; background:#f2f6f5; border:1px solid #c6d8d4; border-radius:8px; padding:12px; margin-bottom:14px; font-size:12.5px;">'
                + '<div><strong>Nama:</strong> ' + escapeHtml(r.nama_lengkap) + '</div>'
                + '<div><strong>No. HP:</strong> ' + escapeHtml(r.no_hp) + '</div>'
                + '<div><strong>Email:</strong> ' + escapeHtml(r.email) + '</div>'
                + '<div><strong>Jenis Kelamin:</strong> ' + escapeHtml(r.jenis_kelamin) + '</div>'
                + '<div><strong>Usia:</strong> ' + (r.usia > 0 ? escapeHtml(r.usia + ' tahun (' + r.kelompok_usia + ')') : '-') + '</div>'
                + '<div><strong>Pendidikan:</strong> ' + escapeHtml(r.pendidikan) + '</div>'
                + '<div><strong>Pekerjaan:</strong> ' + escapeHtml(r.pekerjaan) + '</div>'
                + '<div><strong>Kecamatan / Desa:</strong> ' + escapeHtml(r.nama_kecamatan + ' / ' + r.nama_desa) + '</div>'
                + '<div style="grid-column:1 / -1;"><strong>Alamat:</strong> ' + escapeHtml(r.alamat) + '</div>'
                + '<div><strong>Jenis Survei:</strong> ' + escapeHtml(r.jenis_survei) + '</div>'
                + '<div><strong>Waktu Pengisian:</strong> ' + escapeHtml(r.waktu_pengisian) + '</div>'
                + '<div><strong>Rata-Rata / Indeks:</strong> ' + escapeHtml(r.rata_skor) + ' (' + escapeHtml(r.indeks_persen) + (r.jenis_survei_kode === 'skm' ? ' IKM' : '%') + ' &mdash; ' + escapeHtml(r.kategori) + ')</div>'
                + '</div>'
                + '<h4 style="margin:0 0 8px 0; font-size:13.5px; color:#0f172a;">Rincian Jawaban</h4>'
                + '<div style="overflow-x:auto; margin-bottom:12px;">'
                + '<table style="width:100%; border-collapse:collapse; font-size:12.5px;">'
                + '<thead><tr style="background:#dceae7; border-bottom:1px solid #c6d8d4;"><th style="padding:7px 10px; text-align:left;">Kode</th><th style="padding:7px 10px; text-align:left;">Pertanyaan / Indikator</th><th style="padding:7px 10px; text-align:center;">Nilai</th></tr></thead>'
                + '<tbody>' + (ansRows || '<tr><td colspan="3" style="padding:10px; text-align:center;">Tidak ada jawaban.</td></tr>') + '</tbody>'
                + '</table></div>'
                + '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; font-size:12.5px;">'
                + '<strong>Saran / Masukan:</strong> ' + escapeHtml(r.saran)
                + '</div>';

            detailModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        });
    });

    if (detailCloseBtn && detailModal) {
        detailCloseBtn.addEventListener('click', () => {
            detailModal.classList.remove('show');
            document.body.style.overflow = '';
        });
        detailModal.addEventListener('click', (e) => {
            if (e.target === detailModal) {
                detailModal.classList.remove('show');
                document.body.style.overflow = '';
            }
        });
    }
});
</script>

<!-- Modal Detail Responden -->
<div class="modal-overlay" id="respondenDetailModal">
    <div class="modal-box" style="max-width:740px; width:94%; text-align:left; max-height:88vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #c6d8d4; padding-bottom:10px; margin-bottom:14px;">
            <h3 style="margin:0; font-size:16px; color:#0f172a;"><i class="fas fa-user-check" style="color:#0b6e75;"></i> Detail Jawaban Responden</h3>
            <button type="button" id="closeRespondenModal" style="background:#f2f6f5; border:1px solid #c6d8d4; border-radius:6px; padding:4px 10px; cursor:pointer; font-weight:700; color:#0f172a;">&times; Tutup</button>
        </div>
        <div id="respondenDetailBody"></div>
    </div>
</div>
</body>
</html>
