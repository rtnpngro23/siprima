(() => {
  const seed = document.getElementById('service-availability-state');
  if (!seed) return;
  let state = JSON.parse(seed.textContent);
  const banner = document.createElement('section');
  const noticeView = window.SiprimaServiceNotice.create(banner);
  const main = document.querySelector('main, .main, .main-content') || document.body;
  main.prepend(banner);
  const formPage = /^(?:(?:petugas_desa_)?(?:kk|akta_kelahiran|akta_kematian|pindah_alamat|pindah_online)|lapor_peristiwa|edit_(?:kk|lapor_peristiwa|permohonan_akta_kelahiran|permohonan_akta_kematian|pindah_alamat|pindah_online)|petugas_desa_edit_(?:kk|akta_kelahiran|akta_kematian|pindah_alamat|pindah_online))\.php$/;
  function apply(next) {
    state = next;
    noticeView.update(next);
    if (formPage.test(location.pathname.split('/').pop())) {
      document.querySelectorAll('form button[type="submit"], form input[type="submit"]').forEach(button => {
        if (!next.is_open && !button.disabled) { button.dataset.serviceDisabled = '1'; button.disabled = true; }
        else if (next.is_open && button.dataset.serviceDisabled) { button.disabled = false; delete button.dataset.serviceDisabled; }
      });
    }
    document.querySelectorAll('a[href]').forEach(link => {
      const url = new URL(link.href, location.href);
      if (url.origin !== location.origin || !formPage.test(url.pathname.split('/').pop())) return;
      if (!next.is_open) { link.setAttribute('aria-disabled', 'true'); link.dataset.serviceBlocked = '1'; }
      else if (link.dataset.serviceBlocked) { link.removeAttribute('aria-disabled'); delete link.dataset.serviceBlocked; }
    });
  }
  document.addEventListener('click', event => {
    if (!state.is_open && event.target.closest('a[data-service-blocked]')) {
      event.preventDefault(); event.stopImmediatePropagation(); banner.scrollIntoView({block:'center'});
    }
  }, true);
  document.addEventListener('submit', event => {
    if (!state.is_open && formPage.test(location.pathname.split('/').pop())) {
      event.preventDefault(); event.stopImmediatePropagation(); banner.scrollIntoView({block:'center'});
    }
  }, true);
  async function refresh() {
    try {
      const response = await fetch('service_status.php', {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}});
      if (response.ok) apply(await response.json());
    } catch (_) { /* Server checks every submission independently. */ }
  }
  apply(state);
  setInterval(refresh, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();