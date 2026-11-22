<?php
declare(strict_types=1);
require_once __DIR__ . '/../functions/email_delivery.php';

function email_delivery_confirmation_ui(mysqli $db, string $table, int $id, int $userId, string $knownStatus = '', string $knownStatusAt = ''): string
{
    try {
        $state = email_delivery_status($db, $table, $id, $userId);
    } catch (Throwable $e) {
        $state = ['available' => false];
    }
    if (empty($state['available']) && strtolower(trim($knownStatus)) === 'selesai' && $knownStatusAt !== '') {
        try {
            $eligibleTs = email_delivery_eligible_timestamp($knownStatusAt, $table);
            $state = ['available'=>true, 'eligible'=>time() >= $eligibleTs,
                'seconds_remaining'=>max(0, $eligibleTs - time()), 'status'=>'menunggu'];
        } catch (Throwable $e) {
            $state = ['available' => false];
        }
    }
    if (empty($state['available'])) {
        return '<div class="delivery-confirmation"><div class="delivery-actions"><button type="button" disabled>Sudah Masuk</button>'
            . '<button type="button" disabled>Belum Masuk</button></div><span class="delivery-muted">Aktif setelah status Selesai</span></div>';
    }
    if (empty($state['eligible'])) {
        $minutes = max(1, (int)ceil(((int)$state['seconds_remaining']) / 60));
        return '<div class="delivery-confirmation"><div class="delivery-actions"><button type="button" disabled>Sudah Masuk</button>'
            . '<button type="button" disabled>Belum Masuk</button></div><span class="delivery-muted">Aktif dalam ' . $minutes . ' menit</span></div>';
    }
    $status = (string)($state['status'] ?? 'menunggu');
    if ($status === 'menunggu') {
        $tableArg = htmlspecialchars(json_encode($table), ENT_QUOTES, 'UTF-8');
        return '<div class="delivery-actions"><button type="button" class="delivery-yes" onclick="confirmSpecialDelivery(' . $tableArg . ',' . $id . ',\'sudah_masuk\')">Sudah Masuk</button>'
            . '<button type="button" class="delivery-no" onclick="confirmSpecialDelivery(' . $tableArg . ',' . $id . ',\'belum_masuk\')">Belum Masuk</button></div>';
    }
    $labels = ['sudah_masuk'=>'Sudah diterima','belum_masuk'=>'Menunggu operator','diunggah_operator'=>'Dokumen tersedia',
        'sebagian_diunggah_perlu_pengajuan_baru'=>'Dokumen tersedia sebagian','perlu_pengajuan_baru'=>'Perlu pengajuan baru'];
    if ($status === 'sudah_masuk') {
        return '<span class="delivery-state" data-feedback-auto="1" data-feedback-table="'
            . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '" data-feedback-id="' . $id . '">Sudah diterima</span>';
    }
    $label = htmlspecialchars($labels[$status] ?? ucfirst(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8');
    if (in_array($status, ['sebagian_diunggah_perlu_pengajuan_baru', 'perlu_pengajuan_baru'], true)) {
        $note = trim((string)($state['catatan_operator'] ?? ''));
        $noteHtml = $note !== ''
            ? nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8'))
            : '-';
        return '<div class="delivery-state-detail"><span class="delivery-state">' . $label . '</span>'
            . '<span class="delivery-operator-note"><strong>Catatan operator:</strong> ' . $noteHtml . '</span></div>';
    }
    return '<span class="delivery-state">' . $label . '</span>';
}

function email_delivery_confirmation_assets(): void
{
    $csrf = json_encode(csrf_token());
    echo <<<HTML
<style>.delivery-confirmation{display:grid;gap:5px;min-width:155px}.delivery-actions{display:flex;gap:6px;flex-wrap:wrap}.delivery-actions button{border:0;border-radius:6px;padding:7px 9px;color:#fff;cursor:pointer}.delivery-actions button:disabled{background:#94a3b8;cursor:not-allowed;opacity:.8}.delivery-yes{background:#16a34a}.delivery-no{background:#dc2626}.delivery-muted{font-size:12px;color:#64748b}.delivery-state{font-size:12px;font-weight:600;color:#0f766e}.delivery-state-detail{display:grid;gap:6px;min-width:180px}.delivery-operator-note{font-size:12px;line-height:1.45;color:#475569;white-space:normal;overflow-wrap:anywhere}</style>
<script>
window.CSRF_TOKEN=window.CSRF_TOKEN||{$csrf};
function confirmSpecialDelivery(sourceTable,id,confirmation){
  if(confirmation==='belum_masuk'){window.openEmailDeliveryNoteModal?.(id,sourceTable);return;}
  if(!confirm('Konfirmasi dokumen sudah masuk ke email?'))return;
  const body=new URLSearchParams({csrf_token:window.CSRF_TOKEN||'',source_table:sourceTable,permohonan_id:String(id),confirmation,catatan:''});
  const endpoint=new URL('../user/confirm_email_delivery.php',window.location.href).href;
  fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},credentials:'same-origin',body})
    .then(async r=>{const raw=await r.text();let data;try{data=JSON.parse(raw)}catch(e){throw new Error('Respons server tidak valid (HTTP '+r.status+').')}return{ok:r.ok,data}}).then(({ok,data})=>{if(!ok||!data.success)throw new Error(data.message||'Konfirmasi gagal.');
      const reload=()=>location.reload();window.requestPermohonanFeedback?window.requestPermohonanFeedback(sourceTable,id,'konfirmasi',reload):reload();
    }).catch(error=>alert(error.message||'Konfirmasi gagal.'));
}
</script>
HTML;
}
