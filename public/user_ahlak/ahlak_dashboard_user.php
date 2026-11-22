<?php
require_once '../error_handler.php';
// Gunakan user_ahlak_only.php untuk validasi khusus Puskesmas AHLAK
include '../../app/middleware/user_ahlak_only.php';

// Koneksi database
require_once '../../app/config/koneksi.php';

// Hitung statistik permohonan - khusus tabel tb_akta_kelahiran_ahlak
$total_diajukan = 0;
$total_diproses = 0;
$total_ditolak = 0;
$total_selesai = 0;
$total_semua = 0;

$user_id = $_SESSION['id_user'];
$id_puskes = $_SESSION['id_puskes'];

// Query untuk menghitung statistik dari tb_akta_kelahiran_ahlak
$sql = "SELECT 
        COUNT(*) as total_semua,
        COALESCE(SUM(status = 'diajukan'), 0) as diajukan,
        COALESCE(SUM(status = 'diproses'), 0) as diproses,
        COALESCE(SUM(status = 'selesai'), 0) as selesai,
        COALESCE(SUM(status = 'ditolak'), 0) as ditolak
        FROM tb_akta_kelahiran_ahlak 
        WHERE id_user = ? AND id_puskes = ?";

$stmt = $koneksi->prepare($sql);
if ($stmt) {
    $stmt->bind_param("ii", $user_id, $id_puskes);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($data = $result->fetch_assoc()) {
        $total_semua = $data['total_semua'] ?? 0;
        $total_diajukan = $data['diajukan'] ?? 0;
        $total_diproses = $data['diproses'] ?? 0;
        $total_selesai = $data['selesai'] ?? 0;
        $total_ditolak = $data['ditolak'] ?? 0;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Puskesmas - <?= htmlspecialchars($_SESSION['nama_puskes'] ?? 'Puskesmas') ?></title>
    <link rel="icon" type="image/png" href="../assets/ahlak.png">
    <link rel="stylesheet" href="css/ahlak_dashboard_user.css">
    
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan AHLAK</div>
        <nav>
            <ul>
                <li class="active"><a href="ahlak_dashboard_user.php">Dashboard</a></li>
                <li><a href="ahlak_ajukan_permohonan.php">Ajukan Permohonan</a></li>
                <li><a href="ahlak_status_permohonan.php">Status Permohonan</a></li>                
                <li><a href="ahlak_formulir.php">Formulir Kependudukan</a></li>
                <li><a href="ahlak_profil.php">Profil Saya</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Dashboard <?= htmlspecialchars($_SESSION['nama_puskes'] ?? 'Puskesmas') ?></div>
                <div class="subtitle">Sistem Pelayanan AHLAK (Anak Lahir Ada Akta Kelahiran)</div>
            </div>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['nama']) ?></span>
                <span class="role-badge">USER AHLAK</span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout-btn" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Welcome Message -->
            <div class="welcome-box">
                <h1>Selamat Datang di <?= htmlspecialchars($_SESSION['nama_puskes'] ?? 'Puskesmas') ?></h1>
                <p>
                    Anda login sebagai User Puskesmas. Ajukan dan pantau permohonan AHLAK untuk masyarakat di wilayah kecamatan <?= htmlspecialchars($_SESSION['nama_kecamatan'] ?? 'kecamatan') ?>.
                </p>
            </div>

            <!-- Stats Grid -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #2c3e50; margin-bottom: 20px;">
                📊 Statistik Permohonan AHLAK
            </h2>
            <div class="stats-grid">
                <div class="stat-card total">
                    <h3>Total Semua Permohonan</h3>
                    <p><?= $total_semua ?></p>
                </div>
                <div class="stat-card diajukan">
                    <h3>Diajukan</h3>
                    <p><?= $total_diajukan ?></p>
                </div>
                <div class="stat-card diproses">
                    <h3>Diproses</h3>
                    <p><?= $total_diproses ?></p>
                </div>
                <div class="stat-card ditolak">
                    <h3>Ditolak</h3>
                    <p><?= $total_ditolak ?></p>
                </div>
                <div class="stat-card selesai">
                    <h3>Selesai</h3>
                    <p><?= $total_selesai ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #2c3e50; margin-bottom: 20px; margin-top: 40px;">
                ⚡ Aksi Cepat
            </h2>
            <div class="quick-actions">
                <div class="action-card">
                    <h3>Ajukan Permohonan Baru</h3>
                    <p>Ajukan permohonan Akta Kelahiran baru untuk layanan di <?= htmlspecialchars($_SESSION['nama_puskes'] ?? '') ?></p>
                    <a href="ahlak_ajukan_permohonan.php" class="btn btn-success">Ajukan Sekarang</a>
                </div>
                <div class="action-card">
                    <h3>Status Permohonan</h3>
                    <p>Pantau perkembangan semua permohonan Akta Kelahiran Anda</p>
                    <a href="ahlak_status_permohonan.php" class="btn btn-primary">Lihat Status</a>
                </div>
                <div class="action-card">
                    <h3>Profil Saya</h3>
                    <p>Kelola informasi dan data pribadi Anda di <?= htmlspecialchars($_SESSION['nama_puskes'] ?? '') ?></p>
                    <a href="ahlak_profil.php" class="btn btn-purple">Kelola Profil</a>
                </div>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <h3>Informasi Penting</h3>
                <p>✅ Pastikan semua dokumen lengkap sebelum mengajukan permohonan:</p>
                <p>1. Surat Keterangan Lahir dari Faskes/Puskesmas</p>
                <p>2. Buku Nikah / Akta Nikah / SPTJM Perkawinan</p>
                <p>3. Form F-2.01 Kelahiran</p>
                <p>Jam layanan: Setiap hari 24 jam</p>
                <p>Untuk bantuan, silakan hubungi Admin Dukcapil Limboto <strong>081243425557</strong></p>
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
