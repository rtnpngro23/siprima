<?php
require_once '../../public/error_handler.php';
// operator_ahlak_only.php - Middleware untuk validasi akses operator AHLak (P
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);

// Debug start
error_log("=== KUA OPERATOR ONLY CHECK ===");
error_log("Session data:");
error_log("  - logged_in: " . (isset($_SESSION['logged_in']) ? 'true' : 'false'));
error_log("  - role: " . ($_SESSION['role'] ?? 'NOT SET'));
error_log("  - id_kua: " . ($_SESSION['id_kua'] ?? 'NOT SET'));
error_log("  - nama: " . ($_SESSION['nama'] ?? 'NOT SET'));

// 1. Cek apakah user sudah login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    error_log("❌ FAILED: User not logged in or session expired");
    header('Location: ../login_kua.php');
    exit;
}

// 2. Cek apakah user adalah operator
if ($_SESSION['role'] !== 'operator') {
    error_log("❌ FAILED: User role is " . ($_SESSION['role'] ?? 'NULL') . ", not operator");
    header('Location: ../index.php');
    exit;
}

// 3. Cek apakah operator memiliki KUA (opsional, tergantung kebutuhan)
//    Jika ingin strict, uncomment kode berikut:
/*
if (!isset($_SESSION['id_kua']) || empty($_SESSION['id_kua'])) {
    error_log("❌ FAILED: Operator has no KUA assignment");
    session_destroy();
    header('Location: ../login_pasutri_ahlak.php?error=no_kua_assigned');
    exit;
}
*/

error_log("✅ SUCCESS: User " . $_SESSION['nama'] . " is valid KUA operator");
error_log("=============================");
?>
