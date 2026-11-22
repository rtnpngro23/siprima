<?php
require_once '../error_handler.php';
// File: kua_kartukeluarga.php
include '../../app/middleware/user_kua_only.php'; // Validasi user KUA
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/notifikasi.php';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$id_kua = (int)$_SESSION['id_kua'];

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

// Ambil data KUA untuk mendapatkan kecamatan dan desa
$q_kua = $koneksi->query("SELECT * FROM tb_kua WHERE id_kua = '$id_kua'");
$kua_data = $q_kua->fetch_assoc();

if (!$kua_data) {
    die("Data KUA tidak ditemukan!");
}

// Ambil nama kecamatan dari tabel tb_kecamatan berdasarkan id_kecamatan
$id_kecamatan_kua = $kua_data['id_kecamatan'];
$q_kecamatan = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
$q_kecamatan->bind_param("i", $id_kecamatan_kua);
$q_kecamatan->execute();
$result_kecamatan = $q_kecamatan->get_result();
$kecamatan_data = $result_kecamatan->fetch_assoc();
$nama_kecamatan = $kecamatan_data['nama_kecamatan'] ?? '';

$errors = [];
$success_message = '';
$csrf_token_value = function_exists('csrf_token') ? csrf_token() : '';

// Untuk mempertahankan pilihan setelah submit gagal
$selected_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : '';
$pre_nama_desa = '';
$selected_alamat_catin = isset($_POST['alamat_catin']) ? $_POST['alamat_catin'] : '';

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Data KUA (fixed dari session)
    $id_kecamatan = $id_kecamatan_kua; // Dari data KUA
    
    // Ambil nama kecamatan lagi untuk memastikan
    $q_kec_post = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
    $q_kec_post->bind_param("i", $id_kecamatan);
    $q_kec_post->execute();
    $result_kec_post = $q_kec_post->get_result();
    $kecamatan_post = $result_kec_post->fetch_assoc();
    $nama_kecamatan = $kecamatan_post['nama_kecamatan'] ?? '';
    
    // Desa (pilih dari input)
    $id_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : 0;
    
    // Ambil nama desa
    $nama_desa = '';
    if (!empty($id_desa)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $qd->bind_param("i", $id_desa);
        $qd->execute();
        $rd = $qd->get_result()->fetch_assoc();
        if ($rd) $nama_desa = $rd['nama_desa'];
    }

    // data keluarga
    $alamat_catin = $_POST['alamat_catin'] ?? '';
    $dusun = trim($_POST['dusun'] ?? '');
    $nama_kepalaKeluarga = trim($_POST['nama_kepalaKeluarga'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $no_kk_lama_suami = trim($_POST['no_kk_lama_suami'] ?? '');
    $no_kk_lama_istri = trim($_POST['no_kk_lama_istri'] ?? '');
    
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
    if (!$alamat_catin) $errors[] = 'Pilih status alamat calon pengantin (sama/beda) wajib diisi.';
    if (!$dusun) $errors[] = 'Alamat/Dusun wajib diisi.';
    if (!$nama_kepalaKeluarga) $errors[] = 'Nama Kepala Keluarga wajib diisi.';
    if (empty($anggotaKeluarga)) $errors[] = 'Minimal harus ada satu anggota keluarga.';
    if (!$id_desa) $errors[] = 'Desa wajib dipilih.';

    // Fungsi sanitasi nama untuk folder
    function cleanFolderName($string) {
        $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', $string); // Hapus karakter khusus
        $string = preg_replace('/\s+/', '_', trim($string)); // Ganti spasi dengan underscore
        return $string;
    }
    
    // Siapkan data untuk folder name sesuai format baru
    // Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama kepala keluarga]_[tanggal buat]
    $nama_pemohon = $data_user['nama'] ?? '';
    $nik_pemohon = $data_user['nik'] ?? '';
    $timestamp = date('Ymd_His'); // Format: YYYYMMDD_HHMMSS
    
    // Bersihkan setiap komponen nama
    $kecamatan_clean = cleanFolderName($nama_kecamatan);
    $desa_clean = cleanFolderName($nama_desa);
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

    // File gambar sumber maksimal 5 MB; stable-upload menargetkan hasil di bawah 500 KB.
    $uploadedFiles = [];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    $maxFileSize = 5 * 1024 * 1024;
    
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
    
    // Path folder utama uploads KUA
    $base_upload_dir = __DIR__ . '/uploads/kua_kk/';
    
    // Buat folder utama jika belum ada
    if (!is_dir($base_upload_dir)) {
        mkdir($base_upload_dir, 0755, true);
    }
    
    // Path folder khusus untuk permohonan ini
    $user_folder_path = $base_upload_dir . $folder_name . '/';
    
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
        $fileCount = count($_FILES['dokumen']['name']);
        // Buat folder khusus untuk permohonan ini
        if (!is_dir($user_folder_path)) {
            if (!mkdir($user_folder_path, 0755, true)) {
                $errors[] = 'Gagal membuat folder penyimpanan dokumen';
            }
        }
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileType = mime_content_type($fileTmp);
                $fileSize = $_FILES['dokumen']['size'][$i];
                $docType = $_POST['doc_type'][$i] ?? 'lainnya';

                // Validasi tipe file
                if (!in_array($ext, ['jpg', 'jpeg', 'jfif', 'jpe', 'png']) || 
                    !in_array($fileType, $allowedTypes)) {
                    $errors[] = "File {$fileName}: Gunakan JPG, JPEG, JFIF, JPE, atau PNG";
                    continue;
                }

                // Validasi ukuran file sumber.
                if ($fileSize > $maxFileSize) {
                    $errors[] = "File {$fileName}: Ukuran maksimal 5 MB. File Anda: " . formatFileSize($fileSize);
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
                        'path' => 'uploads/kua_kk/' . $folder_name . '/' . $newName,
                        'type' => $docType,
                        'original_name' => $fileName,
                        'uploaded_name' => $newName,
                        'size' => $fileSize,
                        'uploaded_at' => date('Y-m-d H:i:s')
                    ];
                    $uploadedDocTypes[] = $docType;
                    // Tambahkan .htaccess untuk keamanan
                    $htaccessFile = $user_folder_path . '/.htaccess';
                    if (!file_exists($htaccessFile)) {
                        file_put_contents($htaccessFile, 
                            "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png)$\">\nAllow from all\n</Files>");
                    }
                } else {
                    $errors[] = "File {$fileName}: Gagal menyimpan file";
                }
            } elseif ($_FILES['dokumen']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                // Cek apakah file ini wajib
                $docType = $_POST['doc_type'][$i] ?? '';
                if (in_array($docType, $requiredDocs)) {
                    if (isset($docNames[$docType])) {
                        $errors[] = "{$docNames[$docType]} wajib diupload";
                    }
                }
            }
        }
    }
    
    // Cek dokumen wajib berdasarkan pilihan alamat
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
        
        // Query INSERT ke tabel tb_kartukeluarga_kua
        $stmt = $koneksi->prepare("INSERT INTO tb_kartukeluarga_kua
            (id_user, id_kua, id_kecamatan, id_desa, alamat_catin, 
             nama_pemohon, nik_pemohon, no_hp, email, 
             nama_kecamatan, nama_desa, dusun, 
             no_kk_lama_suami, no_kk_lama_istri, nama_kepalakeluarga, 
             anggotakeluarga, dokumen, keterangan, status, created_at, nama_folder)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
        
        if (!$stmt) {
            $errors[] = 'Prepare statement error: ' . $koneksi->error;
        } else {
            $stmt->bind_param("iiiissssssssssssssss",
                $user_id,               // i (integer)
                $id_kua,                // i (integer)
                $id_kecamatan,          // i (integer)
                $id_desa,               // i (integer)
                $alamat_catin,          // s (string)
                $nama_pemohon,          // s (string)
                $nik_pemohon,           // s (string)
                $no_hp,                 // s (string)
                $email,                 // s (string)
                $nama_kecamatan,        // s (string)
                $nama_desa,             // s (string)
                $dusun,                 // s (string)
                $no_kk_lama_suami,      // s (string)
                $no_kk_lama_istri,      // s (string)
                $nama_kepalaKeluarga,   // s (string)
                $anggotaKeluargaJson,   // s (string - JSON)
                $dokumenJson,           // s (string - JSON)
                $keterangan,            // s (string)
                $status,                // s (string)
                $folder_name            // s (string - nama folder)
            );
            
            if ($stmt->execute()) {
                try {
                    buatNotifikasiOperatorUnit($koneksi, 'tb_kartukeluarga_kua', (int)$stmt->insert_id, 'Pengajuan Baru', 'Permohonan baru menunggu pemeriksaan.');
                } catch (Throwable $e) {
                    error_log('Notifikasi operator KUA gagal: ' . $e->getMessage());
                }
                $_SESSION['success_message'] = 'Permohonan Kartu Keluarga berhasil diajukan di KUA ' . $kua_data['nama_kua'] . '.';
                header('Location: kua_ajukan_permohonan.php');
                exit;
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
                
                // Jika gagal insert, hapus folder yang sudah dibuat
                if (is_dir($user_folder_path)) {
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
            $stmt->close();
        }
    }
    
    // Set selected values for form repopulation
    $selected_desa = $id_desa;
    $selected_alamat_catin = $alamat_catin;
}

// Ambil nama desa untuk form repopulation
if (!empty($selected_desa)) {
    $qd2 = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
    $qd2->bind_param("i", $selected_desa);
    $qd2->execute();
    $rd2 = $qd2->get_result()->fetch_assoc();
    if ($rd2) $pre_nama_desa = $rd2['nama_desa'];
}

// Ambil daftar desa berdasarkan kecamatan KUA
$q_desa = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
$q_desa->bind_param("i", $id_kecamatan_kua);
$q_desa->execute();
$result_desa = $q_desa->get_result();

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
  <meta name="csrf-token" content="<?= esc($csrf_token_value) ?>">
  <title>Ajukan Kartu Keluarga - <?= esc($kua_data['nama_kua'] ?? '') ?></title>
  <link rel="stylesheet" href="css/kua_kartukeluarga.css">
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
        <li class="active"><a href="kua_ajukan_permohonan.php">Ajukan Permohonan</a></li>
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
        <div class="title">Ajukan Kartu Keluarga</div>
        <div class="subtitle"><?= esc($kua_data['nama_kua'] ?? '') ?> - KUA PASUTRI</div>
      </div>
      <div class="user-info">
        <span><?= esc($_SESSION['nama']) ?></span>
        <span class="role-badge">USER KUA</span>
        <?php include __DIR__ . '/../user/notification_widget.php'; ?>
        <button class="logout" id="logoutBtn2">Logout</button>
      </div>
    </header>

    <section class="content">
      <!-- Info KUA -->
      <div class="kua-info">
        <h2>
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="16" y1="2" x2="16" y2="6"></line>
            <line x1="8" y1="2" x2="8" y2="6"></line>
            <line x1="3" y1="10" x2="21" y2="10"></line>
          </svg>
          <?= esc($kua_data['nama_kua'] ?? '') ?>
        </h2>
        <p>Kecamatan <?= esc($nama_kecamatan ?? '') ?> - Pengajuan PASUTRI (Pelayanan Adminduk Suami Istri Terintegrasi)</p>
      </div>

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
            <h2 class="form-title">Form Pengajuan PASUTRI</h2>
            <p class="helper">Lengkapi data keluarga dan unggah dokumen pendukung untuk pengajuan di KUA.</p>
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
          </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="space-y-4" id="kkForm">
          <input type="hidden" name="csrf_token" value="<?= esc($csrf_token_value) ?>">

          <!-- Data Pemohon (otomatis terisi) -->
          <div class="form-grid-2">
            <div>
              <label class="field-label">Nama Pemohon</label>
              <input type="text" value="<?= esc($data_user['nama'] ?? '') ?>" readonly class="input bg-gray-100">
            </div>

            <div>
              <label class="field-label">NIK Pemohon</label>
              <input type="text" value="<?= esc($data_user['nik'] ?? '') ?>" readonly class="input bg-gray-100">
            </div>

            <div>
              <label class="field-label">No HP</label>
              <input type="text" value="<?= esc($data_user['no_hp'] ?? '') ?>" readonly class="input bg-gray-100">
            </div>

            <div>
              <label class="field-label">Email</label>
              <input type="email" value="<?= esc($data_user['email'] ?? '') ?>" readonly class="input bg-gray-100">
            </div>
          </div>

          <!-- Kecamatan & Desa (Kecamatan fixed, desa pilihan) -->
          <div class="form-grid-2">
            <div>
              <label class="field-label">Kecamatan <span style="color:red">*</span></label>
              <input type="text" value="<?= esc($nama_kecamatan ?? '') ?>" readonly class="input bg-gray-100">
              <input type="hidden" name="id_kecamatan" value="<?= esc($id_kecamatan_kua ?? '') ?>">
            </div>

            <div>
              <label class="field-label">Desa <span style="color:red">*</span></label>
              <select name="id_desa" id="desaSelect" required class="select">
                <option value="">-- Pilih Desa --</option>
                <?php
                if ($result_desa->num_rows > 0) {
                    while ($drow = $result_desa->fetch_assoc()) {
                        $selD = ($selected_desa == $drow['id']) ? ' selected' : '';
                        echo "<option value='" . esc($drow['id']) . "'{$selD}>" . esc($drow['nama_desa']) . "</option>";
                    }
                } else {
                    echo "<option value=''>Tidak ada desa tersedia</option>";
                }
                ?>
              </select>
            </div>
          </div>

          <!-- Pilihan Alamat Calon Pengantin -->
          <div>
            <label class="field-label">Status Alamat Calon Pengantin <span style="color:red">*</span></label>
            <div class="radio-group">
              <div class="radio-option">
                <input type="radio" id="alamat_sama" name="alamat_catin" value="alamat sama" 
                  <?= ($selected_alamat_catin == 'alamat sama') ? 'checked' : '' ?>>
                <label for="alamat_sama">Alamat Sama</label>
              </div>
              <div class="radio-option">
                <input type="radio" id="alamat_beda" name="alamat_catin" value="alamat beda"
                  <?= ($selected_alamat_catin == 'alamat beda') ? 'checked' : '' ?>>
                <label for="alamat_beda">Alamat Beda</label>
              </div>
            </div>
            
            <!-- Info Box untuk Dokumen Wajib -->
            <div id="alamatSamaInfo" class="info-box" style="display: <?= ($selected_alamat_catin == 'alamat sama' || empty($selected_alamat_catin)) ? 'block' : 'none' ?>;">
              <h4>📋 Dokumen Wajib untuk Alamat Sama:</h4>
              <ul>
                <li><strong>KK Lama Suami</strong> (Wajib)</li>
                <li><strong>KK Lama Istri</strong> (Wajib)</li>
                <li><strong>KTP Suami</strong> (Wajib)</li>
                <li><strong>KTP Istri</strong> (Wajib)</li>
                <li><strong>Formulir F-1.01 Biodata Keluarga Halaman 1</strong> (Wajib)</li>
                <li><strong>Formulir F-1.01 Biodata Keluarga Halaman 2</strong> (Wajib)</li>
                <li><strong>Buku Nikah</strong> (Wajib)</li>
              </ul>
            </div>
            
            <div id="alamatBedaInfo" class="info-box" style="display: <?= ($selected_alamat_catin == 'alamat beda') ? 'block' : 'none' ?>;">
              <h4>📋 Dokumen Wajib untuk Alamat Beda:</h4>
              <ul>
                <li><strong>KK Lama Suami</strong> (Wajib)</li>
                <li><strong>KK Lama Istri</strong> (Wajib)</li>
                <li><strong>KTP Suami</strong> (Wajib)</li>
                <li><strong>KTP Istri</strong> (Wajib)</li>
                <li><strong>Formulir F-1.01 Biodata Keluarga Halaman 1</strong> (Wajib)</li>
                <li><strong>Formulir F-1.01 Biodata Keluarga Halaman 2</strong> (Wajib)</li>
                <li><strong>Buku Nikah</strong> (Wajib)</li>
                <li><strong>Formulir F-1.03 Pindah</strong> (Wajib)</li>
              </ul>
            </div>
          </div>

          <!-- Data Keluarga -->
          <div class="form-grid-2">
            <div>
              <label class="field-label">Alamat/Dusun/Lingkungan <span style="color:red">*</span></label>
              <input type="text" name="dusun" value="<?= esc($_POST['dusun'] ?? '') ?>" required class="input" placeholder="Contoh: Dusun Suka Makmur">
            </div>

            <div>
              <label class="field-label">Nama Kepala Keluarga <span style="color:red">*</span></label>
              <input type="text" name="nama_kepalaKeluarga" value="<?= esc($_POST['nama_kepalaKeluarga'] ?? '') ?>" required class="input" placeholder="Nama lengkap kepala keluarga">
            </div>
          </div>

          <!-- No KK Lama Pasutri -->
          <div class="form-grid-2">
            <div>
              <label class="field-label">No KK Lama (Suami) <span style="color:red">*</span></label>
              <input type="text" name="no_kk_lama_suami" value="<?= esc($_POST['no_kk_lama_suami'] ?? '') ?>" required class="input" placeholder="Nomor KK Lama suami" maxlength="16">
            </div>

            <div>
              <label class="field-label">No KK Lama (Istri) <span style="color:red">*</span></label>
              <input type="text" name="no_kk_lama_istri" value="<?= esc($_POST['no_kk_lama_istri'] ?? '') ?>" required class="input" placeholder="Nomor KK Lama istri" maxlength="16">
            </div>
          </div>

          <!-- Anggota Keluarga (Input Dinamis) -->
          <div>
            <label class="field-label">Anggota Keluarga <span style="color:red">*</span></label>
            
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
                      <input type="text" name="anggota_hubungan[]" placeholder="Contoh: Suami, Istri, Anak">
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
              <p class="helper">Isi data anggota keluarga termasuk pasangan dan anak-anak.</p>
            </div>
          </div>

          <div>
            <label class="field-label">Keterangan / Pesan untuk Operator Dukcapil</label>
            <textarea name="keterangan" class="input" rows="3" placeholder="Contoh: Permohonan pembuatan KK baru karena pernikahan, penambahan anggota keluarga baru, dll." maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
          </div>

          <!-- File Upload Multiple -->
          <div>
            <label class="field-label">Unggah Dokumen Pendukung <span style="color:red">*</span></label>
            
            <!-- Dokumen WAJIB -->
            <div class="f-doc-group">
              <h4 class="f-doc-group__judul">
                <i class="fas fa-exclamation-circle"></i> Dokumen Wajib (Berdasarkan Pilihan Alamat)
              </h4>
              <div id="wajibUploadContainer">
                <!-- Dokumen wajib akan ditambahkan berdasarkan pilihan alamat -->
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
                    <input type="file" id="fileInputOpsional1" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
                  </div>
                  <div class="file-size-warning" id="fileSizeOpsional1">Maksimal 5 MB per file (Opsional)</div>
                </div>
              </div>
              
              <button type="button" class="btn-secondary mt-2" onclick="addOptionalFileInput()">
                <i class="fas fa-plus"></i> Tambah Dokumen Opsional Lainnya
              </button>
            </div>
            
            <p class="helper">
              <strong>Format:</strong> JPG, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong><br>
            </p>
          </div>

          <div class="flex justify-between items-center mt-6 pt-4 border-t">
            <a href="kua_ajukan_permohonan.php" class="btn-secondary">Kembali</a>
            <button type="submit" class="btn-primary">Ajukan Permohonan Kartu Keluarga</button>
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
// JavaScript untuk KUA Kartu Keluarga
document.addEventListener('DOMContentLoaded', function() {
  const csrfToken = (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '').trim();

  // Sidebar Toggle
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
  
  // Tampilkan info dokumen berdasarkan pilihan alamat
  const alamatSamaRadio = document.getElementById('alamat_sama');
  const alamatBedaRadio = document.getElementById('alamat_beda');
  const alamatSamaInfo = document.getElementById('alamatSamaInfo');
  const alamatBedaInfo = document.getElementById('alamatBedaInfo');
  const wajibUploadContainer = document.getElementById('wajibUploadContainer');
  
  function updateWajibDocuments(isAlamatSama) {
    wajibUploadContainer.innerHTML = '';
    
    // Dokumen wajib untuk semua kondisi
    const wajibDocs = [
      { value: 'kk_lama_suami', label: 'KK Lama Suami (Wajib)' },
      { value: 'kk_lama_istri', label: 'KK Lama Istri (Wajib)' },
      { value: 'ktp_suami', label: 'KTP Suami (Wajib)' },
      { value: 'ktp_istri', label: 'KTP Istri (Wajib)' },
      { value: 'formulir_f101_halaman_1', label: 'Formulir F-1.01 Biodata Keluarga Halaman 1 (Wajib)' },
      { value: 'formulir_f101_halaman_2', label: 'Formulir F-1.01 Biodata Keluarga Halaman 2 (Wajib)' },
      { value: 'buku_nikah', label: 'Buku Nikah (Wajib)' }
    ];
    
    // Tambahkan F-1.03 hanya untuk alamat beda
    if (!isAlamatSama) {
      wajibDocs.push({ value: 'formulir_f103', label: 'Formulir F-1.03 Pindah (Wajib)' });
    }
    
    // Buat file input untuk setiap dokumen wajib
    wajibDocs.forEach((doc, index) => {
      const fileGroup = document.createElement('div');
      fileGroup.className = 'file-input-group';
      fileGroup.id = 'fileGroupWajib' + (index + 1);
      fileGroup.innerHTML = `
        <select name="doc_type[]" class="file-type-select" required>
          <option value="">Pilih Jenis Dokumen</option>
          <option value="${doc.value}" selected>${doc.label}</option>
        </select>
        <div class="file-wrapper">
          <label class="file-label" for="fileInputWajib${index + 1}">Pilih File</label>
          <span id="fileNameWajib${index + 1}" class="file-name">Belum ada file dipilih</span>
          <input type="file" id="fileInputWajib${index + 1}" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
        </div>
        <div class="file-size-warning" id="fileSizeWajib${index + 1}">Maksimal 5 MB per file</div>
        <p class="helper" style="margin-top: 5px; font-size: 13px;">
          <i class="fas fa-info-circle"></i> ${doc.label.replace(/\(Wajib\)/g, '').trim()}
        </p>
      `;
      wajibUploadContainer.appendChild(fileGroup);
      
      // Add event listener
      const fileInput = document.getElementById('fileInputWajib' + (index + 1));
      const fileNameSpan = document.getElementById('fileNameWajib' + (index + 1));
      const fileSizeSpan = document.getElementById('fileSizeWajib' + (index + 1));
      
      if (fileInput && fileNameSpan && fileSizeSpan) {
        fileInput.addEventListener('change', function(e) {
          if (this.files.length > 0) {
            const file = this.files[0];
            fileNameSpan.textContent = file.name;
            
            if (file.size > MAX_FILE_SIZE) {
              fileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (melebihi 5 MB)</span>`;
              this.value = '';
              fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
            } else {
              fileSizeSpan.innerHTML = `Ukuran: ${formatFileSize(file.size)}`;
            }
          } else {
            fileNameSpan.textContent = 'Belum ada file dipilih';
            fileSizeSpan.innerHTML = 'Maksimal 5 MB per file';
          }
        });
      }
    });
  }
  
  // Inisialisasi dokumen wajib
  const defaultAlamatSama = !alamatBedaRadio || (alamatBedaRadio && !alamatBedaRadio.checked);
  updateWajibDocuments(defaultAlamatSama);
  
  if (alamatSamaRadio && alamatBedaRadio) {
    alamatSamaRadio.addEventListener('change', function() {
      if (this.checked) {
        alamatSamaInfo.style.display = 'block';
        alamatBedaInfo.style.display = 'none';
        updateWajibDocuments(true);
      }
    });
    
    alamatBedaRadio.addEventListener('change', function() {
      if (this.checked) {
        alamatSamaInfo.style.display = 'none';
        alamatBedaInfo.style.display = 'block';
        updateWajibDocuments(false);
      }
    });
  }
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

// File Upload Functions
let fileCounterOpsional = 1;
const MAX_FILE_SIZE = 5 * 1024 * 1024;

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
  fileCounterOpsional++;
  const container = document.getElementById('opsionalUploadContainer');
  const newFileGroup = document.createElement('div');
  newFileGroup.className = 'file-input-group';
  newFileGroup.id = 'fileGroupOpsional' + fileCounterOpsional;
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
      <label class="file-label" for="fileInputOpsional${fileCounterOpsional}">Pilih File</label>
      <span id="fileNameOpsional${fileCounterOpsional}" class="file-name">Belum ada file dipilih</span>
      <input type="file" id="fileInputOpsional${fileCounterOpsional}" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" class="file-input">
    </div>
    <div class="file-size-warning" id="fileSizeOpsional${fileCounterOpsional}">Maksimal 5 MB per file (Opsional)</div>
    <button type="button" class="btn-remove" onclick="removeOptionalFileInput(${fileCounterOpsional})">Hapus</button>
  `;
  container.appendChild(newFileGroup);
  
  // Add event listener
  const newFileInput = document.getElementById('fileInputOpsional' + fileCounterOpsional);
  const newFileNameSpan = document.getElementById('fileNameOpsional' + fileCounterOpsional);
  const newFileSizeSpan = document.getElementById('fileSizeOpsional' + fileCounterOpsional);
  
  if (newFileInput && newFileNameSpan && newFileSizeSpan) {
    newFileInput.addEventListener('change', function(e) {
      if (this.files.length > 0) {
        const file = this.files[0];
        newFileNameSpan.textContent = file.name;
        
        if (file.size > MAX_FILE_SIZE) {
          newFileSizeSpan.innerHTML = `<span style="color: red;">❌ Ukuran file: ${formatFileSize(file.size)} (melebihi 5 MB)</span>`;
          this.value = '';
          newFileNameSpan.textContent = 'File terlalu besar, pilih file lain';
        } else {
          newFileSizeSpan.innerHTML = `Ukuran: ${formatFileSize(file.size)}`;
        }
      } else {
        newFileNameSpan.textContent = 'Belum ada file dipilih';
        newFileSizeSpan.innerHTML = 'Maksimal 5 MB per file (Opsional)';
      }
    });
  }
}

function removeOptionalFileInput(fileId) {
  const fileGroup = document.getElementById('fileGroupOpsional' + fileId);
  if (fileGroup && fileId > 1) {
    fileGroup.remove();
  } else {
    alert('Dokumen opsional pertama tidak dapat dihapus.');
  }
}

// Form Validation
document.getElementById('kkForm').addEventListener('submit', function(e) {
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

  const allFileInputs = document.querySelectorAll('input[type="file"]');
  const anggotaRows = document.querySelectorAll('#anggotaBody tr');
  const alamatCatin = document.querySelector('input[name="alamat_catin"]:checked');
  let hasError = false;
  let errorMessages = [];
  
  // Validasi pilihan alamat
  if (!alamatCatin) {
    errorMessages.push('Pilih status alamat calon pengantin (sama/beda)');
    hasError = true;
  }
  
  // Validasi ukuran file
  allFileInputs.forEach((input) => {
    if (input.files.length > 0) {
      const file = input.files[0];
      if (file.size > MAX_FILE_SIZE) {
        errorMessages.push(`File "${file.name}" melebihi batas 5 MB`);
        hasError = true;
      }
    }
  });
  
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
    // Konfirmasi sebelum submit
    const alamatText = alamatCatin ? alamatCatin.value : '';
    const confirmSubmit = confirm(`Apakah Anda yakin data sudah benar dan siap mengajukan permohonan ke KUA?\n\nStatus Alamat: ${alamatText}`);
    if (!confirmSubmit) {
      e.preventDefault();
    }
  }
});
</script>
<!-- Font Awesome Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
</body>
</html>
