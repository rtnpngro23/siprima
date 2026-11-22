// ============================================
// ADMIN COMMON JAVASCRIPT
// Shared JS for all admin pages
// ============================================

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

// Close modal when clicking outside or pressing ESC
if (logoutModal) {
    logoutModal.addEventListener('click', function(event) {
        if (event.target === logoutModal) {
            hideLogoutModal();
        }
    });
}

// Close notification
function closeNotification() {
    const notification = document.getElementById('notification');
    if (notification) {
        notification.style.display = 'none';
    }
}

// Auto close notification after 5 seconds
setTimeout(() => {
    closeNotification();
}, 5000);

// Validasi file
function validateFile(input) {
    const maxSize = 5 * 1024 * 1024; // 5MB
    const allowedTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];

    if (input.files.length > 0) {
        const file = input.files[0];
        const fileType = file.name.split('.').pop().toLowerCase();

        if (file.size > maxSize) {
            alert('Ukuran file terlalu besar. Maksimal 5MB');
            input.value = '';
            return false;
        }

        if (!allowedTypes.includes(fileType)) {
            alert('Format file tidak diizinkan. Hanya PDF, DOC, XLS, JPG, PNG');
            input.value = '';
            return false;
        }
    }
    return true;
}
