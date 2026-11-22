<?php
require_once __DIR__ . '/../../public/error_handler.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);

if (($_SESSION['logged_in'] ?? false) !== true || empty($_SESSION['id_user'])) {
    $_SESSION['login_error'] = 'Silakan login terlebih dahulu.';
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'user') {
    $_SESSION['login_error'] = 'Akses hanya untuk akun pengguna Rumah Sakit.';
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/koneksi.php';
if (!isset($koneksi) || !$koneksi) {
    http_response_code(503);
    exit('Layanan Rumah Sakit sedang tidak tersedia. Silakan coba kembali.');
}

$userId = (int) $_SESSION['id_user'];
$stmt = $koneksi->prepare(
    'SELECT u.id_rs, rs.nama_rs
     FROM tb_users u
     LEFT JOIN tb_rumahsakit rs ON rs.id_rs = u.id_rs
     WHERE u.id = ? AND u.id_rs IS NOT NULL AND u.id_rs <> 0
     LIMIT 1'
);

if (!$stmt) {
    http_response_code(503);
    exit('Data akun Rumah Sakit sedang tidak dapat diperiksa.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$rsAccount = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rsAccount) {
    $_SESSION['login_error'] = 'Akun ini tidak memiliki penugasan Rumah Sakit.';
    header('Location: ../login.php');
    exit;
}

$_SESSION['id_rs'] = (int) $rsAccount['id_rs'];
$_SESSION['nama_rs'] = $rsAccount['nama_rs'] ?: 'Rumah Sakit ' . $_SESSION['id_rs'];
$_SESSION['user_type'] = 'rumah_sakit';

// Bersihkan penugasan lokasi lama agar tidak memicu redirect ke modul lain.
unset(
    $_SESSION['id_kua'], $_SESSION['nama_kua'], $_SESSION['id_kua_db'],
    $_SESSION['id_puskes'], $_SESSION['nama_puskes'], $_SESSION['id_puskes_db']
);
