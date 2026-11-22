(function () {
  'use strict';
  const script=document.currentScript, account=script.dataset.account, catalog=script.dataset.catalog;
  if(new URLSearchParams(location.search).get('inspirasi_nama')!=='1')return;
  const field=document.querySelector('input[name="nama_bayi"]');
  // A failed POST or an existing draft always takes precedence over a suggested name.
  if(!field || field.value.trim() || script.dataset.method!=='GET')return;
  const key='siprima:baby-name:selection';
  let item;
  try {item=JSON.parse(sessionStorage.getItem(key)||'null');}catch(_){return;}
  if(!item || item.account!==account || item.path!==location.pathname || !Number.isFinite(item.at) || item.at>Date.now() || Date.now()-item.at>1800000)return;
  const note=document.createElement('p');note.style.cssText='font-size:13px;color:#376b75;margin-top:8px';note.setAttribute('role','status');field.insertAdjacentElement('afterend',note);
  BabyNames.load(catalog).then(data=>{
    const rows=BabyNames.resolveSaved(data,[item.ids]);if(rows.length!==1 || field.value.trim())return;
    field.value=rows[0].map(n=>n.n).join(' ');
    field.dispatchEvent(new Event('input',{bubbles:true}));
    note.textContent='Nama pilihan Anda sudah diisi. Periksa ejaannya, ya. Anda masih bisa mengubahnya sebelum mengirim.';
    try{sessionStorage.removeItem(key);}catch(_){}
  }).catch(()=>{note.textContent='Nama pilihan belum bisa diisi. Anda dapat mengetiknya sendiri atau kembali ke Inspirasi Nama Bayi.';});
})();