<?php
// get_detail_permohonan.php - Lokasi: /public/user/
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/service_tracking.php';
require_once __DIR__ . '/../functions/dokumen_hasil.php';
require_once __DIR__ . '/../functions/komentar_permohonan.php';
require_once __DIR__ . '/../functions/formulir_f101.php';
require_once __DIR__ . '/../functions/email_delivery.php';
require_once __DIR__ . '/../functions/document_token.php';
require_once __DIR__ . '/../../app/Models/KiaModel.php';

// Disable error reporting untuk production
error_reporting(0);
ini_set('display_errors', 0);

// Set timezone ke WITA (untuk Indonesia Tengah)
date_default_timezone_set('Asia/Makassar');

// Validasi session
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Session expired']);
    exit;
}

$user_id = (int)$_SESSION['id_user'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$jenis = isset($_GET['jenis']) ? $_GET['jenis'] : '';

if (!$id || !in_array($jenis, ['kelahiran', 'kematian', 'kk', 'pindah', 'pindah_online', 'laporan', 'kia'])) {
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
        'kia' => 'tb_pengajuan_kia',
        default => null
    };
}

function getBaseUrl() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . $host; // Hasil: https://siprima.gorontalokab.go.id
}

// **FUNGSI SEDERHANA YANG PASTI BEKERJA**
function getUserDocumentPath($original_path, $jenis, $row_data) {
    if (empty($original_path)) {
        return ['found' => false, 'web_path' => '', 'server_path' => '', 'url' => ''];
    }
    
    // **DEBUG: Log path dari database**
    error_log("DEBUG - Path dari DB: " . $original_path);
    
    // **BASE URL dan BASE PATH yang benar**
    $base_url = getBaseUrl(); // https://siprima.gorontalokab.go.id
    $server_base = '/home/siprima.gorontalokab.go.id/public_html/';
    
    // Bersihkan path
    $cleaned_path = ltrim($original_path, './');
    
    // **PERBAIKAN UTAMA: Coba beberapa kemungkinan struktur**
    $possibilities = [];
    
    // Kemungkinan 1: File di public/user/uploads/...
    $possibilities[] = [
        'web' => 'user/' . $cleaned_path,
        'server' => $server_base . 'user/' . $cleaned_path
    ];
    
    // Kemungkinan 2: File langsung di public/uploads/... (tanpa user/)
    $possibilities[] = [
        'web' => 'uploads/' . $cleaned_path,
        'server' => $server_base . 'uploads/' . $cleaned_path
    ];
    
    // Kemungkinan 3: File langsung di uploads/... (tanpa public/)
    $possibilities[] = [
        'web' => 'uploads/' . $cleaned_path,
        'server' => $server_base . 'uploads/' . $cleaned_path
    ];
    
    // Kemungkinan 4: File di user/uploads/... (tanpa public/)
    $possibilities[] = [
        'web' => 'user/' . $cleaned_path,
        'server' => $server_base . 'user/' . $cleaned_path
    ];
    
    // Kemungkinan 5: Path dari database sudah lengkap
    $possibilities[] = [
        'web' => $cleaned_path,
        'server' => $server_base . $cleaned_path
    ];
    
    // Cari file yang benar-benar ada
    foreach ($possibilities as $poss) {
        if (file_exists($poss['server'])) {
            error_log("DEBUG - File ditemukan di: " . $poss['server']);
            error_log("DEBUG - Web path: " . $poss['web']);
            
            return [
                'found' => true,
                'web_path' => $poss['web'],
                'server_path' => $poss['server'],
                'url' => $base_url . '/' . $poss['web']
            ];
        }
    }
    
    // Jika tidak ditemukan, return kemungkinan yang paling mungkin
    error_log("DEBUG - File TIDAK ditemukan, menggunakan fallback");
    $fallback_web = 'user/' . $cleaned_path;
    
    return [
        'found' => false,
        'web_path' => $fallback_web,
        'server_path' => $server_base . $fallback_web,
        'url' => $base_url . '/' . $fallback_web
    ];
}

// Query berdasarkan jenis permohonan
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
        FROM tb_akta_kelahiran a
        LEFT JOIN tb_users u ON a.id_user = u.id
        WHERE a.id = ? AND a.id_user = ?
    ");
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
            DATE_FORMAT(a.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan
        FROM tb_akta_kematian a
        LEFT JOIN tb_users u ON a.id_user = u.id
        WHERE a.id = ? AND a.id_user = ?
    ");
}
elseif ($jenis == 'kk') {
    $query = $koneksi->prepare("
        SELECT 
            k.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(k.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan
        FROM tb_kartukeluarga k
        LEFT JOIN tb_users u ON k.id_user = u.id
        WHERE k.id = ? AND k.id_user = ?
    ");
}
elseif ($jenis == 'pindah') {
    $query = $koneksi->prepare("
        SELECT 
            p.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(p.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan
        FROM tb_pindah_alamat p
        LEFT JOIN tb_users u ON p.id_user = u.id
        WHERE p.id = ? AND p.id_user = ?
    ");
}
elseif ($jenis == 'pindah_online') {
    $query = $koneksi->prepare("
        SELECT 
            p.*,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(p.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan
        FROM tb_pindah_online p
        LEFT JOIN tb_users u ON p.id_user = u.id
        WHERE p.id = ? AND p.id_user = ?
    ");
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
            DATE_FORMAT(l.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan
        FROM tb_laporan_peristiwa l
        LEFT JOIN tb_users u ON l.id_user = u.id
        WHERE l.id_lapor = ? AND l.id_user = ?
    ");
}
elseif ($jenis == 'kia') {
    $query = $koneksi->prepare("
        SELECT 
            k.*,
            LOWER(k.status) as status,
            COALESCE(k.alasan_penolakan, '') as alasan,
            kc.nama_kecamatan,
            ds.nama_desa,
            u.nama as nama_pemohon_full,
            u.nik as nik_pemohon_full,
            u.no_hp as no_hp_pemohon,
            u.email as email_pemohon,
            DATE_FORMAT(k.created_at, '%d-%m-%Y %H:%i') as tanggal_diajukan
        FROM tb_pengajuan_kia k
        LEFT JOIN tb_kecamatan kc ON k.id_kecamatan = kc.id
        LEFT JOIN tb_desa ds ON k.id_desa = ds.id
        LEFT JOIN tb_users u ON k.id_user = u.id
        WHERE k.id = ? AND k.id_user = ?
    ");
}

// Cek apakah query berhasil dipersiapkan
if (!$query) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Query preparation failed']);
    exit;
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
if ($jenis === 'kia') {
    $anakStmt = $koneksi->prepare("SELECT * FROM tb_anak_kia WHERE id_pengajuan = ? ORDER BY id ASC");
    $anakList = [];
    if ($anakStmt) {
        $anakStmt->bind_param("i", $id);
        $anakStmt->execute();
        $anakRes = $anakStmt->get_result();
        $todayUser = new DateTime('today');
        while ($aRow = $anakRes->fetch_assoc()) {
            $tglStr = trim((string)($aRow['tanggal_lahir'] ?? ''));
            $aRow['umur_teks'] = '-';
            if ($tglStr !== '') {
                try {
                    $tglObj = new DateTime($tglStr);
                    if ($tglObj <= $todayUser) {
                        $diff = $tglObj->diff($todayUser);
                        if ($diff->y > 0) {
                            $aRow['umur_teks'] = $diff->y . ' Tahun' . ($diff->m > 0 ? ' ' . $diff->m . ' Bulan' : '');
                        } elseif ($diff->m > 0) {
                            $aRow['umur_teks'] = $diff->m . ' Bulan' . ($diff->d > 0 ? ' ' . $diff->d . ' Hari' : '');
                        } else {
                            $aRow['umur_teks'] = max(0, $diff->d) . ' Hari';
                        }
                    }
                } catch (\Throwable $e) {}
            }
            $anakList[] = $aRow;
        }
        $anakStmt->close();
    }
    $data['anak_list'] = $anakList;

    $dokStmt = $koneksi->prepare("SELECT d.*, a.nama_anak FROM tb_dokumen_kia d LEFT JOIN tb_anak_kia a ON d.id_anak = a.id WHERE d.id_pengajuan = ? AND (d.status_dokumen = 'valid' OR d.status_dokumen IS NULL) ORDER BY d.id ASC");
    $dokArray = [];
    $hasilKiaPdfList = [];
    if ($dokStmt) {
        $dokStmt->bind_param("i", $id);
        $dokStmt->execute();
        $dokRes = $dokStmt->get_result();
        while ($dRow = $dokRes->fetch_assoc()) {
            if (($dRow['jenis_dokumen'] ?? '') === 'hasil_kia_pdf' && strtolower((string)($data['status'] ?? '')) !== 'selesai') {
                continue;
            }
            $labelSuffix = !empty($dRow['nama_anak']) ? ' (' . $dRow['nama_anak'] . ')' : '';
            $origName = $dRow['original_name'] ?? $dRow['nama_file'] ?? 'Dokumen';
            $ext = strtolower(pathinfo((string)$dRow['file_path'], PATHINFO_EXTENSION));
            $fullUrl = 'kia.php?action=view_dokumen&id_dokumen=' . (int)$dRow['id'] . '&ext=.' . $ext;
            $dlUrl = 'kia.php?action=view_dokumen&id_dokumen=' . (int)$dRow['id'] . '&download=1&ext=.' . $ext;
            $relDocPath = ltrim((string)($dRow['file_path'] ?? ''), '/\\');
            $realDocPath = $relDocPath !== '' ? realpath(__DIR__ . '/../' . $relDocPath) : false;
            $kiaFileExists = ($realDocPath !== false && is_file($realDocPath));
            $dokArray[] = [
                'type' => (string)$dRow['jenis_dokumen'],
                'original_name' => (string)$origName . $labelSuffix,
                'path' => (string)($dRow['file_path'] ?? ''),
                'full_url' => $fullUrl,
                'download_url' => $dlUrl,
                'versi' => (int)($dRow['versi'] ?? 1),
                'file_exists' => $kiaFileExists,
            ];
            if (($dRow['jenis_dokumen'] ?? '') === 'hasil_kia_pdf') {
                $hasilKiaPdfList[] = [
                    'id_dokumen' => (int)$dRow['id'],
                    'id_anak' => $dRow['id_anak'] ? (int)$dRow['id_anak'] : null,
                    'nama_anak' => (string)($dRow['nama_anak'] ?? ''),
                    'original_name' => (string)$origName,
                    'view_url' => $fullUrl,
                    'download_url' => $dlUrl,
                ];
            }
        }
        $dokStmt->close();
    }
    if (empty($hasilKiaPdfList) && strtolower((string)($data['status'] ?? '')) === 'selesai' && !empty(trim((string)($data['dokumen_kia_pdf'] ?? '')))) {
        $hasilKiaPdfList[] = [
            'id_dokumen' => 0,
            'id_anak' => null,
            'nama_anak' => '',
            'original_name' => basename((string)$data['dokumen_kia_pdf']),
            'view_url' => 'kia.php?action=download_pdf&id=' . (int)$id . '&inline=1',
            'download_url' => 'kia.php?action=download_pdf&id=' . (int)$id . '&download=1',
        ];
    }
    $dokArray = array_values(array_filter($dokArray, static fn(array $d): bool => ($d['type'] ?? '') !== 'hasil_kia_pdf'));
    $data['dokumen'] = json_encode($dokArray);
    $data['hasil_kia_pdf_list'] = $hasilKiaPdfList;

    $data['cetak_kia'] = null;
    $data['pengaturan_cetak'] = ['layanan_cetak_aktif' => true, 'bahan_kosong' => []];
    $data['survei_progress'] = ['is_lengkap' => false, 'jumlah_selesai' => 0];
    $data['anak_slots_pdf'] = [];
    $data['laporan_kesalahan_kia'] = [];

    try {
        $kiaModelDetail = new \Siprima\Models\KiaModel($koneksi);
        try {
            $data['cetak_kia'] = $kiaModelDetail->getCetakKiaByPengajuan((int)$id);
        } catch (\Throwable $e) {}
        try {
            $data['pengaturan_cetak'] = $kiaModelDetail->getPengaturanCetakKia();
        } catch (\Throwable $e) {}
        try {
            $data['survei_progress'] = $kiaModelDetail->getSurveiProgress((int)$id, $user_id);
        } catch (\Throwable $e) {}

        $isKiaSelesai = (strtolower((string)($data['status'] ?? '')) === 'selesai');
        $pdfStatusDetail = ['anak_slots' => []];
        try {
            $pdfStatusDetail = $kiaModelDetail->getHasilPdfStatusByPengajuan((int)$id);
        } catch (\Throwable $e) {}

        $anakSlotsOut = [];
        foreach (($pdfStatusDetail['anak_slots'] ?? []) as $slotRow) {
            $p = $slotRow['pdf'] ?? null;
            $pOut = null;
            if ($isKiaSelesai && $p !== null) {
                $docIdSlot = (int)($p['id'] ?? 0);
                $pOut = [
                    'id_dokumen' => $docIdSlot,
                    'original_name' => (string)($p['original_name'] ?? ('Hasil_KIA_' . ($slotRow['nama_anak'] ?? 'Anak') . '.pdf')),
                    'versi' => (int)($p['versi'] ?? 1),
                    'uploaded_at' => (string)($p['uploaded_at'] ?? ''),
                    'view_url' => $docIdSlot > 0 ? ('kia.php?action=view_dokumen&id_dokumen=' . $docIdSlot . '&ext=.pdf') : ('kia.php?action=download_pdf&id=' . (int)$id . '&inline=1'),
                    'download_url' => $docIdSlot > 0 ? ('kia.php?action=view_dokumen&id_dokumen=' . $docIdSlot . '&download=1&ext=.pdf') : ('kia.php?action=download_pdf&id=' . (int)$id . '&download=1'),
                ];
            }
            $riwayatSlots = [];
            if ($isKiaSelesai && !empty($slotRow['riwayat_pdf']) && is_array($slotRow['riwayat_pdf'])) {
                foreach ($slotRow['riwayat_pdf'] as $revPdf) {
                    $revDocId = (int)($revPdf['id'] ?? 0);
                    if ($revDocId > 0) {
                        $riwayatSlots[] = [
                            'id_dokumen' => $revDocId,
                            'original_name' => (string)($revPdf['original_name'] ?? 'Dokumen_KIA.pdf'),
                            'versi' => (int)($revPdf['versi'] ?? 1),
                            'uploaded_at' => (string)($revPdf['uploaded_at'] ?? ''),
                            'view_url' => 'kia.php?action=view_dokumen&id_dokumen=' . $revDocId . '&ext=.pdf',
                            'download_url' => 'kia.php?action=view_dokumen&id_dokumen=' . $revDocId . '&download=1&ext=.pdf',
                        ];
                    }
                }
            }
            $anakSlotsOut[] = [
                'id' => (int)$slotRow['id'],
                'nama_anak' => (string)($slotRow['nama_anak'] ?? ''),
                'nik_anak' => (string)($slotRow['nik_anak'] ?? ''),
                'urutan_anak' => (int)($slotRow['urutan_anak'] ?? 1),
                'riwayat_pdf' => $riwayatSlots,
                'pdf' => $pOut,
            ];
        }
        $data['anak_slots_pdf'] = $anakSlotsOut;
        try {
            $data['laporan_kesalahan_kia'] = $isKiaSelesai ? $kiaModelDetail->getLaporanKesalahan((int)$id, $user_id) : [];
        } catch (\Throwable $e) {
            $data['laporan_kesalahan_kia'] = [];
        }
    } catch (\Throwable $e) {
        error_log('Detail KIA helper error: ' . $e->getMessage());
    }
}
$data['dokumen_hasil_enabled'] = false;
try {
    $data['dokumen_hasil_enabled'] = dokumen_hasil_is_enabled();
} catch (\Throwable $e) {}
$source_table = mapJenisToSourceTable($jenis);
$data['alasan_history'] = [];
$data['status_history_html'] = '';
$data['dokumen_hasil'] = [];
$data['komentar_permohonan'] = [];
$data['email_delivery'] = ['available' => false];
if ($source_table !== null) {
    try {
        $data['alasan_history'] = getPermohonanRejectionHistory($koneksi, $source_table, $id, 10);
    } catch (\Throwable $e) {}
    try {
        $data['status_history_html'] = serviceTrackingHtml(serviceTrackingHistory($koneksi, $source_table, $id), true, (string)$data['status']);
    } catch (\Throwable $e) {}
    try {
        $data['dokumen_hasil'] = dokumen_hasil_list_for_permohonan($koneksi, $source_table, $id);
    } catch (\Throwable $e) {}
    try {
        $data['komentar_permohonan'] = komentar_permohonan_list($koneksi, $source_table, $id);
    } catch (\Throwable $e) {
        $data['komentar_permohonan'] = [];
    }
    try {
        $data['email_delivery'] = email_delivery_status($koneksi, $source_table, $id, $user_id);
    } catch (\Throwable $e) {
        $data['email_delivery'] = ['available' => false];
    }
    foreach ($data['komentar_permohonan'] as &$komentarItem) {
        $komentarItem['is_mine'] = ((int)$komentarItem['id_user'] === $user_id);
    }
    unset($komentarItem);
}

// **PROSES DOKUMEN - PERBAIKAN FINAL**
if ($jenis !== 'kia' && isset($data['dokumen']) && $data['dokumen'] && $data['dokumen'] !== 'null' && $data['dokumen'] !== '[]') {
    $dokumen_array = json_decode($data['dokumen'], true);
    if (is_array($dokumen_array) && !empty($dokumen_array)) {
        foreach ($dokumen_array as $index => &$doc) {
            if (isset($doc['path']) && !empty($doc['path'])) {
                $url = siprima_document_token_url(__DIR__, (string)$doc['path']);
                $doc['path'] = $url ?? '';
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
    'tanggal_diajukan' => isset($data['created_at']) ? date('d-m-Y H:i', strtotime($data['created_at'])) : '-'
];

foreach ($required_fields as $field => $default) {
    if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
        $data[$field] = $default;
    }
}

// Versi terbaca manusia dari daftar anggota. Nilai `anggotaKeluarga` sengaja TIDAK diubah:
// layanan pindah alamat dan pindah masuk ikut membacanya lewat endpoint yang sama.
$data['anggotaKeluarga_text'] = '-';
$daftarAnggota = json_decode((string)($data['anggotaKeluarga'] ?? ''), true);
if (is_array($daftarAnggota) && $daftarAnggota) {
    $labelOpsional = function_exists('siprima_f101_field_opsional') ? siprima_f101_field_opsional() : [];
    $barisAnggota = [];
    foreach ($daftarAnggota as $i => $anggota) {
        if (!is_array($anggota)) continue;
        $nik = trim((string)($anggota['nik'] ?? ''));
        $hubungan = trim((string)($anggota['hubungan'] ?? ''));
        $baris = ($i + 1) . '. ' . trim((string)($anggota['nama'] ?? '-'))
            . ' (NIK: ' . ($nik === '' ? '-' : $nik) . ')'
            . ' - ' . ($hubungan === '' ? '-' : $hubungan);
        $perubahan = [];
        foreach ($labelOpsional as $key => $label) {
            $nilai = trim((string)($anggota[$key] ?? ''));
            if ($nilai !== '') $perubahan[] = $label . ': ' . $nilai;
        }
        if ($perubahan) $baris .= "
     Perubahan — " . implode(', ', $perubahan);
        $barisAnggota[] = $baris;
    }
    if ($barisAnggota) $data['anggotaKeluarga_text'] = implode("
", $barisAnggota);
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

while (ob_get_level() > 0) {
    @ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
exit;
?>
