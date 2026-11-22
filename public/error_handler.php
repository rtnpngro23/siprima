<?php
/**
 * Global Error Handler with Log Rotation
 * Include this file at the top of your PHP files to handle errors gracefully
 */

// Configuration
define('ERROR_LOG_DIR', getenv('SIPRIMA_LOG_DIR') ?: dirname(__DIR__, 2) . '/private_logs');
define('ERROR_LOG_FILE', ERROR_LOG_DIR . '/application.log');
define('ERROR_LOG_MAX_SIZE', 10 * 1024 * 1024); // 10 MB
define('ERROR_LOG_MAX_FILES', 10); // Keep 10 rotated files
define('ERROR_LOG_RETENTION_DAYS', 30); // Delete logs older than 30 days

// Set error reporting level
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1); // Log errors to file

// Log pertama ditulis sebelum .env dimuat; tanpa ini PHP memakai UTC bila php.ini tidak mengatur
// date.timezone. loadEnv() tetap menerapkan APP_TIMEZONE sesudahnya.
date_default_timezone_set('Asia/Makassar');

// Create logs directory if not exists
if (!is_dir(ERROR_LOG_DIR)) {
    mkdir(ERROR_LOG_DIR, 0750, true);
    // Secure the directory
    if (file_exists(ERROR_LOG_DIR)) {
        chmod(ERROR_LOG_DIR, 0750);
    }
}

// Initialize error log
ini_set('error_log', ERROR_LOG_FILE);

// Ensure session cookie/secret hardening is active even when auto_prepend_file is not applied.
require_once __DIR__ . '/../app/config/session_cache.php';
require_once __DIR__ . '/../app/security/password_policy.php';
require_once __DIR__ . '/../app/security/auth_security.php';

function siprima_request_id(): string {
    static $id;
    return $id ??= bin2hex(random_bytes(12));
}

// Upload protection helpers
function upload_security_flatten_files(array $files) {
    $flattened = [];
    foreach ($files as $field => $file) {
        if (!is_array($file) || !isset($file['name'])) {
            continue;
        }

        if (is_array($file['name'])) {
            foreach ($file['name'] as $index => $name) {
                $flattened[] = [
                    'field' => (string)$field,
                    'name' => (string)$name,
                    'type' => (string)($file['type'][$index] ?? ''),
                    'tmp_name' => (string)($file['tmp_name'][$index] ?? ''),
                    'error' => (int)($file['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int)($file['size'][$index] ?? 0),
                ];
            }
        } else {
            $flattened[] = [
                'field' => (string)$field,
                'name' => (string)$file['name'],
                'type' => (string)($file['type'] ?? ''),
                'tmp_name' => (string)($file['tmp_name'] ?? ''),
                'error' => (int)($file['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($file['size'] ?? 0),
            ];
        }
    }
    return $flattened;
}

function upload_security_detect_mime($tmpFile) {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmpFile);
            finfo_close($finfo);
            return $mime;
        }
    }

    return function_exists('mime_content_type') ? (string)mime_content_type($tmpFile) : '';
}

function upload_security_should_scan_for_script_payload($ext, $mime) {
    $ext = strtolower((string)$ext);
    $mime = strtolower((string)$mime);

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'], true)) {
        return false;
    }

    return $mime === ''
        || strpos($mime, 'text/') === 0
        || in_array($mime, [
            'application/javascript',
            'application/json',
            'application/xml',
            'image/svg+xml',
            'text/xml',
        ], true);
}

function upload_security_file_has_script_payload($tmpFile) {
    $content = @file_get_contents($tmpFile);
    if (!is_string($content) || $content === '') {
        return false;
    }

    return (bool)preg_match('/<\?(php|=)?|<script\b|eval\s*\(|base64_decode\s*\(|shell_exec\s*\(|passthru\s*\(|system\s*\(/i', $content);
}

function upload_security_validate_uploaded_file(array $file, &$message = null) {
    static $allowedMimeByExt = [
        'pdf' => ['application/pdf', 'application/x-pdf', 'application/acrobat', 'applications/vnd.pdf', 'text/pdf', 'application/octet-stream'],
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png', 'image/x-png'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/x-cfb', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/x-cfb', 'application/CDFV2', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return true;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Upload file gagal.';
        return false;
    }

    $name = str_replace("\0", '', basename($file['name']));
    $tmpFile = $file['tmp_name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($name === '' || preg_match('/\.(php|phtml|phps|phar|pl|py|jsp|asp|aspx|sh|cgi|exe|bat|cmd)(\.|$)/i', $name)) {
        $message = 'Nama atau ekstensi file tidak diizinkan.';
        return false;
    }
    if (!isset($allowedMimeByExt[$ext])) {
        $message = 'Ekstensi file tidak diizinkan.';
        return false;
    }
    if (!is_uploaded_file($tmpFile) || !is_readable($tmpFile)) {
        $message = 'File upload tidak valid.';
        return false;
    }
    if ($file['size'] <= 0 || $file['size'] > 10 * 1024 * 1024) {
        $message = 'Ukuran file upload tidak valid.';
        return false;
    }
    $detectedMime = upload_security_detect_mime($tmpFile);
    if ($detectedMime !== '' && !in_array($detectedMime, $allowedMimeByExt[$ext], true)) {
        $message = 'Tipe file tidak sesuai dengan ekstensi.';
        return false;
    }

    if (upload_security_should_scan_for_script_payload($ext, $detectedMime) && upload_security_file_has_script_payload($tmpFile)) {
        $message = 'File mengandung pola script berbahaya.';
        return false;
    }

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true) && @getimagesize($tmpFile) === false) {
        $message = 'File gambar tidak valid.';
        return false;
    }

    if ($ext === 'pdf') {
        $header = @file_get_contents($tmpFile, false, null, 0, 5);
        if ($header !== '%PDF-') {
            $message = 'File PDF tidak valid.';
            return false;
        }
    }

    return true;
}

function upload_security_validate_pdf_only_file(array $file, &$message = null) {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return true;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Upload file PDF gagal.';
        return false;
    }

    $name = str_replace("\0", '', basename($file['name']));
    $tmpFile = $file['tmp_name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($name === '' || $ext !== 'pdf') {
        $message = 'Dokumen hasil hanya boleh berupa file PDF.';
        return false;
    }

    if (preg_match('/\.(php|phtml|phps|phar|pl|py|jsp|asp|aspx|sh|cgi|exe|bat|cmd|html|htm|svg)(\.|$)/i', $name)) {
        $message = 'Nama atau ekstensi file tidak diizinkan.';
        return false;
    }

    if (!is_uploaded_file($tmpFile) || !is_readable($tmpFile)) {
        $message = 'File upload PDF tidak valid.';
        return false;
    }

    $maxPdfOnlyBytes = defined('DOKUMEN_HASIL_MAX_BYTES') ? DOKUMEN_HASIL_MAX_BYTES : 1024 * 1024;
    if ($file['size'] <= 0 || $file['size'] > $maxPdfOnlyBytes) {
        $message = 'Ukuran file PDF tidak valid. Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.';
        return false;
    }

    $detectedMime = upload_security_detect_mime($tmpFile);
    if (!in_array($detectedMime, ['application/pdf', 'application/x-pdf'], true)) {
        $message = 'Tipe file tidak sesuai. Dokumen hasil wajib PDF.';
        return false;
    }

    $header = @file_get_contents($tmpFile, false, null, 0, 5);
    if ($header !== '%PDF-') {
        $message = 'File PDF tidak valid.';
        return false;
    }

    return true;
}

function upload_security_reject($message) {
    error_log(sprintf(
        'UPLOAD_REJECT uri=%s method=%s reason=%s',
        parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '-',
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $message
    ));

    http_response_code(400);
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isJson = stripos($accept, 'application/json') !== false || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
    } else {
        echo '<h1>400 Bad Request</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    exit;
}

function upload_security_guard_request() {
    if (PHP_SAPI === 'cli' || (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') || empty($_FILES)) {
        return;
    }

    foreach (upload_security_flatten_files($_FILES) as $file) {
        $message = null;
        $isDokumenHasil = in_array((string)($file['field'] ?? ''), ['dokumen_hasil', 'dokumen_hasil_operator'], true);
        $isValidUpload = $isDokumenHasil
            ? upload_security_validate_pdf_only_file($file, $message)
            : upload_security_validate_uploaded_file($file, $message);

        if (!$isValidUpload) {
            upload_security_reject($message ?: 'File upload ditolak.');
        }
    }
}

// CSRF protection helpers
function csrf_start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function csrf_token() {
    csrf_start_session();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input() {
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_request_token() {
    if (isset($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'x-csrf-token') {
                $header = $v;
                break;
            }
        }
    }
    return $header;
}

function csrf_validate() {
    csrf_start_session();
    $token = csrf_request_token();
    if (!is_string($token) || $token === '') {
        return false;
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }
    return hash_equals($sessionToken, $token);
}

function csrf_should_skip_validation() {
    // All browser POST endpoints, including login and KK, require a session token.
    return false;
}

function csrf_ini_size_to_bytes($size) {
    if (!is_string($size) || $size === '') {
        return 0;
    }
    $size = trim($size);
    $unit = strtolower(substr($size, -1));
    $value = (float)$size;
    switch ($unit) {
        case 'g':
            $value *= 1024;
            // fallthrough
        case 'm':
            $value *= 1024;
            // fallthrough
        case 'k':
            $value *= 1024;
            break;
    }
    return (int)$value;
}

function csrf_request_body_too_large() {
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength <= 0) {
        return false;
    }
    $postMaxBytes = csrf_ini_size_to_bytes((string)ini_get('post_max_size'));
    if ($postMaxBytes <= 0) {
        return false;
    }
    return $contentLength > $postMaxBytes;
}

function csrf_reject() {
    // Diagnostic log for CSRF failures without leaking full token values.
    $postToken = $_POST['csrf_token'] ?? '';
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    error_log(sprintf(
        'CSRF_REJECT path=%s method=%s request_id=%s post_token=%s header_token=%s session_token=%s origin_present=%s',
        parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '-',
        $_SERVER['REQUEST_METHOD'] ?? '-',
        siprima_request_id(),
        (is_string($postToken) && $postToken !== '') ? 'present' : 'missing',
        (is_string($headerToken) && $headerToken !== '') ? 'present' : 'missing',
        (is_string($sessionToken) && $sessionToken !== '') ? 'present' : 'missing',
        isset($_SERVER['HTTP_REFERER']) ? 'yes' : 'no'
    ));

    http_response_code(403);
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = (stripos($accept, 'application/json') !== false) ||
              (stripos($contentType, 'application/json') !== false) ||
              (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'CSRF token invalid.']);
    } else {
        echo '<h1>403 Forbidden</h1><p>CSRF token invalid.</p>';
    }
    exit;
}

function csrf_reject_body_too_large() {
    http_response_code(413);
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = (stripos($accept, 'application/json') !== false) ||
              (stripos($contentType, 'application/json') !== false) ||
              (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

    error_log(sprintf(
        'POST_TOO_LARGE uri=%s method=%s content_length=%s post_max_size=%s',
        parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '-',
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $_SERVER['CONTENT_LENGTH'] ?? '-',
        (string)ini_get('post_max_size')
    ));

    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Ukuran data upload melebihi batas server. Kecilkan ukuran file lalu coba lagi.'
        ]);
    } else {
        echo '<h1>413 Payload Too Large</h1><p>Ukuran data upload melebihi batas server. Kecilkan ukuran file lalu coba lagi.</p>';
    }
    exit;
}

// Enforce CSRF on POST requests (skip CLI)
if (PHP_SAPI !== 'cli' && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
    if (!csrf_should_skip_validation()) {
        if (csrf_request_body_too_large()) {
            csrf_reject_body_too_large();
        }
        if (!csrf_validate()) {
            csrf_reject();
        }
    }
    upload_security_guard_request();
}

/**
 * Log Rotation Manager
 */
class LogRotator {
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Check if log rotation is needed
     */
    public function checkRotation($logFile) {
        if (!file_exists($logFile)) {
            return;
        }
        
        $fileSize = filesize($logFile);
        if ($fileSize >= ERROR_LOG_MAX_SIZE) {
            $this->rotate($logFile);
        }
        
        // Cleanup old logs periodically (once per day)
        $this->cleanupOldLogs();
    }
    
    /**
     * Rotate log file
     */
    private function rotate($logFile) {
        // Create backup copies
        for ($i = ERROR_LOG_MAX_FILES - 1; $i >= 0; $i--) {
            $oldFile = $i === 0 ? $logFile : $logFile . '.' . $i;
            $newFile = $logFile . '.' . ($i + 1);
            
            if (file_exists($oldFile)) {
                if ($i === ERROR_LOG_MAX_FILES - 1) {
                    // Delete the oldest file
                    unlink($oldFile);
                } else {
                    rename($oldFile, $newFile);
                }
            }
        }
        
        // Clear current log file
        file_put_contents($logFile, '');
        chmod($logFile, 0640);
        
        $this->logRotationEvent($logFile);
    }
    
    /**
     * Cleanup logs older than retention period
     */
    private function cleanupOldLogs() {
        // Run cleanup once per day
        $lastCleanupFile = ERROR_LOG_DIR . '/.last_cleanup';
        $needsCleanup = false;
        
        if (!file_exists($lastCleanupFile)) {
            $needsCleanup = true;
        } else {
            $lastCleanup = filemtime($lastCleanupFile);
            if (time() - $lastCleanup > 86400) { // 24 hours
                $needsCleanup = true;
            }
        }
        
        if (!$needsCleanup) {
            return;
        }
        
        $cutoffTime = time() - (ERROR_LOG_RETENTION_DAYS * 24 * 60 * 60);
        $pattern = ERROR_LOG_FILE . '.*';
        
        foreach (glob($pattern) as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
            }
        }
        
        // Mark cleanup time
        touch($lastCleanupFile);
        chmod($lastCleanupFile, 0640);
    }
    
    /**
     * Log rotation event for auditing
     */
    private function logRotationEvent($logFile) {
        $rotationLog = ERROR_LOG_DIR . '/rotation.log';
        $message = date('Y-m-d H:i:s') . " - Log rotated: " . basename($logFile) . "\n";
        file_put_contents($rotationLog, $message, FILE_APPEND | LOCK_EX);
        chmod($rotationLog, 0640);
    }
    
    /**
     * Get current log file info
     */
    public function getLogInfo() {
        if (!file_exists(ERROR_LOG_FILE)) {
            return [
                'exists' => false,
                'size' => 0,
                'size_formatted' => '0 bytes',
                'last_modified' => null
            ];
        }
        
        $size = filesize(ERROR_LOG_FILE);
        $modified = filemtime(ERROR_LOG_FILE);
        
        return [
            'exists' => true,
            'size' => $size,
            'size_formatted' => $this->formatBytes($size),
            'last_modified' => date('Y-m-d H:i:s', $modified),
            'rotation_threshold' => ERROR_LOG_MAX_SIZE,
            'rotation_threshold_formatted' => $this->formatBytes(ERROR_LOG_MAX_SIZE),
            'needs_rotation' => $size >= ERROR_LOG_MAX_SIZE
        ];
    }
    
    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

/**
 * Enhanced error logging with rotation
 */
function logWithRotation($message, $type = 'ERROR') {
    $rotator = LogRotator::getInstance();
    
    // Check if rotation is needed
    $rotator->checkRotation(ERROR_LOG_FILE);
    
    // Prepare log message
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $logMessage = sprintf(
        "[%s] [%s] [IP: %s] [URI: %s] %s\n",
        $timestamp,
        $type,
        $ip,
        $uri,
        preg_replace(['/\b\d{10,16}\b/', '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i'], ['[REDACTED_NUMBER]', '[REDACTED_EMAIL]'], trim($message))
    );
    
    // Write to log file with file locking
    $fp = fopen(ERROR_LOG_FILE, 'a');
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, $logMessage);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    
    // Set secure permissions
    if (file_exists(ERROR_LOG_FILE)) {
        chmod(ERROR_LOG_FILE, 0640);
    }
    
    return $logMessage;
}

// Function untuk menampilkan halaman error
function showErrorPage($status_code = 500, $message = null) {
    http_response_code($status_code);

    // Untuk error 500, arahkan user ke index dengan guard agar tidak redirect loop.
    $isJsonRequest = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) ||
                     (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    $currentScript = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['REQUEST_URI'] ?? '');
    $currentFile = strtolower(basename(parse_url($currentScript, PHP_URL_PATH) ?: ''));

    if ((int)$status_code === 500 && $currentFile !== 'index.php' && !$isJsonRequest && !headers_sent()) {
        $redirectPath = '/index.php';
        if (preg_match('#^(.*/public)(?:/|$)#', $currentScript, $matches)) {
            $redirectPath = $matches[1] . '/index.php';
        }

        header('Location: ' . $redirectPath, true, 302);
        exit;
    }
    
    // Cari file error page
    $error_pages = [
        __DIR__ . "/{$status_code}.php",
        __DIR__ . '/error.php',
        __DIR__ . '/404.php',
        dirname(__DIR__) . '/404.php'
    ];
    
    foreach ($error_pages as $error_page) {
        if (file_exists($error_page)) {
            include $error_page;
            exit;
        }
    }
    
    // Default error page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Error <?php echo $status_code; ?></title>
        <style>
            body { 
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                margin: 0;
                padding: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .error-container {
                background: white;
                border-radius: 15px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                padding: 40px;
                max-width: 500px;
                width: 90%;
                text-align: center;
                animation: fadeIn 0.5s ease-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            h1 { 
                color: #e74c3c;
                margin-bottom: 20px;
                font-size: 2.5em;
            }
            .error-code {
                font-size: 4em;
                font-weight: bold;
                color: #e74c3c;
                margin-bottom: 10px;
            }
            .error-message {
                color: #7f8c8d;
                margin: 20px 0;
                line-height: 1.6;
            }
            .home-button {
                display: inline-block;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 12px 30px;
                border-radius: 50px;
                text-decoration: none;
                font-weight: bold;
                transition: all 0.3s ease;
                margin-top: 20px;
            }
            .home-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
            }
            .error-details {
                margin-top: 20px;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 8px;
                font-size: 0.9em;
                text-align: left;
            }
            .timestamp {
                color: #95a5a6;
                font-size: 0.8em;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-code"><?php echo $status_code; ?></div>
            <h1>Oops! Something went wrong</h1>
            
            <div class="error-message">
                <?php if ($message && $_SERVER['SERVER_NAME'] === 'localhost'): ?>
                    <p><?php echo htmlspecialchars($message); ?></p>
                <?php else: ?>
                    <p>We're sorry, but something went wrong on our end.</p>
                    <p>Our team has been notified and we're working to fix it.</p>
                <?php endif; ?>
            </div>
            
            <a href="index.php" class="home-button">
                <span>← Back to Home</span>
            </a>
            
            <?php if ($message && $_SERVER['SERVER_NAME'] === 'localhost'): ?>
                <div class="error-details">
                    <strong>Debug Details:</strong><br>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="timestamp">
                <?php echo date('F j, Y, g:i a'); ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Custom error handler function
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    // Prepare error message
    $errorTypes = [
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED'
    ];
    
    $type = $errorTypes[$errno] ?? 'UNKNOWN_ERROR';
    $message = "$type: $errstr in $errfile on line $errline";
    
    // Log with rotation
    logWithRotation($message, $type);
    
    // For critical errors, show error page
    $criticalErrors = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (in_array($errno, $criticalErrors)) {
        if ($_SERVER['SERVER_NAME'] === 'localhost') {
            showErrorPage(500, "Critical Error: $errstr in $errfile on line $errline");
        } else {
            showErrorPage(500);
        }
    }
    
    return true;
}

// Custom exception handler
function customExceptionHandler($exception) {
    // Prepare exception message
    $message = sprintf(
        "EXCEPTION: %s in %s on line %d request_id=%s",
        get_class($exception),
        $exception->getFile(),
        $exception->getLine(),
        siprima_request_id()
    );
    
    // Log with rotation
    logWithRotation($message, 'EXCEPTION');
    
    // Show error page
    if ($_SERVER['SERVER_NAME'] === 'localhost') {
        showErrorPage(500, "Exception: " . $exception->getMessage());
    } else {
        showErrorPage(500);
    }
}

// Custom fatal error handler
function customFatalErrorHandler() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Prepare fatal error message
        $message = sprintf(
            "FATAL_ERROR [%s]: %s in %s on line %s",
            $error['type'],
            $error['message'],
            $error['file'],
            $error['line']
        );
        
        // Log with rotation
        logWithRotation($message, 'FATAL_ERROR');
        
        // Show error page
        if ($_SERVER['SERVER_NAME'] === 'localhost') {
            showErrorPage(500, "Fatal Error: " . $error['message']);
        } else {
            showErrorPage(500);
        }
    }
}

// Database connection error handler
function handleDatabaseError($koneksi) {
    if ($koneksi && isset($koneksi->connect_error) && $koneksi->connect_error) {
        $message = "Database Connection Error: " . $koneksi->connect_error;
        logWithRotation($message, 'DB_ERROR');
        showErrorPage(503, "Database connection failed");
    }
}

// Register error handlers
set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');
register_shutdown_function('customFatalErrorHandler');

// Admin function to view log info (optional)
if (isset($_GET['view_log_info']) && $_SERVER['SERVER_NAME'] === 'localhost') {
    header('Content-Type: application/json');
    $rotator = LogRotator::getInstance();
    echo json_encode($rotator->getLogInfo(), JSON_PRETTY_PRINT);
    exit;
}

// Mark as loaded
if (!defined('ERROR_HANDLER_LOADED')) {
    define('ERROR_HANDLER_LOADED', true);
}

// Log application start
logWithRotation('Application started', 'INFO');
?>
