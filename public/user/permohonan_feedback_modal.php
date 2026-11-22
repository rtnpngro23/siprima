<?php $feedbackCsrf = csrf_token(); ?>
<style>
.feedback-overlay{position:fixed;inset:0;background:rgba(15,23,42,.65);display:none;align-items:center;justify-content:center;padding:18px;z-index:10050}.feedback-overlay.show{display:flex}.feedback-box{width:min(480px,100%);background:#fff;border-radius:16px;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.28)}.feedback-box h3{margin:0 0 8px}.feedback-box p{color:#64748b;margin:0 0 18px}.feedback-field{display:block;margin-top:13px;font-weight:600}.feedback-field select,.feedback-field textarea{width:100%;margin-top:6px;padding:10px;border:1px solid #cbd5e1;border-radius:8px}.feedback-stars{display:flex;gap:7px;margin-top:7px}.feedback-stars label{cursor:pointer;background:#f1f5f9;padding:7px 10px;border-radius:7px}.feedback-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:18px}.feedback-actions button,.feedback-actions a{border:0;border-radius:8px;padding:10px 15px;cursor:pointer;font:inherit;text-decoration:none}.feedback-skip{background:#e2e8f0;color:#0f172a}.feedback-submit,.feedback-skm-open{background:#2563eb;color:#fff}.feedback-error{color:#b91c1c;margin-top:9px;min-height:20px;font-size:13px}.feedback-skm[hidden],.feedback-box[hidden]{display:none}.feedback-skm-icon{font-size:36px;margin-bottom:10px}
.feedback-box{max-height:calc(100dvh - 32px);overflow-y:auto}.feedback-box fieldset{min-width:0;border:1px solid #cbd5e1;border-radius:8px;padding:10px}.feedback-box legend{font-size:15px;padding:4px 8px;background:#e8f2fc;color:#234e73;border-radius:5px}.feedback-box small{font-size:13px;font-weight:400;color:#475569}.feedback-stars{gap:4px;justify-content:space-between}.feedback-stars label{min-width:44px;min-height:44px;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;padding:6px 3px;font-size:14px}.feedback-field select,.feedback-field textarea{font-size:16px}.feedback-actions button,.feedback-actions a{min-height:44px}.feedback-actions{flex-wrap:wrap}.feedback-box [hidden]{display:none!important}@media(max-width:480px){.feedback-box{padding:16px}.feedback-overlay{padding:12px}}
</style>
<div class="feedback-overlay" id="feedbackOverlay" role="dialog" aria-modal="true" aria-labelledby="feedbackTitle">
  <form class="feedback-box" id="feedbackForm">
    <h3 id="feedbackTitle">Berikan Tanggapan atau Aduan</h3>
    <p>Masukan Anda membantu kami meningkatkan pelayanan. Pengisian ini tidak wajib.</p>
    <label class="feedback-field">Jenis
      <select id="feedbackType" required><option value="tanggapan">Tanggapan aplikasi &amp; pelayanan</option><option value="aduan">Aduan layanan</option></select>
    </label>
    <div id="feedbackRatingField">
      <fieldset class="feedback-field"><legend>Kemudahan aplikasi SIPRIMA</legend>
        <div class="feedback-stars"><?php for($i=1;$i<=5;$i++):?><label><input type="radio" name="feedback_rating" value="<?=$i?>" <?=$i===5?'checked':''?> aria-label="Aplikasi <?=$i?> bintang"> <?=$i?> ★</label><?php endfor;?></div>
      </fieldset>
      <fieldset class="feedback-field"><legend>Pelayanan petugas</legend>
        <small>Kejelasan informasi dan bantuan selama permohonan diproses.</small>
        <div class="feedback-stars"><?php for($i=1;$i<=5;$i++):?><label><input type="radio" name="feedback_service_rating" value="<?=$i?>" <?=$i===5?'checked':''?> aria-label="Pelayanan <?=$i?> bintang"> <?=$i?> ★</label><?php endfor;?></div>
      </fieldset>
    </div>
    <label class="feedback-field">Pesan <span id="feedbackMessageHint">(opsional)</span>
      <textarea id="feedbackMessage" rows="4" maxlength="1000" placeholder="Tuliskan tanggapan atau aduan Anda"></textarea>
    </label>
    <div class="feedback-error" id="feedbackError" role="alert"></div>
    <div class="feedback-actions"><button type="button" class="feedback-skip" id="feedbackSkip">Lewati</button><button type="submit" class="feedback-submit">Kirim</button></div>
  </form>
  <section class="feedback-box feedback-skm" id="feedbackSkm" hidden>
    <div class="feedback-skm-icon" aria-hidden="true">✓</div>
    <h3 id="feedbackSkmTitle">Bantu Tingkatkan Pelayanan</h3>
    <p>Terima kasih. Anda juga dapat mengisi Survei Kepuasan Masyarakat resmi. Survei akan dibuka di tab baru.</p>
    <div class="feedback-actions"><button type="button" class="feedback-skip" id="feedbackSkmLater">Tidak Sekarang</button><a class="feedback-skm-open" id="feedbackSkmOpen" href="https://skm.go.id/share/instansi/d352215f-cf1e-4ddb-a3ee-cb2d02e0bea7/1" target="_blank" rel="noopener noreferrer">Isi SKM Resmi</a></div>
  </section>
</div>
<script>
(()=>{const endpoint=new URL('../user/permohonan_feedback.php',window.location.href).href,csrf=<?=json_encode($feedbackCsrf)?>,overlay=document.getElementById('feedbackOverlay'),form=document.getElementById('feedbackForm'),skm=document.getElementById('feedbackSkm'),type=document.getElementById('feedbackType'),message=document.getElementById('feedbackMessage'),error=document.getElementById('feedbackError');let context=null,busy=false;
const post=data=>fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},credentials:'same-origin',body:new URLSearchParams({csrf_token:csrf,...data})}).then(r=>r.json().then(j=>({ok:r.ok,data:j})));
const finish=()=>{overlay.classList.remove('show');document.body.style.overflow='';form.hidden=false;skm.hidden=true;overlay.setAttribute('aria-labelledby','feedbackTitle');const callback=context?.callback;context=null;if(typeof callback==='function')callback();};
const showSkm=()=>{form.hidden=true;skm.hidden=false;overlay.setAttribute('aria-labelledby','feedbackSkmTitle');};
const complete=data=>data.show_skm?showSkm():finish();
const skip=()=>{if(!context||busy)return;busy=true;error.textContent='';post({action:'skip',source_table:context.table,permohonan_id:context.id}).then(({ok,data})=>{if(!ok||!data.success)throw new Error(data.message||'Pilihan belum dapat disimpan.');complete(data);}).catch(e=>error.textContent=e.message).finally(()=>busy=false);};
const selectedRating=name=>Number(form.querySelector('input[name="'+name+'"]:checked')?.value||0);
const updateFeedbackRules=()=>{
    const complaint=type.value==='aduan';
    const low=selectedRating('feedback_rating')<=3||selectedRating('feedback_service_rating')<=3;
    document.getElementById('feedbackRatingField').hidden=complaint;
    message.required=complaint||low;
    document.getElementById('feedbackMessageHint').textContent=message.required?'(wajib)':'(opsional)';
    message.placeholder=complaint?'Ceritakan aduan layanan Anda':low?'Mohon ceritakan kendala Anda untuk rating 3 bintang atau kurang':'Tuliskan tanggapan atau saran Anda';
};
type.addEventListener('change',updateFeedbackRules);
form.querySelectorAll('input[type="radio"]').forEach(input=>input.addEventListener('change',updateFeedbackRules));
document.getElementById('feedbackSkip').addEventListener('click',skip);document.getElementById('feedbackSkmLater').addEventListener('click',finish);document.getElementById('feedbackSkmOpen').addEventListener('click',()=>setTimeout(finish,0));
overlay.addEventListener('click',e=>{if(e.target===overlay)(skm.hidden?skip():finish());});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&overlay.classList.contains('show'))(skm.hidden?skip():finish());});
form.addEventListener('submit',e=>{e.preventDefault();if(!context||busy)return;const rating=form.querySelector('input[name="feedback_rating"]:checked')?.value||'';const service_rating=selectedRating('feedback_service_rating');updateFeedbackRules();if(message.required&&!message.value.trim()){error.textContent=type.value==='aduan'?'Pesan aduan wajib diisi.':'Mohon ceritakan kendala Anda untuk rating 3 bintang atau kurang.';message.focus();return;}busy=true;error.textContent='';post({action:'submit',source_table:context.table,permohonan_id:context.id,jenis_feedback:type.value,rating,service_rating,pesan:message.value.trim()}).then(({ok,data})=>{if(!ok||!data.success)throw new Error(data.message||'Feedback gagal disimpan.');complete(data);}).catch(e=>error.textContent=e.message).finally(()=>busy=false);});
window.requestPermohonanFeedback=(table,id,trigger,callback)=>{if(context){if(typeof callback==='function')callback();return;}post({action:'trigger',source_table:table,permohonan_id:String(id),trigger_type:trigger}).then(({ok,data})=>{if(!ok||!data.success||!data.should_prompt){if(typeof callback==='function')callback();return;}context={table,id:String(id),callback};form.reset();form.hidden=false;skm.hidden=true;overlay.setAttribute('aria-labelledby','feedbackTitle');type.dispatchEvent(new Event('change'));error.textContent='';overlay.classList.add('show');document.body.style.overflow='hidden';}).catch(()=>{if(typeof callback==='function')callback();});};
document.addEventListener('click',e=>{const link=e.target.closest('a[data-feedback-table][data-feedback-id]');if(!link||!link.dataset.feedbackTable)return;e.preventDefault();const frame=document.createElement('iframe');frame.hidden=true;frame.src=link.href;document.body.appendChild(frame);setTimeout(()=>frame.remove(),60000);window.requestPermohonanFeedback(link.dataset.feedbackTable,link.dataset.feedbackId,'download');});
const auto=document.querySelector('[data-feedback-auto="1"]');if(auto)window.requestPermohonanFeedback(auto.dataset.feedbackTable,auto.dataset.feedbackId,'konfirmasi');
})();
</script>
