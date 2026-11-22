<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once __DIR__.'/../app/Api/Router.php';

function ref(string $name): array { return ['$ref'=>'#/components/schemas/'.$name]; }
function obj(array $properties,array $required=[]): array {
    $schema=['type'=>'object','properties'=>(object)$properties,'additionalProperties'=>false];
    if($required)$schema['required']=$required;
    return $schema;
}
function stringSchema(int $max=100): array { return ['type'=>'string','maxLength'=>$max]; }
function envelope(array $data): array { return obj(['data'=>$data,'meta'=>ref('Meta')],['data','meta']); }
function paged(array $item): array { return obj(['items'=>['type'=>'array','items'=>$item],'pagination'=>ref('Pagination')],['items','pagination']); }
function response(array $schema): array { return ['description'=>'Berhasil','content'=>['application/json'=>['schema'=>envelope($schema)]]]; }
function body(array $schema,string $type='application/json'): array { return ['required'=>true,'content'=>[$type=>['schema'=>$schema]]]; }

$str=['type'=>'string'];$id=['type'=>'integer','minimum'=>1];$bool=['type'=>'boolean'];$date=['type'=>'string','format'=>'date-time','nullable'=>true];
$schemas=[
    'Meta'=>obj(['request_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{24}$'],'server_time'=>['type'=>'string','format'=>'date-time']],['request_id','server_time']),
    'Error'=>obj(['error'=>obj(['code'=>$str,'message'=>$str,'fields'=>['type'=>'object','additionalProperties'=>$str]],['code','message','fields']),'meta'=>ref('Meta')],['error','meta']),
    'Pagination'=>obj(['page'=>$id,'per_page'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'total'=>['type'=>'integer','minimum'=>0]],['page','per_page','total']),
    'Capabilities'=>['type'=>'object','additionalProperties'=>$bool],
    'User'=>['type'=>'object','properties'=>['id'=>$id,'nama'=>$str,'nik'=>['type'=>'string','pattern'=>'^[0-9]{16}$'],'email'=>['type'=>'string','format'=>'email'],'no_hp'=>$str,'alamat'=>$str,'role'=>['type'=>'string','enum'=>['user','petugas_desa']],
        'persona'=>['type'=>'string','enum'=>['citizen','village_officer']],'id_kecamatan'=>['type'=>'integer','nullable'=>true],'id_desa'=>['type'=>'integer','nullable'=>true],'is_active'=>['type'=>'integer'],
        'must_change_password'=>$bool,'capabilities'=>ref('Capabilities')],'required'=>['id','persona','must_change_password']],
    'TokenPair'=>obj(['token_type'=>['type'=>'string','enum'=>['Bearer']],'access_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'refresh_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
        'session_id'=>$str,'expires_in'=>['type'=>'integer'],'access_expires_at'=>$date,'session_expires_at'=>$date,'idle_timeout_seconds'=>['type'=>'integer','enum'=>[\Siprima\Api\Auth::IDLE_SECONDS]],'user'=>ref('User')],['token_type','access_token','refresh_token','session_id','expires_in','access_expires_at','session_expires_at','idle_timeout_seconds','user']),
    'ApplicationSummary'=>obj(['application_ref'=>['type'=>'string','pattern'=>'^(kk|kelahiran|kematian|pindah|pindah_online|peristiwa)-[1-9][0-9]*$'],'service_code'=>$str,'service_name'=>$str,'subject'=>$str,
        'status'=>['type'=>'string','enum'=>['diajukan','diproses','selesai','ditolak']],'reason'=>['type'=>'string','nullable'=>true],'created_at'=>$date,'updated_at'=>$date],['application_ref','service_code','subject','status']),
    'Attachment'=>obj(['document_ref'=>$str,'doc_type'=>$str,'name'=>$str,'download_path'=>$str],['document_ref','doc_type','name','download_path']),
    'Document'=>obj(['document_ref'=>$str,'name'=>$str,'file_size'=>['type'=>'integer'],'mime_type'=>$str,'uploaded_at'=>$date,'download_path'=>$str],['document_ref','name','file_size','mime_type','uploaded_at','download_path']),
    'History'=>obj(['id'=>$id,'status'=>$str,'alasan'=>['type'=>'string','nullable'=>true],'actor_role'=>['type'=>'string','nullable'=>true],'event_type'=>['type'=>'string','nullable'=>true],'created_at'=>$date],['id','status','created_at']),
    'Comment'=>obj(['id'=>$id,'user_role'=>$str,'parent_id'=>['type'=>'integer','nullable'=>true],'komentar'=>$str,'created_at'=>$date],['id','user_role','komentar','created_at']),
    // file_size is the STORED size after re-encoding, not what the client sent; the original is
    // reported separately so the app can show how much the photo was reduced.
    'Upload'=>obj(['upload_ref'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],'service_code'=>$str,'doc_type'=>$str,'mime_type'=>$str,
        'file_size'=>['type'=>'integer','maximum'=>\Siprima\Api\Uploads::MAX_STORED_IMAGE_BYTES],
        'original_file_size'=>['type'=>'integer','maximum'=>\Siprima\Api\Uploads::MAX_IMAGE_BYTES],
        'width'=>['type'=>'integer','minimum'=>1],'height'=>['type'=>'integer','minimum'=>1],'expires_at'=>$date],
        ['upload_ref','service_code','doc_type','mime_type','file_size','expires_at']),
    'Service'=>['type'=>'object','properties'=>['code'=>$str,'name'=>$str,'fields'=>['type'=>'array','items'=>$str],'required_fields'=>['type'=>'array','items'=>$str],
        'field_schema'=>['type'=>'object','additionalProperties'=>['type'=>'object']], 'required_documents'=>['type'=>'array','items'=>$str],'optional_documents'=>['type'=>'array','items'=>$str],
        'required_document_groups'=>['type'=>'array','items'=>['type'=>'array','items'=>$str]],'can_create'=>$bool,
        'max_file_bytes'=>['type'=>'integer'],'max_stored_file_bytes'=>['type'=>'integer'],
        'max_image_pixels'=>['type'=>'integer'],'max_image_dimension'=>['type'=>'integer'],
        'mime_types'=>['type'=>'array','items'=>$str]],'required'=>['code','name','field_schema','required_documents','required_document_groups','can_create']],
    'Availability'=>['type'=>'object','properties'=>['is_open'=>$bool,'can_create'=>$bool,'closed_reason'=>['type'=>'string','nullable'=>true],'timezone'=>['type'=>'string','enum'=>['Asia/Makassar']],'server_time'=>$date,'label'=>$str,'event'=>['type'=>'object','nullable'=>true],'notice'=>['type'=>'object','nullable'=>true]],'required'=>['is_open','can_create','timezone','server_time']],
    'ReceiptStatus'=>['type'=>'object','properties'=>['available'=>$bool,'eligible'=>$bool,'eligible_at'=>$date,'status'=>$str,'seconds_remaining'=>['type'=>'integer'],'catatan'=>$str,'catatan_operator'=>$str,'tindak_lanjut_at'=>$date]],
    'Notification'=>obj(['id'=>$id,'notification_ref'=>$str,'judul'=>$str,'pesan'=>$str,'tipe'=>$str,'created_at'=>$date,'read'=>$bool,'application_ref'=>['type'=>'string','nullable'=>true]],['id','notification_ref','judul','pesan','read','created_at']),
];
$schemas['ApplicationDetail']=['allOf'=>[ref('ApplicationSummary'),['type'=>'object','properties'=>['fields'=>['type'=>'object'],'revision'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'attachments'=>['type'=>'array','items'=>ref('Attachment')],'capabilities'=>ref('Capabilities')],'required'=>['fields','revision','attachments','capabilities']]]];
// The summary is extensible when composed with allOf.
unset($schemas['ApplicationSummary']['additionalProperties']);
$createAlternatives=[];
foreach(Siprima\Api\Catalog::all('user') as $s) {
    $properties=[];
    foreach(Siprima\Api\Catalog::fieldSchema($s) as $field=>$f) {
        $p=['type'=>$f['type']];
        foreach(['pattern','format','enum','minimum'] as $k) if(isset($f[$k]))$p[$k]=$f[$k];
        if(isset($f['max_length']))$p['maxLength']=$f['max_length'];
        if($field==='anggotaKeluarga')$p+=['minItems'=>1,'maxItems'=>50,'items'=>obj(['nama'=>stringSchema(),'nik'=>['type'=>'string','pattern'=>'^([0-9]{16})?$'],'hubungan'=>stringSchema()],['nama'])];
        $properties[$field]=$p;
    }
    $name=ucfirst($s['code']).'Fields';
    $commonRequired=array_values(array_intersect($s['required_fields'],Siprima\Api\Catalog::get($s['code'],'petugas_desa')['required_fields']));
    $schemas[$name]=obj($properties,$commonRequired);
    $schemas[$name]['description']='Isian bersama kedua persona. Persyaratan final per persona berasal dari GET /services/{service_code}. Waktu/urutan kelahiran opsional pada ADD, wajib pada masyarakat.';
    $createAlternatives[]=obj(['service_code'=>['type'=>'string','enum'=>[$s['code']]],'fields'=>ref($name),'uploads'=>['type'=>'array','minItems'=>1,'maxItems'=>30,'uniqueItems'=>true,'items'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$']]],['service_code','fields','uploads']);
}
$schemas['CreateApplication']=['oneOf'=>$createAlternatives,'description'=>'Katalog GET /services/{service_code} mengembalikan syarat final per persona. ADD tidak membuat peristiwa baru; ADD memproses laporan warga dalam wilayah tugas. id_kecamatan/id_desa berarti wilayah asal untuk pindah dan wilayah tujuan untuk pindah_online.'];
$login=obj(['username'=>stringSchema(),'password'=>['type'=>'string','format'=>'password','maxLength'=>72],'device_name'=>stringSchema()],['username','password','device_name']);
$change=['new_password'=>['type'=>'string','format'=>'password','maxLength'=>72],'confirm_password'=>['type'=>'string','format'=>'password','maxLength'=>72]];
$register=['nama'=>stringSchema(),'nik'=>['type'=>'string','pattern'=>'^[0-9]{16}$'],'email'=>['type'=>'string','format'=>'email','maxLength'=>100],'no_hp'=>stringSchema(20),'alamat'=>stringSchema(2000)];
$regBody=obj($register+['password'=>['type'=>'string','format'=>'password','maxLength'=>72],'confirm_password'=>['type'=>'string','format'=>'password','maxLength'=>72]],array_merge(array_keys($register),['password','confirm_password']));
$villageBody=obj($register+['id_kecamatan'=>$id,'id_desa'=>$id,'jabatan'=>stringSchema(),'keterangan_jabatan'=>stringSchema(),'agree_terms'=>['type'=>'string','enum'=>['on','true']],'surat_tugas'=>['type'=>'string','format'=>'binary']],array_merge(array_keys($register),['id_kecamatan','id_desa','jabatan','agree_terms','surat_tugas']));
$villageBody['description']='Multipart, surat_tugas PDF valid maksimal 1 MB. Jabatan lainnya membutuhkan keterangan_jabatan. Akun belum dibuat sampai verifikasi admin sesuai website.';
$bodies=[
    'POST /auth/login'=>body($login), 'POST /auth/refresh'=>body(obj(['refresh_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']],['refresh_token'])),
    'POST /auth/logout'=>body(obj([])), 'POST /auth/register'=>body($regBody),'POST /auth/register-village-officer'=>body($villageBody,'multipart/form-data'),
    'POST /auth/password/forgot'=>body(obj(['email'=>['type'=>'string','format'=>'email','maxLength'=>100]],['email'])),
    'POST /auth/password/reset'=>body(obj(['token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']]+$change,['token','new_password','confirm_password'])),
    'POST /auth/password/change'=>body(obj(['current_password'=>['type'=>'string','format'=>'password','maxLength'=>72]]+$change,['current_password','new_password','confirm_password'])),
    'PATCH /me'=>body(obj(array_intersect_key($register,array_flip(['nama','email','no_hp','alamat'])))),
    'POST /applications'=>body(ref('CreateApplication')),
    'PATCH /applications/{application_ref}'=>body(obj(['fields'=>['type'=>'object','description'=>'Subset isian yang boleh diubah dari katalog; kunci lain ditolak.'],'uploads'=>['type'=>'array','maxItems'=>30,'uniqueItems'=>true,'items'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$']]])),
    'POST /applications/{application_ref}/resubmit'=>body(obj([])),
    'PATCH /applications/{application_ref}/status'=>body(obj(['status'=>['type'=>'string','enum'=>['diajukan','diproses','selesai','ditolak']],'reason'=>stringSchema(255)],['status'])),
    'POST /uploads'=>body(obj(['service_code'=>$str,'doc_type'=>$str,'file'=>['type'=>'string','format'=>'binary']],['service_code','doc_type','file']),'multipart/form-data'),
    'POST /applications/{application_ref}/comments'=>body(obj(['message'=>['type'=>'string','minLength'=>1,'maxLength'=>1000],'parent_id'=>$id],['message'])),
    'POST /applications/{application_ref}/receipt'=>body(obj(['confirmation'=>['type'=>'string','enum'=>['sudah_masuk','belum_masuk']],'note'=>stringSchema(1000)],['confirmation'])),
    'POST /applications/{application_ref}/feedback'=>body(obj(['jenis_feedback'=>['type'=>'string','enum'=>['tanggapan','aduan']],'rating'=>['type'=>'integer','minimum'=>1,'maximum'=>5],'service_rating'=>['type'=>'integer','minimum'=>1,'maximum'=>5],'pesan'=>stringSchema(1000)],['jenis_feedback'])),
    'PATCH /notifications/{notification_ref}'=>body(obj(['read'=>['type'=>'boolean','enum'=>[true]]],['read'])),
];
$schemas['CreatedApplication']=obj(['application_ref'=>$str,'status'=>['type'=>'string','enum'=>['diajukan']]],['application_ref','status']);
$out=[
    'POST /auth/login'=>ref('TokenPair'),'POST /auth/refresh'=>ref('TokenPair'),'POST /auth/logout'=>obj(['logged_out'=>$bool],['logged_out']),
    'POST /auth/register'=>obj(['user_id'=>$id,'message'=>$str],['user_id','message']),
    'POST /auth/register-village-officer'=>obj(['registration_id'=>$id,'status'=>['type'=>'string','enum'=>['pending']],'verification_code'=>$str,'message'=>$str],['registration_id','status','verification_code','message']),
    'POST /auth/password/forgot'=>obj(['message'=>$str],['message']),
    'POST /auth/password/reset'=>obj(['password_changed'=>$bool,'login_required'=>$bool],['password_changed','login_required']),
    'POST /auth/password/change'=>obj(['password_changed'=>$bool,'login_required'=>$bool],['password_changed','login_required']),
    'GET /me'=>ref('User'),'PATCH /me'=>ref('User'),'GET /services'=>obj(['items'=>['type'=>'array','items'=>ref('Service')]],['items']),
    'GET /services/{service_code}'=>ref('Service'),'GET /service-availability'=>ref('Availability'),
    'GET /references/{type}'=>paged(obj(['id'=>$id,'name'=>$str,'kecamatan_id'=>$id],['id','name'])),
    'GET /applications'=>paged(ref('ApplicationSummary')),'POST /applications'=>ref('CreatedApplication'),
    'GET /applications/{application_ref}'=>ref('ApplicationDetail'),'PATCH /applications/{application_ref}'=>ref('ApplicationDetail'),
    'POST /applications/{application_ref}/resubmit'=>ref('CreatedApplication'),'PATCH /applications/{application_ref}/status'=>ref('ApplicationDetail'),
    'POST /uploads'=>ref('Upload'),'GET /applications/{application_ref}/history'=>paged(ref('History')),
    'GET /applications/{application_ref}/comments'=>paged(ref('Comment')),'POST /applications/{application_ref}/comments'=>obj(['comment_id'=>$id],['comment_id']),
    'GET /applications/{application_ref}/documents'=>obj(['items'=>['type'=>'array','items'=>ref('Document')],'receipt'=>ref('ReceiptStatus'),'operator_results_enabled'=>$bool],['items','receipt','operator_results_enabled']),
    'POST /applications/{application_ref}/receipt'=>obj(['status'=>$str,'changed'=>$bool],['status','changed']),
    'POST /applications/{application_ref}/feedback'=>obj(['saved'=>$bool,'status'=>$str],['saved','status']),
    'GET /notifications'=>paged(ref('Notification')),'PATCH /notifications/{notification_ref}'=>obj(['notification_ref'=>$str,'read'=>$bool],['notification_ref','read']),
];
$public=['/auth/login','/auth/refresh','/auth/register','/auth/register-village-officer','/auth/password/forgot','/auth/password/reset','/service-availability','/references/{type}'];
$paths=[];
foreach(Siprima\Api\Router::ROUTES as $route=>$methods)foreach($methods as $method) {
    $key=$method.' '.$route;$status=$method==='POST' && in_array($route,['/auth/register','/auth/register-village-officer','/applications','/uploads','/applications/{application_ref}/comments'],true)?'201':($route==='/auth/password/forgot'?'202':'200');
    $op=['operationId'=>strtolower($method).'_'.trim(preg_replace('/[^a-z0-9]+/i','_',$route),'_'),'summary'=>$key,'security'=>in_array($route,$public,true)?[]:[['bearerAuth'=>[]]],'responses'=>[]];
    preg_match_all('/\{([a-z_]+)\}/',$route,$vars);
    foreach($vars[1] as $v)$op['parameters'][]=['name'=>$v,'in'=>'path','required'=>true,'schema'=>$str];
    if ($method==='GET' && in_array($route,['/references/{type}','/applications','/applications/{application_ref}/history','/applications/{application_ref}/comments','/notifications'],true)) {
        foreach(['page'=>['type'=>'integer','minimum'=>1,'maximum'=>10000,'default'=>1],'per_page'=>['type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>20]] as $name=>$schema)$op['parameters'][]=['name'=>$name,'in'=>'query','schema'=>$schema];
        if ($route==='/applications')foreach(['q','status','service_code'] as $name)$op['parameters'][]=['name'=>$name,'in'=>'query','schema'=>$str];
        if ($route==='/references/{type}')$op['parameters'][]=['name'=>'kecamatan_id','in'=>'query','description'=>'Wajib ketika type=desa; type hanya kecamatan atau desa.','schema'=>$id];
    }
    if ($method==='POST' && in_array($route,['/applications','/applications/{application_ref}/resubmit'],true))$op['parameters'][]=['name'=>'Idempotency-Key','in'=>'header','required'=>true,'schema'=>['type'=>'string','pattern'=>'^[A-Za-z0-9._:-]{16,128}$'],'description'=>'Simpan per operasi. Retry memakai kunci dan payload yang sama. Kunci berbeda payload menghasilkan 409.'];
    if (($method==='PATCH' && in_array($route,['/applications/{application_ref}','/applications/{application_ref}/status'],true)) || $route==='/applications/{application_ref}/resubmit')$op['parameters'][]=['name'=>'If-Match','in'=>'header','required'=>true,'schema'=>$str,'description'=>'revision SHA-256 dari GET detail; tanda kutip ETag diperbolehkan.'];
    if(isset($bodies[$key]))$op['requestBody']=$bodies[$key];
    if($route==='/documents/{document_ref}/download')$op['responses']['200']=['description'=>'Berkas privat, bukan envelope JSON. Referensi berasal dari detail atau daftar dokumen.','content'=>['application/pdf'=>['schema'=>['type'=>'string','format'=>'binary']],'image/png'=>['schema'=>['type'=>'string','format'=>'binary']],'image/jpeg'=>['schema'=>['type'=>'string','format'=>'binary']],'application/octet-stream'=>['schema'=>['type'=>'string','format'=>'binary']]]];
    else $op['responses'][$status]=response($out[$key]);
    foreach(['401'=>'Tidak terautentikasi/token berakhir','403'=>'Aksi dilarang atau layanan ditutup','404'=>'Objek/rute tidak tersedia dalam lingkup akun','405'=>'Metode tidak didukung','409'=>'Konflik revisi/idempotensi','413'=>'Body terlalu besar','415'=>'Content-Type tidak didukung','422'=>'Validasi gagal','429'=>'Pembatasan request','503'=>'Layanan/migrasi/konfigurasi tidak tersedia'] as $code=>$description)$op['responses'][$code]=['description'=>$description,'content'=>['application/json'=>['schema'=>ref('Error')]]];
    if($route==='/applications/{application_ref}' && $method==='PATCH')$op['description']='Sesuai edit website: simpan perbaikan sekaligus mengembalikan status ke diajukan. Tidak perlu resubmit sesudah PATCH berhasil. uploads mengganti seluruh berkas lama dengan doc_type sama; jenis lainnya dipertahankan.';
    if($route==='/applications/{application_ref}/status')$op['description']='Khusus ADD pada laporan peristiwa wilayah tugas; tidak memberi hak operator untuk layanan lain. reason wajib ketika ditolak. Status diajukan tunduk jam layanan.';
    if($route==='/applications/{application_ref}/feedback')$op['description']='Setelah konfirmasi atau unduhan hasil. Tanggapan wajib dua rating 1–5; pesan wajib untuk aduan atau salah satu rating ≤3. Feedback yang telah submitted/skipped tidak ditimpa.';
    if($route==='/applications/{application_ref}/receipt')$op['description']='Hanya selesai setelah masa cek email 30 menit. note wajib untuk belum_masuk. Status tindak lanjut operator dipertahankan.';
    $paths[$route][strtolower($method)]=$op;
}
$spec=['openapi'=>'3.0.3','info'=>['title'=>'SIPRIMA Android API','version'=>'1.0.0','description'=>'API masyarakat dan petugas desa/ADD. Bearer-only; tidak menerima cookie website. Timestamp ISO 8601 WITA. Access '.intdiv(\Siprima\Api\Auth::ACCESS_SECONDS,60).' menit, idle '.intdiv(\Siprima\Api\Auth::IDLE_SECONDS,86400).' hari, sesi absolut '.intdiv(\Siprima\Api\Auth::SESSION_SECONDS,86400).' hari; refresh dirotasi. Base URL harus HTTPS kecuali mode lokal eksplisit. Endpoint whistleblowing-publications.php yang sudah ada tetap terpisah dan kompatibel.'],
    'servers'=>[['url'=>'https://siprima.example.invalid/api/v1','description'=>'Ganti dengan URL HTTPS staging/produksi'],['url'=>'http://10.0.2.2:8080/api/v1','description'=>'Contoh emulator debug; sesuaikan port dan mount path']],
    'paths'=>$paths,'components'=>['securitySchemes'=>['bearerAuth'=>['type'=>'http','scheme'=>'bearer','bearerFormat'=>'Opaque random token']],'schemas'=>$schemas]];
$json=json_encode($spec,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
$target=__DIR__.'/../docs/mobile-api.openapi.json';
if (in_array('--check',$argv,true)) { if (!is_file($target) || file_get_contents($target)!==$json) { fwrite(STDERR,"OpenAPI out of date; run generator.\n");exit(1); } echo "PASS OpenAPI matches route/catalog definitions\n"; }
else { file_put_contents($target,$json);echo "Generated docs/mobile-api.openapi.json\n"; }
