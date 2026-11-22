<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../public/user/asprima_claude.php';
require_once __DIR__.'/../public/user/asprima_helper.php';
$count=0;
function verify_ai($ok, $label) { global $count; if (!$ok) throw new RuntimeException($label); $count++; }
$config=['enabled'=>true,'key'=>'test-placeholder','model'=>'claude-haiku-4-5'];
$success=['stop_reason'=>'end_turn','content'=>[['type'=>'text','text'=>'{"topic":"profile","service":""}']]];
$transport=function($payload,$key) use($success) {
    verify_ai($payload['output_config']['format']['type']==='json_schema','Structured JSON schema');
    verify_ai($payload['output_config']['format']['schema']['additionalProperties']===false,'Strict schema');
    verify_ai(!str_contains($payload['messages'][0]['content'],'7501011234567890'),'ID redacted');
    verify_ai(!str_contains($payload['messages'][0]['content'],'test@example.com'),'Email redacted');
    verify_ai(!isset($payload['tools']),'No model tools');
    return $success;
};
$intent=asprima_ai_intent('ubah email test@example.com NIK 7501011234567890',$config,$transport);
verify_ai($intent===['topic'=>'profile','service'=>''],'Interpretation');
foreach ([['enabled'=>false],['key'=>'']] as $override) {
    verify_ai(asprima_ai_intent('halo',array_replace($config,$override),function(){ throw new RuntimeException('Must not call'); })===null,'Disabled fallback');
}
foreach ([null,[],
    ['stop_reason'=>'max_tokens','content'=>[['type'=>'text','text'=>'{"topic":"prof']]],
    ['stop_reason'=>'refusal','content'=>[]],
    ['stop_reason'=>'end_turn','content'=>[['type'=>'text','text'=>'{"topic":"delete_database","service":"kk"}']]]] as $invalid) {
    verify_ai(asprima_ai_intent('halo',$config,fn()=>$invalid)===null,'Invalid provider fallback');
}
verify_ai(asprima_ai_intent('halo',$config,function(){throw new RuntimeException('simulated timeout');})===null,'Transport exception fallback');
foreach (['Bagaimana mengubah password?','Bagaimana mengubah email?','Bagaimana mengubah profil?'] as $q) verify_ai(asprima_topic($q)==='profile','Local profile intent');
foreach (['user','desa','rs','kua','ahlak'] as $kind) {
    $answer=asprima_answer(['kind'=>$kind],'profile');
    verify_ai(is_file(__DIR__.'/../public/user/'.$answer['links'][0]['url']),'Profile route '.$kind);
    verify_ai(str_contains($answer['links'][0]['url'],'profil.php'),'Profile destination '.$kind);
}
verify_ai(asprima_topic('Dokumen belum masuk email')==='email','Result email unaffected');
verify_ai(str_contains(asprima_requirements(['kind'=>'user'],'Syarat pindah masuk')['answer'],'F-1.03'),'Normalized service');
echo "ASPRIMA Claude: $count checks passed\n";

$generatedResponse=['stop_reason'=>'end_turn','content'=>[['type'=>'thinking','thinking'=>'mengabaikan blok non-teks'],['type'=>'text','text'=>'Halo, ada layanan SIPRIMA yang ingin Anda tanyakan?']]];
$guide=['answer'=>'Sapa pengguna dan tawarkan bantuan layanan.'];
$answer=asprima_ai_answer('halo',$guide,$config,function($p) use($generatedResponse) {
    verify_ai(!isset($p['tools']) && !isset($p['output_config']),'Generation without tools or forced schema');
    verify_ai(is_string($p['system']) && is_array($p['messages']),'System/messages present');
    verify_ai($p['max_tokens']>=2048,'Token budget');
    return $generatedResponse;
});
verify_ai($answer==='Halo, ada layanan SIPRIMA yang ingin Anda tanyakan?','Generated greeting');
$local=['answer'=>'Status: Diproses. Catatan petugas tetap asli.','topic'=>'status','links'=>[['url'=>'lokal']],'source'=>'Riwayat resmi'];
$composed=asprima_ai_compose($local,$answer,true);
verify_ai(str_ends_with($composed['answer'],$local['answer']),'Personal record preserved exactly');
verify_ai($composed['links']===$local['links'],'Local links preserved');
verify_ai($composed['engine']==='claude','Generated engine');
$fallback=asprima_ai_compose($local,null,true);
verify_ai($fallback['answer']===$local['answer'] && $fallback['engine']==='local' && isset($fallback['notice']),'Visible fallback');
$hello=asprima_ai_compose(['answer'=>'Template','topic'=>'greeting','links'=>[['url'=>'local']]],$answer,false);
verify_ai($hello['answer']===$answer && !isset($hello['links']),'Greeting replaces template and removes irrelevant link');
foreach ([null,
    ['stop_reason'=>'max_tokens','content'=>$generatedResponse['content']],
    ['stop_reason'=>'refusal','content'=>[]]] as $bad) {
    verify_ai(asprima_ai_answer('halo',$guide,$config,fn()=>$bad)===null,'Generation failure fallback');
}
verify_ai(asprima_ai_answer('halo',$guide,array_replace($config,['key'=>'']),function(){throw new RuntimeException('No key');})===null,'No key generation fallback');
echo "ASPRIMA Claude generation: total $count checks passed\n";
