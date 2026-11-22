// Common JavaScript for user dashboard and related pages
document.addEventListener('DOMContentLoaded', function() {
    // Toggle Sidebar Menu on Mobile
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');

    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) &&
                    !menuToggle.contains(event.target) &&
                    sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            }
        });
    }

    // Logout Modal
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');

    function showLogoutModal() {
        if (logoutModal) {
            logoutModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function hideLogoutModal() {
        if (logoutModal) {
            logoutModal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    if (logoutBtn) {
        logoutBtn.addEventListener('click', showLogoutModal);
    }

    if (logoutBtn2) {
        logoutBtn2.addEventListener('click', showLogoutModal);
    }

    if (confirmLogout) {
        confirmLogout.addEventListener('click', function() {
            window.location.href = '../logout.php';
        });
    }

    if (cancelLogout) {
        cancelLogout.addEventListener('click', hideLogoutModal);
    }

    // Close modal when clicking outside
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) {
                hideLogoutModal();
            }
        });
    }

    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
});
