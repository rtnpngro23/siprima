<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || getenv('SIPRIMA_ISOLATED_TEST') !== '1') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Only synthetic data on a private MySQL instance; never route this fixture in production.
require_once __DIR__.'/../public/error_handler.php';
require_once __DIR__.'/../app/security/password_reset.php';
require_once __DIR__.'/../app/security/login_security.php';
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
$db = new mysqli('127.0.0.1','root','','siprima_security_test',(int)getenv('TEST_DB_PORT'));
if ($path==='/csrf') { header('Content-Type: application/json'); echo json_encode(['token'=>csrf_token()]); exit; }
if ($path==='/proses_login.php') {
    $_SERVER['SCRIPT_FILENAME']=realpath(__DIR__.'/../public/proses_login.php');
    chdir(__DIR__.'/../public');
    require 'proses_login.php';exit;
}
if ($path==='/signin') {
    $id=(int)($_POST['id']??7); $_SESSION=[]; session_regenerate_id(true);
    $_SESSION['id_user']=$id; $_SESSION['role']=$id===1?'admin':'user'; $_SESSION['logged_in']=true;
    siprima_auth_bind($db,$id);
    if (isset($_POST['old'])) $_SESSION['auth_last_activity']=time()-1801;
    if (isset($_POST['absolute'])) $_SESSION['auth_started_at']=time()-28801;
    echo 'signed in'; exit;
}
if ($path==='/mutate') {
    $id=(int)$_POST['id'];
    if (($_POST['kind']??'')==='role') $db->query("UPDATE tb_users SET role='operator' WHERE id=$id");
    elseif (($_POST['kind']??'')==='assignment') $db->query("UPDATE tb_users SET id_kua=42 WHERE id=$id");
    else $db->query("UPDATE tb_users SET is_active=0 WHERE id=$id");
    echo 'changed'; exit;
}
if ($path==='/issue') {
    $r=siprima_reset_issue($db,(int)$_POST['id'],1);siprima_reset_delivery($db,$r['request_id'],1,false,false);
    echo json_encode(['password'=>siprima_reset_fallback($db,$r['request_id'],1)]);exit;
}
if ($path==='/throttle') { echo json_encode(login_throttle_begin_attempt('isolated-account'));exit; }
if ($path==='/change_temporary_password.php') {
    $_SERVER['SCRIPT_FILENAME']=realpath(__DIR__.'/../public/change_temporary_password.php');
    require __DIR__.'/../public/change_temporary_password.php';exit;
}
if ($path==='/reset_password.php') {
    $_SERVER['SCRIPT_FILENAME']=realpath(__DIR__.'/../public/reset_password.php');
    require __DIR__.'/../public/reset_password.php';exit;
}
if (in_array($path,['/petugas_desa_kk.php','/petugas_desa_edit_kk.php'],true)) { echo 'accepted valid csrf'; exit; }
siprima_auth_guard($db);
if(empty($_SESSION['id_user'])) {http_response_code(401);exit;}
echo 'protected data';
