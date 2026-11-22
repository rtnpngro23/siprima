<?php
declare(strict_types=1);

namespace Siprima\Services\WhatsApp\Providers;

use Siprima\Services\WhatsApp\WhatsAppProviderInterface;
use Siprima\Services\WhatsAppService;

final class MetaCloudProvider implements WhatsAppProviderInterface
{
    private string $apiVersion;
    private string $phoneNumberId;
    private string $wabaId;
    private string $accessToken;
    private string $gatewayUrlOverride;
    private string $templateLang;
    private bool $sslVerify;

    /** @var array<string, string> */
    private array $templateMap;

    public function __construct(
        ?string $accessToken = null,
        ?string $phoneNumberId = null,
        ?string $apiVersion = null,
        ?string $wabaId = null,
        ?string $templateLang = null,
        ?bool $sslVerify = null,
        ?string $gatewayUrlOverride = null
    ) {
        if (!function_exists('env')) {
            require_once __DIR__ . '/../../../config/env_loader.php';
        }

        $rawVersion = trim((string)($apiVersion ?? env('WA_META_API_VERSION', 'v22.0')));
        $this->apiVersion = preg_match('/^v\d+\.\d+$/', $rawVersion) ? $rawVersion : 'v22.0';

        $this->phoneNumberId = trim((string)($phoneNumberId ?? env('WA_PHONE_NUMBER_ID', '')));
        $this->wabaId = trim((string)($wabaId ?? env('WA_META_WABA_ID', '')));

        $metaToken = trim((string)($accessToken ?? env('WA_META_ACCESS_TOKEN', '')));
        if ($metaToken === '') {
            // Kompatibilitas mundur dengan WA_API_KEY
            $metaToken = trim((string)env('WA_API_KEY', ''));
        }
        $this->accessToken = $metaToken;

        $this->gatewayUrlOverride = trim((string)($gatewayUrlOverride ?? env('WA_GATEWAY_URL', '')));
        $lang = trim((string)($templateLang ?? env('WA_META_TEMPLATE_LANG', 'id')));
        $this->templateLang = $lang !== '' ? $lang : 'id';

        // Wajib aktif secara default untuk keamanan Access Token Meta
        $this->sslVerify = $sslVerify ?? filter_var(env('WA_SSL_VERIFY', true), FILTER_VALIDATE_BOOLEAN);

        $this->templateMap = [
            'kia_diajukan'        => trim((string)env('WA_META_TPL_KIA_DIAJUKAN', 'siprima_kia_diajukan')) ?: 'siprima_kia_diajukan',
            'kia_perbaikan'       => trim((string)env('WA_META_TPL_KIA_PERBAIKAN', 'siprima_kia_perbaikan')) ?: 'siprima_kia_perbaikan',
            'kia_selesai'         => trim((string)env('WA_META_TPL_KIA_SELESAI', 'siprima_kia_selesai')) ?: 'siprima_kia_selesai',
            'kia_pdf_tersedia'    => trim((string)env('WA_META_TPL_KIA_PDF_TERSEDIA', 'siprima_kia_pdf_tersedia')) ?: 'siprima_kia_pdf_tersedia',
            'kia_balasan_laporan' => trim((string)env('WA_META_TPL_KIA_BALASAN_LAPORAN', 'siprima_kia_tanggapan_laporan')) ?: 'siprima_kia_tanggapan_laporan',
            'kia_pdf_pengganti'   => trim((string)env('WA_META_TPL_KIA_PDF_PENGGANTI', 'siprima_kia_pdf_pengganti')) ?: 'siprima_kia_pdf_pengganti',
            'kia_selesai_dicetak' => trim((string)env('WA_META_TPL_KIA_SELESAI_DICETAK', 'siprima_kia_siap_diambil')) ?: 'siprima_kia_siap_diambil',
        ];
    }

    public function getName(): string
    {
        return 'meta_cloud';
    }

    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    public function getPhoneNumberId(): string
    {
        return $this->phoneNumberId;
    }

    public function getWabaId(): string
    {
        return $this->wabaId;
    }

    public function isSslVerifyEnabled(): bool
    {
        return $this->sslVerify;
    }

    public function getEndpointUrl(): string
    {
        if ($this->phoneNumberId !== '') {
            return "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        }
        if ($this->gatewayUrlOverride !== '' && str_contains($this->gatewayUrlOverride, 'graph.facebook.com')) {
            return $this->gatewayUrlOverride;
        }
        return '';
    }

    public function hasApiKey(): bool
    {
        return $this->accessToken !== '';
    }

    public function hasEndpoint(): bool
    {
        return $this->getEndpointUrl() !== '';
    }

    public function isConfigured(): bool
    {
        return $this->hasApiKey() && $this->hasEndpoint();
    }

    public function getConfigurationChecklist(): array
    {
        return [
            [
                'key' => 'WA_META_ACCESS_TOKEN',
                'label' => 'Meta Access Token (System User Permanent Token)',
                'configured' => $this->hasApiKey(),
                'value_display' => $this->hasApiKey() ? 'Tersedia (Disembunyikan)' : 'Belum diisi',
                'description' => 'Token Bearer dari Meta Business Manager (atau fallback WA_API_KEY) untuk autentikasi Graph API.',
            ],
            [
                'key' => 'WA_PHONE_NUMBER_ID',
                'label' => 'Meta Phone Number ID',
                'configured' => $this->phoneNumberId !== '',
                'value_display' => $this->phoneNumberId !== '' ? 'Tersedia (Phone ID diisi)' : 'Belum diisi',
                'description' => 'ID nomor pengirim resmi dari WhatsApp Manager untuk endpoint Graph API.',
            ],
            [
                'key' => 'WA_META_API_VERSION',
                'label' => 'Versi Meta Graph API',
                'configured' => $this->apiVersion !== '',
                'value_display' => $this->apiVersion,
                'description' => 'Versi Graph API dinamis (mis. v22.0) agar tidak perlu mengubah source code saat upgrade API.',
            ],
            [
                'key' => 'WA_META_WABA_ID',
                'label' => 'WhatsApp Business Account ID (Opsional)',
                'configured' => $this->wabaId !== '',
                'value_display' => $this->wabaId !== '' ? 'Tersedia' : 'Belum diisi (Opsional)',
                'description' => 'Identitas akun WABA di Meta Business Manager untuk referensi manajemen template.',
            ],
        ];
    }

    /**
     * Memetakan kode event_type SIPRIMA ke nama Approved Message Template Meta
     */
    public function resolveTemplateName(string $eventType): string
    {
        $ev = strtolower(trim($eventType));
        if ($ev === 'kia_diajukan' || str_starts_with($ev, 'kia_diajukan_ulang') || $ev === 'status_diajukan') {
            return $this->templateMap['kia_diajukan'];
        }
        if (str_starts_with($ev, 'kia_ditolak') || $ev === 'status_ditolak') {
            return $this->templateMap['kia_perbaikan'];
        }
        if ($ev === 'kia_selesai' || $ev === 'status_selesai') {
            return $this->templateMap['kia_selesai'];
        }
        if (str_starts_with($ev, 'kia_pdf_pengganti')) {
            return $this->templateMap['kia_pdf_pengganti'];
        }
        if (str_starts_with($ev, 'kia_pdf_tersedia')) {
            return $this->templateMap['kia_pdf_tersedia'];
        }
        if (str_starts_with($ev, 'kia_balasan_laporan') || $ev === 'laporan_kesalahan_ditindaklanjuti' || $ev === 'komentar_operator') {
            return $this->templateMap['kia_balasan_laporan'];
        }
        if ($ev === 'kia_selesai_dicetak' || $ev === 'cetak_selesai_dicetak') {
            return $this->templateMap['kia_selesai_dicetak'];
        }

        return $this->templateMap['kia_diajukan'];
    }

    /**
     * Sanitisasi nilai parameter template agar mematuhi aturan Meta Cloud API dan anti-PII
     */
    private static function sanitizeTemplateParam(string $value, string $fallback): string
    {
        $clean = (string)preg_replace('/\b\d{16}\b/', '[DATA TERLINDUNGI]', $value);
        $clean = (string)preg_replace('/[\r\n\t]+/', ' ', $clean);
        $clean = (string)preg_replace('/\s{2,}/', ' ', trim($clean));
        if ($clean === '') {
            return $fallback;
        }
        if (mb_strlen($clean, 'UTF-8') > 120) {
            $clean = mb_substr($clean, 0, 117, 'UTF-8') . '...';
        }
        return $clean;
    }

    /**
     * Membentuk payload resmi Meta WhatsApp Cloud API berbasis approved template
     */
    public function buildRequest(string $phone, string $message, string $eventType, array $templateParams = []): array
    {
        $templateName = !empty($templateParams['template_name']) && is_string($templateParams['template_name'])
            ? trim($templateParams['template_name'])
            : $this->resolveTemplateName($eventType);

        $namaPemohon = self::sanitizeTemplateParam((string)($templateParams['nama'] ?? ''), 'Pemohon');
        $permohonanRef = '';
        if (isset($templateParams['permohonan_id']) && (int)$templateParams['permohonan_id'] > 0) {
            $permohonanRef = '#' . (int)$templateParams['permohonan_id'];
        } elseif (preg_match('/#(\d+)/', $message, $m)) {
            $permohonanRef = '#' . $m[1];
        }
        $permohonanRef = self::sanitizeTemplateParam($permohonanRef, 'KIA');

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $this->templateLang,
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $namaPemohon],
                            ['type' => 'text', 'text' => $permohonanRef],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'url' => $this->getEndpointUrl(),
            'headers' => [
                "Authorization: Bearer {$this->accessToken}",
                'Content-Type: application/json',
            ],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'content_type' => 'application/json',
            'meta' => [
                'provider' => 'meta_cloud',
                'api_version' => $this->apiVersion,
                'template_name' => $templateName,
                'template_lang' => $this->templateLang,
                'event_type' => $eventType,
                'payload' => $payload,
            ],
        ];
    }

    public function verifyAcceptance(int $httpCode, string $rawBody): array
    {
        $trimmed = trim($rawBody);
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded) && !empty($decoded['error'])) {
            $errObj = $decoded['error'];
            if (is_array($errObj)) {
                $code = isset($errObj['code']) ? '#' . (string)$errObj['code'] : '';
                $subcode = isset($errObj['error_subcode']) ? '/' . (string)$errObj['error_subcode'] : '';
                $msg = (string)($errObj['message'] ?? 'Meta Cloud API menolak pesan');
                $detail = trim("Meta Error {$code}{$subcode}: {$msg}");
            } else {
                $detail = 'Meta Error: ' . (string)$errObj;
            }
            return [
                'accepted' => false,
                'note' => WhatsAppService::sanitizeResponseNote("HTTP {$httpCode} - {$detail}"),
                'provider_message_id' => null,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'accepted' => false,
                'note' => WhatsAppService::sanitizeResponseNote("HTTP {$httpCode}: " . ($trimmed !== '' ? $trimmed : 'Respons gagal dari Meta Cloud API')),
                'provider_message_id' => null,
            ];
        }

        if (!is_array($decoded)) {
            return [
                'accepted' => false,
                'note' => WhatsAppService::sanitizeResponseNote("HTTP {$httpCode}: Respons Meta Cloud API tidak berformat JSON valid."),
                'provider_message_id' => null,
            ];
        }

        $msgId = isset($decoded['messages'][0]['id']) ? trim((string)$decoded['messages'][0]['id']) : '';
        if ($msgId === '') {
            return [
                'accepted' => false,
                'note' => WhatsAppService::sanitizeResponseNote('Konfirmasi message_id Meta Cloud API tidak ditemukan: ' . $trimmed),
                'provider_message_id' => null,
            ];
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
            $result = call_user_func($httpSender, $request['url'], $this->accessToken, $phone, $message, 'meta_cloud', $request);
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
                'header' => implode("\r\n", $request['headers']) . "\r\n",
                'content' => (string)$request['body'],
                'timeout' => 10,
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
            'raw' => ($raw !== false ? (string)$raw : 'Gagal mengirim request Meta Cloud API via stream'),
            'request' => $request,
        ];
    }
}
