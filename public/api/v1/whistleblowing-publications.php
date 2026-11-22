<?php

declare(strict_types=1);

require_once __DIR__.'/../../error_handler.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function wbs_api_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function wbs_api_bearer_token(): string
{
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $authorization = (string) $value;
                break;
            }
        }
    }

    return preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches) === 1
        ? trim((string) $matches[1])
        : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    wbs_api_respond(405, ['error' => 'method_not_allowed']);
}

try {
    $envPath = __DIR__.'/../../../app/config/.env';
    require_once __DIR__.'/../../../app/config/env_loader.php';
    $envLoaded = is_file($envPath) && loadEnv($envPath);
    $expectedToken = $envLoaded ? trim((string) env('WBS_PUBLIC_API_TOKEN', '')) : '';
    $providedToken = wbs_api_bearer_token();
    if (strlen($expectedToken) < 32) {
        error_log('WBS_PUBLIC_API_CONFIG_INVALID');
        wbs_api_respond(503, ['error' => 'service_unavailable']);
    }
    if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
        header('WWW-Authenticate: Bearer');
        wbs_api_respond(401, ['error' => 'unauthorized']);
    }

    require_once __DIR__.'/../../../app/config/koneksi.php';
    require_once __DIR__.'/../../functions/whistleblowing.php';

    $items = wbs_publications_snapshot($koneksi);
    $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format(DateTimeInterface::ATOM);

    wbs_api_respond(200, [
        'schema_version' => 1,
        'generated_at' => $generatedAt,
        'data' => $items,
        'meta' => [
            'count' => count($items),
            'complete' => true,
        ],
    ]);
} catch (Throwable $exception) {
    error_log('WBS_PUBLIC_API_FAILED reason='.$exception->getMessage());
    wbs_api_respond(503, ['error' => 'service_unavailable']);
}
