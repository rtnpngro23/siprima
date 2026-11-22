<?php
declare(strict_types=1);

namespace Siprima\Api;

use mysqli;
use RuntimeException;
use Throwable;

final class ApiError extends RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public array $fields = [])
    { parent::__construct($message); }
}

function fail(int $status, string $code, string $message, array $fields = []): never
{ throw new ApiError($status, $code, $message, $fields); }

function stmt(mysqli $db, string $sql, array $params = []): \mysqli_stmt
{
    $s = $db->prepare($sql);
    if (!$s) throw new RuntimeException('Database statement unavailable');
    if ($params) $s->bind_param(str_repeat('s', count($params)), ...$params);
    if (!$s->execute()) throw new RuntimeException('Database operation failed');
    return $s;
}

function rows(mysqli $db, string $sql, array $params = []): array
{ $s = stmt($db, $sql, $params); $r = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $r; }

function one(mysqli $db, string $sql, array $params = []): ?array
{ return rows($db, $sql, $params)[0] ?? null; }

function execute(mysqli $db, string $sql, array $params = []): int
{ $s = stmt($db, $sql, $params); $n = $s->affected_rows; $s->close(); return $n; }

function insert(mysqli $db, string $table, array $values): int
{
    // Table/columns are always supplied by server-owned catalog or code, never input keys.
    $columns = '`' . implode('`,`', array_keys($values)) . '`';
    $marks = implode(',', array_fill(0, count($values), '?'));
    execute($db, "INSERT INTO `$table` ($columns) VALUES ($marks)", array_values($values));
    return (int)$db->insert_id;
}

function transaction(mysqli $db, callable $fn): mixed
{
    $db->begin_transaction();
    try { $result = $fn(); $db->commit(); return $result; }
    catch (Throwable $e) { $db->rollback(); throw $e; }
}

function text(array $input, string $key, int $max = 100, bool $required = true): string
{
    $v = $input[$key] ?? '';
    if (!is_string($v) || !mb_check_encoding($v,'UTF-8') || mb_strlen($v) > $max || str_contains($v, "\0") || ($required && trim($v) === ''))
        fail(422, 'VALIDATION_ERROR', 'Periksa isian Anda.', [$key => "Wajib berupa teks maksimal $max karakter" . ($required ? ' dan tidak kosong.' : '.')]);
    return trim($v);
}

function integer(mixed $v, string $key, int $min = 1, int $max = 2147483647): int
{
    if ((!is_int($v) && !is_string($v)) || !preg_match('/^[0-9]+$/D', (string)$v) || (float)$v < $min || (float)$v > $max)
        fail(422, 'VALIDATION_ERROR', 'Angka tidak valid.', [$key => "Gunakan bilangan $min sampai $max."]);
    return (int)$v;
}

function only(array $input, array $allowed): void
{
    $extra = array_diff(array_keys($input), $allowed);
    if ($extra) fail(422, 'UNKNOWN_FIELDS', 'Isian tidak didukung.', array_fill_keys($extra, 'Isian ini tidak dapat diubah.'));
}

function password(array $input, string $key = 'password'): string
{
    $p = $input[$key] ?? null;
    if (!is_string($p) || strlen($p) > 72 || str_contains($p, "\0")) fail(422, 'VALIDATION_ERROR', 'Password tidak valid.', [$key => 'Gunakan password yang valid.']);
    return $p;
}

function newPassword(array $input): string
{
    $p = password($input, 'new_password');
    if ($e = \siprima_password_error($p)) fail(422, 'VALIDATION_ERROR', $e, ['new_password' => $e]);
    if ($p !== ($input['confirm_password'] ?? null)) fail(422, 'VALIDATION_ERROR', 'Konfirmasi password tidak cocok.', ['confirm_password' => 'Harus sama dengan password baru.']);
    return $p;
}

function iso(?string $v): ?string
{
    if (!$v || str_starts_with($v, '0000-')) return null;
    return (new \DateTimeImmutable($v, new \DateTimeZone('Asia/Makassar')))->format(DATE_ATOM);
}

function canonical(mixed $value): string
{
    $sort = static function (mixed $v) use (&$sort): mixed {
        if (!is_array($v)) return $v;
        if (!array_is_list($v)) ksort($v);
        return array_map($sort, $v);
    };
    return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function revision(array $row): string { return hash('sha256', canonical($row)); }

function page(array $query): array
{
    $p = integer($query['page'] ?? 1, 'page', 1, 10000);
    $size = integer($query['per_page'] ?? 20, 'per_page', 1, 100);
    return [$p, $size, ($p - 1) * $size];
}

function database(): mysqli
{
    require_once __DIR__ . '/../config/env_loader.php';
    \loadEnv(__DIR__ . '/../config/.env');
    // loadEnv() menerapkan APP_TIMEZONE website dan dapat menggeser zona waktu proses.
    // Timestamp API harus WITA agar sesuai kontrak dan SET time_zone di bawah.
    date_default_timezone_set('Asia/Makassar');
    if (!(string)getenv('DB_NAME')) throw new RuntimeException('Database not configured');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli((string)(getenv('DB_HOST') ?: 'localhost'), (string)getenv('DB_USER'), (string)getenv('DB_PASS'), (string)getenv('DB_NAME'), (int)(getenv('DB_PORT') ?: 3306));
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '+08:00'");
    return $db;
}

function rateLimit(mysqli $db, string $scope, string $identity, int $limit, int $seconds): void
{
    $bucket = hash('sha256', $scope . ':' . $identity . ':' . intdiv(time(), $seconds));
    $allowed = transaction($db, static function () use ($db, $bucket, $limit, $seconds): bool {
        execute($db, 'INSERT INTO tb_mobile_rate_limits (bucket,hits,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1', [$bucket, time() + $seconds * 2]);
        return (int)one($db, 'SELECT hits FROM tb_mobile_rate_limits WHERE bucket=? FOR UPDATE', [$bucket])['hits'] <= $limit;
    });
    if (!$allowed) { header('Retry-After: ' . $seconds); fail(429, 'RATE_LIMITED', 'Terlalu banyak permintaan. Silakan coba kembali nanti.'); }
}

function idempotent(mysqli $db, int $userId, string $scope, string $key, array $payload, callable $operation): array
{
    if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $key)) fail(422, 'IDEMPOTENCY_KEY_REQUIRED', 'Kirim Idempotency-Key unik 16–128 karakter.');
    $hash = hash('sha256', canonical($payload));
    return transaction($db, static function () use ($db, $userId, $scope, $key, $hash, $operation): array {
        execute($db, 'INSERT INTO tb_mobile_idempotency (user_id,key_hash,scope,payload_hash,created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE key_hash=VALUES(key_hash)', [$userId, hash('sha256', $key), $scope, $hash, time()]);
        $record = one($db, 'SELECT * FROM tb_mobile_idempotency WHERE user_id=? AND key_hash=? FOR UPDATE', [$userId, hash('sha256', $key)]);
        if ($record['scope'] !== $scope || !hash_equals($record['payload_hash'], $hash)) fail(409, 'IDEMPOTENCY_CONFLICT', 'Kunci sudah digunakan untuk permintaan berbeda.');
        if ($record['response_json'] !== null) return json_decode($record['response_json'], true, 64, JSON_THROW_ON_ERROR);
        $result = $operation();
        execute($db, 'UPDATE tb_mobile_idempotency SET response_json=? WHERE user_id=? AND key_hash=?', [canonical($result), $userId, hash('sha256', $key)]);
        return $result;
    });
}
