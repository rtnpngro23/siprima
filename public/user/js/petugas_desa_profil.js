// JavaScript untuk Halaman Profil Petugas Desa

document.addEventListener('DOMContentLoaded', function() {
    function ensureCsrfToken(form) {
        if (!form) return;
        const existing = form.querySelector('input[name="csrf_token"]');
        if (existing && existing.value.trim() !== '') return;

        const token = (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '').trim();
        if (!token) return;

        const hidden = existing || document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'csrf_token';
        hidden.value = token;

        if (!existing) {
            form.prepend(hidden);
        }
    }

    // === 1. Toggle Sidebar Menu on Mobile ===
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
    
    // === 2. Logout Modal ===
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
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
    
    // === 3. Show/Hide Password ===
    const showPwCheckbox = document.getElementById('show_pw');
    
    if (showPwCheckbox) {
        showPwCheckbox.addEventListener('change', function() {
            const currentPw = document.getElementById('current_password');
            const newPw = document.getElementById('new_password');
            const confirmPw = document.getElementById('confirm_password');
            const inputType = this.checked ? 'text' : 'password';
            currentPw.type = inputType;
            newPw.type = inputType;
            confirmPw.type = inputType;
        });
    }
    
    // === 4. Auto-hide Toast Notifications ===
    const toast = document.querySelector('.toast');
    if (toast) {
        setTimeout(() => {
            toast.remove();
        }, 5000);
    }
    
    // === 5. Form Validation Enhancement ===
    const passwordForm = document.getElementById('passwordForm') ||
                        document.querySelector('form[action="change_password"]') || 
                        document.querySelector('input[name="action"][value="change_password"]')?.closest('form');
    const contactForm = document.getElementById('contactForm');

    ensureCsrfToken(contactForm);
    ensureCsrfToken(passwordForm);

    if (passwordForm) {
        passwordForm.addEventListener('submit', function(e) {
            ensureCsrfToken(passwordForm);
            const currentPw = document.getElementById('current_password').value;
            const newPw = document.getElementById('new_password').value;
            const confirmPw = document.getElementById('confirm_password').value;
            
            if (newPw !== confirmPw) {
                e.preventDefault();
                alert('Password baru dan konfirmasi password tidak cocok!');
                return false;
            }
            
            if (newPw.length < 8) {
                e.preventDefault();
                alert('Password baru minimal 8 karakter!');
                return false;
            }

            if (currentPw === newPw) {
                e.preventDefault();
                alert('Password baru harus berbeda dari password saat ini!');
                return false;
            }
        });
    }
    
    // === 6. Input Field Validation ===
    const emailInput = document.querySelector('input[name="email"]');
    if (emailInput) {
        emailInput.addEventListener('blur', function() {
            const email = this.value.trim();
            if (email && !validateEmail(email)) {
                this.style.borderColor = '#e74c3c';
                this.style.boxShadow = '0 0 0 3px rgba(231, 76, 60, 0.1)';
            } else {
                this.style.borderColor = '#d1d5db';
                this.style.boxShadow = 'none';
            }
        });
    }
    
    const noHpInput = document.querySelector('input[name="no_hp"]');
    if (noHpInput) {
        noHpInput.addEventListener('input', function() {
            // Hanya angka yang diizinkan
            this.value = this.value.replace(/[^\d]/g, '');
        });
    }
});

// Helper function untuk validasi email
function validateEmail(email) {
    const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
    return re.test(String(email).toLowerCase());
}
