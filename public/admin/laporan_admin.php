<?php
require_once '../error_handler.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/../../app/config/koneksi.php';
include __DIR__ . '/../../app/middleware/admin_only.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../../app/Laporan/laporan_admin_data.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan & Analisis - Sistem Pelayanan Kependudukan</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/laporan_admin.css?v=<?= urlencode((string)filemtime(__DIR__ . '/css/laporan_admin.css')) ?>">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">Pelayanan Kependudukan</div>
        <nav>
            <ul>
                <li><a href="dashboard_admin.php">Dashboard</a></li>
                <li><a href="manajemen_operator.php">Manajemen Pengguna</a></li>                
                <li><a href="reset_password.php">Reset Password Pengguna</a></li>
                <li><a href="verifikasi_pendaftaran.php">Verifikasi Pendaftaran</a></li>
                <li class="active"><a href="laporan_admin.php">Laporan & Analisis</a></li><li><a href="kinerja_operator.php">Kinerja Operator</a></li>
                <li><a href="dokumen_belum_diterima.php">Dokumen Belum Diterima</a></li>
                <li><a href="tanggapan_pemohon.php">Hasil Survei</a></li>
                <li><a href="whistleblowing.php">Whistleblowing</a></li>
                <li><a href="formulir.php">Formulir Kependudukan</a></li>
                <li><a href="manajemen_formulir_digital.php">Manajemen Formulir Digital</a></li><li><a href="pengaturan_layanan.php">Pengaturan Layanan</a></li>
                <li class="logout-item">
                    <button id="logoutBtn" class="sidebar-btn">Logout</button>
                </li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">Laporan & Analisis</div>
                <div style="color: #7f8c8d; font-size: 14px;">
                    <i class="fas fa-chart-bar"></i> Analisis Data Semua Jenis Layanan & Inovasi
                </div>
            </div>
            <div class="user-info">
                <span>Hai, <?= esc($_SESSION['nama']) ?></span>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Filter Section -->
            <form method="GET" class="filter-section">
                <input type="hidden" name="page" value="1">
                <div class="filter-group">
                    <label for="periode">Periode</label>
                    <select name="periode" id="periode" class="filter-select" onchange="this.form.submit()">
                        <option value="" <?= $periode === '' ? 'selected' : '' ?>>Semua Periode</option>
                        <option value="hari_ini" <?= $periode === 'hari_ini' ? 'selected' : '' ?>>Hari Ini</option>
                        <option value="bulan_ini" <?= $periode === 'bulan_ini' ? 'selected' : '' ?>>Bulan Ini</option>
                        <option value="tahun_ini" <?= $periode === 'tahun_ini' ? 'selected' : '' ?>>Tahun Ini</option>
                    </select>
                </div>

                <?php if ($periode === 'bulan_ini'): ?>
                <div class="filter-group">
                    <label for="bulan">Bulan</label>
                    <select name="bulan" id="bulan" class="filter-select" onchange="this.form.submit()">
                        <option value="01" <?= $bulan == '01' ? 'selected' : '' ?>>Januari</option>
                        <option value="02" <?= $bulan == '02' ? 'selected' : '' ?>>Februari</option>
                        <option value="03" <?= $bulan == '03' ? 'selected' : '' ?>>Maret</option>
                        <option value="04" <?= $bulan == '04' ? 'selected' : '' ?>>April</option>
                        <option value="05" <?= $bulan == '05' ? 'selected' : '' ?>>Mei</option>
                        <option value="06" <?= $bulan == '06' ? 'selected' : '' ?>>Juni</option>
                        <option value="07" <?= $bulan == '07' ? 'selected' : '' ?>>Juli</option>
                        <option value="08" <?= $bulan == '08' ? 'selected' : '' ?>>Agustus</option>
                        <option value="09" <?= $bulan == '09' ? 'selected' : '' ?>>September</option>
                        <option value="10" <?= $bulan == '10' ? 'selected' : '' ?>>Oktober</option>
                        <option value="11" <?= $bulan == '11' ? 'selected' : '' ?>>November</option>
                        <option value="12" <?= $bulan == '12' ? 'selected' : '' ?>>Desember</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="tahun">Tahun</label>
                    <select name="tahun" id="tahun" class="filter-select" onchange="this.form.submit()">
                        <?php foreach ($years as $year_row): ?>
                            <option value="<?= $year_row['tahun'] ?>" <?= $tahun == $year_row['tahun'] ? 'selected' : '' ?>>
                                <?= $year_row['tahun'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($periode === 'tahun_ini'): ?>
                <div class="filter-group">
                    <label for="tahun">Tahun</label>
                    <select name="tahun" id="tahun" class="filter-select" onchange="this.form.submit()">
                        <?php foreach ($years as $year_row): ?>
                            <option value="<?= $year_row['tahun'] ?>" <?= $tahun == $year_row['tahun'] ? 'selected' : '' ?>>
                                <?= $year_row['tahun'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="filter-group">
                    <label for="layanan">Layanan</label>
                    <select name="layanan" id="layanan" class="filter-select">
                        <option value="">Semua Layanan</option>
                        <?php foreach ($layanan_options as $layanan_opt): ?>
                            <option value="<?= esc($layanan_opt) ?>" <?= $layanan_filter === $layanan_opt ? 'selected' : '' ?>>
                                <?= esc($layanan_opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="status_filter">Status</label>
                    <select name="status_filter" id="status_filter" class="filter-select">
                        <option value="">Semua Status</option>
                        <?php foreach ($status_options as $status_opt): ?>
                            <option value="<?= esc($status_opt) ?>" <?= $status_filter === $status_opt ? 'selected' : '' ?>>
                                <?= ucfirst($status_opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="tanggal_dari">Tanggal Dari</label>
                    <input
                        type="date"
                        id="tanggal_dari"
                        name="tanggal_dari"
                        class="filter-select"
                        value="<?= esc($tanggal_dari) ?>"
                    >
                </div>

                <div class="filter-group">
                    <label for="tanggal_sampai">Tanggal Sampai</label>
                    <input
                        type="date"
                        id="tanggal_sampai"
                        name="tanggal_sampai"
                        class="filter-select"
                        value="<?= esc($tanggal_sampai) ?>"
                    >
                </div>

                <div class="filter-group">
                    <label for="q">Cari Nama/NIK</label>
                    <input
                        type="text"
                        id="q"
                        name="q"
                        class="filter-select"
                        placeholder="Masukkan nama atau NIK"
                        value="<?= esc($search_filter) ?>"
                    >
                </div>

                <button type="submit" class="btn-filter">
                    <i class="fas fa-filter"></i> Filter
                </button>
                
                <a href="export_laporan.php?<?= esc(http_build_query($laporanFilter->toQueryArray(false))) ?>" 
                   class="btn-export" target="_blank">
                   <i class="fas fa-file-excel"></i> Export Excel
                </a>
            </form>

            <!-- Period Info -->
            <div class="period-info">
                <h4>
                    <i class="fas fa-calendar-alt"></i> 
                    Periode Laporan: 
                    <?php 
                    if ($periode === 'hari_ini') {
                        echo 'Hari Ini - ' . date('d F Y');
                    } elseif ($periode === 'bulan_ini') {
                        $month_name = date('F', mktime(0, 0, 0, $bulan, 10));
                        echo 'Bulan ' . $month_name . ' ' . $tahun;
                    } elseif ($periode === 'tahun_ini') {
                        echo 'Tahun ' . $tahun;
                    } else {
                        echo 'Semua Periode';
                    }
                    if ($tanggal_dari !== '' || $tanggal_sampai !== '') {
                        echo ' | Rentang Pengajuan: '
                            . ($tanggal_dari !== '' ? date('d/m/Y', strtotime($tanggal_dari)) : '-')
                            . ' s/d '
                            . ($tanggal_sampai !== '' ? date('d/m/Y', strtotime($tanggal_sampai)) : '-');
                    }
                    ?>
                </h4>
                <p>Data mencakup semua jenis layanan: Kartu Keluarga, Cetak Biodata, Kartu Identitas Anak (KIA), Kartu Keluarga KUA (PASUTRI), Akta Kelahiran, Akta Kelahiran (AHLAK), Akta Kelahiran RS (ST SEHATI), Akta Kematian, Akta Kematian RS (ST SEHATI), Pindah Alamat, Pindah Masuk, dan SIP PKK.</p>
            </div>

            <!-- Statistik Total Cards -->
            <div class="stats-grid">
                <div class="stat-card-report total">
                    <h4><i class="fas fa-file-alt"></i> Total Permintaan</h4>
                    <div class="count"><?= $stats['total'] ?></div>
                    <p class="unit">semua layanan</p>
                </div>
                
                <div class="stat-card-report selesai">
                    <h4><i class="fas fa-check-circle"></i> Selesai</h4>
                    <div class="count"><?= $stats['selesai'] ?></div>
                    <p class="unit">layanan selesai</p>
                </div>
                
                <div class="stat-card-report ditolak">
                    <h4><i class="fas fa-times-circle"></i> Ditolak</h4>
                    <div class="count"><?= $stats['ditolak'] ?></div>
                    <p class="unit">layanan ditolak</p>
                </div>
                
                <div class="stat-card-report diproses">
                    <h4><i class="fas fa-cogs"></i> Diproses</h4>
                    <div class="count"><?= $stats['diproses'] ?></div>
                    <p class="unit">dalam proses</p>
                </div>
                
                <div class="stat-card-report diajukan">
                    <h4><i class="fas fa-clock"></i> Diajukan</h4>
                    <div class="count"><?= $stats['diajukan'] ?></div>
                    <p class="unit">menunggu</p>
                </div>
            </div>

            <!-- Statistik Per Jenis Dokumen -->
            <div class="jenis-stats-grid">
                <?php foreach ($stats_per_jenis as $key => $jenis): ?>
                <div class="jenis-stat-card <?= $key ?>">
                    <h4>
                        <?php if ($key === 'kartu_keluarga'): ?>
                            <i class="fas fa-home"></i>
                        <?php elseif ($key === 'kartu_keluarga_kua'): ?>
                            <i class="fas fa-heart"></i>
                        <?php elseif ($key === 'akta_kelahiran'): ?>
                            <i class="fas fa-baby"></i>
                        <?php elseif ($key === 'akta_kelahiran_ahlak'): ?>
                            <i class="fas fa-hospital"></i>
                        <?php elseif ($key === 'akta_kelahiran_rs'): ?>
                            <i class="fas fa-hospital-alt"></i>
                        <?php elseif ($key === 'akta_kematian'): ?>
                            <i class="fas fa-dove"></i>
                        <?php elseif ($key === 'akta_kematian_rs'): ?>
                            <i class="fas fa-procedures"></i>
                        <?php elseif ($key === 'pindah_alamat'): ?>
                            <i class="fas fa-truck-moving"></i>
                        <?php elseif ($key === 'pindah_masuk'): ?>
                            <i class="fas fa-plane-departure"></i>
                        <?php elseif ($key === 'KIA' || $key === 'kia'): ?>
                            <i class="fas fa-id-card"></i>
                        <?php elseif ($key === 'sip_pkk' || $key === 'SIP PKK'): ?>
                            <i class="fas fa-users"></i>
                        <?php endif; ?>
                        <?= $jenis['nama'] ?>
                    </h4>
                    <div class="jenis-stats">
                        <div class="jenis-stat-item total">
                            <div class="label">Total</div>
                            <div class="value"><?= $jenis['total'] ?></div>
                        </div>
                        <div class="jenis-stat-item selesai">
                            <div class="label">Selesai</div>
                            <div class="value"><?= $jenis['selesai'] ?></div>
                        </div>
                        <div class="jenis-stat-item ditolak">
                            <div class="label">Ditolak</div>
                            <div class="value"><?= $jenis['ditolak'] ?></div>
                        </div>
                        <div class="jenis-stat-item diproses">
                            <div class="label">Diproses</div>
                            <div class="value"><?= $jenis['diproses'] ?></div>
                        </div>
                        <div class="jenis-stat-item diajukan">
                            <div class="label">Diajukan</div>
                            <div class="value"><?= $jenis['diajukan'] ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Statistik Tambahan -->
            <div class="table-section">
                <h3><i class="fas fa-map-marked-alt"></i> Statistik Per Kecamatan</h3>
                <?php if (empty($stats_per_kecamatan)): ?>
                    <div class="empty-state">
                        <i class="fas fa-map"></i>
                        <h4>Tidak ada data kecamatan</h4>
                        <p>Belum ada data untuk periode yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container section-scroll" style="max-height: 430px; overflow-x: auto; overflow-y: scroll;" tabindex="0" aria-label="Daftar semua kecamatan">
                        <table>
                            <thead>
                                <tr>
                                    <th>Kecamatan</th>
                                    <th>Total</th>
                                    <th>Diajukan</th>
                                    <th>Diproses</th>
                                    <th>Selesai</th>
                                    <th>Ditolak</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($stats_per_kecamatan as $kecamatan => $kec_stat):
                                ?>
                                    <tr>
                                        <td><strong><?= esc($kecamatan) ?></strong></td>
                                        <td><?= $kec_stat['total'] ?></td>
                                        <td><?= $kec_stat['diajukan'] ?></td>
                                        <td><?= $kec_stat['diproses'] ?></td>
                                        <td><?= $kec_stat['selesai'] ?></td>
                                        <td><?= $kec_stat['ditolak'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-section">
                <h3><i class="fas fa-city"></i> Statistik Per Desa</h3>
                <?php if (empty($stats_per_desa)): ?>
                    <div class="empty-state">
                        <i class="fas fa-home"></i>
                        <h4>Tidak ada data desa</h4>
                        <p>Belum ada data untuk periode yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container section-scroll" style="max-height: 430px; overflow-x: auto; overflow-y: scroll;" tabindex="0" aria-label="Daftar semua desa">
                        <table>
                            <thead>
                                <tr>
                                    <th>Desa (Kecamatan)</th>
                                    <th>Total</th>
                                    <th>Diajukan</th>
                                    <th>Diproses</th>
                                    <th>Selesai</th>
                                    <th>Ditolak</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($stats_per_desa as $desa_stat):
                                ?>
                                    <tr>
                                        <td><strong><?= esc($desa_stat['label']) ?></strong></td>
                                        <td><?= $desa_stat['total'] ?></td>
                                        <td><?= $desa_stat['diajukan'] ?></td>
                                        <td><?= $desa_stat['diproses'] ?></td>
                                        <td><?= $desa_stat['selesai'] ?></td>
                                        <td><?= $desa_stat['ditolak'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-section">
                <h3><i class="fas fa-users"></i> Permohonan Online &amp; Petugas Desa Per Kecamatan</h3>
                <div class="table-container section-scroll" tabindex="0" aria-label="Permohonan Online dan Petugas Desa per kecamatan">
                    <table>
                        <thead>
                            <tr>
                                <th>Kecamatan</th><th>Total</th><th>Diajukan</th><th>Diproses</th>
                                <th>Selesai</th><th>% Selesai</th><th>Ditolak</th><th>% Ditolak</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats_online_desa_per_kecamatan as $kecamatan => $program_stat): ?>
                            <tr>
                                <td><strong><?= esc($kecamatan) ?></strong></td>
                                <td><?= $program_stat['total'] ?></td>
                                <td><?= $program_stat['diajukan'] ?></td>
                                <td><?= $program_stat['diproses'] ?></td>
                                <td><?= $program_stat['selesai'] ?></td>
                                <td><?= laporanPercentage($program_stat['selesai'], $program_stat['total']) ?></td>
                                <td><?= $program_stat['ditolak'] ?></td>
                                <td><?= laporanPercentage($program_stat['ditolak'], $program_stat['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="table-section">
                <h3><i class="fas fa-baby"></i> Statistik AHLAK Per Kecamatan</h3>
                <div class="table-container section-scroll" tabindex="0" aria-label="Statistik AHLAK per kecamatan">
                    <table>
                        <thead>
                            <tr>
                                <th>Kecamatan</th><th>Total</th><th>Diajukan</th><th>Diproses</th>
                                <th>Selesai</th><th>% Selesai</th><th>Ditolak</th><th>% Ditolak</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats_ahlak_per_kecamatan as $kecamatan => $program_stat): ?>
                            <tr>
                                <td><strong><?= esc($kecamatan) ?></strong></td>
                                <td><?= $program_stat['total'] ?></td>
                                <td><?= $program_stat['diajukan'] ?></td>
                                <td><?= $program_stat['diproses'] ?></td>
                                <td><?= $program_stat['selesai'] ?></td>
                                <td><?= laporanPercentage($program_stat['selesai'], $program_stat['total']) ?></td>
                                <td><?= $program_stat['ditolak'] ?></td>
                                <td><?= laporanPercentage($program_stat['ditolak'], $program_stat['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="table-section">
                <h3><i class="fas fa-heart"></i> Statistik Pelayanan PASUTRI Per Kecamatan</h3>
                <?php if (empty($stats_pasutri_per_kecamatan)): ?>
                    <div class="empty-state">
                        <i class="fas fa-map"></i>
                        <h4>Tidak ada data PASUTRI per kecamatan</h4>
                        <p>Belum ada data untuk periode yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container section-scroll" tabindex="0" aria-label="Daftar statistik PASUTRI">
                        <table>
                            <thead>
                                <tr>
                                    <th>Kecamatan</th>
                                    <th>Total</th>
                                    <th>Diajukan</th>
                                    <th>Diproses</th>
                                    <th>Selesai</th>
                                    <th>% Selesai</th>
                                    <th>Ditolak</th>
                                    <th>% Ditolak</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($stats_pasutri_per_kecamatan as $kecamatan => $pasutri_stat):
                                ?>
                                    <tr>
                                        <td><strong><?= esc($kecamatan) ?></strong></td>
                                        <td><?= $pasutri_stat['total'] ?></td>
                                        <td><?= $pasutri_stat['diajukan'] ?></td>
                                        <td><?= $pasutri_stat['diproses'] ?></td>
                                        <td><?= $pasutri_stat['selesai'] ?></td>
                                        <td><?= laporanPercentage($pasutri_stat['selesai'], $pasutri_stat['total']) ?></td>
                                        <td><?= $pasutri_stat['ditolak'] ?></td>
                                        <td><?= laporanPercentage($pasutri_stat['ditolak'], $pasutri_stat['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-section">
                <h3><i class="fas fa-hospital"></i> Statistik ST SEHATI Per Kecamatan</h3>
                <div class="table-container section-scroll" tabindex="0" aria-label="Statistik ST SEHATI per kecamatan">
                    <table>
                        <thead>
                            <tr>
                                <th>Kecamatan</th><th>Kelahiran</th><th>Kematian</th><th>Total</th>
                                <th>Diajukan</th><th>Diproses</th><th>Selesai</th><th>% Selesai</th>
                                <th>Ditolak</th><th>% Ditolak</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats_st_sehati_per_kecamatan as $kecamatan => $program_stat): ?>
                                <tr>
                                    <td><strong><?= esc($kecamatan) ?></strong></td>
                                    <td><?= $program_stat['kelahiran'] ?></td>
                                    <td><?= $program_stat['kematian'] ?></td>
                                    <td><?= $program_stat['total'] ?></td>
                                    <td><?= $program_stat['diajukan'] ?></td>
                                    <td><?= $program_stat['diproses'] ?></td>
                                    <td><?= $program_stat['selesai'] ?></td>
                                    <td><?= laporanPercentage($program_stat['selesai'], $program_stat['total']) ?></td>
                                    <td><?= $program_stat['ditolak'] ?></td>
                                    <td><?= laporanPercentage($program_stat['ditolak'], $program_stat['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="table-section">
                <h3><i class="fas fa-user-tag"></i> Permohonan Berdasarkan Role Pemohon</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Role Pemohon</th><th>Total</th><th>Diajukan</th><th>Diproses</th>
                                <th>Selesai</th><th>% Selesai</th><th>Ditolak</th><th>% Ditolak</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats_per_role as $role_stat): ?>
                            <tr>
                                <td><strong><?= esc($role_stat['label']) ?></strong></td>
                                <td><?= $role_stat['total'] ?></td>
                                <td><?= $role_stat['diajukan'] ?></td>
                                <td><?= $role_stat['diproses'] ?></td>
                                <td><?= $role_stat['selesai'] ?></td>
                                <td><?= laporanPercentage($role_stat['selesai'], $role_stat['total']) ?></td>
                                <td><?= $role_stat['ditolak'] ?></td>
                                <td><?= laporanPercentage($role_stat['ditolak'], $role_stat['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="table-section">
                <h3><i class="fas fa-user-md"></i> Rincian ST SEHATI Berdasarkan User Rumah Sakit</h3>
                <?php if (empty($stats_st_sehati_per_user)): ?>
                    <div class="empty-state">
                        <i class="fas fa-hospital-user"></i>
                        <h4>Tidak ada data user ST SEHATI</h4>
                        <p>Belum ada permohonan ST SEHATI pada periode yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container section-scroll" tabindex="0" aria-label="Rincian ST SEHATI berdasarkan user dan rumah sakit">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nama User</th><th>Rumah Sakit</th><th>Kelahiran</th><th>Kematian</th>
                                    <th>Total</th><th>Selesai</th><th>% Selesai</th>
                                    <th>Ditolak</th><th>% Ditolak</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($stats_st_sehati_per_user as $user_stat): ?>
                                <tr>
                                    <td><strong><?= esc($user_stat['nama_user']) ?></strong></td>
                                    <td><?= esc($user_stat['nama_rs']) ?></td>
                                    <td><?= $user_stat['kelahiran'] ?></td>
                                    <td><?= $user_stat['kematian'] ?></td>
                                    <td><?= $user_stat['total'] ?></td>
                                    <td><?= $user_stat['selesai'] ?></td>
                                    <td><?= laporanPercentage($user_stat['selesai'], $user_stat['total']) ?></td>
                                    <td><?= $user_stat['ditolak'] ?></td>
                                    <td><?= laporanPercentage($user_stat['ditolak'], $user_stat['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Chart Section -->
            <div class="chart-container">
                <h3><i class="fas fa-chart-line"></i> 
                    <?php 
                    if ($periode === 'hari_ini') {
                        echo 'Grafik Permintaan per Jam';
                    } elseif ($periode === 'bulan_ini') {
                        echo 'Grafik Permintaan per Hari';
                    } elseif ($periode === 'tahun_ini') {
                        echo 'Grafik Permintaan per Bulan';
                    } else {
                        echo 'Grafik Permintaan per Bulan (Semua Periode)';
                    }
                    ?>
                </h3>
                <div class="chart-wrapper">
                    <canvas id="permintaanChart"></canvas>
                </div>
            </div>

            <!-- Table Section -->
            <div class="table-section">
                <h3><i class="fas fa-list"></i> 
                    Detail Permintaan Layanan
                    <span style="font-size: 14px; font-weight: normal; color: #7f8c8d; margin-left: 10px;">
                        (Total: <?= $total_records ?> data)
                    </span>
                </h3>
                
                <?php if (empty($reports)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>Tidak ada data ditemukan</h4>
                        <p>Tidak ada permintaan layanan pada periode yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th width="50">#</th>
                                    <th>Tanggal</th>
                                    <th>Jenis Layanan</th>
                                    <th>Judul</th>
                                    <th>Kecamatan</th>
                                    <th>Desa</th>
                                    <th>Status</th>
                                    <th>Operator</th>
                                    <th>Aksi</th>
                                    <th>Timeline</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = ($current_page - 1) * $per_page + 1;
                                foreach ($reports as $report): 
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($report['created_at'])) ?></td>
                                        <td><strong><?= esc($report['jenis_dokumen']) ?></strong></td>
                                        <td><?= esc($report['judul'] ?? '-') ?></td>
                                        <td><?= esc($report['nama_kecamatan'] ?? '-') ?></td>
                                        <td><?= esc($report['nama_desa'] ?? '-') ?></td>
                                        <td>
                                            <span class="status-badge status-<?= esc($report['status']) ?>">
                                                <?= esc(ucfirst((string)$report['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= esc($report['operator_nama'] ?? '-') ?></td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn-go"
                                                onclick="showDetailPermohonan(<?= (int)($report['id'] ?? 0) ?>, <?= htmlspecialchars(json_encode($report['jenis_dokumen'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)"
                                            >
                                                Detail
                                            </button>
                                        </td>
                                        <td class="timeline-info">
<div>Status diperbarui: <?= esc(serviceTrackingUpdatedLabel($koneksi, serviceTrackingTableForKind((string)$report['jenis_dokumen']), (int)$report['id'])) ?></div>
                                            <i class="fas fa-clock"></i> Diajukan: <?= date('H:i', strtotime($report['created_at'])) ?><br>
                                            <?php if ($report['updated_at']): ?>
                                                <i class="fas fa-sync"></i> Update: <?= date('H:i', strtotime($report['updated_at'])) ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-info">
                                Menampilkan <?= count($reports) ?> dari <?= $total_records ?> data
                                (Halaman <?= $current_page ?> dari <?= $total_pages ?>)
                            </div>
                            
                            <div class="pagination-links">
                                <?php 
                                // Tombol Previous
                                if ($current_page > 1): 
                                    $prev_params = http_build_query(array_merge($_GET, ['page' => $current_page - 1]));
                                ?>
                                    <a href="?<?= $prev_params ?>" class="pagination-link">← Prev</a>
                                <?php else: ?>
                                    <span class="pagination-link disabled">← Prev</span>
                                <?php endif; ?>
                                
                                <?php 
                                // Tampilkan 5 halaman di sekitar current page
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                // Jika di awal, tambah halaman di akhir
                                if ($start_page == 1) {
                                    $end_page = min($total_pages, $start_page + 4);
                                }
                                
                                // Jika di akhir, tambah halaman di awal
                                if ($end_page == $total_pages) {
                                    $start_page = max(1, $end_page - 4);
                                }
                                
                                // Tampilkan halaman pertama jika tidak termasuk
                                if ($start_page > 1): 
                                    $first_params = http_build_query(array_merge($_GET, ['page' => 1]));
                                ?>
                                    <a href="?<?= $first_params ?>" class="pagination-link">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span class="pagination-link disabled">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): 
                                    $page_params = http_build_query(array_merge($_GET, ['page' => $i]));
                                ?>
                                    <a href="?<?= $page_params ?>" class="pagination-link <?= $i == $current_page ? 'active' : '' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php 
                                // Tampilkan halaman terakhir jika tidak termasuk
                                if ($end_page < $total_pages): 
                                    $last_params = http_build_query(array_merge($_GET, ['page' => $total_pages]));
                                ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="pagination-link disabled">...</span>
                                    <?php endif; ?>
                                    <a href="?<?= $last_params ?>" class="pagination-link"><?= $total_pages ?></a>
                                <?php endif; ?>
                                
                                <?php 
                                // Tombol Next
                                if ($current_page < $total_pages): 
                                    $next_params = http_build_query(array_merge($_GET, ['page' => $current_page + 1]));
                                ?>
                                    <a href="?<?= $next_params ?>" class="pagination-link">Next →</a>
                                <?php else: ?>
                                    <span class="pagination-link disabled">Next →</span>
                                <?php endif; ?>
                                
                                <!-- Input Lompat ke Halaman -->
                                <div class="page-input-group">
                                    <input type="number" id="goToPage" class="page-input" 
                                           min="1" max="<?= $total_pages ?>" 
                                           value="<?= $current_page ?>"
                                           onkeypress="if(event.key === 'Enter') goToPage()">
                                    <button type="button" class="btn-go" onclick="goToPage()">Go</button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- Modal Detail Permohonan -->
<div class="modal-overlay" id="detailPermohonanModal">
    <div class="modal-box" style="max-width: 820px; width: 95vw; max-height: 90vh; overflow: hidden;">
        <h3><i class="fas fa-file-lines"></i> Detail Permohonan</h3>
        <div id="detailPermohonanBody" style="line-height: 1.7; color: #2c3e50; margin-top: 10px; max-height: 65vh; overflow-y: auto; padding-right: 6px;"></div>
        <div class="modal-actions">
            <button id="closeDetailPermohonan" class="btn-cancel">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Logout -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
        <h3><i class="fas fa-sign-out-alt"></i> Konfirmasi Logout</h3>
        <p>Apakah Anda yakin ingin logout?</p>
        <div class="modal-actions">
            <button id="confirmLogout" class="btn-save">Ya, Logout</button>
            <button id="cancelLogout" class="btn-cancel">Batal</button>
        </div>
    </div>
</div>

<script>
function escapeHtml(value) {
    const text = value === null || value === undefined ? '' : String(value);
    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function normalizeDocPath(path) {
    let p = String(path || '').trim().replaceAll('\\', '/');
    while (p.startsWith('../')) p = p.substring(3);
    if (p.startsWith('/')) p = p.substring(1);
    if (p.startsWith('public/')) p = p.substring('public/'.length);
    if (p.startsWith('user/')) p = p.substring('user/'.length);
    const uploadsIndex = p.indexOf('uploads/');
    if (uploadsIndex > 0) p = p.substring(uploadsIndex);
    return p;
}

function drawFallbackLineChart(canvas, labels, values, title, xLabel) {
    const context = canvas.getContext('2d');
    const wrapper = canvas.parentElement;
    const dpr = window.devicePixelRatio || 1;
    const width = Math.max(640, wrapper ? wrapper.clientWidth : 640);
    const height = Math.max(320, wrapper ? wrapper.clientHeight : 360);
    canvas.width = width * dpr;
    canvas.height = height * dpr;
    canvas.style.width = width + 'px';
    canvas.style.height = height + 'px';
    context.setTransform(dpr, 0, 0, dpr, 0, 0);
    context.clearRect(0, 0, width, height);

    const padding = { left: 58, right: 24, top: 58, bottom: 62 };
    const chartWidth = width - padding.left - padding.right;
    const chartHeight = height - padding.top - padding.bottom;
    const numericValues = values.map((value) => Number(value) || 0);
    const maxValue = Math.max(1, ...numericValues);
    const steps = 5;

    context.fillStyle = '#2c3e50';
    context.font = 'bold 16px sans-serif';
    context.textAlign = 'center';
    context.fillText(title, width / 2, 26);

    context.strokeStyle = '#dfe6ed';
    context.fillStyle = '#607080';
    context.font = '12px sans-serif';
    for (let step = 0; step <= steps; step++) {
        const y = padding.top + (chartHeight * step / steps);
        const value = Math.round(maxValue * (steps - step) / steps);
        context.beginPath();
        context.moveTo(padding.left, y);
        context.lineTo(width - padding.right, y);
        context.stroke();
        context.textAlign = 'right';
        context.fillText(String(value), padding.left - 8, y + 4);
    }

    const pointX = (index) => labels.length <= 1
        ? padding.left + chartWidth / 2
        : padding.left + chartWidth * index / (labels.length - 1);
    const pointY = (value) => padding.top + chartHeight - (Number(value) || 0) / maxValue * chartHeight;

    if (numericValues.length > 0) {
        context.beginPath();
        numericValues.forEach((value, index) => {
            const x = pointX(index);
            const y = pointY(value);
            if (index === 0) context.moveTo(x, y);
            else context.lineTo(x, y);
        });
        context.strokeStyle = '#3498db';
        context.lineWidth = 3;
        context.stroke();

        numericValues.forEach((value, index) => {
            context.beginPath();
            context.arc(pointX(index), pointY(value), 4, 0, Math.PI * 2);
            context.fillStyle = '#3498db';
            context.fill();
        });
    }

    const maxLabels = Math.max(1, Math.floor(chartWidth / 70));
    const labelStep = Math.max(1, Math.ceil(labels.length / maxLabels));
    context.fillStyle = '#607080';
    context.font = '11px sans-serif';
    context.textAlign = 'center';
    labels.forEach((label, index) => {
        if (index % labelStep === 0 || index === labels.length - 1) {
            context.fillText(String(label), pointX(index), height - padding.bottom + 22);
        }
    });
    context.font = '12px sans-serif';
    context.fillText(xLabel, padding.left + chartWidth / 2, height - 12);
}

async function showDetailPermohonan(id, jenis) {
    const modal = document.getElementById('detailPermohonanModal');
    const body = document.getElementById('detailPermohonanBody');
    if (!modal || !body) return;

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    body.innerHTML = '<p>Memuat detail permohonan...</p>';

    try {
        const params = new URLSearchParams({
            detail_fetch: '1',
            id: String(id || 0),
            jenis: String(jenis || '')
        });
        const res = await fetch(`${window.location.pathname}?${params.toString()}`, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        const payload = await res.json();

        if (!payload || !payload.success) {
            body.innerHTML = `<p>${escapeHtml((payload && payload.message) ? payload.message : 'Gagal memuat detail.')}</p>`;
            return;
        }

        let html = '';
        (payload.detail || []).forEach((item) => {
            html += `<div><strong>${escapeHtml(item.label)}:</strong> ${escapeHtml(item.value)}</div>`;
        });

        html += payload.status_history_html || '';
        if (payload.user_info) {
            html += `
                <hr style="margin: 10px 0;">
                <div><strong>Data Pemohon</strong></div>
                <div>Nama: ${escapeHtml(payload.user_info.nama || '-')}</div>
                <div>NIK: ${escapeHtml(payload.user_info.nik || '-')}</div>
                <div>No HP: ${escapeHtml(payload.user_info.no_hp || '-')}</div>
                <div>Email: ${escapeHtml(payload.user_info.email || '-')}</div>
                <div>Role: ${escapeHtml(payload.user_info.role || '-')}</div>
            `;
        }

        if (Array.isArray(payload.dokumen) && payload.dokumen.length > 0) {
            html += '<hr style="margin: 10px 0;"><div><strong>Dokumen</strong></div><ul style="margin: 6px 0; padding-left: 18px;">';
            payload.dokumen.forEach((doc) => {
                const normalizedPath = normalizeDocPath(doc.path || '');
                const baseDocUrl = normalizedPath
                    ? `view_dokumen.php?path=${encodeURIComponent(normalizedPath)}&name=${encodeURIComponent(doc.nama || 'dokumen')}`
                    : '#';
                const openUrl = normalizedPath ? baseDocUrl : '#';
                const downloadUrl = normalizedPath ? `${baseDocUrl}&download=1` : '#';

                html += `
                    <li>
                        ${escapeHtml(doc.nama || '-')} (${escapeHtml(doc.tipe || '-')})
                        ${normalizedPath
                            ? ` - <a href="${openUrl}" target="_blank" rel="noopener noreferrer">Buka</a>
                                | <a href="${downloadUrl}" target="_blank" rel="noopener noreferrer">Download</a>`
                            : ''}
                    </li>
                `;
            });
            html += '</ul>';
        }

        body.innerHTML = html || '<p>Detail tidak tersedia.</p>';
    } catch (err) {
        body.innerHTML = '<p>Terjadi kesalahan saat mengambil detail permohonan.</p>';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Chart Configuration
    const ctx = document.getElementById('permintaanChart').getContext('2d');
    
    // Deklarasi variabel
    let chartTitle, xLabel;
    
    <?php if ($periode === 'hari_ini'): ?>
        chartTitle = 'Permintaan Layanan per Jam - <?= date("d F Y") ?>';
        xLabel = 'Jam';
    <?php elseif ($periode === 'bulan_ini'): ?>
        <?php 
        $bulanName = date("F", mktime(0, 0, 0, $bulan, 10));
        ?>
        chartTitle = 'Permintaan Layanan per Hari - <?= $bulanName ?> <?= $tahun ?>';
        xLabel = 'Tanggal';
    <?php elseif ($periode === 'tahun_ini'): ?>
        chartTitle = 'Permintaan Layanan per Bulan - Tahun <?= $tahun ?>';
        xLabel = 'Bulan';
    <?php else: ?>
        chartTitle = 'Permintaan Layanan per Bulan - Semua Periode';
        xLabel = 'Periode (YYYY-MM)';
    <?php endif; ?>
    
    const chartLabels = <?= json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const chartValues = <?= json_encode($data, JSON_NUMERIC_CHECK) ?>;
    let permintaanChart;

    if (typeof window.Chart === 'undefined') {
        drawFallbackLineChart(ctx.canvas, chartLabels, chartValues, chartTitle, xLabel);
        permintaanChart = {
            resize: function() {
                drawFallbackLineChart(ctx.canvas, chartLabels, chartValues, chartTitle, xLabel);
            }
        };
    } else {
    permintaanChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [{
                label: 'Jumlah Permintaan',
                data: chartValues,
                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                borderColor: 'rgba(52, 152, 219, 1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: 'rgba(52, 152, 219, 1)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: chartTitle,
                    font: {
                        size: 16,
                        weight: 'bold'
                    },
                    padding: {
                        top: 10,
                        bottom: 30
                    }
                },
                legend: {
                    display: false
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleFont: {
                        size: 14
                    },
                    bodyFont: {
                        size: 14
                    },
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            return `Permintaan: ${context.parsed.y} layanan`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: xLabel,
                        font: {
                            size: 14,
                            weight: 'bold'
                        }
                    },
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Jumlah Permintaan',
                        font: {
                            size: 14,
                            weight: 'bold'
                        }
                    },
                    ticks: {
                        stepSize: 1,
                        precision: 0
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'nearest'
            }
        }
    });
    }

    // Function untuk lompat ke halaman tertentu
    window.goToPage = function() {
        const pageInput = document.getElementById('goToPage');
        let page = parseInt(pageInput.value);
        
        // Validasi input
        if (isNaN(page) || page < 1) {
            page = 1;
        } else if (page > <?= $total_pages ?>) {
            page = <?= $total_pages ?>;
        }
        
        // Redirect ke halaman yang dipilih
        const params = new URLSearchParams(window.location.search);
        params.set('page', page);
        window.location.href = '?' + params.toString();
    };

    // Reset page ke 1 ketika filter berubah
    document.getElementById('periode').addEventListener('change', function() {
        document.querySelector('input[name="page"]').value = 1;
    });
    
    <?php if ($periode === 'bulan_ini'): ?>
    document.getElementById('bulan').addEventListener('change', function() {
        document.querySelector('input[name="page"]').value = 1;
    });
    
    document.getElementById('tahun').addEventListener('change', function() {
        document.querySelector('input[name="page"]').value = 1;
    });
    <?php endif; ?>
    
    <?php if ($periode === 'tahun_ini'): ?>
    document.getElementById('tahun').addEventListener('change', function() {
        document.querySelector('input[name="page"]').value = 1;
    });
    <?php endif; ?>

    // Toggle Sidebar Menu on Mobile
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && 
                    !menuToggle.contains(event.target) && 
                    sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            }
        });
    }
    
    // Logout Modal
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutBtn2 = document.getElementById('logoutBtn2');
    const logoutModal = document.getElementById('logoutModal');
    const detailPermohonanModal = document.getElementById('detailPermohonanModal');
    const closeDetailPermohonan = document.getElementById('closeDetailPermohonan');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');
    
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

    if (closeDetailPermohonan) {
        closeDetailPermohonan.addEventListener('click', function() {
            if (detailPermohonanModal) {
                detailPermohonanModal.classList.remove('show');
                document.body.style.overflow = '';
            }
        });
    }
    
    // Close modal when clicking outside
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) {
                hideLogoutModal();
            }
        });
    }

    if (detailPermohonanModal) {
        detailPermohonanModal.addEventListener('click', function(event) {
            if (event.target === detailPermohonanModal) {
                detailPermohonanModal.classList.remove('show');
                document.body.style.overflow = '';
            }
        });
    }
    
    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
        if (event.key === 'Escape' && detailPermohonanModal && detailPermohonanModal.classList.contains('show')) {
            detailPermohonanModal.classList.remove('show');
            document.body.style.overflow = '';
        }
    });

    // Responsive adjustment
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && sidebar) {
            sidebar.classList.remove('open');
        }
        permintaanChart.resize();
    });
});
</script>

</body>
</html>

