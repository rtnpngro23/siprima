<?php
if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/../error_handler.php';
}
$notificationCsrfToken = csrf_token();
// Widget juga dipakai halaman user_kua/user_rs/user_ahlak; endpoint tetap di folder user.
$notificationScope = basename(dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
if (!in_array($notificationScope, ['user', 'user_kua', 'user_rs', 'user_ahlak'], true)) $notificationScope = 'user';
$notificationEndpoint = $notificationScope === 'user' ? 'get_notifikasi.php' : '../user/get_notifikasi.php';
?>
<style>
.notification-widget{position:relative;display:inline-flex;align-items:center}.notification-button{position:relative;width:38px;height:38px;border:0;border-radius:50%;background:#f1f5f9;color:#334155;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.notification-button:hover,.notification-button:focus{background:#e2e8f0;outline:2px solid transparent}.notification-badge{position:absolute;top:-4px;right:-5px;min-width:18px;height:18px;padding:0 5px;border-radius:10px;background:#dc2626;color:#fff;font-size:11px;font-weight:700;line-height:18px;text-align:center}.notification-badge[hidden]{display:none}.notification-dropdown{position:absolute;right:0;top:46px;width:min(360px,calc(100vw - 24px));max-height:430px;overflow:hidden;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 16px 40px rgba(15,23,42,.18);z-index:1200;display:none;color:#1e293b;text-align:left}.notification-dropdown.show{display:block}.notification-title{padding:14px 16px;border-bottom:1px solid #e2e8f0;font-weight:700}.notification-list{max-height:330px;overflow-y:auto}.notification-item{padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fff}.notification-item.unread{background:#eff6ff}.notification-item-title{font-size:14px;font-weight:700;margin-bottom:4px}.notification-item-message{font-size:13px;line-height:1.4;color:#475569;white-space:normal}.notification-item-time{font-size:11px;color:#94a3b8;margin-top:6px}.notification-item.success{border-left:4px solid #16a34a}.notification-item.danger{border-left:4px solid #dc2626}.notification-item.warning{border-left:4px solid #d97706}.notification-item.info{border-left:4px solid #2563eb}.notification-empty{padding:28px 16px;text-align:center;color:#64748b;font-size:13px}@media(max-width:640px){.notification-dropdown{position:fixed;top:64px;right:12px;left:12px;width:auto}}
.notification-item{display:block;color:inherit;text-decoration:none;cursor:pointer}.notification-item:hover{background:#f8fafc}.notification-item.unread:hover{background:#dbeafe}
.notification-more{display:block;width:100%;padding:10px 16px;border:0;border-top:1px solid #e2e8f0;background:#f8fafc;color:#2563eb;font-size:13px;font-weight:600;cursor:pointer}.notification-more[hidden]{display:none}.notification-more:disabled{color:#94a3b8;cursor:wait}
</style>
<div class="notification-widget" data-notification-widget data-endpoint="<?= htmlspecialchars($notificationEndpoint, ENT_QUOTES, 'UTF-8') ?>" data-scope="<?= htmlspecialchars($notificationScope, ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars($notificationCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <button type="button" class="notification-button" data-notification-button aria-label="Notifikasi" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
        <span class="notification-badge" data-notification-badge hidden></span>
    </button>
    <div class="notification-dropdown" data-notification-dropdown>
        <div class="notification-title">Notifikasi</div>
        <div class="notification-list" data-notification-list><div class="notification-empty">Memuat notifikasi...</div></div>
        <button type="button" class="notification-more" data-notification-more hidden>Muat lebih banyak</button>
    </div>
</div>
<script>
(() => {
  const widget = document.currentScript.previousElementSibling;
  if (!widget || !widget.matches('[data-notification-widget]')) return;
  const button = widget.querySelector('[data-notification-button]');
  const dropdown = widget.querySelector('[data-notification-dropdown]');
  const badge = widget.querySelector('[data-notification-badge]');
  const list = widget.querySelector('[data-notification-list]');
  const moreButton = widget.querySelector('[data-notification-more]');
  const endpoint = widget.dataset.endpoint;
  const csrf = widget.dataset.csrf;
  const listUrl = beforeId => endpoint + '?' + new URLSearchParams(beforeId ? {scope:widget.dataset.scope || 'user', before_id:String(beforeId)} : {scope:widget.dataset.scope || 'user'}).toString();
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  let newestId = 0;
  let oldestId = 0;

  const setBadge = value => {
    const count = Number(value || 0);
    badge.hidden = count < 1;
    badge.textContent = count > 99 ? '99+' : String(count);
  };

  const itemHtml = item => {
    const tag = item.target_url ? 'a' : 'div';
    const href = item.target_url ? ` href="${escapeHtml(item.target_url)}"` : '';
    return `
      <${tag}${href} class="notification-item ${escapeHtml(item.tipe || 'info')} ${item.dibaca ? '' : 'unread'}" data-notification-id="${Number(item.id) || 0}">
        <div class="notification-item-title">${escapeHtml(item.judul)}</div>
        <div class="notification-item-message">${escapeHtml(item.pesan)}</div>
        <div class="notification-item-time">${escapeHtml(item.waktu_relatif || item.created_at)}</div>
      </${tag}>`;
  };

  const request = url => fetch(url, {headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'})
    .then(response => response.ok ? response.json() : Promise.reject(new Error('HTTP ' + response.status)))
    .then(data => data && data.success ? data : Promise.reject(new Error('Respons tidak valid')));

  const post = params => fetch(endpoint, {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},credentials:'same-origin',keepalive:true,body:new URLSearchParams({...params,csrf_token:csrf})})
    .then(response => response.ok ? response.json() : Promise.reject(new Error('HTTP ' + response.status)));

  const renderPage = (data, append) => {
    const items = Array.isArray(data.items) ? data.items : [];
    if (!append) {
      list.innerHTML = items.length ? '' : '<div class="notification-empty">Belum ada notifikasi.</div>';
      newestId = items.length ? Number(items[0].id) || 0 : 0;
    }
    list.insertAdjacentHTML('beforeend', items.map(itemHtml).join(''));
    if (items.length) oldestId = Number(items[items.length - 1].id) || 0;
    moreButton.hidden = !data.has_more;
  };

  // Polling hanya memperbarui badge; daftar tidak diganti saat dropdown terbuka agar item "Muat lebih banyak" tetap ada.
  const refresh = () => request(listUrl(0))
    .then(data => { setBadge(data.unread_count); if (!dropdown.classList.contains('show')) renderPage(data, false); })
    .catch(() => {});

  window.addEventListener('siprima:notification-refresh', refresh);

  button.addEventListener('click', event => {
    event.stopPropagation();
    const open = dropdown.classList.toggle('show');
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) return;
    // Badge hanya dihapus setelah daftar benar-benar tampil; notifikasi yang masuk sesudahnya tetap terhitung.
    request(listUrl(0))
      .then(data => {
        renderPage(data, false);
        setBadge(data.unread_count);
        if (newestId > 0) return post({action:'mark_seen', max_id:String(newestId)}).then(result => setBadge(result.unread_count));
      })
      .catch(() => { list.innerHTML = '<div class="notification-empty">Notifikasi gagal dimuat.</div>'; moreButton.hidden = true; });
  });

  moreButton.addEventListener('click', () => {
    moreButton.disabled = true;
    request(listUrl(oldestId))
      .then(data => { renderPage(data, true); moreButton.textContent = 'Muat lebih banyak'; })
      .catch(() => { moreButton.textContent = 'Gagal memuat, coba lagi'; })
      .finally(() => { moreButton.disabled = false; });
  });

  // Status dibaca hanya berubah untuk notifikasi yang diklik.
  list.addEventListener('click', event => {
    const item = event.target.closest('[data-notification-id]');
    if (!item || !item.classList.contains('unread')) return;
    item.classList.remove('unread');
    const sent = post({action:'mark_read', id:item.dataset.notificationId}).catch(() => {});
    const href = item.getAttribute('href');
    const newTab = event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1;
    if (!href || newTab) return;
    event.preventDefault();
    Promise.race([sent, new Promise(resolve => setTimeout(resolve, 800))]).then(() => { window.location.href = href; });
  });

  dropdown.addEventListener('click', event => event.stopPropagation());
  document.addEventListener('click', () => { dropdown.classList.remove('show'); button.setAttribute('aria-expanded','false'); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') { dropdown.classList.remove('show'); button.setAttribute('aria-expanded','false'); } });
  refresh();
  window.setInterval(() => { if (!document.hidden) refresh(); }, 30000);
})();
</script>
