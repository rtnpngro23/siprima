<?php
declare(strict_types=1);
namespace Siprima\Api;

require_once __DIR__.'/Core.php';
require_once __DIR__.'/Catalog.php';
require_once __DIR__.'/Uploads.php';
require_once __DIR__.'/../../public/functions/notifikasi.php';

final class Maintenance
{
    public static function outbox(\mysqli $db,int $limit = 100): int
    {
        $done=0;
        for($i=0;$i<$limit;$i++) {
            $processed=transaction($db,static function () use ($db): bool {
                $event=one($db,'SELECT * FROM tb_mobile_outbox WHERE delivered_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE');
                if (!$event) return false;
                $service=null;foreach(Catalog::all('user') as $s) if($s['table']===$event['source_table']) $service=$s;
                if (!$service) throw new \RuntimeException('Unexpected outbox source');
                $r=one($db,"SELECT * FROM `{$service['table']}` WHERE `{$service['id_column']}`=?",[$event['permohonan_id']]);
                if ($r) {
                    $title=match($event['event_type']) {'created'=>'Pengajuan baru','resubmitted'=>'Pengajuan diperbaiki','comment'=>'Komentar pemohon','receipt_missing'=>'Dokumen belum diterima',default=>'Status laporan peristiwa berubah'};
                    $message=$service['name'].': '.$title.'. Silakan periksa detail permohonan.';
                    if ($service['code']==='peristiwa') {
                        $recipients=$event['event_type']==='status_changed'?[['id'=>$r['id_user']]]
                            :rows($db,"SELECT id FROM tb_users WHERE role='petugas_desa' AND is_active=1 AND id_desa=? AND id_kecamatan=?",[$r['id_desa'],$r['id_kecamatan']]);
                    } else $recipients=rows($db,"SELECT DISTINCT u.id FROM tb_users u JOIN tb_operator_kecamatan o ON o.operator_id=u.id WHERE u.role='operator' AND u.is_active=1 AND o.kecamatan_id=?",[$r['id_kecamatan']]);
                    foreach($recipients as $recipient) {
                        if (!\buatNotifikasi($db,(int)$recipient['id'],$title,$message,'info',null,$service['table'],(int)$event['permohonan_id'])) throw new \RuntimeException('Notification insert failed');
                    }
                }
                execute($db,'UPDATE tb_mobile_outbox SET delivered_at=? WHERE id=?',[time(),$event['id']]);
                return true;
            });
            if (!$processed) break;$done++;
        }
        return $done;
    }

    public static function cleanup(\mysqli $db): int
    {
        $removed=0;
        // Only expired, unattached mobile uploads; never recurse through legacy uploads.
        foreach(rows($db,'SELECT id FROM tb_mobile_uploads WHERE application_ref IS NULL AND expires_at<=? LIMIT 500',[time()]) as $candidate) {
            $removed+=transaction($db,static function () use ($db,$candidate): int {
                $f=one($db,'SELECT * FROM tb_mobile_uploads WHERE id=? FOR UPDATE',[$candidate['id']]);
                if (!$f || $f['application_ref']!==null || (int)$f['expires_at']>time()) return 0;
                if (!preg_match('/^[a-f0-9]{32}\.(jpg|png)$/D',$f['stored_name'])) throw new \RuntimeException('Unsafe stored filename');
                $path=Uploads::root().'/'.$f['stored_name'];
                if (is_link($path)) throw new \RuntimeException('Unexpected symlink');
                if (is_file($path) && !unlink($path)) throw new \RuntimeException('Expired file cleanup failed');
                execute($db,'DELETE FROM tb_mobile_uploads WHERE id=?',[$f['id']]);return 1;
            });
        }
        // Recover the filesystem-before-SQL crash window. Only random mobile filenames
        // older than 48 hours with no metadata row qualify; attached/legacy files stay.
        $root=realpath(Uploads::root());$scanned=0;
        if ($root!==false) foreach(new \DirectoryIterator($root) as $file) {
            if ($file->isDot() || $file->isLink() || !$file->isFile()) continue;
            if (!preg_match('/^[a-f0-9]{32}\.(jpg|png)$/D',$file->getFilename()) || $file->getMTime()>time()-172800) continue;
            if (++$scanned>500) break;
            if (one($db,'SELECT id FROM tb_mobile_uploads WHERE stored_name=? LIMIT 1',[$file->getFilename()])) continue;
            $candidate=realpath($file->getPathname());
            if (!$candidate || dirname($candidate)!==$root) throw new \RuntimeException('Unexpected mobile storage path');
            if (!unlink($candidate)) throw new \RuntimeException('Orphan cleanup failed');
            $removed++;
        }
        execute($db,'DELETE FROM tb_mobile_used_refresh WHERE expires_at<=?',[time()]);
        execute($db,'DELETE FROM tb_mobile_sessions WHERE expires_at<=?',[time()]);
        execute($db,'DELETE FROM tb_mobile_rate_limits WHERE expires_at<=?',[time()]);
        // Idempotency tombstones are retained: deleting them could make an old retry create a duplicate.
        return $removed;
    }
}
