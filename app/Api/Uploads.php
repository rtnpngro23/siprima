<?php
declare(strict_types=1);
namespace Siprima\Api;

require_once __DIR__.'/../../public/user/upload_compat.php';
require_once __DIR__.'/ImageCompression.php';

final class Uploads
{
    // Single source of truth for the limits advertised by Catalog and enforced here, so the
    // contract the Android client reads can never drift from what validateFile() accepts.
    // Images are re-encoded by ImageCompression, so what is accepted on input is deliberately
    // larger than what is stored: a phone camera photo is 3-8 MB and rejecting it outright
    // pushed the resizing work onto the citizen. The PDF path has no processor, so it keeps
    // the website's own 1 MB per-file limit.
    public const MAX_IMAGE_BYTES = ImageCompression::MAX_INPUT_BYTES;
    public const MAX_STORED_IMAGE_BYTES = ImageCompression::TARGET_BYTES - 1;
    public const MAX_IMAGE_PIXELS = ImageCompression::MAX_PIXELS;
    public const MAX_IMAGE_DIMENSION = ImageCompression::MAX_DIMENSION;
    public const MAX_PDF_BYTES = 1048576;
    public const MIME_TYPES = ['image/jpeg','image/png'];

    public function __construct(private \mysqli $db) {}

    private static function limitLabel(int $bytes): string
    { return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', '.'), '0'), ',') . ' MB'; }

    public static function root(): string { return __DIR__.'/../../public/user/uploads/mobile'; }

    public static function directory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('Upload storage unavailable');
        $deny=$dir.'/.htaccess';
        if (!is_file($deny) && file_put_contents($deny,"Require all denied\nOptions -Indexes\n",LOCK_EX)===false) throw new \RuntimeException('Cannot protect upload directory');
    }

    public static function validateFile(array $f, bool $pdf = false): array
    {
        foreach (['error','tmp_name','name','size'] as $key) if (!isset($f[$key]) || is_array($f[$key])) fail(422,'INVALID_UPLOAD','Berkas unggahan tidak valid.',['file'=>'Unggah satu berkas.']);
        if ((int)$f['error']!==UPLOAD_ERR_OK) fail(422,'INVALID_UPLOAD',\siprima_upload_error_message($f['error']),['file'=>'Unggahan tidak lengkap.']);
        $mime='';$ext='';
        if (!$pdf) {
            $error=\siprima_validate_uploaded_image($f['tmp_name'],$f['name'],$f['size'],self::MAX_IMAGE_BYTES,$mime,$ext);
            if ($error!=='') fail(422,'INVALID_UPLOAD','Berkas harus JPG/PNG valid maksimal '.self::limitLabel(self::MAX_IMAGE_BYTES).'.',['file'=>'Periksa isi, ukuran, dan format gambar.']);
            $original=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
            if (($ext==='jpg' && !in_array($original,['jpg','jpeg','jfif','jpe'],true)) || ($ext==='png' && $original!=='png')) fail(422,'INVALID_UPLOAD','Ekstensi tidak sesuai isi berkas.');
        } else {
            if (!is_uploaded_file($f['tmp_name']) || !is_readable($f['tmp_name']) || filesize($f['tmp_name'])!==(int)$f['size'] || (int)$f['size']<5 || (int)$f['size']>self::MAX_PDF_BYTES
                || strtolower(pathinfo($f['name'],PATHINFO_EXTENSION))!=='pdf' || file_get_contents($f['tmp_name'],false,null,0,5)!=='%PDF-'
                || (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])!=='application/pdf') fail(422,'INVALID_UPLOAD','Surat tugas harus PDF valid maksimal '.self::limitLabel(self::MAX_PDF_BYTES).'.');
            $mime='application/pdf';$ext='pdf';
        }
        return [$mime,$ext];
    }

    public function create(array $u, array $input, array $files): array
    {
        only($input,['service_code','doc_type']); only($files,['file']);
        Catalog::guard($this->db);
        $service=Catalog::get(text($input,'service_code',32),$u['role']);
        if (!$service['can_create']) fail(403,'ACTION_FORBIDDEN','Kanal ini tidak membuat laporan peristiwa baru.');
        $type=text($input,'doc_type',64);
        if (!in_array($type,array_merge($service['required_documents'],$service['optional_documents']),true)) fail(422,'VALIDATION_ERROR','Jenis dokumen tidak sesuai katalog.',['doc_type'=>'Pilih jenis dokumen layanan.']);
        $f=$files['file']??[]; [$mime,$ext]=self::validateFile($f);
        $processed=ImageCompression::process($f['tmp_name'],$mime);
        $id=bin2hex(random_bytes(16)); $name=$id.'.'.$ext; self::directory(self::root());
        // Serialize quota checks per account. Keep permanent files in place on attachment so
        // SQL rollback leaves a valid temporary upload and never a half-moved document.
        return transaction($this->db,function () use ($u,$service,$type,$f,$id,$name,$mime,$processed): array {
            one($this->db,'SELECT id FROM tb_users WHERE id=? FOR UPDATE',[$u['id']]);
            $count=(int)one($this->db,'SELECT COUNT(*) AS n FROM tb_mobile_uploads WHERE user_id=? AND application_ref IS NULL',[$u['id']])['n'];
            if ($count>=50) fail(429,'UPLOAD_QUOTA','Batas 50 unggahan sementara tercapai. Tunggu pembersihan unggahan kedaluwarsa.');
            $path=self::root().'/'.$name;
            $created=false;
            try {
                // Persist only the re-encoded output, never the original multi-megabyte upload.
                $stream=fopen($path,'xb');
                if (!$stream) throw new \RuntimeException('Image storage unavailable');
                $created=true;
                try {
                    if (fwrite($stream,$processed['bytes'])!==$processed['file_size'] || !fflush($stream)) throw new \RuntimeException('Image write failed');
                } finally { fclose($stream); }
                $expiry=time()+86400;
                insert($this->db,'tb_mobile_uploads',['id'=>$id,'user_id'=>$u['id'],'service_code'=>$service['code'],'doc_type'=>$type,
                    'original_name'=>mb_substr(basename(str_replace('\\','/',$f['name'])),0,200),'stored_name'=>$name,'mime_type'=>$mime,'file_size'=>$processed['file_size'],'created_at'=>time(),'expires_at'=>$expiry]);
                return ['upload_ref'=>$id,'service_code'=>$service['code'],'doc_type'=>$type,'mime_type'=>$mime,
                    'file_size'=>$processed['file_size'],'original_file_size'=>$processed['original_file_size'],
                    'width'=>$processed['width'],'height'=>$processed['height'],'expires_at'=>date(DATE_ATOM,$expiry)];
            } catch (\Throwable $e) { if ($created && is_file($path)) unlink($path); throw $e; }
        });
    }

    public function attach(array $ids, array $u, array $service, string $ref, array $existing = []): array
    {
        if (!array_is_list($ids) || count($ids)>30 || count(array_unique($ids,SORT_REGULAR))!==count($ids)) fail(422,'VALIDATION_ERROR','Daftar unggahan tidak valid.',['uploads'=>'Maksimal 30 referensi unik.']);
        $added=[];
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) fail(422,'INVALID_UPLOAD','Referensi unggahan tidak valid.');
            $f=one($this->db,'SELECT * FROM tb_mobile_uploads WHERE id=? AND user_id=? FOR UPDATE',[$id,$u['id']]);
            if (!$f || $f['service_code']!==$service['code'] || $f['application_ref']!==null || (int)$f['expires_at']<=time() || !is_file(self::root().'/'.$f['stored_name'])) fail(422,'INVALID_UPLOAD','Unggahan tidak tersedia, telah digunakan, atau kedaluwarsa.');
            if (!in_array($f['doc_type'],array_merge($service['required_documents'],$service['optional_documents']),true)) fail(422,'INVALID_UPLOAD','Jenis unggahan tidak sesuai persona.');
            $added[]=['path'=>'uploads/mobile/'.$f['stored_name'],'type'=>$f['doc_type'],'original_name'=>$f['original_name'],'uploaded_name'=>$f['stored_name'],'size'=>(int)$f['file_size'],'uploaded_at'=>date('Y-m-d H:i:s')];
            execute($this->db,'UPDATE tb_mobile_uploads SET application_ref=? WHERE id=?',[$ref,$id]);
        }
        // A replacement replaces all old pages of that document type; unaffected types stay.
        $types=array_column($added,'type');
        $docs=array_values(array_filter($existing,static fn($d)=>is_array($d) && !in_array($d['type']??'',$types,true)));
        $docs=array_merge($docs,$added); $present=array_column($docs,'type');
        $missing=array_diff($service['required_documents'],$present);
        foreach ($service['required_document_groups'] as $group) if (!array_intersect($group,$present)) $missing[]=implode(' atau ',$group);
        if ($missing) fail(422,'MISSING_DOCUMENTS','Berkas persyaratan belum lengkap.',['uploads'=>'Lengkapi: '.implode(', ',$missing)]);
        return $docs;
    }
}
