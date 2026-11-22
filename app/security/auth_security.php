<?php
declare(strict_types=1);

function siprima_public_path(string $page): string
{
    $root = realpath(__DIR__ . '/../../public');
    $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $url = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($root && $script) {
        $relative = str_replace('\\', '/', substr($script, strlen($root)));
        if ($relative !== '' && str_ends_with($url, $relative)) return substr($url, 0, -strlen($relative)) . '/' . ltrim($page, '/');
    }
    return '/' . ltrim($page, '/');
}

function siprima_auth_snapshot(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT u.*, UNIX_TIMESTAMP(temporary_password_expires_at) AS temporary_password_expires_epoch FROM tb_users u WHERE id=? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Authentication lookup unavailable.');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) throw new RuntimeException('Authentication lookup failed.');
    $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user) return null;
    $assignments = [];
    if (in_array($user['role'], ['operator', 'petugas_desa'], true)) {
        $stmt = $db->prepare('SELECT kecamatan_id,kua_id,puskes_id,rs_id FROM tb_operator_kecamatan WHERE operator_id=? ORDER BY kecamatan_id,kua_id,puskes_id,rs_id');
        if (!$stmt) throw new RuntimeException('Assignment lookup unavailable.');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) throw new RuntimeException('Assignment lookup failed.');
        $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
    $identity = [];
    foreach (['id','password','role','is_active','id_kua','id_puskes','id_rs','id_kecamatan','id_desa','id_petugas','must_change_password','temporary_password_expires_at'] as $field) {
        $identity[$field] = (string)($user[$field] ?? '');
    }
    $identity['assignments'] = $assignments;
    return ['user' => $user, 'fingerprint' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR))];
}

function siprima_auth_bind(mysqli $db, int $id, ?array $verifiedUser = null): void
{
    $snapshot = siprima_auth_snapshot($db, $id);
    if (!$snapshot || (int)$snapshot['user']['is_active'] !== 1) throw new RuntimeException('Account unavailable.');
    if ($verifiedUser !== null) {
        foreach (['password','role','is_active','id_kua','id_puskes','id_rs','id_kecamatan','id_desa','id_petugas'] as $field) {
            if (!hash_equals((string)($snapshot['user'][$field] ?? ''), (string)($verifiedUser[$field] ?? ''))) {
                throw new RuntimeException('Identity changed during login.');
            }
        }
    }
    $_SESSION['auth_fingerprint'] = $snapshot['fingerprint'];
    $_SESSION['auth_started_at'] = time();
    $_SESSION['auth_last_activity'] = time();
}

function siprima_auth_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires'=>time()-3600, 'path'=>$params['path'], 'domain'=>$params['domain'], 'secure'=>$params['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
        session_destroy();
    }
}

function siprima_auth_reject(string $reason = 'revoked'): never
{
    error_log('SECURITY session_rejected reason='.$reason.' user_id='.(int)($_SESSION['id_user']??0));
    siprima_auth_logout();
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        http_response_code(401); header('Content-Type: application/json');
        exit(json_encode(['success'=>false,'message'=>'Sesi berakhir atau hak akses berubah. Silakan login ulang.']));
    }
    header('Location: ' . siprima_public_path('login.php?error=session_expired'));
    exit;
}

function siprima_auth_guard(mysqli $db): void
{
    if (PHP_SAPI === 'cli') return;
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $id = (int)($_SESSION['id_user'] ?? $_SESSION['user_id'] ?? 0);
    if ($id <= 0) return;
    // Pre-deployment sessions must authenticate again; never adopt a stale session.
    $now = time();
    if (empty($_SESSION['auth_fingerprint']) || $now - (int)($_SESSION['auth_last_activity'] ?? 0) > 1800
        || $now - (int)($_SESSION['auth_started_at'] ?? 0) > 28800) siprima_auth_reject('expired_or_legacy');
    try { $snapshot = siprima_auth_snapshot($db, $id); }
    catch (Throwable $e) { http_response_code(503); exit('Pemeriksaan akses sementara tidak tersedia.'); }
    if (!$snapshot || (int)$snapshot['user']['is_active'] !== 1
        || !hash_equals($_SESSION['auth_fingerprint'], $snapshot['fingerprint'])) siprima_auth_reject();
    $_SESSION['auth_last_activity'] = $now;
    if (!empty($snapshot['user']['must_change_password'])) {
        $expires = (int)($snapshot['user']['temporary_password_expires_epoch'] ?? 0);
        if (!$expires || $expires <= $now) siprima_auth_reject('temporary_expired');
        $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $allowed = [realpath(__DIR__.'/../../public/change_temporary_password.php'), realpath(__DIR__.'/../../public/logout.php')];
        if (!$script || !in_array($script, $allowed, true)) {
            header('Location: '.siprima_public_path('change_temporary_password.php'), true, 303); exit;
        }
    }
}
