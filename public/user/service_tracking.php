<?php
require_once __DIR__ . '/status_history_helper.php';

function serviceTrackingTables(): array
{
    return ['tb_kartukeluarga', 'tb_akta_kelahiran', 'tb_akta_kematian', 'tb_pindah_alamat',
        'tb_pindah_online', 'tb_akta_kelahiran_rs', 'tb_akta_kematian_rs',
        'tb_kartukeluarga_kua', 'tb_akta_kelahiran_ahlak', 'tb_pengajuan_kia'];
}

function serviceTrackingHistory(mysqli $db, string $table, int $id, bool $staff = false): array
{
    if (!in_array($table, serviceTrackingTables(), true) || $id < 1) return [];
    $exists = $db->query("SHOW TABLES LIKE 'tb_permohonan_status_history'");
    if (!$exists || !$exists->num_rows) return serviceTrackingSubmission($db, $table, $id, $staff);
    $stmt = $db->prepare("SELECT h.*, UNIX_TIMESTAMP(h.created_at) AS event_epoch, u.nama AS actor_name
        FROM tb_permohonan_status_history h LEFT JOIN tb_users u ON u.id = h.actor_id
        WHERE h.source_table = ? AND h.permohonan_id = ? ORDER BY h.created_at, h.id");
    $stmt->bind_param('si', $table, $id);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result() as $row) {
        $meta = json_decode($row['meta_json'] ?? '', true);
        if (!is_array($meta)) $meta = [];
        $epoch = (int)$row['event_epoch'];
        $event = ['id' => (int)$row['id'], 'status' => $row['status'],
            'status_from' => $meta['status_from'] ?? null, 'alasan' => $row['alasan'],
            'event_type' => $row['event_type'], 'epoch' => $epoch,
            'created_at_formatted' => (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone('Asia/Makassar'))->format('d-m-Y H:i:s') . ' WITA'];
        if ($staff) {
            $event['actor_name'] = !empty($meta['actor_name']) ? $meta['actor_name'] : ($row['actor_name'] ?? 'Sistem');
            $event['actor_role'] = $row['actor_role'];
        }
        $rows[] = $event;
    }
    $stmt->close();
    if (!$rows) return serviceTrackingSubmission($db, $table, $id, $staff);
    if ($rows[0]['status'] !== 'diajukan') {
        $submission = serviceTrackingSubmission($db, $table, $id, $staff);
        if ($submission && $submission[0]['epoch'] <= $rows[0]['epoch']) array_unshift($rows, $submission[0]);
    }
    return $rows;
}

function serviceTrackingSubmission(mysqli $db, string $table, int $id, bool $staff): array
{
    // An existing submission timestamp is evidence; processing/completion times are never guessed.
    $stmt = $db->prepare("SELECT id_user, UNIX_TIMESTAMP(created_at) AS epoch FROM `$table` WHERE id=?");
    $stmt->bind_param('i', $id); $stmt->execute(); $record = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (empty($record['epoch'])) return [];
    $event = ['id' => 0, 'status' => 'diajukan', 'status_from' => null, 'alasan' => null,
        'event_type' => 'submission_reference', 'epoch' => (int)$record['epoch'],
        'created_at_formatted' => (new DateTimeImmutable('@' . $record['epoch']))->setTimezone(new DateTimeZone('Asia/Makassar'))->format('d-m-Y H:i:s') . ' WITA'];
    if ($staff) { $event['actor_name'] = 'Pemohon'; $event['actor_role'] = 'pemohon'; }
    return [$event];
}

function serviceTrackingUpdatedLabel(mysqli $db, string $table, int $id): string
{
    static $labels = [];
    $key = spl_object_id($db) . ':' . $table . ':' . $id;
    if (isset($labels[$key])) return $labels[$key];
    if (!in_array($table, serviceTrackingTables(), true) || $id < 1) return 'Belum tercatat';
    $exists = $db->query("SHOW TABLES LIKE 'tb_permohonan_status_history'");
    if (!$exists || !$exists->num_rows) return 'Belum tercatat';
    $stmt = $db->prepare('SELECT UNIX_TIMESTAMP(MAX(created_at)) AS epoch FROM tb_permohonan_status_history WHERE source_table=? AND permohonan_id=?');
    $stmt->bind_param('si', $table, $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $labels[$key] = empty($row['epoch']) ? 'Belum tercatat' : (new DateTimeImmutable('@' . $row['epoch']))->setTimezone(new DateTimeZone('Asia/Makassar'))->format('d-m-Y H:i:s') . ' WITA';
}

function serviceTrackingTableForKind(string $kind): string
{
    return ['kk' => 'tb_kartukeluarga', 'kelahiran' => 'tb_akta_kelahiran', 'kematian' => 'tb_akta_kematian',
        'pindah' => 'tb_pindah_alamat', 'pindah_online' => 'tb_pindah_online',
        'Kartu Keluarga' => 'tb_kartukeluarga', 'Cetak Biodata' => 'tb_kartukeluarga', 'Akta Kelahiran' => 'tb_akta_kelahiran',
        'Akta Kematian' => 'tb_akta_kematian', 'Pindah Alamat' => 'tb_pindah_alamat', 'Pindah Masuk' => 'tb_pindah_online',
        'KK KUA (PASUTRI)' => 'tb_kartukeluarga_kua', 'Akta Kelahiran (AHLAK)' => 'tb_akta_kelahiran_ahlak',
        'Akta Kelahiran RS (ST SEHATI)' => 'tb_akta_kelahiran_rs', 'Akta Kematian RS (ST SEHATI)' => 'tb_akta_kematian_rs',
        'kia' => 'tb_pengajuan_kia', 'KIA' => 'tb_pengajuan_kia', 'Kartu Identitas Anak' => 'tb_pengajuan_kia',
        'Kartu Identitas Anak (KIA)' => 'tb_pengajuan_kia'][$kind] ?? '';
}

function serviceTrackingHtml(array $history, bool $visual = false, ?string $currentStatus = null): string
{
    $escape = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = $visual
        ? '<section class="service-timeline-visual" aria-label="Riwayat pelayanan"><h3>Riwayat Pelayanan</h3>'
        : '<section class="service-timeline" aria-label="Riwayat pelayanan" style="margin:16px 0;padding:16px;border:1px solid #dbe3ec;border-radius:10px;background:#f8fafc"><h3 style="margin:0 0 12px;font-weight:600">Riwayat Pelayanan</h3>';
    if (!$history) return $html . '<p>Riwayat perubahan status belum tersedia untuk permohonan ini.</p></section>';
    $last = end($history);
    $html .= $visual ? '<!--service-summary--><ol class="service-timeline-events">' : '<p>Pembaruan terakhir: <strong>' . ($last['id'] ? $escape($last['created_at_formatted']) : 'Riwayat perubahan belum tercatat') . '</strong></p><ol style="padding-left:22px;margin-top:12px">';
    foreach ($history as $index => $event) {
        if ($visual) {
            $statusClass = in_array($event['status'], ['diajukan', 'diproses', 'selesai', 'ditolak'], true) ? $event['status'] : 'unknown';
            $progress = ($event['event_type'] ?? '') === 'progress_update';
            $latest = $index === array_key_last($history);
            $html .= '<li class="service-timeline-event is-' . $statusClass . ($progress ? ' is-progress' : '') . ($latest ? ' is-latest' : '') . '"><span class="service-timeline-dot" aria-hidden="true"></span><div class="service-timeline-event-body"><div class="service-timeline-event-heading"><span class="service-timeline-badge is-' . $statusClass . '">' . $escape(ucfirst($event['status'])) . '</span>' . ($latest ? '<span class="service-timeline-latest">Terbaru</span>' : '') . '</div><div class="service-timeline-event-type">' . ($progress ? 'Pembaruan progres' : 'Tahap pelayanan') . '</div><div class="service-timeline-time">' . $escape($event['created_at_formatted']) . '</div>';
            if (isset($event['actor_name'])) $html .= '<div class="service-timeline-actor">' . $escape($event['actor_name']) . ' · ' . $escape(str_replace('_', ' ', $event['actor_role'] ?? 'sistem')) . '</div>';
            if (!empty($event['alasan'])) $html .= '<p class="service-timeline-note">' . $escape($event['alasan']) . '</p>';
            $html .= '</div></li>';
            continue;
        }
        $html .= '<li style="margin:12px 0"><strong>' . $escape(ucfirst($event['status'])) . '</strong> — ' . $escape($event['created_at_formatted']);
        if (($event['event_type'] ?? '') === 'progress_update') $html .= '<div>Pembaruan progres</div>';
        if (isset($event['actor_name'])) $html .= '<div>' . $escape($event['actor_name']) . ' · ' . $escape(str_replace('_', ' ', $event['actor_role'] ?? 'sistem')) . '</div>';
        if (!empty($event['alasan'])) $html .= '<div style="white-space:pre-wrap">' . $escape($event['alasan']) . '</div>';
        $html .= '</li>';
    }
    $html .= '</ol>';
    // Only measure the latest recorded submission cycle; never infer missing historical times.
    $start = null; $process = null; $finish = null; $processLabel = null;
    foreach ($history as $event) {
        if (($event['event_type'] ?? '') === 'progress_update') continue;
        if ($event['status'] === 'diajukan') { $start = $event['epoch']; $process = null; $finish = null; $processLabel = null; }
        if ($event['status'] === 'diproses') {
            if ($finish !== null) { $start = null; $process = null; $finish = null; $processLabel = null; }
            if ($process === null) { $process = $event['epoch']; $processLabel = $event['created_at_formatted']; }
        }
        if (in_array($event['status'], ['selesai', 'ditolak'], true)) $finish = $event['epoch'];
    }
    $duration = static function ($from, $to): string {
        $seconds = max(0, $to - $from);
        return intdiv($seconds, 86400) . ' hari ' . intdiv($seconds % 86400, 3600) . ' jam ' . intdiv($seconds % 3600, 60) . ' menit ' . ($seconds % 60) . ' detik';
    };
    if ($visual) {
        $status = $currentStatus ?? ($last['id'] ? $last['status'] : 'Belum diketahui');
        $statusClass = in_array($status, ['diajukan', 'diproses', 'selesai', 'ditolak'], true) ? $status : 'unknown';
        $summary = '<dl class="service-timeline-summary"><div><dt>Status saat ini</dt><dd><span class="service-timeline-badge is-' . $statusClass . '">' . $escape(ucfirst($status)) . '</span></dd></div><div><dt>Mulai diproses</dt><dd>' . ($processLabel !== null ? $escape($processLabel) : 'Belum tercatat') . '</dd></div><div><dt>Pembaruan terakhir</dt><dd>' . ($last['id'] ? $escape($last['created_at_formatted']) : 'Belum tercatat') . '</dd></div></dl>';
        $html = str_replace('<!--service-summary-->', $summary, $html);
        $html .= '<div class="service-timeline-durations">';
    } else {
        $html .= '<p>Mulai diproses: <strong>' . ($processLabel !== null ? $escape($processLabel) : 'Belum tercatat') . '</strong></p>';
    }
    if ($start !== null && $process !== null) $html .= '<p>Waktu tunggu: ' . $duration($start, $process) . '</p>';
    if ($process !== null && $finish !== null) $html .= '<p>Waktu proses: ' . $duration($process, $finish) . '</p>';
    if ($start !== null && $finish !== null) $html .= '<p>Total pelayanan: ' . $duration($start, $finish) . '</p>';
    if ($visual) $html .= '</div>';
    $html .= '<small>Durasi kalender berdasarkan tahapan yang tercatat. Riwayat lama yang belum tercatat tidak diperkirakan.</small></section>';
    return $html;
}

function serviceTrackingTransitionAllowed(string $from, string $to): bool
{
    return (in_array($from, ['diajukan', 'diproses', 'selesai', 'ditolak'], true) && $from === $to) || ($from === 'diajukan' && $to === 'diproses')
        || ($from === 'diproses' && in_array($to, ['selesai', 'ditolak'], true));
}

/** Called only after the page's role middleware and assignment lookup. */
function serviceTrackingUpdate(mysqli $db, array $tables, callable $canAccess): void
{
    $id = (int)($_POST['id'] ?? 0);
    $table = (string)($_POST['table'] ?? '');
    $status = trim((string)($_POST['status'] ?? ''));
    $note = trim((string)($_POST['alasan'] ?? ''));
    $ajax = ($_POST['status_ajax'] ?? '') === '1';
    $transaction = false; $changed = false; $uploadCount = 0;
    $response = ['success' => false]; $code = 422;
    try {
        if ($id < 1 || !in_array($table, $tables, true) || !in_array($table, serviceTrackingTables(), true)
            || !in_array($status, ['diajukan', 'diproses', 'ditolak', 'selesai'], true)) throw new DomainException('Data permohonan tidak valid.');
        if ($status === 'ditolak' && $note === '') throw new DomainException('Alasan penolakan wajib diisi.');
        if (!ensurePermohonanStatusHistoryTable($db)) throw new RuntimeException('Riwayat pelayanan belum tersedia.');
        if (!$db->begin_transaction()) throw new RuntimeException('Gagal memulai transaksi.');
        $transaction = true;
        $stmt = $db->prepare("SELECT * FROM `$table` WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $id); $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$record || !$canAccess($record)) { $code = 403; throw new DomainException('Anda tidak memiliki akses ke permohonan ini.'); }
        $history = serviceTrackingHistory($db, $table, $id, true);
        $version = $history ? max(array_column($history, 'id')) : 0;
        if (!isset($_POST['expected_status'], $_POST['history_version']) || $_POST['expected_status'] !== $record['status'] || (int)$_POST['history_version'] !== $version) {
            $code = 409;
            $response['status'] = $record['status'];
            $response['history_html'] = serviceTrackingHtml($history, true, (string)$record['status']);
            throw new DomainException('Status telah berubah sejak halaman dibuka. Catatan Anda tetap tersimpan di formulir; muat ulang detail untuk memeriksa perubahan sebelum melanjutkan.');
        }
        if (!serviceTrackingTransitionAllowed($record['status'], $status)) throw new DomainException('Perubahan status tidak sesuai tahapan pelayanan.');
        $hasFiles = function_exists('dokumen_hasil_has_uploaded_files') && dokumen_hasil_has_uploaded_files();
        if ($hasFiles && !in_array($status, ['diproses', 'selesai'], true)) throw new DomainException('Dokumen hasil hanya dapat diunggah saat Diproses atau Selesai.');
        $changed = $status !== $record['status'];
        $progress = !$changed && $status === 'diproses' && $note !== '' && $note !== trim((string)($record['alasan'] ?? ''));
        if (!$changed && !$hasFiles && !$progress) {
            if ($status === 'diproses') throw new DomainException($note === '' ? 'Catatan wajib diisi untuk pembaruan progres.' : 'Catatan masih sama dengan pembaruan terakhir. Tidak ada log baru yang disimpan.');
            throw new DomainException('Pilih status berikutnya. Tidak ada perubahan status yang disimpan.');
        }
        if ($changed || $progress) {
            // Preserve the real submission timestamp when starting tracking for a new request.
            if ($version === 0 && $record['status'] === 'diajukan' && !empty($record['created_at'])) {
                $submittedAt = $record['resubmitted_at'] ?? $record['created_at'];
                $initial = $db->prepare("INSERT INTO tb_permohonan_status_history (source_table, permohonan_id, status, actor_id, actor_role, event_type, created_at) VALUES (?, ?, 'diajukan', ?, 'pemohon', 'submission', ?)");
                $initial->bind_param('siis', $table, $id, $record['id_user'], $submittedAt);
                if (!$initial->execute()) throw new RuntimeException('Gagal mencatat waktu pengajuan.');
                $initial->close();
            }
            $stmt = $db->prepare("UPDATE `$table` SET status = ?, alasan = ? WHERE id = ?");
            $stmt->bind_param('ssi', $status, $note, $id);
            if (!$stmt->execute()) throw new RuntimeException('Gagal memperbarui status.');
            $stmt->close();
            if (!logPermohonanStatusHistory($db, $table, $id, $status, $note ?: null,
                (int)$_SESSION['id_user'], (string)$_SESSION['role'], $progress ? 'progress_update' : 'status_update',
                ['status_from' => $record['status'], 'actor_name' => (string)($_SESSION['nama'] ?? '')])) throw new RuntimeException('Gagal menyimpan riwayat status.');
            $emailTable = $changed ? $db->query("SHOW TABLES LIKE 'tb_konfirmasi_dokumen_email'") : null;
            if ($emailTable && $emailTable->num_rows) {
                $cancel = $db->prepare("UPDATE tb_konfirmasi_dokumen_email SET status_konfirmasi='dibatalkan', operator_id=NULL, operator_confirmed_at=NULL WHERE source_table=? AND permohonan_id=?");
                $cancel->bind_param('si', $table, $id);
                if (!$cancel->execute()) throw new RuntimeException('Gagal memperbarui konfirmasi dokumen.');
                $cancel->close();
            }
        }
        $updatedHistory = serviceTrackingHistory($db, $table, $id, true);
        if (!$db->commit()) throw new RuntimeException('Gagal menyimpan transaksi.');
        $transaction = false;
        $response = ['success' => true, 'status' => $status, 'message' => $progress ? 'Pembaruan progres berhasil disimpan. Status tetap Diproses.' : ($changed ? 'Status berhasil diubah menjadi ' . ucfirst($status) . '.' : 'Dokumen hasil berhasil diunggah.')];
        // File storage has its own lifecycle. Report partial success explicitly; status/history are already committed.
        if ($hasFiles) {
            $uploadError = null;
            try {
                $files = dokumen_hasil_upload_from_request($db, $table, $id, (int)$record['id_user'], (int)$_SESSION['id_user'], $uploadError);
                if ($files === false) throw new RuntimeException($uploadError ?: 'Dokumen hasil gagal diunggah.');
                $uploadCount = count($files);
                $response['message'] .= ($changed || $progress) ? ' ' . $uploadCount . ' dokumen hasil berhasil diunggah.' : '';
                $response['files_uploaded'] = true;
            } catch (Throwable $e) {
                $response['partial'] = true;
                $response['message'] = ($progress ? 'Progres tersimpan, tetapi ' : ($changed ? 'Status tersimpan, tetapi ' : '')) . ($uploadError ?: 'dokumen hasil gagal diunggah. Silakan coba unggah kembali.');
            }
        }
        try {
            $notified = ($changed || $progress) && function_exists('buatNotifikasiStatusPermohonan')
                && buatNotifikasiStatusPermohonan($db, (int)$record['id_user'], $table, $id, $status, $note);
            if (!$notified && ($changed || $progress || $uploadCount) && function_exists('buatNotifikasi')) {
                buatNotifikasi($db, (int)$record['id_user'], 'Pelayanan Diperbarui', $progress ? 'Permohonan masih Diproses. Catatan: ' . $note : ($changed ? 'Status permohonan menjadi ' . ucfirst($status) . ($note !== '' ? '. Catatan: ' . $note : '.') : 'Dokumen hasil tersedia pada detail permohonan.'), 'info', null, $table, $id);
            }
        } catch (Throwable $e) { error_log('Notifikasi tracking gagal: ' . $e->getMessage()); }
        $response['history_html'] = serviceTrackingHtml($updatedHistory, true, $status);
        $response['history_version'] = $updatedHistory ? max(array_column($updatedHistory, 'id')) : 0;
        $response['updated_at'] = $updatedHistory ? end($updatedHistory)['created_at_formatted'] : null;
        $response['status_note'] = ($changed || $progress) ? $note : ($record['alasan'] ?? '');
        $code = 200;
    } catch (Throwable $e) {
        if ($transaction) $db->rollback();
        $response['message'] = $e instanceof DomainException ? $e->getMessage() : 'Pembaruan gagal. Catatan tetap dipertahankan; silakan coba lagi.';
        if (!$e instanceof DomainException) { $code = 500; error_log('Service tracking: ' . $e->getMessage()); }
    }
    if ($ajax) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit;
    }
    $_SESSION[!empty($response['success']) ? 'success_message' : 'error_message'] = $response['message'];
    if (empty($response['success'])) $_SESSION['service_status_draft'] = ['table' => $table, 'id' => $id, 'note' => $note];
    header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?detail_id=' . $id . '&table=' . rawurlencode($table)); exit;
}
