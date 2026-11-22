<?php
require_once __DIR__ . '/../config/service_hours.php';

function service_hours_guard_submission(): void
{
    if (!service_hours_session_restricted($_SESSION)) return;
    $status = service_hours_status();
    if ($status['is_open']) return;
    http_response_code(403);
    $message = service_hours_closed_message($status);
    if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false, 'error'=>'submission_closed', 'message'=>$message]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        $back = ($_SESSION['role'] ?? '') === 'petugas_desa' ? 'petugas_desa_status_permohonan.php' : 'status_permohonan.php';
        echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Pengajuan sementara tidak tersedia</title><body style="font-family:system-ui;background:#f1f5f9;padding:24px">'
            . '<main style="max-width:680px;margin:40px auto;background:white;padding:28px;border-radius:16px">'
            . '<h1>Pengajuan sementara tidak tersedia</h1><p style="line-height:1.7">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="' . $back . '">Lihat status permohonan</a>'
            . '<p>Jika Anda baru mengirim formulir, gunakan tombol Kembali pada browser untuk memeriksa isian. Data belum disimpan.</p></main></body></html>';
    }
    exit;
}

function service_hours_client_state(?DateTimeImmutable $now = null, ?array $settings = null): array
{
    $settings = $settings ?? service_hours_settings();
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(SERVICE_HOURS_TIMEZONE));
    $status = service_hours_status($now, $settings);
    $notice = service_hours_event_notice($now, $settings);
    $message = $notice['message'] ?? '';
    $countdown = '';
    if (!$status['is_open']) {
        if (!$notice || $notice['type'] !== 'closure' || $notice['state'] !== 'active') {
            $message = trim($message . "\n\n" . service_hours_closed_message($status));
        }
    } else {
        $next = service_hours_next_change($now, $settings);
        $secondsLeft = $next ? $next['timestamp'] - $now->getTimestamp() : null;
        if ($next && !$next['is_open'] && $secondsLeft > 0 && $secondsLeft <= 300) {
            $minutesLeft = (int)ceil($secondsLeft / 60);
            $countdown = 'Sisa waktu pengajuan sekitar ' . $minutesLeft
                . ' menit. Selesaikan pengajuan sebelum pukul '
                . $now->setTimestamp($next['timestamp'])->format('H:i') . ' WITA.';
            $message = trim($message . "\n\n" . $countdown);
        }
    }
    $nextOpening = !$status['is_open'] ? service_hours_next_change($now, $settings) : null;
    $currentClosure = !$status['is_open'] && (!$notice || $notice['type'] !== 'closure' || $notice['state'] !== 'active');
    $summary = $notice['summary'] ?? ($status['is_open'] ? 'Segera selesaikan pengajuan Anda.' : 'Pengajuan baru, edit, dan ajukan kembali sementara tidak tersedia.');
    if ($currentClosure && $notice) $summary .= ' Saat ini pengajuan baru, edit, dan ajukan kembali sementara tidak tersedia.';
    $resume = $notice['resume'] ?? '';
    if ($currentClosure && $nextOpening) $resume = 'Pengajuan dapat dilanjutkan kembali pada ' . $nextOpening['at'] . '.';
    return ['is_open'=>$status['is_open'], 'message'=>$message, 'notice'=>$notice,
        'title'=>$notice['title'] ?? ($status['is_open'] ? 'Jam pengajuan segera berakhir' : 'Pengajuan sementara ditutup'),
        'summary'=>$summary,
        'resume'=>$resume,
        'detail'=>$currentClosure ? $message : ($notice['message'] ?? ''),
        'countdown'=>$countdown];
}
function service_hours_register_notice(): void
{
    static $registered = false;
    if ($registered || !service_hours_session_restricted($_SESSION)) return;
    $registered = true;
    $state = json_encode(service_hours_client_state(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ob_start(function ($html) use ($state) {
        if (stripos($html, '</body>') === false) return $html;
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) return $html;
        }
        $script = '<script type="application/json" id="service-availability-state">' . $state . '</script>'
            . '<link rel="stylesheet" href="../assets/css/service-notice.css?v=20260906-2">'
            . '<script src="../assets/js/service-notice.js?v=20260906-1"></script>'
            . '<script src="../assets/js/service-availability.js?v=20260906-3"></script>';
        return str_ireplace('</body>', $script . '</body>', $html);
    });
}