<?php
require_once '../../public/error_handler.php';
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);

// Debug
error_log("=== OPERATOR ONLY CHECK ===");
error_log('Operator access request: ' . siprima_request_id());

// Cek apakah user sudah login (gunakan id_user sebagai penanda login)
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    error_log("User not logged in - no id_user in session");
    header("Location: ../login.php");
    exit;
}

// Cek apakah role adalah operator
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'operator') {
    error_log("User role is not operator. Role: " . ($_SESSION['role'] ?? 'NOT SET'));
    
    // Redirect berdasarkan role
    switch ($_SESSION['role'] ?? '') {
        case 'admin':
            header("Location: ../admin/dashboard_admin.php");
            break;
        case 'user':
            header("Location: ../user/dashboard_user.php");
            break;
        default:
            header("Location: ../login.php");
    }
    exit;
}

error_log("Operator access granted for user: " . ($_SESSION['nama'] ?? 'Unknown'));
?>
