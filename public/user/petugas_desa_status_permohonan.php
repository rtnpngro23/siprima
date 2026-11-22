<?php
require_once __DIR__ . '/service_tracking.php';
require_once '../error_handler.php';
include '../../app/middleware/petugas_desa_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/email_delivery.php';
require_once __DIR__ . '/../functions/kk_kategori.php';

$has_resubmitted_kelahiran = false;
$has_resubmitted_kematian = false;
$has_resubmitted_kk = false;
$has_resubmitted_pindah = false;
$has_resubmitted_pindah_online = false;

$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kelahiran LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) $has_resubmitted_kelahiran = true;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_akta_kematian LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) $has_resubmitted_kematian = true;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_kartukeluarga LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) $has_resubmitted_kk = true;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_pindah_alamat LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) $has_resubmitted_pindah = true;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_pindah_online LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) $has_resubmitted_pindah_online = true;

$akta_created_expr = $has_resubmitted_kelahiran ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$kematian_created_expr = $has_resubmitted_kematian ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$kk_created_expr = $has_resubmitted_kk ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$pindah_created_expr = $has_resubmitted_pindah ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$pindah_online_created_expr = $has_resubmitted_pindah_online ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$csrf_token_value = function_exists('csrf_token') ? csrf_token() : '';
$email_delivery_pending_count = email_delivery_pending_confirmation_count($koneksi, $user_id);

// Ambil data wilayah tugas petugas desa
$user_query = $koneksi->query("SELECT 
    u.id_kecamatan,
    u.id_desa,
    k.nama_kecamatan,
    d.nama_desa
FROM tb_users u
LEFT JOIN tb_kecamatan k ON u.id_kecamatan = k.id
LEFT JOIN tb_desa d ON u.id_desa = d.id
WHERE u.id = '$user_id'");

$user_data = $user_query->fetch_assoc();
$user_kecamatan_id = (int)($user_data['id_kecamatan'] ?? 0);
$user_desa_id = (int)($user_data['id_desa'] ?? 0);
$nama_kecamatan_user = trim((string)($user_data['nama_kecamatan'] ?? ''));
$nama_desa_user = trim((string)($user_data['nama_desa'] ?? ''));

// Pagination settings
$limit = 10; // Jumlah data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Filter dari URL
$filter_status = isset($_GET['filter']) ? $_GET['filter'] : '';
$filter_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : '';
$search = trim((string)($_GET['search'] ?? ''));
$userScope = "(id_user = ? AND ((id_kecamatan = ? AND id_desa = ?) OR (nama_kecamatan = ? AND nama_desa = ?)))";

// Get total count of all permohonan untuk wilayah tugas petugas (TANPA LAPORAN PERISTIWA)
$countQuery = "SELECT COUNT(*) as total FROM (
    SELECT id, 'kelahiran' as jenis, nama_bayi as nama, nama_kecamatan, nama_desa, status, {$akta_created_expr} as created_at FROM tb_akta_kelahiran WHERE {$userScope}
    UNION ALL
    SELECT id, 'kematian' as jenis, nama_meninggal as nama, nama_kecamatan, nama_desa, status, {$kematian_created_expr} as created_at FROM tb_akta_kematian WHERE {$userScope}
    UNION ALL
    SELECT id, 'kk' as jenis, nama_kepalaKeluarga as nama, nama_kecamatan, nama_desa, status, {$kk_created_expr} as created_at FROM tb_kartukeluarga WHERE {$userScope}
    UNION ALL
    SELECT id, 'pindah' as jenis, nama_kepalakeluarga as nama, nama_kecamatan, nama_desa, status, {$pindah_created_expr} as created_at FROM tb_pindah_alamat WHERE {$userScope}
    UNION ALL
    SELECT id, 'pindah_online' as jenis, nama_kepalakeluarga as nama, nama_kecamatan, nama_desa, status, {$pindah_online_created_expr} as created_at FROM tb_pindah_online WHERE {$userScope}
) as combined";

$params = [
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user
];
$types = str_repeat('iiiss', 5);

// Tambahkan filter jika ada
if ($filter_status) {
    $countQuery = "SELECT COUNT(*) as total FROM (
        SELECT id, 'kelahiran' as jenis, nama_bayi as nama, nama_kecamatan, nama_desa, {$akta_created_expr} as created_at, status FROM tb_akta_kelahiran WHERE {$userScope} AND status = ?
        UNION ALL
        SELECT id, 'kematian' as jenis, nama_meninggal as nama, nama_kecamatan, nama_desa, {$kematian_created_expr} as created_at, status FROM tb_akta_kematian WHERE {$userScope} AND status = ?
        UNION ALL
        SELECT id, 'kk' as jenis, nama_kepalaKeluarga as nama, nama_kecamatan, nama_desa, {$kk_created_expr} as created_at, status FROM tb_kartukeluarga WHERE {$userScope} AND status = ?
        UNION ALL
        SELECT id, 'pindah' as jenis, nama_kepalakeluarga as nama, nama_kecamatan, nama_desa, {$pindah_created_expr} as created_at, status FROM tb_pindah_alamat WHERE {$userScope} AND status = ?
        UNION ALL
        SELECT id, 'pindah_online' as jenis, nama_kepalakeluarga as nama, nama_kecamatan, nama_desa, {$pindah_online_created_expr} as created_at, status FROM tb_pindah_online WHERE {$userScope} AND status = ?
    ) as combined";
    
    $params = [
        $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user, $filter_status,
        $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user, $filter_status,
        $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user, $filter_status,
        $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user, $filter_status,
        $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user, $filter_status
    ];
    $types = str_repeat('iiisss', 5);
}

if ($filter_jenis) {
    $countQuery .= " WHERE jenis = ?";
    $params[] = $filter_jenis;
    $types .= 's';
}
if ($search !== '') {
    $countQuery .= ($filter_jenis ? " AND" : " WHERE") . " (nama LIKE ? OR nama_kecamatan LIKE ? OR nama_desa LIKE ?)";
    $likeSearch = '%' . $search . '%';
    array_push($params, $likeSearch, $likeSearch, $likeSearch);
    $types .= 'sss';
}

$stmt = $koneksi->prepare($countQuery);
if (!$stmt) {
    die('Prepare failed: ' . $koneksi->error);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$countResult = $stmt->get_result();
$totalData = $countResult->fetch_assoc()['total'];
$stmt->close();

$totalPages = ceil($totalData / $limit);

// Get data dengan pagination dan filter (TANPA LAPORAN PERISTIWA)
$query = "
    SELECT * FROM (
        SELECT 
            id,
            'kelahiran' as jenis,
            nama_bayi as nama,
            nama_kecamatan,
            nama_desa,
            status,
            alasan,
            keterangan,
            DATE_FORMAT({$akta_created_expr}, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(updated_at, '%d-%m-%Y %H:%i') as tanggal_update,
            '' as nama_folder,
            {$akta_created_expr} as created_at,
            dokumen,
            id_user
        FROM tb_akta_kelahiran 
        WHERE {$userScope}
        
        UNION ALL
        
        SELECT 
            id,
            'kematian' as jenis,
            nama_meninggal as nama,
            nama_kecamatan,
            nama_desa,
            status,
            alasan,
            keterangan,
            DATE_FORMAT({$kematian_created_expr}, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(updated_at, '%d-%m-%Y %H:%i') as tanggal_update,
            nama_folder,
            {$kematian_created_expr} as created_at,
            dokumen,
            id_user
        FROM tb_akta_kematian 
        WHERE {$userScope}
        
        UNION ALL
        
        SELECT 
            id,
            'kk' as jenis,
            nama_kepalaKeluarga as nama,
            nama_kecamatan,
            nama_desa,
            status,
            alasan,
            keterangan,
            DATE_FORMAT({$kk_created_expr}, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(updated_at, '%d-%m-%Y %H:%i') as tanggal_update,
            nama_folder,
            {$kk_created_expr} as created_at,
            dokumen,
            id_user
        FROM tb_kartukeluarga 
        WHERE {$userScope}
        
        UNION ALL
        
        SELECT 
            id,
            'pindah' as jenis,
            nama_kepalakeluarga as nama,
            nama_kecamatan,
            nama_desa,
            status,
            alasan,
            keterangan,
            DATE_FORMAT({$pindah_created_expr}, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(updated_at, '%d-%m-%Y %H:%i') as tanggal_update,
            nama_folder,
            {$pindah_created_expr} as created_at,
            dokumen,
            id_user
        FROM tb_pindah_alamat 
        WHERE {$userScope}
        
        UNION ALL
        
        SELECT 
            id,
            'pindah_online' as jenis,
            nama_kepalakeluarga as nama,
            nama_kecamatan,
            nama_desa,
            status,
            alasan,
            keterangan,
            DATE_FORMAT({$pindah_online_created_expr}, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(updated_at, '%d-%m-%Y %H:%i') as tanggal_update,
            nama_folder,
            {$pindah_online_created_expr} as created_at,
            dokumen,
            id_user
        FROM tb_pindah_online 
        WHERE {$userScope}
    ) as combined
    WHERE 1=1
";

$queryParams = [
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user
];

$types = str_repeat('iiiss', 5);

if ($filter_status) {
    $query .= " AND status = ?";
    $queryParams[] = $filter_status;
    $types .= 's';
}

if ($filter_jenis) {
    $query .= " AND jenis = ?";
    $queryParams[] = $filter_jenis;
    $types .= 's';
}

if ($search !== '') {
    $query .= " AND (nama LIKE ? OR nama_kecamatan LIKE ? OR nama_desa LIKE ?)";
    $likeSearch = '%' . $search . '%';
    array_push($queryParams, $likeSearch, $likeSearch, $likeSearch);
    $types .= 'sss';
}

$query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$queryParams[] = $limit;
$queryParams[] = $offset;
$types .= 'ii';

$stmt = $koneksi->prepare($query);
if (!$stmt) {
    die('Prepare failed: ' . $koneksi->error);
}
$stmt->bind_param($types, ...$queryParams);
$stmt->execute();
$result = $stmt->get_result();

$permohonan = [];
while ($row = $result->fetch_assoc()) {
    $permohonan[] = $row;
}
$stmt->close();

// Muat status dan dokumen hasil satu kali untuk setiap card. Pemeriksaan akses
// tetap dilakukan oleh email_delivery_status() berdasarkan akun dan wilayah.
$delivery_table_map = [
    'kelahiran' => 'tb_akta_kelahiran',
    'kematian' => 'tb_akta_kematian',
    'kk' => 'tb_kartukeluarga',
    'pindah' => 'tb_pindah_alamat',
    'pindah_online' => 'tb_pindah_online',
];
foreach ($permohonan as &$permohonan_row) {
    $delivery_table = $delivery_table_map[$permohonan_row['jenis']] ?? '';
    $permohonan_row['_delivery_table'] = $delivery_table;
    $permohonan_row['_delivery_status'] = $delivery_table !== ''
        ? email_delivery_status($koneksi, $delivery_table, (int)$permohonan_row['id'], $user_id)
        : ['available' => false];
}
unset($permohonan_row);

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
    switch ($jenis) {
        case 'kelahiran':
            return '<span class="bg-cyan-100 text-cyan-800 text-xs font-medium px-2.5 py-0.5 rounded border border-cyan-300">Akta Kelahiran</span>';
        case 'kematian':
            return '<span class="bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-0.5 rounded border border-purple-300">Akta Kematian</span>';
        case 'kk':
            return '<span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded border border-green-300">Kartu Keluarga</span>';
        case 'pindah':
            return '<span class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-0.5 rounded border border-orange-300">Pindah Alamat</span>';
        case 'pindah_online':
            return '<span class="bg-teal-100 text-teal-800 text-xs font-medium px-2.5 py-0.5 rounded border border-teal-300">Pindah Masuk</span>';
        default:
            return '<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded border border-gray-300">' . $jenis . '</span>';
    }
}

function esc($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function formatPetugasDocumentFileSize(int $bytes): string {
    if ($bytes <= 0) return 'PDF';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1024, 1) . ' KB';
}

function renderPetugasOperatorDocumentList(array $deliveryStatus, string $deliveryTable, int $permohonanId): string {
    $status = (string)($deliveryStatus['status'] ?? '');
    $isPartial = $status === 'sebagian_diunggah_perlu_pengajuan_baru';
    $files = is_array($deliveryStatus['files'] ?? null) ? $deliveryStatus['files'] : [];
    $title = $isPartial ? 'Sebagian Dokumen Hasil Tersedia' : 'Dokumen Hasil';
    $modifier = $isPartial ? ' petugas-result-documents--partial' : '';

    $html = '<div class="petugas-result-documents' . $modifier . '">';
    $html .= '<div class="petugas-result-documents__title"><span aria-hidden="true">📄</span> ' . esc($title) . '</div>';

    if (!$files) {
        $html .= '<p class="petugas-result-documents__empty">Dokumen sedang disiapkan. Silakan muat ulang halaman beberapa saat lagi.</p>';
    } else {
        $html .= '<div class="petugas-result-documents__list">';
        foreach ($files as $file) {
            $downloadUrl = (string)($file['download_url'] ?? '');
            if ($downloadUrl === '') continue;
            $fileName = (string)($file['original_name'] ?? 'Dokumen hasil proses.pdf');
            $fileSize = formatPetugasDocumentFileSize((int)($file['file_size'] ?? 0));
            $uploadedAt = '';
            if (!empty($file['uploaded_at']) && strtotime((string)$file['uploaded_at']) !== false) {
                $uploadedAt = date('d-m-Y H:i', strtotime((string)$file['uploaded_at'])) . ' WITA';
            }
            $meta = $fileSize . ($uploadedAt !== '' ? ' • ' . $uploadedAt : '');

            $html .= '<div class="petugas-result-document">';
            $html .= '<div class="petugas-result-document__info"><strong>' . esc($fileName) . '</strong><span>' . esc($meta) . '</span></div>';
            $html .= '<a class="petugas-result-document__download" href="' . esc($downloadUrl) . '" data-feedback-table="' . esc($deliveryTable) . '" data-feedback-id="' . $permohonanId . '">';
            $html .= '<span aria-hidden="true">↓</span> Download</a></div>';
        }
        $html .= '</div>';
    }

    if ($isPartial) {
        $note = trim((string)($deliveryStatus['catatan_operator'] ?? ''));
        if ($note !== '') {
            $html .= '<p class="petugas-result-documents__note"><strong>Catatan operator:</strong> ' . nl2br(esc($note)) . '</p>';
        }
    }

    $html .= '</div>';
    return $html;
}

function renderPetugasDeliveryActions(array $deliveryStatus, string $deliveryTable, int $permohonanId, string $jenis): string {
    if (empty($deliveryStatus['available'])) {
        return '<p class="petugas-delivery-empty">Dokumen hasil belum tersedia.</p>';
    }

    if (empty($deliveryStatus['eligible'])) {
        $minutes = max(1, (int)ceil(((int)($deliveryStatus['seconds_remaining'] ?? 0)) / 60));
        $message = !empty($deliveryStatus['is_recheck']) ? 'Menunggu TTE' : 'Konfirmasi tersedia';
        return '<p class="petugas-delivery-message">' . esc($message) . ' dalam sekitar ' . $minutes . ' menit.</p>';
    }

    $status = (string)($deliveryStatus['status'] ?? 'menunggu');
    if ($status === 'menunggu') {
        $yesAction = esc('confirmEmailDelivery(' . $permohonanId . ', ' . json_encode($jenis) . ", 'sudah_masuk')");
        $noAction = esc('confirmEmailDelivery(' . $permohonanId . ', ' . json_encode($jenis) . ", 'belum_masuk')");
        return '<div class="petugas-delivery-confirmation"><p>Apakah dokumen sudah masuk ke email pemohon?</p><div>'
            . '<button type="button" class="petugas-delivery-button petugas-delivery-button--yes" onclick="' . $yesAction . '">Sudah Masuk</button>'
            . '<button type="button" class="petugas-delivery-button petugas-delivery-button--no" onclick="' . $noAction . '">Belum Masuk</button>'
            . '</div></div>';
    }

    if ($status === 'sudah_masuk') {
        return '<span class="petugas-delivery-state petugas-delivery-state--success">Dokumen sudah diterima</span>';
    }
    if ($status === 'belum_masuk') {
        return '<span class="petugas-delivery-state petugas-delivery-state--waiting">Menunggu tindak lanjut operator</span>';
    }
    if (in_array($status, ['diunggah_operator', 'sebagian_diunggah_perlu_pengajuan_baru'], true)) {
        $html = renderPetugasOperatorDocumentList($deliveryStatus, $deliveryTable, $permohonanId);
        if ($status === 'sebagian_diunggah_perlu_pengajuan_baru') {
            $newUrl = email_delivery_new_application_url((string)($deliveryStatus['source_table'] ?? ''), 'petugas_desa');
            $html .= '<a class="petugas-delivery-new-application" href="' . esc($newUrl) . '">Ajukan Dokumen yang Belum Tersedia</a>';
        }
        return $html;
    }
    if ($status === 'perlu_pengajuan_baru') {
        $newUrl = email_delivery_new_application_url((string)($deliveryStatus['source_table'] ?? ''), 'petugas_desa');
        $note = trim((string)($deliveryStatus['catatan_operator'] ?? ''));
        return '<div class="petugas-delivery-reapply"><strong>Perlu pengajuan baru</strong>'
            . '<p><strong>Catatan operator:</strong> ' . ($note !== '' ? nl2br(esc($note)) : '-') . '</p>'
            . '<a href="' . esc($newUrl) . '">Ajukan Permohonan Baru</a></div>';
    }

    return '<p class="petugas-delivery-empty">Dokumen hasil belum tersedia.</p>';
}

// Get stats for dashboard (TANPA LAPORAN PERISTIWA)
$statsQuery = $koneksi->prepare("
    SELECT 
        jenis,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) as diajukan,
        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) as diproses,
        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) as selesai,
        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) as ditolak
    FROM (
        SELECT 'kelahiran' as jenis, status FROM tb_akta_kelahiran WHERE {$userScope}
        UNION ALL
        SELECT 'kematian' as jenis, status FROM tb_akta_kematian WHERE {$userScope}
        UNION ALL
        SELECT 'kk' as jenis, status FROM tb_kartukeluarga WHERE {$userScope}
        UNION ALL
        SELECT 'pindah' as jenis, status FROM tb_pindah_alamat WHERE {$userScope}
        UNION ALL
        SELECT 'pindah_online' as jenis, status FROM tb_pindah_online WHERE {$userScope}
    ) as combined
    GROUP BY jenis
");

if (!$statsQuery) {
    die('Prepare failed: ' . $koneksi->error);
}

$statsParams = [
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user,
    $user_id, $user_kecamatan_id, $user_desa_id, $nama_kecamatan_user, $nama_desa_user
];
$statsTypes = str_repeat('iiiss', 5);
$statsQuery->bind_param($statsTypes, ...$statsParams);
$statsQuery->execute();
$statsResult = $statsQuery->get_result();

$stats = [
    'kelahiran' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'kematian' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'kk' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'pindah' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'pindah_online' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0]
];

while ($row = $statsResult->fetch_assoc()) {
    $stats[$row['jenis']] = $row;
}
$statsQuery->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc($csrf_token_value) ?>">
  <title>Status Permohonan - Petugas Desa</title>
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/petugas_desa_status_permohonan.css?v=20260825-1">
  <link rel="stylesheet" href="css/petugas_desa_shell.css?v=20260825-1">
    <link rel="stylesheet" href="../assets/css/service-timeline.css?v=20260908-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="petugas-desa-ui">

  <div class="layout">

    <?php include __DIR__ . '/petugas_desa_sidebar.php'; ?>

    <div class="menu-toggle">☰</div>

    <main class="main">
      <header class="header petugas-desa-header">
        <?php
        require_once __DIR__ . '/petugas_desa_header_brand.php';
        render_petugas_desa_header_brand(
          'Status Permohonan',
          'Petugas Desa • ' . $nama_kecamatan_user . ' / ' . $nama_desa_user
        );
        ?>
        <div class="user-info">
          <span>Hai, <?= esc($_SESSION['nama']) ?></span>
          <?php include __DIR__ . '/notification_widget.php'; ?>
          
          <button class="logout" id="logoutBtn2">Logout</button>
        </div>
      </header>

      <section class="content">
        <?php if ($email_delivery_pending_count > 0): ?>
          <div style="margin-bottom:20px;padding:16px 18px;border:1px solid #f59e0b;border-left:5px solid #f59e0b;border-radius:10px;background:#fffbeb;color:#78350f;display:flex;gap:12px;align-items:flex-start;">
            <div style="font-size:24px;line-height:1;">⚠️</div>
            <div>
              <strong style="display:block;margin-bottom:5px;">Konfirmasi penerimaan dokumen diperlukan</strong>
              <div style="line-height:1.5;">
                Terdapat <?= (int)$email_delivery_pending_count ?> permohonan yang sudah berstatus Selesai lebih dari 30 menit.
                Harap periksa email pemohon untuk dokumen dari <strong>SIAK Online DITJEN DUKCAPIL KEMENDAGRI</strong>, kemudian konfirmasikan apakah dokumen sudah diterima atau belum melalui kolom <strong>Konfirmasi Dokumen</strong>.
              </div>
            </div>
          </div>
        <?php endif; ?>
        <!-- Stats Cards -->
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-icon" style="background: #ebf8ff; color: #3498db;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                <line x1="9" y1="9" x2="9.01" y2="9"></line>
                <line x1="15" y1="9" x2="15.01" y2="9"></line>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Akta Kelahiran</h3>
              <div class="number"><?= $stats['kelahiran']['total'] ?></div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon" style="background: #fee2e2; color: #e74c3c;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Akta Kematian</h3>
              <div class="number"><?= $stats['kematian']['total'] ?></div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon" style="background: #dcfce7; color: #27ae60;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Kartu Keluarga</h3>
              <div class="number"><?= $stats['kk']['total'] ?></div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #f39c12;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Pindah Alamat</h3>
              <div class="number"><?= $stats['pindah']['total'] ?></div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon" style="background: #ccf7ed; color: #10b981;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 7V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2"></path>
                <polyline points="8 12 12 16 16 12"></polyline>
                <line x1="12" y1="16" x2="12" y2="4"></line>
                <rect x="3" y="7" width="18" height="14" rx="2" ry="2"></rect>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Pindah Masuk</h3>
              <div class="number"><?= $stats['pindah_online']['total'] ?></div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon" style="background: #f0f9ff; color: #3498db;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="1" x2="12" y2="23"></line>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Total Permohonan</h3>
              <div class="number"><?= $totalData ?></div>
            </div>
          </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
          <div class="filter-group">
            <div>
              <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Jenis Permohonan</label>
              <select id="filterJenis" class="select" onchange="applyFilters()">
                <option value="">Semua Jenis</option>
                <option value="kelahiran" <?= $filter_jenis == 'kelahiran' ? 'selected' : '' ?>>Akta Kelahiran</option>
                <option value="kematian" <?= $filter_jenis == 'kematian' ? 'selected' : '' ?>>Akta Kematian</option>
                <option value="kk" <?= $filter_jenis == 'kk' ? 'selected' : '' ?>>Kartu Keluarga</option>
                <option value="pindah" <?= $filter_jenis == 'pindah' ? 'selected' : '' ?>>Pindah Alamat</option>
                <option value="pindah_online" <?= $filter_jenis == 'pindah_online' ? 'selected' : '' ?>>Pindah Masuk</option>
              </select>
            </div>
            
            <div>
              <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Status</label>
              <select id="filterStatus" class="select" onchange="applyFilters()">
                <option value="">Semua Status</option>
                <option value="diajukan" <?= $filter_status == 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                <option value="diproses" <?= $filter_status == 'diproses' ? 'selected' : '' ?>>Diproses</option>
                <option value="selesai" <?= $filter_status == 'selesai' ? 'selected' : '' ?>>Selesai</option>
                <option value="ditolak" <?= $filter_status == 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
              </select>
            </div>

            <div>
              <label for="searchPermohonan" style="display:block;margin-bottom:8px;font-weight:600;color:#374151;">Pencarian</label>
              <div style="display:flex;gap:8px;">
                <input id="searchPermohonan" class="select" type="search" value="<?= esc($search) ?>" placeholder="Nama, subjek, kecamatan, desa" style="min-width:250px;" onkeydown="if(event.key==='Enter'){event.preventDefault();applyFilters();}">
                <button type="button" class="btn-view" onclick="applyFilters()">Cari</button>
              </div>
            </div>
          </div>
          
          <a href="petugas_desa_ajukan_permohonan.php" class="btn-view" style="padding: 10px 20px; font-size: 14px;">
            + Ajukan Permohonan Baru
          </a>
        </div>

        <!-- Card Riwayat Permohonan -->
        <section class="petugas-status-list-section" aria-labelledby="petugasStatusListTitle">
          <div class="petugas-status-list-section__header">
            <div>
              <h2 id="petugasStatusListTitle">Riwayat Permohonan</h2>
              <p>Wilayah <?= esc($nama_desa_user) ?>, <?= esc($nama_kecamatan_user) ?></p>
            </div>
            <span><?= (int)$totalData ?> permohonan</span>
          </div>

          <?php if (empty($permohonan)): ?>
            <div class="empty-state petugas-status-empty">
              <div class="icon" aria-hidden="true">📄</div>
              <h3>Belum ada permohonan</h3>
              <p>Tidak ada permohonan di desa <?= esc($nama_desa_user) ?> dengan filter yang dipilih.</p>
              <a href="petugas_desa_ajukan_permohonan.php" class="btn-view">Ajukan Permohonan Pertama</a>
            </div>
          <?php else: ?>
            <div class="petugas-permohonan-list" id="tabel-permohonan-konten">
              <?php
              $counter = ($page - 1) * $limit + 1;
              foreach ($permohonan as $row):
                $delivery_table = (string)($row['_delivery_table'] ?? '');
                $delivery_status = is_array($row['_delivery_status'] ?? null) ? $row['_delivery_status'] : ['available' => false];
                $delivery_html = renderPetugasDeliveryActions($delivery_status, $delivery_table, (int)$row['id'], (string)$row['jenis']);
                $location_parts = array_filter([(string)($row['nama_desa'] ?? ''), (string)($row['nama_kecamatan'] ?? '')]);
                $submitted_time = !empty($row['tanggal_diajukan']) && strtotime((string)$row['tanggal_diajukan']) !== false
                  ? date('d-m-Y H:i', strtotime((string)$row['tanggal_diajukan'])) . ' WITA'
                  : (string)($row['tanggal_diajukan'] ?? '-');
                $updated_time = !empty($row['tanggal_update']) && strtotime((string)$row['tanggal_update']) !== false
                  ? date('d-m-Y H:i', strtotime((string)$row['tanggal_update'])) . ' WITA'
                  : '';
              ?>
                <article class="petugas-permohonan-card" data-permohonan-status="<?= esc(strtolower((string)$row['status'])) ?>">
                  <header class="petugas-permohonan-card__header">
                    <div class="petugas-permohonan-card__identity">
                      <span class="petugas-permohonan-card__number">No. <?= $counter++ ?></span>
                      <?= getJenisBadge($row['jenis']) ?>
                    </div>
                    <?= getStatusBadge($row['status']) ?>
                  </header>

                  <div class="petugas-permohonan-card__body">
                    <div class="petugas-permohonan-card__summary">
                      <h3><?= esc($row['nama']) ?></h3>
                      <?php if (!empty($row['keterangan'])): ?>
                        <p class="petugas-permohonan-card__description"><?= esc($row['keterangan']) ?></p>
                      <?php endif; ?>

                      <div class="petugas-permohonan-card__meta">
                        <div><span>Lokasi</span><strong><?= esc($location_parts ? implode(', ', $location_parts) : '-') ?></strong></div>
                        <div><span>Diajukan</span><strong><?= esc($submitted_time) ?></strong></div>
<div><span>Status diperbarui</span><strong><?= esc(serviceTrackingUpdatedLabel($koneksi, serviceTrackingTableForKind((string)$row['jenis']), (int)$row['id'])) ?></strong></div>
                        <?php if ($updated_time !== '' && $row['tanggal_update'] != $row['tanggal_diajukan']): ?>
                          <div><span>Terakhir diperbarui</span><strong><?= esc($updated_time) ?></strong></div>
                        <?php endif; ?>
                      </div>

                      <?php if (!empty($row['alasan'])): ?>
                        <div class="petugas-permohonan-card__reason"><strong>Alasan / catatan:</strong> <?= nl2br(esc($row['alasan'])) ?></div>
                      <?php endif; ?>
                    </div>

                    <aside class="petugas-permohonan-card__side" aria-label="Dokumen hasil dan tindakan">
                      <section class="petugas-permohonan-card__delivery" aria-label="Dokumen hasil">
                        <?= $delivery_html ?>
                      </section>

                      <div class="petugas-permohonan-card__actions">
                        <button type="button" class="btn-view" onclick="showDetail(<?= (int)$row['id'] ?>, '<?= esc($row['jenis']) ?>')">Lihat Detail</button>
                        <?php if (in_array(strtolower((string)$row['status']), ['diajukan', 'ditolak'], true)): ?>
                          <button type="button" class="btn-edit" onclick="editPermohonan(<?= (int)$row['id'] ?>, '<?= esc($row['jenis']) ?>')">Edit &amp; Ajukan Kembali</button>
                        <?php endif; ?>
                      </div>
                    </aside>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
              <div class="pagination">
                <button class="page-btn" onclick="changePage(<?= $page - 1 ?>)" <?= $page <= 1 ? 'disabled' : '' ?>>&laquo; Sebelumnya</button>
                <div class="page-info">Halaman <?= $page ?> dari <?= $totalPages ?></div>
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                  <button class="page-btn <?= $i == $page ? 'active' : '' ?>" onclick="changePage(<?= $i ?>)"><?= $i ?></button>
                <?php endfor; ?>
                <button class="page-btn" onclick="changePage(<?= $page + 1 ?>)" <?= $page >= $totalPages ? 'disabled' : '' ?>>Selanjutnya &raquo;</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      </section>
    </main>
  </div>

  <!-- Detail Modal -->
  <div class="modal-overlay" id="detailModal">
    <div class="modal-box">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTitle">Detail Permohonan</h3>
        <button type="button" class="btn-close" onclick="hideDetailModal()" aria-label="Tutup detail permohonan">Tutup</button>
      </div>
      
      <div id="modalContent">
        <!-- Content will be loaded via AJAX -->
      </div>
    </div>
  </div>

  <!-- Modal Logout -->
  <div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
      <div class="modal-header">
        <h3 class="modal-title">Konfirmasi Logout</h3>
        <button class="btn-close" onclick="hideLogoutModal()">&times;</button>
      </div>
      <p style="margin: 20px 0;">Apakah Anda yakin ingin logout?</p>
      <div style="display: flex; gap: 10px; justify-content: flex-end;">
        <button id="confirmLogout" class="btn-view" style="background: #e74c3c;">Ya, Logout</button>
        <button id="cancelLogout" class="btn-view" style="background: #6b7280;">Batal</button>
      </div>
    </div>
  </div>

  <script>
  window.CSRF_TOKEN = '<?= esc($csrf_token_value) ?>';

  // JavaScript untuk halaman status permohonan petugas desa
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
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout.php');
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    
    if (logoutModal) {
      logoutModal.addEventListener('click', function(event) {
        if (event.target === logoutModal) hideLogoutModal();
      });
    }
    
    // Close modal with ESC
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        const detailModal = document.getElementById('detailModal');
        if (detailModal && detailModal.classList.contains('show')) {
          hideDetailModal();
        }
        if (logoutModal && logoutModal.classList.contains('show')) {
          hideLogoutModal();
        }
      }
    });
  });

  // Function to apply filters
  function applyFilters() {
    const jenis = document.getElementById('filterJenis').value;
    const status = document.getElementById('filterStatus').value;
    const search = document.getElementById('searchPermohonan')?.value.trim() || '';
    const params = new URLSearchParams();
    if (jenis) params.set('jenis', jenis);
    if (status) params.set('filter', status);
    if (search) params.set('search', search);
    window.location.href = 'petugas_desa_status_permohonan.php' + (params.toString() ? `?${params}` : '');
  }

  // Function to change page
  function changePage(page) {
    if (page < 1 || page > <?= $totalPages ?>) return;
    
    const jenis = document.getElementById('filterJenis')?.value || '<?= $filter_jenis ?>';
    const status = document.getElementById('filterStatus')?.value || '<?= $filter_status ?>';
    const search = document.getElementById('searchPermohonan')?.value.trim() || <?= json_encode($search) ?>;
    const params = new URLSearchParams({page: String(page)});
    if (jenis) params.set('jenis', jenis);
    if (status) params.set('filter', status);
    if (search) params.set('search', search);
    window.location.href = `?${params}`;
  }

  // Function to redirect to edit page
  function editPermohonan(id, jenis) {
    const normalizedJenis = String(jenis || '').trim().toLowerCase();
    let editUrl = '';
    switch(normalizedJenis) {
      case 'kelahiran':
      case 'akta_kelahiran':
      case 'akta kelahiran':
      case 'akta-kelahiran':
        editUrl = `petugas_desa_edit_akta_kelahiran.php?id=${id}`;
        break;
      case 'kematian':
      case 'akta_kematian':
      case 'akta kematian':
      case 'akta-kematian':
        editUrl = `petugas_desa_edit_akta_kematian.php?id=${id}`;
        break;
      case 'kk':
      case 'kartu_keluarga':
      case 'kartu keluarga':
        editUrl = `petugas_desa_edit_kk.php?id=${id}`;
        break;
      case 'pindah':
        editUrl = `petugas_desa_edit_pindah_alamat.php?id=${id}`;
        break;
      case 'pindah_online':
        editUrl = `petugas_desa_edit_pindah_online.php?id=${id}`;
        break;
      default:
        alert('Jenis permohonan tidak dikenali');
        return;
    }
    
    if (editUrl) {
      window.location.href = editUrl;
    }
  }

  // Detail Modal Functions
  const detailModal = document.getElementById('detailModal');
  let komentarPollingTimer = null;
  let komentarPollingContext = null;
  let komentarLastId = 0;
  const modalTitle = document.getElementById('modalTitle');
  const modalContent = document.getElementById('modalContent');

  function showDetail(id, jenis) {
    startKomentarPolling(id, jenis);
    // Show loading state
    modalContent.innerHTML = '<div class="text-center py-8">Memuat data...</div>';
    detailModal.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Fetch data via AJAX
    fetch(`get_detail_permohonan_petugas_desa.php?id=${id}&jenis=${jenis}`)
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
        
        // Set modal title
        const jenisText = getJenisText(jenis);
        modalTitle.textContent = `Detail Permohonan ${jenisText}`;
        
        // Get status class
        let statusClass = '';
        let statusText = data.status || 'diajukan';
        switch(statusText) {
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
        const komentarPermohonan = Array.isArray(data.komentar_permohonan) ? data.komentar_permohonan : [];
        komentarLastId = getLastKomentarId(komentarPermohonan);
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
                ${getJenisBadgeHTML(jenis)}
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
              <div class="detail-value">${escapeHtml(data.nama_pemohon_full || data.nama_pelapor || '-')}</div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">NIK Pemohon</label>
              <div class="detail-value">${escapeHtml(data.nik_pemohon_full || data.nik_pelapor || '-')}</div>
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
          // Kategori permohonan menentukan blok data mana yang relevan ditampilkan.
          const kategoriLabel = {
            baru_belum_nik: 'Bayi Baru Lahir (Belum Memiliki NIK)',
            baru_sudah_nik: 'Sudah Memiliki NIK (Belum Memiliki Akta)',
            hilang: 'Akta Kelahiran Hilang',
            pembaruan: 'Pembaruan Akta Kelahiran (TTE Barcode)'
          };
          const kategori = kategoriLabel[data.jenis_permohonan] ? data.jenis_permohonan : 'baru_belum_nik';
          const isHilang = kategori === 'hilang';
          const isBayiBaru = kategori === 'baru_belum_nik';
          const labelNama = isBayiBaru ? 'Nama Bayi' : 'Nama yang Bersangkutan';
          const judulBlok = isHilang ? 'Data Pemilik Akta' : (isBayiBaru ? 'Data Bayi' : 'Data yang Bersangkutan');

          content += `
            <div class="section-header">${judulBlok}</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Jenis Permohonan</label>
                <div class="detail-value">${escapeHtml(kategoriLabel[kategori])}</div>
              </div>

              <div class="detail-group">
                <label class="detail-label">${labelNama}</label>
                <div class="detail-value">${escapeHtml(data.nama_bayi || '-')}</div>
              </div>

              ${!isBayiBaru ? `
              <div class="detail-group">
                <label class="detail-label">NIK yang Bersangkutan</label>
                <div class="detail-value">${escapeHtml(data.nik_subjek || '-')}</div>
              </div>
              ` : ''}

              ${isHilang ? `
              <div class="detail-group">
                <label class="detail-label">Nomor Surat Keterangan Kehilangan</label>
                <div class="detail-value">${escapeHtml(data.no_suket_kehilangan || '-')}</div>
              </div>
              ` : `
              <div class="detail-group">
                <label class="detail-label">Jenis Kelamin</label>
                <div class="detail-value">
                  ${data.jenis_kelamin_display ||
                    (data.jenis_kelamin === 'L' ? 'Laki-laki' :
                     data.jenis_kelamin === 'P' ? 'Perempuan' :
                     escapeHtml(data.jenis_kelamin || '-'))}
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
                <div class="detail-value">${escapeHtml(data.waktu_lahir_formatted || data.Waktu || '-')}</div>
              </div>

              <div class="detail-group">
                <label class="detail-label">Anak ke-</label>
                <div class="detail-value">${escapeHtml(data.urutan_kelahiran || '-')}</div>
              </div>
              `}

              ${(kategori === 'pembaruan' || isHilang) ? `
              <div class="detail-group">
                <label class="detail-label">Nomor Akta Lahir Lama</label>
                <div class="detail-value">${escapeHtml(data.nomor_akta_lama || '-')}</div>
              </div>
              ` : ''}
            </div>

            ${!isHilang ? `
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
            ` : ''}
          `;
        } else if (jenis === 'kematian') {
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
        } else if (jenis === 'kk') {
          // Kategori layanan KK (label dari kk_kategori.php) menentukan blok data yang relevan.
          const kategoriKk = <?= json_encode(siprima_kk_kategori_untuk_js(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
          const infoKk = kategoriKk[data.kategori_layanan] || kategoriKk.kk_baru;
          const kkBaru = infoKk === kategoriKk.kk_baru;
          content += `
            <div class="section-header">Data Kartu Keluarga</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Kategori Layanan</label>
                <div class="detail-value">${escapeHtml(infoKk.label)}</div>
              </div>

              <div class="detail-group">
                <label class="detail-label">${escapeHtml(infoKk.label_nama)}</label>
                <div class="detail-value">${escapeHtml(data.nama_kepalaKeluarga || '-')}</div>
              </div>
              
              ${kkBaru ? `<div class="detail-group">
                <label class="detail-label">Dusun</label>
                <div class="detail-value">${escapeHtml(data.dusun || '-')}</div>
              </div>` : `<div class="detail-group">
                <label class="detail-label">${escapeHtml(infoKk.label_nik)}</label>
                <div class="detail-value">${escapeHtml(data.nik_subjek || '-')}</div>
              </div>`}
            </div>
            
            ${kkBaru ? `<div class="detail-group">
              <label class="detail-label">Anggota Keluarga</label>
              <div class="detail-value" style="white-space: pre-wrap;">${escapeHtml(data.anggotaKeluarga || '-')}</div>
            </div>` : ''}
          `;
        } else if (jenis === 'pindah') {
          content += `
            <div class="section-header">Data Pindah Alamat</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Nama Kepala Keluarga</label>
                <div class="detail-value">${escapeHtml(data.nama_kepalaKeluarga || data.nama_kepalakeluarga || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">No KK</label>
                <div class="detail-value">${escapeHtml(data.no_kk || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Alamat Asal</label>
                <div class="detail-value">${escapeHtml(data.alamat_asal || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Alamat Tujuan</label>
                <div class="detail-value">${escapeHtml(data.alamat_tujuan || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Alasan Pindah</label>
                <div class="detail-value">${escapeHtml(data.alasan_pindah || '-')}</div>
              </div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">Anggota Keluarga yang Pindah</label>
              <div class="detail-value" style="white-space: pre-wrap;">${escapeHtml(data.anggotaKeluarga || '-')}</div>
            </div>
          `;
        } else if (jenis === 'pindah_online') {
          content += `
            <div class="section-header">Data Pindah Masuk</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Nama Kepala Keluarga</label>
                <div class="detail-value">${escapeHtml(data.nama_kepalaKeluarga || data.nama_kepalakeluarga || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">No KK</label>
                <div class="detail-value">${escapeHtml(data.no_kk || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Alamat Asal</label>
                <div class="detail-value">${escapeHtml(data.alamat_asal || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Alamat Tujuan</label>
                <div class="detail-value">${escapeHtml(data.alamat_tujuan || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Jenis Pindah</label>
                <div class="detail-value">${escapeHtml(data.jenis_pindah || 'Pindah Masuk')}</div>
              </div>
            </div>
            
            <div class="detail-group">
              <label class="detail-label">Anggota Keluarga yang Pindah</label>
              <div class="detail-value" style="white-space: pre-wrap;">${escapeHtml(data.anggotaKeluarga || '-')}</div>
            </div>
          `;
        }
                
        // Dokumen section
        if (dokumen.length > 0) {
          content += `
            <div class="section-header">Dokumen Terupload</div>
            <div class="dokumen-grid">
              ${dokumen.map((doc, index) => {
                let docUrl = '';
                let fileName = doc.original_name || 'File ' + (index + 1);
                
                // Dapatkan URL dokumen yang benar
                if (doc.full_url) {
                  docUrl = doc.full_url;
                } else if (doc.path) {
                  const protocol = window.location.protocol;
                  const host = window.location.host;
                  const basePath = window.location.pathname.split('/').slice(0, -1).join('/');
                  docUrl = protocol + '//' + host + '/siprima/' + doc.path;
                }
                
                // Buat link hanya jika URL valid
                if (docUrl) {
                  return `
                    <div class="dokumen-item">
                      <div class="dokumen-type">${getDocTypeText(doc.type)}</div>
                      <div class="dokumen-name">${escapeHtml(fileName)}</div>
                      <a href="${escapeHtml(docUrl)}" target="_blank" class="btn-view mt-2" style="display: inline-block; padding: 5px 10px;">
                        Lihat Dokumen
                      </a>
                    </div>
                  `;
                } else {
                  return `
                    <div class="dokumen-item">
                      <div class="dokumen-type">${getDocTypeText(doc.type)}</div>
                      <div class="dokumen-name">${escapeHtml(fileName)}</div>
                      <span class="text-yellow-600 text-xs">URL tidak tersedia</span>
                    </div>
                  `;
                }
              }).join('')}
            </div>
          `;
        } else {
          content += `
            <div class="section-header">Dokumen</div>
            <div class="detail-value">Tidak ada dokumen</div>
          `;
        }

        // Dokumen hasil dan konfirmasi ditampilkan langsung pada card daftar.

        content += `
          <div class="section-header">Komentar / Klarifikasi</div>
          <div id="komentarList">
            ${renderKomentarPermohonan(komentarPermohonan)}
          </div>
          <form id="formKomentarPermohonan" class="mt-4" onsubmit="submitKomentarPermohonan(event, ${id}, '${jenis}')">
            <label class="detail-label" for="komentar_permohonan">Komentar</label>
            <textarea id="komentar_permohonan" name="komentar" rows="3" maxlength="1000" required
              class="w-full border border-gray-300 rounded p-2 text-sm"
              style="resize: vertical;"
              placeholder="Tulis komentar atau klarifikasi..."></textarea>
            <div class="flex justify-between items-center mt-2">
              <span class="text-xs text-gray-500">Maksimal 1000 karakter.</span>
              <button type="submit" class="btn-view" style="padding: 8px 14px;">Kirim Komentar</button>
            </div>
            <div id="komentarMessage" class="text-sm mt-2"></div>
          </form>
        `;
                
        // Add edit button when status is still submittable
        const statusLower = (data.status || '').toLowerCase();
        if (statusLower === 'diajukan' || statusLower === 'ditolak') {
          content += `
            <div class="mt-6 pt-4 border-t">
              <div class="flex justify-between items-center">
                <button class="btn-edit" onclick="editPermohonan(${id}, '${jenis}')" style="padding: 10px 20px;">
                  Edit & Ajukan Kembali
                </button>
              </div>
            </div>
          `;
        }
        
        modalContent.innerHTML = content;
        if (window.asprimaApplyDraft) window.asprimaApplyDraft(id, jenis);
      })
      .catch(error => {
        console.error('Error fetching detail:', error);
        modalContent.innerHTML = '<div class="text-red-600 text-center py-8">Terjadi kesalahan saat memuat data</div>';
      });
  }

  function renderKomentarPermohonan(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return '<div class="detail-value">Belum ada komentar.</div>';
    }

    return items.map(item => {
      const nama = item.nama_user || '-';
      const role = String(item.user_role || '').replace(/_/g, ' ');
      const tanggal = item.created_at_formatted || item.created_at || '-';
      const mineClass = item.is_mine ? 'border-blue-200 bg-blue-50' : 'border-gray-200 bg-white';

      return `
        <div class="border ${mineClass} rounded p-3 mb-2">
          <div class="flex justify-between gap-3 text-xs text-gray-500 mb-1">
            <span><strong class="text-gray-700">${escapeHtml(nama)}</strong> (${escapeHtml(role)})</span>
            <span>${escapeHtml(tanggal)}</span>
          </div>
          <div class="text-sm text-gray-800" style="white-space: pre-wrap;">${escapeHtml(item.komentar || '')}</div>
        </div>
      `;
    }).join('');
  }

  function submitKomentarPermohonan(event, id, jenis) {
    event.preventDefault();

    const form = event.currentTarget;
    const textarea = form.querySelector('textarea[name="komentar"]');
    const message = document.getElementById('komentarMessage');
    const submitButton = form.querySelector('button[type="submit"]');
    const komentar = textarea ? textarea.value.trim() : '';
    const csrfToken = (window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '').trim();

    if (!komentar) {
      if (message) {
        message.className = 'text-sm mt-2 text-red-600';
        message.textContent = 'Komentar wajib diisi.';
      }
      return;
    }

    if (komentar.length > 1000) {
      if (message) {
        message.className = 'text-sm mt-2 text-red-600';
        message.textContent = 'Komentar maksimal 1000 karakter.';
      }
      return;
    }

    const body = new URLSearchParams();
    body.set('permohonan_id', String(id));
    body.set('jenis', String(jenis));
    body.set('komentar', komentar);
    body.set('csrf_token', csrfToken);

    if (submitButton) submitButton.disabled = true;
    if (message) {
      message.className = 'text-sm mt-2 text-gray-500';
      message.textContent = 'Mengirim komentar...';
    }

    fetch('submit_komentar_permohonan.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-Token': csrfToken
      },
      body: body.toString()
    })
      .then(response => response.json().then(data => ({ ok: response.ok, data })))
      .then(({ ok, data }) => {
        if (!ok || !data.success) {
          throw new Error(data.error || 'Komentar gagal dikirim.');
        }

        if (Array.isArray(data.komentar)) {
          const list = document.getElementById('komentarList');
          if (list) list.innerHTML = renderKomentarPermohonan(data.komentar);
          komentarLastId = getLastKomentarId(data.komentar);
        }
        if (textarea) textarea.value = '';
        if (data.csrf_token) window.CSRF_TOKEN = data.csrf_token;
        if (message) {
          message.className = 'text-sm mt-2 text-green-600';
          message.textContent = data.message || 'Komentar berhasil dikirim.';
        }
      })
      .catch(error => {
        if (message) {
          message.className = 'text-sm mt-2 text-red-600';
          message.textContent = error.message || 'Komentar gagal dikirim.';
        }
      })
      .finally(() => {
        if (submitButton) submitButton.disabled = false;
      });
  }

  function hideDetailModal() {
    if (!detailModal) return;
    stopKomentarPolling();
    detailModal.classList.remove('show');
    document.body.style.overflow = '';
  }

  function getLastKomentarId(items) {
    if (!Array.isArray(items) || items.length === 0) return 0;
    return items.reduce((lastId, item) => Math.max(lastId, Number(item.id || 0)), 0);
  }

  function startKomentarPolling(id, jenis) {
    stopKomentarPolling();
    komentarPollingContext = { id: Number(id), jenis: String(jenis) };
    komentarLastId = 0;
    komentarPollingTimer = window.setInterval(pollKomentarPermohonan, 25000);
  }

  function stopKomentarPolling() {
    if (komentarPollingTimer) window.clearInterval(komentarPollingTimer);
    komentarPollingTimer = null;
    komentarPollingContext = null;
  }

  function pollKomentarPermohonan() {
    if (!komentarPollingContext || document.hidden || !detailModal?.classList.contains('show')) return;
    const query = new URLSearchParams({
      permohonan_id: String(komentarPollingContext.id),
      jenis: komentarPollingContext.jenis
    });
    fetch(`get_komentar_permohonan.php?${query}`, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(response => response.ok ? response.json() : Promise.reject(new Error('Polling gagal')))
      .then(data => {
        if (!data.success || !Array.isArray(data.komentar)) return;
        const newLastId = Number(data.last_comment_id || getLastKomentarId(data.komentar));
        if (newLastId !== komentarLastId) {
          const list = document.getElementById('komentarList');
          if (list) list.innerHTML = renderKomentarPermohonan(data.komentar);
          komentarLastId = newLastId;
        }
      })
      .catch(() => {});
  }

  // Helper function to escape HTML
  function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Helper function to get jenis text
  function getJenisText(jenis) {
    const jenisMap = {
      'kelahiran': 'Akta Kelahiran',
      'kematian': 'Akta Kematian',
      'kk': 'Kartu Keluarga',
      'pindah': 'Pindah Alamat',
      'pindah_online': 'Pindah Masuk'
    };
    return jenisMap[jenis] || jenis;
  }

  // Helper function to get jenis badge HTML
  function getJenisBadgeHTML(jenis) {
    const badgeMap = {
      'kelahiran': '<span class="bg-cyan-100 text-cyan-800 text-xs font-medium px-2.5 py-0.5 rounded border border-cyan-300">Akta Kelahiran</span>',
      'kematian': '<span class="bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-0.5 rounded border border-purple-300">Akta Kematian</span>',
      'kk': '<span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded border border-green-300">Kartu Keluarga</span>',
      'pindah': '<span class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-0.5 rounded border border-orange-300">Pindah Alamat</span>',
      'pindah_online': '<span class="bg-teal-100 text-teal-800 text-xs font-medium px-2.5 py-0.5 rounded border border-teal-300">Pindah Masuk</span>'
    };
    return badgeMap[jenis] || '<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded border border-gray-300">' + jenis + '</span>';
  }

  // Helper function to get document type text
  function getDocTypeText(type) {
    if (!type) return 'Dokumen';
    
    const types = {
      'surat_keterangan_rs': 'Surat Keterangan RS',
      'surat_keterangan_kematian_rs': 'Surat Keterangan Kematian RS',
      'formulir': 'Formulir',
      'formulir_f201_kematian': 'Formulir F-2.01 Kematian',
      'buku_nikah': 'Buku Nikah/SPTJM',
      'identitas_meninggal': 'Identitas Meninggal',
      'kk': 'Kartu Keluarga',
      'ktp_ortu': 'KTP Orang Tua',
      'ktp_ayah': 'KTP Ayah',
      'ktp_ibu': 'KTP Ibu',
      'akta_kelahiran': 'Akta Kelahiran',
      'kk_lama': 'KK Lama',
      'kk_lama_atau_surat_kehilangan': 'KK / Surat Keterangan Hilang KK',
      'suket_kehilangan_kk': 'Surat Keterangan Hilang KK',
      'kk_ktp_hilang': 'KK / KTP / Surat Keterangan Hilang KTP',
      'formulir_f106': 'Formulir F-1.06 SPTJM Perubahan Data',
      'surat_nikah': 'Surat Nikah',
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
  function confirmEmailDelivery(id, jenis, confirmation) {
    const tableMap = {kelahiran:'tb_akta_kelahiran',kematian:'tb_akta_kematian',kk:'tb_kartukeluarga',pindah:'tb_pindah_alamat',pindah_online:'tb_pindah_online'};
    const sourceTable = tableMap[jenis];
    if (!sourceTable) return;
    let catatan = '';
    if (confirmation === 'belum_masuk') {
      if (typeof window.openEmailDeliveryNoteModal === 'function') window.openEmailDeliveryNoteModal(id, jenis);
      return;
    } else if (!window.confirm('Konfirmasi dokumen sudah masuk ke email?')) return;
    const body = new URLSearchParams({csrf_token:window.CSRF_TOKEN || '',source_table:sourceTable,permohonan_id:String(id),confirmation,catatan});
    fetch('confirm_email_delivery.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},credentials:'same-origin',body})
      .then(async response => {const raw=await response.text();let data;try{data=JSON.parse(raw);}catch(error){throw new Error('Respons server tidak valid (HTTP '+response.status+').');}return{ok:response.ok,data};})
      .then(({ok,data}) => {
        if(!ok || !data.success) throw new Error(data.message || 'Konfirmasi gagal.');
        alert(data.message);
        const reload = () => window.location.reload();
        if (confirmation === 'sudah_masuk' && window.requestPermohonanFeedback) {
          window.requestPermohonanFeedback(sourceTable, id, 'konfirmasi', reload);
        } else reload();
      })
      .catch(error => alert(error.message || 'Konfirmasi gagal.'));
  }
  // Buka detail secara otomatis ketika halaman berasal dari tautan notifikasi.
  document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const detailId = Number(params.get('detail_id') || 0);
    const jenis = params.get('jenis') || '';
    if (detailId > 0 && jenis && typeof showDetail === 'function') {
      showDetail(detailId, jenis);
    }
  });
  </script>
<?php include __DIR__ . '/email_delivery_note_modal.php'; ?>
<?php include __DIR__ . '/permohonan_feedback_modal.php'; ?>
<?php include __DIR__ . '/petugas_desa_mobile_bottom_nav.php'; ?>
<script src="js/asprima_handoff.js?v=20260908" data-account-id="<?= (int)$_SESSION['id_user'] ?>"></script>
</body>
</html>
