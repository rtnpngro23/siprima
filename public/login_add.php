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

$throttle_status = login_throttle_status($username_value);
$show_captcha = $throttle_status['requires_captcha'];
$captcha_question = $show_captcha ? login_captcha_prepare() : '';
$service_hours = service_hours_status();
$service_event_notice = service_hours_event_notice();
$show_service_notice = !$service_hours['is_open'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SI PRIMA</title>
    <link rel="stylesheet" href="assets/css/login_pasutri_ahlak.css">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261118-4">
    <script src="assets/js/theme.js?v=20261118-1"></script>
    <link rel="icon" type="image/webp" href="assets/prima.webp">
    <style>
        .login-box {
            background: #ffffff !important;
            border: 1px solid #cbd5e1;
            color: #0f172a;
        }
        .login-box h2 {
            color: #0f172a;
        }
        .login-box .subtitle {
            color: #475569;
        }
        .login-box label {
            color: #1e293b;
        }
        .login-box input[type="text"],
        .login-box input[type="password"] {
            background: #f8fafc !important;
            border: 1.5px solid #94a3b8 !important;
            color: #0f172a !important;
        }
        .login-box input::placeholder {
            color: #64748b;
        }
        .login-box .footer-text {
            color: #334155;
        }
        .login-box .footer-text a {
            color: #0e7490;
        }
    </style>
</head>
<body>

<nav class="top-nav">
    <div class="nav-brand">
        <span class="brand-icon">SI PRIMA</span>
        <span class="brand-subtitle">Sistem Informasi Pelayanan Terintegrasi Identitas Masyarakat</span>
    </div>
    <a href="index.php" class="btn-home">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
        </svg>
        Halaman Utama
    </a>
</nav>

<div class="login-container">
    <div class="login-box">
        <!-- GAMBAR ADD DAN LINE - HANYA ICON -->
        <div class="logo-images-container">
            <img src="../assets/add.png" 
                 alt="ADD" 
                 class="logo-image">
                </div>
                 
        <h2>Login Petugas ADD</h2>
        <p class="subtitle">Portal Adminduk Dari Desa (ADD) — Silakan masuk untuk melanjutkan</p>

        <!-- NOTIFIKASI ERROR YANG DIPERBAIKI -->
        <?php if (!empty($error_message) && !$throttle_status['is_locked']): ?>
            <div class="error-notification <?= in_array($error_type, ['nik_not_found', 'password_wrong', 'credentials_invalid'], true) ? 'error-credentials-invalid' : ($error_type === 'password_wrong' ? 'error-password-wrong' : '') ?>" role="alert" aria-live="polite">
                <div class="error-notification-content">
                    <div class="error-title"><?= htmlspecialchars($error_title) ?></div>
                    <p class="error-message"><?= htmlspecialchars($error_message) ?></p>
                    
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
                    <div class="error-title">Terlalu Banyak Percobaan</div>
                    <p class="error-message">Login dikunci sementara. Coba lagi dalam <?= (int)ceil($throttle_status['remaining_lock_seconds'] / 60) ?> menit.</p>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($service_event_notice !== null): ?>
            <div class="error-notification" role="status">
                <div class="error-notification-content">
                    <div class="error-title"><?= htmlspecialchars($service_event_notice['title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <p class="error-message"><?= htmlspecialchars($service_event_notice['message'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($show_service_notice): ?>
            <div class="error-notification" role="alert" aria-live="polite">
                <div class="error-notification-content">
                    <div class="error-title">Pengajuan sementara tidak tersedia</div>
                    <p class="error-message"><?= htmlspecialchars(service_hours_closed_message()) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <form action="proses_login.php?type=petugas_desa" method="POST" id="loginForm">
<?php echo csrf_input(); ?>
            <label>NIK</label>
            <input type="text" 
                   name="username" 
                   id="nikInput"
                   placeholder="Masukkan NIK (16 digit)" 
                   maxlength="16" 
                   value="<?= htmlspecialchars($username_value) ?>"
                   required 
                   autofocus
                   <?= in_array($error_type, ['nik_not_found', 'credentials_invalid'], true) ? 'class="error" aria-invalid="true"' : '' ?>>
            <div id="nikError" class="validation-error">
                NIK harus 16 digit angka
            </div>

            <label>Password</label>
            <div class="password-wrapper">
                <input type="password" 
                       id="password" 
                       name="password" 
                       placeholder="Masukkan password" 
                       required
                       <?= in_array($error_type, ['password_wrong', 'credentials_invalid'], true) ? 'class="error" aria-invalid="true"' : '' ?>>
                <span id="togglePassword" class="toggle">👁️</span>
            </div>
            <div id="passwordError" class="validation-error">
                Password harus diisi
            </div>
            <?php if ($show_captcha): ?>
                <label for="captchaAnswer">Verifikasi CAPTCHA: <?= htmlspecialchars($captcha_question) ?> = ?</label>
                <input type="text" name="captcha_answer" id="captchaAnswer" placeholder="Masukkan hasil perhitungan" inputmode="numeric" autocomplete="off" required>
            <?php endif; ?>

            <button type="submit" class="btn-login">Login</button>
        </form>

        <p class="footer-text">Belum punya akun? <a href="register_petugas_desa.php">Register</a></p>
        <p class="footer-text"><a href="https://api.whatsapp.com/send?phone=6281243425557" target="_blank" rel="noopener noreferrer">Klik Disini</a> Hubungi Admin untuk Cek Registrasi Petugas ADD</p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle password visibility
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️' : '🙈';
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
            
            // Only allow numbers
            this.value = value.replace(/\D/g, '');
            
            // Limit to 16 digits
            if (this.value.length > 16) {
                this.value = this.value.slice(0, 16);
            }
            
            // Validate and show/hide error
            if (this.value.length === 16) {
                nikError.style.display = 'none';
                this.classList.remove('error');
                this.removeAttribute('aria-invalid');
            } else if (this.value.length > 0) {
                nikError.style.display = 'block';
                this.classList.add('error');
                this.setAttribute('aria-invalid', 'true');
            } else {
                nikError.style.display = 'none';
                this.classList.remove('error');
                this.removeAttribute('aria-invalid');
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
        
        // Validate NIK
        const nik = nikInput.value.trim();
        if (!/^\d{16}$/.test(nik)) {
            nikError.style.display = 'block';
            nikInput.classList.add('error');
            nikInput.setAttribute('aria-invalid', 'true');
            isValid = false;
        }
        
        // Validate Password
        const password = passwordInput.value;
        if (password.length === 0) {
            passwordError.style.display = 'block';
            passwordInput.classList.add('error');
            passwordInput.setAttribute('aria-invalid', 'true');
            isValid = false;
        }
        
        if (!isValid) {
            event.preventDefault();
            // Focus on first error field
            if (!/^\d{16}$/.test(nik)) {
                nikInput.focus();
            } else if (password.length === 0) {
                passwordInput.focus();
            }
        }
    });
    
    // Auto-focus on NIK field
    if (nikInput) {
        nikInput.focus();
    }
    
    // Jika ada error, tambahkan animasi pulse pada field yang error
    setTimeout(() => {
        const errorFields = document.querySelectorAll('input.error');
        errorFields.forEach(field => {
            field.style.animation = 'pulseError 0.6s ease';
            setTimeout(() => {
                field.style.animation = '';
            }, 600);
        });
    }, 500);
});
</script>

</body>
</html>
