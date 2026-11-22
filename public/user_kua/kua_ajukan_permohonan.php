<?php
require_once '../error_handler.php';
// Gunakan user_kua_only.php untuk validasi khusus KUA
include '../../app/middleware/user_kua_only.php'; // Validasi user KU

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
  <title>Ajukan Permohonan - <?= htmlspecialchars($_SESSION['nama_kua'] ?? 'KUA') ?></title>
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/kua_ajukan_permohonan.css">
  <link rel="icon" href="../assets/PASUTRI.png" type="image/png">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

  <div class="layout">

    <!-- Sidebar - KONSISTEN dengan kua_dashboard_user -->
    <aside class="sidebar">
      <div class="logo">Pelayanan Kependudukan PASUTRI</div>
      <nav>
        <ul>
          <li><a href="kua_dashboard_user.php">Dashboard</a></li>
          <li  class="active"><a href="kua_ajukan_permohonan.php">Ajukan Permohonan</a></li>                
          <li><a href="kua_status_permohonan.php">Status Permohonan</a></li>                
          <li><a href="kua_formulir.php">Formulir Kependudukan</a></li>
          <li><a href="kua_profil.php">Profil Saya</a></li>
          <li id="logoutBtn">Logout</li>
        </ul>
      </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <!-- Header - KONSISTEN dengan kua_dashboard_user -->
      <header class="header">
        <div class="left-header">
          <div class="title">Ajukan Permohonan</div>
          <div class="subtitle">Sistem Pelayanan Kependudukan <?= htmlspecialchars($_SESSION['nama_kua'] ?? '') ?></div>
        </div>
        <div class="user-info">
          <span><?= htmlspecialchars($_SESSION['nama'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8') ?></span>
          <span class="role-badge"><?= htmlspecialchars(strtoupper($_SESSION['role'] ?? 'user'), ENT_QUOTES, 'UTF-8') ?></span>
          <?php include __DIR__ . '/../user/notification_widget.php'; ?>
          <button class="logout-btn" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <!-- Welcome Message -->
        <div class="welcome-box">
          <h2>Ajukan Permohonan</h2>
          <p>Pilih jenis permohonan pelayanan kependudukan di <?= htmlspecialchars($_SESSION['nama_kua'] ?? 'KUA', ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <!-- Flash Toast -->
        <?php if (!empty($flash_success)): ?>
          <div class="toast toast-success">
            <?= htmlspecialchars($flash_success) ?>
          </div>
          <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>
        <?php endif; ?>

        <!-- Informasi Role -->
        <?php if (($_SESSION['role'] ?? '') === 'operator'): ?>
        <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
          <p class="text-yellow-800 font-medium">
            ⚠️ Sebagai Operator KUA, Anda dapat membantu pengguna mengajukan permohonan.
            Pastikan data yang dimasukkan sesuai dengan dokumen asli.
          </p>
        </div>
        <?php endif; ?>

        <h2 class="text-2xl font-bold text-gray-800 mb-2">📋 Pilih Jenis Permohonan</h2>
        <p class="text-gray-600 mb-8">Silakan pilih jenis permohonan pelayanan kependudukan:</p>

        <div class="service-grid">
          <!-- Kartu Keluarga Khusus KUA -->
          <a href="kua_kartukeluarga.php" class="service-card kua" style="text-decoration: none; color: inherit;">
            <div class="icon">👨‍👩‍👧‍👦</div>
            <h3>Kartu Keluarga</h3>
            <p>Ajukan permohonan pembuatan Kartu Keluarga baru untuk pasangan suami istri yang baru menikah 
              atau pengajuan pisah Kartu Keluarga karena cerai.
            </p> 
            <p>Satu klik pengajuan akan menghasilkan dokumen Kartu Keluarga suami istri,
              Kartu Keluarga orang tua dari suami/istri serta dokumen biodata untuk suami dan istri
            </p>
            <span class="btn-select">Ajukan Sekarang</span>
          </a>
        </div>

        <!-- Info Box Khusus KUA -->
        <div class="mt-10 p-6 bg-purple-50 border border-purple-200 rounded-lg">
          <h3 class="text-lg font-semibold text-purple-800 mb-2">🏛️ Informasi Layanan <?= htmlspecialchars($_SESSION['nama_kua'] ?? '', ENT_QUOTES, 'UTF-8') ?></h3>
          <ul class="text-purple-700 space-y-2">
            <li>• <strong>Jam Layanan:</strong> Setiap hari 24 jam</li>
            <li>• <strong>Dokumen Wajib:</strong> KTP, KK, Akta Nikah/Buku Nikah, dan dokumen pendukung lainnya</li>
            <li>• <strong>Hubungi Admin:</strong> Admin Dukcapil - 081243425557</li>
            <li>• <strong>Dokumen Hasil:</strong> Hasil pelayanan akan terkirim langsung ke email</li>
          </ul>
        </div>

        <!-- Note untuk Operator -->
        <?php if (($_SESSION['role'] ?? '') === 'operator'): ?>
        <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
          <h4 class="text-blue-800 font-semibold mb-2">📝 Catatan untuk Operator:</h4>
          <p class="text-blue-700">
            Sebagai operator KUA, pastikan untuk:
          </p>
          <ul class="text-blue-700 ml-4 mt-2 list-disc">
            <li>Memverifikasi kelengkapan dokumen</li>
            <li>Memastikan data sesuai dengan dokumen asli</li>
            <li>Mencatat setiap permohonan dengan lengkap</li>
            <li>Memberikan informasi yang jelas kepada pengguna</li>
          </ul>
        </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <!-- Modal Logout - KONSISTEN dengan kua_dashboard_user -->
  <div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
      <h3>Konfirmasi Logout</h3>
      <p>Apakah Anda yakin ingin logout dari sistem KUA?</p>
      <div class="modal-actions">
        <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
        <button id="cancelLogout" class="btn-no">Batal</button>
      </div>
    </div>
  </div>

  <script>
  // Toggle Sidebar Menu on Mobile - KONSISTEN dengan kua_dashboard_user
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
    
    // Logout Modal - KONSISTEN dengan kua_dashboard_user
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
    
    // Animation for service cards
    setTimeout(function() {
      const serviceCards = document.querySelectorAll('.service-card');
      serviceCards.forEach((card, index) => {
        setTimeout(() => {
          card.style.transform = 'translateY(0)';
          card.style.opacity = '1';
        }, index * 100);
      });
    }, 300);
  });
  </script>

</body>
</html>
