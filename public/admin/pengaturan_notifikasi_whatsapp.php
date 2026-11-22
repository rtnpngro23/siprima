<?php
require_once '../error_handler.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../app/middleware/admin_only.php';
require_once __DIR__ . '/../../app/config/service_hours.php';
require_once __DIR__ . '/../../app/Services/WhatsAppService.php';

function wa_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wa_mask_phone(?string $phone): string
{
    $clean = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($clean === '') {
        return '-';
    }
    if (strlen($clean) >= 7) {
        return substr($clean, 0, 4) . '****' . substr($clean, -3);
    }
    return $clean;
}

function wa_sanitize_system_note(?string $raw): string
{
    $text = \Siprima\Services\WhatsAppService::sanitizeResponseNote($raw);
    if ($text === '') {
        return '-';
    }
    if (mb_strlen($text, 'UTF-8') > 140) {
        return mb_substr($text, 0, 137, 'UTF-8') . '...';
    }
    return $text;
}

$error = '';
$actionMessage = '';
$actionType = '';
$db = null;
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

$waIntegrationStatus = [
    'enabled' => false,
    'configured' => false,
    'active' => false,
    'provider' => 'cloud_api',
    'has_api_key' => false,
    'has_phone_number_id' => false,
    'has_gateway_url' => false,
    'counts' => ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0],
];
$waChecklist = [];
$waLogs = [];
$eventTypes = [];

$filterStatus = strtolower(trim((string)($_GET['status'] ?? '')));
if (!in_array($filterStatus, ['', 'sent', 'failed', 'skipped', 'pending'], true)) {
    $filterStatus = '';
}
$filterEventType = trim((string)($_GET['event_type'] ?? ''));
$filterQuery = trim((string)($_GET['q'] ?? ''));

try {
    $db = service_hours_db();
    $waService = new \Siprima\Services\WhatsAppService($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrfToken, $postedToken)) {
            $actionMessage = 'Token keamanan tidak valid. Silakan muat ulang halaman dan coba lagi.';
            $actionType = 'error';
        } else {
            $postAction = trim((string)($_POST['action'] ?? ''));
            if ($postAction === 'retry_single') {
                $retryLogId = filter_var($_POST['log_id'] ?? 0, FILTER_VALIDATE_INT);
                if ($retryLogId === false || $retryLogId <= 0) {
                    $actionMessage = 'ID catatan notifikasi yang dipilih tidak valid.';
                    $actionType = 'error';
                } else {
                    $resRetry = $waService->retryNotifikasi((int)$retryLogId);
                    $actionMessage = (string)($resRetry['message'] ?? 'Proses pengiriman ulang selesai.');
                    $actionType = !empty($resRetry['success']) ? 'success' : 'warning';
                }
            } elseif ($postAction === 'retry_batch') {
                $resBatch = $waService->retryAllFailedOrSkipped(15);
                $actionMessage = (string)($resBatch['message'] ?? 'Proses pengiriman ulang massal selesai.');
                $actionType = (!empty($resBatch['ready']) && (int)($resBatch['sent'] ?? 0) > 0) ? 'success' : 'warning';
            }
        }
    }

    $waIntegrationStatus = $waService->getIntegrationStatus();
    $waChecklist = $waService->getConfigurationChecklist();
    $eventTypes = $waService->getDistinctEventTypes();
    $waLogs = $waService->getFilteredLogs([
        'status' => $filterStatus,
        'event_type' => $filterEventType,
        'q' => $filterQuery,
    ], 100);
} catch (Throwable $e) {
    $error = 'Gagal memuat data pemantauan WhatsApp. Hubungi pengelola aplikasi.';
}

$waActive = !empty($waIntegrationStatus['active']);
$waConfigured = !empty($waIntegrationStatus['configured']);
$waEnabled = !empty($waIntegrationStatus['enabled']);
$waCounts = $waIntegrationStatus['counts'] ?? ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0];
$sentCount = (int)($waCounts['sent'] ?? 0);
$failedCount = (int)($waCounts['failed'] ?? 0);
$skippedCount = (int)($waCounts['skipped'] ?? 0);
$pendingCount = (int)($waCounts['pending'] ?? 0);
$totalCount = $sentCount + $failedCount + $skippedCount + $pendingCount;

if ($waActive) {
    $integBadgeClass = 'wa-badge--active';
    $integLabel = 'Aktif &amp; Terhubung';
    $apiBadgeClass = 'wa-badge--active';
    $apiLabel = 'API Terhubung';
    $activBadgeClass = 'wa-badge--active';
    $activLabel = 'Notifikasi Aktif';
} elseif ($waConfigured && !$waEnabled) {
    $integBadgeClass = 'wa-badge--warn';
    $integLabel = 'Nonaktif (Dinonaktifkan Konfigurasi)';
    $apiBadgeClass = 'wa-badge--active';
    $apiLabel = 'Endpoint &amp; Kunci API Tersedia';
    $activBadgeClass = 'wa-badge--warn';
    $activLabel = 'Belum Diaktifkan';
} else {
    $integBadgeClass = 'wa-badge--muted';
    $integLabel = 'Belum Aktif / Belum Dikonfigurasi';
    $apiBadgeClass = !empty($waIntegrationStatus['has_api_key']) && !empty($waIntegrationStatus['has_gateway_url'])
        ? 'wa-badge--active'
        : 'wa-badge--warn';
    $apiLabel = !empty($waIntegrationStatus['has_api_key']) && !empty($waIntegrationStatus['has_gateway_url'])
        ? 'Kredensial Tersedia'
        : 'API Belum Terhubung';
    $activBadgeClass = 'wa-badge--muted';
    $activLabel = 'Belum Aktif (Mode Non-Blokir)';
}

$providerLabel = strtoupper((string)($waIntegrationStatus['provider'] ?? 'cloud_api'));
$hasFilter = ($filterStatus !== '' || $filterEventType !== '' || $filterQuery !== '');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notifikasi WhatsApp - Pengaturan Layanan Admin SI PRIMA</title>
<link rel="icon" href="../assets/prima.webp">
<link rel="stylesheet" href="common.css">
<link rel="stylesheet" href="css/pengaturan_layanan.css?v=20261121-2">
<link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
<script src="../assets/js/theme.js?v=20261118-1"></script>
<style>
.service-settings .wa-checklist-box {
    margin-top: 14px;
    padding: 14px 16px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
}
.service-settings .wa-checklist-title {
    margin: 0 0 10px;
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
}
.service-settings .wa-checklist-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}
.service-settings .wa-check-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
}
.service-settings .wa-check-key {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12.5px;
    font-weight: 700;
    color: #0f172a;
}
.service-settings .wa-check-desc {
    font-size: 12px;
    color: #475569;
    margin-top: 3px;
    line-height: 1.4;
}
.service-settings button.wa-btn-retry {
    padding: 6px 11px;
    min-height: 34px;
    line-height: 1.2;
    font-size: 12.5px;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid #0b6e75;
    background: #ffffff;
    color: #0b6e75;
    cursor: pointer;
    white-space: nowrap;
}
.service-settings button.wa-btn-retry:hover {
    background: #f0fdfa;
    color: #09595f;
}
.service-settings button.wa-btn-batch {
    padding: 8px 14px;
    min-height: 38px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 8px;
    border: 1px solid #0b6e75;
    background: #0b6e75;
    color: #ffffff;
    cursor: pointer;
}
.service-settings button.wa-btn-batch:hover {
    background: #09595f;
    color: #ffffff;
}
.service-settings .notice.warning {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
}
@media (max-width: 820px) {
    .service-settings .wa-checklist-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="logo">Pelayanan Kependudukan</div>
    <nav>
        <ul>
            <li><a href="dashboard_admin.php">Dashboard</a></li>
            <li><a href="manajemen_operator.php">Manajemen Pengguna</a></li>
            <li><a href="reset_password.php">Reset Password Pengguna</a></li>
            <li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li>
            <li><a href="laporan_admin.php">Laporan &amp; Analisis</a></li>
            <li><a href="kinerja_operator.php">Kinerja Operator</a></li>
            <li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
            <li><a href="tanggapan_pemohon.php">Hasil Survei</a></li>
            <li><a href="whistleblowing.php">Whistleblowing</a></li>
            <li><a href="formulir.php">Formulir Kependudukan</a></li>
            <li class="active"><a href="pengaturan_layanan.php" aria-current="page">Pengaturan Layanan</a></li>
            <li class="logout-item">
                <button id="logoutBtn" class="sidebar-btn">Logout</button>
            </li>
        </ul>
    </nav>
</aside>
<button type="button" class="menu-toggle" aria-label="Buka atau tutup menu admin">☰</button>
<main class="main">
    <header class="header">
        <div class="left-header">
            <h1 class="title">Notifikasi WhatsApp Pemohon</h1>
            <p class="settings-subtitle">Pengaturan Layanan &bull; Pemantauan Integrasi &amp; Riwayat Pengiriman WhatsApp</p>
        </div>
        <div class="user-info">
            <span>Hai, <?= wa_escape($_SESSION['nama'] ?? 'Admin') ?></span>
            <button type="button" class="logout" id="logoutBtn2">Logout</button>
        </div>
    </header>
    <div class="content service-settings">
        <?php if ($error): ?><p class="notice error" role="alert"><?= wa_escape($error) ?></p><?php endif; ?>
        <?php if ($actionMessage !== ''): ?>
            <p class="notice <?= $actionType === 'success' ? 'success' : ($actionType === 'error' ? 'error' : 'warning') ?>" role="status">
                <?= wa_escape($actionMessage) ?>
            </p>
        <?php endif; ?>

        <div class="wa-subpage-header">
            <nav class="wa-breadcrumb" aria-label="Navigasi Pengaturan Layanan">
                <a href="pengaturan_layanan.php">Pengaturan Layanan</a>
                <span aria-hidden="true">/</span>
                <strong>Notifikasi WhatsApp</strong>
            </nav>
            <a href="pengaturan_layanan.php" class="btn-back-settings">&larr; Kembali ke Pengaturan Layanan</a>
        </div>

        <section class="panel" aria-labelledby="waStatusHeading">
            <h2 id="waStatusHeading">Status Integrasi &amp; Koneksi API WhatsApp</h2>
            <div class="wa-status-grid">
                <div class="wa-info-box">
                    <div class="wa-info-label">Status Integrasi WhatsApp</div>
                    <div class="wa-info-value">
                        <span class="wa-badge <?= $integBadgeClass ?>"><?= $integLabel ?></span>
                    </div>
                </div>
                <div class="wa-info-box">
                    <div class="wa-info-label">Status Koneksi API (<?= wa_escape($providerLabel) ?>)</div>
                    <div class="wa-info-value">
                        <span class="wa-badge <?= $apiBadgeClass ?>"><?= $apiLabel ?></span>
                    </div>
                    <div class="muted" style="font-size:12.5px;margin-top:6px;">
                        API Key: <strong><?= !empty($waIntegrationStatus['has_api_key']) ? 'Tersedia (Disembunyikan)' : 'Belum Diisi' ?></strong><br>
                        Endpoint / Phone ID: <strong><?= !empty($waIntegrationStatus['has_gateway_url']) ? 'Tersedia' : 'Belum Diisi' ?></strong>
                    </div>
                </div>
                <div class="wa-info-box">
                    <div class="wa-info-label">Status Aktivasi Notifikasi</div>
                    <div class="wa-info-value">
                        <span class="wa-badge <?= $activBadgeClass ?>"><?= $activLabel ?></span>
                    </div>
                </div>
            </div>

            <div class="wa-stats-grid" aria-label="Ringkasan Statistik Pengiriman WhatsApp">
                <div class="wa-info-box">
                    <div class="wa-info-label">Notifikasi Berhasil Dikirim</div>
                    <div class="wa-stat-number" style="color:#166534;"><?= $sentCount ?></div>
                </div>
                <div class="wa-info-box">
                    <div class="wa-info-label">Notifikasi Gagal</div>
                    <div class="wa-stat-number" style="color:#991b1b;"><?= $failedCount ?></div>
                </div>
                <div class="wa-info-box">
                    <div class="wa-info-label">Dilewati / Belum Aktif</div>
                    <div class="wa-stat-number" style="color:#92400e;"><?= $skippedCount ?></div>
                </div>
                <div class="wa-info-box">
                    <div class="wa-info-label">Total Riwayat Pengiriman</div>
                    <div class="wa-stat-number" style="color:#0f172a;"><?= $totalCount ?></div>
                </div>
            </div>

            <?php if (!empty($waChecklist)): ?>
            <div class="wa-checklist-box" aria-label="Kebutuhan Konfigurasi Gateway WhatsApp">
                <p class="wa-checklist-title">Kebutuhan Konfigurasi Aktivasi Gateway (<code>app/config/.env</code>)</p>
                <div class="wa-checklist-grid">
                    <?php foreach ($waChecklist as $chk): ?>
                        <div class="wa-check-item">
                            <div>
                                <div class="wa-check-key"><?= wa_escape($chk['key']) ?></div>
                                <div class="wa-check-desc"><?= wa_escape($chk['description']) ?></div>
                            </div>
                            <span class="wa-badge <?= !empty($chk['configured']) ? 'wa-badge--active' : 'wa-badge--warn' ?>" style="flex-shrink:0;">
                                <?= !empty($chk['configured']) ? 'Siap' : 'Belum Siap' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="wa-policy-note">
                <strong>Ketentuan Penerima &amp; Privasi:</strong> Notifikasi WhatsApp hanya dikirim kepada <strong>Pemohon</strong> (<code>role = user</code> dengan persetujuan aktif) tanpa mengirimkan NIK, Nomor KK, atau lampiran dokumen. Operator dan Admin tetap menggunakan notifikasi internal SIPRIMA dan tidak menerima pesan WhatsApp sistem. Token serta kredensial API tidak pernah ditampilkan di antarmuka web.
            </div>
        </section>

        <section class="panel" aria-labelledby="waHistoryHeading">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                <div>
                    <h2 id="waHistoryHeading" style="margin:0;">Riwayat Pengiriman Notifikasi WhatsApp</h2>
                    <span class="muted" style="font-size:13px;">Menampilkan maksimal 100 catatan terbaru sesuai data aktual</span>
                </div>
                <?php if ($failedCount + $skippedCount > 0): ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= wa_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="retry_batch">
                        <button type="submit" class="wa-btn-batch">
                            Coba Kirim Ulang Antrean Gagal / Tertunda (<?= $failedCount + $skippedCount ?>)
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <form method="get" class="wa-filter-form" aria-label="Filter riwayat notifikasi WhatsApp">
                <label>Status Pengiriman
                    <select name="status">
                        <option value="">Semua Status</option>
                        <option value="sent" <?= $filterStatus === 'sent' ? 'selected' : '' ?>>Berhasil Dikirim</option>
                        <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Gagal</option>
                        <option value="skipped" <?= $filterStatus === 'skipped' ? 'selected' : '' ?>>Dilewati / Belum Aktif</option>
                        <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Menunggu</option>
                    </select>
                </label>
                <label>Jenis Peristiwa
                    <select name="event_type">
                        <option value="">Semua Peristiwa</option>
                        <?php foreach ($eventTypes as $ev): ?>
                            <option value="<?= wa_escape($ev) ?>" <?= $filterEventType === $ev ? 'selected' : '' ?>>
                                <?= wa_escape(\Siprima\Services\WhatsAppService::formatEventLabel($ev)) ?> (<?= wa_escape($ev) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Cari Pemohon / ID Permohonan
                    <input type="search" name="q" value="<?= wa_escape($filterQuery) ?>" placeholder="Nama pemohon atau ID (mis. 90)">
                </label>
                <div class="wa-filter-actions">
                    <button type="submit">Terapkan Filter</button>
                    <?php if ($hasFilter): ?>
                        <a href="pengaturan_notifikasi_whatsapp.php" class="secondary">Reset</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($waLogs)): ?>
                <p class="muted" style="padding:12px 0;">
                    <?= $hasFilter ? 'Tidak ada riwayat notifikasi WhatsApp yang cocok dengan filter.' : 'Belum ada riwayat pengiriman notifikasi WhatsApp.' ?>
                </p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Waktu Pengiriman</th>
                                <th>Pemohon</th>
                                <th>ID Permohonan</th>
                                <th>Jenis Peristiwa</th>
                                <th>Status Pengiriman</th>
                                <th>Keterangan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($waLogs as $wlog):
                            $wStatus = strtolower((string)($wlog['status'] ?? 'pending'));
                            $statusMap = [
                                'sent' => ['BERHASIL DIKIRIM', 'wa-badge--active'],
                                'failed' => ['GAGAL', 'wa-badge--danger'],
                                'skipped' => ['DILEWATI / BELUM AKTIF', 'wa-badge--warn'],
                                'pending' => ['MENUNGGU', 'wa-badge--muted'],
                            ];
                            [$statusText, $statusBadge] = $statusMap[$wStatus] ?? [strtoupper($wStatus), 'wa-badge--muted'];
                            $wCreatedRaw = (string)($wlog['created_at'] ?? '');
                            $wCreatedTs = $wCreatedRaw !== '' ? strtotime($wCreatedRaw) : false;
                            $wCreatedLabel = $wCreatedTs !== false ? date('d-m-Y H:i:s', $wCreatedTs) . ' WITA' : ($wCreatedRaw !== '' ? $wCreatedRaw : '-');
                            $wEventRaw = (string)($wlog['event_type'] ?? '');
                            $wEventLabel = \Siprima\Services\WhatsAppService::formatEventLabel($wEventRaw);
                            $wNama = trim((string)($wlog['nama_penerima'] ?? ''));
                            $wNamaDisplay = $wNama !== '' ? $wNama : ('Pemohon #' . (int)($wlog['id_user'] ?? 0));
                            $wPhoneMasked = wa_mask_phone((string)($wlog['no_hp'] ?? ''));
                            $wResp = wa_sanitize_system_note((string)($wlog['response_raw'] ?? ''));
                        ?>
                            <tr>
                                <td style="white-space:nowrap;"><?= wa_escape($wCreatedLabel) ?></td>
                                <td>
                                    <strong><?= wa_escape($wNamaDisplay) ?></strong><br>
                                    <span class="muted" style="font-size:12px;">WA: <?= wa_escape($wPhoneMasked) ?></span>
                                </td>
                                <td><?= !empty($wlog['permohonan_id']) ? '#' . (int)$wlog['permohonan_id'] : '-' ?></td>
                                <td>
                                    <div><strong><?= wa_escape($wEventLabel) ?></strong></div>
                                    <code style="font-size:11.5px;color:#475569;"><?= wa_escape($wEventRaw) ?></code>
                                </td>
                                <td><span class="wa-badge <?= $statusBadge ?>"><?= wa_escape($statusText) ?></span></td>
                                <td style="max-width:300px;overflow-wrap:anywhere;font-size:13px;"><?= wa_escape($wResp) ?></td>
                                <td style="white-space:nowrap;">
                                    <?php if (in_array($wStatus, ['failed', 'skipped', 'pending'], true)): ?>
                                        <form method="post" style="margin:0;display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= wa_escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="retry_single">
                                            <input type="hidden" name="log_id" value="<?= (int)$wlog['id'] ?>">
                                            <button type="submit" class="wa-btn-retry">Coba Kirim Ulang</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted" style="font-size:12px;">Terkirim</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
</div>
<div class="modal-overlay" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
    <div class="modal-box">
        <h3 id="logoutTitle">Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button type="button" id="confirmLogout" class="btn-danger">Ya, Logout</button>
            <button type="button" id="cancelLogout" class="btn-cancel">Batal</button>
        </div>
    </div>
</div>
<script src="common.js"></script>
</body>
</html>
