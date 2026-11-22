<?php
require_once '../error_handler.php';
include '../../app/middleware/petugas_desa_only.php';
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
        $alamat = trim($_POST['alamat'] ?? '');

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
            $u = $koneksi->prepare("UPDATE tb_users SET email = ?, no_hp = ?, alamat = ? WHERE id = ?");
            $u->bind_param('sssi', $email, $no_hp, $alamat, $user_id);
            if ($u->execute()) {
                $_SESSION['success_message'] = 'Profil berhasil diperbarui.';
                header('Location: petugas_desa_profil.php');
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
        if ($current !== '' && $new !== '' && hash_equals($current, $new)) {
            $errors[] = 'Password baru harus berbeda dari password saat ini.';
        }

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
                header('Location: petugas_desa_profil.php');
                exit;
            } else {
                error_log('Gagal mengubah password petugas desa ID ' . $user_id . ': ' . $u->error);
                $errors[] = 'Password gagal diubah. Silakan coba lagi.';
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

$csrf_token_value = function_exists('csrf_token') ? csrf_token() : '';
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
  <title>Profil Petugas Desa - Pelayanan Kependudukan</title>
  <!-- CSS eksternal -->
  <link rel="stylesheet" href="css/petugas_desa_profil.css">
  <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="petugas-desa-ui tema-form tema-add">

  <div class="layout">
    <!-- Sidebar -->
    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header petugas-desa-header">
        <?php
        require_once __DIR__ . '/petugas_desa_header_brand.php';
        render_petugas_desa_header_brand('Profil Saya', 'Adminduk Dari Desa (ADD)');
        ?>
        <div class="user-info">
          Hai, <?= htmlspecialchars($_SESSION['nama']) ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <!-- Flash Toast -->
        <?php if (!empty($flash_success)): ?>
          <div class="toast toast-success">
            <?= htmlspecialchars($flash_success) ?>
          </div>
          <script>setTimeout(() => document.querySelector('.toast')?.remove(), 5000);</script>
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
              <h2 class="form-title">Data Profil Petugas Desa</h2>
              <p class="helper">Perbarui informasi kontak dan keamanan akun Anda.</p>
            </div>
          </div>

          <?php if (!empty($errors)): ?>
            <div class="error-box">
              <?php foreach ($errors as $err): ?>
                <p><?= htmlspecialchars($err) ?></p>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="POST" class="space-y-6" id="contactForm">
<?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="update_contact">
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Lengkap</label>
                <input type="text" value="<?= htmlspecialchars($user['nama'] ?? '') ?>" readonly class="input">
                <p class="helper">Nama tidak dapat diubah</p>
              </div>

              <div>
                <label class="field-label">NIK</label>
                <input type="text" value="<?= htmlspecialchars($user['nik'] ?? '') ?>" readonly class="input">
                <p class="helper">NIK tidak dapat diubah</p>
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Email <span class="required">*</span></label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required class="input">
              </div>

              <div>
                <label class="field-label">No HP <span class="required">*</span></label>
                <input type="text" name="no_hp" value="<?= htmlspecialchars($user['no_hp'] ?? '') ?>" required class="input">
              </div>
            </div>

            <div>
              <label class="field-label">Alamat <span class="required">*</span></label>
              <textarea name="alamat" class="input" rows="3" required><?= htmlspecialchars($user['alamat'] ?? '') ?></textarea>
            </div>

            <div>
              <label class="field-label">Role</label>
              <input type="text" value="<?= htmlspecialchars($user['role'] ?? 'Petugas Desa') ?>" readonly class="input">
            </div>

            <button type="submit" class="btn-primary">Simpan Perubahan</button>
          </form>

          <hr class="my-6">

          <form method="POST" class="space-y-6" id="passwordForm">
<?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="change_password">
            <div class="password-section-header">
              <h3 class="password-section-title">Ganti Password</h3>
              <p class="helper"><?= htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8') ?> Password baru harus berbeda dari password saat ini.</p>
            </div>
            
            <div>
              <label class="field-label">Password Saat Ini</label>
              <input type="password" name="current_password" id="current_password" autocomplete="current-password" class="input" required>
            </div>
            
            <div class="form-grid-2">
              <div>
                <label class="field-label">Password Baru</label>
                <input type="password" name="new_password" id="new_password" autocomplete="new-password" minlength="8" class="input" required>
              </div>
              <div>
                <label class="field-label">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" id="confirm_password" autocomplete="new-password" minlength="8" class="input" required>
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

  <!-- JavaScript eksternal -->
  <script src="js/petugas_desa_profil.js"></script>

<?php include __DIR__ . '/ai_chat_widget.php'; ?>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>
