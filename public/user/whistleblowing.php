<?php
declare(strict_types=1);

require_once __DIR__.'/../error_handler.php';
require_once __DIR__.'/../../app/middleware/user_only.php';
require_once __DIR__.'/../../app/config/koneksi.php';
require_once __DIR__.'/../functions/whistleblowing.php';

$userId = wbs_require_regular_applicant($koneksi);
$flash = wbs_take_flash();
$selectedCode = trim((string) ($_GET['kode'] ?? ''));
$selectedCase = null;
$timeline = [];
$attachments = [];
if ($selectedCode !== '') {
    $selectedCase = wbs_case_for_applicant($koneksi, $selectedCode, $userId);
    if (! $selectedCase) {
        http_response_code(404);
        $flash = ['type' => 'error', 'message' => 'Aduan tidak ditemukan atau bukan milik Anda.'];
    } else {
        $timeline = wbs_timeline($koneksi, (int) $selectedCase['id'], false);
        $attachments = array_values(array_filter(wbs_attachments($koneksi, (int) $selectedCase['id']), static fn (array $file): bool => ($file['visibility'] ?? 'pemohon') !== 'internal'));
    }
}

$stmt = $koneksi->prepare('SELECT kode_aduan,kategori,judul,status,created_at,updated_at FROM tb_whistleblowing_aduan WHERE pemohon_id=? ORDER BY updated_at DESC,id DESC');
$cases = [];
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $cases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Whistleblowing - SI PRIMA</title>
  <link rel="icon" type="image/webp" href="../assets/prima.webp">
  <link rel="stylesheet" href="css/dashboard_user.css?v=20260902-1">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
  <link rel="stylesheet" href="css/whistleblowing.css?v=20260902-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>
<div class="layout">
  <?php $userSidebarActive = 'whistleblowing';
include __DIR__.'/user_sidebar.php'; ?>
  <main class="main">
    <header class="header user-general-header">
      <?php require_once __DIR__.'/user_header_brand.php';
render_user_header_brand('Whistleblowing'); ?>
      <div class="user-info"><span>Hai, <?= wbs_escape($_SESSION['nama'] ?? 'Pemohon') ?></span><?php include __DIR__.'/notification_widget.php'; ?><button class="logout" id="logoutBtn2">Logout</button></div>
    </header>
    <section class="wbs-content">
      <?php if ($flash) { ?><div class="wbs-alert <?= wbs_escape($flash['type']) ?>" role="alert"><?= wbs_escape($flash['message']) ?></div><?php } ?>
      <div class="wbs-privacy"><strong>Kerahasiaan pelapor dilindungi.</strong><p>Identitas dan bukti hanya dapat diakses admin. Informasi tidak akan tampil pada halaman publik kecuali ringkasan anonim yang secara khusus disetujui admin setelah kasus selesai.</p></div>

      <?php if ($selectedCase) { ?>
        <div class="wbs-heading"><div><a href="whistleblowing.php" class="wbs-back">← Kembali ke daftar</a><h1><?= wbs_escape($selectedCase['judul']) ?></h1><p><?= wbs_escape($selectedCase['kode_aduan']) ?> · Dibuat <?= wbs_escape($selectedCase['created_at']) ?> WITA</p></div><span class="wbs-status <?= wbs_escape($selectedCase['status']) ?>"><?= wbs_escape(wbs_status_label($selectedCase['status'])) ?></span></div>
        <div class="wbs-detail-grid">
          <section class="wbs-panel"><h2>Isi Aduan</h2><dl><dt>Kategori</dt><dd><?= wbs_escape(wbs_category_label($selectedCase['kategori'])) ?></dd><dt>Layanan terkait</dt><dd><?= wbs_escape($selectedCase['layanan_terkait'] ?: '-') ?></dd><dt>Tanggal kejadian</dt><dd><?= wbs_escape($selectedCase['tanggal_kejadian'] ?: '-') ?></dd><dt>Lokasi</dt><dd><?= wbs_escape($selectedCase['lokasi_kejadian'] ?: '-') ?></dd><dt>Pihak yang dilaporkan</dt><dd><?= wbs_escape($selectedCase['pihak_terlapor'] ?: '-') ?></dd><dt>Kronologi</dt><dd class="prewrap"><?= wbs_escape($selectedCase['uraian']) ?></dd></dl></section>
          <section class="wbs-panel"><h2>Lampiran Bukti</h2><?php if (! $attachments) { ?><p class="wbs-empty">Tidak ada lampiran.</p><?php } else { ?><ul class="wbs-files"><?php foreach ($attachments as $file) { ?><li><a href="../whistleblowing_file.php?id=<?= (int) $file['id'] ?>">Unduh <?= wbs_escape($file['original_name']) ?></a><small><?= number_format(((int) $file['file_size']) / 1024, 0, ',', '.') ?> KB</small></li><?php } ?></ul><?php } ?></section>
        </div>
        <section class="wbs-panel wbs-timeline"><h2>Riwayat Tindak Lanjut</h2><?php foreach ($timeline as $item) { ?><article><div class="wbs-dot"></div><div><div class="wbs-timeline-meta"><strong><?= $item['actor_role'] === 'admin' ? 'Admin Dukcapil' : 'Anda' ?></strong><span><?= wbs_escape($item['created_at']) ?> WITA</span></div><?php if ($item['status_to']) { ?><span class="wbs-status small <?= wbs_escape($item['status_to']) ?>"><?= wbs_escape(wbs_status_label($item['status_to'])) ?></span><?php } ?><p class="prewrap"><?= wbs_escape($item['pesan']) ?></p></div></article><?php } ?></section>
        <?php if ($selectedCase['status'] === 'menunggu_pemohon') { ?><section class="wbs-panel"><h2>Kirim Informasi Tambahan</h2><form method="post" action="whistleblowing_action.php" enctype="multipart/form-data" class="wbs-form"><?= csrf_input() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="kode_aduan" value="<?= wbs_escape($selectedCase['kode_aduan']) ?>"><label>Jawaban Anda<textarea name="pesan" rows="6" minlength="5" maxlength="3000" required></textarea></label><label>Lampiran tambahan <small>Maksimal 3 gambar JPG atau PNG; masing-masing 5 MB dan maksimal 16 megapiksel.</small><input type="file" name="lampiran[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" multiple></label><button class="wbs-button" type="submit">Kirim Jawaban</button></form></section><?php } ?>
      <?php } else { ?>
        <div class="wbs-heading"><div><h1>Whistleblowing</h1><p>Sampaikan dugaan pelanggaran dalam pelayanan administrasi kependudukan.</p></div></div>
        <div class="wbs-layout">
          <section class="wbs-panel"><h2>Buat Aduan Baru</h2><form method="post" action="whistleblowing_action.php" enctype="multipart/form-data" class="wbs-form"><?= csrf_input() ?><input type="hidden" name="action" value="create">
            <label>Kategori<select name="kategori" required><option value="">Pilih kategori</option><?php foreach (wbs_categories() as $value => $label) { ?><option value="<?= wbs_escape($value) ?>"><?= wbs_escape($label) ?></option><?php } ?></select></label>
            <label>Judul aduan<input type="text" name="judul" minlength="10" maxlength="150" required placeholder="Ringkas dugaan pelanggaran"></label>
            <label>Kronologi<textarea name="uraian" rows="8" minlength="30" maxlength="5000" required placeholder="Jelaskan waktu, urutan kejadian, dan informasi penting secara faktual."></textarea></label>
            <div class="wbs-form-grid"><label>Tanggal kejadian <small>Opsional</small><input type="date" name="tanggal_kejadian" max="<?= date('Y-m-d') ?>"></label><label>Layanan terkait <small>Opsional</small><input type="text" name="layanan_terkait" maxlength="100" placeholder="Contoh: Akta Kelahiran"></label><label>Lokasi kejadian <small>Opsional</small><input type="text" name="lokasi_kejadian" maxlength="255"></label><label>Pihak yang dilaporkan <small>Opsional</small><input type="text" name="pihak_terlapor" maxlength="255"></label></div>
            <label>Lampiran bukti <small>Maksimal 3 gambar JPG atau PNG; masing-masing 5 MB dan maksimal 16 megapiksel.</small><input type="file" name="lampiran[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" multiple></label><button class="wbs-button" type="submit">Kirim Aduan Rahasia</button>
          </form></section>
          <section class="wbs-panel"><h2>Aduan Saya</h2><?php if (! $cases) { ?><p class="wbs-empty">Belum ada aduan yang dikirim.</p><?php } else { ?><div class="wbs-case-list"><?php foreach ($cases as $case) { ?><a href="?kode=<?= rawurlencode($case['kode_aduan']) ?>"><div><strong><?= wbs_escape($case['judul']) ?></strong><small><?= wbs_escape($case['kode_aduan']) ?> · <?= wbs_escape(wbs_category_label($case['kategori'])) ?></small></div><span class="wbs-status small <?= wbs_escape($case['status']) ?>"><?= wbs_escape(wbs_status_label($case['status'])) ?></span></a><?php } ?></div><?php } ?></section>
        </div>
      <?php } ?>
    </section>
  </main>
</div>
<?php include __DIR__.'/mobile_bottom_nav.php'; ?>
<div class="modal-overlay" id="logoutModal"><div class="modal-box"><h3>Konfirmasi Logout</h3><p>Apakah Anda yakin ingin logout?</p><div class="modal-actions"><button id="confirmLogout" class="btn-yes">Ya, Logout</button><button id="cancelLogout" class="btn-no">Batal</button></div></div></div>
<script>document.addEventListener('DOMContentLoaded',()=>{const modal=document.getElementById('logoutModal'),show=()=>modal?.classList.add('show'),hide=()=>modal?.classList.remove('show');document.getElementById('logoutBtn')?.addEventListener('click',show);document.getElementById('logoutBtn2')?.addEventListener('click',show);document.getElementById('cancelLogout')?.addEventListener('click',hide);document.getElementById('confirmLogout')?.addEventListener('click',()=>location.href='../logout.php');modal?.addEventListener('click',e=>{if(e.target===modal)hide()});const input=document.querySelector('input[type=file][multiple]');input?.addEventListener('change',()=>{if(input.files.length>3){alert('Maksimal tiga lampiran.');input.value='';}});});</script>
</body></html>
