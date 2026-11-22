<?php
require_once '../error_handler.php';
include '../../app/config/koneksi.php';
require_once __DIR__.'/../functions/email_delivery.php';
if(session_status()===PHP_SESSION_NONE)session_start();
$userId=(int)($_SESSION['id_user']??0);$id=(int)($_GET['id']??0);
if($userId<=0||$id<=0){http_response_code(403);exit('Akses ditolak.');}
$stmt=$koneksi->prepare("SELECT f.*,k.id_user_pemohon,k.id_kecamatan,k.source_table,k.permohonan_id,k.status_konfirmasi FROM tb_konfirmasi_dokumen_email_file f INNER JOIN tb_konfirmasi_dokumen_email k ON k.id=f.konfirmasi_id WHERE f.id=? LIMIT 1");
$stmt->bind_param('i',$id);$stmt->execute();$file=$stmt->get_result()->fetch_assoc();$stmt->close();
$context=$file?email_delivery_context($koneksi,(string)$file['source_table'],(int)$file['permohonan_id']):null;
$allowed=$context && email_delivery_requester_can_access($koneksi,$context,(string)$file['source_table'],$userId);
if(!$allowed && $file && ($_SESSION['role']??'')==='operator'){
  $scope=match($file['source_table']){
    'tb_kartukeluarga_kua'=>'kua',
    'tb_akta_kelahiran_ahlak'=>'ahlak',
    'tb_akta_kelahiran_rs','tb_akta_kematian_rs'=>'rs',
    default=>'general'
  };
  $allowed=email_delivery_operator_can_access_record($koneksi,$userId,$file,$scope);
}
if(!$allowed||!in_array((string)$file['status_konfirmasi'],['diunggah_operator','sebagian_diunggah_perlu_pengajuan_baru'],true)){http_response_code(403);exit('Akses ditolak.');}
$path=email_delivery_resolve_file($file);if($path===false){http_response_code(404);exit('File tidak ditemukan.');}
$name=preg_replace('/[^\w\s.\-()]/u','_',basename((string)$file['original_name']))?:'dokumen.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.str_replace('"','',$name).'"');
header('Content-Length: '.filesize($path));
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Download-Options: noopen');
header('Content-Security-Policy: sandbox');
header('Referrer-Policy: no-referrer');
readfile($path);exit;
