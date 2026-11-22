<?php
declare(strict_types=1);
namespace Siprima\Api;

final class Applications
{
    public function __construct(private \mysqli $db) {}

    public static function parse(string $ref, string $role): array
    {
        if (!preg_match('/^([a-z_]+)-([1-9][0-9]{0,9})$/D',$ref,$m)) fail(404,'NOT_FOUND','Permohonan tidak ditemukan.');
        return [Catalog::get($m[1],$role),integer($m[2],'application_ref')];
    }

    public static function scope(array $u, array $s): array
    {
        if ($s['code']==='peristiwa' && $u['role']==='petugas_desa') {
            if (empty($u['id_desa']) || empty($u['id_kecamatan'])) return ['1=0',[]];
            return ['id_desa=? AND id_kecamatan=?',[$u['id_desa'],$u['id_kecamatan']]];
        }
        // Ordinary ADD applications are owned by the filing officer, not all village residents.
        $where='id_user=?';$params=[$u['id']];
        if ($u['role']==='petugas_desa') { $where.=' AND id_desa=? AND id_kecamatan=?'; $params[]=$u['id_desa']??0;$params[]=$u['id_kecamatan']??0; }
        if (in_array($s['code'],['kelahiran','kematian'],true)) $where.=' AND COALESCE(id_rs,0)=0 AND COALESCE(id_puskes,0)=0';
        return [$where,$params];
    }

    public function get(array $u, string $ref, bool $lock = false): array
    {
        [$s,$id]=self::parse($ref,$u['role']); [$where,$params]=self::scope($u,$s);
        $r=one($this->db,"SELECT * FROM `{$s['table']}` WHERE `{$s['id_column']}`=? AND $where".($lock?' FOR UPDATE':''),array_merge([$id],$params));
        if (!$r) fail(404,'NOT_FOUND','Permohonan tidak ditemukan.');
        return [$s,$r];
    }

    public static function permissions(array $u, array $s, array $r, bool $open): array
    {
        $owned=(int)$r['id_user']===(int)$u['id'];
        $editable=$owned && in_array($r['status'],['diajukan','ditolak'],true);
        return ['can_edit'=>$open && $editable,'can_resubmit'=>$open && $owned && $r['status']==='ditolak',
            'can_comment'=>$owned,'can_receive'=>$owned && $s['code']!=='peristiwa' && $r['status']==='selesai',
            'can_process_event'=>$u['role']==='petugas_desa' && $s['code']==='peristiwa'];
    }

    private function summary(array $s, array $r): array
    {
        return ['application_ref'=>$s['code'].'-'.$r[$s['id_column']], 'service_code'=>$s['code'],'service_name'=>$s['name'],
            'subject'=>$r[$s['subject']]??'', 'status'=>$r['status'], 'reason'=>$r['alasan']??null,
            'created_at'=>iso($r['created_at']??null),'updated_at'=>iso($r['updated_at']??null)];
    }

    public static function documents(array $r): array
    {
        $docs=json_decode((string)($r['dokumen']??''),true);
        if (!is_array($docs)) return empty($r['dokumen'])?[]:[['path'=>$r['dokumen'],'type'=>'dokumen','original_name'=>'Dokumen']];
        return isset($docs['path'])?[$docs]:array_values(array_filter($docs,'is_array'));
    }

    public function detail(array $u, string $ref): array
    {
        [$s,$r]=$this->get($u,$ref); $fields=[];
        foreach ($s['fields'] as $f) {
            $v=$r[$f==='waktu'?'Waktu':$f]??null;
            if ($f==='anggotaKeluarga') $v=json_decode((string)$v,true)?:[];
            if (in_array($f,['id_kecamatan','id_desa','urutan_kelahiran'],true) && $v!==null) $v=(int)$v;
            $fields[$f]=$v;
        }
        $attachments=[];
        foreach (self::documents($r) as $d) {
            $docRef='attachment-'.$ref.'-'.substr(hash('sha256',(string)($d['path']??'')),0,24);
            $attachments[]=['document_ref'=>$docRef,'doc_type'=>$d['type']??'dokumen','name'=>$d['original_name']??'Dokumen','download_path'=>'/documents/'.$docRef.'/download'];
        }
        return $this->summary($s,$r)+['fields'=>$fields,'revision'=>revision($r),'attachments'=>$attachments,
            'capabilities'=>self::permissions($u,$s,$r,Catalog::availability($this->db)['is_open'])];
    }

    public function list(array $u, array $query): array
    {
        only($query,['page','per_page','service_code','status','q']); [$p,$size,$offset]=page($query);
        $codes=Catalog::all($u['role']);
        if (isset($query['service_code'])) { $code=text($query,'service_code',32); $codes=[$code=>Catalog::get($code,$u['role'])]; }
        $status=text($query,'status',16,false); $search=text($query,'q',100,false);
        if ($status!=='' && !in_array($status,['diajukan','diproses','selesai','ditolak'],true)) fail(422,'VALIDATION_ERROR','Status tidak valid.');
        $unions=[];$params=[];
        foreach ($codes as $s) {
            [$where,$bind]=self::scope($u,$s);
            if ($status!=='') { $where.=' AND status=?';$bind[]=$status; }
            if ($search!=='') { $where.=" AND `{$s['subject']}` LIKE ?";$bind[]='%'.$search.'%'; }
            $unions[]="SELECT CONCAT('{$s['code']}-',`{$s['id_column']}`) AS application_ref,'{$s['code']}' AS service_code,`{$s['subject']}` AS subject,status,alasan AS reason,created_at,updated_at FROM `{$s['table']}` WHERE $where";
            array_push($params,...$bind);
        }
        $union=implode(' UNION ALL ',$unions);
        $count=(int)one($this->db,"SELECT COUNT(*) AS n FROM ($union) a",$params)['n'];
        $result=rows($this->db,"SELECT * FROM ($union) a ORDER BY created_at DESC,application_ref DESC LIMIT $size OFFSET $offset",$params);
        foreach ($result as &$r) { $r['created_at']=iso($r['created_at']);$r['updated_at']=iso($r['updated_at']); }
        unset($r);
        return ['items'=>$result,'pagination'=>['page'=>$p,'per_page'=>$size,'total'=>$count]];
    }

    private function actorLock(array $actor): void
    {
        one($this->db,'SELECT id FROM tb_users WHERE id=? FOR UPDATE',[$actor['user']['id']]);
        $snapshot=\siprima_auth_snapshot($this->db,(int)$actor['user']['id']);
        $session=one($this->db,'SELECT revoked_at FROM tb_mobile_sessions WHERE id=?',[$actor['session']['id']]);
        if (!$snapshot || !$session || $session['revoked_at']!==null || !hash_equals($actor['session']['fingerprint'],$snapshot['fingerprint'])) fail(401,'SESSION_REVOKED','Hak akses berubah. Silakan login ulang.');
    }

    private function values(array $fields): array
    {
        if (isset($fields['anggotaKeluarga'])) $fields['anggotaKeluarga']=canonical($fields['anggotaKeluarga']);
        if (isset($fields['waktu'])) { $fields['Waktu']=$fields['waktu']===''?null:(strlen($fields['waktu'])===5?$fields['waktu'].':00':$fields['waktu']);unset($fields['waktu']); }
        return $fields;
    }

    private function history(array $s, int $id, array $u, string $status, string $event, string $reason = ''): void
    {
        insert($this->db,'tb_permohonan_status_history',['source_table'=>$s['table'],'permohonan_id'=>$id,'status'=>$status,'alasan'=>$reason,
            'actor_id'=>$u['id'],'actor_role'=>$u['role'],'event_type'=>$event]);
        insert($this->db,'tb_mobile_outbox',['source_table'=>$s['table'],'permohonan_id'=>$id,'event_type'=>$event,'created_at'=>time()]);
    }

    public function create(array $actor, array $input, string $key): array
    {
        only($input,['service_code','fields','uploads']); $u=$actor['user'];
        $s=Catalog::get(text($input,'service_code',32),$u['role']);
        if (!$s['can_create']) fail(403,'ACTION_FORBIDDEN','ADD menangani laporan peristiwa warga melalui daftar wilayah, bukan membuat laporan baru.');
        if (!is_array($input['fields']??null) || !is_array($input['uploads']??null)) fail(422,'VALIDATION_ERROR','fields dan uploads diperlukan.');
        $fields=Catalog::validate($input['fields'],$s);
        return idempotent($this->db,(int)$u['id'],'applications.create',$key,$input,function () use ($actor,$u,$s,$fields,$input): array {
            $this->actorLock($actor); Catalog::guard($this->db);
            $region=Catalog::region($this->db,$fields,$u);
            $values=$this->values($fields)+$region+['id_user'=>$u['id'],'no_hp'=>$u['no_hp'],'email'=>$u['email'],'status'=>'diajukan','dokumen'=>'[]','nama_folder'=>'mobile'];
            $values[$s['code']==='peristiwa'?'nama_pelapor':'nama_pemohon']=$u['nama'];
            $values[$s['code']==='peristiwa'?'nik_pelapor':'nik_pemohon']=$u['nik'];
            $id=insert($this->db,$s['table'],$values);$ref=$s['code'].'-'.$id;
            $docs=(new Uploads($this->db))->attach($input['uploads'],$u,$s,$ref);
            execute($this->db,"UPDATE `{$s['table']}` SET dokumen=? WHERE `{$s['id_column']}`=?",[canonical($docs),$id]);
            $this->history($s,$id,$u,'diajukan','created');
            return ['application_ref'=>$ref,'status'=>'diajukan'];
        });
    }

    public function edit(array $actor, string $ref, array $input, string $expected): array
    {
        only($input,['fields','uploads']);
        if (!is_array($input['fields']??[]) || !is_array($input['uploads']??[])) fail(422,'VALIDATION_ERROR','fields/ uploads tidak valid.');
        return transaction($this->db,function () use ($actor,$ref,$input,$expected): array {
            $this->actorLock($actor);Catalog::guard($this->db);$u=$actor['user'];[$s,$r]=$this->get($u,$ref,true);
            $this->matchRevision($r,$expected);
            if (!self::permissions($u,$s,$r,true)['can_edit']) fail(403,'ACTION_FORBIDDEN','Permohonan tidak dapat diperbaiki.');
            $prior=[];foreach($s['fields'] as $f) $prior[$f]=$r[$f==='waktu'?'Waktu':$f]??'';
            if (isset($prior['anggotaKeluarga'])) $prior['anggotaKeluarga']=json_decode((string)$prior['anggotaKeluarga'],true)?:[];
            $fields=Catalog::validate(array_merge($prior,$input['fields']??[]),$s);$region=Catalog::region($this->db,$fields,$u);
            $docs=(new Uploads($this->db))->attach($input['uploads']??[],$u,$s,$ref,self::documents($r));
            $values=$this->values($fields)+$region+['dokumen'=>canonical($docs),'status'=>'diajukan','alasan'=>'','resubmitted_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')];
            $sets=implode(',',array_map(static fn($k)=>"`$k`=?",array_keys($values)));
            execute($this->db,"UPDATE `{$s['table']}` SET $sets WHERE `{$s['id_column']}`=?",array_merge(array_values($values),[$r[$s['id_column']]]));
            $this->history($s,(int)$r[$s['id_column']],$u,'diajukan','resubmitted');
            return $this->detail($u,$ref);
        });
    }

    private function matchRevision(array $row, string $expected): void
    {
        $expected=trim($expected,'"');
        if (!preg_match('/^[a-f0-9]{64}$/D',$expected)) fail(422,'REVISION_REQUIRED','Kirim header If-Match berisi revision terbaru.');
        if (!hash_equals(revision($row),$expected)) fail(409,'REVISION_CONFLICT','Permohonan telah berubah. Muat ulang sebelum mengirim.');
    }

    public function resubmit(array $actor, string $ref, array $input, string $key, string $expected): array
    {
        only($input,[]);$u=$actor['user'];
        return idempotent($this->db,(int)$u['id'],'applications.resubmit:'.$ref,$key,['revision'=>$expected],function () use ($actor,$u,$ref,$expected): array {
            $this->actorLock($actor); Catalog::guard($this->db); [$s,$r]=$this->get($u,$ref,true);$this->matchRevision($r,$expected);
            if (!self::permissions($u,$s,$r,true)['can_resubmit']) fail(403,'ACTION_FORBIDDEN','Permohonan tidak dapat diajukan kembali.');
            (new Uploads($this->db))->attach([],$u,$s,$ref,self::documents($r));
            execute($this->db,"UPDATE `{$s['table']}` SET status='diajukan',alasan='',resubmitted_at=NOW(),updated_at=NOW() WHERE `{$s['id_column']}`=?",[$r[$s['id_column']]]);
            $this->history($s,(int)$r[$s['id_column']],$u,'diajukan','resubmitted');
            return ['application_ref'=>$ref,'status'=>'diajukan'];
        });
    }

    public function historyList(array $u,string $ref,array $query): array
    {
        only($query,['page','per_page']);[$p,$size,$offset]=page($query);[$s,$r]=$this->get($u,$ref);
        $args=[$s['table'],$r[$s['id_column']]];
        $items=rows($this->db,"SELECT id,status,alasan,actor_role,event_type,created_at FROM tb_permohonan_status_history WHERE source_table=? AND permohonan_id=? ORDER BY created_at,id LIMIT $size OFFSET $offset",$args);
        foreach($items as &$item) $item['created_at']=iso($item['created_at']);unset($item);
        return ['items'=>$items,'pagination'=>['page'=>$p,'per_page'=>$size,'total'=>(int)one($this->db,'SELECT COUNT(*) AS n FROM tb_permohonan_status_history WHERE source_table=? AND permohonan_id=?',$args)['n']]];
    }

    public function processEvent(array $actor,string $ref,array $input,string $expected): array
    {
        only($input,['status','reason']);$status=text($input,'status',16);$reason=text($input,'reason',255,$status==='ditolak');
        if (!in_array($status,['diajukan','diproses','selesai','ditolak'],true)) fail(422,'VALIDATION_ERROR','Status tidak valid.');
        return transaction($this->db,function () use ($actor,$ref,$input,$expected,$status,$reason): array {
            $this->actorLock($actor);$u=$actor['user'];[$s,$r]=$this->get($u,$ref,true);$this->matchRevision($r,$expected);
            if (!self::permissions($u,$s,$r,true)['can_process_event']) fail(403,'ACTION_FORBIDDEN','Aksi khusus laporan peristiwa pada wilayah ADD.');
            if ($status==='diajukan') Catalog::guard($this->db);
            execute($this->db,'UPDATE tb_laporan_peristiwa SET status=?,alasan=?,updated_at=NOW() WHERE id_lapor=?',[$status,$reason,$r['id_lapor']]);
            $this->history($s,(int)$r['id_lapor'],$u,$status,'status_changed',$reason);
            return $this->detail($u,$ref);
        });
    }
}
