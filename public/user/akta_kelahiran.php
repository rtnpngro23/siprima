<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
require_once __DIR__ . '/../functions/formulir_f201.php';
require_once __DIR__ . '/../functions/akta_kelahiran_kategori.php';
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

// Kategori permohonan menentukan field dan dokumen wajib. Nilai dari URL/POST dinormalisasi;
// kategori 'hilang' ditangani akta_kelahiran_hilang.php karena tidak mencatat peristiwa kelahiran.
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
$doc_bukti_lahir = $is_pembaruan ? 'akta_lama' : 'surat_keterangan';
$label_bukti_lahir = $is_pembaruan ? 'Akta Lahir Lama' : 'Surat Keterangan Lahir';

// Saat formulir dibuat sistem tidak ada lagi kertas yang dipegang; selfie beralih ke KTP/KK
// dan tetap wajib sebagai bukti bahwa pemohon adalah orang yang bersangkutan.
$labelSelfie = FORMULIR_F201_AUTO_ENABLED
    ? 'Foto Selfie Pemohon Memegang KTP/Kartu Keluarga'
    : 'Foto Selfie Pemohon Memegang Formulir';
$slugSelfie = FORMULIR_F201_AUTO_ENABLED
    ? 'foto_selfie_pemohon_memegang_ktp_kk'
    : 'foto_selfie_pemohon_memegang_formulir';

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

    // Hanya diisi pada kategori yang membutuhkan; kategori lain tetap NULL di database.
    $nik_subjek = $butuh_nik_subjek ? trim($_POST['nik_subjek'] ?? '') : null;
    $nomor_akta_lama = $is_pembaruan ? trim($_POST['nomor_akta_lama'] ?? '') : null;

    // Basic validation
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
    if (strlen($nik_ayah) !== 16) $errors[] = 'NIK ayah harus 16 digit.';
    if (empty($nama_ibu)) $errors[] = 'Nama ibu wajib diisi.';
    if (empty($nik_ibu)) $errors[] = 'NIK ibu wajib diisi.';
    if (strlen($nik_ibu) !== 16) $errors[] = 'NIK ibu harus 16 digit.';
    if (empty($nama_saksi1)) $errors[] = 'Nama saksi 1 wajib diisi.';
    if (empty($nik_saksi1)) $errors[] = 'NIK saksi 1 wajib diisi.';
    if (strlen($nik_saksi1) !== 16) $errors[] = 'NIK saksi 1 harus 16 digit.';
    if ($id_kecamatan <= 0) $errors[] = 'Kecamatan wajib dipilih.';
    if ($id_desa <= 0) $errors[] = 'Desa wajib dipilih.';

    // Formulir F-2.01 dibuat sistem: pernyataan, isian tambahan, dan tanda tangan wajib.
    // Pemeriksaan murah saja di sini; dekode gambar dan render dikerjakan setelah upload selesai.
    $f201_fields = null;
    $f201_signature = null;
    $formulir_dibuat_at = null;
    if (FORMULIR_F201_AUTO_ENABLED) {
        if (empty($_POST['pernyataan_benar'])) {
            $errors[] = 'Centang pernyataan kebenaran data sebelum mengirim permohonan.';
        }
        $errors = array_merge($errors, siprima_f201_validate_fields($_POST, $f201_fields));
        $pesan_ttd = siprima_formulir_validate_signature((string)($_POST['ttd_pemohon'] ?? ''), $f201_signature);
        if ($pesan_ttd !== '') $errors[] = $pesan_ttd;
    }
    $jenis_kelahiran = $f201_fields['jenis_kelahiran'] ?? null;
    $penolong_kelahiran = $f201_fields['penolong_kelahiran'] ?? null;
    $berat_bayi_gram = $f201_fields['berat_bayi_gram'] ?? null;
    $panjang_bayi_mm = $f201_fields['panjang_bayi_mm'] ?? null;

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

    // ============================================================
    // BAGIAN PENTING: MEMBUAT NAMA FOLDER SESUAI FORMAT
    // ============================================================
    
    // Siapkan data untuk folder name
    $nama_pemohon = $data_user['nama'] ?? '';
    $nik_pemohon = $data_user['nik'] ?? '';
    $tanggal_buat = date('Ymd_His'); // Format: YYYYMMDD_HHMMSS
    
    // Fungsi sanitasi nama untuk folder
    function cleanFolderName($string) {
        $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', $string);
        $string = preg_replace('/\s+/', '_', trim($string));
        $string = strtolower($string);
        return $string;
    }
    
    // Bersihkan setiap komponen nama
    $kecamatan_clean = cleanFolderName($nama_kecamatan);
    $desa_clean = cleanFolderName($nama_desa);
    $nik_clean = preg_replace('/[^0-9]/', '', $nik_pemohon);
    $pemohon_clean = cleanFolderName($nama_pemohon);
    $bayi_clean = cleanFolderName($nama_bayi);
    
    // Buat nama folder: [kecamatan]_[desa]_[nik pemohon]_[nama pemohon]_[nama bayi]_[tanggal]
    $folder_name = 
        $kecamatan_clean . '_' . 
        $desa_clean . '_' . 
        $nik_clean . '_' . 
        $pemohon_clean . '_' . 
        $bayi_clean . '_' . 
        $tanggal_buat;
    
    // Batasi panjang folder name
    if (strlen($folder_name) > 200) {
        $max_bayi_length = 200 - (strlen($kecamatan_clean) + strlen($desa_clean) + 
                                 strlen($nik_clean) + strlen($pemohon_clean) + 
                                 strlen($tanggal_buat) + 5);
        
        if ($max_bayi_length > 10) {
            $bayi_clean = substr($bayi_clean, 0, $max_bayi_length);
            $folder_name = 
                $kecamatan_clean . '_' . 
                $desa_clean . '_' . 
                $nik_clean . '_' . 
                $pemohon_clean . '_' . 
                $bayi_clean . '_' . 
                $tanggal_buat;
        } else {
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
    
    // Path folder utama uploads
    $base_upload_dir = __DIR__ . '/uploads/akta_kelahiran/';
    
    // Buat folder utama jika belum ada
    if (!is_dir($base_upload_dir)) {
        mkdir($base_upload_dir, 0755, true);
    }
    
    // Path folder khusus untuk permohonan ini
    $permohonan_dir = $base_upload_dir . $folder_name . '/';
    
    // Buat folder khusus untuk permohonan
    if (!is_dir($permohonan_dir)) {
        if (!mkdir($permohonan_dir, 0755, true)) {
            $errors[] = 'Gagal membuat folder penyimpanan dokumen: ' . $permohonan_dir;
        }
    }

    // File upload - MULTIPLE FILES, maksimal 1MB SETELAH kompresi otomatis di sisi klien
    $uploadedFiles = [];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Dokumen wajib
    // Saat formulir dibuat sistem, pemohon tidak lagi mengunggah blanko F-2.01.
    $requiredDocs = FORMULIR_F201_AUTO_ENABLED
        ? [$doc_bukti_lahir, 'buku_nikah', 'foto_selfie']
        : ['formulir', $doc_bukti_lahir, 'buku_nikah', 'foto_selfie'];
    $uploadedDocTypes = [];
    
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name']) && empty($errors)) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        // Buat subfolder untuk dokumen pendukung
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

                // doc_type berasal dari klien. Saat formulir dibuat sistem, unggahan bertipe
                // 'formulir' harus ditolak tegas agar tidak ada dua dokumen yang tampak resmi
                // dan operator tetap tahu mana yang tanda tangannya wajib diverifikasi.
                if (FORMULIR_F201_AUTO_ENABLED && $docType === 'formulir') {
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

                // Generate nama file yang lebih deskriptif
                $docTypeName = '';
                switch ($docType) {
                    case 'formulir':
                        $docTypeName = 'formulir_f201_kelahiran';
                        break;
                    case 'surat_keterangan':
                        $docTypeName = 'surat_keterangan_lahir';
                        break;
                    case 'akta_lama':
                        $docTypeName = 'akta_lahir_lama';
                        break;
                    case 'buku_nikah':
                        $docTypeName = 'buku_nikah_akta_kawin';
                        break;
                    case 'kk':
                        $docTypeName = 'kartu_keluarga';
                        break;
                    case 'suket_kehilangan_kk':
                        $docTypeName = 'suket_kehilangan_kk';
                        break;
                    case 'ktp_ortu':
                        $docTypeName = 'ktp_orang_tua';
                        break;
                    case 'foto_selfie':
                        $docTypeName = $slugSelfie;
                        break;
                    default:
                        $docTypeName = 'dokumen_lainnya';
                }
                
                $timestamp_file = time();
                $randomString = bin2hex(random_bytes(4));
                $newName = $docTypeName . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                
                // Tentukan lokasi penyimpanan
                if ($docType === 'lainnya') {
                    $destination = $pendukung_dir . $newName;
                    $savePath = 'uploads/akta_kelahiran/' . $folder_name . '/dokumen_pendukung/' . $newName;
                } else {
                    $destination = $permohonan_dir . $newName;
                    $savePath = 'uploads/akta_kelahiran/' . $folder_name . '/' . $newName;
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
                $fileName = basename($_FILES['dokumen']['name'][$i] ?? 'dokumen');
                $errorMsg = "File {$fileName}: " . siprima_upload_error_message($_FILES['dokumen']['error'][$i]);
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
                "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
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
                    $missingNames[] = 'Surat Keterangan Lahir';
                    break;
                case 'akta_lama':
                    $missingNames[] = 'Akta Lahir Lama';
                    break;
                case 'buku_nikah':
                    $missingNames[] = 'Buku Nikah/Akta Kawin/SPTJM Perkawinan';
                    break;
                case 'foto_selfie':
                    $missingNames[] = $labelSelfie;
                    break;
            }
        }
        $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
    }

    $hasKkOrSuketKk = in_array('kk', $uploadedDocTypes, true) || in_array('suket_kehilangan_kk', $uploadedDocTypes, true);
    if (!$hasKkOrSuketKk) {
        $errors[] = 'Dokumen wajib belum diupload: Kartu Keluarga (KK) atau Suket Kehilangan KK';
    }

    // Formulir dibuat setelah seluruh unggahan final, tetapi sebelum INSERT: berkas ditulis ke
    // $permohonan_dir sehingga ikut terhapus oleh pembersihan atomik bila penyimpanan data gagal.
    if (FORMULIR_F201_AUTO_ENABLED && empty($errors)) {
        try {
            $formulir_dibuat_at = date('Y-m-d H:i:s');
            $formulirJpeg = siprima_f201_render(
                siprima_f201_data_akta_kelahiran(
                    $_POST,
                    (array)$f201_fields,
                    $data_user,
                    $nama_kecamatan,
                    $nama_desa,
                    $formulir_dibuat_at
                ),
                (string)$f201_signature
            );

            $formulirName = 'formulir_f201_kelahiran_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
            if (file_put_contents($permohonan_dir . $formulirName, $formulirJpeg) === false) {
                throw new RuntimeException('Formulir gagal ditulis ke folder permohonan.');
            }

            // Formulir selalu dokumen pertama, sama seperti sebelum fitur ini ada.
            array_unshift($uploadedFiles, [
                'path' => 'uploads/akta_kelahiran/' . $folder_name . '/' . $formulirName,
                'type' => 'formulir',
                'original_name' => 'Formulir F-2.01 Kelahiran (dibuat sistem)',
                'uploaded_name' => $formulirName,
                'size' => strlen($formulirJpeg),
                'uploaded_at' => $formulir_dibuat_at,
                'generated' => true,
            ]);
        } catch (Throwable $e) {
            error_log('Formulir F-2.01 render error: ' . $e->getMessage());
            $errors[] = 'Formulir belum dapat dibuat. Silakan ulangi tanda tangan Anda lalu coba lagi.';
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
        
        // Simpan status dalam variabel terpisah
        $status = 'diajukan';
        $alasan = ''; // Dikosongkan untuk operator
        
        // Query untuk insert data akta kelahiran
        $stmt = $koneksi->prepare("INSERT INTO tb_akta_kelahiran 
            (id_user, id_kecamatan, id_desa, 
             nama_pemohon, nik_pemohon, no_hp, email, 
             nama_kecamatan, nama_desa, 
             nama_ayah, nik_ayah, nama_ibu, nik_ibu,
             nama_saksi1, nik_saksi1, nama_saksi2, nik_saksi2,
             nama_bayi, tempat_lahir, jenis_kelamin, tanggal_lahir, 
             urutan_kelahiran, Waktu, dokumen, status, alasan,
             keterangan, nama_folder,
             jenis_kelahiran, penolong_kelahiran, berat_bayi_gram, panjang_bayi_mm, formulir_dibuat_at,
             jenis_permohonan, nik_subjek, nomor_akta_lama,
             created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        
        if (!$stmt) {
            error_log('Akta kelahiran insert prepare error: ' . $koneksi->error);
            $errors[] = 'Terjadi gangguan sistem saat memproses permohonan. Silakan coba lagi.';
        } else {
            $stmt->bind_param(
                "iiissssssssssssssssssissssssiiiissss", // 36 parameter, urut sama dengan daftar kolom di atas
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
                $status,            // s (string)
                $alasan,            // s (string)
                $keterangan,        // s (string) - max 100 karakter
                $folder_name,       // s (string) - nama folder penyimpanan
                $jenis_kelahiran,   // i (integer|null) - 1..5
                $penolong_kelahiran,// i (integer|null) - 1..4
                $berat_bayi_gram,   // i (integer|null) - gram
                $panjang_bayi_mm,   // i (integer|null) - milimeter
                $formulir_dibuat_at,// s (string|null) - saat pemohon menyetujui & menandatangani
                $jenis_permohonan,  // s (string) - kategori permohonan
                $nik_subjek,        // s (string|null) - NIK orang yang aktanya diajukan
                $nomor_akta_lama    // s (string|null) - nomor akta lama pada pembaruan akta
            );
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Permohonan Akta Kelahiran berhasil diajukan.';
                header('Location: ajukan_permohonan.php');
                exit;
            } else {
                error_log('Akta kelahiran insert execute error: ' . $stmt->error);
                $errors[] = 'Data belum dapat disimpan. Periksa kembali isian Anda lalu coba lagi.';
                // Hapus folder yang sudah dibuat jika insert gagal
                if (is_dir($permohonan_dir)) {
                    array_map('unlink', glob($permohonan_dir . '/*'));
                    if (is_dir($pendukung_dir)) {
                        array_map('unlink', glob($pendukung_dir . '/*'));
                        rmdir($pendukung_dir);
                    }
                    rmdir($permohonan_dir);
                }
            }
            $stmt->close();
        }
    }
    
    // Pengajuan bersifat atomik: bersihkan semua file jika salah satu validasi/insert gagal.
    if (!empty($errors) && isset($permohonan_dir) && is_dir($permohonan_dir)) {
        siprima_remove_upload_tree($permohonan_dir, $base_upload_dir);
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

function uploadErrorMessage($errorCode) {
    switch ((int)$errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Ukuran file melebihi batas upload server/form.';
        case UPLOAD_ERR_PARTIAL:
            return 'File hanya terupload sebagian. Silakan coba lagi.';
        case UPLOAD_ERR_NO_FILE:
            return 'Tidak ada file yang dipilih.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Folder temporary upload tidak tersedia di server.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server gagal menulis file ke disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload dihentikan oleh ekstensi PHP di server.';
        default:
            return 'Terjadi kesalahan upload yang tidak diketahui.';
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
  <title>Ajukan Akta Kelahiran</title>
  <link rel="stylesheet" href="css/akta_kelahiran.css">
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
        render_user_header_brand('Ajukan Akta Kelahiran');
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
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                <line x1="9" y1="9" x2="9.01" y2="9"></line>
                <line x1="15" y1="9" x2="15.01" y2="9"></line>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Form Pengajuan Akta Kelahiran</h2>
              <p class="helper">
                Kategori: <strong><?= esc(siprima_akta_kelahiran_jenis_label($jenis_permohonan)) ?></strong><br>
                Lengkapi <?= esc(strtolower($label_blok_data)) ?>, orang tua, dan saksi, lalu unggah dokumen pendukung.
              </p>
            </div>
          </div>

          <?php
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

          <form method="POST" enctype="multipart/form-data" class="space-y-4" id="aktaForm">
<?php echo csrf_input(); ?>
            <input type="hidden" name="jenis" value="<?= esc($jenis_permohonan) ?>">

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
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;"><?= esc($label_blok_data) ?></h3>

            <?php if ($butuh_nik_subjek): ?>
            <div class="form-grid-2">
              <div>
                <label class="field-label">NIK yang Bersangkutan <span style="color:red">*</span></label>
                <input type="text" name="nik_subjek" value="<?= esc($_POST['nik_subjek'] ?? '') ?>" required class="input" placeholder="16 digit NIK" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                <p class="helper">NIK yang sudah tercatat di Kartu Keluarga.</p>
              </div>
              <?php if ($is_pembaruan): ?>
              <div>
                <label class="field-label">Nomor Akta Lahir Lama <span style="color:red">*</span></label>
                <input type="text" name="nomor_akta_lama" value="<?= esc($_POST['nomor_akta_lama'] ?? '') ?>" required class="input" placeholder="Nomor pada akta lahir lama" maxlength="100">
                <p class="helper">Salin nomor yang tercetak pada akta lahir lama Anda.</p>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="form-grid-2">
              <div>
                <label class="field-label"><?= esc($label_nama_subjek) ?> <span style="color:red">*</span></label>
                <input type="text" name="nama_bayi" value="<?= esc($_POST['nama_bayi'] ?? '') ?>" required class="input" placeholder="<?= $butuh_nik_subjek ? 'Nama lengkap sesuai dokumen' : 'Nama lengkap bayi' ?>">
                <p class="helper">Tulis nama lengkap tanpa gelar, ejaannya harus sama dengan dokumen pendukung.</p>
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
                <input type="text" name="tempat_lahir" value="<?= esc($_POST['tempat_lahir'] ?? '') ?>" required class="input" placeholder="Kabupaten Gorontalo">
              </div>
              <div>
                <label class="field-label">Tanggal Lahir <span style="color:red">*</span></label>
                <input type="date" name="tanggal_lahir" value="<?= esc($_POST['tanggal_lahir'] ?? '') ?>" required class="input">
                <p class="helper">Harus sama dengan tanggal pada <?= esc(strtolower($label_bukti_lahir)) ?>.</p>
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
                <p class="helper">Urutan kelahiran dalam keluarga. Anak pertama ditulis 1.</p>
              </div>
            </div>

            <?php if (FORMULIR_F201_AUTO_ENABLED): ?>
            <div class="form-grid-2">
              <div>
                <label class="field-label">Jenis Kelahiran <span style="color:red">*</span></label>
                <select name="jenis_kelahiran" class="select" required>
                  <option value="">-- Pilih --</option>
                  <?php foreach (siprima_f201_jenis_kelahiran_options() as $nilai => $teks): ?>
                  <option value="<?= (int)$nilai ?>" <?= ((string)($_POST['jenis_kelahiran'] ?? '') === (string)$nilai) ? 'selected' : '' ?>><?= esc($teks) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="field-label">Penolong Kelahiran <span style="color:red">*</span></label>
                <select name="penolong_kelahiran" class="select" required>
                  <option value="">-- Pilih --</option>
                  <?php foreach (siprima_f201_penolong_options() as $nilai => $teks): ?>
                  <option value="<?= (int)$nilai ?>" <?= ((string)($_POST['penolong_kelahiran'] ?? '') === (string)$nilai) ? 'selected' : '' ?>><?= esc($teks) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Berat Bayi (kg) <span style="color:red">*</span></label>
                <input type="text" inputmode="decimal" name="berat_bayi" value="<?= esc($_POST['berat_bayi'] ?? '') ?>" required class="input" placeholder="Contoh: 3,2">
                <p class="helper">Sesuai <?= esc($label_bukti_lahir) ?>. Gunakan koma atau titik.</p>
              </div>
              <div>
                <label class="field-label">Panjang Bayi (cm) <span style="color:red">*</span></label>
                <input type="text" inputmode="decimal" name="panjang_bayi" value="<?= esc($_POST['panjang_bayi'] ?? '') ?>" required class="input" placeholder="Contoh: 48,5">
                <p class="helper">Sesuai <?= esc($label_bukti_lahir) ?>. Gunakan koma atau titik.</p>
              </div>
            </div>
            <?php endif; ?>

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
                <p class="helper">16 digit angka sesuai KTP, tanpa spasi atau tanda baca.</p>
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
                <p class="helper">16 digit angka sesuai KTP, tanpa spasi atau tanda baca.</p>
              </div>
            </div>

            <!-- Data Saksi -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Saksi (2 Saksi)</h3>
            
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Saksi 1 <span style="color:red">*</span></label>
                <p class="helper">Saksi adalah orang dewasa yang mengetahui kelahiran ini, boleh keluarga sendiri.</p>
                <input type="text" name="nama_saksi1" value="<?= esc($_POST['nama_saksi1'] ?? '') ?>" required class="input" placeholder="Nama lengkap saksi 1">
              </div>
              <div>
                <label class="field-label">NIK Saksi 1 <span style="color:red">*</span></label>
                <input type="text" name="nik_saksi1" value="<?= esc($_POST['nik_saksi1'] ?? '') ?>" required class="input" placeholder="16 digit NIK saksi 1" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                <p class="helper">16 digit angka sesuai KTP saksi.</p>
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

            <!-- Keterangan -->
            <div class="f-field">
              <label class="field-label">Keterangan <span style="color:red">*</span></label>
              <textarea name="keterangan" required class="input" rows="3" placeholder="Contoh: Permohonan akta kelahiran pertama" maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
              <p class="helper">Maksimal 500 karakter</p>
            </div>

            <?php if (FORMULIR_F201_AUTO_ENABLED): ?>
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
              <div class="f-doc-group f-doc-group--wajib">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                </h4>
                <div id="wajibUploadContainer">
                  <?php if (!FORMULIR_F201_AUTO_ENABLED): ?>
                  <div class="file-input-group" id="fileGroupWajib1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="formulir" selected>Formulir F-2.01 Kelahiran</option>
                    </select>
                    <div class="file-size-warning">
                      Unduh Formulir F-2.01 Kelahiran
                      <a href="https://siprima.gorontalokab.go.id/uploads/formulir/1766758549_694e9895d9914.pdf" target="_blank" rel="noopener noreferrer">klik disini</a>
                    </div>
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
                      <option value="<?= esc($doc_bukti_lahir) ?>" selected><?= esc($label_bukti_lahir) ?></option>
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
                      <option value="buku_nikah" selected>Buku Nikah/Akta Kawin/SPTJM Perkawinan</option>
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

                  <div class="file-input-group" id="fileGroupWajib5">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="kk" selected>Kartu Keluarga (KK) / Suket Kehilangan KK</option>
                      <option value="suket_kehilangan_kk">Suket Kehilangan KK</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib5">Pilih File</label>
                      <span id="fileNameWajib5" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib5" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib5">Maksimal 5 MB per file, dikompres otomatis</div>
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
                <?php if (FORMULIR_F201_AUTO_ENABLED): ?>
                1. <?= esc($label_bukti_lahir) ?><br>
                2. Buku Nikah/Akta Kawin/SPTJM Perkawinan<br><br>
                3. <?= esc($labelSelfie) ?><br>
                4. Kartu Keluarga (KK) atau Suket Kehilangan KK<br><br>
                <strong>Formulir F-2.01 dibuat otomatis oleh sistem</strong> dari isian Anda, jadi tidak perlu diunggah.<br><br>
                <?php else: ?>
                1. Formulir F-2.01 Kelahiran<br>
                2. <?= esc($label_bukti_lahir) ?><br>
                3. Buku Nikah/Akta Kawin/SPTJM Perkawinan<br><br>
                4. <?= esc($labelSelfie) ?><br>
                5. Kartu Keluarga (KK) atau Suket Kehilangan KK<br><br>
                <?php endif; ?>
                <?php if ($is_pembaruan): ?>
                <strong>Surat keterangan lahir dari faskes/kantor desa/nahkoda tidak diperlukan</strong> pada pembaruan akta karena akta lahir lama sudah menjadi buktinya.<br><br>
                <?php endif; ?>
                <strong>Format:</strong> JPG, JPEG, JFIF, JPE, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong>
              </p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="akta_kelahiran_pilih_jenis.php" class="btn-secondary">Kembali</a>
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
  // JavaScript untuk form akta kelahiran
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

  // Multiple file upload functionality
  let fileCounter = {
    wajib: 5,
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
        <option value="ktp_ortu">KTP Orang Tua</option>
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
  document.getElementById('aktaForm').addEventListener('submit', async function(e) {
    // Selalu tahan submit native. Chrome Android dapat membuka ulang file dari
    // content provider saat membuat multipart dan menampilkan
    // ERR_UPLOAD_FILE_CHANGED. File akan disalin ke Blob stabil lebih dulu.
    e.preventDefault();

    // Cegah double submit di perangkat mobile.
    if (this.dataset.submitting === '1') {
      e.preventDefault();
      return;
    }

    const fileInputs = document.querySelectorAll('input[type="file"]');
    const submitButton = this.querySelector('button[type="submit"]');
    let hasError = false;
    let errorMessages = [];

    // Cek akses file dasar dari browser.
    for (const input of fileInputs) {
      if (input.value && input.files.length === 0) {
        errorMessages.push('Ada file yang tidak dapat diakses dari browser. Pilih ulang file tersebut.');
        hasError = true;
      }
    }
    
    // Validasi NIK harus 16 digit
    const nikFields = [
        {name: 'nik_ayah', label: 'NIK Ayah'},
        {name: 'nik_ibu', label: 'NIK Ibu'},
        {name: 'nik_saksi1', label: 'NIK Saksi 1'}
    ];
    <?php if ($butuh_nik_subjek): ?>
    nikFields.unshift({name: 'nik_subjek', label: 'NIK yang Bersangkutan'});
    <?php endif; ?>
    
    nikFields.forEach(field => {
        const input = document.querySelector(`input[name="${field.name}"]`);
        if (input && input.value.length !== 16) {
            errorMessages.push(`${field.label} harus 16 digit`);
            input.style.borderColor = '#ef4444';
            hasError = true;
        }
    });
    
    // Validasi NIK saksi 2 jika diisi
    const nikSaksi2 = document.querySelector('input[name="nik_saksi2"]');
    if (nikSaksi2 && nikSaksi2.value.trim() !== '' && nikSaksi2.value.length !== 16) {
        errorMessages.push('NIK Saksi 2 harus 16 digit jika diisi');
        hasError = true;
    }
    
    // Validasi ukuran file
    fileInputs.forEach((input) => {
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
    const bukuInput = document.getElementById('fileInputWajib3');
    const selfieInput = document.getElementById('fileInputWajib4');
    const kkSuketInput = document.getElementById('fileInputWajib5');
    
    // formulirInput tidak ada saat formulir dibuat sistem.
    if (formulirInput && !formulirInput.files.length) {
        errorMessages.push('Formulir F-2.01 Kelahiran wajib diupload');
        hasError = true;
    }

    if (!suratInput.files.length) {
        errorMessages.push(<?= json_encode($label_bukti_lahir . ' wajib diupload', JSON_UNESCAPED_UNICODE) ?>);
        hasError = true;
    }
    
    if (!bukuInput.files.length) {
        errorMessages.push('Buku Nikah/Akta Kawin/SPTJM Perkawinan wajib diupload');
        hasError = true;
    }

    if (!selfieInput.files.length) {
        errorMessages.push(<?= json_encode($labelSelfie . ' wajib diupload', JSON_UNESCAPED_UNICODE) ?>);
        hasError = true;
    }

    if (!kkSuketInput.files.length) {
        errorMessages.push('Kartu Keluarga (KK) atau Suket Kehilangan KK wajib diupload');
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

    if (hasError) {
        let message = 'Perbaiki kesalahan berikut:\n';
        if (errorMessages.length > 0) {
            message += '• ' + errorMessages.join('\n• ');
        }
        alert(message);
    } else {
        const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan akta kelahiran?\n\nPastikan semua dokumen wajib sudah diupload.');
        if (!confirmSubmit) {
            return;
        }

        this.dataset.submitting = '1';
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Menyiapkan berkas...';
        }

        try {
            const formData = new FormData(this);
            const selectedFiles = formData.getAll('dokumen[]');
            const stableFiles = [];

            for (const file of selectedFiles) {
                if (!(file instanceof File) || file.size === 0) {
                    stableFiles.push(file);
                    continue;
                }

                // arrayBuffer() memaksa browser membaca file sekarang. Blob baru
                // tidak lagi bergantung pada file sementara/WhatsApp/MediaStore.
                const copyFile = window.SiprimaStableUpload && window.SiprimaStableUpload.copyFile;
                if (typeof copyFile === 'function') {
                    stableFiles.push(await copyFile(file));
                } else {
                    const bytes = await file.arrayBuffer();
                    stableFiles.push({
                        blob: new Blob([bytes], { type: file.type || 'application/octet-stream' }),
                        name: file.name
                    });
                }
            }

            formData.delete('dokumen[]');
            for (const file of stableFiles) {
                if (file && file.blob) {
                    formData.append('dokumen[]', file.blob, file.name);
                } else {
                    formData.append('dokumen[]', file);
                }
            }

            const uploader = window.SiprimaStableUpload && window.SiprimaStableUpload.uploadFormData;
            const response = typeof uploader === 'function'
                ? await uploader(this.action || window.location.href, 'POST', formData, function(percent) {
                    if (submitButton) {
                        submitButton.textContent = percent < 100
                            ? `Mengunggah ${percent}%`
                            : 'Menyimpan permohonan...';
                    }
                })
                : await fetch(this.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    redirect: 'follow'
                });

            if (!response.ok) {
                throw new Error(`Server merespons dengan status ${response.status}`);
            }

            // Tampilkan hasil akhir GET (setelah redirect sukses) atau validasi
            // PHP tanpa mengulangi POST. Ini juga mempertahankan flash message.
            const html = typeof response.text === 'function' ? await response.text() : response.text;
            if (response.url) {
                window.history.replaceState(null, '', response.url);
            }
            document.open();
            document.write(html);
            document.close();
        } catch (error) {
            console.error('Gagal membaca atau mengunggah dokumen:', error);
            this.dataset.submitting = '0';
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Ajukan Permohonan';
            }
            alert('Salah satu dokumen tidak dapat dibaca oleh perangkat. Pilih ulang dokumen tersebut dari folder Download, lalu segera tekan Ajukan Permohonan. Data formulir Anda tidak hilang.');
        }
    }
  });
  </script>
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
<script src="../assets/js/baby-names.js?v=20260909-2"></script>
<script src="js/baby-name-handoff.js?v=20260909-2" data-account="<?= (int)$_SESSION['id_user'] ?>" data-method="<?= ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 'GET' : 'POST' ?>" data-catalog="../assets/data/baby-names.json?v=20260909-2"></script>
<?php if (FORMULIR_F201_AUTO_ENABLED): ?>
<script src="../assets/js/formulir_ttd.js?v=20260919-1" data-form="aktaForm" data-pratinjau="pratinjau_formulir.php"></script>
<?php endif; ?>
</body>
</html>
