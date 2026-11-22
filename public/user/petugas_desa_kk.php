<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/petugas_desa_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$csrf_token_value = csrf_token();

// Ambil data user untuk auto-fill pemohon DAN data kecamatan/desa yang ditugaskan
$q = $koneksi->query("SELECT 
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
WHERE u.id = '$user_id'");
if (!$q) {
    die("Query error: " . $koneksi->error);
}

$data_user = $q->fetch_assoc();

// Cek jika data user tidak ditemukan
if (!$data_user) {
    header('Location: ../login.php?error=user_not_found');
    exit;
}

// Set nilai kecamatan dan desa dari data user
$user_kecamatan_id = $data_user['id_kecamatan'] ?? 0;
$user_desa_id = $data_user['id_desa'] ?? 0;
$nama_kecamatan_user = $data_user['nama_kecamatan'] ?? '';
$nama_desa_user = $data_user['nama_desa'] ?? '';

$errors = [];
$success_message = '';

// Untuk mempertahankan pilihan setelah submit gagal - KUNCI sesuai user
$selected_kecamatan = $user_kecamatan_id; // SELALU sesuai user
$selected_desa = $user_desa_id; // SELALU sesuai user
$pre_nama_kecamatan = $nama_kecamatan_user;
$pre_nama_desa = $nama_desa_user;

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // IDs kecamatan/desa - PASTIKAN sesuai dengan user
    $id_kecamatan = $user_kecamatan_id; // SELALU ambil dari data user
    $id_desa = $user_desa_id; // SELALU ambil dari data user
    
    // Validasi: cek jika ada upaya mengubah kecamatan/desa
    if ((isset($_POST['id_kecamatan']) && (int)$_POST['id_kecamatan'] != $user_kecamatan_id) ||
        (isset($_POST['id_desa']) && (int)$_POST['id_desa'] != $user_desa_id)) {
        $errors[] = 'Anda tidak memiliki akses untuk mengubah kecamatan/desa.';
    }

    // data keluarga
    $dusun = trim($_POST['dusun'] ?? '');
    $nama_kepalaKeluarga = trim($_POST['nama_kepalaKeluarga'] ?? '');
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

    // basic validation
    if (!$dusun) $errors[] = 'Alamat/Dusun wajib diisi.';
    if (!$nama_kepalaKeluarga) $errors[] = 'Nama Kepala Keluarga wajib diisi.';
    if (empty($anggotaKeluarga)) $errors[] = 'Minimal harus ada satu anggota keluarga.';

    // Ambil nama kecamatan/desa untuk pembuatan folder (dari data user)
    $nama_kecamatan_db = $nama_kecamatan_user;
    $nama_desa_db = $nama_desa_user;

    // Siapkan data untuk folder name sesuai format baru:
    // Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama kepala keluarga]_[tanggal buat]
    $nama_pemohon = $data_user['nama'] ?? '';
    $nik_pemohon = $data_user['nik'] ?? '';
    $timestamp = date('Ymd_His'); // Format: YYYYMMDD_HHMMSS
    
    // Fungsi sanitasi nama untuk folder
    function cleanFolderName($string) {
        $string = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $string); // Hanya izinkan huruf, angka, spasi, dash, underscore
        $string = preg_replace('/\s+/', '_', trim($string)); // Ganti spasi dengan underscore
        return $string;
    }
    
    // Bersihkan setiap komponen nama
    $kecamatan_clean = cleanFolderName($nama_kecamatan_db);
    $desa_clean = cleanFolderName($nama_desa_db);
    $pemohon_clean = cleanFolderName($nama_pemohon);
    $kepala_keluarga_clean = cleanFolderName($nama_kepalaKeluarga);
    
    // Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama kepala keluarga]_[tanggal buat]
    $folder_name = $kecamatan_clean . '_' . 
                   $desa_clean . '_' . 
                   $nik_pemohon . '_' . 
                   $pemohon_clean . '_' . 
                   $kepala_keluarga_clean . '_' . 
                   $timestamp;
    
    // Batasi panjang folder name (max 255 karakter)
    if (strlen($folder_name) > 200) {
        // Potong nama kepala keluarga jika terlalu panjang
        $prefix_length = strlen('PETUGAS_DESA_') + strlen($kecamatan_clean) + strlen($desa_clean) + 
                        strlen($nik_pemohon) + strlen($pemohon_clean) + strlen($timestamp) + 6; // 6 untuk underscore
        
        if ($prefix_length < 200) {
            $max_kepala_length = 200 - $prefix_length;
            if ($max_kepala_length > 10) {
                $kepala_keluarga_clean = substr($kepala_keluarga_clean, 0, $max_kepala_length);
                $folder_name = 'PETUGAS_DESA_' . 
                              $kecamatan_clean . '_' . 
                              $desa_clean . '_' . 
                              $nik_pemohon . '_' . 
                              $pemohon_clean . '_' . 
                              $kepala_keluarga_clean . '_' . 
                              $timestamp;
            } else {
                // Jika masih terlalu panjang, gunakan hash yang lebih pendek
                $folder_hash = substr(md5($folder_name), 0, 8);
                $folder_name = 'PETUGAS_DESA_' . 
                              $kecamatan_clean . '_' . 
                              $desa_clean . '_' . 
                              $nik_pemohon . '_' . 
                              $pemohon_clean . '_' . 
                              $folder_hash . '_' . 
                              $timestamp;
            }
        } else {
            // Jika prefix saja sudah terlalu panjang, gunakan versi sederhana
            $folder_name = 'PETUGAS_DESA_' . $nik_pemohon . '_' . $timestamp;
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
    
    // Dokumen WAJIB sesuai permintaan:
    $requiredDocs = ['formulir_f101', 'kk_lama']; 
    // Dokumen WAJIB: 
    // 1. Formulir F-1.01 biodata keluarga
    // 2. KK lama / surat keterangan hilang kk
    
    // Dokumen PENDUKUNG OPSIONAL:
    $optionalDocs = ['buku_nikah', 'ijazah', 'surat_kerja', 'lainnya'];
    
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
                $timestamp_file = time();
                $randomString = bin2hex(random_bytes(6));
                $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                $newName = $clean_doc_type . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                $destination = $user_folder_path . $newName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    // **PERUBAHAN: Simpan path dengan prefix "user/uploads/kk/" untuk konsistensi**
                    $uploadedFiles[] = [
                        'path' => 'user/uploads/kk/' . $folder_name . '/' . $newName,
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
                        case 'formulir_f101': $docName = 'Formulir F-1.01 Biodata Keluarga'; break;
                        case 'kk_lama': $docName = 'KK Lama / Surat Keterangan Hilang KK'; break;
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
                case 'formulir_f101':
                    $missingNames[] = 'Formulir F-1.01 Biodata Keluarga';
                    break;
                case 'kk_lama':
                    $missingNames[] = 'KK Lama / Surat Keterangan Hilang KK';
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
        
        // Query untuk insert data dengan tambahan nama_folder
        $stmt = $koneksi->prepare("INSERT INTO tb_kartukeluarga
            (id_user, id_kecamatan, id_desa, nama_kecamatan, nama_desa, 
             nama_pemohon, nik_pemohon, no_hp, email, dusun, 
             nama_kepalaKeluarga, anggotaKeluarga, keterangan, dokumen, status, nama_folder, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if (!$stmt) {
            $errors[] = 'Prepare statement error: ' . $koneksi->error;
        } else {
            $stmt->bind_param("iiisssssssssssss",
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
                $status,            // s (string - dalam variabel)
                $folder_name        // s (string - nama folder baru)
            );
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Permohonan Kartu Keluarga berhasil diajukan.';
                header('Location: petugas_desa_status_permohonan.php');
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

    // Set selected values for form repopulation (selalu sesuai user)
    $selected_kecamatan = $user_kecamatan_id;
    $selected_desa = $user_desa_id;
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
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
  <title>Ajukan Kartu Keluarga (Petugas Desa)</title>
  <link rel="stylesheet" href="css/petugas_desa_kk.css">
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
          'Ajukan Kartu Keluarga',
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
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Form Pengajuan Kartu Keluarga</h2>
              <p class="helper">Anda sebagai petugas desa hanya dapat mengajukan permohonan untuk wilayah tugas Anda.</p>
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

          <div class="info-box">
            <p><span class="info-icon">🔒</span> Anda sebagai <strong>Petugas Desa</strong> hanya dapat mengajukan permohonan untuk wilayah:</p>
            <p><strong>Kecamatan:</strong> <?= esc($nama_kecamatan_user) ?> | <strong>Desa:</strong> <?= esc($nama_desa_user) ?></p>
          </div>

          <form method="POST" enctype="multipart/form-data" class="space-y-4" id="kkForm">
            <?= csrf_input(); ?>

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
            </div>

            <div>
              <label class="field-label">Keterangan / Pesan untuk Operator<span style="color:red">*</span></label>
              <textarea name="keterangan" class="input" rows="3" required placeholder="Contoh: Permohonan perubahan alamat karena pindah rumah, penambahan anggota keluarga baru, dll." maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
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
                  <!-- Formulir F-1.01 (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib1">
                    <input type="hidden" name="file_index[]" value="0">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="formulir_f101" selected>Formulir F-1.01 Biodata Keluarga</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Pilih File</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Formulir F-1.01 Biodata Keluarga. Unduh formulir
                      <a href="https://siprima.gorontalokab.go.id/uploads/formulir/1766758287_694e978f1cc6b.pdf" target="_blank" rel="noopener noreferrer">klik disini</a>
                    </p>
                  </div>
                  
                  <!-- KK Lama / Surat Keterangan Hilang (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib2">
                    <input type="hidden" name="file_index[]" value="1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="kk_lama" selected>KK Lama / Surat Keterangan Hilang KK</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Pilih File</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Pilih salah satu: KK Lama <strong>ATAU</strong> Surat Keterangan Hilang KK dari kepolisian
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
                    <input type="hidden" name="file_index[]" value="2">
                    <select name="doc_type[]" class="file-type-select">
                      <option value="">Pilih Jenis Dokumen (Opsional)</option>
                      <option value="buku_nikah">Buku Nikah</option>
                      <option value="ijazah">Ijazah</option>
                      <option value="surat_kerja">Surat Keterangan Kerja</option>
                      <option value="lainnya">Dokumen Lainnya</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputOpsional1">Pilih File</label>
                      <span id="fileNameOpsional1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputOpsional1" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeOpsional1">Maksimal 5 MB per file (Opsional, dikompres otomatis)</div>
                  </div>
                </div>
                
                <button type="button" class="btn-secondary mt-2" onclick="addOptionalFileInput()">
                  <i class="fas fa-plus"></i> Tambah Dokumen Pendukung Lainnya
                </button>
              </div>
              
              <p class="helper"><strong>Format file: JPG atau PNG. Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong></p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="petugas_desa_kk_pilih_jenis.php" class="btn-secondary">Kembali</a>
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

  <!-- JavaScript tetap sama seperti sebelumnya -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
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

  // KECAMATAN DAN DESA TIDAK ADA AJAX KARENA SUDAH DIKUNCI

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

  // Multiple file upload functionality
  let fileCounter = {
    wajib: 2,
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
    const fileIndex = fileCounter.wajib + fileCounter.opsional - 1;
    newFileGroup.innerHTML = `
      <input type="hidden" name="file_index[]" value="${fileIndex}">
      <select name="doc_type[]" class="file-type-select">
        <option value="">Pilih Jenis Dokumen (Opsional)</option>
        <option value="buku_nikah">Buku Nikah</option>
        <option value="ijazah">Ijazah</option>
        <option value="surat_kerja">Surat Keterangan Kerja</option>
        <option value="lainnya">Dokumen Lainnya</option>
      </select>
      <div class="file-wrapper">
        <label class="file-label" for="fileInputOpsional${fileCounter.opsional}">Pilih File</label>
        <span id="fileNameOpsional${fileCounter.opsional}" class="file-name">Belum ada file dipilih</span>
        <input type="file" id="fileInputOpsional${fileCounter.opsional}" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
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
  document.getElementById('kkForm').addEventListener('submit', function(e) {
    // Defensive fallback: pastikan csrf_token selalu ikut saat submit.
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
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

    const allFileInputs = document.querySelectorAll('input[type="file"]');
    const anggotaRows = document.querySelectorAll('#anggotaBody tr');
    let hasError = false;
    let errorMessages = [];
    
    // Validasi ukuran file
    allFileInputs.forEach((input) => {
      if (input.files.length > 0) {
        if (input.dataset.fileStable === '0') {
          // Fallback mode: jangan blok submit, karena beberapa browser mobile
          // gagal clone file meski file lokal tetap valid untuk upload normal.
          console.warn(`File "${input.files[0].name}" tidak bisa distabilkan, lanjut upload mode normal.`);
        }
        const file = input.files[0];
        if (file.size > MAX_FILE_SIZE) {
          errorMessages.push(`File "${file.name}" belum berhasil dikompres, pilih ulang file tersebut`);
          hasError = true;
        }
      }
    });
    
    // Cek dokumen wajib
    const formulirInput = document.getElementById('fileInputWajib1');
    const kkLamaInput = document.getElementById('fileInputWajib2');
    
    if (!formulirInput.files.length) {
      errorMessages.push('Formulir F-1.01 Biodata Keluarga wajib diupload');
      hasError = true;
    }
    
    if (!kkLamaInput.files.length) {
      errorMessages.push('KK Lama / Surat Keterangan Hilang KK wajib diupload');
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
    
    // Validasi required fields
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
      // Konfirmasi sebelum submit
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan Kartu Keluarga?\n\nPastikan Anda telah mengupload:\n1. Formulir F-1.01 Biodata Keluarga\n2. KK Lama / Surat Keterangan Hilang KK');
      if (!confirmSubmit) {
        e.preventDefault();
      }
    }
  });
  </script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>
