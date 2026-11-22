<?php
require_once '../../public/error_handler.php';
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);

// Debug start
error_log("=== AHLak OPERATOR ONLY CHECK ===");
error_log("Session data:");
error_log("  - logged_in: " . (isset($_SESSION['logged_in']) ? 'true' : 'false'));
error_log("  - role: " . ($_SESSION['role'] ?? 'NOT SET'));
error_log("  - id_puskes: " . ($_SESSION['id_puskes'] ?? 'NOT SET'));
error_log("  - nama: " . ($_SESSION['nama'] ?? 'NOT SET'));
error_log("  - operator_type: " . ($_SESSION['operator_type'] ?? 'NOT SET'));
error_log("  - user_type: " . ($_SESSION['user_type'] ?? 'NOT SET'));

// 1. Cek apakah user sudah login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    error_log("❌ FAILED: User not logged in or session expired");
    header('Location: ../login_ahlak.php');
    exit;
}

// 2. Cek apakah user adalah operator
if ($_SESSION['role'] !== 'operator') {
    error_log("❌ FAILED: User role is " . ($_SESSION['role'] ?? 'NULL') . ", not operator");
    header('Location: ../index.php');
    exit;
}

// 3. Cek apakah operator memiliki tugas di PUSKESMAS/AHLak
//    Ada beberapa kondisi untuk menentukan operator AHLak:
//    a. Memiliki id_puskes di session
//    b. Memiliki user_type = 'puskesmas'
//    c. Tidak memiliki id_kua (untuk membedakan dari operator KUA)

$is_ahlak_operator = false;

// Kondisi 1: Memiliki id_puskes
if (isset($_SESSION['id_puskes']) && !empty($_SESSION['id_puskes'])) {
    $is_ahlak_operator = true;
    error_log("✅ User is AHLak operator: Has id_puskes = " . $_SESSION['id_puskes']);
}

// Kondisi 2: Memiliki user_type = 'puskesmas'
if (!$is_ahlak_operator && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'puskesmas') {
    $is_ahlak_operator = true;
    error_log("✅ User is AHLak operator: user_type = puskesmas");
}

// Kondisi 3: Tidak memiliki id_kua (untuk membedakan dari operator KUA)
if (isset($_SESSION['id_kua']) && !empty($_SESSION['id_kua'])) {
    // Operator memiliki KUA, redirect ke KUA dashboard
    error_log("⚠️ Operator has KUA assignment, redirecting to KUA dashboard");
    header('Location: ../user_kua/kua_dashboard_operator.php');
    exit;
}

// 4. Jika bukan operator AHLak, redirect ke dashboard umum
if (!$is_ahlak_operator) {
    error_log("❌ FAILED: User is not AHLak operator");
    
    // Cek apakah operator memiliki tugas kecamatan (operator umum)
    if (isset($_SESSION['kecamatan_tugas']) && !empty($_SESSION['kecamatan_tugas'])) {
        error_log("⚠️ Operator has kecamatan assignment, redirecting to general operator dashboard");
        header('Location: ../user/dashboard_operator.php');
        exit;
    } else {
        // Jika tidak memiliki tugas apapun, redirect ke login
        error_log("⚠️ Operator has no assignment, redirecting to login");
        session_destroy();
        header('Location: ../login_ahlak.php?error=no_assignment');
        exit;
    }
}

// 5. Validasi tambahan: Operator harus memiliki minimal satu puskesmas
if (!isset($_SESSION['nama_puskes']) || empty($_SESSION['nama_puskes'])) {
    error_log("⚠️ AHLak operator has no puskesmas name, trying to fetch...");
    
    // Coba ambil nama puskesmas dari database jika belum ada
    require_once '../config/koneksi.php';
    
    if (isset($_SESSION['id_puskes']) && !empty($_SESSION['id_puskes'])) {
        $sql = "SELECT nama_puskes FROM tb_puskes WHERE id_puskes = ?";
        $stmt = $koneksi->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $_SESSION['id_puskes']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $_SESSION['nama_puskes'] = $row['nama_puskes'];
                error_log("✅ Fetched puskesmas name: " . $row['nama_puskes']);
            }
            $stmt->close();
        }
    }
}

// 6. Log success
error_log("✅ SUCCESS: User " . ($_SESSION['nama'] ?? 'Unknown') . " is valid AHLak operator");
error_log("   Puskesmas ID: " . ($_SESSION['id_puskes'] ?? 'N/A'));
error_log("   Puskesmas Name: " . ($_SESSION['nama_puskes'] ?? 'N/A'));
error_log("=============================");
?>
