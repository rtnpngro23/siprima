<?php
require_once '../error_handler.php';
// Gunakan user_kua_only.php untuk validasi khusus KUA
include '../../app/middleware/user_kua_only.php'; // Validasi user KUA

// Debug
error_log("=== kua_profil.php START ===");
error_log("id_user: " . $_SESSION['id_user']);
error_log("id_kua: " . $_SESSION['id_kua']);
error_log("role: " . $_SESSION['role']);

// Koneksi database
include '../../app/config/koneksi.php';

$errors = [];
$success_message = '';
$csrf_token_value = function_exists('csrf_token') ? csrf_token() : '';

// Ambil data KUA (untuk operator)
if ($_SESSION['role'] === 'operator') {
    $id_kua = $_SESSION['id_kua'];
    $sql_kua = "SELECT * FROM tb_kua WHERE id = ?";
    $stmt_kua = $koneksi->prepare($sql_kua);
    if ($stmt_kua) {
        $stmt_kua->bind_param("i", $id_kua);
        $stmt_kua->execute();
        $result_kua = $stmt_kua->get_result();
        $data_kua = $result_kua->fetch_assoc();
        $stmt_kua->close();
    } else {
        $errors[] = "Error mengambil data KUA: " . $koneksi->error;
    }
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
        // Update email dan no HP
        $email = trim($_POST['email'] ?? '');
        $no_hp = trim($_POST['no_hp'] ?? '');
        
        // Validasi
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email tidak valid";
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
        
    } elseif ($action === 'update_kua_contact') {
        // Update kontak KUA (hanya operator)
        if ($_SESSION['role'] === 'operator') {
            $email_kua = trim($_POST['email_kua'] ?? '');
            $no_telp_kua = trim($_POST['no_telp_kua'] ?? '');
            
            // Validasi
            if (empty($email_kua) || !filter_var($email_kua, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Email KUA tidak valid";
            }
            
            if (empty($errors)) {
                $sql_update = "UPDATE tb_kua SET 
                    email = ?,
                    no_telp = ?
                    WHERE id = ?";
                
                $stmt_update = $koneksi->prepare($sql_update);
                if ($stmt_update) {
                    $stmt_update->bind_param(
                        "ssi",
                        $email_kua,
                        $no_telp_kua,
                        $id_kua
                    );
                    
                    if ($stmt_update->execute()) {
                        $success_message = "Kontak KUA berhasil diperbarui";
                        // Refresh data
                        $data_kua['email'] = $email_kua;
                        $data_kua['no_telp'] = $no_telp_kua;
                    } else {
                        $errors[] = "Error update kontak KUA: " . $koneksi->error;
                    }
                    $stmt_update->close();
                }
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
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
    <title>Profil <?= htmlspecialchars($_SESSION['role'] === 'operator' ? 'KUA' : 'Saya') ?> - <?= htmlspecialchars($_SESSION['nama_kua'] ?? 'KUA') ?></title>
    <link rel="stylesheet" href="css/kua_profil.css">
    <link rel="icon" href="../assets/PASUTRI.png" type="image/png">
    <style>
        
    </style>
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-kua">

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan PASUTRI</div>
        <nav>
            <ul>
                <li><a href="kua_dashboard_user.php">Dashboard</a></li>
                <li><a href="kua_ajukan_permohonan.php">Ajukan Permohonan</a></li>
                <li><a href="kua_status_permohonan.php">Status Permohonan</a></li>
                <li><a href="kua_formulir.php">Formulir Kependudukan</a></li>        
                <li class="active"><a href="kua_profil.php">Profil Saya</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Profil <?= $_SESSION['role'] === 'operator' ? 'KUA' : 'Saya' ?></div>
            </div>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['nama']) ?></span>
                <span class="role-badge"><?= strtoupper($_SESSION['role']) ?></span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout-btn" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Flash Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="toast toast-success">
                    <?= htmlspecialchars($success_message) ?>
                </div>
                <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="profile-container">
                <!-- Card 1: Informasi Pribadi (Semua Role) -->
                <div class="profile-card">
                    <h2>
                        <span class="icon">👤</span>
                        Informasi Pribadi
                    </h2>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update_contact">
                        
                        <div class="form-group">
                            <label>NIK</label>
                            <input type="text" class="form-control" 
                                   value="<?= htmlspecialchars($data_user['nik'] ?? '') ?>" 
                                   readonly disabled>
                            <div class="field-hint">NIK tidak dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Nama Lengkap</label>
                            <input type="text" class="form-control" 
                                   value="<?= htmlspecialchars($data_user['nama'] ?? '') ?>" 
                                   readonly disabled>
                            <div class="field-hint">Nama tidak dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?= htmlspecialchars($data_user['email'] ?? '') ?>" 
                                   required>
                            <div class="field-hint">Email dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>No. HP</label>
                            <input type="text" name="no_hp" class="form-control" 
                                   value="<?= htmlspecialchars($data_user['no_hp'] ?? '') ?>">
                            <div class="field-hint">Nomor HP dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Role</label>
                            <input type="text" class="form-control" 
                                   value="<?= strtoupper($_SESSION['role']) ?>" 
                                   readonly disabled>
                        </div>
                        
                        <?php if ($_SESSION['role'] !== 'operator'): ?>
                        <div class="form-group">
                            <label>KUA</label>
                            <input type="text" class="form-control" 
                                   value="<?= htmlspecialchars($_SESSION['nama_kua'] ?? '') ?>" 
                                   readonly disabled>
                        </div>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn btn-success">Simpan Perubahan Kontak</button>
                    </form>
                </div>

                <?php if ($_SESSION['role'] === 'operator'): ?>
                <!-- Card 2: Kontak KUA (Hanya Operator) -->
                <div class="profile-card">
                    <h2>
                        <span class="icon">🏛️</span>
                        Kontak KUA
                    </h2>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update_kua_contact">
                        
                        <div class="form-group">
                            <label>Nama KUA</label>
                            <input type="text" class="form-control" 
                                   value="<?= htmlspecialchars($data_kua['nama_kua'] ?? '') ?>" 
                                   readonly disabled>
                            <div class="field-hint">Nama KUA tidak dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Alamat KUA</label>
                            <textarea class="form-control" rows="2" readonly disabled><?= htmlspecialchars($data_kua['alamat'] ?? '') ?></textarea>
                            <div class="field-hint">Alamat KUA tidak dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Email KUA</label>
                            <input type="email" name="email_kua" class="form-control" 
                                   value="<?= htmlspecialchars($data_kua['email'] ?? '') ?>" 
                                   required>
                            <div class="field-hint">Email KUA dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>No. Telepon KUA</label>
                            <input type="text" name="no_telp_kua" class="form-control" 
                                   value="<?= htmlspecialchars($data_kua['no_telp'] ?? '') ?>">
                            <div class="field-hint">Telepon KUA dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Kepala KUA</label>
                            <input type="text" class="form-control" 
                                   value="<?= htmlspecialchars($data_kua['kepala_kua'] ?? '') ?>" 
                                   readonly disabled>
                            <div class="field-hint">Nama Kepala KUA tidak dapat diubah</div>
                        </div>
                        
                        <div class="form-group">
                            <label>NIP Kepala KUA</label>
                            <input type="text" class="form-control" 
                                   value="<?= htmlspecialchars($data_kua['nip_kepala'] ?? '') ?>" 
                                   readonly disabled>
                            <div class="field-hint">NIP Kepala KUA tidak dapat diubah</div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Simpan Kontak KUA</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Card 3: Ganti Password -->
                <div class="profile-card">
                    <h2>
                        <span class="icon">🔒</span>
                        Ganti Password
                    </h2>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
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
                                   class="form-control" required>
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
                        
                        <button type="submit" class="btn btn-danger">Ganti Password</button>
                    </form>
                </div>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <h3>Informasi Penting</h3>
                <p>• <strong>NIK dan Nama Lengkap tidak dapat diubah</strong> untuk menjaga integritas data</p>
                <p>• Anda hanya dapat mengubah <strong>Email, No. HP, dan Password</strong></p>
                <p><?= htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8') ?></p>
                <p>• Pastikan email yang diisi valid untuk menerima notifikasi</p>
                <?php if ($_SESSION['role'] === 'operator'): ?>
                <p>• Sebagai operator, Anda juga dapat mengubah kontak KUA (email dan telepon)</p>
                <?php endif; ?>
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
    const csrfToken = (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '').trim();

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
    const passwordForm = document.querySelector('input[name="action"][value="change_password"]')?.closest('form');
    if (passwordForm) {
        passwordForm.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Password baru dan konfirmasi tidak cocok!');
                return false;
            }
            
            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password baru minimal 8 karakter!');
                return false;
            }
        });
    }
    
    // Form validation for email
    const contactForm = document.querySelector('input[name="action"][value="update_contact"]')?.closest('form');
    if (contactForm) {
        contactForm.addEventListener('submit', function(e) {
            const email = contactForm.querySelector('input[name="email"]').value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Format email tidak valid!');
                return false;
            }
        });
    }
    
    // Defensive fallback: pastikan csrf_token selalu ikut saat submit.
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            let csrfInput = form.querySelector('input[name="csrf_token"]');
            if (!csrfInput) {
                csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                form.appendChild(csrfInput);
            }
            if ((!csrfInput.value || !csrfInput.value.trim()) && csrfToken) {
                csrfInput.value = csrfToken;
            }

            // Disable all readonly fields before submission
            const readonlyFields = form.querySelectorAll('input[readonly], textarea[readonly]');
            readonlyFields.forEach(field => {
                field.disabled = false;
            });
        });
    });
});
</script>

</body>
</html>
