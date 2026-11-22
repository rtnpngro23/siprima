<?php
require_once '../error_handler.php';
require_once '../../app/middleware/user_kua_only.php';

// Hitung statistik permohonan berdasarkan role dengan cara yang lebih sederhana
$total_diajukan = 0;
$total_diproses = 0;
$total_ditolak = 0;
$total_selesai = 0;
$total_semua = 0;
$stats_error = null;
$role = (string)($_SESSION['role'] ?? 'user');
$id_user = (int)($_SESSION['id_user'] ?? 0);
$id_kua = (int)($_SESSION['id_kua'] ?? 0);

if ($role === 'operator') {
    // Operator: Hitung semua permohonan di KUA ini
    // Cara sederhana: asumsikan tabel menggunakan id_kua
    $sql = "SELECT 
            COUNT(*) as total_semua,
            COALESCE(SUM(status = 'diajukan'), 0) as diajukan,
            COALESCE(SUM(status = 'diproses'), 0) as diproses,
            COALESCE(SUM(status = 'selesai'), 0) as selesai,
            COALESCE(SUM(status = 'ditolak'), 0) as ditolak
            FROM tb_kartukeluarga_kua 
            WHERE id_kua = ?";
    
    $stmt = $koneksi->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $id_kua);
        if ($stmt->execute() && ($data = $stmt->get_result()->fetch_assoc())) {
            $total_semua = $data['total_semua'] ?? 0;
            $total_diajukan = $data['diajukan'] ?? 0;
            $total_diproses = $data['diproses'] ?? 0;
            $total_selesai = $data['selesai'] ?? 0;
            $total_ditolak = $data['ditolak'] ?? 0;
        } else {
            $stats_error = 'Statistik permohonan sementara tidak dapat dimuat.';
        }
        $stmt->close();
    } else {
        $stats_error = 'Statistik permohonan sementara tidak dapat dimuat.';
    }
} else {
    // User: Hitung permohonan user ini di KUA ini
    // Cara sederhana: asumsikan tabel menggunakan id_kua
    $sql = "SELECT 
            COUNT(*) as total_semua,
            COALESCE(SUM(status = 'diajukan'), 0) as diajukan,
            COALESCE(SUM(status = 'diproses'), 0) as diproses,
            COALESCE(SUM(status = 'selesai'), 0) as selesai,
            COALESCE(SUM(status = 'ditolak'), 0) as ditolak
            FROM tb_kartukeluarga_kua
            WHERE id_user = ? AND id_kua = ?";
    
    $stmt = $koneksi->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $id_user, $id_kua);
        if ($stmt->execute() && ($data = $stmt->get_result()->fetch_assoc())) {
            $total_semua = $data['total_semua'] ?? 0;
            $total_diajukan = $data['diajukan'] ?? 0;
            $total_diproses = $data['diproses'] ?? 0;
            $total_selesai = $data['selesai'] ?? 0;
            $total_ditolak = $data['ditolak'] ?? 0;
        } else {
            $stats_error = 'Statistik permohonan sementara tidak dapat dimuat.';
        }
        $stmt->close();
    } else {
        $stats_error = 'Statistik permohonan sementara tidak dapat dimuat.';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard KUA - <?= htmlspecialchars($_SESSION['nama_kua'] ?? 'KUA') ?></title>    
    <link rel="icon" href="../assets/PASUTRI.png" type="image/png">
    <link rel="stylesheet" href="css/kua_dashboard_user.css">
    
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan PASUTRI</div>
        <nav>
            <ul>
                <?php if ($role === 'operator'): ?>
                    <li class="active"><a href="kua_dashboard_operator.php">Dashboard</a></li>
                    <li><a href="kua_proses_pengajuan.php">Proses Pengajuan</a></li>
                    <li><a href="kua_riwayat_operator.php">Riwayat</a></li>
                    <li><a href="kua_dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <?php else: ?>
                    <li class="active"><a href="kua_dashboard_user.php">Dashboard</a></li>
                    <li><a href="kua_ajukan_permohonan.php">Ajukan Permohonan</a></li>
                    <li><a href="kua_status_permohonan.php">Status Permohonan</a></li>
                    <li><a href="kua_formulir.php">Formulir Kependudukan</a></li>
                    <li><a href="kua_profil.php">Profil Saya</a></li>
                <?php endif; ?>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Dashboard <?= htmlspecialchars($_SESSION['nama_kua'] ?? '') ?></div>
                <div class="subtitle">Sistem Pelayanan Kependudukan KUA</div>
            </div>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['nama'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8') ?></span>
                <span class="role-badge"><?= htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8') ?></span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout-btn" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Welcome Message -->
            <div class="welcome-box">
                <h1>Selamat Datang di <?= htmlspecialchars($_SESSION['nama_kua'] ?? 'KUA') ?></h1>
                <p>
                    <?php if ($role === 'operator'): ?>
                    Anda login sebagai Operator KUA. Kelola semua permohonan dari satu tempat.
                    <?php else: ?>
                    Anda login sebagai User KUA. Ajukan dan pantau permohonan Anda.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($stats_error): ?>
                <div class="info-box"><p><?= htmlspecialchars($stats_error, ENT_QUOTES, 'UTF-8') ?></p></div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #2c3e50; margin-bottom: 20px;">
                📊 Statistik Permohonan <?= $role === 'operator' ? 'KUA' : 'Saya' ?>
            </h2>
            <div class="stats-grid">
                <div class="stat-card total">
                    <h3>Total Semua Permohonan</h3>
                    <p><?= (int)$total_semua ?></p>
                </div>
                <div class="stat-card diajukan">
                    <h3>Diajukan</h3>
                    <p><?= (int)$total_diajukan ?></p>
                </div>
                <div class="stat-card diproses">
                    <h3>Diproses</h3>
                    <p><?= (int)$total_diproses ?></p>
                </div>
                <div class="stat-card ditolak">
                    <h3>Ditolak</h3>
                    <p><?= (int)$total_ditolak ?></p>
                </div>
                <div class="stat-card selesai">
                    <h3>Selesai</h3>
                    <p><?= (int)$total_selesai ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #2c3e50; margin-bottom: 20px; margin-top: 40px;">
                ⚡ Aksi Cepat
            </h2>
            <div class="quick-actions">
                <?php if ($role === 'operator'): ?>
                <!-- Aksi untuk operator KUA -->
                <div class="action-card">
                    <h3>Kelola Permohonan</h3>
                    <p>Verifikasi, proses, dan kelola semua permohonan yang masuk di KUA <?= htmlspecialchars($_SESSION['nama_kua'] ?? '') ?></p>
                    <a href="kua_proses_pengajuan.php" class="btn btn-success">Kelola Sekarang</a>
                </div>
                <div class="action-card">
                    <h3>Statistik KUA Lengkap</h3>
                    <p>Lihat statistik detail dan target kinerja KUA <?= htmlspecialchars($_SESSION['nama_kua'] ?? '') ?></p>
                    <a href="kua_riwayat_operator.php" class="btn btn-primary">Lihat Riwayat</a>
                </div>
                <div class="action-card">
                    <h3>Profil KUA</h3>
                    <p>Kelola informasi dan pengaturan KUA <?= htmlspecialchars($_SESSION['nama_kua'] ?? '') ?></p>
                    <a href="kua_profil.php" class="btn btn-purple">Kelola Profil</a>
                </div>
                <?php else: ?>
                <!-- Aksi untuk user KUA -->
                <div class="action-card">
                    <h3>Ajukan Permohonan Baru</h3>
                    <p>Ajukan permohonan baru untuk pelayanan kependudukan di KUA <?= htmlspecialchars($_SESSION['nama_kua'] ?? '') ?></p>
                    <a href="kua_ajukan_permohonan.php" class="btn btn-success">Ajukan Sekarang</a>
                </div>
                <div class="action-card">
                    <h3>Status Permohonan</h3>
                    <p>Pantau perkembangan semua permohonan Anda di KUA <?= htmlspecialchars($_SESSION['nama_kua'] ?? '') ?></p>
                    <a href="kua_status_permohonan.php" class="btn btn-primary">Lihat Status</a>
                </div>
                <div class="action-card">
                    <h3>Profil Saya</h3>
                    <p>Kelola informasi dan data pribadi Anda di KUA <?= htmlspecialchars($_SESSION['nama_kua'] ?? '') ?></p>
                    <a href="kua_profil.php" class="btn btn-purple">Kelola Profil</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <h3>Informasi Penting</h3>
                <p>Pastikan semua dokumen lengkap sebelum mengajukan permohonan.</p>
                <p>Jam layanan: Setiap hari 24 jam</p>
                <p>Untuk bantuan, silakan hubungi petugas KUA.</p>
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
});
</script>

<?php include __DIR__ . '/../user/ai_chat_widget.php'; ?>
</body>
</html>
