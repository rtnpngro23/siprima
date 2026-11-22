<?php
require_once '../error_handler.php';
include '../../app/middleware/petugas_desa_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/email_delivery.php';


// PERBAIKAN: Gunakan 'id_user' bukan 'user_id'
$user_id = $_SESSION['id_user'] ?? 0; // Tambahkan null coalescing untuk safety

// Validasi jika user_id tidak ditemukan
if (!$user_id) {
    // Redirect ke login jika tidak ada user_id
    header('Location: ../login.php');
    exit;
}

$email_delivery_pending_count = email_delivery_pending_confirmation_count($koneksi, $user_id);

// Ambil data wilayah tugas petugas desa
$user_query = $koneksi->query("SELECT 
    u.id_kecamatan,
    u.id_desa,
    k.nama_kecamatan,
    d.nama_desa
FROM tb_users u
LEFT JOIN tb_kecamatan k ON u.id_kecamatan = k.id
LEFT JOIN tb_desa d ON u.id_desa = d.id
WHERE u.id = '$user_id'");

$user_data = $user_query->fetch_assoc();
$user_kecamatan_id = $user_data['id_kecamatan'] ?? 0;
$user_desa_id = $user_data['id_desa'] ?? 0;
$nama_kecamatan_user = $user_data['nama_kecamatan'] ?? '';
$nama_desa_user = $user_data['nama_desa'] ?? '';

// === FUNGSI UNTUK MENGAMBIL STATISTIK DARI TABEL ===
function getStatusStats($koneksi, $table_name, $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user) {
    $stats = [
        'total' => 0,
        'diajukan' => 0,
        'diproses' => 0,
        'selesai' => 0,
        'ditolak' => 0
    ];
    
    $query = "SELECT 
        COUNT(*) AS total,
        COALESCE(SUM(status = 'diajukan'), 0) AS diajukan,
        COALESCE(SUM(status = 'diproses'), 0) AS diproses,
        COALESCE(SUM(status = 'selesai'), 0) AS selesai,
        COALESCE(SUM(status = 'ditolak'), 0) AS ditolak
    FROM {$table_name} 
    WHERE id_user = ? 
      AND ((id_kecamatan = ? AND id_desa = ?) OR (nama_kecamatan = ? AND nama_desa = ?))";
    
    $stmt = $koneksi->prepare($query);
    if ($stmt) {
        $stmt->bind_param('iiiss', $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['total'] = (int)($row['total'] ?? 0);
            $stats['diajukan'] = (int)($row['diajukan'] ?? 0);
            $stats['diproses'] = (int)($row['diproses'] ?? 0);
            $stats['selesai'] = (int)($row['selesai'] ?? 0);
            $stats['ditolak'] = (int)($row['ditolak'] ?? 0);
        }
        $stmt->close();
    }
    
    return $stats;
}

// === AMBIL STATISTIK UNTUK SEMUA TABEL ===
$stats_laporan = getStatusStats($koneksi, 'tb_laporan_peristiwa', $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user);
$stats_kk = getStatusStats($koneksi, 'tb_kartukeluarga', $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user);
$stats_akta_lahir = getStatusStats($koneksi, 'tb_akta_kelahiran', $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user);
$stats_akta_mati = getStatusStats($koneksi, 'tb_akta_kematian', $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user);
$stats_pindah_alamat = getStatusStats($koneksi, 'tb_pindah_alamat', $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user);
$stats_pindah_online = getStatusStats($koneksi, 'tb_pindah_online', $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user);

// === HITUNG TOTAL SEMUA PERMOHONAN ===
$total_semua_permohonan = 
    $stats_kk['total'] + 
    $stats_akta_lahir['total'] + 
    $stats_akta_mati['total'] + 
    $stats_pindah_alamat['total'] + 
    $stats_pindah_online['total'];

$total_diajukan = 
    $stats_kk['diajukan'] + 
    $stats_akta_lahir['diajukan'] + 
    $stats_akta_mati['diajukan'] + 
    $stats_pindah_alamat['diajukan'] + 
    $stats_pindah_online['diajukan'];

$total_diproses = 
    $stats_kk['diproses'] + 
    $stats_akta_lahir['diproses'] + 
    $stats_akta_mati['diproses'] + 
    $stats_pindah_alamat['diproses'] + 
    $stats_pindah_online['diproses'];

$total_selesai = 
    $stats_kk['selesai'] + 
    $stats_akta_lahir['selesai'] + 
    $stats_akta_mati['selesai'] + 
    $stats_pindah_alamat['selesai'] + 
    $stats_pindah_online['selesai'];

$total_ditolak = 
    $stats_kk['ditolak'] + 
    $stats_akta_lahir['ditolak'] + 
    $stats_akta_mati['ditolak'] + 
    $stats_pindah_alamat['ditolak'] + 
    $stats_pindah_online['ditolak'];

// === PAGINATION UNTUK DATA LAPORAN PERISTIWA DIAJUKAN ===
$per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;

$laporan_list = [];
$total_laporan_diajukan = $stats_laporan['diajukan'];
$total_pages = ceil($total_laporan_diajukan / $per_page);

// Batasi current_page
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $per_page;
}

// Ambil data laporan dengan pagination
if ($total_laporan_diajukan > 0) {
    $query = "SELECT * FROM tb_laporan_peristiwa 
    WHERE id_user = ?
      AND ((id_kecamatan = ? AND id_desa = ?) OR (nama_kecamatan = ? AND nama_desa = ?))
      AND status = 'diajukan' 
    ORDER BY created_at DESC LIMIT ? OFFSET ?";
    
    $stmt = $koneksi->prepare($query);
    if ($stmt) {
        $stmt->bind_param('iiissii', $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user, $per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $laporan_list[] = $row;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard ADD - Adminduk Dari Desa</title>
    <link rel="stylesheet" href="css/dashboard_petugas_desa.css?v=20261004-1">
    <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="petugas-desa-ui">

<div class="layout">
    <!-- Sidebar -->
    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header petugas-desa-header">
            <?php
            require_once __DIR__ . '/petugas_desa_header_brand.php';
            render_petugas_desa_header_brand(
                'Dashboard ADD',
                'Petugas Desa • ' . $nama_kecamatan_user . ' / ' . $nama_desa_user
            );
            ?>
            <div class="user-info">
                <span>Hai, <?= htmlspecialchars($_SESSION['nama']) ?></span>
                <?php include __DIR__ . '/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <?php
            $email_delivery_status_url = 'petugas_desa_status_permohonan.php';
            $email_delivery_audience = 'petugas_desa';
            include __DIR__ . '/email_delivery_dashboard_alert.php';
            ?>
            <!-- Welcome Message -->
            <div class="welcome-box">
                <h1>Selamat Datang, <?= htmlspecialchars($_SESSION['nama']) ?>!</h1>
                <p>Selamat datang di Adminduk Dari Desa (ADD). Kelola semua permohonan dan laporan peristiwa di wilayah tugas Anda.</p>
            </div>

            <div class="notice-row">
                <aside class="privacy-warning" role="note" aria-labelledby="privacyWarningTitle">
                    <div class="privacy-warning__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M12 3 4.5 6v5.2c0 4.7 3.1 8.4 7.5 9.8 4.4-1.4 7.5-5.1 7.5-9.8V6L12 3Z"></path>
                            <path d="M9.5 11v-1a2.5 2.5 0 0 1 5 0v1"></path>
                            <rect x="8.5" y="11" width="7" height="5.5" rx="1"></rect>
                        </svg>
                    </div>
                    <div class="privacy-warning__content">
                        <span class="privacy-warning__label">Peringatan Perlindungan Data Pribadi</span>
                        <h2 id="privacyWarningTitle">Lindungi data pribadi masyarakat</h2>
                        <p>
                            Sesuai <strong>Undang-Undang Nomor 27 Tahun 2022 tentang Perlindungan Data Pribadi</strong>,
                            dilarang menyebarkan atau menggunakan data pribadi untuk keuntungan pribadi maupun untuk
                            tindakan yang dapat merugikan orang lain. Akses dan gunakan data hanya untuk kepentingan pelayanan resmi.
                        </p>
                    </div>
                </aside>

                <aside class="fraud-warning" role="note" aria-labelledby="fraudWarningTitle">
                    <div class="fraud-warning__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M6 3h8l4 4v14H6V3Z"></path>
                            <path d="M14 3v4h4"></path>
                            <path d="m9 12 6 6"></path>
                            <path d="m15 12-6 6"></path>
                        </svg>
                    </div>
                    <div class="fraud-warning__content">
                        <span class="fraud-warning__label">Peringatan Pemalsuan Data dan Dokumen</span>
                        <h2 id="fraudWarningTitle">Jangan catatkan data yang tidak benar</h2>
                        <p>
                            Memalsukan dokumen atau mencatatkan keterangan yang tidak benar atas nama warga adalah
                            tindak pidana, diatur dalam <strong>Pasal 93 Undang-Undang Administrasi Kependudukan</strong>
                            serta <strong>Pasal 263 dan 266 KUHP</strong> tentang pemalsuan surat. Sebagai petugas, jangan
                            meloloskan berkas yang Anda ragukan keasliannya. Pastikan data yang Anda masukkan sesuai
                            dokumen asli warga.
                        </p>
                    </div>
                </aside>
            </div>

            <!-- Statistik Semua Permohonan -->
            <div class="stats-section">
                <h2>📊 Statistik Semua Permohonan</h2>
                <div class="cards">
                    <!-- Kartu Total -->
                    <div class="card card-total">
                        <h3>Total Semua Permohonan</h3>
                        <div class="numbers">
                            <div class="number-item">
                                <p><?= $total_semua_permohonan ?></p>
                                <span>Total</span>
                            </div>
                            <div class="number-item">
                                <p><?= $total_diajukan ?></p>
                                <span>Diajukan</span>
                            </div>
                            <div class="number-item">
                                <p><?= $total_diproses ?></p>
                                <span>Diproses</span>
                            </div>
                            <div class="number-item">
                                <p><?= $total_selesai ?></p>
                                <span>Selesai</span>
                            </div>
                            <div class="number-item">
                                <p><?= $total_ditolak ?></p>
                                <span>Ditolak</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <div class="action-card">
                    <h3>Ajukan Permohonan Baru</h3>
                    <p>Ajukan permohonan kartu keluarga, akta kelahiran, akta kematian, atau perpindahan.</p>
                    <a href="petugas_desa_ajukan_permohonan.php" class="btn-success">Ajukan Permohonan</a>
                </div>
                
                <div class="action-card">
                    <h3>Cek Status Permohonan</h3>
                    <p>Periksa status permohonan yang telah diajukan. Lihat progress dan update status.</p>
                    <a href="petugas_desa_status_permohonan.php" class="btn-primary">Lihat Status</a>
                </div>
                
                <div class="action-card">
                    <h3>Lapor Peristiwa</h3>
                    <p>Buat laporan peristiwa kependudukan seperti kelahiran, kematian, atau peristiwa lainnya.</p>
                    <a href="petugas_desa_lapor_peristiwa.php" class="btn-success">Buat Laporan</a>
                </div>
            </div>

            <!-- Tabel Laporan Peristiwa yang Diajukan -->
            <div class="laporan-section">
                <h2>
                    📋 Laporan Peristiwa (Dasawisma/masyarakat)
                    <span class="section-count">Total: <?= $total_laporan_diajukan ?> data | Halaman <?= $current_page ?> dari <?= $total_pages ?></span>
                </h2>
                
                <?php if (empty($laporan_list)): ?>
                    <div class="empty-state">
                        <i>📭</i>
                        <h4>Tidak ada laporan peristiwa yang diajukan</h4>
                        <p>Tidak ada laporan peristiwa dengan status "diajukan" di desa <?= htmlspecialchars($nama_desa_user) ?>.</p>
                    </div>
                <?php else: ?>
                    <table class="laporan-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal Laporan</th>
                                <th>Jenis Peristiwa</th>
                                <th>Nama Pelapor</th>
                                <th>Subjek Peristiwa</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = ($current_page - 1) * $per_page + 1;
                            foreach ($laporan_list as $row): 
                                $jenis_label = match($row['jenis_peristiwa'] ?? '') {
                                    'lahir' => 'Kelahiran',
                                    'mati' => 'Kematian',
                                    'rentan_adminduk' => 'Rentan Administrasi',
                                    default => $row['jenis_peristiwa'] ?? '-'
                                };
                                $jenis_class = match($row['jenis_peristiwa'] ?? '') {
                                    'lahir' => 'jenis-lahir',
                                    'mati' => 'jenis-mati',
                                    'rentan_adminduk' => 'jenis-rentan',
                                    default => ''
                                };
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= !empty($row['created_at']) ? date('d M Y H:i', strtotime($row['created_at'])) : '-' ?></td>
                                    <td>
                                        <span class="jenis-badge <?= $jenis_class ?>">
                                            <?= htmlspecialchars($jenis_label) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($row['nama_pelapor'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['nama_subjek'] ?? '-') ?></td>
                                    <td>
                                        <span class="status-badge status-diajukan">
                                            Diajukan
                                        </span>
                                    </td>
                                    <td>
                                        <a href="petugas_desa_lapor_peristiwa.php?detail_id=<?= $row['id_lapor'] ?>" class="btn-detail">Detail</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Menampilkan <?= count($laporan_list) ?> dari <?= $total_laporan_diajukan ?> data
                        </div>
                        <div class="pagination-links">
                            <?php 
                            // Tombol Previous
                            if ($current_page > 1): 
                                $prev_page = $current_page - 1;
                            ?>
                                <a href="?page=<?= $prev_page ?>" class="pagination-link">← Prev</a>
                            <?php else: ?>
                                <span class="pagination-link disabled">← Prev</span>
                            <?php endif; ?>
                            
                            <?php 
                            // Tampilkan 5 halaman di sekitar current page
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            // Jika di awal, tambah halaman di akhir
                            if ($start_page == 1) {
                                $end_page = min($total_pages, $start_page + 4);
                            }
                            
                            // Jika di akhir, tambah halaman di awal
                            if ($end_page == $total_pages) {
                                $start_page = max(1, $end_page - 4);
                            }
                            
                            // Tampilkan halaman pertama jika tidak termasuk
                            if ($start_page > 1): ?>
                                <a href="?page=1" class="pagination-link">1</a>
                                <?php if ($start_page > 2): ?>
                                    <span class="pagination-link disabled">...</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?page=<?= $i ?>" class="pagination-link <?= $i == $current_page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php 
                            // Tampilkan halaman terakhir jika tidak termasuk
                            if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span class="pagination-link disabled">...</span>
                                <?php endif; ?>
                                <a href="?page=<?= $total_pages ?>" class="pagination-link"><?= $total_pages ?></a>
                            <?php endif; ?>
                            
                            <?php 
                            // Tombol Next
                            if ($current_page < $total_pages): 
                                $next_page = $current_page + 1;
                            ?>
                                <a href="?page=<?= $next_page ?>" class="pagination-link">Next →</a>
                            <?php else: ?>
                                <span class="pagination-link disabled">Next →</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <p>Butuh bantuan? Hubungi kami di <strong>081243425557</strong> atau email <strong>dkpskabgor@gmail.com</strong></p>
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
});
</script>

<?php include __DIR__ . '/ai_chat_widget.php'; ?>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>
