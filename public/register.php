<?php
require_once 'error_handler.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Halaman ini memuat token per-session dan tidak boleh disajikan dari cache lama.
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Vary: Cookie', false);

// Token diminta ulang tepat sebelum form dikirim. Endpoint tetap bergantung pada
// session cookie yang sama, sehingga proteksi CSRF tidak dilemahkan.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['csrf_refresh'] ?? '') === '1') {
    $freshCsrfToken = csrf_token();
    header('Content-Type: application/json; charset=UTF-8');

    // Lepaskan session lock sebelum respons dikirim agar permintaan berikutnya
    // dari browser yang sama tidak tertahan.
    session_write_close();

    echo json_encode([
        'success' => true,
        'csrf_token' => $freshCsrfToken,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Inisialisasi variabel
$errors = [];
$duplicateAlertMessage = '';
$formData = [
    'nama' => '',
    'nik' => '',
    'email' => '',
    'alamat' => '',
    'no_hp' => ''
];

// 1. PENGGUNAAN KONEKSI TERPUSAT
// Pastikan path ini sesuai dengan struktur folder yang Anda buat (misal di folder app/config/)
include '../app/config/koneksi.php'; 

// Proses form jika method POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil data dari form
    $formData = [
        'nama' => $_POST['nama'] ?? '',
        'nik' => $_POST['nik'] ?? '',
        'email' => $_POST['email'] ?? '',
        'alamat' => $_POST['alamat'] ?? '',
        'no_hp' => $_POST['no_hp'] ?? ''
    ];
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validasi (tetap sama)
    if (empty($formData['nama'])) $errors[] = 'Nama lengkap harus diisi';
    if (empty($formData['nik'])) $errors[] = 'NIK harus diisi';
    elseif (!preg_match("/^[0-9]{16}$/", $formData['nik'])) $errors[] = 'NIK harus 16 digit angka';
    
    if (empty($formData['email'])) $errors[] = 'Email harus diisi';
    elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid';
    
    if (empty($password)) $errors[] = 'Password harus diisi';
    elseif (($passwordError = siprima_password_error($password)) !== null) $errors[] = $passwordError;
    
    if ($password !== $confirm_password) $errors[] = 'Konfirmasi password tidak cocok';
    
    if (empty($formData['alamat'])) $errors[] = 'Alamat harus diisi';
    if (empty($formData['no_hp'])) $errors[] = 'Nomor HP harus diisi';
    
    // Jika tidak ada error, proses ke database
    if (empty($errors)) {
        // Cek email atau NIK sudah terdaftar
        $stmt = $koneksi->prepare("SELECT MAX(LOWER(email) = LOWER(?)) AS email_used, MAX(nik = ?) AS nik_used FROM tb_users WHERE LOWER(email) = LOWER(?) OR nik = ?");
        $stmt->bind_param("ssss", $formData['email'], $formData['nik'], $formData['email'], $formData['nik']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $existing = $result ? $result->fetch_assoc() : null;
        $isEmailUsed = !empty($existing['email_used']);
        $isNikUsed = !empty($existing['nik_used']);

        if ($isEmailUsed || $isNikUsed) {

            if ($isEmailUsed && $isNikUsed) {
                $duplicateAlertMessage = 'Email dan NIK sudah terpakai. Silakan gunakan data lain.';
                $errors[] = 'Email dan NIK sudah terpakai.';
            } elseif ($isEmailUsed) {
                $duplicateAlertMessage = 'Email sudah terpakai. Silakan gunakan email lain.';
                $errors[] = 'Email sudah terpakai.';
            } elseif ($isNikUsed) {
                $duplicateAlertMessage = 'NIK sudah terpakai. Silakan gunakan NIK lain.';
                $errors[] = 'NIK sudah terpakai.';
            } else {
                $duplicateAlertMessage = 'Email atau NIK sudah terpakai.';
                $errors[] = 'Email atau NIK sudah terpakai.';
            }
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'user';
            
            // Insert data
            $insert_stmt = $koneksi->prepare("INSERT INTO tb_users (nama, nik, email, password, role, alamat, no_hp, created_at) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $insert_stmt->bind_param("sssssss", 
                $formData['nama'], 
                $formData['nik'], 
                $formData['email'], 
                $hashed_password, 
                $role, 
                $formData['alamat'], 
                $formData['no_hp']
            );
            
            if ($insert_stmt->execute()) {
                echo "<script>
                        alert('Registrasi berhasil! Silakan login.');
                        window.location.href = 'login.php';
                      </script>";
                exit;
            } else {
                $errors[] = 'Terjadi kesalahan saat menyimpan data.';
            }
            $insert_stmt->close();
        }
        $stmt->close();
    }
}

// Tutup koneksi di akhir script
if (isset($koneksi)) {
    $koneksi->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - SI PRIMA</title>
    <link rel="stylesheet" href="assets/css/register.css?v=20261118-2">
    <link rel="stylesheet" href="assets/css/password_rules.css?v=20260922-1">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261118-14">
    <script src="assets/js/theme.js?v=20261118-1"></script>
    <style>
        .error-message {
            display: none;
            color: #dc3545;
            font-size: 14px;
            margin-top: 5px;
            margin-bottom: 10px;
        }

        .submit-security-error {
            margin-bottom: 15px;
            padding: 10px;
            border: 1px solid #f99;
            border-radius: 5px;
            background: #fee;
            color: #9b1c1c;
        }

        .submit-security-error[hidden] {
            display: none;
        }

        .btn-register:disabled {
            cursor: wait;
            opacity: 0.7;
        }
        
        .nik-status {
            font-size: 14px;
            margin-top: 5px;
            margin-bottom: 10px;
            padding: 5px 10px;
            border-radius: 4px;
        }
        
        .nik-valid {
            color: #198754;
            background-color: #d1e7dd;
            border: 1px solid #badbcc;
        }
        
        .nik-invalid {
            color: #842029;
            background-color: #f8d7da;
            border: 1px solid #f5c2c7;
        }
        
        .nik-empty {
            color: #6c757d;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        
        input.invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        input.valid {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
        }
        
        .input-hint {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
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

<div class="register-container">
    <div class="register-box">

        <h2>Daftar Akun Baru</h2>
        <p class="subtitle">Isi data diri Anda dengan benar</p>

        <?php if (!empty($errors)): ?>
            <div style="background: #fee; border: 1px solid #f99; padding: 10px; margin-bottom: 15px; border-radius: 5px;">
                <strong>Perhatian:</strong>
                <ul style="margin: 5px 0; padding-left: 20px;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div id="submitSecurityError" class="submit-security-error" role="alert" hidden></div>

        <form method="POST" id="registerForm">
            <?php echo csrf_input(); ?>
            <label>Nama Lengkap</label>
            <input type="text" name="nama" value="<?= htmlspecialchars($formData['nama']) ?>" required>

            <label>NIK (Nomor Induk Kependudukan) <span style="color: red;">*</span></label>
            <input type="text" id="nik" name="nik" value="<?= htmlspecialchars($formData['nik']) ?>" 
                   required placeholder="Masukkan 16 digit angka" 
                   maxlength="16" oninput="validateNikRealtime()">
            <div id="nikError" class="error-message">NIK harus 16 digit angka</div>
            <div id="nikStatus" class="nik-status"></div>

            <label>Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($formData['email']) ?>" required>
            <div id="emailError" class="error-message">Format email tidak valid</div>

            <span data-password-rules-config="<?= htmlspecialchars(siprima_password_rules_json(), ENT_QUOTES, 'UTF-8') ?>" hidden></span>
            <label>Password</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password" required data-password-rules>
                <span id="togglePassword" class="toggle">👁️</span>
            </div>
            <div id="passwordError" class="error-message"><?= htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8') ?></div>

            <label>Konfirmasi Password</label>
            <div class="password-wrapper">
                <input type="password" id="confirm_password" name="confirm_password" required data-password-match="password">
                <span id="toggleConfirmPassword" class="toggle">👁️</span>
            </div>
            <div id="confirmError" class="error-message">Konfirmasi password tidak cocok</div>

            <label>Alamat</label>
            <textarea name="alamat" required><?= htmlspecialchars($formData['alamat']) ?></textarea>

            <label>No HP</label>
            <input type="text" name="no_hp" value="<?= htmlspecialchars($formData['no_hp']) ?>" required>

            <button type="submit" class="btn-register" id="registerButton">Daftar</button>
        </form>

        <p class="footer-text">
            Sudah punya akun? <a href="login.php">Login</a>
        </p>
        <p class="footer-text" style="margin-top: 5px; font-size: 13px;">
            <a href="https://api.whatsapp.com/send?phone=6281243425557" target="_blank" rel="noopener noreferrer">Ada Kendala Daftar? Hubungi Admin</a>
        </p>

    </div>
</div>

<?php if (!empty($duplicateAlertMessage)): ?>
<script>
window.addEventListener('DOMContentLoaded', function() {
    alert(<?= json_encode($duplicateAlertMessage) ?>);
});
</script>
<?php endif; ?>

<script>
// Fungsi validasi realtime untuk NIK
function validateNikRealtime() {
    const nikInput = document.getElementById('nik');
    const nikError = document.getElementById('nikError');
    const nikStatus = document.getElementById('nikStatus');
    const nikValue = nikInput.value.trim();
    
    // Reset tampilan
    nikInput.classList.remove('valid', 'invalid');
    nikError.style.display = 'none';
    nikStatus.className = 'nik-status';
    nikStatus.innerHTML = '';
    
    // Validasi kosong
    if (nikValue === '') {
        nikInput.classList.add('invalid');
        nikStatus.className = 'nik-status nik-empty';
        nikStatus.innerHTML = '⚠️ NIK wajib diisi';
        return false;
    }
    
    // Hanya angka yang diperbolehkan
    if (!/^\d+$/.test(nikValue)) {
        nikInput.classList.add('invalid');
        nikError.style.display = 'block';
        nikStatus.className = 'nik-status nik-invalid';
        nikStatus.innerHTML = '❌ NIK hanya boleh berisi angka';
        return false;
    }
    
    // Validasi panjang 16 digit
    if (nikValue.length !== 16) {
        nikInput.classList.add('invalid');
        nikStatus.className = 'nik-status nik-invalid';
        nikStatus.innerHTML = `❌ ${nikValue.length} digit - Harus tepat 16 digit`;
        return false;
    }
    
    // Validasi berhasil
    if (/^[0-9]{16}$/.test(nikValue)) {
        nikInput.classList.remove('invalid');
        nikInput.classList.add('valid');
        nikStatus.className = 'nik-status nik-valid';
        nikStatus.innerHTML = '✅ NIK valid (16 digit)';
        return true;
    }
    
    return false;
}

// Validasi saat form submit
function validateForm() {
    let isValid = true;
    
    // Reset error messages
    document.querySelectorAll('.error-message').forEach(el => {
        el.style.display = 'none';
    });
    
    // Validasi NIK
    const nik = document.getElementById('nik').value;
    if (!validateNikRealtime()) {
        document.getElementById('nikError').style.display = 'block';
        isValid = false;
    }
    
    // Validasi Email
    const email = document.getElementById('email').value;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        document.getElementById('emailError').style.display = 'block';
        isValid = false;
    }
    
    // Validasi Password
    const password = document.getElementById('password').value;
    if (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password) || !/[^a-zA-Z0-9\s]/.test(password)) {
        document.getElementById('passwordError').style.display = 'block';
        isValid = false;
    }
    
    // Validasi Konfirmasi Password
    const confirmPassword = document.getElementById('confirm_password').value;
    if (password !== confirmPassword) {
        document.getElementById('confirmError').style.display = 'block';
        isValid = false;
    }
    
    return isValid;
}

// Meminta token dari session aktif. Cache-buster ditambahkan untuk mencegah
// browser/proxy menggunakan respons token yang lama.
async function requestFreshCsrfToken() {
    const csrfUrl = new URL(window.location.href);
    csrfUrl.search = '';
    csrfUrl.hash = '';
    csrfUrl.searchParams.set('csrf_refresh', '1');
    csrfUrl.searchParams.set('_', Date.now().toString() + '-' + Math.random().toString(16).slice(2));

    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timeoutId = controller
        ? window.setTimeout(function() { controller.abort(); }, 12000)
        : null;
    const fetchOptions = {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store'
    };
    if (controller) {
        fetchOptions.signal = controller.signal;
    }

    const clearRequestTimeout = function() {
        if (timeoutId !== null) {
            window.clearTimeout(timeoutId);
        }
    };

    let response;
    try {
        response = await fetch(csrfUrl.toString(), fetchOptions);
    } catch (error) {
        clearRequestTimeout();
        if (error && error.name === 'AbortError') {
            throw new Error('Pemeriksaan sesi terlalu lama. Periksa koneksi internet lalu coba lagi.');
        }
        throw new Error('Token keamanan gagal diperbarui. Periksa koneksi internet lalu coba lagi.');
    }

    if (!response.ok) {
        clearRequestTimeout();
        throw new Error('Token keamanan tidak dapat dimuat. Muat ulang halaman lalu coba lagi.');
    }

    let data;
    try {
        data = await response.json();
    } catch (error) {
        if (error && error.name === 'AbortError') {
            throw new Error('Pemeriksaan sesi terlalu lama. Periksa koneksi internet lalu coba lagi.');
        }
        throw new Error('Respons token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
    } finally {
        clearRequestTimeout();
    }
    if (!data || data.success !== true || typeof data.csrf_token !== 'string' || data.csrf_token === '') {
        throw new Error('Respons token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
    }

    return data.csrf_token;
}

function updateCsrfInput(form, token) {
    let csrfInput = form.querySelector('input[name="csrf_token"]');
    if (!csrfInput) {
        csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        form.appendChild(csrfInput);
    }
    csrfInput.value = token;
}

// Event listener untuk validasi realtime saat input berubah
document.addEventListener('DOMContentLoaded', function() {
    const nikInput = document.getElementById('nik');
    const registerForm = document.getElementById('registerForm');
    const registerButton = document.getElementById('registerButton');
    const submitSecurityError = document.getElementById('submitSecurityError');
    let registerSubmitting = false;
    
    // Validasi saat user mengetik
    if (nikInput) {
        nikInput.addEventListener('input', validateNikRealtime);
        nikInput.addEventListener('blur', validateNikRealtime);
        
        // Validasi saat halaman dimuat jika sudah ada nilai
        if (nikInput.value) {
            setTimeout(validateNikRealtime, 100);
        }
    }
    
    // Toggle password visibility
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    const toggleConfirm = document.getElementById('toggleConfirmPassword');
    const confirmInput = document.getElementById('confirm_password');
    
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️' : '🙈';
        });
    }
    
    if (toggleConfirm && confirmInput) {
        toggleConfirm.addEventListener('click', function() {
            const type = confirmInput.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmInput.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️' : '🙈';
        });
    }
    
    // Mencegah input karakter non-angka pada NIK
    if (nikInput) {
        nikInput.addEventListener('keypress', function(e) {
            const char = String.fromCharCode(e.which);
            if (!/[0-9]/.test(char)) {
                e.preventDefault();
                return false;
            }
        });
        
        // Mencegah paste karakter non-angka
        nikInput.addEventListener('paste', function(e) {
            const pastedData = e.clipboardData.getData('text');
            if (!/^\d+$/.test(pastedData)) {
                e.preventDefault();
                alert('NIK hanya boleh berisi angka!');
                return false;
            }
        });
    }

    if (registerForm && registerButton) {
        const defaultButtonText = registerButton.textContent;
        const resetSubmitUi = function() {
            registerSubmitting = false;
            registerButton.disabled = false;
            registerButton.textContent = defaultButtonText;
        };

        // Firefox dan beberapa WebView dapat mempertahankan status disabled saat
        // halaman dikembalikan dari back-forward cache.
        resetSubmitUi();

        registerForm.addEventListener('submit', async function(event) {
            event.preventDefault();

            if (registerSubmitting || !validateForm()) {
                return;
            }

            if (submitSecurityError) {
                submitSecurityError.hidden = true;
                submitSecurityError.textContent = '';
            }

            registerSubmitting = true;
            registerButton.disabled = true;
            registerButton.textContent = 'Memeriksa sesi...';

            try {
                // Dua permintaan berurutan harus menghasilkan token yang sama.
                // Jika berbeda, cookie/session tidak tersimpan secara konsisten dan
                // POST sengaja tidak dilakukan agar tidak berakhir dengan 403.
                const freshToken = await requestFreshCsrfToken();
                const verifiedToken = await requestFreshCsrfToken();
                if (freshToken !== verifiedToken) {
                    throw new Error('Sesi browser tidak tersimpan. Izinkan cookie untuk situs ini atau gunakan browser biasa, lalu coba lagi.');
                }

                updateCsrfInput(registerForm, verifiedToken);

                // Pengguna masih dapat mengubah field selama pemeriksaan jaringan.
                // Validasi ulang tepat sebelum native submit agar data invalid tidak
                // lolos ketika submit() melewati event validasi browser.
                const customValid = validateForm();
                const nativeValid = registerForm.checkValidity();
                if (!customValid || !nativeValid) {
                    resetSubmitUi();
                    registerForm.reportValidity();
                    return;
                }

                registerButton.textContent = 'Memproses...';

                // Native submit melewati listener ini, tetapi token tetap divalidasi
                // oleh error_handler.php sebelum data registrasi diproses.
                HTMLFormElement.prototype.submit.call(registerForm);
            } catch (error) {
                resetSubmitUi();

                const message = error instanceof Error
                    ? error.message
                    : 'Sesi keamanan gagal diperbarui. Muat ulang halaman lalu coba lagi.';

                if (submitSecurityError) {
                    submitSecurityError.textContent = message;
                    submitSecurityError.hidden = false;
                    submitSecurityError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    alert(message);
                }
            }
        });

        // Tidak melakukan fetch pada pageshow agar tidak berlomba dengan refresh
        // saat submit. Token selalu diminta dan diverifikasi tepat sebelum POST.
        window.addEventListener('pageshow', function(event) {
            if (!event.persisted) {
                return;
            }

            resetSubmitUi();
            if (submitSecurityError) {
                submitSecurityError.hidden = true;
                submitSecurityError.textContent = '';
            }
        });
    }
});
</script>

<script src="assets/js/password_rules.js?v=20260922-1" defer></script>
</body>
</html>
