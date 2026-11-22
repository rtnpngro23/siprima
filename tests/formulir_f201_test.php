<?php
declare(strict_types=1);

// Uji renderer lembar data setara F-2.01 tanpa menyentuh database maupun sesi.
// Jalankan: php tests/formulir_f201_test.php [direktori_output]

require_once __DIR__ . '/../public/functions/formulir_f201.php';

$outDir = rtrim($argv[1] ?? sys_get_temp_dir(), '/\\');
$failures = 0;

function cek(string $judul, bool $lulus, string $catatan = ''): void
{
    global $failures;
    if (!$lulus) $failures++;
    printf("  [%s] %s%s\n", $lulus ? ' OK ' : 'GAGAL', $judul, $catatan === '' ? '' : ' — ' . $catatan);
}

/** Tanda tangan sintetis: coretan gelap di atas kanvas transparan. */
function ttdDummy(int $w = 600, int $h = 200, bool $kosong = false): string
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    if (!$kosong) {
        imagealphablending($im, true);
        imagesetthickness($im, 6);
        $tinta = imagecolorallocate($im, 20, 30, 90);
        $prevX = 40;
        $prevY = (int)($h / 2);
        for ($x = 40; $x < $w - 40; $x += 10) {
            $y = (int)($h / 2 + sin($x / 28) * ($h / 4) - cos($x / 61) * ($h / 8));
            imageline($im, $prevX, $prevY, $x, $y, $tinta);
            $prevX = $x;
            $prevY = $y;
        }
    }
    ob_start();
    imagepng($im);
    $png = (string)ob_get_clean();
    imagedestroy($im);
    return $png;
}

function dataUrl(string $png): string
{
    return 'data:image/png;base64,' . base64_encode($png);
}

echo "== Validasi tanda tangan ==\n";

$bin = null;
cek('tanda tangan wajar diterima', siprima_formulir_validate_signature(dataUrl(ttdDummy()), $bin) === '' && $bin !== null);

$bin = null;
cek('string kosong ditolak', siprima_formulir_validate_signature('', $bin) !== '' && $bin === null);

$bin = null;
cek('bukan data URL PNG ditolak', siprima_formulir_validate_signature('data:image/jpeg;base64,AAAA', $bin) !== '');

$bin = null;
cek('base64 dengan karakter sampah ditolak', siprima_formulir_validate_signature('data:image/png;base64,AA#$%AA', $bin) !== '');

$bin = null;
$besar = 'data:image/png;base64,' . str_repeat('A', 400001);
$mulai = microtime(true);
$pesanBesar = siprima_formulir_validate_signature($besar, $bin);
cek('payload > 400 KB ditolak sebelum decode', $pesanBesar !== '' && (microtime(true) - $mulai) < 0.05,
    sprintf('%.4f detik', microtime(true) - $mulai));

$bin = null;
cek('kanvas kosong ditolak', siprima_formulir_validate_signature(dataUrl(ttdDummy(600, 200, true)), $bin) !== '');

$bin = null;
cek('dimensi di luar batas ditolak', siprima_formulir_validate_signature(dataUrl(ttdDummy(2400, 200)), $bin) !== '');

// PNG bomb: header mendeklarasikan 30000x30000 (~3,6 GB bila di-decode).
$bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 30000, 30000) . "\x08\x06\x00\x00\x00" . pack('N', 0);
$bin = null;
$puncakSebelum = memory_get_peak_usage(true);
cek('PNG bomb 30000x30000 ditolak', siprima_formulir_validate_signature(dataUrl($bomb), $bin) !== '');
cek('memori tidak melonjak saat PNG bomb', memory_get_peak_usage(true) - $puncakSebelum < 8 * 1024 * 1024,
    sprintf('+%.1f MB', (memory_get_peak_usage(true) - $puncakSebelum) / 1048576));

echo "\n== Validasi isian baru ==\n";

$sah = ['jenis_kelahiran' => '1', 'penolong_kelahiran' => '2', 'berat_bayi' => '3,2', 'panjang_bayi' => '48,5'];

$f = null;
cek('isian wajar diterima', siprima_f201_validate_fields($sah, $f) === []);
cek('koma dinormalisasi ke gram', ($f['berat_bayi_gram'] ?? 0) === 3200, (string)($f['berat_bayi_gram'] ?? 0));
cek('titik dinormalisasi ke milimeter',
    siprima_f201_validate_fields(['jenis_kelahiran' => '1', 'penolong_kelahiran' => '2', 'berat_bayi' => '3.2', 'panjang_bayi' => '48.5'], $f) === []
    && ($f['panjang_bayi_mm'] ?? 0) === 485, (string)($f['panjang_bayi_mm'] ?? 0));

$f = null;
cek('jenis_kelahiran di luar daftar ditolak', siprima_f201_validate_fields(['jenis_kelahiran' => '99'] + $sah, $f) !== []);
$f = null;
cek('penolong_kelahiran di luar daftar ditolak', siprima_f201_validate_fields(['penolong_kelahiran' => '7'] + $sah, $f) !== []);
$f = null;
cek('berat di luar rentang ditolak', siprima_f201_validate_fields(['berat_bayi' => '0,1'] + $sah, $f) !== []);
$f = null;
cek('panjang di luar rentang ditolak', siprima_f201_validate_fields(['panjang_bayi' => '150'] + $sah, $f) !== []);
$f = null;
cek('isian non-angka ditolak, bukan dijadikan 0',
    siprima_f201_validate_fields(['berat_bayi' => "3' OR 1=1--"] + $sah, $f) !== [] && ($f['berat_bayi_gram'] ?? null) === null);
$f = null;
cek('isian kosong ditolak', count(siprima_f201_validate_fields([], $f)) === 4);

echo "\n== Render dokumen ==\n";

$data = [
    'nama_kecamatan' => 'Limboto',
    'nama_desa' => 'Kayubulan',
    'nama_bayi' => 'Muhammad Rafi Alfarizi Pakaya',
    'tempat_lahir' => 'Kabupaten Gorontalo',
    'tanggal_lahir' => '2026-02-03',
    'waktu' => '04:15:00',
    'jenis_kelamin' => 'L',
    'jenis_kelahiran' => 1,
    'urutan_kelahiran' => 2,
    'penolong_kelahiran' => 2,
    'berat_bayi_gram' => 3200,
    'panjang_bayi_mm' => 485,
    'nama_ayah' => 'Abdul Rahman Pakaya',
    'nik_ayah' => '7501010101900001',
    'nama_ibu' => 'Siti Aminah Hulukati',
    'nik_ibu' => '7501014504920002',
    'nama_pemohon' => 'Abdul Rahman Pakaya',
    'nik_pemohon' => '7501010101900001',
    'nama_saksi1' => 'Yusuf Mohamad',
    'nik_saksi1' => '7501011203880003',
    'nama_saksi2' => 'Rahmawati Ibrahim',
    'nik_saksi2' => '7501015506910004',
    'dibuat_at' => '2026-09-19 14:32:00',
];

$bin = null;
siprima_formulir_validate_signature(dataUrl(ttdDummy()), $bin);

$puncakSebelum = memory_get_peak_usage(true);
$jpeg = siprima_f201_render($data, (string)$bin);
$puncak = memory_get_peak_usage(true);

$info = getimagesizefromstring($jpeg);
cek('keluaran berupa JPEG', ($info['mime'] ?? '') === 'image/jpeg');
cek('ukuran kanvas 1240x1754', ($info[0] ?? 0) === 1240 && ($info[1] ?? 0) === 1754,
    ($info[0] ?? 0) . 'x' . ($info[1] ?? 0));
cek('berkas di bawah 900 KB', strlen($jpeg) <= 900000, number_format(strlen($jpeg)) . ' byte');
cek('puncak memori di bawah 96 MB', $puncak < 96 * 1024 * 1024, sprintf('%.1f MB', $puncak / 1048576));

// Nama ekstrem tidak boleh meluap ke luar kolom maupun membuat render gagal.
$ekstrem = $data;
$ekstrem['nama_bayi'] = str_repeat('Namabayisangatpanjangsekali ', 40);
$ekstrem['nama_saksi2'] = str_repeat('X', 500);
$jpegEkstrem = siprima_f201_render($ekstrem, (string)$bin);
cek('nama sangat panjang tetap ter-render', strlen($jpegEkstrem) > 0);

// Field kosong tidak boleh memicu error.
$kosong = ['nama_pemohon' => 'Tanpa Data', 'dibuat_at' => '2026-09-19 08:00:00'];
$jpegKosong = siprima_f201_render($kosong, (string)$bin);
cek('data minimal tetap ter-render', strlen($jpegKosong) > 0);

$target = $outDir . DIRECTORY_SEPARATOR . 'formulir_f201_contoh.jpg';
file_put_contents($target, $jpeg);
file_put_contents($outDir . DIRECTORY_SEPARATOR . 'formulir_f201_ekstrem.jpg', $jpegEkstrem);
echo "\nContoh keluaran: {$target}\n";

echo "\n" . ($failures === 0 ? "SEMUA PEMERIKSAAN LULUS\n" : "{$failures} PEMERIKSAAN GAGAL\n");
exit($failures === 0 ? 0 : 1);
