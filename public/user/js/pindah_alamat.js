// JavaScript untuk form pindah alamat
document.addEventListener('DOMContentLoaded', function() {
    // Toggle Sidebar Menu on Mobile
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
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
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout.php');
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) hideLogoutModal();
        });
    }
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
  
    // AJAX untuk Desa Asal & Auto-fill Nama
    const kecamatanSelect = document.getElementById("kecamatanSelectAsal");
    if (kecamatanSelect) {
        kecamatanSelect.addEventListener("change", function() {
            const idKecamatan = this.value;
            const desaSelect = document.getElementById("desaSelectAsal");
            const namaKecamatanInput = document.getElementById("namaKecamatanAsal");
            const namaDesaInput = document.getElementById("namaDesaAsal");
            
            desaSelect.innerHTML = "<option value=''>-- Pilih Desa --</option>";
            namaDesaInput.value = "";
    
            if (idKecamatan !== "") {
                namaKecamatanInput.value = this.options[this.selectedIndex].text;
    
                fetch("get_desa.php?id_kecamatan=" + idKecamatan)
                    .then(response => response.text())
                    .then(data => {
                        desaSelect.innerHTML += data;
                    })
                    .catch(error => console.error('Error:', error));
            } else {
                namaKecamatanInput.value = "";
            }
        });
    }
  
    const desaSelect = document.getElementById("desaSelectAsal");
    if (desaSelect) {
        desaSelect.addEventListener("change", function() {
            const namaDesaInput = document.getElementById("namaDesaAsal");
            if (this.value !== "") {
                namaDesaInput.value = this.options[this.selectedIndex].text;
            } else {
                namaDesaInput.value = "";
            }
        });
    }
  
    // Set initial values dari server jika ada
    const preKec = document.getElementById("namaKecamatanAsal")?.dataset.preKec || "";
    const preDes = document.getElementById("namaDesaAsal")?.dataset.preDes || "";
    if (preKec) document.getElementById("namaKecamatanAsal").value = preKec;
    if (preDes) document.getElementById("namaDesaAsal").value = preDes;
  
    // Multiple file upload functionality
    window.fileCounter = 1;
    window.MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB
  
    window.formatFileSize = function(bytes) {
        if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(2) + ' MB';
        } else if (bytes >= 1024) {
            return (bytes / 1024).toFixed(2) + ' KB';
        } else {
            return bytes + ' Bytes';
        }
    };
  
    // Initialize file input event listeners
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach((input, index) => {
        const fileNameSpan = document.getElementById('fileName' + (index + 1));
        const fileSizeSpan = document.getElementById('fileSize' + (index + 1));
        
        if (fileNameSpan && fileSizeSpan) {
            input.addEventListener('change', function(e) {
                if (this.files.length > 0) {
                    const file = this.files[0];
                    fileNameSpan.textContent = file.name;
                    
                    if (file.size > window.MAX_FILE_SIZE) {
                        fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${window.formatFileSize(file.size)} (melebihi 1MB)</span>`;
                        this.value = '';
                        fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
                    } else {
                        fileSizeSpan.innerHTML = `Ukuran: ${window.formatFileSize(file.size)}`;
                    }
                } else {
                    fileNameSpan.textContent = 'Belum ada file dipilih';
                    fileSizeSpan.innerHTML = 'Maksimal 1MB per file';
                }
            });
        }
    });
  
    // Validasi form sebelum submit
    const form = document.getElementById('pindahForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const fileInputs = document.querySelectorAll('input[type="file"]');
            const anggotaRows = document.querySelectorAll('#anggotaBody tr');
            let hasError = false;
            let errorMessages = [];
            
            // Validasi No KK
            const noKKInput = document.querySelector('input[name="no_kk"]');
            if (noKKInput && noKKInput.value.length !== 16) {
                errorMessages.push('Nomor Kartu Keluarga harus 16 digit');
                hasError = true;
            }
            
            // Validasi NIK anggota
            const nikInputs = document.querySelectorAll('input[name="anggota_nik[]"]');
            nikInputs.forEach((input, index) => {
                if (input.value && input.value.length !== 16) {
                    errorMessages.push(`NIK anggota baris ${index + 1} harus 16 digit (kosongkan jika tidak ada)`);
                    hasError = true;
                }
            });
            
            // Validasi ukuran file
            fileInputs.forEach((input, index) => {
                if (input.files.length > 0) {
                    const file = input.files[0];
                    if (file.size > window.MAX_FILE_SIZE) {
                        errorMessages.push(`File "${file.name}" melebihi batas 1MB`);
                        hasError = true;
                    }
                }
            });
            
            // Cek dokumen wajib
            const docTypeSelects = document.querySelectorAll('select[name="doc_type[]"]');
            let hasFormulir = false;
            let hasFoto = false;
            let hasKKKTP = false;
            
            docTypeSelects.forEach((select, index) => {
                const type = select.value;
                const fileInput = document.getElementById('fileInput' + (index + 1));
                
                if (type === 'formulir_pindah' && fileInput && fileInput.files.length > 0) hasFormulir = true;
                if (type === 'foto_pemohon' && fileInput && fileInput.files.length > 0) hasFoto = true;
                if (type === 'kk_ktp' && fileInput && fileInput.files.length > 0) hasKKKTP = true;
            });
            
            if (!hasFormulir) {
                errorMessages.push('Formulir F-1.03 Pindah wajib diupload');
                hasError = true;
            }
            
            if (!hasFoto) {
                errorMessages.push('Foto pemohon memegang KK/KTP/Form pindah wajib diupload');
                hasError = true;
            }
            
            if (!hasKKKTP) {
                errorMessages.push('KK/KTP/Surat keterangan hilang wajib diupload');
                hasError = true;
            }
            
            // Validasi anggota keluarga
            let hasAnggota = false;
            anggotaRows.forEach(row => {
                const namaInput = row.querySelector('input[name="anggota_nama[]"]');
                if (namaInput && namaInput.value.trim()) {
                    hasAnggota = true;
                }
            });
            
            if (!hasAnggota) {
                errorMessages.push('Minimal harus ada satu anggota keluarga yang pindah');
                hasError = true;
            }
            
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
            
            if (hasError) {
                e.preventDefault();
                let message = 'Perbaiki kesalahan berikut:\n';
                if (errorMessages.length > 0) {
                    message += '• ' + errorMessages.join('\n• ');
                } else {
                    message += '• Harap lengkapi semua field yang wajib diisi';
                }
                alert(message);
            } else {
                const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan pindah alamat?');
                if (!confirmSubmit) {
                    e.preventDefault();
                }
            }
        });
    }
});

// Fungsi Anggota Keluarga
window.addAnggota = function() {
    const tableBody = document.getElementById('anggotaBody');
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
      <td>
        <input type="text" name="anggota_nama[]" placeholder="Nama lengkap" required>
      </td>
      <td>
        <input type="text" name="anggota_nik[]" placeholder="16 digit NIK" 
               pattern="[0-9]{16}" maxlength="16"
               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
      </td>
      <td>
        <input type="text" name="anggota_hubungan[]" placeholder="Contoh: Anak, Istri">
      </td>
      <td>
        <button type="button" class="btn-remove-anggota" onclick="removeAnggota(this)">×</button>
      </td>
    `;
    tableBody.appendChild(newRow);
};

window.removeAnggota = function(button) {
    const row = button.closest('tr');
    const tableBody = document.getElementById('anggotaBody');
    const rows = tableBody.querySelectorAll('tr');
    
    if (rows.length > 1) {
        row.remove();
    } else {
        alert('Minimal harus ada satu anggota keluarga');
    }
};

// Fungsi Multiple File Upload
window.addFileInput = function() {
    window.fileCounter++;
    const container = document.getElementById('fileUploadContainer');
    const newFileGroup = document.createElement('div');
    newFileGroup.className = 'file-input-group';
    newFileGroup.id = 'fileGroup' + window.fileCounter;
    newFileGroup.innerHTML = `
      <select name="doc_type[]" class="file-type-select" required>
        <option value="">Pilih Jenis Dokumen</option>
        <option value="formulir_pindah">Formulir F-1.03 Pindah</option>
        <option value="foto_pemohon">Foto pemohon memegang KK/KTP/Form pindah</option>
        <option value="kk_ktp">KK/KTP/Surat keterangan hilang KK atau KTP</option>
        <option value="buku_nikah">Buku nikah/akta nikah</option>
        <option value="lainnya">Dokumen Lainnya</option>
      </select>
      <div class="file-wrapper">
        <label class="file-label" for="fileInput${window.fileCounter}">Pilih File</label>
        <span id="fileName${window.fileCounter}" class="file-name">Belum ada file dipilih</span>
        <input type="file" id="fileInput${window.fileCounter}" name="dokumen[]" accept=".jpg,.jpeg,.png" required class="file-input">
      </div>
      <div class="file-size-warning" id="fileSize${window.fileCounter}">Maksimal 1MB per file</div>
      <button type="button" class="btn-remove" onclick="removeFileInput(${window.fileCounter})">Hapus</button>
    `;
    container.appendChild(newFileGroup);
    
    const newFileInput = document.getElementById('fileInput' + window.fileCounter);
    const newFileNameSpan = document.getElementById('fileName' + window.fileCounter);
    const newFileSizeSpan = document.getElementById('fileSize' + window.fileCounter);
    
    if (newFileInput && newFileNameSpan && newFileSizeSpan) {
        newFileInput.addEventListener('change', function(e) {
            if (this.files.length > 0) {
                const file = this.files[0];
                newFileNameSpan.textContent = file.name;
                
                if (file.size > window.MAX_FILE_SIZE) {
                    newFileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${window.formatFileSize(file.size)} (melebihi 1MB)</span>`;
                    this.value = '';
                    newFileNameSpan.textContent = 'File terlalu besar, pilih file lain';
                } else {
                    newFileSizeSpan.innerHTML = `Ukuran: ${window.formatFileSize(file.size)}`;
                }
            } else {
                newFileNameSpan.textContent = 'Belum ada file dipilih';
                newFileSizeSpan.innerHTML = 'Maksimal 1MB per file';
            }
        });
    }
};

window.removeFileInput = function(fileId) {
    const fileGroup = document.getElementById('fileGroup' + fileId);
    if (fileGroup) {
        if (fileId > 1) {
            fileGroup.remove();
        } else {
            alert('Dokumen pertama tidak dapat dihapus. Minimal harus ada satu dokumen.');
        }
    }
};