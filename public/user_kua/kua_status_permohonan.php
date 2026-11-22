<?php
require_once '../error_handler.php';
// File: kua_status_permohonan_kk.php
include '../../app/middleware/user_kua_only.php'; // Validasi user KUA
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../user/service_tracking.php';
require_once __DIR__ . '/../functions/document_token.php';
require_once __DIR__ . '/../user/email_delivery_confirmation_ui.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_kartukeluarga_kua LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}
$created_expr = $has_resubmitted_at ? 'COALESCE(kk.resubmitted_at, kk.created_at)' : 'kk.created_at';

// Debug info
error_log("=== kua_status_permohonan_kk.php START ===");
error_log("id_user: " . $_SESSION['id_user']);
error_log("id_kua: " . $_SESSION['id_kua']);
error_log("nama_kua: " . ($_SESSION['nama_kua'] ?? 'NOT SET'));

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$id_kua = (int)$_SESSION['id_kua'];

// Ambil data KUA
$q_kua = $koneksi->query("SELECT * FROM tb_kua WHERE id_kua = '$id_kua'");
$kua_data = $q_kua->fetch_assoc();

if (!$kua_data) {
    die("Data KUA tidak ditemukan!");
}

// Konfigurasi pagination
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// Hitung offset
$offset = ($page - 1) * $records_per_page;

// Query untuk menghitung total data untuk KUA ini
$count_stmt = $koneksi->prepare("SELECT COUNT(*) as total 
    FROM tb_kartukeluarga_kua 
    WHERE id_kua = ?");
$count_stmt->bind_param('i', $id_kua);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_row = $count_result->fetch_assoc();
$total_records = $total_row['total'];
$count_stmt->close();

// Hitung total halaman
$total_pages = ceil($total_records / $records_per_page);

// Locate an authorized deep link in the normal paginated list.
$asprimaDetailId = filter_var($_GET['detail_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
if ($asprimaDetailId && ($_GET['jenis'] ?? '') === 'kk') {
    $rankExpr = str_replace('kk.', '', $created_expr);
    $rankStmt = $koneksi->prepare("SELECT COUNT(*) AS preceding FROM tb_kartukeluarga_kua WHERE id_kua=? AND (($rankExpr) > (SELECT $rankExpr FROM tb_kartukeluarga_kua WHERE id=? AND id_kua=?) OR (($rankExpr) = (SELECT $rankExpr FROM tb_kartukeluarga_kua WHERE id=? AND id_kua=?) AND id>?))");
    $rankStmt->bind_param('iiiiii', $id_kua, $asprimaDetailId, $id_kua, $asprimaDetailId, $id_kua, $asprimaDetailId);
    $rankStmt->execute();
    $page = intdiv((int)$rankStmt->get_result()->fetch_assoc()['preceding'], 10) + 1;
    $offset = ($page - 1) * 10;
    $rankStmt->close();
}
// Query untuk mengambil data permohonan KK untuk KUA ini dengan pagination
$stmt = $koneksi->prepare("SELECT 
    kk.id, 
    {$created_expr} as created_at, 
    kk.nama_kecamatan, 
    kk.nama_desa, 
    kk.nama_kepalakeluarga, 
    kk.alamat_catin,
    kk.nama_pemohon,
    kk.nik_pemohon,
    kk.no_hp,
    kk.email,
    kk.dusun,
    kk.no_kk_lama_suami,
    kk.no_kk_lama_istri,
    kk.anggotakeluarga, 
    kk.keterangan,
    kk.dokumen, 
    kk.status,
    kk.alasan,
    kk.updated_at,
    u.nama as nama_pengguna,
    DATE_FORMAT({$created_expr}, '%d %M %Y %H:%i') as formatted_date,
    DATE_FORMAT(kk.updated_at, '%d %M %Y %H:%i') as formatted_updated
    FROM tb_kartukeluarga_kua kk
    LEFT JOIN tb_users u ON kk.id_user = u.id
    WHERE kk.id_kua = ? 
    ORDER BY {$created_expr} DESC, kk.id DESC
    LIMIT ? OFFSET ?");
$stmt->bind_param('iii', $id_kua, $records_per_page, $offset);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
if (!empty($rows)) {
    foreach ($rows as &$row) {
        $row['status_history_html'] = serviceTrackingHtml(serviceTrackingHistory($koneksi, 'tb_kartukeluarga_kua', (int)$row['id']), true, (string)$row['status']);
        $row['dokumen'] = siprima_mask_document_json(__DIR__, $row['dokumen'] ?? null);
        $row['alasan_history'] = getPermohonanRejectionHistory(
            $koneksi,
            'tb_kartukeluarga_kua',
            (int)($row['id'] ?? 0),
            15
        );
        try {
            $row['email_delivery'] = email_delivery_status($koneksi, 'tb_kartukeluarga_kua', (int)$row['id'], $user_id);
        } catch (Throwable $e) {
            $row['email_delivery'] = ['available'=>false, 'files'=>[]];
        }
    }
    unset($row);
}

function esc($s){ 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function shortText($s, $n=70){ 
    if (strlen($s) <= $n) return $s; 
    return substr($s,0,$n-1).'…'; 
}

// Fungsi untuk menampilkan dokumen
function displayDocuments($dokumenJson) {
    if (empty($dokumenJson)) return '-';
    
    $documents = json_decode($dokumenJson, true);
    if (!is_array($documents) || empty($documents)) return '-';
    
    $html = '<div class="document-list">';
    foreach ($documents as $doc) {
        $docType = $doc['type'] ?? 'lainnya';
        $docName = $doc['original_name'] ?? 'Dokumen';
        $docPath = $doc['path'] ?? '';
        
        $typeLabels = [
            'kk_lama_suami' => 'KK Lama Suami',
            'kk_lama_istri' => 'KK Lama Istri',
            'formulir_f101' => 'Formulir F-1.01',
            'buku_nikah' => 'Buku Nikah',
            'formulir_f103' => 'Formulir F-1.03',
            'lainnya' => 'Dokumen Lain'
        ];
        
        $typeLabel = $typeLabels[$docType] ?? 'Dokumen';
        
        if (!empty($docPath) && file_exists(__DIR__ . '/' . $docPath)) {
            $html .= '<div class="document-item">
                <span class="doc-type">' . esc($typeLabel) . ':</span>
                <a href="' . esc($docPath) . '" target="_blank" class="doc-link">' . esc($docName) . '</a>
            </div>';
        } else {
            $html .= '<div class="document-item">
                <span class="doc-type">' . esc($typeLabel) . ':</span>
                <span class="doc-missing">' . esc($docName) . ' (File tidak ditemukan)</span>
            </div>';
        }
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Status Permohonan KK - KUA <?= esc($kua_data['nama_kua'] ?? '') ?></title>
  <!-- Favicon inline untuk menghindari 404 -->
  <link rel="icon" type="image/png" href="../assets/PASUTRI.png">
  <link rel="stylesheet" href="css/kua_status_permohonan.css">
  
    <link rel="stylesheet" href="../assets/css/service-timeline.css?v=20260908-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

  <div class="layout">
    <aside class="sidebar">
      <div class="logo">Pelayanan Kependudukan PASUTRI</div>
      <nav>
        <ul>
          <li><a href="kua_dashboard_user.php">Dashboard</a></li>
          <li><a href="kua_ajukan_permohonan.php">Ajukan permohonan</a></li>
          <li class="active"><a href="kua_status_permohonan.php">Status Permohonan</a></li>
          <li><a href="kua_formulir.php">Formulir Kependudukan</a></li>          
          <li><a href="kua_profil.php">Profil Saya</a></li>
          <li id="logoutBtn">Logout</li>
        </ul>
      </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header">
        <div class="left-header">
          <div class="title">Status Permohonan</div>
          <div class="subtitle"><?= esc($kua_data['nama_kua'] ?? '') ?></div>
        </div>
        <div class="user-info">
          Hai, <?= esc($_SESSION['nama']) ?>
          <span class="role-badge"><?= strtoupper($_SESSION['role']) ?></span>
          <?php include __DIR__ . '/../user/notification_widget.php'; ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <!-- KUA Info Banner -->
        <div class="kua-banner">
          <h2>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
              <line x1="16" y1="2" x2="16" y2="6"></line>
              <line x1="8" y1="2" x2="8" y2="6"></line>
              <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            KUA <?= esc($kua_data['nama_kua'] ?? '') ?>
          </h2>
          <p>Lihat status permohonan Kartu Keluarga yang telah diajukan</p>
        </div>

        <div class="form-card">
          <div class="form-header">
            <div class="icon-container">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Status Permohonan Kartu Keluarga</h2>
              <p class="helper">Pantau status permohonan KK yang telah diajukan ke KUA Anda.</p>
            </div>
          </div>

          <?php
          // Flash success from redirect (if any)
          $flash_success = null;
          if (isset($_SESSION['success_message'])) {
              $flash_success = $_SESSION['success_message'];
              unset($_SESSION['success_message']);
          }
          ?>

          <?php if (!empty($flash_success)): ?>
            <div class="toast toast-success">
              <?= esc($flash_success) ?>
            </div>
            <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>
          <?php endif; ?>

          <div class="filter-section">
            <div class="filter-group">
              <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Filter Status</label>
                <select id="filterStatus" class="select">
                  <option value="">Semua Status</option>
                  <option value="diajukan">Diajukan</option>
                  <option value="diproses">Diproses</option>
                  <option value="ditolak">Ditolak</option>
                  <option value="selesai">Selesai</option>
                </select>
              </div>
            </div>
          </div>

          <div class="table-container">
            <?php if (empty($rows)): ?>
              <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <h3 style="margin-bottom: 10px; color: #374151;">Belum Ada Permohonan</h3>
                <p style="margin-bottom: 20px; color: #6b7280;">Belum ada permohonan Kartu Keluarga yang diajukan ke KUA Anda.</p>
              </div>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Tanggal Pengajuan</th>
                    <th>Pemohon</th>
                    <th>Kecamatan</th>
                    <th>Desa</th>
                    <th>Kepala Keluarga</th>
                    <th>Status</th>
                    <th>Aksi</th>
                    <th>Konfirmasi Dokumen</th>
                  </tr>
                </thead>
                <tbody id="tabel-permohonan-konten">
                  <?php 
                  $start_number = ($page - 1) * $records_per_page + 1;
                  foreach ($rows as $row): 
                  ?>
                    <tr class="permohonan-row" data-status="<?= strtolower($row['status']) ?>">
                      <td><?= $start_number++ ?></td>
                      <td>
                        <div>
                          <small>Pengajuan:</small> <?= esc($row['formatted_date'] ?? '-') ?><br>
                          <small style="display:block;margin-top:6px">Status diperbarui: <?= esc(serviceTrackingUpdatedLabel($koneksi, 'tb_kartukeluarga_kua', (int)$row['id'])) ?></small><small>Update:</small>
                          <?= esc((!empty($row['formatted_updated']) && $row['formatted_updated'] !== $row['formatted_date']) ? $row['formatted_updated'] : '-') ?>
                        </div>
                      </td>
                      <td><?= esc($row['nama_pemohon']) ?></td>
                      <td><?= esc($row['nama_kecamatan'] ?? '-') ?></td>
                      <td><?= esc($row['nama_desa'] ?? '-') ?></td>
                      <td><?= esc($row['nama_kepalakeluarga']) ?></td>
                      <td>
                        <?php 
                          $statusClass = 'status-' . strtolower($row['status']);
                          $statusText = ucfirst($row['status']);
                        ?>
                        <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                        <?php if (!empty($row['alasan'])): ?>
                          <p class="status-reason">Alasan: <?= esc($row['alasan']) ?></p>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="action-buttons">
                          <button class="btn-detail" data-request-id="<?= (int)$row['id'] ?>" onclick="showDetailModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)">
                            Detail
                          </button>
                          <?php if (in_array(strtolower((string)$row['status']), ['diajukan', 'ditolak'], true)): ?>
                            <a href="edit_kua_kartukeluarga.php?id=<?= $row['id'] ?>" class="btn-edit">
                              Edit & Ajukan Kembali
                            </a>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td><?= email_delivery_confirmation_ui($koneksi, 'tb_kartukeluarga_kua', (int)$row['id'], $user_id,
                          (string)$row['status'], (string)($row['updated_at'] ?: $row['created_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              
              <!-- Pagination -->
              <?php if ($total_pages > 1): ?>
              <div class="pagination">
                <div class="pagination-info">
                  Menampilkan <?= min($records_per_page, count($rows)) ?> dari <?= $total_records ?> data
                  (Halaman <?= $page ?> dari <?= $total_pages ?>)
                </div>
                <div class="pagination-links">
                  <?php if ($page > 1): ?>
                    <a href="?page=1<?= isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : '' ?>" class="page-link">« Pertama</a>
                    <a href="?page=<?= $page - 1 ?><?= isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : '' ?>" class="page-link">‹ Sebelumnya</a>
                  <?php else: ?>
                    <span class="page-link disabled">« Pertama</span>
                    <span class="page-link disabled">‹ Sebelumnya</span>
                  <?php endif; ?>
                  
                  <?php
                  // Tampilkan maksimal 5 nomor halaman
                  $start_page = max(1, $page - 2);
                  $end_page = min($total_pages, $page + 2);
                  
                  if ($start_page > 1) {
                    echo '<span class="page-dots">...</span>';
                  }
                  
                  for ($i = $start_page; $i <= $end_page; $i++):
                    if ($i == $page):
                  ?>
                    <span class="page-link active"><?= $i ?></span>
                  <?php else: ?>
                    <a href="?page=<?= $i ?><?= isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : '' ?>" class="page-link"><?= $i ?></a>
                  <?php
                    endif;
                  endfor;
                  
                  if ($end_page < $total_pages) {
                    echo '<span class="page-dots">...</span>';
                  }
                  ?>
                  
                  <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?><?= isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : '' ?>" class="page-link">Berikutnya ›</a>
                    <a href="?page=<?= $total_pages ?><?= isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : '' ?>" class="page-link">Terakhir »</a>
                  <?php else: ?>
                    <span class="page-link disabled">Berikutnya ›</span>
                    <span class="page-link disabled">Terakhir »</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- Detail Modal -->
  <div class="modal-overlay" id="detailModal">
    <div class="detail-modal">
      <div class="modal-header">
        <h2>Detail Permohonan Kartu Keluarga</h2>
        <button class="modal-close" onclick="closeDetailModal()">&times;</button>
      </div>
      <div class="modal-body">
        <div class="detail-grid">
          <div class="detail-item">
            <div class="detail-label">ID Permohonan</div>
            <div class="detail-value" id="detail-id">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Tanggal Pengajuan</div>
            <div class="detail-value" id="detail-tanggal">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Tanggal Update</div>
            <div class="detail-value" id="detail-updated">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Nama Pemohon</div>
            <div class="detail-value" id="detail-pemohon">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">NIK Pemohon</div>
            <div class="detail-value" id="detail-nik">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">No HP</div>
            <div class="detail-value" id="detail-nohp">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Email</div>
            <div class="detail-value" id="detail-email">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Kecamatan</div>
            <div class="detail-value" id="detail-kecamatan">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Desa</div>
            <div class="detail-value" id="detail-desa">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Dusun/Alamat</div>
            <div class="detail-value" id="detail-dusun">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Nama Kepala Keluarga</div>
            <div class="detail-value" id="detail-kepala-keluarga">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Status Alamat</div>
            <div class="detail-value" id="detail-alamat-catin">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">KK Lama Suami</div>
            <div class="detail-value" id="detail-kk-siswa">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">KK Lama Istri</div>
            <div class="detail-value" id="detail-kk-istri">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Status Permohonan</div>
            <div class="detail-value" id="detail-status">-</div>
          </div>
        </div>

        <!-- Anggota Keluarga -->
        <div class="anggota-section">
          <h3>👨‍👩‍‍👦 Anggota Keluarga</h3>
          <div class="anggota-list" id="detail-anggota">
            <!-- Anggota keluarga akan ditampilkan di sini -->
          </div>
        </div>

        <!-- Keterangan -->
        <div id="keterangan-container" class="keterangan-box" style="display: none;">
          <div class="detail-label">Keterangan dari Pemohon</div>
          <div class="detail-value" id="detail-keterangan" style="white-space: pre-wrap; line-height: 1.6;"></div>
        </div>

        <!-- Dokumen -->
        <div class="documents-section">
          <h3>📁 Dokumen Pendukung</h3>
          <div class="document-list" id="detail-dokumen">
            <!-- Dokumen akan ditampilkan di sini -->
          </div>
        </div>

        <!-- Alasan Status -->
        <div id="alasan-container" class="alasan-box" style="display: none;">
          <div class="detail-label">Alasan Status</div>
          <div class="detail-value" id="detail-alasan" style="white-space: pre-wrap; line-height: 1.6;"></div>
        </div>
        <div id="alasan-history-container" class="alasan-box" style="display: none;">
          <div class="detail-label">Riwayat Alasan Penolakan</div>
          <div class="detail-value" id="detail-alasan-history"></div>
        </div>
      </div>
      <div style="padding: 20px; text-align: right; border-top: 1px solid #e5e7eb;">
        <button class="btn-back" onclick="closeDetailModal()">Tutup</button>
      </div>
    </div>
  </div>

  <!-- Modal Logout -->
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
  // Toggle Sidebar Menu on Mobile
  document.addEventListener('DOMContentLoaded', function() {
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
        window.location.href = '../logout_pasutri_ahlak.php';
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
      if (event.key === 'Escape') {
        if (logoutModal.classList.contains('show')) {
          hideLogoutModal();
        }
        if (document.getElementById('detailModal').classList.contains('show')) {
          closeDetailModal();
        }
      }
    });
    
    // Preserve filter when changing pages
    const filterSelect = document.getElementById('filterStatus');
    if (filterSelect) {
      // Set current filter value from URL if exists
      const urlParams = new URLSearchParams(window.location.search);
      const filterValue = urlParams.get('filter');
      if (filterValue) {
        filterSelect.value = filterValue;
      }
      
      // Update URL when filter changes
      filterSelect.addEventListener('change', function() {
        const currentPage = <?= $page ?>;
        const filterValue = this.value;
        let url = '?page=' + currentPage;
        if (filterValue) {
          url += '&filter=' + filterValue;
        }
        window.location.href = url;
      });
    }
  });

  // Filter Status
  document.getElementById('filterStatus').addEventListener('change', function() {
    const filterValue = this.value.toLowerCase();
    const rows = document.querySelectorAll('.permohonan-row');
    
    rows.forEach(row => {
      const status = row.getAttribute('data-status');
      if (!filterValue || status === filterValue) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });
  });

  // Show Detail Modal
  function showDetailModal(data) {
    console.log('Data untuk modal:', data);
    
    // Fill modal with data
    document.getElementById('detail-id').textContent = data.id || '-';
    document.getElementById('detail-tanggal').textContent = data.formatted_date || data.created_at || '-';
    document.getElementById('detail-updated').textContent = data.formatted_updated || data.updated_at || '-';
    document.getElementById('detail-pemohon').textContent = data.nama_pemohon || '-';
    document.getElementById('detail-nik').textContent = data.nik_pemohon || '-';
    document.getElementById('detail-nohp').textContent = data.no_hp || '-';
    document.getElementById('detail-email').textContent = data.email || '-';
    document.getElementById('detail-kecamatan').textContent = data.nama_kecamatan || '-';
    document.getElementById('detail-desa').textContent = data.nama_desa || '-';
    document.getElementById('detail-dusun').textContent = data.dusun || '-';
    document.getElementById('detail-kepala-keluarga').textContent = data.nama_kepalakeluarga || '-';
    document.getElementById('detail-alamat-catin').textContent = data.alamat_catin || '-';
    document.getElementById('detail-kk-siswa').textContent = data.no_kk_lama_suami || '-';
    document.getElementById('detail-kk-istri').textContent = data.no_kk_lama_istri || '-';
    
    // Status dengan styling
    const statusElement = document.getElementById('detail-status');
    const status = data.status || 'Pending';
    statusElement.textContent = status;
    statusElement.className = 'detail-value status-badge status-' + status.toLowerCase();
    
    // Anggota Keluarga
    const anggotaContainer = document.getElementById('detail-anggota');
    if (data.anggotakeluarga) {
      try {
        const anggota = JSON.parse(data.anggotakeluarga);
        if (Array.isArray(anggota) && anggota.length > 0) {
          let html = '';
          anggota.forEach((item, index) => {
            html += `
              <div class="anggota-item">
                <div class="anggota-field">
                  <div class="anggota-label">Nama</div>
                  <div>${item.nama || '-'}</div>
                </div>
                <div class="anggota-field">
                  <div class="anggota-label">NIK</div>
                  <div>${item.nik || '-'}</div>
                </div>
                <div class="anggota-field">
                  <div class="anggota-label">Hubungan</div>
                  <div>${item.hubungan || '-'}</div>
                </div>
              </div>
            `;
          });
          anggotaContainer.innerHTML = html;
        } else {
          anggotaContainer.innerHTML = '<div class="anggota-item">Tidak ada data anggota keluarga</div>';
        }
      } catch (e) {
        console.error('Error parsing anggota keluarga JSON:', e);
        anggotaContainer.innerHTML = `<div class="anggota-item">${data.anggotakeluarga}</div>`;
      }
    } else {
      anggotaContainer.innerHTML = '<div class="anggota-item">Tidak ada data anggota keluarga</div>';
    }
    
    // Keterangan
    const keteranganContainer = document.getElementById('keterangan-container');
    const keteranganElement = document.getElementById('detail-keterangan');
    if (data.keterangan && data.keterangan.trim() !== '') {
      keteranganElement.textContent = data.keterangan;
      keteranganContainer.style.display = 'block';
    } else {
      keteranganContainer.style.display = 'none';
    }
    
    // Dokumen
    const dokumenElement = document.getElementById('detail-dokumen');
    if (data.dokumen && data.dokumen !== 'null') {
      try {
        const documents = JSON.parse(data.dokumen);
        if (Array.isArray(documents) && documents.length > 0) {
          let html = '';
          documents.forEach(doc => {
            const typeLabels = {
              'kk_lama_suami': 'KK Lama Suami',
              'kk_lama_istri': 'KK Lama Istri',
              'formulir_f101': 'Formulir F-1.01',
              'buku_nikah': 'Buku Nikah',
              'formulir_f103': 'Formulir F-1.03',
              'lainnya': 'Dokumen Lain'
            };
            
            const typeLabel = typeLabels[doc.type] || 'Dokumen';
            const docName = doc.original_name || 'Dokumen';
            const docPath = doc.path || '';
            
            if (docPath) {
              html += `
                <div class="document-item">
                  <span class="doc-type">${typeLabel}:</span>
                  <a href="${docPath}" target="_blank" class="doc-link">${docName}</a>
                </div>
              `;
            } else {
              html += `
                <div class="document-item">
                  <span class="doc-type">${typeLabel}:</span>
                  <span class="doc-missing">${docName} (File tidak tersedia)</span>
                </div>
              `;
            }
          });
          dokumenElement.innerHTML = html;
        } else {
          dokumenElement.innerHTML = '<div class="document-item"><span class="doc-missing">Tidak ada dokumen</span></div>';
        }
      } catch (e) {
        console.error('Error parsing dokumen JSON:', e);
        // Jika dokumen bukan JSON, tampilkan sebagai link biasa
        dokumenElement.innerHTML = `
          <div class="document-item">
            <span class="doc-type">Dokumen:</span>
            <a href="${data.dokumen}" target="_blank" class="doc-link">Lihat Dokumen</a>
          </div>
        `;
      }
    } else {
      dokumenElement.innerHTML = '<div class="document-item"><span class="doc-missing">Tidak ada dokumen</span></div>';
    }
    const delivery = data.email_delivery || {};
    const operatorFiles = Array.isArray(delivery.files) ? delivery.files : [];
    if (operatorFiles.length) {
      dokumenElement.insertAdjacentHTML('beforeend', `<h4 style="margin:16px 0 8px">Dokumen Hasil dari Operator</h4>${operatorFiles.map(file => `
        <div class="document-item"><span class="doc-type">Dokumen Hasil:</span>
        <a href="${escapeHtml(file.download_url)}" class="doc-link" data-feedback-table="tb_kartukeluarga_kua" data-feedback-id="${data.id}">${escapeHtml(file.original_name || 'Download Dokumen')}</a></div>`).join('')}`);
    }
    const deliveryStatus = String(delivery.status || '');
    if (['sebagian_diunggah_perlu_pengajuan_baru', 'perlu_pengajuan_baru'].includes(deliveryStatus)) {
      const operatorNote = String(delivery.catatan_operator || '').trim();
      dokumenElement.insertAdjacentHTML('beforeend', `
        <div style="margin-top:12px;padding:12px;background:#fffbeb;color:#92400e;border-radius:8px;overflow-wrap:anywhere">
          <strong>Catatan operator:</strong>
          <div style="margin-top:5px;white-space:pre-wrap">${escapeHtml(operatorNote || '-')}</div>
        </div>`);
    }
    
    // Alasan Status
    const alasanContainer = document.getElementById('alasan-container');
    const alasanElement = document.getElementById('detail-alasan');
    
    if (data.alasan && data.alasan.trim() !== '') {
      alasanElement.textContent = data.alasan;
      alasanContainer.style.display = 'block';
    } else {
      alasanContainer.style.display = 'none';
    }

    const alasanHistoryContainer = document.getElementById('alasan-history-container');
    const alasanHistoryElement = document.getElementById('detail-alasan-history');
    const alasanHistory = Array.isArray(data.alasan_history) ? data.alasan_history : [];
    if (alasanHistory.length > 0) {
      const historyHtml = alasanHistory.map(item => {
        const alasanText = (item && item.alasan) ? item.alasan : '-';
        const waktuText = (item && item.created_at_formatted) ? item.created_at_formatted : '-';
        return `<li style="margin-bottom:6px;">${escapeHtml(alasanText)} <span style="color:#6b7280;">(${escapeHtml(waktuText)})</span></li>`;
      }).join('');
      alasanHistoryElement.innerHTML = `<ul style="padding-left:1.1rem; list-style:disc;">${historyHtml}</ul>`;
      alasanHistoryContainer.style.display = 'block';
    } else {
      alasanHistoryElement.innerHTML = '';
      alasanHistoryContainer.style.display = 'none';
    }
    
        let serviceTimeline = document.getElementById('pemohon-service-timeline');
        if (!serviceTimeline) {
            serviceTimeline = document.createElement('div');
            serviceTimeline.id = 'pemohon-service-timeline';
            alasanHistoryContainer.after(serviceTimeline);
        }
        serviceTimeline.innerHTML = data.status_history_html || '';
        alasanHistoryContainer.style.display = 'none';
    // Show modal
    document.getElementById('detailModal').classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  // Close Detail Modal
  function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('show');
    document.body.style.overflow = '';
  }

  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Close modal when clicking outside
  document.getElementById('detailModal').addEventListener('click', function(event) {
    if (event.target === this) {
      closeDetailModal();
    }
  });
  
  // Inisialisasi dua jenis suara
const audioSelesai = new Audio('../assets/notif.mp3');
const audioDitolak = new Audio('../assets/alert.mp3');

function refreshStatusOtomatis() {
    const areaTabel = document.getElementById('tabel-permohonan-konten');
    if (!areaTabel) return;

    // Simpan status lama
    const statusLama = Array.from(areaTabel.querySelectorAll('span')).map(s => s.innerText.trim());

    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const kontenBaru = doc.getElementById('tabel-permohonan-konten');

            if (kontenBaru) {
                const statusBaru = Array.from(kontenBaru.querySelectorAll('span')).map(s => s.innerText.trim());
                
                let adaSelesai = false;
                let adaDitolak = false;

                statusBaru.forEach((status, index) => {
                    // Cek perubahan ke Selesai
                    if (status === 'Selesai' && statusLama[index] !== 'Selesai') {
                        adaSelesai = true;
                    }
                    // Cek perubahan ke Ditolak
                    if (status === 'Ditolak' && statusLama[index] !== 'Ditolak') {
                        adaDitolak = true;
                    }
                });

                areaTabel.innerHTML = kontenBaru.innerHTML;

                // Eksekusi Suara
                if (adaDitolak) {
                    audioDitolak.play().catch(e => console.log("Izin audio diperlukan"));
                } else if (adaSelesai) {
                    audioSelesai.play().catch(e => console.log("Izin audio diperlukan"));
                }
            }
        });
}
setInterval(refreshStatusOtomatis, 120000);
  </script>
<?php email_delivery_confirmation_assets(); ?>
<?php include __DIR__ . '/../user/email_delivery_note_modal.php'; ?>
<?php include __DIR__ . '/../user/permohonan_feedback_modal.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const query = new URLSearchParams(location.search), id = query.get('detail_id');
  if (!/^[1-9][0-9]{0,9}$/.test(id || '') || query.get('jenis') !== 'kk') return;
  const button = document.querySelector('.btn-detail[data-request-id="' + id + '"]');
  if (button) button.click();
});
</script>
</body>
</html>
