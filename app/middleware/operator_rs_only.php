<?php
require_once '../../public/error_handler.php';
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);

// Debug start
error_log("=== RUMAH SAKIT OPERATOR ONLY CHECK ===");
error_log("Session data:");
error_log("  - logged_in: " . (isset($_SESSION['logged_in']) ? 'true' : 'false'));
error_log("  - role: " . ($_SESSION['role'] ?? 'NOT SET'));
error_log("  - id_rs: " . ($_SESSION['id_rs'] ?? 'NOT SET'));
error_log("  - nama_rs: " . ($_SESSION['nama_rs'] ?? 'NOT SET'));
error_log("  - nama: " . ($_SESSION['nama'] ?? 'NOT SET'));
error_log("  - operator_type: " . ($_SESSION['operator_type'] ?? 'NOT SET'));
error_log("  - rs_tugas: " . (isset($_SESSION['rs_tugas']) ? json_encode($_SESSION['rs_tugas']) : 'NOT SET'));

// 1. Cek apakah user sudah login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    error_log("❌ FAILED: User not logged in or session expired");
    header('Location: ../login_rs.php');
    exit;
}

// 2. Cek apakah user adalah operator
if ($_SESSION['role'] !== 'operator') {
    error_log("❌ FAILED: User role is " . ($_SESSION['role'] ?? 'NULL') . ", not operator");
    header('Location: ../index.php');
    exit;
}

// 3. LOAD DATA RUMAH SAKIT OPERATOR (MULTI RS SUPPORT)
// PERBAIKAN PATH DI SINI:
// Menggunakan __DIR__ untuk mendapatkan path absolut yang benar
require_once __DIR__ . '/../../app/config/koneksi.php'; // PERBAIKAN DARI '../config/koneksi.php'

$operator_id = $_SESSION['id_user'] ?? null;
$rs_list = [];
$has_rs_assignment = false;

if ($operator_id) {
    // Query 1: Cek rumah sakit dari tb_operator_kecamatan (multi RS)
    $query_multi_rs = $koneksi->prepare("
        SELECT DISTINCT rs.id_rs, rs.nama_rs
        FROM tb_operator_kecamatan ok
        JOIN tb_rumahsakit rs ON ok.rs_id = rs.id_rs
        WHERE ok.operator_id = ? AND ok.rs_id IS NOT NULL
        ORDER BY rs.nama_rs
    ");
    
    if ($query_multi_rs) {
        $query_multi_rs->bind_param("i", $operator_id);
        if ($query_multi_rs->execute()) {
            $result = $query_multi_rs->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $rs_list[] = [
                    'id_rs' => $row['id_rs'],
                    'nama_rs' => $row['nama_rs']
                ];
            }
        }
        $query_multi_rs->close();
        
        error_log("✓ Found " . count($rs_list) . " rumah sakit from tb_operator_kecamatan");
    }
    
    // Query 2: Cek rumah sakit dari tb_users (single RS - untuk kompatibilitas)
    if (empty($rs_list)) {
        $query_single_rs = $koneksi->prepare("
            SELECT u.id_rs, rs.nama_rs 
            FROM tb_users u
            LEFT JOIN tb_rumahsakit rs ON u.id_rs = rs.id_rs
            WHERE u.id = ? AND u.id_rs IS NOT NULL
        ");
        
        if ($query_single_rs) {
            $query_single_rs->bind_param("i", $operator_id);
            if ($query_single_rs->execute()) {
                $result = $query_single_rs->get_result();
                $row = $result->fetch_assoc();
                
                if ($row && !empty($row['id_rs'])) {
                    $rs_list[] = [
                        'id_rs' => $row['id_rs'],
                        'nama_rs' => $row['nama_rs'] ?? 'Rumah Sakit ' . $row['id_rs']
                    ];
                    error_log("✓ Found 1 rumah sakit from tb_users: " . $row['id_rs']);
                }
            }
            $query_single_rs->close();
        }
    }
    
    // 4. VALIDASI: Apakah operator memiliki tugas rumah sakit?
    if (!empty($rs_list)) {
        $has_rs_assignment = true;
        
        // Simpan semua RS ke session
        $_SESSION['operator_rs'] = $rs_list;
        $_SESSION['rs_tugas'] = $rs_list; // Untuk kompatibilitas
        
        // Set RS aktif (default pertama)
        if (!isset($_SESSION['id_rs']) || !isset($_SESSION['nama_rs'])) {
            $_SESSION['id_rs'] = $rs_list[0]['id_rs'];
            $_SESSION['nama_rs'] = $rs_list[0]['nama_rs'];
        }
        
        // Set operator_type
        $_SESSION['operator_type'] = 'rumah_sakit';
        
        error_log("✅ Operator has " . count($rs_list) . " rumah sakit assignments:");
        foreach ($rs_list as $index => $rs) {
            error_log("  RS #" . ($index + 1) . ": " . $rs['id_rs'] . " - " . $rs['nama_rs']);
        }
        error_log("  Active RS: " . $_SESSION['id_rs'] . " - " . $_SESSION['nama_rs']);
        
    } else {
        // 5. OPERATOR TIDAK MEMILIKI RUMAH SAKIT
        error_log("❌ FAILED: Operator has NO rumah sakit assignments");
        
        // Check if operator has other type of assignments
        $query_check_other = $koneksi->prepare("
            SELECT 
                COUNT(CASE WHEN kecamatan_id IS NOT NULL THEN 1 END) as kec_count,
                COUNT(CASE WHEN kua_id IS NOT NULL THEN 1 END) as kua_count,
                COUNT(CASE WHEN puskes_id IS NOT NULL THEN 1 END) as puskes_count,
                COUNT(CASE WHEN rs_id IS NOT NULL THEN 1 END) as rs_count
            FROM tb_operator_kecamatan
            WHERE operator_id = ?
        ");
        
        if ($query_check_other) {
            $query_check_other->bind_param("i", $operator_id);
            if ($query_check_other->execute()) {
                $result = $query_check_other->get_result();
                $row = $result->fetch_assoc();
                
                if ($row) {
                    error_log("📊 Operator assignment summary:");
                    error_log("  - Kecamatan: " . $row['kec_count']);
                    error_log("  - KUA: " . $row['kua_count']);
                    error_log("  - Puskesmas: " . $row['puskes_count']);
                    error_log("  - Rumah Sakit: " . $row['rs_count']);
                    
                    if ($row['kec_count'] > 0 || $row['kua_count'] > 0 || $row['puskes_count'] > 0) {
                        // Operator memiliki tugas lain (kecamatan/kua/puskesmas)
                        error_log("⚠️ Operator has other assignments, redirecting to general operator dashboard");
                        $_SESSION['operator_type'] = 'general';
                        header('Location: ../user/dashboard_operator.php');
                        exit;
                    }
                }
            }
            $query_check_other->close();
        }
        
        // Redirect ke halaman error
        $error_message = "Anda tidak memiliki akses ke halaman ini karena:";
        $error_message .= "\n1. Anda belum ditugaskan ke rumah sakit manapun";
        $error_message .= "\n2. Hubungi administrator untuk ditugaskan ke rumah sakit";
        
        session_destroy();
        header('Location: ../login_rs.php?error=no_rs_assigned&message=' . urlencode($error_message));
        exit;
    }
} else {
    error_log("❌ ERROR: Operator ID not found in session");
    header('Location: ../login_rs.php');
    exit;
}

// 6. Set user_type untuk konsistensi
if (!isset($_SESSION['user_type'])) {
    $_SESSION['user_type'] = 'rumah_sakit';
    error_log("✓ Set user_type to 'rumah_sakit'");
}

error_log("✅ SUCCESS: User " . ($_SESSION['nama'] ?? 'Unknown') . " is valid RUMAH SAKIT operator");
error_log("  - Total RS Assignments: " . count($rs_list));
error_log("  - Active RS: " . ($_SESSION['id_rs'] ?? 'N/A') . " - " . ($_SESSION['nama_rs'] ?? 'N/A'));
error_log("=============================");

// ====================================================================
// BAGIAN SWITCH RS (Fungsi untuk ganti RS aktif)
// ====================================================================

// Fungsi untuk ganti RS aktif
function switchActiveRS($new_rs_id) {
    global $koneksi;
    
    $operator_id = $_SESSION['id_user'];
    
    // Validasi: Cek apakah operator memiliki akses ke RS ini
    $query = $koneksi->prepare("
        SELECT rs.id_rs, rs.nama_rs
        FROM tb_operator_kecamatan ok
        JOIN tb_rumahsakit rs ON ok.rs_id = rs.id_rs
        WHERE ok.operator_id = ? AND ok.rs_id = ?
        LIMIT 1
    ");
    
    $query->bind_param("ii", $operator_id, $new_rs_id);
    if ($query->execute()) {
        $result = $query->get_result();
        $row = $result->fetch_assoc();
        
        if ($row) {
            // Update session dengan RS baru
            $_SESSION['id_rs'] = $row['id_rs'];
            $_SESSION['nama_rs'] = $row['nama_rs'];
            
            error_log("✓ Switched active RS to: " . $row['id_rs'] . " - " . $row['nama_rs']);
            return true;
        }
    }
    
    error_log("❌ FAILED: Operator does not have access to RS ID: " . $new_rs_id);
    return false;
}

// Proses request untuk ganti RS jika ada parameter
if (isset($_GET['switch_rs']) && is_numeric($_GET['switch_rs'])) {
    $new_rs_id = (int)$_GET['switch_rs'];
    
    if (switchActiveRS($new_rs_id)) {
        // Redirect kembali ke halaman sebelumnya tanpa parameter
        $current_url = strtok($_SERVER["REQUEST_URI"], '?');
        header('Location: ' . $current_url);
        exit;
    } else {
        error_log("⚠️ Invalid RS switch request: " . $new_rs_id);
    }
}
?>
