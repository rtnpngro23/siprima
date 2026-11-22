<?php
require_once '../error_handler.php';

include '../../app/middleware/petugas_desa_only.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'update_status_peristiwa'
    && trim((string)($_POST['status'] ?? '')) === 'diajukan') {
    service_hours_guard_submission();
}
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

// Fungsi helper untuk escape output
function esc($string) {
    return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
}

// Fungsi untuk format tanggal
function formatDate($date) {
    if (empty($date)) return '-';
    return date('d M Y H:i', strtotime($date));
}

// Ambil data user untuk mendapatkan wilayah tugas petugas desa
$user_id = (int)$_SESSION['id_user'];
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

// Set nilai kecamatan dan desa dari data user
$user_kecamatan_id = $user_data['id_kecamatan'] ?? 0;
$user_desa_id = $user_data['id_desa'] ?? 0;
$nama_kecamatan_user = $user_data['nama_kecamatan'] ?? '';
$nama_desa_user = $user_data['nama_desa'] ?? '';

// === DEBUG: Log session dan POST data ===
error_log("=== DEBUG LAPOR PERISTIWA PETUGAS DESA ===");
error_log('Request: ' . siprima_request_id());
error_log("User ID: $user_id");
error_log("Kecamatan: $nama_kecamatan_user (ID: $user_kecamatan_id)");
error_log("Desa: $nama_desa_user (ID: $user_desa_id)");

// === PROSES UPDATE STATUS PERISTIWA ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    error_log("Processing POST action: " . $_POST['action']);
    
    if ($_POST['action'] === 'update_status_peristiwa') {
        $id = (int)($_POST['id'] ?? 0);
        $status_baru = trim($_POST['status'] ?? '');
        $alasan = trim($_POST['alasan'] ?? '');

        error_log("Update status: ID=$id, Status=$status_baru, Alasan=$alasan");

        if ($id <= 0 || !in_array($status_baru, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
            $_SESSION['error_message'] = 'Data tidak valid.';
            error_log("Invalid data: ID=$id, Status=$status_baru");
            header('Location: petugas_desa_lapor_peristiwa.php');
            exit;
        }

        // Validasi alasan jika ditolak
        if ($status_baru === 'ditolak' && empty($alasan)) {
            $_SESSION['error_message'] = 'Alasan penolakan wajib diisi.';
            error_log("Empty reason for rejection");
            header('Location: petugas_desa_lapor_peristiwa.php');
            exit;
        }

        // Cek apakah data ada di database DAN di desa tugas petugas
        $check_stmt = $koneksi->prepare("SELECT id_lapor, id_desa, nama_desa FROM tb_laporan_peristiwa WHERE id_lapor = ?");
        if (!$check_stmt) {
            $_SESSION['error_message'] = 'Error preparing check statement: ' . $koneksi->error;
            error_log("Prepare check error: " . $koneksi->error);
            header('Location: petugas_desa_lapor_peristiwa.php');
            exit;
        }
        $check_stmt->bind_param('i', $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            $_SESSION['error_message'] = 'Data laporan tidak ditemukan.';
            error_log("Data not found for ID: $id");
            header('Location: petugas_desa_lapor_peristiwa.php');
            exit;
        }
        
        $check_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        // Validasi: cek apakah laporan berada di desa tugas petugas
        if ($check_data['id_desa'] != $user_desa_id && $check_data['nama_desa'] != $nama_desa_user) {
            $_SESSION['error_message'] = 'Anda tidak memiliki akses untuk memproses laporan ini.';
            error_log("Access denied: User desa=$nama_desa_user, Report desa=" . ($check_data['nama_desa'] ?? 'NULL'));
            header('Location: petugas_desa_lapor_peristiwa.php');
            exit;
        }

        // Update status di tb_laporan_peristiwa
        $stmt = $koneksi->prepare("UPDATE tb_laporan_peristiwa SET status = ?, alasan = ?, updated_at = NOW() WHERE id_lapor = ?");
        if (!$stmt) {
            $_SESSION['error_message'] = 'Error preparing statement: ' . $koneksi->error;
            error_log("Prepare error: " . $koneksi->error);
            header('Location: petugas_desa_lapor_peristiwa.php');
            exit;
        }
        
        $stmt->bind_param('ssi', $status_baru, $alasan, $id);
        $result = $stmt->execute();
        
        error_log("Update execute result: " . ($result ? 'true' : 'false'));
        error_log("Affected rows: " . $stmt->affected_rows);
        error_log("Error: " . $stmt->error);

        if ($stmt->affected_rows === 0) {
            $_SESSION['error_message'] = 'Tidak ada perubahan atau data sudah diproses.';
        } else {
            // Ambil user_id pelapor untuk notifikasi
            $stmt_user = $koneksi->prepare("SELECT id_user, jenis_peristiwa, nama_subjek FROM tb_laporan_peristiwa WHERE id_lapor = ?");
            if (!$stmt_user) {
                error_log("Prepare error for user query: " . $koneksi->error);
                $_SESSION['error_message'] = 'Status berhasil diupdate, namun notifikasi gagal dikirim.';
                header('Location: petugas_desa_lapor_peristiwa.php');
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
                        "Laporan $jenis_label untuk $nama_subjek sedang diproses oleh petugas desa.",
                        'info'
                    );
                    $_SESSION['success_message'] = 'Status diubah ke Diproses & notifikasi dikirim.';
                } elseif ($status_baru === 'selesai') {
                    buatNotifikasi($koneksi, $user_id_pelapor,
                        "Laporan $jenis_label Selesai",
                        "Laporan $jenis_label untuk $nama_subjek telah selesai diproses oleh petugas desa.",
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
            header('Location: petugas_desa_lapor_peristiwa.php?detail_id=' . $_GET['detail_id']);
        } else {
            header('Location: petugas_desa_lapor_peristiwa.php');
        }
        exit;
    }
}

// === AMBIL DETAIL PERISTIWA JIKA ADA PARAMETER ===
$detail_data = null;
$detail_files = [];
if (isset($_GET['detail_id'])) {
    $detail_id = (int)$_GET['detail_id'];
    
    // Ambil detail laporan HANYA jika di desa tugas petugas
    $query = "SELECT * FROM tb_laporan_peristiwa WHERE id_lapor = ? AND (id_desa = ? OR nama_desa = ?)";
    $stmt = $koneksi->prepare($query);
    if ($stmt) {
        $stmt->bind_param('iis', $detail_id, $user_desa_id, $nama_desa_user);
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
            
            error_log("Detail data loaded: ID=" . ($detail_data['id_lapor'] ?? 'NULL') . 
                     ", Desa=" . ($detail_data['nama_desa'] ?? 'NULL'));
        } else {
            error_log("No detail data found for ID: $detail_id in desa $nama_desa_user");
        }
        $stmt->close();
    } else {
        error_log("Prepare failed for detail query: " . $koneksi->error);
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
    
    // Hitung total data HANYA untuk desa tugas petugas
    $count_query = "SELECT COUNT(*) as total FROM tb_laporan_peristiwa WHERE (id_desa = ? OR nama_desa = ?)";
    $count_params = [$user_desa_id, $nama_desa_user];
    $count_types = 'is';
    
    // Filter berdasarkan status
    if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
        $count_query .= " AND status = ?";
        $count_params[] = $filter_status;
        $count_types .= 's';
    }
    
    // Filter berdasarkan jenis peristiwa
    if ($filter_jenis && in_array($filter_jenis, ['lahir', 'mati', 'rentan_adminduk'])) {
        $count_query .= " AND jenis_peristiwa = ?";
        $count_params[] = $filter_jenis;
        $count_types .= 's';
    }
    
    $stmt_count = $koneksi->prepare($count_query);
    if ($stmt_count) {
        $stmt_count->bind_param($count_types, ...$count_params);
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
        $query = "SELECT * FROM tb_laporan_peristiwa WHERE (id_desa = ? OR nama_desa = ?)";
        
        // Tambahkan filter status
        if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
            $query .= " AND status = ?";
        }
        
        // Tambahkan filter jenis
        if ($filter_jenis && in_array($filter_jenis, ['lahir', 'mati', 'rentan_adminduk'])) {
            $query .= " AND jenis_peristiwa = ?";
        }
        
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            // Gabungkan parameter
            $params = [$user_desa_id, $nama_desa_user];
            $types = 'is';
            
            if ($filter_status) {
                $params[] = $filter_status;
                $types .= 's';
            }
            
            if ($filter_jenis) {
                $params[] = $filter_jenis;
                $types .= 's';
            }
            
            $params[] = $per_page;
            $params[] = $offset;
            $types .= 'ii';
            
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $peristiwa_list[] = $row;
            }
            $stmt->close();
        }
    }
    
    error_log("Total peristiwa di desa $nama_desa_user: $total_peristiwa, Showing: " . count($peristiwa_list));
}

// Hitung statistik HANYA untuk desa tugas petugas
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
FROM tb_laporan_peristiwa 
WHERE (id_desa = ? OR nama_desa = ?)";

$stmt_stats = $koneksi->prepare($stats_query);
if ($stmt_stats) {
    $stmt_stats->bind_param('is', $user_desa_id, $nama_desa_user);
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

// Debug flash messages
error_log("Flash success: " . ($flash_success ?? 'NULL'));
error_log("Flash error: " . ($flash_error ?? 'NULL'));

unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($_GET['detail_id']) ? 'Detail Laporan Peristiwa' : 'Laporan Peristiwa' ?> - Petugas Desa</title>
    <link rel="stylesheet" href="css/petugas_desa_lapor_peristiwa.css?v=20261118-1">
    <link rel="stylesheet" href="../assets/css/operator_document_preview.css?v=20260824-1">
    <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
</head>
<body class="petugas-desa-ui">

<div class="layout">
    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle"></div>

    <main class="main">
        <header class="header petugas-desa-header">
            <?php
            require_once __DIR__ . '/petugas_desa_header_brand.php';
            render_petugas_desa_header_brand(
                isset($_GET['detail_id']) ? 'Detail Laporan Peristiwa' : 'Laporan Peristiwa',
                'Petugas Desa • ' . $nama_kecamatan_user . ' / ' . $nama_desa_user
            );
            ?>
            <div class="user-info">
                <span class="user-role">Petugas Desa</span>
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
                        if (toast) toast.remove();
                    }, 5000);
                </script>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="toast toast-error">
                    <?= esc($flash_error) ?>
                </div>
                <script>
                    setTimeout(() => {
                        const toast = document.querySelector('.toast');
                        if (toast) toast.remove();
                    }, 6000);
                </script>
            <?php endif; ?>

            <?php if (isset($_GET['detail_id']) && $detail_data): ?>
                <!-- Halaman Detail Laporan Peristiwa -->
                <a href="petugas_desa_lapor_peristiwa.php?page=<?= $current_page ?>&jenis=<?= urlencode($_GET['jenis'] ?? '') ?>&status=<?= urlencode($_GET['status'] ?? '') ?>" class="btn-back">
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
                        <form method="POST" class="status-form" action="petugas_desa_lapor_peristiwa.php?detail_id=<?= $detail_data['id_lapor'] ?>
<?php echo csrf_input(); ?>">
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
                        <h4>Wilayah Tugas Anda (Petugas Desa)</h4>
                        <p><?= esc($nama_kecamatan_user . ' / ' . $nama_desa_user) ?></p>
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
                    <h3>Filter Laporan Peristiwa di Desa <?= esc($nama_desa_user) ?></h3>
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
                        <a href="petugas_desa_lapor_peristiwa.php" class="btn-reset">Reset Filter</a>
                    </form>
                </div>

                <!-- Tabel Laporan Peristiwa -->
                <div class="table-section">
                    <h2>
                        Daftar Laporan Peristiwa di Desa <?= esc($nama_desa_user) ?>
                        <span class="table-count">Total: <?= $total_peristiwa ?> data | Halaman <?= $current_page ?> dari <?= $total_pages ?></span>
                    </h2>
                    
                    <?php if (empty($peristiwa_list)): ?>
                        <div class="empty-state">
                            <i>📭</i>
                            <h4>Tidak ada laporan peristiwa ditemukan</h4>
                            <p>Tidak ada laporan peristiwa di desa <?= esc($nama_desa_user) ?> dengan filter yang dipilih.</p>
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
    document.getElementById('jenis').addEventListener('change', function() {
        document.querySelector('input[name="page"]').value = 1;
    });
    
    document.getElementById('status').addEventListener('change', function() {
        document.querySelector('input[name="page"]').value = 1;
    });
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
            toast.remove();
        }, 5000);
    });
    
    // Set textarea display berdasarkan status saat ini
    const statusSelect = document.querySelector('select[name="status"]');
    if (statusSelect) {
        toggleAlasan(statusSelect);
    }
});

// Tutup modal saat klik luar
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', e => {
        if (e.target === modal) modal.classList.remove('show');
    });
});
</script>
<script src="../assets/js/operator_document_preview.js?v=20260913-1"></script>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>
