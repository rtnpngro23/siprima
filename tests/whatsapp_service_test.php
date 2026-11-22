<?php
declare(strict_types=1);

/**
 * Automated Unit & Mock Integration Test Suite for SIPRIMA Multi-Provider WhatsApp Service
 * (Meta Cloud API + Fonnte + wa_consent + KIA Triggers + Security)
 *
 * TIDAK mengirim pesan WhatsApp sungguhan dan TIDAK menggunakan nomor pribadi.
 */

require_once __DIR__ . '/../app/config/service_hours.php';
require_once __DIR__ . '/../app/Services/WhatsAppService.php';

use Siprima\Services\WhatsApp\Providers\FonnteProvider;
use Siprima\Services\WhatsApp\Providers\MetaCloudProvider;
use Siprima\Services\WhatsAppService;

$passed = 0;
$failed = 0;

function wa_assert(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
}

// ---------------------------------------------------------------------------
// 1. Fonnte Provider Compatibility Tests
// ---------------------------------------------------------------------------
$fonnte = new FonnteProvider('https://api.fonnte.com/send', 'mock_fonnte_token_xyz', true);
wa_assert($fonnte->getName() === 'fonnte', '1a. FonnteProvider nama kanonis = fonnte');
wa_assert($fonnte->isConfigured() === true, '1b. FonnteProvider isConfigured() true saat token & URL terisi');

$fonnteReq = $fonnte->buildRequest('6280000000001', 'Halo Pemohon, KIA #999 selesai.', 'kia_selesai');
wa_assert($fonnteReq['url'] === 'https://api.fonnte.com/send', '1c. FonnteProvider endpoint URL benar');
wa_assert(in_array('Authorization: mock_fonnte_token_xyz', $fonnteReq['headers'], true), '1d. FonnteProvider header Authorization sesuai format Fonnte');
wa_assert(($fonnteReq['body']['target'] ?? '') === '6280000000001' && ($fonnteReq['body']['countryCode'] ?? '') === '62', '1e. FonnteProvider body target & countryCode benar');

$fonnteOk = $fonnte->verifyAcceptance(200, '{"status":true,"id":["fn_12345"]}');
wa_assert($fonnteOk['accepted'] === true && $fonnteOk['provider_message_id'] === 'fn_12345', '1f. FonnteProvider menerima respons status=true');

$fonnteErr = $fonnte->verifyAcceptance(200, '{"status":false,"reason":"invalid token"}');
wa_assert($fonnteErr['accepted'] === false, '1g. FonnteProvider menolak respons status=false');

// ---------------------------------------------------------------------------
// 2. Meta Cloud API Provider Request & Template Format Tests
// ---------------------------------------------------------------------------
$meta = new MetaCloudProvider(
    accessToken: 'mock_meta_bearer_token_abc123',
    phoneNumberId: '100200300400500',
    apiVersion: 'v22.0',
    wabaId: '900800700600500',
    templateLang: 'id',
    sslVerify: true
);

wa_assert($meta->getName() === 'meta_cloud', '2a. MetaCloudProvider nama kanonis = meta_cloud');
wa_assert($meta->isConfigured() === true, '2b. MetaCloudProvider isConfigured() true saat token & Phone Number ID terisi');
wa_assert($meta->isSslVerifyEnabled() === true, '2c. MetaCloudProvider CURLOPT_SSL_VERIFYPEER aktif secara default');
wa_assert(
    $meta->getEndpointUrl() === 'https://graph.facebook.com/v22.0/100200300400500/messages',
    '2d. MetaCloudProvider endpoint Graph API dinamis sesuai WA_META_API_VERSION & WA_PHONE_NUMBER_ID'
);

$metaReq = $meta->buildRequest(
    '6280000000002',
    'Halo Budi, permohonan KIA #888 berhasil diajukan.',
    'kia_diajukan',
    ['nama' => 'Budi 7501010101010001', 'permohonan_id' => 888]
);
wa_assert(in_array('Authorization: Bearer mock_meta_bearer_token_abc123', $metaReq['headers'], true), '2e. MetaCloudProvider menggunakan header Authorization: Bearer');
wa_assert(in_array('Content-Type: application/json', $metaReq['headers'], true), '2f. MetaCloudProvider menggunakan Content-Type: application/json');

$metaJson = json_decode((string)$metaReq['body'], true);
wa_assert(is_array($metaJson), '3a. MetaCloudProvider body berupa JSON valid');
wa_assert(($metaJson['messaging_product'] ?? '') === 'whatsapp', '3b. Meta payload messaging_product = whatsapp');
wa_assert(($metaJson['recipient_type'] ?? '') === 'individual', '3c. Meta payload recipient_type = individual');
wa_assert(($metaJson['to'] ?? '') === '6280000000002', '3d. Meta payload to = format E.164 tanpa tanda +');
wa_assert(($metaJson['type'] ?? '') === 'template', '3e. Meta payload menggunakan type = template (approved template)');
wa_assert(($metaJson['template']['name'] ?? '') === 'siprima_kia_diajukan', '3f. Meta payload nama template sesuai event kia_diajukan');
wa_assert(($metaJson['template']['language']['code'] ?? '') === 'id', '3g. Meta payload bahasa template = id');
$bodyParams = $metaJson['template']['components'][0]['parameters'] ?? [];
wa_assert(count($bodyParams) === 2, '3h. Meta payload memiliki 2 parameter body');
wa_assert(!str_contains((string)($bodyParams[0]['text'] ?? ''), '7501010101010001'), '3i. Meta parameter template menyamarkan 16 digit NIK/KK');
wa_assert(($bodyParams[1]['text'] ?? '') === '#888', '3j. Meta parameter kedua memuat referensi permohonan #888');

// Pemetaan seluruh 6+ event KIA ke template Meta
$expectedTemplates = [
    'kia_diajukan' => 'siprima_kia_diajukan',
    'kia_diajukan_ulang_v2' => 'siprima_kia_diajukan',
    'kia_ditolak_v1' => 'siprima_kia_perbaikan',
    'kia_selesai' => 'siprima_kia_selesai',
    'kia_pdf_tersedia_12_v1' => 'siprima_kia_pdf_tersedia',
    'kia_balasan_laporan_5_m9' => 'siprima_kia_tanggapan_laporan',
    'kia_balasan_laporan_5_selesai' => 'siprima_kia_tanggapan_laporan',
    'kia_pdf_pengganti_12_v2' => 'siprima_kia_pdf_pengganti',
    'kia_selesai_dicetak' => 'siprima_kia_siap_diambil',
];
foreach ($expectedTemplates as $ev => $tpl) {
    wa_assert($meta->resolveTemplateName($ev) === $tpl, "3k. Pemetaan template Meta untuk {$ev} -> {$tpl}");
}

// ---------------------------------------------------------------------------
// 4. Meta Acceptance & Error Handling Tests (Token Salah / Kosong / Error Code)
// ---------------------------------------------------------------------------
$metaEmpty = new MetaCloudProvider('', '100200300400500', 'v22.0');
wa_assert($metaEmpty->isConfigured() === false, '4a. MetaCloudProvider dengan token kosong -> isConfigured() = false');

$metaOk = $meta->verifyAcceptance(200, '{"messaging_product":"whatsapp","contacts":[{"input":"6280000000002","wa_id":"6280000000002"}],"messages":[{"id":"wamid.HBgMTest001"}]}');
wa_assert($metaOk['accepted'] === true && $metaOk['provider_message_id'] === 'wamid.HBgMTest001', '4b. MetaCloudProvider menerima respons 200 dengan wamid');

$metaNoId = $meta->verifyAcceptance(200, '{"messaging_product":"whatsapp","messages":[]}');
wa_assert($metaNoId['accepted'] === false, '4c. MetaCloudProvider menolak respons 200 tanpa messages[0].id');

$metaAuthErr = $meta->verifyAcceptance(401, '{"error":{"message":"Invalid OAuth access token","type":"OAuthException","code":190,"error_subcode":463}}');
wa_assert($metaAuthErr['accepted'] === false && str_contains($metaAuthErr['note'], '#190/463'), '4d. MetaCloudProvider menangkap error token salah (#190/463) dengan aman');

$sanitized = WhatsAppService::sanitizeResponseNote('Authorization: Bearer secret_token_999999 NIK 7501010101010001');
wa_assert(!str_contains($sanitized, 'secret_token_999999') && !str_contains($sanitized, '7501010101010001'), '4e. sanitizeResponseNote menyamarkan Bearer token dan 16-digit NIK');

// ---------------------------------------------------------------------------
// 5. Nomor Telepon Validation Tests
// ---------------------------------------------------------------------------
wa_assert(WhatsAppService::formatNomorHp('0812-0000-0001') === '6281200000001', '5a. Normalisasi nomor lokal 0812 -> 62812');
wa_assert(WhatsAppService::formatNomorHp('+62 812 0000 0002') === '6281200000002', '5b. Normalisasi nomor +62 -> 62');
wa_assert(WhatsAppService::formatNomorHp('81200000003') === '6281200000003', '5c. Normalisasi nomor tanpa 0 awal 812 -> 62812');

// ---------------------------------------------------------------------------
// 6 & 7 & 9. DB Integration Tests (wa_consent, role, Meta mock send, idempotency, failure non-blocking)
// Menggunakan transaksi + rollback penuh agar database lokal TIDAK berubah sama sekali.
// ---------------------------------------------------------------------------
$db = service_hours_db();
// Pastikan tabel sudah terinisialisasi SEBELUM memulai transaksi uji
$waService = new WhatsAppService($db, $meta);
$db->begin_transaction();
try {
    // Buat 3 user sintetis di dalam transaksi (di-rollback di akhir)
    $stmtIns = $db->prepare(
        "INSERT INTO tb_users (nama, nik, email, password, role, alamat, no_hp, wa_consent, created_at)
         VALUES (?, ?, ?, 'hash_dummy', ?, 'Alamat Uji', ?, ?, NOW())"
    );
    $n1 = 'Pemohon Setuju Uji'; $nik1 = '9999000000000001'; $em1 = 'wa_test_ok@example.invalid'; $r1 = 'user'; $hp1 = '081200000011'; $c1 = 1;
    $stmtIns->bind_param('sssssi', $n1, $nik1, $em1, $r1, $hp1, $c1);
    $stmtIns->execute();
    $uidConsentYes = (int)$db->insert_id;

    $n2 = 'Pemohon Tolak Uji'; $nik2 = '9999000000000002'; $em2 = 'wa_test_no@example.invalid'; $r2 = 'user'; $hp2 = '081200000012'; $c2 = 0;
    $stmtIns->bind_param('sssssi', $n2, $nik2, $em2, $r2, $hp2, $c2);
    $stmtIns->execute();
    $uidConsentNo = (int)$db->insert_id;

    $n3 = 'Operator Uji'; $nik3 = '9999000000000003'; $em3 = 'wa_test_op@example.invalid'; $r3 = 'operator'; $hp3 = '081200000013'; $c3 = 1;
    $stmtIns->bind_param('sssssi', $n3, $nik3, $em3, $r3, $hp3, $c3);
    $stmtIns->execute();
    $uidOperator = (int)$db->insert_id;
    $stmtIns->close();

    // Aktifkan WA_GATEWAY_ENABLED sementara di proses CLI test saja
    $_ENV['WA_GATEWAY_ENABLED'] = 'true';
    putenv('WA_GATEWAY_ENABLED=true');

    $httpCalls = 0;
    $lastCapturedRequest = null;

    $waService->setHttpSender(function (string $url, string $token, string $phone, string $msg, string $provider, array $request = []) use (&$httpCalls, &$lastCapturedRequest): array {
        $httpCalls++;
        $lastCapturedRequest = $request;
        return [
            'http_code' => 200,
            'raw' => '{"messaging_product":"whatsapp","messages":[{"id":"wamid.HBgMOCK999"}]}',
        ];
    });

    // 6a. Pemohon tanpa persetujuan (wa_consent = 0) -> skipped, 0 HTTP calls
    $resNoConsent = $waService->kirimNotifikasi($uidConsentNo, $hp2, 990001, 'kia_diajukan', 'Halo Pemohon Tolak, KIA #990001 diajukan.');
    wa_assert(($resNoConsent['status'] ?? '') === 'skipped' && $httpCalls === 0, '6a. Pemohon dengan wa_consent=0 dilewati tanpa memanggil HTTP API');

    // 6b. Role non-pemohon (operator) -> skipped, 0 HTTP calls
    $resOp = $waService->kirimNotifikasi($uidOperator, $hp3, 990002, 'kia_diajukan', 'Halo Operator');
    wa_assert(($resOp['status'] ?? '') === 'skipped' && $httpCalls === 0, '6b. Role operator ditolak/dilewati (khusus Pemohon)');

    // 6c. Nomor HP tidak valid -> failed, 0 HTTP calls
    $resBadPhone = $waService->kirimNotifikasi($uidConsentYes, '08123', 990003, 'kia_diajukan', 'Halo Pemohon');
    wa_assert(($resBadPhone['status'] ?? '') === 'failed' && $httpCalls === 0, '6c. Nomor HP tidak valid (< 10 digit) ditolak sebelum panggilan HTTP');

    // 7a. Pemohon menyetujui (wa_consent = 1) -> masuk proses Meta Cloud API mock -> sent
    $resOk = $waService->kirimNotifikasi($uidConsentYes, $hp1, 990004, 'kia_selesai', 'Halo Pemohon Setuju, KIA #990004 selesai.');
    wa_assert(($resOk['status'] ?? '') === 'sent' && ($resOk['provider'] ?? '') === 'meta_cloud' && $httpCalls === 1, '7a. Pemohon wa_consent=1 berhasil masuk pengiriman Meta Cloud API (mock)');
    wa_assert(($lastCapturedRequest['meta']['template_name'] ?? '') === 'siprima_kia_selesai', '7b. Request Meta mock memakai template siprima_kia_selesai');

    // 7c. Idempotensi: event yang sama tidak dikirim ulang
    $resDup = $waService->kirimNotifikasi($uidConsentYes, $hp1, 990004, 'kia_selesai', 'Halo Pemohon Setuju, KIA #990004 selesai.');
    wa_assert(($resDup['status'] ?? '') === 'skipped' && $httpCalls === 1, '7c. Pengiriman ganda event sama dicegah oleh idempotency check');

    // 7d. Provider switching ke Fonnte melalui setProvider() tanpa mengubah caller
    $waService->setProvider($fonnte);
    $waService->setHttpSender(function () use (&$httpCalls): array {
        $httpCalls++;
        return ['http_code' => 200, 'raw' => '{"status":true,"id":["fn_ok_1"]}'];
    });
    $resFonnte = $waService->kirimNotifikasi($uidConsentYes, $hp1, 990005, 'kia_pdf_tersedia', 'Halo Pemohon Setuju, PDF KIA #990005 tersedia.');
    wa_assert(($resFonnte['status'] ?? '') === 'sent' && ($resFonnte['provider'] ?? '') === 'fonnte', '7d. Switching provider ke Fonnte bekerja tanpa mengubah pemanggil');

    // 9. Kegagalan API / Exception tidak membatalkan proses utama (non-blocking)
    $waService->setProvider($meta);
    $waService->setHttpSender(function (): array {
        throw new RuntimeException('Simulated network timeout');
    });
    $resFail = $waService->kirimNotifikasi($uidConsentYes, $hp1, 990006, 'kia_ditolak_v1', 'Halo Pemohon Setuju, KIA #990006 perlu perbaikan.');
    wa_assert(($resFail['status'] ?? '') === 'failed' && ($resFail['success'] ?? true) === false, '9. Exception/timeout API ditangkap aman secara non-blocking');
} finally {
    $db->rollback();
}

// ---------------------------------------------------------------------------
// 8 & 10. Static Verification: 6 KIA Triggers Post-Commit, Security & No Webhook
// ---------------------------------------------------------------------------
$kiaModelSrc = file_get_contents(__DIR__ . '/../app/Models/KiaModel.php');
$requiredEvents = [
    'kia_diajukan',
    'kia_ditolak_v',
    'kia_selesai',
    'kia_pdf_tersedia',
    'kia_balasan_laporan_',
    'kia_pdf_pengganti_',
];
foreach ($requiredEvents as $evKey) {
    wa_assert(str_contains((string)$kiaModelSrc, $evKey), "8a. KiaModel.php tetap memicu event {$evKey}");
}

$waServiceSrc = file_get_contents(__DIR__ . '/../app/Services/WhatsAppService.php');
$metaSrc = file_get_contents(__DIR__ . '/../app/Services/WhatsApp/Providers/MetaCloudProvider.php');
$fonnteSrc = file_get_contents(__DIR__ . '/../app/Services/WhatsApp/Providers/FonnteProvider.php');
wa_assert(!str_contains((string)$waServiceSrc, 'CURLOPT_SSL_VERIFYPEER, false'), '10a. Tidak ada CURLOPT_SSL_VERIFYPEER=false di WhatsAppService');
wa_assert(!str_contains((string)$metaSrc, 'CURLOPT_SSL_VERIFYPEER, false'), '10b. Tidak ada CURLOPT_SSL_VERIFYPEER=false di MetaCloudProvider');
wa_assert(!str_contains((string)$fonnteSrc, 'CURLOPT_SSL_VERIFYPEER, false'), '10c. Tidak ada CURLOPT_SSL_VERIFYPEER=false di FonnteProvider');
wa_assert(!file_exists(__DIR__ . '/../public/api/v1/whatsapp_webhook.php'), '10d. Tidak ada endpoint webhook sementara yang dibuat (sesuai keputusan DITUNDA)');

$profilSrc = file_get_contents(__DIR__ . '/../public/user/profil.php');
wa_assert(
    str_contains((string)$profilSrc, "action === 'update_wa_consent'") && str_contains((string)$profilSrc, 'sukarela'),
    '10e. UI persetujuan sukarela wa_consent terpisah dari edit profil di public/user/profil.php'
);

echo "\nSUMMARY: passed={$passed}, failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
