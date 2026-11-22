<?php
if (PHP_SAPI !== 'cli' || getenv('SIPRIMA_ISOLATED_TEST') !== '1') exit(1);
if (($argv[1]??'') === 'throttle') {
    require_once __DIR__.'/../app/security/login_security.php';
    $_SERVER['REMOTE_ADDR']=$argv[2];
    $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.250';
    $status=login_throttle_begin_attempt('parallel-account');
    echo json_encode(['allowed'=>!$status['is_locked'],'ip'=>login_client_ip()]);exit;
}
require_once __DIR__.'/../app/security/password_reset.php';
$db=new mysqli('127.0.0.1','root','','siprima_security_test',(int)getenv('TEST_DB_PORT'));
if (($argv[1]??'') === 'prepare') {
    $request=siprima_reset_issue($db,(int)($argv[2]??12),1);
    echo substr($request['url'],strrpos($request['url'],'=')+1);exit;
}
try {siprima_reset_consume($db,getenv('TEST_RESET_TOKEN'),'Concurrent9!New');echo 'accepted';}
catch(DomainException $e){echo 'rejected';}
