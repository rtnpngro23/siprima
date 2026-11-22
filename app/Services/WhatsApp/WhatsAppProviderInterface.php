<?php
declare(strict_types=1);

namespace Siprima\Services\WhatsApp;

interface WhatsAppProviderInterface
{
    /**
     * Nama kanonis provider (mis. 'fonnte', 'meta_cloud')
     */
    public function getName(): string;

    /**
     * Apakah kredensial dan endpoint wajib untuk provider ini sudah terisi
     */
    public function isConfigured(): bool;

    /**
     * Apakah kunci API / Access Token tersedia (tanpa mengekspos nilainya)
     */
    public function hasApiKey(): bool;

    /**
     * Apakah endpoint URL / Phone Number ID tersedia
     */
    public function hasEndpoint(): bool;

    /**
     * Daftar checklist konfigurasi .env khusus provider ini untuk tampilan Admin
     *
     * @return array<int, array{key: string, label: string, configured: bool, value_display: string, description: string}>
     */
    public function getConfigurationChecklist(): array;

    /**
     * Membentuk struktur HTTP request sesuai kontrak API provider
     *
     * @param array<string, mixed> $templateParams
     * @return array{url: string, headers: array<int, string>, body: mixed, content_type: string, meta: array<string, mixed>}
     */
    public function buildRequest(string $phone, string $message, string $eventType, array $templateParams = []): array;

    /**
     * Memverifikasi apakah respons HTTP dari provider benar-benar mengonfirmasi penerimaan pesan
     *
     * @return array{accepted: bool, note: string, provider_message_id: ?string}
     */
    public function verifyAcceptance(int $httpCode, string $rawBody): array;

    /**
     * Mengeksekusi pengiriman request ke provider (atau mock $httpSender saat pengujian)
     *
     * @param array<string, mixed> $templateParams
     * @return array{http_code: int, raw: string, request: array<string, mixed>}
     */
    public function send(
        string $phone,
        string $message,
        string $eventType,
        array $templateParams = [],
        ?callable $httpSender = null
    ): array;
}
