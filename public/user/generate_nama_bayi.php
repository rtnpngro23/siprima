<?php
require_once '../error_handler.php';
include '../../app/middleware/user_or_petugas_desa.php';
$isPetugasDesa = ($_SESSION['role'] ?? '') === 'petugas_desa';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inspirasi Nama Bayi</title>
  <link rel="stylesheet" href="css/ajukan_permohonan.css">
  <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
  <link rel="stylesheet" href="css/baby-name-generator.css?v=20260909-2">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body<?= $isPetugasDesa ? ' class="petugas-desa-ui"' : '' ?>>
<div class="layout">
  <?php if ($isPetugasDesa): ?>
    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>
  <?php else: ?>
    <aside class="sidebar"><div class="logo">Pelayanan Kependudukan</div><nav><ul><li><a href="dashboard_user.php">Dashboard</a></li><li class="active"><a href="ajukan_permohonan.php">Ajukan Permohonan</a></li><li><a href="status_permohonan.php">Status Permohonan</a></li><li><a href="formulir_user.php">Formulir Kependudukan</a></li><li><a href="profil.php">Profil</a></li><li><a href="../logout.php">Logout</a></li></ul></nav></aside>
  <?php endif; ?>
  <div class="menu-toggle">☰</div>
  <main class="main">
    <header class="header<?= $isPetugasDesa ? ' petugas-desa-header' : ' user-general-header' ?>">
      <?php if ($isPetugasDesa): ?>
        <?php
        require_once __DIR__ . '/petugas_desa_header_brand.php';
        render_petugas_desa_header_brand('Inspirasi Nama Bayi', 'Adminduk Dari Desa (ADD)');
        ?>
      <?php else: ?>
        <?php
        require_once __DIR__ . '/user_header_brand.php';
        render_user_header_brand('Inspirasi Nama Bayi');
        ?>
      <?php endif; ?>
      <div class="user-info">Hai, <?= htmlspecialchars($_SESSION['nama'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8') ?></div>
    </header>
    <?php
    $babyNameApply = $isPetugasDesa ? 'petugas_desa_akta_kelahiran.php' : 'akta_kelahiran.php';
    // Inspirasi nama bayi berada di dalam menu Akta Kelahiran, jadi tombol kembali mengarah ke sana.
    $babyNameBack = $isPetugasDesa ? 'petugas_desa_akta_kelahiran_pilih_jenis.php' : 'akta_kelahiran_pilih_jenis.php';
    $babyNameBackLabel = 'Menu Akta Kelahiran';
    include __DIR__ . '/../../app/views/baby_name_generator_content.php';
    ?>
  </main>
</div>
<script src="../assets/js/baby-names.js?v=20260909-2" defer></script>
<script src="js/baby-name-generator.js?v=20260909-2" defer></script>
<?php if ($isPetugasDesa): ?>
  <?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
<?php else: ?>
  <?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
<?php endif; ?>
</body>
</html>
