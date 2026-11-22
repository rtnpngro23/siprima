<?php
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_laporan_peristiwa LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// Cek apakah ID laporan diberikan
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: status_permohonan.php?error=no_id');
    exit;
}

$laporan_id = (int)$_GET['id'];

// Query untuk ambil data laporan
$query = "SELECT 
    id_lapor,
    id_kecamatan,
    id_desa,
    nama_pelapor,
    nik_pelapor,
    no_hp,
    email,
    nama_kecamatan, 
    nama_desa,
    jenis_peristiwa,
    nama_subjek,
    nik_subjek,
    tempat_lahir_mati,
    tanggal_lahir_mati,
    nama_ayah,
    nama_ibu,
    keterangan,
    dokumen, 
    status,
    alasan,
    nama_folder,
    created_at
    FROM tb_laporan_peristiwa 
    WHERE id_lapor = ? AND id_user = ?";

$stmt = $koneksi->prepare($query);
if (!$stmt) {
    die("Prepare failed: " . $koneksi->error);
}

$stmt->bind_param('ii', $laporan_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header('Location: status_permohonan.php?error=not_found');
    exit;
}

$laporan = $res->fetch_assoc();

// Hanya izinkan edit saat status diajukan atau ditolak
if (!in_array(strtolower((string)$laporan['status']), ['diajukan', 'ditolak'], true)) {
    header('Location: status_permohonan.php?error=cannot_edit');
    exit;
}

// Dekode data JSON dokumen
$dokumen_data = [];
if (!empty($laporan['dokumen']) && $laporan['dokumen'] !== 'null' && $laporan['dokumen'] !== '') {
    $decoded = json_decode($laporan['dokumen'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $dokumen_data = $decoded;
    }
}

// Ambil data kecamatan dan desa untuk dropdown
$kecamatan_options = [];
$desa_options = [];

$kecamatan_query = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
if ($kecamatan_query) {
    while ($kec = $kecamatan_query->fetch_assoc()) {
        $kecamatan_options[] = $kec;
    }
}

// Jika ada id_kecamatan, ambil desa berdasarkan kecamatan
if (!empty($laporan['id_kecamatan'])) {
    $desa_query = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
    if ($desa_query) {
        $desa_query->bind_param('i', $laporan['id_kecamatan']);
        $desa_query->execute();
        $desa_result = $desa_query->get_result();
        while ($desa = $desa_result->fetch_assoc()) {
            $desa_options[] = $desa;
        }
        $desa_query->close();
    }
}

// ================================================
// BAGIAN UTAMA: PROSES FORM SUBMISSION
// ================================================

$errors = [];
$success = false;

// Gunakan folder yang sudah ada dari data lama
$folder_name = $laporan['nama_folder'] ?? '';

// Path folder uploads berdasarkan nama folder yang sudah ada
$base_upload_dir = __DIR__ . '/../../uploads/laporan_peristiwa/';
$user_folder_path = '';

// Jika ada nama folder dari data lama, gunakan folder tersebut
if (!empty($folder_name)) {
    $user_folder_path = $base_upload_dir . $folder_name . '/';
    
    // Buat folder jika belum ada
    if (!is_dir($user_folder_path)) {
        mkdir($user_folder_path, 0755, true);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi input
    $nama_pelapor = trim($_POST['nama_pelapor'] ?? '');
    $nik_pelapor = trim($_POST['nik_pelapor'] ?? '');
    $no_hp = trim($_POST['no_hp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $id_kecamatan = (int)($_POST['id_kecamatan'] ?? 0);
    $id_desa = (int)($_POST['id_desa'] ?? 0);
    $jenis_peristiwa = trim($_POST['jenis_peristiwa'] ?? '');
    $nama_subjek = trim($_POST['nama_subjek'] ?? '');
    $nik_subjek = trim($_POST['nik_subjek'] ?? '');
    $tempat_lahir_mati = trim($_POST['tempat_lahir_mati'] ?? '');
    $tanggal_lahir_mati = trim($_POST['tanggal_lahir_mati'] ?? '');
    $nama_ayah = trim($_POST['nama_ayah'] ?? '');
    $nama_ibu = trim($_POST['nama_ibu'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi
    if (empty($nama_pelapor)) $errors[] = "Nama pelapor harus diisi";
    if (empty($nik_pelapor)) $errors[] = "NIK pelapor harus diisi";
    if (empty($no_hp)) $errors[] = "No HP harus diisi";
    if (empty($email)) $errors[] = "Email harus diisi";
    if (empty($id_kecamatan)) $errors[] = "Kecamatan harus dipilih";
    if (empty($id_desa)) $errors[] = "Desa harus dipilih";
    if (empty($jenis_peristiwa)) $errors[] = "Jenis peristiwa harus dipilih";
    if (empty($nama_subjek)) $errors[] = "Nama subjek harus diisi";
    if (empty($tempat_lahir_mati)) $errors[] = "Tempat peristiwa harus diisi";
    if (empty($tanggal_lahir_mati)) $errors[] = "Tanggal peristiwa harus diisi";
    if (empty($nama_ayah)) $errors[] = "Nama ayah harus diisi";
    if (empty($nama_ibu)) $errors[] = "Nama ibu harus diisi";
    
    // Validasi NIK jika diisi
    if (!empty($nik_subjek) && !preg_match('/^[0-9]{16}$/', $nik_subjek)) {
        $errors[] = "NIK subjek harus 16 digit angka atau dikosongkan";
    }
    
    // Validasi tanggal tidak lebih dari hari ini
    if (!empty($tanggal_lahir_mati)) {
        $today = date('Y-m-d');
        if ($tanggal_lahir_mati > $today) {
            $errors[] = "Tanggal peristiwa tidak boleh lebih dari hari ini";
        }
    }
    
    // Ambil nama kecamatan dan desa untuk folder name
    $nama_kecamatan_db = '';
    $nama_desa_db = '';
    
    if (!empty($id_kecamatan)) {
        $qk = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        if ($qk) {
            $qk->bind_param('i', $id_kecamatan);
            $qk->execute();
            $qk->bind_result($nama_kecamatan_db);
            $qk->fetch();
            $qk->close();
        }
    }
    
    if (!empty($id_desa)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        if ($qd) {
            $qd->bind_param('i', $id_desa);
            $qd->execute();
            $qd->bind_result($nama_desa_db);
            $qd->fetch();
            $qd->close();
        }
    }
    
    // ================================================
    // BAGIAN UPLOAD FILE KE FOLDER YANG SUDAH ADA
    // ================================================
    
    // Jika folder belum ada, buat folder baru
    if (empty($folder_name)) {
        // Fungsi untuk membersihkan nama folder
        function sanitizeFolderName($string) {
            if (empty($string)) return '';
            $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', $string);
            $string = preg_replace('/\s+/', '_', trim($string));
            return $string;
        }
        
        // Buat nama folder baru
        $timestamp = date('Ymd_His');
        $kec_sanitized = sanitizeFolderName($nama_kecamatan_db);
        $desa_sanitized = sanitizeFolderName($nama_desa_db);
        $pemohon_sanitized = sanitizeFolderName($nama_pelapor);
        $subjek_sanitized = sanitizeFolderName($nama_subjek);
        
        $folder_name = $kec_sanitized . '_' . 
                      $desa_sanitized . '_' . 
                      $nik_pelapor . '_' . 
                      $pemohon_sanitized . '_' . 
                      $subjek_sanitized . '_' . 
                      $timestamp;
        
        if (strlen($folder_name) > 200) {
            $max_subjek_length = 200 - (strlen($kec_sanitized) + strlen($desa_sanitized) + 
                                       strlen($nik_pelapor) + strlen($pemohon_sanitized) + 
                                       strlen($timestamp) + 5);
            
            if ($max_subjek_length > 10) {
                $subjek_sanitized = substr($subjek_sanitized, 0, $max_subjek_length);
                $folder_name = $kec_sanitized . '_' . 
                              $desa_sanitized . '_' . 
                              $nik_pelapor . '_' . 
                              $pemohon_sanitized . '_' . 
                              $subjek_sanitized . '_' . 
                              $timestamp;
            } else {
                $folder_hash = substr(md5($folder_name), 0, 8);
                $folder_name = $kec_sanitized . '_' . 
                              $desa_sanitized . '_' . 
                              $folder_hash . '_' . 
                              $timestamp;
            }
        }
        
        $user_folder_path = $base_upload_dir . $folder_name . '/';
        
        // Buat folder baru
        if (!is_dir($user_folder_path)) {
            mkdir($user_folder_path, 0755, true);
        }
    }
    
    // Handle upload dokumen
    $dokumen_baru = [];
    $has_foto_selfie = false;
    
    // Tambahkan dokumen lama yang tidak dihapus
    if (isset($_POST['existing_docs']) && is_array($_POST['existing_docs'])) {
        foreach ($_POST['existing_docs'] as $doc_index) {
            if (isset($dokumen_data[$doc_index])) {
                $dokumen_baru[] = $dokumen_data[$doc_index];
                if (($dokumen_data[$doc_index]['type'] ?? '') === 'foto_selfie') {
                    $has_foto_selfie = true;
                }
            }
        }
    }

    // Dokumen wajib: Foto Selfie Pemohon Memegang Formulir
    $allowedMimesByExt = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    if (isset($_FILES['dokumen_foto_selfie']) && !empty($_FILES['dokumen_foto_selfie']['name'])) {
        $file_name = $_FILES['dokumen_foto_selfie']['name'];
        $file_tmp = $_FILES['dokumen_foto_selfie']['tmp_name'];
        $file_size = $_FILES['dokumen_foto_selfie']['size'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if (!isset($allowedMimesByExt[$file_ext])) {
            $errors[] = "Format file Foto Selfie tidak didukung. Gunakan JPG, JPEG, PNG, atau GIF";
        } elseif (!is_uploaded_file($file_tmp) || !is_readable($file_tmp)) {
            $errors[] = "Upload Foto Selfie tidak valid";
        } elseif ($file_size > 1 * 1024 * 1024) {
            $errors[] = "Ukuran file Foto Selfie terlalu besar (maksimal 1MB)";
        } else {
            $detectedMime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detectedMime = (string)finfo_file($finfo, $file_tmp);
                    finfo_close($finfo);
                }
            }
            if ($detectedMime === '' && function_exists('mime_content_type')) {
                $detectedMime = (string)mime_content_type($file_tmp);
            }
            if ($detectedMime === '' || !in_array($detectedMime, $allowedMimesByExt[$file_ext], true)) {
                $errors[] = "Tipe file Foto Selfie tidak valid atau tidak sesuai ekstensi";
            } else {
            $timestamp = time();
            $randomString = bin2hex(random_bytes(6));
            $new_filename = 'foto_selfie_' . $laporan_id . '_' . $timestamp . '_' . $randomString . '.' . $file_ext;
            $upload_path = $user_folder_path . $new_filename;

            if (move_uploaded_file($file_tmp, $upload_path)) {
                $path_for_db = 'user/uploads/laporan_peristiwa/' . $folder_name . '/' . $new_filename;
                $dokumen_baru[] = [
                    'type' => 'foto_selfie',
                    'original_name' => $file_name,
                    'path' => $path_for_db,
                    'uploaded_name' => $new_filename,
                    'size' => $file_size,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];
                $has_foto_selfie = true;
            } else {
                $errors[] = "Gagal mengupload Foto Selfie Pemohon";
            }
            }
        }
    }
    
    // Proses dokumen tambahan dari input dinamis
    if (isset($_FILES['dokumen_tambahan']) && !empty($_FILES['dokumen_tambahan']['name'][0])) {
        $total_files = count($_FILES['dokumen_tambahan']['name']);
        
        for ($i = 0; $i < $total_files; $i++) {
            if ($_FILES['dokumen_tambahan']['error'][$i] === 0) {
                $file_name = $_FILES['dokumen_tambahan']['name'][$i];
                $file_tmp = $_FILES['dokumen_tambahan']['tmp_name'][$i];
                $file_size = $_FILES['dokumen_tambahan']['size'][$i];
                
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                if (isset($allowedMimesByExt[$file_ext])) {
                    if (!is_uploaded_file($file_tmp) || !is_readable($file_tmp)) {
                        $errors[] = "Upload dokumen tambahan $file_name tidak valid";
                    } elseif ($file_size <= 1 * 1024 * 1024) {
                        $detectedMime = '';
                        if (function_exists('finfo_open')) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            if ($finfo) {
                                $detectedMime = (string)finfo_file($finfo, $file_tmp);
                                finfo_close($finfo);
                            }
                        }
                        if ($detectedMime === '' && function_exists('mime_content_type')) {
                            $detectedMime = (string)mime_content_type($file_tmp);
                        }
                        if ($detectedMime === '' || !in_array($detectedMime, $allowedMimesByExt[$file_ext], true)) {
                            $errors[] = "Tipe file dokumen tambahan $file_name tidak valid";
                            continue;
                        }

                        $timestamp = time();
                        $randomString = bin2hex(random_bytes(6));
                        $dokumen_type = $_POST['dokumen_type'][$i] ?? 'lainnya';
                        $dokumen_type = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$dokumen_type));
                        if ($dokumen_type === '') {
                            $dokumen_type = 'lainnya';
                        }
                        
                        $new_filename = $dokumen_type . '_' . 
                                      $laporan_id . '_' . 
                                      $timestamp . '_' . 
                                      $randomString . '.' . $file_ext;
                        
                        $upload_path = $user_folder_path . $new_filename;
                        
                        if (move_uploaded_file($file_tmp, $upload_path)) {
                            // Path untuk disimpan di database
                            $path_for_db = 'user/uploads/laporan_peristiwa/' . $folder_name . '/' . $new_filename;
                            
                            $dokumen_baru[] = [
                                'type' => $dokumen_type,
                                'original_name' => $file_name,
                                'path' => $path_for_db,
                                'uploaded_name' => $new_filename,
                                'size' => $file_size,
                                'uploaded_at' => date('Y-m-d H:i:s')
                            ];
                        }
                    } else {
                        $errors[] = "Ukuran file dokumen tambahan $file_name terlalu besar (maksimal 1MB)";
                    }
                } else {
                    $errors[] = "Format file dokumen tambahan $file_name tidak didukung";
                }
            }
        }
    }

    if (!$has_foto_selfie) {
        $errors[] = "Dokumen wajib Foto Selfie Pemohon Memegang Formulir harus diunggah atau dipertahankan";
    }
    
    // Jika tidak ada error, update data
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        // Convert data dokumen ke JSON
        $dokumen_json = !empty($dokumen_baru) ? json_encode($dokumen_baru, JSON_UNESCAPED_UNICODE) : null;
        
        // Update query
        $update_query = "UPDATE tb_laporan_peristiwa SET 
            nama_pelapor = ?,
            nik_pelapor = ?,
            no_hp = ?,
            email = ?,
            id_kecamatan = ?,
            id_desa = ?,
            nama_kecamatan = ?,
            nama_desa = ?,
            jenis_peristiwa = ?,
            nama_subjek = ?,
            nik_subjek = ?,
            tempat_lahir_mati = ?,
            tanggal_lahir_mati = ?,
            nama_ayah = ?,
            nama_ibu = ?,
            keterangan = ?,
            dokumen = ?,
            nama_folder = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id_lapor = ? AND id_user = ?";
        
        $stmt = $koneksi->prepare($update_query);
        if ($stmt) {
            $stmt->bind_param('ssssiissssssssssssii', 
                $nama_pelapor,
                $nik_pelapor,
                $no_hp,
                $email,
                $id_kecamatan,
                $id_desa,
                $nama_kecamatan_db,
                $nama_desa_db,
                $jenis_peristiwa,
                $nama_subjek,
                $nik_subjek,
                $tempat_lahir_mati,
                $tanggal_lahir_mati,
                $nama_ayah,
                $nama_ibu,
                $keterangan,
                $dokumen_json,
                $folder_name,
                $laporan_id,
                $user_id
            );
            
            if ($stmt->execute()) {
            logPermohonanStatusHistory(
                $koneksi,
                'tb_laporan_peristiwa',
                $laporan_id,
                'diajukan',
                null,
                (int)($_SESSION['id_user'] ?? 0),
                (string)($_SESSION['role'] ?? 'user'),
                'resubmit'
            );
                $_SESSION['success_message'] = "Laporan peristiwa berhasil diperbarui dan diajukan kembali!";
                header('Location: status_permohonan.php');
                exit;
            }
            $stmt->close();
        }
    }
}

// Fungsi helper
function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function selected($value, $compare) {
    return $value == $compare ? 'selected' : '';
}

function checked($value, $compare) {
    return $value == $compare ? 'checked' : '';
}

// Map jenis dokumen ke label yang lebih baik
function getDocTypeLabel($type) {
    $labels = [
        'surat_keterangan_lahir' => 'Surat Keterangan Lahir',
        'kk' => 'Kartu Keluarga',
        'ktp' => 'KTP',
        'akta_lahir' => 'Akta Lahir',
        'akta_kematian' => 'Akta Kematian',
        'ijazah' => 'Ijazah',
        'buku_nikah' => 'Buku Nikah',
        'foto_selfie' => 'Foto Selfie Pemohon',
        'lainnya' => 'Dokumen Lainnya'
    ];
    return $labels[$type] ?? $type;
}

// Map jenis peristiwa ke label
function getJenisPeristiwaLabel($jenis) {
    $labels = [
        'lahir' => 'Kelahiran',
        'mati' => 'Kematian',
        'rentan_adminduk' => 'Rentan Administrasi'
    ];
    return $labels[$jenis] ?? $jenis;
}

$selfie_file = null;
foreach ($dokumen_data as $doc) {
    if (($doc['type'] ?? '') === 'foto_selfie') {
        $selfie_file = $doc;
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Laporan Peristiwa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/edit_lapor_peristiwa.css">
    <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
    <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-line">

<div class="layout">
    <!-- SIDEBAR -->
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header user-general-header">
            <?php
            require_once __DIR__ . '/user_header_brand.php';
            render_user_header_brand('Edit Laporan Peristiwa');
            ?>
            <div class="user-info">
                <span>Hai, <?= esc($_SESSION['nama']) ?></span>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong>Terjadi kesalahan:</strong>
                    <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= esc($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <div class="form-header">
                    <h2>Edit Laporan Peristiwa #<?= $laporan_id ?></h2>
                    <span class="status-badge">Status: <?= esc(ucfirst($laporan['status'])) ?></span>
                </div>

                <!-- Info Box -->
                <div class="info-box">
                    <p><strong>Petunjuk Pengeditan:</strong></p>
                    <ul>
                        <li>Perbaiki data yang sesuai dengan alasan penolakan</li>
                        <li>Foto selfie pemohon memegang formulir bersifat wajib</li>
                        <li>Format file yang diperbolehkan: JPG, JPEG, PNG, GIF</li>
                        <li>Ukuran maksimal per file: 1MB</li>
                        <li>Setelah diedit, status akan berubah menjadi "Diajukan"</li>
                    </ul>
                </div>

                <form method="POST" enctype="multipart/form-data" id="editPeristiwaForm">
<?php echo csrf_input(); ?>
                    <!-- Data Pelapor -->
                    <div class="form-section">
                        <h3 class="section-title">Data Pelapor</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Pelapor <span class="required">*</span></label>
                                <input type="text" name="nama_pelapor" class="form-control" 
                                       value="<?= esc($laporan['nama_pelapor'] ?? '') ?>" required readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Pelapor <span class="required">*</span></label>
                                <input type="text" name="nik_pelapor" class="form-control" 
                                       value="<?= esc($laporan['nik_pelapor'] ?? '') ?>" required readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>No HP <span class="required">*</span></label>
                                <input type="tel" name="no_hp" class="form-control" 
                                       value="<?= esc($laporan['no_hp'] ?? '') ?>" required readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?= esc($laporan['email'] ?? '') ?>" required readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Data Domisili -->
                    <div class="form-section">
                        <h3 class="section-title">Data Domisili</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Kecamatan <span class="required">*</span></label>
                                <select name="id_kecamatan" class="form-control" 
                                        onchange="getDesaByKecamatan(this.value)" required>
                                    <option value="">Pilih Kecamatan</option>
                                    <?php foreach ($kecamatan_options as $kec): ?>
                                        <option value="<?= $kec['id'] ?>" 
                                            <?= selected($kec['id'], $laporan['id_kecamatan'] ?? '') ?>>
                                            <?= esc($kec['nama_kecamatan']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Desa <span class="required">*</span></label>
                                <select name="id_desa" id="desa-select" class="form-control" required>
                                    <option value="">Pilih Desa</option>
                                    <?php foreach ($desa_options as $desa): ?>
                                        <option value="<?= $desa['id'] ?>" 
                                            <?= selected($desa['id'], $laporan['id_desa'] ?? '') ?>>
                                            <?= esc($desa['nama_desa']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Jenis Peristiwa -->
                    <div class="form-section">
                        <h3 class="section-title">Jenis Peristiwa</h3>
                        <div class="form-group">
                            <select name="jenis_peristiwa" class="form-control" required onchange="showPeristiwaInfo()">
                                <option value="">Pilih Jenis Peristiwa</option>
                                <option value="lahir" <?= selected('lahir', $laporan['jenis_peristiwa'] ?? '') ?>>Lahir</option>
                                <option value="mati" <?= selected('mati', $laporan['jenis_peristiwa'] ?? '') ?>>Meninggal Dunia</option>
                                <option value="rentan_adminduk" <?= selected('rentan_adminduk', $laporan['jenis_peristiwa'] ?? '') ?>>Rentan Administrasi Kependudukan</option>
                            </select>
                        </div>
                        
                        <div id="peristiwaInfo" class="info-box" style="display: none;">
                            <p><strong>Informasi:</strong></p>
                            <div id="infoContent"></div>
                        </div>
                    </div>

                    <!-- Data Subjek -->
                    <div class="form-section">
                        <h3 class="section-title">Data Subjek</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label id="labelNamaSubjek">Nama Subjek <span class="required">*</span></label>
                                <input type="text" name="nama_subjek" class="form-control" 
                                       value="<?= esc($laporan['nama_subjek'] ?? '') ?>" required 
                                       placeholder="Nama lengkap" id="namaSubjekInput">
                            </div>
                            
                            <div class="form-group">
                                <label id="labelNikSubjek">NIK Subjek (Opsional)</label>
                                <input type="text" name="nik_subjek" class="form-control" 
                                       value="<?= esc($laporan['nik_subjek'] ?? '') ?>" 
                                       placeholder="16 digit angka" id="nikSubjekInput" 
                                       oninput="validateNik(this)">
                                <div id="nikError" style="color: #dc3545; font-size: 14px; display: none;">NIK harus 16 digit angka</div>
                            </div>
                            
                            <div class="form-group">
                                <label id="labelTempat">Tempat Peristiwa <span class="required">*</span></label>
                                <input type="text" name="tempat_lahir_mati" class="form-control" 
                                       value="<?= esc($laporan['tempat_lahir_mati'] ?? '') ?>" required 
                                       placeholder="Contoh: Kabupaten Gorontalo / Kota Gorontalo">
                            </div>
                            
                            <div class="form-group">
                                <label id="labelTanggal">Tanggal Peristiwa <span class="required">*</span></label>
                                <input type="date" name="tanggal_lahir_mati" class="form-control" 
                                       value="<?= esc($laporan['tanggal_lahir_mati'] ?? '') ?>" required 
                                       max="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Data Orang Tua -->
                    <div class="form-section">
                        <h3 class="section-title">Data Orang Tua</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label id="labelAyah">Nama Ayah <span class="required">*</span></label>
                                <input type="text" name="nama_ayah" class="form-control" 
                                       value="<?= esc($laporan['nama_ayah'] ?? '') ?>" required 
                                       placeholder="Nama lengkap ayah">
                            </div>
                            
                            <div class="form-group">
                                <label id="labelIbu">Nama Ibu <span class="required">*</span></label>
                                <input type="text" name="nama_ibu" class="form-control" 
                                       value="<?= esc($laporan['nama_ibu'] ?? '') ?>" required 
                                       placeholder="Nama lengkap ibu">
                            </div>
                        </div>
                    </div>

                    <!-- Keterangan -->
                    <div class="form-section">
                        <h3 class="section-title">Keterangan</h3>
                        <div class="form-group">
                            <label>Keterangan Tambahan</label>
                            <textarea name="keterangan" class="form-control" rows="3" 
                                      placeholder="Berikan penjelasan tambahan mengenai peristiwa yang dilaporkan" maxlength="500"><?= esc($laporan['keterangan'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Dokumen Pendukung -->
                    <div class="form-section">
                        <h3 class="section-title">Dokumen Pendukung</h3>
                        <div style="background: #fef2f2; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #fee2e2;">
                            <strong style="color: #b91c1c;">Dokumen Wajib:</strong> Foto Selfie Pemohon Memegang Formulir
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label>Foto Selfie Pemohon Memegang Formulir <span class="required">*</span></label>
                            <div class="file-upload">
                                <label class="file-upload-label">
                                    <i class="fas fa-upload"></i> Unggah Foto Selfie Pemohon
                                    <input type="file" name="dokumen_foto_selfie" id="dokumen_foto_selfie" accept=".jpg,.jpeg,.jfif,.jpe,.png">
                                </label>
                                <div class="file-preview" id="selfie-preview">
                                    <?php if (!empty($selfie_file)): ?>
                                    <div class="document-item">
                                        <div class="doc-info">
                                            <span class="doc-type-badge badge-foto_selfie">Foto Selfie Pemohon</span>
                                            <span class="doc-name"><?= esc($selfie_file['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <?php if (isset($selfie_file['path'])): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$selfie_file['path']) ?? '') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="file-name">Belum ada file Foto Selfie diunggah</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dokumen yang sudah ada -->
                        <?php if (!empty($dokumen_data)): ?>
                        <div class="document-list" id="existing-documents">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Dokumen yang Sudah Ada:</h4>
                            <?php foreach ($dokumen_data as $index => $doc): ?>
                                <div class="document-item" data-index="<?= $index ?>">
                                    <div class="doc-info">
                                        <?php 
                                        $badge_class = 'badge-' . $doc['type'];
                                        $type_label = getDocTypeLabel($doc['type']);
                                        ?>
                                        <span class="doc-type-badge <?= $badge_class ?>"><?= $type_label ?></span>
                                        <span class="doc-name"><?= esc($doc['original_name']) ?></span>
                                    </div>
                                    <div class="doc-actions">
                                        <input type="checkbox" name="existing_docs[]" value="<?= $index ?>" checked style="margin-right: 10px;">
                                        <?php if (isset($doc['path'])): ?>
                                        <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$doc['path']) ?? '') ?>')">
                                            <i class="fas fa-eye"></i> Lihat
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn-remove-dokumen" onclick="removeExistingDocument(this, <?= $index ?>)">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Dokumen Tambahan -->
                        <div id="dokumen-tambahan-container">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Tambah Dokumen Baru (Opsional):</h4>
                            <div id="dokumen-tambahan-list">
                                <!-- Dokumen tambahan akan ditambahkan di sini -->
                            </div>
                            <button type="button" class="btn-add" onclick="addDokumenTambahan()">
                                <i class="fas fa-plus"></i> Tambah Dokumen Baru
                            </button>
                        </div>
                    </div>

                    <!-- Alasan Penolakan -->
                    <?php if (!empty($laporan['alasan'])): ?>
                    <div class="form-section">
                        <h3 class="section-title" style="color: #dc2626;">Alasan Penolakan Sebelumnya</h3>
                        <div class="form-group">
                            <div style="background: #fee2e2; padding: 15px; border-radius: 6px; border: 1px solid #fecaca;">
                                <strong>Catatan dari Admin:</strong><br>
                                <?= nl2br(esc($laporan['alasan'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <a href="status_permohonan.php" class="btn-cancel">Batal</a>
                        <button type="submit" class="btn-submit">Perbarui dan Ajukan Kembali</button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</div>

<!-- Modal untuk preview dokumen -->
<div id="previewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 2000; justify-content: center; align-items: center;">
    <div style="background: white; border-radius: 8px; padding: 20px; max-width: 90%; max-height: 90%; overflow: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;">Preview Dokumen</h3>
            <button onclick="closePreview()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
        </div>
        <div id="previewContent"></div>
    </div>
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
    
    // Tampilkan info berdasarkan jenis peristiwa yang sudah dipilih
    showPeristiwaInfo();

    const selfieInput = document.getElementById('dokumen_foto_selfie');
    const selfiePreview = document.getElementById('selfie-preview');
    if (selfieInput && selfiePreview) {
        selfieInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const file = this.files[0];
                const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                if (file.size > 1 * 1024 * 1024) {
                    alert(`File ${file.name} terlalu besar (${fileSizeMB} MB). Maksimal 1MB.`);
                    this.value = '';
                    selfiePreview.innerHTML = '<span class="file-name">Belum ada file Foto Selfie diunggah</span>';
                    return;
                }
                selfiePreview.innerHTML = `<span class="file-name">${file.name}</span><br><small>Ukuran: ${fileSizeMB} MB</small>`;
            }
        });
    }
    
    // Set kecamatan dropdown value dan load desa jika sudah ada nilai
    <?php if (!empty($laporan['id_kecamatan'])): ?>
    setTimeout(() => {
        getDesaByKecamatan(<?= $laporan['id_kecamatan'] ?>);
    }, 100);
    <?php endif; ?>
});

// Fungsi untuk menampilkan informasi berdasarkan jenis peristiwa
function showPeristiwaInfo() {
    const jenisSelect = document.querySelector('select[name="jenis_peristiwa"]');
    const infoBox = document.getElementById('peristiwaInfo');
    const infoContent = document.getElementById('infoContent');
    
    // Update label berdasarkan jenis peristiwa
    const labelNamaSubjek = document.getElementById('labelNamaSubjek');
    const labelNikSubjek = document.getElementById('labelNikSubjek');
    const labelTempat = document.getElementById('labelTempat');
    const labelTanggal = document.getElementById('labelTanggal');
    const labelAyah = document.getElementById('labelAyah');
    const labelIbu = document.getElementById('labelIbu');
    const namaSubjekInput = document.getElementById('namaSubjekInput');
    
    const jenis = jenisSelect?.value || '<?= $laporan['jenis_peristiwa'] ?? '' ?>';
    
    if (jenis === 'lahir') {
        labelNamaSubjek.innerHTML = 'Nama Bayi <span class="required">*</span>';
        labelNikSubjek.innerHTML = 'NIK Bayi (Opsional)';
        labelTempat.innerHTML = 'Tempat Lahir <span class="required">*</span>';
        labelTanggal.innerHTML = 'Tanggal Lahir <span class="required">*</span>';
        labelAyah.innerHTML = 'Nama Ayah <span class="required">*</span>';
        labelIbu.innerHTML = 'Nama Ibu <span class="required">*</span>';
        namaSubjekInput.placeholder = 'Nama lengkap bayi';
        
        infoContent.innerHTML = `
            <ul>
                <li><strong>Untuk melaporkan kelahiran bayi</strong></li>
                <li>Isi data bayi yang baru lahir</li>
                <li>Dokumen: Surat Keterangan Lahir (Opsional)</li>
                <li>NIK bayi dapat diisi nanti setelah terdaftar di Dukcapil</li>
            </ul>
        `;
        infoBox.style.display = 'block';
        
    } else if (jenis === 'mati') {
        labelNamaSubjek.innerHTML = 'Nama Meninggal <span class="required">*</span>';
        labelNikSubjek.innerHTML = 'NIK Meninggal (Opsional)';
        labelTempat.innerHTML = 'Tempat Meninggal <span class="required">*</span>';
        labelTanggal.innerHTML = 'Tanggal Meninggal <span class="required">*</span>';
        labelAyah.innerHTML = 'Nama Ayah <span class="required">*</span>';
        labelIbu.innerHTML = 'Nama Ibu <span class="required">*</span>';
        namaSubjekInput.placeholder = 'Nama lengkap yang meninggal';
        
        infoContent.innerHTML = `
            <ul>
                <li><strong>Untuk melaporkan kematian seseorang</strong></li>
                <li>Isi data orang yang meninggal dunia</li>
                <li>Dokumen: KK dan KTP yang meninggal (Opsional)</li>
                <li>Jika NIK tidak diketahui, bisa dikosongkan</li>
            </ul>
        `;
        infoBox.style.display = 'block';
        
    } else if (jenis === 'rentan_adminduk') {
        labelNamaSubjek.innerHTML = 'Nama <span class="required">*</span>';
        labelNikSubjek.innerHTML = 'NIK (Opsional)';
        labelTempat.innerHTML = 'Tempat Lahir <span class="required">*</span>';
        labelTanggal.innerHTML = 'Tanggal Lahir <span class="required">*</span>';
        labelAyah.innerHTML = 'Nama Ayah <span class="required">*</span>';
        labelIbu.innerHTML = 'Nama Ibu <span class="required">*</span>';
        namaSubjekInput.placeholder = 'Nama lengkap';
        
        infoContent.innerHTML = `
            <ul>
                <li><strong>Untuk melaporkan peristiwa rentan administrasi kependudukan</strong></li>
                <li>Contoh: Lansia belum rekam KTP-el, belum punya kartu keluarga, dll</li>
                <li>Isi data lengkap orang yang mengalami kendala administrasi</li>
                <li>Dokumen: ijazah, buku nikah, atau dokumen lainnya (Opsional)</li>
            </ul>
        `;
        infoBox.style.display = 'block';
        
    } else {
        infoBox.style.display = 'none';
    }
}

// Validasi NIK
function validateNik(input) {
    const nikError = document.getElementById('nikError');
    const nikValue = input.value.trim();
    
    if (nikValue === '') {
        nikError.style.display = 'none';
        return;
    }
    
    if (!/^[0-9]{16}$/.test(nikValue)) {
        nikError.style.display = 'block';
    } else {
        nikError.style.display = 'none';
    }
}

// Dokumen Tambahan Functions
let dokumenCounter = 0;

// Stabilkan file upload lintas device/browser (terutama mobile/cloud picker)
// dengan mengkloning file ke memori saat dipilih.
async function stabilizeSelectedFile(input) {
    if (!input || !input.files || input.files.length === 0) {
        if (input) input.dataset.fileStable = '1';
        return;
    }

    const original = input.files[0];
    try {
        const buffer = await original.arrayBuffer();
        if (typeof DataTransfer === 'undefined') {
            input.dataset.fileStable = '0';
            return;
        }

        const stableFile = new File(
            [buffer],
            original.name,
            {
                type: original.type || 'application/octet-stream',
                lastModified: Date.now()
            }
        );
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(stableFile);
        input.files = dataTransfer.files;
        input.dataset.fileStable = '1';
    } catch (err) {
        input.dataset.fileStable = '0';
        console.warn('Gagal menstabilkan file input:', err);
    }
}
function addDokumenTambahan() {
    const container = document.getElementById('dokumen-tambahan-list');
    const newItem = document.createElement('div');
    newItem.className = 'dokumen-tambahan-item';
    newItem.innerHTML = `
        <div class="dokumen-tambahan-header">
            <h4>Dokumen Tambahan #${dokumenCounter + 1}</h4>
            <button type="button" class="btn-remove-dokumen" onclick="removeDokumenTambahan(this)">
                <i class="fas fa-times"></i> Hapus
            </button>
        </div>
        <select name="dokumen_type[]" class="dokumen-type-select">
            <option value="">Pilih Jenis Dokumen</option>
            <option value="surat_keterangan_lahir">Surat Keterangan Lahir</option>
            <option value="kk">Kartu Keluarga</option>
            <option value="ktp">KTP</option>
            <option value="akta_lahir">Akta Lahir</option>
            <option value="akta_kematian">Akta Kematian</option>
            <option value="ijazah">Ijazah</option>
            <option value="buku_nikah">Buku Nikah</option>
            <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div class="file-upload">
            <label class="file-upload-label">
                <i class="fas fa-upload"></i> Unggah File (Opsional)
                <input type="file" name="dokumen_tambahan[]" accept=".jpg,.jpeg,.jfif,.jpe,.png">
            </label>
            <div class="file-preview">
                <span class="file-name">Belum ada file dipilih</span>
            </div>
        </div>
    `;
    
    container.appendChild(newItem);
    dokumenCounter++;
    
    // Add file change event to the new input
    const fileInput = newItem.querySelector('input[type="file"]');
    const filePreview = newItem.querySelector('.file-preview');
    
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            const file = this.files[0];
            
            // Validasi ukuran file (1MB)
            if (file.size > 1 * 1024 * 1024) {
                alert(`File ${file.name} terlalu besar. Maksimal 1MB.`);
                this.value = '';
                filePreview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
                return;
            }
            
            const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
            
            filePreview.innerHTML = `
                <span class="file-name">${file.name}</span><br>
                <small>Ukuran: ${fileSizeMB} MB</small>
            `;
        } else {
            filePreview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
        }
    });
}

function removeDokumenTambahan(button) {
    const item = button.closest('.dokumen-tambahan-item');
    item.remove();
    dokumenCounter--;
}

function removeExistingDocument(button, index) {
    if (confirm('Apakah Anda yakin ingin menghapus dokumen ini?')) {
        const checkbox = button.closest('.document-item').querySelector('input[type="checkbox"]');
        checkbox.checked = false;
        button.closest('.document-item').style.display = 'none';
    }
}

// Preview Functions
function previewDocument(path) {
    // Pastikan path memiliki /user/ di depannya
    let correctedPath = path;
    
    // Koreksi path jika tidak ada /user/
    if (path.startsWith('uploads/')) {
        correctedPath = 'user/' + path;
    } else if (path.startsWith('/uploads/')) {
        correctedPath = '/user' + path;
    }
    
    // Bangun full URL
    const baseUrl = window.location.origin;
    let fullPath;
    
    if (!correctedPath.startsWith('http') && !correctedPath.startsWith('/')) {
        fullPath = baseUrl + '/' + correctedPath;
    } else if (correctedPath.startsWith('/')) {
        fullPath = baseUrl + correctedPath;
    } else {
        fullPath = correctedPath;
    }
    
    const extension = path.split('.').pop().toLowerCase();
    
    if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
        previewImage(fullPath);
    } else {
        alert('Format file tidak didukung untuk preview. Hanya gambar yang dapat dipreview.');
    }
}

function previewImage(src) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    // Tampilkan loading
    content.innerHTML = `
        <div style="text-align: center; padding: 50px;">
            <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #3b82f6;"></i>
            <p style="margin-top: 20px;">Memuat gambar...</p>
        </div>
    `;
    
    modal.style.display = 'flex';
    
    // Buat elemen gambar
    const img = document.createElement('img');
    img.src = src;
    img.style.maxWidth = '100%';
    img.style.maxHeight = '70vh';
    img.style.display = 'block';
    img.style.margin = '0 auto';
    
    // Handle jika gambar gagal dimuat
    img.onerror = function() {
        content.innerHTML = `
            <div style="text-align: center; padding: 50px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f59e0b; margin-bottom: 20px;"></i>
                <h4>Gagal memuat gambar</h4>
                <p>Dokumen tidak ditemukan atau tidak dapat diakses.</p>
                <button onclick="window.open('${src}', '_blank')" style="background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; margin-top: 15px;">
                    <i class="fas fa-external-link-alt"></i> Buka di Tab Baru
                </button>
            </div>
        `;
    };
    
    // Tambahkan tombol download
    const downloadBtn = document.createElement('button');
    downloadBtn.innerHTML = '<i class="fas fa-download"></i> Download';
    downloadBtn.style.background = '#10b981';
    downloadBtn.style.color = 'white';
    downloadBtn.style.border = 'none';
    downloadBtn.style.padding = '8px 16px';
    downloadBtn.style.borderRadius = '6px';
    downloadBtn.style.cursor = 'pointer';
    downloadBtn.style.marginTop = '15px';
    downloadBtn.onclick = function() {
        window.open(src, '_blank');
    };
    
    content.innerHTML = '';
    content.appendChild(img);
    content.appendChild(document.createElement('br'));
    content.appendChild(downloadBtn);
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewContent').innerHTML = '';
}

// Get Desa by Kecamatan
async function getDesaByKecamatan(kecamatanId) {
    const desaSelect = document.getElementById('desa-select');
    if (!desaSelect) return;
    
    if (!kecamatanId || kecamatanId === '') {
        desaSelect.innerHTML = '<option value="">Pilih Kecamatan terlebih dahulu</option>';
        return;
    }
    
    // Show loading state
    desaSelect.innerHTML = '<option value="">Memuat data desa...</option>';
    desaSelect.disabled = true;
    
    try {
        const response = await fetch(`get_desa.php?id_kecamatan=${kecamatanId}`);
        
        if (response.ok) {
            const data = await response.text();
            desaSelect.innerHTML = '<option value="">Pilih Desa</option>' + data;
            
            // Set nilai desa jika ada
            <?php if (!empty($laporan['id_desa'])): ?>
            setTimeout(() => {
                desaSelect.value = <?= $laporan['id_desa'] ?>;
            }, 100);
            <?php endif; ?>
        } else {
            throw new Error('Gagal mengambil data desa');
        }
    } catch (error) {
        desaSelect.innerHTML = '<option value="">Error: ' + error.message + '</option>';
    } finally {
        desaSelect.disabled = false;
    }
}

// Delegasi global untuk menstabilkan setiap file yang dipilih.
document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    stabilizeSelectedFile(target);
});
// Form Validation
document.getElementById('editPeristiwaForm').addEventListener('submit', function(e) {
    let isValid = true;
    
    // Clear previous error states
    document.querySelectorAll('.form-control').forEach(input => {
        input.classList.remove('is-invalid');
    });
    
    // Validate required fields
    const requiredFields = this.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        }
    });
    
    // Validate NIK jika diisi
    const nikSubjek = document.getElementById('nikSubjekInput').value;
    if (nikSubjek && !/^[0-9]{16}$/.test(nikSubjek)) {
        alert('NIK subjek harus 16 digit angka atau dikosongkan');
        isValid = false;
    }
    
    // Validate tanggal tidak lebih dari hari ini
    const tanggalInput = document.querySelector('input[name="tanggal_lahir_mati"]');
    if (tanggalInput.value) {
        const today = new Date().toISOString().split('T')[0];
        if (tanggalInput.value > today) {
            alert('Tanggal peristiwa tidak boleh lebih dari hari ini');
            isValid = false;
        }
    }
    
    // Validate file sizes for new uploads (1MB)
    const fileInputs = this.querySelectorAll('input[type="file"]');
    for (let input of fileInputs) {
        if (input.files.length > 0) {
            if (input.dataset.fileStable === '0') {
                // Fallback mode: jangan blok submit, karena beberapa browser mobile
                // gagal clone file meski file lokal tetap valid untuk upload normal.
                console.warn(`File "${input.files[0].name}" tidak bisa distabilkan, lanjut upload mode normal.`);
            }            const file = input.files[0];
            if (file.size > 1 * 1024 * 1024) {
                alert(`File ${file.name} terlalu besar (maksimal 1MB)`);
                isValid = false;
                break;
            }
        }
    }

    const selfieInput = document.getElementById('dokumen_foto_selfie');
    const hasSelfieUpload = selfieInput && selfieInput.files.length > 0;
    let hasSelfieExisting = false;
    const checkedExisting = this.querySelectorAll('input[name="existing_docs[]"]:checked');
    checkedExisting.forEach(cb => {
        const item = cb.closest('.document-item');
        const badge = item ? item.querySelector('.doc-type-badge') : null;
        if (badge && badge.classList.contains('badge-foto_selfie')) {
            hasSelfieExisting = true;
        }
    });

    if (!hasSelfieUpload && !hasSelfieExisting) {
        alert('Dokumen wajib Foto Selfie Pemohon Memegang Formulir harus diunggah atau dipertahankan.');
        isValid = false;
    }
    
    if (!isValid) {
        e.preventDefault();
        alert('Harap lengkapi semua bidang yang wajib diisi dan periksa data yang dimasukkan!');
    } else {
        // Konfirmasi sebelum submit
        const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan kembali laporan peristiwa?');
        if (!confirmSubmit) {
            e.preventDefault();
        }
    }
});
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>


