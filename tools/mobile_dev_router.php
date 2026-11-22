<?php
declare(strict_types=1);
// API-only local router. Never use as a production web server or serve uploads directly.
if (PHP_SAPI!=='cli-server' || getenv('APP_ENV')!=='local') { http_response_code(404);exit; }
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (!str_starts_with($path,'/api/v1/')) { http_response_code(404);exit; }
$_SERVER['SCRIPT_NAME']='/api/v1/index.php';
$_SERVER['SCRIPT_FILENAME']=__DIR__.'/../public/api/v1/index.php';
unset($_SERVER['PATH_INFO']);
require __DIR__.'/../public/api/v1/index.php';
