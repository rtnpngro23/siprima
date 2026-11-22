<?php
require_once '../error_handler.php';
// Gunakan middleware khusus operator AHLAK
include '../../app/middleware/operator_ahlak_only.php';

// Debug
error_log("=== DASHBOARD OPERATOR AHLAK START ===");
error_log("Operator ID: " . $_SESSION['id_user']);
error_log("Operator Name: " . $_SESSION['nama']);
error_log("Puskesmas IDs: " . (isset($_SESSION['puskes_tugas']) ? implode(', ', $_SESSION['puskes_tugas']) : 'NOT SET'));
error_log("Role: " . $_SESSION['role']);

// Koneksi database
include '../../app/config/koneksi.php';

// ============ AMBIL SEMUA PUSKESMAS YANG DITUGASKAN KE OPERATOR ============
$assigned_puskes_ids = [];
$assigned_puskes_data = [];
$puskes_list_for_display = '';
$total_puskes_system = 0;

// 1. Ambil dari session puskes_tugas (jika sudah ada)
if (isset($_SESSION['puskes_tugas']) && !empty($_SESSION['puskes_tugas'])) {
    $assigned_puskes_ids = $_SESSION['puskes_tugas'];
    error_log("Using puskes_tugas from session: " . count($assigned_puskes_ids) . " puskesmas");
}

// 2. Jika tidak ada di session, ambil dari database tb_operator_kecamatan
if (empty($assigned_puskes_ids)) {
    $sql = "SELECT puskes_id FROM tb_operator_kecamatan 
            WHERE operator_id = ? AND puskes_id IS NOT NULL
            ORDER BY puskes_id";
    $stmt = $koneksi->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['id_user']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['puskes_id'])) {
                $assigned_puskes_ids[] = $row['puskes_id'];
            }
        }
        $stmt->close();
        error_log("Found " . count($assigned_puskes_ids) . " puskesmas from tb_operator_kecamatan");
        
        // Simpan ke session untuk penggunaan berikutnya
        $_SESSION['puskes_tugas'] = $assigned_puskes_ids;
    }
}

// Hitung total puskesmas yang ada di sistem
$sql_total_puskes = "SELECT COUNT(*) as total_puskes FROM tb_puskes";
$result_total = $koneksi->query($sql_total_puskes);
if ($result_total && $row = $result_total->fetch_assoc()) {
    $total_puskes_system = (int)$row['total_puskes'];
}

// Ambil detail data untuk semua puskesmas yang ditugaskan
if (!empty($assigned_puskes_ids)) {
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
        
        // Logika tampilan berdasarkan jumlah puskesmas
        $total_puskes_assigned = count($assigned_puskes_data);
        
        // CEK: Jika jumlah puskesmas yang ditugaskan = 19 atau >= total puskesmas sistem
        if ($total_puskes_assigned == 19 || $total_puskes_assigned >= $total_puskes_system) {
            $puskes_list_for_display = "Semua Puskesmas";
            $wilayah_tugas = "Semua Puskesmas";
        } 
        // Jika jumlah puskesmas yang ditugaskan < 5, tampilkan nama-nama puskesmas
        elseif ($total_puskes_assigned < 5) {
            $puskes_names = array_map(function($puskes) {
                return $puskes['nama_puskes'] . ' (' . ($puskes['nama_kecamatan'] ?? '-') . ')';
            }, $assigned_puskes_data);
            
            $puskes_list_for_display = implode(', ', $puskes_names);
            $wilayah_tugas = $total_puskes_assigned . " Puskesmas: " . $puskes_list_for_display;
        }
        // Jika 5 <= jumlah puskesmas < 19, tampilkan jumlahnya saja
        else {
            $puskes_list_for_display = $total_puskes_assigned . " Puskesmas";
            $wilayah_tugas = $total_puskes_assigned . " Puskesmas";
        }
        
        // Update session dengan nama puskesmas
        if (!isset($_SESSION['puskes_nama']) || empty($_SESSION['puskes_nama'])) {
            $_SESSION['puskes_nama'] = array_column($assigned_puskes_data, 'nama_puskes');
        }
        
        // Set puskesmas pertama sebagai default jika session id_puskes belum ada
        if (!isset($_SESSION['id_puskes']) && !empty($assigned_puskes_data)) {
            $_SESSION['id_puskes'] = $assigned_puskes_data[0]['id_puskes'];
            $_SESSION['nama_puskes'] = $assigned_puskes_data[0]['nama_puskes'];
        }
    }
} else {
    $wilayah_tugas = 'Tidak ada Puskesmas ditugaskan';
    error_log("Operator ID " . $_SESSION['id_user'] . " tidak memiliki puskesmas terkait");
}

// Fungsi helper untuk escape output
function esc($string) {
    return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
}

// Flash messages
$flash_success = $_SESSION['success_message'] ?? null;
$flash_error = $_SESSION['error_message'] ?? null;

// Clear flash messages
unset($_SESSION['success_message'], $_SESSION['error_message']);

// ============ HITUNG STATISTIK AKTA KELAHIRAN AHLAK ============
$stats = [
    'total' => 0,
    'diajukan' => 0,
    'diproses' => 0,
    'selesai' => 0,
    'ditolak' => 0
];

// Gunakan tabel akta kelahiran AHLAK
$tbl = 'tb_akta_kelahiran_ahlak';

if (!empty($assigned_puskes_ids)) {
    try {
        // Jika operator mengelola semua puskesmas (19 atau >= total sistem)
        if ($total_puskes_assigned == 19 || $total_puskes_assigned >= $total_puskes_system) {
            $query = "SELECT 
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
                        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
                        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
                      FROM `$tbl` 
                      WHERE id_puskes IS NOT NULL";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $stats['total'] = (int)($row['total'] ?? 0);
                    $stats['diajukan'] = (int)($row['diajukan'] ?? 0);
                    $stats['diproses'] = (int)($row['diproses'] ?? 0);
                    $stats['selesai'] = (int)($row['selesai'] ?? 0);
                    $stats['ditolak'] = (int)($row['ditolak'] ?? 0);
                }
                $stmt->close();
            }
        } else {
            // Query dengan filter multiple puskesmas
            $placeholders = implode(',', array_fill(0, count($assigned_puskes_ids), '?'));
            $query = "SELECT 
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
                        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
                        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
                      FROM `$tbl` 
                      WHERE id_puskes IN ($placeholders)";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                $types = str_repeat('i', count($assigned_puskes_ids));
                $stmt->bind_param($types, ...$assigned_puskes_ids);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $stats['total'] = (int)($row['total'] ?? 0);
                    $stats['diajukan'] = (int)($row['diajukan'] ?? 0);
                    $stats['diproses'] = (int)($row['diproses'] ?? 0);
                    $stats['selesai'] = (int)($row['selesai'] ?? 0);
                    $stats['ditolak'] = (int)($row['ditolak'] ?? 0);
                }
                $stmt->close();
            }
        }
    } catch (Exception $e) {
        error_log("Error counting stats for table $tbl: " . $e->getMessage());
    }
}

// === Pagination Configuration ===
$limit = 10; // Jumlah data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// === Ambil total data untuk pagination ===
$total_data = 0;

if (!empty($assigned_puskes_ids)) {
    try {
        // Jika operator mengelola semua puskesmas
        if ($total_puskes_assigned == 19 || $total_puskes_assigned >= $total_puskes_system) {
            $query = "SELECT COUNT(*) as total FROM `$tbl` 
                      WHERE status = 'diajukan' 
                      AND id_puskes IS NOT NULL";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $total_data = (int)$row['total'];
                }
                $stmt->close();
            }
        } else {
            $placeholders = implode(',', array_fill(0, count($assigned_puskes_ids), '?'));
            $query = "SELECT COUNT(*) as total FROM `$tbl` 
                      WHERE status = 'diajukan' 
                      AND id_puskes IN ($placeholders)";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                $types = str_repeat('i', count($assigned_puskes_ids));
                $stmt->bind_param($types, ...$assigned_puskes_ids);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $total_data = (int)$row['total'];
                }
                $stmt->close();
            }
        }
    } catch (Exception $e) {
        error_log("Error counting total data for pagination: " . $e->getMessage());
    }
}

// Hitung total halaman
$total_pages = ceil($total_data / $limit);

// === Ambil data pengajuan dengan status 'diajukan' untuk halaman saat ini ===
$submissions = [];

if (!empty($assigned_puskes_ids)) {
    try {
        // Jika operator mengelola semua puskesmas
        if ($total_puskes_assigned == 19 || $total_puskes_assigned >= $total_puskes_system) {
            $query = "SELECT ak.*, u.nama AS nama_user,
                             u.email AS email_user, u.no_hp AS telp_user,
                             p.nama_puskes, kec.nama_kecamatan,
                             DATE_FORMAT(ak.created_at, '%d %M %Y %H:%i') as formatted_date
                      FROM `$tbl` ak 
                      LEFT JOIN tb_users u ON ak.id_user = u.id
                      LEFT JOIN tb_puskes p ON ak.id_puskes = p.id_puskes
                      LEFT JOIN tb_kecamatan kec ON p.id_kecamatan = kec.id
                      WHERE ak.status = 'diajukan'
                      AND ak.id_puskes IS NOT NULL
                      ORDER BY ak.created_at DESC 
                      LIMIT ? OFFSET ?";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                $stmt->bind_param("ii", $limit, $offset);
                $stmt->execute();
                $res = $stmt->get_result();
                
                while ($r = $res->fetch_assoc()) {
                    $r['jenis_layanan'] = 'Akta Kelahiran AHLAK';
                    $r['table'] = $tbl;
                    $submissions[] = $r;
                }
                $stmt->close();
            }
        } else {
            // Query dengan filter multiple puskesmas
            $placeholders = implode(',', array_fill(0, count($assigned_puskes_ids), '?'));
            $query = "SELECT ak.*, u.nama AS nama_user,
                             u.email AS email_user, u.no_hp AS telp_user,
                             p.nama_puskes, kec.nama_kecamatan,
                             DATE_FORMAT(ak.created_at, '%d %M %Y %H:%i') as formatted_date
                      FROM `$tbl` ak 
                      LEFT JOIN tb_users u ON ak.id_user = u.id
                      LEFT JOIN tb_puskes p ON ak.id_puskes = p.id_puskes
                      LEFT JOIN tb_kecamatan kec ON p.id_kecamatan = kec.id
                      WHERE ak.status = 'diajukan' 
                      AND ak.id_puskes IN ($placeholders)
                      ORDER BY ak.created_at DESC 
                      LIMIT ? OFFSET ?";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                // Gabungkan parameter: dulu puskesmas IDs, lalu limit dan offset
                $types = str_repeat('i', count($assigned_puskes_ids)) . 'ii';
                $params = array_merge($assigned_puskes_ids, [$limit, $offset]);
                
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                
                while ($r = $res->fetch_assoc()) {
                    $r['jenis_layanan'] = 'Akta Kelahiran AHLAK';
                    $r['table'] = $tbl;
                    $submissions[] = $r;
                }
                $stmt->close();
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching submissions: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Operator AHLAK</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/ahlak.png">

    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Operator AHLAK</div>
        <nav>
            <ul>
                <li class="active"><a href="ahlak_dashboard_operator.php"></i> Dashboard</a></li>
                <li><a href="ahlak_proses_pengajuan.php"> Proses Pengajuan</a></li>                
                <li><a href="ahlak_riwayat_operator.php"> Riwayat</a></li>
                <li><a href="ahlak_dokumen_belum_diterima.php"> Dokumen Belum Diterima</a></li>
                <li id="logoutBtn"> Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Dashboard Operator AHLAK</div>
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
            <!-- Flash Messages -->
            <?php if ($flash_success): ?>
                <div class="toast success" id="successToast">
                    <span class="toast-icon">✓</span>
                    <span><?= esc($flash_success) ?></span>
                </div>
                <script>
                    setTimeout(() => {
                        const toast = document.getElementById('successToast');
                        toast.classList.add('show');
                        setTimeout(() => toast.classList.remove('show'), 4000);
                    }, 100);
                </script>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="toast error" id="errorToast">
                    <span class="toast-icon">!</span>
                    <span><?= esc($flash_error) ?></span>
                </div>
                <script>
                    setTimeout(() => {
                        const toast = document.getElementById('errorToast');
                        toast.classList.add('show');
                        setTimeout(() => toast.classList.remove('show'), 5000);
                    }, 100);
                </script>
            <?php endif; ?>

            <!-- Welcome Message -->
            <div class="welcome-box">
                <h1>Selamat Datang, <?= esc($_SESSION['nama']) ?>!</h1>
                <p>
                    Anda login sebagai Operator AHLAK dengan tugas di <?= esc($wilayah_tugas) ?>.
                    Kelola semua permohonan Akta Kelahiran AHLAK dari puskesmas yang ditugaskan kepada Anda.
                </p>
                <?php if (!empty($assigned_puskes_data)): ?>
                <p><small>
                    <?php if ($total_puskes_assigned == 19 || $total_puskes_assigned >= $total_puskes_system): ?>
                        <strong>Anda mengelola: Semua Puskesmas</strong>
                    <?php elseif ($total_puskes_assigned < 5): ?>
                        Puskesmas: <?= esc($puskes_list_for_display) ?>
                    <?php else: ?>
                        Jumlah puskesmas yang Anda kelola: <?= $total_puskes_assigned ?>
                    <?php endif; ?>
                </small></p>
                <?php endif; ?>
            </div>

            <!-- Puskesmas Info Box -->
            <?php if (!empty($assigned_puskes_data)): ?>
            <div class="puskes-info-box">
                <div class="puskes-info-header">
                    <div class="puskes-info-icon">🏥</div>
                    <div class="puskes-info-content">
                        <h3>Informasi Puskesmas Tugas</h3>
                        <p>
                            <?php if ($total_puskes_assigned == 19 || $total_puskes_assigned >= $total_puskes_system): ?>
                                Anda bertanggung jawab atas <strong>Semua Puskesmas</strong> di sistem ini.
                            <?php elseif ($total_puskes_assigned < 5): ?>
                                Anda bertanggung jawab atas: <strong><?= esc($puskes_list_for_display) ?></strong>
                            <?php else: ?>
                                Anda bertanggung jawab atas <strong><?= $total_puskes_assigned ?> Puskesmas</strong>.
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

            <!-- Stats Grid - TOTAL AKTA KELAHIRAN AHLAK -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #0f766e; margin-bottom: 20px;">
                📊 Statistik Permohonan Akta Kelahiran AHLAK
            </h2>
            <div class="stats-grid">
                <div class="stat-card total">
                    <h3>Total Semua Permohonan</h3>
                    <div class="count"><?= $stats['total'] ?></div>
                </div>
                <div class="stat-card diajukan">
                    <h3>Menunggu Verifikasi</h3>
                    <div class="count"><?= $stats['diajukan'] ?></div>
                    <div class="detail">Status: Diajukan</div>
                </div>
                <div class="stat-card diproses">
                    <h3>Sedang Diproses</h3>
                    <div class="count"><?= $stats['diproses'] ?></div>
                    <div class="detail">Status: Diproses</div>
                </div>
                <div class="stat-card ditolak">
                    <h3>Ditolak</h3>
                    <div class="count"><?= $stats['ditolak'] ?></div>
                    <div class="detail">Status: Ditolak</div>
                </div>
                <div class="stat-card selesai">
                    <h3>Selesai</h3>
                    <div class="count"><?= $stats['selesai'] ?></div>
                    <div class="detail">Status: Selesai</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #0f766e; margin-bottom: 20px; margin-top: 40px;">
                ⚡ Aksi Cepat Operator AHLAK
            </h2>
            <div class="quick-actions">
                <div class="action-card">
                    <h3>Verifikasi Akta Kelahiran Baru</h3>
                    <p>Verifikasi dan proses permohonan Akta Kelahiran AHLAK yang baru diajukan</p>
                    <a href="ahlak_proses_pengajuan.php" class="btn btn-success">Verifikasi Sekarang</a>
                </div>
                <div class="action-card">
                    <h3>Riwayat Pengajuan</h3>
                    <p>Lihat riwayat lengkap semua pengajuan Akta Kelahiran AHLAK yang telah selesai</p>
                    <a href="ahlak_riwayat_operator.php" class="btn btn-primary">Lihat Semua</a>
                </div>
            </div>

            <!-- Pengajuan Terbaru (Hanya status diajukan) dengan Pagination -->
            <div class="recent-section">
                <h2>Pengajuan Akta Kelahiran AHLAK Terbaru (Status: Diajukan)</h2>
                <?php if (empty($submissions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-baby"></i>
                        <h4>Tidak ada pengajuan</h4>
                        <p>Tidak ada pengajuan Akta Kelahiran AHLAK dengan status 'diajukan' di wilayah tugas Anda.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal Ajuan</th>
                                    <th>Nama Bayi</th>
                                    <th>Nama Ayah</th>
                                    <th>Puskesmas</th>
                                    <th>Tanggal Lahir</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = ($page - 1) * $limit + 1;
                                foreach ($submissions as $r): 
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= esc($r['formatted_date']) ?: ($r['created_at'] ? date('d M Y H:i', strtotime($r['created_at'])) : '-') ?></td>
                                        <td><strong><?= esc($r['nama_bayi'] ?? '-') ?></strong></td>
                                        <td><?= esc($r['nama_ayah'] ?? '-') ?></td>
                                        <td><?= esc($r['nama_puskes'] ?? '-') ?></td>
                                        <td><?= $r['tanggal_lahir'] ? date('d/m/Y', strtotime($r['tanggal_lahir'])) : '-' ?></td>
                                        <td>
                                            <span class="status-badge status-diajukan">
                                                <?= strtoupper($r['status']) ?>
                                            </span>
                                        </td>
                                        <td class="action-column">
                                            <a href="ahlak_proses_pengajuan.php?id=<?= $r['id'] ?>" 
                                               class="btn-action">
                                                Proses
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Menampilkan <?= count($submissions) ?> dari <?= $total_data ?> data
                            (Halaman <?= $page ?> dari <?= $total_pages ?>)
                        </div>
                        
                        <div class="page-controls">
                            <!-- Previous Button -->
                            <a href="?page=<?= $page - 1 ?>" class="pagination-button <?= $page <= 1 ? 'disabled' : '' ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            
                            <!-- Page Numbers -->
                            <div class="page-numbers">
                                <?php
                                // Tampilkan maksimal 5 halaman di sekitar halaman aktif
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                // Jika halaman awal bukan 1, tambahkan ellipsis
                                if ($start_page > 1) {
                                    echo '<a href="?page=1" class="pagination-button">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span class="pagination-button disabled">...</span>';
                                    }
                                }
                                
                                // Tampilkan halaman-halaman
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    $active_class = $i == $page ? 'active' : '';
                                    echo '<a href="?page=' . $i . '" class="pagination-button ' . $active_class . '">' . $i . '</a>';
                                }
                                
                                // Jika halaman akhir bukan total halaman, tambahkan ellipsis
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span class="pagination-button disabled">...</span>';
                                    }
                                    echo '<a href="?page=' . $total_pages . '" class="pagination-button">' . $total_pages . '</a>';
                                }
                                ?>
                            </div>
                            
                            <!-- Next Button -->
                            <a href="?page=<?= $page + 1 ?>" class="pagination-button <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                <?php endif; ?>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <h3>📋 Panduan Operator AHLAK - Akta Kelahiran</h3>
                <p>1. Verifikasi kelengkapan dokumen Akta Kelahiran AHLAK sebelum memproses</p>
                <p>2. Pastikan data bayi dan orang tua sudah lengkap dan valid</p>
                <p>3. Periksa surat keterangan lahir dari bidan/dokter/puskesmas</p>
                <p>4. Pastikan KTP orang tua dan buku nikah sudah diverifikasi</p>
                <?php if ($total_puskes_assigned == 19 || $total_puskes_assigned >= $total_puskes_system): ?>
                    <p>5. Anda mengelola <strong>Semua Puskesmas</strong> - pastikan memproses dari puskesmas yang benar</p>
                <?php elseif ($total_puskes_assigned < 5): ?>
                    <p>5. Anda mengelola: <strong><?= esc($puskes_list_for_display) ?></strong></p>
                <?php else: ?>
                    <p>5. Anda mengelola <strong><?= $total_puskes_assigned ?> Puskesmas</strong></p>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3>Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout dari sistem Operator AHLAK?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
            <button id="cancelLogout" class="btn-no">Batal</button>
        </div>
    </div>
</div>

<script>
// Toggle Sidebar Menu on Mobile
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
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
            window.location.href = '../logout_pasutri_ahlak.php';
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
    
    // Animation for stat cards
    setTimeout(function() {
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach((card, index) => {
            setTimeout(() => {
                card.style.transform = 'translateY(0)';
                card.style.opacity = '1';
            }, index * 100);
        });
    }, 300);
    
    // Auto refresh data every 30 seconds (optional)
    setInterval(function() {
        console.log('Auto refresh dashboard AHLAK');
        // You can add AJAX call here to refresh data
    }, 30000);
});
</script>

</body>
</html>
