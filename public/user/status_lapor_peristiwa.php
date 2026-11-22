<?php
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/document_token.php';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// Konfigurasi pagination
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// Filter status dari URL
$filter_status = isset($_GET['filter']) ? $_GET['filter'] : '';
$filter_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : '';

// Query untuk menghitung total data dengan filter
$count_query = "SELECT COUNT(*) as total FROM tb_laporan_peristiwa WHERE id_user = ?";
$count_params = [$user_id];
$count_types = 'i';

if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
    $count_query .= " AND status = ?";
    $count_params[] = $filter_status;
    $count_types .= 's';
}

if ($filter_jenis && in_array($filter_jenis, ['lahir', 'mati', 'rentan_adminduk'])) {
    $count_query .= " AND jenis_peristiwa = ?";
    $count_params[] = $filter_jenis;
    $count_types .= 's';
}

$count_stmt = $koneksi->prepare($count_query);
if (!$count_stmt) {
    die("Prepare failed: " . $koneksi->error);
}

$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_row = $count_result->fetch_assoc();
$total_records = $total_row['total'];
$count_stmt->close();

// Hitung total halaman
$total_pages = ceil($total_records / $records_per_page);

// Hitung offset
$offset = ($page - 1) * $records_per_page;

// Query untuk mengambil data laporan peristiwa dengan pagination dan filter
$query = "SELECT 
    id_lapor, 
    created_at, 
    nama_kecamatan, 
    nama_desa, 
    jenis_peristiwa,
    nama_subjek, 
    keterangan,
    dokumen, 
    status,
    alasan,
    DATE_FORMAT(created_at, '%d %M %Y %H:%i') as formatted_date
    FROM tb_laporan_peristiwa 
    WHERE id_user = ?";
    
if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
    $query .= " AND status = ?";
}

if ($filter_jenis && in_array($filter_jenis, ['lahir', 'mati', 'rentan_adminduk'])) {
    $query .= " AND jenis_peristiwa = ?";
}

$query .= " ORDER BY created_at DESC, id_lapor DESC LIMIT ? OFFSET ?";

$stmt = $koneksi->prepare($query);
if (!$stmt) {
    die("Prepare failed: " . $koneksi->error);
}

// Bind parameters
$params = [$user_id];
$types = 'i';

if ($filter_status) {
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_jenis) {
    $params[] = $filter_jenis;
    $types .= 's';
}

$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);

// **PERBAIKAN: Process dokumen paths untuk memastikan path web yang benar**
foreach ($rows as &$row) {
    if (isset($row['dokumen']) && $row['dokumen'] && $row['dokumen'] !== 'null' && $row['dokumen'] !== '[]') {
        $dokumen = json_decode($row['dokumen'], true);
        if (is_array($dokumen) && !empty($dokumen)) {
            foreach ($dokumen as &$doc) {
                if (isset($doc['path']) && !empty($doc['path'])) {
                    $url = siprima_document_token_url(__DIR__, (string)$doc['path']);
                    $doc['path'] = $url ?? '';
                    $doc['web_path'] = $url ?? '';
                    $doc['download_url'] = $url === null ? '' : $url . '&download=1';
                }
            }
            $row['dokumen'] = json_encode($dokumen);
        } else {
            $row['dokumen'] = siprima_mask_document_json(__DIR__, (string)$row['dokumen']);
        }
    }
}
unset($row); // Unset reference

function esc($s){ 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function shortText($s, $n=70){ 
    if (strlen($s) <= $n) return $s; 
    return substr($s,0,$n-1).'…'; 
}

// Fungsi untuk menampilkan dokumen
function displayDocuments($dokumenJson) {
    if (empty($dokumenJson)) return '<div class="document-item"><span class="doc-missing">Tidak ada dokumen</span></div>';
    
    $documents = json_decode($dokumenJson, true);
    if (!is_array($documents) || empty($documents)) {
        return '<div class="document-item"><span class="doc-missing">Tidak ada dokumen</span></div>';
    }
    
    $html = '<div class="document-list">';
    foreach ($documents as $doc) {
        $docType = $doc['type'] ?? 'lainnya';
        $docName = $doc['original_name'] ?? 'Dokumen';
        $docPath = $doc['path'] ?? '';
        
        $typeLabels = [
            'surat_keterangan_lahir' => 'Surat Keterangan Lahir',
            'kk' => 'Kartu Keluarga',
            'ktp' => 'KTP',
            'akta_lahir' => 'Akta Lahir',
            'akta_kematian' => 'Akta Kematian',
            'ijazah' => 'Ijazah',
            'buku_nikah' => 'Buku Nikah',
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

// Fungsi untuk menampilkan label jenis peristiwa
function getJenisPeristiwaLabel($jenis) {
    $labels = [
        'lahir' => 'Kelahiran',
        'mati' => 'Kematian',
        'rentan_adminduk' => 'Rentan Administrasi'
    ];
    return $labels[$jenis] ?? $jenis;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Status Laporan Peristiwa</title>
  <!-- Favicon inline untuk menghindari 404 -->
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📝</text></svg>">
  <!-- CSS External -->
  <link rel="stylesheet" href="css/status_permohonan_kk.css">
  <link rel="stylesheet" href="css/status_lapor_peristiwa.css">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

  <div class="layout">
    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header user-general-header">
        <?php
        require_once __DIR__ . '/user_header_brand.php';
        render_user_header_brand('Status Laporan Peristiwa Kependudukan');
        ?>
        <div class="user-info">
          Hai, <?= esc($_SESSION['nama']) ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <div class="form-card">
          <div class="form-header">
            <div class="icon-container">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
              </svg>
            </div>
            <div>
              <h2 class="form-title">Status Laporan Peristiwa Kependudukan</h2>
              <p class="helper">Pantau status laporan peristiwa (kelahiran, kematian, rentan administrasi) yang telah Anda ajukan.</p>
            </div>
          </div>

          <div class="filter-section">
            <div class="filters">
              <div class="filter-group">
                <label for="filterJenis">Jenis Peristiwa</label>
                <select id="filterJenis" class="select">
                  <option value="">Semua Jenis</option>
                  <option value="lahir" <?= $filter_jenis == 'lahir' ? 'selected' : '' ?>>Kelahiran</option>
                  <option value="mati" <?= $filter_jenis == 'mati' ? 'selected' : '' ?>>Kematian</option>
                  <option value="rentan_adminduk" <?= $filter_jenis == 'rentan_adminduk' ? 'selected' : '' ?>>Rentan Administrasi</option>
                </select>
              </div>
              
              <div class="filter-group">
                <label for="filterStatus">Status Laporan</label>
                <select id="filterStatus" class="select">
                  <option value="">Semua Status</option>
                  <option value="diajukan" <?= $filter_status == 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                  <option value="diproses" <?= $filter_status == 'diproses' ? 'selected' : '' ?>>Diproses</option>
                  <option value="ditolak" <?= $filter_status == 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                  <option value="selesai" <?= $filter_status == 'selesai' ? 'selected' : '' ?>>Selesai</option>
                </select>
              </div>
              
              <button type="button" id="btnApplyFilter" class="btn-filter">Terapkan Filter</button>
              <a href="status_lapor_peristiwa.php" class="btn-reset">Reset Filter</a>
            </div>
            <a href="lapor_peristiwa.php" class="btn-new">+ Laporkan Peristiwa Baru</a>
          </div>

          <div class="table-container">
            <?php if (empty($rows)): ?>
              <div class="empty-state">
                <div class="empty-state-icon">📝</div>
                <h3 style="margin-bottom: 10px; color: #374151;">Belum Ada Laporan Peristiwa</h3>
                <p style="margin-bottom: 20px; color: #6b7280;">Anda belum mengajukan laporan peristiwa kependudukan.</p>
                <a href="lapor_peristiwa.php" class="btn-new">Laporkan Peristiwa Pertama</a>
              </div>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Tanggal Laporan</th>
                    <th>Jenis Peristiwa</th>
                    <th>Nama Subjek</th>
                    <th>Kecamatan</th>
                    <th>Desa</th>
                    <th>Status</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $start_number = ($page - 1) * $records_per_page + 1;
                  foreach ($rows as $row): 
                  ?>
                    <tr class="permohonan-row" data-status="<?= strtolower($row['status']) ?>" data-jenis="<?= $row['jenis_peristiwa'] ?>">
                      <td><?= $start_number++ ?></td>
                      <td><?= esc($row['formatted_date']) ?></td>
                      <td>
                        <span class="jenis-badge <?= 'jenis-' . $row['jenis_peristiwa'] ?>">
                          <?= esc(getJenisPeristiwaLabel($row['jenis_peristiwa'])) ?>
                        </span>
                      </td>
                      <td><?= esc($row['nama_subjek']) ?></td>
                      <td><?= esc($row['nama_kecamatan'] ?? '-') ?></td>
                      <td><?= esc($row['nama_desa'] ?? '-') ?></td>
                      <td>
                        <?php 
                          $statusClass = 'status-' . strtolower($row['status']);
                          $statusText = ucfirst($row['status']);
                        ?>
                        <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                      </td>
                      <td>
                        <div class="action-buttons">
                          <button class="btn-detail" onclick="showDetailModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)">
                            Detail
                          </button>
                          <?php if (strtolower($row['status']) === 'ditolak'): ?>
                            <a href="edit_lapor_peristiwa.php?id=<?= $row['id_lapor'] ?>" class="btn-edit">
                              Edit
                            </a>
                          <?php endif; ?>
                        </div>
                      </td>
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
                    <?php
                    $query_params = [];
                    if ($filter_status) $query_params['filter'] = $filter_status;
                    if ($filter_jenis) $query_params['jenis'] = $filter_jenis;
                    $query_params['page'] = 1;
                    $first_url = '?' . http_build_query($query_params);
                    
                    $query_params['page'] = $page - 1;
                    $prev_url = '?' . http_build_query($query_params);
                    ?>
                    <a href="<?= $first_url ?>" class="page-link">« Pertama</a>
                    <a href="<?= $prev_url ?>" class="page-link">‹ Sebelumnya</a>
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
                    $query_params = [];
                    if ($filter_status) $query_params['filter'] = $filter_status;
                    if ($filter_jenis) $query_params['jenis'] = $filter_jenis;
                    $query_params['page'] = $i;
                    $page_url = '?' . http_build_query($query_params);
                    
                    if ($i == $page):
                  ?>
                    <span class="page-link active"><?= $i ?></span>
                  <?php else: ?>
                    <a href="<?= $page_url ?>" class="page-link"><?= $i ?></a>
                  <?php
                    endif;
                  endfor;
                  
                  if ($end_page < $total_pages) {
                    echo '<span class="page-dots">...</span>';
                  }
                  ?>
                  
                  <?php if ($page < $total_pages): ?>
                    <?php
                    $query_params = [];
                    if ($filter_status) $query_params['filter'] = $filter_status;
                    if ($filter_jenis) $query_params['jenis'] = $filter_jenis;
                    $query_params['page'] = $page + 1;
                    $next_url = '?' . http_build_query($query_params);
                    
                    $query_params['page'] = $total_pages;
                    $last_url = '?' . http_build_query($query_params);
                    ?>
                    <a href="<?= $next_url ?>" class="page-link">Berikutnya ›</a>
                    <a href="<?= $last_url ?>" class="page-link">Terakhir »</a>
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
        <h2>Detail Laporan Peristiwa</h2>
        <button class="modal-close" onclick="closeDetailModal()">&times;</button>
      </div>
      <div class="modal-body">
        <div class="detail-grid">
          <div class="detail-item">
            <div class="detail-label">ID Laporan</div>
            <div class="detail-value" id="detail-id">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Tanggal Laporan</div>
            <div class="detail-value" id="detail-tanggal">-</div>
          </div>
          <div class="detail-item">
            <div class="detail-label">Jenis Peristiwa</div>
            <div class="detail-value" id="detail-jenis">-</div>
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
            <div class="detail-label">Status</div>
            <div class="detail-value" id="detail-status">-</div>
          </div>
        </div>

        <div class="detail-item">
          <div class="detail-label">Nama Subjek</div>
          <div class="detail-value detail-subject" id="detail-nama-subjek">-</div>
        </div>

        <div class="detail-item">
          <div class="detail-label">Tempat & Tanggal Peristiwa</div>
          <div class="detail-value" id="detail-tempat-tanggal">-</div>
        </div>

        <!-- Informasi Orang Tua -->
        <div id="orangtua-container" class="detail-parents" style="display: none;">
          <h4>Informasi Orang Tua</h4>
          <p id="detail-ayah">-</p>
          <p id="detail-ibu">-</p>
        </div>

        <!-- Keterangan Box -->
        <div id="keterangan-container" class="keterangan-box" style="display: none;">
          <div class="detail-label">Keterangan dari Pelapor</div>
          <div class="detail-value" id="detail-keterangan" style="white-space: pre-wrap; line-height: 1.6;"></div>
        </div>

        <div class="documents-section">
          <h3>📁 Dokumen Pendukung</h3>
          <div id="detail-dokumen">
            <!-- Dokumen akan ditampilkan di sini -->
          </div>
        </div>

        <div id="alasan-container" class="alasan-box" style="display: none;">
          <div class="detail-label">Alasan Penolakan</div>
          <div class="detail-value" id="detail-alasan" style="white-space: pre-wrap; line-height: 1.6;"></div>
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
      if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
        hideLogoutModal();
      }
    });
    
    // Filter functionality
    const filterStatus = document.getElementById('filterStatus');
    const filterJenis = document.getElementById('filterJenis');
    const btnApplyFilter = document.getElementById('btnApplyFilter');
    
    if (btnApplyFilter) {
      btnApplyFilter.addEventListener('click', function() {
        let url = 'status_lapor_peristiwa.php?';
        const params = [];
        
        if (filterStatus.value) {
          params.push('filter=' + encodeURIComponent(filterStatus.value));
        }
        
        if (filterJenis.value) {
          params.push('jenis=' + encodeURIComponent(filterJenis.value));
        }
        
        if (params.length > 0) {
          url += params.join('&');
        }
        
        window.location.href = url;
      });
    }
  });

  // Filter table rows based on status
  document.getElementById('filterStatus')?.addEventListener('change', function() {
    const filterValue = this.value.toLowerCase();
    const filterJenisValue = document.getElementById('filterJenis')?.value || '';
    const rows = document.querySelectorAll('.permohonan-row');
    
    rows.forEach(row => {
      const status = row.getAttribute('data-status');
      const jenis = row.getAttribute('data-jenis');
      
      let showRow = true;
      
      if (filterValue && status !== filterValue) {
        showRow = false;
      }
      
      if (filterJenisValue && jenis !== filterJenisValue) {
        showRow = false;
      }
      
      row.style.display = showRow ? '' : 'none';
    });
  });

  // Filter table rows based on jenis
  document.getElementById('filterJenis')?.addEventListener('change', function() {
    const filterValue = this.value;
    const filterStatusValue = document.getElementById('filterStatus')?.value.toLowerCase() || '';
    const rows = document.querySelectorAll('.permohonan-row');
    
    rows.forEach(row => {
      const status = row.getAttribute('data-status');
      const jenis = row.getAttribute('data-jenis');
      
      let showRow = true;
      
      if (filterValue && jenis !== filterValue) {
        showRow = false;
      }
      
      if (filterStatusValue && status !== filterStatusValue) {
        showRow = false;
      }
      
      row.style.display = showRow ? '' : 'none';
    });
  });

  // Fungsi untuk menampilkan jenis peristiwa
  function getJenisPeristiwaLabel(jenis) {
    const labels = {
      'lahir': 'Kelahiran',
      'mati': 'Kematian',
      'rentan_adminduk': 'Rentan Administrasi'
    };
    return labels[jenis] || jenis;
  }

  // Show Detail Modal
  function showDetailModal(data) {
    console.log('Data untuk modal:', data);
    
    // Fill modal with data
    document.getElementById('detail-id').textContent = data.id_lapor || '-';
    document.getElementById('detail-tanggal').textContent = data.formatted_date || '-';
    document.getElementById('detail-jenis').textContent = getJenisPeristiwaLabel(data.jenis_peristiwa);
    document.getElementById('detail-kecamatan').textContent = data.nama_kecamatan || '-';
    document.getElementById('detail-desa').textContent = data.nama_desa || '-';
    document.getElementById('detail-nama-subjek').textContent = data.nama_subjek || '-';
    
    // Tempat & Tanggal
    document.getElementById('detail-tempat-tanggal').textContent = '-';
    
    // Status dengan styling
    const statusElement = document.getElementById('detail-status');
    const status = data.status || 'Pending';
    statusElement.textContent = status;
    statusElement.className = 'detail-value status-badge status-' + status.toLowerCase();
    
    // Orang Tua (gunakan data tambahan dari database jika tersedia)
    const orangtuaContainer = document.getElementById('orangtua-container');
    orangtuaContainer.style.display = 'block';
    document.getElementById('detail-ayah').textContent = 'Ayah: -';
    document.getElementById('detail-ibu').textContent = 'Ibu: -';
    
    // Keterangan
    const keteranganContainer = document.getElementById('keterangan-container');
    if (data.keterangan && data.keterangan.trim() !== '') {
      document.getElementById('detail-keterangan').textContent = data.keterangan;
      keteranganContainer.style.display = 'block';
    } else {
      keteranganContainer.style.display = 'none';
    }
    
    // Dokumen - dengan penanganan yang lebih robust
    const dokumenElement = document.getElementById('detail-dokumen');
    let hasDokumen = false;
    
    // Debug: cek apa yang diterima
    console.log('Data dokumen raw:', data.dokumen, 'Tipe:', typeof data.dokumen);
    
    if (data.dokumen && data.dokumen !== 'null' && data.dokumen !== '' && data.dokumen !== '[]') {
      try {
        // Coba parse JSON
        let documents = data.dokumen;
        
        // Jika dokumen masih string, parse sebagai JSON
        if (typeof documents === 'string') {
          documents = JSON.parse(documents);
        }
        
        console.log('Documents after parse:', documents);
        
        // Validasi bahwa documents adalah array dan tidak kosong
        if (Array.isArray(documents) && documents.length > 0) {
          hasDokumen = true;
          let html = '';
          
          documents.forEach((doc, idx) => {
            console.log(`Processing doc ${idx}:`, doc);
            
            const typeLabels = {
              'surat_keterangan_lahir': 'Surat Keterangan Lahir',
              'kk': 'Kartu Keluarga',
              'ktp': 'KTP',
              'akta_lahir': 'Akta Lahir',
              'akta_kematian': 'Akta Kematian',
              'ijazah': 'Ijazah',
              'buku_nikah': 'Buku Nikah',
              'lainnya': 'Dokumen Lain'
            };
            
            const typeLabel = typeLabels[doc.type] || 'Dokumen';
            const docName = (doc.original_name || doc.name || 'Dokumen').replace(/"/g, '');
            // Gunakan web_path yang sudah diproses di PHP, fallback ke path asli
            const docPath = (doc.web_path || doc.path || '').replace(/"/g, '');
            
            if (docPath && docPath.trim() !== '') {
              html += `
                <div class="document-item">
                  <span class="doc-type">${escapeHtml(typeLabel)}:</span>
                  <a href="${escapeHtml(docPath)}" target="_blank" class="doc-link">${escapeHtml(docName)}</a>
                </div>
              `;
            } else {
              html += `
                <div class="document-item">
                  <span class="doc-type">${escapeHtml(typeLabel)}:</span>
                  <span class="doc-missing">${escapeHtml(docName)} (File tidak tersedia)</span>
                </div>
              `;
            }
          });
          
          if (html.trim() !== '') {
            dokumenElement.innerHTML = html;
          } else {
            hasDokumen = false;
          }
        }
      } catch (e) {
        console.error('Error parsing dokumen JSON:', e);
      }
    }
    
    if (!hasDokumen) {
      dokumenElement.innerHTML = '<div class="document-item"><span class="doc-missing">Tidak ada dokumen</span></div>';
    }
    
    // Helper function untuk escape HTML
    function escapeHtml(text) {
      if (!text) return '';
      const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      };
      return text.replace(/[&<>"']/g, m => map[m]);
    }
    
    // Alasan Penolakan
    const alasanContainer = document.getElementById('alasan-container');
    const alasanElement = document.getElementById('detail-alasan');
    
    if (status.toLowerCase() === 'ditolak') {
      if (data.alasan && data.alasan.trim() !== '') {
        alasanElement.textContent = data.alasan;
        alasanContainer.style.display = 'block';
      } else {
        alasanContainer.style.display = 'none';
      }
    } else {
      alasanContainer.style.display = 'none';
    }
    
    // Show modal
    document.getElementById('detailModal').classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  // Close Detail Modal
  function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('show');
    document.body.style.overflow = '';
  }

  // Close modal when clicking outside
  document.getElementById('detailModal').addEventListener('click', function(event) {
    if (event.target === this) {
      closeDetailModal();
    }
  });

  // Close modal with ESC key
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && document.getElementById('detailModal').classList.contains('show')) {
      closeDetailModal();
    }
  });
  </script>

</body>
</html>
