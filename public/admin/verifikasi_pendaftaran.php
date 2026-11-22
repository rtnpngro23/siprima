<?php
require_once '../error_handler.php';
// verifikasi_pendaftaran.php

error_log('=== VERIFIKASI_PENDAFTARAN.PHP STARTING ===');

// Mulai session HANYA jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../app/config/koneksi.php';
require_once __DIR__ . '/../../app/middleware/admin_only.php';
require_once __DIR__ . '/../functions/document_token.php';

// Debug koneksi database
error_log("Koneksi database: " . ($koneksi ? "OK" : "FAILED"));
if (!$koneksi) {
    error_log("Database connection error: " . mysqli_connect_error());
    die("Koneksi database gagal. Silakan coba lagi nanti.");
}

// ==============================================
// KONFIGURASI EMAIL & TELEGRAM
// ==============================================

// Cek apakah config_email.php ada di multiple locations
$config_email_paths = [
    __DIR__ . '/../../app/config/config_email.php',
    __DIR__ . '/../../config_email.php',
    '../config_email.php',
    'config_email.php'
];

$config_email_loaded = false;
foreach ($config_email_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $config_email_loaded = true;
        error_log("config_email.php loaded from: $path");
        break;
    }
}

if (!$config_email_loaded) {
    error_log("WARNING: config_email.php not found in any location");
}

// Fungsi untuk cek konfigurasi email
function isEmailConfigured() {
    if (!defined('SMTP_HOST') || !defined('SMTP_USERNAME') || !defined('SMTP_PASSWORD')) {
        error_log("Email config: Constants not defined");
        return false;
    }
    
    $configured = !empty(SMTP_HOST) && !empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD);
    error_log("Email config check: " . ($configured ? 'YES' : 'NO'));
    return $configured;
}

// Fungsi untuk cek konfigurasi Telegram
function isTelegramConfigured() {
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) {
        error_log("Telegram config: Constants not defined");
        return false;
    }
    
    $configured = !empty(TELEGRAM_BOT_TOKEN) && !empty(TELEGRAM_CHAT_ID);
    error_log("Telegram config check: " . ($configured ? 'YES' : 'NO'));
    return $configured;
}

// Inisialisasi status konfigurasi
$email_configured = isEmailConfigured();
$telegram_configured = isTelegramConfigured();

// Log konfigurasi (sembunyikan informasi sensitif)
if ($email_configured) {
    $masked_password = preg_replace('/./', '*', SMTP_PASSWORD ?? '');
    error_log("SMTP Config - Host: " . SMTP_HOST . ", Port: " . (SMTP_PORT ?? '587'));
    error_log("SMTP Config - Username: " . SMTP_USERNAME);
    error_log("SMTP Config - Password length: " . strlen(SMTP_PASSWORD ?? ''));
}

if ($telegram_configured) {
    $masked_token = substr(TELEGRAM_BOT_TOKEN, 0, 5) . '...' . substr(TELEGRAM_BOT_TOKEN, -5);
    error_log("Telegram Config - Token: $masked_token");
    error_log("Telegram Config - Chat ID: " . TELEGRAM_CHAT_ID);
}

// Fungsi helper untuk escape output
function esc($string) {
    return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
}

// ==============================================
// FUNGSI EMAIL - DIPERBAIKI
// ==============================================

/**
 * Fungsi untuk mengirim email menggunakan PHPMailer - IMPROVED VERSION
 */
function kirimEmail($to, $subject, $body, $altBody = '') {
    error_log("=== KIRIM EMAIL: Attempting to send email to: $to ===");
    
    if (!isEmailConfigured()) {
        error_log("ERROR: Email configuration not complete!");
        return false;
    }
    
    try {
        // Cek ketersediaan PHPMailer
        $phpmailer_paths = [
            __DIR__ . '/../../vendor/autoload.php',
            __DIR__ . '/../../vendor/PHPMailer/src/PHPMailer.php',
            '../vendor/autoload.php',
            '../vendor/PHPMailer/src/PHPMailer.php'
        ];
        
        $phpmailer_loaded = false;
        
        foreach ($phpmailer_paths as $path) {
            if (file_exists($path)) {
                if (strpos($path, 'autoload.php') !== false) {
                    require_once $path;
                    error_log("PHPMailer loaded via Composer autoload: $path");
                } elseif (file_exists(dirname($path) . '/PHPMailer.php')) {
                    require_once dirname($path) . '/PHPMailer.php';
                    require_once dirname($path) . '/SMTP.php';
                    require_once dirname($path) . '/Exception.php';
                    error_log("PHPMailer loaded manually: $path");
                }
                $phpmailer_loaded = true;
                break;
            }
        }
        
        if (!$phpmailer_loaded) {
            error_log("ERROR: PHPMailer library not found!");
            return false;
        }
        
        // Create PHPMailer instance
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = defined('SMTP_ENCRYPTION') ? constant('SMTP_ENCRYPTION') : 'tls';
        $mail->Port       = defined('SMTP_PORT') ? constant('SMTP_PORT') : 587;
        
        // Character encoding
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;
        
        // Debug settings
        if (defined('EMAIL_DEBUG') && constant('EMAIL_DEBUG')) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer Debug (Level $level): $str");
            };
        } else {
            $mail->SMTPDebug = 0;
        }
        
        // Recipients
        $from_email = SMTP_USERNAME;
        $from_name = defined('SENDER_NAME') ? constant('SENDER_NAME') : 'SI PRIMA';
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to);
        
        // Add reply-to
        if (defined('ADMIN_EMAIL') && !empty(constant('ADMIN_EMAIL'))) {
            $admin_name = defined('ADMIN_NAME') ? constant('ADMIN_NAME') : 'Admin SI PRIMA';
            $mail->addReplyTo(constant('ADMIN_EMAIL'), $admin_name);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Tambahkan footer ke body email
        $full_body = $body;
        if (defined('EMAIL_FOOTER')) {
            $full_body .= constant('EMAIL_FOOTER');
        }
        
        $mail->Body    = $full_body;
        $mail->AltBody = $altBody ?: strip_tags($body);
        
        // Send email
        if ($mail->send()) {
            error_log("SUCCESS: Email sent to $to");
            
            // Log success
            if (defined('EMAIL_LOG_ENABLED') && constant('EMAIL_LOG_ENABLED')) {
                logEmailSuccess($to, $subject);
            }
            
            return true;
        } else {
            error_log("ERROR: Email failed to send. Mailer Error: " . $mail->ErrorInfo);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("EXCEPTION: Email could not be sent to $to. Error: " . $e->getMessage());
        return false;
    }
}

// ==============================================
// FUNGSI TELEGRAM - DIPERBAIKI
// ==============================================

/**
 * Fungsi untuk mengirim notifikasi Telegram - ROBUST VERSION
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
        error_log("ERROR: Telegram configuration incomplete - Token or Chat ID is empty");
        return false;
    }
    
    if (empty(trim($message))) {
        error_log("ERROR: Telegram message is empty!");
        return false;
    }
    
    // Bersihkan message
    $clean_message = trim($message);
    
    // Validasi format bot token (basic validation)
    if (!preg_match('/^\d+:[a-zA-Z0-9_-]+$/', $telegram_bot_token)) {
        error_log("ERROR: Invalid Telegram bot token format");
        return false;
    }
    
    // URL API Telegram
    $api_url = "https://api.telegram.org/bot" . $telegram_bot_token . "/sendMessage";
    
    // Siapkan data
    $post_data = [
        'chat_id' => $telegram_chat_id,
        'text' => $clean_message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    
    try {
        // Coba menggunakan curl terlebih dahulu
        if (function_exists('curl_version')) {
            return sendTelegramViaCurl($api_url, $post_data);
        } 
        // Fallback ke file_get_contents
        else {
            return sendTelegramViaFileGetContents($api_url, $post_data);
        }
        
    } catch (Exception $e) {
        error_log("Telegram exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Kirim Telegram menggunakan cURL
 */
function sendTelegramViaCurl($api_url, $post_data) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    if ($curl_error) {
        error_log("cURL Error: $curl_error");
        return false;
    }
    
    return processTelegramResponse($response, $http_code);
}

/**
 * Kirim Telegram menggunakan file_get_contents (fallback)
 */
function sendTelegramViaFileGetContents($api_url, $post_data) {
    $context_options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($post_data),
            'timeout' => 10
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    
    $context = stream_context_create($context_options);
    
    $response = @file_get_contents($api_url, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        error_log("file_get_contents Error: " . ($error['message'] ?? 'Unknown error'));
        return false;
    }
    
    return processTelegramResponse($response);
}

/**
 * Proses response dari Telegram API
 */
function processTelegramResponse($response, $http_code = null) {
    if ($http_code && $http_code !== 200) {
        error_log("HTTP Error: $http_code");
        return false;
    }
    
    $response_data = @json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg());
        error_log("Raw response: " . substr($response, 0, 200));
        return false;
    }
    
    if (isset($response_data['ok']) && $response_data['ok'] === true) {
        error_log("SUCCESS: Telegram notification sent successfully");
        
        // Log success
        if (defined('TELEGRAM_LOG_FILE') && TELEGRAM_LOG_FILE) {
            $log_message = date('Y-m-d H:i:s') . " | SUCCESS | Message sent\n";
            @file_put_contents(TELEGRAM_LOG_FILE, $log_message, FILE_APPEND | LOCK_EX);
        }
        
        return true;
    } else {
        $error = $response_data['description'] ?? 'Unknown error';
        error_log("Telegram API Error: $error");
        
        // Log error
        if (defined('TELEGRAM_LOG_FILE') && TELEGRAM_LOG_FILE) {
            $log_message = date('Y-m-d H:i:s') . " | ERROR | $error\n";
            @file_put_contents(TELEGRAM_LOG_FILE, $log_message, FILE_APPEND | LOCK_EX);
        }
        
        return false;
    }
}

/**
 * Log email success
 */
function logEmailSuccess($to, $subject) {
    if (defined('EMAIL_LOG_FILE') && EMAIL_LOG_FILE) {
        $log_dir = dirname(EMAIL_LOG_FILE);
        if (!file_exists($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        
        $log_message = date('Y-m-d H:i:s') . " | SUCCESS | To: $to | Subject: $subject\n";
        @file_put_contents(EMAIL_LOG_FILE, $log_message, FILE_APPEND | LOCK_EX);
    }
}

// ==============================================
// FUNGSI TEMPLATE EMAIL (TIDAK BERUBAH)
// ==============================================

/**
 * Fungsi untuk membuat template email verifikasi diterima
 */
function buatTemplateEmailDiterima($data, $password) {
    $nama = htmlspecialchars($data['nama']);
    $email = htmlspecialchars($data['email']);
    $jabatan = htmlspecialchars($data['jabatan']);
    $desa = htmlspecialchars($data['nama_desa']);
    $kecamatan = htmlspecialchars($data['nama_kecamatan']);
    $tanggal = date('d F Y H:i');
    $login_url = defined('LOGIN_URL') ? constant('LOGIN_URL') : 'https://siprima.gorontalokab.go.id/login_add.php';
    $app_name = defined('APP_NAME') ? constant('APP_NAME') : 'SI PRIMA';
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            line-height: 1.6; 
            color: #333; 
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header { 
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); 
            color: white; 
            padding: 30px 20px; 
            text-align: center; 
        }
        .header h1 { 
            margin: 0; 
            font-size: 24px; 
        }
        .content { 
            padding: 30px; 
        }
        .info-box { 
            background: #e8f4fd; 
            border-left: 4px solid #3498db; 
            padding: 20px; 
            margin: 20px 0; 
            border-radius: 0 8px 8px 0;
        }
        .warning-box { 
            background: #fff3cd; 
            border-left: 4px solid #ffc107; 
            padding: 20px; 
            margin: 20px 0; 
            border-radius: 0 8px 8px 0;
        }
        .credentials { 
            background: #f8f9fa; 
            padding: 20px; 
            border-radius: 8px; 
            border: 1px dashed #dee2e6; 
            margin: 20px 0; 
            text-align: center;
        }
        .password-box {
            background: white;
            padding: 15px;
            border-radius: 5px;
            border: 2px solid #3498db;
            display: inline-block;
            margin: 10px 0;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 2px;
            color: #2c3e50;
            font-family: 'Courier New', monospace;
        }
        .button {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin: 15px 0;
        }
        .telegram-notice {
            background: #0088cc;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin: 15px 0;
            text-align: center;
        }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Verifikasi Pendaftaran Diterima</h1>
            <p>$app_name - Sistem Informasi Pelayanan Terintegrasi Identitas Masyarakat</p>
        </div>
        
        <div class="content">
            <p>Halo <strong>$nama</strong>,</p>
            
            <p>Selamat! Pendaftaran Anda sebagai Petugas Desa telah <strong>diverifikasi dan diterima</strong>.</p>
            
            <div class="info-box">
                <h3 style="margin-top: 0; color: #2c3e50;">📋 Detail Pendaftaran</h3>
                <p><strong>👤 Nama:</strong> $nama</p>
                <p><strong>💼 Jabatan:</strong> $jabatan</p>
                <p><strong>📍 Lokasi:</strong> Desa $desa, Kecamatan $kecamatan</p>
                <p><strong>📅 Tanggal Verifikasi:</strong> $tanggal</p>
            </div>
            
            <div class="credentials">
                <h3 style="color: #2c3e50;">🔐 Informasi Login</h3>
                <p>Berikut adalah kredensial untuk mengakses sistem:</p>
                
                <p><strong>📧 Email:</strong> $email</p>
                <p><strong>🔑 Password:</strong></p>
                <div class="password-box">$password</div>
                
                <p>Silakan login melalui link berikut:</p>
                <a href="$login_url" class="button">🚀 Login ke Sistem</a>
            </div>
            
            <div class="warning-box">
                <h4 style="margin-top: 0; color: #856404;">⚠️ Penting!</h4>
                <p>1. Simpan informasi login ini dengan aman</p>
                <p>2. Segera ganti password setelah login pertama kali</p>
                <p>3. Password ini bersifat rahasia, jangan bagikan kepada siapapun</p>
            </div>
            
            <div class="telegram-notice">
                <p>📱 <strong>Notifikasi Telegram:</strong> Admin telah mendapatkan notifikasi melalui Telegram tentang verifikasi ini.</p>
            </div>
            
            <p>Anda sekarang dapat mengakses sistem $app_name untuk melayani permohonan administrasi kependudukan.</p>
            
            <p>Salam hormat,<br>
            <strong>Tim Administrasi $app_name - Dinas Dukcapil Kabupaten Gorontalo</strong></p>
        </div>
</body>
</html>
HTML;
}

/**
 * Fungsi untuk membuat template email verifikasi ditolak
 */
function buatTemplateEmailDitolak($data, $alasan) {
    $nama = htmlspecialchars($data['nama']);
    $alasan = nl2br(htmlspecialchars($alasan));
    $tanggal = date('d F Y H:i');
    $app_name = defined('APP_NAME') ? constant('APP_NAME') : 'SI PRIMA';
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            line-height: 1.6; 
            color: #333; 
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header { 
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); 
            color: white; 
            padding: 30px 20px; 
            text-align: center; 
        }
        .header h1 { 
            margin: 0; 
            font-size: 24px; 
        }
        .content { 
            padding: 30px; 
        }
        .reason-box { 
            background: #fdedec; 
            border-left: 4px solid #e74c3c; 
            padding: 20px; 
            margin: 20px 0; 
            border-radius: 0 8px 8px 0;
        }
        .telegram-notice {
            background: #0088cc;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin: 15px 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ Verifikasi Pendaftaran Ditolak</h1>
            <p>$app_name - Sistem Informasi Pelayanan Terintegrasi Identitas Masyarakat</p>
        </div>
        
        <div class="content">
            <p>Halo <strong>$nama</strong>,</p>
            
            <p>Kami informasikan bahwa pendaftaran Anda sebagai Petugas Desa <strong>tidak dapat kami terima</strong>.</p>
            
            <div class="reason-box">
                <h3 style="margin-top: 0; color: #c0392b;">📝 Alasan Penolakan</h3>
                <p>$alasan</p>
            </div>
            
            <div class="telegram-notice">
                <p>📱 <strong>Notifikasi Telegram:</strong> Admin telah mendapatkan notifikasi melalui Telegram tentang penolakan ini.</p>
            </div>
            
            <p>Anda dapat memperbaiki data pendaftaran dan mengajukan ulang jika memungkini.</p>
            
            <p>Terima kasih,<br>
            <strong>Tim Administrasi $app_name - Dinas Dukcapil Kabupaten Gorontalo</strong></p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Fungsi untuk membuat notifikasi Telegram
 */
function buatNotifikasiTelegram($data, $action, $password = null, $alasan = null) {
    error_log("=== BUILDING TELEGRAM MESSAGE ===");
    error_log("Action: $action");
    
    // Validasi data
    if (empty($data) || !is_array($data)) {
        error_log("ERROR: Data is empty or not array!");
        return "⚠️ ERROR: Data pendaftaran kosong";
    }
    
    // Ambil data dengan default values
    $nama = htmlspecialchars($data['nama'] ?? 'Tidak diketahui');
    $nik = htmlspecialchars($data['nik'] ?? 'Tidak diketahui');
    $email = htmlspecialchars($data['email'] ?? 'Tidak diketahui');
    $no_hp = htmlspecialchars($data['no_hp'] ?? 'Tidak ada');
    $desa = htmlspecialchars($data['nama_desa'] ?? 'Tidak diketahui');
    $kecamatan = htmlspecialchars($data['nama_kecamatan'] ?? 'Tidak diketahui');
    $jabatan = htmlspecialchars($data['jabatan'] ?? '-');
    $tanggal = date('d/m/Y H:i');
    $admin_nama = isset($_SESSION['nama']) ? htmlspecialchars($_SESSION['nama']) : 'Admin';
    
    error_log("Processed variables:");
    error_log("- Nama: $nama");
    error_log('Registration notification request: ' . siprima_request_id());
    
    $emoji = $action === 'terima' ? '✅' : '❌';
    $status = $action === 'terima' ? 'DITERIMA' : 'DITOLAK';
    
    if ($action === 'terima') {
        $message = "<b>📋 NOTIFIKASI VERIFIKASI PENDAFTARAN $emoji</b>\n\n";
        $message .= "<b>Status:</b> <code>$status</code>\n";
        $message .= "<b>Waktu:</b> $tanggal\n";
        $message .= "<b>Diverifikasi oleh:</b> $admin_nama\n";
        $message .= "────────────\n";
        $message .= "<b>📝 DATA PENDAFTAR</b>\n";
        $message .= "├ Nama: <code>$nama</code>\n";
        $message .= "├ NIK: <code>$nik</code>\n";
        $message .= "├ Email: <code>$email</code>\n";
        $message .= "├ No. HP: <code>$no_hp</code>\n";
        $message .= "├ Lokasi: $desa, $kecamatan\n";
        $message .= "└ Jabatan: $jabatan\n";
        $message .= "────────────\n";
        
        if ($password) {
            $message .= "<b>🔐 INFORMASI AKUN BARU</b>\n";
            $message .= "├ Email: <code>$email</code>\n";
            $message .= "└ Password: <code>$password</code>\n";
            $message .= "────────────\n";
        }
        
        $message .= "📧 Email notifikasi telah dikirim ke pendaftar.";
        
    } else {
        $alasan_text = $alasan ? htmlspecialchars($alasan) : 'Tidak disebutkan';
        
        $message = "<b>📋 NOTIFIKASI VERIFIKASI PENDAFTARAN $emoji</b>\n\n";
        $message .= "<b>Status:</b> <code>$status</code>\n";
        $message .= "<b>Waktu:</b> $tanggal\n";
        $message .= "<b>Diverifikasi oleh:</b> $admin_nama\n";
        $message .= "────────────\n";
        $message .= "<b>📝 DATA PENDAFTAR</b>\n";
        $message .= "├ Nama: <code>$nama</code>\n";
        $message .= "├ NIK: <code>$nik</code>\n";
        $message .= "├ Email: <code>$email</code>\n";
        $message .= "├ No. HP: <code>$no_hp</code>\n";
        $message .= "├ Lokasi: $desa, $kecamatan\n";
        $message .= "└ Jabatan: $jabatan\n";
        $message .= "────────────\n";
        $message .= "<b>📝 ALASAN PENOLAKAN</b>\n";
        $message .= "└ $alasan_text\n";
        $message .= "────────────\n";
        $message .= "📧 Email pemberitahuan telah dikirim ke pendaftar.";
    }
    
    error_log("Telegram message built successfully");
    error_log("Message length: " . strlen($message));
    
    return $message;
}

/**
 * Fungsi untuk membuat notifikasi Telegram sederhana (backup)
 */
function buatNotifikasiTelegramSederhana($data, $action, $password = null, $alasan = null) {
    error_log("=== BUILDING SIMPLE TELEGRAM MESSAGE ===");
    
    // Validasi data
    if (empty($data) || !is_array($data)) {
        return "⚠️ ERROR: Data pendaftaran kosong";
    }
    
    $nama = htmlspecialchars($data['nama'] ?? 'Tidak diketahui');
    $email = htmlspecialchars($data['email'] ?? 'Tidak diketahui');
    $desa = htmlspecialchars($data['nama_desa'] ?? 'Tidak diketahui');
    $kecamatan = htmlspecialchars($data['nama_kecamatan'] ?? 'Tidak diketahui');
    $tanggal = date('d/m/Y H:i');
    
    if ($action === 'terima') {
        $message = "✅ <b>VERIFIKASI DITERIMA</b>\n\n" .
                   "<b>Nama:</b> $nama\n" .
                   "<b>Email:</b> $email\n" .
                   "<b>Lokasi:</b> $desa, $kecamatan\n";
        
        if ($password) {
            $message .= "<b>Password:</b> <code>$password</code>\n";
        }
        
        $message .= "<b>Waktu:</b> $tanggal\n\n" .
                   "Email telah dikirim ke pendaftar.";
    } else {
        $alasan_text = $alasan ? htmlspecialchars($alasan) : 'Tidak disebutkan';
        
        $message = "❌ <b>VERIFIKASI DITOLAK</b>\n\n" .
                   "<b>Nama:</b> $nama\n" .
                   "<b>Email:</b> $email\n" .
                   "<b>Lokasi:</b> $desa, $kecamatan\n" .
                   "<b>Alasan:</b> $alasan_text\n" .
                   "<b>Waktu:</b> $tanggal\n\n" .
                   "Email telah dikirim ke pendaftar.";
    }
    
    error_log("Simple Telegram message built");
    return $message;
}

/**
 * Generate readable password
 */
function generateReadablePassword($length = 10) {
    return siprima_generate_password($length);
}

// ==============================================
// PROSES VERIFIKASI
// ==============================================

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pendaftaran_id = $_POST['pendaftaran_id'] ?? '';
    $alasan_penolakan = $_POST['alasan_penolakan'] ?? '';
    
    if (empty($pendaftaran_id) || !in_array($action, ['terima', 'tolak'])) {
        $message = 'Aksi tidak valid';
        $message_type = 'error';
    } else {
        // Ambil data pendaftaran
        $stmt = $koneksi->prepare("SELECT * FROM tb_pendaftaran_petugas WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare SELECT failed: " . $koneksi->error);
            $message = 'Gagal mempersiapkan query: ' . $koneksi->error;
            $message_type = 'error';
        } else {
            $stmt->bind_param("i", $pendaftaran_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $pendaftaran = $result->fetch_assoc();
            $stmt->close();
            
            if (!$pendaftaran) {
                $message = 'Data pendaftaran tidak ditemukan';
                $message_type = 'error';
            } elseif ($pendaftaran['status'] !== 'pending') {
                $message = 'Pendaftaran ini sudah diproses sebelumnya';
                $message_type = 'warning';
            } else {
                if ($action === 'terima') {
                    // Proses penerimaan
                    $koneksi->begin_transaction();
                    
                    try {
                        // 1. Update status pendaftaran
                        $stmt = $koneksi->prepare("UPDATE tb_pendaftaran_petugas SET status = 'diverifikasi', updated_at = NOW() WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare update failed: " . $koneksi->error);
                        }
                        $stmt->bind_param("i", $pendaftaran_id);
                        $stmt->execute();
                        $stmt->close();
                        
                        // 2. Ambil id_kecamatan dan id_desa
                        $id_kecamatan = null;
                        $id_desa = null;
                        
                        // Ambil id_kecamatan berdasarkan nama_kecamatan
                        if (!empty($pendaftaran['nama_kecamatan'])) {
                            $stmt_kec = $koneksi->prepare("SELECT id FROM tb_kecamatan WHERE nama_kecamatan = ? LIMIT 1");
                            if ($stmt_kec) {
                                $stmt_kec->bind_param("s", $pendaftaran['nama_kecamatan']);
                                $stmt_kec->execute();
                                $result_kec = $stmt_kec->get_result();
                                if ($row_kec = $result_kec->fetch_assoc()) {
                                    $id_kecamatan = $row_kec['id'];
                                    error_log("Found kecamatan_id: $id_kecamatan for " . $pendaftaran['nama_kecamatan']);
                                } else {
                                    error_log("Kecamatan not found: " . $pendaftaran['nama_kecamatan']);
                                }
                                $stmt_kec->close();
                            }
                        }
                        
                        // Ambil id_desa berdasarkan nama_desa dan kecamatan_id
                        if (!empty($pendaftaran['nama_desa']) && $id_kecamatan) {
                            $stmt_desa = $koneksi->prepare("SELECT id FROM tb_desa WHERE nama_desa = ? AND kecamatan_id = ? LIMIT 1");
                            if ($stmt_desa) {
                                $stmt_desa->bind_param("si", $pendaftaran['nama_desa'], $id_kecamatan);
                                $stmt_desa->execute();
                                $result_desa = $stmt_desa->get_result();
                                if ($row_desa = $result_desa->fetch_assoc()) {
                                    $id_desa = $row_desa['id'];
                                    error_log("Found desa_id: $id_desa for " . $pendaftaran['nama_desa']);
                                } else {
                                    error_log("Desa not found: " . $pendaftaran['nama_desa'] . " in kecamatan_id: $id_kecamatan");
                                }
                                $stmt_desa->close();
                            }
                        }
                        
                        // 3. Buat user baru di tb_users
                        $password = generateReadablePassword(10);
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $role = 'petugas_desa';
                        
                        // Cek duplikasi user
                        $check_stmt = $koneksi->prepare("SELECT id FROM tb_users WHERE email = ? OR nik = ?");
                        if (!$check_stmt) {
                            throw new Exception("Prepare check failed: " . $koneksi->error);
                        }
                        $check_stmt->bind_param("ss", $pendaftaran['email'], $pendaftaran['nik']);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows > 0) {
                            throw new Exception("Email atau NIK sudah terdaftar sebagai user");
                        }
                        $check_stmt->close();
                        
                        // Insert user ke tb_users
                        $user_stmt = $koneksi->prepare("INSERT INTO tb_users 
                            (nama, nik, email, password, role, alamat, no_hp, id_kecamatan, id_desa, is_active, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
                        
                        if (!$user_stmt) {
                            throw new Exception("Prepare insert user failed: " . $koneksi->error);
                        }
                        
                        // Bind parameter
                        $user_stmt->bind_param("sssssssii",
                            $pendaftaran['nama'],
                            $pendaftaran['nik'],
                            $pendaftaran['email'],
                            $hashed_password,
                            $role,
                            $pendaftaran['alamat'],
                            $pendaftaran['no_hp'],
                            $id_kecamatan,
                            $id_desa
                        );
                        
                        $user_stmt->execute();
                        $user_id = $user_stmt->insert_id;
                        $user_stmt->close();
                        
                        error_log("User created with ID: $user_id");
                        
                        // 4. Kirim email
                        $email_sent = false;
                        if ($email_configured && !empty($pendaftaran['email'])) {
                            $email_body = buatTemplateEmailDiterima($pendaftaran, $password);
                            $email_subject = "Verifikasi Pendaftaran Diterima - SI PRIMA";
                            $email_sent = kirimEmail($pendaftaran['email'], $email_subject, $email_body);
                            
                            if (!$email_sent) {
                                error_log("Warning: Email to {$pendaftaran['email']} failed, but user created");
                            }
                        } else {
                            error_log("Email not configured or no email address");
                        }
                        
                        // 5. Kirim notifikasi Telegram
                        $telegram_sent = false;
                        if ($telegram_configured) {
                            error_log("Attempting to send Telegram notification...");
                            
                            // Buat pesan Telegram
                            $telegram_message = buatNotifikasiTelegram($pendaftaran, 'terima', $password);
                            
                            // Kirim pesan
                            $telegram_sent = kirimNotifikasiTelegram($telegram_message);
                            
                            if (!$telegram_sent) {
                                error_log("Primary Telegram failed, trying simple version...");
                                $telegram_message_simple = buatNotifikasiTelegramSederhana($pendaftaran, 'terima', $password);
                                $telegram_sent = kirimNotifikasiTelegram($telegram_message_simple);
                            }
                        }
                        
                        $koneksi->commit();
                        
                        // Pesan untuk admin
                        $message = "✅ Pendaftaran berhasil diverifikasi dan akun petugas telah dibuat.<br>";
                        $message .= "🔑 Password untuk pendaftar: <strong>$password</strong><br>";
                        $message .= "👤 User ID yang dibuat: <strong>$user_id</strong><br>";
                        
                        if ($id_kecamatan) {
                            $message .= "📍 id_kecamatan: <strong>$id_kecamatan</strong><br>";
                        }
                        
                        if ($id_desa) {
                            $message .= "🏘️ id_desa: <strong>$id_desa</strong><br>";
                        }
                        
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
                        
                    } catch (Exception $e) {
                        $koneksi->rollback();
                        error_log("Transaction error: " . $e->getMessage());
                        $message = "❌ Terjadi kesalahan: " . $e->getMessage();
                        $message_type = 'error';
                    }
                    
                } elseif ($action === 'tolak') {
                    // Proses penolakan
                    if (empty($alasan_penolakan)) {
                        $message = 'Alasan penolakan harus diisi';
                        $message_type = 'error';
                    } else {
                        $sql = "UPDATE tb_pendaftaran_petugas 
                            SET status = 'ditolak', 
                                alasan = ?, 
                                updated_at = NOW() 
                            WHERE id = ?";
                        
                        error_log("Executing SQL for rejection: $sql");
                        
                        $stmt = $koneksi->prepare($sql);
                        
                        if (!$stmt) {
                            error_log("Prepare UPDATE failed: " . $koneksi->error);
                            $message = 'Gagal mempersiapkan query penolakan: ' . htmlspecialchars($koneksi->error);
                            $message_type = 'error';
                        } else {
                            $bind_result = $stmt->bind_param("si", $alasan_penolakan, $pendaftaran_id);
                            
                            if ($bind_result === false) {
                                error_log("Bind param failed: " . $stmt->error);
                                $message = 'Gagal mengikat parameter: ' . htmlspecialchars($stmt->error);
                                $message_type = 'error';
                            } else {
                                $execute_result = $stmt->execute();
                                
                                if ($execute_result) {
                                    // Kirim email
                                    $email_sent = false;
                                    if ($email_configured && !empty($pendaftaran['email'])) {
                                        $email_body = buatTemplateEmailDitolak($pendaftaran, $alasan_penolakan);
                                        $email_subject = "Verifikasi Pendaftaran Ditolak - SI PRIMA";
                                        $email_sent = kirimEmail($pendaftaran['email'], $email_subject, $email_body);
                                    }
                                    
                                    // Kirim notifikasi Telegram
                                    $telegram_sent = false;
                                    if ($telegram_configured) {
                                        error_log("Attempting to send Telegram rejection notification...");
                                        
                                        // Buat pesan Telegram
                                        $telegram_message = buatNotifikasiTelegram($pendaftaran, 'tolak', null, $alasan_penolakan);
                                        
                                        // Kirim pesan
                                        $telegram_sent = kirimNotifikasiTelegram($telegram_message);
                                        
                                        if (!$telegram_sent) {
                                            error_log("Primary Telegram failed, trying simple version...");
                                            $telegram_message_simple = buatNotifikasiTelegramSederhana($pendaftaran, 'tolak', null, $alasan_penolakan);
                                            $telegram_sent = kirimNotifikasiTelegram($telegram_message_simple);
                                        }
                                    }
                                    
                                    $message = "✅ Pendaftaran berhasil ditolak.<br>";
                                    
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
                                } else {
                                    error_log("Execute failed: " . $stmt->error);
                                    $message = '❌ Gagal menolak pendaftaran: ' . htmlspecialchars($stmt->error);
                                    $message_type = 'error';
                                }
                            }
                            
                            $stmt->close();
                        }
                    }
                }
            }
        }
    }
    
    // Redirect
    $redirect_url = "verifikasi_pendaftaran.php";
    
    if (isset($_GET['id'])) {
        $redirect_url .= "?id=" . $_GET['id'];
    } elseif (isset($_GET['status'])) {
        $redirect_url .= "?status=" . $_GET['status'];
    } else {
        $redirect_url .= "?status=pending";
    }
    
    if ($message) {
        $redirect_url .= (strpos($redirect_url, '?') === false ? '?' : '&') . 
                        "message=" . urlencode($message) . 
                        "&type=" . $message_type;
    }
    
    header("Location: " . $redirect_url);
    exit();
}

// ==============================================
// TAMPILKAN HALAMAN
// ==============================================

// Tangani pesan dari URL
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $message_type = $_GET['type'] ?? 'info';
}

// Ambil detail pendaftaran jika ada parameter ID
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    $stmt = $koneksi->prepare("SELECT * FROM tb_pendaftaran_petugas WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare SELECT detail failed: " . $koneksi->error);
        $message = 'Gagal mengambil detail: ' . htmlspecialchars($koneksi->error);
        $message_type = 'error';
    } else {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pendaftaran_detail = $result->fetch_assoc();
        $stmt->close();
        
        if (!$pendaftaran_detail) {
            $message = 'Data pendaftaran tidak ditemukan';
            $message_type = 'error';
        }
    }
}

// Ambil semua pendaftaran untuk tabel (pagination + pencarian)
$status_filter = $_GET['status'] ?? 'pending';
$allowed_statuses = ['pending', 'diverifikasi', 'ditolak', 'all'];
$status_filter = in_array($status_filter, $allowed_statuses, true) ? $status_filter : 'pending';

$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search_keyword = trim($_GET['search'] ?? '');
$search_query_suffix = $search_keyword !== '' ? '&search=' . urlencode($search_keyword) : '';

$where_conditions = [];
if ($status_filter !== 'all') {
    $status_safe = $koneksi->real_escape_string($status_filter);
    $where_conditions[] = "p.status = '{$status_safe}'";
}
if ($search_keyword !== '') {
    $search_safe = $koneksi->real_escape_string($search_keyword);
    $where_conditions[] = "(
        p.nama LIKE '%{$search_safe}%'
        OR p.nik LIKE '%{$search_safe}%'
        OR p.email LIKE '%{$search_safe}%'
        OR p.no_hp LIKE '%{$search_safe}%'
        OR p.nama_desa LIKE '%{$search_safe}%'
        OR p.nama_kecamatan LIKE '%{$search_safe}%'
        OR p.jabatan LIKE '%{$search_safe}%'
    )";
}
$where_sql = !empty($where_conditions) ? ' WHERE ' . implode(' AND ', $where_conditions) : '';

$count_query = "SELECT COUNT(*) as total FROM tb_pendaftaran_petugas p" . $where_sql;
$count_result = $koneksi->query($count_query);
$total_data = $count_result ? (int)($count_result->fetch_assoc()['total'] ?? 0) : 0;
$total_pages = max(1, (int)ceil($total_data / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $limit;

$query = "SELECT 
    p.*,
    CASE 
        WHEN p.status = 'pending' THEN 'Menunggu Verifikasi'
        WHEN p.status = 'diverifikasi' THEN 'Diterima'
        WHEN p.status = 'ditolak' THEN 'Ditolak'
        ELSE p.status
    END as status_text,
    CASE 
        WHEN p.status = 'pending' THEN 'warning'
        WHEN p.status = 'diverifikasi' THEN 'success'
        WHEN p.status = 'ditolak' THEN 'danger'
        ELSE 'secondary'
    END as status_class
FROM tb_pendaftaran_petugas p
{$where_sql}
ORDER BY p.created_at DESC
LIMIT {$limit} OFFSET {$offset}";

$pendaftaran_result = $koneksi->query($query);
if (!$pendaftaran_result) {
    error_log("Query failed: " . $koneksi->error);
    $pendaftaran_result = false;
}

// Hitung statistik
$stats_query = $koneksi->query("
    SELECT 
        status,
        COUNT(*) as jumlah
    FROM tb_pendaftaran_petugas
    GROUP BY status
");
$stats = [
    'pending' => 0,
    'diverifikasi' => 0,
    'ditolak' => 0,
    'total' => 0
];

if ($stats_query) {
    while ($stat = $stats_query->fetch_assoc()) {
        $stats[$stat['status']] = $stat['jumlah'];
        $stats['total'] += $stat['jumlah'];
    }
}

// Dokumen pendaftaran privat hanya disajikan melalui URL token berbatas waktu.
function getDokumenUrl($path) {
    if (empty($path)) {
        return null;
    }

    return siprima_document_token_url(__DIR__ . '/..', trim((string)$path));
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Pendaftaran Petugas Desa - SI PRIMA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/verifikasi_pendaftaran.css">
    <style>
        /* Status konfigurasi di header */
        .config-status {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            font-size: 14px;
        }
        .config-item {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 4px;
        }
        .config-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .config-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .control-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin: 18px 0;
            flex-wrap: wrap;
        }
        .search-filter {
            display: flex;
            gap: 10px;
            align-items: center;
            width: 100%;
            max-width: 520px;
        }
        .search-box {
            position: relative;
            width: 100%;
        }
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            font-size: 14px;
        }
        #searchInput {
            width: 100%;
            padding: 10px 14px 10px 36px;
            border: 1px solid #dcdfe6;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        #searchInput:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15);
        }
        .table-info {
            color: #6c757d;
            font-size: 14px;
        }
        .pagination {
            display: flex;
            list-style: none;
            gap: 6px;
            padding: 0;
            margin: 18px 0 8px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .page-item {
            list-style: none;
        }
        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 12px;
            border: 1px solid #dcdfe6;
            border-radius: 8px;
            color: #2c3e50;
            text-decoration: none;
            background: #fff;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .page-item.active .page-link {
            background: #3498db;
            border-color: #3498db;
            color: #fff;
            font-weight: 600;
        }
        .page-item.disabled .page-link {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .page-link:hover {
            border-color: #3498db;
            color: #3498db;
        }
        .pagination-info {
            text-align: center;
            color: #7f8c8d;
            font-size: 13px;
            margin-bottom: 8px;
        }
    </style>
    <link rel="stylesheet" href="common.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
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
                <li><a href="reset_password.php">Reset Password User</a></li>
                <li class="active"><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li>
                <li><a href="laporan_admin.php">Laporan & Analisis</a></li><li><a href="kinerja_operator.php">Kinerja Operator</a></li>
                <li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li><a href="tanggapan_pemohon.php">Hasil Survei</a></li>
                <li><a href="whistleblowing.php">Whistleblowing</a></li>
                <li><a href="formulir.php">Formulir Kependudukan</a></li>
                <li><a href="manajemen_formulir_digital.php">Manajemen Formulir Digital</a></li><li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
                <li class="logout-item">
                    <a href="../logout.php">Logout</a>
                </li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Verifikasi Pendaftaran Petugas Desa</div>
                <div style="color: #7f8c8d; font-size: 14px;">
                    <i class="fas fa-user-check"></i> Verifikasi dan kelola pendaftaran petugas desa
                </div>
                <!-- Status Konfigurasi -->
                <div class="config-status">
                    <div class="config-item <?= $email_configured ? 'config-success' : 'config-warning' ?>">
                        <i class="fas fa-envelope"></i>
                        <span>Email: <?= $email_configured ? 'Aktif' : 'Belum Dikonfigurasi' ?></span>
                    </div>
                    <div class="config-item <?= $telegram_configured ? 'config-success' : 'config-warning' ?>">
                        <i class="fab fa-telegram"></i>
                        <span>Telegram: <?= $telegram_configured ? 'Aktif' : 'Belum Dikonfigurasi' ?></span>
                    </div>
                </div>
            </div>
            <div class="user-info">
                <span>Hai, <?= esc($_SESSION['nama'] ?? 'Admin') ?></span>
                <span class="role-badge" style="background: #0b6e75; color: #ffffff; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600;">
                    <?= esc($_SESSION['role'] ?? '') ?>
                </span>
                <button class="logout" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </header>

        <section class="content">
            <!-- Stats Cards -->
            <div class="stats-cards">
                <div class="stat-card stat-pending">
                    <h3>Menunggu Verifikasi</h3>
                    <div class="count"><?= $stats['pending'] ?></div>
                    <p><i class="fas fa-clock"></i> Perlu ditindaklanjuti</p>
                </div>
                <div class="stat-card stat-diverifikasi">
                    <h3>Sudah Diverifikasi</h3>
                    <div class="count"><?= $stats['diverifikasi'] ?></div>
                    <p><i class="fas fa-check-circle"></i> Petugas aktif</p>
                </div>
                <div class="stat-card stat-ditolak">
                    <h3>Ditolak</h3>
                    <div class="count"><?= $stats['ditolak'] ?></div>
                    <p><i class="fas fa-times-circle"></i> Pendaftaran ditolak</p>
                </div>
                <div class="stat-card stat-total">
                    <h3>Total Pendaftaran</h3>
                    <div class="count"><?= $stats['total'] ?></div>
                    <p><i class="fas fa-users"></i> Semua pendaftaran</p>
                </div>
            </div>
            
            <!-- Navigation Tabs -->
            <div class="nav-tabs">
                <a href="verifikasi_pendaftaran.php?status=pending<?= $search_query_suffix ?>" 
                   class="nav-tab <?= $status_filter === 'pending' ? 'active' : '' ?>">
                    <i class="fas fa-clock"></i> Menunggu (<?= $stats['pending'] ?>)
                </a>
                <a href="verifikasi_pendaftaran.php?status=diverifikasi<?= $search_query_suffix ?>" 
                   class="nav-tab <?= $status_filter === 'diverifikasi' ? 'active' : '' ?>">
                    <i class="fas fa-check-circle"></i> Diterima (<?= $stats['diverifikasi'] ?>)
                </a>
                <a href="verifikasi_pendaftaran.php?status=ditolak<?= $search_query_suffix ?>" 
                   class="nav-tab <?= $status_filter === 'ditolak' ? 'active' : '' ?>">
                    <i class="fas fa-times-circle"></i> Ditolak (<?= $stats['ditolak'] ?>)
                </a>
                <a href="verifikasi_pendaftaran.php?status=all<?= $search_query_suffix ?>" 
                   class="nav-tab <?= $status_filter === 'all' ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> Semua (<?= $stats['total'] ?>)
                </a>
            </div>
            
            <!-- Message Box -->
            <?php if ($message): ?>
                <div class="message-box message-<?= $message_type ?>">
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($pendaftaran_detail)): ?>
                <!-- Detail View -->
                <div class="detail-container">
                    <div class="detail-header">
                        <h2>Detail Pendaftaran #<?= esc($pendaftaran_detail['id']) ?></h2>
                        <span class="detail-badge badge-<?= $pendaftaran_detail['status'] ?>">
                            <?= $pendaftaran_detail['status'] === 'pending' ? 'Menunggu Verifikasi' : 
                               ($pendaftaran_detail['status'] === 'diverifikasi' ? 'Diterima' : 'Ditolak') ?>
                        </span>
                    </div>
                    
                    <div class="detail-grid">
                        <div class="detail-section">
                            <h3>Data Pribadi</h3>
                            <div class="detail-item">
                                <div class="detail-label">Nama Lengkap</div>
                                <div class="detail-value"><?= esc($pendaftaran_detail['nama']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">NIK</div>
                                <div class="detail-value"><?= esc($pendaftaran_detail['nik']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?= esc($pendaftaran_detail['email']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Nomor HP</div>
                                <div class="detail-value"><?= esc($pendaftaran_detail['no_hp']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Alamat</div>
                                <div class="detail-value"><?= esc($pendaftaran_detail['alamat']) ?></div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h3>Data Lokasi & Jabatan</h3>
                            <div class="detail-item">
                                <div class="detail-label">Kecamatan</div>
                                <div class="detail-value"><?= esc($pendaftaran_detail['nama_kecamatan']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Desa</div>
                                <div class="detail-value"><?= esc($pendaftaran_detail['nama_desa']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Jabatan</div>
                                <div class="detail-value"><?= esc($pendaftaran_detail['jabatan']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Tanggal Daftar</div>
                                <div class="detail-value">
                                    <?= date('d/m/Y H:i', strtotime($pendaftaran_detail['created_at'])) ?>
                                </div>
                            </div>
                            <?php if ($pendaftaran_detail['status'] === 'ditolak' && !empty($pendaftaran_detail['alasan'])): ?>
                            <div class="detail-item">
                                <div class="detail-label">Alasan Penolakan</div>
                                <div class="detail-value" style="border-left-color: #e74c3c; background: #fdedec;">
                                    <?= esc($pendaftaran_detail['alasan']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($pendaftaran_detail['status'] === 'pending'): ?>
                        <div class="detail-actions">
                            <button class="btn btn-accept" onclick="showAcceptModal(<?= $pendaftaran_detail['id'] ?>)">
                                <i class="fas fa-check"></i> Terima Pendaftaran
                            </button>
                            <button class="btn btn-reject" onclick="showRejectModal(<?= $pendaftaran_detail['id'] ?>)">
                                <i class="fas fa-times"></i> Tolak Pendaftaran
                            </button>
                            <a href="verifikasi_pendaftaran.php?status=pending<?= $search_query_suffix ?>" class="btn-back">
                                <i class="fas fa-arrow-left"></i> Kembali
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="detail-actions">
                            <a href="verifikasi_pendaftaran.php?status=<?= $pendaftaran_detail['status'] . $search_query_suffix ?>" class="btn-back">
                                <i class="fas fa-arrow-left"></i> Kembali ke Daftar
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php else: ?>
                <!-- Table View -->
                <div class="control-section">
                    <div class="search-filter">
                        <div class="search-box">
                            <span class="search-icon"><i class="fas fa-search"></i></span>
                            <input type="text" id="searchInput"
                                   placeholder="Cari nama, NIK, email, no HP, desa, kecamatan, atau jabatan..."
                                   value="<?= esc($search_keyword) ?>"
                                   onkeyup="handleSearch()">
                        </div>
                    </div>
                    <div class="table-info">
                        Menampilkan <?= min($limit, $total_data > 0 ? $total_data - $offset : 0) ?> dari <?= number_format($total_data) ?> data
                    </div>
                </div>

                <div class="scroll-indicator">
                    <i class="fas fa-arrows-alt-h"></i> Geser ke kanan/kiri untuk melihat semua kolom
                </div>
                
                <div class="table-scroll-wrapper">
                    <?php if ($pendaftaran_result && $pendaftaran_result->num_rows > 0): ?>
                        <table class="sticky-table">
                            <thead>
                                <tr>
                                    <th class="col-id">ID</th>
                                    <th class="col-nama">Nama</th>
                                    <th class="col-nik">NIK</th>
                                    <th class="col-email">Email</th>
                                    <th class="col-hp">No. HP</th>
                                    <th class="col-lokasi">Lokasi</th>
                                    <th class="col-jabatan">Jabatan</th>
                                    <th class="col-tanggal">Tanggal Daftar</th>
                                    <th class="col-status">Status</th>
                                    <th class="col-aksi">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $pendaftaran_result->fetch_assoc()): 
                                    $surat_tugas_row = $row['surat_tugas'] ?? null;
                                    $dokumen_url = getDokumenUrl($surat_tugas_row);
                                    $has_dokumen = ($dokumen_url !== null);
                                    
                                    // Format jabatan untuk tampilan
                                    $jabatan_labels = [
                                        'kasi_pemerintahan' => 'Kasi Pemerintahan',
                                        'kasi_pemberdayaan' => 'Kasi Pemberdayaan',
                                        'sekretaris_desa' => 'Sekretaris Desa',
                                        'kepala_desa' => 'Kepala Desa',
                                        'lainnya' => 'Lainnya'
                                    ];
                                    $jabatan_display = $jabatan_labels[$row['jabatan']] ?? $row['jabatan'];
                                ?>
                                <tr>
                                    <td class="col-id"><strong>#<?= esc($row['id']) ?></strong></td>
                                    <td class="col-nama"><?= esc($row['nama']) ?></td>
                                    <td class="col-nik"><code><?= esc($row['nik']) ?></code></td>
                                    <td class="col-email email-cell">
                                        <small><?= esc($row['email']) ?></small>
                                    </td>
                                    <td class="col-hp"><?= esc($row['no_hp']) ?></td>
                                    <td class="col-lokasi">
                                        <div style="font-size: 13px; line-height: 1.3;">
                                            <strong><?= esc($row['nama_desa']) ?></strong><br>
                                            <span style="color: #666;"><?= esc($row['nama_kecamatan']) ?></span>
                                        </div>
                                    </td>
                                    <td class="col-jabatan">
                                        <span style="font-size: 13px;"><?= esc($jabatan_display) ?></span>
                                    </td>
                                    <td class="col-tanggal"><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                                    <td class="col-status">
                                        <span class="badge badge-<?= $row['status_class'] ?>">
                                            <?= esc($row['status_text']) ?>
                                        </span>
                                    </td>
                                    <td class="col-aksi">
                                        <div class="action-buttons">
                                            <a href="verifikasi_pendaftaran.php?id=<?= $row['id'] ?>" 
                                               class="btn-icon btn-view" title="Lihat Detail">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($has_dokumen): ?>
                                            <button type="button" class="btn-icon btn-preview" 
                                                    onclick="previewDokumen('<?= urlencode($dokumen_url) ?>', 'Surat Tugas - <?= esc($row['nama']) ?>')"
                                                    title="Preview Dokumen">
                                                <i class="fas fa-search"></i>
                                            </button>
                                            <button type="button" class="btn-icon btn-download" 
                                                    onclick="downloadDokumen('<?= urlencode($dokumen_url . '&download=1') ?>', 'surat_tugas_<?= $row['id'] ?>')"
                                                    title="Download Dokumen">
                                                <i class="fas fa-download"></i>
                                            </button>
                                            <?php else: ?>
                                            <span class="btn-icon btn-view" style="opacity: 0.3; cursor: not-allowed;" title="Tidak ada dokumen">
                                                <i class="fas fa-file-alt"></i>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users-slash"></i>
                            <h3>Tidak ada data pendaftaran</h3>
                            <p>Tidak ada pendaftaran dengan status "<?= 
                                $status_filter === 'pending' ? 'Menunggu Verifikasi' : 
                                ($status_filter === 'diverifikasi' ? 'Diterima' : 
                                ($status_filter === 'ditolak' ? 'Ditolak' : 'Semua')) ?>"</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link"><i class="fas fa-chevron-left"></i></span>
                            </li>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        if ($end_page - $start_page < 4 && $start_page > 1) {
                            $start_page = max(1, $end_page - 4);
                        }
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link"><i class="fas fa-chevron-right"></i></span>
                            </li>
                        <?php endif; ?>
                    </div>
                    <div class="pagination-info">Halaman <?= $page ?> dari <?= $total_pages ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- Accept Modal -->
<div class="modal-overlay" id="acceptModal">
    <div class="modal-box">
        <h3><i class="fas fa-check-circle"></i> Terima Pendaftaran</h3>
        <p>Apakah Anda yakin ingin menerima pendaftaran ini?</p>
        <p><strong>Catatan:</strong> Sistem akan:</p>
        <ul style="margin-left: 20px; margin-bottom: 20px;">
            <li>Membuat akun petugas desa dengan password acak</li>
            <li>Mengirim email konfirmasi ke pendaftar</li>
            <li>Mengirim notifikasi Telegram ke admin</li>
        </ul>
        <form id="acceptForm" method="POST" onsubmit="showLoading('acceptBtn')">
<?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="terima">
            <input type="hidden" name="pendaftaran_id" id="acceptId">
            <div class="modal-actions">
                <button type="button" class="btn btn-back" onclick="closeAcceptModal()">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button type="submit" class="btn btn-accept" id="acceptBtn">
                    <i class="fas fa-check"></i> Ya, Terima
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box">
        <h3><i class="fas fa-times-circle"></i> Tolak Pendaftaran</h3>
        <p>Masukkan alasan penolakan untuk pendaftaran ini:</p>
        <form id="rejectForm" method="POST" onsubmit="showLoading('rejectBtn')">
<?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="tolak">
            <input type="hidden" name="pendaftaran_id" id="rejectId">
            <div class="form-group">
                <label class="form-label">Alasan Penolakan *</label>
                <textarea name="alasan_penolakan" class="form-control" 
                          placeholder="Contoh: Surat tugas tidak valid, data tidak lengkap, dll."
                          required rows="4"></textarea>
            </div>
            <p><strong>Catatan:</strong> Email pemberitahuan dan notifikasi Telegram akan dikirim ke pendaftar.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-back" onclick="closeRejectModal()">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button type="submit" class="btn btn-reject" id="rejectBtn">
                    <i class="fas fa-times"></i> Ya, Tolak
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Sidebar Toggle Functionality
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }
    
    // Set active link
    const currentPage = window.location.pathname.split('/').pop();
    const sidebarLinks = document.querySelectorAll('.sidebar nav ul li a');
    
    sidebarLinks.forEach(link => {
        const linkPage = link.getAttribute('href');
        if (linkPage === currentPage || 
            (currentPage === 'verifikasi_pendaftaran.php' && linkPage.includes('verifikasi'))) {
            link.parentElement.classList.add('active');
        } else {
            link.parentElement.classList.remove('active');
        }
    });
    
    // Add scroll indicator
    const tableWrapper = document.querySelector('.table-scroll-wrapper');
    if (tableWrapper) {
        tableWrapper.addEventListener('scroll', function() {
            const scrollIndicator = document.querySelector('.scroll-indicator');
            if (scrollIndicator) {
                if (this.scrollLeft > 10) {
                    scrollIndicator.style.opacity = '0.5';
                } else {
                    scrollIndicator.style.opacity = '1';
                }
            }
        });
    }
});

function handleSearch() {
    const debounceTimer = setTimeout(() => {
        applyFilters();
    }, 500);

    if (window._searchTimer) {
        clearTimeout(window._searchTimer);
    }
    window._searchTimer = debounceTimer;
}

function applyFilters() {
    const params = new URLSearchParams(window.location.search);
    const searchInput = document.getElementById('searchInput');
    const status = params.get('status') || 'pending';
    const searchValue = searchInput ? searchInput.value.trim() : '';

    params.set('status', status);
    if (searchValue) {
        params.set('search', searchValue);
    } else {
        params.delete('search');
    }
    params.set('page', '1');
    params.delete('id');

    window.location.href = 'verifikasi_pendaftaran.php?' + params.toString();
}

// Modal Functions
function showAcceptModal(id) {
    document.getElementById('acceptId').value = id;
    document.getElementById('acceptModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeAcceptModal() {
    document.getElementById('acceptModal').classList.remove('show');
    document.body.style.overflow = '';
}

function showRejectModal(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('show');
    document.body.style.overflow = '';
}

// Dokumen Functions
function previewDokumen(filePath, title) {
    try {
        const decodedPath = decodeURIComponent(filePath);
        
        // Cek tipe file
        const fileExtension = decodedPath.split('.').pop().toLowerCase();
        const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        
        if (imageExtensions.includes(fileExtension)) {
            // Untuk gambar, buka di modal sederhana
            showSimpleImageModal(decodedPath, title);
        } else {
            // Untuk file lain, buka di tab baru
            window.open(decodedPath, '_blank');
        }
    } catch (error) {
        console.error('Error previewing document:', error);
        // Fallback: buka di tab baru
        window.open(decodeURIComponent(filePath), '_blank');
    }
}

function downloadDokumen(filePath, fileName) {
    try {
        const decodedPath = decodeURIComponent(filePath);
        const link = document.createElement('a');
        link.href = decodedPath;
        link.download = fileName || 'document';
        link.target = '_blank';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } catch (error) {
        console.error('Error downloading document:', error);
        // Fallback: buka di tab baru
        window.open(decodeURIComponent(filePath), '_blank');
    }
}

function showSimpleImageModal(imageSrc, title) {
    const modal = document.createElement('div');
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.9);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        padding: 20px;
    `;
    
    modal.innerHTML = `
        <div style="background: white; border-radius: 8px; padding: 20px; max-width: 90%; max-height: 90%;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="margin: 0; color: #333; font-size: 16px;">${title}</h3>
                <button onclick="this.closest('div[style*=\"position: fixed\"]').remove()" 
                        style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            <img src="${imageSrc}" style="max-width: 100%; max-height: 70vh; display: block; margin: 0 auto; border-radius: 4px;">
            <div style="text-align: center; margin-top: 15px;">
                <button onclick="downloadDokumen('${encodeURIComponent(imageSrc)}', '${title.replace(/[^a-z0-9]/gi, '_').toLowerCase()}')"
                        style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin: 0 5px;">
                    <i class="fas fa-download"></i> Download
                </button>
                <button onclick="this.closest('div[style*=\"position: fixed\"]').remove()"
                        style="background: #95a5a6; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin: 0 5px;">
                    <i class="fas fa-times"></i> Tutup
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

function showLoading(buttonId) {
    const btn = document.getElementById(buttonId);
    if (btn) {
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
        btn.disabled = true;
        
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }, 5000);
    }
    return true;
}

// Close modals on outside click
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            if (this.id === 'acceptModal') {
                closeAcceptModal();
            } else if (this.id === 'rejectModal') {
                closeRejectModal();
            }
        }
    });
});

// Close modals with ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAcceptModal();
        closeRejectModal();
        
        // Juga tutup modal gambar jika ada
        const imageModal = document.querySelector('div[style*="position: fixed"]');
        if (imageModal) {
            imageModal.remove();
        }
    }
});
</script>
</body>
</html>
