<?php
require_once 'error_handler.php';
require_once __DIR__ . '/../app/security/login_security.php';
require_once __DIR__ . '/../app/config/session_cache.php';
require_once __DIR__ . '/../app/config/service_hours.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$loginType = $_GET['type'] ?? 'general';
$loginType = in_array($loginType, ['general', 'petugas_desa', 'kua', 'puskesmas', 'rs'], true) ? $loginType : 'general';
$loginRedirectBase = $loginType === 'kua'
    ? 'login_kua.php'
    : ($loginType === 'rs'
        ? 'login_rs.php'
        : ($loginType === 'puskesmas'
            ? 'login_ahlak.php'
            : ($loginType === 'petugas_desa' ? 'login_add.php' : 'login.php')));
$loginRedirect = $loginRedirectBase;

function login_redirect_with_type($url) {
    header('Location: ' . $url);
    exit;
}

function login_query_cache() {
    if (isset($GLOBALS['session_cache_file']) && $GLOBALS['session_cache_file'] instanceof SessionCacheFile) {
        return $GLOBALS['session_cache_file'];
    }

    static $localCache = null;
    if ($localCache === null) {
        $localCache = new SessionCacheFile();
    }

    return $localCache;
}

function login_cached_select_all_one_param(mysqli $koneksi, $query, $paramType, $paramValue, $ttl = 120) {
    $cache = login_query_cache();
    $cacheParams = [$paramType, $paramValue];
    // Authorization assignments must be read freshly at each login.

    $stmt = $koneksi->prepare($query);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($paramType, $paramValue);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    $cache->cacheQuery($query, $cacheParams, $rows, $ttl);
    return $rows;
}

function login_cached_select_row_one_param(mysqli $koneksi, $query, $paramType, $paramValue, $ttl = 120) {
    $rows = login_cached_select_all_one_param($koneksi, $query, $paramType, $paramValue, $ttl);
    return $rows[0] ?? null;
}

// Koneksi ke database
require_once __DIR__ . '/../app/config/koneksi.php';

// Cek koneksi
handleDatabaseError($koneksi);

// Debug: Log semua session yang ada
error_log("==== START LOGIN PROCESS ====");
error_log('Login request: ' . siprima_request_id());

// Ambil data dari form
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$captchaAnswer = $_POST['captcha_answer'] ?? '';

$throttleStatus = login_throttle_begin_attempt();

if ($throttleStatus['is_locked']) {
    $_SESSION['login_error'] = 'Terlalu banyak percobaan login. Silakan coba lagi beberapa menit lagi.';
    $_SESSION['error_title'] = 'Login Dikunci Sementara';
    $_SESSION['error_type'] = 'general';
    $_SESSION['login_username'] = $username;
    login_captcha_prepare();
    login_redirect_with_type($loginRedirect);
}

if ($throttleStatus['requires_captcha']) {
    if (!login_captcha_verify($captchaAnswer)) {
        $throttleAfterFail = login_throttle_register_failure();
        $_SESSION['login_error'] = 'CAPTCHA tidak valid. Silakan coba lagi.';
        $_SESSION['error_title'] = 'Verifikasi Gagal';
        $_SESSION['error_type'] = 'general';
        $_SESSION['login_username'] = $username;
        if ($throttleAfterFail['is_locked']) {
            $_SESSION['login_error'] = 'Terlalu banyak percobaan login. Silakan coba lagi beberapa menit lagi.';
            $_SESSION['error_title'] = 'Login Dikunci Sementara';
        } else {
            login_captcha_prepare();
        }
        login_redirect_with_type($loginRedirect);
    }
}

error_log("Form Data - Password: [HIDDEN]");

// Validasi input
if (empty($username) || empty($password)) {
    login_throttle_register_failure();
    $_SESSION['login_error'] = 'NIK dan password harus diisi';
    $_SESSION['error_title'] = 'Data Tidak Lengkap';
    $_SESSION['error_type'] = 'general';
    $_SESSION['login_username'] = $username;
    error_log("Validation failed: Empty fields");
    login_redirect_with_type($loginRedirect);
}

// Validasi NIK 16 digit
if (!preg_match("/^[0-9]{16}$/", $username)) {
    login_throttle_register_failure();
    $_SESSION['login_error'] = 'NIK harus 16 digit angka';
    $_SESSION['error_title'] = 'Format NIK Salah';
    $_SESSION['error_type'] = 'general';
    $_SESSION['login_username'] = $username;
    error_log("Validation failed: NIK format incorrect");
    login_redirect_with_type($loginRedirect);
}

// Ambil user langsung dengan join ke tabel KUA, PUSKESMAS, dan RUMAH SAKIT
$sql = "SELECT u.id, u.nama, u.nik, u.email, u.password, u.role, u.is_active, 
        u.created_at, u.updated_at, u.id_kua, u.id_puskes, u.id_rs, 
        u.id_kecamatan, u.id_desa, u.id_petugas,
        k.nama_kua, k.id_kecamatan as kua_kecamatan_id,
        p.nama_puskes, p.id_kecamatan as puskes_kecamatan_id,
        rs.nama_rs
        FROM tb_users u
        LEFT JOIN tb_kua k ON u.id_kua = k.id_kua
        LEFT JOIN tb_puskes p ON u.id_puskes = p.id_puskes
        LEFT JOIN tb_rumahsakit rs ON u.id_rs = rs.id_rs
        WHERE u.nik = ?";
$stmt = $koneksi->prepare($sql);

if (!$stmt) {
    // Pastikan $koneksi adalah objek sebelum mengakses properti error
    $errorMessage = (isset($koneksi) && $koneksi instanceof mysqli) ? $koneksi->error : "Unknown connection error";
    
    error_log("Prepare failed: " . $errorMessage);
    $_SESSION['login_error'] = 'Kesalahan sistem. Silakan coba lagi.';
    login_redirect_with_type($loginRedirect);
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // NIK tidak ditemukan
    login_throttle_register_failure();
    $_SESSION['login_error'] = 'NIK atau password yang Anda masukkan salah. Silakan periksa kembali.';
    $_SESSION['error_title'] = 'Login Gagal';
    $_SESSION['error_type'] = 'credentials_invalid';
    $_SESSION['login_username'] = $username;
    
    $_SESSION['suggestion'] = 'Periksa kembali NIK dan huruf besar/kecil pada password. Lupa password? Hubungi administrator.';
    
    error_log('Login account not found');
    login_redirect_with_type($loginRedirect);
}

// User ditemukan
$user = $result->fetch_assoc();
error_log("User found: ID=" . $user['id'] . ", Name=" . $user['nama'] . ", Role=" . $user['role'] . ", Active=" . $user['is_active']);
error_log("Location Info - KUA: " . $user['id_kua'] . ", PUSKES: " . $user['id_puskes'] . ", RS: " . $user['id_rs']);

// Cek status aktif user
if ($user['is_active'] == 0) {
    login_throttle_register_failure();
    $_SESSION['login_error'] = 'Akun Anda telah dinonaktifkan. Silakan hubungi administrator.';
    $_SESSION['error_title'] = 'Akun Dinonaktifkan';
    $_SESSION['error_type'] = 'general';
    $_SESSION['login_username'] = $username;
    error_log("Account is inactive for user: " . $user['nama']);
    login_redirect_with_type($loginRedirect);
}

// Verifikasi password
error_log("Verifying password...");
if (!password_verify($password, $user['password'])) {
    // Password salah
    login_throttle_register_failure();
    error_log("Password verification FAILED for user: " . $user['nama']);
    
    $_SESSION['login_error'] = 'NIK atau password yang Anda masukkan salah. Silakan periksa kembali.';
    $_SESSION['error_title'] = 'Login Gagal';
    $_SESSION['error_type'] = 'credentials_invalid';
    $_SESSION['login_username'] = $username;
    
    $_SESSION['suggestion'] = 'Periksa kembali NIK dan huruf besar/kecil pada password. Lupa password? Hubungi administrator.';
    
    login_redirect_with_type($loginRedirect);
}

// Login tetap tersedia di luar jam pengajuan.
if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
    $upgradedHash = password_hash($password, PASSWORD_DEFAULT);
    $rehash = $koneksi->prepare('UPDATE tb_users SET password=? WHERE id=? AND password=?');
    if (!$rehash) throw new RuntimeException('Password hash upgrade unavailable.');
    $rehash->bind_param('sis', $upgradedHash, $user['id'], $user['password']);
    if (!$rehash->execute() || $rehash->affected_rows !== 1) throw new RuntimeException('Credentials changed during login.');
    $rehash->close(); $user['password'] = $upgradedHash;
}
// Password cocok - LOGIN BERHASIL
error_log("Password verification SUCCESS for user: " . $user['nama']);

// Prevent session fixation after successful login
session_regenerate_id(true);
// Clear identities and assignments belonging to a previous login in this browser.
$_SESSION = [];
siprima_auth_bind($koneksi, (int)$user['id'], $user);
// Set session data dasar
$_SESSION['id_user'] = $user['id'];
$_SESSION['nama'] = $user['nama'];
$_SESSION['nik'] = $user['nik'];
$_SESSION['email'] = $user['email'];
$_SESSION['no_hp'] = $user['no_hp'] ?? '';
$_SESSION['role'] = $user['role'];
$_SESSION['is_active'] = $user['is_active'];
$_SESSION['created_at'] = $user['created_at'];
$_SESSION['updated_at'] = $user['updated_at'];
$_SESSION['logged_in'] = true;

// PROSES KHUSUS UNTUK ROLE 'USER' BIASA
if ($user['role'] === 'user') {
    error_log("Processing REGULAR USER type...");
    
    // Tentukan tipe user berdasarkan lokasi
    $user_type = 'umum'; // default
    
    // 1. User KUA (memiliki id_kua, tidak memiliki id_puskes/id_rs)
    if (!empty($user['id_kua']) && empty($user['id_puskes']) && empty($user['id_rs'])) {
        $_SESSION['id_kua'] = $user['id_kua'];
        $_SESSION['nama_kua'] = $user['nama_kua'] ?? 'KUA ' . $user['id_kua'];
        $_SESSION['id_kecamatan'] = $user['kua_kecamatan_id'] ?? null;
        $user_type = 'kua';
        
        error_log("User identified as KUA user: " . $_SESSION['nama_kua']);
    }
    // 2. User PUSKESMAS (memiliki id_puskes, tidak memiliki id_kua/id_rs)
    elseif (!empty($user['id_puskes']) && empty($user['id_kua']) && empty($user['id_rs'])) {
        $_SESSION['id_puskes'] = $user['id_puskes'];
        $_SESSION['nama_puskes'] = $user['nama_puskes'] ?? 'Puskesmas ' . $user['id_puskes'];
        $_SESSION['id_kecamatan'] = $user['puskes_kecamatan_id'] ?? null;
        $user_type = 'puskesmas';
        
        error_log("User identified as PUSKESMAS user: " . $_SESSION['nama_puskes']);
    }
    // 3. User RUMAH SAKIT (memiliki id_rs, tidak memiliki id_kua/id_puskes)
    elseif (!empty($user['id_rs']) && empty($user['id_kua']) && empty($user['id_puskes'])) {
        $_SESSION['id_rs'] = $user['id_rs'];
        $_SESSION['nama_rs'] = $user['nama_rs'] ?? 'Rumah Sakit ' . $user['id_rs'];
        $user_type = 'rumah_sakit';
        
        error_log("User identified as RUMAH SAKIT user: " . $_SESSION['nama_rs'] . " (ID: " . $user['id_rs'] . ")");
    }
    // 4. User UMUM (tidak memiliki id_kua, id_puskes, id_rs)
    else {
        $user_type = 'umum';
        error_log("User identified as GENERAL user (no specific institution)");
    }
    
    $_SESSION['user_type'] = $user_type;
    error_log("User type determined: " . $user_type);
}

// PROSES KHUSUS UNTUK ROLE PETUGAS_DESA
elseif ($user['role'] === 'petugas_desa') {
    error_log("User is PETUGAS_DESA, checking desa assignment...");
    
    // 1. Ambil data desa berdasarkan id_desa
    if (!empty($user['id_desa'])) {
        $sql_desa = "SELECT d.id, d.nama_desa, d.kecamatan_id 
                     FROM tb_desa d
                     WHERE d.id = ?";

        $desa = login_cached_select_row_one_param($koneksi, $sql_desa, 'i', (int)$user['id_desa'], 300);
        if ($desa) {
            $_SESSION['id_desa'] = $desa['id'];
            $_SESSION['nama_desa'] = $desa['nama_desa'];
            $_SESSION['id_kecamatan'] = $desa['kecamatan_id'];
            
            error_log("Desa found: ID=" . $desa['id'] . ", Name=" . $desa['nama_desa'] . ", Kecamatan_ID=" . $desa['kecamatan_id']);
            
            if (!empty($desa['kecamatan_id'])) {
                $sql_kecamatan = "SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?";
                $kecamatan = login_cached_select_row_one_param($koneksi, $sql_kecamatan, 'i', (int)$desa['kecamatan_id'], 300);
                if ($kecamatan && isset($kecamatan['nama_kecamatan'])) {
                    $_SESSION['nama_kecamatan'] = $kecamatan['nama_kecamatan'];
                    error_log("Kecamatan found: " . $kecamatan['nama_kecamatan']);
                }
            }
            
            error_log("Petugas Desa assigned to: Desa=" . $desa['nama_desa']);
        } else {
            error_log("ERROR: Desa data not found for ID: " . $user['id_desa']);
        }
    } else {
        error_log("WARNING: Petugas Desa has no id_desa assigned");
    }
    
    // 2. Cek apakah petugas_desa memiliki tugas kecamatan tambahan
    $sql_kecamatan_tugas = "SELECT k.id, k.nama_kecamatan 
                          FROM tb_operator_kecamatan ok
                          JOIN tb_kecamatan k ON ok.kecamatan_id = k.id
                          WHERE ok.operator_id = ?";

    $kecamatanRows = login_cached_select_all_one_param($koneksi, $sql_kecamatan_tugas, 'i', (int)$user['id'], 120);
    $kecamatan_ids = array();
    $kecamatan_names = array();

    foreach ($kecamatanRows as $row) {
        $kecamatan_ids[] = $row['id'];
        $kecamatan_names[] = $row['nama_kecamatan'];
    }

    if (count($kecamatan_ids) > 0) {
        $_SESSION['kecamatan_tugas'] = $kecamatan_ids;
        $_SESSION['kecamatan_nama'] = $kecamatan_names;
        error_log("Petugas Desa has additional kecamatan assignments: " . count($kecamatan_ids) . " kecamatan");
    }
    
    // 3. Validasi: Petugas desa harus memiliki minimal id_desa
    if (empty($user['id_desa'])) {
        error_log("BLOCKING LOGIN: Petugas Desa has no desa assignment!");
        
        // Hapus session yang sudah dibuat
        session_destroy();
        
        // Set pesan error
        $_SESSION['login_error'] = 'Akun petugas desa Anda belum ditugaskan ke desa manapun. Silakan hubungi administrator untuk penugasan.';
        $_SESSION['error_title'] = 'Belum Ditugaskan';
        $_SESSION['error_type'] = 'petugas_no_desa';
        $_SESSION['login_username'] = $username;
        
        // Tambahkan instruksi
        $suggestion = '
            <strong>Instruksi:</strong>
            <ul>
                <li>Hubungi administrator sistem</li>
                <li>Minta untuk ditugaskan ke desa tertentu</li>
                <li>Setelah ditugaskan, Anda dapat login kembali</li>
            </ul>
        ';
        $_SESSION['suggestion'] = $suggestion;
        
        login_redirect_with_type($loginRedirect);
    }
}

// PROSES KHUSUS UNTUK OPERATOR (KECAMATAN, KUA, AHLak/PUSKESMAS, RUMAH SAKIT)
elseif ($user['role'] === 'operator') {
    error_log("User is OPERATOR, checking assignments...");
    
    $has_assignment = false;
    $has_kecamatan = false;
    $has_kua = false;
    $has_puskes = false;
    $has_rs = false; // TAMBAHAN UNTUK RUMAH SAKIT
    
    // 1. CEK APAKAH OPERATOR MEMILIKI TUGAS KECAMATAN (kecamatan_id)
    $sql_kecamatan = "SELECT k.id, k.nama_kecamatan 
                      FROM tb_operator_kecamatan ok
                      JOIN tb_kecamatan k ON ok.kecamatan_id = k.id
                      WHERE ok.operator_id = ? AND ok.kecamatan_id IS NOT NULL";

    $kecamatanRows = login_cached_select_all_one_param($koneksi, $sql_kecamatan, 'i', (int)$user['id'], 120);
    $kecamatan_ids = array();
    $kecamatan_names = array();

    foreach ($kecamatanRows as $row) {
        $kecamatan_ids[] = $row['id'];
        $kecamatan_names[] = $row['nama_kecamatan'];
    }

    if (count($kecamatan_ids) > 0) {
        $_SESSION['kecamatan_tugas'] = $kecamatan_ids;
        $_SESSION['kecamatan_nama'] = $kecamatan_names;
        $has_kecamatan = true;
        $has_assignment = true;
        error_log("Operator has KECAMATAN assignment: " . count($kecamatan_ids) . " kecamatan");
    }
    
    // 2. CEK APAKAH OPERATOR MEMILIKI TUGAS KUA (kua_id)
    $sql_kua = "SELECT k.id_kua, k.nama_kua, k.id_kecamatan
                FROM tb_operator_kecamatan ok
                JOIN tb_kua k ON ok.kua_id = k.id_kua
                WHERE ok.operator_id = ? AND ok.kua_id IS NOT NULL";

    $kuaRows = login_cached_select_all_one_param($koneksi, $sql_kua, 'i', (int)$user['id'], 120);
    $kua_ids = array();
    $kua_names = array();
    $kua_kecamatan_ids = array();

    foreach ($kuaRows as $row) {
        $kua_ids[] = $row['id_kua'];
        $kua_names[] = $row['nama_kua'];
        $kua_kecamatan_ids[] = $row['id_kecamatan'];
    }

    if (count($kua_ids) > 0) {
        $_SESSION['kua_tugas'] = $kua_ids;
        $_SESSION['kua_nama'] = $kua_names;
        $_SESSION['kua_kecamatan_ids'] = $kua_kecamatan_ids;
        $has_kua = true;
        $has_assignment = true;
        error_log("Operator has KUA assignment: " . count($kua_ids) . " KUA");
    }
    
    // 3. CEK APAKAH OPERATOR MEMILIKI TUGAS PUSKESMAS/AHLak (puskes_id)
    $sql_puskes = "SELECT p.id_puskes, p.nama_puskes, p.id_kecamatan
                   FROM tb_operator_kecamatan ok
                   JOIN tb_puskes p ON ok.puskes_id = p.id_puskes
                   WHERE ok.operator_id = ? AND ok.puskes_id IS NOT NULL";

    $puskesRows = login_cached_select_all_one_param($koneksi, $sql_puskes, 'i', (int)$user['id'], 120);
    $puskes_ids = array();
    $puskes_names = array();
    $puskes_kecamatan_ids = array();

    foreach ($puskesRows as $row) {
        $puskes_ids[] = $row['id_puskes'];
        $puskes_names[] = $row['nama_puskes'];
        $puskes_kecamatan_ids[] = $row['id_kecamatan'];
    }

    if (count($puskes_ids) > 0) {
        $_SESSION['puskes_tugas'] = $puskes_ids;
        $_SESSION['puskes_nama'] = $puskes_names;
        $_SESSION['puskes_kecamatan_ids'] = $puskes_kecamatan_ids;
        $has_puskes = true;
        $has_assignment = true;
        error_log("Operator has PUSKESMAS/AHLak assignment: " . count($puskes_ids) . " puskesmas");
    }
    
    // 4. CEK APAKAH OPERATOR MEMILIKI TUGAS RUMAH SAKIT (rs_id)
    $sql_rs = "SELECT rs.id_rs, rs.nama_rs
               FROM tb_operator_kecamatan ok
               JOIN tb_rumahsakit rs ON ok.rs_id = rs.id_rs
               WHERE ok.operator_id = ? AND ok.rs_id IS NOT NULL";

    $rsRows = login_cached_select_all_one_param($koneksi, $sql_rs, 'i', (int)$user['id'], 120);
    $rs_ids = array();
    $rs_names = array();

    foreach ($rsRows as $row) {
        $rs_ids[] = $row['id_rs'];
        $rs_names[] = $row['nama_rs'];
    }

    if (count($rs_ids) > 0) {
        $_SESSION['rs_tugas'] = $rs_ids;
        $_SESSION['rs_nama'] = $rs_names;
        $has_rs = true;
        $has_assignment = true;
        error_log("Operator has RUMAH SAKIT assignment: " . count($rs_ids) . " rumah sakit");
    }
    
    // 5. CEK JIKA OPERATOR SUDAH MEMILIKI id_kua atau id_puskes atau id_rs DI tb_users (legacy/backward compatibility)
    if (!$has_assignment) {
        if (!empty($user['id_kua'])) {
            $_SESSION['kua_tugas'] = array($user['id_kua']);
            $_SESSION['kua_nama'] = array($user['nama_kua'] ?? 'KUA ' . $user['id_kua']);
            $_SESSION['kua_kecamatan_ids'] = array($user['kua_kecamatan_id'] ?? null);
            $has_kua = true;
            $has_assignment = true;
            error_log("Operator has KUA assignment in tb_users (legacy): ID=" . $user['id_kua']);
        } elseif (!empty($user['id_puskes'])) {
            $_SESSION['puskes_tugas'] = array($user['id_puskes']);
            $_SESSION['puskes_nama'] = array($user['nama_puskes'] ?? 'Puskesmas ' . $user['id_puskes']);
            $_SESSION['puskes_kecamatan_ids'] = array($user['puskes_kecamatan_id'] ?? null);
            $has_puskes = true;
            $has_assignment = true;
            error_log("Operator has PUSKESMAS assignment in tb_users (legacy): ID=" . $user['id_puskes']);
        } elseif (!empty($user['id_rs'])) {
            $_SESSION['rs_tugas'] = array($user['id_rs']);
            $_SESSION['rs_nama'] = array($user['nama_rs'] ?? 'Rumah Sakit ' . $user['id_rs']);
            $has_rs = true;
            $has_assignment = true;
            error_log("Operator has RUMAH SAKIT assignment in tb_users (legacy): ID=" . $user['id_rs']);
        }
    }
    
    // 6. CEK JIKA OPERATOR TIDAK MEMILIKI TUGAS SAMA SEKALI
    if (!$has_assignment) {
        error_log("BLOCKING LOGIN: Operator has no assignments!");
        
        session_destroy();
        
        $_SESSION['login_error'] = 'Akun operator Anda belum ditugaskan ke kecamatan, KUA, Puskesmas, atau Rumah Sakit manapun. Silakan hubungi administrator untuk penugasan.';
        $_SESSION['error_title'] = 'Belum Ditugaskan';
        $_SESSION['error_type'] = 'operator_no_assignment';
        $_SESSION['login_username'] = $username;
        
        $suggestion = '
            <strong>Instruksi:</strong>
            <ul>
                <li>Hubungi administrator sistem</li>
                <li>Minta untuk ditugaskan ke kecamatan, KUA, Puskesmas, atau Rumah Sakit tertentu</li>
                <li>Setelah ditugaskan, Anda dapat login kembali</li>
            </ul>
        ';
        $_SESSION['suggestion'] = $suggestion;
        
        login_redirect_with_type($loginRedirect);
    }
    
    // 7. TENTUKAN JENIS OPERATOR DAN SET SESSION YANG SESUAI
    if ($has_puskes) {
        $_SESSION['operator_type'] = 'puskesmas';
        // Set id_puskes utama (ambil yang pertama jika banyak)
        $_SESSION['id_puskes'] = $puskes_ids[0] ?? $user['id_puskes'] ?? null;
        $_SESSION['nama_puskes'] = $puskes_names[0] ?? $user['nama_puskes'] ?? 'Puskesmas';
        $_SESSION['user_type'] = 'puskesmas';
        
        // Jika hanya satu puskesmas, set kecamatannya juga
        if (count($puskes_kecamatan_ids) === 1 && !empty($puskes_kecamatan_ids[0])) {
            $_SESSION['id_kecamatan'] = $puskes_kecamatan_ids[0];
            
            // Ambil nama kecamatan
            $sql_kecamatan = "SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?";
            $kecamatan = login_cached_select_row_one_param($koneksi, $sql_kecamatan, 'i', (int)$puskes_kecamatan_ids[0], 300);
            if ($kecamatan && isset($kecamatan['nama_kecamatan'])) {
                $_SESSION['nama_kecamatan'] = $kecamatan['nama_kecamatan'];
                error_log("Operator's puskesmas kecamatan: " . $kecamatan['nama_kecamatan']);
            }
        }
        
        error_log("Operator is PUSKESMAS/AHLak type: " . $_SESSION['nama_puskes']);
    } 
    elseif ($has_kua && !$has_kecamatan) {
        $_SESSION['operator_type'] = 'kua_only';
        $_SESSION['id_kua'] = $kua_ids[0] ?? $user['id_kua'] ?? null;
        $_SESSION['nama_kua'] = $kua_names[0] ?? $user['nama_kua'] ?? 'KUA';
        error_log("Operator is KUA-ONLY type: " . $_SESSION['nama_kua']);
        
        // Jika hanya satu KUA, set kecamatannya juga
        if (isset($kua_kecamatan_ids[0]) && !empty($kua_kecamatan_ids[0])) {
            $_SESSION['id_kecamatan'] = $kua_kecamatan_ids[0];
        }
    } 
    elseif ($has_kecamatan) {
        $_SESSION['operator_type'] = 'kecamatan';
        error_log("Operator is KECAMATAN type");
    }
    elseif ($has_rs) {
        // OPERATOR RUMAH SAKIT
        $_SESSION['operator_type'] = 'rumah_sakit';
        $_SESSION['id_rs'] = $rs_ids[0] ?? $user['id_rs'] ?? null;
        $_SESSION['nama_rs'] = $rs_names[0] ?? $user['nama_rs'] ?? 'Rumah Sakit';
        error_log("Operator is RUMAH SAKIT type: " . $_SESSION['nama_rs']);
    }
    
    // 8. LOG DETAIL ASSIGNMENT
    if ($has_kecamatan) {
        error_log("Kecamatan assignments: " . implode(', ', $kecamatan_names));
    }
    if ($has_kua) {
        error_log("KUA assignments: " . implode(', ', $kua_names));
    }
    if ($has_puskes) {
        error_log("Puskesmas assignments: " . implode(', ', $puskes_names));
    }
    if ($has_rs) {
        error_log("Rumah Sakit assignments: " . implode(', ', $rs_names));
    }
}

// Validasi keamanan portal khusus berdasarkan tipe login yang diminta.
$hasKuaAccess = !empty($_SESSION['id_kua']) || (!empty($_SESSION['kua_tugas']) && is_array($_SESSION['kua_tugas']));
$hasPuskesAccess = !empty($_SESSION['id_puskes']) || (!empty($_SESSION['puskes_tugas']) && is_array($_SESSION['puskes_tugas']));
$hasRsAccess = !empty($_SESSION['id_rs']) || (!empty($_SESSION['rs_tugas']) && is_array($_SESSION['rs_tugas']));

if ($loginType === 'kua' && !$hasKuaAccess) {
    login_throttle_register_failure();
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['login_error'] = 'Akun ini tidak memiliki akses ke portal KUA.';
    $_SESSION['error_title'] = 'Akses Ditolak';
    $_SESSION['error_type'] = 'general';
    $_SESSION['login_username'] = $username;
    login_redirect_with_type('login_kua.php');
}

if ($loginType === 'rs' && !$hasRsAccess) {
    login_throttle_register_failure();
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['login_error'] = 'Akun ini tidak memiliki akses ke portal Rumah Sakit.';
    $_SESSION['error_title'] = 'Akses Ditolak';
    $_SESSION['error_type'] = 'general';
    $_SESSION['login_username'] = $username;
    login_redirect_with_type('login_rs.php');
}

if ($loginType === 'puskesmas' && !$hasPuskesAccess) {
    login_throttle_register_failure();
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['login_error'] = 'Akun ini tidak memiliki akses sebagai petugas AHLAK/Puskesmas.';
    $_SESSION['error_title'] = 'Akses Ditolak';
    $_SESSION['error_type'] = 'general';
    $_SESSION['login_username'] = $username;
    login_redirect_with_type('login_ahlak.php');
}

// Hapus kegagalan hanya setelah kredensial dan akses portal benar-benar valid.
login_throttle_register_success($username);

$stmt->close();

// Debug final session data
error_log("Final session data:");
error_log("- ID User: " . ($_SESSION['id_user'] ?? 'NOT SET'));
error_log("- Name: " . ($_SESSION['nama'] ?? 'NOT SET'));
error_log("- Role: " . ($_SESSION['role'] ?? 'NOT SET'));
error_log("- User Type: " . ($_SESSION['user_type'] ?? 'NOT SET'));
error_log("- Operator Type: " . ($_SESSION['operator_type'] ?? 'NOT SET'));
error_log("- id_kua: " . ($_SESSION['id_kua'] ?? 'N/A'));
error_log("- id_puskes: " . ($_SESSION['id_puskes'] ?? 'N/A'));
error_log("- id_rs: " . ($_SESSION['id_rs'] ?? 'N/A'));
error_log("- Desa: " . ($_SESSION['nama_desa'] ?? 'N/A'));
error_log("- Kecamatan: " . ($_SESSION['nama_kecamatan'] ?? 'N/A'));
error_log("- Puskesmas Name: " . ($_SESSION['nama_puskes'] ?? 'N/A'));
error_log("- KUA Name: " . ($_SESSION['nama_kua'] ?? 'N/A'));
error_log("- Rumah Sakit Name: " . ($_SESSION['nama_rs'] ?? 'N/A'));

// Redirect berdasarkan role dan tipe user
$redirect_url = '';
switch ($_SESSION['role']) {
    case 'admin':
        $redirect_url = 'admin/dashboard_admin.php';
        break;
        
    case 'operator':
        // Tentukan redirect untuk operator berdasarkan tipe
        if (isset($_SESSION['operator_type'])) {
            switch ($_SESSION['operator_type']) {
                case 'puskesmas':
                    $redirect_url = 'user_ahlak/ahlak_dashboard_operator.php';
                    error_log("✅ Redirecting PUSKESMAS/AHLak operator to: " . $redirect_url);
                    break;
                    
                case 'kua_only':
                    $redirect_url = 'user_kua/kua_dashboard_operator.php';
                    error_log("Redirecting KUA-ONLY operator to: " . $redirect_url);
                    break;
                    
                case 'kecamatan':
                    $redirect_url = 'user/dashboard_operator.php';
                    error_log("Redirecting KECAMATAN operator to: " . $redirect_url);
                    break;
                    
                case 'rumah_sakit':
                    $redirect_url = 'user_rs/rs_dashboard_operator.php';
                    error_log("✅ Redirecting RUMAH SAKIT operator to: " . $redirect_url);
                    break;
                    
                default:
                    $redirect_url = 'user/dashboard_operator.php';
                    error_log("⚠️ Operator redirect not determined, using default: " . $redirect_url);
                    break;
            }
        } else {
            // Fallback berdasarkan session data
            if (isset($_SESSION['id_puskes']) && !empty($_SESSION['id_puskes'])) {
                $redirect_url = 'user_ahlak/ahlak_dashboard_operator.php';
                error_log("✅ Redirecting based on id_puskes to: " . $redirect_url);
            } elseif (isset($_SESSION['id_kua']) && !empty($_SESSION['id_kua'])) {
                $redirect_url = 'user_kua/kua_dashboard_operator.php';
                error_log("Redirecting based on id_kua to: " . $redirect_url);
            } elseif (isset($_SESSION['id_rs']) && !empty($_SESSION['id_rs'])) {
                $redirect_url = 'user_rs/rs_dashboard_operator.php';
                error_log("✅ Redirecting based on id_rs to: " . $redirect_url);
            } else {
                $redirect_url = 'user/dashboard_operator.php';
                error_log("⚠️ Operator redirect not determined, using default: " . $redirect_url);
            }
        }
        break;
        
    case 'petugas_desa':
        $redirect_url = 'user/dashboard_petugas_desa.php';
        error_log("Redirecting PETUGAS_DESA to: " . $redirect_url);
        break;
        
     case 'user':
        // User biasa - redirect berdasarkan tipe user
        if (isset($_SESSION['user_type'])) {
            switch ($_SESSION['user_type']) {
                case 'kua':
                    $redirect_url = 'user_kua/kua_dashboard_user.php';
                    error_log("Redirecting KUA user to: " . $redirect_url);
                    break;
                    
                case 'puskesmas':
                    $redirect_url = 'user_ahlak/ahlak_dashboard_user.php';
                    error_log("Redirecting PUSKESMAS user to: " . $redirect_url);
                    break;
                    
                case 'rumah_sakit':
                    $redirect_url = 'user_rs/rs_dashboard_user.php';
                    error_log("Redirecting RUMAH SAKIT user to: " . $redirect_url);
                    break;
                    
                case 'umum':
                default:
                    $redirect_url = 'user/dashboard_user.php';
                    error_log("Redirecting GENERAL user to: " . $redirect_url);
                    break;
            }
        } else {
            // Fallback: cek berdasarkan id_lokasi
            if (isset($_SESSION['id_kua'])) {
                $redirect_url = 'user_kua/kua_dashboard_user.php';
                error_log("Redirecting based on id_kua to: " . $redirect_url);
            } elseif (isset($_SESSION['id_puskes'])) {
                $redirect_url = 'user_ahlak/ahlak_dashboard_user.php';
                error_log("Redirecting based on id_puskes to: " . $redirect_url);
            } elseif (isset($_SESSION['id_rs'])) {
                $redirect_url = 'user_rs/rs_dashboard_user.php';
                error_log("Redirecting based on id_rs to: " . $redirect_url);
            } else {
                $redirect_url = 'user/dashboard_user.php';
                error_log("Redirecting to default user dashboard: " . $redirect_url);
            }
        }
        break;
        
    default:
        // Role tidak dikenali
        session_destroy();
        $_SESSION['login_error'] = 'Role tidak valid. Silakan hubungi administrator.';
        $_SESSION['error_title'] = 'Error Sistem';
        $_SESSION['error_type'] = 'general';
        error_log("Invalid role detected: " . $_SESSION['role']);
        login_redirect_with_type($loginRedirect);
}

error_log("Final redirect URL: " . $redirect_url);
error_log("==== END LOGIN PROCESS ====");

// Redirect
if (headers_sent()) {
    error_log("ERROR: Headers already sent! Cannot redirect.");
    echo "<script>window.location.href = '$redirect_url';</script>";
    exit;
} else {
    header('Location: ' . $redirect_url);
    exit;
}

// Fallback
session_destroy();
login_redirect_with_type($loginRedirect);
?>

