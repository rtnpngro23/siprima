<?php
require_once '../error_handler.php';
// Gunakan middleware khusus operator KUA
include '../../app/middleware/operator_only_kua.php';

// Debug
error_log("=== DASHBOARD OPERATOR KUA START ===");
error_log("Operator ID: " . $_SESSION['id_user']);
error_log("Operator Name: " . $_SESSION['nama']);
error_log("KUA IDs: " . (isset($_SESSION['kua_tugas']) ? implode(', ', $_SESSION['kua_tugas']) : 'NOT SET'));
error_log("Role: " . $_SESSION['role']);

// Koneksi database
include '../../app/config/koneksi.php';

// ============ AMBIL SEMUA KUA YANG DITUGASKAN KE OPERATOR ============
$assigned_kua_ids = [];
$assigned_kua_data = [];
$kua_list_for_display = '';
$total_kua_system = 0;

// 1. Ambil dari session kua_tugas (jika sudah ada)
if (isset($_SESSION['kua_tugas']) && !empty($_SESSION['kua_tugas'])) {
    $assigned_kua_ids = $_SESSION['kua_tugas'];
    error_log("Using kua_tugas from session: " . count($assigned_kua_ids) . " KUA");
}

// 2. Jika tidak ada di session, ambil dari database tb_operator_kecamatan atau tabel yang sesuai
if (empty($assigned_kua_ids)) {
    // Cek tabel yang ada untuk assignment KUA
    // Asumsi: mungkin ada tabel tb_operator_kua atau menggunakan field lain di tb_operator_kecamatan
    $sql = "SELECT kua_id FROM tb_operator_kecamatan 
            WHERE operator_id = ? AND kua_id IS NOT NULL
            ORDER BY kua_id";
    $stmt = $koneksi->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['id_user']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['kua_id'])) {
                $assigned_kua_ids[] = $row['kua_id'];
            }
        }
        $stmt->close();
        error_log("Found " . count($assigned_kua_ids) . " KUA from database");
        
        // Jika tidak ada, coba ambil dari id_kua di session
        if (empty($assigned_kua_ids) && isset($_SESSION['id_kua']) && !empty($_SESSION['id_kua'])) {
            $assigned_kua_ids = [$_SESSION['id_kua']];
            error_log("Using id_kua from session: " . $_SESSION['id_kua']);
        }
        
        // Simpan ke session untuk penggunaan berikutnya
        $_SESSION['kua_tugas'] = $assigned_kua_ids;
    }
}

// Hitung total KUA yang ada di sistem
$sql_total_kua = "SELECT COUNT(*) as total_kua FROM tb_kua";
$result_total = $koneksi->query($sql_total_kua);
if ($result_total && $row = $result_total->fetch_assoc()) {
    $total_kua_system = (int)$row['total_kua'];
}

// Ambil detail data untuk semua KUA yang ditugaskan
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
        }
        $stmt_detail->close();
        
        // Logika tampilan berdasarkan jumlah KUA
        $total_kua_assigned = count($assigned_kua_data);
        
        // CEK: Jika jumlah KUA yang ditugaskan = 19 atau >= total KUA sistem
        if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system) {
            $kua_list_for_display = "Semua KUA";
            $wilayah_tugas = "Semua KUA";
        } 
        // Jika jumlah KUA yang ditugaskan < 5, tampilkan nama-nama KUA
        elseif ($total_kua_assigned < 5) {
            $kua_names = array_map(function($kua) {
                return $kua['nama_kua'] . ' (' . ($kua['nama_kecamatan'] ?? '-') . ')';
            }, $assigned_kua_data);
            
            $kua_list_for_display = implode(', ', $kua_names);
            $wilayah_tugas = $total_kua_assigned . " KUA: " . $kua_list_for_display;
        }
        // Jika 5 <= jumlah KUA < 19, tampilkan jumlahnya saja
        else {
            $kua_list_for_display = $total_kua_assigned . " KUA";
            $wilayah_tugas = $total_kua_assigned . " KUA";
        }
        
        // Update session dengan nama KUA
        if (!isset($_SESSION['kua_nama']) || empty($_SESSION['kua_nama'])) {
            $_SESSION['kua_nama'] = array_column($assigned_kua_data, 'nama_kua');
        }
        
        // Set KUA pertama sebagai default jika session id_kua belum ada
        if (!isset($_SESSION['id_kua']) && !empty($assigned_kua_data)) {
            $_SESSION['id_kua'] = $assigned_kua_data[0]['id_kua'];
            $_SESSION['nama_kua'] = $assigned_kua_data[0]['nama_kua'];
        }
    }
} else {
    $wilayah_tugas = 'Tidak ada KUA ditugaskan';
    error_log("Operator ID " . $_SESSION['id_user'] . " tidak memiliki KUA terkait");
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

// ============ HITUNG STATISTIK DARI tb_kartukeluarga_kua ============
$stats = [
    'total' => 0,
    'diajukan' => 0,
    'diproses' => 0,
    'selesai' => 0,
    'ditolak' => 0
];

// Tabel yang digunakan untuk KUA
$tbl = 'tb_kartukeluarga_kua';

if (!empty($assigned_kua_ids)) {
    try {
        // Jika operator mengelola semua KUA (19 atau >= total sistem)
        if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system) {
            $query = "SELECT 
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
                        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
                        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
                      FROM `$tbl` 
                      WHERE id_kua IS NOT NULL";
            
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
            // Query dengan filter multiple KUA
            $placeholders = implode(',', array_fill(0, count($assigned_kua_ids), '?'));
            $query = "SELECT 
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
                        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
                        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
                      FROM `$tbl` 
                      WHERE id_kua IN ($placeholders)";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                $types = str_repeat('i', count($assigned_kua_ids));
                $stmt->bind_param($types, ...$assigned_kua_ids);
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

if (!empty($assigned_kua_ids)) {
    try {
        // Jika operator mengelola semua KUA
        if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system) {
            $query = "SELECT COUNT(*) as total FROM `$tbl` 
                      WHERE status = 'diajukan' 
                      AND id_kua IS NOT NULL";
            
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
            $placeholders = implode(',', array_fill(0, count($assigned_kua_ids), '?'));
            $query = "SELECT COUNT(*) as total FROM `$tbl` 
                      WHERE status = 'diajukan' 
                      AND id_kua IN ($placeholders)";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                $types = str_repeat('i', count($assigned_kua_ids));
                $stmt->bind_param($types, ...$assigned_kua_ids);
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

if (!empty($assigned_kua_ids)) {
    try {
        // Jika operator mengelola semua KUA
        if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system) {
            $query = "SELECT kk.*, u.nama AS nama_user,
                             u.email AS email_user, u.no_hp AS telp_user,
                             k.nama_kua, kec.nama_kecamatan, d.nama_desa,
                             DATE_FORMAT(kk.created_at, '%d %M %Y %H:%i') as formatted_date
                      FROM `$tbl` kk 
                      LEFT JOIN tb_users u ON kk.id_user = u.id
                      LEFT JOIN tb_kua k ON kk.id_kua = k.id_kua
                      LEFT JOIN tb_kecamatan kec ON kk.id_kecamatan = kec.id
                      LEFT JOIN tb_desa d ON kk.id_desa = d.id
                      WHERE kk.status = 'diajukan'
                      AND kk.id_kua IS NOT NULL
                      ORDER BY kk.created_at DESC 
                      LIMIT ? OFFSET ?";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                $stmt->bind_param("ii", $limit, $offset);
                $stmt->execute();
                $res = $stmt->get_result();
                
                while ($r = $res->fetch_assoc()) {
                    $r['jenis_layanan'] = 'Kartu Keluarga';
                    $r['table'] = $tbl;
                    $submissions[] = $r;
                }
                $stmt->close();
            }
        } else {
            // Query dengan filter multiple KUA
            $placeholders = implode(',', array_fill(0, count($assigned_kua_ids), '?'));
            $query = "SELECT kk.*, u.nama AS nama_user,
                             u.email AS email_user, u.no_hp AS telp_user,
                             k.nama_kua, kec.nama_kecamatan, d.nama_desa,
                             DATE_FORMAT(kk.created_at, '%d %M %Y %H:%i') as formatted_date
                      FROM `$tbl` kk 
                      LEFT JOIN tb_users u ON kk.id_user = u.id
                      LEFT JOIN tb_kua k ON kk.id_kua = k.id_kua
                      LEFT JOIN tb_kecamatan kec ON kk.id_kecamatan = kec.id
                      LEFT JOIN tb_desa d ON kk.id_desa = d.id
                      WHERE kk.status = 'diajukan' 
                      AND kk.id_kua IN ($placeholders)
                      ORDER BY kk.created_at DESC 
                      LIMIT ? OFFSET ?";
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                // Gabungkan parameter: dulu KUA IDs, lalu limit dan offset
                $types = str_repeat('i', count($assigned_kua_ids)) . 'ii';
                $params = array_merge($assigned_kua_ids, [$limit, $offset]);
                
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                
                while ($r = $res->fetch_assoc()) {
                    $r['jenis_layanan'] = 'Kartu Keluarga';
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
    <title>Dashboard Operator PASUTRI</title>
    <link rel="stylesheet" href="../admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/kua_dashboard_operator.css">
    <link rel="icon" href="../assets/PASUTRI.png" type="image/png">
   
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Operator PASUTRI</div>
        <nav>
            <ul>
                <li class="active"><a href="kua_dashboard_operator.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="kua_proses_pengajuan.php"><i class="fas fa-cog"></i> Proses Pengajuan</a></li>               
                <li><a href="kua_riwayat_operator.php"><i class="fas fa-history"></i> Riwayat</a></li>
                <li><a href="kua_dokumen_belum_diterima.php"><i class="fas fa-envelope-open-text"></i> Dokumen Belum Diterima</a></li> 
                <li id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Dashboard Operator PASUTRI</div>
                <div class="subtitle">Wilayah Tugas: <?= esc($wilayah_tugas) ?></div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama']) ?></span>
                <span class="role-badge">OPERATOR PASUTRI</span>
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
                    Anda login sebagai Operator PASUTRI dengan tugas di <?= esc($wilayah_tugas) ?>.
                    Kelola semua permohonan Kartu Keluarga dari KUA yang ditugaskan kepada Anda.
                </p>
                <?php if (!empty($assigned_kua_data)): ?>
                <p><small>
                    <?php if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system): ?>
                        <strong>Anda mengelola: Semua KUA</strong>
                    <?php elseif ($total_kua_assigned < 5): ?>
                        KUA: <?= esc($kua_list_for_display) ?>
                    <?php else: ?>
                        Jumlah KUA yang Anda kelola: <?= $total_kua_assigned ?>
                    <?php endif; ?>
                </small></p>
                <?php endif; ?>
            </div>

            <!-- KUA Info Box -->
            <?php if (!empty($assigned_kua_data)): ?>
            <div class="kua-info-box">
                <div class="kua-info-header">
                    <div class="kua-info-icon">🏛️</div>
                    <div class="kua-info-content">
                        <h3>Informasi KUA Tugas</h3>
                        <p>
                            <?php if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system): ?>
                                Anda bertanggung jawab atas <strong>Semua KUA</strong> di sistem ini.
                            <?php elseif ($total_kua_assigned < 5): ?>
                                Anda bertanggung jawab atas: <strong><?= esc($kua_list_for_display) ?></strong>
                            <?php else: ?>
                                Anda bertanggung jawab atas <strong><?= $total_kua_assigned ?> KUA</strong>.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="kua-info-box" style="border-left-color: #ef4444;">
                <div class="kua-info-header">
                    <div class="kua-info-icon">⚠️</div>
                    <div class="kua-info-content">
                        <h3>Informasi KUA Tugas</h3>
                        <p>Anda belum ditugaskan ke KUA manapun. Hubungi administrator.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats Grid - Kartu Keluarga KUA -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #1e40af; margin-bottom: 20px;">
                📊 Statistik Permohonan Kartu Keluarga KUA
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
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #1e40af; margin-bottom: 20px; margin-top: 40px;">
                ⚡ Aksi Cepat Operator PASUTRI
            </h2>
            <div class="quick-actions">
                <div class="action-card">
                    <h3>Verifikasi Permohonan Baru</h3>
                    <p>Verifikasi dan proses permohonan Kartu Keluarga yang baru diajukan</p>
                    <a href="proses_pengajuan.php" class="btn btn-success">Verifikasi Sekarang</a>
                </div>
                <div class="action-card">
                    <h3>Riwayat Pengajuan</h3>
                    <p>Lihat riwayat lengkap semua pengajuan Kartu Keluarga yang telah selesai</p>
                    <a href="riwayat_operator.php" class="btn btn-primary">Lihat Semua</a>
                </div>
                <div class="action-card">
                    <h3>Pelaporan</h3>
                    <p>Lihat laporan statistik permohonan Kartu Keluarga</p>
                    <a href="laporan_kua.php" class="btn btn-purple">Lihat Laporan</a>
                </div>
            </div>

            <!-- Pengajuan Terbaru (Hanya status diajukan) dengan Pagination -->
            <div class="recent-section">
                <h2>Pengajuan Kartu Keluarga KUA Terbaru (Status: Diajukan)</h2>
                <?php if (empty($submissions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h4>Tidak ada pengajuan</h4>
                        <p>Tidak ada pengajuan Kartu Keluarga dengan status 'diajukan' di wilayah tugas Anda.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal Ajuan</th>
                                    <th>Nama Pemohon</th>
                                    <th>NIK</th>
                                    <th>KUA</th>
                                    <th>Alamat</th>
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
                                        <td><strong><?= esc($r['nama_pemohon'] ?? '-') ?></strong></td>
                                        <td><?= esc($r['nik_pemohon'] ?? '-') ?></td>
                                        <td><?= esc($r['nama_kua'] ?? '-') ?></td>
                                        <td>
                                            <?= esc($r['nama_desa'] ?? '-') ?>, <?= esc($r['nama_kecamatan'] ?? '-') ?>
                                            <?php if (!empty($r['dusun'])): ?>
                                                <br><small>Dusun: <?= esc($r['dusun']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $status_class = '';
                                            switch($r['status']) {
                                                case 'diajukan': $status_class = 'status-diajukan'; break;
                                                case 'diproses': $status_class = 'status-diproses'; break;
                                                case 'selesai': $status_class = 'status-selesai'; break;
                                                case 'ditolak': $status_class = 'status-ditolak'; break;
                                                default: $status_class = 'status-diajukan';
                                            }
                                            ?>
                                            <span class="status-badge <?= $status_class ?>">
                                                <?= strtoupper($r['status']) ?>
                                            </span>
                                        </td>
                                        <td class="action-column">
                                            <a href="kua_proses_pengajuan.php?id=<?= $r['id'] ?>" 
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
                <h3>📋 Panduan Operator PASUTRI</h3>
                <p>1. Verifikasi kelengkapan dokumen permohonan Kartu Keluarga sebelum memproses</p>
                <p>2. Pastikan data pemohon dan anggota keluarga sudah lengkap dan valid</p>
                <p>3. Periksa syarat-syarat administrasi seperti KTP dan KK lama</p>
                <p>4. Pastikan alamat pemohon sesuai dengan wilayah KUA yang bersangkutan</p>
                <?php if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system): ?>
                    <p>5. Anda mengelola <strong>Semua KUA</strong> - pastikan memproses dari KUA yang benar</p>
                <?php elseif ($total_kua_assigned < 5): ?>
                    <p>5. Anda mengelola: <strong><?= esc($kua_list_for_display) ?></strong></p>
                <?php else: ?>
                    <p>5. Anda mengelola <strong><?= $total_kua_assigned ?> KUA</strong></p>
                <?php endif; ?>
                <p>6. Waktu maksimal proses: 3 hari kerja setelah dokumen lengkap</p>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3>Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout dari sistem Operator KUA?</p>
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
        console.log('Auto refresh dashboard KUA');
        // You can add AJAX call here to refresh data
    }, 30000);
});
</script>

</body>
</html>