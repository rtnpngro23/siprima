<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
require_once __DIR__ . '/../functions/formulir_f101.php';
require_once __DIR__ . '/../functions/kk_kategori.php';
require_once __DIR__ . '/../../app/config/document_features.php';
require_once __DIR__ . '/anggota_kk_kartu.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_kartukeluarga LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}

// Disable error reporting untuk production
error_reporting(0);
ini_set('display_errors', 0);

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// Cek apakah ID permohonan diberikan
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: status_permohonan.php?error=no_id');
    exit;
}

$permohonan_id = (int)$_GET['id'];

// Ambil data permohonan berdasarkan ID
$stmt = $koneksi->prepare("SELECT 
    id, 
    id_kecamatan,
    id_desa,
    nama_pemohon,
    nik_pemohon,
    no_hp,
    email,
    nama_kecamatan, 
    nama_desa,
    dusun,
    nama_kepalaKeluarga, 
    anggotaKeluarga, 
    jenis_permohonan,
    keterangan,
    dokumen, 
    status,
    alasan,
    nama_folder,
    kategori_layanan
    FROM tb_kartukeluarga 
    WHERE id = ? AND id_user = ?");
$stmt->bind_param('ii', $permohonan_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header('Location: status_permohonan.php?error=not_found');
    exit;
}

$permohonan = $res->fetch_assoc();

// Kategori selain kk_baru (KK hilang, cetak biodata, perubahan data) punya form edit ringkas sendiri.
if (siprima_kk_kategori_normalize($permohonan['kategori_layanan'] ?? null) !== KK_KATEGORI_DEFAULT) {
    header('Location: edit_kk_layanan.php?id=' . $permohonan_id);
    exit;
}

// Hanya izinkan edit saat status diajukan atau ditolak
if (!in_array(strtolower((string)$permohonan['status']), ['diajukan', 'ditolak'], true)) {
    header('Location: status_permohonan.php?error=cannot_edit');
    exit;
}

// Fungsi untuk meng-generate URL lengkap untuk dokumen
function getDocumentUrl($path) {
    return siprima_document_token_url(__DIR__, (string)$path) ?? '';
}

// Dekode data JSON
$anggota_keluarga = [];
if (!empty($permohonan['anggotaKeluarga'])) {
    $anggota_keluarga = json_decode($permohonan['anggotaKeluarga'], true);
    if (!is_array($anggota_keluarga)) {
        $anggota_keluarga = [];
    }
}

$dokumen_data = [];
if (!empty($permohonan['dokumen'])) {
    $dokumen_data = json_decode($permohonan['dokumen'], true);
    if (!is_array($dokumen_data)) {
        $dokumen_data = [];
    }
    
    // Tambahkan URL lengkap ke setiap dokumen
    foreach ($dokumen_data as &$doc) {
        if (isset($doc['path'])) {
            $doc['full_url'] = getDocumentUrl($doc['path']);
        }
    }
    unset($doc);
}

// Ambil data kecamatan dan desa untuk dropdown
$kecamatan_options = [];
$desa_options = [];

$kecamatan_query = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
while ($kec = $kecamatan_query->fetch_assoc()) {
    $kecamatan_options[] = $kec;
}

// Jika ada id_kecamatan, ambil desa berdasarkan kecamatan
if (!empty($permohonan['id_kecamatan'])) {
    $desa_query = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
    $desa_query->bind_param('i', $permohonan['id_kecamatan']);
    $desa_query->execute();
    $desa_result = $desa_query->get_result();
    while ($desa = $desa_result->fetch_assoc()) {
        $desa_options[] = $desa;
    }
    $desa_query->close();
}

// Proses form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') siprima_validate_request_images($errors);
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi input
    $nama_pemohon = trim($_POST['nama_pemohon'] ?? '');
    $nik_pemohon = trim($_POST['nik_pemohon'] ?? '');
    $no_hp = trim($_POST['no_hp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $id_kecamatan = (int)($_POST['id_kecamatan'] ?? 0);
    $id_desa = (int)($_POST['id_desa'] ?? 0);
    $dusun = trim($_POST['dusun'] ?? '');
    $nama_kepalaKeluarga = trim($_POST['nama_kepalaKeluarga'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi
    if (empty($nama_pemohon)) $errors[] = "Nama pemohon harus diisi";
    if (empty($nik_pemohon)) $errors[] = "NIK pemohon harus diisi";
    if (empty($no_hp)) $errors[] = "No HP harus diisi";
    if (empty($email)) $errors[] = "Email harus diisi";
    if (empty($id_kecamatan)) $errors[] = "Kecamatan harus dipilih";
    if (empty($id_desa)) $errors[] = "Desa harus dipilih";
    if (empty($dusun)) $errors[] = "Dusun harus diisi";
    if (empty($nama_kepalaKeluarga)) $errors[] = "Nama kepala keluarga harus diisi";
    
    // Isian tambahan F-1.01. Pernyataan dan tanda tangan WAJIB diulang setiap kali data diubah:
    // tanda tangan hanya berlaku untuk versi dokumen yang ditandatanganinya.
    $f101_fields = null;
    $f101_signature = null;
    $formulir_dibuat_at = null;
    if (FORMULIR_F101_AUTO_ENABLED) {
        $errors = array_merge($errors, siprima_f101_validate_fields($_POST, $f101_fields));
        if (empty($_POST['pernyataan_benar'])) {
            $errors[] = 'Centang pernyataan kebenaran data sebelum mengajukan kembali.';
        }
        $pesan_ttd = siprima_formulir_validate_signature((string)($_POST['ttd_pemohon'] ?? ''), $f101_signature);
        if ($pesan_ttd !== '') $errors[] = $pesan_ttd;
    }
    $jenis_permohonan = $f101_fields['jenis_permohonan'] ?? null;

    // Validasi anggota keluarga
    $anggota_keluarga_baru = [];
    if (FORMULIR_F101_AUTO_ENABLED) {
        $errors = array_merge($errors, siprima_f101_validate_anggota($_POST, $anggota_keluarga_baru));
        $anggota_keluarga_baru = (array)$anggota_keluarga_baru;
    } elseif (isset($_POST['anggota_nama']) && is_array($_POST['anggota_nama'])) {
        $anggota_nama = $_POST['anggota_nama'];
        $anggota_nik = $_POST['anggota_nik'] ?? [];
        $anggota_hubungan = $_POST['anggota_hubungan'] ?? [];
        
        for ($i = 0; $i < count($anggota_nama); $i++) {
            $nama = trim($anggota_nama[$i] ?? '');
            $nik = trim($anggota_nik[$i] ?? '');
            $hubungan = trim($anggota_hubungan[$i] ?? '');
            
            if (!empty($nama)) {
                $anggota_keluarga_baru[] = [
                    'nama' => $nama,
                    'nik' => $nik,
                    'hubungan' => $hubungan
                ];
            }
        }
    }
    
    if (!FORMULIR_F101_AUTO_ENABLED && empty($anggota_keluarga_baru)) {
        $errors[] = "Minimal harus ada satu anggota keluarga";
    }
    
    // Ambil nama kecamatan dan desa
    $nama_kecamatan_db = '';
    $nama_desa_db = '';
    
    if (!empty($id_kecamatan)) {
        $qk = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $qk->bind_param('i', $id_kecamatan);
        $qk->execute();
        $qk->bind_result($nama_kecamatan_db);
        $qk->fetch();
        $qk->close();
    }
    
    if (!empty($id_desa)) {
        $qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $qd->bind_param('i', $id_desa);
        $qd->execute();
        $qd->bind_result($nama_desa_db);
        $qd->fetch();
        $qd->close();
    }
    
    // GUNAKAN FOLDER YANG SAMA (tidak membuat folder baru)
    $folder_name = $permohonan['nama_folder'];
    $folder_path = __DIR__ . '/uploads/kk/' . $folder_name . '/';
    
    // Pastikan folder ada
    if (!is_dir($folder_path)) {
        if (!mkdir($folder_path, 0755, true)) {
            $errors[] = 'Folder penyimpanan tidak ditemukan dan gagal dibuat';
        }
    }
    
    // Handle upload dokumen - Gunakan folder yang sama
    $dokumen_baru = [];
    $uploadedDocTypes = [];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    
    // Dokumen WAJIB. Saat formulir dibuat sistem, blanko F-1.01 tidak lagi diunggah.
    $requiredDocs = FORMULIR_F101_AUTO_ENABLED
        ? ['kk_lama_atau_surat_kehilangan', 'foto_selfie']
        : ['formulir_f101', 'kk_lama_atau_surat_kehilangan', 'foto_selfie'];
    
    // Proses upload dokumen baru
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name'])) {
        $fileCount = count($_FILES['dokumen']['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileType = mime_content_type($fileTmp);
                $fileSize = $_FILES['dokumen']['size'][$i];
                $docType = $_POST['doc_type'][$i] ?? 'lainnya';

                // doc_type berasal dari klien: tolak unggahan manual bertipe formulir agar tidak
                // ada dua dokumen yang tampak resmi di mata operator.
                if (FORMULIR_F101_AUTO_ENABLED && $docType === 'formulir_f101') {
                    $errors[] = 'Jenis dokumen tidak valid.';
                    continue;
                }

                // Validasi tipe file
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) || 
                    !in_array($fileType, $allowedTypes)) {
                    $errors[] = "File {$fileName}: Hanya format JPG, PNG, atau GIF yang diperbolehkan";
                    continue;
                }

                // Validasi ukuran file - MAKSIMAL 1MB
                if ($fileSize > $maxFileSize) {
                    $errors[] = "File {$fileName}: Ukuran setelah kompresi maksimal 1MB. File Anda: " . formatFileSize($fileSize);
                    continue;
                }

                // Generate unique filename dengan format yang sama
                $timestamp_file = time();
                $randomString = bin2hex(random_bytes(6));
                $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                $newName = $clean_doc_type . '_' . $timestamp_file . '_' . $randomString . '.' . $ext;
                $destination = $folder_path . $newName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    // Simpan path relatif dari root website (folder tetap sama)
                    $dokumen_baru[] = [
                        'path' => 'uploads/kk/' . $folder_name . '/' . $newName,
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
            }
        }
        
        // Tambahkan file .htaccess untuk keamanan jika belum ada
        $htaccessFile = $folder_path . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, 
                "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
        }
    }
    
    // Tambahkan dokumen lama yang tidak dihapus.
    // Halaman formulir lama SELALU dibuang: ia dibuat ulang dari data terbaru, dan menyaringnya
    // di sini juga menutup jalur mempertahankan halaman lama lewat POST langsung.
    if (isset($_POST['existing_docs']) && is_array($_POST['existing_docs'])) {
        foreach ($_POST['existing_docs'] as $doc_index) {
            if (!isset($dokumen_data[$doc_index])) continue;
            if (FORMULIR_F101_AUTO_ENABLED && ($dokumen_data[$doc_index]['type'] ?? '') === 'formulir_f101') continue;
            $dokumen_baru[] = $dokumen_data[$doc_index];
            $uploadedDocTypes[] = $dokumen_data[$doc_index]['type'];
        }
    }
    
    // Cek dokumen wajib
    $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            if ($doc === 'formulir_f101') {
                $missingNames[] = 'Formulir F-1.01 Biodata Keluarga';
            } elseif ($doc === 'kk_lama_atau_surat_kehilangan') {
                $missingNames[] = 'KK Lama / Surat Keterangan Kehilangan KK';
            } elseif ($doc === 'foto_selfie') {
                $missingNames[] = 'Foto Selfie Pemohon Memegang Formulir';
            }
        }
        $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
    }

    // Formulir dibuat setelah seluruh unggahan final dan sebelum UPDATE, agar kegagalan render
    // mencegah penyimpanan data (dokumen dan baris DB tidak pernah saling bertentangan).
    $halamanBaruFormulir = [];
    if (FORMULIR_F101_AUTO_ENABLED && empty($errors)) {
        try {
            $formulir_dibuat_at = date('Y-m-d H:i:s');
            $halamanFormulir = siprima_f101_render(
                siprima_f101_data_kartu_keluarga(
                    $_POST,
                    (array)$f101_fields,
                    $anggota_keluarga_baru,
                    ['nama' => $nama_pemohon, 'nik' => $nik_pemohon],
                    (string)$nama_kecamatan_db,
                    (string)$nama_desa_db,
                    $formulir_dibuat_at
                ),
                (string)$f101_signature
            );

            $total = count($halamanFormulir);
            $berkasFormulir = [];
            foreach ($halamanFormulir as $nomor => $jpeg) {
                $namaHalaman = 'formulir_f101_h' . ($nomor + 1) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
                if (file_put_contents($folder_path . $namaHalaman, $jpeg) === false) {
                    throw new RuntimeException('Formulir gagal ditulis ke folder permohonan.');
                }
                $halamanBaruFormulir[] = $namaHalaman;
                $berkasFormulir[] = [
                    'path' => 'uploads/kk/' . $folder_name . '/' . $namaHalaman,
                    'type' => 'formulir_f101',
                    'original_name' => 'Formulir F-1.01 Biodata Keluarga — Halaman ' . ($nomor + 1) . ' dari ' . $total . ' (dibuat sistem)',
                    'uploaded_name' => $namaHalaman,
                    'size' => strlen($jpeg),
                    'uploaded_at' => $formulir_dibuat_at,
                    'generated' => true,
                ];
            }
            $dokumen_baru = array_merge($berkasFormulir, $dokumen_baru);
        } catch (Throwable $e) {
            error_log('Formulir F-1.01 render error (edit): ' . $e->getMessage());
            $errors[] = 'Formulir belum dapat dibuat. Silakan ulangi tanda tangan Anda lalu coba lagi.';
        }
    }

    // Jika tidak ada error, update data
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        // Convert data ke JSON. JSON yang terpotong membuat SELURUH daftar anggota hilang
        // di semua layar, jadi lebih baik ditolak lebih awal daripada tersimpan rusak.
        $anggota_keluarga_json = json_encode($anggota_keluarga_baru);
        if (strlen((string)$anggota_keluarga_json) > SIPRIMA_F101_MAX_JSON) {
            $errors[] = 'Data anggota keluarga terlalu panjang. Kurangi jumlah anggota atau persingkat isian.';
        }
    }

    if (empty($errors)) {
        $dokumen_json = json_encode($dokumen_baru);
        
        // Update data permohonan
        $stmt = $koneksi->prepare("UPDATE tb_kartukeluarga SET 
            nama_pemohon = ?,
            nik_pemohon = ?,
            no_hp = ?,
            email = ?,
            id_kecamatan = ?,
            id_desa = ?,
            dusun = ?,
            nama_kepalaKeluarga = ?,
            anggotaKeluarga = ?,
            keterangan = ?,
            dokumen = ?,
            nama_kecamatan = ?,
            nama_desa = ?,
            jenis_permohonan = ?,
            formulir_dibuat_at = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?");
        
        // 17 parameter, urut sama dengan daftar SET lalu WHERE id, id_user.
        $stmt->bind_param('ssssiisssssssssii', 
            $nama_pemohon,
            $nik_pemohon,
            $no_hp,
            $email,
            $id_kecamatan,
            $id_desa,
            $dusun,
            $nama_kepalaKeluarga,
            $anggota_keluarga_json,
            $keterangan,
            $dokumen_json,
            $nama_kecamatan_db,
            $nama_desa_db,
            $jenis_permohonan,
            $formulir_dibuat_at,
            $permohonan_id,
            $user_id
        );
        
        if ($stmt->execute()) {
            logPermohonanStatusHistory(
                $koneksi,
                'tb_kartukeluarga',
                $permohonan_id,
                'diajukan',
                null,
                (int)($_SESSION['id_user'] ?? 0),
                (string)($_SESSION['role'] ?? 'user'),
                'resubmit'
            );
            // Hapus file lama yang tidak dipilih lagi.
            // Halaman formulir lama selalu ikut dihapus meski klien menyertakan indeksnya di
            // existing_docs, karena formulir sudah dibuat ulang dan yang lama jadi berkas yatim.
            $existing_docs = $_POST['existing_docs'] ?? [];
            foreach ($dokumen_data as $index => $doc) {
                $formulirLama = FORMULIR_F101_AUTO_ENABLED && ($doc['type'] ?? '') === 'formulir_f101';
                if ((!in_array($index, $existing_docs) || $formulirLama)
                    && isset($doc['path']) && file_exists(__DIR__ . '/' . $doc['path'])) {
                    // Hapus file fisik
                    @unlink(__DIR__ . '/' . $doc['path']);
                }
            }
            
            $success = true;
            $_SESSION['success_message'] = "Permohonan berhasil diperbarui dan diajukan kembali!";
            header('Location: status_permohonan.php');
            exit;
        } else {
            $errors[] = "Gagal memperbarui data: " . $stmt->error;
            // Folder di sini memuat dokumen lama yang masih dipakai, jadi hanya halaman yang
            // baru dirender yang dibuang — jangan hapus seluruh folder.
            foreach ($halamanBaruFormulir as $namaHalaman) {
                if (is_file($folder_path . $namaHalaman)) @unlink($folder_path . $namaHalaman);
            }
        }
        $stmt->close();
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

function selected($value, $compare) {
    return $value == $compare ? 'selected' : '';
}

// Fungsi untuk membersihkan path untuk JavaScript
function js_escape($string) {
    return addslashes($string ?? '');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Permohonan Kartu Keluarga</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/edit_kk.css">
    <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
    <style>
        /* Modal Preview Styles */
        .preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .preview-modal.show {
            display: flex;
        }
        
        .preview-modal-content {
            background: white;
            border-radius: 10px;
            max-width: 95%;
            max-height: 90vh;
            width: auto;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        
        .preview-modal-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .preview-modal-header h3 {
            margin: 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        
        .preview-close-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 5px 12px;
            line-height: 1;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }
        
        .preview-close-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .preview-modal-body {
            padding: 30px;
            flex: 1;
            overflow: auto;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 400px;
            background: #f5f5f5;
            position: relative;
        }
        
        .preview-modal-footer {
            padding: 15px 20px;
            background: white;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        
        .preview-footer-btn {
            padding: 10px 25px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            min-width: 120px;
            justify-content: center;
        }
        
        .preview-footer-btn.close {
            background: #6c757d;
            color: white;
        }
        
        .preview-footer-btn.download {
            background: #28a745;
            color: white;
            text-decoration: none;
        }
        
        .preview-footer-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .preview-loading {
            text-align: center;
            color: #666;
        }
        
        .preview-loading .spinner {
            width: 60px;
            height: 60px;
            border: 5px solid rgba(0,123,255,0.1);
            border-top: 5px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        .preview-error {
            text-align: center;
            color: #dc3545;
            max-width: 400px;
        }
        
        .preview-error i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #ff6b6b;
        }
        
        .preview-image {
            max-width: 100%;
            max-height: 70vh;
            display: block;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .preview-error-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .preview-modal-content {
            animation: fadeIn 0.3s ease-out;
        }
    </style>
    <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-line">

<div class="layout">
    <!-- SIDEBAR -->
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header user-general-header">
            <?php
            require_once __DIR__ . '/user_header_brand.php';
            render_user_header_brand('Edit Permohonan Kartu Keluarga');
            ?>
            <div class="user-info">
                <span>Hai, <?= esc($_SESSION['nama']) ?></span>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong>Terjadi kesalahan:</strong>
                    <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= esc($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <div class="form-header">
                    <h2>Edit Permohonan KK</h2>
                    <span class="status-badge">Status: <?= esc(ucfirst(strtolower((string)($permohonan['status'] ?? '-')))) ?></span>
                </div>

                <form method="POST" enctype="multipart/form-data" id="editKKForm">
<?php echo csrf_input(); ?>
                    <!-- Data Pemohon -->
                    <div class="form-section">
                        <h3 class="section-title">Data Pemohon</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Pemohon <span class="required">*</span></label>
                                <input type="text" name="nama_pemohon" class="form-control" 
                                       value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>" required readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Pemohon <span class="required">*</span></label>
                                <input type="text" name="nik_pemohon" class="form-control" 
                                       value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>" required readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>No HP <span class="required">*</span></label>
                                <input type="tel" name="no_hp" class="form-control" 
                                       value="<?= esc($permohonan['no_hp'] ?? '') ?>" required readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?= esc($permohonan['email'] ?? '') ?>" required readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Data Domisili -->
                    <div class="form-section">
                        <h3 class="section-title">Data Domisili</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Kecamatan <span class="required">*</span></label>
                                <select name="id_kecamatan" id="kecamatanSelect" class="form-control" required>
                                    <option value="">Pilih Kecamatan</option>
                                    <?php foreach ($kecamatan_options as $kec): ?>
                                        <option value="<?= $kec['id'] ?>" 
                                            <?= selected($kec['id'], $permohonan['id_kecamatan'] ?? '') ?>>
                                            <?= esc($kec['nama_kecamatan']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Desa <span class="required">*</span></label>
                                <select name="id_desa" id="desaSelect" class="form-control" required>
                                    <option value="">Pilih Desa</option>
                                    <?php foreach ($desa_options as $desa): ?>
                                        <option value="<?= $desa['id'] ?>" 
                                            <?= selected($desa['id'], $permohonan['id_desa'] ?? '') ?>>
                                            <?= esc($desa['nama_desa']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Dusun <span class="required">*</span></label>
                                <input type="text" name="dusun" class="form-control" 
                                       value="<?= esc($permohonan['dusun'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Nama Kepala Keluarga <span class="required">*</span></label>
                                <input type="text" name="nama_kepalaKeluarga" class="form-control" 
                                       value="<?= esc($permohonan['nama_kepalaKeluarga'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Anggota Keluarga -->
                    <div class="form-section">
                        <h3 class="section-title">Anggota Keluarga</h3>
                        <?php if (FORMULIR_F101_AUTO_ENABLED): ?>
                        <?php
                        $anggotaAwal = siprima_f101_anggota_dari_post($_POST);
                        if (!$anggotaAwal) $anggotaAwal = $anggota_keluarga ?: [[]];
                        ?>
                        <p class="helper">
                            Untuk mengubah data seperti golongan darah, status perkawinan, atau pekerjaan,
                            buka <strong>Ubah data lainnya</strong> pada anggota yang bersangkutan.
                            Yang dibiarkan kosong berarti tidak ada perubahan.
                        </p>
                        <div id="anggotaWadah">
                            <?php foreach ($anggotaAwal as $i => $a): ?>
                                <?php siprima_f101_kartu_anggota(is_array($a) ? $a : [], $i + 1); ?>
                            <?php endforeach; ?>
                        </div>
                        <template id="anggotaTemplate"><?php siprima_f101_kartu_anggota([], null); ?></template>
                        <button type="button" id="btnTambahAnggota" class="btn-add-anggotam">
                            <i class="fas fa-plus"></i> Tambah Anggota Keluarga
                        </button>
                        <p id="pesanAnggota" class="helper" style="display:none; color:#b45309;"></p>
                        <?php else: ?>
                        <table class="anggota-table" id="anggotaTable">
                            <thead>
                                <tr>
                                    <th width="40%">Nama Lengkap</th>
                                    <th width="30%">NIK</th>
                                    <th width="25%">Hubungan Keluarga</th>
                                    <th width="5%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="anggotaBody">
                                <?php if (!empty($anggota_keluarga)): ?>
                                    <?php foreach ($anggota_keluarga as $index => $anggota): ?>
                                    <tr>
                                        <td>
                                            <input type="text" name="anggota_nama[]" 
                                                   value="<?= esc($anggota['nama'] ?? '') ?>" 
                                                   placeholder="Nama lengkap" required>
                                        </td>
                                        <td>
                                            <input type="text" name="anggota_nik[]" 
                                                   value="<?= esc($anggota['nik'] ?? '') ?>" 
                                                   placeholder="NIK">
                                        </td>
                                        <td>
                                            <input type="text" name="anggota_hubungan[]" 
                                                   value="<?= esc($anggota['hubungan'] ?? '') ?>" 
                                                   placeholder="Contoh: Anak, Istri">
                                        </td>
                                        <td>
                                            <?php if ($index > 0): ?>
                                            <button type="button" class="btn-remove-anggotam" 
                                                    onclick="removeAnggota(this)">×</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td>
                                        <input type="text" name="anggota_nama[]" 
                                               placeholder="Nama lengkap" required>
                                    </td>
                                    <td>
                                        <input type="text" name="anggota_nik[]" 
                                               placeholder="NIK">
                                    </td>
                                    <td>
                                        <input type="text" name="anggota_hubungan[]" 
                                               placeholder="Contoh: Anak, Istri">
                                    </td>
                                    <td></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <button type="button" class="btn-add-anggotam" onclick="addAnggota()">
                            <i class="fas fa-plus"></i> Tambah Anggota Keluarga
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if (FORMULIR_F101_AUTO_ENABLED): ?>
                    <div class="form-section">
                        <h3 class="section-title">Jenis Permohonan</h3>
                        <?php $jpTerpilih = (string)($_POST['jenis_permohonan'] ?? $permohonan['jenis_permohonan'] ?? ''); ?>
                        <select name="jenis_permohonan" class="form-control" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach (siprima_f101_jenis_permohonan_pilihan($jpTerpilih) as $opsi): ?>
                            <option value="<?= esc($opsi) ?>" <?= $jpTerpilih === $opsi ? 'selected' : '' ?>><?= esc($opsi) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-section" id="blokFormulir">
                        <h3 class="section-title">Pernyataan dan Tanda Tangan</h3>
                        <p class="helper">
                            Karena data berubah, formulir F-1.01 akan dibuat ulang oleh sistem dan Anda perlu
                            menandatanganinya kembali. Tanda tangan hanya berlaku untuk versi dokumen yang Anda
                            tandatangani.
                        </p>
                        <label style="display:flex; gap:10px; align-items:flex-start; margin:16px 0; cursor:pointer;">
                            <input type="checkbox" id="pernyataanBenar" name="pernyataan_benar" value="1" required
                                   style="margin-top:4px; width:18px; height:18px; flex-shrink:0;"
                                   <?= !empty($_POST['pernyataan_benar']) ? 'checked' : '' ?>>
                            <span>
                                Demikian formulir ini saya isi dengan sesungguhnya. Apabila keterangan tersebut tidak
                                sesuai dengan keadaan sebenarnya, saya bersedia dikenakan sanksi sesuai ketentuan
                                peraturan perundang-undangan yang berlaku. <span class="required">*</span>
                            </span>
                        </label>
                        <div id="areaTtd" style="opacity:.5; pointer-events:none;">
                            <label>Tanda Tangan Pemohon <span class="required">*</span></label>
                            <p class="helper">Tanda tangani di dalam kotak menggunakan jari atau tetikus.</p>
                            <canvas id="kanvasTtd" width="600" height="200"
                                    style="width:100%; max-width:600px; height:auto; aspect-ratio:3/1; background:#fff; border:2px dashed #94a3b8; border-radius:8px; touch-action:none; display:block;"></canvas>
                            <input type="hidden" id="ttdPemohon" name="ttd_pemohon" value="<?= esc($_POST['ttd_pemohon'] ?? '') ?>">
                            <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                                <button type="button" id="btnHapusTtd" class="btn-secondary">Hapus Tanda Tangan</button>
                                <button type="button" id="btnPratinjauFormulir" class="btn-secondary">Lihat Pratinjau Formulir</button>
                            </div>
                        </div>
                        <div id="kotakPratinjau" style="display:none; margin-top:16px;">
                            <p class="helper">Pratinjau halaman pertama formulir yang akan dikirim:</p>
                            <img id="gambarPratinjau" alt="Pratinjau formulir F-1.01" style="width:100%; border:1px solid #cbd5e1; border-radius:8px;">
                        </div>
                        <div id="pesanPratinjau" style="display:none; margin-top:12px; padding:12px; background:#fef2f2; border-left:4px solid #ef4444; border-radius:6px; color:#991b1b;"></div>
                    </div>
                    <?php endif; ?>

                    <!-- Dokumen Pendukung -->
                    <div class="form-section">
                        <h3 class="section-title">Dokumen Pendukung</h3>
                        
                        <!-- Info dokumen -->
                        <div class="f-doc-group">
                            <p class="f-doc-group__judul"><strong>Petunjuk:</strong></p>
                            <ul style="margin: 0; padding-left: 20px; color: #4b5563;">
                                <li><strong>Dokumen WAJIB:</strong> Formulir F-1.01 Biodata Keluarga, KK Lama / Surat Keterangan Kehilangan KK, dan Foto Selfie Pemohon Memegang Formulir</li>
                                <li><strong>Dokumen OPSIONAL:</strong> KTP, Surat Nikah, Akta Lahir, dll (jika ada)</li>
                                <li>Format file: JPG, JPEG, PNG, GIF</li>
                                <li>Ukuran maksimal per file: 1MB</li>
                                <li><strong>Note:</strong> Dokumen disimpan di folder yang sama dengan pengajuan pertama</li>
                            </ul>
                        </div>
                        
                        <!-- Dokumen Wajib -->
                        <div style="margin-bottom: 20px; padding: 15px; background: #fee2e2; border-radius: 8px; border-left: 4px solid #dc2626;">
                            <h4 style="margin-top: 0; color: #dc2626; font-size: 16px;">
                                <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                            </h4>
                            
                            <?php if (!FORMULIR_F101_AUTO_ENABLED): ?>
                            <!-- Formulir F-1.01 (Wajib) -->
                            <?php 
                            $formulir_file = null;
                            foreach ($dokumen_data as $doc) {
                                if ($doc['type'] === 'formulir_f101') {
                                    $formulir_file = $doc;
                                    break;
                                }
                            }
                            ?>
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label>1. Formulir F-1.01 Biodata Keluarga <span class="required">*</span></label>
                                <div class="file-upload">
                                    <label class="file-upload-label">
                                        <i class="fas fa-upload"></i> Unggah Formulir F-1.01
                                        <input type="file" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" <?= !$formulir_file ? 'required' : '' ?>>
                                        <input type="hidden" name="doc_type[]" value="formulir_f101">
                                    </label>
                                    <div class="file-preview" id="formulir-preview">
                                        <?php if ($formulir_file): ?>
                                        <div class="document-item">
                                            <div class="doc-info">
                                                <span class="doc-type-badge badge-formulir">Formulir F-1.01</span>
                                                <span class="doc-name"><?= esc($formulir_file['original_name']) ?></span>
                                            </div>
                                            <div class="doc-actions">
                                                <input type="hidden" name="existing_docs[]" value="<?= array_search($formulir_file, $dokumen_data) ?>">
                                                <?php if (isset($formulir_file['full_url'])): ?>
                                                <button type="button" class="btn-preview" 
                                                        onclick="previewDocument('<?= js_escape($formulir_file['full_url']) ?>', '<?= js_escape($formulir_file['original_name']) ?>')">
                                                    <i class="fas fa-eye"></i> Lihat
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <span class="file-name">Belum ada file diunggah</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php endif; ?>

                            <!-- KK Lama / Surat Kehilangan (Wajib) -->
                            <?php 
                            $kk_lama_file = null;
                            foreach ($dokumen_data as $doc) {
                                if ($doc['type'] === 'kk_lama_atau_surat_kehilangan') {
                                    $kk_lama_file = $doc;
                                    break;
                                }
                            }
                            ?>
                            <div class="form-group">
                                <label>2. KK Lama / Surat Keterangan Kehilangan KK <span class="required">*</span></label>
                                <div class="file-upload">
                                    <label class="file-upload-label">
                                        <i class="fas fa-upload"></i> Unggah KK Lama atau Surat Kehilangan
                                        <input type="file" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" <?= !$kk_lama_file ? 'required' : '' ?>>
                                        <input type="hidden" name="doc_type[]" value="kk_lama_atau_surat_kehilangan">
                                    </label>
                                    <div class="file-preview" id="kk-lama-preview">
                                        <?php if ($kk_lama_file): ?>
                                        <div class="document-item">
                                            <div class="doc-info">
                                                <span class="doc-type-badge badge-kk_lama">KK Lama/Surat Kehilangan</span>
                                                <span class="doc-name"><?= esc($kk_lama_file['original_name']) ?></span>
                                            </div>
                                            <div class="doc-actions">
                                                <input type="hidden" name="existing_docs[]" value="<?= array_search($kk_lama_file, $dokumen_data) ?>">
                                                <?php if (isset($kk_lama_file['full_url'])): ?>
                                                <button type="button" class="btn-preview" 
                                                        onclick="previewDocument('<?= js_escape($kk_lama_file['full_url']) ?>', '<?= js_escape($kk_lama_file['original_name']) ?>')">
                                                    <i class="fas fa-eye"></i> Lihat
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <span class="file-name">Belum ada file diunggah</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <small class="text-muted">Pilih salah satu: KK Lama <strong>ATAU</strong> Surat Keterangan Kehilangan dari kepolisian</small>
                            </div>

                            <?php
                            $foto_selfie_file = null;
                            foreach ($dokumen_data as $doc) {
                                if ($doc['type'] === 'foto_selfie') {
                                    $foto_selfie_file = $doc;
                                    break;
                                }
                            }
                            ?>
                            <div class="form-group" style="margin-top: 15px;">
                                <label>3. Foto Selfie Pemohon Memegang Formulir <span class="required">*</span></label>
                                <div class="file-upload">
                                    <label class="file-upload-label">
                                        <i class="fas fa-upload"></i> Unggah Foto Selfie Pemohon
                                        <input type="file" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png" <?= !$foto_selfie_file ? 'required' : '' ?>>
                                        <input type="hidden" name="doc_type[]" value="foto_selfie">
                                    </label>
                                    <div class="file-preview" id="foto-selfie-preview">
                                        <?php if ($foto_selfie_file): ?>
                                        <div class="document-item">
                                            <div class="doc-info">
                                                <span class="doc-type-badge badge-foto_selfie">Foto Selfie Pemohon</span>
                                                <span class="doc-name"><?= esc($foto_selfie_file['original_name']) ?></span>
                                            </div>
                                            <div class="doc-actions">
                                                <input type="hidden" name="existing_docs[]" value="<?= array_search($foto_selfie_file, $dokumen_data) ?>">
                                                <?php if (isset($foto_selfie_file['full_url'])): ?>
                                                <button type="button" class="btn-preview"
                                                        onclick="previewDocument('<?= js_escape($foto_selfie_file['full_url']) ?>', '<?= js_escape($foto_selfie_file['original_name']) ?>')">
                                                    <i class="fas fa-eye"></i> Lihat
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <span class="file-name">Belum ada file diunggah</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <small class="text-muted">Upload foto selfie terbaru pemohon sambil memegang formulir.</small>
                            </div>
                        </div>
                        
                        <!-- Dokumen Opsional -->
                        <div class="f-doc-group f-doc-group--opsional">
                            <h4 class="f-doc-group__judul">
                                <i class="fas fa-check-circle"></i> Dokumen Opsional (Jika Ada)
                            </h4>
                            
                            <!-- Dokumen yang sudah ada -->
                            <?php if (!empty($dokumen_data)): ?>
                            <div class="document-list" id="existing-documents">
                                <?php foreach ($dokumen_data as $index => $doc): ?>
                                    <?php if ($doc['type'] !== 'formulir_f101' && $doc['type'] !== 'kk_lama_atau_surat_kehilangan' && $doc['type'] !== 'foto_selfie'): ?>
                                    <div class="document-item" data-index="<?= $index ?>">
                                        <div class="doc-info">
                                            <?php 
                                            $badge_class = 'badge-' . str_replace('_', '-', $doc['type']);
                                            $type_labels = [
                                                'ktp' => 'KTP',
                                                'surat_nikah' => 'Surat Nikah',
                                                'akta_lahir' => 'Akta Lahir',
                                                'akta_kematian' => 'Akta Kematian',
                                                'surat_pindah' => 'Surat Pindah',
                                                'foto_selfie' => 'Foto Selfie Pemohon',
                                                'lainnya' => 'Lainnya'
                                            ];
                                            $type_label = $type_labels[$doc['type']] ?? $doc['type'];
                                            ?>
                                            <span class="doc-type-badge <?= $badge_class ?>"><?= $type_label ?></span>
                                            <span class="doc-name"><?= esc($doc['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <input type="checkbox" name="existing_docs[]" value="<?= $index ?>" checked style="margin-right: 10px;">
                                            <?php if (isset($doc['full_url'])): ?>
                                            <button type="button" class="btn-preview" 
                                                    onclick="previewDocument('<?= js_escape($doc['full_url']) ?>', '<?= js_escape($doc['original_name']) ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn-remove-dokumen" onclick="removeExistingDocument(this, <?= $index ?>)">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Input opsional baru -->
                            <div id="opsionalUploadContainer">
                                <!-- Input opsional pertama -->
                                <div class="file-input-group" id="fileGroupOpsional1" style="margin-bottom: 15px;">
                                    <select name="doc_type[]" class="file-type-select">
                                        <option value="">Pilih Jenis Dokumen (Opsional)</option>
                                        <option value="ktp">KTP</option>
                                        <option value="surat_nikah">Buku Nikah/Akta Nikah/Akta Cerai</option>
                                        <option value="akta_lahir">Akta Lahir</option>
                                        <option value="akta_kematian">Akta Kematian</option>
                                        <option value="surat_pindah">Surat Pindah Datang</option>
                                        <option value="lainnya">Dokumen Lainnya</option>
                                    </select>
                                    <div class="file-upload">
                                        <label class="file-upload-label">
                                            <i class="fas fa-upload"></i> Pilih File
                                            <input type="file" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png">
                                        </label>
                                        <div class="file-preview">
                                            <span class="file-name">Belum ada file dipilih</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="button" class="btn-add" onclick="addOptionalFileInput()">
                                <i class="fas fa-plus"></i> Tambah Dokumen Opsional Lainnya
                            </button>
                        </div>
                    </div>

                    <!-- Keterangan -->
                    <div class="form-section">
                        <h3 class="section-title">Keterangan</h3>
                        <div class="form-group">
                            <label>Keterangan Tambahan</label>
                            <textarea name="keterangan" class="form-control" rows="3" 
                                      placeholder="Tambahkan keterangan jika diperlukan" maxlength="500"><?= esc($permohonan['keterangan'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Alasan Penolakan -->
                    <?php if (!empty($permohonan['alasan'])): ?>
                    <div class="form-section">
                        <h3 class="section-title" style="color: #dc2626;">Alasan Penolakan Sebelumnya</h3>
                        <div class="form-group">
                            <div style="background: #fee2e2; padding: 15px; border-radius: 6px; border: 1px solid #fecaca;">
                                <strong>Catatan dari Admin:</strong><br>
                                <?= nl2br(esc($permohonan['alasan'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <a href="status_permohonan.php" class="btn-cancel">Batal</a>
                        <button type="submit" class="btn-submit">Perbarui dan Ajukan Kembali</button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</div>

<!-- Modal Preview Dokumen -->
<div id="imagePreviewModal" class="preview-modal">
    <div class="preview-modal-content">
        <div class="preview-modal-header">
            <h3><i class="fas fa-file-image"></i> <span id="previewTitle">Preview Dokumen</span></h3>
            <button class="preview-close-btn" onclick="closeImagePreview()">×</button>
        </div>
        
        <div class="preview-modal-body" id="previewModalBody">
            <div id="previewLoading" class="preview-loading">
                <div class="spinner"></div>
                <p>Memuat gambar...</p>
            </div>
            
            <div id="previewImageContainer" style="display: none;">
                <img id="previewImage" src="" alt="" class="preview-image">
            </div>
            
            <div id="previewError" class="preview-error" style="display: none;">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="preview-error-title">Gagal Memuat Gambar</div>
                <p id="errorMessage">File mungkin tidak ditemukan atau format tidak didukung.</p>
            </div>
        </div>
        
        <div class="preview-modal-footer">
            <button class="preview-footer-btn close" onclick="closeImagePreview()">
                <i class="fas fa-times"></i> Tutup (ESC)
            </button>
            
            <a id="downloadLink" href="#" download="" class="preview-footer-btn download">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
    </div>
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
// ============ PREVIEW FUNCTIONS ============
function previewDocument(fullUrl, docName = '') {
    // Validasi input
    if (!fullUrl || fullUrl === '' || fullUrl === 'null') {
        alert('URL dokumen tidak valid');
        return;
    }
    
    // Format yang didukung
    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'jfif'];
    const extension = fullUrl.split('.').pop().toLowerCase();
    
    if (!imageExtensions.includes(extension)) {
        alert('Hanya format gambar (JPG, JPEG, JFIF, JPE, PNG) yang dapat dipreview.');
        return;
    }
    
    // Set judul preview
    document.getElementById('previewTitle').textContent = 'Preview: ' + (docName || 'Dokumen');
    
    // Tampilkan modal
    const modal = document.getElementById('imagePreviewModal');
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Reset state
    document.getElementById('previewLoading').style.display = 'block';
    document.getElementById('previewImageContainer').style.display = 'none';
    document.getElementById('previewError').style.display = 'none';
    document.getElementById('previewImage').src = '';
    
    // Set download link
    const downloadLink = document.getElementById('downloadLink');
    downloadLink.href = fullUrl;
    downloadLink.download = docName || 'dokumen';
    
    // Load gambar
    const img = new Image();
    
    // Set timeout untuk loading
    let loadTimeout = setTimeout(() => {
        showError('Waktu memuat gambar terlalu lama');
    }, 10000);
    
    img.onload = function() {
        clearTimeout(loadTimeout);
        
        // Sembunyikan loading
        document.getElementById('previewLoading').style.display = 'none';
        
        // Tampilkan gambar
        const previewImage = document.getElementById('previewImage');
        previewImage.src = fullUrl;
        previewImage.alt = docName || 'Preview Dokumen';
        document.getElementById('previewImageContainer').style.display = 'block';
    };
    
    img.onerror = function() {
        clearTimeout(loadTimeout);
        showError('Gagal memuat gambar. File mungkin tidak ditemukan atau tidak dapat diakses.');
    };
    
    // Tambahkan cache busting untuk mencegah cache
    const cacheBustedUrl = fullUrl + (fullUrl.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
    img.src = cacheBustedUrl;
}

function showError(message) {
    // Sembunyikan loading
    document.getElementById('previewLoading').style.display = 'none';
    
    // Tampilkan error
    document.getElementById('errorMessage').textContent = message;
    document.getElementById('previewError').style.display = 'block';
}

function closeImagePreview() {
    const modal = document.getElementById('imagePreviewModal');
    modal.classList.remove('show');
    document.body.style.overflow = '';
    
    // Reset
    document.getElementById('previewImage').src = '';
    document.getElementById('previewLoading').style.display = 'block';
    document.getElementById('previewImageContainer').style.display = 'none';
    document.getElementById('previewError').style.display = 'none';
}

// ============ EVENT HANDLERS ============
// Delegasi global untuk menstabilkan setiap file yang dipilih.
document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    stabilizeSelectedFile(target);
});
document.addEventListener('DOMContentLoaded', function() {
    // Toggle Sidebar Menu on Mobile
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
    
    // Close preview modal with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeImagePreview();
            if (logoutModal.classList.contains('show')) {
                hideLogoutModal();
            }
        }
    });
    
    // Close preview modal when clicking outside
    document.getElementById('imagePreviewModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeImagePreview();
        }
    });
    
    // File upload preview
    document.querySelectorAll('input[type="file"]').forEach(bindFilePreview);
    
    // Set kecamatan dropdown value dan load desa jika sudah ada nilai
    <?php if (!empty($permohonan['id_kecamatan'])): ?>
    setTimeout(() => {
        getDesaByKecamatan(<?= $permohonan['id_kecamatan'] ?>);
    }, 100);
    <?php endif; ?>
});

// ============ ANGGOTA KELUARGA FUNCTIONS ============
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
            <button type="button" class="btn-remove-anggotam" onclick="removeAnggota(this)">×</button>
        </td>
    `;
    tableBody.appendChild(newRow);
}

function removeAnggota(button) {
    if (confirm('Hapus anggota keluarga ini?')) {
        const row = button.closest('tr');
        row.remove();
    }
}

// ============ DOKUMEN OPSIONAL FUNCTIONS ============
let fileCounter = { opsional: 1 };

// Tampilkan nama file terpilih; dipakai juga oleh input opsional yang ditambah dinamis.
function bindFilePreview(input) {
    input.addEventListener('change', function() {
        const previewDiv = this.closest('.file-upload').querySelector('.file-preview');
        const nameSpan = document.createElement('span');
        nameSpan.className = 'file-name';
        if (this.files.length > 0) {
            const file = this.files[0];
            const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);

            // Validasi ukuran file
            if (file.size > 1 * 1024 * 1024) {
                alert(`File ${file.name} terlalu besar. Maksimal 1MB.`);
                this.value = '';
                nameSpan.textContent = 'File terlalu besar';
            } else {
                nameSpan.textContent = `${file.name} (${fileSizeMB} MB)`;
            }
        } else {
            nameSpan.textContent = 'Belum ada file dipilih';
        }
        previewDiv.replaceChildren(nameSpan);
    });
}
const MAX_FILE_SIZE = 1 * 1024 * 1024;

  // Stabilkan file upload lintas device/browser (terutama mobile/cloud picker)
  // dengan mengkloning file ke memori saat dipilih.
  function stabilizeSelectedFile(input) {
    return; // Upload HTML standar; kompatibel tanpa File/DataTransfer API.
    if (!input || !input.files || input.files.length === 0) {
      if (input) input.dataset.fileStable = '1';
      return;
    }

    const original = input.files[0];
    try {
        const buffer = null;
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

function addOptionalFileInput() {
    fileCounter.opsional++;
    const container = document.getElementById('opsionalUploadContainer');
    const newFileGroup = document.createElement('div');
    newFileGroup.className = 'file-input-group';
    newFileGroup.id = 'fileGroupOpsional' + fileCounter.opsional;
    newFileGroup.style.marginBottom = '15px';
    newFileGroup.innerHTML = `
        <select name="doc_type[]" class="file-type-select">
            <option value="">Pilih Jenis Dokumen (Opsional)</option>
            <option value="ktp">KTP</option>
            <option value="surat_nikah">Buku Nikah/Akta Nikah/Akta Cerai</option>
            <option value="akta_lahir">Akta Lahir</option>
            <option value="akta_kematian">Akta Kematian</option>
            <option value="surat_pindah">Surat Pindah Datang</option>
            <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div class="file-upload">
            <label class="file-upload-label">
                <i class="fas fa-upload"></i> Pilih File
                <input type="file" name="dokumen[]" accept=".jpg,.jpeg,.jfif,.jpe,.png">
            </label>
            <div class="file-preview">
                <span class="file-name">Belum ada file dipilih</span>
            </div>
        </div>
        <button type="button" class="btn-remove" onclick="removeOptionalFileInput(${fileCounter.opsional})">
            <i class="fas fa-trash"></i> Hapus
        </button>
    `;
    container.appendChild(newFileGroup);
    bindFilePreview(newFileGroup.querySelector('input[type="file"]'));
}

function removeOptionalFileInput(fileId) {
    if (fileId > 1) {
        const fileGroup = document.getElementById('fileGroupOpsional' + fileId);
        if (fileGroup && confirm('Hapus input dokumen ini?')) {
            fileGroup.remove();
        }
    } else {
        alert('Input dokumen pertama tidak dapat dihapus. Kosongkan saja pilihan filenya.');
    }
}

function removeExistingDocument(button, index) {
    if (confirm('Apakah Anda yakin ingin menghapus dokumen ini dari daftar?')) {
        const checkbox = button.closest('.document-item').querySelector('input[type="checkbox"]');
        checkbox.checked = false;
        button.closest('.document-item').style.display = 'none';
    }
}

// ============ GET DESA BY KECAMATAN ============
async function getDesaByKecamatan(kecamatanId) {
    const desaSelect = document.getElementById('desaSelect');
    if (!desaSelect) return;
    
    if (!kecamatanId || kecamatanId === '') {
        desaSelect.innerHTML = '<option value="">Pilih Kecamatan terlebih dahulu</option>';
        return;
    }
    
    // Show loading state
    desaSelect.innerHTML = '<option value="">Memuat data desa...</option>';
    desaSelect.disabled = true;
    
    try {
        const response = await fetch(`get_desa.php?id_kecamatan=${kecamatanId}`);
        
        if (response.ok) {
            const data = await response.text();
            desaSelect.innerHTML = '<option value="">Pilih Desa</option>' + data;
            
            // Set nilai desa jika ada
            <?php if (!empty($permohonan['id_desa'])): ?>
            setTimeout(() => {
                desaSelect.value = <?= $permohonan['id_desa'] ?>;
            }, 100);
            <?php endif; ?>
        } else {
            throw new Error('Gagal mengambil data desa');
        }
    } catch (error) {
        desaSelect.innerHTML = '<option value="">Error: Gagal mengambil data</option>';
    } finally {
        desaSelect.disabled = false;
    }
}

// Event listener untuk kecamatan select
const kecamatanSelect = document.getElementById('kecamatanSelect');
if (kecamatanSelect) {
    kecamatanSelect.addEventListener('change', function() {
        getDesaByKecamatan(this.value);
    });
}

// ============ FORM VALIDATION ============
document.getElementById('editKKForm').addEventListener('submit', function(e) {
    let isValid = true;
    
    // Clear previous error states
    document.querySelectorAll('.form-control').forEach(input => {
        input.classList.remove('is-invalid');
    });
    
    // Validate required fields
    const requiredFields = this.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        }
    });
    
    // Validate at least one anggota keluarga
    // Kartu saat formulir dibuat sistem, baris tabel pada tampilan lama.
    const anggotaRows = document.querySelectorAll('#anggotaWadah .anggota-kartu, #anggotaBody tr');
    let hasAnggota = false;
    anggotaRows.forEach(row => {
        const namaInput = row.querySelector('input[name="anggota_nama[]"]');
        if (namaInput && namaInput.value.trim()) {
            hasAnggota = true;
        }
    });
    
    // Pernyataan dan tanda tangan hanya ada saat formulir dibuat sistem.
    const cekPernyataan = document.getElementById('pernyataanBenar');
    if (cekPernyataan && !cekPernyataan.checked) {
        errorMessages.push('Centang pernyataan kebenaran data terlebih dahulu');
        isValid = false;
    }
    const inputTtd = document.getElementById('ttdPemohon');
    if (inputTtd && !inputTtd.value) {
        errorMessages.push('Tanda tangan pemohon wajib diisi ulang setiap kali data diubah');
        isValid = false;
    }

    if (!hasAnggota) {
        alert('Minimal harus ada satu anggota keluarga');
        isValid = false;
    }
    
    // Validate file sizes for new uploads (1MB)
    const fileInputs = this.querySelectorAll('input[type="file"]');
    for (let input of fileInputs) {
        if (input.files.length > 0) {
            if (input.dataset.fileStable === '0') {
                // Fallback mode: jangan blok submit, karena beberapa browser mobile
                // gagal clone file meski file lokal tetap valid untuk upload normal.
                console.warn(`File "${input.files[0].name}" tidak bisa distabilkan, lanjut upload mode normal.`);
            }            const file = input.files[0];
            if (file.size > 1 * 1024 * 1024) {
                alert(`File ${file.name} terlalu besar (maksimal 1MB)`);
                isValid = false;
                break;
            }
        }
    }
    
    // Cek dokumen wajib Formulir F-1.01
    // Saat formulir dibuat sistem, tidak ada input unggahan formulir sama sekali.
    const formulirOtomatis = <?= FORMULIR_F101_AUTO_ENABLED ? 'true' : 'false' ?>;
    const formulirInput = document.querySelector('input[name="doc_type[]"][value="formulir_f101"]')?.parentElement?.querySelector('input[type="file"]');
    const hasFormulir = formulirOtomatis || formulirInput?.files?.length > 0 || <?= !empty($formulir_file) ? 'true' : 'false' ?>;
    
    if (!hasFormulir) {
        alert('Dokumen Formulir F-1.01 wajib diunggah!');
        isValid = false;
    }
    
    // Cek dokumen wajib KK Lama
    const kkLamaInput = document.querySelector('input[name="doc_type[]"][value="kk_lama_atau_surat_kehilangan"]')?.parentElement?.querySelector('input[type="file"]');
    const hasKKLama = kkLamaInput?.files?.length > 0 || <?= !empty($kk_lama_file) ? 'true' : 'false' ?>;
    
    if (!hasKKLama) {
        alert('Dokumen KK Lama atau Surat Keterangan Kehilangan KK wajib diunggah!');
        isValid = false;
    }

    const fotoSelfieInput = document.querySelector('input[name="doc_type[]"][value="foto_selfie"]')?.parentElement?.querySelector('input[type="file"]');
    const hasFotoSelfie = fotoSelfieInput?.files?.length > 0 || <?= !empty($foto_selfie_file) ? 'true' : 'false' ?>;

    if (!hasFotoSelfie) {
        alert('Dokumen Foto Selfie Pemohon Memegang Formulir wajib diunggah!');
        isValid = false;
    }
    
    if (!isValid) {
        e.preventDefault();
        alert('Harap lengkapi semua bidang yang wajib diisi dan periksa file yang diunggah!');
    } else {
        // Konfirmasi sebelum submit
        const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan kembali permohonan?');
        if (!confirmSubmit) {
            e.preventDefault();
        }
    }
});
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php if (FORMULIR_F101_AUTO_ENABLED): ?>
<script src="../assets/js/anggota_kk.js?v=20260920-1"></script>
<script src="../assets/js/formulir_ttd.js?v=20260919-1" data-form="editKKForm" data-pratinjau="pratinjau_formulir_kk.php"></script>
<?php endif; ?>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>


