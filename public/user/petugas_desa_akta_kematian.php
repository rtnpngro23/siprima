<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/petugas_desa_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';;

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// Ambil data user untuk auto-fill pemohon DAN data kecamatan/desa yang ditugaskan
$stmt_user = $koneksi->prepare("SELECT 
    u.nama, 
    u.nik, 
    u.no_hp, 
    u.email,
    u.id_kecamatan,
    u.id_desa,
    k.nama_kecamatan,
    d.nama_desa
FROM tb_users u
LEFT JOIN tb_kecamatan k ON u.id_kecamatan = k.id
LEFT JOIN tb_desa d ON u.id_desa = d.id
WHERE u.id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();

if (!$result_user || $result_user->num_rows === 0) {
    header('Location: ../login.php?error=user_not_found');
    exit;
}

$data_user = $result_user->fetch_assoc();
$stmt_user->close();

// Ambil data kecamatan dan desa user
$user_kecamatan_id = $data_user['id_kecamatan'] ?? 0;
$user_desa_id = $data_user['id_desa'] ?? 0;
$nama_kecamatan_user = $data_user['nama_kecamatan'] ?? '';
$nama_desa_user = $data_user['nama_desa'] ?? '';

$errors = [];
$success_message = '';

// KUNCI pilihan kecamatan dan desa sesuai user
$selected_kecamatan = $user_kecamatan_id; // SELALU sesuai user
$selected_desa = $user_desa_id; // SELALU sesuai user
$pre_nama_kecamatan = $nama_kecamatan_user;
$pre_nama_desa = $nama_desa_user;

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi: Pastikan kecamatan/desa sesuai dengan user
    // ID kecamatan/desa dari user - PASTIKAN sesuai dengan user
    $id_kecamatan = $user_kecamatan_id;
    $id_desa = $user_desa_id;
    
    // Validasi: cek jika ada upaya mengubah kecamatan/desa
    if ((isset($_POST['id_kecamatan']) && (int)$_POST['id_kecamatan'] != $user_kecamatan_id) ||
        (isset($_POST['id_desa']) && (int)$_POST['id_desa'] != $user_desa_id)) {
        $errors[] = 'Anda tidak memiliki akses untuk mengubah kecamatan/desa tujuan.';
    }

    // data orang yang meninggal
    $dusun = trim($_POST['dusun'] ?? '');
    $nama_meninggal = trim($_POST['nama_meninggal'] ?? '');
    $nik_meninggal = trim($_POST['nik_meninggal'] ?? '');
    $tanggal_kematian = trim($_POST['tanggal_kematian'] ?? '');
    $tempat = trim($_POST['tempat'] ?? '');
    
    // Data orang tua (REQUIRED)
    $nama_ayah = trim($_POST['nama_ayah'] ?? '');
    $nik_ayah = trim($_POST['nik_ayah'] ?? '');
    $nama_ibu = trim($_POST['nama_ibu'] ?? '');
    $nik_ibu = trim($_POST['nik_ibu'] ?? '');
    
    // Data saksi (REQUIRED)
    $nama_saksi1 = trim($_POST['nama_saksi1'] ?? '');
    $nik_saksi1 = trim($_POST['nik_saksi1'] ?? '');
    $nama_saksi2 = trim($_POST['nama_saksi2'] ?? '');
    $nik_saksi2 = trim($_POST['nik_saksi2'] ?? '');
    
    $keterangan = trim($_POST['keterangan'] ?? '');

    // Validasi NIK jika diisi
    if (!empty($nik_meninggal) && !preg_match('/^[0-9]{16}$/', $nik_meninggal)) {
        $errors[] = 'NIK yang meninggal harus 16 digit';
    }
    if (!empty($nik_ayah) && !preg_match('/^[0-9]{16}$/', $nik_ayah)) {
        $errors[] = 'NIK ayah harus 16 digit angka';
    }
    if (!empty($nik_ibu) && !preg_match('/^[0-9]{16}$/', $nik_ibu)) {
        $errors[] = 'NIK ibu harus 16 digit angka';
    }
    if (!empty($nik_saksi1) && !preg_match('/^[0-9]{16}$/', $nik_saksi1)) {
        $errors[] = 'NIK saksi 1 harus 16 digit angka';
    }
    if (!empty($nik_saksi2) && !preg_match('/^[0-9]{16}$/', $nik_saksi2)) {
        $errors[] = 'NIK saksi 2 harus 16 digit angka';
    }

    // basic validation
    if (empty($nama_meninggal)) $errors[] = 'Nama yang meninggal wajib diisi.';
    if (empty($tanggal_kematian)) $errors[] = 'Tanggal kematian wajib diisi.';
    
    // Validasi data orang tua (REQUIRED)
    if (empty($nama_ayah)) $errors[] = 'Nama ayah wajib diisi.';
    if (empty($nik_ayah)) $errors[] = 'NIK ayah wajib diisi.';
    if (empty($nama_ibu)) $errors[] = 'Nama ibu wajib diisi.';
    if (empty($nik_ibu)) $errors[] = 'NIK ibu wajib diisi.';
    
    // Validasi data saksi (REQUIRED)
    if (empty($nama_saksi1)) $errors[] = 'Nama saksi 1 wajib diisi.';
    if (empty($nik_saksi1)) $errors[] = 'NIK saksi 1 wajib diisi.';
    if (empty($nama_saksi2)) $errors[] = 'Nama saksi 2 wajib diisi.';
    if (empty($nik_saksi2)) $errors[] = 'NIK saksi 2 wajib diisi.';
    
    // Validasi tanggal kematian tidak lebih dari hari ini
    $today = date('Y-m-d');
    if ($tanggal_kematian > $today) {
        $errors[] = 'Tanggal kematian tidak boleh lebih dari hari ini.';
    }

    // file upload - MULTIPLE FILES, maksimal 1MB SETELAH kompresi otomatis di sisi klien
    $uploadedFiles = [];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Dokumen wajib: 
    // 1. Formulir F-2.01 Kematian
    // 2. Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan
    // 3. KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal
    $requiredDocs = ['formulir_kematian', 'surat_keterangan_kematian', 'kk_ktp_meninggal']; 
    $uploadedDocTypes = [];
    
    // Buat folder penyimpanan unggahan dengan format khusus
    $folderName = generateFolderName($nama_kecamatan_user, $nama_desa_user, 
                                    $data_user['nik'], $data_user['nama'], 
                                    $nama_meninggal);
    
    // Path folder: uploads/akta_kematian/[folderName]
    $baseUploadDir = __DIR__ . '/uploads/akta_kematian';
    $uploadDir = $baseUploadDir . '/' . $folderName;
    
    // Pastikan folder uploads/akta_kematian ada
    if (!is_dir($baseUploadDir)) {
        mkdir($baseUploadDir, 0755, true);
    }
    
    // Buat folder spesifik jika belum ada
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            $errors[] = "Gagal membuat folder penyimpanan: " . $folderName;
        } else {
            // Tambahkan file .htaccess untuk keamanan
            $htaccessFile = $uploadDir . '/.htaccess';
            if (!file_exists($htaccessFile)) {
                file_put_contents($htaccessFile, 
                    "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
            }
        }
    }
    
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        // Dapatkan array doc_type dan file_index dari POST
        $doc_types = isset($_POST['doc_type']) && is_array($_POST['doc_type']) ? $_POST['doc_type'] : [];
        $file_indexes = isset($_POST['file_index']) && is_array($_POST['file_index']) ? $_POST['file_index'] : [];
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $fileSize = $_FILES['dokumen']['size'][$i];
                
                // Ambil doc_type dari array yang sesuai
                $docType = isset($doc_types[$i]) ? $doc_types[$i] : 'lainnya';
                
                $fileType = '';
                $ext = '';
                $uploadValidationError = siprima_validate_uploaded_image(
                    $fileTmp, $fileName, $fileSize, $maxFileSize, $fileType, $ext
                );
                if ($uploadValidationError !== '') {
                    $errors[] = $uploadValidationError;
                    continue;
                }

                // Generate unique filename
                $timestamp_file = time() . '_' . $i;
                $randomString = bin2hex(random_bytes(6));
                $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                $newName = $clean_doc_type . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                $destination = $uploadDir . '/' . $newName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    // Simpan path relatif: uploads/akta_kematian/[folderName]/[filename]
                    $relativePath = 'uploads/akta_kematian/' . $folderName . '/' . $newName;
                    $uploadedFiles[] = [
                        'path' => $relativePath,
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
            }
        }
    }

    // Cek dokumen wajib
    $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
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

    // Ambil nama kecamatan/desa dari data user (sudah diambil di atas)
    $nama_kecamatan = $nama_kecamatan_user;
    $nama_desa = $nama_desa_user;

    if (empty($errors)) {
        // Siapkan nilai pemohon dari data user
        $nama_pemohon = $data_user['nama'] ?? '';
        $nik_pemohon = $data_user['nik'] ?? '';
        $no_hp = $data_user['no_hp'] ?? '';
        $email = $data_user['email'] ?? '';

        // Convert uploaded files to JSON string
        $dokumenJson = json_encode($uploadedFiles);

        // Simpan data ke database
        $status = 'diajukan';
        
        $stmt = $koneksi->prepare("INSERT INTO tb_akta_kematian 
            (id_user, id_kecamatan, id_desa, nama_pemohon, nik_pemohon, no_hp, email, 
             nama_kecamatan, nama_desa, dusun, Nama_meninggal, Nik_meninggal, 
             tanggal_kematian, Tempat, Nama_Ayah, Nik_ayah, Nama_Ibu, Nik_ibu,
             nama_saksi1, nik_saksi1, nama_saksi2, nik_saksi2,
             dokumen, keterangan, status, nama_folder, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if (!$stmt) {
            $errors[] = 'Prepare statement error: ' . $koneksi->error;
        } else {
            $stmt->bind_param("iiisssssssssssssssssssssss",
                $user_id,           // i
                $id_kecamatan,      // i
                $id_desa,           // i
                $nama_pemohon,      // s
                $nik_pemohon,       // s
                $no_hp,             // s
                $email,             // s
                $nama_kecamatan,    // s
                $nama_desa,         // s
                $dusun,             // s
                $nama_meninggal,    // s
                $nik_meninggal,     // s
                $tanggal_kematian,  // s
                $tempat,            // s
                $nama_ayah,         // s
                $nik_ayah,          // s
                $nama_ibu,          // s
                $nik_ibu,           // s
                $nama_saksi1,       // s
                $nik_saksi1,        // s
                $nama_saksi2,       // s
                $nik_saksi2,        // s
                $dokumenJson,       // s
                $keterangan,        // s
                $status,            // s
                $folderName         // s - nama folder
            );
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Permohonan Akta Kematian berhasil diajukan untuk ' . $nama_kecamatan_user . ' / ' . $nama_desa_user . '.';
                header('Location: petugas_desa_status_permohonan.php');
                exit;
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    if (!empty($errors) && isset($uploadDir) && is_dir($uploadDir)) {
        siprima_remove_upload_tree($uploadDir, $baseUploadDir);
        $uploadedFiles = [];
    }

    // Set selected values for form repopulation (selalu sesuai user)
    $selected_kecamatan = $user_kecamatan_id;
    $selected_desa = $user_desa_id;
}

// Fungsi untuk membuat nama folder sesuai format
// Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama kepala keluarga]_[tanggal buat]
function generateFolderName($kecamatan, $desa, $nik_pemohon, $nama_pemohon, $nama_meninggal) {
    // Bersihkan string dari karakter yang tidak diinginkan
    function cleanString($string) {
        $string = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $string);
        $string = preg_replace('/\s+/', '_', trim($string));
        return $string;
    }
    
    $cleanKecamatan = cleanString($kecamatan);
    $cleanDesa = cleanString($desa);
    $cleanNamaPemohon = cleanString($nama_pemohon);
    $cleanNamaMeninggal = cleanString($nama_meninggal);
    
    // Potong jika terlalu panjang (maksimal 50 karakter per bagian)
    $cleanKecamatan = substr($cleanKecamatan, 0, 50);
    $cleanDesa = substr($cleanDesa, 0, 50);
    $cleanNamaPemohon = substr($cleanNamaPemohon, 0, 50);
    $cleanNamaMeninggal = substr($cleanNamaMeninggal, 0, 50);
    
    // Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama kepala keluarga]_[timestamp]
    $timestamp = date('Ymd_His');
    $folderName = $cleanKecamatan . '_' . 
                  $cleanDesa . '_' . 
                  $nik_pemohon . '_' . 
                  $cleanNamaPemohon . '_' . 
                  $cleanNamaMeninggal . '_' . 
                  $timestamp;
    
    // Batasi panjang folder name
    if (strlen($folderName) > 200) {
        $folderName = 'AKTA_KEMATIAN_' . $nik_pemohon . '_' . $timestamp;
    }
    
    return $folderName;
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
  <title>Ajukan Akta Kematian (Petugas Desa)</title>
  <link rel="stylesheet" href="css/petugas_desa_akta_kematian.css">
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
          'Ajukan Akta Kematian',
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
                <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Form Pengajuan Akta Kematian</h2>
              <p class="helper">Sebagai petugas desa, Anda hanya dapat mengajukan permohonan akta kematian untuk wilayah Anda.</p>
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
            <script>window._userUtils?.removeToasts(5000);</script>
          <?php endif; ?>

          <?php if (!empty($errors)): ?>
            <div class="toast toast-error">
              <?= esc($errors[0]) ?>
            </div>
            <script>window._userUtils?.removeToasts(5000);</script>

            <div class="error-box">
              <?php foreach ($errors as $err) echo "<p>" . esc($err) . "</p>"; ?>
              <p>Data teks tetap tersimpan. Demi keamanan browser, pilih ulang file yang disebut gagal di atas.</p>
            </div>
          <?php endif; ?>

          <div class="info-box">
            <p><span class="info-icon">🔒</span> Anda sebagai <strong>Petugas Desa</strong> hanya dapat mengajukan permohonan akta kematian untuk wilayah:</p>
            <p><strong>Kecamatan:</strong> <?= esc($nama_kecamatan_user) ?> | <strong>Desa:</strong> <?= esc($nama_desa_user) ?></p>
          </div>

          <form method="POST" enctype="multipart/form-data" class="space-y-4" id="aktaKematianForm">
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

            <!-- Kecamatan & Desa - Dikunci -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Kecamatan <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
                <select name="id_kecamatan" id="kecamatanSelect" required class="select locked-select" disabled>
                  <option value="<?php echo $user_kecamatan_id; ?>" selected><?php echo htmlspecialchars($nama_kecamatan_user); ?></option>
                </select>
                <input type="hidden" name="id_kecamatan" value="<?php echo $user_kecamatan_id; ?>">
              </div>

              <div>
                <label class="field-label">Desa <span style="color:red">*</span> <span class="lock-icon">🔒</span></label>
                <select name="id_desa" id="desaSelect" required class="select locked-select" disabled>
                  <option value="<?php echo $user_desa_id; ?>" selected><?php echo htmlspecialchars($nama_desa_user); ?></option>
                </select>
                <input type="hidden" name="id_desa" value="<?php echo $user_desa_id; ?>">
              </div>
            </div>

            <!-- Nama Kecamatan & Desa (otomatis terisi) -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Kecamatan</label>
                <input type="text" id="namaKecamatan" readonly class="input bg-gray-100" value="<?php echo htmlspecialchars($nama_kecamatan_user); ?>">
              </div>

              <div>
                <label class="field-label">Nama Desa</label>
                <input type="text" id="namaDesa" readonly class="input bg-gray-100" value="<?php echo htmlspecialchars($nama_desa_user); ?>">
              </div>
            </div>

            <!-- Data Orang yang Meninggal -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Orang yang Meninggal</h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Alamat/Dusun <span style="color:red">*</span></label>
                <input type="text" name="dusun" value="<?= esc($_POST['dusun'] ?? '') ?>" required class="input" placeholder="Nama dusun/lingkungan">
              </div>

              <div>
                <label class="field-label">Tempat Kematian <span style="color:red">*</span></label>
                <input type="text" name="tempat" value="<?= esc($_POST['tempat'] ?? '') ?>" required class="input" placeholder="Kabupaten Gorontalo / Kota Gorontalo">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Yang Meninggal <span style="color:red">*</span></label>
                <input type="text" name="nama_meninggal" value="<?= esc($_POST['nama_meninggal'] ?? '') ?>" required class="input" placeholder="Nama lengkap">
              </div>
              <div>
                <label class="field-label">NIK Yang Meninggal <span style="color:red">*</span></label>
                <input type="text" name="nik_meninggal" value="<?= esc($_POST['nik_meninggal'] ?? '') ?>" class="input" required placeholder="16 digit NIK" maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Tanggal Kematian <span style="color:red">*</span></label>
                <input type="date" name="tanggal_kematian" id="tanggal_kematian" value="<?= esc($_POST['tanggal_kematian'] ?? '') ?>" required class="input" max="<?php echo date('Y-m-d'); ?>">
              </div>
            </div>

            <!-- Data Orang Tua (REQUIRED) -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Orang Tua <span style="color:red">*</span></h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Ayah <span style="color:red">*</span></label>
                <input type="text" name="nama_ayah" value="<?= esc($_POST['nama_ayah'] ?? '') ?>" required class="input" placeholder="Nama ayah">
              </div>
              <div>
                <label class="field-label">NIK Ayah <span style="color:red">*</span></label>
                <input type="text" name="nik_ayah" value="<?= esc($_POST['nik_ayah'] ?? '') ?>" required class="input" placeholder="16 digit NIK ayah" maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Ibu <span style="color:red">*</span></label>
                <input type="text" name="nama_ibu" value="<?= esc($_POST['nama_ibu'] ?? '') ?>" required class="input" placeholder="Nama ibu">
              </div>
              <div>
                <label class="field-label">NIK Ibu <span style="color:red">*</span></label>
                <input type="text" name="nik_ibu" value="<?= esc($_POST['nik_ibu'] ?? '') ?>" required class="input" placeholder="16 digit NIK ibu" maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
              </div>
            </div>

            <!-- Data Saksi 1 (REQUIRED) -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Saksi 1 <span style="color:red">*</span></h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Saksi 1 <span style="color:red">*</span></label>
                <input type="text" name="nama_saksi1" value="<?= esc($_POST['nama_saksi1'] ?? '') ?>" required class="input" placeholder="Nama lengkap saksi 1">
              </div>
              <div>
                <label class="field-label">NIK Saksi 1 <span style="color:red">*</span></label>
                <input type="text" name="nik_saksi1" value="<?= esc($_POST['nik_saksi1'] ?? '') ?>" required class="input" placeholder="16 digit NIK saksi 1" maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
              </div>
            </div>

            <!-- Data Saksi 2 (REQUIRED) -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Saksi 2 <span style="color:red">*</span></h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Saksi 2 <span style="color:red">*</span></label>
                <input type="text" name="nama_saksi2" value="<?= esc($_POST['nama_saksi2'] ?? '') ?>" required class="input" placeholder="Nama lengkap saksi 2">
              </div>
              <div>
                <label class="field-label">NIK Saksi 2 <span style="color:red">*</span></label>
                <input type="text" name="nik_saksi2" value="<?= esc($_POST['nik_saksi2'] ?? '') ?>" required class="input" placeholder="16 digit NIK saksi 2" maxlength="16" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)">
              </div>
            </div>

            <!-- Keterangan / Pesan -->
            <div>
              <label class="field-label">Keterangan / Pesan untuk Operator <span style="color:red">*</span></label>
              <textarea name="keterangan" required class="input" rows="3" placeholder="Contoh: Permohonan akta kematian untuk keperluan administrasi warisan, dll." maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
            </div>

            <!-- File Upload Multiple -->
            <div>
              <label class="field-label">Unggah Dokumen Pendukung <span style="color:red">*</span></label>
              
              <!-- Dokumen WAJIB -->
              <div class="wajib-container">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                </h4>
                <div id="wajibUploadContainer">
                  <!-- Formulir F-2.01 Kematian (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib1">
                    <input type="hidden" name="file_index[]" value="0">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="formulir_kematian" selected>Formulir F-2.01 Kematian</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Pilih File</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Formulir F-2.01 Kematian yang sudah diisi dan ditandatangani
                    </p>
                  </div>
                  
                  <!-- Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib2">
                    <input type="hidden" name="file_index[]" value="1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="surat_keterangan_kematian" selected>Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Pilih File</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Surat keterangan kematian dari Rumah Sakit, Puskesmas, atau Desa/Kelurahan
                    </p>
                  </div>
                  
                  <!-- KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib3">
                    <input type="hidden" name="file_index[]" value="2">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="kk_ktp_meninggal" selected>KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib3">Pilih File</label>
                      <span id="fileNameWajib3" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib3" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib3">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> KK/KTP yang meninggal atau Surat Keterangan Hilang KK/KTP
                    </p>
                  </div>
                </div>
              </div>
              
              <!-- Dokumen OPSIONAL -->
              <div class="opsional-container">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-check-circle"></i> Dokumen Pendukung Lainnya (Jika Ada)
                </h4>
                <div id="opsionalUploadContainer">
                  <!-- Input opsional pertama -->
                  <div class="file-input-group" id="fileGroupOpsional1">
                    <input type="hidden" name="file_index[]" value="3">
                    <select name="doc_type[]" class="file-type-select">
                      <option value="">Pilih Jenis Dokumen (Opsional)</option>
                      <option value="surat_keterangan_hilang">Surat Keterangan Hilang KTP/Kartu Keluarga</option>
                      <option value="akta_lahir">Akta Kelahiran</option>
                      <option value="akta_kawin">Akta Perkawinan/Buku Nikah</option>
                      <option value="ktp_ahli_waris">KTP Ahli Waris</option>
                      <option value="surat_kuasa">Surat Kuasa Ahli Waris</option>
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
              
              <p class="helper"><strong>Format file: JPG, PNG, atau GIF. Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong></p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="petugas_desa_ajukan_permohonan.php" class="btn-secondary">Kembali</a>
              <button type="submit" class="btn-primary">Ajukan Permohonan Akta Kematian</button>
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
  document.addEventListener('DOMContentLoaded', function() {
    // Toggle Sidebar Menu on Mobile
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

    // Set nilai awal untuk nama kecamatan/desa
    document.getElementById("namaKecamatan").value = "<?= addslashes($nama_kecamatan_user) ?>";
    document.getElementById("namaDesa").value = "<?= addslashes($nama_desa_user) ?>";
  });

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
    const fileIndex = fileCounter.wajib + fileCounter.opsional - 1;
    newFileGroup.innerHTML = `
      <input type="hidden" name="file_index[]" value="${fileIndex}">
      <select name="doc_type[]" class="file-type-select">
        <option value="">Pilih Jenis Dokumen (Opsional)</option>
        <option value="surat_keterangan_hilang">Surat Keterangan Hilang KTP/Kartu Keluarga</option>
        <option value="akta_lahir">Akta Kelahiran</option>
        <option value="akta_kawin">Akta Perkawinan/Buku Nikah</option>
        <option value="ktp_ahli_waris">KTP Ahli Waris</option>
        <option value="surat_kuasa">Surat Kuasa Ahli Waris</option>
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
            this.dataset.uploadReadable = '1';
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
        alert('Dokumen pendukung pertama tidak dapat dihapus. Kosongkan saja jika tidak ada.');
      }
    }
  }

  // Initialize file input event listeners
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
            this.dataset.uploadReadable = '1';
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
              this.dataset.uploadReadable = '1';
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
  document.getElementById('aktaKematianForm').addEventListener('submit', function(e) {
    const allFileInputs = document.querySelectorAll('input[type="file"]');
    let hasError = false;
    let errorMessages = [];
    let totalUploadSize = 0;

    const csrfInput = this.querySelector('input[name="csrf_token"]');
    if (!csrfInput || !csrfInput.value.trim()) {
      errorMessages.push('Token keamanan tidak ditemukan. Muat ulang halaman lalu coba lagi.');
      hasError = true;
    }
    
    // Validasi NIK ayah harus 16 digit (required)
    const nikAyahInput = document.querySelector('input[name="nik_ayah"]');
    if (nikAyahInput && (!nikAyahInput.value || !/^\d{16}$/.test(nikAyahInput.value))) {
      errorMessages.push('NIK ayah harus 16 digit angka');
      hasError = true;
      nikAyahInput.style.borderColor = '#ef4444';
    } else if (nikAyahInput) {
      nikAyahInput.style.borderColor = '#d1d5db';
    }
    
    // Validasi NIK ibu harus 16 digit (required)
    const nikIbuInput = document.querySelector('input[name="nik_ibu"]');
    if (nikIbuInput && (!nikIbuInput.value || !/^\d{16}$/.test(nikIbuInput.value))) {
      errorMessages.push('NIK ibu harus 16 digit angka');
      hasError = true;
      nikIbuInput.style.borderColor = '#ef4444';
    } else if (nikIbuInput) {
      nikIbuInput.style.borderColor = '#d1d5db';
    }
    
    // Validasi NIK saksi 1 harus 16 digit (required)
    const nikSaksi1Input = document.querySelector('input[name="nik_saksi1"]');
    if (nikSaksi1Input && (!nikSaksi1Input.value || !/^\d{16}$/.test(nikSaksi1Input.value))) {
      errorMessages.push('NIK saksi 1 harus 16 digit angka');
      hasError = true;
      nikSaksi1Input.style.borderColor = '#ef4444';
    } else if (nikSaksi1Input) {
      nikSaksi1Input.style.borderColor = '#d1d5db';
    }
    
    // Validasi NIK saksi 2 harus 16 digit (required)
    const nikSaksi2Input = document.querySelector('input[name="nik_saksi2"]');
    if (nikSaksi2Input && (!nikSaksi2Input.value || !/^\d{16}$/.test(nikSaksi2Input.value))) {
      errorMessages.push('NIK saksi 2 harus 16 digit angka');
      hasError = true;
      nikSaksi2Input.style.borderColor = '#ef4444';
    } else if (nikSaksi2Input) {
      nikSaksi2Input.style.borderColor = '#d1d5db';
    }
    
    // Validasi NIK yang meninggal jika diisi harus 16 digit
    const nikMeninggalInput = document.querySelector('input[name="nik_meninggal"]');
    if (nikMeninggalInput && nikMeninggalInput.value && !/^\d{16}$/.test(nikMeninggalInput.value)) {
      errorMessages.push('NIK yang meninggal harus 16 digit angka atau kosongkan');
      hasError = true;
      nikMeninggalInput.style.borderColor = '#ef4444';
    } else if (nikMeninggalInput) {
      nikMeninggalInput.style.borderColor = '#d1d5db';
    }
    
    // Validasi tanggal kematian tidak boleh di masa depan
    const tanggalKematianInput = document.querySelector('input[name="tanggal_kematian"]');
    if (tanggalKematianInput && tanggalKematianInput.value) {
      const today = new Date();
      const deathDate = new Date(tanggalKematianInput.value);
      if (deathDate > today) {
        errorMessages.push('Tanggal kematian tidak boleh di masa depan');
        hasError = true;
        tanggalKematianInput.style.borderColor = '#ef4444';
      } else {
        tanggalKematianInput.style.borderColor = '#d1d5db';
      }
    }
    
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
    const suratInput = document.getElementById('fileInputWajib2');
    const kkKtpInput = document.getElementById('fileInputWajib3');
    
    if (!formulirInput.files.length) {
      errorMessages.push('Formulir F-2.01 Kematian wajib diupload');
      hasError = true;
      formulirInput.style.borderColor = '#ef4444';
    } else {
      formulirInput.style.borderColor = '#d1d5db';
    }
    
    if (!suratInput.files.length) {
      errorMessages.push('Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan wajib diupload');
      hasError = true;
      suratInput.style.borderColor = '#ef4444';
    } else {
      suratInput.style.borderColor = '#d1d5db';
    }
    
    if (!kkKtpInput.files.length) {
      errorMessages.push('KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal wajib diupload');
      hasError = true;
      kkKtpInput.style.borderColor = '#ef4444';
    } else {
      kkKtpInput.style.borderColor = '#d1d5db';
    }
    
    // Validasi required fields (semua field kecuali file upload)
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
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan akta kematian?\n\nPastikan Anda telah mengupload:\n1. Formulir F-2.01 Kematian\n2. Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan\n3. KK/KTP / Surat Keterangan Hilang KK/KTP yang meninggal.');
      if (!confirmSubmit) {
        e.preventDefault();
      }
    }
  });
  </script>
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>
