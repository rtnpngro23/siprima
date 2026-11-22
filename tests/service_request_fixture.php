<?php
// Isolated CLI request fixture. Never available through HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/^siprima_service_test_[a-f0-9]{8}$/D', $input['database'] ?? '')) exit(2);
$_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $input['database'];
$_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = 'false';
putenv('DB_NAME='.$input['database']);
putenv('APP_DEBUG=false');
$_SERVER['REQUEST_METHOD'] = $input['method'] ?? 'GET';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTP_ACCEPT'] = $input['accept'] ?? 'text/html';
$_SERVER['SCRIPT_NAME'] = '/admin/pengaturan_layanan.php';
$_POST = $input['post'] ?? [];
$_GET = $input['get'] ?? [];
session_id('servicetest'.bin2hex(random_bytes(12)));
session_start();
$_SESSION = ['id_user'=>123, 'user_id'=>123, 'role'=>$input['role'] ?? 'admin', 'csrf_token'=>'fixture-csrf'];
if (!empty($input['session'])) $_SESSION = array_merge($_SESSION,$input['session']);
$root = dirname(__DIR__);
$target = $input['target'] ?? 'admin';
ob_start();
register_shutdown_function(function () {
    $body = ob_get_contents();
    while (ob_get_level()) ob_end_clean();
    echo json_encode(['code'=>http_response_code() ?: 200, 'saved'=>!empty($_SESSION['service_settings_saved']),
        'session_active'=>session_status() === PHP_SESSION_ACTIVE, 'body'=>$body]);
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
if ($target === 'admin') {
    chdir($root.'/public/admin');
    require $root.'/public/admin/pengaturan_layanan.php';
} elseif ($target === 'home') {
    chdir($root.'/public');
    require $root.'/public/index.php';
} elseif ($target === 'status') {
    chdir($root.'/public/user');
    require $root.'/public/user/service_status.php';
} elseif ($target === 'middleware') {
    chdir($root.'/public/user');
    require $root.'/app/middleware/'.(($input['role'] ?? '') === 'petugas_desa' ? 'petugas_desa_only.php' : 'user_only.php');
    echo 'READ_ACCESS_OK';
} elseif ($target === 'guard') {
    require $root.'/app/middleware/service_submission.php';
    service_hours_guard_submission();
    echo 'SUBMISSION_ALLOWED';
} elseif ($target === 'form') {
    $file = $input['file'] ?? '';
    if (!preg_match('/^[a-z_]+\.php$/D',$file)) exit(2);
    chdir($root.'/public/user');
    require $root.'/public/user/'.$file;
}