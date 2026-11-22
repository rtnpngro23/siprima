'use strict';
// Keep reset bearer tokens in the URL fragment and memory, never in localStorage.
const resetToken = document.getElementById('resetToken');
if (resetToken) {
    const token = new URLSearchParams(location.hash.slice(1)).get('token') || '';
    if (/^[a-f0-9]{64}$/.test(token)) resetToken.value = token;
}

// Progressive enhancement: fields remain usable when JavaScript is unavailable.
document.querySelectorAll('[data-password-target]').forEach((button) => {
    const input = document.getElementById(button.dataset.passwordTarget);
    if (!input) return;
    const label = button.getAttribute('aria-label');
    button.hidden = false;
    button.addEventListener('click', () => {
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Tutup' : 'Lihat';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', visible ? label.replace('Tampilkan', 'Sembunyikan') : label);
    });
});
