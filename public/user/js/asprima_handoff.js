(function () {
  'use strict';
  const script = document.currentScript;
  const account = script ? script.dataset.accountId : '';
  window.asprimaApplyDraft = function (id, jenis) {
    if (new URLSearchParams(location.search).get('asprima_comment') !== '1') return;
    try {
      const draft = JSON.parse(sessionStorage.getItem('siprima:asprima:draft') || 'null');
      if (!draft || draft.account !== account || draft.path !== location.pathname || draft.key !== jenis + ':' + id || typeof draft.text !== 'string' || draft.text.length > 1000 || !Number.isFinite(draft.at) || Date.now() - draft.at > 600000 || draft.at > Date.now()) return;
      const field = document.getElementById('komentar_permohonan');
      if (!field || field.value.trim()) return;
      field.value = draft.text;
      const note = document.createElement('p');
      note.textContent = 'Contoh pesan dari ASPRIMA. Boleh Anda ubah dulu, lalu tekan Kirim Komentar kalau sudah sesuai.';
      note.style.cssText = 'font-size:13px;color:#475569';
      field.insertAdjacentElement('beforebegin', note);
      sessionStorage.removeItem('siprima:asprima:draft');
      setTimeout(function () { field.scrollIntoView({block:'center'}); field.focus({preventScroll:true}); }, 100);
    } catch (_) {}
  };
})();