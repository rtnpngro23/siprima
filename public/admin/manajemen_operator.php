<?php
require_once __DIR__ . '/../error_handler.php';
// manajemen_operator.php

// Debug untuk melihat session SEBELUM session_start
error_log('=== MANAJEMEN_OPERATOR.PHP STARTING ===');
error_log('Session status before: ' . session_status());

// Mulai session HANYA jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    error_log('Session started');
} else {
    error_log('Session already active');
}

error_log('Session status after: ' . session_status());
error_log('Request: ' . siprima_request_id());
error_log('Session user_id: ' . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log('Session role: ' . ($_SESSION['role'] ?? 'NOT SET'));

require_once __DIR__ . '/../../app/config/koneksi.php';
require_once __DIR__ . '/../../app/middleware/admin_only.php';

// Fungsi helper
function esc($string) {
    return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
}

// === AMBIL STATISTIK USER DARI DATABASE ===
$stats = [
    'total' => 0,
    'admin' => 0,
    'operator' => 0,
    'petugas_desa' => 0,
    'user_kua' => 0,
    'user_reguler' => 0,
    'user_puskes' => 0,
    'user_rs' => 0,
    'aktif_hari_ini' => 0,
    'nonaktif' => 0,
    'aktif' => 0
];

// Hitung total user per role dan status
$query_stats = $koneksi->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin,
        SUM(CASE WHEN role = 'operator' THEN 1 ELSE 0 END) as operator,
        SUM(CASE WHEN role = 'petugas_desa' THEN 1 ELSE 0 END) as petugas_desa,
        SUM(CASE WHEN role = 'user' AND id_kua IS NOT NULL THEN 1 ELSE 0 END) as user_kua,
        SUM(CASE WHEN role = 'user' AND id_puskes IS NOT NULL THEN 1 ELSE 0 END) as user_puskes,
        SUM(CASE WHEN role = 'user' AND id_rs IS NOT NULL THEN 1 ELSE 0 END) as user_rs,
        SUM(CASE WHEN role = 'user' AND id_kua IS NULL AND id_puskes IS NULL AND id_rs IS NULL AND id_petugas IS NULL THEN 1 ELSE 0 END) as user_reguler,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as nonaktif,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as aktif
    FROM tb_users
");
$query_stats->execute();
$result_stats = $query_stats->get_result();

if ($row = $result_stats->fetch_assoc()) {
    $stats['total'] = (int)$row['total'];
    $stats['admin'] = (int)$row['admin'];
    $stats['operator'] = (int)$row['operator'];
    $stats['petugas_desa'] = (int)$row['petugas_desa'];
    $stats['user_kua'] = (int)$row['user_kua'];
    $stats['user_puskes'] = (int)$row['user_puskes'];
    $stats['user_rs'] = (int)$row['user_rs'];
    $stats['user_reguler'] = (int)$row['user_reguler'];
    $stats['nonaktif'] = (int)$row['nonaktif'];
    $stats['aktif'] = (int)$row['aktif'];
}

// Hitung user yang dibuat hari ini
$today = date('Y-m-d');
$query_active = $koneksi->prepare("
    SELECT COUNT(*) as aktif_hari_ini 
    FROM tb_users 
    WHERE DATE(created_at) = ?
");
$query_active->bind_param('s', $today);
$query_active->execute();
$result_active = $query_active->get_result();

if ($row_active = $result_active->fetch_assoc()) {
    $stats['aktif_hari_ini'] = (int)$row_active['aktif_hari_ini'];
}

// Ambil semua KUA dari database
$query_all_kua = $koneksi->query("
    SELECT id_kua, nama_kua 
    FROM tb_kua 
    ORDER BY nama_kua
");
$all_kua = $query_all_kua ? $query_all_kua->fetch_all(MYSQLI_ASSOC) : [];

// Ambil semua kecamatan dari database
$query_kec = $koneksi->query("
    SELECT id as kecamatan_id, nama_kecamatan
    FROM tb_kecamatan
    ORDER BY nama_kecamatan
");
$all_kecamatan = $query_kec ? $query_kec->fetch_all(MYSQLI_ASSOC) : [];

// Ambil semua puskesmas dari database
$query_all_puskes = $koneksi->query("
    SELECT id_puskes, nama_puskes, id_kecamatan
    FROM tb_puskes 
    ORDER BY nama_puskes
");
$all_puskes = $query_all_puskes ? $query_all_puskes->fetch_all(MYSQLI_ASSOC) : [];

// AMBIL SEMUA RUMAH SAKIT dari database
$query_all_rs = $koneksi->query("
    SELECT id_rs, nama_rs
    FROM tb_rumahsakit 
    ORDER BY nama_rs
");
$all_rs = $query_all_rs ? $query_all_rs->fetch_all(MYSQLI_ASSOC) : [];

// Ambil semua desa dari database
$query_all_desa = $koneksi->query("
    SELECT id, nama_desa, kecamatan_id
    FROM tb_desa 
    ORDER BY nama_desa
");
$all_desa = $query_all_desa ? $query_all_desa->fetch_all(MYSQLI_ASSOC) : [];

// Ambil semua petugas desa dari database untuk dropdown
$query_all_petugas = $koneksi->query("
    SELECT id, nama
    FROM tb_users 
    WHERE role = 'petugas_desa' 
    ORDER BY nama
");
$all_petugas = $query_all_petugas ? $query_all_petugas->fetch_all(MYSQLI_ASSOC) : [];

// Konfigurasi pagination
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Hitung total data dengan filter jika ada
$filter_where = "";
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = $koneksi->real_escape_string($_GET['search']);
    $filter_where = "WHERE u.nama LIKE '%$search%' OR u.nik LIKE '%$search%' OR u.email LIKE '%$search%' 
                     OR ku.nama_kua LIKE '%$search%' OR p.nama_puskes LIKE '%$search%' 
                     OR rs.nama_rs LIKE '%$search%' OR d.nama_desa LIKE '%$search%'";
}

if (isset($_GET['role']) && !empty($_GET['role'])) {
    $role = $koneksi->real_escape_string($_GET['role']);
    $filter_where .= empty($filter_where) ? "WHERE " : " AND ";
    $filter_where .= "u.role = '$role'";
}

if (isset($_GET['kua']) && !empty($_GET['kua'])) {
    $kua_id = $koneksi->real_escape_string($_GET['kua']);
    $filter_where .= empty($filter_where) ? "WHERE " : " AND ";
    $filter_where .= "(u.id_kua = '$kua_id' OR u.id IN (SELECT operator_id FROM tb_operator_kecamatan WHERE kua_id = '$kua_id'))";
}

if (isset($_GET['puskes']) && !empty($_GET['puskes'])) {
    $puskes_id = $koneksi->real_escape_string($_GET['puskes']);
    $filter_where .= empty($filter_where) ? "WHERE " : " AND ";
    $filter_where .= "(u.id_puskes = '$puskes_id' OR u.id IN (SELECT operator_id FROM tb_operator_kecamatan WHERE puskes_id = '$puskes_id'))";
}

// TAMBAH FILTER RUMAH SAKIT
if (isset($_GET['rs']) && !empty($_GET['rs'])) {
    $rs_id = $koneksi->real_escape_string($_GET['rs']);
    $filter_where .= empty($filter_where) ? "WHERE " : " AND ";
    $filter_where .= "(u.id_rs = '$rs_id' OR u.id IN (SELECT operator_id FROM tb_operator_kecamatan WHERE rs_id = '$rs_id'))";
}

$count_query = $koneksi->query("
    SELECT COUNT(*) as total 
    FROM tb_users u 
    LEFT JOIN tb_kua ku ON u.id_kua = ku.id_kua
    LEFT JOIN tb_puskes p ON u.id_puskes = p.id_puskes
    LEFT JOIN tb_rumahsakit rs ON u.id_rs = rs.id_rs
    LEFT JOIN tb_desa d ON u.id_desa = d.id
    LEFT JOIN tb_users pt ON u.id_petugas = pt.id
    $filter_where
");
$total_data = $count_query ? $count_query->fetch_assoc()['total'] : 0;
$total_pages = ceil($total_data / $limit);

// Query untuk mengambil semua user dengan pagination
$query_users = $koneksi->query("
    SELECT 
        u.id,
        u.nama,
        u.nik,
        u.no_hp,
        u.email,
        u.role,
        u.is_active,
        u.alamat,
        u.created_at,
        u.id_kua,
        u.id_puskes,
        u.id_rs,
        u.id_petugas,
        u.id_desa,
        ku.nama_kua,
        p.nama_puskes,
        rs.nama_rs,
        d.nama_desa,
        pt.nama as nama_petugas
    FROM tb_users u 
    LEFT JOIN tb_kua ku ON u.id_kua = ku.id_kua
    LEFT JOIN tb_puskes p ON u.id_puskes = p.id_puskes
    LEFT JOIN tb_rumahsakit rs ON u.id_rs = rs.id_rs
    LEFT JOIN tb_desa d ON u.id_desa = d.id
    LEFT JOIN tb_users pt ON u.id_petugas = pt.id
    $filter_where
    GROUP BY u.id
    ORDER BY 
        CASE u.role 
            WHEN 'admin' THEN 1
            WHEN 'operator' THEN 2
            WHEN 'petugas_desa' THEN 3
            WHEN 'user' THEN 4
            ELSE 5
        END,
        u.nama
    LIMIT $limit OFFSET $offset
");

$users = $query_users ? $query_users->fetch_all(MYSQLI_ASSOC) : [];

// Untuk kebutuhan form dan tampilan, kita perlu data kecamatan, KUA, puskesmas, dan rumah sakit per operator
$operator_kecamatan = [];
$operator_kecamatan_ids = [];
$operator_kua = [];
$operator_kua_ids = [];
$operator_puskes = [];
$operator_puskes_ids = [];
$operator_rs = [];
$operator_rs_ids = [];

// Ambil semua data operator dari database termasuk wilayah tugasnya
$all_operators = $koneksi->query("
    SELECT id, nama, role 
    FROM tb_users 
    WHERE role = 'operator' 
    ORDER BY nama
");

$operators_with_tugas = [];
if ($all_operators) {
    while ($operator = $all_operators->fetch_assoc()) {
        $operator_id = $operator['id'];
        
        // Ambil data kecamatan yang ditugaskan
        $query_kec_op = $koneksi->prepare("
            SELECT 
                k.id as kecamatan_id,
                k.nama_kecamatan
            FROM tb_operator_kecamatan ok 
            JOIN tb_kecamatan k ON ok.kecamatan_id = k.id 
            WHERE ok.operator_id = ?
            ORDER BY k.nama_kecamatan
        ");
        $kecamatan_op = [];
        $kecamatan_ids_op = [];
        if ($query_kec_op) {
            $query_kec_op->bind_param('i', $operator_id);
            if ($query_kec_op->execute()) {
                $result_kec = $query_kec_op->get_result();
                while ($row = $result_kec->fetch_assoc()) {
                    $kecamatan_op[] = [
                        'kecamatan_id' => $row['kecamatan_id'],
                        'nama_kecamatan' => $row['nama_kecamatan']
                    ];
                    $kecamatan_ids_op[] = $row['kecamatan_id'];
                }
            }
            $query_kec_op->close();
        }
        
        // Ambil data KUA yang ditugaskan
        $query_kua_op = $koneksi->prepare("
            SELECT 
                ku.id_kua,
                ku.nama_kua
            FROM tb_operator_kecamatan ok 
            JOIN tb_kua ku ON ok.kua_id = ku.id_kua 
            WHERE ok.operator_id = ?
            GROUP BY ku.id_kua
            ORDER BY ku.nama_kua
        ");
        $kua_op = [];
        $kua_ids_op = [];
        if ($query_kua_op) {
            $query_kua_op->bind_param('i', $operator_id);
            if ($query_kua_op->execute()) {
                $result_kua = $query_kua_op->get_result();
                while ($row = $result_kua->fetch_assoc()) {
                    $kua_op[] = [
                        'id_kua' => $row['id_kua'],
                        'nama_kua' => $row['nama_kua']
                    ];
                    $kua_ids_op[] = $row['id_kua'];
                }
            }
            $query_kua_op->close();
        }
        
        // Ambil data puskesmas yang ditugaskan
        $query_puskes_op = $koneksi->prepare("
            SELECT 
                p.id_puskes,
                p.nama_puskes
            FROM tb_operator_kecamatan ok 
            JOIN tb_puskes p ON ok.puskes_id = p.id_puskes 
            WHERE ok.operator_id = ?
            GROUP BY p.id_puskes
            ORDER BY p.nama_puskes
        ");
        $puskes_op = [];
        $puskes_ids_op = [];
        if ($query_puskes_op) {
            $query_puskes_op->bind_param('i', $operator_id);
            if ($query_puskes_op->execute()) {
                $result_puskes = $query_puskes_op->get_result();
                while ($row = $result_puskes->fetch_assoc()) {
                    $puskes_op[] = [
                        'id_puskes' => $row['id_puskes'],
                        'nama_puskes' => $row['nama_puskes']
                    ];
                    $puskes_ids_op[] = $row['id_puskes'];
                }
            }
            $query_puskes_op->close();
        }
        
        // AMBIL DATA RUMAH SAKIT YANG DITUGASKAN
        $query_rs_op = $koneksi->prepare("
            SELECT 
                rs.id_rs,
                rs.nama_rs
            FROM tb_operator_kecamatan ok 
            JOIN tb_rumahsakit rs ON ok.rs_id = rs.id_rs 
            WHERE ok.operator_id = ?
            GROUP BY rs.id_rs
            ORDER BY rs.nama_rs
        ");
        $rs_op = [];
        $rs_ids_op = [];
        if ($query_rs_op) {
            $query_rs_op->bind_param('i', $operator_id);
            if ($query_rs_op->execute()) {
                $result_rs = $query_rs_op->get_result();
                while ($row = $result_rs->fetch_assoc()) {
                    $rs_op[] = [
                        'id_rs' => $row['id_rs'],
                        'nama_rs' => $row['nama_rs']
                    ];
                    $rs_ids_op[] = $row['id_rs'];
                }
            }
            $query_rs_op->close();
        }
        
        // Simpan data untuk operator
        $operator_kecamatan[$operator_id] = $kecamatan_op;
        $operator_kecamatan_ids[$operator_id] = $kecamatan_ids_op;
        $operator_kua[$operator_id] = $kua_op;
        $operator_kua_ids[$operator_id] = $kua_ids_op;
        $operator_puskes[$operator_id] = $puskes_op;
        $operator_puskes_ids[$operator_id] = $puskes_ids_op;
        $operator_rs[$operator_id] = $rs_op;
        $operator_rs_ids[$operator_id] = $rs_ids_op;
        
        // Simpan data lengkap operator dengan tugas
        $operators_with_tugas[$operator_id] = [
            'nama' => $operator['nama'],
            'kecamatan' => $kecamatan_op,
            'kua' => $kua_op,
            'puskes' => $puskes_op,
            'rumahsakit' => $rs_op
        ];
    }
}

// Ambil current user ID dengan default 0 jika tidak ada
$current_user_id = $_SESSION['user_id'] ?? 0;

error_log('MANAJEMEN_OPERATOR.PHP - Total users: ' . count($users));

$csrf_token_value = function_exists('csrf_token') ? csrf_token() : '';
$csrf_input_html = function_exists('csrf_input')
    ? csrf_input()
    : '<input type="hidden" name="csrf_token" value="">';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
    <script>window.CSRF_TOKEN = '<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Pengguna - Sistem Pengaduan</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="common.css">
    <link rel="stylesheet" href="css/manajemen_operator.css">

    <!-- Data untuk JavaScript -->
    <script>
        const allKuaData = <?= json_encode($all_kua) ?>;
        const allKecamatanData = <?= json_encode($all_kecamatan) ?>;
        const allPuskesData = <?= json_encode($all_puskes) ?>;
        const allRSData = <?= json_encode($all_rs) ?>;
        const allDesaData = <?= json_encode($all_desa) ?>;
        const allPetugasData = <?= json_encode($all_petugas) ?>;
        const operatorKecamatanIds = <?= json_encode($operator_kecamatan_ids) ?>;
        const operatorKuaIds = <?= json_encode($operator_kua_ids) ?>;
        const operatorPuskesIds = <?= json_encode($operator_puskes_ids) ?>;
        const operatorRSIds = <?= json_encode($operator_rs_ids) ?>;
        const operatorsWithTugas = <?= json_encode($operators_with_tugas) ?>;
    </script>

    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-12">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan</div>
        <nav>
            <ul>
                <li><a href="dashboard_admin.php">Dashboard</a></li>
                <li class="active"><a href="manajemen_operator.php">Manajemen Pengguna</a></li>
                <li><a href="reset_password.php">Reset Password Pengguna</a></li>
                <li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li>
                <li><a href="laporan_admin.php">Laporan & Analisis</a></li><li><a href="kinerja_operator.php">Kinerja Operator</a></li>
                <li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li><a href="tanggapan_pemohon.php">Hasil Survei</a></li>
                <li><a href="whistleblowing.php">Whistleblowing</a></li>
                <li><a href="formulir.php">Formulir Kependudukan</a></li>
                <li><a href="manajemen_formulir_digital.php">Manajemen Formulir Digital</a></li><li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
                <li class="logout-item">
                    <button id="logoutBtn" class="sidebar-btn">Logout</button>
                </li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Manajemen Pengguna</div>
                <div style="color: #7f8c8d; font-size: 14px;">
                    <i class="fas fa-users-cog"></i> Kelola semua akun pengguna dan operator
                </div>
            </div>
            <div class="user-info">
                <span>Hai, <?= esc($_SESSION['nama'] ?? 'Admin') ?></span>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Cards Statistik -->
            <div class="cards">
                <div class="card card-total">
                    <h3><i class="fas fa-users"></i> Total Pengguna</h3>
                    <p><?= number_format($stats['total']) ?></p>
                </div>
                <div class="card card-admin">
                    <h3><i class="fas fa-user-shield"></i> Administrator</h3>
                    <p><?= number_format($stats['admin']) ?></p>
                </div>
                <div class="card card-operator">
                    <h3><i class="fas fa-user-tie"></i> Operator</h3>
                    <p><?= number_format($stats['operator']) ?></p>
                </div>
                <div class="card card-petugas-desa">
                    <h3><i class="fas fa-user-check"></i> Petugas Desa</h3>
                    <p><?= number_format($stats['petugas_desa']) ?></p>
                </div>
                <div class="card card-user-reguler">
                    <h3><i class="fas fa-user"></i> User Reguler</h3>
                    <p><?= number_format($stats['user_reguler']) ?></p>
                </div>
                <div class="card card-user-kua">
                    <h3><i class="fas fa-mosque"></i> User KUA</h3>
                    <p><?= number_format($stats['user_kua']) ?></p>
                </div>
                <div class="card card-user-puskes">
                    <h3><i class="fas fa-hospital"></i> User Puskesmas</h3>
                    <p><?= number_format($stats['user_puskes']) ?></p>
                </div>
                <div class="card card-user-rs">
                    <h3><i class="fas fa-hospital-alt"></i> User Rumah Sakit</h3>
                    <p><?= number_format($stats['user_rs']) ?></p>
                </div>
                <div class="card card-aktif">
                    <h3><i class="fas fa-check-circle"></i> Aktif</h3>
                    <p><?= number_format($stats['aktif']) ?></p>
                </div>
                <div class="card card-nonaktif">
                    <h3><i class="fas fa-times-circle"></i> Nonaktif</h3>
                    <p><?= number_format($stats['nonaktif']) ?></p>
                </div>
                <div class="card card-hari-ini">
                    <h3><i class="fas fa-calendar-day"></i> User Baru Hari Ini</h3>
                    <p><?= number_format($stats['aktif_hari_ini']) ?></p>
                </div>
            </div>

            <!-- Control Section -->
            <div class="control-section">
                <div class="search-filter">
                    <div class="search-box">
                        <span class="search-icon"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchInput" placeholder="Cari nama, NIK, email, KUA, Puskesmas, atau Rumah Sakit..." 
                               value="<?= esc($_GET['search'] ?? '') ?>"
                               onkeyup="handleSearch()">
                    </div>
                    
                    <select class="filter-select" id="roleFilter" onchange="handleFilter()">
                        <option value="">Semua Role</option>
                        <option value="admin" <?= (isset($_GET['role']) && $_GET['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
                        <option value="operator" <?= (isset($_GET['role']) && $_GET['role'] === 'operator') ? 'selected' : '' ?>>Operator</option>
                        <option value="petugas_desa" <?= (isset($_GET['role']) && $_GET['role'] === 'petugas_desa') ? 'selected' : '' ?>>Petugas Desa</option>
                        <option value="user" <?= (isset($_GET['role']) && $_GET['role'] === 'user') ? 'selected' : '' ?>>User</option>
                    </select>
                    
                    <select class="filter-select" id="kuaFilter" onchange="handleFilter()">
                        <option value="">Semua KUA</option>
                        <?php foreach ($all_kua as $kua): ?>
                            <option value="<?= $kua['id_kua'] ?>" 
                                <?= (isset($_GET['kua']) && $_GET['kua'] == $kua['id_kua']) ? 'selected' : '' ?>>
                                <?= esc($kua['nama_kua']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select class="filter-select" id="puskesFilter" onchange="handleFilter()">
                        <option value="">Semua Puskesmas</option>
                        <?php foreach ($all_puskes as $puskes): ?>
                            <option value="<?= $puskes['id_puskes'] ?>" 
                                <?= (isset($_GET['puskes']) && $_GET['puskes'] == $puskes['id_puskes']) ? 'selected' : '' ?>>
                                <?= esc($puskes['nama_puskes']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- TAMBAH FILTER RUMAH SAKIT -->
                    <select class="filter-select" id="rsFilter" onchange="handleFilter()">
                        <option value="">Semua Rumah Sakit</option>
                        <?php foreach ($all_rs as $rs): ?>
                            <option value="<?= $rs['id_rs'] ?>" 
                                <?= (isset($_GET['rs']) && $_GET['rs'] == $rs['id_rs']) ? 'selected' : '' ?>>
                                <?= esc($rs['nama_rs']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button class="btn-add" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Tambah Pengguna
                </button>
            </div>

            <!-- Notification Area -->
            <div id="notificationArea" style="position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 400px;"></div>

            <!-- Table Section -->
            <div class="table-section">
                <div class="table-header">
                    <h2><i class="fas fa-list"></i> Daftar Pengguna</h2>
                    <div style="color: #6c757d; font-size: 14px;">
                        Menampilkan <?= min($limit, count($users)) ?> dari <?= number_format($total_data) ?> data
                    </div>
                </div>
                
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users fa-3x" style="margin-bottom: 20px; color: #dee2e6;"></i>
                        <h4 style="color: #6c757d;">Tidak ada pengguna ditemukan</h4>
                        <p style="color: #6c757d;">Coba ubah filter pencarian atau tambahkan pengguna baru.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table id="usersTable">
                            <thead>
                                <tr>
                                    <th width="50">#</th>
                                    <th>Nama Lengkap</th>
                                    <th>NIK</th>
                                    <th>No HP</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>KUA</th>
                                    <th>Puskesmas</th>
                                    <th>Rumah Sakit</th>
                                    <th>Desa</th>
                                    <th>Petugas</th>
                                    <th>Wilayah Tugas</th>
                                    <th>Status</th>
                                    <th>Tanggal Dibuat</th>
                                    <th width="120">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $start_num = ($page - 1) * $limit + 1;
                                foreach ($users as $index => $user): 
                                ?>
                                    <tr data-id="<?= $user['id'] ?>" 
                                        data-role="<?= $user['role'] ?>"
                                        data-search="<?= strtolower(esc($user['nama'] . ' ' . $user['nik'] . ' ' . $user['email'] . ' ' . $user['nama_kua'] . ' ' . $user['nama_puskes'] . ' ' . $user['nama_rs'] . ' ' . $user['nama_desa'])) ?>">
                                        <td><?= $start_num + $index ?></td>
                                        <td><strong><?= esc($user['nama'] ?? '-') ?></strong></td>
                                        <td style="font-family: monospace;"><?= esc($user['nik'] ?? '-') ?></td>
                                        <td><?= esc($user['no_hp'] ?? '-') ?></td>
                                        <td><?= esc($user['email'] ?? '-') ?></td>
                                        <td>
                                            <?php if ($user['role'] === 'admin'): ?>
                                                <span class="badge bg-danger">Admin</span>
                                            <?php elseif ($user['role'] === 'operator'): ?>
                                                <span class="badge bg-primary">Operator</span>
                                            <?php elseif ($user['role'] === 'petugas_desa'): ?>
                                                <span class="badge bg-cyan">Petugas Desa</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">User</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['nama_kua'])): ?>
                                                <span class="badge bg-pink" title="KUA <?= esc($user['nama_kua']) ?>">
                                                    <i class="fas fa-mosque"></i> <?= esc($user['nama_kua']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['nama_puskes'])): ?>
                                                <span class="badge bg-yellow" title="Puskesmas <?= esc($user['nama_puskes']) ?>">
                                                    <i class="fas fa-hospital"></i> <?= esc($user['nama_puskes']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['nama_rs'])): ?>
                                                <span class="badge rs-tag" title="Rumah Sakit <?= esc($user['nama_rs']) ?>">
                                                    <i class="fas fa-hospital-alt"></i> <?= esc($user['nama_rs']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['nama_desa'])): ?>
                                                <span class="badge bg-brown" title="Desa <?= esc($user['nama_desa']) ?>">
                                                    <i class="fas fa-village"></i> <?= esc($user['nama_desa']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['nama_petugas'])): ?>
                                                <span class="badge bg-teal" title="Petugas <?= esc($user['nama_petugas']) ?>">
                                                    <i class="fas fa-user-check"></i> <?= esc($user['nama_petugas']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user['role'] === 'operator'): ?>
                                                <div class="tags-container">
                                                    <?php 
                                                    // Tampilkan kecamatan tugas
                                                    if (isset($operator_kecamatan[$user['id']]) && !empty($operator_kecamatan[$user['id']])): 
                                                        $kecamatan_count = count($operator_kecamatan[$user['id']]);
                                                    ?>
                                                        <?php foreach (array_slice($operator_kecamatan[$user['id']], 0, 3) as $kecamatan): ?>
                                                            <span class="badge kecamatan-tag" title="Kecamatan <?= esc($kecamatan['nama_kecamatan']) ?>">
                                                                <i class="fas fa-map-marker-alt"></i> <?= esc($kecamatan['nama_kecamatan']) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                        <?php if ($kecamatan_count > 3): ?>
                                                            <span class="badge bg-secondary" title="+<?= $kecamatan_count - 3 ?> kecamatan lainnya">
                                                                +<?= $kecamatan_count - 3 ?> kecamatan
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php 
                                                    // Tampilkan KUA tugas
                                                    if (isset($operator_kua[$user['id']]) && !empty($operator_kua[$user['id']])): 
                                                        $kua_count = count($operator_kua[$user['id']]);
                                                    ?>
                                                        <?php foreach (array_slice($operator_kua[$user['id']], 0, 2) as $kua): ?>
                                                            <span class="badge kua-tag" title="KUA <?= esc($kua['nama_kua']) ?>">
                                                                <i class="fas fa-mosque"></i> <?= esc($kua['nama_kua']) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                        <?php if ($kua_count > 2): ?>
                                                            <span class="badge bg-secondary" title="+<?= $kua_count - 2 ?> KUA lainnya">
                                                                +<?= $kua_count - 2 ?> KUA
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php 
                                                    // Tampilkan puskesmas tugas
                                                    if (isset($operator_puskes[$user['id']]) && !empty($operator_puskes[$user['id']])): 
                                                        $puskes_count = count($operator_puskes[$user['id']]);
                                                    ?>
                                                        <?php foreach (array_slice($operator_puskes[$user['id']], 0, 2) as $puskes): ?>
                                                            <span class="badge puskes-tag" title="Puskesmas <?= esc($puskes['nama_puskes']) ?>">
                                                                <i class="fas fa-hospital"></i> <?= esc($puskes['nama_puskes']) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                        <?php if ($puskes_count > 2): ?>
                                                            <span class="badge bg-secondary" title="+<?= $puskes_count - 2 ?> puskesmas lainnya">
                                                                +<?= $puskes_count - 2 ?> puskesmas
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php 
                                                    // TAMPILKAN RUMAH SAKIT TUGAS
                                                    if (isset($operator_rs[$user['id']]) && !empty($operator_rs[$user['id']])): 
                                                        $rs_count = count($operator_rs[$user['id']]);
                                                    ?>
                                                        <?php foreach (array_slice($operator_rs[$user['id']], 0, 2) as $rs): ?>
                                                            <span class="badge rs-tag" title="Rumah Sakit <?= esc($rs['nama_rs']) ?>">
                                                                <i class="fas fa-hospital-alt"></i> <?= esc($rs['nama_rs']) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                        <?php if ($rs_count > 2): ?>
                                                            <span class="badge bg-secondary" title="+<?= $rs_count - 2 ?> rumah sakit lainnya">
                                                                +<?= $rs_count - 2 ?> RS
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ((!isset($operator_kecamatan[$user['id']]) || empty($operator_kecamatan[$user['id']])) && 
                                                              (!isset($operator_kua[$user['id']]) || empty($operator_kua[$user['id']])) &&
                                                              (!isset($operator_puskes[$user['id']]) || empty($operator_puskes[$user['id']])) &&
                                                              (!isset($operator_rs[$user['id']]) || empty($operator_rs[$user['id']]))): ?>
                                                        <span class="text-muted" style="font-size: 12px;">Belum ada tugas</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($user['role'] === 'admin'): ?>
                                                <span class="text-muted" style="font-size: 12px;">Semua wilayah</span>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 12px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $is_active = (int)($user['is_active'] ?? 1);
                                            ?>
                                            <?php if ($is_active): ?>
                                                <span class="badge bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Nonaktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $created_date = $user['created_at'] ?? '';
                                            if ($created_date): 
                                                echo date('d/m/Y', strtotime($created_date));
                                            else: 
                                                echo '-';
                                            endif; 
                                            ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-outline-primary" onclick="openEditModal(<?= $user['id'] ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if ($user['id'] != $current_user_id): ?>
                                                    <button class="btn-outline-danger" 
                                                            onclick="confirmDelete(<?= $user['id'] ?>, '<?= esc($user['nama']) ?>', '<?= $user['role'] ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <!-- Previous Page -->
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">
                                        <i class="fas fa-chevron-left"></i>
                                    </span>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Page Numbers -->
                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $start_page + 4);
                            
                            if ($end_page - $start_page < 4 && $start_page > 1) {
                                $start_page = max(1, $end_page - 4);
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next Page -->
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">
                                        <i class="fas fa-chevron-right"></i>
                                    </span>
                                </li>
                            <?php endif; ?>
                        </div>
                        
                        <div class="pagination-info">
                            Halaman <?= $page ?> dari <?= $total_pages ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3><i class="fas fa-sign-out-alt"></i> Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
            <button id="cancelLogout" class="btn-no">Batal</button>
        </div>
    </div>
</div>

<!-- Modal Tambah/Edit Pengguna -->
<div class="modal-overlay" id="userModal">
    <div class="modal-box" style="max-width: 900px;">
        <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Tambah Pengguna Baru</h3>
        <form id="userForm">
            <input type="hidden" id="userId" name="id" value="">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="nama" class="form-label">Nama Lengkap <span>*</span></label>
                    <input type="text" class="form-control" id="nama" name="nama" required>
                    <small id="nama-error" class="form-text error"></small>
                </div>
                <div class="form-group">
                    <label for="role" class="form-label">Role <span>*</span></label>
                    <select class="form-control" id="role" name="role" required onchange="toggleRoleFields()">
                        <option value="user">User</option>
                        <option value="petugas_desa">Petugas Desa</option>
                        <option value="operator">Operator</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="nik" class="form-label">NIK <span>*</span></label>
                    <input type="text" class="form-control" id="nik" name="nik" 
                           maxlength="16" minlength="16" required>
                    <small class="form-text">16 digit angka</small>
                    <small id="nik-error" class="form-text error"></small>
                    <small id="nikHelp" class="form-text" style="display: none; color: #f59e0b;">
                        <i class="fas fa-info-circle"></i> NIK tidak dapat diubah
                    </small>
                </div>
                <div class="form-group">
                    <label for="no_hp" class="form-label">No HP <span>*</span></label>
                    <input type="tel" class="form-control" id="no_hp" name="no_hp" 
                           pattern="[0-9]{10,13}" maxlength="13" minlength="10" required>
                    <small class="form-text">10-13 digit angka</small>
                    <small id="nohp-error" class="form-text error"></small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email">
                    <small id="email-error" class="form-text error"></small>
                </div>
                <div class="form-group">
                    <label for="is_active" class="form-label">Status</label>
                    <select class="form-control" id="is_active" name="is_active">
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="alamat" class="form-label">Alamat</label>
                <textarea class="form-control" id="alamat" name="alamat" rows="2"></textarea>
            </div>
            
            <!-- Field KUA untuk user biasa -->
            <div class="form-group" id="kuaGroup">
                <label for="id_kua" class="form-label">KUA</label>
                <select class="form-control" id="id_kua" name="id_kua">
                    <option value="">-- Pilih KUA --</option>
                    <?php foreach ($all_kua as $kua): ?>
                        <option value="<?= $kua['id_kua'] ?>"><?= esc($kua['nama_kua']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">
                    Pilih KUA jika pengguna adalah user KUA. Kosongkan untuk user biasa.
                </small>
            </div>
            
            <!-- Field Puskesmas untuk user biasa -->
            <div class="form-group" id="puskesGroup">
                <label for="id_puskes" class="form-label">Puskesmas</label>
                <select class="form-control" id="id_puskes" name="id_puskes">
                    <option value="">-- Pilih Puskesmas --</option>
                    <?php foreach ($all_puskes as $puskes): ?>
                        <option value="<?= $puskes['id_puskes'] ?>"><?= esc($puskes['nama_puskes']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">
                    Pilih puskesmas jika pengguna adalah user puskesmas. Kosongkan untuk user biasa.
                </small>
            </div>
            
            <!-- Field Rumah Sakit untuk user biasa -->
            <div class="form-group" id="rsGroup">
                <label for="id_rs" class="form-label">Rumah Sakit</label>
                <select class="form-control" id="id_rs" name="id_rs">
                    <option value="">-- Pilih Rumah Sakit --</option>
                    <?php foreach ($all_rs as $rs): ?>
                        <option value="<?= $rs['id_rs'] ?>"><?= esc($rs['nama_rs']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">
                    Pilih rumah sakit jika pengguna adalah user rumah sakit. Kosongkan untuk user biasa.
                </small>
            </div>
            
            <!-- Field Kecamatan untuk petugas desa -->
            <div class="form-group" id="kecamatanPetugasGroup">
                <label for="id_kecamatan" class="form-label">Kecamatan</label>
                <select class="form-control" id="id_kecamatan" name="id_kecamatan">
                    <option value="">-- Pilih Kecamatan --</option>
                    <?php foreach ($all_kecamatan as $kecamatan): ?>
                        <option value="<?= $kecamatan['kecamatan_id'] ?>"><?= esc($kecamatan['nama_kecamatan']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">
                    Pilih kecamatan terlebih dahulu agar daftar desa sesuai wilayah.
                </small>
            </div>

            <!-- Field Desa untuk petugas desa -->
            <div class="form-group" id="desaGroup">
                <label for="id_desa" class="form-label">Desa</label>
                <select class="form-control" id="id_desa" name="id_desa">
                    <option value="">-- Pilih Desa --</option>
                    <?php foreach ($all_desa as $desa): ?>
                        <option value="<?= $desa['id'] ?>"><?= esc($desa['nama_desa']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">
                    Pilih desa jika pengguna adalah petugas desa.
                </small>
            </div>
            
            <!-- Field Petugas untuk user biasa -->
            <div class="form-group" id="petugasGroup">
                <label for="id_petugas" class="form-label">Petugas Desa</label>
                <select class="form-control" id="id_petugas" name="id_petugas">
                    <option value="">-- Pilih Petugas Desa --</option>
                    <?php foreach ($all_petugas as $petugas): ?>
                        <option value="<?= $petugas['id'] ?>"><?= esc($petugas['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">
                    Pilih petugas desa jika user dilayani oleh petugas desa tertentu.
                </small>
            </div>
            
            <!-- Field Tugas untuk Operator -->
            <div class="form-group" id="operatorTugasGroup" style="display: none;">
                <label class="form-label">Wilayah Tugas Operator</label>
                <small class="form-text d-block mb-2">
                    Pilih kecamatan, KUA, puskesmas, dan rumah sakit yang dapat dikelola operator ini.
                </small>
                
                <!-- Tabs untuk Kecamatan, KUA, Puskesmas, dan Rumah Sakit -->
                <div class="tabs">
                    <div class="tab active" onclick="switchTab('kecamatan')">Kecamatan</div>
                    <div class="tab" onclick="switchTab('kua')">KUA</div>
                    <div class="tab" onclick="switchTab('puskes')">Puskesmas</div>
                    <div class="tab" onclick="switchTab('rs')">Rumah Sakit</div>
                </div>
                
                <!-- Tab Content Kecamatan -->
                <div id="kecamatanTab" class="tab-content active">
                    <div class="checkbox-container">
                        <div class="checkbox-grid">
                            <?php foreach ($all_kecamatan as $kecamatan): ?>
                                <div class="checkbox-item">
                                    <div class="form-check">
                                        <input class="form-check-input kecamatan-checkbox" 
                                               type="checkbox" 
                                               name="kecamatan_tugas[]" 
                                               value="<?= $kecamatan['kecamatan_id'] ?>" 
                                               id="kec_<?= $kecamatan['kecamatan_id'] ?>">
                                        <label class="form-check-label" for="kec_<?= $kecamatan['kecamatan_id'] ?>">
                                            <?= esc($kecamatan['nama_kecamatan']) ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small id="kecamatan-error" class="form-text error"></small>
                    
                    <div style="margin-top: 15px;">
                        <button type="button" class="btn-no" onclick="selectAllKecamatan()" style="padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-check-square me-1"></i> Pilih Semua Kecamatan
                        </button>
                        <button type="button" class="btn-no" onclick="deselectAllKecamatan()" style="padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-square me-1"></i> Hapus Semua Kecamatan
                        </button>
                    </div>
                </div>
                
                <!-- Tab Content KUA -->
                <div id="kuaTab" class="tab-content">
                    <div class="checkbox-container">
                        <div class="checkbox-grid">
                            <?php foreach ($all_kua as $kua): ?>
                                <div class="checkbox-item">
                                    <div class="form-check">
                                        <input class="form-check-input kua-checkbox" 
                                               type="checkbox" 
                                               name="kua_tugas[]" 
                                               value="<?= $kua['id_kua'] ?>" 
                                               id="kua_<?= $kua['id_kua'] ?>">
                                        <label class="form-check-label" for="kua_<?= $kua['id_kua'] ?>">
                                            <i class="fas fa-mosque"></i> <?= esc($kua['nama_kua']) ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small id="kua-error" class="form-text error"></small>
                    
                    <div style="margin-top: 15px;">
                        <button type="button" class="btn-no" onclick="selectAllKua()" style="padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-check-square me-1"></i> Pilih Semua KUA
                        </button>
                        <button type="button" class="btn-no" onclick="deselectAllKua()" style="padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-square me-1"></i> Hapus Semua KUA
                        </button>
                    </div>
                </div>
                
                <!-- Tab Content Puskesmas -->
                <div id="puskesTab" class="tab-content">
                    <div class="checkbox-container">
                        <div class="checkbox-grid">
                            <?php foreach ($all_puskes as $puskes): ?>
                                <div class="checkbox-item">
                                    <div class="form-check">
                                        <input class="form-check-input puskes-checkbox" 
                                               type="checkbox" 
                                               name="puskes_tugas[]" 
                                               value="<?= $puskes['id_puskes'] ?>" 
                                               id="puskes_<?= $puskes['id_puskes'] ?>">
                                        <label class="form-check-label" for="puskes_<?= $puskes['id_puskes'] ?>">
                                            <i class="fas fa-hospital"></i> <?= esc($puskes['nama_puskes']) ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small id="puskes-error" class="form-text error"></small>
                    
                    <div style="margin-top: 15px;">
                        <button type="button" class="btn-no" onclick="selectAllPuskes()" style="padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-check-square me-1"></i> Pilih Semua Puskesmas
                        </button>
                        <button type="button" class="btn-no" onclick="deselectAllPuskes()" style="padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-square me-1"></i> Hapus Semua Puskesmas
                        </button>
                    </div>
                </div>
                
                <!-- Tab Content Rumah Sakit -->
                <div id="rsTab" class="tab-content">
                    <div class="checkbox-container">
                        <div class="checkbox-grid">
                            <?php foreach ($all_rs as $rs): ?>
                                <div class="checkbox-item">
                                    <div class="form-check">
                                        <input class="form-check-input rs-checkbox" 
                                               type="checkbox" 
                                               name="rs_tugas[]" 
                                               value="<?= $rs['id_rs'] ?>" 
                                               id="rs_<?= $rs['id_rs'] ?>">
                                        <label class="form-check-label" for="rs_<?= $rs['id_rs'] ?>">
                                            <i class="fas fa-hospital-alt"></i> <?= esc($rs['nama_rs']) ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small id="rs-error" class="form-text error"></small>
                    
                    <div style="margin-top: 15px;">
                        <button type="button" class="btn-no" onclick="selectAllRS()" style="padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-check-square me-1"></i> Pilih Semua Rumah Sakit
                        </button>
                        <button type="button" class="btn-no" onclick="deselectAllRS()" style="padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-square me-1"></i> Hapus Semua Rumah Sakit
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="form-row" id="passwordRow">
                <div class="form-group">
                    <label for="password" class="form-label">
                        Password <span id="passwordHint">*</span>
                    </label>
                    <input type="password" class="form-control" id="password" name="password">
                    <small id="passwordHelp" class="form-text"><?= htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8') ?></small>
                    <small id="password-error" class="form-text error"></small>
                </div>
                <div class="form-group">
                    <label for="confirm_password" class="form-label">
                        Konfirmasi Password <span id="confirmHint">*</span>
                    </label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                    <small id="confirmHelp" class="form-text">Harus sama dengan password</small>
                    <small id="confirm-error" class="form-text error"></small>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-no" onclick="closeUserModal()">Batal</button>
                <button type="button" class="btn-yes" id="submitBtn" onclick="saveUser()">
                    <span id="submitText">Simpan</span>
                    <span id="submitSpinner" class="fas fa-spinner fa-spin" style="display: none;"></span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Konfirmasi Hapus -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box" style="max-width: 400px;">
        <h3><i class="fas fa-trash"></i> Konfirmasi Hapus</h3>
        <p id="deleteMessage">Apakah Anda yakin ingin menghapus pengguna ini?</p>
        <div class="modal-actions">
            <button type="button" class="btn-no" onclick="closeDeleteModal()">Batal</button>
            <button type="button" class="btn-yes" onclick="deleteUser()" id="deleteBtn">
                <span>Hapus</span>
                <span id="deleteSpinner" class="fas fa-spinner fa-spin" style="display: none;"></span>
            </button>
        </div>
    </div>
</div>

<script>
// Data untuk JavaScript

// === GLOBAL VARIABLES ===

let currentUserId = null;
let currentUserName = null;
let currentUserRole = null;

// === INITIALIZATION ===
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing...');
    
    // Initialize sidebar toggle
    initSidebar();
    
    // Initialize logout modal
    initLogoutModal();
    
    // Initialize user modal
    initUserModal();
    
    // Initialize delete modal
    initDeleteModal();
    
    // Initialize form validation
    initFormValidation();

    const kecamatanSelect = document.getElementById('id_kecamatan');
    if (kecamatanSelect) {
        kecamatanSelect.addEventListener('change', function() {
            populateDesaByKecamatan(this.value, '');
        });
    }
    populateDesaByKecamatan('');
    
    console.log('Initialization complete');
});

// === SIDEBAR FUNCTIONS ===
function initSidebar() {
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
}

// === LOGOUT MODAL FUNCTIONS ===
function initLogoutModal() {
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');
    
    function showLogoutModal() {
        if (logoutModal) logoutModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function hideLogoutModal() {
        if (logoutModal) logoutModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    
    if (confirmLogout) {
        confirmLogout.addEventListener('click', function() {
            window.location.href = '../logout.php';
        });
    }
    
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    
    // Close modal when clicking outside
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) hideLogoutModal();
        });
    }
    
    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
}

// === DELETE FUNCTIONS ===
function confirmDelete(id, name, role = 'user') {
    currentUserId = id;
    currentUserName = name;
    currentUserRole = role;
    
    // Tampilkan modal konfirmasi dengan info tambahan
    let message = `Apakah Anda yakin ingin menghapus pengguna <strong>"${name}"</strong>?`;
    
    if (role === 'operator') {
        message += '<br><small class="text-danger">*Operator akan kehilangan semua akses kecamatan, KUA, puskesmas, dan rumah sakit tugas</small>';
    } else if (role === 'admin') {
        message += '<br><small class="text-danger">*Hati-hati! Admin memiliki akses penuh ke sistem</small>';
    } else if (role === 'petugas_desa') {
        message += '<br><small class="text-danger">*Petugas desa akan kehilangan akses ke data desa</small>';
    }
    
    const deleteMessage = document.getElementById('deleteMessage');
    if (deleteMessage) {
        deleteMessage.innerHTML = message;
    }
    
    const deleteModal = document.getElementById('deleteModal');
    if (deleteModal) {
        deleteModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

// === INIT DELETE MODAL ===
function initDeleteModal() {
    const deleteModal = document.getElementById('deleteModal');
    const deleteBtn = document.getElementById('deleteBtn');
    
    if (!deleteModal || !deleteBtn) {
        console.error('Delete modal elements not found');
        return;
    }
    
    // Close modal when clicking outside
    deleteModal.addEventListener('click', function(event) {
        if (event.target === deleteModal) {
            closeDeleteModal();
        }
    });
    
    // Close with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && deleteModal.classList.contains('show')) {
            closeDeleteModal();
        }
    });
}

// === CLOSE DELETE MODAL ===
function closeDeleteModal() {
    const deleteModal = document.getElementById('deleteModal');
    const deleteBtn = document.getElementById('deleteBtn');
    const deleteSpinner = document.getElementById('deleteSpinner');
    const deleteText = deleteBtn ? deleteBtn.querySelector('span:first-child') : null;
    
    if (deleteModal) deleteModal.classList.remove('show');
    document.body.style.overflow = '';
    
    // Reset button state
    if (deleteText) deleteText.style.display = 'inline-block';
    if (deleteSpinner) deleteSpinner.style.display = 'none';
    if (deleteBtn) deleteBtn.disabled = false;
    
    currentUserId = null;
    currentUserName = null;
    currentUserRole = null;
}

// === DELETE USER FUNCTION ===
function deleteUser() {
    if (!currentUserId) {
        showNotification('ID pengguna tidak valid', 'error');
        return;
    }
    
    const deleteBtn = document.getElementById('deleteBtn');
    const deleteSpinner = document.getElementById('deleteSpinner');
    const deleteText = deleteBtn.querySelector('span:first-child');
    
    if (!deleteBtn || !deleteSpinner || !deleteText) {
        console.error('Delete button elements not found');
        return;
    }
    
    // Disable button and show spinner
    deleteText.style.display = 'none';
    deleteSpinner.style.display = 'inline-block';
    deleteBtn.disabled = true;
    
    // Create form data
    const formData = new FormData();
    formData.append('id', currentUserId);
    formData.append('action', 'delete_user');
      formData.append('csrf_token', window.CSRF_TOKEN || '');
    
    // Send request
    fetch('delete_user.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        console.log('Delete response:', text);
        try {
            const data = JSON.parse(text.trim());
            if (data.success) {
                showNotification(data.message, 'success');
                
                // Reload page after delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
                
            } else {
                showNotification(data.message || 'Gagal menghapus pengguna', 'error');
                closeDeleteModal();
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            showNotification('Terjadi kesalahan saat menghapus', 'error');
            closeDeleteModal();
        }
    })
    .catch(error => {
        console.error('Delete error:', error);
        showNotification('Terjadi kesalahan: ' + error.message, 'error');
        closeDeleteModal();
    });
}

// === SEARCH AND FILTER FUNCTIONS ===
function handleSearch() {
    const searchInput = document.getElementById('searchInput');
    const debounceTimer = setTimeout(() => {
        applyFilters();
    }, 500);
    
    // Clear previous timer
    if (this.timer) clearTimeout(this.timer);
    this.timer = debounceTimer;
}

function handleFilter() {
    applyFilters();
}

function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const role = document.getElementById('roleFilter').value;
    const kua = document.getElementById('kuaFilter').value;
    const puskes = document.getElementById('puskesFilter').value;
    const rs = document.getElementById('rsFilter').value;
    
    let url = 'manajemen_operator.php?';
    const params = new URLSearchParams();
    
    if (search) params.append('search', search);
    if (role) params.append('role', role);
    if (kua) params.append('kua', kua);
    if (puskes) params.append('puskes', puskes);
    if (rs) params.append('rs', rs);
    
    // Keep page if it's not the first page
    const page = new URLSearchParams(window.location.search).get('page');
    if (page && page > 1) {
        params.append('page', 1); // Always go to first page when filtering
    }
    
    window.location.href = url + params.toString();
}

// === FORM VALIDATION ===
function initFormValidation() {
    // NIK validation (only numbers, max 16 digits)
    const nikInput = document.getElementById('nik');
    if (nikInput) {
        nikInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 16);
        });
    }
    
    // No HP validation (only numbers, max 13 digits)
    const noHpInput = document.getElementById('no_hp');
    if (noHpInput) {
        noHpInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 13);
        });
    }
}

// === USER MODAL FUNCTIONS ===
function initUserModal() {
    const userModal = document.getElementById('userModal');
    const roleSelect = document.getElementById('role');
    
    if (roleSelect) {
        roleSelect.addEventListener('change', toggleRoleFields);
    }
    
    // Close modal when clicking outside
    if (userModal) {
        userModal.addEventListener('click', function(event) {
            if (event.target === userModal) {
                closeUserModal();
            }
        });
    }
    
    // Close with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && userModal && userModal.classList.contains('show')) {
            closeUserModal();
        }
    });
}

function toggleRoleFields() {
    const role = document.getElementById('role').value;
    const kuaGroup = document.getElementById('kuaGroup');
    const puskesGroup = document.getElementById('puskesGroup');
    const rsGroup = document.getElementById('rsGroup');
    const kecamatanPetugasGroup = document.getElementById('kecamatanPetugasGroup');
    const desaGroup = document.getElementById('desaGroup');
    const petugasGroup = document.getElementById('petugasGroup');
    const operatorTugasGroup = document.getElementById('operatorTugasGroup');
    const kecamatanSelect = document.getElementById('id_kecamatan');
    const desaSelect = document.getElementById('id_desa');
    
    if (kuaGroup && puskesGroup && rsGroup && kecamatanPetugasGroup && desaGroup && petugasGroup && operatorTugasGroup) {
        if (role === 'user') {
            kuaGroup.style.display = 'block';
            puskesGroup.style.display = 'block';
            rsGroup.style.display = 'block';
            kecamatanPetugasGroup.style.display = 'none';
            desaGroup.style.display = 'none';
            petugasGroup.style.display = 'block';
            operatorTugasGroup.style.display = 'none';
            if (kecamatanSelect) kecamatanSelect.value = '';
            if (desaSelect) desaSelect.value = '';
            populateDesaByKecamatan('');
        } else if (role === 'petugas_desa') {
            kuaGroup.style.display = 'none';
            puskesGroup.style.display = 'none';
            rsGroup.style.display = 'none';
            kecamatanPetugasGroup.style.display = 'block';
            desaGroup.style.display = 'block';
            petugasGroup.style.display = 'none';
            operatorTugasGroup.style.display = 'none';
            populateDesaByKecamatan(kecamatanSelect ? kecamatanSelect.value : '');
        } else if (role === 'operator') {
            kuaGroup.style.display = 'none';
            puskesGroup.style.display = 'none';
            rsGroup.style.display = 'none';
            kecamatanPetugasGroup.style.display = 'none';
            desaGroup.style.display = 'none';
            petugasGroup.style.display = 'none';
            operatorTugasGroup.style.display = 'block';
            if (kecamatanSelect) kecamatanSelect.value = '';
            if (desaSelect) desaSelect.value = '';
            populateDesaByKecamatan('');
        } else {
            kuaGroup.style.display = 'none';
            puskesGroup.style.display = 'none';
            rsGroup.style.display = 'none';
            kecamatanPetugasGroup.style.display = 'none';
            desaGroup.style.display = 'none';
            petugasGroup.style.display = 'none';
            operatorTugasGroup.style.display = 'none';
            if (kecamatanSelect) kecamatanSelect.value = '';
            if (desaSelect) desaSelect.value = '';
            populateDesaByKecamatan('');
        }
    }
}

function populateDesaByKecamatan(kecamatanId, selectedDesaId = '') {
    const desaSelect = document.getElementById('id_desa');
    if (!desaSelect) return;

    const kecId = String(kecamatanId || '');
    const currentSelected = selectedDesaId !== '' ? String(selectedDesaId) : String(desaSelect.value || '');

    desaSelect.innerHTML = '<option value="">-- Pilih Desa --</option>';

    if (!kecId) {
        return;
    }

    const filteredDesa = allDesaData.filter(desa => String(desa.kecamatan_id) === kecId);
    filteredDesa.forEach(desa => {
        const option = document.createElement('option');
        option.value = desa.id;
        option.textContent = desa.nama_desa;
        if (String(desa.id) === currentSelected) {
            option.selected = true;
        }
        desaSelect.appendChild(option);
    });
}

function switchTab(tabName) {
    // Update tabs
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Show selected tab
    if (tabName === 'kecamatan') {
        document.querySelector('.tab[onclick="switchTab(\'kecamatan\')"]').classList.add('active');
        document.getElementById('kecamatanTab').classList.add('active');
    } else if (tabName === 'kua') {
        document.querySelector('.tab[onclick="switchTab(\'kua\')"]').classList.add('active');
        document.getElementById('kuaTab').classList.add('active');
    } else if (tabName === 'puskes') {
        document.querySelector('.tab[onclick="switchTab(\'puskes\')"]').classList.add('active');
        document.getElementById('puskesTab').classList.add('active');
    } else {
        document.querySelector('.tab[onclick="switchTab(\'rs\')"]').classList.add('active');
        document.getElementById('rsTab').classList.add('active');
    }
}

function openAddModal() {
    console.log('Opening add modal');
    const modal = document.getElementById('userModal');
    const title = document.getElementById('modalTitle');
    const submitText = document.getElementById('submitText');
    
    if (!modal || !title || !submitText) {
        console.error('User modal elements not found');
        return;
    }
    
    title.innerHTML = '<i class="fas fa-user-plus"></i> Tambah Pengguna Baru';
    submitText.textContent = 'Simpan';
    
    // Reset form
    resetUserForm();
    
    // Show modal
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

async function openEditModal(id) {
    console.log('openEditModal called with ID:', id);
    
    const modal = document.getElementById('userModal');
    const title = document.getElementById('modalTitle');
    const submitText = document.getElementById('submitText');
    
    if (!modal || !title || !submitText) {
        console.error('User modal elements not found');
        return;
    }
    
    title.innerHTML = '<i class="fas fa-edit"></i> Edit Pengguna';
    submitText.textContent = 'Update';
    
    // Reset form first
    resetUserForm();
    
    // Set user ID
    document.getElementById('userId').value = id;
    
    try {
        console.log('Fetching user data from get_user.php?id=' + id);
        
        // Load user data
        const response = await fetch(`get_user.php?id=${id}`);
        console.log('Response status:', response.status);
        
        const text = await response.text();
        console.log('Raw response:', text);
        
        const data = JSON.parse(text);
        console.log('Parsed data:', data);
        
        if (data.success) {
            const user = data.user;
            console.log('User data:', user);
            
            // Fill form fields
            document.getElementById('nama').value = user.nama || '';
            document.getElementById('nik').value = user.nik || '';
            document.getElementById('no_hp').value = user.no_hp || '';
            document.getElementById('email').value = user.email || '';
            document.getElementById('role').value = user.role || 'user';
            document.getElementById('is_active').value = user.is_active || '1';
            document.getElementById('alamat').value = user.alamat || '';
            document.getElementById('id_kua').value = user.id_kua || '';
            document.getElementById('id_puskes').value = user.id_puskes || '';
            document.getElementById('id_rs').value = user.id_rs || '';
            document.getElementById('id_kecamatan').value = user.id_kecamatan || '';
            populateDesaByKecamatan(user.id_kecamatan || '', user.id_desa || '');
            document.getElementById('id_petugas').value = user.id_petugas || '';
            
            // Make NIK read-only for edit
            const nikInput = document.getElementById('nik');
            nikInput.readOnly = true;
            const nikHelp = document.getElementById('nikHelp');
            nikHelp.style.display = 'block';
            
            // Toggle fields based on role
            toggleRoleFields();
            
            // Jika role adalah operator, load tugas kecamatan, KUA, puskesmas, dan rumah sakit
            if (user.role === 'operator') {
                console.log('Loading operator tugas data');
                
                // Clear all checkboxes first
                document.querySelectorAll('.kecamatan-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                document.querySelectorAll('.kua-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                document.querySelectorAll('.puskes-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                document.querySelectorAll('.rs-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                
                // Check selected kecamatan
                if (data.kecamatan_tugas && data.kecamatan_tugas.length > 0) {
                    data.kecamatan_tugas.forEach(kecId => {
                        const checkbox = document.getElementById(`kec_${kecId}`);
                        if (checkbox) {
                            checkbox.checked = true;
                            console.log('Checked kecamatan:', kecId);
                        } else {
                            console.warn('Checkbox not found for kecamatan:', kecId);
                        }
                    });
                }
                
                // Check selected KUA
                if (data.kua_tugas && data.kua_tugas.length > 0) {
                    data.kua_tugas.forEach(kuaId => {
                        const checkbox = document.getElementById(`kua_${kuaId}`);
                        if (checkbox) {
                            checkbox.checked = true;
                            console.log('Checked KUA:', kuaId);
                        } else {
                            console.warn('Checkbox not found for KUA:', kuaId);
                        }
                    });
                }
                
                // Check selected puskesmas
                if (data.puskes_tugas && data.puskes_tugas.length > 0) {
                    data.puskes_tugas.forEach(puskesId => {
                        const checkbox = document.getElementById(`puskes_${puskesId}`);
                        if (checkbox) {
                            checkbox.checked = true;
                            console.log('Checked puskesmas:', puskesId);
                        } else {
                            console.warn('Checkbox not found for puskesmas:', puskesId);
                        }
                    });
                }
                
                // Check selected rumah sakit
                if (data.rs_tugas && data.rs_tugas.length > 0) {
                    data.rs_tugas.forEach(rsId => {
                        const checkbox = document.getElementById(`rs_${rsId}`);
                        if (checkbox) {
                            checkbox.checked = true;
                            console.log('Checked rumah sakit:', rsId);
                        } else {
                            console.warn('Checkbox not found for rumah sakit:', rsId);
                        }
                    });
                }
            }
            
            // Change password hints for edit
            document.getElementById('passwordHint').textContent = '(kosongkan jika tidak diubah)';
            document.getElementById('confirmHint').textContent = '(kosongkan jika tidak diubah)';
            
        } else {
            console.error('API returned error:', data.message);
            showNotification('Gagal memuat data pengguna: ' + data.message, 'error');
            return;
        }
    } catch (error) {
        console.error('Error loading user data:', error);
        showNotification('Terjadi kesalahan saat memuat data: ' + error.message, 'error');
    }
    
    // Show modal
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    resetUserForm();
}

function resetUserForm() {
    const form = document.getElementById('userForm');
    if (form) {
        form.reset();
        form.querySelector('#userId').value = '';
    }
    
    // Uncheck all checkboxes
    document.querySelectorAll('.kecamatan-checkbox').forEach(cb => {
        cb.checked = false;
    });
    document.querySelectorAll('.kua-checkbox').forEach(cb => {
        cb.checked = false;
    });
    document.querySelectorAll('.puskes-checkbox').forEach(cb => {
        cb.checked = false;
    });
    document.querySelectorAll('.rs-checkbox').forEach(cb => {
        cb.checked = false;
    });
    
    // Reset role to user
    const roleSelect = document.getElementById('role');
    if (roleSelect) {
        roleSelect.value = 'user';
        toggleRoleFields();
    }
    
    // Reset tabs
    switchTab('kecamatan');
    
    // Reset NIK help text and readonly
    const nikHelp = document.getElementById('nikHelp');
    if (nikHelp) nikHelp.style.display = 'none';
    
    const nikInput = document.getElementById('nik');
    if (nikInput) nikInput.readOnly = false;

    const kecamatanSelect = document.getElementById('id_kecamatan');
    if (kecamatanSelect) {
        kecamatanSelect.value = '';
    }
    populateDesaByKecamatan('');
    
    // Reset password hints
    document.getElementById('passwordHint').textContent = '*';
    document.getElementById('confirmHint').textContent = '*';
}

function selectAllKecamatan() {
    document.querySelectorAll('.kecamatan-checkbox').forEach(cb => {
        cb.checked = true;
    });
}

function deselectAllKecamatan() {
    document.querySelectorAll('.kecamatan-checkbox').forEach(cb => {
        cb.checked = false;
    });
}

function selectAllKua() {
    document.querySelectorAll('.kua-checkbox').forEach(cb => {
        cb.checked = true;
    });
}

function deselectAllKua() {
    document.querySelectorAll('.kua-checkbox').forEach(cb => {
        cb.checked = false;
    });
}

function selectAllPuskes() {
    document.querySelectorAll('.puskes-checkbox').forEach(cb => {
        cb.checked = true;
    });
}

function deselectAllPuskes() {
    document.querySelectorAll('.puskes-checkbox').forEach(cb => {
        cb.checked = false;
    });
}

// FUNGSI BARU UNTUK RUMAH SAKIT
function selectAllRS() {
    document.querySelectorAll('.rs-checkbox').forEach(cb => {
        cb.checked = true;
    });
}

function deselectAllRS() {
    document.querySelectorAll('.rs-checkbox').forEach(cb => {
        cb.checked = false;
    });
}

// === SAVE USER FUNCTION ===
function saveUser() {
    const userId = document.getElementById('userId').value;
    const nama = document.getElementById('nama').value.trim();
    const nik = document.getElementById('nik').value.trim();
    const noHp = document.getElementById('no_hp').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const role = document.getElementById('role').value;
    const idKua = document.getElementById('id_kua').value;
    const idPuskes = document.getElementById('id_puskes').value;
    const idRS = document.getElementById('id_rs').value;
    const idKecamatan = document.getElementById('id_kecamatan').value;
    const idDesa = document.getElementById('id_desa').value;
    const idPetugas = document.getElementById('id_petugas').value;
    
    // Basic validation
    if (!nama) {
        showNotification('Nama wajib diisi', 'error');
        return;
    }
    
    if (!/^\d{16}$/.test(nik)) {
        showNotification('NIK harus 16 digit angka', 'error');
        return;
    }
    
    if (!/^\d{10,13}$/.test(noHp)) {
        showNotification('No HP harus 10-13 digit angka', 'error');
        return;
    }
    
    // For new user, password is required
    if (!userId && (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password) || !/[^a-zA-Z0-9\s]/.test(password))) {
        showNotification('Minimal 8 karakter dengan huruf besar, huruf kecil, angka, dan simbol', 'error');
        return;
    }
    
    // For existing user, password is optional but if filled, must be at least 6 chars
    if (userId && password && (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password) || !/[^a-zA-Z0-9\s]/.test(password))) {
        showNotification('Minimal 8 karakter dengan huruf besar, huruf kecil, angka, dan simbol', 'error');
        return;
    }
    
    // Password confirmation
    if (password && password !== confirmPassword) {
        showNotification('Konfirmasi password tidak cocok', 'error');
        return;
    }
    
    // Validation for petugas_desa role
    if (role === 'petugas_desa' && !idKecamatan) {
        showNotification('Kecamatan harus dipilih untuk petugas desa', 'error');
        return;
    }

    if (role === 'petugas_desa' && !idDesa) {
        showNotification('Desa harus dipilih untuk petugas desa', 'error');
        return;
    }
    
    // Show loading
    const submitBtn = document.getElementById('submitBtn');
    const submitText = document.getElementById('submitText');
    const submitSpinner = document.getElementById('submitSpinner');
    
    submitText.style.display = 'none';
    submitSpinner.style.display = 'inline-block';
    submitBtn.disabled = true;
    
    // Prepare form data
    const formData = new FormData(document.getElementById('userForm'));
    formData.append('action', userId ? 'update_user' : 'add_user');
      formData.append('csrf_token', window.CSRF_TOKEN || '');
    
    // Send request
    fetch('save_user.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        console.log('Save response:', text);
        try {
            const data = JSON.parse(text.trim());
            if (data.success) {
                showNotification(data.message, 'success');
                setTimeout(() => {
                    closeUserModal();
                    window.location.reload();
                }, 1500);
            } else {
                showNotification(data.message || 'Gagal menyimpan', 'error');
                submitText.style.display = 'inline-block';
                submitSpinner.style.display = 'none';
                submitBtn.disabled = false;
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            showNotification('Terjadi kesalahan saat menyimpan', 'error');
            submitText.style.display = 'inline-block';
            submitSpinner.style.display = 'none';
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Save error:', error);
        showNotification('Terjadi kesalahan: ' + error.message, 'error');
        submitText.style.display = 'inline-block';
        submitSpinner.style.display = 'none';
        submitBtn.disabled = false;
    });
}

// === NOTIFICATION FUNCTION ===
function showNotification(message, type = 'success') {
    const notificationArea = document.getElementById('notificationArea');
    
    if (!notificationArea) {
        console.error('Notification area not found');
        return;
    }
    
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.style.cssText = `
        padding: 15px 20px;
        margin-bottom: 15px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        display: flex;
        justify-content: space-between;
        align-items: center;
        animation: slideInRight 0.3s ease;
        ${type === 'success' ? 'background: #28a745;' : ''}
        ${type === 'error' ? 'background: #dc3545;' : ''}
        ${type === 'warning' ? 'background: #ffc107; color: #333;' : ''}
        ${type === 'info' ? 'background: #17a2b8;' : ''}
    `;
    
    notification.innerHTML = `
        <div>${message}</div>
        <button class="close-btn" onclick="this.parentElement.remove()" style="
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            margin-left: 15px;
        ">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    notificationArea.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}
</script>
</body>
</html>
