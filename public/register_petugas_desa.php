<?php
// register_petugas_desa.php
require_once 'error_handler.php';
session_start();

// Inisialisasi variabel
$errors = [];
$duplicateAlertMessage = '';
$success = false;
$pendaftaran_id = null;
$formData = [
    'nama' => '',
    'nik' => '',
    'email' => '',
    'no_hp' => '',
    'alamat' => '',
    'id_kecamatan' => '',
    'id_desa' => '',
    'jabatan' => '',
    'keterangan_jabatan' => ''
];

function uploadErrorMessage(int $errorCode): string {
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas yang diizinkan.',
        UPLOAD_ERR_PARTIAL => 'File terupload sebagian. Silakan coba lagi.',
        UPLOAD_ERR_NO_FILE => 'Tidak ada file yang dipilih.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menyimpan file upload.',
        UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP/server.',
        default => 'Terjadi kesalahan upload yang tidak diketahui.'
    };
}

// Bagian pengambilan kecamatan di atas file
include '../app/config/koneksi.php';

// Pastikan koneksi tidak null
if (isset($koneksi) && $koneksi instanceof mysqli) {
    $kecamatan_result = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
} else {
    $kecamatan_result = false;
    error_log("Gagal mengambil data kecamatan: Objek koneksi tidak valid.");
}

// Proses form jika method POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Ambil data dari form
    $formData = [
        'nama' => isset($_POST['nama']) ? trim($_POST['nama']) : '',
        'nik' => isset($_POST['nik']) ? preg_replace('/[^0-9]/', '', trim($_POST['nik'])) : '',
        'email' => isset($_POST['email']) ? trim($_POST['email']) : '',
        'no_hp' => isset($_POST['no_hp']) ? preg_replace('/[^0-9]/', '', trim($_POST['no_hp'])) : '',
        'alamat' => isset($_POST['alamat']) ? trim($_POST['alamat']) : '',
        'id_kecamatan' => isset($_POST['id_kecamatan']) ? trim($_POST['id_kecamatan']) : '',
        'id_desa' => isset($_POST['id_desa']) ? trim($_POST['id_desa']) : '',
        'jabatan' => isset($_POST['jabatan']) ? trim($_POST['jabatan']) : '',
        'keterangan_jabatan' => isset($_POST['keterangan_jabatan']) ? trim($_POST['keterangan_jabatan']) : ''
    ];

    // Validasi
    if (empty($formData['nama'])) {
        $errors[] = 'Nama lengkap harus diisi';
    } elseif (strlen($formData['nama']) < 3) {
        $errors[] = 'Nama minimal 3 karakter';
    }
    
    if (empty($formData['nik'])) {
        $errors[] = 'NIK harus diisi';
    } elseif (!preg_match("/^[0-9]{16}$/", $formData['nik'])) {
        $errors[] = 'NIK harus 16 digit angka';
    }
    
    if (empty($formData['email'])) {
        $errors[] = 'Email harus diisi';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid';
    }
    
    if (empty($formData['no_hp'])) {
        $errors[] = 'Nomor HP harus diisi';
    } elseif (!preg_match("/^[0-9]{10,13}$/", $formData['no_hp'])) {
        $errors[] = 'Nomor HP harus 10-13 digit angka';
    }
    
    if (empty($formData['alamat'])) {
        $errors[] = 'Alamat harus diisi';
    }
    
    if (empty($formData['id_kecamatan'])) {
        $errors[] = 'Kecamatan harus dipilih';
    }
    
    if (empty($formData['id_desa'])) {
        $errors[] = 'Desa harus dipilih';
    }
    
    if (empty($formData['jabatan'])) {
        $errors[] = 'Jabatan harus dipilih';
    }
    
    if ($formData['jabatan'] === 'lainnya' && empty($formData['keterangan_jabatan'])) {
        $errors[] = 'Silakan sebutkan jabatan Anda';
    }

    // Upload surat tugas
    $surat_tugas = null;
    $file_uploaded = false;
    if (isset($_FILES['surat_tugas']) && $_FILES['surat_tugas']['error'] !== UPLOAD_ERR_NO_FILE) {
        
        if ($_FILES['surat_tugas']['error'] === UPLOAD_ERR_OK) {
            $max_size = 1 * 1024 * 1024; // 1MB
            $allowed_types = [
                'application/pdf',
                'application/x-pdf',
                'application/acrobat',
                'applications/vnd.pdf',
                'text/pdf',
                'application/octet-stream'
            ];
            $tmp_file = $_FILES['surat_tugas']['tmp_name'] ?? '';
            $file_ext = strtolower(pathinfo($_FILES['surat_tugas']['name'], PATHINFO_EXTENSION));
            $file_size = $_FILES['surat_tugas']['size'];

            if ($file_ext !== 'pdf') {
                $errors[] = 'Format file harus PDF (ekstensi .pdf)';
            } elseif (!is_uploaded_file($tmp_file) || !is_readable($tmp_file)) {
                $errors[] = 'File Anda tidak dapat diakses. Pilih ulang file PDF lalu coba lagi.';
            } elseif ($file_size > $max_size) {
                $errors[] = 'Ukuran file maksimal 1MB';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detected_type = $finfo ? (string)finfo_file($finfo, $tmp_file) : '';
                if ($finfo) {
                    finfo_close($finfo);
                }

                $file_header = @file_get_contents($tmp_file, false, null, 0, 5);
                $is_pdf_signature = ($file_header === '%PDF-');
                if (!in_array($detected_type, $allowed_types, true) && !$is_pdf_signature) {
                    $errors[] = 'File harus berupa PDF yang valid';
                } else {
                    $file_uploaded = true;
                }
            }
        } else {
            $errors[] = 'Gagal upload surat tugas: ' . uploadErrorMessage((int)$_FILES['surat_tugas']['error']);
        }
    } else {
        $errors[] = 'Surat tugas wajib diupload';
    }
    
    // Validasi persetujuan
    if (!isset($_POST['agree_terms']) || $_POST['agree_terms'] !== 'on') {
        $errors[] = 'Anda harus menyetujui persyaratan pendaftaran';
    }
    
    // Jika tidak ada error, proses ke database
    if (empty($errors)) {
        
        // Cek apakah NIK/Email sudah terdaftar sebagai user aktif
        $check_user = $koneksi->prepare("SELECT MAX(LOWER(email) = LOWER(?)) AS email_used, MAX(nik = ?) AS nik_used FROM tb_users WHERE LOWER(email) = LOWER(?) OR nik = ?");
        if ($check_user) {
            $check_user->bind_param("ssss", $formData['email'], $formData['nik'], $formData['email'], $formData['nik']);
            $check_user->execute();
            $user_result = $check_user->get_result();
            
            $existing_user = $user_result ? $user_result->fetch_assoc() : null;
            $isEmailUsed = !empty($existing_user['email_used']);
            $isNikUsed = !empty($existing_user['nik_used']);

            if ($isEmailUsed || $isNikUsed) {

                if ($isEmailUsed && $isNikUsed) {
                    $duplicateAlertMessage = 'Email dan NIK sudah terpakai pada akun pengguna.';
                    $errors[] = 'Email dan NIK sudah terpakai pada akun pengguna.';
                } elseif ($isEmailUsed) {
                    $duplicateAlertMessage = 'Email sudah terpakai pada akun pengguna.';
                    $errors[] = 'Email sudah terpakai pada akun pengguna.';
                } elseif ($isNikUsed) {
                    $duplicateAlertMessage = 'NIK sudah terpakai pada akun pengguna.';
                    $errors[] = 'NIK sudah terpakai pada akun pengguna.';
                } else {
                    $duplicateAlertMessage = 'Email atau NIK sudah terpakai pada akun pengguna.';
                    $errors[] = 'Email atau NIK sudah terpakai pada akun pengguna.';
                }
            } else {
                // Cek apakah sudah ada pendaftaran pending dengan NIK/email yang sama
                $check_pending = $koneksi->prepare("SELECT MAX(LOWER(email) = LOWER(?)) AS email_used, MAX(nik = ?) AS nik_used FROM tb_pendaftaran_petugas WHERE (LOWER(email) = LOWER(?) OR nik = ?) AND status = 'pending'");
                if ($check_pending) {
                    $check_pending->bind_param("ssss", $formData['email'], $formData['nik'], $formData['email'], $formData['nik']);
                    $check_pending->execute();
                    $pending_result = $check_pending->get_result();
                    
                    $existing_pending = $pending_result ? $pending_result->fetch_assoc() : null;
                    $isEmailUsed = !empty($existing_pending['email_used']);
                    $isNikUsed = !empty($existing_pending['nik_used']);

                    if ($isEmailUsed || $isNikUsed) {

                        if ($isEmailUsed && $isNikUsed) {
                            $duplicateAlertMessage = 'Email dan NIK sudah terpakai untuk pendaftaran yang masih menunggu verifikasi.';
                            $errors[] = 'Email dan NIK sudah terpakai untuk pendaftaran yang masih menunggu verifikasi.';
                        } elseif ($isEmailUsed) {
                            $duplicateAlertMessage = 'Email sudah terpakai untuk pendaftaran yang masih menunggu verifikasi.';
                            $errors[] = 'Email sudah terpakai untuk pendaftaran yang masih menunggu verifikasi.';
                        } elseif ($isNikUsed) {
                            $duplicateAlertMessage = 'NIK sudah terpakai untuk pendaftaran yang masih menunggu verifikasi.';
                            $errors[] = 'NIK sudah terpakai untuk pendaftaran yang masih menunggu verifikasi.';
                        } else {
                            $duplicateAlertMessage = 'Anda sudah mengajukan pendaftaran sebelumnya. Silakan tunggu verifikasi admin.';
                            $errors[] = 'Anda sudah mengajukan pendaftaran sebelumnya. Silakan tunggu verifikasi admin.';
                        }
                    } else {
                        // Ambil nama kecamatan dan desa
                        $nama_kecamatan = '';
                        $nama_desa = '';
                        
                        $kecamatan_query = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
                        if ($kecamatan_query) {
                            $kecamatan_query->bind_param('i', $formData['id_kecamatan']);
                            $kecamatan_query->execute();
                            $kecamatan_result = $kecamatan_query->get_result();
                            if ($kecamatan_row = $kecamatan_result->fetch_assoc()) {
                                $nama_kecamatan = $kecamatan_row['nama_kecamatan'];
                            }
                            $kecamatan_query->close();
                        }
                        
                        $desa_query = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
                        if ($desa_query) {
                            $desa_query->bind_param('i', $formData['id_desa']);
                            $desa_query->execute();
                            $desa_result = $desa_query->get_result();
                            if ($desa_row = $desa_result->fetch_assoc()) {
                                $nama_desa = $desa_row['nama_desa'];
                            }
                            $desa_query->close();
                        }
                        
                        // Tentukan jabatan lengkap
                        $jabatan_lengkap = ($formData['jabatan'] === 'lainnya') 
                            ? $formData['keterangan_jabatan'] 
                            : $formData['jabatan'];
                        
                        // **PERBAIKAN: SIMPAN DATA TANPA SURAT_TUGAS DULU**
                        $stmt = $koneksi->prepare("INSERT INTO tb_pendaftaran_petugas 
                            (nama, nik, email, no_hp, alamat, kecamatan_id, nama_kecamatan, desa_id, nama_desa, jabatan, status, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
                        
                        if ($stmt) {
                            // **PERBAIKAN: bind_param dengan 10 parameter, bukan 12**
                            $stmt->bind_param("sssssisiss", 
                                $formData['nama'],
                                $formData['nik'],
                                $formData['email'],
                                $formData['no_hp'],
                                $formData['alamat'],
                                $formData['id_kecamatan'],
                                $nama_kecamatan,
                                $formData['id_desa'],
                                $nama_desa,
                                $jabatan_lengkap
                            );
                            
                            if ($stmt->execute()) {
                                $pendaftaran_id = $stmt->insert_id;
                                
                                // Handle file upload jika ada
                                if ($file_uploaded && isset($_FILES['surat_tugas'])) {
                                    // Buat nama file sementara
                                    $temp_filename = 'temp_surat_tugas_' . time() . '_' . bin2hex(random_bytes(8)) . '.pdf';
                                    $temp_upload_dir = 'uploads/temp/';
                                    
                                    if (!is_dir($temp_upload_dir)) {
                                        mkdir($temp_upload_dir, 0755, true);
                                    }
                                    
                                    if (move_uploaded_file($_FILES['surat_tugas']['tmp_name'], $temp_upload_dir . $temp_filename)) {
                                        $surat_tugas = [
                                            'temp_path' => $temp_upload_dir . $temp_filename,
                                            'original_name' => $_FILES['surat_tugas']['name']
                                        ];
                                        
                                        // Format nama folder: [nik]_[nama]
                                        $nama_user_clean = preg_replace('/[^a-zA-Z0-9]/', '_', $formData['nama']);
                                        $nik_clean = $formData['nik'];
                                        
                                        $folder_name = $nik_clean . '_' . $nama_user_clean;
                                        
                                        // Buat path folder
                                        $base_upload_dir = 'uploads/petugas_desa/';
                                        $user_folder = $base_upload_dir . $folder_name . '/';
                                        
                                        // Buat folder jika belum ada
                                        if (!is_dir($base_upload_dir)) {
                                            mkdir($base_upload_dir, 0755, true);
                                            
                                            // Tambahkan pengaman otomatis jika file .htaccess belum ada
                                            $htaccess_path = $base_upload_dir . '.htaccess';
                                            if (!file_exists($htaccess_path)) {
                                                $content = "Options -Indexes\n";
                                                $content .= "<FilesMatch \"\\.(?i:php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n";
                                                $content .= "    Order Allow,Deny\n";
                                                $content .= "    Deny from all\n";
                                                $content .= "</FilesMatch>\n";
                                                $content .= "php_flag engine off";
                                                file_put_contents($htaccess_path, $content);
                                            }
                                        }
                                        
                                        if (!is_dir($user_folder)) {
                                            mkdir($user_folder, 0755, true);
                                        }
                                        
                                        // Generate nama file baru
                                        $new_filename = 'surat_tugas_' . $pendaftaran_id . '_' . time() . '.pdf';
                                        $new_filepath = $user_folder . $new_filename;
                                        
                                        // Pindahkan file
                                        if (rename($surat_tugas['temp_path'], $new_filepath)) {
                                            // Update database dengan path file
                                            $update_file = $koneksi->prepare("UPDATE tb_pendaftaran_petugas SET surat_tugas = ? WHERE id = ?");
                                            $update_file->bind_param("si", $new_filepath, $pendaftaran_id);
                                            if (!$update_file->execute()) {
                                                error_log("Error updating file path: " . $update_file->error);
                                            }
                                            $update_file->close();
                                        } else {
                                            error_log("Error moving file from temp to permanent location");
                                        }
                                    } else {
                                        error_log("Error moving uploaded file to temp location");
                                    }
                                }
                                
                                // Generate kode verifikasi
                                $kode_verifikasi = strtoupper(bin2hex(random_bytes(4)));
                                
                                // Simpan kode verifikasi
                                $update_kode = $koneksi->prepare("UPDATE tb_pendaftaran_petugas SET kode_verifikasi = ? WHERE id = ?");
                                $update_kode->bind_param("si", $kode_verifikasi, $pendaftaran_id);
                                $update_kode->execute();
                                $update_kode->close();
                                
                                // Set success
                                $success = true;
                                $_SESSION['pendaftaran_id'] = $pendaftaran_id;
                                $_SESSION['kode_verifikasi'] = $kode_verifikasi;
                                $_SESSION['email_pendaftar'] = $formData['email'];
                                
                            } else {
                                $errors[] = 'Terjadi kesalahan saat menyimpan data: ' . $stmt->error;
                                error_log("Database error: " . $stmt->error);
                            }
                            $stmt->close();
                        } else {
                            $errors[] = 'Terjadi kesalahan dalam persiapan query: ' . $koneksi->error;
                            error_log("Prepare error: " . $koneksi->error);
                        }
                    }
                    $check_pending->close();
                } else {
                    $errors[] = 'Error dalam query pendaftaran pending';
                    error_log("Prepare check_pending error: " . $koneksi->error);
                }
            }
            $check_user->close();
        } else {
            $errors[] = 'Error dalam query cek user';
            error_log("Prepare check_user error: " . $koneksi->error);
        }
    }
}

// Tutup koneksi
if (isset($koneksi)) {
    $koneksi->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Sebagai Petugas Desa - SI PRIMA</title>
    <link rel="stylesheet" href="assets/css/register.css">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261118-3">
    <script src="assets/js/theme.js?v=20261118-1"></script>
    <style>
        .register-container {
            max-width: 900px;
            margin: 30px auto;
            padding: 20px;
        }
        
        .register-box {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .form-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eaeaea;
        }
        
        .form-header h2 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .form-header .subtitle {
            color: #7f8c8d;
            font-size: 16px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .register-container {
                margin: 16px auto;
                padding: 12px;
            }
            .register-box {
                padding: 24px 18px;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e293b;
            line-height: 1.4;
        }
        
        .form-group.required label::after {
            content: "\00a0*";
            color: #dc2626;
            white-space: nowrap;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .error-message {
            background: #fee;
            border: 1px solid #f99;
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            color: #c0392b;
        }
        
        .error-message ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        
        .success-message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            color: #155724;
            text-align: center;
        }
        
        .success-message h3 {
            margin-top: 0;
            color: #0c5460;
        }
        
        .btn-submit {
            background: #2ecc71;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s;
            display: block;
            width: 100%;
            margin-top: 20px;
        }
        
        .btn-submit:hover {
            background: #27ae60;
        }
        
        .btn-back {
            display: inline-block;
            background: #7f8c8d;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .btn-back:hover {
            background: #6c757d;
        }
        
        .file-upload {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        
        .file-upload input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-label {
            display: block;
            padding: 12px 20px;
            background: #3498db;
            color: white;
            border-radius: 6px;
            text-align: center;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .file-label:hover {
            background: #2980b9;
        }
        
        .file-name {
            margin-top: 10px;
            font-size: 14px;
            color: #7f8c8d;
        }
        
        .input-hint {
            font-size: 12px;
            margin-top: 5px;
            color: #6c757d;
        }
        
        .counter {
            font-size: 12px;
            margin-top: 5px;
            color: #6c757d;
        }
        
        .counter.valid {
            color: #198754;
        }
        
        .counter.invalid {
            color: #dc3545;
        }
        
        .select-loading {
            color: #6c757d;
            font-style: italic;
        }
        
        .contact-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin-top: 20px;
            text-align: center;
            font-size: 14px;
        }
        
        .contact-info h4 {
            margin-top: 0;
            color: #0c4a6e;
            margin-bottom: 10px;
        }
        
        .contact-info p {
            margin: 5px 0;
        }
        
        .contact-info strong {
            color: #2c3e50;
        }
        
        .terms-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
        }
        
        .terms-box label {
            display: flex;
            align-items: flex-start;
            cursor: pointer;
        }
        
        .terms-box input[type="checkbox"] {
            margin-right: 10px;
            margin-top: 3px;
        }
        
        .terms-text {
            font-size: 14px;
            color: #495057;
        }
    </style>
</head>
<body>

<nav class="top-nav">
    <div class="nav-brand">
        <span class="brand-icon">SI PRIMA</span>
        <span class="brand-subtitle">Sistem Informasi Pelayanan Terintegrasi Identitas Masyarakat</span>
    </div>
    <a href="index.php" class="btn-home">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
        </svg>
        Halaman Utama
    </a>
</nav>

<div class="register-container">
    <div class="register-box">
        
        <?php if ($success): ?>
            <!-- Halaman Sukses -->
            <div class="success-message">
                <h3>🎉 Pendaftaran Berhasil!</h3>
                <p>Terima kasih telah mendaftar sebagai Petugas Desa.</p>
                <p><strong>Kode Pendaftaran Anda:</strong> <span style="font-size: 24px; color: #2c3e50;"><?= $pendaftaran_id ?? '' ?></span></p>
                
                <p>Data Anda akan diverifikasi oleh admin dalam waktu 1-3 hari kerja.</p>
                <p>Anda akan menerima notifikasi via email ke: <strong><?= htmlspecialchars($formData['email']) ?></strong></p>
                <p>Silakan simpan kode pendaftaran untuk pengecekan status.</p>
                
                <div class="contact-info">
                    <h4>Jika ada kendala, hubungi:</h4>
                    <p><strong>📞 WA:</strong> 0812-4342-5557</p>
                    <p><strong>✉️ Email:</strong> dkpskabgor@gmail.com</p>
                </div>
                
                <div style="margin-top: 20px;">
                    <a href="login_add.php" class="btn-back">Kembali ke Halaman Login</a>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Form Pendaftaran -->
            <div class="form-header">
                <h2>Pendaftaran Petugas Desa</h2>
                <p class="subtitle">Daftar sebagai petugas desa untuk mengelola data kependudukan</p>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <strong>Perhatian:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" id="petugasForm" onsubmit="return validateForm()">
<?php echo csrf_input(); ?>
                <h3 style="color: #2c3e50; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">Data Pribadi</h3>
                
                <div class="form-grid">
                    <div class="form-group required">
                        <label>Nama Lengkap</label>
                        <input type="text" name="nama" id="nama" 
                               value="<?= htmlspecialchars($formData['nama']) ?>" 
                               required placeholder="Nama lengkap sesuai KTP">
                    </div>
                    
                    <div class="form-group required">
                        <label>NIK (Nomor Induk Kependudukan)</label>
                        <input type="text" name="nik" id="nik" 
                               value="<?= htmlspecialchars($formData['nik']) ?>" 
                               required placeholder="Masukkan 16 digit angka" 
                               maxlength="16" oninput="validateNikInput()">
                        <div class="counter" id="nikCounter">0/16 digit</div>
                        <div class="input-hint" id="nikHint"></div>
                    </div>
                    
                    <div class="form-group required">
                        <label>Email</label>
                        <input type="email" name="email" id="email" 
                               value="<?= htmlspecialchars($formData['email']) ?>" 
                               required placeholder="email@contoh.com" oninput="validateEmail(this)">
                        <div class="input-hint" id="emailHint"></div>
                    </div>
                    
                    <div class="form-group required">
                        <label>Nomor HP/WhatsApp</label>
                        <input type="text" name="no_hp" id="no_hp" 
                               value="<?= htmlspecialchars($formData['no_hp']) ?>" 
                               required placeholder="081234567890" 
                               maxlength="13" oninput="validateNoHPInput()">
                        <div class="counter" id="noHPCounter">0/13 digit</div>
                        <div class="input-hint" id="noHPHint"></div>
                    </div>
                </div>
                
                <div class="form-group required full-width">
                    <label>Alamat Lengkap</label>
                    <textarea name="alamat" rows="3" required placeholder="Alamat tempat tinggal saat ini"><?= htmlspecialchars($formData['alamat']) ?></textarea>
                </div>
                
                <h3 style="color: #2c3e50; margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 1px solid #eee;">Data Lokasi & Jabatan</h3>
                
                <div class="form-grid">
                    <div class="form-group required">
                        <label>Kecamatan</label>
                        <select name="id_kecamatan" id="kecamatan" required onchange="loadDesa()">
                            <option value="">-- Pilih Kecamatan --</option>
                            <?php 
                            if (isset($kecamatan_result) && $kecamatan_result->num_rows > 0):
                                $kecamatan_result->data_seek(0);
                                while ($kecamatan = $kecamatan_result->fetch_assoc()): 
                            ?>
                                <option value="<?= $kecamatan['id'] ?>" 
                                    <?= $formData['id_kecamatan'] == $kecamatan['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kecamatan['nama_kecamatan']) ?>
                                </option>
                            <?php endwhile; ?>
                            <?php else: ?>
                                <option value="">Data kecamatan tidak tersedia</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="form-group required">
                        <label>Desa</label>
                        <select name="id_desa" id="desa" required disabled>
                            <option value="">-- Pilih Desa --</option>
                            <?php if (!empty($formData['id_desa']) && !empty($formData['id_kecamatan'])): ?>
                                <option value="<?= $formData['id_desa'] ?>" selected>Loading...</option>
                            <?php endif; ?>
                        </select>
                        <div class="input-hint" id="desaHint">Pilih kecamatan terlebih dahulu</div>
                    </div>
                    
                    <div class="form-group required">
                        <label>Jabatan di Desa</label>
                        <select name="jabatan" id="jabatanSelect" required onchange="toggleJabatanLainnya()">
                            <option value="">-- Pilih Jabatan --</option>
                            <option value="kepala_desa" <?= $formData['jabatan'] == 'kepala_desa' ? 'selected' : '' ?>>Kepala Desa</option>
                            <option value="sekretaris_desa" <?= $formData['jabatan'] == 'sekretaris_desa' ? 'selected' : '' ?>>Sekretaris Desa</option>
                            <option value="kasi_pemerintahan" <?= $formData['jabatan'] == 'kasi_pemerintahan' ? 'selected' : '' ?>>Kasi Pemerintahan</option>
                            <option value="petugas_adminduk" <?= $formData['jabatan'] == 'petugas_adminduk' ? 'selected' : '' ?>>Petugas Admin Kependudukan</option>
                            <option value="ketua_rt" <?= $formData['jabatan'] == 'ketua_rt' ? 'selected' : '' ?>>Ketua RT</option>
                            <option value="sekretaris_rt" <?= $formData['jabatan'] == 'sekretaris_rt' ? 'selected' : '' ?>>Sekretaris RT</option>
                            <option value="lainnya" <?= $formData['jabatan'] == 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group full-width" id="keteranganJabatanGroup" style="display: <?= $formData['jabatan'] == 'lainnya' ? 'block' : 'none' ?>; grid-column: 1 / -1;">
                    <label>Jabatan Lainnya</label>
                    <input type="text" name="keterangan_jabatan" id="keterangan_jabatan" 
                           value="<?= htmlspecialchars($formData['keterangan_jabatan']) ?>" 
                           placeholder="Tuliskan jabatan Anda di desa">
                </div>
                
                <h3 style="color: #2c3e50; margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 1px solid #eee;">Dokumen Pendukung</h3>
                
                <div class="form-group required full-width">
                    <label>Surat Tugas / Surat Pengangkatan</label>
                    <div class="file-upload">
                        <label class="file-label">
                            📄 Pilih File Surat Tugas
                            <input type="file" name="surat_tugas" id="surat_tugas" accept=".pdf" required onchange="displayFileName(this)">
                        </label>
                    </div>
                    <div class="file-name" id="fileName">Belum ada file dipilih</div>
                    <div class="input-hint">Format: PDF (maks. 1MB)</div>
                </div>
                
                <div class="terms-box">
                    <label>
                        <input type="checkbox" name="agree_terms" id="agree_terms" required>
                        <span class="terms-text">
                            Saya menyetujui bahwa data yang saya berikan adalah benar dan siap menerima konsekuensi hukum jika data yang diberikan ternyata tidak benar.
                        </span>
                    </label>
                </div>
                
                <div class="contact-info">
                    <h4>Informasi Admin</h4>
                    <p>Jika ada kendala dalam pendaftaran, silakan hubungi:</p>
                    <p><strong>📞 Telepon:</strong> 0812-4342-5557</p>
                    <p><strong>✉️ Email:</strong> dkpskabgor@gmail.com</p>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 30px;">
                    <a href="login_add.php" class="btn-back">Batal</a>
                    <button type="submit" class="btn-submit">📨 Kirim Pendaftaran</button>
                </div>
            </form>
        <?php endif; ?>
        
    </div>
</div>

<?php if (!empty($duplicateAlertMessage)): ?>
<script>
window.addEventListener('DOMContentLoaded', function() {
    alert(<?= json_encode($duplicateAlertMessage) ?>);
});
</script>
<?php endif; ?>

<script>
// Fungsi untuk mendapatkan hanya angka dari string
function getNumbersOnly(str) {
    return str.replace(/\D/g, '');
}

// Validasi NIK input
function validateNikInput() {
    const input = document.getElementById('nik');
    const hint = document.getElementById('nikHint');
    const counter = document.getElementById('nikCounter');
    
    // Hanya angka yang diperbolehkan
    const numbers = getNumbersOnly(input.value);
    const numbersLength = numbers.length;
    
    // Update nilai input dengan angka saja
    input.value = numbers.substring(0, 16);
    
    // Update counter
    counter.textContent = `${numbersLength}/16 digit`;
    counter.className = numbersLength === 16 ? 'counter valid' : 'counter invalid';
    
    if (numbersLength === 0) {
        hint.innerHTML = 'Masukkan 16 digit NIK';
        hint.style.color = '#6c757d';
        return false;
    }
    
    if (numbersLength !== 16) {
        hint.innerHTML = `❌ ${numbersLength} digit - Harus tepat 16 digit`;
        hint.style.color = '#dc3545';
        return false;
    }
    
    hint.innerHTML = '✅ NIK valid (16 digit angka)';
    hint.style.color = '#198754';
    return true;
}

// Validasi No HP input
function validateNoHPInput() {
    const input = document.getElementById('no_hp');
    const hint = document.getElementById('noHPHint');
    const counter = document.getElementById('noHPCounter');
    
    // Hanya angka yang diperbolehkan
    const numbers = getNumbersOnly(input.value);
    const numbersLength = numbers.length;
    
    // Update nilai input dengan angka saja
    input.value = numbers.substring(0, 13);
    
    // Update counter
    counter.textContent = `${numbersLength}/13 digit`;
    counter.className = (numbersLength >= 10 && numbersLength <= 13) ? 'counter valid' : 'counter invalid';
    
    if (numbersLength === 0) {
        hint.innerHTML = 'Masukkan 10-13 digit nomor HP';
        hint.style.color = '#6c757d';
        return false;
    }
    
    if (numbersLength < 10 || numbersLength > 13) {
        hint.innerHTML = `❌ ${numbersLength} digit - Harus 10-13 digit`;
        hint.style.color = '#dc3545';
        return false;
    }
    
    hint.innerHTML = '✅ Nomor HP valid';
    hint.style.color = '#198754';
    return true;
}

// Validasi Email
function validateEmail(input) {
    const hint = document.getElementById('emailHint');
    const email = input.value;
    
    if (email.length === 0) {
        hint.innerHTML = 'Masukkan alamat email';
        hint.style.color = '#6c757d';
        return false;
    }
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        hint.innerHTML = '❌ Format email tidak valid';
        hint.style.color = '#dc3545';
        return false;
    }
    
    hint.innerHTML = '✅ Format email valid';
    hint.style.color = '#198754';
    return true;
}

// Load desa berdasarkan kecamatan yang dipilih
function loadDesa() {
    const kecamatanSelect = document.getElementById('kecamatan');
    const desaSelect = document.getElementById('desa');
    const desaHint = document.getElementById('desaHint');
    
    const kecamatanId = kecamatanSelect.value;
    
    // Reset desa
    desaSelect.innerHTML = '<option value="">-- Pilih Desa --</option>';
    desaSelect.disabled = true;
    
    if (kecamatanId) {
        desaHint.innerHTML = '<span class="select-loading">Memuat data desa...</span>';
        
        // AJAX request untuk mendapatkan desa
        fetch(`user/get_desa.php?kecamatan_id=${kecamatanId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(data => {
                if (data.trim() === '') {
                    throw new Error('Empty response');
                }
                desaSelect.innerHTML = data;
                desaSelect.disabled = false;
                desaHint.innerHTML = `Pilih desa di Kecamatan ${kecamatanSelect.options[kecamatanSelect.selectedIndex].text}`;
                desaHint.style.color = '#198754';
            })
            .catch(error => {
                console.error('Error:', error);
                desaSelect.innerHTML = '<option value="">Gagal memuat data</option>';
                desaHint.innerHTML = '❌ Gagal memuat data desa';
                desaHint.style.color = '#dc3545';
            });
    } else {
        desaHint.innerHTML = 'Pilih kecamatan terlebih dahulu';
        desaHint.style.color = '#6c757d';
    }
}

function toggleJabatanLainnya() {
    const jabatanSelect = document.getElementById('jabatanSelect');
    const keteranganGroup = document.getElementById('keteranganJabatanGroup');
    const keteranganInput = document.getElementById('keterangan_jabatan');
    
    if (jabatanSelect.value === 'lainnya') {
        keteranganGroup.style.display = 'block';
        keteranganInput.required = true;
    } else {
        keteranganGroup.style.display = 'none';
        keteranganInput.required = false;
        keteranganInput.value = '';
    }
}

function displayFileName(input) {
    const fileName = document.getElementById('fileName');
    if (input.files.length > 0) {
        const file = input.files[0];
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const mime = String(file.type || '').toLowerCase();
        const isPdfByMime = mime === 'application/pdf' || mime === 'application/x-pdf' || mime === 'application/octet-stream' || mime === '';
        const isPdfByExt = ext === 'pdf';

        fileName.textContent = file.name;
        fileName.style.color = '#198754';
        
        // Validasi ukuran file (client-side)
        const maxSize = 1 * 1024 * 1024; // 1MB
        if (file.size > maxSize) {
            fileName.textContent = 'File terlalu besar (maks 1MB)';
            fileName.style.color = '#dc3545';
            input.value = '';
            return;
        }
        
        // Validasi tipe file
        if (!isPdfByExt || !isPdfByMime) {
            fileName.textContent = 'Hanya file PDF yang diperbolehkan';
            fileName.style.color = '#dc3545';
            input.value = '';
        }
    } else {
        fileName.textContent = 'Belum ada file dipilih';
        fileName.style.color = '#6c757d';
    }
}

// Validasi form sebelum submit
function validateForm() {
    let isValid = true;
    const errors = [];
    
    // Validasi NIK
    if (!validateNikInput()) {
        errors.push('NIK harus tepat 16 digit angka');
        isValid = false;
    }
    
    // Validasi Email
    const email = document.getElementById('email').value;
    if (!validateEmail(document.getElementById('email'))) {
        errors.push('Email tidak valid');
        isValid = false;
    }
    
    // Validasi No HP
    if (!validateNoHPInput()) {
        errors.push('Nomor HP harus 10-13 digit angka');
        isValid = false;
    }
    
    // Validasi Kecamatan
    const kecamatan = document.getElementById('kecamatan').value;
    if (!kecamatan) {
        errors.push('Kecamatan harus dipilih');
        isValid = false;
    }
    
    // Validasi Desa
    const desa = document.getElementById('desa');
    if (!desa.value || desa.value === '' || desa.disabled) {
        errors.push('Desa harus dipilih');
        isValid = false;
    }
    
    // Validasi file
    const fileInput = document.getElementById('surat_tugas');
    if (fileInput.files.length === 0) {
        errors.push('Surat tugas wajib diupload');
        isValid = false;
    } else {
        const maxSize = 1 * 1024 * 1024; // 1MB
        const file = fileInput.files[0];
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const mime = String(file.type || '').toLowerCase();
        const isPdfByMime = mime === 'application/pdf' || mime === 'application/x-pdf' || mime === 'application/octet-stream' || mime === '';
        const isPdfByExt = ext === 'pdf';
        
        if (!isPdfByExt || !isPdfByMime) {
            errors.push('Format file harus PDF');
            isValid = false;
        }
        
        if (file.size > maxSize) {
            errors.push('Ukuran file terlalu besar (maks 1MB)');
            isValid = false;
        }
    }
    
    // Validasi jabatan
    const jabatan = document.getElementById('jabatanSelect').value;
    if (!jabatan) {
        errors.push('Jabatan harus dipilih');
        isValid = false;
    }
    
    if (jabatan === 'lainnya') {
        const keterangan = document.getElementById('keterangan_jabatan').value;
        if (!keterangan.trim()) {
            errors.push('Silakan sebutkan jabatan Anda');
            isValid = false;
        }
    }
    
    // Validasi checkbox persetujuan
    const agreeTerms = document.getElementById('agree_terms');
    if (!agreeTerms.checked) {
        errors.push('Anda harus menyetujui persyaratan pendaftaran');
        isValid = false;
    }
    
    if (!isValid) {
        alert('Mohon perbaiki kesalahan berikut:\n\n• ' + errors.join('\n• '));
        return false;
    }
    
    // Tampilkan loading
    const submitBtn = document.querySelector('.btn-submit');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '⏳ Mengirim...';
    submitBtn.disabled = true;
    
    return true;
}

// Inisialisasi
document.addEventListener('DOMContentLoaded', function() {
    // Validasi nilai awal jika ada
    if (document.getElementById('nik').value) {
        validateNikInput();
    }
    
    if (document.getElementById('no_hp').value) {
        validateNoHPInput();
    }
    
    // Validasi email awal jika ada
    if (document.getElementById('email').value) {
        validateEmail(document.getElementById('email'));
    }
    
    // Tampilkan/kembalikan nilai jika sudah ada
    toggleJabatanLainnya();
    
    // Load desa jika kecamatan sudah dipilih
    const kecamatanSelect = document.getElementById('kecamatan');
    if (kecamatanSelect.value) {
        loadDesa();
    }
});
</script>

<script src="assets/js/stable-upload.js?v=20260821-2"></script>
</body>
</html>
