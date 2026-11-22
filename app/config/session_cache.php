<?php

function siprima_session_is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

function siprima_session_secret() {
    $envSecret = getenv('APP_SESSION_SECRET');
    if (is_string($envSecret) && strlen($envSecret) >= 32) {
        return $envSecret;
    }

    $cacheDir = dirname(__DIR__) . '/cache';
    $secretFile = $cacheDir . '/session_secret.key';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0750, true);
    }

    if (is_file($secretFile)) {
        $secret = trim((string)@file_get_contents($secretFile));
        if (strlen($secret) >= 32) {
            return $secret;
        }
    }

    $secret = bin2hex(random_bytes(32));
    @file_put_contents($secretFile, $secret, LOCK_EX);
    @chmod($secretFile, 0640);
    return $secret;
}

function siprima_configure_session_security() {
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $secret = siprima_session_secret();
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $sessionName = 'SIPRIMA_' . substr(hash_hmac('sha256', $host, $secret), 0, 16);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.gc_maxlifetime', '3600');
    session_name($sessionName);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => siprima_session_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

siprima_configure_session_security();

class SessionCacheFile {
    private $cacheDir;

    public function __construct($cacheDir = null) {
        $this->cacheDir = $cacheDir ?: dirname(__DIR__) . '/cache/session_query';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }
    }

    private function cachePath($query, array $params) {
        return rtrim($this->cacheDir, '/\\') . '/' . hash('sha256', $query . "\n" . json_encode($params)) . '.json';
    }

    public function cacheQuery($query, array $params, array $rows, $ttl = 120) {
        $path = $this->cachePath($query, $params);
        $payload = [
            'expires_at' => time() + max(1, (int)$ttl),
            'rows' => $rows,
        ];
        @file_put_contents($path, json_encode($payload), LOCK_EX);
        @chmod($path, 0640);
    }
}
