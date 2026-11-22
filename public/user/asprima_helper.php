<?php
require_once __DIR__ . '/service_tracking.php';
require_once __DIR__ . '/asprima_requirements.php';

function asprima_widget_allowed(array $session, string $page): bool
{
    if (empty($session['id_user']) || !in_array($session['role'] ?? '', ['user', 'petugas_desa'], true)) return false;
    // Explicit allowlist: never overlap controls on status, application, or edit pages.
    return in_array(basename($page), ['dashboard_user.php', 'dashboard_petugas_desa.php',
        'ahlak_dashboard_user.php', 'rs_dashboard_user.php', 'kua_dashboard_user.php',
        'profil.php', 'petugas_desa_profil.php', 'formulir_user.php', 'petugas_desa_formulir.php'], true);
}

function asprima_account(mysqli $db, array $session): array
{
    if (empty($session['id_user']) || !in_array($session['role'] ?? '', ['user', 'petugas_desa'], true)) throw new DomainException('Silakan masuk dengan akun pemohon.');
    $stmt = $db->prepare('SELECT u.id,u.role,u.id_desa,u.id_kecamatan,u.id_kua,u.id_puskes,u.id_rs,d.nama_desa,k.nama_kecamatan FROM tb_users u LEFT JOIN tb_desa d ON d.id=u.id_desa LEFT JOIN tb_kecamatan k ON k.id=u.id_kecamatan WHERE u.id=?');
    if (!$stmt) throw new RuntimeException('Data akun belum dapat diperiksa.');
    $id = (int)$session['id_user']; $stmt->bind_param('i', $id); $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$account || $account['role'] !== $session['role']) throw new DomainException('Akun pemohon tidak dapat diakses. Silakan login ulang.');
    $account['kind'] = $account['role'] === 'petugas_desa' ? 'desa' : (!empty($account['id_rs']) ? 'rs' : (!empty($account['id_kua']) ? 'kua' : (!empty($account['id_puskes']) ? 'ahlak' : 'user')));
    return $account;
}

function asprima_services(array $account): array
{
    $all = [
        'kk'=>['tb_kartukeluarga','Kartu Keluarga','nama_kepalaKeluarga'],
        'kelahiran'=>['tb_akta_kelahiran','Akta Kelahiran','nama_bayi'],
        'kematian'=>['tb_akta_kematian','Akta Kematian','Nama_meninggal'],
        'pindah'=>['tb_pindah_alamat','Pindah Alamat','nama_kepalaKeluarga'],
        'pindah_online'=>['tb_pindah_online','Pindah Masuk / Online','nama_kepalaKeluarga'],
    ];
    return match ($account['kind']) {
        'rs'=>['kelahiran'=>['tb_akta_kelahiran_rs','Akta Kelahiran RS','nama_bayi'], 'kematian'=>['tb_akta_kematian_rs','Akta Kematian RS','nama_meninggal']],
        'kua'=>['kk'=>['tb_kartukeluarga_kua','Kartu Keluarga KUA','nama_kepalakeluarga']],
        'ahlak'=>['kelahiran'=>['tb_akta_kelahiran_ahlak','Akta Kelahiran AHLAK','nama_bayi']],
        default=>$all,
    };
}

function asprima_scope(array $account): array
{
    if ($account['kind'] === 'desa') {
        if (empty($account['id_desa']) || empty($account['id_kecamatan'])) return ['1=0', '', []];
        return ['(id_desa=? OR (nama_desa=? AND nama_kecamatan=? AND nama_desa<>\'\'))', 'iss', [(int)$account['id_desa'], (string)$account['nama_desa'], (string)$account['nama_kecamatan']]];
    }
    if (in_array($account['kind'], ['kua','ahlak'], true)) {
        $column = $account['kind'] === 'kua' ? 'id_kua' : 'id_puskes';
        return ["$column=?", 'i', [(int)$account[$column]]];
    }
    // RS detail pages and general user detail pages are restricted to the submitting account.
    return ['id_user=?', 'i', [(int)$account['id']]];
}

function asprima_query(mysqli $db, string $sql, string $types, array $params): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('Permohonan belum dapat dimuat.');
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new RuntimeException('Permohonan belum dapat dimuat.');
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return $rows;
}

function asprima_time(int $epoch): string
{
    return $epoch > 0 ? (new DateTimeImmutable('@'.$epoch))->setTimezone(new DateTimeZone('Asia/Makassar'))->format('d-m-Y H:i') . ' WITA' : 'Waktu belum tercatat';
}

function asprima_requests(mysqli $db, array $account, int $page): array
{
    [$scope,$types,$params] = asprima_scope($account);
    $queries=[]; $allParams=[]; $allTypes='';
    foreach (asprima_services($account) as $key=>$config) {
        [$table,$label,$title] = $config;
        $queries[] = "SELECT id,status,`$title` AS title,UNIX_TIMESTAMP(created_at) AS created_epoch,'$key' AS service_key FROM `$table` WHERE $scope";
        $allTypes .= $types; $allParams = array_merge($allParams,$params);
    }
    $rows = asprima_query($db, implode(' UNION ALL ', $queries) . ' ORDER BY created_epoch DESC,id DESC,service_key LIMIT 11 OFFSET ' . (max(0,min(10000,$page))*10), $allTypes,$allParams);
    $more=count($rows)>10; $rows=array_slice($rows,0,10); $services=asprima_services($account);
    return ['requests'=>array_map(static fn($row)=>['key'=>$row['service_key'].':'.$row['id'], 'label'=>$services[$row['service_key']][1], 'title'=>$row['title'] ?: '', 'status'=>$row['status'], 'submitted_at'=>asprima_time((int)$row['created_epoch'])],$rows), 'more'=>$more];
}

function asprima_request(mysqli $db, array $account, string $key): array
{
    if (!preg_match('/^([a-z_]+):([1-9][0-9]{0,9})$/D',$key,$match)) throw new DomainException('Permohonan belum terbaca. Silakan pilih lagi.');
    $services=asprima_services($account);
    if (!isset($services[$match[1]])) throw new DomainException('Permohonan tidak tersedia untuk akun ini.');
    [$table,$label,$title]=$services[$match[1]];
    [$scope,$types,$params]=asprima_scope($account);
    $rows=asprima_query($db,"SELECT id,status,alasan,`$title` AS title,UNIX_TIMESTAMP(created_at) AS created_epoch FROM `$table` WHERE id=? AND ($scope)",'i'.$types,array_merge([(int)$match[2]],$params));
    if (!$rows) throw new DomainException('Permohonan ini tidak bisa dibuka dari akun Anda. Silakan pilih permohonan lain.');
    return $rows[0]+['key'=>$key,'table'=>$table,'service_key'=>$match[1],'label'=>$label];
}

function asprima_routes(array $account, ?array $request=null): array
{
    [$directory,$status,$apply] = match ($account['kind']) {
        'desa'=>['user','petugas_desa_status_permohonan.php','petugas_desa_ajukan_permohonan.php'],
        'rs'=>['user_rs','rs_status_permohonan.php','rs_ajukan_permohonan.php'],
        'kua'=>['user_kua','kua_status_permohonan.php','kua_ajukan_permohonan.php'],
        'ahlak'=>['user_ahlak','ahlak_status_permohonan.php','ahlak_ajukan_permohonan.php'],
        default=>['user','status_permohonan.php','ajukan_permohonan.php'],
    };
    $url='../'.$directory.'/'.$status;
    if ($request) $url.='?detail_id='.(int)$request['id'].'&jenis='.rawurlencode($request['service_key']);
    return ['status'=>$url, 'apply'=>'../'.$directory.'/'.$apply];
}

function asprima_topic(string $message): string
{
    $text=function_exists('mb_strtolower') ? mb_strtolower($message,'UTF-8') : strtolower($message);
    if (preg_match('/password|kata sandi|profil|(?:ganti|ubah|mengubah).*email/',$text)) return 'profile';
    if (preg_match('/syarat|persyaratan|berkas.*(?:siap|wajib)|dokumen.*(?:siap|wajib)/',$text)) return 'requirements';
    foreach ([
        'contact'=>'/petugas|klarifikasi|komentar|konsultasi|hubungi/',
        'email'=>'/belum.*(?:email|dokumen)|(?:email|dokumen).*belum/',
        'waiting'=>'/dukcapil asal|persetujuan|menunggu/',
        'rejected'=>'/ditolak|penolakan|perbaiki|ajukan kembali/',
        'upload'=>'/unggah|upload|memasukkan berkas|berkas.*gagal|file|jpg|png|pdf/',
        'requirements'=>'/syarat|persyaratan|dokumen.*(?:siap|wajib)/',
        'closed'=>'/ditutup|tidak tersedia|jam pelayanan|jam kerja|hari libur/',
        'meaning'=>'/arti status|makna status|perbedaan status/',
        'edit'=>'/edit|ubah data|mengubah|salah data/',
        'result'=>'/hasil|selesai|unduh|download/',
        'status'=>'/status|perkembangan|riwayat|progres|proses|terkirim|permohonan saya/',
        'apply'=>'/ajukan|pengajuan|mengajukan|kelahiran|kematian|kartu keluarga|pindah/',
        'profile'=>'/profil|akun|password|kata sandi|email/',
        'greeting'=>'/^(halo|hai|assalam|selamat)/',
    ] as $topic=>$pattern) if (preg_match($pattern,$text)) return $topic;
    return 'unknown';
}

function asprima_answer(array $account, string $topic, ?array $request=null, array $history=[], string $message=''): array
{
    if ($topic==='requirements') return asprima_requirements($account,$message,$request);
    $routes=asprima_routes($account,$request);
    $detail=['label'=>'Buka Status Permohonan','url'=>$routes['status']];
    $apply=['label'=>'Mulai mengajukan','url'=>$routes['apply']];
    $answers=[
        'apply'=>'Pilih menu Ajukan Permohonan, lalu pilih layanan yang Anda butuhkan. Isi data dan masukkan foto berkas yang diminta. Periksa sekali lagi sebelum menekan Kirim. Kalau berhasil, permohonan Anda akan muncul di menu Status Permohonan.',
        'upload'=>'Berkas belum bisa masuk? Cek jenis dan ukuran berkas yang diminta di formulir. Kalau terlalu besar, kecilkan ukurannya, tetapi pastikan tulisannya tetap jelas. Lalu pilih berkasnya lagi. Cek juga koneksi internet dan pesan yang muncul di layar.',
        'meaning'=>'Diajukan berarti permohonan sudah terkirim. Diproses berarti sedang dikerjakan petugas. Ditolak berarti Anda perlu membaca alasan dan mengikuti petunjuk perbaikannya. Selesai berarti pelayanan sudah selesai; buka permohonannya untuk melihat dokumen hasil. Petugas bisa memberi kabar baru meskipun status masih Diproses.',
        'closed'=>'Kalau pengajuan sedang tutup, lihat jadwal buka di halaman Ajukan Permohonan. Permohonan yang sudah Anda kirim tetap tercatat.',
        'profile'=>'Untuk mengganti kontak atau kata sandi, buka menu Profil. Simpan kata sandi dan kode masuk untuk Anda sendiri, ya.',
        'greeting'=>'Halo! Saya ASPRIMA. Mau cek syarat, mengajukan permohonan, atau melihat kabar terbaru? Saya bantu, ya.',
        'unknown'=>'Maaf, saya belum paham pertanyaannya. Coba tulis dengan cara lain, atau pilih bantuan di bawah. Kalau perlu penjelasan dari petugas, buka permohonan Anda lalu tanyakan di sana.',
    ];
    if ($topic==='profile') {
        $profile=match($account['kind']) {
            'desa'=>'../user/petugas_desa_profil.php',
            'rs'=>'../user_rs/rs_profil.php',
            'kua'=>'../user_kua/kua_profil.php',
            'ahlak'=>'../user_ahlak/ahlak_profil.php',
            default=>'../user/profil.php',
        };
        return ['answer'=>$answers['profile'],'source'=>'Panduan penggunaan SIPRIMA','links'=>[['label'=>'Buka Profil','url'=>$profile]],'topic'=>'profile'];
    }
    if (isset($answers[$topic])) return ['answer'=>$answers[$topic], 'source'=>'Panduan penggunaan SIPRIMA', 'links'=>in_array($topic,['apply','requirements','upload','closed'],true)?[$apply]:[$detail], 'topic'=>$topic];
    if (!$request) return ['answer'=>'Pilih dulu permohonan yang ingin Anda cek, ya.', 'needs_request'=>true, 'links'=>[$detail], 'topic'=>$topic];
    $status=strtolower((string)$request['status']);
    $last=$history ? end($history) : null;
    $answer=$request['label'].(!empty($request['title']) ? ' · '.$request['title'] : '')."\nStatus saat ini: ".ucfirst($status).'.';
    $source='Dilihat pada '.asprima_time(time());
    $recordNote=trim((string)($request['alasan'] ?? ''));
    if ($last && !empty($last['id'])) {
        $source='Riwayat pelayanan · '.$last['created_at_formatted'];
        $answer.="\nKabar terakhir: ".$last['created_at_formatted'].'.';
    }
    if ($recordNote !== '') {
        $answer.="\nPesan dari petugas:\n".$recordNote;
        if (!$last || trim((string)($last['alasan'] ?? '')) !== $recordNote) $source='Pesan dari petugas; waktu pesan belum tercatat.';
    } else $answer.="\nBelum ada pesan baru dari petugas. Anda bisa melihat kabar sebelumnya lewat tombol di bawah.";
    $links=[['label'=>'Lihat perkembangan','url'=>$routes['status']]];
    if ($topic==='waiting') $answer.="\nKalau petugas menulis “menunggu Dukcapil asal”, artinya permohonan masih menunggu persetujuan dari Dukcapil daerah asal. Ikuti pesan petugas, ya. Tanggal persetujuannya belum bisa saya pastikan.";
    elseif ($topic==='email') $answer.=$status==='selesai' ? "\nCoba cek kotak masuk dan folder spam email. Buka Status Permohonan untuk memberi tahu apakah email sudah diterima. Jika tombolnya belum bisa dipakai, ikuti waktu tunggu yang tertulis di sana." : "\nPermohonan Anda belum selesai. Lihat dulu pesan terbaru dari petugas, ya.";
    elseif ($topic==='result') {
        $answer.=$status==='selesai' ? "\nBuka permohonan Anda untuk melihat dokumen hasil dan kabar pengiriman email. Jika dokumennya belum tersedia, tanyakan kepada petugas." : "\nPermohonan Anda belum selesai. Cek kabar terbaru lewat tombol di bawah, ya.";
        $links[0]['label']='Lihat Dokumen Hasil';
    } elseif (in_array($topic,['rejected','edit'],true)) {
        if (in_array($status,['diajukan','ditolak'],true)) {
            $answer.="\nBuka permohonannya, lalu pilih Edit & Ajukan Kembali jika tombolnya tersedia. Betulkan data atau berkas sesuai pesan petugas. Setelah diperiksa, kirim lagi.";
            $links[0]['label']='Buka Permohonan untuk Diperbaiki';
        } else $answer.="\nKalau ada data yang salah, beri tahu petugas dulu, ya. Tunggu arahannya sebelum membuat permohonan baru.";
    } elseif ($topic==='contact') $answer.="\nSaya siapkan contoh pertanyaan untuk petugas. Anda bisa mengubahnya dulu, lalu kirim kalau sudah sesuai.";
    else $answer.="\nCek kabar dari petugas lewat tombol di bawah. Kalau status masih Diproses, permohonan masih ditangani. Tanggal selesainya belum bisa saya pastikan.";
    $draft='Boleh saya tahu perkembangan permohonan '.$request['label'].(!empty($request['title']) ? ' atas nama '.$request['title'] : '').'? Apakah ada berkas yang perlu saya lengkapi?';
    if (in_array($account['kind'],['user','desa'],true)) {
        $links[]=['label'=>'Tanyakan kepada Petugas','url'=>$routes['status'].'&asprima_comment=1','draft'=>$draft,'request_key'=>$request['key']];
    } else {
        $answer.="\nAnda juga bisa bertanya lewat WhatsApp layanan. Periksa pesannya dulu sebelum dikirim, ya.";
        $links[]=['label'=>'Tanya lewat WhatsApp','url'=>'https://api.whatsapp.com/send?phone=6281243425557&text='.rawurlencode($draft)];
    }
    return ['answer'=>$answer,'source'=>$source,'links'=>$links,'topic'=>$topic,
        'request'=>['key'=>$request['key'],'label'=>$request['label'],'title'=>$request['title'] ?? '','status'=>$status],
        'suggestions'=>match($status){'ditolak'=>['Apa yang perlu saya perbaiki?','Bagaimana cara memasukkan berkas?'], 'selesai'=>['Di mana dokumen hasil saya?','Dokumen belum masuk email'], default=>['Apa perkembangan terbaru?','Bagaimana bertanya kepada petugas?']}];
}
