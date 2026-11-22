<?php
declare(strict_types=1);
namespace Siprima\Api;

require_once __DIR__.'/../config/service_hours.php';
require_once __DIR__.'/Uploads.php';

final class Catalog
{
    public static function all(string $role): array
    {
        $add=$role==='petugas_desa';
        $parent=['nama_ayah','nik_ayah','nama_ibu','nik_ibu','nama_saksi1','nik_saksi1','nama_saksi2','nik_saksi2'];
        $move=['no_kk','nama_kepalaKeluarga','alamat_asal','alamat_tujuan','alasan_pindah','anggotaKeluarga'];
        $defs=[
            'kk'=>['tb_kartukeluarga','Kartu Keluarga','nama_kepalaKeluarga', ['dusun','nama_kepalaKeluarga','anggotaKeluarga'], ['dusun','nama_kepalaKeluarga','anggotaKeluarga'],
                $add?['formulir_f101','kk_lama']:['formulir_f101','kk_lama_atau_surat_kehilangan','foto_selfie'],
                $add?['buku_nikah','ijazah','surat_kerja','lainnya']:['ktp','surat_nikah','akta_lahir','akta_kematian','surat_pindah','lainnya']],
            'kelahiran'=>['tb_akta_kelahiran','Akta Kelahiran','nama_bayi',array_merge(['nama_bayi','tempat_lahir','tanggal_lahir','waktu','jenis_kelamin','urutan_kelahiran'],$parent),
                ['nama_bayi','tempat_lahir','tanggal_lahir','waktu','jenis_kelamin','urutan_kelahiran','nama_ayah','nik_ayah','nama_ibu','nik_ibu','nama_saksi1','nik_saksi1'],
                $add?['formulir_kelahiran','surat_keterangan_lahir','buku_nikah']:['formulir','surat_keterangan','buku_nikah','foto_selfie'], ['kk','suket_kehilangan_kk','ktp_ortu','lainnya']],
            'kematian'=>['tb_akta_kematian','Akta Kematian','nama_meninggal',array_merge(['dusun','nama_meninggal','nik_meninggal','tanggal_kematian','tempat'],$parent),
                array_merge(['dusun','nama_meninggal','nik_meninggal','tanggal_kematian','tempat'], $add?$parent:['nama_ayah','nama_ibu','nama_saksi1','nik_saksi1','nama_saksi2','nik_saksi2']),
                $add?['formulir_kematian','surat_keterangan_kematian','kk_ktp_meninggal']:['formulir_f201','surat_kematian','identitas_meninggal','foto_selfie'],
                $add?['surat_keterangan_hilang','akta_lahir','akta_kawin','ktp_ahli_waris','surat_kuasa','lainnya']:['ktp_ayah','ktp_ibu','ktp_saksi1','ktp_saksi2','lainnya']],
            'pindah'=>['tb_pindah_alamat','Pindah Alamat','nama_kepalaKeluarga',$move,$move,
                $add?['formulir_pindah','foto_pemohon','kk_ktp']:['formulir_f103','kk_ktp_hilang','foto_pemohon'], $add?['buku_nikah','surat_kerja','lainnya']:['buku_nikah','akta_lahir','surat_pindah','surat_kuasa','lainnya']],
            'pindah_online'=>['tb_pindah_online','Pindah Masuk','nama_kepalaKeluarga',$move,$move,['formulir_pindah','kk_ktp','foto_pemohon'],$add?['buku_nikah','surat_kerja','lainnya']:['buku_nikah','lainnya']],
            'peristiwa'=>['tb_laporan_peristiwa','Laporan Peristiwa','nama_subjek',['jenis_peristiwa','nama_subjek','nik_subjek','tempat_lahir_mati','tanggal_lahir_mati','nama_ayah','nama_ibu'],
                ['jenis_peristiwa','nama_subjek','tempat_lahir_mati','tanggal_lahir_mati','nama_ayah','nama_ibu'],['foto_selfie'],['surat_keterangan_lahir','kk','ktp','akta_lahir','akta_kematian','ijazah','buku_nikah','lainnya']],
        ];
        $out=[];
        foreach ($defs as $code=>$d) {
            $required=array_merge(['id_kecamatan','id_desa'],$d[4]);
            if ($code==='kelahiran' || ($add && in_array($code,['kk','kematian','pindah'],true))) $required[]='keterangan';
            if ($add && $code==='kelahiran') $required=array_values(array_diff($required,['waktu','urutan_kelahiran']));
            $out[$code]=['code'=>$code,'table'=>$d[0],'name'=>$d[1],'subject'=>$d[2], 'id_column'=>$code==='peristiwa'?'id_lapor':'id',
                'fields'=>array_merge(['id_kecamatan','id_desa'],$d[3],['keterangan']), 'required_fields'=>$required,
                'required_documents'=>$d[5], 'optional_documents'=>$d[6], 'required_document_groups'=>$code==='kelahiran'?[['kk','suket_kehilangan_kk']]:[],
                'can_create'=>!($add && $code==='peristiwa'), 'max_file_bytes'=>Uploads::MAX_IMAGE_BYTES,
                'max_stored_file_bytes'=>Uploads::MAX_STORED_IMAGE_BYTES,
                'max_image_pixels'=>Uploads::MAX_IMAGE_PIXELS, 'max_image_dimension'=>Uploads::MAX_IMAGE_DIMENSION,
                'mime_types'=>Uploads::MIME_TYPES];
        }
        return $out;
    }

    public static function get(string $code, string $role): array
    { return self::all($role)[$code] ?? fail(404,'SERVICE_NOT_FOUND','Layanan tidak ditemukan.'); }

    public static function metadata(array $service): array
    {
        unset($service['table'],$service['id_column'],$service['subject']);
        $service['field_schema']=self::fieldSchema($service);
        return $service;
    }

    public static function fieldSchema(array $service): array
    {
        $out=[];
        foreach ($service['fields'] as $f) {
            $schema=['type'=>'string','max_length'=>self::maxLength($f),'required'=>in_array($f,$service['required_fields'],true)];
            if (in_array($f,['id_kecamatan','id_desa','urutan_kelahiran'],true)) $schema=['type'=>'integer','minimum'=>1,'required'=>$schema['required']];
            if (str_starts_with($f,'nik_') || $f==='no_kk') $schema['pattern']='^[0-9]{16}$';
            if (str_starts_with($f,'tanggal_')) $schema['format']='date';
            if ($f==='waktu') $schema['pattern']='^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$';
            if ($f==='jenis_kelamin') $schema['enum']=['L','P'];
            if ($f==='jenis_peristiwa') $schema['enum']=['lahir','mati','rentan_adminduk'];
            if ($f==='anggotaKeluarga') $schema=['type'=>'array','min_items'=>1,'max_items'=>50,'required'=>true,'item_fields'=>['nama','nik','hubungan']];
            $out[$f]=$schema;
        }
        return $out;
    }

    private static function maxLength(string $f): int
    { return (str_starts_with($f,'nik_') || $f==='no_kk') ? 16 : (in_array($f,['alamat_asal','alamat_tujuan','alasan_pindah','keterangan'],true)?255:100); }

    public static function validate(array $input, array $service): array
    {
        only($input,$service['fields']); $out=[];
        foreach ($service['fields'] as $field) {
            $required=in_array($field,$service['required_fields'],true);
            if ($field==='anggotaKeluarga') {
                $members=$input[$field]??null;
                if (!is_array($members) || !array_is_list($members) || !$members || count($members)>50) fail(422,'VALIDATION_ERROR','Isi 1–50 anggota keluarga.',[$field=>'Daftar anggota tidak valid.']);
                $out[$field]=[];
                foreach ($members as $i=>$m) {
                    if (!is_array($m)) fail(422,'VALIDATION_ERROR','Anggota tidak valid.',["$field.$i"=>'Objek diperlukan.']);
                    only($m,['nama','nik','hubungan']);
                    $name=text($m,'nama'); $nik=text($m,'nik',16,false); $relation=text($m,'hubungan',100,false);
                    if ($nik!=='' && !preg_match('/^[0-9]{16}$/D',$nik)) fail(422,'VALIDATION_ERROR','NIK harus 16 digit.',["$field.$i.nik"=>'Gunakan 16 digit angka.']);
                    $out[$field][]=['nama'=>$name,'nik'=>$nik,'hubungan'=>$relation];
                }
                continue;
            }
            if (in_array($field,['id_kecamatan','id_desa','urutan_kelahiran'],true)) {
                $out[$field]=!$required && in_array($input[$field]??null,[null,''],true)?null:integer($input[$field]??null,$field); continue;
            }
            $v=text($input,$field,self::maxLength($field),$required);
            if ($v!=='' && (str_starts_with($field,'nik_') || $field==='no_kk') && !preg_match('/^[0-9]{16}$/D',$v)) fail(422,'VALIDATION_ERROR','NIK/KK harus 16 digit.',[$field=>'Gunakan 16 digit angka.']);
            if ($v!=='' && str_starts_with($field,'tanggal_')) {
                $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
                if (!$d || $d->format('Y-m-d')!==$v || (in_array($service['code'],['peristiwa','kematian'],true) && $v>date('Y-m-d'))) fail(422,'VALIDATION_ERROR','Tanggal tidak valid.',[$field=>'Gunakan tanggal yang valid.']);
            }
            if ($v!=='' && $field==='waktu' && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/D',$v)) fail(422,'VALIDATION_ERROR','Waktu tidak valid.',[$field=>'Gunakan HH:MM.']);
            if (($field==='jenis_kelamin' && !in_array($v,['L','P'],true)) || ($field==='jenis_peristiwa' && !in_array($v,['lahir','mati','rentan_adminduk'],true))) fail(422,'VALIDATION_ERROR','Pilihan tidak valid.',[$field=>'Pilih nilai dari katalog.']);
            $out[$field]=$v;
        }
        return $out;
    }

    public static function region(\mysqli $db, array $fields, ?array $user = null): array
    {
        $k=integer($fields['id_kecamatan']??null,'id_kecamatan'); $d=integer($fields['id_desa']??null,'id_desa');
        if ($user && $user['role']==='petugas_desa' && ($k!==(int)$user['id_kecamatan'] || $d!==(int)$user['id_desa'])) fail(403,'REGION_FORBIDDEN','Wilayah harus sesuai penugasan akun.');
        $r=one($db,'SELECT k.nama_kecamatan,d.nama_desa FROM tb_desa d JOIN tb_kecamatan k ON k.id=d.kecamatan_id WHERE d.id=? AND k.id=?',[$d,$k]);
        if (!$r) fail(422,'VALIDATION_ERROR','Desa tidak sesuai kecamatan.',['id_desa'=>'Pilih desa pada kecamatan yang benar.']);
        return $r;
    }

    public static function availability(\mysqli $db): array
    {
        try {
            $record=one($db,'SELECT payload FROM tb_service_settings WHERE id=1');
            $settings=json_decode($record['payload']??'',true,32,JSON_THROW_ON_ERROR); \service_hours_validate($settings);
        } catch (\Throwable $e) { $settings=\service_hours_defaults(); $settings['unavailable']=true; }
        $status=\service_hours_status(null,$settings);
        return $status+['can_create'=>$status['is_open'],'server_time'=>date(DATE_ATOM),'notice'=>\service_hours_event_notice(null,$settings)];
    }

    public static function guard(\mysqli $db): void
    {
        $s=self::availability($db);
        if (!$s['is_open']) fail($s['closed_reason']==='unavailable'?503:403,'SERVICE_CLOSED',\service_hours_closed_message($s));
    }
}
