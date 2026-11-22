<?php
declare(strict_types=1);

namespace Siprima\Services\WhatsApp\Providers;

use Siprima\Services\WhatsApp\WhatsAppProviderInterface;
use Siprima\Services\WhatsAppService;

final class FonnteProvider implements WhatsAppProviderInterface
{
    private string $apiUrl;
    private string $apiKey;
    private bool $sslVerify;

    public function __construct(?string $apiUrl = null, ?string $apiKey = null, ?bool $sslVerify = null)
    {
        if (!function_exists('env')) {
            require_once __DIR__ . '/../../../config/env_loader.php';
        }
        $resolvedUrl = trim((string)($apiUrl ?? env('WA_GATEWAY_URL', '')));
        if ($resolvedUrl === '') {
            $resolvedUrl = 'https://api.fonnte.com/send';
        }
        $this->apiUrl = $resolvedUrl;
        $this->apiKey = trim((string)($apiKey ?? env('WA_API_KEY', '')));
        $this->sslVerify = $sslVerify ?? filter_var(env('WA_SSL_VERIFY', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function getName(): string
    {
        return 'fonnte';
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function hasEndpoint(): bool
    {
        return $this->apiUrl !== '';
    }

    public function isConfigured(): bool
    {
        return $this->hasApiKey() && $this->hasEndpoint();
    }

    public function getConfigurationChecklist(): array
    {
        return [
            [
                'key' => 'WA_API_KEY',
                'label' => 'Token / API Key Fonnte',
                'configured' => $this->hasApiKey(),
                'value_display' => $this->hasApiKey() ? 'Tersedia (Disembunyikan)' : 'Belum diisi',
                'description' => 'Diperlukan untuk autentikasi permintaan API ke penyedia Fonnte.',
            ],
            [
                'key' => 'WA_GATEWAY_URL',
                'label' => 'Endpoint URL Gateway Fonnte',
                'configured' => $this->hasEndpoint(),
                'value_display' => $this->hasEndpoint() ? 'Tersedia (URL Endpoint diisi)' : 'Belum diisi',
                'description' => 'Endpoint HTTP POST Fonnte (default: https://api.fonnte.com/send).',
            ],
        ];
    }

    public function buildRequest(string $phone, string $message, string $eventType, array $templateParams = []): array
    {
        return [
            'url' => $this->apiUrl,
            'headers' => [
                "Authorization: {$this->apiKey}",
            ],
            'body' => [
                'target' => $phone,
                'message' => $message,
                'countryCode' => '62',
            ],
            'content_type' => 'multipart/form-data',
            'meta' => [
                'provider' => 'fonnte',
                'event_type' => $eventType,
            ],
        ];
    }

    public function verifyAcceptance(int $httpCode, string $rawBody): array
    {
        $trimmed = trim($rawBody);
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'accepted' => false,
                'note' => WhatsAppService::sanitizeResponseNote("HTTP {$httpCode}: " . ($trimmed !== '' ? $trimmed : 'Respons gagal dari Fonnte')),
                'provider_message_id' => null,
            ];
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            if ($trimmed === '') {
                return [
                    'accepted' => false,
                    'note' => "HTTP {$httpCode}: Respons Fonnte kosong tanpa konfirmasi penerimaan.",
                    'provider_message_id' => null,
                ];
            }
            return [
                'accepted' => false,
                'note' => WhatsAppService::sanitizeResponseNote("HTTP {$httpCode}: Respons Fonnte tidak berformat JSON: {$trimmed}"),
                'provider_message_id' => null,
            ];
        }

        if (!empty($decoded['error'])) {
            $errDetail = is_array($decoded['error'])
                ? (string)($decoded['error']['message'] ?? json_encode($decoded['error']))
                : (string)$decoded['error'];
            return [
                'accepted' => false,
                'note' => WhatsAppService::sanitizeResponseNote('Fonnte menolak pesan: ' . $errDetail),
                'provider_message_id' => null,
            ];
        }

        if (empty($decoded['status']) || $decoded['status'] === false) {
            $reason = (string)($decoded['reason'] ?? $decoded['message'] ?? $trimmed);
            return [
                'accepted' => false,
                'note' => WhatsAppService::sanitizeResponseNote('Fonnte melaporkan kegagalan: ' . $reason),
                'provider_message_id' => null,
            ];
        }

        $msgId = null;
        if (!empty($decoded['id']) && is_array($decoded['id']) && isset($decoded['id'][0])) {
            $msgId = (string)$decoded['id'][0];
        } elseif (!empty($decoded['id']) && is_scalar($decoded['id'])) {
            $msgId = (string)$decoded['id'];
        }

        return [
            'accepted' => true,
            'note' => WhatsAppService::sanitizeResponseNote($trimmed),
            'provider_message_id' => $msgId,
        ];
    }

    public function send(
        string $phone,
        string $message,
        string $eventType,
        array $templateParams = [],
        ?callable $httpSender = null
    ): array {
        $request = $this->buildRequest($phone, $message, $eventType, $templateParams);

        if (is_callable($httpSender)) {
            $result = call_user_func($httpSender, $request['url'], $this->apiKey, $phone, $message, 'fonnte', $request);
            return [
                'http_code' => (int)($result['http_code'] ?? 0),
                'raw' => (string)($result['raw'] ?? ''),
                'request' => $request,
            ];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $request['url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerify ? 2 : 0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $request['headers']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);

            $raw = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                return ['http_code' => 500, 'raw' => 'cURL Error: ' . $err, 'request' => $request];
            }
            return ['http_code' => $httpCode, 'raw' => (string)$raw, 'request' => $request];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: {$this->apiKey}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($request['body']),
                'timeout' => 8,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $this->sslVerify,
                'verify_peer_name' => $this->sslVerify,
            ],
        ]);
        $raw = @file_get_contents($request['url'], false, $context);
        $httpCode = 500;
        if (isset($http_response_header[0]) && preg_match('/HTTP\/\S+\s+(\d{3})/', $http_response_header[0], $m)) {
            $httpCode = (int)$m[1];
        } elseif ($raw !== false) {
            $httpCode = 200;
        }

        return [
            'http_code' => $httpCode,
            'raw' => ($raw !== false ? (string)$raw : 'Gagal mengirim request Fonnte via stream'),
            'request' => $request,
        ];
    }
}
