<?php
$_userSidebarPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$_userSidebarPageMap = [
  'dashboard_user.php' => 'dashboard',
  'kia.php' => 'ajukan',
  'ajukan_permohonan.php' => 'ajukan',
  'akta_kelahiran.php' => 'ajukan',
  'akta_kematian.php' => 'ajukan',
  'kk.php' => 'ajukan',
  'lapor_peristiwa.php' => 'ajukan',
  'pindah_alamat.php' => 'ajukan',
  'pindah_online.php' => 'ajukan',
  'status_permohonan.php' => 'status',
  'status_lapor_peristiwa.php' => 'status',
  'edit_lapor_peristiwa.php' => 'status',
  'edit_kk.php' => 'status',
  'edit_permohonan_akta_kelahiran.php' => 'status',
  'edit_permohonan_akta_kematian.php' => 'status',
  'edit_pindah_alamat.php' => 'status',
  'edit_pindah_online.php' => 'status',
  'profil.php' => 'profil',
  'formulir_user.php' => 'formulir',
  'whistleblowing.php' => 'whistleblowing',
];
$_userSidebarActive = (string)($userSidebarActive ?? ($_userSidebarPageMap[$_userSidebarPage] ?? ''));
$_userSidebarItems = [
  'dashboard' => ['Dashboard', 'dashboard_user.php'],
  'ajukan' => ['Ajukan Permohonan', 'ajukan_permohonan.php'],
  'status' => ['Status Permohonan', 'status_permohonan.php'],
  'profil' => ['Profil', 'profil.php'],
  'formulir' => ['Formulir Kependudukan', 'formulir_user.php'],
  'whistleblowing' => ['Whistleblowing', 'whistleblowing.php'],
];
?>
<aside class="sidebar user-sidebar" aria-label="Navigasi akun masyarakat">
  <?php include __DIR__ . '/sidebar_brand.php'; ?>
  <nav class="user-sidebar__nav">
    <ul>
      <?php foreach ($_userSidebarItems as $_userSidebarKey => $_userSidebarItem): ?>
        <li class="<?= $_userSidebarActive === $_userSidebarKey ? 'active' : '' ?>">
          <a href="<?= htmlspecialchars($_userSidebarItem[1], ENT_QUOTES, 'UTF-8') ?>"<?= $_userSidebarActive === $_userSidebarKey ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($_userSidebarItem[0], ENT_QUOTES, 'UTF-8') ?></a>
        </li>
      <?php endforeach; ?>
      <li class="user-sidebar__logout-item">
        <button type="button" id="logoutBtn" class="user-sidebar__logout-button">Logout</button>
      </li>
    </ul>
  </nav>
</aside>
<?php
unset($_userSidebarPage, $_userSidebarPageMap, $_userSidebarActive, $_userSidebarItems, $_userSidebarKey, $_userSidebarItem);
?>
