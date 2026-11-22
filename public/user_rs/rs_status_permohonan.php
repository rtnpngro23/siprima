<?php
require_once __DIR__ . '/../user/service_tracking.php';
require_once '../error_handler.php';
// Gunakan middleware khusus RS
include '../../app/middleware/user_rs_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../user/email_delivery_confirmation_ui.php';

$kelahiran_created_expr = 'created_at';
$kematian_created_expr = 'created_at';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$id_rs = (int)$_SESSION['id_rs'];

// Ambil data nama rumah sakit untuk ditampilkan
$rs_nama = $_SESSION['nama_rs'] ?? 'Rumah Sakit';

// Pagination settings
$limit = 10; // Jumlah data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Get total count of all permohonan (both kelahiran and kematian)
$totalData = 0;
$totalPages = 0;
$permohonan = [];
try {
$countQuery = $koneksi->query("
    SELECT COUNT(*) as total FROM (
        SELECT id, 'kelahiran' as jenis, {$kelahiran_created_expr} as created_at FROM tb_akta_kelahiran_rs WHERE id_user = $user_id
        UNION ALL
        SELECT id, 'kematian' as jenis, {$kematian_created_expr} as created_at FROM tb_akta_kematian_rs WHERE id_user = $user_id
    ) as combined
");

$totalData = $countQuery ? (int)($countQuery->fetch_assoc()['total'] ?? 0) : 0;
$totalPages = ceil($totalData / $limit);

// Get data with pagination (latest first) - Hanya data ringkas untuk tabel
$query = $koneksi->query("
    SELECT * FROM (
        SELECT 
            id,
            'kelahiran' as jenis,
            nama_bayi as nama,
            nama_kecamatan,
            nama_desa,
            status,
            alasan,
            '' as keterangan,
            DATE_FORMAT({$kelahiran_created_expr}, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(updated_at, '%d-%m-%Y %H:%i') as tanggal_update,
            '' as nama_folder,
            updated_at as status_updated_at,
            {$kelahiran_created_expr} as created_at
        FROM tb_akta_kelahiran_rs 
        WHERE id_user = $user_id
        
        UNION ALL
        
        SELECT 
            id,
            'kematian' as jenis,
            nama_meninggal as nama,
            nama_kecamatan,
            nama_desa,
            status,
            alasan,
            '' as keterangan,
            DATE_FORMAT({$kematian_created_expr}, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(updated_at, '%d-%m-%Y %H:%i') as tanggal_update,
            '' as nama_folder,
            updated_at as status_updated_at,
            {$kematian_created_expr} as created_at
        FROM tb_akta_kematian_rs 
        WHERE id_user = $user_id
    ) as combined
    ORDER BY created_at DESC
    LIMIT $limit OFFSET $offset
");

while ($query && ($row = $query->fetch_assoc())) {
    $permohonan[] = $row;
}
} catch (Throwable $e) {
    $totalData = 0;
    $totalPages = 0;
    $permohonan = [];
}

// Function to get status badge color
function getStatusBadge($status) {
    switch ($status) {
        case 'diajukan':
            return '<span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-0.5 rounded border border-yellow-300">Diajukan</span>';
        case 'diproses':
            return '<span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded border border-blue-300">Diproses</span>';
        case 'selesai':
            return '<span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded border border-green-300">Selesai</span>';
        case 'ditolak':
            return '<span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded border border-red-300">Ditolak</span>';
        default:
            return '<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded border border-gray-300">' . $status . '</span>';
    }
}

// Function to get jenis badge
function getJenisBadge($jenis) {
    if ($jenis == 'kelahiran') {
        return '<span class="bg-cyan-100 text-cyan-800 text-xs font-medium px-2.5 py-0.5 rounded border border-cyan-300">Akta Kelahiran</span>';
    } else {
        return '<span class="bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-0.5 rounded border border-purple-300">Akta Kematian</span>';
    }
}

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Tentukan base URL untuk dokumen sesuai dengan struktur folder
$current_path = $_SERVER['PHP_SELF'];
$base_path_user = '/siprima/user_rs/'; // Default path sesuai struktur

// Cek jika kita berada di subfolder
if (strpos($current_path, '/siprima/user_rs/') !== false) {
    $base_path_user = '/siprima/user_rs/';
} elseif (strpos($current_path, '/user_rs/') !== false) {
    $base_path_user = '/user_rs/';
}

$base_url_user = 'http://' . $_SERVER['HTTP_HOST'] . $base_path_user;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Status Permohonan - <?= esc($rs_nama) ?></title>
  <link rel="icon" href="../assets/st sehati.png" type="image/png">
  <link rel="stylesheet" href="css/rs_status_permohonan.css">

  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  
    <link rel="stylesheet" href="../assets/css/service-timeline.css?v=20260908-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-6">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

  <div class="layout">

    <aside class="sidebar">
      <div class="logo">ST SEHATI - <?= esc($rs_nama) ?></div>
      <nav>
        <ul>
          <li><a href="rs_dashboard_user.php">Dashboard</a></li>
          <li><a href="rs_ajukan_permohonan.php">Ajukan Permohonan</a></li>
          <li class="active">Status Permohonan</li>
          <li><a href="rs_profil.php">Profil Saya</a></li>
          <li><a href="rs_formulir.php">Formulir Kependudukan</a></li>
          <li id="logoutBtn">Logout</li>
        </ul>
      </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header">
        <div class="left-header">
          <div class="title">Status Permohonan</div>
          <div class="subtitle"><?= esc($rs_nama) ?></div>
        </div>
        <div class="user-info">
          <span><?= esc($_SESSION['nama']) ?></span>
          <span class="role-badge"><?= strtoupper($_SESSION['role']) ?></span>
          <?php include __DIR__ . '/../user/notification_widget.php'; ?>
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <!-- Stats Cards -->
        <div class="stats-grid">
          <?php
          // Query untuk mendapatkan statistik
          $statsQuery = null;
          try {
            $statsQuery = $koneksi->query("
            SELECT 
                jenis,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) as diajukan,
                SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) as diproses,
                SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) as selesai,
                SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) as ditolak
            FROM (
                SELECT 'kelahiran' as jenis, status FROM tb_akta_kelahiran_rs WHERE id_user = $user_id
                UNION ALL
                SELECT 'kematian' as jenis, status FROM tb_akta_kematian_rs WHERE id_user = $user_id
            ) as combined
            GROUP BY jenis
            ");
          } catch (Throwable $e) {
            $statsQuery = null;
          }
          
          $stats = ['kelahiran' => [], 'kematian' => []];
          while ($statsQuery && ($row = $statsQuery->fetch_assoc())) {
              $stats[$row['jenis']] = $row;
          }
          ?>
          
          <div class="stat-card">
            <div class="stat-icon" style="background: #ebf8ff; color: #2c5282;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                <line x1="9" y1="9" x2="9.01" y2="9"></line>
                <line x1="15" y1="9" x2="15.01" y2="9"></line>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Akta Kelahiran</h3>
              <div class="number"><?= isset($stats['kelahiran']['total']) ? $stats['kelahiran']['total'] : 0 ?></div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon" style="background: #fee2e2; color: #991b1b;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Akta Kematian</h3>
              <div class="number"><?= isset($stats['kematian']['total']) ? $stats['kematian']['total'] : 0 ?></div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon" style="background: #f0f9ff; color: #0369a1;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Total Permohonan</h3>
              <div class="number"><?= $totalData ?></div>
            </div>
          </div>
        </div>

        <!-- Table Card -->
        <div class="table-card">
          <div class="table-header">
            <h2 class="table-title">Riwayat Permohonan</h2>
          </div>

          <div class="table-container">
            <?php if (empty($permohonan)): ?>
              <div class="empty-state">
                <div class="icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                  </svg>
                </div>
                <h3>Belum ada permohonan</h3>
                <p>Anda belum mengajukan permohonan akta kelahiran atau kematian.</p>
              </div>
            <?php else: ?>
              <table>
                <thead>
                  <tr>
                    <th>No</th>
                    <th>Jenis</th>
                    <th>Nama</th>
                    <th>Lokasi</th>
                    <th>Status</th>
                    <th>Tanggal Diajukan</th>
                    <th>Aksi</th>
                    <th>Konfirmasi Dokumen</th>
                  </tr>
                </thead>
                <tbody id="tabel-permohonan-konten">
                  <?php 
                  $counter = ($page - 1) * $limit + 1;
                  foreach ($permohonan as $row): 
                  ?>
                    <tr>
                      <td><?= $counter++ ?></td>
                      <td><?= getJenisBadge($row['jenis']) ?></td>
                      <td>
                        <div class="font-medium text-gray-900"><?= esc($row['nama']) ?></div>
                        <div class="text-sm text-gray-500"><?= esc($row['keterangan']) ?></div>
                      </td>
                      <td>
                        <div class="text-sm"><?= esc($row['nama_kecamatan']) ?></div>
                        <div class="text-xs text-gray-500"><?= esc($row['nama_desa']) ?></div>
                      </td>
                      <td>
                        <div class="mb-1"><?= getStatusBadge($row['status']) ?></div>
                        <?php if (!empty($row['alasan'])): ?>
                          <div class="text-xs text-gray-600 mt-1">Alasan: <?= esc($row['alasan']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div>
                          <small>Pengajuan:</small> <?= esc($row['tanggal_diajukan'] ?? '-') ?><br>
                          <small style="display:block;margin-top:6px">Status diperbarui: <?= esc(serviceTrackingUpdatedLabel($koneksi, ($row['jenis'] === 'kelahiran' ? 'tb_akta_kelahiran_rs' : 'tb_akta_kematian_rs'), (int)$row['id'])) ?></small><small>Update:</small>
                          <?= esc((!empty($row['tanggal_update']) && $row['tanggal_update'] !== $row['tanggal_diajukan']) ? $row['tanggal_update'] : '-') ?>
                        </div>
                      </td>
                      <td>
                        <div class="btn-group">
                          <button class="btn-view" onclick="showDetail(<?= $row['id'] ?>, '<?= $row['jenis'] ?>')">
                            Lihat Detail
                          </button>
                          <?php if (in_array(strtolower((string)$row['status']), ['diajukan', 'ditolak'], true)): ?>
                            <button class="btn-edit" onclick="editPermohonan(<?= $row['id'] ?>, '<?= $row['jenis'] ?>')">
                              Edit & Ajukan Kembali
                            </button>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td><?php
                        $deliveryTable = $row['jenis'] === 'kelahiran' ? 'tb_akta_kelahiran_rs' : 'tb_akta_kematian_rs';
                        try {
                            echo email_delivery_confirmation_ui(
                                $koneksi, $deliveryTable, (int)$row['id'], $user_id,
                                (string)$row['status'], (string)($row['status_updated_at'] ?: $row['created_at'])
                            );
                        } catch (Throwable $e) {
                            echo '<span class="text-gray-500">Belum tersedia</span>';
                        }
                      ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>

              <!-- Pagination -->
              <?php if ($totalPages > 1): ?>
                <div class="pagination">
                  <button class="page-btn" onclick="changePage(<?= $page - 1 ?>)" <?= $page <= 1 ? 'disabled' : '' ?>>
                    &laquo; Sebelumnya
                  </button>
                  
                  <div class="page-info">
                    Halaman <?= $page ?> dari <?= $totalPages ?>
                  </div>
                  
                  <?php
                  // Show page numbers
                  $startPage = max(1, $page - 2);
                  $endPage = min($totalPages, $page + 2);
                  
                  for ($i = $startPage; $i <= $endPage; $i++):
                  ?>
                    <button class="page-btn <?= $i == $page ? 'active' : '' ?>" onclick="changePage(<?= $i ?>)">
                      <?= $i ?>
                    </button>
                  <?php endfor; ?>
                  
                  <button class="page-btn" onclick="changePage(<?= $page + 1 ?>)" <?= $page >= $totalPages ? 'disabled' : '' ?>>
                    Selanjutnya &raquo;
                  </button>
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
    <div class="modal-box">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTitle">Detail Permohonan</h3>
        <button class="btn-close" onclick="hideDetailModal()">&times;</button>
      </div>
      
      <div id="modalContent">
        <!-- Content will be loaded via AJAX -->
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
  // JavaScript untuk halaman status permohonan RS
  document.addEventListener('DOMContentLoaded', function() {
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
      if (!logoutModal) return;
      
      logoutModal.classList.add('show');
      document.body.style.overflow = 'hidden';
    }
    
    function hideLogoutModal() {
      if (!logoutModal) return;
      
      logoutModal.classList.remove('show');
      document.body.style.overflow = '';
    }
    
    // Create logout modal if it doesn't exist
    if (!logoutModal) {
      const modalHtml = `
        <div class="modal-overlay" id="logoutModal">
          <div class="modal-box">
            <h3 class="modal-title">Konfirmasi Logout</h3>
            <p>Apakah Anda yakin ingin logout?</p>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
              <button id="confirmLogout" class="btn-view" style="background: #e74c3c;">Ya, Logout</button>
              <button id="cancelLogout" class="btn-view" style="background: #6b7280;">Batal</button>
            </div>
          </div>
        </div>
      `;
      
      document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
    
    // Reassign elements after modal creation
    const newLogoutModal = document.getElementById('logoutModal');
    const newConfirmLogout = document.getElementById('confirmLogout');
    const newCancelLogout = document.getElementById('cancelLogout');
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (newConfirmLogout) newConfirmLogout.addEventListener('click', () => window.location.href = '../logout_pasutri_ahlak.php');
    if (newCancelLogout) newCancelLogout.addEventListener('click', hideLogoutModal);
    
    if (newLogoutModal) {
      newLogoutModal.addEventListener('click', function(event) {
        if (event.target === newLogoutModal) hideLogoutModal();
      });
    }
    
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        const detailModal = document.getElementById('detailModal');
        if (detailModal && detailModal.classList.contains('show')) {
          hideDetailModal();
        }
        if (newLogoutModal && newLogoutModal.classList.contains('show')) {
          hideLogoutModal();
        }
      }
    });
  });

  // Function to change page
  function changePage(page) {
    if (page < 1 || page > <?= $totalPages ?>) return;
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    window.location.href = url.toString();
  }

  // Function to redirect to edit page
  function editPermohonan(id, jenis) {
    if (jenis === 'kelahiran') {
      window.location.href = `rs_edit_akta_kelahiran.php?id=${id}`;
    } else {
      window.location.href = `rs_edit_akta_kematian.php?id=${id}`;
    }
  }

  // Detail Modal Functions
  const detailModal = document.getElementById('detailModal');
  const modalTitle = document.getElementById('modalTitle');
  const modalContent = document.getElementById('modalContent');

  function showDetail(id, jenis) {
    // Show loading state
    modalContent.innerHTML = '<div class="text-center py-8">Memuat data...</div>';
    detailModal.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // **PERBAIKAN PATH: Gunakan path yang sama dengan struktur folder**
    // get_detail_permohonan.php berada di folder yang sama dengan file ini
    const apiPath = 'get_detail_permohonan.php';
    
    // Fetch data via AJAX
    fetch(`${apiPath}?id=${id}&jenis=${jenis}`)
      .then(response => {
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        return response.json();
      })
      .then(data => {
        if (data.error) {
          modalContent.innerHTML = `<div class="text-red-600 text-center py-8">${escapeHtml(data.error)}</div>`;
          return;
        }
        
        // Debug: Log data untuk pemeriksaan
        console.log('Data received:', data);
        
        // Set modal title
        const jenisText = jenis === 'kelahiran' ? 'Akta Kelahiran' : 'Akta Kematian';
        modalTitle.textContent = `Detail Permohonan ${jenisText}`;
        
        // Get status class
        let statusClass = '';
        let statusText = data.status;
        switch(data.status) {
          case 'diajukan':
            statusClass = 'bg-yellow-100 text-yellow-800 border-yellow-300';
            statusText = 'Diajukan';
            break;
          case 'diproses':
            statusClass = 'bg-blue-100 text-blue-800 border-blue-300';
            statusText = 'Diproses';
            break;
          case 'selesai':
            statusClass = 'bg-green-100 text-green-800 border-green-300';
            statusText = 'Selesai';
            break;
          case 'ditolak':
            statusClass = 'bg-red-100 text-red-800 border-red-300';
            statusText = 'Ditolak';
            break;
          default:
            statusClass = 'bg-gray-100 text-gray-800 border-gray-300';
        }
        
        // Parse dokumen
        let dokumen = [];
        try {
          dokumen = JSON.parse(data.dokumen || '[]');
        } catch (e) {
          console.error('Error parsing dokumen JSON:', e);
          dokumen = [];
        }

        const alasanHistory = Array.isArray(data.alasan_history) ? data.alasan_history : [];
        const historyAlasanHtml = alasanHistory.length
          ? `<div class="text-gray-700 text-sm mt-3">
              <strong>Riwayat Alasan Penolakan:</strong>
              <ul class="mt-2" style="padding-left: 1.1rem; list-style: disc;">
                ${alasanHistory.map((item) => `<li class="mb-1">
                    ${escapeHtml(item.alasan || '-')}
                    <span class="text-gray-500">(${escapeHtml(item.created_at_formatted || '-')})</span>
                  </li>`).join('')}
              </ul>
            </div>`
          : '';
        
        // Create modal content
        let content = '';
        
        // General information section
        content += `
          <div class="detail-grid">
            <div class="detail-group">
              <label class="detail-label">Jenis Permohonan</label>
              <div class="detail-value">
                ${jenis === 'kelahiran' ? 
                  '<span class="bg-cyan-100 text-cyan-800 text-xs font-medium px-2.5 py-0.5 rounded border border-cyan-300">Akta Kelahiran</span>' : 
                  '<span class="bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-0.5 rounded border border-purple-300">Akta Kematian</span>'}
              </div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">Status</label>
              <div class="detail-value">
                <span class="${statusClass} text-xs font-medium px-2.5 py-0.5 rounded border">${statusText}</span>
                ${data.alasan ? 
                  `<div class="text-gray-700 text-sm mt-2">
                    <strong>Alasan Status:</strong><br>
                    ${escapeHtml(data.alasan)}
                  </div>` : ''}
              </div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">Keterangan</label>
              <div class="detail-value">${escapeHtml(data.keterangan || '-')}</div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">Tanggal Diajukan</label>
              <div class="detail-value">${escapeHtml(data.tanggal_diajukan || '-')}</div>
            </div>
          </div>
          
          ${data.status_history_html || ''}
          <div class="section-header">Data Pemohon</div>
          <div class="detail-grid">
            <div class="detail-group">
              <label class="detail-label">Nama Pemohon</label>
              <div class="detail-value">${escapeHtml(data.nama_pemohon_full || '-')}</div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">NIK Pemohon</label>
              <div class="detail-value">${escapeHtml(data.nik_pemohon_full || '-')}</div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">No HP</label>
              <div class="detail-value">${escapeHtml(data.no_hp_pemohon || '-')}</div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">Email</label>
              <div class="detail-value">${escapeHtml(data.email_pemohon || '-')}</div>
            </div>
          </div>
          
          <div class="section-header">Lokasi</div>
          <div class="detail-grid">
            <div class="detail-group">
              <label class="detail-label">Kecamatan</label>
              <div class="detail-value">${escapeHtml(data.nama_kecamatan || '-')}</div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">Desa</label>
              <div class="detail-value">${escapeHtml(data.nama_desa || '-')}</div>
            </div>
          </div>
        `;
        
        // Jenis-specific sections
        if (jenis === 'kelahiran') {
          content += `
            <div class="section-header">Data Bayi</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Nama Bayi</label>
                <div class="detail-value">${escapeHtml(data.nama_bayi || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Jenis Kelamin</label>
                <div class="detail-value">
                  ${data.jenis_kelamin === 'L' ? 'Laki-laki' : 
                    data.jenis_kelamin === 'P' ? 'Perempuan' : 
                    escapeHtml(data.jenis_kelamin || '-')}
                </div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Tempat Lahir</label>
                <div class="detail-value">${escapeHtml(data.tempat_lahir || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Tanggal Lahir</label>
                <div class="detail-value">${escapeHtml(data.tanggal_lahir_formatted || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Waktu Lahir</label>
                <div class="detail-value">${escapeHtml(data.waktu_lahir_formatted || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Anak ke-</label>
                <div class="detail-value">${escapeHtml(data.urutan_kelahiran || '-')}</div>
              </div>
            </div>
            
            <div class="section-header">Data Orang Tua</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Nama Ayah</label>
                <div class="detail-value">${escapeHtml(data.nama_ayah || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">NIK Ayah</label>
                <div class="detail-value">${escapeHtml(data.nik_ayah || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Nama Ibu</label>
                <div class="detail-value">${escapeHtml(data.nama_ibu || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">NIK Ibu</label>
                <div class="detail-value">${escapeHtml(data.nik_ibu || '-')}</div>
              </div>
            </div>
          `;
        } else {
          // Kematian
          content += `
            <div class="section-header">Data Orang yang Meninggal</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Nama yang Meninggal</label>
                <div class="detail-value">${escapeHtml(data.nama_meninggal || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">NIK yang Meninggal</label>
                <div class="detail-value">${escapeHtml(data.nik_meninggal || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Tanggal Kematian</label>
                <div class="detail-value">${escapeHtml(data.tanggal_kematian_formatted || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Tempat Kematian</label>
                <div class="detail-value">${escapeHtml(data.tempat || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Dusun/Alamat</label>
                <div class="detail-value">${escapeHtml(data.dusun || '-')}</div>
              </div>
            </div>
            
            <div class="section-header">Data Orang Tua</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Nama Ayah</label>
                <div class="detail-value">${escapeHtml(data.nama_ayah || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">NIK Ayah</label>
                <div class="detail-value">${escapeHtml(data.nik_ayah || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Nama Ibu</label>
                <div class="detail-value">${escapeHtml(data.nama_ibu || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">NIK Ibu</label>
                <div class="detail-value">${escapeHtml(data.nik_ibu || '-')}</div>
              </div>
            </div>
          `;
        }
        
        // Saksi section (common for both)
        content += `
          <div class="section-header">Data Saksi</div>
          <div class="detail-grid">
            <div class="detail-group">
              <label class="detail-label">Nama Saksi 1</label>
              <div class="detail-value">${escapeHtml(data.nama_saksi1 || '-')}</div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">NIK Saksi 1</label>
              <div class="detail-value">${escapeHtml(data.nik_saksi1 || '-')}</div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">Nama Saksi 2</label>
              <div class="detail-value">${escapeHtml(data.nama_saksi2 || '-')}</div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">NIK Saksi 2</label>
              <div class="detail-value">${escapeHtml(data.nik_saksi2 || '-')}</div>
            </div>
          </div>
        `;
        
        // Dokumen section - **PERBAIKAN UTAMA UNTUK PATH DOKUMEN**
        if (dokumen.length > 0) {
          content += `
            <div class="section-header">Dokumen Terupload</div>
            <div class="dokumen-grid">
              ${dokumen.map((doc, index) => {
                let docUrl = '';
                let fileName = doc.original_name || 'File ' + (index + 1);
                
                // Prioritaskan full_url dari API (jika sudah ada)
                if (doc.full_url) {
                  docUrl = doc.full_url;
                }
                // Jika ada path, buat URL lengkap
                else if (doc.path) {
                  // Bersihkan path
                  let cleanPath = doc.path;
                  
                  // Hapus awalan siprima/ atau user_rs/ jika ada
                  cleanPath = cleanPath.replace(/^(siprima\/|user_rs\/)/, '');
                  
                  // Tambahkan base URL sesuai struktur
                  // Base URL dari PHP: <?= $base_url_user ?>
                  const baseUrl = '<?= $base_url_user ?>';
                  docUrl = baseUrl + cleanPath;
                }
                
                return `
                  <div class="dokumen-item">
                    <div class="dokumen-type">${getDocTypeText(doc.type)}</div>
                    <div class="dokumen-name">${escapeHtml(fileName)}</div>
                    ${docUrl ? `
                      <a href="${escapeHtml(docUrl)}" target="_blank" class="btn-view mt-2">
                        Lihat Dokumen
                      </a>
                    ` : `
                      <span class="text-red-500 text-xs">URL tidak tersedia</span>
                    `}
                  </div>
                `;
              }).join('')}
            </div>
          `;
        } else {
          content += `
            <div class="section-header">Dokumen</div>
            <div class="detail-value">Tidak ada dokumen</div>
          `;
        }

        const delivery = data.email_delivery || {};
        const operatorFiles = Array.isArray(delivery.files) ? delivery.files : [];
        const deliveryTable = jenis === 'kelahiran' ? 'tb_akta_kelahiran_rs' : 'tb_akta_kematian_rs';
        if (operatorFiles.length > 0) {
          content += `<div class="section-header">Dokumen Hasil dari Operator</div><div class="dokumen-grid">
            ${operatorFiles.map(file => `<div class="dokumen-item"><div class="dokumen-type">Dokumen Hasil</div>
              <div class="dokumen-name">${escapeHtml(file.original_name || 'Dokumen.pdf')}</div>
              <a href="${escapeHtml(file.download_url)}" class="btn-view mt-2" data-feedback-table="${deliveryTable}" data-feedback-id="${id}">Download Dokumen</a></div>`).join('')}
          </div>`;
        }
        const deliveryStatus = String(delivery.status || '');
        if (['sebagian_diunggah_perlu_pengajuan_baru', 'perlu_pengajuan_baru'].includes(deliveryStatus)) {
          const operatorNote = String(delivery.catatan_operator || '').trim();
          content += `<div class="section-header">Catatan Operator</div>
            <div class="detail-value" style="white-space:pre-wrap;overflow-wrap:anywhere">${escapeHtml(operatorNote || '-')}</div>`;
        }
        
                
        // Add edit button when status is still submittable
        const statusLower = (data.status || '').toLowerCase();
        if (statusLower === 'diajukan' || statusLower === 'ditolak') {
          content += `
            <div class="mt-6 pt-4 border-t">
              <div class="flex justify-between items-center">
                <button class="btn-edit" onclick="editPermohonan(${id}, '${jenis}')">
                  Edit & Ajukan Kembali
                </button>
              </div>
            </div>
          `;
        } else {
          content += `
            
          `;
        }
        
        modalContent.innerHTML = content;
      })
      .catch(error => {
        console.error('Error fetching detail:', error);
        modalContent.innerHTML = '<div class="text-red-600 text-center py-8">Terjadi kesalahan saat memuat data</div>';
      });
  }

  function hideDetailModal() {
    if (!detailModal) return;
    detailModal.classList.remove('show');
    document.body.style.overflow = '';
  }

  // Helper function to escape HTML
  function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Helper function to get document type text
  function getDocTypeText(type) {
    if (!type) return 'Dokumen';
    
    const types = {
      'surat_keterangan_rs': 'Surat Keterangan RS',
      'surat_keterangan_kematian_rs': 'Surat Keterangan Kematian RS',
      'formulir': 'Formulir F-2.01',
      'formulir_f201_kematian': 'Formulir F-2.01 Kematian',
      'buku_nikah': 'Buku Nikah/SPTJM',
      'identitas_meninggal': 'Identitas Meninggal',
      'kk': 'Kartu Keluarga',
      'ktp_ortu': 'KTP Orang Tua',
      'ktp_ayah': 'KTP Ayah',
      'ktp_ibu': 'KTP Ibu',
      'lainnya': 'Dokumen Lainnya'
    };
    return types[type] || type.replace(/_/g, ' ');
  }

  // Close modal when clicking outside
  if (detailModal) {
    detailModal.addEventListener('click', function(event) {
      if (event.target === detailModal) {
        hideDetailModal();
      }
    });
  }
  
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
  const query = new URLSearchParams(location.search), id = query.get('detail_id'), jenis = query.get('jenis');
  if (/^[1-9][0-9]{0,9}$/.test(id || '') && ['kelahiran','kematian'].includes(jenis)) showDetail(Number(id), jenis);
});
</script>
</body>
</html>
