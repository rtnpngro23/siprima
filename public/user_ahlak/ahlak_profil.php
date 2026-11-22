<?php
require_once '../error_handler.php';
include '../../app/middleware/user_ahlak_only.php';
include '../../app/config/koneksi.php';

$user_id = $_SESSION['id_user'];

// Ambil info user
$stmt = $koneksi->prepare("SELECT id, nama, nik, email, no_hp, role, alamat FROM tb_users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_contact') {
        $email = trim($_POST['email'] ?? '');
        $no_hp = trim($_POST['no_hp'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid.';
        }

        // cek email unik (kecuali milik user ini)
        $q = $koneksi->prepare("SELECT id FROM tb_users WHERE email = ? AND id != ? LIMIT 1");
        $q->bind_param('si', $email, $user_id);
        $q->execute();
        $r = $q->get_result();
        if ($r && $r->num_rows > 0) {
            $errors[] = 'Email sudah digunakan oleh pengguna lain.';
        }

        if (empty($errors)) {
            $u = $koneksi->prepare("UPDATE tb_users SET email = ?, no_hp = ? WHERE id = ?");
            $u->bind_param('ssi', $email, $no_hp, $user_id);
            if ($u->execute()) {
                $_SESSION['success_message'] = 'Profil berhasil diperbarui.';
                header('Location: ahlak_profil.php');
                exit;
            } else {
                $errors[] = 'Database error: ' . $koneksi->error;
            }
        }

    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new) || empty($confirm)) $errors[] = 'Semua field password wajib diisi.';
        if ($new !== $confirm) $errors[] = 'Password baru dan konfirmasi tidak cocok.';
        if (($passwordError = siprima_password_error($new)) !== null) $errors[] = $passwordError;

        // ambil hash saat ini
        $q = $koneksi->prepare("SELECT password FROM tb_users WHERE id = ?");
        $q->bind_param('i', $user_id);
        $q->execute();
        $res = $q->get_result()->fetch_assoc();
        $hash = $res['password'] ?? '';

        if (!password_verify($current, $hash)) {
            $errors[] = 'Password saat ini salah.';
        }

        if (empty($errors)) {
            $newhash = password_hash($new, PASSWORD_DEFAULT);
            $u = $koneksi->prepare("UPDATE tb_users SET password = ? WHERE id = ?");
            $u->bind_param('si', $newhash, $user_id);
            if ($u->execute()) {
                $_SESSION['success_message'] = 'Password berhasil diubah.';
                header('Location: ahlak_profil.php');
                exit;
            } else {
                $errors[] = 'Database error: ' . $koneksi->error;
            }
        }
    }
}

// flash messages (from redirect)
$flash_success = null;
if (isset($_SESSION['success_message'])) {
    $flash_success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Profil Saya - AHLAK Puskesmas</title>
  <link rel="icon" type="image/png" href="../assets/ahlak.png">
  <link rel="stylesheet" href="css/ahlak_profil.css?v=20261118-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
</head>
<body class="tema-form tema-ahlak">

  <div class="layout">
    <!-- Sidebar Khusus AHLAK -->
    <aside class="sidebar">
      <div class="logo">Pelayanan Kependudukan AHLAK</div>
      <nav>
        <ul>
          <li><a href="ahlak_dashboard_user.php">Dashboard</a></li>
          <li><a href="ahlak_ajukan_permohonan.php">Ajukan Permohonan</a></li>
          <li><a href="ahlak_status_permohonan.php">Status Permohonan</a></li>          
          <li><a href="ahlak_formulir.php">Formulir Kesehatan</a></li>
          <li class="active">Profil</li>
          <li id="logoutBtn">Logout</li>
        </ul>
      </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header">
        <div class="left-header">
          <div class="title">Profil Saya - Puskesmas</div>
          <div style="font-size: 14px; color: #666; margin-top: 5px;">
           <?= htmlspecialchars($_SESSION['nama_puskes'] ?? '') ?>
          </div>
        </div>
        <div class="user-info">
          <span><?= htmlspecialchars($_SESSION['nama']) ?></span>
          <span class="role-badge">USER AHLAK</span>
          <?php include __DIR__ . '/../user/notification_widget.php'; ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <!-- Puskesmas Info Banner -->
        <div class="puskesmas-banner">
          <h3>🏥 <?= htmlspecialchars($_SESSION['nama_puskes'] ?? '') ?></h3>
          <p><strong>Kecamatan:</strong> <?= htmlspecialchars($_SESSION['nama_kecamatan'] ?? '') ?></p>
          <p><strong>Alamat Puskesmas:</strong> <?= htmlspecialchars($_SESSION['alamat_puskes'] ?? 'Alamat belum diisi') ?></p>
        </div>

        <!-- Flash Toast - SAMA dengan ajukan_permohonan.php -->
        <?php if (!empty($flash_success)): ?>
          <div class="toast toast-success">
            <?= htmlspecialchars($flash_success) ?>
          </div>
          <script>window._userUtils?.removeToasts(5000);</script>
        <?php endif; ?>

        <div class="form-card">
          <div class="form-header">
            <div class="icon-container">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Data Profil AHLAK</h2>
              <p class="helper">Perbarui email, nomor HP, atau ubah password Anda. <strong>Nama dan NIK terkunci</strong> untuk keamanan.</p>
            </div>
          </div>

          <?php if (!empty($errors)): ?>
            <div class="error-box">
              <?php foreach ($errors as $err): ?>
                <p><?= htmlspecialchars($err) ?></p>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="POST" class="space-y-6">
<?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="update_contact">
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama <small>(Terkunci)</small></label>
                <div class="locked-field">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="locked-field-icon">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                  <?= htmlspecialchars($user['nama'] ?? '') ?>
                </div>
              </div>

              <div>
                <label class="field-label">NIK <small>(Terkunci)</small></label>
                <div class="locked-field">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="locked-field-icon">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                  <?= htmlspecialchars($user['nik'] ?? '') ?>
                </div>
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Email <small>(Dapat Diubah)</small></label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required class="input" placeholder="Masukkan email aktif">
              </div>

              <div>
                <label class="field-label">No HP <small>(Dapat Diubah)</small></label>
                <input type="text" name="no_hp" value="<?= htmlspecialchars($user['no_hp'] ?? '') ?>" required class="input" placeholder="Contoh: 081234567890">
              </div>
            </div>

            <div>
              <label class="field-label">Alamat</label>
              <div class="locked-field">
                <?= htmlspecialchars($user['alamat'] ?? 'Alamat belum diisi') ?>
              </div>
              <p class="helper">Alamat diambil dari data registrasi Puskesmas. Hubungi admin untuk perubahan.</p>
            </div>

            <button type="submit" class="btn-primary">Simpan Perubahan Kontak</button>
          </form>

          <hr class="my-6">

          <form method="POST" class="space-y-6">
<?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="change_password">
            <h3 class="form-title" style="font-size: 18px; margin-bottom: 15px;">Ubah Password</h3>
            
            <div>
              <label class="field-label">Password Saat Ini</label>
              <input type="password" name="current_password" class="input" required placeholder="Masukkan password saat ini">
            </div>
            
            <div class="form-grid-2">
              <div>
                <label class="field-label">Password Baru</label>
                <input type="password" name="new_password" id="new_password" class="input" required minlength="8" maxlength="72" placeholder="Minimal 8 karakter">
                <small><?= htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8') ?></small>
              </div>
              <div>
                <label class="field-label">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" id="confirm_password" class="input" required placeholder="Ulangi password baru">
              </div>
            </div>

            <div class="flex items-center gap-3">
              <input type="checkbox" id="show_pw">
              <label for="show_pw" class="helper">Tampilkan password</label>
            </div>

            <button type="submit" class="btn-primary">Ganti Password</button>
          </form>
        </div>
      </section>
    </main>
  </div>

  <!-- Modal Logout - Khusus AHLAK -->
  <div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
      <h3>Konfirmasi Logout</h3>
      <p>Apakah Anda yakin ingin logout dari sistem AHLAK?</p>
      <div class="modal-actions">
        <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
        <button id="cancelLogout" class="btn-no">Batal</button>
      </div>
    </div>
  </div>

  <script>
  // Toggle Sidebar Menu on Mobile - SAMA dengan ajukan_permohonan.php
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
    
    // Logout Modal - Khusus AHLAK
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
        // Logout khusus AHLAK
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
    
    // Show/Hide Password
    document.getElementById('show_pw')?.addEventListener('change', function(){
      const newPw = document.getElementById('new_password');
      const confirmPw = document.getElementById('confirm_password');
      if (this.checked) {
        newPw.type = 'text';
        confirmPw.type = 'text';
      } else {
        newPw.type = 'password';
        confirmPw.type = 'password';
      }
    });
    
    // Auto-hide toast after 5 seconds
    setTimeout(() => {
      const toast = document.querySelector('.toast');
      if (toast) {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s ease';
        setTimeout(() => {
          if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
          }
        }, 500);
      }
    }, 5000);
  });
  </script>
</body>
</html>
