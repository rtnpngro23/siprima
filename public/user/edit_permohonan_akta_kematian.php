<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';
require_once __DIR__ . '/../functions/formulir_f201_kematian.php';
require_once __DIR__ . '/../../app/config/document_features.php';

// Selaras dengan akta_kematian.php: tanpa kertas, selfie beralih ke KTP/KK.
$labelSelfie = FORMULIR_F201_KEMATIAN_AUTO_ENABLED
    ? 'Foto Selfie Pemohon Memegang KTP/Kartu Keluarga'
    : 'Foto Selfie Pemohon Memegang Formulir';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kematian LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}

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

// Ambil data permohonan berdasarkan ID - TAMBAH nama_folder
$stmt = $koneksi->prepare("SELECT 
    id, 
    id_kecamatan,
    id_desa,
    dusun,
    Nama_meninggal, 
    Nik_meninggal,
    tanggal_kematian,
    Tempat,
    Nama_Ayah, 
    Nik_ayah, 
    Nama_Ibu,
    Nik_ibu,
    nama_saksi1,
    nik_saksi1,
    nama_saksi2,
    nik_saksi2,
    keterangan,
    dokumen, 
    status,
    alasan,
    nama_folder  -- TAMBAHKAN INI
    FROM tb_akta_kematian 
    WHERE id = ? AND id_user = ?");
$stmt->bind_param('ii', $permohonan_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header('Location: status_permohonan.php?error=not_found');
    exit;
}

$permohonan = $res->fetch_assoc();

// Hanya izinkan edit saat status diajukan atau ditolak
if (!in_array(strtolower((string)$permohonan['status']), ['diajukan', 'ditolak'], true)) {
    header('Location: status_permohonan.php?error=cannot_edit');
    exit;
}

// Dekode data JSON untuk dokumen
$dokumen_data = [];
if (!empty($permohonan['dokumen'])) {
    $dokumen_data = json_decode($permohonan['dokumen'], true);
    if (!is_array($dokumen_data)) {
        $dokumen_data = [];
    }
}

// TENTUKAN FOLDER UPLOAD BERDASARKAN nama_folder YANG SUDAH ADA
$folder_name = $permohonan['nama_folder'] ?? '';
$upload_dir = '';
$relative_upload_dir = '';

if (!empty($folder_name)) {
    // Gunakan folder yang sudah ada
    $upload_dir = __DIR__ . '/uploads/akta_kematian/' . $folder_name . '/';
    $relative_upload_dir = 'uploads/akta_kematian/' . $folder_name . '/';
    
    // Pastikan folder ada
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
} else {
    // Fallback: buat folder berdasarkan format seperti di akta_kematian.php
    // Ambil data user untuk mendapatkan nama dan NIK pemohon
    $user_query = $koneksi->prepare("SELECT nama, nik FROM tb_users WHERE id = ?");
    $user_query->bind_param('i', $user_id);
    $user_query->execute();
    $user_result = $user_query->get_result();
    $user_data = $user_result->fetch_assoc();
    
    // Ambil nama kecamatan dan desa
    $nama_kecamatan = '';
    $nama_desa = '';
    
    if (!empty($permohonan['id_kecamatan'])) {
        $kec_stmt = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $kec_stmt->bind_param('i', $permohonan['id_kecamatan']);
        $kec_stmt->execute();
        $kec_stmt->bind_result($nama_kecamatan);
        $kec_stmt->fetch();
        $kec_stmt->close();
    }
    
    if (!empty($permohonan['id_desa'])) {
        $desa_stmt = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $desa_stmt->bind_param('i', $permohonan['id_desa']);
        $desa_stmt->execute();
        $desa_stmt->bind_result($nama_desa);
        $desa_stmt->fetch();
        $desa_stmt->close();
    }
    
    // Buat nama folder sesuai format di akta_kematian.php
    $folder_name = str_replace(' ', '_', strtolower(trim($nama_kecamatan))) . '_' . 
                   str_replace(' ', '_', strtolower(trim($nama_desa))) . '_' . 
                   ($user_data['nik'] ?? $user_id) . '_' . 
                   str_replace(' ', '_', strtolower(trim($user_data['nama'] ?? 'user'))) . '_' . 
                   str_replace(' ', '_', strtolower(trim($permohonan['Nama_meninggal'] ?? 'meninggal'))) . '_' . 
                   date('Ymd_His');
    
    $upload_dir = __DIR__ . '/uploads/akta_kematian/' . $folder_name . '/';
    $relative_upload_dir = 'uploads/akta_kematian/' . $folder_name . '/';
    
    // Buat folder
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
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
    $nama_meninggal = trim($_POST['nama_meninggal'] ?? '');
    $nik_meninggal = trim($_POST['nik_meninggal'] ?? '');
    $tempat_kematian = trim($_POST['tempat_kematian'] ?? '');
    $tanggal_kematian = trim($_POST['tanggal_kematian'] ?? '');
    $nama_ayah = trim($_POST['nama_ayah'] ?? '');
    $nik_ayah = trim($_POST['nik_ayah'] ?? '');
    $nama_ibu = trim($_POST['nama_ibu'] ?? '');
    $nik_ibu = trim($_POST['nik_ibu'] ?? '');
    $nama_saksi1 = trim($_POST['nama_saksi1'] ?? '');
    $nik_saksi1 = trim($_POST['nik_saksi1'] ?? '');
    $nama_saksi2 = trim($_POST['nama_saksi2'] ?? '');
    $nik_saksi2 = trim($_POST['nik_saksi2'] ?? '');
    $id_kecamatan = (int)($_POST['id_kecamatan'] ?? 0);
    $id_desa = (int)($_POST['id_desa'] ?? 0);
    $dusun = trim($_POST['dusun'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validasi
    if (empty($nama_meninggal)) $errors[] = "Nama yang meninggal harus diisi";
    if (empty($nik_meninggal)) $errors[] = "NIK yang meninggal harus diisi";
    if (empty($tempat_kematian)) $errors[] = "Tempat kematian harus diisi";
    if (empty($tanggal_kematian)) $errors[] = "Tanggal kematian harus diisi";
    if (empty($nama_ayah)) $errors[] = "Nama ayah harus diisi";
    if (empty($nik_ayah)) $errors[] = "NIK ayah harus diisi";
    if (empty($nama_ibu)) $errors[] = "Nama ibu harus diisi";
    if (empty($nik_ibu)) $errors[] = "NIK ibu harus diisi";
    // Saksi 1 dan Saksi 2 wajib (nama + NIK) — sebelumnya halaman edit tidak punya field saksi
    // sama sekali, sehingga data saksi tidak pernah bisa diperbaiki pemohon. Ditambahkan di sini,
    // konsisten dengan halaman ajuan (akta_kematian.php).
    if (empty($nama_saksi1)) $errors[] = "Nama saksi 1 harus diisi";
    if (empty($nik_saksi1)) $errors[] = "NIK saksi 1 harus diisi";
    if (empty($nama_saksi2)) $errors[] = "Nama saksi 2 harus diisi";
    if (empty($nik_saksi2)) $errors[] = "NIK saksi 2 harus diisi";
    if (empty($id_kecamatan)) $errors[] = "Kecamatan harus dipilih";
    if (empty($id_desa)) $errors[] = "Desa harus dipilih";
    
    // Validasi format tanggal
    if (!empty($tanggal_kematian)) {
        $date = DateTime::createFromFormat('Y-m-d', $tanggal_kematian);
        if (!$date || $date->format('Y-m-d') !== $tanggal_kematian) {
            $errors[] = "Format tanggal kematian tidak valid. Gunakan format: YYYY-MM-DD";
        }
    }

    // Validasi format NIK saksi (16 digit)
    if (!empty($nik_saksi1) && !preg_match('/^[0-9]{16}$/', $nik_saksi1)) {
        $errors[] = "NIK saksi 1 harus 16 digit angka";
    }
    if (!empty($nik_saksi2) && !preg_match('/^[0-9]{16}$/', $nik_saksi2)) {
        $errors[] = "NIK saksi 2 harus 16 digit angka";
    }

    // Pernyataan dan tanda tangan WAJIB diulang setiap kali data diubah: tanda tangan hanya
    // berlaku untuk versi dokumen yang ditandatanganinya.
    $f201k_signature = null;
    $formulir_dibuat_at = null;
    if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED) {
        if (empty($_POST['pernyataan_benar'])) {
            $errors[] = 'Centang pernyataan kebenaran data sebelum mengajukan kembali.';
        }
        $pesan_ttd = siprima_formulir_validate_signature((string)($_POST['ttd_pemohon'] ?? ''), $f201k_signature);
        if ($pesan_ttd !== '') $errors[] = $pesan_ttd;
    }

    // Handle upload dokumen - GUNAKAN FOLDER YANG SUDAH ADA
    $dokumen_baru = [];
    $dokumen_counter = 0;
    
    // Proses dokumen yang diupload
    $dokumen_counter = 0;
    
    // Dokumen Surat Keterangan Kematian (wajib)
    if (!empty($_FILES['dokumen_surat_kematian']['name'])) {
        $surat_file = $_FILES['dokumen_surat_kematian'];
        $surat_ext = pathinfo($surat_file['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['jpg', 'jpeg', 'png'];
        
        if (in_array(strtolower($surat_ext), $allowed_ext)) {
            if ($surat_file['size'] <= 1 * 1024 * 1024) { // 1MB
                $surat_filename = 'surat_kematian_' . $user_id . '_' . time() . '.' . $surat_ext;
                $surat_path = $upload_dir . $surat_filename;
                $surat_relative_path = $relative_upload_dir . $surat_filename;
                
                if (move_uploaded_file($surat_file['tmp_name'], $surat_path)) {
                    $dokumen_baru[] = [
                        'type' => 'surat_kematian',
                        'original_name' => $surat_file['name'],
                        'path' => $surat_relative_path
                    ];
                    $dokumen_counter++;
                } else {
                    $errors[] = "Gagal mengupload Surat Keterangan Kematian";
                }
            } else {
                $errors[] = "Ukuran file Surat Keterangan Kematian terlalu besar (maksimal 1MB)";
            }
        } else {
            $errors[] = "Format file Surat Keterangan Kematian tidak didukung. Gunakan JPG, JPEG, atau PNG";
        }
    } else {
        // Cari Surat Keterangan Kematian dari dokumen lama
        $has_surat = false;
        foreach ($dokumen_data as $doc) {
            if (in_array($doc['type'] ?? '', ['surat_kematian', 'surat_keterangan_kematian'])) {
                $dokumen_baru[] = $doc;
                $dokumen_counter++;
                $has_surat = true;
                break;
            }
        }
        
        if (!$has_surat) {
            $errors[] = "Dokumen Surat Keterangan Kematian wajib diunggah";
        }
    }
    
    // Dokumen Formulir F-2.01 Kematian (wajib).
    // Saat flag mati: perilaku lama persis, tidak disentuh.
    // Saat flag hidup: formulir TIDAK PERNAH diterima sebagai unggahan manual atau dipertahankan
    // dari dokumen lama — ia selalu dibuat ulang oleh sistem (blok render di bawah, dekat UPDATE).
    // Path dokumen lama dicatat di $formulirLamaPath untuk dihapus SETELAH UPDATE sukses.
    $formulirLamaPath = null;
    if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED) {
        // Pemalsuan: tolak tegas bila klien tetap mengirim unggahan formulir manual.
        if (!empty($_FILES['dokumen_formulir_f201']['name'])) {
            $errors[] = 'Jenis dokumen tidak valid.';
        }
        foreach ($dokumen_data as $doc) {
            if (in_array($doc['type'] ?? '', ['formulir_f201', 'formulir_f-201', 'f201', 'f-201'], true)) {
                $formulirLamaPath = (string)($doc['path'] ?? '');
                break;
            }
        }
    } elseif (!empty($_FILES['dokumen_formulir_f201']['name'])) {
        $f201_file = $_FILES['dokumen_formulir_f201'];
        $f201_ext = pathinfo($f201_file['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['jpg', 'jpeg', 'png'];

        if (in_array(strtolower($f201_ext), $allowed_ext)) {
            if ($f201_file['size'] <= 1 * 1024 * 1024) { // 1MB
                $f201_filename = 'formulir_f201_' . $user_id . '_' . time() . '.' . $f201_ext;
                $f201_path = $upload_dir . $f201_filename;
                $f201_relative_path = $relative_upload_dir . $f201_filename;

                if (move_uploaded_file($f201_file['tmp_name'], $f201_path)) {
                    $dokumen_baru[] = [
                        'type' => 'formulir_f201',
                        'original_name' => $f201_file['name'],
                        'path' => $f201_relative_path
                    ];
                    $dokumen_counter++;
                } else {
                    $errors[] = "Gagal mengupload Formulir F-2.01 Kematian";
                }
            } else {
                $errors[] = "Ukuran file Formulir F-2.01 Kematian terlalu besar (maksimal 1MB)";
            }
        } else {
            $errors[] = "Format file Formulir F-2.01 Kematian tidak didukung. Gunakan JPG, JPEG, atau PNG";
        }
    } else {
        // Cari Formulir F-2.01 dari dokumen lama
        $has_f201 = false;
        foreach ($dokumen_data as $doc) {
            if (in_array($doc['type'] ?? '', ['formulir_f201', 'formulir_f-201', 'f201', 'f-201'])) {
                $dokumen_baru[] = $doc;
                $dokumen_counter++;
                $has_f201 = true;
                break;
            }
        }

        if (!$has_f201) {
            $errors[] = "Dokumen Formulir F-2.01 Kematian wajib diunggah";
        }
    }
    
    // Dokumen Identitas yang Meninggal (wajib)
    if (!empty($_FILES['dokumen_identitas']['name'])) {
        $identitas_file = $_FILES['dokumen_identitas'];
        $identitas_ext = pathinfo($identitas_file['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['jpg', 'jpeg', 'png'];
        
        if (in_array(strtolower($identitas_ext), $allowed_ext)) {
            if ($identitas_file['size'] <= 1 * 1024 * 1024) { // 1MB
                $identitas_filename = 'identitas_' . $user_id . '_' . time() . '.' . $identitas_ext;
                $identitas_path = $upload_dir . $identitas_filename;
                $identitas_relative_path = $relative_upload_dir . $identitas_filename;
                
                if (move_uploaded_file($identitas_file['tmp_name'], $identitas_path)) {
                    $dokumen_baru[] = [
                        'type' => 'identitas_meninggal',
                        'original_name' => $identitas_file['name'],
                        'path' => $identitas_relative_path
                    ];
                    $dokumen_counter++;
                } else {
                    $errors[] = "Gagal mengupload Identitas yang Meninggal";
                }
            } else {
                $errors[] = "Ukuran file Identitas yang Meninggal terlalu besar (maksimal 1MB)";
            }
        } else {
            $errors[] = "Format file Identitas yang Meninggal tidak didukung. Gunakan JPG, JPEG, atau PNG";
        }
    } else {
        // Cari Identitas dari dokumen lama
        $has_identitas = false;
        foreach ($dokumen_data as $doc) {
            if (in_array($doc['type'] ?? '', ['identitas_meninggal', 'ktp_meninggal', 'kk_meninggal'])) {
                $dokumen_baru[] = $doc;
                $dokumen_counter++;
                $has_identitas = true;
                break;
            }
        }
        
        if (!$has_identitas) {
            $errors[] = "Dokumen Identitas yang Meninggal (KK/KTP) wajib diunggah";
        }
    }

    // Dokumen Foto Selfie Pemohon Memegang Formulir (wajib)
    if (!empty($_FILES['dokumen_foto_selfie']['name'])) {
        $selfie_file_new = $_FILES['dokumen_foto_selfie'];
        $selfie_ext = pathinfo($selfie_file_new['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['jpg', 'jpeg', 'png'];

        if (in_array(strtolower($selfie_ext), $allowed_ext)) {
            if ($selfie_file_new['size'] <= 1 * 1024 * 1024) { // 1MB
                $selfie_filename = 'foto_selfie_' . $user_id . '_' . time() . '.' . $selfie_ext;
                $selfie_path = $upload_dir . $selfie_filename;
                $selfie_relative_path = $relative_upload_dir . $selfie_filename;

                if (move_uploaded_file($selfie_file_new['tmp_name'], $selfie_path)) {
                    $dokumen_baru[] = [
                        'type' => 'foto_selfie',
                        'original_name' => $selfie_file_new['name'],
                        'path' => $selfie_relative_path
                    ];
                    $dokumen_counter++;
                } else {
                    $errors[] = "Gagal mengupload Foto Selfie Pemohon";
                }
            } else {
                $errors[] = "Ukuran file Foto Selfie Pemohon terlalu besar (maksimal 1MB)";
            }
        } else {
            $errors[] = "Format file Foto Selfie Pemohon tidak didukung. Gunakan JPG, JPEG, atau PNG";
        }
    } else {
        // Cari Foto Selfie dari dokumen lama
        $has_selfie = false;
        foreach ($dokumen_data as $doc) {
            if (($doc['type'] ?? '') === 'foto_selfie') {
                $dokumen_baru[] = $doc;
                $dokumen_counter++;
                $has_selfie = true;
                break;
            }
        }

        if (!$has_selfie) {
            $errors[] = "Dokumen Foto Selfie Pemohon Memegang Formulir wajib diunggah";
        }
    }
    
    // Proses dokumen tambahan dari input dinamis
    if (isset($_FILES['dokumen_tambahan']) && !empty($_FILES['dokumen_tambahan']['name'][0])) {
        $total_files = count($_FILES['dokumen_tambahan']['name']);
        
        for ($i = 0; $i < $total_files; $i++) {
            if ($_FILES['dokumen_tambahan']['error'][$i] === 0) {
                $file_name = $_FILES['dokumen_tambahan']['name'][$i];
                $file_tmp = $_FILES['dokumen_tambahan']['tmp_name'][$i];
                $file_size = $_FILES['dokumen_tambahan']['size'][$i];
                
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png'];
                
                if (in_array($file_ext, $allowed_ext)) {
                    if ($file_size <= 1 * 1024 * 1024) { // 1MB
                        $new_filename = 'dokumen_' . $user_id . '_' . time() . '_' . $i . '.' . $file_ext;
                        $upload_path = $upload_dir . $new_filename;
                        $relative_path = $relative_upload_dir . $new_filename;
                        
                        if (move_uploaded_file($file_tmp, $upload_path)) {
                            $dokumen_type = $_POST['dokumen_type'][$i] ?? 'lainnya';
                            
                            $dokumen_baru[] = [
                                'type' => $dokumen_type,
                                'original_name' => $file_name,
                                'path' => $relative_path
                            ];
                            $dokumen_counter++;
                        } else {
                            $errors[] = "Gagal mengupload dokumen: " . $file_name;
                        }
                    } else {
                        $errors[] = "Ukuran file $file_name terlalu besar (maksimal 1MB)";
                    }
                } else {
                    $errors[] = "Format file $file_name tidak didukung. Gunakan JPG, JPEG, atau PNG";
                }
            }
        }
    }
    
    // Tambahkan dokumen lama yang tidak dihapus
    if (isset($_POST['existing_docs']) && is_array($_POST['existing_docs'])) {
        foreach ($_POST['existing_docs'] as $doc_index) {
            if (isset($dokumen_data[$doc_index])) {
                $doc = $dokumen_data[$doc_index];
                $doc_type = $doc['type'] ?? '';
                
                // Skip dokumen wajib yang sudah diproses di atas
                $is_surat = in_array($doc_type, ['surat_kematian', 'surat_keterangan_kematian']);
                $is_f201 = in_array($doc_type, ['formulir_f201', 'formulir_f-201', 'f201', 'f-201']);
                $is_identitas = in_array($doc_type, ['identitas_meninggal', 'ktp_meninggal', 'kk_meninggal']);
                $is_selfie = $doc_type === 'foto_selfie';
                
                if (!$is_surat && !$is_f201 && !$is_identitas && !$is_selfie) {
                    $dokumen_baru[] = $doc;
                    $dokumen_counter++;
                }
            }
        }
    }
    
    // Jika tidak ada error, update data
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        // Ambil nama kecamatan dan desa
        $nama_kecamatan = '';
        $nama_desa = '';
        
        $kec_stmt = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $kec_stmt->bind_param('i', $id_kecamatan);
        $kec_stmt->execute();
        $kec_stmt->bind_result($nama_kecamatan);
        $kec_stmt->fetch();
        $kec_stmt->close();
        
        $desa_stmt = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $desa_stmt->bind_param('i', $id_desa);
        $desa_stmt->execute();
        $desa_stmt->bind_result($nama_desa);
        $desa_stmt->fetch();
        $desa_stmt->close();

        // Formulir dibuat setelah seluruh unggahan/validasi final, sebelum UPDATE: kegagalan render
        // mencegah penyimpanan data (dokumen dan baris DB tidak pernah saling bertentangan).
        $formulirBaruName = null;
        if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED) {
            try {
                $pu_stmt = $koneksi->prepare("SELECT nama, nik FROM tb_users WHERE id = ?");
                $pu_stmt->bind_param('i', $user_id);
                $pu_stmt->execute();
                $pemohon = $pu_stmt->get_result()->fetch_assoc() ?: [];
                $pu_stmt->close();

                $formulir_dibuat_at = date('Y-m-d H:i:s');
                $formulirJpeg = siprima_f201_kematian_render(
                    siprima_f201_kematian_data(
                        array_merge($_POST, ['nama_meninggal' => $nama_meninggal, 'nik_meninggal' => $nik_meninggal,
                            'tempat' => $tempat_kematian, 'tanggal_kematian' => $tanggal_kematian,
                            'nama_ayah' => $nama_ayah, 'nik_ayah' => $nik_ayah,
                            'nama_ibu' => $nama_ibu, 'nik_ibu' => $nik_ibu,
                            'nama_saksi1' => $nama_saksi1, 'nik_saksi1' => $nik_saksi1,
                            'nama_saksi2' => $nama_saksi2, 'nik_saksi2' => $nik_saksi2]),
                        $pemohon,
                        (string)$nama_kecamatan,
                        (string)$nama_desa,
                        $formulir_dibuat_at
                    ),
                    (string)$f201k_signature
                );

                $formulirBaruName = 'formulir_f201_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                if (file_put_contents($upload_dir . $formulirBaruName, $formulirJpeg) === false) {
                    throw new RuntimeException('Formulir gagal ditulis ke folder permohonan.');
                }

                array_unshift($dokumen_baru, [
                    'path' => $relative_upload_dir . $formulirBaruName,
                    'type' => 'formulir_f201',
                    'original_name' => 'Formulir F-2.01 Kematian (dibuat sistem)',
                    'generated' => true,
                ]);
            } catch (Throwable $e) {
                error_log('Formulir F-2.01 Kematian render error (edit): ' . $e->getMessage());
                $errors[] = 'Formulir belum dapat dibuat. Silakan ulangi tanda tangan Anda lalu coba lagi.';
                $formulirBaruName = null;
            }
        }

        if (!empty($errors)) {
            if ($formulirBaruName !== null && is_file($upload_dir . $formulirBaruName)) {
                @unlink($upload_dir . $formulirBaruName);
            }
        } else {

        // Update data permohonan
        $stmt = $koneksi->prepare("UPDATE tb_akta_kematian SET 
            Nama_meninggal = ?,
            Nik_meninggal = ?,
            tanggal_kematian = ?,
            Tempat = ?,
            Nama_Ayah = ?,
            Nik_ayah = ?,
            Nama_Ibu = ?,
            Nik_ibu = ?,
            nama_saksi1 = ?,
            nik_saksi1 = ?,
            nama_saksi2 = ?,
            nik_saksi2 = ?,
            id_kecamatan = ?,
            id_desa = ?,
            dusun = ?,
            keterangan = ?,
            dokumen = ?,
            nama_kecamatan = ?,
            nama_desa = ?,
            nama_folder = ?,  -- SIMPAN nama_folder YANG SAMA
            formulir_dibuat_at = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?");

        $dokumen_json = json_encode($dokumen_baru);

        // 23 parameter, urut sama dengan daftar SET di atas lalu WHERE id, id_user.
        $stmt->bind_param('ssssssssssssiisssssssii',
            $nama_meninggal,
            $nik_meninggal,
            $tanggal_kematian,
            $tempat_kematian,
            $nama_ayah,
            $nik_ayah,
            $nama_ibu,
            $nik_ibu,
            $nama_saksi1,
            $nik_saksi1,
            $nama_saksi2,
            $nik_saksi2,
            $id_kecamatan,
            $id_desa,
            $dusun,
            $keterangan,
            $dokumen_json,
            $nama_kecamatan,
            $nama_desa,
            $folder_name,  // Gunakan folder_name yang sudah ada
            $formulir_dibuat_at,
            $permohonan_id,
            $user_id
        );
        
        if ($stmt->execute()) {
            // Formulir lama dibuang hanya setelah data baru benar-benar tersimpan.
            if ($formulirLamaPath !== null) {
                $lama = realpath($upload_dir . basename($formulirLamaPath));
                $akar = realpath(__DIR__ . '/uploads/akta_kematian');
                if ($lama !== false && $akar !== false
                    && strncmp($lama, $akar . DIRECTORY_SEPARATOR, strlen($akar) + 1) === 0
                    && basename($lama) !== $formulirBaruName
                    && is_file($lama)) {
                    @unlink($lama);
                }
            }
            logPermohonanStatusHistory(
                $koneksi,
                'tb_akta_kematian',
                $permohonan_id,
                'diajukan',
                null,
                (int)($_SESSION['id_user'] ?? 0),
                (string)($_SESSION['role'] ?? 'user'),
                'resubmit'
            );
            $_SESSION['success_message'] = "Permohonan akta kematian berhasil diperbarui dan diajukan kembali!";
            header('Location: status_permohonan.php');
            exit;
        } else {
            $errors[] = "Gagal memperbarui data: " . $stmt->error;
            // Formulir yang baru dirender tidak jadi dipakai: jangan tinggalkan berkas yatim.
            if ($formulirBaruName !== null && is_file($upload_dir . $formulirBaruName)) {
                @unlink($upload_dir . $formulirBaruName);
            }
        }
        $stmt->close();
        }
    }
}

// Fungsi helper untuk mendapatkan absolute path
function getAbsoluteFilePath($relativePath) {
    if (strpos($relativePath, '/') === 0 || preg_match('#^[a-z]:#i', $relativePath)) {
        return $relativePath;
    }
    return __DIR__ . '/' . $relativePath;
}

// Fungsi helper
function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function selected($value, $compare) {
    return $value == $compare ? 'selected' : '';
}

// Cari dokumen wajib untuk ditampilkan di form
$surat_file = null;
$f201_file = null;
$identitas_file = null;
$selfie_file = null;

foreach ($dokumen_data as $doc) {
    $doc_type = $doc['type'] ?? '';
    
    if (in_array($doc_type, ['surat_kematian', 'surat_keterangan_kematian']) && !$surat_file) {
        $surat_file = $doc;
    } elseif (in_array($doc_type, ['formulir_f201', 'formulir_f-201', 'f201', 'f-201']) && !$f201_file) {
        $f201_file = $doc;
    } elseif (in_array($doc_type, ['identitas_meninggal', 'ktp_meninggal', 'kk_meninggal']) && !$identitas_file) {
        $identitas_file = $doc;
    } elseif ($doc_type === 'foto_selfie' && !$selfie_file) {
        $selfie_file = $doc;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Permohonan Akta Kematian</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/edit_permohonan_akta_kematian.css">
    <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
    <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-line">

<div class="layout">
    <!-- Sidebar -->
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header user-general-header">
            <?php
            require_once __DIR__ . '/user_header_brand.php';
            render_user_header_brand('Edit Permohonan Akta Kematian');
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
                    <h2>Edit Permohonan Akta Kematian</h2>
                    <span class="status-badge">Status: <?= esc(ucfirst(strtolower((string)($permohonan['status'] ?? '-')))) ?></span>
                </div>

                <form method="POST" enctype="multipart/form-data" id="editAktaForm">
<?php echo csrf_input(); ?>
                    <!-- Data Yang Meninggal -->
                    <div class="form-section">
                        <h3 class="section-title">Data Yang Meninggal</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Yang Meninggal <span class="required">*</span></label>
                                <input type="text" name="nama_meninggal" class="form-control" 
                                       value="<?= esc($permohonan['Nama_meninggal'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Yang Meninggal <span class="required">*</span></label>
                                <input type="text" name="nik_meninggal" class="form-control" 
                                       value="<?= esc($permohonan['Nik_meninggal'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Tempat Kematian <span class="required">*</span></label>
                                <input type="text" name="tempat_kematian" class="form-control" 
                                       value="<?= esc($permohonan['Tempat'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Tanggal Kematian <span class="required">*</span></label>
                                <input type="date" name="tanggal_kematian" class="form-control" 
                                       value="<?= esc($permohonan['tanggal_kematian'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Data Orang Tua -->
                    <div class="form-section">
                        <h3 class="section-title">Data Orang Tua</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nama Ayah <span class="required">*</span></label>
                                <input type="text" name="nama_ayah" class="form-control" 
                                       value="<?= esc($permohonan['Nama_Ayah'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Ayah <span class="required">*</span></label>
                                <input type="text" name="nik_ayah" class="form-control" 
                                       value="<?= esc($permohonan['Nik_ayah'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Nama Ibu <span class="required">*</span></label>
                                <input type="text" name="nama_ibu" class="form-control" 
                                       value="<?= esc($permohonan['Nama_Ibu'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>NIK Ibu <span class="required">*</span></label>
                                <input type="text" name="nik_ibu" class="form-control"
                                       value="<?= esc($permohonan['Nik_ibu'] ?? '') ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Nama Saksi 1 <span class="required">*</span></label>
                                <input type="text" name="nama_saksi1" class="form-control"
                                       value="<?= esc($permohonan['nama_saksi1'] ?? '') ?>" required>
                            </div>

                            <div class="form-group">
                                <label>NIK Saksi 1 <span class="required">*</span></label>
                                <input type="text" name="nik_saksi1" class="form-control"
                                       value="<?= esc($permohonan['nik_saksi1'] ?? '') ?>" required
                                       maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                            </div>

                            <div class="form-group">
                                <label>Nama Saksi 2 <span class="required">*</span></label>
                                <input type="text" name="nama_saksi2" class="form-control"
                                       value="<?= esc($permohonan['nama_saksi2'] ?? '') ?>" required>
                            </div>

                            <div class="form-group">
                                <label>NIK Saksi 2 <span class="required">*</span></label>
                                <input type="text" name="nik_saksi2" class="form-control"
                                       value="<?= esc($permohonan['nik_saksi2'] ?? '') ?>" required
                                       maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                            </div>
                        </div>
                    </div>

                    <?php if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
                    <!-- Pernyataan & tanda tangan: wajib diulang karena data berubah -->
                    <div class="form-section" id="blokFormulir">
                        <h3 class="section-title">Pernyataan dan Tanda Tangan</h3>
                        <p class="helper" style="margin-top: 0;">
                            Karena data berubah, formulir F-2.01 akan dibuat ulang oleh sistem dan Anda perlu
                            menandatanganinya kembali. Tanda tangan hanya berlaku untuk versi dokumen yang Anda tandatangani.
                        </p>

                        <label style="display: flex; gap: 10px; align-items: flex-start; margin: 16px 0; cursor: pointer;">
                            <input type="checkbox" id="pernyataanBenar" name="pernyataan_benar" value="1" required
                                   style="margin-top: 4px; width: 18px; height: 18px; flex-shrink: 0;"
                                   <?= !empty($_POST['pernyataan_benar']) ? 'checked' : '' ?>>
                            <span>
                                Saya menyatakan bahwa seluruh data yang saya isi di atas adalah benar dan saya
                                bertanggung jawab sepenuhnya atas kebenaran data tersebut.
                                <span class="required">*</span>
                            </span>
                        </label>

                        <div id="areaTtd" style="opacity: .5; pointer-events: none;">
                            <label class="field-label">Tanda Tangan Pemohon <span class="required">*</span></label>
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

                    <!-- Data Domisili -->
                    <div class="form-section">
                        <h3 class="section-title">Data Domisili</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Kecamatan <span class="required">*</span></label>
                                <select name="id_kecamatan" class="form-control" 
                                        onchange="getDesaByKecamatan(this.value)" required>
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
                                <select name="id_desa" id="desa-select" class="form-control" required>
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
                                <label>Dusun/Lingkungan</label>
                                <input type="text" name="dusun" class="form-control" 
                                       value="<?= esc($permohonan['dusun'] ?? '') ?>" 
                                       placeholder="Contoh: Dusun Mekar Jaya">
                            </div>
                        </div>
                    </div>

                    <!-- Dokumen Pendukung -->
                    <div class="form-section">
                        <h3 class="section-title">Dokumen Pendukung</h3>
                        
                        <!-- Info dokumen -->
                        <div style="background: #fef2f2; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fee2e2;">
                            <p style="margin: 0 0 10px 0; color: #dc2626;"><strong>Petunjuk:</strong></p>
                            <ul style="margin: 0; padding-left: 20px; color: #4b5563;">
                                <li>Dokumen yang <strong>wajib</strong> diunggah:
                                    <ol style="margin: 5px 0 0 0; padding-left: 20px;">
                                        <?php if (!FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
                                        <li>Formulir F-2.01 Kematian</li>
                                        <?php endif; ?>
                                        <li>Surat Keterangan Kematian dari RS/Puskes/Desa/Kelurahan</li>
                                        <li>KK/KTP/Surat Kehilangan KK/KTP yang meninggal</li>
                                        <li><?= esc($labelSelfie) ?></li>
                                    </ol>
                                    <?php if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
                                    <p class="f-doc-group__catatan">Formulir F-2.01 dibuat ulang otomatis oleh sistem setiap kali Anda menyimpan perubahan.</p>
                                    <?php endif; ?>
                                </li>
                                <li>Dokumen tambahan (jika ada): KTP Ayah, KTP Ibu, KTP Saksi, dll</li>
                                <li>Format file yang diperbolehkan: JPG, JPEG, PNG</li>
                                <li>Ukuran maksimal per file: 1MB</li>
                            </ul>
                        </div>

                        <!-- Dokumen Wajib: Foto Selfie Pemohon -->
                        <div class="form-group">
                            <label><?= esc($labelSelfie) ?> <span class="required">*</span></label>
                            <div class="file-upload">
                                <label class="file-upload-label">
                                    <i class="fas fa-upload"></i> Unggah Foto Selfie Pemohon
                                    <input type="file" name="dokumen_foto_selfie" id="dokumen_foto_selfie" accept=".jpg,.jpeg,.png">
                                </label>
                                <div class="file-preview" id="selfie-preview">
                                    <?php if ($selfie_file): ?>
                                    <div class="document-item">
                                        <div class="doc-info">
                                            <span class="doc-type-badge badge-foto_selfie">Foto Selfie Pemohon</span>
                                            <span class="doc-name"><?= esc($selfie_file['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <?php if (file_exists(getAbsoluteFilePath($selfie_file['path']))): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$selfie_file['path']) ?? '') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="file-name">Belum ada file Foto Selfie Pemohon diunggah</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
                        <!-- Dokumen Wajib: Formulir F-2.01 Kematian -->
                        <div class="form-group">
                            <label>Formulir F-2.01 Kematian <span class="required">*</span></label>
                            <div class="file-upload">
                                <label class="file-upload-label">
                                    <i class="fas fa-upload"></i> Unggah Formulir F-2.01 Kematian
                                    <input type="file" name="dokumen_formulir_f201" id="dokumen_formulir_f201" accept=".jpg,.jpeg,.png">
                                </label>
                                <div class="file-preview" id="f201-preview">
                                    <?php if ($f201_file): ?>
                                    <div class="document-item">
                                        <div class="doc-info">
                                            <span class="doc-type-badge badge-formulir_f201">Formulir F-2.01</span>
                                            <span class="doc-name"><?= esc($f201_file['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <?php if (file_exists(getAbsoluteFilePath($f201_file['path']))): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$f201_file['path']) ?? '') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="file-name">Belum ada file Formulir F-2.01 diunggah</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Dokumen Wajib: Surat Keterangan Kematian -->
                        <div class="form-group">
                            <label>Surat Keterangan Kematian <span class="required">*</span></label>
                            <div class="file-upload">
                                <label class="file-upload-label">
                                    <i class="fas fa-upload"></i> Unggah Surat Keterangan Kematian
                                    <input type="file" name="dokumen_surat_kematian" id="dokumen_surat_kematian" accept=".jpg,.jpeg,.png">
                                </label>
                                <div class="file-preview" id="surat-preview">
                                    <?php if ($surat_file): ?>
                                    <div class="document-item">
                                        <div class="doc-info">
                                            <span class="doc-type-badge badge-surat_kematian">Surat Keterangan Kematian</span>
                                            <span class="doc-name"><?= esc($surat_file['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <?php if (file_exists(getAbsoluteFilePath($surat_file['path']))): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$surat_file['path']) ?? '') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="file-name">Belum ada file Surat Keterangan Kematian diunggah</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dokumen Wajib: Identitas yang Meninggal -->
                        <div class="form-group">
                            <label>KK/KTP/Surat Kehilangan KK/KTP yang Meninggal <span class="required">*</span></label>
                            <div class="file-upload">
                                <label class="file-upload-label">
                                    <i class="fas fa-upload"></i> Unggah Identitas yang Meninggal
                                    <input type="file" name="dokumen_identitas" id="dokumen_identitas" accept=".jpg,.jpeg,.png">
                                </label>
                                <div class="file-preview" id="identitas-preview">
                                    <?php if ($identitas_file): ?>
                                    <div class="document-item">
                                        <div class="doc-info">
                                            <span class="doc-type-badge badge-identitas_meninggal">Identitas Meninggal</span>
                                            <span class="doc-name"><?= esc($identitas_file['original_name']) ?></span>
                                        </div>
                                        <div class="doc-actions">
                                            <?php if (file_exists(getAbsoluteFilePath($identitas_file['path']))): ?>
                                            <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$identitas_file['path']) ?? '') ?>')">
                                                <i class="fas fa-eye"></i> Lihat
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="file-name">Belum ada file Identitas yang Meninggal diunggah</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dokumen yang sudah ada (selain wajib) -->
                        <?php if (!empty($dokumen_data)): ?>
                        <div class="document-list" id="existing-documents">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Dokumen Lain yang Sudah Ada:</h4>
                            <?php foreach ($dokumen_data as $index => $doc): ?>
                                <?php 
                                // Skip dokumen wajib (yang sudah ditampilkan di atas)
                                $doc_type = $doc['type'] ?? '';
                                $is_wajib = false;
                                
                                if (in_array($doc_type, ['surat_kematian', 'surat_keterangan_kematian'])) $is_wajib = true;
                                if (in_array($doc_type, ['formulir_f201', 'formulir_f-201', 'f201', 'f-201'])) $is_wajib = true;
                                if (in_array($doc_type, ['identitas_meninggal', 'ktp_meninggal', 'kk_meninggal'])) $is_wajib = true;
                                if ($doc_type === 'foto_selfie') $is_wajib = true;
                                ?>
                                <?php if (!$is_wajib): ?>
                                <div class="document-item" data-index="<?= $index ?>">
                                    <div class="doc-info">
                                        <?php 
                                        $badge_class = 'badge-' . $doc['type'];
                                        $type_labels = [
                                            'formulir_f201' => 'Formulir F-2.01',
                                            'surat_kematian' => 'Surat Keterangan Kematian',
                                            'identitas_meninggal' => 'Identitas Meninggal',
                                            'foto_selfie' => 'Foto Selfie Pemohon',
                                            'ktp_ayah' => 'KTP Ayah',
                                            'ktp_ibu' => 'KTP Ibu',
                                            'ktp_saksi1' => 'KTP Saksi 1',
                                            'ktp_saksi2' => 'KTP Saksi 2',
                                            'lainnya' => 'Lainnya'
                                        ];
                                        $type_label = $type_labels[$doc['type']] ?? $doc['type'];
                                        ?>
                                        <span class="doc-type-badge <?= $badge_class ?>"><?= $type_label ?></span>
                                        <span class="doc-name"><?= esc($doc['original_name']) ?></span>
                                    </div>
                                    <div class="doc-actions">
                                        <input type="checkbox" name="existing_docs[]" value="<?= $index ?>" checked style="margin-right: 10px;">
                                        <?php if (file_exists(getAbsoluteFilePath($doc['path']))): ?>
                                        <button type="button" class="btn-preview" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$doc['path']) ?? '') ?>')">
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
                        
                        <!-- Dokumen Tambahan -->
                        <div id="dokumen-tambahan-container">
                            <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Tambah Dokumen Lain:</h4>
                            <div id="dokumen-tambahan-list">
                                <!-- Dokumen tambahan akan ditambahkan di sini -->
                            </div>
                            <button type="button" class="btn-add" onclick="addDokumenTambahan()">
                                <i class="fas fa-plus"></i> Tambah Dokumen
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

<!-- Modal untuk preview dokumen -->
<div id="previewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 2000; justify-content: center; align-items: center;">
    <div style="background: white; border-radius: 8px; padding: 20px; max-width: 90%; max-height: 90%; overflow: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;">Preview Dokumen</h3>
            <button onclick="closePreview()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
        </div>
        <div id="previewContent"></div>
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
    
    // Preview upload untuk dokumen wajib
    const f201Input = document.getElementById('dokumen_formulir_f201');
    const suratInput = document.getElementById('dokumen_surat_kematian');
    const identitasInput = document.getElementById('dokumen_identitas');
    const selfieInput = document.getElementById('dokumen_foto_selfie');
    
    // Preview Formulir F-2.01
    if (f201Input) {
        f201Input.addEventListener('change', function() {
            previewUpload(this, 'f201-preview', 'Formulir F-2.01 Kematian', 'formulir_f201');
        });
    }
    
    // Preview Surat Keterangan Kematian
    if (suratInput) {
        suratInput.addEventListener('change', function() {
            previewUpload(this, 'surat-preview', 'Surat Keterangan Kematian', 'surat_kematian');
        });
    }
    
    // Preview Identitas
    if (identitasInput) {
        identitasInput.addEventListener('change', function() {
            previewUpload(this, 'identitas-preview', 'Identitas Meninggal', 'identitas_meninggal');
        });
    }

    if (selfieInput) {
        selfieInput.addEventListener('change', function() {
            previewUpload(this, 'selfie-preview', 'Foto Selfie Pemohon', 'foto_selfie');
        });
    }
    
    // Set kecamatan dropdown value dan load desa jika sudah ada nilai
    <?php if (!empty($permohonan['id_kecamatan'])): ?>
    setTimeout(() => {
        getDesaByKecamatan(<?= $permohonan['id_kecamatan'] ?>);
    }, 100);
    <?php endif; ?>
});

// Fungsi untuk preview upload
function previewUpload(input, previewId, docName, docType) {
    const preview = document.getElementById(previewId);
    
    if (input.files.length > 0) {
        const file = input.files[0];
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
        
        if (file.size > 1 * 1024 * 1024) {
            alert(`File ${file.name} terlalu besar (${fileSizeMB} MB). Maksimal 1MB.`);
            input.value = '';
            preview.innerHTML = `<span class="file-name">File terlalu besar, pilih file lain</span>`;
            return;
        }
        
        preview.innerHTML = `
            <div class="document-item">
                <div class="doc-info">
                    <span class="doc-type-badge badge-${docType}">${docName} (Baru)</span>
                    <span class="doc-name">${file.name}</span>
                </div>
                <div class="doc-actions">
                    <small>Ukuran: ${fileSizeMB} MB</small>
                </div>
            </div>
        `;
    }
}

// Dokumen Tambahan Functions
let dokumenCounter = 0;

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
function addDokumenTambahan() {
    const container = document.getElementById('dokumen-tambahan-list');
    const newItem = document.createElement('div');
    newItem.className = 'dokumen-tambahan-item';
    newItem.innerHTML = `
        <div class="dokumen-tambahan-header">
            <h4>Dokumen Tambahan #${dokumenCounter + 1}</h4>
            <button type="button" class="btn-remove-dokumen" onclick="removeDokumenTambahan(this)">
                <i class="fas fa-times"></i> Hapus
            </button>
        </div>
        <select name="dokumen_type[]" class="dokumen-type-select" required>
            <option value="">Pilih Jenis Dokumen</option>
            <option value="ktp_ayah">KTP Ayah</option>
            <option value="ktp_ibu">KTP Ibu</option>
            <option value="ktp_saksi1">KTP Saksi 1</option>
            <option value="ktp_saksi2">KTP Saksi 2</option>
            <option value="lainnya">Dokumen Lainnya</option>
        </select>
        <div class="file-upload">
            <label class="file-upload-label">
                <i class="fas fa-upload"></i> Unggah File
                <input type="file" name="dokumen_tambahan[]" accept=".jpg,.jpeg,.png" required>
            </label>
            <div class="file-preview">
                <span class="file-name">Belum ada file dipilih</span>
            </div>
        </div>
    `;
    
    container.appendChild(newItem);
    dokumenCounter++;
    
    // Add file change event to the new input
    const fileInput = newItem.querySelector('input[type="file"]');
    const filePreview = newItem.querySelector('.file-preview');
    
    fileInput.addEventListener('change', function() {
        previewDokumenTambahanInput(this, filePreview);
    });
}

function previewDokumenTambahanInput(input, previewEl = null) {
    const filePreview = previewEl || input?.closest('.file-upload')?.querySelector('.file-preview');
    if (!filePreview) return;

    if (input.files.length > 0) {
        const file = input.files[0];
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);

        if (file.size > 1 * 1024 * 1024) {
            alert(`File ${file.name} terlalu besar (${fileSizeMB} MB). Maksimal 1MB.`);
            input.value = '';
            filePreview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
            return;
        }

        filePreview.innerHTML = `
            <span class="file-name">${file.name}</span><br>
            <small>Ukuran: ${fileSizeMB} MB</small>
        `;
    } else {
        filePreview.innerHTML = '<span class="file-name">Belum ada file dipilih</span>';
    }
}

function removeDokumenTambahan(button) {
    const item = button.closest('.dokumen-tambahan-item');
    item.remove();
    dokumenCounter--;
}

function removeExistingDocument(button, index) {
    if (confirm('Apakah Anda yakin ingin menghapus dokumen ini?')) {
        const checkbox = button.closest('.document-item').querySelector('input[type="checkbox"]');
        checkbox.checked = false;
        button.closest('.document-item').style.display = 'none';
    }
}

// Preview Functions
function previewDocument(path) {
    const extension = path.split('.').pop().toLowerCase();
    
    if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
        previewImage(path);
    } else if (extension === 'pdf') {
        previewPDF(path);
    } else {
        alert('Format file tidak didukung untuk preview.');
    }
}

function previewImage(src) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    content.innerHTML = `<img src="${src}" style="max-width: 100%; max-height: 70vh; display: block; margin: 0 auto;">`;
    modal.style.display = 'flex';
}

function previewPDF(src) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    content.innerHTML = `
        <iframe src="${src}" style="width: 80vw; height: 80vh; border: none;"></iframe>
        <p style="text-align: center; margin-top: 10px;">
            <a href="${src}" target="_blank" style="color: #3b82f6;">Buka di tab baru</a>
        </p>
    `;
    modal.style.display = 'flex';
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewContent').innerHTML = '';
}

// Get Desa by Kecamatan
function getDesaByKecamatan(kecamatanId) {
    const desaSelect = document.getElementById('desa-select');
    
    if (!kecamatanId || kecamatanId === '') {
        desaSelect.innerHTML = '<option value="">Pilih Kecamatan terlebih dahulu</option>';
        return;
    }
    
    // Show loading
    desaSelect.innerHTML = '<option value="">Memuat data desa...</option>';
    desaSelect.disabled = true;
    
    fetch(`get_desa.php?id_kecamatan=${kecamatanId}`)
        .then(response => response.text())
        .then(data => {
            desaSelect.innerHTML = '<option value="">Pilih Desa</option>' + data;
            
            // Set nilai desa jika ada
            <?php if (!empty($permohonan['id_desa'])): ?>
            setTimeout(() => {
                desaSelect.value = <?= $permohonan['id_desa'] ?>;
            }, 100);
            <?php endif; ?>
        })
        .catch(error => {
            console.error('Error:', error);
            desaSelect.innerHTML = '<option value="">Error loading data</option>';
        })
        .finally(() => {
            desaSelect.disabled = false;
        });
}

// Delegasi global untuk menstabilkan setiap file yang dipilih.
document.addEventListener('change', function(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'file') return;
    stabilizeSelectedFile(target);
    (function() {
        if (target.id === 'dokumen_formulir_f201') {
            previewUpload(target, 'f201-preview', 'Formulir F-2.01 Kematian', 'formulir_f201');
            return;
        }
        if (target.id === 'dokumen_surat_kematian') {
            previewUpload(target, 'surat-preview', 'Surat Keterangan Kematian', 'surat_kematian');
            return;
        }
        if (target.id === 'dokumen_identitas') {
            previewUpload(target, 'identitas-preview', 'Identitas Meninggal', 'identitas_meninggal');
            return;
        }
        if (target.id === 'dokumen_foto_selfie') {
            previewUpload(target, 'selfie-preview', 'Foto Selfie Pemohon', 'foto_selfie');
            return;
        }
        if (target.name === 'dokumen_tambahan[]') {
            previewDokumenTambahanInput(target);
        }
    })();
});
// Form Validation
document.getElementById('editAktaForm').addEventListener('submit', function(e) {
    let isValid = true;
    const errorMessages = [];
    
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
    
    // Validate dokumen wajib
    const f201Input = document.getElementById('dokumen_formulir_f201');
    const suratInput = document.getElementById('dokumen_surat_kematian');
    const identitasInput = document.getElementById('dokumen_identitas');
    const selfieInput = document.getElementById('dokumen_foto_selfie');
    
    // Cek apakah dokumen wajib sudah ada atau diupload baru.
    // f201Input tidak ada di DOM saat formulir dibuat sistem (elemen dihapus dari HTML).
    const formulirOtomatis = <?= FORMULIR_F201_KEMATIAN_AUTO_ENABLED ? 'true' : 'false' ?>;
    const hasF201 = formulirOtomatis || (f201Input && f201Input.files.length > 0) || <?= !empty($f201_file) ? 'true' : 'false' ?>;
    const hasSurat = suratInput.files.length > 0 || <?= !empty($surat_file) ? 'true' : 'false' ?>;
    const hasIdentitas = identitasInput.files.length > 0 || <?= !empty($identitas_file) ? 'true' : 'false' ?>;
    const hasSelfie = selfieInput.files.length > 0 || <?= !empty($selfie_file) ? 'true' : 'false' ?>;

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

    if (!hasF201) {
        errorMessages.push('Formulir F-2.01 Kematian wajib diunggah');
        isValid = false;
    }
    if (!hasSurat) {
        errorMessages.push('Surat Keterangan Kematian wajib diunggah');
        isValid = false;
    }
    if (!hasIdentitas) {
        errorMessages.push('KK/KTP/Surat Kehilangan KK/KTP yang meninggal wajib diunggah');
        isValid = false;
    }
    if (!hasSelfie) {
        errorMessages.push('Foto Selfie Pemohon Memegang Formulir wajib diunggah');
        isValid = false;
    }
    
    // Validate file sizes for new uploads
    const fileInputs = this.querySelectorAll('input[type="file"]');
    for (let input of fileInputs) {
        if (input.files.length > 0) {
            if (input.dataset.fileStable === '0') {
                // Fallback mode: jangan blok submit, karena beberapa browser mobile
                // gagal clone file meski file lokal tetap valid untuk upload normal.
                console.warn(`File "${input.files[0].name}" tidak bisa distabilkan, lanjut upload mode normal.`);
            }            const file = input.files[0];
            if (file.size > 1 * 1024 * 1024) {
                errorMessages.push(`File ${file.name} terlalu besar (maksimal 1MB)`);
                isValid = false;
                break;
            }
        }
    }
    
    if (!isValid) {
        e.preventDefault();
        if (errorMessages.length > 0) {
            alert('Mohon perbaiki kesalahan berikut:\n\n' + errorMessages.join('\n'));
        } else {
            alert('Harap lengkapi semua bidang yang wajib diisi dan periksa file yang diunggah!');
        }
        return false;
    }
    
    // Form is valid, allow submission
    return true;
});
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED): ?>
<script src="../assets/js/formulir_ttd.js?v=20260919-1" data-form="editAktaForm" data-pratinjau="pratinjau_formulir_kematian.php"></script>
<?php endif; ?>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>


