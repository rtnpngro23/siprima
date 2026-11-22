<?php
declare(strict_types=1);
require_once __DIR__ . '/password_policy.php';

function siprima_reset_stmt(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('Layanan reset belum tersedia. Periksa migrasi keamanan.');
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new RuntimeException('Operasi reset gagal. Silakan coba lagi.');
    return $stmt;
}

function siprima_reset_base_url(): string
{
    // Explicit trusted configuration only: never build credential links from Host headers.
    $base = rtrim((string)getenv('APP_PUBLIC_URL'), '/');
    $parts = parse_url($base);
    $host = strtolower((string)($parts['host'] ?? ''));
    $localHttp = getenv('APP_ENV') === 'local'
        && ($parts['scheme'] ?? '') === 'http'
        && (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
            || preg_match('/^[a-z0-9_][a-z0-9_.-]*\.test$/D', $host) === 1);
    if (!$parts || (($parts['scheme'] ?? '') !== 'https' && !$localHttp) || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('APP_PUBLIC_URL harus berisi URL HTTPS publik SIPRIMA yang tepercaya.');
    }
    return $base;
}

function siprima_reset_issue(mysqli $db, int $userId, int $actorId): array
{
    $base = siprima_reset_base_url();
    $db->begin_transaction();
    try {
        $stmt = siprima_reset_stmt($db, 'SELECT id,nama,email,role,password,is_active FROM tb_users WHERE id=? FOR UPDATE', 'i', [$userId]);
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$user || (int)$user['is_active'] !== 1) throw new DomainException('Akun tidak tersedia.');
        $stmt = siprima_reset_stmt($db, 'SELECT UNIX_TIMESTAMP(created_at) AS created_epoch FROM tb_password_reset WHERE user_id=? FOR UPDATE', 'i', [$userId]);
        $prior = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($prior && time() - (int)$prior['created_epoch'] < 60) throw new DomainException('Tunggu satu menit sebelum meminta reset berikutnya.');
        $token = bin2hex(random_bytes(32)); $hash = hash('sha256', $token);
        $requestId = bin2hex(random_bytes(16));
        $stmt = siprima_reset_stmt($db, "INSERT INTO tb_password_reset
            (user_id,requested_by,request_id,token_hash,password_snapshot,expires_at,email_status,telegram_status,created_at)
            VALUES (?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE),'pending','pending',NOW())
            ON DUPLICATE KEY UPDATE requested_by=VALUES(requested_by),request_id=VALUES(request_id),token_hash=VALUES(token_hash),
            password_snapshot=VALUES(password_snapshot),expires_at=VALUES(expires_at),email_status='pending',telegram_status='pending',consumed_at=NULL,created_at=NOW()",
            'iisss', [$userId,$actorId,$requestId,$hash,$user['password']]); $stmt->close();
        $db->commit();
        unset($user['password']);
        // Fragment keeps the token out of HTTP access logs and Referer headers.
        return ['user'=>$user,'request_id'=>$requestId,'url'=>$base.'/reset_password.php#token='.$token];
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function siprima_reset_delivery(mysqli $db, string $requestId, int $actorId, bool $email, bool $telegram): void
{
    $stmt = siprima_reset_stmt($db, 'UPDATE tb_password_reset SET email_status=?,telegram_status=? WHERE request_id=? AND requested_by=? AND consumed_at IS NULL',
        'sssi', [$email?'sent':'failed',$telegram?'sent':'failed',$requestId,$actorId]); $stmt->close();
}

function siprima_reset_fallback(mysqli $db, string $requestId, int $actorId): string
{
    $stmt = siprima_reset_stmt($db, 'SELECT user_id FROM tb_password_reset WHERE request_id=? AND requested_by=?', 'si', [$requestId,$actorId]);
    $candidate = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$candidate) throw new DomainException('Permintaan reset tidak tersedia.');
    $id = (int)$candidate['user_id'];
    $db->begin_transaction();
    try {
        $stmt = siprima_reset_stmt($db, 'SELECT password,is_active FROM tb_users WHERE id=? FOR UPDATE', 'i', [$id]);
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $stmt = siprima_reset_stmt($db, "SELECT * FROM tb_password_reset WHERE user_id=? AND request_id=? AND requested_by=?
            AND consumed_at IS NULL AND expires_at>NOW() AND email_status='failed' AND telegram_status='failed' FOR UPDATE", 'isi', [$id,$requestId,$actorId]);
        $reset = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$user || !$reset || (int)$user['is_active'] !== 1 || !hash_equals($reset['password_snapshot'], $user['password'])) throw new DomainException('Fallback tidak tersedia atau sudah digunakan.');
        $password = siprima_generate_password(); $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = siprima_reset_stmt($db, 'UPDATE tb_users SET password=?,must_change_password=1,temporary_password_expires_at=DATE_ADD(NOW(),INTERVAL 24 HOUR),updated_at=NOW() WHERE id=?', 'si', [$hash,$id]); $stmt->close();
        $stmt = siprima_reset_stmt($db, 'UPDATE tb_password_reset SET consumed_at=NOW() WHERE user_id=?', 'i', [$id]); $stmt->close();
        $db->commit();
        error_log('SECURITY reset_fallback actor_id='.$actorId.' user_id='.$id);
        return $password;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function siprima_reset_consume(mysqli $db, string $token, string $password): void
{
    if (($error = siprima_password_error($password)) !== null) throw new DomainException($error);
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new DomainException('Tautan reset tidak valid atau kedaluwarsa.');
    $hash = hash('sha256', $token);
    $stmt = siprima_reset_stmt($db, 'SELECT user_id FROM tb_password_reset WHERE token_hash=?', 's', [$hash]);
    $candidate = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$candidate) throw new DomainException('Tautan reset tidak valid atau kedaluwarsa.');
    $id = (int)$candidate['user_id'];
    $db->begin_transaction();
    try {
        $stmt = siprima_reset_stmt($db, 'SELECT password,is_active FROM tb_users WHERE id=? FOR UPDATE', 'i', [$id]);
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $stmt = siprima_reset_stmt($db, 'SELECT * FROM tb_password_reset WHERE user_id=? AND token_hash=? AND consumed_at IS NULL AND expires_at>NOW() FOR UPDATE', 'is', [$id,$hash]);
        $reset = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$user || !$reset || (int)$user['is_active'] !== 1 || !hash_equals($reset['password_snapshot'], $user['password'])) throw new DomainException('Tautan reset tidak valid atau kedaluwarsa.');
        if (password_verify($password, $user['password'])) throw new DomainException('Password baru harus berbeda dari password saat ini.');
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = siprima_reset_stmt($db, 'UPDATE tb_users SET password=?,must_change_password=0,temporary_password_expires_at=NULL,updated_at=NOW() WHERE id=?', 'si', [$newHash,$id]); $stmt->close();
        $stmt = siprima_reset_stmt($db, 'UPDATE tb_password_reset SET consumed_at=NOW() WHERE user_id=?', 'i', [$id]); $stmt->close();
        $db->commit();
        error_log('SECURITY password_reset_completed user_id='.$id);
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
