<?php
require_once __DIR__ . '/../user/service_tracking.php';
require_once '../error_handler.php';
error_log("=== START RS PROSES PENGAJUAN TERBARU ===");

// Include middleware untuk validasi akses operator rumah sakit
include '../../app/middleware/operator_rs_only.php';
include '../../app/config/koneksi.php';
include '../functions/notifikasi.php';
require_once __DIR__ . '/../user/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_kelahiran = false;
$has_resubmitted_kematian = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kelahiran_rs LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_kelahiran = true;
}
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kematian_rs LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_kematian = true;
}
$created_expr_kelahiran = $has_resubmitted_kelahiran ? 'COALESCE(ak.resubmitted_at, ak.created_at)' : 'ak.created_at';
$created_expr_kematian = $has_resubmitted_kematian ? 'COALESCE(ak.resubmitted_at, ak.created_at)' : 'ak.created_at';

// ============ AMBIL SEMUA RS YANG DITUGASKAN KE OPERATOR ============
$assigned_rs_ids = [];
$assigned_rs_data = [];

// Gunakan data RS dari session yang sudah diverifikasi oleh middleware
if (isset($_SESSION['operator_rs']) && is_array($_SESSION['operator_rs'])) {
    foreach ($_SESSION['operator_rs'] as $rs) {
        $assigned_rs_ids[] = $rs['id_rs'];
        $assigned_rs_data[] = $rs;
    }
    error_log("Using RS data from session: " . count($assigned_rs_ids) . " RS found");
} else {
    // Fallback: Ambil data dari database (untuk kompatibilitas)
    $sql_direct_rs = "SELECT rs_id FROM tb_operator_kecamatan 
                      WHERE operator_id = ? AND rs_id IS NOT NULL";
    $stmt_direct = $koneksi->prepare($sql_direct_rs);
    if ($stmt_direct) {
        $stmt_direct->bind_param("i", $_SESSION['id_user']);
        $stmt_direct->execute();
        $result_direct = $stmt_direct->get_result();
        while ($row = $result_direct->fetch_assoc()) {
            $assigned_rs_ids[] = $row['rs_id'];
        }
        $stmt_direct->close();
    }
    
    // Ambil detail RS
    if (!empty($assigned_rs_ids)) {
        $placeholders = implode(',', array_fill(0, count($assigned_rs_ids), '?'));
        $sql_rs_detail = "SELECT id_rs, nama_rs FROM tb_rumahsakit 
                          WHERE id_rs IN ($placeholders) 
                          ORDER BY nama_rs";
        
        $stmt_detail = $koneksi->prepare($sql_rs_detail);
        if ($stmt_detail) {
            $types = str_repeat('i', count($assigned_rs_ids));
            $bind_params = array_merge([$types], $assigned_rs_ids);
            foreach ($bind_params as $key => $value) {
                if ($key > 0) {
                    $bind_params[$key] = &$bind_params[$key];
                }
            }
            call_user_func_array([$stmt_detail, 'bind_param'], $bind_params);
            $stmt_detail->execute();
            $result_detail = $stmt_detail->get_result();
            
            while ($row = $result_detail->fetch_assoc()) {
                $assigned_rs_data[] = $row;
            }
            $stmt_detail->close();
        }
    }
}

// Tentukan wilayah tugas untuk ditampilkan di header
$wilayah_tugas = '';
$wilayah_tugas_html = ''; // Untuk versi HTML dengan format lebih baik
if (!empty($assigned_rs_data)) {
    $total_rs_assigned = count($assigned_rs_data);
    
    if ($total_rs_assigned === 1) {
        $rs = $assigned_rs_data[0];
        $wilayah_tugas = $rs['nama_rs'];
    } else if ($total_rs_assigned >= 5) {
        // Jika wilayah tugas = 5 rumah sakit atau lebih, tulis semua rumah sakit
        $wilayah_tugas = $total_rs_assigned . ' Rumah Sakit';
        
        // Buat versi HTML dengan pemisah yang lebih baik untuk multiple hospitals
        $rs_names = array_map(function($rs) {
            return $rs['nama_rs'];
        }, $assigned_rs_data);
        
        $wilayah_tugas_html = $total_rs_assigned . ' Rumah Sakit:<br>' . 
                              '<small style="color: #666;">' . 
                              implode(', ', $rs_names) . 
                              '</small>';
    } else {
        $wilayah_tugas = $total_rs_assigned . ' Rumah Sakit';
    }
} else {
    $wilayah_tugas = 'Tidak ada RS ditugaskan';
}

// === PROSES UPDATE STATUS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        serviceTrackingUpdate($koneksi, ['tb_akta_kelahiran_rs', 'tb_akta_kematian_rs'], static fn(array $row): bool => in_array((int)($row['id_rs'] ?? 0), array_map('intval', $assigned_rs_ids), true));
    }
}

// === AMBIL DETAIL JIKA ADA PARAMETER ID ===
$detail_data = null;
$detail_files = [];
$detail_table = '';
$detail_alasan_history = [];

if (isset($_GET['detail_id']) && isset($_GET['table'])) {
    $detail_id = (int)$_GET['detail_id'];
    $detail_table = $_GET['table'];
    
    $valid_tables = ['tb_akta_kelahiran_rs', 'tb_akta_kematian_rs'];
    
    if (in_array($detail_table, $valid_tables) && !empty($assigned_rs_ids)) {
        $placeholders = implode(',', array_fill(0, count($assigned_rs_ids), '?'));
        $detail_created_expr = $detail_table === 'tb_akta_kelahiran_rs' ? $created_expr_kelahiran : $created_expr_kematian;
        $query = "SELECT ak.*, {$detail_created_expr} as effective_created_at 
                  FROM `$detail_table` ak
                  WHERE ak.id = ? AND ak.id_rs IN ($placeholders)";
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            $params = array_merge([$detail_id], $assigned_rs_ids);
            $types = 'i' . str_repeat('i', count($assigned_rs_ids));
            $bind_params = array_merge([$types], $params);
            foreach ($bind_params as $key => $value) {
                if ($key > 0) {
                    $bind_params[$key] = &$bind_params[$key];
                }
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $detail_data = $result->fetch_assoc();
            
            if ($detail_data) {
                $detail_alasan_history = getPermohonanRejectionHistory($koneksi, $detail_table, $detail_id, 15);

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
}

foreach ($detail_files as &$detail_file) {
    $url = siprima_document_token_url(__DIR__, (string)($detail_file['path'] ?? ''));
    $detail_file['path'] = $url ?? '';
}
unset($detail_file);

// === PAGINATION SETTINGS ===
$per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;

// === TAMPILKAN DATA PENGAJUAN TERBARU (jika tidak sedang lihat detail) ===
$submissions = [];
$total_submissions = 0;
$total_pages = 0;

if (!isset($_GET['detail_id'])) {
    $filter_status = $_GET['status'] ?? '';
    $filter_rs = isset($_GET['rs']) ? (int)$_GET['rs'] : 0;
    $filter_type = $_GET['type'] ?? ''; // 'kelahiran' atau 'kematian'
    
    // Filter RS: jika 0 atau tidak dalam daftar RS operator, gunakan semua RS operator
    if ($filter_rs <= 0 || !in_array($filter_rs, $assigned_rs_ids)) {
        $filter_rs = 0;
    }

    // Hitung total data dari kedua tabel
    if (!empty($assigned_rs_ids)) {
        $total_counts = [];
        
        // Hitung untuk akta kelahiran
        $placeholders = implode(',', array_fill(0, count($assigned_rs_ids), '?'));
        $query_count_kelahiran = "SELECT COUNT(*) as total FROM tb_akta_kelahiran_rs WHERE id_rs IN ($placeholders)";
        $params_count = $assigned_rs_ids;
        
        if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
            $query_count_kelahiran .= " AND status = ?";
            $params_count[] = $filter_status;
        }
        
        if ($filter_rs > 0) {
            $query_count_kelahiran .= " AND id_rs = ?";
            $params_count[] = $filter_rs;
        }
        
        $stmt_count = $koneksi->prepare($query_count_kelahiran);
        if ($stmt_count) {
            $types = str_repeat('i', count($assigned_rs_ids));
            if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
                $types .= 's';
            }
            if ($filter_rs > 0) {
                $types .= 'i';
            }
            $bind_params = array_merge([$types], $params_count);
            foreach ($bind_params as $key => $value) {
                if ($key > 0) {
                    $bind_params[$key] = &$bind_params[$key];
                }
            }
            call_user_func_array([$stmt_count, 'bind_param'], $bind_params);
            $stmt_count->execute();
            $result_count = $stmt_count->get_result();
            $row_count = $result_count->fetch_assoc();
            $total_counts['kelahiran'] = (int)($row_count['total'] ?? 0);
            $stmt_count->close();
        }
        
        // Reset params untuk kematian
        $params_count = $assigned_rs_ids;
        
        // Hitung untuk akta kematian
        $query_count_kematian = "SELECT COUNT(*) as total FROM tb_akta_kematian_rs WHERE id_rs IN ($placeholders)";
        
        if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
            $query_count_kematian .= " AND status = ?";
            $params_count[] = $filter_status;
        }
        
        if ($filter_rs > 0) {
            $query_count_kematian .= " AND id_rs = ?";
            $params_count[] = $filter_rs;
        }
        
        $stmt_count = $koneksi->prepare($query_count_kematian);
        if ($stmt_count) {
            $types = str_repeat('i', count($assigned_rs_ids));
            if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
                $types .= 's';
            }
            if ($filter_rs > 0) {
                $types .= 'i';
            }
            $bind_params = array_merge([$types], $params_count);
            foreach ($bind_params as $key => $value) {
                if ($key > 0) {
                    $bind_params[$key] = &$bind_params[$key];
                }
            }
            call_user_func_array([$stmt_count, 'bind_param'], $bind_params);
            $stmt_count->execute();
            $result_count = $stmt_count->get_result();
            $row_count = $result_count->fetch_assoc();
            $total_counts['kematian'] = (int)($row_count['total'] ?? 0);
            $stmt_count->close();
        }
        
        // Total semua pengajuan
        $total_submissions = ($total_counts['kelahiran'] ?? 0) + ($total_counts['kematian'] ?? 0);
    }
    
    // Hitung total halaman
    $total_pages = ceil($total_submissions / $per_page);
    
    // Batasi current_page agar tidak melebihi total_pages
    if ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
        $offset = ($current_page - 1) * $per_page;
    }
    
    // Ambil data gabungan dari kedua tabel
    if ($total_submissions > 0 && !empty($assigned_rs_ids)) {
        // Sederhanakan query - ambil semua data tanpa filter kompleks
        $placeholders = implode(',', array_fill(0, count($assigned_rs_ids), '?'));
        
        // Buat base query
        $query_kelahiran = "SELECT 
            'kelahiran' as jenis_layanan,
            ak.id,
            ak.id_user,
            ak.id_rs,
            ak.nama_pemohon,
            ak.nik_pemohon,
            ak.nama_bayi as nama_subjek,
            ak.nama_ayah,
            ak.nama_ibu,
            ak.tanggal_lahir as tanggal_peristiwa,
            ak.Waktu as waktu_peristiwa,
            ak.jenis_kelamin,
            ak.status,
            {$created_expr_kelahiran} as created_at,
            ak.updated_at,
            ak.keterangan
            FROM tb_akta_kelahiran_rs ak
            WHERE ak.id_rs IN ($placeholders)";
        
        $query_kematian = "SELECT 
            'kematian' as jenis_layanan,
            ak.id,
            ak.id_user,
            ak.id_rs,
            ak.nama_pemohon,
            ak.nik_pemohon,
            ak.nama_meninggal as nama_subjek,
            ak.nama_ayah,
            ak.nama_ibu,
            ak.tanggal_kematian as tanggal_peristiwa,
            '' as waktu_peristiwa,
            '' as jenis_kelamin,
            ak.status,
            {$created_expr_kematian} as created_at,
            ak.updated_at,
            ak.keterangan
            FROM tb_akta_kematian_rs ak
            WHERE ak.id_rs IN ($placeholders)";
        
        // Parameter dasar: RS IDs
        $params = $assigned_rs_ids;
        
        // Tambahkan filter status jika ada
        $where_conditions = [];
        if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
            $where_conditions[] = "status = ?";
            $params[] = $filter_status;
        }
        
        // Tambahkan filter RS spesifik jika ada
        if ($filter_rs > 0) {
            $where_conditions[] = "id_rs = ?";
            $params[] = $filter_rs;
        }
        
        // Gabungkan kondisi WHERE jika ada
        if (!empty($where_conditions)) {
            $where_clause = " AND " . implode(" AND ", $where_conditions);
            $query_kelahiran .= $where_clause;
            $query_kematian .= $where_clause;
        }
        
        // Gabungkan query dengan UNION dan limit
        $query = "($query_kelahiran) UNION ALL ($query_kematian) 
                  ORDER BY created_at DESC 
                  LIMIT ? OFFSET ?";
        
        // Tambahkan parameter limit dan offset
        // Build params array WITHOUT limit/offset yet
        // $params already contains: rs_ids, filter_status (if set), filter_rs (if set)
        
        // IMPORTANT: UNION uses parameters TWICE (once for each subquery)
        // So we need to duplicate the params for the two subqueries
        $union_params = array_merge($params, $params);
        // Then add limit/offset ONCE at the end
        $union_params[] = $per_page;
        $union_params[] = $offset;
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            // Build types string - we need types for BOTH subqueries in UNION
            $single_types = str_repeat('i', count($assigned_rs_ids));
            if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
                $single_types .= 's';
            }
            if ($filter_rs > 0) {
                $single_types .= 'i';
            }
            // Double the types for UNION (one set for each subquery) + 'ii' for LIMIT/OFFSET
            $types = $single_types . $single_types . 'ii';
            
            $bind_params = array_merge([$types], $union_params);
            foreach ($bind_params as $key => $value) {
                if ($key > 0) {
                    $bind_params[$key] = &$bind_params[$key];
                }
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                // Tambahkan nama RS untuk tampilan
                $rs_id = $row['id_rs'];
                $nama_rs = 'RS ' . $rs_id;
                foreach ($assigned_rs_data as $rs) {
                    if ($rs['id_rs'] == $rs_id) {
                            $nama_rs = $rs['nama_rs'];
                            break;
                        }
                    }
                    $row['nama_rs'] = $nama_rs;
                    $submissions[] = $row;
                }
            $stmt->close();
        } else {
            error_log("Prepare statement error: " . $koneksi->error);
        }
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

// Flash messages
$flash_success = $_SESSION['success_message'] ?? null;
$flash_error = $_SESSION['error_message'] ?? null;

// Clear flash messages setelah diambil
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Hitung statistik untuk header
$stats = [
    'total' => 0,
    'diajukan' => 0,
    'diproses' => 0,
    'selesai' => 0,
    'ditolak' => 0
];

if (!empty($assigned_rs_ids)) {
    $placeholders = implode(',', array_fill(0, count($assigned_rs_ids), '?'));
    
    // Hitung statistik untuk akta kelahiran
    $query_stats_kelahiran = "SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
    FROM tb_akta_kelahiran_rs WHERE id_rs IN ($placeholders)";
    
    $stmt_stats = $koneksi->prepare($query_stats_kelahiran);
    if ($stmt_stats) {
        $types = str_repeat('i', count($assigned_rs_ids));
        $bind_params = array_merge([$types], $assigned_rs_ids);
        foreach ($bind_params as $key => $value) {
            if ($key > 0) {
                $bind_params[$key] = &$bind_params[$key];
            }
        }
        call_user_func_array([$stmt_stats, 'bind_param'], $bind_params);
        $stmt_stats->execute();
        $result_stats = $stmt_stats->get_result();
        
        if ($row = $result_stats->fetch_assoc()) {
            $stats['total'] += (int)($row['total'] ?? 0);
            $stats['diajukan'] += (int)($row['diajukan'] ?? 0);
            $stats['diproses'] += (int)($row['diproses'] ?? 0);
            $stats['selesai'] += (int)($row['selesai'] ?? 0);
            $stats['ditolak'] += (int)($row['ditolak'] ?? 0);
        }
        $stmt_stats->close();
    }
    
    // Hitung statistik untuk akta kematian
    $query_stats_kematian = "SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
    FROM tb_akta_kematian_rs WHERE id_rs IN ($placeholders)";
    
    $stmt_stats = $koneksi->prepare($query_stats_kematian);
    if ($stmt_stats) {
        $types = str_repeat('i', count($assigned_rs_ids));
        $bind_params = array_merge([$types], $assigned_rs_ids);
        foreach ($bind_params as $key => $value) {
            if ($key > 0) {
                $bind_params[$key] = &$bind_params[$key];
            }
        }
        call_user_func_array([$stmt_stats, 'bind_param'], $bind_params);
        $stmt_stats->execute();
        $result_stats = $stmt_stats->get_result();
        
        if ($row = $result_stats->fetch_assoc()) {
            $stats['total'] += (int)($row['total'] ?? 0);
            $stats['diajukan'] += (int)($row['diajukan'] ?? 0);
            $stats['diproses'] += (int)($row['diproses'] ?? 0);
            $stats['selesai'] += (int)($row['selesai'] ?? 0);
            $stats['ditolak'] += (int)($row['ditolak'] ?? 0);
        }
        $stmt_stats->close();
    }
}

error_log("=== END RS PROSES PENGAJUAN TERBARU ===");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($_GET['detail_id']) ? 'Detail Pengajuan' : 'Proses Pengajuan Terbaru' ?> - Operator RS</title>
    <link rel="icon" href="../assets/st sehati.png" type="image/png">
    <link rel="stylesheet" href="css/rs_proses_pengajuan.css">
    <link rel="stylesheet" href="../assets/css/operator_document_preview.css?v=20260824-1">
        
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="../assets/js/service-tracking.js?v=20260908-1"></script>
    <link rel="stylesheet" href="../assets/css/service-timeline.css?v=20260908-2">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator Dukcapil <br> ST SEHATI</div>
        <nav>
            <ul>
                <li><a href="rs_dashboard_operator.php">Dashboard</a></li>
                <li class="active"><a href="rs_proses_pengajuan.php">Proses Pengajuan</a></li>
                <li><a href="rs_riwayat_operator.php">Riwayat</a></li>
                <li><a href="rs_dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">
                    <?= isset($_GET['detail_id']) ? 'Detail Pengajuan' : 'Proses Pengajuan Terbaru' ?>
                </div>
                <div class="subtitle">
                    Wilayah Tugas: 
                    <?php if (!empty($wilayah_tugas_html)): ?>
                        <?= $wilayah_tugas_html ?>
                    <?php else: ?>
                        <?= esc($wilayah_tugas) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama']) ?></span>
                <span class="role-badge">OPERATOR RS</span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Flash Message Toast -->
            <?php if ($flash_success): ?>
                <div class="toast toast-success">
                    <?= esc($flash_success) ?>
                </div>
                <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="toast toast-error">
                    <?= esc($flash_error) ?>
                </div>
                <script>setTimeout(() => document.querySelector('.toast').remove(), 6000);</script>
            <?php endif; ?>

            <?php if (isset($_GET['detail_id']) && $detail_data): ?>
                <!-- Halaman Detail Pengajuan -->
                <a href="rs_proses_pengajuan.php?page=<?= $current_page ?>&status=<?= urlencode($_GET['status'] ?? '') ?>&rs=<?= urlencode($_GET['rs'] ?? '') ?>&type=<?= urlencode($_GET['type'] ?? '') ?>" class="btn-back">
                    <i>←</i> Kembali ke Daftar Pengajuan
                </a>
                
                <div class="detail-section">
                    <div class="detail-header">
                        <h1 class="detail-title">
                            Detail <?= $detail_table === 'tb_akta_kelahiran_rs' ? 'Akta Kelahiran' : 'Akta Kematian' ?>
                            <span class="jenis-badge"><?= $detail_table === 'tb_akta_kelahiran_rs' ? 'Kelahiran' : 'Kematian' ?></span>
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
                                <div class="detail-value"><?= esc($detail_data['nama_pemohon'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">NIK Pemohon</div>
                                <div class="detail-value"><?= esc($detail_data['nik_pemohon'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">No HP</div>
                                <div class="detail-value"><?= esc($detail_data['no_hp'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?= esc($detail_data['email'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Tanggal Pengajuan</div>
                                <div class="detail-value"><?= formatDate($detail_data['effective_created_at'] ?? ($detail_data['created_at'] ?? '')) ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Lokasi -->
                        <div class="detail-card">
                            <h3>Informasi Lokasi</h3>
                            <div class="detail-row">
                                <div class="detail-label">Kecamatan</div>
                                <div class="detail-value"><?= esc($detail_data['nama_kecamatan'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Desa</div>
                                <div class="detail-value"><?= esc($detail_data['nama_desa'] ?? '-') ?></div>
                            </div>
                            <?php if (!empty($detail_data['dusun'])): ?>
                            <div class="detail-row">
                                <div class="detail-label">Dusun/Alamat</div>
                                <div class="detail-value"><?= esc($detail_data['dusun'] ?? '-') ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Informasi Subjek (Bayi untuk kelahiran, Meninggal untuk kematian) -->
                        <div class="detail-card">
                            <h3>Informasi <?= $detail_table === 'tb_akta_kelahiran_rs' ? 'Bayi' : 'Yang Meninggal' ?></h3>
                            <div class="detail-row">
                                <div class="detail-label">Nama</div>
                                <div class="detail-value">
                                    <?= $detail_table === 'tb_akta_kelahiran_rs' ? esc($detail_data['nama_bayi'] ?? '-') : esc($detail_data['nama_meninggal'] ?? '-') ?>
                                </div>
                            </div>
                            <?php if ($detail_table === 'tb_akta_kelahiran_rs'): ?>
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
                                    <div class="detail-label">Jenis Kelamin</div>
                                    <div class="detail-value"><?= esc($detail_data['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Anak ke-</div>
                                    <div class="detail-value"><?= esc($detail_data['urutan_kelahiran'] ?? '-') ?></div>
                                </div>
                            <?php else: ?>
                                <div class="detail-row">
                                    <div class="detail-label">NIK</div>
                                    <div class="detail-value"><?= esc($detail_data['nik_meninggal'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Tanggal Kematian</div>
                                    <div class="detail-value"><?= formatDate($detail_data['tanggal_kematian'] ?? '') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Tempat Kematian</div>
                                    <div class="detail-value"><?= esc($detail_data['tempat'] ?? '-') ?></div>
                                </div>
                            <?php endif; ?>
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
                        <div class="detail-card">
                            <h3>Informasi Saksi</h3>
                            <div class="detail-row">
                                <div class="detail-label">Saksi 1</div>
                                <div class="detail-value"><?= esc($detail_data['nama_saksi1'] ?? '-') ?> (NIK: <?= esc($detail_data['nik_saksi1'] ?? '-') ?>)</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Saksi 2</div>
                                <div class="detail-value"><?= esc($detail_data['nama_saksi2'] ?? '-') ?> (NIK: <?= esc($detail_data['nik_saksi2'] ?? '-') ?>)</div>
                            </div>
                        </div>
                        
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
                            <?php foreach ($detail_files as $index => $file): 
                                $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
                                $is_previewable = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif']);
                                $icon = match($ext) {
                                    'pdf' => '📄',
                                    'jpg', 'jpeg', 'png', 'gif' => '🖼️',
                                    default => '📎'
                                };
                                
                                // Tentukan label berdasarkan tipe dokumen
                                $type_label = 'Dokumen';
                                if ($detail_table === 'tb_akta_kelahiran_rs') {
                                    $type_label = match($file['type'] ?? 'lainnya') {
                                        'surat_keterangan_rs' => 'Surat Keterangan Lahir RS',
                                        'formulir' => 'Formulir F-2.01 Kelahiran',
                                        'buku_nikah' => 'Buku Nikah/Akta Kawin',
                                        'kk' => 'Kartu Keluarga',
                                        'ktp_ortu' => 'KTP Orang Tua',
                                        default => ucfirst($file['type'] ?? 'Dokumen')
                                    };
                                } else {
                                    $type_label = match($file['type'] ?? 'lainnya') {
                                        'surat_keterangan_kematian_rs' => 'Surat Keterangan Kematian RS',
                                        'formulir_f201_kematian' => 'Formulir F-2.01 Kematian',
                                        'identitas_meninggal' => 'KK/KTP yang Meninggal',
                                        'kk' => 'Kartu Keluarga',
                                        'ktp_ayah' => 'KTP Ayah',
                                        'ktp_ibu' => 'KTP Ibu',
                                        default => ucfirst($file['type'] ?? 'Dokumen')
                                    };
                                }
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
                        <?php include __DIR__ . '/../user/service_tracking_form.php'; ?>
                        <h3>Ubah Status Pengajuan</h3>
                        <form method="POST" class="status-form">
<?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="expected_status" value="<?= esc($detail_data['status'] ?? '') ?>">
                            <input type="hidden" name="history_version" value="<?= (int)$trackingVersion ?>">
                            <input type="hidden" name="table" value="<?= esc($detail_table) ?>">
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
                <!-- Halaman Utama (Daftar Pengajuan Terbaru) -->
                
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
                        <h3>Diproses</h3>
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
                    <form method="GET" class="filters">
                        <input type="hidden" name="page" value="1">
                        <?php if (count($assigned_rs_data) > 1): ?>
                        <div class="filter-group">
                            <label for="rs">Rumah Sakit</label>
                            <select name="rs" id="rs">
                                <option value="">Semua Rumah Sakit</option>
                                <?php foreach ($assigned_rs_data as $rs): ?>
                                    <option value="<?= $rs['id_rs'] ?>" <?= ($_GET['rs'] ?? '') == $rs['id_rs'] ? 'selected' : '' ?>>
                                        <?= esc($rs['nama_rs']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
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
                        
                        <div class="filter-group">
                            <label for="type">Jenis Layanan</label>
                            <select name="type" id="type">
                                <option value="">Semua Layanan</option>
                                <option value="kelahiran" <?= ($_GET['type'] ?? '') === 'kelahiran' ? 'selected' : '' ?>>Akta Kelahiran</option>
                                <option value="kematian" <?= ($_GET['type'] ?? '') === 'kematian' ? 'selected' : '' ?>>Akta Kematian</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn-filter">Terapkan Filter</button>
                        <a href="rs_proses_pengajuan.php" class="btn-reset">Reset Filter</a>
                    </form>
                </div>

                <!-- Tabel Pengajuan -->
                <div class="table-section">
                    <h2>
                        Daftar Pengajuan Terbaru
                        
                        <span class="table-count">Total: <?= $total_submissions ?> data | Halaman <?= $current_page ?> dari <?= $total_pages ?></span>
                    </h2>
                    
                    <?php if (empty($submissions)): ?>
                        <div class="empty-state">
                            <i>📋</i>
                            <h4>Tidak ada pengajuan ditemukan</h4>
                            <p>Tidak ada permohonan di rumah sakit tugas Anda dengan filter yang dipilih.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal Pengajuan</th>                                    
                                    <th>Nama Pemohon</th>
                                    <th>Jenis Layanan</th>
                                    <th>Nama Lahir/Mati</th>
                                    <?php if ($filter_type !== 'kematian'): ?>
                                                                        <?php endif; ?>
                                    <th>Tgl Lahir/Mati</th>
                                    <?php if ($filter_type !== 'kematian'): ?>
                                        <th>Waktu</th>
                                    <?php endif; ?>
                                    <th>Status Saat Ini</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tabel-permohonan-konten">
                                <?php 
                                $no = ($current_page - 1) * $per_page + 1;
                                foreach ($submissions as $row): 
                                    // Tentukan tabel berdasarkan jenis layanan
                                    $table_name = $row['jenis_layanan'] === 'kelahiran' ? 'tb_akta_kelahiran_rs' : 'tb_akta_kematian_rs';
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
                                        <td><?= esc($row['nama_pemohon'] ?? '-') ?></td>
                                       <td>
                                            <span class="layanan-badge <?= $row['jenis_layanan'] === 'kelahiran' ? 'badge-kelahiran' : 'badge-kematian' ?>">
                                                <?= $row['jenis_layanan'] === 'kelahiran' ? 'Kelahiran' : 'Kematian' ?>
                                            </span>
                                        </td>
                                        <td><?= esc($row['nama_subjek'] ?? '-') ?></td>
                                        <?php if ($filter_type !== 'kematian'): ?>
                                        <?php endif; ?>
                                        <td><?= !empty($row['tanggal_peristiwa']) ? date('d M Y', strtotime($row['tanggal_peristiwa'])) : '-' ?></td>
                                        <?php if ($filter_type !== 'kematian'): ?>
                                            <td><?= esc($row['waktu_peristiwa'] ?? '-') ?></td>
                                        <?php endif; ?>
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
                                            <small style="display:block;margin-top:6px">Status diperbarui: <?= esc(serviceTrackingUpdatedLabel($koneksi, $table_name, (int)$row['id'])) ?></small>
                                        </td>
                                        <td>
                                            <a href="?detail_id=<?= $row['id'] ?>&table=<?= $table_name ?>&page=<?= $current_page ?>&status=<?= urlencode($_GET['status'] ?? '') ?>&rs=<?= urlencode($_GET['rs'] ?? '') ?>&type=<?= urlencode($_GET['type'] ?? '') ?>" class="btn-detail">Detail</a>
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
    const filterElements = ['status', 'rs', 'type'];
    filterElements.forEach(filterId => {
        const element = document.getElementById(filterId);
        if (element) {
            element.addEventListener('change', function() {
                document.querySelector('input[name="page"]').value = 1;
            });
        }
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
