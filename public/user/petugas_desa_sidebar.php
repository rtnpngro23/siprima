<?php
$petugasSidebarPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$petugasSidebarPageMap = [
    'dashboard_petugas_desa.php' => 'dashboard',
    'petugas_desa_ajukan_permohonan.php' => 'ajukan',
    'petugas_desa_akta_kelahiran.php' => 'ajukan',
    'petugas_desa_akta_kematian.php' => 'ajukan',
    'petugas_desa_kk.php' => 'ajukan',
    'petugas_desa_pindah_alamat.php' => 'ajukan',
    'petugas_desa_pindah_online.php' => 'ajukan',
    'generate_nama_bayi.php' => 'ajukan',
    'petugas_desa_status_permohonan.php' => 'status',
    'petugas_desa_edit_akta_kelahiran.php' => 'status',
    'petugas_desa_edit_akta_kematian.php' => 'status',
    'petugas_desa_edit_kk.php' => 'status',
    'petugas_desa_edit_pindah_alamat.php' => 'status',
    'petugas_desa_edit_pindah_online.php' => 'status',
    'petugas_desa_lapor_peristiwa.php' => 'laporan',
    'petugas_desa_formulir.php' => 'formulir',
    'petugas_desa_profil.php' => 'profil',
];
$petugasSidebarActive = (string)($petugasSidebarPageMap[$petugasSidebarPage] ?? '');
$petugasSidebarItems = [
    'dashboard' => ['Dashboard', 'dashboard_petugas_desa.php'],
    'ajukan' => ['Ajukan Permohonan', 'petugas_desa_ajukan_permohonan.php'],
    'status' => ['Status Permohonan', 'petugas_desa_status_permohonan.php'],
    'laporan' => ['Laporan Peristiwa', 'petugas_desa_lapor_peristiwa.php'],
    'formulir' => ['Formulir Kependudukan', 'petugas_desa_formulir.php'],
    'profil' => ['Profil', 'petugas_desa_profil.php'],
];
?>
<aside class="sidebar petugas-desa-sidebar" aria-label="Navigasi petugas desa">
    <div class="logo petugas-desa-sidebar__brand">
        <img src="../assets/add.png" alt="Logo ADD" width="92" height="92">
        <span>Adminduk Dari Desa</span>
        <strong>(ADD)</strong>
    </div>
    <nav class="petugas-desa-sidebar__nav">
        <ul>
            <?php foreach ($petugasSidebarItems as $petugasSidebarKey => $petugasSidebarItem): ?>
                <li class="<?= $petugasSidebarActive === $petugasSidebarKey ? 'active' : '' ?>">
                    <a href="<?= htmlspecialchars($petugasSidebarItem[1], ENT_QUOTES, 'UTF-8') ?>"<?= $petugasSidebarActive === $petugasSidebarKey ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($petugasSidebarItem[0], ENT_QUOTES, 'UTF-8') ?></a>
                </li>
            <?php endforeach; ?>
            <li class="petugas-desa-sidebar__logout-item">
                <button type="button" id="logoutBtn" class="petugas-desa-sidebar__logout-button">Logout</button>
            </li>
        </ul>
    </nav>
</aside>
<?php
unset(
    $petugasSidebarPage,
    $petugasSidebarPageMap,
    $petugasSidebarActive,
    $petugasSidebarItems,
    $petugasSidebarKey,
    $petugasSidebarItem
);
?>
