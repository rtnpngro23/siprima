<?php
require_once '../error_handler.php';
include '../../app/config/koneksi.php';
// Gunakan user_kua_only.php untuk validasi khusus KUA
include '../../app/middleware/user_kua_only.php';

// Debug
error_log("=== kua_formulir.php START ===");
error_log("id_user: " . $_SESSION['id_user']);
error_log("id_kua: " . $_SESSION['id_kua']);
error_log("nama_kua: " . ($_SESSION['nama_kua'] ?? 'NOT SET'));
error_log("role: " . $_SESSION['role']);

// Ambil data KUA untuk operator
if ($_SESSION['role'] === 'operator') {
    $id_kua = $_SESSION['id_kua'];
    $sql_kua = "SELECT * FROM tb_kua WHERE id = ?";
    $stmt_kua = $koneksi->prepare($sql_kua);
    if ($stmt_kua) {
        $stmt_kua->bind_param("i", $id_kua);
        $stmt_kua->execute();
        $result_kua = $stmt_kua->get_result();
        $data_kua = $result_kua->fetch_assoc();
        $stmt_kua->close();
    }
}

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
    <title>Formulir Kependudukan - Sistem Pelayanan Kependudukan PASUTRI</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/kua_formulir.css">
    <link rel="icon" href="../assets/PASUTRI.png" type="image/png">
    
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar KUA - SAMA dengan kua_profil.php -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan PASUTRI</div>
        <nav>
            <ul>
                <li><a href="kua_dashboard_user.php">Dashboard</a></li>
                <li><a href="kua_ajukan_permohonan.php">Ajukan Permohonan</a></li>                
                <li><a href="kua_status_permohonan.php">Status Permohonan</a></li>                
                <li  class="active"><a href="kua_formulir.php">Formulir Kependudukan</a></li>
                <li><a href="kua_profil.php">Profil Saya</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Formulir KUA</div>
                <div class="subtitle">
                    <i class="fas fa-file-alt"></i> Download formulir untuk pengurusan administrasi kependudukan
                </div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama'] ?? 'Pengguna') ?></span>
                <span class="role-badge"><?= strtoupper($_SESSION['role']) ?></span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout-btn" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- KUA Info Box -->
            <div class="kua-info-box">
                <h3><i class="fas fa-mosque"></i> <?= esc($_SESSION['nama_kua'] ?? 'KUA') ?></h3>
                <p>Download formulir-formulir yang diperlukan untuk pengurusan administrasi kependudukan</p>
            </div>
           
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
                    <i class="fas fa-list"></i> Daftar Formulir Kependudukan KUA
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
                                
                                // Kategori formulir berdasarkan nama
                                $kategori = 'lainnya';
                                $nama_lower = strtolower($f['nama_formulir']);
                                if (strpos($nama_lower, 'nikah') !== false || strpos($nama_lower, 'pernikahan') !== false) {
                                    $kategori = 'pernikahan';
                                } elseif (strpos($nama_lower, 'keluarga') !== false || strpos($nama_lower, 'kk') !== false || strpos($nama_lower, 'akta') !== false) {
                                    $kategori = 'keluarga';
                                } elseif (strpos($nama_lower, 'surat') !== false || strpos($nama_lower, 'keterangan') !== false) {
                                    $kategori = 'administrasi';
                                }
                            ?>
                                <div class="file-item" 
                                     data-name="<?php echo esc(strtolower($f['nama_formulir'])); ?>"
                                     data-kategori="<?php echo $kategori; ?>"
                                     id="<?php echo $kategori; ?>">
                                    <div class="file-icon">
                                        <?php echo getFileIcon($filename); ?>
                                    </div>
                                    <div class="file-info">
                                        <div class="file-name">
                                            <?php echo esc($f['nama_formulir']); ?>
                                            <?php if ($kategori == 'pernikahan'): ?>
                                                <span style="color: #e74c3c; font-size: 12px; margin-left: 5px;">[Pernikahan]</span>
                                            <?php elseif ($kategori == 'keluarga'): ?>
                                                <span style="color: #3498db; font-size: 12px; margin-left: 5px;">[Keluarga]</span>
                                            <?php elseif ($kategori == 'administrasi'): ?>
                                                <span style="color: #2ecc71; font-size: 12px; margin-left: 5px;">[Administrasi]</span>
                                            <?php endif; ?>
                                        </div>
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
              
                <div class="info-card warning">
                    <div class="info-card-header">
                        <i class="fas fa-exclamation-triangle"></i> Informasi Kontak
                    </div>
                    <div class="info-card-body">
                        <p>Jika mengalami kesulitan:</p>
                        <ul>
                            <li><strong>Kontak Dukcapil:</strong> <?= esc($data_kua['no_telp'] ?? '081243425557') ?></li>
                            <li><strong>Email Dukcapil:</strong> <?= esc($data_kua['email'] ?? 'dkpskabgor@gmail.com') ?></li>
                            <li><strong>Lokasi Dukcapil:</strong> <?= esc($data_kua['alamat'] ?? 'Jl Kolonel Rauf Mo\'o') ?></li>
                            <li><strong>Jam Operasional:</strong> Senin-Jumat 08:00 - 16:00</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <p>&copy; <?php echo date('Y'); ?> Sistem Pelayanan Kependudukan KUA <?= esc($_SESSION['nama_kua'] ?? '') ?>. Semua formulir merupakan dokumen resmi pemerintah.</p>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3>Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
            <button id="cancelLogout" class="btn-no">Batal</button>
        </div>
    </div>
</div>

<script>
// Toggle Sidebar Menu on Mobile
document.addEventListener('DOMContentLoaded', function() {
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
    }
    
    // CSS untuk no-results message
    const styleNoResults = document.createElement('style');
    styleNoResults.textContent = `
        .no-results-message {
            grid-column: 1 / -1;
            padding: 40px 20px;
        }
    `;
    document.head.appendChild(styleNoResults);
    
    // Auto-focus search input
    setTimeout(() => {
        if (searchInput) {
            searchInput.focus();
        }
    }, 300);
    
    // Animation untuk file items
    document.addEventListener('DOMContentLoaded', function() {
        const fileItems = document.querySelectorAll('.file-item');
        fileItems.forEach((item, index) => {
            setTimeout(() => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(20px)';
                item.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                
                setTimeout(() => {
                    item.style.opacity = '1';
                    item.style.transform = 'translateY(0)';
                }, 50);
            }, index * 100);
        });
    });
});
</script>

</body>
</html>