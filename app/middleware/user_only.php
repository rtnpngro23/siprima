<?php
require_once '../../public/error_handler.php';
require_once __DIR__ . '/service_submission.php';
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'user') {
    switch ($_SESSION['role'] ?? '') {
        case 'admin':
            header("Location: ../admin/dashboard_admin.php");
            break;
        case 'operator':
            header("Location: ../user/dashboard_operator.php");
            break;
        default:
            header("Location: ../login.php");
    }
    exit;
}

service_hours_register_notice();
?>