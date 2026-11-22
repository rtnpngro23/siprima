<?php
require_once '../error_handler.php';

include '../../app/middleware/petugas_desa_only.php';
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
  <link rel="stylesheet" href="css/petugas_desa_ajukan_permohonan.page.css?v=20260927-2">
  <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="petugas-desa-ui">

  <div class="layout">

    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header petugas-desa-header">
        <?php
        require_once __DIR__ . '/petugas_desa_header_brand.php';
        render_petugas_desa_header_brand('Ajukan Permohonan', 'Adminduk Dari Desa (ADD)');
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
        <?php endif; ?>

        <h2 class="text-2xl font-bold text-gray-800 mb-2">Pilih Jenis Permohonan</h2>
        <p class="text-gray-600 mb-8">Silakan pilih jenis permohonan yang ingin Anda ajukan:</p>

        <div class="service-grid">

          <!-- Laporkan Peristiwa 
          <a href="lapor_peristiwa.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">⚠️</div>
            <h3>Laporkan Peristiwa</h3>
            <p>Untuk dasawisma melaporkan peristiwa lahir/mati/penduduk rentan adminduk</p>
            <button class="btn-select">Lapor Sekarang</button>
          </a>
          -->

          <!-- Kartu Keluarga -->
          <a href="petugas_desa_kk_pilih_jenis.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">👨‍👩‍👧‍👦</div>
            <h3>Kartu Keluarga</h3>
            <p>Ajukan KK baru, KK hilang, cetak biodata, atau perubahan data Kartu Keluarga.</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>

          <!-- Pindah Alamat -->
          <a href="petugas_desa_pindah_alamat.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">🏠</div>
            <h3>Pindah Alamat</h3>
            <p>Ajukan permohonan pindah alamat antar desa / kecamatan / kab / kota / Provinsi.</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>

          <!-- Pindah Online / dari luar kabupaten -->
          <a href="petugas_desa_pindah_online.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">✈️</div>
            <h3><span class="judul-lengkap">Pindah Masuk (Asal Luar Kab Gorontalo)</span><span class="judul-mobile">Pindah Masuk</span></h3>
            <p>Ajukan permohonan pindah alamat asal alamat luar Kabupaten Gorontalo ke Kabupaten Gorontalo.</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>

          <!-- Akta Kelahiran -->
          <a href="petugas_desa_akta_kelahiran_pilih_jenis.php" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon">👶</div>
            <h3>Akta Kelahiran</h3>
            <p>Ajukan permohonan pembuatan Akta Kelahiran sebagai bukti hukum atas kelahiran seseorang.</p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>

          <!-- Akta Kematian -->
          <a href="petugas_desa_akta_kematian.php" class="service-card" style="text-decoration: none; color: inherit;">
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
            <li>• Anda akan menerima notifikasi via email</li>
          </ul>
        </div>
      </section>
    </main>
  </div>

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

  <script src="js/petugas_desa_ajukan_permohonan.page.js"></script>


<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>
