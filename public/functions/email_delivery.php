<?php
declare(strict_types=1);

require_once __DIR__ . '/notifikasi.php';

const EMAIL_DELIVERY_WAIT_SECONDS = 1800;
const EMAIL_DELIVERY_MAX_FILE_BYTES = 1048576;

function email_delivery_timezone(): DateTimeZone {
    static $timezone = null;
    if ($timezone === null) $timezone = new DateTimeZone('Asia/Makassar');
    return $timezone;
}

function email_delivery_eligible_timestamp(string $selesaiAt, string $table = ''): int {
    $completed = new DateTimeImmutable($selesaiAt, email_delivery_timezone());
    return $completed->getTimestamp() + EMAIL_DELIVERY_WAIT_SECONDS;
}

function email_delivery_format_timestamp(int $timestamp): string {
    return (new DateTimeImmutable('@'.$timestamp))->setTimezone(email_delivery_timezone())->format('Y-m-d H:i:s');
}

function email_delivery_tables(): array {
    return ['tb_kartukeluarga','tb_akta_kelahiran','tb_akta_kematian','tb_pindah_alamat','tb_pindah_online',
        'tb_kartukeluarga_kua','tb_akta_kelahiran_rs','tb_akta_kematian_rs','tb_akta_kelahiran_ahlak'];
}

function email_delivery_context(mysqli $db, string $table, int $id): ?array {
    if ($id <= 0 || !in_array($table, email_delivery_tables(), true)) return null;
    $meta = [
        'tb_kartukeluarga'=>['Kartu Keluarga','kk','nama_kepalaKeluarga'],
        'tb_akta_kelahiran'=>['Akta Kelahiran','kelahiran','nama_bayi'],
        'tb_akta_kematian'=>['Akta Kematian','kematian','nama_meninggal'],
        'tb_pindah_alamat'=>['Pindah Alamat','pindah','nama_kepalaKeluarga'],
        'tb_pindah_online'=>['Pindah Masuk','pindah_online','nama_kepalaKeluarga'],
        'tb_kartukeluarga_kua'=>['Kartu Keluarga KUA','kua_kk','nama_kepalakeluarga'],
        'tb_akta_kelahiran_rs'=>['Akta Kelahiran RS','rs_kelahiran','nama_bayi'],
        'tb_akta_kematian_rs'=>['Akta Kematian RS','rs_kematian','nama_meninggal'],
        'tb_akta_kelahiran_ahlak'=>['Akta Kelahiran AHLAK','ahlak_kelahiran','nama_bayi']
    ][$table];
    $stmt = $db->prepare("SELECT * FROM `$table` WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    return [
        'id_user'=>(int)($row['id_user']??0),
        'id_kecamatan'=>(int)($row['id_kecamatan']??0),
        'layanan'=>$meta[0], 'jenis'=>$meta[1],
        'nama_pemohon'=>trim((string)($row['nama_pemohon']??'-')),
        'nama_subjek'=>trim((string)($row[$meta[2]]??'-')),
        'status'=>(string)$row['status'],
        'id_desa'=>(int)($row['id_desa']??0),
        'id_kua'=>(int)($row['id_kua']??0),
        'id_puskes'=>(int)($row['id_puskes']??0),
        'id_rs'=>(int)($row['id_rs']??0),
        'nama_kecamatan'=>(string)($row['nama_kecamatan']??''),
        'nama_desa'=>(string)($row['nama_desa']??''),
        'nik_pemohon'=>(string)($row['nik_pemohon']??''),
        'created_at'=>(string)($row['created_at']??''),
        'updated_at'=>(string)($row['updated_at']??'')
    ];
}

function email_delivery_selesai_at(mysqli $db, string $table, int $id, string $updatedAt = '', string $createdAt = ''): ?string {
    // updated_at pada data utama adalah waktu perubahan status terakhir.
    if ($updatedAt !== '' && $updatedAt !== '0000-00-00 00:00:00') return $updatedAt;
    $stmt = $db->prepare("SELECT created_at FROM tb_permohonan_status_history WHERE source_table=? AND permohonan_id=? AND status='selesai' ORDER BY created_at DESC,id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('si', $table, $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['created_at'])) return (string)$row['created_at'];
    }
    return $createdAt !== '' && $createdAt !== '0000-00-00 00:00:00' ? $createdAt : null;
}

function email_delivery_get(mysqli $db, string $table, int $id): ?array {
    $stmt=$db->prepare("SELECT * FROM tb_konfirmasi_dokumen_email WHERE source_table=? AND permohonan_id=? LIMIT 1");
    if(!$stmt)return null; $stmt->bind_param('si',$table,$id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

function email_delivery_requester_can_access(mysqli $db, array $context, string $table, int $userId): bool {
    if ((int)$context['id_user'] === $userId) return true;
    $unit = [
        'tb_kartukeluarga_kua'=>['id_kua','id_kua'],
        'tb_akta_kelahiran_rs'=>['id_rs','id_rs'], 'tb_akta_kematian_rs'=>['id_rs','id_rs'],
        'tb_akta_kelahiran_ahlak'=>['id_puskes','id_puskes']
    ][$table] ?? null;
    if (!$unit || (int)($context[$unit[1]] ?? 0) <= 0) return false;
    $unitId=(int)$context[$unit[1]];
    if ((int)($_SESSION[$unit[0]] ?? 0) === $unitId) return true;
    $stmt=$db->prepare("SELECT 1 FROM tb_users WHERE id=? AND `{$unit[0]}`=? LIMIT 1");
    if(!$stmt)return false; $stmt->bind_param('ii',$userId,$unitId);
    $stmt->execute(); $allowed=$stmt->get_result()->num_rows>0; $stmt->close(); return $allowed;
}

function email_delivery_files(mysqli $db, int $confirmationId): array {
    $stmt=$db->prepare("SELECT id,original_name,file_size,uploaded_at FROM tb_konfirmasi_dokumen_email_file WHERE konfirmasi_id=? ORDER BY uploaded_at DESC,id DESC");
    if(!$stmt)return []; $stmt->bind_param('i',$confirmationId); $stmt->execute(); $result=$stmt->get_result(); $items=[];
    while($row=$result->fetch_assoc()){ $row['download_url']='../user/download_email_delivery.php?id='.(int)$row['id']; $items[]=$row; } $stmt->close(); return $items;
}

function email_delivery_status(mysqli $db, string $table, int $id, int $userId): array {
    $context=email_delivery_context($db,$table,$id);
    if(!$context || !email_delivery_requester_can_access($db,$context,$table,$userId) || strtolower(trim($context['status']))!=='selesai') return ['available'=>false];
    $selesaiAt=email_delivery_selesai_at($db,$table,$id,$context['updated_at'],$context['created_at']);
    if(!$selesaiAt)return ['available'=>false];
    $record=email_delivery_get($db,$table,$id);
    $isRecheck=$record && (string)$record['status_konfirmasi']==='dibatalkan' && !empty($record['eligible_at']);
    $eligibleTs=$isRecheck
      ? (new DateTimeImmutable((string)$record['eligible_at'],email_delivery_timezone()))->getTimestamp()
      : email_delivery_eligible_timestamp($selesaiAt, $table);
    $displayStatus=$isRecheck ? 'menunggu' : ($record['status_konfirmasi']??'menunggu');
    return [
      'available'=>true,'eligible'=>time()>=$eligibleTs,'eligible_at'=>email_delivery_format_timestamp($eligibleTs),'is_recheck'=>$isRecheck,
      'seconds_remaining'=>max(0,$eligibleTs-time()),'status'=>$displayStatus,
      'catatan'=>$record['catatan_pemohon']??'',
      'catatan_operator'=>$record['catatan_operator']??'',
      'tindak_lanjut_at'=>$record['tindak_lanjut_at']??'',
      'source_table'=>$table,
      'files'=>$record ? email_delivery_files($db,(int)$record['id']) : []
    ];
}

function email_delivery_new_application_url(string $sourceTable, string $role = 'user'): string {
    return $role === 'petugas_desa' ? 'petugas_desa_ajukan_permohonan.php' : 'ajukan_permohonan.php';
}

function email_delivery_operator_can_access_record(mysqli $db, int $operatorId, array $record, string $scope = 'general'): bool {
    $table=(string)($record['source_table']??'');
    $context=email_delivery_context($db,$table,(int)($record['permohonan_id']??0));
    if(!$context)return false;
    if($scope==='kua' && $table!=='tb_kartukeluarga_kua')return false;
    if($scope==='ahlak' && $table!=='tb_akta_kelahiran_ahlak')return false;
    if($scope==='rs' && !in_array($table,['tb_akta_kelahiran_rs','tb_akta_kematian_rs'],true))return false;
    if($scope==='general' && in_array($table,['tb_kartukeluarga_kua','tb_akta_kelahiran_ahlak','tb_akta_kelahiran_rs','tb_akta_kematian_rs'],true))return false;
    $column=['kua'=>'kua_id','ahlak'=>'puskes_id','rs'=>'rs_id'][$scope]??'kecamatan_id';
    $unitId=['kua'=>(int)$context['id_kua'],'ahlak'=>(int)$context['id_puskes'],'rs'=>(int)$context['id_rs']][$scope]??(int)$context['id_kecamatan'];
    if($operatorId<=0||$unitId<=0)return false;
    $stmt=$db->prepare("SELECT 1 FROM tb_operator_kecamatan WHERE operator_id=? AND `$column`=? LIMIT 1");
    if(!$stmt)return false;
    $stmt->bind_param('ii',$operatorId,$unitId);$stmt->execute();$allowed=$stmt->get_result()->num_rows>0;$stmt->close();
    return $allowed;
}

function email_delivery_pending_confirmation_count(mysqli $db, int $userId): int {
    if ($userId <= 0) return 0;
    $total = 0;
    foreach (email_delivery_tables() as $table) {
        $sql = "SELECT COUNT(*) AS total
                FROM `$table` p
                LEFT JOIN tb_konfirmasi_dokumen_email k
                  ON k.source_table = ? AND k.permohonan_id = p.id
                WHERE p.id_user = ?
                  AND LOWER(p.status) = 'selesai'
                  AND (k.id IS NULL OR k.status_konfirmasi = 'dibatalkan')
                  AND CASE
                    WHEN k.status_konfirmasi = 'dibatalkan' THEN k.eligible_at <= NOW()
                    ELSE COALESCE(
                      (SELECT MAX(h.created_at)
                       FROM tb_permohonan_status_history h
                       WHERE h.source_table = ? AND h.permohonan_id = p.id AND h.status = 'selesai'),
                      p.updated_at
                    ) <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                  END";
        $stmt = $db->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('sis', $table, $userId, $table);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $total += (int)($row['total'] ?? 0);
        }
        $stmt->close();
    }
    return $total;
}

function email_delivery_upload_root(): string { return dirname(__DIR__).DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'email_delivery'; }

function email_delivery_category(string $sourceTable): string {
    return match($sourceTable) {
        'tb_kartukeluarga' => 'kartu_keluarga',
        'tb_akta_kelahiran' => 'akta_kelahiran',
        'tb_akta_kematian' => 'akta_kematian',
        'tb_pindah_alamat' => 'pindah_alamat',
        'tb_pindah_online' => 'pindah_masuk',
        default => 'permohonan'
    };
}

function email_delivery_filename_segment(string $value, string $fallback): string {
    $value=trim(strtolower($value));
    if($value==='')$value=$fallback;
    $value=preg_replace('/[^a-z0-9]+/','_',$value);
    $value=trim((string)$value,'_');
    return $value!==''?substr($value,0,60):$fallback;
}

function email_delivery_storage_basename(array $context, int $timestamp): string {
    return implode('_',[
        email_delivery_filename_segment((string)($context['nama_kecamatan']??''),'kecamatan'),
        email_delivery_filename_segment((string)($context['nama_desa']??''),'desa'),
        email_delivery_filename_segment((string)($context['nik_pemohon']??''),'nik'),
        email_delivery_filename_segment((string)($context['nama_pemohon']??''),'pemohon'),
        email_delivery_filename_segment((string)($context['nama_subjek']??''),'subjek'),
        (new DateTimeImmutable('@'.$timestamp))->setTimezone(email_delivery_timezone())->format('Ymd_His')
    ]);
}
function email_delivery_resolve_file(array $file) {
    $root=realpath(email_delivery_upload_root()); $relative=str_replace('\\','/',ltrim((string)($file['file_path']??''),'/'));
    if(strpos($relative,'uploads/email_delivery/')!==0 || strtolower(pathinfo($relative,PATHINFO_EXTENSION))!=='pdf')return false;
    $candidate=realpath(dirname(__DIR__).DIRECTORY_SEPARATOR.$relative);
    if(!$root||!$candidate||!is_file($candidate))return false;
    return strpos(str_replace('\\','/',$candidate),rtrim(str_replace('\\','/',$root),'/').'/')===0 ? $candidate : false;
}
?>
