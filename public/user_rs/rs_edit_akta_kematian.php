<?php
require_once '../error_handler.php';
include '../../app/middleware/user_rs_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/notifikasi.php';
require_once __DIR__ . '/../functions/document_token.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kematian_rs LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$rs_nama = $_SESSION['nama_rs'] ?? 'Rumah Sakit';
$csrf_token_value = csrf_token();

// Get ID from URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: rs_status_permohonan.php?error=invalid_id');
    exit;
}

// Check if permohonan exists and belongs to user
$query = $koneksi->prepare("
    SELECT * FROM tb_akta_kematian_rs 
    WHERE id = ? AND id_user = ? AND status IN ('diajukan', 'ditolak')
");
$query->bind_param("ii", $id, $user_id);
$query->execute();
$result = $query->get_result();

if ($result->num_rows === 0) {
    header('Location: rs_status_permohonan.php?error=data_not_found');
    exit;
}

$data = $result->fetch_assoc();

// Parse dokumen
$dokumen_array = [];
if (!empty($data['dokumen'])) {
    $dokumen_array = json_decode($data['dokumen'], true);
    if (!is_array($dokumen_array)) {
        $dokumen_array = [];
    }
}

// Get existing folder name
$existing_folder = $data['nama_folder'] ?? '';

// Get kecamatan options for dropdown
$kecamatan_options = [];
$kecamatan_query = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
while ($kec = $kecamatan_query->fetch_assoc()) {
    $kecamatan_options[] = $kec;
}

// Get desa options based on current kecamatan
$desa_options = [];
if (!empty($data['id_kecamatan'])) {
    $desa_query = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
    $desa_query->bind_param('i', $data['id_kecamatan']);
    $desa_query->execute();
    $desa_result = $desa_query->get_result();
    while ($desa = $desa_result->fetch_assoc()) {
        $desa_options[] = $desa;
    }
    $desa_query->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Collect form data
    $nama_meninggal = trim($_POST['nama_meninggal'] ?? '');
    $nik_meninggal = trim($_POST['nik_meninggal'] ?? '');
    $tanggal_kematian = trim($_POST['tanggal_kematian'] ?? '');
    $tempat = trim($_POST['tempat'] ?? '');
    $dusun = trim($_POST['dusun'] ?? '');
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
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Validate required fields
    if (empty($nama_meninggal)) $errors[] = "Nama yang meninggal wajib diisi";
    if (empty($nik_meninggal)) $errors[] = "NIK yang meninggal wajib diisi";
    if (empty($tanggal_kematian)) $errors[] = "Tanggal kematian wajib diisi";
    if (empty($tempat)) $errors[] = "Tempat kematian wajib diisi";
    if (empty($dusun)) $errors[] = "Dusun/alamat wajib diisi";
    if (empty($nama_saksi1)) $errors[] = "Nama saksi 1 wajib diisi";
    if (empty($nik_saksi1)) $errors[] = "NIK saksi 1 wajib diisi";
    if (empty($nama_saksi2)) $errors[] = "Nama saksi 2 wajib diisi";
    if (empty($nik_saksi2)) $errors[] = "NIK saksi 2 wajib diisi";
    if (empty($id_kecamatan)) $errors[] = "Kecamatan wajib dipilih";
    if (empty($id_desa)) $errors[] = "Desa wajib dipilih";
    
    // Validate NIK format (16 digit)
    if (!empty($nik_meninggal) && !preg_match('/^\d{16}$/', $nik_meninggal)) $errors[] = "NIK yang meninggal harus 16 digit angka";
    if (!empty($nik_ayah) && !preg_match('/^\d{16}$/', $nik_ayah)) $errors[] = "NIK ayah harus 16 digit angka";
    if (!empty($nik_ibu) && !preg_match('/^\d{16}$/', $nik_ibu)) $errors[] = "NIK ibu harus 16 digit angka";
    if (!empty($nik_saksi1) && !preg_match('/^\d{16}$/', $nik_saksi1)) $errors[] = "NIK saksi 1 harus 16 digit angka";
    if (!empty($nik_saksi2) && !preg_match('/^\d{16}$/', $nik_saksi2)) $errors[] = "NIK saksi 2 harus 16 digit angka";
    
    // Validate date not in future
    if (!empty($tanggal_kematian)) {
        $selectedDate = new DateTime($tanggal_kematian);
        $today = new DateTime();
        if ($selectedDate > $today) {
            $errors[] = "Tanggal kematian tidak boleh lebih dari hari ini";
        }
    }
    
    // Get nama kecamatan and desa
    $nama_kecamatan = '';
    $nama_desa = '';
    
    if ($id_kecamatan) {
        $kec_stmt = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
        $kec_stmt->bind_param('i', $id_kecamatan);
        $kec_stmt->execute();
        $kec_stmt->bind_result($nama_kecamatan);
        $kec_stmt->fetch();
        $kec_stmt->close();
    }
    
    if ($id_desa) {
        $desa_stmt = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
        $desa_stmt->bind_param('i', $id_desa);
        $desa_stmt->execute();
        $desa_stmt->bind_result($nama_desa);
        $desa_stmt->fetch();
        $desa_stmt->close();
    }
    
    // Handle dokumen uploads - using existing folder
    $uploaded_dokumen = [];
    $required_docs = [
        'surat_keterangan_kematian_rs' => 'Surat Keterangan Kematian RS',
        'formulir_f201_kematian' => 'Formulir F-2.01 Kematian',
        'identitas_meninggal' => 'Identitas yang Meninggal'
    ];
    
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    $allowedExt = ['jpg', 'jpeg', 'jfif', 'jpe', 'png'];
    
    // Check if we have existing folder, if not create one
    $base_upload_dir = __DIR__ . '/uploads/akta_kematian_rs/';
    if ($existing_folder && is_dir($base_upload_dir . $existing_folder)) {
        $permohonan_dir = $base_upload_dir . $existing_folder;
    } else {
        // Create new folder if doesn't exist
        $folderName = str_replace(' ', '_', $nama_kecamatan) . '_' . 
                     str_replace(' ', '_', $nama_desa) . '_' . 
                     $data['nik_pemohon'] . '_' . 
                     str_replace(' ', '_', $data['nama_pemohon']) . '_' . 
                     str_replace(' ', '_', $nama_meninggal) . '_' . 
                     date('Ymd');
        $folderName = preg_replace('/[^A-Za-z0-9_]/', '', $folderName);
        $permohonan_dir = $base_upload_dir . $folderName;
        
        if (!is_dir($permohonan_dir)) {
            mkdir($permohonan_dir, 0755, true);
        }
        $existing_folder = $folderName;
    }
    
    // Process required documents
    $found_required_docs = [];
    
    foreach ($required_docs as $type => $label) {
        $has_document = false;
        
        // Check if new file is uploaded
        if (isset($_FILES[$type]) && $_FILES[$type]['error'] == 0) {
            $file = $_FILES[$type];
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            // Validate file size
            if ($file['size'] > $maxFileSize) {
                $errors[] = "File {$file['name']} terlalu besar. Maksimal 1MB";
                continue;
            }
            
            // Validate file extension
            if (!in_array($file_ext, $allowedExt)) {
                $errors[] = "File {$file['name']}: Hanya format JPG, JPEG, PNG yang diperbolehkan";
                continue;
            }
            
            // Generate unique filename
            $docTypeName = '';
            switch ($type) {
                case 'surat_keterangan_kematian_rs':
                    $docTypeName = 'Surat_Keterangan_Kematian_RS';
                    break;
                case 'formulir_f201_kematian':
                    $docTypeName = 'Formulir_F201_Kematian';
                    break;
                case 'identitas_meninggal':
                    $docTypeName = 'Identitas_Meninggal';
                    break;
                default:
                    $docTypeName = $type;
            }
            
            $newName = $docTypeName . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $file_ext;
            $destination = $permohonan_dir . '/' . $newName;
            $savePath = 'uploads/akta_kematian_rs/' . $existing_folder . '/' . $newName;
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $uploaded_dokumen[] = [
                    'type' => $type,
                    'original_name' => $file['name'],
                    'path' => $savePath,
                    'folder' => $existing_folder
                ];
                $has_document = true;
                $found_required_docs[$type] = true;
            } else {
                $errors[] = "Gagal mengupload file {$file['name']}";
            }
        }
        
        // Check for existing document if no new upload
        if (!$has_document) {
            foreach ($dokumen_array as $doc) {
                if ($doc['type'] == $type) {
                    $uploaded_dokumen[] = $doc;
                    $has_document = true;
                    $found_required_docs[$type] = true;
                    break;
                }
            }
        }
        
        // Check if required document is missing
        if (!$has_document) {
            $errors[] = "Dokumen {$label} wajib diunggah";
        }
    }
    
    // Process additional optional documents
    $optional_docs = ['kk', 'ktp_ayah', 'ktp_ibu'];
    foreach ($optional_docs as $type) {
        $has_document = false;
        
        // Check if new file is uploaded
        if (isset($_FILES[$type]) && $_FILES[$type]['error'] == 0) {
            $file = $_FILES[$type];
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            // Validate file size
            if ($file['size'] > $maxFileSize) {
                $errors[] = "File {$file['name']} terlalu besar. Maksimal 1MB";
                continue;
            }
            
            // Validate file extension
            if (!in_array($file_ext, $allowedExt)) {
                $errors[] = "File {$file['name']}: Hanya format JPG, JPEG, PNG yang diperbolehkan";
                continue;
            }
            
            // Generate unique filename
            $docTypeName = '';
            switch ($type) {
                case 'kk':
                    $docTypeName = 'Kartu_Keluarga';
                    break;
                case 'ktp_ayah':
                    $docTypeName = 'KTP_Ayah';
                    break;
                case 'ktp_ibu':
                    $docTypeName = 'KTP_Ibu';
                    break;
                default:
                    $docTypeName = $type;
            }
            
            $newName = $docTypeName . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $file_ext;
            $destination = $permohonan_dir . '/' . $newName;
            $savePath = 'uploads/akta_kematian_rs/' . $existing_folder . '/' . $newName;
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $uploaded_dokumen[] = [
                    'type' => $type,
                    'original_name' => $file['name'],
                    'path' => $savePath,
                    'folder' => $existing_folder
                ];
                $has_document = true;
            } else {
                $errors[] = "Gagal mengupload file {$file['name']}";
            }
        }
        
        // Check for existing document if no new upload
        if (!$has_document && isset($_POST['existing_' . $type])) {
            foreach ($dokumen_array as $doc) {
                if ($doc['type'] == $type) {
                    $uploaded_dokumen[] = $doc;
                    break;
                }
            }
        }
    }
    
    // Process dokumen tambahan lainnya
    $dokumen_tambahan_array = [];
    if (isset($_POST['dokumen_tambahan_type']) && is_array($_POST['dokumen_tambahan_type'])) {
        foreach ($_POST['dokumen_tambahan_type'] as $index => $type) {
            if (isset($_FILES['dokumen_tambahan']['name'][$index]) && $_FILES['dokumen_tambahan']['error'][$index] == 0) {
                $file_name = $_FILES['dokumen_tambahan']['name'][$index];
                $file_tmp = $_FILES['dokumen_tambahan']['tmp_name'][$index];
                $file_size = $_FILES['dokumen_tambahan']['size'][$index];
                $file_type = $_FILES['dokumen_tambahan']['type'][$index];
                
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                // Validate file size
                if ($file_size > $maxFileSize) {
                    $errors[] = "File {$file_name} terlalu besar. Maksimal 1MB";
                    continue;
                }
                
                // Validate file extension
                if (!in_array($file_ext, $allowedExt)) {
                    $errors[] = "File {$file_name}: Hanya format JPG, JPEG, PNG yang diperbolehkan";
                    continue;
                }
                
                // Generate unique filename
                $docTypeName = 'Dokumen_Tambahan_' . ($index + 1);
                $newName = $docTypeName . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $file_ext;
                $destination = $permohonan_dir . '/' . $newName;
                $savePath = 'uploads/akta_kematian_rs/' . $existing_folder . '/' . $newName;
                
                if (move_uploaded_file($file_tmp, $destination)) {
                    $dokumen_tambahan_array[] = [
                        'type' => $type,
                        'original_name' => $file_name,
                        'path' => $savePath,
                        'folder' => $existing_folder
                    ];
                } else {
                    $errors[] = "Gagal mengupload file {$file_name}";
                }
            }
        }
    }
    
    // Keep existing dokumen tambahan jika tidak dihapus
    if (isset($_POST['existing_dokumen_tambahan']) && is_array($_POST['existing_dokumen_tambahan'])) {
        foreach ($_POST['existing_dokumen_tambahan'] as $doc_index) {
            if (isset($dokumen_array[$doc_index])) {
                $doc = $dokumen_array[$doc_index];
                // Hanya simpan dokumen yang bukan wajib dan bukan optional standar
                if (!in_array($doc['type'], array_keys($required_docs)) && 
                    !in_array($doc['type'], $optional_docs)) {
                    $uploaded_dokumen[] = $doc;
                }
            }
        }
    }
    
    // Add new dokumen tambahan
    foreach ($dokumen_tambahan_array as $doc) {
        $uploaded_dokumen[] = $doc;
    }
    
    // If no errors, update database
    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = NOW(),\n                updated_at = NOW()"
            : "created_at = NOW(),\n                updated_at = NOW()";

        // Prepare dokumen JSON
        $dokumen_json = json_encode($uploaded_dokumen);
        
        // Update database
        $update_query = $koneksi->prepare("
            UPDATE tb_akta_kematian_rs 
            SET 
                nama_meninggal = ?,
                nik_meninggal = ?,
                tanggal_kematian = ?,
                tempat = ?,
                dusun = ?,
                nama_ayah = ?,
                nik_ayah = ?,
                nama_ibu = ?,
                nik_ibu = ?,
                nama_saksi1 = ?,
                nik_saksi1 = ?,
                nama_saksi2 = ?,
                nik_saksi2 = ?,
                id_kecamatan = ?,
                id_desa = ?,
                nama_kecamatan = ?,
                nama_desa = ?,
                keterangan = ?,
                dokumen = ?,
                nama_folder = ?,
                status = 'diajukan',
                alasan = NULL,
                {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?
        ");
        
        $update_query->bind_param(
            "sssssssssssssiisssssii",
            $nama_meninggal,
            $nik_meninggal,
            $tanggal_kematian,
            $tempat,
            $dusun,
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
            $nama_kecamatan,
            $nama_desa,
            $keterangan,
            $dokumen_json,
            $existing_folder,
            $id,
            $user_id
        );
        
        if ($update_query->execute()) {
            if ($update_query->affected_rows > 0) {
                try {
                    buatNotifikasiOperatorUnit($koneksi, 'tb_akta_kematian_rs', (int)$id, 'Pengajuan Diperbaiki', 'Permohonan telah diperbaiki dan diajukan kembali.');
                } catch (Throwable $e) {
                    error_log('Notifikasi operator RS gagal: ' . $e->getMessage());
                }
            }
            $_SESSION['success_message'] = "Permohonan akta kematian berhasil diperbarui dan diajukan ulang!";
            header('Location: rs_status_permohonan.php');
            exit;
        } else {
            $errors[] = "Gagal mengupdate permohonan: " . $koneksi->error;
        }
    }
}

// Helper function
function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Helper to check existing document
function hasDocument($type, $dokumen_array) {
    foreach ($dokumen_array as $doc) {
        if ($doc['type'] == $type) {
            return $doc;
        }
    }
    return false;
}

// Helper to get dokumen tambahan (yang bukan wajib atau optional standar)
function getDokumenTambahan($dokumen_array) {
    $required_and_optional = ['surat_keterangan_kematian_rs', 'formulir_f201_kematian', 'identitas_meninggal', 'kk', 'ktp_ayah', 'ktp_ibu'];
    $tambahan = [];
    
    foreach ($dokumen_array as $index => $doc) {
        if (!in_array($doc['type'], $required_and_optional)) {
            $doc['index'] = $index;
            $tambahan[] = $doc;
        }
    }
    
    return $tambahan;
}

// Get dokumen tambahan
$dokumen_tambahan = getDokumenTambahan($dokumen_array);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Permohonan Akta Kematian - <?= esc($rs_nama) ?></title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
    <script>window.CSRF_TOKEN = '<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>';</script>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="css/rs_edit_akta_kematian.css">
    <link rel="icon" href="../assets/st sehati.png" type="image/png">
    <style>
        
    </style>
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="tema-form tema-rs">

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">ST SEHATI - <?= esc($rs_nama) ?></div>
        <nav>
            <ul>
                <li><a href="rs_dashboard_user.php">Dashboard</a></li>
                <li><a href="rs_ajukan_permohonan.php">Ajukan Permohonan</a></li>
                <li class="active"><a href="rs_status_permohonan.php">Status Permohonan</a></li>
                <li><a href="rs_profil.php">Profil Saya</a></li>
                <li><a href="rs_formulir.php">Formulir Kependudukan</a></li>
                <li><a href="../logout_pasutri_ahlak.php" style="background: rgba(231, 76, 60, 0.1); border-left-color: #e74c3c;">Logout</a></li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Edit Permohonan Akta Kematian</div>
                <div class="subtitle">Perbaiki data dan ajukan ulang permohonan</div>
            </div>
            <div class="user-info">
                <span><?= esc($_SESSION['nama']) ?></span>
                <span class="role-badge"><?= strtoupper($_SESSION['role']) ?></span>
                <?php include __DIR__ . '/../user/notification_widget.php'; ?>
                <a href="../logout_pasutri_ahlak.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <section class="content">
            <div class="form-card">
                <div class="form-header">
                    <div class="icon-container">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </div>
                    <div>
                        <h2 class="form-title">Edit Permohonan Akta Kematian</h2>
                        <p class="helper">ID Permohonan: <?= esc($data['id']) ?> | Status: <span style="font-weight: 600;"><?= esc(ucfirst(strtolower((string)($data['status'] ?? '-')))) ?></span></p>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="error-box">
                        <strong>Terjadi kesalahan:</strong>
                        <?php foreach ($errors as $error): ?>
                            <p><?= esc($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['success'])): ?>
                    <div class="toast toast-success">
                        Permohonan berhasil diupdate dan diajukan ulang!
                    </div>
                    <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>
                <?php endif; ?>

                <?php if (!empty($data['alasan'])): ?>
                    <div class="error-box" style="background: #fffbeb; border-color: #fbbf24; color: #92400e;">
                        <strong>Alasan Penolakan Sebelumnya:</strong>
                        <p><?= nl2br(esc($data['alasan'])) ?></p>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="editForm">
<?php echo csrf_input(); ?>
                    <!-- Data Pemohon (Readonly) -->
                    <h3 class="section-header">Data Pemohon</h3>
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Pemohon</label>
                            <input type="text" value="<?= esc($data['nama_pemohon']) ?>" readonly class="input bg-gray-100">
                        </div>
                        <div>
                            <label class="field-label">NIK Pemohon</label>
                            <input type="text" value="<?= esc($data['nik_pemohon']) ?>" readonly class="input bg-gray-100">
                        </div>
                        <div>
                            <label class="field-label">No HP</label>
                            <input type="text" value="<?= esc($data['no_hp']) ?>" readonly class="input bg-gray-100">
                        </div>
                        <div>
                            <label class="field-label">Email</label>
                            <input type="email" value="<?= esc($data['email']) ?>" readonly class="input bg-gray-100">
                        </div>
                    </div>

                    <!-- Data Orang yang Meninggal -->
                    <h3 class="section-header">Data Orang yang Meninggal</h3>
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama yang Meninggal <span class="required">*</span></label>
                            <input type="text" name="nama_meninggal" value="<?= esc($data['nama_meninggal']) ?>" required class="input" placeholder="Nama lengkap">
                        </div>
                        <div>
                            <label class="field-label">NIK yang Meninggal <span class="required">*</span></label>
                            <input type="text" name="nik_meninggal" value="<?= esc($data['nik_meninggal']) ?>" required class="input" placeholder="16 digit NIK" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                        </div>
                        <div>
                            <label class="field-label">Tanggal Kematian <span class="required">*</span></label>
                            <input type="date" name="tanggal_kematian" value="<?= esc($data['tanggal_kematian']) ?>" required class="input">
                        </div>
                        <div>
                            <label class="field-label">Tempat Kematian <span class="required">*</span></label>
                            <input type="text" name="tempat" value="<?= esc($data['tempat']) ?>" required class="input" placeholder="Kabupaten Gorontalo / Kota Gorontalo">
                        </div>
                        <div>
                            <label class="field-label">Dusun/Alamat <span class="required">*</span></label>
                            <input type="text" name="dusun" value="<?= esc($data['dusun']) ?>" required class="input" placeholder="Nama dusun/lingkungan">
                        </div>
                    </div>

                    <!-- Data Orang Tua -->
                    <h3 class="section-header">Data Orang Tua</h3>
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Ayah</label>
                            <input type="text" name="nama_ayah" value="<?= esc($data['nama_ayah']) ?>" class="input" placeholder="Nama ayah (jika diketahui)">
                        </div>
                        <div>
                            <label class="field-label">NIK Ayah</label>
                            <input type="text" name="nik_ayah" value="<?= esc($data['nik_ayah']) ?>" class="input" placeholder="16 digit NIK ayah" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                        </div>
                        <div>
                            <label class="field-label">Nama Ibu</label>
                            <input type="text" name="nama_ibu" value="<?= esc($data['nama_ibu']) ?>" class="input" placeholder="Nama ibu (jika diketahui)">
                        </div>
                        <div>
                            <label class="field-label">NIK Ibu</label>
                            <input type="text" name="nik_ibu" value="<?= esc($data['nik_ibu']) ?>" class="input" placeholder="16 digit NIK ibu" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                        </div>
                    </div>

                    <!-- Data Saksi -->
                    <h3 class="section-header">Data Saksi</h3>
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Nama Saksi 1 <span class="required">*</span></label>
                            <input type="text" name="nama_saksi1" value="<?= esc($data['nama_saksi1']) ?>" required class="input" placeholder="Nama lengkap saksi 1">
                        </div>
                        <div>
                            <label class="field-label">NIK Saksi 1 <span class="required">*</span></label>
                            <input type="text" name="nik_saksi1" value="<?= esc($data['nik_saksi1']) ?>" required class="input" placeholder="16 digit NIK saksi 1" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                        </div>
                        <div>
                            <label class="field-label">Nama Saksi 2 <span class="required">*</span></label>
                            <input type="text" name="nama_saksi2" value="<?= esc($data['nama_saksi2']) ?>" required class="input" placeholder="Nama lengkap saksi 2">
                        </div>
                        <div>
                            <label class="field-label">NIK Saksi 2 <span class="required">*</span></label>
                            <input type="text" name="nik_saksi2" value="<?= esc($data['nik_saksi2']) ?>" required class="input" placeholder="16 digit NIK saksi 2" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
                        </div>
                    </div>

                    <!-- Lokasi dengan dropdown kecamatan dan desa -->
                    <h3 class="section-header">Lokasi</h3>
                    <div class="form-grid-2">
                        <div>
                            <label class="field-label">Kecamatan <span class="required">*</span></label>
                            <select name="id_kecamatan" id="kecamatanSelect" required class="select">
                                <option value="">-- Pilih Kecamatan --</option>
                                <?php foreach ($kecamatan_options as $kec): ?>
                                    <option value="<?= esc($kec['id']) ?>" 
                                        <?= ($data['id_kecamatan'] == $kec['id']) ? 'selected' : '' ?>>
                                        <?= esc($kec['nama_kecamatan']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">Desa <span class="required">*</span></label>
                            <select name="id_desa" id="desaSelect" required class="select">
                                <option value="">-- Pilih Desa --</option>
                                <?php foreach ($desa_options as $desa): ?>
                                    <option value="<?= esc($desa['id']) ?>" 
                                        <?= ($data['id_desa'] == $desa['id']) ? 'selected' : '' ?>>
                                        <?= esc($desa['nama_desa']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Keterangan -->
                    <h3 class="section-header">Keterangan</h3>
                    <div>
                        <label class="field-label">Keterangan / Pesan <span class="required">*</span></label>
                        <textarea name="keterangan" required class="input" rows="3" placeholder="Contoh: Permohonan akta kematian" maxlength="500"><?= esc($data['keterangan']) ?></textarea>
                        <p class="helper" style="font-size: 12px;">Maksimal 500 karakter</p>
                    </div>

                    <!-- Dokumen Pendukung -->
                    <h3 class="section-header">Dokumen Pendukung</h3>
                    <div class="f-doc-group">
                        <p class="f-doc-group__judul">Petunjuk:</p>
                        <ul style="margin: 0; padding-left: 20px; color: #4b5563;">
                            <li>Dokumen yang <strong>wajib</strong> diunggah:
                                <ol style="margin: 5px 0 0 0; padding-left: 20px;">
                                    <li>Surat Keterangan Kematian dari Rumah Sakit</li>
                                    <li>Formulir F-2.01 Kematian</li>
                                    <li>Identitas yang Meninggal (KTP/KK)</li>
                                </ol>
                            </li>
                            <li>Dokumen tambahan (jika ada): Kartu Keluarga, KTP Orang Tua, dll</li>
                            <li>Format file yang diperbolehkan: JPG, JPEG, PNG</li>
                            <li>Ukuran maksimal per file: 1MB</li>
                            <li>Biarkan kosong jika tidak ingin mengganti file yang sudah ada</li>
                        </ul>
                    </div>

                    <!-- Dokumen Wajib -->
                    <h4 style="color: #374151; margin-bottom: 15px; font-size: 16px;">Dokumen Wajib:</h4>
                    
                    <!-- 1. Surat Keterangan Kematian RS -->
                    <div class="file-upload-group">
                        <label class="field-label">1. Surat Keterangan Kematian dari Rumah Sakit <span class="required">*</span></label>
                        <?php $existing_doc = hasDocument('surat_keterangan_kematian_rs', $dokumen_array); ?>
                        <?php if ($existing_doc): ?>
                            <div class="existing-doc">
                                <div class="doc-name">File saat ini: <?= esc($existing_doc['original_name']) ?></div>
                                <input type="hidden" name="existing_surat_keterangan_kematian_rs" value="1">
                                <button type="button" class="doc-preview-btn" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$existing_doc['path']) ?? '') ?>')">
                                    <i class="fas fa-eye"></i> Lihat
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="file-wrapper">
                            <label class="file-label" for="surat_keterangan_kematian_rs">Pilih File Baru</label>
                            <span id="surat_keterangan_kematian_rs_name" class="file-name">Belum ada file dipilih</span>
                            <input type="file" id="surat_keterangan_kematian_rs" name="surat_keterangan_kematian_rs" accept=".jpg,.jpeg,.png" class="file-input">
                        </div>
                        <div class="file-info">Maksimal 5 MB per file, dikompres otomatis</div>
                    </div>

                    <!-- 2. Formulir F-2.01 Kematian -->
                    <div class="file-upload-group">
                        <label class="field-label">2. Formulir F-2.01 Kematian <span class="required">*</span></label>
                        <?php $existing_doc = hasDocument('formulir_f201_kematian', $dokumen_array); ?>
                        <?php if ($existing_doc): ?>
                            <div class="existing-doc">
                                <div class="doc-name">File saat ini: <?= esc($existing_doc['original_name']) ?></div>
                                <input type="hidden" name="existing_formulir_f201_kematian" value="1">
                                <button type="button" class="doc-preview-btn" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$existing_doc['path']) ?? '') ?>')">
                                    <i class="fas fa-eye"></i> Lihat
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="file-wrapper">
                            <label class="file-label" for="formulir_f201_kematian">Pilih File Baru</label>
                            <span id="formulir_f201_kematian_name" class="file-name">Belum ada file dipilih</span>
                            <input type="file" id="formulir_f201_kematian" name="formulir_f201_kematian" accept=".jpg,.jpeg,.png" class="file-input">
                        </div>
                        <div class="file-info">Maksimal 5 MB per file, dikompres otomatis</div>
                    </div>

                    <!-- 3. Identitas Meninggal -->
                    <div class="file-upload-group">
                        <label class="field-label">3. Identitas yang Meninggal (KTP/KK) <span class="required">*</span></label>
                        <?php $existing_doc = hasDocument('identitas_meninggal', $dokumen_array); ?>
                        <?php if ($existing_doc): ?>
                            <div class="existing-doc">
                                <div class="doc-name">File saat ini: <?= esc($existing_doc['original_name']) ?></div>
                                <input type="hidden" name="existing_identitas_meninggal" value="1">
                                <button type="button" class="doc-preview-btn" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$existing_doc['path']) ?? '') ?>')">
                                    <i class="fas fa-eye"></i> Lihat
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="file-wrapper">
                            <label class="file-label" for="identitas_meninggal">Pilih File Baru</label>
                            <span id="identitas_meninggal_name" class="file-name">Belum ada file dipilih</span>
                            <input type="file" id="identitas_meninggal" name="identitas_meninggal" accept=".jpg,.jpeg,.png" class="file-input">
                        </div>
                        <div class="file-info">Maksimal 5 MB per file, dikompres otomatis</div>
                    </div>

                    <!-- Dokumen Tambahan Standar -->
                    <h4 style="color: #374151; margin: 30px 0 15px 0; font-size: 16px;">Dokumen Tambahan (Opsional):</h4>
                    
                    <!-- Kartu Keluarga -->
                    <div class="file-upload-group">
                        <label class="field-label">Kartu Keluarga (KK)</label>
                        <?php $existing_doc = hasDocument('kk', $dokumen_array); ?>
                        <?php if ($existing_doc): ?>
                            <div class="existing-doc">
                                <div class="doc-name">File saat ini: <?= esc($existing_doc['original_name']) ?></div>
                                <input type="hidden" name="existing_kk" value="1">
                                <button type="button" class="doc-preview-btn" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$existing_doc['path']) ?? '') ?>')">
                                    <i class="fas fa-eye"></i> Lihat
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="file-wrapper">
                            <label class="file-label" for="kk">Pilih File Baru</label>
                            <span id="kk_name" class="file-name">Belum ada file dipilih</span>
                            <input type="file" id="kk" name="kk" accept=".jpg,.jpeg,.png" class="file-input">
                        </div>
                        <div class="file-info">Maksimal 5 MB per file, dikompres otomatis</div>
                    </div>

                    <!-- KTP Ayah -->
                    <div class="file-upload-group">
                        <label class="field-label">KTP Ayah</label>
                        <?php $existing_doc = hasDocument('ktp_ayah', $dokumen_array); ?>
                        <?php if ($existing_doc): ?>
                            <div class="existing-doc">
                                <div class="doc-name">File saat ini: <?= esc($existing_doc['original_name']) ?></div>
                                <input type="hidden" name="existing_ktp_ayah" value="1">
                                <button type="button" class="doc-preview-btn" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$existing_doc['path']) ?? '') ?>')">
                                    <i class="fas fa-eye"></i> Lihat
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="file-wrapper">
                            <label class="file-label" for="ktp_ayah">Pilih File Baru</label>
                            <span id="ktp_ayah_name" class="file-name">Belum ada file dipilih</span>
                            <input type="file" id="ktp_ayah" name="ktp_ayah" accept=".jpg,.jpeg,.png" class="file-input">
                        </div>
                        <div class="file-info">Maksimal 5 MB per file, dikompres otomatis</div>
                    </div>

                    <!-- KTP Ibu -->
                    <div class="file-upload-group">
                        <label class="field-label">KTP Ibu</label>
                        <?php $existing_doc = hasDocument('ktp_ibu', $dokumen_array); ?>
                        <?php if ($existing_doc): ?>
                            <div class="existing-doc">
                                <div class="doc-name">File saat ini: <?= esc($existing_doc['original_name']) ?></div>
                                <input type="hidden" name="existing_ktp_ibu" value="1">
                                <button type="button" class="doc-preview-btn" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$existing_doc['path']) ?? '') ?>')">
                                    <i class="fas fa-eye"></i> Lihat
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="file-wrapper">
                            <label class="file-label" for="ktp_ibu">Pilih File Baru</label>
                            <span id="ktp_ibu_name" class="file-name">Belum ada file dipilih</span>
                            <input type="file" id="ktp_ibu" name="ktp_ibu" accept=".jpg,.jpeg,.png" class="file-input">
                        </div>
                        <div class="file-info">Maksimal 5 MB per file, dikompres otomatis</div>
                    </div>

                    <!-- Dokumen Tambahan Lainnya -->
                    <h4 style="color: #374151; margin: 30px 0 15px 0; font-size: 16px;">Dokumen Tambahan Lainnya:</h4>
                    
                    <!-- Existing Dokumen Tambahan -->
                    <div id="existing-dokumen-tambahan">
                        <?php foreach ($dokumen_tambahan as $doc): ?>
                            <div class="existing-doc" data-index="<?= $doc['index'] ?>">
                                <div class="doc-name"><?= esc($doc['original_name']) ?> (<?= esc($doc['type']) ?>)</div>
                                <input type="hidden" name="existing_dokumen_tambahan[]" value="<?= $doc['index'] ?>">
                                <button type="button" class="doc-preview-btn" onclick="previewDocument('<?= esc(siprima_document_token_url(__DIR__, (string)$doc['path']) ?? '') ?>')">
                                    <i class="fas fa-eye"></i> Lihat
                                </button>
                                <button type="button" class="doc-remove-btn" onclick="removeExistingDokumenTambahan(this, <?= $doc['index'] ?>)">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Form untuk dokumen tambahan baru -->
                    <div id="dokumen-tambahan-container">
                        <div id="dokumen-tambahan-list">
                            <!-- Dokumen tambahan baru akan ditambahkan di sini -->
                        </div>
                        <button type="button" class="btn-add-tambahan" onclick="addDokumenTambahan()">
                            <i class="fas fa-plus"></i> Tambah Dokumen Lainnya
                        </button>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="flex justify-between items-center mt-6 pt-4 border-t">
                        <a href="rs_status_permohonan.php" class="btn-secondary">Kembali</a>
                        <button type="submit" class="btn-primary">Simpan & Ajukan Ulang</button>
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
            <h3 class="f-doc-group__judul">Preview Dokumen</h3>
            <button onclick="closePreview()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
        </div>
        <div id="previewContent"></div>
    </div>
</div>

<!-- Font Awesome for icons -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>

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
    }    // Stabilkan file upload lintas device/browser (terutama mobile/cloud picker)
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

    // Delegasi global untuk menstabilkan setiap file yang dipilih.
    document.addEventListener('change', function(event) {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;
        if (target.type !== 'file') return;
        stabilizeSelectedFile(target);
    });


    // File input display
    const fileInputs = document.querySelectorAll('.file-input');
    fileInputs.forEach(input => {
        const fileNameSpan = document.getElementById(input.id + '_name');
        
        input.addEventListener('change', function() {
            if (this.files.length > 0) {
                const file = this.files[0];
                const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
                
                // Hide existing doc display when new file is selected
                const existingDoc = this.parentNode.parentNode.querySelector('.existing-doc');
                if (existingDoc) {
                    existingDoc.style.display = 'none';
                }
                
                // Show file name
                if (fileNameSpan) {
                    fileNameSpan.textContent = `${file.name} (${fileSizeMB} MB)`;
                }
                
                // Show warning if file is too large
                if (file.size > 1 * 1024 * 1024) {
                    alert(`File ${file.name} terlalu besar! Maksimal 1MB, file Anda: ${fileSizeMB}MB`);
                    this.value = '';
                    if (fileNameSpan) {
                        fileNameSpan.textContent = 'Belum ada file dipilih';
                    }
                }
            } else {
                if (fileNameSpan) {
                    fileNameSpan.textContent = 'Belum ada file dipilih';
                }
            }
        });
    });

    // AJAX untuk mendapatkan desa berdasarkan kecamatan
    const kecamatanSelect = document.getElementById('kecamatanSelect');
    const desaSelect = document.getElementById('desaSelect');
    
    if (kecamatanSelect) {
        kecamatanSelect.addEventListener('change', function() {
            const kecamatanId = this.value;
            
            if (kecamatanId) {
                // Show loading
                desaSelect.innerHTML = '<option value="">Memuat data desa...</option>';
                desaSelect.disabled = true;
                
                fetch(`../user/get_desa.php?id_kecamatan=${kecamatanId}`)
                    .then(response => response.text())
                    .then(data => {
                        desaSelect.innerHTML = '<option value="">-- Pilih Desa --</option>' + data;
                        desaSelect.disabled = false;
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        desaSelect.innerHTML = '<option value="">Error loading data</option>';
                        desaSelect.disabled = false;
                    });
            } else {
                desaSelect.innerHTML = '<option value="">-- Pilih Desa --</option>';
            }
        });
    }

    // Form validation
    const form = document.getElementById('editForm');
    form.addEventListener('submit', function(e) {
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfToken = (window.CSRF_TOKEN || (csrfMeta ? csrfMeta.getAttribute('content') : '') || '').trim();
        let csrfInput = form.querySelector('input[name="csrf_token"]');
        if (!csrfInput) {
            csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            form.appendChild(csrfInput);
        }
        if (csrfToken) {
            csrfInput.value = csrfToken;
        }
        if (!csrfInput.value || !csrfInput.value.trim()) {
            e.preventDefault();
            alert('Sesi keamanan tidak valid (CSRF token kosong). Muat ulang halaman lalu coba lagi.');
            return false;
        }

        let valid = true;
        const errorMessages = [];
        
        // Clear previous error states
        form.querySelectorAll('.input, .select').forEach(input => {
            input.style.borderColor = '#d1d5db';
        });
        
        // Check required fields
        const requiredFields = form.querySelectorAll('[required]');
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                valid = false;
                field.style.borderColor = '#e53e3e';
            }
        });
        
        // Check NIK format
        const nikFields = form.querySelectorAll('input[name^="nik_"]');
        nikFields.forEach(field => {
            if (field.value && !/^\d{16}$/.test(field.value)) {
                valid = false;
                field.style.borderColor = '#e53e3e';
                errorMessages.push(`${field.name} harus 16 digit angka`);
            }
        });
        
        // Check date not in future
        const tanggalKematian = form.querySelector('input[name="tanggal_kematian"]');
        if (tanggalKematian.value) {
            const selectedDate = new Date(tanggalKematian.value);
            const today = new Date();
            if (selectedDate > today) {
                valid = false;
                tanggalKematian.style.borderColor = '#e53e3e';
                errorMessages.push('Tanggal kematian tidak boleh lebih dari hari ini');
            }
        }
        
        // Check file sizes
        const fileInputs = form.querySelectorAll('input[type="file"]');
        for (let input of fileInputs) {
            if (input.files.length > 0) {
                if (input.dataset.fileStable === '0') {
                    // Fallback mode: jangan blok submit, karena beberapa browser mobile
                    // gagal clone file meski file lokal tetap valid untuk upload normal.
                    console.warn(`File "${input.files[0].name}" tidak bisa distabilkan, lanjut upload mode normal.`);
                }                const file = input.files[0];
                if (file.size > 1 * 1024 * 1024) {
                    valid = false;
                    errorMessages.push(`File ${file.name} terlalu besar (maksimal 1MB)`);
                    break;
                }
            }
        }
        
        // Check required documents (at least one of existing or new)
        const requiredDocs = ['surat_keterangan_kematian_rs', 'formulir_f201_kematian', 'identitas_meninggal'];
        let hasAllRequiredDocs = true;
        
        for (const docType of requiredDocs) {
            const fileInput = document.getElementById(docType);
            const existingInput = document.querySelector(`input[name="existing_${docType}"]`);
            
            if (!fileInput.files.length && !existingInput) {
                hasAllRequiredDocs = false;
                errorMessages.push(`Dokumen ${getDocName(docType)} wajib diunggah`);
            }
        }
        
        if (!hasAllRequiredDocs) {
            valid = false;
        }
        
        if (!valid) {
            e.preventDefault();
            let message = 'Mohon perbaiki kesalahan berikut:\n\n';
            if (errorMessages.length > 0) {
                message += errorMessages.join('\n');
            } else {
                message += 'Harap lengkapi semua bidang yang wajib diisi';
            }
            alert(message);
            return false;
        }
        
        // Confirm submission
        const confirmSubmit = confirm('Apakah Anda yakin data sudah benar dan siap mengajukan ulang permohonan akta kematian?\n\nPastikan semua dokumen wajib sudah tersedia.');
        if (!confirmSubmit) {
            e.preventDefault();
            return false;
        }
        
        return true;
    });
});

// Helper function to get document name
function getDocName(type) {
    const names = {
        'surat_keterangan_kematian_rs': 'Surat Keterangan Kematian dari Rumah Sakit',
        'formulir_f201_kematian': 'Formulir F-2.01 Kematian',
        'identitas_meninggal': 'Identitas yang Meninggal'
    };
    return names[type] || type;
}

// Preview document function
function previewDocument(path) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    // Check if it's an image
    const extension = path.split('.').pop().toLowerCase();
    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (imageExtensions.includes(extension)) {
        content.innerHTML = `<img src="${path}" style="max-width: 100%; max-height: 70vh; display: block; margin: 0 auto;" alt="Preview Dokumen">`;
    } else {
        content.innerHTML = `<p>File tidak dapat dipreview. <a href="${path}" target="_blank" style="color: #2c5282;">Klik untuk membuka</a></p>`;
    }
    
    modal.style.display = 'flex';
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewContent').innerHTML = '';
}

// Dokumen Tambahan Functions
let dokumenTambahanCounter = 0;

function addDokumenTambahan() {
    const container = document.getElementById('dokumen-tambahan-list');
    const newItem = document.createElement('div');
    newItem.className = 'dokumen-tambahan-item';
    newItem.id = 'dokumen-tambahan-' + dokumenTambahanCounter;
    newItem.innerHTML = `
        <div class="dokumen-tambahan-header">
            <h4>Dokumen Tambahan #${dokumenTambahanCounter + 1}</h4>
            <button type="button" class="btn-remove-tambahan" onclick="removeDokumenTambahan(${dokumenTambahanCounter})">
                <i class="fas fa-times"></i> Hapus
            </button>
        </div>
        <div style="margin-bottom: 10px;">
            <label class="field-label">Jenis Dokumen</label>
            <select name="dokumen_tambahan_type[]" class="select" required>
                <option value="">Pilih Jenis Dokumen</option>
                <option value="ktp_saksi1">KTP Saksi 1</option>
                <option value="ktp_saksi2">KTP Saksi 2</option>
                <option value="surat_rt">Surat Keterangan RT/RW</option>
                <option value="surat_kepolisian">Surat Keterangan Kepolisian</option>
                <option value="surat_kesehatan">Surat Keterangan Penyebab Kematian</option>
                <option value="lainnya">Dokumen Lainnya</option>
            </select>
        </div>
        <div class="file-wrapper">
            <label class="file-label" for="dokumen_tambahan_${dokumenTambahanCounter}">Pilih File</label>
            <span id="dokumen_tambahan_name_${dokumenTambahanCounter}" class="file-name">Belum ada file dipilih</span>
            <input type="file" id="dokumen_tambahan_${dokumenTambahanCounter}" name="dokumen_tambahan[]" accept=".jpg,.jpeg,.png" required class="file-input">
        </div>
        <div class="file-info">Maksimal 5 MB per file, dikompres otomatis</div>
    `;
    
    container.appendChild(newItem);
    
    // Add event listener for file input
    const fileInput = document.getElementById(`dokumen_tambahan_${dokumenTambahanCounter}`);
    const fileNameSpan = document.getElementById(`dokumen_tambahan_name_${dokumenTambahanCounter}`);
    
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            const file = this.files[0];
            const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
            
            // Show file name
            if (fileNameSpan) {
                fileNameSpan.textContent = `${file.name} (${fileSizeMB} MB)`;
            }
            
            // Show warning if file is too large
            if (file.size > 1 * 1024 * 1024) {
                alert(`File ${file.name} terlalu besar! Maksimal 1MB, file Anda: ${fileSizeMB}MB`);
                this.value = '';
                if (fileNameSpan) {
                    fileNameSpan.textContent = 'Belum ada file dipilih';
                }
            }
        } else {
            if (fileNameSpan) {
                fileNameSpan.textContent = 'Belum ada file dipilih';
            }
        }
    });
    
    dokumenTambahanCounter++;
}

function removeDokumenTambahan(index) {
    const item = document.getElementById('dokumen-tambahan-' + index);
    if (item) {
        item.remove();
    }
}

function removeExistingDokumenTambahan(button, index) {
    if (confirm('Apakah Anda yakin ingin menghapus dokumen ini?')) {
        const container = button.closest('.existing-doc');
        if (container) {
            // Remove the hidden input
            const hiddenInput = container.querySelector('input[type="hidden"]');
            if (hiddenInput) {
                hiddenInput.remove();
            }
            
            // Hide the container
            container.style.display = 'none';
            
            // Add a marker to indicate this document should be removed
            const removeMarker = document.createElement('input');
            removeMarker.type = 'hidden';
            removeMarker.name = 'remove_dokumen_tambahan[]';
            removeMarker.value = index;
            container.appendChild(removeMarker);
        }
    }
}
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
</body>
</html>
