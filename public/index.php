<?php
require_once 'error_handler.php';
require_once __DIR__ . '/../app/middleware/service_submission.php';
$home_service_state = service_hours_client_state();
$service_event_notice = $home_service_state['notice'];
$show_service_event_popup = $service_event_notice !== null;

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}

$siteBaseUrl = $scheme . '://' . $host . $basePath;
$canonicalUrl = $siteBaseUrl . '/';
$ogImageUrl = $siteBaseUrl . '/assets/prima.webp';
$seoTitle = 'SI PRIMA - Layanan Administrasi Kependudukan Kabupaten Gorontalo';
$seoDescription = 'SI PRIMA adalah layanan administrasi kependudukan online Kabupaten Gorontalo untuk pengajuan KK, akta kelahiran, akta kematian, pindah alamat, dan pindah masuk secara cepat dan terintegrasi.';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:locale" content="id_ID">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:site_name" content="SI PRIMA">
    <meta property="og:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "WebSite",
            "name": "SI PRIMA",
            "url": "<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>",
            "description": "<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>",
            "inLanguage": "id-ID"
        }
    </script>
    <link rel="stylesheet" href="assets/css/index.css?v=20261118-3">
    <link rel="stylesheet" href="assets/css/service-notice.css?v=20260906-2">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261118-13">
    <script src="assets/js/theme.js?v=20261118-1"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <link rel="icon" type="image/png" href="assets/favicon-logo-dukcapil.png">
</head>
<body>

<header class="main-header">
    <div class="header-container">
        <a href="index.php" class="logo">
            <div class="brand-emblems">
                <img src="assets/logo-kabupaten-gorontalo.jpg" alt="Lambang Pemerintah Kabupaten Gorontalo" class="header-emblem">
                <span class="emblem-divider" aria-hidden="true"></span>
                <img src="assets/favicon-logo-dukcapil.png" alt="Logo Dukcapil Kabupaten Gorontalo" class="header-emblem">
            </div>
            <div class="logo-text">
                <span class="logo-icon">SI PRIMA</span>
                <span class="logo-subtitle">Pemerintah Kabupaten Gorontalo · Disdukcapil</span>
            </div>
        </a>
        <nav class="header-nav" aria-label="Navigasi utama">
            <ul class="header-nav-links">
                <li><a href="#inovasi">Inovasi</a></li>
                <li><a href="#alur-layanan">Alur Layanan</a></li>
                <li><a href="#layanan-kami">Layanan</a></li>
                <li><a href="#lokasi-kantor">Lokasi</a></li>
            </ul>
            <div class="header-actions">
                <a href="register.php" class="btn-nav-outline">Daftar Akun</a>
                <a href="login.php" class="btn-nav-primary">Masuk</a>
            </div>
        </nav>
    </div>
</header>

<section class="hero-landing">
    <div class="hero-content">
        <div class="hero-main-grid">
            <div class="hero-copy">
                <span class="hero-eyebrow">Portal Resmi Layanan Adminduk · Kabupaten Gorontalo</span>
                <h1 class="hero-title">Pelayanan Administrasi Kependudukan Online Terintegrasi</h1>
                <p class="hero-description">Portal pelayanan administrasi kependudukan daring resmi Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo. Memudahkan masyarakat dalam pengajuan dokumen kependudukan secara tertib, transparan, dan terintegrasi.</p>

                <div class="hero-buttons">
                    <a href="login.php" class="btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                            <polyline points="10 17 15 12 10 7"></polyline>
                            <line x1="15" y1="12" x2="3" y2="12"></line>
                        </svg>
                        Masuk Sekarang
                    </a>
                    <a href="register.php" class="btn-secondary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="8.5" cy="7" r="4"></circle>
                            <line x1="20" y1="8" x2="20" y2="14"></line>
                            <line x1="23" y1="11" x2="17" y2="11"></line>
                        </svg>
                        Daftar Akun
                    </a>
                    <a href="informasi_whistleblowing.php" class="btn-wbs">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                            <line x1="12" y1="8" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        Informasi Whistleblowing
                    </a>
                </div>
            </div>

            <div class="hero-badge-panel">
                <div class="hero-badge">
                    <img src="assets/prima.webp" alt="Logo SI PRIMA" class="hero-logo">
                </div>
                <p class="hero-badge-caption">
                    SI PRIMA
                    <span>Sistem Informasi Pelayanan Terintegrasi Identitas Masyarakat</span>
                </p>
            </div>
        </div>

        <div class="innovations-container" id="inovasi">
            <div class="innovations-header">
                <h2>Inovasi Layanan SI PRIMA</h2>
                <span class="innovations-hint">Pilih kanal layanan sesuai peran Anda</span>
            </div>
            <div class="innovations-grid">
                <a href="login_add.php">
                    <div class="innovation-item">
                        <div class="innovation-icon">
                            <img src="assets/add.png" alt="ADD - Adminduk Dari Desa" class="innovation-image">
                        </div>
                        <h3>ADD</h3>
                        <p>Adminduk Dari Desa</p>
                    </div>
                </a>
                <a href="login.php">
                    <div class="innovation-item">
                        <div class="innovation-icon">
                            <img src="assets/line.png" alt="LINE - Layanan Identitas Online" class="innovation-image">
                        </div>
                        <h3>LINE</h3>
                        <p>Layanan Identitas Online</p>
                    </div>
                </a>
                <a href="login_ahlak.php">
                    <div class="innovation-item">
                        <div class="innovation-icon">
                            <img src="assets/ahlak.png" alt="AHLAK - Anak Lahir Ada Akta Kelahiran" class="innovation-image">
                        </div>
                        <h3>AHLAK</h3>
                        <p>Anak Lahir Ada Akta Kelahiran</p>
                    </div>
                </a>
                <a href="login_kua.php">
                    <div class="innovation-item">
                        <div class="innovation-icon">
                            <img src="assets/PASUTRI.png" alt="PASUTRI - Pelayanan Administrasi Terintegrasi" class="innovation-image">
                        </div>
                        <h3>PASUTRI</h3>
                        <p>Pelayanan Administrasi Terintegrasi</p>
                    </div>
                </a>
                <a href="login.php">
                    <div class="innovation-item">
                        <div class="innovation-icon">
                            <img src="assets/logo-kabupaten-gorontalo.jpg" alt="SIP PKK - Sistem Informasi Pelaporan Peristiwa Kependudukan Kolaboratif" class="innovation-image">
                        </div>
                        <h3>SIP PKK</h3>
                        <p>Sistem Informasi Pelaporan Peristiwa Kependudukan Kolaboratif</p>
                    </div>
                </a>
                <a href="login_rs.php">
                    <div class="innovation-item">
                        <div class="innovation-icon">
                            <img src="assets/st sehati.png" alt="ST SEHATI - Sistem Terpadu Sinergi Kelahiran dan Kematian Terintegrasi" class="innovation-image">
                        </div>
                        <h3>ST SEHATI</h3>
                        <p>Sistem Terpadu Sinergi Kelahiran dan Kematian Terintegrasi</p>
                    </div>
                </a>
            </div>
        </div>
    </div>
</section>

<?php if ($home_service_state['message'] !== ''): ?>
<div class="service-home-notice-wrap">
    <section id="service-home-notice" class="service-notice" aria-label="Pemberitahuan layanan">
        <h2 class="service-notice-title"><?= htmlspecialchars($home_service_state['title'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars($home_service_state['summary'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><?= htmlspecialchars($home_service_state['resume'], ENT_QUOTES, 'UTF-8') ?></p>
        <details><summary>Lihat detail pengumuman</summary><p><?= htmlspecialchars($home_service_state['detail'], ENT_QUOTES, 'UTF-8') ?></p></details>
        <?php if ($home_service_state['countdown']): ?><p><?= htmlspecialchars($home_service_state['countdown'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </section>
</div>
<?php endif; ?>

<section class="portal-info" aria-labelledby="portal-info-title">
    <div class="portal-info-card">
        <div class="portal-info-icon" aria-hidden="true">
            <img src="assets/favicon-logo-dukcapil.png" alt="" width="36" height="36" style="object-fit: contain;">
        </div>
        <div class="portal-info-copy">
            <h2 id="portal-info-title">Portal Informasi Dukcapil Kabupaten Gorontalo</h2>
            <p>Akses informasi resmi Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo melalui portal dinas.</p>
        </div>
        <div class="portal-info-action">
            <a class="portal-info-link" href="https://dukcapil.gorontalokab.go.id/" target="_blank" rel="noopener noreferrer">
                Kunjungi Portal <span aria-hidden="true">&#8599;</span>
            </a>
        </div>
    </div>
</section>

<section class="visual-flow" id="alur-layanan">
    <div class="container">
        <div class="section-header">
            <h2>Alur Pemakaian Aplikasi SI PRIMA</h2>
            <p class="section-subtitle">Visualisasi langkah-langkah pengajuan layanan administrasi kependudukan secara tertib</p>
        </div>

        <div class="flow-diagram">
            <div class="innovation-row">
                <a href="login_add.php">
                    <div class="innovation-flow-item" data-innovation="ADD">
                        <div class="innovation-flow-icon">
                            <img src="assets/add.png" alt="ADD" class="flow-innovation-image">
                        </div>
                        <h4>ADD</h4>
                        <p>Petugas Desa</p>
                    </div>
                </a>
                <a href="login.php">
                    <div class="innovation-flow-item" data-innovation="LINE">
                        <div class="innovation-flow-icon">
                            <img src="assets/line.png" alt="LINE" class="flow-innovation-image">
                        </div>
                        <h4>LINE</h4>
                        <p>Masyarakat Umum</p>
                    </div>
                </a>
                <a href="login_ahlak.php">
                    <div class="innovation-flow-item" data-innovation="AHLAK">
                        <div class="innovation-flow-icon">
                            <img src="assets/ahlak.png" alt="AHLAK" class="flow-innovation-image">
                        </div>
                        <h4>AHLAK</h4>
                        <p>Petugas Faskes</p>
                    </div>
                </a>
                <a href="login_kua.php">
                    <div class="innovation-flow-item" data-innovation="PASUTRI">
                        <div class="innovation-flow-icon">
                            <img src="assets/PASUTRI.png" alt="PASUTRI" class="flow-innovation-image">
                        </div>
                        <h4>PASUTRI</h4>
                        <p>Petugas KUA</p>
                    </div>
                </a>
                <a href="login.php">
                    <div class="innovation-flow-item" data-innovation="SIP PKK">
                        <div class="innovation-flow-icon">
                            <img src="assets/logo-kabupaten-gorontalo.jpg" alt="SIP PKK" class="flow-innovation-image">
                        </div>
                        <h4>SIP PKK</h4>
                        <p>Petugas Dasawisma</p>
                    </div>
                </a>
                <a href="login_rs.php">
                    <div class="innovation-flow-item" data-innovation="ST SEHATI">
                        <div class="innovation-flow-icon">
                            <img src="assets/st sehati.png" alt="ST SEHATI" class="flow-innovation-image">
                        </div>
                        <h4>ST SEHATI</h4>
                        <p>Petugas Rumah Sakit</p>
                    </div>
                </a>
            </div>

            <div class="flow-arrow" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14m0 0l-7-7m7 7l7-7"/>
                </svg>
            </div>

            <div class="flow-steps-grid">
                <div class="flow-step-card">
                    <div class="flow-step-top">
                        <span class="step-number-circle">1</span>
                        <span class="step-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="8.5" cy="7" r="4"></circle>
                                <line x1="20" y1="8" x2="20" y2="14"></line>
                                <line x1="23" y1="11" x2="17" y2="11"></line>
                            </svg>
                        </span>
                    </div>
                    <h3>Registrasi &amp; Login</h3>
                    <div class="step-details">
                        <p><strong>Petugas Desa:</strong> Klik ADD lalu registrasi menggunakan email kantor desa.</p>
                        <p><strong>Masyarakat:</strong> Klik Masuk Sekarang atau Daftar Akun dengan email aktif.</p>
                        <p><strong>KUA/Faskes:</strong> Masuk menggunakan akun resmi dari Dukcapil Kab. Gorontalo.</p>
                    </div>
                </div>

                <div class="flow-step-card">
                    <div class="flow-step-top">
                        <span class="step-number-circle">2</span>
                        <span class="step-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                    </div>
                    <h3>Update Profil</h3>
                    <div class="step-details">
                        <p><strong>KUA/Faskes:</strong> Wajib memperbarui email &amp; nomor HP.</p>
                        <p>Gunakan email resmi faskes atau KUA kecamatan, lalu simpan perubahan data.</p>
                    </div>
                </div>

                <div class="flow-step-card">
                    <div class="flow-step-top">
                        <span class="step-number-circle">3</span>
                        <span class="step-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                            </svg>
                        </span>
                    </div>
                    <h3>Ajukan Permohonan</h3>
                    <div class="step-details">
                        <p>Pilih layanan yang dibutuhkan, isi formulir dengan lengkap, unggah dokumen pendukung, lalu kirim permohonan.</p>
                    </div>
                </div>

                <div class="flow-step-card">
                    <div class="flow-step-top">
                        <span class="step-number-circle">4</span>
                        <span class="step-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                <path d="M9 12l2 2 4-4"></path>
                            </svg>
                        </span>
                    </div>
                    <h3>Pantau Status</h3>
                    <div class="step-details">
                        <p>Pantau perkembangan berkas pada menu Status Permohonan.</p>
                        <p>Tahap: Pengajuan &rarr; Proses &rarr; Selesai.</p>
                    </div>
                </div>

                <div class="flow-step-card">
                    <div class="flow-step-top">
                        <span class="step-number-circle">5</span>
                        <span class="step-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                            </svg>
                        </span>
                    </div>
                    <h3>Terima Hasil</h3>
                    <div class="step-details">
                        <p>Dokumen dikirim ke email dan dapat diunduh melalui dashboard.</p>
                        <p>KTP / KIA fisik diambil di kantor Dukcapil sesuai pemberitahuan.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flow-legend">
            <h3>Keterangan Alur:</h3>
            <div class="legend-items">
                <div class="legend-item"><span class="legend-badge">A</span><span>Pilih Inovasi Sesuai Peran</span></div>
                <div class="legend-item"><span class="legend-badge">1</span><span>Proses Registrasi &amp; Login</span></div>
                <div class="legend-item"><span class="legend-badge">2</span><span>Update Profil Wajib (KUA/Faskes)</span></div>
                <div class="legend-item"><span class="legend-badge">3</span><span>Pengajuan Permohonan</span></div>
                <div class="legend-item"><span class="legend-badge">4</span><span>Pemantauan Status</span></div>
                <div class="legend-item"><span class="legend-badge">5</span><span>Penerimaan Hasil</span></div>
            </div>
        </div>
    </div>
</section>

<section class="features" id="layanan-kami">
    <div class="container">
        <div class="section-header">
            <h2>Layanan Kami</h2>
            <p class="section-subtitle">Berbagai layanan administrasi kependudukan yang dapat Anda akses secara daring</p>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="16" rx="3" ry="3"></rect>
                        <circle cx="9" cy="10" r="2.5"></circle>
                        <path d="M15 8h4m-4 4h4m-6 4h6"></path>
                    </svg>
                </div>
                <h3>Kartu Identitas Anak (KIA)</h3>
                <p>Penerbitan identitas resmi anak usia 0 hingga kurang dari 17 tahun secara terintegrasi (mendukung satu atau beberapa anak sekaligus).</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <h3>Kartu Keluarga</h3>
                <p>Pengajuan KK baru, perubahan data, atau cetak ulang dengan mudah dan cepat.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="12" y1="18" x2="12" y2="12"></line>
                        <line x1="9" y1="15" x2="15" y2="15"></line>
                    </svg>
                </div>
                <h3>Akta Kelahiran</h3>
                <p>Pembuatan akta kelahiran untuk anak baru lahir dengan proses yang mudah.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                </div>
                <h3>Akta Kematian</h3>
                <p>Pembuatan akta kematian untuk pelaporan penduduk yang telah meninggal.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                </div>
                <h3>Pindah Alamat</h3>
                <p>Pengajuan pindah alamat dengan proses yang mudah dan cepat.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                </div>
                <h3>Pindah Masuk</h3>
                <p>Pengajuan pindah dari luar Kabupaten Gorontalo masuk ke Kabupaten Gorontalo.</p>
            </div>
        </div>
    </div>
</section>

<section class="location-section" id="lokasi-kantor" aria-labelledby="lokasi-kantor-title">
    <div class="container">
        <div class="section-header">
            <h2 id="lokasi-kantor-title">Lokasi Kantor Kami</h2>
            <p class="section-subtitle">Kunjungi kantor Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo untuk layanan tatap muka dan pengambilan dokumen fisik</p>
        </div>

        <div class="location-card">
            <div class="location-map-wrap">
                <div id="office-map" aria-label="Peta lokasi Kantor Disdukcapil Kabupaten Gorontalo di Limboto"></div>
            </div>

            <div class="location-info-panel">
                <div>
                    <span class="location-badge">Kantor Layanan Resmi</span>
                    <h3>Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo</h3>
                    <div class="location-address">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                            <circle cx="12" cy="10" r="3"></circle>
                        </svg>
                        <span>Jl. Ahmad A. Wahab No. 78, Limboto, Kabupaten Gorontalo, Gorontalo, Indonesia.</span>
                    </div>
                    <p class="location-note">Titik lokasi Kantor Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo. Gunakan tombol Petunjuk Arah untuk rute langsung melalui Google Maps.</p>
                </div>

                <div class="location-actions">
                    <a class="btn-directions"
                       href="https://www.google.com/maps/dir/?api=1&amp;destination=0.6212885,122.9762467"
                       target="_blank"
                       rel="noopener noreferrer">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <polygon points="3 11 22 2 13 21 11 13 3 11"></polygon>
                        </svg>
                        Petunjuk Arah
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<footer class="main-footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-brand">
                <div class="footer-brand-head">
                    <img src="assets/favicon-logo-dukcapil.png" alt="Logo Dukcapil Kabupaten Gorontalo" class="footer-emblem">
                    <h3>SI PRIMA</h3>
                </div>
                <p>Sistem Informasi Pelayanan Terintegrasi Identitas Masyarakat — Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo.</p>
            </div>
            <div class="footer-links">
                <h4>Layanan</h4>
                <ul>
                    <li><a href="login.php">Kartu Identitas Anak (KIA)</a></li>
                    <li><a href="login.php">Kartu Keluarga</a></li>
                    <li><a href="login.php">Pindah Alamat</a></li>
                    <li><a href="login.php">Akta Kelahiran</a></li>
                    <li><a href="login.php">Akta Kematian</a></li>
                </ul>
            </div>
            <div class="footer-links">
                <h4>Informasi</h4>
                <ul>
                    <li><a href="https://dukcapil.gorontalokab.go.id/" target="_blank" rel="noopener noreferrer" aria-label="Portal Dukcapil (terbuka di tab baru)">Portal Dukcapil <span aria-hidden="true">&#8599;</span></a></li>
                    <li><a href="https://dukcapil.gorontalokab.go.id/about/">Tentang Kami</a></li>
                    <li><a href="https://dukcapil.gorontalokab.go.id/contact/">Kontak</a></li>
                    <li><a href="https://dukcapil.gorontalokab.go.id/faq/">FAQ</a></li>
                    <li><a href="https://api.whatsapp.com/send?phone=6281243425557&amp;text=Sampaikan%20aduan%20anda,%20NAMA:%20%20,%20NIK:%20%20,%20PERMASALAHAN:">Bantuan</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2023 SI PRIMA - Dinas Kependudukan &amp; Pencatatan Sipil Kabupaten Gorontalo. All rights reserved.</p>
        </div>
    </div>
</footer>

<?php if ($show_service_event_popup && $service_event_notice['type'] === 'closure'): ?>
<dialog id="service-announcement-dialog" class="service-announcement-dialog" aria-labelledby="serviceEventTitle" aria-describedby="serviceEventDescription">
    <button type="button" class="service-dialog-close" data-notice-close aria-label="Tutup pengumuman">&times;</button>
    <span class="service-dialog-label">Pengumuman layanan</span>
    <h2 id="serviceEventTitle"><?= htmlspecialchars($service_event_notice['title'], ENT_QUOTES, 'UTF-8') ?></h2>
    <p id="serviceEventDescription"><?= htmlspecialchars($service_event_notice['description'], ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong><?= htmlspecialchars($service_event_notice['summary'], ENT_QUOTES, 'UTF-8') ?></strong></p>
    <p><?= htmlspecialchars($service_event_notice['resume'], ENT_QUOTES, 'UTF-8') ?></p>
    <p>Login, melihat status, dan unduh dokumen tetap tersedia.</p>
    <button type="button" class="service-dialog-confirm" data-notice-close autofocus>Saya Mengerti</button>
</dialog>
<?php endif; ?>
<?php if ($home_service_state['message'] !== ''): ?>
<script type="application/json" id="service-home-notice-state"><?= json_encode($home_service_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/js/service-notice.js?v=20260906-1"></script>
<?php endif; ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var mapEl = document.getElementById('office-map');
    if (!mapEl || typeof L === 'undefined') {
        return;
    }
    var limbotoCoords = [0.6212885, 122.9762467];
    var map = L.map('office-map', {
        scrollWheelZoom: false
    }).setView(limbotoCoords, 16);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var marker = L.marker(limbotoCoords)
        .addTo(map)
        .bindPopup('<strong>Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo</strong><br>Jl. Ahmad A. Wahab No. 78, Limboto, Kabupaten Gorontalo', {
            maxWidth: window.innerWidth <= 640 ? 200 : 280
        });
    if (window.innerWidth > 640) {
        marker.openPopup();
    }
});
</script>

</body>
</html>
