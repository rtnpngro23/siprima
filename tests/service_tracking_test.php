<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../public/user/service_tracking.php';
$count = 0;
function verify($condition, string $label): void { global $count; if (!$condition) throw new RuntimeException($label); $count++; }
verify(serviceTrackingTransitionAllowed('diajukan', 'diproses'), 'Start processing');
verify(!serviceTrackingTransitionAllowed('diajukan', 'selesai'), 'Cannot skip processing');
verify(serviceTrackingTransitionAllowed('diproses', 'ditolak'), 'Rejection from processing');
verify(!serviceTrackingTransitionAllowed('selesai', 'diproses'), 'Terminal state protected');
verify(!serviceTrackingTransitionAllowed('unknown', 'unknown'), 'Unknown states rejected');
$sample = [
    ['id'=>1,'status'=>'diajukan','epoch'=>0,'created_at_formatted'=>'08:00 WITA'],
    ['id'=>2,'status'=>'diproses','epoch'=>3600,'created_at_formatted'=>'09:00 WITA','actor_name'=>'<script>','actor_role'=>'operator','alasan'=>'<img onerror=alert(1)>'],
    ['id'=>3,'status'=>'selesai','epoch'=>9000,'created_at_formatted'=>'10:30 WITA'],
];
$html = serviceTrackingHtml($sample);
verify(str_contains($html, 'Total pelayanan: 0 hari 2 jam 30 menit'), 'Total calendar duration');
verify(str_contains($html, 'Waktu proses: 0 hari 1 jam 30 menit'), 'Processing duration');
verify(!str_contains($html, '<script>') && !str_contains($html, '<img '), 'Timeline escapes names and notes');
verify(!str_contains(serviceTrackingHtml([$sample[2]]), 'Waktu proses:'), 'Missing times are not guessed');
$sample[] = ['id'=>4,'status'=>'diajukan','epoch'=>10000,'created_at_formatted'=>'11:00 WITA'];
verify(!str_contains(serviceTrackingHtml($sample), 'Total pelayanan:'), 'Resubmission starts a fresh cycle');
$progressEvent = ['id'=>5,'status'=>'diproses','event_type'=>'progress_update','epoch'=>7200,'created_at_formatted'=>'10:00 WITA','alasan'=>'Menunggu persetujuan Dukcapil asal'];
$progressHtml = serviceTrackingHtml([$sample[0], $sample[1], $progressEvent, $sample[2]]);
verify(str_contains($progressHtml, 'Waktu proses: 0 hari 1 jam 30 menit') && str_contains($progressHtml, 'Mulai diproses: <strong>09:00 WITA'), 'Progress does not reset processing start or duration');
verify(str_contains($progressHtml, 'Pembaruan progres'), 'Progress event labelled distinctly');
verify(!str_contains(serviceTrackingHtml([$progressEvent, $sample[2]]), 'Waktu proses:'), 'Legacy progress is not treated as a known processing start');
$shortCycle = [
    ['id'=>1,'status'=>'diajukan','epoch'=>100,'created_at_formatted'=>'Waktu uji'],
    ['id'=>2,'status'=>'diproses','epoch'=>114,'created_at_formatted'=>'Waktu uji'],
    ['id'=>3,'status'=>'selesai','epoch'=>129,'created_at_formatted'=>'Waktu uji'],
];
foreach ([false, true] as $visual) {
    $shortHtml = serviceTrackingHtml($shortCycle, $visual);
    verify(str_contains($shortHtml, 'Waktu tunggu: 0 hari 0 jam 0 menit 14 detik'), 'Sub-minute waiting time is visible');
    verify(str_contains($shortHtml, 'Waktu proses: 0 hari 0 jam 0 menit 15 detik'), 'Sub-minute processing time is visible');
    verify(str_contains($shortHtml, 'Total pelayanan: 0 hari 0 jam 0 menit 29 detik'), 'Sub-minute total is visible');
}
$shortCycle[2]['epoch'] = 114;
verify(str_contains(serviceTrackingHtml($shortCycle), 'Waktu proses: 0 hari 0 jam 0 menit 0 detik'), 'True zero duration stays zero');
$shortCycle[2]['epoch'] = 114 + 90061;
verify(str_contains(serviceTrackingHtml($shortCycle), 'Waktu proses: 1 hari 1 jam 1 menit 1 detik'), 'Day hour minute second boundaries');
echo "Unit checks: $count passed\n";
if (!in_array('--integration', $argv, true)) exit;
require_once __DIR__ . '/../app/config/env_loader.php';
loadEnv(__DIR__ . '/../app/config/.env');
if (!in_array((string)env('DB_HOST','localhost'), ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Local database required');
$db = new mysqli((string)env('DB_HOST','localhost'), (string)env('DB_USER','root'), (string)env('DB_PASS',''));
$testDb = 'siprima_tracking_test_' . bin2hex(random_bytes(4));
$created = false;
function startRequest(array $post, string $upload = ''): array {
    global $testDb;
    $process = proc_open([PHP_BINARY, __DIR__ . '/service_tracking_fixture.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode(['database'=>$testDb,'post'=>$post,'upload'=>$upload])); fclose($pipes[0]);
    return [$process, $pipes];
}
function finishRequest(array $request): array {
    [$process, $pipes] = $request;
    $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Fixture failed: ' . substr($err,0,300));
    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}
function request(array $post, string $upload=''): array { return finishRequest(startRequest($post,$upload)); }
function version(int $id=1): int { global $db; return (int)$db->query('SELECT COALESCE(MAX(id),0) AS v FROM tb_permohonan_status_history WHERE permohonan_id='.$id)->fetch_assoc()['v']; }
function currentStatus(int $id=1): string { global $db; return $db->query('SELECT status FROM tb_kartukeluarga WHERE id='.$id)->fetch_assoc()['status']; }
try {
    $db->query("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4"); $created = true; $db->select_db($testDb);
    $db->query('CREATE TABLE tb_users (id INT PRIMARY KEY, nama VARCHAR(100)) ENGINE=InnoDB');
    $db->query("INSERT INTO tb_users VALUES (7,'Petugas Uji'),(8,'Pemohon Uji')");
    $db->query("CREATE TABLE tb_kartukeluarga (id INT PRIMARY KEY, id_user INT, nama_kecamatan VARCHAR(100), status VARCHAR(32), alasan TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $db->query("INSERT INTO tb_kartukeluarga (id,id_user,nama_kecamatan,status) VALUES (1,8,'Wilayah Uji','diajukan'),(2,8,'Wilayah Lain','diajukan'),(3,8,'Wilayah Uji','diajukan'),(4,8,'Wilayah Uji','diproses')");
    ensurePermohonanStatusHistoryTable($db);
    $base=['id'=>1,'table'=>'tb_kartukeluarga','status'=>'diproses','alasan'=>'','expected_status'=>'diajukan','history_version'=>0,'status_ajax'=>'1'];
    $r=request(array_merge($base,['id'=>2])); verify($r['code']===403 && currentStatus(2)==='diajukan', 'Cross-region update denied');
    $r=request(array_merge($base,['table'=>'tb_users'])); verify($r['code']===422, 'Table allowlist');
    $r=request($base); verify($r['body']['success'] && currentStatus()==='diproses', 'Empty processing note accepted');
    verify(count(serviceTrackingHistory($db,'tb_kartukeluarga',1))===2, 'Submission and transition both logged');
    $history=serviceTrackingHistory($db,'tb_kartukeluarga',1,true);
    verify($history[1]['status_from']==='diajukan' && $history[1]['actor_name']==='Petugas <uji>', 'Previous state and actor snapshot saved');
    verify(str_ends_with($history[1]['created_at_formatted'],'WITA'), 'WITA timestamp');
    verify(!isset(serviceTrackingHistory($db,'tb_kartukeluarga',1)[1]['actor_name']), 'Public timeline omits staff identity');
    $v=version(); $r=request($base); verify($r['code']===409 && version()===$v, 'Duplicate stale submission rejected');
    $process=array_merge($base,['expected_status'=>'diproses','history_version'=>$v]);
    $r=request($process); verify($r['code']===422 && version()===$v, 'Same status adds no log');
    $progressPost=array_merge($process,['alasan'=>'Menunggu persetujuan Dukcapil asal']);
    $r=request($progressPost);
    verify($r['body']['success'] && currentStatus()==='diproses' && $r['body']['status_note']===$progressPost['alasan'], 'Same status accepts new progress note');
    $newHistory=serviceTrackingHistory($db,'tb_kartukeluarga',1,true);
    verify(end($newHistory)['event_type']==='progress_update' && $newHistory[1]['epoch']===$history[1]['epoch'], 'Progress logged without overwriting processing start');
    $v=version();
    $r=request(array_merge($progressPost,['history_version'=>$v,'alasan'=>'  Menunggu persetujuan Dukcapil asal  ']));
    verify($r['code']===422 && version()===$v, 'Duplicate progress note adds no log');
    $r=request($progressPost); verify($r['code']===409, 'Stale progress submission rejected');
    $process['history_version']=$v;
    $db->query("CREATE TRIGGER fail_progress BEFORE INSERT ON tb_permohonan_status_history FOR EACH ROW BEGIN IF NEW.event_type='progress_update' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected progress failure'; END IF; END");
    $r=request(array_merge($process,['alasan'=>'Persetujuan telah diterima']));
    verify($r['code']===500 && version()===$v && $db->query('SELECT alasan FROM tb_kartukeluarga WHERE id=1')->fetch_assoc()['alasan']===$progressPost['alasan'], 'Failed progress log rolls back note');
    $db->query('DROP TRIGGER fail_progress');
    $r=request(array_merge($process,['status'=>'ditolak'])); verify($r['code']===422 && currentStatus()==='diproses', 'Rejection requires reason');
    $db->query("CREATE TRIGGER fail_tracking BEFORE INSERT ON tb_permohonan_status_history FOR EACH ROW BEGIN IF NEW.status='selesai' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected log failure'; END IF; END");
    $r=request(array_merge($process,['status'=>'selesai'])); verify($r['code']===500 && currentStatus()==='diproses' && version()===$v, 'Failed history insert rolls back status');
    $db->query('DROP TRIGGER fail_tracking');
    $r=request(array_merge($process,['status'=>'selesai']),'fail'); verify($r['body']['success'] && $r['body']['partial'] && currentStatus()==='selesai', 'Upload failure reports committed status explicitly');
    $done=array_merge($process,['status'=>'selesai','expected_status'=>'selesai','history_version'=>version()]);
    $v=version(); $r=request($done,'ok'); verify($r['body']['files_uploaded'] && version()===$v, 'Upload retry does not duplicate status history');
    $r=request(array_merge($done,['status'=>'diproses'])); verify($r['code']===422, 'Completed state cannot be reopened');
    $parallel=array_merge($base,['id'=>3]);
    $a=startRequest($parallel); $b=startRequest($parallel);
    $results=[finishRequest($a),finishRequest($b)]; $codes=array_column($results,'code'); sort($codes);
    verify($codes===[200,409], 'Concurrent operators produce one winner and one conflict');
    $r=request(array_merge($base,['id'=>4,'status'=>'ditolak','expected_status'=>'diproses','alasan'=>'Berkas tidak lengkap.']));
    verify($r['body']['success'] && str_contains($r['body']['history_html'],'Berkas tidak lengkap.'), 'Rejection reason rendered');
    echo "All checks: $count passed\n";
} finally {
    if ($created && preg_match('/^siprima_tracking_test_[a-f0-9]{8}$/D',$testDb)) $db->query("DROP DATABASE `$testDb`");
    $db->close();
}
