<?php
$mobile_nav_current_page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$mobile_nav_ajukan_pages = [
    'ajukan_permohonan.php',
    'lapor_peristiwa.php',
    'kk.php',
    'pindah_alamat.php',
    'pindah_online.php',
    'akta_kelahiran.php',
    'akta_kematian.php',
    'generate_nama_bayi.php',
    'edit_lapor_peristiwa.php',
    'edit_kk.php',
    'edit_pindah_alamat.php',
    'edit_pindah_online.php',
    'edit_permohonan_akta_kelahiran.php',
    'edit_permohonan_akta_kematian.php',
];
$mobile_nav_more_pages = [
    'profil.php',
    'whistleblowing.php',
];

$mobile_nav_active_page = $mobile_nav_current_page;
if (in_array($mobile_nav_current_page, $mobile_nav_ajukan_pages, true)) {
    $mobile_nav_active_page = 'ajukan_permohonan.php';
} elseif (in_array($mobile_nav_current_page, $mobile_nav_more_pages, true)) {
    $mobile_nav_active_page = 'more';
}

$mobile_nav_item_class = static function (string $page) use ($mobile_nav_active_page): string {
    return 'mobile-bottom-nav__item' . ($mobile_nav_active_page === $page ? ' is-active' : '');
};

$mobile_nav_current_attribute = static function (string $page) use ($mobile_nav_active_page): string {
    return $mobile_nav_active_page === $page ? ' aria-current="page"' : '';
};
?>
<nav class="mobile-bottom-nav" aria-label="Navigasi utama pengguna">
    <a href="dashboard_user.php" class="<?= $mobile_nav_item_class('dashboard_user.php') ?>"<?= $mobile_nav_current_attribute('dashboard_user.php') ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 10.8 12 3l9 7.8"></path>
            <path d="M5.5 9.5V21h13V9.5"></path>
            <path d="M9.5 21v-6h5v6"></path>
        </svg>
        <span>Dashboard</span>
    </a>
    <a href="ajukan_permohonan.php" class="<?= $mobile_nav_item_class('ajukan_permohonan.php') ?>"<?= $mobile_nav_current_attribute('ajukan_permohonan.php') ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 8v8M8 12h8"></path>
        </svg>
        <span>Ajukan</span>
    </a>
    <a href="status_permohonan.php" class="<?= $mobile_nav_item_class('status_permohonan.php') ?>"<?= $mobile_nav_current_attribute('status_permohonan.php') ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M8 4h8"></path>
            <path d="M9 3h6v3H9z"></path>
            <path d="M6 5h12v16H6z"></path>
            <path d="m9 13 2 2 4-5"></path>
        </svg>
        <span>Status</span>
    </a>
    <a href="formulir_user.php" class="<?= $mobile_nav_item_class('formulir_user.php') ?>"<?= $mobile_nav_current_attribute('formulir_user.php') ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 3h8l4 4v14H6z"></path>
            <path d="M14 3v5h4M9 12h6M9 16h6"></path>
        </svg>
        <span>Formulir</span>
    </a>
    <button type="button" class="<?= $mobile_nav_item_class('more') ?>" id="mobileBottomNavMoreButton" aria-controls="mobileBottomNavMoreSheet" aria-expanded="false"<?= $mobile_nav_current_attribute('more') ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="5" cy="12" r="1"></circle>
            <circle cx="12" cy="12" r="1"></circle>
            <circle cx="19" cy="12" r="1"></circle>
        </svg>
        <span>Lainnya</span>
    </button>
</nav>

<div class="mobile-bottom-nav-more-backdrop" id="mobileBottomNavMoreBackdrop" hidden></div>
<section class="mobile-bottom-nav-more-sheet" id="mobileBottomNavMoreSheet" role="dialog" aria-modal="true" aria-labelledby="mobileBottomNavMoreTitle" aria-hidden="true" hidden>
    <div class="mobile-bottom-nav-more-sheet__handle" aria-hidden="true"></div>
    <div class="mobile-bottom-nav-more-sheet__header">
        <div>
            <strong id="mobileBottomNavMoreTitle">Menu Lainnya</strong>
            <span>Akun masyarakat SI PRIMA</span>
        </div>
        <button type="button" id="mobileBottomNavMoreClose" aria-label="Tutup menu">&times;</button>
    </div>
    <nav class="mobile-bottom-nav-more-sheet__menu" aria-label="Menu lainnya">
        <a href="whistleblowing.php"<?= $mobile_nav_current_page === 'whistleblowing.php' ? ' aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.6 2.8 8.1 7 10 4.2-1.9 7-5.4 7-10V6z"></path><path d="M12 8v5M12 16h.01"></path></svg>
            <span><strong>Whistleblowing</strong><small>Pengaduan rahasia</small></span>
            <span class="mobile-bottom-nav-more-sheet__chevron" aria-hidden="true">›</span>
        </a>
        <a href="profil.php"<?= $mobile_nav_current_page === 'profil.php' ? ' aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4.5 21a7.5 7.5 0 0 1 15 0"></path></svg>
            <span><strong>Profil Saya</strong><small>Kelola data akun</small></span>
            <span class="mobile-bottom-nav-more-sheet__chevron" aria-hidden="true">›</span>
        </a>
        <button type="button" class="mobile-bottom-nav-more-sheet__logout" id="mobileBottomNavLogoutButton">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H5v16h5M14 8l4 4-4 4M18 12H9"></path></svg>
            <span><strong>Logout</strong><small>Keluar dari akun</small></span>
        </button>
    </nav>
</section>

<script>
(function () {
    const button = document.getElementById('mobileBottomNavMoreButton');
    const sheet = document.getElementById('mobileBottomNavMoreSheet');
    const backdrop = document.getElementById('mobileBottomNavMoreBackdrop');
    const closeButton = document.getElementById('mobileBottomNavMoreClose');
    const logoutButton = document.getElementById('mobileBottomNavLogoutButton');
    let hideTimer = null;

    if (!button || !sheet || !backdrop || !closeButton) return;

    const closeSheet = function (restoreFocus = true) {
        window.clearTimeout(hideTimer);
        sheet.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        sheet.setAttribute('aria-hidden', 'true');
        button.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('mobile-bottom-nav-sheet-open');
        hideTimer = window.setTimeout(function () {
            sheet.hidden = true;
            backdrop.hidden = true;
        }, 250);
        if (restoreFocus) button.focus();
    };

    const openSheet = function () {
        window.clearTimeout(hideTimer);
        sheet.hidden = false;
        backdrop.hidden = false;
        sheet.setAttribute('aria-hidden', 'false');
        button.setAttribute('aria-expanded', 'true');
        document.body.classList.add('mobile-bottom-nav-sheet-open');
        window.requestAnimationFrame(function () {
            sheet.classList.add('is-open');
            backdrop.classList.add('is-open');
            closeButton.focus();
        });
    };

    button.addEventListener('click', function () {
        sheet.classList.contains('is-open') ? closeSheet() : openSheet();
    });
    closeButton.addEventListener('click', function () { closeSheet(); });
    backdrop.addEventListener('click', function () { closeSheet(); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && sheet.classList.contains('is-open')) closeSheet();
    });

    logoutButton?.addEventListener('click', function () {
        closeSheet(false);
        window.clearTimeout(hideTimer);
        sheet.hidden = true;
        backdrop.hidden = true;
        const logoutModal = document.getElementById('logoutModal');
        if (logoutModal) {
            logoutModal.classList.add('show');
            logoutModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            return;
        }
        if (window.confirm('Apakah Anda yakin ingin logout?')) {
            window.location.href = '../logout.php';
        }
    });
})();
</script>
<?php unset($mobile_nav_current_page, $mobile_nav_ajukan_pages, $mobile_nav_more_pages, $mobile_nav_active_page, $mobile_nav_item_class, $mobile_nav_current_attribute); ?>
