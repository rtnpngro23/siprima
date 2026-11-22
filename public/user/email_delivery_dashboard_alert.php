<?php
$dashboardPendingCount = (int)($email_delivery_pending_count ?? 0);
$dashboardStatusUrl = (string)($email_delivery_status_url ?? 'status_permohonan.php');
$dashboardAudience = (string)($email_delivery_audience ?? 'user');
if ($dashboardPendingCount > 0):
?>
<style>
  .email-delivery-dashboard-alert{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin:0 0 24px;padding:18px 20px;border:1px solid #f6c453;border-left:5px solid #f59e0b;border-radius:12px;background:#fffbeb;color:#78350f;box-shadow:0 5px 16px rgba(146,64,14,.08)}
  .email-delivery-dashboard-alert__content{display:flex;align-items:flex-start;gap:13px}.email-delivery-dashboard-alert__icon{font-size:25px;line-height:1}.email-delivery-dashboard-alert strong{display:block;margin-bottom:5px;font-size:16px}.email-delivery-dashboard-alert p{margin:0;line-height:1.55;color:#92400e}.email-delivery-dashboard-alert__button{flex:none;display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;border-radius:8px;background:#d97706;color:#fff;text-decoration:none;font-weight:700;white-space:nowrap}.email-delivery-dashboard-alert__button:hover{background:#b45309}
  @media(max-width:700px){.email-delivery-dashboard-alert{flex-direction:column}.email-delivery-dashboard-alert__button{width:100%}}
</style>
<div class="email-delivery-dashboard-alert" role="alert">
  <div class="email-delivery-dashboard-alert__content">
    <span class="email-delivery-dashboard-alert__icon" aria-hidden="true">⚠️</span>
    <div>
      <strong>Konfirmasi penerimaan dokumen diperlukan</strong>
      <p>
        Terdapat <?= $dashboardPendingCount ?> permohonan selesai yang belum dikonfirmasi.
        <?php if ($dashboardAudience === 'petugas_desa'): ?>Periksa email pemohon<?php else: ?>Periksa email Anda<?php endif; ?> untuk dokumen dari SIAK Online DITJEN DUKCAPIL KEMENDAGRI, lalu konfirmasikan apakah dokumen sudah diterima atau belum.
      </p>
    </div>
  </div>
  <a class="email-delivery-dashboard-alert__button" href="<?= htmlspecialchars($dashboardStatusUrl, ENT_QUOTES, 'UTF-8') ?>">Konfirmasi Sekarang</a>
</div>
<?php endif; ?>

