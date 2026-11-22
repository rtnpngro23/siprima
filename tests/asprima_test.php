<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../public/user/asprima_helper.php';
$count=0;
function check($ok,$label) { global $count; if (!$ok) throw new RuntimeException($label); $count++; }
foreach (['status_permohonan.php','petugas_desa_status_permohonan.php','ajukan_permohonan.php','petugas_desa_edit_kk.php','rs_status_permohonan.php','ahlak_ajukan_permohonan.php','kua_edit_kk.php'] as $page) check(!asprima_widget_allowed(['id_user'=>1,'role'=>'user'],$page),'No widget: '.$page);
foreach (['dashboard_user.php','dashboard_petugas_desa.php','rs_dashboard_user.php','kua_dashboard_user.php','ahlak_dashboard_user.php'] as $page) check(asprima_widget_allowed(['id_user'=>1,'role'=>'user'],$page),'Dashboard enabled');
check(!asprima_widget_allowed(['id_user'=>1,'role'=>'operator'],'dashboard_user.php'),'Operator denied');
check(!asprima_widget_allowed([],'dashboard_user.php'),'Anonymous denied');
$user=['id'=>7,'kind'=>'user'];
check(asprima_scope($user)===['id_user=?','i',[7]],'User ownership');
check(asprima_scope(['id'=>8,'kind'=>'rs'])===['id_user=?','i',[8]],'RS ownership');
check(asprima_scope(['kind'=>'desa'])===['1=0','',[]],'Missing village denied');
check(asprima_scope(['kind'=>'kua','id_kua'=>4])===['id_kua=?','i',[4]],'KUA scope');
check(asprima_scope(['kind'=>'ahlak','id_puskes'=>5])===['id_puskes=?','i',[5]],'AHLAK scope');
foreach (['Apa perkembangan permohonan saya?'=>'status','Bagaimana mengatasi unggah berkas gagal?'=>'upload','Apa yang perlu saya perbaiki?'=>'rejected','Dokumen belum masuk email'=>'email','menunggu Dukcapil asal'=>'waiting'] as $q=>$topic) check(asprima_topic($q)===$topic,'Topic '.$q);
check(!empty(asprima_answer($user,'status')['needs_request']),'Explicit selection required');
$r=['id'=>12,'key'=>'pindah_online:12','service_key'=>'pindah_online','label'=>'Pindah Online','title'=>'Nama Contoh','status'=>'diproses','alasan'=>'Menunggu proses dari Dukcapil asal'];
$history=[['id'=>1,'status'=>'diproses','alasan'=>$r['alasan'],'created_at_formatted'=>'08-09-2026 10:30 WITA']];
$a=asprima_answer($user,'waiting',$r,$history);
check(str_contains($a['answer'],$r['alasan']),'Exact note');
check(str_contains($a['source'],'10:30 WITA'),'Real update time');
check(str_contains($a['links'][1]['url'],'asprima_comment=1'),'Draft handoff');
check($a['links'][1]['request_key']===$r['key'],'Draft bound to request');
check(!str_contains($a['answer'],'30 menit'),'No completion promise');
$r['alasan']='Catatan baru';
check(str_contains(asprima_answer($user,'status',$r,$history)['source'],'belum tercatat'),'No invented note timestamp');
check(str_contains(asprima_answer($user,'result',$r)['answer'],'belum selesai'),'No premature result');
foreach (['user','desa','rs','kua','ahlak'] as $kind) foreach(asprima_routes(['kind'=>$kind]) as $route) check(is_file(__DIR__.'/../public/user/'.$route),'Route exists: '.$route);
check(asprima_topic('Syarat upload berkas kelahiran')==='requirements','Requirements take priority');
check(!str_contains($a['answer'],'#12') && $a['request']['title']==='Nama Contoh','Identity without request number');
$menu=asprima_answer($user,'requirements');
check(count($menu['suggestions'])===5 && empty($menu['needs_request']),'Requirements before applying');
foreach (['user','desa','rs','kua','ahlak'] as $kind) {
    $account=['kind'=>$kind];
    foreach (asprima_services($account) as $key=>$config) {
        $answer=asprima_answer($account,'requirements',null,[],'Syarat '.$config[1]);
        check(str_contains($answer['answer'],'1. ') && isset($answer['source']),'Requirements '.$kind.' '.$key);
    }
}
check(str_contains(asprima_requirements($user,'Syarat kelahiran')['answer'],'selfie'),'General birth selfie');
check(!str_contains(asprima_requirements(['kind'=>'desa'],'Syarat kelahiran')['answer'],'selfie'),'Village birth requirements differ');
check(str_contains(asprima_requirements(['kind'=>'ahlak'],'Syarat kelahiran')['answer'],'Kartu Keluarga lama'),'AHLAK old KK');
check(str_contains(asprima_requirements(['kind'=>'kua'],'Syarat KK')['answer'],'alamat calon pengantin berbeda'),'KUA conditional document');
check(!isset(asprima_requirements(['kind'=>'rs'],'Syarat KK')['links']),'Unsupported service not invented');
check(str_contains(asprima_requirements($user,'Apa syaratnya?',$r)['answer'],'F-1.03'),'Selected request requirements');
check(!str_contains(file_get_contents(__DIR__.'/../public/user/ai_chat_faq.php'),'feedback'),'Rating endpoint removed');
echo "ASPRIMA: $count checks passed\n";