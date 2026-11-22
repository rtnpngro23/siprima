<?php
require_once '../error_handler.php';
// formulir.php
error_log('=== FORMULIR.PHP STARTING ===');
error_log('Session status before: ' . session_status());

// Mulai session HANYA jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    error_log('Session started in formulir.php');
} else {
    error_log('Session already active');
}

error_log('Session status after: ' . session_status());
error_log('Request: ' . siprima_request_id());
error_log('Session user_id: ' . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log('Session nama: ' . ($_SESSION['nama'] ?? 'NOT SET'));
error_log('Session role: ' . ($_SESSION['role'] ?? 'NOT SET'));

// Cek apakah admin_only.php sudah include session_start

include __DIR__ . '/../../app/middleware/admin_only.php';

// Debug setelah include admin_only.php
error_log('After admin_only.php - Session status: ' . session_status());
error_log('Session user_id: ' . ($_SESSION['user_id'] ?? 'NOT SET'));

// Include koneksi setelah session_start
include __DIR__ . '/../../app/config/koneksi.php';

// Fungsi untuk upload file
function uploadFormulir($file) {
    $targetDir = "../uploads/formulir/";
    
    // Buat folder jika belum ada
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    $fileName = basename($file["name"]);
    $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $uniqueName = time() . '_' . uniqid() . '.' . $fileType;
    $targetFilePath = $targetDir . $uniqueName;
    
    // Cek ukuran file (max 5MB)
    if ($file["size"] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'Ukuran file terlalu besar. Maksimal 5MB'];
    }
    
    // Hanya izinkan format tertentu
    $allowedTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
    if (!in_array($fileType, $allowedTypes)) {
        return ['success' => false, 'message' => 'Format file tidak diizinkan. Hanya PDF, DOC, XLS, JPG, PNG'];
    }
    
    // Upload file
    if (move_uploaded_file($file["tmp_name"], $targetFilePath)) {
        return ['success' => true, 'file_path' => 'uploads/formulir/' . $uniqueName, 'original_name' => $fileName];
    } else {
        return ['success' => false, 'message' => 'Gagal mengupload file'];
    }
}

// Debug POST data
error_log('Form upload request: ' . siprima_request_id());

// Handle tambah formulir
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah'])) {
    error_log('Processing tambah formulir');
    
    $nama_formulir = $koneksi->real_escape_string($_POST['nama_formulir']);
    
    if (empty($nama_formulir)) {
        $_SESSION['error'] = "Nama formulir harus diisi";
    } elseif (empty($_FILES['file_formulir']['name'])) {
        $_SESSION['error'] = "File formulir harus diupload";
    } else {
        $upload = uploadFormulir($_FILES['file_formulir']);
        
        if ($upload['success']) {
            $stmt = $koneksi->prepare("INSERT INTO tb_formulir (nama_formulir, file_formulir) VALUES (?, ?)");
            $stmt->bind_param("ss", $nama_formulir, $upload['file_path']);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Formulir berhasil ditambahkan";
            } else {
                $_SESSION['error'] = "Gagal menambahkan formulir: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = $upload['message'];
        }
    }
    
    // Redirect untuk menghindari resubmit
    header("Location: formulir.php");
    exit();
}

// Handle edit formulir
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit'])) {
    error_log('Processing edit formulir');
    
    $id = (int)$_POST['id'];
    $nama_formulir = $koneksi->real_escape_string($_POST['nama_formulir']);
    
    if (empty($nama_formulir)) {
        $_SESSION['error'] = "Nama formulir harus diisi";
    } else {
        if (!empty($_FILES['file_formulir']['name'])) {
            $upload = uploadFormulir($_FILES['file_formulir']);
            
            if ($upload['success']) {
                // Hapus file lama
                $stmt = $koneksi->prepare("SELECT file_formulir FROM tb_formulir WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    if (file_exists("../" . $row['file_formulir'])) {
                        unlink("../" . $row['file_formulir']);
                    }
                }
                $stmt->close();
                
                // Update dengan file baru
                $stmt = $koneksi->prepare("UPDATE tb_formulir SET nama_formulir = ?, file_formulir = ? WHERE id = ?");
                $stmt->bind_param("ssi", $nama_formulir, $upload['file_path'], $id);
            } else {
                $_SESSION['error'] = $upload['message'];
                header("Location: formulir.php");
                exit();
            }
        } else {
            // Update tanpa mengganti file
            $stmt = $koneksi->prepare("UPDATE tb_formulir SET nama_formulir = ? WHERE id = ?");
            $stmt->bind_param("si", $nama_formulir, $id);
        }
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Formulir berhasil diperbarui";
        } else {
            $_SESSION['error'] = "Gagal memperbarui formulir: " . $stmt->error;
        }
        $stmt->close();
    }
    
    // Redirect untuk menghindari resubmit
    header("Location: formulir.php");
    exit();
}

// Handle hapus formulir
if (isset($_GET['hapus'])) {
    error_log('Processing hapus formulir: ' . $_GET['hapus']);
    
    $id = (int)$_GET['hapus'];
    
    // Ambil data file untuk dihapus
    $stmt = $koneksi->prepare("SELECT file_formulir FROM tb_formulir WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Hapus file dari server
        if (file_exists("../" . $row['file_formulir'])) {
            unlink("../" . $row['file_formulir']);
        }
        
        // Hapus dari database
        $stmt2 = $koneksi->prepare("DELETE FROM tb_formulir WHERE id = ?");
        $stmt2->bind_param("i", $id);
        
        if ($stmt2->execute()) {
            $_SESSION['success'] = "Formulir berhasil dihapus";
        } else {
            $_SESSION['error'] = "Gagal menghapus formulir: " . $stmt2->error;
        }
        $stmt2->close();
    }
    $stmt->close();
    
    // Redirect untuk menghindari resubmit
    header("Location: formulir.php");
    exit();
}

// Ambil semua formulir
$query = "SELECT * FROM tb_formulir ORDER BY nama_formulir ASC";
$result = $koneksi->query($query);
$formulir = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $formulir[] = $row;
    }
}

function esc($s){ 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf': return '📄';
        case 'doc': 
        case 'docx': return '📝';
        case 'xls':
        case 'xlsx': return '📊';
        case 'jpg':
        case 'jpeg':
        case 'png': return '🖼️';
        default: return '📎';
    }
}

// Debug akhir
error_log('=== FORMULIR.PHP END ===');
error_log('Total formulir: ' . count($formulir));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Formulir - Sistem Pengaduan</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/formulir.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan</div>
        <nav>
            <ul>
                <li><a href="dashboard_admin.php">Dashboard</a></li>
                <li><a href="manajemen_operator.php">Manajemen Pengguna</a></li>
                <li><a href="reset_password.php">Reset Password User</a></li>
                <li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li>
                <li><a href="laporan_admin.php">Laporan & Analisis</a></li><li><a href="kinerja_operator.php">Kinerja Operator</a></li>
                <li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li><a href="tanggapan_pemohon.php">Hasil Survei</a></li>
                <li><a href="whistleblowing.php">Whistleblowing</a></li>
                <li class="active"><a href="formulir.php">Formulir Kependudukan</a></li>
                <li><a href="manajemen_formulir_digital.php">Manajemen Formulir Digital</a></li><li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
                <li class="logout-item">
                    <button id="logoutBtn" class="sidebar-btn">Logout</button>
                </li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Kelola Formulir</div>
                <div style="color: #7f8c8d; font-size: 14px;">
                    <i class="fas fa-file-alt"></i> Manajemen formulir dan dokumen persyaratan
                </div>
            </div>
            <div class="user-info">
                <span>Hai, <?= esc($_SESSION['nama'] ?? 'Admin') ?></span>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="notification success" id="notification">
                    <span><?php echo esc($_SESSION['success']); ?></span>
                    <button class="close-btn" onclick="closeNotification()">×</button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="notification error" id="notification">
                    <span><?php echo esc($_SESSION['error']); ?></span>
                    <button class="close-btn" onclick="closeNotification()">×</button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Control Section -->
            <div class="control-section">
                <h2 style="margin: 0; color: #2c3e50;">
                    <i class="fas fa-file-alt me-2"></i> Daftar Formulir
                </h2>
                <button class="btn-add" onclick="openTambahModal()">
                    <i class="fas fa-plus"></i> Tambah Formulir
                </button>
            </div>

            <!-- Table Section -->
            <div class="table-section">
                <div class="table-header">
                    <h2><i class="fas fa-list"></i> Daftar Formulir</h2>
                    <div style="color: #6c757d; font-size: 14px;">
                        Total: <?php echo count($formulir); ?> formulir
                    </div>
                </div>
                
                <?php if (empty($formulir)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt fa-3x" style="margin-bottom: 20px; color: #dee2e6;"></i>
                        <h4 style="color: #6c757d;">Belum ada formulir</h4>
                        <p style="color: #6c757d;">Klik tombol "Tambah Formulir" untuk menambahkan formulir baru.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th width="50">#</th>
                                    <th>Nama Formulir</th>
                                    <th>File</th>
                                    <th width="300">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($formulir as $f): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><strong><?php echo esc($f['nama_formulir']); ?></strong></td>
                                        <td>
                                            <div class="file-item">
                                                <div class="file-icon">
                                                    <?php echo getFileIcon($f['file_formulir']); ?>
                                                </div>
                                                <div class="file-info">
                                                    <div class="file-name">
                                                        <?php 
                                                        $filename = basename($f['file_formulir']);
                                                        echo esc($filename); 
                                                        ?>
                                                    </div>
                                                    <?php if (file_exists("../" . $f['file_formulir'])): 
                                                        $size = filesize("../" . $f['file_formulir']);
                                                        $size_kb = round($size / 1024, 2);
                                                    ?>
                                                        <div class="file-size"><?php echo $size_kb; ?> KB</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="../<?php echo esc($f['file_formulir']); ?>" 
                                                   target="_blank" 
                                                   class="btn-download" 
                                                   download>
                                                   <i class="fas fa-download"></i> Download
                                                </a>
                                                <button class="btn-edit" onclick="openEditModal(
                                                    <?php echo htmlspecialchars(json_encode($f), ENT_QUOTES, 'UTF-8'); ?>
                                                )">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn-delete" 
                                                        onclick="confirmHapus(<?php echo $f['id']; ?>, '<?php echo esc(addslashes($f['nama_formulir'])); ?>')">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3><i class="fas fa-sign-out-alt"></i> Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-save">Ya, Logout</button>
            <button id="cancelLogout" class="btn-cancel">Batal</button>
        </div>
    </div>
</div>

<!-- Modal Tambah Formulir -->
<div class="modal-overlay" id="tambahModal">
    <div class="modal-box">
        <h3><i class="fas fa-plus"></i> Tambah Formulir Baru</h3>
        <form action="formulir.php" method="POST" enctype="multipart/form-data" id="formTambah" onsubmit="return validateTambahForm()">
<?php echo csrf_input(); ?>
            <div class="form-group">
                <label class="form-label" for="nama_formulir">Nama Formulir <span>*</span></label>
                <input type="text" 
                       class="form-control" 
                       id="nama_formulir" 
                       name="nama_formulir" 
                       required
                       placeholder="Contoh: Formulir KK, Surat Keterangan, dll">
            </div>
            
            <div class="form-group">
                <label class="form-label" for="file_formulir">File Formulir <span>*</span></label>
                <input type="file" 
                       class="form-control" 
                       id="file_formulir" 
                       name="file_formulir" 
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                       required
                       onchange="validateFile(this)">
                <small class="form-text">
                    Format: PDF, DOC, XLS, JPG, PNG (Maks. 5MB)
                </small>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeTambahModal()">Batal</button>
                <button type="submit" class="btn-success" name="tambah">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Formulir -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3><i class="fas fa-edit"></i> Edit Formulir</h3>
        <form action="formulir.php" method="POST" enctype="multipart/form-data" id="formEdit" onsubmit="return validateEditForm()">
<?php echo csrf_input(); ?>
            <input type="hidden" id="edit_id" name="id">
            
            <div class="form-group">
                <label class="form-label" for="edit_nama_formulir">Nama Formulir <span>*</span></label>
                <input type="text" 
                       class="form-control" 
                       id="edit_nama_formulir" 
                       name="nama_formulir" 
                       required>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="edit_file_formulir">File Formulir</label>
                <input type="file" 
                       class="form-control" 
                       id="edit_file_formulir" 
                       name="file_formulir" 
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                       onchange="validateFile(this)">
                <small class="form-text">
                    Kosongkan jika tidak ingin mengganti file. Format: PDF, DOC, XLS, JPG, PNG (Maks. 5MB)
                </small>
                <div id="currentFile" class="current-file-info"></div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeEditModal()">Batal</button>
                <button type="submit" class="btn-save" name="edit">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Konfirmasi Hapus -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box" style="max-width: 400px;">
        <h3><i class="fas fa-trash"></i> Konfirmasi Hapus</h3>
        <p>Apakah Anda yakin ingin menghapus formulir <strong id="confirmNama"></strong>?</p>
        <p style="color: #e74c3c; font-size: 14px;">
            <i class="fas fa-exclamation-triangle"></i> File akan dihapus permanen dari server!
        </p>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeConfirmModal()">Batal</button>
            <button type="button" class="btn-danger" id="confirmDeleteBtn">Ya, Hapus</button>
        </div>
    </div>
</div>

<script>
// JavaScript functions
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }
    
    // Logout modal
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');
    
    function showLogoutModal() {
        if (logoutModal) logoutModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function hideLogoutModal() {
        if (logoutModal) logoutModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    
    if (confirmLogout) {
        confirmLogout.addEventListener('click', function() {
            window.location.href = '../logout.php';
        });
    }
    
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    
    // Close modal when clicking outside
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) hideLogoutModal();
        });
    }
    
    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
});

// Close notification
function closeNotification() {
    const notification = document.getElementById('notification');
    if (notification) {
        notification.remove();
    }
}

// Auto-hide notification after 5 seconds
setTimeout(function() {
    closeNotification();
}, 5000);

// Formulir modal functions
function openTambahModal() {
    document.getElementById('tambahModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeTambahModal() {
    document.getElementById('tambahModal').classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('formTambah').reset();
}

function openEditModal(formulirData) {
    document.getElementById('edit_id').value = formulirData.id;
    document.getElementById('edit_nama_formulir').value = formulirData.nama_formulir;
    
    // Show current file info
    const currentFileDiv = document.getElementById('currentFile');
    const fileName = formulirData.file_formulir.split('/').pop();
    currentFileDiv.innerHTML = `
        <strong>File saat ini:</strong><br>
        ${fileName}<br>
        <small>File yang diupload akan menggantikan file ini</small>
    `;
    
    document.getElementById('editModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('formEdit').reset();
    document.getElementById('currentFile').innerHTML = '';
}

function validateFile(input) {
    const file = input.files[0];
    if (!file) return true;
    
    const allowedTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
    const fileType = file.name.split('.').pop().toLowerCase();
    const maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!allowedTypes.includes(fileType)) {
        alert('Format file tidak diizinkan. Hanya PDF, DOC, XLS, JPG, PNG yang diperbolehkan.');
        input.value = '';
        return false;
    }
    
    if (file.size > maxSize) {
        alert('Ukuran file terlalu besar. Maksimal 5MB.');
        input.value = '';
        return false;
    }
    
    return true;
}

function validateTambahForm() {
    const nama = document.getElementById('nama_formulir').value.trim();
    const file = document.getElementById('file_formulir').files[0];
    
    if (!nama) {
        alert('Nama formulir harus diisi');
        return false;
    }
    
    if (!file) {
        alert('File formulir harus diupload');
        return false;
    }
    
    return validateFile(document.getElementById('file_formulir'));
}

function validateEditForm() {
    const nama = document.getElementById('edit_nama_formulir').value.trim();
    
    if (!nama) {
        alert('Nama formulir harus diisi');
        return false;
    }
    
    const fileInput = document.getElementById('edit_file_formulir');
    if (fileInput.files.length > 0) {
        return validateFile(fileInput);
    }
    
    return true;
}

// Hapus konfirmasi
let hapusId = null;
let hapusNama = null;

function confirmHapus(id, nama) {
    hapusId = id;
    hapusNama = nama;
    
    document.getElementById('confirmNama').textContent = nama;
    document.getElementById('confirmModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('show');
    document.body.style.overflow = '';
    hapusId = null;
    hapusNama = null;
}

document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (hapusId) {
        window.location.href = 'formulir.php?hapus=' + hapusId;
    }
});

// Close modals on outside click
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(event) {
        if (event.target === this) {
            if (this.id === 'tambahModal') {
                closeTambahModal();
            } else if (this.id === 'editModal') {
                closeEditModal();
            } else if (this.id === 'confirmModal') {
                closeConfirmModal();
            }
        }
    });
});

// Close modals with ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeTambahModal();
        closeEditModal();
        closeConfirmModal();
    }
});
</script>

<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
</body>
</html>
