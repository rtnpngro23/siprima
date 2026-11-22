<?php
declare(strict_types=1);
// Test-only fixture. Never deploy tests/; no HTTP mutation endpoint is provided.
if (getenv('SIPRIMA_ISOLATED_TEST')!=='1' || PHP_SAPI!=='cli') { http_response_code(404);exit; }
$fixtureRoot=(string)getenv('MOBILE_API_TEST_ROOT');
if (!$fixtureRoot || !is_dir($fixtureRoot)) exit(1);
require_once $fixtureRoot.'/app/Api/Router.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root', '', '', (int)getenv('TEST_DB_PORT'));
$db->query('CREATE DATABASE IF NOT EXISTS siprima_mobile_test');$db->select_db('siprima_mobile_test');$db->set_charset('utf8mb4');$db->query("SET time_zone='+08:00'");
$command=$argv[1]??'';
$input=json_decode(stream_get_contents(STDIN),true)?:[];
function migration(mysqli $db,string $sql): void {
    $db->multi_query($sql);do { if($r=$db->store_result()) $r->free(); } while($db->more_results() && $db->next_result());
}
function result(mixed $v): never { echo json_encode($v,JSON_THROW_ON_ERROR);exit; }
if ($command==='setup') {
    // Extract DDL only. The SQL dump's resident records are NEVER imported.
    $dump=file_get_contents(__DIR__.'/../sql/sipr_db_dukcapil.sql');
    preg_match_all('/^CREATE TABLE `([^`]+)`.*?;/ms',$dump,$matches,PREG_SET_ORDER);
    $names=['tb_users','tb_operator_kecamatan','tb_kecamatan','tb_desa','tb_pendaftaran_petugas','tb_notifikasi'];
    foreach(Siprima\Api\Catalog::all('user') as $s) $names[]=$s['table'];
    foreach($matches as $match) if(in_array($match[1],$names,true)) {
        $ddl=preg_replace('/(`(?:id|id_lapor)` int\(11\) NOT NULL)/','$1 AUTO_INCREMENT PRIMARY KEY',$match[0],1);
        $db->query($ddl);
    }
    $db->query('ALTER TABLE tb_users ADD must_change_password TINYINT NOT NULL DEFAULT 0, ADD temporary_password_expires_at DATETIME NULL');
    $db->query("CREATE TABLE tb_password_reset (user_id INT PRIMARY KEY,requested_by INT NOT NULL,request_id CHAR(32) UNIQUE,token_hash CHAR(64) UNIQUE,password_snapshot VARCHAR(255),expires_at DATETIME,email_status VARCHAR(16),telegram_status VARCHAR(16),consumed_at DATETIME NULL,created_at DATETIME) ENGINE=InnoDB");
    foreach(['20260907_service_tracking.sql','2026-07-16_create_permohonan_komentar.sql','2026-07-16_create_permohonan_dokumen_hasil.sql',
        '2026-07-20_create_email_delivery_confirmation.sql','2026-07-21_add_tindak_lanjut_dokumen_email.sql','2026-08-21_create_permohonan_feedback.sql',
        '20260910_feedback_service_rating.sql','2026-07-20_add_notifikasi_permohonan_target.sql','20260906_service_settings.sql'] as $file) migration($db,file_get_contents(__DIR__.'/../sql/'.$file));
    $hash=password_hash('Fixture9!Secret',PASSWORD_BCRYPT,['cost'=>4]);
    foreach([1=>'user',2=>'user',3=>'petugas_desa',4=>'petugas_desa',5=>'operator',6=>'admin',7=>'user',8=>'user',9=>'user',10=>'user',11=>'user',12=>'user'] as $id=>$role) {
        Siprima\Api\insert($db,'tb_users',['id'=>$id,'nama'=>'Synthetic '.$id,'nik'=>str_pad((string)$id,16,'0',STR_PAD_LEFT),'email'=>'user'.$id.'@example.invalid','no_hp'=>'081234567890','alamat'=>'Synthetic address','password'=>$hash,'role'=>$role,'is_active'=>$id===8?0:1,'id_kecamatan'=>1,'id_desa'=>$id===4?2:1,'id_rs'=>$id===7?1:null]);
    }
    $db->query("UPDATE tb_users SET must_change_password=1,temporary_password_expires_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id=9");
    $db->query("INSERT INTO tb_kecamatan (id,nama_kecamatan) VALUES (1,'Synthetic District'),(2,'Other District')");
    $db->query("INSERT INTO tb_desa (id,kecamatan_id,nama_desa) VALUES (1,1,'Synthetic Village'),(2,1,'Other Village'),(3,2,'Third Village')");
    $db->query('INSERT INTO tb_operator_kecamatan (operator_id,kecamatan_id) VALUES (5,1)');
    Siprima\Api\execute($db,'UPDATE tb_service_settings SET payload=? WHERE id=1',[json_encode(service_hours_defaults())]);
    result(['setup'=>true]);
}
switch($command) {
    case 'image':
        // A real decodable PNG with alpha: the upload path now re-encodes through GD, so a
        // hand-written byte string that merely looks like a PNG is no longer a valid fixture.
        $image=imagecreatetruecolor(4,4);imagealphablending($image,false);imagesavealpha($image,true);
        imagefill($image,0,0,imagecolorallocatealpha($image,20,180,100,64));
        ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);result(['base64'=>base64_encode($bytes)]);
    case 'fault':
        $table=$input['kind']==='history'?'tb_permohonan_status_history':'tb_notifikasi';
        $name=$input['kind']==='history'?'mobile_test_history':'mobile_test_notification';
        $db->query("DROP TRIGGER IF EXISTS $name");
        if($input['enabled']) $db->query("CREATE TRIGGER $name BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic failure'");
        result(true);
    case 'outbox-attempt':
        require_once $fixtureRoot.'/app/Api/Maintenance.php';
        try { result(['ok'=>true,'count'=>Siprima\Api\Maintenance::outbox($db)]); } catch(Throwable $e) { result(['ok'=>false]); }
    case 'dispatch':
        try { result((new Siprima\Api\Router($db))->dispatch($input['method'],$input['path'],$input['body']??[],[],[],$input['headers']??[])); }
        catch(Siprima\Api\ApiError $e) { result(['status'=>$e->status,'error'=>$e->errorCode]); }
    case 'clear-rate': $db->query('DELETE FROM tb_mobile_rate_limits');result(true);
    case 'state':
        result(['applications'=>(int)$db->query('SELECT COUNT(*) n FROM tb_kartukeluarga')->fetch_assoc()['n'],
            'temporary_uploads'=>(int)$db->query('SELECT COUNT(*) n FROM tb_mobile_uploads WHERE application_ref IS NULL')->fetch_assoc()['n'],
            'outbox_pending'=>(int)$db->query('SELECT COUNT(*) n FROM tb_mobile_outbox WHERE delivered_at IS NULL')->fetch_assoc()['n'],
            'notifications'=>(int)$db->query('SELECT COUNT(*) n FROM tb_notifikasi')->fetch_assoc()['n']]);
    case 'status':
        [$s,$id]=Siprima\Api\Applications::parse($input['ref'],'user');
        Siprima\Api\execute($db,"UPDATE `{$s['table']}` SET status=?,updated_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE `{$s['id_column']}`=?",[$input['status'],$id]);result(true);
    case 'future-status':
        [$s,$id]=Siprima\Api\Applications::parse($input['ref'],'user');
        Siprima\Api\execute($db,"UPDATE `{$s['table']}` SET status='selesai',updated_at=NOW() WHERE `{$s['id_column']}`=?",[$id]);result(true);
    case 'settings-missing': $db->query("UPDATE tb_service_settings SET payload='invalid-json' WHERE id=1");result(true);
    case 'closed':
        $settings=service_hours_defaults();if($input['closed']) $settings['events']=[['id'=>'1234567890abcdef','title'=>'Synthetic closure','message'=>'Fixture only','kind'=>'closure','enabled'=>true,'announce'=>'2020-01-01T00:00','start'=>'2020-01-01T00:00','end'=>'2099-01-01T00:00']];
        Siprima\Api\execute($db,'UPDATE tb_service_settings SET payload=? WHERE id=1',[json_encode($settings)]);result(true);
    case 'identity':
        $column=$input['field'];if(!in_array($column,['is_active','role','id_desa','id_rs','must_change_password'],true)) exit(1);
        Siprima\Api\execute($db,"UPDATE tb_users SET `$column`=? WHERE id=?",[$input['value'],$input['id']]);result(true);
    case 'expire-session':
        $column=$input['type']==='access'?'access_expires_at':($input['type']==='idle'?'last_used_at':'expires_at');
        // Idle expiry follows the mobile policy constant, which is far longer than 4000 seconds.
        $past=$input['type']==='idle'?time()-Siprima\Api\Auth::IDLE_SECONDS-1:time()-4000;
        Siprima\Api\execute($db,"UPDATE tb_mobile_sessions SET `$column`=? WHERE id=?",[$past,$input['id']]);result(true);
    case 'expire-upload': Siprima\Api\execute($db,'UPDATE tb_mobile_uploads SET expires_at=? WHERE id=?',[time()-1,$input['id']]);result(true);
    case 'orphan':
        $file=Siprima\Api\Uploads::root().'/'.str_repeat('f',32).'.png';file_put_contents($file,'Synthetic orphan');touch($file,time()-172801);result(true);
    case 'web-change':
        // Mimic a website update within the same timestamp: hash revision must still change.
        Siprima\Api\execute($db,'UPDATE tb_kartukeluarga SET alasan=?,updated_at=updated_at WHERE id=?',['Synthetic website update',$input['id']]);result(true);
    case 'reset-token':
        $r=siprima_reset_issue($db,(int)$input['id'],6);result(substr($r['url'],strrpos($r['url'],'=')+1));
    case 'email-document':
        [$s,$id]=Siprima\Api\Applications::parse($input['ref'],'user');
        $r=Siprima\Api\one($db,'SELECT id FROM tb_konfirmasi_dokumen_email WHERE source_table=? AND permohonan_id=?',[$s['table'],$id]);
        $dir=$fixtureRoot.'/public/uploads/email_delivery';Siprima\Api\Uploads::directory($dir);$name='synthetic-'.$id.'.pdf';file_put_contents($dir.'/'.$name,"%PDF-1.4\n% synthetic fixture only\n%%EOF");
        $doc=Siprima\Api\insert($db,'tb_konfirmasi_dokumen_email_file',['konfirmasi_id'=>$r['id'],'uploaded_by'=>5,'original_name'=>$name,'stored_name'=>$name,'file_path'=>'uploads/email_delivery/'.$name,'file_size'=>filesize($dir.'/'.$name),'mime_type'=>'application/pdf']);
        Siprima\Api\execute($db,"UPDATE tb_konfirmasi_dokumen_email SET status_konfirmasi='sebagian_diunggah_perlu_pengajuan_baru' WHERE id=?",[$r['id']]);result('email-'.$doc);
    case 'notification':
        result(Siprima\Api\insert($db,'tb_notifikasi',['user_id'=>$input['id'],'judul'=>'Synthetic notification','pesan'=>'Fixture message','tipe'=>'info']));
    case 'outbox': require_once $fixtureRoot.'/app/Api/Maintenance.php';result(Siprima\Api\Maintenance::outbox($db));
    case 'cleanup': require_once $fixtureRoot.'/app/Api/Maintenance.php';result(Siprima\Api\Maintenance::cleanup($db));
    case 'assert-private':
        foreach(Siprima\Api\rows($db,'SELECT * FROM tb_mobile_sessions') as $s) {
            foreach(['access_hash','refresh_hash'] as $key) if(!preg_match('/^[a-f0-9]{64}$/D',$s[$key])) throw new RuntimeException('Invalid token hash');
            foreach($input['tokens'] as $token) if(in_array($token,$s,true)) throw new RuntimeException('Raw token persisted');
        }result(true);
    default: throw new RuntimeException('Unknown fixture command');
}
