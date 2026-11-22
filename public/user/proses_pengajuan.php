<?php
require_once __DIR__ . '/service_tracking.php';
require_once '../error_handler.php';
error_log("=== START PROSES PENGAJUAN ===");
error_log('Request: ' . siprima_request_id());
error_log("Role: " . ($_SESSION['role'] ?? 'NOT SET'));
error_log("User ID: " . ($_SESSION['id_user'] ?? 'NOT SET'));
error_log("Kecamatan tugas IDs: " . print_r($_SESSION['kecamatan_tugas'] ?? [], true));
error_log("Kecamatan nama: " . print_r($_SESSION['kecamatan_nama'] ?? [], true));

include '../../app/middleware/operator_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/status_history_helper.php';
require_once __DIR__ . '/../functions/dokumen_hasil.php';
require_once __DIR__ . '/../functions/komentar_permohonan.php';
require_once __DIR__ . '/../functions/document_token.php';
require_once __DIR__ . '/../functions/akta_kelahiran_kategori.php';
require_once __DIR__ . '/../functions/kk_kategori.php';
$notifikasi_candidates = [
    __DIR__ . '/../functions/notifikasi.php',
    dirname(__DIR__) . '/functions/notifikasi.php',
    dirname(__DIR__, 2) . '/functions/notifikasi.php'
];
$notifikasi_loaded = false;
foreach ($notifikasi_candidates as $notifikasi_file) {
    if (is_file($notifikasi_file)) {
        require_once $notifikasi_file;
        $notifikasi_loaded = true;
        break;
    }
}
if (!$notifikasi_loaded) {
    error_log('notifikasi.php tidak ditemukan. Pencarian: ' . implode(' | ', $notifikasi_candidates));
}
if (!function_exists('buatNotifikasi')) {
    function buatNotifikasi($koneksi, $id_user, $judul, $pesan, $tipe = 'info') {
        return false;
    }
}

$csrf_token_value = csrf_token();

$table_has_resubmitted_at = [];
$table_created_expr = [];
$resubmitted_tables = ['tb_kartukeluarga', 'tb_akta_kelahiran', 'tb_akta_kematian', 'tb_pindah_alamat', 'tb_pindah_online', 'tb_pengajuan_kia'];
foreach ($resubmitted_tables as $tbl) {
    $table_has_resubmitted_at[$tbl] = false;
    $col_check = $koneksi->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'resubmitted_at'");
    if ($col_check && $col_check->num_rows > 0) {
        $table_has_resubmitted_at[$tbl] = true;
    }
    $table_created_expr[$tbl] = $table_has_resubmitted_at[$tbl]
        ? "COALESCE(resubmitted_at, created_at)"
        : "created_at";
}

if (isset($_GET['csrf_refresh']) && $_GET['csrf_refresh'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'csrf_token' => csrf_token()
    ]);
    exit;
}

// Fungsi untuk mendapatkan nama folder dari data permohonan
function getFolderNameFromPermohonan($koneksi, $table, $id) {
    $query = "";
    
    switch ($table) {
        case 'tb_kartukeluarga':
            $query = "SELECT nama_kecamatan, nama_desa, id_user, nik_pemohon, nama_pemohon, 
                             nama_kepalaKeluarga, nama_folder 
                     FROM tb_kartukeluarga WHERE id = ?";
            break;
        case 'tb_akta_kelahiran':
            $query = "SELECT nama_kecamatan, nama_desa, id_user, nik_pemohon, nama_pemohon, 
                             nama_bayi, nama_folder 
                     FROM tb_akta_kelahiran WHERE id = ?";
            break;
        case 'tb_akta_kematian':
            $query = "SELECT nama_kecamatan, nama_desa, id_user, nik_pemohon, nama_pemohon, 
                             Nama_meninggal, nama_folder 
                     FROM tb_akta_kematian WHERE id = ?";
            break;
        case 'tb_pindah_alamat':
            $query = "SELECT nama_kecamatan, nama_desa, id_user, nik_pemohon, nama_pemohon, 
                             nama_kepalaKeluarga, nama_folder 
                     FROM tb_pindah_alamat WHERE id = ?";
            break;
        case 'tb_pindah_online':
            $query = "SELECT nama_kecamatan, nama_desa, id_user, nik_pemohon, nama_pemohon, 
                             nama_kepalaKeluarga, nama_folder 
                     FROM tb_pindah_online WHERE id = ?";
            break;
        default:
            return null;
    }
    
    $stmt = $koneksi->prepare($query);
    if (!$stmt) return null;
    
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    
    if (!$data) return null;
    
    // Prioritas 1: Gunakan nama_folder jika ada
    if (!empty($data['nama_folder'])) {
        return $data['nama_folder'];
    }
    
    // Prioritas 2: Format lama untuk backward compatibility
    function cleanFolderName($string) {
        if (empty($string)) return '';
        $string = preg_replace('/[^A-Za-z0-9\s\-]/', '', $string);
        $string = preg_replace('/\s+/', '_', $string);
        return $string;
    }
    
    $folder_name = cleanFolderName($data['nama_kecamatan'] ?? '') . ',' . 
                   cleanFolderName($data['nama_desa'] ?? '') . ',' . 
                   $data['id_user'] . ',' . 
                   $data['nik_pemohon'] . cleanFolderName($data['nama_pemohon'] ?? '');
    
    return $folder_name;
}

// Fungsi helper untuk format ukuran file
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return $bytes . ' byte';
    } else {
        return '0 bytes';
    }
}

// === Tentukan kecamatan tugas operator ===
$allowed_kecamatan_ids = $_SESSION['kecamatan_tugas'] ?? [];
$allowed_kecamatan_names = $_SESSION['kecamatan_nama'] ?? [];

if (empty($allowed_kecamatan_ids)) {
    error_log("Operator has no kecamatan assigned! Loading all kecamatan...");
    $all_kecamatan_names = [];
    $all_kecamatan_ids = [];
    
    $kec_query = $koneksi->query("SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
    if ($kec_query) {
        while ($row = $kec_query->fetch_assoc()) {
            $all_kecamatan_ids[] = $row['id'];
            $all_kecamatan_names[] = $row['nama_kecamatan'];
        }
        error_log("Loaded all kecamatan: " . count($all_kecamatan_names));
    }
    $allowed_kecamatan_ids = $all_kecamatan_ids;
    $allowed_kecamatan_names = $all_kecamatan_names;
} else {
    error_log("Operator has kecamatan assigned: " . count($allowed_kecamatan_names));
}

if (isset($_GET['komentar_poll']) && $_GET['komentar_poll'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $poll_id = (int)($_GET['permohonan_id'] ?? 0);
    $poll_table = trim((string)($_GET['table'] ?? ''));
    $poll_valid_tables = ['tb_kartukeluarga', 'tb_akta_kelahiran', 'tb_akta_kematian', 'tb_pindah_alamat', 'tb_pindah_online', 'tb_pengajuan_kia'];
    if ($poll_id <= 0 || !in_array($poll_table, $poll_valid_tables, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Data permohonan tidak valid.']);
        exit;
    }

    $sqlPollKec = ($poll_table === 'tb_pengajuan_kia')
        ? "SELECT kc.nama_kecamatan FROM tb_pengajuan_kia t LEFT JOIN tb_kecamatan kc ON kc.id = t.id_kecamatan WHERE t.id = ? LIMIT 1"
        : "SELECT nama_kecamatan FROM `$poll_table` WHERE id = ? LIMIT 1";
    $stmtPoll = $koneksi->prepare($sqlPollKec);
    if (!$stmtPoll) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal memeriksa permohonan.']);
        exit;
    }
    $stmtPoll->bind_param('i', $poll_id);
    $stmtPoll->execute();
    $pollPermohonan = $stmtPoll->get_result()->fetch_assoc();
    $stmtPoll->close();

    $pollKecamatan = trim((string)($pollPermohonan['nama_kecamatan'] ?? ''));
    if ($pollPermohonan === null || !in_array($pollKecamatan, $allowed_kecamatan_names, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Anda tidak memiliki akses ke permohonan ini.']);
        exit;
    }

    $pollComments = komentar_permohonan_list($koneksi, $poll_table, $poll_id);
    $pollLastComment = !empty($pollComments) ? end($pollComments) : null;
    echo json_encode([
        'success' => true,
        'komentar' => $pollComments,
        'last_comment_id' => (int)($pollLastComment['id'] ?? 0),
    ]);
    exit;
}

// === PROSES UPDATE STATUS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'reply_komentar_permohonan') {
        $permohonan_id = (int)($_POST['permohonan_id'] ?? 0);
        $table = trim((string)($_POST['table'] ?? ''));
        $komentar = (string)($_POST['komentar'] ?? '');
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $valid_tables = ['tb_kartukeluarga', 'tb_akta_kelahiran', 'tb_akta_kematian', 'tb_pindah_alamat', 'tb_pindah_online', 'tb_pengajuan_kia'];
        $redirect_detail = 'proses_pengajuan.php';

        if ($permohonan_id > 0 && in_array($table, $valid_tables, true)) {
            $redirect_detail .= '?detail_id=' . rawurlencode((string)$permohonan_id) . '&table=' . rawurlencode($table);
        }

        if ($permohonan_id <= 0 || !in_array($table, $valid_tables, true)) {
            $_SESSION['error_message'] = 'Data permohonan untuk balasan komentar tidak valid.';
            header('Location: ' . $redirect_detail);
            exit;
        }

        // Pastikan permohonan masih berada dalam wilayah tugas operator.
        $sqlPermKec = ($table === 'tb_pengajuan_kia')
            ? "SELECT t.id_user, kc.nama_kecamatan FROM tb_pengajuan_kia t LEFT JOIN tb_kecamatan kc ON kc.id = t.id_kecamatan WHERE t.id = ? LIMIT 1"
            : "SELECT id_user, nama_kecamatan FROM `$table` WHERE id = ? LIMIT 1";
        $stmtPermohonan = $koneksi->prepare($sqlPermKec);
        if (!$stmtPermohonan) {
            $_SESSION['error_message'] = 'Gagal memeriksa data permohonan.';
            header('Location: ' . $redirect_detail);
            exit;
        }
        $stmtPermohonan->bind_param('i', $permohonan_id);
        $stmtPermohonan->execute();
        $permohonan = $stmtPermohonan->get_result()->fetch_assoc();
        $stmtPermohonan->close();

        $nama_kecamatan_permohonan = trim((string)($permohonan['nama_kecamatan'] ?? ''));
        $operator_boleh_mengakses = $permohonan !== null
            && !empty($allowed_kecamatan_names)
            && in_array($nama_kecamatan_permohonan, $allowed_kecamatan_names, true);

        if (!$operator_boleh_mengakses) {
            $_SESSION['error_message'] = 'Anda tidak memiliki akses ke permohonan ini.';
            header('Location: proses_pengajuan.php');
            exit;
        }

        $reply_error = null;
        $reply_id = komentar_permohonan_create(
            $koneksi,
            $table,
            $permohonan_id,
            (int)($_SESSION['id_user'] ?? 0),
            'operator',
            $komentar,
            $reply_error,
            $parent_id > 0 ? $parent_id : null
        );

        if ($reply_id === null) {
            $_SESSION['error_message'] = $reply_error ?: 'Balasan komentar gagal dikirim.';
            header('Location: ' . $redirect_detail);
            exit;
        }

        $user_id_pemohon = (int)($permohonan['id_user'] ?? 0);
        if ($user_id_pemohon > 0 && function_exists('buatNotifikasiBalasanKomentarOperator')) {
            buatNotifikasiBalasanKomentarOperator($koneksi, $user_id_pemohon, $table, $permohonan_id);
        }

        $_SESSION['success_message'] = 'Balasan komentar berhasil dikirim.';
        header('Location: ' . $redirect_detail);
        exit;
    }

    if ($_POST['action'] === 'kia_balas_laporan_kesalahan') {
        require_once __DIR__ . '/../../app/Models/KiaModel.php';
        require_once __DIR__ . '/../../app/Controllers/KiaController.php';
        $idLaporan = (int)($_POST['id_laporan'] ?? 0);
        $idPengajuan = (int)($_POST['id_pengajuan'] ?? 0);
        $idAnakLap = (int)($_POST['id_anak'] ?? 0);
        $isiPesan = trim((string)($_POST['isi_pesan'] ?? ''));
        $statusLaporan = trim((string)($_POST['status_laporan'] ?? ''));
        $redirectLap = 'proses_pengajuan.php?detail_id=' . $idPengajuan . '&table=tb_pengajuan_kia#kiaLaporanKesalahanPanel';

        // Validasi wilayah tugas operator terhadap pengajuan KIA terkait
        $stmtKecLap = $koneksi->prepare(
            "SELECT kc.nama_kecamatan
             FROM tb_pengajuan_kia t
             LEFT JOIN tb_kecamatan kc ON kc.id = t.id_kecamatan
             WHERE t.id = ? LIMIT 1"
        );
        $kecPermLap = '';
        if ($stmtKecLap) {
            $stmtKecLap->bind_param('i', $idPengajuan);
            $stmtKecLap->execute();
            $rowKecLap = $stmtKecLap->get_result()->fetch_assoc();
            $stmtKecLap->close();
            $kecPermLap = trim((string)($rowKecLap['nama_kecamatan'] ?? ''));
        }
        $bolehLapOp = $kecPermLap !== '' && !empty($allowed_kecamatan_names) && in_array($kecPermLap, $allowed_kecamatan_names, true);
        if (!$bolehLapOp && ($_SESSION['role'] ?? '') !== 'admin') {
            $_SESSION['error_message'] = 'Anda tidak memiliki hak akses untuk menangani laporan pengajuan KIA ini.';
            header('Location: proses_pengajuan.php');
            exit;
        }

        $actorLap = [
            'id' => (int)($_SESSION['id_user'] ?? 0),
            'role' => $_SESSION['role'] ?? 'operator',
            'nama' => $_SESSION['nama'] ?? 'Operator',
        ];
        $kiaCtrlLap = new \Siprima\Controllers\KiaController(new \Siprima\Models\KiaModel($koneksi), $koneksi);

        $pdfUploadedOk = false;
        if (!empty($_FILES['pdf_pengganti_anak']['name']) && (int)($_FILES['pdf_pengganti_anak']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadPayload = ['id_pengajuan' => $idPengajuan];
            if ($idAnakLap > 0) {
                $uploadPayload['id_anak'] = $idAnakLap;
            }
            $upRepl = $kiaCtrlLap->handleUploadPdfHasil('POST', $uploadPayload, ['dokumen_kia_pdf' => $_FILES['pdf_pengganti_anak']], $actorLap);
            if (empty($upRepl['data']['success'])) {
                $_SESSION['error_message'] = $upRepl['data']['error'] ?? 'Gagal mengunggah PDF KIA pengganti.';
                header('Location: ' . $redirectLap);
                exit;
            }
            $pdfUploadedOk = true;
            if ($isiPesan === '') {
                $isiPesan = 'Dokumen PDF KIA pengganti versi terbaru telah diunggah oleh petugas.';
            }
        }

        if ($isiPesan !== '' || $statusLaporan !== '') {
            $resLap = $kiaCtrlLap->handleBalasLaporanKesalahan('POST', [
                'id_laporan' => $idLaporan,
                'isi_pesan' => $isiPesan,
                'status_laporan' => $statusLaporan,
            ], $actorLap);
            if (!empty($resLap['data']['success'])) {
                $_SESSION['success_message'] = $pdfUploadedOk
                    ? 'PDF KIA pengganti diunggah dan tanggapan laporan berhasil disimpan (status permohonan utama tetap Selesai).'
                    : 'Tanggapan & status laporan kesalahan berhasil diperbarui.';
            } else {
                $_SESSION['error_message'] = $resLap['data']['error'] ?? 'Gagal memperbarui laporan kesalahan.';
            }
        } elseif ($pdfUploadedOk) {
            $_SESSION['success_message'] = 'PDF KIA pengganti versi terbaru berhasil diunggah untuk anak terkait.';
        } else {
            $_SESSION['error_message'] = 'Isi tanggapan, ubah status laporan, atau pilih file PDF pengganti.';
        }

        header('Location: ' . $redirectLap);
        exit;
    }

    if ($_POST['action'] === 'delete_dokumen_hasil') {
        if (!dokumen_hasil_is_enabled()) {
            $_SESSION['error_message'] = 'Fitur dokumen hasil sedang dinonaktifkan.';
            header('Location: proses_pengajuan.php');
            exit;
        }

        $dokumen_id = (int)($_POST['dokumen_id'] ?? 0);
        $permohonan_id = (int)($_POST['permohonan_id'] ?? 0);
        $table = trim($_POST['table'] ?? '');

        $valid_tables = ['tb_kartukeluarga', 'tb_akta_kelahiran', 'tb_akta_kematian', 'tb_pindah_alamat', 'tb_pindah_online', 'tb_pengajuan_kia'];
        if ($dokumen_id <= 0 || $permohonan_id <= 0 || !in_array($table, $valid_tables, true)) {
            $_SESSION['error_message'] = 'Data dokumen hasil tidak valid.';
            header('Location: proses_pengajuan.php');
            exit;
        }

        if ($table === 'tb_pengajuan_kia') {
            $stmtDelDok = $koneksi->prepare("UPDATE tb_dokumen_kia SET status_dokumen = 'deleted' WHERE id = ? AND id_pengajuan = ?");
            if ($stmtDelDok) {
                $stmtDelDok->bind_param('ii', $dokumen_id, $permohonan_id);
                $stmtDelDok->execute();
                $stmtDelDok->close();
            }
            $stmtCekSisa = $koneksi->prepare("SELECT file_path FROM tb_dokumen_kia WHERE id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf' AND status_dokumen = 'valid' ORDER BY id DESC LIMIT 1");
            $sisaPath = null;
            if ($stmtCekSisa) {
                $stmtCekSisa->bind_param('i', $permohonan_id);
                $stmtCekSisa->execute();
                $sisaRow = $stmtCekSisa->get_result()->fetch_assoc();
                $sisaPath = $sisaRow['file_path'] ?? null;
                $stmtCekSisa->close();
            }
            $stmtClearP = $koneksi->prepare("UPDATE tb_pengajuan_kia SET dokumen_kia_pdf = ?, updated_at = NOW() WHERE id = ?");
            if ($stmtClearP) {
                $stmtClearP->bind_param('si', $sisaPath, $permohonan_id);
                $stmtClearP->execute();
                $stmtClearP->close();
            }
            $_SESSION['success_message'] = 'Dokumen hasil KIA berhasil dihapus.';
            header('Location: proses_pengajuan.php?detail_id=' . rawurlencode((string)$permohonan_id) . '&table=' . rawurlencode($table));
            exit;
        }

        $document = dokumen_hasil_get_by_id($koneksi, $dokumen_id);
        if (
            $document === null ||
            (string)($document['source_table'] ?? '') !== $table ||
            (int)($document['permohonan_id'] ?? 0) !== $permohonan_id
        ) {
            $_SESSION['error_message'] = 'Dokumen hasil tidak ditemukan untuk permohonan ini.';
            header('Location: proses_pengajuan.php?detail_id=' . rawurlencode((string)$permohonan_id) . '&table=' . rawurlencode($table));
            exit;
        }

        $delete_error = null;
        if (dokumen_hasil_delete_by_id($koneksi, $dokumen_id, $delete_error)) {
            $_SESSION['success_message'] = 'Dokumen hasil berhasil dihapus.';
        } else {
            $_SESSION['error_message'] = $delete_error ?: 'Dokumen hasil gagal dihapus.';
        }

        header('Location: proses_pengajuan.php?detail_id=' . rawurlencode((string)$permohonan_id) . '&table=' . rawurlencode($table));
        exit;
    }

    if (in_array($_POST['action'], ['kia_koreksi_anak', 'kia_koreksi_mapping', 'kia_upload_pdf', 'kia_update_cetak'], true)) {
        require_once __DIR__ . '/../../app/Models/KiaModel.php';
        require_once __DIR__ . '/../../app/Controllers/KiaController.php';
        $kiaModelOp = new \Siprima\Models\KiaModel($koneksi);
        $kiaCtrlOp = new \Siprima\Controllers\KiaController($kiaModelOp, $koneksi);
        $prevRowKia = $kiaModelOp->getPengajuanById($kiaId);
        $prevStatusKia = strtolower((string)($prevRowKia['pengajuan']['status'] ?? ''));
        $actorOp = [
            'id' => (int)($_SESSION['id_user'] ?? 0),
            'role' => (string)($_SESSION['role'] ?? 'operator'),
            'nama' => (string)($_SESSION['nama'] ?? 'Operator')
        ];
        $kiaId = (int)($_POST['id_pengajuan'] ?? $_POST['id'] ?? 0);
        $subAction = match($_POST['action']) {
            'kia_koreksi_anak' => 'koreksi_anak',
            'kia_koreksi_mapping' => 'koreksi_mapping',
            'kia_upload_pdf' => 'upload_pdf',
            'kia_update_cetak' => 'update_status_cetak',
            default => ''
        };
        $resOp = $kiaCtrlOp->handleRequest('POST', $subAction, $_POST, $_FILES, $actorOp);
        if (!empty($resOp['data']['success'])) {
            $_SESSION['success_message'] = $resOp['data']['message'] ?? 'Perubahan data KIA berhasil disimpan.';
        } else {
            $_SESSION['error_message'] = $resOp['data']['error'] ?? 'Gagal memproses perubahan KIA.';
        }
        header('Location: proses_pengajuan.php?detail_id=' . $kiaId . '&table=tb_pengajuan_kia');
        exit;
    }
    if ($_POST['action'] === 'update_status' && ($_POST['table'] ?? '') === 'tb_pengajuan_kia') {
        require_once __DIR__ . '/../../app/Models/KiaModel.php';
        require_once __DIR__ . '/../../app/Controllers/KiaController.php';
        $isAjaxKia = !empty($_POST['status_ajax']);
        $kiaId = (int)($_POST['id'] ?? $_POST['id_pengajuan'] ?? $_GET['detail_id'] ?? 0);
        $newSt = strtolower(trim((string)($_POST['status'] ?? '')));
        $alasanSt = trim((string)($_POST['alasan'] ?? ''));
        $actorOp = [
            'id' => (int)($_SESSION['id_user'] ?? 0),
            'role' => (string)($_SESSION['role'] ?? 'operator'),
            'nama' => (string)($_SESSION['nama'] ?? 'Operator')
        ];
        $kiaModelOp = new \Siprima\Models\KiaModel($koneksi);
        $kiaCtrlOp = new \Siprima\Controllers\KiaController($kiaModelOp, $koneksi);
        $prevRowKia = $kiaModelOp->getPengajuanById($kiaId);
        $prevStatusKia = strtolower((string)($prevRowKia['pengajuan']['status'] ?? ''));

        $filesUploadedFlag = false;
        $uploadErrMsg = null;
        $hasNewPdfUploaded = false;
        $uploadSucceeded = false;
        $uploadErrors = [];

        // 1. Deteksi berkas PDF per anak (dokumen_kia_pdf_anak[id_anak])
        if (!empty($_FILES['dokumen_kia_pdf_anak']['name']) && is_array($_FILES['dokumen_kia_pdf_anak']['name'])) {
            foreach ($_FILES['dokumen_kia_pdf_anak']['name'] as $anakIdKey => $origNameItem) {
                $errItem = $_FILES['dokumen_kia_pdf_anak']['error'][$anakIdKey] ?? UPLOAD_ERR_NO_FILE;
                if ($errItem === UPLOAD_ERR_NO_FILE || trim((string)$origNameItem) === '') {
                    continue;
                }
                $hasNewPdfUploaded = true;
                $singleFilePayload = [
                    'dokumen_kia_pdf' => [
                        'name' => $origNameItem,
                        'type' => $_FILES['dokumen_kia_pdf_anak']['type'][$anakIdKey] ?? 'application/pdf',
                        'tmp_name' => $_FILES['dokumen_kia_pdf_anak']['tmp_name'][$anakIdKey] ?? '',
                        'error' => $errItem,
                        'size' => $_FILES['dokumen_kia_pdf_anak']['size'][$anakIdKey] ?? 0,
                    ]
                ];
                $upRes = $kiaCtrlOp->handleUploadPdfHasil(
                    'POST',
                    ['id_pengajuan' => $kiaId, 'id_anak' => (int)$anakIdKey],
                    $singleFilePayload,
                    $actorOp
                );
                if (!empty($upRes['data']['success'])) {
                    $uploadSucceeded = true;
                    $filesUploadedFlag = true;
                } else {
                    $uploadErrors[] = $upRes['data']['error'] ?? ('Gagal mengunggah PDF untuk anak #' . (int)$anakIdKey . '.');
                }
            }
        }

        // 2. Fallback berkas PDF tunggal (legacy dokumen_kia_pdf / dokumen_hasil)
        $filePdfToUpload = null;
        if (!$hasNewPdfUploaded && !empty($_FILES['dokumen_kia_pdf']['name']) && ($_FILES['dokumen_kia_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $filePdfToUpload = $_FILES;
            $hasNewPdfUploaded = true;
        } elseif (!$hasNewPdfUploaded && !empty($_FILES['dokumen_hasil']['name'][0]) && ($_FILES['dokumen_hasil']['error'][0] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $filePdfToUpload = [
                'dokumen_kia_pdf' => [
                    'name' => $_FILES['dokumen_hasil']['name'][0],
                    'type' => $_FILES['dokumen_hasil']['type'][0] ?? 'application/pdf',
                    'tmp_name' => $_FILES['dokumen_hasil']['tmp_name'][0],
                    'error' => $_FILES['dokumen_hasil']['error'][0] ?? 0,
                    'size' => $_FILES['dokumen_hasil']['size'][0] ?? 0,
                ]
            ];
            $hasNewPdfUploaded = true;
        }

        if ($filePdfToUpload !== null) {
            $upRes = $kiaCtrlOp->handleUploadPdfHasil('POST', ['id_pengajuan' => $kiaId], $filePdfToUpload, $actorOp);
            if (!empty($upRes['data']['success'])) {
                $uploadSucceeded = true;
                $filesUploadedFlag = true;
            } else {
                $uploadErrors[] = $upRes['data']['error'] ?? 'Gagal mengunggah berkas PDF Hasil KIA.';
            }
        }

        if (!empty($uploadErrors)) {
            $uploadErrMsg = implode(' ', $uploadErrors);
        }

        if ($newSt === 'selesai') {
            // Jika ada berkas PDF yang dipilih namun proses upload gagal: batalkan perubahan status! Status harus tetap Diproses.
            if ($hasNewPdfUploaded && !empty($uploadErrors)) {
                $failMsg = 'Perubahan status dibatalkan: Gagal mengunggah berkas PDF Hasil KIA (' . ($uploadErrMsg ?? 'Format atau ukuran tidak sesuai') . '). Status permohonan tetap Diproses.';
                if ($isAjaxKia) {
                    http_response_code(422);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => $failMsg], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $_SESSION['error_message'] = $failMsg;
                header('Location: proses_pengajuan.php?detail_id=' . $kiaId . '&table=tb_pengajuan_kia');
                exit;
            }

            // Validasi kelengkapan PDF sesuai jumlah anak dalam permohonan
            $kiaModelOpCheck = new \Siprima\Models\KiaModel($koneksi);
            $pdfCompleteness = $kiaModelOpCheck->getHasilPdfStatusByPengajuan($kiaId);

            if (empty($pdfCompleteness['complete'])) {
                $missingList = !empty($pdfCompleteness['missing_names'])
                    ? ' Belum diunggah: ' . implode(', ', $pdfCompleteness['missing_names']) . '.'
                    : '';
                $errMsgNoPdf = 'Gagal menyelesaikan permohonan: Dokumen PDF KIA wajib lengkap untuk seluruh anak (' . (int)$pdfCompleteness['uploaded_count'] . ' dari ' . (int)$pdfCompleteness['total_anak'] . ' anak).' . $missingList . ' Status permohonan tetap Diproses.';
                if ($isAjaxKia) {
                    http_response_code(422);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => $errMsgNoPdf], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $_SESSION['error_message'] = $errMsgNoPdf;
                header('Location: proses_pengajuan.php?detail_id=' . $kiaId . '&table=tb_pengajuan_kia');
                exit;
            }
        } else {
            // Jika status bukan selesai, namun operator mengunggah PDF dan uploadnya gagal
            if ($hasNewPdfUploaded && !$uploadSucceeded) {
                if ($isAjaxKia) {
                    http_response_code(422);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => $uploadErrMsg ?? 'Gagal mengunggah berkas PDF KIA.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $_SESSION['error_message'] = $uploadErrMsg ?? 'Gagal mengunggah berkas PDF KIA.';
                header('Location: proses_pengajuan.php?detail_id=' . $kiaId . '&table=tb_pengajuan_kia');
                exit;
            }
        }

        if ($prevStatusKia === 'selesai' && $newSt === 'selesai' && $filesUploadedFlag) {
            $resSt = [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Berkas PDF hasil KIA berhasil diperbarui (status permohonan tetap Selesai).'
                ]
            ];
        } else {
            $resSt = $kiaCtrlOp->handleUpdateStatus('POST', [
                'id' => $kiaId,
                'status' => $newSt,
                'alasan' => $alasanSt,
                'require_pdf' => ($newSt === 'selesai') ? 1 : 0,
            ], $actorOp);
        }

        $okSt = !empty($resSt['data']['success']);
        $msgSt = $okSt
            ? ($resSt['data']['message'] ?? ('Status berhasil diubah menjadi ' . ucfirst($newSt) . '.'))
            : ($resSt['data']['error'] ?? 'Gagal mengubah status pengajuan KIA.');

        if ($isAjaxKia) {
            $updatedHistory = serviceTrackingHistory($koneksi, 'tb_pengajuan_kia', $kiaId, true);
            http_response_code($okSt ? 200 : 422);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'success' => $okSt,
                'status' => $newSt,
                'message' => $msgSt,
                'files_uploaded' => $filesUploadedFlag,
                'history_html' => serviceTrackingHtml($updatedHistory, true, $newSt),
                'history_version' => $updatedHistory ? max(array_column($updatedHistory, 'id')) : 0,
                'updated_at' => $updatedHistory ? end($updatedHistory)['created_at_formatted'] : date('d M Y H:i'),
                'status_note' => $alasanSt,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        if ($okSt) {
            $_SESSION['success_message'] = $msgSt;
        } else {
            $_SESSION['error_message'] = $msgSt;
        }
        header('Location: proses_pengajuan.php?detail_id=' . $kiaId . '&table=tb_pengajuan_kia');
        exit;
    }
    if ($_POST['action'] === 'update_status') {
        serviceTrackingUpdate($koneksi, ['tb_kartukeluarga', 'tb_akta_kelahiran', 'tb_akta_kematian', 'tb_pindah_alamat', 'tb_pindah_online'], static fn(array $row): bool => in_array(trim((string)($row['nama_kecamatan'] ?? '')), $allowed_kecamatan_names, true));
    }
}

// === AMBIL DETAIL JIKA ADA PARAMETER ID ===
$detail_data = null;
$detail_files = [];
$detail_table = '';
$detail_folder_name = '';
$detail_alasan_history = [];
$detail_dokumen_hasil = [];
$detail_komentar_permohonan = [];
$table_upload_folders = [
    'tb_kartukeluarga' => 'kk',
    'tb_akta_kelahiran' => 'akta_kelahiran',
    'tb_akta_kematian' => 'akta_kematian',
    'tb_pindah_alamat' => 'pindah',
    'tb_pindah_online' => 'pindah_online'
];

if (isset($_GET['detail_id']) && isset($_GET['table'])) {
    $detail_id = (int)$_GET['detail_id'];
    $detail_table = $_GET['table'];
    
    $valid_tables = ['tb_kartukeluarga', 'tb_akta_kelahiran', 'tb_akta_kematian', 'tb_pindah_alamat', 'tb_pindah_online', 'tb_pengajuan_kia'];
    
    if (in_array($detail_table, $valid_tables)) {
        $exclude_rs_puskes = in_array($detail_table, ['tb_akta_kelahiran', 'tb_akta_kematian']);
        $created_expr = $table_created_expr[$detail_table] ?? 'created_at';
        $query = ($detail_table === 'tb_pengajuan_kia')
            ? "SELECT t.*, LOWER(t.status) AS status, t.keterangan_permohonan AS keterangan, t.alasan_penolakan AS alasan, kc.nama_kecamatan, ds.nama_desa, {$created_expr} AS effective_created_at FROM `tb_pengajuan_kia` t LEFT JOIN tb_kecamatan kc ON kc.id = t.id_kecamatan LEFT JOIN tb_desa ds ON ds.id = t.id_desa WHERE t.id = ?"
            : "SELECT *, {$created_expr} AS effective_created_at FROM `$detail_table` WHERE id = ?";
        
        if (!empty($allowed_kecamatan_names) && count($allowed_kecamatan_names) < 14) {
            $colKecDetail = ($detail_table === 'tb_pengajuan_kia') ? 'kc.nama_kecamatan' : 'nama_kecamatan';
            $query .= " AND $colKecDetail IN ('" . implode("','", $allowed_kecamatan_names) . "')";
        }
        
        $stmt = $koneksi->prepare($query);
        if ($stmt) {
            $stmt->bind_param('i', $detail_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $detail_data = $result->fetch_assoc();
            
            if ($detail_data) {
                $detail_alasan_history = getPermohonanRejectionHistory($koneksi, $detail_table, $detail_id, 15);
                $detail_dokumen_hasil = dokumen_hasil_list_for_permohonan($koneksi, $detail_table, $detail_id);
                $detail_komentar_permohonan = komentar_permohonan_list($koneksi, $detail_table, $detail_id);

                // Dapatkan nama folder untuk pembuatan path dokumen
                $detail_folder_name = getFolderNameFromPermohonan($koneksi, $detail_table, $detail_id);
                
                // Ambil data dokumen dengan format JSON
                if (!empty($detail_data['dokumen'])) {
                    $dokumen_data = json_decode($detail_data['dokumen'], true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && is_array($dokumen_data)) {
                        $detail_files = [];
                        
                        foreach ($dokumen_data as $file) {
                            if (isset($file['path'])) {
                                $file_path = (string)$file['path'];
                                $found_path = '';
                                $upload_subfolder = $table_upload_folders[$detail_table] ?? str_replace('tb_', '', $detail_table);

                                // Normalisasi format path dari DB (supports uploads/... dan user/uploads/...)
                                $normalized_path = str_replace('\\', '/', trim($file_path));
                                $normalized_path = preg_replace('#^(\./)+#', '', $normalized_path);
                                $normalized_path = ltrim($normalized_path, '/');

                                $possible_paths = [$normalized_path];
                                if (strpos($normalized_path, 'user/') === 0) {
                                    $possible_paths[] = substr($normalized_path, 5);
                                }
                                if (strpos($normalized_path, 'public/user/') === 0) {
                                    $possible_paths[] = substr($normalized_path, 12);
                                }
                                if (strpos($normalized_path, 'public/') === 0) {
                                    $possible_paths[] = substr($normalized_path, 7);
                                }

                                // Fallback jika path di DB hanya nama file
                                if ($detail_folder_name) {
                                    $filename = basename($normalized_path);
                                    $possible_paths[] = "uploads/{$upload_subfolder}/{$detail_folder_name}/{$filename}";
                                    $possible_paths[] = "uploads/{$upload_subfolder}/{$detail_folder_name}/dokumen_pendukung/{$filename}";
                                }

                                $possible_paths = array_values(array_unique(array_filter($possible_paths)));
                                $search_roots = [__DIR__, dirname(__DIR__), dirname(__DIR__, 2)];

                                foreach ($possible_paths as $possible_path) {
                                    foreach ($search_roots as $root) {
                                        $full_path = rtrim($root, '/\\') . '/' . ltrim($possible_path, '/');
                                        if (is_file($full_path)) {
                                            $found_path = $possible_path;
                                            break 2;
                                        }
                                    }
                                }

                                $browser_path = $found_path !== '' ? $found_path : $normalized_path;
                                if (strpos($browser_path, 'user/uploads/') === 0) {
                                    $browser_path = substr($browser_path, 5);
                                } elseif (strpos($browser_path, 'public/user/uploads/') === 0) {
                                    $browser_path = substr($browser_path, 12);
                                } elseif (strpos($browser_path, 'public/uploads/') === 0) {
                                    $browser_path = substr($browser_path, 7);
                                }

                                $document_url = siprima_document_token_url(__DIR__, $browser_path)
                                    ?? siprima_document_token_url(dirname(__DIR__), $browser_path);
                                $detail_files[] = [
                                    'path' => $document_url ?? '',
                                    'download_url' => $document_url === null ? '' : $document_url . '&download=1',
                                    'type' => $file['type'] ?? 'lainnya',
                                    'original_name' => $file['original_name'] ?? basename($file_path),
                                    'size' => $file['size'] ?? null,
                                    // Ditandai agar operator tahu dokumen mana yang dibuat sistem
                                    // dan karenanya tanda tangannya perlu diverifikasi.
                                    'generated' => !empty($file['generated'])
                                ];
                            }
                        }
                    } else {
                        // Jika bukan JSON, anggap sebagai single file path
                        $document_url = siprima_document_token_url(__DIR__, (string)$detail_data['dokumen'])
                            ?? siprima_document_token_url(dirname(__DIR__), (string)$detail_data['dokumen']);
                        $detail_files = [[
                            'path' => $document_url ?? '',
                            'download_url' => $document_url === null ? '' : $document_url . '&download=1',
                            'type' => 'dokumen',
                            'original_name' => basename($detail_data['dokumen'])
                        ]];
                    }
                }
                
                // Ambil data user pemohon
                $detail_superseded_files = [];
                if ($detail_table === 'tb_pengajuan_kia') {
                    require_once __DIR__ . '/../../app/Models/KiaModel.php';
                    $kiaModelDetailOp = new \Siprima\Models\KiaModel($koneksi);
                    $detail_data['cetak_kia'] = $kiaModelDetailOp->getCetakKiaByPengajuan($detail_id);
                    $detail_data['pengaturan_cetak'] = $kiaModelDetailOp->getPengaturanCetakKia();

                    $anakStmtOp = $koneksi->prepare("SELECT * FROM tb_anak_kia WHERE id_pengajuan = ? ORDER BY urutan_anak ASC, id ASC");
                    $detail_data['anak_list'] = [];
                    if ($anakStmtOp) {
                        $anakStmtOp->bind_param('i', $detail_id);
                        $anakStmtOp->execute();
                        $rawAnakRows = $anakStmtOp->get_result()->fetch_all(MYSQLI_ASSOC);
                        $anakStmtOp->close();
                        $todayOp = new DateTime('today');
                        foreach ($rawAnakRows as $akRow) {
                            $tglStr = trim((string)($akRow['tanggal_lahir'] ?? ''));
                            $akRow['umur_tahun'] = 0;
                            $akRow['umur_bulan'] = 0;
                            $akRow['umur_teks'] = '-';
                            if ($tglStr !== '') {
                                try {
                                    $tglObj = new DateTime($tglStr);
                                    if ($tglObj <= $todayOp) {
                                        $diff = $tglObj->diff($todayOp);
                                        $akRow['umur_tahun'] = $diff->y;
                                        $akRow['umur_bulan'] = $diff->m;
                                        if ($diff->y > 0) {
                                            $akRow['umur_teks'] = $diff->y . ' Tahun' . ($diff->m > 0 ? ' ' . $diff->m . ' Bulan' : '');
                                        } elseif ($diff->m > 0) {
                                            $akRow['umur_teks'] = $diff->m . ' Bulan' . ($diff->d > 0 ? ' ' . $diff->d . ' Hari' : '');
                                        } else {
                                            $akRow['umur_teks'] = max(0, $diff->d) . ' Hari';
                                        }
                                    } else {
                                        $akRow['umur_teks'] = 'Tanggal lahir di masa depan';
                                    }
                                } catch (\Throwable $e) {
                                    $akRow['umur_teks'] = '-';
                                }
                            }
                            $detail_data['anak_list'][] = $akRow;
                        }
                    }

                    $dokStmtOp = $koneksi->prepare("SELECT d.*, a.nama_anak, a.urutan_anak FROM tb_dokumen_kia d LEFT JOIN tb_anak_kia a ON d.id_anak = a.id WHERE d.id_pengajuan = ? ORDER BY d.versi DESC, d.id ASC");
                    $detail_files = [];
                    $detail_superseded_files = [];
                    $detail_data['dokumen_kia_rows'] = [];
                    $seenActiveDocs = [];
                    $seenActivePasfotoPerAnak = [];
                    if ($dokStmtOp) {
                        $dokStmtOp->bind_param('i', $detail_id);
                        $dokStmtOp->execute();
                        $dokRowsOp = $dokStmtOp->get_result()->fetch_all(MYSQLI_ASSOC);
                        $dokStmtOp->close();
                        $detail_data['dokumen_kia_rows'] = $dokRowsOp;
                        foreach ($dokRowsOp as $dRow) {
                            $fPath = str_replace('\\', '/', ltrim((string)($dRow['file_path'] ?? ''), '/\\'));
                            $absCandidate = dirname(__DIR__) . '/' . $fPath;
                            $fileExists = ($fPath !== '') && is_file($absCandidate) && is_readable($absCandidate);
                            if (!$fileExists) {
                                error_log("KIA Operator Detail: File fisik tidak ditemukan untuk id_dokumen=" . (int)($dRow['id'] ?? 0) . " path={$absCandidate}");
                            }
                            $extDok = strtolower(pathinfo($fPath, PATHINFO_EXTENSION));
                            if ($extDok === '') {
                                $mimeDok = strtolower((string)($dRow['mime_type'] ?? ''));
                                $extDok = str_contains($mimeDok, 'png') ? 'png' : (str_contains($mimeDok, 'pdf') ? 'pdf' : 'jpg');
                            }
                            $viewDokUrl = 'kia.php?action=view_dokumen&id_dokumen=' . (int)$dRow['id'] . '&ext=.' . $extDok;
                            $dlDokUrl = 'kia.php?action=view_dokumen&id_dokumen=' . (int)$dRow['id'] . '&download=1&ext=.' . $extDok;
                            $origName = (string)($dRow['original_name'] ?? $dRow['nama_file'] ?? basename($fPath));
                            if (($dRow['jenis_dokumen'] ?? '') === 'hasil_kia_pdf') {
                                if (strtolower((string)($dRow['status_dokumen'] ?? 'valid')) === 'valid') {
                                    $detail_dokumen_hasil[] = [
                                        'id' => (int)$dRow['id'],
                                        'original_name' => $origName,
                                        'file_size' => (int)($dRow['file_size'] ?? 0),
                                        'uploaded_at' => $dRow['uploaded_at'] ?? '',
                                        'file_exists' => $fileExists,
                                        'preview_url' => $viewDokUrl,
                                        'download_url' => 'kia.php?action=download_pdf&id=' . (int)$detail_id
                                    ];
                                }
                                continue;
                            }
                            $anakSuffix = !empty($dRow['nama_anak']) ? ' (' . $dRow['nama_anak'] . ')' : '';
                            $anakLabel = !empty($dRow['nama_anak'])
                                ? ('Anak ke-' . (int)($dRow['urutan_anak'] ?: 1) . ': ' . (string)$dRow['nama_anak'])
                                : 'Dokumen Pemohon / Keluarga';
                            $versiDok = (int)($dRow['versi'] ?? 1);
                            $stDok = strtolower((string)($dRow['status_dokumen'] ?? 'valid'));
                            $docEntry = [
                                'id_dokumen' => (int)$dRow['id'],
                                'id_anak' => $dRow['id_anak'] ? (int)$dRow['id_anak'] : null,
                                'anak_label' => $anakLabel,
                                'path' => $viewDokUrl,
                                'download_url' => $dlDokUrl,
                                'ext' => $extDok,
                                'file_exists' => $fileExists,
                                'type' => (string)($dRow['jenis_dokumen'] ?? 'lainnya'),
                                'original_name' => $origName . $anakSuffix,
                                'size' => (int)($dRow['file_size'] ?? 0),
                                'versi' => $versiDok,
                                'status_dokumen' => $stDok,
                                'uploaded_at' => $dRow['uploaded_at'] ?? '',
                                'generated' => false
                            ];
                            if ($stDok === 'superseded') {
                                $detail_superseded_files[] = $docEntry;
                            } else {
                                // Deduplikasi tampilan: pastikan satu pasfoto aktif hanya muncul sekali per anak
                                $isPasfoto = ($dRow['jenis_dokumen'] ?? '') === 'pasfoto_anak';
                                $dedupKey = ($dRow['jenis_dokumen'] ?? '') . '_' . ($dRow['id_anak'] ? (int)$dRow['id_anak'] : 'general');
                                if (isset($seenActiveDocs[$dedupKey])) {
                                    $detail_superseded_files[] = $docEntry;
                                    continue;
                                }
                                if ($isPasfoto && empty($dRow['id_anak']) && !empty($seenActivePasfotoPerAnak)) {
                                    $detail_superseded_files[] = $docEntry;
                                    continue;
                                }
                                $seenActiveDocs[$dedupKey] = true;
                                if ($isPasfoto && !empty($dRow['id_anak'])) {
                                    $seenActivePasfotoPerAnak[(int)$dRow['id_anak']] = true;
                                }
                                $detail_files[] = $docEntry;
                            }
                        }
                    }
                    if (empty($detail_dokumen_hasil) && !empty(trim((string)($detail_data['dokumen_kia_pdf'] ?? '')))) {
                        $fPath = str_replace('\\', '/', ltrim((string)$detail_data['dokumen_kia_pdf'], '/\\'));
                        $absCandidate = dirname(__DIR__) . '/' . $fPath;
                        $fileExists = is_file($absCandidate) && is_readable($absCandidate);
                        $detail_dokumen_hasil[] = [
                            'id' => 0,
                            'original_name' => basename($fPath),
                            'file_size' => $fileExists ? (int)@filesize($absCandidate) : 0,
                            'uploaded_at' => $detail_data['updated_at'] ?? $detail_data['created_at'] ?? '',
                            'file_exists' => $fileExists,
                            'preview_url' => 'kia.php?action=download_pdf&id=' . (int)$detail_id,
                            'download_url' => 'kia.php?action=download_pdf&id=' . (int)$detail_id . '&download=1'
                        ];
                    }
                }
                $user_id = $detail_data['id_user'] ?? 0;
                $user_query = $koneksi->prepare("SELECT nama, nik, no_hp, email FROM tb_users WHERE id = ?");
                if ($user_query) {
                    $user_query->bind_param('i', $user_id);
                    $user_query->execute();
                    $user_result = $user_query->get_result();
                    $detail_data['user_info'] = $user_result->fetch_assoc();
                }
            }
            $stmt->close();
        }
    }
}

// === PAGINATION SETTINGS ===
$per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;

// === TAMPILKAN DATA PENGAJUAN ===
$submissions = [];
$total_submissions = 0;
$total_pages = 0;

if (!isset($_GET['detail_id'])) {
    require_once __DIR__ . '/../../app/Models/KiaModel.php';
    $kiaQueueModel = new \Siprima\Models\KiaModel($koneksi);
    $kiaQueueModel->ensureCetakKiaTables();

    $filter_type = $_GET['type'] ?? '';
    $filter_status = $_GET['status'] ?? '';
    $filter_kategori_antrean = trim((string)($_GET['kategori_antrean'] ?? ''));
    $filter_kecamatan = trim($_GET['kecamatan'] ?? '');
    $search_nama = trim($_GET['search_nama'] ?? '');

    if ($filter_kecamatan !== '' && !in_array($filter_kecamatan, $allowed_kecamatan_names, true)) {
        $filter_kecamatan = '';
    }

    $tables_config = [
        'tb_pengajuan_kia' => [
            'nama_field' => 'no_kk',
            'jenis' => 'KIA',
            'title_field' => 'no_kk',
            'exclude_rs_puskes' => false
        ],
        'tb_kartukeluarga' => [
            'nama_field' => 'nama_kepalaKeluarga',
            'jenis' => 'Kartu Keluarga',
            'title_field' => 'nama_kepalaKeluarga',
            'exclude_rs_puskes' => false
        ],
        'tb_akta_kelahiran' => [
            'nama_field' => 'nama_bayi',
            'jenis' => 'Akta Kelahiran',
            'title_field' => 'nama_bayi',
            'exclude_rs_puskes' => true
        ],
        'tb_akta_kematian' => [
            'nama_field' => 'Nama_meninggal',
            'jenis' => 'Akta Kematian',
            'title_field' => 'Nama_meninggal',
            'exclude_rs_puskes' => true
        ],
        'tb_pindah_alamat' => [
            'nama_field' => 'nama_kepalaKeluarga',
            'jenis' => 'Pindah Alamat',
            'title_field' => 'nama_kepalaKeluarga',
            'exclude_rs_puskes' => false
        ],
        'tb_pindah_online' => [
            'nama_field' => 'nama_kepalaKeluarga',
            'jenis' => 'Pindah Masuk',
            'title_field' => 'nama_kepalaKeluarga',
            'exclude_rs_puskes' => false
        ]
    ];
    
    $all_submissions = [];
    
    foreach ($tables_config as $table => $config) {
        if (!$filter_type || $filter_type === $config['jenis']) {
            $created_expr = $table_created_expr[$table] ?? 't.created_at';
            $has_updated_at = false;
            $column_stmt = $koneksi->prepare("SHOW COLUMNS FROM `{$table}` LIKE 'updated_at'");
            if ($column_stmt) {
                $column_stmt->execute();
                $column_result = $column_stmt->get_result();
                $has_updated_at = $column_result && $column_result->num_rows > 0;
                $column_stmt->close();
            }
            $last_update_select = $has_updated_at ? "t.updated_at" : $created_expr;

            if ($table === 'tb_pengajuan_kia') {
                $query = "SELECT t.id, {$created_expr} AS created_at, {$last_update_select} AS updated_at, LOWER(t.status) AS status, kc.nama_kecamatan, ds.nama_desa, COALESCE((SELECT GROUP_CONCAT(a.nama_anak SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = t.id), t.no_kk) AS title, (SELECT COUNT(*) FROM tb_anak_kia a2 WHERE a2.id_pengajuan = t.id) AS jumlah_anak, t.resubmitted_at, t.id_user, COALESCE(t.versi_aktif, 1) AS versi_aktif, COALESCE(t.kategori_antrian, 'reguler') AS kategori_antrian, (SELECT ck.status_cetak FROM tb_cetak_kia ck WHERE ck.id_pengajuan = t.id ORDER BY ck.id DESC LIMIT 1) AS status_cetak_kia, (SELECT COUNT(*) FROM tb_laporan_kesalahan_kia lk WHERE lk.id_pengajuan = t.id AND lk.status NOT IN ('selesai','selesai_dikoreksi')) AS laporan_aktif_count, '{$table}' AS table_name, '{$config['jenis']}' AS jenis FROM `tb_pengajuan_kia` t LEFT JOIN tb_kecamatan kc ON kc.id = t.id_kecamatan LEFT JOIN tb_desa ds ON ds.id = t.id_desa WHERE 1=1";
            } else {
            $query = "SELECT t.id, {$created_expr} AS created_at, {$last_update_select} AS updated_at, t.status, t.nama_kecamatan, t.nama_desa, 
                             t.{$config['title_field']} AS title, t.id_user,
                             1 AS versi_aktif, 'reguler' AS kategori_antrian, 0 AS jumlah_anak, NULL AS resubmitted_at, NULL AS status_cetak_kia, 0 AS laporan_aktif_count,
                             '{$table}' AS table_name,
                             '{$config['jenis']}' AS jenis
                      FROM `{$table}` t
                      WHERE 1=1";
            }
            
            $where_conditions = [];
            $params = [];
            $types = '';
            
            if ($filter_kecamatan !== '') {
                $colKecList = ($table === 'tb_pengajuan_kia') ? 'kc.nama_kecamatan' : 't.nama_kecamatan';
                $where_conditions[] = "$colKecList = ?";
                $params[] = $filter_kecamatan;
                $types .= 's';
            } elseif (!empty($allowed_kecamatan_names)) {
                if (count($allowed_kecamatan_names) < 14) {
                    $placeholders = implode(',', array_fill(0, count($allowed_kecamatan_names), '?'));
                    $colKecListIn = ($table === 'tb_pengajuan_kia') ? 'kc.nama_kecamatan' : 't.nama_kecamatan';
                    $where_conditions[] = "$colKecListIn IN ($placeholders)";
                    $params = $allowed_kecamatan_names;
                    $types = str_repeat('s', count($allowed_kecamatan_names));
                }
            } else {
                $where_conditions[] = "1 = 0";
            }
            
            if ($filter_status && in_array($filter_status, ['diajukan', 'diproses', 'selesai', 'ditolak'])) {
                $where_conditions[] = "t.status = ?";
                $params[] = $filter_status;
                $types .= 's';
            }

            if ($search_nama !== '') {
                $where_conditions[] = "(
                    t.{$config['title_field']} LIKE ?
                    OR EXISTS (
                        SELECT 1
                        FROM tb_users u
                        WHERE u.id = t.id_user
                          AND u.nama LIKE ?
                    )
                )";
                $like_search_nama = '%' . $search_nama . '%';
                $params[] = $like_search_nama;
                $params[] = $like_search_nama;
                $types .= 'ss';
            }
            
            if ($config['exclude_rs_puskes']) {
                $where_conditions[] = "(t.id_rs IS NULL OR t.id_rs = 0 OR t.id_rs = '') 
                                       AND (t.id_puskes IS NULL OR t.id_puskes = 0 OR t.id_puskes = '')";
            }
            
            if (!empty($where_conditions)) {
                $query .= " AND " . implode(" AND ", $where_conditions);
            }
            
            $stmt = $koneksi->prepare($query);
            if ($stmt) {
                if (!empty($params)) {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    if ($filter_kategori_antrean !== '') {
                        $rowSt = strtolower((string)($row['status'] ?? 'diajukan'));
                        $rowVer = (int)($row['versi_aktif'] ?? 1);
                        $rowKat = strtolower((string)($row['kategori_antrian'] ?? 'reguler'));
                        $rowCetak = (string)($row['status_cetak_kia'] ?? '');
                        $isResub = ($rowVer > 1 || str_contains($rowKat, 'perbaikan'));
                        $matchKat = match ($filter_kategori_antrean) {
                            'pengajuan_baru' => (!$isResub),
                            'pengajuan_perbaikan', 'pengajuan_ulang' => ($isResub),
                            'perlu_verifikasi' => ($rowSt === 'diproses'),
                            'perlu_perbaikan' => ($rowSt === 'ditolak'),
                            'antrean_cetak' => ($rowCetak !== ''),
                            'selesai' => ($rowSt === 'selesai'),
                            default => true
                        };
                        if (!$matchKat) {
                            continue;
                        }
                    }
                    $all_submissions[] = $row;
                }
                $stmt->close();
            }
        }
    }
    
    usort($all_submissions, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    $total_submissions = count($all_submissions);
    $total_pages = ceil($total_submissions / $per_page);
    
    if ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
        $offset = ($current_page - 1) * $per_page;
    }
    
    $submissions = array_slice($all_submissions, $offset, $per_page);
    
    if (!empty($submissions)) {
        foreach ($submissions as &$submission) {
            $user_id = $submission['id_user'];
            $user_query = $koneksi->prepare("SELECT nama FROM tb_users WHERE id = ?");
            if ($user_query) {
                $user_query->bind_param('i', $user_id);
                $user_query->execute();
                $user_result = $user_query->get_result();
                $user_data = $user_result->fetch_assoc();
                $submission['nama_user'] = $user_data['nama'] ?? '-';
                $user_query->close();
            } else {
                $submission['nama_user'] = '-';
            }
        }
    }
    
    error_log("Total submissions: $total_submissions");
}

// Fungsi helper untuk escape output
function esc($string) {
    return htmlspecialchars($string ?? '-', ENT_QUOTES, 'UTF-8');
}

function pickDetailValue(array $data, array $keys, $default = '-') {
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $value = $data[$key];
            if ($value !== null && trim((string)$value) !== '') {
                return $value;
            }
        }
    }
    return $default;
}

// Fungsi untuk menentukan jenis layanan berdasarkan table
function getJenisLayanan($table) {
    return match($table) {
        'tb_kartukeluarga' => 'Kartu Keluarga',
        'tb_akta_kelahiran' => 'Akta Kelahiran',
        'tb_akta_kematian' => 'Akta Kematian',
        'tb_pindah_alamat' => 'Pindah Alamat',
        'tb_pindah_online' => 'Pindah Masuk',
        'tb_pengajuan_kia' => 'KIA',
        default => 'Tidak Diketahui'
    };
}

// Fungsi untuk format tanggal
function formatDate($date) {
    if (empty($date)) return '-';
    return date('d M Y H:i', strtotime($date));
}

// Fungsi untuk menampilkan alasan pindah
function displayAlasanPindah($kode) {
    $alasanList = [
        'kerja' => 'Pekerjaan',
        'pendidikan' => 'Pendidikan',
        'keluarga' => 'Keluarga',
        'rumah' => 'Pindah Rumah',
        'kembali' => 'Kembali ke Kampung Halaman',
        'lainnya' => 'Lainnya'
    ];
    return $alasanList[$kode] ?? $kode;
}

// Flash messages
$flash_success = $_SESSION['success_message'] ?? null;
$flash_error = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Hitung statistik
$stats = [
    'total' => 0,
    'diajukan' => 0,
    'diproses' => 0,
    'selesai' => 0,
    'ditolak' => 0
];

if (!isset($_GET['detail_id'])) {
    $tables_to_count = [
        'tb_kartukeluarga' => ['exclude_rs_puskes' => false],
        'tb_akta_kelahiran' => ['exclude_rs_puskes' => true],
        'tb_akta_kematian' => ['exclude_rs_puskes' => true],
        'tb_pindah_alamat' => ['exclude_rs_puskes' => false],
        'tb_pindah_online' => ['exclude_rs_puskes' => false],
        'tb_pengajuan_kia' => ['exclude_rs_puskes' => false]
    ];
    
    foreach ($tables_to_count as $tbl => $config) {
        try {
            $check = $koneksi->query("SHOW TABLES LIKE '" . $koneksi->real_escape_string($tbl) . "'");
            
            if ($check && $check->num_rows > 0) {
                $query = "SELECT 
                            COUNT(*) AS total,
                            SUM(CASE WHEN status = 'diajukan' THEN 1 ELSE 0 END) AS diajukan,
                            SUM(CASE WHEN status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
                            SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                            SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak
                          FROM `$tbl` t " . ($tbl === 'tb_pengajuan_kia' ? "LEFT JOIN tb_kecamatan kc ON kc.id = t.id_kecamatan " : "") . "WHERE 1=1";
                
                $where_conditions = [];
                $params = [];
                $types = '';
                
                if (!empty($allowed_kecamatan_names) && count($allowed_kecamatan_names) < 14) {
                    $placeholders = implode(',', array_fill(0, count($allowed_kecamatan_names), '?'));
                    $colKecCnt = ($tbl === 'tb_pengajuan_kia') ? 'kc.nama_kecamatan' : 'nama_kecamatan';
                    $where_conditions[] = "$colKecCnt IN ($placeholders)";
                    $params = $allowed_kecamatan_names;
                    $types = str_repeat('s', count($allowed_kecamatan_names));
                } elseif (empty($allowed_kecamatan_names)) {
                    $where_conditions[] = "1 = 0";
                }
                
                if ($config['exclude_rs_puskes']) {
                    $where_conditions[] = "(id_rs IS NULL OR id_rs = 0 OR id_rs = '') 
                                           AND (id_puskes IS NULL OR id_puskes = 0 OR id_puskes = '')";
                }
                
                if (!empty($where_conditions)) {
                    $query .= " AND " . implode(" AND ", $where_conditions);
                }
                
                $stmt = $koneksi->prepare($query);
                if ($stmt) {
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($row = $result->fetch_assoc()) {
                        $stats['total'] += (int)($row['total'] ?? 0);
                        $stats['diajukan'] += (int)($row['diajukan'] ?? 0);
                        $stats['diproses'] += (int)($row['diproses'] ?? 0);
                        $stats['selesai'] += (int)($row['selesai'] ?? 0);
                        $stats['ditolak'] += (int)($row['ditolak'] ?? 0);
                    }
                    $stmt->close();
                }
            }
            $check->close();
        } catch (Exception $e) {
            error_log("Error counting stats for table $tbl: " . $e->getMessage());
        }
    }
}

// Tentukan wilayah tugas
if (empty($allowed_kecamatan_names)) {
    $wilayah_tugas = 'Belum ditugaskan ke kecamatan manapun';
} elseif (count($allowed_kecamatan_names) >= 14) {
    $wilayah_tugas = 'Semua Kecamatan';
} else {
    $wilayah_tugas = implode(', ', $allowed_kecamatan_names);
    if (strlen($wilayah_tugas) > 100) {
        $wilayah_tugas = substr($wilayah_tugas, 0, 100) . '...';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token_value, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= isset($_GET['detail_id']) ? 'Detail Pengajuan' : 'Proses Pengajuan' ?> - Operator</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/proses_pengajuan.css?v=20260908-2">
    <link rel="stylesheet" href="../assets/css/operator_document_preview.css?v=20260824-1">
    <script src="../assets/js/service-tracking.js?v=20260908-1"></script>
    <link rel="stylesheet" href="../assets/css/service-timeline.css?v=20260908-2">
    <link rel="stylesheet" href="../assets/css/theme.css?v=20261118-10">
    <script src="../assets/js/theme.js?v=20261118-1"></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">Operator Dukcapil</div>
        <nav>
            <ul>
                <li><a href="dashboard_operator.php">Dashboard</a></li>
                <li class="active">Proses Pengajuan</li>
                <li><a href="lapor_peristiwa_opr.php">Laporan Peristiwa</a></li>
                <li><a href="riwayat_operator.php">Riwayat</a></li>  
                <li><a href="dokumen_email_belum_masuk.php">Dokumen Belum Diterima</a></li>
                <li><a href="formulir_operator.php">Formulir Kependudukan</a></li>

                <li id="logoutBtn">Logout</li>
            </ul>
        </nav>
    </aside>

    <div class="menu-toggle">☰</div>

    <main class="main">
        <header class="header">
            <div class="left-header">
                <div class="title">
                    <?= isset($_GET['detail_id']) ? 'Detail Pengajuan' : 'Proses Pengajuan' ?> - Operator
                </div>
                <div class="subtitle">Wilayah Tugas: <?= esc($wilayah_tugas) ?></div>
            </div>
            <div class="user-info">
                Hai, <?= esc($_SESSION['nama']) ?>
                <?php include __DIR__ . '/notification_widget.php'; ?>
                <button class="logout" id="logoutBtn2">Logout</button>
            </div>
        </header>

        <section class="content">
            <!-- Flash Message Toast -->
            <?php if ($flash_success): ?>
                <div class="toast toast-success">
                    <?= esc($flash_success) ?>
                </div>
                <script>setTimeout(() => document.querySelector('.toast').remove(), 5000);</script>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="toast toast-error">
                    <?= esc($flash_error) ?>
                </div>
                <script>setTimeout(() => document.querySelector('.toast').remove(), 6000);</script>
            <?php endif; ?>

            <?php if (isset($_GET['detail_id']) && $detail_data): ?>
                <!-- Halaman Detail Pengajuan -->
                <a href="proses_pengajuan.php?page=<?= $current_page ?>&type=<?= urlencode($_GET['type'] ?? '') ?>&status=<?= urlencode($_GET['status'] ?? '') ?>&kecamatan=<?= urlencode($_GET['kecamatan'] ?? '') ?>&search_nama=<?= urlencode($_GET['search_nama'] ?? '') ?>" class="btn-back">
                    <i>←</i> Kembali ke Daftar Pengajuan
                </a>
                
                <div class="detail-section">
                    <div class="detail-header">
                        <h1 class="detail-title">
                            Detail Pengajuan <?= getJenisLayanan($_GET['table']) ?>
                            <span class="jenis-badge <?= match($_GET['table']) {
                                'tb_kartukeluarga' => 'jenis-kk',
                                'tb_akta_kelahiran' => 'jenis-akta-lahir',
                                'tb_akta_kematian' => 'jenis-akta-mati',
                                'tb_pindah_alamat' => 'jenis-pindah-alamat',
                                'tb_pindah_online' => 'jenis-pindah-masuk',
                                'tb_pengajuan_kia' => 'jenis-kia',
                                default => ''
                            } ?>">
                                <?= getJenisLayanan($_GET['table']) ?>
                            </span>
                        </h1>
                        <div data-service-current-status class="detail-status status-<?= strtolower($detail_data['status'] ?? '') ?>">
                            <?= strtoupper($detail_data['status'] ?? 'TIDAK DIKETAHUI') ?>
                        </div>
                    </div>
                    
                    <div class="detail-grid">
                        <!-- Informasi Pemohon -->
                        <div class="detail-card">
                            <h3>Informasi Pemohon</h3>
                            <div class="detail-row">
                                <div class="detail-label">Nama Pemohon</div>
                                <div class="detail-value"><?= esc($detail_data['nama_pemohon'] ?? ($detail_data['user_info']['nama'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">NIK Pemohon</div>
                                <div class="detail-value"><?= esc($detail_data['nik_pemohon'] ?? ($detail_data['user_info']['nik'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">No HP</div>
                                <div class="detail-value"><?= esc($detail_data['no_hp'] ?? ($detail_data['user_info']['no_hp'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?= esc($detail_data['email'] ?? ($detail_data['user_info']['email'] ?? '-')) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Tanggal Pengajuan</div>
                                <div class="detail-value"><?= formatDate($detail_data['effective_created_at'] ?? ($detail_data['created_at'] ?? '')) ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Lokasi -->
                        <div class="detail-card">
                            <h3>Informasi Lokasi</h3>
                            <div class="detail-row">
                                <div class="detail-label">Kecamatan</div>
                                <div class="detail-value"><?= esc($detail_data['nama_kecamatan'] ?? '-') ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Desa</div>
                                <div class="detail-value"><?= esc($detail_data['nama_desa'] ?? '-') ?></div>
                            </div>
                        </div>
                        
                        <!-- Informasi Layanan Spesifik -->
                        <div class="detail-card">
                            <h3>Informasi <?= getJenisLayanan($_GET['table']) ?></h3>
                            <?php if ($_GET['table'] === 'tb_kartukeluarga'): ?>
                                <?php
                                // Kategori layanan KK menentukan data mana yang perlu diverifikasi operator.
                                $kategori_kk = siprima_kk_kategori_normalize($detail_data['kategori_layanan'] ?? null);
                                $config_kk = siprima_kk_layanan_config($kategori_kk);
                                ?>
                                <div class="detail-row">
                                    <div class="detail-label">Jenis Permohonan</div>
                                    <div class="detail-value"><?= esc(siprima_kk_kategori_label($kategori_kk)) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label"><?= esc($config_kk['label_nama'] ?? 'Nama Kepala Keluarga') ?></div>
                                    <div class="detail-value"><?= esc($detail_data['nama_kepalaKeluarga'] ?? '-') ?></div>
                                </div>
                                <?php if ($config_kk !== null): ?>
                                <div class="detail-row">
                                    <div class="detail-label"><?= esc($config_kk['label_nik']) ?></div>
                                    <div class="detail-value"><?= esc($detail_data['nik_subjek'] ?? '-') ?></div>
                                </div>
                                <?php else: ?>
                                <div class="detail-row">
                                    <div class="detail-label">Dusun/Alamat</div>
                                    <div class="detail-value"><?= esc($detail_data['dusun'] ?? '-') ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($detail_data['anggotaKeluarga'])): 
                                    $anggota = json_decode($detail_data['anggotaKeluarga'], true);
                                    if (is_array($anggota) && !empty($anggota)): ?>
                                    <div class="detail-row">
                                        <div class="detail-label">Anggota Keluarga</div>
                                        <div class="detail-value">
                                            <div style="max-height: 200px; overflow-y: auto; padding: 5px;">
                                                <?php foreach ($anggota as $index => $member): ?>
                                                    <div style="margin-bottom: 5px; padding-bottom: 5px; border-bottom: 1px dotted #ddd;">
                                                        <?= ($index + 1) ?>. <?= esc($member['nama'] ?? '') ?> 
                                                        (NIK: <?= esc($member['nik'] ?? '-') ?>)
                                                        - <?= esc($member['hubungan'] ?? '-') ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                            <?php elseif ($_GET['table'] === 'tb_akta_kelahiran'): ?>
                                <?php
                                // Kategori permohonan menentukan data mana yang perlu diverifikasi operator.
                                $kategori_akta = siprima_akta_kelahiran_jenis_normalize($detail_data['jenis_permohonan'] ?? null);
                                $akta_hilang = $kategori_akta === 'hilang';
                                $akta_bayi_baru = $kategori_akta === AKTA_KELAHIRAN_JENIS_DEFAULT;
                                ?>
                                <div class="detail-row">
                                    <div class="detail-label">Jenis Permohonan</div>
                                    <div class="detail-value"><?= esc(siprima_akta_kelahiran_jenis_label($kategori_akta)) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label"><?= $akta_bayi_baru ? 'Nama Bayi' : 'Nama yang Bersangkutan' ?></div>
                                    <div class="detail-value"><?= esc($detail_data['nama_bayi'] ?? '-') ?></div>
                                </div>
                                <?php if (!$akta_bayi_baru): ?>
                                <div class="detail-row">
                                    <div class="detail-label">NIK yang Bersangkutan</div>
                                    <div class="detail-value"><?= esc($detail_data['nik_subjek'] ?? '-') ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if ($akta_hilang): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Nomor Surat Keterangan Kehilangan</div>
                                    <div class="detail-value"><?= esc($detail_data['no_suket_kehilangan'] ?? '-') ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if ($kategori_akta === 'pembaruan' || $akta_hilang): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Nomor Akta Lahir Lama</div>
                                    <div class="detail-value"><?= esc($detail_data['nomor_akta_lama'] ?? '-') ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!$akta_hilang): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Tanggal Lahir</div>
                                    <div class="detail-value"><?= formatDate($detail_data['tanggal_lahir'] ?? '') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Waktu Lahir</div>
                                    <div class="detail-value"><?= esc($detail_data['Waktu'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Tempat Lahir</div>
                                    <div class="detail-value"><?= esc($detail_data['tempat_lahir'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Jenis Kelamin</div>
                                    <div class="detail-value"><?= esc($detail_data['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Anak ke-</div>
                                    <div class="detail-value"><?= esc($detail_data['urutan_kelahiran'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Nama Ayah</div>
                                    <div class="detail-value"><?= esc($detail_data['nama_ayah'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">NIK Ayah</div>
                                    <div class="detail-value"><?= esc($detail_data['nik_ayah'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Nama Ibu</div>
                                    <div class="detail-value"><?= esc($detail_data['nama_ibu'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">NIK Ibu</div>
                                    <div class="detail-value"><?= esc($detail_data['nik_ibu'] ?? '-') ?></div>
                                </div>
                                
                                <!-- DATA SAKSI KELAHIRAN -->
                                <div class="detail-row">
                                    <div class="detail-label">Nama Saksi 1</div>
                                    <div class="detail-value"><?= esc($detail_data['nama_saksi1'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">NIK Saksi 1</div>
                                    <div class="detail-value"><?= esc($detail_data['nik_saksi1'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Nama Saksi 2</div>
                                    <div class="detail-value"><?= esc($detail_data['nama_saksi2'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">NIK Saksi 2</div>
                                    <div class="detail-value"><?= esc($detail_data['nik_saksi2'] ?? '-') ?></div>
                                </div>
                                <?php endif; ?>
                                
                            <?php elseif ($_GET['table'] === 'tb_akta_kematian'): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Nama Yang Meninggal</div>
                                    <div class="detail-value"><?= esc(pickDetailValue($detail_data, ['Nama_meninggal', 'nama_meninggal'])) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">NIK Yang Meninggal</div>
                                    <div class="detail-value"><?= esc(pickDetailValue($detail_data, ['Nik_meninggal', 'nik_meninggal'])) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Tanggal Kematian</div>
                                    <div class="detail-value"><?= formatDate(pickDetailValue($detail_data, ['tanggal_kematian', 'Tanggal_kematian'], '')) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Tempat Kematian</div>
                                    <div class="detail-value"><?= esc(pickDetailValue($detail_data, ['Tempat', 'tempat'])) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Dusun</div>
                                    <div class="detail-value"><?= esc($detail_data['dusun'] ?? '-') ?></div>
                                </div>
                                
                                <!-- DATA ORANG TUA -->
                                <div class="detail-row">
                                    <div class="detail-label">Nama Ayah</div>
                                    <div class="detail-value"><?= esc(pickDetailValue($detail_data, ['Nama_Ayah', 'nama_ayah'])) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">NIK Ayah</div>
                                    <div class="detail-value"><?= esc(pickDetailValue($detail_data, ['Nik_ayah', 'nik_ayah'])) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Nama Ibu</div>
                                    <div class="detail-value"><?= esc(pickDetailValue($detail_data, ['Nama_Ibu', 'nama_ibu'])) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">NIK Ibu</div>
                                    <div class="detail-value"><?= esc(pickDetailValue($detail_data, ['Nik_ibu', 'nik_ibu'])) ?></div>
                                </div>
                                
                                <!-- DATA SAKSI KEMATIAN -->
                                <div class="detail-row">
                                    <div class="detail-label">Nama Saksi 1</div>
                                    <div class="detail-value"><?= esc($detail_data['nama_saksi1'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">NIK Saksi 1</div>
                                    <div class="detail-value"><?= esc($detail_data['nik_saksi1'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Nama Saksi 2</div>
                                    <div class="detail-value"><?= esc($detail_data['nama_saksi2'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">NIK Saksi 2</div>
                                    <div class="detail-value"><?= esc($detail_data['nik_saksi2'] ?? '-') ?></div>
                                </div>
                                
                            <?php elseif ($_GET['table'] === 'tb_pindah_alamat'): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Nama Kepala Keluarga</div>
                                    <div class="detail-value"><?= esc(pickDetailValue($detail_data, ['nama_kepalaKeluarga', 'nama_kepalakeluarga'])) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Alamat Asal</div>
                                    <div class="detail-value"><?= esc($detail_data['alamat_asal'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Alamat Tujuan</div>
                                    <div class="detail-value"><?= esc($detail_data['alamat_tujuan'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Alasan Pindah</div>
                                    <div class="detail-value"><?= displayAlasanPindah($detail_data['alasan_pindah'] ?? '-') ?></div>
                                </div>
                                <?php if (!empty($detail_data['anggotaKeluarga'])): 
                                    $anggota_pindah_alamat = json_decode($detail_data['anggotaKeluarga'], true);
                                    if (is_array($anggota_pindah_alamat) && !empty($anggota_pindah_alamat)): ?>
                                    <div class="detail-row">
                                        <div class="detail-label">Anggota Keluarga yang Pindah</div>
                                        <div class="detail-value">
                                            <div style="max-height: 200px; overflow-y: auto; padding: 5px;">
                                                <?php foreach ($anggota_pindah_alamat as $index => $member): ?>
                                                    <div style="margin-bottom: 5px; padding-bottom: 5px; border-bottom: 1px dotted #ddd;">
                                                        <?= ($index + 1) ?>. <?= esc($member['nama'] ?? '') ?>
                                                        (NIK: <?= esc($member['nik'] ?? '-') ?>)
                                                        - <?= esc($member['hubungan'] ?? '-') ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                            <?php elseif ($_GET['table'] === 'tb_pengajuan_kia'): ?>
                                <?php
                                $kiaAnakList = $detail_data['anak_list'] ?? [];
                                $kiaVersiAktif = (int)($detail_data['versi_aktif'] ?? 1);
                                $kiaKatAntrian = strtolower((string)($detail_data['kategori_antrian'] ?? 'reguler'));
                                $isKiaResubmitted = ($kiaVersiAktif > 1 || str_contains($kiaKatAntrian, 'perbaikan'));
                                ?>
                                <?php if ($isKiaResubmitted): ?>
                                <div style="margin:8px 0 14px;padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #d97706;border-radius:8px;font-size:12.5px;color:#78350f;">
                                    <div style="font-weight:700;color:#92400e;margin-bottom:4px;">🔄 Permohonan Hasil Revisi / Perbaikan (Versi <?= $kiaVersiAktif ?>)</div>
                                    <div><strong>Tanggal Revisi Terakhir:</strong> <?= !empty($detail_data['resubmitted_at']) ? esc(formatDate($detail_data['resubmitted_at'])) : esc(formatDate($detail_data['updated_at'] ?? '')) ?></div>
                                    <?php
                                    $alasanRevisiPrev = trim((string)($detail_data['alasan_penolakan'] ?? ($detail_data['alasan'] ?? '')));
                                    if ($alasanRevisiPrev === '' && !empty($detail_alasan_history)) {
                                        $alasanRevisiPrev = trim((string)($detail_alasan_history[0]['alasan'] ?? ''));
                                    }
                                    ?>
                                    <?php if ($alasanRevisiPrev !== ''): ?>
                                    <div style="margin-top:4px;"><strong>Catatan Perbaikan Sebelumnya:</strong> <?= esc($alasanRevisiPrev) ?></div>
                                    <?php endif; ?>
                                    <div style="margin-top:4px;font-size:11.5px;color:#92400e;">Bagian dokumen dengan penanda <strong>✨ Diperbarui pada Revisi V<?= $kiaVersiAktif ?></strong> telah diperbarui oleh pemohon.</div>
                                </div>
                                <?php endif; ?>
                                <div class="detail-row">
                                    <div class="detail-label">Kategori Antrean &amp; Versi</div>
                                    <div class="detail-value">
                                        <?php if ($isKiaResubmitted): ?>
                                            <span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:12px;font-weight:700;">🔄 Pengajuan Ulang / Perbaikan Berkas (Versi <?= $kiaVersiAktif ?>)</span>
                                        <?php else: ?>
                                            <span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;font-size:12px;font-weight:700;">🆕 Pengajuan Baru (Versi 1)</span>
                                        <?php endif; ?>
                                        <span style="display:inline-block;margin-left:6px;padding:4px 10px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:12px;font-weight:600;">
                                            <?= $kiaKatAntrian === 'multi_anak' ? 'Multi-Anak (>1 Anak)' : ($kiaKatAntrian === 'perbaikan' ? 'Antrean Perbaikan/Koreksi' : 'Reguler') ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Nomor Kartu Keluarga (KK)</div>
                                    <div class="detail-value"><?= esc($detail_data['no_kk'] ?? '-') ?></div>
                                </div>
                                <?php foreach ($kiaAnakList as $idxA => $anakRow): ?>
                                    <div style="color:#0284c7;font-weight:700;font-size:13px;margin:<?= $idxA > 0 ? '16px' : '8px' ?> 0 6px;display:flex;align-items:center;gap:6px;">
                                        <span>👶 Data Anak ke-<?= ($idxA + 1) ?></span>
                                        <?php if (!empty($anakRow['urutan_anak'])): ?>
                                            <span style="font-size:11px;font-weight:600;color:#64748b;background:#f1f5f9;padding:1px 6px;border-radius:4px;">Urutan KK: <?= (int)$anakRow['urutan_anak'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Nama Anak</div>
                                        <div class="detail-value"><strong><?= esc($anakRow['nama_anak'] ?? '-') ?></strong></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">NIK Anak</div>
                                        <div class="detail-value"><?= esc($anakRow['nik_anak'] ?? '-') ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Tanggal Lahir</div>
                                        <div class="detail-value"><?= !empty($anakRow['tanggal_lahir']) ? date('d F Y', strtotime($anakRow['tanggal_lahir'])) : '-' ?></div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Umur Anak Saat Ini</div>
                                        <div class="detail-value">
                                            <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#e0f2fe;color:#0369a1;font-weight:700;font-size:12.5px;">
                                                <?= esc($anakRow['umur_teks'] ?? '-') ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div style="margin-top:6px;">
                                        <button type="button" onclick="var f=document.getElementById('formKoreksiAnak_<?= (int)$anakRow['id'] ?>');f.style.display=(f.style.display==='none'?'block':'none');" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">&#9998; Koreksi Data Anak ke-<?= ($idxA + 1) ?></button>
                                        <form id="formKoreksiAnak_<?= (int)$anakRow['id'] ?>" method="POST" style="display:none;margin-top:8px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                                            <?= csrf_input(); ?>
                                            <input type="hidden" name="action" value="kia_koreksi_anak">
                                            <input type="hidden" name="id_pengajuan" value="<?= (int)$detail_data['id'] ?>">
                                            <input type="hidden" name="id_anak" value="<?= (int)$anakRow['id'] ?>">
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                                                <div>
                                                    <label style="font-size:11px;font-weight:600;display:block;">Nama Anak</label>
                                                    <input type="text" name="nama_anak" value="<?= esc($anakRow['nama_anak'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;" required>
                                                </div>
                                                <div>
                                                    <label style="font-size:11px;font-weight:600;display:block;">NIK Anak (16 digit)</label>
                                                    <input type="text" name="nik_anak" maxlength="16" value="<?= esc($anakRow['nik_anak'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;" required>
                                                </div>
                                                <div>
                                                    <label style="font-size:11px;font-weight:600;display:block;">Tanggal Lahir</label>
                                                    <input type="date" name="tanggal_lahir" value="<?= esc($anakRow['tanggal_lahir'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;" required>
                                                </div>
                                                <div>
                                                    <label style="font-size:11px;font-weight:600;display:block;">Alasan Koreksi</label>
                                                    <input type="text" name="alasan_koreksi" placeholder="Contoh: Perbaikan ejaan sesuai Akta" style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;" required>
                                                </div>
                                            </div>
                                            <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Simpan Koreksi Anak</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif ($_GET['table'] === 'tb_pindah_online'): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Nama Kepala Keluarga</div>
                                    <div class="detail-value"><?= esc(pickDetailValue($detail_data, ['nama_kepalaKeluarga', 'nama_kepalakeluarga'])) ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Nomor KK</div>
                                    <div class="detail-value"><?= esc($detail_data['no_kk'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Alamat Asal (luar Gorontalo)</div>
                                    <div class="detail-value"><?= esc($detail_data['alamat_asal'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Alamat Tujuan</div>
                                    <div class="detail-value"><?= esc($detail_data['alamat_tujuan'] ?? '-') ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Alasan Pindah</div>
                                    <div class="detail-value"><?= displayAlasanPindah($detail_data['alasan_pindah'] ?? '-') ?></div>
                                </div>
                                <?php if (!empty($detail_data['anggotaKeluarga'])): 
                                    $anggota_pindah_online = json_decode($detail_data['anggotaKeluarga'], true);
                                    if (is_array($anggota_pindah_online) && !empty($anggota_pindah_online)): ?>
                                    <div class="detail-row">
                                        <div class="detail-label">Anggota Keluarga yang Pindah</div>
                                        <div class="detail-value">
                                            <div style="max-height: 200px; overflow-y: auto; padding: 5px;">
                                                <?php foreach ($anggota_pindah_online as $index => $member): ?>
                                                    <div style="margin-bottom: 5px; padding-bottom: 5px; border-bottom: 1px dotted #ddd;">
                                                        <?= ($index + 1) ?>. <?= esc($member['nama'] ?? '') ?>
                                                        (NIK: <?= esc($member['nik'] ?? '-') ?>)
                                                        - <?= esc($member['hubungan'] ?? '-') ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Keterangan -->
                        <?php if (!empty($detail_data['keterangan'])): ?>
                        <div class="detail-card">
                            <h3>Keterangan/Pesan dari Pemohon</h3>
                            <div class="detail-row">
                                <div class="detail-value"><?= nl2br(esc($detail_data['keterangan'])) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Alasan Penolakan -->
                        <div class="detail-card" data-service-current-note style="display:<?= empty($detail_data['alasan']) ? 'none' : 'block' ?>">
                            <h3>Catatan Status</h3>
                            <div class="detail-row">
                                <div class="detail-value" data-service-note-value style="white-space:pre-wrap"><?= esc($detail_data['alasan'] ?? '') ?></div>
                            </div>
                        </div>

                        <?php if (!empty($detail_alasan_history)): ?>
                        <div class="detail-card">
                            <h3>Riwayat Alasan Penolakan</h3>
                            <?php foreach ($detail_alasan_history as $history_item): ?>
                            <div class="detail-row">
                                <div class="detail-value">
                                    <?= nl2br(esc($history_item['alasan'] ?? '-')) ?>
                                    <div style="margin-top:6px; font-size:12px; color:#6b7280;">
                                        <?= esc($history_item['created_at_formatted'] ?? '-') ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Dokumen yang Diupload -->
                    <?php if (!empty($detail_files)): ?>
                    <div class="dokumen-section">
                        <h3>Dokumen yang Diupload</h3>
                        <div class="dokumen-grid">
                            <?php 
                            $file_counter = 1;
                            foreach ($detail_files as $index => $file): 
                                $ext = strtolower((string)($file['ext'] ?? pathinfo($file['path'], PATHINFO_EXTENSION)));
                                $file_exists = isset($file['file_exists']) ? (bool)$file['file_exists'] : true;
                                $is_image = in_array($ext, ['jpg', 'jpeg', 'jfif', 'jpe', 'png', 'gif'], true);
                                $is_previewable = $file_exists && in_array($ext, ['pdf', 'jpg', 'jpeg', 'jfif', 'jpe', 'png', 'gif'], true);
                                $icon = match($ext) {
                                    'pdf' => '📄',
                                    'jpg', 'jpeg', 'jfif', 'jpe', 'png', 'gif' => '🖼️',
                                    default => '📎'
                                };
                                
                                // Tentukan label berdasarkan tipe dokumen
                                $type_label = match($file['type'] ?? 'lainnya') {
                                    'formulir_f101' => 'Formulir F-1.01 Biodata Keluarga',
                                    'formulir_f103' => 'Formulir F-103 Pindah',
                                    'kk_lama_atau_surat_kehilangan' => 'KK Lama / Surat Kehilangan',
                                    'suket_kehilangan_kk' => 'Surat Keterangan Hilang KK',
                                    'formulir_f106' => 'Formulir F-1.06 SPTJM Perubahan Data',
                                    'formulir' => 'Formulir F-2.01 Kelahiran',
                                    'surat_keterangan' => 'Surat Keterangan Lahir',
                                    'buku_nikah' => 'Buku Nikah/Akta Kawin',
                                    'kk_ktp_hilang' => 'KK/KTP/Surat Keterangan Hilang',
                                    'kk_ktp' => 'KK/KTP',
                                    'foto_pemohon' => 'Foto Pemohon Memegang Dokumen',
                                    'foto_pemohon_kk_ktp' => 'Foto Pemohon Memegang KK/KTP',
                                    'ktp' => 'KTP',
                                    'kk' => 'Kartu Keluarga',
                                    'surat_kematian' => 'Surat Kematian',
                                    'akta_kelahiran' => 'Akta Kelahiran Anak',
                                    'saksi' => 'Surat Saksi',
                                    'formulir_f201' => 'Formulir F-2.01 Kematian',
                                    'identitas_meninggal' => 'KK/KTP/Surat Kehilangan',
                                    'pasfoto_anak' => 'Pas Foto Anak',
                                    'fc_kk' => 'Kartu Keluarga Terbaru',
                                    'dokumen_tambahan' => 'Dokumen Tambahan',
                                    'lainnya' => 'Dokumen Pendukung',
                                    default => ucwords(str_replace('_', ' ', $file['type'] ?? 'Dokumen'))
                                };
                                
                                $display_name = $file['original_name'] ?? 'Dokumen ' . $file_counter;
                                if (strlen($display_name) > 28) {
                                    $display_name = substr($display_name, 0, 25) . '...';
                                }
                                
                                $file_info = '';
                                if (isset($file['size'])) {
                                    $file_size = formatFileSize($file['size']);
                                    $file_info = " ({$file_size})";
                                }
                            ?>
                            <div class="dokumen-card" <?= $is_previewable ? "onclick=\"previewDokumen('" . esc($file['path']) . "', '" . esc($ext) . "')\"" : '' ?>>
                                <?php if ($is_image && $file_exists): ?>
                                    <?php if (($file['type'] ?? '') === 'pasfoto_anak'): ?>
                                    <div class="dokumen-thumb-wrap" style="width:112px;height:140px;aspect-ratio:4/5;margin:0 auto 8px auto;border-radius:6px;overflow:hidden;background:#f8fafc;border:1px solid #cbd5e1;display:flex;align-items:center;justify-content:center;">
                                        <img src="<?= esc($file['path']) ?>" alt="<?= esc($type_label) ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover;object-position:center 20%;cursor:zoom-in;" onerror="this.style.display='none';this.parentElement.innerHTML='<span style=&quot;font-size:11px;color:#dc2626;padding:8px;text-align:center;&quot;>⚠️ Gagal memuat pratinjau gambar</span>';">
                                    </div>
                                    <?php else: ?>
                                    <div class="dokumen-thumb-wrap" style="width:100%;height:135px;margin-bottom:8px;border-radius:8px;overflow:hidden;background:#f8fafc;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;padding:4px;">
                                        <img src="<?= esc($file['path']) ?>" alt="<?= esc($type_label) ?>" loading="lazy" style="max-width:100%;max-height:100%;object-fit:contain;cursor:zoom-in;" onerror="this.style.display='none';this.parentElement.innerHTML='<span style=&quot;font-size:11px;color:#dc2626;padding:8px;text-align:center;&quot;>⚠️ Gagal memuat pratinjau gambar</span>';">
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                <div class="dokumen-icon"><?= $icon ?></div>
                                <?php endif; ?>
                                <?php if (!empty($file['anak_label']) || !empty($file['versi'])): ?>
                                <div style="display:flex;flex-wrap:wrap;gap:4px;justify-content:center;margin-bottom:6px;">
                                    <?php if (!empty($file['anak_label'])): ?>
                                    <span style="font-size:10.5px;font-weight:700;color:#1e40af;background:#dbeafe;padding:2px 7px;border-radius:999px;"><?= esc($file['anak_label']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($file['versi'])): ?>
                                    <span style="font-size:10.5px;font-weight:700;color:#166534;background:#dcfce7;padding:2px 7px;border-radius:999px;">Aktif (V<?= (int)$file['versi'] ?>)</span>
                                    <?php if (!empty($isKiaResubmitted) && (int)$file['versi'] === (int)($kiaVersiAktif ?? 1)): ?>
                                    <span style="font-size:10.5px;font-weight:700;color:#92400e;background:#fef3c7;border:1px solid #fde68a;padding:2px 7px;border-radius:999px;">✨ Diperbarui pada Revisi V<?= (int)$kiaVersiAktif ?></span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <div class="dokumen-name" title="<?= esc($file['original_name'] ?? $display_name) ?>">
                                    <?= esc($display_name) ?>
                                </div>
                                <div class="dokumen-type">
                                    <?= esc($type_label) ?><?= $file_info ?>
                                </div>
                                <?php if (!$file_exists): ?>
                                <div style="margin-top:6px;padding:5px 8px;border-radius:6px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-size:11px;font-weight:600;">
                                    ⚠️ File fisik tidak ditemukan di server
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($file['generated'])): ?>
                                <?php // Dibuat server dari isian pemohon: inilah dokumen yang tanda tangannya wajib diverifikasi. ?>
                                <div class="dokumen-type" style="margin-top:4px; color:#1d4ed8; font-weight:600;">
                                    Dibuat sistem — verifikasi tanda tangan pemohon
                                </div>
                                <?php endif; ?>
                                <div class="dokumen-actions">
                                    <?php if ($is_previewable): ?>
                                    <button type="button" class="btn-preview" onclick="event.stopPropagation(); previewDokumen('<?= esc($file['path']) ?>', '<?= esc($ext) ?>')">
                                        Perbesar / Preview
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($file_exists): ?>
                                    <a href="<?= esc($file['download_url'] ?? '') ?>" class="btn-download" onclick="event.stopPropagation()">
                                        Download
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php 
                            $file_counter++;
                            endforeach; ?>
                        </div>
                        
                        <!-- Info debug -->
                        <?php if (($_GET['table'] ?? '') === 'tb_pengajuan_kia' && !empty($detail_superseded_files)): ?>
                        <div style="margin-top:14px;padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;">
                            <strong style="display:block;font-size:12.5px;color:#92400e;margin-bottom:8px;">📁 Dokumen Versi Sebelumnya (Riwayat Revisi Lama — Tidak Dihapus):</strong>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <?php foreach ($detail_superseded_files as $supDoc): ?>
                                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;background:#fff;border:1px solid #e2e8f0;padding:7px 10px;border-radius:6px;font-size:12px;">
                                        <span><strong>V<?= (int)($supDoc['versi'] ?? 1) ?></strong> &bull; <?= esc($supDoc['original_name'] ?? '') ?> <span style="color:#64748b;">(<?= esc($supDoc['anak_label'] ?? '') ?>)</span></span>
                                        <span style="display:flex;gap:6px;">
                                            <a href="<?= esc($supDoc['path'] ?? '') ?>" target="_blank" style="color:#0b6e75;font-weight:600;text-decoration:none;">Preview Lama</a>
                                            <a href="<?= esc($supDoc['download_url'] ?? '') ?>" style="color:#475569;font-weight:600;text-decoration:none;">Unduh Lama</a>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 5px; font-size: 12px; color: #666;">
                            <strong>Info:</strong> Total <?= count($detail_files) ?> dokumen ditemukan.
                            <?php if (($_GET['table'] ?? '') === 'tb_pengajuan_kia' && !empty($detail_files)): ?>
                                <button type="button" onclick="var m=document.getElementById('formKoreksiMappingKia');m.style.display=(m.style.display==='none'?'block':'none');" style="margin-left:12px;background:#fff;border:1px solid #cbd5e1;padding:3px 10px;border-radius:6px;font-weight:600;color:#1e293b;cursor:pointer;">&#8644; Koreksi Mapping Dokumen</button>
                                <form id="formKoreksiMappingKia" method="POST" style="display:none;margin-top:10px;padding:12px;background:#fff;border:1px solid #cbd5e1;border-radius:8px;">
                                    <?= csrf_input(); ?>
                                    <input type="hidden" name="action" value="kia_koreksi_mapping">
                                    <input type="hidden" name="id_pengajuan" value="<?= (int)$detail_data['id'] ?>">
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end;">
                                        <div>
                                            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;">Pilih Dokumen</label>
                                            <select name="id_dokumen" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;" required>
                                                <?php foreach ($detail_files as $df): ?>
                                                    <option value="<?= (int)($df['id_dokumen'] ?? 0) ?>"><?= esc($df['original_name'] ?? '') ?> (<?= esc($df['type'] ?? '') ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;">Jenis Dokumen Baru</label>
                                            <select name="jenis_dokumen_baru" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;" required>
                                                <option value="pasfoto_anak">Pas Foto Anak</option>
                                                <option value="akta_kelahiran">Akta Kelahiran Anak</option>
                                                <option value="fc_kk">Kartu Keluarga Terbaru</option>
                                                <option value="foto_pemohon">Foto Pemohon Memegang Dokumen</option>
                                                <option value="dokumen_tambahan">Dokumen Tambahan</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;">Relasi Anak (Opsional)</label>
                                            <select name="id_anak_baru" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;">
                                                <option value="">-- Dokumen Umum / Pemohon --</option>
                                                <?php foreach (($detail_data['anak_list'] ?? []) as $idxK => $ak): ?>
                                                    <option value="<?= (int)$ak['id'] ?>">Anak ke-<?= ($idxK + 1) ?>: <?= esc($ak['nama_anak'] ?? '') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;">Catatan Koreksi</label>
                                            <input type="text" name="alasan_koreksi" placeholder="Contoh: Tertukar slot upload" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;" required>
                                        </div>
                                    </div>
                                    <button type="submit" style="margin-top:10px;background:#2563eb;color:#fff;border:0;padding:6px 14px;border-radius:6px;font-weight:600;cursor:pointer;">Simpan Mapping Dokumen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="dokumen-section">
                        <h3>Dokumen yang Diupload</h3>
                        <p style="color: #666; font-style: italic;">Tidak ada dokumen yang ditemukan atau data dokumen kosong.</p>
                    </div>
                    <?php endif; ?>

                    <?php if (($_GET['table'] ?? '') === 'tb_pengajuan_kia' && !empty($detail_superseded_files)): ?>
                    <div class="dokumen-section" style="margin-top:16px;border:1px dashed #cbd5e1;background:#f8fafc;border-radius:10px;padding:16px;">
                        <h3 style="margin-top:0;font-size:15px;color:#475569;">🗂️ Riwayat Berkas Sebelumnya (Digantikan Saat Pengajuan Ulang / Resubmit)</h3>
                        <p style="margin:0 0 12px 0;font-size:12.5px;color:#64748b;">Berikut adalah berkas versi sebelumnya yang telah digantikan oleh pemohon saat pengajuan ulang, sehingga Operator dapat membandingkan berkas lama dan berkas terbaru.</p>
                        <div class="dokumen-grid">
                            <?php foreach ($detail_superseded_files as $sIdx => $sFile):
                                $sExt = strtolower((string)($sFile['ext'] ?? pathinfo($sFile['path'], PATHINFO_EXTENSION)));
                                $sFileExists = isset($sFile['file_exists']) ? (bool)$sFile['file_exists'] : true;
                                $sIsImage = in_array($sExt, ['jpg', 'jpeg', 'jfif', 'jpe', 'png', 'gif'], true);
                                $sPreview = $sFileExists && in_array($sExt, ['pdf', 'jpg', 'jpeg', 'jfif', 'jpe', 'png', 'gif'], true);
                            ?>
                            <div class="dokumen-card" style="opacity:0.92;border:1px solid #cbd5e1;background:#fff;" <?= $sPreview ? "onclick=\"previewDokumen('" . esc($sFile['path']) . "', '" . esc($sExt) . "')\"" : '' ?>>
                                <?php if ($sIsImage && $sFileExists): ?>
                                <div class="dokumen-thumb-wrap" style="width:100%;height:110px;margin-bottom:8px;border-radius:8px;overflow:hidden;background:#f8fafc;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;">
                                    <img src="<?= esc($sFile['path']) ?>" alt="Berkas Lama" loading="lazy" style="width:100%;height:100%;object-fit:cover;cursor:zoom-in;" onerror="this.style.display='none';">
                                </div>
                                <?php endif; ?>
                                <div style="display:flex;flex-wrap:wrap;gap:4px;justify-content:center;margin-bottom:6px;">
                                    <span style="font-size:10.5px;font-weight:700;color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:999px;">Digantikan / Versi Lama (V<?= (int)($sFile['versi'] ?? 1) ?>)</span>
                                    <?php if (!empty($sFile['anak_label'])): ?>
                                    <span style="font-size:10.5px;font-weight:600;color:#475569;background:#e2e8f0;padding:2px 7px;border-radius:999px;"><?= esc($sFile['anak_label']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="dokumen-name" title="<?= esc($sFile['original_name'] ?? '') ?>"><?= esc($sFile['original_name'] ?? ('Berkas Lama ' . ($sIdx + 1))) ?></div>
                                <div class="dokumen-type"><?= esc(ucwords(str_replace('_', ' ', $sFile['type'] ?? 'Dokumen'))) ?></div>
                                <?php if (!$sFileExists): ?>
                                <div style="margin-top:6px;padding:4px 8px;border-radius:6px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-size:11px;font-weight:600;">
                                    ⚠️ File fisik tidak ditemukan di server
                                </div>
                                <?php endif; ?>
                                <div class="dokumen-actions">
                                    <?php if ($sPreview): ?>
                                    <button type="button" class="btn-preview" onclick="event.stopPropagation(); previewDokumen('<?= esc($sFile['path']) ?>', '<?= esc($sExt) ?>')">Preview Lama</button>
                                    <?php endif; ?>
                                    <?php if ($sFileExists): ?>
                                    <a href="<?= esc($sFile['download_url'] ?? '') ?>" class="btn-download" onclick="event.stopPropagation()">Download</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (($_GET['table'] ?? '') === 'tb_pengajuan_kia' && strtolower((string)($detail_data['status'] ?? '')) === 'selesai'): ?>
                    <?php
                    $opCetakKia = $detail_data['cetak_kia'] ?? null;
                    $opPengaturanCetak = $detail_data['pengaturan_cetak'] ?? ['layanan_cetak_aktif' => true, 'blangko_tersedia' => true, 'tinta_tersedia' => true, 'pesan_status' => ''];
                    ?>
                    <div class="dokumen-section" style="margin-top:18px;border:1px solid #bae6fd;background:#f0f9ff;border-radius:12px;padding:18px;">
                        <h3 style="margin:0 0 8px 0;color:#0c4a6e;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                            <span>🖨️ Layanan Pencetakan Kartu Fisik KIA</span>
                            <span style="font-size:12px;padding:4px 10px;border-radius:999px;background:<?= !empty($opPengaturanCetak['layanan_cetak_aktif']) ? '#dcfce7;color:#166534;' : '#fee2e2;color:#991b1b;' ?>">
                                <?= !empty($opPengaturanCetak['layanan_cetak_aktif']) ? 'Bahan Cetak Tersedia (Blangko &amp; Tinta Aktif)' : 'Bahan Cetak Tidak Tersedia' ?>
                            </span>
                        </h3>
                        <p style="margin:0 0 12px 0;font-size:12.5px;color:#0369a1;">
                            Status permohonan KIA dan status pencetakan kartu fisik KIA dikelola secara terpisah. Pastikan bahan cetak tersedia sebelum mengubah status cetak fisik menjadi <strong>Sedang Dicetak</strong> atau <strong>Selesai Dicetak</strong>.
                        </p>

                        <?php if (empty($opPengaturanCetak['layanan_cetak_aktif'])): ?>
                        <div style="margin-bottom:12px;padding:10px 14px;border-radius:8px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:12.5px;font-weight:600;">
                            ⚠️ Peringatan Ketersediaan Bahan Cetak: <?= esc($opPengaturanCetak['pesan_status'] ?? 'Bahan cetak sedang kosong.') ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($opCetakKia): ?>
                            <?php
                            $stCetakOp = strtolower((string)($opCetakKia['status_cetak'] ?? 'menunggu_cetak'));
                            $lblCetakOp = $stCetakOp === 'selesai_dicetak' ? 'Selesai Dicetak (Siap Diambil)' : ($stCetakOp === 'sedang_dicetak' ? 'Sedang Dicetak' : 'Menunggu Cetak');
                            ?>
                            <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:14px;margin-bottom:12px;">
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;font-size:13px;">
                                    <div><strong>Status Cetak Saat Ini:</strong><br><span style="font-weight:700;color:#0284c7;"><?= esc($lblCetakOp) ?></span></div>
                                    <div><strong>Tanggal Permohonan Cetak:</strong><br><?= esc($opCetakKia['requested_at'] ?? '-') ?></div>
                                    <div><strong>Catatan Pemohon:</strong><br><?= esc($opCetakKia['catatan_pemohon'] ?: '-') ?></div>
                                    <div><strong>Dokumen PDF KIA Terkait:</strong><br><a href="kia.php?action=download_pdf&amp;id=<?= (int)$detail_data['id'] ?>" style="color:#16a34a;font-weight:700;text-decoration:underline;">&#11015; Unduh PDF KIA</a></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="background:#ffffff;border:1px dashed #94a3b8;border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:13px;color:#475569;">
                                Pemohon belum mengajukan permintaan cetak fisik KIA, atau Operator dapat langsung menginisiasi pencetakan kartu fisik di bawah ini.
                            </div>
                        <?php endif; ?>

                        <form method="POST" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:14px;">
                            <?= csrf_input(); ?>
                            <input type="hidden" name="action" value="kia_update_cetak">
                            <input type="hidden" name="id_pengajuan" value="<?= (int)$detail_data['id'] ?>">
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">
                                <div>
                                    <label style="display:block;font-size:12px;font-weight:700;color:#1e293b;margin-bottom:5px;">Ubah Status Cetak Kartu Fisik KIA</label>
                                    <select name="status_cetak" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;" required>
                                        <option value="menunggu_cetak" <?= (($opCetakKia['status_cetak'] ?? '') === 'menunggu_cetak') ? 'selected' : '' ?>>Menunggu Cetak</option>
                                        <option value="sedang_dicetak" <?= (($opCetakKia['status_cetak'] ?? '') === 'sedang_dicetak') ? 'selected' : '' ?> <?= empty($opPengaturanCetak['layanan_cetak_aktif']) ? 'disabled' : '' ?>>Sedang Dicetak</option>
                                        <option value="selesai_dicetak" <?= (($opCetakKia['status_cetak'] ?? '') === 'selesai_dicetak') ? 'selected' : '' ?> <?= empty($opPengaturanCetak['layanan_cetak_aktif']) ? 'disabled' : '' ?>>Selesai Dicetak (Siap Diambil)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;font-weight:700;color:#1e293b;margin-bottom:5px;">Catatan Operator Cetak (Opsional)</label>
                                    <input type="text" name="catatan_operator" value="<?= esc($opCetakKia['catatan_operator'] ?? '') ?>" placeholder="Contoh: Kartu fisik KIA sudah dicetak dan siap diambil di Loket 2" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;">
                                </div>
                                <div>
                                    <button type="submit" style="width:100%;padding:9px 16px;background:#0284c7;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">Simpan Status Cetak Fisik</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if (dokumen_hasil_is_enabled() && $detail_table !== 'tb_pengajuan_kia'): ?>
                    <div class="dokumen-section">
                        <h3>Dokumen Hasil Proses</h3>
                        <?php if (!empty($detail_dokumen_hasil)): ?>
                        <div class="dokumen-grid">
                            <?php foreach ($detail_dokumen_hasil as $hasil_index => $hasil): ?>
                            <div class="dokumen-card">
                                <div class="dokumen-icon">
                                    <i class="fas fa-file-pdf"></i>
                                </div>
                                <div class="dokumen-info">
                                    <h4><?= esc($hasil['original_name'] ?? ('Dokumen Hasil ' . ($hasil_index + 1))) ?></h4>
                                    <p>
                                        PDF
                                        <?php if (!empty($hasil['file_size'])): ?>
                                            - <?= number_format(((int)$hasil['file_size']) / 1024, 1) ?> KB
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($hasil['uploaded_at'])): ?>
                                        <p>Upload: <?= esc(formatDate($hasil['uploaded_at'])) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="dokumen-actions">
                                    <?php if (!empty($hasil['preview_url']) && (!isset($hasil['file_exists']) || $hasil['file_exists'])): ?>
                                    <button type="button" class="btn-preview" onclick="event.stopPropagation(); previewDokumen('<?= esc($hasil['preview_url']) ?>', 'pdf')">
                                        Preview PDF
                                    </button>
                                    <?php endif; ?>
                                    <a href="<?= esc($hasil['download_url'] ?? ('../download_dokumen_hasil.php?id=' . (int)($hasil['id'] ?? 0))) ?>" class="btn-download" onclick="event.stopPropagation()">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                    <form method="POST" class="delete-dokumen-hasil-form" style="display:inline;" onsubmit="return confirm('Hapus dokumen hasil ini?');">
                                        <?= csrf_input(); ?>
                                        <input type="hidden" name="action" value="delete_dokumen_hasil">
                                        <input type="hidden" name="dokumen_id" value="<?= (int)($hasil['id'] ?? 0) ?>">
                                        <input type="hidden" name="table" value="<?= esc($detail_table) ?>">
                                        <input type="hidden" name="permohonan_id" value="<?= (int)$detail_data['id'] ?>">
                                        <button type="submit" class="btn-download" style="background:#dc3545;" onclick="event.stopPropagation()">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p style="color: #666; font-style: italic;">Belum ada dokumen hasil proses yang diupload operator.</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($detail_table === 'tb_pengajuan_kia'): ?>
                        <?php
                        if (!isset($tracking_panel_html)) {
                            ob_start();
                            include __DIR__ . '/service_tracking_form.php';
                            $tracking_panel_html = ob_get_clean();
                        }
                        $hasExistingPdf = !empty($detail_dokumen_hasil);
                        $latestPdf = $hasExistingPdf ? end($detail_dokumen_hasil) : null;
                        $currStatusKia = strtolower(trim((string)($detail_data['status'] ?? 'diajukan')));
                        ?>
                        <!-- 5. Ubah Status Permohonan & 6. Unggah Dokumen PDF Hasil KIA (Sesuai Urutan Alur Pemeriksaan) -->
                        <div class="status-form-container kia-action-panel" style="margin-top:20px;margin-bottom:24px;background:#ffffff;border:1.5px solid #cbd5e1;border-radius:12px;padding:22px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;border-bottom:1px solid #f1f5f9;padding-bottom:12px;">
                                <h3 style="margin:0;font-size:16px;color:#1e293b;display:flex;align-items:center;gap:8px;">
                                    <i class="fas fa-tasks" style="color:#2563eb;"></i> Ubah Status Permohonan &amp; Unggah Dokumen PDF Hasil KIA
                                </h3>
                                <span style="font-size:12.5px;padding:4px 12px;border-radius:999px;font-weight:700;background:<?= $hasExistingPdf ? '#dcfce7;color:#166534;border:1px solid #86efac;' : '#fee2e2;color:#991b1b;border:1px solid #fca5a5;' ?>">
                                    <?= $hasExistingPdf ? '✓ PDF Hasil Tersimpan' : '⚠️ PDF Hasil Belum Diunggah' ?>
                                </span>
                            </div>

                            <form method="POST" id="kiaStatusForm" class="status-form" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:16px;">
                                <?= csrf_input(); ?>
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="expected_status" value="<?= esc($detail_data['status'] ?? '') ?>">
                                <input type="hidden" name="history_version" value="<?= (int)($trackingVersion ?? 0) ?>">
                                <input type="hidden" name="table" value="<?= esc($_GET['table']) ?>">
                                <input type="hidden" name="id" value="<?= (int)$detail_data['id'] ?>">

                                <!-- 5. Ubah Status Permohonan -->
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
                                    <label for="kiaStatusSelect" style="display:block;font-size:13.5px;font-weight:700;color:#1e293b;margin-bottom:6px;">
                                        5. Ubah Status Permohonan:
                                    </label>
                                    <select name="status" id="kiaStatusSelect" class="status-select" onchange="toggleKiaStatusAlasan(this)" style="width:100%;max-width:320px;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13.5px;font-weight:600;color:#0f172a;background:#fff;" required>
                                        <option value="diajukan" <?= $currStatusKia === 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                                        <option value="diproses" <?= $currStatusKia === 'diproses' ? 'selected' : '' ?>>Diproses</option>
                                        <option value="selesai" <?= $currStatusKia === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                                        <option value="ditolak" <?= $currStatusKia === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                                    </select>

                                    <div id="kiaAlasanContainer" style="margin-top:12px;display:<?= $currStatusKia === 'ditolak' ? 'block' : 'none' ?>;">
                                        <label for="kiaAlasanInput" style="display:block;font-size:12.5px;font-weight:700;color:#dc2626;margin-bottom:5px;">
                                            Alasan / Catatan Penolakan (Wajib jika status Ditolak):
                                        </label>
                                        <textarea name="alasan" id="kiaAlasanInput" rows="3" placeholder="Tuliskan catatan atau alasan penolakan secara jelas untuk pemohon..." style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;"><?= esc($detail_data['alasan'] ?? '') ?></textarea>
                                    </div>
                                </div>

                                <!-- 6. Unggah Dokumen PDF Hasil KIA (Tepat di bawah Ubah Status) -->
                                <?php
                                $kiaPdfStatusInfo = (new \Siprima\Models\KiaModel($koneksi))->getHasilPdfStatusByPengajuan((int)$detail_data['id']);
                                $anakPdfSlots = $kiaPdfStatusInfo['anak_slots'] ?? [];
                                $totalAnakSlots = count($anakPdfSlots);
                                ?>
                                <div id="kiaPdfSection" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
                                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                                        <label style="margin:0;font-size:13.5px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:6px;">
                                            <i class="fas fa-file-pdf" style="color:#dc2626;"></i> 6. Dokumen KIA yang akan diunggah
                                            <?php if ($totalAnakSlots > 0): ?>
                                                <span style="font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;background:#dceae7;color:#0b6e75;">
                                                    <?= (int)$kiaPdfStatusInfo['uploaded_count'] ?> / <?= $totalAnakSlots ?> Anak
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <span style="font-size:11.5px;color:#64748b;">Format resmi .pdf, maksimal 5 MB per berkas</span>
                                    </div>

                                    <?php if ($totalAnakSlots > 0): ?>
                                        <div style="display:flex;flex-direction:column;gap:12px;" id="kiaAnakSlotsWrap">
                                            <?php foreach ($anakPdfSlots as $slotIdx => $slot):
                                                $slotAnakId = (int)$slot['id'];
                                                $slotNama = trim((string)($slot['nama_anak'] ?? '')) !== '' ? (string)$slot['nama_anak'] : ('Anak ' . ($slotIdx + 1));
                                                $slotPdf = $slot['pdf'] ?? null;
                                                $slotHasExisting = ($slotPdf !== null);
                                                $slotDocId = (int)($slotPdf['id'] ?? 0);
                                                $slotDownloadUrl = $slotDocId > 0
                                                    ? ('kia.php?action=view_dokumen&id_dokumen=' . $slotDocId . '&download=1&ext=.pdf')
                                                    : ('kia.php?action=download_pdf&id=' . (int)$detail_data['id'] . '&download=1');
                                                $slotInputId = 'dokumen_kia_pdf_anak_' . $slotAnakId;
                                            ?>
                                                <div class="kia-anak-pdf-slot" data-anak-nama="<?= esc($slotNama) ?>" data-has-existing="<?= $slotHasExisting ? '1' : '0' ?>" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:14px;">
                                                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                                                        <div style="font-size:13px;font-weight:700;color:#0f172a;">
                                                            <?= ($slotIdx + 1) ?>. PDF KIA &mdash; <?= esc($slotNama) ?>
                                                            <?php if (!empty($slot['nik_anak'])): ?>
                                                                <span style="font-weight:500;color:#64748b;font-size:12px;">(NIK: <?= esc($slot['nik_anak']) ?>)</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;<?= $slotHasExisting ? 'background:#dcfce7;color:#166534;' : 'background:#fef3c7;color:#92400e;' ?>">
                                                            <?= $slotHasExisting ? ('PDF Tersimpan (V' . (int)($slotPdf['versi'] ?? 1) . ')') : 'Belum Diunggah' ?>
                                                        </span>
                                                    </div>

                                                    <?php if ($slotHasExisting): ?>
                                                        <?php
                                                        $slotViewUrl = $slotDocId > 0
                                                            ? ('kia.php?action=view_dokumen&id_dokumen=' . $slotDocId . '&ext=.pdf')
                                                            : ('kia.php?action=download_pdf&id=' . (int)$detail_data['id'] . '&inline=1');
                                                        $slotRiwayatPdf = is_array($slot['riwayat_pdf'] ?? null) ? $slot['riwayat_pdf'] : [];
                                                        ?>
                                                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                                                            <div style="display:flex;align-items:center;gap:8px;">
                                                                <i class="fas fa-file-pdf" style="font-size:20px;color:#dc2626;"></i>
                                                                <div>
                                                                    <strong style="display:block;font-size:12.5px;color:#0f172a;"><?= esc($slotPdf['original_name'] ?? ('KIA_' . $slotNama . '.pdf')) ?></strong>
                                                                    <span style="font-size:11px;color:#64748b;">
                                                                        <?php if ((int)($slotPdf['file_size'] ?? 0) > 0): ?>
                                                                            <?= number_format(((int)$slotPdf['file_size']) / 1024, 1) ?> KB &bull;
                                                                        <?php endif; ?>
                                                                        <?= !empty($slotPdf['uploaded_at']) ? 'Diunggah: ' . esc(formatDate($slotPdf['uploaded_at'])) : '' ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                                <a href="<?= esc($slotViewUrl) ?>" target="_blank" rel="noopener" style="padding:5px 11px;border-radius:6px;background:#ffffff;border:1px solid #0b6e75;color:#0b6e75;text-decoration:none;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:5px;">
                                                                    <i class="fas fa-eye"></i> Lihat PDF
                                                                </a>
                                                                <a href="<?= esc($slotDownloadUrl) ?>" class="btn-download" style="padding:5px 12px;border-radius:6px;background:#059669;color:#fff;text-decoration:none;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:5px;">
                                                                    <i class="fas fa-download"></i> Unduh PDF
                                                                </a>
                                                            </div>
                                                        </div>
                                                        <?php if (!empty($slotRiwayatPdf)): ?>
                                                            <details style="margin-bottom:10px;font-size:11.5px;color:#475569;">
                                                                <summary style="cursor:pointer;font-weight:600;">Riwayat versi PDF sebelumnya (<?= count($slotRiwayatPdf) ?>)</summary>
                                                                <div style="margin-top:6px;display:flex;flex-direction:column;gap:4px;">
                                                                    <?php foreach ($slotRiwayatPdf as $rvOp): ?>
                                                                        <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;padding:4px 8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">
                                                                            <span>V<?= (int)($rvOp['versi'] ?? 1) ?> &mdash; <?= esc($rvOp['original_name'] ?? 'Dokumen.pdf') ?> <em>(Digantikan)</em></span>
                                                                            <?php if (!empty($rvOp['id'])): ?>
                                                                                <a href="kia.php?action=view_dokumen&amp;id_dokumen=<?= (int)$rvOp['id'] ?>&amp;ext=.pdf" target="_blank" rel="noopener" style="color:#0b6e75;font-weight:600;">Lihat</a>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </details>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <div>
                                                        <label for="<?= $slotInputId ?>" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">
                                                            <?= $slotHasExisting ? 'Ganti PDF untuk ' . esc($slotNama) . ' (Opsional):' : 'Unggah PDF KIA untuk ' . esc($slotNama) . ':' ?>
                                                            <?php if (!$slotHasExisting): ?>
                                                                <span class="kiaPdfWajibTag" style="color:#dc2626;display:<?= $currStatusKia === 'selesai' ? 'inline' : 'none' ?>;">* (Wajib untuk Selesai)</span>
                                                            <?php endif; ?>
                                                        </label>
                                                        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;">
                                                            <input type="file"
                                                                   name="dokumen_kia_pdf_anak[<?= $slotAnakId ?>]"
                                                                   id="<?= $slotInputId ?>"
                                                                   class="kia-anak-pdf-input"
                                                                   data-preview-target="preview_<?= $slotInputId ?>"
                                                                   accept="application/pdf,.pdf"
                                                                   style="font-size:12.5px;padding:7px;background:#fff;border:1px solid #cbd5e1;border-radius:6px;width:100%;max-width:380px;">
                                                            <button type="button"
                                                                    class="btn-clear-kia-file"
                                                                    data-target-input="<?= $slotInputId ?>"
                                                                    style="display:none;padding:6px 10px;border-radius:6px;border:1px solid #fca5a5;background:#fef2f2;color:#b91c1c;font-size:11.5px;font-weight:600;cursor:pointer;">
                                                                Hapus Pilihan
                                                            </button>
                                                        </div>
                                                        <div id="preview_<?= $slotInputId ?>" style="margin-top:5px;font-size:12px;color:#0b6e75;font-weight:600;display:none;"></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php elseif ($hasExistingPdf && $latestPdf): ?>
                                        <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                                            <div style="display:flex;align-items:center;gap:10px;">
                                                <i class="fas fa-file-pdf" style="font-size:24px;color:#dc2626;"></i>
                                                <div>
                                                    <strong style="display:block;font-size:13px;color:#0f172a;"><?= esc($latestPdf['original_name'] ?? 'Dokumen_Hasil_KIA.pdf') ?></strong>
                                                    <span style="font-size:11.5px;color:#64748b;">
                                                        <?= number_format(((int)($latestPdf['file_size'] ?? 0)) / 1024, 1) ?> KB
                                                        <?php if (!empty($latestPdf['uploaded_at'])): ?>
                                                            &bull; Diunggah: <?= esc(formatDate($latestPdf['uploaded_at'])) ?>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <a href="kia.php?action=download_pdf&amp;id=<?= (int)$detail_data['id'] ?>&amp;download=1" class="btn-download" style="padding:6px 14px;border-radius:6px;background:#059669;color:#fff;text-decoration:none;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:5px;">
                                                <i class="fas fa-download"></i> Unduh PDF
                                            </a>
                                        </div>
                                        <div>
                                            <label for="dokumen_kia_pdf" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;">
                                                Ganti / Perbarui Berkas PDF (Opsional jika ingin memperbarui file):
                                            </label>
                                            <input type="file" name="dokumen_kia_pdf" id="dokumen_kia_pdf" accept="application/pdf,.pdf" style="font-size:12.5px;padding:6px;background:#fff;border:1px solid #cbd5e1;border-radius:6px;width:100%;max-width:420px;">
                                        </div>
                                    <?php else: ?>
                                        <div>
                                            <label for="dokumen_kia_pdf" style="display:block;font-size:12.5px;font-weight:700;color:#1e293b;margin-bottom:5px;">
                                                Pilih Berkas PDF Hasil KIA: <span id="kiaPdfWajibTag" style="color:#dc2626;display:<?= $currStatusKia === 'selesai' ? 'inline' : 'none' ?>;">* (Wajib jika status Selesai)</span>
                                            </label>
                                            <input type="file" name="dokumen_kia_pdf" id="dokumen_kia_pdf" accept="application/pdf,.pdf" style="font-size:12.5px;padding:8px;background:#fff;border:1.5px dashed #94a3b8;border-radius:8px;width:100%;max-width:420px;">
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Tombol Simpan Terpadu -->
                                <div style="display:flex;align-items:center;gap:12px;margin-top:4px;">
                                    <button type="submit" class="update-btn" style="padding:10px 24px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:13.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                                        <i class="fas fa-save"></i> Simpan Status &amp; Dokumen
                                    </button>
                                    <small style="color:#64748b;font-size:12px;">Pilihan status dan berkas PDF disimpan bersamaan.</small>
                                </div>
                            </form>
                        </div>
                    <!-- Panel Laporan Kesalahan Dokumen KIA & Percakapan Pemohon -->
                    <?php
                    $kiaLaporanListOp = (new \Siprima\Models\KiaModel($koneksi))->getLaporanKesalahan((int)$detail_data['id']);
                    $lapStatusLabelsOp = [
                        'menunggu_koreksi' => 'Dilaporkan',
                        'dilaporkan' => 'Dilaporkan',
                        'sedang_diperiksa' => 'Sedang Diperiksa',
                        'dalam_perbaikan' => 'Dalam Perbaikan',
                        'selesai_dikoreksi' => 'Selesai',
                        'selesai' => 'Selesai',
                    ];
                    ?>
                    <?php
                    $lapKategoriLabelsOp = [
                        'foto_anak_tertukar' => 'Foto anak tertukar',
                        'foto_tertukar' => 'Foto anak tertukar',
                        'identitas_anak_tertukar' => 'Identitas anak tertukar',
                        'dokumen_salah_anak' => 'Identitas / PDF anak tertukar',
                        'data_tidak_sesuai' => 'Data pada dokumen tidak sesuai',
                        'identitas_tidak_sesuai' => 'Data pada dokumen tidak sesuai',
                        'kualitas_cetak' => 'Data pada dokumen tidak sesuai',
                        'kesalahan_lainnya' => 'Kesalahan lainnya',
                    ];
                    ?>
                    <div id="kiaLaporanKesalahanPanel" class="status-form-container" style="margin-top:20px;border-left:4px solid #b91c1c;">
                        <h3 style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                            <span>⚠️ Laporan Kesalahan Dokumen KIA &amp; Percakapan Pemohon (<?= count($kiaLaporanListOp) ?>)</span>
                        </h3>
                        <p style="font-size:12px;color:#475569;margin:0 0 12px 0;">Permohonan utama tetap berstatus <strong>Selesai</strong>. Anda dapat membalas laporan, memperbarui status penanganan, dan mengunggah PDF pengganti khusus untuk anak yang dilaporkan tanpa mengubah PDF anak lainnya.</p>
                        <?php if (empty($kiaLaporanListOp)): ?>
                            <p style="font-size:13px;color:#64748b;margin:0;">Belum ada laporan kesalahan dokumen dari pemohon pada permohonan ini.</p>
                        <?php else: ?>
                            <div style="display:flex;flex-direction:column;gap:14px;">
                                <?php foreach ($kiaLaporanListOp as $lapOp):
                                    $lapIdOp = (int)$lapOp['id'];
                                    $lapStRaw = strtolower((string)($lapOp['status'] ?? 'dilaporkan'));
                                    $lapStLblOp = $lapStatusLabelsOp[$lapStRaw] ?? ucfirst($lapStRaw);
                                    $lapAnakTag = !empty($lapOp['nama_anak']) ? ('PDF KIA — ' . $lapOp['nama_anak']) : 'Dokumen KIA';
                                    $lapKatKey = strtolower((string)($lapOp['kategori_kesalahan'] ?? 'kesalahan_lainnya'));
                                    $lapKatLabel = $lapKategoriLabelsOp[$lapKatKey] ?? ucwords(str_replace('_', ' ', $lapKatKey));
                                    $lapActivePdf = is_array($lapOp['active_pdf'] ?? null) ? $lapOp['active_pdf'] : null;
                                    $hasUpdatedChildPdf = $lapActivePdf && !empty($lapActivePdf['id']) && (int)$lapActivePdf['id'] !== (int)($lapOp['id_dokumen'] ?? 0);
                                ?>
                                <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:14px;">
                                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                                        <div>
                                            <div style="font-size:11.5px;color:#475569;margin-bottom:3px;">
                                                Permohonan <strong>#<?= (int)$detail_data['id'] ?></strong> &bull; Pemohon: <strong><?= esc($lapOp['nama_pelapor'] ?? ($detail_data['nama_pemohon'] ?? '-')) ?></strong>
                                            </div>
                                            <strong style="font-size:13.5px;color:#0f172a;"><?= esc($lapAnakTag) ?></strong>
                                            <span style="margin-left:6px;font-size:11.5px;background:#f1f5f9;color:#334155;padding:2px 8px;border-radius:999px;"><?= esc($lapKatLabel) ?></span>
                                            <?php if (!empty($lapOp['nama_file_dokumen'])): ?>
                                                <div style="font-size:11.5px;color:#64748b;margin-top:4px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                                    <span>Dokumen dilaporkan: <strong><?= esc($lapOp['nama_file_dokumen']) ?></strong> (V<?= (int)($lapOp['versi_dokumen'] ?? 1) ?>)</span>
                                                    <?php if (!empty($lapOp['id_dokumen'])): ?>
                                                        <a href="kia.php?action=view_dokumen&amp;id_dokumen=<?= (int)$lapOp['id_dokumen'] ?>&amp;ext=.pdf" target="_blank" rel="noopener" style="font-size:11.5px;font-weight:700;color:#0b6e75;text-decoration:underline;">&#128065; Lihat PDF Dilaporkan</a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($hasUpdatedChildPdf): ?>
                                                <div style="font-size:11.5px;color:#065f46;margin-top:4px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                                    <span>&#10003; PDF Pengganti Aktif: <strong><?= esc($lapActivePdf['original_name'] ?? 'Dokumen_KIA.pdf') ?></strong> (V<?= (int)($lapActivePdf['versi'] ?? 1) ?>)</span>
                                                    <a href="kia.php?action=view_dokumen&amp;id_dokumen=<?= (int)$lapActivePdf['id'] ?>&amp;ext=.pdf" target="_blank" rel="noopener" style="font-size:11.5px;font-weight:700;color:#059669;text-decoration:underline;">&#128065; Lihat PDF Pengganti Terbaru</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <span style="font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:999px;background:#fef3c7;color:#92400e;">Status Laporan: <?= esc($lapStLblOp) ?></span>
                                    </div>

                                    <!-- Riwayat Percakapan -->
                                    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px;max-height:240px;overflow-y:auto;padding:8px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
                                        <?php if (!empty($lapOp['pesan_list'])): ?>
                                            <?php foreach ($lapOp['pesan_list'] as $msgOp):
                                                $isOpMsg = in_array($msgOp['role_pengirim'] ?? '', ['operator', 'admin'], true);
                                            ?>
                                                <div style="padding:7px 10px;border-radius:7px;font-size:12.5px;<?= $isOpMsg ? 'background:#dceae7;border-left:3px solid #0b6e75;' : 'background:#ffffff;border:1px solid #cbd5e1;' ?>">
                                                    <div style="font-weight:700;font-size:11px;color:#0f172a;"><?= esc($msgOp['nama_pengirim'] ?? '') ?> (<?= $isOpMsg ? 'Operator' : 'Pemohon' ?>) &bull; <span style="font-weight:400;color:#64748b;"><?= esc($msgOp['created_at'] ?? '') ?></span></div>
                                                    <div style="margin-top:2px;color:#1e293b;"><?= nl2br(esc($msgOp['isi_pesan'] ?? '')) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div style="font-size:12.5px;color:#1e293b;"><strong>Pemohon:</strong> <?= nl2br(esc($lapOp['keterangan_kesalahan'] ?? '')) ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Form Tanggapan Operator, Status Penanganan & Penggantian PDF Anak Terkait -->
                                    <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;" onsubmit="const btn=this.querySelector('button[type=submit]');if(btn){btn.disabled=true;btn.textContent='Menyimpan...';}">
                                        <?= csrf_input(); ?>
                                        <input type="hidden" name="action" value="kia_balas_laporan_kesalahan">
                                        <input type="hidden" name="id_laporan" value="<?= $lapIdOp ?>">
                                        <input type="hidden" name="id_pengajuan" value="<?= (int)$detail_data['id'] ?>">
                                        <input type="hidden" name="id_anak" value="<?= (int)($lapOp['id_anak'] ?? 0) ?>">
                                        <div>
                                            <textarea name="isi_pesan" rows="2" placeholder="Tulis tanggapan untuk pemohon..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12.5px;background:#fff;"></textarea>
                                        </div>
                                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between;">
                                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                                <label style="font-size:11.5px;font-weight:600;color:#475569;">Status Penanganan:</label>
                                                <select name="status_laporan" style="padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;background:#fff;">
                                                    <option value="dilaporkan" <?= $lapStRaw === 'dilaporkan' || $lapStRaw === 'menunggu_koreksi' ? 'selected' : '' ?>>Dilaporkan</option>
                                                    <option value="sedang_diperiksa" <?= $lapStRaw === 'sedang_diperiksa' ? 'selected' : '' ?>>Sedang Diperiksa</option>
                                                    <option value="dalam_perbaikan" <?= $lapStRaw === 'dalam_perbaikan' ? 'selected' : '' ?>>Dalam Perbaikan</option>
                                                    <option value="selesai" <?= $lapStRaw === 'selesai' || $lapStRaw === 'selesai_dikoreksi' ? 'selected' : '' ?>>Selesai</option>
                                                </select>
                                            </div>
                                            <button type="submit" style="padding:8px 14px;background:#0b6e75;color:#fff;border:0;border-radius:7px;font-size:12.5px;font-weight:600;cursor:pointer;">Kirim Tanggapan &amp; Simpan</button>
                                        </div>
                                        <div style="padding-top:6px;border-top:1px dashed #cbd5e1;">
                                            <label style="display:block;font-size:11.5px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                                                Unggah PDF KIA Pengganti khusus <?= esc($lapOp['nama_anak'] ?? 'Anak Ini') ?> (Opsional &bull; PDF anak lain tidak berubah):
                                            </label>
                                            <input type="file" name="pdf_pengganti_anak" accept="application/pdf,.pdf" style="font-size:12px;padding:5px;background:#fff;border:1px solid #cbd5e1;border-radius:6px;width:100%;max-width:420px;">
                                        </div>
                                    </form>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <!-- Form Update Status (Non-KIA) -->
                    <div class="status-form-container">
                        <?php
                        if (!isset($tracking_panel_html)) {
                            ob_start();
                            include __DIR__ . '/service_tracking_form.php';
                            $tracking_panel_html = ob_get_clean();
                        }
                        ?>
                        <h3>Ubah Status Pengajuan</h3>
                        <form method="POST" class="status-form" enctype="multipart/form-data">
                            <?= csrf_input(); ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="expected_status" value="<?= esc($detail_data['status'] ?? '') ?>">
                            <input type="hidden" name="history_version" value="<?= (int)$trackingVersion ?>">
                            <input type="hidden" name="table" value="<?= esc($_GET['table']) ?>">
                            <input type="hidden" name="id" value="<?= $detail_data['id'] ?>">
                            <select name="status" class="status-select" onchange="toggleAlasan(this)">
                                <option value="diajukan" <?= ($detail_data['status'] ?? '') === 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                                <option value="diproses" <?= ($detail_data['status'] ?? '') === 'diproses' ? 'selected' : '' ?>>Diproses</option>
                                <option value="selesai" <?= ($detail_data['status'] ?? '') === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="ditolak" <?= ($detail_data['status'] ?? '') === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                            </select>
                            <textarea name="alasan" rows="3"></textarea>
                            <?php if (dokumen_hasil_is_enabled()): ?>
                            <div class="dokumen-hasil-upload" data-dokumen-hasil-upload style="display:none; margin-top: 12px;">
                                <label for="dokumen_hasil" style="display:block; font-weight:600; margin-bottom:6px;">Upload Dokumen Hasil (PDF)</label>
                                <input type="file" id="dokumen_hasil" name="dokumen_hasil[]" accept="application/pdf,.pdf" multiple>
                                <small style="display:block; color:#666; margin-top:6px;">Bisa upload lebih dari 1 file. Hanya PDF, maksimal 1MB per file.</small>
                            </div>
                            <?php endif; ?>
                            <button type="submit" class="update-btn">Update Status</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <section class="operator-service-activity" aria-label="Riwayat pelayanan dan komentar pemohon">
                    <div class="operator-service-history">
                        <?= $tracking_panel_html ?>
                    </div>
                    <div class="dokumen-section operator-service-comments">
                        <h3>Komentar / Klarifikasi Pemohon</h3>
                        <div id="operatorKomentarList" style="display:flex; flex-direction:column; gap:10px;">
                            <?php if (!empty($detail_komentar_permohonan)): ?>
                                <?php foreach ($detail_komentar_permohonan as $komentar): ?>
                                    <div style="border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:#fff;">
                                        <div style="display:flex; justify-content:space-between; gap:12px; margin-bottom:6px; color:#6b7280; font-size:12px;">
                                            <span>
                                                <strong style="color:#374151;"><?= esc($komentar['nama_user'] ?? '-') ?></strong>
                                                (<?= esc(str_replace('_', ' ', $komentar['user_role'] ?? '-')) ?>)
                                            </span>
                                            <span><?= esc($komentar['created_at_formatted'] ?? '-') ?></span>
                                        </div>
                                        <div style="white-space:pre-wrap; color:#1f2937; font-size:14px;"><?= esc($komentar['komentar'] ?? '') ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p id="operatorKomentarEmpty" style="color: #666; font-style: italic;">Belum ada komentar atau klarifikasi dari pemohon.</p>
                            <?php endif; ?>
                        </div>

                        <?php
                        $komentar_parent_id = 0;
                        foreach (array_reverse($detail_komentar_permohonan) as $komentar_parent) {
                            if (($komentar_parent['user_role'] ?? '') !== 'operator') {
                                $komentar_parent_id = (int)($komentar_parent['id'] ?? 0);
                                break;
                            }
                        }
                        ?>
                            <form method="POST" id="operatorKomentarForm" style="margin-top:16px; padding-top:16px; border-top:1px solid #e5e7eb;">
                                <?= csrf_input(); ?>
                                <input type="hidden" name="action" value="reply_komentar_permohonan">
                                <input type="hidden" name="table" value="<?= esc($detail_table) ?>">
                                <input type="hidden" name="permohonan_id" value="<?= (int)$detail_data['id'] ?>">
                                <input type="hidden" name="parent_id" value="<?= $komentar_parent_id ?>">
                                <label for="balasan_komentar" style="display:block; font-weight:600; margin-bottom:6px;">Balas Komentar Pemohon</label>
                                <textarea id="balasan_komentar" name="komentar" rows="3" maxlength="1000" required
                                    placeholder="Tuliskan balasan atau klarifikasi untuk pemohon..."
                                    style="width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; resize:vertical;"></textarea>
                                <small style="display:block; color:#6b7280; margin-top:5px;">Maksimal 1000 karakter. Balasan hanya berupa teks.</small>
                                <button type="submit" class="update-btn" style="margin-top:10px;">
                                    <i class="fas fa-reply"></i> Kirim Balasan
                                </button>
                            </form>
                            <script>
                            (() => {
                                const pollUrl = new URL(window.location.href);
                                pollUrl.searchParams.set('komentar_poll', '1');
                                pollUrl.searchParams.set('permohonan_id', '<?= (int)$detail_data['id'] ?>');
                                pollUrl.searchParams.set('table', '<?= esc($detail_table) ?>');
                                let lastCommentId = <?= !empty($detail_komentar_permohonan) ? (int)($detail_komentar_permohonan[array_key_last($detail_komentar_permohonan)]['id'] ?? 0) : 0 ?>;

                                const escapeComment = (value) => String(value ?? '')
                                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

                                const pollComments = () => {
                                    if (document.hidden) return;
                                    fetch(pollUrl.toString(), {
                                        headers: { 'Accept': 'application/json' },
                                        credentials: 'same-origin',
                                        cache: 'no-store'
                                    })
                                    .then(response => response.ok ? response.json() : Promise.reject())
                                    .then(data => {
                                        if (!data.success || !Array.isArray(data.komentar)) return;
                                        const newLastId = Number(data.last_comment_id || 0);
                                        if (newLastId === lastCommentId) return;

                                        const list = document.getElementById('operatorKomentarList');
                                        if (list) {
                                            list.innerHTML = data.komentar.map(item => `
                                                <div style="border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:${item.user_role === 'operator' ? '#eff6ff' : '#fff'};">
                                                    <div style="display:flex; justify-content:space-between; gap:12px; margin-bottom:6px; color:#6b7280; font-size:12px;">
                                                        <span><strong style="color:#374151;">${escapeComment(item.nama_user || '-')}</strong> (${escapeComment(String(item.user_role || '-').replace(/_/g, ' '))})</span>
                                                        <span>${escapeComment(item.created_at_formatted || '-')}</span>
                                                    </div>
                                                    <div style="white-space:pre-wrap; color:#1f2937; font-size:14px;">${escapeComment(item.komentar || '')}</div>
                                                </div>
                                            `).join('');
                                        }

                                        const latestPemohon = [...data.komentar].reverse().find(item => item.user_role !== 'operator');
                                        const parentInput = document.querySelector('#operatorKomentarForm input[name="parent_id"]');
                                        if (parentInput && latestPemohon) parentInput.value = String(latestPemohon.id || 0);
                                        lastCommentId = newLastId;
                                    })
                                    .catch(() => {});
                                };

                                window.setInterval(pollComments, 25000);
                            })();
                            </script>
                    </div>
                    </section>


                </div>
                
            <?php else: ?>
                <!-- Halaman Utama (Daftar Pengajuan) -->
                <!-- Wilayah Tugas Info -->
                <div class="wilayah-info">
                    <div class="wilayah-icon">📍</div>
                    <div class="wilayah-text">
                        <h4>Wilayah Tugas Anda</h4>
                        <p><?= esc($wilayah_tugas) ?></p>
                    </div>
                </div>

                <!-- Statistik Cards -->
                <div class="stats-cards">
                    <div class="stat-card total">
                        <h3>Total Pengajuan</h3>
                        <div class="count"><?= $stats['total'] ?></div>
                    </div>
                    <div class="stat-card diajukan">
                        <h3>Diajukan</h3>
                        <div class="count"><?= $stats['diajukan'] ?></div>
                    </div>
                    <div class="stat-card diproses">
                        <h3>Sedang Diproses</h3>
                        <div class="count"><?= $stats['diproses'] ?></div>
                    </div>
                    <div class="stat-card selesai">
                        <h3>Selesai</h3>
                        <div class="count"><?= $stats['selesai'] ?></div>
                    </div>
                    <div class="stat-card ditolak">
                        <h3>Ditolak</h3>
                        <div class="count"><?= $stats['ditolak'] ?></div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <h3>Filter Pengajuan</h3>
                    <form method="GET" class="filters">
                            <input type="hidden" name="page" value="1">
                        <div class="filter-group">
                            <label for="search_nama">Cari Nama</label>
                            <input
                                type="text"
                                name="search_nama"
                                id="search_nama"
                                value="<?= esc($_GET['search_nama'] ?? '') ?>"
                                placeholder="Nama pemohon atau judul"
                            >
                        </div>
                        <div class="filter-group">
                            <label for="type">Jenis Layanan</label>
                            <select name="type" id="type">
                                <option value="">Semua Jenis</option>
                                <option value="Kartu Keluarga" <?= ($_GET['type'] ?? '') === 'Kartu Keluarga' ? 'selected' : '' ?>>Kartu Keluarga</option>
                                <option value="Akta Kelahiran" <?= ($_GET['type'] ?? '') === 'Akta Kelahiran' ? 'selected' : '' ?>>Akta Kelahiran</option>
                                <option value="Akta Kematian" <?= ($_GET['type'] ?? '') === 'Akta Kematian' ? 'selected' : '' ?>>Akta Kematian</option>
                                <option value="Pindah Alamat" <?= ($_GET['type'] ?? '') === 'Pindah Alamat' ? 'selected' : '' ?>>Pindah Alamat</option>
                                <option value="Pindah Masuk" <?= ($_GET['type'] ?? '') === 'Pindah Masuk' ? 'selected' : '' ?>>Pindah Masuk</option>
                                <option value="KIA" <?= in_array($_GET['type'] ?? '', ['KIA', 'Kartu Identitas Anak'], true) ? 'selected' : '' ?>>Kartu Identitas Anak (KIA)</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="kecamatan">Kecamatan</label>
                            <select name="kecamatan" id="kecamatan">
                                <option value="">Semua Kecamatan</option>
                                <?php foreach (array_unique($allowed_kecamatan_names) as $nama_kecamatan): ?>
                                    <option value="<?= esc($nama_kecamatan) ?>" <?= ($_GET['kecamatan'] ?? '') === $nama_kecamatan ? 'selected' : '' ?>>
                                        <?= esc($nama_kecamatan) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select name="status" id="status">
                                <option value="">Semua Status</option>
                                <option value="diajukan" <?= ($_GET['status'] ?? '') === 'diajukan' ? 'selected' : '' ?>>Diajukan</option>
                                <option value="diproses" <?= ($_GET['status'] ?? '') === 'diproses' ? 'selected' : '' ?>>Diproses</option>
                                <option value="selesai" <?= ($_GET['status'] ?? '') === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="ditolak" <?= ($_GET['status'] ?? '') === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="kategori_antrean">Kategori Antrean</label>
                            <select name="kategori_antrean" id="kategori_antrean">
                                <option value="">Semua Kategori Antrean</option>
                                <option value="pengajuan_baru" <?= ($_GET['kategori_antrean'] ?? '') === 'pengajuan_baru' ? 'selected' : '' ?>>🆕 Pengajuan Baru</option>
                                <option value="pengajuan_perbaikan" <?= in_array($_GET['kategori_antrean'] ?? '', ['pengajuan_perbaikan', 'pengajuan_ulang'], true) ? 'selected' : '' ?>>🔄 Pengajuan Perbaikan (Hasil Koreksi / Edit)</option>
                                <option value="perlu_verifikasi" <?= ($_GET['kategori_antrean'] ?? '') === 'perlu_verifikasi' ? 'selected' : '' ?>>🔍 Perlu Verifikasi / Proses</option>
                                <option value="perlu_perbaikan" <?= ($_GET['kategori_antrean'] ?? '') === 'perlu_perbaikan' ? 'selected' : '' ?>>⚠️ Perlu Perbaikan Berkas (Ditolak)</option>
                                <option value="antrean_cetak" <?= ($_GET['kategori_antrean'] ?? '') === 'antrean_cetak' ? 'selected' : '' ?>>🖨️ Permohonan Cetak Fisik KIA</option>
                                <option value="selesai" <?= ($_GET['kategori_antrean'] ?? '') === 'selesai' ? 'selected' : '' ?>>✅ Selesai</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn-filter">Terapkan Filter</button>
                        <a href="proses_pengajuan.php" class="btn-reset">Reset Filter</a>
                    </form>
                </div>

                <?php
                $currTabKat = (string)($_GET['kategori_antrean'] ?? '');
                $baseTabParams = $_GET;
                unset($baseTabParams['kategori_antrean'], $baseTabParams['page']);
                $mkTabUrl = static function (string $kat) use ($baseTabParams): string {
                    $q = $baseTabParams;
                    if ($kat !== '') $q['kategori_antrean'] = $kat;
                    $q['page'] = 1;
                    return 'proses_pengajuan.php?' . http_build_query($q);
                };
                ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;" role="tablist" aria-label="Pemisahan Antrean Permohonan">
                    <a href="<?= esc($mkTabUrl('')) ?>" style="padding:9px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;border:1px solid <?= $currTabKat === '' ? '#0b6e75' : '#cbd5e1' ?>;background:<?= $currTabKat === '' ? '#0b6e75' : '#ffffff' ?>;color:<?= $currTabKat === '' ? '#ffffff' : '#334155' ?>;">📋 Semua Antrean</a>
                    <a href="<?= esc($mkTabUrl('pengajuan_baru')) ?>" style="padding:9px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;border:1px solid <?= $currTabKat === 'pengajuan_baru' ? '#0b6e75' : '#cbd5e1' ?>;background:<?= $currTabKat === 'pengajuan_baru' ? '#0b6e75' : '#ffffff' ?>;color:<?= $currTabKat === 'pengajuan_baru' ? '#ffffff' : '#0369a1' ?>;">🆕 Tab Permohonan Baru</a>
                    <a href="<?= esc($mkTabUrl('pengajuan_perbaikan')) ?>" style="padding:9px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;border:1px solid <?= in_array($currTabKat, ['pengajuan_perbaikan', 'pengajuan_ulang'], true) ? '#b45309' : '#cbd5e1' ?>;background:<?= in_array($currTabKat, ['pengajuan_perbaikan', 'pengajuan_ulang'], true) ? '#b45309' : '#ffffff' ?>;color:<?= in_array($currTabKat, ['pengajuan_perbaikan', 'pengajuan_ulang'], true) ? '#ffffff' : '#92400e' ?>;">🔄 Tab Permohonan Revisi</a>
                </div>

                <!-- Tabel Pengajuan -->
                <div class="table-section">
                    <h2>
                        Daftar Pengajuan untuk Diproses
                        <span class="table-count">Total: <?= $total_submissions ?> data | Halaman <?= $current_page ?> dari <?= $total_pages ?></span>
                    </h2>
                    
                    <?php if (empty($submissions)): ?>
                        <div class="empty-state">
                            <i>📭</i>
                            <h4>Tidak ada pengajuan ditemukan</h4>
                            <p>Tidak ada permohonan dari wilayah tugas Anda dengan filter yang dipilih.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Jenis Layanan</th>
                                    <th>Nama Pemohon</th>
                                    <th>Judul</th>
                                    <th>Kecamatan</th>
                                    <th>Desa</th>
                                    <th>Status Saat Ini</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tabel-permohonan-konten">
                                <?php 
                                $no = ($current_page - 1) * $per_page + 1;
                                foreach ($submissions as $row): 
                                    $jenis_class = match($row['jenis']) {
                                        'Kartu Keluarga' => 'jenis-kk',
                                        'Akta Kelahiran' => 'jenis-akta-lahir',
                                        'Akta Kematian' => 'jenis-akta-mati',
                                        'Pindah Alamat' => 'jenis-pindah-alamat',
                                        'KIA', 'Kartu Identitas Anak' => 'jenis-kia',
                                        'Pindah Masuk' => 'jenis-pindah-masuk',
                                        default => ''
                                    };
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <div>
                                                <small>Pengajuan:</small> <?= formatDate($row['created_at'] ?? '') ?><br>
                                                <small>Update:</small> <?= formatDate($row['updated_at'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="jenis-badge <?= $jenis_class ?>">
                                                <?= esc($row['jenis']) ?>
                                            </span>
                                            <?php if (($row['table_name'] ?? '') === 'tb_pengajuan_kia'): ?>
                                                <?php
                                                $rVer = (int)($row['versi_aktif'] ?? 1);
                                                $rKat = strtolower((string)($row['kategori_antrian'] ?? 'reguler'));
                                                $rCetak = (string)($row['status_cetak_kia'] ?? '');
                                                ?>
                                                <?php if ($rVer > 1 || str_contains($rKat, 'perbaikan')): ?>
                                                    <div style="margin-top:4px;"><span style="display:inline-block;padding:2px 7px;border-radius:999px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:11px;font-weight:700;">🔄 Revisi (V<?= $rVer ?>) &bull; <?= (int)($row['jumlah_anak'] ?? 0) ?> Anak</span></div>
                                                    <?php if (!empty($row['resubmitted_at'])): ?>
                                                        <div style="font-size:10.5px;color:#92400e;margin-top:2px;">Revisi: <?= esc(formatDate($row['resubmitted_at'])) ?></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div style="margin-top:4px;"><span style="display:inline-block;padding:2px 7px;border-radius:999px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;font-size:11px;font-weight:600;">🆕 Baru (V1) &bull; <?= (int)($row['jumlah_anak'] ?? 0) ?> Anak</span></div>
                                                <?php endif; ?>
                                                <?php if ($rCetak !== ''): ?>
                                                    <div style="margin-top:4px;"><span style="display:inline-block;padding:2px 7px;border-radius:999px;background:#dcfce7;color:#166534;border:1px solid #86efac;font-size:11px;font-weight:700;">🖨️ Cetak: <?= esc(ucwords(str_replace('_', ' ', $rCetak))) ?></span></div>
                                                <?php endif; ?>
                                                <?php if ((int)($row['laporan_aktif_count'] ?? 0) > 0): ?>
                                                    <div style="margin-top:4px;"><span style="display:inline-block;padding:2px 7px;border-radius:999px;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;font-size:11px;font-weight:700;">⚠️ <?= (int)$row['laporan_aktif_count'] ?> Laporan Kesalahan</span></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= esc($row['nama_user'] ?? '-') ?></td>
                                        <td><?= esc($row['title'] ?? '-') ?></td>
                                        <td><?= esc($row['nama_kecamatan'] ?? '-') ?></td>
                                        <td><?= esc($row['nama_desa'] ?? '-') ?></td>
                                        <td>
                                            <?php
                                            $status = strtolower($row['status']);
                                            $badge_class = match($status) {
                                                'selesai', 'disetujui' => 'status-selesai',
                                                'ditolak' => 'status-ditolak',
                                                'diproses' => 'status-diproses',
                                                default => 'status-diajukan'
                                            };
                                            ?>
                                            <span class="status-badge <?= $badge_class ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                            <small style="display:block;margin-top:6px">Status diperbarui: <?= esc(serviceTrackingUpdatedLabel($koneksi, $row['table_name'], (int)$row['id'])) ?></small>
                                        </td>
                                        <td>
                                            <a href="?detail_id=<?= $row['id'] ?>&table=<?= $row['table_name'] ?>&page=<?= $current_page ?>&type=<?= urlencode($_GET['type'] ?? '') ?>&status=<?= urlencode($_GET['status'] ?? '') ?>&kecamatan=<?= urlencode($_GET['kecamatan'] ?? '') ?>&search_nama=<?= urlencode($_GET['search_nama'] ?? '') ?>&kategori_antrean=<?= urlencode($_GET['kategori_antrean'] ?? '') ?>" class="btn-detail">Detail</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                Menampilkan <?= count($submissions) ?> dari <?= $total_submissions ?> data
                                (Halaman <?= $current_page ?> dari <?= $total_pages ?>)
                            </div>
                            <div class="pagination-links">
                                <?php 
                                // Tombol Previous
                                if ($current_page > 1): 
                                    $prev_params = http_build_query(array_merge($_GET, ['page' => $current_page - 1]));
                                ?>
                                    <a href="?<?= $prev_params ?>" class="pagination-link">← Prev</a>
                                <?php else: ?>
                                    <span class="pagination-link disabled">← Prev</span>
                                <?php endif; ?>
                                
                                <?php 
                                // Tampilkan 5 halaman di sekitar current page
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                // Jika di awal, tambah halaman di akhir
                                if ($start_page == 1) {
                                    $end_page = min($total_pages, $start_page + 4);
                                }
                                
                                // Jika di akhir, tambah halaman di awal
                                if ($end_page == $total_pages) {
                                    $start_page = max(1, $end_page - 4);
                                }
                                
                                // Tampilkan halaman pertama jika tidak termasuk
                                if ($start_page > 1): 
                                    $first_params = http_build_query(array_merge($_GET, ['page' => 1]));
                                ?>
                                    <a href="?<?= $first_params ?>" class="pagination-link">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span class="pagination-link disabled">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): 
                                    $page_params = http_build_query(array_merge($_GET, ['page' => $i]));
                                ?>
                                    <a href="?<?= $page_params ?>" class="pagination-link <?= $i == $current_page ? 'active' : '' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php 
                                // Tampilkan halaman terakhir jika tidak termasuk
                                if ($end_page < $total_pages): 
                                    $last_params = http_build_query(array_merge($_GET, ['page' => $total_pages]));
                                ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="pagination-link disabled">...</span>
                                    <?php endif; ?>
                                    <a href="?<?= $last_params ?>" class="pagination-link"><?= $total_pages ?></a>
                                <?php endif; ?>
                                
                                <?php 
                                // Tombol Next
                                if ($current_page < $total_pages): 
                                    $next_params = http_build_query(array_merge($_GET, ['page' => $current_page + 1]));
                                ?>
                                    <a href="?<?= $next_params ?>" class="pagination-link">Next →</a>
                                <?php else: ?>
                                    <span class="pagination-link disabled">Next →</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- Modal Preview Dokumen -->
<div class="modal-overlay" id="previewModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Preview Dokumen</h3>
            <button type="button" class="btn-cancel" onclick="closePreviewModal()">Tutup</button>
        </div>
        <div class="preview-toolbar" id="imagePreviewToolbar" hidden aria-label="Kontrol tampilan gambar">
            <button type="button" class="preview-tool-btn" onclick="rotatePreviewImage(-90)" title="Putar ke kiri" aria-label="Putar gambar ke kiri">
                <i class="fas fa-rotate-left" aria-hidden="true"></i>
            </button>
            <button type="button" class="preview-tool-btn" onclick="rotatePreviewImage(90)" title="Putar ke kanan" aria-label="Putar gambar ke kanan">
                <i class="fas fa-rotate-right" aria-hidden="true"></i>
            </button>
            <span class="preview-toolbar-separator" aria-hidden="true"></span>
            <button type="button" class="preview-tool-btn" id="previewZoomOutBtn" onclick="zoomPreviewImage(-0.25)" title="Perkecil" aria-label="Perkecil gambar">
                <i class="fas fa-magnifying-glass-minus" aria-hidden="true"></i>
            </button>
            <span class="preview-zoom-label" id="previewZoomLabel" aria-live="polite">100%</span>
            <button type="button" class="preview-tool-btn" id="previewZoomInBtn" onclick="zoomPreviewImage(0.25)" title="Perbesar" aria-label="Perbesar gambar">
                <i class="fas fa-magnifying-glass-plus" aria-hidden="true"></i>
            </button>
        </div>
        <div class="modal-body" id="previewContent">
            Memuat preview...
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
            <button id="cancelLogout" class="btn-cancel">Batal</button>
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
    
    if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
    if (logoutBtn2) logoutBtn2.addEventListener('click', showLogoutModal);
    if (confirmLogout) confirmLogout.addEventListener('click', () => window.location.href = '../logout.php');
    if (cancelLogout) cancelLogout.addEventListener('click', hideLogoutModal);
    
    if (logoutModal) {
        logoutModal.addEventListener('click', function(event) {
            if (event.target === logoutModal) hideLogoutModal();
        });
    }
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && logoutModal.classList.contains('show')) {
            hideLogoutModal();
        }
    });
    
    // Reset to page 1 when changing filter
    const typeFilter = document.getElementById('type');
    if (typeFilter) {
        typeFilter.addEventListener('change', function() {
            document.querySelector('input[name="page"]').value = 1;
        });
    }

    const kecamatanFilter = document.getElementById('kecamatan');
    if (kecamatanFilter) {
        kecamatanFilter.addEventListener('change', function() {
            document.querySelector('input[name="page"]').value = 1;
        });
    }
    
                    const statusFilter = document.getElementById('status');
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            document.querySelector('input[name="page"]').value = 1;
        });
    }

    const searchNamaInput = document.getElementById('search_nama');
    if (searchNamaInput) {
        searchNamaInput.addEventListener('input', function() {
            const pageInput = document.querySelector('input[name="page"]');
            if (pageInput) pageInput.value = 1;
        });
    }
});

let previewImageRotation = 0;
let previewImageScale = 1;
let previewImagePanX = 0;
let previewImagePanY = 0;
let previewImageDragStartX = 0;
let previewImageDragStartY = 0;
let previewImageActivePointer = null;
const previewImageMinScale = 0.5;
const previewImageMaxScale = 3;

function setImagePreviewToolbarVisible(visible) {
    const toolbar = document.getElementById('imagePreviewToolbar');
    if (toolbar) toolbar.hidden = !visible;
}

function updatePreviewImageTransform() {
    const image = document.getElementById('previewImage');
    const zoomLabel = document.getElementById('previewZoomLabel');
    const zoomOutButton = document.getElementById('previewZoomOutBtn');
    const zoomInButton = document.getElementById('previewZoomInBtn');

    if (image) {
        image.style.transform = `translate3d(${previewImagePanX}px, ${previewImagePanY}px, 0) rotate(${previewImageRotation}deg) scale(${previewImageScale})`;
    }
    if (zoomLabel) zoomLabel.textContent = `${Math.round(previewImageScale * 100)}%`;
    if (zoomOutButton) zoomOutButton.disabled = previewImageScale <= previewImageMinScale;
    if (zoomInButton) zoomInButton.disabled = previewImageScale >= previewImageMaxScale;
}

function resetPreviewImageTransform() {
    previewImageRotation = 0;
    previewImageScale = 1;
    previewImagePanX = 0;
    previewImagePanY = 0;
    previewImageActivePointer = null;
    updatePreviewImageTransform();
}

function startPreviewImageDrag(event) {
    if (event.button !== 0 || !document.getElementById('previewImage')) return;

    const stage = event.currentTarget;
    event.preventDefault();
    previewImageActivePointer = event.pointerId;
    previewImageDragStartX = event.clientX - previewImagePanX;
    previewImageDragStartY = event.clientY - previewImagePanY;
    stage.classList.add('is-dragging');
    if (stage.setPointerCapture) stage.setPointerCapture(event.pointerId);
}

function movePreviewImageDrag(event) {
    if (previewImageActivePointer !== event.pointerId) return;

    event.preventDefault();
    previewImagePanX = event.clientX - previewImageDragStartX;
    previewImagePanY = event.clientY - previewImageDragStartY;
    updatePreviewImageTransform();
}

function endPreviewImageDrag(event) {
    if (previewImageActivePointer !== event.pointerId) return;

    const stage = event.currentTarget;
    previewImageActivePointer = null;
    stage.classList.remove('is-dragging');
    if (stage.hasPointerCapture && stage.hasPointerCapture(event.pointerId)) {
        stage.releasePointerCapture(event.pointerId);
    }
}

function bindPreviewImageDragging() {
    const stage = document.getElementById('previewImageStage');
    if (!stage) return;

    stage.addEventListener('pointerdown', startPreviewImageDrag);
    stage.addEventListener('pointermove', movePreviewImageDrag);
    stage.addEventListener('pointerup', endPreviewImageDrag);
    stage.addEventListener('pointercancel', endPreviewImageDrag);
}

function rotatePreviewImage(degrees) {
    if (!document.getElementById('previewImage')) return;
    previewImageRotation = (previewImageRotation + degrees) % 360;
    updatePreviewImageTransform();
}

function zoomPreviewImage(delta) {
    if (!document.getElementById('previewImage')) return;
    previewImageScale = Math.min(
        previewImageMaxScale,
        Math.max(previewImageMinScale, Number((previewImageScale + delta).toFixed(2)))
    );
    updatePreviewImageTransform();
}

function closePreviewModal() {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    if (modal) modal.classList.remove('show');
    if (content) content.innerHTML = 'Memuat preview...';
    setImagePreviewToolbarVisible(false);
    resetPreviewImageTransform();
}

// Preview Dokumen Function (SAMA DENGAN RS)
function previewDokumen(url, ext) {
    const content = document.getElementById('previewContent');
    content.innerHTML = '<p style="text-align:center; color:#666; padding:50px;">Memuat preview dokumen...</p>';

    let previewHTML = '';
    ext = String(ext || '').toLowerCase();
    setImagePreviewToolbarVisible(false);
    resetPreviewImageTransform();

    if (['jpg', 'jpeg', 'jfif', 'jpe', 'png', 'gif'].includes(ext)) {
        setImagePreviewToolbarVisible(true);
        previewHTML = `<div class="preview-image-stage" id="previewImageStage" title="Tahan klik kiri lalu geser untuk memindahkan gambar"><img id="previewImage" src="${url}" alt="Preview dokumen" draggable="false" onerror="this.parentElement.innerHTML='<div style=&quot;padding:30px;text-align:center;color:#dc2626;font-weight:600;&quot;>⚠️ File gambar tidak ditemukan atau gagal dimuat dari server.</div>';"></div>`;
    } else if (ext === 'pdf') {
        setImagePreviewToolbarVisible(false);
        previewHTML = `<div style="margin-bottom:8px;text-align:right;"><a href="${url}" target="_blank" rel="noopener" style="font-size:12.5px;font-weight:600;color:#2563eb;text-decoration:underline;">Buka PDF di Tab Baru ↗</a></div><iframe src="${url}" style="width:100%; height:500px; border:1px solid #cbd5e1; border-radius:8px;" frameborder="0"></iframe>`;
    } else {
        // File lain: buka tab baru
        window.open(url, '_blank');
        return;
    }

    content.innerHTML = previewHTML;
    document.getElementById('previewModal').classList.add('show');
    if (document.getElementById('previewImage')) {
        bindPreviewImageDragging();
        requestAnimationFrame(updatePreviewImageTransform);
    }
}

// Toggle Alasan Function
function toggleAlasan(select) {
    const form = select.closest('form');
    const textarea = form ? form.querySelector('textarea[name="alasan"]') : select.parentElement.querySelector('textarea[name="alasan"]');
    if (textarea) {
        const wajibAlasan = select.value === 'ditolak';
        textarea.style.display = 'block';
        textarea.required = wajibAlasan;
    }

    if (form) {
        const uploadSection = form.querySelector('[data-dokumen-hasil-upload]');
        const uploadInput = form.querySelector('input[name="dokumen_hasil[]"]');
        const canUploadHasil = ['diproses', 'selesai'].includes(select.value);

        if (uploadSection) {
            uploadSection.style.display = canUploadHasil ? 'block' : 'none';
        }
        if (uploadInput) {
            uploadInput.disabled = !canUploadHasil;
            if (!canUploadHasil) {
                uploadInput.value = '';
            }
        }
    }
}

// Toggle Alasan & Tag Wajib KIA
function toggleKiaStatusAlasan(select) {
    const form = select.closest('form');
    if (!form) return;
    const alasanContainer = form.querySelector('#kiaAlasanContainer');
    const alasanInput = form.querySelector('#kiaAlasanInput');
    const pdfWajibTag = form.querySelector('#kiaPdfWajibTag');

    if (alasanContainer) {
        const isDitolak = (select.value === 'ditolak');
        alasanContainer.style.display = isDitolak ? 'block' : 'none';
        if (alasanInput) {
            alasanInput.required = isDitolak;
        }
    }

    if (pdfWajibTag) {
        pdfWajibTag.style.display = (select.value === 'selesai') ? 'inline' : 'none';
    }
}

// Auto-hide toast notifications
document.addEventListener('DOMContentLoaded', function() {
    const toasts = document.querySelectorAll('.toast');
    toasts.forEach(toast => {
        setTimeout(() => {
            toast.remove();
        }, 5000);
    });
    
    // Set textarea display berdasarkan status saat ini
    const statusSelect = document.querySelector('select[name="status"]');
    if (statusSelect) {
        toggleAlasan(statusSelect);
    }

    // Preview nama file yang dipilih & tombol hapus pilihan sebelum disimpan
    document.querySelectorAll('.kia-anak-pdf-input').forEach(inp => {
        inp.addEventListener('change', function() {
            const previewEl = document.getElementById(this.dataset.previewTarget || '');
            const clearBtn = document.querySelector('.btn-clear-kia-file[data-target-input="' + this.id + '"]');
            if (this.files && this.files.length > 0) {
                const f = this.files[0];
                if (previewEl) {
                    previewEl.textContent = 'File dipilih: ' + f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
                    previewEl.style.display = 'block';
                }
                if (clearBtn) clearBtn.style.display = 'inline-block';
            } else {
                if (previewEl) {
                    previewEl.textContent = '';
                    previewEl.style.display = 'none';
                }
                if (clearBtn) clearBtn.style.display = 'none';
            }
        });
    });

    document.querySelectorAll('.btn-clear-kia-file').forEach(btn => {
        btn.addEventListener('click', function() {
            const inp = document.getElementById(this.dataset.targetInput || '');
            if (inp) {
                inp.value = '';
                inp.dispatchEvent(new Event('change'));
            }
        });
    });

    const kiaForm = document.getElementById('kiaStatusForm');
    if (kiaForm) {
        kiaForm.addEventListener('submit', function(e) {
            const statusSelect = document.getElementById('kiaStatusSelect');
            const anakSlots = document.querySelectorAll('.kia-anak-pdf-slot');

            if (statusSelect && statusSelect.value === 'selesai') {
                if (anakSlots.length > 0) {
                    for (const slot of anakSlots) {
                        const hasEx = slot.dataset.hasExisting === '1';
                        const inp = slot.querySelector('.kia-anak-pdf-input');
                        const hasFile = inp && inp.files && inp.files.length > 0;
                        if (!hasEx && !hasFile) {
                            e.preventDefault();
                            alert('Gagal: PDF KIA untuk "' + (slot.dataset.anakNama || 'Anak') + '" wajib diunggah sebelum status diubah menjadi Selesai.');
                            if (inp) inp.focus();
                            return false;
                        }
                    }
                } else {
                    const pdfInput = document.getElementById('dokumen_kia_pdf');
                    const hasExisting = <?= !empty($detail_dokumen_hasil) ? 'true' : 'false' ?>;
                    const hasFile = pdfInput && pdfInput.files && pdfInput.files.length > 0;
                    if (!hasExisting && !hasFile) {
                        e.preventDefault();
                        alert('Gagal: Berkas Dokumen PDF Hasil KIA wajib dipilih sebelum permohonan dapat diubah menjadi Selesai.');
                        if (pdfInput) pdfInput.focus();
                        return false;
                    }
                }
            }
        });
        const kiaStatusSelect = document.getElementById('kiaStatusSelect');
        if (kiaStatusSelect) {
            toggleKiaStatusAlasan(kiaStatusSelect);
        }
    }
});

// Tutup modal saat klik luar
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', e => {
        if (e.target !== modal) return;
        if (modal.id === 'previewModal') {
            closePreviewModal();
        } else {
            modal.classList.remove('show');
        }
    });
});

// Tambahkan event listener untuk download button
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('btn-download') || 
        e.target.closest('.btn-download')) {
        // Biarkan default behavior (download file)
        // Tidak perlu melakukan apa-apa khusus
    }
});

// Suara untuk operator saat ada berkas baru masuk
const audioBaru = new Audio('https://siprima.gorontalokab.go.id/assets/new_task.mp3');

function refreshOperatorOtomatis() {
    const areaTabel = document.getElementById('tabel-permohonan-konten');
    if (!areaTabel) return;

    // Hitung berapa banyak status "Diajukan" yang ada saat ini
    const jumlahDiajukanLama = Array.from(areaTabel.querySelectorAll('span'))
                                    .filter(s => s.innerText.trim() === 'Diajukan').length;

    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const kontenBaru = doc.getElementById('tabel-permohonan-konten');

            if (kontenBaru) {
                // Hitung jumlah "Diajukan" di data terbaru
                const jumlahDiajukanBaru = Array.from(kontenBaru.querySelectorAll('span'))
                                                .filter(s => s.innerText.trim() === 'Diajukan').length;

                // Jika jumlah permohonan baru lebih banyak dari sebelumnya
                if (jumlahDiajukanBaru > jumlahDiajukanLama) {
                    audioBaru.play().catch(e => console.log("Izin audio diperlukan"));
                    const jumlahBaru = jumlahDiajukanBaru - jumlahDiajukanLama;
                    const body = new URLSearchParams({
                        count: String(jumlahBaru),
                        csrf_token: <?= json_encode($csrf_token_value) ?>
                    });
                    fetch('notify_operator_new_submission.php', {
                        method: 'POST',
                        headers: {'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},
                        credentials: 'same-origin',
                        body
                    }).then(response => response.ok ? response.json() : Promise.reject())
                      .then(data => {
                          if (data.success) window.dispatchEvent(new CustomEvent('siprima:notification-refresh'));
                      })
                      .catch(() => {});
                }

                areaTabel.innerHTML = kontenBaru.innerHTML;
            }
        });
}

// Untuk Operator, refresh setiap 1 menit (60000 ms) agar pelayanan lebih sigap
setInterval(refreshOperatorOtomatis, 60000);
</script>
<script src="../assets/js/stable-upload.js?v=20260821-2"></script>
<script src="../assets/js/operator_document_preview.js?v=20260913-1"></script>
</body>
</html>
