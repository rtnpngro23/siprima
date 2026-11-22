<?php
require_once '../error_handler.php';
require_once __DIR__ . '/../functions/kk_kategori.php';
include '../../app/middleware/user_only.php';

// Kartu dijaga sejajar dengan daftar kategori di kk_kategori.php: label diambil dari
// helper agar tidak ada dua sumber kebenaran, sedangkan tujuan dan penjelasan khusus halaman ini.
$kategori = [
    'kk_baru' => [
        'ikon' => '👨‍👩‍👧‍👦',
        'tujuan' => 'kk.php',
        'judul_mobile' => 'Kartu Keluarga Baru',
        'teks' => 'Pembuatan Kartu Keluarga baru, pisah KK, atau penambahan anggota keluarga menggunakan Formulir F-1.01.',
    ],
    'kk_hilang' => [
        'ikon' => '🔍',
        'tujuan' => 'kk_layanan.php?jenis=kk_hilang',
        'judul_mobile' => 'Kartu Keluarga Hilang',
        'teks' => 'Kartu Keluarga hilang dan dicetak ulang tanpa perubahan data. Siapkan surat keterangan hilang KK.',
    ],
    'cetak_biodata' => [
        'ikon' => '🪪',
        'tujuan' => 'kk_layanan.php?jenis=cetak_biodata',
        'judul_mobile' => 'Cetak Biodata',
        'teks' => 'Cetak biodata sebagai pengganti KTP sementara, isinya lebih lengkap dari KTP. Cukup isi nama dan NIK.',
    ],
    'perubahan_data' => [
        'ikon' => '✏️',
        'tujuan' => 'kk_layanan.php?jenis=perubahan_data',
        'teks' => 'Perubahan elemen data di Kartu Keluarga (pendidikan, pekerjaan, status, dll.) dengan Formulir F-1.06 SPTJM Perubahan Data.',
    ],
];

function esc_pilih($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Jenis Permohonan Kartu Keluarga</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/ajukan_permohonan.css?v=20260927-2">
  <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <style>
    /* Gaya sendiri: css/ajukan_permohonan.css tidak memuat .btn-secondary,
       sehingga tautan kembali akan tampil sebagai teks biasa tanpa aturan ini. */
    .tombol-kembali-baris {
      margin-top: 28px;
      padding-top: 20px;
      border-top: 1px solid #e2e8f0;
    }
    .tombol-kembali {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 12px 22px;
      border: 2px solid #1d4ed8;
      border-radius: 10px;
      background: #ffffff;
      color: #1d4ed8;
      font-size: 16px;
      font-weight: 600;
      text-decoration: none;
      box-shadow: 0 1px 3px rgba(15, 23, 42, .12);
      transition: background-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .tombol-kembali:hover,
    .tombol-kembali:focus-visible {
      background: #1d4ed8;
      color: #ffffff;
      box-shadow: 0 4px 10px rgba(29, 78, 216, .3);
    }
    @media (max-width: 640px) {
      .tombol-kembali {
        width: 100%;
        justify-content: center;
      }
    }
  </style>
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
        render_user_header_brand('Kartu Keluarga');
        ?>
        <div class="user-info">
          Hai, <?= esc_pilih($_SESSION['nama'] ?? '') ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Pilih Jenis Permohonan Kartu Keluarga</h2>
        <p class="text-gray-600 mb-8">Pilih kategori yang sesuai dengan kondisi Anda agar dokumen yang diminta tepat:</p>

        <div class="service-grid service-grid--sub">
          <?php foreach (siprima_kk_kategori_options() as $kode => $label): ?>
          <a href="<?= esc_pilih($kategori[$kode]['tujuan']) ?>" class="service-card" style="text-decoration: none; color: inherit;">
            <div class="icon"><?= esc_pilih($kategori[$kode]['ikon']) ?></div>
            <h3>
              <?php if (isset($kategori[$kode]['judul_mobile'])): ?>
              <span class="judul-lengkap"><?= esc_pilih($label) ?></span><span class="judul-mobile"><?= esc_pilih($kategori[$kode]['judul_mobile']) ?></span>
              <?php else: ?>
              <?= esc_pilih($label) ?>
              <?php endif; ?>
            </h3>
            <p><?= esc_pilih($kategori[$kode]['teks']) ?></p>
            <button class="btn-select">Ajukan Sekarang</button>
          </a>
          <?php endforeach; ?>
        </div>

        <div class="mt-10 p-6 bg-blue-50 border border-blue-200 rounded-lg">
          <h3 class="text-lg font-semibold text-blue-800 mb-2">📋 Informasi Penting</h3>
          <ul class="text-blue-700 space-y-2">
            <li>• Pastikan data yang Anda berikan sudah benar dan lengkap</li>
            <li>• Siapkan dokumen pendukung yang diperlukan sesuai kategori</li>
            <li>• Hubungi <strong>Admin 081243425557</strong> jika ada kendala</li>
            <li>• Hasil dokumen yang anda ajukan akan terkirim via email</li>
          </ul>
        </div>

        <div class="tombol-kembali-baris">
          <a href="ajukan_permohonan.php" class="tombol-kembali">
            <span aria-hidden="true">&#8592;</span> Kembali ke Menu Permohonan
          </a>
        </div>
      </section>
    </main>
  </div>

  <?php include __DIR__ . '/mobile_bottom_nav.php'; ?>

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

    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout.php');
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);

    if (logoutModal) {
      logoutModal.addEventListener('click', function(event) {
        if (event.target === logoutModal) hideLogoutModal();
      });
      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) hideLogoutModal();
      });
    }
  });
  </script>

</body>
</html>
