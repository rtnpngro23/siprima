<?php
// Keep these lists aligned with requiredDocs in the corresponding application forms.
require_once __DIR__ . '/../../app/config/document_features.php';

function asprima_requirements(array $account, string $message, ?array $request = null): array
{
    $services = asprima_services($account);
    $text = strtolower($message);
    $key = null;
    foreach (['pindah_online'=>'/pindah.*(?:online|masuk)/','pindah'=>'/pindah/','kelahiran'=>'/kelahiran|lahir/','kematian'=>'/kematian|meninggal/','kk'=>'/\bkk\b|kartu keluarga/'] as $candidate=>$pattern) {
        if (preg_match($pattern,$text)) { $key=$candidate; break; }
    }
    $suggestions = array_map(static fn($service)=>'Syarat '.$service[1], array_values($services));
    if ($key !== null && !isset($services[$key])) return ['answer'=>'Layanan itu belum tersedia untuk akun ini. Pilih salah satu layanan di bawah, ya.', 'suggestions'=>$suggestions, 'topic'=>'requirements'];
    if ($key === null && $request) $key=$request['service_key'];
    if ($key === null && count($services) === 1) $key=array_key_first($services);
    if ($key === null) return ['answer'=>'Mau mengurus apa? Pilih jenis layanan di bawah untuk melihat berkas yang perlu disiapkan.', 'suggestions'=>$suggestions, 'topic'=>'requirements'];
    $lists = [
        'kk'=>['Formulir F-1.01 Biodata Keluarga.','KK lama atau surat keterangan kehilangan KK.'],
        'kelahiran'=>['Formulir F-2.01 Kelahiran.','Surat keterangan lahir dari fasilitas kesehatan, desa, atau kelurahan.','Buku nikah, akta kawin, atau Surat Pernyataan Tanggung Jawab Mutlak (SPTJM) Perkawinan.'],
        'kematian'=>['Formulir F-2.01 Kematian.','Surat keterangan kematian dari RS, puskesmas, desa, atau kelurahan.','KK atau KTP orang yang meninggal, atau surat keterangan kehilangan dokumen tersebut.'],
        'pindah'=>['Formulir F-1.03 Pindah Penduduk yang sudah ditandatangani.','KK, KTP, atau surat keterangan kehilangan KK/KTP.','Foto pemohon memegang KK, KTP, atau formulir pindah.'],
        'pindah_online'=>['Formulir F-1.03 Pindah Penduduk.','KK, KTP, atau surat keterangan kehilangan KK/KTP.','Foto pemohon memegang KK, KTP, atau formulir pindah.'],
    ];
    if ($account['kind']==='user') {
        $lists['kk'][]='Foto selfie pemohon.';
        // Jalur pemohon umum: formulir KK dibuat sistem, jadi tidak perlu disiapkan pemohon.
        if (FORMULIR_F101_AUTO_ENABLED) {
            $lists['kk'] = array_values(array_filter(
                $lists['kk'],
                static fn($item) => stripos($item, 'Formulir F-1.01') !== 0
            ));
        }
        // Jalur pemohon umum: formulir akta kelahiran dibuat sistem, jadi tidak perlu disiapkan.
        if (FORMULIR_F201_AUTO_ENABLED) {
            $lists['kelahiran'] = array_values(array_filter(
                $lists['kelahiran'],
                static fn($item) => stripos($item, 'Formulir F-2.01') !== 0
            ));
            $lists['kelahiran'][]='Foto selfie pemohon memegang KTP atau Kartu Keluarga.';
        } else {
            $lists['kelahiran'][]='Foto selfie pemohon memegang formulir.';
        }
        // Jalur pemohon umum: formulir akta kematian dibuat sistem, jadi tidak perlu disiapkan.
        if (FORMULIR_F201_KEMATIAN_AUTO_ENABLED) {
            $lists['kematian'] = array_values(array_filter(
                $lists['kematian'],
                static fn($item) => stripos($item, 'Formulir F-2.01') !== 0
            ));
            $lists['kematian'][]='Foto selfie pemohon memegang KTP atau Kartu Keluarga.';
        } else {
            $lists['kematian'][]='Foto selfie pemohon memegang formulir.';
        }
    }
    if ($account['kind']==='ahlak') $lists['kelahiran'][]='Kartu Keluarga lama.';
    if ($account['kind']==='rs') {
        $lists['kelahiran'][1]='Surat keterangan lahir dari rumah sakit.';
        $lists['kematian'][1]='Surat keterangan kematian dari rumah sakit.';
        $lists['kematian'][2]='KK atau KTP orang yang meninggal.';
    }
    if ($account['kind']==='kua') $lists['kk']=['KK lama suami dan KK lama istri.','KTP suami dan KTP istri.','Formulir F-1.01 Biodata Keluarga halaman 1 dan 2.','Buku nikah.'];
    $answer='Untuk '.$services[$key][1].", siapkan berkas ini, ya:\n\n";
    foreach ($lists[$key] as $i=>$item) $answer.=($i+1).'. '.$item."\n";
    if ($account['kind']==='kua') $answer.="\nJika memilih alamat calon pengantin berbeda, tambahkan Formulir F-1.03 Pindah.\n";
    $answer.="\nPastikan foto berkas jelas dan tulisannya terbaca. Isi data yang diminta di formulir, lalu tambahkan berkas lain jika diperlukan untuk keperluan Anda.";
    return ['answer'=>$answer,'source'=>'Daftar berkas pada formulir '.$services[$key][1], 'links'=>[['label'=>'Mulai mengajukan','url'=>asprima_routes($account)['apply']]],'suggestions'=>$suggestions,'topic'=>'requirements'];
}