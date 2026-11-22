<?php
require_once '../error_handler.php';
// Include middleware untuk validasi akses operator rumah sakit
include '../../app/middleware/operator_rs_only.php';
include '../../app/config/koneksi.php';

// Fungsi helper untuk escape output
function esc($string) {
    return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
}

// ====================== PAGINATION SETTINGS ======================
$per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;
$total_records = 0;
$total_pages = 0;

// ============ AMBIL SEMUA RS YANG DITUGASKAN KE OPERATOR ============
$assigned_rs_ids = [];
$assigned_rs_data = [];
$rs_list_for_display = '';

// Gunakan data RS dari session yang sudah diverifikasi oleh middleware
if (isset($_SESSION['operator_rs']) && is_array($_SESSION['operator_rs'])) {
    foreach ($_SESSION['operator_rs'] as $rs) {
        $assigned_rs_ids[] = $rs['id_rs'];
        $assigned_rs_data[] = $rs;
    }
    error_log("Using RS data from session: " . count($assigned_rs_ids) . " RS found");
} else {
    // Fallback: Ambil data dari database (untuk kompatibilitas)
    $sql_direct_rs = "SELECT rs_id FROM tb_operator_kecamatan 
                      WHERE operator_id = ? AND rs_id IS NOT NULL";
    $stmt_direct = $koneksi->prepare($sql_direct_rs);
    if ($stmt_direct) {
        $stmt_direct->bind_param("i", $_SESSION['id_user']);
        $stmt_direct->execute();
        $result_direct = $stmt_direct->get_result();
        while ($row = $result_direct->fetch_assoc()) {
            $assigned_rs_ids[] = $row['rs_id'];
        }
        $stmt_direct->close();
    }
    
    // Ambil detail RS
    if (!empty($assigned_rs_ids)) {
        $placeholders = implode(',', array_fill(0, count($assigned_rs_ids), '?'));
        $sql_rs_detail = "SELECT id_rs, nama_rs FROM tb_rumahsakit 
                          WHERE id_rs IN ($placeholders) 
                          ORDER BY nama_rs";
        
        $stmt_detail = $koneksi->prepare($sql_rs_detail);
        if ($stmt_detail) {
            $types = str_repeat('i', count($assigned_rs_ids));
            $stmt_detail->bind_param($types, ...$assigned_rs_ids);
            $stmt_detail->execute();
            $result_detail = $stmt_detail->get_result();
            
            while ($row = $result_detail->fetch_assoc()) {
                $assigned_rs_data[] = $row;
                error_log("RS detail: " . $row['nama_rs'] . " (ID: " . $row['id_rs'] . ")");
            }
            $stmt_detail->close();
        }
    }
}

// Buat string untuk menampilkan daftar rumah sakit
if (!empty($assigned_rs_data)) {
    $total_rs_assigned = count($assigned_rs_data);
    error_log("Total RS assigned: $total_rs_assigned");
    
    $rs_names = array_map(function($rs) {
        return $rs['nama_rs'];
    }, $assigned_rs_data);
    
    $rs_list_for_display = implode(', ', $rs_names);
}

// Tentukan wilayah tugas untuk ditampilkan di header
$wilayah_tugas = '';
$wilayah_tugas_html = ''; // Untuk versi HTML dengan format lebih baik
if (!empty($assigned_rs_data)) {
    $total_rs_assigned = count($assigned_rs_data);
    
    if ($total_rs_assigned === 1) {
        $rs = $assigned_rs_data[0];
        $wilayah_tugas = $rs['nama_rs'];
    } else if ($total_rs_assigned >= 5) {
        // Jika wilayah tugas = 5 rumah sakit atau lebih, tulis semua rumah sakit
        // Format: "5 Rumah Sakit di Kabupaten Gorontalo" kemudian list semua nama RS
        $wilayah_tugas = $total_rs_assigned . ' Rumah Sakit: ' . $rs_list_for_display;
        
        // Buat versi HTML dengan pemisah yang lebih baik untuk multiple hospitals
        $rs_html_list = [];
        foreach ($assigned_rs_data as $rs) {
            $rs_html_list[] = $rs['nama_rs'];
        }
        $wilayah_tugas_html = $total_rs_assigned . ' Rumah Sakit:<br>' . 
                              '<small style="color: #666;">' . 
                              implode(', ', $rs_html_list) . 
                              '</small>';
    } else {
        $wilayah_tugas = $total_rs_assigned . ' Rumah Sakit: ' . $rs_list_for_display;
    }
} else {
    $wilayah_tugas = 'Tidak ada RS ditugaskan';
    error_log("Operator ID " . $_SESSION['id_user'] . " tidak memiliki RS terkait");
}

error_log("Wilayah tugas: $wilayah_tugas");

// === Filter ===
$filter_status = $_GET['status'] ?? '';
$filter_date = $_GET['date'] ?? '';
$filter_rs = $_GET['rs'] ?? '';
$filter_type = $_GET['type'] ?? ''; // 'kelahiran' atau 'kematian'

$riwayat = [];
$all_riwayat_data = [];

// Ambil data riwayat dari kedua tabel (tb_akta_kelahiran_rs dan tb_akta_kematian_rs)
if (!empty($assigned_rs_ids)) {
    // 1. Data dari tb_akta_kelahiran_rs
    if ($filter_type === '' || $filter_type === 'kelahiran') {
        $placeholders = implode(',', array_fill(0, count($assigned_rs_ids), '?'));
        $base_query_kelahiran = "SELECT 
                        ak.*,
                        ak.created_at,
                        ak.updated_at,
                        ak.status,
                        ak.nama_kecamatan,
                        ak.nama_desa,
                        ak.nama_bayi AS nama_utama,
                        ak.nama_ayah,
                        ak.nama_ibu,
                        ak.alasan,
                        ak.tempat_lahir,
                        ak.tanggal_lahir,
                        ak.jenis_kelamin,
                        ak.urutan_kelahiran,
                        ak.Waktu AS waktu_lahir,
                        u.nama AS nama_user,
                        rs.nama_rs,
                        'Akta Kelahiran' AS jenis_layanan
                       FROM tb_akta_kelahiran_rs ak
                       LEFT JOIN tb_users u ON ak.id_user = u.id
                       LEFT JOIN tb_rumahsakit rs ON ak.id_rs = rs.id_rs
                       WHERE ak.status IN ('selesai', 'ditolak')
                       AND ak.id_rs IN ($placeholders)";
        
        $where_conditions = [];
        $params = $assigned_rs_ids;
        $types = str_repeat('i', count($assigned_rs_ids));
        
        // Filter berdasarkan status
        if ($filter_status && in_array($filter_status, ['selesai', 'ditolak'])) {
            $where_conditions[] = "ak.status = ?";
            $params[] = $filter_status;
            $types .= 's';
        }
        
        // Filter berdasarkan tanggal
        if ($filter_date) {
            $where_conditions[] = "DATE(ak.updated_at) = ?";
            $params[] = $filter_date;
            $types .= 's';
        }
        
        // Filter berdasarkan RS spesifik
        if ($filter_rs && in_array($filter_rs, array_column($assigned_rs_data, 'id_rs'))) {
            $where_conditions[] = "ak.id_rs = ?";
            $params[] = $filter_rs;
            $types .= 'i';
        }
        
        // Bangun query
        $query_kelahiran = $base_query_kelahiran;
        if (!empty($where_conditions)) {
            $query_kelahiran .= " AND " . implode(" AND ", $where_conditions);
        }
        
        $query_kelahiran .= " ORDER BY ak.updated_at DESC";
        
        $stmt = $koneksi->prepare($query_kelahiran);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            
            $count = 0;
            while ($r = $res->fetch_assoc()) {
                // Tentukan tanggal aktivitas (gunakan updated_at jika ada)
                if (isset($r['updated_at']) && !empty($r['updated_at'])) {
                    $r['activity_date'] = $r['updated_at'];
                } else {
                    $r['activity_date'] = $r['created_at'] ?? date('Y-m-d H:i:s');
                }
                $all_riwayat_data[] = $r;
                $count++;
            }
            error_log("Found $count history records from tb_akta_kelahiran_rs");
            $stmt->close();
        } else {
            error_log("Failed to prepare statement for kelahiran: " . $koneksi->error);
        }
    }
    
    // 2. Data dari tb_akta_kematian_rs
    if ($filter_type === '' || $filter_type === 'kematian') {
        $placeholders = implode(',', array_fill(0, count($assigned_rs_ids), '?'));
        $base_query_kematian = "SELECT 
                        ak.*,
                        ak.created_at,
                        ak.updated_at,
                        ak.status,
                        ak.nama_kecamatan,
                        ak.nama_desa,
                        ak.nama_meninggal AS nama_utama,
                        ak.nama_ayah,
                        ak.nama_ibu,
                        ak.alasan,
                        ak.tanggal_kematian,
                        ak.tempat AS tempat_kematian,
                        u.nama AS nama_user,
                        rs.nama_rs,
                        'Akta Kematian' AS jenis_layanan
                       FROM tb_akta_kematian_rs ak
                       LEFT JOIN tb_users u ON ak.id_user = u.id
                       LEFT JOIN tb_rumahsakit rs ON ak.id_rs = rs.id_rs
                       WHERE ak.status IN ('selesai', 'ditolak')
                       AND ak.id_rs IN ($placeholders)";
        
        $where_conditions = [];
        $params = $assigned_rs_ids;
        $types = str_repeat('i', count($assigned_rs_ids));
        
        // Filter berdasarkan status
        if ($filter_status && in_array($filter_status, ['selesai', 'ditolak'])) {
            $where_conditions[] = "ak.status = ?";
            $params[] = $filter_status;
            $types .= 's';
        }
        
        // Filter berdasarkan tanggal
        if ($filter_date) {
            $where_conditions[] = "DATE(ak.updated_at) = ?";
            $params[] = $filter_date;
            $types .= 's';
        }
        
        // Filter berdasarkan RS spesifik
        if ($filter_rs && in_array($filter_rs, array_column($assigned_rs_data, 'id_rs'))) {
            $where_conditions[] = "ak.id_rs = ?";
            $params[] = $filter_rs;
            $types .= 'i';
        }
        
        // Bangun query
        $query_kematian = $base_query_kematian;
        if (!empty($where_conditions)) {
            $query_kematian .= " AND " . implode(" AND ", $where_conditions);
        }
        
        $query_kematian .= " ORDER BY ak.updated_at DESC";
        
        $stmt = $koneksi->prepare($query_kematian);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            
            $count = 0;
            while ($r = $res->fetch_assoc()) {
                // Tentukan tanggal aktivitas (gunakan updated_at jika ada)
                if (isset($r['updated_at']) && !empty($r['updated_at'])) {
                    $r['activity_date'] = $r['updated_at'];
                } else {
                    $r['activity_date'] = $r['created_at'] ?? date('Y-m-d H:i:s');
                }
                $all_riwayat_data[] = $r;
                $count++;
            }
            error_log("Found $count history records from tb_akta_kematian_rs");
            $stmt->close();
        } else {
            error_log("Failed to prepare statement for kematian: " . $koneksi->error);
        }
    }
}

// Urutkan semua data berdasarkan tanggal aktivitas (terbaru dulu)
usort($all_riwayat_data, function($a, $b) {
    $dateA = strtotime($a['activity_date'] ?? '');
    $dateB = strtotime($b['activity_date'] ?? '');
    return $dateB - $dateA;
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

// Hitung statistik untuk header
$stats = [
    'total' => $total_records,
    'selesai' => 0,
    'ditolak' => 0,
    'hari_ini' => 0,
    'kelahiran' => 0,
    'kematian' => 0
];

$today = date('Y-m-d');

foreach ($all_riwayat_data as $item) {
    $status = strtolower($item['status'] ?? '');
    if ($status === 'selesai') {
        $stats['selesai']++;
    } elseif ($status === 'ditolak') {
        $stats['ditolak']++;
    }
    
    $jenis = strtolower($item['jenis_layanan'] ?? '');
    if (strpos($jenis, 'kelahiran') !== false) {
        $stats['kelahiran']++;
    } elseif (strpos($jenis, 'kematian') !== false) {
        $stats['kematian']++;
    }
    
    $item_date = $item['activity_date'] ?? $item['updated_at'] ?? $item['created_at'] ?? '';
    if ($item_date && date('Y-m-d', strtotime($item_date)) === $today) {
        $stats['hari_ini']++;
    }
}

// Statistik per rumah sakit
$rs_stats = [];
foreach ($assigned_rs_data as $rs) {
    $rs_stats[$rs['nama_rs']] = [
        'total' => 0,
        'selesai' => 0,
        'ditolak' => 0,
        'kelahiran' => 0,
        'kematian' => 0,
        'id_rs' => $rs['id_rs']
    ];
}

foreach ($all_riwayat_data as $item) {
    $rs_name = $item['nama_rs'] ?? '';
    if (!empty($rs_name) && isset($rs_stats[$rs_name])) {
        $rs_stats[$rs_name]['total']++;
        $status = strtolower($item['status'] ?? '');
        if ($status === 'selesai') {
            $rs_stats[$rs_name]['selesai']++;
        } elseif ($status === 'ditolak') {
            $rs_stats[$rs_name]['ditolak']++;
        }
        
        $jenis = strtolower($item['jenis_layanan'] ?? '');
        if (strpos($jenis, 'kelahiran') !== false) {
            $rs_stats[$rs_name]['kelahiran']++;
        } elseif (strpos($jenis, 'kematian') !== false) {
            $rs_stats[$rs_name]['kematian']++;
        }
    }
}

error_log("Total history records: $total_records");
error_log("Current page: $current_page, Total pages: $total_pages");
error_log("=== END RS RIWAYAT OPERATOR ===");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Akta Kelahiran & Kematian - Operator RS</title>
    <link rel="icon" href="../assets/st sehati.png" type="image/png">
    <link rel="stylesheet" href="css/rs_riwayat_operator.css">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator Dukcapil <br> ST SEHATI</div>
        <nav>
            <ul>
                <li><a href="rs_dashboard_operator.php">Dashboard</a></li>
                <li><a href="rs_proses_pengajuan.php">Proses Pengajuan</a></li>
                <li class="active"><a href="rs_riwayat_operator.php">Laporan</a></li>
                <li><a href="rs_dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Riwayat Proses Ajuan - Operator ST SEHATI</div>
                <div class="subtitle">
                    Wilayah Tugas: 
                    <?php if (!empty($wilayah_tugas_html)): ?>
                        <?= $wilayah_tugas_html ?>
                    <?php else: ?>
                        <?= esc($wilayah_tugas) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama']) ?></span>
                <span class="role-badge">OPERATOR RS</span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- RS Info Box -->
            <?php if (!empty($assigned_rs_data)): ?>
            <div class="rs-info-box">
                <div class="rs-info-header">
                    <div class="rs-info-icon">🏥</div>
                    <div class="rs-info-content">
                        <h3>Informasi Wilayah Tugas</h3>
                        <p>
                            Anda bertanggung jawab atas <?= count($assigned_rs_data) ?> Rumah Sakit: <?= esc($rs_list_for_display) ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="rs-info-box" style="border-left-color: #e74c3c;">
                <div class="rs-info-header">
                    <div class="rs-info-icon">⚠️</div>
                    <div class="rs-info-content">
                        <h3>Informasi Rumah Sakit Tugas</h3>
                        <p>Anda belum ditugaskan ke rumah sakit manapun. Hubungi administrator.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Statistik Cards -->
            <div class="stats-cards">
                <div class="stat-card total">
                    <h3>Total Proses Ajuan</h3>
                    <div class="count"><?= $stats['total'] ?></div>
                </div>
                
                <div class="stat-card selesai">
                    <h3>Selesai</h3>
                    <div class="count"><?= $stats['selesai'] ?></div>
                </div>
                
                <div class="stat-card ditolak">
                    <h3>Ditolak</h3>
                    <div class="count"><?= $stats['ditolak'] ?></div>
                </div>
                
                <div class="stat-card hari-ini">
                    <h3>Proses Hari Ini</h3>
                    <div class="count"><?= $stats['hari_ini'] ?></div>
                </div>
                
                <div class="stat-card kelahiran">
                    <h3>Kelahiran</h3>
                    <div class="count"><?= $stats['kelahiran'] ?></div>
                </div>
                
                <div class="stat-card kematian">
                    <h3>Kematian</h3>
                    <div class="count"><?= $stats['kematian'] ?></div>
                </div>
            </div>

            <!-- Statistik Grid -->
            <div class="stats-grid">
                <!-- Statistik Per Rumah Sakit -->
                <div class="stats-box">
                    <h3>Statistik Per Rumah Sakit</h3>
                    <div class="stats-list">
                        <?php 
                        // Urutkan RS berdasarkan total
                        uasort($rs_stats, function($a, $b) {
                            return $b['total'] - $a['total'];
                        });
                        
                        $counter = 0;
                        foreach ($rs_stats as $rs_name => $rs_data): 
                            $counter++;
                        ?>
                            <div class="stats-item">
                                <div class="stats-item-name"><?= esc($rs_name) ?></div>
                                <div class="stats-item-counts">
                                    <div class="stats-item-count">
                                        <div class="label">Total</div>
                                        <div class="value"><?= $rs_data['total'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Selesai</div>
                                        <div class="value"><?= $rs_data['selesai'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Ditolak</div>
                                        <div class="value"><?= $rs_data['ditolak'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Kelahiran</div>
                                        <div class="value"><?= $rs_data['kelahiran'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Kematian</div>
                                        <div class="value"><?= $rs_data['kematian'] ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; 
                        
                        if ($counter === 0): ?>
                            <div class="empty-state" style="padding: 20px 0;">
                                <p>Tidak ada data riwayat ditemukan</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="filters">
                    <input type="hidden" name="page" value="1">
                    
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <option value="">Semua Status</option>
                            <option value="selesai" <?= $filter_status === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                            <option value="ditolak" <?= $filter_status === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                    </div>
                    
                    <?php if (!empty($assigned_rs_data) && count($assigned_rs_data) > 1): ?>
                    <div class="filter-group">
                        <label for="rs">Rumah Sakit</label>
                        <select name="rs" id="rs">
                            <option value="">Semua Rumah Sakit</option>
                            <?php foreach ($assigned_rs_data as $rs): ?>
                                <option value="<?= esc($rs['id_rs']) ?>" <?= $filter_rs == $rs['id_rs'] ? 'selected' : '' ?>>
                                    <?= esc($rs['nama_rs']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="filter-group">
                        <label for="type">Jenis Layanan</label>
                        <select name="type" id="type">
                            <option value="">Semua Layanan</option>
                            <option value="kelahiran" <?= $filter_type === 'kelahiran' ? 'selected' : '' ?>>Akta Kelahiran</option>
                            <option value="kematian" <?= $filter_type === 'kematian' ? 'selected' : '' ?>>Akta Kematian</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date">Tanggal</label>
                        <input type="date" name="date" id="date" value="<?= esc($filter_date) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <button type="submit" class="btn-filter">Terapkan Filter</button>
                    </div>
                    
                    <div class="filter-group">
                        <a href="rs_riwayat_operator.php" class="btn-reset">Reset Filter</a>
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
                    Daftar Riwayat Akta Kelahiran & Kematian
                    <span class="table-count">Total: <?= $total_records ?> data</span>
                </h2>
                
                <?php if (empty($riwayat)): ?>
                    <div class="empty-state">
                        <i>📭</i>
                        <h4>Tidak ada riwayat ditemukan</h4>
                        <p>Tidak ada riwayat yang tercatat dengan filter yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Jenis Layanan</th>
                                <th>Nama Pemohon</th>
                                <th>Rumah Sakit</th>
                                <th>Nama Subjek</th>
                                <th>Status</th>
                                <th>Alasan/Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = ($current_page - 1) * $per_page + 1;
                            foreach ($riwayat as $item): 
                                $is_kelahiran = strpos(strtolower($item['jenis_layanan'] ?? ''), 'kelahiran') !== false;
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td>
                                        <?php 
                                        $datetime = $item['activity_date'] ?? $item['updated_at'] ?? $item['created_at'] ?? '';
                                        if ($datetime && $datetime != '1970-01-01') {
                                            echo date('d M Y H:i', strtotime($datetime));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="jenis-badge <?= $is_kelahiran ? 'jenis-kelahiran' : 'jenis-kematian' ?>">
                                            <?= esc($item['jenis_layanan']) ?>
                                        </span>
                                    </td>
                                    <td><?= esc($item['nama_user'] ?? $item['nama_pemohon'] ?? '-') ?></td>
                                    <td>
                                        <?php if (!empty($item['nama_rs'])): ?>
                                            <strong><?= esc($item['nama_rs']) ?></strong>
                                        <?php else: ?>
                                            <em style="color: #e74c3c;">Tidak ada RS</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= esc($item['nama_utama'] ?? '-') ?></strong>
                                        <br>
                                        <small>
                                            <?php if ($is_kelahiran): ?>
                                                Lahir: <?= esc($item['tempat_lahir'] ?? '-') ?>, 
                                                <?= $item['tanggal_lahir'] ? date('d/m/Y', strtotime($item['tanggal_lahir'])) : '-' ?>
                                                <?= !empty($item['waktu_lahir']) ? ' (' . esc($item['waktu_lahir']) . ')' : '' ?>
                                                <br>
                                                Jenis Kelamin: <?= ($item['jenis_kelamin'] ?? '') === 'L' ? 'Laki-laki' : 'Perempuan' ?>
                                                <?= !empty($item['urutan_kelahiran']) ? ', Anak ke-' . esc($item['urutan_kelahiran']) : '' ?>
                                            <?php else: ?>
                                                Meninggal: <?= esc($item['tempat_kematian'] ?? '-') ?>, 
                                                <?= $item['tanggal_kematian'] ? date('d/m/Y', strtotime($item['tanggal_kematian'])) : '-' ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php
                                        $status = strtolower($item['status'] ?? '');
                                        $badge_class = match($status) {
                                            'selesai' => 'status-selesai',
                                            'ditolak' => 'status-ditolak',
                                            default => ''
                                        };
                                        ?>
                                        <span class="status-badge <?= $badge_class ?>">
                                            <?= ucfirst($item['status'] ?? 'Aktivitas') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= esc($item['alasan'] ?? '-') ?>
                                        <?php if (!empty($item['keterangan'])): ?>
                                            <br><small><strong>Pesan:</strong> <?= esc($item['keterangan']) ?></small>
                                        <?php endif; ?>
                                    </td>
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
                    Timeline Riwayat Akta Kelahiran & Kematian
                    <span class="table-count">Total: <?= $total_records ?> data</span>
                </h2>
                
                <?php if (empty($riwayat)): ?>
                    <div class="empty-state">
                        <i>📭</i>
                        <h4>Tidak ada riwayat ditemukan</h4>
                        <p>Tidak ada riwayat yang tercatat dengan filter yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="activity-timeline">
                        <?php 
                        $start_no = ($current_page - 1) * $per_page + 1;
                        $no = $start_no;
                        foreach ($riwayat as $item): 
                            $is_kelahiran = strpos(strtolower($item['jenis_layanan'] ?? ''), 'kelahiran') !== false;
                        ?>
                            <div class="activity-item">
                                <div class="activity-header">
                                    <div class="activity-title">
                                        <?= $no++ ?>. <?= esc($item['jenis_layanan']) ?> - 
                                        <?= esc($item['nama_user'] ?? $item['nama_pemohon'] ?? 'Pemohon') ?>
                                    </div>
                                    <div class="activity-time">
                                        <?php 
                                        $datetime = $item['activity_date'] ?? $item['updated_at'] ?? $item['created_at'] ?? '';
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
                                        default => ''
                                    };
                                    ?>
                                    <span class="status-badge <?= $badge_class ?>">
                                        <?= ucfirst($item['status'] ?? 'Aktivitas') ?>
                                    </span>
                                    
                                    <div style="margin-top: 10px;">
                                        <?php if (!empty($item['nama_rs'])): ?>
                                            <p style="margin: 5px 0;"><strong>Rumah Sakit:</strong> <?= esc($item['nama_rs']) ?></p>
                                        <?php endif; ?>
                                        <p style="margin: 5px 0;"><strong><?= $is_kelahiran ? 'Bayi' : 'Meninggal' ?>:</strong> <?= esc($item['nama_utama'] ?? '-') ?></p>
                                        <p style="margin: 5px 0;">
                                            <?php if ($is_kelahiran): ?>
                                                <strong>Lahir:</strong> 
                                                <?= esc($item['tempat_lahir'] ?? '-') ?>, 
                                                <?= $item['tanggal_lahir'] ? date('d/m/Y', strtotime($item['tanggal_lahir'])) : '-' ?>
                                                <?= !empty($item['waktu_lahir']) ? ' (' . esc($item['waktu_lahir']) . ')' : '' ?>
                                            <?php else: ?>
                                                <strong>Meninggal:</strong> 
                                                <?= esc($item['tempat_kematian'] ?? '-') ?>, 
                                                <?= $item['tanggal_kematian'] ? date('d/m/Y', strtotime($item['tanggal_kematian'])) : '-' ?>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($is_kelahiran): ?>
                                            <p style="margin: 5px 0;">
                                                <strong>Jenis Kelamin:</strong> 
                                                <?= ($item['jenis_kelamin'] ?? '') === 'L' ? 'Laki-laki' : 'Perempuan' ?>
                                                <?= !empty($item['urutan_kelahiran']) ? ', Anak ke-' . esc($item['urutan_kelahiran']) : '' ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($item['nama_ayah']) || !empty($item['nama_ibu'])): ?>
                                            <p style="margin: 5px 0;">
                                                <strong>Orang Tua:</strong> 
                                                <?= esc($item['nama_ayah'] ?? '-') ?> & <?= esc($item['nama_ibu'] ?? '-') ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($item['alasan'])): ?>
                                        <p style="margin-top: 8px; margin-bottom: 0;">
                                            <strong>Keterangan:</strong> <?= esc($item['alasan']) ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($item['keterangan'])): ?>
                                        <p style="margin-top: 5px; margin-bottom: 0;">
                                            <strong>Pesan Pemohon:</strong> <?= esc($item['keterangan']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="activity-meta">
                                    <?php if (!empty($item['nama_kecamatan'])): ?>
                                        <span>📍 <?= esc($item['nama_kecamatan']) ?></span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($item['nama_desa'])): ?>
                                        <span>🏠 <?= esc($item['nama_desa']) ?></span>
                                    <?php endif; ?>
                                </div>
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
document.getElementById('status')?.addEventListener('change', function() {
    document.querySelector('input[name="page"]').value = 1;
});

document.getElementById('rs')?.addEventListener('change', function() {
    document.querySelector('input[name="page"]').value = 1;
});

document.getElementById('type')?.addEventListener('change', function() {
    document.querySelector('input[name="page"]').value = 1;
});

document.getElementById('date')?.addEventListener('change', function() {
    document.querySelector('input[name="page"]').value = 1;
});
</script>

</body>
</html>
