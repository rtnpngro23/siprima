<?php
require_once '../error_handler.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/email_delivery.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
set_exception_handler(static function (Throwable $e): never {
    error_log('confirm_email_delivery: '.$e->getMessage());
    $message = 'Konfirmasi gagal diproses.';
    if ($e instanceof mysqli_sql_exception) {
        $message = match ($e->getCode()) {
            1146 => 'Tabel konfirmasi dokumen belum tersedia. Jalankan migrasi konfirmasi dokumen.',
            1054 => 'Struktur tabel konfirmasi dokumen belum lengkap. Jalankan seluruh migrasi konfirmasi dokumen.',
            1265, 1366 => 'Struktur status konfirmasi belum sesuai versi aplikasi terbaru.',
            default => 'Database gagal menyimpan konfirmasi dokumen.'
        };
    }
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$message,'csrf_token'=>csrf_token()], JSON_UNESCAPED_UNICODE);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['id_user']) || !csrf_validate()) {
    http_response_code(401); echo json_encode(['success'=>false,'message'=>'Sesi tidak valid.']); exit;
}
$role=(string)($_SESSION['role']??'');
if(!in_array($role,['user','petugas_desa'],true)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Akses ditolak.']);exit;}

$table=trim((string)($_POST['source_table']??'')); $id=(int)($_POST['permohonan_id']??0); $choice=(string)($_POST['confirmation']??'');
if(!in_array($choice,['sudah_masuk','belum_masuk'],true)){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Pilihan tidak valid.']);exit;}
$catatan=trim((string)($_POST['catatan']??''));
if($choice==='belum_masuk' && $catatan===''){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Catatan dokumen yang belum diterima wajib diisi.']);exit;}
if(mb_strlen($catatan)>1000){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Catatan maksimal 1000 karakter.']);exit;}
$userId=(int)$_SESSION['id_user']; $context=email_delivery_context($koneksi,$table,$id);
if(!$context){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Data permohonan tidak ditemukan.']);exit;}
if(!email_delivery_requester_can_access($koneksi,$context,$table,$userId)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Akun tidak terhubung dengan unit permohonan ini.']);exit;}
if(strtolower(trim($context['status']))!=='selesai'){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Status permohonan belum selesai.']);exit;}
$existingConfirmation=email_delivery_get($koneksi,$table,$id);
$selesaiAt=email_delivery_selesai_at($koneksi,$table,$id,$context['updated_at'],$context['created_at']);
$eligibleTs=$selesaiAt ? email_delivery_eligible_timestamp($selesaiAt,$table) : 0;
if($existingConfirmation && (string)$existingConfirmation['status_konfirmasi']==='dibatalkan' && !empty($existingConfirmation['eligible_at'])){
  $eligibleTs=(new DateTimeImmutable((string)$existingConfirmation['eligible_at'],email_delivery_timezone()))->getTimestamp();
}
if(!$selesaiAt || time() < $eligibleTs){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Konfirmasi belum tersedia. Silakan tunggu sampai masa cek email selesai.']);exit;}
$eligibleAt=email_delivery_format_timestamp($eligibleTs);
if($existingConfirmation && in_array((string)$existingConfirmation['status_konfirmasi'],['diunggah_operator','sebagian_diunggah_perlu_pengajuan_baru','perlu_pengajuan_baru'],true)){
  echo json_encode(['success'=>true,'message'=>'Status tindak lanjut operator tidak diubah.','status'=>$existingConfirmation['status_konfirmasi'],'csrf_token'=>csrf_token()]);exit;
}
  $stmt=$koneksi->prepare("INSERT INTO tb_konfirmasi_dokumen_email (source_table,permohonan_id,id_user_pemohon,id_kecamatan,id_desa,jenis_layanan,nama_pemohon,nama_subjek,selesai_at,eligible_at,status_konfirmasi,catatan_pemohon,confirmed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE id_user_pemohon=VALUES(id_user_pemohon),id_kecamatan=VALUES(id_kecamatan),id_desa=VALUES(id_desa),jenis_layanan=VALUES(jenis_layanan),nama_pemohon=VALUES(nama_pemohon),nama_subjek=VALUES(nama_subjek),selesai_at=VALUES(selesai_at),eligible_at=VALUES(eligible_at),catatan_pemohon=IF(status_konfirmasi IN ('diunggah_operator','perlu_pengajuan_baru'),catatan_pemohon,VALUES(catatan_pemohon)),status_konfirmasi=IF(status_konfirmasi IN ('diunggah_operator','perlu_pengajuan_baru'),status_konfirmasi,VALUES(status_konfirmasi)),confirmed_at=IF(status_konfirmasi IN ('diunggah_operator','perlu_pengajuan_baru'),confirmed_at,NOW()),updated_at=NOW()");
  if (!$stmt) {
    throw new mysqli_sql_exception($koneksi->error, $koneksi->errno);
  }
  $stmt->bind_param('siiiisssssss',$table,$id,$userId,$context['id_kecamatan'],$context['id_desa'],$context['layanan'],$context['nama_pemohon'],$context['nama_subjek'],$selesaiAt,$eligibleAt,$choice,$catatan);
$ok=$stmt->execute(); $stmt->close();
if(!$ok){http_response_code(500);echo json_encode(['success'=>false,'message'=>'Konfirmasi gagal disimpan.']);exit;}

if($choice==='belum_masuk'){
  $assignment=['tb_kartukeluarga_kua'=>['kua_id','id_kua'],'tb_akta_kelahiran_rs'=>['rs_id','id_rs'],
    'tb_akta_kematian_rs'=>['rs_id','id_rs'],'tb_akta_kelahiran_ahlak'=>['puskes_id','id_puskes']][$table]??['kecamatan_id','id_kecamatan'];
  $assignmentId=(int)($context[$assignment[1]]??0);
  $stmtOp=$assignmentId>0?$koneksi->prepare("SELECT DISTINCT u.id FROM tb_users u INNER JOIN tb_operator_kecamatan ok ON ok.operator_id=u.id WHERE ok.`{$assignment[0]}`=? AND u.role='operator'"):null;
  if($stmtOp){$stmtOp->bind_param('i',$assignmentId);$stmtOp->execute();$res=$stmtOp->get_result();while($op=$res->fetch_assoc()){
    buatNotifikasi($koneksi,(int)$op['id'],'Dokumen Belum Masuk ke Email','Jenis: '.$context['layanan'].' | Pemohon: '.$context['nama_pemohon'].' | Subjek: '.$context['nama_subjek'].'. Catatan: '.$catatan,'warning',null,$table,$id);
  }$stmtOp->close();}
}
echo json_encode(['success'=>true,'message'=>$choice==='sudah_masuk'?'Dokumen dikonfirmasi sudah diterima.':'Laporan diteruskan kepada operator.','status'=>$choice,'csrf_token'=>csrf_token()]);
