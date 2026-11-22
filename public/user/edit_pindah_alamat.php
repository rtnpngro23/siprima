<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_pindah_alamat LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
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

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// Cek apakah ID permohonan diberikan
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: status_permohonan.php?error=no_id');
    exit;
}

$permohonan_id = (int)$_GET['id'];

// Ambil data permohonan berdasarkan ID
$stmt = $koneksi->prepare("SELECT 
    id, 
    id_kecamatan,
    id_desa,
    nama_pemohon,
    nik_pemohon,
    no_hp,
    email,
    nama_kecamatan, 
    nama_desa,
    no_kk,
    nama_kepalakeluarga, 
    alamat_asal,
    alamat_tujuan,
    alasan_pindah,
    anggotaKeluarga, 
    keterangan,
    dokumen, 
    status,
    alasan,
    nama_folder
    FROM tb_pindah_alamat 
    WHERE id = ? AND id_user = ?");
$stmt->bind_param('ii', $permohonan_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header('Location: status_permohonan.php?error=not_found');
    exit;
}

$permohonan = $res->fetch_assoc();

// Hanya izinkan edit saat status diajukan atau ditolak
if (!in_array(strtolower((string)$permohonan['status']), ['diajukan', 'ditolak'], true)) {
    header('Location: status_permohonan.php?error=cannot_edit');
    exit;
}

// Dekode data JSON
$anggota_keluarga = [];
if (!empty($permohonan['anggotaKeluarga'])) {
    $anggota_keluarga = json_decode($permohonan['anggotaKeluarga'], true);
    if (!is_array($anggota_keluarga)) {
        $anggota_keluarga = [];
    }
}

$dokumen_data = [];
if (!empty($permohonan['dokumen'])) {
    $dokumen_data = json_decode($permohonan['dokumen'], true);
    if (!is_array($dokumen_data)) {
        $dokumen_data = [];
    }
}

// Ambil nama folder dari database
$nama_folder = $permohonan['nama_folder'] ?? '';

// Ambil data kecamatan dan desa untuk dropdown
$kecamatan_options = [];
$desa_options = [];

$kecamatan_query = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
while ($kec = $kecamatan_query->fetch_assoc()) {
    $kecamatan_options[] = $kec;
}

// Jika ada id_kecamatan, ambil desa berdasarkan kecamatan
if (!empty($permohonan['id_kecamatan'])) {
    $desa_query = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
    $desa_query->bind_param('i', $permohonan['id_kecamatan']);
    $desa_query->execute();
    $desa_result = $desa_query->get_result();
    while ($desa = $desa_result->fetch_assoc()) {
        $desa_options[] = $desa;
    }
    $desa_query->close();
}

// Proses form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') siprima_validate_request_images($errors);
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi input
    $nama_pemohon = trim($_POST['nama_pemohon'] ?? '');
    $nik_pemohon = trim($_POST['nik_pemohon'] ?? '');
    $no_hp = trim($_POST['no_hp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $id_kecamatan = (int)($_POST['id_kecamatan_asal'] ?? 0);
    $id_desa = (int)($_POST['id_desa_asal'] ?? 0);
    $no_kk = trim($_POST['no_kk'] ?? '');
    $nama_kepalakeluarga = trim($_POST['nama_kepalakeluarga'] ?? '');
    $alamat_asal = trim($_POST['alamat_asal'] ?? '');
    $alamat_tujuan = trim($_POST['alamat_tujuan'] ?? '');
    $alasan_pindah = trim($_POST['alasan_pindah'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi
    if (empty($nama_pemohon)) $errors[] = "Nama pemohon harus diisi";
    if (empty($nik_pemohon)) $errors[] = "NIK pemohon harus diisi";
    if (empty($no_hp)) $errors[] = "No HP harus diisi";
    if (empty($email)) $errors[] = "Email harus diisi";
    if (empty($id_kecamatan)) $errors[] = "Kecamatan asal harus dipilih";
    if (empty($id_desa)) $errors[] = "Desa asal harus dipilih";
    if (empty($no_kk)) $errors[] = "Nomor KK harus diisi";
    if (strlen($no_kk) !== 16) $errors[] = "Nomor KK harus 16 digit";
    if (empty($nama_kepalakeluarga)) $errors[] = "Nama kepala keluarga harus diisi";
    if (empty($alamat_asal)) $errors[] = "Alamat asal harus diisi";
    if (empty($alamat_tujuan)) $errors[] = "Alamat tujuan harus diisi";
    if (empty($alasan_pindah)) $errors[] = "Alasan pindah harus dipilih";
    
    // Validasi anggota keluarga
    $anggota_keluarga_baru = [];
    if (isset($_POST['anggota_nama']) && is_array($_POST['anggota_nama'])) {
        $anggota_nama = $_POST['anggota_nama'];
        $anggota_nik = $_POST['anggota_nik'] ?? [];
        $anggota_hubungan = $_POST['anggota_hubungan'] ?? [];
        
        for ($i = 0; $i < count($anggota_nama); $i++) {
            $nama = trim($anggota_nama[$i] ?? '');
            $nik = trim($anggota_nik[$i] ?? '');
            $hubungan = trim($anggota_hubungan[$i] ?? '');
            
            if (!empty($nama)) {
                $anggota_keluarga_baru[] = [
                    'nama' => $nama,
                    'nik' => $nik,
                    'hubungan' => $hubungan
                ];
            }
        }
    }
    
    if (empty($anggota_keluarga_baru)) {
        $errors[] = "Minimal harus ada satu anggota keluarga";
    }
    
    // PERBAIKAN: Gunakan folder yang sudah ada
    $upload_dir = 'uploads/pindah/';
    $user_folder_path = '';
    
    // Jika ada nama_folder di database, gunakan folder tersebut
    if (!empty($nama_folder)) {
        $user_folder_path = $upload_dir . $nama_folder . '/';
        
        // Pastikan folder ada
        if (!file_exists($user_folder_path)) {
            mkdir($user_folder_path, 0755, true);
        }
    } else {
        // Jika tidak ada nama_folder, buat nama folder baru berdasarkan data saat ini
        $nama_kecamatan_asal = '';
        $nama_desa_asal = '';
        
        // Ambil nama kecamatan dan desa
        $kec_stmt = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $kec_stmt->bind_param('i', $id_kecamatan);
        $kec_stmt->execute();
        $kec_stmt->bind_result($nama_kecamatan_asal);
        $kec_stmt->fetch();
        $kec_stmt->close();
        
        $desa_stmt = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $desa_stmt->bind_param('i', $id_desa);
        $desa_stmt->execute();
        $desa_stmt->bind_result($nama_desa_asal);
        $desa_stmt->fetch();
        $desa_stmt->close();
        
        // Fungsi sanitasi nama untuk folder
        function cleanFolderName($string) {
            $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', $string);
            $string = preg_replace('/\s+/', '_', trim($string));
            return $string;
        }
        
        // Bersihkan setiap komponen nama
        $kecamatan_clean = cleanFolderName($nama_kecamatan_asal);
        $desa_clean = cleanFolderName($nama_desa_asal);
        $pemohon_clean = cleanFolderName($nama_pemohon);
        $kepala_keluarga_clean = cleanFolderName($nama_kepalakeluarga);
        
        // Buat nama folder
        $nama_folder = $kecamatan_clean . '_' . 
                      $desa_clean . '_' . 
                      $nik_pemohon . '_' . 
                      $pemohon_clean . '_' . 
                      $kepala_keluarga_clean . '_' . 
                      date('Ymd_His');
        
        // Batasi panjang folder name
        if (strlen($nama_folder) > 200) {
            $folder_hash = substr(md5($nama_folder), 0, 8);
            $nama_folder = $kecamatan_clean . '_' . 
                          $desa_clean . '_' . 
                          $nik_pemohon . '_' . 
                          $pemohon_clean . '_' . 
                          $folder_hash . '_' . 
                          date('Ymd_His');
        }
        
        $user_folder_path = $upload_dir . $nama_folder . '/';
        
        // Buat folder utama jika belum ada
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Buat folder khusus untuk user jika belum ada
        if (!file_exists($user_folder_path)) {
            mkdir($user_folder_path, 0755, true);
        }
    }
    
    // Handle upload dokumen
    $dokumen_baru = [];
    
    // PERBAIKAN: Definisikan dokumen wajib dengan semua kemungkinan jenis
    $required_docs_main = ['formulir_pindah', 'formulir_f103'];
    $required_docs_photo = ['foto_pemohon'];
    $required_docs_id = ['kk_ktp', 'kk_ktp_hilang'];
    
    $uploaded_doc_types = [];
    
    // Proses dokumen yang diupload (dari input dinamis)
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileSize = $_FILES['dokumen']['size'][$i];
                $docType = $_POST['doc_type'][$i] ?? 'lainnya';
                
                // Validasi tipe file
                $allowedExt = ['jpg', 'jpeg', 'jfif', 'jpe', 'png'];
                if (!in_array($ext, $allowedExt)) {
                    $errors[] = "File {$fileName}: Hanya format JPG, PNG yang diperbolehkan";
                    continue;
                }
                
                // Validasi ukuran file - MAKSIMAL 1MB
                $maxFileSize = 1 * 1024 * 1024;
                if ($fileSize > $maxFileSize) {
                    $errors[] = "File {$fileName}: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
                    continue;
                }
                
                // Generate unique filename
                $timestamp_file = time();
                $randomString = bin2hex(random_bytes(6));
                $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                $newName = $clean_doc_type . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                $destination = $user_folder_path . $newName;
                
                if (move_uploaded_file($fileTmp, $destination)) {
                    // Simpan path relatif dari root website
                    $dokumen_baru[] = [
                        'path' => 'uploads/pindah/' . $nama_folder . '/' . $newName,
                        'type' => $docType,
                        'original_name' => $fileName,
                        'uploaded_name' => $newName,
                        'size' => $fileSize,
                        'uploaded_at' => date('Y-m-d H:i:s')
                    ];
                    $uploaded_doc_types[] = $docType;
                } else {
                    $errors[] = "File {$fileName}: Gagal menyimpan file";
                }
            }
        }
    }
    
    // PERBAIKAN: Tambahkan dokumen lama yang tidak dihapus dengan normalisasi jenis dokumen
    if (isset($_POST['existing_docs']) && is_array($_POST['existing_docs'])) {
        foreach ($_POST['existing_docs'] as $doc_index) {
            if (isset($dokumen_data[$doc_index])) {
                $doc = $dokumen_data[$doc_index];
                
                // Normalisasi jenis dokumen untuk kompatibilitas
                $doc_type_normalized = $doc['type'];
                
                // Jika ada jenis dokumen lama, konversi ke format yang divalidasi
                if ($doc_type_normalized == 'formulir_f103') {
                    $doc_type_normalized = 'formulir_pindah';
                } elseif ($doc_type_normalized == 'kk_ktp_hilang') {
                    $doc_type_normalized = 'kk_ktp';
                }
                
                // Simpan dokumen dengan tipe yang dinormalisasi
                $dokumen_baru[] = [
                    'path' => $doc['path'],
                    'type' => $doc_type_normalized,
                    'original_name' => $doc['original_name'],
                    'original_type' => $doc['type'], // Simpan tipe asli untuk referensi
                    'uploaded_name' => $doc['uploaded_name'] ?? basename($doc['path']),
                    'size' => $doc['size'] ?? 0,
                    'uploaded_at' => $doc['uploaded_at'] ?? date('Y-m-d H:i:s')
                ];
                $uploaded_doc_types[] = $doc_type_normalized;
            }
        }
    }
    
    // PERBAIKAN: Cek dokumen wajib dengan logika yang lebih fleksibel
    $has_formulir = false;
    $has_foto = false;
    $has_kk_ktp = false;
    
    foreach ($uploaded_doc_types as $doc_type) {
        // Cek formulir
        if (in_array($doc_type, $required_docs_main)) {
            $has_formulir = true;
        }
        // Cek foto
        if (in_array($doc_type, $required_docs_photo)) {
            $has_foto = true;
        }
        // Cek dokumen identitas
        if (in_array($doc_type, $required_docs_id)) {
            $has_kk_ktp = true;
        }
    }
    
    $missingDocs = [];
    if (!$has_formulir) {
        $missingDocs[] = 'Formulir F-1.03 Pindah';
    }
    if (!$has_foto) {
        $missingDocs[] = 'Foto pemohon memegang KK/KTP/Form pindah';
    }
    if (!$has_kk_ktp) {
        $missingDocs[] = 'KK/KTP/Surat keterangan hilang KK atau KTP';
    }
    
    if (!empty($missingDocs)) {
        $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingDocs);
    }
    
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        // Ambil nama kecamatan dan desa
        $nama_kecamatan = '';
        $nama_desa = '';
        
        $kec_stmt = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $kec_stmt->bind_param('i', $id_kecamatan);
        $kec_stmt->execute();
        $kec_stmt->bind_result($nama_kecamatan);
        $kec_stmt->fetch();
        $kec_stmt->close();
        
        $desa_stmt = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $desa_stmt->bind_param('i', $id_desa);
        $desa_stmt->execute();
        $desa_stmt->bind_result($nama_desa);
        $desa_stmt->fetch();
        $desa_stmt->close();
        
        // Update data permohonan
        $stmt = $koneksi->prepare("UPDATE tb_pindah_alamat SET 
            nama_pemohon = ?,
            nik_pemohon = ?,
            no_hp = ?,
            email = ?,
            id_kecamatan = ?,
            id_desa = ?,
            nama_kecamatan = ?,
            nama_desa = ?,
            no_kk = ?,
            nama_kepalakeluarga = ?,
            alamat_asal = ?,
            alamat_tujuan = ?,
            alasan_pindah = ?,
            anggotaKeluarga = ?,
            keterangan = ?,
            dokumen = ?,
            status = 'diajukan',
            alasan = NULL,
            nama_folder = ?,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?");

        $anggota_keluarga_json = json_encode($anggota_keluarga_baru);
        $dokumen_json = json_encode($dokumen_baru);

        $stmt->bind_param('ssssiisssssssssssii', 
            $nama_pemohon,
            $nik_pemohon,
            $no_hp,
            $email,
            $id_kecamatan,
            $id_desa,
            $nama_kecamatan,
            $nama_desa,
            $no_kk,
            $nama_kepalakeluarga,
            $alamat_asal,
            $alamat_tujuan,
            $alasan_pindah,
            $anggota_keluarga_json,
            $keterangan,
            $dokumen_json,
            $nama_folder,
            $permohonan_id,
            $user_id
        );
        
        if ($stmt->execute()) {
            logPermohonanStatusHistory(
                $koneksi,
                'tb_pindah_alamat',
                $permohonan_id,
                'diajukan',
                null,
                (int)($_SESSION['id_user'] ?? 0),
                (string)($_SESSION['role'] ?? 'user'),
                'resubmit'
            );
            $success = true;
            $_SESSION['success_message'] = "Permohonan pindah alamat berhasil diperbarui dan diajukan kembali!";
            header('Location: status_permohonan.php');
            exit;
        } else {
            $errors[] = "Gagal memperbarui data: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Fungsi helper
function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function selected($value, $compare) {
    return $value == $compare ? 'selected' : '';
}

function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' Bytes';
    }
}

// Fungsi untuk mendapatkan label dokumen
function getDocTypeLabel($type) {
    $labels = [
        'formulir_f103' => 'Formulir F-1.03 Pindah',
        'formulir_pindah' => 'Formulir F-1.03 Pindah',
        'foto_pemohon' => 'Foto Pemohon',
        'kk_ktp_hilang' => 'KK/KTP Hilang',
        'kk_ktp' => 'KK/KTP',
        'buku_nikah' => 'Buku Nikah',
        'lainnya' => 'Dokumen Lain'
    ];
    return $labels[$type] ?? $type;
}

// Fungsi untuk mendapatkan badge class
function getDocTypeBadgeClass($type) {
    $badge_classes = [
        'formulir_f103' => 'badge-formulir_pindah',
        'formulir_pindah' => 'badge-formulir_pindah',
        'foto_pemohon' => 'badge-foto_pemohon',
        'kk_ktp_hilang' => 'badge-kk_ktp',
        'kk_ktp' => 'badge-kk_ktp',
        'buku_nikah' => 'badge-buku_nikah',
        'lainnya' => 'badge-lainnya'
    ];
    return $badge_classes[$type] ?? 'badge-lainnya';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
    <title>Edit Permohonan Pindah Alamat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/edit_pindah_alamat.css">
    <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
    <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-line">

<div class="layout">
    <!-- PERBAIKAN SIDEBAR: Sama dengan dashboard_user.php -->
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header user-general-header">
            <?php
            require_once __DIR__ . '/user_header_brand.php';
            render_user_header_brand('Edit Permohonan Pindah Alamat');
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
                    <h2>Edit Permohonan Pindah Alamat</h2>
                    <span class="status-badge">Status: <?= esc(ucfirst(strtolower((string)($permohonan['status'] ?? '-')))) ?></span>
                </div>

                <form method="POST" enctype="multipart/form-data" id="editPindahAlamatForm">
<?php echo csrf_input(); ?>
                    <!-- Data Pemohon -->
                    <div class="form-section">
                        <h3 class="section-title">Data Pemohon</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Pemohon <span class="required">*</span></label>
                                <input type="text" name="nama_pemohon" class="form-control" 
                                       value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>" readonly required>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Pemohon <span class="required">*</span></label>
                                <input type="text" name="nik_pemohon" class="form-control" 
                                       value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>" readonly required>
                            </div>
                            
                            <div class="form-group">
                                <label>No HP <span class="required">*</span></label>
                                <input type="tel" name="no_hp" class="form-control" 
                                       value="<?= esc($permohonan['no_hp'] ?? '') ?>" readonly required>
                            </div>
                            
                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?= esc($permohonan['email'] ?? '') ?>" readonly required>
                            </div>
                        </div>
                    </div>

                    <!-- Data Kartu Keluarga -->
                    <div class="form-section">
                        <h3 class="section-title">Data Kartu Keluarga</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nomor Kartu Keluarga (No KK) <span class="required">*</span></label>
                                <input type="text" name="no_kk" class="form-control" 
                                       value="<?= esc($permohonan['no_kk'] ?? '') ?>" 
                                       pattern="[0-9]{16}" maxlength="16"
                                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                                       required>
                                <small class="text-muted">16 digit nomor KK</small>
                            </div>
                            
                            <div class="form-group">
                                <label>Nama Kepala Keluarga <span class="required">*</span></label>
                                <input type="text" name="nama_kepalakeluarga" class="form-control" 
                                       value="<?= esc($permohonan['nama_kepalakeluarga'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Alamat Asal -->
                    <div class="form-section">
                        <h3 class="section-title">Alamat Asal</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Kecamatan Asal <span class="required">*</span></label>
                                <select name="id_kecamatan_asal" id="kecamatanSelectAsal" class="form-control" 
                                        onchange="getDesaByKecamatan(this.value, 'desaSelectAsal')" required>
                                    <option value="">Pilih Kecamatan</option>
                                    <?php foreach ($kecamatan_options as $kec): ?>
                                        <option value="<?= $kec['id'] ?>" 
                                            <?= selected($kec['id'], $permohonan['id_kecamatan'] ?? '') ?>>
                                            <?= esc($kec['nama_kecamatan']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Desa Asal <span class="required">*</span></label>
                                <select name="id_desa_asal" id="desaSelectAsal" class="form-control" required>
                                    <option value="">Pilih Desa</option>
                                    <?php foreach ($desa_options as $desa): ?>
                                        <option value="<?= $desa['id'] ?>" 
                                            <?= selected($desa['id'], $permohonan['id_desa'] ?? '') ?>>
                                            <?= esc($desa['nama_desa']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Kecamatan Asal</label>
                                <input type="text" id="namaKecamatanAsal" class="form-control bg-gray-100" 
                                       value="<?= esc($permohonan['nama_kecamatan'] ?? '') ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Nama Desa Asal</label>
                                <input type="text" id="namaDesaAsal" class="form-control bg-gray-100" 
                                       value="<?= esc($permohonan['nama_desa'] ?? '') ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Alamat Asal Lengkap <span class="required">*</span></label>
                            <textarea name="alamat_asal" class="form-control" rows="3" required
                                      placeholder="Alamat lengkap saat ini (nama jalan, RT/RW, dusun, nomor rumah)"><?= esc($permohonan['alamat_asal'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Alamat Tujuan -->
                    <div class="form-section">
                        <h3 class="section-title">Alamat Tujuan</h3>
                        <div class="form-group">
                            <label>Alamat Tujuan Lengkap <span class="required">*</span></label>
                            <textarea name="alamat_tujuan" class="form-control" rows="3" required
                                      placeholder="Alamat lengkap tujuan baru (nama jalan, RT/RW, dusun, kelurahan, kecamatan, kabupaten, provinsi)"><?= esc($permohonan['alamat_tujuan'] ?? '') ?></textarea>
                            <small class="text-muted">Contoh: Jl. Merdeka No. 123, RT 01/RW 02, Kelurahan Suka Maju, Kecamatan Suka Makmur, Kabupaten Suka Damai, Provinsi Suka Bahagia</small>
                        </div>
                    </div>

                    <!-- Alasan Pindah -->
                    <div class="form-section">
                        <h3 class="section-title">Alasan Pindah</h3>
                        <div class="form-group">
                            <label>Alasan Pindah <span class="required">*</span></label>
                            <select name="alasan_pindah" class="form-control" required>
                                <option value="">-- Pilih Alasan Pindah --</option>
                                <option value="kerja" <?= selected('kerja', $permohonan['alasan_pindah'] ?? '') ?>>Pekerjaan</option>
                                <option value="pendidikan" <?= selected('pendidikan', $permohonan['alasan_pindah'] ?? '') ?>>Pendidikan</option>
                                <option value="keluarga" <?= selected('keluarga', $permohonan['alasan_pindah'] ?? '') ?>>Keluarga</option>
                                <option value="rumah" <?= selected('rumah', $permohonan['alasan_pindah'] ?? '') ?>>Pindah Rumah</option>
                                <option value="lainnya" <?= selected('lainnya', $permohonan['alasan_pindah'] ?? '') ?>>Lainnya</option>
                            </select>
                        </div>
                    </div>

                    <!-- Anggota Keluarga -->
                    <div class="form-section">
                        <h3 class="section-title">Anggota Keluarga yang Pindah</h3>
                        <table class="anggota-table" id="anggotaTable">
                            <thead>
                                <tr>
                                    <th width="40%">Nama Lengkap</th>
                                    <th width="30%">NIK</th>
                                    <th width="25%">Hubungan Keluarga</th>
                                    <th width="5%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="anggotaBody">
                                <?php if (!empty($anggota_keluarga)): ?>
                                    <?php foreach ($anggota_keluarga as $index => $anggota): ?>
                                    <tr>
                                        <td>
                                            <input type="text" name="anggota_nama[]" 
                                                   value="<?= esc($anggota['nama'] ?? '') ?>" 
                                                   placeholder="Nama lengkap" required>
                                        </td>
                                        <td>
                                            <input type="text" name="anggota_nik[]" 
                                                   value="<?= esc($anggota['nik'] ?? '') ?>" 
                                                   placeholder="16 digit NIK"
                                                   pattern="[0-9]{16}" maxlength="16"
                                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                                        </td>
                                        <td>
                                            <input type="text" name="anggota_hubungan[]" 
                                                   value="<?= esc($anggota['hubungan'] ?? '') ?>" 
                                                   placeholder="Contoh: Anak, Istri">
                                        </td>
                                        <td>
                                            <?php if ($index > 0): ?>
                                            <button type="button" class="btn-remove-anggotam" 
                                                    onclick="removeAnggota(this)">×</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td>
                                        <input type="text" name="anggota_nama[]" 
                                               placeholder="Nama lengkap" required>
                                    </td>
                                    <td>
                                        <input type="text" name="anggota_nik[]" 
                                               placeholder="16 digit NIK"
                                               pattern="[0-9]{16}" maxlength="16"
                                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                                    </td>
                                    <td>
                                        <input type="text" name="anggota_hubungan[]" 
                                               placeholder="Contoh: Anak, Istri">
                                    </td>
                                    <td></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <button type="button" class="btn-add-anggotam" onclick="addAnggota()">
                            <i class="fas fa-plus"></i> Tambah Anggota Keluarga
                        </button>
                    </div>

                    <!-- Dokumen Pendukung -->
                    <div class="form-section">
                        <h3 class="section-title">Dokumen Pendukung</h3>
                        
                        <!-- Info dokumen -->
                        <div class="f-doc-group">
                            <p class="f-doc-group__judul"><strong>Petunjuk:</strong></p>
                            <ul style="margin: 0; padding-left: 20px; color: #4b5563;">
                                <li><strong>Dokumen wajib:</strong> Formulir F-1.03 Pindah, Foto pemohon memegang KK/KTP/Form pindah, dan KK/KTP/Surat keterangan hilang</li>
                                <li>Dokumen tambahan (jika ada): Buku nikah/akta nikah, dll</li>
                                <li>Format file yang diperbolehkan: JPG, JPEG, PNG</li>
                                <li>Ukuran maksimal per file: 1MB</li>
                            </ul>
                        </div>
                        
                        <!-- Dokumen yang sudah ada -->
                        <?php if (!empty($dokumen_data)): ?>
                        <div class="document-list" id="existing-documents">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Dokumen yang Sudah Ada:</h4>
                            <?php foreach ($dokumen_data as $index => $doc): ?>
                                <div class="document-item" data-index="<?= $index ?>">
                                    <div class="doc-info">
                                        <?php 
                                        $badge_class = getDocTypeBadgeClass($doc['type']);
                                        $type_label = getDocTypeLabel($doc['type']);
                                        ?>
                                        <span class="doc-type-badge <?= $badge_class ?>"><?= $type_label ?></span>
                                        <span class="doc-name"><?= esc($doc['original_name']) ?></span>
                                        <input type="hidden" name="existing_doc_type[<?= $index ?>]" value="<?= esc($doc['type']) ?>">
                                    </div>
                                    <div class="doc-actions">
                                        <input type="checkbox" name="existing_docs[]" value="<?= $index ?>" checked style="margin-right: 10px;">
                                        <?php if (file_exists($doc['path'])): ?>
                                        <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$doc['path']) ?? '') ?>')">
                                            <i class="fas fa-eye"></i> Lihat
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn-remove" onclick="removeExistingDocument(this, <?= $index ?>)">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Dokumen Tambahan -->
                        <div id="dokumen-tambahan-container">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Tambah/Perbarui Dokumen:</h4>
                            <div id="dokumen-tambahan-list">
                                <!-- Dokumen tambahan akan ditambahkan di sini -->
                            </div>
                            <button type="button" class="btn-add" onclick="addDokumenInput()">
                                <i class="fas fa-plus"></i> Tambah Dokumen
                            </button>
                        </div>
                    </div>

                    <!-- Keterangan -->
                    <div class="form-section">
                        <h3 class="section-title">Keterangan</h3>
                        <div class="form-group">
                            <label>Keterangan Tambahan</label>
                            <textarea name="keterangan" class="form-control" rows="3" 
                                      placeholder="Keterangan tambahan untuk operator (jika ada)" maxlength="500"><?= esc($permohonan['keterangan'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Alasan Penolakan -->
                    <?php if (!empty($permohonan['alasan'])): ?>
                    <div class="form-section">
                        <h3 class="section-title" style="color: #dc2626;">Alasan Penolakan Sebelumnya</h3>
                        <div class="form-group">
                            <div style="background: #fee2e2; padding: 15px; border-radius: 6px; border: 1px solid #fecaca;">
                                <strong>Catatan dari Admin:</strong><br>
                                <?= nl2br(esc($permohonan['alasan'])) ?>
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

<!-- Modal Logout - Sama dengan dashboard_user.php -->
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
// Toggle Sidebar Menu on Mobile - SAMA dengan dashboard_user.php
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
    
    // Logout Modal - SAMA dengan dashboard_user.php
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
    
    // Set kecamatan dropdown value dan load desa jika sudah ada nilai
    <?php if (!empty($permohonan['id_kecamatan'])): ?>
    setTimeout(() => {
        const kecamatanSelect = document.getElementById('kecamatanSelectAsal');
        if (kecamatanSelect) {
            kecamatanSelect.value = <?= $permohonan['id_kecamatan'] ?>;
            getDesaByKecamatan(<?= $permohonan['id_kecamatan'] ?>, 'desaSelectAsal');
        }
    }, 100);
    <?php endif; ?>
});

// Anggota Keluarga Functions
function addAnggota() {
    const tableBody = document.getElementById('anggotaBody');
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td>
            <input type="text" name="anggota_nama[]" placeholder="Nama lengkap" required>
        </td>
        <td>
            <input type="text" name="anggota_nik[]" placeholder="16 digit NIK"
                   pattern="[0-9]{16}" maxlength="16"
                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
        </td>
        <td>
            <input type="text" name="anggota_hubungan[]" placeholder="Contoh: Anak, Istri">
        </td>
        <td>
            <button type="button" class="btn-remove-anggotam" onclick="removeAnggota(this)">×</button>
        </td>
    `;
    tableBody.appendChild(newRow);
}

function removeAnggota(button) {
    const row = button.closest('tr');
    row.remove();
}

// Dokumen Input Functions
let dokumenCounter = 0;

// Stabilkan file upload lintas device/browser (terutama mobile/cloud picker)
// dengan mengkloning file ke memori saat dipilih.
function stabilizeSelectedFile(input) {
    return; // Upload HTML standar; kompatibel tanpa File/DataTransfer API.
    if (!input || !input.files || input.files.length === 0) {
        if (input) input.dataset.fileStable = '1';
        return;
    }

    const original = input.files[0];
    try {
        const buffer = null;
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
function addDokumenInput() {
    const container = document.getElementById('dokumen-tambahan-list');
    const newItem = document.createElement('div');
    newItem.className = 'file-upload-group';
    newItem.innerHTML = `
        <select name="doc_type[]" class="dokumen-type-select" required>
            <option value="">Pilih Jenis Dokumen</option>
            <option value="formulir_pindah">Formulir F-1.03 Pindah</option>
            <option value="foto_pemohon">Foto pemohon memegang KK/KTP/Form pindah</option>
            <option value="kk_ktp">KK/KTP/Surat keterangan hilang KK atau KTP</option>
            <option value="buku_nikah">Buku nikah/akta nikah</option>
            <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div class="file-upload">
            <label class="file-upload-label">
                <i class="fas fa-upload"></i> Unggah File
                <input type="file" name="dokumen[]" accept=".jpg,.jpeg,.png" required>
            </label>
            <div class="file-preview">
                <span class="file-name">Belum ada file dipilih</span>
            </div>
        </div>
        <button type="button" class="btn-remove" onclick="removeDokumenInput(this)" style="margin-top: 10px;">
            <i class="fas fa-times"></i> Hapus Dokumen
        </button>
    `;
    
    container.appendChild(newItem);
    dokumenCounter++;
    
    // Add file change event to the new input
    const fileInput = newItem.querySelector('input[type="file"]');
    const filePreview = newItem.querySelector('.file-preview');
    
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            const file = this.files[0];
            const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
            
            if (file.size > 1 * 1024 * 1024) {
                alert(`File ${file.name} terlalu besar (${fileSizeMB} MB). Maksimal 1MB.`);
                this.value = '';
                filePreview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
                return;
            }
            
            // Validasi tipe file
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            if (!allowedTypes.includes(file.type)) {
                alert(`File ${file.name} format tidak didukung. Hanya JPG, PNG yang diperbolehkan.`);
                this.value = '';
                filePreview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
                return;
            }
            
            filePreview.innerHTML = `
                <span class="file-name">${file.name}</span><br>
                <small>Ukuran: ${fileSizeMB} MB</small>
            `;
        } else {
            filePreview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
        }
    });
}

function removeDokumenInput(button) {
    const item = button.closest('.file-upload-group');
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
    const extension = path.split('.').pop().toLowerCase();
    
    if (['jpg', 'jpeg', 'png'].includes(extension)) {
        previewImage(path);
    } else if (extension === 'pdf') {
        previewPDF(path);
    } else {
        alert('Format file tidak didukung untuk preview.');
    }
}

function previewImage(src) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    content.innerHTML = `<img src="${src}" style="max-width: 100%; max-height: 70vh; display: block; margin: 0 auto;">`;
    modal.style.display = 'flex';
}

function previewPDF(src) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    content.innerHTML = `
        <iframe src="${src}" style="width: 80vw; height: 80vh; border: none;"></iframe>
        <p style="text-align: center; margin-top: 10px;">
            <a href="${src}" target="_blank" style="color: #3b82f6;">Buka di tab baru</a>
        </p>
    `;
    modal.style.display = 'flex';
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewContent').innerHTML = '';
}

// Get Desa by Kecamatan
async function getDesaByKecamatan(kecamatanId, desaSelectId) {
    console.log('getDesaByKecamatan called with:', kecamatanId, 'for select:', desaSelectId);
    
    const desaSelect = document.getElementById(desaSelectId);
    const namaKecamatanInput = document.getElementById('namaKecamatanAsal');
    const namaDesaInput = document.getElementById('namaDesaAsal');
    
    if (!desaSelect) {
        console.error('ERROR: Element #' + desaSelectId + ' not found!');
        return;
    }
    
    if (!kecamatanId || kecamatanId === '') {
        desaSelect.innerHTML = '<option value="">Pilih Kecamatan terlebih dahulu</option>';
        namaKecamatanInput.value = '';
        namaDesaInput.value = '';
        return;
    }
    
    // Set nama kecamatan
    const kecamatanSelect = document.getElementById('kecamatanSelectAsal');
    if (kecamatanSelect && namaKecamatanInput) {
        namaKecamatanInput.value = kecamatanSelect.options[kecamatanSelect.selectedIndex].text;
    }
    
    // Clear nama desa
    if (namaDesaInput) {
        namaDesaInput.value = '';
    }
    
    // Show loading state
    desaSelect.innerHTML = '<option value="">Memuat data desa...</option>';
    desaSelect.disabled = true;
    
    try {
        // Coba kedua kemungkinan URL
        const urls = [
            `get_desa.php?kecamatan_id=${kecamatanId}`,
            `get_desa.php?id_kecamatan=${kecamatanId}`
        ];
        
        let response = null;
        let lastError = null;
        
        // Coba semua URL
        for (const url of urls) {
            try {
                console.log('Trying URL:', url);
                response = await fetch(url);
                
                if (response.ok) {
                    const data = await response.text();
                    console.log('Response from', url, ':', data.substring(0, 100));
                    
                    if (data && !data.includes('Error') && !data.includes('Tidak ada')) {
                        desaSelect.innerHTML = '<option value="">Pilih Desa</option>' + data;
                        
                        // Set nilai desa jika ada
                        <?php if (!empty($permohonan['id_desa'])): ?>
                        setTimeout(() => {
                            if (desaSelect.value !== <?= $permohonan['id_desa'] ?>) {
                                desaSelect.value = <?= $permohonan['id_desa'] ?>;
                                console.log('Set desa value to:', <?= $permohonan['id_desa'] ?>);
                                
                                // Set nama desa
                                if (namaDesaInput && desaSelect.options[desaSelect.selectedIndex]) {
                                    namaDesaInput.value = desaSelect.options[desaSelect.selectedIndex].text;
                                }
                            }
                        }, 100);
                        <?php endif; ?>
                        
                        desaSelect.disabled = false;
                        return;
                    }
                }
            } catch (err) {
                lastError = err;
                console.warn('Failed with URL', url, ':', err.message);
            }
        }
        
        // Jika semua gagal
        throw new Error(lastError || 'All URLs failed');
        
    } catch (error) {
        console.error('Semua percobaan gagal:', error);
        
        // Fallback ke data dari PHP
        <?php if (!empty($desa_options)): ?>
        console.log('Using PHP fallback data');
        let options = '<option value="">Pilih Desa</option>';
        <?php foreach ($desa_options as $desa): ?>
        options += `<option value="<?= $desa['id'] ?>"><?= addslashes($desa['nama_desa']) ?></option>`;
        <?php endforeach; ?>
        desaSelect.innerHTML = options;
        
        <?php if (!empty($permohonan['id_desa'])): ?>
        setTimeout(() => {
            desaSelect.value = <?= $permohonan['id_desa'] ?>;
            if (namaDesaInput && desaSelect.options[desaSelect.selectedIndex]) {
                namaDesaInput.value = desaSelect.options[desaSelect.selectedIndex].text;
            }
        }, 100);
        <?php endif; ?>
        <?php else: ?>
        desaSelect.innerHTML = '<option value="">Error: ' + error.message + '</option>';
        <?php endif; ?>
    } finally {
        desaSelect.disabled = false;
    }
}

// Event untuk update nama desa ketika desa dipilih
document.addEventListener('DOMContentLoaded', function() {
    const desaSelectAsal = document.getElementById('desaSelectAsal');
    const namaDesaAsal = document.getElementById('namaDesaAsal');
    
    if (desaSelectAsal && namaDesaAsal) {
        desaSelectAsal.addEventListener('change', function() {
            if (this.value !== "" && this.options[this.selectedIndex]) {
                namaDesaAsal.value = this.options[this.selectedIndex].text;
            } else {
                namaDesaAsal.value = "";
            }
        });
    }
});

// Delegasi global untuk menstabilkan setiap file yang dipilih.
document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    stabilizeSelectedFile(target);
});
// Form Validation - DIPERBAIKI
document.getElementById('editPindahAlamatForm').addEventListener('submit', async function(e) {
    let isValid = true;
    let errorMessages = [];
    
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
    
    // Validate No KK 16 digit
    const noKKInput = document.querySelector('input[name="no_kk"]');
    if (noKKInput && noKKInput.value.length !== 16) {
        errorMessages.push('Nomor KK harus 16 digit');
        noKKInput.classList.add('is-invalid');
        isValid = false;
    }
    
    // Validate at least one anggota keluarga
    const anggotaRows = document.querySelectorAll('#anggotaBody tr');
    let hasAnggota = false;
    anggotaRows.forEach(row => {
        const namaInput = row.querySelector('input[name="anggota_nama[]"]');
        if (namaInput && namaInput.value.trim()) {
            hasAnggota = true;
        }
    });
    
    if (!hasAnggota) {
        errorMessages.push('Minimal harus ada satu anggota keluarga');
        isValid = false;
    }
    
    // Validate NIK anggota jika diisi harus 16 digit
    const nikInputs = document.querySelectorAll('input[name="anggota_nik[]"]');
    nikInputs.forEach((input, index) => {
        if (input.value && input.value.length !== 16) {
            errorMessages.push(`NIK anggota baris ${index + 1} harus 16 digit (kosongkan jika tidak ada)`);
            input.classList.add('is-invalid');
            isValid = false;
        }
    });
    
    // PERBAIKAN: Validasi dokumen wajib dengan fleksibilitas
    const existingDocs = document.querySelectorAll('input[name="existing_docs[]"]:checked');
    const newDocInputs = document.querySelectorAll('input[name="dokumen[]"]');
    const existingDocTypeInputs = document.querySelectorAll('input[name^="existing_doc_type"]');
    
    // Mapping jenis dokumen untuk normalisasi
    const docTypeMapping = {
        'formulir_f103': 'formulir_pindah',
        'formulir_pindah': 'formulir_pindah',
        'foto_pemohon': 'foto_pemohon',
        'kk_ktp_hilang': 'kk_ktp',
        'kk_ktp': 'kk_ktp'
    };
    
    let docTypes = [];
    
    // Ambil jenis dokumen dari existing docs dengan normalisasi
    existingDocs.forEach(checkbox => {
        const docItem = checkbox.closest('.document-item');
        const docTypeInput = docItem.querySelector('input[name^="existing_doc_type"]');
        if (docTypeInput) {
            const originalType = docTypeInput.value;
            // Normalisasi jenis dokumen
            const normalizedType = docTypeMapping[originalType] || originalType;
            docTypes.push(normalizedType);
        }
    });
    
    // Ambil jenis dokumen dari new docs
    newDocInputs.forEach((input, index) => {
        if (input.files.length > 0) {
            const select = input.closest('.file-upload-group').querySelector('select[name="doc_type[]"]');
            if (select && select.value) {
                docTypes.push(select.value);
            }
        }
    });
    
    // Cek dokumen wajib dengan fleksibilitas
    const hasFormulir = docTypes.some(type => 
        type === 'formulir_pindah' || type === 'formulir_f103'
    );
    const hasFoto = docTypes.some(type => 
        type === 'foto_pemohon'
    );
    const hasKKKTP = docTypes.some(type => 
        type === 'kk_ktp' || type === 'kk_ktp_hilang'
    );
    
    const missingDocs = [];
    if (!hasFormulir) {
        missingDocs.push('Formulir F-1.03 Pindah');
    }
    if (!hasFoto) {
        missingDocs.push('Foto pemohon memegang KK/KTP/Form pindah');
    }
    if (!hasKKKTP) {
        missingDocs.push('KK/KTP/Surat keterangan hilang');
    }
    
    if (missingDocs.length > 0) {
        errorMessages.push('Dokumen wajib belum diupload: ' + missingDocs.join(', '));
        isValid = false;
    }
    
    // Validate file sizes for new uploads
    newDocInputs.forEach(input => {
        if (input.files.length > 0) {
            if (input.dataset.fileStable === '0') {
                // Fallback mode: jangan blok submit, karena beberapa browser mobile
                // gagal clone file meski file lokal tetap valid untuk upload normal.
                console.warn(`File "${input.files[0].name}" tidak bisa distabilkan, lanjut upload mode normal.`);
            }            const file = input.files[0];
            if (file.size > 1 * 1024 * 1024) {
                errorMessages.push(`File ${file.name} terlalu besar (maksimal 1MB)`);
                isValid = false;
            }
        }
    });
    
    if (!isValid) {
        e.preventDefault();
        let message = 'Harap perbaiki kesalahan berikut:\n\n';
        if (errorMessages.length > 0) {
            message += '• ' + errorMessages.join('\n• ');
        } else {
            message += '• Harap lengkapi semua bidang yang wajib diisi';
        }
        alert(message);
    } else {
        const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan kembali permohonan pindah alamat?');
        if (!confirmSubmit) {
            e.preventDefault();
            return;
        }

        // Refresh CSRF token tepat sebelum submit untuk mencegah token stale.
        e.preventDefault();
        const form = this;
        let csrfInput = form.querySelector('input[name="csrf_token"]');
        if (!csrfInput) {
            csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            form.appendChild(csrfInput);
        }

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
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta && csrfMeta.content) {
                csrfInput.value = csrfMeta.content;
            }
        }

        form.submit();
    }
});
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>


