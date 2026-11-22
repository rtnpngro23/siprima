<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
require_once __DIR__ . '/../functions/kk_kategori.php';
include '../../app/middleware/petugas_desa_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_kartukeluarga LIKE 'resubmitted_at'");
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
    nama_kepalaKeluarga, 
    anggotaKeluarga, 
    keterangan,
    dokumen, 
    status,
    alasan,
    nama_folder,
    kategori_layanan
    FROM tb_kartukeluarga 
    WHERE id = ? AND id_user = ?");
$stmt->bind_param('ii', $permohonan_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header('Location: petugas_desa_status_permohonan.php?error=not_found');
    exit;
}

$permohonan = $res->fetch_assoc();

// Kategori selain kk_baru (KK hilang, cetak biodata, perubahan data) punya form edit ringkas sendiri.
if (siprima_kk_kategori_normalize($permohonan['kategori_layanan'] ?? null) !== KK_KATEGORI_DEFAULT) {
    header('Location: petugas_desa_edit_kk_layanan.php?id=' . $permohonan_id);
    exit;
}

// Hanya izinkan edit saat status diajukan atau ditolak
if (!in_array(strtolower((string)$permohonan['status']), ['diajukan', 'ditolak'], true)) {
    header('Location: petugas_desa_status_permohonan.php?error=cannot_edit');
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

// ========= PERBAIKAN UTAMA: Sesuaikan pemisahan dokumen dengan petugas_desa_kk.php =========
// PENTING: Di petugas_desa_kk.php, jenis dokumen adalah 'formulir_f101' dan 'kk_lama'
// BUKAN 'kk_lama_atau_surat_kehilangan'
$dokumen_formulir = null;
$dokumen_kk_lama = null;
$dokumen_opsional = [];

foreach ($dokumen_data as $index => $doc) {
    // PERBAIKAN: Gunakan 'kk_lama' bukan 'kk_lama_atau_surat_kehilangan'
    if ($doc['type'] === 'formulir_f101') {
        $dokumen_formulir = [
            'data' => $doc,
            'index' => $index
        ];
    } elseif ($doc['type'] === 'kk_lama') { // PERBAIKAN DI SINI
        $dokumen_kk_lama = [
            'data' => $doc,
            'index' => $index
        ];
    } else {
        $dokumen_opsional[] = [
            'data' => $doc,
            'index' => $index
        ];
    }
}

// Cek apakah dokumen wajib sudah ada
$has_formulir = !is_null($dokumen_formulir);
$has_kk_lama = !is_null($dokumen_kk_lama);

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

// Proses form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') siprima_validate_request_images($errors);
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== FORM SUBMITTED ===");
    
    // Data pemohon - ambil dari data lama
    $nama_pemohon = $permohonan['nama_pemohon'] ?? '';
    $nik_pemohon = $permohonan['nik_pemohon'] ?? '';
    $no_hp = $permohonan['no_hp'] ?? '';
    $email = $permohonan['email'] ?? '';
    
    // Kecamatan dan Desa sesuai user
    $id_kecamatan = $user_kecamatan_id;
    $id_desa = $user_desa_id;
    $nama_kecamatan_db = $nama_kecamatan_user;
    $nama_desa_db = $nama_desa_user;
    
    // Data keluarga (bisa diubah)
    $dusun = trim($_POST['dusun'] ?? '');
    $nama_kepalaKeluarga = trim($_POST['nama_kepalaKeluarga'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi
    if (empty($dusun)) $errors[] = "Dusun harus diisi";
    if (empty($nama_kepalaKeluarga)) $errors[] = "Nama kepala keluarga harus diisi";
    
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
    
    // GUNAKAN FOLDER YANG SAMA (tidak membuat folder baru)
    $folder_name = $permohonan['nama_folder'] ?? '';
    
    if (empty($folder_name)) {
        // Jika tidak ada nama folder, buat dari data pemohonan
        function cleanFolderName($string) {
            $string = preg_replace('/[^A-Za-z0-9\s\-]/', '', $string);
            $string = preg_replace('/\s+/', '_', $string);
            return $string;
        }
        
        $folder_name = cleanFolderName($nama_kecamatan_db) . ',' . 
                       cleanFolderName($nama_desa_db) . ',' . 
                       $user_id . ',' . 
                       $nik_pemohon . cleanFolderName($nama_pemohon);
    }
    
    $folder_path = __DIR__ . '/uploads/kk/' . $folder_name . '/';
    
    // Pastikan folder ada
    if (!is_dir($folder_path)) {
        if (!mkdir($folder_path, 0755, true)) {
            $errors[] = 'Folder penyimpanan tidak ditemukan dan gagal dibuat';
        }
    }
    
    // Handle upload dokumen - Gunakan folder yang sama
    $dokumen_baru = [];
    $uploadedDocTypes = [];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Proses upload dokumen baru
    $uploaded_files = [];
    
    // === SIMPAN DOKUMEN LAMA YANG TIDAK DIREPLACE ===
    // 1. Dokumen Formulir F-1.01
    $formulir_keep_old = isset($_POST['keep_formulir_old']) && $_POST['keep_formulir_old'] == '1';
    $formulir_file = null;
    
    if (isset($_FILES['formulir_file']) && $_FILES['formulir_file']['error'] === UPLOAD_ERR_OK) {
        $formulir_file = $_FILES['formulir_file'];
    }
    
    // Jika ada file formulir baru atau mempertahankan yang lama
    if ($formulir_file || ($formulir_keep_old && $dokumen_formulir)) {
        if ($formulir_file) {
            // Upload file baru
            $fileTmp = $formulir_file['tmp_name'];
            $fileName = basename($formulir_file['name']);
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileType = mime_content_type($fileTmp);
            $fileSize = $formulir_file['size'];
            $docType = 'formulir_f101';

            // Validasi tipe file
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) || 
                !in_array($fileType, $allowedTypes)) {
                $errors[] = "File {$fileName}: Hanya format JPG, PNG, atau GIF yang diperbolehkan";
            } elseif ($fileSize > $maxFileSize) {
                $errors[] = "File {$fileName}: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
            } else {
                // Generate unique filename
                $timestamp_file = time();
                $randomString = bin2hex(random_bytes(6));
                $newName = $docType . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                $destination = $folder_path . $newName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    $dokumen_baru[] = [
                        'path' => 'uploads/kk/' . $folder_name . '/' . $newName,
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
            }
        } elseif ($formulir_keep_old && $dokumen_formulir) {
            // Simpan dokumen lama
            $dokumen_baru[] = $dokumen_formulir['data'];
            $uploadedDocTypes[] = 'formulir_f101';
        }
    }
    
    // 2. Dokumen KK Lama (PERBAIKAN: gunakan 'kk_lama' bukan 'kk_lama_atau_surat_kehilangan')
    $kk_lama_keep_old = isset($_POST['keep_kk_lama_old']) && $_POST['keep_kk_lama_old'] == '1';
    $kk_lama_file = null;
    
    if (isset($_FILES['kk_lama_file']) && $_FILES['kk_lama_file']['error'] === UPLOAD_ERR_OK) {
        $kk_lama_file = $_FILES['kk_lama_file'];
    }
    
    // Jika ada file KK lama baru atau mempertahankan yang lama
    if ($kk_lama_file || ($kk_lama_keep_old && $dokumen_kk_lama)) {
        if ($kk_lama_file) {
            // Upload file baru
            $fileTmp = $kk_lama_file['tmp_name'];
            $fileName = basename($kk_lama_file['name']);
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileType = mime_content_type($fileTmp);
            $fileSize = $kk_lama_file['size'];
            $docType = 'kk_lama'; // PERBAIKAN DI SINI

            // Validasi tipe file
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) || 
                !in_array($fileType, $allowedTypes)) {
                $errors[] = "File {$fileName}: Hanya format JPG, PNG, atau GIF yang diperbolehkan";
            } elseif ($fileSize > $maxFileSize) {
                $errors[] = "File {$fileName}: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
            } else {
                // Generate unique filename
                $timestamp_file = time();
                $randomString = bin2hex(random_bytes(6));
                $newName = $docType . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                $destination = $folder_path . $newName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    $dokumen_baru[] = [
                        'path' => 'uploads/kk/' . $folder_name . '/' . $newName,
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
            }
        } elseif ($kk_lama_keep_old && $dokumen_kk_lama) {
            // Simpan dokumen lama
            $dokumen_baru[] = $dokumen_kk_lama['data'];
            $uploadedDocTypes[] = 'kk_lama'; // PERBAIKAN DI SINI
        }
    }
    
    // 3. Proses file opsional lainnya
    if (isset($_FILES['dokumen_opsional']) && is_array($_FILES['dokumen_opsional']['name'])) {
        $fileCount = count($_FILES['dokumen_opsional']['name']);
        $docTypeOpsional = $_POST['doc_type_opsional'] ?? [];
        
        for ($i = 0; $i < $fileCount; $i++) {
            // Skip jika tidak ada file atau doc_type tidak dipilih
            if ($_FILES['dokumen_opsional']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            
            // Validasi bahwa doc_type harus dipilih
            $docType = trim($docTypeOpsional[$i] ?? '');
            if (empty($docType)) {
                $fileName = basename($_FILES['dokumen_opsional']['name'][$i]);
                $errors[] = "File {$fileName}: Pilih jenis dokumen terlebih dahulu";
                continue;
            }
            
            if ($_FILES['dokumen_opsional']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen_opsional']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen_opsional']['name'][$i]);
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileType = mime_content_type($fileTmp);
                $fileSize = $_FILES['dokumen_opsional']['size'][$i];

                // Validasi tipe file
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) || 
                    !in_array($fileType, $allowedTypes)) {
                    $errors[] = "File {$fileName}: Hanya format JPG, PNG, atau GIF yang diperbolehkan";
                    continue;
                }

                if ($fileSize > $maxFileSize) {
                    $errors[] = "File {$fileName}: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
                    continue;
                }

                // Generate unique filename
                $timestamp_file = time();
                $randomString = bin2hex(random_bytes(6));
                $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                $newName = $clean_doc_type . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                $destination = $folder_path . $newName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    $dokumen_baru[] = [
                        'path' => 'uploads/kk/' . $folder_name . '/' . $newName,
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
            }
        }
    }
    
    // 4. Tambahkan dokumen opsional lama yang dipilih
    if (isset($_POST['existing_docs']) && is_array($_POST['existing_docs'])) {
        foreach ($_POST['existing_docs'] as $doc_index) {
            if (isset($dokumen_data[$doc_index])) {
                $dokumen_baru[] = $dokumen_data[$doc_index];
                $uploadedDocTypes[] = $dokumen_data[$doc_index]['type'];
            }
        }
    }
    
    // Tambahkan file .htaccess untuk keamanan jika belum ada
    $htaccessFile = $folder_path . '/.htaccess';
    if (!file_exists($htaccessFile)) {
        file_put_contents($htaccessFile, 
            "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
    }
    
    // Cek dokumen wajib
    $hasFormulirInNew = false;
    $hasKKLamaInNew = false;
    
    foreach ($dokumen_baru as $doc) {
        if ($doc['type'] === 'formulir_f101') {
            $hasFormulirInNew = true;
        }
        if ($doc['type'] === 'kk_lama') { // PERBAIKAN DI SINI
            $hasKKLamaInNew = true;
        }
    }
    
    if (!$hasFormulirInNew) {
        $errors[] = 'Dokumen wajib Formulir F-1.01 Biodata Keluarga belum diupload';
    }
    
    if (!$hasKKLamaInNew) {
        $errors[] = 'Dokumen wajib KK Lama / Surat Keterangan Kehilangan KK belum diupload';
    }

    // Jika tidak ada error, update data
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        // Convert data ke JSON
        $anggota_keluarga_json = json_encode($anggota_keluarga_baru);
        $dokumen_json = json_encode($dokumen_baru);
        
        // Update data permohonan
        $stmt = $koneksi->prepare("UPDATE tb_kartukeluarga SET 
            nama_pemohon = ?,
            nik_pemohon = ?,
            no_hp = ?,
            email = ?,
            id_kecamatan = ?,
            id_desa = ?,
            dusun = ?,
            nama_kepalaKeluarga = ?,
            anggotaKeluarga = ?,
            keterangan = ?,
            dokumen = ?,
            nama_kecamatan = ?,
            nama_desa = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?");
        
        if ($stmt === false) {
            $errors[] = "Gagal menyiapkan statement: " . $koneksi->error;
        } else {
            $stmt->bind_param('ssssiisssssssii', 
                $nama_pemohon,
                $nik_pemohon,
                $no_hp,
                $email,
                $id_kecamatan,
                $id_desa,
                $dusun,
                $nama_kepalaKeluarga,
                $anggota_keluarga_json,
                $keterangan,
                $dokumen_json,
                $nama_kecamatan_db,
                $nama_desa_db,
                $permohonan_id,
                $user_id
            );
            
            if ($stmt->execute()) {
            logPermohonanStatusHistory(
                $koneksi,
                'tb_kartukeluarga',
                $permohonan_id,
                'diajukan',
                null,
                (int)($_SESSION['id_user'] ?? 0),
                (string)($_SESSION['role'] ?? 'user'),
                'resubmit'
            );
                // Hapus file lama yang tidak dipilih lagi
                $existing_docs = $_POST['existing_docs'] ?? [];
                foreach ($dokumen_data as $index => $doc) {
                    if (!in_array($index, $existing_docs) && isset($doc['path']) && file_exists(__DIR__ . '/' . $doc['path'])) {
                        // Hapus file fisik
                        @unlink(__DIR__ . '/' . $doc['path']);
                    }
                }
                
                // Hapus file lama jika di-replace dengan yang baru
                if ($formulir_file && $dokumen_formulir && isset($dokumen_formulir['data']['path'])) {
                    @unlink(__DIR__ . '/' . $dokumen_formulir['data']['path']);
                }
                if ($kk_lama_file && $dokumen_kk_lama && isset($dokumen_kk_lama['data']['path'])) {
                    @unlink(__DIR__ . '/' . $dokumen_kk_lama['data']['path']);
                }
                
                $success = true;
                $_SESSION['success_message'] = "Permohonan berhasil diperbarui dan diajukan kembali!";
                header('Location: petugas_desa_status_permohonan.php');
                exit;
            } else {
                $errors[] = "Gagal memperbarui data: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Fungsi helper untuk format ukuran file
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' Bytes';
    }
}

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Permohonan Kartu Keluarga - Petugas Desa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/petugas_desa_edit_kk.css">
    <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
    
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="petugas-desa-ui tema-form tema-add">

<div class="layout">
    <!-- SIDEBAR -->
    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header petugas-desa-header">
            <?php
            require_once __DIR__ . '/petugas_desa_header_brand.php';
            render_petugas_desa_header_brand(
                'Edit Permohonan Kartu Keluarga',
                'Petugas Desa • ' . $nama_kecamatan_user . ' / ' . $nama_desa_user
            );
            ?>
            <div class="user-info">
                <span class="user-role">Petugas Desa</span>
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

            <div class="info-box">
                <p><strong>⚠️ Perhatian:</strong> Anda sebagai <strong>Petugas Desa</strong> hanya dapat mengajukan permohonan untuk wilayah:</p>
                <p><strong>Kecamatan:</strong> <?= esc($nama_kecamatan_user) ?> | <strong>Desa:</strong> <?= esc($nama_desa_user) ?></p>
                <p><strong>ℹ️ Catatan:</strong> Data pemohon (Nama, NIK, No HP, Email) dan wilayah (Kecamatan/Desa) tidak dapat diubah.</p>
            </div>

            <div class="form-container">
                <div class="form-header">
                    <h2>Edit Permohonan Kartu Keluarga</h2>
                    <span class="status-badge">Status: <?= esc(ucfirst(strtolower((string)($permohonan['status'] ?? '-')))) ?></span>
                </div>

                <!-- Alasan Penolakan -->
                <?php if (!empty($permohonan['alasan'])): ?>
                <div class="alasan-box">
                    <h4>Alasan Penolakan Sebelumnya:</h4>
                    <?= nl2br(esc($permohonan['alasan'])) ?>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="editKKForm">
<?php echo csrf_input(); ?>
                    <!-- Data Pemohon (Tidak bisa diubah) -->
                    <div class="form-section">
                        <h3 class="section-title">Data Pemohon</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Pemohon <span class="required">*</span></label>
                                <input type="text" name="nama_pemohon" class="form-control" 
                                       value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>" required readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Pemohon <span class="required">*</span></label>
                                <input type="text" name="nik_pemohon" class="form-control" 
                                       value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>" required readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>No HP <span class="required">*</span></label>
                                <input type="tel" name="no_hp" class="form-control" 
                                       value="<?= esc($permohonan['no_hp'] ?? '') ?>" required readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?= esc($permohonan['email'] ?? '') ?>" required readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Data Domisili (Tidak bisa diubah) -->
                    <div class="form-section">
                        <h3 class="section-title">Data Domisili</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Kecamatan <span class="required">*</span></label>
                                <input type="text" class="form-control" 
                                       value="<?= esc($nama_kecamatan_user) ?>" readonly>
                                <input type="hidden" name="id_kecamatan" value="<?= $user_kecamatan_id ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Desa <span class="required">*</span></label>
                                <input type="text" class="form-control" 
                                       value="<?= esc($nama_desa_user) ?>" readonly>
                                <input type="hidden" name="id_desa" value="<?= $user_desa_id ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Dusun/Alamat <span class="required">*</span></label>
                                <input type="text" name="dusun" class="form-control" 
                                       value="<?= esc($permohonan['dusun'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Nama Kepala Keluarga <span class="required">*</span></label>
                                <input type="text" name="nama_kepalaKeluarga" class="form-control" 
                                       value="<?= esc($permohonan['nama_kepalaKeluarga'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Anggota Keluarga -->
                    <div class="form-section">
                        <h3 class="section-title">Anggota Keluarga</h3>
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
                                                   placeholder="NIK">
                                        </td>
                                        <td>
                                            <input type="text" name="anggota_hubungan[]" 
                                                   value="<?= esc($anggota['hubungan'] ?? '') ?>" 
                                                   placeholder="Contoh: Anak, Istri">
                                        </td>
                                        <td>
                                            <?php if ($index > 0): ?>
                                            <button type="button" class="btn-remove-anggota" 
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
                                               placeholder="NIK">
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
                        <button type="button" class="btn-add-anggota" onclick="addAnggota()">
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
                                <li><strong>Dokumen WAJIB:</strong> Formulir F-1.01 Biodata Keluarga dan KK Lama / Surat Keterangan Kehilangan KK</li>
                                <li><strong>Dokumen OPSIONAL:</strong> KTP, Buku Nikah, Akta Lahir, dll (jika ada)</li>
                                <li>Format file: JPG, JPEG, PNG, GIF</li>
                                <li>Ukuran maksimal per file: 1MB</li>
                            </ul>
                        </div>
                        
                        <!-- Dokumen Wajib -->
                        <div style="margin-bottom: 20px; padding: 15px; background: #fee2e2; border-radius: 8px; border-left: 4px solid #dc2626;">
                            <h4 style="margin-top: 0; color: #dc2626; font-size: 16px;">
                                <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                            </h4>
                            
                            <!-- Formulir F-1.01 (Wajib) -->
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label>1. Formulir F-1.01 Biodata Keluarga <span class="required">*</span></label>
                                <div class="file-upload-area" id="formulirUploadArea">
                                    <?php if ($dokumen_formulir): ?>
                                    <div class="current-document">
                                        <div class="doc-info">
                                            <span class="doc-type-badge badge-formulir">Formulir F-1.01 (Terupload)</span>
                                            <span class="doc-name"><?= esc($dokumen_formulir['data']['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <?php if (isset($dokumen_formulir['data']['path']) && file_exists(__DIR__ . '/' . $dokumen_formulir['data']['path'])): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= htmlspecialchars(siprima_document_token_url(__DIR__, (string)$dokumen_formulir['data']['path']) ?? '', ENT_QUOTES, 'UTF-8') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="keep-old-checkbox">
                                        <input type="checkbox" id="keepFormulirOld" name="keep_formulir_old" value="1" checked>
                                        <label for="keepFormulirOld">Pertahankan dokumen ini</label>
                                    </div>
                                    <hr style="margin: 15px 0; border-color: #e5e7eb;">
                                    <?php endif; ?>
                                    
                                    <label class="file-upload-label">
                                        <i class="fas fa-upload"></i> <?= $dokumen_formulir ? 'Ganti File Formulir' : 'Unggah Formulir F-1.01' ?>
                                        <input type="file" name="formulir_file" accept=".jpg,.jpeg,.jfif,.jpe,.png" <?= !$dokumen_formulir ? 'required' : '' ?>>
                                    </label>
                                    <div class="file-preview" id="formulir-preview"></div>
                                    <small style="color: #6b7280; display: block; margin-top: 10px;"><?= $dokumen_formulir ? 'Kosongkan jika ingin mempertahankan dokumen saat ini' : 'Harus diunggah' ?></small>
                                </div>
                            </div>
                            
                            <!-- KK Lama / Surat Kehilangan (Wajib) -->
                            <div class="form-group">
                                <label>2. KK Lama / Surat Keterangan Kehilangan KK <span class="required">*</span></label>
                                <div class="file-upload-area" id="kkLamaUploadArea">
                                    <?php if ($dokumen_kk_lama): ?>
                                    <div class="current-document">
                                        <div class="doc-info">
                                            <span class="doc-type-badge badge-kk_lama">KK Lama/Surat Kehilangan (Terupload)</span>
                                            <span class="doc-name"><?= esc($dokumen_kk_lama['data']['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <?php if (isset($dokumen_kk_lama['data']['path']) && file_exists(__DIR__ . '/' . $dokumen_kk_lama['data']['path'])): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= htmlspecialchars(siprima_document_token_url(__DIR__, (string)$dokumen_kk_lama['data']['path']) ?? '', ENT_QUOTES, 'UTF-8') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="keep-old-checkbox">
                                        <input type="checkbox" id="keepKKLamaOld" name="keep_kk_lama_old" value="1" checked>
                                        <label for="keepKKLamaOld">Pertahankan dokumen ini</label>
                                    </div>
                                    <hr style="margin: 15px 0; border-color: #e5e7eb;">
                                    <?php endif; ?>
                                    
                                    <label class="file-upload-label">
                                        <i class="fas fa-upload"></i> <?= $dokumen_kk_lama ? 'Ganti File KK Lama' : 'Unggah KK Lama atau Surat Kehilangan' ?>
                                        <input type="file" name="kk_lama_file" accept=".jpg,.jpeg,.jfif,.jpe,.png" <?= !$dokumen_kk_lama ? 'required' : '' ?>>
                                    </label>
                                    <div class="file-preview" id="kk-lama-preview"></div>
                                    <small style="color: #6b7280; display: block; margin-top: 10px;">Pilih salah satu: KK Lama <strong>ATAU</strong> Surat Keterangan Kehilangan dari kepolisian</small>
                                    <small style="color: #6b7280;"><?= $dokumen_kk_lama ? 'Kosongkan jika ingin mempertahankan dokumen saat ini' : 'Harus diunggah' ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dokumen Opsional -->
                        <div class="f-doc-group f-doc-group--opsional">
                            <h4 class="f-doc-group__judul">
                                <i class="fas fa-check-circle"></i> Dokumen Opsional (Jika Ada)
                            </h4>
                            
                            <!-- Dokumen yang sudah ada -->
                            <?php if (!empty($dokumen_opsional)): ?>
                            <div class="document-list" id="existing-documents">
                                <?php foreach ($dokumen_opsional as $doc_item): 
                                    $doc = $doc_item['data'];
                                    $index = $doc_item['index'];
                                ?>
                                    <div class="document-item" data-index="<?= $index ?>">
                                        <div class="doc-info">
                                            <?php 
                                            $badge_class = 'badge-' . str_replace('_', '-', $doc['type']);
                                            $type_labels = [
                                                'ktp' => 'KTP',
                                                'surat_nikah' => 'Buku Nikah',
                                                'akta_lahir' => 'Akta Lahir',
                                                'akta_kematian' => 'Akta Kematian',
                                                'surat_pindah' => 'Surat Pindah',
                                                'lainnya' => 'Lainnya'
                                            ];
                                            $type_label = $type_labels[$doc['type']] ?? $doc['type'];
                                            ?>
                                            <span class="doc-type-badge <?= $badge_class ?>"><?= $type_label ?></span>
                                            <span class="doc-name"><?= esc($doc['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <input type="checkbox" name="existing_docs[]" value="<?= $index ?>" checked style="margin-right: 10px;">
                                            <?php if (isset($doc['path']) && file_exists(__DIR__ . '/' . $doc['path'])): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= htmlspecialchars(siprima_document_token_url(__DIR__, (string)$doc['path']) ?? '', ENT_QUOTES, 'UTF-8') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn-remove-anggota" onclick="removeExistingDocument(this, <?= $index ?>)">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Input opsional baru -->
                            <div id="opsionalUploadContainer">
                                <!-- Input opsional pertama -->
                                <div class="file-input-group" id="fileGroupOpsional1" style="margin-bottom: 15px;">
                                    <select name="doc_type_opsional[]" class="form-control" style="margin-bottom: 10px;">
                                        <option value="">Pilih Jenis Dokumen (Opsional)</option>
                                        <option value="ktp">KTP</option>
                                        <option value="surat_nikah">Buku Nikah/Akta Nikah/Akta Cerai</option>
                                        <option value="akta_lahir">Akta Lahir</option>
                                        <option value="akta_kematian">Akta Kematian</option>
                                        <option value="surat_pindah">Surat Pindah Datang</option>
                                        <option value="lainnya">Dokumen Lainnya</option>
                                    </select>
                                    <div class="file-upload-area">
                                        <label class="file-upload-label">
                                            <i class="fas fa-upload"></i> Pilih File
                                            <input type="file" name="dokumen_opsional[]" accept=".jpg,.jpeg,.jfif,.jpe,.png">
                                        </label>
                                        <div class="file-preview">
                                            <span class="file-name">Belum ada file dipilih</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="button" class="btn-add" onclick="addOptionalFileInput()">
                                <i class="fas fa-plus"></i> Tambah Dokumen Opsional Lainnya
                            </button>
                        </div>
                    </div>

                    <!-- Keterangan -->
                    <div class="form-section">
                        <h3 class="section-title">Keterangan</h3>
                        <div class="form-group">
                            <label>Keterangan Tambahan</label>
                            <textarea name="keterangan" class="form-control" rows="3" 
                                      placeholder="Tambahkan keterangan jika diperlukan" maxlength="500"><?= esc($permohonan['keterangan'] ?? '') ?></textarea>
                        </div>
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
// Variabel global
let fileCounter = {
    opsional: 1
};
const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB

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

// Toggle Sidebar Menu on Mobile
// Delegasi global untuk menstabilkan setiap file yang dipilih.
document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    stabilizeSelectedFile(target);
});
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
    
    // File upload preview untuk dokumen wajib
    const formulirInput = document.querySelector('input[name="formulir_file"]');
    const kkLamaInput = document.querySelector('input[name="kk_lama_file"]');
    
    if (formulirInput) {
        formulirInput.addEventListener('change', function() {
            const previewDiv = document.getElementById('formulir-preview');
            handleFilePreview(this, previewDiv, 'Formulir F-1.01 (Baru)');
        });
    }
    
    if (kkLamaInput) {
        kkLamaInput.addEventListener('change', function() {
            const previewDiv = document.getElementById('kk-lama-preview');
            handleFilePreview(this, previewDiv, 'KK Lama (Baru)');
        });
    }
    
    // Initialize optional file inputs
    initializeOptionalFileInputs();
    
    // Initialize form validation
    initializeFormValidation();
});

// Fungsi untuk format ukuran file
function formatFileSize(bytes) {
    if (bytes >= 1048576) {
        return (bytes / 1048576).toFixed(2) + ' MB';
    } else if (bytes >= 1024) {
        return (bytes / 1024).toFixed(2) + ' KB';
    } else {
        return bytes + ' Bytes';
    }
}

// Fungsi untuk menangani preview file
function handleFilePreview(input, previewDiv, label) {
    if (input.files.length > 0) {
        const file = input.files[0];
        
        // Validasi ukuran file (1MB)
        if (file.size > MAX_FILE_SIZE) {
            alert('Ukuran file terlalu besar. Maksimal 1MB.');
            input.value = '';
            previewDiv.innerHTML = '<span class="file-name">Belum ada file diunggah</span>';
            return;
        }
        
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
        previewDiv.innerHTML = `
            <div class="current-document" style="margin-top: 15px;">
                <div class="doc-info">
                    <span class="doc-type-badge">${label}</span>
                    <span class="doc-name">${file.name}</span>
                    <small>(${fileSizeMB} MB)</small>
                </div>
                <div class="doc-actions">
                    <button type="button" class="btn-preview" onclick="previewImage('${URL.createObjectURL(file)}')">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                </div>
            </div>
        `;
        
        // Uncheck "keep old" checkbox
        if (label.includes('Formulir')) {
            const keepCheckbox = document.getElementById('keepFormulirOld');
            if (keepCheckbox) keepCheckbox.checked = false;
        } else if (label.includes('KK Lama')) {
            const keepCheckbox = document.getElementById('keepKKLamaOld');
            if (keepCheckbox) keepCheckbox.checked = false;
        }
    } else {
        previewDiv.innerHTML = '';
    }
}

// Anggota Keluarga Functions
function addAnggota() {
    const tableBody = document.getElementById('anggotaBody');
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td>
            <input type="text" name="anggota_nama[]" placeholder="Nama lengkap" required>
        </td>
        <td>
            <input type="text" name="anggota_nik[]" placeholder="NIK">
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

// Dokumen Opsional Functions
function initializeOptionalFileInputs() {
    const optionalContainer = document.getElementById('opsionalUploadContainer');
    const firstFileGroup = optionalContainer.querySelector('.file-input-group');
    
    if (firstFileGroup) {
        const fileInput = firstFileGroup.querySelector('input[type="file"]');
        const filePreview = firstFileGroup.querySelector('.file-preview');
        
        if (fileInput && filePreview) {
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    const file = this.files[0];
                    const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                    filePreview.innerHTML = `<span class="file-name">${file.name} (${fileSizeMB} MB)</span>`;
                } else {
                    filePreview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
                }
            });
        }
    }
}

function addOptionalFileInput() {
    fileCounter.opsional++;
    const container = document.getElementById('opsionalUploadContainer');
    const newFileGroup = document.createElement('div');
    newFileGroup.className = 'file-input-group';
    newFileGroup.id = 'fileGroupOpsional' + fileCounter.opsional;
    newFileGroup.style.marginBottom = '15px';
    newFileGroup.innerHTML = `
        <select name="doc_type_opsional[]" class="form-control" style="margin-bottom: 10px;">
            <option value="">Pilih Jenis Dokumen (Opsional)</option>
            <option value="ktp">KTP</option>
            <option value="surat_nikah">Buku Nikah/Akta Nikah/Akta Cerai</option>
            <option value="akta_lahir">Akta Lahir</option>
            <option value="akta_kematian">Akta Kematian</option>
            <option value="surat_pindah">Surat Pindah Datang</option>
            <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div class="file-upload-area">
            <label class="file-upload-label">
                <i class="fas fa-upload"></i> Pilih File
                <input type="file" name="dokumen_opsional[]" accept=".jpg,.jpeg,.jfif,.jpe,.png">
            </label>
            <div class="file-preview">
                <span class="file-name">Belum ada file dipilih</span>
            </div>
        </div>
        <button type="button" class="btn-remove-anggota" onclick="removeOptionalFileInput(${fileCounter.opsional})" style="margin-top: 10px;">
            <i class="fas fa-trash"></i> Hapus Input
        </button>
    `;
    container.appendChild(newFileGroup);
    
    // Add event listener to new file input
    const newFileInput = newFileGroup.querySelector('input[type="file"]');
    const newFilePreview = newFileGroup.querySelector('.file-preview');
    
    if (newFileInput && newFilePreview) {
        newFileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const file = this.files[0];
                
                // Validasi ukuran file client-side
                if (file.size > MAX_FILE_SIZE) {
                    alert(`File ${file.name} terlalu besar. Maksimal 1MB.`);
                    this.value = '';
                    newFilePreview.innerHTML = '<span class="file-name">File terlalu besar, pilih file lain</span>';
                    return;
                }
                
                const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                newFilePreview.innerHTML = `<span class="file-name">${file.name} (${fileSizeMB} MB)</span>`;
            } else {
                newFilePreview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
            }
        });
    }
}

function removeOptionalFileInput(fileId) {
    const fileGroup = document.getElementById('fileGroupOpsional' + fileId);
    if (fileGroup) {
        // Don't remove the first optional file group
        if (fileId > 1) {
            fileGroup.remove();
        } else {
            alert('Dokumen opsional pertama tidak dapat dihapus.');
        }
    }
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
    
    if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
        // Untuk path relatif dari folder user, ubah uploads/ menjadi ./uploads/ atau ../uploads/
        let fullPath = path;
        if (!path.startsWith('http') && !path.startsWith('/')) {
            // Path relatif - perlu disesuaikan karena file ini berada di folder user
            fullPath = './' + path;
        } else if (!path.startsWith('http')) {
            // Path absolut dari root
            fullPath = window.location.origin + path;
        }
        previewImage(fullPath);
    } else {
        alert('Format file tidak didukung untuk preview. Hanya gambar yang dapat dipreview.');
    }
}

function previewImage(src) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    content.innerHTML = `<img src="${src}" style="max-width: 100%; max-height: 70vh; display: block; margin: 0 auto;" onerror="this.onerror=null; alert('Gagal memuat gambar. File mungkin tidak tersedia.'); closePreview();">`;
    modal.style.display = 'flex';
    
    // Close modal when clicking outside
    modal.onclick = function(event) {
        if (event.target === modal) {
            closePreview();
        }
    };
    
    // Close with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal.style.display === 'flex') {
            closePreview();
        }
    });
}

function closePreview() {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    if (modal) {
        modal.style.display = 'none';
    }
    if (content) {
        content.innerHTML = '';
    }
}

// Form Validation
function initializeFormValidation() {
    const form = document.getElementById('editKKForm');
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        let isValid = true;
        let errorMessages = [];
        
        // Validasi required fields (kecuali file input)
        const requiredFields = this.querySelectorAll('[required]');
        requiredFields.forEach(field => {
            // Skip file inputs for now
            if (field.type === 'file') return;
            
            if (!field.value.trim()) {
                field.style.borderColor = '#ef4444';
                isValid = false;
                errorMessages.push(`"${field.name}" harus diisi`);
            } else {
                field.style.borderColor = '';
            }
        });
        
        // Validasi at least one anggota keluarga
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
        
        // Validasi dokumen wajib Formulir F-1.01
        const formulirFile = document.querySelector('input[name="formulir_file"]');
        const keepFormulirOld = document.querySelector('#keepFormulirOld');
        const hasFormulir = (formulirFile && formulirFile.files.length > 0) || 
                           (keepFormulirOld && keepFormulirOld.checked) || 
                           <?= $has_formulir ? 'true' : 'false' ?>;
        
        if (!hasFormulir) {
            errorMessages.push('Dokumen Formulir F-1.01 wajib diunggah atau dipilih dari dokumen lama!');
            isValid = false;
        }
        
        // Validasi dokumen wajib KK Lama
        const kkLamaFile = document.querySelector('input[name="kk_lama_file"]');
        const keepKKLamaOld = document.querySelector('#keepKKLamaOld');
        const hasKKLama = (kkLamaFile && kkLamaFile.files.length > 0) || 
                         (keepKKLamaOld && keepKKLamaOld.checked) || 
                         <?= $has_kk_lama ? 'true' : 'false' ?>;
        
        if (!hasKKLama) {
            errorMessages.push('Dokumen KK Lama atau Surat Keterangan Kehilangan KK wajib diunggah atau dipilih dari dokumen lama!');
            isValid = false;
        }
        
        // Validasi ukuran file untuk file baru
        const fileInputs = this.querySelectorAll('input[type="file"]');
        fileInputs.forEach((input) => {
            if (input.files.length > 0) {
                const file = input.files[0];
                if (file.size > MAX_FILE_SIZE) {
                    errorMessages.push(`File "${file.name}" belum berhasil dikompres, pilih ulang file tersebut (${(file.size/1024/1024).toFixed(2)} MB)`);
                    isValid = false;
                }
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            let message = 'Harap perbaiki kesalahan berikut:\n\n';
            if (errorMessages.length > 0) {
                message += '• ' + errorMessages.join('\n• ');
            }
            alert(message);
            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            // Konfirmasi sebelum submit
            const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan kembali permohonan?');
            if (!confirmSubmit) {
                e.preventDefault();
            }
        }
    });
}
</script>

<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>


