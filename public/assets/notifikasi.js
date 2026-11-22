document.addEventListener('DOMContentLoaded', function () {
    const notifBtn = document.getElementById('notifBtn');
    const dropdown = document.getElementById('notifDropdown');
    const list = document.getElementById('notifList');

    if (!notifBtn || !dropdown) return;

    notifBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');

        if (!dropdown.classList.contains('hidden') && list.innerHTML.includes('Klik bell')) {
            loadNotifications();
        }
    });

    document.addEventListener('click', () => dropdown.classList.add('hidden'));
    dropdown.addEventListener('click', e => e.stopPropagation());

    function loadNotifications() {
        list.innerHTML = `
            <div class="notif-loading">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <p>Memuat notifikasi...</p>
            </div>`;

        fetch('get_notifikasi.php')
            .then(r => r.json())
            .then(data => {
                if (data.length === 0) {
                    list.innerHTML = `
                        <div class="notif-empty">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p>Tidak ada notifikasi</p>
                        </div>`;
                    return;
                }

                list.innerHTML = data.map(n => `
                    <a href="status_permohonan.php" class="notif-item ${n.dibaca == 0 ? 'unread' : ''}">
                        <div class="flex">
                            <div class="icon ${n.tipe}">
                                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div class="content">
                                <div class="title">${n.judul}</div>
                                <div class="message">${n.pesan}</div>
                                <div class="time">${formatTime(n.created_at)}</div>
                            </div>
                        </div>
                    </a>
                `).join('');
            })
            .catch(() => {
                list.innerHTML = '<div class="notif-empty"><p class="text-red-600">Gagal memuat</p></div>';
            });
    }

    function formatTime(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const diff = (now - date) / 1000;
        if (diff < 60) return 'Baru saja';
        if (diff < 3600) return Math.floor(diff / 60) + ' menit lalu';
        if (diff < 86400) return Math.floor(diff / 3600) + ' jam lalu';
        return date.toLocaleDateString('id-ID');
    }
});