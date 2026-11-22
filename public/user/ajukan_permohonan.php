<?php
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
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
  <title>Ajukan Permohonan</title>
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/ajukan_permohonan.css?v=20260927-2">
  <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

  <div class="layout">

    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <main class="main">
      <header class="header user-general-header">
        <?php
        require_once __DIR__ . '/user_header_brand.php';
        render_user_header_brand('Ajukan Permohonan');
        ?>
        <div class="user-info">
          Hai, <?= htmlspecialchars($_SESSION['nama']) ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <!-- Flash Toast -->
        <?php if (!empty($flash_success)): ?>
          <div class="toast toast-success">
            <?= htmlspecialchars($flash_success) ?>
          </div>
          <script>window._userUtils?.removeToasts(5000);</script>
        <?php endif; ?>

        <h2 class="text-2xl font-bold text-gray-800 mb-2">Pilih Jenis Permohonan</h2>
        <p class="text-gray-600 mb-8">Silakan pilih jenis permohonan yang ingin Anda ajukan:</p>

        <div class="service-grid">

          <!-- Laporkan Peristiwa -->
          <a href="lapor_peristiwa.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">⚠️</div>
            <h3>Laporkan Peristiwa</h3>
            <p>Untuk dasawisma melaporkan peristiwa lahir/mati/penduduk rentan adminduk</p>
            <button class="btn-select">Lapor Sekarang</button>
          </a>

          <!-- Kartu Identitas Anak (KIA) -->
          <a href="kia.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">
              <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-slate-200 shadow-sm" title="Kartu Identitas Anak (KIA)">
                <svg width="34" height="34" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Kartu Identitas Anak (KIA)">
                  <!-- Kartu KIA Indonesia (Pink / Merah Khas KIA) -->
                  <rect x="3" y="7" width="30" height="22" rx="3.5" fill="#e11d48" stroke="#be123c" stroke-width="1.2"/>
                  <rect x="5" y="9" width="26" height="4" rx="1.5" fill="#fda4af" opacity="0.75"/>
                  <!-- Foto Anak di sisi kiri kartu -->
                  <rect x="6" y="15" width="7" height="9" rx="1.5" fill="#ffffff" stroke="#f43f5e" stroke-width="0.8"/>
                  <circle cx="9.5" cy="18" r="2" fill="#e11d48"/>
                  <path d="M7 23.5C7 21.8 8.1 21 9.5 21C10.9 21 12 21.8 12 23.5" fill="#e11d48"/>
                  <!-- Garis data identitas anak di sisi kanan kartu -->
                  <rect x="16" y="15" width="13" height="1.8" rx="0.9" fill="#ffffff"/>
                  <rect x="16" y="18.5" width="10" height="1.8" rx="0.9" fill="#ffe4e6"/>
                  <rect x="16" y="22" width="12" height="1.8" rx="0.9" fill="#ffe4e6"/>
                </svg>
              </span>
            </div>
            <h3><span class="judul-lengkap">Kartu Identitas Anak (KIA)</span><span class="judul-mobile">KIA</span></h3>
            <p>Ajukan permohonan pembuatan atau perubahan Kartu Identitas Anak untuk anak dibawah 17 tahun</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>

          <!-- Kartu Keluarga -->
          <a href="kk_pilih_jenis.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">👨‍👩‍👧‍👦</div>
            <h3>Kartu Keluarga</h3>
            <p>Ajukan KK baru, KK hilang, cetak biodata, atau perubahan data Kartu Keluarga.</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>

          <!-- Pindah Alamat -->
          <a href="pindah_alamat.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">🏠</div>
            <h3>Pindah Alamat</h3>
            <p>Ajukan permohonan pindah alamat antar desa / kecamatan / kab / kota / Provinsi.</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>

          <!-- Pindah Online / dari luar kabupaten -->
          <a href="pindah_online.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">✈️</div>
            <h3><span class="judul-lengkap">Pindah Masuk (Asal Luar Kab Gorontalo)</span><span class="judul-mobile">Pindah Masuk</span></h3>
            <p>Ajukan permohonan pindah alamat asal alamat luar Kabupaten Gorontalo ke Kabupaten Gorontalo.</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>

          <!-- Akta Kelahiran -->
          <a href="akta_kelahiran_pilih_jenis.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">👶</div>
            <h3>Akta Kelahiran</h3>
            <p>Akta baru (belum/sudah ber-NIK), akta hilang, atau pembaruan akta lama ke TTE barcode.</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>

          <!-- Akta Kematian -->
          <a href="akta_kematian.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">🕊️</div>
            <h3>Akta Kematian</h3>
            <p>Ajukan permohonan pembuatan Akta Kematian sebagai bukti hukum atas kematian seseorang.</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>
        </div>

        <!-- Info Box -->
        <div class="mt-10 p-6 bg-blue-50 border border-blue-200 rounded-lg">
          <h3 class="text-lg font-semibold text-blue-800 mb-2">📋 Informasi Penting</h3>
          <ul class="text-blue-700 space-y-2">
            <li>• Pastikan data yang Anda berikan sudah benar dan lengkap</li>
            <li>• Siapkan dokumen pendukung yang diperlukan</li>
            <li>• Hubungi <strong>Admin 081243425557</strong> jika ada kendala</li>
            <li>• Hasil dokumen yang anda ajukan akan terkirim via email</li>
          </ul>
        </div>
      </section>
    </main>
  </div>

  <?php include __DIR__ . '/mobile_bottom_nav.php'; ?>

  <!-- Modal Logout - SAMA dengan dashboard_user -->
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
  document.addEventListener('DOMContentLoaded', function() {
    // Logout Modal - SAMA dengan dashboard_user
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
  </script>


</body>
</html>
