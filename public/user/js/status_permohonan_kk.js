// JavaScript untuk status permohonan KK
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
    
    // Preserve filter when changing pages
    const filterSelect = document.getElementById('filterStatus');
    if (filterSelect) {
        // Update URL when filter changes
        filterSelect.addEventListener('change', function() {
            const filterValue = this.value;
            let url = '?page=1'; // Reset ke halaman 1 saat filter berubah
            if (filterValue) {
                url += '&filter=' + filterValue;
            }
            window.location.href = url;
        });
    }
});

// Filter Status (Client-side filtering sebagai alternatif)
document.addEventListener('DOMContentLoaded', function() {
    const filterSelect = document.getElementById('filterStatus');
    if (filterSelect) {
        filterSelect.addEventListener('change', function() {
            const filterValue = this.value.toLowerCase();
            const rows = document.querySelectorAll('.permohonan-row');
            
            rows.forEach(row => {
                const status = row.getAttribute('data-status');
                if (!filterValue || status === filterValue) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
});

// Show Detail Modal
window.showDetailModal = function(data) {
    console.log('Data untuk modal:', data); // Debug
    
    // Fill modal with data
    document.getElementById('detail-id').textContent = data.id || '-';
    document.getElementById('detail-tanggal').textContent = data.formatted_date || data.created_at || '-';
    document.getElementById('detail-kecamatan').textContent = data.nama_kecamatan || '-';
    document.getElementById('detail-desa').textContent = data.nama_desa || '-';
    document.getElementById('detail-kepala-keluarga').textContent = data.nama_kepalaKeluarga || '-';
    
    // Status dengan styling
    const statusElement = document.getElementById('detail-status');
    const status = data.status || 'Pending';
    statusElement.textContent = status;
    statusElement.className = 'detail-value status-badge status-' + status.toLowerCase();
    
    // Anggota Keluarga
    const anggotaElement = document.getElementById('detail-anggota');
    try {
        const anggota = JSON.parse(data.anggotaKeluarga);
        if (Array.isArray(anggota) && anggota.length > 0) {
            let html = '';
            anggota.forEach((anggota, index) => {
                html += `${index + 1}. ${anggota.nama || '-'} (NIK: ${anggota.nik || '-'}) - Hubungan: ${anggota.hubungan || '-'}\n`;
            });
            anggotaElement.textContent = html;
        } else {
            anggotaElement.textContent = data.anggotaKeluarga || '-';
        }
    } catch (e) {
        anggotaElement.textContent = data.anggotaKeluarga || '-';
    }
    
    // Keterangan
    const keteranganContainer = document.getElementById('keterangan-container');
    const keteranganElement = document.getElementById('detail-keterangan');
    if (data.keterangan && data.keterangan.trim() !== '') {
        keteranganElement.textContent = data.keterangan;
        keteranganContainer.style.display = 'block';
    } else {
        keteranganContainer.style.display = 'none';
    }
    
    // Dokumen
    const dokumenElement = document.getElementById('detail-dokumen');
    if (data.dokumen && data.dokumen !== 'null') {
        try {
            const documents = JSON.parse(data.dokumen);
            if (Array.isArray(documents) && documents.length > 0) {
                let html = '';
                documents.forEach(doc => {
                    const typeLabels = {
                        'ktp': 'KTP',
                        'kk_lama': 'KK Lama',
                        'formulir': 'Formulir',
                        'surat_nikah': 'Surat Nikah',
                        'lainnya': 'Dokumen Lain'
                    };
                    
                    const typeLabel = typeLabels[doc.type] || 'Dokumen';
                    const docName = doc.original_name || 'Dokumen';
                    const docPath = doc.path || '';
                    
                    if (docPath) {
                        html += `
                            <div class="document-item">
                                <span class="doc-type">${typeLabel}:</span>
                                <a href="${docPath}" target="_blank" class="doc-link">${docName}</a>
                            </div>
                        `;
                    } else {
                        html += `
                            <div class="document-item">
                                <span class="doc-type">${typeLabel}:</span>
                                <span class="doc-missing">${docName} (File tidak tersedia)</span>
                            </div>
                        `;
                    }
                });
                dokumenElement.innerHTML = html;
            } else {
                dokumenElement.innerHTML = '<div class="document-item"><span class="doc-missing">Tidak ada dokumen</span></div>';
            }
        } catch (e) {
            console.error('Error parsing dokumen JSON:', e);
            // Jika dokumen bukan JSON, tampilkan sebagai link biasa
            dokumenElement.innerHTML = `
                <div class="document-item">
                    <span class="doc-type">Dokumen:</span>
                    <a href="${data.dokumen}" target="_blank" class="doc-link">Lihat Dokumen</a>
                </div>
            `;
        }
    } else {
        dokumenElement.innerHTML = '<div class="document-item"><span class="doc-missing">Tidak ada dokumen</span></div>';
    }
    
    // Alasan Penolakan
    const alasanContainer = document.getElementById('alasan-container');
    const alasanElement = document.getElementById('detail-alasan');
    const editNoticeContainer = document.getElementById('edit-notice-container');
    
    if (status.toLowerCase() === 'ditolak') {
        if (data.alasan && data.alasan.trim() !== '') {
            alasanElement.textContent = data.alasan;
            alasanContainer.style.display = 'block';
        }
        // Tampilkan tombol edit jika status ditolak
        editNoticeContainer.style.display = 'block';
        
        // Setup edit button
        const editBtn = document.getElementById('btnEditModal');
        if (editBtn) {
            editBtn.onclick = function() {
                window.location.href = 'edit_kk.php?id=' + data.id;
            };
        }
    } else {
        alasanContainer.style.display = 'none';
        editNoticeContainer.style.display = 'none';
    }
    
    // Show modal
    document.getElementById('detailModal').classList.add('show');
    document.body.style.overflow = 'hidden';
};

// Close Detail Modal
window.closeDetailModal = function() {
    document.getElementById('detailModal').classList.remove('show');
    document.body.style.overflow = '';
};

// Close modal when clicking outside
document.getElementById('detailModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeDetailModal();
    }
});

// Close modal with ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && document.getElementById('detailModal').classList.contains('show')) {
        closeDetailModal();
    }
});