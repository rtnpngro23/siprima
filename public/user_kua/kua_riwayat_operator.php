<?php
require_once '../error_handler.php';
include '../../app/middleware/operator_only_kua.php';

error_log("=== START KUA PROSES PENGAJUAN (OPERATOR) ===");
error_log('Request: ' . siprima_request_id());
error_log("Role: " . $_SESSION['role']);
error_log("User ID: " . $_SESSION['id_user']);
error_log("KUA IDs: " . (isset($_SESSION['kua_tugas']) ? implode(', ', $_SESSION['kua_tugas']) : 'NOT SET'));

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

// ============ AMBIL SEMUA KUA YANG DITUGASKAN KE OPERATOR ============
$assigned_kua_ids = [];
$assigned_kua_data = [];
$kua_list_for_display = '';
$total_kua_system = 0;

// 1. Hitung total KUA di sistem
$sql_total_kua = "SELECT COUNT(*) as total_kua FROM tb_kua";
$result_total = $koneksi->query($sql_total_kua);
if ($result_total && $row = $result_total->fetch_assoc()) {
    $total_kua_system = (int)$row['total_kua'];
    error_log("Total KUA in system: $total_kua_system");
}

// 2. Ambil KUA langsung yang ditugaskan ke operator (dari kolom kua_id)
$sql_direct_kua = "SELECT kua_id FROM tb_operator_kecamatan 
                   WHERE operator_id = ? AND kua_id IS NOT NULL";
$stmt_direct = $koneksi->prepare($sql_direct_kua);
if ($stmt_direct) {
    $stmt_direct->bind_param("i", $_SESSION['id_user']);
    $stmt_direct->execute();
    $result_direct = $stmt_direct->get_result();
    while ($row = $result_direct->fetch_assoc()) {
        $assigned_kua_ids[] = $row['kua_id'];
        error_log("Direct KUA assigned: " . $row['kua_id']);
    }
    $stmt_direct->close();
} else {
    error_log("Error preparing direct KUA query: " . $koneksi->error);
}

// 3. Jika tidak ada KUA langsung, ambil melalui kecamatan yang ditugaskan
if (empty($assigned_kua_ids)) {
    error_log("No direct KUA, checking kecamatan assignments");
    $sql_kecamatan = "SELECT kecamatan_id FROM tb_operator_kecamatan 
                      WHERE operator_id = ? AND kecamatan_id IS NOT NULL";
    $stmt_kec = $koneksi->prepare($sql_kecamatan);
    if ($stmt_kec) {
        $stmt_kec->bind_param("i", $_SESSION['id_user']);
        $stmt_kec->execute();
        $result_kec = $stmt_kec->get_result();
        $kecamatan_ids = [];
        while ($row = $stmt_kec->get_result()->fetch_assoc()) {
            $kecamatan_ids[] = $row['kecamatan_id'];
            error_log("Kecamatan assigned: " . $row['kecamatan_id']);
        }
        $stmt_kec->close();
        
        // Ambil KUA yang ada di kecamatan-kecamatan tersebut
        if (!empty($kecamatan_ids)) {
            $placeholders = implode(',', array_fill(0, count($kecamatan_ids), '?'));
            $sql_kua_in_kecamatan = "SELECT id_kua FROM tb_kua 
                                     WHERE id_kecamatan IN ($placeholders)";
            $stmt_kua = $koneksi->prepare($sql_kua_in_kecamatan);
            if ($stmt_kua) {
                $types = str_repeat('i', count($kecamatan_ids));
                $stmt_kua->bind_param($types, ...$kecamatan_ids);
                $stmt_kua->execute();
                $result_kua = $stmt_kua->get_result();
                while ($row = $result_kua->fetch_assoc()) {
                    $assigned_kua_ids[] = $row['id_kua'];
                    error_log("KUA from kecamatan: " . $row['id_kua']);
                }
                $stmt_kua->close();
            }
        } else {
            error_log("No kecamatan assigned to operator");
        }
    }
}

// 4. Ambil detail data untuk semua KUA yang ditugaskan
if (!empty($assigned_kua_ids)) {
    $placeholders = implode(',', array_fill(0, count($assigned_kua_ids), '?'));
    $sql_kua_detail = "SELECT k.*, kec.nama_kecamatan 
                       FROM tb_kua k 
                       LEFT JOIN tb_kecamatan kec ON k.id_kecamatan = kec.id 
                       WHERE k.id_kua IN ($placeholders) 
                       ORDER BY k.nama_kua";
    
    $stmt_detail = $koneksi->prepare($sql_kua_detail);
    if ($stmt_detail) {
        $types = str_repeat('i', count($assigned_kua_ids));
        $stmt_detail->bind_param($types, ...$assigned_kua_ids);
        $stmt_detail->execute();
        $result_detail = $stmt_detail->get_result();
        
        while ($row = $result_detail->fetch_assoc()) {
            $assigned_kua_data[] = $row;
            error_log("KUA detail: " . $row['nama_kua'] . " (ID: " . $row['id_kua'] . ")");
        }
        $stmt_detail->close();
        
        // Buat string untuk menampilkan daftar KUA
        $total_kua_assigned = count($assigned_kua_data);
        error_log("Total KUA assigned: $total_kua_assigned");
        
        // CEK: Jika jumlah KUA yang ditugaskan sama atau lebih dari total KUA di sistem, tampilkan "Semua KUA"
        if ($total_kua_assigned >= $total_kua_system) {
            $kua_list_for_display = "Semua KUA";
            error_log("Operator manages ALL KUA");
        } else {
            $kua_names = array_map(function($kua) {
                return $kua['nama_kua'] . ' (' . ($kua['nama_kecamatan'] ?? '-') . ')';
            }, $assigned_kua_data);
            
            $kua_list_for_display = implode(', ', $kua_names);
        }
        
        // Set KUA pertama sebagai default jika session id_kua belum ada
        if (!isset($_SESSION['id_kua']) && !empty($assigned_kua_data)) {
            $_SESSION['id_kua'] = $assigned_kua_data[0]['id_kua'];
            $_SESSION['nama_kua'] = $assigned_kua_data[0]['nama_kua'];
            error_log("Set default KUA: " . $_SESSION['nama_kua'] . " (ID: " . $_SESSION['id_kua'] . ")");
        }
    }
} else {
    error_log("Operator ID " . $_SESSION['id_user'] . " has NO KUA assigned");
}

// Tentukan wilayah tugas untuk ditampilkan di header
$wilayah_tugas = '';
if (!empty($assigned_kua_data)) {
    $total_kua_assigned = count($assigned_kua_data);
    
    if ($total_kua_assigned >= $total_kua_system) {
        $wilayah_tugas = 'Semua KUA';
    } else if ($total_kua_assigned === 1) {
        $kua = $assigned_kua_data[0];
        $wilayah_tugas = ($kua['nama_kecamatan'] ?? 'Tidak Diketahui') . ' - ' . $kua['nama_kua'];
    } else {
        $wilayah_tugas = $total_kua_assigned . ' KUA: ' . $kua_list_for_display;
    }
} else {
    $wilayah_tugas = 'Tidak ada KUA ditugaskan';
    error_log("Operator ID " . $_SESSION['id_user'] . " tidak memiliki KUA terkait");
}

error_log("Wilayah tugas: $wilayah_tugas");

// === Filter ===
$filter_status = $_GET['status'] ?? '';
$filter_date = $_GET['date'] ?? '';
$filter_kua = $_GET['kua'] ?? '';

$riwayat = [];
$all_riwayat_data = [];

// Ambil data riwayat dari tb_kartukeluarga_kua
if (!empty($assigned_kua_ids)) {
    // Pilih kondisi KUA
    if ($total_kua_system > 0 && count($assigned_kua_data) >= $total_kua_system) {
        // Jika mengelola semua KUA
        $base_query = "SELECT 
                        kk.*,
                        kk.created_at,
                        kk.updated_at,
                        kk.status,
                        kk.nama_kecamatan,
                        kk.nama_desa,
                        kk.nama_kepalaKeluarga AS nama_utama,
                        kk.alasan,
                        kk.no_kk_lama_suami,
                        kk.no_kk_lama_istri,
                        u.nama AS nama_user,
                        k.nama_kua,
                        'Kartu Keluarga KUA' AS jenis_layanan
                       FROM tb_kartukeluarga_kua kk
                       LEFT JOIN tb_users u ON kk.id_user = u.id
                       LEFT JOIN tb_kua k ON kk.id_kua = k.id_kua
                       WHERE kk.status IN ('selesai', 'ditolak')";
        
        $where_conditions = [];
        $params = [];
        $types = '';
        
    } else {
        // Jika mengelola beberapa KUA tertentu
        $placeholders = implode(',', array_fill(0, count($assigned_kua_ids), '?'));
        $base_query = "SELECT 
                        kk.*,
                        kk.created_at,
                        kk.updated_at,
                        kk.status,
                        kk.nama_kecamatan,
                        kk.nama_desa,
                        kk.nama_kepalaKeluarga AS nama_utama,
                        kk.alasan,
                        kk.no_kk_lama_suami,
                        kk.no_kk_lama_istri,
                        u.nama AS nama_user,
                        k.nama_kua,
                        'Kartu Keluarga KUA' AS jenis_layanan
                       FROM tb_kartukeluarga_kua kk
                       LEFT JOIN tb_users u ON kk.id_user = u.id
                       LEFT JOIN tb_kua k ON kk.id_kua = k.id_kua
                       WHERE kk.status IN ('selesai', 'ditolak')
                       AND kk.id_kua IN ($placeholders)";
        
        $where_conditions = [];
        $params = $assigned_kua_ids;
        $types = str_repeat('i', count($assigned_kua_ids));
    }
    
    // Filter berdasarkan status
    if ($filter_status && in_array($filter_status, ['selesai', 'ditolak'])) {
        $where_conditions[] = "kk.status = ?";
        $params[] = $filter_status;
        $types .= 's';
    }
    
    // Filter berdasarkan tanggal
    if ($filter_date) {
        $where_conditions[] = "DATE(kk.updated_at) = ?";
        $params[] = $filter_date;
        $types .= 's';
    }
    
    // Filter berdasarkan KUA spesifik
    if ($filter_kua && in_array($filter_kua, array_column($assigned_kua_data, 'id_kua'))) {
        $where_conditions[] = "kk.id_kua = ?";
        $params[] = $filter_kua;
        $types .= 'i';
    }
    
    // Bangun query
    $query = $base_query;
    if (!empty($where_conditions)) {
        $query .= " AND " . implode(" AND ", $where_conditions);
    }
    
    $query .= " ORDER BY kk.updated_at DESC";
    
    $stmt = $koneksi->prepare($query);
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
        error_log("Found $count history records from tb_kartukeluarga_kua");
        $stmt->close();
    } else {
        error_log("Failed to prepare statement: " . $koneksi->error);
    }
}

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
    'hari_ini' => 0
];

$today = date('Y-m-d');

foreach ($all_riwayat_data as $item) {
    $status = strtolower($item['status'] ?? '');
    if ($status === 'selesai') {
        $stats['selesai']++;
    } elseif ($status === 'ditolak') {
        $stats['ditolak']++;
    }
    
    $item_date = $item['activity_date'] ?? $item['updated_at'] ?? $item['created_at'] ?? '';
    if ($item_date && date('Y-m-d', strtotime($item_date)) === $today) {
        $stats['hari_ini']++;
    }
}

// Statistik per KUA
$kua_stats = [];
foreach ($assigned_kua_data as $kua) {
    $kua_stats[$kua['nama_kua']] = [
        'total' => 0,
        'selesai' => 0,
        'ditolak' => 0,
        'id_kua' => $kua['id_kua']
    ];
}

foreach ($all_riwayat_data as $item) {
    $kua_name = $item['nama_kua'] ?? '';
    if (!empty($kua_name) && isset($kua_stats[$kua_name])) {
        $kua_stats[$kua_name]['total']++;
        $status = strtolower($item['status'] ?? '');
        if ($status === 'selesai') {
            $kua_stats[$kua_name]['selesai']++;
        } elseif ($status === 'ditolak') {
            $kua_stats[$kua_name]['ditolak']++;
        }
    }
}

error_log("Total history records: $total_records");
error_log("Current page: $current_page, Total pages: $total_pages");
error_log("=== END KUA RIWAYAT OPERATOR ===");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Kartu Keluarga KUA - Operator PASUTRI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/kua_riwayat_operator.css?v=20260821-2">
    <link rel="icon" type="image/png" href="../assets/PASUTRI.png">
    
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator PASUTRI</div>
        <nav>
            <ul>
                <li><a href="kua_dashboard_operator.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="kua_proses_pengajuan.php"><i class="fas fa-cog"></i> Proses Pengajuan</a></li>
                <li  class="active"><a href="kua_riwayat_operator.php"><i class="fas fa-history"></i> Riwayat</a></li>
                <li><a href="kua_dokumen_belum_diterima.php"><i class="fas fa-envelope-open-text"></i> Dokumen Belum Diterima</a></li>
                <li id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Riwayat Kartu Keluarga KUA - Operator PASUTRI</div>
                <div class="subtitle">Wilayah Tugas: <?= esc($wilayah_tugas) ?></div>
            </div>
            <div class="user-info">
                Hai, <?= esc($_SESSION['nama']) ?>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- KUA Info Box -->
            <?php if (!empty($assigned_kua_data)): ?>
            <div class="kua-info-box">
                <div class="kua-info-header">
                    <div class="kua-info-icon">🏢</div>
                    <div class="kua-info-content">
                        <h3>Informasi Wilayah Tugas</h3>
                        <p>
                            <?php if (isset($total_kua_system) && count($assigned_kua_data) >= $total_kua_system): ?>
                                Anda bertanggung jawab atas <strong>Semua KUA</strong> di sistem ini.
                            <?php else: ?>
                                Anda bertanggung jawab atas 
                                <?= count($assigned_kua_data) ?> KUA: <?= esc($kua_list_for_display) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="kua-info-box" style="border-left-color: #f56565;">
                <div class="kua-info-header">
                    <div class="kua-info-icon">⚠️</div>
                    <div class="kua-info-content">
                        <h3>Informasi KUA Tugas</h3>
                        <p>Anda belum ditugaskan ke KUA manapun. Hubungi administrator.</p>
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
            </div>

            <!-- Statistik Grid -->
            <div class="stats-grid">
                <!-- Statistik Per KUA -->
                <div class="stats-box">
                    <h3>Statistik Per KUA</h3>
                    <div class="stats-list">
                        <?php 
                        // Urutkan KUA berdasarkan total
                        uasort($kua_stats, function($a, $b) {
                            return $b['total'] - $a['total'];
                        });
                        
                        $counter = 0;
                        foreach ($kua_stats as $kua_name => $kua_data): 
                            $counter++;
                        ?>
                            <div class="stats-item">
                                <div class="stats-item-name"><?= esc($kua_name) ?></div>
                                <div class="stats-item-counts">
                                    <div class="stats-item-count">
                                        <div class="label">Total</div>
                                        <div class="value"><?= $kua_data['total'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Selesai</div>
                                        <div class="value"><?= $kua_data['selesai'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Ditolak</div>
                                        <div class="value"><?= $kua_data['ditolak'] ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; 
                        
                        if ($counter === 0): ?>
                            <div class="empty-state" style="padding: 20px 0;">
                                <p>Tidak ada data KUA</p>
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
                    
                    <?php if (!empty($assigned_kua_data) && count($assigned_kua_data) > 1): ?>
                    <div class="filter-group">
                        <label for="kua">KUA</label>
                        <select name="kua" id="kua">
                            <option value="">Semua KUA</option>
                            <?php foreach ($assigned_kua_data as $kua): ?>
                                <option value="<?= esc($kua['id_kua']) ?>" <?= $filter_kua == $kua['id_kua'] ? 'selected' : '' ?>>
                                    <?= esc($kua['nama_kua']) ?> (<?= esc($kua['nama_kecamatan'] ?? '-') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="filter-group">
                        <label for="date">Tanggal</label>
                        <input type="date" name="date" id="date" value="<?= esc($filter_date) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <button type="submit" class="btn-filter">Terapkan Filter</button>
                    </div>
                    
                    <div class="filter-group">
                        <a href="kua_riwayat_operator.php" class="btn-reset">Reset Filter</a>
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
                    Daftar Riwayat 
                    <?php if (isset($total_kua_system) && count($assigned_kua_data) >= $total_kua_system): ?>
                        <span style="color: #3b82f6;">(Semua KUA)</span>
                    <?php else: ?>
                        <?= count($assigned_kua_data) ?> KUA Tugas
                    <?php endif; ?>
                    <span class="table-count">Total: <?= $total_records ?> data</span>
                </h2>
                
                <?php if (empty($riwayat)): ?>
                    <div class="empty-state">
                        <i>📭</i>
                        <h4>Tidak ada riwayat ditemukan</h4>
                        <p>Tidak ada riwayat Kartu Keluarga KUA yang tercatat dengan filter yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Jenis Layanan</th>
                                <th>Nama Pemohon</th>
                                <th>KUA</th>
                                <th>Kecamatan</th>
                                <th>Desa</th>
                                <th>Nama Kepala Keluarga</th>
                                <th>Status</th>
                                <th>Alasan/Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = ($current_page - 1) * $per_page + 1;
                            foreach ($riwayat as $item): 
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
                                        <span class="jenis-badge jenis-kk">
                                            <?= esc($item['jenis_layanan']) ?>
                                        </span>
                                    </td>
                                    <td><?= esc($item['nama_user'] ?? $item['nama_pemohon'] ?? '-') ?></td>
                                    <td><?= esc($item['nama_kua'] ?? '-') ?></td>
                                    <td><?= esc($item['nama_kecamatan'] ?? '-') ?></td>
                                    <td><?= esc($item['nama_desa'] ?? '-') ?></td>
                                    <td><?= esc($item['nama_utama'] ?? $item['nama_kepalaKeluarga'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $status = strtolower($item['status'] ?? '');
                                        $badge_class = match($status) {
                                            'selesai' => 'status-selesai',
                                            'ditolak' => 'status-ditolak',
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
                    Timeline Riwayat Kartu Keluarga KUA
                    <span class="table-count">Total: <?= $total_records ?> data</span>
                </h2>
                
                <?php if (empty($riwayat)): ?>
                    <div class="empty-state">
                        <i>📭</i>
                        <h4>Tidak ada riwayat ditemukan</h4>
                        <p>Tidak ada riwayat Kartu Keluarga KUA yang tercatat dengan filter yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="activity-timeline">
                        <?php 
                        $start_no = ($current_page - 1) * $per_page + 1;
                        $no = $start_no;
                        foreach ($riwayat as $item): 
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
                                        default => 'status-log'
                                    };
                                    ?>
                                    <span class="status-badge <?= $badge_class ?>">
                                        <?= ucfirst($item['status'] ?? 'Aktivitas') ?>
                                    </span>
                                    
                                    <?php if (!empty($item['nama_utama']) || !empty($item['nama_kepalaKeluarga'])): ?>
                                        <p style="margin-top: 8px; margin-bottom: 4px;">
                                            <strong>Kepala Keluarga:</strong> <?= esc($item['nama_utama'] ?? $item['nama_kepalaKeluarga']) ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($item['alasan'])): ?>
                                        <p style="margin-top: 8px; margin-bottom: 0;">
                                            <strong>Keterangan:</strong> <?= esc($item['alasan']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="activity-meta">
                                    <?php if (!empty($item['nama_kua'])): ?>
                                        <span>🏢 <?= esc($item['nama_kua']) ?></span>
                                    <?php endif; ?>
                                    
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
const statusFilter = document.getElementById('status');
if (statusFilter) {
    statusFilter.addEventListener('change', function() {
        const pageInput = document.querySelector('input[name="page"]');
        if (pageInput) pageInput.value = 1;
    });
}

const kuaFilter = document.getElementById('kua');
if (kuaFilter) {
    kuaFilter.addEventListener('change', function() {
        const pageInput = document.querySelector('input[name="page"]');
        if (pageInput) pageInput.value = 1;
    });
}

const dateFilter = document.getElementById('date');
if (dateFilter) {
    dateFilter.addEventListener('change', function() {
        const pageInput = document.querySelector('input[name="page"]');
        if (pageInput) pageInput.value = 1;
    });
}
</script>

</body>
</html>
