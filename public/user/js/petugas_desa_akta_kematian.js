// JavaScript untuk Halaman Ajukan Akta Kematian Petugas Desa

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

  // KECAMATAN DAN DESA SUDAH DIKUNCI - TIDAK PERLU AJAX
  // Set nilai awal untuk nama kecamatan/desa saat halaman dimuat
  const namaKecamatan = document.getElementById('namaKecamatan');
  const namaDesa = document.getElementById('namaDesa');
  
  if (namaKecamatan) {
    namaKecamatan.value = namaKecamatan.getAttribute('value') || '';
  }
  
  if (namaDesa) {
    namaDesa.value = namaDesa.getAttribute('value') || '';
  }
});

// Global variables for file upload
let fileCounter = 1;
const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB

function formatFileSize(bytes) {
  if (bytes >= 1048576) {
    return (bytes / 1048576).toFixed(2) + ' MB';
  } else if (bytes >= 1024) {
    return (bytes / 1024).toFixed(2) + ' KB';
  } else {
    return bytes + ' Bytes';
  }
}

function initializeFileInputs() {
  const fileInputs = document.querySelectorAll('input[type="file"]');
  fileInputs.forEach((input, index) => {
    const fileNameSpan = document.getElementById('fileName' + (index + 1));
    const fileSizeSpan = document.getElementById('fileSize' + (index + 1));
    
    if (fileNameSpan && fileSizeSpan) {
      input.addEventListener('change', function(e) {
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
  });
}

function addFileInput() {
  fileCounter++;
  const container = document.getElementById('fileUploadContainer');
  const newFileGroup = document.createElement('div');
  newFileGroup.className = 'file-input-group';
  newFileGroup.id = 'fileGroup' + fileCounter;
  newFileGroup.innerHTML = `
    <select name="doc_type[]" class="file-type-select" required>
      <option value="">Pilih Jenis Dokumen</option>
      <option value="surat_kematian">Surat Keterangan Kematian</option>
      <option value="formulir">Formulir F-2.01 Kematian</option>
      <option value="kk">Kartu Keluarga (KK)</option>
      <option value="ktp_meninggal">KTP Yang Meninggal</option>
      <option value="lainnya">Dokumen Lainnya</option>
    </select>
    <div class="file-wrapper">
      <label class="file-label" for="fileInput${fileCounter}">Pilih File</label>
      <span id="fileName${fileCounter}" class="file-name">Belum ada file dipilih</span>
      <input type="file" id="fileInput${fileCounter}" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
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
      if (this.files.length > 0) {
        const file = this.files[0];
        newFileNameSpan.textContent = file.name;
        
        if (file.size > MAX_FILE_SIZE) {
          newFileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (melebihi 1MB)</span>`;
          this.value = '';
          newFileNameSpan.textContent = 'File terlalu besar, pilih file lain';
        } else {
          newFileSizeSpan.innerHTML = `Ukuran: ${formatFileSize(file.size)}`;
        }
      } else {
        newFileNameSpan.textContent = 'Belum ada file dipilih';
        newFileSizeSpan.innerHTML = 'Maksimal 1MB per file';
      }
    });
  }
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

// Validasi form sebelum submit
const aktaKematianForm = document.getElementById('aktaKematianForm');
if (aktaKematianForm) {
  aktaKematianForm.addEventListener('submit', function(e) {
    const fileInputs = document.querySelectorAll('input[type="file"]');
    let hasError = false;
    let errorMessages = [];
    
    // Validasi ukuran file
    fileInputs.forEach((input, index) => {
      if (input.files.length > 0) {
        const file = input.files[0];
        if (file.size > MAX_FILE_SIZE) {
          errorMessages.push(`File "${file.name}" melebihi batas 1MB`);
          hasError = true;
        }
      }
    });
    
    // Cek dokumen wajib
    const docTypeSelects = document.querySelectorAll('select[name="doc_type[]"]');
    let hasSuratKematian = false;
    let hasFormulir = false;
    
    docTypeSelects.forEach((select, index) => {
      const type = select.value;
      const fileInput = document.getElementById('fileInput' + (index + 1));
      
      if (type === 'surat_kematian' && fileInput && fileInput.files.length > 0) {
        hasSuratKematian = true;
      }
      if (type === 'formulir' && fileInput && fileInput.files.length > 0) {
        hasFormulir = true;
      }
    });
    
    if (!hasSuratKematian) {
      errorMessages.push('Surat Keterangan Kematian wajib diupload');
      hasError = true;
    }
    
    if (!hasFormulir) {
      errorMessages.push('Formulir F-2.01 Kematian wajib diupload');
      hasError = true;
    }
    
    // Validasi NIK (jika diisi)
    const nikMeninggal = document.querySelector('input[name="nik_meninggal"]');
    const nikAyah = document.querySelector('input[name="nik_ayah"]');
    const nikIbu = document.querySelector('input[name="nik_ibu"]');
    
    if (nikMeninggal && nikMeninggal.value && nikMeninggal.value.length !== 16) {
      errorMessages.push('NIK Yang Meninggal harus 16 digit (kosongkan jika tidak ada)');
      hasError = true;
    }
    
    if (nikAyah && nikAyah.value && nikAyah.value.length !== 16) {
      errorMessages.push('NIK Ayah harus 16 digit (kosongkan jika tidak ada)');
      hasError = true;
    }
    
    if (nikIbu && nikIbu.value && nikIbu.value.length !== 16) {
      errorMessages.push('NIK Ibu harus 16 digit (kosongkan jika tidak ada)');
      hasError = true;
    }
    
    // Validasi tanggal kematian tidak boleh di masa depan
    const tanggalKematian = document.querySelector('input[name="tanggal_kematian"]');
    if (tanggalKematian && tanggalKematian.value) {
      const today = new Date().toISOString().split('T')[0];
      if (tanggalKematian.value > today) {
        errorMessages.push('Tanggal kematian tidak boleh di masa depan');
        hasError = true;
      }
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
      // Gunakan data dari window.userLocationData yang sudah di-set di file PHP
      let locationInfo = 'Kecamatan / Desa';
      if (window.userLocationData && window.userLocationData.fullLocation) {
        locationInfo = window.userLocationData.fullLocation;
      } else {
        // Fallback: coba ambil dari input hidden atau field readonly
        const namaKecamatan = document.getElementById('namaKecamatan')?.value || '';
        const namaDesa = document.getElementById('namaDesa')?.value || '';
        if (namaKecamatan && namaDesa) {
          locationInfo = namaKecamatan + ' / ' + namaDesa;
        }
      }
      
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan akta kematian untuk ' + 
                                   locationInfo + '?');
      if (!confirmSubmit) {
        e.preventDefault();
      }
    }
  });
}

// Utility function untuk menghapus toast notifications
if (typeof window._userUtils === 'undefined') {
  window._userUtils = {
    removeToasts: function(delay) {
      setTimeout(() => {
        const toasts = document.querySelectorAll('.toast');
        toasts.forEach(toast => toast.remove());
      }, delay || 5000);
    }
  };
}

// Initialize file inputs on page load
document.addEventListener('DOMContentLoaded', function() {
  initializeFileInputs();
});