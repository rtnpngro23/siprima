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
$selected_kecamatan_tujuan = isset($_POST['id_kecamatan_tujuan']) ? (int)$_POST['id_kecamatan_tujuan'] : '';
$selected_desa_tujuan = isset($_POST['id_desa_tujuan']) ? (int)$_POST['id_desa_tujuan'] : '';
$pre_nama_kecamatan_tujuan = '';
$pre_nama_desa_tujuan = '';

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi dan sanitasi input
    $id_kecamatan_tujuan = isset($_POST['id_kecamatan_tujuan']) ? (int)$_POST['id_kecamatan_tujuan'] : 0;
    $id_desa_tujuan = isset($_POST['id_desa_tujuan']) ? (int)$_POST['id_desa_tujuan'] : 0;
    
    // Data pindah online (pindah masuk ke Gorontalo)
    $alamat_asal = trim($_POST['alamat_asal'] ?? '');
    $alamat_tujuan = trim($_POST['alamat_tujuan'] ?? '');
    $no_kk = trim($_POST['no_kk'] ?? '');
    $nama_kepalaKeluarga = trim($_POST['nama_kepalaKeluarga'] ?? '');
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
    if (empty($alamat_asal)) $errors[] = 'Alamat asal (di luar Kabupaten Gorontalo) wajib diisi.';
    if (empty($alamat_tujuan)) $errors[] = 'Alamat tujuan lengkap di Kabupaten Gorontalo wajib diisi.';
    if (empty($no_kk)) $errors[] = 'Nomor Kartu Keluarga (No KK) wajib diisi.';
    if (strlen($no_kk) !== 16) $errors[] = 'Nomor Kartu Keluarga harus 16 digit.';
    if (empty($nama_kepalaKeluarga)) $errors[] = 'Nama Kepala Keluarga wajib diisi.';
    if (empty($alasan_pindah)) $errors[] = 'Alasan pindah wajib diisi.';
    if (empty($anggotaKeluarga)) $errors[] = 'Minimal harus ada satu anggota keluarga yang pindah.';
    if ($id_kecamatan_tujuan <= 0) $errors[] = 'Kecamatan tujuan di Kabupaten Gorontalo wajib dipilih.';
    if ($id_desa_tujuan <= 0) $errors[] = 'Desa tujuan di Kabupaten Gorontalo wajib dipilih.';

    // Ambil nama kecamatan dan desa tujuan
    $nama_kecamatan_tujuan = '';
    $nama_desa_tujuan = '';
    if (!empty($id_kecamatan_tujuan)) {
        $qk = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $qk->bind_param("i", $id_kecamatan_tujuan);
        $qk->execute();
        $rk = $qk->get_result()->fetch_assoc();
        if ($rk) $nama_kecamatan_tujuan = $rk['nama_kecamatan'];
        $qk->close();
    }
    
    if (!empty($id_desa_tujuan)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $qd->bind_param("i", $id_desa_tujuan);
        $qd->execute();
        $rd = $qd->get_result()->fetch_assoc();
        if ($rd) $nama_desa_tujuan = $rd['nama_desa'];
        $qd->close();
    }

    // ============================================================
    // BAGIAN PENTING: MEMBUAT NAMA FOLDER SESUAI FORMAT
    // ============================================================
    
    // Siapkan data untuk folder name
    $nama_pemohon = $data_user['nama'] ?? '';
    $nik_pemohon = $data_user['nik'] ?? '';
    $tanggal_buat = date('Ymd_His'); // Format: YYYYMMDD_HHMMSS
    
    // Fungsi sanitasi nama untuk folder (menghilangkan karakter khusus)
    function cleanFolderName($string) {
        // Hapus karakter khusus kecuali huruf, angka, dan spasi
        $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', $string);
        // Ganti spasi dengan underscore
        $string = preg_replace('/\s+/', '_', trim($string));
        // Ubah ke lowercase untuk konsistensi
        $string = strtolower($string);
        return $string;
    }
    
    // Bersihkan setiap komponen nama
    $kecamatan_clean = cleanFolderName($nama_kecamatan_tujuan);
    $desa_clean = cleanFolderName($nama_desa_tujuan);
    $nik_clean = preg_replace('/[^0-9]/', '', $nik_pemohon); // Hanya angka
    $pemohon_clean = cleanFolderName($nama_pemohon);
    $kepala_keluarga_clean = cleanFolderName($nama_kepalaKeluarga);
    
    // Buat nama folder sesuai format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama kepala keluarga]_[tanggal buat]
    $folder_name = 
        $kecamatan_clean . '_' . 
        $desa_clean . '_' . 
        $nik_clean . '_' . 
        $pemohon_clean . '_' . 
        $kepala_keluarga_clean . '_' . 
        $tanggal_buat;
    
    // Batasi panjang folder name (max 255 karakter untuk filesystem)
    if (strlen($folder_name) > 200) {
        // Potong nama kepala keluarga jika terlalu panjang
        $max_kepala_length = 200 - (strlen($kecamatan_clean) + strlen($desa_clean) + 
                                   strlen($nik_clean) + strlen($pemohon_clean) + 
                                   strlen($tanggal_buat) + 5); // 5 untuk underscore
        
        if ($max_kepala_length > 10) {
            $kepala_keluarga_clean = substr($kepala_keluarga_clean, 0, $max_kepala_length);
            $folder_name = 
                $kecamatan_clean . '_' . 
                $desa_clean . '_' . 
                $nik_clean . '_' . 
                $pemohon_clean . '_' . 
                $kepala_keluarga_clean . '_' . 
                $tanggal_buat;
        } else {
            // Jika masih terlalu panjang, gunakan hash pendek
            $folder_hash = substr(md5($folder_name), 0, 8);
            $folder_name = 
                $kecamatan_clean . '_' . 
                $desa_clean . '_' . 
                $nik_clean . '_' . 
                $pemohon_clean . '_' . 
                $folder_hash . '_' . 
                $tanggal_buat;
        }
    }
    
    // Debug: Tampilkan nama folder (opsional)
    // error_log("Nama folder yang dibuat: " . $folder_name);
    
    // Path folder utama uploads
    $base_upload_dir = __DIR__ . '/uploads/pindah_online/';
    
    // Buat folder utama jika belum ada
    if (!is_dir($base_upload_dir)) {
        mkdir($base_upload_dir, 0755, true);
    }
    
    // Path folder khusus untuk permohonan ini
    $permohonan_dir = $base_upload_dir . $folder_name . '/';
    
    // Buat folder khusus untuk permohonan jika belum ada
    if (!is_dir($permohonan_dir)) {
        if (!mkdir($permohonan_dir, 0755, true)) {
            $errors[] = 'Gagal membuat folder penyimpanan dokumen: ' . $permohonan_dir;
        }
    }

    // File upload - MULTIPLE FILES, maksimal 1MB SETELAH kompresi otomatis di sisi klien
    $uploadedFiles = [];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Dokumen wajib berdasarkan persyaratan
    $requiredDocs = ['formulir_pindah', 'kk_ktp', 'foto_pemohon']; // Wajib sesuai permintaan
    $uploadedDocTypes = [];
    
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name']) && empty($errors)) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        // Buat subfolder untuk dokumen pendukung (opsional)
        $pendukung_dir = $permohonan_dir . 'dokumen_pendukung/';
        if (!is_dir($pendukung_dir)) {
            mkdir($pendukung_dir, 0755, true);
        }
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $fileSize = $_FILES['dokumen']['size'][$i];
                $docType = $_POST['doc_type'][$i] ?? 'lainnya';

                $fileType = '';
                $ext = '';
                $uploadValidationError = siprima_validate_uploaded_image(
                    $fileTmp, $fileName, $fileSize, $maxFileSize, $fileType, $ext
                );
                if ($uploadValidationError !== '') {
                    $errors[] = $uploadValidationError;
                    continue;
                }

                // Generate nama file yang lebih deskriptif
                $docTypeName = '';
                switch ($docType) {
                    case 'formulir_pindah':
                        $docTypeName = 'formulir_f103_pindah';
                        break;
                    case 'kk_ktp':
                        $docTypeName = 'kk_ktp_surat_hilang';
                        break;
                    case 'foto_pemohon':
                        $docTypeName = 'foto_pemohon_memegang_dokumen';
                        break;
                    case 'buku_nikah':
                        $docTypeName = 'buku_akta_nikah';
                        break;
                    default:
                        $docTypeName = 'dokumen_lainnya';
                }
                
                // Tambahkan timestamp dan random string untuk menghindari duplikasi
                $timestamp_file = time();
                $randomString = bin2hex(random_bytes(4));
                $newName = $docTypeName . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                
                // Tentukan lokasi penyimpanan
                if ($docType === 'lainnya') {
                    $destination = $pendukung_dir . $newName;
                    $savePath = 'uploads/pindah_online/' . $folder_name . '/dokumen_pendukung/' . $newName;
                } else {
                    $destination = $permohonan_dir . $newName;
                    $savePath = 'uploads/pindah_online/' . $folder_name . '/' . $newName;
                }

                if (move_uploaded_file($fileTmp, $destination)) {
                    $uploadedFiles[] = [
                        'path' => $savePath,
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
                $errorMsg = "File {$failedName}: " . siprima_upload_error_message($_FILES['dokumen']['error'][$i]);
                // Cek apakah file ini wajib
                $docType = $_POST['doc_type'][$i] ?? '';
                if (in_array($docType, $requiredDocs)) {
                    $errorMsg .= " (Dokumen wajib!)";
                }
                $errors[] = $errorMsg;
            }
        }
        
        // Tambahkan file .htaccess untuk keamanan
        $htaccessFile = $permohonan_dir . '.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, 
                "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png)$\">\nAllow from all\n</Files>");
        }
    }

    // Cek dokumen wajib - semua harus ada
    $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
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
        
        // Query untuk insert data pindah online DENGAN nama_folder
        $stmt = $koneksi->prepare("INSERT INTO tb_pindah_online
            (id_user, id_kecamatan, id_desa, nama_pemohon, nik_pemohon, no_hp, email,
             alamat_asal, nama_kecamatan, nama_desa, alamat_tujuan, no_kk, nama_kepalaKeluarga,
             alasan_pindah, anggotaKeluarga, dokumen, status, alasan, keterangan, nama_folder, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if (!$stmt) {
            $errors[] = 'Prepare statement error: ' . $koneksi->error;
        } else {
            $stmt->bind_param("iiisssssssssssssssss",
                $user_id,               // i (integer)
                $id_kecamatan_tujuan,   // i (integer)
                $id_desa_tujuan,        // i (integer)
                $nama_pemohon,          // s (string)
                $nik_pemohon,           // s (string)
                $no_hp,                 // s (string)
                $email,                 // s (string)
                $alamat_asal,           // s (string)
                $nama_kecamatan_tujuan, // s (string)
                $nama_desa_tujuan,      // s (string)
                $alamat_tujuan,         // s (string)
                $no_kk,                 // s (string)
                $nama_kepalaKeluarga,   // s (string)
                $alasan_pindah,         // s (string)
                $anggotaKeluargaJson,   // s (string - JSON)
                $dokumenJson,           // s (string - JSON)
                $status,                // s (string)
                $alasan,                // s (string) - dikosongkan untuk operator
                $keterangan,            // s (string)
                $folder_name            // s (string) - nama folder penyimpanan
            );
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Permohonan pindah masuk ke Kabupaten Gorontalo berhasil diajukan.';
                header('Location: ajukan_permohonan.php');
                exit;
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
                // Hapus folder yang sudah dibuat jika insert gagal (opsional)
                if (is_dir($permohonan_dir)) {
                    // Hapus semua file dalam folder
                    array_map('unlink', glob($permohonan_dir . '/*'));
                    if (is_dir($pendukung_dir)) {
                        array_map('unlink', glob($pendukung_dir . '/*'));
                        rmdir($pendukung_dir);
                    }
                    // Hapus folder utama
                    rmdir($permohonan_dir);
                }
            }
            $stmt->close();
        }
    }
    
    if (!empty($errors) && isset($permohonan_dir) && is_dir($permohonan_dir)) {
        siprima_remove_upload_tree($permohonan_dir, $base_upload_dir);
        $uploadedFiles = [];
    }

    // Set selected values for form repopulation
    $selected_kecamatan_tujuan = $id_kecamatan_tujuan;
    $selected_desa_tujuan = $id_desa_tujuan;
}

// Ambil nama kecamatan dan desa untuk form repopulation
if (!empty($selected_kecamatan_tujuan)) {
    $qk2 = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
    $qk2->bind_param("i", $selected_kecamatan_tujuan);
    $qk2->execute();
    $rk2 = $qk2->get_result()->fetch_assoc();
    if ($rk2) $pre_nama_kecamatan_tujuan = $rk2['nama_kecamatan'];
    $qk2->close();
}

if (!empty($selected_desa_tujuan)) {
    $qd2 = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
    $qd2->bind_param("i", $selected_desa_tujuan);
    $qd2->execute();
    $rd2 = $qd2->get_result()->fetch_assoc();
    if ($rd2) $pre_nama_desa_tujuan = $rd2['nama_desa'];
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ajukan Pindah Masuk ke Kabupaten Gorontalo</title>
  <link rel="stylesheet" href="css/pindah_online.css">
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
        render_user_header_brand('Pindah Masuk ke Kabupaten Gorontalo');
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
              <h2 class="form-title">Form Pengajuan Pindah Masuk ke Kabupaten Gorontalo</h2>
              <p class="helper">Untuk penduduk yang berada di luar Kabupaten Gorontalo ingin pindah masuk.</p>
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

          <form method="POST" enctype="multipart/form-data" class="space-y-4" id="pindahOnlineForm">
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
                <input type="text" name="nama_kepalaKeluarga" 
                       value="<?= esc($_POST['nama_kepalaKeluarga'] ?? '') ?>" 
                       required class="input" 
                       placeholder="Nama lengkap kepala keluarga">
              </div>
            </div>

            <!-- Alamat Asal (di luar Gorontalo) -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Alamat Asal (Di Luar Kabupaten Gorontalo)</h3>

            <div>
              <label class="field-label">Alamat Asal Lengkap <span style="color:red">*</span></label>
              <textarea name="alamat_asal" class="input" rows="3" required placeholder="Alamat lengkap saat ini di luar Kabupaten Gorontalo (nama jalan, RT/RW, dusun, kelurahan, kecamatan, kabupaten/kota, provinsi)"><?= esc($_POST['alamat_asal'] ?? '') ?></textarea>
              <p class="helper">Contoh: Jl. Sudirman No. 45, RT 02/RW 03, Kelurahan Menteng, Kecamatan Menteng, Kota Jakarta Pusat, Provinsi DKI Jakarta</p>
            </div>

            <!-- Alamat Tujuan (di Gorontalo) -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Alamat Tujuan (Di Kabupaten Gorontalo)</h3>

            <!-- Pilih Kecamatan dan Desa -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Kecamatan Tujuan <span style="color:red">*</span></label>
                <select name="id_kecamatan_tujuan" id="kecamatanSelectTujuan" required class="select">
                  <option value="">-- Pilih Kecamatan di Kabupaten Gorontalo --</option>
                  <?php
                  $kec = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
                  while ($row = $kec->fetch_assoc()) {
                      $sel = ($selected_kecamatan_tujuan == $row['id']) ? ' selected' : '';
                      echo "<option value='" . esc($row['id']) . "'{$sel}>" . esc($row['nama_kecamatan']) . "</option>";
                  }
                  ?>
                </select>
              </div>

              <div>
                <label class="field-label">Desa Tujuan <span style="color:red">*</span></label>
                <select name="id_desa_tujuan" id="desaSelectTujuan" required class="select">
                  <option value="">-- Pilih Desa di Kabupaten Gorontalo --</option>
                <?php
                if (!empty($selected_kecamatan_tujuan)) {
                    $des = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
                    $des->bind_param("i", $selected_kecamatan_tujuan);
                    $des->execute();
                    $resDes = $des->get_result();
                    while ($drow = $resDes->fetch_assoc()) {
                        $selD = ($selected_desa_tujuan == $drow['id']) ? ' selected' : '';
                        echo "<option value='" . esc($drow['id']) . "'{$selD}>" . esc($drow['nama_desa']) . "</option>";
                    }
                    $des->close();
                }
                ?>
                </select>
              </div>
            </div>

            <!-- Nama Kecamatan & Desa Tujuan (otomatis terisi) -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Kecamatan Tujuan</label>
                <input type="text" id="namaKecamatanTujuan" readonly class="input bg-gray-100" value="<?= esc($pre_nama_kecamatan_tujuan ?? '') ?>">
              </div>

              <div>
                <label class="field-label">Nama Desa Tujuan</label>
                <input type="text" id="namaDesaTujuan" readonly class="input bg-gray-100" value="<?= esc($pre_nama_desa_tujuan ?? '') ?>">
              </div>
            </div>

            <!-- Alamat Tujuan Lengkap -->
            <div>
              <label class="field-label">Alamat Tujuan Lengkap <span style="color:red">*</span></label>
              <textarea name="alamat_tujuan" class="input" rows="3" required placeholder="Alamat lengkap tujuan di Kabupaten Gorontalo (nama jalan, RT/RW, dusun, nomor rumah)"><?= esc($_POST['alamat_tujuan'] ?? '') ?></textarea>
              <p class="helper">Contoh: Jl. Merdeka No. 123, RT 01/RW 02, Dusun Suka Maju, Desa [nama desa yang dipilih]</p>
            </div>

            <!-- Alasan Pindah -->
            <div>
              <label class="field-label">Alasan Pindah ke Kabupaten Gorontalo <span style="color:red">*</span></label>
              <select name="alasan_pindah" required class="select">
                <option value="">-- Pilih Alasan Pindah --</option>
                <option value="kerja" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'kerja' ? 'selected' : '' ?>>Pekerjaan</option>
                <option value="pendidikan" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'pendidikan' ? 'selected' : '' ?>>Pendidikan</option>
                <option value="keluarga" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'keluarga' ? 'selected' : '' ?>>Keluarga</option>
                <option value="rumah" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'rumah' ? 'selected' : '' ?>>Pindah Rumah</option>
                <option value="kembali" <?= isset($_POST['alasan_pindah']) && $_POST['alasan_pindah'] == 'kembali' ? 'selected' : '' ?>>Kembali ke Kampung Halaman</option>
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
                <p class="helper">Isi data anggota keluarga yang ikut pindah ke Kabupaten Gorontalo.</p>
              </div>
            </div>

            <div>
              <label class="field-label">Keterangan Tambahan</label>
              <textarea name="keterangan" class="input" rows="3" placeholder="Keterangan tambahan untuk operator (jika ada)" maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
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
                      <option value="formulir_pindah" selected>Formulir F-1.03 Pindah Penduduk</option>
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

                  <div class="file-input-group" id="fileGroupWajib2">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="kk_ktp" selected>KK/KTP/Surat Keterangan Hilang KK/KTP</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Pilih File</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Pilih salah satu: KK, KTP, atau Surat Keterangan Hilang
                    </p>
                  </div>

                  <div class="file-input-group" id="fileGroupWajib3">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="foto_pemohon" selected>Foto Selfie Pemohon Memegang Formulir</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib3">Upload Foto Selfie</label>
                      <span id="fileNameWajib3" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib3" name="dokumen[]" accept="image/jpeg,image/png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib3">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Foto selfie pemohon terbaru sambil memegang formulir
                    </p>
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
                      <option value="buku_nikah">Buku Nikah/Akta Nikah</option>
                      <option value="lainnya">Dokumen Pendukung Lainnya</option>
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
                  + Tambah Dokumen Opsional Lainnya
                </button>
              </div>
              
              <p class="helper">
                <strong>Dokumen wajib:</strong><br>
                1. Formulir F-1.03 Pindah Penduduk<br>
                2. KK/KTP/Surat Keterangan Hilang KK/KTP<br>
                3. Foto Selfie Pemohon Memegang Formulir<br><br>
                <strong>Format:</strong> JPG, JPEG, JFIF, JPE, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong><br>
              </p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="ajukan_permohonan.php" class="btn-secondary">Kembali</a>
              <button type="submit" class="btn-primary">Ajukan Permohonan Pindah Masuk</button>
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
  // JavaScript untuk form pindah online
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

  // AJAX untuk Desa Tujuan & Auto-fill Nama
  document.getElementById("kecamatanSelectTujuan").addEventListener("change", function() {
    var idKecamatan = this.value;
    var desaSelect = document.getElementById("desaSelectTujuan");
    var namaKecamatanInput = document.getElementById("namaKecamatanTujuan");
    var namaDesaInput = document.getElementById("namaDesaTujuan");
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

  document.getElementById("desaSelectTujuan").addEventListener("change", function() {
    var namaDesaInput = document.getElementById("namaDesaTujuan");
    if (this.value !== "") {
      namaDesaInput.value = this.options[this.selectedIndex].text;
    } else {
      namaDesaInput.value = "";
    }
  });

  // Set initial values dari server jika ada
  window.addEventListener("DOMContentLoaded", function() {
    var preKec = "<?= addslashes($pre_nama_kecamatan_tujuan ?? '') ?>";
    var preDes = "<?= addslashes($pre_nama_desa_tujuan ?? '') ?>";
    if (preKec) document.getElementById("namaKecamatanTujuan").value = preKec;
    if (preDes) document.getElementById("namaDesaTujuan").value = preDes;
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
        <option value="buku_nikah">Buku Nikah/Akta Nikah</option>
        <option value="lainnya">Dokumen Pendukung Lainnya</option>
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
          
          if (file.size > MAX_FILE_SIZE) {
            newFileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (gagal dikompres, pilih gambar lain)</span>`;
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
            
            if (file.size > MAX_FILE_SIZE) {
              fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (gagal dikompres, pilih gambar lain)</span>`;
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

  // Validasi form sebelum submit
  document.getElementById('pindahOnlineForm').addEventListener('submit', function(e) {
    const fileInputs = document.querySelectorAll('input[type="file"]');
    const anggotaRows = document.querySelectorAll('#anggotaBody tr');
    let hasError = false;
    let errorMessages = [];
    
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
    fileInputs.forEach((input, index) => {
      if (input.files.length > 0) {
        const file = input.files[0];
        if (file.size > MAX_FILE_SIZE) {
          errorMessages.push(`File "${file.name}" belum berhasil dikompres, pilih ulang file tersebut`);
          hasError = true;
        }
      }
    });
    
    const formulirInput = document.getElementById('fileInputWajib1');
    const kkKtpInput = document.getElementById('fileInputWajib2');
    const fotoInput = document.getElementById('fileInputWajib3');

    if (!formulirInput.files.length) {
      errorMessages.push('Formulir F-1.03 Pindah Penduduk wajib diupload');
      hasError = true;
    }
    
    if (!kkKtpInput.files.length) {
      errorMessages.push('KK/KTP/Surat Keterangan Hilang KK/KTP wajib diupload');
      hasError = true;
    }
    
    if (!fotoInput.files.length) {
      errorMessages.push('Foto Selfie Pemohon Memegang Formulir wajib diupload');
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
    
    // Validasi required fields
    const requiredFields = this.querySelectorAll('[required]');
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
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan pindah masuk ke Kabupaten Gorontalo?');
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
