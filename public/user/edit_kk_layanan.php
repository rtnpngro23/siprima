<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/document_token.php';
require_once __DIR__ . '/../functions/kk_kategori.php';

if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$permohonan_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($permohonan_id <= 0) {
    header('Location: status_permohonan.php?error=not_found');
    exit;
}

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_kartukeluarga LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}

// Batas akses horizontal: permohonan hanya bisa dibuka pemiliknya sendiri.
$stmt = $koneksi->prepare("SELECT
    id, nama_pemohon, nik_pemohon, nama_kecamatan, nama_desa,
    nama_kepalaKeluarga, nik_subjek, keterangan,
    dokumen, status, alasan, nama_folder, kategori_layanan
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
$stmt->close();

// Kategori kk_baru punya form edit sendiri yang memuat isian F-1.01.
$kategori = siprima_kk_kategori_normalize($permohonan['kategori_layanan'] ?? null);
$config = siprima_kk_layanan_config($kategori);
if ($config === null) {
    header('Location: edit_kk.php?id=' . $permohonan_id);
    exit;
}

if (!in_array(strtolower((string)$permohonan['status']), ['diajukan', 'ditolak'], true)) {
    header('Location: status_permohonan.php?error=cannot_edit');
    exit;
}

$dokumen_data = json_decode((string)$permohonan['dokumen'], true);
if (!is_array($dokumen_data)) {
    $dokumen_data = [];
}

$errors = [];

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_subjek = trim((string)($_POST['nama_subjek'] ?? ''));
    $nik_subjek = trim((string)($_POST['nik_subjek'] ?? ''));
    $keterangan = trim((string)($_POST['keterangan'] ?? ''));

    $errors = siprima_kk_layanan_validasi($config, $nama_subjek, $nik_subjek, $keterangan);

    // nama_folder berasal dari DB, tetapi tetap dicek agar unggahan tidak pernah keluar dari uploads/kk/.
    $folder_name = (string)($permohonan['nama_folder'] ?? '');
    if ($folder_name === '' || basename($folder_name) !== $folder_name) {
        $errors[] = 'Folder dokumen permohonan tidak valid. Hubungi admin.';
    }
    $user_folder_path = __DIR__ . '/uploads/kk/' . $folder_name . '/';

    // Dokumen lama yang dipertahankan + dokumen baru digabung menjadi satu daftar final.
    $dipertahankan = array_map('intval', isset($_POST['existing_docs']) && is_array($_POST['existing_docs']) ? $_POST['existing_docs'] : []);
    $dokumen_baru = [];
    foreach ($dipertahankan as $doc_index) {
        if (isset($dokumen_data[$doc_index])) $dokumen_baru[] = $dokumen_data[$doc_index];
    }

    $unggahan = [];
    if (empty($errors)) {
        $doc_types = isset($_POST['doc_type_new']) && is_array($_POST['doc_type_new']) ? $_POST['doc_type_new'] : [];
        $unggahan = siprima_kk_layanan_simpan_unggahan($kategori, $doc_types, $user_folder_path, 'uploads/kk/' . $folder_name . '/', $errors);
        $dokumen_baru = array_merge($dokumen_baru, $unggahan);
        $kurang = siprima_kk_layanan_dokumen_kurang($kategori, $dokumen_baru);
        if ($kurang) $errors[] = 'Dokumen wajib belum ada: ' . implode(', ', $kurang);
    }

    if (empty($errors)) {
        $tanggal_submit_clause = $has_resubmitted_at
            ? "resubmitted_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP"
            : "created_at = CURRENT_TIMESTAMP,\n            updated_at = CURRENT_TIMESTAMP";

        $dokumen_json = json_encode(array_values($dokumen_baru));

        $stmt = $koneksi->prepare("UPDATE tb_kartukeluarga SET
            nama_kepalaKeluarga = ?,
            nik_subjek = ?,
            keterangan = ?,
            dokumen = ?,
            status = 'diajukan',
            alasan = NULL,
            {$tanggal_submit_clause}
            WHERE id = ? AND id_user = ?");

        if (!$stmt) {
            error_log('KK layanan update prepare error: ' . $koneksi->error);
            $errors[] = 'Terjadi gangguan sistem saat memperbarui permohonan. Silakan coba lagi.';
        } else {
            $stmt->bind_param(
                'ssssii', // 4 string sesuai daftar SET lalu WHERE id, id_user
                $nama_subjek,
                $nik_subjek,
                $keterangan,
                $dokumen_json,
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
                    $user_id,
                    (string)($_SESSION['role'] ?? 'user'),
                    'resubmit'
                );
                // Hapus file lama yang tidak dipertahankan, sama seperti edit_kk.php.
                foreach ($dokumen_data as $index => $doc) {
                    if (!in_array((int)$index, $dipertahankan, true)
                        && isset($doc['path']) && file_exists(__DIR__ . '/' . $doc['path'])) {
                        @unlink(__DIR__ . '/' . $doc['path']);
                    }
                }
                $_SESSION['success_message'] = 'Permohonan ' . siprima_kk_kategori_label($kategori) . ' berhasil diperbarui dan diajukan kembali!';
                header('Location: status_permohonan.php');
                exit;
            }

            error_log('KK layanan update execute error: ' . $stmt->error);
            $errors[] = 'Data belum dapat disimpan. Periksa kembali isian Anda lalu coba lagi.';
            $stmt->close();
        }
    }

    // Unggahan baru dibuang jika perubahan gagal disimpan; dokumen lama tetap utuh.
    foreach ($unggahan as $doc) {
        @unlink(__DIR__ . '/' . $doc['path']);
    }

    // Tampilkan kembali isian terakhir milik pemohon saat validasi gagal.
    $permohonan['nama_kepalaKeluarga'] = $nama_subjek;
    $permohonan['nik_subjek'] = $nik_subjek;
    $permohonan['keterangan'] = $keterangan;
}

$link_f106 = $kategori === 'perubahan_data' ? siprima_kk_link_f106($koneksi) : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Edit <?= esc(siprima_kk_kategori_label($kategori)) ?></title>
  <link rel="stylesheet" href="css/edit_permohonan_akta_kelahiran.css">
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
        render_user_header_brand('Edit Kartu Keluarga');
        ?>
        <div class="user-info">
          Hai, <?= esc($_SESSION['nama'] ?? '') ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <div class="form-card">
          <div class="form-header">
            <div>
              <h2 class="form-title">Perbaiki Permohonan <?= esc(siprima_kk_kategori_label($kategori)) ?></h2>
              <p class="helper">Kategori: <strong><?= esc(siprima_kk_kategori_label($kategori)) ?></strong></p>
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

          <form method="POST" enctype="multipart/form-data" id="editKkLayananForm">
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

            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data yang Diajukan</h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label"><?= esc($config['label_nama']) ?> <span style="color:red">*</span></label>
                <input type="text" name="nama_subjek" value="<?= esc($permohonan['nama_kepalaKeluarga'] ?? '') ?>" required class="input" maxlength="100" placeholder="Nama lengkap sesuai KK/KTP">
              </div>
              <div>
                <label class="field-label"><?= esc($config['label_nik']) ?> <span style="color:red">*</span></label>
                <input type="text" name="nik_subjek" value="<?= esc($permohonan['nik_subjek'] ?? '') ?>" required class="input" placeholder="16 digit NIK" maxlength="16" pattern="[0-9]{16}" inputmode="numeric" title="Harus 16 digit angka">
              </div>
            </div>

            <div>
              <label class="field-label"><?= $config['keterangan_wajib'] ? 'Data yang Diubah <span style="color:red">*</span>' : 'Keterangan (Opsional)' ?></label>
              <textarea name="keterangan" <?= $config['keterangan_wajib'] ? 'required' : '' ?> class="input" rows="3" maxlength="500" placeholder="<?= esc($config['placeholder_keterangan']) ?>"><?= esc($permohonan['keterangan'] ?? '') ?></textarea>
              <p class="helper">Maksimal 500 karakter</p>
            </div>

            <?php if (!empty($dokumen_data)): ?>
            <div class="existing-docs-container">
              <div class="existing-docs-title">Dokumen yang Sudah Ada (Centang untuk mempertahankan):</div>
              <?php foreach ($dokumen_data as $index => $doc): ?>
              <?php $doc_type = (string)($doc['type'] ?? 'lainnya'); ?>
              <div class="existing-doc-item" data-index="<?= (int)$index ?>">
                <div class="doc-info">
                  <span class="doc-type-badge" data-doc-type="<?= esc($doc_type) ?>"><?= esc(siprima_kk_dokumen_label($kategori, $doc_type)) ?></span>
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
                  <?php foreach ($config['wajib'] as $docLabel): ?>
                  <li><strong><?= esc($docLabel) ?></strong></li>
                  <?php endforeach; ?>
                </ul>
                <?php if ($link_f106 !== ''): ?>
                <p style="margin: 10px 0 0 0;">Formulir F-1.06 SPTJM Perubahan Data dapat diunduh <a href="<?= esc($link_f106) ?>" target="_blank" rel="noopener noreferrer">di sini</a>.</p>
                <?php endif; ?>
                <p style="margin: 10px 0 0 0; color: #dc2626;"><strong>Jika dokumen sudah ada di atas dan masih benar, tidak perlu diupload ulang.</strong></p>
              </div>

              <?php
              // Satu markup slot dipakai untuk slot pertama (tanpa Hapus) dan template slot tambahan.
              $render_slot_baru = function (string $id_input, bool $bisa_dihapus) use ($config): void { ?>
              <div class="file-input-group">
                <select name="doc_type_new[]" class="file-type-select">
                  <option value="">Pilih Jenis Dokumen</option>
                  <?php foreach ($config['wajib'] as $docType => $docLabel): ?>
                  <option value="<?= esc($docType) ?>"><?= esc($docLabel) ?></option>
                  <?php endforeach; ?>
                  <?php if (!isset($config['wajib']['lainnya'])): ?>
                  <option value="lainnya">Dokumen Pendukung Lainnya</option>
                  <?php endif; ?>
                </select>
                <div class="file-wrapper">
                  <label class="file-label" for="<?= esc($id_input) ?>">Pilih File</label>
                  <span class="file-name">Belum ada file dipilih</span>
                  <input type="file" id="<?= esc($id_input) ?>" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" class="file-input">
                </div>
                <div class="file-size-warning">Maksimal 5 MB per file, dikompres otomatis</div>
                <?php if ($bisa_dihapus): ?>
                <button type="button" class="btn-remove">Hapus</button>
                <?php endif; ?>
              </div>
              <?php }; ?>
              <div id="slotBaruContainer">
                <?php $render_slot_baru('fileInput1', false); ?>
              </div>
              <!-- Isi <template> tidak ikut terkirim; disalin ke container saat tombol Tambah ditekan. -->
              <template id="templateSlotBaru"><?php $render_slot_baru('', true); ?></template>
              <button type="button" class="btn-secondary mt-2" id="btnTambahSlotBaru">
                + Tambah Dokumen Lainnya
              </button>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="status_permohonan.php" class="btn-secondary">Batal</a>
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

    // Listener dipasang di form (delegasi) agar slot yang ditambahkan belakangan ikut tertangani.
    document.getElementById('editKkLayananForm').addEventListener('change', function(event) {
      const input = event.target;
      if (input.type !== 'file') return;
      const nameSpan = input.closest('.file-input-group').querySelector('.file-name');
      if (!input.files.length) {
        nameSpan.textContent = 'Belum ada file dipilih';
        return;
      }
      if (input.files[0].size > MAX_FILE_SIZE) {
        alert('File "' + input.files[0].name + '" belum berhasil dikompres, pilih ulang file tersebut');
        input.value = '';
        nameSpan.textContent = 'File terlalu besar, pilih file lain';
        return;
      }
      nameSpan.textContent = input.files[0].name;
    });

    // Unggahan baru: satu slot tampil di awal, slot berikutnya ditambah/dihapus sesuai kebutuhan.
    let nomorSlotBaru = 1;
    const slotBaruContainer = document.getElementById('slotBaruContainer');
    document.getElementById('btnTambahSlotBaru').addEventListener('click', function() {
      nomorSlotBaru++;
      const slotBaru = document.getElementById('templateSlotBaru').content.firstElementChild.cloneNode(true);
      slotBaru.querySelector('input[type="file"]').id = 'fileInput' + nomorSlotBaru;
      slotBaru.querySelector('.file-label').htmlFor = 'fileInput' + nomorSlotBaru;
      slotBaruContainer.appendChild(slotBaru);
    });
    slotBaruContainer.addEventListener('click', function(event) {
      const tombolHapus = event.target.closest('.btn-remove');
      if (tombolHapus) tombolHapus.closest('.file-input-group').remove();
    });
  });

  // Dokumen wajib dihitung dari gabungan dokumen lama yang dicentang dan dokumen baru,
  // sama seperti pemeriksaan di sisi server.
  document.getElementById('editKkLayananForm').addEventListener('submit', function(e) {
    const wajib = <?= json_encode($config['wajib'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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

<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>
</body>
</html>
