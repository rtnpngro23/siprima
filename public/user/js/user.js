document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    const logoutModal = document.getElementById('logoutModal');

    // === DROPDOWN MENU (jika ada dropdown di sidebar) ===
    document.querySelectorAll('.dropdown-toggle').forEach(button => {
        button.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();

            const parentLi = button.closest('li.dropdown, li.dropdown-sub');
            if (!parentLi) return;

            parentLi.classList.toggle('open');

            // Tutup dropdown lain di level yang sama
            const siblings = [...parentLi.parentElement.children].filter(
                el => el !== parentLi && (el.classList.contains('dropdown') || el.classList.contains('dropdown-sub'))
            );
            siblings.forEach(sib => sib.classList.remove('open'));
        });
    });

    // Tutup semua dropdown saat klik di luar sidebar
    document.addEventListener('click', () => {
        document.querySelectorAll('li.dropdown.open, li.dropdown-sub.open').forEach(el => {
            el.classList.remove('open');
        });
    });

    // === TOGGLE SIDEBAR MOBILE ===
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', e => {
            e.stopPropagation();
            sidebar.classList.toggle('open');
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.show').forEach(modal => {
                modal.classList.remove('show');
            });
        }
    });

    // === LOGOUT MODAL ===
    if (logoutModal) {
        const showModal = () => logoutModal.classList.add('show');
        const hideModal = () => logoutModal.classList.remove('show');

        // Tombol logout di sidebar dan di header
        document.getElementById('logoutBtn')?.addEventListener('click', showModal);
        document.getElementById('logoutBtn2')?.addEventListener('click', showModal);

        // Tombol batal
        document.getElementById('cancelLogout')?.addEventListener('click', hideModal);

        // Tombol konfirmasi logout
        document.getElementById('confirmLogout')?.addEventListener('click', () => {
            window.location.href = 'logout.php'; // sesuaikan nama file logout Anda
        });

        // Tutup modal saat klik di luar kotak modal
        logoutModal.addEventListener('click', e => {
            if (e.target === logoutModal) hideModal();
        });
    }

    // Debug: pastikan script berjalan (bisa dihapus nanti)
    console.log('user.js berhasil dimuat');
});