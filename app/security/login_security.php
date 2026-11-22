<?php

const LOGIN_THROTTLE_MAX_ATTEMPTS = 5;
const LOGIN_THROTTLE_IP_MAX_ATTEMPTS = 20;
const LOGIN_THROTTLE_WINDOW_SECONDS = 900;
const LOGIN_THROTTLE_LOCK_SECONDS = 300;
const LOGIN_CAPTCHA_AFTER_ATTEMPTS = 3;
const LOGIN_CAPTCHA_IP_AFTER_ATTEMPTS = 10;
const LOGIN_CAPTCHA_TTL_SECONDS = 300;

function login_client_ip() {
    $trustedProxies = array_filter(array_map('trim', explode(',', getenv('LOGIN_TRUSTED_PROXIES') ?: '')));
    $trustProxy = in_array($_SERVER['REMOTE_ADDR'] ?? '', $trustedProxies, true);
    $forwarded = $trustProxy ? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') : '';
    if (is_string($forwarded) && $forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim($parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            // Walk from the trusted edge; the leftmost value can be client supplied.
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $hop = trim($parts[$i]);
                if (!filter_var($hop, FILTER_VALIDATE_IP)) break;
                if (!in_array($hop, $trustedProxies, true)) return $hop;
            }
        }
    }

    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!is_string($remoteAddr) || !filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return '127.0.0.1';
    }

    return $remoteAddr;
}

function login_throttle_storage_dir() {
    return getenv('LOGIN_THROTTLE_DIR') ?: dirname(__DIR__, 3) . '/private_security/login_throttle';
}

function login_throttle_identifier($identifier = null) {
    if ($identifier !== null) return trim((string)$identifier);
    return trim((string)($_POST['username'] ?? $_SESSION['login_username'] ?? ''));
}

function login_throttle_file_path($identifier = null, $scope = 'account') {
    $ip = login_client_ip();
    $key = $scope === 'global' ? 'all' : ($scope === 'ip' ? $ip : login_throttle_identifier($identifier));
    return login_throttle_storage_dir() . '/' . $scope . '_' . hash('sha256', $key) . '.json';
}

function login_throttle_empty_state() {
    return ['attempts' => 0, 'window_started_at' => 0, 'lock_until' => 0];
}

function login_throttle_read_state($identifier = null, $scope = 'account') {
    $dir = login_throttle_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $filePath = login_throttle_file_path($identifier, $scope);
    if (!file_exists($filePath)) {
        return login_throttle_empty_state();
    }

    $handle = fopen($filePath, 'rb');
    if (!$handle || !flock($handle, LOCK_SH)) throw new RuntimeException('Login throttle unavailable.');
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN); fclose($handle);
    if (!is_string($raw) || $raw === '') {
        return login_throttle_empty_state();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return login_throttle_empty_state();
    }

    return [
        'attempts' => (int)($decoded['attempts'] ?? 0),
        'window_started_at' => (int)($decoded['window_started_at'] ?? 0),
        'lock_until' => (int)($decoded['lock_until'] ?? 0)
    ];
}

function login_throttle_bucket_status($identifier, $scope) {
    $now = time();
    $state = login_throttle_read_state($identifier, $scope);

    if ($state['window_started_at'] > 0 && ($now - $state['window_started_at']) > LOGIN_THROTTLE_WINDOW_SECONDS) {
        $state['attempts'] = 0;
        $state['window_started_at'] = 0;
        if ($state['lock_until'] <= $now) {
            $state['lock_until'] = 0;
        }
        // Expiry is computed without a write; the locked increment persists the next window.
    }

    $remainingLockSeconds = max(0, $state['lock_until'] - $now);

    return ['attempts'=>$state['attempts'], 'remaining_lock_seconds'=>$remainingLockSeconds];
}

function login_throttle_status($identifier = null) {
    $account = login_throttle_bucket_status($identifier, 'account');
    $ip = login_throttle_bucket_status($identifier, 'ip');
    $global = login_throttle_bucket_status(null, 'global');
    $remaining = max($account['remaining_lock_seconds'], $ip['remaining_lock_seconds'], $global['remaining_lock_seconds']);
    return [
        'attempts'=>$account['attempts'],
        'is_locked'=>$remaining > 0,
        'remaining_lock_seconds'=>$remaining,
        'requires_captcha'=>$account['attempts'] >= LOGIN_CAPTCHA_AFTER_ATTEMPTS
            || $ip['attempts'] >= LOGIN_CAPTCHA_IP_AFTER_ATTEMPTS || $remaining > 0
    ];
}

function login_throttle_increment_bucket($identifier, $scope, $maxAttempts) {
    $dir = login_throttle_storage_dir();
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $handle = fopen(login_throttle_file_path($identifier, $scope), 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Login throttle unavailable.');
    $raw = stream_get_contents($handle);
    $decoded = json_decode($raw ?: '', true);
    $state = is_array($decoded) ? [
        'attempts'=>(int)($decoded['attempts']??0),
        'window_started_at'=>(int)($decoded['window_started_at']??0),
        'lock_until'=>(int)($decoded['lock_until']??0)
    ] : login_throttle_empty_state();
    $now = time();

    if ($state['window_started_at'] <= 0 || ($now - $state['window_started_at']) > LOGIN_THROTTLE_WINDOW_SECONDS) {
        $state['window_started_at'] = $now;
        $state['attempts'] = 0;
    }

    $state['attempts']++;
    if ($state['attempts'] >= $maxAttempts) {
        $delay = $scope === 'account' ? min(LOGIN_THROTTLE_LOCK_SECONDS, 5 * (2 ** min(6, $state['attempts'] - $maxAttempts))) : LOGIN_THROTTLE_LOCK_SECONDS;
        $state['lock_until'] = $now + $delay;
        if ($state['attempts'] === $maxAttempts) {
            error_log('SECURITY login_throttled scope=' . $scope . ' key=' . substr(basename(login_throttle_file_path($identifier, $scope)), 0, 32));
        }
    }
    rewind($handle); ftruncate($handle, 0);
    fwrite($handle, json_encode($state)); fflush($handle);
    flock($handle, LOCK_UN); fclose($handle);
}

function login_throttle_register_failure($identifier = null) {
    if (!empty($GLOBALS['login_attempt_reserved'])) return login_throttle_status($identifier);
    login_throttle_increment_bucket($identifier, 'account', LOGIN_THROTTLE_MAX_ATTEMPTS);
    login_throttle_increment_bucket($identifier, 'ip', LOGIN_THROTTLE_IP_MAX_ATTEMPTS);
    login_throttle_increment_bucket(null, 'global', 1000);
    return login_throttle_status($identifier);
}

// Reserve an attempt before password verification, under one lock across buckets.
// Blocked requests do not extend the lock; a new cookie or source IP cannot reset an account.
function login_throttle_begin_attempt($identifier = null): array {
    $dir = login_throttle_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Login throttle unavailable.');
    $handle = fopen($dir . '/attempt.lock', 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Login throttle unavailable.');
    try {
        $status = login_throttle_status($identifier);
        if (!$status['is_locked']) {
            login_throttle_increment_bucket($identifier, 'account', LOGIN_THROTTLE_MAX_ATTEMPTS);
            login_throttle_increment_bucket($identifier, 'ip', LOGIN_THROTTLE_IP_MAX_ATTEMPTS);
            login_throttle_increment_bucket(null, 'global', 1000);
            $GLOBALS['login_attempt_reserved'] = true;
        }
        return $status;
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}

function login_throttle_register_success($identifier = null) {
    $handle = fopen(login_throttle_storage_dir() . '/attempt.lock', 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Login throttle unavailable.');
    $filePath = login_throttle_file_path($identifier, 'account');
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    flock($handle, LOCK_UN); fclose($handle);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    unset($_SESSION['login_captcha_question'], $_SESSION['login_captcha_answer'], $_SESSION['login_captcha_expires_at']);
}

function login_captcha_prepare() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $now = time();
    $currentQuestion = $_SESSION['login_captcha_question'] ?? '';
    $currentAnswer = $_SESSION['login_captcha_answer'] ?? '';
    $expiresAt = (int)($_SESSION['login_captcha_expires_at'] ?? 0);

    if (is_string($currentQuestion) && $currentQuestion !== '' && is_string($currentAnswer) && $currentAnswer !== '' && $expiresAt > $now) {
        return $currentQuestion;
    }

    $left = random_int(1, 9);
    $right = random_int(1, 9);
    $operator = random_int(0, 1) === 1 ? '+' : '-';

    if ($operator === '-' && $left < $right) {
        $tmp = $left;
        $left = $right;
        $right = $tmp;
    }

    $answer = $operator === '+' ? ($left + $right) : ($left - $right);
    $question = $left . ' ' . $operator . ' ' . $right;

    $_SESSION['login_captcha_question'] = $question;
    $_SESSION['login_captcha_answer'] = (string)$answer;
    $_SESSION['login_captcha_expires_at'] = $now + LOGIN_CAPTCHA_TTL_SECONDS;

    return $question;
}

function login_captcha_verify($input) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $expiresAt = (int)($_SESSION['login_captcha_expires_at'] ?? 0);
    $answer = $_SESSION['login_captcha_answer'] ?? '';
    $candidate = trim((string)$input);

    $isValid = $expiresAt > time()
        && is_string($answer)
        && $answer !== ''
        && $candidate !== ''
        && hash_equals($answer, $candidate);

    unset($_SESSION['login_captcha_question'], $_SESSION['login_captcha_answer'], $_SESSION['login_captcha_expires_at']);

    return $isValid;
}
