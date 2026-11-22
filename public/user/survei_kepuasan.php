<?php
declare(strict_types=1);

require_once __DIR__ . '/../error_handler.php';
require_once __DIR__ . '/../../app/config/koneksi.php';
require_once __DIR__ . '/../../app/middleware/service_submission.php';
require_once __DIR__ . '/../../app/Models/KiaModel.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

siprima_auth_guard($koneksi);

if (empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$userId = (int)$_SESSION['id_user'];
$idPengajuan = isset($_GET['id_pengajuan']) ? (int)$_GET['id_pengajuan'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

// Ambil data profil pemohon dari tb_users
$stmtUser = $koneksi->prepare("SELECT nama, nik, email, no_hp, alamat FROM tb_users WHERE id = ? LIMIT 1");
$stmtUser->bind_param('i', $userId);
$stmtUser->execute();
$dataUser = $stmtUser->get_result()->fetch_assoc() ?: [];
$stmtUser->close();

// Cari pengajuan KIA milik user (berdasarkan id_pengajuan atau pengajuan terakhir milik user)
$pengajuanKia = null;
if ($idPengajuan > 0) {
    $stmtKia = $koneksi->prepare("SELECT * FROM tb_pengajuan_kia WHERE id = ? AND id_user = ? LIMIT 1");
    $stmtKia->bind_param('ii', $idPengajuan, $userId);
    $stmtKia->execute();
    $pengajuanKia = $stmtKia->get_result()->fetch_assoc() ?: null;
    $stmtKia->close();
}

if (!$pengajuanKia) {
    $stmtLatest = $koneksi->prepare("SELECT * FROM tb_pengajuan_kia WHERE id_user = ? ORDER BY id DESC LIMIT 1");
    $stmtLatest->bind_param('i', $userId);
    $stmtLatest->execute();
    $pengajuanKia = $stmtLatest->get_result()->fetch_assoc() ?: null;
    $stmtLatest->close();
    if ($pengajuanKia) {
        $idPengajuan = (int)$pengajuanKia['id'];
    }
}

$kiaModel = new \Siprima\Models\KiaModel($koneksi);
$todayDate = date('Y-m-d');
$progress = ($idPengajuan > 0)
    ? $kiaModel->getSurveiProgress($idPengajuan, $userId, $todayDate)
    : ['jumlah_selesai' => 0, 'total_survei' => 3, 'is_lengkap' => false, 'survei_selesai' => []];

$noAntrianDefault = $idPengajuan > 0 ? sprintf('#KIA-%03d', $idPengajuan) : '#KIA-001';
$tanggalLayananDefault = !empty($pengajuanKia['updated_at'])
    ? date('Y-m-d', strtotime((string)$pengajuanKia['updated_at']))
    : $todayDate;

// Daftar Pertanyaan SKM (9 Unsur PermenpanRB No. 14 Tahun 2017)
$skmQuestions = [
    [
        'code' => 'U1',
        'badge' => 'Unsur 1: Persyaratan',
        'question' => 'Bagaimana pendapat Saudara tentang kesesuaian persyaratan pelayanan pengajuan KIA dengan jenis pelayanannya?',
        'options' => [
            1 => 'Tidak Sesuai',
            2 => 'Kurang Sesuai',
            3 => 'Sesuai',
            4 => 'Sangat Sesuai',
        ],
    ],
    [
        'code' => 'U2',
        'badge' => 'Unsur 2: Sistem, Mekanisme, dan Prosedur',
        'question' => 'Bagaimana pemahaman Saudara tentang kemudahan prosedur pelayanan pengajuan KIA di aplikasi SIPRIMA?',
        'options' => [
            1 => 'Tidak Mudah',
            2 => 'Kurang Mudah',
            3 => 'Mudah',
            4 => 'Sangat Mudah',
        ],
    ],
    [
        'code' => 'U3',
        'badge' => 'Unsur 3: Waktu Penyelesaian',
        'question' => 'Bagaimana pendapat Saudara tentang kecepatan waktu dalam memberikan pelayanan penerbitan KIA?',
        'options' => [
            1 => 'Tidak Cepat',
            2 => 'Kurang Cepat',
            3 => 'Cepat',
            4 => 'Sangat Cepat',
        ],
    ],
    [
        'code' => 'U4',
        'badge' => 'Unsur 4: Biaya / Tarif',
        'question' => 'Bagaimana pendapat Saudara tentang kewajaran biaya/tarif dalam pelayanan KIA (Pelayanan Gratis / Rp 0)?',
        'options' => [
            1 => 'Sangat Mahal',
            2 => 'Cukup Mahal',
            3 => 'Murah',
            4 => 'Gratis / Sesuai Ketentuan',
        ],
    ],
    [
        'code' => 'U5',
        'badge' => 'Unsur 5: Produk Spesifikasi Jenis Pelayanan',
        'question' => 'Bagaimana pendapat Saudara tentang kesesuaian produk pelayanan (dokumen/kartu KIA) antara yang tercantum dalam standar pelayanan dengan hasil yang diberikan?',
        'options' => [
            1 => 'Tidak Sesuai',
            2 => 'Kurang Sesuai',
            3 => 'Sesuai',
            4 => 'Sangat Sesuai',
        ],
    ],
    [
        'code' => 'U6',
        'badge' => 'Unsur 6: Kompetensi Pelaksana',
        'question' => 'Bagaimana pendapat Saudara tentang kompetensi/kemampuan petugas dalam memberikan pelayanan KIA?',
        'options' => [
            1 => 'Tidak Kompeten',
            2 => 'Kurang Kompeten',
            3 => 'Kompeten',
            4 => 'Sangat Kompeten',
        ],
    ],
    [
        'code' => 'U7',
        'badge' => 'Unsur 7: Perilaku Pelaksana',
        'question' => 'Bagaimana pendapat Saudara tentang perilaku petugas dalam pelayanan terkait kesopanan dan keramahan?',
        'options' => [
            1 => 'Tidak Sopan & Ramah',
            2 => 'Kurang Sopan & Ramah',
            3 => 'Sopan & Ramah',
            4 => 'Sangat Sopan & Ramah',
        ],
    ],
    [
        'code' => 'U8',
        'badge' => 'Unsur 8: Penanganan Pengaduan, Saran & Masukan',
        'question' => 'Bagaimana pendapat Saudara tentang kualitas penanganan pengaduan, koreksi data, saran dan masukan pengguna layanan?',
        'options' => [
            1 => 'Tidak Ada',
            2 => 'Ada Tetapi Tidak Berfungsi',
            3 => 'Berfungsi Kurang Maksimal',
            4 => 'Dikelola dengan Baik',
        ],
    ],
    [
        'code' => 'U9',
        'badge' => 'Unsur 9: Sarana dan Prasarana',
        'question' => 'Bagaimana pendapat Saudara tentang kualitas sarana dan prasarana (termasuk kemudahan fitur digital SIPRIMA)?',
        'options' => [
            1 => 'Buruk',
            2 => 'Cukup',
            3 => 'Baik',
            4 => 'Sangat Baik',
        ],
    ],
];

// Daftar Pernyataan SPKP (8 Pernyataan Skala 1-6)
$spkpQuestions = [
    ['code' => 'P1', 'badge' => 'Informasi Layanan', 'question' => 'Informasi persyaratan dan alur pengajuan KIA melalui SIPRIMA tersedia dengan jelas dan mudah dipahami.'],
    ['code' => 'P2', 'badge' => 'Kemudahan Persyaratan', 'question' => 'Persyaratan dokumen pengajuan KIA mudah dipenuhi dan sesuai dengan ketentuan yang berlaku.'],
    ['code' => 'P3', 'badge' => 'Kejelasan Prosedur', 'question' => 'Tahapan pengajuan dari pengisian data anak hingga unduh dokumen KIA berjalan sistematis dan transparan.'],
    ['code' => 'P4', 'badge' => 'Ketepatan Waktu', 'question' => 'Waktu verifikasi dan penyelesaian permohonan KIA sesuai dengan standar waktu pelayanan yang dijanjikan.'],
    ['code' => 'P5', 'badge' => 'Kesesuaian Biaya (Gratis)', 'question' => 'Seluruh proses pelayanan KIA tidak dipungut biaya apa pun (100% Gratis).'],
    ['code' => 'P6', 'badge' => 'Kualitas Hasil KIA', 'question' => 'Dokumen/Kartu Identitas Anak (KIA) yang diterbitkan memiliki data yang akurat dan kualitas cetak/PDF yang jelas.'],
    ['code' => 'P7', 'badge' => 'Sikap & Keramahan Petugas', 'question' => 'Petugas/Operator memberikan pelayanan serta tanggapan dengan cepat, ramah, dan solutif.'],
    ['code' => 'P8', 'badge' => 'Fasilitas & Sistem Online', 'question' => 'Fitur pelacakan status, notifikasi, dan unduh dokumen pada sistem SIPRIMA berfungsi dengan sangat baik.'],
];

// Daftar Pernyataan SPAK (5 Pernyataan Anti Korupsi Skala 1-6)
$spakQuestions = [
    ['code' => 'A1', 'badge' => 'Non-Diskriminasi', 'question' => 'Petugas memberikan pelayanan pengajuan KIA secara adil tanpa membedakan status sosial, agama, suku, atau hubungan tertentu.'],
    ['code' => 'A2', 'badge' => 'Sesuai Prosedur Resmi', 'question' => 'Tidak ada petugas yang memberikan pelayanan di luar prosedur resmi atau memanipulasi antrean pelayanan.'],
    ['code' => 'A3', 'badge' => 'Bebas Gratifikasi', 'question' => 'Petugas tidak meminta atau menerima imbalan uang, barang, maupun fasilitas apa pun di luar ketentuan.'],
    ['code' => 'A4', 'badge' => 'Bebas Pungutan Liar (Pungli)', 'question' => 'Tidak terdapat pungutan liar (pungli) dalam seluruh rangkaian proses penerbitan Kartu Identitas Anak (KIA).'],
    ['code' => 'A5', 'badge' => 'Bebas Percaloan', 'question' => 'Pelayanan KIA bebas dari praktik percaloan atau perantara tidak resmi yang menjanjikan percepatan layanan.'],
];

$likert6Labels = [
    1 => 'Sangat Tidak Setuju',
    2 => 'Tidak Setuju',
    3 => 'Kurang Setuju',
    4 => 'Cukup Setuju',
    5 => 'Setuju',
    6 => 'Sangat Setuju',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Survei Kepuasan Pelayanan Terintegrasi — SIPRIMA</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/ajukan_permohonan.css?v=20260927-2">
  <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <style>
    .survei-wrapper {
      max-width: 820px;
      margin: 0 auto 48px auto;
    }
    .survei-hero {
      background: linear-gradient(135deg, #166534 0%, #15803d 60%, #16a34a 100%);
      color: #ffffff;
      border-radius: 16px;
      padding: 20px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      box-shadow: 0 10px 25px rgba(22, 101, 52, 0.18);
      margin-bottom: 18px;
    }
    .survei-hero-left {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .survei-hero-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.18);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      flex-shrink: 0;
    }
    .survei-hero h2 {
      font-size: 18px;
      font-weight: 700;
      margin: 0 0 2px 0;
      color: #ffffff;
    }
    .survei-hero p {
      font-size: 12.5px;
      margin: 0;
      color: #dcfce7;
    }
    .survei-stepper {
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 10px;
      background: #ffffff;
      border-radius: 14px;
      padding: 12px 18px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
      border: 1px solid #e2e8f0;
      margin-bottom: 20px;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
      white-space: nowrap;
    }
    .survei-step-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 8px 16px;
      min-height: 38px;
      border-radius: 9999px;
      font-size: 13px;
      font-weight: 600;
      line-height: 1;
      color: #64748b;
      background: #f8fafc;
      border: 1.5px solid #e2e8f0;
      white-space: nowrap;
      cursor: pointer;
      box-sizing: border-box;
      flex-shrink: 0;
      transition: all 0.2s ease;
    }
    .survei-step-pill .step-num {
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: #e2e8f0;
      color: #475569;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 11.5px;
      font-weight: 700;
    }
    .survei-step-pill.active {
      background: #166534;
      color: #ffffff;
      box-shadow: 0 4px 10px rgba(22, 101, 52, 0.22);
    }
    .survei-step-pill.active .step-num {
      background: #ffffff;
      color: #166534;
    }
    .survei-step-pill.done {
      background: #dcfce7;
      color: #166534;
      border-color: #86efac;
    }
    .survei-step-pill.done .step-num {
      background: #16a34a;
      color: #ffffff;
    }
    .survei-card {
      background: #ffffff;
      border-radius: 16px;
      padding: 26px 28px;
      box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06);
      border: 1px solid #e2e8f0;
    }
    .survei-section-header {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-radius: 12px;
      padding: 16px 20px;
      margin-bottom: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .survei-section-header h3 {
      margin: 0 0 4px 0;
      font-size: 16.5px;
      font-weight: 700;
      color: #166534;
    }
    .survei-section-header p {
      margin: 0;
      font-size: 12.5px;
      color: #15803d;
      line-height: 1.45;
    }
    .survei-counter-badge {
      background: #dcfce7;
      color: #166534;
      border: 1px solid #86efac;
      padding: 5px 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }
    .survei-form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    .survei-form-group {
      margin-bottom: 16px;
    }
    .survei-form-group.full {
      grid-column: 1 / -1;
    }
    .survei-label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 6px;
    }
    .survei-input, .survei-select, .survei-textarea {
      width: 100%;
      padding: 11px 14px;
      border-radius: 10px;
      border: 1.5px solid #cbd5e1;
      font-size: 13.5px;
      font-family: inherit;
      color: #0f172a;
      background: #f8fafc;
      box-sizing: border-box;
      transition: border-color 0.2s, background 0.2s;
    }
    .survei-input:focus, .survei-select:focus, .survei-textarea:focus {
      outline: none;
      border-color: #16a34a;
      background: #ffffff;
    }
    .gender-toggle-group {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }
    .gender-btn {
      padding: 10px 14px;
      border-radius: 10px;
      border: 1.5px solid #cbd5e1;
      background: #f8fafc;
      font-weight: 600;
      font-size: 13.5px;
      color: #334155;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.18s;
    }
    .gender-btn.selected {
      border-color: #16a34a;
      background: #dcfce7;
      color: #166534;
    }
    .q-card {
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 14px;
      padding: 18px 20px;
      margin-bottom: 14px;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .q-card.answered {
      border-color: #86efac;
      background: #fcfffe;
    }
    .q-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 8px;
      flex-wrap: wrap;
    }
    .q-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11.5px;
      font-weight: 700;
      background: #eff6ff;
      color: #1d4ed8;
    }
    .q-badge.spkp {
      background: #f0fdfa;
      color: #0f766e;
    }
    .q-badge.spak {
      background: #dcfce7;
      color: #15803d;
    }
    .q-anti-korupsi-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 9px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      background: #bbf7d0;
      color: #166534;
    }
    .q-text {
      font-size: 14px;
      font-weight: 600;
      color: #1e293b;
      margin: 0 0 14px 0;
      line-height: 1.45;
    }
    .skm-options-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }
    .skm-opt-btn {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 11px 14px;
      border-radius: 10px;
      border: 1.5px solid #cbd5e1;
      background: #f8fafc;
      cursor: pointer;
      text-align: left;
      font-family: inherit;
      font-size: 13px;
      font-weight: 600;
      color: #334155;
      transition: all 0.16s ease;
    }
    .skm-opt-btn:hover {
      border-color: #93c5fd;
      background: #eff6ff;
    }
    .skm-opt-btn .opt-score {
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: #e2e8f0;
      color: #334155;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 12px;
      flex-shrink: 0;
    }
    .skm-opt-btn.selected {
      border-color: #2563eb;
      background: #eff6ff;
      color: #1e40af;
      box-shadow: 0 2px 8px rgba(37, 99, 235, 0.14);
    }
    .skm-opt-btn.selected .opt-score {
      background: #2563eb;
      color: #ffffff;
    }
    .likert6-grid {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 8px;
    }
    .likert6-btn {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 10px 6px;
      border-radius: 10px;
      border: 1.5px solid #cbd5e1;
      background: #f8fafc;
      cursor: pointer;
      font-family: inherit;
      transition: all 0.16s ease;
    }
    .likert6-btn .l-num {
      font-size: 15px;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 3px;
    }
    .likert6-btn .l-lbl {
      font-size: 10.5px;
      font-weight: 500;
      color: #64748b;
      text-align: center;
      line-height: 1.2;
    }
    .likert6-btn.selected-spkp {
      border-color: #0284c7;
      background: #e0f2fe;
      box-shadow: 0 2px 8px rgba(2, 132, 199, 0.16);
    }
    .likert6-btn.selected-spkp .l-num,
    .likert6-btn.selected-spkp .l-lbl {
      color: #0369a1;
      font-weight: 700;
    }
    .likert6-btn.selected-spak {
      border-color: #16a34a;
      background: #dcfce7;
      box-shadow: 0 2px 8px rgba(22, 163, 74, 0.16);
    }
    .likert6-btn.selected-spak .l-num,
    .likert6-btn.selected-spak .l-lbl {
      color: #166534;
      font-weight: 700;
    }
    .survei-nav-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-top: 22px;
      padding-top: 18px;
      border-top: 1px solid #e2e8f0;
    }
    .btn-survei-prev {
      padding: 11px 20px;
      border-radius: 10px;
      border: 1.5px solid #cbd5e1;
      background: #ffffff;
      color: #334155;
      font-weight: 600;
      font-size: 13.5px;
      cursor: pointer;
    }
    .btn-survei-next {
      padding: 11px 24px;
      border-radius: 10px;
      border: none;
      background: #16a34a;
      color: #ffffff;
      font-weight: 700;
      font-size: 13.5px;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
    }
    .btn-survei-next:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    @media (max-width: 640px) {
      .survei-form-grid, .skm-options-grid {
        grid-template-columns: 1fr;
      }
      .likert6-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }
  </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-14">
    <link rel="stylesheet" href="css/survei_siprima.css?v=20261119-1">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>
  <div class="layout">
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <main class="main">
      <header class="header user-general-header">
        <div class="left-header user-header-brand">
          <span class="user-header-brand__icon" aria-hidden="true">📋</span>
          <div class="user-header-brand__copy">
            <h1>Survei Kepuasan Pelayanan</h1>
            <p>Instrumen Terintegrasi SKM, SPKP, dan SPAK — SIPRIMA</p>
          </div>
        </div>
        <div class="user-info">
          Hai, <?= htmlspecialchars($_SESSION['nama'] ?? 'User') ?>
          <button class="logout" id="logoutBtnTop" onclick="window.location='../logout.php'">Logout</button>
        </div>
      </header>

      <section class="content" style="padding-top: 20px;">
        <div class="survei-wrapper">

          <!-- Banner Header Survei -->
          <div class="survei-hero">
            <div class="survei-hero-left">
              <div class="survei-hero-icon">🏛️</div>
              <div>
                <h2>Survei Kepuasan Pelayanan Terintegrasi</h2>
                <p>SKM • SPKP • SPAK — Dinas Kependudukan dan Pencatatan Sipil</p>
              </div>
            </div>
            <div style="text-align: right; font-size: 12px; background: rgba(255,255,255,0.16); padding: 6px 12px; border-radius: 999px; font-weight: 600;">
              <?= htmlspecialchars($noAntrianDefault) ?>
            </div>
          </div>

          <?php if ($idPengajuan <= 0): ?>
            <div style="background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: 16px 20px; border-radius: 14px; margin-bottom: 18px;">
              <strong>Belum Ada Permohonan KIA Terdaftar</strong>
              <p style="margin: 6px 0 0 0; font-size: 13.5px;">Anda belum memiliki permohonan Kartu Identitas Anak (KIA). Silakan ajukan permohonan terlebih dahulu sebelum mengisi survei layanan KIA.</p>
              <div style="margin-top: 12px;">
                <a href="kia.php" style="display: inline-block; padding: 9px 16px; background: #16a34a; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 13px;">Ajukan Permohonan KIA</a>
                <a href="status_permohonan.php" style="display: inline-block; padding: 9px 16px; background: #e2e8f0; color: #334155; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 13px; margin-left: 8px;">Kembali ke Status Permohonan</a>
              </div>
            </div>
          <?php endif; ?>

          <!-- Stepper Survei KIA -->
          <div class="survei-stepper" id="surveiStepper">
            <button type="button" class="survei-step-pill active" data-step="1" onclick="goToSurveiStep(1)">
              <span class="step-num">1</span> Info Layanan
            </button>
            <button type="button" class="survei-step-pill" data-step="2" onclick="goToSurveiStep(2)">
              <span class="step-num">2</span> Identitas Pemohon
            </button>
            <button type="button" class="survei-step-pill" data-step="3" onclick="goToSurveiStep(3)">
              <span class="step-num">3</span> SKM
            </button>
            <button type="button" class="survei-step-pill" data-step="4" onclick="goToSurveiStep(4)">
              <span class="step-num">4</span> SPKP
            </button>
            <button type="button" class="survei-step-pill" data-step="5" onclick="goToSurveiStep(5)">
              <span class="step-num">5</span> SPAK
            </button>
            <button type="button" class="survei-step-pill" data-step="6" onclick="goToSurveiStep(6)">
              <span class="step-num">6</span> Saran &amp; Masukan
            </button>
          </div>

          <div id="surveiAlertBox" style="display:none; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13.5px; font-weight: 600;"></div>

          <form id="formSurveiTerintegrasi" onsubmit="return false;">
            <?= csrf_input() ?>
            <input type="hidden" id="survei_id_pengajuan" value="<?= (int)$idPengajuan ?>">

            <!-- ========================================================== -->
            <!-- STEP 1: INFO LAYANAN                                       -->
            <!-- ========================================================== -->
            <div class="survei-step-panel" id="surveiStep1">
              <div class="survei-card">
                <div class="survei-section-header">
                  <div>
                    <h3>Informasi Penerimaan Layanan</h3>
                    <p>Pastikan detail layanan yang baru saja Anda terima sudah sesuai.</p>
                  </div>
                </div>

                <div class="survei-form-group">
                  <label class="survei-label" for="info_jenis_layanan">Jenis Layanan <span style="color:#dc2626">*</span></label>
                  <select id="info_jenis_layanan" class="survei-select">
                    <option value="Kartu Identitas Anak (KIA)" selected>Kartu Identitas Anak (KIA)</option>
                    <option value="Kartu Keluarga (KK)">Kartu Keluarga (KK)</option>
                    <option value="KTP Elektronik (KTP-el)">KTP Elektronik (KTP-el)</option>
                    <option value="Akta Kelahiran">Akta Kelahiran</option>
                    <option value="Surat Pindah (SKPWNI)">Surat Pindah (SKPWNI)</option>
                  </select>
                </div>

                <div class="survei-form-grid">
                  <div class="survei-form-group">
                    <label class="survei-label" for="info_tanggal_layanan">Tanggal Pelayanan <span style="color:#dc2626">*</span></label>
                    <input type="date" id="info_tanggal_layanan" class="survei-input" value="<?= htmlspecialchars($tanggalLayananDefault) ?>" required>
                  </div>
                  <div class="survei-form-group">
                    <label class="survei-label" for="info_no_antrian">Nomor Antrian (Opsional)</label>
                    <input type="text" id="info_no_antrian" class="survei-input" value="<?= htmlspecialchars($noAntrianDefault) ?>" placeholder="Contoh: #KIA-001">
                  </div>
                </div>

                <div class="survei-form-group">
                  <label class="survei-label" for="info_loket_layanan">Loket Pelayanan</label>
                  <select id="info_loket_layanan" class="survei-select">
                    <option value="Loket 2 - Pelayanan Anak & KIA" selected>Loket 2 - Pelayanan Anak &amp; KIA</option>
                    <option value="Loket 1 - Pelayanan KK & KTP-el">Loket 1 - Pelayanan KK &amp; KTP-el</option>
                    <option value="Loket 3 - Pencatatan Sipil & Akta">Loket 3 - Pencatatan Sipil &amp; Akta</option>
                    <option value="Loket Online SIPRIMA">Loket Online SIPRIMA</option>
                  </select>
                </div>

                <div class="survei-nav-footer" style="justify-content: flex-end;">
                  <button type="button" class="btn-survei-next" onclick="goToSurveiStep(2)">Lanjut &rarr;</button>
                </div>
              </div>
            </div>

            <!-- ========================================================== -->
            <!-- STEP 2: PROFIL RESPONDEN                                   -->
            <!-- ========================================================== -->
            <div class="survei-step-panel" id="surveiStep2" style="display: none;">
              <div class="survei-card">
                <div class="survei-section-header">
                  <div>
                    <h3>Identitas Pemohon</h3>
                    <p>Lengkapi dan pastikan identitas pemohon sesuai untuk keperluan validasi survei kepuasan pelayanan KIA.</p>
                  </div>
                </div>

                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 12px 16px; border-radius: 10px; font-size: 12.5px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px;">
                  <span style="font-size: 18px;">🔒</span>
                  <span>Data Anda aman dan dirahasiakan. Informasi ini hanya digunakan untuk analisis statistik peningkatan kualitas pelayanan publik.</span>
                </div>

                <div class="survei-form-grid">
                  <div class="survei-form-group full">
                    <label class="survei-label" for="resp_nama">Nama Lengkap <span style="color:#dc2626">*</span></label>
                    <input type="text" id="resp_nama" class="survei-input" value="<?= htmlspecialchars((string)($dataUser['nama'] ?? $_SESSION['nama'] ?? '')) ?>" readonly style="background:#f1f5f9;cursor:not-allowed;" required>
                  </div>

                  <div class="survei-form-group">
                    <label class="survei-label" for="resp_email">Email <span style="color:#dc2626">*</span></label>
                    <input type="email" id="resp_email" class="survei-input" value="<?= htmlspecialchars((string)($dataUser['email'] ?? $_SESSION['email'] ?? '')) ?>" readonly style="background:#f1f5f9;cursor:not-allowed;" required>
                  </div>

                  <div class="survei-form-group">
                    <label class="survei-label" for="resp_hp">No. HP / WhatsApp <span style="color:#dc2626">*</span></label>
                    <input type="tel" id="resp_hp" class="survei-input" value="" placeholder="Contoh: 081234567890" required>
                  </div>

                  <div class="survei-form-group full">
                    <label class="survei-label" for="resp_alamat">Alamat Lengkap <span style="color:#dc2626">*</span></label>
                    <textarea id="resp_alamat" class="survei-textarea" rows="2" readonly style="background:#f1f5f9;cursor:not-allowed;" required><?= htmlspecialchars((string)($dataUser['alamat'] ?? $_SESSION['alamat'] ?? '-')) ?></textarea>
                  </div>

                  <div class="survei-form-group">
                    <label class="survei-label">Jenis Kelamin <span style="color:#dc2626">*</span></label>
                    <input type="hidden" id="resp_jk" value="">
                    <div class="gender-toggle-group">
                      <button type="button" class="gender-btn" id="btnJkL" onclick="selectGender('L')">♂ Laki-Laki</button>
                      <button type="button" class="gender-btn" id="btnJkP" onclick="selectGender('P')">♀ Perempuan</button>
                    </div>
                  </div>

                  <div class="survei-form-group">
                    <label class="survei-label" for="resp_usia">Usia (Tahun) <span style="color:#dc2626">*</span></label>
                    <input type="number" id="resp_usia" class="survei-input" min="15" max="120" value="" placeholder="Masukkan usia (tahun)" required>
                  </div>

                  <div class="survei-form-group">
                    <label class="survei-label" for="resp_pendidikan">Pendidikan Terakhir <span style="color:#dc2626">*</span></label>
                    <select id="resp_pendidikan" class="survei-select" required>
                      <option value="">-- Pilih Pendidikan Terakhir --</option>
                      <option value="SD">SD</option>
                      <option value="SMP">SMP</option>
                      <option value="SMA/SMK">SMA/SMK</option>
                      <option value="Diploma">Diploma</option>
                      <option value="S1">S1</option>
                      <option value="S2">S2</option>
                      <option value="S3">S3</option>
                      <option value="Lainnya">Lainnya</option>
                    </select>
                  </div>

                  <div class="survei-form-group">
                    <label class="survei-label" for="resp_pekerjaan">Pekerjaan <span style="color:#dc2626">*</span></label>
                    <input type="text" id="resp_pekerjaan" class="survei-input" value="" placeholder="Contoh: PNS / Wiraswasta / Pegawai Swasta / IRT" required>
                  </div>
                </div>

                <div class="survei-nav-footer">
                  <button type="button" class="btn-survei-prev" onclick="goToSurveiStep(1)">&larr; Kembali</button>
                  <button type="button" class="btn-survei-next" onclick="goToSurveiStep(3)">Lanjut ke Survei &rarr;</button>
                </div>
              </div>
            </div>

            <!-- ========================================================== -->
            <!-- STEP 3: SURVEI KEPUASAN MASYARAKAT (SKM - 9 UNSUR)         -->
            <!-- ========================================================== -->
            <div class="survei-step-panel" id="surveiStep3" style="display: none;">
              <div class="survei-card">
                <div class="survei-section-header">
                  <div>
                    <h3>Survei Kepuasan Masyarakat (SKM)</h3>
                    <p>Bagian 1 dari 3 • Berdasarkan PermenpanRB No. 14 Tahun 2017 (9 Unsur Pelayanan)</p>
                  </div>
                  <span id="skmCounterBadge" class="survei-counter-badge">0 / 9 terjawab</span>
                </div>

                <?php foreach ($skmQuestions as $idx => $q): ?>
                  <div class="q-card" id="card_skm_<?= $q['code'] ?>">
                    <div class="q-header">
                      <span class="q-badge"><?= ($idx + 1) ?>. <?= htmlspecialchars($q['badge']) ?></span>
                      <span style="font-size:11.5px; color:#64748b; font-weight:600;">Kode: <?= $q['code'] ?></span>
                    </div>
                    <p class="q-text"><?= htmlspecialchars($q['question']) ?></p>
                    <div class="skm-options-grid">
                      <?php foreach ($q['options'] as $score => $label): ?>
                        <button type="button"
                                class="skm-opt-btn"
                                data-qcode="<?= $q['code'] ?>"
                                data-score="<?= $score ?>"
                                onclick="selectSkmAnswer('<?= $q['code'] ?>', <?= $score ?>, this)">
                          <span class="opt-score"><?= $score ?></span>
                          <span><?= htmlspecialchars($label) ?></span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>

                <div class="survei-nav-footer">
                  <button type="button" class="btn-survei-prev" onclick="goToSurveiStep(2)">&larr; Sebelumnya</button>
                  <button type="button" class="btn-survei-next" onclick="goToSurveiStep(4)">Selanjutnya &rarr;</button>
                </div>
              </div>
            </div>

            <!-- ========================================================== -->
            <!-- STEP 4: SURVEI PERSEPSI KUALITAS PELAYANAN (SPKP - 8 ITEM) -->
            <!-- ========================================================== -->
            <div class="survei-step-panel" id="surveiStep4" style="display: none;">
              <div class="survei-card">
                <div class="survei-section-header">
                  <div>
                    <h3>Survei Persepsi Kualitas Pelayanan (SPKP)</h3>
                    <p>Bagian 2 dari 3 • Skala 1 - 6 (1 = Sangat Tidak Setuju s/d 6 = Sangat Setuju)</p>
                  </div>
                  <span id="spkpCounterBadge" class="survei-counter-badge">0 / 8 terjawab</span>
                </div>

                <?php foreach ($spkpQuestions as $idx => $q): ?>
                  <div class="q-card" id="card_spkp_<?= $q['code'] ?>">
                    <div class="q-header">
                      <span class="q-badge spkp"><?= ($idx + 1) ?>. <?= htmlspecialchars($q['badge']) ?></span>
                      <span style="font-size:11.5px; color:#64748b; font-weight:600;">Kode: <?= $q['code'] ?></span>
                    </div>
                    <p class="q-text"><?= htmlspecialchars($q['question']) ?></p>
                    <div class="likert6-grid">
                      <?php foreach ($likert6Labels as $score => $lbl): ?>
                        <button type="button"
                                class="likert6-btn"
                                data-qcode="<?= $q['code'] ?>"
                                data-score="<?= $score ?>"
                                onclick="selectSpkpAnswer('<?= $q['code'] ?>', <?= $score ?>, this)">
                          <span class="l-num"><?= $score ?></span>
                          <span class="l-lbl"><?= htmlspecialchars($lbl) ?></span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>

                <div class="survei-nav-footer">
                  <button type="button" class="btn-survei-prev" onclick="goToSurveiStep(3)">&larr; Sebelumnya</button>
                  <button type="button" class="btn-survei-next" onclick="goToSurveiStep(5)">Selanjutnya &rarr;</button>
                </div>
              </div>
            </div>

            <!-- ========================================================== -->
            <!-- STEP 5: SURVEI PERSEPSI ANTI KORUPSI (SPAK - 5 ITEM)       -->
            <!-- ========================================================== -->
            <div class="survei-step-panel" id="surveiStep5" style="display: none;">
              <div class="survei-card">
                <div class="survei-section-header">
                  <div>
                    <h3>Survei Persepsi Anti Korupsi (SPAK)</h3>
                    <p>Bagian 3 dari 3 • Skala 1 - 6 • Zona Integritas Menuju WBK / WBBM</p>
                  </div>
                  <span id="spakCounterBadge" class="survei-counter-badge">0 / 5 terjawab</span>
                </div>

                <?php foreach ($spakQuestions as $idx => $q): ?>
                  <div class="q-card" id="card_spak_<?= $q['code'] ?>">
                    <div class="q-header">
                      <span class="q-badge spak"><?= ($idx + 1) ?>. <?= htmlspecialchars($q['badge']) ?></span>
                      <span class="q-anti-korupsi-pill">🛡️ Bebas Korupsi</span>
                    </div>
                    <p class="q-text"><?= htmlspecialchars($q['question']) ?></p>
                    <div class="likert6-grid">
                      <?php foreach ($likert6Labels as $score => $lbl): ?>
                        <button type="button"
                                class="likert6-btn"
                                data-qcode="<?= $q['code'] ?>"
                                data-score="<?= $score ?>"
                                onclick="selectSpakAnswer('<?= $q['code'] ?>', <?= $score ?>, this)">
                          <span class="l-num"><?= $score ?></span>
                          <span class="l-lbl"><?= htmlspecialchars($lbl) ?></span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>

                <div class="survei-nav-footer">
                  <button type="button" class="btn-survei-prev" onclick="goToSurveiStep(4)">&larr; Sebelumnya</button>
                  <button type="button" class="btn-survei-next" onclick="goToSurveiStep(6)">Selanjutnya &rarr;</button>
                </div>
              </div>
            </div>

            <!-- ========================================================== -->
            <!-- STEP 6: SARAN & MASUKAN + SUBMIT                           -->
            <!-- ========================================================== -->
            <div class="survei-step-panel" id="surveiStep6" style="display: none;">
              <div class="survei-card">
                <div class="survei-section-header">
                  <div>
                    <h3>Saran &amp; Masukan Peningkatan Layanan</h3>
                    <p>Opsional • Masukan Anda sangat berharga untuk menyempurnakan layanan KIA SIPRIMA</p>
                  </div>
                </div>
                <div class="survei-form-group">
                  <label class="survei-label" for="saran_skm">Saran untuk SKM (Kepuasan Masyarakat)</label>
                  <textarea id="saran_skm" class="survei-textarea" rows="2" placeholder="Tuliskan saran terkait persyaratan, prosedur, atau fasilitas pelayanan..."></textarea>
                </div>

                <div class="survei-form-group">
                  <label class="survei-label" for="saran_spkp">Saran untuk SPKP (Kualitas Pelayanan)</label>
                  <textarea id="saran_spkp" class="survei-textarea" rows="2" placeholder="Tuliskan saran terkait ketepatan waktu, kualitas dokumen KIA, atau keramahan petugas..."></textarea>
                </div>

                <div class="survei-form-group">
                  <label class="survei-label" for="saran_spak">Saran untuk SPAK (Anti Korupsi &amp; Integritas)</label>
                  <textarea id="saran_spak" class="survei-textarea" rows="2" placeholder="Tuliskan masukan terkait transparansi dan integritas pelayanan bebas pungli..."></textarea>
                </div>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; margin-top:14px; font-size:13px; color:#334155;">
                  <strong>Ringkasan Pengisian Instrumen:</strong>
                  <div style="display:flex; gap:16px; margin-top:8px; flex-wrap:wrap;">
                    <span id="summarySkmStatus">• SKM: 0/9</span>
                    <span id="summarySpkpStatus">• SPKP: 0/8</span>
                    <span id="summarySpakStatus">• SPAK: 0/5</span>
                  </div>
                </div>

                <div class="survei-nav-footer">
                  <button type="button" class="btn-survei-prev" onclick="goToSurveiStep(5)">&larr; Kembali</button>
                  <button type="button" class="btn-survei-next" id="btnSubmitSurveiAll" onclick="submitIntegratedSurvei()">Kirim Survei ✓</button>
                </div>
              </div>
            </div>
          </form>

        </div>
      </section>
    </main>
  </div>

  <!-- MODAL SUKSES SURVEI TERKIRIM -->
  <div id="surveiSuccessModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; max-width:430px; width:100%; border-radius:18px; padding:28px 24px; text-align:center; box-shadow:0 20px 45px rgba(0,0,0,0.22);">
      <div style="width:68px; height:68px; border-radius:50%; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto; font-size:34px; font-weight:700;">✓</div>
      <h3 style="margin:0 0 8px 0; font-size:20px; color:#0f172a;">Survey Berhasil Terkirim!</h3>
      <p style="margin:0 0 20px 0; font-size:13.5px; color:#475569; line-height:1.55;">
        Terima kasih atas partisipasi Anda dalam mengisi Survei Kepuasan Pelayanan Terintegrasi (SKM, SPKP, dan SPAK). Penilaian Anda sangat berarti bagi peningkatan layanan SIPRIMA.
      </p>
      <a href="status_permohonan.php" style="display:block; width:100%; padding:12px 16px; border-radius:10px; background:#16a34a; color:#fff; font-weight:700; font-size:14px; text-decoration:none; box-sizing:border-box;">
        Kembali ke Beranda
      </a>
    </div>
  </div>

  <script>
    let currentSurveiStep = 1;
    const answersSkm = {};
    const answersSpkp = {};
    const answersSpak = {};
    const completedToday = <?= json_encode($progress['survei_selesai'] ?? []) ?>;

    function showSurveiAlert(msg, type = 'error') {
      const box = document.getElementById('surveiAlertBox');
      if (!box) return;
      box.style.display = 'block';
      if (type === 'error') {
        box.style.background = '#fef2f2';
        box.style.border = '1px solid #fecaca';
        box.style.color = '#b91c1c';
      } else {
        box.style.background = '#dcfce7';
        box.style.border = '1px solid #86efac';
        box.style.color = '#166534';
      }
      box.textContent = msg;
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function hideSurveiAlert() {
      const box = document.getElementById('surveiAlertBox');
      if (box) box.style.display = 'none';
    }

    function selectGender(g) {
      document.getElementById('resp_jk').value = g;
      document.getElementById('btnJkL').classList.toggle('selected', g === 'L');
      document.getElementById('btnJkP').classList.toggle('selected', g === 'P');
    }

    function selectSkmAnswer(code, score, btnEl) {
      answersSkm[code] = score;
      const card = document.getElementById('card_skm_' + code);
      if (card) {
        card.classList.add('answered');
        card.querySelectorAll('.skm-opt-btn').forEach(b => b.classList.remove('selected'));
      }
      btnEl.classList.add('selected');
      updateCounters();
    }

    function selectSpkpAnswer(code, score, btnEl) {
      answersSpkp[code] = score;
      const card = document.getElementById('card_spkp_' + code);
      if (card) {
        card.classList.add('answered');
        card.querySelectorAll('.likert6-btn').forEach(b => b.classList.remove('selected-spkp'));
      }
      btnEl.classList.add('selected-spkp');
      updateCounters();
    }

    function selectSpakAnswer(code, score, btnEl) {
      answersSpak[code] = score;
      const card = document.getElementById('card_spak_' + code);
      if (card) {
        card.classList.add('answered');
        card.querySelectorAll('.likert6-btn').forEach(b => b.classList.remove('selected-spak'));
      }
      btnEl.classList.add('selected-spak');
      updateCounters();
    }

    function updateCounters() {
      const cSkm = Object.keys(answersSkm).length;
      const cSpkp = Object.keys(answersSpkp).length;
      const cSpak = Object.keys(answersSpak).length;
      document.getElementById('skmCounterBadge').textContent = cSkm + ' / 9 terjawab';
      document.getElementById('spkpCounterBadge').textContent = cSpkp + ' / 8 terjawab';
      document.getElementById('spakCounterBadge').textContent = cSpak + ' / 5 terjawab';
      document.getElementById('summarySkmStatus').textContent = '• SKM: ' + cSkm + '/9 terjawab';
      document.getElementById('summarySpkpStatus').textContent = '• SPKP: ' + cSpkp + '/8 terjawab';
      document.getElementById('summarySpakStatus').textContent = '• SPAK: ' + cSpak + '/5 terjawab';
    }

    function validateStepBeforeMove(targetStep) {
      if (targetStep <= currentSurveiStep) return true;

      if (currentSurveiStep === 2 || targetStep > 2) {
        const nama = document.getElementById('resp_nama').value.trim();
        const hp = document.getElementById('resp_hp').value.trim();
        const email = document.getElementById('resp_email').value.trim();
        const jk = document.getElementById('resp_jk').value.trim();
        const usia = parseInt(document.getElementById('resp_usia').value, 10);
        const pendidikan = document.getElementById('resp_pendidikan').value;
        const pekerjaan = document.getElementById('resp_pekerjaan').value.trim();
        const alamat = document.getElementById('resp_alamat').value.trim();

        if (!nama || !hp || !email || !jk || !pendidikan || !pekerjaan || !alamat || isNaN(usia) || usia < 1 || usia > 120) {
          showSurveiAlert('Mohon lengkapi seluruh data Profil Responden (No. HP, Jenis Kelamin, Usia, Pendidikan Terakhir, dan Pekerjaan wajib diisi manual).');
          return false;
        }
      }

      if (currentSurveiStep === 3 && targetStep > 3 && Object.keys(answersSkm).length < 9) {
        showSurveiAlert('Mohon jawab seluruh 9 pertanyaan pada bagian SKM terlebih dahulu.');
        return false;
      }

      if (currentSurveiStep === 4 && targetStep > 4 && Object.keys(answersSpkp).length < 8) {
        showSurveiAlert('Mohon jawab seluruh 8 pernyataan pada bagian SPKP terlebih dahulu.');
        return false;
      }

      if (currentSurveiStep === 5 && targetStep > 5 && Object.keys(answersSpak).length < 5) {
        showSurveiAlert('Mohon jawab seluruh 5 pernyataan pada bagian SPAK terlebih dahulu.');
        return false;
      }

      return true;
    }

    function goToSurveiStep(step) {
      hideSurveiAlert();
      if (!validateStepBeforeMove(step)) return;

      currentSurveiStep = step;
      for (let i = 1; i <= 6; i++) {
        const panel = document.getElementById('surveiStep' + i);
        if (panel) panel.style.display = (i === step) ? 'block' : 'none';
      }

      document.querySelectorAll('#surveiStepper .survei-step-pill').forEach(pill => {
        const s = parseInt(pill.getAttribute('data-step'), 10);
        pill.classList.remove('active', 'done');
        if (s === step) pill.classList.add('active');
        else if (s < step) pill.classList.add('done');
      });

      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function submitIntegratedSurvei() {
      hideSurveiAlert();
      const idPengajuan = parseInt(document.getElementById('survei_id_pengajuan').value, 10);
      if (!idPengajuan || idPengajuan <= 0) {
        showSurveiAlert('ID Pengajuan KIA tidak ditemukan. Silakan ajukan permohonan KIA terlebih dahulu.');
        return;
      }

      if (Object.keys(answersSkm).length < 9) {
        goToSurveiStep(3);
        showSurveiAlert('Mohon lengkapi seluruh 9 pertanyaan SKM sebelum mengirim survei.');
        return;
      }
      if (Object.keys(answersSpkp).length < 8) {
        goToSurveiStep(4);
        showSurveiAlert('Mohon lengkapi seluruh 8 pernyataan SPKP sebelum mengirim survei.');
        return;
      }
      if (Object.keys(answersSpak).length < 5) {
        goToSurveiStep(5);
        showSurveiAlert('Mohon lengkapi seluruh 5 pernyataan SPAK sebelum mengirim survei.');
        return;
      }

      const responden = {
        nama_lengkap: document.getElementById('resp_nama').value.trim(),
        no_hp: document.getElementById('resp_hp').value.trim(),
        email: document.getElementById('resp_email').value.trim(),
        jenis_kelamin: document.getElementById('resp_jk').value,
        usia: parseInt(document.getElementById('resp_usia').value, 10) || 30,
        pendidikan: document.getElementById('resp_pendidikan').value,
        pekerjaan: document.getElementById('resp_pekerjaan').value,
        alamat: document.getElementById('resp_alamat').value.trim()
      };

      const csrfTokenInput = document.querySelector('input[name="csrf_token"]');
      const csrfToken = csrfTokenInput ? csrfTokenInput.value : '';
      const btn = document.getElementById('btnSubmitSurveiAll');
      btn.disabled = true;
      btn.textContent = 'Mengirim Survei...';

      const saranSkm = (document.getElementById('saran_skm')?.value || '').trim();
      const saranSpkp = (document.getElementById('saran_spkp')?.value || '').trim();
      const saranSpak = (document.getElementById('saran_spak')?.value || '').trim();

      const payloads = [
        { jenis: 'skm', jawaban: answersSkm, saran: saranSkm },
        { jenis: 'spkp', jawaban: answersSpkp, saran: saranSpkp },
        { jenis: 'spak', jawaban: answersSpak, saran: saranSpak }
      ];

      try {
        for (const item of payloads) {
          const fd = new FormData();
          fd.append('action', 'submit_survei');
          fd.append('csrf_token', csrfToken);
          fd.append('id_pengajuan', String(idPengajuan));
          fd.append('jenis_survei', item.jenis);
          fd.append('responden', JSON.stringify(responden));
          fd.append('jawaban', JSON.stringify(item.jawaban));
          fd.append('saran', item.saran);

          const resp = await fetch('kia.php?action=submit_survei', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
          });
          const resJson = await resp.json();
          // Abaikan error duplikat harian jika salah satu instrumen sudah terisi sebelumnya hari ini
          if (!resJson.success && !(resJson.error && resJson.error.includes('sudah pernah diisi'))) {
            throw new Error(resJson.error || ('Gagal menyimpan survei ' + item.jenis.toUpperCase()));
          }
        }

        document.getElementById('surveiSuccessModal').style.display = 'flex';
      } catch (err) {
        showSurveiAlert(err.message || 'Terjadi kesalahan saat mengirim survei.');
        btn.disabled = false;
        btn.textContent = 'Kirim Survei ✓';
      }
    }
  </script>
  <script src="js/sidebar_Responsive.js"></script>
</body>
</html>
