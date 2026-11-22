<?php
require_once '../error_handler.php';
include '../../app/middleware/user_rs_only.php';
include '../../app/config/koneksi.php';

// Debug log
error_log("=== rs_formulir.php START ===");
error_log("id_user: " . $_SESSION['id_user']);
error_log("id_rs: " . ($_SESSION['id_rs'] ?? 'N/A'));
error_log("nama_rs: " . ($_SESSION['nama_rs'] ?? 'N/A'));
error_log("role: " . $_SESSION['role']);

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
    <title>Formulir Kependudukan - <?= esc($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></title>
    <link rel="stylesheet" href="css/rs_formulir.css">
    <link rel="icon" href="../assets/st sehati.png" type="image/png">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar Khusus RS -->
    <aside class="sidebar">
        <div class="logo">ST SEHATI - <?= esc($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></div>
        <nav>
            <ul>
                <li><a href="rs_dashboard_user.php">Dashboard</a></li>
                <li><a href="rs_ajukan_permohonan.php">Ajukan Permohonan</a></li>
                <li><a href="rs_status_permohonan.php">Status Permohonan</a></li>
                <li><a href="rs_profil.php">Profil Saya</a></li>
                <li class="active"><a href="rs_formulir.php">Formulir Kependudukan</a></li>
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
                <div class="title">Formulir Kependudukan</div>
                <div class="subtitle"><?= esc($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama']) ?></span>
                <span class="role-badge"><?= strtoupper($_SESSION['role']) ?></span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Stats Card -->
            <div class="stats-card">
                <i class="fas fa-file-alt"></i>
                <h3><?= count($formulir) ?></h3>
                <p>Formulir Tersedia untuk <?= esc($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></p>
            </div>

            <!-- Search Box -->
            <div class="search-box">
                <div class="search-icon">
                    <i class="fas fa-search"></i>
                </div>
                <input type="text" id="searchInput" placeholder="Cari formulir berdasarkan nama...">
            </div>

            <!-- Main Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-file-medical"></i> Daftar Formulir Kependudukan
                </div>
                <div class="card-body">
                    <?php if (empty($formulir)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt fa-3x"></i>
                            <h4>Belum ada formulir tersedia</h4>
                            <p>Silakan hubungi administrator sistem untuk mengupload formulir yang diperlukan</p>
                        </div>
                    <?php else: ?>
                        <div id="formulirList">
                            <?php foreach ($formulir as $index => $f): 
                                $filename = basename($f['file_formulir']);
                                $filepath = "../" . $f['file_formulir'];
                                $filesize = file_exists($filepath) ? filesize($filepath) : 0;
                                $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                
                                // Tentukan badge class berdasarkan tipe file
                                $badgeClass = 'badge-other';
                                if ($filetype == 'pdf') $badgeClass = 'badge-pdf';
                                elseif (in_array($filetype, ['doc', 'docx'])) $badgeClass = 'badge-doc';
                                elseif (in_array($filetype, ['xls', 'xlsx'])) $badgeClass = 'badge-xls';
                                elseif (in_array($filetype, ['jpg', 'jpeg', 'png'])) $badgeClass = 'badge-img';
                            ?>
                                <div class="file-item" data-name="<?= esc(strtolower($f['nama_formulir'])) ?>">
                                    <div class="file-icon">
                                        <?= getFileIcon($filename) ?>
                                    </div>
                                    <div class="file-info">
                                        <div class="file-name"><?= esc($f['nama_formulir']) ?></div>
                                        <div class="file-meta">
                                            <span class="file-badge <?= $badgeClass ?>">
                                                <?= strtoupper($filetype) ?>
                                            </span>
                                            <?php if ($filesize > 0): ?>
                                                <span><i class="fas fa-weight-hanging"></i> <?= formatFileSize($filesize) ?></span>
                                            <?php endif; ?>
                                            <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($f['created_at'] ?? 'now')) ?></span>
                                        </div>
                                    </div>
                                    <div class="file-actions">
                                        <?php if (in_array($filetype, ['pdf', 'jpg', 'jpeg', 'png'])): ?>
                                            <a href="<?= esc($filepath) ?>" 
                                               target="_blank" 
                                               class="btn-preview">
                                                <i class="fas fa-eye"></i> Preview
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= esc($filepath) ?>" 
                                           class="btn-download" 
                                           download="<?= esc($filename) ?>">
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
                <div class="info-card info">
                    <div class="info-card-header">
                        <i class="fas fa-info-circle"></i> Informasi Penting
                    </div>
                    <div class="info-card-body">
                        <ul>
                            <li><strong>Formulir F-2.01:</strong> Untuk pelaporan kelahiran dari rumah sakit</li>
                            <li><strong>Surat Keterangan Lahir:</strong> Dokumen wajib dari RS untuk permohonan akta kelahiran</li>
                            <li><strong>Formulir Kematian:</strong> Untuk pelaporan kematian di rumah sakit</li>
                            <li><strong>Surat Keterangan Kematian:</strong> Dokumen wajib dari RS untuk permohonan akta kematian</li>
                            <li><strong>Format PDF/DOC:</strong> Gunakan format yang sesuai untuk pengisian</li>
                        </ul>
                    </div>
                </div>
                
                <div class="info-card tips">
                    <div class="info-card-header">
                        <i class="fas fa-lightbulb"></i> Tips Penggunaan
                    </div>
                    <div class="info-card-body">
                        <ul>
                            <li>Download formulir sebelum mengajukan permohonan</li>
                            <li>Isi formulir dengan data yang lengkap dan benar</li>
                            <li>Gunakan tanda tangan dan cap rumah sakit yang valid</li>
                            <li>Simpan salinan formulir yang telah diisi</li>
                            <li>Upload formulir yang telah diisi saat mengajukan permohonan</li>
                        </ul>
                    </div>
                </div>
                
                <div class="info-card help">
                    <div class="info-card-header">
                        <i class="fas fa-question-circle"></i> Bantuan & Kontak
                    </div>
                    <div class="info-card-body">
                        <p>Jika mengalami kesulitan atau formulir tidak tersedia:</p>
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
                <p><i class="fas fa-hospital"></i> &copy; <?= date('Y') ?> ST SEHATI - Sistem Terpadu Sinergi Kelahiran dan Kematian Terintegrasi.</p>
                <p class="text-sm mt-2">Semua formulir merupakan dokumen resmi pemerintah untuk pelayanan kependudukan.</p>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3><i class="fas fa-sign-out-alt"></i> Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout dari sistem?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
            <button id="cancelLogout" class="btn-no">Batal</button>
        </div>
    </div>
</div>

<script>
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
        window.location.href = '../logout_pasutri_ahlak.php';
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
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
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
        const existingMessage = formulirList?.querySelector('.no-results-message');
        
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
}

// Modal Styles
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
        color: #2c5282;
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

    .btn-yes {
        background: #2c5282;
        color: white;
        border: none;
        padding: 10px 25px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .btn-yes:hover {
        background: #1a365d;
    }

    .btn-no {
        background: #6b7280;
        color: white;
        border: none;
        padding: 10px 25px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .btn-no:hover {
        background: #4b5563;
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

// Add CSS for no-results message
const style = document.createElement('style');
style.textContent = `
    .no-results-message {
        grid-column: 1 / -1;
        padding: 40px 20px;
    }
`;
document.head.appendChild(style);

// Download tracking (opsional)
document.querySelectorAll('.btn-download').forEach(btn => {
    btn.addEventListener('click', function(e) {
        const fileName = this.getAttribute('download');
        console.log(`Downloading: ${fileName}`);
        // Di sini bisa ditambahkan tracking ke database jika diperlukan
    });
});
</script>

</body>
</html>