<?php

declare(strict_types=1);

require_once __DIR__.'/../error_handler.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__.'/../../app/middleware/admin_only.php';
require_once __DIR__.'/../../app/config/koneksi.php';
require_once __DIR__.'/../functions/whistleblowing.php';
require_once __DIR__.'/../functions/notifikasi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! csrf_validate()) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$adminId = (int) ($_SESSION['id_user'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$code = trim((string) ($_POST['kode_aduan'] ?? ''));
$case = wbs_case_by_code($koneksi, $code);
if (! $case) {
    wbs_set_flash('error', 'Aduan tidak ditemukan.');
    header('Location: whistleblowing.php');
    exit;
}

try {
    if ($action === 'follow_up') {
        $from = (string) $case['status'];
        $to = (string) ($_POST['status'] ?? '');
        $message = trim((string) ($_POST['pesan'] ?? ''));
        if (in_array($from, ['selesai', 'ditolak'], true)) {
            throw new InvalidArgumentException('Kasus yang sudah final tidak dapat diubah lagi.');
        }
        if (! array_key_exists($to, wbs_statuses()) || ! wbs_allowed_transition($from, $to)) {
            throw new InvalidArgumentException('Perubahan status tidak diperbolehkan.');
        }
        if (mb_strlen($message) < 5 || mb_strlen($message) > 3000) {
            throw new InvalidArgumentException('Tanggapan harus terdiri dari 5–3000 karakter.');
        }

        $caseId = (int) $case['id'];
        $role = 'admin';
        $visibility = 'pemohon';
        $koneksi->begin_transaction();
        $lockStmt = $koneksi->prepare('SELECT status FROM tb_whistleblowing_aduan WHERE id=? FOR UPDATE');
        if (! $lockStmt) {
            throw new RuntimeException('Kunci aduan gagal disiapkan.');
        }
        $lockStmt->bind_param('i', $caseId);
        $lockStmt->execute();
        $lockedStatus = (string) ($lockStmt->get_result()->fetch_assoc()['status'] ?? '');
        $lockStmt->close();
        if ($lockedStatus !== $from) {
            throw new RuntimeException('Status aduan telah berubah. Muat ulang halaman.');
        }
        $stmt = $koneksi->prepare('INSERT INTO tb_whistleblowing_tindak_lanjut (aduan_id,actor_user_id,actor_role,status_from,status_to,pesan,visibility) VALUES (?,?,?,?,?,?,?)');
        if (! $stmt) {
            throw new RuntimeException('Tindak lanjut gagal disiapkan.');
        }
        $stmt->bind_param('iisssss', $caseId, $adminId, $role, $from, $to, $message, $visibility);
        if (! $stmt->execute()) {
            throw new RuntimeException('Tindak lanjut gagal disimpan.');
        }
        $stmt->close();

        if (in_array($to, ['selesai', 'ditolak'], true)) {
            $stmt = $koneksi->prepare('UPDATE tb_whistleblowing_aduan SET status=?,admin_penanggung_jawab_id=COALESCE(admin_penanggung_jawab_id,?),selesai_at=NOW(),is_published=IF(?=\'selesai\',is_published,0),updated_at=NOW() WHERE id=? AND status=?');
            if (! $stmt) {
                throw new RuntimeException('Perubahan status gagal disiapkan.');
            }
            $stmt->bind_param('sisis', $to, $adminId, $to, $caseId, $from);
        } else {
            $stmt = $koneksi->prepare('UPDATE tb_whistleblowing_aduan SET status=?,admin_penanggung_jawab_id=COALESCE(admin_penanggung_jawab_id,?),updated_at=NOW() WHERE id=? AND status=?');
            if (! $stmt) {
                throw new RuntimeException('Perubahan status gagal disiapkan.');
            }
            $stmt->bind_param('siis', $to, $adminId, $caseId, $from);
        }
        if (! $stmt->execute() || ($from !== $to && $stmt->affected_rows !== 1)) {
            throw new RuntimeException('Status aduan telah berubah. Muat ulang halaman.');
        }
        $stmt->close();
        $koneksi->commit();

        $notificationType = $to === 'selesai' ? 'success' : ($to === 'ditolak' ? 'danger' : 'info');
        buatNotifikasi($koneksi, (int) $case['pemohon_id'], 'Tindak Lanjut Whistleblowing', 'Aduan '.$code.' berstatus '.wbs_status_label($to).'.', $notificationType, 'whistleblowing.php?kode='.rawurlencode($code));
        wbs_set_flash('success', 'Tindak lanjut berhasil dikirim kepada pemohon.');
    } elseif ($action === 'internal_note') {
        $message = trim((string) ($_POST['pesan_internal'] ?? ''));
        if (mb_strlen($message) < 5 || mb_strlen($message) > 3000) {
            throw new InvalidArgumentException('Catatan internal harus terdiri dari 5–3000 karakter.');
        }
        $caseId = (int) $case['id'];
        $role = 'admin';
        $visibility = 'internal';
        $stmt = $koneksi->prepare('INSERT INTO tb_whistleblowing_tindak_lanjut (aduan_id,actor_user_id,actor_role,status_from,status_to,pesan,visibility) VALUES (?,?,?,?,?,?,?)');
        $status = (string) $case['status'];
        if (! $stmt) {
            throw new RuntimeException('Catatan internal gagal disiapkan.');
        }
        $stmt->bind_param('iisssss', $caseId, $adminId, $role, $status, $status, $message, $visibility);
        if (! $stmt->execute()) {
            throw new RuntimeException('Catatan internal gagal disimpan.');
        }
        $stmt->close();
        wbs_set_flash('success', 'Catatan internal berhasil disimpan.');
    } elseif ($action === 'publish') {
        if ((string) $case['status'] !== 'selesai') {
            throw new InvalidArgumentException('Hanya kasus selesai yang dapat dipublikasikan.');
        }
        $summary = trim((string) ($_POST['ringkasan_publik'] ?? ''));
        $result = trim((string) ($_POST['hasil_tindak_lanjut_publik'] ?? ''));
        if (mb_strlen($summary) < 30 || mb_strlen($summary) > 1000) {
            throw new InvalidArgumentException('Ringkasan publik harus terdiri dari 30–1000 karakter.');
        }
        if (mb_strlen($result) < 30 || mb_strlen($result) > 3000) {
            throw new InvalidArgumentException('Hasil tindak lanjut publik harus terdiri dari 30–3000 karakter.');
        }
        if (wbs_public_text_contains_sensitive_data($summary."\n".$result, $case)) {
            throw new InvalidArgumentException('Teks publik terdeteksi memuat identitas, NIK, email, nomor telepon, atau pihak terlapor. Anonimkan isinya terlebih dahulu.');
        }
        $caseId = (int) $case['id'];
        $koneksi->begin_transaction();
        $stmt = $koneksi->prepare('UPDATE tb_whistleblowing_aduan SET ringkasan_publik=?,hasil_tindak_lanjut_publik=?,is_published=1,published_by=?,published_at=NOW(),updated_at=NOW() WHERE id=? AND status=\'selesai\'');
        if (! $stmt) {
            throw new RuntimeException('Publikasi gagal disiapkan.');
        }
        $stmt->bind_param('ssii', $summary, $result, $adminId, $caseId);
        if (! $stmt->execute() || $stmt->affected_rows !== 1) {
            throw new RuntimeException('Hasil tindak lanjut gagal dipublikasikan.');
        }
        $stmt->close();
        $role = 'admin';
        $visibility = 'internal';
        $status = 'selesai';
        $message = 'Ringkasan anonim hasil tindak lanjut dipublikasikan pada web informasi Dukcapil.';
        $stmt = $koneksi->prepare('INSERT INTO tb_whistleblowing_tindak_lanjut (aduan_id,actor_user_id,actor_role,status_from,status_to,pesan,visibility) VALUES (?,?,?,?,?,?,?)');
        if (! $stmt) {
            throw new RuntimeException('Audit publikasi gagal disiapkan.');
        }
        $stmt->bind_param('iisssss', $caseId, $adminId, $role, $status, $status, $message, $visibility);
        if (! $stmt->execute()) {
            throw new RuntimeException('Audit publikasi gagal disimpan.');
        }
        $stmt->close();
        $koneksi->commit();
        wbs_set_flash('success', 'Hasil anonim berhasil dipublikasikan.');
    } elseif ($action === 'unpublish') {
        $caseId = (int) $case['id'];
        $koneksi->begin_transaction();
        $stmt = $koneksi->prepare('UPDATE tb_whistleblowing_aduan SET is_published=0,published_by=NULL,published_at=NULL,updated_at=NOW() WHERE id=? AND is_published=1');
        if (! $stmt) {
            throw new RuntimeException('Pencabutan publikasi gagal disiapkan.');
        }
        $stmt->bind_param('i', $caseId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Publikasi sudah tidak aktif.');
        }
        $stmt->close();
        $role = 'admin';
        $visibility = 'internal';
        $status = (string) $case['status'];
        $message = 'Publikasi hasil tindak lanjut dicabut dari web informasi Dukcapil.';
        $stmt = $koneksi->prepare('INSERT INTO tb_whistleblowing_tindak_lanjut (aduan_id,actor_user_id,actor_role,status_from,status_to,pesan,visibility) VALUES (?,?,?,?,?,?,?)');
        if (! $stmt) {
            throw new RuntimeException('Audit pencabutan gagal disiapkan.');
        }
        $stmt->bind_param('iisssss', $caseId, $adminId, $role, $status, $status, $message, $visibility);
        if (! $stmt->execute()) {
            throw new RuntimeException('Audit pencabutan gagal disimpan.');
        }
        $stmt->close();
        $koneksi->commit();
        wbs_set_flash('success', 'Publikasi berhasil dicabut.');
    } else {
        throw new InvalidArgumentException('Aksi tidak dikenali.');
    }
} catch (Throwable $exception) {
    try {
        $koneksi->rollback();
    } catch (Throwable $ignored) {
    }
    error_log('WBS_ADMIN_ACTION_FAILED action='.$action.' admin_id='.$adminId.' case_id='.(int) $case['id'].' reason='.$exception->getMessage());
    wbs_set_flash('error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Tindakan admin belum dapat diproses.');
}

header('Location: whistleblowing.php?kode='.rawurlencode($code));
exit;
