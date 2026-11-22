<?php
require_once 'error_handler.php';
session_start();

$redirectTarget = 'login.php';
if (!empty($_SESSION['id_rs']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'rumah_sakit') || (isset($_SESSION['operator_type']) && $_SESSION['operator_type'] === 'rumah_sakit')) {
    $redirectTarget = 'login_rs.php';
} elseif (!empty($_SESSION['id_kua']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'kua') || (isset($_SESSION['operator_type']) && $_SESSION['operator_type'] === 'kua_only')) {
    $redirectTarget = 'login_kua.php';
} elseif (!empty($_SESSION['id_puskes']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'puskesmas') || (isset($_SESSION['operator_type']) && $_SESSION['operator_type'] === 'puskesmas')) {
    $redirectTarget = 'login_ahlak.php';
}

// Debug: Log session before destruction
error_log("=== LOGOUT PROCESS ===");
error_log("Session data before logout:");
foreach ($_SESSION as $key => $value) {
    if (!is_array($value)) {
        error_log("$key: $value");
    }
}

// Hapus semua session
session_unset();
session_destroy();

// Hapus cookie session jika ada
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Redirect ke halaman login sesuai konteks sesi
header('Location: ' . $redirectTarget);
exit;
?>
