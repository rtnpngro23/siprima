// Formulir Modal Functions
const tambahModal = document.getElementById('tambahModal');
const editModal = document.getElementById('editModal');
const confirmModal = document.getElementById('confirmModal');

function openTambahModal() {
    tambahModal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeTambahModal() {
    tambahModal.classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('formTambah').reset();
}

function openEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_nama_formulir').value = data.nama_formulir;

    // Tampilkan file saat ini
    const currentFileDiv = document.getElementById('currentFile');
    const filename = data.file_formulir.split('/').pop();
    currentFileDiv.innerHTML = `
        <strong>File saat ini:</strong>
        <span>${filename}</span>
        <br>
        <a href="../${data.file_formulir}" target="_blank" style="color: #3498db; font-size: 13px;">
            <i class="fas fa-eye"></i> Lihat File
        </a>
    `;

    editModal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    editModal.classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('formEdit').reset();
    document.getElementById('currentFile').innerHTML = '';
}

// Konfirmasi Hapus
let idToDelete = null;

function confirmHapus(id, nama) {
    idToDelete = id;
    document.getElementById('confirmNama').textContent = nama;
    confirmModal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeConfirmModal() {
    confirmModal.classList.remove('show');
    document.body.style.overflow = '';
    idToDelete = null;
}

// Handle konfirmasi hapus
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (idToDelete) {
        window.location.href = `formulir.php?hapus=${idToDelete}`;
    }
});

// Validasi form tambah
function validateTambahForm() {
    const nama = document.getElementById('nama_formulir').value.trim();
    const file = document.getElementById('file_formulir');

    if (!nama) {
        alert('Nama formulir harus diisi');
        return false;
    }

    if (file.files.length === 0) {
        alert('File formulir harus diupload');
        return false;
    }

    return validateFile(file);
}

// Validasi form edit
function validateEditForm() {
    const nama = document.getElementById('edit_nama_formulir').value.trim();
    const file = document.getElementById('edit_file_formulir');

    if (!nama) {
        alert('Nama formulir harus diisi');
        return false;
    }

    if (file.files.length > 0) {
        return validateFile(file);
    }

    return true;
}

// Close all modals with ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeTambahModal();
        closeEditModal();
        closeConfirmModal();
        hideLogoutModal();
    }
});

// Close modals when clicking outside
[tambahModal, editModal, confirmModal, logoutModal].forEach(modal => {
    modal?.addEventListener('click', function(event) {
        if (event.target === this) {
            if (this.id === 'tambahModal') closeTambahModal();
            if (this.id === 'editModal') closeEditModal();
            if (this.id === 'confirmModal') closeConfirmModal();
            if (this.id === 'logoutModal') hideLogoutModal();
        }
    });
});
