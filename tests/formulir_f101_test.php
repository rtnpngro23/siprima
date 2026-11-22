<?php
declare(strict_types=1);

// Uji renderer lembar data setara F-1.01 (Kartu Keluarga) tanpa menyentuh database maupun sesi.
// Jalankan: php tests/formulir_f101_test.php [direktori_output]

require_once __DIR__ . '/../public/functions/formulir_f101.php';

$outDir = rtrim($argv[1] ?? sys_get_temp_dir(), '/\\');
$failures = 0;

function cek(string $judul, bool $lulus, string $catatan = ''): void
{
    global $failures;
    if (!$lulus) $failures++;
    printf("  [%s] %s%s\n", $lulus ? ' OK ' : 'GAGAL', $judul, $catatan === '' ? '' : ' — ' . $catatan);
}

function ttdDummy(int $w = 600, int $h = 200): string
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    imagesetthickness($im, 6);
    $tinta = imagecolorallocate($im, 20, 30, 90);
    $px = 40; $py = (int)($h / 2);
    for ($x = 40; $x < $w - 40; $x += 10) {
        $y = (int)($h / 2 + sin($x / 28) * ($h / 4) - cos($x / 61) * ($h / 8));
        imageline($im, $px, $py, $x, $y, $tinta);
        $px = $x; $py = $y;
    }
    ob_start();
    imagepng($im);
    $png = (string)ob_get_clean();
    unset($im);
    return $png;
}

/** POST sintetis: $n anggota, $terisi field opsional pertama per anggota. */
function postAnggota(int $n, int $terisi): array
{
    $contoh = [
        'jenis_kelamin' => 'Perempuan',
        'tempat_lahir' => 'Kabupaten Gorontalo',
        'tanggal_lahir' => '1990-05-17',
        'agama' => 'Islam',
        'gol_darah' => 'B',
        'pendidikan' => 'SLTA/Sederajat',
        'pekerjaan' => 'Karyawan Swasta',
        'status_kawin' => 'Kawin Tercatat',
    ];
    $post = ['anggota_nama' => [], 'anggota_nik' => [], 'anggota_hubungan' => []];
    foreach (array_keys($contoh) as $k) $post['anggota_' . $k] = [];
    for ($i = 0; $i < $n; $i++) {
        $post['anggota_nama'][] = 'ANGGOTA KE ' . ($i + 1);
        $post['anggota_nik'][] = str_pad((string)(7501000000000000 + $i), 16, '0', STR_PAD_LEFT);
        $post['anggota_hubungan'][] = $i === 0 ? 'Kepala Keluarga' : 'Anak';
        $j = 0;
        foreach ($contoh as $k => $v) {
            $post['anggota_' . $k][] = $j < $terisi ? $v : '';
            $j++;
        }
    }
    return $post;
}

$dataDasar = [
    'dusun' => 'Dusun Barakati',
    'nama_kepalaKeluarga' => 'ABDUL RAHMAN PAKAYA',
    'keterangan' => 'Perbaikan golongan darah dan status perkawinan',
    'jenis_permohonan' => 'Perubahan Data',
];
$pemohon = ['nama' => 'ABDUL RAHMAN PAKAYA', 'nik' => '7501010101900001'];

echo "== Validasi Jenis Permohonan ==\n";
$f = null;
cek('pilihan sah diterima', siprima_f101_validate_fields(['jenis_permohonan' => 'Pisah KK'], $f) === []
    && ($f['jenis_permohonan'] ?? '') === 'Pisah KK');
$f = null;
cek('kosong ditolak', siprima_f101_validate_fields([], $f) !== []);
$f = null;
cek('di luar daftar ditolak, bukan dipaksa default',
    siprima_f101_validate_fields(['jenis_permohonan' => 'Hapus Semua'], $f) !== []
    && ($f['jenis_permohonan'] ?? null) === null);

echo "\n== Validasi anggota ==\n";
$a = null;
cek('anggota wajar diterima', siprima_f101_validate_anggota(postAnggota(3, 8), $a) === [] && count($a) === 3);
cek('hanya key terisi yang ditulis',
    isset($a[0]['gol_darah']) && count($a[0]) === 11, 'jumlah key=' . count($a[0] ?? []));

$a = null;
siprima_f101_validate_anggota(postAnggota(2, 0), $a);
cek('tanpa field opsional hanya 3 key', count($a[0] ?? []) === 3, 'jumlah key=' . count($a[0] ?? []));
cek('tidak ada key kosong yang nyangkut', !array_key_exists('gol_darah', $a[0] ?? ['gol_darah' => 1]));

$a = null;
$p = postAnggota(2, 0);
$p['anggota_nik'][1] = '12345';
cek('NIK 5 digit ditolak', siprima_f101_validate_anggota($p, $a) !== []);

$a = null;
$p = postAnggota(2, 0);
$p['anggota_nik'][1] = '';
cek('NIK kosong tetap diterima', siprima_f101_validate_anggota($p, $a) === [], 'NIK boleh kosong untuk bayi baru');

$a = null;
$p = postAnggota(2, 8);
$p['anggota_agama'][0] = 'Jedi';
cek('agama di luar whitelist ditolak', siprima_f101_validate_anggota($p, $a) !== []);

$a = null;
$p = postAnggota(2, 8);
$p['anggota_tanggal_lahir'][0] = '2999-01-01';
cek('tanggal lahir masa depan ditolak', siprima_f101_validate_anggota($p, $a) !== []);
$a = null;
$p = postAnggota(2, 8);
$p['anggota_tanggal_lahir'][0] = '17-05-1990';
cek('format tanggal salah ditolak', siprima_f101_validate_anggota($p, $a) !== []);

$a = null;
$p = postAnggota(3, 0);
array_pop($p['anggota_nik']);           // simulasi pemotongan max_input_vars
cek('array anggota tidak sama panjang ditolak', siprima_f101_validate_anggota($p, $a) !== [],
    'mencegah data satu orang menempel ke orang lain');

$a = null;
cek('lebih dari 50 anggota ditolak', siprima_f101_validate_anggota(postAnggota(51, 0), $a) !== []);
$a = null;
cek('tepat 50 anggota diterima', siprima_f101_validate_anggota(postAnggota(50, 0), $a) === []);

$a = null;
$p = postAnggota(2, 0);
$p['anggota_nama'][1] = '';
cek('baris tanpa nama diabaikan', siprima_f101_validate_anggota($p, $a) === [] && count($a) === 1);

$a = null;
$p = postAnggota(1, 0);
$p['anggota_nama'][0] = str_repeat('X', 400);
siprima_f101_validate_anggota($p, $a);
cek('nama dipotong ke 100 karakter', mb_strlen($a[0]['nama']) === 100, (string)mb_strlen($a[0]['nama'] ?? ''));

echo "\n== Ukuran JSON ==\n";
$a = null;
siprima_f101_validate_anggota(postAnggota(50, 8), $a);
$json = json_encode($a, JSON_UNESCAPED_UNICODE);
cek('50 anggota penuh di bawah ambang aman', strlen($json) < SIPRIMA_F101_MAX_JSON,
    number_format(strlen($json)) . ' byte dari ' . number_format(SIPRIMA_F101_MAX_JSON));

echo "\n== Paginasi ==\n";
foreach ([[1, 0], [1, 8], [8, 0], [8, 8], [30, 0], [30, 8], [50, 8]] as [$n, $terisi]) {
    $a = null;
    siprima_f101_validate_anggota(postAnggota($n, $terisi), $a);
    $hal = siprima_f101_paginate($a);
    $totalIndeks = array_sum(array_map('count', $hal));
    cek("paginasi {$n} anggota × {$terisi} field", $totalIndeks === count($a) && count($hal) >= 1,
        count($hal) . ' halaman, semua ' . $totalIndeks . ' anggota terdistribusi');
}

echo "\n== Render ==\n";
$ttd = null;
$pesan = siprima_formulir_validate_signature('data:image/png;base64,' . base64_encode(ttdDummy()), $ttd);
cek('tanda tangan dummy sah', $pesan === '' && $ttd !== null);

$puncakTertinggi = 0;
foreach ([[1, 0], [3, 3], [8, 8], [30, 8], [50, 8]] as [$n, $terisi]) {
    $a = null;
    siprima_f101_validate_anggota(postAnggota($n, $terisi), $a);
    $f = null;
    siprima_f101_validate_fields($dataDasar, $f);
    $data = siprima_f101_data_kartu_keluarga($dataDasar, (array)$f, $a, $pemohon,
        'LIMBOTO', 'KAYUBULAN', '2026-09-20 10:15:00');

    $sebelum = memory_get_peak_usage(true);
    $halaman = siprima_f101_render($data, (string)$ttd);
    $puncak = memory_get_peak_usage(true);
    $puncakTertinggi = max($puncakTertinggi, $puncak);

    $semuaJpeg = true;
    $totalByte = 0;
    foreach ($halaman as $jpeg) {
        $info = getimagesizefromstring($jpeg);
        if (($info['mime'] ?? '') !== 'image/jpeg' || $info[0] !== 1240 || $info[1] !== 1754) $semuaJpeg = false;
        $totalByte += strlen($jpeg);
    }
    cek("render {$n} anggota × {$terisi} field", $semuaJpeg && count($halaman) === count(siprima_f101_paginate($a)),
        count($halaman) . ' halaman, ' . number_format($totalByte) . ' byte total');

    if ($n === 3) file_put_contents($outDir . DIRECTORY_SEPARATOR . 'formulir_f101_kecil.jpg', $halaman[0]);
    if ($n === 30) {
        foreach ($halaman as $k => $jpeg) {
            file_put_contents($outDir . DIRECTORY_SEPARATOR . 'formulir_f101_besar_h' . ($k + 1) . '.jpg', $jpeg);
        }
    }
}
cek('puncak memori datar terhadap jumlah anggota', $puncakTertinggi < 96 * 1024 * 1024,
    sprintf('%.1f MB', $puncakTertinggi / 1048576));

// Data minimal tidak boleh memicu error.
$a = null;
siprima_f101_validate_anggota(postAnggota(1, 0), $a);
$halaman = siprima_f101_render(
    siprima_f101_data_kartu_keluarga([], ['jenis_permohonan' => 'Lainnya'], $a, [], '', '', '2026-09-20 08:00:00'),
    (string)$ttd
);
cek('data minimal tetap ter-render', count($halaman) === 1 && strlen($halaman[0]) > 0);

echo "\nContoh keluaran: {$outDir}\n";
echo "\n" . ($failures === 0 ? "SEMUA PEMERIKSAAN LULUS\n" : "{$failures} PEMERIKSAAN GAGAL\n");
exit($failures === 0 ? 0 : 1);
