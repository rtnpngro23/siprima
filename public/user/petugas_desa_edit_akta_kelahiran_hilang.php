<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/petugas_desa_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';
require_once __DIR__ . '/../functions/akta_kelahiran_kategori.php';

if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$permohonan_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($permohonan_id <= 0) {
    header('Location: petugas_desa_status_permohonan.php?error=not_found');
    exit;
}

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kelahiran LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}

// Batas akses horizontal: permohonan hanya bisa dibuka pemiliknya sendiri.
$stmt = $koneksi->prepare("SELECT
    id, nama_pemohon, nik_pemohon, no_hp, email,
    nama_kecamatan, nama_desa, nama_bayi, nik_subjek,
    no_suket_kehilangan, nomor_akta_lama, keterangan,
    dokumen, status, alasan, nama_folder, jenis_permohonan
    FROM tb_akta_kelahiran
    WHERE id = ? AND id_user = ?");
$stmt->bind_param('ii', $permohonan_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header('Location: petugas_desa_status_permohonan.php?error=not_found');
    exit;
}

$permohonan = $res->fetch_assoc();
$stmt->close();

// Kategori lain punya form edit sendiri yang memuat data kelahiran lengkap.
if (siprima_akta_kelahiran_jenis_normalize($permohonan['jenis_permohonan'] ?? null) !== 'hilang') {
    header('Location: petugas_desa_edit_akta_kelahiran.php?id=' . $permohonan_id);
    exit;
}

if (!in_array(strtolower((string)$permohonan['status']), ['diajukan', 'ditolak'], true)) {
    header('Location: petugas_desa_status_permohonan.php?error=cannot_edit');
    exit;
}

$dokumen_data = json_decode((string)$permohonan['dokumen'], true);
if (!is_array($dokumen_data)) {
    $dokumen_data = [];
}

$errors = [];

function normalizeHilangDocType($type) {
    $type = strtolower(trim((string)$type));
    $aliases = [
        'suket_kehilangan_kepolisian' => 'suket_kehilangan_polisi',
        'surat_kehilangan_polisi' => 'suket_kehilangan_polisi',
    ];
    return $aliases[$type] ?? $type;
}

function docLabelHilang($type) {
    $labels = [
        'suket_kehilangan_polisi' => 'Surat Keterangan Kehilangan dari Kepolisian',
        'foto_selfie' => 'Foto Selfie Pemohon Memegang KTP/Kartu Keluarga',
        'kk' => 'Kartu Keluarga (KK)',
        'lainnya' => 'Dokumen Lain',
    ];
    return $labels[$type] ?? $type;
}

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_subjek = trim($_POST['nama_bayi'] ?? '');
    $nik_subjek = trim($_POST['nik_subjek'] ?? '');
    $no_suket_kehilangan = trim($_POST['no_suket_kehilangan'] ?? '');
    $nomor_akta_lama = trim($_POST['nomor_akta_lama'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($nama_subjek === '') $errors[] = 'Nama yang bersangkutan harus diisi';
    if ($nik_subjek === '') {
        $errors[] = 'NIK yang bersangkutan harus diisi';
    } elseif (!preg_match('/^[0-9]{16}$/', $nik_subjek)) {
        $errors[] = 'NIK yang bersangkutan harus 16 digit angka';
    }
    if ($no_suket_kehilangan === '') $errors[] = 'Nomor surat keterangan kehilangan harus diisi';
    if ($keterangan === '') $errors[] = 'Keterangan harus diisi';

    $folder_name = (string)($permohonan['nama_folder'] ?? '');
    $base_upload_dir = __DIR__ . '/uploads/akta_kelahiran/';
    $user_folder_path = $base_upload_dir . $folder_name . '/';

    if (!file_exists($user_folder_path)) {
        if (!mkdir($user_folder_path, 0755, true)) {
            $errors[] = 'Gagal membuat folder penyimpanan dokumen';
        } else {
            $htaccessFile = $user_folder_path . '.htaccess';
            if (!file_exists($htaccessFile)) {
                file_put_contents($htaccessFile,
                    "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
            }
        }
    }

    // Dokumen lama yang dipertahankan + dokumen baru digabung menjadi satu daftar final.
    $dokumen_baru = [];
    if (isset($_POST['existing_docs']) && is_array($_POST['existing_docs'])) {
        foreach ($_POST['existing_docs'] as $doc_index) {
            if (isset($dokumen_data[$doc_index])) {
                $doc = $dokumen_data[$doc_index];
                $doc['type'] = normalizeHilangDocType($doc['type'] ?? 'lainnya');
                $dokumen_baru[] = $doc;
            }
        }
    }

    $maxFileSize = 1 * 1024 * 1024; // 1MB
    if (isset($_FILES['dokumen']) && is_array($_FILES['dokumen']['name']) && empty($errors)) {
        $doc_types = isset($_POST['doc_type_new']) && is_array($_POST['doc_type_new']) ? $_POST['doc_type_new'] : [];
        $fileCount = count($_FILES['dokumen']['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['dokumen']['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['dokumen']['tmp_name'][$i];
                $fileName = basename($_FILES['dokumen']['name'][$i]);
                $fileSize = $_FILES['dokumen']['size'][$i];
                $docType = normalizeHilangDocType($doc_types[$i] ?? 'lainnya');

                $fileType = '';
                $ext = '';
                $uploadValidationError = siprima_validate_uploaded_image(
                    $fileTmp, $fileName, $fileSize, $maxFileSize, $fileType, $ext
                );
                if ($uploadValidationError !== '') {
                    $errors[] = $uploadValidationError;
                    continue;
                }

                $clean_doc_type = preg_replace('/[^a-z0-9]/', '_', strtolower($docType));
                $newName = $clean_doc_type . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

                if (move_uploaded_file($fileTmp, $user_folder_path . $newName)) {
                    $dokumen_baru[] = [
                        'path' => 'uploads/akta_kelahiran/' . $folder_name . '/' . $newName,
                        'type' => $docType,
                        'original_name' => $fileName,
                        'uploaded_name' => $newName,
                        'size' => $fileSize,
                        'uploaded_at' => date('Y-m-d H:i:s')
                    ];
                } else {
                    $errors[] = "File {$fileName}: Gagal menyimpan file";
                }
            } elseif ($_FILES['dokumen']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $fileName = basename($_FILES['dokumen']['name'][$i] ?? 'dokumen');
                $errors[] = "File {$fileName}: " . siprima_upload_error_message($_FILES['dokumen']['error'][$i]);
            }
        }
    }

    $requiredDocs = ['suket_kehilangan_polisi', 'foto_selfie'];
    $docTypesFinal = array_map('normalizeHilangDocType', array_column($dokumen_baru, 'type'));
    $missingRequired = array_diff($requiredDocs, $docTypesFinal);
    if (!empty($missingRequired)) {
        $missingNames = array_map('docLabelHilang', $missingRequired);
        $errors[] = 'Dokumen wajib belum ada: ' . implode(', ', $missingNames);
    }

    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        $dokumen_json = json_encode(array_values($dokumen_baru));
        $nomor_akta_lama_simpan = $nomor_akta_lama !== '' ? $nomor_akta_lama : null;

        $stmt = $koneksi->prepare("UPDATE tb_akta_kelahiran SET
            nama_bayi = ?,
            nik_subjek = ?,
            no_suket_kehilangan = ?,
            nomor_akta_lama = ?,
            keterangan = ?,
            dokumen = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?");

        if (!$stmt) {
            error_log('Akta kelahiran hilang update prepare error: ' . $koneksi->error);
            $errors[] = 'Terjadi gangguan sistem saat memperbarui permohonan. Silakan coba lagi.';
        } else {
            $stmt->bind_param(
                'ssssssii', // 6 string sesuai daftar SET lalu WHERE id, id_user
                $nama_subjek,
                $nik_subjek,
                $no_suket_kehilangan,
                $nomor_akta_lama_simpan,
                $keterangan,
                $dokumen_json,
                $permohonan_id,
                $user_id
            );

            if ($stmt->execute()) {
                logPermohonanStatusHistory(
                    $koneksi,
                    'tb_akta_kelahiran',
                    $permohonan_id,
                    'diajukan',
                    null,
                    $user_id,
                    (string)($_SESSION['role'] ?? 'user'),
                    'resubmit'
                );
                $_SESSION['success_message'] = 'Permohonan akta kelahiran hilang berhasil diperbarui dan diajukan kembali!';
                header('Location: petugas_desa_status_permohonan.php');
                exit;
            }

            error_log('Akta kelahiran hilang update execute error: ' . $stmt->error);
            $errors[] = 'Data belum dapat disimpan. Periksa kembali isian Anda lalu coba lagi.';
            $stmt->close();
        }
    }

    // Tampilkan kembali isian terakhir milik pemohon saat validasi gagal.
    $permohonan['nama_bayi'] = $nama_subjek;
    $permohonan['nik_subjek'] = $nik_subjek;
    $permohonan['no_suket_kehilangan'] = $no_suket_kehilangan;
    $permohonan['nomor_akta_lama'] = $nomor_akta_lama;
    $permohonan['keterangan'] = $keterangan;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Edit Permohonan Akta Kelahiran Hilang (Petugas Desa)</title>

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
          'Edit Akta Kelahiran Hilang',
          'Petugas Desa - ' . (string)($permohonan['nama_kecamatan'] ?? '') . ' / ' . (string)($permohonan['nama_desa'] ?? '')
        );
        ?>
        <div class="user-info">
          <span class="user-role">Petugas Desa</span>
          Hai, <?= esc($_SESSION['nama'] ?? '') ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <div class="form-card">
          <div class="form-header">
            <div>
              <h2 class="form-title">Perbaiki Permohonan Akta Kelahiran Hilang</h2>
              <p class="helper">Kategori: <strong><?= esc(siprima_akta_kelahiran_jenis_label('hilang')) ?></strong></p>
            </div>
          </div>

          <?php if (!empty($permohonan['alasan'])): ?>
            <div class="error-box">
              <p><strong>Alasan penolakan petugas:</strong></p>
              <p><?= esc($permohonan['alasan']) ?></p>
            </div>
          <?php endif; ?>

          <?php if (!empty($errors)): ?>
            <div class="error-box">
              <?php foreach ($errors as $err) echo '<p>' . esc($err) . '</p>'; ?>
              <p>Data teks tetap tersimpan. Demi keamanan browser, pilih ulang file yang disebut gagal di atas.</p>
            </div>
          <?php endif; ?>

          <form method="POST" enctype="multipart/form-data" id="editHilangForm">
<?php echo csrf_input(); ?>
            <input type="hidden" name="id" value="<?= (int)$permohonan_id ?>">

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Pemohon</label>
                <input type="text" value="<?= esc($permohonan['nama_pemohon'] ?? '') ?>" readonly class="input bg-gray-100">
              </div>
              <div>
                <label class="field-label">NIK Pemohon</label>
                <input type="text" value="<?= esc($permohonan['nik_pemohon'] ?? '') ?>" readonly class="input bg-gray-100">
              </div>
              <div>
                <label class="field-label">Kecamatan</label>
                <input type="text" value="<?= esc($permohonan['nama_kecamatan'] ?? '') ?>" readonly class="input bg-gray-100">
              </div>
              <div>
                <label class="field-label">Desa</label>
                <input type="text" value="<?= esc($permohonan['nama_desa'] ?? '') ?>" readonly class="input bg-gray-100">
              </div>
            </div>

            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data Pemilik Akta</h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama yang Bersangkutan <span style="color:red">*</span></label>
                <input type="text" name="nama_bayi" value="<?= esc($permohonan['nama_bayi'] ?? '') ?>" required class="input" placeholder="Nama lengkap sesuai akta yang hilang">
              </div>
              <div>
                <label class="field-label">NIK yang Bersangkutan <span style="color:red">*</span></label>
                <input type="text" name="nik_subjek" value="<?= esc($permohonan['nik_subjek'] ?? '') ?>" required class="input" placeholder="16 digit NIK" maxlength="16" pattern="[0-9]{16}" title="Harus 16 digit angka">
              </div>
            </div>

            <div class="form-grid-2">
              <div>
                <label class="field-label">Nomor Surat Keterangan Kehilangan <span style="color:red">*</span></label>
                <input type="text" name="no_suket_kehilangan" value="<?= esc($permohonan['no_suket_kehilangan'] ?? '') ?>" required class="input" placeholder="Nomor suket dari kepolisian" maxlength="100">
              </div>
              <div>
                <label class="field-label">Nomor Akta Lahir Lama</label>
                <input type="text" name="nomor_akta_lama" value="<?= esc($permohonan['nomor_akta_lama'] ?? '') ?>" class="input" placeholder="Isi jika diketahui" maxlength="100">
              </div>
            </div>

            <div>
              <label class="field-label">Keterangan <span style="color:red">*</span></label>
              <textarea name="keterangan" required class="input" rows="3" maxlength="500"><?= esc($permohonan['keterangan'] ?? '') ?></textarea>
              <p class="helper">Maksimal 500 karakter</p>
            </div>

            <?php if (!empty($dokumen_data)): ?>
            <div class="existing-docs-container">
              <div class="existing-docs-title">Dokumen yang Sudah Ada (Centang untuk mempertahankan):</div>
              <?php foreach ($dokumen_data as $index => $doc): ?>
              <?php $doc_type = normalizeHilangDocType($doc['type'] ?? 'lainnya'); ?>
              <div class="existing-doc-item" data-index="<?= (int)$index ?>">
                <div class="doc-info">
                  <span class="doc-type-badge" data-doc-type="<?= esc($doc_type) ?>"><?= esc(docLabelHilang($doc_type)) ?></span>
                  <span class="doc-name"><?= esc($doc['original_name'] ?? 'Dokumen') ?></span>
                </div>
                <div class="doc-actions">
                  <input type="checkbox" name="existing_docs[]" value="<?= (int)$index ?>" checked class="doc-checkbox">
                  <?php if (isset($doc['path']) && file_exists(__DIR__ . '/' . $doc['path'])): ?>
                  <a class="btn-preview" target="_blank" rel="noopener noreferrer"
                     href="<?= esc(siprima_document_token_url(__DIR__, (string)$doc['path']) ?? '') ?>">Lihat</a>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div>
              <label class="field-label">Unggah Dokumen Baru (Jika Perlu)</label>
              <div class="f-doc-group">
                <p class="f-doc-group__judul"><strong>Dokumen wajib:</strong></p>
                <ul style="margin: 0; padding-left: 20px; color: #4b5563;">
                  <li><strong>Surat Keterangan Kehilangan dari Kepolisian</strong></li>
                  <li><strong>Foto Selfie Pemohon Memegang KTP/Kartu Keluarga</strong></li>
                </ul>
                <p style="margin: 10px 0 0 0; color: #dc2626;"><strong>Jika dokumen sudah ada di atas dan masih benar, tidak perlu diupload ulang.</strong></p>
              </div>

              <?php for ($baris = 1; $baris <= 2; $baris++): ?>
              <div class="file-input-group">
                <select name="doc_type_new[]" class="file-type-select">
                  <option value="">Pilih Jenis Dokumen</option>
                  <option value="suket_kehilangan_polisi">Surat Keterangan Kehilangan dari Kepolisian</option>
                  <option value="foto_selfie">Foto Selfie Pemohon Memegang KTP/Kartu Keluarga</option>
                  <option value="kk">Kartu Keluarga (KK)</option>
                  <option value="lainnya">Dokumen Lainnya</option>
                </select>
                <div class="file-wrapper">
                  <label class="file-label" for="fileInput<?= $baris ?>">Pilih File</label>
                  <span id="fileName<?= $baris ?>" class="file-name">Belum ada file dipilih</span>
                  <input type="file" id="fileInput<?= $baris ?>" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" class="file-input">
                </div>
                <div class="file-size-warning">Maksimal 5 MB per file, dikompres otomatis</div>
              </div>
              <?php endfor; ?>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="petugas_desa_status_permohonan.php" class="btn-secondary">Batal</a>
              <button type="submit" class="btn-primary">Simpan &amp; Ajukan Ulang</button>
            </div>
          </form>
        </div>
      </section>
    </main>
  </div>

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
  const MAX_FILE_SIZE = 1 * 1024 * 1024;

  document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (menuToggle && sidebar) {
      menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
      });
    }

    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');

    if (logoutBtn2) logoutBtn2.addEventListener('click', function() {
      logoutModal.classList.add('show');
    });
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout.php');
    if (cancelLogout) cancelLogout.addEventListener('click', function() {
      logoutModal.classList.remove('show');
    });

    document.querySelectorAll('input[type="file"]').forEach(function(input) {
      input.addEventListener('change', function() {
        const nameSpan = document.getElementById('fileName' + this.id.replace('fileInput', ''));
        if (!this.files.length) {
          if (nameSpan) nameSpan.textContent = 'Belum ada file dipilih';
          return;
        }
        if (this.files[0].size > MAX_FILE_SIZE) {
          alert('File "' + this.files[0].name + '" belum berhasil dikompres, pilih ulang file tersebut');
          this.value = '';
          if (nameSpan) nameSpan.textContent = 'File terlalu besar, pilih file lain';
          return;
        }
        if (nameSpan) nameSpan.textContent = this.files[0].name;
      });
    });
  });

  // Dokumen wajib dihitung dari gabungan dokumen lama yang dicentang dan dokumen baru,
  // sama seperti pemeriksaan di sisi server.
  document.getElementById('editHilangForm').addEventListener('submit', function(e) {
    const wajib = {
      suket_kehilangan_polisi: 'Surat Keterangan Kehilangan dari Kepolisian',
      foto_selfie: 'Foto Selfie Pemohon Memegang KTP/Kartu Keluarga'
    };
    const dimiliki = new Set();

    document.querySelectorAll('input[name="existing_docs[]"]:checked').forEach(function(checkbox) {
      const badge = checkbox.closest('.existing-doc-item').querySelector('.doc-type-badge');
      if (badge) dimiliki.add(badge.dataset.docType || '');
    });

    const selects = document.querySelectorAll('select[name="doc_type_new[]"]');
    const files = document.querySelectorAll('input[name="dokumen[]"]');
    selects.forEach(function(select, index) {
      if (files[index] && files[index].files.length > 0 && select.value) {
        dimiliki.add(select.value);
      }
    });

    const kurang = Object.keys(wajib).filter(function(kode) { return !dimiliki.has(kode); });
    if (kurang.length > 0) {
      e.preventDefault();
      alert('Dokumen wajib belum ada:\n• ' + kurang.map(function(kode) { return wajib[kode]; }).join('\n• '));
    }
  });
  </script>

<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
</body>
</html>
