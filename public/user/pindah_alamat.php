<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
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
$stmt_user = $koneksi->prepare("SELECT nama, nik, no_hp, email FROM tb_users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();

if (!$result_user || $result_user->num_rows === 0) {
    header('Location: ../login.php?error=user_not_found');
    exit;
}

$data_user = $result_user->fetch_assoc();
$stmt_user->close();

$errors = [];
$success_message = '';

// Untuk mempertahankan pilihan setelah submit gagal
$selected_kecamatan_asal = isset($_POST['id_kecamatan_asal']) ? (int)$_POST['id_kecamatan_asal'] : '';
$selected_desa_asal = isset($_POST['id_desa_asal']) ? (int)$_POST['id_desa_asal'] : '';
$pre_nama_kecamatan_asal = '';
$pre_nama_desa_asal = '';

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi dan sanitasi input
    $id_kecamatan_asal = isset($_POST['id_kecamatan_asal']) ? (int)$_POST['id_kecamatan_asal'] : 0;
    $id_desa_asal = isset($_POST['id_desa_asal']) ? (int)$_POST['id_desa_asal'] : 0;
    
    // Data pindah alamat
    $no_kk = trim($_POST['no_kk'] ?? '');
    $nama_kepalakeluarga = trim($_POST['nama_kepalakeluarga'] ?? '');
    $alamat_asal = trim($_POST['alamat_asal'] ?? '');
    $alamat_tujuan = trim($_POST['alamat_tujuan'] ?? '');
    $alasan_pindah = trim($_POST['alasan_pindah'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Proses data anggota keluarga dari input dinamis
    $anggotaKeluarga = [];
    if (isset($_POST['anggota_nama']) && is_array($_POST['anggota_nama'])) {
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

    // Basic validation
    if (empty($no_kk)) $errors[] = 'Nomor Kartu Keluarga (No KK) wajib diisi.';
    if (strlen($no_kk) !== 16) $errors[] = 'Nomor Kartu Keluarga harus 16 digit.';
    if (empty($nama_kepalakeluarga)) $errors[] = 'Nama Kepala Keluarga wajib diisi.';
    if (empty($alamat_asal)) $errors[] = 'Alamat asal wajib diisi.';
    if (empty($alamat_tujuan)) $errors[] = 'Alamat tujuan wajib diisi.';
    if (empty($alasan_pindah)) $errors[] = 'Alasan pindah wajib diisi.';
    if (empty($anggotaKeluarga)) $errors[] = 'Minimal harus ada satu anggota keluarga yang pindah.';
    if ($id_kecamatan_asal <= 0) $errors[] = 'Kecamatan asal wajib dipilih.';
    if ($id_desa_asal <= 0) $errors[] = 'Desa / Kelurahan asal wajib dipilih.';

    // Ambil nama kecamatan/desa asal untuk pembuatan folder
    $nama_kecamatan_asal = '';
    $nama_desa_asal = '';
    if (!empty($id_kecamatan_asal)) {
        $qk = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $qk->bind_param("i", $id_kecamatan_asal);
        $qk->execute();
        $rk = $qk->get_result()->fetch_assoc();
        if ($rk) $nama_kecamatan_asal = $rk['nama_kecamatan'];
        $qk->close();
    }
    
    if (!empty($id_desa_asal)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $qd->bind_param("i", $id_desa_asal);
        $qd->execute();
        $rd = $qd->get_result()->fetch_assoc();
        if ($rd) $nama_desa_asal = $rd['nama_desa'];
        $qd->close();
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
    $kecamatan_clean = cleanFolderName($nama_kecamatan_asal);
    $desa_clean = cleanFolderName($nama_desa_asal);
    $pemohon_clean = cleanFolderName($nama_pemohon);
    $kepala_keluarga_clean = cleanFolderName($nama_kepalakeluarga);
    
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
    $base_upload_dir = __DIR__ . '/uploads/pindah/';
    
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

    // File upload - MULTIPLE FILES, maksimal 1MB SETELAH kompresi otomatis di sisi klien
    $uploadedFiles = [];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Dokumen WAJIB sesuai permintaan
    $requiredDocs = ['formulir_f103', 'kk_ktp_hilang', 'foto_pemohon'];
    
    // Dokumen OPSIONAL
    $optionalDocs = ['buku_nikah', 'akta_lahir', 'surat_pindah', 'surat_kuasa', 'lainnya'];
    
    $uploadedDocTypes = [];
    
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name']) && empty($errors)) {
        $fileCount = count($_FILES['dokumen']['name']);
        // Dapatkan array doc_type dan file_index dari POST untuk mapping yang benar
        $doc_types = isset($_POST['doc_type']) && is_array($_POST['doc_type']) ? $_POST['doc_type'] : [];
        $file_indexes = isset($_POST['file_index']) && is_array($_POST['file_index']) ? $_POST['file_index'] : [];
        
        // PENTING: Kita perlu memetakan file dengan file_index untuk mendapatkan doc_type yang benar
        // Buat mapping dari file_index ke doc_type
        $index_to_doctype = [];
        foreach ($file_indexes as $i => $idx) {
            if (isset($doc_types[$i])) {
                $index_to_doctype[(int)$idx] = $doc_types[$i];
            }
        }
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $fileSize = $_FILES['dokumen']['size'][$i];
                // PERBAIKAN: Gunakan file_index yang sesuai untuk mendapatkan doc_type yang benar
                // Jika file_index ada, gunakan mapping, jika tidak gunakan urutan biasa
                $docType = isset($file_indexes[$i]) && isset($index_to_doctype[(int)$file_indexes[$i]]) 
                    ? $index_to_doctype[(int)$file_indexes[$i]]
                    : (isset($doc_types[$i]) ? $doc_types[$i] : 'lainnya');

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
                        'path' => 'uploads/pindah/' . $folder_name . '/' . $newName,
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
                $failedName = basename((string)($_FILES['dokumen']['name'][$i] ?? 'dokumen'));
                $errors[] = "File {$failedName}: " . siprima_upload_error_message($_FILES['dokumen']['error'][$i]);
                // Cek apakah file ini wajib
                $docType = isset($file_indexes[$i]) && isset($index_to_doctype[(int)$file_indexes[$i]]) 
                    ? $index_to_doctype[(int)$file_indexes[$i]]
                    : (isset($doc_types[$i]) ? $doc_types[$i] : '');
                if (in_array($docType, $requiredDocs)) {
                    $docName = '';
                    switch ($docType) {
                        case 'formulir_f103': $docName = 'Formulir Pindah F-1.03 Pindah Penduduk'; break;
                        case 'kk_ktp_hilang': $docName = 'KK/KTP atau Surat Keterangan Hilang KK/KTP'; break;
                        case 'foto_pemohon': $docName = 'Foto Pemohon Memegang KTP/KK/Form Pindah'; break;
                    }
                    if ($docName) {
                        $errors[] = "{$docName} wajib diupload";
                    }
                }
            }
        }
        
        // Tambahkan file .htaccess untuk keamanan
        $htaccessFile = $user_folder_path . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, 
                "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png)$\">\nAllow from all\n</Files>");
        }
    }

    // Cek dokumen wajib
    $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            switch ($doc) {
                case 'formulir_f103':
                    $missingNames[] = 'Formulir Pindah F-1.03 Pindah Penduduk';
                    break;
                case 'kk_ktp_hilang':
                    $missingNames[] = 'KK/KTP atau Surat Keterangan Hilang KK/KTP';
                    break;
                case 'foto_pemohon':
                    $missingNames[] = 'Foto Pemohon Memegang KTP/KK/Form Pindah';
                    break;
            }
        }
        $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
    }

    if (empty($errors)) {
        // Siapkan nilai pemohon dari data user
        $nama_pemohon = $data_user['nama'] ?? '';
        $nik_pemohon = $data_user['nik'] ?? '';
        $no_hp = $data_user['no_hp'] ?? '';
        $email = $data_user['email'] ?? '';

        // Convert uploaded files to JSON string
        $dokumenJson = json_encode($uploadedFiles);
        
        // Convert anggota keluarga ke JSON
        $anggotaKeluargaJson = json_encode($anggotaKeluarga);

        // Simpan status dalam variabel terpisah
        $status = 'diajukan';
        
        // Kolom alasan dikosongkan untuk diisi oleh operator jika ditolak
        $alasan = '';
        
        // Query untuk insert data pindah alamat dengan tambahan kolom nama_folder
        $stmt = $koneksi->prepare("INSERT INTO tb_pindah_alamat
            (id_user, id_kecamatan, id_desa, nama_pemohon, nik_pemohon, no_hp, email,
             nama_kecamatan, nama_desa, no_kk, nama_kepalakeluarga, alamat_asal, 
             alamat_tujuan, alasan_pindah, anggotaKeluarga, dokumen, status, 
             alasan, keterangan, created_at, nama_folder)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
        
        if (!$stmt) {
            $errors[] = 'Prepare statement error: ' . $koneksi->error;
        } else {
            $stmt->bind_param("iiisssssssssssssssss",
                $user_id,               // i (integer)
                $id_kecamatan_asal,     // i (integer)
                $id_desa_asal,          // i (integer)
                $nama_pemohon,          // s (string)
                $nik_pemohon,           // s (string)
                $no_hp,                 // s (string)
                $email,                 // s (string)
                $nama_kecamatan_asal,   // s (string)
                $nama_desa_asal,        // s (string)
                $no_kk,                 // s (string)
                $nama_kepalakeluarga,   // s (string)
                $alamat_asal,           // s (string)
                $alamat_tujuan,         // s (string)
                $alasan_pindah,         // s (string) - dari dropdown form
                $anggotaKeluargaJson,   // s (string - JSON)
                $dokumenJson,           // s (string - JSON)
                $status,                // s (string) = 'diajukan'
                $alasan,                // s (string) - dikosongkan
                $keterangan,            // s (string)
                $folder_name            // s (string) - nama folder
            );
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Permohonan pindah alamat berhasil diajukan.';
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
            $stmt->close();
        }
    }
    
    if (!empty($errors) && isset($user_folder_path) && is_dir($user_folder_path)) {
        siprima_remove_upload_tree($user_folder_path, $base_upload_dir);
        $uploadedFiles = [];
    }

    // Set selected values for form repopulation
    $selected_kecamatan_asal = $id_kecamatan_asal;
    $selected_desa_asal = $id_desa_asal;
}

// Ambil nama kecamatan dan desa untuk form repopulation
if (!empty($selected_kecamatan_asal)) {
    $qk2 = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
    $qk2->bind_param("i", $selected_kecamatan_asal);
    $qk2->execute();
    $rk2 = $qk2->get_result()->fetch_assoc();
    if ($rk2) $pre_nama_kecamatan_asal = $rk2['nama_kecamatan'];
    $qk2->close();
}

if (!empty($selected_desa_asal)) {
    $qd2 = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
    $qd2->bind_param("i", $selected_desa_asal);
    $qd2->execute();
    $rd2 = $qd2->get_result()->fetch_assoc();
    if ($rd2) $pre_nama_desa_asal = $rd2['nama_desa'];
    $qd2->close();
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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ajukan Pindah Alamat</title>
  <link rel="stylesheet" href="css/pindah_alamat.css">
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
        render_user_header_brand('Ajukan Pindah Alamat');
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
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Form Pengajuan Pindah Alamat</h2>
              <p class="helper">Lengkapi data alamat baru dan unggah dokumen pendukung.</p>
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
            <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>
          <?php endif; ?>

          <?php if (!empty($errors)): ?>
            <div class="toast toast-error">
              <?= esc($errors[0]) ?>
            </div>
            <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>

            <div class="error-box">
              <?php foreach ($errors as $err) echo "<p>" . esc($err) . "</p>"; ?>
              <p>Data teks tetap tersimpan. Demi keamanan browser, pilih ulang file yang disebut gagal di atas.</p>
            </div>
          <?php endif; ?>

          <form method="POST" enctype="multipart/form-data" class="space-y-4" id="pindahForm">
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

            <!-- Data Kartu Keluarga -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Kartu Keluarga</h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nomor Kartu Keluarga (No KK) <span style="color:red">*</span></label>
                <input type="text" name="no_kk" value="<?= esc($_POST['no_kk'] ?? '') ?>" 
                       required class="input" 
                       placeholder="16 digit nomor KK"
                       pattern="[0-9]{16}"
                       maxlength="16"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                <p class="helper">Masukkan 16 digit nomor KK</p>
              </div>

              <div>
                <label class="field-label">Nama Kepala Keluarga <span style="color:red">*</span></label>
                <input type="text" name="nama_kepalakeluarga" 
                       value="<?= esc($_POST['nama_kepalakeluarga'] ?? '') ?>" 
                       required class="input" 
                       placeholder="Nama lengkap kepala keluarga">
              </div>
            </div>

            <!-- Alamat Asal -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Alamat Asal</h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Kecamatan Asal <span style="color:red">*</span></label>
                <select name="id_kecamatan_asal" id="kecamatanSelectAsal" required class="select">
                  <option value="">-- Pilih Kecamatan --</option>
                  <?php
                  $kec = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
                  while ($row = $kec->fetch_assoc()) {
                      $sel = ($selected_kecamatan_asal == $row['id']) ? ' selected' : '';
                      echo "<option value='" . esc($row['id']) . "'{$sel}>" . esc($row['nama_kecamatan']) . "</option>";
                  }
                  ?>
                </select>
              </div>

              <div>
                <label class="field-label">Desa / Kelurahan Asal <span style="color:red">*</span></label>
                <select name="id_desa_asal" id="desaSelectAsal" required class="select">
                  <option value="">-- Pilih Desa --</option>
                <?php
                if (!empty($selected_kecamatan_asal)) {
                    $des = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
                    $des->bind_param("i", $selected_kecamatan_asal);
                    $des->execute();
                    $resDes = $des->get_result();
                    while ($drow = $resDes->fetch_assoc()) {
                        $selD = ($selected_desa_asal == $drow['id']) ? ' selected' : '';
                        echo "<option value='" . esc($drow['id']) . "'{$selD}>" . esc($drow['nama_desa']) . "</option>";
                    }
                    $des->close();
                }
                ?>
                </select>
              </div>
            </div>

            <!-- Nama Kecamatan & Desa Asal (otomatis terisi) -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Kecamatan Asal</label>
                <input type="text" id="namaKecamatanAsal" readonly class="input bg-gray-100" value="<?= esc($pre_nama_kecamatan_asal ?? '') ?>">
              </div>

              <div>
                <label class="field-label">Nama Desa / Kelurahan Asal</label>
                <input type="text" id="namaDesaAsal" readonly class="input bg-gray-100" value="<?= esc($pre_nama_desa_asal ?? '') ?>">
              </div>
            </div>

            <div>
              <label class="field-label">Alamat Asal Lengkap <span style="color:red">*</span></label>
              <textarea name="alamat_asal" class="input" rows="3" required placeholder="Alamat lengkap saat ini (nama jalan, RT/RW, dusun, nomor rumah)"><?= esc($_POST['alamat_asal'] ?? '') ?></textarea>
            </div>

            <!-- Alamat Tujuan -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Alamat Tujuan</h3>

            <div>
              <label class="field-label">Alamat Tujuan Lengkap <span style="color:red">*</span></label>
              <textarea name="alamat_tujuan" class="input" rows="3" required placeholder="Alamat lengkap tujuan baru (nama jalan, RT/RW, dusun, kelurahan, kecamatan, kabupaten, provinsi)"><?= esc($_POST['alamat_tujuan'] ?? '') ?></textarea>
              <p class="helper">Contoh: Jl. Merdeka No. 123, RT 01/RW 02, Kelurahan Suka Maju, Kecamatan Suka Makmur, Kabupaten Suka Damai, Provinsi Suka Bahagia</p>
            </div>

            <!-- Alasan Pindah -->
            <div>
              <label class="field-label">Alasan Pindah <span style="color:red">*</span></label>
              <select name="alasan_pindah" required class="select">
                <option value="">-- Pilih Alasan Pindah --</option>
                <option value="kerja" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'kerja' ? 'selected' : '' ?>>Pekerjaan</option>
                <option value="pendidikan" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'pendidikan' ? 'selected' : '' ?>>Pendidikan</option>
                <option value="keluarga" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'keluarga' ? 'selected' : '' ?>>Keluarga</option>
                <option value="rumah" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'rumah' ? 'selected' : '' ?>>Pindah Rumah</option>
                <option value="lainnya" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
              </select>
            </div>

            <!-- Anggota Keluarga yang Pindah -->
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
                    <!-- Baris pertama wajib ada -->
                    <tr>
                      <td>
                        <input type="text" name="anggota_nama[]" placeholder="Nama lengkap" required>
                      </td>
                      <td>
                        <input type="text" name="anggota_nik[]" placeholder="16 digit NIK" 
                               pattern="[0-9]{16}" maxlength="16"
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
                      </td>
                      <td>
                        <input type="text" name="anggota_hubungan[]" placeholder="Contoh: Kepala Keluarga, Istri, Anak">
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
                <p class="helper">Isi data anggota keluarga yang ikut pindah.</p>
              </div>
            </div>

            <div>
              <label class="field-label">Keterangan Tambahan</label>
              <textarea name="keterangan" class="input" rows="3" placeholder="Keterangan tambahan untuk operator (jika ada)" maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
            </div>

            <!-- File Upload Multiple -->
            <div>
              <label class="field-label">Unggah Dokumen Pendukung</label>
              
              <!-- Info Box -->
              <div class="info-box">
                <h4><i class="fas fa-info-circle"></i> Informasi Dokumen</h4>
                <ul>
                  <li><strong>Dokumen Wajib:</strong> Formulir Pindah F-1.03, KK/KTP atau Surat Keterangan Hilang, dan Foto Pemohon</li>
                  <li><strong>Format File:</strong> JPG, PNG (maksimal 1MB per file)</li>
                </ul>
              </div>
              
              <!-- Dokumen WAJIB -->
              <div class="f-doc-group">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                </h4>
                <div id="wajibUploadContainer">
                  <!-- Formulir F-1.03 (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib1">
                    <input type="hidden" name="file_index[]" value="0">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="formulir_f103" selected>Formulir Pindah F-1.03 Pindah Penduduk</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Pilih File</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Formulir F-1.03 yang sudah ditandatangani. Unduh formulir
                      <a href="https://siprima.gorontalokab.go.id/uploads/formulir/1776654115_69e597232eb6a.pdf" target="_blank" rel="noopener noreferrer">klik disini</a>
                    </p>
                  </div>
                  
                  <!-- KK/KTP atau Surat Keterangan Hilang (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib2">
                    <input type="hidden" name="file_index[]" value="1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="kk_ktp_hilang" selected>KK/KTP atau Surat Keterangan Hilang KK/KTP</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Pilih File</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Pilih salah satu: KK <strong>ATAU</strong> KTP <strong>ATAU</strong> Surat Keterangan Hilang dari kepolisian
                    </p>
                  </div>
                  
                  <!-- Foto Pemohon (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib3">
                    <input type="hidden" name="file_index[]" value="2">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="foto_pemohon" selected>Foto Pemohon Memegang KTP/KK/Form Pindah</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib3">Upload Foto Selfie</label>
                      <span id="fileNameWajib3" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib3" name="dokumen[]" accept="image/jpeg,image/png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib3">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Foto diri pemohon sedang memegang KTP, KK, atau Formulir Pindah
                    </p>
                  </div>
                </div>
              </div>
              
              <!-- Dokumen OPSIONAL -->
              <div class="f-doc-group f-doc-group--opsional">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-check-circle"></i> Dokumen Lainnya (Jika Ada)
                </h4>
                <div id="opsionalUploadContainer">
                  <!-- Input opsional pertama -->
                  <div class="file-input-group" id="fileGroupOpsional1">
                    <input type="hidden" name="file_index[]" value="3">
                    <select name="doc_type[]" class="file-type-select">
                      <option value="">Pilih Jenis Dokumen (Opsional)</option>
                      <option value="buku_nikah">Buku Nikah / Akta Nikah</option>
                      <option value="akta_lahir">Akta Lahir</option>
                      <option value="surat_pindah">Surat Pindah Datang</option>
                      <option value="surat_kuasa">Surat Kuasa</option>
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
                  <i class="fas fa-plus"></i> Tambah Dokumen Pendukung Lainnya
                </button>
              </div>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="ajukan_permohonan.php" class="btn-secondary">Kembali</a>
              <button type="submit" class="btn-primary">Ajukan Permohonan Pindah</button>
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
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout.php');
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

  // AJAX untuk Desa Asal & Auto-fill Nama
  document.getElementById("kecamatanSelectAsal").addEventListener("change", function() {
    var idKecamatan = this.value;
    var desaSelect = document.getElementById("desaSelectAsal");
    var namaKecamatanInput = document.getElementById("namaKecamatanAsal");
    var namaDesaInput = document.getElementById("namaDesaAsal");
    desaSelect.innerHTML = "<option value=''>-- Pilih Desa --</option>";
    namaDesaInput.value = "";

    if (idKecamatan !== "") {
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

  document.getElementById("desaSelectAsal").addEventListener("change", function() {
    var namaDesaInput = document.getElementById("namaDesaAsal");
    if (this.value !== "") {
      namaDesaInput.value = this.options[this.selectedIndex].text;
    } else {
      namaDesaInput.value = "";
    }
  });

  // Set initial values dari server jika ada
  window.addEventListener("DOMContentLoaded", function() {
    var preKec = "<?= addslashes($pre_nama_kecamatan_asal ?? '') ?>";
    var preDes = "<?= addslashes($pre_nama_desa_asal ?? '') ?>";
    if (preKec) document.getElementById("namaKecamatanAsal").value = preKec;
    if (preDes) document.getElementById("namaDesaAsal").value = preDes;
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
        <input type="text" name="anggota_nik[]" placeholder="16 digit NIK" 
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
    const tableBody = document.getElementById('anggotaBody');
    const rows = tableBody.querySelectorAll('tr');
    
    if (rows.length > 1) {
      row.remove();
    } else {
      alert('Minimal harus ada satu anggota keluarga');
    }
  }

  // Multiple file upload functionality
  let fileCounter = {
    wajib: 3,
    opsional: 1
  };
  const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB
  const SERVER_POST_MAX_BYTES = <?= (int)$serverPostMaxBytes ?>;
  const SERVER_UPLOAD_MAX_BYTES = <?= (int)$serverUploadMaxBytes ?>;
  const SERVER_POST_BUFFER = 64 * 1024; // antisipasi multipart boundary overhead
  const EFFECTIVE_MAX_FILE_SIZE = SERVER_UPLOAD_MAX_BYTES > 0
    ? Math.min(MAX_FILE_SIZE, SERVER_UPLOAD_MAX_BYTES)
    : MAX_FILE_SIZE;
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
    const fileIndex = 3 + fileCounter.opsional - 1; // Start from index 3 (after the 3 wajib files)
    newFileGroup.innerHTML = `
      <input type="hidden" name="file_index[]" value="${fileIndex}">
      <select name="doc_type[]" class="file-type-select">
        <option value="">Pilih Jenis Dokumen (Opsional)</option>
        <option value="buku_nikah">Buku Nikah / Akta Nikah</option>
        <option value="akta_lahir">Akta Lahir</option>
        <option value="surat_pindah">Surat Pindah Datang</option>
        <option value="surat_kuasa">Surat Kuasa</option>
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
    
    const newFileInput = document.getElementById('fileInputOpsional' + fileCounter.opsional);
    const newFileNameSpan = document.getElementById('fileNameOpsional' + fileCounter.opsional);
    const newFileSizeSpan = document.getElementById('fileSizeOpsional' + fileCounter.opsional);
    
    if (newFileInput && newFileNameSpan && newFileSizeSpan) {
      newFileInput.addEventListener('change', function(e) {
        if (this.files.length > 0) {
          const file = this.files[0];
          newFileNameSpan.textContent = file.name;
          
          if (file.size > EFFECTIVE_MAX_FILE_SIZE) {
            newFileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (melebihi batas ${formatFileSize(EFFECTIVE_MAX_FILE_SIZE)})</span>`;
            this.value = '';
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
            
            if (file.size > EFFECTIVE_MAX_FILE_SIZE) {
              fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (melebihi batas ${formatFileSize(EFFECTIVE_MAX_FILE_SIZE)})</span>`;
              this.value = '';
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
            
            if (file.size > EFFECTIVE_MAX_FILE_SIZE) {
              fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (melebihi batas ${formatFileSize(EFFECTIVE_MAX_FILE_SIZE)})</span>`;
              this.value = '';
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
  // Validasi form sebelum submit
  document.getElementById('pindahForm').addEventListener('submit', function(e) {
    const allFileInputs = document.querySelectorAll('input[type="file"]');
    const anggotaRows = document.querySelectorAll('#anggotaBody tr');
    let hasError = false;
    let errorMessages = [];
    let totalUploadSize = 0;

    const csrfInput = this.querySelector('input[name="csrf_token"]');
    if (!csrfInput || !csrfInput.value.trim()) {
      errorMessages.push('Token keamanan tidak ditemukan. Muat ulang halaman lalu coba lagi.');
      hasError = true;
    }
    
    // Validasi No KK
    const noKKInput = document.querySelector('input[name="no_kk"]');
    if (noKKInput && noKKInput.value.length !== 16) {
      errorMessages.push('Nomor Kartu Keluarga harus 16 digit');
      hasError = true;
    }
    
    // Validasi NIK anggota
    const nikInputs = document.querySelectorAll('input[name="anggota_nik[]"]');
    nikInputs.forEach((input, index) => {
      if (input.value && input.value.length !== 16) {
        errorMessages.push(`NIK anggota baris ${index + 1} harus 16 digit (kosongkan jika tidak ada)`);
        hasError = true;
      }
    });
    
    // Validasi ukuran file
    allFileInputs.forEach((input) => {
      if (input.files.length > 0) {
        const file = input.files[0];
        if (file.size > EFFECTIVE_MAX_FILE_SIZE) {
          errorMessages.push(`File "${file.name}" melebihi batas ${formatFileSize(EFFECTIVE_MAX_FILE_SIZE)}`);
          hasError = true;
        }
        totalUploadSize += file.size;
      }
    });

    if (SERVER_POST_MAX_BYTES > 0 && totalUploadSize > Math.max(0, SERVER_POST_MAX_BYTES - SERVER_POST_BUFFER)) {
      errorMessages.push(`Total ukuran upload ${formatFileSize(totalUploadSize)} melebihi batas server ${formatFileSize(SERVER_POST_MAX_BYTES)}. Kecilkan ukuran file agar tidak gagal CSRF.`);
      hasError = true;
    }
    
    // Cek dokumen wajib
    const formulirInput = document.getElementById('fileInputWajib1');
    const kkKtpInput = document.getElementById('fileInputWajib2');
    const fotoInput = document.getElementById('fileInputWajib3');
    
    if (!formulirInput.files.length) {
      errorMessages.push('Formulir Pindah F-1.03 Pindah Penduduk wajib diupload');
      hasError = true;
    }
    
    if (!kkKtpInput.files.length) {
      errorMessages.push('KK/KTP atau Surat Keterangan Hilang KK/KTP wajib diupload');
      hasError = true;
    }
    
    if (!fotoInput.files.length) {
      errorMessages.push('Foto Pemohon Memegang KTP/KK/Form Pindah wajib diupload');
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
      errorMessages.push('Minimal harus ada satu anggota keluarga yang pindah');
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
      e.preventDefault();
      let message = 'Perbaiki kesalahan berikut:\n';
      if (errorMessages.length > 0) {
        message += '• ' + errorMessages.join('\n• ');
      } else {
        message += '• Harap lengkapi semua field yang wajib diisi';
      }
      alert(message);
    } else {
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan pindah alamat?\n\nPastikan Anda telah mengupload:\n1. Formulir Pindah F-1.03\n2. KK/KTP atau Surat Keterangan Hilang\n3. Foto Pemohon Memegang KTP/KK/Form Pindah');
      if (!confirmSubmit) {
        e.preventDefault();
      }
    }
  });
  </script>
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>
