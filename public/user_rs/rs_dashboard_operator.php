<?php
require_once '../error_handler.php';
// Gunakan middleware khusus operator rumah sakit
include '../../app/middleware/operator_rs_only.php';
include '../../app/config/koneksi.php';

// Ambil data operator dan semua RS yang ditugaskan
$operator_id = $_SESSION['id_user'];
$rs_list = $_SESSION['operator_rs'] ?? [];

// Jika tidak ada RS list dalam session, ambil dari database
if (empty($rs_list)) {
    $query = $koneksi->prepare("
        SELECT DISTINCT rs.id_rs, rs.nama_rs
        FROM tb_operator_kecamatan ok
        JOIN tb_rumahsakit rs ON ok.rs_id = rs.id_rs
        WHERE ok.operator_id = ? AND ok.rs_id IS NOT NULL
        ORDER BY rs.nama_rs
    ");
    
    if ($query) {
        $query->bind_param("i", $operator_id);
        $query->execute();
        $result = $query->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $rs_list[] = [
                'id_rs' => $row['id_rs'],
                'nama_rs' => $row['nama_rs']
            ];
        }
        $query->close();
    }
    
    // Simpan ke session untuk penggunaan selanjutnya
    $_SESSION['operator_rs'] = $rs_list;
}

// Hitung total statistik untuk SEMUA rumah sakit
$total_all_permohonan = 0;
$total_all_diajukan = 0;
$total_all_diproses = 0;
$total_all_selesai = 0;
$total_all_ditolak = 0;

// Statistik per rumah sakit
$stats_per_rs = [];

foreach ($rs_list as $rs) {
    $id_rs = $rs['id_rs'];
    $nama_rs = $rs['nama_rs'];
    
    // Hitung statistik permohonan akta kelahiran untuk rumah sakit ini
    $stats_kelahiran = [
        'total' => 0,
        'diajukan' => 0,
        'diproses' => 0,
        'selesai' => 0,
        'ditolak' => 0
    ];
    
    $query_kelahiran = $koneksi->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(status = 'diajukan'), 0) as diajukan,
            COALESCE(SUM(status = 'diproses'), 0) as diproses,
            COALESCE(SUM(status = 'selesai'), 0) as selesai,
            COALESCE(SUM(status = 'ditolak'), 0) as ditolak
        FROM tb_akta_kelahiran_rs 
        WHERE id_rs = ?
    ");
    
    if ($query_kelahiran) {
        $query_kelahiran->bind_param("i", $id_rs);
        $query_kelahiran->execute();
        $result_kelahiran = $query_kelahiran->get_result();
        
        if ($data = $result_kelahiran->fetch_assoc()) {
            $stats_kelahiran['total'] = (int)$data['total'];
            $stats_kelahiran['diajukan'] = (int)$data['diajukan'];
            $stats_kelahiran['diproses'] = (int)$data['diproses'];
            $stats_kelahiran['selesai'] = (int)$data['selesai'];
            $stats_kelahiran['ditolak'] = (int)$data['ditolak'];
        }
        $query_kelahiran->close();
    }
    
    // Hitung statistik permohonan akta kematian untuk rumah sakit ini
    $stats_kematian = [
        'total' => 0,
        'diajukan' => 0,
        'diproses' => 0,
        'selesai' => 0,
        'ditolak' => 0
    ];
    
    $query_kematian = $koneksi->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(status = 'diajukan'), 0) as diajukan,
            COALESCE(SUM(status = 'diproses'), 0) as diproses,
            COALESCE(SUM(status = 'selesai'), 0) as selesai,
            COALESCE(SUM(status = 'ditolak'), 0) as ditolak
        FROM tb_akta_kematian_rs 
        WHERE id_rs = ?
    ");
    
    if ($query_kematian) {
        $query_kematian->bind_param("i", $id_rs);
        $query_kematian->execute();
        $result_kematian = $query_kematian->get_result();
        
        if ($data = $result_kematian->fetch_assoc()) {
            $stats_kematian['total'] = (int)$data['total'];
            $stats_kematian['diajukan'] = (int)$data['diajukan'];
            $stats_kematian['diproses'] = (int)$data['diproses'];
            $stats_kematian['selesai'] = (int)$data['selesai'];
            $stats_kematian['ditolak'] = (int)$data['ditolak'];
        }
        $query_kematian->close();
    }
    
    // Hitung total untuk rumah sakit ini
    $total_rs = $stats_kelahiran['total'] + $stats_kematian['total'];
    $total_diajukan_rs = $stats_kelahiran['diajukan'] + $stats_kematian['diajukan'];
    $total_diproses_rs = $stats_kelahiran['diproses'] + $stats_kematian['diproses'];
    $total_selesai_rs = $stats_kelahiran['selesai'] + $stats_kematian['selesai'];
    $total_ditolak_rs = $stats_kelahiran['ditolak'] + $stats_kematian['ditolak'];
    
    // Simpan statistik per RS
    $stats_per_rs[] = [
        'id_rs' => $id_rs,
        'nama_rs' => $nama_rs,
        'total' => $total_rs,
        'diajukan' => $total_diajukan_rs,
        'diproses' => $total_diproses_rs,
        'selesai' => $total_selesai_rs,
        'ditolak' => $total_ditolak_rs,
        'kelahiran' => $stats_kelahiran,
        'kematian' => $stats_kematian
    ];
    
    // Tambahkan ke total keseluruhan
    $total_all_permohonan += $total_rs;
    $total_all_diajukan += $total_diajukan_rs;
    $total_all_diproses += $total_diproses_rs;
    $total_all_selesai += $total_selesai_rs;
    $total_all_ditolak += $total_ditolak_rs;
}

// Ambil 10 permohonan terbaru dari SEMUA rumah sakit
$recent_permohonan = [];
$rs_ids = array_column($rs_list, 'id_rs');

if (!empty($rs_ids)) {
    // Fix: Buat query dengan cara yang lebih aman
    if (count($rs_ids) === 1) {
        // Jika hanya 1 RS, gunakan query sederhana
        $id_rs = $rs_ids[0];
        $query_recent = $koneksi->prepare("
            (
                SELECT 
                    'kelahiran' as jenis,
                    id,
                    nama_pemohon,
                    nama_bayi,
                    status,
                    created_at,
                    id_rs,
                    (SELECT nama_rs FROM tb_rumahsakit WHERE id_rs = t.id_rs) as nama_rs
                FROM tb_akta_kelahiran_rs t
                WHERE id_rs = ?
                ORDER BY created_at DESC
                LIMIT 5
            )
            UNION ALL
            (
                SELECT 
                    'kematian' as jenis,
                    id,
                    nama_pemohon,
                    nama_meninggal as nama_bayi,
                    status,
                    created_at,
                    id_rs,
                    (SELECT nama_rs FROM tb_rumahsakit WHERE id_rs = t.id_rs) as nama_rs
                FROM tb_akta_kematian_rs t
                WHERE id_rs = ?
                ORDER BY created_at DESC
                LIMIT 5
            )
            ORDER BY created_at DESC
            LIMIT 10
        ");
        
        if ($query_recent) {
            $query_recent->bind_param("ii", $id_rs, $id_rs);
            $query_recent->execute();
            $result_recent = $query_recent->get_result();
            
            while ($row = $result_recent->fetch_assoc()) {
                $recent_permohonan[] = $row;
            }
            $query_recent->close();
        }
    } else {
        // Jika multi RS, buat dynamic query
        $placeholders = implode(',', array_fill(0, count($rs_ids), '?'));
        $types = str_repeat('i', count($rs_ids));
        
        $query_recent = $koneksi->prepare("
            (
                SELECT 
                    'kelahiran' as jenis,
                    id,
                    nama_pemohon,
                    nama_bayi,
                    status,
                    created_at,
                    id_rs,
                    (SELECT nama_rs FROM tb_rumahsakit WHERE id_rs = t.id_rs) as nama_rs
                FROM tb_akta_kelahiran_rs t
                WHERE id_rs IN ($placeholders)
                ORDER BY created_at DESC
                LIMIT 5
            )
            UNION ALL
            (
                SELECT 
                    'kematian' as jenis,
                    id,
                    nama_pemohon,
                    nama_meninggal as nama_bayi,
                    status,
                    created_at,
                    id_rs,
                    (SELECT nama_rs FROM tb_rumahsakit WHERE id_rs = t.id_rs) as nama_rs
                FROM tb_akta_kematian_rs t
                WHERE id_rs IN ($placeholders)
                ORDER BY created_at DESC
                LIMIT 5
            )
            ORDER BY created_at DESC
            LIMIT 10
        ");
        
        if ($query_recent) {
            // Bind parameters untuk IN clause (2 kali karena ada 2 subquery)
            $bind_params = array_merge([$types . $types], $rs_ids, $rs_ids);
            foreach ($bind_params as $key => $value) {
                if ($key > 0) { // Skip type string
                    $bind_params[$key] = &$bind_params[$key];
                }
            }
            call_user_func_array([$query_recent, 'bind_param'], $bind_params);
            
            $query_recent->execute();
            $result_recent = $query_recent->get_result();
            
            while ($row = $result_recent->fetch_assoc()) {
                $recent_permohonan[] = $row;
            }
            $query_recent->close();
        }
    }
}

// Tentukan jumlah RS untuk ditampilkan
$rs_count = count($rs_list);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Operator - <?= $rs_count > 1 ? "Multi Rumah Sakit" : htmlspecialchars($rs_list[0]['nama_rs'] ?? 'Rumah Sakit') ?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/rs_dashboard_operator.css">
    <link rel="icon" href="../assets/st sehati.png" type="image/png">
    
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Operator Dukcapil <br> ST SEHATI</div>
        <nav>
            <ul>
                <li class="active"><a href="rs_dashboard_operator.php">Dashboard</a></li>
                <li><a href="rs_proses_pengajuan.php">Proses Pengajuan</a></li>
                <li><a href="rs_riwayat_operator.php">Riwayat</a></li>
                <li><a href="rs_dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Dashboard</div>
                <div class="subtitle">
                    <?php if ($rs_count > 1): ?>
                        Mengelola <?= $rs_count ?> Rumah Sakit
                        <span class="rs-count-badge"><?= $rs_count ?> RS</span>
                    <?php else: ?>
                        <?= htmlspecialchars($rs_list[0]['nama_rs'] ?? 'Rumah Sakit') ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['nama'] ?? 'Operator') ?></span>
                <span class="role-badge">OPERATOR</span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Welcome Message -->
            <div class="welcome-box">
                <h1>
                    <?php if ($rs_count > 1): ?>
                        Selamat Datang, Operator ST SEHATI
                    <?php else: ?>
                        Selamat Datang <?= htmlspecialchars($rs_list[0]['nama_rs'] ?? 'Rumah Sakit') ?>
                    <?php endif; ?>
                </h1>
                <p>
                    <?php if ($rs_count > 1): ?>
                        Anda mengelola <?= $rs_count ?> rumah sakit. Pantau dan kelola semua permohonan akta kelahiran dan kematian dari seluruh rumah sakit yang ditugaskan kepada Anda.
                    <?php else: ?>
                        Kelola permohonan akta kelahiran dan kematian untuk rumah sakit ini. Pantau status permohonan dan lakukan verifikasi dengan mudah.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Stats Grid (TOTAL SEMUA RS) -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #8e44ad; margin-bottom: 20px;">
                <i class="fas fa-chart-bar"></i> Statistik Keseluruhan
                <?php if ($rs_count > 1): ?>
                    <span style="font-size: 0.9rem; color: #666; font-weight: normal;">
                        (Semua <?= $rs_count ?> Rumah Sakit)
                    </span>
                <?php endif; ?>
            </h2>
            <div class="stats-grid">
                <div class="stat-card total">
                    <h3>Total Semua Permohonan</h3>
                    <p><?= $total_all_permohonan ?></p>
                    <div style="font-size: 0.9rem; color: #666; margin-top: 5px;">
                        <?php if ($rs_count > 1): ?>
                            <?= $rs_count ?> RS
                        <?php endif; ?>
                    </div>
                </div>
                <div class="stat-card diajukan">
                    <h3>Diajukan</h3>
                    <p><?= $total_all_diajukan ?></p>
                </div>
                <div class="stat-card diproses">
                    <h3>Diproses</h3>
                    <p><?= $total_all_diproses ?></p>
                </div>
                <div class="stat-card ditolak">
                    <h3>Ditolak</h3>
                    <p><?= $total_all_ditolak ?></p>
                </div>
                <div class="stat-card selesai">
                    <h3>Selesai</h3>
                    <p><?= $total_all_selesai ?></p>
                </div>
            </div>

            <!-- RS List Section (jika multi RS) -->
            <?php if ($rs_count > 1): ?>
            <div class="rs-list-section">
                <h3><i class="fas fa-hospital-alt"></i> Daftar Rumah Sakit yang Dikelola</h3>
                <div class="rs-grid">
                    <?php foreach ($stats_per_rs as $rs_stat): ?>
                        <div class="rs-card">
                            <div class="rs-card-header">
                                <div class="rs-name">
                                    <i class="fas fa-hospital"></i>
                                    <?= htmlspecialchars($rs_stat['nama_rs']) ?>
                                </div>                                
                            </div>
                            
                            <div class="rs-stats">
                                <div class="rs-stat-item">
                                    <div class="rs-stat-label">Total</div>
                                    <div class="rs-stat-value"><?= $rs_stat['total'] ?></div>
                                </div>
                                <div class="rs-stat-item">
                                    <div class="rs-stat-label">Diajukan</div>
                                    <div class="rs-stat-value"><?= $rs_stat['diajukan'] ?></div>
                                </div>
                                <div class="rs-stat-item">
                                    <div class="rs-stat-label">Diproses</div>
                                    <div class="rs-stat-value"><?= $rs_stat['diproses'] ?></div>
                                </div>
                                <div class="rs-stat-item">
                                    <div class="rs-stat-label">Selesai</div>
                                    <div class="rs-stat-value"><?= $rs_stat['selesai'] ?></div>
                                </div>
                                <div class="rs-stat-item">
                                    <div class="rs-stat-label">Ditolak</div>
                                    <div class="rs-stat-value"><?= $rs_stat['ditolak'] ?></div>
                                </div>
                            </div>
                            
                            <div class="rs-detail-stats">
                                <div class="detail-stat">
                                    <i class="fas fa-baby"></i> Kelahiran: 
                                    <span><?= $rs_stat['kelahiran']['total'] ?></span>
                                    (<?= $rs_stat['kelahiran']['diajukan'] ?> diajukan)
                                </div>
                                <div class="detail-stat">
                                    <i class="fas fa-scroll"></i> Kematian: 
                                    <span><?= $rs_stat['kematian']['total'] ?></span>
                                    (<?= $rs_stat['kematian']['diajukan'] ?> diajukan)
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #8e44ad; margin-bottom: 20px; margin-top: 40px;">
                <i class="fas fa-bolt"></i> Layanan Cepat
            </h2>
            <div class="quick-actions">
                <div class="action-card">
                    <h3>Verifikasi Dokumen</h3>
                    <p>Verifikasi kelengkapan dokumen permohonan kelahiran dan kematian.</p>
                    <a href="rs_proses_pengajuan.php" class="btn btn-blue">Mulai Verifikasi</a>
                </div>
                <div class="action-card">
                    <h3>Riwayat</h3>
                    <p>Lihat riwayat lengkap semua pengajuan yang telah selesai.</p>
                    <a href="rs_permohonan_kelahiran.php" class="btn btn-success">Kelola Kelahiran</a>
                </div>
                              
            </div>

            <!-- Recent Permohonan dari SEMUA RS -->
            <div class="recent-table">
                <h3><i class="fas fa-history"></i> Permohonan Terbaru 
                    <?php if ($rs_count > 1): ?>
                        <span style="font-size: 0.9rem; color: #666; font-weight: normal;">
                            (Dari Semua Rumah Sakit)
                        </span>
                    <?php else: ?>
                        <span style="font-size: 0.9rem; color: #666; font-weight: normal;">
                            - <?= htmlspecialchars($rs_list[0]['nama_rs'] ?? 'Rumah Sakit') ?>
                        </span>
                    <?php endif; ?>
                </h3>
                <?php if (empty($recent_permohonan)): ?>
                    <div style="text-align: center; padding: 40px; color: #6c757d;">
                        <i class="fas fa-inbox fa-3x" style="margin-bottom: 20px; color: #dee2e6;"></i>
                        <h4 style="color: #6c757d;">Belum ada permohonan</h4>
                        <p style="color: #6c757d;">Permohonan yang diajukan akan muncul di sini.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Jenis</th>
                                    <?php if ($rs_count > 1): ?>
                                        <th>Rumah Sakit</th>
                                    <?php endif; ?>
                                    <th>Nama Pemohon</th>
                                    <th>Nama Subjek</th>
                                    <th>Status</th>
                                    <th>Tanggal</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_permohonan as $permohonan): ?>
                                    <tr>
                                        <td>
                                            <?php if ($permohonan['jenis'] === 'kelahiran'): ?>
                                                <span style="background: #f4ecf7; color: #8e44ad; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                    <i class="fas fa-baby"></i> Kelahiran
                                                </span>
                                            <?php else: ?>
                                                <span style="background: #fdebd0; color: #d35400; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                    <i class="fas fa-scroll"></i> Kematian
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($rs_count > 1): ?>
                                            <td>
                                                <span class="rs-badge">
                                                    <i class="fas fa-hospital"></i>
                                                    <?= htmlspecialchars($permohonan['nama_rs'] ?? '-') ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($permohonan['nama_pemohon']) ?></td>
                                        <td><?= htmlspecialchars($permohonan['nama_bayi']) ?></td>
                                        <td>
                                            <?php 
                                            $status = $permohonan['status'];
                                            $badge_class = '';
                                            $badge_text = '';
                                            
                                            switch ($status) {
                                                case 'diajukan':
                                                    $badge_class = 'badge-diajukan';
                                                    $badge_text = 'Diajukan';
                                                    break;
                                                case 'diproses':
                                                    $badge_class = 'badge-diproses';
                                                    $badge_text = 'Diproses';
                                                    break;
                                                case 'selesai':
                                                    $badge_class = 'badge-selesai';
                                                    $badge_text = 'Selesai';
                                                    break;
                                                case 'ditolak':
                                                    $badge_class = 'badge-ditolak';
                                                    $badge_text = 'Ditolak';
                                                    break;
                                                default:
                                                    $badge_class = 'badge-diajukan';
                                                    $badge_text = $status;
                                            }
                                            ?>
                                            <span class="badge <?= $badge_class ?>"><?= $badge_text ?></span>
                                        </td>
                                        <td><?= date('d/m/Y H:i', strtotime($permohonan['created_at'])) ?></td>
                                        <td>
                                            <a href="<?= $permohonan['jenis'] === 'kelahiran' ? 'rs_detail_kelahiran.php?id=' . $permohonan['id'] : 'rs_detail_kematian.php?id=' . $permohonan['id'] ?>" 
                                               class="btn-primary" style="padding: 6px 12px; font-size: 12px;">
                                                <i class="fas fa-eye"></i> Lihat
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout_pasutri_ahlak.php');
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) hideLogoutModal();
        });
    }
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
});
</script>
</body>
</html>
