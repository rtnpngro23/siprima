<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../public/user/functions/feedback_validation.php';
require_once __DIR__.'/../app/Laporan/KinerjaOperator.php';
$count = 0;
function fvCheck($ok,$label) { global $count; if (!$ok) throw new RuntimeException($label); $count++; }
function fvRejected(array $data): bool {
    try { permohonan_feedback_validate($data); return false; } catch (InvalidArgumentException $e) { return true; }
}
for($web=1;$web<=5;$web++) for($service=1;$service<=5;$service++) {
    $data=['jenis_feedback'=>'tanggapan','rating'=>(string)$web,'service_rating'=>(string)$service,'pesan'=>''];
    fvCheck(fvRejected($data)===($web<=3||$service<=3),"Empty comment $web/$service");
    $data['pesan']='Masukan pemohon';
    $valid=permohonan_feedback_validate($data);
    fvCheck($valid['rating']===$web && $valid['service_rating']===$service,"Saved scores $web/$service");
}
$base=['jenis_feedback'=>'tanggapan','rating'=>'5','service_rating'=>'5','pesan'=>''];
foreach([0,6,-1,'3.5','5abc',[],true,null] as $invalid) {
    fvCheck(fvRejected(array_replace($base,['rating'=>$invalid])),'Reject invalid app rating');
    fvCheck(fvRejected(array_replace($base,['service_rating'=>$invalid])),'Reject invalid service rating');
}
fvCheck(fvRejected(['jenis_feedback'=>'tanggapan','rating'=>'5']),'Missing service rating not fabricated');
fvCheck(fvRejected(array_replace($base,['service_rating'=>3,'pesan'=>" \n\t\u{00a0}"])),'Whitespace not accepted');
fvCheck(fvRejected(array_replace($base,['pesan'=>str_repeat('a',1001)])),'Max length enforced');
fvCheck(fvRejected(array_replace($base,['pesan'=>[]])),'Array message rejected');
fvCheck(fvRejected(['jenis_feedback'=>'aduan','pesan'=>'']),'Complaint requires message');
$complaint=permohonan_feedback_validate(['jenis_feedback'=>'aduan','pesan'=>'Dokumen belum tersedia','rating'=>5,'service_rating'=>5]);
fvCheck($complaint['rating']===null && $complaint['service_rating']===null,'Complaint stores no default ratings');
$cycle=['status'=>'selesai','operator'=>7,'end'=>100];
$feedback=['status'=>'submitted','jenis_feedback'=>'tanggapan','respondent_role'=>'pemohon','service_rating'=>4,'trigger_epoch'=>101,'submitted_epoch'=>102];
fvCheck(kinerjaFeedbackRating($cycle,null,$feedback)===4,'Service rating attributed');
fvCheck(kinerjaFeedbackRating($cycle,null,array_replace($feedback,['service_rating'=>null]))===null,'Historical rating excluded');
fvCheck(kinerjaFeedbackRating($cycle,null,array_replace($feedback,['respondent_role'=>'petugas_registrasi']))===null,'Registrar ratings kept separate');
fvCheck(kinerjaFeedbackRating(array_replace($cycle,['operator'=>null]),null,$feedback)===null,'Shared work excluded');
fvCheck(kinerjaFeedbackRating($cycle,['began'=>101],$feedback)===null,'Resubmission not credited to old cycle');
fvCheck(kinerjaFeedbackRating($cycle,null,array_replace($feedback,['status'=>'skipped']))===null,'Skipped not counted');
fvCheck(kinerjaFeedbackRating($cycle,null,array_replace($feedback,['jenis_feedback'=>'aduan']))===null,'Complaint not scored');
fvCheck(kinerjaFeedbackRating($cycle,null,array_replace($feedback,['trigger_epoch'=>99]))===null,'Feedback before completion excluded');
echo "$count feedback checks passed\n";