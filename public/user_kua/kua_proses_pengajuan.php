<?php
require_once __DIR__ . '/../user/service_tracking.php';
require_once '../error_handler.php';
// Gunakan middleware khusus operator KUA
include '../../app/middleware/operator_only_kua.php';

error_log("=== START KUA PROSES PENGAJUAN (OPERATOR) ===");
error_log('Request: ' . siprima_request_id());
error_log("Role: " . $_SESSION['role']);
error_log("User ID: " . $_SESSION['id_user']);
error_log("KUA IDs: " . (isset($_SESSION['kua_tugas']) ? implode(', ', $_SESSION['kua_tugas']) : 'NOT SET'));

include '../../app/config/koneksi.php';
include '../functions/notifikasi.php';
require_once __DIR__ . '/../user/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';
$csrf_token_value = function_exists('csrf_token') ? csrf_token() : '';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_kartukeluarga_kua LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}
$created_expr = $has_resubmitted_at ? 'COALESCE(kk.resubmitted_at, kk.created_at)' : 'kk.created_at';

// ============ AMBIL SEMUA KUA YANG DITUGASKAN KE OPERATOR ============
$assigned_kua_ids = [];
$assigned_kua_data = [];
$kua_list_for_display = '';
$total_kua_system = 0;

// 1. Ambil dari session kua_tugas (jika sudah ada)
if (isset($_SESSION['kua_tugas']) && !empty($_SESSION['kua_tugas'])) {
    $assigned_kua_ids = $_SESSION['kua_tugas'];
    error_log("Using kua_tugas from session: " . count($assigned_kua_ids) . " KUA");
}

// 2. Jika tidak ada di session, ambil dari database tb_operator_kecamatan atau tabel yang sesuai
if (empty($assigned_kua_ids)) {
    $sql = "SELECT kua_id FROM tb_operator_kecamatan 
            WHERE operator_id = ? AND kua_id IS NOT NULL
            ORDER BY kua_id";
    $stmt = $koneksi->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['id_user']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['kua_id'])) {
                $assigned_kua_ids[] = $row['kua_id'];
            }
        }
        $stmt->close();
        error_log("Found " . count($assigned_kua_ids) . " KUA from database");
        
        // Jika tidak ada, coba ambil dari id_kua di session
        if (empty($assigned_kua_ids) && isset($_SESSION['id_kua']) && !empty($_SESSION['id_kua'])) {
            $assigned_kua_ids = [$_SESSION['id_kua']];
            error_log("Using id_kua from session: " . $_SESSION['id_kua']);
        }
        
        // Simpan ke session untuk penggunaan berikutnya
        $_SESSION['kua_tugas'] = $assigned_kua_ids;
    }
}

// Hitung total KUA yang ada di sistem
$sql_total_kua = "SELECT COUNT(*) as total_kua FROM tb_kua";
$result_total = $koneksi->query($sql_total_kua);
if ($result_total && $row = $result_total->fetch_assoc()) {
    $total_kua_system = (int)$row['total_kua'];
}

// Ambil detail data untuk semua KUA yang ditugaskan
if (!empty($assigned_kua_ids)) {
    $placeholders = implode(',', array_fill(0, count($assigned_kua_ids), '?'));
    $sql_kua_detail = "SELECT k.*, kec.nama_kecamatan 
                      FROM tb_kua k 
                      LEFT JOIN tb_kecamatan kec ON k.id_kecamatan = kec.id 
                      WHERE k.id_kua IN ($placeholders) 
                      ORDER BY k.nama_kua";
    
    $stmt_detail = $koneksi->prepare($sql_kua_detail);
    if ($stmt_detail) {
        $types = str_repeat('i', count($assigned_kua_ids));
        $stmt_detail->bind_param($types, ...$assigned_kua_ids);
        $stmt_detail->execute();
        $result_detail = $stmt_detail->get_result();
        
        while ($row = $result_detail->fetch_assoc()) {
            $assigned_kua_data[] = $row;
        }
        $stmt_detail->close();
        
        // Logika tampilan berdasarkan jumlah KUA
        $total_kua_assigned = count($assigned_kua_data);
        
        // CEK: Jika jumlah KUA yang ditugaskan = 19 atau >= total KUA sistem
        if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system) {
            $kua_list_for_display = "Semua KUA";
            $wilayah_tugas = "Semua KUA";
        } 
        // Jika jumlah KUA yang ditugaskan < 5, tampilkan nama-nama KUA
        elseif ($total_kua_assigned < 5) {
            $kua_names = array_map(function($kua) {
                return $kua['nama_kua'] . ' (' . ($kua['nama_kecamatan'] ?? '-') . ')';
            }, $assigned_kua_data);
            
            $kua_list_for_display = implode(', ', $kua_names);
            $wilayah_tugas = $total_kua_assigned . " KUA: " . $kua_list_for_display;
        }
        // Jika 5 <= jumlah KUA < 19, tampilkan jumlahnya saja
        else {
            $kua_list_for_display = $total_kua_assigned . " KUA";
            $wilayah_tugas = $total_kua_assigned . " KUA";
        }
        
        // Update session dengan nama KUA
        if (!isset($_SESSION['kua_nama']) || empty($_SESSION['kua_nama'])) {
            $_SESSION['kua_nama'] = array_column($assigned_kua_data, 'nama_kua');
        }
        
        // Set KUA pertama sebagai default jika session id_kua belum ada
        if (!isset($_SESSION['id_kua']) && !empty($assigned_kua_data)) {
            $_SESSION['id_kua'] = $assigned_kua_data[0]['id_kua'];
            $_SESSION['nama_kua'] = $assigned_kua_data[0]['nama_kua'];
        }
    }
} else {
    $wilayah_tugas = 'Tidak ada KUA ditugaskan';
    error_log("Operator ID " . $_SESSION['id_user'] . " tidak memiliki KUA terkait");
}

// === PROSES UPDATE STATUS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        serviceTrackingUpdate($koneksi, ['tb_kartukeluarga_kua'], static fn(array $row): bool => in_array((int)($row['id_kua'] ?? 0), array_map('intval', $assigned_kua_ids), true));
    }
}

// === AMBIL DETAIL JIKA ADA PARAMETER ID ===
$detail_data = null;
$detail_files = [];
$detail_alasan_history = [];

if (isset($_GET['detail_id'])) {
    $detail_id = (int)$_GET['detail_id'];
    
    if (!empty($assigned_kua_ids)) {
        $placeholders = implode(',', array_fill(0, count($assigned_kua_ids), '?'));
        $query = "SELECT kk.*, 
                         {$created_expr} as effective_created_at,
                         u.nama as nama_user_lengkap, 
                         u.nik as nik_user, 
                         u.no_hp as no_hp_user, 
                         u.email as email_user,
                         k.nama_kua, 
                         kec.nama_kecamatan,
                         d.nama_desa
                  FROM tb_kartukeluarga_kua kk
                  LEFT JOIN tb_users u ON kk.id_user = u.id
                  LEFT JOIN tb_kua k ON kk.id_kua = k.id_kua
                  LEFT JOIN tb_kecamatan kec ON k.id_kecamatan = kec.id
                  LEFT JOIN tb_desa d ON kk.id_desa = d.id
                  WHERE kk.id = ? AND kk.id_kua IN ($placeholders)";
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            $types = 'i' . str_repeat('i', count($assigned_kua_ids));
            $params = array_merge([$detail_id], $assigned_kua_ids);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $detail_data = $result->fetch_assoc();
            
            if ($detail_data) {
                $detail_alasan_history = getPermohonanRejectionHistory($koneksi, 'tb_kartukeluarga_kua', $detail_id, 15);

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

// === TAMPILKAN DATA PENGAJUAN (jika tidak sedang lihat detail) ===
$submissions = [];
$total_submissions = 0;
$total_pages = 0;

if (!isset($_GET['detail_id'])) {
    $filter_status = in_array($_GET['status'] ?? '', ['diajukan', 'diproses', 'selesai', 'ditolak'], true) ? $_GET['status'] : '';
    $search_nama = trim((string)($_GET['search_nama'] ?? ''));

    if (!empty($assigned_kua_ids)) {
        $placeholders = implode(',', array_fill(0, count($assigned_kua_ids), '?'));
        $base_from = " FROM tb_kartukeluarga_kua kk
                       LEFT JOIN tb_users u ON kk.id_user = u.id
                       LEFT JOIN tb_kua k ON kk.id_kua = k.id_kua
                       LEFT JOIN tb_desa d ON kk.id_desa = d.id
                       WHERE kk.id_kua IN ($placeholders)";
        $where = '';
        $params_filter = $assigned_kua_ids;
        $types_filter = str_repeat('i', count($assigned_kua_ids));

        if ($filter_status !== '') {
            $where .= " AND kk.status = ?";
            $params_filter[] = $filter_status;
            $types_filter .= 's';
        }
        if ($search_nama !== '') {
            $where .= " AND (kk.nama_pemohon LIKE ? OR u.nama LIKE ? OR kk.nama_kepalaKeluarga LIKE ? OR kk.nik_pemohon LIKE ? OR k.nama_kua LIKE ? OR d.nama_desa LIKE ?)";
            $like_search = '%' . $search_nama . '%';
            for ($i = 0; $i < 6; $i++) $params_filter[] = $like_search;
            $types_filter .= 'ssssss';
        }

        $stmt_count = $koneksi->prepare("SELECT COUNT(*) AS total" . $base_from . $where);
        if ($stmt_count) {
            $stmt_count->bind_param($types_filter, ...$params_filter);
            $stmt_count->execute();
            $total_submissions = (int)($stmt_count->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt_count->close();
        }
    }
    
    // Hitung total halaman
    $total_pages = ceil($total_submissions / $per_page);
    
    // Batasi current_page agar tidak melebihi total_pages
    if ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
        $offset = ($current_page - 1) * $per_page;
    }
    
    // Ambil data dengan kondisi yang sama seperti perhitungan pagination.
    if ($total_submissions > 0 && !empty($assigned_kua_ids)) {
        $params = array_merge($params_filter, [$per_page, $offset]);
        $types = $types_filter . 'ii';
        $query = "SELECT kk.*, {$created_expr} AS effective_created_at, u.nama AS nama_user_lengkap, k.nama_kua, d.nama_desa" . $base_from . $where . " ORDER BY {$created_expr} DESC LIMIT ? OFFSET ?";
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['created_at'] = $row['effective_created_at'] ?? ($row['created_at'] ?? null);
                $row['jenis'] = 'Kartu Keluarga KUA';
                $row['table_name'] = 'tb_kartukeluarga_kua';
                $row['title'] = $row['nama_kepalaKeluarga'] ?? 'Kartu Keluarga';
                $row['nama_user'] = $row['nama_user_lengkap'] ?? $row['nama_pemohon'] ?? '-';
                $submissions[] = $row;
            }
            $stmt->close();
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

// Fungsi untuk format ukuran file
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

// Fungsi untuk menampilkan anggota keluarga
function displayAnggotaKeluarga($anggotaJson) {
    if (empty($anggotaJson)) {
        return '<div class="anggota-empty">Tidak ada data anggota keluarga</div>';
    }
    
    try {
        $anggota = json_decode($anggotaJson, true);
        if (!is_array($anggota) || empty($anggota)) {
            return '<div class="anggota-empty">Tidak ada data anggota keluarga</div>';
        }
        
        $html = '<div class="anggota-table-container">';
        $html .= '<table class="anggota-table">';
        $html .= '<thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Lengkap</th>
                        <th>NIK</th>                        
                        <th>Hubungan</th>
                    </tr>
                  </thead>';
        $html .= '<tbody>';
        
        foreach ($anggota as $index => $a) {
            $html .= '<tr>';
            $html .= '<td class="text-center">' . ($index + 1) . '</td>';
            $html .= '<td>' . esc($a['nama'] ?? '-') . '</td>';
            $html .= '<td>' . esc($a['nik'] ?? '-') . '</td>';
            $html .= '<td>' . esc($a['hubungan'] ?? '-') . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '<div class="anggota-count">Total: ' . count($anggota) . ' anggota</div>';
        $html .= '</div>';
        
        return $html;
    } catch (Exception $e) {
        return '<div class="anggota-error">Error parsing data anggota keluarga</div>';
    }
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

if (!empty($assigned_kua_ids)) {
    // Jika operator mengelola semua KUA
    if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system) {
        $query_stats = "SELECT 
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
                        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
                        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
                      FROM tb_kartukeluarga_kua 
                      WHERE id_kua IS NOT NULL";
        
        $stmt_stats = $koneksi->prepare($query_stats);
        if ($stmt_stats) {
            $stmt_stats->execute();
            $result_stats = $stmt_stats->get_result();
            
            if ($row = $result_stats->fetch_assoc()) {
                $stats['total'] = (int)($row['total'] ?? 0);
                $stats['diajukan'] = (int)($row['diajukan'] ?? 0);
                $stats['diproses'] = (int)($row['diproses'] ?? 0);
                $stats['selesai'] = (int)($row['selesai'] ?? 0);
                $stats['ditolak'] = (int)($row['ditolak'] ?? 0);
            }
            $stmt_stats->close();
        }
    } else {
        // Jika operator mengelola beberapa KUA tertentu
        $placeholders = implode(',', array_fill(0, count($assigned_kua_ids), '?'));
        $query_stats = "SELECT 
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
                        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
                        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
                      FROM tb_kartukeluarga_kua WHERE id_kua IN ($placeholders)";
        
        $stmt_stats = $koneksi->prepare($query_stats);
        if ($stmt_stats) {
            $types = str_repeat('i', count($assigned_kua_ids));
            $stmt_stats->bind_param($types, ...$assigned_kua_ids);
            $stmt_stats->execute();
            $result_stats = $stmt_stats->get_result();
            
            if ($row = $result_stats->fetch_assoc()) {
                $stats['total'] = (int)($row['total'] ?? 0);
                $stats['diajukan'] = (int)($row['diajukan'] ?? 0);
                $stats['diproses'] = (int)($row['diproses'] ?? 0);
                $stats['selesai'] = (int)($row['selesai'] ?? 0);
                $stats['ditolak'] = (int)($row['ditolak'] ?? 0);
            }
            $stmt_stats->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= esc($csrf_token_value) ?>">
    <title><?= isset($_GET['detail_id']) ? 'Detail Pengajuan' : 'Proses Pengajuan' ?> - Operator PASUTRI</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/kua_proses_pengajuan.css">
    <link rel="stylesheet" href="../assets/css/operator_document_preview.css?v=20260824-1">
    <link rel="icon" type="image/png" href="../assets/PASUTRI.png">
    
    <script src="../assets/js/service-tracking.js?v=20260908-1"></script>
    <link rel="stylesheet" href="../assets/css/service-timeline.css?v=20260908-2">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator PASUTRI</div>
        <nav>
            <ul>
                <li><a href="kua_dashboard_operator.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li class="active"><i class="fas fa-cog"></i> Proses Pengajuan</li>
                <li><a href="kua_riwayat_operator.php"><i class="fas fa-history"></i> Riwayat</a></li>
                <li><a href="kua_dokumen_belum_diterima.php"><i class="fas fa-envelope-open-text"></i> Dokumen Belum Diterima</a></li>
                <li id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">
                    <?= isset($_GET['detail_id']) ? 'Detail Pengajuan' : 'Proses Pengajuan ' ?> - Operator PASUTRI
                </div>
                <div class="subtitle">Wilayah Tugas: <?= esc($wilayah_tugas) ?></div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama']) ?></span>
                <span class="role-badge">OPERATOR PASUTRI</span>
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
                <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="toast toast-error">
                    <?= esc($flash_error) ?>
                </div>
                <script>setTimeout(() => document.querySelector('.toast').remove(), 6000);</script>
            <?php endif; ?>

            <!-- KUA Info Box -->
            <?php if (!empty($assigned_kua_data)): ?>
            <div class="kua-info-box">
                <div class="kua-info-header">
                    <div class="kua-info-icon">🏢</div>
                    <div class="kua-info-content">
                        <h3>Informasi Wilayah Tugas</h3>
                        <p>
                            <?php if ($total_kua_assigned == 19 || $total_kua_assigned >= $total_kua_system): ?>
                                Anda bertanggung jawab atas <strong>Semua KUA</strong> di sistem ini.
                            <?php elseif ($total_kua_assigned < 5): ?>
                                Anda bertanggung jawab atas: <strong><?= esc($kua_list_for_display) ?></strong>
                            <?php else: ?>
                                Anda bertanggung jawab atas <strong><?= $total_kua_assigned ?> KUA</strong>.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="kua-info-box" style="border-left-color: #ef4444;">
                <div class="kua-info-header">
                    <div class="kua-info-icon">⚠️</div>
                    <div class="kua-info-content">
                        <h3>Informasi KUA Tugas</h3>
                        <p>Anda belum ditugaskan ke KUA manapun. Hubungi administrator.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['detail_id']) && $detail_data): ?>
                <!-- Halaman Detail Pengajuan -->
                <a href="kua_proses_pengajuan.php?page=<?= $current_page ?>&status=<?= urlencode($_GET['status'] ?? '') ?>&search_nama=<?= urlencode($_GET['search_nama'] ?? '') ?>" class="btn-back">
                    <i>←</i> Kembali ke Daftar Pengajuan
                </a>
                
                <div class="detail-section">
                    <div class="detail-header">
                        <h1 class="detail-title">
                            Detail Pengajuan Kartu Keluarga KUA
                            <span class="jenis-badge jenis-kk">
                                Kartu Keluarga KUA
                            </span>
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
                                <div class="detail-value"><?= esc($detail_data['nama_pemohon'] ?? ($detail_data['nama_user_lengkap'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">NIK Pemohon</div>
                                <div class="detail-value"><?= esc($detail_data['nik_pemohon'] ?? ($detail_data['nik_user'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">No HP</div>
                                <div class="detail-value"><?= esc($detail_data['no_hp'] ?? ($detail_data['no_hp_user'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?= esc($detail_data['email'] ?? ($detail_data['email_user'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">KUA</div>
                                <div class="detail-value"><?= esc($detail_data['nama_kua'] ?? '-') ?> (<?= esc($detail_data['nama_kecamatan'] ?? '-') ?>)</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Desa</div>
                                <div class="detail-value"><?= esc($detail_data['nama_desa'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Tanggal Pengajuan</div>
                                <div class="detail-value"><?= formatDate($detail_data['effective_created_at'] ?? ($detail_data['created_at'] ?? '')) ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Kartu Keluarga -->
                        <div class="detail-card">
                            <h3>Informasi Kartu Keluarga</h3>
                            <div class="detail-row">
                                <div class="detail-label">Nama Kepala Keluarga</div>
                                <div class="detail-value"><?= esc($detail_data['nama_kepalaKeluarga'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Alamat/Dusun</div>
                                <div class="detail-value"><?= esc($detail_data['dusun'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">No KK Lama Suami</div>
                                <div class="detail-value"><?= esc($detail_data['no_kk_lama_suami'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">No KK Lama Istri</div>
                                <div class="detail-value"><?= esc($detail_data['no_kk_lama_istri'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Status Alamat Calon Pengantin</div>
                                <div class="detail-value"><?= esc($detail_data['alamat_catin'] == 'alamat sama' ? 'Alamat Sama' : 'Alamat Beda') ?></div>
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
                    
                    <!-- Anggota Keluarga -->
                    <div class="anggota-section">
                        <h3>👨‍👩‍👧‍👦 Anggota Keluarga</h3>
                        <?= displayAnggotaKeluarga($detail_data['anggotaKeluarga'] ?? '') ?>
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
                                $type_label = match($file['type'] ?? 'lainnya') {
                                    'kk_lama_suami' => 'KK Lama Suami',
                                    'kk_lama_istri' => 'KK Lama Istri',
                                    'formulir_f101' => 'Formulir F-1.01 Biodata Keluarga',
                                    'buku_nikah' => 'Buku Nikah',
                                    'formulir_f103' => 'Formulir F-1.03 Pindah',
                                    'lainnya' => 'Dokumen Pendukung',
                                    default => ucwords(str_replace('_', ' ', $file['type'] ?? 'Dokumen'))
                                };
                                
                                $display_name = $file['original_name'] ?? 'Dokumen ' . ($index + 1);
                                if (strlen($display_name) > 25) {
                                    $display_name = substr($display_name, 0, 22) . '...';
                                }
                                
                                $file_info = '';
                                if (isset($file['size'])) {
                                    $file_size = formatFileSize($file['size']);
                                    $file_info = " ({$file_size})";
                                }
                            ?>
                            <div class="dokumen-card" onclick="previewDokumen('<?= esc($file['path']) ?>', '<?= $ext ?>')">
                                <div class="dokumen-icon"><?= $icon ?></div>
                                <div class="dokumen-name" title="<?= esc($file['original_name'] ?? $display_name) ?>">
                                    <?= esc($display_name) ?>
                                </div>
                                <div class="dokumen-type">
                                    <?= esc($type_label) ?><?= $file_info ?>
                                </div>
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
                        <?php $serviceTrackingTable = 'tb_kartukeluarga_kua'; include __DIR__ . '/../user/service_tracking_form.php'; ?>
                        <h3>Ubah Status Pengajuan</h3>
                        <form method="POST" class="status-form">
                            <input type="hidden" name="csrf_token" value="<?= esc($csrf_token_value) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="expected_status" value="<?= esc($detail_data['status'] ?? '') ?>">
                            <input type="hidden" name="history_version" value="<?= (int)$trackingVersion ?>">
                            <input type="hidden" name="table" value="tb_kartukeluarga_kua">
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
                    <form method="GET" class="filters">
                        <input type="hidden" name="page" value="1">
                        <div class="filter-group">
                            <label for="search_nama">Pencarian</label>
                            <input type="search" name="search_nama" id="search_nama" value="<?= esc($_GET['search_nama'] ?? '') ?>" placeholder="Nama, NIK, KUA, atau desa">
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
                        <a href="kua_proses_pengajuan.php" class="btn-reset">Reset Filter</a>
                    </form>
                </div>

                <!-- Tabel Pengajuan -->
                <div class="table-section">
                    <h2>
                        Daftar Pengajuan 
                        
                        <span class="table-count">Total: <?= $total_submissions ?> data | Halaman <?= $current_page ?> dari <?= $total_pages ?></span>
                    </h2>
                    
                    <?php if (empty($submissions)): ?>
                        <div class="empty-state">
                            <i>📭</i>
                            <h4>Tidak ada pengajuan ditemukan</h4>
                            <p>Tidak ada permohonan Kartu Keluarga di KUA tugas Anda dengan filter yang dipilih.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Pemohon</th>
                                    <th>NIK</th>
                                    <th>KUA</th>
                                    <th>Desa</th>
                                    <th>Alamat/Dusun</th>
                                    <th>Nama Kepala Keluarga</th>
                                    <th>Status Saat Ini</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tabel-permohonan-konten">
                                <?php 
                                $no = ($current_page - 1) * $per_page + 1;
                                foreach ($submissions as $row): 
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
                                        <td><?= esc($row['nama_user'] ?? '-') ?></td>
                                        <td><?= esc($row['nik_pemohon'] ?? '-') ?></td>
                                        <td><?= esc($row['nama_kua'] ?? '-') ?></td>
                                        <td><?= esc($row['nama_desa'] ?? '-') ?></td>
                                        <td><?= esc($row['dusun'] ?? '-') ?></td>
                                        <td><?= esc($row['nama_kepalaKeluarga'] ?? '-') ?></td>
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
                                            <small style="display:block;margin-top:6px">Status diperbarui: <?= esc(serviceTrackingUpdatedLabel($koneksi, 'tb_kartukeluarga_kua', (int)$row['id'])) ?></small>
                                        </td>
                                        <td>
                                            <a href="?detail_id=<?= $row['id'] ?>&table=tb_kartukeluarga_kua&page=<?= $current_page ?>&status=<?= urlencode($_GET['status'] ?? '') ?>&search_nama=<?= urlencode($_GET['search_nama'] ?? '') ?>" class="btn-detail">Detail</a>
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
        <h3>Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout dari sistem Operator KUA?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
            <button id="cancelLogout" class="btn-cancel">Batal</button>
        </div>
    </div>
</div>

<script>
// Toggle Sidebar Menu on Mobile
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '').trim();

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
    if (document.getElementById('status')) {
        document.getElementById('status').addEventListener('change', function() {
            document.querySelector('input[name="page"]').value = 1;
        });
    }

    // Defensive fallback: pastikan csrf_token selalu ikut untuk form POST.
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function(form) {
        form.addEventListener('submit', function() {
            let csrfInput = form.querySelector('input[name="csrf_token"]');
            if (!csrfInput) {
                csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                form.appendChild(csrfInput);
            }
            if ((!csrfInput.value || !csrfInput.value.trim()) && csrfToken) {
                csrfInput.value = csrfToken;
            }
        });
    });
});

// previewDokumen: operator_document_preview.js; toggleAlasan: service-tracking.js

// Auto-hide toast notifications
document.addEventListener('DOMContentLoaded', function() {
    const toasts = document.querySelectorAll('.toast');
    toasts.forEach(toast => {
        setTimeout(() => {
            toast.remove();
        }, 5000);
    });
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
