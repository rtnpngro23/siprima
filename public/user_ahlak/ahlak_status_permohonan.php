<?php
require_once '../error_handler.php';
include '../../app/middleware/user_ahlak_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../user/service_tracking.php';
require_once __DIR__ . '/ahlak_document_helper.php';
require_once __DIR__ . '/../user/email_delivery_confirmation_ui.php';

$has_resubmitted_at = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kelahiran_ahlak LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) {
    $has_resubmitted_at = true;
}
$created_expr = $has_resubmitted_at ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$id_puskes = $_SESSION['id_puskes'] ?? 0;

// Konfigurasi pagination
$limit = 10; // Jumlah data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filter status dari URL
$filter_status = isset($_GET['filter']) ? $_GET['filter'] : '';

// Query untuk menghitung total data dengan filter - HANYA untuk Puskesmas ini
$count_query = "SELECT COUNT(*) as total FROM tb_akta_kelahiran_ahlak WHERE id_puskes = ?";
if ($filter_status) {
    $count_query .= " AND status = ?";
}

$stmt_count = $koneksi->prepare($count_query);
if ($filter_status) {
    $stmt_count->bind_param('is', $id_puskes, $filter_status);
} else {
    $stmt_count->bind_param('i', $id_puskes);
}
$stmt_count->execute();
$res_count = $stmt_count->get_result();
$row_count = $res_count->fetch_assoc();
$total_data = $row_count['total'];
$total_pages = ceil($total_data / $limit);

// Validasi halaman
if ($page < 1) $page = 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

// Locate an authorized deep link in the normal paginated list.
$asprimaDetailId = filter_var($_GET['detail_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
if ($asprimaDetailId && ($_GET['jenis'] ?? '') === 'kelahiran') {
    $filter_status = '';
    $rankExpr = str_replace('kk.', '', $created_expr);
    $rankStmt = $koneksi->prepare("SELECT COUNT(*) AS preceding FROM tb_akta_kelahiran_ahlak WHERE id_puskes=? AND (($rankExpr) > (SELECT $rankExpr FROM tb_akta_kelahiran_ahlak WHERE id=? AND id_puskes=?) OR (($rankExpr) = (SELECT $rankExpr FROM tb_akta_kelahiran_ahlak WHERE id=? AND id_puskes=?) AND id>?))");
    $rankStmt->bind_param('iiiiii', $id_puskes, $asprimaDetailId, $id_puskes, $asprimaDetailId, $id_puskes, $asprimaDetailId);
    $rankStmt->execute();
    $page = intdiv((int)$rankStmt->get_result()->fetch_assoc()['preceding'], 10) + 1;
    $offset = ($page - 1) * 10;
    $rankStmt->close();
}
// Query untuk mengambil data dengan pagination dan filter - HANYA untuk Puskesmas ini
$query = "SELECT 
    id, 
    {$created_expr} as created_at, 
    nama_kecamatan, 
    nama_desa, 
    nama_bayi, 
    tempat_lahir,
    tanggal_lahir,
    Waktu,
    urutan_kelahiran,
    jenis_kelamin,
    nama_ayah, 
    nik_ayah, 
    nama_ibu, 
    nik_ibu,
    nama_saksi1,
    nik_saksi1,
    nama_saksi2,
    nik_saksi2,
    keterangan,
    dokumen, 
    status,
    alasan,
    updated_at,
    DATE_FORMAT({$created_expr}, '%d %M %Y %H:%i') as formatted_date,
    DATE_FORMAT(updated_at, '%d %M %Y %H:%i') as formatted_updated,
    DATE_FORMAT(tanggal_lahir, '%d %M %Y') as formatted_tanggal_lahir
    FROM tb_akta_kelahiran_ahlak 
    WHERE id_puskes = ?";
    
if ($filter_status) {
    $query .= " AND status = ?";
}

$query .= " ORDER BY {$created_expr} DESC, id DESC LIMIT ? OFFSET ?";

$stmt = $koneksi->prepare($query);
if ($filter_status) {
    $stmt->bind_param('isii', $id_puskes, $filter_status, $limit, $offset);
} else {
    $stmt->bind_param('iii', $id_puskes, $limit, $offset);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
if (!empty($rows)) {
    foreach ($rows as &$row) {
        $row['status_history_html'] = serviceTrackingHtml(serviceTrackingHistory($koneksi, 'tb_akta_kelahiran_ahlak', (int)$row['id']), true, (string)$row['status']);
        $row['dokumen'] = ahlak_mask_document_json($row['dokumen'] ?? null);
        $row['alasan_history'] = getPermohonanRejectionHistory(
            $koneksi,
            'tb_akta_kelahiran_ahlak',
            (int)($row['id'] ?? 0),
            15
        );
        try {
            $row['email_delivery'] = email_delivery_status($koneksi, 'tb_akta_kelahiran_ahlak', (int)$row['id'], $user_id);
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
    return substr($s,0,$n-1).''; 
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
            'surat_lahir' => 'Surat Keterangan Lahir',
            'buku_nikah' => 'Buku Nikah / Akta Nikah / SPTJM',
            'formulir' => 'Form F-2.01 Kelahiran',
            'kk' => 'Kartu Keluarga (KK)',
            'ktp_ortu' => 'KTP Orang Tua',
            'lainnya' => 'Dokumen Lain'
        ];
        
        $typeLabel = $typeLabels[$docType] ?? 'Dokumen';
        
        if (!empty($docPath)) {
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
  <title>Status Permohonan AHLAK - Puskesmas</title>
  <link rel="stylesheet" href="css/ahlak_status_permohonan.css">
  <link rel="icon" type="image/png" href="../assets/ahlak.png">
    <link rel="stylesheet" href="../assets/css/service-timeline.css?v=20260908-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

  <div class="layout">

    <aside class="sidebar">
      <div class="logo">Pelayanan Kependudukan AHLAK</div>
      <nav>
        <ul>
          <li><a href="ahlak_dashboard_user.php">Dashboard</a></li>
          <li><a href="ahlak_ajukan_permohonan.php">Ajukan Permohonan</a></li>
          <li class="active"><a href="ahlak_status_permohonan.php">Status Permohonan</a></li>          
          <li><a href="ahlak_formulir.php">Formulir Kependudukan</a></li>
          <li><a href="ahlak_profil.php">Profil</a></li>
          <li id="logoutBtn">Logout</li>
        </ul>
      </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header">
        <div class="left-header">
          <div class="title">Status Permohonan AHLAK</div>
          <div style="font-size: 14px; color: #666; margin-top: 5px;">
           <?= esc($_SESSION['nama_puskes'] ?? '') ?>
          </div>
        </div>
        <div class="user-info">
          <span><?= esc($_SESSION['nama']) ?></span>
          <span class="role-badge">USER AHLAK</span>
          <?php include __DIR__ . '/../user/notification_widget.php'; ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <!-- Puskesmas Info Banner -->
        <div class="puskesmas-banner">
          <h3>🏥 <?= esc($_SESSION['nama_puskes'] ?? '') ?></h3>
          <p><strong>Kecamatan:</strong> <?= esc($_SESSION['nama_kecamatan'] ?? '') ?></p>
          <p><strong>Layanan AHLAK:</strong> Akta Kelahiran, Kartu Keluarga dan Kartu Identitas Anak</p>
        </div>

        <div class="form-card">
          <div class="form-header">
            <div class="icon-container">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                <line x1="9" y1="9" x2="9.01" y2="9"></line>
                <line x1="15" y1="9" x2="15.01" y2="9"></line>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Status Permohonan AHLAK</h2>
              <p class="helper">Pantau status permohonan AHLAK yang telah diajukan melalui Puskesmas ini.</p>
            </div>
          </div>

          <div class="filter-section">
            <div class="filter-group">              
              <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Filter Status</label>
                <select id="filterStatus" class="select">
                  <option value="">Semua Status</option>
                  <option value="diajukan" <?= $filter_status == 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                  <option value="diproses" <?= $filter_status == 'diproses' ? 'selected' : '' ?>>Diproses</option>
                  <option value="ditolak" <?= $filter_status == 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                  <option value="selesai" <?= $filter_status == 'selesai' ? 'selected' : '' ?>>Selesai</option>
                </select>
              </div>
            </div>
            <a href="ahlak_akta_kelahiran.php" class="btn-new">+ Ajukan Akta Baru</a>
          </div>

          <div class="table-container">
            <?php if (empty($rows)): ?>
              <div class="empty-state">
                <div class="empty-state-icon">👶</div>
                <h3 style="margin-bottom: 10px; color: #374151;">Belum Ada Permohonan</h3>
                <p style="margin-bottom: 20px; color: #6b7280;">Belum ada permohonan akta kelahiran yang diajukan melalui Puskesmas ini.</p>
                <a href="ahlak_akta_kelahiran.php" class="btn-new">Ajukan Permohonan Pertama</a>
              </div>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Tanggal Pengajuan</th>
                    <th>Kecamatan</th>
                    <th>Desa</th>
                    <th>Nama Bayi</th>
                    <th>Tanggal Lahir</th>
                    <th>Status</th>
                    <th>Aksi</th>
                    <th>Konfirmasi Dokumen</th>
                  </tr>
                </thead>
                <tbody id="tabel-permohonan-konten">
                  <?php 
                  $nomor_awal = ($page - 1) * $limit + 1;
                  $i = $nomor_awal; 
                  foreach ($rows as $row): ?>
                    <tr class="permohonan-row" data-status="<?= strtolower($row['status']) ?>">
                      <td><?= $i++ ?></td>
                      <td>
                        <div>
                          <small>Pengajuan:</small> <?= esc($row['formatted_date'] ?? '-') ?><br>
                          <small style="display:block;margin-top:6px">Status diperbarui: <?= esc(serviceTrackingUpdatedLabel($koneksi, 'tb_akta_kelahiran_ahlak', (int)$row['id'])) ?></small><small>Update:</small>
                          <?= esc((!empty($row['formatted_updated']) && $row['formatted_updated'] !== $row['formatted_date']) ? $row['formatted_updated'] : '-') ?>
                        </div>
                      </td>
                      <td><?= esc($row['nama_kecamatan'] ?? '-') ?></td>
                      <td><?= esc($row['nama_desa'] ?? '-') ?></td>
                      <td><?= esc($row['nama_bayi']) ?></td>
                      <td><?= esc($row['formatted_tanggal_lahir']) ?></td>
                      <td>
                        <?php 
                          $statusClass = 'status-' . strtolower($row['status']);
                          $statusText = ucfirst($row['status']);
                        ?>
                        <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                        <?php if (!empty($row['alasan'])): ?>
                        <div class="text-xs text-gray-600 mt-1">Alasan: <?= esc($row['alasan']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <button class="btn-detail" data-request-id="<?= (int)$row['id'] ?>" onclick="showDetailModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)">
                          Detail
                        </button>
                        <?php if (in_array(strtolower((string)$row['status']), ['diajukan', 'ditolak'], true)): ?>
                        <a href="ahlak_edit_permohonan_akta_kelahiran.php?id=<?= $row['id'] ?>" class="btn-edit">
                          Edit & Ajukan Kembali
                        </a>
                        <?php endif; ?>
                      </td>
                      <td><?= email_delivery_confirmation_ui($koneksi, 'tb_akta_kelahiran_ahlak', (int)$row['id'], $user_id,
                          (string)$row['status'], (string)($row['updated_at'] ?: $row['created_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              
              <!-- Pagination -->
              <?php if ($total_pages > 1): ?>
              <div class="pagination">
                <div class="pagination-info">
                  Menampilkan <?= ($offset + 1) ?> - <?= min($offset + $limit, $total_data) ?> dari <?= $total_data ?> data
                  (Halaman <?= $page ?> dari <?= $total_pages ?>)
                </div>
                
                <?php 
                // Tentukan jumlah halaman yang ditampilkan
                $max_pages_to_show = 5;
                $start_page = max(1, $page - floor($max_pages_to_show / 2));
                $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
                
                // Sesuaikan start_page jika end_page mencapai batas
                if ($end_page - $start_page + 1 < $max_pages_to_show) {
                  $start_page = max(1, $end_page - $max_pages_to_show + 1);
                }
                ?>
                
                <!-- Tombol Previous -->
                <?php if ($page > 1): ?>
                  <a href="?page=<?= $page - 1 ?><?= $filter_status ? '&filter=' . $filter_status : '' ?>" class="page-link">« Prev</a>
                <?php else: ?>
                  <span class="page-link disabled">« Prev</span>
                <?php endif; ?>
                
                <!-- Halaman pertama -->
                <?php if ($start_page > 1): ?>
                  <a href="?page=1<?= $filter_status ? '&filter=' . $filter_status : '' ?>" class="page-link">1</a>
                  <?php if ($start_page > 2): ?>
                    <span class="ellipsis">...</span>
                  <?php endif; ?>
                <?php endif; ?>
                
                <!-- Halaman tengah -->
                <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                  <?php if ($p == $page): ?>
                    <span class="page-link active"><?= $p ?></span>
                  <?php else: ?>
                    <a href="?page=<?= $p ?><?= $filter_status ? '&filter=' . $filter_status : '' ?>" class="page-link"><?= $p ?></a>
                  <?php endif; ?>
                <?php endfor; ?>
                
                <!-- Halaman terakhir -->
                <?php if ($end_page < $total_pages): ?>
                  <?php if ($end_page < $total_pages - 1): ?>
                    <span class="ellipsis">...</span>
                  <?php endif; ?>
                  <a href="?page=<?= $total_pages ?><?= $filter_status ? '&filter=' . $filter_status : '' ?>" class="page-link"><?= $total_pages ?></a>
                <?php endif; ?>
                
                <!-- Tombol Next -->
                <?php if ($page < $total_pages): ?>
                  <a href="?page=<?= $page + 1 ?><?= $filter_status ? '&filter=' . $filter_status : '' ?>" class="page-link">Next »</a>
                <?php else: ?>
                  <span class="page-link disabled">Next »</span>
                <?php endif; ?>
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
        <h2>Detail Permohonan Akta Kelahiran</h2>
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
            <div class="detail-label">Kecamatan</div>
            <div class="detail-value" id="detail-kecamatan">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Desa</div>
            <div class="detail-value" id="detail-desa">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Nama Bayi</div>
            <div class="detail-value" id="detail-nama-bayi">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Jenis Kelamin</div>
            <div class="detail-value" id="detail-jenis-kelamin">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Tempat Lahir</div>
            <div class="detail-value" id="detail-tempat-lahir">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Tanggal Lahir</div>
            <div class="detail-value" id="detail-tanggal-lahir">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Waktu Lahir</div>
            <div class="detail-value" id="detail-waktu-lahir">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Anak ke-</div>
            <div class="detail-value" id="detail-urutan">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Nama Ayah</div>
            <div class="detail-value" id="detail-nama-ayah">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">NIK Ayah</div>
            <div class="detail-value" id="detail-nik-ayah">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Nama Ibu</div>
            <div class="detail-value" id="detail-nama-ibu">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">NIK Ibu</div>
            <div class="detail-value" id="detail-nik-ibu">-</div>
          </div>
          <!-- Saksi 1 -->
          <div class="detail-item">
            <div class="detail-label">Nama Saksi 1</div>
            <div class="detail-value" id="detail-nama-saksi1">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">NIK Saksi 1</div>
            <div class="detail-value" id="detail-nik-saksi1">-</div>
          </div>
          <!-- Saksi 2 -->
          <div class="detail-item">
            <div class="detail-label">Nama Saksi 2</div>
            <div class="detail-value" id="detail-nama-saksi2">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">NIK Saksi 2</div>
            <div class="detail-value" id="detail-nik-saksi2">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Status</div>
            <div class="detail-value" id="detail-status">-</div>
          </div>
        </div>

        <!-- Keterangan Box -->
        <div id="keterangan-container" class="keterangan-box" style="display: none;">
          <div class="detail-label">Keterangan dari Pemohon</div>
          <div class="detail-value" id="detail-keterangan" style="white-space: pre-wrap; line-height: 1.6;"></div>
        </div>

        <div class="documents-section">
          <h3>📁 Dokumen Pendukung</h3>
          <div class="document-list" id="detail-dokumen">
            <!-- Dokumen akan ditampilkan di sini -->
          </div>
        </div>

        <div id="alasan-container" class="alasan-box" style="display: none;">
          <div class="detail-label">Alasan Status</div>
          <div class="detail-value" id="detail-alasan" style="white-space: pre-wrap; line-height: 1.6;"></div>
        </div>
        <div id="alasan-history-container" class="alasan-box" style="display: none;">
          <div class="detail-label">Riwayat Alasan Penolakan</div>
          <div class="detail-value" id="detail-alasan-history"></div>
        </div>
        
        <!-- Notice untuk status diajukan/ditolak -->
        <div id="edit-notice-container" class="edit-notice" style="display: none;">
          <h4>⚠️ Permohonan Dapat Diedit</h4>
          <p>Permohonan ini dapat diedit dan diajukan kembali.</p>
          <button type="button" class="btn-edit-modal" id="btnEditModal" style="display: none;">Edit & Ajukan Kembali</button>
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
    document.addEventListener('DOMContentLoaded', function() {
        // Filter status change
        const filterStatus = document.getElementById('filterStatus');
        if (filterStatus) {
            filterStatus.addEventListener('change', function() {
                const status = this.value;
                const url = new URL(window.location.href);
                if (status) {
                    url.searchParams.set('filter', status);
                } else {
                    url.searchParams.delete('filter');
                }
                url.searchParams.delete('page'); // Reset ke halaman 1
                window.location.href = url.toString();
            });
        }

        // Toggle Sidebar Menu on Mobile
        const menuToggle = document.querySelector('.menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        
        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
            
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
        
        if (logoutModal) {
            logoutModal.addEventListener('click', function(event) {
                if (event.target === logoutModal) {
                    hideLogoutModal();
                }
            });
        }
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
                hideLogoutModal();
            }
        });
    });

    // Global functions for modal
    function showDetailModal(data) {
        // Isi data ke modal
        document.getElementById('detail-id').textContent = data.id || '-';
        document.getElementById('detail-tanggal').textContent = data.formatted_date || '-';
        document.getElementById('detail-kecamatan').textContent = data.nama_kecamatan || '-';
        document.getElementById('detail-desa').textContent = data.nama_desa || '-';
        document.getElementById('detail-nama-bayi').textContent = data.nama_bayi || '-';
        
        // Jenis kelamin
        const jenisKelamin = data.jenis_kelamin || '';
        document.getElementById('detail-jenis-kelamin').textContent = 
            jenisKelamin === 'L' ? 'Laki-laki' : 
            jenisKelamin === 'P' ? 'Perempuan' : '-';
        
        document.getElementById('detail-tempat-lahir').textContent = data.tempat_lahir || '-';
        document.getElementById('detail-tanggal-lahir').textContent = data.formatted_tanggal_lahir || '-';
        document.getElementById('detail-waktu-lahir').textContent = data.Waktu || '-';
        document.getElementById('detail-urutan').textContent = data.urutan_kelahiran || '-';
        document.getElementById('detail-nama-ayah').textContent = data.nama_ayah || '-';
        document.getElementById('detail-nik-ayah').textContent = data.nik_ayah || '-';
        document.getElementById('detail-nama-ibu').textContent = data.nama_ibu || '-';
        document.getElementById('detail-nik-ibu').textContent = data.nik_ibu || '-';
        
        // Saksi 1
        document.getElementById('detail-nama-saksi1').textContent = data.nama_saksi1 || '-';
        document.getElementById('detail-nik-saksi1').textContent = data.nik_saksi1 || '-';
        
        // Saksi 2
        document.getElementById('detail-nama-saksi2').textContent = data.nama_saksi2 || '-';
        document.getElementById('detail-nik-saksi2').textContent = data.nik_saksi2 || '-';
        
        // Status dengan badge
        const status = data.status || '';
        const statusClass = 'status-' + status.toLowerCase();
        const statusText = status.charAt(0).toUpperCase() + status.slice(1);
        document.getElementById('detail-status').innerHTML = 
            `<span class="status-badge ${statusClass}">${statusText}</span>`;
        
        // Keterangan
        const keteranganContainer = document.getElementById('keterangan-container');
        const keteranganValue = document.getElementById('detail-keterangan');
        if (data.keterangan && data.keterangan.trim() !== '') {
            keteranganValue.textContent = data.keterangan;
            keteranganContainer.style.display = 'block';
        } else {
            keteranganContainer.style.display = 'none';
        }
        
        // Alasan status
        const alasanContainer = document.getElementById('alasan-container');
        const alasanValue = document.getElementById('detail-alasan');
        if (data.alasan && data.alasan.trim() !== '') {
            alasanValue.textContent = data.alasan;
            alasanContainer.style.display = 'block';
        } else {
            alasanContainer.style.display = 'none';
        }
        
        const alasanHistoryContainer = document.getElementById('alasan-history-container');
        const alasanHistoryValue = document.getElementById('detail-alasan-history');
        const alasanHistory = Array.isArray(data.alasan_history) ? data.alasan_history : [];
        if (alasanHistory.length > 0) {
            const historyList = alasanHistory.map(item => {
                const alasanText = (item && item.alasan) ? item.alasan : '-';
                const waktuText = (item && item.created_at_formatted) ? item.created_at_formatted : '-';
                return `<li style="margin-bottom:6px;">${escapeHtml(alasanText)} <span style="color:#6b7280;">(${escapeHtml(waktuText)})</span></li>`;
            }).join('');
            alasanHistoryValue.innerHTML = `<ul style="padding-left:1.1rem; list-style:disc;">${historyList}</ul>`;
            alasanHistoryContainer.style.display = 'block';
        } else {
            alasanHistoryValue.innerHTML = '';
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
        // Notice untuk status diajukan/ditolak
        const editNoticeContainer = document.getElementById('edit-notice-container');
        const btnEditModal = document.getElementById('btnEditModal');
        const statusLower = (status || '').toLowerCase();
        if (statusLower === 'diajukan' || statusLower === 'ditolak') {
            editNoticeContainer.style.display = 'block';
            if (btnEditModal) {
                btnEditModal.style.display = 'inline-block';
                btnEditModal.onclick = function() {
                    window.location.href = 'ahlak_edit_permohonan_akta_kelahiran.php?id=' + data.id;
                };
            }
        } else {
            editNoticeContainer.style.display = 'none';
            if (btnEditModal) {
                btnEditModal.style.display = 'none';
            }
        }
        
        // Dokumen
        const dokumenContainer = document.getElementById('detail-dokumen');
        if (data.dokumen && data.dokumen.trim() !== '' && data.dokumen !== '[]') {
            const documents = JSON.parse(data.dokumen);
            let html = '';
            
            documents.forEach(doc => {
                const typeLabels = {
                    'surat_lahir': 'Surat Keterangan Lahir',
                    'buku_nikah': 'Buku Nikah / Akta Nikah / SPTJM',
                    'formulir': 'Form F-2.01 Kelahiran',
                    'kk': 'Kartu Keluarga (KK)',
                    'ktp_ortu': 'KTP Orang Tua',
                    'lainnya': 'Dokumen Lain'
                };
                
                const typeLabel = typeLabels[doc.type] || 'Dokumen';
                const fileName = doc.original_name || 'Dokumen';
                const filePath = doc.path || '';
                
                if (filePath) {
                    html += `
                        <div class="document-item">
                            <span class="doc-type">${typeLabel}:</span>
                            <a href="${filePath}" target="_blank" class="doc-link">${fileName}</a>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="document-item">
                            <span class="doc-type">${typeLabel}:</span>
                            <span class="doc-missing">${fileName} (File tidak ditemukan)</span>
                        </div>
                    `;
                }
            });
            
            dokumenContainer.innerHTML = html;
        } else {
            dokumenContainer.innerHTML = '<div class="document-item"><span class="doc-missing">Tidak ada dokumen</span></div>';
        }
        const delivery = data.email_delivery || {};
        const operatorFiles = Array.isArray(delivery.files) ? delivery.files : [];
        if (operatorFiles.length) {
            dokumenContainer.insertAdjacentHTML('beforeend', `<h4 style="margin:16px 0 8px">Dokumen Hasil dari Operator</h4>${operatorFiles.map(file => `
                <div class="document-item"><span class="doc-type">Dokumen Hasil:</span>
                <a href="${escapeHtml(file.download_url)}" class="doc-link" data-feedback-table="tb_akta_kelahiran_ahlak" data-feedback-id="${data.id}">${escapeHtml(file.original_name || 'Download Dokumen')}</a></div>`).join('')}`);
        }
        const deliveryStatus = String(delivery.status || '');
        if (['sebagian_diunggah_perlu_pengajuan_baru', 'perlu_pengajuan_baru'].includes(deliveryStatus)) {
            const operatorNote = String(delivery.catatan_operator || '').trim();
            dokumenContainer.insertAdjacentHTML('beforeend', `
                <div style="margin-top:12px;padding:12px;background:#fffbeb;color:#92400e;border-radius:8px;overflow-wrap:anywhere">
                    <strong>Catatan operator:</strong>
                    <div style="margin-top:5px;white-space:pre-wrap">${escapeHtml(operatorNote || '-')}</div>
                </div>`);
        }
        
        // Tampilkan modal
        document.getElementById('detailModal').classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
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
    
    // Close modal dengan ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeDetailModal();
        }
    });
    
    // Close modal dengan klik di luar
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
  if (!/^[1-9][0-9]{0,9}$/.test(id || '') || query.get('jenis') !== 'kelahiran') return;
  const button = document.querySelector('.btn-detail[data-request-id="' + id + '"]');
  if (button) button.click();
});
</script>
</body>
</html>
