<?php
require_once '../error_handler.php';
require_once '../../app/middleware/operator_only.php';
require_once '../../app/config/koneksi.php';

$formulir = [];
$loadError = false;
try {
    $result = $koneksi->query('SELECT nama_formulir, file_formulir FROM tb_formulir ORDER BY nama_formulir ASC');
    if (!$result) {
        $loadError = true;
    } else {
        $formulir = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
} catch (mysqli_sql_exception $e) {
    $loadError = true;
}
function esc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Formulir Kependudukan - Operator</title>
    <link rel="stylesheet" href="css/formulir_user.css?v=20261115-1">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
    <style>
        .main, .file-info { min-width: 0; }
        .file-name { overflow-wrap: anywhere; }
        .file-actions { flex-wrap: wrap; }
        .btn-download, .btn-preview, .menu-toggle { min-height: 44px; min-width: 44px; }
        .left-header .subtitle { font-size: 14px; color: #7f8c8d; margin: 0; }
        .search-box { background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 25px; }
        .search-box label { display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 8px; }
        .search-box input { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; background: #fff; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); overflow: hidden; }
        .card-header { background: #f8f9fa; color: #2c3e50; padding: 18px 25px; font-size: 18px; font-weight: 600; border-bottom: 2px solid #eee; margin: 0; }
        [hidden] { display: none !important; }
        @media (max-width: 768px) {
            .header { padding-top: 80px; }
            .sidebar.open + .main { margin-left: 0; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-11">
</head>
<body>
<div class="layout">
    <aside class="sidebar" id="operatorSidebar">
        <div class="logo">Operator Dukcapil</div>
        <nav aria-label="Menu operator"><ul>
            <li><a href="dashboard_operator.php">Dashboard</a></li>
            <li><a href="proses_pengajuan.php">Proses Pengajuan</a></li>
            <li><a href="lapor_peristiwa_opr.php">Laporan Peristiwa</a></li>
            <li><a href="riwayat_operator.php">Riwayat</a></li>
            <li><a href="dokumen_email_belum_masuk.php">Dokumen Belum Diterima</a></li>
            <li class="active"><a href="formulir_operator.php" aria-current="page">Formulir Kependudukan</a></li>
            <li class="logout-item"><a href="../logout.php">Logout</a></li>
        </ul></nav>
    </aside>
    <button type="button" class="menu-toggle" aria-controls="operatorSidebar" aria-expanded="false" aria-label="Buka menu">☰</button>
    <main class="main">
        <header class="header">
            <div class="left-header">
                <h1 class="title">Formulir Kependudukan - Operator</h1>
                <p class="subtitle">Unduh dan pratinjau formulir resmi administrasi kependudukan.</p>
            </div>
            <div class="user-info">
                <span style="white-space:nowrap;">Hai, <?= esc($_SESSION['nama'] ?? 'Operator') ?></span>
                <?php include __DIR__ . '/notification_widget.php'; ?>
                <a href="../logout.php" class="logout" style="text-decoration:none;display:inline-flex;align-items:center;">Logout</a>
            </div>
        </header>
        <section class="content">
            <div class="search-box">
                <label for="searchInput">Cari formulir</label>
                <input type="search" id="searchInput" placeholder="Ketik nama formulir">
            </div>
            <div class="card">
                <h2 class="card-header">Daftar Formulir Kependudukan</h2>
                <div class="card-body">
                    <?php if ($loadError): ?>
                        <p role="alert">Daftar formulir gagal dimuat. Silakan muat ulang halaman.</p>
                    <?php elseif (!$formulir): ?>
                        <p class="empty-state">Belum ada formulir tersedia.</p>
                    <?php else: ?>
                        <?php foreach ($formulir as $item):
                            $filename = basename(str_replace('\\', '/', $item['file_formulir']));
                            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                            $available = in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'], true)
                                && is_file(__DIR__ . '/../uploads/formulir/' . $filename);
                            $url = '../uploads/formulir/' . rawurlencode($filename);
                        ?>
                            <div class="file-item" data-name="<?= esc($item['nama_formulir']) ?>">
                                <div class="file-info"><h3 class="file-name"><?= esc($item['nama_formulir']) ?></h3></div>
                                <div class="file-actions">
                                    <?php if ($available): ?>
                                        <?php if (in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)): ?>
                                            <a class="btn-preview" href="<?= esc($url) ?>" target="_blank" rel="noopener">Pratinjau</a>
                                        <?php endif; ?>
                                        <a class="btn-download" href="<?= esc($url) ?>" download="<?= esc($filename) ?>">Unduh</a>
                                    <?php else: ?>
                                        <span>File tidak tersedia. Hubungi administrator.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <p id="noResults" class="empty-state" role="status" hidden>Tidak ditemukan formulir yang sesuai.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</div>
<script>
const toggle = document.querySelector('.menu-toggle');
const sidebar = document.getElementById('operatorSidebar');
toggle.addEventListener('click', () => {
    const open = sidebar.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        sidebar.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Buka menu');
    }
});
document.getElementById('searchInput').addEventListener('input', event => {
    const term = event.target.value.trim().toLocaleLowerCase('id');
    const items = document.querySelectorAll('.file-item');
    let visible = 0;
    items.forEach(item => {
        item.hidden = !item.dataset.name.toLocaleLowerCase('id').includes(term);
        if (!item.hidden) visible++;
    });
    const message = document.getElementById('noResults');
    if (message) message.hidden = visible > 0;
});
</script>
</body>
</html>