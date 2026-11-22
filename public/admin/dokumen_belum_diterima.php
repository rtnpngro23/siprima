<?php
require_once __DIR__ . '/../error_handler.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include __DIR__ . '/../../app/middleware/admin_only.php';
include __DIR__ . '/../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/email_delivery.php';

function esc_admin_delivery($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$status = in_array($_GET['status'] ?? '', ['belum_masuk', 'diunggah_operator', 'sebagian_diunggah_perlu_pengajuan_baru', 'perlu_pengajuan_baru'], true) ? $_GET['status'] : '';
$kecamatanId = max(0, (int)($_GET['kecamatan'] ?? 0));
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$kecamatanResult = $koneksi->query("SELECT id,nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
$kecamatanList = $kecamatanResult ? $kecamatanResult->fetch_all(MYSQLI_ASSOC) : [];

$fromWhere = " FROM tb_konfirmasi_dokumen_email k
 LEFT JOIN tb_kecamatan kc ON kc.id=k.id_kecamatan
 LEFT JOIN tb_desa d ON d.id=k.id_desa
 LEFT JOIN tb_users op ON op.id=k.operator_id
 WHERE k.status_konfirmasi IN ('belum_masuk','diunggah_operator','sebagian_diunggah_perlu_pengajuan_baru','perlu_pengajuan_baru')";
$params = [];
if ($status !== '') { $fromWhere .= " AND k.status_konfirmasi=?"; $params[] = $status; }
if ($kecamatanId > 0) { $fromWhere .= " AND k.id_kecamatan=?"; $params[] = $kecamatanId; }
if ($search !== '') {
    $fromWhere .= " AND (k.nama_pemohon LIKE ? OR k.nama_subjek LIKE ? OR k.jenis_layanan LIKE ? OR d.nama_desa LIKE ? OR kc.nama_kecamatan LIKE ? OR op.nama LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$countStmt = $koneksi->prepare("SELECT COUNT(*) AS total" . $fromWhere);
$countStmt->execute($params);
$totalData = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$totalPages = max(1, (int)ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$query = "SELECT k.*,kc.nama_kecamatan,d.nama_desa,op.nama AS nama_operator" . $fromWhere . " ORDER BY k.updated_at DESC,k.id DESC LIMIT {$perPage} OFFSET {$offset}";
$stmt = $koneksi->prepare($query);
$stmt->execute($params);
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
foreach ($items as &$item) {
    $item['files'] = email_delivery_files($koneksi, (int)$item['id']);
    if (trim((string)($item['nama_subjek'] ?? '')) === '' || trim((string)$item['nama_subjek']) === '-') {
        $context = email_delivery_context($koneksi, (string)$item['source_table'], (int)$item['permohonan_id']);
        if ($context) $item['nama_subjek'] = $context['nama_subjek'];
    }
}
unset($item);

$statsResult = $koneksi->query("SELECT
 SUM(status_konfirmasi='belum_masuk') AS belum,
 SUM(status_konfirmasi='diunggah_operator') AS diunggah,
 SUM(status_konfirmasi='perlu_pengajuan_baru') AS pengajuan_baru,
 SUM(status_konfirmasi='sebagian_diunggah_perlu_pengajuan_baru') AS sebagian
 FROM tb_konfirmasi_dokumen_email
 WHERE status_konfirmasi IN ('belum_masuk','diunggah_operator','sebagian_diunggah_perlu_pengajuan_baru','perlu_pengajuan_baru')");
$stats = $statsResult ? $statsResult->fetch_assoc() : ['belum'=>0,'diunggah'=>0];

function admin_delivery_page_url(int $target, string $status, int $kecamatanId, string $search): string {
    return '?' . http_build_query(['status'=>$status,'kecamatan'=>$kecamatanId,'search'=>$search,'page'=>$target]);
}
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Dokumen Belum Diterima - Admin</title><link rel="icon" type="image/webp" href="../assets/prima.webp"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="dashboard_admin.css"><link rel="stylesheet" href="common.css"><style>
.monitor{padding:30px}.monitor-summary{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:16px;margin-bottom:20px}.monitor-card{background:#fff;border-radius:12px;padding:18px;border-left:5px solid #3498db;box-shadow:0 4px 12px rgba(0,0,0,.07)}.monitor-card.warning{border-color:#f59e0b}.monitor-card.success{border-color:#16a34a}.monitor-card strong{display:block;font-size:26px;margin-top:8px}.filters{display:flex;gap:10px;align-items:end;flex-wrap:wrap;background:#fff;padding:16px;border-radius:12px;margin-bottom:18px}.filters label{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:600}.filters input,.filters select{min-width:180px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px}.action-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:7px;padding:9px 13px;background:#3498db;color:#fff;text-decoration:none;cursor:pointer}.action-btn.secondary{background:#64748b}.table-wrap{overflow-x:auto;background:#fff;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.06)}table{width:100%;min-width:1350px;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top;font-size:13px}th{background:#f8fafc;color:#334155}.status-pill{display:inline-block;padding:5px 9px;border-radius:999px;font-weight:700;font-size:12px}.status-pending{background:#fef3c7;color:#92400e}.status-uploaded{background:#dcfce7;color:#166534}.file-link{display:block;margin:4px 0;color:#2563eb;text-decoration:none}.pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:18px}.pages{display:flex;gap:6px}.page-link{padding:8px 11px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#334155;text-decoration:none}.page-link.active{background:#3498db;color:#fff;border-color:#3498db}.page-link.disabled{pointer-events:none;opacity:.45}@media(max-width:768px){.monitor{padding:18px 12px}.monitor-summary{grid-template-columns:1fr}.filters>*{width:100%}.filters input,.filters select{width:100%}}
</style>    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head><body><div class="layout"><aside class="sidebar"><div class="logo">Pelayanan Kependudukan</div><nav><ul><li><a href="dashboard_admin.php">Dashboard</a></li><li><a href="manajemen_operator.php">Manajemen Pengguna</a></li><li><a href="reset_password.php">Reset Password Pengguna</a></li><li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li><li><a href="laporan_admin.php">Laporan &amp; Analisis</a></li><li><a href="kinerja_operator.php">Kinerja Operator</a></li><li class="active"><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li><li><a href="tanggapan_pemohon.php">Hasil Survei</a></li><li><a href="whistleblowing.php">Whistleblowing</a></li><li><a href="formulir.php">Formulir Kependudukan</a></li><li><a href="manajemen_formulir_digital.php">Manajemen Formulir Digital</a></li><li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
                <li class="logout-item"><button id="logoutBtn" class="sidebar-btn">Logout</button></li></ul></nav></aside><div class="menu-toggle">☰</div><main class="main"><header class="header"><div class="left-header"><div class="title">Dokumen Belum Diterima</div><div style="color:#7f8c8d;font-size:14px"><i class="fas fa-file-circle-exclamation"></i> Pemantauan tindak lanjut operator</div></div><div class="user-info"><span>Hai, <?=esc_admin_delivery($_SESSION['nama']??'Admin')?></span><button class="logout" id="logoutBtn2">Logout</button></div></header><section class="monitor">
<div class="monitor-summary"><div class="monitor-card"><span>Total</span><strong><?=((int)($stats['belum']??0)+(int)($stats['diunggah']??0)+(int)($stats['sebagian']??0)+(int)($stats['pengajuan_baru']??0))?></strong></div><div class="monitor-card warning"><span>Belum Ditangani</span><strong><?=(int)($stats['belum']??0)?></strong></div><div class="monitor-card success"><span>Sudah Diunggah Operator</span><strong><?=(int)($stats['diunggah']??0)?></strong></div><div class="monitor-card warning"><span>Dokumen Sebagian</span><strong><?=(int)($stats['sebagian']??0)?></strong></div><div class="monitor-card warning"><span>Perlu Pengajuan Baru</span><strong><?=(int)($stats['pengajuan_baru']??0)?></strong></div></div>
<form class="filters" method="get"><label>Status<select name="status"><option value="">Semua Status</option><option value="belum_masuk" <?=$status==='belum_masuk'?'selected':''?>>Belum Ditangani</option><option value="diunggah_operator" <?=$status==='diunggah_operator'?'selected':''?>>Sudah Diunggah</option><option value="sebagian_diunggah_perlu_pengajuan_baru" <?=$status==='sebagian_diunggah_perlu_pengajuan_baru'?'selected':''?>>Dokumen Sebagian</option><option value="perlu_pengajuan_baru" <?=$status==='perlu_pengajuan_baru'?'selected':''?>>Perlu Pengajuan Baru</option></select></label><label>Kecamatan<select name="kecamatan"><option value="0">Semua Kecamatan</option><?php foreach($kecamatanList as $kecamatan):?><option value="<?=(int)$kecamatan['id']?>" <?=$kecamatanId===(int)$kecamatan['id']?'selected':''?>><?=esc_admin_delivery($kecamatan['nama_kecamatan'])?></option><?php endforeach;?></select></label><label>Pencarian<input type="search" name="search" value="<?=esc_admin_delivery($search)?>" placeholder="Pemohon, subjek, operator"></label><button class="action-btn" type="submit"><i class="fas fa-search"></i>&nbsp; Cari</button><a class="action-btn secondary" href="dokumen_belum_diterima.php">Reset</a></form>
<div class="table-wrap">
  <table>
    <thead><tr><th>No</th><th>Layanan</th><th>Pemohon</th><th>Subjek</th><th>Wilayah</th><th>Catatan Pemohon</th><th>Catatan Operator</th><th>Dikonfirmasi Pemohon</th><th>Status</th><th>Operator</th><th>Dokumen</th><th>Terakhir Diperbarui</th></tr></thead>
    <tbody id="monitorBody">
      <?php if(!$items):?><tr><td colspan="12" style="text-align:center;padding:28px">Tidak ada data yang sesuai.</td></tr><?php endif;?>
      <?php $number=$offset+1;foreach($items as $item):?>
        <tr>
          <td><?=$number++?></td><td><?=esc_admin_delivery($item['jenis_layanan'])?></td><td><?=esc_admin_delivery($item['nama_pemohon'])?></td><td><?=esc_admin_delivery($item['nama_subjek'])?></td>
          <td><?=esc_admin_delivery($item['nama_kecamatan']??'-')?> / <?=esc_admin_delivery($item['nama_desa']??'-')?></td>
          <td style="white-space:pre-wrap;min-width:190px"><?=esc_admin_delivery($item['catatan_pemohon']??'-')?></td>
          <td style="white-space:pre-wrap;min-width:190px"><?=esc_admin_delivery($item['catatan_operator']??'-')?><br><small><?=esc_admin_delivery($item['tindak_lanjut_at']??'')?></small></td>
          <td><?=esc_admin_delivery($item['confirmed_at']??'-')?> <small>WITA</small></td>
          <td><?php if($item['status_konfirmasi']==='diunggah_operator'):?><span class="status-pill status-uploaded">Sudah Diunggah</span><?php elseif($item['status_konfirmasi']==='sebagian_diunggah_perlu_pengajuan_baru'):?><span class="status-pill status-pending">Dokumen Sebagian</span><?php elseif($item['status_konfirmasi']==='perlu_pengajuan_baru'):?><span class="status-pill status-pending">Perlu Pengajuan Baru</span><?php else:?><span class="status-pill status-pending">Belum Ditangani</span><?php endif;?></td>
          <td><?=esc_admin_delivery($item['nama_operator']??'-')?><br><small><?=esc_admin_delivery($item['operator_confirmed_at']??'')?></small></td>
          <td><?php if(!$item['files']):?>-<?php endif;?><?php foreach($item['files'] as $file):?><a class="file-link" href="download_email_delivery.php?id=<?=(int)$file['id']?>"><i class="fas fa-file-pdf"></i> <?=esc_admin_delivery($file['original_name'])?></a><?php endforeach;?></td>
          <td><?=esc_admin_delivery($item['updated_at'])?> <small>WITA</small></td>
        </tr>
      <?php endforeach;?>
    </tbody>
  </table>
</div>
<div class="pagination"><span>Menampilkan <?=count($items)?> dari <?=$totalData?> data · Halaman <?=$page?> dari <?=$totalPages?></span><div class="pages"><a class="page-link <?=$page<=1?'disabled':''?>" href="<?=esc_admin_delivery(admin_delivery_page_url(max(1,$page-1),$status,$kecamatanId,$search))?>">Sebelumnya</a><?php for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++):?><a class="page-link <?=$i===$page?'active':''?>" href="<?=esc_admin_delivery(admin_delivery_page_url($i,$status,$kecamatanId,$search))?>"><?=$i?></a><?php endfor;?><a class="page-link <?=$page>=$totalPages?'disabled':''?>" href="<?=esc_admin_delivery(admin_delivery_page_url(min($totalPages,$page+1),$status,$kecamatanId,$search))?>">Berikutnya</a></div></div>
</section></main></div><div class="modal-overlay" id="logoutModal"><div class="modal-box"><h3>Konfirmasi Logout</h3><p>Apakah Anda yakin ingin logout?</p><div class="modal-actions"><button id="confirmLogout" class="btn-yes">Ya, Logout</button><button id="cancelLogout" class="btn-no">Batal</button></div></div></div><script>document.addEventListener('DOMContentLoaded',()=>{const sidebar=document.querySelector('.sidebar');const toggle=document.querySelector('.menu-toggle');toggle?.addEventListener('click',()=>sidebar?.classList.toggle('open'));const modal=document.getElementById('logoutModal');const show=()=>{modal?.classList.add('show');document.body.style.overflow='hidden';};const hide=()=>{modal?.classList.remove('show');document.body.style.overflow='';};document.getElementById('logoutBtn')?.addEventListener('click',show);document.getElementById('logoutBtn2')?.addEventListener('click',show);document.getElementById('cancelLogout')?.addEventListener('click',hide);document.getElementById('confirmLogout')?.addEventListener('click',()=>location.href='../logout.php');modal?.addEventListener('click',e=>{if(e.target===modal)hide();});document.addEventListener('keydown',e=>{if(e.key==='Escape')hide();});window.setInterval(()=>{if(!document.hidden)location.reload();},60000);});</script></body></html>
