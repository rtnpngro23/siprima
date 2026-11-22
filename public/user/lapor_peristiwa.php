<?php
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// Ambil data user untuk auto-fill pelapor
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
$selected_jenis_peristiwa = isset($_POST['jenis_peristiwa']) ? $_POST['jenis_peristiwa'] : '';
$pre_nama_kecamatan = '';
$pre_nama_desa = '';

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // IDs kecamatan/desa
    $id_kecamatan = isset($_POST['id_kecamatan']) ? (int)$_POST['id_kecamatan'] : 0;
    $id_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : 0;
    
    // Data peristiwa
    $jenis_peristiwa = trim($_POST['jenis_peristiwa'] ?? '');
    $nama_subjek = trim($_POST['nama_subjek'] ?? '');
    $nik_subjek = trim($_POST['nik_subjek'] ?? '');
    $tempat_lahir_mati = trim($_POST['tempat_lahir_mati'] ?? '');
    $tanggal_lahir_mati = trim($_POST['tanggal_lahir_mati'] ?? '');
    $nama_ayah = trim($_POST['nama_ayah'] ?? '');
    $nama_ibu = trim($_POST['nama_ibu'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi berdasarkan jenis peristiwa
    if (empty($jenis_peristiwa)) {
        $errors[] = 'Jenis peristiwa wajib dipilih';
    }
    
    if (empty($nama_subjek)) {
        $errors[] = 'Nama subjek wajib diisi';
    }
    
    if (!empty($nik_subjek) && !preg_match('/^[0-9]{16}$/', $nik_subjek)) {
        $errors[] = 'NIK subjek harus 16 digit angka';
    }
    
    if (empty($tempat_lahir_mati)) {
        $errors[] = 'Tempat peristiwa wajib diisi';
    }
    
    if (empty($tanggal_lahir_mati)) {
        $errors[] = 'Tanggal peristiwa wajib diisi';
    } elseif (strtotime($tanggal_lahir_mati) > strtotime('today')) {
        $errors[] = 'Tanggal peristiwa tidak boleh lebih dari hari ini';
    }
    
    if (empty($nama_ayah)) {
        $errors[] = 'Nama ayah wajib diisi';
    }
    
    if (empty($nama_ibu)) {
        $errors[] = 'Nama ibu wajib diisi';
    }
    
    // Ambil nama kecamatan/desa berdasarkan id (jika ada)
    $nama_kecamatan = '';
    $nama_desa = '';
    if (!empty($id_kecamatan)) {
        $qk = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $qk->bind_param("i", $id_kecamatan);
        $qk->execute();
        $rk = $qk->get_result()->fetch_assoc();
        if ($rk) $nama_kecamatan = $rk['nama_kecamatan'];
    }
    if (!empty($id_desa)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $qd->bind_param("i", $id_desa);
        $qd->execute();
        $rd = $qd->get_result()->fetch_assoc();
        if ($rd) $nama_desa = $rd['nama_desa'];
    }
    
    // Buat nama folder untuk penyimpanan dokumen
    $folder_name = '';
    if (!empty($nama_kecamatan) && !empty($nama_desa)) {
        // Format folder: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama_subjek]_[tanggal buat]
        $nama_pemohon = $data_user['nama'] ?? '';
        $nik_pemohon = $data_user['nik'] ?? '';
        $timestamp = date('Ymd_His'); // Format: YYYYMMDD_HHMMSS
        
        // Sanitize nama untuk folder (hapus karakter khusus, ganti spasi dengan underscore)
        $sanitize_name = function($name) {
            // Hapus karakter khusus kecuali huruf, angka, spasi
            $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
            // Ganti spasi dengan underscore
            $name = preg_replace('/\s+/', '_', trim($name));
            return $name;
        };
        
        $kec_sanitized = $sanitize_name($nama_kecamatan);
        $desa_sanitized = $sanitize_name($nama_desa);
        $pemohon_sanitized = $sanitize_name($nama_pemohon);
        $subjek_sanitized = $sanitize_name($nama_subjek);
        
        // Gabungkan semua komponen
        $folder_name = $kec_sanitized . '_' . 
                      $desa_sanitized . '_' . 
                      $nik_pemohon . '_' . 
                      $pemohon_sanitized . '_' . 
                      $subjek_sanitized . '_' . 
                      $timestamp;
        
        // Batasi panjang folder name (max 255 karakter untuk kompatibilitas sistem file)
        if (strlen($folder_name) > 200) {
            // Potong nama subjek jika terlalu panjang
            $max_subjek_length = 200 - (strlen($kec_sanitized) + strlen($desa_sanitized) + 
                                       strlen($nik_pemohon) + strlen($pemohon_sanitized) + 
                                       strlen($timestamp) + 5); // 5 untuk underscore
            
            if ($max_subjek_length > 10) {
                $subjek_sanitized = substr($subjek_sanitized, 0, $max_subjek_length);
                $folder_name = $kec_sanitized . '_' . 
                              $desa_sanitized . '_' . 
                              $nik_pemohon . '_' . 
                              $pemohon_sanitized . '_' . 
                              $subjek_sanitized . '_' . 
                              $timestamp;
            } else {
                // Jika masih terlalu panjang, gunakan hash
                $folder_hash = substr(md5($folder_name), 0, 8);
                $folder_name = $kec_sanitized . '_' . 
                              $desa_sanitized . '_' . 
                              $folder_hash . '_' . 
                              $timestamp;
            }
        }
    }

    if (empty($errors)) {
        // **PERBAIKAN UTAMA: PROSES UPLOAD DOKUMEN DULU**
        $uploadedFiles = [];
        $allowedMimesByExt = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
        ];
        $maxFileSize = 1 * 1024 * 1024; // 1MB
        $requiredDocs = ['foto_selfie'];
        $uploadedDocTypes = [];
        
        // **PERBAIKAN PATH: Sesuaikan dengan struktur website**
        // Contoh dari kk.php: __DIR__ . '/uploads/kk/'
        // Untuk lapor_peristiwa: __DIR__ . '/uploads/laporan_peristiwa/'
        
        $base_upload_dir = __DIR__ . '/uploads/laporan_peristiwa/';
        
        // Debug: cek path upload
        error_log("Base upload directory: " . $base_upload_dir);
        
        // Buat folder utama jika belum ada
        if (!is_dir($base_upload_dir)) {
            if (!mkdir($base_upload_dir, 0755, true)) {
                $errors[] = 'Gagal membuat folder upload utama';
                error_log("Failed to create main upload directory: " . $base_upload_dir);
            }
        }
        
        // **PERBAIKAN: Buat folder khusus hanya jika ada folder_name**
        if (!empty($folder_name)) {
            $user_folder_path = $base_upload_dir . $folder_name . '/';
            error_log("User folder path: " . $user_folder_path);
            
            // Buat folder khusus untuk user jika belum ada
            if (!is_dir($user_folder_path)) {
                if (!mkdir($user_folder_path, 0755, true)) {
                    $errors[] = 'Gagal membuat folder penyimpanan dokumen';
                    error_log("Failed to create user folder: " . $user_folder_path);
                } else {
                    error_log("User folder created successfully: " . $user_folder_path);
                    
                    // Tambahkan .htaccess untuk keamanan
                    $htaccessFile = $user_folder_path . '.htaccess';
                    if (!file_exists($htaccessFile)) {
                        file_put_contents($htaccessFile, 
                            "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
                    }
                }
            }
            
            // Proses upload file jika ada
            if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
                $fileCount = count($_FILES['dokumen']['name']);
                error_log("Number of files to upload: " . $fileCount);
                
                for ($i = 0; $i < $fileCount; $i++) {
                    // Cek apakah ada file yang diupload
                    if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK && 
                        $_FILES['dokumen']['size'][$i] > 0) {
                        
                        $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                        $fileName = basename($_FILES['dokumen']['name'][$i]);
                        $fileSize = $_FILES['dokumen']['size'][$i];
                        $docType = $_POST['doc_type'][$i] ?? 'lainnya';
                        
                        // Debug
                        error_log("Processing file: " . $fileName);
                        error_log("Temp file: " . $fileTmp);
                        error_log("File size: " . $fileSize);
                        
                        $fileExt = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
                        if (!isset($allowedMimesByExt[$fileExt])) {
                            $errors[] = "File $fileName: Ekstensi file tidak diizinkan. Hanya JPG, JPEG, PNG, GIF";
                            continue;
                        }

                        if (!is_uploaded_file($fileTmp) || !is_readable($fileTmp)) {
                            $errors[] = "File $fileName: Upload tidak valid";
                            continue;
                        }

                        $detectedMime = '';
                        if (function_exists('finfo_open')) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            if ($finfo) {
                                $detectedMime = (string)finfo_file($finfo, $fileTmp);
                                finfo_close($finfo);
                            }
                        }
                        if ($detectedMime === '' && function_exists('mime_content_type')) {
                            $detectedMime = (string)mime_content_type($fileTmp);
                        }

                        if ($detectedMime === '' || !in_array($detectedMime, $allowedMimesByExt[$fileExt], true)) {
                            $errors[] = "File $fileName: Tipe file tidak valid atau tidak sesuai ekstensi";
                            continue;
                        }

                        // Validasi ukuran file - MAKSIMAL 1MB
                        if ($fileSize > $maxFileSize) {
                            $errors[] = "File $fileName: Ukuran file melebihi 1MB";
                            continue;
                        }

                        // Generate unique filename
                        $timestamp_file = time();
                        $random_str = bin2hex(random_bytes(6));
                        
                        // Dapatkan ekstensi file (sudah tervalidasi)
                        $ext = $fileExt;
                        $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                        $newName = $clean_doc_type . '_' . 
                                  $timestamp_file . '_' . 
                                  $random_str . '.' . $ext;
                        $destination = $user_folder_path . $newName;

                        error_log("Destination: " . $destination);
                        error_log("Is file tmp readable: " . (is_readable($fileTmp) ? 'YES' : 'NO'));

                        if (move_uploaded_file($fileTmp, $destination)) {
                            // **PERBAIKAN PATH RELATIF: Gunakan path dari root folder user**
                            // Dari root user: uploads/laporan_peristiwa/
                            $relative_path = 'uploads/laporan_peristiwa/' . $folder_name . '/' . $newName;
                            
                            $uploadedFiles[] = [
                                'path' => $relative_path,
                                'type' => $docType,
                                'original_name' => $fileName,
                                'size' => $fileSize,
                                'uploaded_at' => date('Y-m-d H:i:s')
                            ];
                            $uploadedDocTypes[] = $docType;
                            
                            error_log("File uploaded successfully: " . $relative_path);
                        } else {
                            $errors[] = "Gagal mengupload file: $fileName";
                            error_log("Failed to move uploaded file. Error: " . $_FILES['dokumen']['error'][$i]);
                            error_log("Upload error code: " . $_FILES['dokumen']['error'][$i]);
                        }
                    } else {
                        // Jika error selain "no file"
                        $upload_error = $_FILES['dokumen']['error'][$i];
                        if ($upload_error !== UPLOAD_ERR_OK && $upload_error !== UPLOAD_ERR_NO_FILE) {
                            $errors[] = "Error upload file: " . $upload_error;
                        }
                    }
                }
            } else {
                error_log("No files in \$_FILES['dokumen'] array");
            }
        } else {
            error_log("Folder name is empty, cannot create upload directory");
        }

        $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
        if (!empty($missingRequired)) {
            $errors[] = 'Dokumen wajib belum diupload: Foto Selfie Pemohon';
        }
        
        // Jika ada error upload, jangan lanjut ke insert database
        if (!empty($errors)) {
            // Tetap set selected values untuk form repopulation
            $selected_kecamatan = $id_kecamatan;
            $selected_desa = $id_desa;
            $selected_jenis_peristiwa = $jenis_peristiwa;
        } else {
            // Siapkan nilai pelapor dari data user
            $nama_pelapor = $data_user['nama'] ?? '';
            $nik_pelapor = $data_user['nik'] ?? '';
            $no_hp = $data_user['no_hp'] ?? '';
            $email = $data_user['email'] ?? '';

            // Status otomatis "diajukan"
            $status = 'diajukan';
            
            // Konversi uploadedFiles ke JSON
            $dokumenJson = !empty($uploadedFiles) ? json_encode($uploadedFiles) : NULL;
            
            // **PERBAIKAN: Insert ke database dengan dokumen JSON**
            $stmt = $koneksi->prepare("INSERT INTO tb_laporan_peristiwa
                (id_user, id_kecamatan, id_desa, nama_pelapor, nik_pelapor, no_hp, email,
                 nama_kecamatan, nama_desa, jenis_peristiwa, nama_subjek, nik_subjek,
                 tempat_lahir_mati, tanggal_lahir_mati, nama_ayah, nama_ibu,
                 keterangan, status, nama_folder, dokumen, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            if (!$stmt) {
                $errors[] = 'Prepare statement error: ' . $koneksi->error;
            } else {
                // **PERBAIKAN: Parameter harus 20 s sesuai jumlah field (termasuk dokumen)**
                $stmt->bind_param("iiisssssssssssssssss",
                    $user_id,            // i (integer)
                    $id_kecamatan,       // i (integer)
                    $id_desa,            // i (integer)
                    $nama_pelapor,       // s (string)
                    $nik_pelapor,        // s (string)
                    $no_hp,              // s (string)
                    $email,              // s (string)
                    $nama_kecamatan,     // s (string)
                    $nama_desa,          // s (string)
                    $jenis_peristiwa,    // s (string)
                    $nama_subjek,        // s (string)
                    $nik_subjek,         // s (string)
                    $tempat_lahir_mati,  // s (string)
                    $tanggal_lahir_mati, // s (string)
                    $nama_ayah,          // s (string)
                    $nama_ibu,           // s (string)
                    $keterangan,         // s (string)
                    $status,             // s (string)
                    $folder_name,        // s (string) - nama_folder
                    $dokumenJson         // s (string) - dokumen JSON
                );
                
                if ($stmt->execute()) {
                    $stmt->close();
                    
                    $_SESSION['success_message'] = 'Laporan peristiwa berhasil diajukan.';
                    header('Location: ajukan_permohonan.php');
                    exit;
                } else {
                    $errors[] = 'Database error: ' . $stmt->error;
                    $stmt->close();
                    
                    // Jika gagal insert, hapus folder yang sudah dibuat (opsional)
                    if (!empty($user_folder_path) && is_dir($user_folder_path)) {
                        // Hapus semua file dalam folder
                        $files = glob($user_folder_path . '/*');
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                unlink($file);
                            }
                        }
                        // Hapus folder
                        rmdir($user_folder_path);
                    }
                }
            }
        }
    }
    
    // Set selected values for form repopulation
    $selected_kecamatan = $id_kecamatan;
    $selected_desa = $id_desa;
    $selected_jenis_peristiwa = $jenis_peristiwa;
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

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Flash success from redirect (if any)
$flash_success = null;
if (isset($_SESSION['success_message'])) {
    $flash_success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Lapor Peristiwa Kependudukan</title>
  <link rel="stylesheet" href="css/lapor_peristiwa.css">
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
        render_user_header_brand('Lapor Peristiwa Kependudukan');
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
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Form Laporan Peristiwa Kependudukan</h2>
              <p class="helper">Laporkan peristiwa lahir, mati, atau peristiwa rentan administrasi kependudukan</p>
            </div>
          </div>

          <!-- Flash success message -->
          <?php if (!empty($flash_success)): ?>
            <div class="toast toast-success">
              <?= esc($flash_success) ?>
            </div>
            <script>window._userUtils?.removeToasts(5000);</script>
          <?php endif; ?>

          <?php if (!empty($errors)): ?>
            <div class="toast toast-error">
              <?= esc($errors[0]) ?>
            </div>
            <script>window._userUtils?.removeToasts(5000);</script>

            <div class="error-box">
              <?php foreach ($errors as $err) echo "<p>" . esc($err) . "</p>"; ?>
            </div>
          <?php endif; ?>

          <form method="POST" enctype="multipart/form-data" class="space-y-4" id="peristiwaForm" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" onsubmit="return validateForm()">
<?php echo csrf_input(); ?>

            <!-- Data Pelapor (otomatis terisi) -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Pelapor</label>
                <input type="text" value="<?= esc($data_user['nama'] ?? '') ?>" readonly class="input bg-gray-100">
                <input type="hidden" name="nama_pelapor" value="<?= esc($data_user['nama'] ?? '') ?>">
              </div>

              <div>
                <label class="field-label">NIK Pelapor</label>
                <input type="text" value="<?= esc($data_user['nik'] ?? '') ?>" readonly class="input bg-gray-100">
                <input type="hidden" name="nik_pelapor" value="<?= esc($data_user['nik'] ?? '') ?>">
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

            <!-- Jenis Peristiwa -->
            <div>
              <label class="field-label">Jenis Peristiwa <span style="color:red">*</span></label>
              <select name="jenis_peristiwa" id="jenisPeristiwa" required class="select" onchange="showPeristiwaInfo()">
                <option value="">-- Pilih Jenis Peristiwa --</option>
                <option value="lahir" <?= $selected_jenis_peristiwa == 'lahir' ? 'selected' : '' ?>>Lahir</option>
                <option value="mati" <?= $selected_jenis_peristiwa == 'mati' ? 'selected' : '' ?>>Meninggal Dunia</option>
                <option value="rentan_adminduk" <?= $selected_jenis_peristiwa == 'rentan_adminduk' ? 'selected' : '' ?>>Rentan Administrasi Kependudukan</option>
              </select>
            </div>

            <!-- Informasi berdasarkan jenis peristiwa -->
            <div id="peristiwaInfo" class="peristiwa-info" style="display: none;">
              <h4>Informasi:</h4>
              <div id="infoContent"></div>
            </div>

            <!-- Data Subjek -->
            <div class="form-grid-2">
              <div>
                <label class="field-label" id="labelNamaSubjek">Nama Subjek <span style="color:red">*</span></label>
                <input type="text" name="nama_subjek" value="<?= esc($_POST['nama_subjek'] ?? '') ?>" required class="input" 
                       placeholder="Nama lengkap" id="namaSubjekInput">
              </div>

              <div>
                <label class="field-label" id="labelNikSubjek">NIK Subjek (Opsional)</label>
                <input type="text" name="nik_subjek" value="<?= esc($_POST['nik_subjek'] ?? '') ?>" class="input" 
                      maxlength="16" pattern="[0-9]{16}" placeholder="16 digit angka" id="nikSubjekInput" oninput="validateNik(this)">
                <div id="nikError" style="color: #dc3545; font-size: 14px; display: none;">NIK harus 16 digit angka</div>
              </div>
            </div>

            <!-- Tempat dan Tanggal -->
            <div class="form-grid-2">
              <div>
                <label class="field-label" id="labelTempat">Tempat Peristiwa <span style="color:red">*</span></label>
                <input type="text" name="tempat_lahir_mati" value="<?= esc($_POST['tempat_lahir_mati'] ?? '') ?>" required class="input" 
                       placeholder="Contoh: Kabupaten Gorontalo / Kota Gorontalo">
              </div>

              <div>
                <label class="field-label" id="labelTanggal">Tanggal Peristiwa <span style="color:red">*</span></label>
                <input type="date" name="tanggal_lahir_mati" value="<?= esc($_POST['tanggal_lahir_mati'] ?? '') ?>" required 
                       class="input" max="<?= date('Y-m-d') ?>">
              </div>
            </div>

            <!-- Nama Orang Tua -->
            <div class="form-grid-2">
              <div>
                <label class="field-label" id="labelAyah">Nama Ayah <span style="color:red">*</span></label>
                <input type="text" name="nama_ayah" value="<?= esc($_POST['nama_ayah'] ?? '') ?>" required class="input" 
                       placeholder="Nama lengkap ayah">
              </div>

              <div>
                <label class="field-label" id="labelIbu">Nama Ibu <span style="color:red">*</span></label>
                <input type="text" name="nama_ibu" value="<?= esc($_POST['nama_ibu'] ?? '') ?>" required class="input" 
                       placeholder="Nama lengkap ibu">
              </div>
            </div>

            <div>
              <label class="field-label">Keterangan / Pesan untuk Operator</label>
              <textarea name="keterangan" class="input" rows="3" 
                placeholder="Berikan penjelasan tambahan mengenai peristiwa yang dilaporkan, contoh : Belum memiliki akta lahir/mati, lansia tidak memiliki data, belum rekam ktp-el, dll" maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
            </div>

            <!-- File Upload Multiple -->
            <div>
              <label class="field-label">Unggah Dokumen Pendukung <span style="color:red">*</span></label>
              <div class="f-doc-group">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                </h4>
                <div id="wajibUploadContainer">
                  <div class="file-input-group" id="fileGroupWajib1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="foto_selfie" selected>Foto Selfie Pemohon</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Upload Foto Selfie</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept="image/jpeg,image/png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>
                </div>
              </div>

              <div class="f-doc-group f-doc-group--opsional">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-check-circle"></i> Dokumen Opsional (Jika Ada)
                </h4>
                <div id="opsionalUploadContainer">
                  <div class="file-input-group" id="fileGroupOpsional1">
                    <select name="doc_type[]" class="file-type-select">
                      <option value="">Pilih Jenis Dokumen (Opsional)</option>
                      <option value="surat_keterangan_lahir">Surat Keterangan Lahir</option>
                      <option value="kk">Kartu Keluarga</option>
                      <option value="ktp">KTP</option>
                      <option value="akta_lahir">Akta Lahir</option>
                      <option value="akta_kematian">Akta Kematian</option>
                      <option value="ijazah">Ijazah</option>
                      <option value="buku_nikah">Buku Nikah</option>
                      <option value="lainnya">Dokumen Lainnya</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputOpsional1">Pilih File</label>
                      <span id="fileNameOpsional1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputOpsional1" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeOpsional1">Maksimal 5 MB per file (Opsional, dikompres otomatis)</div>
                  </div>
                </div>
                <button type="button" class="btn-secondary mt-2" onclick="addOptionalFileInput()">
                  + Tambah Dokumen Opsional Lainnya
                </button>
              </div>
              
              <p class="helper"><strong>Dokumen wajib:</strong> Foto Selfie Pemohon. <strong>Format:</strong> JPG, JPEG, JFIF, JPE, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong></p>
              <p class="helper" id="docRequirement"></p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="ajukan_permohonan.php" class="btn-secondary">Kembali ke Ajukan Permohonan</a>
              <button type="submit" class="btn-primary">Laporkan Peristiwa</button>
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
  // Fungsi untuk menampilkan informasi berdasarkan jenis peristiwa
  function showPeristiwaInfo() {
    const jenisSelect = document.getElementById('jenisPeristiwa');
    const infoBox = document.getElementById('peristiwaInfo');
    const infoContent = document.getElementById('infoContent');
    const docRequirement = document.getElementById('docRequirement');
    
    // Update label berdasarkan jenis peristiwa
    const labelNamaSubjek = document.getElementById('labelNamaSubjek');
    const labelNikSubjek = document.getElementById('labelNikSubjek');
    const labelTempat = document.getElementById('labelTempat');
    const labelTanggal = document.getElementById('labelTanggal');
    const labelAyah = document.getElementById('labelAyah');
    const labelIbu = document.getElementById('labelIbu');
    const namaSubjekInput = document.getElementById('namaSubjekInput');
    
    const jenis = jenisSelect.value;
    
    if (jenis === 'lahir') {
      labelNamaSubjek.innerHTML = 'Nama Bayi <span style="color:red">*</span>';
      labelNikSubjek.innerHTML = 'NIK Bayi (Opsional)';
      labelTempat.innerHTML = 'Tempat Lahir <span style="color:red">*</span>';
      labelTanggal.innerHTML = 'Tanggal Lahir <span style="color:red">*</span>';
      labelAyah.innerHTML = 'Nama Ayah <span style="color:red">*</span>';
      labelIbu.innerHTML = 'Nama Ibu <span style="color:red">*</span>';
      namaSubjekInput.placeholder = 'Nama lengkap bayi';
      
      infoContent.innerHTML = `
        <ul>
          <li><strong>Untuk melaporkan kelahiran bayi</strong></li>
          <li>Isi data bayi yang baru lahir</li>
          <li>Dokumen: Surat Keterangan Lahir (Opsional)</li>
          <li>NIK bayi dapat diisi nanti setelah terdaftar di Dukcapil</li>
        </ul>
      `;
      docRequirement.innerHTML = '<strong>Dokumen wajib: Foto Selfie Pemohon</strong>';
      infoBox.style.display = 'block';
      
    } else if (jenis === 'mati') {
      labelNamaSubjek.innerHTML = 'Nama Meninggal <span style="color:red">*</span>';
      labelNikSubjek.innerHTML = 'NIK Meninggal (Opsional)';
      labelTempat.innerHTML = 'Tempat Meninggal <span style="color:red">*</span>';
      labelTanggal.innerHTML = 'Tanggal Meninggal <span style="color:red">*</span>';
      labelAyah.innerHTML = 'Nama Ayah <span style="color:red">*</span>';
      labelIbu.innerHTML = 'Nama Ibu <span style="color:red">*</span>';
      namaSubjekInput.placeholder = 'Nama lengkap yang meninggal';
      
      infoContent.innerHTML = `
        <ul>
          <li><strong>Untuk melaporkan kematian seseorang</strong></li>
          <li>Isi data orang yang meninggal dunia</li>
          <li>Dokumen: KK dan KTP yang meninggal (Opsional)</li>
          <li>Jika NIK tidak diketahui, bisa dikosongkan</li>
        </ul>
      `;
      docRequirement.innerHTML = '<strong>Dokumen wajib: Foto Selfie Pemohon</strong>';
      infoBox.style.display = 'block';
      
    } else if (jenis === 'rentan_adminduk') {
      labelNamaSubjek.innerHTML = 'Nama <span style="color:red">*</span>';
      labelNikSubjek.innerHTML = 'NIK (Opsional)';
      labelTempat.innerHTML = 'Tempat Lahir <span style="color:red">*</span>';
      labelTanggal.innerHTML = 'Tanggal Lahir <span style="color:red">*</span>';
      labelAyah.innerHTML = 'Nama Ayah <span style="color:red">*</span>';
      labelIbu.innerHTML = 'Nama Ibu <span style="color:red">*</span>';
      namaSubjekInput.placeholder = 'Nama lengkap';
      
      infoContent.innerHTML = `
        <ul>
          <li><strong>Untuk melaporkan peristiwa rentan administrasi kependudukan</strong></li>
          <li>Contoh: Lansia belum rekam KTP-el, belum punya kartu keluarga, dll</li>
          <li>Isi data lengkap orang yang mengalami kendala administrasi</li>
          <li>Dokumen: ijazah, buku nikah, atau dokumen lainnya (Opsional)</li>
        </ul>
      `;
      docRequirement.innerHTML = '<strong>Dokumen wajib: Foto Selfie Pemohon</strong>';
      infoBox.style.display = 'block';
      
    } else {
      infoBox.style.display = 'none';
      docRequirement.innerHTML = '';
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
  // Delegasi global untuk menstabilkan setiap file yang dipilih.
  document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    stabilizeSelectedFile(target);
  });

  // Validasi form sebelum submit
  function validateForm() {
    let isValid = true;
    let errorMessages = [];
    
    // Validasi jenis peristiwa
    const jenisPeristiwa = document.getElementById('jenisPeristiwa').value;
    if (!jenisPeristiwa) {
      errorMessages.push('Jenis peristiwa wajib dipilih');
      isValid = false;
    }
    
    // Validasi NIK jika diisi
    const nikSubjek = document.getElementById('nikSubjekInput').value;
    if (nikSubjek && !/^[0-9]{16}$/.test(nikSubjek)) {
      errorMessages.push('NIK subjek harus 16 digit angka');
      isValid = false;
    }
    
    // Validasi tanggal tidak lebih dari hari ini
    const tanggalInput = document.querySelector('input[name="tanggal_lahir_mati"]');
    if (tanggalInput.value) {
      const today = new Date().toISOString().split('T')[0];
      if (tanggalInput.value > today) {
        errorMessages.push('Tanggal peristiwa tidak boleh lebih dari hari ini');
        isValid = false;
      }
    }
    
    // Validasi ukuran file client-side
    const fileInputs = document.querySelectorAll('input[type="file"]');
    const maxSize = 1 * 1024 * 1024; // 1MB
    
    fileInputs.forEach((input, index) => {
      if (input.files.length > 0) {
        const file = input.files[0];
        if (file.size > maxSize) {
          errorMessages.push(`File "${file.name}" melebihi ukuran maksimal 1MB`);
          isValid = false;
        }
      }
    });

    const selfieInput = document.getElementById('fileInputWajib1');
    let hasFotoSelfie = selfieInput && selfieInput.files.length > 0;

    if (!hasFotoSelfie) {
      errorMessages.push('Foto Selfie Pemohon wajib diupload');
      isValid = false;
    }
    
    if (!isValid) {
      alert('Perbaiki kesalahan berikut:\n• ' + errorMessages.join('\n• '));
      return false;
    }
    
    return true;
  }

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

  // Multiple file upload functionality dengan validasi ukuran 1MB (OPSIONAL)
  let fileCounter = {
    wajib: 1,
    opsional: 1
  };
  const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB

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
        <option value="surat_keterangan_lahir">Surat Keterangan Lahir</option>
        <option value="kk">Kartu Keluarga</option>
        <option value="ktp">KTP</option>
        <option value="akta_lahir">Akta Lahir</option>
        <option value="akta_kematian">Akta Kematian</option>
        <option value="ijazah">Ijazah</option>
        <option value="buku_nikah">Buku Nikah</option>
        <option value="lainnya">Dokumen Lainnya</option>
      </select>
      <div class="file-wrapper">
        <label class="file-label" for="fileInputOpsional${fileCounter.opsional}">Pilih File</label>
        <span id="fileNameOpsional${fileCounter.opsional}" class="file-name">Belum ada file dipilih</span>
        <input type="file" id="fileInputOpsional${fileCounter.opsional}" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
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
      if (fileId > 1) {
        fileGroup.remove();
      } else {
        alert('Dokumen opsional pertama tidak dapat dihapus.');
      }
    }
  }

  // Initialize file input event listeners
  document.addEventListener('DOMContentLoaded', function() {
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

    for (let i = 1; i <= fileCounter.opsional; i++) {
      const input = document.getElementById('fileInputOpsional' + i);
      const fileNameSpan = document.getElementById('fileNameOpsional' + i);
      const fileSizeSpan = document.getElementById('fileSizeOpsional' + i);
      if (input && fileNameSpan && fileSizeSpan) {
        input.addEventListener('change', function(e) {
          if (this.files.length > 0) {
            const file = this.files[0];
            fileNameSpan.textContent = file.name;
            if (file.size > MAX_FILE_SIZE) {
              fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (gagal dikompres, pilih gambar lain)</span>`;
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
  </script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>
