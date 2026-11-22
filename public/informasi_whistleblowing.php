<?php
declare(strict_types=1);

require_once __DIR__.'/error_handler.php';
require_once __DIR__.'/../app/config/koneksi.php';
require_once __DIR__.'/functions/whistleblowing.php';

$category = array_key_exists((string) ($_GET['kategori'] ?? ''), wbs_categories()) ? (string) $_GET['kategori'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$where = " WHERE is_published=1 AND status='selesai' AND ringkasan_publik IS NOT NULL AND hasil_tindak_lanjut_publik IS NOT NULL";
if ($category !== '') {
    $where .= ' AND kategori=?';
}
$countStmt = $koneksi->prepare('SELECT COUNT(*) total FROM tb_whistleblowing_aduan'.$where);
$total = 0;
$items = [];
if ($countStmt) {
    if ($category !== '') {
        $countStmt->bind_param('s', $category);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $stmt = $koneksi->prepare('SELECT kode_aduan,kategori,ringkasan_publik,hasil_tindak_lanjut_publik,published_at FROM tb_whistleblowing_aduan'.$where." ORDER BY published_at DESC,id DESC LIMIT {$perPage} OFFSET {$offset}");
    if ($stmt) {
        if ($category !== '') {
            $stmt->bind_param('s', $category);
        } $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    $pages = 1;
}
function wbs_public_url(int $page, string $category): string
{
    return '?'.http_build_query(['kategori' => $category, 'page' => $page]);
}
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Informasi Tindak Lanjut Whistleblowing - Dukcapil Kabupaten Gorontalo</title><meta name="description" content="Informasi anonim hasil tindak lanjut whistleblowing pada layanan Dukcapil Kabupaten Gorontalo."><meta name="robots" content="index,follow"><link rel="icon" type="image/webp" href="assets/prima.webp"><link rel="stylesheet" href="assets/css/informasi_whistleblowing.css?v=20260902-1"><link rel="stylesheet" href="assets/css/theme.css?v=20261118-4"><script src="assets/js/theme.js?v=20261118-1"></script></head><body>
<header class="public-header"><a href="index.php"><img src="assets/prima.webp" alt="Logo SI PRIMA"><span><strong>SI PRIMA</strong><small>Dukcapil Kabupaten Gorontalo</small></span></a><nav><a href="index.php">Beranda</a><a href="login.php">Masuk</a></nav></header>
<main><section class="public-hero"><span>Transparansi Pelayanan</span><h1>Informasi Tindak Lanjut Whistleblowing</h1><p>Ringkasan anonim kasus yang telah selesai ditindaklanjuti dan dinyatakan layak dipublikasikan oleh admin Dukcapil.</p></section>
<section class="public-content"><div class="privacy-note"><strong>Perlindungan identitas</strong><p>Halaman ini tidak menampilkan identitas pelapor, pihak terlapor, catatan internal, ataupun bukti pendukung.</p></div><form class="public-filter"><label>Kategori<select name="kategori"><option value="">Semua kategori</option><?php foreach (wbs_categories() as $value => $label) { ?><option value="<?= wbs_escape($value)?>" <?= $category === $value ? 'selected' : ''?>><?= wbs_escape($label)?></option><?php }?></select></label><button>Filter</button><a href="informasi_whistleblowing.php">Reset</a></form>
<?php if (! $items) { ?><div class="empty"><h2>Belum ada hasil yang dipublikasikan</h2><p>Informasi akan tampil setelah kasus selesai dan admin menyetujui ringkasan anonimnya.</p></div><?php } else { ?><div class="publication-list"><?php foreach ($items as $item) { ?><article><div class="publication-meta"><span><?= wbs_escape(wbs_category_label($item['kategori']))?></span><time><?= wbs_escape($item['published_at'])?> WITA</time></div><h2><?= wbs_escape(wbs_publication_id((string) $item['kode_aduan']))?></h2><h3>Ringkasan Aduan</h3><p class="prewrap"><?= wbs_escape($item['ringkasan_publik'])?></p><h3>Hasil Tindak Lanjut</h3><p class="prewrap"><?= wbs_escape($item['hasil_tindak_lanjut_publik'])?></p></article><?php }?></div><?php }?>
<div class="pagination"><span><?= $total?> hasil dipublikasikan</span><div><a class="<?= $page <= 1 ? 'disabled' : ''?>" href="<?= wbs_escape(wbs_public_url(max(1, $page - 1), $category))?>">Sebelumnya</a><a class="<?= $page >= $pages ? 'disabled' : ''?>" href="<?= wbs_escape(wbs_public_url(min($pages, $page + 1), $category))?>">Berikutnya</a></div></div></section></main>
<footer>© <?= date('Y')?> Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo</footer></body></html>
