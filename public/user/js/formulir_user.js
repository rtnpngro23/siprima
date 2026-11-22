(function(){
  function init(){
    // Toggle Sidebar Menu
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if(menuToggle && sidebar){
      menuToggle.addEventListener('click', function(){ sidebar.classList.toggle('open'); });
      document.addEventListener('click', function(event){
        if(window.innerWidth <= 768){
          if(!sidebar.contains(event.target) && !menuToggle.contains(event.target) && sidebar.classList.contains('open')){
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

    function showLogoutModal(){ logoutModal.classList.add('show'); document.body.style.overflow = 'hidden'; }
    function hideLogoutModal(){ logoutModal.classList.remove('show'); document.body.style.overflow = ''; }
    if(logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if(logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if(confirmLogout) confirmLogout.addEventListener('click', function(){ window.location.href = '../logout.php'; });
    if(cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    logoutModal?.addEventListener('click', function(event){ if(event.target === logoutModal) hideLogoutModal(); });

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if(searchInput){
      searchInput.addEventListener('input', function(){
        const searchTerm = this.value.toLowerCase();
        const items = document.querySelectorAll('.file-item');
        let visibleCount = 0;
        items.forEach(item => {
          const fileName = item.getAttribute('data-name');
          if(fileName.includes(searchTerm)) { item.style.display = 'flex'; visibleCount++; } else { item.style.display = 'none'; }
        });
        const formulirList = document.getElementById('formulirList');
        const existingMessage = formulirList.querySelector('.no-results-message');
        if (searchTerm && visibleCount === 0) {
          if (!existingMessage) {
            const message = document.createElement('div');
            message.className = 'empty-state no-results-message';
            message.innerHTML = `
                <i class="fas fa-search fa-3x"></i>
                <h4>Tidak ditemukan formulir</h4>
                <p>Tidak ada formulir yang sesuai dengan pencarian "${searchTerm}"</p>
            `;
            formulirList.appendChild(message);
          }
        } else if (existingMessage) {
          existingMessage.remove();
        }
      });
    }
  }

  if(window._userUtils && typeof window._userUtils.ready === 'function') window._userUtils.ready(init); else document.addEventListener('DOMContentLoaded', init);
})();