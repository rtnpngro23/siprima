<?php
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/email_delivery.php';

// PERBAIKAN: Gunakan 'id_user' bukan 'user_id'
$user_id = $_SESSION['id_user'] ?? 0; // Tambahkan null coalescing untuk safety

// Validasi jika user_id tidak ditemukan
if (!$user_id) {
    // Redirect ke login jika tidak ada user_id
    header('Location: ../login.php');
    exit;
}

$email_delivery_pending_count = email_delivery_pending_confirmation_count($koneksi, $user_id);

// Hitung status permohonan - TAMBAHKAN SEMUA TABEL
$total = $menunggu = $diproses = $selesai = $ditolak = 0;
$tablesToCheck = [
    'tb_kartukeluarga', 
    'tb_akta_kelahiran', 
    'tb_akta_kematian',
    'tb_laporan_peristiwa',
    'tb_pindah_alamat',      // TAMBAHKAN TABEL INI
    'tb_pindah_online',      // TAMBAHKAN TABEL INI
    'tb_pengajuan_kia'       // TAMBAHKAN TABEL KIA
];


foreach ($tablesToCheck as $tbl) {
    $check = $koneksi->query("SHOW TABLES LIKE '" . $tbl . "'");
    if ($check && $check->num_rows > 0) {
        $s = $koneksi->prepare("SELECT COUNT(*) AS total, 
            COALESCE(SUM(status = 'diajukan'),0) AS menunggu, 
            COALESCE(SUM(status = 'diproses'),0) AS diproses, 
            COALESCE(SUM(status = 'selesai'),0) AS selesai,
            COALESCE(SUM(status = 'ditolak'),0) AS ditolak 
            FROM `" . $tbl . "` WHERE id_user = ?");
        if ($s) {
            $s->bind_param('i', $user_id);
            $s->execute();
            $r = $s->get_result()->fetch_assoc();
            $total    += (int)($r['total'] ?? 0);
            $menunggu += (int)($r['menunggu'] ?? 0);
            $diproses += (int)($r['diproses'] ?? 0);
            $selesai  += (int)($r['selesai'] ?? 0);
            $ditolak  += (int)($r['ditolak'] ?? 0);
            $s->close();
        }
    }
    if (isset($check)) $check->close();
}



foreach ($tablesToCheck as $tbl) {
    $check = $koneksi->query("SHOW TABLES LIKE '" . $tbl . "'");
    if ($check && $check->num_rows > 0) {
        $s = $koneksi->prepare("SELECT COUNT(*) as total FROM `" . $tbl . "` WHERE id_user = ?");
        if ($s) {
            $s->bind_param('i', $user_id);
            $s->execute();
            $r = $s->get_result()->fetch_assoc();
            $jenis = str_replace(['tb_', 'akta_'], '', $tbl);
            $jenis = str_replace('_alamat', '', $jenis);
            $jenis = str_replace('kartu', 'kk', $jenis);
            
            if (isset($jenisStats[$jenis])) {
                $jenisStats[$jenis]['total'] = (int)($r['total'] ?? 0);
            }
            $s->close();
        }
    }
    if (isset($check)) $check->close();
}

$kiaDocsList = [];
$checkKia = $koneksi->query("SHOW TABLES LIKE 'tb_pengajuan_kia'");
if ($checkKia && $checkKia->num_rows > 0) {
    $stmtKiaDocs = $koneksi->prepare(
        "SELECT p.id, p.no_kk, p.status, p.dokumen_kia_pdf, p.alasan_penolakan, p.created_at, p.updated_at,
                COALESCE((SELECT GROUP_CONCAT(a.nama_anak SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = p.id), 'Data Anak') AS nama_anak,
                (SELECT ck.status_cetak FROM tb_cetak_kia ck WHERE ck.id_pengajuan = p.id ORDER BY ck.id DESC LIMIT 1) AS status_cetak
         FROM tb_pengajuan_kia p
         WHERE p.id_user = ?
         ORDER BY p.id DESC
         LIMIT 10"
    );
    if ($stmtKiaDocs) {
        $stmtKiaDocs->bind_param('i', $user_id);
        $stmtKiaDocs->execute();
        $resKiaDocs = $stmtKiaDocs->get_result();
        while ($kd = $resKiaDocs->fetch_assoc()) {
            $kiaDocsList[] = $kd;
        }
        $stmtKiaDocs->close();
    }
    $checkKia->close();
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard LINE - Pelayanan Layanan Identitas Online (LINE)</title>
    <link rel="stylesheet" href="css/dashboard_user.css?v=20261004-1">
    <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
    <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="siprima-user-page">

<div class="layout">
    <!-- Sidebar SAMA dengan ajukan_permohonan.php -->
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <main class="main">
        <header class="header user-general-header">
            <?php
            require_once __DIR__ . '/user_header_brand.php';
            render_user_header_brand('Dashboard LINE');
            ?>
            <div class="user-info">
                <span>Hai, <?= htmlspecialchars($_SESSION['nama']) ?></span>
                <?php include __DIR__ . '/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <?php
            $email_delivery_status_url = 'status_permohonan.php';
            $email_delivery_audience = 'user';
            include __DIR__ . '/email_delivery_dashboard_alert.php';
            ?>
                        <!-- Welcome Message -->
            <div class="welcome-box">
                <h1>Selamat Datang <?= htmlspecialchars($_SESSION['nama']) ?></h1>
                <p>Selamat Datang di LINE (Layanan Identitas Online)</p>
            </div>

            <!-- Peringatan hukum: perlindungan data pribadi & pemalsuan dokumen -->
            <div class="notice-row">
                <div class="privacy-warning" role="note" aria-labelledby="privacyWarningTitle">
                    <div class="privacy-warning-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    </div>
                    <div class="privacy-warning-content">
                        <h2 id="privacyWarningTitle">Peringatan Perlindungan Data Pribadi</h2>
                        <p>
                            Sesuai Undang-Undang Nomor 27 Tahun 2022 tentang Pelindungan Data Pribadi,
                            harap menjaga kerahasiaan data dan dokumen pribadi. Jangan menyebarluaskan
                            NIK, nomor KK, akta, atau dokumen kependudukan kepada pihak yang tidak berwenang.
                        </p>
                    </div>
                </div>

                <div class="fraud-warning" role="note" aria-labelledby="fraudWarningTitle">
                    <div class="fraud-warning-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                    </div>
                    <div class="fraud-warning-content">
                        <h2 id="fraudWarningTitle">Peringatan Pemalsuan Data dan Dokumen</h2>
                        <p>
                            Memalsukan dokumen atau memberikan keterangan yang tidak benar dalam permohonan
                            dokumen kependudukan adalah tindak pidana, diatur dalam Pasal 93 Undang-Undang
                            Administrasi Kependudukan serta Pasal 263 dan 266 KUHP tentang pemalsuan surat.
                            Jangan memakai dokumen milik orang lain. Permohonan dengan data palsu akan
                            ditolak dan dapat diteruskan kepada aparat penegak hukum.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Panduan Alur Pengajuan -->
            <section class="guide-card" aria-labelledby="guideCardTitle">
                <div class="guide-card-content">
                    <span class="guide-card-eyebrow">Panduan Layanan</span>
                    <h2 id="guideCardTitle">Alur Pengajuan dan Penerimaan Dokumen</h2>
                    <p>
                        Pelajari tahapan pengajuan, verifikasi, perbaikan berkas, hingga dokumen
                        diterima sebelum membuat permohonan baru.
                    </p>
                </div>

                <button
                    type="button"
                    class="guide-thumbnail-button"
                    data-guide-open
                    aria-label="Buka gambar alur pengajuan dan penerimaan dokumen"
                    aria-haspopup="dialog"
                    aria-controls="guideModal"
                >
                    <img
                        src="/assets/alur-pengajuan-siprima.png"
                        alt="Diagram alur pengajuan dan penerimaan dokumen SIPRIMA"
                        width="800"
                        height="450"
                        loading="lazy"
                    >
                </button>
            </section>

            <!-- Cards Statistik -->
            <h2 style="font-size: 1.4rem; font-weight: 700; margin-bottom: 18px;">
                Ringkasan Permohonan
            </h2>
            <div class="cards">
                <div class="card">
                    <h3>Total Permohonan</h3>
                    <p><?= $total ?></p>
                </div>
                <div class="card">
                    <h3>Menunggu Verifikasi</h3>
                    <p><?= $menunggu ?></p>
                </div>
                <div class="card">
                    <h3>Sedang Diproses</h3>
                    <p><?= $diproses ?></p>
                </div>
                <div class="card">
                    <h3>Selesai</h3>
                    <p><?= $selesai ?></p>
                </div>
                <div class="card card-ditolak">
                    <h3>Ditolak</h3>
                    <p><?= $ditolak ?></p>
                </div>
            </div>

            <!-- Dokumen Hasil Layanan & Unduh KIA -->
            <div id="section-dokumen-hasil" style="margin-top: 36px; margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                    <div>
                        <h2 style="font-size: 1.35rem; font-weight: 700; margin: 0 0 4px 0;">
                            Dokumen Hasil Layanan (Siap Unduh)
                        </h2>
                        <p style="margin: 0; font-size: 14px;">
                            Dokumen resmi hasil pemrosesan pelayanan yang dapat dibuka atau diunduh secara mandiri oleh pemohon.
                        </p>
                    </div>
                    <a href="status_permohonan.php" class="btn-primary" style="font-size: 13px; padding: 8px 14px; text-decoration: none;">
                        Lihat Semua Status &amp; Riwayat &rarr;
                    </a>
                </div>

                <?php if (empty($kiaDocsList)): ?>
                    <div class="kia-empty-box" style="border: 1px dashed #94a3b8; border-radius: 12px; padding: 28px; text-align: center;">
                        <strong style="display: block; font-size: 15px; margin-bottom: 6px;">Belum Ada Dokumen KIA</strong>
                        <p style="margin: 0 0 16px 0; font-size: 14px;">Anda belum memiliki permohonan KIA atau dokumen hasil belum diterbitkan.</p>
                        <a href="kia.php" class="btn-success" style="font-size: 14px; padding: 10px 18px; text-decoration: none; border-radius: 8px;">Ajukan Permohonan KIA</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow-x: auto; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <table style="width: 100%; min-width: 650px; border-collapse: collapse; text-align: left; font-size: 13.5px;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600;">
                                    <th style="padding: 12px 16px;">No. Permohonan</th>
                                    <th style="padding: 12px 16px;">Layanan / Subjek</th>
                                    <th style="padding: 12px 16px;">Tanggal Pengajuan</th>
                                    <th style="padding: 12px 16px;">Status Layanan</th>
                                    <th style="padding: 12px 16px; text-align: center;">Dokumen Hasil</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kiaDocsList as $docItem): 
                                    $st = strtolower((string)$docItem['status']);
                                    $pdfItems = [];
                                    if ($st === 'selesai') {
                                        $stmtPdfRows = $koneksi->prepare("SELECT d.id, d.original_name, a.nama_anak FROM tb_dokumen_kia d LEFT JOIN tb_anak_kia a ON d.id_anak = a.id WHERE d.id_pengajuan = ? AND d.jenis_dokumen = 'hasil_kia_pdf' AND d.status_dokumen = 'valid' ORDER BY COALESCE(a.urutan_anak, 999) ASC, d.id ASC");
                                        if ($stmtPdfRows) {
                                            $pidItem = (int)$docItem['id'];
                                            $stmtPdfRows->bind_param('i', $pidItem);
                                            $stmtPdfRows->execute();
                                            $pdfItems = $stmtPdfRows->get_result()->fetch_all(MYSQLI_ASSOC);
                                            $stmtPdfRows->close();
                                        }
                                    }
                                    $hasPdf = ($st === 'selesai' && (!empty($pdfItems) || !empty($docItem['dokumen_kia_pdf'])));
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 14px 16px; font-weight: 600; color: #1e293b;">
                                        #<?= (int)$docItem['id'] ?>
                                        <div style="font-size: 11.5px; font-weight: normal; color: #64748b;">No. KK: <?= htmlspecialchars($docItem['no_kk']) ?></div>
                                    </td>
                                    <td style="padding: 14px 16px;">
                                        <strong style="color: #0f172a; display: block;">Kartu Identitas Anak (KIA)</strong>
                                        <span style="color: #64748b; font-size: 12.5px;"><?= htmlspecialchars($docItem['nama_anak']) ?></span>
                                    </td>
                                    <td style="padding: 14px 16px; color: #475569;">
                                        <?= date('d M Y, H:i', strtotime($docItem['created_at'])) ?>
                                    </td>
                                    <td style="padding: 14px 16px;">
                                        <?php if ($st === 'selesai'): ?>
                                            <span style="display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; background: #dcfce7; color: #15803d;">Selesai</span>
                                        <?php elseif ($st === 'diproses'): ?>
                                            <span style="display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; background: #dbeafe; color: #1d4ed8;">Sedang Diproses</span>
                                        <?php elseif ($st === 'ditolak'): ?>
                                            <span style="display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; background: #fee2e2; color: #b91c1c;">Ditolak (Perlu Revisi)</span>
                                        <?php else: ?>
                                            <span style="display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; background: #f1f5f9; color: #475569;">Menunggu Verifikasi</span>
                                        <?php endif; ?>

                                        <?php if (!empty($docItem['status_cetak'])): ?>
                                            <div style="margin-top: 4px; font-size: 11.5px; color: #0369a1;">
                                                🪪 Cetak Fisik: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $docItem['status_cetak']))) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 14px 16px; text-align: center;">
                                        <?php if ($hasPdf): ?>
                                            <?php if (!empty($pdfItems)): ?>
                                                <div style="display:flex;flex-direction:column;gap:8px;align-items:center;">
                                                    <?php foreach ($pdfItems as $pIdx => $pItem):
                                                        $anakTitle = trim((string)($pItem['nama_anak'] ?? '')) !== '' ? $pItem['nama_anak'] : ('Anak ' . ($pIdx + 1));
                                                        $vUrl = 'kia.php?action=view_dokumen&id_dokumen=' . (int)$pItem['id'] . '&ext=.pdf';
                                                        $dUrl = 'kia.php?action=view_dokumen&id_dokumen=' . (int)$pItem['id'] . '&download=1&ext=.pdf';
                                                    ?>
                                                        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px;">
                                                            <?php if (count($pdfItems) > 1): ?>
                                                                <span style="font-size:11.5px;font-weight:700;color:#0f172a;"><?= htmlspecialchars($anakTitle) ?>:</span>
                                                            <?php endif; ?>
                                                            <a href="<?= htmlspecialchars($vUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn-buka-kia-pdf" style="display:inline-flex;align-items:center;gap:5px;padding:6px 10px;background:#0284c7;color:#ffffff;text-decoration:none;border-radius:7px;font-weight:600;font-size:12px;">
                                                                📄 Buka
                                                            </a>
                                                            <a href="<?= htmlspecialchars($dUrl) ?>" download="<?= htmlspecialchars($pItem['original_name'] ?: ('Hasil_KIA_' . $anakTitle . '.pdf')) ?>" class="btn-unduh-kia-pdf" style="display:inline-flex;align-items:center;gap:5px;padding:6px 10px;background:#16a34a;color:#ffffff;text-decoration:none;border-radius:7px;font-weight:600;font-size:12px;">
                                                                ⬇️ Unduh
                                                            </a>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="display: inline-flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 6px;">
                                                    <a href="kia.php?action=download_pdf&amp;id=<?= (int)$docItem['id'] ?>&amp;inline=1"
                                                       target="_blank"
                                                       rel="noopener noreferrer"
                                                       class="btn-buka-kia-pdf"
                                                       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #0284c7; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 12.5px; transition: background 0.2s;"
                                                       title="Buka berkas PDF resmi KIA di browser">
                                                        📄 Buka PDF
                                                    </a>
                                                    <a href="kia.php?action=download_pdf&amp;id=<?= (int)$docItem['id'] ?>&amp;download=1" 
                                                       download="Hasil_KIA_<?= (int)$docItem['id'] ?>.pdf"
                                                       class="btn-unduh-kia-pdf"
                                                       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #16a34a; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 12.5px; transition: background 0.2s;"
                                                       title="Unduh berkas PDF resmi KIA">
                                                        ⬇️ Unduh PDF
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($st === 'selesai'): ?>
                                            <span style="font-size: 12px; color: #d97706; display: inline-flex; align-items: center; gap: 4px;">
                                                ⏳ Dokumen PDF sedang dipersiapkan
                                            </span>
                                        <?php elseif ($st === 'ditolak'): ?>
                                            <a href="status_permohonan.php" style="font-size: 12px; color: #dc2626; text-decoration: underline; font-weight: 600;">
                                                Perbaiki Berkas
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: #94a3b8;">
                                                Belum dapat diunduh (Menunggu verifikasi)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <h2 style="font-size: 1.5rem; font-weight: 700; color: #2c3e50; margin-bottom: 20px; margin-top: 40px;">
                ⚡ Aksi Cepat
            </h2>
            <div class="quick-actions">
                <div class="action-card">
                    <h3>Ajukan Permohonan Baru</h3>
                    <p>Ajukan permohonan baru untuk Kartu Keluarga, Akta Kelahiran, Akta Kematian, Pindah Alamat, atau Laporan Peristiwa.</p>
                    <a href="ajukan_permohonan.php" class="btn-success">Ajukan Sekarang</a>
                </div>
                <div class="action-card">
                    <h3>Cek Status Permohonan</h3>
                    <p>Pantau perkembangan semua permohonan yang sedang Anda ajukan.</p>
                    <a href="status_permohonan.php" class="btn-primary">Lihat Status</a>
                </div>
                <div class="action-card">
                    <h3>Unduh Dokumen Hasil</h3>
                    <p>Unduh dokumen PDF resmi hasil pelayanan KIA dan administrasi kependudukan yang telah selesai diproses.</p>
                    <a href="#section-dokumen-hasil" class="btn-primary" style="background:#16a34a;border-color:#16a34a;">Unduh Dokumen Hasil</a>
                </div>
                <div class="action-card">
                    <h3>Formulir Kependudukan</h3>
                    <p>Download formulir yang diperlukan untuk pengurusan administrasi kependudukan.</p>
                    <a href="formulir_user.php" class="btn-primary">Lihat Formulir</a>
                </div>
                <div class="action-card">
                    <h3>Whistleblowing</h3>
                    <p>Sampaikan dugaan pelanggaran pelayanan secara rahasia dan pantau tindak lanjut admin.</p>
                    <a href="whistleblowing.php" class="btn-primary">Buat atau Pantau Aduan</a>
                </div>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <p>Butuh bantuan? Hubungi kami di <strong>081243425557</strong> atau email <strong>dkpskabgor@gmail.com</strong>.
                <br><small>Jam operasional: Senin-Jumat 08:00-16:00 WIB</small></p>
            </div>
        </section>
    </main>
</div>

<?php include __DIR__ . '/mobile_bottom_nav.php'; ?>

<!-- Modal Preview Panduan -->
<div
    class="guide-modal-overlay"
    id="guideModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="guideModalTitle"
    aria-hidden="true"
>
    <div class="guide-modal-box" role="document">
        <div class="guide-modal-header">
            <div>
                <span class="guide-modal-eyebrow">Panduan Layanan</span>
                <h2 id="guideModalTitle">Alur Pengajuan dan Penerimaan Dokumen</h2>
            </div>
            <button type="button" class="guide-close-button" id="guideCloseButton" aria-label="Tutup preview panduan">&times;</button>
        </div>

        <div class="guide-image-stage" id="guideImageStage">
            <img
                id="guidePreviewImage"
                src="/assets/alur-pengajuan-siprima.png"
                alt="Diagram lengkap alur pengajuan dan penerimaan dokumen SIPRIMA"
                width="800"
                height="450"
            >
        </div>

        <div class="guide-modal-footer">
            <p>Gambar disesuaikan otomatis dengan ukuran layar perangkat Anda.</p>
            <a href="ajukan_permohonan.php" class="guide-submit-link">Ajukan Permohonan</a>
        </div>
    </div>
</div>

<!-- Modal Logout - SAMA dengan ajukan_permohonan.php -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3>Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-yes">Ya, Logout</button>
            <button id="cancelLogout" class="btn-no">Batal</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Logout Modal - SAMA dengan ajukan_permohonan.php
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');

    // Preview panduan alur pengajuan
    const guideModal = document.getElementById('guideModal');
    const guideOpenButtons = document.querySelectorAll('[data-guide-open]');
    const guideCloseButton = document.getElementById('guideCloseButton');
    let guideLastFocusedElement = null;

    function showGuideModal(event) {
        guideLastFocusedElement = event.currentTarget;
        guideModal.classList.add('show');
        guideModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        guideCloseButton.focus();
    }

    function hideGuideModal() {
        guideModal.classList.remove('show');
        guideModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

        if (guideLastFocusedElement) {
            guideLastFocusedElement.focus();
        }
    }

    guideOpenButtons.forEach(function(button) {
        button.addEventListener('click', showGuideModal);
    });

    guideCloseButton.addEventListener('click', hideGuideModal);

    guideModal.addEventListener('click', function(event) {
        if (event.target === guideModal) {
            hideGuideModal();
        }
    });

    guideModal.addEventListener('keydown', function(event) {
        if (event.key !== 'Tab') return;

        const focusableElements = Array.from(
            guideModal.querySelectorAll('button:not([disabled]), a[href]')
        );
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (event.shiftKey && document.activeElement === firstElement) {
            event.preventDefault();
            lastElement.focus();
        } else if (!event.shiftKey && document.activeElement === lastElement) {
            event.preventDefault();
            firstElement.focus();
        }
    });
    
    function showLogoutModal() {
        logoutModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function hideLogoutModal() {
        logoutModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    if (logoutBtn) {
        logoutBtn.addEventListener('click', showLogoutModal);
    }
    
    if (logoutBtn2) {
        logoutBtn2.addEventListener('click', showLogoutModal);
    }
    
    if (confirmLogout) {
        confirmLogout.addEventListener('click', function() {
            window.location.href = '../logout.php';
        });
    }
    
    if (cancelLogout) {
        cancelLogout.addEventListener('click', hideLogoutModal);
    }
    
    // Close modal when clicking outside
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) {
                hideLogoutModal();
            }
        });
    }
    
    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && guideModal.classList.contains('show')) {
            hideGuideModal();
            return;
        }

        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
});
</script>

<?php include __DIR__ . '/ai_chat_widget.php'; ?>
</body>
</html>
