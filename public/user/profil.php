<?php
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
include '../../app/config/koneksi.php';

$user_id = $_SESSION['id_user'];
$csrf_token_value = csrf_token();

// Ambil info user
$stmt = $koneksi->prepare("SELECT id, nama, nik, email, no_hp, role, alamat, COALESCE(wa_consent, 0) AS wa_consent FROM tb_users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$currentWaConsent = (int)($user['wa_consent'] ?? 0) === 1 ? 1 : 0;

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
                header('Location: profil.php');
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
                header('Location: profil.php');
                exit;
            } else {
                error_log('Gagal mengubah password pengguna ID ' . $user_id . ': ' . $u->error);
                $errors[] = 'Password gagal diubah. Silakan coba lagi.';
            }
        }
    } elseif ($action === 'update_wa_consent') {
        $rawConsent = $_POST['wa_consent'] ?? '';
        if (!in_array($rawConsent, ['0', '1'], true)) {
            $errors[] = 'Pilihan persetujuan notifikasi WhatsApp tidak valid.';
        } else {
            $newConsent = (int)$rawConsent;
            $u = $koneksi->prepare("UPDATE tb_users SET wa_consent = ? WHERE id = ?");
            if ($u) {
                $u->bind_param('ii', $newConsent, $user_id);
                if ($u->execute()) {
                    $_SESSION['success_message'] = $newConsent === 1
                        ? 'Persetujuan notifikasi WhatsApp berhasil diaktifkan.'
                        : 'Notifikasi WhatsApp berhasil dinonaktifkan.';
                    header('Location: profil.php');
                    exit;
                }
                error_log('Gagal memperbarui wa_consent pengguna ID ' . $user_id . ': ' . $u->error);
                $u->close();
            }
            $errors[] = 'Preferensi notifikasi WhatsApp gagal disimpan. Silakan coba lagi.';
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
  <title>Profil Saya - Pelayanan Layanan Identitas Online (LINE)</title>
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="css/profil.css">
  <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-line">

  <div class="layout">
    <!-- Sidebar SAMA dengan ajukan_permohonan.php -->
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <main class="main">
      <header class="header user-general-header">
        <?php
        require_once __DIR__ . '/user_header_brand.php';
        render_user_header_brand('Profil Saya');
        ?>
        <div class="user-info">
          Hai, <?= htmlspecialchars($_SESSION['nama']) ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
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
              <h2 class="form-title">Data Profil</h2>
              <p class="helper">Perbarui email, nomor HP, atau ubah password Anda.</p>
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
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_contact">
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama</label>
                <input type="text" value="<?= htmlspecialchars($user['nama'] ?? '') ?>" readonly class="input">
              </div>

              <div>
                <label class="field-label">NIK</label>
                <input type="text" value="<?= htmlspecialchars($user['nik'] ?? '') ?>" readonly class="input">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required class="input">
              </div>

              <div>
                <label class="field-label">No HP</label>
                <input type="text" name="no_hp" value="<?= htmlspecialchars($user['no_hp'] ?? '') ?>" required class="input">
              </div>
            </div>

            <button type="submit" class="btn-primary">Simpan Perubahan</button>
          </form>

          <hr class="my-6">

          <form method="POST" class="space-y-6" id="waConsentForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_wa_consent">
            <div class="password-section-header">
              <h3 class="password-section-title">Preferensi Notifikasi WhatsApp</h3>
              <p class="helper">
                Persetujuan ini bersifat <strong>sukarela</strong> dan dapat diubah kapan saja tanpa memengaruhi proses pelayanan Anda.
                Notifikasi hanya berisi pemberitahuan status permohonan dan arahan membuka SIPRIMA (tidak mengirim NIK, Nomor KK, atau berkas dokumen).
              </p>
            </div>

            <fieldset style="border:1px solid #d1d5db;border-radius:10px;padding:14px 16px;background:#f8fafc;">
              <legend style="font-weight:600;font-size:14px;padding:0 6px;color:#0f172a;">Status Persetujuan Saat Ini: <?= $currentWaConsent === 1 ? 'Setuju (Aktif)' : 'Tidak Setuju (Nonaktif)' ?></legend>
              <div style="display:flex;flex-direction:column;gap:10px;margin-top:6px;">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:14px;color:#1e293b;">
                  <input type="radio" name="wa_consent" value="1" <?= $currentWaConsent === 1 ? 'checked' : '' ?> style="margin-top:3px;">
                  <span><strong>Setuju</strong> — Saya menyetujui menerima pemberitahuan status layanan KIA melalui WhatsApp resmi SIPRIMA.</span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:14px;color:#1e293b;">
                  <input type="radio" name="wa_consent" value="0" <?= $currentWaConsent === 0 ? 'checked' : '' ?> style="margin-top:3px;">
                  <span><strong>Tidak Setuju</strong> — Jangan kirimkan notifikasi WhatsApp ke nomor saya (pantau status langsung melalui akun SIPRIMA).</span>
                </label>
              </div>
            </fieldset>

            <button type="submit" class="btn-primary">Simpan Preferensi Notifikasi</button>
          </form>

          <hr class="my-6">

          <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
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

  <?php include __DIR__ . '/mobile_bottom_nav.php'; ?>

  <!-- Modal Logout - SAMA dengan ajukan_permohonan.php -->
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
  document.addEventListener('DOMContentLoaded', function() {
    // Logout Modal - SAMA dengan ajukan_permohonan.php
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
        window.location.href = '../logout.php';
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
      const currentPw = document.getElementById('current_password');
      const newPw = document.getElementById('new_password');
      const confirmPw = document.getElementById('confirm_password');
      const inputType = this.checked ? 'text' : 'password';
      currentPw.type = inputType;
      newPw.type = inputType;
      confirmPw.type = inputType;
    });

    // Defensive fallback: ensure csrf_token is always posted with profile forms.
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    document.querySelectorAll('form.space-y-6').forEach(function(form) {
      form.addEventListener('submit', function() {
        let csrfInput = form.querySelector('input[name="csrf_token"]');
        if (!csrfInput) {
          csrfInput = document.createElement('input');
          csrfInput.type = 'hidden';
          csrfInput.name = 'csrf_token';
          form.appendChild(csrfInput);
        }
        if (!csrfInput.value && csrfToken) {
          csrfInput.value = csrfToken;
        }
      });
    });
  });
  </script>
<?php include __DIR__ . '/ai_chat_widget.php'; ?>
</body>
</html>
