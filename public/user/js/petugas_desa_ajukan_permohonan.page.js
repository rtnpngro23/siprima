/* Extracted JS for petugas_desa_ajukan_permohonan.php
   Uses window._userUtils.ready when available. If not, falls back to DOMContentLoaded. */
(function(){
  const init = function(){
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main');

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

    // Logout Modal - SAMA dengan dashboard_user
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');

    function showLogoutModal() {
      logoutModal.classList.add('show');
      document.body.style.overflow = 'hidden';
    }

    function hideLogoutModal() {
      logoutModal.classList.remove('show');
      document.body.style.overflow = '';
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

    // Auto-remove toasts (if any)
    if (document.querySelector('.toast')) {
      window._userUtils?.removeToasts(5000);
    }
  };

  if (window._userUtils && typeof window._userUtils.ready === 'function') {
    window._userUtils.ready(init);
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }
})();