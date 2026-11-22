<?php
require_once __DIR__ . '/error_handler.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../app/security/login_security.php';
require_once __DIR__ . '/../app/config/service_hours.php';
$error_message = '';
$error_type = '';
$error_title = '';
$username_value = '';
$suggestion = '';
$service_hours = service_hours_status();
$service_event_notice = service_hours_event_notice();

// Tampilkan error dari session jika ada
if (isset($_SESSION['login_error'])) {
    $error_message = $_SESSION['login_error'];
    $error_type = $_SESSION['error_type'] ?? 'general';
    $error_title = $_SESSION['error_title'] ?? 'Error';
    $suggestion = $_SESSION['suggestion'] ?? '';
    
    unset($_SESSION['login_error'], $_SESSION['error_type'], $_SESSION['error_title'], $_SESSION['suggestion']);
}

// Tampilkan kembali username jika login gagal
if (isset($_SESSION['login_username'])) {
    $username_value = $_SESSION['login_username'];
    unset($_SESSION['login_username']);
}

// Tampilkan pesan ramah pengguna berdasarkan query error (mis. redirect dari middleware)
$query_error = $_GET['error'] ?? '';
if (empty($error_message) && $query_error === 'service_hour_ended') {
    if ($service_event_notice !== null && $service_event_notice['state'] === 'active') {
        $error_type = 'service_event';
        $error_title = $service_event_notice['title'];
        $error_message = $service_event_notice['message'];
    } else {
        $error_type = 'general';
        $error_title = 'Pengajuan sementara tidak tersedia';
        $error_message = $service_hours['is_open'] ? 'Anda tetap dapat login untuk menggunakan layanan.' : service_hours_closed_message();
    }
}
if (empty($error_message) && $query_error === 'service_closed_holiday') {
    $error_type = 'general';
    $error_title = 'Pengajuan sementara tidak tersedia';
    $error_message = service_hours_closed_message();
}

// Dapatkan tipe login dari URL (opsional)
$login_type = $_GET['type'] ?? 'general';
$login_types = [
    'general' => 'Sistem Informasi Pelayanan Terintegrasi Identitas Masyarakat',
    'kua' => 'Sistem Pelayanan Kependudukan KUA',
    'puskesmas' => 'Sistem Pelayanan Kesehatan Masyarakat',
    'rs' => 'Sistem Pelayanan Rumah Sakit'
];
$system_name = $login_types[$login_type] ?? $login_types['general'];
$throttle_status = login_throttle_status($username_value);
$show_captcha = $throttle_status['requires_captcha'];
$captcha_question = $show_captcha ? login_captcha_prepare() : '';
$is_always_open_type = in_array($login_type, ['kua', 'puskesmas', 'rs'], true);
$show_service_event_notice = $service_event_notice !== null
    && !$is_always_open_type
    && $error_type !== 'service_event';
$show_service_notice = !$service_hours['is_open']
    && !$is_always_open_type
    && ($service_event_notice === null || $service_event_notice['type'] !== 'closure' || $service_event_notice['state'] !== 'active');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk - SI PRIMA Disdukcapil Kabupaten Gorontalo</title>
    <link rel="icon" type="image/png" href="assets/favicon-logo-dukcapil.png">
    <link rel="stylesheet" href="assets/css/login.css?v=20261118-3">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261118-14">
    <script src="assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<nav class="top-nav">
    <div class="nav-brand-wrap">
        <a href="index.php" class="nav-brand">
            <div class="brand-emblems">
                <img src="assets/logo-kabupaten-gorontalo.jpg" alt="Lambang Pemerintah Kabupaten Gorontalo" class="nav-emblem">
                <span class="emblem-divider" aria-hidden="true"></span>
                <img src="assets/favicon-logo-dukcapil.png" alt="Logo Dukcapil Kabupaten Gorontalo" class="nav-emblem">
            </div>
            <div class="nav-brand-text">
                <span class="brand-icon">SI PRIMA</span>
                <span class="brand-subtitle"><?= htmlspecialchars($system_name, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </a>
        <a href="index.php" class="btn-home">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
            Halaman Utama
        </a>
    </div>
</nav>

<main class="login-container">
    <div class="login-shell">
        <aside class="login-aside">
            <div class="aside-header">
                <span class="aside-badge">Disdukcapil Kabupaten Gorontalo</span>
                <div class="login-logo">
                    <img src="assets/prima.webp" alt="Logo SI PRIMA" class="login-logo-img" onerror="this.style.display='none'">
                </div>
                <h1 class="aside-title">Portal Pelayanan Administrasi Kependudukan</h1>
                <p class="aside-desc">Masuk menggunakan akun terdaftar untuk mengajukan permohonan, memantau status berkas, dan mengunduh dokumen kependudukan.</p>
            </div>

            <ul class="aside-list">
                <li>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>Gunakan NIK 16 digit yang terdaftar pada sistem SI PRIMA.</span>
                </li>
                <li>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>Dokumen hasil layanan dapat dipantau dan diunduh melalui dashboard.</span>
                </li>
                <li>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>Keamanan akun dilindungi validasi sesi dan enkripsi kata sandi.</span>
                </li>
            </ul>
        </aside>

        <section class="login-box" aria-label="Formulir Masuk">
            <div class="login-box-header">
                <h2>Masuk ke Akun</h2>
                <p class="subtitle">Silakan masukkan NIK dan kata sandi Anda</p>
            </div>

            <!-- System Type Indicator -->
            <?php if ($login_type !== 'general'): ?>
                <div class="system-type">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M3 21h18"></path>
                        <path d="M5 21V7l8-4v18"></path>
                        <path d="M19 21V11l-6-4"></path>
                    </svg>
                    <span>
                        <?php
                        if ($login_type === 'kua') echo 'Login untuk Pengguna KUA';
                        elseif ($login_type === 'puskesmas') echo 'Login Khusus Petugas AHLAK / Puskesmas';
                        elseif ($login_type === 'rs') echo 'Login untuk Pengguna Rumah Sakit';
                        else echo 'Login Pengguna Umum';
                        ?>
                    </span>
                </div>
            <?php endif; ?>

            <!-- NOTIFIKASI ERROR -->
            <?php if (!empty($error_message) && !$throttle_status['is_locked']): ?>
                <div class="error-notification <?= $error_type === 'service_event' ? 'service-event-notification' : (in_array($error_type, ['nik_not_found', 'password_wrong', 'credentials_invalid'], true) ? 'error-credentials-invalid' : ($error_type === 'password_wrong' ? 'error-password-wrong' : '')) ?>" role="alert" aria-live="polite">
                    <div class="error-notification-content">
                        <div class="error-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            <?= htmlspecialchars($error_title, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <p class="error-message"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></p>

                        <?php if (!empty($suggestion)): ?>
                            <div class="suggestion-box">
                                <?= $suggestion ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($throttle_status['is_locked']): ?>
                <div class="error-notification" role="alert" aria-live="polite">
                    <div class="error-notification-content">
                        <div class="error-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            Terlalu Banyak Percobaan
                        </div>
                        <p class="error-message">Login dikunci sementara. Coba lagi dalam <?= (int)ceil($throttle_status['remaining_lock_seconds'] / 60) ?> menit.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($show_service_event_notice): ?>
                <div class="error-notification service-event-notification" role="status" aria-live="polite">
                    <div class="error-notification-content">
                        <div class="error-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="16" x2="12" y2="12"></line>
                                <line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                            <?= htmlspecialchars($service_event_notice['title'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <p class="error-message"><?= htmlspecialchars($service_event_notice['message'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($show_service_notice): ?>
                <div class="error-notification" role="alert" aria-live="polite">
                    <div class="error-notification-content">
                        <div class="error-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            Pengajuan sementara tidak tersedia
                        </div>
                        <p class="error-message"><?= htmlspecialchars(service_hours_closed_message(), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <form action="proses_login.php<?= $login_type !== 'general' ? '?type=' . htmlspecialchars($login_type, ENT_QUOTES, 'UTF-8') : '' ?>" method="POST" id="loginForm">
                <?php echo csrf_input(); ?>
                <div class="form-field">
                    <label for="nikInput">NIK</label>
                    <input type="text"
                           name="username"
                           id="nikInput"
                           placeholder="Masukkan NIK (16 digit)"
                           maxlength="16"
                           inputmode="numeric"
                           value="<?= htmlspecialchars($username_value, ENT_QUOTES, 'UTF-8') ?>"
                           required
                           autofocus
                           <?= in_array($error_type, ['nik_not_found', 'credentials_invalid'], true) ? 'class="error" aria-invalid="true"' : '' ?>>
                    <div id="nikError" class="validation-error">
                        NIK harus 16 digit angka
                    </div>
                </div>

                <div class="form-field">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password"
                               id="password"
                               name="password"
                               placeholder="Masukkan password"
                               required
                               <?= in_array($error_type, ['password_wrong', 'credentials_invalid'], true) ? 'class="error" aria-invalid="true"' : '' ?>>
                        <button type="button" id="togglePassword" class="toggle" aria-label="Tampilkan password">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                    <div id="passwordError" class="validation-error">
                        Password harus diisi
                    </div>
                </div>

                <?php if ($show_captcha): ?>
                    <div class="form-field">
                        <label for="captchaAnswer">Verifikasi CAPTCHA: <?= htmlspecialchars($captcha_question, ENT_QUOTES, 'UTF-8') ?> = ?</label>
                        <input type="text" name="captcha_answer" id="captchaAnswer" placeholder="Masukkan hasil perhitungan" inputmode="numeric" autocomplete="off" required>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-login">Masuk</button>
            </form>

            <div class="login-footer-links">
                <p class="footer-text">Belum punya akun? <a href="register.php">Daftar Akun</a></p>
                <p class="footer-text footer-help">
                    <a href="https://api.whatsapp.com/send?phone=6281243425557" target="_blank" rel="noopener noreferrer">Lupa Password? Hubungi Admin</a>
                </p>
            </div>
        </section>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const eyeOpenSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    const eyeClosedSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

    // Toggle password visibility
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.innerHTML = type === 'password' ? eyeOpenSvg : eyeClosedSvg;
            this.setAttribute('aria-label', type === 'password' ? 'Tampilkan password' : 'Sembunyikan password');
        });
    }

    // Form validation
    const loginForm = document.getElementById('loginForm');
    const nikInput = document.getElementById('nikInput');
    const nikError = document.getElementById('nikError');
    const passwordError = document.getElementById('passwordError');

    // Real-time NIK validation
    if (nikInput) {
        nikInput.addEventListener('input', function() {
            const value = this.value;
            this.value = value.replace(/\D/g, '');
            if (this.value.length > 16) {
                this.value = this.value.slice(0, 16);
            }

            if (this.value.length === 16) {
                nikError.style.display = 'none';
                this.classList.remove('error');
                this.removeAttribute('aria-invalid');
                this.style.borderColor = '#15803d';
                this.style.boxShadow = '0 0 0 3px rgba(21, 128, 61, 0.12)';
                setTimeout(() => {
                    this.style.borderColor = '';
                    this.style.boxShadow = '';
                }, 1000);
            } else if (this.value.length > 0) {
                nikError.style.display = 'block';
                this.classList.add('error');
                this.setAttribute('aria-invalid', 'true');
                this.style.borderColor = '';
                this.style.boxShadow = '';
            } else {
                nikError.style.display = 'none';
                this.classList.remove('error');
                this.removeAttribute('aria-invalid');
                this.style.borderColor = '';
                this.style.boxShadow = '';
            }
        });
    }

    // Real-time password validation
    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            if (this.value.length > 0) {
                passwordError.style.display = 'none';
                this.classList.remove('error');
                this.removeAttribute('aria-invalid');
            } else {
                passwordError.style.display = 'block';
                this.classList.add('error');
                this.setAttribute('aria-invalid', 'true');
            }
        });
    }

    // Form submission validation
    loginForm.addEventListener('submit', function(event) {
        let isValid = true;

        const nik = nikInput.value.trim();
        if (!/^\d{16}$/.test(nik)) {
            nikError.style.display = 'block';
            nikInput.classList.add('error');
            nikInput.setAttribute('aria-invalid', 'true');
            nikInput.style.animation = 'pulseError 0.6s ease';
            isValid = false;
        }

        const password = passwordInput.value;
        if (password.length === 0) {
            passwordError.style.display = 'block';
            passwordInput.classList.add('error');
            passwordInput.setAttribute('aria-invalid', 'true');
            passwordInput.style.animation = 'pulseError 0.6s ease';
            isValid = false;
        }

        if (!isValid) {
            event.preventDefault();
            if (!/^\d{16}$/.test(nik)) {
                nikInput.focus();
            } else if (password.length === 0) {
                passwordInput.focus();
            }
            setTimeout(() => {
                document.querySelectorAll('input.error').forEach(field => {
                    field.style.animation = '';
                });
            }, 600);
        } else {
            const submitBtn = this.querySelector('.btn-login');
            if (submitBtn) {
                submitBtn.textContent = 'Memproses...';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.75';
            }
        }
    });

    if (nikInput) {
        setTimeout(() => {
            nikInput.focus();
        }, 300);
    }

    setTimeout(() => {
        document.querySelectorAll('input.error').forEach(field => {
            field.style.animation = 'pulseError 0.6s ease';
            setTimeout(() => {
                field.style.animation = '';
            }, 600);
        });
    }, 500);

    document.addEventListener('keydown', function(event) {
        if (event.ctrlKey && event.key === 'Enter') {
            const submitBtn = loginForm.querySelector('.btn-login');
            if (submitBtn) {
                submitBtn.click();
            }
        }
        if (event.key === 'Escape') {
            nikInput.value = '';
            passwordInput.value = '';
            nikInput.focus();
        }
    });
});
</script>

</body>
</html>
