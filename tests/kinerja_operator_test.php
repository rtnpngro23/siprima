<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../app/Laporan/KinerjaOperator.php';
$count = 0;
function checkK($ok,$label) { global $count; if (!$ok) throw new RuntimeException($label); $count++; }
function eventK($status,$time,$actor=7,$type='status_update',$role='operator') {
    return ['status'=>$status,'epoch'=>$time,'actor_id'=>$actor,'actor_role'=>$role,'event_type'=>$type,'alasan'=>'Syarat belum lengkap'];
}
$a = [eventK('diajukan',100,1,'submission','user'),eventK('diproses',200),eventK('selesai',3800)];
$r = kinerjaCycles($a);
checkK(count($r)===1 && $r[0]['duration']===3600 && $r[0]['operator']===7,'Complete cycle attributed to real actor');
$b = $a; array_splice($b,2,0,[eventK('diproses',300,7,'progress_update')]);
checkK(kinerjaCycles($b)[0]['duration']===3600,'Progress does not reset start');
$b = $a; $b[2]['actor_id']=8;
checkK(kinerjaCycles($b)[0]['shared'] && kinerjaCycles($b)[0]['operator']===null,'Shared work excluded');
$b = $a; $b[2]['actor_role']='admin';
checkK(kinerjaCycles($b)[0]['operator']===null,'Admin intervention not credited to operator');
checkK(kinerjaCycles([eventK('selesai',3800)])[0]['duration']===null,'Missing process start not fabricated');
$b = [eventK('diproses',200,7,'progress_update'),eventK('diproses',300),eventK('selesai',3800)];
checkK(kinerjaCycles($b)[0]['duration']===null,'Incomplete progress history cannot fabricate start');
$b = [eventK('diproses',200),eventK('ditolak',300),eventK('diajukan',400,1,'resubmit','user'),eventK('diproses',600,8),eventK('selesai',1200,8)];
$r = kinerjaCycles($b);
checkK(count($r)===2 && $r[0]['operator']===7 && $r[1]['operator']===8 && $r[1]['duration']===600,'Resubmit resets operator and time');
$b = $a; $b[]=eventK('selesai',4000); $b[]=eventK('selesai',4500,8,'document_upload');
checkK(count(kinerjaCycles($b))===1 && kinerjaCycles($b)[0]['end']===3800,'Duplicate terminal and upload not counted twice');
checkK(kinerjaCycles([])===[],'Empty history');
checkK(kinerjaMean([])===null && kinerjaMedian([])===null,'Empty metrics not zero');
checkK(kinerjaMean([60,180,300])===180.0 && kinerjaMedian([60,180,300])===180.0,'Mean and odd median');
checkK(kinerjaMedian([60,180])===120.0,'Even median');
checkK(kinerjaDate('2026-02-30')===null && kinerjaDate('2026-02-28')!==null,'Date boundaries');
$b = [eventK('diproses',200),eventK('selesai',100)];
checkK(kinerjaCycles($b)[0]['duration']===null,'Negative duration excluded');
$b = [eventK('diproses',200),eventK('selesai',300),eventK('ditolak',400)];
checkK(kinerjaCycles($b)[0]['operator']===null,'Conflicting final decisions excluded');
echo "$count calculation checks passed\n";
if (!in_array('--read-only-integration',$argv,true)) exit;
require_once __DIR__.'/../app/config/env_loader.php';
loadEnv(__DIR__.'/../app/config/.env');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli((string)env('DB_HOST','localhost'),(string)env('DB_USER','root'),(string)env('DB_PASS',''),(string)env('DB_NAME'));
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "Integration unavailable: database connection failed.\n");
    exit(2);
}
$db->set_charset('utf8mb4');
$db->query('SET SESSION TRANSACTION READ ONLY');
$today = new DateTimeImmutable('today',new DateTimeZone('Asia/Makassar'));
$data = kinerjaLoad($db,$today->modify('first day of this month')->getTimestamp(),time(),'','');
echo json_encode(['history_available'=>$data['history'],'operator_count'=>count($data['operators']),'completed'=>$data['total']['selesai'],'rejected'=>$data['total']['ditolak'],'processing'=>$data['total']['diproses'],'untracked'=>$data['untracked'],'excluded'=>count($data['excluded']),'missing_sources'=>$data['missing']],JSON_UNESCAPED_UNICODE)."\n";
$db->close();