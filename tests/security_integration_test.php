<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !getenv('SIPRIMA_ISOLATED_TEST')) exit(1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', '', '', (int)getenv('TEST_DB_PORT'));
$db->query('CREATE DATABASE IF NOT EXISTS siprima_security_test');
$db->select_db('siprima_security_test');
if (($argv[1] ?? '') === 'setup') {
    $db->query("CREATE TABLE tb_users (id INT PRIMARY KEY, nama VARCHAR(100), nik VARCHAR(16),email VARCHAR(100),no_hp VARCHAR(20),password VARCHAR(255),role VARCHAR(20),is_active INT DEFAULT 1,id_kua INT NULL,id_puskes INT NULL,id_rs INT NULL,id_kecamatan INT NULL,id_desa INT NULL,id_petugas INT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $db->query('CREATE TABLE tb_operator_kecamatan (operator_id INT,kecamatan_id INT,kua_id INT,puskes_id INT,rs_id INT) ENGINE=InnoDB');
    $db->query('CREATE TABLE tb_kua (id_kua INT,nama_kua VARCHAR(100),id_kecamatan INT)');
    $db->query('CREATE TABLE tb_puskes (id_puskes INT,nama_puskes VARCHAR(100),id_kecamatan INT)');
    $db->query('CREATE TABLE tb_rumahsakit (id_rs INT,nama_rs VARCHAR(100))');
    $hash = password_hash('Initial8!Secret', PASSWORD_BCRYPT, ['cost'=>4]);
    $stmt = $db->prepare("INSERT INTO tb_users(id,nama,nik,email,password,role) VALUES (?, 'Synthetic User', ?, 'synthetic@example.invalid', ?, ?)");
    for ($id=1;$id<=12;$id++) { $nik = str_pad((string)$id,16,'0',STR_PAD_LEFT); $role=$id===1?'admin':($id===2?'operator':'user'); $stmt->bind_param('isss',$id,$nik,$hash,$role);$stmt->execute(); }
    $db->query('INSERT INTO tb_operator_kecamatan VALUES(2,NULL,1,NULL,NULL)');
    echo "PASS isolated schema setup\n"; exit;
}
require_once __DIR__.'/../app/security/password_policy.php';
require_once __DIR__.'/../app/security/password_reset.php';
require_once __DIR__.'/../app/security/auth_security.php';
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo 'PASS '.$label."\n"; }
function denied(callable $fn, string $label): void { try { $fn(); } catch (DomainException $e) { check(true,$label);return; } throw new RuntimeException($label.' was accepted'); }
foreach (['short1!', 'alllower1!', 'ALLUPPER1!', 'NoNumbers!', 'NoSymbol123', 'Password1!', "Abcdef1!\0",str_repeat('A',73).'a1!'] as $bad) check(siprima_password_error($bad)!==null,'reject invalid password');
check(siprima_password_error('Good8!Ab')===null,'accept eight-character combination');
check(siprima_password_error(str_repeat('Ab1!',18))===null,'accept 72-byte password');
for($i=0;$i<100;$i++) { if(siprima_password_error(siprima_generate_password(8))!==null) throw new RuntimeException('Generator failed'); }
check(true,'100 generated passwords satisfy all groups');
$before = siprima_auth_snapshot($db,2)['fingerprint'];
$verifiedUser = siprima_auth_snapshot($db,2)['user'];
$db->query('UPDATE tb_operator_kecamatan SET kua_id=2 WHERE operator_id=2');
check($before!==siprima_auth_snapshot($db,2)['fingerprint'],'assignment change invalidates fingerprint');
$before = siprima_auth_snapshot($db,2)['fingerprint'];
$db->query("UPDATE tb_users SET role='user' WHERE id=2");
check($before!==siprima_auth_snapshot($db,2)['fingerprint'],'role change invalidates fingerprint');
try { siprima_auth_bind($db,2,$verifiedUser); throw new LogicException('Stale identity accepted'); }
catch (RuntimeException $e) { check(true,'identity changed during login is rejected'); }
$before = siprima_auth_snapshot($db,3)['fingerprint'];
$request = siprima_reset_issue($db,3,1);
$token = substr($request['url'],strrpos($request['url'],'=')+1);
check(!str_contains($request['url'],'?'),'reset token uses fragment, not query');
$row = $db->query('SELECT * FROM tb_password_reset WHERE user_id=3')->fetch_assoc();
check($row['token_hash']===hash('sha256',$token) && !in_array($token,$row,true),'only hash persisted');
denied(fn()=>siprima_reset_issue($db,3,1),'reset request cooldown');
denied(fn()=>siprima_reset_fallback($db,$request['request_id'],1),'pending delivery blocks fallback');
siprima_reset_delivery($db,$request['request_id'],1,true,false);
denied(fn()=>siprima_reset_fallback($db,$request['request_id'],1),'one successful channel blocks fallback');
siprima_reset_consume($db,$token,'Changed9!Secret');
denied(fn()=>siprima_reset_consume($db,$token,'Changed0!Secret'),'token replay rejected');
check($before!==siprima_auth_snapshot($db,3)['fingerprint'],'reset invalidates session fingerprint');
$request = siprima_reset_issue($db,4,1);
siprima_reset_delivery($db,$request['request_id'],1,false,false);
denied(fn()=>siprima_reset_fallback($db,$request['request_id'],2),'different admin cannot use fallback');
$temporary = siprima_reset_fallback($db,$request['request_id'],1);
$user = $db->query('SELECT * FROM tb_users WHERE id=4')->fetch_assoc();
check(password_verify($temporary,$user['password']) && $user['must_change_password']==1,'fallback password hashed and forced change set');
check(siprima_password_error($temporary)===null,'fallback password meets policy');
denied(fn()=>siprima_reset_fallback($db,$request['request_id'],1),'fallback replay cannot regenerate password');
$token = substr($request['url'],strrpos($request['url'],'=')+1);
denied(fn()=>siprima_reset_consume($db,$token,'Changed9!Secret'),'fallback revokes old token');
$request = siprima_reset_issue($db,5,1);
$db->query('UPDATE tb_password_reset SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE user_id=5');
$token = substr($request['url'],strrpos($request['url'],'=')+1);
denied(fn()=>siprima_reset_consume($db,$token,'Changed9!Secret'),'expired token rejected');
$request = siprima_reset_issue($db,6,1);
$db->query("UPDATE tb_users SET password='changed-outside-reset' WHERE id=6");
$token = substr($request['url'],strrpos($request['url'],'=')+1);
denied(fn()=>siprima_reset_consume($db,$token,'Changed9!Secret'),'password change invalidates pending token');
foreach(['http://example.invalid','https://user@example.invalid','https://example.invalid/?x=1'] as $url) {
    putenv('APP_PUBLIC_URL='.$url);try {siprima_reset_base_url();throw new LogicException('Unsafe base accepted');}catch(RuntimeException $e) {check(true,'unsafe reset base rejected');}
}
echo "PASS security integration suite\n";
