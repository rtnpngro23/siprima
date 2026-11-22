<?php
$petugasMobilePage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$petugasMobilePageMap = [
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
    'petugas_desa_lapor_peristiwa.php' => 'more',
    'petugas_desa_formulir.php' => 'formulir',
    'petugas_desa_profil.php' => 'more',
];
$petugasMobileActive = (string)($petugasMobilePageMap[$petugasMobilePage] ?? '');
$petugasMobileClass = static fn(string $key): string => 'petugas-mobile-nav__item' . ($petugasMobileActive === $key ? ' is-active' : '');
$petugasMobileCurrent = static fn(string $key): string => $petugasMobileActive === $key ? ' aria-current="page"' : '';
?>
<nav class="petugas-mobile-nav" aria-label="Navigasi utama petugas desa">
    <a href="dashboard_petugas_desa.php" class="<?= $petugasMobileClass('dashboard') ?>"<?= $petugasMobileCurrent('dashboard') ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8"></path><path d="M5.5 9.5V21h13V9.5"></path><path d="M9.5 21v-6h5v6"></path></svg>
        <span>Dashboard</span>
    </a>
    <a href="petugas_desa_ajukan_permohonan.php" class="<?= $petugasMobileClass('ajukan') ?>"<?= $petugasMobileCurrent('ajukan') ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v8M8 12h8"></path></svg>
        <span>Ajukan</span>
    </a>
    <a href="petugas_desa_status_permohonan.php" class="<?= $petugasMobileClass('status') ?>"<?= $petugasMobileCurrent('status') ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8"></path><path d="M9 3h6v3H9z"></path><path d="M6 5h12v16H6z"></path><path d="m9 13 2 2 4-5"></path></svg>
        <span>Status</span>
    </a>
    <a href="petugas_desa_formulir.php" class="<?= $petugasMobileClass('formulir') ?>"<?= $petugasMobileCurrent('formulir') ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l3 3v15H6z"></path><path d="M15 3v4h4M9 11h6M9 15h6M9 18h4"></path></svg>
        <span>Formulir</span>
    </a>
    <button type="button" class="<?= $petugasMobileClass('more') ?>" id="petugasMobileMoreButton" aria-controls="petugasMobileMoreSheet" aria-expanded="false">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1"></circle><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle></svg>
        <span>Lainnya</span>
    </button>
</nav>

<div class="petugas-mobile-more-backdrop" id="petugasMobileMoreBackdrop" hidden></div>
<section class="petugas-mobile-more-sheet" id="petugasMobileMoreSheet" aria-hidden="true" aria-label="Menu lainnya">
    <div class="petugas-mobile-more-sheet__handle" aria-hidden="true"></div>
    <div class="petugas-mobile-more-sheet__header">
        <div><strong>Menu Lainnya</strong><span>Adminduk Dari Desa</span></div>
        <button type="button" id="petugasMobileMoreClose" aria-label="Tutup menu">&times;</button>
    </div>
    <a href="petugas_desa_lapor_peristiwa.php">Laporan Peristiwa</a>
    <a href="petugas_desa_profil.php">Profil Saya</a>
    <a class="petugas-mobile-more-sheet__logout" href="../logout.php" onclick="return confirm('Apakah Anda yakin ingin logout?')">Logout</a>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const button = document.getElementById('petugasMobileMoreButton');
    const sheet = document.getElementById('petugasMobileMoreSheet');
    const backdrop = document.getElementById('petugasMobileMoreBackdrop');
    const closeButton = document.getElementById('petugasMobileMoreClose');
    if (!button || !sheet || !backdrop || !closeButton) return;

    const closeSheet = function () {
        sheet.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        backdrop.hidden = true;
        sheet.setAttribute('aria-hidden', 'true');
        button.setAttribute('aria-expanded', 'false');
    };
    const openSheet = function () {
        backdrop.hidden = false;
        requestAnimationFrame(function () {
            sheet.classList.add('is-open');
            backdrop.classList.add('is-open');
        });
        sheet.setAttribute('aria-hidden', 'false');
        button.setAttribute('aria-expanded', 'true');
    };

    button.addEventListener('click', function () {
        sheet.classList.contains('is-open') ? closeSheet() : openSheet();
    });
    closeButton.addEventListener('click', closeSheet);
    backdrop.addEventListener('click', closeSheet);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeSheet();
    });
});
</script>
<?php unset($petugasMobilePage, $petugasMobilePageMap, $petugasMobileActive, $petugasMobileClass, $petugasMobileCurrent); ?>
