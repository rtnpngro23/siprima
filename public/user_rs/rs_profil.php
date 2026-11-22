<?php
require_once '../error_handler.php';
// Gunakan user_rs_only.php untuk validasi khusus RS
include '../../app/middleware/user_rs_only.php';


// Debug
error_log("=== rs_profil.php START ===");
error_log("id_user: " . $_SESSION['id_user']);
error_log("id_rs: " . ($_SESSION['id_rs'] ?? 'N/A'));
error_log("role: " . $_SESSION['role']);
error_log("nama_rs: " . ($_SESSION['nama_rs'] ?? 'N/A'));

// Koneksi database
include '../../app/config/koneksi.php';

$errors = [];
$success_message = '';

// Ambil data rumah sakit (untuk semua user RS)
$id_rs = $_SESSION['id_rs'] ?? 0;
$sql_rs = "SELECT * FROM tb_rumahsakit WHERE id_rs = ?";
$stmt_rs = $koneksi->prepare($sql_rs);
if ($stmt_rs) {
    $stmt_rs->bind_param("i", $id_rs);
    $stmt_rs->execute();
    $result_rs = $stmt_rs->get_result();
    $data_rs = $result_rs->fetch_assoc();
    $stmt_rs->close();
} else {
    $errors[] = "Error mengambil data Rumah Sakit: " . $koneksi->error;
}

// Ambil data user
$sql_user = "SELECT * FROM tb_users WHERE id = ?";
$stmt_user = $koneksi->prepare($sql_user);
if ($stmt_user) {
    $stmt_user->bind_param("i", $_SESSION['id_user']);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    $data_user = $result_user->fetch_assoc();
    $stmt_user->close();
} else {
    $errors[] = "Error mengambil data user: " . $koneksi->error;
}

// Proses update profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_contact') {
        // Update email dan no HP (hanya ini yang bisa diedit)
        $email = trim($_POST['email'] ?? '');
        $no_hp = trim($_POST['no_hp'] ?? '');
        
        // Validasi
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email tidak valid";
        }
        
        // Validasi format no HP (opsional)
        if (!empty($no_hp) && !preg_match('/^[0-9]{10,13}$/', $no_hp)) {
            $errors[] = "Format No. HP tidak valid (10-13 digit angka)";
        }
        
        // Cek email unik (kecuali milik user ini)
        if (empty($errors)) {
            $sql_check = "SELECT id FROM tb_users WHERE email = ? AND id != ?";
            $stmt_check = $koneksi->prepare($sql_check);
            if ($stmt_check) {
                $stmt_check->bind_param("si", $email, $_SESSION['id_user']);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                if ($result_check->num_rows > 0) {
                    $errors[] = "Email sudah digunakan oleh user lain";
                }
                $stmt_check->close();
            }
        }
        
        // Update database
        if (empty($errors)) {
            $sql_update = "UPDATE tb_users SET 
                email = ?,
                no_hp = ?
                WHERE id = ?";
            
            $stmt_update = $koneksi->prepare($sql_update);
            if ($stmt_update) {
                $stmt_update->bind_param(
                    "ssi",
                    $email,
                    $no_hp,
                    $_SESSION['id_user']
                );
                
                if ($stmt_update->execute()) {
                    $success_message = "Profil berhasil diperbarui";
                    // Update data user di session untuk email
                    $_SESSION['email'] = $email;
                    // Refresh data
                    $data_user['email'] = $email;
                    $data_user['no_hp'] = $no_hp;
                } else {
                    $errors[] = "Error update profil: " . $koneksi->error;
                }
                $stmt_update->close();
            } else {
                $errors[] = "Error prepare update: " . $koneksi->error;
            }
        }
        
    } elseif ($action === 'change_password') {
        // Ganti password
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validasi
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $errors[] = "Semua field password harus diisi";
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = "Password baru dan konfirmasi tidak cocok";
        }
        
        if (($passwordError = siprima_password_error($new_password)) !== null) {
            $errors[] = $passwordError;
        }
        
        // Verifikasi password lama
        if (empty($errors)) {
            $sql_check = "SELECT password FROM tb_users WHERE id = ?";
            $stmt_check = $koneksi->prepare($sql_check);
            if ($stmt_check) {
                $stmt_check->bind_param("i", $_SESSION['id_user']);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                $user_data = $result_check->fetch_assoc();
                $stmt_check->close();
                
                if (!password_verify($current_password, $user_data['password'])) {
                    $errors[] = "Password saat ini salah";
                }
            }
        }
        
        // Update password
        if (empty($errors)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $sql_update = "UPDATE tb_users SET password = ? WHERE id = ?";
            $stmt_update = $koneksi->prepare($sql_update);
            if ($stmt_update) {
                $stmt_update->bind_param("si", $hashed_password, $_SESSION['id_user']);
                if ($stmt_update->execute()) {
                    $success_message = "Password berhasil diubah";
                } else {
                    $errors[] = "Error update password: " . $koneksi->error;
                }
                $stmt_update->close();
            }
        }
    }
}

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - <?= esc($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></title>
    <link rel="icon" href="../assets/st sehati.png" type="image/png">
    <link rel="stylesheet" href="css/rs_profil.css">
    
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-rs">

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">ST SEHATI - <?= esc($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></div>
        <nav>
            <ul>
                <li><a href="rs_dashboard_user.php">Dashboard</a></li>
                <li><a href="rs_ajukan_permohonan.php">Ajukan Permohonan</a></li>
                <li><a href="rs_status_permohonan.php">Status Permohonan</a></li>
                <li class="active">Profil Saya</li>
                <li><a href="rs_formulir.php">Formulir Kependudukan</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Profil Saya</div>
                <div class="subtitle"><?= esc($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama']) ?></span>
                <span class="role-badge"><?= strtoupper($_SESSION['role']) ?></span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Flash Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="toast toast-success">
                    <?= esc($success_message) ?>
                </div>
                <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <?php foreach ($errors as $error): ?>
                        <p><?= esc($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="profile-container">
                <!-- Card 1: Informasi Pribadi -->
                <div class="profile-card">
                    <h2>
                        <span class="icon">👤</span>
                        Informasi Pribadi
                    </h2>
                    <form method="POST" action="">
<?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="update_contact">
                        
                        <div class="form-group">
                            <label>NIK</label>
                            <input type="text" class="form-control readonly-field" 
                                   value="<?= esc($data_user['nik'] ?? '') ?>" 
                                   readonly disabled>
                            <div class="field-hint">NIK tidak dapat diubah (untuk integritas data)</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Nama Lengkap</label>
                            <input type="text" class="form-control readonly-field" 
                                   value="<?= esc($data_user['nama'] ?? '') ?>" 
                                   readonly disabled>
                            <div class="field-hint">Nama tidak dapat diubah (untuk integritas data)</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?= esc($data_user['email'] ?? '') ?>" 
                                   required>
                            <div class="field-hint">Email dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>No. HP</label>
                            <input type="text" name="no_hp" class="form-control" 
                                   value="<?= esc($data_user['no_hp'] ?? '') ?>"
                                   placeholder="Contoh: 081234567890">
                            <div class="field-hint">Format: 10-13 digit angka</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Role</label>
                            <input type="text" class="form-control readonly-field" 
                                   value="<?= strtoupper($_SESSION['role']) ?>" 
                                   readonly disabled>
                        </div>
                        
                        <div class="form-group">
                            <label>Rumah Sakit</label>
                            <input type="text" class="form-control readonly-field" 
                                   value="<?= esc($_SESSION['nama_rs'] ?? '') ?>" 
                                   readonly disabled>
                        </div>
                        
                        <button type="submit" class="btn btn-success">
                            <svg style="width:16px;height:16px;margin-right:8px;vertical-align:-2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                                <polyline points="17 21 17 13 7 13 7 21"></polyline>
                                <polyline points="7 3 7 8 15 8"></polyline>
                            </svg>
                            Simpan Perubahan Kontak
                        </button>
                    </form>
                </div>

                <!-- Card 2: Ganti Password -->
                <div class="profile-card">
                    <h2>
                        <span class="icon">🔒</span>
                        Ganti Password
                    </h2>
                    <form method="POST" action="">
<?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label>Password Saat Ini</label>
                            <input type="password" name="current_password" id="current_password" 
                                   class="form-control" required>
                            <div class="field-hint">Masukkan password saat ini</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Password Baru</label>
                            <input type="password" name="new_password" id="new_password" 
                                   class="form-control" required
                                   pattern=".{8,}"
                                   title="Minimal 8 karakter">
                            <div class="field-hint"><?= htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        
                        <div class="form-group">
                            <label>Konfirmasi Password Baru</label>
                            <input type="password" name="confirm_password" id="confirm_password" 
                                   class="form-control" required>
                            <div class="field-hint">Ulangi password baru</div>
                        </div>
                        
                        <div class="password-toggle">
                            <input type="checkbox" id="show_password">
                            <label for="show_password">Tampilkan Password</label>
                        </div>
                        
                        <button type="submit" class="btn btn-danger">
                            <svg style="width:16px;height:16px;margin-right:8px;vertical-align:-2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778"></path>
                                <path d="M21 2l-2 2"></path>
                                <line x1="2" y1="2" x2="22" y2="22"></line>
                            </svg>
                            Ganti Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <h3>Informasi Penting</h3>
                <p>• <strong>NIK dan Nama Lengkap tidak dapat diubah</strong> untuk menjaga integritas data</p>
                <p>• Anda hanya dapat mengubah <strong>Email dan No. HP</strong></p>
                <p><?= htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8') ?></p>
                <p>• Pastikan email yang diisi valid untuk menerima notifikasi</p>
                <p>• No. HP digunakan untuk komunikasi penting terkait permohonan</p>
                <p>• Data rumah sakit terkait ditetapkan oleh administrator</p>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
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
// Toggle Sidebar Menu on Mobile
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && 
                    !menuToggle.contains(event.target) && 
                    sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            }
        });
    }
    
    // Logout Modal
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');
    
    function showLogoutModal() {
        logoutModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function hideLogoutModal() {
        logoutModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    if (logoutBtn) {
        logoutBtn.addEventListener('click', showLogoutModal);
    }
    
    if (logoutBtn2) {
        logoutBtn2.addEventListener('click', showLogoutModal);
    }
    
    if (confirmLogout) {
        confirmLogout.addEventListener('click', function() {
            window.location.href = '../logout_pasutri_ahlak.php';
        });
    }
    
    if (cancelLogout) {
        cancelLogout.addEventListener('click', hideLogoutModal);
    }
    
    // Close modal when clicking outside
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) {
                hideLogoutModal();
            }
        });
    }
    
    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
    
    // Toggle Password Visibility
    const showPasswordCheckbox = document.getElementById('show_password');
    if (showPasswordCheckbox) {
        showPasswordCheckbox.addEventListener('change', function() {
            const currentPassword = document.getElementById('current_password');
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            if (this.checked) {
                currentPassword.type = 'text';
                newPassword.type = 'text';
                confirmPassword.type = 'text';
            } else {
                currentPassword.type = 'password';
                newPassword.type = 'password';
                confirmPassword.type = 'password';
            }
        });
    }
    
    // Form validation for password
    const passwordForm = document.querySelector('form[action*="change_password"]');
    if (passwordForm) {
        passwordForm.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            // Check if passwords match
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Password baru dan konfirmasi tidak cocok!');
                return false;
            }
            
            // Check minimum length
            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password baru minimal 8 karakter!');
                return false;
            }
            
            // Optional: Check password strength
            const hasUpperCase = /[A-Z]/.test(newPassword);
            const hasLowerCase = /[a-z]/.test(newPassword);
            const hasNumbers = /\d/.test(newPassword);
            
            if (!hasUpperCase || !hasLowerCase || !hasNumbers) {
                if (!confirm('Password sebaiknya mengandung huruf besar, huruf kecil, dan angka.\nLanjutkan dengan password ini?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }
    
    // Form validation for contact info
    const contactForm = document.querySelector('form[action*="update_contact"]');
    if (contactForm) {
        contactForm.addEventListener('submit', function(e) {
            const email = contactForm.querySelector('input[name="email"]').value;
            const noHp = contactForm.querySelector('input[name="no_hp"]').value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const phoneRegex = /^[0-9]{10,13}$/;
            
            // Validate email
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Format email tidak valid! Contoh: user@example.com');
                return false;
            }
            
            // Validate phone number (if provided)
            if (noHp && !phoneRegex.test(noHp)) {
                e.preventDefault();
                alert('Format No. HP tidak valid! Gunakan 10-13 digit angka.');
                return false;
            }
        });
    }
    
    // Real-time validation for phone number
    const phoneInput = document.querySelector('input[name="no_hp"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            // Remove non-numeric characters
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }
    
    // Prevent form submission for readonly fields
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Re-enable disabled fields before submission
            const disabledFields = form.querySelectorAll('input[disabled], textarea[disabled]');
            disabledFields.forEach(field => {
                field.disabled = false;
            });
        });
    });
    
    // Auto-hide toast messages after 5 seconds
    setTimeout(() => {
        const toast = document.querySelector('.toast');
        if (toast) {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }
    }, 5000);
});
</script>

</body>
</html>
