<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
require_once __DIR__ . '/../functions/formulir_f201_kematian.php';
require_once __DIR__ . '/../../app/config/document_features.php';
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

// Saat formulir dibuat sistem tidak ada lagi kertas yang dipegang; selfie beralih ke KTP/KK
// dan tetap wajib sebagai bukti bahwa pemohon adalah orang yang bersangkutan.
$labelSelfie = FORMULIR_F201_KEMATIAN_AUTO_ENABLED
    ? 'Foto Selfie Pemohon Memegang KTP/Kartu Keluarga'
    : 'Foto Selfie Pemohon Memegang Formulir';

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

    // data orang yang meninggal
    $dusun = trim($_POST['dusun'] ?? '');
    $nama_meninggal = trim($_POST['nama_meninggal'] ?? '');
    $nik_meninggal = trim($_POST['nik_meninggal'] ?? '');
    $tanggal_kematian = trim($_POST['tanggal_kematian'] ?? '');
    $tempat = trim($_POST['tempat'] ?? '');
    $nama_ayah = trim($_POST['nama_ayah'] ?? '');
    $nik_ayah = trim($_POST['nik_ayah'] ?? '');
    $nama_ibu = trim($_POST['nama_ibu'] ?? '');
    $nik_ibu = trim($_POST['nik_ibu'] ?? '');
    $nama_saksi1 = trim($_POST['nama_saksi1'] ?? '');
    $nik_saksi1 = trim($_POST['nik_saksi1'] ?? '');
    $nama_saksi2 = trim($_POST['nama_saksi2'] ?? '');
    $nik_saksi2 = trim($_POST['nik_saksi2'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');

    // basic validation
    if (!$nama_meninggal) $errors[] = 'Nama yang meninggal wajib diisi.';
    if (!$tanggal_kematian) $errors[] = 'Tanggal kematian wajib diisi.';
    
    // Validasi format tanggal kematian (harus YYYY-MM-DD)
    if (!empty($tanggal_kematian)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_kematian)) {
            $errors[] = 'Format tanggal kematian tidak valid. Gunakan format YYYY-MM-DD.';
        } else {
            // Validasi apakah tanggal valid
            $date_parts = explode('-', $tanggal_kematian);
            if (!checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
                $errors[] = 'Tanggal kematian tidak valid.';
            }
        }
    }

    // Validasi NIK harus 16 digit jika diisi
    if ($nik_ayah && strlen($nik_ayah) !== 16) {
        $errors[] = 'NIK Ayah harus 16 digit.';
    }
    if ($nik_ibu && strlen($nik_ibu) !== 16) {
        $errors[] = 'NIK Ibu harus 16 digit.';
    }

    // Saksi 1 dan Saksi 2 wajib diisi (nama + NIK). UI sudah menandainya wajib sejak awal
    // (required + asterisk merah); validasi ini menutup celah karena backend sebelumnya hanya
    // mengecek panjang NIK bila diisi, tidak pernah mengecek keberadaannya.
    if (!$nama_saksi1) $errors[] = 'Nama Saksi 1 wajib diisi.';
    if (!$nik_saksi1) {
        $errors[] = 'NIK Saksi 1 wajib diisi.';
    } elseif (strlen($nik_saksi1) !== 16) {
        $errors[] = 'NIK Saksi 1 harus 16 digit.';
    }
    if (!$nama_saksi2) $errors[] = 'Nama Saksi 2 wajib diisi.';
    if (!$nik_saksi2) {
        $errors[] = 'NIK Saksi 2 wajib diisi.';
    } elseif (strlen($nik_saksi2) !== 16) {
        $errors[] = 'NIK Saksi 2 harus 16 digit.';
    }

    // Formulir F-2.01 dibuat sistem: pernyataan dan tanda tangan wajib.
    // Pemeriksaan murah saja di sini; render dikerjakan setelah upload selesai.
    $f201k_signature = null;
    $formulir_dibuat_at = null;
    if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED) {
        if (empty($_POST['pernyataan_benar'])) {
            $errors[] = 'Centang pernyataan kebenaran data sebelum mengirim permohonan.';
        }
        $pesan_ttd = siprima_formulir_validate_signature((string)($_POST['ttd_pemohon'] ?? ''), $f201k_signature);
        if ($pesan_ttd !== '') $errors[] = $pesan_ttd;
    }

    // Ambil nama kecamatan/desa untuk nama folder
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

    // file upload - MULTIPLE FILES, maksimal 1MB SETELAH kompresi otomatis di sisi klien
    $uploadedFiles = [];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    // Saat formulir dibuat sistem, pemohon tidak lagi mengunggah blanko F-2.01.
    $requiredDocs = FORMULIR_F201_KEMATIAN_AUTO_ENABLED
        ? ['surat_kematian', 'identitas_meninggal', 'foto_selfie']
        : ['formulir_f201', 'surat_kematian', 'identitas_meninggal', 'foto_selfie']; // Dokumen wajib
    $uploadedDocTypes = [];
    
    // Buat nama folder unik
    $folderName = str_replace(' ', '_', strtolower(trim($nama_kecamatan))) . '_' . 
                  str_replace(' ', '_', strtolower(trim($nama_desa))) . '_' . 
                  $data_user['nik'] . '_' . 
                  str_replace(' ', '_', strtolower(trim($data_user['nama']))) . '_' . 
                  str_replace(' ', '_', strtolower(trim($nama_meninggal))) . '_' . 
                  date('Ymd_His');
    
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $fileSize = $_FILES['dokumen']['size'][$i];
                $docType = $_POST['doc_type'][$i] ?? 'lainnya';

                // doc_type berasal dari klien. Saat formulir dibuat sistem, unggahan bertipe
                // 'formulir_f201' harus ditolak tegas agar tidak ada dua dokumen yang tampak resmi
                // dan operator tetap tahu mana yang tanda tangannya wajib diverifikasi.
                if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED && $docType === 'formulir_f201') {
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

                // Create upload directory dengan nama folder unik
                $uploadDir = __DIR__ . '/uploads/akta_kematian/' . $folderName;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);

                    // --- TAMBAHKAN LOGIKA .HTACCESS DI SINI ---
                    $htaccessPath = $uploadDir . '/.htaccess';
                    $htaccessContent = "Order Deny,Allow\n" . 
                                      "Deny from all\n" . 
                                      "<Files ~ \"\.(jpg|jpeg|png|gif)$\">\n" . 
                                      "    Allow from all\n" . 
                                      "</Files>\n" .
                                      "Options -Indexes";
                    
                    file_put_contents($htaccessPath, $htaccessContent);
                    // ------------------------------------------
                }

                // Generate unique filename
                $newName = $docType . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $destination = $uploadDir . '/' . $newName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    $uploadedFiles[] = [
                        'path' => 'uploads/akta_kematian/' . $folderName . '/' . $newName,
                        'type' => $docType,
                        'original_name' => $fileName
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
            if ($doc === 'formulir_f201') {
                $missingNames[] = 'Formulir F-2.01 Kematian';
            } elseif ($doc === 'surat_kematian') {
                $missingNames[] = 'Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan';
            } elseif ($doc === 'identitas_meninggal') {
                $missingNames[] = 'KK/KTP/Surat Kehilangan KK/KTP yang meninggal';
            } elseif ($doc === 'foto_selfie') {
                $missingNames[] = $labelSelfie;
            }
        }
        $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
    }

    // Formulir dibuat setelah seluruh unggahan final, tetapi sebelum INSERT: berkas ditulis ke
    // $uploadDir sehingga ikut terhapus oleh pembersihan atomik di bawah bila terjadi kegagalan apa pun.
    if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED && empty($errors)) {
        try {
            $formulir_dibuat_at = date('Y-m-d H:i:s');
            $formulirJpeg = siprima_f201_kematian_render(
                siprima_f201_kematian_data($_POST, $data_user, $nama_kecamatan, $nama_desa, $formulir_dibuat_at),
                (string)$f201k_signature
            );

            $formulirName = 'formulir_f201_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
            // $uploadDir hanya diset di dalam loop unggahan; bangun ulang dari $folderName sebagai
            // jaga-jaga bila entah bagaimana loop tidak pernah membuatnya.
            if (!isset($uploadDir)) $uploadDir = __DIR__ . '/uploads/akta_kematian/' . $folderName;
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            if (file_put_contents($uploadDir . '/' . $formulirName, $formulirJpeg) === false) {
                throw new RuntimeException('Formulir gagal ditulis ke folder permohonan.');
            }

            // Formulir selalu dokumen pertama, sama seperti sebelum fitur ini ada.
            array_unshift($uploadedFiles, [
                'path' => 'uploads/akta_kematian/' . $folderName . '/' . $formulirName,
                'type' => 'formulir_f201',
                'original_name' => 'Formulir F-2.01 Kematian (dibuat sistem)',
                'generated' => true,
            ]);
        } catch (Throwable $e) {
            error_log('Formulir F-2.01 Kematian render error: ' . $e->getMessage());
            $errors[] = 'Formulir belum dapat dibuat. Silakan ulangi tanda tangan Anda lalu coba lagi.';
        }
    }

    if (!empty($errors) && isset($uploadDir) && is_dir($uploadDir)) {
        siprima_remove_upload_tree($uploadDir, __DIR__ . '/uploads/akta_kematian');
        $uploadedFiles = [];
        $uploadedDocTypes = [];
    }

    if (empty($errors)) {
        // Siapkan nilai pemohon dari data user
        $nama_pemohon = $data_user['nama'] ?? '';
        $nik_pemohon = $data_user['nik'] ?? '';
        $no_hp = $data_user['no_hp'] ?? '';
        $email = $data_user['email'] ?? '';

        // Convert uploaded files to JSON string
        $dokumenJson = json_encode($uploadedFiles);
        
        // Periksa apakah kolom nama_folder ada di tabel
        $checkFolderCol = $koneksi->query("SHOW COLUMNS FROM tb_akta_kematian LIKE 'nama_folder'");
        if ($checkFolderCol->num_rows === 0) {
            // Tambahkan kolom nama_folder jika belum ada
            $koneksi->query("ALTER TABLE tb_akta_kematian ADD COLUMN nama_folder VARCHAR(255) NULL AFTER keterangan");
        }

        // Simpan data ke database
        $status = 'diajukan';
        
        $stmt = $koneksi->prepare("INSERT INTO tb_akta_kematian 
            (id_user, id_kecamatan, id_desa, nama_pemohon, nik_pemohon, no_hp, email, 
             nama_kecamatan, nama_desa, dusun, Nama_meninggal, Nik_meninggal, 
             tanggal_kematian, Tempat, Nama_Ayah, Nik_ayah, Nama_Ibu, Nik_ibu,
             nama_saksi1, nik_saksi1, nama_saksi2, nik_saksi2,
             dokumen, keterangan, nama_folder, status, formulir_dibuat_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        if (!$stmt) {
            $errors[] = 'Prepare statement error: ' . $koneksi->error;
        } else {
            // 27 parameter, urut sama dengan daftar kolom di atas.
            $stmt->bind_param("iiissssssssssssssssssssssss",
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
                $folderName,        // s
                $status,            // s
                $formulir_dibuat_at // s (string|null)
            );
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Permohonan Akta Kematian berhasil diajukan.';
                header('Location: ajukan_permohonan.php');
                exit;
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
                if (isset($uploadDir) && is_dir($uploadDir)) {
                    siprima_remove_upload_tree($uploadDir, __DIR__ . '/uploads/akta_kematian');
                }
            }
        }
    }
    
    if (!empty($errors) && isset($uploadDir) && is_dir($uploadDir)) {
        siprima_remove_upload_tree($uploadDir, __DIR__ . '/uploads/akta_kematian');
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

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ajukan Akta Kematian</title>
  <link rel="stylesheet" href="css/akta_kematian.css?v=20260824-1">
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
        render_user_header_brand('Ajukan Akta Kematian');
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
                <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Form Pengajuan Akta Kematian</h2>
              <p class="helper">Lengkapi data orang yang meninggal, lalu unggah dokumen pendukung.</p>
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

            <!-- Data Orang yang Meninggal -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Alamat/Dusun <span style="color: red">*</span> </label>
                <input type="text" name="dusun" value="<?= esc($_POST['dusun'] ?? '') ?>" required class="input" placeholder="Nama dusun/lingkungan">
              </div>

              <div>
                <label class="field-label">Tempat Kematian <span style="color: red">*</span></label>
                <input type="text" name="tempat" value="<?= esc($_POST['tempat'] ?? '') ?>" required class="input" placeholder="Kabupaten Gorontalo / Kota Gorontalo">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Yang Meninggal <span style="color:red">*</span></label>
                <input type="text" name="nama_meninggal" value="<?= esc($_POST['nama_meninggal'] ?? '') ?>" required class="input" placeholder="Nama lengkap">
              </div>
              <div>
                <label class="field-label">NIK Yang Meninggal <span style="color: red">*</span></label>
                <input type="text" name="nik_meninggal" value="<?= esc($_POST['nik_meninggal'] ?? '') ?>" required class="input" placeholder="16 digit NIK" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Tanggal Kematian <span style="color:red">*</span></label>
                <input type="date" name="tanggal_kematian" value="<?= esc($_POST['tanggal_kematian'] ?? '') ?>" required class="input">
              </div>
            </div>

            <!-- Data Orang Tua -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Ayah <span style="color: red">*</span></label>
                <input type="text" name="nama_ayah" value="<?= esc($_POST['nama_ayah'] ?? '') ?>" required class="input" placeholder="Nama ayah (jika diketahui)">
              </div>
              <div>
                <label class="field-label">NIK Ayah</label>
                <input type="text" name="nik_ayah" value="<?= esc($_POST['nik_ayah'] ?? '') ?>" class="input" placeholder="16 digit NIK ayah" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Ibu <span style="color: red">*</span></label>
                <input type="text" name="nama_ibu" value="<?= esc($_POST['nama_ibu'] ?? '') ?>" required class="input" placeholder="Nama ibu (jika diketahui)">
              </div>
              <div>
                <label class="field-label">NIK Ibu</label>
                <input type="text" name="nik_ibu" value="<?= esc($_POST['nik_ibu'] ?? '') ?>" class="input" placeholder="16 digit NIK ibu" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <!-- Data Saksi 1 & 2 -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Saksi 1 <span style="color: red">*</span></label>
                <input type="text" name="nama_saksi1" value="<?= esc($_POST['nama_saksi1'] ?? '') ?>" required class="input" placeholder="Nama saksi 1">
              </div>
              <div>
                <label class="field-label">NIK Saksi 1 <span style="color: red">*</span></label>
                <input type="text" name="nik_saksi1" value="<?= esc($_POST['nik_saksi1'] ?? '') ?>" required class="input" placeholder="16 digit NIK saksi 1" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Saksi 2 <span style="color: red">*</span></label>
                <input type="text" name="nama_saksi2" value="<?= esc($_POST['nama_saksi2'] ?? '') ?>" required class="input" placeholder="Nama saksi 2">
              </div>
              <div>
                <label class="field-label">NIK Saksi 2 <span style="color: red">*</span></label>
                <input type="text" name="nik_saksi2" value="<?= esc($_POST['nik_saksi2'] ?? '') ?>" required class="input" placeholder="16 digit NIK saksi 2" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <!-- Keterangan / Pesan -->
            <div>
              <label class="field-label">Keterangan / Pesan untuk Operator <span style="color: red">*</span></label>
              <textarea name="keterangan" class="input" rows="3" placeholder="Contoh: Permohonan akta kematian dan Kartu Keluarga baru, serta perubahan status ke cerai mati, dll." maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
            </div>

            <?php if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
            <!-- Pernyataan & tanda tangan: menggantikan blanko F-2.01 yang dulu ditulis tangan -->
            <div id="blokFormulir" style="margin-top: 30px; padding: 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
              <h3 style="margin-top: 0; margin-bottom: 6px; color: #1f2937;">Pernyataan dan Tanda Tangan</h3>
              <p class="helper" style="margin-top: 0;">
                Formulir F-2.01 dibuat otomatis oleh sistem dari isian di atas. Anda tidak perlu lagi
                mengunduh, menulis tangan, mencetak, atau memotret formulir.
              </p>

              <label style="display: flex; gap: 10px; align-items: flex-start; margin: 16px 0; cursor: pointer;">
                <input type="checkbox" id="pernyataanBenar" name="pernyataan_benar" value="1" required
                       style="margin-top: 4px; width: 18px; height: 18px; flex-shrink: 0;"
                       <?= !empty($_POST['pernyataan_benar']) ? 'checked' : '' ?>>
                <span>
                  Saya menyatakan bahwa seluruh data yang saya isi di atas adalah benar dan saya
                  bertanggung jawab sepenuhnya atas kebenaran data tersebut.
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
                <p class="helper" style="margin-top: 0;">Pratinjau formulir yang akan dikirim:</p>
                <img id="gambarPratinjau" alt="Pratinjau formulir F-2.01"
                     style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px;">
              </div>
              <div id="pesanPratinjau" style="display: none; margin-top: 12px; padding: 12px; background: #fef2f2; border-left: 4px solid #ef4444; border-radius: 6px; color: #991b1b;"></div>
            </div>
            <?php endif; ?>

            <!-- File Upload Multiple -->
            <div>
              <label class="field-label">Unggah Dokumen Pendukung <span style="color:red">*</span></label>
              <div class="f-doc-group">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                </h4>
                <div id="wajibUploadContainer">
                  <?php if (!FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
                  <div class="file-input-group" id="fileGroupWajib1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="formulir_f201" selected>Formulir F-2.01 Kematian</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Pilih File</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>
                  <?php endif; ?>

                  <div class="file-input-group" id="fileGroupWajib2">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="surat_kematian" selected>Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Pilih File</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>

                  <div class="file-input-group" id="fileGroupWajib3">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="identitas_meninggal" selected>KK/KTP/Surat Kehilangan KK/KTP yang meninggal</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib3">Pilih File</label>
                      <span id="fileNameWajib3" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib3" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib3">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>

                  <div class="file-input-group" id="fileGroupWajib4">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="foto_selfie" selected><?= esc($labelSelfie) ?></option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib4">Upload Foto Selfie</label>
                      <span id="fileNameWajib4" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib4" name="dokumen[]" accept="image/jpeg,image/png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib4">Maksimal 5 MB per file, dikompres otomatis</div>
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
                      <option value="ktp_ayah">KTP Ayah</option>
                      <option value="ktp_ibu">KTP Ibu</option>
                      <option value="ktp_saksi1">KTP Saksi 1</option>
                      <option value="ktp_saksi2">KTP Saksi 2</option>
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
                  + Tambah Dokumen Opsional Lainnya
                </button>
              </div>
              
              <p class="helper"><strong>Dokumen wajib:</strong>
              <ol>
                <?php if (!FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
                <li>Formulir F-2.01 Kematian</li>
                <?php endif; ?>
                <li>Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan</li>
                <li>KK/KTP/Surat Kehilangan KK/KTP yang meninggal</li>
                <li><?= esc($labelSelfie) ?></li>
              </ol>
              <?php if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
              <strong>Formulir F-2.01 dibuat otomatis oleh sistem</strong> dari isian Anda, jadi tidak perlu diunggah.<br>
              <?php endif; ?>
              <strong>Format:</strong> JPG, JPEG, JFIF, JPE, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong></p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="ajukan_permohonan.php" class="btn-secondary">Kembali</a>
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

  // Multiple file upload functionality dengan validasi ukuran 1MB
  let fileCounter = {
    wajib: 4,
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
        <option value="ktp_ayah">KTP Ayah</option>
        <option value="ktp_ibu">KTP Ibu</option>
        <option value="ktp_saksi1">KTP Saksi 1</option>
        <option value="ktp_saksi2">KTP Saksi 2</option>
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
  document.getElementById('aktaKematianForm').addEventListener('submit', function(e) {
    const fileInputs = document.querySelectorAll('input[type="file"]');
    let hasError = false;
    let errorMessages = [];
    
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
    const suratInput = document.getElementById('fileInputWajib2');
    const identitasInput = document.getElementById('fileInputWajib3');
    const selfieInput = document.getElementById('fileInputWajib4');

    // formulirInput tidak ada saat formulir dibuat sistem.
    if (formulirInput && !formulirInput.files.length) {
      errorMessages.push('Formulir F-2.01 Kematian wajib diupload');
      hasError = true;
    }
    if (!suratInput.files.length) {
      errorMessages.push('Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan wajib diupload');
      hasError = true;
    }
    if (!identitasInput.files.length) {
      errorMessages.push('KK/KTP/Surat Kehilangan KK/KTP yang meninggal wajib diupload');
      hasError = true;
    }
    if (!selfieInput.files.length) {
      errorMessages.push(<?= json_encode($labelSelfie . ' wajib diupload', JSON_UNESCAPED_UNICODE) ?>);
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

    // Validasi required fields
    const requiredFields = this.querySelectorAll('[required]');
    requiredFields.forEach(field => {
      if (!field.value.trim() && field.type !== 'file') {
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
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan akta kematian?');
      if (!confirmSubmit) {
        e.preventDefault();
      }
    }
  });
  </script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
<script src="../assets/js/formulir_ttd.js?v=20260919-1" data-form="aktaKematianForm" data-pratinjau="pratinjau_formulir_kematian.php"></script>
<?php endif; ?>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>
