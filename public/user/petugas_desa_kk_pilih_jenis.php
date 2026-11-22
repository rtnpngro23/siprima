<?php
require_once '../error_handler.php';
require_once __DIR__ . '/../functions/kk_kategori.php';
include '../../app/middleware/petugas_desa_only.php';

// Kartu dijaga sejajar dengan daftar kategori di kk_kategori.php: label diambil dari
// helper agar tidak ada dua sumber kebenaran, sedangkan tujuan dan penjelasan khusus halaman ini.
$kategori = [
    'kk_baru' => [
        'ikon' => '👨‍👩‍👧‍👦',
        'tujuan' => 'petugas_desa_kk.php',
        'judul_mobile' => 'Kartu Keluarga Baru',
        'teks' => 'Pembuatan Kartu Keluarga baru, pisah KK, atau penambahan anggota keluarga menggunakan Formulir F-1.01.',
    ],
    'kk_hilang' => [
        'ikon' => '🔍',
        'tujuan' => 'petugas_desa_kk_layanan.php?jenis=kk_hilang',
        'judul_mobile' => 'Kartu Keluarga Hilang',
        'teks' => 'Kartu Keluarga hilang dan dicetak ulang tanpa perubahan data. Siapkan surat keterangan hilang KK.',
    ],
    'cetak_biodata' => [
        'ikon' => '🪪',
        'tujuan' => 'petugas_desa_kk_layanan.php?jenis=cetak_biodata',
        'judul_mobile' => 'Cetak Biodata',
        'teks' => 'Cetak biodata sebagai pengganti KTP sementara, isinya lebih lengkap dari KTP. Cukup isi nama dan NIK.',
    ],
    'perubahan_data' => [
        'ikon' => '✏️',
        'tujuan' => 'petugas_desa_kk_layanan.php?jenis=perubahan_data',
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
  <title>Jenis Permohonan Kartu Keluarga (Petugas Desa)</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/petugas_desa_ajukan_permohonan.page.css?v=20260927-2">
  <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
  <style>
    /* Gaya sendiri: CSS halaman ini tidak memuat .btn-secondary,
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
      border: 2px solid var(--add-primary, #176b4d);
      border-radius: 10px;
      background: #ffffff;
      color: var(--add-primary, #176b4d);
      font-size: 16px;
      font-weight: 600;
      text-decoration: none;
      box-shadow: 0 1px 3px rgba(15, 23, 42, .12);
      transition: background-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .tombol-kembali:hover,
    .tombol-kembali:focus-visible {
      background: var(--add-primary, #176b4d);
      color: #ffffff;
      box-shadow: 0 4px 10px rgba(23, 107, 77, .3);
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
<body class="petugas-desa-ui">

  <div class="layout">

    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header petugas-desa-header">
        <?php
        require_once __DIR__ . '/petugas_desa_header_brand.php';
        render_petugas_desa_header_brand('Kartu Keluarga', 'Petugas Desa');
        ?>
        <div class="user-info">
          <span class="user-role">Petugas Desa</span>
          Hai, <?= esc_pilih($_SESSION['nama'] ?? '') ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Pilih Jenis Permohonan Kartu Keluarga</h2>
        <p class="text-gray-600 mb-8">Pilih kategori yang sesuai dengan kondisi warga agar dokumen yang diminta tepat:</p>

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

        <div class="tombol-kembali-baris">
          <a href="petugas_desa_ajukan_permohonan.php" class="tombol-kembali">
            <span aria-hidden="true">&#8592;</span> Kembali ke Menu Permohonan
          </a>
        </div>
      </section>
    </main>
  </div>

  <?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>

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
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (menuToggle && sidebar) {
      menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
      });
    }

    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');

    if (logoutBtn2) logoutBtn2.addEventListener('click', function() {
      logoutModal.classList.add('show');
      document.body.style.overflow = 'hidden';
    });
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout.php');
    if (cancelLogout) cancelLogout.addEventListener('click', function() {
      logoutModal.classList.remove('show');
      document.body.style.overflow = '';
    });
    if (logoutModal) {
      logoutModal.addEventListener('click', function(event) {
        if (event.target === logoutModal) {
          logoutModal.classList.remove('show');
          document.body.style.overflow = '';
        }
      });
    }
  });
  </script>

</body>
</html>
