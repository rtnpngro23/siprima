<?php
require_once '../error_handler.php';
// Gunakan middleware khusus operator AHLAK
include '../../app/middleware/operator_ahlak_only.php';

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

// ============ AMBIL SEMUA PUSKESMAS YANG DITUGASKAN KE OPERATOR AHLAK ============
$assigned_puskes_ids = $_SESSION['puskes_tugas'] ?? [];
$assigned_puskes_data = [];
$puskes_list_for_display = '';
$total_puskes_system = 0;

// 1. Hitung total puskesmas di sistem
$sql_total_puskes = "SELECT COUNT(*) as total_puskes FROM tb_puskes";
$result_total = $koneksi->query($sql_total_puskes);
if ($result_total && $row = $result_total->fetch_assoc()) {
    $total_puskes_system = (int)$row['total_puskes'];
}

// 2. Ambil detail puskesmas yang ditugaskan
if (empty($assigned_puskes_ids)) {
    // Jika tidak ada puskesmas di session, ambil semua puskesmas
    $sql_puskes = "SELECT p.*, kec.nama_kecamatan 
                   FROM tb_puskes p
                   LEFT JOIN tb_kecamatan kec ON p.id_kecamatan = kec.id 
                   ORDER BY p.nama_puskes";
    $result_puskes = $koneksi->query($sql_puskes);
    if ($result_puskes) {
        while ($row = $result_puskes->fetch_assoc()) {
            $assigned_puskes_ids[] = $row['id_puskes'];
            $assigned_puskes_data[] = $row;
        }
    }
    // Simpan ke session untuk penggunaan selanjutnya
    $_SESSION['puskes_tugas'] = $assigned_puskes_ids;
} else {
    // Ambil detail untuk puskesmas di session
    $placeholders = implode(',', array_fill(0, count($assigned_puskes_ids), '?'));
    $sql_puskes_detail = "SELECT p.*, kec.nama_kecamatan 
                          FROM tb_puskes p
                          LEFT JOIN tb_kecamatan kec ON p.id_kecamatan = kec.id 
                          WHERE p.id_puskes IN ($placeholders) 
                          ORDER BY p.nama_puskes";
    
    $stmt_detail = $koneksi->prepare($sql_puskes_detail);
    if ($stmt_detail) {
        $types = str_repeat('i', count($assigned_puskes_ids));
        $stmt_detail->bind_param($types, ...$assigned_puskes_ids);
        $stmt_detail->execute();
        $result_detail = $stmt_detail->get_result();
        
        while ($row = $result_detail->fetch_assoc()) {
            $assigned_puskes_data[] = $row;
        }
        $stmt_detail->close();
    }
}

// Buat string untuk menampilkan daftar puskesmas
if (!empty($assigned_puskes_data)) {
    $total_puskes_assigned = count($assigned_puskes_data);
    
    // CEK: Jika jumlah puskesmas yang ditugaskan sama atau lebih dari total puskesmas di sistem, tampilkan "Semua Puskesmas"
    if ($total_puskes_assigned >= $total_puskes_system) {
        $puskes_list_for_display = "Semua Puskesmas";
    } else {
        $puskes_names = array_map(function($puskes) {
            return $puskes['nama_puskes'] . ' (' . ($puskes['nama_kecamatan'] ?? '-') . ')';
        }, $assigned_puskes_data);
        
        $puskes_list_for_display = implode(', ', $puskes_names);
    }
}

// Tentukan wilayah tugas untuk ditampilkan di header
$wilayah_tugas = '';
if (!empty($assigned_puskes_data)) {
    $total_puskes_assigned = count($assigned_puskes_data);
    
    if ($total_puskes_assigned >= $total_puskes_system) {
        $wilayah_tugas = 'Semua Puskesmas';
    } else if ($total_puskes_assigned === 1) {
        $puskes = $assigned_puskes_data[0];
        $wilayah_tugas = ($puskes['nama_kecamatan'] ?? 'Tidak Diketahui') . ' - ' . $puskes['nama_puskes'];
    } else {
        $wilayah_tugas = $total_puskes_assigned . ' Puskesmas: ' . $puskes_list_for_display;
    }
} else {
    $wilayah_tugas = 'Tidak ada puskesmas ditugaskan';
}

// === Filter ===
$filter_status = $_GET['status'] ?? '';
$filter_date = $_GET['date'] ?? '';
$filter_puskes = $_GET['puskes'] ?? '';

$riwayat = [];
$all_riwayat_data = [];

// Ambil data riwayat dari tb_akta_kelahiran_ahlak dan tb_akta_kelahiran yang sudah selesai/ditolak
$tables_to_check = ['tb_akta_kelahiran_ahlak'];

foreach ($tables_to_check as $table_name) {
    if (!empty($assigned_puskes_ids)) {
        // Pilih kondisi puskesmas
        if ($total_puskes_system > 0 && count($assigned_puskes_data) >= $total_puskes_system) {
            // Jika mengelola semua puskesmas
            $base_query = "SELECT 
                            t.*,
                            t.created_at,
                            t.updated_at,
                            t.status,
                            t.nama_kecamatan,
                            t.nama_desa,
                            t.nama_bayi AS nama_utama,
                            t.alasan,
                            t.nama_ayah,
                            t.nama_ibu,
                            t.tempat_lahir,
                            t.tanggal_lahir,
                            t.jenis_kelamin,
                            t.urutan_kelahiran,
                            t.Waktu AS waktu_lahir,
                            u.nama AS nama_user,
                            p.nama_puskes,
                            'Akta Kelahiran AHLAK' AS jenis_layanan
                           FROM `$table_name` t
                           LEFT JOIN tb_users u ON t.id_user = u.id
                           LEFT JOIN tb_puskes p ON t.id_puskes = p.id_puskes
                           WHERE t.status IN ('selesai', 'ditolak')";
            
            $where_conditions = [];
            $params = [];
            $types = '';
            
        } else {
            // Jika mengelola beberapa puskesmas tertentu
            $placeholders = implode(',', array_fill(0, count($assigned_puskes_ids), '?'));
            $base_query = "SELECT 
                            t.*,
                            t.created_at,
                            t.updated_at,
                            t.status,
                            t.nama_kecamatan,
                            t.nama_desa,
                            t.nama_bayi AS nama_utama,
                            t.alasan,
                            t.nama_ayah,
                            t.nama_ibu,
                            t.tempat_lahir,
                            t.tanggal_lahir,
                            t.jenis_kelamin,
                            t.urutan_kelahiran,
                            t.Waktu AS waktu_lahir,
                            u.nama AS nama_user,
                            p.nama_puskes,
                            'Akta Kelahiran AHLAK' AS jenis_layanan
                           FROM `$table_name` t
                           LEFT JOIN tb_users u ON t.id_user = u.id
                           LEFT JOIN tb_puskes p ON t.id_puskes = p.id_puskes
                           WHERE t.status IN ('selesai', 'ditolak')
                           AND t.id_puskes IN ($placeholders)";
            
            $where_conditions = [];
            $params = $assigned_puskes_ids;
            $types = str_repeat('i', count($assigned_puskes_ids));
        }
        
        // Filter berdasarkan status
        if ($filter_status && in_array($filter_status, ['selesai', 'ditolak'])) {
            $where_conditions[] = "t.status = ?";
            $params[] = $filter_status;
            $types .= 's';
        }
        
        // Filter berdasarkan tanggal
        if ($filter_date) {
            $where_conditions[] = "DATE(t.updated_at) = ?";
            $params[] = $filter_date;
            $types .= 's';
        }
        
        // Filter berdasarkan puskesmas spesifik
        if ($filter_puskes && in_array($filter_puskes, array_column($assigned_puskes_data, 'id_puskes'))) {
            $where_conditions[] = "t.id_puskes = ?";
            $params[] = $filter_puskes;
            $types .= 'i';
        }
        
        // Bangun query
        $query = $base_query;
        if (!empty($where_conditions)) {
            $query .= " AND " . implode(" AND ", $where_conditions);
        }
        
        $query .= " ORDER BY t.updated_at DESC";
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            
            while ($r = $res->fetch_assoc()) {
                // Tentukan tanggal aktivitas (gunakan updated_at jika ada)
                if (isset($r['updated_at']) && !empty($r['updated_at'])) {
                    $r['activity_date'] = $r['updated_at'];
                } else {
                    $r['activity_date'] = $r['created_at'] ?? date('Y-m-d H:i:s');
                }
                $all_riwayat_data[] = $r;
            }
            $stmt->close();
        }
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

// Statistik per puskesmas
$puskes_stats = [];
foreach ($assigned_puskes_data as $puskes) {
    $puskes_stats[$puskes['nama_puskes']] = [
        'total' => 0,
        'selesai' => 0,
        'ditolak' => 0,
        'id_puskes' => $puskes['id_puskes']
    ];
}

foreach ($all_riwayat_data as $item) {
    $puskes_name = $item['nama_puskes'] ?? '';
    if (!empty($puskes_name) && isset($puskes_stats[$puskes_name])) {
        $puskes_stats[$puskes_name]['total']++;
        $status = strtolower($item['status'] ?? '');
        if ($status === 'selesai') {
            $puskes_stats[$puskes_name]['selesai']++;
        } elseif ($status === 'ditolak') {
            $puskes_stats[$puskes_name]['ditolak']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Akta Kelahiran - Operator AHLAK</title>
    <link rel="stylesheet" href="../admin.css">
    <link rel="icon" type="image/png" href="../assets/ahlak.png">
    <link rel="stylesheet" href="css/ahlak_riwayat_operator.css"> 
    
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator AHLAK</div>
        <nav>
            <ul>
                <li><a href="ahlak_dashboard_operator.php">Dashboard</a></li>
                <li><a href="ahlak_proses_pengajuan.php">Proses Pengajuan</a></li>                
                <li class="active">Riwayat</li>
                
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Riwayat Akta Kelahiran - Operator AHLAK</div>
                <div class="subtitle">Wilayah Tugas: <?= esc($wilayah_tugas) ?></div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama']) ?></span>
                <span class="role-badge">OPERATOR AHLAK</span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout-btn" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Puskesmas Info Box -->
            <?php if (!empty($assigned_puskes_data)): ?>
            <div class="puskes-info-box">
                <div class="puskes-info-header">
                    <div class="puskes-info-icon">🏥</div>
                    <div class="puskes-info-content">
                        <h3>Informasi Wilayah Tugas</h3>
                        <p>
                            <?php if (isset($total_puskes_system) && count($assigned_puskes_data) >= $total_puskes_system): ?>
                                Anda bertanggung jawab atas <strong>Semua Puskesmas</strong> di sistem ini.
                            <?php else: ?>
                                Anda bertanggung jawab atas 
                                <?= count($assigned_puskes_data) ?> Puskesmas: <?= esc($puskes_list_for_display) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="puskes-info-box" style="border-left-color: #ef4444;">
                <div class="puskes-info-header">
                    <div class="puskes-info-icon">⚠️</div>
                    <div class="puskes-info-content">
                        <h3>Informasi Puskesmas Tugas</h3>
                        <p>Anda belum ditugaskan ke puskesmas manapun. Hubungi administrator.</p>
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
                <!-- Statistik Per Puskesmas -->
                <div class="stats-box">
                    <h3>Statistik Per Puskesmas</h3>
                    <div class="stats-list">
                        <?php 
                        // Urutkan puskesmas berdasarkan total
                        uasort($puskes_stats, function($a, $b) {
                            return $b['total'] - $a['total'];
                        });
                        
                        $counter = 0;
                        foreach ($puskes_stats as $puskes_name => $puskes_data): 
                            $counter++;
                        ?>
                            <div class="stats-item">
                                <div class="stats-item-name"><?= esc($puskes_name) ?></div>
                                <div class="stats-item-counts">
                                    <div class="stats-item-count">
                                        <div class="label">Total</div>
                                        <div class="value"><?= $puskes_data['total'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Selesai</div>
                                        <div class="value"><?= $puskes_data['selesai'] ?></div>
                                    </div>
                                    <div class="stats-item-count">
                                        <div class="label">Ditolak</div>
                                        <div class="value"><?= $puskes_data['ditolak'] ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; 
                        
                        if ($counter === 0): ?>
                            <div class="empty-state" style="padding: 20px 0;">
                                <p>Tidak ada data riwayat untuk puskesmas yang ditugaskan</p>
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
                    
                    <?php if (!empty($assigned_puskes_data) && count($assigned_puskes_data) > 1): ?>
                    <div class="filter-group">
                        <label for="puskes">Puskesmas</label>
                        <select name="puskes" id="puskes">
                            <option value="">Semua Puskesmas</option>
                            <?php foreach ($assigned_puskes_data as $puskes): ?>
                                <option value="<?= esc($puskes['id_puskes']) ?>" <?= $filter_puskes == $puskes['id_puskes'] ? 'selected' : '' ?>>
                                    <?= esc($puskes['nama_puskes']) ?> (<?= esc($puskes['nama_kecamatan'] ?? '-') ?>)
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
                        <a href="ahlak_riwayat_operator.php" class="btn-reset">Reset Filter</a>
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
                    Daftar Riwayat Akta Kelahiran
                    <?php if (isset($total_puskes_system) && count($assigned_puskes_data) >= $total_puskes_system): ?>
                        <span style="color: #0d9488;">(Semua Puskesmas)</span>
                    <?php else: ?>
                        <?= count($assigned_puskes_data) ?> Puskesmas Tugas
                    <?php endif; ?>
                    <span class="table-count">Total: <?= $total_records ?> data</span>
                </h2>
                
                <?php if (empty($riwayat)): ?>
                    <div class="empty-state">
                        <i>📭</i>
                        <h4>Tidak ada riwayat ditemukan</h4>
                        <p>Tidak ada riwayat Akta Kelahiran yang tercatat dengan filter yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Jenis Layanan</th>
                                <th>Nama Pemohon</th>
                                <th>Puskesmas</th>
                                <th>Kecamatan</th>
                                <th>Desa</th>
                                <th>Nama Bayi</th>
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
                                        <span class="jenis-badge jenis-akta-ahlak">
                                            <?= esc($item['jenis_layanan']) ?>
                                        </span>
                                    </td>
                                    <td><?= esc($item['nama_user'] ?? $item['nama_pemohon'] ?? '-') ?></td>
                                    <td>
                                        <?php if (!empty($item['nama_puskes'])): ?>
                                            <strong><?= esc($item['nama_puskes']) ?></strong>
                                        <?php else: ?>
                                            <em style="color: #ef4444;">Tidak ada ID Puskesmas</em>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= esc($item['nama_kecamatan'] ?? '-') ?></td>
                                    <td><?= esc($item['nama_desa'] ?? '-') ?></td>
                                    <td>
                                        <strong><?= esc($item['nama_bayi'] ?? '-') ?></strong>
                                        <br>
                                        <small>
                                            Lahir: <?= esc($item['tempat_lahir'] ?? '-') ?>, 
                                            <?= $item['tanggal_lahir'] ? date('d/m/Y', strtotime($item['tanggal_lahir'])) : '-' ?>
                                            <?= !empty($item['waktu_lahir']) ? ' (' . esc($item['waktu_lahir']) . ')' : '' ?>
                                        </small>
                                        <br>
                                        <small>
                                            Jenis Kelamin: <?= ($item['jenis_kelamin'] ?? '') === 'L' ? 'Laki-laki' : 'Perempuan' ?>
                                            <?= !empty($item['urutan_kelahiran']) ? ', Anak ke-' . esc($item['urutan_kelahiran']) : '' ?>
                                        </small>
                                    </td>
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
                    Timeline Riwayat Akta Kelahiran
                    <span class="table-count">Total: <?= $total_records ?> data</span>
                </h2>
                
                <?php if (empty($riwayat)): ?>
                    <div class="empty-state">
                        <i>📭</i>
                        <h4>Tidak ada riwayat ditemukan</h4>
                        <p>Tidak ada riwayat Akta Kelahiran yang tercatat dengan filter yang dipilih.</p>
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
                                    
                                    <div style="margin-top: 10px;">
                                        <?php if (!empty($item['nama_puskes'])): ?>
                                            <p style="margin: 5px 0;"><strong>Puskesmas:</strong> <?= esc($item['nama_puskes']) ?></p>
                                        <?php endif; ?>
                                        <p style="margin: 5px 0;"><strong>Bayi:</strong> <?= esc($item['nama_bayi'] ?? '-') ?></p>
                                        <p style="margin: 5px 0;">
                                            <strong>Lahir:</strong> 
                                            <?= esc($item['tempat_lahir'] ?? '-') ?>, 
                                            <?= $item['tanggal_lahir'] ? date('d/m/Y', strtotime($item['tanggal_lahir'])) : '-' ?>
                                            <?= !empty($item['waktu_lahir']) ? ' (' . esc($item['waktu_lahir']) . ')' : '' ?>
                                        </p>
                                        <p style="margin: 5px 0;">
                                            <strong>Jenis Kelamin:</strong> 
                                            <?= ($item['jenis_kelamin'] ?? '') === 'L' ? 'Laki-laki' : 'Perempuan' ?>
                                            <?= !empty($item['urutan_kelahiran']) ? ', Anak ke-' . esc($item['urutan_kelahiran']) : '' ?>
                                        </p>
                                        <p style="margin: 5px 0;">
                                            <strong>Orang Tua:</strong> 
                                            <?= esc($item['nama_ayah'] ?? '-') ?> & <?= esc($item['nama_ibu'] ?? '-') ?>
                                        </p>
                                    </div>
                                    
                                    <?php if (!empty($item['alasan'])): ?>
                                        <p style="margin-top: 8px; margin-bottom: 0;">
                                            <strong>Keterangan:</strong> <?= esc($item['alasan']) ?>
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

document.getElementById('puskes')?.addEventListener('change', function() {
    document.querySelector('input[name="page"]').value = 1;
});

document.getElementById('date')?.addEventListener('change', function() {
    document.querySelector('input[name="page"]').value = 1;
});
</script>

</body>
</html>