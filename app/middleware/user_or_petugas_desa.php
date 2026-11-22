<?php
require_once '../../public/error_handler.php';
require_once __DIR__ . '/service_submission.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$allowedRoles = ['user', 'petugas_desa'];
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);
if (empty($_SESSION['id_user']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

service_hours_register_notice();
