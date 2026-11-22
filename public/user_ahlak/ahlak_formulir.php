<?php
require_once '../error_handler.php';
require_once '../../app/middleware/user_ahlak_only.php';
require_once '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/document_token.php';

// Ambil semua formulir dari database
$query = "SELECT id, nama_formulir, file_formulir FROM tb_formulir ORDER BY nama_formulir ASC";
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
    <title>Formulir Kependudukan - AHLAK Puskesmas</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/ahlak.png">
    <link rel="stylesheet" href="css/ahlak_dashboard_user.css">
    <link rel="stylesheet" href="css/ahlak_formulir.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar Khusus AHLAK -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan AHLAK</div>
        <nav>
            <ul>
                <li><a href="ahlak_dashboard_user.php">Dashboard</a></li>
                <li><a href="ahlak_ajukan_permohonan.php">Ajukan Permohonan</a></li>
                <li><a href="ahlak_status_permohonan.php">Status Permohonan</a></li>                
                <li  class="active"><a href="ahlak_formulir.php">Formulir Kependudukan</a></li>
                <li><a href="ahlak_profil.php">Profil Saya</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Formulir Kependudukan - Puskesmas</div>
                <div style="color: #7f8c8d; font-size: 14px;">
                    <i class="fas fa-file-medical-alt"></i> Download formulir Kependudukan untuk pelayanan AHLAK
                </div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama'] ?? 'Pengguna') ?></span>
                <span class="role-badge">USER AHLAK</span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Puskesmas Info Banner -->
            <div class="puskesmas-banner">
                <h3>🏥 <?= esc($_SESSION['nama_puskes'] ?? '') ?></h3>
                <p><strong>Kecamatan:</strong> <?= esc($_SESSION['nama_kecamatan'] ?? '') ?></p>
                <p><strong>Layanan:</strong> Formulir Kependudukan untuk pelayanan AHLAK</p>
            </div>

            <!-- Stats Card -->
            <div class="stats-card">
                <i class="fas fa-file-medical"></i>
                <h3><?php echo count($formulir); ?></h3>
                <p>Formulir Kependudukan Tersedia</p>
            </div>

            <!-- Search Box -->
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Cari formulir kependudukan...">
            </div>

            <!-- Main Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-file-medical-alt"></i> Daftar Formulir Kependudukan
                </div>
                <div class="card-body">
                    <?php if (empty($formulir)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-medical fa-3x"></i>
                            <h4>Belum ada formulir Kependudukan</h4>
                            <p>Silakan hubungi administrator untuk mengupload formulir yang diperlukan</p>
                        </div>
                    <?php else: ?>
                        <div id="formulirList">
                            <?php foreach ($formulir as $index => $f): 
                                $storedPath = str_replace('\\', '/', ltrim((string)$f['file_formulir'], '/'));
                                $filename = basename($storedPath);
                                $validPath = preg_match('#^uploads/formulir/[A-Za-z0-9._-]+$#', $storedPath) === 1;
                                $secureUrl = $validPath ? siprima_document_token_url(dirname(__DIR__), $storedPath) : null;
                                $physicalPath = $validPath ? dirname(__DIR__) . '/' . $storedPath : '';
                                $filesize = $physicalPath !== '' && is_file($physicalPath) ? filesize($physicalPath) : 0;
                                $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                
                                // Tentukan badge class berdasarkan tipe file
                                $badgeClass = 'badge-secondary';
                                if ($filetype == 'pdf') $badgeClass = 'badge-pdf';
                                elseif (in_array($filetype, ['doc', 'docx'])) $badgeClass = 'badge-doc';
                                elseif (in_array($filetype, ['xls', 'xlsx'])) $badgeClass = 'badge-xls';
                                elseif (in_array($filetype, ['jpg', 'jpeg', 'png'])) $badgeClass = 'badge-img';
                                
                                // Tambahkan badge khusus untuk formulir kesehatan
                                $namaLower = strtolower($f['nama_formulir']);
                                if (strpos($namaLower, 'kesehatan') !== false || 
                                    strpos($namaLower, 'medis') !== false ||
                                    strpos($namaLower, 'kelahiran') !== false) {
                                    $badgeClass = 'badge-health';
                                } elseif (strpos($namaLower, 'form') !== false || 
                                          strpos($namaLower, 'formulir') !== false) {
                                    $badgeClass = 'badge-form';
                                }
                            ?>
                                <div class="file-item" data-name="<?php echo esc(strtolower($f['nama_formulir'])); ?>">
                                    <div class="file-icon">
                                        <?php echo getFileIcon($filename); ?>
                                    </div>
                                    <div class="file-info">
                                        <div class="file-name"><?php echo esc($f['nama_formulir']); ?></div>
                                        <div class="file-meta">
                                            <span class="file-badge <?php echo $badgeClass; ?>">
                                                <?php 
                                                    if ($badgeClass == 'badge-health') echo 'KESEHATAN';
                                                    elseif ($badgeClass == 'badge-form') echo 'FORMULIR';
                                                    else echo strtoupper($filetype);
                                                ?>
                                            </span>
                                            <?php if ($filesize > 0): ?>
                                            <span><i class="fas fa-weight-hanging"></i> <?php echo formatFileSize($filesize); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="file-actions">
                                        <?php if ($secureUrl && in_array($filetype, ['pdf', 'jpg', 'jpeg', 'png'])): ?>
                                            <a href="<?php echo esc($secureUrl); ?>" 
                                               target="_blank" 
                                               rel="noopener noreferrer"
                                               class="btn-preview">
                                                <i class="fas fa-eye"></i> Preview
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($secureUrl): ?>
                                        <a href="<?php echo esc($secureUrl . '&download=1'); ?>" 
                                           class="btn-download" 
                                           download="<?php echo esc($filename); ?>">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                        <?php else: ?><span class="file-unavailable">File tidak tersedia</span><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
              <!-- Information Cards -->               
                <div class="info-card help">
                    <div class="info-card-header">
                        <i class="fas fa-question-circle"></i> Bantuan Puskesmas
                    </div>
                    <div class="info-card-body">
                        <p>Untuk bantuan teknis:</p>
                        <ul>
                            <li><strong>Kontak:</strong> <?= esc($_SESSION['kontak_puskes'] ?? '081243425557') ?></li>
                            <li><strong>Email:</strong> <?= esc($_SESSION['email_puskes'] ?? 'dkpskabgor@gmail.com') ?></li>
                            <li><strong>Lokasi:</strong> <?= esc($_SESSION['alamat_puskes'] ?? 'Jl Kolonel Rauf Mo\'o') ?></li>
                            <li><strong>Jam Pelayanan:</strong> Setiap hari 24 jam</li>
                        </ul>
                    </div>
                </div>
            <!-- Footer -->
            <div class="footer">
                <p>&copy; <?php echo date('Y'); ?> Sistem AHLAK Puskesmas. Dinas Dukcapil Kabupaten Gorontalo.</p>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3><i class="fas fa-sign-out-alt"></i> Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout dari sistem AHLAK?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-save">Ya, Logout</button>
            <button id="cancelLogout" class="btn-cancel">Batal</button>
        </div>
    </div>
</div>

<script>
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
    
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const formulirList = document.getElementById('formulirList');
    
    if (searchInput && formulirList) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const fileItems = formulirList.querySelectorAll('.file-item');
            
            fileItems.forEach(item => {
                const fileName = item.getAttribute('data-name') || '';
                if (fileName.includes(searchTerm) || searchTerm === '') {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
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
            // Logout khusus AHLAK
            window.location.href = '../logout_pasutri_ahlak.php';
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
        if (event.key === 'Escape') {
            hideLogoutModal();
        }
    });
    
    // Auto-focus search input
    setTimeout(() => {
        if (searchInput) {
            searchInput.focus();
        }
    }, 300);
});
</script>

</body>
</html>
