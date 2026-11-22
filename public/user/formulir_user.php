<?php
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
include '../../app/config/koneksi.php';

// Ambil semua formulir dari database
$query = "SELECT * FROM tb_formulir ORDER BY nama_formulir ASC";
$result = $koneksi->query($query);
$formulir = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $formulir[] = $row;
    }
}

// Fungsi helper untuk escape output
function esc($s){ 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

// Fungsi untuk mendapatkan icon berdasarkan tipe file
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

// Fungsi untuk mendapatkan warna badge berdasarkan tipe file
function getFileBadge($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf': return 'badge-danger';
        case 'doc': 
        case 'docx': return 'badge-primary';
        case 'xls':
        case 'xlsx': return 'badge-success';
        case 'jpg':
        case 'jpeg':
        case 'png': return 'badge-info';
        default: return 'badge-secondary';
    }
}

// Fungsi untuk format ukuran file
function formatFileSize($bytes) {
    if ($bytes == 0) return "0 Bytes";
    $k = 1024;
    $sizes = ["Bytes", "KB", "MB", "GB"];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), 2) . " " . $sizes[$i];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulir Kependudukan - Sistem Pelayanan</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/formulir_user.css">
    <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
    <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar SAMA dengan formulir.php (admin) -->
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <main class="main">
        <header class="header user-general-header">
            <?php
            require_once __DIR__ . '/user_header_brand.php';
            render_user_header_brand(
                'Formulir Kependudukan',
                'Download formulir untuk pengurusan administrasi'
            );
            ?>
            <div class="user-info">
                <span>Hai, <?= esc($_SESSION['nama'] ?? 'Pengguna') ?></span>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Stats Card -->
            <div class="stats-card">
                <i class="fas fa-file-pdf"></i>
                <h3><?php echo count($formulir); ?></h3>
                <p>Formulir Tersedia</p>
            </div>

            <!-- Search Box -->
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Cari formulir berdasarkan nama...">
            </div>

            <!-- Main Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list"></i> Daftar Formulir Kependudukan
                </div>
                <div class="card-body">
                    <?php if (empty($formulir)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt fa-3x"></i>
                            <h4>Belum ada formulir tersedia</h4>
                            <p>Silakan hubungi administrator untuk mengupload formulir yang diperlukan</p>
                        </div>
                    <?php else: ?>
                        <div id="formulirList">
                            <?php foreach ($formulir as $index => $f): 
                                $filename = basename($f['file_formulir']);
                                $filepath = "../" . $f['file_formulir'];
                                $filesize = file_exists($filepath) ? filesize($filepath) : 0;
                                $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                
                                // Tentukan badge class berdasarkan tipe file
                                $badgeClass = 'badge-secondary';
                                if ($filetype == 'pdf') $badgeClass = 'badge-pdf';
                                elseif (in_array($filetype, ['doc', 'docx'])) $badgeClass = 'badge-doc';
                                elseif (in_array($filetype, ['xls', 'xlsx'])) $badgeClass = 'badge-xls';
                                elseif (in_array($filetype, ['jpg', 'jpeg', 'png'])) $badgeClass = 'badge-img';
                            ?>
                                <div class="file-item" data-name="<?php echo esc(strtolower($f['nama_formulir'])); ?>">
                                    <div class="file-icon">
                                        <?php echo getFileIcon($filename); ?>
                                    </div>
                                    <div class="file-info">
                                        <div class="file-name"><?php echo esc($f['nama_formulir']); ?></div>
                                        <div class="file-meta">
                                            <span class="file-badge <?php echo $badgeClass; ?>">
                                                <?php echo strtoupper($filetype); ?>
                                            </span>
                                            <?php if ($filesize > 0): ?>
                                                <span><i class="fas fa-weight-hanging me-1"></i><?php echo formatFileSize($filesize); ?></span>
                                            <?php endif; ?>
                                            <span><i class="fas fa-calendar me-1"></i><?php echo date('d/m/Y', strtotime($f['created_at'] ?? 'now')); ?></span>
                                        </div>
                                    </div>
                                    <div class="file-actions">
                                        <?php if (in_array($filetype, ['pdf', 'jpg', 'jpeg', 'png'])): ?>
                                            <a href="<?php echo esc($filepath); ?>" 
                                               target="_blank" 
                                               class="btn-preview">
                                                <i class="fas fa-eye"></i> Preview
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?php echo esc($filepath); ?>" 
                                           class="btn-download" 
                                           download="<?php echo esc($filename); ?>">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Information Cards -->
            <div class="info-cards">
                                
                <div class="info-card help">
                    <div class="info-card-header">
                        <i class="fas fa-question-circle"></i> Bantuan
                    </div>
                    <div class="info-card-body">
                        <p>Jika mengalami kesulitan:</p>
                        <ul>
                            <li><strong>Kontak:</strong> 081243425557</li>
                            <li><strong>Email:</strong> dkpskabgor@gmail.com</li>
                            <li><strong>Lokasi:</strong> Jl Kolonel Rauf Mo'o</li>
                            <li><strong>Jam Operasional:</strong> 08:00 - 16:00</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <p>&copy; <?php echo date('Y'); ?> Sistem Pelayanan Kependudukan. Semua formulir merupakan dokumen resmi pemerintah.</p>
            </div>
        </section>
    </main>
</div>

<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>

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

<script>
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
logoutModal?.addEventListener('click', function(event) {
    if (event.target === logoutModal) {
        hideLogoutModal();
    }
});

// Search functionality
document.getElementById('searchInput').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const items = document.querySelectorAll('.file-item');
    let visibleCount = 0;
    
    items.forEach(item => {
        const fileName = item.getAttribute('data-name');
        if (fileName.includes(searchTerm)) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Tampilkan pesan jika tidak ada hasil
    const formulirList = document.getElementById('formulirList');
    const existingMessage = formulirList.querySelector('.no-results-message');
    
    if (searchTerm && visibleCount === 0) {
        if (!existingMessage) {
            const message = document.createElement('div');
            message.className = 'empty-state no-results-message';
            message.innerHTML = `
                <i class="fas fa-search fa-3x"></i>
                <h4>Tidak ditemukan formulir</h4>
                <p>Tidak ada formulir yang sesuai dengan pencarian "${searchTerm}"</p>
            `;
            formulirList.appendChild(message);
        }
    } else if (existingMessage) {
        existingMessage.remove();
    }
});

// Add CSS for no-results message
const style = document.createElement('style');
style.textContent = `
    .no-results-message {
        grid-column: 1 / -1;
        padding: 40px 20px;
    }
`;
document.head.appendChild(style);

// Modal Styles (dari formulir.php)
const styleModal = document.createElement('style');
styleModal.textContent = `
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        justify-content: center;
        align-items: center;
        z-index: 9999;
    }

    .modal-overlay.show {
        display: flex;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .modal-box {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 400px;
        padding: 30px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        animation: slideUp 0.3s ease;
    }

    @keyframes slideUp {
        from { transform: translateY(20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-box h3 {
        margin-top: 0;
        margin-bottom: 15px;
        color: #2c3e50;
        font-size: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .modal-box p {
        margin-bottom: 25px;
        color: #555;
        line-height: 1.5;
    }

    .modal-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
    }

    .btn-save {
        background: #3498db;
        color: white;
        border: none;
        padding: 10px 25px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .btn-save:hover {
        background: #2980b9;
    }

    .btn-cancel {
        background: #95a5a6;
        color: white;
        border: none;
        padding: 10px 25px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .btn-cancel:hover {
        background: #7f8c8d;
    }
`;
document.head.appendChild(styleModal);

// Close all modals with ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        hideLogoutModal();
    }
});

// Auto-focus search input
setTimeout(() => {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.focus();
    }
}, 300);
</script>

<?php include __DIR__ . '/ai_chat_widget.php'; ?>
</body>
</html>
