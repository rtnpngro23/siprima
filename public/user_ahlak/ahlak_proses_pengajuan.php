<?php
require_once __DIR__ . '/../user/service_tracking.php';
require_once '../error_handler.php';
// Gunakan middleware khusus operator AHLAK
include '../../app/middleware/operator_ahlak_only.php';

include '../../app/config/koneksi.php';
include '../functions/notifikasi.php';
require_once __DIR__ . '/../user/status_history_helper.php';
require_once __DIR__ . '/ahlak_document_helper.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kelahiran_ahlak LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}
$created_expr = $has_resubmitted_at ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';

// Fungsi untuk mendapatkan nama folder dari data permohonan
function getFolderNameFromPermohonan($koneksi, $table, $id) {
    $folder_name = '';
    
    if ($table === 'tb_akta_kelahiran_ahlak') {
        $query = "SELECT nama_folder FROM tb_akta_kelahiran_ahlak WHERE id = ?";
        $stmt = $koneksi->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = $result->fetch_assoc();
            $stmt->close();
            
            if ($data && !empty($data['nama_folder'])) {
                $folder_name = $data['nama_folder'];
            }
        }
    }
    
    return $folder_name;
}

// Fungsi helper untuk format ukuran file
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return $bytes . ' byte';
    } else {
        return '0 bytes';
    }
}

// === Tentukan puskesmas tugas operator ===
$allowed_puskes_ids = $_SESSION['puskes_tugas'] ?? [];
$allowed_puskes_names = $_SESSION['puskes_nama'] ?? [];

// Jika tidak ada puskesmas di session, ambil dari database
if (empty($allowed_puskes_ids)) {
    error_log("Operator has no puskesmas assigned! Loading from database...");
    
    // Ambil semua puskesmas dari database
    $puskes_query = $koneksi->query("SELECT id_puskes, nama_puskes FROM tb_puskes ORDER BY nama_puskes");
    if ($puskes_query) {
        while ($row = $puskes_query->fetch_assoc()) {
            $allowed_puskes_ids[] = $row['id_puskes'];
            $allowed_puskes_names[] = $row['nama_puskes'];
        }
        error_log("Loaded all puskesmas: " . count($allowed_puskes_names));
    }
} else {
    error_log("Operator has puskesmas assigned: " . count($allowed_puskes_names));
}

// === PROSES UPDATE STATUS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        serviceTrackingUpdate($koneksi, ['tb_akta_kelahiran_ahlak'], static fn(array $row): bool => in_array((int)($row['id_puskes'] ?? 0), array_map('intval', $allowed_puskes_ids), true));
    }
}

// === AMBIL DETAIL JIKA ADA PARAMETER ID ===
$detail_data = null;
$detail_files = [];
$detail_table = '';
$detail_folder_name = '';
$detail_alasan_history = [];

if (isset($_GET['detail_id']) && isset($_GET['table'])) {
    $detail_id = (int)$_GET['detail_id'];
    $detail_table = $_GET['table'];
    
    $valid_tables = ['tb_akta_kelahiran_ahlak'];
    
    if (in_array($detail_table, $valid_tables)) {
        $query = "SELECT *, {$created_expr} AS effective_created_at FROM `$detail_table` WHERE id = ?";
        
        // Filter berdasarkan puskesmas yang ditugaskan
        if (!empty($allowed_puskes_ids)) {
            $placeholders = implode(',', array_fill(0, count($allowed_puskes_ids), '?'));
            $query .= " AND id_puskes IN ($placeholders)";
        } else {
            $query .= " AND 1=0";
        }
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            if (!empty($allowed_puskes_ids)) {
                $params = array_merge([$detail_id], $allowed_puskes_ids);
                $types = 'i' . str_repeat('i', count($allowed_puskes_ids));
                $stmt->bind_param($types, ...$params);
            } else {
                $stmt->bind_param('i', $detail_id);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $detail_data = $result->fetch_assoc();
            
            if ($detail_data) {
                $detail_alasan_history = getPermohonanRejectionHistory($koneksi, $detail_table, $detail_id, 15);

                // Dapatkan nama folder untuk pembuatan path dokumen
                $detail_folder_name = getFolderNameFromPermohonan($koneksi, $detail_table, $detail_id);
                
                // Decode dokumen jika ada (format JSON)
                if (!empty($detail_data['dokumen'])) {
                    $dokumen_data = json_decode($detail_data['dokumen'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($dokumen_data)) {
                        $detail_files = [];
                        
                        foreach ($dokumen_data as $file) {
                            if (isset($file['path'])) {
                                // Normalize path
                                $file_path = $file['path'];
                                $found_path = '';
                                
                                // Coba berbagai kemungkinan path
                                $possible_paths = [
                                    $file_path,
                                    '../' . $file_path,
                                    str_replace('\\', '/', $file_path)
                                ];
                                
                                // Tambahkan path berdasarkan folder name jika ada
                                if ($detail_folder_name) {
                                    $table_folder = 'akta_kelahiran';
                                    $possible_paths[] = "uploads/{$table_folder}/{$detail_folder_name}/" . basename($file_path);
                                    $possible_paths[] = "../uploads/{$table_folder}/{$detail_folder_name}/" . basename($file_path);
                                    $possible_paths[] = "uploads/{$table_folder}/{$detail_folder_name}/dokumen_pendukung/" . basename($file_path);
                                }
                                
                                foreach ($possible_paths as $possible_path) {
                                    $full_path = __DIR__ . '/' . str_replace('../', '', $possible_path);
                                    if (file_exists($full_path)) {
                                        $found_path = $possible_path;
                                        break;
                                    }
                                }
                                
                                if ($found_path) {
                                    // Normalize path untuk browser
                                    $browser_path = $found_path;
                                    $browser_path = str_replace('../', '', $browser_path);
                                    if (strpos($browser_path, 'user/uploads/') === 0) {
                                        $browser_path = str_replace('user/uploads/', 'uploads/', $browser_path);
                                    }
                                    $detail_files[] = [
                                        'path' => $browser_path,
                                        'url' => ahlak_document_url($browser_path),
                                        'type' => $file['type'] ?? 'lainnya',
                                        'original_name' => $file['original_name'] ?? basename($file_path),
                                        'size' => $file['size'] ?? null
                                    ];
                                } else {
                                    // Jika tidak ditemukan, tetap tampilkan dengan path asli
                                    $browser_path = str_replace('../', '', $file_path);
                                    if (strpos($browser_path, 'user/uploads/') === 0) {
                                        $browser_path = str_replace('user/uploads/', 'uploads/', $browser_path);
                                    }
                                    $detail_files[] = [
                                        'path' => $browser_path,
                                        'url' => ahlak_document_url($browser_path),
                                        'type' => $file['type'] ?? 'lainnya',
                                        'original_name' => $file['original_name'] ?? basename($file_path),
                                        'size' => $file['size'] ?? null
                                    ];
                                }
                            }
                        }
                    } else {
                        // Jika bukan JSON, anggap sebagai single file path
                        $detail_files = [[
                            'path' => $detail_data['dokumen'],
                            'url' => ahlak_document_url((string)$detail_data['dokumen']),
                            'type' => 'dokumen',
                            'original_name' => basename($detail_data['dokumen'])
                        ]];
                    }
                }
                
                // Ambil data user pemohon
                $user_id = $detail_data['id_user'] ?? 0;
                $user_query = $koneksi->prepare("SELECT nama, nik, no_hp, email FROM tb_users WHERE id = ?");
                if ($user_query) {
                    $user_query->bind_param('i', $user_id);
                    $user_query->execute();
                    $user_result = $user_query->get_result();
                    $detail_data['user_info'] = $user_result->fetch_assoc();
                }
                
                // Ambil nama puskesmas
                $puskes_id = $detail_data['id_puskes'] ?? 0;
                if ($puskes_id) {
                    $puskes_query = $koneksi->prepare("SELECT nama_puskes FROM tb_puskes WHERE id_puskes = ?");
                    if ($puskes_query) {
                        $puskes_query->bind_param('i', $puskes_id);
                        $puskes_query->execute();
                        $puskes_result = $puskes_query->get_result();
                        $detail_data['puskes_info'] = $puskes_result->fetch_assoc();
                    }
                }
            }
            $stmt->close();
        }
    }
}

// === PAGINATION SETTINGS ===
$per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;

// === TAMPILKAN DATA PENGAJUAN ===
$submissions = [];
$total_submissions = 0;
$total_pages = 0;

if (!isset($_GET['detail_id'])) {
    $filter_status = $_GET['status'] ?? '';

    $tables_config = [
        'tb_akta_kelahiran_ahlak' => [
            'nama_field' => 'nama_bayi',
            'jenis' => 'Akta Kelahiran AHLAK',
            'title_field' => 'nama_bayi',
            'table_name' => 'tb_akta_kelahiran_ahlak'
        ]
    ];
    
    $all_submissions = [];
    
    foreach ($tables_config as $table => $config) {
        $query = "SELECT t.id, {$created_expr} AS created_at, t.updated_at, t.status, t.nama_kecamatan, t.nama_desa, 
                         t.{$config['title_field']} AS title, t.id_user, t.id_puskes,
                         t.nama_bayi, t.tanggal_lahir, t.jenis_kelamin,
                         '{$table}' AS table_name,
                         '{$config['jenis']}' AS jenis
                  FROM `{$table}` t
                  WHERE 1=1";
        
        $where_conditions = [];
        $params = [];
        $types = '';
        
        // Filter berdasarkan puskesmas yang ditugaskan
        if (!empty($allowed_puskes_ids)) {
            $placeholders = implode(',', array_fill(0, count($allowed_puskes_ids), '?'));
            $where_conditions[] = "t.id_puskes IN ($placeholders)";
            $params = $allowed_puskes_ids;
            $types = str_repeat('i', count($allowed_puskes_ids));
        } else {
            $where_conditions[] = "1 = 0";
        }
        
        // Filter berdasarkan status
        if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
            $where_conditions[] = "t.status = ?";
            $params[] = $filter_status;
            $types .= 's';
        }
        
        if (!empty($where_conditions)) {
            $query .= " AND " . implode(" AND ", $where_conditions);
        }
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $all_submissions[] = $row;
            }
            $stmt->close();
        }
    }
    
    // Sort berdasarkan tanggal terbaru
    usort($all_submissions, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    $total_submissions = count($all_submissions);
    $total_pages = ceil($total_submissions / $per_page);
    
    if ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
        $offset = ($current_page - 1) * $per_page;
    }
    
    $submissions = array_slice($all_submissions, $offset, $per_page);
    
    // Ambil nama user dan puskesmas untuk setiap submission
    if (!empty($submissions)) {
        foreach ($submissions as &$submission) {
            $user_id = $submission['id_user'];
            $user_query = $koneksi->prepare("SELECT nama FROM tb_users WHERE id = ?");
            if ($user_query) {
                $user_query->bind_param('i', $user_id);
                $user_query->execute();
                $user_result = $user_query->get_result();
                $user_data = $user_result->fetch_assoc();
                $submission['nama_user'] = $user_data['nama'] ?? '-';
                $user_query->close();
            } else {
                $submission['nama_user'] = '-';
            }
            
            // Ambil nama puskesmas
            $puskes_id = $submission['id_puskes'] ?? 0;
            if ($puskes_id) {
                $puskes_query = $koneksi->prepare("SELECT nama_puskes FROM tb_puskes WHERE id_puskes = ?");
                if ($puskes_query) {
                    $puskes_query->bind_param('i', $puskes_id);
                    $puskes_query->execute();
                    $puskes_result = $puskes_query->get_result();
                    $puskes_data = $puskes_result->fetch_assoc();
                    $submission['nama_puskes'] = $puskes_data['nama_puskes'] ?? '-';
                    $puskes_query->close();
                }
            }
        }
    }
    
    error_log("Total submissions: $total_submissions");
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

// Flash messages
$flash_success = $_SESSION['success_message'] ?? null;
$flash_error = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Hitung statistik
$stats = [
    'total' => 0,
    'diajukan' => 0,
    'diproses' => 0,
    'selesai' => 0,
    'ditolak' => 0
];

if (!isset($_GET['detail_id'])) {
    $tables_to_count = ['tb_akta_kelahiran_ahlak'];
    
    foreach ($tables_to_count as $tbl) {
        try {
            $check = $koneksi->query("SHOW TABLES LIKE '" . $koneksi->real_escape_string($tbl) . "'");
            
            if ($check && $check->num_rows > 0) {
                $query = "SELECT 
                            COUNT(*) AS total,
                            SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
                            SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
                            SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                            SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
                          FROM `$tbl` WHERE 1=1";
                
                $where_conditions = [];
                $params = [];
                $types = '';
                
                if (!empty($allowed_puskes_ids)) {
                    $placeholders = implode(',', array_fill(0, count($allowed_puskes_ids), '?'));
                    $where_conditions[] = "id_puskes IN ($placeholders)";
                    $params = $allowed_puskes_ids;
                    $types = str_repeat('i', count($allowed_puskes_ids));
                } elseif (empty($allowed_puskes_ids)) {
                    $where_conditions[] = "1 = 0";
                }
                
                if (!empty($where_conditions)) {
                    $query .= " AND " . implode(" AND ", $where_conditions);
                }
                
                $stmt = $koneksi->prepare($query);
                if ($stmt) {
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($row = $result->fetch_assoc()) {
                        $stats['total'] += (int)($row['total'] ?? 0);
                        $stats['diajukan'] += (int)($row['diajukan'] ?? 0);
                        $stats['diproses'] += (int)($row['diproses'] ?? 0);
                        $stats['selesai'] += (int)($row['selesai'] ?? 0);
                        $stats['ditolak'] += (int)($row['ditolak'] ?? 0);
                    }
                    $stmt->close();
                }
            }
            $check->close();
        } catch (Exception $e) {
            error_log("Error counting stats for table $tbl: " . $e->getMessage());
        }
    }
}

// Tentukan wilayah tugas
if (empty($allowed_puskes_names)) {
    $wilayah_tugas = 'Belum ditugaskan ke puskesmas manapun';
} elseif (count($allowed_puskes_names) >= 19) { // 19 adalah total puskesmas di Gorontalo
    $wilayah_tugas = 'Semua Puskesmas';
} else {
    $wilayah_tugas = implode(', ', $allowed_puskes_names);
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
    <title><?= isset($_GET['detail_id']) ? 'Detail Akta Kelahiran' : 'Proses Pengajuan Akta Kelahiran' ?> - Operator AHLAK</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/ahlak.png">

    <link rel="stylesheet" href="../assets/css/operator_document_preview.css?v=20260824-1">
    <script src="../assets/js/service-tracking.js?v=20260908-1"></script>
    <link rel="stylesheet" href="../assets/css/service-timeline.css?v=20260908-2">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator AHLAK</div>
        <nav>
            <ul>
                <li><a href="ahlak_dashboard_operator.php"></i> Dashboard</a></li>
                <li class="active"></i> Proses Pengajuan</li>
                <li><a href="ahlak_riwayat_operator.php"></i> Riwayat</a></li>
                <li><a href="ahlak_dokumen_belum_diterima.php"></i> Dokumen Belum Diterima</a></li>
                <li id="logoutBtn"></i> Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">
                    <?= isset($_GET['detail_id']) ? 'Detail Akta Kelahiran' : 'Proses Pengajuan Akta Kelahiran' ?> - Operator AHLAK
                </div>
                <div class="subtitle">Wilayah Tugas: <?= esc($wilayah_tugas) ?></div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama']) ?></span>
                <span class="role-badge">OPERATOR AHLAK</span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout-btn" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Flash Message Toast -->
            <?php if ($flash_success): ?>
                <div class="toast toast-success">
                    <?= esc($flash_success) ?>
                </div>
                <script>setTimeout(() => document.querySelector('.toast')?.remove(), 5000);</script>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="toast toast-error">
                    <?= esc($flash_error) ?>
                </div>
                <script>setTimeout(() => document.querySelector('.toast')?.remove(), 6000);</script>
            <?php endif; ?>

            <?php if (isset($_GET['detail_id']) && $detail_data): ?>
                <!-- Halaman Detail Pengajuan -->
                <a href="ahlak_proses_pengajuan.php?page=<?= $current_page ?>&status=<?= urlencode($_GET['status'] ?? '') ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Kembali ke Daftar Pengajuan
                </a>
                
                <div class="detail-section">
                    <div class="detail-header">
                        <h1 class="detail-title">
                            Detail Akta Kelahiran AHLAK
                            <span class="jenis-badge">Akta Kelahiran AHLAK</span>
                        </h1>
                        <div data-service-current-status class="detail-status status-<?= strtolower($detail_data['status'] ?? '') ?>">
                            <?= strtoupper($detail_data['status'] ?? 'TIDAK DIKETAHUI') ?>
                        </div>
                    </div>
                    
                    <div class="detail-grid">
                        <!-- Informasi Pemohon -->
                        <div class="detail-card">
                            <h3>Informasi Pemohon</h3>
                            <div class="detail-row">
                                <div class="detail-label">Nama Pemohon</div>
                                <div class="detail-value"><?= esc($detail_data['nama_pemohon'] ?? ($detail_data['user_info']['nama'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">NIK Pemohon</div>
                                <div class="detail-value"><?= esc($detail_data['nik_pemohon'] ?? ($detail_data['user_info']['nik'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">No HP</div>
                                <div class="detail-value"><?= esc($detail_data['no_hp'] ?? ($detail_data['user_info']['no_hp'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?= esc($detail_data['email'] ?? ($detail_data['user_info']['email'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Tanggal Pengajuan</div>
                                <div class="detail-value"><?= formatDate($detail_data['effective_created_at'] ?? ($detail_data['created_at'] ?? '')) ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Lokasi & Puskesmas -->
                        <div class="detail-card">
                            <h3>Informasi Lokasi</h3>
                            <div class="detail-row">
                                <div class="detail-label">Puskesmas</div>
                                <div class="detail-value"><?= esc($detail_data['puskes_info']['nama_puskes'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Kecamatan</div>
                                <div class="detail-value"><?= esc($detail_data['nama_kecamatan'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Desa</div>
                                <div class="detail-value"><?= esc($detail_data['nama_desa'] ?? '-') ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Bayi -->
                        <div class="detail-card">
                            <h3>Informasi Bayi</h3>
                            <div class="detail-row">
                                <div class="detail-label">Nama Bayi</div>
                                <div class="detail-value"><?= esc($detail_data['nama_bayi'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Jenis Kelamin</div>
                                <div class="detail-value">
                                    <?= ($detail_data['jenis_kelamin'] ?? '') === 'L' ? 'Laki-laki' : 'Perempuan' ?>
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Tempat Lahir</div>
                                <div class="detail-value"><?= esc($detail_data['tempat_lahir'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Tanggal Lahir</div>
                                <div class="detail-value"><?= formatDate($detail_data['tanggal_lahir'] ?? '') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Waktu Lahir</div>
                                <div class="detail-value"><?= esc($detail_data['Waktu'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Anak ke-</div>
                                <div class="detail-value"><?= esc($detail_data['urutan_kelahiran'] ?? '-') ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Orang Tua -->
                        <div class="detail-card">
                            <h3>Informasi Orang Tua</h3>
                            <div class="detail-row">
                                <div class="detail-label">Nama Ayah</div>
                                <div class="detail-value"><?= esc($detail_data['nama_ayah'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">NIK Ayah</div>
                                <div class="detail-value"><?= esc($detail_data['nik_ayah'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Nama Ibu</div>
                                <div class="detail-value"><?= esc($detail_data['nama_ibu'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">NIK Ibu</div>
                                <div class="detail-value"><?= esc($detail_data['nik_ibu'] ?? '-') ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Saksi -->
                        <?php if (!empty($detail_data['nama_saksi1']) || !empty($detail_data['nama_saksi2'])): ?>
                        <div class="detail-card">
                            <h3>Informasi Saksi</h3>
                            <?php if (!empty($detail_data['nama_saksi1'])): ?>
                            <div class="detail-row">
                                <div class="detail-label">Saksi 1 (Nama)</div>
                                <div class="detail-value"><?= esc($detail_data['nama_saksi1']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Saksi 1 (NIK)</div>
                                <div class="detail-value"><?= esc($detail_data['nik_saksi1'] ?? '-') ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($detail_data['nama_saksi2'])): ?>
                            <div class="detail-row">
                                <div class="detail-label">Saksi 2 (Nama)</div>
                                <div class="detail-value"><?= esc($detail_data['nama_saksi2']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Saksi 2 (NIK)</div>
                                <div class="detail-value"><?= esc($detail_data['nik_saksi2'] ?? '-') ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Keterangan -->
                        <?php if (!empty($detail_data['keterangan'])): ?>
                        <div class="detail-card">
                            <h3>Keterangan/Pesan dari Pemohon</h3>
                            <div class="detail-row">
                                <div class="detail-value"><?= nl2br(esc($detail_data['keterangan'])) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Alasan Penolakan -->
                        <div class="detail-card" data-service-current-note style="display:<?= empty($detail_data['alasan']) ? 'none' : 'block' ?>">
                            <h3>Catatan Status</h3>
                            <div class="detail-row">
                                <div class="detail-value" data-service-note-value style="white-space:pre-wrap"><?= esc($detail_data['alasan'] ?? '') ?></div>
                            </div>
                        </div>

                        <?php if (!empty($detail_alasan_history)): ?>
                        <div class="detail-card">
                            <h3>Riwayat Alasan Penolakan</h3>
                            <?php foreach ($detail_alasan_history as $history_item): ?>
                            <div class="detail-row">
                                <div class="detail-value">
                                    <?= nl2br(esc($history_item['alasan'] ?? '-')) ?>
                                    <div style="margin-top:6px; font-size:12px; color:#6b7280;">
                                        <?= esc($history_item['created_at_formatted'] ?? '-') ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Dokumen yang Diupload -->
                    <?php if (!empty($detail_files)): ?>
                    <div class="dokumen-section">
                        <h3>Dokumen yang Diupload</h3>
                        <div class="dokumen-grid">
                            <?php 
                            $file_counter = 1;
                            foreach ($detail_files as $index => $file): 
                                $document_url = $file['url'] ?? null;
                                $ext = strtolower(pathinfo($file['original_name'] ?? $file['path'], PATHINFO_EXTENSION));
                                $ext = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif'], true) ? $ext : 'file';
                                $is_previewable = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif']);
                                $icon = match($ext) {
                                    'pdf' => '📄',
                                    'jpg', 'jpeg', 'png', 'gif' => '🖼️',
                                    default => '📎'
                                };
                                
                                // Tentukan label berdasarkan tipe dokumen
                                $type_label = match($file['type'] ?? 'lainnya') {
                                    'surat_lahir' => 'Surat Keterangan Lahir',
                                    'buku_nikah' => 'Buku Nikah/Akta Kawin',
                                    'formulir' => 'Formulir F-2.01 Kelahiran',
                                    'kk' => 'Kartu Keluarga',
                                    'ktp_ortu' => 'KTP Orang Tua',
                                    'lainnya' => 'Dokumen Pendukung',
                                    default => ucwords(str_replace('_', ' ', $file['type'] ?? 'Dokumen'))
                                };
                                
                                $display_name = $file['original_name'] ?? 'Dokumen ' . $file_counter;
                                if (strlen($display_name) > 25) {
                                    $display_name = substr($display_name, 0, 22) . '...';
                                }
                                
                                $file_info = '';
                                if (isset($file['size'])) {
                                    $file_size = formatFileSize($file['size']);
                                    $file_info = " ({$file_size})";
                                }
                            ?>
                            <div class="dokumen-card"<?= $document_url ? ' onclick="previewDokumen(\'' . esc($document_url) . '\', \'' . esc($ext) . '\')"' : '' ?>>
                                <div class="dokumen-icon"><?= $icon ?></div>
                                <div class="dokumen-name" title="<?= esc($file['original_name'] ?? $display_name) ?>">
                                    <?= esc($display_name) ?>
                                </div>
                                <div class="dokumen-type">
                                    <?= esc($type_label) ?><?= $file_info ?>
                                </div>
                                <div class="dokumen-actions">
                                    <?php if ($is_previewable && $document_url): ?>
                                    <button class="btn-preview" onclick="event.stopPropagation(); previewDokumen('<?= esc($document_url) ?>', '<?= esc($ext) ?>')">
                                        Preview
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($document_url): ?>
                                    <a href="<?= esc($document_url) ?>&amp;download=1" class="btn-download" onclick="event.stopPropagation()">
                                        Download
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php 
                            $file_counter++;
                            endforeach; ?>
                        </div>
                        
                        <!-- Info debug -->
                        <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 5px; font-size: 12px; color: #666;">
                            <strong>Info:</strong> Total <?= count($detail_files) ?> dokumen ditemukan.
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="dokumen-section">
                        <h3>Dokumen yang Diupload</h3>
                        <p style="color: #666; font-style: italic;">Tidak ada dokumen yang ditemukan atau data dokumen kosong.</p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Form Update Status -->
                    <div class="status-form-container">
                        <?php include __DIR__ . '/../user/service_tracking_form.php'; ?>
                        <h3>Ubah Status Pengajuan</h3>
                        <form method="POST" class="status-form">
<?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="expected_status" value="<?= esc($detail_data['status'] ?? '') ?>">
                            <input type="hidden" name="history_version" value="<?= (int)$trackingVersion ?>">
                            <input type="hidden" name="table" value="<?= esc($_GET['table']) ?>">
                            <input type="hidden" name="id" value="<?= $detail_data['id'] ?>">
                            <select name="status" class="status-select" onchange="toggleAlasan(this)">
                                <option value="diajukan" <?= ($detail_data['status'] ?? '') === 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                                <option value="diproses" <?= ($detail_data['status'] ?? '') === 'diproses' ? 'selected' : '' ?>>Diproses</option>
                                <option value="selesai" <?= ($detail_data['status'] ?? '') === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="ditolak" <?= ($detail_data['status'] ?? '') === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                            </select>
                            <textarea name="alasan" rows="3"></textarea>
                            <button type="submit" class="update-btn">Update Status</button>
                        </form>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Halaman Utama (Daftar Pengajuan) -->
                <!-- Wilayah Tugas Info -->
                <div class="wilayah-info">
                    <div class="wilayah-icon">🏥</div>
                    <div class="wilayah-text">
                        <h4>Wilayah Tugas Anda - AHLAK</h4>
                        <p><?= esc($wilayah_tugas) ?></p>
                    </div>
                </div>

                <!-- Statistik Cards -->
                <div class="stats-cards">
                    <div class="stat-card total">
                        <h3>Total Pengajuan</h3>
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
                    <h3>Filter Pengajuan Akta Kelahiran AHLAK</h3>
                    <form method="GET" class="filters">
                        <input type="hidden" name="page" value="1">
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
                        <a href="ahlak_proses_pengajuan.php" class="btn-reset">Reset Filter</a>
                    </form>
                </div>

                <!-- Tabel Pengajuan -->
                <div class="table-section">
                    <h2>
                        Daftar Pengajuan Akta Kelahiran AHLAK untuk Diproses
                        <span class="table-count">Total: <?= $total_submissions ?> data | Halaman <?= $current_page ?> dari <?= $total_pages ?></span>
                    </h2>
                    
                    <?php if (empty($submissions)): ?>
                        <div class="empty-state">
                            <i>📭</i>
                            <h4>Tidak ada pengajuan ditemukan</h4>
                            <p>Tidak ada permohonan Akta Kelahiran AHLAK dari wilayah tugas Anda dengan filter yang dipilih.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Puskesmas</th>
                                    <th>Nama Pemohon</th>
                                    <th>Nama Bayi</th>
                                    <th>Tanggal Lahir</th>
                                    <th>Jenis Kelamin</th>
                                    <th>Status Saat Ini</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tabel-permohonan-konten">
                                <?php 
                                $no = ($current_page - 1) * $per_page + 1;
                                foreach ($submissions as $row): 
                                    $jenis_class = 'jenis-badge';
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <div>
                                                <small>Pengajuan:</small>
                                                <?= !empty($row['created_at']) ? date('d M Y H:i', strtotime($row['created_at'])) : '-' ?><br>
                                                <small>Update:</small>
                                                <?php
                                                $has_update = !empty($row['updated_at']) && !empty($row['created_at']) && strtotime($row['updated_at']) !== strtotime($row['created_at']);
                                                echo $has_update ? date('d M Y H:i', strtotime($row['updated_at'])) : '-';
                                                ?>
                                            </div>
                                        </td>
                                        <td><?= esc($row['nama_puskes'] ?? '-') ?></td>
                                        <td><?= esc($row['nama_user'] ?? '-') ?></td>
                                        <td><strong><?= esc($row['nama_bayi'] ?? '-') ?></strong></td>
                                        <td><?= $row['tanggal_lahir'] ? date('d/m/Y', strtotime($row['tanggal_lahir'])) : '-' ?></td>
                                        <td><?= ($row['jenis_kelamin'] ?? '') === 'L' ? 'Laki-laki' : 'Perempuan' ?></td>
                                        <td>
                                            <?php
                                            $status = strtolower($row['status']);
                                            $badge_class = match($status) {
                                                'selesai', 'disetujui' => 'status-selesai',
                                                'ditolak' => 'status-ditolak',
                                                'diproses' => 'status-diproses',
                                                default => 'status-diajukan'
                                            };
                                            ?>
                                            <span class="status-badge <?= $badge_class ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                            <small style="display:block;margin-top:6px">Status diperbarui: <?= esc(serviceTrackingUpdatedLabel($koneksi, 'tb_akta_kelahiran_ahlak', (int)$row['id'])) ?></small>
                                        </td>
                                        <td>
                                            <a href="?detail_id=<?= $row['id'] ?>&table=tb_akta_kelahiran_ahlak&page=<?= $current_page ?>&status=<?= urlencode($_GET['status'] ?? '') ?>" 
                                               class="btn-detail">Detail</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                Menampilkan <?= count($submissions) ?> dari <?= $total_submissions ?> data
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
                                    <a href="?<?= $next_params ?>" class="pagination-link">Next </a>
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
        <div class="modal-header">
            <h3>Konfirmasi Logout</h3>
            <button class="btn-cancel" onclick="document.getElementById('logoutModal').classList.remove('show')">Tutup</button>
        </div>
        <div class="modal-body">
            <p>Apakah Anda yakin ingin logout dari sistem Operator AHLAK?</p>
            <div class="modal-actions">
                <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
                <button id="cancelLogout" class="btn-cancel">Batal</button>
            </div>
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
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout_pasutri_ahlak.php');
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) hideLogoutModal();
        });
    }
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
    
    // Reset to page 1 when changing filter
    const statusFilter = document.getElementById('status');
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
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
        // Gambar - gunakan path asli
        previewHTML = `<img src="${url}" alt="Preview" style="max-width:100%; height:auto; display:block; margin:auto; box-shadow:0 4px 10px rgba(0,0,0,0.1);">`;
    } else if (ext === 'pdf') {
        // Gunakan Google Docs Viewer dengan URL lengkap
        const baseUrl = window.location.origin;
        const fullUrl = encodeURIComponent(baseUrl + '/' + url.replace(/^\.\.\//, ''));
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
        const wajibAlasan = select.value === 'ditolak';
        textarea.style.display = 'block';
        textarea.required = wajibAlasan;
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

// Tambahkan event listener untuk download button
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('btn-download') || 
        e.target.closest('.btn-download')) {
        // Biarkan default behavior (download file)
        // Tidak perlu melakukan apa-apa khusus
    }
});

// Suara untuk operator saat ada berkas baru masuk
const audioBaru = new Audio('https://siprima.gorontalokab.go.id/assets/new_task.mp3');

function refreshOperatorOtomatis() {
    const areaTabel = document.getElementById('tabel-permohonan-konten');
    if (!areaTabel) return;

    // Hitung berapa banyak status "Diajukan" yang ada saat ini
    const jumlahDiajukanLama = Array.from(areaTabel.querySelectorAll('span'))
                                    .filter(s => s.innerText.trim() === 'Diajukan').length;

    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const kontenBaru = doc.getElementById('tabel-permohonan-konten');

            if (kontenBaru) {
                // Hitung jumlah "Diajukan" di data terbaru
                const jumlahDiajukanBaru = Array.from(kontenBaru.querySelectorAll('span'))
                                                .filter(s => s.innerText.trim() === 'Diajukan').length;

                // Jika jumlah permohonan baru lebih banyak dari sebelumnya
                if (jumlahDiajukanBaru > jumlahDiajukanLama) {
                    audioBaru.play().catch(e => console.log("Izin audio diperlukan"));
                    
                    // Opsional: Tampilkan notifikasi browser
                    alert("Ada " + (jumlahDiajukanBaru - jumlahDiajukanLama) + " permohonan baru masuk!");
                }

                areaTabel.innerHTML = kontenBaru.innerHTML;
            }
        });
}

// Untuk Operator, refresh setiap 1 menit (60000 ms) agar pelayanan lebih sigap
setInterval(refreshOperatorOtomatis, 60000);
</script>
<script src="../assets/js/operator_document_preview.js?v=20260913-1"></script>
</body>
</html>
