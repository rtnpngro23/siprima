<?php
require_once '../error_handler.php';
// Gunakan user_ahlak_only.php untuk validasi khusus Puskesmas AHLAK
include '../../app/middleware/user_ahlak_only.php';

// Debug
error_log("=== ahlak_akta_kelahiran.php START ===");
error_log("id_user: " . $_SESSION['id_user']);
error_log("id_puskes: " . $_SESSION['id_puskes']);
error_log("nama_puskes: " . ($_SESSION['nama_puskes'] ?? 'NOT SET'));
error_log("role: " . $_SESSION['role']);

// Koneksi database
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/notifikasi.php';

$user_id = (int)$_SESSION['id_user'];
$id_puskes = (int)$_SESSION['id_puskes'];

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

// Ambil data puskesmas dari tabel tb_puskes
$q_puskes = $koneksi->prepare("SELECT id_kecamatan, nama_puskes FROM tb_puskes WHERE id_puskes = ?");
$q_puskes->bind_param("i", $id_puskes);
$q_puskes->execute();
$puskes_data = $q_puskes->get_result()->fetch_assoc();
$q_puskes->close();

if (!$puskes_data) {
    die("Data puskesmas tidak ditemukan!");
}

$id_kecamatan_ahlak = (int)($puskes_data['id_kecamatan'] ?? 0);
$nama_puskes = $puskes_data['nama_puskes'] ?? '';

// Ambil nama kecamatan dari tabel tb_kecamatan
$q_kec = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
$q_kec->bind_param("i", $id_kecamatan_ahlak);
$q_kec->execute();
$kec_data = $q_kec->get_result()->fetch_assoc();
$q_kec->close();

$nama_kecamatan_ahlak = $kec_data['nama_kecamatan'] ?? '';

error_log("Kecamatan AHLAK: id=$id_kecamatan_ahlak, nama=$nama_kecamatan_ahlak, puskes=$nama_puskes");

$errors = [];
$success_message = '';

// Untuk mempertahankan pilihan setelah submit gagal
$selected_kecamatan = $id_kecamatan_ahlak; // Kunci: otomatis pakai kecamatan dari puskesmas
$selected_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : '';
$pre_nama_kecamatan = $nama_kecamatan_ahlak; // Otomatis terisi
$pre_nama_desa = '';

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // IDs kecamatan/desa (kecamatan terkunci, desa dipilih)
    $id_kecamatan = $id_kecamatan_ahlak; // SELALU dari puskesmas
    $id_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : 0;
    $id_puskes_form = $id_puskes; // SELALU dari session

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
    if (!$nama_ayah) $errors[] = 'Nama ayah wajib diisi.';
    if (!$nik_ayah) $errors[] = 'NIK ayah wajib diisi.';
    if (!$nama_ibu) $errors[] = 'Nama ibu wajib diisi.';
    if (!$nik_ibu) $errors[] = 'NIK ibu wajib diisi.';
    if (!$nama_saksi1) $errors[] = 'Nama saksi 1 wajib diisi.';
    if (!$nik_saksi1) $errors[] = 'NIK saksi 1 wajib diisi.';
    
    // Validasi kecamatan sudah dipilih (harus selalu ada karena terkunci)
    if (!$id_kecamatan) {
        $errors[] = 'Kecamatan tidak ditemukan untuk puskesmas ini.';
    }

    // Validasi desa wajib dipilih
    if (!$id_desa) {
        $errors[] = 'Desa wajib dipilih.';
    }

    // Validasi format tanggal lahir
    if (!empty($tanggal_lahir)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_lahir)) {
            $errors[] = 'Format tanggal lahir tidak valid. Gunakan format YYYY-MM-DD.';
        } else {
            $date_parts = explode('-', $tanggal_lahir);
            if (!checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
                $errors[] = 'Tanggal lahir tidak valid.';
            }
            $birth_date = new DateTime($tanggal_lahir);
            $today = new DateTime();
            if ($birth_date > $today) {
                $errors[] = 'Tanggal lahir tidak boleh di masa depan.';
            }
        }
    }

    // Ambil nama desa tujuan
    $nama_desa = '';
    if (!empty($id_desa)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ? AND kecamatan_id = ?");
        $qd->bind_param("ii", $id_desa, $id_kecamatan);
        $qd->execute();
        $rd = $qd->get_result()->fetch_assoc();
        if ($rd) {
            $nama_desa = $rd['nama_desa'];
        } else {
            $errors[] = 'Desa tidak valid untuk kecamatan ini.';
        }
        $qd->close();
    }

    // File upload - MULTIPLE FILES, maksimal 1MB SETELAH kompresi otomatis di sisi klien
    $uploadedFiles = [];
    $allowedTypes = ['image/jpeg', 'image/png'];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Dokumen wajib berdasarkan persyaratan
    $requiredDocs = [
        'formulir',          // 1. Formulir F-2.01 Kelahiran
        'surat_keterangan',  // 2. Surat Keterangan Lahir
        'buku_nikah',        // 3. Buku Nikah/Akta Kawin/SPTJM Perkawinan
        'kk_lama'            // 4. Kartu Keluarga lama
    ];
    $uploadedDocTypes = [];
    
    // Buat folder dengan format khusus SAMA seperti sebelumnya
    $folderName = str_replace(' ', '_', $nama_kecamatan_ahlak) . '_' . 
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
        $baseUploadDir = __DIR__ . '/uploads/akta_kelahiran_ahlak';
        if (!is_dir($baseUploadDir)) {
            mkdir($baseUploadDir, 0755, true);
        }
        
        // Buat folder khusus untuk permohonan ini
        $permohonanDir = $baseUploadDir . '/' . $folderName;
        if (!is_dir($permohonanDir)) {
            mkdir($permohonanDir, 0755, true);
            // --- TAMBAHKAN LOGIKA .HTACCESS DI SINI ---
            $htaccessFile = $permohonanDir . '/.htaccess';
            $htaccessContent = "Order Deny,Allow\n" . 
                              "Deny from all\n" . 
                              "<Files ~ \"\.(jpg|jpeg|png|gif)$\">\n" . 
                              "    Allow from all\n" . 
                              "</Files>\n" .
                              "Options -Indexes"; // Tambahan: mencegah listing direktori
            file_put_contents($htaccessFile, $htaccessContent);
            // ------------------------------------------

        }
        
        // Buat subfolder untuk dokumen pendukung
        $pendukungDir = $permohonanDir . '/dokumen_pendukung';
        if (!is_dir($pendukungDir)) {
            mkdir($pendukungDir, 0755, true);
            // --- TAMBAHKAN LOGIKA .HTACCESS DI SINI ---
            $htaccessFile = $permohonanDir . '/.htaccess';
            $htaccessContent = "Order Deny,Allow\n" . 
                              "Deny from all\n" . 
                              "<Files ~ \"\.(jpg|jpeg|png|gif)$\">\n" . 
                              "    Allow from all\n" . 
                              "</Files>\n" .
                              "Options -Indexes"; // Tambahan: mencegah listing direktori
            file_put_contents($htaccessFile, $htaccessContent);
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
                    case 'formulir':
                        $docTypeName = 'Formulir_F201_Kelahiran';
                        break;
                    case 'surat_keterangan':
                        $docTypeName = 'Surat_Keterangan_Lahir';
                        break;
                    case 'buku_nikah':
                        $docTypeName = 'Buku_Nikah_Atau_SPTJM';
                        break;
                    case 'kk_lama':
                        $docTypeName = 'Kartu_Keluarga_Lama';
                        break;
                    case 'kk':
                        $docTypeName = 'Kartu_Keluarga';
                        break;
                    case 'ktp_ortu':
                        $docTypeName = 'KTP_Orang_Tua';
                        break;
                    default:
                        $docTypeName = 'Dokumen_Lainnya';
                }
                
                $newName = $docTypeName . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                
                // Simpan di folder utama atau subfolder pendukung
                if ($docType === 'lainnya') {
                    $destination = $pendukungDir . '/' . $newName;
                    $savePath = 'uploads/akta_kelahiran_ahlak/' . $folderName . '/dokumen_pendukung/' . $newName;
                } else {
                    $destination = $permohonanDir . '/' . $newName;
                    $savePath = 'uploads/akta_kelahiran_ahlak/' . $folderName . '/' . $newName;
                }

                if (move_uploaded_file($fileTmp, $destination)) {
                    $uploadedFiles[] = [
                        'path' => $savePath,
                        'type' => $docType,
                        'original_name' => $fileName,
                        'folder' => $folderName
                    ];
                    $uploadedDocTypes[] = $docType === 'kk' ? 'kk_lama' : $docType;
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
                case 'formulir':
                    $missingNames[] = 'Formulir F-2.01 Kelahiran';
                    break;
                case 'surat_keterangan':
                    $missingNames[] = 'Surat Keterangan Lahir dari fasilitas kesehatan/desa/kelurahan';
                    break;
                case 'buku_nikah':
                    $missingNames[] = 'Buku Nikah/Akta Kawin/SPTJM Perkawinan';
                    break;
                case 'kk_lama':
                    $missingNames[] = 'Kartu Keluarga lama';
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
        
        // Query untuk insert data akta kelahiran AHLAK (sesuai struktur tabel tb_akta_kelahiran_ahlak)
        $stmt = $koneksi->prepare("INSERT INTO tb_akta_kelahiran_ahlak 
            (id_user, id_kecamatan, id_desa, id_puskes,
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
            error_log("Prepare error: " . $koneksi->error);
        } else {
            $stmt->bind_param(
                "iiiissssssssssssssssssissssss", 
                $user_id,           // i (integer)
                $id_kecamatan,      // i (integer)
                $id_desa,           // i (integer)
                $id_puskes_form,    // i (integer) - dari session
                $nama_pemohon,      // s (string)
                $nik_pemohon,       // s (string)
                $no_hp,             // s (string)
                $email,             // s (string)
                $nama_kecamatan_ahlak, // s (string)
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
                    buatNotifikasiOperatorUnit($koneksi, 'tb_akta_kelahiran_ahlak', (int)$stmt->insert_id, 'Pengajuan Baru', 'Permohonan baru menunggu pemeriksaan.');
                } catch (Throwable $e) {
                    error_log('Notifikasi operator AHLAK gagal: ' . $e->getMessage());
                }
                $_SESSION['success_message'] = 'Permohonan Akta Kelahiran AHLAK berhasil diajukan.';
                header('Location: ahlak_status_permohonan.php');
                exit;
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
                error_log("Execute error: " . $stmt->error);
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
    $selected_desa = $id_desa;
    
    // Ambil nama desa untuk form repopulation
    if (!empty($selected_desa)) {
        $qd2 = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ? AND kecamatan_id = ?");
        $qd2->bind_param("ii", $selected_desa, $id_kecamatan_ahlak);
        $qd2->execute();
        $rd2 = $qd2->get_result()->fetch_assoc();
        if ($rd2) $pre_nama_desa = $rd2['nama_desa'];
        $qd2->close();
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ajukan Akta Kelahiran AHLAK - <?= esc($nama_puskes) ?></title>
  <link rel="stylesheet" href="css/ahlak_akta_kelahiran.css">
  <link rel="icon" type="image/png" href="../assets/ahlak.png">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-ahlak">

  <div class="layout">

    <aside class="sidebar">
      <div class="logo">Pelayanan Kependudukan AHLAK</div>
      <nav>
        <ul>
          <li><a href="ahlak_dashboard_user.php">Dashboard</a></li>
          <li class="active"><a href="ahlak_akta_kelahiran.php">Ajukan Permohonan</a></li>
          <li><a href="ahlak_status_permohonan.php">Status Permohonan</a></li>                
          <li><a href="ahlak_formulir.php">Formulir Kependudukan</a></li>
          <li><a href="ahlak_profil.php">Profil Saya</a></li>
          <li id="logoutBtn">Logout</li>
        </ul>
      </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header">
        <div class="left-header">
          <div class="title">Ajukan Akta Kelahiran AHLAK</div>
          <div class="subtitle">Puskesmas: <?= esc($nama_puskes) ?> | Kecamatan: <?= esc($nama_kecamatan_ahlak) ?></div>
        </div>
        <div class="user-info">
          Hai, <?= esc($_SESSION['nama']) ?>
          <span class="puskes-badge">USER AHLAK</span>
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
              <h2 class="form-title">Form Pengajuan Akta Kelahiran AHLAK</h2>
              <p class="helper">Lengkapi data bayi, orang tua, dan saksi, lalu unggah dokumen pendukung.</p>
              <!--
              <div class="locked-info">
                <strong>Catatan Penting:</strong> Anda mengajukan permohonan untuk wilayah <strong><?= esc($nama_kecamatan_ahlak) ?></strong> 
                sebagai petugas <strong><?= esc($nama_puskes) ?></strong>. Pilihan kecamatan terkunci.
              </div>
                -->
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
            <div class="info-box">
              <h4>Informasi Pemohon</h4>
              <p><strong>Nama:</strong> <?= esc($data_user['nama'] ?? '') ?></p>
              <p><strong>NIK:</strong> <?= esc($data_user['nik'] ?? '') ?></p>
              <p><strong>No HP:</strong> <?= esc($data_user['no_hp'] ?? '') ?></p>
              <p><strong>Email:</strong> <?= esc($data_user['email'] ?? '') ?></p>
              <input type="hidden" name="nama_pemohon" value="<?= esc($data_user['nama'] ?? '') ?>">
              <input type="hidden" name="nik_pemohon" value="<?= esc($data_user['nik'] ?? '') ?>">
              <input type="hidden" name="no_hp" value="<?= esc($data_user['no_hp'] ?? '') ?>">
              <input type="hidden" name="email" value="<?= esc($data_user['email'] ?? '') ?>">
            </div>

            <!-- Kecamatan & Desa -->
            <div class="form-grid-2">
              <div>
                <label class="field-label">Kecamatan <span style="color:red">*</span></label>
                <input type="text" value="<?= esc($nama_kecamatan_ahlak) ?>" readonly class="input bg-gray-100 locked-field">
                <input type="hidden" name="id_kecamatan" value="<?= esc($id_kecamatan_ahlak) ?>">
              </div>

              <div>
                <label class="field-label">Desa <span style="color:red">*</span></label>
                <select name="id_desa" id="desaSelect" required class="select">
                  <option value="">-- Pilih Desa --</option>
                <?php
                // Ambil desa berdasarkan kecamatan puskesmas
                $des = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
                $des->bind_param("i", $id_kecamatan_ahlak);
                $des->execute();
                $resDes = $des->get_result();
                while ($drow = $resDes->fetch_assoc()) {
                    $selD = ($selected_desa == $drow['id']) ? ' selected' : '';
                    echo "<option value='" . esc($drow['id']) . "'{$selD}>" . esc($drow['nama_desa']) . "</option>";
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
                <input type="number" name="urutan_kelahiran" min="1" value="<?= esc($_POST['urutan_kelahiran'] ?? '1') ?>" required class="input" placeholder="Contoh: 1">
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
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Saksi (2 Saksi)</h3>
            
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
              <textarea name="keterangan" required class="input" rows="3" placeholder="Contoh: Permohonan akta kelahiran pertama untuk Puskesmas <?= esc($nama_puskes) ?>" maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
              <p class="helper" style="font-size: 12px;">Maksimal 500 karakter</p>
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
                    <input type="hidden" name="doc_type[]" value="formulir">
                    <strong>Formulir F-2.01 Kelahiran</strong>
                    <div class="file-size-warning">
                      Unduh Formulir F-2.01 Kelahiran
                      <a href="https://siprima.gorontalokab.go.id/uploads/formulir/1766758549_694e9895d9914.pdf" target="_blank" rel="noopener noreferrer">klik disini</a>
                    </div>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Pilih File</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>

                  <div class="file-input-group" id="fileGroupWajib2">
                    <input type="hidden" name="doc_type[]" value="surat_keterangan">
                    <strong>Surat Keterangan Lahir dari fasilitas kesehatan/desa/kelurahan</strong>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Pilih File</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>

                  <div class="file-input-group" id="fileGroupWajib3">
                    <input type="hidden" name="doc_type[]" value="buku_nikah">
                    <strong>Buku Nikah/Akta Kawin/SPTJM Perkawinan</strong>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib3">Pilih File</label>
                      <span id="fileNameWajib3" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib3" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib3">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>

                  <div class="file-input-group" id="fileGroupWajib4">
                    <input type="hidden" name="doc_type[]" value="kk_lama">
                    <strong>Kartu Keluarga lama</strong>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib4">Pilih File</label>
                      <span id="fileNameWajib4" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib4" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" required class="file-input">
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
                <strong>Dokumen WAJIB untuk AHLAK:</strong><br>
                1. Formulir F-2.01 Kelahiran
                (<a href="https://siprima.gorontalokab.go.id/uploads/formulir/1766758549_694e9895d9914.pdf" target="_blank" rel="noopener noreferrer">klik disini</a>)<br>
                2. Surat Keterangan Lahir dari fasilitas kesehatan/desa/kelurahan<br>
                3. Buku Nikah/Akta Kawin/SPTJM Perkawinan<br>
                4. Kartu Keluarga lama<br><br>                
                <strong>Format file:</strong> JPG, JPEG, JFIF, JPE, PNG<br>
                <strong>Ukuran maksimal:</strong> 1MB per file<br>
                <strong>Folder penyimpanan:</strong> uploads/akta_kelahiran_ahlak/[nama_folder]
              </p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="ahlak_dashboard_user.php" class="btn-secondary">Kembali ke Dashboard</a>
              <button type="submit" class="btn-primary">Ajukan Permohonan AHLAK</button>
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
  // JavaScript untuk form akta kelahiran AHLAK
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

  // Auto-fill nama desa ketika dipilih
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

  function bindFileInput(input, fileNameSpan, fileSizeSpan, defaultSizeText) {
    if (!input || !fileNameSpan || !fileSizeSpan) return;

    input.addEventListener('change', function() {
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
        fileSizeSpan.innerHTML = defaultSizeText;
      }
    });
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
        <option value="ktp_ortu">KTP Orang Tua</option>
        <option value="lainnya">Dokumen Pendukung Lainnya (Opsional)</option>
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
    
    bindFileInput(
      document.getElementById('fileInputOpsional' + fileCounter.opsional),
      document.getElementById('fileNameOpsional' + fileCounter.opsional),
      document.getElementById('fileSizeOpsional' + fileCounter.opsional),
      'Maksimal 5 MB per file (Opsional, dikompres otomatis)'
    );
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
      bindFileInput(
        document.getElementById('fileInputWajib' + i),
        document.getElementById('fileNameWajib' + i),
        document.getElementById('fileSizeWajib' + i),
        'Maksimal 5 MB per file, dikompres otomatis'
      );
    }

    for (let i = 1; i <= fileCounter.opsional; i++) {
      bindFileInput(
        document.getElementById('fileInputOpsional' + i),
        document.getElementById('fileNameOpsional' + i),
        document.getElementById('fileSizeOpsional' + i),
        'Maksimal 5 MB per file (Opsional, dikompres otomatis)'
      );
    }
  });

  // Validasi form sebelum submit - KHUSUS AHLAK
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
    
    // Validasi desa wajib dipilih
    const desaSelect = document.getElementById('desaSelect');
    if (!desaSelect.value) {
      errorMessages.push('Desa wajib dipilih');
      desaSelect.style.borderColor = '#ef4444';
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
    
    // Cek dokumen wajib KHUSUS AHLAK
    const formulirInput = document.getElementById('fileInputWajib1');
    const suratInput = document.getElementById('fileInputWajib2');
    const bukuNikahInput = document.getElementById('fileInputWajib3');
    const kkLamaInput = document.getElementById('fileInputWajib4');
    
    if (!formulirInput.files.length) {
      errorMessages.push('Formulir F-2.01 Kelahiran wajib diupload');
      hasError = true;
    }
    
    if (!suratInput.files.length) {
      errorMessages.push('Surat Keterangan Lahir dari fasilitas kesehatan/desa/kelurahan wajib diupload');
      hasError = true;
    }
    
    if (!bukuNikahInput.files.length) {
      errorMessages.push('Buku Nikah/Akta Kawin/SPTJM Perkawinan wajib diupload');
      hasError = true;
    }

    if (!kkLamaInput.files.length) {
      errorMessages.push('Kartu Keluarga lama wajib diupload');
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
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan akta kelahiran AHLAK?\n\n' +
                                    'Puskesmas: <?= esc($nama_puskes) ?>\n' +
                                    'Kecamatan: <?= esc($nama_kecamatan_ahlak) ?>\n\n' +
                                    'Pastikan semua dokumen wajib sudah diupload:\n' +
                                    '1. Formulir F-2.01 Kelahiran\n' +
                                    '2. Surat Keterangan Lahir\n' +
                                    '3. Buku Nikah/Akta Kawin\n' +
                                    '4. Kartu Keluarga lama');
      if (!confirmSubmit) {
        e.preventDefault();
      }
    }
  });
  </script>
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<script src="../assets/js/baby-names.js?v=20260909-2"></script>
<script src="../user/js/baby-name-handoff.js?v=20260909-2" data-account="<?= (int)$_SESSION['id_user'] ?>" data-method="<?= ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 'GET' : 'POST' ?>" data-catalog="../assets/data/baby-names.json?v=20260909-2"></script>
</body>
</html>
