<?php
require_once '../error_handler.php';
// reset_password.php
// Halaman khusus admin untuk mereset password user

error_log('=== RESET_PASSWORD.PHP STARTING ===');

// Mulai session HANYA jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../app/config/koneksi.php';
require_once __DIR__ . '/../../app/middleware/admin_only.php';
require_once __DIR__ . '/../../app/security/password_reset.php';
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');

// Cek apakah config_email.php ada
$config_email_path = __DIR__ . '/../../app/config/config_email.php';
if (file_exists($config_email_path)) {
    require_once $config_email_path;
    error_log('config_email.php loaded successfully');
} else {
    error_log("ERROR: config_email.php not found at: $config_email_path");
    die("Configuration file not found!");
}

// Define LOGIN_URL constant jika belum didefinisikan
if (!defined('LOGIN_URL')) {
    define('LOGIN_URL', 'https://siprima.gorontalokab.go.id/login.php');
}

// Fungsi helper untuk escape output
function esc($string) {
    return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
}

// ==============================================
// FUNGSI UNTUK CEK KONFIGURASI
// ==============================================

/**
 * Cek apakah email terkonfigurasi dengan benar
 */
function isEmailConfigured() {
    if (!defined('SMTP_HOST') || !defined('SMTP_USERNAME') || !defined('SMTP_PASSWORD')) {
        error_log("Email config: Constants not defined");
        return false;
    }
    
    $configured = !empty(SMTP_HOST) && !empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD);
    error_log("Email config check: " . ($configured ? 'YES' : 'NO'));
    return $configured;
}

/**
 * Cek apakah Telegram terkonfigurasi dengan benar
 */
function isTelegramConfigured() {
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) {
        error_log("Telegram config: Constants not defined");
        return false;
    }
    
    $configured = !empty(TELEGRAM_BOT_TOKEN) && !empty(TELEGRAM_CHAT_ID);
    error_log("Telegram config check: " . ($configured ? 'YES' : 'NO'));
    return $configured;
}

// ==============================================
// INISIALISASI KONFIGURASI
// ==============================================

$email_configured = isEmailConfigured();
$telegram_configured = isTelegramConfigured();

error_log("Final check - Email configured: " . ($email_configured ? 'YES' : 'NO'));
error_log("Final check - Telegram configured: " . ($telegram_configured ? 'YES' : 'NO'));

// ==============================================
// FUNGSI EMAIL DAN TELEGRAM
// ==============================================

/**
 * Fungsi untuk mengirim email menggunakan PHPMailer
 */
function kirimEmail($to, $subject, $body, $altBody = '') {
    error_log('Reset email attempt; request_id=' . siprima_request_id());
    
    if (!isEmailConfigured()) {
        error_log("ERROR: Email configuration not complete!");
        return false;
    }
    
    try {
        // Cek ketersediaan PHPMailer
        $phpmailer_base = __DIR__ . '/../../vendor/PHPMailer/src/';
        
        if (!file_exists($phpmailer_base . 'PHPMailer.php')) {
            error_log("ERROR: PHPMailer library not found at: $phpmailer_base");
            return false;
        }
        
        // Load PHPMailer files
        require_once $phpmailer_base . 'Exception.php';
        require_once $phpmailer_base . 'PHPMailer.php';
        require_once $phpmailer_base . 'SMTP.php';
        
        // Create PHPMailer instance
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls';
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
        
        // Character encoding
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;
        
        // Debug settings
        $mail->SMTPDebug = 0;
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer Debug (Level $level): $str");
        };
        
        // Recipients
        $from_email = SMTP_USERNAME;
        $from_name = defined('SENDER_NAME') ? SENDER_NAME : 'SI PRIMA';
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to);
        
        // Add reply-to
        if (defined('ADMIN_EMAIL') && !empty(ADMIN_EMAIL)) {
            $admin_name = 'Admin SI PRIMA';
            $mail->addReplyTo(ADMIN_EMAIL, $admin_name);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Tambahkan footer ke body email
        $full_body = $body;
        if (defined('EMAIL_FOOTER')) {
            $full_body .= EMAIL_FOOTER;
        }
        
        $mail->Body    = $full_body;
        $mail->AltBody = $altBody ?: strip_tags($body);
        
        // Send email
        if ($mail->send()) {
            error_log('Reset email sent');
            return true;
        } else {
            error_log("ERROR: Email failed to send. Mailer Error: " . $mail->ErrorInfo);
            return false;
        }
        
    } catch (Exception $e) {
        error_log('Reset email failed; request_id=' . siprima_request_id());
        return false;
    }
}

/**
 * Fungsi untuk mengirim notifikasi Telegram
 */
function kirimNotifikasiTelegram($message) {
    error_log("=== KIRIM TELEGRAM: Attempting to send Telegram notification ===");
    
    if (!isTelegramConfigured()) {
        error_log("ERROR: Telegram configuration not complete!");
        return false;
    }
    
    $telegram_bot_token = TELEGRAM_BOT_TOKEN;
    $telegram_chat_id = TELEGRAM_CHAT_ID;
    
    if (empty($telegram_bot_token) || empty($telegram_chat_id)) {
        error_log("ERROR: Telegram configuration not complete");
        return false;
    }
    
    if (empty(trim($message))) {
        error_log("ERROR: Telegram message is empty!");
        return false;
    }
    
    // URL API Telegram
    $api_url = "https://api.telegram.org/bot" . $telegram_bot_token . "/sendMessage";
    
    // Bersihkan message
    $clean_message = trim($message);
    
    // Siapkan data
    $post_data = [
        'chat_id' => $telegram_chat_id,
        'text' => $clean_message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    
    try {
        // Gunakan file_get_contents sebagai alternatif curl
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($post_data),
                'timeout' => 10
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        
        $response = @file_get_contents($api_url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            error_log('Telegram delivery failed; request_id=' . siprima_request_id());
            return false;
        }
        
        $response_data = json_decode($response, true);
        
        if (isset($response_data['ok']) && $response_data['ok'] === true) {
            error_log("SUCCESS: Telegram notification sent successfully");
            return true;
        } else {
            $error = $response_data['description'] ?? 'Unknown error';
            error_log("Telegram API Error: " . $error);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Telegram exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Fungsi untuk membuat template email reset password
 */
function buatTemplateEmailResetPassword($data, $resetUrl) {
    $nama = htmlspecialchars($data['nama']);
    $email = htmlspecialchars($data['email']);
    $role = htmlspecialchars($data['role']);
    $reset_url = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $tanggal = date('d F Y H:i');
    $login_url = LOGIN_URL;
    $app_name = defined('APP_NAME') ? APP_NAME : 'SI PRIMA';
    $reset_by = isset($_SESSION['nama']) ? htmlspecialchars($_SESSION['nama']) : 'Administrator';
    
    // Terjemahan role
    $role_labels = [
        'admin' => 'Administrator',
        'operator' => 'Operator',
        'petugas_desa' => 'Petugas Desa',
        'user' => 'Pengguna'
    ];
    $role_display = $role_labels[$role] ?? $role;
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #2c3e50; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { padding: 20px; }
        .password-box { background: #f8f9fa; padding: 15px; border: 2px solid #3498db; border-radius: 6px; font-size: 18px; font-weight: bold; text-align: center; margin: 15px 0; }
        .button { display: inline-block; background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-top: 10px; }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Permintaan Reset Password</h1>
        </div>
        
        <div class="content">
            <p>Halo <strong>$nama</strong>,</p>
            <p>Administrator meminta reset password akun Anda di $app_name. Tautan berlaku 15 menit dan hanya dapat digunakan sekali.</p>
            
            <p><strong>Detail Reset:</strong></p>
            <ul>
                <li><strong>Nama:</strong> $nama</li>
                <li><strong>Email:</strong> $email</li>
                <li><strong>Role:</strong> $role_display</li>
                <li><strong>Direset oleh:</strong> $reset_by</li>
                <li><strong>Waktu Reset:</strong> $tanggal</li>
            </ul>
            
            <p><a href="$reset_url" class="button">Tetapkan Password Baru</a></p>
            
            <p>Silakan login melalui: <a href="$login_url" class="button">Login ke Sistem</a></p>
            <p>Salam hormat,<br>Tim $app_name</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Fungsi untuk membuat notifikasi Telegram reset password
 */
function buatNotifikasiTelegramReset($data, $resetUrl) {
    $nama = htmlspecialchars($data['nama']);
    $email = htmlspecialchars($data['email']);
    $role = htmlspecialchars($data['role']);
    $reset_by = isset($_SESSION['nama']) ? htmlspecialchars($_SESSION['nama']) : 'Admin';
    $tanggal = date('d/m/Y H:i:s');
    
    // Terjemahan role
    $role_labels = [
        'admin' => 'Administrator',
        'operator' => 'Operator',
        'petugas_desa' => 'Petugas Desa',
        'user' => 'Pengguna'
    ];
    $role_display = $role_labels[$role] ?? $role;
    
    $message = "🔐 <b>RESET PASSWORD AKUN</b>\n\n";
    $message .= "📅 <b>Waktu:</b> <code>$tanggal</code>\n";
    $message .= "👤 <b>Direset oleh:</b> <code>$reset_by</code>\n\n";
    $message .= "📋 <b>DATA USER</b>\n";
    $message .= "├ Nama: <code>$nama</code>\n";
    $message .= "├ Email: <code>$email</code>\n";
    $message .= "├ Role: $role_display\n";
    $reset_url = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $message .= '└ <a href="' . $reset_url . '">Tetapkan password baru</a>' . "\n\n";
    $message .= "🔗 <b>Link reset password (salin lengkap):</b>\n";
    $message .= '<code>' . $reset_url . '</code>' . "\n\n";
    $message .= "Tautan sekali pakai, berlaku 15 menit. Sampaikan hanya kepada pemilik akun.\n";
    
    return $message;
}

/**
 * Generate readable password
 */
function generateReadablePassword($length = 12) {
    return siprima_generate_password($length);
}

// ==============================================
// PROSES RESET PASSWORD
// ==============================================

$message = '';
$message_type = '';
$reset_results = [];
$fallback_request = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_fallback') {
    try {
        $temporary = siprima_reset_fallback($koneksi, (string)($_POST['request_id'] ?? ''), (int)$_SESSION['id_user']);
        $message = 'Password sementara: <strong>' . esc($temporary) . '</strong><br>Sampaikan kepada pemilik akun. Berlaku 24 jam dan wajib diganti pada login pertama. Tampilan ini tidak tersedia dalam riwayat.';
        $message_type = 'success';
        unset($temporary);
    } catch (DomainException $e) {
        $message = esc($e->getMessage()); $message_type = 'error';
    } catch (Throwable $e) {
        $message = 'Fallback belum dapat diproses. Periksa migrasi keamanan.'; $message_type = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_single') {
    $user_id = $_POST['user_id'] ?? '';
    
    if (empty($user_id)) {
        $message = "❌ User ID tidak valid";
        $message_type = 'error';
    } else {
        try {
            $recent = array_filter($_SESSION['reset_requests'] ?? [], static fn($t) => (int)$t > time() - 3600);
            if (count($recent) >= 20) throw new DomainException('Batas permintaan reset admin tercapai. Coba lagi nanti.');
            $request = siprima_reset_issue($koneksi, (int)$user_id, (int)$_SESSION['id_user']);
            $recent[] = time(); $_SESSION['reset_requests'] = array_values($recent);
            $user = $request['user'];
            
            // 4. Kirim email notifikasi
            $email_sent = false;
            if ($email_configured && !empty($user['email'])) {
                $email_body = buatTemplateEmailResetPassword($user, $request['url']);
                $email_subject = "Permintaan Reset Password - SI PRIMA";
                $email_sent = kirimEmail($user['email'], $email_subject, $email_body);
                
                if (!$email_sent) {
                    error_log('Reset email delivery failed');
                }
            }
            
            // 5. Kirim notifikasi Telegram
            $telegram_sent = false;
            if ($telegram_configured) {
                $telegram_message = buatNotifikasiTelegramReset($user, $request['url']);
                $telegram_sent = kirimNotifikasiTelegram($telegram_message);
                
                if (!$telegram_sent) {
                    error_log("Telegram notification failed for user: " . $user['nama']);
                }
            }
            
            siprima_reset_delivery($koneksi, $request['request_id'], (int)$_SESSION['id_user'], $email_sent, $telegram_sent);
            if (!$email_sent && !$telegram_sent) $fallback_request = $request['request_id'];
            error_log('SECURITY reset_requested actor_id=' . (int)$_SESSION['id_user'] . ' user_id=' . (int)$user_id);
            // Record delivery metadata only, never the bearer token or password.
            $reset_results[] = [
                'user_id' => $user_id,
                'nama' => $user['nama'],
                'email' => $user['email'],
                'role' => $user['role'],
                'email_sent' => $email_sent,
                'telegram_sent' => $telegram_sent,
                'success' => true
            ];
            
            // 7. Buat pesan sukses
            $message = 'Permintaan reset dibuat untuk <strong>' . esc($user['nama']) . '</strong><br>Password lama berubah setelah tautan digunakan atau fallback dijalankan.<br>';
            
            if ($email_configured) {
                $message .= "📧 Email: " . ($email_sent ? "✅ Terkirim" : "❌ Gagal") . "<br>";
            } else {
                $message .= "📧 Email: ⚠️ Tidak dikonfigurasi<br>";
            }
            
            if ($telegram_configured) {
                $message .= "📱 Telegram: " . ($telegram_sent ? "✅ Terkirim" : "❌ Gagal");
            } else {
                $message .= "📱 Telegram: ⚠️ Tidak dikonfigurasi";
            }
            
            $message_type = 'success';
            
        } catch (Throwable $e) {
            // Pesan exception reset tidak memuat token/password; dicatat agar penyebab bisa ditelusuri.
            error_log('SECURITY reset_request_failed request_id=' . siprima_request_id() . ' reason=' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
            $message = $e instanceof DomainException ? esc($e->getMessage()) : 'Permintaan reset gagal. Periksa migrasi keamanan dan APP_PUBLIC_URL.';
            $message_type = 'error';
        }
    }
}

// ==============================================
// FUNGSI UNTUK MENGAMBIL DATA STATISTIK
// ==============================================

/**
 * Mengambil statistik user dari database - TANPA deleted_at
 */
function getStatistics($koneksi) {
    $stats = [
        'total_users' => 0,
        'active_users' => 0,
        'inactive_users' => 0,
        'admin_count' => 0,
        'operator_count' => 0,
        'petugas_desa_count' => 0,
        'user_count' => 0
    ];
    
    try {
        // 1. Total users
        $res = $koneksi->query("SELECT COUNT(*) as total FROM tb_users");
        if($res) $stats['total_users'] = $res->fetch_assoc()['total'];
        
        // 2. Active users
        $res = $koneksi->query("SELECT COUNT(*) as total FROM tb_users WHERE is_active = 1");
        if($res) $stats['active_users'] = $res->fetch_assoc()['total'];
        
        // 3. Inactive users
        $res = $koneksi->query("SELECT COUNT(*) as total FROM tb_users WHERE is_active = 0");
        if($res) $stats['inactive_users'] = $res->fetch_assoc()['total'];
        
        // 4. Count by role
        $roles = ['admin', 'operator', 'petugas_desa', 'user'];
        foreach ($roles as $role) {
            $query = "SELECT COUNT(*) as total FROM tb_users WHERE role = ?";
            $stmt = $koneksi->prepare($query);
            
            if ($stmt) {
                $stmt->bind_param("s", $role);
                $stmt->execute();
                $result = $stmt->get_result();
                $stats[$role . '_count'] = $result ? $result->fetch_assoc()['total'] : 0;
                $stmt->close();
            } else {
                // Fallback ke query langsung jika prepare gagal
                $res = $koneksi->query("SELECT COUNT(*) as total FROM tb_users WHERE role = '$role'");
                if ($res) {
                    $stats[$role . '_count'] = $res->fetch_assoc()['total'];
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Error getting statistics: " . $e->getMessage());
    }
    
    return $stats;
}

// ==============================================
// INISIALISASI VARIABEL UNTUK FILTER DAN PAGINATION
// ==============================================

// Inisialisasi variabel filter
$search_nik = $_GET['search_nik'] ?? '';
$role_filter = $_GET['role_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? 'active';

// Inisialisasi variabel pagination
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Inisialisasi role labels
$role_labels = [
    'admin' => 'Administrator',
    'operator' => 'Operator',
    'petugas_desa' => 'Petugas Desa',
    'user' => 'Pengguna'
];

// ==============================================
// MENGAMBIL DATA USER DARI DATABASE - TANPA deleted_at
// ==============================================

try {
    // Ambil statistik
    $stats = getStatistics($koneksi);
    
    // Bangun query dengan filter
    $where_conditions = [];
    $params = [];
    $types = "";
    
    // Filter NIK
    if (!empty($search_nik)) {
        $where_conditions[] = "(nik LIKE ? OR nama LIKE ?)";
        $params[] = "%" . $search_nik . "%";
        $params[] = "%" . $search_nik . "%";
        $types .= "ss";
    }
    
    // Filter role
    if (!empty($role_filter) && isset($role_labels[$role_filter])) {
        $where_conditions[] = "role = ?";
        $params[] = $role_filter;
        $types .= "s";
    }
    
    // Filter status
    if ($status_filter === 'active') {
        $where_conditions[] = "is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $where_conditions[] = "is_active = 0";
    }
    // 'all' tidak perlu kondisi tambahan
    
    // Gabungkan kondisi WHERE (TANPA deleted_at)
    $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Query untuk total data
    $count_query = "SELECT COUNT(*) as total FROM tb_users $where_sql";
    
    if (!empty($params)) {
        $count_stmt = $koneksi->prepare($count_query);
        if ($count_stmt === false) {
            throw new Exception("Failed to prepare count query: " . $koneksi->error);
        }
        if ($types) {
            $count_stmt->bind_param($types, ...$params);
        }
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $total_data_row = $count_result->fetch_assoc();
        $total_data = $total_data_row ? $total_data_row['total'] : 0;
        $count_stmt->close();
    } else {
        $result = $koneksi->query($count_query);
        if ($result === false) {
            throw new Exception("Failed to execute count query: " . $koneksi->error);
        }
        $total_data_row = $result->fetch_assoc();
        $total_data = $total_data_row ? $total_data_row['total'] : 0;
    }
    
    // Hitung total halaman
    $total_pages = ceil($total_data / $limit);
    
    // Validasi halaman
    if ($page < 1) $page = 1;
    if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
    
    // Query untuk data dengan pagination
    $query = "SELECT id, nama, nik, email, role, is_active, created_at 
              FROM tb_users 
              $where_sql 
              ORDER BY created_at DESC 
              LIMIT ? OFFSET ?";
    
    // Tambahkan parameter untuk LIMIT dan OFFSET
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $koneksi->prepare($query);
    if (!$stmt) {
        throw new Exception("Gagal mempersiapkan query: " . $koneksi->error);
    }
    
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $stats = [
        'total_users' => 0,
        'active_users' => 0,
        'inactive_users' => 0,
        'admin_count' => 0,
        'operator_count' => 0,
        'petugas_count' => 0,
        'user_count' => 0
    ];
    $users = [];
    $total_data = 0;
    $total_pages = 1;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - SI PRIMA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/reset_password.css?v=<?= filemtime(__DIR__ . '/css/reset_password.css') ?>">
    <link rel="stylesheet" href="common.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <style>
        
    </style>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan</div>
        <nav>
            <ul>
                <li><a href="dashboard_admin.php">Dashboard</a></li>
                <li><a href="manajemen_operator.php">Manajemen Pengguna</a></li>
                <li  class="active"><a href="reset_password.php">Reset Password Pengguna</a></li>
                <li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li>
                <li><a href="laporan_admin.php">Laporan & Analisis</a></li><li><a href="kinerja_operator.php">Kinerja Operator</a></li>
                <li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li><a href="tanggapan_pemohon.php">Hasil Survei</a></li>
                <li><a href="whistleblowing.php">Whistleblowing</a></li>
                <li><a href="formulir.php">Formulir Kependudukan</a></li>
                <li><a href="manajemen_formulir_digital.php">Manajemen Formulir Digital</a></li><li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
                <li class="logout-item">
                    <button id="logoutBtn" class="sidebar-btn">Logout</button>
                </li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Reset Password Pengguna</div>
                <div style="color: #7f8c8d; font-size: 14px;">
                    <i class="fas fa-key"></i> Reset password individual pengguna
                </div>
            </div>
            <div class="user-info">
                <span style="color: #2c3e50;">Hai, <?= esc($_SESSION['nama'] ?? 'Admin') ?></span>
                <span class="role-badge"><?= esc($_SESSION['role'] ?? '') ?></span>
                <button class="logout-btn" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </header>

        <section class="content">
            <!-- Stats Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <h3>Total User</h3>
                    <div class="count"><?= number_format($stats['total_users']) ?></div>
                    <p><i class="fas fa-users"></i> Semua user</p>
                </div>
                <div class="stat-card">
                    <h3>Aktif</h3>
                    <div class="count"><?= number_format($stats['active_users']) ?></div>
                    <p><i class="fas fa-check-circle"></i> User aktif</p>
                </div>
                <div class="stat-card">
                    <h3>Nonaktif</h3>
                    <div class="count"><?= number_format($stats['inactive_users']) ?></div>
                    <p><i class="fas fa-times-circle"></i> User nonaktif</p>
                </div>
                <div class="stat-card">
                    <h3>Admin</h3>
                    <div class="count"><?= number_format($stats['admin_count']) ?></div>
                    <p><i class="fas fa-user-shield"></i> Administrator</p>
                </div>
            </div>
            
            <!-- Warning Banner -->
            <div class="warning-banner">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <h3>PERINGATAN: TINDAKAN INI BERBAHAYA!</h3>
                    <p>Reset password akan mengubah password user dan mengirim notifikasi via email & Telegram. Pastikan Anda memiliki alasan yang valid.</p>
                </div>
            </div>
            
            <!-- Konfigurasi Status -->
            <div class="config-status">
                <div class="config-item <?= $email_configured ? 'config-success' : 'config-warning' ?>">
                    <i class="fas fa-envelope"></i>
                    <span>Email: <?= $email_configured ? '✅ Terkonfigurasi' : '⚠️ Belum dikonfigurasi' ?></span>
                </div>
                <div class="config-item <?= $telegram_configured ? 'config-success' : 'config-warning' ?>">
                    <i class="fab fa-telegram"></i>
                    <span>Telegram: <?= $telegram_configured ? '✅ Terkonfigurasi' : '⚠️ Belum dikonfigurasi' ?></span>
                </div>
            </div>
            
            <!-- Message Box -->
            <?php if ($message): ?>
                <div class="message-box message-<?= $message_type ?>">
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <!-- Filter Section -->
            <?php if ($fallback_request !== ''): ?>
                <div class="message-box message-warning">
                    <p>Email dan Telegram tidak berhasil. Buat password sementara untuk disampaikan kepada pemilik akun.</p>
                    <form method="post"><?= csrf_input() ?>
                        <input type="hidden" name="action" value="reset_fallback">
                        <input type="hidden" name="request_id" value="<?= esc($fallback_request) ?>">
                        <button type="submit">Buat dan tampilkan password sementara</button>
                    </form>
                </div>
            <?php endif; ?>
            <div class="filter-section">
                <h3><i class="fas fa-filter"></i> Filter Pencarian</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label class="form-label">Cari NIK/Nama</label>
                        <input type="text" class="form-control" name="search_nik" 
                               value="<?= esc($search_nik) ?>" 
                               placeholder="NIK atau nama">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role_filter">
                            <option value="">Semua Role</option>
                            <?php foreach ($role_labels as $role_value => $role_label): ?>
                                <option value="<?= $role_value ?>" <?= $role_filter === $role_value ? 'selected' : '' ?>>
                                    <?= $role_label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status_filter">
                            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Aktif</option>
                            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Semua Status</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Terapkan Filter
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Table Section -->
            <div class="table-section">
                <h3><i class="fas fa-list"></i> Daftar Pengguna 
                    <?php if ($total_data > 0): ?>
                        <span style="font-size: 14px; color: #7f8c8d;">(<?= number_format($total_data) ?> data)</span>
                    <?php endif; ?>
                </h3>
                
                <?php if (empty($users)): ?>
                    <div style="text-align: center; padding: 50px 20px; color: #95a5a6;">
                        <i class="fas fa-users-slash fa-3x" style="margin-bottom: 15px; opacity: 0.5;"></i>
                        <h4 style="margin-bottom: 10px; color: #7f8c8d;">Tidak ada data pengguna</h4>
                        <p>Coba ubah filter pencarian atau tidak ada user yang sesuai.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nama</th>
                                    <th>NIK</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Tanggal Daftar</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $start_num = ($page - 1) * $limit + 1;
                                foreach ($users as $index => $user): 
                                ?>
                                <tr>
                                    <td><?= $start_num + $index ?></td>
                                    <td>
                                        <strong><?= esc($user['nama']) ?></strong>
                                        <?php if ($user['id'] == ($_SESSION['user_id'] ?? 0)): ?>
                                            <span class="badge badge-primary" style="margin-left: 5px;">Anda</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code style="background: #f8f9fa; padding: 2px 6px; border-radius: 4px;"><?= esc($user['nik']) ?></code></td>
                                    <td>
                                        <small><?= esc($user['email']) ?></small>
                                        <?php if (empty($user['email'])): ?>
                                            <span class="badge badge-warning" style="margin-left: 5px;">No Email</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $role_class = [
                                            'admin' => 'badge-danger',
                                            'operator' => 'badge-primary',
                                            'petugas_desa' => 'badge-success',
                                            'user' => 'badge-warning'
                                        ][$user['role']] ?? 'badge-secondary';
                                        ?>
                                        <span class="badge <?= $role_class ?>">
                                            <?= esc($role_labels[$user['role']] ?? $user['role']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge badge-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= date('d/m/Y', strtotime($user['created_at'])) ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($user['id'] != ($_SESSION['user_id'] ?? 0)): ?>
                                            <button class="btn-icon btn-reset" 
                                                    title="Reset Password"
                                                    onclick="confirmReset(<?= $user['id'] ?>, '<?= esc(addslashes($user['nama'])) ?>')">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <?php else: ?>
                                            <span class="btn-icon btn-secondary" 
                                                  title="Tidak dapat reset password sendiri"
                                                  style="cursor: not-allowed; opacity: 0.5;">
                                                <i class="fas fa-key"></i>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <!-- Previous Page -->
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">
                                        <i class="fas fa-chevron-left"></i>
                                    </span>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Page Numbers -->
                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $start_page + 4);
                            
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next Page -->
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">
                                        <i class="fas fa-chevron-right"></i>
                                    </span>
                                </li>
                            <?php endif; ?>
                        </div>
                        
                        <div class="pagination-info">
                            Halaman <?= $page ?> dari <?= $total_pages ?> • 
                            Menampilkan <?= min($limit, count($users)) ?> dari <?= number_format($total_data) ?> data
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- Confirmation Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <h3><i class="fas fa-exclamation-triangle"></i> Konfirmasi Reset Password</h3>
        <p>Anda yakin ingin reset password untuk:</p>
        <p id="userNameConfirm" style="font-weight: bold; padding: 10px; background: #f8f9fa; border-radius: 6px; margin: 10px 0;"></p>
        
        <div class="notif-info">
            <p><i class="fas fa-info-circle"></i> Notifikasi yang akan dikirim:</p>
            <ul style="margin-left: 20px;">
                <li><i class="fas fa-envelope"></i> Email: <?= $email_configured ? '✅ AKTIF' : '⚠️ TIDAK AKTIF' ?></li>
                <li><i class="fab fa-telegram"></i> Telegram: <?= $telegram_configured ? '✅ AKTIF' : '⚠️ TIDAK AKTIF' ?></li>
            </ul>
        </div>
        
        <p><strong>Aksi ini akan:</strong></p>
        <ul style="margin-left: 20px; margin-bottom: 20px;">
            <li>Generate password baru</li>
            <li>Mengirim email notifikasi ke user</li>
            <li>Mengirim notifikasi Telegram ke admin</li>
            <li><strong style="color: #e74c3c;">Tidak dapat dibatalkan!</strong></li>
        </ul>
        <form id="resetForm" method="POST">
<?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="reset_single">
            <input type="hidden" name="user_id" id="userIdInput">
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-key"></i> Ya, Reset Password
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Sidebar Toggle
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && 
                    !menuToggle.contains(event.target) && 
                    sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            }
        });
    }
});

// Reset Password Confirmation
function confirmReset(userId, userName) {
    document.getElementById('userNameConfirm').textContent = userName;
    document.getElementById('userIdInput').value = userId;
    
    document.getElementById('confirmModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('confirmModal').classList.remove('show');
    document.body.style.overflow = '';
}

// Close modal on outside click
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Form submission handler
document.getElementById('resetForm').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('.btn-danger');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
    submitBtn.disabled = true;
});
</script>
</body>
</html>
