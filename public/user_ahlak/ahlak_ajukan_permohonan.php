<?php
require_once '../error_handler.php';

include '../../app/middleware/user_ahlak_only.php';
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
  <title>Ajukan Permohonan AHLAK - Puskesmas</title>
  <link rel="stylesheet" href="css/ahlak_dashboard_user.css">
  <link rel="stylesheet" href="css/ahlak_ajukan_permohonan.css?v=20260910-1">
  <link rel="icon" type="image/png" href="../assets/ahlak.png">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>

<body>

  <div class="layout">

    <aside class="sidebar">
      <div class="logo">Pelayanan Kependudukan AHLAK</div>
      <nav>
        <ul>
          <li><a href="ahlak_dashboard_user.php">Dashboard</a></li>
          <li class="active"><a href="ahlak_ajukan_permohonan.php">Ajukan Permohonan</a></li>
          <li><a href="ahlak_status_permohonan.php">Status Permohonan</a></li>          
          <li><a href="ahlak_formulir.php">Formulir Kependudukan</a></li>
          <li><a href="ahlak_profil.php">Profil</a></li>
          <li id="logoutBtn">Logout</li>
        </ul>
      </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header">
        <div class="left-header">
          <div class="title">Ajukan Permohonan AHLAK</div>
          <div style="font-size: 14px; color: #666; margin-top: 5px;">
            <?= htmlspecialchars($_SESSION['nama_puskes'] ?? '') ?>
          </div>
        </div>
        <div class="user-info">
          <span><?= htmlspecialchars($_SESSION['nama']) ?></span>
          <span class="role-badge">USER AHLAK</span>
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
        <?php endif; ?>

        <!-- Puskesmas Info -->
        <div class="puskesmas-info">
          <h3>🏥 <?= htmlspecialchars($_SESSION['nama_puskes'] ?? 'Puskesmas') ?></h3>
          <p><strong>Alamat:</strong> <?= htmlspecialchars($_SESSION['nama_kecamatan'] ?? 'Kecamatan') ?>, Kabupaten Gorontalo</p>
          <p><strong>Layanan:</strong> Pencatatan Kelahiran untuk masyarakat wilayah <?= htmlspecialchars($_SESSION['nama_kecamatan'] ?? 'kecamatan') ?></p>
        </div>

        <!-- Welcome Message -->
        <div class="welcome-box">
          <h1>Layanan Pencatatan Kelahiran</h1>
          <p>Ajukan permohonan AHLAK untuk penerbitan Akta Kelahiran dan Kartu Keluarga di wilayah kerja <?= htmlspecialchars($_SESSION['nama_puskes'] ?? 'Puskesmas') ?></p>
        </div>

        <h2 style="font-size: 1.5rem; font-weight: 700; color: #2c3e50; margin-bottom: 10px;">Pilih Jenis Permohonan</h2>
        <p style="color: #666; margin-bottom: 30px;"><?= htmlspecialchars($_SESSION['nama_puskes'] ?? '') ?> menyediakan layanan AHLAK:</p>

        <div class="service-grid">
          <a href="ahlak_akta_kelahiran.php" class="service-card single-card">
            <div class="icon">👶</div>
            <h3>ANAK LAHIR ADA AKTA KELAHIRAN</h3>
            <p>Ajukan permohonan AHLAK pembuatan Akta Kelahiran dan Kartu Keluarga sebagai bukti hukum atas kelahiran bayi yang terjadi di wilayah kerja <?= htmlspecialchars($_SESSION['nama_puskes'] ?? '') ?>.</p>
            <span class="btn-select">Ajukan Sekarang</span>
          </a>

          <a href="ahlak_generate_nama_bayi.php" class="service-card" style="text-decoration:none;color:inherit">
            <div class="icon" aria-hidden="true">✨</div>
            <h3>Inspirasi Nama Bayi</h3>
            <p>Belum menemukan nama untuk si kecil? Pilih dari 100 nama laki-laki dan 100 nama perempuan, lengkap dengan arti. Nama pilihan bisa dilanjutkan ke formulir akta kelahiran.</p>
            <span class="btn-select">Cari Inspirasi Nama</span>
          </a>
        </div>

        <!-- Info Box -->
        <div class="info-box">
          <h3>📋 Informasi Penting</h3>
          <p><strong>Dokumen yang diperlukan:</strong></p>
          <ul style="text-align: left; display: inline-block; margin-top: 10px;">
            <li>Surat keterangan lahir dari fasilitas kesehatan / puskesmas</li>
            <li>Formulir F-2.01 kelahiran</li>
            <li>Kartu Keluarga</li>
            <li>Surat nikah/buku nikah</li>
          </ul>
          <p style="margin-top: 15px;"><strong>Jam layanan:</strong> Setiap hari 24 jam</p>
          <p><strong>Dokumen:</strong> Hasil pelayanan (Akta Kelahiran dan Kartu Keluarga) akan terkirim ke email puskesmas</p>
          <p><strong>Hubungi:</strong> Admin Dukcapil Limboto jika ada kendala <strong>HP/WA 081243425557</strong></p>
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

    // Auto-hide toast after 5 seconds
    setTimeout(() => {
      const toast = document.querySelector('.toast');
      if (toast) {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s ease';
        setTimeout(() => {
          if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
          }
        }, 500);
      }
    }, 5000);
  });
  </script>

</body>
</html>
