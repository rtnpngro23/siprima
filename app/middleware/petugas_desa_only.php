<?php
require_once '../../public/error_handler.php';
require_once __DIR__ . '/service_submission.php';
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);

if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

if ($_SESSION['role'] !== 'petugas_desa') {
    header('Location: ../index.php');
    exit;
}

service_hours_register_notice();
?>
