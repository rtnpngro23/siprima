<?php
declare(strict_types=1);
require_once __DIR__.'/../app/security/password_reset.php';
$local = 'http://siprima_gorontalokab_riset.test:8080/public_html/public';
$cases = [
    ['local', $local, true],
    ['local', 'http://localhost:8080/public', true],
    ['local', 'http://127.0.0.1/public', true],
    ['local', 'http://[::1]:8080/public', true],
    ['production', $local, false],
    ['', $local, false],
    ['local', 'http://example.com', false],
    ['local', 'http://app.test.example.com', false],
    ['local', 'http://user@app.test', false],
    ['local', 'http://app.test/?x=1', false],
    ['local', 'http://app.test/#token', false],
    ['local', '', false],
    ['production', 'https://example.com/public', true],
];
foreach ($cases as [$environment, $url, $expected]) {
    putenv('APP_ENV='.$environment);
    putenv('APP_PUBLIC_URL='.$url);
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    try { $actual = siprima_reset_base_url(); $accepted = true; }
    catch (RuntimeException $e) { $accepted = false; }
    if ($accepted !== $expected || ($accepted && $actual !== $url)) {
        throw new LogicException('Unexpected URL validation result: '.$environment.' '.$url);
    }
}
echo 'PASS '.count($cases)." reset URL cases\n";