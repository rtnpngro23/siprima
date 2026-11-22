(() => {
  function node(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text) el.textContent = text;
    return el;
  }
  function create(root) {
    root.className = 'service-notice';
    root.setAttribute('aria-label', 'Pemberitahuan layanan');
    const heading = node('h2', 'service-notice-title');
    const summary = node('p', 'service-notice-summary');
    const resume = node('p', 'service-notice-resume');
    const details = node('details', 'service-notice-details');
    const toggle = node('summary', '', 'Lihat detail pengumuman');
    const detail = node('p', 'service-notice-detail');
    details.append(toggle, detail);
    const countdown = node('p', 'service-notice-countdown');
    countdown.setAttribute('role', 'status');
    root.append(heading, summary, resume, details, countdown);
    function setText(el, text) {
      if (el.textContent !== (text || '')) el.textContent = text || '';
    }
    return {
      update(state) {
        root.hidden = !state.message;
        root.dataset.tone = !state.is_open ? 'closed' : (state.countdown ? 'warning' : 'info');
        setText(heading, state.title || 'Pemberitahuan layanan');
        setText(summary, state.summary || state.message);
        setText(resume, state.resume);
        resume.hidden = !state.resume;
        setText(detail, state.detail);
        details.hidden = !state.detail;
        setText(countdown, state.countdown);
        countdown.hidden = !state.countdown;
      }
    };
  }
  window.SiprimaServiceNotice = {create};

  const seed = document.getElementById('service-home-notice-state');
  const root = document.getElementById('service-home-notice');
  if (!seed || !root) return;
  const state = JSON.parse(seed.textContent);
  root.replaceChildren();
  create(root).update(state);
  const notice = state.notice;
  if (!notice || notice.type !== 'closure') return;
  const dialog = document.getElementById('service-announcement-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const key = 'siprima.notice.' + notice.id;
  let remembered = false;
  for (const name of ['localStorage', 'sessionStorage']) {
    try { remembered = remembered || window[name].getItem(key) === notice.version; } catch (_) {}
  }
  const reopen = node('button', 'service-notice-reopen', 'Buka pengumuman');
  reopen.type = 'button';
  root.append(reopen);
  let focusBefore;
  let previousOverflow;
  function open() {
    if (dialog.open) return;
    focusBefore = document.activeElement;
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    dialog.showModal();
  }
  function close() { dialog.close(); }
  reopen.addEventListener('click', open);
  dialog.querySelectorAll('[data-notice-close]').forEach(button => button.addEventListener('click', close));
  dialog.addEventListener('click', event => { if (event.target === dialog) close(); });
  dialog.addEventListener('close', () => {
    document.body.style.overflow = previousOverflow || '';
    for (const name of ['localStorage', 'sessionStorage']) {
      try { window[name].setItem(key, notice.version); } catch (_) {}
    }
    if (focusBefore && typeof focusBefore.focus === 'function') focusBefore.focus();
  });
  if (!remembered) open();
})();