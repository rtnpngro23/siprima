<?php
declare(strict_types=1);
// Additive, CLI-only; no production migration is performed by creating this file.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../app/Api/Core.php';
require_once __DIR__.'/../app/Api/Catalog.php';
if (!in_array('--apply',$argv,true) && !in_array('--check',$argv,true)) { echo "Usage: php sql/mobile_api_migrate.php --check|--apply\n"; exit(1); }
try {
    $db=Siprima\Api\database();
    $required=[
        'tb_users'=>['id','role','is_active','must_change_password','temporary_password_expires_at'],
        'tb_operator_kecamatan'=>['operator_id','kecamatan_id','kua_id','puskes_id','rs_id'],
        'tb_desa'=>['id','kecamatan_id','nama_desa'], 'tb_kecamatan'=>['id','nama_kecamatan'],
        'tb_pendaftaran_petugas'=>['id','surat_tugas','kode_verifikasi','status'],
        'tb_password_reset'=>['user_id','token_hash','password_snapshot'],
        'tb_permohonan_status_history'=>['source_table','permohonan_id','actor_role','event_type','meta_json'],
        'tb_permohonan_komentar'=>['parent_id','deleted_at','komentar'],
        'tb_permohonan_feedback'=>['rating','service_rating','respondent_role','trigger_type'],
        'tb_konfirmasi_dokumen_email'=>['catatan_pemohon','catatan_operator','tindak_lanjut_at','status_konfirmasi'],
        'tb_konfirmasi_dokumen_email_file'=>['konfirmasi_id','file_path'],
        'tb_permohonan_dokumen_hasil'=>['source_table','permohonan_id','file_path'],
        'tb_service_settings'=>['id','payload'], 'tb_notifikasi'=>['user_id','target_url','source_table','permohonan_id'],
    ];
    foreach(Siprima\Api\Catalog::all('user') as $s) {
        $required[$s['table']]=array_values(array_unique(array_merge([$s['id_column'],'id_user','dokumen','nama_folder','status','alasan','created_at','updated_at','resubmitted_at'],array_map(static fn($f)=>$f==='waktu'?'Waktu':$f,$s['fields']))));
    }
    $problems=[];
    foreach($required as $table=>$columns) {
        $row=Siprima\Api\one($db,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if (!$row || strtoupper((string)$row['ENGINE'])!=='INNODB') { $problems[]="$table must exist and use InnoDB"; continue; }
        $actual=array_column(Siprima\Api\rows($db,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]),'COLUMN_NAME');
        foreach(array_diff($columns,$actual) as $column) $problems[]="$table.$column missing";
    }
    if ($problems) throw new RuntimeException("Website schema prerequisites failed:\n".implode("\n",$problems));
    if (in_array('--check',$argv,true)) { echo "PASS website schema prerequisites\n"; exit; }
    $sql=file_get_contents(__DIR__.'/20260913_mobile_api.sql');
    $db->multi_query($sql);
    do { if ($r=$db->store_result()) $r->free(); } while($db->more_results() && $db->next_result());
    Siprima\Api\execute($db,'INSERT INTO tb_mobile_migrations (version) VALUES (?) ON DUPLICATE KEY UPDATE version=VALUES(version)',['20260913_mobile_api']);
    echo "PASS mobile API migration (idempotent)\n";
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
