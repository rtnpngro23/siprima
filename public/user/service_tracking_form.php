<?php
require_once __DIR__ . '/service_tracking.php';
$trackingTable = (string)($serviceTrackingTable ?? $_GET['table'] ?? '');
$trackingId = (int)($detail_data['id'] ?? 0);
$trackingHistory = serviceTrackingHistory($koneksi, $trackingTable, $trackingId, true);
$trackingVersion = $trackingHistory ? max(array_column($trackingHistory, 'id')) : 0;
?>
<div id="service-tracking-history"><?= serviceTrackingHtml($trackingHistory, true, (string)($detail_data['status'] ?? '')) ?></div>
<div id="service-tracking-message" role="status" aria-live="polite" style="margin:12px 0"></div>
<script type="application/json" id="service-tracking-state"><?= json_encode([
    'status' => $detail_data['status'] ?? '', 'version' => $trackingVersion,
    'draft' => isset($_SESSION['service_status_draft']) && $_SESSION['service_status_draft']['table'] === $trackingTable && $_SESSION['service_status_draft']['id'] === $trackingId ? $_SESSION['service_status_draft']['note'] : '',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php unset($_SESSION['service_status_draft']); ?>
