<?php
// get_detail_permohonan_petugas_desa.php - Lokasi: /public/user/
require_once '../error_handler.php';
include '../../app/middleware/petugas_desa_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/service_tracking.php';
require_once __DIR__ . '/../functions/dokumen_hasil.php';
require_once __DIR__ . '/../functions/komentar_permohonan.php';
require_once __DIR__ . '/../functions/email_delivery.php';
require_once __DIR__ . '/../functions/document_token.php';

// Set timezone ke WITA (untuk Indonesia Tengah)
date_default_timezone_set('Asia/Makassar');

// Disable error reporting untuk production
error_reporting(0);
ini_set('display_errors', 0);

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Session expired']);
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$jenis = isset($_GET['jenis']) ? $_GET['jenis'] : '';

if (!$id || !in_array($jenis, ['kelahiran', 'kematian', 'kk', 'pindah', 'pindah_online', 'laporan'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

function mapJenisToSourceTable(string $jenis): ?string {
    return match ($jenis) {
        'kelahiran' => 'tb_akta_kelahiran',
        'kematian' => 'tb_akta_kematian',
        'kk' => 'tb_kartukeluarga',
        'pindah' => 'tb_pindah_alamat',
        'pindah_online' => 'tb_pindah_online',
        'laporan' => 'tb_laporan_peristiwa',
        default => null
    };
}

function getBaseUrl() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . $host;
}

// **FUNGSI SEDERHANA UNTUK PATH FILE - DIPERBAIKI**
function getUserDocumentPath($original_path, $jenis, $row_data) {
    if (empty($original_path)) {
        return ['found' => false, 'web_path' => '', 'server_path' => '', 'url' => ''];
    }
    
    // **BASE URL yang benar**
    $base_url = getBaseUrl(); // https://siprima.gorontalokab.go.id
    $server_base = '/home/siprima.gorontalokab.go.id/public_html/';
    
    // **PERBAIKAN UTAMA: Bersihkan path dari 'user/' yang berulang**
    $cleaned_path = ltrim($original_path, './');
    
    // Hapus 'user/' di awal jika ada (untuk menghindari duplikasi)
    if (strpos($cleaned_path, 'user/') === 0) {
        $cleaned_path = substr($cleaned_path, 5); // Hapus 'user/'
    }
    
    // **Coba beberapa kemungkinan struktur**
    $possibilities = [];
    
    // Kemungkinan 1: File di public/user/uploads/...
    $possibilities[] = [
        'web' => 'user/' . $cleaned_path, // TIDAK ADA 'user/user/'
        'server' => $server_base . 'user/' . $cleaned_path
    ];
    
    // Kemungkinan 2: File langsung di uploads/... (tanpa 'user/')
    $possibilities[] = [
        'web' => 'uploads/' . $cleaned_path,
        'server' => $server_base . 'uploads/' . $cleaned_path
    ];
    
    // Kemungkinan 3: Path sudah lengkap dari database
    $possibilities[] = [
        'web' => $cleaned_path,
        'server' => $server_base . $cleaned_path
    ];
    
    // Kemungkinan 4: Hapus 'uploads/' jika sudah ada di path
    if (strpos($cleaned_path, 'uploads/') !== 0) {
        $possibilities[] = [
            'web' => $cleaned_path,
            'server' => $server_base . $cleaned_path
        ];
    } else {
        // Jika sudah ada 'uploads/', coba tanpa 'user/' tambahan
        $possibilities[] = [
            'web' => 'user/' . $cleaned_path,
            'server' => $server_base . 'user/' . $cleaned_path
        ];
    }
    
    // Cari file yang benar-benar ada
    foreach ($possibilities as $poss) {
        if (file_exists($poss['server'])) {
            return [
                'found' => true,
                'web_path' => $poss['web'],
                'server_path' => $poss['server'],
                'url' => $base_url . '/' . $poss['web']
            ];
        }
    }
    
    // Jika tidak ditemukan, return kemungkinan yang paling mungkin
    // **PERBAIKAN: Pastikan tidak ada duplikasi 'user/'**
    $fallback_web = 'user/' . $cleaned_path;
    
    return [
        'found' => false,
        'web_path' => $fallback_web,
        'server_path' => $server_base . $fallback_web,
        'url' => $base_url . '/' . $fallback_web
    ];
}

// Ambil data wilayah tugas petugas desa
$user_query = $koneksi->query("SELECT 
    u.id_kecamatan,
    u.id_desa,
    k.nama_kecamatan,
    d.nama_desa,
    u.role
FROM tb_users u
LEFT JOIN tb_kecamatan k ON u.id_kecamatan = k.id
LEFT JOIN tb_desa d ON u.id_desa = d.id
WHERE u.id = '$user_id'");

$user_data = $user_query->fetch_assoc();
$user_kecamatan_id = $user_data['id_kecamatan'] ?? 0;
$user_desa_id = $user_data['id_desa'] ?? 0;
$nama_kecamatan_user = $user_data['nama_kecamatan'] ?? '';
$nama_desa_user = $user_data['nama_desa'] ?? '';
$user_role = $user_data['role'] ?? '';

// Query berdasarkan jenis permohonan dengan filter wilayah
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
            DATE_FORMAT(a.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(a.updated_at, '%d-%m-%Y %H:%i') as tanggal_update
        FROM tb_akta_kelahiran a
        LEFT JOIN tb_users u ON a.id_user = u.id
        WHERE a.id = ? AND (a.id_desa = ? OR a.nama_desa = ?)
    ");
    $query->bind_param("iss", $id, $user_desa_id, $nama_desa_user);
} 
elseif ($jenis == 'kematian') {
    $query = $koneksi->prepare("
        SELECT 
            a.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(a.tanggal_kematian, '%d-%m-%Y') as tanggal_kematian_formatted,
            DATE_FORMAT(a.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(a.updated_at, '%d-%m-%Y %H:%i') as tanggal_update
        FROM tb_akta_kematian a
        LEFT JOIN tb_users u ON a.id_user = u.id
        WHERE a.id = ? AND (a.id_desa = ? OR a.nama_desa = ?)
    ");
    $query->bind_param("iss", $id, $user_desa_id, $nama_desa_user);
}
elseif ($jenis == 'kk') {
    $query = $koneksi->prepare("
        SELECT 
            k.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(k.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(k.updated_at, '%d-%m-%Y %H:%i') as tanggal_update
        FROM tb_kartukeluarga k
        LEFT JOIN tb_users u ON k.id_user = u.id
        WHERE k.id = ? AND (k.id_desa = ? OR k.nama_desa = ?)
    ");
    $query->bind_param("iss", $id, $user_desa_id, $nama_desa_user);
}
elseif ($jenis == 'pindah') {
    $query = $koneksi->prepare("
        SELECT 
            p.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(p.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(p.updated_at, '%d-%m-%Y %H:%i') as tanggal_update
        FROM tb_pindah_alamat p
        LEFT JOIN tb_users u ON p.id_user = u.id
        WHERE p.id = ? AND (p.id_desa = ? OR p.nama_desa = ?)
    ");
    $query->bind_param("iss", $id, $user_desa_id, $nama_desa_user);
}
elseif ($jenis == 'pindah_online') {
    $query = $koneksi->prepare("
        SELECT 
            p.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(p.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(p.updated_at, '%d-%m-%Y %H:%i') as tanggal_update
        FROM tb_pindah_online p
        LEFT JOIN tb_users u ON p.id_user = u.id
        WHERE p.id = ? AND (p.id_desa = ? OR p.nama_desa = ?)
    ");
    $query->bind_param("iss", $id, $user_desa_id, $nama_desa_user);
}
elseif ($jenis == 'laporan') {
    $query = $koneksi->prepare("
        SELECT 
            l.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(l.tanggal_lahir_mati, '%d-%m-%Y') as tanggal_lahir_mati_formatted,
            DATE_FORMAT(l.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan,
            DATE_FORMAT(l.updated_at, '%d-%m-%Y %H:%i') as tanggal_update
        FROM tb_laporan_peristiwa l
        LEFT JOIN tb_users u ON l.id_user = u.id
        WHERE l.id_lapor = ? AND (l.id_desa = ? OR l.nama_desa = ?)
    ");
    $query->bind_param("iss", $id, $user_desa_id, $nama_desa_user);
}

// Cek apakah query berhasil dipersiapkan
if (!$query) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Query preparation failed']);
    exit;
}

$query->execute();
$result = $query->get_result();

if ($result->num_rows === 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Data tidak ditemukan atau tidak dalam wilayah tugas Anda']);
    exit;
}

$data = $result->fetch_assoc();
$data['dokumen_hasil_enabled'] = dokumen_hasil_is_enabled();
$source_table = mapJenisToSourceTable($jenis);
if ($source_table !== null) {
    $data['alasan_history'] = getPermohonanRejectionHistory($koneksi, $source_table, $id, 10);
    $data['status_history_html'] = serviceTrackingHtml(serviceTrackingHistory($koneksi, $source_table, $id), true, (string)$data['status']);
    $data['dokumen_hasil'] = dokumen_hasil_list_for_permohonan($koneksi, $source_table, $id);
    $data['komentar_permohonan'] = komentar_permohonan_list($koneksi, $source_table, $id);
    $data['email_delivery'] = email_delivery_status($koneksi, $source_table, $id, $user_id);
    foreach ($data['komentar_permohonan'] as &$komentarItem) {
        $komentarItem['is_mine'] = ((int)$komentarItem['id_user'] === $user_id);
    }
    unset($komentarItem);
} else {
    $data['alasan_history'] = [];
    $data['dokumen_hasil'] = [];
    $data['komentar_permohonan'] = [];
    $data['email_delivery'] = ['available' => false];
}

// **PROSES DOKUMEN - DIPERBAIKI UNTUK HINDARI DUPLIKASI PATH**
if (isset($data['dokumen']) && $data['dokumen'] && $data['dokumen'] !== 'null' && $data['dokumen'] !== '[]') {
    $dokumen_array = json_decode($data['dokumen'], true);
    if (is_array($dokumen_array) && !empty($dokumen_array)) {
        foreach ($dokumen_array as $index => &$doc) {
            if (isset($doc['path']) && !empty($doc['path'])) {
                $url = siprima_document_token_url(__DIR__, (string)$doc['path']);
                $doc['path'] = $url ?? '';
                $doc['web_path'] = $url ?? '';
                $doc['full_url'] = $url ?? '';
                $doc['download_url'] = $url === null ? '' : $url . '&download=1';
                $doc['file_exists'] = $url !== null;
            }
        }
        $data['dokumen'] = json_encode($dokumen_array);
    } else {
        $data['dokumen'] = siprima_mask_document_json(__DIR__, (string)$data['dokumen']);
    }
}

// Ensure semua field ada untuk menghindari "-"
$required_fields = [
    'keterangan' => '',
    'nama_pemohon_full' => isset($data['nama_pemohon']) ? $data['nama_pemohon'] : '',
    'nik_pemohon_full' => isset($data['nik_pemohon']) ? $data['nik_pemohon'] : '',
    'no_hp_pemohon' => isset($data['no_hp']) ? $data['no_hp'] : '',
    'email_pemohon' => isset($data['email']) ? $data['email'] : '',
    'nama_kecamatan' => isset($data['nama_kecamatan']) ? $data['nama_kecamatan'] : '',
    'nama_desa' => isset($data['nama_desa']) ? $data['nama_desa'] : '',
    'nama_bayi' => '',
    'jenis_kelamin' => '',
    'nama_meninggal' => '',
    'nik_meninggal' => '',
    'tanggal_kematian_formatted' => '',
    'tempat' => '',
    'dusun' => '',
    'nama_ayah' => '',
    'nik_ayah' => '',
    'nama_ibu' => '',
    'nik_ibu' => '',
    'nama_saksi1' => '',
    'nik_saksi1' => '',
    'nama_saksi2' => '',
    'nik_saksi2' => '',
    'tanggal_lahir_formatted' => '',
    'waktu_lahir_formatted' => '',
    'tempat_lahir' => '',
    'urutan_kelahiran' => '',
    'Waktu' => '',
    'nama_kepalaKeluarga' => '',
    'anggotaKeluarga' => '',
    'no_kk' => '',
    'alamat_asal' => '',
    'alamat_tujuan' => '',
    'alasan_pindah' => '',
    'jenis_peristiwa' => '',
    'nama_subjek' => '',
    'nik_subjek' => '',
    'jenis_permohonan' => '',
    'no_suket_kehilangan' => '',
    'nomor_akta_lama' => '',
    'tempat_lahir_mati' => '',
    'tanggal_lahir_mati_formatted' => '',
    'tanggal_diajukan' => isset($data['created_at']) ? date('d-m-Y H:i', strtotime($data['created_at'])) : '-',
    'tanggal_update' => isset($data['updated_at']) ? date('d-m-Y H:i', strtotime($data['updated_at'])) : '-'
];

foreach ($required_fields as $field => $default) {
    if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
        $data[$field] = $default;
    }
}

// Fallback untuk data pemohon
if (empty($data['nama_pemohon_full']) && isset($data['nama_pemohon'])) {
    $data['nama_pemohon_full'] = $data['nama_pemohon'];
}
if (empty($data['nik_pemohon_full']) && isset($data['nik_pemohon'])) {
    $data['nik_pemohon_full'] = $data['nik_pemohon'];
}
if (empty($data['no_hp_pemohon']) && isset($data['no_hp'])) {
    $data['no_hp_pemohon'] = $data['no_hp'];
}
if (empty($data['email_pemohon']) && isset($data['email'])) {
    $data['email_pemohon'] = $data['email'];
}

// Format jenis kelamin
if (isset($data['jenis_kelamin'])) {
    if ($data['jenis_kelamin'] == 'L') {
        $data['jenis_kelamin_display'] = 'Laki-laki';
    } elseif ($data['jenis_kelamin'] == 'P') {
        $data['jenis_kelamin_display'] = 'Perempuan';
    } else {
        $data['jenis_kelamin_display'] = $data['jenis_kelamin'] ?: '-';
    }
}

// Format jenis peristiwa
if (isset($data['jenis_peristiwa'])) {
    $peristiwa_map = [
        'lahir' => 'Lahir',
        'mati' => 'Mati',
        'rentan_adminduk' => 'Rentan Administrasi Kependudukan'
    ];
    $data['jenis_peristiwa_display'] = $peristiwa_map[$data['jenis_peristiwa']] ?? $data['jenis_peristiwa'];
}

// Tambahkan informasi wilayah tugas petugas
$data['wilayah_petugas'] = $nama_kecamatan_user . ' / ' . $nama_desa_user;
$data['user_role'] = $user_role;

header('Content-Type: application/json');
echo json_encode($data);
exit;
?>
