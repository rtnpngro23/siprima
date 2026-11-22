<?php
require_once '../error_handler.php';
require_once __DIR__ . '/upload_compat.php';
require_once __DIR__ . '/../functions/kk_kategori.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
include '../../app/config/koneksi.php';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// Form ringkas untuk kategori KK selain kk_baru; kk_baru dan nilai tidak dikenal kembali ke pilihan.
$kategori = siprima_kk_kategori_normalize($_POST['jenis'] ?? $_GET['jenis'] ?? null);
$config = siprima_kk_layanan_config($kategori);
if ($config === null) {
    header('Location: kk_pilih_jenis.php');
    exit;
}

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

// Untuk mempertahankan pilihan setelah submit gagal
$selected_kecamatan = isset($_POST['id_kecamatan']) ? (int)$_POST['id_kecamatan'] : '';
$selected_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : '';
$pre_nama_kecamatan = '';
$pre_nama_desa = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_kecamatan = isset($_POST['id_kecamatan']) ? (int)$_POST['id_kecamatan'] : 0;
    $id_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : 0;

    // Kolom nama_kepalaKeluarga dipakai ulang sebagai nama yang dilayani (untuk cetak biodata:
    // nama pemilik biodata) agar daftar, laporan, dan email tetap membaca satu kolom nama.
    $nama_subjek = trim((string)($_POST['nama_subjek'] ?? ''));
    $nik_subjek = trim((string)($_POST['nik_subjek'] ?? ''));
    $keterangan = trim((string)($_POST['keterangan'] ?? ''));

    $errors = siprima_kk_layanan_validasi($config, $nama_subjek, $nik_subjek, $keterangan);
    if ($id_kecamatan <= 0) $errors[] = 'Kecamatan wajib dipilih.';
    if ($id_desa <= 0) $errors[] = 'Desa wajib dipilih.';

    // Ambil nama kecamatan dan desa; desa harus berada di kecamatan yang dipilih.
    $nama_kecamatan = '';
    $nama_desa = '';
    if ($id_kecamatan > 0 && $id_desa > 0) {
        $qw = $koneksi->prepare("SELECT k.nama_kecamatan, d.nama_desa
            FROM tb_desa d JOIN tb_kecamatan k ON d.kecamatan_id = k.id
            WHERE d.id = ? AND k.id = ?");
        $qw->bind_param("ii", $id_desa, $id_kecamatan);
        $qw->execute();
        $rw = $qw->get_result()->fetch_assoc();
        $qw->close();
        if ($rw) {
            $nama_kecamatan = $rw['nama_kecamatan'];
            $nama_desa = $rw['nama_desa'];
        } else {
            $errors[] = 'Desa tidak sesuai dengan kecamatan yang dipilih.';
        }
    }

    $nama_pemohon = $data_user['nama'] ?? '';
    $nik_pemohon = $data_user['nik'] ?? '';

    function cleanFolderName($string) {
        $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', (string)$string);
        return preg_replace('/\s+/', '_', trim($string));
    }

    $folder_name = cleanFolderName($nama_kecamatan) . '_' . cleanFolderName($nama_desa) . '_' .
        preg_replace('/[^0-9]/', '', $nik_pemohon) . '_' . cleanFolderName($nama_pemohon) . '_' .
        cleanFolderName($nama_subjek) . '_' . $kategori . '_' . date('Ymd_His');
    if (strlen($folder_name) > 200) {
        $folder_name = cleanFolderName($nama_kecamatan) . '_' . cleanFolderName($nama_desa) . '_' .
            substr(md5($folder_name), 0, 8) . '_' . $kategori . '_' . date('Ymd_His');
    }

    $base_upload_dir = __DIR__ . '/uploads/kk/';
    $permohonan_dir = $base_upload_dir . $folder_name . '/';

    $uploadedFiles = [];
    if (empty($errors)) {
        $docTypes = isset($_POST['doc_type']) && is_array($_POST['doc_type']) ? $_POST['doc_type'] : [];
        $uploadedFiles = siprima_kk_layanan_simpan_unggahan($kategori, $docTypes, $permohonan_dir, 'uploads/kk/' . $folder_name . '/', $errors);
        $kurang = siprima_kk_layanan_dokumen_kurang($kategori, $uploadedFiles);
        if ($kurang) $errors[] = 'Dokumen wajib belum diupload: ' . implode(', ', $kurang);
    }

    if (empty($errors)) {
        $no_hp = $data_user['no_hp'] ?? '';
        $email = $data_user['email'] ?? '';
        $dokumenJson = json_encode($uploadedFiles);
        $status = 'diajukan';
        $alasan = '';

        $stmt = $koneksi->prepare("INSERT INTO tb_kartukeluarga
            (id_user, id_kecamatan, id_desa,
             nama_pemohon, nik_pemohon, no_hp, email,
             nama_kecamatan, nama_desa, nama_kepalaKeluarga,
             dokumen, status, alasan, keterangan, nama_folder,
             kategori_layanan, nik_subjek,
             created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

        if (!$stmt) {
            error_log('KK layanan insert prepare error: ' . $koneksi->error);
            $errors[] = 'Terjadi gangguan sistem saat memproses permohonan. Silakan coba lagi.';
        } else {
            $stmt->bind_param(
                "iiissssssssssssss", // 3 integer + 14 string, urut sama dengan daftar kolom di atas
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
                $kategori,
                $nik_subjek
            );

            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Permohonan ' . siprima_kk_kategori_label($kategori) . ' berhasil diajukan.';
                header('Location: ajukan_permohonan.php');
                exit;
            }

            error_log('KK layanan insert execute error: ' . $stmt->error);
            $errors[] = 'Data belum dapat disimpan. Periksa kembali isian Anda lalu coba lagi.';
            $stmt->close();
        }
    }

    // Pengajuan bersifat atomik: bersihkan semua file jika salah satu validasi/insert gagal.
    if (!empty($errors) && is_dir($permohonan_dir)) {
        siprima_remove_upload_tree($permohonan_dir, $base_upload_dir);
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

$link_f106 = $kategori === 'perubahan_data' ? siprima_kk_link_f106($koneksi) : '';

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ajukan <?= esc(siprima_kk_kategori_label($kategori)) ?></title>
  <link rel="stylesheet" href="css/kk.css">
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
        render_user_header_brand('Kartu Keluarga');
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
              <h2 class="form-title">Form <?= esc(siprima_kk_kategori_label($kategori)) ?></h2>
              <p class="helper">Kategori: <strong><?= esc(siprima_kk_kategori_label($kategori)) ?></strong></p>
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

          <form method="POST" enctype="multipart/form-data" class="space-y-4" id="kkLayananForm">
<?php echo csrf_input(); ?>
            <input type="hidden" name="jenis" value="<?= esc($kategori) ?>">

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

            <h3 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px;">Data yang Diajukan</h3>

            <div class="form-grid-2">
              <div>
                <label class="field-label"><?= esc($config['label_nama']) ?> <span style="color:red">*</span></label>
                <input type="text" name="nama_subjek" value="<?= esc($_POST['nama_subjek'] ?? '') ?>" required class="input" maxlength="100" placeholder="Nama lengkap sesuai KK/KTP">
              </div>
              <div>
                <label class="field-label"><?= esc($config['label_nik']) ?> <span style="color:red">*</span></label>
                <input type="text" name="nik_subjek" value="<?= esc($_POST['nik_subjek'] ?? '') ?>" required class="input" placeholder="16 digit NIK" maxlength="16" pattern="[0-9]{16}" inputmode="numeric" title="Harus 16 digit angka">
              </div>
            </div>

            <div>
              <label class="field-label"><?= $config['keterangan_wajib'] ? 'Data yang Diubah <span style="color:red">*</span>' : 'Keterangan (Opsional)' ?></label>
              <textarea name="keterangan" <?= $config['keterangan_wajib'] ? 'required' : '' ?> class="input" rows="3" placeholder="<?= esc($config['placeholder_keterangan']) ?>" maxlength="500"><?= esc($_POST['keterangan'] ?? '') ?></textarea>
              <p class="helper">Maksimal 500 karakter</p>
            </div>

            <!-- File Upload -->
            <div>
              <label class="field-label">Unggah Dokumen <span style="color:red">*</span></label>
              <?php if ($link_f106 !== ''): ?>
              <p class="helper">
                Formulir F-1.06 SPTJM Perubahan Data dapat diunduh
                <a href="<?= esc($link_f106) ?>" target="_blank" rel="noopener noreferrer">di sini</a>,
                lalu isi, tanda tangani, dan unggah fotonya.
              </p>
              <?php endif; ?>

              <div class="f-doc-group">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-exclamation-circle"></i> Dokumen Wajib
                </h4>
                <?php $slot = 0; foreach ($config['wajib'] as $docType => $docLabel): $slot++; ?>
                <div class="file-input-group">
                  <input type="hidden" name="doc_type[]" value="<?= esc($docType) ?>">
                  <p class="field-label"><?= esc($docLabel) ?></p>
                  <div class="file-wrapper">
                    <label class="file-label" for="fileInput<?= $slot ?>">Pilih File</label>
                    <span id="fileName<?= $slot ?>" class="file-name">Belum ada file dipilih</span>
                    <input type="file" id="fileInput<?= $slot ?>" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required class="file-input" data-label="<?= esc($docLabel) ?>">
                  </div>
                  <div class="file-size-warning" id="fileSize<?= $slot ?>">Maksimal 5 MB per file, dikompres otomatis</div>
                </div>
                <?php endforeach; ?>
              </div>

              <div class="f-doc-group f-doc-group--opsional">
                <h4 class="f-doc-group__judul">
                  <i class="fas fa-check-circle"></i> Dokumen Pendukung Lainnya (Opsional)
                </h4>
                <div id="opsionalUploadContainer">
                  <?php $slot++; ?>
                  <div class="file-input-group">
                    <input type="hidden" name="doc_type[]" value="lainnya">
                    <div class="file-wrapper">
                      <label class="file-label" for="fileInput<?= $slot ?>">Pilih File</label>
                      <span id="fileName<?= $slot ?>" class="file-name">Belum ada file dipilih</span>
                      <input type="file" id="fileInput<?= $slot ?>" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" class="file-input">
                    </div>
                    <div class="file-size-warning" id="fileSize<?= $slot ?>">Maksimal 5 MB per file (Opsional, dikompres otomatis)</div>
                  </div>
                </div>
                <!-- Isi <template> tidak ikut terkirim; disalin ke container saat tombol Tambah ditekan. -->
                <template id="templateOpsional">
                  <div class="file-input-group">
                    <input type="hidden" name="doc_type[]" value="lainnya">
                    <div class="file-wrapper">
                      <label class="file-label">Pilih File</label>
                      <span class="file-name">Belum ada file dipilih</span>
                      <input type="file" name="dokumen[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" class="file-input">
                    </div>
                    <div class="file-size-warning">Maksimal 5 MB per file (Opsional, dikompres otomatis)</div>
                    <button type="button" class="btn-remove">Hapus</button>
                  </div>
                </template>
                <button type="button" class="btn-secondary mt-2" id="btnTambahOpsional">
                  <i class="fas fa-plus"></i> Tambah Dokumen Opsional Lainnya
                </button>
              </div>

              <p class="helper">
                <strong>Format:</strong> JPG, JPEG, JFIF, JPE, PNG. <strong>Maksimal 5 MB per file; gambar dikompresi menjadi kurang dari 500 KB.</strong>
              </p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t">
              <a href="kk_pilih_jenis.php" class="btn-secondary">Kembali</a>
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

    // Listener dipasang di form (delegasi) agar slot opsional yang ditambahkan belakangan ikut tertangani.
    document.getElementById('kkLayananForm').addEventListener('change', function(event) {
      const input = event.target;
      if (input.type !== 'file') return;
      const group = input.closest('.file-input-group');
      const fileNameSpan = group.querySelector('.file-name');
      const fileSizeSpan = group.querySelector('.file-size-warning');
      if (!fileSizeSpan.dataset.teksAwal) fileSizeSpan.dataset.teksAwal = fileSizeSpan.textContent;

      if (input.files.length === 0) {
        fileNameSpan.textContent = 'Belum ada file dipilih';
        fileSizeSpan.textContent = fileSizeSpan.dataset.teksAwal;
        return;
      }
      const file = input.files[0];
      fileNameSpan.textContent = file.name;
      if (file.size > MAX_FILE_SIZE) {
        fileSizeSpan.innerHTML = '<span style="color: red;">&#10060; Ukuran file: ' + formatFileSize(file.size) + ' (gagal dikompres, pilih gambar lain)</span>';
        input.value = '';
        fileNameSpan.textContent = 'File terlalu besar, pilih file lain';
      } else {
        fileSizeSpan.textContent = 'Ukuran: ' + formatFileSize(file.size);
      }
    });

    // Dokumen opsional: satu slot tampil di awal, slot berikutnya ditambah/dihapus sesuai kebutuhan.
    let slotOpsional = <?= $slot ?>;
    const containerOpsional = document.getElementById('opsionalUploadContainer');
    document.getElementById('btnTambahOpsional').addEventListener('click', function() {
      slotOpsional++;
      const slotBaru = document.getElementById('templateOpsional').content.firstElementChild.cloneNode(true);
      slotBaru.querySelector('input[type="file"]').id = 'fileInput' + slotOpsional;
      slotBaru.querySelector('.file-label').htmlFor = 'fileInput' + slotOpsional;
      containerOpsional.appendChild(slotBaru);
    });
    containerOpsional.addEventListener('click', function(event) {
      const tombolHapus = event.target.closest('.btn-remove');
      if (tombolHapus) tombolHapus.closest('.file-input-group').remove();
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

      fetch("get_desa.php?id_kecamatan=" + encodeURIComponent(idKecamatan))
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
  document.getElementById('kkLayananForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (this.dataset.submitting === '1') return;

    const submitButton = this.querySelector('button[type="submit"]');
    const errorMessages = [];

    const nikSubjek = this.querySelector('input[name="nik_subjek"]');
    if (nikSubjek && !/^[0-9]{16}$/.test(nikSubjek.value)) {
      errorMessages.push('NIK harus 16 digit angka');
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
      if (input.required && !input.files.length) {
        errorMessages.push((input.dataset.label || 'Dokumen wajib') + ' wajib diupload');
      }
    }

    if (errorMessages.length > 0) {
      alert('Perbaiki kesalahan berikut:\n• ' + errorMessages.join('\n• '));
      return;
    }

    if (!confirm('Apakah Anda yakin data sudah benar dan siap mengajukan permohonan?\n\nPastikan semua dokumen wajib sudah diupload.')) {
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
