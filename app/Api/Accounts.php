<?php
declare(strict_types=1);
namespace Siprima\Api;

final class Accounts
{
    public function __construct(private \mysqli $db) {}

    private function fields(array $input, bool $village): array
    {
        $f=[];
        foreach (['nama'=>100,'nik'=>16,'email'=>100,'alamat'=>2000,'no_hp'=>$village?15:20] as $k=>$n) $f[$k]=text($input,$k,$n);
        if (!preg_match('/^[0-9]{16}$/D',$f['nik'])) fail(422,'VALIDATION_ERROR','NIK harus 16 digit.',['nik'=>'Gunakan 16 digit angka.']);
        if (!filter_var($f['email'],FILTER_VALIDATE_EMAIL)) fail(422,'VALIDATION_ERROR','Email tidak valid.',['email'=>'Periksa format email.']);
        if ($village && (!preg_match('/^[0-9]{10,13}$/D',$f['no_hp']) || mb_strlen($f['nama'])<3)) fail(422,'VALIDATION_ERROR','Nama minimal 3 karakter dan nomor HP 10–13 digit.');
        return $f;
    }

    public function register(array $input, array $files, bool $village): array
    {
        $allowed=['nama','nik','email','alamat','no_hp'];
        only($input,array_merge($allowed,$village?['id_kecamatan','id_desa','jabatan','keterangan_jabatan','agree_terms']:['password','confirm_password']));
        $f=$this->fields($input,$village); $path=null;
        if ($village) {
            only($files,['surat_tugas']);
            if (!in_array($input['agree_terms']??null,['on','true',true],true)) fail(422,'VALIDATION_ERROR','Persetujuan pendaftaran diperlukan.',['agree_terms'=>'Persetujuan wajib.']);
            $region=Catalog::region($this->db,$input); $job=text($input,'jabatan',100);
            if ($job==='lainnya') $job=text($input,'keterangan_jabatan',100);
            Uploads::validateFile($files['surat_tugas']??[],true);
            $f+=['kecamatan_id'=>(int)$input['id_kecamatan'],'desa_id'=>(int)$input['id_desa'],'nama_kecamatan'=>$region['nama_kecamatan'],'nama_desa'=>$region['nama_desa'],'jabatan'=>$job,'status'=>'pending','kode_verifikasi'=>strtoupper(bin2hex(random_bytes(4)))];
        } else {
            only($files,[]); $p=password($input);
            if ($e=\siprima_password_error($p)) fail(422,'VALIDATION_ERROR',$e,['password'=>$e]);
            if ($p!==($input['confirm_password']??null)) fail(422,'VALIDATION_ERROR','Konfirmasi password tidak cocok.',['confirm_password'=>'Password berbeda.']);
            $f+=['password'=>password_hash($p,PASSWORD_DEFAULT),'role'=>'user','is_active'=>1];
        }
        // Serialize mobile registrations. Existing database unique constraints remain authoritative.
        $lock='mobile-register-'.substr(hash('sha256',(string)getenv('DB_NAME')),0,32);
        if ((int)one($this->db,'SELECT GET_LOCK(?,5) AS ok',[$lock])['ok']!==1) fail(409,'REGISTRATION_BUSY','Pendaftaran sedang diproses. Coba kembali.');
        try {
            return transaction($this->db,function () use ($village,$files,&$f,&$path): array {
                if (one($this->db,'SELECT id FROM tb_users WHERE nik=? OR LOWER(email)=LOWER(?) LIMIT 1',[$f['nik'],$f['email']])
                    || one($this->db,"SELECT id FROM tb_pendaftaran_petugas WHERE status='pending' AND (nik=? OR LOWER(email)=LOWER(?)) LIMIT 1",[$f['nik'],$f['email']])) fail(409,'ACCOUNT_EXISTS','NIK/email sudah digunakan atau pendaftaran sedang menunggu verifikasi.');
                if ($village) {
                    $dir=__DIR__.'/../../public/uploads/petugas_desa/mobile'; Uploads::directory($dir);
                    $name=bin2hex(random_bytes(16)).'.pdf'; $path=$dir.'/'.$name;
                    if (!move_uploaded_file($files['surat_tugas']['tmp_name'],$path)) throw new \RuntimeException('Registration upload failed');
                    $f['surat_tugas']='uploads/petugas_desa/mobile/'.$name;
                    $id=insert($this->db,'tb_pendaftaran_petugas',$f);
                    return ['registration_id'=>$id,'status'=>'pending','verification_code'=>$f['kode_verifikasi'],'message'=>'Pendaftaran menunggu verifikasi administrator.'];
                }
                $id=insert($this->db,'tb_users',$f);
                return ['user_id'=>$id,'message'=>'Pendaftaran berhasil. Silakan login.'];
            });
        } catch (\Throwable $e) { if ($path && is_file($path)) unlink($path); throw $e; }
        finally { one($this->db,'SELECT RELEASE_LOCK(?) AS released',[$lock]); }
    }

    public function update(array $u, array $input): array
    {
        only($input,['nama','no_hp','email','alamat']);
        if (!$input) fail(422,'VALIDATION_ERROR','Tidak ada perubahan profil.');
        $lock='mobile-register-'.substr(hash('sha256',(string)getenv('DB_NAME')),0,32);
        if ((int)one($this->db,'SELECT GET_LOCK(?,5) AS ok',[$lock])['ok']!==1) fail(409,'PROFILE_BUSY','Profil sedang diperbarui. Coba kembali.');
        try {
            return transaction($this->db,function () use ($input,$u): array {
                $current=one($this->db,'SELECT * FROM tb_users WHERE id=? FOR UPDATE',[$u['id']]);
                if (!$current) fail(401,'SESSION_REVOKED','Akun tidak tersedia.');
                $f=$this->fields(array_merge($current,$input),$current['role']==='petugas_desa');unset($f['nik']);
                if (one($this->db,'SELECT id FROM tb_users WHERE LOWER(email)=LOWER(?) AND id<>? LIMIT 1',[$f['email'],$u['id']])) fail(409,'EMAIL_EXISTS','Email sudah digunakan.');
                execute($this->db,'UPDATE tb_users SET nama=?,no_hp=?,email=?,alamat=?,updated_at=NOW() WHERE id=?',[$f['nama'],$f['no_hp'],$f['email'],$f['alamat'],$u['id']]);
                return Auth::profile(array_merge($current,$f));
            });
        } finally { one($this->db,'SELECT RELEASE_LOCK(?) AS released',[$lock]); }
    }
}
