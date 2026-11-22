<?php
declare(strict_types=1);
require_once __DIR__.'/error_handler.php';
require_once __DIR__.'/../app/config/koneksi.php';
require_once __DIR__.'/../app/security/password_reset.php';
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
if (empty($_SESSION['id_user'])) { header('Location: login.php'); exit; }
$id = (int)$_SESSION['id_user']; $error = ''; $success = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $password = (string)($_POST['new_password'] ?? '');
        if (($problem = siprima_password_error($password)) !== null) throw new DomainException($problem);
        if ($password !== (string)($_POST['confirm_password'] ?? '')) throw new DomainException('Konfirmasi password tidak cocok.');
        $koneksi->begin_transaction();
        $stmt = siprima_reset_stmt($koneksi, 'SELECT password,must_change_password,UNIX_TIMESTAMP(temporary_password_expires_at) AS expires_epoch FROM tb_users WHERE id=? FOR UPDATE', 'i', [$id]);
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$user || empty($user['must_change_password']) || (int)$user['expires_epoch'] <= time()
            || !password_verify((string)($_POST['current_password'] ?? ''), $user['password'])) throw new DomainException('Password sementara tidak valid.');
        if (password_verify($password, $user['password'])) throw new DomainException('Gunakan password baru yang berbeda.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = siprima_reset_stmt($koneksi, 'UPDATE tb_users SET password=?,must_change_password=0,temporary_password_expires_at=NULL,updated_at=NOW() WHERE id=?', 'si', [$hash,$id]); $stmt->close();
        $stmt = siprima_reset_stmt($koneksi, 'UPDATE tb_password_reset SET consumed_at=NOW() WHERE user_id=? AND consumed_at IS NULL', 'i', [$id]); $stmt->close();
        $koneksi->commit(); siprima_auth_logout(); $success = true;
        error_log('SECURITY temporary_password_changed user_id='.$id);
    } catch (DomainException $e) { $koneksi->rollback(); $error = $e->getMessage(); }
    catch (Throwable $e) { $koneksi->rollback(); $error = 'Password belum dapat diubah. Hubungi administrator.'; }
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ganti Password Sementara — SIPRIMA</title><link rel="stylesheet" href="assets/css/password_rules.css?v=20260922-1"><link rel="stylesheet" href="assets/css/theme.css?v=20261118-3"><script src="assets/js/theme.js?v=20261118-1"></script><script src="assets/js/password_rules.js?v=20260922-1" defer></script></head><body><main>
<h1>Ganti Password Sementara</h1>
<?php if ($success): ?><p>Password berhasil diubah. <a href="login.php">Login kembali</a>.</p>
<?php else: ?><p>Anda perlu mengganti password sementara sebelum menggunakan layanan.</p><p><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></p><p><?=htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8')?></p>
<form method="post"><?=csrf_input()?><span data-password-rules-config="<?=htmlspecialchars(siprima_password_rules_json(), ENT_QUOTES, 'UTF-8')?>" hidden></span>
<p><label>Password sementara <input type="password" name="current_password" required autocomplete="current-password"></label></p>
<p><label>Password baru <input type="password" id="newPassword" name="new_password" data-password-rules required minlength="8" maxlength="72" autocomplete="new-password"></label></p>
<p><label>Konfirmasi password <input type="password" id="confirmPassword" name="confirm_password" data-password-match="newPassword" required minlength="8" maxlength="72" autocomplete="new-password"></label></p>
<button type="submit">Simpan password</button></form><p><a href="logout.php">Keluar</a></p>
<?php endif; ?></main></body></html>
