<?php
require_once '../error_handler.php';
// File: edit_kua_kartukeluarga.php
include '../../app/middleware/user_kua_only.php'; // Validasi user KUA
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/notifikasi.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_kartukeluarga_kua LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$id_kua = (int)$_SESSION['id_kua'];

// Cek apakah ID permohonan diberikan
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: kua_status_permohonan.php?error=no_id');
    exit;
}

$permohonan_id = (int)$_GET['id'];

// Ambil data permohonan berdasarkan ID
$stmt = $koneksi->prepare("SELECT 
    kk.id, 
    kk.id_user,
    kk.id_kecamatan,
    kk.id_desa,
    kk.nama_pemohon,
    kk.nik_pemohon,
    kk.no_hp,
    kk.email,
    kk.nama_kecamatan, 
    kk.nama_desa,
    kk.dusun,
    kk.alamat_catin,
    kk.nama_kepalakeluarga, 
    kk.anggotakeluarga, 
    kk.keterangan,
    kk.dokumen, 
    kk.status,
    kk.alasan,
    kk.no_kk_lama_suami,
    kk.no_kk_lama_istri,
    kk.nama_folder,
    u.nama as nama_pengguna,
    u.email as email_pengguna
    FROM tb_kartukeluarga_kua kk
    LEFT JOIN tb_users u ON kk.id_user = u.id
    WHERE kk.id = ? AND kk.id_kua = ?");
$stmt->bind_param('ii', $permohonan_id, $id_kua);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header('Location: kua_status_permohonan.php?error=not_found');
    exit;
}

$permohonan = $res->fetch_assoc();

// Hanya izinkan edit saat status diajukan atau ditolak
if (!in_array(strtolower((string)$permohonan['status']), ['diajukan', 'ditolak'], true)) {
    header('Location: kua_status_permohonan.php?error=cannot_edit');
    exit;
}

// Dekode data JSON
$anggota_keluarga = [];
if (!empty($permohonan['anggotakeluarga'])) {
    $anggota_keluarga = json_decode($permohonan['anggotakeluarga'], true);
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
$folder_path = '';

// Cek apakah folder sudah ada
if (!empty($nama_folder)) {
    $folder_path = __DIR__ . '/uploads/kua_kk/' . $nama_folder . '/';
    if (!is_dir($folder_path)) {
        // Buat folder jika belum ada
        mkdir($folder_path, 0755, true);
        
        // Tambahkan .htaccess untuk keamanan
        $htaccessFile = $folder_path . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, 
                "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png)$\">\nAllow from all\n</Files>");
        }
    }
}

// Proses form submission
$errors = [];
$success = false;
$csrf_token_value = function_exists('csrf_token') ? csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Data pemohon TIDAK bisa diubah (dari database)
    $nama_pemohon = $permohonan['nama_pemohon'] ?? '';
    $nik_pemohon = $permohonan['nik_pemohon'] ?? '';
    $no_hp = $permohonan['no_hp'] ?? '';
    $email = $permohonan['email'] ?? '';
    
    // Kecamatan TIDAK bisa diubah (dari database)
    $id_kecamatan = $permohonan['id_kecamatan'] ?? 0;
    $nama_kecamatan = $permohonan['nama_kecamatan'] ?? '';
    
    // Data lain yang bisa diubah
    $id_desa = (int)($_POST['id_desa'] ?? 0);
    $dusun = trim($_POST['dusun'] ?? '');
    $alamat_catin = trim($_POST['alamat_catin'] ?? '');
    $nama_kepalakeluarga = trim($_POST['nama_kepalakeluarga'] ?? '');
    $no_kk_lama_suami = trim($_POST['no_kk_lama_suami'] ?? '');
    $no_kk_lama_istri = trim($_POST['no_kk_lama_istri'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi
    if (empty($nama_pemohon)) $errors[] = "Nama pemohon tidak valid";
    if (empty($nik_pemohon)) $errors[] = "NIK pemohon tidak valid";
    if (empty($no_hp)) $errors[] = "No HP tidak valid";
    if (empty($email)) $errors[] = "Email tidak valid";
    if (empty($id_kecamatan)) $errors[] = "Kecamatan tidak valid";
    if (empty($nama_kecamatan)) $errors[] = "Nama kecamatan tidak valid";
    if (empty($id_desa)) $errors[] = "Desa harus dipilih";
    if (empty($dusun)) $errors[] = "Dusun harus diisi";
    if (empty($alamat_catin)) $errors[] = "Status alamat harus dipilih";
    if (empty($nama_kepalakeluarga)) $errors[] = "Nama kepala keluarga harus diisi";
    if (empty($no_kk_lama_suami)) $errors[] = "No KK Lama Suami harus diisi";
    if (empty($no_kk_lama_istri)) $errors[] = "No KK Lama Istri harus diisi";
    
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
    
    // Handle upload dokumen - MAKSIMAL 1MB per file
    $dokumen_baru = [];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Tentukan dokumen wajib berdasarkan pilihan alamat
    $requiredDocs = [];
    $docNames = [
        'kk_lama_suami' => 'KK Lama Suami',
        'kk_lama_istri' => 'KK Lama Istri',
        'ktp_suami' => 'KTP Suami',
        'ktp_istri' => 'KTP Istri',
        'formulir_f101_halaman_1' => 'Formulir F-1.01 Biodata Keluarga Halaman 1',
        'formulir_f101_halaman_2' => 'Formulir F-1.01 Biodata Keluarga Halaman 2',
        'buku_nikah' => 'Buku Nikah',
        'formulir_f103' => 'Formulir F-1.03 Pindah'
    ];
    
    // Dokumen wajib default untuk alamat sama
    $requiredDocs = [
        'kk_lama_suami',
        'kk_lama_istri',
        'ktp_suami',
        'ktp_istri',
        'formulir_f101_halaman_1',
        'formulir_f101_halaman_2',
        'buku_nikah'
    ];
    if ($alamat_catin === 'alamat beda') {
        $requiredDocs[] = 'formulir_f103';
    }
    
    $uploadedDocTypes = [];
    
    // Buat folder jika belum ada
    if (empty($folder_path)) {
        // Buat folder baru berdasarkan data terbaru
        $nama_desa_db = '';
        if (!empty($id_desa)) {
            $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
            $qd->bind_param("i", $id_desa);
            $qd->execute();
            $rd = $qd->get_result()->fetch_assoc();
            if ($rd) $nama_desa_db = $rd['nama_desa'];
        }
        
        // Fungsi sanitasi nama untuk folder
        function cleanFolderName($string) {
            $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', $string);
            $string = preg_replace('/\s+/', '_', trim($string));
            return $string;
        }
        
        // Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama kepala keluarga]_[tanggal buat]
        $timestamp = date('Ymd_His');
        $kecamatan_clean = cleanFolderName($nama_kecamatan);
        $desa_clean = cleanFolderName($nama_desa_db);
        $pemohon_clean = cleanFolderName($nama_pemohon);
        $kepala_keluarga_clean = cleanFolderName($nama_kepalakeluarga);
        
        $nama_folder = $kecamatan_clean . '_' . 
                      $desa_clean . '_' . 
                      $nik_pemohon . '_' . 
                      $pemohon_clean . '_' . 
                      $kepala_keluarga_clean . '_' . 
                      $timestamp;
        
        // Batasi panjang folder name
        if (strlen($nama_folder) > 200) {
            $folder_hash = substr(md5($nama_folder), 0, 8);
            $nama_folder = $kecamatan_clean . '_' . 
                          $desa_clean . '_' . 
                          $nik_pemohon . '_' . 
                          $pemohon_clean . '_' . 
                          $folder_hash . '_' . 
                          $timestamp;
        }
        
        $folder_path = __DIR__ . '/uploads/kua_kk/' . $nama_folder . '/';
        mkdir($folder_path, 0755, true);
        
        // Tambahkan .htaccess untuk keamanan
        $htaccessFile = $folder_path . '/.htaccess';
        file_put_contents($htaccessFile, 
            "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png)$\">\nAllow from all\n</Files>");
    }
    
    // Proses dokumen KK Lama Suami
    if (!empty($_FILES['dokumen_kk_suami']['name'])) {
        $kk_suami_file = $_FILES['dokumen_kk_suami'];
        $ext = strtolower(pathinfo($kk_suami_file['name'], PATHINFO_EXTENSION));
        $fileType = mime_content_type($kk_suami_file['tmp_name']);
        $fileSize = $kk_suami_file['size'];
        
        // Validasi tipe file
        if (!in_array($ext, ['jpg', 'jpeg', 'jfif', 'jpe', 'png']) || !in_array($fileType, $allowedTypes)) {
            $errors[] = "File KK Lama Suami: Hanya format JPG atau PNG yang diperbolehkan";
        }
        
        // Validasi ukuran file
        if ($fileSize > $maxFileSize) {
            $errors[] = "File KK Lama Suami: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
        }
        
        if (empty($errors)) {
            // Generate unique filename
            $timestamp_file = time();
            $randomString = bin2hex(random_bytes(6));
            $newName = 'kk_lama_suami_' . $timestamp_file . '_' . $randomString . '.' . $ext;
            $destination = $folder_path . $newName;
            
            if (move_uploaded_file($kk_suami_file['tmp_name'], $destination)) {
                // Hapus file KK Lama Suami lama jika ada
                foreach ($dokumen_data as $key => $doc) {
                    if ($doc['type'] === 'kk_lama_suami') {
                        $old_path = __DIR__ . '/' . $doc['path'];
                        if (file_exists($old_path) && $old_path !== $destination) {
                            @unlink($old_path);
                        }
                        unset($dokumen_data[$key]);
                        break;
                    }
                }
                
                $dokumen_baru[] = [
                    'path' => 'uploads/kua_kk/' . $nama_folder . '/' . $newName,
                    'type' => 'kk_lama_suami',
                    'original_name' => $kk_suami_file['name'],
                    'uploaded_name' => $newName,
                    'size' => $fileSize,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];
                $uploadedDocTypes[] = 'kk_lama_suami';
            } else {
                $errors[] = "File KK Lama Suami: Gagal menyimpan file";
            }
        }
    } else {
        // Gunakan file KK Lama Suami lama
        foreach ($dokumen_data as $doc) {
            if ($doc['type'] === 'kk_lama_suami') {
                $dokumen_baru[] = $doc;
                $uploadedDocTypes[] = 'kk_lama_suami';
                break;
            }
        }
    }
    
    // Proses dokumen KK Lama Istri
    if (!empty($_FILES['dokumen_kk_istri']['name'])) {
        $kk_istri_file = $_FILES['dokumen_kk_istri'];
        $ext = strtolower(pathinfo($kk_istri_file['name'], PATHINFO_EXTENSION));
        $fileType = mime_content_type($kk_istri_file['tmp_name']);
        $fileSize = $kk_istri_file['size'];
        
        // Validasi tipe file
        if (!in_array($ext, ['jpg', 'jpeg', 'jfif', 'jpe', 'png']) || !in_array($fileType, $allowedTypes)) {
            $errors[] = "File KK Lama Istri: Hanya format JPG atau PNG yang diperbolehkan";
        }
        
        // Validasi ukuran file
        if ($fileSize > $maxFileSize) {
            $errors[] = "File KK Lama Istri: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
        }
        
        if (empty($errors)) {
            // Generate unique filename
            $timestamp_file = time();
            $randomString = bin2hex(random_bytes(6));
            $newName = 'kk_lama_istri_' . $timestamp_file . '_' . $randomString . '.' . $ext;
            $destination = $folder_path . $newName;
            
            if (move_uploaded_file($kk_istri_file['tmp_name'], $destination)) {
                // Hapus file KK Lama Istri lama jika ada
                foreach ($dokumen_data as $key => $doc) {
                    if ($doc['type'] === 'kk_lama_istri') {
                        $old_path = __DIR__ . '/' . $doc['path'];
                        if (file_exists($old_path) && $old_path !== $destination) {
                            @unlink($old_path);
                        }
                        unset($dokumen_data[$key]);
                        break;
                    }
                }
                
                $dokumen_baru[] = [
                    'path' => 'uploads/kua_kk/' . $nama_folder . '/' . $newName,
                    'type' => 'kk_lama_istri',
                    'original_name' => $kk_istri_file['name'],
                    'uploaded_name' => $newName,
                    'size' => $fileSize,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];
                $uploadedDocTypes[] = 'kk_lama_istri';
            } else {
                $errors[] = "File KK Lama Istri: Gagal menyimpan file";
            }
        }
    } else {
        // Gunakan file KK Lama Istri lama
        foreach ($dokumen_data as $doc) {
            if ($doc['type'] === 'kk_lama_istri') {
                $dokumen_baru[] = $doc;
                $uploadedDocTypes[] = 'kk_lama_istri';
                break;
            }
        }
    }
    
    // Proses dokumen tambahan
    if (isset($_FILES['dokumen_tambahan']) && is_array($_FILES['dokumen_tambahan']['name'])) {
        $fileCount = count($_FILES['dokumen_tambahan']['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen_tambahan']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen_tambahan']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen_tambahan']['name'][$i]);
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileType = mime_content_type($fileTmp);
                $fileSize = $_FILES['dokumen_tambahan']['size'][$i];
                $docType = $_POST['dokumen_type'][$i] ?? 'lainnya';
                
                // Validasi tipe file
                if (!in_array($ext, ['jpg', 'jpeg', 'jfif', 'jpe', 'png']) || !in_array($fileType, $allowedTypes)) {
                    $errors[] = "File {$fileName}: Hanya format JPG atau PNG yang diperbolehkan";
                    continue;
                }
                
                // Validasi ukuran file
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
                        'path' => 'uploads/kua_kk/' . $nama_folder . '/' . $newName,
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
    
    // Tambahkan dokumen lama yang dipilih untuk dipertahankan
    if (isset($_POST['existing_docs']) && is_array($_POST['existing_docs'])) {
        foreach ($_POST['existing_docs'] as $doc_index) {
            if (isset($dokumen_data[$doc_index])) {
                $doc = $dokumen_data[$doc_index];
                // Skip KK Lama Suami dan Istri karena sudah diproses di atas
                if ($doc['type'] !== 'kk_lama_suami' && $doc['type'] !== 'kk_lama_istri') {
                    $dokumen_baru[] = $doc;
                    $uploadedDocTypes[] = $doc['type'];
                }
            }
        }
    }
    
    // Cek dokumen wajib
    $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            if (isset($docNames[$doc])) {
                $missingNames[] = $docNames[$doc];
            }
        }
        
        if (!empty($missingNames)) {
            $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
        }
    }
    
    // Jika tidak ada error, update data
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        // Ambil nama desa
        $nama_desa = '';
        if (!empty($id_desa)) {
            $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
            $qd->bind_param("i", $id_desa);
            $qd->execute();
            $rd = $qd->get_result()->fetch_assoc();
            if ($rd) $nama_desa = $rd['nama_desa'];
        }
        
        // Konversi data ke JSON
        $anggota_keluarga_json = json_encode($anggota_keluarga_baru);
        $dokumen_json = json_encode($dokumen_baru);
        
        // Update data permohonan
        $stmt = $koneksi->prepare("UPDATE tb_kartukeluarga_kua SET 
            id_desa = ?,
            dusun = ?,
            alamat_catin = ?,
            nama_kepalakeluarga = ?,
            no_kk_lama_suami = ?,
            no_kk_lama_istri = ?,
            anggotakeluarga = ?,
            keterangan = ?,
            dokumen = ?,
            nama_desa = ?,
            nama_folder = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_kua = ?");
        
        $stmt->bind_param('issssssssssii', 
            $id_desa,
            $dusun,
            $alamat_catin,
            $nama_kepalakeluarga,
            $no_kk_lama_suami,
            $no_kk_lama_istri,
            $anggota_keluarga_json,
            $keterangan,
            $dokumen_json,
            $nama_desa,
            $nama_folder,
            $permohonan_id,
            $id_kua
        );
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                try {
                    buatNotifikasiOperatorUnit($koneksi, 'tb_kartukeluarga_kua', (int)$permohonan_id, 'Pengajuan Diperbaiki', 'Permohonan telah diperbaiki dan diajukan kembali.');
                } catch (Throwable $e) {
                    error_log('Notifikasi operator KUA gagal: ' . $e->getMessage());
                }
            }
            $_SESSION['success_message'] = "Permohonan berhasil diperbarui dan diajukan kembali!";
            header('Location: kua_status_permohonan.php');
            exit;
        } else {
            $errors[] = "Gagal memperbarui data: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Ambil daftar desa berdasarkan kecamatan
$desa_options = [];
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

// Fungsi helper
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

function selected($value, $compare) {
    return $value == $compare ? 'selected' : '';
}

function checked($value, $compare) {
    return $value == $compare ? 'checked' : '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= esc($csrf_token_value) ?>">
    <title>Edit Permohonan KK - KUA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../assets/PASUTRI.png" type="image/png">

    
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-kua">

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan KUA</div>
        <nav>
            <ul>
                <li><a href="kua_dashboard_user.php">Dashboard</a></li>
                <li><a href="kua_ajukan_permohonan.php">Ajukan permohonan</a></li>
                <li><a href="kua_status_permohonan.php">Status Permohonan</a></li>                
                <li><a href="kua_formulir.php">Formulir Kependudukan</a></li>
                <li><a href="kua_profil.php">Profil Saya</a></li>
                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Edit Permohonan Kartu Keluarga - KUA</div>
                <div class="subtitle">KUA <?= esc($_SESSION['nama_kua'] ?? '') ?></div>
            </div>
            <div class="user-info">
                <span>Hai, <?= esc($_SESSION['nama']) ?></span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- KUA Info Banner -->
            <div class="kua-banner">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    Edit Permohonan KK - KUA <?= esc($_SESSION['nama_kua'] ?? '') ?>
                </h2>
                <p>Perbaiki data permohonan yang ditolak dan ajukan kembali</p>
                <?php if (!empty($nama_folder)): ?>
                <p style="font-size: 12px; opacity: 0.8;">
                    <i class="fas fa-folder"></i> Folder dokumen: <?= esc($nama_folder) ?>
                </p>
                <?php endif; ?>
            </div>

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
                    <h2>Edit Permohonan KK</h2>
                    <span class="status-badge">Status: <?= esc(ucfirst(strtolower((string)($permohonan['status'] ?? '-')))) ?></span>
                </div>

                <form method="POST" enctype="multipart/form-data" id="editKKForm">
                    <input type="hidden" name="csrf_token" value="<?= esc($csrf_token_value) ?>">
                    <!-- Data Pemohon - TIDAK BISA DIUBAH -->
                    <div class="form-section">
                        <h3 class="section-title">Data Pemohon</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Pemohon</label>
                                <input type="text" class="form-control readonly" 
                                       value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Pemohon</label>
                                <input type="text" class="form-control readonly" 
                                       value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>No HP</label>
                                <input type="tel" class="form-control readonly" 
                                       value="<?= esc($permohonan['no_hp'] ?? '') ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" class="form-control readonly" 
                                       value="<?= esc($permohonan['email'] ?? '') ?>" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Data Domisili - KECAMATAN TIDAK BISA DIUBAH -->
                    <div class="form-section">
                        <h3 class="section-title">Data Domisili</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Kecamatan</label>
                                <input type="text" class="form-control readonly" 
                                       value="<?= esc($permohonan['nama_kecamatan'] ?? '') ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Desa <span class="required">*</span></label>
                                <select name="id_desa" id="desa-select" class="form-control" required>
                                    <option value="">Pilih Desa</option>
                                    <?php foreach ($desa_options as $desa): ?>
                                        <option value="<?= $desa['id'] ?>" 
                                            <?= selected($desa['id'], $permohonan['id_desa'] ?? '') ?>>
                                            <?= esc($desa['nama_desa']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Dusun/Alamat <span class="required">*</span></label>
                                <input type="text" name="dusun" class="form-control" 
                                       value="<?= esc($permohonan['dusun'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Status Alamat Calon Pengantin <span class="required">*</span></label>
                                <div class="radio-group">
                                    <label class="radio-item">
                                        <input type="radio" name="alamat_catin" value="alamat sama" 
                                            <?= checked('alamat sama', $permohonan['alamat_catin'] ?? '') ?> required>
                                        Alamat Sama
                                    </label>
                                    <label class="radio-item">
                                        <input type="radio" name="alamat_catin" value="alamat beda" 
                                            <?= checked('alamat beda', $permohonan['alamat_catin'] ?? '') ?>>
                                        Alamat Beda
                                    </label>
                                </div>
                                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                    Pilih apakah alamat calon pengantin sama atau berbeda
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Data KK -->
                    <div class="form-section">
                        <h3 class="section-title">Data Kartu Keluarga</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Kepala Keluarga <span class="required">*</span></label>
                                <input type="text" name="nama_kepalakeluarga" class="form-control" 
                                       value="<?= esc($permohonan['nama_kepalakeluarga'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>No KK Lama Suami <span class="required">*</span></label>
                                <input type="text" name="no_kk_lama_suami" class="form-control" 
                                       value="<?= esc($permohonan['no_kk_lama_suami'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>No KK Lama Istri <span class="required">*</span></label>
                                <input type="text" name="no_kk_lama_istri" class="form-control" 
                                       value="<?= esc($permohonan['no_kk_lama_istri'] ?? '') ?>" required>
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
                                                   placeholder="Contoh: Suami, Istri, Anak">
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
                                               placeholder="NIK">
                                    </td>
                                    <td>
                                        <input type="text" name="anggota_hubungan[]" 
                                               placeholder="Contoh: Suami, Istri, Anak">
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
                                <li>Dokumen wajib: <strong>KK Lama Suami, KK Lama Istri, KTP Suami, KTP Istri, F-1.01 Halaman 1, F-1.01 Halaman 2, Buku Nikah</strong></li>
                                <li>Jika <strong>Alamat Beda</strong>, wajib tambah <strong>Formulir F-1.03 Pindah</strong></li>
                                <li>Format file yang diperbolehkan: JPG, JPEG, PNG</li>
                                <li>Ukuran maksimal per file: <strong>1MB</strong></li>
                                <li>Dokumen akan disimpan di folder yang sama dengan pengajuan pertama</li>
                            </ul>
                        </div>
                        
                        <!-- Dokumen Wajib: KK Lama Suami -->
                        <div class="form-group">
                            <label>KK Lama Suami <span class="required">*</span></label>
                            <div class="file-upload">
                                <label class="file-upload-label">
                                    <i class="fas fa-upload"></i> Unggah KK Lama Suami
                                    <input type="file" name="dokumen_kk_suami" id="dokumen_kk_suami" accept=".jpg,.jpeg,.png" onchange="previewFile(this, 'kk-suami-preview')">
                                </label>
                                <div class="file-preview" id="kk-suami-preview">
                                    <?php 
                                    $kk_suami_file = null;
                                    foreach ($dokumen_data as $doc) {
                                        if ($doc['type'] === 'kk_lama_suami') {
                                            $kk_suami_file = $doc;
                                            break;
                                        }
                                    }
                                    ?>
                                    <?php if ($kk_suami_file): ?>
                                    <div class="document-item">
                                        <div class="doc-info">
                                            <span class="doc-type-badge badge-kk_lama_suami">KK Lama Suami</span>
                                            <span class="doc-name"><?= esc($kk_suami_file['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <?php if (file_exists(__DIR__ . '/' . $kk_suami_file['path'])): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$kk_suami_file['path']) ?? '') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="file-name">Belum ada file KK Lama Suami</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dokumen Wajib: KK Lama Istri -->
                        <div class="form-group">
                            <label>KK Lama Istri <span class="required">*</span></label>
                            <div class="file-upload">
                                <label class="file-upload-label">
                                    <i class="fas fa-upload"></i> Unggah KK Lama Istri
                                    <input type="file" name="dokumen_kk_istri" id="dokumen_kk_istri" accept=".jpg,.jpeg,.png" onchange="previewFile(this, 'kk-istri-preview')">
                                </label>
                                <div class="file-preview" id="kk-istri-preview">
                                    <?php 
                                    $kk_istri_file = null;
                                    foreach ($dokumen_data as $doc) {
                                        if ($doc['type'] === 'kk_lama_istri') {
                                            $kk_istri_file = $doc;
                                            break;
                                        }
                                    }
                                    ?>
                                    <?php if ($kk_istri_file): ?>
                                    <div class="document-item">
                                        <div class="doc-info">
                                            <span class="doc-type-badge badge-kk_lama_istri">KK Lama Istri</span>
                                            <span class="doc-name"><?= esc($kk_istri_file['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <?php if (file_exists(__DIR__ . '/' . $kk_istri_file['path'])): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$kk_istri_file['path']) ?? '') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="file-name">Belum ada file KK Lama Istri</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dokumen yang sudah ada (selain KK) -->
                        <?php if (!empty($dokumen_data)): ?>
                        <div class="document-list" id="existing-documents">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Dokumen Lain yang Sudah Ada:</h4>
                            <?php foreach ($dokumen_data as $index => $doc): ?>
                                <?php if ($doc['type'] !== 'kk_lama_suami' && $doc['type'] !== 'kk_lama_istri'): ?>
                                <div class="document-item" data-index="<?= $index ?>" data-doc-type="<?= esc($doc['type']) ?>">
                                    <div class="doc-info">
                                        <?php 
                                        $badge_class = 'badge-' . str_replace('-', '_', $doc['type']);
                                        $type_labels = [
                                            'kk_lama_suami' => 'KK Lama Suami',
                                            'kk_lama_istri' => 'KK Lama Istri',
                                            'ktp_suami' => 'KTP Suami',
                                            'ktp_istri' => 'KTP Istri',
                                            'formulir_f101_halaman_1' => 'Formulir F-1.01 Halaman 1',
                                            'formulir_f101_halaman_2' => 'Formulir F-1.01 Halaman 2',
                                            'formulir_f101' => 'Formulir F-1.01 (Format Lama)',
                                            'buku_nikah' => 'Buku Nikah',
                                            'formulir_f103' => 'Formulir F-1.03',
                                            'lainnya' => 'Lainnya'
                                        ];
                                        $type_label = $type_labels[$doc['type']] ?? $doc['type'];
                                        ?>
                                        <span class="doc-type-badge <?= $badge_class ?>"><?= $type_label ?></span>
                                        <span class="doc-name"><?= esc($doc['original_name']) ?></span>
                                        <small style="color: #666; display: block; margin-top: 3px;">
                                            Ukuran: <?= formatFileSize($doc['size'] ?? 0) ?>
                                        </small>
                                    </div>
                                    <div class="doc-actions">
                                        <input type="checkbox" name="existing_docs[]" value="<?= $index ?>" checked style="margin-right: 10px;">
                                        <label style="font-size: 12px; color: #666;">Pertahankan</label>
                                        <?php if (file_exists(__DIR__ . '/' . $doc['path'])): ?>
                                        <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$doc['path']) ?? '') ?>')">
                                            <i class="fas fa-eye"></i> Lihat
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Dokumen Tambahan -->
                        <div id="dokumen-tambahan-container">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Tambah Dokumen Lain:</h4>
                            <div id="dokumen-tambahan-list">
                                <!-- Dokumen tambahan akan ditambahkan di sini -->
                            </div>
                            <button type="button" class="btn-add" onclick="addDokumenTambahan()">
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
                                      placeholder="Tambahkan keterangan jika diperlukan" maxlength="500"><?= esc($permohonan['keterangan'] ?? '') ?></textarea>
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
                        <a href="kua_status_permohonan.php" class="btn-cancel">Batal</a>
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
    const csrfToken = (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '').trim();

    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
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
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', function() {
        window.location.href = '../logout_pasutri_ahlak.php';
    });
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) hideLogoutModal();
        });
    }
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
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
            <input type="text" name="anggota_nik[]" placeholder="NIK">
        </td>
        <td>
            <input type="text" name="anggota_hubungan[]" placeholder="Contoh: Suami, Istri, Anak">
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

// Dokumen Tambahan Functions
let dokumenCounter = 0;

function addDokumenTambahan() {
    const container = document.getElementById('dokumen-tambahan-list');
    const newItem = document.createElement('div');
    newItem.className = 'dokumen-tambahan-item';
    newItem.style.cssText = 'background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 15px;';
    newItem.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h4 style="margin: 0; font-size: 16px; color: #374151;">Dokumen Tambahan #${dokumenCounter + 1}</h4>
            <button type="button" style="background: #ef4444; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 14px;" onclick="removeDokumenTambahan(this)">
                <i class="fas fa-times"></i> Hapus
            </button>
        </div>
        <select name="dokumen_type[]" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; margin-bottom: 10px; font-size: 14px;" required>
            <option value="">Pilih Jenis Dokumen</option>
            <option value="ktp_suami">KTP Suami</option>
            <option value="ktp_istri">KTP Istri</option>
            <option value="formulir_f101_halaman_1">Formulir F-1.01 Halaman 1</option>
            <option value="formulir_f101_halaman_2">Formulir F-1.01 Halaman 2</option>
            <option value="buku_nikah">Buku Nikah</option>
            <option value="formulir_f103">Formulir F-1.03</option>
            <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div style="border: 2px dashed #d1d5db; border-radius: 8px; padding: 15px; text-align: center; background: white;">
            <label style="display: inline-block; background: #3b82f6; color: white; padding: 8px 16px; border-radius: 6px; cursor: pointer; transition: background 0.3s;">
                <i class="fas fa-upload"></i> Unggah File
                <input type="file" name="dokumen_tambahan[]" accept=".jpg,.jpeg,.png" style="display: none;" onchange="previewDokumenTambahan(this, ${dokumenCounter})" required>
            </label>
            <div class="file-preview" id="dokumen-preview-${dokumenCounter}" style="margin-top: 10px;">
                <span class="file-name">Belum ada file dipilih</span>
            </div>
        </div>
    `;
    
    container.appendChild(newItem);
    dokumenCounter++;
}

function removeDokumenTambahan(button) {
    const item = button.closest('.dokumen-tambahan-item');
    item.remove();
}

function previewDokumenTambahan(input, index) {
    if (input.files.length > 0) {
        const file = input.files[0];
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
        const previewElement = document.getElementById(`dokumen-preview-${index}`);
        
        if (file.size > 1 * 1024 * 1024) {
            alert(`File ${file.name} terlalu besar (maksimal 1MB)`);
            input.value = '';
            previewElement.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
            return;
        }
        
        previewElement.innerHTML = `
            <span class="file-name">${file.name}</span><br>
            <small>Ukuran: ${fileSizeMB} MB</small>
        `;
    }
}

// Preview file upload
function previewFile(input, previewId) {
    const previewElement = document.getElementById(previewId);
    
    if (input.files.length > 0) {
        const file = input.files[0];
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
        
        if (file.size > 1 * 1024 * 1024) {
            alert(`File ${file.name} terlalu besar (maksimal 1MB)`);
            input.value = '';
            return;
        }
        
        const docType = input.id.includes('suami') ? 'KK Lama Suami' : 'KK Lama Istri';
        const badgeClass = input.id.includes('suami') ? 'badge-kk_lama_suami' : 'badge-kk_lama_istri';
        
        previewElement.innerHTML = `
            <div class="document-item">
                <div class="doc-info">
                    <span class="doc-type-badge ${badgeClass}">${docType} (Baru)</span>
                    <span class="doc-name">${file.name}</span>
                </div>
                <div class="doc-actions">
                    <span style="font-size: 12px; color: #666;">${fileSizeMB} MB</span>
                </div>
            </div>
        `;
    }
}

// Preview dokumen yang sudah ada
function previewDocument(path) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    // Cek ekstensi file
    const extension = path.split('.').pop().toLowerCase();
    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    const pdfExtensions = ['pdf'];
    
    if (imageExtensions.includes(extension)) {
        content.innerHTML = `<img src="${path}" style="max-width: 100%; max-height: 70vh; display: block; margin: 0 auto;">`;
    } else if (pdfExtensions.includes(extension)) {
        content.innerHTML = `
            <iframe src="${path}" style="width: 80vw; height: 80vh; border: none;"></iframe>
            <p style="text-align: center; margin-top: 10px;">
                <a href="${path}" target="_blank" style="color: #3b82f6;">Buka di tab baru</a>
            </p>
        `;
    } else {
        content.innerHTML = `<p>Tidak dapat menampilkan preview untuk file ini.</p>`;
    }
    
    modal.style.display = 'flex';
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewContent').innerHTML = '';
}

// Form Validation
document.getElementById('editKKForm').addEventListener('submit', function(e) {
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

    let isValid = true;
    let errorMessages = [];
    
    // Validate required fields
    const requiredFields = this.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.style.borderColor = '#dc2626';
            isValid = false;
        } else {
            field.style.borderColor = '#d1d5db';
        }
    });
    
    // Validate radio buttons
    const alamatCatin = document.querySelector('input[name="alamat_catin"]:checked');
    if (!alamatCatin) {
        errorMessages.push('Status alamat calon pengantin harus dipilih');
        isValid = false;
    }
    
    // Validate anggota keluarga
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
    
    // Validate KK Lama Suami and Istri
    const hasKKSuami = <?= !empty($kk_suami_file) ? 'true' : 'false' ?>;
    const hasKKIstri = <?= !empty($kk_istri_file) ? 'true' : 'false' ?>;
    const kkSuamiInput = document.getElementById('dokumen_kk_suami');
    const kkIstriInput = document.getElementById('dokumen_kk_istri');
    
    if (!hasKKSuami && kkSuamiInput.files.length === 0) {
        errorMessages.push('Dokumen KK Lama Suami wajib diunggah!');
        isValid = false;
    }
    
    if (!hasKKIstri && kkIstriInput.files.length === 0) {
        errorMessages.push('Dokumen KK Lama Istri wajib diunggah!');
        isValid = false;
    }
    
    // Validate file sizes (1MB max)
    const fileInputs = this.querySelectorAll('input[type="file"]');
    for (let input of fileInputs) {
        if (input.files.length > 0) {
            const file = input.files[0];
            if (file.size > 1 * 1024 * 1024) {
                errorMessages.push(`File ${file.name} terlalu besar (maksimal 1MB)`);
                isValid = false;
                break;
            }
        }
    }
    
    // Check dokumen wajib berdasarkan alamat
    const selectedAlamat = document.querySelector('input[name="alamat_catin"]:checked');
    const selectedDocTypes = new Set();

    // Dokumen KK (special field)
    if (hasKKSuami || kkSuamiInput.files.length > 0) selectedDocTypes.add('kk_lama_suami');
    if (hasKKIstri || kkIstriInput.files.length > 0) selectedDocTypes.add('kk_lama_istri');

    // Dokumen existing yang dipertahankan
    document.querySelectorAll('input[name="existing_docs[]"]:checked').forEach(input => {
        const docItem = input.closest('.document-item');
        const docType = docItem ? (docItem.dataset.docType || '').trim() : '';
        if (docType) selectedDocTypes.add(docType);
    });

    // Dokumen tambahan baru
    const typeSelects = document.querySelectorAll('select[name="dokumen_type[]"]');
    const tambahanInputs = document.querySelectorAll('input[name="dokumen_tambahan[]"]');
    typeSelects.forEach((select, idx) => {
        const selectedType = (select.value || '').trim();
        const hasFile = tambahanInputs[idx] && tambahanInputs[idx].files.length > 0;
        if (selectedType && hasFile) selectedDocTypes.add(selectedType);
    });

    const requiredDocs = [
        'kk_lama_suami',
        'kk_lama_istri',
        'ktp_suami',
        'ktp_istri',
        'formulir_f101_halaman_1',
        'formulir_f101_halaman_2',
        'buku_nikah'
    ];

    if (selectedAlamat && selectedAlamat.value === 'alamat beda') {
        requiredDocs.push('formulir_f103');
    }

    const requiredDocLabels = {
        kk_lama_suami: 'KK Lama Suami',
        kk_lama_istri: 'KK Lama Istri',
        ktp_suami: 'KTP Suami',
        ktp_istri: 'KTP Istri',
        formulir_f101_halaman_1: 'Formulir F-1.01 Halaman 1',
        formulir_f101_halaman_2: 'Formulir F-1.01 Halaman 2',
        buku_nikah: 'Buku Nikah',
        formulir_f103: 'Formulir F-1.03 Pindah'
    };

    const missingDocs = requiredDocs.filter(type => !selectedDocTypes.has(type));
    if (missingDocs.length > 0) {
        errorMessages.push('Dokumen wajib belum lengkap: ' + missingDocs.map(type => requiredDocLabels[type] || type).join(', '));
        isValid = false;
    }
    
    if (!isValid) {
        e.preventDefault();
        let message = 'Harap perbaiki kesalahan berikut:\n\n';
        if (errorMessages.length > 0) {
            message += errorMessages.join('\n');
        } else {
            message += 'Harap lengkapi semua bidang yang wajib diisi';
        }
        alert(message);
    } else {
        // Konfirmasi sebelum submit
        const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan kembali?');
        if (!confirmSubmit) {
            e.preventDefault();
        }
    }
});
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
</body>
</html>
