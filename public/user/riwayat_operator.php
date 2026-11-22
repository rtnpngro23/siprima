<?php
require_once '../error_handler.php';
error_log("=== START RIWAYAT OPERATOR ===");
error_log('Request: ' . siprima_request_id());
error_log("Role: " . ($_SESSION['role'] ?? 'NOT SET'));
error_log("User ID: " . ($_SESSION['id_user'] ?? 'NOT SET'));
error_log("Kecamatan tugas IDs: " . print_r($_SESSION['kecamatan_tugas'] ?? [], true));
error_log("Kecamatan nama: " . print_r($_SESSION['kecamatan_nama'] ?? [], true));

include '../../app/middleware/operator_only.php';
include '../../app/config/koneksi.php';

// Fungsi helper untuk escape output
function esc($string) {
    return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
}

function isValidFilterDate($date) {
    $date_obj = DateTime::createFromFormat('Y-m-d', $date);
    return $date_obj && $date_obj->format('Y-m-d') === $date;
}

// ====================== PAGINATION SETTINGS ======================
$per_page = 10; // Jumlah data per halaman
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;
$total_records = 0;
$total_pages = 0;

// === Tentukan kecamatan tugas operator berdasarkan session array ===
$allowed_kecamatan_ids = $_SESSION['kecamatan_tugas'] ?? []; // Array ID kecamatan
$allowed_kecamatan_names = $_SESSION['kecamatan_nama'] ?? []; // Array nama kecamatan

// Ambil semua kecamatan dari database untuk operator yang bertugas di semua kecamatan
$all_kecamatan_names = [];
$all_kecamatan_ids = [];

if (empty($allowed_kecamatan_ids)) {
    error_log("Operator has no kecamatan assigned! Loading all kecamatan...");
    // Operator dengan semua kecamatan - ambil semua kecamatan dari database
    $kec_query = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
    if ($kec_query) {
        while ($row = $kec_query->fetch_assoc()) {
            $all_kecamatan_ids[] = $row['id'];
            $all_kecamatan_names[] = $row['nama_kecamatan'];
        }
        error_log("Loaded all kecamatan: " . count($all_kecamatan_names));
    }
    $allowed_kecamatan_ids = $all_kecamatan_ids;
    $allowed_kecamatan_names = $all_kecamatan_names;
} else {
    error_log("Operator has kecamatan assigned: " . count($allowed_kecamatan_names));
}

// === Filter ===
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_date_start = trim($_GET['date_start'] ?? ($_GET['date'] ?? ''));
$filter_date_end = trim($_GET['date_end'] ?? '');
$filter_kecamatan = $_GET['kecamatan'] ?? '';

if ($filter_date_start !== '' && !isValidFilterDate($filter_date_start)) {
    $filter_date_start = '';
}

if ($filter_date_end !== '' && !isValidFilterDate($filter_date_end)) {
    $filter_date_end = '';
}

if ($filter_date_start !== '' && $filter_date_end !== '' && strtotime($filter_date_start) > strtotime($filter_date_end)) {
    [$filter_date_start, $filter_date_end] = [$filter_date_end, $filter_date_start];
}

$riwayat = [];
$all_riwayat_data = []; // Untuk menyimpan semua data sebelum pagination

// Konfigurasi tabel dengan flag exclude_rs_puskes
$tables_config = [
    'tb_kartukeluarga' => [
        'nama_field' => 'nama_kepalaKeluarga', 
        'jenis' => 'Kartu Keluarga',
        'exclude_rs_puskes' => false
    ],
    'tb_akta_kelahiran' => [
        'nama_field' => 'nama_bayi', 
        'jenis' => 'Akta Kelahiran',
        'exclude_rs_puskes' => true
    ],
    'tb_akta_kematian' => [
        'nama_field' => 'Nama_meninggal', 
        'jenis' => 'Akta Kematian',
        'exclude_rs_puskes' => true
    ],
    'tb_pindah_alamat' => [
        'nama_field' => 'nama_kepalaKeluarga', 
        'jenis' => 'Pindah Alamat',
        'exclude_rs_puskes' => false
    ],
    'tb_pindah_online' => [
        'nama_field' => 'nama_kepalaKeluarga', 
        'jenis' => 'Pindah Masuk',
        'exclude_rs_puskes' => false
    ]
];

// TAMBAHAN: Konfigurasi untuk tabel laporan peristiwa dan KIA
$tables_config['tb_laporan_peristiwa'] = [
    'nama_field' => 'nama_subjek',
    'jenis' => 'Laporan Peristiwa',
    'exclude_rs_puskes' => false
];
$tables_config['tb_pengajuan_kia'] = [
    'nama_field' => 'no_kk',
    'jenis' => 'Kartu Identitas Anak (KIA)',
    'exclude_rs_puskes' => false
];

foreach ($tables_config as $tbl => $config) {
    // Cek apakah tabel ada
    $check = $koneksi->query("SHOW TABLES LIKE '" . $koneksi->real_escape_string($tbl) . "'");
    if ($check && $check->num_rows > 0) {
        // Cek struktur tabel untuk kolom yang tersedia
        $structure = $koneksi->query("DESCRIBE `$tbl`");
        $columns = [];
        while ($col = $structure->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        
        // Tentukan kolom tanggal yang akan digunakan
        $date_column = 'created_at'; // default
        if (in_array('update_at', $columns)) {
            $date_column = 'update_at';
        } elseif (in_array('updated_at', $columns)) {
            $date_column = 'updated_at';
        }
        
        // Tentukan field nama utama berdasarkan tabel
        $nama_field = $config['nama_field'];
        // Hapus backticks jika ada
        $nama_field = str_replace('`', '', $nama_field);
        
        // Tentukan field nama user (pelapor) - khusus untuk laporan peristiwa
        $nama_user_field = 'nama_pelapor'; // default untuk tb_laporan_peristiwa
        if ($tbl === 'tb_laporan_peristiwa') {
            $nama_user_field = 'nama_pelapor';
        } else {
            // Untuk tabel lain, gunakan join dengan tb_users
            $nama_user_field = 'u.nama AS nama_user';
        }
        
        // Base query dengan prepared statement
        if ($tbl === 'tb_laporan_peristiwa') {
            $has_id_user = in_array('id_user', $columns, true);
            // Query khusus untuk tb_laporan_peristiwa
            $base_query = "SELECT 
                            t.id_lapor as id,
                            t.created_at,
                            " . (in_array($date_column, $columns) ? "t.$date_column," : "t.created_at,") . "
                            t.status,
                            t.nama_kecamatan,
                            t.nama_desa,
                            t.$nama_field AS nama_utama,
                            t.alasan,
                            t.nama_pelapor AS nama_user,
                            " . ($has_id_user ? "COALESCE(u.role, '')" : "''") . " AS requester_role,
                            '{$config['jenis']}' AS jenis_layanan,
                            t.jenis_peristiwa
                           FROM `$tbl` t 
                           " . ($has_id_user ? "LEFT JOIN tb_users u ON t.id_user = u.id" : "") . "
                           WHERE t.status IN ('diajukan', 'diproses', 'selesai', 'ditolak')";
        } elseif ($tbl === 'tb_pengajuan_kia') {
            $base_query = "SELECT 
                            t.id,
                            t.created_at,
                            " . (in_array($date_column, $columns) ? "t.$date_column," : "t.created_at,") . "
                            LOWER(t.status) AS status,
                            kc.nama_kecamatan,
                            ds.nama_desa,
                            COALESCE((SELECT GROUP_CONCAT(a.nama_anak SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = t.id), t.no_kk) AS nama_utama,
                            COALESCE(NULLIF(t.alasan_penolakan, ''), t.keterangan_permohonan) AS alasan,
                            u.nama AS nama_user,
                            COALESCE(u.role, '') AS requester_role,
                            '{$config['jenis']}' AS jenis_layanan
                           FROM `tb_pengajuan_kia` t
                           LEFT JOIN tb_users u ON t.id_user = u.id
                           LEFT JOIN tb_kecamatan kc ON kc.id = t.id_kecamatan
                           LEFT JOIN tb_desa ds ON ds.id = t.id_desa
                           WHERE LOWER(t.status) IN ('diajukan', 'diproses', 'selesai', 'ditolak')";
        } else {
            // Query untuk tabel lainnya
            $base_query = "SELECT 
                            t.id,
                            t.created_at,
                            " . (in_array($date_column, $columns) ? "t.$date_column," : "t.created_at,") . "
                            t.status,
                            t.nama_kecamatan,
                            t.nama_desa,
                            t.$nama_field AS nama_utama,
                            t.alasan,
                            u.nama AS nama_user,
                            COALESCE(u.role, '') AS requester_role,
                            '{$config['jenis']}' AS jenis_layanan
                           FROM `$tbl` t 
                           LEFT JOIN tb_users u ON t.id_user = u.id 
                           WHERE t.status IN ('diajukan', 'diproses', 'selesai', 'ditolak')";
        }
        
        $where_conditions = [];
        $params = [];
        $types = '';
        $kecColExpr = ($tbl === 'tb_pengajuan_kia') ? 'kc.nama_kecamatan' : 't.nama_kecamatan';
        
        // Filter berdasarkan kecamatan
        if (!empty($allowed_kecamatan_names)) {
            if (count($allowed_kecamatan_names) < 14) { // Filter jika tidak semua kecamatan
                $placeholders = implode(',', array_fill(0, count($allowed_kecamatan_names), '?'));
                $where_conditions[] = "$kecColExpr IN ($placeholders)";
                $params = array_merge($params, $allowed_kecamatan_names);
                $types .= str_repeat('s', count($allowed_kecamatan_names));
                error_log("Filtering $tbl by kecamatan: " . implode(', ', $allowed_kecamatan_names));
            } else {
                error_log("Showing all kecamatan for $tbl");
            }
        } else {
            error_log("No kecamatan assigned, returning empty for $tbl");
            $where_conditions[] = "1 = 0"; // Return empty
        }
        
        // Filter berdasarkan jenis layanan
        if ($filter_type && $filter_type !== $config['jenis']) {
            continue; // Skip tabel jika tidak sesuai filter jenis
        }
        
        // Filter berdasarkan status
        if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
            $where_conditions[] = "LOWER(t.status) = ?";
            $params[] = $filter_status;
            $types .= 's';
        }
        
        // Filter berdasarkan rentang tanggal
        $filter_date_column = in_array($date_column, $columns) ? "t.$date_column" : "t.created_at";
        if ($filter_date_start !== '') {
            $where_conditions[] = "DATE($filter_date_column) >= ?";
            $params[] = $filter_date_start;
            $types .= 's';
        }

        if ($filter_date_end !== '') {
            $where_conditions[] = "DATE($filter_date_column) <= ?";
            $params[] = $filter_date_end;
            $types .= 's';
        }
        
        // Filter berdasarkan kecamatan spesifik (dari filter form)
        if ($filter_kecamatan && in_array($filter_kecamatan, $allowed_kecamatan_names)) {
            $where_conditions[] = "$kecColExpr = ?";
            $params[] = $filter_kecamatan;
            $types .= 's';
        }
        
        // Filter untuk mengecualikan data yang memiliki id_rs atau id_puskes
        if ($config['exclude_rs_puskes'] && $tbl !== 'tb_laporan_peristiwa') {
            $where_conditions[] = "(t.id_rs IS NULL OR t.id_rs = 0 OR t.id_rs = '') 
                                   AND (t.id_puskes IS NULL OR t.id_puskes = 0 OR t.id_puskes = '')";
        }
        
        // Bangun query
        $query = $base_query;
        if (!empty($where_conditions)) {
            $query .= " AND " . implode(" AND ", $where_conditions);
        }
        
        // Urutkan berdasarkan kolom tanggal yang tersedia
        if (in_array($date_column, $columns)) {
            $query .= " ORDER BY t.$date_column DESC";
        } else {
            $query .= " ORDER BY t.created_at DESC";
        }
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            
            $count = 0;
            while ($r = $res->fetch_assoc()) {
                // Tentukan tanggal aktivitas
                if (isset($r[$date_column]) && !empty($r[$date_column])) {
                    $r['activity_date'] = $r[$date_column];
                } elseif (isset($r['created_at']) && !empty($r['created_at'])) {
                    $r['activity_date'] = $r['created_at'];
                } else {
                    $r['activity_date'] = date('Y-m-d H:i:s');
                }
                
                // Untuk laporan peristiwa, tambahkan informasi jenis peristiwa ke nama_utama
                if ($tbl === 'tb_laporan_peristiwa' && isset($r['jenis_peristiwa'])) {
                    $jenis_peristiwa = $r['jenis_peristiwa'];
                    $jenis_text = '';
                    switch($jenis_peristiwa) {
                        case 'lahir':
                            $jenis_text = ' (Kelahiran)';
                            break;
                        case 'mati':
                            $jenis_text = ' (Kematian)';
                            break;
                        case 'rentan_adminduk':
                            $jenis_text = ' (Rentan Adminduk)';
                            break;
                    }
                    $r['nama_utama'] = ($r['nama_utama'] ?? '') . $jenis_text;
                }
                
                $all_riwayat_data[] = $r;
                $count++;
            }
            error_log("Found $count history records from $tbl");
            $stmt->close();
        } else {
            error_log("Failed to prepare statement for $tbl: " . $koneksi->error);
        }
        $check->close();
    } else {
        error_log("Table $tbl does not exist");
    }
}

// Urutkan berdasarkan tanggal terbaru
usort($all_riwayat_data, function($a, $b) {
    $dateA = $a['activity_date'] ?? $a['created_at'] ?? '1970-01-01';
    $dateB = $b['activity_date'] ?? $b['created_at'] ?? '1970-01-01';
    return strtotime($dateB) - strtotime($dateA);
});

// ====================== PAGINATION ======================
$total_records = count($all_riwayat_data);
$total_pages = ceil($total_records / $per_page);

// Batasi current_page agar tidak melebihi total_pages
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $per_page;
}

// Ambil data untuk halaman saat ini
$riwayat = array_slice($all_riwayat_data, $offset, $per_page);

// Hitung statistik untuk header (dari semua data, bukan hanya halaman saat ini)
$stats = [
    'total' => $total_records,
    'diajukan' => 0,
    'diproses' => 0,
    'selesai' => 0,
    'ditolak' => 0,
    'hari_ini' => 0
];

$today = date('Y-m-d');

foreach ($all_riwayat_data as $item) {
    $status = strtolower($item['status'] ?? '');
    if ($status === 'diajukan') {
        $stats['diajukan']++;
    } elseif ($status === 'diproses') {
        $stats['diproses']++;
    } elseif ($status === 'selesai') {
        $stats['selesai']++;
    } elseif ($status === 'ditolak') {
        $stats['ditolak']++;
    }
    
    $item_date = $item['activity_date'] ?? $item['created_at'] ?? '';
    if ($item_date && date('Y-m-d', strtotime($item_date)) === $today) {
        $stats['hari_ini']++;
    }
}

// Statistik per kecamatan (dari kecamatan tugas saja)
$kecamatan_stats = [];
foreach ($allowed_kecamatan_names as $kec) {
    $kecamatan_stats[$kec] = [
        'total' => 0,
        'diajukan' => 0,
        'diproses' => 0,
        'selesai' => 0,
        'ditolak' => 0
    ];
}

foreach ($all_riwayat_data as $item) {
    if (!empty($item['nama_kecamatan']) && in_array($item['nama_kecamatan'], $allowed_kecamatan_names)) {
        $kecamatan_stats[$item['nama_kecamatan']]['total']++;
        $status = strtolower($item['status'] ?? '');
        if ($status === 'diajukan') {
            $kecamatan_stats[$item['nama_kecamatan']]['diajukan']++;
        } elseif ($status === 'diproses') {
            $kecamatan_stats[$item['nama_kecamatan']]['diproses']++;
        } elseif ($status === 'selesai') {
            $kecamatan_stats[$item['nama_kecamatan']]['selesai']++;
        } elseif ($status === 'ditolak') {
            $kecamatan_stats[$item['nama_kecamatan']]['ditolak']++;
        }
    }
}

// Statistik per desa (gunakan kombinasi kecamatan + desa untuk hindari nama desa ganda)
$desa_stats = [];
foreach ($all_riwayat_data as $item) {
    $nama_desa = trim((string)($item['nama_desa'] ?? ''));
    $nama_kecamatan = trim((string)($item['nama_kecamatan'] ?? ''));

    if ($nama_desa === '') {
        continue;
    }

    $key = strtolower($nama_kecamatan . '|' . $nama_desa);
    $label = $nama_desa;
    if ($nama_kecamatan !== '') {
        $label .= ' (' . $nama_kecamatan . ')';
    }

    if (!isset($desa_stats[$key])) {
        $desa_stats[$key] = [
            'label' => $label,
            'total' => 0,
            'diajukan' => 0,
            'diproses' => 0,
            'selesai' => 0,
            'ditolak' => 0
        ];
    }

    $desa_stats[$key]['total']++;
    $status = strtolower((string)($item['status'] ?? ''));
    if ($status === 'diajukan') {
        $desa_stats[$key]['diajukan']++;
    } elseif ($status === 'diproses') {
        $desa_stats[$key]['diproses']++;
    } elseif ($status === 'selesai') {
        $desa_stats[$key]['selesai']++;
    } elseif ($status === 'ditolak') {
        $desa_stats[$key]['ditolak']++;
    }
}

uasort($desa_stats, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});

// Statistik sumber permohonan (berdasarkan role pemohon)
$source_stats = [
    'user' => ['label' => 'User Umum', 'total' => 0, 'selesai' => 0, 'ditolak' => 0],
    'petugas_desa' => ['label' => 'Petugas Desa', 'total' => 0, 'selesai' => 0, 'ditolak' => 0]
];

foreach ($all_riwayat_data as $item) {
    $role = strtolower(trim((string)($item['requester_role'] ?? '')));
    $source_key = in_array($role, ['petugas_desa', 'petugas desa', 'petugas-desa'], true) ? 'petugas_desa' : 'user';

    $source_stats[$source_key]['total']++;
    $status = strtolower((string)($item['status'] ?? ''));
    if ($status === 'selesai') {
        $source_stats[$source_key]['selesai']++;
    } elseif ($status === 'ditolak') {
        $source_stats[$source_key]['ditolak']++;
    }
}

// Ambil statistik per jenis layanan (DIPERBARUI dengan Laporan Peristiwa)
$jenis_stats = [
    'Kartu Keluarga' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'Kartu Identitas Anak (KIA)' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'Akta Kelahiran' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'Akta Kematian' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'Pindah Alamat' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'Pindah Masuk' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'Laporan Peristiwa' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0]
];

foreach ($all_riwayat_data as $item) {
    $jenis = $item['jenis_layanan'] ?? '';
    if (isset($jenis_stats[$jenis])) {
        $jenis_stats[$jenis]['total']++;
        $status = strtolower($item['status'] ?? '');
        if ($status === 'diajukan') {
            $jenis_stats[$jenis]['diajukan']++;
        } elseif ($status === 'diproses') {
            $jenis_stats[$jenis]['diproses']++;
        } elseif ($status === 'selesai') {
            $jenis_stats[$jenis]['selesai']++;
        } elseif ($status === 'ditolak') {
            $jenis_stats[$jenis]['ditolak']++;
        }
    }
}

// Tentukan wilayah tugas untuk ditampilkan di header
if (empty($allowed_kecamatan_names)) {
    $wilayah_tugas = 'Belum ditugaskan ke kecamatan manapun';
} elseif (count($allowed_kecamatan_names) >= 14) {
    $wilayah_tugas = 'Semua Kecamatan';
} else {
    $wilayah_tugas = implode(', ', $allowed_kecamatan_names);
    // Jika terlalu panjang, potong
    if (strlen($wilayah_tugas) > 100) {
        $wilayah_tugas = substr($wilayah_tugas, 0, 100) . '...';
    }
}

error_log("Wilayah tugas: $wilayah_tugas");
error_log("Total history records: $total_records");
error_log("Current page: $current_page, Total pages: $total_pages");
error_log("=== END RIWAYAT OPERATOR ===");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Aktivitas - Operator</title>
    <link rel="stylesheet" href="css/riwayat_operator.css?v=20261115-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator Dukcapil</div>
        <nav>
            <ul>
                <li><a href="dashboard_operator.php">Dashboard</a></li>
                <li><a href="proses_pengajuan.php">Proses Pengajuan</a></li>
                <li><a href="lapor_peristiwa_opr.php">Laporan Peristiwa</a></li>
                <li class="active">Riwayat</li>
                <li><a href="dokumen_email_belum_masuk.php">Dokumen Belum Diterima</a></li>
                <li><a href="formulir_operator.php">Formulir Kependudukan</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Riwayat Aktivitas - Operator</div>
                <div class="subtitle">Wilayah Tugas: <?= esc($wilayah_tugas) ?></div>
            </div>
            <div class="user-info">
                <span style="white-space:nowrap;">Hai, <?= esc($_SESSION['nama']) ?></span>
                <?php include __DIR__ . '/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Wilayah Tugas Info -->
            <div class="wilayah-info">
                <div class="wilayah-icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0b6e75" stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg>
                </div>
                <div class="wilayah-text">
                    <h4>Wilayah Tugas Anda</h4>
                    <p><?= esc($wilayah_tugas) ?></p>
                </div>
            </div>

            <!-- Statistik Cards -->
            <div class="stats-cards">
                <div class="stat-card total">
                    <h3>Total Ajuan</h3>
                    <div class="count"><?= $stats['total'] ?></div>
                </div>

                <div class="stat-card diproses">
                    <h3>Diproses</h3>
                    <div class="count"><?= $stats['diproses'] ?></div>
                </div>
                
                <div class="stat-card selesai">
                    <h3>Selesai</h3>
                    <div class="count"><?= $stats['selesai'] ?></div>
                </div>

                <div class="stat-card diajukan">
                    <h3>Diajukan</h3>
                    <div class="count"><?= $stats['diajukan'] ?></div>
                </div>
                
                <div class="stat-card ditolak">
                    <h3>Ditolak</h3>
                    <div class="count"><?= $stats['ditolak'] ?></div>
                </div>
                
                <div class="stat-card hari-ini">
                    <h3>Proses Hari Ini</h3>
                    <div class="count"><?= $stats['hari_ini'] ?></div>
                </div>
            </div>

            <!-- Statistik Grid -->
            <div class="stats-grid">
                <!-- Statistik Per Jenis Layanan -->
                <div class="stats-box">
                    <h3>Statistik Per Jenis Layanan</h3>
                    <div class="stats-list">
                        <?php foreach ($jenis_stats as $jenis => $jenis_data): ?>
                            <?php if ($jenis_data['total'] > 0): ?>
                                <div class="stats-item">
                                    <div class="stats-item-name"><?= esc($jenis) ?></div>
                                    <div class="stats-item-counts">
                                        <div class="stats-item-count">
                                            <div class="label">Total</div>
                                            <div class="value"><?= $jenis_data['total'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Selesai</div>
                                            <div class="value"><?= $jenis_data['selesai'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Diajukan</div>
                                            <div class="value"><?= $jenis_data['diajukan'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Diproses</div>
                                            <div class="value"><?= $jenis_data['diproses'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Ditolak</div>
                                            <div class="value"><?= $jenis_data['ditolak'] ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Statistik Per Kecamatan (dari wilayah tugas) -->
                <div class="stats-box">
                    <h3>Statistik Per Kecamatan</h3>
                    <div class="stats-list">
                        <?php 
                        // Urutkan kecamatan berdasarkan total
                        uasort($kecamatan_stats, function($a, $b) {
                            return $b['total'] - $a['total'];
                        });
                        
                        $counter = 0;
                        foreach ($kecamatan_stats as $kecamatan => $kec_data): 
                            $counter++;
                        ?>
                            <div class="stats-item">
                                <div class="stats-item-name"><?= esc($kecamatan) ?></div>
                                <div class="stats-item-counts">
                                    <div class="stats-item-count">
                                        <div class="label">Total</div>
                                        <div class="value"><?= $kec_data['total'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Selesai</div>
                                        <div class="value"><?= $kec_data['selesai'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Diajukan</div>
                                        <div class="value"><?= $kec_data['diajukan'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Diproses</div>
                                        <div class="value"><?= $kec_data['diproses'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Ditolak</div>
                                        <div class="value"><?= $kec_data['ditolak'] ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; 
                        
                        if ($counter === 0): ?>
                            <div class="empty-state" style="padding: 20px 0;">
                                <p>Tidak ada data kecamatan</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistik Per Desa -->
                <div class="stats-box">
                    <h3>Statistik Per Desa</h3>
                    <div class="stats-list">
                        <?php if (!empty($desa_stats)): ?>
                            <?php foreach ($desa_stats as $desa_data): ?>
                                <div class="stats-item">
                                    <div class="stats-item-name"><?= esc($desa_data['label']) ?></div>
                                    <div class="stats-item-counts">
                                        <div class="stats-item-count">
                                            <div class="label">Total</div>
                                            <div class="value"><?= $desa_data['total'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Selesai</div>
                                            <div class="value"><?= $desa_data['selesai'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Diajukan</div>
                                            <div class="value"><?= $desa_data['diajukan'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Diproses</div>
                                            <div class="value"><?= $desa_data['diproses'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Ditolak</div>
                                            <div class="value"><?= $desa_data['ditolak'] ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 20px 0;">
                                <p>Tidak ada data desa</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistik Sumber Permohonan -->
                <div class="stats-box">
                    <h3>Statistik Sumber Permohonan</h3>
                    <div class="stats-list">
                        <?php foreach ($source_stats as $src_data): ?>
                            <?php if ($src_data['total'] > 0): ?>
                                <div class="stats-item">
                                    <div class="stats-item-name"><?= esc($src_data['label']) ?></div>
                                    <div class="stats-item-counts">
                                        <div class="stats-item-count">
                                            <div class="label">Total</div>
                                            <div class="value"><?= $src_data['total'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Selesai</div>
                                            <div class="value"><?= $src_data['selesai'] ?></div>
                                        </div>
                                        <div class="stats-item-count">
                                            <div class="label">Ditolak</div>
                                            <div class="value"><?= $src_data['ditolak'] ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3>Filter Riwayat</h3>
                <form method="GET" class="filters">
                    <input type="hidden" name="page" value="1">
                    <div class="filter-group">
                        <label for="type">Jenis Layanan</label>
                        <select name="type" id="type">
                            <option value="">Semua Jenis</option>
                            <option value="Kartu Keluarga" <?= $filter_type === 'Kartu Keluarga' ? 'selected' : '' ?>>Kartu Keluarga</option>
                            <option value="Akta Kelahiran" <?= $filter_type === 'Akta Kelahiran' ? 'selected' : '' ?>>Akta Kelahiran</option>
                            <option value="Akta Kematian" <?= $filter_type === 'Akta Kematian' ? 'selected' : '' ?>>Akta Kematian</option>
                            <option value="Pindah Alamat" <?= $filter_type === 'Pindah Alamat' ? 'selected' : '' ?>>Pindah Alamat</option>
                            <option value="Pindah Masuk" <?= $filter_type === 'Pindah Masuk' ? 'selected' : '' ?>>Pindah Masuk</option>
                            <!-- DITAMBAH: Laporan Peristiwa -->
                            <option value="Laporan Peristiwa" <?= $filter_type === 'Laporan Peristiwa' ? 'selected' : '' ?>>Laporan Peristiwa</option>
                            <option value="Kartu Identitas Anak (KIA)" <?= $filter_type === 'Kartu Identitas Anak (KIA)' ? 'selected' : '' ?>>Kartu Identitas Anak (KIA)</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <option value="">Semua Status</option>
                            <option value="diajukan" <?= $filter_status === 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                            <option value="diproses" <?= $filter_status === 'diproses' ? 'selected' : '' ?>>Diproses</option>
                            <option value="selesai" <?= $filter_status === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                            <option value="ditolak" <?= $filter_status === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="kecamatan">Kecamatan</label>
                        <select name="kecamatan" id="kecamatan">
                            <option value="">Semua Kecamatan</option>
                            <?php foreach ($allowed_kecamatan_names as $kec): ?>
                                <option value="<?= esc($kec) ?>" <?= $filter_kecamatan === $kec ? 'selected' : '' ?>>
                                    <?= esc($kec) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_start">Dari Tanggal</label>
                        <input type="date" name="date_start" id="date_start" value="<?= esc($filter_date_start) ?>">
                    </div>

                    <div class="filter-group">
                        <label for="date_end">Sampai Tanggal</label>
                        <input type="date" name="date_end" id="date_end" value="<?= esc($filter_date_end) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <button type="submit" class="btn-filter">Terapkan Filter</button>
                    </div>
                    
                    <div class="filter-group">
                        <a href="riwayat_operator.php" class="btn-reset">Reset Filter</a>
                    </div>
                </form>
            </div>

            <!-- View Toggle -->
            <div class="view-toggle">
                <button class="view-btn active" onclick="switchView('table')">Tabel</button>
                <button class="view-btn" onclick="switchView('timeline')">Timeline</button>
            </div>

            <!-- Tabel View -->
            <div id="tableView" class="table-section">
                <h2>
                    Daftar Riwayat Aktivitas
                    <span class="table-count">Total: <?= $total_records ?> data | Halaman <?= $current_page ?> dari <?= $total_pages ?></span>
                </h2>
                
                <?php if (empty($riwayat)): ?>
                    <div class="empty-state">
                        <i>📭</i>
                        <h4>Tidak ada riwayat ditemukan</h4>
                        <p>Tidak ada aktivitas yang tercatat dengan filter yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Jenis Layanan</th>
                                <th>Nama Pemohon</th>
                                <th>Kecamatan</th>
                                <th>Desa</th>
                                <th>Status</th>
                                <th>Alasan/Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = ($current_page - 1) * $per_page + 1;
                            foreach ($riwayat as $item): 
                                // Tentukan kelas badge berdasarkan jenis layanan
                                $jenis_class = match($item['jenis_layanan']) {
                                    'Kartu Keluarga' => 'jenis-kk',
                                    'Akta Kelahiran' => 'jenis-akta-lahir',
                                    'Akta Kematian' => 'jenis-akta-mati',
                                    'Pindah Alamat' => 'jenis-pindah-alamat',
                                    'Pindah Masuk' => 'jenis-pindah-masuk',
                                    'Laporan Peristiwa' => 'jenis-laporan-peristiwa',
                                    default => ''
                                };
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td>
                                        <?php 
                                        $datetime = $item['activity_date'] ?? $item['created_at'] ?? '';
                                        if ($datetime && $datetime != '1970-01-01') {
                                            echo date('d M Y H:i', strtotime($datetime));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="jenis-badge <?= $jenis_class ?>">
                                            <?= esc($item['jenis_layanan']) ?>
                                        </span>
                                    </td>
                                    <td><?= esc($item['nama_user'] ?? $item['nama_utama'] ?? '-') ?></td>
                                    <td><?= esc($item['nama_kecamatan'] ?? '-') ?></td>
                                    <td><?= esc($item['nama_desa'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $status = strtolower($item['status'] ?? '');
                                        $badge_class = match($status) {
                                            'selesai' => 'status-selesai',
                                            'ditolak' => 'status-ditolak',
                                            'diproses' => 'status-diproses',
                                            default => 'status-log'
                                        };
                                        ?>
                                        <span class="status-badge <?= $badge_class ?>">
                                            <?= ucfirst($item['status'] ?? 'Aktivitas') ?>
                                        </span>
                                    </td>
                                    <td><?= esc($item['alasan'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-container">
                        <div class="pagination-info">
                            Menampilkan <?= count($riwayat) ?> dari <?= $total_records ?> data
                            (Halaman <?= $current_page ?> dari <?= $total_pages ?>)
                        </div>
                        
                        <div class="pagination-links">
                            <?php 
                            // Tombol Previous
                            if ($current_page > 1): 
                                $prev_params = http_build_query(array_merge($_GET, ['page' => $current_page - 1]));
                            ?>
                                <a href="?<?= $prev_params ?>" class="pagination-link">← Prev</a>
                            <?php else: ?>
                                <span class="pagination-link disabled">← Prev</span>
                            <?php endif; ?>
                            
                            <?php 
                            // Tampilkan 5 halaman di sekitar current page
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            // Jika di awal, tambah halaman di akhir
                            if ($start_page == 1) {
                                $end_page = min($total_pages, $start_page + 4);
                            }
                            
                            // Jika di akhir, tambah halaman di awal
                            if ($end_page == $total_pages) {
                                $start_page = max(1, $end_page - 4);
                            }
                            
                            // Tampilkan halaman pertama jika tidak termasuk
                            if ($start_page > 1): 
                                $first_params = http_build_query(array_merge($_GET, ['page' => 1]));
                            ?>
                                <a href="?<?= $first_params ?>" class="pagination-link">1</a>
                                <?php if ($start_page > 2): ?>
                                    <span class="pagination-link disabled">...</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): 
                                $page_params = http_build_query(array_merge($_GET, ['page' => $i]));
                            ?>
                                <a href="?<?= $page_params ?>" class="pagination-link <?= $i == $current_page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php 
                            // Tampilkan halaman terakhir jika tidak termasuk
                            if ($end_page < $total_pages): 
                                $last_params = http_build_query(array_merge($_GET, ['page' => $total_pages]));
                            ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span class="pagination-link disabled">...</span>
                                <?php endif; ?>
                                <a href="?<?= $last_params ?>" class="pagination-link"><?= $total_pages ?></a>
                            <?php endif; ?>
                            
                            <?php 
                            // Tombol Next
                            if ($current_page < $total_pages): 
                                $next_params = http_build_query(array_merge($_GET, ['page' => $current_page + 1]));
                            ?>
                                <a href="?<?= $next_params ?>" class="pagination-link">Next →</a>
                            <?php else: ?>
                                <span class="pagination-link disabled">Next →</span>
                            <?php endif; ?>
                            
                            <!-- Input Lompat ke Halaman -->
                            <div class="page-input-group">
                                <input type="number" id="goToPage" class="page-input" 
                                       min="1" max="<?= $total_pages ?>" 
                                       value="<?= $current_page ?>"
                                       onkeypress="if(event.key === 'Enter') goToPage()">
                                <button type="button" class="btn-go" onclick="goToPage()">Go</button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Timeline View (Hidden by default) -->
            <div id="timelineView" class="table-section" style="display: none;">
                <h2>
                    Timeline Aktivitas
                    <span class="table-count">Total: <?= $total_records ?> data | Halaman <?= $current_page ?> dari <?= $total_pages ?></span>
                </h2>
                
                <?php if (empty($riwayat)): ?>
                    <div class="empty-state">
                        <i></i>
                        <h4>Tidak ada riwayat ditemukan</h4>
                        <p>Tidak ada aktivitas yang tercatat dengan filter yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="activity-timeline">
                        <?php 
                        $start_no = ($current_page - 1) * $per_page + 1;
                        $no = $start_no;
                        foreach ($riwayat as $item): 
                            $jenis_class = match($item['jenis_layanan']) {
                                'Kartu Keluarga' => 'jenis-kk',
                                'Akta Kelahiran' => 'jenis-akta-lahir',
                                'Akta Kematian' => 'jenis-akta-mati',
                                'Pindah Alamat' => 'jenis-pindah-alamat',
                                'Pindah Masuk' => 'jenis-pindah-masuk',
                                'Laporan Peristiwa' => 'jenis-laporan-peristiwa',
                                default => ''
                            };
                        ?>
                            <div class="activity-item">
                                <div class="activity-header">
                                    <div class="activity-title">
                                        <?= $no++ ?>. 
                                        <span class="jenis-badge <?= $jenis_class ?>">
                                            <?= esc($item['jenis_layanan']) ?>
                                        </span>
                                        - <?= esc($item['nama_user'] ?? $item['nama_utama'] ?? 'Pemohon') ?>
                                    </div>
                                    <div class="activity-time">
                                        <?php 
                                        $datetime = $item['activity_date'] ?? $item['created_at'] ?? '';
                                        if ($datetime && $datetime != '1970-01-01') {
                                            echo date('d M Y H:i', strtotime($datetime));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </div>
                                </div>
                                
                                <div class="activity-details">
                                    <?php
                                    $status = strtolower($item['status'] ?? '');
                                    $badge_class = match($status) {
                                        'selesai' => 'status-selesai',
                                        'ditolak' => 'status-ditolak',
                                        'diproses' => 'status-diproses',
                                        default => 'status-log'
                                    };
                                    ?>
                                    <span class="status-badge <?= $badge_class ?>">
                                        <?= ucfirst($item['status'] ?? 'Aktivitas') ?>
                                    </span>
                                    
                                    <?php if (!empty($item['alasan'])): ?>
                                        <p style="margin-top: 8px; margin-bottom: 0;"><?= esc($item['alasan']) ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($item['nama_kecamatan']) || !empty($item['nama_desa'])): ?>
                                    <div class="activity-meta">
                                        <?php if (!empty($item['nama_kecamatan'])): ?>
                                            <span>📍 <?= esc($item['nama_kecamatan']) ?></span>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($item['nama_desa'])): ?>
                                            <span>🏠 <?= esc($item['nama_desa']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3>Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
            <button id="cancelLogout" class="btn-cancel">Batal</button>
        </div>
    </div>
</div>

<script>
// Toggle Sidebar Menu on Mobile
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && 
                    !menuToggle.contains(event.target) && 
                    sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            }
        });
    }
    
    // Logout Modal
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');
    
    function showLogoutModal() {
        logoutModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function hideLogoutModal() {
        logoutModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    if (logoutBtn) {
        logoutBtn.addEventListener('click', showLogoutModal);
    }
    
    if (logoutBtn2) {
        logoutBtn2.addEventListener('click', showLogoutModal);
    }
    
    if (confirmLogout) {
        confirmLogout.addEventListener('click', function() {
            window.location.href = '../logout.php';
        });
    }
    
    if (cancelLogout) {
        cancelLogout.addEventListener('click', hideLogoutModal);
    }
    
    // Close modal when clicking outside
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) {
                hideLogoutModal();
            }
        });
    }
    
    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
});

// Switch View Function
function switchView(viewType) {
    const tableView = document.getElementById('tableView');
    const timelineView = document.getElementById('timelineView');
    const tableBtn = document.querySelector('.view-btn[onclick*="table"]');
    const timelineBtn = document.querySelector('.view-btn[onclick*="timeline"]');
    
    if (viewType === 'table') {
        tableView.style.display = 'block';
        timelineView.style.display = 'none';
        tableBtn.classList.add('active');
        timelineBtn.classList.remove('active');
    } else {
        tableView.style.display = 'none';
        timelineView.style.display = 'block';
        tableBtn.classList.remove('active');
        timelineBtn.classList.add('active');
    }
}

// Function untuk lompat ke halaman tertentu
window.goToPage = function() {
    const pageInput = document.getElementById('goToPage');
    let page = parseInt(pageInput.value);
    
    // Validasi input
    if (isNaN(page) || page < 1) {
        page = 1;
    } else if (page > <?= $total_pages ?>) {
        page = <?= $total_pages ?>;
    }
    
    // Redirect ke halaman yang dipilih
    const params = new URLSearchParams(window.location.search);
    params.set('page', page);
    window.location.href = '?' + params.toString();
};

// Reset page ke 1 ketika filter berubah
['type', 'status', 'kecamatan', 'date_start', 'date_end'].forEach(function(filterId) {
    const filterElement = document.getElementById(filterId);
    if (filterElement) {
        filterElement.addEventListener('change', function() {
            document.querySelector('input[name="page"]').value = 1;
        });
    }
});
</script>

</body>
</html>
