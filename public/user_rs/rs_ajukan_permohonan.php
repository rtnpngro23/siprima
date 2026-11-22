<?php
require_once '../error_handler.php';
// Gunakan middleware khusus RS
include '../../app/middleware/user_rs_only.php';


$flash_success = null;
if (isset($_SESSION['success_message'])) {
    $flash_success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ajukan Permohonan - <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></title>
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/rs_ajukan_permohonan.css">
  <link rel="icon" href="../assets/st sehati.png" type="image/png">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

  <div class="layout">

    <aside class="sidebar">
      <div class="logo">ST SEHATI - <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></div>
      <nav>
        <ul>
          <li><a href="rs_dashboard_user.php">Dashboard</a></li>
          <li class="active"><a href ="rs_ajukan_permohonan.php">Ajukan Permohonan</a></li>
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
          <div class="title">Ajukan Permohonan</div>
          <div class="subtitle"><?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></div>
        </div>
        <div class="user-info">
          <span><?= htmlspecialchars($_SESSION['nama']) ?></span>
          <span class="role-badge"><?= strtoupper($_SESSION['role']) ?></span>
          <?php include __DIR__ . '/../user/notification_widget.php'; ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <!-- Flash Toast -->
        <?php if (!empty($flash_success)): ?>
          <div class="toast toast-success">
            <?= htmlspecialchars($flash_success) ?>
          </div>
          <script>
            setTimeout(function() {
              document.querySelector('.toast').style.display = 'none';
            }, 5000);
          </script>
        <?php endif; ?>

        <h2 class="text-2xl font-bold text-gray-800 mb-2">Layanan <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></h2>
        <p class="text-gray-600 mb-8">Pilih jenis permohonan akta yang ingin Anda ajukan:</p>

        <div class="service-grid">

          <!-- Akta Kelahiran -->
          <a href="rs_akta_kelahiran.php" class="service-card akta-kelahiran" style="text-decoration: none; color: inherit;">
            <div class="icon">👶</div>
            <h3>Akta Kelahiran</h3>
            <p>Ajukan permohonan pembuatan Akta Kelahiran untuk bayi yang baru lahir di <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?>. Pastikan Anda memiliki surat keterangan lahir dari dokter/bidan.</p>
            <button class="btn-select">Ajukan Akta Kelahiran</button>
          </a>

          <a href="rs_generate_nama_bayi.php" class="service-card" style="text-decoration:none;color:inherit">
            <div class="icon" aria-hidden="true">✨</div>
            <h3>Inspirasi Nama Bayi</h3>
            <p>Belum menemukan nama untuk si kecil? Pilih dari 100 nama laki-laki dan 100 nama perempuan, lengkap dengan arti. Nama pilihan bisa dilanjutkan ke formulir akta kelahiran.</p>
            <span class="btn-select">Cari Inspirasi Nama</span>
          </a>

          <!-- Akta Kematian -->
          <a href="rs_akta_kematian.php" class="service-card akta-kematian" style="text-decoration: none; color: inherit;">
            <div class="icon">🕊️</div>
            <h3>Akta Kematian</h3>
            <p>Ajukan permohonan pembuatan Akta Kematian untuk pasien yang meninggal di <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?>. Dilengkapi dengan surat keterangan meninggal dari dokter.</p>
            <button class="btn-select">Ajukan Akta Kematian</button>
          </a>

        </div>

        <!-- Info Box -->
        <div class="info-box">
          <h3>📋 Dokumen yang Diperlukan</h3>
          <h4 class="mt-4 mb-2 font-semibold text-blue-900">Untuk Akta Kelahiran:</h4>
          <ul>
            <li>Surat Keterangan Lahir dari dokter/bidan <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></li>
            <li>KTP / KK kedua orang tua</li>
            <li>Buku Nikah / Akta Perkawinan orang tua</li>
          </ul>
          
          <h4 class="mt-6 mb-2 font-semibold text-blue-900">Untuk Akta Kematian:</h4>
          <ul>
            <li>Surat Keterangan Meninggal dari dokter <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></li>
            <li>KTP / KK almarhum/almarhumah</li>
            <li>KTP pelapor (anggota keluarga terdekat)</li>
          </ul>
          
          <p class="mt-6 pt-4 border-t border-blue-300 text-blue-900 font-medium">
            💡 <strong>Catatan:</strong> Hasil dokumen yang Anda ajukan akan terkirim via email. Pastikan email Anda aktif dan dapat diakses.
          </p>
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
    
    // Remove toast after 5 seconds
    const toast = document.querySelector('.toast');
    if (toast) {
      setTimeout(function() {
        toast.style.display = 'none';
      }, 5000);
    }
  });
  </script>

</body>
</html>