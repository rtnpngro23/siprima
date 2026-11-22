<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/petugas_desa_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kematian LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// Cek apakah ID permohonan diberikan
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: petugas_desa_status_permohonan.php?error=no_id');
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
    dusun,
    Nama_meninggal, 
    Nik_meninggal,
    tanggal_kematian,
    Tempat,
    Nama_Ayah, 
    Nik_ayah, 
    Nama_Ibu, 
    Nik_ibu,
    nama_saksi1,
    nik_saksi1,
    nama_saksi2,
    nik_saksi2,
    keterangan,
    dokumen, 
    status,
    alasan,
    nama_folder
    FROM tb_akta_kematian 
    WHERE id = ? AND id_user = ?");
$stmt->bind_param('ii', $permohonan_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header('Location: petugas_desa_status_permohonan.php?error=not_found');
    exit;
}

$permohonan = $res->fetch_assoc();

// Hanya izinkan edit saat status diajukan atau ditolak
if (!in_array(strtolower((string)$permohonan['status']), ['diajukan', 'ditolak'], true)) {
    header('Location: petugas_desa_status_permohonan.php?error=cannot_edit');
    exit;
}

// Ambil data user untuk mendapatkan wilayah tugas
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

// Dekode data JSON untuk dokumen
$dokumen_data = [];
if (!empty($permohonan['dokumen'])) {
    $dokumen_data = json_decode($permohonan['dokumen'], true);
    if (!is_array($dokumen_data)) {
        $dokumen_data = [];
    }
}

// Proses form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') siprima_validate_request_images($errors);
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Data pemohon TIDAK bisa diubah - ambil dari data lama
    $nama_pemohon = $permohonan['nama_pemohon'] ?? '';
    $nik_pemohon = $permohonan['nik_pemohon'] ?? '';
    $no_hp = $permohonan['no_hp'] ?? '';
    $email = $permohonan['email'] ?? '';
    
    // Validasi: cek jika ada upaya mengubah data pemohon
    if ((isset($_POST['nama_pemohon']) && trim($_POST['nama_pemohon']) != $permohonan['nama_pemohon']) ||
        (isset($_POST['nik_pemohon']) && trim($_POST['nik_pemohon']) != $permohonan['nik_pemohon']) ||
        (isset($_POST['no_hp']) && trim($_POST['no_hp']) != $permohonan['no_hp']) ||
        (isset($_POST['email']) && trim($_POST['email']) != $permohonan['email'])) {
        $errors[] = 'Data pemohon tidak dapat diubah.';
    }
    
    // Kecamatan dan Desa TIDAK bisa diubah - ambil dari data user
    $id_kecamatan = $user_kecamatan_id;
    $id_desa = $user_desa_id;
    $nama_kecamatan_db = $nama_kecamatan_user;
    $nama_desa_db = $nama_desa_user;
    
    // Validasi kecamatan/desa sesuai user
    if (isset($_POST['id_kecamatan']) && (int)$_POST['id_kecamatan'] != $user_kecamatan_id) {
        $errors[] = "Anda tidak memiliki akses untuk mengubah kecamatan.";
    }
    if (isset($_POST['id_desa']) && (int)$_POST['id_desa'] != $user_desa_id) {
        $errors[] = "Anda tidak memiliki akses untuk mengubah desa.";
    }
    
    // Data kematian, orang tua, dan saksi (bisa diubah)
    $dusun = trim($_POST['dusun'] ?? '');
    $nama_meninggal = trim($_POST['nama_meninggal'] ?? '');
    $nik_meninggal = trim($_POST['nik_meninggal'] ?? '');
    $tanggal_kematian = trim($_POST['tanggal_kematian'] ?? '');
    $tempat = trim($_POST['tempat'] ?? '');
    $nama_ayah = trim($_POST['nama_ayah'] ?? '');
    $nik_ayah = trim($_POST['nik_ayah'] ?? '');
    $nama_ibu = trim($_POST['nama_ibu'] ?? '');
    $nik_ibu = trim($_POST['nik_ibu'] ?? '');
    $nama_saksi1 = trim($_POST['nama_saksi1'] ?? '');
    $nik_saksi1 = trim($_POST['nik_saksi1'] ?? '');
    $nama_saksi2 = trim($_POST['nama_saksi2'] ?? '');
    $nik_saksi2 = trim($_POST['nik_saksi2'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi
    if (empty($nama_meninggal)) $errors[] = "Nama yang meninggal harus diisi";
    if (empty($nik_meninggal)) $errors[] = "NIK yang meninggal harus diisi";
    if (empty($tempat)) $errors[] = "Tempat kematian harus diisi";
    if (empty($tanggal_kematian)) $errors[] = "Tanggal kematian harus diisi";
    if (empty($nama_ayah)) $errors[] = "Nama ayah harus diisi";
    if (empty($nik_ayah)) $errors[] = "NIK ayah harus diisi";
    if (empty($nama_ibu)) $errors[] = "Nama ibu harus diisi";
    if (empty($nik_ibu)) $errors[] = "NIK ibu harus diisi";
    if (empty($nama_saksi1)) $errors[] = "Nama saksi 1 harus diisi";
    if (empty($nik_saksi1)) $errors[] = "NIK saksi 1 harus diisi";
    if (empty($nama_saksi2)) $errors[] = "Nama saksi 2 harus diisi";
    if (empty($nik_saksi2)) $errors[] = "NIK saksi 2 harus diisi";
    
    // Validasi format NIK
    if (!empty($nik_meninggal) && !preg_match('/^[0-9]{16}$/', $nik_meninggal)) {
        $errors[] = "NIK yang meninggal harus 16 digit angka";
    }
    if (!empty($nik_ayah) && !preg_match('/^[0-9]{16}$/', $nik_ayah)) {
        $errors[] = "NIK ayah harus 16 digit angka";
    }
    if (!empty($nik_ibu) && !preg_match('/^[0-9]{16}$/', $nik_ibu)) {
        $errors[] = "NIK ibu harus 16 digit angka";
    }
    if (!empty($nik_saksi1) && !preg_match('/^[0-9]{16}$/', $nik_saksi1)) {
        $errors[] = "NIK saksi 1 harus 16 digit angka";
    }
    if (!empty($nik_saksi2) && !preg_match('/^[0-9]{16}$/', $nik_saksi2)) {
        $errors[] = "NIK saksi 2 harus 16 digit angka";
    }
    
    // Validasi format tanggal
    if (!empty($tanggal_kematian)) {
        $date = DateTime::createFromFormat('Y-m-d', $tanggal_kematian);
        if (!$date || $date->format('Y-m-d') !== $tanggal_kematian) {
            $errors[] = "Format tanggal kematian tidak valid. Gunakan format: YYYY-MM-DD";
        }
    }
    
    // ================================================
    // BAGIAN PERBAIKAN: GUNAKAN FOLDER YANG SAMA
    // ================================================
    
    // Ambil nama folder dari database (dari permohonan pertama)
    $folder_name = $permohonan['nama_folder'] ?? '';
    
    // Path folder uploads
    $base_upload_dir = __DIR__ . '/uploads/akta_kematian/';
    $user_folder_path = $base_upload_dir . $folder_name . '/';
    
    // Buat folder jika belum ada (sebagai cadangan)
    if (!file_exists($user_folder_path)) {
        if (!mkdir($user_folder_path, 0755, true)) {
            $errors[] = 'Gagal membuat folder penyimpanan dokumen';
        } else {
            // Tambahkan file .htaccess untuk keamanan
            $htaccessFile = $user_folder_path . '/.htaccess';
            if (!file_exists($htaccessFile)) {
                file_put_contents($htaccessFile, 
                    "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
            }
        }
    }
    
    // Proses dokumen: gabungkan dokumen lama yang dipilih + dokumen baru
    $dokumen_baru = [];
    $uploadedDocTypes = [];
    
    // 1. Tambahkan dokumen lama yang dipilih (jika ada)
    if (isset($_POST['existing_docs']) && is_array($_POST['existing_docs'])) {
        foreach ($_POST['existing_docs'] as $doc_index) {
            if (isset($dokumen_data[$doc_index])) {
                $doc = $dokumen_data[$doc_index];
                $dokumen_baru[] = $doc;
                $uploadedDocTypes[] = $doc['type'];
            }
        }
    }
    
    // 2. Proses dokumen baru yang diupload
    $uploadedFiles = [];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name']) && empty($errors)) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        // Dapatkan array doc_type dan file_index dari POST
        $doc_types = isset($_POST['doc_type_new']) && is_array($_POST['doc_type_new']) ? $_POST['doc_type_new'] : [];
        $file_indexes = isset($_POST['file_index_new']) && is_array($_POST['file_index_new']) ? $_POST['file_index_new'] : [];
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileType = mime_content_type($fileTmp);
                $fileSize = $_FILES['dokumen']['size'][$i];
                
                // Ambil doc_type dari array yang sesuai
                $docType = isset($doc_types[$i]) ? $doc_types[$i] : 'lainnya';
                
                // Validasi tipe file
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) || 
                    !in_array($fileType, $allowedTypes)) {
                    $errors[] = "File {$fileName}: Hanya format JPG, PNG, atau GIF yang diperbolehkan";
                    continue;
                }
                
                // Validasi ukuran file
                if ($fileSize > $maxFileSize) {
                    $errors[] = "File {$fileName}: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
                    continue;
                }
                
                // Generate unique filename
                $timestamp_file = time() . '_' . $i;
                $randomString = bin2hex(random_bytes(6));
                $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                $newName = $clean_doc_type . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                $destination = $user_folder_path . $newName;
                
                if (move_uploaded_file($fileTmp, $destination)) {
                    $uploadedFiles[] = [
                        'path' => 'uploads/akta_kematian/' . $folder_name . '/' . $newName,
                        'type' => $docType,
                        'original_name' => $fileName,
                        'uploaded_name' => $newName,
                        'size' => $fileSize,
                        'uploaded_at' => date('Y-m-d H:i:s')
                    ];
                    $uploadedDocTypes[] = $docType;
                } else {
                    $errors[] = "File {$fileName}: Gagal menyimpan file";
                }
            } elseif ($_FILES['dokumen']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $errors[] = "Error upload file: " . $_FILES['dokumen']['name'][$i];
            }
        }
        
        // Tambahkan file baru ke dokumen_baru
        foreach ($uploadedFiles as $doc) {
            $dokumen_baru[] = $doc;
        }
    }
    
    // 3. Validasi dokumen wajib untuk akta kematian
    $requiredDocs = ['formulir_kematian', 'surat_keterangan_kematian', 'kk_ktp_meninggal'];
    $docTypesInNewData = array_column($dokumen_baru, 'type');
    
    $missingRequired = array_diff($requiredDocs, $docTypesInNewData);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            switch ($doc) {
                case 'formulir_kematian':
                    $missingNames[] = 'Formulir F-2.01 Kematian';
                    break;
                case 'surat_keterangan_kematian':
                    $missingNames[] = 'Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan';
                    break;
                case 'kk_ktp_meninggal':
                    $missingNames[] = 'KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal';
                    break;
            }
        }
        $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
    }
    
    // Jika tidak ada error, update data
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        // Update data permohonan
        $stmt = $koneksi->prepare("UPDATE tb_akta_kematian SET 
            nama_pemohon = ?,
            nik_pemohon = ?,
            no_hp = ?,
            email = ?,
            id_kecamatan = ?,
            id_desa = ?,
            nama_kecamatan = ?,
            nama_desa = ?,
            dusun = ?,
            Nama_meninggal = ?,
            Nik_meninggal = ?,
            tanggal_kematian = ?,
            Tempat = ?,
            Nama_Ayah = ?,
            Nik_ayah = ?,
            Nama_Ibu = ?,
            Nik_ibu = ?,
            nama_saksi1 = ?,
            nik_saksi1 = ?,
            nama_saksi2 = ?,
            nik_saksi2 = ?,
            keterangan = ?,
            dokumen = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?");
        
        $dokumen_json = json_encode($dokumen_baru);
        
        // Perbaiki bind_param - sesuaikan dengan jumlah parameter
        $stmt->bind_param('ssssiisssssssssssssssssii', 
            $nama_pemohon,
            $nik_pemohon,
            $no_hp,
            $email,
            $id_kecamatan,
            $id_desa,
            $nama_kecamatan_db,
            $nama_desa_db,
            $dusun,
            $nama_meninggal,
            $nik_meninggal,
            $tanggal_kematian,
            $tempat,
            $nama_ayah,
            $nik_ayah,
            $nama_ibu,
            $nik_ibu,
            $nama_saksi1,
            $nik_saksi1,
            $nama_saksi2,
            $nik_saksi2,
            $keterangan,
            $dokumen_json,
            $permohonan_id,
            $user_id
        );
        
        if ($stmt->execute()) {
            logPermohonanStatusHistory(
                $koneksi,
                'tb_akta_kematian',
                $permohonan_id,
                'diajukan',
                null,
                (int)($_SESSION['id_user'] ?? 0),
                (string)($_SESSION['role'] ?? 'user'),
                'resubmit'
            );
            $success = true;
            $_SESSION['success_message'] = "Permohonan akta kematian berhasil diperbarui dan diajukan kembali!";
            header('Location: petugas_desa_status_permohonan.php');
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

function iniSizeToBytes($size) {
    if (!is_string($size) || $size === '') {
        return 0;
    }
    $size = trim($size);
    $unit = strtolower(substr($size, -1));
    $value = (float)$size;
    switch ($unit) {
        case 'g':
            $value *= 1024;
            // fallthrough
        case 'm':
            $value *= 1024;
            // fallthrough
        case 'k':
            $value *= 1024;
            break;
    }
    return (int)$value;
}

$serverPostMaxBytes = iniSizeToBytes((string)ini_get('post_max_size'));
$serverUploadMaxBytes = iniSizeToBytes((string)ini_get('upload_max_filesize'));

// Fungsi untuk mendapatkan absolute path
function getAbsoluteFilePath($relativePath) {
    if (strpos($relativePath, '/') === 0 || preg_match('#^[a-z]:#i', $relativePath)) {
        return $relativePath;
    }
    return __DIR__ . '/' . $relativePath;
}

// Cari dokumen wajib untuk ditampilkan di form
$formulir_file = null;
$surat_kematian_file = null;
$identitas_file = null;

foreach ($dokumen_data as $doc) {
    $doc_type = $doc['type'] ?? '';
    
    if ($doc_type === 'formulir_kematian' && !$formulir_file) {
        $formulir_file = $doc;
    } elseif ($doc_type === 'surat_keterangan_kematian' && !$surat_kematian_file) {
        $surat_kematian_file = $doc;
    } elseif ($doc_type === 'kk_ktp_meninggal' && !$identitas_file) {
        $identitas_file = $doc;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>Edit Permohonan Akta Kematian - Petugas Desa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/petugas_desa_edit_akta_kematian.css">
    <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="petugas-desa-ui tema-form tema-add">

<div class="layout">
    <!-- Sidebar -->
    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header petugas-desa-header">
            <?php
            require_once __DIR__ . '/petugas_desa_header_brand.php';
            render_petugas_desa_header_brand(
                'Edit Permohonan Akta Kematian',
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
                    <div>
                        <span class="status-badge">Status: <?= esc(ucfirst(strtolower((string)($permohonan['status'] ?? '-')))) ?></span>
                        <p class="helper">Perbaiki data dan ajukan kembali permohonan yang ditolak. Dokumen baru akan disimpan di folder yang sama.</p>
                    </div>
                </div>

                <div class="info-box">
                    <p><span class="info-icon">🔒</span> Anda sebagai <strong>Petugas Desa</strong> hanya dapat mengajukan permohonan untuk wilayah:</p>
                    <p><strong>Kecamatan:</strong> <?= esc($nama_kecamatan_user) ?> | <strong>Desa:</strong> <?= esc($nama_desa_user) ?></p><br>
                </div>

                <!-- Alasan Penolakan -->
                <?php if (!empty($permohonan['alasan'])): ?>
                <div class="alasan-box">
                    <div class="alasan-title">Alasan Penolakan Sebelumnya:</div>
                    <?= nl2br(esc($permohonan['alasan'])) ?>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="editAktaKematianForm">
<?php echo csrf_input(); ?>

                    <!-- Data Pemohon (Dikunci - tidak bisa diedit) -->
                    <div class="form-section">
                        <h3 class="section-title">Data Pemohon</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Pemohon <span class="lock-icon">🔒</span></label>
                                <input type="text" value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>" readonly class="form-control locked-field">
                                <input type="hidden" name="nama_pemohon" value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>NIK Pemohon <span class="lock-icon">🔒</span></label>
                                <input type="text" value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>" readonly class="form-control locked-field">
                                <input type="hidden" name="nik_pemohon" value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>No HP <span class="lock-icon">🔒</span></label>
                                <input type="text" value="<?= esc($permohonan['no_hp'] ?? '') ?>" readonly class="form-control locked-field">
                                <input type="hidden" name="no_hp" value="<?= esc($permohonan['no_hp'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>Email <span class="lock-icon">🔒</span></label>
                                <input type="email" value="<?= esc($permohonan['email'] ?? '') ?>" readonly class="form-control locked-field">
                                <input type="hidden" name="email" value="<?= esc($permohonan['email'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Kecamatan & Desa (Dikunci sesuai user) -->
                    <div class="form-section">
                        <h3 class="section-title">Wilayah Tugas</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Kecamatan <span class="required">*</span> <span class="lock-icon">🔒</span></label>
                                <select name="id_kecamatan" required class="form-control locked-field" disabled>
                                    <option value="<?= $user_kecamatan_id ?>" selected><?= esc($nama_kecamatan_user) ?></option>
                                </select>
                                <input type="hidden" name="id_kecamatan" value="<?= $user_kecamatan_id ?>">
                            </div>

                            <div class="form-group">
                                <label>Desa <span class="required">*</span> <span class="lock-icon">🔒</span></label>
                                <select name="id_desa" required class="form-control locked-field" disabled>
                                    <option value="<?= $user_desa_id ?>" selected><?= esc($nama_desa_user) ?></option>
                                </select>
                                <input type="hidden" name="id_desa" value="<?= $user_desa_id ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Nama Kecamatan</label>
                                <input type="text" readonly class="form-control locked-field" value="<?= esc($nama_kecamatan_user) ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Nama Desa</label>
                                <input type="text" readonly class="form-control locked-field" value="<?= esc($nama_desa_user) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Data Yang Meninggal -->
                    <div class="form-section">
                        <h3 class="section-title">Data Yang Meninggal</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Yang Meninggal <span class="required">*</span></label>
                                <input type="text" name="nama_meninggal" class="form-control" 
                                       value="<?= esc($permohonan['Nama_meninggal'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Yang Meninggal <span class="required">*</span></label>
                                <input type="text" name="nik_meninggal" class="form-control" 
                                       value="<?= esc($permohonan['Nik_meninggal'] ?? '') ?>" required
                                       maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                            </div>
                            
                            <div class="form-group">
                                <label>Alamat/Dusun</label>
                                <input type="text" name="dusun" class="form-control" 
                                       value="<?= esc($permohonan['dusun'] ?? '') ?>" 
                                       placeholder="Nama dusun/lingkungan">
                            </div>
                            
                            <div class="form-group">
                                <label>Tempat Kematian <span class="required">*</span></label>
                                <input type="text" name="tempat" class="form-control" 
                                       value="<?= esc($permohonan['Tempat'] ?? '') ?>" required
                                       placeholder="Kabupaten Gorontalo / Kota Gorontalo">
                            </div>
                            
                            <div class="form-group">
                                <label>Tanggal Kematian <span class="required">*</span></label>
                                <input type="date" name="tanggal_kematian" class="form-control" 
                                       value="<?= esc($permohonan['tanggal_kematian'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Data Orang Tua -->
                    <div class="form-section">
                        <h3 class="section-title">Data Orang Tua <span class="required">*</span></h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Ayah <span class="required">*</span></label>
                                <input type="text" name="nama_ayah" class="form-control" 
                                       value="<?= esc($permohonan['Nama_Ayah'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Ayah <span class="required">*</span></label>
                                <input type="text" name="nik_ayah" class="form-control" 
                                       value="<?= esc($permohonan['Nik_ayah'] ?? '') ?>" required
                                       maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                            </div>
                            
                            <div class="form-group">
                                <label>Nama Ibu <span class="required">*</span></label>
                                <input type="text" name="nama_ibu" class="form-control" 
                                       value="<?= esc($permohonan['Nama_Ibu'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Ibu <span class="required">*</span></label>
                                <input type="text" name="nik_ibu" class="form-control" 
                                       value="<?= esc($permohonan['Nik_ibu'] ?? '') ?>" required
                                       maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                            </div>
                        </div>
                    </div>

                    <!-- Data Saksi 1 -->
                    <div class="form-section">
                        <h3 class="section-title">Data Saksi 1 <span class="required">*</span></h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Saksi 1 <span class="required">*</span></label>
                                <input type="text" name="nama_saksi1" class="form-control" 
                                       value="<?= esc($permohonan['nama_saksi1'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Saksi 1 <span class="required">*</span></label>
                                <input type="text" name="nik_saksi1" class="form-control" 
                                       value="<?= esc($permohonan['nik_saksi1'] ?? '') ?>" required
                                       maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                            </div>
                        </div>
                    </div>

                    <!-- Data Saksi 2 -->
                    <div class="form-section">
                        <h3 class="section-title">Data Saksi 2 <span class="required">*</span></h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Saksi 2 <span class="required">*</span></label>
                                <input type="text" name="nama_saksi2" class="form-control" 
                                       value="<?= esc($permohonan['nama_saksi2'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Saksi 2 <span class="required">*</span></label>
                                <input type="text" name="nik_saksi2" class="form-control" 
                                       value="<?= esc($permohonan['nik_saksi2'] ?? '') ?>" required
                                       maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                            </div>
                        </div>
                    </div>

                    <!-- Keterangan -->
                    <div class="form-section">
                        <h3 class="section-title">Keterangan</h3>
                        <div class="form-group">
                            <label>Keterangan / Pesan untuk Operator</label>
                            <textarea name="keterangan" class="form-control" rows="3" maxlength="500"><?= esc($permohonan['keterangan'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Dokumen yang sudah ada -->
                    <?php if (!empty($dokumen_data)): ?>
                    <div class="form-section">
                        <h3 class="section-title">Dokumen yang Sudah Ada</h3>
                        <div class="existing-docs-container">
                            <div class="existing-docs-title">Centang dokumen yang ingin dipertahankan:</div>
                            <?php foreach ($dokumen_data as $index => $doc): ?>
                            <div class="document-item" data-index="<?= $index ?>">
                                <div class="doc-info">
                                    <?php 
                                    $badge_class = 'badge-' . ($doc['type'] ?? 'lainnya');
                                    $type_labels = [
                                        'formulir_kematian' => 'Formulir F-2.01 Kematian',
                                        'surat_keterangan_kematian' => 'Surat Keterangan Kematian',
                                        'kk_ktp_meninggal' => 'KK/KTP Meninggal',
                                        'ktp_ayah' => 'KTP Ayah',
                                        'ktp_ibu' => 'KTP Ibu',
                                        'ktp_saksi1' => 'KTP Saksi 1',
                                        'ktp_saksi2' => 'KTP Saksi 2',
                                        'lainnya' => 'Dokumen Lain'
                                    ];
                                    $doc_type = $doc['type'] ?? 'lainnya';
                                    $type_label = $type_labels[$doc_type] ?? $doc_type;
                                    ?>
                                    <span class="doc-type-badge <?= $badge_class ?>"><?= $type_label ?></span>
                                    <span class="doc-name"><?= esc($doc['original_name'] ?? 'Dokumen') ?></span>
                                </div>
                                <div class="doc-actions">
                                    <input type="checkbox" name="existing_docs[]" value="<?= $index ?>" checked class="doc-checkbox">
                                    <?php if (isset($doc['path']) && file_exists(getAbsoluteFilePath($doc['path']))): ?>
                                    <button type="button" class="btn-preview" onclick="previewDocument('<?= htmlspecialchars(siprima_document_token_url(__DIR__, (string)$doc['path']) ?? '', ENT_QUOTES, 'UTF-8') ?>')">
                                        <i class="fas fa-eye"></i> Lihat
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-remove" onclick="removeExistingDocument(this, <?= $index ?>)">
                                        Hapus
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- File Upload Multiple (untuk dokumen baru) -->
                    <div class="form-section">
                        <h3 class="section-title">Unggah Dokumen Baru (Jika Perlu)</h3>
                        
                        <div class="f-doc-group">
                            <p class="f-doc-group__judul"><strong>Petunjuk Dokumen Wajib:</strong></p>
                            <ul style="margin: 0; padding-left: 20px; color: #4b5563;">
                                <li><strong>Formulir F-2.01 Kematian</strong> (diisi lengkap dan ditandatangani)</li>
                                <li><strong>Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan</strong></li>
                                <li><strong>KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal</strong></li>
                            </ul>
                            <p style="margin: 10px 0 0 0; color: #dc2626;"><strong>Jika dokumen sudah ada di atas, tidak perlu diupload ulang.</strong></p>
                        </div>
                        
                        <div id="fileUploadContainer">
                            <!-- File inputs akan ditambahkan secara dinamis -->
                            <div class="dokumen-tambahan-item" id="fileGroup1">
                                <input type="hidden" name="file_index_new[]" value="0">
                                <select name="doc_type_new[]" class="dokumen-type-select">
                                    <option value="">Pilih Jenis Dokumen</option>
                                    <option value="formulir_kematian">Formulir F-2.01 Kematian</option>
                                    <option value="surat_keterangan_kematian">Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan</option>
                                    <option value="kk_ktp_meninggal">KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal</option>
                                    <option value="ktp_ayah">KTP Ayah</option>
                                    <option value="ktp_ibu">KTP Ibu</option>
                                    <option value="ktp_saksi1">KTP Saksi 1</option>
                                    <option value="ktp_saksi2">KTP Saksi 2</option>
                                    <option value="lainnya">Dokumen Lainnya</option>
                                </select>
                                <div class="file-upload">
                                    <label class="file-upload-label">
                                        <i class="fas fa-upload"></i> Pilih File
                                        <input type="file" id="fileInput1" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
                                    </label>
                                    <div class="file-preview" id="fileName1">Belum ada file dipilih</div>
                                    <div id="fileSize1" style="font-size: 12px; color: #ef4444; margin-top: 5px;">Maksimal 5 MB per file, dikompres otomatis</div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn-add" onclick="addFileInput()">
                            <i class="fas fa-plus"></i> Tambah Dokumen Lain
                        </button>
                        
                        <p style="margin-top: 10px; color: #6b7280; font-size: 14px;">
                            <strong>Dokumen wajib:</strong> Formulir F-2.01 Kematian, Surat Keterangan Kematian, dan KK/KTP yang meninggal. 
                            <br><strong>Format:</strong> JPG, JPEG, JFIF, JPE, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong>
                        </p>
                    </div>

                    <div class="form-actions">
                        <a href="petugas_desa_status_permohonan.php" class="btn-cancel">Batal</a>
                        <button type="submit" class="btn-submit">Perbarui dan Ajukan Kembali</button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</div>

<!-- Modal untuk preview dokumen -->
<div id="previewModal" class="modal-overlay" style="display: none;">
    <div class="modal-box preview-modal">
        <div class="preview-header">
            <h3>Preview Dokumen</h3>
            <button onclick="closePreview()">&times;</button>
        </div>
        <div id="previewContent" class="preview-content"></div>
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
// Dokumen Input Functions
let dokumenCounter = 1;
const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB
const SERVER_POST_MAX_BYTES = <?= (int)$serverPostMaxBytes ?>;
const SERVER_UPLOAD_MAX_BYTES = <?= (int)$serverUploadMaxBytes ?>;
const SERVER_POST_BUFFER = 64 * 1024; // antisipasi overhead multipart form-data
const EFFECTIVE_MAX_FILE_SIZE = SERVER_UPLOAD_MAX_BYTES > 0
    ? Math.min(MAX_FILE_SIZE, SERVER_UPLOAD_MAX_BYTES)
    : MAX_FILE_SIZE;
  function prepareFileForReliableUpload(input) {
    return; // Upload HTML standar; kompatibel tanpa File/DataTransfer API.
    if (!input || !input.files || input.files.length === 0) {
      if (input) input.dataset.uploadReadable = '1';
      return;
    }

    const original = input.files[0];
    input.dataset.uploadReadable = 'pending';

    try {
        const buffer = null;
      if (typeof DataTransfer === 'undefined') {
        input.dataset.uploadReadable = '1';
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
      input.dataset.uploadReadable = '1';
    } catch (err) {
      input.dataset.uploadReadable = '0';
      console.warn('File tidak bisa dibaca stabil oleh browser:', err);
    }
  }

function formatFileSizeClient(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' Bytes';
}

function attachFileInputPreview(fileInput) {
    if (!fileInput || fileInput.dataset.previewBound === '1') return;

    const suffix = fileInput.id.replace('fileInput', '');
    const fileNameSpan = document.getElementById('fileName' + suffix);
    const fileSizeSpan = document.getElementById('fileSize' + suffix);

    fileInput.addEventListener('change', function() {
        if (!fileNameSpan || !fileSizeSpan) return;

        if (this.files.length > 0) {
            const file = this.files[0];
            const fileSizeMB = (file.size / 1024 / 1024);
            fileNameSpan.textContent = file.name + ' (' + fileSizeMB.toFixed(2) + ' MB)';

            // Validasi ukuran file
            if (file.size > 1048576) { // 1MB
                fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${fileSizeMB.toFixed(2)} MB (gagal dikompres, pilih gambar lain)</span>`;
                this.value = '';
                fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
            } else {
                fileSizeSpan.innerHTML = `Ukuran: ${fileSizeMB.toFixed(2)} MB`;
            }
        } else {
            fileNameSpan.textContent = 'Belum ada file dipilih';
            fileSizeSpan.innerHTML = 'Maksimal 5 MB per file, dikompres otomatis';
        }
    });

    fileInput.dataset.previewBound = '1';
}

function addFileInput() {
    const container = document.getElementById('fileUploadContainer');
    dokumenCounter++;
    
    const newGroup = document.createElement('div');
    newGroup.className = 'dokumen-tambahan-item';
    newGroup.id = 'fileGroup' + dokumenCounter;
    newGroup.innerHTML = `
        <div class="dokumen-tambahan-header">
            <h4>Dokumen Tambahan #${dokumenCounter}</h4>
            <button type="button" class="btn-remove-dokumen" onclick="removeFileInput(this)">
                <i class="fas fa-times"></i> Hapus
            </button>
        </div>
        <input type="hidden" name="file_index_new[]" value="${dokumenCounter - 1}">
        <select name="doc_type_new[]" class="dokumen-type-select">
            <option value="">Pilih Jenis Dokumen</option>
            <option value="formulir_kematian">Formulir F-2.01 Kematian</option>
            <option value="surat_keterangan_kematian">Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan</option>
            <option value="kk_ktp_meninggal">KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal</option>
            <option value="ktp_ayah">KTP Ayah</option>
            <option value="ktp_ibu">KTP Ibu</option>
            <option value="ktp_saksi1">KTP Saksi 1</option>
            <option value="ktp_saksi2">KTP Saksi 2</option>
            <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div class="file-upload">
            <label class="file-upload-label">
                <i class="fas fa-upload"></i> Pilih File
                <input type="file" id="fileInput${dokumenCounter}" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
            </label>
            <div class="file-preview" id="fileName${dokumenCounter}">Belum ada file dipilih</div>
            <div id="fileSize${dokumenCounter}" style="font-size: 12px; color: #ef4444; margin-top: 5px;">Maksimal 5 MB per file, dikompres otomatis</div>
        </div>
    `;
    
    container.appendChild(newGroup);

    const fileInput = document.getElementById('fileInput' + dokumenCounter);
    attachFileInputPreview(fileInput);
}

function removeFileInput(button) {
    const group = button.closest('.dokumen-tambahan-item');
    if (group && group.id !== 'fileGroup1') {
        group.remove();
    }
}

function removeExistingDocument(button, index) {
    if (confirm('Apakah Anda yakin ingin menghapus dokumen ini?')) {
        const docItem = button.closest('.document-item');
        const checkbox = docItem.querySelector('.doc-checkbox');
        checkbox.checked = false;
        docItem.style.display = 'none';
    }
}

attachFileInputPreview(document.getElementById('fileInput1'));

// Preview Document Function
function previewDocument(filePath) {
    const modal = document.getElementById('previewModal');
    const previewContent = document.getElementById('previewContent');
    
    // Cek tipe file untuk menentukan cara preview
    const extension = filePath.split('.').pop().toLowerCase();
    
    if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
        // Tampilkan gambar
        previewContent.innerHTML = `
        <img src="${filePath}" 
            alt="Preview Dokumen" 
            onerror="this.onerror=null; 
                        this.src='data:image/svg+xml;charset=UTF-8,
                        <svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;100%&quot; height=&quot;100%&quot;>
                        <rect width=&quot;100%&quot; height=&quot;100%&quot; fill=&quot;#f3f4f6&quot;/>
                        <text x=&quot;50%&quot; y=&quot;50%&quot; font-family=&quot;Arial&quot; font-size=&quot;16&quot; fill=&quot;#9ca3af&quot; text-anchor=&quot;middle&quot; dy=&quot;.3em&quot;>
                            Gambar tidak dapat ditampilkan
                        </text>
                        </svg>'">
        `;
    } else {
        // Untuk file non-gambar
        previewContent.innerHTML = `<div style="padding: 20px; text-align: center; color: #6b7280;">
            <i class="fas fa-file" style="font-size: 48px; margin-bottom: 15px;"></i>
            <p>File tidak dapat dipreview secara langsung.</p>
            <p>Silakan unduh untuk melihat kontennya.</p>
        </div>`;
    }
    
    modal.style.display = 'flex';
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('previewModal');
    if (event.target === modal) {
        closePreview();
    }
});

// Form Validation
document.getElementById('editAktaKematianForm').addEventListener('submit', function(e) {
    let isValid = true;
    let errorMessages = [];

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    let csrfInput = this.querySelector('input[name="csrf_token"]');
    if (!csrfInput) {
        csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        this.appendChild(csrfInput);
    }
    if ((!csrfInput.value || !csrfInput.value.trim()) && csrfToken) {
        csrfInput.value = csrfToken;
    }
    if (!csrfInput.value || !csrfInput.value.trim()) {
        errorMessages.push('Token keamanan tidak ditemukan. Muat ulang halaman lalu coba lagi.');
        isValid = false;
    }
    
    // Validate NIK harus 16 digit
    const nikInputs = [
        { name: 'nik_meninggal', label: 'NIK yang meninggal' },
        { name: 'nik_ayah', label: 'NIK ayah' },
        { name: 'nik_ibu', label: 'NIK ibu' },
        { name: 'nik_saksi1', label: 'NIK saksi 1' },
        { name: 'nik_saksi2', label: 'NIK saksi 2' }
    ];
    
    nikInputs.forEach(inputInfo => {
        const input = document.querySelector(`input[name="${inputInfo.name}"]`);
        if (input && (!input.value || input.value.length !== 16)) {
            errorMessages.push(`${inputInfo.label} harus 16 digit angka`);
            input.style.borderColor = '#ef4444';
            isValid = false;
        } else if (input) {
            input.style.borderColor = '#d1d5db';
        }
    });
    
    // Validate required fields
    const requiredFields = this.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.style.borderColor = '#ef4444';
            isValid = false;
        } else {
            field.style.borderColor = '#d1d5db';
        }
    });
    
    // Validate dokumen wajib
    const existingDocsChecked = document.querySelectorAll('input[name="existing_docs[]"]:checked');
    const docTypeSelects = document.querySelectorAll('select[name="doc_type_new[]"]');
    const fileInputs = document.querySelectorAll('input[type="file"]');
    let totalUploadSize = 0;

    let hasFormulir = false;
    let hasSuratKematian = false;
    let hasKKKTP = false;
    
    // Cek dokumen lama yang dipilih
    existingDocsChecked.forEach(checkbox => {
        const docItem = checkbox.closest('.document-item');
        const badge = docItem.querySelector('.doc-type-badge');
        if (badge) {
            if (badge.classList.contains('badge-formulir_kematian')) hasFormulir = true;
            if (badge.classList.contains('badge-surat_keterangan_kematian')) hasSuratKematian = true;
            if (badge.classList.contains('badge-kk_ktp_meninggal')) hasKKKTP = true;
        }
    });
    
    // Cek dokumen baru yang diupload
    docTypeSelects.forEach((select, index) => {
        const type = select.value;
        const fileInput = fileInputs[index];
        
        if (fileInput && fileInput.files.length > 0) {
            const file = fileInput.files[0];
            totalUploadSize += file.size;
        if (file.size > EFFECTIVE_MAX_FILE_SIZE) {
            errorMessages.push(
                `File ${file.name} melebihi batas ukuran ${formatFileSizeClient(EFFECTIVE_MAX_FILE_SIZE)}`
            );
            isValid = false;
        }
        if (fileInput.dataset.uploadReadable === 'pending') {
            errorMessages.push(`File ${file.name} masih diproses browser. Tunggu sebentar lalu ajukan kembali.`);
            isValid = false;
        } else if (fileInput.dataset.uploadReadable === '0') {
            errorMessages.push(`File ${file.name} tidak dapat diakses oleh Chrome. Simpan ulang file ke Galeri/Downloads, lalu pilih ulang.`);
            isValid = false;
        }
        if (type === 'formulir_kematian') hasFormulir = true;
        if (type === 'surat_keterangan_kematian') hasSuratKematian = true;
        if (type === 'kk_ktp_meninggal') hasKKKTP = true;
        }
    });

    if (SERVER_POST_MAX_BYTES > 0 && totalUploadSize > Math.max(0, SERVER_POST_MAX_BYTES - SERVER_POST_BUFFER)) {
        errorMessages.push(
            `Total ukuran upload ${formatFileSizeClient(totalUploadSize)} melebihi batas server ` +
            `${formatFileSizeClient(SERVER_POST_MAX_BYTES)}. Kecilkan ukuran file agar tidak gagal CSRF.`
        );
        isValid = false;
    }
    
    if (!hasFormulir) {
        errorMessages.push('Formulir F-2.01 Kematian wajib diupload atau dipilih dari dokumen yang ada');
        isValid = false;
    }
    
    if (!hasSuratKematian) {
        errorMessages.push('Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan wajib diupload atau dipilih dari dokumen yang ada');
        isValid = false;
    }
    
    if (!hasKKKTP) {
        errorMessages.push('KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal wajib diupload atau dipilih dari dokumen yang ada');
        isValid = false;
    }
    
    // Validate tanggal kematian tidak boleh di masa depan
    const tanggalKematianInput = document.querySelector('input[name="tanggal_kematian"]');
    if (tanggalKematianInput && tanggalKematianInput.value) {
        const today = new Date();
        const deathDate = new Date(tanggalKematianInput.value);
        if (deathDate > today) {
            errorMessages.push('Tanggal kematian tidak boleh di masa depan');
            isValid = false;
            tanggalKematianInput.style.borderColor = '#ef4444';
        } else {
            tanggalKematianInput.style.borderColor = '#d1d5db';
        }
    }
    
    if (!isValid) {
        e.preventDefault();
        let message = 'Harap perbaiki kesalahan berikut:\n\n';
        if (errorMessages.length > 0) {
            message += '• ' + errorMessages.join('\n• ');
        }
        alert(message);
    } else {
        const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan kembali permohonan akta kematian?\n\nPastikan dokumen wajib sudah lengkap.');
        if (!confirmSubmit) {
            e.preventDefault();
        }
    }
});

// Logout Modal Functions
document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    if (!target.files || target.files.length === 0) {
        target.dataset.uploadReadable = '1';
        return;
    }
    if (target.files[0].size > EFFECTIVE_MAX_FILE_SIZE) return;
    prepareFileForReliableUpload(target);
});
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.file-input').forEach(attachFileInputPreview);

    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');
    
    function showLogoutModal() {
        logoutModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    
    function hideLogoutModal() {
        logoutModal.style.display = 'none';
        document.body.style.overflow = '';
    }
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout.php');
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) hideLogoutModal();
        });
    }
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.style.display === 'flex') {
            hideLogoutModal();
        }
    });
    
    // Toggle Sidebar Menu on Mobile
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
});
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>
