<?php
require_once '../error_handler.php';
include '../../app/middleware/operator_only.php';
include '../../app/config/koneksi.php';
require_once __DIR__.'/../functions/email_delivery.php';

$requestedDeliveryScope=$emailDeliveryScope??'general';
$deliveryScope=in_array($requestedDeliveryScope,['general','kua','ahlak','rs'],true)?$requestedDeliveryScope:'general';
$scopeConfigs=[
  'general'=>['logo'=>'Operator Dukcapil','css'=>'/user/css/dokumen_email_belum_masuk.css?v=20261115-1','dashboard'=>'dashboard_operator.php','process'=>'/user/proses_pengajuan.php','history'=>'riwayat_operator.php'],
  'kua'=>['logo'=>'Operator PASUTRI','css'=>'css/kua_proses_pengajuan.css?v=20260821-2','dashboard'=>'kua_dashboard_operator.php','process'=>'kua_proses_pengajuan.php','history'=>'kua_riwayat_operator.php'],
  'ahlak'=>['logo'=>'Operator AHLAK','css'=>'css/ahlak_dokumen_belum_diterima.css?v=20260822-1','dashboard'=>'ahlak_dashboard_operator.php','process'=>'ahlak_proses_pengajuan.php','history'=>'ahlak_riwayat_operator.php'],
  'rs'=>['logo'=>'Operator ST SEHATI','css'=>'css/rs_proses_pengajuan.css?v=20260821-2','dashboard'=>'rs_dashboard_operator.php','process'=>'rs_proses_pengajuan.php','history'=>'rs_riwayat_operator.php']
];
$scopeConfig=$scopeConfigs[$deliveryScope]??$scopeConfigs['general'];
$inlinePageCss='';
if($deliveryScope==='general'){
  $inlineCssFile=__DIR__.'/css/dokumen_email_belum_masuk.css';
  if(is_readable($inlineCssFile))$inlinePageCss=(string)file_get_contents($inlineCssFile);
}
$operatorId=(int)($_SESSION['id_user']??0); $success=''; $error='';
$canAccess=static fn(array $record):bool=>email_delivery_operator_can_access_record($koneksi,$operatorId,$record,$deliveryScope);
$action=(string)($_POST['action']??'');
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='recheck_email'){
  $confirmationId=(int)($_POST['confirmation_id']??0);
  $stmt=$koneksi->prepare("SELECT * FROM tb_konfirmasi_dokumen_email WHERE id=? AND status_konfirmasi='belum_masuk' LIMIT 1");$stmt->bind_param('i',$confirmationId);$stmt->execute();$confirmation=$stmt->get_result()->fetch_assoc();$stmt->close();
  if(!$confirmation || !$canAccess($confirmation)){$error='Data tidak ditemukan atau di luar unit tugas.';}
  else{
    $eligibleAt=email_delivery_format_timestamp(time()+EMAIL_DELIVERY_WAIT_SECONDS);
    $update=$koneksi->prepare("UPDATE tb_konfirmasi_dokumen_email SET status_konfirmasi='dibatalkan',eligible_at=?,operator_id=?,operator_confirmed_at=NOW(),updated_at=NOW() WHERE id=? AND status_konfirmasi='belum_masuk'");
    $update->bind_param('sii',$eligibleAt,$operatorId,$confirmationId);
    if(!$update->execute() || $update->affected_rows!==1){$error='Masa cek email gagal dimulai. Muat ulang halaman dan coba kembali.';}
    else{
      $context=notifikasiPermohonanContext($koneksi,(string)$confirmation['source_table'],(int)$confirmation['permohonan_id']);
      $target=notifikasiTargetDetailPemohon($koneksi,(int)$confirmation['id_user_pemohon'],(int)$confirmation['permohonan_id'],$context['jenis']??'',(string)$confirmation['source_table']);
      buatNotifikasi($koneksi,(int)$confirmation['id_user_pemohon'],'Periksa Kembali Email','Dokumen sedang menunggu TTE atau telah dikirim ulang. Silakan periksa kembali email setelah 30 menit.','info',$target,$confirmation['source_table'],(int)$confirmation['permohonan_id']);
      $success='Masa cek email 30 menit berhasil dimulai untuk pemohon.';
    }
    $update->close();
  }
}
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='follow_up_new_application'){
  $confirmationId=(int)($_POST['confirmation_id']??0);$operatorNote=trim((string)($_POST['catatan_operator']??''));
  if($operatorNote==='' || mb_strlen($operatorNote)>1000){$error='Catatan tindak lanjut wajib diisi dan maksimal 1000 karakter.';}
  else{
    $stmt=$koneksi->prepare("SELECT * FROM tb_konfirmasi_dokumen_email WHERE id=? AND status_konfirmasi='belum_masuk' LIMIT 1");$stmt->bind_param('i',$confirmationId);$stmt->execute();$confirmation=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$confirmation || !$canAccess($confirmation)){$error='Data tidak ditemukan atau di luar unit tugas.';}
    else{
      $update=$koneksi->prepare("UPDATE tb_konfirmasi_dokumen_email SET status_konfirmasi='perlu_pengajuan_baru',catatan_operator=?,operator_id=?,tindak_lanjut_operator_id=?,tindak_lanjut_at=NOW(),updated_at=NOW() WHERE id=? AND status_konfirmasi='belum_masuk'");$update->bind_param('siii',$operatorNote,$operatorId,$operatorId,$confirmationId);
      if(!$update->execute() || $update->affected_rows!==1){$error='Tindak lanjut gagal disimpan. Muat ulang halaman dan coba kembali.';}
      else{$context=notifikasiPermohonanContext($koneksi,(string)$confirmation['source_table'],(int)$confirmation['permohonan_id']);$target=notifikasiTargetDetailPemohon($koneksi,(int)$confirmation['id_user_pemohon'],(int)$confirmation['permohonan_id'],$context['jenis']??'',(string)$confirmation['source_table']);$message='Jenis: '.$confirmation['jenis_layanan'].' | Pemohon: '.$confirmation['nama_pemohon'].' | Subjek: '.$confirmation['nama_subjek'].'. Dokumen belum tersedia dan perlu diajukan kembali. Catatan operator: '.$operatorNote;$notified=buatNotifikasi($koneksi,(int)$confirmation['id_user_pemohon'],'Perlu Pengajuan Baru',$message,'warning',$target,$confirmation['source_table'],(int)$confirmation['permohonan_id']);$success='Tindak lanjut berhasil disimpan.'.($notified?' Pemohon telah diberi notifikasi.':' Namun notifikasi gagal dibuat.');}
      $update->close();
    }
  }
}
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='cancel_follow_up'){
  $confirmationId=(int)($_POST['confirmation_id']??0);$stmt=$koneksi->prepare("SELECT * FROM tb_konfirmasi_dokumen_email WHERE id=? AND status_konfirmasi='perlu_pengajuan_baru' LIMIT 1");$stmt->bind_param('i',$confirmationId);$stmt->execute();$confirmation=$stmt->get_result()->fetch_assoc();$stmt->close();
  if(!$confirmation || !$canAccess($confirmation)){$error='Tindak lanjut tidak ditemukan atau di luar unit tugas.';}
  else{$update=$koneksi->prepare("UPDATE tb_konfirmasi_dokumen_email SET status_konfirmasi='belum_masuk',catatan_operator=NULL,tindak_lanjut_operator_id=NULL,tindak_lanjut_at=NULL,updated_at=NOW() WHERE id=?");$update->bind_param('i',$confirmationId);$success=$update->execute()?'Tindak lanjut dibatalkan.':'Tindak lanjut gagal dibatalkan.';$update->close();}
}
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='delete_fallback_file'){
  $fileId=(int)($_POST['file_id']??0);
  $stmt=$koneksi->prepare("SELECT f.*,k.id AS confirmation_id,k.id_kecamatan,k.source_table,k.permohonan_id,k.status_konfirmasi FROM tb_konfirmasi_dokumen_email_file f INNER JOIN tb_konfirmasi_dokumen_email k ON k.id=f.konfirmasi_id WHERE f.id=? LIMIT 1");
  $stmt->bind_param('i',$fileId);$stmt->execute();$deleteFile=$stmt->get_result()->fetch_assoc();$stmt->close();
  if(!$deleteFile || !$canAccess($deleteFile)){$error='Dokumen tidak ditemukan atau di luar unit tugas.';}
  else{
    $filePath=email_delivery_resolve_file($deleteFile);$temporaryPath=$filePath!==false?$filePath.'.deleting_'.bin2hex(random_bytes(4)):false;
    if($filePath!==false && !@rename($filePath,$temporaryPath)){$error='File fisik tidak dapat disiapkan untuk dihapus.';}
    else{
      $koneksi->begin_transaction();
      try{
        $delete=$koneksi->prepare("DELETE FROM tb_konfirmasi_dokumen_email_file WHERE id=? AND konfirmasi_id=?");
        $delete->bind_param('ii',$fileId,$deleteFile['confirmation_id']);if(!$delete->execute()||$delete->affected_rows<1)throw new RuntimeException('Data dokumen gagal dihapus.');$delete->close();
        $count=$koneksi->prepare("SELECT COUNT(*) AS total FROM tb_konfirmasi_dokumen_email_file WHERE konfirmasi_id=?");$count->bind_param('i',$deleteFile['confirmation_id']);$count->execute();$remaining=(int)($count->get_result()->fetch_assoc()['total']??0);$count->close();
        if($remaining===0){$reset=$koneksi->prepare("UPDATE tb_konfirmasi_dokumen_email SET status_konfirmasi='belum_masuk',operator_id=NULL,operator_confirmed_at=NULL WHERE id=?");$reset->bind_param('i',$deleteFile['confirmation_id']);if(!$reset->execute())throw new RuntimeException('Status laporan gagal dikembalikan.');$reset->close();}
        $koneksi->commit();if($temporaryPath!==false)@unlink($temporaryPath);$success=$remaining===0?'Dokumen terakhir dihapus. Laporan dikembalikan ke Belum Ditangani.':'Dokumen berhasil dihapus.';
      }catch(Throwable $e){$koneksi->rollback();if($temporaryPath!==false&&is_file($temporaryPath))@rename($temporaryPath,$filePath);$error=$e->getMessage();}
    }
  }
}
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='upload_fallback'){
  $uploadTransactionStarted=false;$storedFiles=[];
  try{
  $confirmationId=(int)($_POST['confirmation_id']??0);
  $requiresNewApplication=(string)($_POST['requires_new_application']??'')==='1';
  $operatorNote=trim((string)($_POST['catatan_operator']??''));
  $koneksi->begin_transaction();$uploadTransactionStarted=true;
  $stmt=$koneksi->prepare("SELECT * FROM tb_konfirmasi_dokumen_email WHERE id=? AND status_konfirmasi IN ('belum_masuk','diunggah_operator') LIMIT 1 FOR UPDATE");
  $stmt->bind_param('i',$confirmationId);$stmt->execute();$confirmation=$stmt->get_result()->fetch_assoc();$stmt->close();
  if(!$confirmation || !$canAccess($confirmation)){$error='Data tidak ditemukan atau di luar unit tugas.';}
  elseif($requiresNewApplication && ($operatorNote==='' || mb_strlen($operatorNote)>1000)){$error='Catatan tindak lanjut wajib diisi dan maksimal 1000 karakter.';}
  elseif(empty($_FILES['dokumen_fallback']) || !is_array($_FILES['dokumen_fallback']['name']??null)){$error='Pilih minimal satu file PDF.';}
  else{
    $files=[];
    for($fileIndex=0;$fileIndex<3;$fileIndex++){
      $file=['name'=>$_FILES['dokumen_fallback']['name'][$fileIndex]??'','type'=>$_FILES['dokumen_fallback']['type'][$fileIndex]??'','tmp_name'=>$_FILES['dokumen_fallback']['tmp_name'][$fileIndex]??'','error'=>(int)($_FILES['dokumen_fallback']['error'][$fileIndex]??UPLOAD_ERR_NO_FILE),'size'=>(int)($_FILES['dokumen_fallback']['size'][$fileIndex]??0)];
      if($file['error']!==UPLOAD_ERR_NO_FILE)$files[]=$file;
    }
    if(!$files){$error='Pilih minimal satu file PDF.';}
    else{
      foreach($files as $file){$validationMessage=null;if(!upload_security_validate_pdf_only_file($file,$validationMessage)){$error=$validationMessage?:'PDF tidak valid.';break;}}
    }
    if($error===''){
      $storageContext=email_delivery_context($koneksi,(string)$confirmation['source_table'],(int)$confirmation['permohonan_id']);
      if(!$storageContext){$error='Data sumber permohonan tidak ditemukan.';}
      $category=email_delivery_category((string)$confirmation['source_table']);
      $requestTimestamp=$storageContext&&!empty($storageContext['created_at'])
        ? (new DateTimeImmutable($storageContext['created_at'],email_delivery_timezone()))->getTimestamp()
        : time();
      $baseStoredName=$storageContext?email_delivery_storage_basename($storageContext,$requestTimestamp):'';
      $root=email_delivery_upload_root();$folder=$root.DIRECTORY_SEPARATOR.$category;
      if($error!==''){}
      elseif(!is_dir($folder) && !mkdir($folder,0755,true)){$error='Folder penyimpanan gagal dibuat.';}
      else{
        $countExisting=$koneksi->prepare("SELECT COUNT(*) AS total FROM tb_konfirmasi_dokumen_email_file WHERE konfirmasi_id=?");
        $countExisting->bind_param('i',$confirmationId);$countExisting->execute();$existingFileCount=(int)($countExisting->get_result()->fetch_assoc()['total']??0);$countExisting->close();
        $storedFiles=[];$documentNumber=$existingFileCount;
        foreach($files as $file){
          $documentNumber++;
          $original=preg_replace('/[^\w\s.\-()]/u','_',basename((string)$file['name']))?:'dokumen.pdf';
          $stored=$baseStoredName.($documentNumber>1?'_'.$documentNumber:'').'.pdf';
          while(is_file($folder.DIRECTORY_SEPARATOR.$stored)){
            $documentNumber++;
            $stored=$baseStoredName.'_'.$documentNumber.'.pdf';
          }
          $destination=$folder.DIRECTORY_SEPARATOR.$stored;
          if(!move_uploaded_file((string)$file['tmp_name'],$destination)){$error='Salah satu file gagal disimpan.';break;}
          @chmod($destination,0640);
          $storedFiles[]=['original'=>$original,'stored'=>$stored,'destination'=>$destination,'relative'=>'uploads/email_delivery/'.$category.'/'.$stored,'size'=>(int)filesize($destination),'mime'=>'application/pdf'];
        }
        if($error!==''){foreach($storedFiles as $saved)@unlink($saved['destination']);}
        else{
          try{
            foreach($storedFiles as $saved){
              $insert=$koneksi->prepare("INSERT INTO tb_konfirmasi_dokumen_email_file (konfirmasi_id,uploaded_by,original_name,stored_name,file_path,file_size,mime_type) VALUES (?,?,?,?,?,?,?)");
              $insert->bind_param('iisssis',$confirmationId,$operatorId,$saved['original'],$saved['stored'],$saved['relative'],$saved['size'],$saved['mime']);
              if(!$insert->execute())throw new RuntimeException('Data salah satu file gagal disimpan.');$insert->close();
            }
            if($requiresNewApplication){
              $update=$koneksi->prepare("UPDATE tb_konfirmasi_dokumen_email SET status_konfirmasi='sebagian_diunggah_perlu_pengajuan_baru',catatan_operator=?,operator_id=?,tindak_lanjut_operator_id=?,operator_confirmed_at=NOW(),tindak_lanjut_at=NOW(),updated_at=NOW() WHERE id=? AND status_konfirmasi='belum_masuk'");
              $update->bind_param('siii',$operatorNote,$operatorId,$operatorId,$confirmationId);
            }else{
              $update=$koneksi->prepare("UPDATE tb_konfirmasi_dokumen_email SET status_konfirmasi='diunggah_operator',operator_id=?,operator_confirmed_at=NOW() WHERE id=? AND status_konfirmasi IN ('belum_masuk','diunggah_operator')");
              $update->bind_param('ii',$operatorId,$confirmationId);
            }
            if(!$update->execute()||$update->affected_rows<1)throw new RuntimeException('Status laporan gagal diperbarui.');$update->close();
            $koneksi->commit();$uploadTransactionStarted=false;
            $target=notifikasiTargetDetailPemohon($koneksi,(int)$confirmation['id_user_pemohon'],(int)$confirmation['permohonan_id'],notifikasiPermohonanContext($koneksi,$confirmation['source_table'],(int)$confirmation['permohonan_id'])['jenis']??'',(string)$confirmation['source_table']);
            $notificationTitle=$requiresNewApplication?'Dokumen Sebagian Tersedia':'Dokumen Hasil Tersedia';
            $notificationMessage='Jenis: '.$confirmation['jenis_layanan'].' | Pemohon: '.$confirmation['nama_pemohon'].' | Subjek: '.$confirmation['nama_subjek'].'. '.($requiresNewApplication?'Operator telah menyediakan sebagian dokumen. Dokumen lainnya perlu diajukan kembali. Catatan operator: '.$operatorNote:'Operator telah menyediakan dokumen untuk diunduh.');
            buatNotifikasi($koneksi,(int)$confirmation['id_user_pemohon'],$notificationTitle,$notificationMessage,$requiresNewApplication?'warning':'success',$target,$confirmation['source_table'],(int)$confirmation['permohonan_id']);
            $success=count($storedFiles).' dokumen berhasil diupload'.($requiresNewApplication?' sebagai dokumen sebagian.':'').' dan pemohon telah diberi notifikasi.';
          }catch(Throwable $e){
            if($uploadTransactionStarted){
              $koneksi->rollback();$uploadTransactionStarted=false;
              foreach($storedFiles as $saved)@unlink($saved['destination']);
              $error='Upload dokumen gagal disimpan.';
            }else{
              $error='Dokumen berhasil disimpan, tetapi notifikasi pemohon gagal dibuat.';
            }
            error_log('EMAIL_DELIVERY_UPLOAD_SAVE_ERROR confirmation_id='.$confirmationId.' message='.$e->getMessage());
          }
        }
      }
    }
  }
  if($uploadTransactionStarted){$koneksi->rollback();$uploadTransactionStarted=false;}
  }catch(Throwable $e){
    $wasCommitted=!$uploadTransactionStarted && !empty($storedFiles);
    if($uploadTransactionStarted){
      try{$koneksi->rollback();}catch(Throwable $rollbackError){error_log('EMAIL_DELIVERY_UPLOAD_ROLLBACK_ERROR confirmation_id='.(int)($confirmationId??0).' message='.$rollbackError->getMessage());}
      foreach($storedFiles as $saved){if(!empty($saved['destination']))@unlink($saved['destination']);}
      $uploadTransactionStarted=false;
    }
    error_log('EMAIL_DELIVERY_UPLOAD_ERROR confirmation_id='.(int)($confirmationId??0).' message='.$e->getMessage());
    $error=$wasCommitted
      ? 'Dokumen berhasil disimpan, tetapi notifikasi pemohon gagal dibuat.'
      : 'Upload dokumen gagal diproses. Silakan muat ulang halaman dan coba kembali.';
  }
}

$filter=in_array($_GET['status']??'',['belum_masuk','diunggah_operator','sebagian_diunggah_perlu_pengajuan_baru','perlu_pengajuan_baru'],true)?$_GET['status']:'belum_masuk';
$search=trim((string)($_GET['search']??''));
$filterKecamatan=(int)($_GET['kecamatan']??0);
$perPage=10;
$currentPage=max(1,(int)($_GET['page']??1));
if($deliveryScope==='rs'){
  $kecamatanStmt=$koneksi->prepare("SELECT id,nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan");
  $kecamatanStmt->execute();$assignedKecamatan=$kecamatanStmt->get_result()->fetch_all(MYSQLI_ASSOC);$kecamatanStmt->close();
  $unitStmt=$koneksi->prepare("SELECT DISTINCT rs.nama_rs FROM tb_operator_kecamatan ok INNER JOIN tb_rumahsakit rs ON rs.id_rs=ok.rs_id WHERE ok.operator_id=? ORDER BY rs.nama_rs");
  $unitStmt->bind_param('i',$operatorId);$unitStmt->execute();$assignedUnits=$unitStmt->get_result()->fetch_all(MYSQLI_ASSOC);$unitStmt->close();
  $wilayahTugas=$assignedUnits?implode(', ',array_column($assignedUnits,'nama_rs')):'Belum ditentukan';
}else{
  $kecamatanStmt=$koneksi->prepare("SELECT DISTINCT k.id,k.nama_kecamatan FROM tb_operator_kecamatan ok INNER JOIN tb_kecamatan k ON k.id=ok.kecamatan_id WHERE ok.operator_id=? ORDER BY k.nama_kecamatan");
  $kecamatanStmt->bind_param('i',$operatorId);$kecamatanStmt->execute();$assignedKecamatan=$kecamatanStmt->get_result()->fetch_all(MYSQLI_ASSOC);$kecamatanStmt->close();
  $wilayahTugas=$assignedKecamatan ? implode(', ',array_column($assignedKecamatan,'nama_kecamatan')) : 'Belum ditentukan';
}
$fromWhere=" FROM tb_konfirmasi_dokumen_email k LEFT JOIN tb_kecamatan kc ON kc.id=k.id_kecamatan LEFT JOIN tb_desa d ON d.id=k.id_desa WHERE k.status_konfirmasi=?";
if($deliveryScope==='kua'){
  $fromWhere.=" AND k.source_table='tb_kartukeluarga_kua' AND EXISTS (SELECT 1 FROM tb_kartukeluarga_kua p INNER JOIN tb_operator_kecamatan ok ON ok.kua_id=p.id_kua WHERE p.id=k.permohonan_id AND ok.operator_id=?)";
}elseif($deliveryScope==='ahlak'){
  $fromWhere.=" AND k.source_table='tb_akta_kelahiran_ahlak' AND EXISTS (SELECT 1 FROM tb_akta_kelahiran_ahlak p INNER JOIN tb_operator_kecamatan ok ON ok.puskes_id=p.id_puskes WHERE p.id=k.permohonan_id AND ok.operator_id=?)";
}elseif($deliveryScope==='rs'){
  $fromWhere.=" AND ((k.source_table='tb_akta_kelahiran_rs' AND EXISTS (SELECT 1 FROM tb_akta_kelahiran_rs p INNER JOIN tb_operator_kecamatan ok ON ok.rs_id=p.id_rs WHERE p.id=k.permohonan_id AND ok.operator_id=?)) OR (k.source_table='tb_akta_kematian_rs' AND EXISTS (SELECT 1 FROM tb_akta_kematian_rs p INNER JOIN tb_operator_kecamatan ok ON ok.rs_id=p.id_rs WHERE p.id=k.permohonan_id AND ok.operator_id=?)))";
}else{
  $fromWhere.=" AND k.source_table NOT IN ('tb_kartukeluarga_kua','tb_akta_kelahiran_ahlak','tb_akta_kelahiran_rs','tb_akta_kematian_rs') AND EXISTS (SELECT 1 FROM tb_operator_kecamatan ok WHERE ok.operator_id=? AND ok.kecamatan_id=k.id_kecamatan)";
}
$params=$deliveryScope==='rs'?[$filter,$operatorId,$operatorId]:[$filter,$operatorId];
if($filterKecamatan>0){$fromWhere.=" AND k.id_kecamatan=?";$params[]=$filterKecamatan;}
if($search!==''){$fromWhere.=" AND (k.nama_pemohon LIKE ? OR k.nama_subjek LIKE ? OR k.jenis_layanan LIKE ?)";$like='%'.$search.'%';$params[]=$like;$params[]=$like;$params[]=$like;}
$countStmt=$koneksi->prepare("SELECT COUNT(*) AS total".$fromWhere);$countStmt->execute($params);$totalItems=(int)($countStmt->get_result()->fetch_assoc()['total']??0);$countStmt->close();
$totalPages=max(1,(int)ceil($totalItems/$perPage));
$currentPage=min($currentPage,$totalPages);
$offset=($currentPage-1)*$perPage;
$query="SELECT k.*,kc.nama_kecamatan,d.nama_desa".$fromWhere." ORDER BY k.updated_at DESC,k.id DESC LIMIT ".$perPage." OFFSET ".$offset;
$stmt=$koneksi->prepare($query);$stmt->execute($params);$items=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
foreach($items as &$queueItem){
  $queueItem['files']=email_delivery_files($koneksi,(int)$queueItem['id']);
  if(trim((string)($queueItem['nama_subjek']??''))==='' || trim((string)$queueItem['nama_subjek'])==='-'){
    $sourceContext=email_delivery_context($koneksi,(string)$queueItem['source_table'],(int)$queueItem['permohonan_id']);
    if($sourceContext && trim((string)($sourceContext['nama_subjek']??''))!=='')$queueItem['nama_subjek']=$sourceContext['nama_subjek'];
  }
}
unset($queueItem);
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function pagination_url($page,$filter,$filterKecamatan,$search){return '?'.http_build_query(['status'=>$filter,'kecamatan'=>$filterKecamatan,'search'=>$search,'page'=>$page]);}
$resetUrl=basename((string)($_SERVER['SCRIPT_NAME']??'dokumen_email_belum_masuk.php'));
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dokumen Belum Diterima</title><link rel="stylesheet" href="<?=h($scopeConfig['css'])?>"><script src="../assets/js/theme.js?v=20261118-1"></script><?php if($inlinePageCss!==''):?><style id="email-delivery-page-css"><?=$inlinePageCss?></style><?php endif;?><style>.queue{padding:30px}.queue-header{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.filters{display:flex;gap:15px;align-items:end;flex-wrap:wrap;background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:25px}.filters label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:600;color:#555}.filters input,.filters select{padding:9px 12px;border:1px solid #ddd;border-radius:6px;min-width:170px;font-size:14px}.table-wrap{overflow-x:auto;background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05)}.queue table{width:100%;min-width:1250px;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden}.queue th,.queue td{padding:15px;border-bottom:1px solid #eee;text-align:left;font-size:14px;vertical-align:top}.queue th{background:#f8f9fa;color:#2c3e50;font-weight:600;border-bottom:2px solid #eee}.detail-column{min-width:185px}.detail-link{display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;line-height:1.2;padding:9px 14px}.pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:20px;padding:15px 0;border-top:1px solid #eee}.pagination-links{display:flex;gap:6px;flex-wrap:wrap}.page-link{display:inline-flex;min-width:36px;height:36px;align-items:center;justify-content:center;border:1px solid #ddd;border-radius:6px;background:#fff;color:#333;text-decoration:none;padding:0 12px;font-size:14px}.page-link.active{background:#3498db;border-color:#3498db;color:#fff}.page-link.disabled{color:#999;pointer-events:none;background:#f8f9fa}.alert{padding:12px 16px;border-radius:6px;margin-bottom:15px}.ok{background:#d1fae5;color:#065f46}.err{background:#fee2e2;color:#991b1b}.upload{display:flex;flex-direction:column;gap:7px;align-items:stretch;min-width:260px}.upload label{font-size:12px;color:#555;font-weight:600}.upload-actions{display:flex;gap:7px}.upload-actions .btn{flex:1}.btn{border:0;border-radius:6px;padding:8px 12px;background:#3498db;color:#fff;cursor:pointer;text-decoration:none;font-size:13px;font-weight:600}.btn-cancel{background:#dceae7;color:#0f172a;border:1px solid #a9c2bd}.wilayah-compact{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:10px;margin-bottom:18px}.wilayah-compact details summary{cursor:pointer;font-size:13px;font-weight:600;color:#0b6e75;margin-top:4px}.wilayah-compact details p{margin:6px 0 0;font-size:13px;color:#334155;line-height:1.5}.user-info{flex-shrink:0}.user-info>span{white-space:nowrap}.modal-overlay{display:none}.modal-overlay.show{display:flex}@media(max-width:800px){.queue{padding:15px}.queue-header{flex-wrap:wrap}.filters{flex-direction:column;align-items:stretch;padding:15px}.filters label,.filters input,.filters select,.filters .btn{width:100%;min-width:0;box-sizing:border-box}.main{margin-left:0;min-width:0;max-width:100%;overflow-x:hidden}.sidebar.open+.main{margin-left:0}.modal-box{width:95%;max-width:95%}}</style><link rel="stylesheet" href="../assets/css/theme.css?v=20261118-11"></head><body>
<div class="layout"><aside class="sidebar"><div class="logo"><?=h($scopeConfig['logo'])?></div><nav><ul><li><a href="<?=h($scopeConfig['dashboard'])?>">Dashboard</a></li><li><a href="<?=h($scopeConfig['process'])?>">Proses Pengajuan</a></li><?php if($deliveryScope==='general'):?><li><a href="lapor_peristiwa_opr.php">Laporan Peristiwa</a></li><?php endif;?><?php if($scopeConfig['history']!==''):?><li><a href="<?=h($scopeConfig['history'])?>">Riwayat</a></li><?php endif;?><li class="active"><a href="dokumen_email_belum_masuk.php" aria-current="page">Dokumen Belum Diterima</a></li><?php if($deliveryScope==='general'):?><li><a href="formulir_operator.php">Formulir Kependudukan</a></li><?php endif;?><li id="logoutBtn">Logout</li></ul></nav></aside><div class="menu-toggle">☰</div>
<main class="main"><header class="header"><div class="left-header"><div class="title">Dokumen Belum Diterima - Operator</div><div class="subtitle">Laporan pemohon setelah 30 menit</div></div><div class="user-info"><span>Hai, <?=h($_SESSION['nama']??'Operator')?></span><?php include __DIR__.'/notification_widget.php'; ?><button type="button" class="logout" id="logoutBtn2">Logout</button></div></header>
<section class="queue"><div class="wilayah-info wilayah-compact"><div class="wilayah-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0b6e75" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></div><div class="wilayah-text"><h4>Wilayah Tugas Anda</h4><?php $kecList=$deliveryScope==='rs'?array_column($assignedUnits,'nama_rs'):array_column($assignedKecamatan,'nama_kecamatan'); if(count($kecList)>3): ?><p><?=count($kecList)?> wilayah terdaftar</p><details><summary>Lihat daftar wilayah</summary><p><?=h($wilayahTugas)?></p></details><?php else: ?><p><?=h($wilayahTugas)?></p><?php endif; ?></div></div><?php if($success):?><div class="alert ok"><?=h($success)?></div><?php endif;?><?php if($error):?><div class="alert err"><?=h($error)?></div><?php endif;?><div class="queue-header"><h2>Daftar Laporan Dokumen Belum Diterima</h2></div><form method="get" class="filters"><label>Status<select name="status"><option value="belum_masuk" <?=$filter==='belum_masuk'?'selected':''?>>Belum Ditangani</option><option value="diunggah_operator" <?=$filter==='diunggah_operator'?'selected':''?>>Sudah Diunggah</option></select></label><label>Kecamatan<select name="kecamatan"><option value="0">Semua Kecamatan</option><?php foreach($assignedKecamatan as $kec):?><option value="<?=(int)$kec['id']?>" <?=$filterKecamatan===(int)$kec['id']?'selected':''?>><?=h($kec['nama_kecamatan'])?></option><?php endforeach;?></select></label><label>Pencarian<input type="search" name="search" value="<?=h($search)?>" placeholder="Pemohon, subjek, layanan"></label><button class="btn" type="submit">Cari</button><a class="btn" style="background:#64748b" href="<?=h($resetUrl)?>">Reset</a></form><div class="table-wrap"><table><thead><tr><th>Layanan</th><th>Pemohon</th><th>Subjek</th><th>Kecamatan</th><th>Desa</th><th>Catatan Pemohon</th><th>Dilaporkan</th><th>Detail</th><th>Status/Aksi</th></tr></thead><tbody id="emailDeliveryQueueBody">
<?php if(!$items):?><tr><td colspan="9">Tidak ada laporan.</td></tr><?php endif;?><?php foreach($items as $item):?><tr><td><?=h($item['jenis_layanan'])?></td><td><?=h($item['nama_pemohon'])?></td><td><?=h($item['nama_subjek'])?></td><td><?=h($item['nama_kecamatan']??'-')?></td><td><?=h($item['nama_desa']??'-')?></td><td style="white-space:pre-wrap;min-width:180px"><?=h($item['catatan_pemohon']??'-')?></td><td><?=h($item['confirmed_at'])?></td><td class="detail-column"><form method="get" action="<?=h($scopeConfig['process'])?>" class="detail-form"><input type="hidden" name="detail_id" value="<?=(int)$item['permohonan_id']?>"><input type="hidden" name="table" value="<?=h($item['source_table'])?>"><button type="submit" formmethod="get" formaction="<?=h($scopeConfig['process'])?>" class="btn detail-link">Lihat Detail Permohonan</button></form></td><td><?php if($item['status_konfirmasi']==='belum_masuk'):?><form method="post" enctype="multipart/form-data" class="upload"><?=csrf_input()?><input type="hidden" name="action" value="upload_fallback"><input type="hidden" name="confirmation_id" value="<?=(int)$item['id']?>"><label>Dokumen 1 (PDF)<input type="file" name="dokumen_fallback[]" accept="application/pdf,.pdf" required></label><label>Dokumen 2 (opsional)<input type="file" name="dokumen_fallback[]" accept="application/pdf,.pdf"></label><label>Dokumen 3 (opsional)<input type="file" name="dokumen_fallback[]" accept="application/pdf,.pdf"></label><div class="upload-actions"><button type="submit" class="btn">Upload Dokumen</button><button type="button" class="btn btn-cancel" onclick="cancelEmailDeliveryUpload(this.form)">Batal</button></div></form><form method="post" style="margin-top:8px" onsubmit="return confirm('Mulai masa tunggu cek email selama 30 menit?');"><?=csrf_input()?><input type="hidden" name="action" value="recheck_email"><input type="hidden" name="confirmation_id" value="<?=(int)$item['id']?>"><button type="submit" class="btn" style="width:100%;background:#0891b2">Cek Email</button></form><?php else:?><div style="margin-bottom:8px">Sudah disediakan <?=h($item['operator_confirmed_at'])?></div><?php foreach($item['files'] as $uploadedFile):?><div style="display:flex;gap:6px;align-items:center;margin:6px 0;flex-wrap:wrap"><a class="btn" href="<?=h($uploadedFile['download_url'])?>"><?=h($uploadedFile['original_name'])?></a><form method="post" onsubmit="return confirm('Hapus dokumen ini?');"><?=csrf_input()?><input type="hidden" name="action" value="delete_fallback_file"><input type="hidden" name="file_id" value="<?=(int)$uploadedFile['id']?>"><button class="btn" style="background:#dc2626">Hapus</button></form></div><?php endforeach;?><details style="margin-top:12px"><summary class="btn" style="display:inline-block">Tambah Dokumen</summary><form method="post" enctype="multipart/form-data" class="upload" style="margin-top:10px"><?=csrf_input()?><input type="hidden" name="action" value="upload_fallback"><input type="hidden" name="confirmation_id" value="<?=(int)$item['id']?>"><label>Dokumen tambahan 1 (PDF)<input type="file" name="dokumen_fallback[]" accept="application/pdf,.pdf" required></label><label>Dokumen tambahan 2 (opsional)<input type="file" name="dokumen_fallback[]" accept="application/pdf,.pdf"></label><label>Dokumen tambahan 3 (opsional)<input type="file" name="dokumen_fallback[]" accept="application/pdf,.pdf"></label><div class="upload-actions"><button type="submit" class="btn">Upload Tambahan</button><button type="button" class="btn btn-cancel" onclick="cancelEmailDeliveryUpload(this.form)">Batal</button></div></form></details><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<div class="pagination"><span>Menampilkan <?=count($items)?> dari <?=$totalItems?> data · Halaman <?=$currentPage?> dari <?=$totalPages?></span><div class="pagination-links"><a class="page-link <?=$currentPage<=1?'disabled':''?>" href="<?=h(pagination_url(max(1,$currentPage-1),$filter,$filterKecamatan,$search))?>">Sebelumnya</a><?php $pageStart=max(1,$currentPage-2);$pageEnd=min($totalPages,$currentPage+2);for($pageNumber=$pageStart;$pageNumber<=$pageEnd;$pageNumber++):?><a class="page-link <?=$pageNumber===$currentPage?'active':''?>" href="<?=h(pagination_url($pageNumber,$filter,$filterKecamatan,$search))?>"><?=$pageNumber?></a><?php endfor;?><a class="page-link <?=$currentPage>=$totalPages?'disabled':''?>" href="<?=h(pagination_url(min($totalPages,$currentPage+1),$filter,$filterKecamatan,$search))?>">Berikutnya</a></div></div></section></main></div>
<script>(()=>{const body=document.getElementById('emailDeliveryQueueBody');if(!body)return;const table=body.closest('table');const headRow=table?.querySelector('thead tr');if(headRow&&!headRow.querySelector('.queue-number-heading')){const th=document.createElement('th');th.className='queue-number-heading';th.textContent='No';headRow.insertBefore(th,headRow.firstChild);}const applyNumbers=()=>{Array.from(body.querySelectorAll(':scope > tr')).forEach((row,index)=>{const emptyCell=row.querySelector(':scope > td[colspan]');if(emptyCell){emptyCell.colSpan=10;return;}if(row.querySelector(':scope > .queue-number-cell'))return;const td=document.createElement('td');td.className='queue-number-cell';td.textContent=String(<?= (int)$offset ?>+index+1);row.insertBefore(td,row.firstChild);});};applyNumbers();new MutationObserver(applyNumbers).observe(body,{childList:true});})();</script>
<div class="modal-overlay" id="followUpModal"><div class="modal-box" style="max-width:560px"><h3>Tindak Lanjut Dokumen</h3><p id="followUpIdentity"></p><p><strong>Catatan pemohon:</strong></p><p id="followUpApplicantNote" style="white-space:pre-wrap;background:#f8fafc;padding:10px;border-radius:7px"></p><form method="post"><?=csrf_input()?><input type="hidden" name="action" value="follow_up_new_application"><input type="hidden" name="confirmation_id" id="followUpConfirmationId"><label for="followUpOperatorNote" style="display:block;font-weight:600;margin:12px 0 6px">Catatan operator</label><textarea id="followUpOperatorNote" name="catatan_operator" rows="5" maxlength="1000" required style="width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:7px" placeholder="Jelaskan dokumen yang belum tersedia dan perlu diajukan kembali."></textarea><p style="font-size:12px;color:#64748b">Pemohon akan menerima notifikasi untuk membuat pengajuan baru.</p><div class="modal-actions"><button type="submit" class="btn-yes">Kirim Tindak Lanjut</button><button type="button" id="closeFollowUpModal" class="btn-cancel">Batal</button></div></form></div></div>
<script>(()=>{const items=<?=json_encode(array_map(static fn($item)=>['id'=>(int)$item['id'],'status'=>(string)$item['status_konfirmasi'],'layanan'=>(string)$item['jenis_layanan'],'pemohon'=>(string)$item['nama_pemohon'],'subjek'=>(string)$item['nama_subjek'],'catatan'=>(string)($item['catatan_pemohon']??''),'catatan_operator'=>(string)($item['catatan_operator']??'')],$items),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;const modal=document.getElementById('followUpModal');const close=()=>{modal?.classList.remove('show');document.body.style.overflow='';};const enhance=()=>{const rows=Array.from(document.querySelectorAll('#emailDeliveryQueueBody>tr')).filter(row=>!row.querySelector('td[colspan]'));rows.forEach((row,index)=>{const item=items[index],cell=row.lastElementChild;if(!item||!cell)return;if(item.status==='belum_masuk'&&!cell.querySelector('[data-follow-up]')){const button=document.createElement('button');button.type='button';button.className='btn';button.dataset.followUp='1';button.style.cssText='background:#d97706;margin-top:7px';button.textContent='Tindak Lanjut';button.onclick=()=>{document.getElementById('followUpConfirmationId').value=String(item.id);document.getElementById('followUpIdentity').textContent=`${item.layanan} — ${item.pemohon} — ${item.subjek}`;document.getElementById('followUpApplicantNote').textContent=item.catatan||'-';document.getElementById('followUpOperatorNote').value='';modal?.classList.add('show');document.body.style.overflow='hidden';};cell.appendChild(button);}else if(item.status==='perlu_pengajuan_baru'){cell.innerHTML='<strong style="color:#92400e">Perlu Pengajuan Baru</strong><div style="white-space:pre-wrap;margin:7px 0"></div>';cell.querySelector('div').textContent=item.catatan_operator||'-';}});};document.addEventListener('DOMContentLoaded',()=>{const select=document.querySelector('select[name="status"]');if(select&&!select.querySelector('[value="perlu_pengajuan_baru"]'))select.add(new Option('Perlu Pengajuan Baru','perlu_pengajuan_baru',false,<?=json_encode($filter==='perlu_pengajuan_baru')?>));enhance();});document.getElementById('closeFollowUpModal')?.addEventListener('click',close);modal?.addEventListener('click',event=>{if(event.target===modal)close();});})();</script>
<script>(()=>{const form=document.querySelector('#followUpModal form');if(!form)return;form.enctype='multipart/form-data';const note=document.getElementById('followUpOperatorNote');const upload=document.createElement('div');upload.innerHTML='<label style="display:block;font-weight:600;margin:12px 0 6px">Upload dokumen yang tersedia (opsional)</label><label style="display:block;margin:6px 0">Dokumen 1 (PDF)<input type="file" name="dokumen_fallback[]" accept="application/pdf,.pdf"></label><label style="display:block;margin:6px 0">Dokumen 2 (opsional)<input type="file" name="dokumen_fallback[]" accept="application/pdf,.pdf"></label><label style="display:block;margin:6px 0">Dokumen 3 (opsional)<input type="file" name="dokumen_fallback[]" accept="application/pdf,.pdf"></label><small style="color:#64748b">Maksimal 1 MB per PDF. Dokumen yang diupload dapat langsung diunduh pemohon.</small>';note.insertAdjacentElement('afterend',upload);form.addEventListener('submit',()=>{const hasFile=Array.from(form.querySelectorAll('input[type="file"]')).some(input=>input.files&&input.files.length);form.querySelector('input[name="action"]').value=hasFile?'upload_fallback':'follow_up_new_application';if(hasFile){const hidden=document.createElement('input');hidden.type='hidden';hidden.name='requires_new_application';hidden.value='1';form.appendChild(hidden);}});})();</script>
<script>document.addEventListener('DOMContentLoaded',()=>{const select=document.querySelector('select[name="status"]');if(select&&!select.querySelector('[value="sebagian_diunggah_perlu_pengajuan_baru"]'))select.add(new Option('Dokumen Sebagian','sebagian_diunggah_perlu_pengajuan_baru',false,<?=json_encode($filter==='sebagian_diunggah_perlu_pengajuan_baru')?>));const partialItems=<?=json_encode(array_values(array_map(static fn($item)=>['status'=>(string)$item['status_konfirmasi'],'catatan'=>(string)($item['catatan_operator']??'')],$items)),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;const rows=Array.from(document.querySelectorAll('#emailDeliveryQueueBody>tr')).filter(row=>!row.querySelector('td[colspan]'));rows.forEach((row,index)=>{if(partialItems[index]?.status!=='sebagian_diunggah_perlu_pengajuan_baru')return;const note=document.createElement('div');note.style.cssText='margin-bottom:9px;padding:8px;background:#fffbeb;color:#92400e;border-radius:6px;white-space:pre-wrap';note.textContent='Dokumen sebagian tersedia. Catatan: '+(partialItems[index].catatan||'-');row.lastElementChild?.prepend(note);});});</script>
<script>(()=>{const body=document.getElementById('emailDeliveryQueueBody');if(!body)return;const enhanceFollowUpButtons=()=>{body.querySelectorAll(':scope>tr').forEach(row=>{if(row.querySelector('[data-follow-up]'))return;const uploadForm=Array.from(row.querySelectorAll('form')).find(form=>form.querySelector('input[name="action"][value="upload_fallback"]')&&form.querySelector('input[name="confirmation_id"]'));if(!uploadForm||uploadForm.closest('details'))return;const cells=Array.from(row.children);const hasNumber=cells[0]?.classList.contains('queue-number-cell');const offset=hasNumber?1:0;const item={id:Number(uploadForm.querySelector('input[name="confirmation_id"]').value||0),layanan:(cells[offset]?.textContent||'').trim(),pemohon:(cells[offset+1]?.textContent||'').trim(),subjek:(cells[offset+2]?.textContent||'').trim(),catatan:(cells[offset+5]?.textContent||'').trim()};if(!item.id)return;const button=document.createElement('button');button.type='button';button.className='btn';button.dataset.followUp='1';button.style.cssText='background:#d97706;margin-top:7px';button.textContent='Tindak Lanjut';button.addEventListener('click',()=>{document.getElementById('followUpConfirmationId').value=String(item.id);document.getElementById('followUpIdentity').textContent=`${item.layanan} — ${item.pemohon} — ${item.subjek}`;document.getElementById('followUpApplicantNote').textContent=item.catatan||'-';document.getElementById('followUpOperatorNote').value='';document.getElementById('followUpModal')?.classList.add('show');document.body.style.overflow='hidden';});row.lastElementChild?.appendChild(button);});};enhanceFollowUpButtons();new MutationObserver(enhanceFollowUpButtons).observe(body,{childList:true});})();</script>
<div class="modal-overlay" id="logoutModal"><div class="modal-box"><h3>Konfirmasi Logout</h3><p>Apakah Anda yakin ingin logout?</p><div class="modal-actions"><button type="button" id="confirmLogout" class="btn-yes">Ya, Logout</button><button type="button" id="cancelLogout" class="btn-cancel">Batal</button></div></div></div>
<script>function cancelEmailDeliveryUpload(form){if(!form)return;form.reset();const details=form.closest('details');if(details)details.open=false;}document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('form[enctype="multipart/form-data"]').forEach(form=>form.setAttribute('action',window.location.pathname));const menuToggle=document.querySelector('.menu-toggle');const sidebar=document.querySelector('.sidebar');if(menuToggle&&sidebar){menuToggle.addEventListener('click',()=>sidebar.classList.toggle('open'));document.addEventListener('click',event=>{if(window.innerWidth<=768&&!sidebar.contains(event.target)&&!menuToggle.contains(event.target)&&sidebar.classList.contains('open'))sidebar.classList.remove('open');});}const logoutModal=document.getElementById('logoutModal');const showLogout=()=>{logoutModal?.classList.add('show');document.body.style.overflow='hidden';};const hideLogout=()=>{logoutModal?.classList.remove('show');document.body.style.overflow='';};document.getElementById('logoutBtn')?.addEventListener('click',showLogout);document.getElementById('logoutBtn2')?.addEventListener('click',showLogout);document.getElementById('confirmLogout')?.addEventListener('click',()=>window.location.href='../logout.php');document.getElementById('cancelLogout')?.addEventListener('click',hideLogout);logoutModal?.addEventListener('click',event=>{if(event.target===logoutModal)hideLogout();});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&logoutModal?.classList.contains('show'))hideLogout();});});(()=>{const refreshQueue=()=>{if(document.hidden)return;const selected=Array.from(document.querySelectorAll('#emailDeliveryQueueBody input[type="file"]')).some(input=>input.files&&input.files.length>0);const formOpen=Array.from(document.querySelectorAll('#emailDeliveryQueueBody details')).some(detail=>detail.open);if(selected||formOpen)return;fetch(window.location.href,{headers:{Accept:'text/html'},credentials:'same-origin',cache:'no-store'}).then(response=>response.ok?response.text():Promise.reject()).then(html=>{const doc=new DOMParser().parseFromString(html,'text/html');const fresh=doc.getElementById('emailDeliveryQueueBody');const current=document.getElementById('emailDeliveryQueueBody');if(fresh&&current)current.innerHTML=fresh.innerHTML;}).catch(()=>{});};window.setInterval(refreshQueue,60000);})();</script><script src="../assets/js/stable-upload.js?v=20260821-2"></script></body></html>
