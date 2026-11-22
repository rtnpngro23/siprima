(function () {
  'use strict';
  function initWidget(widget) {
    const toggle = widget.querySelector('.sip-ai-toggle');
    const panel = widget.querySelector('.sip-ai-panel');
    const form = widget.querySelector('.sip-ai-form');
    const input = form.querySelector('input');
    const messages = widget.querySelector('.sip-ai-messages');
    const suggestions = widget.querySelector('.sip-ai-suggestions');
    const contextLabel = widget.querySelector('[data-context-label]');
    let selection = null, busy = false, pendingQuestion = '', picker = null;
    const initialQuestions = ['Bagaimana cara mengajukan permohonan?', 'Apa perkembangan permohonan saya?', 'Apa saja syarat permohonan?', 'Bagaimana mendapatkan dokumen hasil?'];
    function open(value) {
      panel.hidden = !value; toggle.setAttribute('aria-expanded', String(value));
      if (value) { if (!window.matchMedia('(max-width: 640px)').matches) input.focus(); } else toggle.focus();
    }
    function setBusy(value) {
      busy = value; form.querySelector('button').disabled = value; input.disabled = value;
      widget.querySelectorAll('.sip-ai-suggestions button,[data-change-request],.sip-ai-picker button').forEach(button => { button.disabled = value; });
      form.setAttribute('aria-busy', String(value));
    }
    function append(text, kind = 'bot') {
      const item = document.createElement('div'); item.className = 'sip-ai-message sip-ai-message-' + kind;
      item.textContent = text; messages.appendChild(item); messages.scrollTop = messages.scrollHeight;
      return item;
    }
    async function api(payload) {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 55000);
      try {
        const response = await fetch(widget.dataset.endpoint, {method: 'POST', credentials: 'same-origin', signal: controller.signal,
          headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': widget.dataset.csrfToken}, body: JSON.stringify(payload)});
        let data;
        try { data = await response.json(); } catch (_) { throw new Error('Bantuan belum tersedia. Muat ulang halaman dan coba kembali.'); }
        if (!response.ok || !data.success) throw new Error(data.answer || 'Bantuan belum tersedia. Silakan coba kembali.');
        return data;
      } finally { clearTimeout(timer); }
    }
    function safeLink(value) {
      try {
        const url = new URL(value, window.location.href);
        if (url.origin === window.location.origin && /\/(?:user|user_rs|user_ahlak|user_kua)\/[a-z_]+\.php$/.test(url.pathname)) return url;
        if (url.protocol === 'https:' && url.hostname === 'api.whatsapp.com' && url.pathname === '/send' && url.searchParams.get('phone') === '6281243425557') return url;
      } catch (_) {}
      return null;
    }
    function addLinks(item, links) {
      if (!Array.isArray(links)) return;
      const wrap = document.createElement('div'); wrap.className = 'sip-ai-links';
      links.forEach(link => {
        if (!link || typeof link.url !== 'string' || typeof link.label !== 'string') return;
        const url = safeLink(link.url); if (!url) return;
        const anchor = document.createElement('a'); anchor.href = url.href; anchor.textContent = link.label;
        if (url.origin !== window.location.origin) { anchor.target = '_blank'; anchor.rel = 'noopener noreferrer'; }
        if (typeof link.draft === 'string' && url.origin === window.location.origin) {
          const draft = document.createElement('p'); draft.className = 'sip-ai-draft'; draft.textContent = 'Contoh pesan — boleh diubah sebelum dikirim:\n' + link.draft; item.appendChild(draft);
          anchor.addEventListener('click', () => {
            try { sessionStorage.setItem('siprima:asprima:draft', JSON.stringify({text: link.draft.slice(0,1000), key: link.request_key, account: widget.dataset.accountId, path: url.pathname, at: Date.now()})); } catch (_) {}
          });
        }
        wrap.appendChild(anchor);
      });
      if (wrap.children.length) item.appendChild(wrap);
    }
    function setSuggestions(questions) {
      suggestions.replaceChildren();
      questions.forEach(question => {
        const button = document.createElement('button'); button.type = 'button'; button.dataset.question = question; button.textContent = question; suggestions.appendChild(button);
      });
    }
    async function chooseRequests(page = 0) {
      if (busy) return;
      setBusy(true);
      if (picker) picker.remove();
      picker = append('Sebentar, saya cari permohonannya…'); picker.classList.add('sip-ai-picker');
      const currentPicker = picker;
      try {
        const data = await api({action:'requests',page});
        currentPicker.textContent = data.requests.length ? 'Mau cek permohonan yang mana?' : 'Belum ada permohonan pada halaman ini. Gunakan Cara mengajukan untuk memulai.';
        data.requests.forEach(request => {
          const button = document.createElement('button'); button.type = 'button';
          button.className = 'sip-ai-request-card';
          const service = document.createElement('span'); service.className = 'sip-ai-request-service'; service.textContent = request.label;
          const name = document.createElement('strong'); name.className = 'sip-ai-request-name'; name.textContent = request.title || 'Nama belum tercatat';
          button.append(service, name);
          button.addEventListener('click', () => {
            if (busy) return;
            selection = request; contextLabel.textContent = request.label + (request.title ? ' · ' + request.title : '');
            currentPicker.remove(); picker = null;
            const question = pendingQuestion || 'Apa perkembangan permohonan saya?'; pendingQuestion = '';
            send(question, false);
          });
          currentPicker.appendChild(button);
        });
        if (page > 0) { const button = document.createElement('button'); button.type='button';button.textContent='Sebelumnya';button.addEventListener('click',()=>chooseRequests(page-1));currentPicker.appendChild(button); }
        if (data.more) { const button = document.createElement('button'); button.type='button';button.textContent='Berikutnya';button.addEventListener('click',()=>chooseRequests(page+1));currentPicker.appendChild(button); }
      } catch (error) { currentPicker.textContent = error.message || 'Daftar permohonan belum dapat dimuat.'; }
      finally { setBusy(false); messages.scrollTop = messages.scrollHeight; }
    }
    async function send(text, echo = true) {
      const question = String(text || '').trim(); if (!question || busy) return;
      if (echo) append(question, 'user');
      input.value = ''; setBusy(true);
      const loading = append('Sebentar, ya…'); let needsRequest = false;
      try {
        const data = await api({action:'answer', message:question, request_key:selection ? selection.key : ''});
        loading.remove(); const item = append(data.answer || 'Jawaban belum tersedia.');
        if (data.source) { const source = document.createElement('small'); source.className='sip-ai-source';source.textContent='Sumber: '+data.source;item.appendChild(source); }
        if (data.notice) { const note=document.createElement('small'); note.className='sip-ai-source'; note.textContent=data.notice; item.appendChild(note); }
        addLinks(item, data.links);
        if (data.request) { selection = data.request; contextLabel.textContent = selection.label + (selection.title ? ' · ' + selection.title : ''); }
        if (Array.isArray(data.suggestions)) setSuggestions(data.suggestions);
        needsRequest = Boolean(data.needs_request); if (needsRequest) pendingQuestion = question;
      } catch (error) {
        loading.remove(); append(error.name==='AbortError' ? 'Bantuan belum merespons. Pertanyaan tetap ada di kotak; coba kembali.' : (error.message || 'Koneksi bermasalah. Coba kembali.'),'error');
        input.value = question;
      } finally { setBusy(false); messages.scrollTop=messages.scrollHeight; }
      if (needsRequest) await chooseRequests();
    }
    toggle.addEventListener('click',()=>open(panel.hidden));
    widget.querySelector('.sip-ai-close').addEventListener('click',()=>open(false));
    widget.querySelector('[data-change-request]').addEventListener('click',()=>{ if (!busy) { pendingQuestion=''; chooseRequests(); } });
    form.addEventListener('submit',event=>{event.preventDefault();send(input.value);});
    suggestions.addEventListener('click',event=>{const button=event.target.closest('[data-question]');if(button && !busy)send(button.dataset.question);});
    document.addEventListener('keydown',event=>{if(event.key==='Escape' && !panel.hidden)open(false);});
    // General guidance can always be requested, even after a request was selected.
    const reset=document.createElement('button');reset.type='button';reset.textContent='Bantuan lainnya';
    reset.addEventListener('click',()=>{if(busy)return;selection=null;contextLabel.textContent='Bantuan lainnya';pendingQuestion='';if(picker){picker.remove();picker=null;}setSuggestions(initialQuestions);});
    widget.querySelector('.sip-ai-context').appendChild(reset);
  }
  document.addEventListener('DOMContentLoaded',()=>document.querySelectorAll('.sip-ai-widget').forEach(initWidget));
})();