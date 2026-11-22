<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_pindah_online LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
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
    alamat_asal,
    alamat_tujuan,
    no_kk,
    nama_kepalaKeluarga, 
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

// PERBAIKAN BESAR: Perbaiki path dokumen untuk preview
$folderName = $permohonan['nama_folder'] ?? '';

// Base URL untuk preview dokumen
$base_url = 'http://' . $_SERVER['HTTP_HOST'];
$current_path = dirname($_SERVER['PHP_SELF']);

// Hapus /user dari path karena kita berada di dalam folder user
$base_path = str_replace('/user', '', $current_path);

foreach ($dokumen_data as &$doc) {
    if (isset($doc['path']) && !empty($doc['path'])) {
        $original_path = $doc['path'];
        
        // PERBAIKAN: Cek apakah file ada di path yang tersimpan
        $absolute_path = __DIR__ . '/' . $original_path;
        
        // Jika file tidak ditemukan di path asli, coba cari berdasarkan struktur folder
        if (!file_exists($absolute_path) && !empty($folderName)) {
            // Ambil nama file saja
            $filename = basename($original_path);
            
            // Coba beberapa kemungkinan lokasi berdasarkan struktur folder yang ada
            $possible_paths = [
                // Struktur utama: /user/uploads/pindah_online/[folder]/
                'uploads/pindah_online/' . $folderName . '/' . $filename,
                'uploads/pindah_online/' . $folderName . '/dokumen_pendukung/' . $filename,
                // Path dari root
                '../user/uploads/pindah_online/' . $folderName . '/' . $filename,
                '../user/uploads/pindah_online/' . $folderName . '/dokumen_pendukung/' . $filename,
                // Path alternatif
                '../../user/uploads/pindah_online/' . $folderName . '/' . $filename
            ];
            
            foreach ($possible_paths as $possible_path) {
                $full_possible_path = __DIR__ . '/' . $possible_path;
                if (file_exists($full_possible_path)) {
                    $doc['path'] = $possible_path;
                    break;
                }
            }
        }
        
        if (isset($doc['path']) && !empty($doc['path'])) {
            $doc['preview_url'] = siprima_document_token_url(__DIR__, (string)$doc['path']) ?? '';
        }
    }
}
unset($doc); // Hapus reference

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
    $id_kecamatan_tujuan = (int)($_POST['id_kecamatan_tujuan'] ?? 0);
    $id_desa_tujuan = (int)($_POST['id_desa_tujuan'] ?? 0);
    $alamat_asal = trim($_POST['alamat_asal'] ?? '');
    $alamat_tujuan = trim($_POST['alamat_tujuan'] ?? '');
    $no_kk = trim($_POST['no_kk'] ?? '');
    $nama_kepalaKeluarga = trim($_POST['nama_kepalaKeluarga'] ?? '');
    $alasan_pindah = trim($_POST['alasan_pindah'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi
    if (empty($nama_pemohon)) $errors[] = "Nama pemohon harus diisi";
    if (empty($nik_pemohon)) $errors[] = "NIK pemohon harus diisi";
    if (empty($no_hp)) $errors[] = "No HP harus diisi";
    if (empty($email)) $errors[] = "Email harus diisi";
    if (empty($alamat_asal)) $errors[] = "Alamat asal harus diisi";
    if (empty($alamat_tujuan)) $errors[] = "Alamat tujuan harus diisi";
    if (empty($no_kk)) $errors[] = "Nomor KK harus diisi";
    if (strlen($no_kk) !== 16) $errors[] = "Nomor KK harus 16 digit";
    if (empty($nama_kepalaKeluarga)) $errors[] = "Nama kepala keluarga harus diisi";
    if (empty($alasan_pindah)) $errors[] = "Alasan pindah harus dipilih";
    if (empty($id_kecamatan_tujuan)) $errors[] = "Kecamatan tujuan harus dipilih";
    if (empty($id_desa_tujuan)) $errors[] = "Desa tujuan harus dipilih";
    
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
                // Validasi NIK jika diisi
                if (!empty($nik) && strlen($nik) !== 16) {
                    $errors[] = "NIK anggota harus 16 digit atau dikosongkan";
                } else {
                    $anggota_keluarga_baru[] = [
                        'nama' => $nama,
                        'nik' => $nik,
                        'hubungan' => $hubungan
                    ];
                }
            }
        }
    }
    
    if (empty($anggota_keluarga_baru)) {
        $errors[] = "Minimal harus ada satu anggota keluarga";
    }
    
    // **PERBAIKAN: Gunakan folder yang sama**
    $folderName = $permohonan['nama_folder'] ?? '';
    
    // Ambil nama kecamatan dan desa tujuan untuk validasi
    $nama_kecamatan_tujuan = '';
    $nama_desa_tujuan = '';
    
    if (!empty($id_kecamatan_tujuan)) {
        $qk = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $qk->bind_param('i', $id_kecamatan_tujuan);
        $qk->execute();
        $qk->bind_result($nama_kecamatan_tujuan);
        $qk->fetch();
        $qk->close();
    }
    
    if (!empty($id_desa_tujuan)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $qd->bind_param('i', $id_desa_tujuan);
        $qd->execute();
        $qd->bind_result($nama_desa_tujuan);
        $qd->fetch();
        $qd->close();
    }
    
    // Path folder lama (folder yang sudah ada)
    $base_upload_dir = __DIR__ . '/uploads/pindah_online/';
    $folder_path = '';
    $pendukung_path = '';
    
    if (!empty($folderName)) {
        $folder_path = $base_upload_dir . $folderName . '/';
        $pendukung_path = $folder_path . 'dokumen_pendukung/';
        
        // Pastikan folder ada
        if (!is_dir($folder_path)) {
            if (!mkdir($folder_path, 0755, true)) {
                $errors[] = 'Gagal membuat folder penyimpanan dokumen';
            }
        }
        
        // Buat subfolder untuk dokumen pendukung jika belum ada
        if (!is_dir($pendukung_path)) {
            if (!mkdir($pendukung_path, 0755, true)) {
                $errors[] = 'Gagal membuat folder dokumen pendukung';
            }
        }
    }
    
    // Handle upload dokumen
    $dokumen_baru = [];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Dokumen wajib berdasarkan pindah_online.php
    $requiredDocs = ['formulir_pindah', 'kk_ktp', 'foto_pemohon'];
    
    // Simpan dokumen yang sudah ada yang dipilih
    $selected_existing_docs = [];
    $existing_doc_types = [];
    
    if (isset($_POST['existing_docs']) && is_array($_POST['existing_docs'])) {
        foreach ($_POST['existing_docs'] as $doc_index) {
            $doc_index = (int)$doc_index;
            if (isset($dokumen_data[$doc_index])) {
                $doc = $dokumen_data[$doc_index];
                $selected_existing_docs[] = $doc;
                $existing_doc_types[] = $doc['type'];
                
                // Perbaiki path jika perlu
                if (isset($doc['path']) && !empty($folderName)) {
                    $dokumen_baru[] = $doc;
                } else {
                    $dokumen_baru[] = $doc;
                }
            }
        }
    }
    
    // Proses upload dokumen baru
    $uploadedDocTypes = [];
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileType = mime_content_type($fileTmp);
                $fileSize = $_FILES['dokumen']['size'][$i];
                $docType = $_POST['doc_type'][$i] ?? 'lainnya';

                // Validasi tipe file
                $allowedExt = ['jpg', 'jpeg', 'jfif', 'jpe', 'png'];
                if (!in_array($ext, $allowedExt) || 
                    !in_array($fileType, $allowedTypes)) {
                    $errors[] = "File {$fileName}: Hanya format JPG, PNG, atau GIF yang diperbolehkan";
                    continue;
                }

                // Validasi ukuran file
                if ($fileSize > $maxFileSize) {
                    $errors[] = "File {$fileName}: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
                    continue;
                }

                // Generate nama file yang lebih deskriptif
                $docTypeName = '';
                switch ($docType) {
                    case 'formulir_pindah':
                        $docTypeName = 'Formulir_F103_Pindah';
                        break;
                    case 'kk_ktp':
                        $docTypeName = 'KK_KTP_Surat_Hilang';
                        break;
                    case 'foto_pemohon':
                        $docTypeName = 'Foto_Pemohon_Memegang_Dokumen';
                        break;
                    case 'buku_nikah':
                        $docTypeName = 'Buku_Akta_Nikah';
                        break;
                    default:
                        $docTypeName = 'Dokumen_Lainnya';
                }
                
                $newName = $docTypeName . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                
                // Simpan di folder yang sudah ada (bukan folder baru)
                if (!empty($folder_path)) {
                    if ($docType === 'lainnya') {
                        $destination = $pendukung_path . $newName;
                        $savePath = 'uploads/pindah_online/' . $folderName . '/dokumen_pendukung/' . $newName;
                    } else {
                        $destination = $folder_path . $newName;
                        $savePath = 'uploads/pindah_online/' . $folderName . '/' . $newName;
                    }

                    if (move_uploaded_file($fileTmp, $destination)) {
                        $dokumen_baru[] = [
                            'path' => $savePath,
                            'type' => $docType,
                            'original_name' => $fileName,
                            'folder' => $folderName
                        ];
                        $uploadedDocTypes[] = $docType;
                    } else {
                        $errors[] = "File {$fileName}: Gagal menyimpan file";
                    }
                } else {
                    $errors[] = "Folder penyimpanan tidak ditemukan";
                }
            }
        }
    }
    
    // Gabungkan semua tipe dokumen untuk validasi
    $all_doc_types = array_merge($existing_doc_types, $uploadedDocTypes);
    
    // Cek dokumen wajib - semua harus ada (dari dokumen lama dan/atau baru)
    $missingRequired = array_diff($requiredDocs, $all_doc_types);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            switch ($doc) {
                case 'formulir_pindah':
                    $missingNames[] = 'Formulir F-1.03 Pindah Penduduk';
                    break;
                case 'kk_ktp':
                    $missingNames[] = 'KK/KTP/Surat keterangan hilang KK atau KTP';
                    break;
                case 'foto_pemohon':
                    $missingNames[] = 'Pemohon Foto Memegang KK/KTP/Form Pindah';
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

        // Convert data ke JSON
        $anggota_keluarga_json = json_encode($anggota_keluarga_baru);
        $dokumen_json = json_encode($dokumen_baru);
        
        // Update data permohonan
        $stmt = $koneksi->prepare("UPDATE tb_pindah_online SET 
            nama_pemohon = ?,
            nik_pemohon = ?,
            no_hp = ?,
            email = ?,
            id_kecamatan = ?,
            id_desa = ?,
            alamat_asal = ?,
            alamat_tujuan = ?,
            nama_kecamatan = ?,
            nama_desa = ?,
            no_kk = ?,
            nama_kepalaKeluarga = ?,
            alasan_pindah = ?,
            anggotaKeluarga = ?,
            keterangan = ?,
            dokumen = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?");
        
        $stmt->bind_param('ssssiissssssssssii', 
            $nama_pemohon,
            $nik_pemohon,
            $no_hp,
            $email,
            $id_kecamatan_tujuan,
            $id_desa_tujuan,
            $alamat_asal,
            $alamat_tujuan,
            $nama_kecamatan_tujuan,
            $nama_desa_tujuan,
            $no_kk,
            $nama_kepalaKeluarga,
            $alasan_pindah,
            $anggota_keluarga_json,
            $keterangan,
            $dokumen_json,
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
            $_SESSION['success_message'] = "Permohonan pindah masuk berhasil diperbarui dan diajukan kembali!";
            header('Location: status_permohonan.php');
            exit;
        } else {
            $errors[] = "Gagal memperbarui data: " . $stmt->error;
            
            // Jika gagal update, hapus file baru yang diupload (tapi jangan hapus folder)
            if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
                for ($i = 0; $i < count($_FILES['dokumen']['name']); $i++) {
                    if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                        $fileName = basename($_FILES['dokumen']['name'][$i]);
                        $docType = $_POST['doc_type'][$i] ?? 'lainnya';
                        
                        if (!empty($dokumen_baru)) {
                            foreach ($dokumen_baru as $doc) {
                                if (isset($doc['original_name']) && $doc['original_name'] === $fileName && 
                                    isset($doc['type']) && $doc['type'] === $docType &&
                                    isset($doc['path'])) {
                                    $file_path = __DIR__ . '/' . $doc['path'];
                                    if (file_exists($file_path)) {
                                        unlink($file_path);
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        $stmt->close();
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

function selected($value, $compare) {
    return $value == $compare ? 'selected' : '';
}

// Untuk validasi JavaScript
$existing_doc_types_js = [];
$existing_docs_array_js = [];
foreach ($dokumen_data as $index => $doc) {
    if (isset($doc['type'])) {
        $existing_doc_types_js[$index] = $doc['type'];
        $existing_docs_array_js[] = [
            'index' => $index,
            'type' => $doc['type'],
            'name' => $doc['original_name'] ?? 'Dokumen',
            'preview_url' => $doc['preview_url'] ?? '' // PERBAIKAN: Tambahkan preview_url
        ];
    }
}
$csrf_token_value = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
    <script>window.CSRF_TOKEN = '<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>';</script>
    <title>Edit Permohonan Pindah Masuk</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/edit_pindah_online.css">
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
            render_user_header_brand('Edit Permohonan Pindah Masuk');
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
                    <h2>Edit Permohonan Pindah Masuk</h2>
                    <span class="status-badge">Status: <?= esc(ucfirst(strtolower((string)($permohonan['status'] ?? '-')))) ?></span>
                </div>

                <form method="POST" enctype="multipart/form-data" id="editPindahOnlineForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
                    <!-- Data Pemohon -->
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
                                       value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>" required readonly
                                       pattern="[0-9]{16}" maxlength="16"
                                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
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

                    <!-- Data Kartu Keluarga -->
                    <div class="form-section">
                        <h3 class="section-title">Data Kartu Keluarga</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nomor Kartu Keluarga (No KK) <span class="required">*</span></label>
                                <input type="text" name="no_kk" class="form-control" 
                                       value="<?= esc($permohonan['no_kk'] ?? '') ?>" required
                                       pattern="[0-9]{16}" maxlength="16"
                                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                                <small class="text-muted">16 digit nomor KK</small>
                            </div>
                            
                            <div class="form-group">
                                <label>Nama Kepala Keluarga <span class="required">*</span></label>
                                <input type="text" name="nama_kepalaKeluarga" class="form-control" 
                                       value="<?= esc($permohonan['nama_kepalaKeluarga'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Alamat Asal (di luar Gorontalo) -->
                    <div class="form-section">
                        <h3 class="section-title">Alamat Asal (Di Luar Kabupaten Gorontalo)</h3>
                        <div class="form-group">
                            <label>Alamat Asal Lengkap <span class="required">*</span></label>
                            <textarea name="alamat_asal" class="form-control" rows="3" required><?= esc($permohonan['alamat_asal'] ?? '') ?></textarea>
                            <small class="text-muted">Alamat lengkap saat ini di luar Kabupaten Gorontalo</small>
                        </div>
                    </div>

                    <!-- Alamat Tujuan (di Gorontalo) -->
                    <div class="form-section">
                        <h3 class="section-title">Alamat Tujuan (Di Kabupaten Gorontalo)</h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Kecamatan Tujuan <span class="required">*</span></label>
                                <select name="id_kecamatan_tujuan" id="kecamatanSelectTujuan" class="form-control" required>
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
                                <label>Desa Tujuan <span class="required">*</span></label>
                                <select name="id_desa_tujuan" id="desaSelectTujuan" class="form-control" required>
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
                        
                        <div class="form-group">
                            <label>Alamat Tujuan Lengkap <span class="required">*</span></label>
                            <textarea name="alamat_tujuan" class="form-control" rows="3" required><?= esc($permohonan['alamat_tujuan'] ?? '') ?></textarea>
                            <small class="text-muted">Alamat lengkap tujuan di Kabupaten Gorontalo</small>
                        </div>
                    </div>

                    <!-- Alasan Pindah -->
                    <div class="form-section">
                        <h3 class="section-title">Alasan Pindah</h3>
                        <div class="form-group">
                            <label>Alasan Pindah ke Kabupaten Gorontalo <span class="required">*</span></label>
                            <select name="alasan_pindah" class="form-control" required>
                                <option value="">-- Pilih Alasan Pindah --</option>
                                <option value="kerja" <?= selected('kerja', $permohonan['alasan_pindah'] ?? '') ?>>Pekerjaan</option>
                                <option value="pendidikan" <?= selected('pendidikan', $permohonan['alasan_pindah'] ?? '') ?>>Pendidikan</option>
                                <option value="keluarga" <?= selected('keluarga', $permohonan['alasan_pindah'] ?? '') ?>>Keluarga</option>
                                <option value="rumah" <?= selected('rumah', $permohonan['alasan_pindah'] ?? '') ?>>Pindah Rumah</option>
                                <option value="kembali" <?= selected('kembali', $permohonan['alasan_pindah'] ?? '') ?>>Kembali ke Kampung Halaman</option>
                                <option value="lainnya" <?= selected('lainnya', $permohonan['alasan_pindah'] ?? '') ?>>Lainnya</option>
                            </select>
                        </div>
                    </div>

                    <!-- Anggota Keluarga yang Pindah -->
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
                                                   placeholder="16 digit NIK (kosongkan jika tidak ada)"
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
                                               placeholder="16 digit NIK (kosongkan jika tidak ada)"
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
                                <li><strong>Dokumen WAJIB:</strong> 
                                    <ol>
                                        <li>Formulir F-1.03 Pindah Penduduk</li>
                                        <li>KK/KTP/Surat Keterangan Hilang KK/KTP</li>
                                        <li>Pemohon Foto Memegang KK/KTP/Form Pindah</li>
                                    </ol>
                                </li>
                                <li><strong>Dokumen OPSIONAL:</strong> Buku nikah/akta nikah, dokumen pendukung lainnya</li>
                                <li>Format file: JPG, JPEG, PNG, GIF</li>
                                <li>Ukuran maksimal per file: 1MB</li>
                            </ul>
                        </div>
                        
                        <!-- Dokumen yang sudah ada -->
                        <?php if (!empty($dokumen_data)): ?>
                        <div class="document-list" id="existing-documents">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Dokumen yang Sudah Ada (Centang untuk menyimpan):</h4>
                            <?php foreach ($dokumen_data as $index => $doc): ?>
                                <div class="document-item" data-index="<?= $index ?>">
                                    <div class="doc-info">
                                        <?php 
                                        $badge_class = 'badge-' . ($doc['type'] ?? 'lainnya');
                                        $type_labels = [
                                            'formulir_pindah' => 'Formulir F-1.03',
                                            'kk_ktp' => 'KK/KTP/Surat Hilang',
                                            'foto_pemohon' => 'Foto Pemohon',
                                            'buku_nikah' => 'Buku Nikah',
                                            'lainnya' => 'Dokumen Lainnya'
                                        ];
                                        $type_label = $type_labels[$doc['type'] ?? 'lainnya'] ?? ($doc['type'] ?? 'Dokumen');
                                        ?>
                                        <span class="doc-type-badge <?= $badge_class ?>"><?= $type_label ?></span>
                                        <span class="doc-name"><?= esc($doc['original_name'] ?? 'Dokumen') ?></span>
                                    </div>
                                    <div class="doc-actions">
                                        <input type="checkbox" name="existing_docs[]" value="<?= $index ?>" checked style="margin-right: 10px;">
                                        <?php 
                                        // PERBAIKAN: Gunakan preview_url yang sudah diperbaiki
                                        $preview_url = $doc['preview_url'] ?? '';
                                        if (!empty($preview_url)):
                                        ?>
                                        <button type="button" class="btn-preview" 
                                                onclick="previewDocument('<?= addslashes($preview_url) ?>', '<?= esc($doc['original_name'] ?? 'Dokumen') ?>')">
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
                        
                        <!-- Dokumen baru -->
                        <div id="fileUploadContainer">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Tambah Dokumen Baru (Opsional):</h4>
                            
                            <div class="file-input-group" id="fileGroup1">
                                <select name="doc_type[]" class="file-type-select">
                                    <option value="">Pilih Jenis Dokumen Baru</option>
                                    <option value="formulir_pindah">Formulir F-1.03 Pindah Penduduk</option>
                                    <option value="kk_ktp">KK/KTP/Surat Keterangan Hilang KK/KTP</option>
                                    <option value="foto_pemohon">Pemohon Foto Memegang KK/KTP/Form Pindah</option>
                                    <option value="buku_nikah">Buku nikah/akta nikah</option>
                                    <option value="lainnya">Dokumen Pendukung Lainnya</option>
                                </select>
                                <div class="file-upload">
                                    <label class="file-upload-label">
                                        <i class="fas fa-upload"></i> Pilih File
                                        <input type="file" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
                                    </label>
                                    <div class="file-preview">
                                        <span class="file-name">Belum ada file dipilih</span>
                                    </div>
                                </div>
                                <div class="file-size-warning">Maksimal 5 MB per file, dikompres otomatis</div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn-add" onclick="addFileInput()">
                            <i class="fas fa-plus"></i> Tambah Dokumen Lainnya
                        </button>
                        
                        <p class="helper" style="margin-top: 15px;">
                            <strong>Catatan:</strong> Centang dokumen yang sudah ada untuk menyimpannya. File baru akan ditambahkan ke folder yang sama.
                        </p>
                    </div>

                    <!-- Keterangan -->
                    <div class="form-section">
                        <h3 class="section-title">Keterangan</h3>
                        <div class="form-group">
                            <label>Keterangan Tambahan</label>
                            <textarea name="keterangan" class="form-control" rows="3" maxlength="500"><?= esc($permohonan['keterangan'] ?? '') ?></textarea>
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
<div id="previewModal" class="modal-overlay">
    <div class="preview-container">
        <div class="preview-header">
            <h3 id="previewTitle">Preview Dokumen</h3>
            <button class="close-preview" onclick="closePreview()">×</button>
        </div>
        <div class="preview-content" id="previewContent">
            <!-- Preview content will be loaded here -->
        </div>
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
        if (event.key === 'Escape' && document.getElementById('previewModal').classList.contains('show')) {
            closePreview();
        }
    });
    
    // Set kecamatan dropdown value dan load desa jika sudah ada nilai
    <?php if (!empty($permohonan['id_kecamatan'])): ?>
    setTimeout(() => {
        getDesaByKecamatan(<?= $permohonan['id_kecamatan'] ?>);
    }, 100);
    <?php endif; ?>
    
    // Initialize file input event listeners
    initFileInputListeners();
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
            <input type="text" name="anggota_nik[]" 
                   placeholder="16 digit NIK (kosongkan jika tidak ada)"
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
    const rows = document.querySelectorAll('#anggotaBody tr');
    if (rows.length > 1) {
        row.remove();
    } else {
        alert('Minimal harus ada satu anggota keluarga');
    }
}

// Dokumen Functions
let fileCounter = 1;
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

function formatFileSize(bytes) {
    if (bytes >= 1048576) {
        return (bytes / 1048576).toFixed(2) + ' MB';
    } else if (bytes >= 1024) {
        return (bytes / 1024).toFixed(2) + ' KB';
    } else {
        return bytes + ' Bytes';
    }
}

function addFileInput() {
    fileCounter++;
    const container = document.getElementById('fileUploadContainer');
    const newFileGroup = document.createElement('div');
    newFileGroup.className = 'file-input-group';
    newFileGroup.id = 'fileGroup' + fileCounter;
    newFileGroup.innerHTML = `
        <select name="doc_type[]" class="file-type-select">
            <option value="">Pilih Jenis Dokumen Baru</option>
            <option value="formulir_pindah">Formulir F-1.03 Pindah Penduduk</option>
            <option value="kk_ktp">KK/KTP/Surat Keterangan Hilang KK/KTP</option>
            <option value="foto_pemohon">Pemohon Foto Memegang KK/KTP/Form Pindah</option>
            <option value="buku_nikah">Buku nikah/akta nikah</option>
            <option value="lainnya">Dokumen Pendukung Lainnya</option>
        </select>
        <div class="file-upload">
            <label class="file-upload-label">
                <i class="fas fa-upload"></i> Pilih File
                <input type="file" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
            </label>
            <div class="file-preview">
                <span class="file-name">Belum ada file dipilih</span>
            </div>
        </div>
        <div class="file-size-warning">Maksimal 5 MB per file, dikompres otomatis</div>
        <button type="button" class="btn-remove" onclick="removeFileInput(${fileCounter})">Hapus</button>
    `;
    container.appendChild(newFileGroup);
    
    // Add event listener to new file input
    initFileInput(newFileGroup);
}

function removeFileInput(fileId) {
    const fileGroup = document.getElementById('fileGroup' + fileId);
    if (fileGroup) {
        // Don't remove the first file group
        if (fileId > 1) {
            fileGroup.remove();
        } else {
            alert('Dokumen pertama tidak dapat dihapus.');
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

// PERBAIKAN: Preview Functions
function previewDocument(url, title) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    const titleElement = document.getElementById('previewTitle');
    
    // Set title
    if (title) {
        titleElement.textContent = 'Preview: ' + title;
    } else {
        titleElement.textContent = 'Preview Dokumen';
    }
    
    // Clear previous content
    content.innerHTML = '';
    
    // Create loading indicator
    content.innerHTML = '<p>Memuat dokumen...</p>';
    
    // Determine file type from URL
    const extension = url.split('.').pop().toLowerCase();
    
    if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
        // For images, create an img element
        const img = document.createElement('img');
        img.src = url;
        img.alt = title || 'Preview dokumen';
        img.className = 'preview-image';
        img.onload = function() {
            content.innerHTML = '';
            content.appendChild(img);
        };
        img.onerror = function() {
            content.innerHTML = '<p style="color: #dc2626;">Gagal memuat gambar. URL: ' + url + '</p>';
            console.error('Gagal memuat gambar dari URL:', url);
        };
    } else {
        content.innerHTML = '<p style="color: #dc2626;">Format file tidak didukung untuk preview. Hanya gambar (JPG, JPEG, JFIF, JPE, PNG) yang dapat dipreview.</p>';
    }
    
    // Show modal
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closePreview() {
    const modal = document.getElementById('previewModal');
    modal.classList.remove('show');
    document.body.style.overflow = '';
}

// Get Desa by Kecamatan
async function getDesaByKecamatan(kecamatanId) {
    const desaSelect = document.getElementById('desaSelectTujuan');
    if (!desaSelect) {
        console.error('ERROR: Element #desaSelectTujuan not found!');
        return;
    }
    
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
            <?php if (!empty($permohonan['id_desa'])): ?>
            setTimeout(() => {
                desaSelect.value = <?= $permohonan['id_desa'] ?>;
            }, 100);
            <?php endif; ?>
        } else {
            throw new Error('Gagal mengambil data desa');
        }
    } catch (error) {
        console.error('Error:', error);
        desaSelect.innerHTML = '<option value="">Error: ' + error.message + '</option>';
    } finally {
        desaSelect.disabled = false;
    }
}

// File input initialization
function initFileInputListeners() {
    const fileInputs = document.querySelectorAll('.file-input');
    fileInputs.forEach((input, index) => {
        input.addEventListener('change', function() {
            const preview = this.closest('.file-upload').querySelector('.file-preview');
            if (this.files.length > 0) {
                const file = this.files[0];
                
                // Validasi ukuran file (1MB)
                if (file.size > MAX_FILE_SIZE) {
                    alert(`File ${file.name} terlalu besar. Maksimal 1MB.`);
                    this.value = '';
                    preview.innerHTML = '<span class="file-name">File terlalu besar, pilih file lain</span>';
                    return;
                }
                
                const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                preview.innerHTML = `<span class="file-name">${file.name} (${fileSizeMB} MB)</span>`;
            } else {
                preview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
            }
        });
    });
}

function initFileInput(fileGroup) {
    const fileInput = fileGroup.querySelector('.file-input');
    const preview = fileGroup.querySelector('.file-preview');
    
    if (fileInput && preview) {
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const file = this.files[0];
                
                // Validasi ukuran file (1MB)
                if (file.size > MAX_FILE_SIZE) {
                    alert(`File ${file.name} terlalu besar. Maksimal 1MB.`);
                    this.value = '';
                    preview.innerHTML = '<span class="file-name">File terlalu besar, pilih file lain</span>';
                    return;
                }
                
                const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                preview.innerHTML = `<span class="file-name">${file.name} (${fileSizeMB} MB)</span>`;
            } else {
                preview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
            }
        });
    }
}

// Form Validation untuk dokumen yang sudah ada
document.getElementById('editPindahOnlineForm').addEventListener('submit', function(e) {
    let isValid = true;
    let errorMessages = [];
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = (window.CSRF_TOKEN || (csrfMeta ? csrfMeta.getAttribute('content') : '') || '').trim();
    let csrfInput = this.querySelector('input[name="csrf_token"]');

    if (!csrfInput) {
        csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        this.appendChild(csrfInput);
    }
    if (csrfToken) {
        csrfInput.value = csrfToken;
    }
    if (!csrfInput.value.trim()) {
        e.preventDefault();
        alert('Sesi keamanan tidak valid (CSRF token kosong). Muat ulang halaman lalu coba lagi.');
        return;
    }
    
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
    
    // Validate No KK length
    const noKKInput = document.querySelector('input[name="no_kk"]');
    if (noKKInput && noKKInput.value.length !== 16) {
        errorMessages.push('Nomor KK harus 16 digit');
        noKKInput.classList.add('is-invalid');
        isValid = false;
    }
    
    // Validate NIK length if provided
    const nikInputs = document.querySelectorAll('input[name="anggota_nik[]"]');
    nikInputs.forEach((input, index) => {
        if (input.value && input.value.length !== 16) {
            errorMessages.push(`NIK anggota baris ${index + 1} harus 16 digit atau dikosongkan`);
            input.classList.add('is-invalid');
            isValid = false;
        }
    });
    
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
    
    // PERBAIKAN: Cek dokumen wajib dengan benar
    // Data dokumen yang sudah ada dari PHP
    const existingDocs = <?= json_encode($existing_docs_array_js) ?>;
    
    // Cek dokumen wajib dari dokumen yang sudah ada (yang dicentang)
    const checkedExistingDocs = [];
    const checkedExistingInputs = document.querySelectorAll('input[name="existing_docs[]"]:checked');
    
    checkedExistingInputs.forEach(input => {
        const index = parseInt(input.value);
        // Cari dokumen dengan index yang sesuai
        const doc = existingDocs.find(d => d.index === index);
        if (doc && doc.type) {
            checkedExistingDocs.push(doc.type);
        }
    });
    
    // Cek dokumen baru dari form
    const docTypeSelects = document.querySelectorAll('select[name="doc_type[]"]');
    let hasFormulir = checkedExistingDocs.includes('formulir_pindah');
    let hasKKKTP = checkedExistingDocs.includes('kk_ktp');
    let hasFoto = checkedExistingDocs.includes('foto_pemohon');
    
    docTypeSelects.forEach((select, index) => {
        const type = select.value;
        const fileInput = document.querySelector(`#fileGroup${index + 1} input[type="file"]`);
        
        if (type === 'formulir_pindah' && fileInput && fileInput.files.length > 0) {
            hasFormulir = true;
        }
        if (type === 'kk_ktp' && fileInput && fileInput.files.length > 0) {
            hasKKKTP = true;
        }
        if (type === 'foto_pemohon' && fileInput && fileInput.files.length > 0) {
            hasFoto = true;
        }
    });
    
    if (!hasFormulir) {
        errorMessages.push('Formulir F-1.03 Pindah Penduduk wajib diupload atau dipilih dari dokumen yang sudah ada');
        isValid = false;
    }
    
    if (!hasKKKTP) {
        errorMessages.push('KK/KTP/Surat Keterangan Hilang KK/KTP wajib diupload atau dipilih dari dokumen yang sudah ada');
        isValid = false;
    }
    
    if (!hasFoto) {
        errorMessages.push('Pemohon Foto Memegang KK/KTP/Form Pindah wajib diupload atau dipilih dari dokumen yang sudah ada');
        isValid = false;
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
            if (file.size > MAX_FILE_SIZE) {
                errorMessages.push(`File ${file.name} terlalu besar (maksimal 1MB)`);
                isValid = false;
                break;
            }
        }
    }
    
    if (!isValid) {
        e.preventDefault();
        let message = 'Perbaiki kesalahan berikut:\n';
        if (errorMessages.length > 0) {
            message += '• ' + errorMessages.join('\n• ');
        } else {
            message += '• Harap lengkapi semua field yang wajib diisi';
        }
        alert(message);
    } else {
        // Konfirmasi sebelum submit
        const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan kembali permohonan pindah masuk?');
        if (!confirmSubmit) {
            e.preventDefault();
        }
    }
});

// Event listener for kecamatan dropdown
document.getElementById('kecamatanSelectTujuan').addEventListener('change', function() {
    getDesaByKecamatan(this.value);
});
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>


