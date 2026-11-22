<?php
require_once __DIR__ . '/asprima_helper.php';
if (!asprima_widget_allowed($_SESSION ?? [], (string)($_SERVER['SCRIPT_NAME'] ?? ''))) return;
if (!empty($GLOBALS['siprima_asprima_rendered'])) return;
$GLOBALS['siprima_asprima_rendered']=true;
$sipAiCsrfToken = function_exists('csrf_token') ? csrf_token() : '';
?>
<link rel="stylesheet" href="../user/css/ai_chat_widget.css?v=20260913-2">
<div class="sip-ai-widget" data-endpoint="../user/ai_chat_faq.php" data-csrf-token="<?= htmlspecialchars($sipAiCsrfToken, ENT_QUOTES, 'UTF-8') ?>" data-account-id="<?= (int)$_SESSION['id_user'] ?>">
    <button type="button" class="sip-ai-toggle" aria-label="Buka bantuan ASPRIMA" aria-expanded="false" aria-controls="asprima-panel"><span class="sip-ai-orb" aria-hidden="true"></span><span class="sip-ai-toggle-mark">ASPRIMA</span></button>
    <section id="asprima-panel" class="sip-ai-panel" aria-label="Bantuan ASPRIMA" hidden>
        <header class="sip-ai-header"><div><strong>ASPRIMA</strong><span>Teman bantu urus dokumen</span></div><button type="button" class="sip-ai-close" aria-label="Tutup bantuan">&times;</button></header>
        <div class="sip-ai-context"><span data-context-label>Bantuan lainnya</span><button type="button" data-change-request>Pilih permohonan</button></div>
        <div class="sip-ai-messages" role="log" aria-live="polite" aria-relevant="additions">
            <div class="sip-ai-message sip-ai-message-bot">Halo! Saya ASPRIMA. Mau cek syarat, mengajukan permohonan, atau melihat kabar terbaru? Pilih bantuan di bawah, ya.</div>
        </div>
        <div class="sip-ai-suggestions" aria-label="Pertanyaan cepat">
            <button type="button" data-question="Bagaimana cara mengajukan permohonan?">Cara mengajukan</button>
            <button type="button" data-question="Apa perkembangan permohonan saya?">Cek perkembangan</button>
            <button type="button" data-question="Apa saja syarat permohonan?">Syarat permohonan</button>
            <button type="button" data-question="Bagaimana mendapatkan dokumen hasil?">Dokumen hasil</button>
        </div>
        <p class="sip-ai-privacy">Pertanyaan dapat diproses OpenAI. Jangan tulis NIK, nomor KK, kata sandi, atau data pribadi.</p>
        <form class="sip-ai-form"><input type="text" name="message" maxlength="500" autocomplete="off" placeholder="Tulis pertanyaan..." aria-label="Pertanyaan untuk ASPRIMA"><button type="submit">Kirim</button></form>
    </section>
</div>
<script src="../user/js/ai_chat_widget.js?v=20260913-2" defer></script>