<?php
require_once '../error_handler.php';
// **CEK JIKA USER ADALAH OPERATOR KUA, REDIRECT KE KUA DASHBOARD**
if (isset($_SESSION['id_kua']) && !empty($_SESSION['id_kua']) && 
    isset($_SESSION['role']) && $_SESSION['role'] === 'operator') {
    
    error_log("âš ï¸ Operator KUA trying to access general dashboard, redirecting to KUA dashboard...");
    error_log("   User ID: " . $_SESSION['id_user']);
    error_log("   KUA ID: " . $_SESSION['id_kua']);
    
    // Redirect ke dashboard operator KUA
    header('Location: ../user_kua/kua_dashboard_operator.php');
    exit;
}

// Lanjutkan dengan kode existing Anda
error_log("=== ACCESSING DASHBOARD OPERATOR ===");
error_log('Request: ' . siprima_request_id());
error_log("Role: " . ($_SESSION['role'] ?? 'NOT SET'));
error_log("User ID: " . ($_SESSION['id_user'] ?? 'NOT SET'));

include '../../app/middleware/operator_only.php';
include '../../app/config/koneksi.php';

// === Tentukan kecamatan tugas operator berdasarkan session array ===
$allowed_kecamatan_ids = $_SESSION['kecamatan_tugas'] ?? []; // Array ID kecamatan
$allowed_kecamatan_names = $_SESSION['kecamatan_nama'] ?? []; // Array nama kecamatan

// Ambil semua kecamatan dari database untuk operator yang bertugas di semua kecamatan
$all_kecamatan_names = [];
$all_kecamatan_ids = [];

if (empty($allowed_kecamatan_ids) || in_array('semua', $allowed_kecamatan_ids) || strtolower(trim($_SESSION['kecamatan_tugas_str'] ?? '')) === 'semua') {
    // Operator dengan semua kecamatan - ambil semua kecamatan dari database
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

// Fungsi helper untuk escape output
if (!function_exists('esc')) {
    function esc($string) {
        return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
    }
}

// Flash messages
$flash_success = $_SESSION['success_message'] ?? null;
$flash_error = $_SESSION['error_message'] ?? null;

// Clear flash messages
unset($_SESSION['success_message'], $_SESSION['error_message']);

// === Hitung statistik berdasarkan kecamatan tugas ===
$stats = [
    'total' => 0,
    'diajukan' => 0,
    'diproses' => 0,
    'selesai' => 0,
    'ditolak' => 0
];


// Daftar tabel dan field yang akan diproses - DITAMBAHKAN TABEL LAPORAN PERISTIWA
$tables = [
    'tb_kartukeluarga' => [
        'nama_field' => 'nama_kepalaKeluarga', 
        'jenis' => 'Kartu Keluarga',
        'nama_field_display' => 'nama_kepalaKeluarga',
        'exclude_rs_puskes' => false
    ],
    'tb_akta_kelahiran' => [
        'nama_field' => 'nama_bayi', 
        'jenis' => 'Akta Kelahiran',
        'nama_field_display' => 'nama_bayi',
        'exclude_rs_puskes' => true
    ],
    'tb_akta_kematian' => [
        'nama_field' => 'Nama_meninggal', 
        'jenis' => 'Akta Kematian',
        'nama_field_display' => 'Nama_meninggal',
        'exclude_rs_puskes' => true
    ],
    'tb_pindah_alamat' => [
        'nama_field' => 'nama_kepalaKeluarga', 
        'jenis' => 'Pindah Alamat',
        'nama_field_display' => 'nama_kepalaKeluarga',
        'exclude_rs_puskes' => false
    ],
    'tb_pindah_online' => [
        'nama_field' => 'nama_kepalaKeluarga', 
        'jenis' => 'Pindah Masuk',
        'nama_field_display' => 'nama_kepalaKeluarga',
        'exclude_rs_puskes' => false
    ],
    'tb_pengajuan_kia' => [
        'nama_field' => 'no_kk',
        'jenis' => 'Kartu Identitas Anak',
        'nama_field_display' => 'no_kk',
        'exclude_rs_puskes' => false
    ],
    'tb_laporan_peristiwa' => [ // TAMBAHKAN TABEL INI
        'nama_field' => 'nama_subjek', 
        'jenis' => 'Laporan Peristiwa',
        'nama_field_display' => 'nama_subjek',
        'exclude_rs_puskes' => false
    ]
];

foreach ($tables as $tbl => $info) {
    try {
        $check = $koneksi->query("SHOW TABLES LIKE '" . $koneksi->real_escape_string($tbl) . "'");
        
        if ($check && $check->num_rows > 0) {
            // Query dengan filter kecamatan jika ada
            $query = "SELECT 
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
                        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
                        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
                      FROM `$tbl` t " . ($tbl === 'tb_pengajuan_kia' ? "LEFT JOIN tb_kecamatan kc ON kc.id = t.id_kecamatan " : "") . "WHERE 1=1";
            
            // Tambahkan kondisi untuk mengecualikan data yang memiliki id_rs atau id_puskes
            if ($info['exclude_rs_puskes']) {
                $query .= " AND (id_rs IS NULL OR id_rs = 0 OR id_rs = '') 
                           AND (id_puskes IS NULL OR id_puskes = 0 OR id_puskes = '')";
            }
            
            // Tambahkan filter kecamatan jika operator tidak memiliki semua kecamatan
            if (!empty($allowed_kecamatan_names)) {
                if (count($allowed_kecamatan_names) < 20) {
                    $placeholders = implode(',', array_fill(0, count($allowed_kecamatan_names), '?'));
                    $colKec = ($tbl === 'tb_pengajuan_kia') ? 'kc.nama_kecamatan' : 'nama_kecamatan';
                    $query .= " AND $colKec IN ($placeholders)";
                    
                    $stmt = $koneksi->prepare($query);
                    if ($stmt) {
                        $types = str_repeat('s', count($allowed_kecamatan_names));
                        $values = $allowed_kecamatan_names;
                        $stmt->bind_param($types, ...$values);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    }
                } else {
                    $stmt = $koneksi->prepare($query);
                    if ($stmt) {
                        $stmt->execute();
                        $result = $stmt->get_result();
                    }
                }
            } else {
                $query .= " AND 1=0";
                $stmt = $koneksi->prepare($query);
                if ($stmt) {
                    $stmt->execute();
                    $result = $stmt->get_result();
                }
            }
            
            if (isset($stmt) && $stmt) {
                if ($row = $result->fetch_assoc()) {
                    $total_jenis = (int)($row['total'] ?? 0);
                    
                    $stats['total'] += $total_jenis;
                    $stats['diajukan'] += (int)($row['diajukan'] ?? 0);
                    $stats['diproses'] += (int)($row['diproses'] ?? 0);
                    $stats['selesai'] += (int)($row['selesai'] ?? 0);
                    $stats['ditolak'] += (int)($row['ditolak'] ?? 0);
                    
                    // Update statistik per jenis
                    if (isset($jenis_stats[$info['jenis']])) {
                        $jenis_stats[$info['jenis']]['total'] = $total_jenis;
                    }
                }
                $stmt->close();
            }
        }
        if (isset($check)) $check->close();
    } catch (Exception $e) {
        error_log("Error counting stats for table $tbl: " . $e->getMessage());
    }
}

// === Pagination Configuration ===
$limit = 10; // Jumlah data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// === Ambil SEMUA data terlebih dahulu untuk sorting dan pagination ===
$all_submissions = [];

foreach ($tables as $tbl => $info) {
    $check = $koneksi->query("SHOW TABLES LIKE '" . $koneksi->real_escape_string($tbl) . "'");
    if ($check && $check->num_rows > 0) {
        $nama_field_clean = str_replace(['`', '"', "'"], '', $info['nama_field']);
        
 $id_select = ($tbl === 'tb_laporan_peristiwa') ? 't.id_lapor AS id' : 't.id';
        if ($tbl === 'tb_pengajuan_kia') {
            $query = "SELECT t.id, t.created_at, LOWER(t.status) AS status, NULL AS dokumen, kc.nama_kecamatan, ds.nama_desa,
                             COALESCE((SELECT GROUP_CONCAT(a.nama_anak SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = t.id), t.no_kk) AS nama_utama,
                             u.nama AS nama_user, u.email AS email_user, u.no_hp AS telp_user,
                             t.keterangan_permohonan AS keterangan, t.alasan_penolakan AS alasan,
                             DATE_FORMAT(t.created_at, '%d %M %Y %H:%i') as formatted_date,
                             '{$info['jenis']}' AS jenis_layanan,
                             '{$tbl}' AS table_name
                      FROM `tb_pengajuan_kia` t
                      LEFT JOIN tb_users u ON t.id_user = u.id
                      LEFT JOIN tb_kecamatan kc ON kc.id = t.id_kecamatan
                      LEFT JOIN tb_desa ds ON ds.id = t.id_desa
                      WHERE LOWER(t.status) = 'diajukan'";
        } else {
        $query = "SELECT $id_select, t.created_at, t.status, t.dokumen, t.nama_kecamatan, t.nama_desa,
                         t.{$nama_field_clean} AS nama_utama, u.nama AS nama_user,
                         u.email AS email_user, u.no_hp AS telp_user,
                         t.keterangan, t.alasan,
                         DATE_FORMAT(t.created_at, '%d %M %Y %H:%i') as formatted_date,
                         '{$info['jenis']}' AS jenis_layanan,
                         '{$tbl}' AS table_name
                  FROM `$tbl` t 
                  LEFT JOIN tb_users u ON t.id_user = u.id
                  WHERE t.status = 'diajukan'";
        }
        
        if ($info['exclude_rs_puskes']) {
            $query .= " AND (t.id_rs IS NULL OR t.id_rs = 0 OR t.id_rs = '') 
                       AND (t.id_puskes IS NULL OR t.id_puskes = 0 OR t.id_puskes = '')";
        }
        
        if (!empty($allowed_kecamatan_names) && count($allowed_kecamatan_names) < 20) {
            $placeholders = implode(',', array_fill(0, count($allowed_kecamatan_names), '?'));
            $colKecSub = ($tbl === 'tb_pengajuan_kia') ? 'kc.nama_kecamatan' : 't.nama_kecamatan';
            $query .= " AND $colKecSub IN ($placeholders)";
        }
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            if (!empty($allowed_kecamatan_names) && count($allowed_kecamatan_names) < 20) {
                $types = str_repeat('s', count($allowed_kecamatan_names));
                $stmt->bind_param($types, ...$allowed_kecamatan_names);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            
            while ($r = $res->fetch_assoc()) {
                $all_submissions[] = $r;
            }
            $stmt->close();
        }
        $check->close();
    }
}

// === Urutkan SEMUA data berdasarkan created_at DESC (terbaru) ===
usort($all_submissions, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// === Hitung total data untuk pagination ===
$total_data = count($all_submissions);

// === Hitung total halaman ===
$total_pages = ceil($total_data / $limit);

// === Ambil data untuk halaman saat ini ===
$submissions = array_slice($all_submissions, $offset, $limit);

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
    <title>Dashboard Operator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
    <style>
        /* Dasar tipografi & latar: mengikuti proses_pengajuan.css (acuan operator) */
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        /* Layout Structure */
        .layout {
            display: flex;
            min-height: 100vh;
            background: #f5f7fa;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%);
            color: white;
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar .logo {
            padding: 25px 20px;
            font-size: 22px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.1);
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            letter-spacing: 1px;
            color: #3498db;
        }

        .sidebar nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar nav ul li {
            padding: 0;
            margin: 0;
        }

        .sidebar nav ul li a,
        .sidebar nav ul li {
            display: block;
            padding: 15px 20px;
            color: #bdc3c7;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            font-size: 15px;
            cursor: pointer;
        }

        .sidebar nav ul li a:hover,
        .sidebar nav ul li:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: #3498db;
            padding-left: 25px;
        }

        .sidebar nav ul li.active {
            background: rgba(52, 152, 219, 0.2);
            color: white;
            border-left-color: #3498db;
            font-weight: 600;
        }

        .sidebar nav ul li.active a {
            color: white;
        }

        .sidebar nav ul li#logoutBtn {
            margin-top: 20px;
            background: rgba(231, 76, 60, 0.1);
            border-left-color: #e74c3c;
        }

        .sidebar nav ul li#logoutBtn:hover {
            background: rgba(231, 76, 60, 0.3);
        }

        /* Menu Toggle Button */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            background: #3498db;
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            z-index: 1100;
            font-size: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        /* Main Content */
        .main {
            flex: 1;
            margin-left: 250px;
            padding: 0;
            transition: margin-left 0.3s ease;
        }

        /* Header */
        .header {
            background: white;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .left-header .title {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .left-header .subtitle {
            font-size: 14px;
            color: #7f8c8d;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: 500;
            color: #2c3e50;
        }

        .user-info .logout {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .user-info .logout:hover {
            background: #c0392b;
        }

        /* Content Area */
        .content {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        /* Dashboard Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: #fff;
            padding: 20px 15px;
            border-radius: 10px;
            box-shadow: 0 3px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-top: 4px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card.total { border-color: #3498db; }
        .stat-card.diajukan { border-color: #f39c12; }
        .stat-card.diproses { border-color: #9b59b6; }
        .stat-card.selesai { border-color: #2ecc71; }
        .stat-card.ditolak { border-color: #e74c3c; }
        
        .stat-card h3 {
            margin: 0 0 8px 0;
            font-size: 14px;
            color: #555;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card .count {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin: 5px 0;
        }
        
        /* Statistik Per Jenis */
        .jenis-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .jenis-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
            border-left: 4px solid;
        }
        
        .jenis-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .jenis-icon {
            font-size: 28px;
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .jenis-info h4 {
            margin: 0 0 5px 0;
            color: #2c3e50;
            font-size: 16px;
            font-weight: 600;
        }
        
        .jenis-info .count {
            font-size: 22px;
            font-weight: bold;
        }
        
        .jenis-info .count-zero {
            color: #95a5a6;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .action-card {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            transition: all 0.3s ease;
            border-left: 4px solid #3498db;
        }
        
        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .action-card h3 {
            margin: 0 0 15px 0;
            color: #2c3e50;
            font-size: 18px;
        }
        
        .action-card p {
            color: #7f8c8d;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .btn-action {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 10px 25px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s ease;
        }
        
        .btn-action:hover {
            background: #2980b9;
        }
        
        /* Recent Submissions */
        .recent-section {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-top: 30px;
        }
        
        .recent-section h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #2c3e50;
            font-size: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #eee;
            font-size: 14px;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
            vertical-align: top;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-diajukan {
            background: #fef3c7;
            color: #92400e;
        }
        
        /* Action Column Styling */
        .action-column {
            text-align: center;
        }
        
        /* Jenis Layanan Badge */
        .jenis-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .jenis-kk {
            background: #e8f6f3;
            color: #16a085;
            border: 1px solid #16a085;
        }
        
        .jenis-akta-lahir {
            background: #f4ecf7;
            color: #8e44ad;
            border: 1px solid #8e44ad;
        }
        
        .jenis-akta-mati {
            background: #fdebd0;
            color: #d35400;
            border: 1px solid #d35400;
        }
        
        .jenis-pindah-alamat {
            background: #d6eaf8;
            color: #2874a6;
            border: 1px solid #2874a6;
        }
        
        .jenis-pindah-masuk {
            background: #d5f4e6;
            color: #239b56;
            border: 1px solid #239b56;
        }
        
        .jenis-laporan { /* TAMBAHKAN STYLE UNTUK LAPORAN PERISTIWA */
            background: #fadbd8;
            color: #e74c3c;
            border: 1px solid #e74c3c;
        }
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            gap: 10px;
        }
        
        .pagination-info {
            font-size: 14px;
            color: #6c757d;
            margin-right: 20px;
        }
        
        .pagination-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            background: #f8f9fa;
            color: #3498db;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            border: 1px solid #dee2e6;
            min-width: 40px;
            text-align: center;
        }
        
        .pagination-button:hover:not(.disabled) {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }
        
        .pagination-button.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }
        
        .pagination-button.disabled {
            color: #6c757d;
            cursor: not-allowed;
            background: #f8f9fa;
        }
        
        .page-numbers {
            display: flex;
            gap: 5px;
        }
        
        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 400px;
            opacity: 0;
            transform: translateX(100px);
            transition: all 0.3s ease;
        }
        
        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }
        
        .toast.success {
            background: #2ecc71;
        }
        
        .toast.error {
            background: #e74c3c;
        }
        
        .toast-icon {
            font-size: 20px;
        }
        
        /* Wilayah Info */
        .wilayah-info {
            background: #e8f4fc;
            border: 1px solid #3498db;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .wilayah-icon {
            font-size: 24px;
            color: #3498db;
        }
        
        .wilayah-text h4 {
            margin: 0 0 5px 0;
            color: #2c3e50;
        }
        
        .wilayah-text p {
            margin: 0;
            color: #555;
            font-size: 14px;
        }

        /* Logout Modal */
        .logout-modal .modal-box {
            max-width: 400px;
        }

        .btn-yes {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .btn-yes:hover {
            background: #c0392b;
        }

        .btn-no {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .btn-no:hover {
            background: #7f8c8d;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #bdc3c7;
        }
        
        .empty-state h4 {
            margin: 0 0 10px 0;
            color: #2c3e50;
        }
        
        .empty-state p {
            margin: 0;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
                min-width: 0;
                max-width: 100%;
                overflow-x: hidden;
            }

            .sidebar.open + .main {
                margin-left: 0;
            }

            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 20px;
            }

            .user-info {
                flex-direction: column;
                gap: 10px;
            }

            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .jenis-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
            
            th, td {
                padding: 12px 10px;
                font-size: 13px;
            }
            
            .pagination {
                flex-wrap: wrap;
                gap: 8px;
            }
            
            .pagination-info {
                margin-right: 0;
                margin-bottom: 10px;
                width: 100%;
                text-align: center;
            }
            
            .page-numbers {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .content {
                padding: 16px;
            }

            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .jenis-stats {
                grid-template-columns: 1fr;
            }
            
            .wilayah-info {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 20px;
            }
            
            .user-info {
                flex-direction: column;
                gap: 10px;
            }
            
            .pagination-button {
                padding: 6px 10px;
                min-width: 35px;
                height: 35px;
                font-size: 14px;
            }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator Dukcapil</div>
        <nav>
            <ul>
                <li class="active">Dashboard</li>            
                <li><a href="proses_pengajuan.php">Proses Pengajuan</a></li>
                <li><a href="lapor_peristiwa_opr.php">Laporan Peristiwa</a></li>
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
                <div class="title">Dashboard Operator</div>
                <div class="subtitle">Wilayah Tugas: <?= esc($wilayah_tugas) ?></div>
            </div>
            <div class="user-info">
                Hai, <?= esc($_SESSION['nama']) ?>
                <?php include __DIR__ . '/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Flash Messages -->
            <?php if ($flash_success): ?>
                <div class="toast success" id="successToast">
                    <span class="toast-icon">âœ“</span>
                    <span><?= esc($flash_success) ?></span>
                </div>
                <script>
                    setTimeout(() => {
                        const toast = document.getElementById('successToast');
                        toast.classList.add('show');
                        setTimeout(() => toast.classList.remove('show'), 4000);
                    }, 100);
                </script>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="toast error" id="errorToast">
                    <span class="toast-icon">!</span>
                    <span><?= esc($flash_error) ?></span>
                </div>
                <script>
                    setTimeout(() => {
                        const toast = document.getElementById('errorToast');
                        toast.classList.add('show');
                        setTimeout(() => toast.classList.remove('show'), 5000);
                    }, 100);
                </script>
            <?php endif; ?>

            <!-- Wilayah Tugas Info -->
            <div class="wilayah-info">
                <div class="wilayah-icon">ðŸ“</div>
                <div class="wilayah-text">
                    <h4>Wilayah Tugas Anda</h4>
                    <p><?= esc($wilayah_tugas) ?></p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card total">
                    <h3>Total Pengajuan</h3>
                    <div class="count"><?= $stats['total'] ?></div>
                    <div class="detail"><?= count($tables) ?> Jenis Layanan</div>
                </div>
                
                <div class="stat-card diajukan">
                    <h3>Diajukan</h3>
                    <div class="count"><?= $stats['diajukan'] ?></div>
                    <div class="detail">Menunggu diproses</div>
                </div>
                
                <div class="stat-card diproses">
                    <h3>Sedang Diproses</h3>
                    <div class="count"><?= $stats['diproses'] ?></div>
                    <div class="detail">Dalam proses verifikasi</div>
                </div>
                
                <div class="stat-card selesai">
                    <h3>Selesai</h3>
                    <div class="count"><?= $stats['selesai'] ?></div>
                    <div class="detail">Telah disetujui</div>
                </div>
                
                <div class="stat-card ditolak">
                    <h3>Ditolak</h3>
                    <div class="count"><?= $stats['ditolak'] ?></div>
                    <div class="detail">Tidak memenuhi syarat</div>
                </div>
            </div>
           
            <!-- Quick Actions -->
            <div class="quick-actions">
                <div class="action-card">
                    <h3>Proses Pengajuan</h3>
                    <p>Lanjutkan pengolahan dokumen yang sedang berjalan</p>
                    <a href="proses_pengajuan.php" class="btn-action">Proses</a>
                </div>
                
                <div class="action-card">
                    <h3>Laporan Peristiwa</h3>
                    <p>Kelola laporan peristiwa kependudukan</p>
                    <a href="lapor_peristiwa_opr.php" class="btn-action">Kelola</a>
                </div>
                
                <div class="action-card">
                    <h3>Riwayat Pengajuan</h3>
                    <p>Lihat riwayat lengkap semua pengajuan yang telah selesai</p>
                    <a href="riwayat_operator.php" class="btn-action">Lihat Riwayat</a>
                </div>
            </div>

            <!-- Pengajuan Terbaru (Hanya status diajukan) dengan Pagination -->
            <div class="recent-section">
                <h2>Pengajuan Terbaru (Status: Diajukan)</h2>
                <?php if (empty($submissions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>Tidak ada pengajuan</h4>
                        <p>Tidak ada pengajuan dengan status 'diajukan' dalam wilayah tugas Anda.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Kecamatan</th>
                                <th>Jenis Layanan</th>
                                <th>Nama Pemohon</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = ($page - 1) * $limit + 1;
                            foreach ($submissions as $r): 
                                // Tentukan kelas CSS berdasarkan jenis layanan
                                $jenis_class = '';
                                switch($r['jenis_layanan']) {
                                    case 'Kartu Keluarga':
                                        $jenis_class = 'jenis-kk';
                                        break;
                                    case 'Akta Kelahiran':
                                        $jenis_class = 'jenis-akta-lahir';
                                        break;
                                    case 'Akta Kematian':
                                        $jenis_class = 'jenis-akta-mati';
                                        break;
                                    case 'Pindah Alamat':
                                        $jenis_class = 'jenis-pindah-alamat';
                                        break;
                                    case 'Pindah Masuk':
                                        $jenis_class = 'jenis-pindah-masuk';
                                        break;
                                    case 'Kartu Identitas Anak':
                                        $jenis_class = 'jenis-kia';
                                        break;
                                    case 'Laporan Peristiwa':
                                        $jenis_class = 'jenis-laporan';
                                        break;
                                }
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= esc($r['formatted_date']) ?: ($r['created_at'] ? date('d M Y H:i', strtotime($r['created_at'])) : '-') ?></td>
                                    <td><?= esc($r['nama_kecamatan']) ?></td>
                                    <td>
                                        <span class="jenis-badge <?= $jenis_class ?>">
                                            <?= esc($r['jenis_layanan']) ?>
                                        </span>
                                    </td>
                                    <td><?= esc($r['nama_user'] ?? $r['nama_utama'] ?? '-') ?></td>
                                    <td>
                                        <span class="status-badge status-diajukan">
                                            <?= ucfirst($r['status']) ?>
                                        </span>
                                    </td>
                                    <td class="action-column">
                                        <?php if ($r['jenis_layanan'] == 'Laporan Peristiwa'): ?>
                                            <a href="lapor_peristiwa_opr.php?filter=diajukan" 
                                               class="btn-action" 
                                               style="padding: 8px 15px; font-size: 13px;">
                                                Proses
                                            </a>
                                        <?php else: ?>
                                            <a href="proses_pengajuan.php?filter=diajukan&jenis=<?= urlencode($r['jenis_layanan']) ?>" 
                                               class="btn-action" 
                                               style="padding: 8px 15px; font-size: 13px;">
                                                Proses
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Menampilkan <?= count($submissions) ?> dari <?= $total_data ?> data
                            (Halaman <?= $page ?> dari <?= $total_pages ?>)
                        </div>
                        
                        <div class="page-controls">
                            <!-- Previous Button -->
                            <a href="?page=<?= $page - 1 ?>" class="pagination-button <?= $page <= 1 ? 'disabled' : '' ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            
                            <!-- Page Numbers -->
                            <div class="page-numbers">
                                <?php
                                // Tampilkan maksimal 5 halaman di sekitar halaman aktif
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                // Jika halaman awal bukan 1, tambahkan ellipsis
                                if ($start_page > 1) {
                                    echo '<a href="?page=1" class="pagination-button">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span class="pagination-button disabled">...</span>';
                                    }
                                }
                                
                                // Tampilkan halaman-halaman
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    $active_class = $i == $page ? 'active' : '';
                                    echo '<a href="?page=' . $i . '" class="pagination-button ' . $active_class . '">' . $i . '</a>';
                                }
                                
                                // Jika halaman akhir bukan total halaman, tambahkan ellipsis
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span class="pagination-button disabled">...</span>';
                                    }
                                    echo '<a href="?page=' . $total_pages . '" class="pagination-button">' . $total_pages . '</a>';
                                }
                                ?>
                            </div>
                            
                            <!-- Next Button -->
                            <a href="?page=<?= $page + 1 ?>" class="pagination-button <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                <?php endif; ?>
            </div>
            
            
        </section>
    </main>
</div>

<!-- Logout Modal -->
<div class="modal-overlay logout-modal" id="logoutModal">
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
        if (event.key === 'Escape') {
            hideLogoutModal();
        }
    });
});
</script>

</body>
</html>

