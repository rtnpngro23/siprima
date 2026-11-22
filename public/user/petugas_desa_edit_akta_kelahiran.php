<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/petugas_desa_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';
require_once __DIR__ . '/../functions/akta_kelahiran_kategori.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kelahiran LIKE 'resubmitted_at'");
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
    nama_ayah,
    nik_ayah,
    nama_ibu,
    nik_ibu,
    nama_saksi1,
    nik_saksi1,
    nama_saksi2,
    nik_saksi2,
    nama_bayi,
    tempat_lahir,
    tanggal_lahir,
    Waktu,
    urutan_kelahiran,
    jenis_kelamin,
    keterangan,
    dokumen, 
    status,
    alasan,
    nama_folder,
    jenis_permohonan,
    nik_subjek,
    nomor_akta_lama
    FROM tb_akta_kelahiran
    WHERE id = ? AND id_user = ?");
if (!$stmt) {
    error_log('Gagal menyiapkan query data permohonan edit akta kelahiran: ' . $koneksi->error);
    header('Location: petugas_desa_status_permohonan.php?error=system');
    exit;
}
$stmt->bind_param('ii', $permohonan_id, $user_id);
$executed = $stmt->execute();
if (!$executed) {
    error_log('Gagal menjalankan query data permohonan edit akta kelahiran: ' . $stmt->error);
    $stmt->close();
    header('Location: petugas_desa_status_permohonan.php?error=system');
    exit;
}
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

// Kategori permohonan tidak bisa diubah saat mengajukan ulang: dibaca dari record, bukan input.
$jenis_permohonan = siprima_akta_kelahiran_jenis_normalize($permohonan['jenis_permohonan'] ?? null);
if ($jenis_permohonan === 'hilang') {
    header('Location: petugas_desa_edit_akta_kelahiran_hilang.php?id=' . (int)$permohonan_id);
    exit;
}
$butuh_nik_subjek = $jenis_permohonan !== AKTA_KELAHIRAN_JENIS_DEFAULT;
$is_pembaruan = $jenis_permohonan === 'pembaruan';
$label_nama_subjek = $butuh_nik_subjek ? 'Nama yang Bersangkutan' : 'Nama Bayi';
$label_blok_data = $butuh_nik_subjek ? 'Data yang Bersangkutan' : 'Data Bayi';
// Pembaruan akta memakai akta lahir lama sebagai pengganti surat keterangan lahir.
$doc_bukti_lahir = $is_pembaruan ? 'akta_lahir_lama' : 'surat_keterangan_lahir';
$label_bukti_lahir = $is_pembaruan
    ? 'Akta Lahir Lama'
    : 'Surat Keterangan Kelahiran dari Puskes/RS/Desa/Kelurahan';

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

$user_data = $user_query ? $user_query->fetch_assoc() : [];
if (!$user_query) {
    error_log('Gagal mengambil data wilayah petugas desa: ' . $koneksi->error);
}

// Set nilai kecamatan dan desa dari data user, fallback ke data permohonan jika profil user kosong
$user_kecamatan_id = (int)($user_data['id_kecamatan'] ?? 0);
$user_desa_id = (int)($user_data['id_desa'] ?? 0);
$nama_kecamatan_user = trim((string)($user_data['nama_kecamatan'] ?? ''));
$nama_desa_user = trim((string)($user_data['nama_desa'] ?? ''));

if ($user_kecamatan_id <= 0) {
    $user_kecamatan_id = (int)($permohonan['id_kecamatan'] ?? 0);
}
if ($user_desa_id <= 0) {
    $user_desa_id = (int)($permohonan['id_desa'] ?? 0);
}
if ($nama_kecamatan_user === '') {
    $nama_kecamatan_user = (string)($permohonan['nama_kecamatan'] ?? '');
}
if ($nama_desa_user === '') {
    $nama_desa_user = (string)($permohonan['nama_desa'] ?? '');
}

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
    
    // Data bayi dan orang tua (bisa diubah)
    $nama_bayi = trim($_POST['nama_bayi'] ?? '');
    $tempat_lahir = trim($_POST['tempat_lahir'] ?? '');
    $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '');
    $waktu_lahir = trim($_POST['waktu_lahir'] ?? '');
    $urutan_kelahiran = (int)($_POST['urutan_kelahiran'] ?? 1);
    $jenis_kelamin = trim($_POST['jenis_kelamin'] ?? '');
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
    $nik_subjek = $butuh_nik_subjek ? trim($_POST['nik_subjek'] ?? '') : null;
    $nomor_akta_lama = $is_pembaruan ? trim($_POST['nomor_akta_lama'] ?? '') : null;

    if (empty($nama_bayi)) $errors[] = $label_nama_subjek . " harus diisi";
    if ($butuh_nik_subjek) {
        if ($nik_subjek === '') {
            $errors[] = "NIK yang bersangkutan harus diisi";
        } elseif (!preg_match('/^[0-9]{16}$/', $nik_subjek)) {
            $errors[] = "NIK yang bersangkutan harus 16 digit angka";
        }
    }
    if ($is_pembaruan && $nomor_akta_lama === '') $errors[] = "Nomor akta lahir lama harus diisi";
    if (empty($tempat_lahir)) $errors[] = "Tempat lahir harus diisi";
    if (empty($tanggal_lahir)) $errors[] = "Tanggal lahir harus diisi";
    if (empty($jenis_kelamin)) $errors[] = "Jenis kelamin harus dipilih";
    if (empty($nama_ayah)) $errors[] = "Nama ayah harus diisi";
    if (empty($nik_ayah)) $errors[] = "NIK ayah harus diisi";
    if (empty($nama_ibu)) $errors[] = "Nama ibu harus diisi";
    if (empty($nik_ibu)) $errors[] = "NIK ibu harus diisi";
    if (empty($nama_saksi1)) $errors[] = "Nama saksi 1 harus diisi";
    if (empty($nik_saksi1)) $errors[] = "NIK saksi 1 harus diisi";
    
    
    // Validasi format NIK
    if (!empty($nik_ayah) && !preg_match('/^[0-9]{16}$/', $nik_ayah)) {
        $errors[] = "NIK ayah harus 16 digit angka";
    }
    if (!empty($nik_ibu) && !preg_match('/^[0-9]{16}$/', $nik_ibu)) {
        $errors[] = "NIK ibu harus 16 digit angka";
    }
    if (!empty($nik_saksi1) && !preg_match('/^[0-9]{16}$/', $nik_saksi1)) {
        $errors[] = "NIK saksi 1 harus 16 digit angka";
    }
    
    
    // Validasi format tanggal
    if (!empty($tanggal_lahir)) {
        $date = DateTime::createFromFormat('Y-m-d', $tanggal_lahir);
        if (!$date || $date->format('Y-m-d') !== $tanggal_lahir) {
            $errors[] = "Format tanggal lahir tidak valid. Gunakan format: YYYY-MM-DD";
        }
    }
    
    // ================================================
    // BAGIAN PERBAIKAN: GUNAKAN FOLDER YANG SAMA
    // ================================================
    
    // Ambil nama folder dari database (dari permohonan pertama)
    $folder_name = $permohonan['nama_folder'] ?? '';
    
    // Path folder uploads
    $base_upload_dir = __DIR__ . '/uploads/akta_kelahiran/';
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
                $doc['type'] = normalizeAktaDocType($doc['type'] ?? 'lainnya');
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
                $docType = normalizeAktaDocType(isset($doc_types[$i]) ? $doc_types[$i] : 'lainnya');
                
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
                        'path' => 'uploads/akta_kelahiran/' . $folder_name . '/' . $newName,
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
    
    // 3. Validasi dokumen wajib untuk akta kelahiran
    $requiredDocs = ['formulir_kelahiran', $doc_bukti_lahir, 'buku_nikah'];
    $docTypesInNewData = array_map('normalizeAktaDocType', array_column($dokumen_baru, 'type'));
    
    $missingRequired = array_diff($requiredDocs, $docTypesInNewData);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            switch ($doc) {
                case 'formulir_kelahiran':
                    $missingNames[] = 'Formulir F-2.01 Kelahiran';
                    break;
                case 'akta_lahir_lama':
                    $missingNames[] = 'Akta Lahir Lama';
                    break;
                case 'surat_keterangan_lahir':
                    $missingNames[] = 'Surat Keterangan Kelahiran dari Puskes/RS/Desa/Kelurahan';
                    break;
                case 'buku_nikah':
                    $missingNames[] = 'Buku Nikah/Akta kawin/SPTJM Perkawinan';
                    break;
            }
        }
        $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
    }

    $hasKkOrSuketKk = in_array('kk', $docTypesInNewData, true) || in_array('suket_kehilangan_kk', $docTypesInNewData, true);
    if (!$hasKkOrSuketKk) {
        $errors[] = 'Dokumen wajib belum diupload: Kartu Keluarga (KK) atau Suket Kehilangan KK';
    }
    
    // Jika tidak ada error, update data
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        // Update data permohonan
        $stmt = $koneksi->prepare("UPDATE tb_akta_kelahiran SET 
            nama_pemohon = ?,
            nik_pemohon = ?,
            no_hp = ?,
            email = ?,
            id_kecamatan = ?,
            id_desa = ?,
            nama_kecamatan = ?,
            nama_desa = ?,
            nama_ayah = ?,
            nik_ayah = ?,
            nama_ibu = ?,
            nik_ibu = ?,
            nama_saksi1 = ?,
            nik_saksi1 = ?,
            nama_saksi2 = ?,
            nik_saksi2 = ?,
            nama_bayi = ?,
            tempat_lahir = ?,
            tanggal_lahir = ?,
            Waktu = ?,
            urutan_kelahiran = ?,
            jenis_kelamin = ?,
            keterangan = ?,
            dokumen = ?,
            nik_subjek = ?,
            nomor_akta_lama = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?");
        if (!$stmt) {
            $errors[] = "Gagal menyiapkan query pembaruan data: " . $koneksi->error;
        } else {
        
        $dokumen_json = json_encode($dokumen_baru);
        
        // 26 parameter non-integer dan 2 integer, urut sama dengan daftar SET lalu WHERE.
        $stmt->bind_param('ssssiissssssssssssssisssssii',  
            $nama_pemohon,
            $nik_pemohon,
            $no_hp,
            $email,
            $id_kecamatan,
            $id_desa,
            $nama_kecamatan_db,
            $nama_desa_db,
            $nama_ayah,
            $nik_ayah,
            $nama_ibu,
            $nik_ibu,
            $nama_saksi1,
            $nik_saksi1,
            $nama_saksi2,
            $nik_saksi2,
            $nama_bayi,
            $tempat_lahir,
            $tanggal_lahir,
            $waktu_lahir,
            $urutan_kelahiran,
            $jenis_kelamin,
            $keterangan,
            $dokumen_json,
            $nik_subjek,
            $nomor_akta_lama,
            $permohonan_id,
            $user_id
        );
        
            if ($stmt->execute()) {
                logPermohonanStatusHistory(
                    $koneksi,
                    'tb_akta_kelahiran',
                    $permohonan_id,
                    'diajukan',
                    null,
                    (int)($_SESSION['id_user'] ?? 0),
                    (string)($_SESSION['role'] ?? 'user'),
                    'resubmit'
                );
                $success = true;
                $_SESSION['success_message'] = "Permohonan akta kelahiran berhasil diperbarui dan diajukan kembali!";
                header('Location: petugas_desa_status_permohonan.php');
                exit;
            } else {
                $errors[] = "Gagal memperbarui data: " . $stmt->error;
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

function normalizeAktaDocType($type) {
    $type = strtolower(trim((string)$type));
    $aliases = [
        'formulir' => 'formulir_kelahiran',
        'formulir_f201' => 'formulir_kelahiran',
        'formulir_f201_kelahiran' => 'formulir_kelahiran',
        'surat_keterangan' => 'surat_keterangan_lahir',
        'surat_keterangan_kelahiran' => 'surat_keterangan_lahir',
        'akta_lama' => 'akta_lahir_lama',
        'buku_nikah_akta_kawin' => 'buku_nikah',
        'suket_kk_hilang' => 'suket_kehilangan_kk',
        'suket_kehilangan_kk' => 'suket_kehilangan_kk',
        'surat_kehilangan_kk' => 'suket_kehilangan_kk'
    ];
    return $aliases[$type] ?? $type;
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Permohonan Akta Kelahiran - Petugas Desa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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
                'Edit Permohonan Akta Kelahiran',
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
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                            <line x1="9" y1="9" x2="9.01" y2="9"></line>
                            <line x1="15" y1="9" x2="15.01" y2="9"></line>
                        </svg>
                    </div>
                    <div>
                        <h2 class="form-title">Edit Permohonan Akta Kelahiran</h2>
                        <p class="helper">Perbaiki data dan ajukan kembali permohonan yang ditolak. Dokumen baru akan disimpan di folder yang sama.</p>
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
                    <p><span class="info-icon"></span> Anda sebagai <strong>Petugas Desa</strong> hanya dapat mengajukan permohonan untuk wilayah:</p>
                    <p><strong>Kecamatan:</strong> <?= esc($nama_kecamatan_user) ?> | <strong>Desa:</strong> <?= esc($nama_desa_user) ?></p><br>
                </div>

                <!-- Alasan Penolakan -->
                <?php if (!empty($permohonan['alasan'])): ?>
                <div class="alasan-box">
                    <div class="alasan-title">Alasan Penolakan Sebelumnya:</div>
                    <?= nl2br(esc($permohonan['alasan'])) ?>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="space-y-4" id="editAktaKelahiranForm">
<?php echo csrf_input(); ?>

                    <!-- Data Pemohon (Dikunci - tidak bisa diedit) -->
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Pemohon <span style="color:red">*</span> <span class="lock-icon"></span></label>
                            <input type="text" value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>" readonly class="input bg-gray-100 locked-field">
                            <input type="hidden" name="nama_pemohon" value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="field-label">NIK Pemohon <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
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

                    <!-- Kecamatan & Desa (Dikunci sesuai user) -->
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Kecamatan <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
                            <select name="id_kecamatan" id="kecamatanSelect" required class="select locked-select" disabled>
                                <option value="<?= $user_kecamatan_id ?>" selected><?= esc($nama_kecamatan_user) ?></option>
                            </select>
                            <input type="hidden" name="id_kecamatan" value="<?= $user_kecamatan_id ?>">
                        </div>

                        <div>
                            <label class="field-label">Desa <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
                            <select name="id_desa" id="desaSelect" required class="select locked-select" disabled>
                                <option value="<?= $user_desa_id ?>" selected><?= esc($nama_desa_user) ?></option>
                            </select>
                            <input type="hidden" name="id_desa" value="<?= $user_desa_id ?>">
                        </div>
                    </div>

                    <!-- Nama Kecamatan & Desa (otomatis terisi) -->
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Kecamatan</label>
                            <input type="text" id="namaKecamatan" readonly class="input bg-gray-100" value="<?= esc($nama_kecamatan_user) ?>">
                        </div>

                        <div>
                            <label class="field-label">Nama Desa</label>
                            <input type="text" id="namaDesa" readonly class="input bg-gray-100" value="<?= esc($nama_desa_user) ?>">
                        </div>
                    </div>

                    <!-- Data Bayi -->
                    <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;"><?= esc($label_blok_data) ?></h3>

                    <?php if ($butuh_nik_subjek): ?>
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">NIK yang Bersangkutan <span style="color:red">*</span></label>
                            <input type="text" name="nik_subjek" class="input"
                                   value="<?= esc($_POST['nik_subjek'] ?? $permohonan['nik_subjek'] ?? '') ?>"
                                   required placeholder="16 digit NIK" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                        </div>
                        <?php if ($is_pembaruan): ?>
                        <div>
                            <label class="field-label">Nomor Akta Lahir Lama <span style="color:red">*</span></label>
                            <input type="text" name="nomor_akta_lama" class="input"
                                   value="<?= esc($_POST['nomor_akta_lama'] ?? $permohonan['nomor_akta_lama'] ?? '') ?>"
                                   required placeholder="Nomor pada akta lahir lama" maxlength="100">
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="form-grid-2">
                        <div>
                            <label class="field-label"><?= esc($label_nama_subjek) ?> <span style="color:red">*</span></label>
                            <input type="text" name="nama_bayi" class="input" 
                                   value="<?= esc($permohonan['nama_bayi'] ?? '') ?>" 
                                   required placeholder="Nama lengkap bayi">
                        </div>

                        <div>
                            <label class="field-label">Jenis Kelamin <span style="color:red">*</span></label>
                            <select name="jenis_kelamin" class="select" required>
                                <option value="">-- Pilih --</option>
                                <option value="L" <?= selected('L', $permohonan['jenis_kelamin'] ?? '') ?>>Laki-laki</option>
                                <option value="P" <?= selected('P', $permohonan['jenis_kelamin'] ?? '') ?>>Perempuan</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Tempat Lahir <span style="color:red">*</span></label>
                            <input type="text" name="tempat_lahir" class="input" 
                                   value="<?= esc($permohonan['tempat_lahir'] ?? '') ?>" 
                                   required placeholder="Kabupaten/Kota lahir bayi">
                        </div>
                        <div>
                            <label class="field-label">Tanggal Lahir <span style="color:red">*</span></label>
                            <input type="date" name="tanggal_lahir" class="input"
                                   value="<?= esc($permohonan['tanggal_lahir'] ?? '') ?>" 
                                   required>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Waktu Lahir</label>
                            <input type="time" name="waktu_lahir" class="input"
                                   value="<?= esc($permohonan['Waktu'] ?? '') ?>">
                            <p class="helper">Format: HH:MM</p>
                        </div>
                        <div>
                            <label class="field-label">Anak ke-</label>
                            <input type="number" name="urutan_kelahiran" min="1" class="input"
                                   value="<?= esc($permohonan['urutan_kelahiran'] ?? 1) ?>" 
                                   placeholder="Contoh: 1">
                        </div>
                    </div>

                    <!-- Data Orang Tua -->
                    <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Orang Tua <span style="color:red">*</span></h3>

                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Ayah <span style="color:red">*</span></label>
                            <input type="text" name="nama_ayah" class="input" 
                                   value="<?= esc($permohonan['nama_ayah'] ?? '') ?>" 
                                   required placeholder="Nama lengkap ayah">
                        </div>
                        <div>
                            <label class="field-label">NIK Ayah <span style="color:red">*</span></label>
                            <input type="text" name="nik_ayah" class="input" 
                                   value="<?= esc($permohonan['nik_ayah'] ?? '') ?>" 
                                   required placeholder="16 digit NIK ayah" 
                                   pattern="[0-9]{16}" maxlength="16"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                                   title="NIK harus 16 digit angka">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Ibu <span style="color:red">*</span></label>
                            <input type="text" name="nama_ibu" class="input" 
                                   value="<?= esc($permohonan['nama_ibu'] ?? '') ?>" 
                                   required placeholder="Nama lengkap ibu">
                        </div>
                        <div>
                            <label class="field-label">NIK Ibu <span style="color:red">*</span></label>
                            <input type="text" name="nik_ibu" class="input" 
                                   value="<?= esc($permohonan['nik_ibu'] ?? '') ?>" 
                                   required placeholder="16 digit NIK ibu" 
                                   pattern="[0-9]{16}" maxlength="16"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                                   title="NIK harus 16 digit angka">
                        </div>
                    </div>

                    <!-- Data Saksi 1 -->
                    <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Saksi 1 <span style="color:red">*</span></h3>

                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Saksi 1 <span style="color:red">*</span></label>
                            <input type="text" name="nama_saksi1" class="input" 
                                   value="<?= esc($permohonan['nama_saksi1'] ?? '') ?>" 
                                   required placeholder="Nama lengkap saksi 1">
                        </div>
                        <div>
                            <label class="field-label">NIK Saksi 1 <span style="color:red">*</span></label>
                            <input type="text" name="nik_saksi1" class="input" 
                                   value="<?= esc($permohonan['nik_saksi1'] ?? '') ?>" 
                                   required placeholder="16 digit NIK saksi 1" 
                                   pattern="[0-9]{16}" maxlength="16"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                                   title="NIK harus 16 digit angka">
                        </div>
                    </div>

                    <!-- Data Saksi 2 -->
                    <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Saksi 2 </h3>

                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Saksi 2</label>
                            <input type="text" name="nama_saksi2" class="input" 
                                   value="<?= esc($permohonan['nama_saksi2'] ?? '') ?>" 
                                    placeholder="Nama lengkap saksi 2">
                        </div>
                        <div>
                            <label class="field-label">NIK Saksi 2 </label>
                            <input type="text" name="nik_saksi2" class="input" 
                                   value="<?= esc($permohonan['nik_saksi2'] ?? '') ?>" 
                                    placeholder="16 digit NIK saksi 2" 
                                   pattern="[0-9]{16}" maxlength="16"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                                   title="NIK harus 16 digit angka">
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
                                $doc_type = normalizeAktaDocType($doc['type'] ?? 'lainnya');
                                $badge_class = 'badge-' . $doc_type;
                                $type_labels = [
                                    'formulir_kelahiran' => 'Formulir F-2.01 Kelahiran',
                                    'surat_keterangan_lahir' => 'Surat Keterangan Kelahiran',
                                    'akta_lahir_lama' => 'Akta Lahir Lama',
                                    'buku_nikah' => 'Buku Nikah/Akta Kawin',
                                    'kk' => 'Kartu Keluarga (KK)',
                                    'suket_kehilangan_kk' => 'Suket Kehilangan KK',
                                    'ktp_ortu' => 'KTP Orang Tua',
                                    'lainnya' => 'Dokumen Lain'
                                ];
                                $type_label = $type_labels[$doc_type] ?? $doc_type;
                                ?>
                                <span class="doc-type-badge <?= $badge_class ?>" data-doc-type="<?= esc($doc_type) ?>"><?= $type_label ?></span>
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
                                <li><strong>Formulir F-2.01 Kelahiran</strong> (diisi lengkap dan ditandatangani)</li>
                                <li><strong>Surat Keterangan Kelahiran dari Puskes/RS/Desa/Kelurahan</strong></li>
                                <li><strong>Buku Nikah/Akta kawin/SPTJM Perkawinan</strong></li>
                            </ul>
                            <p style="margin: 10px 0 0 0; color: #dc2626;"><strong>Jika dokumen sudah ada di atas, tidak perlu diupload ulang.</strong></p>
                        </div>
                        
                        <div id="fileUploadContainer">
                            <!-- File inputs akan ditambahkan secara dinamis -->
                            <div class="file-input-group" id="fileGroup1">
                                <input type="hidden" name="file_index_new[]" value="0">
                                <select name="doc_type_new[]" class="file-type-select">
                                    <option value="">Pilih Jenis Dokumen</option>
                                    <option value="formulir_kelahiran">Formulir F-2.01 Kelahiran</option>
                                    <option value="<?= esc($doc_bukti_lahir) ?>"><?= esc($label_bukti_lahir) ?></option>
                                    <option value="buku_nikah">Buku Nikah/Akta kawin/SPTJM Perkawinan</option>
                                    <option value="kk">Kartu Keluarga (KK)</option>
                                    <option value="suket_kehilangan_kk">Suket Kehilangan KK</option>
                                    <option value="ktp_ortu">KTP Orang Tua</option>
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
                        
                        <p class="helper"><strong>Dokumen wajib:</strong> Formulir F-2.01 Kelahiran, Surat Keterangan Kelahiran, Buku Nikah, serta Kartu Keluarga (KK) atau Suket Kehilangan KK. Format: JPG, JPEG, JFIF, JPE, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong></p>
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
// Dokumen Input Functions
let dokumenCounter = 1;
const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB (batas bisnis)
const SERVER_POST_MAX_BYTES = <?= (int)$serverPostMaxBytes ?>;
const SERVER_UPLOAD_MAX_BYTES = <?= (int)$serverUploadMaxBytes ?>;
const SERVER_POST_BUFFER = 64 * 1024; // antisipasi multipart boundary overhead
const EFFECTIVE_MAX_FILE_SIZE = SERVER_UPLOAD_MAX_BYTES > 0
    ? Math.min(MAX_FILE_SIZE, SERVER_UPLOAD_MAX_BYTES)
    : MAX_FILE_SIZE;
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

function formatFileSizeClient(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' Bytes';
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
            <option value="formulir_kelahiran">Formulir F-2.01 Kelahiran</option>
            <option value="<?= esc($doc_bukti_lahir) ?>"><?= esc($label_bukti_lahir) ?></option>
            <option value="buku_nikah">Buku Nikah/Akta kawin/SPTJM Perkawinan</option>
            <option value="kk">Kartu Keluarga (KK)</option>
            <option value="suket_kehilangan_kk">Suket Kehilangan KK</option>
            <option value="ktp_ortu">KTP Orang Tua</option>
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
    
    // Add file change event
    const fileInput = document.getElementById('fileInput' + dokumenCounter);
    bindFileInputEvents(fileInput);
}

function bindFileInputEvents(fileInput) {
    if (!fileInput) return;
    if (fileInput.dataset.boundChange === '1') return;

    fileInput.addEventListener('change', function() {
        const suffix = (this.id || '').replace('fileInput', '');
        const fileNameSpan = document.getElementById('fileName' + suffix);
        const fileSizeSpan = document.getElementById('fileSize' + suffix);
        if (!fileNameSpan || !fileSizeSpan) return;

        if (this.files.length > 0) {
            const file = this.files[0];
            const fileSizeMB = (file.size / 1024 / 1024);
            fileNameSpan.textContent = file.name + ' (' + fileSizeMB.toFixed(2) + ' MB)';

            // Validasi ukuran file
            if (file.size > EFFECTIVE_MAX_FILE_SIZE) {
                fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${fileSizeMB.toFixed(2)} MB (melebihi batas ${formatFileSizeClient(EFFECTIVE_MAX_FILE_SIZE)})</span>`;
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

    fileInput.dataset.boundChange = '1';
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

// Bind input file awal agar nama file langsung tampil saat dipilih.
bindFileInputEvents(document.getElementById('fileInput1'));

// Form Validation
document.getElementById('editAktaKelahiranForm').addEventListener('submit', function(e) {
    let isValid = true;
    let errorMessages = [];
    let totalUploadSize = 0;
    const normalizeDocType = (type) => {
        const aliases = {
            formulir: 'formulir_kelahiran',
            formulir_f201: 'formulir_kelahiran',
            formulir_f201_kelahiran: 'formulir_kelahiran',
            surat_keterangan: 'surat_keterangan_lahir',
            surat_keterangan_kelahiran: 'surat_keterangan_lahir',
            buku_nikah_akta_kawin: 'buku_nikah',
            suket_kk_hilang: 'suket_kehilangan_kk',
            suket_kehilangan_kk: 'suket_kehilangan_kk',
            surat_kehilangan_kk: 'suket_kehilangan_kk'
        };
        const key = String(type || '').trim().toLowerCase();
        return aliases[key] || key;
    };

    const csrfInput = this.querySelector('input[name="csrf_token"]');
    if (!csrfInput || !csrfInput.value.trim()) {
        errorMessages.push('Token keamanan tidak ditemukan. Muat ulang halaman lalu coba lagi.');
        isValid = false;
    }
    
    // Validate NIK harus 16 digit
    const nikInputs = [
        { name: 'nik_ayah', label: 'NIK ayah' },
        { name: 'nik_ibu', label: 'NIK ibu' },
        { name: 'nik_saksi1', label: 'NIK saksi 1' },
        
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
    
    let hasFormulir = false;
    let hasSuratKeterangan = false;
    let hasBukuNikah = false;
    let hasKkOrSuketKk = false;
    
    // Cek dokumen lama yang dipilih
    existingDocsChecked.forEach(checkbox => {
        const docItem = checkbox.closest('.existing-doc-item');
        const badge = docItem.querySelector('.doc-type-badge');
        if (badge) {
            const docType = normalizeDocType(badge.dataset.docType || '');
            if (docType === 'formulir_kelahiran') hasFormulir = true;
            if (docType === 'surat_keterangan_lahir') hasSuratKeterangan = true;
            if (docType === 'buku_nikah') hasBukuNikah = true;
            if (docType === 'kk' || docType === 'suket_kehilangan_kk') hasKkOrSuketKk = true;
        }
    });
    
    // Cek dokumen baru yang diupload
    docTypeSelects.forEach((select, index) => {
        const type = normalizeDocType(select.value);
        const fileInput = fileInputs[index];
        
        if (fileInput && fileInput.files.length > 0) {
            if (type === 'formulir_kelahiran') hasFormulir = true;
            if (type === 'surat_keterangan_lahir') hasSuratKeterangan = true;
            if (type === 'buku_nikah') hasBukuNikah = true;
            if (type === 'kk' || type === 'suket_kehilangan_kk') hasKkOrSuketKk = true;
        }
    });

    // Akumulasi ukuran dari semua file input (termasuk jika doc type belum dipilih)
    fileInputs.forEach(input => {
        if (input.files.length > 0) {
            totalUploadSize += input.files[0].size;
            if (input.files[0].size > EFFECTIVE_MAX_FILE_SIZE) {
                errorMessages.push(`File "${input.files[0].name}" melebihi batas ${formatFileSizeClient(EFFECTIVE_MAX_FILE_SIZE)}`);
                isValid = false;
            }
        }
    });

    if (SERVER_POST_MAX_BYTES > 0 && totalUploadSize > Math.max(0, SERVER_POST_MAX_BYTES - SERVER_POST_BUFFER)) {
        errorMessages.push(`Total ukuran upload ${formatFileSizeClient(totalUploadSize)} melebihi batas server ${formatFileSizeClient(SERVER_POST_MAX_BYTES)}. Kecilkan ukuran file agar tidak gagal CSRF.`);
        isValid = false;
    }
    
    if (!hasFormulir) {
        errorMessages.push('Formulir F-2.01 Kelahiran wajib diupload atau dipilih dari dokumen yang ada');
        isValid = false;
    }
    
    if (!hasSuratKeterangan) {
        errorMessages.push('Surat Keterangan Kelahiran dari Puskes/RS/Desa/Kelurahan wajib diupload atau dipilih dari dokumen yang ada');
        isValid = false;
    }
    
    if (!hasBukuNikah) {
        errorMessages.push('Buku Nikah/Akta kawin/SPTJM Perkawinan wajib diupload atau dipilih dari dokumen yang ada');
        isValid = false;
    }

    if (!hasKkOrSuketKk) {
        errorMessages.push('Kartu Keluarga (KK) atau Suket Kehilangan KK wajib diupload atau dipilih dari dokumen yang ada');
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
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Mengajukan ulang...';
        }
    }
});

// Logout Modal Functions
// Delegasi global untuk menstabilkan setiap file yang dipilih.
document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    stabilizeSelectedFile(target);
});
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


