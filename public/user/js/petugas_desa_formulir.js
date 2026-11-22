// Toggle Sidebar Menu on Mobile
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle
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
    
    // Logout Modal functionality
    setupLogoutModal();
    
    // Search functionality
    setupSearch();
    
    // Auto-focus search input
    setTimeout(() => {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.focus();
        }
    }, 300);
    
    // Add active class to current page in sidebar
    highlightActiveMenuItem();
    
    // Add smooth scroll behavior
    addSmoothScroll();
    
    // Initialize tooltips if Bootstrap is available
    if (typeof bootstrap !== 'undefined') {
        initTooltips();
    }
});

// Setup Logout Modal
function setupLogoutModal() {
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
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
                hideLogoutModal();
            }
        });
    }
}

// Setup Search Functionality
function setupSearch() {
    const searchInput = document.getElementById('searchInput');
    if (!searchInput) return;
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        const items = document.querySelectorAll('.formulir-card');
        let visibleCount = 0;
        
        items.forEach(item => {
            const fileName = item.getAttribute('data-name') || '';
            const fileType = item.getAttribute('data-type') || '';
            
            // Search in filename and file type
            const matchesSearch = fileName.includes(searchTerm) || 
                                 fileType.includes(searchTerm);
            
            if (searchTerm === '' || matchesSearch) {
                item.style.display = 'flex';
                visibleCount++;
                item.classList.remove('hidden-by-search');
            } else {
                item.style.display = 'none';
                item.classList.add('hidden-by-search');
            }
        });
        
        // Show/hide no results message
        const formulirList = document.getElementById('formulirList');
        const existingMessage = formulirList.querySelector('.no-results-message');
        
        if (searchTerm && visibleCount === 0) {
            if (!existingMessage) {
                const message = createNoResultsMessage(searchTerm);
                formulirList.appendChild(message);
            } else {
                existingMessage.querySelector('p').textContent = 
                    `Tidak ada formulir yang sesuai dengan pencarian "${searchTerm}"`;
            }
        } else if (existingMessage) {
            existingMessage.remove();
        }
        
        // Update counter
        updateFormulirCount(visibleCount, items.length);
    });
    
    // Add clear search button
    addClearSearchButton(searchInput);
}

// Create No Results Message
function createNoResultsMessage(searchTerm) {
    const message = document.createElement('div');
    message.className = 'empty-state no-results-message';
    message.innerHTML = `
        <div class="empty-state-icon">
            <i class="bi bi-search"></i>
        </div>
        <h4>Tidak ditemukan formulir</h4>
        <p>Tidak ada formulir yang sesuai dengan pencarian "${searchTerm}"</p>
        <button class="btn-clear-search" style="margin-top: 20px;">
            <i class="bi bi-x-circle"></i> Hapus Pencarian
        </button>
    `;
    
    // Add clear search functionality
    message.querySelector('.btn-clear-search').addEventListener('click', function() {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
        }
    });
    
    return message;
}

// Update Formulir Count Display
function updateFormulirCount(visible, total) {
    const counter = document.querySelector('.formulir-count');
    if (counter) {
        if (visible === total) {
            counter.textContent = `Total: ${total} formulir`;
        } else {
            counter.textContent = `Menampilkan: ${visible} dari ${total} formulir`;
            counter.style.background = '#dbeafe';
            counter.style.color = '#1e40af';
        }
    }
}

// Add Clear Search Button
function addClearSearchButton(searchInput) {
    const searchBox = searchInput.parentElement;
    let clearBtn = searchBox.querySelector('.clear-search');
    
    if (!clearBtn) {
        clearBtn = document.createElement('button');
        clearBtn.className = 'clear-search';
        clearBtn.innerHTML = '<i class="bi bi-x"></i>';
        clearBtn.style.cssText = `
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            font-size: 18px;
            display: none;
            padding: 5px;
            border-radius: 50%;
        `;
        
        clearBtn.addEventListener('mouseenter', function() {
            this.style.background = '#f3f4f6';
        });
        
        clearBtn.addEventListener('mouseleave', function() {
            this.style.background = 'none';
        });
        
        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
            searchInput.focus();
            this.style.display = 'none';
        });
        
        searchBox.appendChild(clearBtn);
    }
    
    // Show/hide clear button based on input
    searchInput.addEventListener('input', function() {
        clearBtn.style.display = this.value ? 'block' : 'none';
    });
}

// Highlight Active Menu Item
function highlightActiveMenuItem() {
    const currentPath = window.location.pathname;
    const sidebarLinks = document.querySelectorAll('.sidebar nav a');
    
    sidebarLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && currentPath.includes(href)) {
            link.parentElement.classList.add('active');
        } else {
            link.parentElement.classList.remove('active');
        }
    });
}

// Add Smooth Scroll Behavior
function addSmoothScroll() {
    const style = document.createElement('style');
    style.textContent = `
        html {
            scroll-behavior: smooth;
        }
        
        /* Smooth scroll for anchor links */
        a[href^="#"] {
            transition: all 0.3s ease;
        }
    `;
    document.head.appendChild(style);
}

// Initialize Tooltips
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Add CSS for hidden items and search button
document.addEventListener('DOMContentLoaded', function() {
    const style = document.createElement('style');
    style.textContent = `
        .hidden-by-search {
            opacity: 0;
            transform: scale(0.95);
            transition: opacity 0.3s, transform 0.3s;
        }
        
        .no-results-message {
            grid-column: 1 / -1;
            padding: 40px 20px;
        }
        
        .btn-clear-search {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-clear-search:hover {
            background: #2980b9;
        }
        
        /* File type specific colors */
        .file-pdf .formulir-icon {
            color: #dc2626;
        }
        
        .file-doc .formulir-icon {
            color: #2563eb;
        }
        
        .file-xls .formulir-icon {
            color: #059669;
        }
        
        .file-img .formulir-icon {
            color: #7c3aed;
        }
        
        .file-other .formulir-icon {
            color: #6b7280;
        }
    `;
    document.head.appendChild(style);
});

// Add keyboard shortcuts
document.addEventListener('keydown', function(event) {
    // Ctrl/Cmd + F to focus search
    if ((event.ctrlKey || event.metaKey) && event.key === 'f') {
        event.preventDefault();
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }
    
    // Escape to clear search
    if (event.key === 'Escape') {
        const searchInput = document.getElementById('searchInput');
        if (searchInput && searchInput.value) {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
        }
    }
});

// Add download tracking (optional analytics)
document.addEventListener('click', function(event) {
    if (event.target.closest('.btn-download')) {
        const downloadBtn = event.target.closest('.btn-download');
        const fileName = downloadBtn.getAttribute('download') || 'Unknown';
        console.log(`Formulir downloaded: ${fileName}`);
        // Here you could send this data to your analytics service
    }
});