<?php
// Error handler path - sesuaikan
require_once __DIR__ . '/../../public/error_handler.php';
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);

// Debug: Log session data
error_log("=== user_ahlak_only.php START ===");
error_log("logged_in: " . (isset($_SESSION['logged_in']) ? $_SESSION['logged_in'] : 'NOT SET'));
error_log("id_puskes: " . (isset($_SESSION['id_puskes']) ? $_SESSION['id_puskes'] : 'NOT SET'));
error_log("role: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET'));
error_log("user_type: " . (isset($_SESSION['user_type']) ? $_SESSION['user_type'] : 'NOT SET'));

// Cek apakah user sudah login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    error_log("User not logged in. Redirecting to login.");
    $_SESSION['login_error'] = 'Silakan login terlebih dahulu';
    header('Location: ../login_ahlak.php');
    exit;
}

error_log("User is logged in.");

// Cek apakah role adalah user biasa
if ($_SESSION['role'] !== 'user') {
    error_log("Invalid role for Ahlak access: " . $_SESSION['role']);
    $_SESSION['login_error'] = 'Akses ditolak. Hanya user biasa yang dapat mengakses halaman Puskesmas.';
    header('Location: ../login_ahlak.php');
    exit;
}

error_log("Role is valid: " . $_SESSION['role']);

// Cek apakah user bertipe puskesmas
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'puskesmas') {
    error_log("User is not puskesmas type. Type: " . ($_SESSION['user_type'] ?? 'NOT SET'));
    
    // Cek alternatif: apakah user memiliki id_puskes?
    if (!isset($_SESSION['id_puskes']) || empty($_SESSION['id_puskes'])) {
        error_log("User does not have id_puskes. Redirecting to general dashboard.");
        header('Location: ../user/dashboard_user.php');
        exit;
    }
    
    // Jika punya id_puskes tapi bukan tipe puskesmas, update session
    $_SESSION['user_type'] = 'puskesmas';
    error_log("Updated user_type to puskesmas based on id_puskes");
}

error_log("User type is puskesmas.");

// Validasi: User harus memiliki id_puskes
if (!isset($_SESSION['id_puskes']) || empty($_SESSION['id_puskes'])) {
    error_log("User does not have id_puskes. Redirecting to general dashboard.");
    header('Location: ../user/dashboard_user.php');
    exit;
}

error_log("User has id_puskes: " . $_SESSION['id_puskes']);

// Validasi: User TIDAK boleh memiliki id_kua atau id_rs
if (isset($_SESSION['id_kua']) && $_SESSION['id_kua'] != 0) {
    error_log("User has id_kua: " . $_SESSION['id_kua'] . ". Redirecting to KUA dashboard.");
    header('Location: ../user_kua/kua_dashboard_user.php');
    exit;
}

if (isset($_SESSION['id_rs']) && $_SESSION['id_rs'] != 0) {
    error_log("User has id_rs: " . $_SESSION['id_rs'] . ". Redirecting to general dashboard.");
    header('Location: ../user/dashboard_user.php');
    exit;
}

// PERBAIKAN: Path yang benar untuk struktur folder Anda
require_once __DIR__ . '/../config/koneksi.php';

// Validasi koneksi
if (!isset($koneksi) || !$koneksi) {
    error_log("Database connection failed. Redirecting to error page.");
    header('Location: ../error.php?message=Database connection failed');
    exit;
}

// Cek apakah $_SESSION['id_user'] sudah ada
if (!isset($_SESSION['id_user'])) {
    error_log("User ID not found in session. Cannot validate puskesmas.");
    header('Location: ../login_ahlak.php');
    exit;
}

// Ambil data Puskesmas dari database untuk validasi
// Ambil data puskesmas dari tb_puskes
$query_puskes = $koneksi->prepare("
    SELECT id_puskes, nama_puskes, id_kecamatan 
    FROM tb_puskes 
    WHERE id_puskes = ?
");

if (!$query_puskes) {
    error_log("Prepare failed for tb_puskes: " . $koneksi->error);
    // Fallback: cek apakah user memiliki id_puskes di tb_users
    $query_user = $koneksi->prepare("
        SELECT id_puskes 
        FROM tb_users 
        WHERE id = ? AND id_puskes IS NOT NULL AND id_puskes != 0
    ");
    
    if ($query_user) {
        $query_user->bind_param('i', $_SESSION['id_user']);
        $query_user->execute();
        $result_user = $query_user->get_result();
        
        if ($result_user->num_rows === 0) {
            error_log("User does not have valid id_puskes in tb_users. Redirecting.");
            unset($_SESSION['id_puskes'], $_SESSION['nama_puskes']);
            header('Location: ../user/dashboard_user.php');
            exit;
        }
        
        $query_user->close();
        
        // Set nama puskesmas default
        $_SESSION['nama_puskes'] = 'Puskesmas ' . $_SESSION['id_puskes'];
        error_log("Using default puskesmas name: " . $_SESSION['nama_puskes']);
    }
} else {
    $query_puskes->bind_param('i', $_SESSION['id_puskes']);
    $query_puskes->execute();
    $result_puskes = $query_puskes->get_result();

    if ($result_puskes->num_rows === 0) {
        error_log("Puskesmas not found in tb_puskes. id_puskes: " . $_SESSION['id_puskes']);
        // Fallback: cek di tb_users
        $query_check = $koneksi->prepare("SELECT id_puskes FROM tb_users WHERE id = ? AND id_puskes = ?");
        $query_check->bind_param('ii', $_SESSION['id_user'], $_SESSION['id_puskes']);
        $query_check->execute();
        $result_check = $query_check->get_result();
        
        if ($result_check->num_rows === 0) {
            error_log("User-puskesmas mismatch. Redirecting.");
            unset($_SESSION['id_puskes']);
            header('Location: ../user/dashboard_user.php');
            exit;
        }
        
        $_SESSION['nama_puskes'] = 'Puskesmas ' . $_SESSION['id_puskes'];
        error_log("Using default name for puskesmas ID: " . $_SESSION['id_puskes']);
        $query_check->close();
    } else {
        // Set data Puskesmas ke session
        $puskes_data = $result_puskes->fetch_assoc();
        $_SESSION['nama_puskes'] = $puskes_data['nama_puskes'];
        $_SESSION['id_puskes_kecamatan'] = $puskes_data['id_kecamatan'];
        
        // Ambil nama kecamatan jika diperlukan
        $sql_kecamatan = "SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?";
        $stmt_kec = $koneksi->prepare($sql_kecamatan);
        if ($stmt_kec) {
            $stmt_kec->bind_param("i", $puskes_data['id_kecamatan']);
            $stmt_kec->execute();
            $result_kec = $stmt_kec->get_result();
            if ($kecamatan = $result_kec->fetch_assoc()) {
                $_SESSION['nama_kecamatan'] = $kecamatan['nama_kecamatan'];
                error_log("Kecamatan puskesmas: " . $kecamatan['nama_kecamatan']);
            }
            $stmt_kec->close();
        }
        
        error_log("Puskesmas validated: " . $_SESSION['nama_puskes'] . " (Kecamatan ID: " . $_SESSION['id_puskes_kecamatan'] . ")");
    }
    
    $query_puskes->close();
}

error_log("=== user_ahlak_only.php END ===");
?>
