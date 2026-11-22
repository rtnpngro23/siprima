<?php
declare(strict_types=1);

require_once __DIR__.'/../error_handler.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__.'/../../app/middleware/admin_only.php';
require_once __DIR__.'/../../app/config/koneksi.php';
require_once __DIR__.'/../functions/whistleblowing.php';

$flash = wbs_take_flash();
$code = trim((string) ($_GET['kode'] ?? ''));
$selectedCase = $code !== '' ? wbs_case_by_code($koneksi, $code) : null;
$timeline = $selectedCase ? wbs_timeline($koneksi, (int) $selectedCase['id'], true) : [];
$attachments = $selectedCase ? wbs_attachments($koneksi, (int) $selectedCase['id']) : [];

$status = array_key_exists((string) ($_GET['status'] ?? ''), wbs_statuses()) ? (string) $_GET['status'] : '';
$category = array_key_exists((string) ($_GET['kategori'] ?? ''), wbs_categories()) ? (string) $_GET['kategori'] : '';
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$where = ' WHERE 1=1';
$params = [];
if ($status !== '') {
    $where .= ' AND a.status=?';
    $params[] = $status;
}
if ($category !== '') {
    $where .= ' AND a.kategori=?';
    $params[] = $category;
}
if ($search !== '') {
    $where .= ' AND (a.kode_aduan LIKE ? OR a.judul LIKE ? OR u.nama LIKE ?)';
    $like = '%'.$search.'%';
    array_push($params, $like, $like, $like);
}

$countStmt = $koneksi->prepare('SELECT COUNT(*) total FROM tb_whistleblowing_aduan a LEFT JOIN tb_users u ON u.id=a.pemohon_id'.$where);
$countStmt->execute($params);
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$listStmt = $koneksi->prepare('SELECT a.kode_aduan,a.kategori,a.judul,a.status,a.is_published,a.created_at,a.updated_at,u.nama AS nama_pemohon FROM tb_whistleblowing_aduan a LEFT JOIN tb_users u ON u.id=a.pemohon_id'.$where." ORDER BY a.updated_at DESC,a.id DESC LIMIT {$perPage} OFFSET {$offset}");
$listStmt->execute($params);
$items = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();
$summaryResult = $koneksi->query("SELECT COUNT(*) total,SUM(status='baru') baru,SUM(status IN ('diverifikasi','diproses','menunggu_pemohon')) aktif,SUM(status='selesai') selesai,SUM(is_published=1) published FROM tb_whistleblowing_aduan");
$summary = $summaryResult ? $summaryResult->fetch_assoc() : [];
function wbs_admin_url(int $target, string $status, string $category, string $search): string
{
    return '?'.http_build_query(['status' => $status, 'kategori' => $category, 'search' => $search, 'page' => $target]);
}
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Whistleblowing - Admin</title><link rel="icon" type="image/webp" href="../assets/prima.webp"><link rel="stylesheet" href="dashboard_admin.css"><link rel="stylesheet" href="common.css"><link rel="stylesheet" href="css/whistleblowing.css?v=20260902-1">    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head><body>
<div class="layout"><aside class="sidebar"><div class="logo">Pelayanan Kependudukan</div><nav><ul><li><a href="dashboard_admin.php">Dashboard</a></li><li><a href="manajemen_operator.php">Manajemen Pengguna</a></li><li><a href="reset_password.php">Reset Password Pengguna</a></li><li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li><li><a href="laporan_admin.php">Laporan &amp; Analisis</a></li><li><a href="kinerja_operator.php">Kinerja Operator</a></li><li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li><li><a href="tanggapan_pemohon.php">Hasil Survei</a></li><li class="active"><a href="whistleblowing.php">Whistleblowing</a></li><li><a href="formulir.php">Formulir Kependudukan</a></li><li><a href="manajemen_formulir_digital.php">Manajemen Formulir Digital</a></li><li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
                <li class="logout-item"><button id="logoutBtn" class="sidebar-btn">Logout</button></li></ul></nav></aside><div class="menu-toggle">☰</div>
<main class="main"><header class="header"><div class="left-header"><div class="title">Whistleblowing</div><div class="wbs-subtitle">Aduan rahasia dan tindak lanjut admin</div></div><div class="user-info"><span>Hai, <?= wbs_escape($_SESSION['nama'] ?? 'Admin') ?></span><button class="logout" id="logoutBtn2">Logout</button></div></header><section class="wbs-admin">
<?php if ($flash) { ?><div class="wbs-alert <?= wbs_escape($flash['type']) ?>"><?= wbs_escape($flash['message']) ?></div><?php } ?>
<?php if ($selectedCase) { ?>
  <div class="wbs-detail-heading"><div><a href="whistleblowing.php" class="wbs-back">← Kembali ke daftar</a><h1><?= wbs_escape($selectedCase['judul']) ?></h1><p><?= wbs_escape($selectedCase['kode_aduan']) ?> · <?= wbs_escape(wbs_category_label($selectedCase['kategori'])) ?></p></div><span class="wbs-status <?= wbs_escape($selectedCase['status']) ?>"><?= wbs_escape(wbs_status_label($selectedCase['status'])) ?></span></div>
  <div class="wbs-columns"><div>
    <section class="wbs-panel"><h2>Identitas Rahasia Pemohon</h2><div class="wbs-confidential">Informasi ini tidak boleh disalin ke ringkasan publik.</div><dl><dt>Nama</dt><dd><?= wbs_escape($selectedCase['nama_pemohon'] ?: '-') ?></dd><dt>NIK</dt><dd><?= wbs_escape($selectedCase['nik'] ?: '-') ?></dd><dt>Email</dt><dd><?= wbs_escape($selectedCase['email'] ?: '-') ?></dd><dt>No. HP</dt><dd><?= wbs_escape($selectedCase['no_hp'] ?: '-') ?></dd></dl></section>
    <section class="wbs-panel"><h2>Isi Aduan</h2><dl><dt>Tanggal kejadian</dt><dd><?= wbs_escape($selectedCase['tanggal_kejadian'] ?: '-') ?></dd><dt>Lokasi</dt><dd><?= wbs_escape($selectedCase['lokasi_kejadian'] ?: '-') ?></dd><dt>Layanan</dt><dd><?= wbs_escape($selectedCase['layanan_terkait'] ?: '-') ?></dd><dt>Pihak terlapor</dt><dd><?= wbs_escape($selectedCase['pihak_terlapor'] ?: '-') ?></dd><dt>Kronologi</dt><dd class="prewrap"><?= wbs_escape($selectedCase['uraian']) ?></dd></dl></section>
    <section class="wbs-panel"><h2>Lampiran Bukti</h2><?php if (! $attachments) { ?><p class="wbs-muted">Tidak ada lampiran.</p><?php } else { ?><ul class="wbs-files"><?php foreach ($attachments as $file) { ?><li><a href="../whistleblowing_file.php?id=<?= (int) $file['id'] ?>">Unduh <?= wbs_escape($file['original_name']) ?></a><small><?= number_format(((int) $file['file_size']) / 1024, 0, ',', '.') ?> KB</small></li><?php } ?></ul><?php } ?></section>
  </div><div>
    <?php if (! in_array($selectedCase['status'], ['selesai', 'ditolak'], true)) { ?><section class="wbs-panel"><h2>Tindak Lanjut untuk Pemohon</h2><form method="post" action="whistleblowing_action.php" class="wbs-form"><?= csrf_input() ?><input type="hidden" name="action" value="follow_up"><input type="hidden" name="kode_aduan" value="<?= wbs_escape($selectedCase['kode_aduan']) ?>"><label>Status<select name="status" required><?php foreach (wbs_statuses() as $value => $label) {
        if (! wbs_allowed_transition($selectedCase['status'], $value)) {
            continue;
        } ?><option value="<?= wbs_escape($value) ?>" <?= $value === $selectedCase['status'] ? 'selected' : '' ?>><?= wbs_escape($label) ?></option><?php } ?></select></label><label>Tanggapan publik<textarea name="pesan" rows="6" minlength="5" maxlength="3000" required></textarea></label><button class="wbs-button" type="submit">Simpan dan Beri Notifikasi</button></form></section><?php } ?>
    <section class="wbs-panel"><h2>Catatan Internal</h2><form method="post" action="whistleblowing_action.php" class="wbs-form"><?= csrf_input() ?><input type="hidden" name="action" value="internal_note"><input type="hidden" name="kode_aduan" value="<?= wbs_escape($selectedCase['kode_aduan']) ?>"><label>Catatan khusus admin<textarea name="pesan_internal" rows="4" minlength="5" maxlength="3000" required></textarea></label><button class="wbs-button secondary" type="submit">Simpan Catatan Internal</button></form></section>
    <?php if ($selectedCase['status'] === 'selesai') { ?><section class="wbs-panel publish"><h2>Web Informasi Dukcapil</h2><p>Hanya tulis informasi anonim. Jangan mencantumkan nama, NIK, kontak, pihak terlapor, atau rincian bukti.</p><form method="post" action="whistleblowing_action.php" class="wbs-form"><?= csrf_input() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="kode_aduan" value="<?= wbs_escape($selectedCase['kode_aduan']) ?>"><label>Ringkasan anonim<textarea name="ringkasan_publik" rows="5" minlength="30" maxlength="1000" required><?= wbs_escape($selectedCase['ringkasan_publik']) ?></textarea></label><label>Hasil tindak lanjut anonim<textarea name="hasil_tindak_lanjut_publik" rows="6" minlength="30" maxlength="3000" required><?= wbs_escape($selectedCase['hasil_tindak_lanjut_publik']) ?></textarea></label><button class="wbs-button success" type="submit"><?= $selectedCase['is_published'] ? 'Perbarui Publikasi' : 'Publikasikan Hasil' ?></button></form><?php if ($selectedCase['is_published']) { ?><form method="post" action="whistleblowing_action.php" class="unpublish" onsubmit="return confirm('Cabut hasil ini dari halaman publik?')"><?= csrf_input() ?><input type="hidden" name="action" value="unpublish"><input type="hidden" name="kode_aduan" value="<?= wbs_escape($selectedCase['kode_aduan']) ?>"><button type="submit">Cabut Publikasi</button></form><?php } ?></section><?php } ?>
  </div></div>
  <section class="wbs-panel wbs-timeline"><h2>Audit Trail</h2><?php foreach ($timeline as $item) { ?><article class="<?= $item['visibility'] === 'internal' ? 'internal' : '' ?>"><div><strong><?= wbs_escape($item['actor_name'] ?: ucfirst($item['actor_role'])) ?></strong><span><?= wbs_escape($item['created_at']) ?> WITA</span><?php if ($item['visibility'] === 'internal') { ?><em>Internal</em><?php }?></div><p class="prewrap"><?= wbs_escape($item['pesan']) ?></p><?php if ($item['status_to']) { ?><small><?= wbs_escape(wbs_status_label($item['status_from'] ?: $item['status_to'])) ?> → <?= wbs_escape(wbs_status_label($item['status_to'])) ?></small><?php }?></article><?php } ?></section>
<?php } else { ?>
  <div class="wbs-summary"><div>Total<strong><?= (int) ($summary['total'] ?? 0) ?></strong></div><div>Baru<strong><?= (int) ($summary['baru'] ?? 0) ?></strong></div><div>Aktif<strong><?= (int) ($summary['aktif'] ?? 0) ?></strong></div><div>Selesai<strong><?= (int) ($summary['selesai'] ?? 0) ?></strong></div><div>Dipublikasi<strong><?= (int) ($summary['published'] ?? 0) ?></strong></div></div>
  <form class="wbs-filters" method="get"><label>Status<select name="status"><option value="">Semua</option><?php foreach (wbs_statuses() as $value => $label) { ?><option value="<?= wbs_escape($value)?>" <?= $status === $value ? 'selected' : ''?>><?= wbs_escape($label)?></option><?php }?></select></label><label>Kategori<select name="kategori"><option value="">Semua</option><?php foreach (wbs_categories() as $value => $label) { ?><option value="<?= wbs_escape($value)?>" <?= $category === $value ? 'selected' : ''?>><?= wbs_escape($label)?></option><?php }?></select></label><label>Pencarian<input name="search" value="<?= wbs_escape($search)?>" placeholder="Tiket, judul, pemohon"></label><button class="wbs-button">Cari</button><a href="whistleblowing.php" class="wbs-button secondary">Reset</a></form>
  <div class="wbs-table"><table><thead><tr><th>No</th><th>Tiket</th><th>Waktu</th><th>Kategori</th><th>Judul</th><th>Pemohon</th><th>Status</th><th>Publik</th><th>Aksi</th></tr></thead><tbody><?php if (! $items) { ?><tr><td colspan="9" class="empty">Belum ada aduan yang sesuai.</td></tr><?php }?><?php $number = $offset + 1;
    foreach ($items as $item) { ?><tr><td><?= $number++?></td><td><?= wbs_escape($item['kode_aduan'])?></td><td><?= wbs_escape($item['created_at'])?> WITA</td><td><?= wbs_escape(wbs_category_label($item['kategori']))?></td><td><?= wbs_escape($item['judul'])?></td><td><?= wbs_escape($item['nama_pemohon'] ?: '-')?></td><td><span class="wbs-status small <?= wbs_escape($item['status'])?>"><?= wbs_escape(wbs_status_label($item['status']))?></span></td><td><?= $item['is_published'] ? 'Ya' : 'Tidak'?></td><td><a class="wbs-button compact" href="?kode=<?= rawurlencode($item['kode_aduan'])?>">Detail</a></td></tr><?php }?></tbody></table></div>
  <div class="wbs-pagination"><span>Menampilkan <?= count($items)?> dari <?= $total?> data</span><div><a class="<?= $page <= 1 ? 'disabled' : ''?>" href="<?= wbs_escape(wbs_admin_url(max(1, $page - 1), $status, $category, $search))?>">Sebelumnya</a><a class="<?= $page >= $pages ? 'disabled' : ''?>" href="<?= wbs_escape(wbs_admin_url(min($pages, $page + 1), $status, $category, $search))?>">Berikutnya</a></div></div>
<?php } ?>
</section></main></div>
<div class="modal-overlay" id="logoutModal"><div class="modal-box"><h3>Konfirmasi Logout</h3><p>Apakah Anda yakin ingin logout?</p><div class="modal-actions"><button id="confirmLogout" class="btn-yes">Ya, Logout</button><button id="cancelLogout" class="btn-no">Batal</button></div></div></div><script>document.addEventListener('DOMContentLoaded',()=>{const sidebar=document.querySelector('.sidebar'),modal=document.getElementById('logoutModal'),show=()=>modal?.classList.add('show'),hide=()=>modal?.classList.remove('show');document.querySelector('.menu-toggle')?.addEventListener('click',()=>sidebar?.classList.toggle('open'));document.getElementById('logoutBtn')?.addEventListener('click',show);document.getElementById('logoutBtn2')?.addEventListener('click',show);document.getElementById('cancelLogout')?.addEventListener('click',hide);document.getElementById('confirmLogout')?.addEventListener('click',()=>location.href='../logout.php');modal?.addEventListener('click',e=>{if(e.target===modal)hide()});});</script></body></html>
