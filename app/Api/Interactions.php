<?php
declare(strict_types=1);
namespace Siprima\Api;

require_once __DIR__.'/../../public/functions/email_delivery.php';
require_once __DIR__.'/../../public/functions/komentar_permohonan.php';
require_once __DIR__.'/../../public/functions/document_token.php';
require_once __DIR__.'/../../public/user/functions/feedback_validation.php';
require_once __DIR__.'/../config/document_features.php';

final class Interactions
{
    private Applications $apps;
    public function __construct(private \mysqli $db) { $this->apps=new Applications($db); }

    public function comments(array $u,string $ref,array $query): array
    {
        only($query,['page','per_page']);[$p,$size,$offset]=page($query);[$s,$r]=$this->apps->get($u,$ref);
        if ((int)$r['id_user']!==(int)$u['id']) fail(403,'ACTION_FORBIDDEN','Komentar hanya untuk permohonan milik akun.');
        $params=[$s['table'],$r[$s['id_column']]];
        $items=rows($this->db,"SELECT id,user_role,parent_id,komentar,created_at FROM tb_permohonan_komentar WHERE source_table=? AND permohonan_id=? AND deleted_at IS NULL ORDER BY created_at,id LIMIT $size OFFSET $offset",$params);
        foreach($items as &$item) $item['created_at']=iso($item['created_at']);unset($item);
        return ['items'=>$items,'pagination'=>['page'=>$p,'per_page'=>$size,'total'=>(int)one($this->db,'SELECT COUNT(*) AS n FROM tb_permohonan_komentar WHERE source_table=? AND permohonan_id=? AND deleted_at IS NULL',$params)['n']]];
    }

    public function comment(array $u,string $ref,array $input): array
    {
        only($input,['message','parent_id']);$message=text($input,'message',1000);
        $parent=isset($input['parent_id'])?integer($input['parent_id'],'parent_id'):null;
        return transaction($this->db,function () use ($u,$ref,$message,$parent): array {
            [$s,$r]=$this->apps->get($u,$ref,true);
            if ((int)$r['id_user']!==(int)$u['id']) fail(403,'ACTION_FORBIDDEN','Komentar hanya untuk permohonan milik akun.');
            $error=null;
            $id=\komentar_permohonan_create($this->db,$s['table'],(int)$r[$s['id_column']],(int)$u['id'],$u['role'],$message,$error,$parent);
            if (!$id) fail(422,'INVALID_COMMENT',$error?:'Komentar tidak dapat disimpan.');
            insert($this->db,'tb_mobile_outbox',['source_table'=>$s['table'],'permohonan_id'=>$r[$s['id_column']],'event_type'=>'comment','created_at'=>time()]);
            return ['comment_id'=>$id];
        });
    }

    public function documents(array $u,string $ref): array
    {
        [$s,$r]=$this->apps->get($u,$ref);$id=$r[$s['id_column']];$items=[];
        if ((int)$r['id_user']!==(int)$u['id']) fail(403,'ACTION_FORBIDDEN','Hasil hanya tersedia untuk akun pemohon.');
        if (DOKUMEN_HASIL_ENABLED) {
            foreach(rows($this->db,'SELECT id,original_name,file_size,mime_type,uploaded_at FROM tb_permohonan_dokumen_hasil WHERE source_table=? AND permohonan_id=? AND id_user_pemohon=? ORDER BY id',[$s['table'],$id,$u['id']]) as $d) $items[]=$this->documentMeta('result',$d);
        }
        foreach(rows($this->db,'SELECT f.id,f.original_name,f.file_size,f.mime_type,f.uploaded_at FROM tb_konfirmasi_dokumen_email_file f JOIN tb_konfirmasi_dokumen_email c ON c.id=f.konfirmasi_id WHERE c.source_table=? AND c.permohonan_id=? AND c.id_user_pemohon=? ORDER BY f.id',[$s['table'],$id,$u['id']]) as $d) $items[]=$this->documentMeta('email',$d);
        $receipt=$s['code']==='peristiwa'?['available'=>false]:\email_delivery_status($this->db,$s['table'],(int)$id,(int)$u['id']);
        unset($receipt['files'],$receipt['source_table']);
        foreach(['eligible_at','tindak_lanjut_at'] as $k) if(isset($receipt[$k])) $receipt[$k]=iso($receipt[$k]);
        return ['items'=>$items,'receipt'=>$receipt,'operator_results_enabled'=>DOKUMEN_HASIL_ENABLED];
    }

    private function documentMeta(string $kind,array $d): array
    {
        $ref=$kind.'-'.$d['id'];
        return ['document_ref'=>$ref,'name'=>$d['original_name'],'file_size'=>(int)$d['file_size'],'mime_type'=>$d['mime_type'],'uploaded_at'=>iso($d['uploaded_at']),'download_path'=>'/documents/'.$ref.'/download'];
    }

    public function download(array $u,string $ref): array
    {
        $path=null;$name='document';$trigger=null;
        if (preg_match('/^attachment-([a-z_]+-[1-9][0-9]*)-([a-f0-9]{24})$/D',$ref,$m)) {
            [$s,$r]=$this->apps->get($u,$m[1]);
            foreach(Applications::documents($r) as $d) if(hash_equals(substr(hash('sha256',(string)($d['path']??'')),0,24),$m[2])) {
                $resolved=\siprima_document_resolve_path(__DIR__.'/../../public/user',(string)$d['path']);
                $path=$resolved['file']??null;$name=$d['original_name']??'Dokumen';break;
            }
        } elseif (preg_match('/^(email|result)-([1-9][0-9]{0,9})$/D',$ref,$m)) {
            if ($m[1]==='result' && !DOKUMEN_HASIL_ENABLED) fail(404,'NOT_FOUND','Dokumen tidak ditemukan.');
            $d=$m[1]==='email'
                ?one($this->db,'SELECT f.*,c.source_table,c.permohonan_id,c.id_user_pemohon FROM tb_konfirmasi_dokumen_email_file f JOIN tb_konfirmasi_dokumen_email c ON c.id=f.konfirmasi_id WHERE f.id=?',[$m[2]])
                :one($this->db,'SELECT * FROM tb_permohonan_dokumen_hasil WHERE id=?',[$m[2]]);
            if (!$d || (int)$d['id_user_pemohon']!==(int)$u['id']) fail(404,'NOT_FOUND','Dokumen tidak ditemukan.');
            $code=null;foreach(Catalog::all($u['role']) as $s) if($s['table']===$d['source_table']) $code=$s['code'];
            if (!$code) fail(404,'NOT_FOUND','Dokumen tidak ditemukan.');
            [$s,$r]=$this->apps->get($u,$code.'-'.$d['permohonan_id']);
            if ($m[1]==='email') $path=\email_delivery_resolve_file($d)?:null;
            else {
                $relative=(string)$d['file_path'];
                if (str_starts_with($relative,'uploads/hasil_permohonan/') && strtolower(pathinfo($relative,PATHINFO_EXTENSION))==='pdf') {
                    $resolved=\siprima_document_resolve_path(__DIR__.'/../../public',$relative);$path=$resolved['file']??null;
                }
            }
            $name=$d['original_name'];$trigger=[$s,$r];
        }
        if (!$path || !is_file($path) || !is_readable($path)) fail(404,'NOT_FOUND','Dokumen tidak ditemukan.');
        if ($trigger) $this->triggerFeedback($u,$trigger[0],$trigger[1],'download');
        return ['path'=>$path,'name'=>preg_replace('/[^A-Za-z0-9._-]/','_',basename($name)),'mime'=>(new \finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream'];
    }

    public function receipt(array $u,string $ref,array $input): array
    {
        only($input,['confirmation','note']);$choice=text($input,'confirmation',32);$note=text($input,'note',1000,$choice==='belum_masuk');
        if (!in_array($choice,['sudah_masuk','belum_masuk'],true)) fail(422,'VALIDATION_ERROR','Pilihan penerimaan tidak valid.');
        return transaction($this->db,function () use ($u,$ref,$choice,$note): array {
            [$s,$r]=$this->apps->get($u,$ref,true);$id=(int)$r[$s['id_column']];
            if (!Applications::permissions($u,$s,$r,true)['can_receive']) fail(403,'ACTION_FORBIDDEN','Konfirmasi belum tersedia untuk permohonan ini.');
            $status=\email_delivery_status($this->db,$s['table'],$id,(int)$u['id']);
            if (empty($status['eligible'])) fail(422,'RECEIPT_NOT_READY','Tunggu masa cek email 30 menit setelah selesai.');
            $existing=one($this->db,'SELECT * FROM tb_konfirmasi_dokumen_email WHERE source_table=? AND permohonan_id=? FOR UPDATE',[$s['table'],$id]);
            if ($existing && in_array($existing['status_konfirmasi'],['diunggah_operator','sebagian_diunggah_perlu_pengajuan_baru','perlu_pengajuan_baru'],true)) return ['status'=>$existing['status_konfirmasi'],'changed'=>false];
            $context=\email_delivery_context($this->db,$s['table'],$id);
            $end=\email_delivery_selesai_at($this->db,$s['table'],$id,(string)$r['updated_at'],(string)$r['created_at']);
            if ($existing) execute($this->db,'UPDATE tb_konfirmasi_dokumen_email SET status_konfirmasi=?,catatan_pemohon=?,confirmed_at=NOW(),updated_at=NOW() WHERE id=?',[$choice,$note,$existing['id']]);
            else insert($this->db,'tb_konfirmasi_dokumen_email',['source_table'=>$s['table'],'permohonan_id'=>$id,'id_user_pemohon'=>$u['id'],'id_kecamatan'=>$r['id_kecamatan'],'id_desa'=>$r['id_desa'],
                'jenis_layanan'=>$s['name'],'nama_pemohon'=>$context['nama_pemohon'],'nama_subjek'=>$context['nama_subjek'],'selesai_at'=>$end,'eligible_at'=>$status['eligible_at'],'status_konfirmasi'=>$choice,'catatan_pemohon'=>$note]);
            if ($choice==='belum_masuk') insert($this->db,'tb_mobile_outbox',['source_table'=>$s['table'],'permohonan_id'=>$id,'event_type'=>'receipt_missing','created_at'=>time()]);
            $this->triggerFeedback($u,$s,$r,'konfirmasi');
            return ['status'=>$choice,'changed'=>true];
        });
    }

    private function triggerFeedback(array $u,array $s,array $r,string $trigger): void
    {
        if ($s['code']==='peristiwa' || $r['status']!=='selesai') return;
        execute($this->db,'INSERT INTO tb_permohonan_feedback (source_table,permohonan_id,respondent_user_id,respondent_role,jenis_layanan,nama_pemohon,nama_subjek,trigger_type) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
            [$s['table'],$r[$s['id_column']],$u['id'],$u['role']==='petugas_desa'?'petugas_registrasi':'pemohon',$s['name'],$r['nama_pemohon']??'',$r[$s['subject']]??'',$trigger]);
    }

    public function feedback(array $u,string $ref,array $input): array
    {
        only($input,['jenis_feedback','rating','service_rating','pesan']);
        try { $f=\permohonan_feedback_validate($input); }
        catch(\InvalidArgumentException $e) { fail(422,'VALIDATION_ERROR',$e->getMessage(),['feedback'=>$e->getMessage()]); }
        return transaction($this->db,function () use ($u,$ref,$f): array {
            [$s,$r]=$this->apps->get($u,$ref,true);
            if ((int)$r['id_user']!==(int)$u['id'] || $s['code']==='peristiwa' || $r['status']!=='selesai') fail(403,'ACTION_FORBIDDEN','Feedback tidak tersedia.');
            $record=one($this->db,'SELECT * FROM tb_permohonan_feedback WHERE source_table=? AND permohonan_id=? AND respondent_role=? AND respondent_user_id=? FOR UPDATE',[$s['table'],$r[$s['id_column']],$u['role']==='petugas_desa'?'petugas_registrasi':'pemohon',$u['id']]);
            if (!$record) fail(409,'FEEDBACK_NOT_READY','Unduh hasil atau konfirmasi penerimaan terlebih dahulu.');
            if ($record['status']!=='pending') return ['saved'=>false,'status'=>$record['status']];
            execute($this->db,"UPDATE tb_permohonan_feedback SET status='submitted',jenis_feedback=?,rating=?,service_rating=?,pesan=?,submitted_at=NOW(),updated_at=NOW() WHERE id=?",[$f['type'],$f['rating'],$f['service_rating'],$f['message'],$record['id']]);
            return ['saved'=>true,'status'=>'submitted'];
        });
    }

    public function notifications(array $u,array $query): array
    {
        only($query,['page','per_page']);[$p,$size,$offset]=page($query);
        $items=rows($this->db,"SELECT id,judul,pesan,tipe,dibaca,created_at,source_table,permohonan_id FROM tb_notifikasi WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT $size OFFSET $offset",[$u['id']]);
        foreach($items as &$n) {
            $n['notification_ref']='notification-'.$n['id'];$n['read']=(bool)$n['dibaca'];$n['created_at']=iso($n['created_at']);$n['application_ref']=null;
            foreach(Catalog::all($u['role']) as $s) if($s['table']===($n['source_table']??null) && !empty($n['permohonan_id'])) $n['application_ref']=$s['code'].'-'.$n['permohonan_id'];
            unset($n['source_table'],$n['permohonan_id'],$n['dibaca']);
        }unset($n);
        return ['items'=>$items,'pagination'=>['page'=>$p,'per_page'=>$size,'total'=>(int)one($this->db,'SELECT COUNT(*) AS n FROM tb_notifikasi WHERE user_id=?',[$u['id']])['n']]];
    }

    public function readNotification(array $u,string $ref,array $input): array
    {
        only($input,['read']);if(($input['read']??null)!==true) fail(422,'VALIDATION_ERROR','read harus true.');
        if(!preg_match('/^notification-([1-9][0-9]{0,9})$/D',$ref,$m) || !one($this->db,'SELECT id FROM tb_notifikasi WHERE id=? AND user_id=?',[$m[1]??0,$u['id']])) fail(404,'NOT_FOUND','Notifikasi tidak ditemukan.');
        execute($this->db,'UPDATE tb_notifikasi SET dibaca=1 WHERE id=? AND user_id=?',[$m[1],$u['id']]);
        return ['notification_ref'=>$ref,'read'=>true];
    }
}
