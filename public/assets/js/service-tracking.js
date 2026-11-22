(function () {
    'use strict';
    const allowed = status => ({diajukan: ['diajukan', 'diproses'], diproses: ['diproses', 'selesai', 'ditolak'], selesai: ['selesai'], ditolak: ['ditolak']}[status] || []);
    function toggle(select) {
        const form = select.closest('form');
        if (!form) return;
        const progress = select.value === 'diproses' && form.elements.expected_status.value === 'diproses';
        const files = form.querySelector('input[name="dokumen_hasil[]"]');
        const uploadOnly = Boolean(files && files.files && files.files.length);
        const note = form.querySelector('textarea[name="alasan"]');
        if (note) { note.required = select.value === 'ditolak' || (progress && !uploadOnly); note.style.display = 'block'; note.removeAttribute('placeholder'); }
        const help = form.querySelector('[data-status-note-help]');
        if (help) help.textContent = select.value === 'ditolak' ? 'Alasan penolakan wajib diisi dan akan terlihat oleh pemohon.' : progress ? 'Isi catatan baru untuk memperbarui progres. Catatan terlihat oleh pemohon. Jika hanya mengunggah dokumen, catatan boleh kosong.' : 'Catatan opsional; jika diisi akan terlihat oleh pemohon.';
        const canUpload = ['diproses', 'selesai'].includes(select.value);
        const section = form.querySelector('[data-dokumen-hasil-upload]');
        const input = form.querySelector('input[name="dokumen_hasil[]"]');
        if (section) section.style.display = canUpload ? 'block' : 'none';
        if (input) input.disabled = !canUpload;
        const button = form.querySelector('button[type="submit"]');
        if (button && !form.dataset.statusBusy) button.textContent = progress ? 'Simpan Pembaruan Progres' : select.value === 'diproses' && form.elements.expected_status.value === 'diajukan' ? 'Mulai Proses' : select.value === 'selesai' && form.elements.expected_status.value === 'diproses' ? 'Selesaikan Pelayanan' : select.value === 'ditolak' && form.elements.expected_status.value === 'diproses' ? 'Tolak Permohonan' : 'Simpan Pembaruan';
    }
    function syncOptions(form, status) {
        const select = form.elements.status;
        Array.from(select.options).forEach(option => { option.disabled = !allowed(status).includes(option.value); });
        select.value = status === 'diajukan' ? 'diproses' : status;
        toggle(select);
    }
    document.addEventListener('DOMContentLoaded', function () {
        const stateNode = document.getElementById('service-tracking-state');
        if (!stateNode) return;
        const state = JSON.parse(stateNode.textContent);
        const form = document.querySelector('form.status-form');
        if (!form) return;
        form.dataset.serviceTracking = '1';
        for (const [name, value] of Object.entries({expected_status: state.status, history_version: state.version, status_ajax: '1'})) {
            const input = form.querySelector('input[name="' + name + '"]') || document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input);
        }
        const note = form.querySelector('textarea[name="alasan"]');
        if (note) {
            note.value = state.draft || ''; note.id = 'service-status-note';
            const label = document.createElement('label'); label.htmlFor = note.id; label.textContent = 'Catatan';
            const help = document.createElement('small'); help.id = 'service-status-note-help'; help.dataset.statusNoteHelp = '1'; help.style.display = 'block';
            note.before(label); note.after(help); note.setAttribute('aria-describedby', help.id);
        }
        window.toggleAlasan = toggle;
        form.elements.status.addEventListener('change', () => toggle(form.elements.status));
        const upload = form.querySelector('input[name="dokumen_hasil[]"]');
        if (upload) upload.addEventListener('change', () => toggle(form.elements.status));
        syncOptions(form, state.status);
    });
    // Own this form's submit before legacy handlers or stable-upload can navigate away.
    document.addEventListener('submit', async function (event) {
        const form = event.target;
        if (!form.matches('form[data-service-tracking]')) return;
        event.preventDefault(); event.stopImmediatePropagation();
        if (form.dataset.statusBusy || !form.reportValidity()) return;
        const message = document.getElementById('service-tracking-message');
        const button = form.querySelector('button[type="submit"]');
        const note = form.elements.alasan;
        const submittedNote = note.value;
        const body = new FormData(form);
        const select = form.elements.status;
        const upload = form.querySelector('input[name="dokumen_hasil[]"]');
        form.dataset.statusBusy = '1'; button.disabled = true; select.disabled = true;
        if (upload) upload.disabled = true;
        button.textContent = 'Menyimpan…'; message.textContent = '';
        try {
            // A hidden input named "action" shadows form.action in browsers.
            const response = await fetch(form.getAttribute('action') || window.location.href, {method: 'POST', body, credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || data.error || 'Pembaruan gagal. Periksa sesi login Anda dan coba kembali.');
            form.elements.expected_status.value = data.status;
            form.elements.history_version.value = data.history_version;
            const history = document.getElementById('service-tracking-history');
            if (history && data.history_html) history.innerHTML = data.history_html;
            document.querySelectorAll('[data-service-current-status]').forEach(el => { el.textContent = data.status.toUpperCase(); el.className = 'detail-status status-' + data.status; });
            document.querySelectorAll('[data-service-current-note]').forEach(el => {
                el.style.display = data.status_note ? 'block' : 'none';
                el.querySelector('[data-service-note-value]').textContent = data.status_note || '';
            });
            if (!data.partial && note.value === submittedNote) note.value = '';
            if (upload && data.files_uploaded) upload.value = '';
            message.style.color = data.partial ? '#92400e' : '#166534'; message.textContent = data.message;
            if (data.files_uploaded) {
                const link = document.createElement('a'); link.href = window.location.href; link.textContent = ' Lihat daftar dokumen terbaru'; message.appendChild(link);
            }
            syncOptions(form, data.status);
            window.dispatchEvent(new CustomEvent('siprima:notification-refresh'));
        } catch (error) {
            message.style.color = '#b91c1c';
            message.textContent = error instanceof SyntaxError ? 'Respons belum dapat dibaca. Catatan tetap ada; periksa status terbaru sebelum mencoba kembali.' : (error.message || 'Koneksi terputus. Periksa status terbaru sebelum mencoba kembali.');
        } finally {
            delete form.dataset.statusBusy; button.disabled = false; select.disabled = false; toggle(select);
        }
    }, true);
})();
