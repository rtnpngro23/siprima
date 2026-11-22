<?php
require_once '../error_handler.php';
// Gunakan middleware khusus RS
include '../../app/middleware/user_rs_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/notifikasi.php';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$id_rs = (int)$_SESSION['id_rs']; // ID Rumah Sakit dari session

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
    $id_puskes = 0; // default value
    $id_rs = (int)$_SESSION['id_rs']; // ID Rumah Sakit dari session

    // Data bayi & orangtua
    $nama_bayi = trim($_POST['nama_bayi'] ?? '');
    $tempat_lahir = trim($_POST['tempat_lahir'] ?? '');
    $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '');
    $waktu = trim($_POST['waktu'] ?? '');
    $jenis_kelamin = trim($_POST['jenis_kelamin'] ?? '');
    $urutan_kelahiran = isset($_POST['urutan_kelahiran']) && $_POST['urutan_kelahiran'] !== '' ? (int)$_POST['urutan_kelahiran'] : null;
    $nama_ayah = trim($_POST['nama_ayah'] ?? '');
    $nik_ayah = trim($_POST['nik_ayah'] ?? '');
    $nama_ibu = trim($_POST['nama_ibu'] ?? '');
    $nik_ibu = trim($_POST['nik_ibu'] ?? '');
    
    // Data saksi
    $nama_saksi1 = trim($_POST['nama_saksi1'] ?? '');
    $nik_saksi1 = trim($_POST['nik_saksi1'] ?? '');
    $nama_saksi2 = trim($_POST['nama_saksi2'] ?? '');
    $nik_saksi2 = trim($_POST['nik_saksi2'] ?? '');
    
    $keterangan = trim($_POST['keterangan'] ?? '');

    // Validasi NIK harus 16 digit jika diisi
    if ($nik_ayah && strlen($nik_ayah) !== 16) {
        $errors[] = 'NIK Ayah harus 16 digit.';
    }
    if ($nik_ibu && strlen($nik_ibu) !== 16) {
        $errors[] = 'NIK Ibu harus 16 digit.';
    }
    if ($nik_saksi1 && strlen($nik_saksi1) !== 16) {
        $errors[] = 'NIK Saksi 1 harus 16 digit.';
    }
    if ($nik_saksi2 && strlen($nik_saksi2) !== 16) {
        $errors[] = 'NIK Saksi 2 harus 16 digit.';
    }

    // Validasi urutan_kelahiran jika diisi
    if ($urutan_kelahiran !== null && $urutan_kelahiran < 1) {
        $errors[] = 'Urutan kelahiran harus berupa angka lebih besar atau sama dengan 1.';
    }

    // Basic validation
    if (!$nama_bayi) $errors[] = 'Nama bayi wajib diisi.';
    if (!$tanggal_lahir) $errors[] = 'Tanggal lahir wajib diisi.';
    if (!$jenis_kelamin) $errors[] = 'Jenis kelamin wajib diisi.';

    // Ambil nama kecamatan dan desa tujuan
    $nama_kecamatan = '';
    $nama_desa = '';
    if (!empty($id_kecamatan)) {
        $qk = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $qk->bind_param("i", $id_kecamatan);
        $qk->execute();
        $rk = $qk->get_result()->fetch_assoc();
        if ($rk) $nama_kecamatan = $rk['nama_kecamatan'];
        $qk->close();
    }
    
    if (!empty($id_desa)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $qd->bind_param("i", $id_desa);
        $qd->execute();
        $rd = $qd->get_result()->fetch_assoc();
        if ($rd) $nama_desa = $rd['nama_desa'];
        $qd->close();
    }

    // File upload - MULTIPLE FILES, maksimal 1MB SETELAH kompresi otomatis di sisi klien
    $uploadedFiles = [];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Dokumen wajib berdasarkan persyaratan RS
    $requiredDocs = [
        'surat_keterangan_rs', // 1. Surat Keterangan Lahir dari RS
        'formulir',           // 2. Formulir F-2.01 Kelahiran
        'buku_nikah'          // 3. Buku Nikah/Akta Kawin/SPTJM Perkawinan
    ];
    $uploadedDocTypes = [];
    
    // Buat folder dengan format khusus: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama bayi]_[tanggal buat]
    $folderName = str_replace(' ', '_', $nama_kecamatan) . '_' . 
                  str_replace(' ', '_', $nama_desa) . '_' . 
                  $data_user['nik'] . '_' . 
                  str_replace(' ', '_', $data_user['nama']) . '_' . 
                  str_replace(' ', '_', $nama_bayi) . '_' . 
                  date('Ymd');
    
    // Hilangkan karakter tidak aman
    $folderName = preg_replace('/[^A-Za-z0-9_]/', '', $folderName);
    
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        // Buat direktori upload utama jika belum ada
        $baseUploadDir = __DIR__ . '/uploads/akta_kelahiran_rs';
        if (!is_dir($baseUploadDir)) {
            mkdir($baseUploadDir, 0755, true);
        }
        
        // Buat folder khusus untuk permohonan ini
        $permohonanDir = $baseUploadDir . '/' . $folderName;
        if (!is_dir($permohonanDir)) {
            mkdir($permohonanDir, 0755, true);

            // --- TAMBAHKAN LOGIKA .HTACCESS DI SINI ---
            $htaccessPath = $permohonanDir . '/.htaccess';
            $htaccessContent = "Order Deny,Allow\n" . 
                              "Deny from all\n" . 
                              "<Files ~ \"\.(jpg|jpeg|png|gif)$\">\n" . 
                              "    Allow from all\n" . 
                              "</Files>\n" .
                              "Options -Indexes";
            
            file_put_contents($htaccessPath, $htaccessContent);
            // ------------------------------------------

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
                $allowedExt = ['jpg', 'jpeg', 'jfif', 'jpe', 'png'];
                if (!in_array($ext, $allowedExt) || 
                    !in_array($fileType, $allowedTypes)) {
                    $errors[] = "File {$fileName}: Gunakan JPG, JPEG, JFIF, JPE, atau PNG";
                    continue;
                }

                // Validasi ukuran file - MAKSIMAL 1MB
                if ($fileSize > $maxFileSize) {
                    $errors[] = "File {$fileName}: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
                    continue;
                }

                // Generate nama file yang lebih deskriptif
                $docTypeName = '';
                switch ($docType) {
                    case 'surat_keterangan_rs':
                        $docTypeName = 'Surat_Keterangan_RS';
                        break;
                    case 'formulir':
                        $docTypeName = 'Formulir_F201_Kelahiran';
                        break;
                    case 'buku_nikah':
                        $docTypeName = 'Buku_Nikah_Atau_SPTJM';
                        break;
                    case 'kk':
                        $docTypeName = 'Kartu_Keluarga';
                        break;
                    case 'ktp_ortu':
                        $docTypeName = 'KTP_Orang_Tua';
                        break;
                    default:
                        $docTypeName = 'Dokumen_Lainnya_' . ($i + 1);
                }
                
                // Nama file simple: [tipe]_[timestamp]_[random].ext
                $newName = $docTypeName . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                
                // Simpan di folder utama
                $destination = $permohonanDir . '/' . $newName;
                $savePath = 'uploads/akta_kelahiran_rs/' . $folderName . '/' . $newName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    $uploadedFiles[] = [
                        'path' => $savePath,
                        'type' => $docType,
                        'original_name' => $fileName,
                        'folder' => $folderName
                    ];
                    $uploadedDocTypes[] = $docType;
                } else {
                    $errors[] = "File {$fileName}: Gagal menyimpan file";
                }
            } elseif ($_FILES['dokumen']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $errors[] = "Error upload file: " . $_FILES['dokumen']['name'][$i];
            }
        }
    }

    // Cek dokumen wajib - semua harus ada
    $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            switch ($doc) {
                case 'surat_keterangan_rs':
                    $missingNames[] = 'Surat Keterangan Lahir dari Rumah Sakit';
                    break;
                case 'formulir':
                    $missingNames[] = 'Formulir F-2.01 Kelahiran';
                    break;
                case 'buku_nikah':
                    $missingNames[] = 'Buku Nikah/Akta Kawin/SPTJM Perkawinan';
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

        // Normalisasi field Waktu ke format HH:MM:SS
        if (!empty($waktu) && preg_match('/^\d{2}:\d{2}$/', $waktu)) {
            $waktu .= ':00';
        }

        // Potong keterangan jika terlalu panjang (maks 50 karakter sesuai tabel)
        if (strlen($keterangan) > 50) {
            $keterangan = substr($keterangan, 0, 47) . '...';
        }

        // Convert uploaded files to JSON string
        $dokumenJson = json_encode($uploadedFiles);

        // Simpan status dalam variabel terpisah
        $status = 'diajukan';
        $alasan = ''; // Dikosongkan untuk operator
        
        // Query untuk insert data akta kelahiran RS (sesuai struktur tabel tb_akta_kelahiran_rs)
        $stmt = $koneksi->prepare("INSERT INTO tb_akta_kelahiran_rs 
            (id_user, id_kecamatan, id_desa, id_rs,
             nama_pemohon, nik_pemohon, no_hp, email, 
             nama_kecamatan, nama_desa, 
             nama_ayah, nik_ayah, nama_ibu, nik_ibu,
             nama_saksi1, nik_saksi1, nama_saksi2, nik_saksi2,
             nama_bayi, tempat_lahir, jenis_kelamin, tanggal_lahir, 
             urutan_kelahiran, Waktu, dokumen, status, alasan, 
             keterangan, nama_folder, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        
        if (!$stmt) {
            $errors[] = 'Prepare statement error: ' . $koneksi->error;
        } else {
            $stmt->bind_param(
                "iiiissssssssssssssssssissssss", // 32 parameter sesuai tabel
                $user_id,           // i (integer)
                $id_kecamatan,      // i (integer)
                $id_desa,           // i (integer)
                $id_rs,             // i (integer) - ID Rumah Sakit
                $nama_pemohon,      // s (string)
                $nik_pemohon,       // s (string)
                $no_hp,             // s (string)
                $email,             // s (string)
                $nama_kecamatan,    // s (string)
                $nama_desa,         // s (string)
                $nama_ayah,         // s (string)
                $nik_ayah,          // s (string)
                $nama_ibu,          // s (string)
                $nik_ibu,           // s (string)
                $nama_saksi1,       // s (string)
                $nik_saksi1,        // s (string)
                $nama_saksi2,       // s (string)
                $nik_saksi2,        // s (string)
                $nama_bayi,         // s (string)
                $tempat_lahir,      // s (string)
                $jenis_kelamin,     // s (string)
                $tanggal_lahir,     // s (string)
                $urutan_kelahiran,  // i (integer)
                $waktu,             // s (string)
                $dokumenJson,       // s (string - JSON)
                $status,            // s (string)
                $alasan,            // s (string)
                $keterangan,        // s (string) - max 50 karakter
                $folderName         // s (string) - nama folder penyimpanan
            );
            
            if ($stmt->execute()) {
                try {
                    buatNotifikasiOperatorUnit($koneksi, 'tb_akta_kelahiran_rs', (int)$stmt->insert_id, 'Pengajuan Baru', 'Permohonan baru menunggu pemeriksaan.');
                } catch (Throwable $e) {
                    error_log('Notifikasi operator RS gagal: ' . $e->getMessage());
                }
                $_SESSION['success_message'] = 'Permohonan Akta Kelahiran berhasil diajukan ke Rumah Sakit.';
                header('Location: rs_ajukan_permohonan.php');
                exit;
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
                // Hapus folder yang sudah dibuat jika insert gagal
                if (is_dir($permohonanDir)) {
                    // Fungsi rekursif untuk hapus folder
                    function deleteDirectory($dir) {
                        if (!is_dir($dir)) return;
                        $files = array_diff(scandir($dir), array('.', '..'));
                        foreach ($files as $file) {
                            (is_dir("$dir/$file")) ? deleteDirectory("$dir/$file") : unlink("$dir/$file");
                        }
                        return rmdir($dir);
                    }
                    deleteDirectory($permohonanDir);
                }
            }
            $stmt->close();
        }
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
    $qk2->close();
}

if (!empty($selected_desa)) {
    $qd2 = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
    $qd2->bind_param("i", $selected_desa);
    $qd2->execute();
    $rd2 = $qd2->get_result()->fetch_assoc();
    if ($rd2) $pre_nama_desa = $rd2['nama_desa'];
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
  <title>Ajukan Akta Kelahiran - <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></title>
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/rs_akta_kelahiran.css">
  <link rel="icon" href="../assets/st sehati.png" type="image/png">
  
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-rs">

  <div class="layout">

    <aside class="sidebar">
      <div class="logo">ST SEHATI - <?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></div>
      <nav>
        <ul>
          <li><a href="rs_dashboard_user.php">Dashboard</a></li>
          <li class="active"><a href ="rs_ajukan_permohonan.php">Ajukan Permohonan</a></li>
          <li><a href="rs_status_permohonan.php">Status Permohonan</a></li>
          <li><a href="rs_profil.php">Profil Saya</a></li>
          <li><a href="rs_formulir.php">Formulir Kependudukan</a></li>
          <li id="logoutBtn">Logout</li>
        </ul>
      </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header">
        <div class="left-header">
          <div class="title">Ajukan Akta Kelahiran</div>
          <div class="subtitle"><?= htmlspecialchars($_SESSION['nama_rs'] ?? 'Rumah Sakit') ?></div>
        </div>
        <div class="user-info">
          <span><?= htmlspecialchars($_SESSION['nama']) ?></span>
          <span class="role-badge"><?= strtoupper($_SESSION['role']) ?></span>
          <?php include __DIR__ . '/../user/notification_widget.php'; ?>
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
              <h2 class="form-title">Form Pengajuan Akta Kelahiran</h2>
              <p class="helper">Lengkapi data bayi, orang tua, dan saksi, lalu unggah dokumen pendukung.</p>
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

          <form method="POST" enctype="multipart/form-data" class="space-y-4" id="aktaForm">
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

            <!-- Data Bayi -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Bayi</h3>
            
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Bayi <span style="color:red">*</span></label>
                <input type="text" name="nama_bayi" value="<?= esc($_POST['nama_bayi'] ?? '') ?>" required class="input" placeholder="Nama lengkap bayi">
              </div>

              <div>
                <label class="field-label">Jenis Kelamin <span style="color:red">*</span></label>
                <select name="jenis_kelamin" class="select" required>
                  <option value="">-- Pilih --</option>
                  <option value="L" <?= (($_POST['jenis_kelamin'] ?? '') === 'L') ? 'selected' : '' ?>>Laki-laki</option>
                  <option value="P" <?= (($_POST['jenis_kelamin'] ?? '') === 'P') ? 'selected' : '' ?>>Perempuan</option>
                </select>
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Tempat Lahir <span style="color:red">*</span></label>
                <input type="text" name="tempat_lahir" value="<?= esc($_POST['tempat_lahir'] ?? '') ?>" required class="input" placeholder="Kabupaten Gorontalo / Kota Gorontalo">
              </div>
              <div>
                <label class="field-label">Tanggal Lahir <span style="color:red">*</span></label>
                <input type="date" name="tanggal_lahir" value="<?= esc($_POST['tanggal_lahir'] ?? '') ?>" required class="input">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Waktu Lahir <span style="color:red">*</span></label>
                <input type="time" name="waktu" value="<?= esc($_POST['waktu'] ?? '') ?>" required class="input">
              </div>
              <div>
                <label class="field-label">Anak ke- <span style="color:red">*</span></label>
                <input type="number" name="urutan_kelahiran" min="1" value="<?= esc($_POST['urutan_kelahiran'] ?? '') ?>" required class="input" placeholder="Contoh: 1">
              </div>
            </div>

            <!-- Data Orang Tua -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Orang Tua</h3>
            
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Ayah <span style="color:red">*</span></label>
                <input type="text" name="nama_ayah" value="<?= esc($_POST['nama_ayah'] ?? '') ?>" required class="input" placeholder="Nama lengkap ayah">
              </div>
              <div>
                <label class="field-label">NIK Ayah <span style="color:red">*</span></label>
                <input type="text" name="nik_ayah" value="<?= esc($_POST['nik_ayah'] ?? '') ?>" required class="input" placeholder="16 digit NIK ayah" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Ibu <span style="color:red">*</span></label>
                <input type="text" name="nama_ibu" value="<?= esc($_POST['nama_ibu'] ?? '') ?>" required class="input" placeholder="Nama lengkap ibu">
              </div>
              <div>
                <label class="field-label">NIK Ibu <span style="color:red">*</span></label>
                <input type="text" name="nik_ibu" value="<?= esc($_POST['nik_ibu'] ?? '') ?>" required class="input" placeholder="16 digit NIK ibu" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <!-- Data Saksi -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Saksi</h3>
            
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Saksi 1 <span style="color:red">*</span></label>
                <input type="text" name="nama_saksi1" value="<?= esc($_POST['nama_saksi1'] ?? '') ?>" required class="input" placeholder="Nama lengkap saksi 1">
              </div>
              <div>
                <label class="field-label">NIK Saksi 1 <span style="color:red">*</span></label>
                <input type="text" name="nik_saksi1" value="<?= esc($_POST['nik_saksi1'] ?? '') ?>" required class="input" placeholder="16 digit NIK saksi 1" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Saksi 2</label>
                <input type="text" name="nama_saksi2" value="<?= esc($_POST['nama_saksi2'] ?? '') ?>" class="input" placeholder="Nama lengkap saksi 2">
              </div>
              <div>
                <label class="field-label">NIK Saksi 2</label>
                <input type="text" name="nik_saksi2" value="<?= esc($_POST['nik_saksi2'] ?? '') ?>" class="input" placeholder="16 digit NIK saksi 2" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <!-- Keterangan / Pesan -->
            <div>
              <label class="field-label">Keterangan / Pesan untuk Operator <span style="color:red">*</span></label>
              <textarea name="keterangan" required class="input" rows="3" placeholder="Contoh: Permohonan akta kelahiran pertama" maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
              <p class="helper" style="font-size: 12px;">Maksimal 500 karakter</p>
            </div>

            <!-- File Upload Multiple -->
            <div>
              <label class="field-label">Unggah Dokumen Pendukung <span style="color:red">*</span></label>

              <div class="f-doc-group">
                <h4 class="f-doc-group__judul">Dokumen Wajib</h4>
                <div id="wajibUploadContainer">
                  <div class="file-input-group" id="fileGroupWajib1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="surat_keterangan_rs" selected>Surat Keterangan Lahir dari Rumah Sakit</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Pilih File</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>

                  <div class="file-input-group" id="fileGroupWajib2">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="formulir" selected>Formulir F-2.01 Kelahiran</option>
                    </select>
                    <div class="file-size-warning">
                      Unduh Formulir F-2.01 Kelahiran
                      <a href="https://siprima.gorontalokab.go.id/uploads/formulir/1766758549_694e9895d9914.pdf" target="_blank" rel="noopener noreferrer">klik disini</a>
                    </div>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Pilih File</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>

                  <div class="file-input-group" id="fileGroupWajib3">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="buku_nikah" selected>Buku Nikah/Akta Kawin/SPTJM Perkawinan</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib3">Pilih File</label>
                      <span id="fileNameWajib3" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib3" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib3">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>
                </div>
              </div>

              <div class="f-doc-group f-doc-group--opsional">
                <h4 class="f-doc-group__judul">Dokumen Opsional (Jika Ada)</h4>
                <div id="opsionalUploadContainer">
                  <div class="file-input-group" id="fileGroupOpsional1">
                    <select name="doc_type[]" class="file-type-select">
                      <option value="">Pilih Jenis Dokumen (Opsional)</option>
                      <option value="kk">Kartu Keluarga (KK)</option>
                      <option value="ktp_ortu">KTP Orang Tua</option>
                      <option value="lainnya">Dokumen Pendukung Lainnya</option>
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

              <p class="helper">
                <strong>Dokumen WAJIB untuk Rumah Sakit:</strong><br>
                1. Surat Keterangan Lahir dari Rumah Sakit<br>
                2. Formulir F-2.01 Kelahiran
                (<a href="https://siprima.gorontalokab.go.id/uploads/formulir/1766758549_694e9895d9914.pdf" target="_blank" rel="noopener noreferrer">klik disini</a>)<br>
                3. Buku Nikah/Akta Kawin/SPTJM Perkawinan<br><br>
                <strong>Format file:</strong> JPG, JPEG, JFIF, JPE, PNG<br>
                <strong>Ukuran maksimal:</strong> 1MB per file<br>
            </p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="rs_ajukan_permohonan.php" class="btn-secondary">Kembali</a>
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
  // JavaScript untuk form akta kelahiran RS
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
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout_pasutri_ahlak.php');
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

      fetch("../user/get_desa.php?id_kecamatan=" + idKecamatan)
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

  // Multiple file upload functionality
  let fileCounter = {
    wajib: 3,
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
        <option value="kk">Kartu Keluarga (KK)</option>
        <option value="ktp_ortu">KTP Orang Tua</option>
        <option value="lainnya">Dokumen Pendukung Lainnya</option>
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
      newFileInput.addEventListener('change', function() {
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
  // Delegasi global untuk menstabilkan setiap file yang dipilih.
  document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    stabilizeSelectedFile(target);
  });

  // Validasi form sebelum submit
  document.getElementById('aktaForm').addEventListener('submit', function(e) {
    const fileInputs = document.querySelectorAll('input[type="file"]');
    let hasError = false;
    let errorMessages = [];
    
    // Validasi NIK harus 16 digit
    const nikFields = ['nik_ayah', 'nik_ibu', 'nik_saksi1'];
    nikFields.forEach(fieldName => {
      const field = document.querySelector(`input[name="${fieldName}"]`);
      if (field && field.value.trim() !== '' && field.value.length !== 16) {
        const fieldLabel = fieldName.replace('_', ' ');
        errorMessages.push(`${fieldLabel} harus 16 digit`);
        field.style.borderColor = '#ef4444';
        hasError = true;
      }
    });
    
    // Validasi NIK saksi 2 jika diisi
    const nikSaksi2 = document.querySelector('input[name="nik_saksi2"]');
    if (nikSaksi2 && nikSaksi2.value.trim() !== '' && nikSaksi2.value.length !== 16) {
      errorMessages.push('NIK Saksi 2 harus 16 digit jika diisi');
      nikSaksi2.style.borderColor = '#ef4444';
      hasError = true;
    }
    
    // Validasi ukuran file
    fileInputs.forEach((input, index) => {
      if (input.files.length > 0) {
        const file = input.files[0];
        if (file.size > MAX_FILE_SIZE) {
          errorMessages.push(`File "${file.name}" belum berhasil dikompres, pilih ulang file tersebut`);
          input.style.borderColor = '#ef4444';
          hasError = true;
        }
      }
    });
    
    const suratRSInput = document.getElementById('fileInputWajib1');
    const formulirInput = document.getElementById('fileInputWajib2');
    const bukuInput = document.getElementById('fileInputWajib3');

    if (!suratRSInput.files.length) {
      errorMessages.push('Surat Keterangan Lahir dari Rumah Sakit wajib diupload');
      hasError = true;
    }
    
    if (!formulirInput.files.length) {
      errorMessages.push('Formulir F-2.01 Kelahiran wajib diupload');
      hasError = true;
    }
    
    if (!bukuInput.files.length) {
      errorMessages.push('Buku Nikah/Akta Kawin/SPTJM Perkawinan wajib diupload');
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
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan akta kelahiran?\n\nPastikan semua dokumen wajib sudah diupload:\n1. Surat Keterangan Lahir dari Rumah Sakit\n2. Formulir F-2.01 Kelahiran\n3. Buku Nikah/Akta Kawin');
      if (!confirmSubmit) {
        e.preventDefault();
      }
    }
  });
  </script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<script src="../assets/js/baby-names.js?v=20260909-2"></script>
<script src="../user/js/baby-name-handoff.js?v=20260909-2" data-account="<?= (int)$_SESSION['id_user'] ?>" data-method="<?= ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 'GET' : 'POST' ?>" data-catalog="../assets/data/baby-names.json?v=20260909-2"></script>
</body>
</html>
