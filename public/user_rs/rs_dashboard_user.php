<?php
require_once '../error_handler.php';
// Gunakan user_rs_only.php untuk validasi khusus RS
include '../../app/middleware/user_rs_only.php';


// Debug
error_log("=== rs_dashboard_user.php START ===");
error_log("id_user: " . $_SESSION['id_user']);
error_log("id_rs: " . $_SESSION['id_rs']);
error_log("nama_rs: " . ($_SESSION['nama_rs'] ?? 'NOT SET'));

// Koneksi database
include '../../app/config/koneksi.php';

// Hitung statistik permohonan - khusus akta kelahiran dan kematian untuk RS
$total_diajukan = 0;
$total_diproses = 0;
$total_ditolak = 0;
$total_selesai = 0;
$total_semua = 0;

$user_id = $_SESSION['id_user'];
$id_rs = $_SESSION['id_rs'];

// PERBAIKAN: Query untuk tabel tb_akta_kelahiran_rs (bukan tb_akta_kelahiran)
$sql_kelahiran = "SELECT 
        COUNT(*) as total_semua,
        COALESCE(SUM(status = 'diajukan'), 0) as diajukan,
        COALESCE(SUM(status = 'diproses'), 0) as diproses,
        COALESCE(SUM(status = 'selesai'), 0) as selesai,
        COALESCE(SUM(status = 'ditolak'), 0) as ditolak
        FROM tb_akta_kelahiran_rs 
        WHERE id_user = ? AND id_rs = ?";

$stmt_kelahiran = $koneksi->prepare($sql_kelahiran);
if ($stmt_kelahiran) {
    $stmt_kelahiran->bind_param("ii", $user_id, $id_rs);
    $stmt_kelahiran->execute();
    $result_kelahiran = $stmt_kelahiran->get_result();
    
    if ($data_kelahiran = $result_kelahiran->fetch_assoc()) {
        $total_semua += $data_kelahiran['total_semua'] ?? 0;
        $total_diajukan += $data_kelahiran['diajukan'] ?? 0;
        $total_diproses += $data_kelahiran['diproses'] ?? 0;
        $total_selesai += $data_kelahiran['selesai'] ?? 0;
        $total_ditolak += $data_kelahiran['ditolak'] ?? 0;
    }
    $stmt_kelahiran->close();
}

// PERBAIKAN: Query untuk tabel tb_akta_kematian_rs (bukan tb_akta_kematian)
$sql_kematian = "SELECT 
        COUNT(*) as total_semua,
        COALESCE(SUM(status = 'diajukan'), 0) as diajukan,
        COALESCE(SUM(status = 'diproses'), 0) as diproses,
        COALESCE(SUM(status = 'selesai'), 0) as selesai,
        COALESCE(SUM(status = 'ditolak'), 0) as ditolak
        FROM tb_akta_kematian_rs 
        WHERE id_user = ? AND id_rs = ?";

$stmt_kematian = $koneksi->prepare($sql_kematian);
if ($stmt_kematian) {
    $stmt_kematian->bind_param("ii", $user_id, $id_rs);
    $stmt_kematian->execute();
    $result_kematian = $stmt_kematian->get_result();
    
    if ($data_kematian = $result_kematian->fetch_assoc()) {
        $total_semua += $data_kematian['total_semua'] ?? 0;
        $total_diajukan += $data_kematian['diajukan'] ?? 0;
        $total_diproses += $data_kematian['diproses'] ?? 0;
        $total_selesai += $data_kematian['selesai'] ?? 0;
        $total_ditolak += $data_kematian['ditolak'] ?? 0;
    }
    $stmt_kematian->close();
}

// Jika terjadi error, berikan default values
if ($total_semua === 0) {
    // Check jika user benar-benar tidak memiliki permohonan atau ada error
    error_log("Tidak ada data permohonan ditemukan untuk user_id: $user_id, id_rs: $id_rs");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard RS - <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></title>
    <link rel="stylesheet" href="css/rs_dashboard_user.css">
    <link rel="icon" href="../assets/st sehati.png" type="image/png">
    
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">ST SEHATI - <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></div>
        <nav>
            <ul>
                <li class="active"><a href="rs_dashboard_user.php">Dashboard</a></li>
                <li><a href="rs_ajukan_permohonan.php">Ajukan Permohonan</a></li>
                <li><a href="rs_status_permohonan.php">Status Permohonan</a></li>
                <li><a href="rs_profil.php">Profil Saya</a></li>
                <li><a href="rs_formulir.php">Formulir Kependudukan</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Dashboard ST SEHATI</div>
                <div class="subtitle">Sistem Terpadu Sinergi Kelahiran dan Kematian Terintegrasi</div>
            </div>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['nama'] ?? 'User') ?></span>
                <span class="role-badge"><?= strtoupper($_SESSION['role'] ?? 'USER') ?></span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout-btn" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Welcome Message -->
            <div class="welcome-box">
                <h1>Selamat Datang <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></h1>
                <p>
                    ST SEHATI merupakan layanan online untuk pengajuan Akta Kelahiran dan Akta Kematian kerja sama pihak rumah sakit dengan Dinas Dukcapil Kab. Gorontalo.
                    Ajukan permohonan dengan mudah dan pantau statusnya kapan saja.
                </p>
            </div>

            <!-- Stats Grid -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #2c5282; margin-bottom: 20px;">
                📊 Statistik Permohonan Anda
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
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #2c5282; margin-bottom: 20px; margin-top: 40px;">
                ⚡ Layanan Cepat
            </h2>
            <div class="quick-actions">
                <div class="action-card">
                    <h3>Ajukan Akta Kelahiran</h3>
                    <p>Ajukan akta kelahiran untuk bayi yang baru lahir di <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?>. Pastikan dokumen lengkap.</p>
                    <!-- PERBAIKAN: Link ke file yang benar -->
                    <a href="rs_akta_kelahiran.php" class="btn btn-success">Ajukan Sekarang</a>
                </div>
                <div class="action-card">
                    <h3>Ajukan Akta Kematian</h3>
                    <p>Ajukan akta kematian untuk pasien yang meninggal di <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?>. Dilengkapi dengan surat keterangan dokter.</p>
                    <!-- PERBAIKAN: Link ke file yang benar -->
                    <a href="rs_akta_kematian.php" class="btn btn-primary">Ajukan Sekarang</a>
                </div>
                <div class="action-card">
                    <h3>Status Permohonan</h3>
                    <p>Pantau perkembangan semua permohonan akta kelahiran dan kematian yang telah Anda ajukan.</p>
                    <a href="rs_status_permohonan.php" class="btn btn-blue">Lihat Status</a>
                </div>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <h3>Informasi Penting</h3>
                <p>Pastikan Anda memiliki dokumen berikut sebelum mengajukan permohonan:</p>
                <p><strong>Akta Kelahiran:</strong> Surat keterangan lahir dari rumah sakit, Formulir F-2.01 Kelahiran, Buku Nikah/Akta Kawin</p>
                <p><strong>Akta Kematian:</strong> Surat keterangan meninggal dari rumah sakit, Formulir F-2.01 Kematian, KK/KTP yang meninggal</p>
                <p>Jam layanan: Setiap hari (24 jam) untuk pengajuan online</p>
                <p>Untuk bantuan, hubungi <strong>HP/WA 081243425557</strong></p>
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