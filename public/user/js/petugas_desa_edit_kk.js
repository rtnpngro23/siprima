// JavaScript untuk Halaman Edit Permohonan KK Petugas Desa

// Variabel global
let fileCounter = 1;
const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB

// Fungsi untuk format ukuran file
function formatFileSize(bytes) {
    if (bytes >= 1048576) {
        return (bytes / 1048576).toFixed(2) + ' MB';
    } else if (bytes >= 1024) {
        return (bytes / 1024).toFixed(2) + ' KB';
    } else {
        return bytes + ' Bytes';
    }
}

// ==================== SIDEBAR DAN LOGOUT ====================
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
    
    // Auto-remove toast notifications
    setTimeout(() => {
        const toast = document.querySelector('.toast');
        if (toast) {
            toast.remove();
        }
    }, 5000);
    
    // Initialize file input event listeners
    initializeFileInputListeners();
    
    // Initialize form validation
    initializeFormValidation();
});

// ==================== ANGGOTA KELUARGA FUNCTIONS ====================
function addAnggota() {
    const tableBody = document.getElementById('anggotaBody');
    if (!tableBody) return;
    
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td>
            <input type="text" name="anggota_nama[]" placeholder="Nama lengkap" required>
        </td>
        <td>
            <input type="text" name="anggota_nik[]" placeholder="NIK">
        </td>
        <td>
            <input type="text" name="anggota_hubungan[]" placeholder="Contoh: Anak, Istri">
        </td>
        <td>
            <button type="button" class="btn-remove-anggota" onclick="removeAnggota(this)">×</button>
        </td>
    `;
    tableBody.appendChild(newRow);
}

function removeAnggota(button) {
    const row = button.closest('tr');
    const tableBody = document.getElementById('anggotaBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr');
    
    // Pastikan minimal ada 1 baris
    if (rows.length > 1) {
        row.remove();
    } else {
        alert('Minimal harus ada satu anggota keluarga');
    }
}

// ==================== FILE UPLOAD FUNCTIONS ====================
function addFileInput() {
    const container = document.getElementById('fileUploadContainer');
    if (!container) return;
    
    fileCounter++;
    const newFileGroup = document.createElement('div');
    newFileGroup.className = 'file-input-group';
    newFileGroup.id = 'fileGroup' + fileCounter;
    newFileGroup.innerHTML = `
        <select name="doc_type[]" class="file-type-select">
            <option value="">Pilih Jenis Dokumen (Opsional)</option>
            <option value="ktp">KTP</option>
            <option value="kk_lama">KK Lama</option>
            <option value="formulir">Formulir</option>
            <option value="surat_nikah">Surat Nikah/Akta Cerai</option>
            <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div class="file-wrapper">
            <label class="file-label" for="fileInput${fileCounter}">Pilih File</label>
            <span id="fileName${fileCounter}" class="file-name">Belum ada file dipilih</span>
            <input type="file" id="fileInput${fileCounter}" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
        </div>
        <div class="file-size-warning" id="fileSize${fileCounter}">Maksimal 1MB per file</div>
        <button type="button" class="btn-remove" onclick="removeFileInput(${fileCounter})">Hapus</button>
    `;
    container.appendChild(newFileGroup);
    
    // Add event listener to new file input
    const newFileInput = document.getElementById('fileInput' + fileCounter);
    const newFileNameSpan = document.getElementById('fileName' + fileCounter);
    const newFileSizeSpan = document.getElementById('fileSize' + fileCounter);
    
    if (newFileInput && newFileNameSpan && newFileSizeSpan) {
        newFileInput.addEventListener('change', function(e) {
            handleFileChange(this, newFileNameSpan, newFileSizeSpan);
        });
    }
}

function removeFileInput(fileId) {
    const fileGroup = document.getElementById('fileGroup' + fileId);
    if (fileGroup) {
        // Don't remove the first file group
        if (fileId > 1) {
            fileGroup.remove();
        }
    }
}

function initializeFileInputListeners() {
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach((input, index) => {
        const fileNameSpan = document.getElementById('fileName' + (index + 1));
        const fileSizeSpan = document.getElementById('fileSize' + (index + 1));
        
        if (fileNameSpan && fileSizeSpan) {
            input.addEventListener('change', function(e) {
                handleFileChange(this, fileNameSpan, fileSizeSpan);
            });
        }
    });
}

function handleFileChange(fileInput, fileNameSpan, fileSizeSpan) {
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        fileNameSpan.textContent = file.name;
        
        // Validasi ukuran file client-side
        if (file.size > MAX_FILE_SIZE) {
            fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (melebihi 1MB)</span>`;
            fileInput.value = ''; // Clear file input
            fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
        } else {
            fileSizeSpan.innerHTML = `Ukuran: ${formatFileSize(file.size)}`;
        }
        
        // Validasi tipe file
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!allowedTypes.includes(file.type)) {
            fileSizeSpan.innerHTML = `<span style="color: red;">❌ Format file tidak didukung. Gunakan JPG, PNG, atau GIF</span>`;
            fileInput.value = '';
            fileNameSpan.textContent = 'Format tidak didukung, pilih file lain';
        }
    } else {
        fileNameSpan.textContent = 'Belum ada file dipilih';
        fileSizeSpan.innerHTML = 'Maksimal 1MB per file';
    }
}

// ==================== EXISTING DOCUMENTS FUNCTIONS ====================
function removeExistingDocument(button, index) {
    if (confirm('Apakah Anda yakin ingin menghapus dokumen ini dari permohonan?')) {
        const checkbox = button.closest('.existing-doc-item').querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.checked = false;
        }
        button.closest('.existing-doc-item').style.display = 'none';
    }
}

// ==================== PREVIEW FUNCTIONS ====================
function previewDocument(path) {
    const extension = path.split('.').pop().toLowerCase();
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    if (!modal || !content) return;
    
    if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
        content.innerHTML = `<img src="${path}" alt="Preview Dokumen" style="max-width: 100%; display: block; margin: 0 auto;">`;
    } else if (extension === 'pdf') {
        content.innerHTML = `
            <iframe src="${path}" style="width: 100%; height: 400px; border: none;"></iframe>
            <p style="text-align: center; margin-top: 10px;">
                <a href="${path}" target="_blank" style="color: #3b82f6;">Buka di tab baru</a>
            </p>
        `;
    } else {
        content.innerHTML = `<p>Format file tidak didukung untuk preview.</p>`;
    }
    
    modal.style.display = 'flex';
    
    // Close modal when clicking outside
    modal.onclick = function(event) {
        if (event.target === modal) {
            closePreview();
        }
    };
    
    // Close with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal.style.display === 'flex') {
            closePreview();
        }
    });
}

function closePreview() {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    if (modal) {
        modal.style.display = 'none';
    }
    if (content) {
        content.innerHTML = '';
    }
}

// ==================== FORM VALIDATION ====================
function initializeFormValidation() {
    const form = document.getElementById('kkForm');
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        let hasError = false;
        let errorMessages = [];
        
        // Validasi required fields
        const requiredFields = this.querySelectorAll('[required]');
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.style.borderColor = '#ef4444';
                hasError = true;
            } else {
                field.style.borderColor = '#d1d5db';
            }
        });
        
        // Validasi email format
        const emailField = this.querySelector('input[type="email"]');
        if (emailField && emailField.value.trim()) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailField.value.trim())) {
                emailField.style.borderColor = '#ef4444';
                hasError = true;
                errorMessages.push('Format email tidak valid');
            }
        }
        
        // Validasi anggota keluarga
        const anggotaRows = document.querySelectorAll('#anggotaBody tr');
        let hasAnggota = false;
        anggotaRows.forEach(row => {
            const namaInput = row.querySelector('input[name="anggota_nama[]"]');
            if (namaInput && namaInput.value.trim()) {
                hasAnggota = true;
            }
        });
        
        if (!hasAnggota) {
            errorMessages.push('Minimal harus ada satu anggota keluarga');
            hasError = true;
        }
        
        // Validasi ukuran file untuk file baru
        const fileInputs = this.querySelectorAll('input[type="file"]');
        fileInputs.forEach((input, index) => {
            if (input.files.length > 0) {
                const file = input.files[0];
                if (file.size > MAX_FILE_SIZE) {
                    errorMessages.push(`File "${file.name}" melebihi batas 1MB`);
                    hasError = true;
                }
            }
        });
        
        // Cek apakah ada KTP (baik dari dokumen lama atau baru)
        const existingDocs = document.querySelectorAll('.existing-doc-item');
        let hasKTP = false;
        
        // Cek KTP dari dokumen lama
        existingDocs.forEach(doc => {
            const checkbox = doc.querySelector('input[type="checkbox"]');
            const docTypeBadge = doc.querySelector('.doc-type-badge');
            if (checkbox && checkbox.checked && docTypeBadge && docTypeBadge.textContent === 'KTP') {
                hasKTP = true;
            }
        });
        
        // Cek KTP dari file baru
        const docTypeSelects = this.querySelectorAll('select[name="doc_type[]"]');
        docTypeSelects.forEach((select, index) => {
            const type = select.value;
            const fileInput = document.getElementById('fileInput' + (index + 1));
            
            if (type === 'ktp' && fileInput && fileInput.files.length > 0) {
                hasKTP = true;
            }
        });
        
        if (!hasKTP) {
            errorMessages.push('Dokumen KTP wajib ada (dari dokumen lama atau yang baru diupload)');
            hasError = true;
        }
        
        if (hasError) {
            e.preventDefault();
            let message = 'Perbaiki kesalahan berikut:\n';
            if (errorMessages.length > 0) {
                message += '• ' + errorMessages.join('\n• ');
            } else {
                message += '• Harap lengkapi semua field yang wajib diisi';
            }
            alert(message);
            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            // Konfirmasi sebelum submit
            const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan kembali permohonan?');
            if (!confirmSubmit) {
                e.preventDefault();
            }
        }
    });
}
