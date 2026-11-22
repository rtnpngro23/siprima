<?php
require_once __DIR__.'/../error_handler.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../../app/middleware/admin_only.php';
require_once __DIR__.'/../../app/config/koneksi.php';
require_once __DIR__.'/../../app/Laporan/KinerjaOperator.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function ke($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function kparam(string $key, string $default = ''): string { return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : $default; }
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Makassar'));
$startText = kparam('from', $today->format('Y-m-01'));
$endText = kparam('to', $today->format('Y-m-d'));
$start = kinerjaDate($startText); $end = kinerjaDate($endText);
$service = kparam('service'); $unit = kparam('unit'); $error = '';
$operator = ctype_digit(kparam('operator')) ? (int)kparam('operator') : 0;
$services = kinerjaServices(); $units = ['Dukcapil','KUA','Puskesmas','Rumah Sakit'];
if (!$start || !$end || $end < $start || $end > $today || $start < $end->modify('-1 year')) {
    $error = 'Pilih tanggal yang valid, maksimal satu tahun dan tidak melewati hari ini.';
}
if (($service !== '' && !isset($services[$service])) || ($unit !== '' && !in_array($unit,$units,true))) {
    $error = 'Filter layanan atau unit tidak valid.';
}
$data = null;
$until = $end ? min(time(), $end->modify('+1 day')->getTimestamp()) : time();
if ($error === '') {
    try { $data = kinerjaLoad($koneksi,$start->getTimestamp(),$until,$service,$unit); }
    catch (Throwable $e) { $error = 'Data kinerja belum dapat dimuat. Periksa ketersediaan tabel dan kolom riwayat pelayanan.'; }
}
function klink(array $changes = []): string {
    global $startText,$endText,$service,$unit,$operator;
    return '?'.http_build_query(array_merge(['from'=>$startText,'to'=>$endText,'service'=>$service,'unit'=>$unit,'operator'=>$operator],$changes));
}
function kratio(array $s): string {
    $n = $s['selesai'] + $s['ditolak'];
    return $n ? number_format(100 * $s['selesai'] / $n,1,',','.').'%' : 'Belum ada keputusan';
}
$selected = $data && $operator ? ($data['operators'][$operator] ?? null) : null;
$stats = $selected ?? ($data['total'] ?? kinerjaEmpty());
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Kinerja Operator - SIPRIMA</title>
    <link rel="stylesheet" href="css/kinerja_operator.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>
<div class="layout">
<aside class="sidebar" id="adminSidebar">
    <div class="logo">Pelayanan Kependudukan</div>
    <nav aria-label="Menu admin"><ul>
        <li><a href="dashboard_admin.php">Dashboard</a></li>
        <li><a href="manajemen_operator.php">Manajemen Pengguna</a></li>
        <li><a href="reset_password.php">Reset Password Pengguna</a></li>
        <li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li>
        <li><a href="laporan_admin.php">Laporan &amp; Analisis</a></li>
        <li><a href="kinerja_operator.php" aria-current="page">Kinerja Operator</a></li>
        <li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
        <li><a href="tanggapan_pemohon.php">Hasil Survei</a></li>
        <li><a href="whistleblowing.php">Whistleblowing</a></li>
        <li><a href="formulir.php">Formulir Kependudukan</a></li>
        <li><a href="manajemen_formulir_digital.php">Manajemen Formulir Digital</a></li><li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
        <li><a href="../logout.php">Logout</a></li>
    </ul></nav>
</aside>
<main class="main">
<header class="header"><button type="button" class="menu-toggle" aria-label="Buka menu" aria-controls="adminSidebar" aria-expanded="false">☰</button>
<div><p class="eyebrow">MONITORING PELAYANAN</p><h1>Kinerja Operator</h1><p>Kecepatan, hasil pelayanan, dan pekerjaan yang masih berjalan.</p></div></header>
<section class="content">
<form class="filters" method="get">
    <label>Dari tanggal<input type="date" name="from" value="<?=ke($startText)?>" required></label>
    <label>Sampai tanggal<input type="date" name="to" value="<?=ke($endText)?>" max="<?=$today->format('Y-m-d')?>" required></label>
    <label>Unit layanan<select name="unit"><option value="">Semua unit</option><?php foreach($units as $option): ?><option <?= $unit===$option?'selected':'' ?>><?=ke($option)?></option><?php endforeach; ?></select></label>
    <label>Jenis layanan<select name="service"><option value="">Semua layanan</option><?php foreach($services as $key=>[$label,$group]): ?><option value="<?=ke($key)?>" <?=$service===$key?'selected':''?>><?=ke($label)?></option><?php endforeach; ?></select></label>
    <?php if($operator): ?><input type="hidden" name="operator" value="<?=$operator?>"><?php endif; ?>
    <button class="button" type="submit">Tampilkan</button><a class="button secondary" href="kinerja_operator.php">Reset</a>
</form>
<?php if($error): ?><p class="notice error" role="alert"><?=ke($error)?></p><?php else: ?>
<?php if(!$data['history']): ?><p class="notice">Riwayat pelayanan belum tersedia. Data lama belum dapat dinilai per operator.</p><?php endif; ?>
<?php if($data['missing']): ?><p class="notice">Sumber layanan belum tersedia: <?=ke(implode(', ',$data['missing']))?>. Angka hanya mencakup sumber yang tersedia.</p><?php endif; ?>
<?php if($operator && !$selected): ?><p class="notice">Operator tidak ditemukan. Ringkasan seluruh operator ditampilkan.</p><?php endif; ?>
<?php if($selected): ?><div class="section-heading"><div><a href="<?=ke(klink(['operator'=>0,'page'=>1]))?>">← Semua operator</a><h2><?=ke($selected['name'])?></h2></div><span class="badge">Rincian operator</span></div><?php endif; ?>
<div class="stats">
    <article class="stat"><span>Selesai</span><strong><?=$stats['selesai']?></strong><small>Siklus dengan keputusan selesai</small></article>
    <article class="stat"><span>Rata-rata waktu</span><strong class="duration"><?=ke(kinerjaDuration(kinerjaMean($stats['durations'])))?></strong><small>Dari <?=count($stats['durations'])?> siklus selesai dengan waktu lengkap</small></article>
    <article class="stat"><span>Ditolak</span><strong><?=$stats['ditolak']?></strong><small>Alasan tetap perlu ditinjau</small></article>
    <article class="stat"><span>Masih diproses</span><strong><?=$stats['diproses']?></strong><small>Posisi pada akhir periode yang dipilih</small></article>
</div>
<p class="notice">Selesai dan ditolak dihitung berdasarkan tanggal keputusan dalam periode. Masih diproses mencakup antrean lama sampai akhir periode. Durasi memakai waktu kalender sejak mulai diproses, termasuk waktu menunggu. Unit mengikuti sumber layanan.</p>
<?php if(!$selected): ?>
<section class="panel"><div class="panel-heading"><h2>Daftar operator</h2><span>Urut nama · tanpa peringkat</span></div>
<div class="table-wrap"><table class="operator-table"><thead><tr><th>Operator</th><th>Selesai</th><th>Rata-rata / median</th><th>Rasio selesai</th><th>Ditolak</th><th>Masih diproses</th><th>Sampel waktu</th><th>Kepuasan pemohon</th></tr></thead><tbody>
<?php foreach($data['operators'] as $id=>$s): ?>
<tr><td data-label="Operator"><a class="operator-link" href="<?=ke(klink(['operator'=>$id,'page'=>1]))?>"><?=ke($s['name'])?></a><small><?=count($s['rows'])?> siklus teratribusi</small></td>
<td data-label="Selesai"><?=$s['selesai']?></td><td data-label="Rata-rata / median"><?=ke(kinerjaDuration(kinerjaMean($s['durations'])))?><small>Median: <?=ke(kinerjaDuration(kinerjaMedian($s['durations'])))?></small></td>
<td data-label="Rasio selesai"><?=ke(kratio($s))?></td><td data-label="Ditolak"><?=$s['ditolak']?></td><td data-label="Masih diproses"><?=$s['diproses']?></td><td data-label="Sampel waktu"><?=count($s['durations'])?> / <?=$s['selesai']?> selesai</td><td data-label="Kepuasan pemohon"><?=!empty($s['service_ratings']) ? ke(number_format(kinerjaMean($s['service_ratings']),1,',','.')).' / 5' : 'Belum dinilai'?><small><?=count($s['service_ratings']??[])?> tanggapan</small></td></tr>
<?php endforeach; ?>
<?php if(!$data['operators']): ?><tr><td colspan="8">Belum ada operator yang dapat ditampilkan.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php else: ?>
<section class="panel"><div class="panel-heading"><h2>Hasil dan kelengkapan data</h2></div><div class="panel-body">
<p>Rasio selesai: <strong><?=ke(kratio($selected))?></strong> · Median durasi: <strong><?=ke(kinerjaDuration(kinerjaMedian($selected['durations'])))?></strong>.</p>
<p>Kepuasan pelayanan: <strong><?=!empty($selected['service_ratings']) ? ke(number_format(kinerjaMean($selected['service_ratings']),1,',','.')).' / 5' : 'Belum dinilai'?></strong> dari <?=count($selected['service_ratings']??[])?> tanggapan pemohon untuk <?=$selected['selesai']?> siklus selesai.</p>
<p>Waktu lengkap tersedia untuk <strong><?=count($selected['durations'])?> dari <?=$selected['selesai']?> siklus selesai</strong>. Data tanpa waktu mulai tidak masuk rata-rata. Penolakan yang sah tidak otomatis berarti kinerja buruk.</p>
</div></section>
<section class="panel"><div class="panel-heading"><h2>Perkembangan 6 bulan</h2></div><div class="panel-body">
<p class="muted">Selesai dan ditolak per bulan, sampai akhir periode terpilih. Mengikuti filter unit dan layanan.</p>
<?php
$monthEnd = $end->modify('first day of this month');
$months = []; $maximum = 1;
for($i=5;$i>=0;$i--) {
    $month = $monthEnd->modify("-$i months")->format('Y-m');
    $entry = $data['trend'][$operator][$month] ?? [];
    $months[$month] = [$entry['selesai']??0,$entry['ditolak']??0];
    $maximum = max($maximum,array_sum($months[$month]));
}
foreach($months as $month=>[$done,$rejected]): ?>
<div class="trend"><span><?=ke($month)?></span><div class="bars" aria-hidden="true"><i class="done" style="width:<?=100*$done/$maximum?>%"></i><i class="rejected" style="width:<?=100*$rejected/$maximum?>%"></i></div><small><?=$done?> selesai · <?=$rejected?> ditolak</small></div>
<?php endforeach; ?>
</div></section>
<section class="panel"><div class="panel-heading"><h2>Permohonan penyusun angka</h2></div><div class="panel-body">
<form method="get" class="detail-filter">
<?php foreach(['from'=>$startText,'to'=>$endText,'unit'=>$unit,'service'=>$service,'operator'=>$operator] as $key=>$value): ?><input type="hidden" name="<?=ke($key)?>" value="<?=ke($value)?>"><?php endforeach; ?>
<label>Status<select name="status"><option value="">Semua status</option><?php foreach(['selesai','ditolak','diproses'] as $option): ?><option value="<?=$option?>" <?=kparam('status')===$option?'selected':''?>><?=ucfirst($option)?></option><?php endforeach; ?></select></label><button class="button">Saring</button></form>
<?php
$rows = array_values(array_filter($selected['rows'],static fn($r)=>!in_array(kparam('status'),['selesai','ditolak','diproses'],true)||$r['status']===kparam('status')));
usort($rows,static fn($a,$b)=>$b['end']<=>$a['end']);
$pages = max(1,(int)ceil(count($rows)/20)); $page = min($pages,max(1,(int)kparam('page','1')));
foreach(array_slice($rows,($page-1)*20,20) as $row): ?>
<article class="request"><div><h3><?=ke($row['service'])?> #<?=$row['id']?></h3><small><?=ke($row['unit'])?> · Siklus <?=$row['cycle']?> · <?=ke((new DateTimeImmutable('@'.$row['end']))->setTimezone(new DateTimeZone('Asia/Makassar'))->format('d/m/Y H:i'))?> WITA</small></div>
<span class="badge <?=ke($row['status'])?>"><?=ucfirst($row['status'])?></span>
<p>Nama pemohon: <strong><?=ke(trim((string)($row['applicant_name'] ?? '')) ?: 'Belum tercatat')?></strong><br>
Subjek: <strong><?=ke(trim((string)($row['subject_name'] ?? '')) ?: 'Belum tercatat')?></strong></p>
<?php if($row['status']!=='ditolak'): ?><p><?=$row['status']==='diproses'?'Umur proses':'Durasi selesai'?>: <strong><?=ke(kinerjaDuration($row['status']==='diproses'?($row['age']??null):$row['duration']))?></strong></p><?php endif; ?>
<?php if($row['status']==='ditolak'): ?><p>Alasan penolakan: <?=ke($row['reason']?:'Belum tercatat')?></p><?php endif; ?>
</article>
<?php endforeach; ?>
<?php if(!$rows): ?><p class="empty">Belum ada pekerjaan sesuai filter.</p><?php endif; ?>
<nav class="pagination" aria-label="Halaman permohonan">
<?php if($page>1): ?><a class="button secondary" href="<?=ke(klink(['page'=>$page-1,'status'=>kparam('status')]))?>">Sebelumnya</a><?php endif; ?>
<span>Halaman <?=$page?> / <?=$pages?> · <?=count($rows)?> siklus</span>
<?php if($page<$pages): ?><a class="button secondary" href="<?=ke(klink(['page'=>$page+1,'status'=>kparam('status')]))?>">Berikutnya</a><?php endif; ?>
</nav></div></section>
<?php endif; ?>
<section class="panel"><div class="panel-heading"><h2>Cakupan dan cara membaca</h2></div><div class="panel-body">
<p><strong><?=$data['untracked']?></strong> permohonan yang diajukan dalam periode ini belum memiliki riwayat pada akhir periode.</p>
<p><strong><?=count($data['excluded'])?></strong> siklus terpisah dari penilaian individu karena dikerjakan bersama, identitas pelakunya belum lengkap, atau status tidak cocok dengan riwayat. Angka cakupan ini berlaku untuk semua operator sesuai filter layanan.</p>
<?php if($data['excluded']): ?><details><summary>Lihat pekerjaan yang belum dinilai per individu</summary>
<?php foreach(array_slice($data['excluded'],0,50) as $row): ?><p><?=ke($row['service'])?> #<?=$row['id']?> · Siklus <?=$row['cycle']?> · <?=ke($row['status'])?> · <?=!empty($row['conflict'])?'Status tidak cocok dengan riwayat':($row['shared']?'Pekerjaan bersama':'Identitas pelaku belum lengkap')?></p><?php endforeach; ?>
<?php if(count($data['excluded'])>50): ?><p>Menampilkan 50 siklus pertama. Persempit periode atau jenis layanan untuk meninjau data lainnya.</p><?php endif; ?>
</details><?php endif; ?>
<p>Kepuasan hanya memakai rating pelayanan pemohon pada siklus selesai dalam periode ini, yang sudah dikirim sampai akhir periode. Rating aplikasi dan tanggapan petugas registrasi tersedia terpisah di <a href="tanggapan_pemohon.php">Tanggapan Pemohon</a>. Rating lama tidak dianggap sebagai rating pelayanan.</p><ul><li>Rasio selesai = selesai ÷ (selesai + ditolak). Pekerjaan diproses ditampilkan terpisah.</li>
<li>Pengajuan ulang dihitung sebagai siklus baru. Satu permohonan bisa memiliki beberapa siklus.</li>
<li>Bandingkan jenis layanan yang sama dan perhatikan jumlah sampel. Tidak ada nilai nol untuk durasi yang belum tercatat.</li>
<li>Identitas operator berasal dari riwayat tindakan, bukan wilayah tugas atau identitas pemohon.</li></ul>
</div></section>
<?php endif; ?>
</section></main></div>
<script>
const toggle = document.querySelector('.menu-toggle');
const sidebar = document.getElementById('adminSidebar');
function setMenu(open) {
    sidebar.classList.toggle('open',open);
    toggle.setAttribute('aria-expanded',String(open));
    toggle.setAttribute('aria-label',open?'Tutup menu':'Buka menu');
}
toggle.addEventListener('click',()=>setMenu(!sidebar.classList.contains('open')));
document.addEventListener('keydown',event=>{if(event.key==='Escape') setMenu(false);});
document.addEventListener('click',event=>{if(!sidebar.contains(event.target)&&!toggle.contains(event.target)) setMenu(false);});
</script>
</body></html>