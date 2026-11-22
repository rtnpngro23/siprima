document.addEventListener('DOMContentLoaded', function () {
    // Sidebar toggle mobile
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (menuToggle) {
        menuToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    }

    // Logout modal
    const logoutModal = document.getElementById('logoutModal');
    document.querySelectorAll('#logoutBtn, #logoutBtn2').forEach(btn => {
        btn.addEventListener('click', () => logoutModal.classList.add('show'));
    });
    document.getElementById('cancelLogout').addEventListener('click', () => logoutModal.classList.remove('show'));
    document.getElementById('confirmLogout').addEventListener('click', () => location.href = '../logout.php');

    // Close modal outside click
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', e => {
            if (e.target === modal) modal.classList.remove('show');
        });
    });

    // Add user modal
    const addModal = document.getElementById('addModal');
    window.openAddModal = () => {
        addModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        addModal.querySelector('form').reset();
    };
    window.closeAddModal = () => {
        addModal.classList.remove('show');
        document.body.style.overflow = '';
    };

    // Auto close notification
    setTimeout(() => {
        const notif = document.querySelector('.notification');
        if (notif) notif.style.opacity = '0';
    }, 5000);
});