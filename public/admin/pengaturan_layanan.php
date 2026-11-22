<?php
require_once '../error_handler.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../app/middleware/admin_only.php';
require_once __DIR__ . '/../../app/config/service_hours.php';
require_once __DIR__ . '/../../app/Models/KiaModel.php';
require_once __DIR__ . '/../../app/Services/WhatsAppService.php';
function sh_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function sh_history_summary(string $payload): string {
    $s = json_decode($payload, true);
    if (!is_array($s)) return '<p>Riwayat tidak dapat dibaca.</p>';
    $names = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];
    $days = array_map(function ($d) use ($names) { return $names[$d] ?? '-'; }, $s['days']);
    $html = '<p><strong>Jadwal rutin:</strong> ' . ($s['enabled'] ? 'Aktif' : 'Nonaktif') . '<br>'
        . sh_escape(implode(', ', $days).' • '.$s['open'].'–'.$s['close'].' WITA') . '</p>';
    if (!$s['events']) return $html . '<p>Belum ada event.</p>';
    foreach ($s['events'] as $event) {
        $html .= '<p><strong>'.sh_escape($event['title']).'</strong> • '.($event['enabled']?'Aktif':'Nonaktif')
            . '<br>'.($event['kind']==='closure'?'Penutupan sementara':'Pemberitahuan saja')
            . '<br>Mulai tampil: '.sh_escape(str_replace('T',' ',$event['announce']))
            . '<br>'.sh_escape(str_replace('T',' ',$event['start']).' s.d. '.str_replace('T',' ',$event['end']).' WITA')
            . '<br>'.nl2br(sh_escape($event['message'])).'</p>';
    }
    return $html;
}
$error = '';
$preview = null;
$draft = null;
$db = null;
$row = null;
$settings = service_hours_defaults();
$audit = [];
$loaded = false;
try {
    $db = service_hours_db();
    $result = $db->query('SELECT payload, revision FROM tb_service_settings WHERE id=1');
    if (!$result || !($row = $result->fetch_assoc())) throw new RuntimeException('Pengaturan belum tersedia. Jalankan migrasi sql/20260906_service_settings.sql.');
    $settings = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
    service_hours_validate($settings);
    $loaded = true;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!csrf_validate()) throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'cetak_kia_settings') {
            $kiaAdminModel = new \Siprima\Models\KiaModel($db);
            $blangko = (int)($_POST['blangko_tersedia'] ?? 1) === 1 ? 1 : 0;
            $tinta   = (int)($_POST['tinta_tersedia'] ?? 1) === 1 ? 1 : 0;
            $ribbon  = $tinta;
            $catatanCetak = trim((string)($_POST['catatan_cetak_kia'] ?? ''));
            $dataCetak = [
                'blangko_tersedia' => $blangko,
                'tinta_tersedia'   => $tinta,
                'ribbon_tersedia'  => $ribbon,
                'catatan'          => $catatanCetak
            ];
            $actor = [
                'id'   => (int)($_SESSION['id_user'] ?? 0),
                'role' => (string)($_SESSION['role'] ?? 'admin'),
                'nama' => (string)($_SESSION['nama'] ?? 'Admin')
            ];
            $kiaAdminModel->updatePengaturanCetakKia($dataCetak, $actor);
            $_SESSION['service_settings_saved'] = true;
            header('Location: pengaturan_layanan.php#cetak-kia');
            exit;
        }
        $next = $settings;
        if ($action === 'schedule') {
            $next['enabled'] = isset($_POST['enabled']);
            $next['days'] = array_values(array_unique(array_map('intval', (array)($_POST['days'] ?? []))));
            sort($next['days']);
            $next['open'] = (string)($_POST['open'] ?? '');
            $next['close'] = (string)($_POST['close'] ?? '');
        } elseif (in_array($action, ['event','preview'], true)) {
            $id = (string)($_POST['event_id'] ?? '');
            if ($id !== '' && !in_array($id, array_column($settings['events'], 'id'), true)) throw new RuntimeException('Event tidak ditemukan.');
            $draft = ['id'=>$id ?: bin2hex(random_bytes(8)), 'enabled'=>isset($_POST['event_enabled']),
                'kind'=>(string)($_POST['kind'] ?? ''), 'title'=>trim((string)($_POST['title'] ?? '')),
                'message'=>trim((string)($_POST['message'] ?? '')), 'announce'=>(string)($_POST['announce'] ?? ''),
                'start'=>(string)($_POST['start'] ?? ''), 'end'=>(string)($_POST['end'] ?? '')];
            $next['events'] = array_values(array_filter($next['events'], function ($event) use ($id) { return $event['id'] !== $id; }));
            $next['events'][] = $draft;
        } elseif ($action === 'delete') {
            $id = (string)($_POST['event_id'] ?? '');
            if (!in_array($id, array_column($settings['events'], 'id'), true)) throw new RuntimeException('Event tidak ditemukan.');
            $next['events'] = array_values(array_filter($next['events'], function ($event) use ($id) {
                return $event['id'] !== $id;
            }));
        } elseif ($action === 'cancel') {
            $found = false;
            foreach ($next['events'] as &$event) {
                if ($event['id'] === (string)($_POST['event_id'] ?? '')) { $event['enabled'] = false; $found = true; }
            }
            unset($event);
            if (!$found) throw new RuntimeException('Event tidak ditemukan.');
        } else {
            throw new RuntimeException('Aksi tidak valid.');
        }
        service_hours_validate($next);
        if ($action === 'preview') {
            $preview = [
                service_hours_notice_for($draft, service_hours_datetime($draft['announce']), $next),
                service_hours_notice_for($draft, service_hours_datetime($draft['start']), $next)
            ];
        } else {
            if (!$db->begin_transaction()) throw new RuntimeException('Tidak dapat memulai penyimpanan.');
            try {
                $lockedResult = $db->query('SELECT payload, revision FROM tb_service_settings WHERE id=1 FOR UPDATE');
                if (!$lockedResult || !($locked = $lockedResult->fetch_assoc())) throw new RuntimeException('Pengaturan tidak dapat dibaca.');
                if ((int)$locked['revision'] !== (int)($_POST['revision'] ?? 0)) {
                    throw new RuntimeException('Pengaturan telah diubah admin lain. Muat ulang halaman sebelum menyimpan.');
                }
                $payload = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $stmt = $db->prepare('UPDATE tb_service_settings SET payload=?, revision=revision+1 WHERE id=1');
                if (!$stmt) throw new RuntimeException('Penyimpanan gagal.');
                $stmt->bind_param('s', $payload);
                if (!$stmt->execute()) throw new RuntimeException('Penyimpanan gagal.');
                $stmt->close();
                $adminId = (int)$_SESSION['id_user'];
                $at = (new DateTimeImmutable('now', new DateTimeZone(SERVICE_HOURS_TIMEZONE)))->format('Y-m-d H:i:s');
                $stmt = $db->prepare('INSERT INTO tb_service_settings_audit (admin_id,changed_at,action,before_payload,after_payload) VALUES (?,?,?,?,?)');
                if (!$stmt) throw new RuntimeException('Riwayat tidak dapat disimpan.');
                $before = $locked['payload'];
                $stmt->bind_param('issss', $adminId, $at, $action, $before, $payload);
                if (!$stmt->execute()) throw new RuntimeException('Riwayat tidak dapat disimpan.');
                $stmt->close();
                if (!$db->commit()) throw new RuntimeException('Penyimpanan gagal.');
                $_SESSION['service_settings_saved'] = true;
                header('Location: pengaturan_layanan.php');
                exit;
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
        }
    }
    $history = $db->query('SELECT id,admin_id,changed_at,action,before_payload,after_payload FROM tb_service_settings_audit ORDER BY id DESC LIMIT 30');
    if ($history) $audit = $history->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    $error = $e instanceof mysqli_sql_exception || $e instanceof JsonException ? 'Database pengaturan tidak dapat diproses. Hubungi pengelola aplikasi.' : $e->getMessage();
}
if (!$loaded) { $settings = service_hours_defaults(); $row = null; }
$saved = !empty($_SESSION['service_settings_saved']);
unset($_SESSION['service_settings_saved']);
$now = new DateTimeImmutable('now', new DateTimeZone(SERVICE_HOURS_TIMEZONE));
$status = service_hours_status($now, $row ? $settings : array_merge($settings, ['unavailable'=>true]));
$edit = $draft;
if (!$edit && isset($_GET['edit'])) {
    foreach ($settings['events'] as $event) { if ($event['id'] === $_GET['edit']) $edit = $event; }
}
$edit = $edit ?? ['id'=>'','enabled'=>true,'kind'=>'closure','title'=>'','message'=>'','announce'=>'','start'=>'','end'=>''];
// A preview of a new event must remain a new event when submitted again.
if ($draft && empty($_POST['event_id'])) $edit['id'] = '';
$nextChange = service_hours_next_change($now, $row ? $settings : array_merge($settings, ['unavailable'=>true]));
$dayNames = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];
$events = $settings['events'];
usort($events, function ($a, $b) { return strcmp($b['start'], $a['start']); });
$revision = (int)($row['revision'] ?? 0);
$pengaturanCetakKia = [
    'blangko_tersedia' => true,
    'tinta_tersedia' => true,
    'ribbon_tersedia' => true,
    'layanan_cetak_aktif' => true,
    'catatan' => '',
    'pesan_status' => 'Seluruh bahan cetak kartu fisik KIA tersedia.',
    'updated_at' => null,
];
$logCetakKia = [];
$waIntegrationStatus = [
    'enabled' => false,
    'configured' => false,
    'active' => false,
    'provider' => 'cloud_api',
    'has_api_key' => false,
    'has_phone_number_id' => false,
    'has_gateway_url' => false,
    'counts' => ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0],
];
if ($db instanceof mysqli) {
    try {
        $waService = new \Siprima\Services\WhatsAppService($db);
        $waIntegrationStatus = $waService->getIntegrationStatus();
    } catch (Throwable $e) {
        // ignore fallback
    }
}
if ($db instanceof mysqli) {
    try {
        $kiaAdminModel = new \Siprima\Models\KiaModel($db);
        $pengaturanCetakKia = $kiaAdminModel->getPengaturanCetakKia();
        $resLogKia = $db->query("SELECT l.*, u.nama AS nama_admin FROM tb_pengaturan_cetak_kia_log l LEFT JOIN tb_users u ON u.id = l.updated_by ORDER BY l.id DESC LIMIT 15");
        if ($resLogKia) {
            $logCetakKia = $resLogKia->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Throwable $e) {
        // ignore fallback
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pengaturan Layanan - Admin SI PRIMA</title>
<link rel="icon" href="../assets/prima.webp">
<link rel="stylesheet" href="common.css">
<link rel="stylesheet" href="css/pengaturan_layanan.css?v=20261121-2">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>
<div class="layout">
<aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan</div>
        <nav>
            <ul>
                <li><a href="dashboard_admin.php">Dashboard</a></li>
                <li><a href="manajemen_operator.php">Manajemen Pengguna</a></li>                
                <li><a href="reset_password.php">Reset Password Pengguna</a></li>
                <li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li>
                <li><a href="laporan_admin.php">Laporan & Analisis</a></li><li><a href="kinerja_operator.php">Kinerja Operator</a></li>
                <li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li><a href="tanggapan_pemohon.php">Hasil Survei</a></li>
                <li><a href="whistleblowing.php">Whistleblowing</a></li>
                <li><a href="formulir.php">Formulir Kependudukan</a></li>
                <li class="active"><a href="pengaturan_layanan.php" aria-current="page">Pengaturan Layanan</a></li>
                <li class="logout-item">
                    <button id="logoutBtn" class="sidebar-btn">Logout</button>
                </li>
            </ul>
        </nav>
    </aside>
<button type="button" class="menu-toggle" aria-label="Buka atau tutup menu admin">☰</button>
<main class="main">
    <header class="header">
        <div class="left-header">
            <h1 class="title">Pengaturan Layanan</h1>
            <p class="settings-subtitle">Jadwal pengajuan dan pemberitahuan LINE/Umum dan ADD • WITA</p>
        </div>
        <div class="user-info">
            <span>Hai, <?= sh_escape($_SESSION['nama'] ?? 'Admin') ?></span>
            <button type="button" class="logout" id="logoutBtn2">Logout</button>
        </div>
    </header>
    <div class="content service-settings">
<?php if ($saved): ?><p class="notice success" role="status">Pengaturan berhasil disimpan. Perubahan berlaku pada pemeriksaan pengajuan berikutnya.</p><?php endif; ?>
<?php if ($error): ?><p class="notice error" role="alert"><?=sh_escape($error)?></p><?php endif; ?>
<section class="panel">
<h2>Status pengajuan saat ini <span class="badge <?=$status['is_open']?'open':'closed'?>"><?=$status['is_open']?'Aktif':'Dibatasi'?></span></h2>
<p><?=$status['is_open'] ? 'Pengajuan baru, edit, dan ajukan kembali tersedia.' : sh_escape(service_hours_closed_message($status))?></p>
<p><strong>Jadwal rutin:</strong> <?=sh_escape($status['label'])?></p>
<?php if ($nextChange): ?><p><strong>Perubahan berikutnya:</strong> Pengajuan <?=$nextChange['is_open']?'aktif':'dibatasi'?> pada <?=sh_escape($nextChange['at'])?>.</p><?php endif; ?>
<p class="muted">Login, status permohonan, dan unduh dokumen tetap tersedia. KUA/PASUTRI, AHLAK, ST SEHATI, admin, dan operator tetap dapat bekerja.</p>
<div class="subpage-nav-row">
    <div>
        <strong>Notifikasi WhatsApp Pemohon:</strong>
        <span class="wa-badge <?= !empty($waIntegrationStatus['active']) ? 'wa-badge--active' : (!empty($waIntegrationStatus['configured']) ? 'wa-badge--warn' : 'wa-badge--muted') ?>">
            <?= !empty($waIntegrationStatus['active']) ? 'Terhubung &amp; Aktif' : (!empty($waIntegrationStatus['configured']) ? 'Kredensial Tersedia (Nonaktif)' : 'Belum Dikonfigurasi') ?>
        </span>
    </div>
    <a href="pengaturan_notifikasi_whatsapp.php" class="btn-subpage-wa">Notifikasi WhatsApp &rarr;</a>
</div>
</section>
<nav class="tabs" aria-label="Bagian pengaturan">
    <a href="#cetak-kia">Bahan Cetak KIA</a>
    <a href="#jadwal">Jam Rutin</a>
    <a href="#event">Event &amp; Pemberitahuan</a>
    <a href="#riwayat">Riwayat</a>
    <a href="pengaturan_notifikasi_whatsapp.php" class="tab-page-link">Notifikasi WhatsApp &rarr;</a>
</nav>
<section class="panel" id="cetak-kia">
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
    <h2 style="margin:0;">🖨️ Pengaturan Ketersediaan Bahan Cetak KIA (Kartu Fisik)</h2>
    <span class="badge <?=!empty($pengaturanCetakKia['layanan_cetak_aktif'])?'open':'closed'?>" style="font-size:13px;font-weight:700;padding:5px 14px;">
        <?=!empty($pengaturanCetakKia['layanan_cetak_aktif'])?'✓ Layanan Cetak Aktif':'⚠️ Cetak Fisik Dibatasi'?>
    </span>
</div>
<p style="color:#475569;font-size:14px;line-height:1.6;">Atur ketersediaan bahan cetak fisik Kartu Identitas Anak (KIA) secara terpisah. Jika salah satu bahan (<strong>Blangko KIA</strong> atau <strong>Tinta</strong>) diatur <em>Tidak Tersedia</em>, pemohon tidak dapat mengajukan cetak kartu fisik KIA baru dan operator tidak dapat memproses pencetakan fisik sampai bahan tersedia kembali. Pengajuan permohonan KIA dan unduh PDF KIA tetap berjalan normal.</p>
<div style="background:<?=!empty($pengaturanCetakKia['layanan_cetak_aktif'])?'#f0fdf4':'#fffbeb'?>;border:1px solid <?=!empty($pengaturanCetakKia['layanan_cetak_aktif'])?'#bbf7d0':'#fde68a'?>;border-radius:10px;padding:12px 16px;margin:14px 0 18px;">
    <strong style="color:<?=!empty($pengaturanCetakKia['layanan_cetak_aktif'])?'#166534':'#92400e'?>;">Status Saat Ini:</strong>
    <span style="color:<?=!empty($pengaturanCetakKia['layanan_cetak_aktif'])?'#15803d':'#b45309'?>;margin-left:6px;"><?=sh_escape($pengaturanCetakKia['pesan_status'] ?? '')?></span>
</div>
<form method="post" id="cetak-kia-form">
<?=csrf_input()?>
<input type="hidden" name="action" value="cetak_kia_settings">
<div class="grid">
    <label>Status Blangko KIA
        <select name="blangko_tersedia">
            <option value="1" <?=!empty($pengaturanCetakKia['blangko_tersedia'])?'selected':''?>>Tersedia</option>
            <option value="0" <?=empty($pengaturanCetakKia['blangko_tersedia'])?'selected':''?>>Tidak Tersedia (Habis / Kosong)</option>
        </select>
    </label>
    <label>Status Tinta Printer KIA
        <select name="tinta_tersedia">
            <option value="1" <?=!empty($pengaturanCetakKia['tinta_tersedia'])?'selected':''?>>Tersedia</option>
            <option value="0" <?=empty($pengaturanCetakKia['tinta_tersedia'])?'selected':''?>>Tidak Tersedia (Habis / Kosong)</option>
        </select>
    </label>
</div>
<label>Catatan / Alasan Ketidaktersediaan (Ditampilkan kepada Pemohon &amp; Operator)
    <textarea name="catatan_cetak_kia" rows="2" maxlength="500" placeholder="Contoh: Stok blangko KIA sedang dalam proses pengiriman dari pusat. Pemohon tetap dapat mengunduh dokumen PDF KIA resmi."><?=sh_escape($pengaturanCetakKia['catatan'] ?? '')?></textarea>
</label>
<div class="actions">
    <button type="submit">Simpan Pengaturan Bahan Cetak KIA</button>
</div>
</form>
<?php if (!empty($logCetakKia)): ?>
<details style="margin-top:14px;">
    <summary style="cursor:pointer;font-weight:600;">Riwayat Perubahan Bahan Cetak KIA (<?=count($logCetakKia)?> terakhir)</summary>
    <div class="table-wrap" style="margin-top:10px;">
        <table>
            <thead><tr><th>Waktu</th><th>Admin</th><th>Blangko</th><th>Tinta</th><th>Catatan</th></tr></thead>
            <tbody>
            <?php foreach ($logCetakKia as $lg): ?>
                <tr>
                    <td><?=sh_escape($lg['created_at'] ?? '-')?></td>
                    <td><?=sh_escape($lg['nama_admin'] ?? ('Admin #' . (int)($lg['updated_by'] ?? 0)))?></td>
                    <td><?=!empty($lg['blangko_tersedia']) ? 'Tersedia' : 'Tidak Tersedia'?></td>
                    <td><?=!empty($lg['tinta_tersedia']) ? 'Tersedia' : 'Tidak Tersedia'?></td>
                    <td><?=sh_escape($lg['catatan'] ?? '-')?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>
<?php endif; ?>
</section>
<section class="panel" id="jadwal">
<h2>Jam layanan rutin</h2>
<form method="post">
<?=csrf_input()?><input type="hidden" name="revision" value="<?=$revision?>"><input type="hidden" name="action" value="schedule">
<label class="check"><input type="checkbox" name="enabled" <?=$settings['enabled']?'checked':''?>> Batasi pengajuan sesuai jadwal rutin</label>
<p class="muted">Jika dinonaktifkan, pengajuan tersedia 24 jam kecuali ada event penutupan aktif.</p>
<fieldset><legend>Hari layanan</legend><div class="days"><?php foreach ($dayNames as $day=>$name): ?><label class="check"><input type="checkbox" name="days[]" value="<?=$day?>" <?=in_array($day,$settings['days'],true)?'checked':''?>><?=$name?></label><?php endforeach; ?></div></fieldset>
<div class="grid"><label>Jam buka<input type="time" name="open" required value="<?=sh_escape($settings['open'])?>"></label><label>Jam tutup<input type="time" name="close" required value="<?=sh_escape($settings['close'])?>"></label></div>
<button <?=$row?'':'disabled'?>>Simpan jadwal</button>
</form>
</section>
<section class="panel" id="event">
<h2><?=$edit['id']?'Edit event':'Tambah event / hari libur'?></h2>
<p>Untuk libur sehari, pilih penutupan dari pukul 00.00 sampai 00.00 hari berikutnya. Event selesai otomatis; setelah itu pengajuan mengikuti jadwal rutin.</p>
<form method="post" id="event-form">
<?=csrf_input()?><input type="hidden" name="revision" value="<?=$revision?>"><input type="hidden" name="event_id" value="<?=sh_escape($edit['id'])?>">
<div class="grid"><label>Jenis<select name="kind"><option value="closure" <?=$edit['kind']==='closure'?'selected':''?>>Penutupan sementara + pemberitahuan</option><option value="notice" <?=$edit['kind']==='notice'?'selected':''?>>Pemberitahuan saja</option></select></label><label class="check"><input type="checkbox" name="event_enabled" <?=$edit['enabled']?'checked':''?>>Aktifkan / jadwalkan event</label></div>
<label>Judul<input name="title" required maxlength="200" value="<?=sh_escape($edit['title'])?>" placeholder="Contoh: Perbaikan jaringan"></label>
<label>Pesan / alasan<textarea name="message" required maxlength="4000" rows="3" placeholder="Tuliskan informasi untuk masyarakat. Keterangan waktu dan layanan terdampak ditambahkan otomatis untuk penutupan."><?=sh_escape($edit['message'])?></textarea></label>
<div class="grid three"><?php foreach (['announce'=>'Mulai tampil pemberitahuan','start'=>'Mulai event','end'=>'Selesai event'] as $key=>$label): ?><label><?=$label?> (WITA)<input type="datetime-local" name="<?=$key?>" required value="<?=sh_escape($edit[$key])?>"></label><?php endforeach; ?></div>
<p class="muted">Penutupan hanya membatasi pengajuan baru, edit, dan ajukan kembali LINE/Umum dan ADD. Jadwal penutupan yang bertabrakan tidak dapat disimpan. Jika beberapa pengumuman bersamaan, penutupan aktif ditampilkan lebih dahulu.</p>
<div class="actions"><button name="action" value="preview" class="secondary" <?=$row?'':'disabled'?>>Pratinjau pesan</button><button name="action" value="event" <?=$row?'':'disabled'?>>Simpan event</button><?php if ($edit['id']): ?><a href="pengaturan_layanan.php#event">Batal edit</a><?php endif; ?></div>
</form>
<?php if ($preview): ?><div class="preview"><h3>Pratinjau pemberitahuan</h3><?php foreach ($preview as $i=>$notice): ?><h4><?=$i?'Saat event berlangsung':'Saat mulai diumumkan'?> — <?=sh_escape($notice['title'])?></h4><p><?=nl2br(sh_escape($notice['message']))?></p><?php endforeach; ?></div><?php endif; ?>
</section>
<section class="panel">
<h2>Daftar event</h2>
<?php if (!$events): ?><p class="muted">Belum ada event. Tambahkan pemberitahuan atau jadwal penutupan melalui formulir di atas.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>Mulai tampil</th><th>Periode WITA</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($events as $event):
$stamp=$now->format('Y-m-d\TH:i');
$label=!$event['enabled']?'Nonaktif':($stamp >= $event['end']?'Selesai':($stamp >= $event['start']?'Berlangsung':'Terjadwal'));
?>
<tr><td><strong><?=sh_escape($event['title'])?></strong><br><?=$event['kind']==='closure'?'Penutupan sementara':'Pemberitahuan saja'?></td><td><?=sh_escape(str_replace('T',' ',$event['announce']))?></td><td><?=sh_escape(str_replace('T',' ',$event['start']))?><br>s.d. <?=sh_escape(str_replace('T',' ',$event['end']))?></td><td><?=$label?></td><td><a href="?edit=<?=sh_escape($event['id'])?>#event">Edit</a>
<?php if ($event['enabled'] && $stamp < $event['end']): ?><form method="post" class="inline"><?=csrf_input()?><input type="hidden" name="revision" value="<?=$revision?>"><input type="hidden" name="event_id" value="<?=sh_escape($event['id'])?>"><button class="secondary" name="action" value="cancel">Nonaktifkan</button></form><?php endif; ?>
<form method="post" class="inline" onsubmit="return confirm('Hapus event ini? Pengumuman dan penutupannya akan dihapus. Layanan kembali mengikuti jadwal dan event lain yang berlaku. Riwayat perubahan tetap tersimpan.');">
<?=csrf_input()?>
<input type="hidden" name="revision" value="<?=$revision?>">
<input type="hidden" name="event_id" value="<?=sh_escape($event['id'])?>">
<button type="submit" class="event-delete" name="action" value="delete" aria-label="Hapus event <?=sh_escape($event['title'])?>">Hapus</button>
</form>
</td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<section class="panel" id="riwayat"><h2>Riwayat perubahan</h2><p class="muted">30 perubahan terakhir. Riwayat lengkap tetap tersimpan.</p>
<?php if (!$audit): ?><p>Belum ada perubahan.</p><?php endif; ?>
<?php foreach ($audit as $item): ?><details><summary><?=sh_escape($item['changed_at'])?> WITA • Admin #<?=(int)$item['admin_id']?> • <?=sh_escape(['schedule'=>'Jadwal rutin','event'=>'Simpan event','cancel'=>'Nonaktifkan event','delete'=>'Hapus event'][$item['action']] ?? $item['action'])?></summary><div class="grid"><div><h4>Sebelum</h4><div class="history-snapshot"><?=sh_history_summary($item['before_payload'])?></div></div><div><h4>Sesudah</h4><div class="history-snapshot"><?=sh_history_summary($item['after_payload'])?></div></div></div></details><?php endforeach; ?>
</section>
    </div>
</main>
</div>
<div class="modal-overlay" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
    <div class="modal-box">
        <h3 id="logoutTitle">Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button type="button" id="confirmLogout" class="btn-danger">Ya, Logout</button>
            <button type="button" id="cancelLogout" class="btn-cancel">Batal</button>
        </div>
    </div>
</div>
<script src="common.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabLinks = document.querySelectorAll('.service-settings .tabs a[href^="#"]');
    function updateActiveTab() {
        const hash = window.location.hash || '#cetak-kia';
        tabLinks.forEach(link => {
            const isMatch = link.getAttribute('href') === hash;
            link.classList.toggle('active', isMatch);
            link.setAttribute('aria-selected', isMatch ? 'true' : 'false');
        });
    }
    tabLinks.forEach(link => {
        link.addEventListener('click', function () {
            tabLinks.forEach(l => {
                l.classList.remove('active');
                l.setAttribute('aria-selected', 'false');
            });
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
        });
    });
    window.addEventListener('hashchange', updateActiveTab);
    updateActiveTab();
});
</script>
</body></html>