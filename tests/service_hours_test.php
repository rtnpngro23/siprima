<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../app/config/service_hours.php';
$count=0;
function check($condition, string $label): void { global $count; if (!$condition) throw new RuntimeException($label); $count++; }
function closedAt(array $s, string $at): bool { return !service_hours_status(service_hours_datetime($at),$s)['is_open']; }
function invalid(array $s, string $label): void {
    try { service_hours_validate($s); } catch (InvalidArgumentException $e) { check(true,$label); return; }
    throw new RuntimeException($label);
}
$s=service_hours_defaults();
check(!closedAt($s,'2026-09-06T22:00'),'Disabled schedule stays open');
$s['enabled']=true;
check(closedAt($s,'2026-09-07T07:59'),'Before opening');
check(!closedAt($s,'2026-09-07T08:00'),'Opening inclusive');
check(!closedAt($s,'2026-09-07T15:29'),'Before closing');
check(closedAt($s,'2026-09-07T15:30'),'Closing exclusive');
check(closedAt($s,'2026-09-06T10:00'),'Weekend');
check(service_hours_status(new DateTimeImmutable('2026-09-07T00:00:00Z'),$s)['is_open'] === true,'UTC converted to WITA');
$event=['id'=>'1234567890abcdef','enabled'=>true,'kind'=>'closure','title'=>'Maintenance','message'=>'Network work',
    'announce'=>'2026-09-07T08:00','start'=>'2026-09-07T13:00','end'=>'2026-09-08T10:00'];
$s['events']=[$event];
service_hours_validate($s);
check(!closedAt($s,'2026-09-07T12:59'),'Before event');
check(closedAt($s,'2026-09-07T13:00'),'Event starts');
check(closedAt($s,'2026-09-08T09:59'),'Event spans days');
check(!closedAt($s,'2026-09-08T10:00'),'Event ends automatically');
$s['enabled']=false;
check(closedAt($s,'2026-09-07T14:00'),'Closure overrides disabled routine');
$s['events'][0]['kind']='notice';
check(!closedAt($s,'2026-09-07T14:00'),'Notice does not close submissions');
$s['events'][0]=$event;
$s['events'][0]['enabled']=false;
check(!closedAt($s,'2026-09-07T14:00'),'Cancelled closure');
$s['events']=[$event];
$other=$event; $other['id']='abcdef1234567890'; $other['start']='2026-09-08T09:00'; $other['end']='2026-09-08T11:00';
$s['events'][]=$other; invalid($s,'Overlap rejected');
$s['events'][1]['start']=$event['end']; service_hours_validate($s); check(true,'Adjacent events allowed');
$s['events'][1]['end']='2026-09-08T08:00'; invalid($s,'End before start');
$s=service_hours_defaults(); $s['open']='25:00'; invalid($s,'Invalid time');
$s=service_hours_defaults(); $s['days']=[]; invalid($s,'Empty days');
$s=service_hours_defaults(); $s['events']=[$event]; $s['events'][0]['announce']='2026-02-30T08:00'; invalid($s,'Invalid calendar day');
check(service_hours_session_restricted(['role'=>'user']),'LINE restricted');
check(service_hours_session_restricted(['role'=>'petugas_desa']),'ADD restricted');
foreach (['id_kua','id_puskes','id_rs'] as $key) check(!service_hours_session_restricted(['role'=>'user',$key=>1]),$key.' exempt');
foreach (['admin','operator'] as $role) check(!service_hours_session_restricted(['role'=>$role]),$role.' exempt');
check(service_hours_notice_for($event,service_hours_datetime('2026-09-07T12:00'),service_hours_defaults())['state']==='upcoming','Upcoming notice');
check(service_hours_notice_for($event,service_hours_datetime('2026-09-07T13:00'),service_hours_defaults())['state']==='active','Active notice');
$s=service_hours_defaults(); $s['enabled']=true;
$change=service_hours_next_change(service_hours_datetime('2026-09-06T10:00'),$s);
check($change['at']==='07/09/2026 08:00 WITA' && $change['is_open'],'Next weekday opening');
$s['events']=[$event]; $s['events'][0]['end']='2026-10-10T10:00';
$change=service_hours_next_change(service_hours_datetime('2026-09-08T10:00'),$s);
check($change['at']==='12/10/2026 08:00 WITA' && $change['is_open'],'Next opening after long event ends on weekend');
$s['unavailable']=true;
check(closedAt($s,'2026-09-07T08:00'),'Unavailable settings block submission');
check(service_hours_next_change(null,$s)===null,'Unavailable settings have no predicted transition');
require_once __DIR__.'/../app/middleware/service_submission.php';
$s=service_hours_defaults(); $s['enabled']=true;
$e=$event; $e['start']='2026-09-07T13:00'; $e['end']='2026-09-07T14:00'; $s['events']=[$e];
check(strpos(service_hours_resume_message($e,$s),'pukul 14:00 WITA')!==false,'Resume same day within service hours');
$e['end']='2026-09-07T15:30'; $s['events']=[$e];
check(strpos(service_hours_resume_message($e,$s),'Selasa, 08/09/2026 pukul 08:00 WITA')!==false,'Exact close resumes next day');
$e['start']='2026-09-11T13:00'; $e['end']='2026-09-11T17:00'; $s['events']=[$e];
check(strpos(service_hours_resume_message($e,$s),'Senin, 14/09/2026 pukul 08:00 WITA')!==false,'Skip weekend after late event');
$e['start']='2026-09-07T06:00'; $e['end']='2026-09-07T07:00'; $s['events']=[$e];
check(strpos(service_hours_resume_message($e,$s),'pukul 08:00 WITA')!==false,'Early event resumes at opening');
$s['enabled']=false;
check(strpos(service_hours_resume_message($e,$s),'pukul 07:00 WITA')!==false,'No routine restriction resumes at event end');
$s['enabled']=true; $e=$event; $e['end']='2026-09-07T14:00'; $s['events']=[$e];
$state=service_hours_client_state(service_hours_datetime('2026-09-07T12:57'),$s);
check(strpos($state['message'],'Network work')===0,'Announcement first');
check(strpos($state['message'],"\n\nSisa waktu pengajuan sekitar 3 menit.")!==false,'Remaining minutes in following paragraph');
check(strpos($state['message'],'pukul 13:00 WITA')!==false,'Countdown has exact closing time');
$state=service_hours_client_state(service_hours_datetime('2026-09-07T13:30'),$s);
check(!$state['is_open'] && strpos($state['message'],'kembali pada pukul 14:00 WITA')!==false,'Active event retains reopening time');
$e2=$e; $e2['id']='fedcba0987654321'; $e2['start']=$e['end']; $e2['end']='2026-09-07T14:30'; $s['events'][]=$e2;
check(strpos(service_hours_resume_message($e,$s),'pukul 14:30 WITA')!==false,'Adjacent closure delays reopening');
$before=service_hours_notice_for($e,service_hours_datetime('2026-09-07T12:57'),$s);
$during=service_hours_notice_for($e,service_hours_datetime('2026-09-07T13:30'),$s);
check($before['version']===$during['version'],'Popup version stable across upcoming/active transition');
$edited=$e; $edited['message']='Updated announcement';
check(service_hours_notice_for($edited,service_hours_datetime('2026-09-07T12:57'),$s)['version']!==$before['version'],'Changed announcement has new popup version');
echo "Rule tests passed: $count\n";

if (!in_array('--integration',$argv,true)) exit;
$db=service_hours_db();
require_once __DIR__.'/../app/config/env_loader.php';
if (!in_array((string)env('DB_HOST','localhost'),['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Integration requires local database.');
$testDb='siprima_service_test_'.bin2hex(random_bytes(4));
$original=(string)env('DB_NAME');
function requestTest(array $input): array {
    global $testDb;
    $input['database']=$testDb;
    $process=proc_open([PHP_BINARY,__DIR__.'/service_request_fixture.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture');
    fwrite($pipes[0],json_encode($input)); fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code=proc_close($process);
    if ($code!==0) throw new RuntimeException('Fixture failed: '.substr($err,0,300));
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function revision(): int { global $db; return (int)$db->query('SELECT revision FROM tb_service_settings WHERE id=1')->fetch_assoc()['revision']; }
try {
    if (!$db->query("CREATE DATABASE $testDb CHARACTER SET utf8mb4")) throw new RuntimeException('Cannot create isolated test database');
    $db->select_db($testDb);
    $sql=file_get_contents(__DIR__.'/../sql/20260906_service_settings.sql');
    if (!$db->multi_query($sql)) throw new RuntimeException('Test migration failed');
    do { if ($r=$db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException('Test migration failed');
    $r=requestTest([]); check(strpos($r['body'],'Pengaturan Layanan')!==false,'Admin page renders');
    $r=requestTest(['role'=>'user']); check(strpos($r['body'],'Pengaturan Layanan')===false && !$r['saved'],'Nonadmin blocked');
    $post=['action'=>'schedule','revision'=>revision(),'enabled'=>'on','days'=>[1,2,3,4,5],'open'=>'08:00','close'=>'15:30','csrf_token'=>'fixture-csrf'];
    $bad=$post; $bad['csrf_token']='wrong';
    $r=requestTest(['method'=>'POST','post'=>$bad]); check(!$r['saved'] && revision()===1,'CSRF rejected');
    $bad=$post; $bad['close']='07:00';
    $r=requestTest(['method'=>'POST','post'=>$bad]); check(!$r['saved'] && revision()===1,'Invalid schedule not saved');
    $r=requestTest(['method'=>'POST','post'=>$post]); check($r['saved'] && revision()===2,'Schedule save');
    $r=requestTest(['method'=>'POST','post'=>$post]); check(!$r['saved'] && revision()===2,'Stale revision rejected');
    $now=new DateTimeImmutable('now',new DateTimeZone(SERVICE_HOURS_TIMEZONE));
    $post=['action'=>'event','revision'=>revision(),'csrf_token'=>'fixture-csrf','event_enabled'=>'on','kind'=>'closure',
        'title'=>'<script>alert(1)</script>','message'=>'Test closure','announce'=>$now->modify('-2 hours')->format('Y-m-d\TH:i'),
        'start'=>$now->modify('-1 hour')->format('Y-m-d\TH:i'),'end'=>$now->modify('+1 hour')->format('Y-m-d\TH:i')];
    $preview=$post; $preview['action']='preview';
    $r=requestTest(['method'=>'POST','post'=>$preview]); check(!$r['saved'] && revision()===2 && strpos($r['body'],'&lt;script&gt;')!==false,'Preview escapes content without saving');
    $r=requestTest(['method'=>'POST','post'=>$post]); check($r['saved'],'Closure save');
    $r=requestTest([]); check(strpos($r['body'],'<script>alert(1)</script>')===false && strpos($r['body'],'&lt;script&gt;')!==false,'Stored content escaped');
    $post['revision']=revision();
    $r=requestTest(['method'=>'POST','post'=>$post]); check(!$r['saved'],'Overlapping closure rejected by admin');
    foreach (['user','petugas_desa'] as $role) {
        $r=requestTest(['target'=>'middleware','role'=>$role]);
        check($r['session_active'] && strpos($r['body'],'READ_ACCESS_OK')!==false,'Read access survives closure: '.$role);
        $r=requestTest(['target'=>'status','role'=>$role]);
        $state=json_decode($r['body'],true);
        check($state['is_open']===false,'Status reports closure: '.$role);
    }
    foreach (['id_kua','id_puskes','id_rs'] as $key) {
        $r=requestTest(['target'=>'status','role'=>'user','session'=>[$key=>1]]);
        check(json_decode($r['body'],true)['is_open']===true,'Always-open status: '.$key);
        $r=requestTest(['target'=>'guard','role'=>'user','session'=>[$key=>1]]);
        check(strpos($r['body'],'SUBMISSION_ALLOWED')!==false,'Always-open submission guard: '.$key);
    }
    $files=glob(__DIR__.'/../public/user/*.php');
    $guardCount=0;
    foreach ($files as $file) {
        if (basename($file)==='petugas_desa_lapor_peristiwa.php' || strpos(file_get_contents($file),'service_hours_guard_submission();')===false) continue;
        $guardCount++;
        $name=basename($file);
        foreach (['GET','POST'] as $method) {
            $r=requestTest(['target'=>'form','file'=>$name,'method'=>$method,'post'=>['csrf_token'=>'fixture-csrf'],
                'role'=>strpos($name,'petugas_desa_')===0?'petugas_desa':'user','accept'=>'application/json']);
            check($r['code']===403 && $r['session_active'] && json_decode($r['body'],true)['error']==='submission_closed','Guard '.$method.' '.$name);
        }
    }
    check($guardCount===22,'All 22 form guards exercised');
    $r=requestTest(['target'=>'home']);
    check(strpos($r['body'],'<dialog id="service-announcement-dialog"')!==false,'Homepage closure renders dialog');
    check(strpos($r['body'],'id="service-home-notice"')!==false,'Homepage retains banner');
    check(strpos($r['body'],'<script>alert(1)</script>')===false,'Homepage notice escapes title');
    check(strpos($r['body'],'&lt;script&gt;')!==false,'Homepage escaped title visible');
    $r=requestTest(['target'=>'form','file'=>'petugas_desa_lapor_peristiwa.php','method'=>'POST','role'=>'petugas_desa','accept'=>'application/json',
        'post'=>['csrf_token'=>'fixture-csrf','action'=>'update_status_peristiwa','status'=>'diajukan']]);
    check($r['code']===403 && $r['session_active'],'Report resubmission blocked');
    $payload=json_decode($db->query('SELECT payload FROM tb_service_settings WHERE id=1')->fetch_assoc()['payload'],true);
    $activeEvents=array_values(array_filter($payload['events'],function ($e) { return $e['title']==='<script>alert(1)</script>'; }));
    $id=$activeEvents[0]['id'];
    $r=requestTest(['method'=>'POST','post'=>['action'=>'cancel','revision'=>revision(),'event_id'=>$id,'csrf_token'=>'fixture-csrf']]);
    check($r['saved'],'Cancel event');
    $post=['action'=>'schedule','revision'=>revision(),'days'=>[1,2,3,4,5],'open'=>'08:00','close'=>'15:30','csrf_token'=>'fixture-csrf'];
    $r=requestTest(['method'=>'POST','post'=>$post]); check($r['saved'],'Disable routine');
    $r=requestTest(['target'=>'status','role'=>'user']);
    check(json_decode($r['body'],true)['is_open']===true,'Submissions reopen after cancellation with routine off');
    check((int)$db->query('SELECT COUNT(*) AS n FROM tb_service_settings_audit')->fetch_assoc()['n']===4,'Audit only records successful writes');
    $r=requestTest(['target'=>'guard','role'=>'user']);
    check(strpos($r['body'],'SUBMISSION_ALLOWED')!==false,'Open schedule allows submission guard');
    $beforeRevision=revision();
    $db->query('RENAME TABLE tb_service_settings_audit TO tb_service_settings_audit_hold');
    $post['revision']=$beforeRevision;
    $post['open']='09:00';
    $r=requestTest(['method'=>'POST','post'=>$post]);
    check(!$r['saved'] && revision()===$beforeRevision,'Audit failure rolls back settings atomically');
    $db->query('RENAME TABLE tb_service_settings_audit_hold TO tb_service_settings_audit');
    $delete=['action'=>'delete','revision'=>revision(),'event_id'=>$id,'csrf_token'=>'fixture-csrf'];
    $bad=$delete; $bad['csrf_token']='wrong';
    $r=requestTest(['method'=>'POST','post'=>$bad]);
    check(!$r['saved'] && revision()===$delete['revision'],'Delete rejects invalid CSRF');
    $r=requestTest(['method'=>'POST','post'=>$delete,'role'=>'user']);
    check(!$r['saved'] && revision()===$delete['revision'],'Delete rejects nonadmin');
    $bad=$delete; $bad['event_id']='does-not-exist';
    $r=requestTest(['method'=>'POST','post'=>$bad]);
    check(!$r['saved'] && revision()===$delete['revision'],'Delete rejects missing event');
    $bad=$delete; $bad['revision']=0;
    $r=requestTest(['method'=>'POST','post'=>$bad]);
    check(!$r['saved'] && revision()===$delete['revision'],'Delete rejects stale revision');
    $r=requestTest(['method'=>'POST','post'=>$delete]);
    check($r['saved'],'Event deleted successfully');
    $after=json_decode($db->query('SELECT payload FROM tb_service_settings WHERE id=1')->fetch_assoc()['payload'],true);
    check(!in_array($id,array_column($after['events'],'id'),true),'Deleted event removed from settings');
    $last=$db->query('SELECT action,before_payload,after_payload FROM tb_service_settings_audit ORDER BY id DESC LIMIT 1')->fetch_assoc();
    check($last['action']==='delete' && strpos($last['before_payload'],$id)!==false && strpos($last['after_payload'],$id)===false,'Delete audited with before and after');
    echo "All rule and integration checks passed: $count\n";
} finally {
    // Only the random database created by this test may be removed.
    if (preg_match('/^siprima_service_test_[a-f0-9]{8}$/D',$testDb) && $testDb !== $original) {
        $db->select_db($original);
        $db->query("DROP DATABASE IF EXISTS $testDb");
    }
}