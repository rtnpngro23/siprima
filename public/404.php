<?php
// Halaman error publik: dipakai rewrite .htaccess untuk URL yang tidak ada dan di-include showErrorPage().
$status_code = isset($status_code) ? (int)$status_code : 404;
if (!headers_sent()) {
    http_response_code($status_code);
}
$is_not_found = $status_code === 404;
$error_title = $is_not_found ? 'Halaman Tidak Ditemukan' : 'Layanan Sedang Terganggu';
$error_text = $is_not_found
    ? 'Halaman yang Anda cari tidak ada, sudah dipindahkan, atau alamatnya salah ketik.'
    : 'Terjadi gangguan sementara pada layanan. Silakan coba beberapa saat lagi.';

// URL tetap absolut karena URL asli di browser bisa berada di subfolder mana pun.
$home_url = preg_match('#^(.*/public)(?:/|$)#', $_SERVER['SCRIPT_NAME'] ?? '', $matches) ? $matches[1] . '/index.php' : '/index.php';
$asset_base = rtrim(dirname($home_url), '/\\') . '/assets/';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $status_code ?> - <?= htmlspecialchars($error_title, ENT_QUOTES, 'UTF-8') ?> | SI PRIMA</title>
    <link rel="icon" href="<?= htmlspecialchars($asset_base . 'favicon-logo-dukcapil.png', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($asset_base . 'css/theme.css?v=20261118-3', ENT_QUOTES, 'UTF-8') ?>">
    <script src="<?= htmlspecialchars($asset_base . 'js/theme.js?v=20261118-1', ENT_QUOTES, 'UTF-8') ?>"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--sp-bg, #e9f0ee);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            color: var(--sp-ink, #0f172a);
        }
        .error-card {
            background: var(--sp-surface, #f2f6f5);
            border: 1px solid var(--sp-border, #c6d8d4);
            border-top: 4px solid var(--sp-primary, #0b6e75);
            border-radius: 14px;
            box-shadow: var(--sp-shadow-md, 0 6px 20px rgba(11, 110, 117, 0.08));
            padding: 40px 32px;
            max-width: 480px;
            width: 100%;
            text-align: center;
        }
        .error-logo { height: 64px; margin: 0 auto 16px; display: block; object-fit: contain; }
        .error-code { font-size: 68px; font-weight: 800; line-height: 1; color: var(--sp-primary, #0b6e75); }
        .error-title { font-size: 22px; margin: 12px 0 8px; color: var(--sp-ink, #0f172a); }
        .error-text { color: var(--sp-ink-muted, #475569); line-height: 1.6; margin-bottom: 28px; }
        .error-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 11px 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid var(--sp-primary, #0b6e75);
            font-family: inherit;
        }
        .btn-primary { background: var(--sp-primary, #0b6e75); color: #f9fcfb; }
        .btn-primary:hover { background: var(--sp-primary-hover, #08545a); border-color: var(--sp-primary-hover, #08545a); }
        .btn-secondary { background: var(--sp-surface-white, #f9fcfb); color: var(--sp-ink, #0f172a); border-color: var(--sp-border-strong, #a9c2bd); }
        .btn-secondary:hover { background: var(--sp-surface-card, #dceae7); }
        @media (max-width: 480px) {
            .error-card { padding: 32px 20px; }
            .error-code { font-size: 56px; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <main class="error-card">
        <img src="<?= htmlspecialchars($asset_base . 'prima.webp', ENT_QUOTES, 'UTF-8') ?>" alt="SI PRIMA" class="error-logo" onerror="this.style.display='none'">
        <div class="error-code"><?= $status_code ?></div>
        <h1 class="error-title"><?= htmlspecialchars($error_title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="error-text"><?= htmlspecialchars($error_text, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="error-actions">
            <button type="button" class="btn btn-secondary" id="btnBack">&larr; Halaman Sebelumnya</button>
            <a href="<?= htmlspecialchars($home_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary" id="btnHome">Halaman Utama</a>
        </div>
    </main>
    <script>
        document.getElementById('btnBack').addEventListener('click', function () {
            // Tanpa riwayat dari situs ini (mis. link dibuka langsung), arahkan ke halaman utama.
            var fromThisSite = document.referrer.indexOf(location.origin + '/') === 0;
            if (fromThisSite && history.length > 1) {
                history.back();
            } else {
                location.href = document.getElementById('btnHome').href;
            }
        });
    </script>
</body>
</html>
