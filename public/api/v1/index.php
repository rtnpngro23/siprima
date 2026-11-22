<?php
declare(strict_types=1);

// This entrypoint never includes the browser's koneksi.php/error_handler.php or trusts cookies.
use Siprima\Api\ApiError;
use Siprima\Api\Router;

ini_set('display_errors','0');
date_default_timezone_set('Asia/Makassar');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$requestId=bin2hex(random_bytes(12));header('X-Request-ID: '.$requestId);
if (session_status()===PHP_SESSION_ACTIVE) session_abort();
$_SESSION=[];
set_exception_handler(static function (Throwable $e) use ($requestId): void {
    $status=$e instanceof ApiError?$e->status:503;
    http_response_code($status);
    if (!$e instanceof ApiError) error_log('MOBILE_API request='.$requestId.' exception='.get_class($e).' code='.(int)$e->getCode());
    echo json_encode(['error'=>['code'=>$e instanceof ApiError?$e->errorCode:'SERVICE_UNAVAILABLE',
        'message'=>$e instanceof ApiError?$e->getMessage():'Layanan sementara tidak tersedia. Silakan coba kembali.',
        'fields'=>(object)($e instanceof ApiError?$e->fields:[])],'meta'=>['request_id'=>$requestId,'server_time'=>date(DATE_ATOM)]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});
require_once __DIR__.'/../../../app/Api/Router.php';
require_once __DIR__.'/../../../app/config/env_loader.php';
loadEnv(__DIR__.'/../../../app/config/.env');
// loadEnv() menerapkan APP_TIMEZONE milik website dan menimpa baris di atas.
// Kontrak API menjanjikan WITA, jadi tegaskan ulang setelah env dimuat.
date_default_timezone_set('Asia/Makassar');
if (getenv('MOBILE_API_ENABLED')!=='true') Siprima\Api\fail(503,'API_NOT_ENABLED','API mobile belum diaktifkan.');
$https=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (int)($_SERVER['SERVER_PORT']??0)===443;
$trusted=array_filter(array_map('trim',explode(',',getenv('LOGIN_TRUSTED_PROXIES')?:'')));
if (in_array($_SERVER['REMOTE_ADDR']??'',$trusted,true) && ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https') $https=true;
if (!$https && !(getenv('APP_ENV')==='local' && getenv('MOBILE_API_ALLOW_HTTP')==='true')) Siprima\Api\fail(403,'HTTPS_REQUIRED','Gunakan koneksi HTTPS.');
$method=$_SERVER['REQUEST_METHOD']??'GET';
$uri=(string)(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'');
$mount=preg_replace('#/index\.php$#','',$_SERVER['SCRIPT_NAME']??'');
$path=$_SERVER['PATH_INFO']??'';
if ($path==='') $path=str_starts_with($uri,$mount)?substr($uri,strlen($mount)):$uri;
$path='/'.trim(preg_replace('#^/index\.php#','',$path),'/');
if (preg_match('/[%\\\\\x00-\x20]/',$path)) Siprima\Api\fail(404,'NOT_FOUND','Endpoint tidak ditemukan.');
$contentType=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));
$input=[];$files=[];
if (in_array($method,['POST','PATCH'],true)) {
    if (in_array($path,['/uploads','/auth/register-village-officer'],true) && $contentType!=='multipart/form-data')
        Siprima\Api\fail(415,'UNSUPPORTED_MEDIA_TYPE','Endpoint ini membutuhkan multipart/form-data.');
    if ($contentType==='multipart/form-data' && $method==='POST' && in_array($path,['/uploads','/auth/register-village-officer'],true)) {
        // Image uploads carry a 5 MB file plus multipart overhead; the ADD assignment letter
        // is a 1 MB PDF and keeps the tighter envelope.
        $bodyLimit=$path==='/uploads'?6291456:2097152;
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>$bodyLimit)
            Siprima\Api\fail(413,'PAYLOAD_TOO_LARGE',$path==='/uploads'?'Permintaan unggahan gambar maksimal 6 MB; berkas maksimal 5 MB.':'Permintaan maksimal 2 MB.');
        $input=$_POST;$files=$_FILES;
    } else {
        if ($contentType!=='application/json') Siprima\Api\fail(415,'UNSUPPORTED_MEDIA_TYPE','Gunakan application/json atau multipart pada endpoint unggahan.');
        $raw=file_get_contents('php://input',false,null,0,262145);
        if (strlen($raw)>262144) Siprima\Api\fail(413,'PAYLOAD_TOO_LARGE','JSON maksimal 256 KB.');
        try { $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
        catch(JsonException $e) { Siprima\Api\fail(422,'INVALID_JSON','JSON tidak valid.'); }
        if (!is_array($input) || !str_starts_with(ltrim($raw),'{')) Siprima\Api\fail(422,'INVALID_JSON','Body harus berupa objek JSON.');
    }
}
$headers=['authorization'=>(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''),
    'if-match'=>(string)($_SERVER['HTTP_IF_MATCH']??''),'idempotency-key'=>(string)($_SERVER['HTTP_IDEMPOTENCY_KEY']??'')];
$db=Siprima\Api\database();
$result=(new Router($db))->dispatch($method,$path,$input,$_GET,$files,$headers);
http_response_code($result['status']);
if (isset($result['file'])) {
    $file=$result['file'];header('Content-Type: '.$file['mime']);header('Content-Length: '.filesize($file['path']));
    header('Content-Disposition: attachment; filename="'.$file['name'].'"');readfile($file['path']);exit;
}
echo json_encode(['data'=>$result['data'],'meta'=>['request_id'=>$requestId,'server_time'=>date(DATE_ATOM)]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
