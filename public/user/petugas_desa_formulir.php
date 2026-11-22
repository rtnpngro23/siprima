<?php
require_once '../error_handler.php';
include '../../app/middleware/petugas_desa_only.php';
include '../../app/config/koneksi.php';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

// Ambil data user
$user_id = (int)$_SESSION['id_user'];
$nama_user = $_SESSION['nama'] ?? 'Petugas Desa';

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
    <title>Formulir Kependudukan - Petugas Desa</title>
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/petugas_desa_formulir.css">
    <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="petugas-desa-ui">

<div class="layout">
    <!-- Sidebar khusus petugas desa -->
    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header petugas-desa-header">
            <?php
            require_once __DIR__ . '/petugas_desa_header_brand.php';
            render_petugas_desa_header_brand(
                'Formulir Kependudukan',
                'Petugas Desa • Akses formulir administrasi kependudukan'
            );
            ?>
            <div class="user-info">
                <span class="user-role">Petugas Desa</span>
                <span>Hai, <?= esc($nama_user) ?></span>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Stats Card -->
            <div class="stats-card">
                <div class="stats-icon">
                    <i class="bi bi-file-earmark-pdf"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo count($formulir); ?></h3>
                    <p>Formulir Tersedia</p>
                </div>
            </div>

            <!-- Search Box -->
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="searchInput" placeholder="Cari formulir berdasarkan nama...">
            </div>

            <!-- Main Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-content">
                        <i class="bi bi-files"></i>
                        <h3>Daftar Formulir Kependudukan</h3>
                    </div>
                    <div class="card-header-actions">
                        <span class="formulir-count">Total: <?php echo count($formulir); ?> formulir</span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($formulir)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="bi bi-file-earmark-text"></i>
                            </div>
                            <h4>Belum ada formulir tersedia</h4>
                            <p>Silakan hubungi administrator untuk mengupload formulir yang diperlukan</p>
                        </div>
                    <?php else: ?>
                        <div id="formulirList" class="formulir-grid">
                            <?php foreach ($formulir as $index => $f): 
                                $filename = basename($f['file_formulir']);
                                $filepath = "../" . $f['file_formulir'];
                                $filesize = file_exists($filepath) ? filesize($filepath) : 0;
                                $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                
                                // Tentukan warna berdasarkan tipe file
                                $fileColor = '';
                                if ($filetype == 'pdf') $fileColor = 'file-pdf';
                                elseif (in_array($filetype, ['doc', 'docx'])) $fileColor = 'file-doc';
                                elseif (in_array($filetype, ['xls', 'xlsx'])) $fileColor = 'file-xls';
                                elseif (in_array($filetype, ['jpg', 'jpeg', 'png'])) $fileColor = 'file-img';
                                else $fileColor = 'file-other';
                            ?>
                                <div class="formulir-card <?php echo $fileColor; ?>" 
                                     data-name="<?php echo esc(strtolower($f['nama_formulir'])); ?>"
                                     data-type="<?php echo $filetype; ?>">
                                    <div class="formulir-icon">
                                        <?php echo getFileIcon($filename); ?>
                                    </div>
                                    <div class="formulir-info">
                                        <h4 class="formulir-title"><?php echo esc($f['nama_formulir']); ?></h4>
                                        <div class="formulir-meta">
                                            <span class="file-type"><?php echo strtoupper($filetype); ?></span>
                                            <?php if ($filesize > 0): ?>
                                                <span class="file-size"><?php echo formatFileSize($filesize); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="formulir-actions">
                                            <?php if (in_array($filetype, ['pdf', 'jpg', 'jpeg', 'png'])): ?>
                                                <a href="<?php echo esc($filepath); ?>" 
                                                   target="_blank" 
                                                   class="btn-preview">
                                                    <i class="bi bi-eye"></i> Preview
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo esc($filepath); ?>" 
                                               class="btn-download" 
                                               download="<?php echo esc($filename); ?>">
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                        </div>
                                    </div>
                                    <div class="formulir-footer">
                                        <small class="upload-date">
                                            <i class="bi bi-calendar"></i> 
                                            <?php echo date('d/m/Y', strtotime($f['created_at'] ?? 'now')); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Information Section -->
            <div class="information-section">
                <h3><i class="bi bi-info-circle"></i> Informasi Penting</h3>
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-icon">
                            <i class="bi bi-telephone"></i>
                        </div>
                        <div class="info-content">
                            <h4>Kontak Bantuan</h4>
                            <p><strong>Telepon:</strong> 081243425557</p>
                            <p><strong>Email:</strong> dkpskabgor@gmail.com</p>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="info-content">
                            <h4>Jam Operasional</h4>
                            <p><strong>Senin - Jumat:</strong> 08:00 - 16:00 WITA</p>
                            <p><strong>Sabtu:</strong> 08:00 - 14:00 WITA</p>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-icon">
                            <i class="bi bi-geo-alt"></i>
                        </div>
                        <div class="info-content">
                            <h4>Lokasi Kantor</h4>
                            <p><strong>Alamat:</strong> Jl. Kolonel Rauf Mo'o</p>
                            <p><strong>Kota:</strong> Gorontalo</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <p>&copy; <?php echo date('Y'); ?> Sistem Pelayanan Kependudukan Kabupaten Gorontalo</p>
                <p class="footer-note">Semua formulir merupakan dokumen resmi pemerintah untuk keperluan administrasi kependudukan</p>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3><i class="bi bi-box-arrow-right"></i> Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout dari sistem?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-logout-confirm">Ya, Logout</button>
            <button id="cancelLogout" class="btn-logout-cancel">Batal</button>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script src="js/petugas_desa_formulir.js"></script>
<?php include __DIR__ . '/ai_chat_widget.php'; ?>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>
