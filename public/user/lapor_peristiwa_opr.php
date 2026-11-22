<?php
require_once '../error_handler.php';

// **CEK JIKA USER ADALAH OPERATOR KUA, REDIRECT KE KUA DASHBOARD**
if (isset($_SESSION['id_kua']) && !empty($_SESSION['id_kua']) && 
    isset($_SESSION['role']) && $_SESSION['role'] === 'operator') {
    
    // Redirect ke dashboard operator KUA
    header('Location: ../user_kua/kua_dashboard_operator.php');
    exit;
}

include '../../app/middleware/operator_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/document_token.php';
$notifikasi_candidates = [
    __DIR__ . '/../functions/notifikasi.php',
    dirname(__DIR__) . '/functions/notifikasi.php',
    dirname(__DIR__, 2) . '/functions/notifikasi.php'
];
$notifikasi_loaded = false;
foreach ($notifikasi_candidates as $notifikasi_file) {
    if (is_file($notifikasi_file)) {
        require_once $notifikasi_file;
        $notifikasi_loaded = true;
        break;
    }
}
if (!$notifikasi_loaded) {
    error_log('notifikasi.php tidak ditemukan. Pencarian: ' . implode(' | ', $notifikasi_candidates));
}
if (!function_exists('buatNotifikasi')) {
    function buatNotifikasi($koneksi, $id_user, $judul, $pesan, $tipe = 'info') {
        return false;
    }
}

$csrf_token_value = csrf_token();

if (isset($_GET['csrf_refresh']) && $_GET['csrf_refresh'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'csrf_token' => csrf_token()
    ]);
    exit;
}

// Fungsi helper untuk escape output
function esc($string) {
    return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
}

// Fungsi untuk format tanggal
function formatDate($date) {
    if (empty($date)) return '-';
    return date('d M Y H:i', strtotime($date));
}

// === Tentukan kecamatan tugas operator berdasarkan session array ===
$allowed_kecamatan_ids = $_SESSION['kecamatan_tugas'] ?? [];
$allowed_kecamatan_names = $_SESSION['kecamatan_nama'] ?? [];

// Ambil semua kecamatan dari database untuk operator yang bertugas di semua kecamatan
if (empty($allowed_kecamatan_ids) || in_array('semua', $allowed_kecamatan_ids) || strtolower(trim($_SESSION['kecamatan_tugas_str'] ?? '')) === 'semua') {
    $kec_query = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
    if ($kec_query) {
        while ($row = $kec_query->fetch_assoc()) {
            $all_kecamatan_ids[] = $row['id'];
            $all_kecamatan_names[] = $row['nama_kecamatan'];
        }
    }
    $allowed_kecamatan_ids = $all_kecamatan_ids;
    $allowed_kecamatan_names = $all_kecamatan_names;
}

// === PROSES UPDATE STATUS PERISTIWA ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'update_status_peristiwa') {
        $id = (int)($_POST['id'] ?? 0);
        $status_baru = trim($_POST['status'] ?? '');
        $alasan = trim($_POST['alasan'] ?? '');

        if ($id <= 0 || !in_array($status_baru, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
            $_SESSION['error_message'] = 'Data tidak valid.';
            header('Location: lapor_peristiwa_opr.php');
            exit;
        }

        // Validasi alasan jika ditolak
        if ($status_baru === 'ditolak' && empty($alasan)) {
            $_SESSION['error_message'] = 'Alasan penolakan wajib diisi.';
            header('Location: lapor_peristiwa_opr.php');
            exit;
        }

        // Cek apakah data ada di database DAN di kecamatan tugas operator
        $check_stmt = $koneksi->prepare("SELECT id_lapor, nama_kecamatan FROM tb_laporan_peristiwa WHERE id_lapor = ?");
        if (!$check_stmt) {
            $_SESSION['error_message'] = 'Error preparing check statement.';
            header('Location: lapor_peristiwa_opr.php');
            exit;
        }
        $check_stmt->bind_param('i', $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            $_SESSION['error_message'] = 'Data laporan tidak ditemukan.';
            header('Location: lapor_peristiwa_opr.php');
            exit;
        }
        
        $check_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        // Validasi: cek apakah laporan berada di kecamatan tugas operator
        if (!empty($allowed_kecamatan_names) && !in_array($check_data['nama_kecamatan'], $allowed_kecamatan_names)) {
            $_SESSION['error_message'] = 'Anda tidak memiliki akses untuk memproses laporan ini.';
            header('Location: lapor_peristiwa_opr.php');
            exit;
        }

        // Update status di tb_laporan_peristiwa
        $stmt = $koneksi->prepare("UPDATE tb_laporan_peristiwa SET status = ?, alasan = ?, updated_at = NOW() WHERE id_lapor = ?");
        if (!$stmt) {
            $_SESSION['error_message'] = 'Error preparing statement.';
            header('Location: lapor_peristiwa_opr.php');
            exit;
        }
        
        $stmt->bind_param('ssi', $status_baru, $alasan, $id);
        $result = $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            $_SESSION['error_message'] = 'Tidak ada perubahan atau data sudah diproses.';
        } else {
            // Ambil user_id pelapor untuk notifikasi
            $stmt_user = $koneksi->prepare("SELECT id_user, jenis_peristiwa, nama_subjek FROM tb_laporan_peristiwa WHERE id_lapor = ?");
            if (!$stmt_user) {
                $_SESSION['error_message'] = 'Status berhasil diupdate, namun notifikasi gagal dikirim.';
                header('Location: lapor_peristiwa_opr.php');
                exit;
            }
            $stmt_user->bind_param('i', $id);
            $stmt_user->execute();
            $user_result = $stmt_user->get_result()->fetch_assoc();
            $user_id_pelapor = $user_result['id_user'] ?? null;
            $jenis_peristiwa = $user_result['jenis_peristiwa'] ?? '';
            $nama_subjek = $user_result['nama_subjek'] ?? '';

            if ($user_id_pelapor) {
                $jenis_label = match($jenis_peristiwa) {
                    'lahir' => 'Kelahiran',
                    'mati' => 'Kematian',
                    'rentan_adminduk' => 'Rentan Administrasi',
                    default => 'Peristiwa'
                };

                if ($status_baru === 'ditolak') {
                    buatNotifikasi($koneksi, $user_id_pelapor,
                        "Laporan $jenis_label Ditolak",
                        "Laporan $jenis_label untuk $nama_subjek ditolak. Alasan: $alasan",
                        'danger'
                    );
                    $_SESSION['success_message'] = 'Status diubah ke Ditolak & notifikasi dikirim.';
                } elseif ($status_baru === 'diproses') {
                    buatNotifikasi($koneksi, $user_id_pelapor,
                        "Laporan $jenis_label Sedang Diproses",
                        "Laporan $jenis_label untuk $nama_subjek sedang diproses oleh operator.",
                        'info'
                    );
                    $_SESSION['success_message'] = 'Status diubah ke Diproses & notifikasi dikirim.';
                } elseif ($status_baru === 'selesai') {
                    buatNotifikasi($koneksi, $user_id_pelapor,
                        "Laporan $jenis_label Selesai",
                        "Laporan $jenis_label untuk $nama_subjek telah selesai diproses oleh operator.",
                        'success'
                    );
                    $_SESSION['success_message'] = 'Status diubah ke Selesai & notifikasi dikirim.';
                } else {
                    $_SESSION['success_message'] = 'Status berhasil diubah.';
                }
            } else {
                $_SESSION['success_message'] = 'Status diubah (user tidak ditemukan).';
            }
        }

        $stmt->close();
        
        // Redirect dengan parameter yang benar
        if (isset($_GET['detail_id'])) {
            header('Location: lapor_peristiwa_opr.php?detail_id=' . $_GET['detail_id']);
        } else {
            header('Location: lapor_peristiwa_opr.php');
        }
        exit;
    }
}

// === AMBIL DETAIL PERISTIWA JIKA ADA PARAMETER ===
$detail_data = null;
$detail_files = [];
if (isset($_GET['detail_id'])) {
    $detail_id = (int)$_GET['detail_id'];
    
    // Buat kondisi WHERE untuk filter kecamatan tugas operator
    $where_conditions = [];
    $where_types = '';
    $where_params = [];
    
    if (!empty($allowed_kecamatan_names) && count($allowed_kecamatan_names) < 20) {
        $placeholders = implode(',', array_fill(0, count($allowed_kecamatan_names), '?'));
        $where_conditions[] = "nama_kecamatan IN ($placeholders)";
        $where_types .= str_repeat('s', count($allowed_kecamatan_names));
        $where_params = array_merge($where_params, $allowed_kecamatan_names);
    }
    
    // Tambahkan ID sebagai kondisi terakhir
    $where_conditions[] = "id_lapor = ?";
    $where_types .= 'i';
    $where_params[] = $detail_id;
    
    $where_clause = implode(' AND ', $where_conditions);
    $query = "SELECT * FROM tb_laporan_peristiwa WHERE $where_clause";
    
    $stmt = $koneksi->prepare($query);
    if ($stmt) {
        $stmt->bind_param($where_types, ...$where_params);
        $stmt->execute();
        $result = $stmt->get_result();
        $detail_data = $result->fetch_assoc();
        
        if ($detail_data) {
            // Decode dokumen jika ada (format JSON)
            if (!empty($detail_data['dokumen'])) {
                $dokumen_data = json_decode($detail_data['dokumen'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dokumen_data)) {
                    $detail_files = $dokumen_data;
                } else {
                    // Jika bukan JSON, anggap sebagai single file path
                    $detail_files = [[
                        'path' => $detail_data['dokumen'],
                        'type' => 'dokumen',
                        'original_name' => basename($detail_data['dokumen'])
                    ]];
                }
            }
        }
        $stmt->close();
    }
}

foreach ($detail_files as &$detail_file) {
    $url = siprima_document_token_url(__DIR__, (string)($detail_file['path'] ?? ''));
    $detail_file['path'] = $url ?? '';
}
unset($detail_file);

// === PAGINATION UNTUK DAFTAR PERISTIWA ===
$per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;

$peristiwa_list = [];
$total_peristiwa = 0;
$total_pages = 0;

if (!isset($_GET['detail_id'])) {
    $filter_status = $_GET['status'] ?? '';
    $filter_jenis = $_GET['jenis'] ?? '';
    
    // Hitung total data HANYA untuk kecamatan tugas operator
    $count_query = "SELECT COUNT(*) as total FROM tb_laporan_peristiwa WHERE 1=1";
    $count_params = [];
    $count_types = '';
    
    // Filter berdasarkan kecamatan tugas operator
    if (!empty($allowed_kecamatan_names) && count($allowed_kecamatan_names) < 20) {
        $placeholders = implode(',', array_fill(0, count($allowed_kecamatan_names), '?'));
        $count_query .= " AND nama_kecamatan IN ($placeholders)";
        $count_types = str_repeat('s', count($allowed_kecamatan_names));
        $count_params = $allowed_kecamatan_names;
    }
    
    // Filter berdasarkan status
    if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
        $count_query .= " AND status = ?";
        $count_types .= 's';
        $count_params[] = $filter_status;
    }
    
    // Filter berdasarkan jenis peristiwa
    if ($filter_jenis && in_array($filter_jenis, ['lahir', 'mati', 'rentan_adminduk'])) {
        $count_query .= " AND jenis_peristiwa = ?";
        $count_types .= 's';
        $count_params[] = $filter_jenis;
    }
    
    $stmt_count = $koneksi->prepare($count_query);
    if ($stmt_count) {
        if (!empty($count_types)) {
            $stmt_count->bind_param($count_types, ...$count_params);
        }
        $stmt_count->execute();
        $result_count = $stmt_count->get_result();
        $row_count = $result_count->fetch_assoc();
        $total_peristiwa = (int)($row_count['total'] ?? 0);
        $stmt_count->close();
    }
    
    // Hitung total halaman
    $total_pages = ceil($total_peristiwa / $per_page);
    
    // Batasi current_page
    if ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
        $offset = ($current_page - 1) * $per_page;
    }
    
    // Ambil data dengan pagination
    if ($total_peristiwa > 0) {
        $query = "SELECT * FROM tb_laporan_peristiwa WHERE 1=1";
        $params = [];
        $types = '';
        
        // Filter berdasarkan kecamatan tugas operator
        if (!empty($allowed_kecamatan_names) && count($allowed_kecamatan_names) < 20) {
            $placeholders = implode(',', array_fill(0, count($allowed_kecamatan_names), '?'));
            $query .= " AND nama_kecamatan IN ($placeholders)";
            $types .= str_repeat('s', count($allowed_kecamatan_names));
            $params = $allowed_kecamatan_names;
        }
        
        // Tambahkan filter status
        if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
            $query .= " AND status = ?";
            $types .= 's';
            $params[] = $filter_status;
        }
        
        // Tambahkan filter jenis
        if ($filter_jenis && in_array($filter_jenis, ['lahir', 'mati', 'rentan_adminduk'])) {
            $query .= " AND jenis_peristiwa = ?";
            $types .= 's';
            $params[] = $filter_jenis;
        }
        
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = $per_page;
        $params[] = $offset;
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $peristiwa_list[] = $row;
            }
            $stmt->close();
        }
    }
}

// Hitung statistik HANYA untuk kecamatan tugas operator
$stats = [
    'total' => 0,
    'diajukan' => 0,
    'diproses' => 0,
    'selesai' => 0,
    'ditolak' => 0
];

$stats_query = "SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
    SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
    SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
    SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
FROM tb_laporan_peristiwa WHERE 1=1";

$stats_params = [];
$stats_types = '';

if (!empty($allowed_kecamatan_names) && count($allowed_kecamatan_names) < 20) {
    $placeholders = implode(',', array_fill(0, count($allowed_kecamatan_names), '?'));
    $stats_query .= " AND nama_kecamatan IN ($placeholders)";
    $stats_types = str_repeat('s', count($allowed_kecamatan_names));
    $stats_params = $allowed_kecamatan_names;
}

$stmt_stats = $koneksi->prepare($stats_query);
if ($stmt_stats) {
    if (!empty($stats_types)) {
        $stmt_stats->bind_param($stats_types, ...$stats_params);
    }
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    if ($row_stats = $result_stats->fetch_assoc()) {
        $stats['total'] = (int)($row_stats['total'] ?? 0);
        $stats['diajukan'] = (int)($row_stats['diajukan'] ?? 0);
        $stats['diproses'] = (int)($row_stats['diproses'] ?? 0);
        $stats['selesai'] = (int)($row_stats['selesai'] ?? 0);
        $stats['ditolak'] = (int)($row_stats['ditolak'] ?? 0);
    }
    $stmt_stats->close();
}

// Flash messages
$flash_success = $_SESSION['success_message'] ?? null;
$flash_error = $_SESSION['error_message'] ?? null;

unset($_SESSION['success_message'], $_SESSION['error_message']);

// Tentukan wilayah tugas untuk ditampilkan di header
if (empty($allowed_kecamatan_names)) {
    $wilayah_tugas = 'Belum ditugaskan ke kecamatan manapun';
} elseif (count($allowed_kecamatan_names) >= 20) {
    $wilayah_tugas = 'Semua Kecamatan';
} else {
    $wilayah_tugas = implode(', ', $allowed_kecamatan_names);
    if (strlen($wilayah_tugas) > 100) {
        $wilayah_tugas = substr($wilayah_tugas, 0, 100) . '...';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= isset($_GET['detail_id']) ? 'Detail Laporan Peristiwa' : 'Laporan Peristiwa' ?> - Operator</title>
    <link rel="stylesheet" href="css/lapor_peristiwa_opr.css?v=20261115-1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/operator_document_preview.css?v=20260824-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
    
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator Dukcapil</div>
        <nav>
            <ul>
                <li><a href="dashboard_operator.php">Dashboard</a></li>
                <li><a href="proses_pengajuan.php">Proses Pengajuan</a></li>
                <li class="active">Laporan Peristiwa</li>
                <li><a href="riwayat_operator.php">Riwayat</a></li>
                <li><a href="dokumen_email_belum_masuk.php">Dokumen Belum Diterima</a></li>
                <li><a href="formulir_operator.php">Formulir Kependudukan</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">
                    <?= isset($_GET['detail_id']) ? 'Detail Laporan Peristiwa' : 'Laporan Peristiwa' ?> - Operator
                </div>
                <div class="subtitle">Wilayah Tugas: <?= esc($wilayah_tugas) ?></div>
            </div>
            <div class="user-info">
                Hai, <?= esc($_SESSION['nama']) ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Flash Message Toast -->
            <?php if ($flash_success): ?>
                <div class="toast toast-success">
                    <?= esc($flash_success) ?>
                </div>
                <script>
                    setTimeout(() => {
                        const toast = document.querySelector('.toast');
                        if (toast) {
                            toast.classList.add('show');
                            setTimeout(() => toast.remove(), 5000);
                        }
                    }, 100);
                </script>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="toast toast-error">
                    <?= esc($flash_error) ?>
                </div>
                <script>
                    setTimeout(() => {
                        const toast = document.querySelector('.toast');
                        if (toast) {
                            toast.classList.add('show');
                            setTimeout(() => toast.remove(), 6000);
                        }
                    }, 100);
                </script>
            <?php endif; ?>

            <?php if (isset($_GET['detail_id']) && $detail_data): ?>
                <!-- Halaman Detail Laporan Peristiwa -->
                <a href="lapor_peristiwa_opr.php?page=<?= $current_page ?>&jenis=<?= urlencode($_GET['jenis'] ?? '') ?>&status=<?= urlencode($_GET['status'] ?? '') ?>" class="btn-back">
                    <i>←</i> Kembali ke Daftar Laporan
                </a>
                
                <div class="detail-section">
                    <div class="detail-header">
                        <h1 class="detail-title">
                            Detail Laporan Peristiwa
                            <span class="jenis-badge <?= match($detail_data['jenis_peristiwa']) {
                                'lahir' => 'jenis-lahir',
                                'mati' => 'jenis-mati',
                                'rentan_adminduk' => 'jenis-rentan',
                                default => ''
                            } ?>">
                                <?= match($detail_data['jenis_peristiwa']) {
                                    'lahir' => 'Kelahiran',
                                    'mati' => 'Kematian',
                                    'rentan_adminduk' => 'Rentan Administrasi',
                                    default => $detail_data['jenis_peristiwa']
                                } ?>
                            </span>
                        </h1>
                        <div class="detail-status status-<?= strtolower($detail_data['status'] ?? '') ?>">
                            <?= strtoupper($detail_data['status'] ?? 'TIDAK DIKETAHUI') ?>
                        </div>
                    </div>
                    
                    <div class="detail-grid">
                        <!-- Informasi Pelapor -->
                        <div class="detail-card">
                            <h3>Informasi Pelapor</h3>
                            <div class="detail-row">
                                <div class="detail-label">Nama Pelapor</div>
                                <div class="detail-value"><?= esc($detail_data['nama_pelapor']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">NIK Pelapor</div>
                                <div class="detail-value"><?= esc($detail_data['nik_pelapor']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">No HP</div>
                                <div class="detail-value"><?= esc($detail_data['no_hp']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?= esc($detail_data['email']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Tanggal Laporan</div>
                                <div class="detail-value"><?= formatDate($detail_data['created_at']) ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Lokasi -->
                        <div class="detail-card">
                            <h3>Informasi Lokasi</h3>
                            <div class="detail-row">
                                <div class="detail-label">Kecamatan</div>
                                <div class="detail-value"><?= esc($detail_data['nama_kecamatan']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Desa</div>
                                <div class="detail-value"><?= esc($detail_data['nama_desa']) ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Subjek Peristiwa -->
                        <div class="detail-card">
                            <h3>Informasi Subjek Peristiwa</h3>
                            <div class="detail-row">
                                <div class="detail-label">Nama Subjek</div>
                                <div class="detail-value"><?= esc($detail_data['nama_subjek']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">NIK Subjek</div>
                                <div class="detail-value"><?= esc($detail_data['nik_subjek'] ?: '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Tempat Peristiwa</div>
                                <div class="detail-value"><?= esc($detail_data['tempat_lahir_mati']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Tanggal Peristiwa</div>
                                <div class="detail-value"><?= formatDate($detail_data['tanggal_lahir_mati']) ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Orang Tua -->
                        <div class="detail-card">
                            <h3>Informasi Orang Tua</h3>
                            <div class="detail-row">
                                <div class="detail-label">Nama Ayah</div>
                                <div class="detail-value"><?= esc($detail_data['nama_ayah']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Nama Ibu</div>
                                <div class="detail-value"><?= esc($detail_data['nama_ibu']) ?></div>
                            </div>
                        </div>
                        
                        <!-- Keterangan -->
                        <?php if (!empty($detail_data['keterangan'])): ?>
                        <div class="detail-card">
                            <h3>Keterangan/Pesan dari Pelapor</h3>
                            <div class="detail-row">
                                <div class="detail-value"><?= nl2br(esc($detail_data['keterangan'])) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Alasan Penolakan -->
                        <?php if (!empty($detail_data['alasan'])): ?>
                        <div class="detail-card">
                            <h3>Alasan Penolakan</h3>
                            <div class="detail-row">
                                <div class="detail-value"><?= nl2br(esc($detail_data['alasan'])) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Dokumen yang Diupload -->
                    <?php if (!empty($detail_files)): ?>
                    <div class="dokumen-section">
                        <h3>Dokumen yang Diupload</h3>
                        <div class="dokumen-grid">
                            <?php foreach ($detail_files as $index => $file): 
                                $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
                                $is_previewable = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif']);
                                $icon = match($ext) {
                                    'pdf' => '📄',
                                    'jpg', 'jpeg', 'png', 'gif' => '🖼️',
                                    default => '📎'
                                };
                                $type_label = match($file['type'] ?? 'lainnya') {
                                    'surat_keterangan_lahir' => 'Surat Keterangan Lahir',
                                    'kk' => 'Kartu Keluarga',
                                    'ktp' => 'KTP',
                                    'akta_lahir' => 'Akta Lahir',
                                    'akta_kematian' => 'Akta Kematian',
                                    'ijazah' => 'Ijazah',
                                    'buku_nikah' => 'Buku Nikah',
                                    default => ucfirst($file['type'] ?? 'Dokumen')
                                };
                            ?>
                            <div class="dokumen-card" onclick="previewDokumen('<?= esc($file['path']) ?>', '<?= $ext ?>')">
                                <div class="dokumen-icon"><?= $icon ?></div>
                                <div class="dokumen-name"><?= esc($file['original_name'] ?? 'Dokumen ' . ($index + 1)) ?></div>
                                <div class="dokumen-type"><?= esc($type_label) ?></div>
                                <div class="dokumen-actions">
                                    <?php if ($is_previewable): ?>
                                    <button class="btn-preview" onclick="event.stopPropagation(); previewDokumen('<?= esc($file['path']) ?>', '<?= $ext ?>')">Preview</button>
                                    <?php endif; ?>
                                    <a href="<?= esc($file['path']) ?>&amp;download=1" class="btn-download" onclick="event.stopPropagation()">Download</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Form Update Status -->
                    <div class="status-form-container">
                        <h3>Ubah Status Laporan</h3>
                        <form method="POST" class="status-form" action="lapor_peristiwa_opr.php?detail_id=<?= $detail_data['id_lapor'] ?>">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="update_status_peristiwa">
                            <input type="hidden" name="id" value="<?= $detail_data['id_lapor'] ?>">
                            <select name="status" class="status-select" onchange="toggleAlasan(this)">
                                <option value="diajukan" <?= ($detail_data['status'] ?? '') === 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                                <option value="diproses" <?= ($detail_data['status'] ?? '') === 'diproses' ? 'selected' : '' ?>>Diproses</option>
                                <option value="selesai" <?= ($detail_data['status'] ?? '') === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="ditolak" <?= ($detail_data['status'] ?? '') === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                            </select>
                            <textarea name="alasan" placeholder="Tuliskan alasan penolakan (jika status ditolak)..." rows="3"><?= esc($detail_data['alasan'] ?? '') ?></textarea>
                            <button type="submit" class="update-btn">Update Status</button>
                        </form>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Halaman Utama (Daftar Laporan Peristiwa) -->
                <!-- Wilayah Tugas Info -->
                <div class="wilayah-info">
                    <div class="wilayah-icon">📍</div>
                    <div class="wilayah-text">
                        <h4>Wilayah Tugas Anda (Operator)</h4>
                        <p><?= esc($wilayah_tugas) ?></p>
                    </div>
                </div>

                <!-- Statistik Cards -->
                <div class="stats-cards">
                    <div class="stat-card total">
                        <h3>Total Laporan</h3>
                        <div class="count"><?= $stats['total'] ?></div>
                    </div>
                    
                    <div class="stat-card diajukan">
                        <h3>Diajukan</h3>
                        <div class="count"><?= $stats['diajukan'] ?></div>
                    </div>
                    
                    <div class="stat-card diproses">
                        <h3>Sedang Diproses</h3>
                        <div class="count"><?= $stats['diproses'] ?></div>
                    </div>
                    
                    <div class="stat-card selesai">
                        <h3>Selesai</h3>
                        <div class="count"><?= $stats['selesai'] ?></div>
                    </div>
                    
                    <div class="stat-card ditolak">
                        <h3>Ditolak</h3>
                        <div class="count"><?= $stats['ditolak'] ?></div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <h3>Filter Laporan Peristiwa</h3>
                    <form method="GET" class="filters">
                        <input type="hidden" name="page" value="1">
                        <div class="filter-group">
                            <label for="jenis">Jenis Peristiwa</label>
                            <select name="jenis" id="jenis">
                                <option value="">Semua Jenis</option>
                                <option value="lahir" <?= ($_GET['jenis'] ?? '') === 'lahir' ? 'selected' : '' ?>>Kelahiran</option>
                                <option value="mati" <?= ($_GET['jenis'] ?? '') === 'mati' ? 'selected' : '' ?>>Kematian</option>
                                <option value="rentan_adminduk" <?= ($_GET['jenis'] ?? '') === 'rentan_adminduk' ? 'selected' : '' ?>>Rentan Administrasi</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select name="status" id="status">
                                <option value="">Semua Status</option>
                                <option value="diajukan" <?= ($_GET['status'] ?? '') === 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                                <option value="diproses" <?= ($_GET['status'] ?? '') === 'diproses' ? 'selected' : '' ?>>Diproses</option>
                                <option value="selesai" <?= ($_GET['status'] ?? '') === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="ditolak" <?= ($_GET['status'] ?? '') === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn-filter">Terapkan Filter</button>
                        <a href="lapor_peristiwa_opr.php" class="btn-reset">Reset Filter</a>
                    </form>
                </div>

                <!-- Tabel Laporan Peristiwa -->
                <div class="table-section">
                    <h2>
                        Daftar Laporan Peristiwa
                        <span class="table-count">Total: <?= $total_peristiwa ?> data | Halaman <?= $current_page ?> dari <?= $total_pages ?></span>
                    </h2>
                    
                    <?php if (empty($peristiwa_list)): ?>
                        <div class="empty-state">
                            <i>📭</i>
                            <h4>Tidak ada laporan peristiwa ditemukan</h4>
                            <p>Tidak ada laporan peristiwa di wilayah tugas Anda dengan filter yang dipilih.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Jenis Peristiwa</th>
                                    <th>Nama Pelapor</th>
                                    <th>Subjek Peristiwa</th>
                                    <th>Kecamatan</th>
                                    <th>Desa</th>
                                    <th>Status Saat Ini</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = ($current_page - 1) * $per_page + 1;
                                foreach ($peristiwa_list as $row): 
                                    $jenis_label = match($row['jenis_peristiwa']) {
                                        'lahir' => 'Kelahiran',
                                        'mati' => 'Kematian',
                                        'rentan_adminduk' => 'Rentan Administrasi',
                                        default => $row['jenis_peristiwa']
                                    };
                                    $jenis_class = match($row['jenis_peristiwa']) {
                                        'lahir' => 'jenis-lahir',
                                        'mati' => 'jenis-mati',
                                        'rentan_adminduk' => 'jenis-rentan',
                                        default => ''
                                    };
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : '-' ?></td>
                                        <td>
                                            <span class="jenis-badge <?= $jenis_class ?>">
                                                <?= esc($jenis_label) ?>
                                            </span>
                                        </td>
                                        <td><?= esc($row['nama_pelapor']) ?></td>
                                        <td><?= esc($row['nama_subjek']) ?></td>
                                        <td><?= esc($row['nama_kecamatan']) ?></td>
                                        <td><?= esc($row['nama_desa']) ?></td>
                                        <td>
                                            <?php
                                            $status = strtolower($row['status']);
                                            $badge_class = match($status) {
                                                'selesai' => 'status-selesai',
                                                'ditolak' => 'status-ditolak',
                                                'diproses' => 'status-diproses',
                                                default => 'status-diajukan'
                                            };
                                            ?>
                                            <span class="status-badge <?= $badge_class ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="?detail_id=<?= $row['id_lapor'] ?>&page=<?= $current_page ?>&jenis=<?= urlencode($_GET['jenis'] ?? '') ?>&status=<?= urlencode($_GET['status'] ?? '') ?>" class="btn-detail">Detail</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                Menampilkan <?= count($peristiwa_list) ?> dari <?= $total_peristiwa ?> data
                                (Halaman <?= $current_page ?> dari <?= $total_pages ?>)
                            </div>
                            <div class="pagination-links">
                                <?php 
                                // Tombol Previous
                                if ($current_page > 1): 
                                    $prev_params = http_build_query(array_merge($_GET, ['page' => $current_page - 1]));
                                ?>
                                    <a href="?<?= $prev_params ?>" class="pagination-link">← Prev</a>
                                <?php else: ?>
                                    <span class="pagination-link disabled"> Prev</span>
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
                                if ($start_page > 1): 
                                    $first_params = http_build_query(array_merge($_GET, ['page' => 1]));
                                ?>
                                    <a href="?<?= $first_params ?>" class="pagination-link">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span class="pagination-link disabled">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): 
                                    $page_params = http_build_query(array_merge($_GET, ['page' => $i]));
                                ?>
                                    <a href="?<?= $page_params ?>" class="pagination-link <?= $i == $current_page ? 'active' : '' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php 
                                // Tampilkan halaman terakhir jika tidak termasuk
                                if ($end_page < $total_pages): 
                                    $last_params = http_build_query(array_merge($_GET, ['page' => $total_pages]));
                                ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="pagination-link disabled">...</span>
                                    <?php endif; ?>
                                    <a href="?<?= $last_params ?>" class="pagination-link"><?= $total_pages ?></a>
                                <?php endif; ?>
                                
                                <?php 
                                // Tombol Next
                                if ($current_page < $total_pages): 
                                    $next_params = http_build_query(array_merge($_GET, ['page' => $current_page + 1]));
                                ?>
                                    <a href="?<?= $next_params ?>" class="pagination-link">Next →</a>
                                <?php else: ?>
                                    <span class="pagination-link disabled">Next →</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- Modal Preview Dokumen -->
<div class="modal-overlay" id="previewModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Preview Dokumen</h3>
            <button class="btn-cancel" onclick="document.getElementById('previewModal').classList.remove('show')">Tutup</button>
        </div>
        <div class="modal-body" id="previewContent">
            Memuat preview...
        </div>
    </div>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3>Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
            <button id="cancelLogout" class="btn-cancel">Batal</button>
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
    
    // Reset to page 1 when changing filter
    const jenisSelect = document.getElementById('jenis');
    const statusSelect = document.getElementById('status');
    
    if (jenisSelect) {
        jenisSelect.addEventListener('change', function() {
            document.querySelector('input[name="page"]').value = 1;
        });
    }
    
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            document.querySelector('input[name="page"]').value = 1;
        });
    }
});

// Preview Dokumen Function
function previewDokumen(url, ext) {
    const content = document.getElementById('previewContent');
    content.innerHTML = '<p style="text-align:center; color:#666; padding:50px;">Memuat preview dokumen...</p>';

    let previewHTML = '';

    if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
        // Gambar
        previewHTML = `<img src="${url}" alt="Preview" style="max-width:100%; height:auto; display:block; margin:auto; box-shadow:0 4px 10px rgba(0,0,0,0.1);">`;
    } else if (ext === 'pdf') {
        // Gunakan Google Docs Viewer
        const fullUrl = encodeURIComponent(location.origin + '/' + url.replace(/^\.\.\//, ''));
        const googleViewer = `https://docs.google.com/viewer?url=${fullUrl}&embedded=true`;
        
        previewHTML = `<iframe src="${googleViewer}" style="width:100%; height:500px; border:none;" frameborder="0"></iframe>`;
    } else {
        // File lain: buka tab baru
        window.open(url, '_blank');
        return;
    }

    content.innerHTML = previewHTML;
    document.getElementById('previewModal').classList.add('show');
}

// Toggle Alasan Function
function toggleAlasan(select) {
    const textarea = select.parentElement.querySelector('textarea[name="alasan"]');
    if (textarea) {
        textarea.style.display = select.value === 'ditolak' ? 'block' : 'none';
        if (select.value !== 'ditolak') {
            textarea.value = '';
        }
    }
}

// Auto-hide toast notifications
document.addEventListener('DOMContentLoaded', function() {
    const toasts = document.querySelectorAll('.toast');
    toasts.forEach(toast => {
        setTimeout(() => {
            toast.classList.add('show');
        }, 100);
        setTimeout(() => {
            toast.remove();
        }, 5000);
    });
    
    // Set textarea display berdasarkan status saat ini
    const statusSelect = document.querySelector('select[name="status"]');
    if (statusSelect) {
        toggleAlasan(statusSelect);
    }

    // Defensive fallback: pastikan csrf_token selalu sinkron saat submit update status.
    const statusForms = document.querySelectorAll('form.status-form[method="POST"], form.status-form[method="post"]');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    statusForms.forEach(form => {
        let csrfInput = form.querySelector('input[name="csrf_token"]');
        if (!csrfInput) {
            csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            form.appendChild(csrfInput);
        }

        if (csrfToken) {
            csrfInput.value = csrfToken;
        }

        form.addEventListener('submit', async function(event) {
            event.preventDefault();

            try {
                const csrfUrl = new URL(window.location.href);
                csrfUrl.searchParams.set('csrf_refresh', '1');

                const response = await fetch(csrfUrl.toString(), {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                const data = await response.json();

                if (data && data.success && data.csrf_token) {
                    csrfInput.value = data.csrf_token;
                }
            } catch (err) {
                // Fallback ke token yang sudah ada di form.
            }

            form.submit();
        });
    });
});

// Tutup modal saat klik luar
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', e => {
        if (e.target === modal) modal.classList.remove('show');
    });
});
</script>
<script src="../assets/js/operator_document_preview.js?v=20260913-1"></script>
</body>
</html>
