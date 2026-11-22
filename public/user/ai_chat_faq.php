<?php
require_once __DIR__ . '/../error_handler.php';
require_once __DIR__ . '/asprima_helper.php';
require_once __DIR__ . '/asprima_claude.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
function asprima_respond(array $data, int $code=200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['user','petugas_desa'],true) || empty($_SESSION['id_user'])) asprima_respond(['success'=>false,'answer'=>'Silakan login menggunakan akun pemohon.'],403);
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') asprima_respond(['success'=>false,'answer'=>'Buka bantuan dari tombol ASPRIMA, ya.'],405);
if (!csrf_validate()) asprima_respond(['success'=>false,'answer'=>'Silakan buka ulang halaman ini, lalu coba lagi.'],403);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>4096) asprima_respond(['success'=>false,'answer'=>'Pertanyaan terlalu panjang.'],413);
$payload=json_decode(file_get_contents('php://input'),true);
if (!is_array($payload)) asprima_respond(['success'=>false,'answer'=>'Pertanyaan belum terbaca. Coba kirim lagi, ya.'],400);
$now=time(); $rate=$_SESSION['ai_chat_rate'] ?? [];
if (!is_array($rate) || $now-(int)($rate['window_start'] ?? 0)>=60) $rate=['window_start'=>$now,'count'=>0];
$rate['count']=(int)($rate['count'] ?? 0)+1; $_SESSION['ai_chat_rate']=$rate;
if ($rate['count']>30) asprima_respond(['success'=>false,'answer'=>'Tunggu sebentar sebelum mencoba kembali.'],429);
$action=$payload['action'] ?? 'answer';
if (!is_string($action) || !in_array($action,['answer','requests'],true)) asprima_respond(['success'=>false,'answer'=>'Pilihan ini belum tersedia. Coba buka ulang bantuan.'],400);
try {
    require __DIR__ . '/../../app/config/koneksi.php';
    $account=asprima_account($koneksi,$_SESSION);
    if ($action==='requests') {
        $page=filter_var($payload['page'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);
        if ($page===false) asprima_respond(['success'=>false,'answer'=>'Daftar ini belum bisa dibuka. Pilih permohonan lagi, ya.'],400);
        asprima_respond(['success'=>true]+asprima_requests($koneksi,$account,$page));
    }
    $message=$payload['message'] ?? '';
    if (!is_string($message) || trim($message)==='' || strlen($message)>2000 || (function_exists('mb_strlen')?mb_strlen($message,'UTF-8'):strlen($message))>500) asprima_respond(['success'=>false,'answer'=>'Tulis pertanyaan maksimal 500 karakter.'],400);
    $topic=asprima_topic(trim($message)); $key=$payload['request_key'] ?? '';
    if (!is_string($key) || strlen($key)>50) asprima_respond(['success'=>false,'answer'=>'Permohonan belum terbaca. Silakan pilih lagi.'],400);
    $request=$key!=='' ? asprima_request($koneksi,$account,$key) : null;
    $history=$request ? serviceTrackingHistory($koneksi,$request['table'],(int)$request['id']) : [];
    $config=require __DIR__ . '/../../app/config/asprima.php';
    // Ownership validation above must finish before any external request.
    // Release the session lock during network I/O; the rate counter is saved first.
    session_write_close();
    @set_time_limit(55);
    $intent=asprima_ai_intent(trim($message), $config);
    $answerMessage=$message;
    if ($intent && $intent['topic'] !== 'unknown') {
        $topic=$intent['topic'];
        if ($topic==='requirements' && $intent['service']!=='') {
            $labels=['kk'=>'KK','kelahiran'=>'kelahiran','kematian'=>'kematian','pindah'=>'pindah','pindah_online'=>'pindah masuk'];
            $answerMessage='Syarat '.$labels[$intent['service']];
        }
    }
    $response=asprima_answer($account,$topic,$request,$history,$answerMessage);
    // Never send the selected record, names, notes, or history to the model.
    $guide=asprima_answer($account,$topic,null,[],$answerMessage);
    if ($topic==='requirements') $guide=['answer'=>$response['answer']];
    $personal=$request && !in_array($topic,['apply','upload','meaning','closed','profile','greeting','unknown','requirements'],true);
    if ($personal) $guide=['answer'=>'Jelaskan singkat bahwa informasi resmi permohonan ditampilkan di bawah. Jangan menebak status atau catatan petugas.'];
    $generated=$intent ? asprima_ai_answer(trim($message),$guide,$config) : null;
    $response=asprima_ai_compose($response,$generated,(bool)$personal);
    asprima_respond(['success'=>true]+$response);
} catch (DomainException $e) {
    asprima_respond(['success'=>false,'answer'=>$e->getMessage()],403);
} catch (Throwable $e) {
    error_log('ASPRIMA: bantuan tidak tersedia ('.get_class($e).').');
    asprima_respond(['success'=>false,'answer'=>'Maaf, saya belum bisa melihat permohonan Anda. Coba lagi sebentar, atau buka menu Status Permohonan.'],503);
}