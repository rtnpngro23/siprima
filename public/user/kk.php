<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
require_once __DIR__ . '/../functions/formulir_f101.php';
require_once __DIR__ . '/../../app/config/document_features.php';
require_once __DIR__ . '/anggota_kk_kartu.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// Ambil data user untuk auto-fill pemohon
$q = $koneksi->query("SELECT nama, nik, no_hp, email FROM tb_users WHERE id = '$user_id'");
if (!$q) {
    die("Query error: " . $koneksi->error);
}

$data_user = $q->fetch_assoc();

// Cek jika data user tidak ditemukan
if (!$data_user) {
    header('Location: ../login.php?error=user_not_found');
    exit;
}

$errors = [];
$success_message = '';

// Untuk mempertahankan pilihan setelah submit gagal
$selected_kecamatan = isset($_POST['id_kecamatan']) ? (int)$_POST['id_kecamatan'] : '';
$selected_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : '';
$pre_nama_kecamatan = '';
$pre_nama_desa = '';

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // IDs kecamatan/desa
    $id_kecamatan = isset($_POST['id_kecamatan']) ? (int)$_POST['id_kecamatan'] : 0;
    $id_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : 0;

    // data keluarga
    $dusun = trim($_POST['dusun'] ?? '');
    $nama_kepalaKeluarga = trim($_POST['nama_kepalaKeluarga'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Isian tambahan F-1.01: jenis permohonan, pernyataan, dan tanda tangan.
    // Pemeriksaan murah saja di sini; render gambar dikerjakan setelah unggahan final.
    $f101_fields = null;
    $f101_signature = null;
    $formulir_dibuat_at = null;
    if (FORMULIR_F101_AUTO_ENABLED) {
        $errors = array_merge($errors, siprima_f101_validate_fields($_POST, $f101_fields));
        if (empty($_POST['pernyataan_benar'])) {
            $errors[] = 'Centang pernyataan kebenaran data sebelum mengirim permohonan.';
        }
        $pesan_ttd = siprima_formulir_validate_signature((string)($_POST['ttd_pemohon'] ?? ''), $f101_signature);
        if ($pesan_ttd !== '') $errors[] = $pesan_ttd;
    }
    $jenis_permohonan = $f101_fields['jenis_permohonan'] ?? null;

    // Proses data anggota keluarga dari input dinamis
    $anggotaKeluarga = [];
    if (FORMULIR_F101_AUTO_ENABLED) {
        // Validator F-1.01 sekaligus menolak pemotongan max_input_vars, NIK tidak sah,
        // enum di luar daftar, dan jumlah anggota di atas batas.
        $errors = array_merge($errors, siprima_f101_validate_anggota($_POST, $anggotaKeluarga));
        $anggotaKeluarga = (array)$anggotaKeluarga;
    } elseif (isset($_POST['anggota_nama']) && is_array($_POST['anggota_nama'])) {
        $anggota_nama = $_POST['anggota_nama'];
        $anggota_nik = $_POST['anggota_nik'] ?? [];
        $anggota_hubungan = $_POST['anggota_hubungan'] ?? [];
        
        for ($i = 0; $i < count($anggota_nama); $i++) {
            $nama = trim($anggota_nama[$i] ?? '');
            $nik = trim($anggota_nik[$i] ?? '');
            $hubungan = trim($anggota_hubungan[$i] ?? '');
            
            if (!empty($nama)) {
                $anggotaKeluarga[] = [
                    'nama' => $nama,
                    'nik' => $nik,
                    'hubungan' => $hubungan
                ];
            }
        }
    }

    // basic validation
    if (!$dusun) $errors[] = 'Alamat/Dusun wajib diisi.';
    if (!$nama_kepalaKeluarga) $errors[] = 'Nama Kepala Keluarga wajib diisi.';
    if (!FORMULIR_F101_AUTO_ENABLED && empty($anggotaKeluarga)) {
        $errors[] = 'Minimal harus ada satu anggota keluarga.';
    }

    // Ambil nama kecamatan/desa untuk pembuatan folder
    $nama_kecamatan_db = '';
    $nama_desa_db = '';
    if (!empty($id_kecamatan)) {
        $qk = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $qk->bind_param("i", $id_kecamatan);
        $qk->execute();
        $rk = $qk->get_result()->fetch_assoc();
        if ($rk) $nama_kecamatan_db = $rk['nama_kecamatan'];
    }
    if (!empty($id_desa)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $qd->bind_param("i", $id_desa);
        $qd->execute();
        $rd = $qd->get_result()->fetch_assoc();
        if ($rd) $nama_desa_db = $rd['nama_desa'];
    }

    // Siapkan data untuk folder name sesuai format baru
    // Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama kepala keluarga]_[tanggal buat]
    $nama_pemohon = $data_user['nama'] ?? '';
    $nik_pemohon = $data_user['nik'] ?? '';
    $timestamp = date('Ymd_His'); // Format: YYYYMMDD_HHMMSS
    
    // Fungsi sanitasi nama untuk folder
    function cleanFolderName($string) {
        $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', $string); // Hapus karakter khusus
        $string = preg_replace('/\s+/', '_', trim($string)); // Ganti spasi dengan underscore
        return $string;
    }
    
    // Bersihkan setiap komponen nama
    $kecamatan_clean = cleanFolderName($nama_kecamatan_db);
    $desa_clean = cleanFolderName($nama_desa_db);
    $pemohon_clean = cleanFolderName($nama_pemohon);
    $kepala_keluarga_clean = cleanFolderName($nama_kepalaKeluarga);
    
    // Buat nama folder sesuai format
    $folder_name = $kecamatan_clean . '_' . 
                  $desa_clean . '_' . 
                  $nik_pemohon . '_' . 
                  $pemohon_clean . '_' . 
                  $kepala_keluarga_clean . '_' . 
                  $timestamp;
    
    // Batasi panjang folder name (max 255 karakter)
    if (strlen($folder_name) > 200) {
        // Potong nama kepala keluarga jika terlalu panjang
        $max_kepala_length = 200 - (strlen($kecamatan_clean) + strlen($desa_clean) + 
                                   strlen($nik_pemohon) + strlen($pemohon_clean) + 
                                   strlen($timestamp) + 5); // 5 untuk underscore
        
        if ($max_kepala_length > 10) {
            $kepala_keluarga_clean = substr($kepala_keluarga_clean, 0, $max_kepala_length);
            $folder_name = $kecamatan_clean . '_' . 
                          $desa_clean . '_' . 
                          $nik_pemohon . '_' . 
                          $pemohon_clean . '_' . 
                          $kepala_keluarga_clean . '_' . 
                          $timestamp;
        } else {
            // Jika masih terlalu panjang, gunakan hash
            $folder_hash = substr(md5($folder_name), 0, 8);
            $folder_name = $kecamatan_clean . '_' . 
                          $desa_clean . '_' . 
                          $nik_pemohon . '_' . 
                          $pemohon_clean . '_' . 
                          $folder_hash . '_' . 
                          $timestamp;
        }
    }
    
    // Path folder utama uploads
    $base_upload_dir = __DIR__ . '/uploads/kk/';
    
    // Buat folder utama jika belum ada
    if (!is_dir($base_upload_dir)) {
        mkdir($base_upload_dir, 0755, true);
    }
    
    // Path folder khusus untuk permohonan ini
    $user_folder_path = $base_upload_dir . $folder_name . '/';
    
    // Buat folder khusus untuk user jika belum ada
    if (!is_dir($user_folder_path)) {
        if (!mkdir($user_folder_path, 0755, true)) {
            $errors[] = 'Gagal membuat folder penyimpanan dokumen';
        }
    }

    // file upload - MULTIPLE FILES, maksimal 1MB SETELAH kompresi otomatis di sisi klien
    $uploadedFiles = [];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Dokumen WAJIB sesuai permintaan.
    // Saat formulir dibuat sistem, pemohon tidak lagi mengunggah blanko F-1.01.
    $requiredDocs = FORMULIR_F101_AUTO_ENABLED
        ? ['kk_lama_atau_surat_kehilangan', 'foto_selfie']
        : ['formulir_f101', 'kk_lama_atau_surat_kehilangan', 'foto_selfie'];
    
    // Dokumen OPSIONAL
    $optionalDocs = ['ktp', 'surat_nikah', 'akta_lahir', 'akta_kematian', 'surat_pindah', 'lainnya'];
    
    $uploadedDocTypes = [];
    
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $fileSize = $_FILES['dokumen']['size'][$i];
                $docType = $_POST['doc_type'][$i] ?? 'lainnya';

                // doc_type berasal dari klien. Saat formulir dibuat sistem, unggahan bertipe
                // formulir_f101 harus ditolak tegas agar tidak ada dua dokumen yang tampak resmi
                // dan operator tetap tahu mana yang tanda tangannya wajib diverifikasi.
                if (FORMULIR_F101_AUTO_ENABLED && $docType === 'formulir_f101') {
                    $errors[] = 'Jenis dokumen tidak valid.';
                    continue;
                }

                $fileType = '';
                $ext = '';
                $uploadValidationError = siprima_validate_uploaded_image(
                    $fileTmp, $fileName, $fileSize, $maxFileSize, $fileType, $ext
                );
                if ($uploadValidationError !== '') {
                    $errors[] = $uploadValidationError;
                    continue;
                }

                // Generate unique filename dengan format yang lebih baik
                // Format: [jenis_dokumen]_[timestamp]_[random].ext
                $timestamp_file = time();
                $randomString = bin2hex(random_bytes(6));
                $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                $newName = $clean_doc_type . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                $destination = $user_folder_path . $newName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    // Simpan path relatif dari root website
                    $uploadedFiles[] = [
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
            } elseif ($_FILES['dokumen']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                // Cek apakah file ini wajib
                $docType = $_POST['doc_type'][$i] ?? '';
                if (in_array($docType, $requiredDocs)) {
                    if ($docType === 'formulir_f101') {
                        $docName = 'Formulir F-1.01 Biodata Keluarga';
                    } elseif ($docType === 'kk_lama_atau_surat_kehilangan') {
                        $docName = 'KK Lama / Surat Keterangan Kehilangan KK';
                    } elseif ($docType === 'foto_selfie') {
                        $docName = 'Foto Selfie Pemohon';
                    } else {
                        $docName = 'Dokumen wajib';
                    }
                    $failedName = basename((string)($_FILES['dokumen']['name'][$i] ?? $docName));
                    $errors[] = "File {$failedName} ({$docName}): " . siprima_upload_error_message($_FILES['dokumen']['error'][$i]);
                } else {
                    $failedName = basename((string)($_FILES['dokumen']['name'][$i] ?? 'dokumen opsional'));
                    $errors[] = "File {$failedName}: " . siprima_upload_error_message($_FILES['dokumen']['error'][$i]);
                }
            }
        }
        
        // Tambahkan file .htaccess untuk keamanan
        $htaccessFile = $user_folder_path . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, 
                "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
        }
    }

    // Cek dokumen wajib
    $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            if ($doc === 'formulir_f101') {
                $missingNames[] = 'Formulir F-1.01 Biodata Keluarga';
            } elseif ($doc === 'kk_lama_atau_surat_kehilangan') {
                $missingNames[] = 'KK Lama / Surat Keterangan Kehilangan KK';
            } elseif ($doc === 'foto_selfie') {
                $missingNames[] = 'Foto Selfie Pemohon';
            }
        }
        $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
    }

    // Formulir dibuat setelah seluruh unggahan final, tetapi sebelum INSERT: berkas ditulis ke
    // $user_folder_path sehingga ikut terhapus oleh pembersihan atomik bila penyimpanan gagal.
    if (FORMULIR_F101_AUTO_ENABLED && empty($errors)) {
        try {
            $formulir_dibuat_at = date('Y-m-d H:i:s');
            $halamanFormulir = siprima_f101_render(
                siprima_f101_data_kartu_keluarga(
                    $_POST,
                    (array)$f101_fields,
                    $anggotaKeluarga,
                    $data_user,
                    $nama_kecamatan_db,
                    $nama_desa_db,
                    $formulir_dibuat_at
                ),
                (string)$f101_signature
            );

            $total = count($halamanFormulir);
            $berkasFormulir = [];
            foreach ($halamanFormulir as $nomor => $jpeg) {
                $namaHalaman = 'formulir_f101_h' . ($nomor + 1) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
                if (file_put_contents($user_folder_path . $namaHalaman, $jpeg) === false) {
                    throw new RuntimeException('Formulir gagal ditulis ke folder permohonan.');
                }
                $berkasFormulir[] = [
                    'path' => 'uploads/kk/' . $folder_name . '/' . $namaHalaman,
                    'type' => 'formulir_f101',
                    'original_name' => 'Formulir F-1.01 Biodata Keluarga — Halaman ' . ($nomor + 1) . ' dari ' . $total . ' (dibuat sistem)',
                    'uploaded_name' => $namaHalaman,
                    'size' => strlen($jpeg),
                    'uploaded_at' => $formulir_dibuat_at,
                    'generated' => true,
                ];
            }
            // Formulir selalu di depan, sama seperti sebelum fitur ini ada.
            $uploadedFiles = array_merge($berkasFormulir, $uploadedFiles);
        } catch (Throwable $e) {
            error_log('Formulir F-1.01 render error: ' . $e->getMessage());
            $errors[] = 'Formulir belum dapat dibuat. Silakan ulangi tanda tangan Anda lalu coba lagi.';
        }
    }

    if (empty($errors)) {
        // Siapkan nilai pemohon dari data user
        $nama_pemohon = $data_user['nama'] ?? '';
        $nik_pemohon = $data_user['nik'] ?? '';
        $no_hp = $data_user['no_hp'] ?? '';
        $email = $data_user['email'] ?? '';

        // Convert uploaded files to JSON string
        $dokumenJson = json_encode($uploadedFiles);
        
        // Convert anggota keluarga ke JSON.
        // JSON yang terpotong membuat SELURUH daftar anggota hilang di semua layar,
        // jadi lebih baik ditolak lebih awal daripada tersimpan rusak.
        $anggotaKeluargaJson = json_encode($anggotaKeluarga);
        if (strlen((string)$anggotaKeluargaJson) > SIPRIMA_F101_MAX_JSON) {
            $errors[] = 'Data anggota keluarga terlalu panjang. Kurangi jumlah anggota atau persingkat isian.';
        }
    }

    if (empty($errors)) {

        // Simpan status dalam variabel terpisah
        $status = 'diajukan';
        
        // Query untuk menyimpan data KK
        $stmt = $koneksi->prepare("INSERT INTO tb_kartukeluarga
            (id_user, id_kecamatan, id_desa, nama_kecamatan, nama_desa, 
             nama_pemohon, nik_pemohon, no_hp, email, dusun, 
             nama_kepalaKeluarga, anggotaKeluarga, keterangan, dokumen, status, created_at, nama_folder,
             jenis_permohonan, formulir_dibuat_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
        
        if (!$stmt) {
            $errors[] = 'Prepare statement error: ' . $koneksi->error;
        } else {
            $stmt->bind_param("iiisssssssssssssss", // 18 parameter, urut sama dengan daftar kolom
                $user_id,           // i (integer)
                $id_kecamatan,      // i (integer)
                $id_desa,           // i (integer)
                $nama_kecamatan_db, // s (string)
                $nama_desa_db,      // s (string)
                $nama_pemohon,      // s (string)
                $nik_pemohon,       // s (string)
                $no_hp,             // s (string)
                $email,             // s (string)
                $dusun,             // s (string)
                $nama_kepalaKeluarga, // s (string)
                $anggotaKeluargaJson, // s (string - JSON)
                $keterangan,        // s (string)
                $dokumenJson,       // s (string - JSON)
                $status,            // s (string)
                $folder_name,       // s (string - nama folder)
                $jenis_permohonan,  // s (string|null) - whitelist F-1.01
                $formulir_dibuat_at // s (string|null) - saat pemohon menyetujui & menandatangani
            );
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Permohonan Kartu Keluarga berhasil diajukan.';
                header('Location: ajukan_permohonan.php');
                exit;
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
                
                // Jika gagal insert, hapus folder yang sudah dibuat (opsional)
                if (is_dir($user_folder_path)) {
                    // Hapus semua file dalam folder
                    array_map('unlink', glob($user_folder_path . '/*'));
                    // Hapus folder
                    rmdir($user_folder_path);
                }
            }
        }
    }
    
    if (!empty($errors) && isset($user_folder_path) && is_dir($user_folder_path)) {
        siprima_remove_upload_tree($user_folder_path, $base_upload_dir);
        $uploadedFiles = [];
    }

    // Set selected values for form repopulation
    $selected_kecamatan = $id_kecamatan;
    $selected_desa = $id_desa;
}

// Ambil nama kecamatan dan desa untuk form repopulation
if (!empty($selected_kecamatan)) {
    $qk2 = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
    $qk2->bind_param("i", $selected_kecamatan);
    $qk2->execute();
    $rk2 = $qk2->get_result()->fetch_assoc();
    if ($rk2) $pre_nama_kecamatan = $rk2['nama_kecamatan'];
}
if (!empty($selected_desa)) {
    $qd2 = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
    $qd2->bind_param("i", $selected_desa);
    $qd2->execute();
    $rd2 = $qd2->get_result()->fetch_assoc();
    if ($rd2) $pre_nama_desa = $rd2['nama_desa'];
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

function uploadErrorMessage($errorCode) {
    switch ((int)$errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Ukuran file melebihi batas upload server/form.';
        case UPLOAD_ERR_PARTIAL:
            return 'File hanya terupload sebagian. Silakan coba lagi.';
        case UPLOAD_ERR_NO_FILE:
            return 'Tidak ada file yang dipilih.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Folder temporary upload tidak tersedia di server.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server gagal menulis file ke disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload dihentikan oleh ekstensi PHP di server.';
        default:
            return 'Terjadi kesalahan upload yang tidak diketahui.';
    }
}

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ajukan Kartu Keluarga</title>
  <link rel="stylesheet" href="css/kk.css">
  <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-line">

  <div class="layout">

    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header user-general-header">
        <?php
        require_once __DIR__ . '/user_header_brand.php';
        render_user_header_brand('Ajukan Kartu Keluarga');
        ?>
        <div class="user-info">
          Hai, <?= esc($_SESSION['nama']) ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <div class="form-card">
          <div class="form-header">
            <div class="icon-container">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Form Pengajuan Kartu Keluarga</h2>
              <p class="helper">Lengkapi data keluarga dan unggah dokumen pendukung.</p>
            </div>
          </div>

          <?php
            // Flash success from redirect (if any)
            $flash_success = null;
            if (isset($_SESSION['success_message'])) {
                $flash_success = $_SESSION['success_message'];
                unset($_SESSION['success_message']);
            }
          ?>

          <?php if (!empty($flash_success)): ?>
            <div class="toast toast-success">
              <?= esc($flash_success) ?>
            </div>
            <script>if (window._userUtils && window._userUtils.removeToasts) { window._userUtils.removeToasts(5000); }</script>
          <?php endif; ?>

          <?php if (!empty($errors)): ?>
            <div class="toast toast-error">
              <?= esc($errors[0]) ?>
            </div>
            <script>if (window._userUtils && window._userUtils.removeToasts) { window._userUtils.removeToasts(5000); }</script>

            <div class="error-box">
              <?php foreach ($errors as $err) echo "<p>" . esc($err) . "</p>"; ?>
              <p>Data teks tetap tersimpan. Demi keamanan browser, pilih ulang file yang disebut gagal di atas.</p>
            </div>
          <?php endif; ?>

          <form method="POST" enctype="multipart/form-data" class="space-y-4" id="kkForm">
<?php echo csrf_input(); ?>

            <!-- Data Pemohon (otomatis terisi) -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Pemohon</label>
                <input type="text" value="<?= esc($data_user['nama'] ?? '') ?>" readonly class="input bg-gray-100">
                <input type="hidden" name="nama_pemohon" value="<?= esc($data_user['nama'] ?? '') ?>">
              </div>

              <div>
                <label class="field-label">NIK Pemohon</label>
                <input type="text" value="<?= esc($data_user['nik'] ?? '') ?>" readonly class="input bg-gray-100">
                <input type="hidden" name="nik_pemohon" value="<?= esc($data_user['nik'] ?? '') ?>">
              </div>

              <div>
                <label class="field-label">No HP</label>
                <input type="text" value="<?= esc($data_user['no_hp'] ?? '') ?>" readonly class="input bg-gray-100">
                <input type="hidden" name="no_hp" value="<?= esc($data_user['no_hp'] ?? '') ?>">
              </div>

              <div>
                <label class="field-label">Email</label>
                <input type="email" value="<?= esc($data_user['email'] ?? '') ?>" readonly class="input bg-gray-100">
                <input type="hidden" name="email" value="<?= esc($data_user['email'] ?? '') ?>">
              </div>
            </div>

            <!-- Kecamatan & Desa -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Kecamatan <span style="color:red">*</span></label>
                <select name="id_kecamatan" id="kecamatanSelect" required class="select">
                  <option value="">-- Pilih Kecamatan --</option>
                  <?php
                  $kec = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
                  while ($row = $kec->fetch_assoc()) {
                      $sel = ($selected_kecamatan == $row['id']) ? ' selected' : '';
                      echo "<option value='" . esc($row['id']) . "'{$sel}>" . esc($row['nama_kecamatan']) . "</option>";
                  }
                  ?>
                </select>
              </div>

              <div>
                <label class="field-label">Desa <span style="color:red">*</span></label>
                <select name="id_desa" id="desaSelect" required class="select">
                  <option value="">-- Pilih Desa --</option>
                <?php
                if (!empty($selected_kecamatan)) {
                    $des = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
                    $des->bind_param("i", $selected_kecamatan);
                    $des->execute();
                    $resDes = $des->get_result();
                    while ($drow = $resDes->fetch_assoc()) {
                        $selD = ($selected_desa == $drow['id']) ? ' selected' : '';
                        echo "<option value='" . esc($drow['id']) . "'{$selD}>" . esc($drow['nama_desa']) . "</option>";
                    }
                }
                ?>
                </select>
              </div>
            </div>

            <!-- Nama Kecamatan & Desa (otomatis terisi) -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Kecamatan</label>
                <input type="text" id="namaKecamatan" readonly class="input bg-gray-100" value="<?= esc($pre_nama_kecamatan ?? '') ?>">
              </div>

              <div>
                <label class="field-label">Nama Desa</label>
                <input type="text" id="namaDesa" readonly class="input bg-gray-100" value="<?= esc($pre_nama_desa ?? '') ?>">
              </div>
            </div>

            <?php if (FORMULIR_F101_AUTO_ENABLED): ?>
            <div>
              <label class="field-label">Jenis Permohonan <span style="color:red">*</span></label>
              <select name="jenis_permohonan" class="input" required>
                <option value="">-- Pilih --</option>
                <?php foreach (siprima_f101_jenis_permohonan_pilihan() as $opsi): ?>
                <option value="<?= esc($opsi) ?>" <?= (($_POST['jenis_permohonan'] ?? '') === $opsi) ? 'selected' : '' ?>><?= esc($opsi) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="helper">Dicetak di formulir agar operator langsung tahu maksud permohonan Anda.</p>
            </div>
            <?php endif; ?>

            <!-- Data Keluarga -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Alamat/Dusun <span style="color:red">*</span></label>
                <input type="text" name="dusun" value="<?= esc($_POST['dusun'] ?? '') ?>" required class="input" placeholder="Contoh: Dusun Suka Makmur">
              </div>

              <div>
                <label class="field-label">Nama Kepala Keluarga <span style="color:red">*</span></label>
                <input type="text" name="nama_kepalaKeluarga" value="<?= esc($_POST['nama_kepalaKeluarga'] ?? '') ?>" required class="input" placeholder="Nama lengkap kepala keluarga">
              </div>
            </div>

            <!-- Anggota Keluarga (Input Dinamis) -->
            <div>
              <label class="field-label">Anggota Keluarga <span style="color:red">*</span></label>

              <?php if (FORMULIR_F101_AUTO_ENABLED): ?>
              <?php
              $anggotaAwal = siprima_f101_anggota_dari_post($_POST);
              if (!$anggotaAwal) $anggotaAwal = [[]];
              ?>
              <p class="helper" style="margin-top:6px;">
                Isi nama, NIK, dan hubungan keluarga. Untuk mengubah data seperti golongan darah,
                status perkawinan, atau pekerjaan, buka <strong>Ubah data lainnya</strong> pada
                anggota yang bersangkutan. Yang dibiarkan kosong berarti tidak ada perubahan.
              </p>
              <div id="anggotaWadah" style="margin-top:10px;">
                <?php foreach ($anggotaAwal as $i => $a): ?>
                  <?php siprima_f101_kartu_anggota(is_array($a) ? $a : [], $i + 1); ?>
                <?php endforeach; ?>
              </div>
              <template id="anggotaTemplate"><?php siprima_f101_kartu_anggota([], null); ?></template>
              <button type="button" id="btnTambahAnggota" class="btn-add-anggota">
                <i class="fas fa-plus"></i> Tambah Anggota Keluarga
              </button>
              <p id="pesanAnggota" class="helper" style="display:none; color:#b45309;"></p>
              <?php else: ?>

              <!-- Tabel Anggota Keluarga -->
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
                    <!-- Baris pertama wajib ada -->
                    <tr>
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
                        <!-- Baris pertama tidak bisa dihapus -->
                      </td>
                    </tr>
                  </tbody>
                </table>
                <button type="button" class="btn-add-anggota" onclick="addAnggota()">
                  <i class="fas fa-plus"></i> Tambah Anggota Keluarga
                </button>
                <p class="helper">Isi data anggota keluarga termasuk kepala keluarga jika belum termasuk.</p>
              </div>
              <?php endif; ?>
            </div>

            <div>
              <label class="field-label">Keterangan / Pesan untuk Operator</label>
              <textarea name="keterangan" class="input" rows="3" placeholder="Contoh: Permohonan perubahan alamat karena pindah rumah, penambahan anggota keluarga baru, dll." maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>

            <?php if (FORMULIR_F101_AUTO_ENABLED): ?>
            <!-- Pernyataan & tanda tangan: menggantikan blanko F-1.01 yang dulu ditulis tangan -->
            <div id="blokFormulir" style="margin-top: 30px; padding: 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
              <h3 style="margin-top: 0; margin-bottom: 6px; color: #1f2937;">Pernyataan dan Tanda Tangan</h3>
              <p class="helper" style="margin-top: 0;">
                Formulir F-1.01 dibuat otomatis oleh sistem dari isian di atas. Anda tidak perlu lagi
                mengunduh, menulis tangan, mencetak, atau memotret formulir.
              </p>

              <label style="display: flex; gap: 10px; align-items: flex-start; margin: 16px 0; cursor: pointer;">
                <input type="checkbox" id="pernyataanBenar" name="pernyataan_benar" value="1" required
                       style="margin-top: 4px; width: 18px; height: 18px; flex-shrink: 0;"
                       <?= !empty($_POST['pernyataan_benar']) ? 'checked' : '' ?>>
                <span>
                  Demikian formulir ini saya isi dengan sesungguhnya. Apabila keterangan tersebut tidak
                  sesuai dengan keadaan sebenarnya, saya bersedia dikenakan sanksi sesuai ketentuan
                  peraturan perundang-undangan yang berlaku.
                  <span style="color:red">*</span>
                </span>
              </label>

              <div id="areaTtd" style="opacity: .5; pointer-events: none;">
                <label class="field-label">Tanda Tangan Pemohon <span style="color:red">*</span></label>
                <p class="helper" style="margin-top: 0;">Tanda tangani di dalam kotak menggunakan jari atau tetikus.</p>
                <canvas id="kanvasTtd" width="600" height="200"
                        style="width: 100%; max-width: 600px; height: auto; aspect-ratio: 3 / 1; background: #fff; border: 2px dashed #94a3b8; border-radius: 8px; touch-action: none; display: block;"></canvas>
                <input type="hidden" id="ttdPemohon" name="ttd_pemohon" value="<?= esc($_POST['ttd_pemohon'] ?? '') ?>">
                <div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                  <button type="button" id="btnHapusTtd" class="btn-secondary">Hapus Tanda Tangan</button>
                  <button type="button" id="btnPratinjauFormulir" class="btn-secondary">Lihat Pratinjau Formulir</button>
                </div>
              </div>

              <div id="kotakPratinjau" style="display: none; margin-top: 16px;">
                <p class="helper" style="margin-top: 0;">Pratinjau halaman pertama formulir yang akan dikirim:</p>
                <img id="gambarPratinjau" alt="Pratinjau formulir F-1.01"
                     style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px;">
              </div>
              <div id="pesanPratinjau" style="display: none; margin-top: 12px; padding: 12px; background: #fef2f2; border-left: 4px solid #ef4444; border-radius: 6px; color: #991b1b;"></div>
            </div>
            <?php endif; ?>
            </div>

            <!-- File Upload Multiple -->
            <div>
              <label class="field-label">Unggah Dokumen Pendukung</label>
              
              <!-- Dokumen WAJIB -->
              <div class="f-doc-group">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                </h4>
                <div id="wajibUploadContainer">
                  <?php if (!FORMULIR_F101_AUTO_ENABLED): ?>
                  <!-- Formulir F-1.01 (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="formulir_f101" selected>Formulir F-1.01 Biodata Keluarga</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Pilih File</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Formulir F-1.01 yang sudah diisi dan ditandatangani. Unduh formulir
                      <a href="https://siprima.gorontalokab.go.id/uploads/formulir/1766758287_694e978f1cc6b.pdf" target="_blank" rel="noopener noreferrer">klik disini</a>
                    </p>
                  </div>
                  <?php endif; ?>
                  
                  <!-- KK Lama atau Surat Kehilangan (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib2">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="kk_lama_atau_surat_kehilangan" selected>KK Lama / Surat Keterangan Kehilangan KK</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Pilih File</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Pilih salah satu: KK Lama <strong>ATAU</strong> Surat Keterangan Kehilangan dari kepolisian
                    </p>
                  </div>

                  <!-- Foto Selfie Pemohon (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib3">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="foto_selfie" selected>Foto Selfie Pemohon Memegang Formulir</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib3">Upload Foto Selfie</label>
                      <span id="fileNameWajib3" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib3" name="dokumen[]" accept="image/jpeg,image/png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib3">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Upload foto selfie Pemohon yang memegang Formulir F-1.01
                    </p>
                  </div>
                </div>
              </div>
              
              <!-- Dokumen OPSIONAL -->
              <div class="f-doc-group f-doc-group--opsional">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-check-circle"></i> Dokumen Opsional (Jika Ada)
                </h4>
                <div id="opsionalUploadContainer">
                  <!-- Input opsional pertama -->
                  <div class="file-input-group" id="fileGroupOpsional1">
                    <select name="doc_type[]" class="file-type-select">
                      <option value="">Pilih Jenis Dokumen (Opsional)</option>
                      <option value="ktp">KTP</option>
                      <option value="surat_nikah">Buku Nikah/Akta Nikah/Akta Cerai</option>
                      <option value="akta_lahir">Akta Lahir</option>
                      <option value="akta_kematian">Akta Kematian</option>
                      <option value="surat_pindah">Surat Pindah Datang</option>
                      <option value="lainnya">Dokumen Lainnya</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputOpsional1">Pilih File</label>
                      <span id="fileNameOpsional1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputOpsional1" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeOpsional1">Maksimal 5 MB per file (Opsional, dikompres otomatis)</div>
                  </div>
                </div>
                
                <button type="button" class="btn-secondary mt-2" onclick="addOptionalFileInput()">
                  <i class="fas fa-plus"></i> Tambah Dokumen Opsional Lainnya
                </button>
              </div>
              
              <p class="helper">
                <strong>Dokumen WAJIB:</strong> 
                1. Formulir F-1.01 Biodata Keluarga yang sudah diisi, 
                2. KK Lama <strong>ATAU</strong> Surat Keterangan Kehilangan KK dari kepolisian,
                3. Foto selfie pemohon.<br>
                <strong>Format:</strong> JPG, JPEG, JFIF, JPE, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong>
              </p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="kk_pilih_jenis.php" class="btn-secondary">Kembali</a>
              <button type="submit" class="btn-primary">Ajukan Permohonan</button>
            </div>
          </form>
        </div>
      </section>
    </main>
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
  });

  // AJAX untuk Desa & Auto-fill Nama
  document.getElementById("kecamatanSelect").addEventListener("change", function() {
    var idKecamatan = this.value;
    var desaSelect = document.getElementById("desaSelect");
    var namaKecamatanInput = document.getElementById("namaKecamatan");
    var namaDesaInput = document.getElementById("namaDesa");
    desaSelect.innerHTML = "<option value=''>-- Pilih Desa --</option>";
    namaDesaInput.value = "";

    if (idKecamatan !== "") {
      // tampilkan nama kecamatan (teks option)
      namaKecamatanInput.value = this.options[this.selectedIndex].text;

      fetch("get_desa.php?id_kecamatan=" + idKecamatan)
        .then(response => response.text())
        .then(data => {
          desaSelect.innerHTML += data;
        })
        .catch(error => console.error('Error:', error));
    } else {
      namaKecamatanInput.value = "";
    }
  });

  document.getElementById("desaSelect").addEventListener("change", function() {
    var namaDesaInput = document.getElementById("namaDesa");
    if (this.value !== "") {
      namaDesaInput.value = this.options[this.selectedIndex].text;
    } else {
      namaDesaInput.value = "";
    }
  });

  // Set initial values dari server jika ada
  window.addEventListener("DOMContentLoaded", function() {
    var preKec = "<?= addslashes($pre_nama_kecamatan ?? '') ?>";
    var preDes = "<?= addslashes($pre_nama_desa ?? '') ?>";
    if (preKec) document.getElementById("namaKecamatan").value = preKec;
    if (preDes) document.getElementById("namaDesa").value = preDes;
  });

  // Fungsi Anggota Keluarga
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
    const tableBody = document.getElementById('anggotaBody');
    const rows = tableBody.querySelectorAll('tr');
    
    // Pastikan minimal ada 1 baris
    if (rows.length > 1) {
      row.remove();
    } else {
      alert('Minimal harus ada satu anggota keluarga');
    }
  }

  // Multiple file upload functionality dengan validasi ukuran 1MB
  let fileCounter = {
    wajib: 3,
    opsional: 1
  };
  const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB

  function formatFileSize(bytes) {
    if (bytes >= 1048576) {
      return (bytes / 1048576).toFixed(2) + ' MB';
    } else if (bytes >= 1024) {
      return (bytes / 1024).toFixed(2) + ' KB';
    } else {
      return bytes + ' Bytes';
    }
  }

  function addOptionalFileInput() {
    fileCounter.opsional++;
    const container = document.getElementById('opsionalUploadContainer');
    const newFileGroup = document.createElement('div');
    newFileGroup.className = 'file-input-group';
    newFileGroup.id = 'fileGroupOpsional' + fileCounter.opsional;
    newFileGroup.innerHTML = `
      <select name="doc_type[]" class="file-type-select">
        <option value="">Pilih Jenis Dokumen (Opsional)</option>
        <option value="ktp">KTP</option>
        <option value="surat_nikah">Buku Nikah/Akta Kawin/Akta Cerai</option>
        <option value="akta_lahir">Akta Lahir</option>
        <option value="akta_kematian">Akta Kematian</option>
        <option value="surat_pindah">Surat Pindah Datang</option>
        <option value="lainnya">Dokumen Lainnya</option>
      </select>
      <div class="file-wrapper">
        <label class="file-label" for="fileInputOpsional${fileCounter.opsional}">Pilih File</label>
        <span id="fileNameOpsional${fileCounter.opsional}" class="file-name">Belum ada file dipilih</span>
        <input type="file" id="fileInputOpsional${fileCounter.opsional}" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" class="file-input">
      </div>
      <div class="file-size-warning" id="fileSizeOpsional${fileCounter.opsional}">Maksimal 5 MB per file (Opsional, dikompres otomatis)</div>
      <button type="button" class="btn-remove" onclick="removeOptionalFileInput(${fileCounter.opsional})">Hapus</button>
    `;
    container.appendChild(newFileGroup);
    
    // Add event listener to new file input
    const newFileInput = document.getElementById('fileInputOpsional' + fileCounter.opsional);
    const newFileNameSpan = document.getElementById('fileNameOpsional' + fileCounter.opsional);
    const newFileSizeSpan = document.getElementById('fileSizeOpsional' + fileCounter.opsional);
    
    if (newFileInput && newFileNameSpan && newFileSizeSpan) {
      newFileInput.addEventListener('change', function(e) {
        if (this.files.length > 0) {
          const file = this.files[0];
          newFileNameSpan.textContent = file.name;
          
          // Validasi ukuran file client-side
          if (file.size > MAX_FILE_SIZE) {
            newFileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (gagal dikompres, pilih gambar lain)</span>`;
            this.value = ''; // Clear file input
            newFileNameSpan.textContent = 'File terlalu besar, pilih file lain';
          } else {
            newFileSizeSpan.innerHTML = `Ukuran: ${formatFileSize(file.size)}`;
          }
        } else {
          newFileNameSpan.textContent = 'Belum ada file dipilih';
          newFileSizeSpan.innerHTML = 'Maksimal 5 MB per file (Opsional, dikompres otomatis)';
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

  // Initialize file input event listeners untuk dokumen wajib dan opsional
  document.addEventListener('DOMContentLoaded', function() {
    // Dokumen wajib
    for (let i = 1; i <= fileCounter.wajib; i++) {
      const input = document.getElementById('fileInputWajib' + i);
      const fileNameSpan = document.getElementById('fileNameWajib' + i);
      const fileSizeSpan = document.getElementById('fileSizeWajib' + i);
      
      if (input && fileNameSpan && fileSizeSpan) {
        input.addEventListener('change', function(e) {
          if (this.files.length > 0) {
            const file = this.files[0];
            fileNameSpan.textContent = file.name;
            
            // Validasi ukuran file client-side
            if (file.size > MAX_FILE_SIZE) {
              fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (gagal dikompres, pilih gambar lain)</span>`;
              this.value = ''; // Clear file input
              fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
            } else {
              fileSizeSpan.innerHTML = `Ukuran: ${formatFileSize(file.size)}`;
            }
          } else {
            fileNameSpan.textContent = 'Belum ada file dipilih';
            fileSizeSpan.innerHTML = 'Maksimal 5 MB per file, dikompres otomatis';
          }
        });
      }
    }
    
    // Dokumen opsional
    for (let i = 1; i <= fileCounter.opsional; i++) {
      const input = document.getElementById('fileInputOpsional' + i);
      const fileNameSpan = document.getElementById('fileNameOpsional' + i);
      const fileSizeSpan = document.getElementById('fileSizeOpsional' + i);
      
      if (input && fileNameSpan && fileSizeSpan) {
        input.addEventListener('change', function(e) {
          if (this.files.length > 0) {
            const file = this.files[0];
            fileNameSpan.textContent = file.name;
            
            // Validasi ukuran file client-side
            if (file.size > MAX_FILE_SIZE) {
              fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (gagal dikompres, pilih gambar lain)</span>`;
              this.value = ''; // Clear file input
              fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
            } else {
              fileSizeSpan.innerHTML = `Ukuran: ${formatFileSize(file.size)}`;
            }
          } else {
            fileNameSpan.textContent = 'Belum ada file dipilih';
            fileSizeSpan.innerHTML = 'Maksimal 5 MB per file (Opsional, dikompres otomatis)';
          }
        });
      }
    }
  });
  // Chrome Android dapat mencabut akses URI dokumen beberapa saat setelah
  // pemilih file ditutup. Simpan salinan Blob selagi akses masih tersedia,
  // lalu gunakan salinan tersebut ketika form dikirim.
  const stableFileCopies = new WeakMap();

  document.addEventListener('change', function(e) {
    const input = e.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;

    const file = input.files && input.files[0];
    if (!file) {
      stableFileCopies.delete(input);
      return;
    }

    const copyPromise = (async function() {
      const bytes = typeof file.arrayBuffer === 'function'
        ? await file.arrayBuffer()
        : await new Promise(function(resolve, reject) {
            const reader = new FileReader();
            reader.onload = function() { resolve(reader.result); };
            reader.onerror = function() { reject(reader.error || new Error('File tidak dapat dibaca.')); };
            reader.onabort = function() { reject(new Error('Pembacaan file dibatalkan.')); };
            reader.readAsArrayBuffer(file);
          });

      return {
        blob: new Blob([bytes], { type: file.type || 'application/octet-stream' }),
        name: file.name || 'dokumen'
      };
    })();

    // Simpan Promise segera agar submit dapat menunggu proses penyalinan yang
    // mungkin masih berjalan. Catch dipasang sekarang agar tidak memunculkan
    // unhandled rejection sebelum pengguna menekan tombol Ajukan.
    copyPromise.catch(function() {});
    stableFileCopies.set(input, { file: file, promise: copyPromise });
  }, true);

  // Validasi form sebelum submit
  document.getElementById('kkForm').addEventListener('submit', async function(e) {
    // Hindari submit multipart native yang dapat memicu
    // ERR_UPLOAD_FILE_CHANGED pada Chrome Android. Berkas akan disalin ke
    // Blob stabil sebelum dikirim.
    e.preventDefault();

    // Cegah double submit yang sering terjadi di perangkat mobile.
    if (this.dataset.submitting === '1') {
      e.preventDefault();
      return;
    }

    const allFileInputs = document.querySelectorAll('input[type="file"]');
    // Kartu saat formulir dibuat sistem, baris tabel pada tampilan lama.
    const anggotaRows = document.querySelectorAll('#anggotaWadah .anggota-kartu, #anggotaBody tr');
    const submitButton = this.querySelector('button[type="submit"]');
    let hasError = false;
    let errorMessages = [];
    
    // Validasi ukuran file
    allFileInputs.forEach((input) => {
      if (input.value && input.files.length === 0) {
        errorMessages.push('Ada file yang tidak dapat diakses dari browser. Pilih ulang file tersebut.');
        hasError = true;
        return;
      }
      if (input.files.length > 0) {
        const file = input.files[0];
        if (file.size > MAX_FILE_SIZE) {
          errorMessages.push(`File "${file.name}" belum berhasil dikompres, pilih ulang file tersebut`);
          hasError = true;
        }
      }
    });
    
    // Cek dokumen wajib (Formulir F-1.01 dan KK Lama/Surat Kehilangan)
    const formulirInput = document.getElementById('fileInputWajib1');
    const kkInput = document.getElementById('fileInputWajib2');
    const selfieInput = document.getElementById('fileInputWajib3');
    
    // formulirInput tidak ada saat formulir dibuat sistem.
    if (formulirInput && !formulirInput.files.length) {
      errorMessages.push('Formulir F-1.01 Biodata Keluarga wajib diupload');
      hasError = true;
    }
    
    if (!kkInput.files.length) {
      errorMessages.push('KK Lama atau Surat Keterangan Kehilangan KK wajib diupload');
      hasError = true;
    }

    if (!selfieInput.files.length) {
      errorMessages.push('Foto selfie pemohon wajib diupload');
      hasError = true;
    }
    
    // Validasi anggota keluarga
    let hasAnggota = false;
    anggotaRows.forEach(row => {
      const namaInput = row.querySelector('input[name="anggota_nama[]"]');
      if (namaInput && namaInput.value.trim()) {
        hasAnggota = true;
      }
    });
    
    if (!hasAnggota) {
      errorMessages.push('Minimal harus ada satu anggota keluarga');
      hasError = true;
    }

    // Pernyataan dan tanda tangan hanya ada saat formulir dibuat sistem.
    const cekPernyataan = document.getElementById('pernyataanBenar');
    if (cekPernyataan && !cekPernyataan.checked) {
      errorMessages.push('Centang pernyataan kebenaran data terlebih dahulu');
      hasError = true;
    }
    const inputTtd = document.getElementById('ttdPemohon');
    if (inputTtd && !inputTtd.value) {
      errorMessages.push('Tanda tangan pemohon wajib diisi');
      hasError = true;
    }
    
    // Validasi required fields (selain file)
    const requiredFields = this.querySelectorAll('[required]:not(input[type="file"])');
    requiredFields.forEach(field => {
      if (!field.value.trim()) {
        field.style.borderColor = '#ef4444';
        hasError = true;
      } else {
        field.style.borderColor = '#d1d5db';
      }
    });
    
    if (hasError) {
      let message = 'Perbaiki kesalahan berikut:\n';
      if (errorMessages.length > 0) {
        message += '• ' + errorMessages.join('\n• ');
      } else {
        message += '• Harap lengkapi semua field yang wajib diisi';
      }
      alert(message);
    } else {
      // Konfirmasi sebelum submit
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan?\n\nPastikan Anda telah mengupload:\n1. Formulir F-1.01 Biodata Keluarga\n2. KK Lama atau Surat Kehilangan KK\n3. Foto selfie pemohon');
      if (!confirmSubmit) {
        return;
      }

      this.dataset.submitting = '1';
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Menyiapkan berkas...';
      }

      try {
        const formData = new FormData(this);
        const stableFiles = [];

        for (const input of allFileInputs) {
          const file = input.files && input.files[0];
          if (!file || file.size === 0) continue;

          let cached = stableFileCopies.get(input);
          // File hasil kompresi dapat mengganti objek File melalui DataTransfer.
          // Buat salinan baru bila cache masih menunjuk ke file sebelumnya.
          if (!cached || cached.file !== file) {
            const bytes = await file.arrayBuffer();
            cached = {
              file: file,
              promise: Promise.resolve({
                blob: new Blob([bytes], { type: file.type || 'application/octet-stream' }),
                name: file.name || 'dokumen'
              })
            };
            stableFileCopies.set(input, cached);
          }
          stableFiles.push(await cached.promise);
        }

        formData.delete('dokumen[]');
        for (const file of stableFiles) {
          formData.append('dokumen[]', file.blob, file.name);
        }

        const uploader = window.SiprimaStableUpload && window.SiprimaStableUpload.uploadFormData;
        const response = typeof uploader === 'function'
          ? await uploader(this.action || window.location.href, 'POST', formData, function(percent) {
              if (submitButton) {
                submitButton.textContent = percent < 100
                  ? `Mengunggah ${percent}%`
                  : 'Menyimpan permohonan...';
              }
            })
          : await fetch(this.action || window.location.href, {
              method: 'POST',
              body: formData,
              credentials: 'same-origin',
              redirect: 'follow'
            });

        if (!response.ok) {
          throw new Error(`Server merespons dengan status ${response.status}`);
        }

        const html = typeof response.text === 'function' ? await response.text() : response.text;
        if (response.url) {
          window.history.replaceState(null, '', response.url);
        }
        document.open();
        document.write(html);
        document.close();
      } catch (error) {
        console.error('Gagal membaca atau mengunggah dokumen KK:', error);
        this.dataset.submitting = '0';
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = 'Ajukan Permohonan';
        }
        alert('Salah satu dokumen tidak dapat dibaca oleh perangkat. Pilih ulang dokumen tersebut dari folder Download, lalu segera tekan Ajukan Permohonan. Data formulir Anda tidak hilang.');
      }
    }
  });
  </script>
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php if (FORMULIR_F101_AUTO_ENABLED): ?>
<script src="../assets/js/anggota_kk.js?v=20260920-1"></script>
<script src="../assets/js/formulir_ttd.js?v=20260919-1" data-form="kkForm" data-pratinjau="pratinjau_formulir_kk.php"></script>
<?php endif; ?>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>
