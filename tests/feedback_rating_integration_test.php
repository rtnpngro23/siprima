<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../public/user/functions/feedback_validation.php';
require_once __DIR__.'/../app/config/env_loader.php';
loadEnv(__DIR__.'/../app/config/.env');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli((string)env('DB_HOST','localhost'),(string)env('DB_USER','root'),(string)env('DB_PASS',''),(string)env('DB_NAME'));
$db->set_charset('utf8mb4');
// Connection-local table only; applicant records and real feedback are never modified.
$db->query('CREATE TEMPORARY TABLE feedback_rating_test LIKE tb_permohonan_feedback');
$db->query("INSERT INTO feedback_rating_test (source_table,permohonan_id,respondent_user_id,respondent_role,jenis_layanan,nama_pemohon,nama_subjek,trigger_type)
    VALUES ('test',1,1,'pemohon','Uji','Pemohon Uji','Subjek Uji','download')");
$id=(int)$db->insert_id;
$endpoint=file_get_contents(__DIR__.'/../public/user/permohonan_feedback.php');
if (!preg_match('/prepare\("(UPDATE tb_permohonan_feedback SET status=\'submitted\'[^"]+)"\)/',$endpoint,$match)) throw new RuntimeException('Update SQL not found');
$sql=str_replace('tb_permohonan_feedback','feedback_rating_test',$match[1]);
$count=0;
foreach([
    ['jenis_feedback'=>'tanggapan','rating'=>5,'service_rating'=>5,'pesan'=>''],
    ['jenis_feedback'=>'tanggapan','rating'=>2,'service_rating'=>5,'pesan'=>'Aplikasi sulit digunakan'],
    ['jenis_feedback'=>'tanggapan','rating'=>5,'service_rating'=>3,'pesan'=>'Informasi kurang jelas'],
    ['jenis_feedback'=>'aduan','rating'=>5,'service_rating'=>5,'pesan'=>'Mohon periksa dokumen'],
] as $payload) {
    $db->query("UPDATE feedback_rating_test SET status='pending',rating=NULL,service_rating=NULL WHERE id=$id");
    $v=permohonan_feedback_validate($payload);
    $stmt=$db->prepare($sql);
    $stmt->bind_param('siisi',$v['type'],$v['rating'],$v['service_rating'],$v['message'],$id);
    $stmt->execute();
    if($stmt->affected_rows!==1) throw new RuntimeException('Feedback not saved');
    $row=$db->query("SELECT rating,service_rating,pesan,status FROM feedback_rating_test WHERE id=$id")->fetch_assoc();
    if (($row['rating']===null?null:(int)$row['rating'])!==$v['rating']
        || ($row['service_rating']===null?null:(int)$row['service_rating'])!==$v['service_rating']
        || $row['pesan']!==$v['message'] || $row['status']!=='submitted') throw new RuntimeException('Stored feedback mismatch');
    $stmt->execute();
    if($stmt->affected_rows!==0) throw new RuntimeException('Feedback could be overwritten');
    $stmt->close(); $count++;
}
$db->close();
echo "$count database storage scenarios passed; duplicate submissions blocked; temporary data discarded.\n";