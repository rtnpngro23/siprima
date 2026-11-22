<?php
require_once '../error_handler.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../app/middleware/admin_only.php';
require_once __DIR__ . '/../../app/config/document_features.php';
function df_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function df_history_summary(string $payload): string {
    $settings = json_decode($payload, true);
    if (!is_array($settings)) return '<p>Riwayat tidak dapat dibaca.</p>';
    $html = '';
    foreach (document_features_catalog() as $key => $meta) {
        $html .= '<p><strong>' . df_escape($meta['label']) . ':</strong> '
            . (!empty($settings[$key]) ? 'Aktif' : 'Nonaktif') . '</p>';
    }
    return $html;
}
$error = '';
$db = null;
$row = null;
$catalog = document_features_catalog();
$settings = document_features_defaults();
$audit = [];
$loaded = false;
try {
    $db = document_features_db();
    $result = $db->query('SELECT payload, revision FROM tb_document_features WHERE id=1');
    if (!$result || !($row = $result->fetch_assoc())) throw new RuntimeException('Pengaturan belum tersedia. Jalankan migrasi sql/20260923_document_features.sql.');
    $settings = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
    document_features_validate($settings);
    $loaded = true;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!csrf_validate()) throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman.');
        if ((string)($_POST['action'] ?? '') !== 'features') throw new RuntimeException('Aksi tidak valid.');
        $selected = array_filter((array)($_POST['features'] ?? []), 'is_string');
        if (count($selected) !== count((array)($_POST['features'] ?? []))
            || array_diff($selected, array_keys($catalog))) throw new RuntimeException('Fitur tidak dikenal.');
        $next = [];
        foreach (array_keys($catalog) as $key) $next[$key] = in_array($key, $selected, true);
        document_features_validate($next);
        if (!$db->begin_transaction()) throw new RuntimeException('Tidak dapat memulai penyimpanan.');
        try {
            $lockedResult = $db->query('SELECT payload, revision FROM tb_document_features WHERE id=1 FOR UPDATE');
            if (!$lockedResult || !($locked = $lockedResult->fetch_assoc())) throw new RuntimeException('Pengaturan tidak dapat dibaca.');
            if ((int)$locked['revision'] !== (int)($_POST['revision'] ?? 0)) {
                throw new RuntimeException('Pengaturan telah diubah admin lain. Muat ulang halaman sebelum menyimpan.');
            }
            $payload = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $stmt = $db->prepare('UPDATE tb_document_features SET payload=?, revision=revision+1 WHERE id=1');
            if (!$stmt) throw new RuntimeException('Penyimpanan gagal.');
            $stmt->bind_param('s', $payload);
            if (!$stmt->execute()) throw new RuntimeException('Penyimpanan gagal.');
            $stmt->close();
            $adminId = (int)$_SESSION['id_user'];
            $at = (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i:s');
            $stmt = $db->prepare('INSERT INTO tb_document_features_audit (admin_id,changed_at,action,before_payload,after_payload) VALUES (?,?,?,?,?)');
            if (!$stmt) throw new RuntimeException('Riwayat tidak dapat disimpan.');
            $action = 'features';
            $before = $locked['payload'];
            $stmt->bind_param('issss', $adminId, $at, $action, $before, $payload);
            if (!$stmt->execute()) throw new RuntimeException('Riwayat tidak dapat disimpan.');
            $stmt->close();
            if (!$db->commit()) throw new RuntimeException('Penyimpanan gagal.');
            $_SESSION['document_features_saved'] = true;
            header('Location: manajemen_formulir_digital.php');
            exit;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
    $history = $db->query('SELECT id,admin_id,changed_at,action,before_payload,after_payload FROM tb_document_features_audit ORDER BY id DESC LIMIT 30');
    if ($history) $audit = $history->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    $error = $e instanceof mysqli_sql_exception || $e instanceof JsonException ? 'Database pengaturan tidak dapat diproses. Hubungi pengelola aplikasi.' : $e->getMessage();
}
if (!$loaded) { $settings = document_features_defaults(); $row = null; }
$saved = !empty($_SESSION['document_features_saved']);
unset($_SESSION['document_features_saved']);
$activeCount = count(array_filter(array_intersect_key($settings, $catalog)));
$revision = (int)($row['revision'] ?? 0);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manajemen Formulir Digital - Admin SI PRIMA</title>
<link rel="icon" href="../assets/prima.webp">
<link rel="stylesheet" href="common.css">
<link rel="stylesheet" href="css/pengaturan_layanan.css?v=20260907-1">
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
                <li class="active"><a href="manajemen_formulir_digital.php" aria-current="page">Manajemen Formulir Digital</a></li>
                <li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
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
            <h1 class="title">Manajemen Formulir Digital</h1>
            <p class="settings-subtitle">Aktifkan atau nonaktifkan formulir otomatis dan dokumen hasil</p>
        </div>
        <div class="user-info">
            <span>Hai, <?= df_escape($_SESSION['nama'] ?? 'Admin') ?></span>
            <button type="button" class="logout" id="logoutBtn2">Logout</button>
        </div>
    </header>
    <div class="content service-settings">
<?php if ($saved): ?><p class="notice success" role="status">Pengaturan berhasil disimpan. Perubahan berlaku pada halaman yang dibuka berikutnya.</p><?php endif; ?>
<?php if ($error): ?><p class="notice error" role="alert"><?=df_escape($error)?></p><?php endif; ?>
<section class="panel">
<h2>Ringkasan <span class="badge <?=$activeCount ? 'open' : 'closed'?>"><?=$activeCount?> dari <?=count($catalog)?> aktif</span></h2>
<p>Halaman ini mengatur fitur yang sebelumnya hanya dapat diubah melalui berkas konfigurasi di server. Perubahan langsung berlaku tanpa perlu menyentuh kode.</p>
<p class="muted">Pengaturan tidak mengubah permohonan yang sudah masuk. Permohonan yang terlanjur dibuat dengan formulir otomatis tetap menyimpan berkas hasilnya.</p>
<?php if (!$loaded): ?><p class="muted">Nilai yang ditampilkan adalah nilai cadangan bawaan karena pengaturan belum dapat dibaca. Penyimpanan dinonaktifkan sampai migrasi dijalankan.</p><?php endif; ?>
</section>
<section class="panel">
<h2>Fitur formulir digital</h2>
<form method="post">
<?=csrf_input()?><input type="hidden" name="revision" value="<?=$revision?>"><input type="hidden" name="action" value="features">
<div class="table-wrap"><table>
<thead><tr><th>Fitur</th><th>Cakupan</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($catalog as $key => $meta): ?>
<tr>
    <td>
        <strong><?=df_escape($meta['label'])?></strong>
        <p class="muted"><?=df_escape($meta['description'])?></p>
        <p class="muted">Perhatian: <?=df_escape($meta['caution'])?></p>
    </td>
    <td><?=df_escape($meta['scope'])?></td>
    <td>
        <label class="check">
            <input type="checkbox" name="features[]" value="<?=df_escape($key)?>" <?=!empty($settings[$key]) ? 'checked' : ''?>>
            Aktifkan
        </label>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<div class="actions"><button <?=$row ? '' : 'disabled'?>>Simpan pengaturan</button></div>
</form>
</section>
<section class="panel"><h2>Riwayat perubahan</h2><p class="muted">30 perubahan terakhir. Riwayat lengkap tetap tersimpan.</p>
<?php if (!$audit): ?><p>Belum ada perubahan.</p><?php endif; ?>
<?php foreach ($audit as $item): ?><details><summary><?=df_escape($item['changed_at'])?> WITA • Admin #<?=(int)$item['admin_id']?></summary><div class="grid"><div><h4>Sebelum</h4><div class="history-snapshot"><?=df_history_summary($item['before_payload'])?></div></div><div><h4>Sesudah</h4><div class="history-snapshot"><?=df_history_summary($item['after_payload'])?></div></div></div></details><?php endforeach; ?>
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
</body>
</html>
