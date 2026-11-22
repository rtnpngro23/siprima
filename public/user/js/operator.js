document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    const logoutModal = document.getElementById('logoutModal');

    // Toggle sidebar mobile
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', e => {
            e.stopPropagation();
            sidebar.classList.toggle('open');
        });
    }

    // LOGOUT MODAL
    if (logoutModal) {
        const showModal = () => logoutModal.classList.add('show');
        const hideModal = () => logoutModal.classList.remove('show');

        // Tombol logout di sidebar dan header
        document.getElementById('logoutBtn')?.addEventListener('click', showModal);
        document.getElementById('logoutBtn2')?.addEventListener('click', showModal);

        document.getElementById('cancelLogout')?.addEventListener('click', hideModal);
        document.getElementById('confirmLogout')?.addEventListener('click', () => {
            window.location.href = '../logout.php'; // sesuaikan path logout
        });

        // Tutup saat klik di luar modal
        logoutModal.addEventListener('click', e => {
            if (e.target === logoutModal) hideModal();
        });
    }

    console.log('operator.js loaded successfully');
});