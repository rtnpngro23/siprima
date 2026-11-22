(function () {
  const page = window._aktaKelahiranPage || {};
  let logoutModalOpen = false;
  let logoutModal; // Deklarasi di sini

  function init() {
    console.log('Akta Kelahiran Page Initializing...');
    
    // Pastikan modal tertutup saat inisialisasi
    logoutModal = document.getElementById('logoutModal'); // Assign di sini
    if (logoutModal) {
      logoutModal.classList.remove('show');
      logoutModal.style.display = 'none';
      document.body.style.overflow = '';
    }

    // Toggle Sidebar Menu on Mobile
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');

    if (menuToggle && sidebar) {
      menuToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        sidebar.classList.toggle('open');
      });

      // Close sidebar when clicking outside on mobile
      document.addEventListener('click', function (event) {
        if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
          if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
            sidebar.classList.remove('open');
          }
        }
      });
    }

    // Logout Modal - FIXED VERSION
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');

    function showLogoutModal() {
      console.log('Showing logout modal');
      if (logoutModal) {
        logoutModal.classList.add('show');
        logoutModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        logoutModalOpen = true;
      }
    }

    function hideLogoutModal() {
      console.log('Hiding logout modal');
      if (logoutModal) {
        logoutModal.classList.remove('show');
        logoutModal.style.display = 'none';
        document.body.style.overflow = '';
        logoutModalOpen = false;
      }
    }

    // Event Listeners dengan error handling
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        showLogoutModal();
      });
    }

    if (logoutBtn2) {
      logoutBtn2.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        showLogoutModal();
      });
    }

    if (confirmLogout) {
      confirmLogout.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        window.location.href = '../logout.php';
      });
    }

    if (cancelLogout) {
      cancelLogout.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        hideLogoutModal();
      });
    }

    // Modal click outside to close
    if (logoutModal) {
      logoutModal.addEventListener('click', function (event) {
        if (event.target === logoutModal) {
          hideLogoutModal();
        }
      });

      // Tutup modal jika terbuka saat init
      hideLogoutModal();
    }

    // Escape key to close modal
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && logoutModalOpen) {
        hideLogoutModal();
      }
    });

    // AJAX untuk Desa & Auto-fill Nama
    const kecSelect = document.getElementById("kecamatanSelect");
    if (kecSelect) {
      kecSelect.addEventListener('change', function () {
        const idKecamatan = this.value;
        const desaSelect = document.getElementById("desaSelect");
        const namaKecamatanInput = document.getElementById("namaKecamatan");
        const namaDesaInput = document.getElementById("namaDesa");
        
        desaSelect.innerHTML = "<option value=''>-- Pilih Desa --</option>";
        namaDesaInput.value = "";

        if (idKecamatan !== "") {
          namaKecamatanInput.value = this.options[this.selectedIndex].text;

          // AJAX request
          fetch("get_desa.php?id_kecamatan=" + encodeURIComponent(idKecamatan))
            .then(response => {
              if (!response.ok) {
                throw new Error('Network response was not ok');
              }
              return response.text();
            })
            .then(data => { 
              desaSelect.innerHTML += data; 
            })
            .catch(error => {
              console.error('Error fetching desa:', error);
              desaSelect.innerHTML += '<option value="">Error loading desa</option>';
            });
        } else {
          namaKecamatanInput.value = "";
        }
      });
    }

    const desaSelect = document.getElementById("desaSelect");
    if (desaSelect) {
      desaSelect.addEventListener('change', function () {
        const namaDesaInput = document.getElementById("namaDesa");
        if (this.value !== "") {
          namaDesaInput.value = this.options[this.selectedIndex].text;
        } else {
          namaDesaInput.value = "";
        }
      });
    }

    // Set initial values dari server
    if (page.preKec) {
      const namaKecamatanInput = document.getElementById("namaKecamatan");
      if (namaKecamatanInput) namaKecamatanInput.value = page.preKec;
    }
    
    if (page.preDes) {
      const namaDesaInput = document.getElementById("namaDesa");
      if (namaDesaInput) namaDesaInput.value = page.preDes;
    }

    // Multiple file upload functionality
    let fileCounter = 1;
    const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB

    function formatFileSize(bytes) {
      if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
      else if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
      else return bytes + ' Bytes';
    }

    function addFileInput() {
      fileCounter++;
      const container = document.getElementById('fileUploadContainer');
      if (!container) return;

      const newFileGroup = document.createElement('div');
      newFileGroup.className = 'file-input-group';
      newFileGroup.id = 'fileGroup' + fileCounter;
      newFileGroup.innerHTML = `
        <select name="doc_type[]" class="file-type-select" required>
          <option value="">Pilih Jenis Dokumen</option>
          <option value="akta_kelahiran">Surat Keterangan Lahir</option>
          <option value="formulir">Formulir F-2.01 Kelahiran</option>
          <option value="kk">Kartu Keluarga (KK)</option>
          <option value="ktp_ortu">KTP Orang Tua</option>
          <option value="buku_nikah">Buku Nikah</option>
          <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div class="file-wrapper">
          <label class="file-label" for="fileInput${fileCounter}">Pilih File</label>
          <span id="fileName${fileCounter}" class="file-name">Belum ada file dipilih</span>
          <input type="file" id="fileInput${fileCounter}" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
        </div>
        <div class="file-size-warning" id="fileSize${fileCounter}">Maksimal 1MB per file</div>
        <button type="button" class="btn-remove" onclick="window.removeFileInput(${fileCounter})">Hapus</button>
      `;
      container.appendChild(newFileGroup);

      // Setup event listener untuk file input baru
      setupFileInputListeners(fileCounter);
    }

    function removeFileInput(fileId) {
      const fileGroup = document.getElementById('fileGroup' + fileId);
      if (fileGroup) {
        if (fileId > 1) {
          fileGroup.remove();
        } else {
          alert('Dokumen pertama tidak dapat dihapus. Minimal harus ada satu dokumen.');
        }
      }
    }

    function setupFileInputListeners(index) {
      const fileInput = document.getElementById('fileInput' + index);
      const fileNameSpan = document.getElementById('fileName' + index);
      const fileSizeSpan = document.getElementById('fileSize' + index);

      if (fileInput && fileNameSpan && fileSizeSpan) {
        fileInput.addEventListener('change', function (e) {
          if (this.files.length > 0) {
            const file = this.files[0];
            fileNameSpan.textContent = file.name;
            
            if (file.size > MAX_FILE_SIZE) {
              fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (melebihi 1MB)</span>`;
              this.value = '';
              fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
            } else {
              fileSizeSpan.innerHTML = `Ukuran: ${formatFileSize(file.size)}`;
            }
          } else {
            fileNameSpan.textContent = 'Belum ada file dipilih';
            fileSizeSpan.innerHTML = 'Maksimal 1MB per file';
          }
        });
      }
    }

    // Setup file input listeners untuk yang sudah ada
    for (let i = 1; i <= fileCounter; i++) {
      setupFileInputListeners(i);
    }

    // Expose functions to global scope
    window.addFileInput = addFileInput;
    window.removeFileInput = removeFileInput;

    // Form validation
    const form = document.getElementById('aktaForm');
    if (form) {
      form.addEventListener('submit', function (e) {
        // Validasi file
        const fileInputs = document.querySelectorAll('input[type="file"]');
        let hasError = false;
        let errorMessages = [];
        
        fileInputs.forEach((input, index) => {
          if (input.files.length > 0 && input.files[0].size > MAX_FILE_SIZE) {
            errorMessages.push(`File "${input.files[0].name}" melebihi batas 1MB`);
            hasError = true;
          }
        });

        // Validasi dokumen wajib
        const docTypeSelects = document.querySelectorAll('select[name="doc_type[]"]');
        let hasAktaLahir = false;
        let hasFormulir = false;

        docTypeSelects.forEach((select, index) => {
          const type = select.value;
          const fileInput = document.getElementById('fileInput' + (index + 1));
          if (type === 'akta_kelahiran' && fileInput && fileInput.files.length > 0) hasAktaLahir = true;
          if (type === 'formulir' && fileInput && fileInput.files.length > 0) hasFormulir = true;
        });

        if (!hasAktaLahir) { 
          errorMessages.push('Surat Keterangan Lahir wajib diupload'); 
          hasError = true; 
        }
        if (!hasFormulir) { 
          errorMessages.push('Formulir F-2.01 Kelahiran wajib diupload'); 
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
          return false;
        } else {
          const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan akta kelahiran?');
          if (!confirmSubmit) {
            e.preventDefault();
            return false;
          }
        }
      });
    }
  }

  // Inisialisasi dengan DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();