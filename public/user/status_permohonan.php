<?php
require_once __DIR__ . '/service_tracking.php';
require_once '../error_handler.php';
// Gunakan middleware khusus user biasa
include '../../app/middleware/user_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/email_delivery.php';
require_once __DIR__ . '/../functions/document_token.php';
require_once __DIR__ . '/../functions/kk_kategori.php';
require_once __DIR__ . '/../../app/Models/KiaModel.php';

$has_resubmitted_kelahiran = false;
$has_resubmitted_kematian = false;
$has_resubmitted_kk = false;
$has_resubmitted_pindah = false;
$has_resubmitted_pindah_online = false;
$has_resubmitted_laporan = false;

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
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_laporan_peristiwa LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) $has_resubmitted_laporan = true;

$akta_created_expr = $has_resubmitted_kelahiran ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$kematian_created_expr = $has_resubmitted_kematian ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$kk_created_expr = $has_resubmitted_kk ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$pindah_created_expr = $has_resubmitted_pindah ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$pindah_online_created_expr = $has_resubmitted_pindah_online ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$laporan_created_expr = $has_resubmitted_laporan ? 'COALESCE(resubmitted_at, created_at)' : 'created_at';
$has_resubmitted_kia = false;
$resubmitted_col_check = $koneksi->query("SHOW COLUMNS FROM tb_pengajuan_kia LIKE 'resubmitted_at'");
if ($resubmitted_col_check && $resubmitted_col_check->num_rows > 0) $has_resubmitted_kia = true;
$kia_created_expr = $has_resubmitted_kia ? 'COALESCE(k.resubmitted_at, k.created_at)' : 'k.created_at';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Location: ../login.php?error=session_expired');
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$csrf_token_value = function_exists('csrf_token') ? csrf_token() : '';
$email_delivery_pending_count = email_delivery_pending_confirmation_count($koneksi, $user_id);

// Pagination settings
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Filter status dari URL
$filter_status = isset($_GET['filter']) ? $_GET['filter'] : '';
$filter_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : '';
$search = trim((string)($_GET['search'] ?? ''));

// Get total count of all permohonan untuk user ini
$countQuery = "SELECT COUNT(*) as total FROM (
    SELECT id, 'kelahiran' as jenis, nama_bayi as nama, nama_kecamatan, nama_desa, status, {$akta_created_expr} as created_at FROM tb_akta_kelahiran WHERE id_user = ?
    UNION ALL
    SELECT id, 'kematian' as jenis, nama_meninggal as nama, nama_kecamatan, nama_desa, status, {$kematian_created_expr} as created_at FROM tb_akta_kematian WHERE id_user = ?
    UNION ALL
    SELECT id, 'kk' as jenis, nama_kepalaKeluarga as nama, nama_kecamatan, nama_desa, status, {$kk_created_expr} as created_at FROM tb_kartukeluarga WHERE id_user = ?
    UNION ALL
    SELECT id, 'pindah' as jenis, nama_kepalakeluarga as nama, nama_kecamatan, nama_desa, status, {$pindah_created_expr} as created_at FROM tb_pindah_alamat WHERE id_user = ?
    UNION ALL
    SELECT id, 'pindah_online' as jenis, nama_kepalakeluarga as nama, nama_kecamatan, nama_desa, status, {$pindah_online_created_expr} as created_at FROM tb_pindah_online WHERE id_user = ?
    UNION ALL
    SELECT id_lapor as id, 'laporan' as jenis, nama_subjek as nama, nama_kecamatan, nama_desa, status, {$laporan_created_expr} as created_at FROM tb_laporan_peristiwa WHERE id_user = ?
    UNION ALL
    SELECT k.id, 'kia' as jenis, COALESCE((SELECT GROUP_CONCAT(a.nama_anak SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = k.id), u.nama, k.no_kk) as nama, kc.nama_kecamatan, ds.nama_desa, k.status, {$kia_created_expr} as created_at FROM tb_pengajuan_kia k LEFT JOIN tb_kecamatan kc ON kc.id = k.id_kecamatan LEFT JOIN tb_desa ds ON ds.id = k.id_desa LEFT JOIN tb_users u ON u.id = k.id_user WHERE k.id_user = ?
) as combined";

$params = [$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id];

// Tambahkan filter jika ada
if ($filter_status) {
    $countQuery = "SELECT COUNT(*) as total FROM (
        SELECT id, 'kelahiran' as jenis, nama_bayi as nama, nama_kecamatan, nama_desa, {$akta_created_expr} as created_at, status FROM tb_akta_kelahiran WHERE id_user = ? AND status = ?
        UNION ALL
        SELECT id, 'kematian' as jenis, nama_meninggal as nama, nama_kecamatan, nama_desa, {$kematian_created_expr} as created_at, status FROM tb_akta_kematian WHERE id_user = ? AND status = ?
        UNION ALL
        SELECT id, 'kk' as jenis, nama_kepalaKeluarga as nama, nama_kecamatan, nama_desa, {$kk_created_expr} as created_at, status FROM tb_kartukeluarga WHERE id_user = ? AND status = ?
        UNION ALL
        SELECT id, 'pindah' as jenis, nama_kepalakeluarga as nama, nama_kecamatan, nama_desa, {$pindah_created_expr} as created_at, status FROM tb_pindah_alamat WHERE id_user = ? AND status = ?
        UNION ALL
        SELECT id, 'pindah_online' as jenis, nama_kepalakeluarga as nama, nama_kecamatan, nama_desa, {$pindah_online_created_expr} as created_at, status FROM tb_pindah_online WHERE id_user = ? AND status = ?
        UNION ALL
        SELECT id_lapor as id, 'laporan' as jenis, nama_subjek as nama, nama_kecamatan, nama_desa, {$laporan_created_expr} as created_at, status FROM tb_laporan_peristiwa WHERE id_user = ? AND status = ?
        UNION ALL
        SELECT k.id, 'kia' as jenis, COALESCE((SELECT GROUP_CONCAT(a.nama_anak SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = k.id), u.nama, k.no_kk) as nama, kc.nama_kecamatan, ds.nama_desa, {$kia_created_expr} as created_at, k.status FROM tb_pengajuan_kia k LEFT JOIN tb_kecamatan kc ON kc.id = k.id_kecamatan LEFT JOIN tb_desa ds ON ds.id = k.id_desa LEFT JOIN tb_users u ON u.id = k.id_user WHERE k.id_user = ? AND k.status = ?
    ) as combined";
    
    $params = [
        $user_id, $filter_status,
        $user_id, $filter_status,
        $user_id, $filter_status,
        $user_id, $filter_status,
        $user_id, $filter_status,
        $user_id, $filter_status,
        $user_id, $filter_status
    ];
}

if ($filter_status) {
    $types = str_repeat('is', count($params) / 2);
} else {
    $types = str_repeat('i', count($params));
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

// Get data dengan pagination dan filter
$query = "
    SELECT * FROM (
        SELECT 
            k.id,
            'kia' as jenis,
            COALESCE(u.nama, (SELECT GROUP_CONCAT(a.nama_anak SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = k.id), k.no_kk) as nama,
            kc.nama_kecamatan,
            ds.nama_desa,
            k.status,
            k.alasan_penolakan as alasan,
            COALESCE(NULLIF(k.keterangan_permohonan, ''), (SELECT CONCAT('Permohonan KIA (', GROUP_CONCAT(a.nama_anak SEPARATOR ', '), ')') FROM tb_anak_kia a WHERE a.id_pengajuan = k.id)) as keterangan,
            DATE_FORMAT({$kia_created_expr}, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(k.updated_at, '%d-%m-%Y %H:%i') as tanggal_update,
            COALESCE(k.dokumen_kia_pdf, '') as nama_folder,
            {$kia_created_expr} as created_at,
            NULL as dokumen,
            k.id_user
        FROM tb_pengajuan_kia k
        LEFT JOIN tb_kecamatan kc ON kc.id = k.id_kecamatan
        LEFT JOIN tb_desa ds ON ds.id = k.id_desa
        LEFT JOIN tb_users u ON u.id = k.id_user
        WHERE k.id_user = ?
        
        UNION ALL
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
        WHERE id_user = ?
        
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
        WHERE id_user = ?
        
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
        WHERE id_user = ?
        
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
        WHERE id_user = ?
        
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
        WHERE id_user = ?
        
        UNION ALL
        
        SELECT 
            id_lapor as id,
            'laporan' as jenis,
            nama_subjek as nama,
            nama_kecamatan,
            nama_desa,
            status,
            alasan,
            keterangan,
            DATE_FORMAT({$laporan_created_expr}, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(updated_at, '%d-%m-%Y %H:%i') as tanggal_update,
            nama_folder,
            {$laporan_created_expr} as created_at,
            dokumen,
            id_user
        FROM tb_laporan_peristiwa 
        WHERE id_user = ?
    ) as combined
    WHERE 1=1
";

$queryParams = [$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id];
$types = 'iiiiiii';

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
    // Proses dokumen untuk mendapatkan path yang benar
    if (isset($row['dokumen']) && $row['dokumen'] && $row['dokumen'] !== 'null' && $row['dokumen'] !== '[]') {
        $dokumen = json_decode($row['dokumen'], true);
        if (is_array($dokumen) && !empty($dokumen)) {
            foreach ($dokumen as &$doc) {
                if (isset($doc['path']) && !empty($doc['path'])) {
                    $url = siprima_document_token_url(__DIR__, (string)$doc['path']);
                    $doc['path'] = $url ?? '';
                    $doc['clean_path'] = $url ?? '';
                    $doc['file_exists'] = $url !== null;
                    $doc['full_url'] = $url ?? '';
                    $doc['download_url'] = $url === null ? '' : $url . '&download=1';
                }
            }
            $row['dokumen'] = json_encode($dokumen);
        } else {
            $row['dokumen'] = siprima_mask_document_json(__DIR__, (string)$row['dokumen']);
        }
    }
    $permohonan[] = $row;
}
$stmt->close();

// Siapkan status pengiriman dokumen satu kali agar tabel desktop dan card mobile
// memakai sumber data yang sama tanpa mengulang query saat rendering.
$delivery_table_map = [
    'kelahiran' => 'tb_akta_kelahiran',
    'kematian' => 'tb_akta_kematian',
    'kk' => 'tb_kartukeluarga',
    'pindah' => 'tb_pindah_alamat',
    'pindah_online' => 'tb_pindah_online',
];

$kiaModelStatus = new \Siprima\Models\KiaModel($koneksi);
$pengaturanCetakKiaGlobal = $kiaModelStatus->getPengaturanCetakKia();
$pendingMandatorySurveiKia = $kiaModelStatus->getPendingMandatorySurvei($user_id);

foreach ($permohonan as &$permohonan_row) {
    $delivery_table = $delivery_table_map[$permohonan_row['jenis']] ?? '';
    $permohonan_row['_delivery_table'] = $delivery_table;
    $permohonan_row['_delivery_status'] = $delivery_table !== ''
        ? email_delivery_status($koneksi, $delivery_table, (int)$permohonan_row['id'], $user_id)
        : ['available' => false];
    if ($permohonan_row['jenis'] === 'kia') {
        $permohonan_row['_kia_cetak'] = $kiaModelStatus->getCetakKiaByPengajuan((int)$permohonan_row['id']);
        $permohonan_row['_kia_survei'] = $kiaModelStatus->getSurveiProgress((int)$permohonan_row['id'], $user_id);
        $permohonan_row['_kia_pengaturan_cetak'] = $pengaturanCetakKiaGlobal;
        $permohonan_row['_kia_pdf_status'] = $kiaModelStatus->getHasilPdfStatusByPengajuan((int)$permohonan_row['id']);
        $permohonan_row['_kia_laporan_list'] = (strtolower((string)$permohonan_row['status']) === 'selesai')
            ? $kiaModelStatus->getLaporanKesalahan((int)$permohonan_row['id'], $user_id)
            : [];
    }
}
unset($permohonan_row);

function renderKiaPrintStatusBadge(?array $cetakKia): string {
    if (!$cetakKia) return '';
    $st = strtolower((string)($cetakKia['status_cetak'] ?? 'menunggu_cetak'));
    if ($st === 'selesai_dicetak') {
        return '<span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded border border-green-300">🖨️ Selesai Dicetak (Siap Diambil)</span>';
    }
    if ($st === 'sedang_dicetak') {
        return '<span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded border border-blue-300">🖨️ Sedang Dicetak</span>';
    }
    return '<span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-0.5 rounded border border-yellow-300">🖨️ Menunggu Cetak</span>';
}

function renderKiaDeliveryAndSurveyBox(array $row): string {
    $id = (int)$row['id'];
    $status = strtolower((string)($row['status'] ?? 'diajukan'));
    $survei = is_array($row['_kia_survei'] ?? null) ? $row['_kia_survei'] : ['is_lengkap' => false, 'jumlah_selesai' => 0];
    $cetak = is_array($row['_kia_cetak'] ?? null) ? $row['_kia_cetak'] : null;
    $pengaturan = is_array($row['_kia_pengaturan_cetak'] ?? null) ? $row['_kia_pengaturan_cetak'] : ['layanan_cetak_aktif' => true];

    $html = '<div class="kia-delivery-panel" style="display:flex;flex-direction:column;gap:8px;">';

    if (!empty($survei['is_lengkap'])) {
        $html .= '<div style="padding:7px 10px;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12px;font-weight:600;">✓ Survei Pelayanan (SKM, SPKP, SPAK) Sudah Diisi</div>';
    } else {
        $html .= '<div class="delivery-confirmation" style="margin-bottom:2px;background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #16a34a;padding:10px 12px;border-radius:8px;">'
            . '<p style="margin:0 0 6px;font-size:12px;color:#166534;font-weight:600;">🌟 Mari bantu tingkatkan pelayanan KIA melalui survei kepuasan (SKM, SPKP, SPAK).</p>'
            . '<div class="delivery-confirmation__actions" style="display:flex;gap:6px;flex-wrap:wrap;">'
            . '<button type="button" class="delivery-button delivery-button--yes" style="background:#16a34a;border-color:#16a34a;color:#fff;font-weight:600;padding:6px 14px;border-radius:6px;cursor:pointer;" onclick="if(typeof openKiaSurveiModal===\'function\'){openKiaSurveiModal(' . $id . ', false);}">Isi Survei</button>'
            . '</div></div>';
    }

    if ($status === 'selesai') {
        $pdfStatus = is_array($row['_kia_pdf_status'] ?? null) ? $row['_kia_pdf_status'] : ['anak_slots' => [], 'legacy_pdf' => null];
        $anakSlots = is_array($pdfStatus['anak_slots'] ?? null) ? $pdfStatus['anak_slots'] : [];

        if (!empty($anakSlots)) {
            $html .= '<div class="kia-card-pdf-list" style="padding:10px 12px;border-radius:8px;background:#f2f6f5;border:1px solid #c6d8d4;">';
            $html .= '<div style="font-size:12.5px;font-weight:700;color:#0f172a;margin-bottom:6px;">Dokumen Hasil KIA</div>';
            $html .= '<div style="display:flex;flex-direction:column;">';
            $slotTotal = count($anakSlots);
            foreach ($anakSlots as $idx => $slot) {
                $namaAnakRaw = trim((string)($slot['nama_anak'] ?? '')) !== '' ? (string)$slot['nama_anak'] : ('Anak ' . ($idx + 1));
                $pdfSlot = is_array($slot['pdf'] ?? null) ? $slot['pdf'] : null;
                $rowBorder = ($idx < $slotTotal - 1) ? 'border-bottom:1px solid #dbe5e3;' : '';
                $html .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:6px 0;' . $rowBorder . '">';
                $html .= '<span style="font-size:13px;font-weight:600;color:#0f172a;">' . esc($namaAnakRaw) . '</span>';
                if ($pdfSlot !== null) {
                    $docId = (int)($pdfSlot['id'] ?? 0);
                    $safeChildFile = preg_replace('/[^A-Za-z0-9_-]+/', '_', $namaAnakRaw);
                    $safeChildFile = trim((string)$safeChildFile, '_');
                    $dlFileName = 'KIA_' . ($safeChildFile !== '' ? $safeChildFile : ('Anak_' . ($idx + 1))) . '.pdf';
                    $dlUrl = $docId > 0 ? ('kia.php?action=view_dokumen&id_dokumen=' . $docId . '&download=1&ext=.pdf') : ('kia.php?action=download_pdf&id=' . $id . '&download=1');
                    $dlFileJs = htmlspecialchars((string)json_encode($dlFileName, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    $html .= '<a href="' . esc($dlUrl) . '" download="' . esc($dlFileName) . '" onclick="return downloadKiaPdfDirect(event, this, ' . htmlspecialchars((string)json_encode($dlUrl), ENT_QUOTES, 'UTF-8') . ', ' . $dlFileJs . ')" style="font-size:12px;font-weight:600;padding:5px 12px;border-radius:6px;background:#0b6e75;color:#ffffff;text-decoration:none;white-space:nowrap;">&#11015; Unduh PDF</a>';
                } else {
                    $html .= '<span style="font-size:12px;font-weight:600;color:#b45309;white-space:nowrap;">Belum Tersedia</span>';
                }
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }

        if ($cetak) {
            $st = strtolower((string)($cetak['status_cetak'] ?? 'menunggu_cetak'));
            $label = $st === 'selesai_dicetak' ? 'Selesai Dicetak (Siap Diambil di Dukcapil/Kecamatan)' : ($st === 'sedang_dicetak' ? 'Sedang Dicetak oleh Operator' : 'Menunggu Antrean Cetak Fisik');
            $bg = $st === 'selesai_dicetak' ? '#dcfce7' : ($st === 'sedang_dicetak' ? '#dbeafe' : '#fef3c7');
            $fg = $st === 'selesai_dicetak' ? '#166534' : ($st === 'sedang_dicetak' ? '#1e40af' : '#92400e');
            $bd = $st === 'selesai_dicetak' ? '#86efac' : ($st === 'sedang_dicetak' ? '#93c5fd' : '#fde68a');
            $html .= '<div style="padding:8px 10px;border-radius:8px;background:' . $bg . ';border:1px solid ' . $bd . ';color:' . $fg . ';font-size:12px;">'
                . '<strong>🖨️ Status Cetak Kartu Fisik:</strong><br>' . esc($label);
            if (!empty($cetak['catatan_operator'])) {
                $html .= '<div style="margin-top:4px;font-size:11.5px;">Catatan Operator: ' . esc((string)$cetak['catatan_operator']) . '</div>';
            }
            $html .= '</div>';
        } elseif (empty($pengaturan['layanan_cetak_aktif'])) {
            $daftarBahan = !empty($pengaturan['bahan_kosong']) && is_array($pengaturan['bahan_kosong'])
                ? implode(', ', $pengaturan['bahan_kosong'])
                : 'Bahan cetak';
            $pesanTampil = "Layanan cetak kartu fisik KIA sedang tidak tersedia karena keterbatasan bahan cetak ({$daftarBahan}).";
            $html .= '<div style="padding:8px 10px;border-radius:8px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:11.5px;line-height:1.45;">'
                . '<strong>⚠️ Cetak Kartu Fisik Belum Tersedia:</strong><br>'
                . esc($pesanTampil)
                . '</div>';
        }
    }

    $html .= '</div>';
    return $html;
}

// Function to get status badge color
function getStatusBadge($status) {
    switch ($status) {
        case 'diajukan':
            return '<span data-status-value="diajukan" class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-0.5 rounded border border-yellow-300">Diajukan</span>';
        case 'diproses':
            return '<span data-status-value="diproses" class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded border border-blue-300">Diproses</span>';
        case 'selesai':
            return '<span data-status-value="selesai" class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded border border-green-300">Selesai</span>';
        case 'ditolak':
            return '<span data-status-value="ditolak" class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded border border-red-300">Ditolak</span>';
        default:
            return '<span data-status-value="' . esc((string)$status) . '" class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded border border-gray-300">' . esc((string)$status) . '</span>';
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
        case 'laporan':
            return '<span class="bg-indigo-100 text-indigo-800 text-xs font-medium px-2.5 py-0.5 rounded border border-indigo-300">Laporan Peristiwa</span>';
        case 'kia':
            return '<span class="bg-pink-100 text-pink-800 text-xs font-medium px-2.5 py-0.5 rounded border border-pink-300">KIA</span>';
        default:
            return '<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded border border-gray-300">' . $jenis . '</span>';
    }
}

if (!function_exists('esc')) {
    function esc($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function statusPermohonanUrl(array $params = []): string {
    $filtered = array_filter($params, static fn($value) => $value !== '' && $value !== null);
    return 'status_permohonan.php' . ($filtered ? '?' . http_build_query($filtered) : '');
}

function formatDocumentFileSize(int $bytes): string {
    if ($bytes <= 0) return 'PDF';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1024, 1) . ' KB';
}

function renderOperatorDocumentList(array $deliveryStatus, string $deliveryTable, int $permohonanId): string {
    $status = (string)($deliveryStatus['status'] ?? '');
    $isPartial = $status === 'sebagian_diunggah_perlu_pengajuan_baru';
    $files = is_array($deliveryStatus['files'] ?? null) ? $deliveryStatus['files'] : [];
    $modifier = $isPartial ? ' operator-documents--partial' : '';
    $title = $isPartial ? 'Sebagian Dokumen Tersedia' : 'Dokumen dari Operator';

    $html = '<div class="operator-documents' . $modifier . '">';
    $html .= '<div class="operator-documents__title"><span aria-hidden="true">📄</span> ' . esc($title) . '</div>';

    if (!$files) {
        $html .= '<p class="operator-documents__empty">Dokumen sedang disiapkan. Silakan muat ulang halaman beberapa saat lagi.</p>';
    } else {
        $html .= '<div class="operator-documents__list">';
        foreach ($files as $file) {
            $downloadUrl = (string)($file['download_url'] ?? '');
            if ($downloadUrl === '') continue;
            $fileName = (string)($file['original_name'] ?? 'Dokumen hasil proses.pdf');
            $fileSize = formatDocumentFileSize((int)($file['file_size'] ?? 0));
            $uploadedAt = '';
            if (!empty($file['uploaded_at']) && strtotime((string)$file['uploaded_at']) !== false) {
                $uploadedAt = date('d-m-Y H:i', strtotime((string)$file['uploaded_at'])) . ' WITA';
            }
            $meta = $fileSize . ($uploadedAt !== '' ? ' • ' . $uploadedAt : '');

            $html .= '<div class="operator-document">';
            $html .= '<div class="operator-document__info"><strong>' . esc($fileName) . '</strong><span>' . esc($meta) . '</span></div>';
            $html .= '<a class="operator-document__download" href="' . esc($downloadUrl) . '" data-feedback-table="' . esc($deliveryTable) . '" data-feedback-id="' . $permohonanId . '">';
            $html .= '<span aria-hidden="true">↓</span> Download</a>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }

    if ($isPartial) {
        $note = trim((string)($deliveryStatus['catatan_operator'] ?? ''));
        if ($note !== '') {
            $html .= '<p class="operator-documents__note"><strong>Catatan operator:</strong> ' . nl2br(esc($note)) . '</p>';
        }
    }

    $html .= '</div>';
    return $html;
}

function renderEmailDeliveryActions(array $deliveryStatus, string $deliveryTable, int $permohonanId, string $jenis): string {
    if (empty($deliveryStatus['available'])) return '';

    if (empty($deliveryStatus['eligible'])) {
        $minutes = max(1, (int)ceil(((int)($deliveryStatus['seconds_remaining'] ?? 0)) / 60));
        $message = !empty($deliveryStatus['is_recheck']) ? 'Menunggu TTE' : 'Konfirmasi tersedia';
        return '<p class="delivery-message">' . esc($message) . ' dalam sekitar ' . $minutes . ' menit.</p>';
    }

    $status = (string)($deliveryStatus['status'] ?? 'menunggu');
    if ($status === 'menunggu') {
        $yesAction = esc('confirmEmailDelivery(' . $permohonanId . ', ' . json_encode($jenis) . ", 'sudah_masuk')");
        $noAction = esc('confirmEmailDelivery(' . $permohonanId . ', ' . json_encode($jenis) . ", 'belum_masuk')");
        return '<div class="delivery-confirmation"><p>Apakah dokumen sudah masuk ke email Anda?</p><div class="delivery-confirmation__actions">'
            . '<button type="button" class="delivery-button delivery-button--yes" onclick="' . $yesAction . '">Sudah Masuk</button>'
            . '<button type="button" class="delivery-button delivery-button--no" onclick="' . $noAction . '">Belum Masuk</button>'
            . '</div></div>';
    }

    if ($status === 'sudah_masuk') {
        return '<span class="delivery-state delivery-state--success">Dokumen sudah diterima</span>';
    }

    if ($status === 'belum_masuk') {
        return '<span class="delivery-state delivery-state--waiting">Menunggu tindak lanjut operator</span>';
    }

    if (in_array($status, ['diunggah_operator', 'sebagian_diunggah_perlu_pengajuan_baru'], true)) {
        $html = renderOperatorDocumentList($deliveryStatus, $deliveryTable, $permohonanId);
        if ($status === 'sebagian_diunggah_perlu_pengajuan_baru') {
            $newUrl = email_delivery_new_application_url((string)($deliveryStatus['source_table'] ?? ''), 'user');
            $html .= '<a class="delivery-new-application" href="' . esc($newUrl) . '">Ajukan Dokumen yang Belum Tersedia</a>';
        }
        return $html;
    }

    if ($status === 'perlu_pengajuan_baru') {
        $newUrl = email_delivery_new_application_url((string)($deliveryStatus['source_table'] ?? ''), 'user');
        $note = trim((string)($deliveryStatus['catatan_operator'] ?? ''));
        $html = '<div class="delivery-reapply"><strong>Dokumen perlu diajukan kembali.</strong>';
        $html .= '<p><strong>Catatan operator:</strong> ' . ($note !== '' ? nl2br(esc($note)) : '-') . '</p>';
        $html .= '<a href="' . esc($newUrl) . '">Ajukan Permohonan Baru</a></div>';
        return $html;
    }

    return '';
}

// Get stats for dashboard
$statsQuery = $koneksi->prepare("
    SELECT 
        jenis,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) as diajukan,
        SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) as diproses,
        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) as selesai,
        SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) as ditolak
    FROM (
        SELECT 'kelahiran' as jenis, status FROM tb_akta_kelahiran WHERE id_user = ?
        UNION ALL
        SELECT 'kematian' as jenis, status FROM tb_akta_kematian WHERE id_user = ?
        UNION ALL
        SELECT 'kk' as jenis, status FROM tb_kartukeluarga WHERE id_user = ?
        UNION ALL
        SELECT 'pindah' as jenis, status FROM tb_pindah_alamat WHERE id_user = ?
        UNION ALL
        SELECT 'pindah_online' as jenis, status FROM tb_pindah_online WHERE id_user = ?
        UNION ALL
        SELECT 'laporan' as jenis, status FROM tb_laporan_peristiwa WHERE id_user = ?
        UNION ALL
        SELECT 'kia' as jenis, status FROM tb_pengajuan_kia WHERE id_user = ?
    ) as combined
    GROUP BY jenis
");

if (!$statsQuery) {
    die('Prepare failed: ' . $koneksi->error);
}

$statsQuery->bind_param('iiiiiii', $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
$statsQuery->execute();
$statsResult = $statsQuery->get_result();

$stats = [
    'kelahiran' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'kematian' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'kk' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'pindah' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'pindah_online' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'laporan' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0],
    'kia' => ['total' => 0, 'diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0]
];

while ($row = $statsResult->fetch_assoc()) {
    $stats[$row['jenis']] = $row;
}
$statsQuery->close();

$statusTotals = ['diajukan' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0];
$allPermohonanTotal = 0;
foreach ($stats as $typeStats) {
    $allPermohonanTotal += (int)($typeStats['total'] ?? 0);
    foreach ($statusTotals as $statusKey => $_) {
        $statusTotals[$statusKey] += (int)($typeStats[$statusKey] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc($csrf_token_value) ?>">
  <title>Status Permohonan - SIPRIMA</title>
  <link rel="icon" type="image/webp" href="../assets/prima.webp">
  <!-- Tailwind static CSS (no runtime CDN warning) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
  <link rel="stylesheet" href="css/status_permohonan.css?v=20260822-2">
  <link rel="stylesheet" href="css/mobile_bottom_nav.css?v=20260903-1">
  <link rel="stylesheet" href="css/user_sidebar.css?v=20260824-1">
    <link rel="stylesheet" href="../assets/css/service-timeline.css?v=20260908-1">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-12">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body class="status-page">

  <div class="layout">

    <?php include __DIR__ . '/user_sidebar.php'; ?>

    <main class="main">
      <header class="header user-general-header">
        <?php
        require_once __DIR__ . '/user_header_brand.php';
        render_user_header_brand(
          'Status Permohonan',
          'Sistem Informasi Pelayanan Kependudukan'
        );
        ?>
        <div class="user-info">
          <span class="user-name"><?= esc($_SESSION['nama']) ?></span>
          <?php include __DIR__ . '/notification_widget.php'; ?>
          <span class="role-badge"><?= strtoupper($_SESSION['role'] ?? 'USER') ?></span>
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
                Harap periksa email Anda untuk dokumen dari <strong>SIAK Online DITJEN DUKCAPIL KEMENDAGRI</strong>, kemudian konfirmasikan apakah dokumen sudah diterima atau belum melalui kolom <strong>Konfirmasi Dokumen</strong>.
              </div>
            </div>
          </div>
        <?php endif; ?>
        <!-- Ringkasan khusus ponsel -->
        <div class="mobile-status-overview" aria-label="Ringkasan status permohonan">
          <a class="mobile-total-card" href="status_permohonan.php">
            <span>Total Permohonan</span>
            <strong><?= $allPermohonanTotal ?></strong>
          </a>
          <div class="mobile-status-chips">
            <?php
            $mobileStatusLabels = [
              'diajukan' => 'Diajukan',
              'diproses' => 'Diproses',
              'selesai' => 'Selesai',
              'ditolak' => 'Ditolak',
            ];
            foreach ($mobileStatusLabels as $statusKey => $statusLabel):
            ?>
              <a
                class="mobile-status-chip mobile-status-chip--<?= esc($statusKey) ?><?= $filter_status === $statusKey ? ' is-active' : '' ?>"
                href="<?= esc(statusPermohonanUrl(['filter' => $statusKey])) ?>"
                <?= $filter_status === $statusKey ? 'aria-current="true"' : '' ?>
              >
                <span><?= esc($statusLabel) ?></span>
                <strong><?= $statusTotals[$statusKey] ?></strong>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Stats Cards Desktop -->
        <div class="stats-grid desktop-stats-grid">
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
            <div class="stat-icon" style="background: #f3e8ff; color: #8e44ad;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Laporan Peristiwa</h3>
              <div class="number"><?= $stats['laporan']['total'] ?></div>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon" style="background: #fce7f3; color: #db2777;">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                <circle cx="9" cy="10" r="2"></circle>
                <line x1="15" y1="8" x2="19" y2="8"></line>
                <line x1="15" y1="12" x2="19" y2="12"></line>
                <line x1="7" y1="16" x2="17" y2="16"></line>
              </svg>
            </div>
            <div class="stat-info">
              <h3>Kartu Identitas Anak</h3>
              <div class="number"><?= $stats['kia']['total'] ?></div>
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

        <!-- Pencarian dan Filter -->
        <div class="status-toolbar">
          <div class="status-search-row">
            <label for="searchPermohonan" class="sr-only">Cari permohonan</label>
            <input id="searchPermohonan" class="status-search-input" type="search" value="<?= esc($search) ?>" placeholder="Cari nama, subjek, kecamatan, atau desa" onkeydown="if(event.key==='Enter'){event.preventDefault();applyFilters();}">
            <button type="button" class="status-search-button" onclick="applyFilters()">Cari</button>
            <button type="button" class="mobile-filter-button" id="openStatusFilters" aria-controls="statusFilterPanel" aria-expanded="false">
              Filter
              <?php if ($filter_jenis !== '' || $filter_status !== ''): ?>
                <span class="mobile-filter-count"><?= ($filter_jenis !== '' ? 1 : 0) + ($filter_status !== '' ? 1 : 0) ?></span>
              <?php endif; ?>
            </button>
          </div>

          <?php if ($filter_jenis !== '' || $filter_status !== '' || $search !== ''): ?>
            <div class="active-filter-chips" aria-label="Filter aktif">
              <?php if ($filter_jenis !== ''): ?>
                <a href="<?= esc(statusPermohonanUrl(['filter' => $filter_status, 'search' => $search])) ?>">
                  Jenis: <?= esc([
                    'kelahiran' => 'Akta Kelahiran', 'kematian' => 'Akta Kematian', 'kk' => 'Kartu Keluarga',
                    'pindah' => 'Pindah Alamat', 'pindah_online' => 'Pindah Masuk', 'laporan' => 'Laporan Peristiwa', 'kia' => 'Kartu Identitas Anak (KIA)'
                  ][$filter_jenis] ?? $filter_jenis) ?> <span aria-hidden="true">×</span>
                </a>
              <?php endif; ?>
              <?php if ($filter_status !== ''): ?>
                <a href="<?= esc(statusPermohonanUrl(['jenis' => $filter_jenis, 'search' => $search])) ?>">
                  Status: <?= esc(ucfirst($filter_status)) ?> <span aria-hidden="true">×</span>
                </a>
              <?php endif; ?>
              <?php if ($search !== ''): ?>
                <a href="<?= esc(statusPermohonanUrl(['jenis' => $filter_jenis, 'filter' => $filter_status])) ?>">
                  Pencarian: <?= esc($search) ?> <span aria-hidden="true">×</span>
                </a>
              <?php endif; ?>
              <a class="clear-all-filters" href="status_permohonan.php">Hapus semua</a>
            </div>
          <?php endif; ?>

          <div class="status-filter-backdrop" id="statusFilterBackdrop" aria-hidden="true"></div>
          <div class="filter-section" id="statusFilterPanel" aria-hidden="false">
            <div class="mobile-filter-header">
              <div>
                <strong>Filter Permohonan</strong>
                <span>Pilih jenis dan status</span>
              </div>
              <button type="button" id="closeStatusFilters" aria-label="Tutup filter">&times;</button>
            </div>
          <div class="filter-group">
            <div>
              <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Jenis Permohonan</label>
              <select id="filterJenis" class="select">
                <option value="">Semua Jenis</option>
                <option value="kelahiran" <?= $filter_jenis == 'kelahiran' ? 'selected' : '' ?>>Akta Kelahiran</option>
                <option value="kematian" <?= $filter_jenis == 'kematian' ? 'selected' : '' ?>>Akta Kematian</option>
                <option value="kk" <?= $filter_jenis == 'kk' ? 'selected' : '' ?>>Kartu Keluarga</option>
                <option value="pindah" <?= $filter_jenis == 'pindah' ? 'selected' : '' ?>>Pindah Alamat</option>
                <option value="pindah_online" <?= $filter_jenis == 'pindah_online' ? 'selected' : '' ?>>Pindah Masuk</option>
                <option value="laporan" <?= $filter_jenis == 'laporan' ? 'selected' : '' ?>>Laporan Peristiwa</option>
                <option value="kia" <?= $filter_jenis == 'kia' ? 'selected' : '' ?>>Kartu Identitas Anak (KIA)</option>
              </select>
            </div>
            
            <div>
              <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Status</label>
              <select id="filterStatus" class="select">
                <option value="">Semua Status</option>
                <option value="diajukan" <?= $filter_status == 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                <option value="diproses" <?= $filter_status == 'diproses' ? 'selected' : '' ?>>Diproses</option>
                <option value="selesai" <?= $filter_status == 'selesai' ? 'selected' : '' ?>>Selesai</option>
                <option value="ditolak" <?= $filter_status == 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
              </select>
            </div>

          </div>

          <button type="button" class="apply-filter-button" onclick="applyFilters()">Terapkan Filter</button>
          
          <a href="ajukan_permohonan.php" class="btn-view filter-new-application">
            + Ajukan Permohonan Baru
          </a>
          </div>
        </div>


        <!-- Table Card -->
        <div class="table-card">
          <div class="table-header">
            <h2 class="table-title">Riwayat Permohonan</h2>
          </div>

          <!-- Card khusus ponsel -->
          <div class="mobile-permohonan-list" id="permohonan-mobile-list">
            <?php if (empty($permohonan)): ?>
              <div class="mobile-empty-state">
                <div aria-hidden="true">📄</div>
                <h3>Belum ada permohonan</h3>
                <p>Anda belum mengajukan permohonan apa pun.</p>
                <a href="ajukan_permohonan.php">Ajukan Permohonan Pertama</a>
              </div>
            <?php else: ?>
              <?php foreach ($permohonan as $row): ?>
                <?php
                $delivery_table = (string)($row['_delivery_table'] ?? '');
                $delivery_status = is_array($row['_delivery_status'] ?? null) ? $row['_delivery_status'] : ['available' => false];
                $delivery_html = renderEmailDeliveryActions($delivery_status, $delivery_table, (int)$row['id'], (string)$row['jenis']);
                if ($row['jenis'] === 'kia') {
                  $delivery_html = renderKiaDeliveryAndSurveyBox($row);
                }
                $location_parts = array_filter([(string)($row['nama_desa'] ?? ''), (string)($row['nama_kecamatan'] ?? '')]);
                $submitted_time = !empty($row['tanggal_diajukan']) && strtotime((string)$row['tanggal_diajukan']) !== false
                  ? date('d-m-Y H:i', strtotime((string)$row['tanggal_diajukan'])) . ' WITA'
                  : (string)($row['tanggal_diajukan'] ?? '-');
                ?>
                <article class="mobile-permohonan-card" data-permohonan-status="<?= esc(strtolower((string)$row['status'])) ?>">
                  <div class="mobile-permohonan-card__badges">
                    <?= getJenisBadge($row['jenis']) ?>
                    <?= getStatusBadge($row['status']) ?>
                    <?= ($row['jenis'] === 'kia' && !empty($row['_kia_cetak'])) ? renderKiaPrintStatusBadge($row['_kia_cetak']) : '' ?>
                  </div>

                  <h3><?= esc($row['nama']) ?></h3>
                  <?php if (!empty($row['keterangan'])): ?>
                    <p class="mobile-permohonan-card__description"><?= esc($row['keterangan']) ?></p>
                  <?php endif; ?>

                  <div class="mobile-permohonan-card__meta">
                    <div><span>Lokasi</span><strong><?= esc($location_parts ? implode(', ', $location_parts) : '-') ?></strong></div>
                    <div><span>Diajukan</span><strong><?= esc($submitted_time) ?></strong></div>
<div><span>Status diperbarui</span><strong><?= esc(serviceTrackingUpdatedLabel($koneksi, serviceTrackingTableForKind((string)$row['jenis']), (int)$row['id'])) ?></strong></div>
                  </div>

                  <?php if (!empty($row['alasan'])): ?>
                    <div class="mobile-rejection-reason"><strong>Alasan / catatan:</strong> <?= nl2br(esc($row['alasan'])) ?></div>
                  <?php endif; ?>

                  <?php if ($delivery_html !== ''): ?>
                    <section class="mobile-permohonan-card__delivery" aria-label="Dokumen dan konfirmasi">
                      <?= $delivery_html ?>
                    </section>
                  <?php endif; ?>

                  <div class="mobile-permohonan-card__actions">
                    <?php if ($row['jenis'] === 'kia' && strtolower((string)$row['status']) === 'selesai'): ?>
                      <?php if (empty($row['_kia_pdf_status']['anak_slots'])): ?>
                        <a href="kia.php?action=download_pdf&amp;id=<?= (int)$row['id'] ?>" download="Hasil_KIA_<?= (int)$row['id'] ?>.pdf" onclick="return downloadKiaPdfDirect(event, this, 'kia.php?action=download_pdf&amp;id=<?= (int)$row['id'] ?>', 'Hasil_KIA_<?= (int)$row['id'] ?>.pdf')" class="mobile-action" style="background:#16a34a;color:#fff;text-decoration:none;text-align:center;">&#11015; Unduh KIA (PDF)</a>
                      <?php endif; ?>
                      <?php if (empty($row['_kia_cetak'])): ?>
                        <?php if (!empty($row['_kia_pengaturan_cetak']['layanan_cetak_aktif'])): ?>
                          <button type="button" class="mobile-action" style="background:#0284c7;color:#fff;" onclick="openCetakKiaModal(<?= (int)$row['id'] ?>)">🖨️ Cetak KIA (Kartu Fisik)</button>
                        <?php else: ?>
                          <button type="button" class="mobile-action" style="background:#94a3b8;color:#fff;cursor:not-allowed;" disabled title="<?= esc((string)($row['_kia_pengaturan_cetak']['pesan_status'] ?? '')) ?>">🖨️ Cetak KIA Tidak Tersedia</button>
                        <?php endif; ?>
                      <?php endif; ?>
                    <?php endif; ?>
                    <button type="button" class="mobile-action mobile-action--detail" onclick="showDetail(<?= (int)$row['id'] ?>, '<?= esc($row['jenis']) ?>')">Lihat Detail</button>
                    <?php if (in_array(strtolower((string)$row['status']), ['diajukan', 'diproses', 'ditolak'], true)): ?>
                      <button type="button" class="mobile-action mobile-action--edit" onclick="editPermohonan(<?= (int)$row['id'] ?>, '<?= esc($row['jenis']) ?>')">Edit &amp; Ajukan Kembali</button>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
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
                <p>Anda belum mengajukan permohonan apapun.</p>
                <a href="ajukan_permohonan.php" class="btn-view" style="margin-top: 20px; padding: 10px 20px;">
                  Ajukan Permohonan Pertama
                </a>
              </div>
            <?php else: ?>
              <div class="desktop-permohonan-list" id="tabel-permohonan-konten">
                <?php
                $counter = ($page - 1) * $limit + 1;
                foreach ($permohonan as $row):
                  $delivery_table = (string)($row['_delivery_table'] ?? '');
                  $delivery_status = is_array($row['_delivery_status'] ?? null) ? $row['_delivery_status'] : ['available' => false];
                  $delivery_actions = renderEmailDeliveryActions($delivery_status, $delivery_table, (int)$row['id'], (string)$row['jenis']);
                  if ($row['jenis'] === 'kia') {
                    $delivery_actions = renderKiaDeliveryAndSurveyBox($row);
                  }
                  $location_parts = array_filter([(string)($row['nama_desa'] ?? ''), (string)($row['nama_kecamatan'] ?? '')]);
                  $submitted_time = !empty($row['tanggal_diajukan']) && strtotime((string)$row['tanggal_diajukan']) !== false
                    ? date('d-m-Y H:i', strtotime((string)$row['tanggal_diajukan'])) . ' WITA'
                    : (string)($row['tanggal_diajukan'] ?? '-');
                  $updated_time = !empty($row['tanggal_update']) && strtotime((string)$row['tanggal_update']) !== false
                    ? date('d-m-Y H:i', strtotime((string)$row['tanggal_update'])) . ' WITA'
                    : (string)($row['tanggal_update'] ?? '');
                ?>
                  <article class="desktop-permohonan-card" data-permohonan-status="<?= esc(strtolower((string)$row['status'])) ?>">
                    <header class="desktop-permohonan-card__header">
                      <div class="desktop-permohonan-card__identity">
                        <span class="desktop-permohonan-card__number">No. <?= $counter++ ?></span>
                        <?= getJenisBadge($row['jenis']) ?>
                      </div>
                      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <?= ($row['jenis'] === 'kia' && !empty($row['_kia_cetak'])) ? renderKiaPrintStatusBadge($row['_kia_cetak']) : '' ?>
                        <?= getStatusBadge($row['status']) ?>
                      </div>
                    </header>

                    <div class="desktop-permohonan-card__body">
                      <div class="desktop-permohonan-card__summary">
                        <h3><?= esc($row['nama']) ?></h3>
                        <?php if (!empty($row['keterangan'])): ?>
                          <p class="desktop-permohonan-card__description"><?= esc($row['keterangan']) ?></p>
                        <?php endif; ?>

                        <div class="desktop-permohonan-card__meta">
                          <div><span>Lokasi</span><strong><?= esc($location_parts ? implode(', ', $location_parts) : '-') ?></strong></div>
                          <div><span>Diajukan</span><strong><?= esc($submitted_time) ?></strong></div>
<div><span>Status diperbarui</span><strong><?= esc(serviceTrackingUpdatedLabel($koneksi, serviceTrackingTableForKind((string)$row['jenis']), (int)$row['id'])) ?></strong></div>
                          <?php if ($updated_time !== '' && $row['tanggal_update'] != $row['tanggal_diajukan']): ?>
                            <div><span>Terakhir diperbarui</span><strong><?= esc($updated_time) ?></strong></div>
                          <?php endif; ?>
                        </div>

                        <?php if (!empty($row['alasan'])): ?>
                          <div class="desktop-permohonan-card__reason"><strong>Alasan / catatan:</strong> <?= nl2br(esc($row['alasan'])) ?></div>
                        <?php endif; ?>
                      </div>

                      <aside class="desktop-permohonan-card__side" aria-label="Dokumen dan tindakan">
                        <div class="desktop-permohonan-card__delivery">
                          <span class="desktop-permohonan-card__side-label">Dokumen dan Konfirmasi</span>
                          <?= $delivery_actions !== '' ? $delivery_actions : '<p class="desktop-permohonan-card__empty">Belum tersedia.</p>' ?>
                        </div>

                        <div class="desktop-permohonan-card__actions">
                          <?php if ($row['jenis'] === 'kia' && strtolower((string)$row['status']) === 'selesai'): ?>
                            <?php if (empty($row['_kia_pdf_status']['anak_slots'])): ?>
                              <a href="kia.php?action=download_pdf&amp;id=<?= (int)$row['id'] ?>" download="Hasil_KIA_<?= (int)$row['id'] ?>.pdf" onclick="return downloadKiaPdfDirect(event, this, 'kia.php?action=download_pdf&amp;id=<?= (int)$row['id'] ?>', 'Hasil_KIA_<?= (int)$row['id'] ?>.pdf')" class="btn-view" style="background:#16a34a;color:#fff;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;">&#11015; Unduh KIA (PDF)</a>
                            <?php endif; ?>
                            <?php if (empty($row['_kia_cetak'])): ?>
                              <?php if (!empty($row['_kia_pengaturan_cetak']['layanan_cetak_aktif'])): ?>
                                <button type="button" class="btn-view" style="background:#0284c7;color:#fff;" onclick="openCetakKiaModal(<?= (int)$row['id'] ?>)">🖨️ Cetak KIA</button>
                              <?php else: ?>
                                <button type="button" class="btn-view" style="background:#94a3b8;color:#fff;cursor:not-allowed;" disabled title="<?= esc((string)($row['_kia_pengaturan_cetak']['pesan_status'] ?? '')) ?>">🖨️ Cetak KIA (Bahan Kosong)</button>
                              <?php endif; ?>
                            <?php endif; ?>
                          <?php endif; ?>
                          <button type="button" class="btn-view" onclick="showDetail(<?= (int)$row['id'] ?>, '<?= esc($row['jenis']) ?>')">Lihat Detail</button>
                          <?php if (in_array(strtolower((string)$row['status']), ['diajukan', 'diproses', 'ditolak'], true)): ?>
                            <button type="button" class="btn-edit" onclick="editPermohonan(<?= (int)$row['id'] ?>, '<?= esc($row['jenis']) ?>')">Edit &amp; Ajukan Kembali</button>
                          <?php endif; ?>
                        </div>
                      </aside>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>

              <!-- Pagination -->
              <?php if ($totalPages > 1): ?>
                <div class="pagination">
                  <button class="page-btn page-prev" onclick="changePage(<?= $page - 1 ?>)" <?= $page <= 1 ? 'disabled' : '' ?>>
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
                    <button class="page-btn page-number <?= $i == $page ? 'active' : '' ?>" onclick="changePage(<?= $i ?>)">
                      <?= $i ?>
                    </button>
                  <?php endfor; ?>
                  
                  <button class="page-btn page-next" onclick="changePage(<?= $page + 1 ?>)" <?= $page >= $totalPages ? 'disabled' : '' ?>>
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

  <?php include __DIR__ . '/mobile_bottom_nav.php'; ?>

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

  // Handler unduhan PDF langsung via Chrome (Blob Streaming menghindari intercept IDM dialog)
  window.downloadKiaPdfDirect = function(event, el, url, defaultFilename) {
    if (event) {
      if (typeof event.preventDefault === 'function') event.preventDefault();
      if (typeof event.stopPropagation === 'function') event.stopPropagation();
    }
    const originalText = el ? el.innerHTML : '';
    if (el) {
      el.style.pointerEvents = 'none';
      el.style.opacity = '0.75';
      el.innerHTML = '&#9203; Mengunduh...';
    }

    fetch(url, {
      method: 'GET',
      credentials: 'same-origin'
    })
    .then(response => {
      if (!response.ok) {
        throw new Error('Gagal mengambil berkas PDF (Status: ' + response.status + ')');
      }
      const contentType = response.headers.get('content-type') || '';
      if (contentType.includes('application/json')) {
        return response.json().then(json => {
          throw new Error(json.error || json.message || 'Gagal mengunduh PDF.');
        });
      }

      let filename = defaultFilename || 'Hasil_KIA.pdf';
      const disposition = response.headers.get('content-disposition');
      if (disposition && disposition.indexOf('filename=') !== -1) {
        const match = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
        if (match && match[1]) {
          filename = match[1].replace(/['"]/g, '').trim();
        }
      }
      return response.blob().then(blob => ({ blob, filename }));
    })
    .then(({ blob, filename }) => {
      const blobUrl = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.style.display = 'none';
      a.href = blobUrl;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => {
        window.URL.revokeObjectURL(blobUrl);
      }, 2000);
    })
    .catch(err => {
      console.warn('Direct blob download fallback to native navigation:', err);
      window.location.href = url;
    })
    .finally(() => {
      if (el) {
        el.style.pointerEvents = '';
        el.style.opacity = '1';
        el.innerHTML = originalText;
      }
    });

    return false;
  };

  // JavaScript untuk halaman status permohonan
  document.addEventListener('DOMContentLoaded', function() {
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
    window.location.href = 'status_permohonan.php' + (params.toString() ? `?${params}` : '');
  }

  function setStatusFilterOpen(isOpen) {
    const panel = document.getElementById('statusFilterPanel');
    const backdrop = document.getElementById('statusFilterBackdrop');
    const trigger = document.getElementById('openStatusFilters');
    if (!panel || !backdrop || !trigger) return;

    panel.classList.toggle('is-open', isOpen);
    backdrop.classList.toggle('is-open', isOpen);
    panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    backdrop.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    document.body.classList.toggle('status-filter-open', isOpen);

    if (isOpen) {
      window.setTimeout(() => document.getElementById('filterJenis')?.focus(), 100);
    } else {
      trigger.focus();
    }
  }

  function syncStatusFilterForViewport() {
    const panel = document.getElementById('statusFilterPanel');
    const backdrop = document.getElementById('statusFilterBackdrop');
    const trigger = document.getElementById('openStatusFilters');
    if (!panel || !backdrop || !trigger) return;

    if (window.innerWidth > 768) {
      panel.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      panel.setAttribute('aria-hidden', 'false');
      backdrop.setAttribute('aria-hidden', 'true');
      trigger.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('status-filter-open');
    } else if (!panel.classList.contains('is-open')) {
      panel.setAttribute('aria-hidden', 'true');
    }
  }

  document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('openStatusFilters')?.addEventListener('click', () => setStatusFilterOpen(true));
    document.getElementById('closeStatusFilters')?.addEventListener('click', () => setStatusFilterOpen(false));
    document.getElementById('statusFilterBackdrop')?.addEventListener('click', () => setStatusFilterOpen(false));
    syncStatusFilterForViewport();
  });

  window.addEventListener('resize', syncStatusFilterForViewport);

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && document.getElementById('statusFilterPanel')?.classList.contains('is-open')) {
      setStatusFilterOpen(false);
    }
  });

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
    let editUrl = '';
    switch(jenis) {
      case 'kelahiran':
        editUrl = `edit_permohonan_akta_kelahiran.php?id=${id}`;
        break;
      case 'kematian':
        editUrl = `edit_permohonan_akta_kematian.php?id=${id}`;
        break;
      case 'kk':
        editUrl = `edit_kk.php?id=${id}`;
        break;
      case 'pindah':
        editUrl = `edit_pindah_alamat.php?id=${id}`;
        break;
      case 'pindah_online':
        editUrl = `edit_pindah_online.php?id=${id}`;
        break;
      case 'laporan':
        editUrl = `edit_lapor_peristiwa.php?id=${id}`;
        break;
      case 'kia':
        editUrl = `kia.php?id=${id}`;
        break;
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
  let pendingKiaModalAction = null;

  function escHtml(text) {
    return escapeHtml(text);
  }

  function showDetailModal(jenis, id) {
    return showDetail(id, jenis);
  }

  window.openKiaLaporFromCard = function(idPengajuan, idAnak, idDokumen, namaAnak, docName) {
    pendingKiaModalAction = {
      type: 'lapor',
      id: Number(idPengajuan) || 0,
      idAnak: Number(idAnak) || 0,
      idDokumen: Number(idDokumen) || 0,
      namaAnak: String(namaAnak || ''),
      docName: String(docName || '')
    };
    showDetail(idPengajuan, 'kia');
    return false;
  };

  window.openKiaConversationFromCard = function(idPengajuan) {
    pendingKiaModalAction = {
      type: 'conversation',
      id: Number(idPengajuan) || 0
    };
    showDetail(idPengajuan, 'kia');
    return false;
  };
  const modalTitle = document.getElementById('modalTitle');
  const modalContent = document.getElementById('modalContent');

  // **PERBAIKAN FUNGSI: Untuk mendapatkan URL dokumen yang benar di PRODUCTION**
  function getDocumentUrl(doc, jenis, rowData) {
    // Prioritas 1: Gunakan full_url dari PHP jika ada
    if (doc.full_url) {
      return doc.full_url;
    }
    
    // Prioritas 2: Gunakan download_url dari PHP jika ada
    if (doc.download_url) {
      return doc.download_url;
    }
    
    // Prioritas 3: Gunakan clean_path yang sudah di-generate oleh PHP
    if (doc.clean_path) {
      // Base URL untuk production
      const baseUrl = 'https://siprima.gorontalokab.go.id/';
      
      // Pastikan path dimulai dengan public/
      let cleanPath = doc.clean_path;
      if (!cleanPath.startsWith('public/')) {
        cleanPath = 'public/' + cleanPath;
      }
      
      // Tambahkan public_html/ jika perlu
      return baseUrl + 'public_html/' + cleanPath;
    }
    
    // Fallback: Coba build URL dari original path
    const originalPath = doc.path || '';
    if (!originalPath) return null;
    
    // Bersihkan path
    let cleanPath = originalPath;
    
    // Jika path mengandung uploads/, pastikan dalam format user/uploads/
    if (cleanPath.includes('uploads/') && !cleanPath.includes('user/uploads/')) {
      cleanPath = cleanPath.replace('uploads/', 'user/uploads/');
    }
    
    // Jika tidak ada user/ di awal, tambahkan
    if (!cleanPath.startsWith('user/') && !cleanPath.startsWith('public/')) {
      cleanPath = 'user/' + cleanPath;
    }
    
    // Format URL final untuk production
    const baseUrl = 'https://siprima.gorontalokab.go.id/';
    return baseUrl + 'public_html/public/' + cleanPath;
  }

  function showDetail(id, jenis) {
    const feedbackTable = {kelahiran:'tb_akta_kelahiran',kematian:'tb_akta_kematian',kk:'tb_kartukeluarga',pindah:'tb_pindah_alamat',pindah_online:'tb_pindah_online',kia:'tb_pengajuan_kia'}[jenis] || '';
    startKomentarPolling(id, jenis);
    // Show loading state
    modalContent.innerHTML = '<div class="text-center py-8">Memuat data...</div>';
    detailModal.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Fetch data via AJAX dengan fallback URL lintas environment.
    const query = `id=${encodeURIComponent(id)}&jenis=${encodeURIComponent(jenis)}`;
    const endpointCandidates = [
      `get_detail_permohonan_user.php?${query}`,
      `./get_detail_permohonan_user.php?${query}`,
      `/user/get_detail_permohonan_user.php?${query}`,
      `/public/user/get_detail_permohonan_user.php?${query}`
    ];

    const tryFetchDetail = (urls, index = 0) => {
      if (index >= urls.length) {
        throw new Error('Network response was not ok');
      }

      return fetch(urls[index], {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      }).then(response => {
        if (response.ok) {
          return response.json();
        }
        return tryFetchDetail(urls, index + 1);
      }).catch(() => tryFetchDetail(urls, index + 1));
    };

    tryFetchDetail(endpointCandidates)
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
        const dokumenHasil = Array.isArray(data.dokumen_hasil) ? data.dokumen_hasil : [];
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
              <div class="detail-value" style="white-space: pre-wrap;">${escapeHtml(data.anggotaKeluarga_text || data.anggotaKeluarga || '-')}</div>
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
        } else if (jenis === 'laporan') {
          content += `
            <div class="section-header">Data Laporan Peristiwa</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Jenis Peristiwa</label>
                <div class="detail-value">${escapeHtml(data.jenis_peristiwa_display || getJenisPeristiwaText(data.jenis_peristiwa))}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Nama Subjek</label>
                <div class="detail-value">${escapeHtml(data.nama_subjek || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">NIK Subjek</label>
                <div class="detail-value">${escapeHtml(data.nik_subjek || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Tempat Lahir/Mati</label>
                <div class="detail-value">${escapeHtml(data.tempat_lahir_mati || '-')}</div>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Tanggal Lahir/Mati</label>
                <div class="detail-value">${escapeHtml(data.tanggal_lahir_mati_formatted || '-')}</div>
              </div>
            </div>
          `;
        }
        
        if (jenis === 'kia') {
          const anakList = Array.isArray(data.anak_list) ? data.anak_list : [];
          content += `
            <div class="section-header">Informasi Pengajuan KIA</div>
            <div class="detail-grid">
              <div class="detail-group">
                <label class="detail-label">Nomor Kartu Keluarga (KK)</label>
                <div class="detail-value">${escapeHtml(data.no_kk || '-')}</div>
              </div>
              <div class="detail-group">
                <label class="detail-label">Nama Pemohon</label>
                <div class="detail-value">${escapeHtml(data.nama_pemohon || data.nama_user || '-')}</div>
              </div>
              <div class="detail-group">
                <label class="detail-label">NIK Pemohon</label>
                <div class="detail-value">${escapeHtml(data.nik_pemohon || data.nik_user || '-')}</div>
              </div>
              <div class="detail-group">
                <label class="detail-label">Email Pengiriman</label>
                <div class="detail-value">${escapeHtml(data.alamat_pengiriman || data.email_user || '-')}</div>
              </div>
              <div class="detail-group">
                <label class="detail-label">Kategori Antrian</label>
                <div class="detail-value">${escapeHtml(data.kategori_antrian || (anakList.length > 1 ? 'Multi-Anak' : 'Reguler'))}</div>
              </div>
              <div class="detail-group">
                <label class="detail-label">Jumlah Anak</label>
                <div class="detail-value">${anakList.length} Anak</div>
              </div>
            </div>
            <div class="section-header">Data Anak (${anakList.length})</div>
            <div style="overflow-x:auto;margin-bottom:16px;">
              <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                  <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;text-align:left;">
                    <th style="padding:8px 10px;">No</th>
                    <th style="padding:8px 10px;">NIK Anak</th>
                    <th style="padding:8px 10px;">Nama Lengkap</th>
                    <th style="padding:8px 10px;">JK</th>
                    <th style="padding:8px 10px;">TTL</th>
                    <th style="padding:8px 10px;">Usia</th>
                    <th style="padding:8px 10px;">No. Akta</th>
                  </tr>
                </thead>
                <tbody>
                  ${anakList.map((a, idx) => `
                    <tr style="border-bottom:1px solid #f1f5f9;">
                      <td style="padding:8px 10px;">${idx + 1}</td>
                      <td style="padding:8px 10px;font-family:monospace;">${escapeHtml(a.nik_anak || '-')}</td>
                      <td style="padding:8px 10px;font-weight:600;">${escapeHtml(a.nama_anak || '-')}</td>
                      <td style="padding:8px 10px;">${escapeHtml(a.jenis_kelamin === 'L' ? 'Laki-Laki' : (a.jenis_kelamin === 'P' ? 'Perempuan' : (a.jenis_kelamin || '-')))}</td>
                      <td style="padding:8px 10px;">${escapeHtml((a.tempat_lahir || '-') + ', ' + (a.tanggal_lahir || '-'))}</td>
                      <td style="padding:8px 10px;">${escapeHtml(String(a.usia_tahun ?? '-'))} Thn (${a.wajib_pasfoto == 1 ? 'Wajib Foto' : '< 5 Thn'})</td>
                      <td style="padding:8px 10px;">${escapeHtml(a.no_akta_kelahiran || '-')}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
            ${String(data.status || '').toLowerCase() === 'selesai' ? (() => {
              const cetakInfo = data.cetak_kia || null;
              const pengaturanCetak = data.pengaturan_cetak || { layanan_cetak_aktif: true, pesan_status: '' };
              const surveiProg = data.survei_progress || { is_lengkap: false };
              const slots = Array.isArray(data.anak_slots_pdf) ? data.anak_slots_pdf : [];
              const fallbackPdfs = Array.isArray(data.hasil_kia_pdf_list) ? data.hasil_kia_pdf_list : [];
              const lapList = Array.isArray(data.laporan_kesalahan_kia) ? data.laporan_kesalahan_kia : [];

              let cetakHtml = '';
              if (cetakInfo) {
                const stCetak = String(cetakInfo.status_cetak || 'menunggu_cetak').toLowerCase();
                const labelCetak = stCetak === 'selesai_dicetak' ? 'Selesai Dicetak (Siap Diambil di Dukcapil/Kecamatan)' : (stCetak === 'sedang_dicetak' ? 'Sedang Dicetak oleh Operator' : 'Menunggu Antrean Cetak');
                cetakHtml = `<div style="margin-top:8px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12.5px;color:#1e3a8a;">
                  <strong>🖨️ Status Pencetakan Kartu Fisik KIA:</strong> ${escapeHtml(labelCetak)}
                  ${cetakInfo.catatan_operator ? `<div style="margin-top:4px;">Catatan Operator: ${escapeHtml(cetakInfo.catatan_operator)}</div>` : ''}
                </div>`;
              } else if (pengaturanCetak.layanan_cetak_aktif) {
                cetakHtml = `<button type="button" onclick="openCetakKiaModal(${Number(data.id)})" class="btn-view" style="background:#0284c7;color:#fff;padding:8px 14px;border-radius:8px;font-weight:600;">🖨️ Ajukan Cetak KIA (Kartu Fisik)</button>`;
              } else {
                const bahanKosong = Array.isArray(pengaturanCetak.bahan_kosong) && pengaturanCetak.bahan_kosong.length > 0
                  ? pengaturanCetak.bahan_kosong.join(', ')
                  : 'Bahan cetak';
                const pesanTampil = `Layanan cetak kartu fisik KIA sedang tidak tersedia karena keterbatasan bahan cetak (${bahanKosong}).`;
                cetakHtml = `<div style="margin-top:8px;padding:10px 14px;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;font-size:12px;color:#9a3412;">
                  <strong>⚠️ Layanan Cetak Fisik KIA Belum Tersedia:</strong> ${escapeHtml(pesanTampil)}
                </div>`;
              }
              const surveiBtnHtml = surveiProg.is_lengkap
                ? `<span style="padding:7px 12px;background:#dcfce7;color:#166534;border-radius:8px;font-size:12px;font-weight:600;">✓ Survei Pelayanan Sudah Diisi</span>`
                : `<button type="button" onclick="hideDetailModal();if(typeof openKiaSurveiModal==='function'){openKiaSurveiModal(${Number(data.id)});}else{openKiaSurveyPromptModal(${Number(data.id)});}" class="btn-view" style="background:#1e3a8a;color:#fff;padding:7px 13px;border-radius:8px;font-weight:600;font-size:12px;">📋 Isi Survei (SKM, SPKP, SPAK)</button>`;

              const safeDownloadName = (nameText, idx) => {
                const cleaned = String(nameText || '').replace(/[^A-Za-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '');
                return 'KIA_' + (cleaned || ('Anak_' + (idx + 1))) + '.pdf';
              };

              let docsListHtml = '';
              if (slots.length > 0) {
                docsListHtml = slots.map((slot, idx) => {
                  const rawNama = slot.nama_anak ? String(slot.nama_anak) : ('Anak ' + (idx + 1));
                  const namaAnak = escapeHtml(rawNama);
                  const p = slot.pdf || null;
                  const rowBorder = idx < slots.length - 1 ? 'border-bottom:1px solid #dbe5e3;' : '';
                  if (p && (p.download_url || p.view_url)) {
                    const dlUrl = p.download_url || p.view_url;
                    const dlFile = safeDownloadName(rawNama, idx);
                    return `
                      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;${rowBorder}">
                        <span style="font-size:13.5px;font-weight:600;color:#0f172a;">${namaAnak}</span>
                        <a href="${escapeHtml(dlUrl)}" download="${escapeHtml(dlFile)}" onclick="return downloadKiaPdfDirect(event, this, '${escapeHtml(dlUrl)}', '${escapeHtml(dlFile)}')" class="btn-view" style="background:linear-gradient(135deg,#0b6e75,#16858a);color:#fff;text-decoration:none;padding:6px 14px;border-radius:6px;font-weight:600;font-size:12px;white-space:nowrap;">&#11015; Unduh PDF</a>
                      </div>
                    `;
                  }
                  return `
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;${rowBorder}">
                      <span style="font-size:13.5px;font-weight:600;color:#0f172a;">${namaAnak}</span>
                      <span style="font-size:12px;font-weight:600;color:#b45309;">Belum Tersedia</span>
                    </div>
                  `;
                }).join('');
              } else if (fallbackPdfs.length > 0) {
                docsListHtml = fallbackPdfs.map((p, idx) => {
                  const rawNama = p.nama_anak ? String(p.nama_anak) : ('Anak ' + (idx + 1));
                  const namaLbl = escapeHtml(rawNama);
                  const dlUrl = p.download_url || ('kia.php?action=download_pdf&id=' + Number(data.id));
                  const dlFile = safeDownloadName(rawNama, idx);
                  const rowBorder = idx < fallbackPdfs.length - 1 ? 'border-bottom:1px solid #dbe5e3;' : '';
                  return `
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;${rowBorder}">
                      <span style="font-size:13.5px;font-weight:600;color:#0f172a;">${namaLbl}</span>
                      <a href="${escapeHtml(dlUrl)}" download="${escapeHtml(dlFile)}" onclick="return downloadKiaPdfDirect(event, this, '${escapeHtml(dlUrl)}', '${escapeHtml(dlFile)}')" class="btn-view" style="background:linear-gradient(135deg,#0b6e75,#16858a);color:#fff;text-decoration:none;padding:6px 14px;border-radius:6px;font-weight:600;font-size:12px;white-space:nowrap;">&#11015; Unduh PDF</a>
                    </div>
                  `;
                }).join('');
              } else {
                const defaultDlUrl = `kia.php?action=download_pdf&id=${Number(data.id)}`;
                const defaultFile = `KIA_${Number(data.id)}.pdf`;
                docsListHtml = `
                  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;">
                    <span style="font-size:13.5px;font-weight:600;color:#0f172a;">Dokumen KIA</span>
                    <a href="${defaultDlUrl}" download="${defaultFile}" onclick="return downloadKiaPdfDirect(event, this, '${defaultDlUrl}', '${defaultFile}')" class="btn-view" style="background:linear-gradient(135deg,#0b6e75,#16858a);color:#fff;text-decoration:none;padding:6px 14px;border-radius:6px;font-weight:600;font-size:12px;white-space:nowrap;">&#11015; Unduh PDF</a>
                  </div>
                `;
              }

              let anakSelectOptionsHtml = '';
              if (slots.length > 0) {
                anakSelectOptionsHtml = slots.map((slot, idx) => {
                  const rawNama = slot.nama_anak ? String(slot.nama_anak) : ('Anak ' + (idx + 1));
                  const p = slot.pdf || null;
                  const docIdVal = p && p.id_dokumen ? Number(p.id_dokumen) : 0;
                  return `<option value="${Number(slot.id || 0)}" data-id-dokumen="${docIdVal}">${escapeHtml(rawNama)}${docIdVal > 0 ? '' : ' (PDF belum tersedia)'}</option>`;
                }).join('');
              } else if (anakList.length > 0) {
                anakSelectOptionsHtml = anakList.map((a, idx) => {
                  const rawNama = a.nama_anak ? String(a.nama_anak) : ('Anak ' + (idx + 1));
                  return `<option value="${Number(a.id || 0)}" data-id-dokumen="0">${escapeHtml(rawNama)}</option>`;
                }).join('');
              } else if (fallbackPdfs.length > 0) {
                anakSelectOptionsHtml = fallbackPdfs.map((p, idx) => {
                  const rawNama = p.nama_anak ? String(p.nama_anak) : ('Dokumen KIA ' + (idx + 1));
                  return `<option value="${Number(p.id_anak || 0)}" data-id-dokumen="${Number(p.id_dokumen || 0)}">${escapeHtml(rawNama)}</option>`;
                }).join('');
              } else {
                anakSelectOptionsHtml = `<option value="0" data-id-dokumen="0">Dokumen KIA</option>`;
              }

              const lapStatusLabel = (s) => {
                const m = { 'menunggu_koreksi':'Dilaporkan', 'dilaporkan':'Dilaporkan', 'sedang_diperiksa':'Sedang Diperiksa', 'dalam_perbaikan':'Dalam Perbaikan', 'selesai_dikoreksi':'Selesai', 'selesai':'Selesai' };
                return m[String(s || '').toLowerCase()] || s;
              };
              const lapKategoriLabel = (k) => {
                const m = {
                  'foto_anak_tertukar': 'Foto anak tertukar',
                  'foto_tertukar': 'Foto anak tertukar',
                  'identitas_anak_tertukar': 'Identitas anak tertukar',
                  'dokumen_salah_anak': 'Identitas / PDF anak tertukar',
                  'data_tidak_sesuai': 'Data pada dokumen tidak sesuai',
                  'identitas_tidak_sesuai': 'Data pada dokumen tidak sesuai',
                  'kualitas_cetak': 'Data pada dokumen tidak sesuai',
                  'kesalahan_lainnya': 'Kesalahan lainnya'
                };
                const key = String(k || '').toLowerCase();
                return m[key] || key.replace(/_/g, ' ');
              };

              const riwayatLapHtml = lapList.length > 0 ? `
                <div id="kiaRiwayatLaporanWrap" style="margin-bottom:14px;">
                  <div style="font-size:12.5px;font-weight:700;color:#0f172a;margin-bottom:8px;">Riwayat Laporan &amp; Percakapan (${lapList.length})</div>
                  ${lapList.map(lap => {
                    const msgs = Array.isArray(lap.pesan_list) ? lap.pesan_list : [];
                    const stLbl = lapStatusLabel(lap.status);
                    const katLbl = lapKategoriLabel(lap.kategori_kesalahan);
                    const activePdf = lap.active_pdf || null;
                    const hasNewPdf = activePdf && Number(activePdf.id || 0) > 0 && Number(activePdf.id) !== Number(lap.id_dokumen || 0);
                    return `
                      <div style="background:#ffffff;border:1px solid #c6d8d4;border-radius:8px;padding:10px 12px;margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
                          <span style="font-size:12.5px;font-weight:700;color:#0f172a;">${lap.nama_anak ? escapeHtml(lap.nama_anak) : 'Dokumen KIA'} &bull; <span style="font-weight:500;color:#475569;">${escapeHtml(katLbl)}</span></span>
                          <span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;background:#dceae7;color:#0b6e75;">${escapeHtml(stLbl)}</span>
                        </div>
                        ${hasNewPdf ? `<div style="font-size:11.5px;color:#065f46;background:#ecfdf5;border:1px solid #a7f3d0;padding:5px 8px;border-radius:6px;margin-bottom:6px;">&#10003; Dokumen PDF KIA anak ini telah diperbarui oleh operator.</div>` : ''}
                        <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">
                          ${msgs.length > 0 ? msgs.map(m => {
                            const isStaffMsg = (m.role_pengirim === 'operator' || m.role_pengirim === 'admin');
                            return `<div style="padding:7px 10px;border-radius:7px;font-size:12px;${isStaffMsg ? 'background:#dceae7;border-left:3px solid #0b6e75;' : 'background:#f8fafc;border:1px solid #e2e8f0;'}">
                              <div style="font-weight:700;font-size:11px;color:#0f172a;">${escapeHtml(m.nama_pengirim || m.role_pengirim)} (${isStaffMsg ? 'Operator' : 'Anda'}) &bull; <span style="font-weight:400;color:#64748b;">${escapeHtml(m.created_at || '')}</span></div>
                              <div style="color:#1e293b;margin-top:2px;white-space:pre-wrap;">${escapeHtml(m.isi_pesan || '')}</div>
                            </div>`;
                          }).join('') : `<div style="font-size:12px;color:#334155;">${escapeHtml(lap.keterangan_kesalahan || '')}</div>`}
                        </div>
                        <div style="display:flex;gap:6px;align-items:center;">
                          <input type="text" id="kiaReplyInput_${Number(lap.id)}" placeholder="Tulis balasan atau komentar lanjutan untuk operator..." style="flex:1;padding:7px 10px;border:1px solid #c6d8d4;border-radius:6px;font-size:12px;background:#fff;">
                          <button type="button" id="kiaReplyBtn_${Number(lap.id)}" onclick="submitKiaErrorReply(${Number(lap.id)}, ${Number(data.id)})" style="background:#0b6e75;color:#fff;border:none;padding:7px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">Kirim Balasan</button>
                        </div>
                      </div>
                    `;
                  }).join('')}
                </div>
              ` : '';

              return `
              <div id="kiaDocsPanel" style="margin:12px 0 14px;padding:14px 16px;background:#dceae7;border:1px solid #c6d8d4;border-radius:10px;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
                  <strong style="color:#0f172a;font-size:14.5px;">Dokumen Hasil KIA</strong>
                  <div>${surveiBtnHtml}</div>
                </div>
                <div style="background:#f2f6f5;border:1px solid #c6d8d4;border-radius:8px;padding:4px 12px;">
                  ${docsListHtml}
                </div>
                <div style="margin-top:10px;">${cetakHtml}</div>
              </div>

              <div id="kiaLaporanKomunikasiPanel" style="margin:0 0 18px;padding:14px 16px;background:#f2f6f5;border:1px solid #c6d8d4;border-radius:10px;">
                <div style="margin-bottom:10px;">
                  <strong style="color:#0f172a;display:block;font-size:14px;">Laporan Kesalahan dan Komunikasi</strong>
                  <span style="font-size:12px;color:#475569;">Jika terdapat kekeliruan pada dokumen KIA (meski status sudah Selesai), laporkan dan komunikasikan langsung dengan operator di sini.</span>
                </div>
                ${riwayatLapHtml}
                <div id="kiaLaporFormBox" style="padding:12px 14px;background:#ffffff;border:1px solid #c6d8d4;border-radius:8px;">
                  <strong style="display:block;font-size:13px;color:#0f172a;margin-bottom:10px;">Buat Laporan Kesalahan Dokumen</strong>
                  <input type="hidden" id="kiaLaporIdPengajuan" value="${Number(data.id)}">
                  <div style="margin-bottom:9px;">
                    <label for="kiaLaporSelectAnak" style="display:block;font-size:12px;font-weight:600;color:#0f172a;margin-bottom:4px;">Pilih Anak yang Dokumennya Bermasalah</label>
                    <select id="kiaLaporSelectAnak" style="width:100%;padding:8px 10px;border:1px solid #c6d8d4;border-radius:6px;background:#fff;font-size:12.5px;">
                      ${anakSelectOptionsHtml}
                    </select>
                  </div>
                  <div style="margin-bottom:9px;">
                    <label for="kiaLaporKategori" style="display:block;font-size:12px;font-weight:600;color:#0f172a;margin-bottom:4px;">Kategori Kesalahan</label>
                    <select id="kiaLaporKategori" style="width:100%;padding:8px 10px;border:1px solid #c6d8d4;border-radius:6px;background:#fff;font-size:12.5px;">
                      <option value="foto_anak_tertukar">Foto anak tertukar</option>
                      <option value="identitas_anak_tertukar">Identitas anak tertukar</option>
                      <option value="data_tidak_sesuai">Data pada dokumen tidak sesuai</option>
                      <option value="kesalahan_lainnya">Kesalahan lainnya</option>
                    </select>
                  </div>
                  <div style="margin-bottom:9px;">
                    <label for="kiaLaporKeterangan" style="display:block;font-size:12px;font-weight:600;color:#0f172a;margin-bottom:4px;">Penjelasan Kesalahan</label>
                    <textarea id="kiaLaporKeterangan" rows="3" placeholder="Jelaskan bagian dokumen yang keliru agar segera ditindaklanjuti operator (status permohonan utama tetap Selesai)..." style="width:100%;padding:8px 10px;border:1px solid #c6d8d4;border-radius:6px;background:#fff;font-size:12.5px;"></textarea>
                  </div>
                  <div id="kiaLaporAlert" style="display:none;margin-bottom:8px;padding:8px 10px;border-radius:6px;font-size:12px;"></div>
                  <button type="button" id="btnSubmitKiaLapor" onclick="submitKiaErrorReport(${Number(data.id)})" style="background:linear-gradient(135deg,#0b6e75,#16858a);color:#fff;border:none;padding:8px 16px;border-radius:7px;font-size:12.5px;font-weight:600;cursor:pointer;">Kirim Laporan</button>
                </div>
              </div>
              `;
            })() : ''}
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
                
                // **PERBAIKAN: Gunakan fungsi getDocumentUrl yang sudah diperbaiki**
                docUrl = getDocumentUrl(doc, jenis, data);
                
                // Buat link hanya jika URL valid
                if (docUrl) {
                  const pasfotoThumb = doc.type === 'pasfoto_anak'
                    ? `<div style="width:64px;height:80px;aspect-ratio:4/5;margin:6px auto;border-radius:4px;overflow:hidden;border:1px solid #c6d8d4;background:#f2f6f5;"><img src="${escapeHtml(docUrl)}" alt="Pasfoto Anak" style="width:100%;height:100%;object-fit:cover;object-position:center 20%;display:block;" onerror="this.parentElement.style.display='none'"></div>`
                    : '';
                  return `
                    <div class="dokumen-item">
                      ${pasfotoThumb}
                      <div class="dokumen-type">${getDocTypeText(doc.type)}</div>
                      <div class="dokumen-name">${escapeHtml(fileName)}</div>
                      <a href="${escapeHtml(docUrl)}" target="_blank" class="btn-view mt-2" style="display: inline-block; padding: 5px 10px;">
                        Lihat Dokumen
                      </a>
                      ${doc.file_exists === false ? 
                        '<span class="text-red-500 text-xs block mt-1">(File mungkin tidak ditemukan)</span>' : 
                        ''}
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

        if (data.dokumen_hasil_enabled && dokumenHasil.length > 0) {
          content += `
            <div class="section-header">Dokumen Hasil Proses</div>
            <div class="dokumen-grid">
              ${dokumenHasil.map((doc, index) => {
                const fileName = doc.original_name || 'Dokumen Hasil ' + (index + 1);
                const downloadUrl = doc.download_url || ('../download_dokumen_hasil.php?id=' + encodeURIComponent(doc.id || ''));
                const fileSize = Number(doc.file_size || 0);
                const sizeText = fileSize > 0 ? ` - ${(fileSize / 1024).toFixed(1)} KB` : '';
                return `
                  <div class="dokumen-item">
                    <div class="dokumen-type">PDF Hasil Proses</div>
                    <div class="dokumen-name">${escapeHtml(fileName)}</div>
                    <div class="text-gray-500 text-xs mt-1">PDF${sizeText}</div>
                    <a href="${escapeHtml(downloadUrl)}" class="btn-view mt-2" data-feedback-table="${feedbackTable}" data-feedback-id="${id}" style="display: inline-block; padding: 5px 10px;">
                      Download PDF
                    </a>
                  </div>
                `;
              }).join('')}
            </div>
          `;
        } else if (data.dokumen_hasil_enabled && ['diproses', 'selesai'].includes((data.status || '').toLowerCase())) {
          content += `
            <div class="section-header">Dokumen Hasil Proses</div>
            <div class="detail-value">Dokumen hasil proses belum tersedia.</div>
          `;
        }

        const emailDelivery = data.email_delivery || {};
        if (emailDelivery.available) {
          const deliveryStatus = emailDelivery.status || 'menunggu';
          let deliveryBody = '';
          if (!emailDelivery.eligible) {
            const minutes = Math.max(1, Math.ceil(Number(emailDelivery.seconds_remaining || 0) / 60));
            deliveryBody = emailDelivery.is_recheck
              ? `<div class="detail-value">Menunggu TTE, tunggu dalam ${minutes} menit lagi.</div>`
              : `<div class="detail-value">Menunggu pengiriman email otomatis. Konfirmasi tersedia sekitar ${minutes} menit lagi.</div>`;
          } else if (deliveryStatus === 'menunggu') {
            deliveryBody = `<div class="detail-value" style="margin-bottom:10px;">Apakah dokumen hasil proses sudah masuk ke email Anda?</div>
              <button type="button" class="btn-view" onclick="confirmEmailDelivery(${id}, '${jenis}', 'sudah_masuk')" style="background:#16a34a;margin-right:8px;">Sudah Masuk Email</button>
              <button type="button" class="btn-view" onclick="confirmEmailDelivery(${id}, '${jenis}', 'belum_masuk')" style="background:#dc2626;">Belum Masuk Email</button>`;
          } else if (deliveryStatus === 'sudah_masuk') {
            deliveryBody = '<div class="detail-value">Dokumen telah Anda konfirmasi masuk ke email.</div>';
          } else if (deliveryStatus === 'belum_masuk') {
            deliveryBody = `<div class="detail-value">Laporan dokumen belum masuk telah diteruskan kepada operator.</div><div class="detail-value" style="margin-top:6px;"><strong>Catatan:</strong> ${escapeHtml(emailDelivery.catatan || '-')}</div>`;
          } else if (deliveryStatus === 'diunggah_operator') {
            const files = Array.isArray(emailDelivery.files) ? emailDelivery.files : [];
            deliveryBody = '<div class="detail-value">Operator telah menyediakan dokumen hasil proses.</div>' + files.map(file => `<a class="btn-view" data-feedback-table="${feedbackTable}" data-feedback-id="${id}" style="display:inline-block;margin-top:8px;" href="${escapeHtml(file.download_url)}">Download ${escapeHtml(file.original_name || 'PDF')}</a>`).join('');
          } else if (deliveryStatus === 'sebagian_diunggah_perlu_pengajuan_baru') {
            const files = Array.isArray(emailDelivery.files) ? emailDelivery.files : [];
            deliveryBody = `<div class="detail-value"><strong>Sebagian dokumen sudah tersedia.</strong></div>${files.map(file => `<a class="btn-view" data-feedback-table="${feedbackTable}" data-feedback-id="${id}" style="display:inline-block;margin:8px 7px 0 0" href="${escapeHtml(file.download_url)}">Download ${escapeHtml(file.original_name || 'PDF')}</a>`).join('')}<div class="detail-value" style="margin-top:10px;white-space:pre-wrap"><strong>Catatan operator:</strong> ${escapeHtml(emailDelivery.catatan_operator || '-')}</div><a class="btn-view" style="display:inline-block;margin-top:10px;background:#d97706" href="ajukan_permohonan.php">Ajukan Dokumen yang Belum Tersedia</a>`;
          } else if (deliveryStatus === 'perlu_pengajuan_baru') {
            const newUrl = 'ajukan_permohonan.php';
            deliveryBody = `<div class="detail-value"><strong>Dokumen perlu diajukan kembali.</strong></div><div class="detail-value" style="margin-top:6px;white-space:pre-wrap"><strong>Catatan operator:</strong> ${escapeHtml(emailDelivery.catatan_operator || '-')}</div><div class="detail-value" style="margin-top:6px">Tindak lanjut: ${escapeHtml(emailDelivery.tindak_lanjut_at || '-')} WITA</div><a class="btn-view" style="display:inline-block;margin-top:10px" href="${newUrl}">Ajukan Permohonan Baru</a>`;
          }
          content += `<div class="section-header">Konfirmasi Penerimaan Email</div>${deliveryBody}`;
        }

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
        if (statusLower === 'diajukan' || statusLower === 'diproses' || statusLower === 'ditolak') {
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
        if (pendingKiaModalAction && Number(pendingKiaModalAction.id) === Number(id) && jenis === 'kia') {
          const act = pendingKiaModalAction;
          pendingKiaModalAction = null;
          window.requestAnimationFrame(() => {
            if (act.type === 'lapor') {
              openKiaLaporForm(act.id, act.idAnak, act.idDokumen, act.namaAnak, act.docName);
            } else if (act.type === 'conversation') {
              const wrap = document.getElementById('kiaRiwayatLaporanWrap') || document.getElementById('kiaDocsPanel');
              if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
          });
        }
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
      'pindah_online': 'Pindah Masuk',
      'laporan': 'Laporan Peristiwa',
      'kia': 'Kartu Identitas Anak (KIA)',
      'laporan': 'Laporan Peristiwa'
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
      'pindah_online': '<span class="bg-teal-100 text-teal-800 text-xs font-medium px-2.5 py-0.5 rounded border border-teal-300">Pindah Masuk</span>',
      'kia': '<span class="bg-pink-100 text-pink-800 text-xs font-medium px-2.5 py-0.5 rounded border border-pink-300">KIA</span>',
      'laporan': '<span class="bg-indigo-100 text-indigo-800 text-xs font-medium px-2.5 py-0.5 rounded border border-indigo-300">Laporan Peristiwa</span>'
    };
    return badgeMap[jenis] || '<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded border border-gray-300">' + jenis + '</span>';
  }

  // Helper function to get jenis peristiwa text
  function getJenisPeristiwaText(jenis) {
    const jenisMap = {
      'lahir': 'Lahir',
      'mati': 'Mati',
      'rentan_adminduk': 'Rentan Administrasi Kependudukan'
    };
    return jenisMap[jenis] || jenis;
  }

  // Helper function to get document type text
  function getDocTypeText(type) {
    if (!type) return 'Dokumen';
    
    const types = {
      'surat_keterangan_rs': 'Surat Keterangan RS',
      'surat_keterangan_kematian_rs': 'Surat Keterangan Kematian RS',
      'formulir': 'Formulir F-2.01 Kelahiran',
      'formulir_kelahiran': 'Formulir F-2.01 Kelahiran',
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
      'fc_kk': 'Fotokopi Kartu Keluarga (KK)',
      'pasfoto_anak': 'Pasfoto Anak',
      'foto_pemohon': 'Foto Pemohon Memegang KK/Akta',
      'dokumen_lainnya': 'Dokumen Pendukung Lainnya',
      'hasil_kia_pdf': 'Dokumen Hasil KIA (PDF)',
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
    const areaKartu = document.getElementById('permohonan-mobile-list');
    if (!areaTabel && !areaKartu) return;

    // Simpan status lama
    const statusLama = areaTabel
      ? Array.from(areaTabel.querySelectorAll('[data-status-value]')).map(element => element.dataset.statusValue || '')
      : [];

    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const kontenBaru = doc.getElementById('tabel-permohonan-konten');
            const kartuBaru = doc.getElementById('permohonan-mobile-list');

            if (kontenBaru) {
                const statusBaru = Array.from(kontenBaru.querySelectorAll('[data-status-value]')).map(element => element.dataset.statusValue || '');
                
                let adaSelesai = false;
                let adaDitolak = false;

                statusBaru.forEach((status, index) => {
                    // Cek perubahan ke Selesai
                    if (status === 'selesai' && statusLama[index] !== 'selesai') {
                        adaSelesai = true;
                    }
                    // Cek perubahan ke Ditolak
                    if (status === 'ditolak' && statusLama[index] !== 'ditolak') {
                        adaDitolak = true;
                    }
                });

                if (areaTabel) areaTabel.innerHTML = kontenBaru.innerHTML;
                if (areaKartu && kartuBaru) areaKartu.innerHTML = kartuBaru.innerHTML;

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
<!-- Modal Prompt Survei KIA (SKM, SPKP & SPAK) -->
<div id="kiaSurveyPromptModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.55);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:16px;max-width:460px;width:100%;padding:28px 24px;text-align:center;box-shadow:0 20px 45px rgba(15,23,42,0.25);position:relative;">
    <button type="button" onclick="closeKiaSurveyPromptModal()" style="position:absolute;top:14px;right:16px;border:none;background:none;font-size:22px;color:#94a3b8;cursor:pointer;line-height:1;" aria-label="Tutup">&times;</button>
    <div style="width:64px;height:64px;border-radius:50%;background:#eff6ff;color:#2563eb;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
      <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
    </div>
    <h3 style="font-size:19px;font-weight:700;color:#0f172a;margin:0 0 10px;">Bantu Kami Meningkatkan Pelayanan KIA</h3>
    <p style="font-size:13.5px;color:#475569;line-height:1.6;margin:0 0 18px;">
      Partisipasi Anda sangat berarti untuk evaluasi dan peningkatan kualitas pelayanan KIA melalui survei SKM, SPKP, dan SPAK.
    </p>
    <div style="display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-bottom:20px;">
      <span style="background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:600;padding:5px 12px;border-radius:999px;">⏱️ Estimasi 3-5 menit</span>
      <span style="background:#f0fdf4;color:#15803d;font-size:12px;font-weight:600;padding:5px 12px;border-radius:999px;">🔒 Data dirahasiakan</span>
    </div>
    <div style="display:flex;gap:10px;justify-content:center;">
      <button type="button" onclick="closeKiaSurveyPromptModal()" style="flex:1;background:#f1f5f9;color:#475569;font-weight:600;font-size:13.5px;padding:11px 16px;border-radius:10px;border:1px solid #cbd5e1;cursor:pointer;">Nanti Saja</button>
      <button type="button" id="kiaSurveyPromptStartBtn" onclick="startKiaSurveiFromPrompt()" style="flex:1.2;background:#1e3a8a;color:#fff;font-weight:600;font-size:13.5px;padding:11px 16px;border-radius:10px;border:none;cursor:pointer;">Isi Survei Sekarang &rarr;</button>
    </div>
  </div>
</div>

<!-- Modal Pengajuan Cetak KIA (Kartu Fisik) -->
<div id="cetakKiaRequestModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(15,23,42,0.58);align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:16px;max-width:460px;width:100%;padding:24px;box-shadow:0 20px 45px rgba(15,23,42,0.25);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
      <h3 style="margin:0;font-size:18px;font-weight:700;color:#0f172a;">🖨️ Permohonan Cetak KIA (Kartu Fisik)</h3>
      <button type="button" onclick="closeCetakKiaModal()" style="border:none;background:none;font-size:22px;color:#64748b;cursor:pointer;">&times;</button>
    </div>
    <p style="font-size:13.5px;color:#475569;line-height:1.55;margin:0 0 14px;">
      Selain mengunduh dokumen PDF KIA secara mandiri, Anda dapat mengajukan pencetakan <strong>Kartu Fisik KIA</strong> kepada Operator Dukcapil/Kecamatan.
    </p>
    <input type="hidden" id="cetakKiaIdPengajuan" value="">
    <div style="margin-bottom:14px;">
      <label for="cetakKiaCatatanPemohon" style="display:block;font-size:12.5px;font-weight:600;color:#334155;margin-bottom:6px;">Catatan Pengambilan / Keterangan Tambahan (Opsional)</label>
      <textarea id="cetakKiaCatatanPemohon" rows="3" maxlength="300" style="width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:13px;box-sizing:border-box;" placeholder="Contoh: Mohon informasi jika kartu fisik KIA sudah siap diambil di loket..."></textarea>
    </div>
    <div id="cetakKiaAlertBox" style="display:none;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:12.5px;font-weight:600;"></div>
    <div style="display:flex;justify-content:flex-end;gap:10px;">
      <button type="button" onclick="closeCetakKiaModal()" style="padding:9px 16px;border-radius:9px;border:1px solid #cbd5e1;background:#fff;color:#475569;font-weight:600;font-size:13px;cursor:pointer;">Batal</button>
      <button type="button" id="btnSubmitCetakKia" onclick="submitCetakKiaRequest()" style="padding:9px 18px;border-radius:9px;border:none;background:#0284c7;color:#fff;font-weight:700;font-size:13px;cursor:pointer;">Ajukan Cetak KIA</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/survei_kia_modal.php'; ?>

<script>
const pendingMandatoryKiaSurveiId = <?= (int)($pendingMandatorySurveiKia['id_pengajuan'] ?? 0) ?>;
let currentPromptKiaId = 0;
function openKiaSurveyPromptModal(idPengajuan) {
  currentPromptKiaId = Number(idPengajuan) || 0;
  if (typeof openKiaSurveiModal === 'function' && currentPromptKiaId > 0) {
    openKiaSurveiModal(currentPromptKiaId, false);
    return;
  }
  const modal = document.getElementById('kiaSurveyPromptModal');
  if (modal) modal.style.display = 'flex';
}
function startKiaSurveiFromPrompt() {
  closeKiaSurveyPromptModal();
  if (typeof openKiaSurveiModal === 'function' && currentPromptKiaId > 0) {
    openKiaSurveiModal(currentPromptKiaId, false);
  } else if (currentPromptKiaId > 0) {
    window.location.href = 'survei_kepuasan.php?id_pengajuan=' + encodeURIComponent(currentPromptKiaId);
  }
}
function closeKiaSurveyPromptModal() {
  const modal = document.getElementById('kiaSurveyPromptModal');
  if (modal) modal.style.display = 'none';
}

// Catatan: Survei bersifat ajakan (sukarela), halaman status_permohonan TIDAK memunculkan popup otomatis.

function openCetakKiaModal(idPengajuan) {
  const modal = document.getElementById('cetakKiaRequestModal');
  const idInput = document.getElementById('cetakKiaIdPengajuan');
  const noteInput = document.getElementById('cetakKiaCatatanPemohon');
  const alertBox = document.getElementById('cetakKiaAlertBox');
  if (idInput) idInput.value = String(idPengajuan || '');
  if (noteInput) noteInput.value = '';
  if (alertBox) alertBox.style.display = 'none';
  if (modal) modal.style.display = 'flex';
}

function closeCetakKiaModal() {
  const modal = document.getElementById('cetakKiaRequestModal');
  if (modal) modal.style.display = 'none';
}

async function submitCetakKiaRequest() {
  const idPengajuan = Number(document.getElementById('cetakKiaIdPengajuan')?.value || 0);
  const catatan = (document.getElementById('cetakKiaCatatanPemohon')?.value || '').trim();
  const alertBox = document.getElementById('cetakKiaAlertBox');
  const btn = document.getElementById('btnSubmitCetakKia');
  if (!idPengajuan) return;

  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Memproses...';
  }
  try {
    const fd = new FormData();
    fd.append('action', 'ajukan_cetak_kia');
    fd.append('csrf_token', window.CSRF_TOKEN || '');
    fd.append('id_pengajuan', String(idPengajuan));
    fd.append('catatan_pemohon', catatan);

    const res = await fetch('kia.php?action=ajukan_cetak_kia', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const json = await res.json();
    if (!json.success) {
      throw new Error(json.error || 'Gagal mengajukan pencetakan kartu fisik KIA.');
    }
    if (alertBox) {
      alertBox.style.display = 'block';
      alertBox.style.background = '#dcfce7';
      alertBox.style.color = '#166534';
      alertBox.style.border = '1px solid #86efac';
      alertBox.textContent = json.message || 'Permohonan cetak KIA berhasil dikirim.';
    }
    setTimeout(() => window.location.reload(), 900);
  } catch (err) {
    if (alertBox) {
      alertBox.style.display = 'block';
      alertBox.style.background = '#fef2f2';
      alertBox.style.color = '#b91c1c';
      alertBox.style.border = '1px solid #fecaca';
      alertBox.textContent = err.message || 'Terjadi kesalahan saat mengajukan cetak KIA.';
    }
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Ajukan Cetak KIA';
    }
  }
}

function openKiaLaporForm(idPengajuan, idAnak, idDokumen, namaAnak, docName) {
  const selectEl = document.getElementById('kiaLaporSelectAnak');
  if (selectEl && idAnak) {
    selectEl.value = String(idAnak);
  }
  const idInput = document.getElementById('kiaLaporIdPengajuan');
  if (idInput && idPengajuan) idInput.value = String(idPengajuan);
  const ketEl = document.getElementById('kiaLaporKeterangan');
  if (ketEl) ketEl.focus();
  const panel = document.getElementById('kiaLaporanKomunikasiPanel') || document.getElementById('kiaLaporFormBox');
  if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function submitKiaErrorReport(idPengajuanArg) {
  const idPengajuan = Number(idPengajuanArg || document.getElementById('kiaLaporIdPengajuan')?.value || 0);
  const selectEl = document.getElementById('kiaLaporSelectAnak');
  let idAnak = 0;
  let idDokumen = 0;
  if (selectEl && selectEl.options.length > 0) {
    idAnak = Number(selectEl.value || 0);
    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    idDokumen = Number(selectedOpt?.getAttribute('data-id-dokumen') || 0);
  }
  const kategori = (document.getElementById('kiaLaporKategori')?.value || 'kesalahan_lainnya').trim();
  const keterangan = (document.getElementById('kiaLaporKeterangan')?.value || '').trim();
  const alertEl = document.getElementById('kiaLaporAlert');
  const btn = document.getElementById('btnSubmitKiaLapor');
  if (!idPengajuan) return;
  if (!keterangan) {
    if (alertEl) {
      alertEl.style.display = 'block';
      alertEl.style.background = '#fef2f2';
      alertEl.style.color = '#b91c1c';
      alertEl.textContent = 'Silakan isi penjelasan kesalahan dokumen terlebih dahulu.';
    }
    return;
  }
  if (btn) { btn.disabled = true; btn.textContent = 'Mengirim...'; }
  try {
    const fd = new FormData();
    fd.append('action', 'lapor_kesalahan');
    fd.append('csrf_token', window.CSRF_TOKEN || '');
    fd.append('id_pengajuan', String(idPengajuan));
    if (idAnak > 0) fd.append('id_anak', String(idAnak));
    if (idDokumen > 0) fd.append('id_dokumen', String(idDokumen));
    fd.append('kategori_kesalahan', kategori);
    fd.append('keterangan_kesalahan', keterangan);

    const res = await fetch('kia.php?action=lapor_kesalahan', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'Gagal mengirim laporan kesalahan.');
    if (alertEl) {
      alertEl.style.display = 'block';
      alertEl.style.background = '#dcfce7';
      alertEl.style.color = '#166534';
      alertEl.textContent = json.message || 'Laporan kesalahan berhasil dikirim.';
    }
    setTimeout(() => showDetail(idPengajuan, 'kia'), 500);
  } catch (err) {
    if (alertEl) {
      alertEl.style.display = 'block';
      alertEl.style.background = '#fef2f2';
      alertEl.style.color = '#b91c1c';
      alertEl.textContent = err.message || 'Gagal mengirim laporan.';
    }
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Kirim Laporan'; }
  }
}

async function submitKiaErrorReply(idLaporan, idPengajuan) {
  const inp = document.getElementById('kiaReplyInput_' + idLaporan);
  const btn = document.getElementById('kiaReplyBtn_' + idLaporan);
  const pesan = (inp?.value || '').trim();
  if (!pesan) return;
  if (inp) inp.disabled = true;
  if (btn) { btn.disabled = true; btn.textContent = 'Mengirim...'; }
  try {
    const fd = new FormData();
    fd.append('action', 'balas_laporan_kesalahan');
    fd.append('csrf_token', window.CSRF_TOKEN || '');
    fd.append('id_laporan', String(idLaporan));
    fd.append('isi_pesan', pesan);

    const res = await fetch('kia.php?action=balas_laporan_kesalahan', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'Gagal mengirim balasan.');
    showDetail(idPengajuan, 'kia');
  } catch (err) {
    alert(err.message || 'Gagal mengirim balasan.');
  } finally {
    if (inp) inp.disabled = false;
    if (btn) { btn.disabled = false; btn.textContent = 'Kirim Balasan'; }
  }
}
</script>
<script src="js/asprima_handoff.js?v=20260908" data-account-id="<?= (int)$_SESSION['id_user'] ?>"></script>
</body>
</html>
