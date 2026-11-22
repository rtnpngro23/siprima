(function () {
  'use strict';
  const root=document.getElementById('baby-generator');if(!root)return;
  const $=id=>document.getElementById(id), B=window.BabyNames;
  let data=null, rows=[], favorites=[], showFavorites=false, selected=null, lastOptions=null;
  const storageKey='siprima:baby-name:favorites:'+root.dataset.account;
  const options=()=>({gender:$('gender').value,region:$('nameOrigin').value,theme:$('nameTheme').value,count:Number($('wordCount').value),mix:[$('mixOriginA').value,$('mixOriginB').value]});
  const fullName=row=>row.map(n=>n.n).join(' '), key=row=>row.map(n=>n.id).join(':');
  const say=text=>{$('resultInfo').textContent=text;};
  function element(tag,className,text){const node=document.createElement(tag);if(className)node.className=className;if(text)node.textContent=text;return node;}
  function button(text,fn){const node=element('button','copy-btn',text);node.type='button';node.addEventListener('click',fn);return node;}
  function save(row) {
    const found=favorites.some(f=>key(f)===key(row));
    if(!found && favorites.length>=20){say('Anda sudah menyimpan 20 pilihan. Hapus salah satu untuk menyimpan nama lain.');return;}
    const next=found?favorites.filter(f=>key(f)!==key(row)):[...favorites,row];
    try{localStorage.setItem(storageKey,JSON.stringify(next.map(r=>r.map(n=>n.id))));favorites=next;say(found?'Nama dihapus dari favorit.':'Nama disimpan di perangkat ini.');render();}
    catch(_){say('Nama belum bisa disimpan di perangkat ini. Gunakan Salin nama untuk menyimpannya.');}
  }
  function render() {
    const container=$('nameResults');container.replaceChildren();
    const visible=showFavorites?favorites:rows;
    $('favoritesToggle').textContent=(showFavorites?'Kembali ke hasil':'Lihat favorit')+' ('+favorites.length+')';
    $('favoritesToggle').setAttribute('aria-pressed',String(showFavorites));
    container.className=visible.length?'name-grid':'empty-state';
    if(!visible.length){container.textContent=showFavorites?'Belum ada nama favorit. Simpan pilihan yang Anda sukai.':'Belum ada rangkaian yang cocok. Coba harapan lain atau kurangi jumlah kata.';return;}
    visible.forEach((row,rowIndex)=>{
      const card=element('article','name-card');card.appendChild(element('h3','name-title',fullName(row)));
      const list=element('ul','word-meanings');
      row.forEach((name,index)=>{
        const item=element('li','');item.appendChild(element('strong','',name.n));item.appendChild(element('span','name-origin',name.o));item.appendChild(element('p','',name.m));
        const source=element('a','name-source','Baca rujukan');source.href=B.sourceUrl(name.source.url);source.target='_blank';source.rel='noopener noreferrer';source.title=name.source.title; if(new URL(name.source.url).hostname==='en.wiktionary.org') source.textContent='Wiktionary · CC BY-SA 4.0';source.setAttribute('aria-label','Baca rujukan arti '+name.n);item.appendChild(source);
        if(!showFavorites){const change=button('Ganti '+name.n,()=>{const next=B.replaceWord(data,row,index,lastOptions);if(!next){say('Belum ada pengganti yang cocok dengan pilihan ini. Coba harapan lain.');return;}rows[rowIndex]=next;say('Satu kata sudah diganti.');render();});item.appendChild(change);}
        list.appendChild(item);
      });card.appendChild(list);
      card.appendChild(element('p','combined-meaning','Harapan keluarga: semoga si kecil tumbuh membawa kebaikan dari nama yang dipilih. Ini harapan, bukan terjemahan rangkaian nama.'));
      const actions=element('div','name-actions');
      const copy=button('Salin nama',async()=>{try{await navigator.clipboard.writeText(fullName(row));say('Nama sudah disalin.');}catch(_){say('Belum bisa menyalin otomatis. Anda bisa memilih teks nama lalu menyalinnya.');}});
      const fav=button(favorites.some(f=>key(f)===key(row))?'Hapus favorit':'Simpan favorit',()=>save(row));
      const use=button('Gunakan nama ini',()=>{selected=row;$('confirmedName').textContent=fullName(row);$('handoffNotice').textContent='';$('confirmBabyName').showModal();});
      actions.append(copy,fav,use);card.appendChild(actions);container.appendChild(card);
    });
  }
  function generate() {
    $('mixedOrigins').hidden=$('nameOrigin').value!=='campuran';
    if(!data)return;showFavorites=false;lastOptions=options();
    if(lastOptions.region==='campuran' && lastOptions.mix[0]===lastOptions.mix[1]){rows=[];render();say('Pilih dua asal nama yang berbeda untuk membuat campuran.');return;}
    rows=B.generate(data,lastOptions,Math.random,rows);render();
    say(rows.length?rows.length+' pilihan. Belum cocok? Tekan Cari inspirasi nama lagi.':'');
  }
  async function load() {
    $('retryCatalog').hidden=true;$('generateButton').disabled=true;
    try{
      data=await B.load(root.dataset.catalog);
      try{favorites=B.resolveSaved(data,JSON.parse(localStorage.getItem(storageKey)||'[]'));}catch(_){favorites=[];}
      $('catalogInfo').textContent=data.names.filter(n=>n.g==='L').length+' nama laki-laki dan '+data.names.filter(n=>n.g==='P').length+' nama perempuan, lengkap dengan arti dan rujukan.';
      $('generateButton').disabled=false;generate();
    }catch(_){data=null;$('catalogInfo').textContent='Daftar nama belum bisa dibuka.';$('nameResults').textContent='Periksa koneksi, lalu tekan Coba buka lagi.';$('retryCatalog').hidden=false;}
  }
  $('namePreferences').addEventListener('submit',event=>{event.preventDefault();generate();});
  $('namePreferences').addEventListener('change',generate);
  $('favoritesToggle').addEventListener('click',()=>{if(!data)return;showFavorites=!showFavorites;render();say('');});
  $('retryCatalog').addEventListener('click',load);
  $('cancelBabyName').addEventListener('click',()=>$('confirmBabyName').close());
  $('useBabyName').addEventListener('click',()=>{
    if(!selected)return;
    const target=new URL(root.dataset.apply,location.href);
    if(target.origin!==location.origin)return;
    try{sessionStorage.setItem('siprima:baby-name:selection',JSON.stringify({ids:selected.map(n=>n.id),account:root.dataset.account,path:target.pathname,at:Date.now()}));}
    catch(_){$('handoffNotice').textContent='Nama belum bisa diteruskan. Tutup kotak ini dan gunakan Salin nama, lalu tempel di formulir akta.';return;}
    target.searchParams.set('inspirasi_nama','1');location.assign(target.href);
  });
  document.querySelector('.menu-toggle')?.addEventListener('click',()=>document.querySelector('.sidebar')?.classList.toggle('open'));
  load();
})();