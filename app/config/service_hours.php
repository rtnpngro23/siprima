<?php
// All dates in the settings document are local WITA; no browser timezone conversion.
const SERVICE_HOURS_TIMEZONE = 'Asia/Makassar';

function service_hours_defaults(): array
{
    return ['enabled' => false, 'days' => [1, 2, 3, 4, 5], 'open' => '08:00', 'close' => '15:30', 'events' => []];
}

function service_hours_db(): mysqli
{
    static $db;
    if ($db instanceof mysqli) return $db;
    require_once __DIR__ . '/env_loader.php';
    if (!loadEnv(__DIR__ . '/.env')) throw new RuntimeException('Konfigurasi database tidak tersedia.');
    $db = new mysqli((string)env('DB_HOST', 'localhost'), (string)env('DB_USER', 'root'), (string)env('DB_PASS', ''), (string)env('DB_NAME'));
    if ($db->connect_errno || !$db->set_charset('utf8mb4')) throw new RuntimeException('Database pengaturan tidak tersedia.');
    return $db;
}

function service_hours_settings(): array
{
    static $settings;
    if ($settings !== null) return $settings;
    try {
        $result = service_hours_db()->query('SELECT payload FROM tb_service_settings WHERE id=1');
        if (!$result || !($row = $result->fetch_assoc())) throw new RuntimeException('Pengaturan belum tersedia.');
        $settings = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        service_hours_validate($settings);
    } catch (Throwable $e) {
        error_log('Service settings could not be loaded.');
        $settings = service_hours_defaults();
        $settings['unavailable'] = true;
    }
    return $settings;
}

function service_hours_datetime(string $value): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone(SERVICE_HOURS_TIMEZONE));
    if (!$date || $date->format('Y-m-d\TH:i') !== $value) throw new InvalidArgumentException('Tanggal/jam tidak valid.');
    return $date;
}

function service_hours_validate(array $settings): void
{
    if (!isset($settings['enabled'], $settings['days'], $settings['open'], $settings['close'], $settings['events'])
        || !is_bool($settings['enabled']) || !is_array($settings['days']) || !is_array($settings['events'])) {
        throw new InvalidArgumentException('Pengaturan tidak valid.');
    }
    foreach (['open', 'close'] as $key) {
        if (!is_string($settings[$key]) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $settings[$key])) {
            throw new InvalidArgumentException('Jam layanan tidak valid.');
        }
    }
    if ($settings['open'] >= $settings['close']) throw new InvalidArgumentException('Jam tutup harus setelah jam buka.');
    if (!$settings['days'] || array_diff($settings['days'], [1,2,3,4,5,6,7])) throw new InvalidArgumentException('Pilih hari layanan yang valid.');
    if (count($settings['events']) > 500) throw new InvalidArgumentException('Maksimal 500 event.');
    $ids = [];
    foreach ($settings['events'] as $event) {
        if (!is_array($event)) throw new InvalidArgumentException('Event tidak valid.');
        foreach (['id','title','message','kind','announce','start','end'] as $key) {
            if (!isset($event[$key]) || !is_string($event[$key])) throw new InvalidArgumentException('Data event tidak lengkap.');
        }
        if (!preg_match('/^[a-f0-9]{16}$/D', $event['id']) || isset($ids[$event['id']])) throw new InvalidArgumentException('ID event tidak valid.');
        $ids[$event['id']] = true;
        if (!is_bool($event['enabled'] ?? null) || !in_array($event['kind'], ['notice','closure'], true)) throw new InvalidArgumentException('Jenis event tidak valid.');
        if (trim($event['title']) === '' || strlen($event['title']) > 200 || trim($event['message']) === '' || strlen($event['message']) > 4000) {
            throw new InvalidArgumentException('Judul dan pesan wajib diisi (maksimal 200 dan 4000 byte).');
        }
        $announce = service_hours_datetime($event['announce']);
        $start = service_hours_datetime($event['start']);
        $end = service_hours_datetime($event['end']);
        if ($announce > $start || $start >= $end) throw new InvalidArgumentException('Pengumuman harus sebelum/saat mulai, dan selesai harus setelah mulai.');
    }
    $closures = array_values(array_filter($settings['events'], function ($e) { return $e['enabled'] && $e['kind'] === 'closure'; }));
    for ($i=0; $i<count($closures); $i++) {
        for ($j=$i+1; $j<count($closures); $j++) {
            if ($closures[$i]['start'] < $closures[$j]['end'] && $closures[$j]['start'] < $closures[$i]['end']) {
                throw new InvalidArgumentException('Jadwal penutupan bertabrakan: ' . $closures[$i]['title'] . ' / ' . $closures[$j]['title']);
            }
        }
    }
}

function service_hours_status(?DateTimeImmutable $now = null, ?array $settings = null): array
{
    $settings = $settings ?? service_hours_settings();
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(SERVICE_HOURS_TIMEZONE));
    $stamp = $now->format('Y-m-d\TH:i');
    $day = (int)$now->format('N');
    $isDay = in_array($day, $settings['days'], true);
    $open = true;
    $reason = null;
    $event = null;
    if ($settings['enabled'] && (!$isDay || $now->format('H:i') < $settings['open'] || $now->format('H:i') >= $settings['close'])) {
        $open = false;
        $reason = $isDay ? 'outside_hours' : 'holiday';
    }
    foreach ($settings['events'] as $item) {
        if ($item['enabled'] && $item['kind'] === 'closure' && $item['start'] <= $stamp && $stamp < $item['end']) {
            $open = false;
            $reason = 'event';
            $event = $item;
            break;
        }
    }
    if (!empty($settings['unavailable'])) { $open = false; $reason = 'unavailable'; }
    $names = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];
    $label = implode(', ', array_map(function ($d) use ($names) { return $names[$d]; }, $settings['days']))
        . ', ' . $settings['open'] . '–' . $settings['close'] . ' WITA';
    return ['is_open'=>$open, 'closed_reason'=>$reason, 'event'=>$event, 'timezone'=>SERVICE_HOURS_TIMEZONE,
        'label'=>$settings['enabled'] ? $label : '24 jam (jadwal rutin nonaktif)', 'is_weekday'=>$isDay,
        'is_holiday'=>$reason === 'holiday', 'holiday_name'=>null, 'special_close_reason'=>$event['title'] ?? null,
        'close_time'=>$settings['close'].':00', 'current_time'=>$now->format('H:i:s'), 'current_date'=>$now->format('Y-m-d')];
}

function service_hours_notice_for(array $event, DateTimeImmutable $now, ?array $settings = null): array
{
    $active = $now->format('Y-m-d\TH:i') >= $event['start'];
    $start = service_hours_datetime($event['start'])->format('d/m/Y H:i');
    $end = service_hours_datetime($event['end'])->format('d/m/Y H:i');
    $message = $event['message'];
    if ($event['kind'] === 'closure') {
        $settings = $settings ?? service_hours_settings();
        $routineMessage = service_hours_resume_message($event, $settings);
        $message .= ' Pengajuan baru, edit, dan ajukan kembali untuk LINE/Umum dan ADD '
            . ($active ? 'dinonaktifkan' : 'akan dinonaktifkan') . ' pada ' . $start . ' sampai ' . $end
            . ' WITA. Login, melihat status, dan unduh dokumen tetap tersedia. ' . $routineMessage;
    }
    $settings = $settings ?? service_hours_settings();
    $summary = $event['kind'] === 'closure'
        ? 'Pengajuan ' . ($active ? 'ditutup sementara' : 'akan ditutup sementara') . ' pada ' . $start . '–' . $end . ' WITA.'
        : 'Informasi terbaru mengenai layanan SI PRIMA.';
    $version = hash('sha256', json_encode([$event, $settings['enabled'], $settings['days'], $settings['open'], $settings['close']]));
    return ['version'=>$version, 'summary'=>$summary, 'resume'=>$routineMessage ?? '',
        'id'=>$event['id'], 'type'=>$event['kind'], 'title'=>$event['title'], 'description'=>$event['message'], 'message'=>$message,
        'state'=>$active ? 'active' : 'upcoming', 'close_time'=>substr($event['start'],11).':00', 'button_label'=>'Saya Mengerti'];
}

function service_hours_event_notice(?DateTimeImmutable $now = null, ?array $settings = null): ?array
{
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(SERVICE_HOURS_TIMEZONE));
    $stamp = $now->format('Y-m-d\TH:i');
    $settings = $settings ?? service_hours_settings();
    $events = array_values(array_filter($settings['events'], function ($e) use ($stamp) {
        return $e['enabled'] && $e['announce'] <= $stamp && $stamp < $e['end'];
    }));
    // Active closures take precedence; otherwise display the nearest scheduled event.
    usort($events, function ($a, $b) use ($stamp) {
        $aPriority = ($a['kind'] === 'closure' && $a['start'] <= $stamp) ? 0 : 1;
        $bPriority = ($b['kind'] === 'closure' && $b['start'] <= $stamp) ? 0 : 1;
        return ($aPriority <=> $bPriority) ?: strcmp($a['start'], $b['start']);
    });
    return $events ? service_hours_notice_for($events[0], $now, $settings) : null;
}

function service_hours_closed_message(?array $status = null): string
{
    $status = $status ?? service_hours_status();
    $reason = $status['closed_reason'] === 'unavailable'
        ? 'Pengaturan layanan belum dapat dimuat. Silakan coba lagi atau hubungi admin.'
        : ($status['event'] ? $status['event']['title'] . '. ' . $status['event']['message'] . ' Sampai ' . service_hours_datetime($status['event']['end'])->format('d/m/Y H:i') . ' WITA.'
            : 'Di luar jadwal pengajuan: ' . $status['label'] . '.');
    return $reason . ' Pengajuan baru, edit, dan ajukan kembali LINE/Umum dan ADD sementara tidak tersedia. Anda tetap dapat login, melihat status, dan mengunduh dokumen.';
}

function service_hours_session_restricted(array $session): bool
{
    if (($session['role'] ?? '') === 'petugas_desa') return true;
    return ($session['role'] ?? '') === 'user'
        && empty($session['id_kua']) && empty($session['id_puskes']) && empty($session['id_rs']);
}
function service_hours_next_change(?DateTimeImmutable $now = null, ?array $settings = null): ?array
{
    $settings = $settings ?? service_hours_settings();
    if (!empty($settings['unavailable'])) return null;
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(SERVICE_HOURS_TIMEZONE));
    $candidates = [];
    $bases = [$now];
    foreach ($settings['events'] as $event) {
        if (!$event['enabled'] || $event['kind'] !== 'closure') continue;
        $candidates[] = service_hours_datetime($event['start']);
        $end = service_hours_datetime($event['end']);
        $candidates[] = $end;
        if ($end > $now) $bases[] = $end;
    }
    if ($settings['enabled']) {
        foreach ($bases as $base) {
            for ($i=0; $i<8; $i++) {
                $date = $base->modify("+$i days");
                if (!in_array((int)$date->format('N'), $settings['days'], true)) continue;
                foreach (['open','close'] as $key) $candidates[] = service_hours_datetime($date->format('Y-m-d').'T'.$settings[$key]);
            }
        }
    }
    usort($candidates, function ($a,$b) { return $a <=> $b; });
    $current = service_hours_status($now,$settings)['is_open'];
    foreach ($candidates as $candidate) {
        if ($candidate <= $now) continue;
        $open = service_hours_status($candidate,$settings)['is_open'];
        if ($open !== $current) return ['at'=>$candidate->format('d/m/Y H:i').' WITA', 'is_open'=>$open, 'timestamp'=>$candidate->getTimestamp()];
    }
    return null;
}
function service_hours_resume_message(array $event, array $settings): string
{
    $end = service_hours_datetime($event['end']);
    $resume = $end;
    if (!service_hours_status($end, $settings)['is_open']) {
        $next = service_hours_next_change($end, $settings);
        if (!$next || !$next['is_open']) return 'Waktu pengajuan dibuka kembali belum dapat ditentukan.';
        $resume = $end->setTimestamp($next['timestamp']);
    }
    if ($resume->format('Y-m-d') === $end->format('Y-m-d')) {
        return 'Setelah event selesai, pengajuan dapat dilanjutkan kembali pada pukul ' . $resume->format('H:i') . ' WITA.';
    }
    $days = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];
    return 'Setelah event selesai, pengajuan dapat dilanjutkan kembali pada hari layanan berikutnya, '
        . $days[(int)$resume->format('N')] . ', ' . $resume->format('d/m/Y') . ' pukul ' . $resume->format('H:i') . ' WITA.';
}