<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
require_once __DIR__ . '/../functions/akta_kelahiran_kategori.php';
include '../../app/middleware/petugas_desa_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';

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

// Kategori permohonan menentukan field dan dokumen wajib. Nilai dari URL/POST dinormalisasi;
// kategori 'hilang' ditangani petugas_desa_akta_kelahiran_hilang.php.
$jenis_permohonan = siprima_akta_kelahiran_jenis_normalize(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['jenis'] ?? '') : ($_GET['jenis'] ?? '')
);
if (!in_array($jenis_permohonan, siprima_akta_kelahiran_jenis_form_kelahiran(), true)) {
    $jenis_permohonan = AKTA_KELAHIRAN_JENIS_DEFAULT;
}
$butuh_nik_subjek = $jenis_permohonan !== AKTA_KELAHIRAN_JENIS_DEFAULT;
$is_pembaruan = $jenis_permohonan === 'pembaruan';
$label_nama_subjek = $butuh_nik_subjek ? 'Nama yang Bersangkutan' : 'Nama Bayi';
$label_blok_data = $butuh_nik_subjek ? 'Data yang Bersangkutan' : 'Data Bayi';
// Pembaruan akta tidak lagi memerlukan surat keterangan lahir dari faskes/kantor desa/nahkoda
// karena akta lahir lama sudah menjadi buktinya.
$doc_bukti_lahir = $is_pembaruan ? 'akta_lahir_lama' : 'surat_keterangan_lahir';
$label_bukti_lahir = $is_pembaruan
    ? 'Akta Lahir Lama'
    : 'Surat Keterangan Kelahiran dari Puskes/RS/Desa/Kelurahan';

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

    // data bayi & orangtua
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
    $nama_saksi1 = trim($_POST['nama_saksi1'] ?? '');
    $nik_saksi1 = trim($_POST['nik_saksi1'] ?? '');
    $nama_saksi2 = trim($_POST['nama_saksi2'] ?? '');
    $nik_saksi2 = trim($_POST['nik_saksi2'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');

    // Hanya diisi pada kategori yang membutuhkan; kategori lain tetap NULL di database.
    $nik_subjek = $butuh_nik_subjek ? trim($_POST['nik_subjek'] ?? '') : null;
    $nomor_akta_lama = $is_pembaruan ? trim($_POST['nomor_akta_lama'] ?? '') : null;

    // Validasi sederhana urutan_kelahiran jika diisi
    if ($urutan_kelahiran !== null && $urutan_kelahiran < 1) {
        $errors[] = 'Urutan kelahiran harus berupa angka lebih besar atau sama dengan 1.';
    }

    // basic validation
    if (empty($nama_bayi)) $errors[] = $label_nama_subjek . ' wajib diisi.';
    if ($butuh_nik_subjek) {
        if ($nik_subjek === '') $errors[] = 'NIK yang bersangkutan wajib diisi.';
        elseif (strlen($nik_subjek) !== 16) $errors[] = 'NIK yang bersangkutan harus 16 digit.';
    }
    if ($is_pembaruan && $nomor_akta_lama === '') $errors[] = 'Nomor akta lahir lama wajib diisi.';
    if (empty($tanggal_lahir)) $errors[] = 'Tanggal lahir wajib diisi.';
    if (empty($jenis_kelamin)) $errors[] = 'Jenis kelamin wajib diisi.';
    if (empty($nama_ayah)) $errors[] = 'Nama ayah wajib diisi.';
    if (empty($nik_ayah)) $errors[] = 'NIK ayah wajib diisi.';
    if (empty($nama_ibu)) $errors[] = 'Nama ibu wajib diisi.';
    if (empty($nik_ibu)) $errors[] = 'NIK ibu wajib diisi.';
    if (empty($nama_saksi1)) $errors[] = 'Nama saksi 1 wajib diisi.';
    if (empty($nik_saksi1)) $errors[] = 'NIK saksi 1 wajib diisi.';
    
    // Validasi format NIK
    if (!empty($nik_ayah) && !preg_match('/^[0-9]{16}$/', $nik_ayah)) {
        $errors[] = 'NIK ayah harus 16 digit angka.';
    }
    if (!empty($nik_ibu) && !preg_match('/^[0-9]{16}$/', $nik_ibu)) {
        $errors[] = 'NIK ibu harus 16 digit angka.';
    }
    if (!empty($nik_saksi1) && !preg_match('/^[0-9]{16}$/', $nik_saksi1)) {
        $errors[] = 'NIK saksi 1 harus 16 digit angka.';
    }
    
    
    if ($id_kecamatan <= 0) $errors[] = 'Kecamatan tidak valid.';
    if ($id_desa <= 0) $errors[] = 'Desa tidak valid.';

    // Ambil nama kecamatan/desa dari data user (sudah diambil di atas)
    $nama_kecamatan = $nama_kecamatan_user;
    $nama_desa = $nama_desa_user;

    if (empty($errors)) {
        // Siapkan nilai pemohon dari data user
        $nama_pemohon = $data_user['nama'] ?? '';
        $nik_pemohon = $data_user['nik'] ?? '';
        $no_hp = $data_user['no_hp'] ?? '';
        $email = $data_user['email'] ?? '';
        
        // ================================================
        // BAGIAN BARU: Buat folder penyimpanan dengan format
        // Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama bayi]_[tanggal buat]
        // ================================================
        $timestamp = date('Ymd_His'); // Format: YYYYMMDD_HHMMSS
        
        // Fungsi sanitasi nama untuk folder
        function cleanFolderName($string) {
            $string = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $string); // Hanya izinkan huruf, angka, spasi, dash, underscore
            $string = preg_replace('/\s+/', '_', trim($string)); // Ganti spasi dengan underscore
            return $string;
        }
        
        // Bersihkan setiap komponen nama
        $kecamatan_clean = cleanFolderName($nama_kecamatan);
        $desa_clean = cleanFolderName($nama_desa);
        $pemohon_clean = cleanFolderName($nama_pemohon);
        $bayi_clean = cleanFolderName($nama_bayi);
        
        // Format: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama bayi]_[tanggal buat]
        $folder_name = $kecamatan_clean . '_' . 
                       $desa_clean . '_' . 
                       $nik_pemohon . '_' . 
                       $pemohon_clean . '_' . 
                       $bayi_clean . '_' . 
                       $timestamp;
        
        // Batasi panjang folder name (max 255 karakter)
        if (strlen($folder_name) > 200) {
            // Potong nama bayi jika terlalu panjang
            $prefix_length = strlen($kecamatan_clean) + strlen($desa_clean) + 
                            strlen($nik_pemohon) + strlen($pemohon_clean) + strlen($timestamp) + 5; // 5 untuk underscore
            
            if ($prefix_length < 200) {
                $max_bayi_length = 200 - $prefix_length;
                if ($max_bayi_length > 10) {
                    $bayi_clean = substr($bayi_clean, 0, $max_bayi_length);
                    $folder_name = $kecamatan_clean . '_' . 
                                  $desa_clean . '_' . 
                                  $nik_pemohon . '_' . 
                                  $pemohon_clean . '_' . 
                                  $bayi_clean . '_' . 
                                  $timestamp;
                } else {
                    // Jika masih terlalu panjang, gunakan hash yang lebih pendek
                    $folder_hash = substr(md5($folder_name), 0, 8);
                    $folder_name = $kecamatan_clean . '_' . 
                                  $desa_clean . '_' . 
                                  $nik_pemohon . '_' . 
                                  $pemohon_clean . '_' . 
                                  $folder_hash . '_' . 
                                  $timestamp;
                }
            } else {
                // Jika prefix saja sudah terlalu panjang, gunakan versi sederhana
                $folder_name = 'AKTA_LAHIR_' . $nik_pemohon . '_' . $timestamp;
            }
        }
        
        // Path folder utama uploads untuk akta kelahiran
        $base_upload_dir = __DIR__ . '/uploads/akta_kelahiran/';
        
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
        
        // Tambahkan file .htaccess untuk keamanan
        $htaccessFile = $user_folder_path . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, 
                "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
        }
        // ================================================
        // END BAGIAN BARU: Buat folder penyimpanan
        // ================================================

        // File upload - MULTIPLE FILES, maksimal 1MB SETELAH kompresi otomatis di sisi klien
        $uploadedFiles = [];
        $maxFileSize = 1 * 1024 * 1024; // 1MB
        
        // Dokumen wajib berdasarkan persyaratan
        $requiredDocs = ['formulir_kelahiran', $doc_bukti_lahir, 'buku_nikah']; // Wajib
        $uploadedDocTypes = [];
        
        if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
            $fileCount = count($_FILES['dokumen']['name']);
            
            // Dapatkan array doc_type dan file_index dari POST untuk mapping yang benar
            $doc_types = isset($_POST['doc_type']) && is_array($_POST['doc_type']) ? $_POST['doc_type'] : [];
            $file_indexes = isset($_POST['file_index']) && is_array($_POST['file_index']) ? $_POST['file_index'] : [];
            
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
                    // Gunakan file_index yang sesuai untuk mendapatkan doc_type yang benar
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
                    $timestamp_file = time() . '_' . $i;
                    $randomString = bin2hex(random_bytes(6));
                    $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                    $newName = $clean_doc_type . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                    $destination = $user_folder_path . $newName;

                    if (move_uploaded_file($fileTmp, $destination)) {
                        // Simpan path dengan prefix yang sesuai
                        $uploadedFiles[] = [
                            'path' => 'uploads/akta_kelahiran/' . $folder_name . '/' . $newName,
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
                            case 'formulir_kelahiran': $docName = 'Formulir F-2.01 Kelahiran'; break;
                            case 'surat_keterangan_lahir': $docName = 'Surat Keterangan Kelahiran dari Puskes/RS/Desa/Kelurahan'; break;
                            case 'akta_lahir_lama': $docName = 'Akta Lahir Lama'; break;
                            case 'buku_nikah': $docName = 'Buku Nikah/Akta kawin/SPTJM Perkawinan'; break;
                        }
                        if ($docName) {
                            $errors[] = "{$docName} wajib diupload";
                        }
                    }
                }
            }
        }

        // Cek dokumen wajib - semua harus ada
        $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
        if (!empty($missingRequired)) {
            $missingNames = [];
            foreach ($missingRequired as $doc) {
                switch ($doc) {
                    case 'formulir_kelahiran':
                        $missingNames[] = 'Formulir F-2.01 Kelahiran';
                        break;
                    case 'surat_keterangan_lahir':
                        $missingNames[] = 'Surat Keterangan Kelahiran dari Puskes/RS/Desa/Kelurahan';
                        break;
                    case 'akta_lahir_lama':
                        $missingNames[] = 'Akta Lahir Lama';
                        break;
                    case 'buku_nikah':
                        $missingNames[] = 'Buku Nikah/Akta kawin/SPTJM Perkawinan';
                        break;
                }
            }
            $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
        }

        $hasKkOrSuketKk = in_array('kk', $uploadedDocTypes, true) || in_array('suket_kehilangan_kk', $uploadedDocTypes, true);
        if (!$hasKkOrSuketKk) {
            $errors[] = 'Dokumen wajib belum diupload: Kartu Keluarga (KK) atau Suket Kehilangan KK';
        }

        if (empty($errors)) {
            // Normalisasi field Waktu ke format HH:MM:SS
            if (!empty($waktu) && preg_match('/^\d{2}:\d{2}$/', $waktu)) {
                $waktu .= ':00';
            }

            // Convert uploaded files to JSON string
            $dokumenJson = json_encode($uploadedFiles);

            // Simpan status dalam variabel terpisah
            $status = 'diajukan';
            
            // Query untuk insert data akta kelahiran dengan tambahan nama_folder dan data saksi
            $stmt = $koneksi->prepare("INSERT INTO tb_akta_kelahiran 
                (id_user, id_kecamatan, id_desa, nama_pemohon, nik_pemohon, no_hp, email, 
                 nama_kecamatan, nama_desa, nama_ayah, nik_ayah, nama_ibu, nik_ibu, 
                 nama_saksi1, nik_saksi1, nama_saksi2, nik_saksi2,
                 nama_bayi, tempat_lahir, jenis_kelamin, tanggal_lahir, urutan_kelahiran, 
                 Waktu, dokumen, keterangan, status, nama_folder,
                 jenis_permohonan, nik_subjek, nomor_akta_lama, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

            if (!$stmt) {
                $errors[] = 'Prepare statement error: ' . $koneksi->error;
            } else {
                $stmt->bind_param("iiissssssssssssssssssissssssss",
                    $user_id,           // i (integer)
                    $id_kecamatan,      // i (integer)
                    $id_desa,           // i (integer)
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
                    $keterangan,        // s (string)
                    $status,            // s (string)
                    $folder_name,       // s (string) - nama folder baru
                    $jenis_permohonan,  // s (string) - kategori permohonan
                    $nik_subjek,        // s (string|null) - NIK orang yang aktanya diajukan
                    $nomor_akta_lama    // s (string|null) - nomor akta lama pada pembaruan akta
                );
                
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = 'Permohonan Akta Kelahiran berhasil diajukan.';
                    header('Location: /user/petugas_desa_status_permohonan.php');
                    exit;
                } else {
                    $errors[] = 'Database error: ' . $stmt->error;
                    
                    // Jika gagal insert, hapus folder yang sudah dibuat (opsional)
                    if (is_dir($user_folder_path)) {
                        // Hapus semua file dalam folder
                        array_map('unlink', glob($user_folder_path . '/*'));
                        // Hapus folder .htaccess
                        if (file_exists($htaccessFile)) {
                            unlink($htaccessFile);
                        }
                        // Hapus folder
                        rmdir($user_folder_path);
                    }
                }
                $stmt->close();
            }
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
  <title>Ajukan Akta Kelahiran (Petugas Desa)</title>
  <link rel="stylesheet" href="css/petugas_desa_akta_kelahiran.css">
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
          'Ajukan Akta Kelahiran',
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
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                <line x1="9" y1="9" x2="9.01" y2="9"></line>
                <line x1="15" y1="9" x2="15.01" y2="9"></line>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Form Pengajuan Akta Kelahiran</h2>
              <p class="helper">Sebagai petugas desa, Anda hanya dapat mengajukan permohonan akta kelahiran untuk wilayah Anda.</p>
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
            <p><span class="info-icon">🔒</span> Anda sebagai <strong>Petugas Desa</strong> hanya dapat mengajukan permohonan akta kelahiran untuk wilayah:</p>
            <p><strong>Kecamatan:</strong> <?= esc($nama_kecamatan_user) ?> | <strong>Desa:</strong> <?= esc($nama_desa_user) ?></p>
          </div>

          <form method="POST" action="/user/petugas_desa_akta_kelahiran.php" enctype="multipart/form-data" class="space-y-4" id="aktaForm">
<?php echo csrf_input(); ?>
            <input type="hidden" name="jenis" value="<?= esc($jenis_permohonan) ?>">

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

            <!-- Data Bayi -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;"><?= esc($label_blok_data) ?></h3>

            <?php if ($butuh_nik_subjek): ?>
            <div class="form-grid-2">
              <div>
                <label class="field-label">NIK yang Bersangkutan <span style="color:red">*</span></label>
                <input type="text" name="nik_subjek" value="<?= esc($_POST['nik_subjek'] ?? '') ?>"
                       required class="input" placeholder="16 digit NIK" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                <p class="helper">NIK yang sudah tercatat di Kartu Keluarga.</p>
              </div>
              <?php if ($is_pembaruan): ?>
              <div>
                <label class="field-label">Nomor Akta Lahir Lama <span style="color:red">*</span></label>
                <input type="text" name="nomor_akta_lama" value="<?= esc($_POST['nomor_akta_lama'] ?? '') ?>"
                       required class="input" placeholder="Nomor pada akta lahir lama" maxlength="100">
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="form-grid-2">
              <div>
                <label class="field-label"><?= esc($label_nama_subjek) ?> <span style="color:red">*</span></label>
                <input type="text" name="nama_bayi" value="<?= esc($_POST['nama_bayi'] ?? '') ?>" 
                       required class="input" 
                       placeholder="<?= $butuh_nik_subjek ? 'Nama lengkap sesuai dokumen' : 'Nama lengkap bayi' ?>">
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
                <label class="field-label">Tempat Lahir</label>
                <input type="text" name="tempat_lahir" value="<?= esc($_POST['tempat_lahir'] ?? '') ?>" 
                       class="input" 
                       placeholder="Kabupaten/Kota lahir bayi">
              </div>
              <div>
                <label class="field-label">Tanggal Lahir <span style="color:red">*</span></label>
                <input type="date" name="tanggal_lahir" value="<?= esc($_POST['tanggal_lahir'] ?? '') ?>" 
                       required class="input">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Waktu Lahir</label>
                <input type="time" name="waktu" value="<?= esc($_POST['waktu'] ?? '') ?>" 
                       class="input">
                <p class="helper">Format: HH:MM</p>
              </div>
              <div>
                <label class="field-label">Anak ke-</label>
                <input type="number" name="urutan_kelahiran" min="1" 
                       value="<?= esc($_POST['urutan_kelahiran'] ?? '') ?>" 
                       class="input" 
                       placeholder="Contoh: 1">
              </div>
            </div>

            <!-- Data Orang Tua - WAJIB DIISI -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Orang Tua <span style="color:red">*</span></h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Ayah <span style="color:red">*</span></label>
                <input type="text" name="nama_ayah" value="<?= esc($_POST['nama_ayah'] ?? '') ?>" 
                       required class="input" 
                       placeholder="Nama lengkap ayah">
              </div>
              <div>
                <label class="field-label">NIK Ayah <span style="color:red">*</span></label>
                <input type="text" name="nik_ayah" value="<?= esc($_POST['nik_ayah'] ?? '') ?>" 
                       required class="input" 
                       placeholder="16 digit NIK ayah" 
                       pattern="[0-9]{16}" maxlength="16"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                       title="NIK harus 16 digit angka">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Ibu <span style="color:red">*</span></label>
                <input type="text" name="nama_ibu" value="<?= esc($_POST['nama_ibu'] ?? '') ?>" 
                       required class="input" 
                       placeholder="Nama lengkap ibu">
              </div>
              <div>
                <label class="field-label">NIK Ibu <span style="color:red">*</span></label>
                <input type="text" name="nik_ibu" value="<?= esc($_POST['nik_ibu'] ?? '') ?>" 
                       required class="input" 
                       placeholder="16 digit NIK ibu" 
                       pattern="[0-9]{16}" maxlength="16"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                       title="NIK harus 16 digit angka">
              </div>
            </div>

            <!-- Data Saksi 1 - WAJIB DIISI -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Saksi 1 <span style="color:red">*</span></h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Saksi 1 <span style="color:red">*</span></label>
                <input type="text" name="nama_saksi1" value="<?= esc($_POST['nama_saksi1'] ?? '') ?>" 
                       required class="input" 
                       placeholder="Nama lengkap saksi 1">
              </div>
              <div>
                <label class="field-label">NIK Saksi 1 <span style="color:red">*</span></label>
                <input type="text" name="nik_saksi1" value="<?= esc($_POST['nik_saksi1'] ?? '') ?>" 
                       required class="input" 
                       placeholder="16 digit NIK saksi 1" 
                       pattern="[0-9]{16}" maxlength="16"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                       title="NIK harus 16 digit angka">
              </div>
            </div>

            <!-- Data Saksi 2 - WAJIB DIISI -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Saksi 2</h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Saksi 2</label>
                <input type="text" name="nama_saksi2" value="<?= esc($_POST['nama_saksi2'] ?? '') ?>" 
                        class="input" 
                       placeholder="Nama lengkap saksi 2">
              </div>
              <div>
                <label class="field-label">NIK Saksi 2</label>
                <input type="text" name="nik_saksi2" value="<?= esc($_POST['nik_saksi2'] ?? '') ?>" 
                        class="input" 
                       placeholder="16 digit NIK saksi 2" 
                       pattern="[0-9]{16}" maxlength="16"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"
                       title="NIK harus 16 digit angka">
              </div>
            </div>

            <!-- Keterangan / Pesan -->
            <div>
              <label class="field-label">Keterangan / Pesan untuk Operator</label>
              <textarea name="keterangan" class="input" rows="3" 
                        required placeholder="Contoh: Permohonan akta kelahiran pertama" maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
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
                  <!-- Formulir F-2.01 Kelahiran (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib1">
                    <input type="hidden" name="file_index[]" value="0">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="formulir_kelahiran" selected>Formulir F-2.01 Kelahiran</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Pilih File</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Unduh Formulir F-2.01 Kelahiran
                      <a href="https://siprima.gorontalokab.go.id/uploads/formulir/1766758549_694e9895d9914.pdf" target="_blank" rel="noopener noreferrer">klik disini</a>
                    </p>
                  </div>
                  
                  <!-- Surat Keterangan Kelahiran (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib2">
                    <input type="hidden" name="file_index[]" value="1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="<?= esc($doc_bukti_lahir) ?>" selected><?= esc($label_bukti_lahir) ?></option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Pilih File</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Surat keterangan kelahiran dari Puskesmas, Rumah Sakit, atau Desa/Kelurahan
                    </p>
                  </div>
                  
                  <!-- Buku Nikah/Akta kawin/SPTJM Perkawinan (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib3">
                    <input type="hidden" name="file_index[]" value="2">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="buku_nikah" selected>Buku Nikah/Akta kawin/SPTJM Perkawinan</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib3">Pilih File</label>
                      <span id="fileNameWajib3" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib3" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib3">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Buku nikah, akta kawin, atau SPTJM perkawinan
                    </p>
                  </div>

                  <!-- KK atau Suket Kehilangan KK (Wajib) -->
                  <div class="file-input-group" id="fileGroupWajib4">
                    <input type="hidden" name="file_index[]" value="3">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="">Pilih Jenis Dokumen</option>
                      <option value="kk" selected>Kartu Keluarga (KK)</option>
                      <option value="suket_kehilangan_kk">Suket Kehilangan KK</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib4">Pilih File</label>
                      <span id="fileNameWajib4" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib4" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib4">Maksimal 5 MB per file, dikompres otomatis</div>
                    <p class="helper" style="margin-top: 5px; font-size: 13px;">
                      <i class="fas fa-info-circle"></i> Unggah Kartu Keluarga (KK) atau Suket Kehilangan KK
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
                    <input type="hidden" name="file_index[]" value="4">
                    <select name="doc_type[]" class="file-type-select">
                      <option value="">Pilih Jenis Dokumen (Opsional)</option>
                      <option value="ktp_ortu">KTP Orang Tua</option>
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
              <a href="/user/petugas_desa_akta_kelahiran_pilih_jenis.php" class="btn-secondary">Kembali</a>
              <button type="submit" class="btn-primary">Ajukan Permohonan Akta Kelahiran</button>
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
      logoutModal.classList.add('show');
      document.body.style.overflow = 'hidden';
    }
    
    function hideLogoutModal() {
      logoutModal.classList.remove('show');
      document.body.style.overflow = '';
    }
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '/logout.php');
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

    // KECAMATAN DAN DESA SUDAH DIKUNCI - TIDAK PERLU AJAX
    // Set nilai awal untuk nama kecamatan/desa
    document.getElementById("namaKecamatan").value = "<?= addslashes($nama_kecamatan_user) ?>";
    document.getElementById("namaDesa").value = "<?= addslashes($nama_desa_user) ?>";
  });

  // Multiple file upload functionality
  let fileCounter = {
    wajib: 4,
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
        <option value="ktp_ortu">KTP Orang Tua</option>
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
            newFileSizeSpan.innerHTML = `<span style="color: red;"> Ukuran file: ${formatFileSize(file.size)} (melebihi batas ${formatFileSize(EFFECTIVE_MAX_FILE_SIZE)})</span>`;
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
  document.getElementById('aktaForm').addEventListener('submit', function(e) {
    const allFileInputs = document.querySelectorAll('input[type="file"]');
    let hasError = false;
    let errorMessages = [];
    let totalUploadSize = 0;

    const csrfInput = this.querySelector('input[name="csrf_token"]');
    if (!csrfInput || !csrfInput.value.trim()) {
      errorMessages.push('Token keamanan tidak ditemukan. Muat ulang halaman lalu coba lagi.');
      hasError = true;
    }
    
    // NIK yang bersangkutan hanya ada pada kategori selain bayi baru lahir.
    const nikSubjekInput = document.querySelector('input[name="nik_subjek"]');
    if (nikSubjekInput && !/^\d{16}$/.test(nikSubjekInput.value)) {
      errorMessages.push('NIK yang bersangkutan harus 16 digit angka');
      hasError = true;
      nikSubjekInput.style.borderColor = '#ef4444';
    } else if (nikSubjekInput) {
      nikSubjekInput.style.borderColor = '#d1d5db';
    }

    // Validasi NIK ayah harus 16 digit
    const nikAyahInput = document.querySelector('input[name="nik_ayah"]');
    if (nikAyahInput && (!nikAyahInput.value || !/^\d{16}$/.test(nikAyahInput.value))) {
      errorMessages.push('NIK ayah harus 16 digit angka');
      hasError = true;
      nikAyahInput.style.borderColor = '#ef4444';
    } else if (nikAyahInput) {
      nikAyahInput.style.borderColor = '#d1d5db';
    }
    
    // Validasi NIK ibu harus 16 digit
    const nikIbuInput = document.querySelector('input[name="nik_ibu"]');
    if (nikIbuInput && (!nikIbuInput.value || !/^\d{16}$/.test(nikIbuInput.value))) {
      errorMessages.push('NIK ibu harus 16 digit angka');
      hasError = true;
      nikIbuInput.style.borderColor = '#ef4444';
    } else if (nikIbuInput) {
      nikIbuInput.style.borderColor = '#d1d5db';
    }
    
    // Validasi NIK saksi 1 harus 16 digit
    const nikSaksi1Input = document.querySelector('input[name="nik_saksi1"]');
    if (nikSaksi1Input && (!nikSaksi1Input.value || !/^\d{16}$/.test(nikSaksi1Input.value))) {
      errorMessages.push('NIK saksi 1 harus 16 digit angka');
      hasError = true;
      nikSaksi1Input.style.borderColor = '#ef4444';
    } else if (nikSaksi1Input) {
      nikSaksi1Input.style.borderColor = '#d1d5db';
    }
    
    
    // Validasi tanggal lahir tidak boleh di masa depan
    const tanggalLahirInput = document.querySelector('input[name="tanggal_lahir"]');
    if (tanggalLahirInput && tanggalLahirInput.value) {
      const today = new Date();
      const birthDate = new Date(tanggalLahirInput.value);
      if (birthDate > today) {
        errorMessages.push('Tanggal lahir tidak boleh di masa depan');
        hasError = true;
        tanggalLahirInput.style.borderColor = '#ef4444';
      } else {
        tanggalLahirInput.style.borderColor = '#d1d5db';
      }
    }
    
    // Validasi urutan kelahiran jika diisi harus > 0
    const urutanKelahiranInput = document.querySelector('input[name="urutan_kelahiran"]');
    if (urutanKelahiranInput && urutanKelahiranInput.value && parseInt(urutanKelahiranInput.value) < 1) {
      errorMessages.push('Urutan kelahiran harus lebih besar dari 0');
      hasError = true;
      urutanKelahiranInput.style.borderColor = '#ef4444';
    } else if (urutanKelahiranInput) {
      urutanKelahiranInput.style.borderColor = '#d1d5db';
    }
    
    // Validasi ukuran file
    allFileInputs.forEach((input) => {
      if (input.files.length > 0) {
        if (input.dataset.fileStable === '0') {
          // Fallback mode: jangan blok submit, karena beberapa browser mobile
          // gagal clone file meski file lokal tetap valid untuk upload normal.
          console.warn(`File "${input.files[0].name}" tidak bisa distabilkan, lanjut upload mode normal.`);
        }
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
    const bukuNikahInput = document.getElementById('fileInputWajib3');
    const kkSuketInput = document.getElementById('fileInputWajib4');
    
    if (!formulirInput.files.length) {
      errorMessages.push('Formulir F-2.01 Kelahiran wajib diupload');
      hasError = true;
      formulirInput.style.borderColor = '#ef4444';
    } else {
      formulirInput.style.borderColor = '#d1d5db';
    }
    
    if (!suratInput.files.length) {
      errorMessages.push(<?= json_encode($label_bukti_lahir . ' wajib diupload', JSON_UNESCAPED_UNICODE) ?>);
      hasError = true;
      suratInput.style.borderColor = '#ef4444';
    } else {
      suratInput.style.borderColor = '#d1d5db';
    }
    
    if (!bukuNikahInput.files.length) {
      errorMessages.push('Buku Nikah/Akta kawin/SPTJM Perkawinan wajib diupload');
      hasError = true;
      bukuNikahInput.style.borderColor = '#ef4444';
    } else {
      bukuNikahInput.style.borderColor = '#d1d5db';
    }

    if (!kkSuketInput.files.length) {
      errorMessages.push('Kartu Keluarga (KK) atau Suket Kehilangan KK wajib diupload');
      hasError = true;
      kkSuketInput.style.borderColor = '#ef4444';
    } else {
      kkSuketInput.style.borderColor = '#d1d5db';
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
        message += ' Harap lengkapi semua field yang wajib diisi';
      }
      alert(message);
    } else {
      const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan akta kelahiran?\n\nPastikan Anda telah mengupload:\n1. Formulir F-2.01 Kelahiran\n2. <?= esc($label_bukti_lahir) ?>\n3. Buku Nikah/Akta kawin/SPTJM Perkawinan\n4. Kartu Keluarga (KK) atau Suket Kehilangan KK.');
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
<script src="../assets/js/baby-names.js?v=20260909-2"></script>
<script src="js/baby-name-handoff.js?v=20260909-2" data-account="<?= (int)$_SESSION['id_user'] ?>" data-method="<?= ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 'GET' : 'POST' ?>" data-catalog="../assets/data/baby-names.json?v=20260909-2"></script>
</body>
</html>
