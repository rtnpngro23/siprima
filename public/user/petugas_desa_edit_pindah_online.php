<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/petugas_desa_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_pindah_online LIKE 'resubmitted_at'");
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

// Ambil data permohonan berdasarkan ID - TAMBAHKAN nama_folder
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
    nama_kepalaKeluarga, 
    alamat_asal,
    alamat_tujuan,
    alasan_pindah,
    anggotaKeluarga, 
    keterangan,
    dokumen, 
    status,
    alasan,
    nama_folder
    FROM tb_pindah_online 
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

// Debug: Tampilkan data dokumen
error_log("Dokumen data count: " . count($dokumen_data));
foreach ($dokumen_data as $index => $doc) {
    error_log("Dokumen {$index}: " . print_r($doc, true));
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
    if (isset($_POST['id_kecamatan_tujuan']) && (int)$_POST['id_kecamatan_tujuan'] != $user_kecamatan_id) {
        $errors[] = "Anda tidak memiliki akses untuk mengubah kecamatan.";
    }
    if (isset($_POST['id_desa_tujuan']) && (int)$_POST['id_desa_tujuan'] != $user_desa_id) {
        $errors[] = "Anda tidak memiliki akses untuk mengubah desa.";
    }
    
    // Data keluarga (bisa diubah)
    $no_kk = trim($_POST['no_kk'] ?? '');
    $nama_kepalaKeluarga = trim($_POST['nama_kepalaKeluarga'] ?? '');
    $alamat_asal = trim($_POST['alamat_asal'] ?? '');
    $alamat_tujuan = trim($_POST['alamat_tujuan'] ?? '');
    $alasan_pindah = trim($_POST['alasan_pindah'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi data keluarga
    if (empty($no_kk)) $errors[] = "Nomor KK harus diisi";
    if (strlen($no_kk) !== 16) $errors[] = "Nomor KK harus 16 digit";
    if (empty($nama_kepalaKeluarga)) $errors[] = "Nama kepala keluarga harus diisi";
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
    
    // ================================================
    // BAGIAN YANG DIPERBAIKI: Gunakan folder yang sama
    // ================================================
    
    // Ambil nama folder dari database (jika sudah ada)
    $folder_name = $permohonan['nama_folder'] ?? '';
    
    // Jika tidak ada nama folder di database, buat yang baru berdasarkan data
    if (empty($folder_name)) {
        // Fungsi sanitasi nama untuk folder
        function cleanFolderName($string) {
            $string = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $string);
            $string = preg_replace('/\s+/', '_', trim($string));
            return $string;
        }
        
        // Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama kepala keluarga]_[timestamp]
        $timestamp = date('Ymd_His');
        
        $kecamatan_clean = cleanFolderName($nama_kecamatan_db);
        $desa_clean = cleanFolderName($nama_desa_db);
        $pemohon_clean = cleanFolderName($nama_pemohon);
        $kepala_keluarga_clean = cleanFolderName($nama_kepalaKeluarga);
        
        $folder_name = $kecamatan_clean . '_' . 
                       $desa_clean . '_' . 
                       $nik_pemohon . '_' . 
                       $pemohon_clean . '_' . 
                       $kepala_keluarga_clean . '_' . 
                       $timestamp;
        
        if (strlen($folder_name) > 200) {
            $folder_name = 'PINDAH_ONLINE_' . $nik_pemohon . '_' . $timestamp;
        }
    }
    
    // Path folder uploads untuk pindah online
    $base_upload_dir = __DIR__ . '/uploads/pindah_online/';
    $user_folder_path = $base_upload_dir . $folder_name . '/';
    
    // Buat folder jika belum ada (hanya jika folder baru)
    if (!file_exists($user_folder_path) && empty($permohonan['nama_folder'])) {
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
    } elseif (file_exists($user_folder_path)) {
        // Pastikan .htaccess ada jika folder sudah ada
        $htaccessFile = $user_folder_path . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, 
                "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
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
                error_log("Menambahkan dokumen lama: {$doc['type']}");
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
                        'path' => 'uploads/pindah_online/' . $folder_name . '/' . $newName,
                        'type' => $docType,
                        'original_name' => $fileName,
                        'uploaded_name' => $newName,
                        'size' => $fileSize,
                        'uploaded_at' => date('Y-m-d H:i:s')
                    ];
                    $uploadedDocTypes[] = $docType;
                    error_log("Menambahkan dokumen baru: {$docType}");
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
    
    // Debug: Tampilkan dokumen yang akan disimpan
    error_log("Jumlah dokumen baru: " . count($dokumen_baru));
    error_log("Tipe dokumen yang ada: " . implode(', ', array_column($dokumen_baru, 'type')));
    
    // 3. Validasi dokumen wajib
    $requiredDocs = ['formulir_pindah', 'foto_pemohon', 'kk_ktp'];
    $docTypesInNewData = array_column($dokumen_baru, 'type');
    
    $missingRequired = array_diff($requiredDocs, $docTypesInNewData);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            switch ($doc) {
                case 'formulir_pindah':
                    $missingNames[] = 'Formulir F-1.03 Pindah';
                    break;
                case 'foto_pemohon':
                    $missingNames[] = 'Foto pemohon memegang KK/KTP/Form pindah';
                    break;
                case 'kk_ktp':
                    $missingNames[] = 'KK/KTP/Surat keterangan hilang KK atau KTP';
                    break;
            }
        }
        $errors[] = 'Dokumen wajib belum diupload atau dipilih: ' . implode(', ', $missingNames);
    }
    
    // Jika tidak ada error, update data
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        // Update data permohonan - TAMBAHKAN nama_folder
        $stmt = $koneksi->prepare("UPDATE tb_pindah_online SET 
            nama_pemohon = ?,
            nik_pemohon = ?,
            no_hp = ?,
            email = ?,
            id_kecamatan = ?,
            id_desa = ?,
            nama_kecamatan = ?,
            nama_desa = ?,
            no_kk = ?,
            nama_kepalaKeluarga = ?,
            alamat_asal = ?,
            alamat_tujuan = ?,
            alasan_pindah = ?,
            anggotaKeluarga = ?,
            keterangan = ?,
            dokumen = ?,
            nama_folder = ?,
            status = 'diajukan',
            alasan = NULL,
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
            $nama_kecamatan_db,
            $nama_desa_db,
            $no_kk,
            $nama_kepalaKeluarga,
            $alamat_asal,
            $alamat_tujuan,
            $alasan_pindah,
            $anggota_keluarga_json,
            $keterangan,
            $dokumen_json,
            $folder_name,
            $permohonan_id,
            $user_id
        );
        
        if ($stmt->execute()) {
            logPermohonanStatusHistory(
                $koneksi,
                'tb_pindah_online',
                $permohonan_id,
                'diajukan',
                null,
                (int)($_SESSION['id_user'] ?? 0),
                (string)($_SESSION['role'] ?? 'user'),
                'resubmit'
            );
            $success = true;
            $_SESSION['success_message'] = "Permohonan pindah online berhasil diperbarui dan diajukan kembali!";
            header('Location: petugas_desa_status_permohonan.php');
            exit;
        } else {
            $errors[] = "Gagal memperbarui data: " . $stmt->error;
            
            // Jika gagal update dan folder baru dibuat (bukan folder lama), hapus folder
            if (empty($permohonan['nama_folder']) && is_dir($user_folder_path)) {
                array_map('unlink', glob($user_folder_path . '/*'));
                $htaccessFile = $user_folder_path . '/.htaccess';
                if (file_exists($htaccessFile)) {
                    unlink($htaccessFile);
                }
                rmdir($user_folder_path);
            }
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Permohonan Pindah Masuk - Petugas Desa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/petugas_desa_edit_pindah_online.css">
    <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="petugas-desa-ui tema-form tema-add">

<div class="layout">
    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header petugas-desa-header">
            <?php
            require_once __DIR__ . '/petugas_desa_header_brand.php';
            render_petugas_desa_header_brand(
                'Edit Permohonan Pindah Masuk',
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
            <div class="form-card">
                <div class="form-header">
                    <div class="icon-container">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                        </svg>
                    </div>
                    <div>
                        <h2 class="form-title">Edit Permohonan Pindah Masuk</h2>
                        <p class="helper">Perbaiki data dan ajukan kembali permohonan pindah masuk yang ditolak. Dokumen baru akan disimpan di folder yang sama.</p>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="toast toast-error">
                        <?= esc($errors[0]) ?>
                    </div>

                    <div class="error-box">
                        <?php foreach ($errors as $err) echo "<p>" . esc($err) . "</p>"; ?>
                    </div>
                <?php endif; ?>

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

                <form method="POST" enctype="multipart/form-data" class="space-y-4" id="editPindahOnlineForm">
<?php echo csrf_input(); ?>

                    <!-- Data Pemohon (Dikunci - tidak bisa diedit) -->
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Pemohon <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
                            <input type="text" value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>" readonly class="input bg-gray-100 locked-field">
                            <input type="hidden" name="nama_pemohon" value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="field-label">NIK Pemohon <span style="color:red">*</span> <span class="lock-icon"></span></label>
                            <input type="text" value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>" readonly class="input bg-gray-100 locked-field">
                            <input type="hidden" name="nik_pemohon" value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="field-label">No HP <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
                            <input type="text" value="<?= esc($permohonan['no_hp'] ?? '') ?>" readonly class="input bg-gray-100 locked-field">
                            <input type="hidden" name="no_hp" value="<?= esc($permohonan['no_hp'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="field-label">Email <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
                            <input type="email" value="<?= esc($permohonan['email'] ?? '') ?>" readonly class="input bg-gray-100 locked-field">
                            <input type="hidden" name="email" value="<?= esc($permohonan['email'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Kecamatan & Desa Tujuan (Dikunci sesuai user) -->
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Kecamatan Tujuan <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
                            <select name="id_kecamatan_tujuan" id="kecamatanSelectTujuan" required class="select locked-select" disabled>
                                <option value="<?= $user_kecamatan_id ?>" selected><?= esc($nama_kecamatan_user) ?></option>
                            </select>
                            <input type="hidden" name="id_kecamatan_tujuan" value="<?= $user_kecamatan_id ?>">
                        </div>

                        <div>
                            <label class="field-label">Desa Tujuan <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
                            <select name="id_desa_tujuan" id="desaSelectTujuan" required class="select locked-select" disabled>
                                <option value="<?= $user_desa_id ?>" selected><?= esc($nama_desa_user) ?></option>
                            </select>
                            <input type="hidden" name="id_desa_tujuan" value="<?= $user_desa_id ?>">
                        </div>
                    </div>

                    <!-- Nama Kecamatan & Desa Tujuan (otomatis terisi) -->
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Kecamatan Tujuan</label>
                            <input type="text" id="namaKecamatanTujuan" readonly class="input bg-gray-100" value="<?= esc($nama_kecamatan_user) ?>">
                        </div>

                        <div>
                            <label class="field-label">Nama Desa Tujuan</label>
                            <input type="text" id="namaDesaTujuan" readonly class="input bg-gray-100" value="<?= esc($nama_desa_user) ?>">
                        </div>
                    </div>

                    <!-- Data Kartu Keluarga -->
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nomor Kartu Keluarga (No KK) <span style="color:red">*</span></label>
                            <input type="text" name="no_kk" class="input" 
                                   value="<?= esc($permohonan['no_kk'] ?? '') ?>" 
                                   pattern="[0-9]{16}" maxlength="16"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                                   required placeholder="16 digit nomor KK">
                        </div>

                        <div>
                            <label class="field-label">Nama Kepala Keluarga <span style="color:red">*</span></label>
                            <input type="text" name="nama_kepalaKeluarga" class="input" 
                                   value="<?= esc($permohonan['nama_kepalaKeluarga'] ?? '') ?>" 
                                   required placeholder="Nama lengkap kepala keluarga">
                        </div>
                    </div>

                    <!-- Alamat Asal -->
                    <div>
                        <label class="field-label">Alamat Asal Lengkap <span style="color:red">*</span></label>
                        <textarea name="alamat_asal" class="input" rows="3" required
                                  placeholder="Alamat lengkap saat ini di luar Kabupaten Gorontalo (nama jalan, RT/RW, dusun, kelurahan, kecamatan, kabupaten/kota, provinsi)"><?= esc($permohonan['alamat_asal'] ?? '') ?></textarea>
                        <p class="helper">Contoh: Jl. Sudirman No. 45, RT 02/RW 03, Kelurahan Menteng, Kecamatan Menteng, Kota Jakarta Pusat, Provinsi DKI Jakarta</p>
                    </div>

                    <!-- Alamat Tujuan -->
                    <div>
                        <label class="field-label">Alamat Tujuan Lengkap (di Kabupaten Gorontalo) <span style="color:red">*</span></label>
                        <textarea name="alamat_tujuan" class="input" rows="3" required
                                  placeholder="Alamat lengkap tujuan baru di Kabupaten Gorontalo (nama jalan, RT/RW, dusun)"><?= esc($permohonan['alamat_tujuan'] ?? '') ?></textarea>
                        <p class="helper">Contoh: Jl. Merdeka No. 123, RT 01/RW 02, Dusun Suka Makmur, Desa <?= esc($nama_desa_user) ?></p>
                    </div>

                    <!-- Alasan Pindah -->
                    <div>
                        <label class="field-label">Alasan Pindah <span style="color:red">*</span></label>
                        <select name="alasan_pindah" class="select" required>
                            <option value="">-- Pilih Alasan Pindah --</option>
                            <option value="kerja" <?= selected('kerja', $permohonan['alasan_pindah'] ?? '') ?>>Pekerjaan</option>
                            <option value="pendidikan" <?= selected('pendidikan', $permohonan['alasan_pindah'] ?? '') ?>>Pendidikan</option>
                            <option value="keluarga" <?= selected('keluarga', $permohonan['alasan_pindah'] ?? '') ?>>Keluarga</option>
                            <option value="rumah" <?= selected('rumah', $permohonan['alasan_pindah'] ?? '') ?>>Pindah Rumah</option>
                            <option value="kembali" <?= selected('kembali', $permohonan['alasan_pindah'] ?? '') ?>>Kembali ke Kampung Halaman</option>
                            <option value="lainnya" <?= selected('lainnya', $permohonan['alasan_pindah'] ?? '') ?>>Lainnya</option>
                        </select>
                    </div>

                    <!-- Anggota Keluarga (Input Dinamis) -->
                    <div>
                        <label class="field-label">Anggota Keluarga yang Pindah <span style="color:red">*</span></label>
                        
                        <div style="margin-top: 10px; margin-bottom: 10px;">
                            <table id="anggotaTable" class="anggota-table">
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
                                                       placeholder="NIK"
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
                                                <button type="button" class="btn-remove-anggota" onclick="removeAnggota(this)">×</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td>
                                            <input type="text" name="anggota_nama[]" placeholder="Nama lengkap" required>
                                        </td>
                                        <td>
                                            <input type="text" name="anggota_nik[]" placeholder="NIK"
                                                   pattern="[0-9]{16}" maxlength="16"
                                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                                        </td>
                                        <td>
                                            <input type="text" name="anggota_hubungan[]" placeholder="Contoh: Anak, Istri">
                                        </td>
                                        <td></td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <button type="button" class="btn-add-anggota" onclick="addAnggota()">
                                <i class="fas fa-plus"></i> Tambah Anggota Keluarga
                            </button>
                            <p class="helper">Isi data anggota keluarga yang ikut pindah, termasuk kepala keluarga.</p>
                        </div>
                    </div>

                    <div>
                        <label class="field-label">Keterangan / Pesan untuk Operator</label>
                        <textarea name="keterangan" class="input" rows="3" placeholder="Keterangan tambahan untuk operator (jika ada)" maxlength="500"><?= esc($permohonan['keterangan'] ?? '') ?></textarea>
                    </div>

                    <!-- Dokumen yang sudah ada -->
                    <?php if (!empty($dokumen_data)): ?>
                    <div class="existing-docs-container">
                        <div class="existing-docs-title">Dokumen yang Sudah Ada (Centang untuk mempertahankan):</div>
                        <?php foreach ($dokumen_data as $index => $doc): ?>
                        <div class="existing-doc-item" data-index="<?= $index ?>">
                            <div class="doc-info">
                                <?php 
                                $badge_class = 'badge-' . ($doc['type'] ?? 'lainnya');
                                $type_labels = [
                                    'formulir_pindah' => 'Formulir F-1.03 Pindah',
                                    'foto_pemohon' => 'Foto Pemohon',
                                    'kk_ktp' => 'KK/KTP',
                                    'buku_nikah' => 'Buku Nikah',
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
                                <?php if (isset($doc['path']) && file_exists(__DIR__ . '/' . $doc['path'])): ?>
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
                    <?php endif; ?>

                    <!-- File Upload Multiple (untuk dokumen baru) -->
                    <div>
                        <label class="field-label">Unggah Dokumen Baru (Jika Perlu)</label>
                        <div class="f-doc-group">
                            <p class="f-doc-group__judul"><strong>Petunjuk Dokumen Wajib:</strong></p>
                            <ul style="margin: 0; padding-left: 20px; color: #4b5563;">
                                <li><strong>Formulir F-1.03 Pindah</strong> (diisi lengkap dan ditandatangani)</li>
                                <li><strong>Foto pemohon memegang KK/KTP/Form pindah</strong></li>
                                <li><strong>KK/KTP/Surat keterangan hilang KK atau KTP</strong></li>
                            </ul>
                            <p style="margin: 10px 0 0 0; color: #dc2626;"><strong>Jika dokumen sudah ada di atas, tidak perlu diupload ulang.</strong></p>
                        </div>
                        
                        <div id="fileUploadContainer">
                            <!-- File inputs akan ditambahkan secara dinamis -->
                            <div class="file-input-group" id="fileGroup1">
                                <input type="hidden" name="file_index_new[]" value="0">
                                <select name="doc_type_new[]" class="file-type-select">
                                    <option value="">Pilih Jenis Dokumen</option>
                                    <option value="formulir_pindah">Formulir F-1.03 Pindah</option>
                                    <option value="foto_pemohon">Foto pemohon memegang KK/KTP/Form pindah</option>
                                    <option value="kk_ktp">KK/KTP/Surat keterangan hilang KK atau KTP</option>
                                    <option value="buku_nikah">Buku nikah/akta nikah</option>
                                    <option value="lainnya">Dokumen Lainnya</option>
                                </select>
                                <div class="file-wrapper">
                                    <label class="file-label" for="fileInput1">Pilih File</label>
                                    <span id="fileName1" class="file-name">Belum ada file dipilih</span>
                                    <input type="file" id="fileInput1" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
                                </div>
                                <div class="file-size-warning" id="fileSize1">Maksimal 5 MB per file, dikompres otomatis</div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn-secondary mt-2" onclick="addFileInput()">
                            + Tambah Dokumen Lain
                        </button>
                        
                        <p class="helper"><strong>Dokumen wajib:</strong> Formulir F-1.03 Pindah, Foto pemohon, dan KK/KTP. Format: JPG, JPEG, JFIF, JPE, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong></p>
                    </div>

                    <div class="flex justify-between items-center mt-6 pt-4 border-t">
                        <a href="petugas_desa_status_permohonan.php" class="btn-secondary">Batal</a>
                        <button type="submit" class="btn-primary">Perbarui dan Ajukan Kembali</button>
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
// Anggota Keluarga Functions
function addAnggota() {
    const tableBody = document.getElementById('anggotaBody');
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td>
            <input type="text" name="anggota_nama[]" placeholder="Nama lengkap" required>
        </td>
        <td>
            <input type="text" name="anggota_nik[]" placeholder="NIK"
                   pattern="[0-9]{16}" maxlength="16"
                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
        </td>
        <td>
            <input type="text" name="anggota_hubungan[]" placeholder="Contoh: Anak, Istri">
        </td>
        <td>
            <button type="button" class="btn-remove-anggota" onclick="removeAnggota(this)">×</button>
        </td>
    `;
    tableBody.appendChild(newRow);
}

function removeAnggota(button) {
    const row = button.closest('tr');
    if (document.querySelectorAll('#anggotaBody tr').length > 1) {
        row.remove();
    } else {
        alert('Minimal harus ada satu anggota keluarga');
    }
}

// Dokumen Input Functions
let dokumenCounter = 1;

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

function updateFileInputDisplay(input) {
    const group = input.closest('.file-input-group');
    if (!group) return;

    const fileNameSpan = group.querySelector('.file-name');
    const fileSizeSpan = group.querySelector('.file-size-warning');
    if (!fileNameSpan || !fileSizeSpan) return;

    if (input.files.length > 0) {
        const file = input.files[0];
        const fileSizeMB = (file.size / 1024 / 1024);
        fileNameSpan.textContent = file.name + ' (' + fileSizeMB.toFixed(2) + ' MB)';

        if (file.size > 1048576) {
            fileSizeSpan.innerHTML = `<span style="color: red;">Ukuran file: ${fileSizeMB.toFixed(2)} MB (gagal dikompres, pilih gambar lain)</span>`;
            input.value = '';
            fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
        } else {
            fileSizeSpan.innerHTML = `Ukuran: ${fileSizeMB.toFixed(2)} MB`;
        }
    } else {
        fileNameSpan.textContent = 'Belum ada file dipilih';
        fileSizeSpan.innerHTML = 'Maksimal 5 MB per file, dikompres otomatis';
    }
}

function addFileInput() {
    const container = document.getElementById('fileUploadContainer');
    dokumenCounter++;
    
    const newGroup = document.createElement('div');
    newGroup.className = 'file-input-group';
    newGroup.id = 'fileGroup' + dokumenCounter;
    newGroup.innerHTML = `
        <input type="hidden" name="file_index_new[]" value="${dokumenCounter - 1}">
        <select name="doc_type_new[]" class="file-type-select">
            <option value="">Pilih Jenis Dokumen</option>
            <option value="formulir_pindah">Formulir F-1.03 Pindah</option>
            <option value="foto_pemohon">Foto pemohon memegang KK/KTP/Form pindah</option>
            <option value="kk_ktp">KK/KTP/Surat keterangan hilang KK atau KTP</option>
            <option value="buku_nikah">Buku nikah/akta nikah</option>
            <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div class="file-wrapper">
            <label class="file-label" for="fileInput${dokumenCounter}">Pilih File</label>
            <span id="fileName${dokumenCounter}" class="file-name">Belum ada file dipilih</span>
            <input type="file" id="fileInput${dokumenCounter}" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
        </div>
        <div class="file-size-warning" id="fileSize${dokumenCounter}">Maksimal 5 MB per file, dikompres otomatis</div>
        <button type="button" class="btn-remove-file" onclick="removeFileInput(this)">× Hapus</button>
    `;
    
    container.appendChild(newGroup);
}

function removeFileInput(button) {
    const group = button.closest('.file-input-group');
    if (group && group.id !== 'fileGroup1') {
        group.remove();
    }
}

function removeExistingDocument(button, index) {
    if (confirm('Apakah Anda yakin ingin menghapus dokumen ini?')) {
        const docItem = button.closest('.existing-doc-item');
        const checkbox = docItem.querySelector('.doc-checkbox');
        checkbox.checked = false;
        docItem.style.display = 'none';
    }
}

// Preview Document Function
function previewDocument(filePath) {
    const modal = document.getElementById('previewModal');
    const previewContent = document.getElementById('previewContent');
    
    // Cek tipe file untuk menentukan cara preview
    const extension = filePath.split('.').pop().toLowerCase();
    
    if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
        // Tampilkan gambar
        previewContent.innerHTML = `<img src="${filePath}" alt="Preview Dokumen" onerror="this.onerror=null; this.src='data:image/svg+xml;charset=UTF-8,<svg width=\"100%\" height=\"100%\" xmlns=\"http://www.w3.org/2000/svg\"><rect width=\"100%\" height=\"100%\" fill=\"%23f3f4f6\"/><text x=\"50%\" y=\"50%\" font-family=\"Arial\" font-size=\"16\" fill=\"%239ca3af\" text-anchor=\"middle\" dy=\".3em\">`;
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

// Delegasi global untuk memperbarui label dan menstabilkan setiap file yang dipilih.
document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    updateFileInputDisplay(target);
    stabilizeSelectedFile(target);
});
// Form Validation
document.getElementById('editPindahOnlineForm').addEventListener('submit', function(e) {
    let isValid = true;
    let errorMessages = [];
    
    // Validate No KK 16 digit
    const noKKInput = document.querySelector('input[name="no_kk"]');
    if (noKKInput && noKKInput.value.length !== 16) {
        errorMessages.push('Nomor KK harus 16 digit');
        noKKInput.style.borderColor = '#ef4444';
        isValid = false;
    } else if (noKKInput) {
        noKKInput.style.borderColor = '#d1d5db';
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
            input.style.borderColor = '#ef4444';
            isValid = false;
        } else {
            input.style.borderColor = '#d1d5db';
        }
    });
    
    // Validate dokumen wajib
    const existingDocsChecked = document.querySelectorAll('input[name="existing_docs[]"]:checked');
    const docTypeSelects = document.querySelectorAll('select[name="doc_type_new[]"]');
    const fileInputs = document.querySelectorAll('input[type="file"]');
    
    let hasFormulir = false;
    let hasFoto = false;
    let hasKKKTP = false;
    
    // Cek dokumen lama yang dipilih
    existingDocsChecked.forEach(checkbox => {
        const docItem = checkbox.closest('.existing-doc-item');
        const badge = docItem.querySelector('.doc-type-badge');
        if (badge) {
            if (badge.classList.contains('badge-formulir_pindah')) hasFormulir = true;
            if (badge.classList.contains('badge-foto_pemohon')) hasFoto = true;
            if (badge.classList.contains('badge-kk_ktp')) hasKKKTP = true;
        }
    });
    
    // Cek dokumen baru yang diupload
    docTypeSelects.forEach((select, index) => {
        const type = select.value;
        const fileInput = fileInputs[index];
        
        if (fileInput && fileInput.files.length > 0) {
            if (type === 'formulir_pindah') hasFormulir = true;
            if (type === 'foto_pemohon') hasFoto = true;
            if (type === 'kk_ktp') hasKKKTP = true;
        }
    });
    
    if (!hasFormulir) {
        errorMessages.push('Formulir F-1.03 Pindah wajib diupload atau dipilih dari dokumen yang ada');
        isValid = false;
    }
    
    if (!hasFoto) {
        errorMessages.push('Foto pemohon memegang KK/KTP/Form pindah wajib diupload atau dipilih dari dokumen yang ada');
        isValid = false;
    }
    
    if (!hasKKKTP) {
        errorMessages.push('KK/KTP/Surat keterangan hilang KK atau KTP wajib diupload atau dipilih dari dokumen yang ada');
        isValid = false;
    }
    
    if (!isValid) {
        e.preventDefault();
        let message = 'Harap perbaiki kesalahan berikut:\n\n';
        if (errorMessages.length > 0) {
            message += '• ' + errorMessages.join('\n• ');
        }
        alert(message);
    } else {
        const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan kembali permohonan pindah masuk?\n\nPastikan dokumen wajib sudah lengkap.');
        if (!confirmSubmit) {
            e.preventDefault();
        }
    }
});

// Logout Modal Functions
document.addEventListener('DOMContentLoaded', function() {
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
});
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>


