<?php
require_once '../error_handler.php';
include '../../app/middleware/user_rs_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../user/service_tracking.php';
require_once __DIR__ . '/../functions/document_token.php';
require_once __DIR__ . '/../functions/email_delivery.php';

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Session expired']);
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$jenis = isset($_GET['jenis']) ? $_GET['jenis'] : '';

if (!$id || !in_array($jenis, ['kelahiran', 'kematian'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

if ($jenis == 'kelahiran') {
    $query = $koneksi->prepare("
        SELECT 
            a.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(a.tanggal_lahir, '%d-%m-%Y') as tanggal_lahir_formatted,
            DATE_FORMAT(a.Waktu, '%H:%i') as waktu_lahir_formatted,
            DATE_FORMAT(a.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan
        FROM tb_akta_kelahiran_rs a
        LEFT JOIN tb_users u ON a.id_user = u.id
        WHERE a.id = ? AND a.id_user = ?
    ");
} else {
    $query = $koneksi->prepare("
        SELECT 
            a.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(a.tanggal_kematian, '%d-%m-%Y') as tanggal_kematian_formatted,
            DATE_FORMAT(a.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan
        FROM tb_akta_kematian_rs a
        LEFT JOIN tb_users u ON a.id_user = u.id
        WHERE a.id = ? AND a.id_user = ?
    ");
}

$query->bind_param("ii", $id, $user_id);
$query->execute();
$result = $query->get_result();

if ($result->num_rows === 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Data tidak ditemukan']);
    exit;
}

$data = $result->fetch_assoc();
$history_table = $jenis === 'kelahiran' ? 'tb_akta_kelahiran_rs' : 'tb_akta_kematian_rs';
$data['alasan_history'] = getPermohonanRejectionHistory($koneksi, $history_table, $id, 15);
    $data['status_history_html'] = serviceTrackingHtml(serviceTrackingHistory($koneksi, $history_table, $id), true, (string)$data['status']);
try {
    $data['email_delivery'] = email_delivery_status($koneksi, $history_table, $id, $user_id);
} catch (Throwable $e) {
    $data['email_delivery'] = ['available'=>false, 'files'=>[]];
}

// Process dokumen paths - SESUAIKAN DENGAN STRUKTUR FOLDER YANG BENAR
if (isset($data['dokumen']) && $data['dokumen']) {
    $dokumen = json_decode($data['dokumen'], true);
    if (is_array($dokumen)) {
        foreach ($dokumen as &$doc) {
            if (isset($doc['path'])) {
                $original_path = $doc['path'];
                
                // Debug log
                error_log("Original dokumen path: " . $original_path);
                
                // Bersihkan path dari ../ dan ./
                $clean_path = ltrim($original_path, './');
                $clean_path = str_replace('../', '', $clean_path);
                
                // Jika path sudah dimulai dengan uploads/, biarkan
                // Jika tidak, sesuaikan dengan struktur folder
                if (strpos($clean_path, 'uploads/') !== 0) {
                    // Jika path dimulai dengan user_rs/, hapus
                    if (strpos($clean_path, 'user_rs/') === 0) {
                        $clean_path = substr($clean_path, strlen('user_rs/'));
                    }
                    
                    // Pastikan dimulai dengan uploads/
                    $clean_path = 'uploads/' . ltrim($clean_path, '/');
                }
                
                // Hapus public/ jika ada di awal path (karena base_url sudah include public/)
                if (strpos($clean_path, 'public/') === 0) {
                    $clean_path = substr($clean_path, strlen('public/'));
                }
                
                // Hapus siprima/ jika ada di awal path
                if (strpos($clean_path, 'siprima/') === 0) {
                    $clean_path = substr($clean_path, strlen('siprima/'));
                }
                
                $url = siprima_document_token_url(__DIR__, $clean_path);
                $doc['full_url'] = $url ?? '';
                $doc['path'] = $url ?? '';
            }
        }
        $data['dokumen'] = json_encode($dokumen);
    } else {
        $data['dokumen'] = siprima_mask_document_json(__DIR__, (string)$data['dokumen']);
    }
}

header('Content-Type: application/json');
echo json_encode($data);
?>
