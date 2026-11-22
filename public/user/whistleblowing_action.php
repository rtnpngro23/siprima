<?php

declare(strict_types=1);

require_once __DIR__.'/../error_handler.php';
require_once __DIR__.'/../../app/middleware/user_only.php';
require_once __DIR__.'/../../app/config/koneksi.php';
require_once __DIR__.'/../functions/whistleblowing.php';

$userId = wbs_require_regular_applicant($koneksi);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! csrf_validate()) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$action = (string) ($_POST['action'] ?? '');
$storedPaths = [];

try {
    if ($action === 'create') {
        $category = (string) ($_POST['kategori'] ?? '');
        $title = trim((string) ($_POST['judul'] ?? ''));
        $description = trim((string) ($_POST['uraian'] ?? ''));
        $incidentDate = trim((string) ($_POST['tanggal_kejadian'] ?? ''));
        $location = trim((string) ($_POST['lokasi_kejadian'] ?? ''));
        $reportedParty = trim((string) ($_POST['pihak_terlapor'] ?? ''));
        $service = trim((string) ($_POST['layanan_terkait'] ?? ''));
        $files = wbs_uploaded_files();

        if (! array_key_exists($category, wbs_categories())) {
            throw new InvalidArgumentException('Pilih kategori aduan yang valid.');
        }
        if (mb_strlen($title) < 10 || mb_strlen($title) > 150) {
            throw new InvalidArgumentException('Judul harus terdiri dari 10–150 karakter.');
        }
        if (mb_strlen($description) < 30 || mb_strlen($description) > 5000) {
            throw new InvalidArgumentException('Kronologi harus terdiri dari 30–5000 karakter.');
        }
        if (mb_strlen($location) > 255 || mb_strlen($reportedParty) > 255 || mb_strlen($service) > 100) {
            throw new InvalidArgumentException('Salah satu isian melebihi batas karakter.');
        }
        if ($incidentDate !== '') {
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $incidentDate);
            if (! $parsedDate || $parsedDate->format('Y-m-d') !== $incidentDate || $parsedDate > new DateTimeImmutable('today')) {
                throw new InvalidArgumentException('Tanggal kejadian tidak valid atau berada di masa depan.');
            }
        } else {
            $incidentDate = null;
        }
        if ($fileError = wbs_validate_files($files)) {
            throw new InvalidArgumentException($fileError);
        }

        $code = wbs_generate_code($koneksi);
        $koneksi->begin_transaction();
        $stmt = $koneksi->prepare('INSERT INTO tb_whistleblowing_aduan (kode_aduan,pemohon_id,kategori,judul,uraian,tanggal_kejadian,lokasi_kejadian,pihak_terlapor,layanan_terkait) VALUES (?,?,?,?,?,?,?,?,?)');
        if (! $stmt) {
            throw new RuntimeException('Tabel whistleblowing belum tersedia.');
        }
        $stmt->bind_param('sisssssss', $code, $userId, $category, $title, $description, $incidentDate, $location, $reportedParty, $service);
        if (! $stmt->execute()) {
            throw new RuntimeException('Aduan gagal disimpan.');
        }
        $caseId = (int) $stmt->insert_id;
        $stmt->close();

        $initialMessage = 'Aduan diterima sistem dan menunggu verifikasi admin.';
        $initialStatus = 'baru';
        $visibility = 'pemohon';
        $actorRole = 'pemohon';
        $stmt = $koneksi->prepare('INSERT INTO tb_whistleblowing_tindak_lanjut (aduan_id,actor_user_id,actor_role,status_to,pesan,visibility) VALUES (?,?,?,?,?,?)');
        if (! $stmt) {
            throw new RuntimeException('Riwayat aduan gagal disiapkan.');
        }
        $stmt->bind_param('iissss', $caseId, $userId, $actorRole, $initialStatus, $initialMessage, $visibility);
        if (! $stmt->execute()) {
            throw new RuntimeException('Riwayat aduan gagal disimpan.');
        }
        $stmt->close();

        $storedPaths = wbs_store_files($koneksi, $caseId, null, $userId, $code, $files);
        $koneksi->commit();
        wbs_set_flash('success', 'Aduan berhasil dikirim dengan nomor tiket '.$code.'.');
        header('Location: whistleblowing.php?kode='.rawurlencode($code));
        exit;
    }

    if ($action === 'reply') {
        $code = trim((string) ($_POST['kode_aduan'] ?? ''));
        $message = trim((string) ($_POST['pesan'] ?? ''));
        $case = wbs_case_for_applicant($koneksi, $code, $userId);
        if (! $case) {
            throw new InvalidArgumentException('Aduan tidak ditemukan.');
        }
        if ((string) $case['status'] !== 'menunggu_pemohon') {
            throw new InvalidArgumentException('Aduan ini tidak sedang menunggu jawaban Anda.');
        }
        if (mb_strlen($message) < 5 || mb_strlen($message) > 3000) {
            throw new InvalidArgumentException('Jawaban harus terdiri dari 5–3000 karakter.');
        }
        $files = wbs_uploaded_files();
        if ($fileError = wbs_validate_files($files)) {
            throw new InvalidArgumentException($fileError);
        }

        $caseId = (int) $case['id'];
        $from = 'menunggu_pemohon';
        $to = 'diproses';
        $role = 'pemohon';
        $visibility = 'pemohon';
        $koneksi->begin_transaction();
        $stmt = $koneksi->prepare('INSERT INTO tb_whistleblowing_tindak_lanjut (aduan_id,actor_user_id,actor_role,status_from,status_to,pesan,visibility) VALUES (?,?,?,?,?,?,?)');
        if (! $stmt) {
            throw new RuntimeException('Jawaban gagal disiapkan.');
        }
        $stmt->bind_param('iisssss', $caseId, $userId, $role, $from, $to, $message, $visibility);
        if (! $stmt->execute()) {
            throw new RuntimeException('Jawaban gagal disimpan.');
        }
        $followUpId = (int) $stmt->insert_id;
        $stmt->close();
        $storedPaths = wbs_store_files($koneksi, $caseId, $followUpId, $userId, $code, $files);
        $stmt = $koneksi->prepare("UPDATE tb_whistleblowing_aduan SET status='diproses',updated_at=NOW() WHERE id=? AND pemohon_id=? AND status='menunggu_pemohon'");
        if (! $stmt) {
            throw new RuntimeException('Status aduan gagal disiapkan.');
        }
        $stmt->bind_param('ii', $caseId, $userId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Status aduan telah berubah. Muat ulang halaman.');
        }
        $stmt->close();
        $koneksi->commit();
        wbs_set_flash('success', 'Jawaban tambahan berhasil dikirim kepada admin.');
        header('Location: whistleblowing.php?kode='.rawurlencode($code));
        exit;
    }

    throw new InvalidArgumentException('Aksi tidak dikenali.');
} catch (Throwable $exception) {
    try {
        $koneksi->rollback();
    } catch (Throwable $ignored) {
    }
    wbs_cleanup_files($storedPaths);
    error_log('WBS_USER_ACTION_FAILED action='.$action.' user_id='.$userId.' reason='.$exception->getMessage());
    $message = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Aduan belum dapat diproses. Silakan coba lagi.';
    wbs_set_flash('error', $message);
    $redirectCode = trim((string) ($_POST['kode_aduan'] ?? ''));
    header('Location: whistleblowing.php'.($redirectCode !== '' ? '?kode='.rawurlencode($redirectCode) : ''));
    exit;
}
