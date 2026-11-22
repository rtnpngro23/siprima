<?php
require_once __DIR__ . '/../error_handler.php';

// Debug untuk melihat session SEBELUM session_start
error_log('=== DASHBOARD_ADMIN.PHP STARTING ===');
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
error_log('Session id_user: ' . ($_SESSION['id_user'] ?? 'NOT SET'));
error_log('Session role: ' . ($_SESSION['role'] ?? 'NOT SET'));

include __DIR__ . '/../../app/config/koneksi.php';
include __DIR__ . '/../../app/middleware/admin_only.php';

$current_admin_id = (int)($_SESSION['id_user'] ?? ($_SESSION['user_id'] ?? 0));
$csrf_token_value = function_exists('csrf_token') ? csrf_token() : '';
$csrf_input_html = function_exists('csrf_input')
    ? csrf_input()
    : '<input type="hidden" name="csrf_token" value="">';

// Fungsi helper untuk escape output
if (!function_exists('esc')) {
    function esc($string) {
        return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
    }
}

// === AMBIL STATISTIK USER ===
$stats = [
    'total' => 0,
    'admin' => 0,
    'operator' => 0,
    'user' => 0,
    'aktif_hari_ini' => 0
];

// Hitung total user per role
$query_stats = $koneksi->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin,
        SUM(CASE WHEN role = 'operator' THEN 1 ELSE 0 END) as operator,
        SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) as user
    FROM tb_users
");
$query_stats->execute();
$result_stats = $query_stats->get_result();

if ($row = $result_stats->fetch_assoc()) {
    $stats['total'] = (int)$row['total'];
    $stats['admin'] = (int)$row['admin'];
    $stats['operator'] = (int)$row['operator'];
    $stats['user'] = (int)$row['user'];
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

// === PAGINATION CONFIG ===
$items_per_page = 10; // Jumlah data per halaman
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;

// === AMBIL DATA PENGGUNA UNTUK TABEL ===
$users = [];
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';

// Hitung total data untuk pagination
$count_query = "SELECT COUNT(*) as total FROM tb_users";
$count_conditions = [];
$count_params = [];
$count_types = '';

if (!empty($search)) {
    $count_conditions[] = "(nama LIKE ? OR email LIKE ? OR nik LIKE ?)";
    $search_param = "%$search%";
    $count_params = array_merge($count_params, [$search_param, $search_param, $search_param]);
    $count_types .= 'sss';
}

if (!empty($role_filter) && in_array($role_filter, ['admin', 'operator', 'user'])) {
    $count_conditions[] = "role = ?";
    $count_params[] = $role_filter;
    $count_types .= 's';
}

if (!empty($count_conditions)) {
    $count_query .= " WHERE " . implode(" AND ", $count_conditions);
}

$count_stmt = $koneksi->prepare($count_query);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_items = $count_result->fetch_assoc()['total'] ?? 0;
$count_stmt->close();

// Hitung total halaman
$total_pages = ceil($total_items / $items_per_page);

// Query untuk mengambil data dengan pagination
$data_query = "SELECT id, nama, nik, email, role, alamat, no_hp, created_at FROM tb_users";
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(nama LIKE ? OR email LIKE ? OR nik LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= 'sss';
}

if (!empty($role_filter) && in_array($role_filter, ['admin', 'operator', 'user'])) {
    $where_conditions[] = "role = ?";
    $params[] = $role_filter;
    $types .= 's';
}

if (!empty($where_conditions)) {
    $data_query .= " WHERE " . implode(" AND ", $where_conditions);
}

$data_query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= 'ii';

$query_users = $koneksi->prepare($data_query);
if (!empty($params)) {
    $query_users->bind_param($types, ...$params);
}
$query_users->execute();
$result_users = $query_users->get_result();

while ($user = $result_users->fetch_assoc()) {
    $users[] = $user;
}

// === PROSES FORM ACTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_user') {
        // Tambah user baru
        $nama = $_POST['nama'] ?? '';
        $nik = $_POST['nik'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $alamat = $_POST['alamat'] ?? '';
        $no_hp = $_POST['no_hp'] ?? '';
        
        // Debug: Tampilkan data yang diterima
        error_log('User creation request: ' . siprima_request_id());
        
        // Validasi
        $errors = [];
        
        if (empty($nama)) $errors[] = 'Nama wajib diisi';
        if (empty($nik)) $errors[] = 'NIK wajib diisi';
        if (empty($email)) $errors[] = 'Email wajib diisi';
        if (empty($password)) $errors[] = 'Password wajib diisi';
        
        if (!empty($nik) && !preg_match("/^[0-9]{16}$/", $nik)) {
            $errors[] = 'NIK harus 16 digit angka';
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid';
        }
        
        if (!empty($password) && ($passwordError = siprima_password_error($password)) !== null) {
            $errors[] = $passwordError;
        }
        
        if (empty($errors)) {
            // Cek duplikasi
            $check = $koneksi->prepare("SELECT id FROM tb_users WHERE email = ? OR nik = ?");
            $check->bind_param("ss", $email, $nik);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $_SESSION['error_message'] = 'Email atau NIK sudah terdaftar';
            } else {
                // Hash password dan simpan
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $insert = $koneksi->prepare("
                    INSERT INTO tb_users (nama, nik, email, password, role, alamat, no_hp, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert->bind_param("sssssss", $nama, $nik, $email, $hashed_password, $role, $alamat, $no_hp);
                
                if ($insert->execute()) {
                    $_SESSION['success_message'] = 'Pengguna berhasil ditambahkan';
                } else {
                    $_SESSION['error_message'] = 'Gagal menambahkan pengguna: ' . $insert->error;
                    error_log("Error insert: " . $insert->error);
                }
                $insert->close();
            }
            $check->close();
        } else {
            $_SESSION['error_message'] = implode('<br>', $errors);
        }
        
        header('Location: dashboard_admin.php');
        exit;
        
    } elseif ($action === 'delete_user') {
        // Hapus user
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            // Jangan izinkan menghapus diri sendiri
            if ($id == $current_admin_id) {
                $_SESSION['error_message'] = 'Tidak dapat menghapus akun sendiri';
            } else {
                $delete = $koneksi->prepare("DELETE FROM tb_users WHERE id = ?");
                $delete->bind_param("i", $id);
                
                if ($delete->execute()) {
                    $_SESSION['success_message'] = 'Pengguna berhasil dihapus';
                } else {
                    $_SESSION['error_message'] = 'Gagal menghapus pengguna: ' . $delete->error;
                }
                $delete->close();
            }
        }
        
        header('Location: dashboard_admin.php');
        exit;
        
    } elseif ($action === 'change_role') {
        // Ubah role user
        $id = (int)($_POST['id'] ?? 0);
        $new_role = $_POST['role'] ?? '';
        
        if ($id > 0 && in_array($new_role, ['admin', 'operator', 'user'])) {
            // Jangan izinkan mengubah role diri sendiri
            if ($id == $current_admin_id) {
                $_SESSION['error_message'] = 'Tidak dapat mengubah role akun sendiri';
            } else {
                $update = $koneksi->prepare("UPDATE tb_users SET role = ? WHERE id = ?");
                $update->bind_param("si", $new_role, $id);
                
                if ($update->execute()) {
                    $_SESSION['success_message'] = 'Role pengguna berhasil diubah';
                } else {
                    $_SESSION['error_message'] = 'Gagal mengubah role pengguna: ' . $update->error;
                }
                $update->close();
            }
        }
        
        header('Location: dashboard_admin.php');
        exit;
    }
}

// Flash messages
$flash_success = $_SESSION['success_message'] ?? null;
$flash_error = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
    <script>window.CSRF_TOKEN = '<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Sistem Pengaduan</title>
    <link rel="icon" type="image/webp" href="../assets/prima.webp">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard_admin.css">
    <link rel="stylesheet" href="common.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar SAMA dengan dashboard_user -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan</div>
        <nav>
            <ul>
                <li class="active"><a href="dashboard_admin.php">Dashboard</a></li>
                <li><a href="manajemen_operator.php">Manajemen Pengguna</a></li>
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
                <div class="title">Dashboard Admin</div>
                <div style="color: #7f8c8d; font-size: 14px;">
                    <i class="fas fa-users"></i> Manajemen Pengguna
                </div>
            </div>
            <div class="user-info">
                <span>Hai, <?= esc($_SESSION['nama']) ?></span>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <?php if ($flash_success): ?>
                <div class="notification success" id="notification">
                    <span><?= esc($flash_success) ?></span>
                    <button class="close-btn" onclick="closeNotification()">×</button>
                </div>
            <?php endif; ?>
            
            <?php if ($flash_error): ?>
                <div class="notification error" id="notification">
                    <span><?= esc($flash_error) ?></span>
                    <button class="close-btn" onclick="closeNotification()">×</button>
                </div>
            <?php endif; ?>

            <!-- Cards Statistik -->
            <div class="cards">
                <div class="card">
                    <h3>Total Pengguna</h3>
                    <p><?= number_format($stats['total']) ?></p>
                </div>
                <div class="card card-admin">
                    <h3>Administrator</h3>
                    <p><?= number_format($stats['admin']) ?></p>
                </div>
                <div class="card card-operator">
                    <h3>Operator</h3>
                    <p><?= number_format($stats['operator']) ?></p>
                </div>
                <div class="card card-user">
                    <h3>User Reguler</h3>
                    <p><?= number_format($stats['user']) ?></p>
                </div>
            </div>

            <!-- Control Section -->
            <div class="control-section">
                <div class="search-filter">
                    <div class="search-box">
                        <span class="search-icon"><i class="fas fa-search"></i></span>
                        <form method="GET" style="display: inline;">
                            <input type="text" 
                                   name="search" 
                                   placeholder="Cari nama, email, atau NIK..." 
                                   value="<?= esc($search) ?>"
                                   onkeyup="if(event.key === 'Enter') this.form.submit()">
                            <?php if ($role_filter): ?>
                                <input type="hidden" name="role" value="<?= esc($role_filter) ?>">
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <form method="GET" class="filter-form">
                        <select name="role" class="filter-select" onchange="this.form.submit()">
                            <option value="">Semua Role</option>
                            <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="operator" <?= $role_filter === 'operator' ? 'selected' : '' ?>>Operator</option>
                            <option value="user" <?= $role_filter === 'user' ? 'selected' : '' ?>>User</option>
                        </select>
                        <?php if ($search): ?>
                            <input type="hidden" name="search" value="<?= esc($search) ?>">
                        <?php endif; ?>
                    </form>
                </div>
                
                <button class="btn-add" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Tambah Pengguna
                </button>
            </div>

            <!-- Table Section -->
            <div class="table-section">
                <div class="table-header">
                    <h2><i class="fas fa-list"></i> Daftar Pengguna</h2>
                    <div style="color: #6c757d; font-size: 14px;">
                        Menampilkan <?= count($users) ?> dari <?= number_format($total_items) ?> data
                    </div>
                </div>
                
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users fa-3x" style="margin-bottom: 20px; color: #dee2e6;"></i>
                        <h4 style="color: #6c757d;">Tidak ada pengguna ditemukan</h4>
                        <p style="color: #6c757d;">Tidak ada data pengguna dengan filter yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th width="50">#</th>
                                    <th>Nama</th>
                                    <th>NIK</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Alamat</th>
                                    <th>No HP</th>
                                    <th>Tanggal Daftar</th>
                                    <th width="150">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = $offset + 1; 
                                foreach ($users as $user): 
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= esc($user['nama']) ?></strong></td>
                                        <td style="font-family: monospace;"><?= esc($user['nik']) ?></td>
                                        <td><?= esc($user['email']) ?></td>
                                        <td>
                                            <?php if ($user['role'] === 'admin'): ?>
                                                <span class="badge badge-admin">Admin</span>
                                            <?php elseif ($user['role'] === 'operator'): ?>
                                                <span class="badge badge-operator">Operator</span>
                                            <?php else: ?>
                                                <span class="badge badge-user">User</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= esc($user['alamat']) ?></td>
                                        <td><?= esc($user['no_hp']) ?></td>
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
                                                <form method="POST" class="role-form">
<?php echo $csrf_input_html; ?>
                                                    <input type="hidden" name="action" value="change_role">
                                                    <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                                    <select name="role" class="role-select" onchange="confirmRoleChange(this)">
                                                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                        <option value="operator" <?= $user['role'] === 'operator' ? 'selected' : '' ?>>Operator</option>
                                                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                                    </select>
                                                </form>
                                                
                                                <form method="POST" class="delete-form" onsubmit="return confirmDeleteUser(event)">
<?php echo $csrf_input_html; ?>
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                                    <button type="submit" class="btn-delete" title="Hapus">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
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
                            <div class="pagination-info">
                                Halaman <?= $current_page ?> dari <?= $total_pages ?> | 
                                Data <?= $offset + 1 ?> - <?= min($offset + $items_per_page, $total_items) ?> dari <?= number_format($total_items) ?>
                            </div>
                            
                            <!-- First Page -->
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" 
                               class="page-link <?= $current_page == 1 ? 'disabled' : '' ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            
                            <!-- Previous Page -->
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $current_page - 1)])) ?>" 
                               class="page-link <?= $current_page == 1 ? 'disabled' : '' ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                            
                            <?php
                            // Tampilkan beberapa halaman sekitar halaman aktif
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            // Jika di awal, perluas ke kanan
                            if ($start_page == 1) {
                                $end_page = min($total_pages, 5);
                            }
                            
                            // Jika di akhir, perluas ke kiri
                            if ($end_page == $total_pages) {
                                $start_page = max(1, $total_pages - 4);
                            }
                            
                            // Tampilkan halaman pertama jika tidak termasuk
                            if ($start_page > 1) {
                                echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '" class="page-link">1</a>';
                                if ($start_page > 2) {
                                    echo '<span class="page-link dots">...</span>';
                                }
                            }
                            
                            // Tampilkan range halaman
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $active_class = ($i == $current_page) ? 'active' : '';
                                echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . '" class="page-link ' . $active_class . '">' . $i . '</a>';
                            }
                            
                            // Tampilkan halaman terakhir jika tidak termasuk
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<span class="page-link dots">...</span>';
                                }
                                echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '" class="page-link">' . $total_pages . '</a>';
                            }
                            ?>
                            
                            <!-- Next Page -->
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($total_pages, $current_page + 1)])) ?>" 
                               class="page-link <?= $current_page == $total_pages ? 'disabled' : '' ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            
                            <!-- Last Page -->
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>" 
                               class="page-link <?= $current_page == $total_pages ? 'disabled' : '' ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- Modal Logout - SAMA dengan dashboard_user -->
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

<!-- Modal Tambah User -->
<div class="modal-overlay" id="addModal">
    <div class="modal-box">
        <h3><i class="fas fa-user-plus"></i> Tambah Pengguna Baru</h3>
        <form method="POST" id="addUserForm" onsubmit="return validateAddUser(event)">
<?php echo $csrf_input_html; ?>
            <input type="hidden" name="action" value="add_user">
            
            <div class="form-group">
                <label for="nama" class="form-label">Nama Lengkap <span>*</span></label>
                <input type="text" class="form-control" id="nama" name="nama" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="nik" class="form-label">NIK <span>*</span></label>
                    <input type="text" class="form-control" id="nik" name="nik" maxlength="16" required 
                           pattern="[0-9]{16}" title="NIK harus 16 digit angka">
                    <small class="form-text">16 digit angka</small>
                    <small id="nik-error" class="form-text error" style="display: none;"></small>
                </div>
                <div class="form-group">
                    <label for="email" class="form-label">Email <span>*</span></label>
                    <input type="email" class="form-control" id="email" name="email" required>
                    <small id="email-error" class="form-text error" style="display: none;"></small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="password" class="form-label">Password <span>*</span></label>
                    <input type="password" class="form-control" id="password" name="password" required minlength="8" maxlength="72" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9\s]).{8,}">
                    <small><?= htmlspecialchars(SIPRIMA_PASSWORD_HELP, ENT_QUOTES, 'UTF-8') ?></small>
                    <small class="form-text">Minimal 6 karakter</small>
                    <small id="password-error" class="form-text error" style="display: none;"></small>
                </div>
                <div class="form-group">
                    <label for="role" class="form-label">Role <span>*</span></label>
                    <select class="form-control" id="role" name="role" required>
                        <option value="user">User</option>
                        <option value="operator">Operator</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="alamat" class="form-label">Alamat</label>
                    <textarea class="form-control" id="alamat" name="alamat" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label for="no_hp" class="form-label">No HP</label>
                    <input type="text" class="form-control" id="no_hp" name="no_hp">
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-no" onclick="closeAddModal()">Batal</button>
                <button type="submit" class="btn-yes">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
const CURRENT_SEARCH = <?= json_encode((string)$search, JSON_UNESCAPED_UNICODE) ?>;
const CURRENT_ROLE_FILTER = <?= json_encode((string)$role_filter, JSON_UNESCAPED_UNICODE) ?>;

function ensureCsrfToken(formData) {
    const existingToken = formData.get('csrf_token');
    if (typeof existingToken === 'string' && existingToken.trim() !== '') {
        return;
    }

    const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const token = (window.CSRF_TOKEN || metaToken || '').trim();
    if (token !== '') {
        formData.set('csrf_token', token);
    }
}

// Toggle Sidebar Menu on Mobile - SAMA dengan dashboard_user
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
    
    // Logout Modal - SAMA dengan dashboard_user
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
    
    // Store original role values for reset
    document.querySelectorAll('.role-select').forEach(select => {
        select.dataset.originalValue = select.value;
    });
});

// Modal Add User
const addModal = document.getElementById('addModal');
const addUserForm = document.getElementById('addUserForm');

function openAddModal() {
    addModal.classList.add('show');
    document.body.style.overflow = 'hidden';
    addUserForm.reset();
    // Clear error messages
    document.querySelectorAll('.form-text.error').forEach(el => {
        el.style.display = 'none';
        el.textContent = '';
    });
}

function closeAddModal() {
    addModal.classList.remove('show');
    document.body.style.overflow = '';
    addUserForm.reset();
    // Clear error messages
    document.querySelectorAll('.form-text.error').forEach(el => {
        el.style.display = 'none';
        el.textContent = '';
    });
}

// Close add modal when clicking outside or pressing ESC
addModal.addEventListener('click', function(event) {
    if (event.target === addModal) {
        closeAddModal();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && addModal.classList.contains('show')) {
        closeAddModal();
    }
});

// Real-time validation
document.getElementById('nik').addEventListener('input', function() {
    const nik = this.value;
    const errorEl = document.getElementById('nik-error');
    
    if (nik.length > 0 && !/^\d+$/.test(nik)) {
        errorEl.textContent = 'NIK hanya boleh berisi angka';
        errorEl.style.display = 'block';
    } else if (nik.length > 0 && nik.length !== 16) {
        errorEl.textContent = 'NIK harus 16 digit';
        errorEl.style.display = 'block';
    } else {
        errorEl.style.display = 'none';
    }
});

document.getElementById('email').addEventListener('blur', function() {
    const email = this.value;
    const errorEl = document.getElementById('email-error');
    
    if (email.length > 0 && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errorEl.textContent = 'Format email tidak valid';
        errorEl.style.display = 'block';
    } else {
        errorEl.style.display = 'none';
    }
});

document.getElementById('password').addEventListener('input', function() {
    const password = this.value;
    const errorEl = document.getElementById('password-error');
    
    if (password.length > 0 && (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password) || !/[^a-zA-Z0-9\s]/.test(password))) {
        errorEl.textContent = 'Minimal 8 karakter dengan huruf besar, huruf kecil, angka, dan simbol';
        errorEl.style.display = 'block';
    } else {
        errorEl.style.display = 'none';
    }
});

// Validate add user form
function validateAddUser(event) {
    event.preventDefault();
    
    const form = document.getElementById('addUserForm');
    const nama = form.querySelector('#nama');
    const nik = form.querySelector('#nik');
    const email = form.querySelector('#email');
    const password = form.querySelector('#password');
    
    let isValid = true;
    
    // Clear previous errors
    document.querySelectorAll('.form-text.error').forEach(el => {
        el.style.display = 'none';
        el.textContent = '';
    });
    
    // Validate NIK
    if (!nik.value.match(/^\d{16}$/)) {
        document.getElementById('nik-error').textContent = 'NIK harus 16 digit angka';
        document.getElementById('nik-error').style.display = 'block';
        nik.focus();
        isValid = false;
    }
    
    // Validate email
    if (!email.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        document.getElementById('email-error').textContent = 'Format email tidak valid';
        document.getElementById('email-error').style.display = 'block';
        if (isValid) email.focus();
        isValid = false;
    }
    
    // Validate password
    if (password.value.length < 8 || !/[A-Z]/.test(password.value) || !/[a-z]/.test(password.value) || !/[0-9]/.test(password.value) || !/[^a-zA-Z0-9\s]/.test(password.value)) {
        document.getElementById('password-error').textContent = 'Minimal 8 karakter dengan huruf besar, huruf kecil, angka, dan simbol';
        document.getElementById('password-error').style.display = 'block';
        if (isValid) password.focus();
        isValid = false;
    }
    
    if (isValid) {
        // Show loading state
        const submitBtn = form.querySelector('.btn-yes');
        const originalText = submitBtn.textContent;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
        submitBtn.disabled = true;
        
        // Submit form via AJAX
        const formData = new FormData(form);
        ensureCsrfToken(formData);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.redirected) {
                window.location.href = response.url;
            } else {
                return response.text();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Terjadi kesalahan. Silakan coba lagi.');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        });
    }
    
    return false; // Prevent default form submission
}

// Confirm role change
function confirmRoleChange(select) {
    const newRole = select.options[select.selectedIndex].text;
    if (confirm(`Yakin ingin mengubah role menjadi ${newRole}?`)) {
        // Show loading state
        const originalValue = select.value;
        select.disabled = true;
        
        // Submit form
        const form = select.closest('form');
        const formData = new FormData(form);
        ensureCsrfToken(formData);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.redirected) {
                window.location.href = response.url;
            } else {
                select.disabled = false;
                alert('Terjadi kesalahan. Silakan coba lagi.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            select.disabled = false;
            alert('Terjadi kesalahan. Silakan coba lagi.');
        });
    } else {
        // Reset to original value
        select.value = select.dataset.originalValue;
    }
}

// Confirm delete user
function confirmDeleteUser(event) {
    event.preventDefault();
    
    const form = event.target.closest('form');
    const userId = form.querySelector('input[name="id"]').value;
    
    if (confirm('Yakin ingin menghapus pengguna ini? Tindakan ini tidak dapat dibatalkan.')) {
        // Show loading state
        const deleteBtn = form.querySelector('.btn-delete');
        const originalHtml = deleteBtn.innerHTML;
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        deleteBtn.disabled = true;
        
        // Submit form
        const formData = new FormData(form);
        ensureCsrfToken(formData);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.redirected) {
                window.location.href = response.url;
            } else {
                deleteBtn.innerHTML = originalHtml;
                deleteBtn.disabled = false;
                alert('Terjadi kesalahan. Silakan coba lagi.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            deleteBtn.innerHTML = originalHtml;
            deleteBtn.disabled = false;
            alert('Terjadi kesalahan. Silakan coba lagi.');
        });
    }
    
    return false;
}

// Close notification
function closeNotification() {
    const notification = document.getElementById('notification');
    if (notification) {
        notification.style.display = 'none';
    }
}

// Auto close notification after 5 seconds
setTimeout(() => {
    closeNotification();
}, 5000);

// Preserve search and filter parameters in pagination links
document.addEventListener('DOMContentLoaded', function() {
    // Update pagination links to preserve search and filter
    document.querySelectorAll('.pagination a').forEach(link => {
        const url = new URL(link.href);
        const params = new URLSearchParams(url.search);
        
        // Add current search and filter if not already present
        if (!params.has('search') && CURRENT_SEARCH) {
            params.set('search', CURRENT_SEARCH);
        }
        if (!params.has('role') && CURRENT_ROLE_FILTER) {
            params.set('role', CURRENT_ROLE_FILTER);
        }
        
        // Update the href
        link.href = url.pathname + '?' + params.toString();
    });
});
</script>

</body>
</html>
