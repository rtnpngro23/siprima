<?php
declare(strict_types=1);

require_once __DIR__ . '/../error_handler.php';
require_once __DIR__ . '/../../app/config/koneksi.php';
require_once __DIR__ . '/../../app/middleware/service_submission.php';
require_once __DIR__ . '/../../app/Models/KiaModel.php';
require_once __DIR__ . '/../../app/Controllers/KiaController.php';
require_once __DIR__ . '/../../app/Routes/KiaRouter.php';

use Siprima\Routes\KiaRouter;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$isJson = str_contains($contentType, 'application/json');
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || isset($_SERVER['HTTP_X_TESTING_BYPASS']);

// Tentukan apakah request merupakan API call atau view render HTML
$isApiCall = ($method === 'POST') || ($action !== '' && $action !== 'form') || $isJson || $isAjax;

// handle request router / api
if ($isApiCall) {
    siprima_auth_guard($koneksi);

    if (empty($_SESSION['id_user'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Sesi tidak valid atau telah berakhir. Silakan login kembali.'
        ]);
        exit;
    }

    $actor = [
        'id' => (int)$_SESSION['id_user'],
        'role' => (string)($_SESSION['role'] ?? 'user'),
        'nama' => (string)($_SESSION['nama'] ?? '')
    ];

    // Proteksi CSRF untuk request state-modifying (POST) dari browser
    if ($method === 'POST') {
        $isTest = isset($_SERVER['HTTP_X_TESTING_BYPASS']) && $_SERVER['HTTP_X_TESTING_BYPASS'] === 'siprima_test';
        if (!$isTest && !csrf_validate()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Sesi telah berakhir, silakan muat ulang halaman.'
            ]);
            exit;
        }
    }

    if ($action === '') {
        $action = ($method === 'POST') ? 'create' : 'list';
    }

    $input = array_merge($_GET, $_POST);

    if ($isJson) {
        $rawBody = file_get_contents('php://input');
        if ($rawBody) {
            $json = json_decode($rawBody, true);
            if (is_array($json)) {
                $input = array_merge($input, $json);
            }
        }
    }

    $files = $_FILES ?? [];

    $router = KiaRouter::createDefault($koneksi);
    $response = $router->dispatch($method, $action, $input, $files, $actor);

    // Jika browser atau client script mengakses download_pdf, stream file PDF fisik utuh
    if ($action === 'download_pdf') {
        $wantsJson = (!empty($input['format']) && strtolower((string)$input['format']) === 'json');
        if (!$wantsJson) {
            if ($response['status'] === 200 && !empty($response['data']['data']['file_path'])) {
                $pdfInfo = $response['data']['data'];
                $relPath = str_replace('\\', '/', ltrim((string)$pdfInfo['file_path'], '/\\'));
                $candidates = [
                    realpath(__DIR__ . '/../' . $relPath),
                    realpath(__DIR__ . '/../../' . $relPath),
                    realpath(__DIR__ . '/' . $relPath),
                    realpath(__DIR__ . '/../uploads/kia_hasil/' . basename($relPath)),
                    realpath(__DIR__ . '/../uploads/' . basename($relPath))
                ];
                $absPath = null;
                foreach ($candidates as $cand) {
                    if ($cand !== false && is_file($cand) && is_readable($cand)) {
                        $absPath = $cand;
                        break;
                    }
                }

                if ($absPath !== null) {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    while (ob_get_level() > 0) {
                        @ob_end_clean();
                    }
                    if (function_exists('ini_set')) {
                        @ini_set('zlib.output_compression', 'Off');
                    }
                    clearstatcache(true, $absPath);
                    $fileSize = filesize($absPath);
                    $isInline = !empty($input['preview']) || !empty($input['inline']) || (($input['mode'] ?? '') === 'preview') || (($input['disposition'] ?? '') === 'inline');
                    $disposition = $isInline ? 'inline' : 'attachment';
                    $permohonanId = !empty($input['id']) ? (int)$input['id'] : (!empty($pdfInfo['id']) ? (int)$pdfInfo['id'] : 0);
                    $rawName = trim((string)($pdfInfo['original_name'] ?? ''));
                    if ($rawName !== '' && preg_match('/^Hasil_KIA_\d+\.pdf$/i', $rawName)) {
                        $downloadName = $rawName;
                    } elseif ($permohonanId > 0) {
                        $downloadName = 'Hasil_KIA_' . $permohonanId . '.pdf';
                    } elseif ($rawName !== '') {
                        $downloadName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $rawName);
                        if (!str_ends_with(strtolower($downloadName), '.pdf')) {
                            $downloadName .= '.pdf';
                        }
                    } else {
                        $downloadName = 'Hasil_KIA.pdf';
                    }

                    if (!$isInline) {
                        header('Content-Description: File Transfer');
                        header('Content-Transfer-Encoding: binary');
                    }
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
                    if ($fileSize !== false) {
                        header('Content-Length: ' . $fileSize);
                    }
                    header('Accept-Ranges: bytes');
                    header('Access-Control-Expose-Headers: Content-Disposition, Content-Length');
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: private, max-age=0, must-revalidate');
                    header('Pragma: public');
                    header('Expires: 0');
                    readfile($absPath);
                    exit;
                }

                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo "Berkas PDF hasil KIA tidak ditemukan di server (" . htmlspecialchars(basename($relPath)) . ").";
                exit;
            }

            http_response_code($response['status'] ?? 404);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string)($response['data']['error'] ?? 'Berkas PDF hasil KIA belum dapat diakses.');
            exit;
        }
    }

    // Jika browser mengakses view_dokumen secara langsung (img src / iframe / link download), stream file fisik
    if ($action === 'view_dokumen') {
        if (!$isAjax && !$isJson) {
            if ($response['status'] === 200 && !empty($response['data']['data']['abs_path'])) {
                $docInfo = $response['data']['data'];
                $absPath = (string)$docInfo['abs_path'];
                if (is_file($absPath) && is_readable($absPath)) {
                    while (ob_get_level() > 0) {
                        @ob_end_clean();
                    }
                    $mime = (string)($docInfo['mime_type'] ?? 'application/octet-stream');
                    $isDownload = !empty($input['download']) && (string)$input['download'] === '1';
                    $disposition = $isDownload ? 'attachment' : 'inline';
                    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', (string)($docInfo['original_name'] ?? basename($absPath)));
                    header('Content-Type: ' . $mime);
                    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
                    header('Content-Length: ' . filesize($absPath));
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: private, max-age=300');
                    readfile($absPath);
                    exit;
                }

                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo "Berkas dokumen tidak ditemukan di server.";
                exit;
            }

            http_response_code($response['status'] ?? 404);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string)($response['data']['error'] ?? 'Dokumen tidak dapat diakses.');
            exit;
        }
    }

    // Jika browser mengakses export_survei_excel secara langsung (GET link), stream file .xlsx fisik
    if ($action === 'export_survei_excel' && !$isAjax && !$isJson && $response['status'] === 200 && !empty($response['data']['xlsx_base64'])) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $xlsxBytes = base64_decode((string)$response['data']['xlsx_base64']);
        $downloadName = (string)($response['data']['filename'] ?? 'Laporan_Survei_KIA.xlsx');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . strlen($xlsxBytes));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $xlsxBytes;
        exit;
    }

    http_response_code($response['status']);
    header('Content-Type: application/json');
    echo json_encode($response['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// render formulir pengajuan kia
siprima_auth_guard($koneksi);
require_once __DIR__ . '/user_header_brand.php';

if (empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'user') {
    switch ($_SESSION['role'] ?? '') {
        case 'admin':
            header('Location: ../admin/dashboard_admin.php');
            break;
        case 'operator':
            header('Location: dashboard_operator.php');
            break;
        default:
            header('Location: ../login.php');
    }
    exit;
}

service_hours_guard_submission();
service_hours_register_notice();

$userId = (int)$_SESSION['id_user'];

// Cek apakah halaman dibuka dalam mode Edit & Resubmit (?id=...)
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEditMode = false;
$existingPengajuan = null;
$existingAnakList = [];
$existingDokumenList = [];

$kiaModelInit = new \Siprima\Models\KiaModel($koneksi);
if ($editId > 0) {
    $detailExisting = $kiaModelInit->getPengajuanById($editId, $userId);
    if ($detailExisting && !empty($detailExisting['pengajuan'])) {
        $exSt = strtolower((string)($detailExisting['pengajuan']['status'] ?? ''));
        if ($exSt === 'selesai') {
            $_SESSION['error'] = 'Permohonan KIA yang sudah Selesai tidak dapat diedit kembali.';
            header('Location: status_permohonan.php');
            exit;
        }
        $isEditMode = true;
        $existingPengajuan = $detailExisting['pengajuan'];
        $existingAnakList = $detailExisting['anak'] ?? [];
        $existingDokumenList = $detailExisting['dokumen'] ?? [];
    }
}

// Cek apakah masih ada pengajuan KIA milik pemohon yang belum menyelesaikan survei wajib
$pendingMandatorySurvei = !$isEditMode ? $kiaModelInit->getPendingMandatorySurvei($userId) : null;

// Ambil data profil pemohon untuk auto-fill identitas dasar
$stmtUser = $koneksi->prepare("SELECT nama, nik, no_hp, email, id_kecamatan, id_desa FROM tb_users WHERE id = ?");
$stmtUser->bind_param("i", $userId);
$stmtUser->execute();
$dataUser = $stmtUser->get_result()->fetch_assoc() ?: [];

// Ambil daftar kecamatan untuk dropdown
$kecamatanList = [];
$resKec = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan ASC");
if ($resKec) {
    while ($row = $resKec->fetch_assoc()) {
        $kecamatanList[] = $row;
    }
}

// Pada pengajuan baru, pemohon wajib memilih Kecamatan terlebih dahulu, kemudian memilih Desa secara manual
$desaList = [];
$selectedKecamatan = $isEditMode ? (int)($existingPengajuan['id_kecamatan'] ?? 0) : 0;
$selectedDesa = $isEditMode ? (int)($existingPengajuan['id_desa'] ?? 0) : 0;
$selectedNamaKecamatan = '';
$selectedNamaDesa = '';

foreach ($kecamatanList as $kc) {
    if ((int)$kc['id'] === $selectedKecamatan) {
        $selectedNamaKecamatan = (string)$kc['nama_kecamatan'];
        break;
    }
}

if ($selectedKecamatan > 0) {
    $stmtDesa = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa ASC");
    $stmtDesa->bind_param("i", $selectedKecamatan);
    $stmtDesa->execute();
    $resDesa = $stmtDesa->get_result();
    while ($row = $resDesa->fetch_assoc()) {
        $desaList[] = $row;
        if ((int)$row['id'] === $selectedDesa) {
            $selectedNamaDesa = (string)$row['nama_desa'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Form Pengajuan Kartu Identitas Anak (KIA) - SIPRIMA</title>
  <link rel="stylesheet" href="css/kk.css?v=20260820-2">
  <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
  <link rel="stylesheet" href="css/kia.css?v=20261121-2">
  <link rel="stylesheet" href="../assets/css/form-kit.css?v=20260922-1">
  <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
  <script src="../assets/js/theme.js?v=20261118-1"></script>
  <style>
    .tema-form .form-card {
      padding: 32px 34px;
    }
    .tema-form .form-card > h2 {
      margin: 0 0 8px 0;
    }
    .tema-form .form-card > .subjudul {
      margin: 0 0 26px 0;
      line-height: 1.5;
    }
    .kia-form-section-title {
      font-size: 16px;
      font-weight: 700;
      color: #1f2937;
      margin: 28px 0 16px;
      padding-bottom: 8px;
      border-bottom: 1px solid var(--form-border, #fecdd3);
    }
    .kia-form-section-title:first-of-type {
      margin-top: 0;
    }
    .tema-form .form-grid-2 {
      gap: 20px;
      margin-bottom: 24px;
    }
    .kia-child-block {
      background: #ffffff;
      border: 1px solid var(--form-border, #e2e8f0);
      border-left: 4px solid var(--form-primary, #ff2d35);
      border-radius: 12px;
      padding: 22px;
      margin-bottom: 18px;
      box-shadow: 0 1px 2px rgba(15, 23, 42, .05);
    }
    .kia-child-block-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px dashed #e2e8f0;
    }
    .kia-child-block-title {
      font-size: 15px;
      font-weight: 700;
      color: #1f2937;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .kia-child-badge {
      display: inline-flex;
      align-items: center;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      background: #ffe4e6;
      color: #be123c;
    }
    .btn-add-child-inline {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border-radius: 8px;
      border: 1.5px solid var(--form-primary, #ff2d35);
      background: #ffffff;
      color: var(--form-primary, #ff2d35);
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .btn-add-child-inline:hover {
      background: var(--form-primary, #ff2d35);
      color: #ffffff;
    }
    .btn-remove-child-inline {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 6px 12px;
      border-radius: 8px;
      border: 1px solid #fecaca;
      background: #fef2f2;
      color: #dc2626;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
    }
    .btn-remove-child-inline:hover {
      background: #fee2e2;
    }
    .kia-section-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      margin: 28px 0 16px;
      padding-bottom: 8px;
      border-bottom: 1px solid var(--form-border, #fecdd3);
    }
    .kia-section-bar h3 {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
      color: #1f2937;
    }
    .kia-keterangan-wrap {
      margin-bottom: 28px;
    }
    .tema-form .f-doc-group {
      padding: 20px;
      margin-top: 16px;
      margin-bottom: 20px;
    }
    .tema-form .f-doc-group__judul {
      margin: 0 0 16px 0;
    }
    .tema-form .file-input-group {
      padding: 16px;
      margin-bottom: 16px;
    }
    .tema-form .form-actions {
      margin-top: 28px;
      gap: 14px;
    }
    .kia-file-title {
      font-size: 14px;
      font-weight: 700;
      color: #1f2937;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 8px;
    }
    .kia-badge-wajib {
      font-size: 11px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 999px;
      background: #fee2e2;
      color: #b91c1c;
    }
    .kia-badge-opsional {
      font-size: 11px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 999px;
      background: #e0f2fe;
      color: #0369a1;
    }
    .kia-field-error {
      display: none;
      margin-top: 6px;
      font-size: 12px;
      font-weight: 600;
      color: #dc2626;
    }
    .kia-preview-strip {
      display: none;
      align-items: center;
      gap: 10px;
      margin-top: 10px;
      padding: 8px 12px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
    }
    .kia-preview-strip img {
      width: 64px;
      height: 64px;
      object-fit: contain;
      background: #ffffff;
      border-radius: 4px;
      border: 1px solid #cbd5e1;
      flex-shrink: 0;
    }
    .kia-preview-strip.kia-preview-strip--pasfoto img {
      width: 72px;
      height: 90px;
      aspect-ratio: 4 / 5;
      object-fit: cover;
      object-position: center center;
      border-radius: 4px;
      border: 1px solid #94a3b8;
    }
    @media (max-width: 768px) {
      .tema-form .form-card {
        padding: 22px 18px;
      }
      .tema-form .form-grid-2 {
        gap: 16px;
      }
      .kia-child-block {
        padding: 16px;
      }
    }
  </style>
</head>
<body class="tema-form tema-line">

  <div class="layout">
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <main class="main">
      <header class="header user-general-header">
        <div class="left-header user-header-brand">
          <?php render_user_header_brand('Kartu Identitas Anak (KIA)'); ?>
        </div>
        <div class="user-info">
          Hai, <?= htmlspecialchars((string)($_SESSION['nama'] ?? $dataUser['nama'] ?? 'Pemohon')) ?>
          <?php include __DIR__ . '/notification_widget.php'; ?>
          <button class="logout" onclick="window.location.href='../logout.php'">Logout</button>
        </div>
      </header>

      <section class="content">
        <div class="form-card">
          <h2><?= $isEditMode ? 'Edit & Ajukan Kembali Kartu Identitas Anak (KIA)' : 'Form Pengajuan Kartu Identitas Anak (KIA)' ?></h2>
          <p class="subjudul">Lengkapi data anak dan unggah dokumen pendukung dalam satu halaman.</p>

          <?php if ($isEditMode && $existingPengajuan): ?>
          <div style="background: #fffbeb; border: 1px solid #fde68a; border-left: 5px solid #f59e0b; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
              <div style="font-size: 20px; line-height: 1;">⚠️</div>
              <div style="flex: 1;">
                <div style="font-weight: 700; color: #92400e; font-size: 0.95rem; margin-bottom: 4px;">
                  Permohonan Ditolak — Mode Perbaikan & Pengajuan Ulang (Versi <?= (int)($existingPengajuan['versi_aktif'] ?? 1) ?>)
                </div>
                <div style="color: #b45309; font-size: 0.875rem; line-height: 1.5;">
                  <strong>Catatan Penolakan Petugas:</strong>
                  <?= htmlspecialchars((string)($existingPengajuan['alasan_penolakan'] ?? 'Silakan periksa kembali kelengkapan data dan dokumen Anda.')) ?>
                </div>
                <div style="color: #78350f; font-size: 0.8rem; margin-top: 6px;">
                  Silakan perbarui data atau unggah ulang berkas yang perlu diperbaiki di bawah ini. Berkas lama yang sudah valid tetap tersimpan otomatis jika tidak diganti.
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <div id="globalAlert" class="error-box" style="display: none;"></div>

          <form id="kiaForm" method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_input() ?>
            <?php if ($isEditMode && $editId > 0): ?>
              <input type="hidden" id="editPengajuanId" name="id" value="<?= (int)$editId ?>">
            <?php endif; ?>

            <!-- identitas pemohon -->
            <h3 class="kia-form-section-title">1. Identitas Pemohon dan Wilayah</h3>
            <div class="form-grid-2">
              <div>
                <label class="field-label">Nama Pemohon</label>
                <input type="text" id="inputNamaPemohon" name="nama_pemohon" class="input bg-gray-100" value="<?= htmlspecialchars((string)($dataUser['nama'] ?? '')) ?>" readonly>
              </div>
              <div>
                <label class="field-label">NIK Pemohon</label>
                <input type="text" id="inputNikPemohon" name="nik_pemohon" class="input bg-gray-100" value="<?= htmlspecialchars((string)($dataUser['nik'] ?? '')) ?>" readonly>
              </div>
              <div>
                <label class="field-label">No HP</label>
                <input type="text" id="inputNoHp" name="no_hp" class="input bg-gray-100" value="<?= htmlspecialchars((string)($dataUser['no_hp'] ?? '')) ?>" readonly>
              </div>
              <div>
                <label class="field-label">Email</label>
                <input type="email" id="inputEmail" name="email" class="input bg-gray-100" value="<?= htmlspecialchars((string)($dataUser['email'] ?? '')) ?>" readonly>
              </div>
              <div>
                <label class="field-label">Kecamatan <span class="req">*</span></label>
                <select id="selectKecamatan" name="id_kecamatan" class="select" required>
                  <option value="">-- Pilih Kecamatan --</option>
                  <?php foreach ($kecamatanList as $kec): ?>
                    <option value="<?= (int)$kec['id'] ?>" <?= ($selectedKecamatan === (int)$kec['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string)$kec['nama_kecamatan']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="kia-field-error" id="errorKecamatan"></div>
              </div>
              <div>
                <label class="field-label">Desa <span class="req">*</span></label>
                <select id="selectDesa" name="id_desa" class="select" required>
                  <option value="">-- Pilih Desa --</option>
                  <?php foreach ($desaList as $ds): ?>
                    <option value="<?= (int)$ds['id'] ?>" <?= ($selectedDesa === (int)$ds['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string)$ds['nama_desa']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="kia-field-error" id="errorDesa"></div>
              </div>
              <div>
                <label class="field-label">Nama Kecamatan</label>
                <input type="text" id="nama_kecamatan" class="input bg-gray-100" value="<?= htmlspecialchars($selectedNamaKecamatan) ?>" readonly>
              </div>
              <div>
                <label class="field-label">Nama Desa</label>
                <input type="text" id="nama_desa" class="input bg-gray-100" value="<?= htmlspecialchars($selectedNamaDesa) ?>" readonly>
              </div>
            </div>

            

            <!-- data anak -->
            <div class="kia-section-bar">
              <h3>2. Data Anak yang Diajukan</h3>
            </div>

            <div id="childrenListContainer">
              <!-- Blok anak ke-0 (default) -->
              <div class="kia-child-block kia-anak-item" data-child-index="0">
                <div class="kia-child-block-header">
                  <div class="kia-child-block-title">
                    <span class="kia-child-badge child-number-label">Data Anak ke-1</span>
                  </div>
                  <button type="button" class="btn-remove-child-inline btn-hapus-anak" style="display: none;">Hapus Anak</button>
                </div>

                <div class="form-grid-2" style="margin-bottom: 0;">
                  <div>
                    <label class="field-label">Nama Lengkap Anak <span class="req">*</span></label>
                    <input type="text" class="input input-nama-anak" name="anak[0][nama_anak]" placeholder="Nama lengkap anak sesuai Akta/KK" required>
                    <div class="kia-field-error error-nama-anak"></div>
                  </div>
                  <div>
                    <label class="field-label">NIK Anak <span class="req">*</span></label>
                    <input type="text" class="input input-nik-anak" name="anak[0][nik_anak]" maxlength="16" inputmode="numeric" placeholder="16 digit NIK Anak" required>
                    <div class="kia-field-error error-nik-anak"></div>
                  </div>
                  <div>
                    <label class="field-label">Tanggal Lahir Anak <span class="req">*</span></label>
                    <input type="date" class="input input-tgl-lahir" name="anak[0][tanggal_lahir]" max="<?= date('Y-m-d') ?>" required>
                    <div class="kia-field-error error-tgl-lahir"></div>
                  </div>
                  <div>
                    <label class="field-label">Usia Anak</label>
                    <input type="text" class="input bg-gray-100 input-usia-anak" placeholder="Terhitung otomatis dari Tanggal Lahir" readonly>
                  </div>
                </div>
              </div>
            </div>

            <!-- Tombol Tambah Anak tepat di bawah bagian Data Anak -->
            <div class="kia-add-child-action" style="margin-top: 14px; margin-bottom: 24px;">
              <button type="button" id="btnAddChild" class="btn-add-child-inline">
                <span style="font-size: 16px; font-weight: 800;">+</span> Tambah Anak
              </button>
            </div>

            <!-- keterangan permohonan -->
            <div class="kia-keterangan-wrap">
              <h3 class="kia-form-section-title">3. Keterangan Tambahan (Opsional)</h3>
              <label class="field-label" for="inputKeterangan">Keterangan Permohonan</label>
              <textarea id="inputKeterangan" name="keterangan_permohonan" class="input" rows="3" maxlength="500" placeholder="Contoh: Pengajuan pembuatan Kartu Identitas Anak (KIA) baru untuk keperluan sekolah dan administrasi kependudukan"><?= htmlspecialchars((string)($existingPengajuan['keterangan_permohonan'] ?? '')) ?></textarea>
              <p class="helper">Maksimal 500 karakter</p>
              <div class="kia-field-error" id="errorKeterangan"></div>
            </div>

            <!-- upload dokumen -->
            <div>
              <h3 class="kia-form-section-title">4. Unggah Dokumen Persyaratan <span class="req">*</span></h3>
              <p class="helper">Format: JPG, JPEG, JFIF, JPE, PNG. <b>Maksimal 5 MB per file; Pasfoto Anak wajib untuk usia &ge; 5 tahun (opsional untuk usia &lt; 5 tahun).</b></p>

              <!-- DOKUMEN WAJIB -->
              <div class="f-doc-group" id="mandatoryUploadsContainer">
                <h4 class="f-doc-group__judul">Dokumen Wajib</h4>

                <!-- Slot dinamis per anak: Pasfoto Anak & Akta Kelahiran Anak -->
                <div id="dynamicChildDocsContainer"></div>

                <!-- Slot Kartu Keluarga (KK) Terbaru -->
                <div class="file-input-group" id="cardFcKk">
                  <div class="kia-file-title">
                    <span>Kartu Keluarga (KK) Terbaru <span class="req">*</span></span>
                    <span class="kia-badge-wajib">Wajib</span>
                  </div>
                  <div class="file-wrapper">
                    <label class="file-label" for="fileFcKk">Pilih File</label>
                    <span class="file-name" id="statusFcKk">Belum ada file dipilih</span>
                    <input type="file" id="fileFcKk" name="fc_kk" class="file-input" accept=".jpg,.jpeg,.jfif,.jpe,.png,image/jpeg,image/png">
                  </div>
                  <small class="helper">Maksimal 5 MB per file (Format: JPG, JPEG, JFIF, JPE, PNG).</small>
                  <div class="kia-preview-strip" id="previewFcKk"></div>
                  <div class="kia-field-error" id="errorFcKk"></div>
                </div>

                <!-- Slot Foto Selfie / Pemohon Memegang KK atau Akta Kelahiran -->
                <div class="file-input-group" id="cardFotoPemohon">
                  <div class="kia-file-title">
                    <span>Foto Selfie Memegang Kartu Keluarga/Akta Kelahiran <span class="req">*</span></span>
                    <span class="kia-badge-wajib">Wajib</span>
                  </div>
                  <div class="file-wrapper">
                    <label class="file-label" for="fileFotoPemohon">Pilih File</label>
                    <span class="file-name" id="statusFotoPemohon">Belum ada file dipilih</span>
                    <input type="file" id="fileFotoPemohon" name="foto_pemohon" class="file-input" accept=".jpg,.jpeg,.jfif,.jpe,.png,image/jpeg,image/png">
                  </div>
                  <small class="helper">Maksimal 5 MB per file (Foto pemohon memegang KK atau Akta Kelahiran asli/fotokopi yang jelas).</small>
                  <div class="kia-preview-strip" id="previewFotoPemohon"></div>
                  <div class="kia-field-error" id="errorFotoPemohon"></div>
                </div>
              </div>

              <!-- DOKUMEN PENDUKUNG LAINNYA (OPSIONAL) -->
              <div class="f-doc-group f-doc-group--opsional">
                <h4 class="f-doc-group__judul">Dokumen Pendukung Lainnya (Opsional)</h4>

                <div id="optionalUploadsContainer">
                  <div class="file-input-group optional-doc-item" data-opt-index="0">
                    <div class="kia-file-title">
                      <span>Dokumen Pendukung #1 (Opsional)</span>
                      <span class="kia-badge-opsional">Opsional</span>
                    </div>
                    <div class="file-wrapper">
                      <label class="file-label" for="fileOpt_0">Pilih File</label>
                      <span class="file-name" id="statusOpt_0">Belum ada file dipilih</span>
                      <input type="file" id="fileOpt_0" name="dokumen_tambahan_0" class="file-input input-opt-file" accept=".jpg,.jpeg,.jfif,.jpe,.png,image/jpeg,image/png">
                    </div>
                    <small class="helper">Maksimal 5 MB per file (Opsional).</small>
                    <div class="kia-preview-strip" id="previewOpt_0"></div>
                    <div class="kia-field-error" id="errorOpt_0"></div>
                  </div>
                </div>

                <button type="button" id="btnAddOptionalDoc" class="btn-add-file">+ Tambah Dokumen Opsional Lainnya</button>
              </div>
            </div>

            <!-- TOMBOL AKSI BAWAH -->
            <div class="form-actions">
              <a href="<?= $isEditMode ? 'status_permohonan.php' : 'ajukan_permohonan.php' ?>" class="btn-secondary">Kembali</a>
              <button type="submit" id="btnSubmitKia" class="btn-primary">
                <?= $isEditMode ? 'Simpan & Ajukan Kembali' : 'Ajukan Permohonan' ?>
              </button>
            </div>
          </form>
        </div>
      </section>
    </main>
  </div>

  <!-- MODAL CROPPER PROPORSIONAL KHUSUS PASFOTO ANAK -->
  <div id="cropModalOverlay" class="kia-crop-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="cropModalTitle">
    <div class="kia-crop-modal-card">
      <div class="kia-crop-header">
        <h3 id="cropModalTitle">Sesuaikan Area Pasfoto Anak</h3>
        <p id="cropModalSubtitle">Geser dan perbesar gambar agar kepala dan bahu anak berada di dalam bingkai pasfoto persegi panjang tegak rasio 4:5 (ukuran hasil 480 × 600 piksel).</p>
      </div>
      <div class="kia-crop-workspace" id="cropWorkspace">
        <canvas id="cropCanvas" width="480" height="600"></canvas>
        <div class="kia-crop-overlay-guide">
          <div class="kia-crop-guide-oval"></div>
        </div>
      </div>
      <div class="kia-crop-controls">
        <div class="kia-crop-control-group">
          <label for="cropZoomRange">Perbesar / Zoom:</label>
          <input type="range" id="cropZoomRange" min="0.2" max="3.5" step="0.02" value="1">
        </div>
        <div class="kia-crop-control-group" style="justify-content: center; gap: 10px;">
          <button type="button" class="btn-crop-move" id="btnCropReset">Reset Posisi</button>
        </div>
      </div>
      <div class="kia-crop-footer">
        <button type="button" class="btn-crop-cancel" id="btnCropCancel">Batal</button>
        <button type="button" class="btn-crop-confirm" id="btnCropConfirm">Simpan Hasil Crop</button>
      </div>
    </div>
  </div>

  <!-- MODAL 1: KONFIRMASI PENGAJUAN BERHASIL -->
  <div id="successModalOverlay" class="kia-crop-modal-overlay" style="display: none; z-index: 10040;" role="dialog" aria-modal="true">
    <div class="kia-crop-modal-card" style="max-width: 460px; text-align: center; padding: 28px 24px; border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 8px 24px rgba(15,23,42,0.16);">
      <div style="width: 56px; height: 56px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; font-size: 26px; font-weight: 700;">
        &#10003;
      </div>
      <h3 id="successModalTitle" style="font-size: 18px; font-weight: 700; color: #0f172a; margin: 0 0 8px;">Permohonan KIA Berhasil Diajukan</h3>
      <p id="successModalMessage" style="font-size: 14px; color: #475569; line-height: 1.55; margin: 0 0 22px;">
        Berkas permohonan Kartu Identitas Anak (KIA) Anda telah berhasil dikirim ke sistem SIPRIMA dan masuk ke antrean operator.
      </p>
      <div style="display: flex; justify-content: center;">
        <button type="button" id="btnSuccessContinue" class="btn-primary" style="padding: 10px 24px; font-size: 14px; font-weight: 600; cursor: pointer; border-radius: 6px; width: 100%; max-width: 240px; justify-content: center;">
          Lanjutkan &rarr;
        </button>
      </div>
    </div>
  </div>

  <!-- MODAL 2: POP-UP AJAKAN MENGISI SURVEI KIA -->
  <div id="kiaSurveyInvitationModal" class="kia-crop-modal-overlay" style="display: none; z-index: 10045;" role="dialog" aria-modal="true" aria-labelledby="invitationTitle">
    <div class="kia-crop-modal-card" style="max-width: 480px; text-align: center; padding: 28px 24px; border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 8px 24px rgba(15,23,42,0.16);">
      <h3 id="invitationTitle" style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 10px;">Survei Kepuasan Pelayanan KIA</h3>
      <p style="font-size: 14px; color: #475569; line-height: 1.6; margin: 0 0 22px;">
        Partisipasi Anda sangat berarti untuk evaluasi dan peningkatan kualitas pelayanan KIA melalui survei SKM, SPKP, dan SPAK.
      </p>
      <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
        <button type="button" id="btnAcceptSurveyInvite" style="padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; border-radius: 6px; background: #16a34a; border: none; color: #ffffff; flex: 1; min-width: 170px;">
          Isi Survei Sekarang
        </button>
        <button type="button" id="btnDeclineSurveyInvite" style="padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; border-radius: 6px; background: #ffffff; border: 1px solid #cbd5e1; color: #334155; flex: 1; min-width: 150px;">
          Kembali ke Beranda
        </button>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/survei_kia_modal.php'; ?>

  <script>
  (function () {
    'use strict';

    const MAX_FILE_SIZE = 5242880; // 5 MB
    const ALLOWED_EXTS = ['jpg', 'jpeg', 'jfif', 'jpe', 'png'];
    const ALLOWED_MIMES = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/x-png', ''];

    const isEditMode = <?= $isEditMode ? 'true' : 'false' ?>;
    const editPengajuanId = <?= (int)$editId ?>;
    const pendingMandatorySurveiId = <?= (int)($pendingMandatorySurvei['id'] ?? 0) ?>;
    const existingAnakData = <?= json_encode($existingAnakList, JSON_UNESCAPED_UNICODE) ?>;
    const existingDokumenData = <?= json_encode($existingDokumenList, JSON_UNESCAPED_UNICODE) ?>;

    const kiaForm = document.getElementById('kiaForm');
    const selectKecamatan = document.getElementById('selectKecamatan');
    const selectDesa = document.getElementById('selectDesa');
    const inputNamaKec = document.getElementById('nama_kecamatan');
    const inputNamaDesa = document.getElementById('nama_desa');
    const inputNoKk = document.getElementById('inputNoKk');
    const inputKeterangan = document.getElementById('inputKeterangan');
    const childrenListContainer = document.getElementById('childrenListContainer');
    const dynamicChildDocsContainer = document.getElementById('dynamicChildDocsContainer');
    const optionalUploadsContainer = document.getElementById('optionalUploadsContainer');
    const btnAddChild = document.getElementById('btnAddChild');
    const btnAddOptionalDoc = document.getElementById('btnAddOptionalDoc');
    const btnSubmitKia = document.getElementById('btnSubmitKia');
    const globalAlert = document.getElementById('globalAlert');

    // Penyimpanan file yang telah diproses/dicrop (key => File) dan file asli sebelum crop (untuk pasfoto)
    const selectedFiles = {};
    const originalPasfotoFiles = {};

    function showGlobalError(msg) {
      if (!globalAlert) return;
      globalAlert.innerHTML = '<p style="font-weight:700;margin:0;">' + msg + '</p>';
      globalAlert.style.display = 'block';
      globalAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function hideGlobalError() {
      if (!globalAlert) return;
      globalAlert.style.display = 'none';
      globalAlert.innerHTML = '';
    }

    function setFieldError(el, msg) {
      if (!el) return;
      if (msg) {
        el.textContent = msg;
        el.style.display = 'block';
      } else {
        el.textContent = '';
        el.style.display = 'none';
      }
    }

    // dropdown desa berdasar kecamatan
    if (selectKecamatan) {
      selectKecamatan.addEventListener('change', function () {
        const kecId = this.value;
        const kecText = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text.trim() : '';
        if (inputNamaKec) {
          inputNamaKec.value = kecId ? kecText : '';
        }
        if (inputNamaDesa) {
          inputNamaDesa.value = '';
        }
        setFieldError(document.getElementById('errorKecamatan'), '');
        setFieldError(document.getElementById('errorDesa'), '');

        if (!kecId) {
          selectDesa.innerHTML = '<option value="" selected>-- Pilih Desa --</option>';
          selectDesa.value = '';
          return;
        }

        selectDesa.innerHTML = '<option value="" selected>Memuat desa...</option>';
        selectDesa.value = '';
        fetch('get_desa.php?id_kecamatan=' + encodeURIComponent(kecId))
          .then(res => res.text())
          .then(html => {
            selectDesa.innerHTML = '<option value="" selected>-- Pilih Desa --</option>' + (html || '');
            selectDesa.value = '';
            if (inputNamaDesa) {
              inputNamaDesa.value = '';
            }
          })
          .catch(() => {
            selectDesa.innerHTML = '<option value="" selected>-- Gagal memuat desa --</option>';
            selectDesa.value = '';
          });
      });
    }

    if (selectDesa) {
      selectDesa.addEventListener('change', function () {
        const desaId = this.value;
        const desaText = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text.trim() : '';
        if (inputNamaDesa) {
          inputNamaDesa.value = desaId ? desaText : '';
        }
        setFieldError(document.getElementById('errorDesa'), '');
      });
    }

    if (inputNoKk) {
      inputNoKk.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16);
        setFieldError(document.getElementById('errorNoKk'), '');
      });
    }

    // hitung umur anak
    function calculateChildAgeInfo(dateStr) {
      if (!dateStr) return { valid: false, years: 0, months: 0, label: '' };
      const parts = dateStr.split('-');
      if (parts.length !== 3) return { valid: false, years: 0, months: 0, label: '' };
      const birth = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      const today = new Date();
      if (isNaN(birth.getTime()) || birth > today) {
        return { valid: false, years: -1, months: 0, label: 'Tanggal lahir tidak boleh di masa depan' };
      }
      let years = today.getFullYear() - birth.getFullYear();
      let months = today.getMonth() - birth.getMonth();
      if (today.getDate() < birth.getDate()) {
        months--;
      }
      if (months < 0) {
        years--;
        months += 12;
      }
      const wajibFoto = years >= 5;
      const ageText = years > 0
        ? (years + ' Tahun' + (months > 0 ? ' ' + months + ' Bulan' : ''))
        : (months + ' Bulan');
      const reqInfo = wajibFoto ? ' (Wajib Pasfoto)' : ' (Pasfoto Opsional)';
      return {
        valid: true,
        years: years,
        months: months,
        wajibFoto: wajibFoto,
        label: ageText + reqInfo
      };
    }

    // update slot upload dokumen anak
    function syncChildUploadSlots() {
      const childBlocks = Array.from(childrenListContainer.querySelectorAll('.kia-child-block'));
      const totalChildren = childBlocks.length;

      // Sinkronkan selectedFiles akta_kelahiran <-> akta_kelahiran_0
      if (selectedFiles['akta_kelahiran'] && !selectedFiles['akta_kelahiran_0']) {
        selectedFiles['akta_kelahiran_0'] = selectedFiles['akta_kelahiran'];
      } else if (selectedFiles['akta_kelahiran_0'] && !selectedFiles['akta_kelahiran']) {
        selectedFiles['akta_kelahiran'] = selectedFiles['akta_kelahiran_0'];
      }

      childBlocks.forEach((block, idx) => {
        const namaInput = block.querySelector('.input-nama-anak');
        const tglInput = block.querySelector('.input-tgl-lahir');
        const namaAnak = namaInput && namaInput.value.trim() !== '' ? namaInput.value.trim() : ('Anak ke-' + (idx + 1));
        const ageInfo = calculateChildAgeInfo(tglInput ? tglInput.value : '');
        const isWajibPasfoto = ageInfo.valid && ageInfo.years >= 5;

        // A. Slot Pasfoto Anak ke-idx
        const pasfotoKey = 'pasfoto_anak_' + idx;
        let pasfotoGroup = document.getElementById('card_' + pasfotoKey);
        const existingPasfoto = isEditMode
          ? existingDokumenData.find(d => d.jenis_dokumen === 'pasfoto_anak' && d.status_dokumen === 'valid')
          : null;

        if (!pasfotoGroup) {
          pasfotoGroup = document.createElement('div');
          pasfotoGroup.className = 'file-input-group';
          pasfotoGroup.id = 'card_' + pasfotoKey;
          pasfotoGroup.innerHTML = `
            <div class="kia-file-title">
              <span class="pasfoto-title-text">Pasfoto Anak (${escapeHtml(namaAnak)}) ${isWajibPasfoto ? '<span class="req">*</span>' : ''}</span>
              <span class="pasfoto-badge ${isWajibPasfoto ? 'kia-badge-wajib' : 'kia-badge-opsional'}">
                ${isWajibPasfoto ? 'Wajib (Usia &ge; 5 Tahun)' : 'Opsional (Usia &lt; 5 Tahun)'}
              </span>
            </div>
            <div class="file-wrapper">
              <label class="file-label" for="file_${pasfotoKey}">Pilih File</label>
              <span class="file-name" id="status_${pasfotoKey}">
                ${selectedFiles[pasfotoKey] ? escapeHtml(selectedFiles[pasfotoKey].name) : (existingPasfoto ? ('Berkas tersimpan: ' + escapeHtml(existingPasfoto.original_name)) : 'Belum ada file dipilih')}
              </span>
              <input type="file" id="file_${pasfotoKey}" name="${pasfotoKey}" class="file-input" accept=".jpg,.jpeg,.jfif,.jpe,.png,image/jpeg,image/png">
            </div>
            <small class="helper">Maksimal 5 MB per file. Resolusi minimal 150x150 piksel (Dilengkapi fitur sesuaikan/crop proporsional).</small>
            <div class="kia-preview-strip" id="preview_${pasfotoKey}"></div>
            <div class="kia-field-error" id="error_${pasfotoKey}"></div>
          `;
          dynamicChildDocsContainer.appendChild(pasfotoGroup);

          const pasfotoInput = pasfotoGroup.querySelector('#file_' + pasfotoKey);
          pasfotoInput.addEventListener('change', function () {
            if (this.files && this.files[0]) {
              handleFileSelection(pasfotoKey, this.files[0], true);
            }
          });
        } else {
          const titleSpan = pasfotoGroup.querySelector('.pasfoto-title-text');
          if (titleSpan) {
            titleSpan.innerHTML = `Pasfoto Anak (${escapeHtml(namaAnak)}) ${isWajibPasfoto ? '<span class="req">*</span>' : ''}`;
          }
          const badgeSpan = pasfotoGroup.querySelector('.pasfoto-badge');
          if (badgeSpan) {
            badgeSpan.className = `pasfoto-badge ${isWajibPasfoto ? 'kia-badge-wajib' : 'kia-badge-opsional'}`;
            badgeSpan.innerHTML = isWajibPasfoto ? 'Wajib (Usia &ge; 5 Tahun)' : 'Opsional (Usia &lt; 5 Tahun)';
          }
        }

        if (selectedFiles[pasfotoKey]) {
          renderFilePreview(pasfotoKey, selectedFiles[pasfotoKey], true);
        }

        // B. Slot Akta Kelahiran Anak
        const aktaKey = totalChildren === 1 ? 'akta_kelahiran' : ('akta_kelahiran_' + idx);
        let aktaGroup = document.getElementById('card_' + aktaKey);
        const existingAkta = isEditMode
          ? existingDokumenData.find(d => d.jenis_dokumen === 'akta_kelahiran' && d.status_dokumen === 'valid')
          : null;

        if (totalChildren > 1 && idx === 0 && document.getElementById('card_akta_kelahiran')) {
          const oldCard = document.getElementById('card_akta_kelahiran');
          oldCard.id = 'card_akta_kelahiran_0';
          const oldInp = oldCard.querySelector('#file_akta_kelahiran');
          if (oldInp) {
            oldInp.id = 'file_akta_kelahiran_0';
            oldInp.name = 'akta_kelahiran_0';
            const oldLbl = oldCard.querySelector('label.file-label');
            if (oldLbl) oldLbl.setAttribute('for', 'file_akta_kelahiran_0');
          }
          const oldStatus = oldCard.querySelector('#status_akta_kelahiran');
          if (oldStatus) oldStatus.id = 'status_akta_kelahiran_0';
          const oldPrev = oldCard.querySelector('#preview_akta_kelahiran');
          if (oldPrev) oldPrev.id = 'preview_akta_kelahiran_0';
          const oldErr = oldCard.querySelector('#error_akta_kelahiran');
          if (oldErr) oldErr.id = 'error_akta_kelahiran_0';
          aktaGroup = oldCard;
        } else if (totalChildren === 1 && idx === 0 && document.getElementById('card_akta_kelahiran_0')) {
          const oldCard = document.getElementById('card_akta_kelahiran_0');
          oldCard.id = 'card_akta_kelahiran';
          const oldInp = oldCard.querySelector('#file_akta_kelahiran_0');
          if (oldInp) {
            oldInp.id = 'file_akta_kelahiran';
            oldInp.name = 'akta_kelahiran';
            const oldLbl = oldCard.querySelector('label.file-label');
            if (oldLbl) oldLbl.setAttribute('for', 'file_akta_kelahiran');
          }
          const oldStatus = oldCard.querySelector('#status_akta_kelahiran_0');
          if (oldStatus) oldStatus.id = 'status_akta_kelahiran';
          const oldPrev = oldCard.querySelector('#preview_akta_kelahiran_0');
          if (oldPrev) oldPrev.id = 'preview_akta_kelahiran';
          const oldErr = oldCard.querySelector('#error_akta_kelahiran_0');
          if (oldErr) oldErr.id = 'error_akta_kelahiran';
          aktaGroup = oldCard;
        }

        if (!aktaGroup) {
          aktaGroup = document.createElement('div');
          aktaGroup.className = 'file-input-group';
          aktaGroup.id = 'card_' + aktaKey;
          aktaGroup.innerHTML = `
            <div class="kia-file-title">
              <span class="akta-title-text">Akta Kelahiran anak (${escapeHtml(namaAnak)}) <span class="req">*</span></span>
              <span class="kia-badge-wajib">Wajib</span>
            </div>
            <div class="file-wrapper">
              <label class="file-label" for="file_${aktaKey}">Pilih File</label>
              <span class="file-name" id="status_${aktaKey}">
                ${selectedFiles[aktaKey] ? escapeHtml(selectedFiles[aktaKey].name) : (existingAkta ? ('Berkas tersimpan: ' + escapeHtml(existingAkta.original_name)) : 'Belum ada file dipilih')}
              </span>
              <input type="file" id="file_${aktaKey}" name="${aktaKey}" class="file-input" accept=".jpg,.jpeg,.jfif,.jpe,.png,image/jpeg,image/png">
            </div>
            <small class="helper">Maksimal 5 MB per file (Fotokopi/Scan Akta Kelahiran terbaru).</small>
            <div class="kia-preview-strip" id="preview_${aktaKey}"></div>
            <div class="kia-field-error" id="error_${aktaKey}"></div>
          `;
          dynamicChildDocsContainer.appendChild(aktaGroup);

          const aktaInput = aktaGroup.querySelector('#file_' + aktaKey);
          aktaInput.addEventListener('change', function () {
            if (this.files && this.files[0]) {
              handleFileSelection(aktaKey, this.files[0], false);
            }
          });
        } else {
          const titleSpan = aktaGroup.querySelector('.akta-title-text');
          if (titleSpan) {
            titleSpan.innerHTML = `Akta Kelahiran anak (${escapeHtml(namaAnak)}) <span class="req">*</span>`;
          }
        }

        const aktaFile = selectedFiles[aktaKey] || (totalChildren === 1 ? selectedFiles['akta_kelahiran_0'] : selectedFiles['akta_kelahiran']);
        if (aktaFile) {
          selectedFiles[aktaKey] = aktaFile;
          renderFilePreview(aktaKey, aktaFile, false);
        }
      });

      // Bersihkan grup dokumen anak yang dihapus
      const allPasfotoCards = dynamicChildDocsContainer.querySelectorAll('[id^="card_pasfoto_anak_"]');
      allPasfotoCards.forEach(c => {
        const cIdx = parseInt(c.id.replace('card_pasfoto_anak_', ''), 10);
        if (cIdx >= totalChildren) {
          c.remove();
          delete selectedFiles['pasfoto_anak_' + cIdx];
        }
      });
      const allAktaCards = dynamicChildDocsContainer.querySelectorAll('[id^="card_akta_kelahiran_"]');
      allAktaCards.forEach(c => {
        const cIdx = parseInt(c.id.replace('card_akta_kelahiran_', ''), 10);
        if (cIdx >= totalChildren) {
          c.remove();
          delete selectedFiles['akta_kelahiran_' + cIdx];
        }
      });
    }

    function escapeHtml(str) {
      return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    // kelola data anak
    function reindexChildren() {
      const blocks = Array.from(childrenListContainer.querySelectorAll('.kia-child-block'));
      blocks.forEach((block, idx) => {
        block.dataset.childIndex = String(idx);
        const badge = block.querySelector('.child-number-label');
        if (badge) badge.textContent = 'Data Anak ke-' + (idx + 1);

        const namaInput = block.querySelector('.input-nama-anak');
        const nikInput = block.querySelector('.input-nik-anak');
        const tglInput = block.querySelector('.input-tgl-lahir');

        if (namaInput) namaInput.name = `anak[${idx}][nama_anak]`;
        if (nikInput) nikInput.name = `anak[${idx}][nik_anak]`;
        if (tglInput) tglInput.name = `anak[${idx}][tanggal_lahir]`;

        const btnRemove = block.querySelector('.btn-hapus-anak');
        if (btnRemove) {
          btnRemove.style.display = blocks.length > 1 ? 'inline-flex' : 'none';
        }
      });
      syncChildUploadSlots();
    }

    function bindChildEvents(block) {
      const namaInput = block.querySelector('.input-nama-anak');
      const nikInput = block.querySelector('.input-nik-anak');
      const tglInput = block.querySelector('.input-tgl-lahir');
      const usiaInput = block.querySelector('.input-usia-anak');
      const btnRemove = block.querySelector('.btn-hapus-anak');

      if (namaInput) {
        namaInput.addEventListener('input', function () {
          setFieldError(block.querySelector('.error-nama-anak'), '');
          syncChildUploadSlots();
        });
      }

      if (nikInput) {
        nikInput.addEventListener('input', function () {
          this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16);
          setFieldError(block.querySelector('.error-nik-anak'), '');
        });
      }

      if (tglInput) {
        tglInput.addEventListener('change', function () {
          const info = calculateChildAgeInfo(this.value);
          if (usiaInput) {
            usiaInput.value = info.label;
          }
          setFieldError(block.querySelector('.error-tgl-lahir'), info.years === -1 ? info.label : '');
          syncChildUploadSlots();
        });
      }

      if (btnRemove) {
        btnRemove.addEventListener('click', function () {
          const blocks = childrenListContainer.querySelectorAll('.kia-child-block');
          if (blocks.length <= 1) return;
          block.remove();
          reindexChildren();
        });
      }
    }

    function addChildBlock(prefill) {
      const idx = childrenListContainer.querySelectorAll('.kia-child-block').length;
      const div = document.createElement('div');
      div.className = 'kia-child-block kia-anak-item';
      div.dataset.childIndex = String(idx);
      div.innerHTML = `
        <div class="kia-child-block-header">
          <div class="kia-child-block-title">
            <span class="kia-child-badge child-number-label">Data Anak ke-${idx + 1}</span>
          </div>
          <button type="button" class="btn-remove-child-inline btn-hapus-anak">Hapus Anak</button>
        </div>
        <div class="form-grid-2" style="margin-bottom: 0;">
          <div>
            <label class="field-label">Nama Lengkap Anak <span class="req">*</span></label>
            <input type="text" class="input input-nama-anak" name="anak[${idx}][nama_anak]" placeholder="Nama lengkap anak sesuai Akta/KK" value="${escapeHtml(prefill ? prefill.nama_anak : '')}" required>
            <div class="kia-field-error error-nama-anak"></div>
          </div>
          <div>
            <label class="field-label">NIK Anak <span class="req">*</span></label>
            <input type="text" class="input input-nik-anak" name="anak[${idx}][nik_anak]" maxlength="16" inputmode="numeric" placeholder="16 digit NIK Anak" value="${escapeHtml(prefill ? prefill.nik_anak : '')}" required>
            <div class="kia-field-error error-nik-anak"></div>
          </div>
          <div>
            <label class="field-label">Tanggal Lahir Anak <span class="req">*</span></label>
            <input type="date" class="input input-tgl-lahir" name="anak[${idx}][tanggal_lahir]" max="<?= date('Y-m-d') ?>" value="${escapeHtml(prefill ? prefill.tanggal_lahir : '')}" required>
            <div class="kia-field-error error-tgl-lahir"></div>
          </div>
          <div>
            <label class="field-label">Usia Anak</label>
            <input type="text" class="input bg-gray-100 input-usia-anak" placeholder="Terhitung otomatis dari Tanggal Lahir" readonly>
          </div>
        </div>
      `;
      childrenListContainer.appendChild(div);
      bindChildEvents(div);
      if (prefill && prefill.tanggal_lahir) {
        const info = calculateChildAgeInfo(prefill.tanggal_lahir);
        const usiaInput = div.querySelector('.input-usia-anak');
        if (usiaInput) usiaInput.value = info.label;
      }
      reindexChildren();
    }

    // Inisialisasi blok anak pertama atau prefill dari mode edit
    const firstChildBlock = childrenListContainer.querySelector('.kia-child-block');
    if (isEditMode && Array.isArray(existingAnakData) && existingAnakData.length > 0) {
      childrenListContainer.innerHTML = '';
      existingAnakData.forEach(anak => addChildBlock(anak));
    } else if (firstChildBlock) {
      bindChildEvents(firstChildBlock);
      syncChildUploadSlots();
    }

    if (btnAddChild) {
      btnAddChild.addEventListener('click', function () {
        addChildBlock(null);
      });
    }

    // validasi file upload
    function formatBytes(bytes) {
      if (bytes < 1024) return bytes + ' B';
      if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
      return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function getElementsForSlot(slotKey) {
      if (slotKey === 'id_kecamatan') {
        return { errorEl: document.getElementById('errorKecamatan') };
      }
      if (slotKey === 'id_desa') {
        return { errorEl: document.getElementById('errorDesa') };
      }
      if (slotKey === 'no_kk') {
        return { errorEl: document.getElementById('errorNoKk') };
      }
      if (slotKey === 'keterangan_permohonan') {
        return { errorEl: document.getElementById('errorKeterangan') };
      }
      if (slotKey.startsWith('anak_')) {
        const parts = slotKey.split('_');
        const idx = parts[1];
        const field = parts.slice(2).join('_');
        const block = childrenListContainer.querySelector(`.kia-child-block[data-child-index="${idx}"]`);
        if (block) {
          if (field === 'nama') return { errorEl: block.querySelector('.error-nama-anak') };
          if (field === 'nik') return { errorEl: block.querySelector('.error-nik-anak') };
          if (field === 'tanggal_lahir') return { errorEl: block.querySelector('.error-tgl-lahir') };
        }
      }
      if (slotKey === 'fc_kk') {
        return {
          statusEl: document.getElementById('statusFcKk'),
          previewEl: document.getElementById('previewFcKk'),
          errorEl: document.getElementById('errorFcKk')
        };
      }
      if (slotKey === 'foto_pemohon') {
        return {
          statusEl: document.getElementById('statusFotoPemohon'),
          previewEl: document.getElementById('previewFotoPemohon'),
          errorEl: document.getElementById('errorFotoPemohon')
        };
      }
      if (slotKey === 'akta_kelahiran' || slotKey === 'akta_kelahiran_0') {
        return {
          statusEl: document.getElementById('status_akta_kelahiran') || document.getElementById('status_akta_kelahiran_0'),
          previewEl: document.getElementById('preview_akta_kelahiran') || document.getElementById('preview_akta_kelahiran_0'),
          errorEl: document.getElementById('error_akta_kelahiran') || document.getElementById('error_akta_kelahiran_0')
        };
      }
      if (slotKey === 'pasfoto_anak' || slotKey === 'pasfoto_anak_0') {
        return {
          statusEl: document.getElementById('status_pasfoto_anak_0') || document.getElementById('status_pasfoto_anak'),
          previewEl: document.getElementById('preview_pasfoto_anak_0') || document.getElementById('preview_pasfoto_anak'),
          errorEl: document.getElementById('error_pasfoto_anak_0') || document.getElementById('error_pasfoto_anak')
        };
      }
      if (slotKey.startsWith('dokumen_tambahan_')) {
        const idx = slotKey.replace('dokumen_tambahan_', '');
        return {
          statusEl: document.getElementById('statusOpt_' + idx),
          previewEl: document.getElementById('previewOpt_' + idx),
          errorEl: document.getElementById('errorOpt_' + idx)
        };
      }
      return {
        statusEl: document.getElementById('status_' + slotKey),
        previewEl: document.getElementById('preview_' + slotKey),
        errorEl: document.getElementById('error_' + slotKey)
      };
    }

    function autoCropPasfotoTo480x600(img, sourceFile, callback) {
      const canvas = document.createElement('canvas');
      canvas.width = 480;
      canvas.height = 600;
      const ctx = canvas.getContext('2d');
      if (!ctx || !img || !img.width || !img.height) {
        callback(sourceFile);
        return;
      }
      const targetAspect = 0.8; // Rasio 4:5 portrait (480x600 px)
      const srcAspect = img.width / img.height;
      let sx = 0, sy = 0, sWidth = img.width, sHeight = img.height;
      if (srcAspect > targetAspect) {
        sHeight = img.height;
        sWidth = Math.round(img.height * targetAspect);
        sx = Math.round((img.width - sWidth) / 2);
        sy = 0;
      } else {
        sWidth = img.width;
        sHeight = Math.round(img.width / targetAspect);
        sx = 0;
        sy = Math.round((img.height - sHeight) * 0.20);
      }
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, 480, 600);
      ctx.drawImage(img, sx, sy, sWidth, sHeight, 0, 0, 480, 600);
      canvas.toBlob(function (blob) {
        if (!blob) {
          callback(sourceFile);
          return;
        }
        const safeName = sourceFile.name.replace(/\.[^.]+$/, '') + '_480x600.jpg';
        const croppedFile = new File([blob], safeName, { type: 'image/jpeg' });
        callback(croppedFile);
      }, 'image/jpeg', 0.92);
    }

    function renderFilePreview(slotKey, file, isPasfoto) {
      const els = getElementsForSlot(slotKey);
      if (els && els.statusEl) {
        els.statusEl.textContent = file.name + ' (' + formatBytes(file.size) + ')';
      }
      if (els && els.errorEl) {
        setFieldError(els.errorEl, '');
      }
      if (!els || !els.previewEl) return;

      const url = URL.createObjectURL(file);
      els.previewEl.classList.toggle('kia-preview-strip--pasfoto', Boolean(isPasfoto));
      els.previewEl.style.display = 'inline-flex';
      els.previewEl.innerHTML = `
        <img src="${url}" alt="Preview">
        <div style="font-size:12px;color:#334155;">
          <div style="font-weight:700;">${escapeHtml(file.name)}</div>
          <div>Ukuran: ${formatBytes(file.size)}${isPasfoto ? ' &bull; <strong>480 &times; 600 px (Rasio 4:5 Portrait)</strong>' : ''}</div>
          ${isPasfoto ? '<button type="button" class="btn-recrop" style="margin-top:4px;padding:4px 10px;font-size:11px;font-weight:700;border-radius:6px;border:1px solid #2563eb;background:#eff6ff;color:#1d4ed8;cursor:pointer;">Sesuaikan / Crop Ulang</button>' : ''}
        </div>
      `;
      if (isPasfoto) {
        const btnRecrop = els.previewEl.querySelector('.btn-recrop');
        if (btnRecrop) {
          btnRecrop.addEventListener('click', function () {
            openCropModal(slotKey, originalPasfotoFiles[slotKey] || selectedFiles[slotKey] || file);
          });
        }
      }
    }

    function handleFileSelection(slotKey, file, isPasfoto) {
      const els = getElementsForSlot(slotKey);
      if (els && els.errorEl) {
        setFieldError(els.errorEl, '');
      }

      if (!file) return;

      if (file.size <= 0) {
        if (els && els.errorEl) setFieldError(els.errorEl, 'File kosong (0 byte) tidak diperbolehkan.');
        return;
      }

      if (file.size > MAX_FILE_SIZE) {
        if (els && els.errorEl) setFieldError(els.errorEl, 'Ukuran file (' + formatBytes(file.size) + ') melebihi batas maksimal 5 MB.');
        return;
      }

      const ext = (file.name.split('.').pop() || '').toLowerCase();
      const validMimes = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/x-png', ''];
      if (!ALLOWED_EXTS.includes(ext) || (file.type && !validMimes.includes(file.type.toLowerCase()))) {
        if (els && els.errorEl) setFieldError(els.errorEl, 'Format file tidak didukung. Gunakan JPG, JPEG, JFIF, JPE, atau PNG.');
        return;
      }

      const img = new Image();
      const objUrl = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(objUrl);
        if (isPasfoto && (img.width < 150 || img.height < 150)) {
          if (els && els.errorEl) setFieldError(els.errorEl, 'Resolusi pasfoto (' + img.width + 'x' + img.height + ' px) terlalu kecil. Minimal 150x150 piksel.');
          return;
        }
        if (isPasfoto) {
          originalPasfotoFiles[slotKey] = file;
          autoCropPasfotoTo480x600(img, file, function (processedFile) {
            selectedFiles[slotKey] = processedFile;
            renderFilePreview(slotKey, processedFile, true);
          });
          return;
        }
        selectedFiles[slotKey] = file;
        renderFilePreview(slotKey, file, false);
      };
      img.onerror = function () {
        URL.revokeObjectURL(objUrl);
        if (els && els.errorEl) setFieldError(els.errorEl, 'File rusak atau bukan gambar yang valid.');
      };
      img.src = objUrl;
    }

    // Event untuk FC KK dan Foto Pemohon
    const fileFcKk = document.getElementById('fileFcKk');
    if (fileFcKk) {
      fileFcKk.addEventListener('change', function () {
        if (this.files && this.files[0]) {
          handleFileSelection('fc_kk', this.files[0], false);
        }
      });
    }

    const fileFotoPemohon = document.getElementById('fileFotoPemohon');
    if (fileFotoPemohon) {
      fileFotoPemohon.addEventListener('change', function () {
        if (this.files && this.files[0]) {
          handleFileSelection('foto_pemohon', this.files[0], false);
        }
      });
    }

    // Mode Edit: tampilkan nama berkas yang sudah ada untuk FC KK dan Foto Pemohon
    if (isEditMode && Array.isArray(existingDokumenData)) {
      const exKk = existingDokumenData.find(d => d.jenis_dokumen === 'fc_kk' && d.status_dokumen === 'valid');
      if (exKk && document.getElementById('statusFcKk')) {
        document.getElementById('statusFcKk').textContent = 'Berkas tersimpan: ' + exKk.original_name;
      }
      const exFoto = existingDokumenData.find(d => d.jenis_dokumen === 'foto_pemohon' && d.status_dokumen === 'valid');
      if (exFoto && document.getElementById('statusFotoPemohon')) {
        document.getElementById('statusFotoPemohon').textContent = 'Berkas tersimpan: ' + exFoto.original_name;
      }
    }

    // dokumen tambahan opsional
    function bindOptionalSlot(itemEl) {
      const idx = itemEl.dataset.optIndex;
      const inputEl = itemEl.querySelector('.input-opt-file');
      if (inputEl) {
        inputEl.addEventListener('change', function () {
          if (this.files && this.files[0]) {
            handleFileSelection('dokumen_tambahan_' + idx, this.files[0], false);
          }
        });
      }
    }

    const firstOptItem = optionalUploadsContainer.querySelector('.optional-doc-item');
    if (firstOptItem) {
      bindOptionalSlot(firstOptItem);
    }

    if (btnAddOptionalDoc) {
      btnAddOptionalDoc.addEventListener('click', function () {
        const nextIdx = optionalUploadsContainer.querySelectorAll('.optional-doc-item').length;
        const div = document.createElement('div');
        div.className = 'file-input-group optional-doc-item';
        div.dataset.optIndex = String(nextIdx);
        div.innerHTML = `
          <div class="kia-file-title">
            <span>Dokumen Pendukung #${nextIdx + 1} (Opsional)</span>
            <button type="button" class="btn-remove-child-inline btn-remove-opt">Hapus</button>
          </div>
          <div class="file-wrapper">
            <label class="file-label" for="fileOpt_${nextIdx}">Pilih File</label>
            <span class="file-name" id="statusOpt_${nextIdx}">Belum ada file dipilih</span>
            <input type="file" id="fileOpt_${nextIdx}" name="dokumen_tambahan_${nextIdx}" class="file-input input-opt-file" accept=".jpg,.jpeg,.jfif,.jpe,.png,image/jpeg,image/png">
          </div>
          <small class="helper">Maksimal 5 MB per file (Opsional).</small>
          <div class="kia-preview-strip" id="previewOpt_${nextIdx}"></div>
          <div class="kia-field-error" id="errorOpt_${nextIdx}"></div>
        `;
        optionalUploadsContainer.appendChild(div);
        bindOptionalSlot(div);
        const btnRem = div.querySelector('.btn-remove-opt');
        if (btnRem) {
          btnRem.addEventListener('click', function () {
            delete selectedFiles['dokumen_tambahan_' + nextIdx];
            div.remove();
          });
        }
      });
    }

    // crop pasfoto
    const cropModalOverlay = document.getElementById('cropModalOverlay');
    const cropCanvas = document.getElementById('cropCanvas');
    const cropWorkspace = document.getElementById('cropWorkspace');
    const cropZoomRange = document.getElementById('cropZoomRange');
    const btnCropReset = document.getElementById('btnCropReset');
    const btnCropCancel = document.getElementById('btnCropCancel');
    const btnCropConfirm = document.getElementById('btnCropConfirm');

    let activeCropSlotKey = null;
    let activeCropSourceFile = null;
    let cropImg = null;
    let cropScale = 1;
    let cropBaseScale = 1;
    let cropOffsetX = 0;
    let cropOffsetY = 0;
    let isDraggingCrop = false;
    let dragStartX = 0;
    let dragStartY = 0;

    function clampCropOffsets() {
      if (!cropCanvas || !cropImg) return;
      const drawW = cropImg.width * cropScale;
      const drawH = cropImg.height * cropScale;
      const maxOffsetX = Math.max(0, (drawW - cropCanvas.width) / 2);
      const maxOffsetY = Math.max(0, (drawH - cropCanvas.height) / 2);
      cropOffsetX = Math.max(-maxOffsetX, Math.min(maxOffsetX, cropOffsetX));
      cropOffsetY = Math.max(-maxOffsetY, Math.min(maxOffsetY, cropOffsetY));
    }

    function drawCropCanvas() {
      if (!cropCanvas || !cropImg) return;
      clampCropOffsets();
      const ctx = cropCanvas.getContext('2d');
      ctx.clearRect(0, 0, cropCanvas.width, cropCanvas.height);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, cropCanvas.width, cropCanvas.height);

      const drawW = cropImg.width * cropScale;
      const drawH = cropImg.height * cropScale;
      const x = (cropCanvas.width - drawW) / 2 + cropOffsetX;
      const y = (cropCanvas.height - drawH) / 2 + cropOffsetY;
      ctx.drawImage(cropImg, x, y, drawW, drawH);
    }

    function openCropModal(slotKey, file) {
      if (!cropModalOverlay || !cropCanvas) return;
      activeCropSlotKey = slotKey;
      activeCropSourceFile = originalPasfotoFiles[slotKey] || file;

      const img = new Image();
      const url = URL.createObjectURL(activeCropSourceFile);
      img.onload = function () {
        URL.revokeObjectURL(url);
        cropImg = img;
        const scaleX = cropCanvas.width / img.width;
        const scaleY = cropCanvas.height / img.height;
        cropBaseScale = Math.max(scaleX, scaleY);
        cropScale = cropBaseScale;
        cropOffsetX = 0;
        const excessH = (img.height * cropBaseScale) - cropCanvas.height;
        cropOffsetY = excessH > 0 ? Math.round(excessH * 0.30) : 0;
        if (cropZoomRange) {
          cropZoomRange.min = String(cropBaseScale.toFixed(4));
          cropZoomRange.max = String((cropBaseScale * 3.0).toFixed(4));
          cropZoomRange.step = String((cropBaseScale * 0.02).toFixed(4));
          cropZoomRange.value = String(cropScale);
        }
        cropModalOverlay.style.display = 'flex';
        drawCropCanvas();
      };
      img.src = url;
    }

    if (cropZoomRange) {
      cropZoomRange.addEventListener('input', function () {
        const parsed = parseFloat(this.value) || cropBaseScale;
        cropScale = Math.max(cropBaseScale, parsed);
        drawCropCanvas();
      });
    }

    if (btnCropReset) {
      btnCropReset.addEventListener('click', function () {
        cropScale = cropBaseScale;
        cropOffsetX = 0;
        const excessH = cropImg ? ((cropImg.height * cropBaseScale) - cropCanvas.height) : 0;
        cropOffsetY = excessH > 0 ? Math.round(excessH * 0.30) : 0;
        if (cropZoomRange) cropZoomRange.value = String(cropScale);
        drawCropCanvas();
      });
    }

    if (cropWorkspace) {
      cropWorkspace.addEventListener('pointerdown', function (e) {
        isDraggingCrop = true;
        const dragRatio = cropCanvas.width / (cropWorkspace.clientWidth || 240);
        dragStartX = e.clientX - (cropOffsetX / dragRatio);
        dragStartY = e.clientY - (cropOffsetY / dragRatio);
        cropWorkspace.setPointerCapture(e.pointerId);
      });
      cropWorkspace.addEventListener('pointermove', function (e) {
        if (!isDraggingCrop) return;
        const dragRatio = cropCanvas.width / (cropWorkspace.clientWidth || 240);
        cropOffsetX = (e.clientX - dragStartX) * dragRatio;
        cropOffsetY = (e.clientY - dragStartY) * dragRatio;
        drawCropCanvas();
      });
      cropWorkspace.addEventListener('pointerup', function () {
        isDraggingCrop = false;
      });
    }

    if (btnCropCancel) {
      btnCropCancel.addEventListener('click', function () {
        cropModalOverlay.style.display = 'none';
      });
    }

    if (btnCropConfirm) {
      btnCropConfirm.addEventListener('click', function () {
        if (!cropCanvas || !activeCropSlotKey || !activeCropSourceFile) {
          cropModalOverlay.style.display = 'none';
          return;
        }
        cropCanvas.toBlob(function (blob) {
          if (blob) {
            const safeName = activeCropSourceFile.name.replace(/\.[^.]+$/, '') + '_cropped.jpg';
            const croppedFile = new File([blob], safeName, { type: 'image/jpeg' });
            selectedFiles[activeCropSlotKey] = croppedFile;
            renderFilePreview(activeCropSlotKey, croppedFile, true);
          }
          cropModalOverlay.style.display = 'none';
        }, 'image/jpeg', 0.92);
      });
    }

    // submit formulir
    if (kiaForm) {
      kiaForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        hideGlobalError();

        let hasValidationError = false;

        if (!selectKecamatan.value) {
          setFieldError(document.getElementById('errorKecamatan'), 'Kecamatan wajib dipilih.');
          hasValidationError = true;
        }
        if (!selectDesa.value) {
          setFieldError(document.getElementById('errorDesa'), 'Desa wajib dipilih.');
          hasValidationError = true;
        }

        const noKkVal = (inputNoKk && inputNoKk.value ? inputNoKk.value : '').trim();

        const ketVal = (inputKeterangan ? inputKeterangan.value : '').trim();
        // Keterangan bersifat opsional
        if (document.getElementById('errorKeterangan')) {
          setFieldError(document.getElementById('errorKeterangan'), '');
        }

        const childBlocks = Array.from(childrenListContainer.querySelectorAll('.kia-child-block'));
        childBlocks.forEach((block, idx) => {
          const namaVal = (block.querySelector('.input-nama-anak')?.value || '').trim();
          const nikVal = (block.querySelector('.input-nik-anak')?.value || '').trim();
          const tglVal = (block.querySelector('.input-tgl-lahir')?.value || '').trim();

          if (!namaVal) {
            setFieldError(block.querySelector('.error-nama-anak'), 'Nama lengkap anak ke-' + (idx + 1) + ' wajib diisi.');
            hasValidationError = true;
          }
          if (!/^[0-9]{16}$/.test(nikVal)) {
            setFieldError(block.querySelector('.error-nik-anak'), 'NIK anak ke-' + (idx + 1) + ' harus 16 digit angka.');
            hasValidationError = true;
          }
          const ageInfo = calculateChildAgeInfo(tglVal);
          if (!tglVal || !ageInfo.valid) {
            setFieldError(block.querySelector('.error-tgl-lahir'), 'Tanggal lahir anak ke-' + (idx + 1) + ' wajib diisi dengan benar.');
            hasValidationError = true;
          }
        });

        if (hasValidationError) {
          showGlobalError('Mohon periksa kembali isian formulir yang ditandai merah.');
          return;
        }

        const formData = new FormData();
        const csrfInput = kiaForm.querySelector('input[name="csrf_token"]');
        if (csrfInput) {
          formData.append('csrf_token', csrfInput.value);
        }
        if (isEditMode && editPengajuanId > 0) {
          formData.append('id', String(editPengajuanId));
        }

        formData.append('id_kecamatan', selectKecamatan.value);
        formData.append('id_desa', selectDesa.value);
        formData.append('no_kk', noKkVal);
        formData.append('keterangan_permohonan', ketVal || 'Permohonan Kartu Identitas Anak (KIA)');

        childBlocks.forEach((block, idx) => {
          formData.append(`anak[${idx}][nama_anak]`, (block.querySelector('.input-nama-anak')?.value || '').trim());
          formData.append(`anak[${idx}][nik_anak]`, (block.querySelector('.input-nik-anak')?.value || '').trim());
          formData.append(`anak[${idx}][tanggal_lahir]`, (block.querySelector('.input-tgl-lahir')?.value || '').trim());
        });

        // Bersihkan alias tunggal jika pengajuan memiliki lebih dari 1 anak
        if (childBlocks.length > 1) {
          delete selectedFiles['akta_kelahiran'];
          delete selectedFiles['pasfoto_anak'];
        }

        // Lampirkan hanya file yang benar-benar dipilih oleh pengguna
        Object.keys(selectedFiles).forEach(key => {
          if (childBlocks.length > 1 && (key === 'akta_kelahiran' || key === 'pasfoto_anak')) {
            return;
          }
          if (selectedFiles[key] instanceof File) {
            formData.append(key, selectedFiles[key], selectedFiles[key].name);
          }
        });

        // Untuk satu anak: pastikan akta_kelahiran_0 dan pasfoto_anak_0 terlampir konsisten
        if (childBlocks.length === 1) {
          const singleAkta = selectedFiles['akta_kelahiran_0'] || selectedFiles['akta_kelahiran'];
          if (singleAkta instanceof File && !formData.has('akta_kelahiran_0')) {
            formData.append('akta_kelahiran_0', singleAkta, singleAkta.name);
          }
          const singleFoto = selectedFiles['pasfoto_anak_0'] || selectedFiles['pasfoto_anak'];
          if (singleFoto instanceof File && !formData.has('pasfoto_anak_0')) {
            formData.append('pasfoto_anak_0', singleFoto, singleFoto.name);
          }
        }

        // Fallback apabila ada input file yang dipilih tetapi belum masuk ke selectedFiles
        const fileInputs = kiaForm.querySelectorAll('input[type="file"]');
        fileInputs.forEach(inp => {
          const fName = inp.name;
          if (fName && !formData.has(fName) && inp.files && inp.files.length > 0 && inp.files[0].size > 0) {
            formData.append(fName, inp.files[0], inp.files[0].name);
          }
        });

        const origBtnText = btnSubmitKia.textContent;
        btnSubmitKia.disabled = true;
        btnSubmitKia.textContent = 'Mengirim Permohonan...';

        try {
          const targetAction = isEditMode ? 'resubmit' : 'create';
          const resp = await fetch('kia.php?action=' + targetAction, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
          });

          const json = await resp.json();
          if (!resp.ok || !json.success) {
            let errMsg = json.error || 'Validasi formulir pengajuan KIA gagal.';
            if (json.fields && typeof json.fields === 'object') {
              const fieldMsgs = Object.values(json.fields);
              if (fieldMsgs.length > 0) {
                errMsg += ' (' + fieldMsgs.join(' ') + ')';
              }
              Object.entries(json.fields).forEach(([k, v]) => {
                const els = getElementsForSlot(k);
                if (els && els.errorEl) {
                  setFieldError(els.errorEl, String(v));
                }
              });
            }
            showGlobalError(errMsg);
            btnSubmitKia.disabled = false;
            btnSubmitKia.textContent = origBtnText;
            return;
          }

          const submittedPengajuanId = (json.data && json.data.id_pengajuan) ? parseInt(json.data.id_pengajuan, 10) : editPengajuanId;

          // dialog sukses
          const successModal = document.getElementById('successModalOverlay');
          const successTitle = document.getElementById('successModalTitle');
          const successMsg = document.getElementById('successModalMessage');
          const btnSuccessContinue = document.getElementById('btnSuccessContinue');

          const inviteModal = document.getElementById('kiaSurveyInvitationModal');
          const btnAcceptInvite = document.getElementById('btnAcceptSurveyInvite');
          const btnDeclineInvite = document.getElementById('btnDeclineSurveyInvite');

          if (successModal) {
            if (isEditMode && successTitle) {
              successTitle.textContent = 'Perbaikan KIA Berhasil Diajukan Kembali!';
            }
            if (json.message && successMsg) {
              successMsg.textContent = json.message;
            }

            if (btnSuccessContinue) {
              btnSuccessContinue.onclick = function () {
                successModal.style.display = 'none';
                if (isEditMode) {
                  // Survei sukarela hanya ditawarkan pada pengajuan baru pertama kali
                  window.location.href = 'status_permohonan.php';
                  return;
                }
                // modal survei kepuasan
                if (inviteModal) {
                  inviteModal.style.display = 'flex';
                } else if (typeof openKiaSurveiModal === 'function' && submittedPengajuanId > 0) {
                  openKiaSurveiModal(submittedPengajuanId, false);
                } else {
                  window.location.href = 'dashboard_user.php';
                }
              };
            }

            if (btnAcceptInvite) {
              btnAcceptInvite.onclick = function () {
                if (inviteModal) inviteModal.style.display = 'none';
                if (typeof openKiaSurveiModal === 'function' && submittedPengajuanId > 0) {
                  openKiaSurveiModal(submittedPengajuanId, false);
                } else {
                  window.location.href = 'status_permohonan.php';
                }
              };
            }

            if (btnDeclineInvite) {
              btnDeclineInvite.onclick = function () {
                if (inviteModal) inviteModal.style.display = 'none';
                window.location.href = 'dashboard_user.php';
              };
            }

            successModal.style.display = 'flex';
          } else {
            window.location.href = 'status_permohonan.php';
          }
        } catch (err) {
          showGlobalError('Terjadi gangguan jaringan saat mengirim permohonan. Silakan coba lagi.');
          btnSubmitKia.disabled = false;
          btnSubmitKia.textContent = origBtnText;
        }
      });
    }
  })();
  </script>
</body>
</html>
