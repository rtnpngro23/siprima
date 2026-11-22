<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
require_once __DIR__ . '/../functions/akta_kelahiran_kategori.php';
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
$jenis_permohonan = 'hilang';

// Untuk mempertahankan pilihan setelah submit gagal
$selected_kecamatan = isset($_POST['id_kecamatan']) ? (int)$_POST['id_kecamatan'] : '';
$selected_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : '';
$pre_nama_kecamatan = '';
$pre_nama_desa = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_kecamatan = isset($_POST['id_kecamatan']) ? (int)$_POST['id_kecamatan'] : 0;
    $id_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : 0;

    // Kolom nama_bayi dipakai ulang sebagai nama pemilik akta agar daftar dan detail
    // permohonan akta kelahiran tetap membaca satu kolom nama yang sama.
    $nama_subjek = trim($_POST['nama_bayi'] ?? '');
    $nik_subjek = trim($_POST['nik_subjek'] ?? '');
    $no_suket_kehilangan = trim($_POST['no_suket_kehilangan'] ?? '');
    $nomor_akta_lama = trim($_POST['nomor_akta_lama'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($nama_subjek === '') $errors[] = 'Nama yang bersangkutan wajib diisi.';
    if ($nik_subjek === '') {
        $errors[] = 'NIK yang bersangkutan wajib diisi.';
    } elseif (strlen($nik_subjek) !== 16) {
        $errors[] = 'NIK yang bersangkutan harus 16 digit.';
    }
    if ($no_suket_kehilangan === '') $errors[] = 'Nomor surat keterangan kehilangan wajib diisi.';
    if ($keterangan === '') $errors[] = 'Keterangan wajib diisi.';
    if ($id_kecamatan <= 0) $errors[] = 'Kecamatan wajib dipilih.';
    if ($id_desa <= 0) $errors[] = 'Desa wajib dipilih.';

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

    $nama_pemohon = $data_user['nama'] ?? '';
    $nik_pemohon = $data_user['nik'] ?? '';
    $tanggal_buat = date('Ymd_His');

    function cleanFolderName($string) {
        $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', $string);
        $string = preg_replace('/\s+/', '_', trim($string));
        return strtolower($string);
    }

    $kecamatan_clean = cleanFolderName($nama_kecamatan);
    $desa_clean = cleanFolderName($nama_desa);
    $nik_clean = preg_replace('/[^0-9]/', '', $nik_pemohon);
    $pemohon_clean = cleanFolderName($nama_pemohon);
    $subjek_clean = cleanFolderName($nama_subjek);

    $folder_name = $kecamatan_clean . '_' . $desa_clean . '_' . $nik_clean . '_' .
        $pemohon_clean . '_' . $subjek_clean . '_hilang_' . $tanggal_buat;
    if (strlen($folder_name) > 200) {
        $folder_name = $kecamatan_clean . '_' . $desa_clean . '_' . $nik_clean . '_' .
            substr(md5($folder_name), 0, 8) . '_hilang_' . $tanggal_buat;
    }

    $base_upload_dir = __DIR__ . '/uploads/akta_kelahiran/';
    if (!is_dir($base_upload_dir)) {
        mkdir($base_upload_dir, 0755, true);
    }

    $permohonan_dir = $base_upload_dir . $folder_name . '/';
    $pendukung_dir = $permohonan_dir . 'dokumen_pendukung/';
    if (!is_dir($permohonan_dir)) {
        if (!mkdir($permohonan_dir, 0755, true)) {
            $errors[] = 'Gagal membuat folder penyimpanan dokumen: ' . $permohonan_dir;
        }
    }

    $uploadedFiles = [];
    $maxFileSize = 1 * 1024 * 1024; // 1MB
    $requiredDocs = ['suket_kehilangan_polisi', 'foto_selfie'];
    $uploadedDocTypes = [];

    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name']) && empty($errors)) {
        $fileCount = count($_FILES['dokumen']['name']);
        if (!is_dir($pendukung_dir)) {
            mkdir($pendukung_dir, 0755, true);
        }

        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $fileSize = $_FILES['dokumen']['size'][$i];
                $docType = $_POST['doc_type'][$i] ?? 'lainnya';

                $fileType = '';
                $ext = '';
                $uploadValidationError = siprima_validate_uploaded_image(
                    $fileTmp, $fileName, $fileSize, $maxFileSize, $fileType, $ext
                );
                if ($uploadValidationError !== '') {
                    $errors[] = $uploadValidationError;
                    continue;
                }

                switch ($docType) {
                    case 'suket_kehilangan_polisi':
                        $docTypeName = 'suket_kehilangan_kepolisian';
                        break;
                    case 'foto_selfie':
                        $docTypeName = 'foto_selfie_pemohon_memegang_ktp_kk';
                        break;
                    case 'kk':
                        $docTypeName = 'kartu_keluarga';
                        break;
                    default:
                        $docTypeName = 'dokumen_lainnya';
                }

                $newName = $docTypeName . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

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
                if (in_array($docType, $requiredDocs, true)) {
                    $errorMsg .= " (Dokumen wajib!)";
                }
                $errors[] = $errorMsg;
            }
        }

        $htaccessFile = $permohonan_dir . '.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile,
                "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
        }
    }

    $missingRequired = array_diff($requiredDocs, $uploadedDocTypes);
    if (!empty($missingRequired)) {
        $missingNames = [];
        foreach ($missingRequired as $doc) {
            $missingNames[] = $doc === 'suket_kehilangan_polisi'
                ? 'Surat Keterangan Kehilangan dari Kepolisian'
                : 'Foto Selfie Pemohon Memegang KTP/Kartu Keluarga';
        }
        $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $missingNames);
    }

    if (empty($errors)) {
        $no_hp = $data_user['no_hp'] ?? '';
        $email = $data_user['email'] ?? '';
        $dokumenJson = json_encode($uploadedFiles);
        $status = 'diajukan';
        $alasan = '';
        $nomor_akta_lama_simpan = $nomor_akta_lama !== '' ? $nomor_akta_lama : null;

        $stmt = $koneksi->prepare("INSERT INTO tb_akta_kelahiran
            (id_user, id_kecamatan, id_desa,
             nama_pemohon, nik_pemohon, no_hp, email,
             nama_kecamatan, nama_desa, nama_bayi,
             dokumen, status, alasan, keterangan, nama_folder,
             jenis_permohonan, nik_subjek, no_suket_kehilangan, nomor_akta_lama,
             created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

        if (!$stmt) {
            error_log('Akta kelahiran hilang insert prepare error: ' . $koneksi->error);
            $errors[] = 'Terjadi gangguan sistem saat memproses permohonan. Silakan coba lagi.';
        } else {
            $stmt->bind_param(
                "iiissssssssssssssss", // 3 integer + 16 string, urut sama dengan daftar kolom di atas
                $user_id,
                $id_kecamatan,
                $id_desa,
                $nama_pemohon,
                $nik_pemohon,
                $no_hp,
                $email,
                $nama_kecamatan,
                $nama_desa,
                $nama_subjek,
                $dokumenJson,
                $status,
                $alasan,
                $keterangan,
                $folder_name,
                $jenis_permohonan,
                $nik_subjek,
                $no_suket_kehilangan,
                $nomor_akta_lama_simpan
            );

            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Permohonan Akta Kelahiran (Akta Hilang) berhasil diajukan.';
                header('Location: ajukan_permohonan.php');
                exit;
            }

            error_log('Akta kelahiran hilang insert execute error: ' . $stmt->error);
            $errors[] = 'Data belum dapat disimpan. Periksa kembali isian Anda lalu coba lagi.';
            $stmt->close();
        }
    }

    // Pengajuan bersifat atomik: bersihkan semua file jika salah satu validasi/insert gagal.
    if (!empty($errors) && isset($permohonan_dir) && is_dir($permohonan_dir)) {
        siprima_remove_upload_tree($permohonan_dir, $base_upload_dir);
        $uploadedFiles = [];
    }

    $selected_kecamatan = $id_kecamatan;
    $selected_desa = $id_desa;
}

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

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ajukan Akta Kelahiran Hilang</title>
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
        render_user_header_brand('Akta Kelahiran Hilang');
        ?>
        <div class="user-info">
          Hai, <?= esc($_SESSION['nama'] ?? '') ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <div class="form-card">
          <div class="form-header">
            <div class="icon-container">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Form Pengajuan Akta Kelahiran Hilang</h2>
              <p class="helper">
                Kategori: <strong><?= esc(siprima_akta_kelahiran_jenis_label($jenis_permohonan)) ?></strong><br>
                Isi data pemilik akta dan nomor surat keterangan kehilangan dari kepolisian.
              </p>
            </div>
          </div>

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

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Kecamatan</label>
                <input type="text" id="namaKecamatan" readonly class="input bg-gray-100" value="<?= esc($pre_nama_kecamatan) ?>">
              </div>

              <div>
                <label class="field-label">Nama Desa</label>
                <input type="text" id="namaDesa" readonly class="input bg-gray-100" value="<?= esc($pre_nama_desa) ?>">
              </div>
            </div>

            <!-- Data Pemilik Akta -->
            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Pemilik Akta</h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama yang Bersangkutan <span style="color:red">*</span></label>
                <input type="text" name="nama_bayi" value="<?= esc($_POST['nama_bayi'] ?? '') ?>" required class="input" placeholder="Nama lengkap sesuai akta yang hilang">
              </div>
              <div>
                <label class="field-label">NIK yang Bersangkutan <span style="color:red">*</span></label>
                <input type="text" name="nik_subjek" value="<?= esc($_POST['nik_subjek'] ?? '') ?>" required class="input" placeholder="16 digit NIK" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nomor Surat Keterangan Kehilangan <span style="color:red">*</span></label>
                <input type="text" name="no_suket_kehilangan" value="<?= esc($_POST['no_suket_kehilangan'] ?? '') ?>" required class="input" placeholder="Nomor suket dari kepolisian" maxlength="100">
                <p class="helper">Sesuai surat keterangan kehilangan yang diterbitkan kepolisian.</p>
              </div>
              <div>
                <label class="field-label">Nomor Akta Lahir Lama</label>
                <input type="text" name="nomor_akta_lama" value="<?= esc($_POST['nomor_akta_lama'] ?? '') ?>" class="input" placeholder="Isi jika diketahui" maxlength="100">
                <p class="helper">Opsional, membantu petugas menelusuri data akta.</p>
              </div>
            </div>

            <div>
              <label class="field-label">Keterangan <span style="color:red">*</span></label>
              <textarea name="keterangan" required class="input" rows="3" placeholder="Contoh: Permohonan cetak ulang akta kelahiran yang hilang" maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
              <p class="helper">Maksimal 500 karakter</p>
            </div>

            <!-- File Upload -->
            <div>
              <label class="field-label">Unggah Dokumen Pendukung <span style="color:red">*</span></label>
              <div class="f-doc-group">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                </h4>
                <div id="wajibUploadContainer">
                  <div class="file-input-group" id="fileGroupWajib1">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="suket_kehilangan_polisi" selected>Surat Keterangan Kehilangan dari Kepolisian</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib1">Pilih File</label>
                      <span id="fileNameWajib1" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib1" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib1">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>

                  <div class="file-input-group" id="fileGroupWajib2">
                    <select name="doc_type[]" class="file-type-select" required>
                      <option value="foto_selfie" selected>Foto Selfie Pemohon Memegang KTP/Kartu Keluarga</option>
                    </select>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInputWajib2">Upload Foto Selfie</label>
                      <span id="fileNameWajib2" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInputWajib2" name="dokumen[]" accept="image/jpeg,image/png" required class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSizeWajib2">Maksimal 5 MB per file, dikompres otomatis</div>
                  </div>
                </div>
              </div>

              <div class="f-doc-group f-doc-group--opsional">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-check-circle"></i> Dokumen Opsional (Jika Ada)
                </h4>
                <div class="file-input-group" id="fileGroupOpsional1">
                  <select name="doc_type[]" class="file-type-select">
                    <option value="">Pilih Jenis Dokumen (Opsional)</option>
                    <option value="kk">Kartu Keluarga (KK)</option>
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

              <p class="helper">
                <strong>Dokumen wajib:</strong><br>
                1. Surat Keterangan Kehilangan dari Kepolisian<br>
                2. Foto Selfie Pemohon Memegang KTP/Kartu Keluarga<br><br>
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
  const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB

  function formatFileSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' Bytes';
  }

  document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');

    if (menuToggle && sidebar) {
      menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
      });
      document.addEventListener('click', function(event) {
        if (window.innerWidth <= 768 && !sidebar.contains(event.target) &&
            !menuToggle.contains(event.target) && sidebar.classList.contains('open')) {
          sidebar.classList.remove('open');
        }
      });
    }

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

    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout.php');
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    if (logoutModal) {
      logoutModal.addEventListener('click', function(event) {
        if (event.target === logoutModal) hideLogoutModal();
      });
    }

    ['Wajib1', 'Wajib2', 'Opsional1'].forEach(function(suffix) {
      const input = document.getElementById('fileInput' + suffix);
      const fileNameSpan = document.getElementById('fileName' + suffix);
      const fileSizeSpan = document.getElementById('fileSize' + suffix);
      if (!input || !fileNameSpan || !fileSizeSpan) return;

      const defaultSizeText = fileSizeSpan.textContent;
      input.addEventListener('change', function() {
        if (this.files.length === 0) {
          fileNameSpan.textContent = 'Belum ada file dipilih';
          fileSizeSpan.textContent = defaultSizeText;
          return;
        }
        const file = this.files[0];
        fileNameSpan.textContent = file.name;
        if (file.size > MAX_FILE_SIZE) {
          fileSizeSpan.innerHTML = '<span style="color: red;">&#10060; Ukuran file: ' + formatFileSize(file.size) + ' (gagal dikompres, pilih gambar lain)</span>';
          this.value = '';
          fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
        } else {
          fileSizeSpan.textContent = 'Ukuran: ' + formatFileSize(file.size);
        }
      });
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
    namaDesaInput.value = this.value !== "" ? this.options[this.selectedIndex].text : "";
  });

  // Alur submit sama dengan form akta kelahiran: file disalin ke Blob stabil lebih dulu
  // karena Chrome Android dapat menampilkan ERR_UPLOAD_FILE_CHANGED saat membuat multipart.
  document.getElementById('aktaForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (this.dataset.submitting === '1') return;

    const submitButton = this.querySelector('button[type="submit"]');
    const errorMessages = [];

    const nikSubjek = this.querySelector('input[name="nik_subjek"]');
    if (nikSubjek && nikSubjek.value.length !== 16) {
      errorMessages.push('NIK yang Bersangkutan harus 16 digit');
      nikSubjek.style.borderColor = '#ef4444';
    }

    const fileInputs = this.querySelectorAll('input[type="file"]');
    for (const input of fileInputs) {
      if (input.value && input.files.length === 0) {
        errorMessages.push('Ada file yang tidak dapat diakses dari browser. Pilih ulang file tersebut.');
      }
      if (input.files.length > 0 && input.files[0].size > MAX_FILE_SIZE) {
        errorMessages.push('File "' + input.files[0].name + '" belum berhasil dikompres, pilih ulang file tersebut');
      }
    }

    const suketInput = document.getElementById('fileInputWajib1');
    const selfieInput = document.getElementById('fileInputWajib2');
    if (!suketInput.files.length) errorMessages.push('Surat Keterangan Kehilangan dari Kepolisian wajib diupload');
    if (!selfieInput.files.length) errorMessages.push('Foto Selfie Pemohon Memegang KTP/Kartu Keluarga wajib diupload');

    if (errorMessages.length > 0) {
      alert('Perbaiki kesalahan berikut:\n• ' + errorMessages.join('\n• '));
      return;
    }

    if (!confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan akta kelahiran yang hilang?\n\nPastikan semua dokumen wajib sudah diupload.')) {
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
              submitButton.textContent = percent < 100 ? 'Mengunggah ' + percent + '%' : 'Menyimpan permohonan...';
            }
          })
        : await fetch(this.action || window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            redirect: 'follow'
          });

      if (!response.ok) {
        throw new Error('Server merespons dengan status ' + response.status);
      }

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
  });
  </script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>
