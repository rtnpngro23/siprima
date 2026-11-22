<?php
declare(strict_types=1);

namespace Siprima\Services;

use mysqli;
use Throwable;

final class WhatsAppService
{
    private mysqli $db;

    /** @var callable|null Optional transport override for automated testing */
    private $httpSender = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->ensureTable();
    }

    /**
     * Set custom HTTP transport handler (untuk kebutuhan pengujian otomatis)
     */
    public function setHttpSender(?callable $sender): void
    {
        $this->httpSender = $sender;
    }

    /**
     * Pastikan tabel log notifikasi WhatsApp tersedia
     */
    public function ensureTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `tb_whatsapp_log` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `id_user` INT NULL,
            `no_hp` VARCHAR(30) NOT NULL,
            `permohonan_id` INT NULL,
            `source_table` VARCHAR(50) NOT NULL DEFAULT 'tb_pengajuan_kia',
            `event_type` VARCHAR(50) NOT NULL,
            `pesan` TEXT NOT NULL,
            `status` ENUM('sent', 'failed', 'skipped', 'pending') NOT NULL DEFAULT 'pending',
            `response_raw` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_wa_user` (`id_user`),
            KEY `idx_wa_event` (`permohonan_id`, `event_type`),
            KEY `idx_wa_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        try {
            @$this->db->query($sql);
        } catch (Throwable) {
            // Non-blocking: lewati jika koneksi berstatus read-only atau DDL dibatasi
        }
    }

    /**
     * Normalisasi nomor telepon ke format internasional (misal: 0812... -> 62812...)
     */
    public static function formatNomorHp(string $phone): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if ($clean === '') {
            return '';
        }
        if (str_starts_with($clean, '08')) {
            $clean = '62' . substr($clean, 1);
        } elseif (str_starts_with($clean, '8')) {
            $clean = '62' . $clean;
        } elseif (str_starts_with($clean, '+62')) {
            $clean = substr($clean, 1);
        }
        return $clean;
    }

    /**
     * Periksa apakah notifikasi untuk event tertentu sudah pernah terkirim (idempotency)
     */
    public function isAlreadySent(int $permohonanId, string $eventType, int $excludeLogId = 0): bool
    {
        if ($excludeLogId > 0) {
            $stmt = $this->db->prepare(
                "SELECT id FROM tb_whatsapp_log 
                 WHERE permohonan_id = ? AND event_type = ? AND status = 'sent' AND id <> ? 
                 LIMIT 1"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('isi', $permohonanId, $eventType, $excludeLogId);
        } else {
            $stmt = $this->db->prepare(
                "SELECT id FROM tb_whatsapp_log 
                 WHERE permohonan_id = ? AND event_type = ? AND status = 'sent' 
                 LIMIT 1"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('is', $permohonanId, $eventType);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = ($res && $res->num_rows > 0);
        $stmt->close();
        return $exists;
    }

    /**
     * Sanitisasi catatan kegagalan/respons agar aman disimpan dan tidak membocorkan token atau NIK/KK
     */
    public static function sanitizeResponseNote(?string $raw): string
    {
        $text = trim((string)$raw);
        if ($text === '') {
            return '';
        }
        $text = (string)preg_replace('/\b\d{16}\b/', '[DATA TERLINDUNGI]', $text);
        $text = (string)preg_replace('/\bAuthorization\s*:\s*(?:Bearer\s+|Basic\s+)?[^\s"\',}]+/i', 'Authorization: [DISAMARKAN]', $text);
        $text = (string)preg_replace('/\bBearer\s+[A-Za-z0-9\-_\.=]+/i', 'Bearer [DISAMARKAN]', $text);
        if (function_exists('env')) {
            $apiKey = trim((string)env('WA_API_KEY', ''));
            if ($apiKey !== '' && strlen($apiKey) >= 6) {
                $text = str_replace($apiKey, '[KUNCI DISAMARKAN]', $text);
            }
        }
        if (mb_strlen($text, 'UTF-8') > 1000) {
            $text = mb_substr($text, 0, 997, 'UTF-8') . '...';
        }
        return $text;
    }

    /**
     * Resolusi konfigurasi WhatsApp Gateway dari environment
     */
    private function resolveGatewayConfig(): array
    {
        if (!function_exists('env')) {
            require_once __DIR__ . '/../config/env_loader.php';
        }
        $enabled = filter_var(env('WA_GATEWAY_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $provider = strtolower(trim((string)env('WA_GATEWAY_PROVIDER', 'cloud_api')));
        $apiUrl = trim((string)env('WA_GATEWAY_URL', ''));
        $apiKey = trim((string)env('WA_API_KEY', ''));
        $phoneNumberId = trim((string)env('WA_PHONE_NUMBER_ID', ''));

        if ($apiUrl === '' && in_array($provider, ['meta', 'cloud_api', 'whatsapp_cloud'], true) && $phoneNumberId !== '') {
            $apiUrl = "https://graph.facebook.com/v19.0/{$phoneNumberId}/messages";
        }

        $hasCredentials = ($apiKey !== '' && $apiUrl !== '');
        return [
            'enabled' => $enabled,
            'provider' => $provider !== '' ? $provider : 'cloud_api',
            'api_url' => $apiUrl,
            'api_key' => $apiKey,
            'phone_number_id' => $phoneNumberId,
            'has_api_key' => $apiKey !== '',
            'has_gateway_url' => $apiUrl !== '',
            'configured' => $hasCredentials,
            'ready' => ($enabled && $hasCredentials),
        ];
    }

    /**
     * Verifikasi penerima KHUSUS Pemohon (role = 'user') dan persetujuan WhatsApp (wa_consent)
     */
    private function validatePemohonRecipient(?int $userId, string $fallbackPhone = ''): array
    {
        $resolvedPhone = $fallbackPhone;
        if ($userId !== null && $userId > 0) {
            $stmtU = $this->db->prepare("SELECT role, no_hp, COALESCE(wa_consent, 1) AS wa_consent FROM tb_users WHERE id = ? LIMIT 1");
            if ($stmtU) {
                $stmtU->bind_param('i', $userId);
                $stmtU->execute();
                $uRow = $stmtU->get_result()->fetch_assoc();
                $stmtU->close();
                if ($uRow) {
                    $roleUser = strtolower(trim((string)($uRow['role'] ?? '')));
                    if ($roleUser !== 'user') {
                        return [
                            'allowed' => false,
                            'status' => 'skipped',
                            'phone' => self::formatNomorHp($fallbackPhone) ?: '-',
                            'note' => "Dilewati: Notifikasi WhatsApp hanya untuk Pemohon (role={$roleUser})",
                            'message' => 'WhatsApp hanya dikirim kepada Pemohon.',
                        ];
                    }
                    if ((int)($uRow['wa_consent'] ?? 1) === 0) {
                        return [
                            'allowed' => false,
                            'status' => 'skipped',
                            'phone' => self::formatNomorHp($fallbackPhone) ?: '-',
                            'note' => 'Dilewati: Pemohon belum/tidak menyetujui notifikasi WhatsApp',
                            'message' => 'Pemohon tidak menyetujui notifikasi WhatsApp.',
                        ];
                    }
                    $dbPhone = trim((string)($uRow['no_hp'] ?? ''));
                    if (trim($resolvedPhone) === '' || trim($resolvedPhone) === '-') {
                        $resolvedPhone = $dbPhone;
                    }
                }
            }
        }

        $formattedPhone = self::formatNomorHp($resolvedPhone);
        if ($formattedPhone === '' || strlen($formattedPhone) < 10 || strlen($formattedPhone) > 15) {
            return [
                'allowed' => false,
                'status' => 'failed',
                'phone' => $resolvedPhone,
                'note' => 'Nomor telepon pemohon kosong atau tidak valid (harus 10-15 digit).',
                'message' => 'Nomor WhatsApp pemohon tidak valid.',
            ];
        }

        return [
            'allowed' => true,
            'status' => 'ok',
            'phone' => $formattedPhone,
            'note' => '',
            'message' => '',
        ];
    }

    /**
     * Pastikan status 'sent' hanya diberikan setelah provider mengonfirmasi pesan diterima untuk diproses
     */
    public static function verifyProviderAcceptance(int $httpCode, string $rawBody, string $provider = 'generic'): array
    {
        $trimmed = trim($rawBody);
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'accepted' => false,
                'note' => self::sanitizeResponseNote("HTTP {$httpCode}: " . ($trimmed !== '' ? $trimmed : 'Respons gagal dari provider')),
            ];
        }

        $providerLower = strtolower(trim($provider));
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            if (!empty($decoded['error'])) {
                $errDetail = is_array($decoded['error'])
                    ? (string)($decoded['error']['message'] ?? json_encode($decoded['error']))
                    : (string)$decoded['error'];
                return [
                    'accepted' => false,
                    'note' => self::sanitizeResponseNote('Provider menolak pesan: ' . $errDetail),
                ];
            }
            if ((array_key_exists('status', $decoded) && $decoded['status'] === false)
                || (array_key_exists('success', $decoded) && $decoded['success'] === false)) {
                $reason = (string)($decoded['reason'] ?? $decoded['message'] ?? $trimmed);
                return [
                    'accepted' => false,
                    'note' => self::sanitizeResponseNote('Provider melaporkan kegagalan: ' . $reason),
                ];
            }
            if (in_array($providerLower, ['meta', 'cloud_api', 'whatsapp_cloud'], true)) {
                $hasMsgId = !empty($decoded['messages'][0]['id']);
                if (!$hasMsgId) {
                    return [
                        'accepted' => false,
                        'note' => self::sanitizeResponseNote('Konfirmasi message_id Meta Cloud API tidak ditemukan: ' . $trimmed),
                    ];
                }
            } elseif ($providerLower === 'fonnte') {
                if (empty($decoded['status'])) {
                    return [
                        'accepted' => false,
                        'note' => self::sanitizeResponseNote('Konfirmasi status sukses Fonnte tidak ditemukan: ' . $trimmed),
                    ];
                }
            }
            return [
                'accepted' => true,
                'note' => self::sanitizeResponseNote($trimmed),
            ];
        }

        if ($trimmed === '') {
            return [
                'accepted' => false,
                'note' => "HTTP {$httpCode}: Respons provider kosong tanpa konfirmasi penerimaan.",
            ];
        }

        if (stripos($trimmed, 'error') !== false && stripos($trimmed, 'no error') === false) {
            return [
                'accepted' => false,
                'note' => self::sanitizeResponseNote("HTTP {$httpCode}: " . $trimmed),
            ];
        }

        return [
            'accepted' => true,
            'note' => self::sanitizeResponseNote($trimmed),
        ];
    }

    /**
     * Kirim notifikasi WhatsApp dengan penanganan error non-blocking
     */
    public function kirimNotifikasi(
        ?int $userId,
        string $noHp,
        int $permohonanId,
        string $eventType,
        string $pesan,
        string $sourceTable = 'tb_pengajuan_kia'
    ): array {
        try {
            if (trim($pesan) === '') {
                $this->catatLog($userId, $noHp, $permohonanId, $sourceTable, $eventType, $pesan, 'failed', 'Isi pesan WhatsApp tidak boleh kosong');
                return ['success' => false, 'status' => 'failed', 'error' => 'Pesan WhatsApp kosong.'];
            }

            // Sanitisasi keamanan: pastikan tidak ada deretan 16 digit NIK/KK di pesan WhatsApp
            $pesan = (string)preg_replace('/\b\d{16}\b/', '[DATA TERLINDUNGI]', $pesan);

            $recipient = $this->validatePemohonRecipient($userId, $noHp);
            if (!$recipient['allowed']) {
                $this->catatLog($userId, $recipient['phone'], $permohonanId, $sourceTable, $eventType, $pesan, $recipient['status'], $recipient['note']);
                if ($recipient['status'] === 'skipped') {
                    return ['success' => true, 'skipped' => true, 'status' => 'skipped', 'message' => $recipient['message']];
                }
                return ['success' => false, 'status' => 'failed', 'error' => $recipient['message']];
            }
            $formattedPhone = $recipient['phone'];

            // Hindari pengiriman ganda untuk event yang sama
            if ($this->isAlreadySent($permohonanId, $eventType)) {
                $this->catatLog($userId, $formattedPhone, $permohonanId, $sourceTable, $eventType, $pesan, 'skipped', 'Notifikasi untuk event ini sudah pernah terkirim sebelumnya.');
                return ['success' => true, 'skipped' => true, 'status' => 'skipped', 'message' => 'Notifikasi sudah pernah terkirim.'];
            }

            // Cek konfigurasi environment
            $config = $this->resolveGatewayConfig();
            if (!$config['ready']) {
                $note = !$config['configured']
                    ? 'WA_API_KEY atau WA_GATEWAY_URL/WA_PHONE_NUMBER_ID belum dikonfigurasi di app/config/.env'
                    : 'WA_GATEWAY_ENABLED masih nonaktif di app/config/.env';
                $this->catatLog($userId, $formattedPhone, $permohonanId, $sourceTable, $eventType, $pesan, 'skipped', $note);
                return ['success' => true, 'skipped' => true, 'status' => 'skipped', 'message' => 'Gateway WhatsApp belum diaktifkan di konfigurasi.'];
            }

            // Kirim request ke gateway WhatsApp dan verifikasi konfirmasi penerimaan dari provider
            $response = $this->dispatchHttpRequest($config['api_url'], $config['api_key'], $formattedPhone, $pesan, $config['provider']);
            $acceptance = self::verifyProviderAcceptance(
                (int)($response['http_code'] ?? 0),
                (string)($response['raw'] ?? ''),
                $config['provider']
            );
            $isSuccess = $acceptance['accepted'];
            $finalStatus = $isSuccess ? 'sent' : 'failed';

            $this->catatLog(
                $userId,
                $formattedPhone,
                $permohonanId,
                $sourceTable,
                $eventType,
                $pesan,
                $finalStatus,
                $acceptance['note']
            );

            return [
                'success' => $isSuccess,
                'status' => $finalStatus,
                'http_code' => $response['http_code'] ?? 0,
                'response' => $acceptance['note']
            ];
        } catch (Throwable $e) {
            // Catat kegagalan tanpa menghentikan proses utama
            @$this->catatLog($userId, $noHp, $permohonanId, $sourceTable, $eventType, $pesan, 'failed', self::sanitizeResponseNote('Exception: ' . $e->getMessage()));
            return ['success' => false, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * Coba kirim ulang satu catatan notifikasi yang gagal atau tertunda/dilewati
     */
    public function retryNotifikasi(int $logId): array
    {
        if ($logId <= 0) {
            return ['success' => false, 'status' => 'invalid', 'message' => 'ID catatan notifikasi tidak valid.'];
        }

        $stmt = $this->db->prepare("SELECT * FROM tb_whatsapp_log WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return ['success' => false, 'status' => 'error', 'message' => 'Gagal menyiapkan query riwayat WhatsApp.'];
        }
        $stmt->bind_param('i', $logId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['success' => false, 'status' => 'not_found', 'message' => "Catatan notifikasi #{$logId} tidak ditemukan."];
        }

        $currStatus = strtolower((string)($row['status'] ?? ''));
        if ($currStatus === 'sent') {
            return ['success' => true, 'status' => 'sent', 'message' => "Catatan #{$logId} sudah berstatus terkirim."];
        }

        $userId = isset($row['id_user']) && (int)$row['id_user'] > 0 ? (int)$row['id_user'] : null;
        $permohonanId = (int)($row['permohonan_id'] ?? 0);
        $eventType = (string)($row['event_type'] ?? '');
        $pesan = (string)preg_replace('/\b\d{16}\b/', '[DATA TERLINDUNGI]', (string)($row['pesan'] ?? ''));
        $rawPhone = (string)($row['no_hp'] ?? '');

        if (trim($pesan) === '') {
            $note = 'Isi pesan kosong sehingga tidak dapat dikirim ulang.';
            $this->updateLogEntry($logId, $rawPhone, 'failed', $note);
            return ['success' => false, 'status' => 'failed', 'message' => $note];
        }

        $recipient = $this->validatePemohonRecipient($userId, $rawPhone);
        if (!$recipient['allowed']) {
            $this->updateLogEntry($logId, $recipient['phone'], $recipient['status'], $recipient['note']);
            return ['success' => false, 'status' => $recipient['status'], 'message' => $recipient['message']];
        }
        $formattedPhone = $recipient['phone'];

        if ($permohonanId > 0 && $eventType !== '' && $this->isAlreadySent($permohonanId, $eventType, $logId)) {
            $note = 'Dilewati saat kirim ulang: event yang sama sudah terkirim pada catatan lain.';
            $this->updateLogEntry($logId, $formattedPhone, 'skipped', $note);
            return ['success' => true, 'skipped' => true, 'status' => 'skipped', 'message' => $note];
        }

        $config = $this->resolveGatewayConfig();
        if (!$config['ready']) {
            $note = !$config['configured']
                ? 'Percobaan ulang belum dapat dilakukan: WA_API_KEY atau WA_GATEWAY_URL/WA_PHONE_NUMBER_ID belum diisi di app/config/.env.'
                : 'Percobaan ulang belum dapat dilakukan: WA_GATEWAY_ENABLED masih nonaktif di app/config/.env.';
            $nextStatus = $currStatus === 'failed' ? 'failed' : 'skipped';
            $this->updateLogEntry($logId, $formattedPhone, $nextStatus, $note);
            return ['success' => false, 'status' => $nextStatus, 'message' => $note];
        }

        $response = $this->dispatchHttpRequest($config['api_url'], $config['api_key'], $formattedPhone, $pesan, $config['provider']);
        $acceptance = self::verifyProviderAcceptance(
            (int)($response['http_code'] ?? 0),
            (string)($response['raw'] ?? ''),
            $config['provider']
        );
        $isSuccess = $acceptance['accepted'];
        $finalStatus = $isSuccess ? 'sent' : 'failed';
        $this->updateLogEntry($logId, $formattedPhone, $finalStatus, $acceptance['note']);

        return [
            'success' => $isSuccess,
            'status' => $finalStatus,
            'http_code' => $response['http_code'] ?? 0,
            'message' => $isSuccess
                ? "Notifikasi #{$logId} berhasil dikirim dan dikonfirmasi provider."
                : "Pengiriman ulang #{$logId} gagal: " . $acceptance['note'],
        ];
    }

    /**
     * Coba kirim ulang antrean notifikasi yang berstatus failed/skipped
     */
    public function retryAllFailedOrSkipped(int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));
        $config = $this->resolveGatewayConfig();
        if (!$config['ready']) {
            return [
                'success' => false,
                'ready' => false,
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'message' => 'Pengiriman ulang massal belum dijalankan karena WA_GATEWAY_ENABLED masih nonaktif atau WA_API_KEY/WA_GATEWAY_URL belum lengkap di app/config/.env.',
            ];
        }

        $stmt = $this->db->prepare("SELECT id FROM tb_whatsapp_log WHERE status IN ('failed', 'skipped') ORDER BY id ASC LIMIT ?");
        if (!$stmt) {
            return ['success' => false, 'ready' => true, 'attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'Gagal membaca antrean gagal.'];
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $stmt->close();

        $summary = ['success' => true, 'ready' => true, 'attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($ids as $id) {
            $summary['attempted']++;
            $resItem = $this->retryNotifikasi($id);
            $st = (string)($resItem['status'] ?? 'failed');
            if ($st === 'sent') {
                $summary['sent']++;
            } elseif ($st === 'skipped') {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
            }
        }
        $summary['message'] = "Diproses {$summary['attempted']} catatan: {$summary['sent']} berhasil dikirim, {$summary['failed']} gagal, {$summary['skipped']} dilewati.";
        return $summary;
    }

    private function dispatchHttpRequest(string $url, string $apiKey, string $phone, string $message, string $provider): array
    {
        if (is_callable($this->httpSender)) {
            $result = call_user_func($this->httpSender, $url, $apiKey, $phone, $message, $provider);
            if (is_array($result)) {
                return [
                    'http_code' => (int)($result['http_code'] ?? 0),
                    'raw' => (string)($result['raw'] ?? ''),
                ];
            }
        }
        return $this->executeHttpRequest($url, $apiKey, $phone, $message, $provider);
    }

    private function updateLogEntry(int $logId, string $noHp, string $status, string $responseNote): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE tb_whatsapp_log SET no_hp = ?, status = ?, response_raw = ?, created_at = NOW() WHERE id = ?");
            if ($stmt) {
                $safeNote = self::sanitizeResponseNote($responseNote);
                $stmt->bind_param('sssi', $noHp, $status, $safeNote, $logId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable) {
            // Silence update error
        }
    }

    /**
     * Eksekusi HTTP request ke penyedia WhatsApp Gateway
     */
    private function executeHttpRequest(string $url, string $apiKey, string $phone, string $message, string $provider): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            if (in_array($provider, ['meta', 'cloud_api', 'whatsapp_cloud'], true)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer {$apiKey}",
                    "Content-Type: application/json"
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => $message]
                ]));
            } elseif ($provider === 'fonnte') {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: {$apiKey}"
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'target' => $phone,
                    'message' => $message,
                    'countryCode' => '62'
                ]);
            } else {
                // Format generic JSON API
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer {$apiKey}",
                    "Content-Type: application/json"
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'phone' => $phone,
                    'message' => $message
                ]));
            }

            $raw = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                return ['http_code' => 500, 'raw' => 'cURL Error: ' . $err];
            }
            return ['http_code' => $httpCode, 'raw' => $raw];
        }

        // Fallback file_get_contents
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: {$apiKey}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query(['target' => $phone, 'message' => $message]),
                'timeout' => 8
            ]
        ]);
        $raw = @file_get_contents($url, false, $context);
        return [
            'http_code' => ($raw !== false ? 200 : 500),
            'raw' => ($raw !== false ? $raw : 'Gagal mengirim request via file_get_contents')
        ];
    }

    /**
     * Catat log pengiriman notifikasi ke basis data
     */
    private function catatLog(
        ?int $userId,
        string $noHp,
        int $permohonanId,
        string $sourceTable,
        string $eventType,
        string $pesan,
        string $status,
        ?string $rawResponse
    ): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO tb_whatsapp_log 
                 (id_user, no_hp, permohonan_id, source_table, event_type, pesan, status, response_raw, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $safeRaw = $rawResponse !== null ? self::sanitizeResponseNote($rawResponse) : null;
                $stmt->bind_param('isisssss', $userId, $noHp, $permohonanId, $sourceTable, $eventType, $pesan, $status, $safeRaw);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable) {
            // Silence logging error
        }
    }

    /**
     * Ringkasan status konfigurasi & statistik pengiriman untuk halaman Admin (tanpa mengungkap API key)
     */
    public function getIntegrationStatus(): array
    {
        $config = $this->resolveGatewayConfig();

        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0];
        $res = @$this->db->query("SELECT status, COUNT(*) AS cnt FROM tb_whatsapp_log GROUP BY status");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $st = (string)($r['status'] ?? '');
                if (isset($counts[$st])) {
                    $counts[$st] = (int)$r['cnt'];
                }
            }
        }

        return [
            'enabled' => $config['enabled'],
            'configured' => $config['configured'],
            'active' => $config['ready'],
            'provider' => $config['provider'],
            'has_api_key' => $config['has_api_key'],
            'has_phone_number_id' => $config['phone_number_id'] !== '',
            'has_gateway_url' => $config['has_gateway_url'],
            'counts' => $counts,
        ];
    }

    /**
     * Daftar kebutuhan konfigurasi di app/config/.env untuk aktivasi live WhatsApp Gateway (tanpa membocorkan kredensial)
     */
    public function getConfigurationChecklist(): array
    {
        $config = $this->resolveGatewayConfig();
        $isMeta = in_array($config['provider'], ['meta', 'cloud_api', 'whatsapp_cloud'], true);

        return [
            [
                'key' => 'WA_GATEWAY_ENABLED',
                'label' => 'Aktivasi Pengiriman Gateway',
                'configured' => $config['enabled'],
                'value_display' => $config['enabled'] ? 'true (Aktif)' : 'false / kosong (Nonaktif)',
                'description' => 'Atur menjadi true di app/config/.env agar sistem mulai mengirim pesan ke provider.',
            ],
            [
                'key' => 'WA_GATEWAY_PROVIDER',
                'label' => 'Penyedia Layanan Gateway',
                'configured' => $config['provider'] !== '',
                'value_display' => strtoupper($config['provider']),
                'description' => 'Isi dengan fonnte, cloud_api (Meta), atau generic sesuai layanan WhatsApp Gateway yang digunakan.',
            ],
            [
                'key' => 'WA_API_KEY',
                'label' => 'Token / API Key Provider',
                'configured' => $config['has_api_key'],
                'value_display' => $config['has_api_key'] ? 'Tersedia (Disembunyikan)' : 'Belum diisi',
                'description' => 'Diperlukan untuk autentikasi permintaan API ke penyedia WhatsApp Gateway.',
            ],
            [
                'key' => $isMeta ? 'WA_PHONE_NUMBER_ID' : 'WA_GATEWAY_URL',
                'label' => $isMeta ? 'Phone Number ID / URL Cloud API' : 'Endpoint URL Gateway WhatsApp',
                'configured' => $config['has_gateway_url'],
                'value_display' => $config['has_gateway_url'] ? 'Tersedia (' . ($isMeta && $config['phone_number_id'] !== '' ? 'Phone ID diisi' : 'URL Endpoint diisi') . ')' : 'Belum diisi',
                'description' => $isMeta
                    ? 'Isi WA_PHONE_NUMBER_ID atau WA_GATEWAY_URL lengkap untuk Meta WhatsApp Cloud API.'
                    : 'Endpoint HTTP POST penyedia gateway (mis. https://api.fonnte.com/send).',
            ],
        ];
    }

    /**
     * Riwayat pengiriman notifikasi WhatsApp terbaru untuk pemantauan Admin
     */
    public function getRecentLogs(int $limit = 25): array
    {
        return $this->getFilteredLogs([], $limit);
    }

    /**
     * Riwayat pengiriman notifikasi WhatsApp terfilter untuk halaman khusus Admin
     */
    public function getFilteredLogs(array $filters = [], int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $where = [];
        $params = [];
        $types = '';

        $status = strtolower(trim((string)($filters['status'] ?? '')));
        if (in_array($status, ['sent', 'failed', 'skipped', 'pending'], true)) {
            $where[] = 'l.status = ?';
            $params[] = $status;
            $types .= 's';
        }

        $eventType = trim((string)($filters['event_type'] ?? ''));
        if ($eventType !== '') {
            $where[] = 'l.event_type = ?';
            $params[] = $eventType;
            $types .= 's';
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $qNumeric = ltrim($q, '#');
            if ($qNumeric !== '' && ctype_digit($qNumeric)) {
                $idExact = (int)$qNumeric;
                $where[] = '(COALESCE(u.nama, "") LIKE ? OR l.event_type LIKE ? OR l.permohonan_id = ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $idExact;
                $types .= 'ssi';
            } else {
                $where[] = '(COALESCE(u.nama, "") LIKE ? OR l.event_type LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $types .= 'ss';
            }
        }

        $sql = "SELECT l.id, l.id_user, u.nama AS nama_penerima, u.role AS role_penerima, l.no_hp, l.permohonan_id, l.event_type, l.status, l.response_raw, l.created_at
                FROM tb_whatsapp_log l
                LEFT JOIN tb_users u ON l.id_user = u.id";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY l.id DESC LIMIT {$limit}";

        $stmt = @$this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /**
     * Daftar jenis peristiwa yang tersedia pada log untuk dropdown filter
     */
    public function getDistinctEventTypes(): array
    {
        $res = @$this->db->query("SELECT DISTINCT event_type FROM tb_whatsapp_log WHERE event_type IS NOT NULL AND event_type <> '' ORDER BY event_type ASC");
        if (!$res) {
            return [];
        }
        $events = [];
        while ($row = $res->fetch_assoc()) {
            $ev = trim((string)($row['event_type'] ?? ''));
            if ($ev !== '') {
                $events[] = $ev;
            }
        }
        return $events;
    }

    /**
     * Format kode jenis peristiwa ke label Bahasa Indonesia yang mudah dibaca
     */
    public static function formatEventLabel(string $eventType): string
    {
        $clean = trim($eventType);
        if ($clean === '') {
            return '-';
        }
        $map = [
            'kia_diajukan' => 'Pengajuan KIA Berhasil Dikirim',
            'kia_selesai' => 'Pengajuan KIA Selesai',
            'kia_selesai_dicetak' => 'Kartu Fisik KIA Selesai Dicetak',
            'kia_pdf_tersedia' => 'Dokumen PDF Hasil KIA Tersedia',
            'status_diajukan' => 'Permohonan KIA Diajukan',
            'status_diproses' => 'Permohonan KIA Diproses',
            'status_ditolak' => 'Permohonan KIA Perlu Perbaikan / Ditolak',
            'status_selesai' => 'Permohonan KIA Selesai',
            'laporan_kesalahan_ditindaklanjuti' => 'Tindak Lanjut Laporan Kesalahan KIA',
            'komentar_operator' => 'Balasan / Komentar Operator',
            'cetak_sedang_dicetak' => 'Kartu Fisik KIA Sedang Dicetak',
            'cetak_selesai_dicetak' => 'Kartu Fisik KIA Selesai Dicetak',
        ];
        if (isset($map[$clean])) {
            return $map[$clean];
        }
        if (str_starts_with($clean, 'kia_diajukan_ulang')) {
            return 'Perbaikan Pengajuan KIA Dikirim Ulang';
        }
        if (str_starts_with($clean, 'kia_ditolak')) {
            return 'Pengajuan KIA Perlu Perbaikan / Ditolak';
        }
        if (str_starts_with($clean, 'kia_pdf_pengganti')) {
            return 'Dokumen PDF KIA Pengganti Tersedia';
        }
        if (str_starts_with($clean, 'kia_pdf_tersedia')) {
            return 'Dokumen PDF Hasil KIA Tersedia';
        }
        if (str_starts_with($clean, 'kia_balasan_laporan')) {
            return str_ends_with($clean, '_selesai')
                ? 'Laporan Kesalahan KIA Diselesaikan Operator'
                : 'Operator Membalas Laporan Kesalahan KIA';
        }
        return ucwords(str_replace('_', ' ', $clean));
    }
}
