<?php
require_once __DIR__ . '/../../public/error_handler.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);

if (($_SESSION['logged_in'] ?? false) !== true) {
    $_SESSION['login_error'] = 'Silakan login terlebih dahulu';
    header('Location: ../login_kua.php');
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['user', 'operator'], true) || empty($_SESSION['id_kua'])) {
    $_SESSION['login_error'] = 'Akses KUA tidak diizinkan.';
    header('Location: ../login_kua.php');
    exit;
}

require_once __DIR__ . '/../config/koneksi.php';
if (!isset($koneksi) || !$koneksi) {
    http_response_code(503);
    exit('Layanan KUA sedang tidak tersedia. Silakan coba kembali.');
}

$query_kua = $koneksi->prepare('SELECT id_kua, nama_kua FROM tb_kua WHERE id_kua = ?');
if (!$query_kua) {
    http_response_code(503);
    exit('Layanan KUA sedang tidak tersedia. Silakan coba kembali.');
}

$id_kua = (int)$_SESSION['id_kua'];
$query_kua->bind_param('i', $id_kua);
$executed = $query_kua->execute();
if (!$executed) {
    $query_kua->close();
    http_response_code(503);
    exit('Layanan KUA sedang tidak tersedia. Silakan coba kembali.');
}
$kua_data = $query_kua->get_result()->fetch_assoc();
$query_kua->close();

if (!$kua_data) {
    unset($_SESSION['id_kua'], $_SESSION['nama_kua'], $_SESSION['id_kua_db']);
    $_SESSION['login_error'] = 'Data KUA tidak ditemukan.';
    header('Location: ../login_kua.php');
    exit;
}

$_SESSION['nama_kua'] = $kua_data['nama_kua'];
$_SESSION['id_kua_db'] = $kua_data['id_kua'];
