<?php
declare(strict_types=1);
require_once __DIR__.'/error_handler.php';
require_once __DIR__.'/../app/config/koneksi.php';
require_once __DIR__.'/../app/security/password_reset.php';
require_once __DIR__.'/../app/security/login_security.php';
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
$error = ''; $success = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $resetIdentifier = 'reset:' . hash('sha256', (string)($_POST['reset_token'] ?? ''));
        $status = login_throttle_begin_attempt($resetIdentifier);
        if ($status['is_locked']) throw new DomainException('Terlalu banyak percobaan. Silakan tunggu beberapa menit.');
        $password = (string)($_POST['new_password'] ?? '');
        if ($password !== (string)($_POST['confirm_password'] ?? '')) throw new DomainException('Konfirmasi password tidak cocok.');
        siprima_reset_consume($koneksi, (string)($_POST['reset_token'] ?? ''), $password);
        login_throttle_register_success($resetIdentifier);
        $success = true;
        if (!empty($_SESSION['id_user'])) siprima_auth_logout();
    } catch (DomainException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { $error = 'Reset belum dapat diproses. Hubungi administrator.'; error_log('SECURITY reset_failed request_id='.siprima_request_id()); }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset Password — SIPRIMA</title>
    <link rel="stylesheet" href="assets/css/password_reset.css">
    <link rel="stylesheet" href="assets/css/password_rules.css?v=20260922-1">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261118-4">
    <script src="assets/js/theme.js?v=20261118-1"></script>
    <script src="assets/js/security_password.js" defer></script>
    <script src="assets/js/password_rules.js?v=20260922-1" defer></script>
</head>
<body>
<main class="reset-page">
    <a class="brand" href="login.php" aria-label="SIPRIMA — halaman login"><span class="brand-mark" aria-hidden="true">S</span><span>SIPRIMA<small>KABUPATEN GORONTALO</small></span></a>
    <section class="reset-card" aria-labelledby="reset-title">
        <header class="card-header"><span class="eyebrow">KEAMANAN AKUN</span><span class="secure-badge">Pemulihan akses</span></header>
        <div class="card-content">
        <?php if ($success): ?>
            <div class="status-icon" aria-hidden="true">✓</div>
            <h1 id="reset-title">Password berhasil diubah</h1>
            <p class="intro" role="status">Akun Anda siap digunakan kembali. Silakan masuk dengan password baru Anda.</p>
            <a class="primary-button" href="login.php">Masuk ke akun</a>
        <?php else: ?>
            <h1 id="reset-title">Buat password baru</h1>
            <p class="intro">Lindungi akun Anda dengan password yang kuat dan belum pernah digunakan sebelumnya.</p>
            <?php if ($error !== ''): ?>
                <div class="alert" role="alert"><strong>Password belum diubah</strong><p><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></p></div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <?=csrf_input()?>
                <input type="hidden" name="reset_token" id="resetToken" value="">
                <span data-password-rules-config="<?=htmlspecialchars(siprima_password_rules_json(), ENT_QUOTES, 'UTF-8')?>" hidden></span>
                <div class="field">
                    <label for="newPassword">Password baru</label>
                    <div class="password-input"><input id="newPassword" type="password" name="new_password" data-password-rules required minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="passwordHelp"><button class="password-toggle" type="button" data-password-target="newPassword" aria-controls="newPassword" aria-label="Tampilkan password baru" aria-pressed="false" hidden>Lihat</button></div>
                </div>
                <aside class="password-help" id="passwordHelp"><strong>Gunakan kombinasi yang aman</strong><p><?=htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8')?></p></aside>
                <div class="field">
                    <label for="confirmPassword">Konfirmasi password</label>
                    <div class="password-input"><input id="confirmPassword" type="password" name="confirm_password" data-password-match="newPassword" required minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="confirmHelp"><button class="password-toggle" type="button" data-password-target="confirmPassword" aria-controls="confirmPassword" aria-label="Tampilkan konfirmasi password" aria-pressed="false" hidden>Lihat</button></div>
                    <p class="field-note" id="confirmHelp">Ketik ulang password baru Anda.</p>
                </div>
                <button class="primary-button" type="submit">Simpan password baru <span aria-hidden="true">→</span></button>
            </form>
            <p class="expiry-note">Tautan reset berlaku <strong>15 menit</strong> dan hanya dapat digunakan sekali. Jika tidak valid, buka tautan terbaru dari pemberitahuan Anda.</p>
            <a class="back-link" href="login.php"><span aria-hidden="true">←</span> Kembali ke halaman login</a>
        <?php endif; ?>
        </div>
    </section>
    <footer>Jaga kerahasiaan password Anda.<br>Jangan bagikan kepada siapa pun.</footer>
</main>
</body>
</html>
