<?php
declare(strict_types=1);
namespace Siprima\Api;

require_once __DIR__ . '/../security/auth_security.php';
require_once __DIR__ . '/../security/password_policy.php';
require_once __DIR__ . '/../security/password_reset.php';
require_once __DIR__ . '/../security/login_security.php';

final class Auth
{
    // Mobile session policy is deliberately longer than the browser's: an installed app is
    // expected to stay signed in between visits, and re-login every 8 hours reads as a defect.
    // The short access token plus rotating refresh remains the actual protection, and validate()
    // still revokes on password, role, active-status, or assignment change at the next request.
    public const ACCESS_SECONDS = 900;
    public const SESSION_SECONDS = 2592000;
    public const IDLE_SECONDS = 1209600;

    public function __construct(private \mysqli $db) {}

    public static function eligible(array $u): bool
    {
        return (int)($u['is_active'] ?? 0) === 1 && in_array($u['role'] ?? '', ['user','petugas_desa'], true)
            && empty($u['id_rs']) && empty($u['id_kua']) && empty($u['id_puskes']);
    }

    public static function profile(array $u): array
    {
        $out = array_intersect_key($u, array_flip(['id','nama','nik','email','no_hp','alamat','role','id_kecamatan','id_desa','is_active','must_change_password']));
        foreach (['id','id_kecamatan','id_desa','is_active'] as $key) $out[$key] = isset($out[$key]) ? (int)$out[$key] : null;
        $out['must_change_password'] = !empty($u['must_change_password']);
        $out['persona'] = $u['role'] === 'petugas_desa' ? 'village_officer' : 'citizen';
        return $out;
    }

    public function login(array $input): array
    {
        only($input, ['username','password','device_name']);
        $identifier = mb_strtolower(text($input, 'username', 100));
        $password = password($input);
        $device = text($input, 'device_name', 100);
        // Canonical account identity shares the website's NIK bucket across email/NIK aliases.
        $matches = rows($this->db, 'SELECT * FROM tb_users WHERE nik=? OR LOWER(email)=? LIMIT 2', [$identifier, $identifier]);
        $u = count($matches) === 1 ? $matches[0] : null;
        $bucket = $u ? (string)$u['nik'] : $identifier;
        $status = \login_throttle_begin_attempt($bucket);
        if ($status['is_locked']) { header('Retry-After: 300'); fail(429, 'RATE_LIMITED', 'Percobaan login dibatasi. Coba kembali nanti.'); }
        $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $verified = password_verify($password, $u['password'] ?? $dummy);
        if (!$u || !$verified || !self::eligible($u)) fail(401, 'INVALID_CREDENTIALS', 'Kredensial tidak valid atau akun tidak tersedia untuk mobile.');
        return transaction($this->db, function () use ($u, $password, $device, $bucket): array {
            $locked = one($this->db, 'SELECT * FROM tb_users WHERE id=? FOR UPDATE', [$u['id']]);
            if (!$locked || !hash_equals($u['password'], $locked['password']) || !self::eligible($locked)) fail(401, 'SESSION_REVOKED', 'Identitas berubah. Silakan login kembali.');
            if (password_needs_rehash($locked['password'], PASSWORD_DEFAULT)) {
                execute($this->db, 'UPDATE tb_users SET password=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            }
            $snapshot = \siprima_auth_snapshot($this->db, (int)$u['id']);
            $this->checkTemporary($snapshot['user']);
            // Do not call the browser success helper: it starts a cookie session.
            $lock = fopen(\login_throttle_storage_dir() . '/attempt.lock', 'c+');
            if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Throttle unavailable');
            try { $p = \login_throttle_file_path($bucket, 'account'); if (is_file($p)) unlink($p); }
            finally { flock($lock, LOCK_UN); fclose($lock); }
            $sid = bin2hex(random_bytes(16));
            $access = bin2hex(random_bytes(32)); $refresh = bin2hex(random_bytes(32));
            $now = time();
            insert($this->db, 'tb_mobile_sessions', ['id'=>$sid, 'user_id'=>$u['id'], 'device_name'=>$device,
                'access_hash'=>hash('sha256',$access), 'refresh_hash'=>hash('sha256',$refresh), 'fingerprint'=>$snapshot['fingerprint'],
                'access_expires_at'=>$now+self::ACCESS_SECONDS, 'expires_at'=>$now+self::SESSION_SECONDS, 'last_used_at'=>$now, 'created_at'=>$now]);
            return $this->tokens($sid, $access, $refresh, $now+self::ACCESS_SECONDS, $now+self::SESSION_SECONDS, $snapshot['user']);
        });
    }

    private function tokens(string $sid, string $access, string $refresh, int $accessEnd, int $end, array $u): array
    {
        return ['token_type'=>'Bearer', 'access_token'=>$access, 'refresh_token'=>$refresh, 'session_id'=>$sid,
            'expires_in'=>max(0,$accessEnd-time()), 'access_expires_at'=>date(DATE_ATOM,$accessEnd),
            'session_expires_at'=>date(DATE_ATOM,$end), 'idle_timeout_seconds'=>self::IDLE_SECONDS, 'user'=>self::profile($u)];
    }

    private function checkTemporary(array $u): void
    {
        if (!empty($u['must_change_password']) && (int)($u['temporary_password_expires_epoch'] ?? 0) <= time())
            fail(401, 'TEMPORARY_PASSWORD_EXPIRED', 'Password sementara kedaluwarsa. Minta reset kembali.');
    }

    private function validate(array $session): array
    {
        $snapshot = \siprima_auth_snapshot($this->db, (int)$session['user_id']);
        if ($session['revoked_at'] !== null || (int)$session['expires_at'] <= time() || (int)$session['last_used_at'] + self::IDLE_SECONDS <= time()
            || !$snapshot || !self::eligible($snapshot['user']) || !hash_equals($session['fingerprint'], $snapshot['fingerprint']))
            fail(401, 'SESSION_REVOKED', 'Sesi berakhir atau hak akses berubah. Silakan login ulang.');
        $this->checkTemporary($snapshot['user']);
        return $snapshot['user'];
    }

    public function authenticate(string $header, bool $allowTemporary = false): array
    {
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $m)) fail(401,'UNAUTHENTICATED','Bearer token diperlukan.');
        $session = one($this->db, 'SELECT * FROM tb_mobile_sessions WHERE access_hash=?', [hash('sha256',$m[1])]);
        if (!$session || (int)$session['access_expires_at'] <= time()) fail(401,'TOKEN_EXPIRED','Access token berakhir atau tidak valid.');
        $user = $this->validate($session);
        if (!$allowTemporary && !empty($user['must_change_password'])) fail(403,'PASSWORD_CHANGE_REQUIRED','Ganti password sementara terlebih dahulu.');
        execute($this->db, 'UPDATE tb_mobile_sessions SET last_used_at=? WHERE id=? AND revoked_at IS NULL', [time(),$session['id']]);
        return ['user'=>$user,'session'=>$session];
    }

    public function refresh(array $input): array
    {
        only($input, ['refresh_token']); $token = text($input,'refresh_token',64);
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) fail(401,'INVALID_REFRESH_TOKEN','Refresh token tidak valid.');
        $hash = hash('sha256',$token);
        // Commit family revocation before reporting replay (do not rollback the revocation).
        $result = transaction($this->db, function () use ($hash): ?array {
            $used = one($this->db,'SELECT session_id FROM tb_mobile_used_refresh WHERE token_hash=?',[$hash]);
            if ($used) { execute($this->db,'UPDATE tb_mobile_sessions SET revoked_at=? WHERE id=?',[time(),$used['session_id']]); return null; }
            $s = one($this->db,'SELECT * FROM tb_mobile_sessions WHERE refresh_hash=? FOR UPDATE',[$hash]);
            if (!$s) {
                $used = one($this->db,'SELECT session_id FROM tb_mobile_used_refresh WHERE token_hash=? FOR UPDATE',[$hash]);
                if ($used) execute($this->db,'UPDATE tb_mobile_sessions SET revoked_at=? WHERE id=?',[time(),$used['session_id']]);
                return null;
            }
            $u = $this->validate($s); $now=time();
            $a=bin2hex(random_bytes(32)); $r=bin2hex(random_bytes(32)); $aEnd=min($now+self::ACCESS_SECONDS,(int)$s['expires_at']);
            insert($this->db,'tb_mobile_used_refresh',['token_hash'=>$hash,'session_id'=>$s['id'],'expires_at'=>$s['expires_at']]);
            execute($this->db,'UPDATE tb_mobile_sessions SET access_hash=?,refresh_hash=?,access_expires_at=?,last_used_at=? WHERE id=?', [hash('sha256',$a),hash('sha256',$r),$aEnd,$now,$s['id']]);
            return $this->tokens($s['id'],$a,$r,$aEnd,(int)$s['expires_at'],$u);
        });
        if (!$result) fail(401,'INVALID_REFRESH_TOKEN','Refresh token tidak valid atau telah digunakan. Login ulang.');
        return $result;
    }

    public function logout(array $actor): array
    { execute($this->db,'UPDATE tb_mobile_sessions SET revoked_at=? WHERE id=?',[time(),$actor['session']['id']]); return ['logged_out'=>true]; }

    public function change(array $actor, array $input): array
    {
        only($input,['current_password','new_password','confirm_password']);
        $current=password($input,'current_password'); $new=newPassword($input);
        return transaction($this->db,function () use ($actor,$current,$new): array {
            $u=one($this->db,'SELECT * FROM tb_users WHERE id=? FOR UPDATE',[$actor['user']['id']]);
            if (!$u || !password_verify($current,$u['password'])) fail(422,'INVALID_PASSWORD','Password saat ini tidak cocok.',['current_password'=>'Password tidak cocok.']);
            if (password_verify($new,$u['password'])) fail(422,'VALIDATION_ERROR','Password baru harus berbeda.',['new_password'=>'Gunakan password berbeda.']);
            execute($this->db,'UPDATE tb_users SET password=?,must_change_password=0,temporary_password_expires_at=NULL,updated_at=NOW() WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$u['id']]);
            execute($this->db,'UPDATE tb_password_reset SET consumed_at=NOW() WHERE user_id=?',[$u['id']]);
            execute($this->db,'UPDATE tb_mobile_sessions SET revoked_at=? WHERE user_id=?',[time(),$u['id']]);
            return ['password_changed'=>true,'login_required'=>true];
        });
    }

    public function forgot(array $input): array
    {
        only($input,['email']); $email=text($input,'email',100);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) fail(422,'VALIDATION_ERROR','Email tidak valid.',['email'=>'Format email tidak valid.']);
        rateLimit($this->db,'forgot-email',strtolower($email),1,60);
        $matches=rows($this->db,'SELECT * FROM tb_users WHERE LOWER(email)=LOWER(?) LIMIT 2',[$email]);
        $u=count($matches)===1?$matches[0]:null;
        if ($u && self::eligible($u)) {
            try {
                $request=\siprima_reset_issue($this->db,(int)$u['id'],(int)$u['id']);
                $sent=$this->sendReset($email,$request['url']);
                \siprima_reset_delivery($this->db,$request['request_id'],(int)$u['id'],$sent,false);
            } catch (\Throwable $e) { error_log('MOBILE_API password_reset_delivery_unavailable'); }
        }
        // Never return the token, account existence, or delivery diagnostics.
        return ['message'=>'Jika akun tersedia, petunjuk reset akan dikirim ke email terdaftar.'];
    }

    private function sendReset(string $email, string $url): bool
    {
        require_once __DIR__.'/../../vendor/PHPMailer/src/Exception.php';
        require_once __DIR__.'/../../vendor/PHPMailer/src/PHPMailer.php';
        require_once __DIR__.'/../../vendor/PHPMailer/src/SMTP.php';
        if (!getenv('SMTP_HOST') || !getenv('SMTP_USERNAME')) return false;
        $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->Host=(string)getenv('SMTP_HOST'); $mail->SMTPAuth=true;
        $mail->Username=(string)getenv('SMTP_USERNAME'); $mail->Password=(string)getenv('SMTP_PASSWORD');
        $mail->Port=(int)(getenv('SMTP_PORT') ?: 587); $mail->SMTPSecure=(string)(getenv('SMTP_ENCRYPTION') ?: 'tls');
        $mail->Timeout=15; $mail->CharSet='UTF-8'; $mail->setFrom($mail->Username,'SIPRIMA'); $mail->addAddress($email);
        $mail->Subject='Reset password SIPRIMA'; $mail->Body="Gunakan tautan sekali pakai ini dalam 15 menit untuk mengganti password SIPRIMA:\n".$url;
        return $mail->send();
    }

    public function reset(array $input): array
    {
        only($input,['token','new_password','confirm_password']);
        $new=newPassword($input); $token=text($input,'token',64);
        $candidate=one($this->db,'SELECT u.* FROM tb_password_reset r JOIN tb_users u ON u.id=r.user_id WHERE r.token_hash=?',[hash('sha256',$token)]);
        if (!$candidate || !self::eligible($candidate)) fail(422,'INVALID_RESET_TOKEN','Token reset tidak valid atau kedaluwarsa.');
        try { \siprima_reset_consume($this->db,$token,$new); }
        catch (\DomainException $e) { fail(422,'INVALID_RESET_TOKEN',$e->getMessage()); }
        return ['password_changed'=>true,'login_required'=>true];
    }
}
