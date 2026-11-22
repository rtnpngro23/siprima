<style>
.email-note-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;padding:18px;z-index:3000}.email-note-overlay.show{display:flex}.email-note-box{width:min(520px,100%);background:#fff;border-radius:14px;box-shadow:0 24px 60px rgba(15,23,42,.3);overflow:hidden}.email-note-header{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:1px solid #e2e8f0}.email-note-header h3{margin:0;font-size:18px;color:#1e293b}.email-note-close{border:0;background:transparent;font-size:26px;color:#64748b;cursor:pointer}.email-note-body{padding:18px}.email-note-body label{display:block;font-weight:600;color:#334155;margin-bottom:7px}.email-note-body textarea{width:100%;box-sizing:border-box;min-height:125px;padding:11px 12px;border:1px solid #cbd5e1;border-radius:9px;resize:vertical;font:inherit}.email-note-help{display:flex;justify-content:space-between;gap:10px;margin-top:6px;font-size:12px;color:#64748b}.email-note-error{display:none;margin-top:9px;color:#b91c1c;font-size:13px}.email-note-actions{display:flex;justify-content:flex-end;gap:9px;padding:14px 18px;border-top:1px solid #e2e8f0;background:#f8fafc}.email-note-btn{border:0;border-radius:8px;padding:9px 14px;cursor:pointer;font-weight:600}.email-note-cancel{background:#e2e8f0;color:#334155}.email-note-submit{background:#dc2626;color:#fff}.email-note-submit:disabled{opacity:.6;cursor:not-allowed}
</style>
<div class="email-note-overlay" id="emailDeliveryNoteModal" role="dialog" aria-modal="true" aria-labelledby="emailDeliveryNoteTitle">
  <div class="email-note-box">
    <div class="email-note-header"><h3 id="emailDeliveryNoteTitle">Dokumen Belum Masuk ke Email</h3><button type="button" class="email-note-close" data-email-note-close aria-label="Tutup">&times;</button></div>
    <form id="emailDeliveryNoteForm">
      <div class="email-note-body">
        <label for="emailDeliveryNote">Catatan dokumen yang belum diterima</label>
        <textarea id="emailDeliveryNote" maxlength="1000" required placeholder="Contoh: Kartu Keluarga dan biodata belum masuk ke email."></textarea>
        <div class="email-note-help"><span>Jelaskan dokumen apa saja yang belum diterima.</span><span id="emailDeliveryNoteCount">0/1000</span></div>
        <div class="email-note-error" id="emailDeliveryNoteError"></div>
      </div>
      <div class="email-note-actions"><button type="button" class="email-note-btn email-note-cancel" data-email-note-close>Batal</button><button type="submit" class="email-note-btn email-note-submit" id="emailDeliveryNoteSubmit">Kirim Laporan</button></div>
    </form>
  </div>
</div>
<script>
(() => {
  const modal=document.getElementById('emailDeliveryNoteModal'),form=document.getElementById('emailDeliveryNoteForm'),note=document.getElementById('emailDeliveryNote'),counter=document.getElementById('emailDeliveryNoteCount'),errorBox=document.getElementById('emailDeliveryNoteError'),submit=document.getElementById('emailDeliveryNoteSubmit');
  let request={id:0,jenis:''};
  const tableMap={kelahiran:'tb_akta_kelahiran',kematian:'tb_akta_kematian',kk:'tb_kartukeluarga',pindah:'tb_pindah_alamat',pindah_online:'tb_pindah_online'};
  const userEndpoint=file=>new URL('../user/'+file,window.location.href).href;
  const parseJsonResponse=async response=>{const raw=await response.text();let data;try{data=JSON.parse(raw);}catch(error){throw new Error('Respons server tidak valid (HTTP '+response.status+').');}return{ok:response.ok,data};};
  const close=()=>{modal.classList.remove('show');document.body.style.overflow='';errorBox.style.display='none';};
  window.openEmailDeliveryNoteModal=(id,jenis)=>{request={id:Number(id),jenis:String(jenis)};note.value='';counter.textContent='0/1000';errorBox.style.display='none';modal.classList.add('show');document.body.style.overflow='hidden';setTimeout(()=>note.focus(),50);};
  modal.querySelectorAll('[data-email-note-close]').forEach(button=>button.addEventListener('click',close));
  modal.addEventListener('click',event=>{if(event.target===modal)close();});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&modal.classList.contains('show'))close();});
  note.addEventListener('input',()=>counter.textContent=note.value.length+'/1000');
  const freshCsrfToken=()=>fetch(userEndpoint('get_csrf_token.php'),{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'})
    .then(parseJsonResponse)
    .then(({ok,data})=>{if(!ok||!data.success||!data.csrf_token)throw new Error(data.message||'Token keamanan gagal dimuat.');window.CSRF_TOKEN=data.csrf_token;const meta=document.querySelector('meta[name="csrf-token"]');if(meta)meta.setAttribute('content',data.csrf_token);return data.csrf_token;});
  form.addEventListener('submit',async event=>{
    event.preventDefault();const catatan=note.value.trim(),sourceTable=request.jenis.startsWith('tb_')?request.jenis:tableMap[request.jenis];
    if(!catatan){errorBox.textContent='Catatan wajib diisi.';errorBox.style.display='block';return;}
    if(!sourceTable)return;
    submit.disabled=true;submit.textContent='Mengirim...';errorBox.style.display='none';
    let csrfToken='';
    try{csrfToken=await freshCsrfToken();}catch(error){errorBox.textContent=error.message||'Token keamanan gagal dimuat. Silakan muat ulang halaman.';errorBox.style.display='block';submit.disabled=false;submit.textContent='Kirim Laporan';return;}
    const body=new URLSearchParams({csrf_token:csrfToken,source_table:sourceTable,permohonan_id:String(request.id),confirmation:'belum_masuk',catatan});
    fetch(userEndpoint('confirm_email_delivery.php'),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json','X-CSRF-Token':csrfToken},credentials:'same-origin',body})
      .then(parseJsonResponse)
      .then(({ok,data})=>{if(!ok||!data.success)throw new Error(data.message||'Konfirmasi gagal.');alert(data.message);window.location.reload();})
      .catch(error=>{errorBox.textContent=error.message||'Konfirmasi gagal.';errorBox.style.display='block';})
      .finally(()=>{submit.disabled=false;submit.textContent='Kirim Laporan';});
  });
})();
</script>
