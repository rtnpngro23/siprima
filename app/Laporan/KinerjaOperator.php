<?php
/** Read-only calculations for operator performance; never infer an operator from a region. */
function kinerjaServices(): array {
    return [
        'tb_kartukeluarga'=>['Kartu Keluarga','Dukcapil'],
        'tb_akta_kelahiran'=>['Akta Kelahiran','Dukcapil'],
        'tb_pengajuan_kia'=>['Kartu Identitas Anak (KIA)','Dukcapil'],
        'tb_akta_kematian'=>['Akta Kematian','Dukcapil'],
        'tb_pindah_alamat'=>['Pindah Alamat','Dukcapil'],
        'tb_pindah_online'=>['Pindah Masuk','Dukcapil'],
        'tb_kartukeluarga_kua'=>['KK KUA (PASUTRI)','KUA'],
        'tb_akta_kelahiran_ahlak'=>['Akta Kelahiran (AHLAK)','Puskesmas'],
        'tb_akta_kelahiran_rs'=>['Akta Kelahiran RS','Rumah Sakit'],
        'tb_akta_kematian_rs'=>['Akta Kematian RS','Rumah Sakit'],
        'tb_laporan_peristiwa'=>['Laporan Peristiwa','Dukcapil'],
    ];
}
function kinerjaDate(string $value): ?DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Makassar'));
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}
function kinerjaEmpty(): array {
    return ['selesai'=>0,'ditolak'=>0,'diproses'=>0,'durations'=>[],'rows'=>[],'service_ratings'=>[]];
}
function kinerjaDuration(?float $seconds): string {
    if ($seconds === null) return 'Belum dapat dinilai';
    $minutes = (int)round($seconds / 60);
    return $minutes >= 1440 ? floor($minutes / 1440).' hari '.floor(($minutes % 1440) / 60).' jam'
        : ($minutes >= 60 ? floor($minutes / 60).' jam '.($minutes % 60).' menit' : $minutes.' menit');
}
function kinerjaMean(array $values): ?float { return $values ? array_sum($values) / count($values) : null; }
function kinerjaMedian(array $values): ?float {
    if (!$values) return null;
    sort($values); $n = count($values); $middle = intdiv($n, 2);
    return $n % 2 ? (float)$values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}
/** Collapse status events into submission cycles. Upload/comment events do not change outcomes. */
function kinerjaCycles(array $events): array {
    $cycles = []; $cycle = null; $previous = null;
    $startTypes = ['', 'submission', 'resubmission', 'resubmit', 'created', 'resubmitted'];
    $decisionTypes = ['status_update', 'progress_update', 'processing', 'rejected', 'completed', 'status_updated'];
    $allowedTypes = array_merge($startTypes, $decisionTypes);
    foreach ($events as $event) {
        $status = $event['status'];
        $type = $event['event_type'] ?? '';
        if (!in_array($status, ['diajukan','diproses','selesai','ditolak'], true)
            || (!in_array($type, $allowedTypes, true))) continue;
        $epoch = (int)$event['epoch'];
        $terminal = $cycle && in_array($cycle['status'], ['selesai','ditolak'], true);
        if ($terminal && $status === $cycle['status']) continue;
        $isResubmitStart = $cycle !== null && in_array($type, ['resubmission', 'resubmit', 'resubmitted'], true) && $previous !== 'diajukan';
        if ($cycle === null || ($status === 'diajukan' && $previous !== 'diajukan')
            || $isResubmitStart || ($terminal && $status === 'diproses')) {
            if ($cycle !== null) $cycles[] = $cycle;
            $cycle = ['status'=>$status,'start'=>null,'end'=>$epoch,'actors'=>[],'unknown'=>false,
                'reason'=>'','began'=>$epoch,'cycle'=>count($cycles) + 1,'duration'=>null];
        }
        if ($status === 'diproses' && $previous !== 'diproses' && $cycle['start'] === null && $type !== 'progress_update') {
            $cycle['start'] = $epoch;
        }
        if ($status !== 'diajukan') {
            $actor = (int)($event['actor_id'] ?? 0);
            if ($actor > 0 && ($event['actor_role'] ?? '') === 'operator') $cycle['actors'][$actor] = true;
            else $cycle['unknown'] = true;
        }
        if ($terminal && in_array($status, ['selesai','ditolak'], true)) $cycle['unknown'] = true;
        $cycle['status'] = $status; $cycle['end'] = $epoch;
        $cycle['reason'] = (string)($event['alasan'] ?? '');
        $previous = $status;
    }
    if ($cycle !== null) $cycles[] = $cycle;
    foreach ($cycles as &$item) {
        $item['operator'] = count($item['actors']) === 1 && !$item['unknown'] ? (int)array_key_first($item['actors']) : null;
        $item['shared'] = count($item['actors']) > 1;
        if ($item['status'] === 'selesai' && $item['start'] !== null && $item['end'] >= $item['start']) {
            $item['duration'] = $item['end'] - $item['start'];
        }
    }
    unset($item);
    return $cycles;
}
/** Match an applicant rating to its completed cycle; app-only ratings stay separate. */
function kinerjaFeedbackRating(array $cycle, ?array $next, ?array $feedback): ?int {
    if (!$feedback || $cycle['status'] !== 'selesai' || $cycle['operator'] === null
        || ($feedback['respondent_role'] ?? '') !== 'pemohon'
        || ($feedback['status'] ?? '') !== 'submitted' || ($feedback['jenis_feedback'] ?? '') !== 'tanggapan') return null;
    $rating = (int)($feedback['service_rating'] ?? 0);
    $triggered = (int)($feedback['trigger_epoch'] ?? 0);
    $submitted = (int)($feedback['submitted_epoch'] ?? 0);
    if ($rating < 1 || $rating > 5 || $triggered < $cycle['end'] || $submitted < $triggered
        || ($next && ($triggered >= $next['began'] || $submitted >= $next['began']))) return null;
    return $rating;
}
function kinerjaAdd(array &$stats, array $row): void {
    if (isset($row['service_rating'])) $stats['service_ratings'][] = $row['service_rating'];
    $stats[$row['status']]++;
    if ($row['duration'] !== null && $row['status'] === 'selesai') $stats['durations'][] = $row['duration'];
    $stats['rows'][] = $row;
}
/** Read existing history only. Missing schemas are surfaced; no CREATE/UPDATE or fabricated dates. */
function kinerjaLoad(mysqli $db, int $from, int $until, string $service, string $unit): array {
    $data = ['operators'=>[], 'total'=>kinerjaEmpty(), 'excluded'=>[], 'untracked'=>0, 'missing'=>[],
        'history'=>false, 'trend'=>[]];
    $users = $db->query("SELECT id, nama FROM tb_users WHERE role='operator' ORDER BY nama");
    while ($user = $users->fetch_assoc()) {
        $data['operators'][(int)$user['id']] = ['name'=>$user['nama']] + kinerjaEmpty();
    }
    $tables = [];
    $result = $db->query('SHOW TABLES');
    while ($table = $result->fetch_row()) $tables[$table[0]] = true;
    $data['history'] = isset($tables['tb_permohonan_status_history']);
    $feedbackByRequest = [];
    if (isset($tables['tb_permohonan_feedback'])) {
        $column = $db->query("SHOW COLUMNS FROM tb_permohonan_feedback LIKE 'service_rating'");
        if ($column && $column->num_rows) {
            $stmt = $db->prepare("SELECT source_table,permohonan_id,status,jenis_feedback,respondent_role,service_rating,
                UNIX_TIMESTAMP(triggered_at) AS trigger_epoch,UNIX_TIMESTAMP(submitted_at) AS submitted_epoch
                FROM tb_permohonan_feedback WHERE status='submitted' AND jenis_feedback='tanggapan'
                AND respondent_role='pemohon' AND service_rating BETWEEN 1 AND 5 AND submitted_at < FROM_UNIXTIME(?)");
            $stmt->bind_param('i',$until); $stmt->execute();
            $feedbackRows = $stmt->get_result();
            while ($feedback = $feedbackRows->fetch_assoc()) $feedbackByRequest[$feedback['source_table']][(int)$feedback['permohonan_id']] = $feedback;
            $stmt->close();
        }
    }
    $trendFrom = (new DateTimeImmutable('@'.$until))->setTimezone(new DateTimeZone('Asia/Makassar'))
        ->modify('-1 second')->modify('first day of this month')->setTime(0,0)->modify('-5 months')->getTimestamp();
    foreach (kinerjaServices() as $table => [$label,$group]) {
        if (($service !== '' && $service !== $table) || ($unit !== '' && $unit !== $group)) continue;
        if (!isset($tables[$table])) { $data['missing'][] = $label; continue; }
        $key = $table === 'tb_laporan_peristiwa' ? 'id_lapor' : 'id';
        // Coverage audit uses submitted records in the selected interval, not inferred completion dates.
        $sql = "SELECT COUNT(*) AS n FROM $table p WHERE UNIX_TIMESTAMP(p.created_at)>=? AND UNIX_TIMESTAMP(p.created_at)<?";
        if ($data['history']) $sql .= " AND NOT EXISTS (SELECT 1 FROM tb_permohonan_status_history h WHERE h.source_table=? AND h.permohonan_id=p.$key AND UNIX_TIMESTAMP(h.created_at)<?)";
        $stmt = $db->prepare($sql);
        if ($data['history']) $stmt->bind_param('iisi', $from, $until, $table, $until);
        else $stmt->bind_param('ii', $from, $until);
        $stmt->execute(); $data['untracked'] += (int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();
        if (!$data['history']) continue;
        if ($table === 'tb_pengajuan_kia') {
            $applicantExpr = "COALESCE((SELECT u.nama FROM tb_users u WHERE u.id = p.id_user LIMIT 1), '')";
            $subjectExpr = "COALESCE((SELECT GROUP_CONCAT(a.nama_anak ORDER BY a.id ASC SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = p.id), p.no_kk)";
        } else {
            $applicantField = $table === 'tb_laporan_peristiwa' ? 'nama_pelapor' : 'nama_pemohon';
            $subjectField = match ($table) {
                'tb_akta_kelahiran', 'tb_akta_kelahiran_ahlak', 'tb_akta_kelahiran_rs' => 'nama_bayi',
                'tb_akta_kematian', 'tb_akta_kematian_rs' => 'nama_meninggal',
                'tb_laporan_peristiwa' => 'nama_subjek',
                default => 'nama_kepalaKeluarga',
            };
            $applicantExpr = "COALESCE(p.$applicantField, '')";
            $subjectExpr = "COALESCE(p.$subjectField, '')";
        }
        $stmt = $db->prepare("SELECT h.permohonan_id,LOWER(TRIM(COALESCE(h.status,''))) AS status,h.actor_id,LOWER(TRIM(COALESCE(h.actor_role,''))) AS actor_role,COALESCE(h.event_type,'') AS event_type,h.alasan,
            UNIX_TIMESTAMP(h.created_at) AS epoch, LOWER(TRIM(COALESCE(p.status,''))) AS record_status, UNIX_TIMESTAMP(p.updated_at) AS record_epoch, $applicantExpr AS applicant_name, $subjectExpr AS subject_name
            FROM tb_permohonan_status_history h INNER JOIN $table p ON p.$key=h.permohonan_id
            WHERE h.source_table=? AND h.created_at < FROM_UNIXTIME(?)
            ORDER BY h.permohonan_id,h.created_at,h.id");
        $stmt->bind_param('si', $table, $until); $stmt->execute();
        $stmt->bind_result($requestId,$status,$actorId,$actorRole,$eventType,$reason,$epoch,$recordStatus,$recordEpoch,$applicantName,$subjectName);
        $events = []; $lastId = null;
        $consume = static function(array $events, int $id) use (&$data,$table,$label,$group,$from,$until,$trendFrom,$feedbackByRequest): void {
            $cycles = kinerjaCycles($events);
            foreach ($cycles as $index => $row) {
                if ($row['status'] === 'diproses' && $index !== count($cycles) - 1) continue;
                $row += ['id'=>$id,'table'=>$table,'service'=>$label,'unit'=>$group];
                $latest = end($events);
                $row['applicant_name'] = $latest['applicant_name'];
                $row['subject_name'] = $latest['subject_name'];
                if ($index === count($cycles) - 1 && !empty($latest['record_epoch'])
                    && $latest['record_epoch'] < $until && $latest['record_status'] !== $row['status']) {
                    $row['operator'] = null;
                    $row['conflict'] = true;
                }
                if ($row['operator'] !== null && !isset($data['operators'][$row['operator']])) {
                    $data['operators'][$row['operator']] = ['name'=>'Operator #'.$row['operator'].' (arsip)'] + kinerjaEmpty();
                }
                if (in_array($row['status'], ['selesai','ditolak'], true) && $row['end'] >= $trendFrom && $row['operator'] !== null) {
                    $month = (new DateTimeImmutable('@'.$row['end']))->setTimezone(new DateTimeZone('Asia/Makassar'))->format('Y-m');
                    $data['trend'][$row['operator']][$month][$row['status']] =
                        ($data['trend'][$row['operator']][$month][$row['status']] ?? 0) + 1;
                }
                if (!in_array($row['status'], ['selesai','ditolak','diproses'], true)) continue;
                if ($row['status'] !== 'diproses' && $row['end'] < $from) continue;
                if ($row['status'] === 'diproses') $row['age'] = $row['start'] !== null ? max(0, $until - $row['start']) : null;
                if ($row['operator'] === null) { $data['excluded'][] = $row; continue; }
                $row['service_rating'] = kinerjaFeedbackRating($row, $cycles[$index + 1] ?? null, $feedbackByRequest[$table][$id] ?? null);
                kinerjaAdd($data['operators'][$row['operator']], $row);
                kinerjaAdd($data['total'], $row);
            }
        };
        while ($stmt->fetch()) {
            if ($lastId !== null && $lastId !== (int)$requestId) { $consume($events, $lastId); $events = []; }
            $lastId = (int)$requestId;
            $events[] = ['status'=>$status,'actor_id'=>$actorId,'actor_role'=>$actorRole,'event_type'=>$eventType,'alasan'=>$reason,'epoch'=>$epoch,'record_status'=>$recordStatus,'record_epoch'=>$recordEpoch,'applicant_name'=>$applicantName,'subject_name'=>$subjectName];
        }
        if ($lastId !== null) $consume($events,$lastId);
        $stmt->close();
    }
    return $data;
}