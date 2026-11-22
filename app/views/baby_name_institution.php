<?php
if (!isset($babyNameModule, $_SESSION['id_user']) || !in_array($babyNameModule, ['ahlak', 'rs'], true)) {
    http_response_code(403);
    exit;
}
$babyNameApply = $babyNameModule . '_akta_kelahiran.php';
$babyNameBack = $babyNameModule . '_ajukan_permohonan.php';
$babyNameBrand = $babyNameModule === 'ahlak' ? 'Pelayanan Kependudukan AHLAK' : 'ST SEHATI';
$babyNameFacility = $babyNameModule === 'ahlak' ? ($_SESSION['nama_puskes'] ?? 'Puskesmas') : ($_SESSION['nama_rs'] ?? 'Rumah Sakit');
$babyNameLogout = '../logout_pasutri_ahlak.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inspirasi Nama Bayi</title>
  <link rel="stylesheet" href="../user/css/ajukan_permohonan.css">
  <link rel="stylesheet" href="../user/css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="../user/css/baby-name-generator.css?v=20260909-2">
</head>
<body>
<div class="layout">
  <aside class="sidebar"><div class="logo"><?= htmlspecialchars($babyNameBrand, ENT_QUOTES, 'UTF-8') ?></div><nav><ul>
    <li><a href="<?= $babyNameModule ?>_dashboard_user.php">Dashboard</a></li>
    <li class="active"><a href="<?= $babyNameBack ?>" aria-current="page">Ajukan Permohonan</a></li>
    <li><a href="<?= $babyNameModule ?>_status_permohonan.php">Status Permohonan</a></li>
    <li><a href="<?= $babyNameModule ?>_formulir.php">Formulir Kependudukan</a></li>
    <li><a href="<?= $babyNameModule ?>_profil.php">Profil</a></li>
    <li><a href="<?= $babyNameLogout ?>">Logout</a></li>
  </ul></nav></aside>
  <button type="button" class="menu-toggle" aria-label="Buka menu">☰</button>
  <main class="main">
    <header class="header"><div><div class="title">Inspirasi Nama Bayi</div><p><?= htmlspecialchars($babyNameFacility, ENT_QUOTES, 'UTF-8') ?></p></div><div class="user-info">Hai, <?= htmlspecialchars($_SESSION['nama'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8') ?></div></header>
    <?php include __DIR__ . '/baby_name_generator_content.php'; ?>
  </main>
</div>
<script src="../assets/js/baby-names.js?v=20260909-2" defer></script>
<script src="../user/js/baby-name-generator.js?v=20260909-2" defer></script>
</body>
</html>
