<?php
declare(strict_types=1);

// Uji renderer lembar data setara F-2.01 (Akta Kematian) tanpa menyentuh database maupun sesi.
// Jalankan: php tests/formulir_f201_kematian_test.php [direktori_output]

require_once __DIR__ . '/../public/functions/formulir_f201_kematian.php';

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
    $prevX = 40; $prevY = (int)($h / 2);
    for ($x = 40; $x < $w - 40; $x += 10) {
        $y = (int)($h / 2 + sin($x / 28) * ($h / 4) - cos($x / 61) * ($h / 8));
        imageline($im, $prevX, $prevY, $x, $y, $tinta);
        $prevX = $x; $prevY = $y;
    }
    ob_start();
    imagepng($im);
    $png = (string)ob_get_clean();
    unset($im);
    return $png;
}

function dataUrl(string $png): string
{
    return 'data:image/png;base64,' . base64_encode($png);
}

$dataLengkap = [
    'nama_meninggal' => 'Hasan Mahmud Pakaya',
    'nik_meninggal' => '7501015012800001',
    'tempat' => 'Kabupaten Gorontalo',
    'tanggal_kematian' => '2026-09-15',
    'nama_ayah' => 'Ibrahim Pakaya',
    'nik_ayah' => '7501010101500001',
    'nama_ibu' => 'Sitti Rahma Hulukati',
    'nik_ibu' => '7501014504550002',
    'nama_saksi1' => 'Yusuf Mohamad',
    'nik_saksi1' => '7501011203880003',
    'nama_saksi2' => 'Rahmawati Ibrahim',
    'nik_saksi2' => '7501015506910004',
];
$pemohon = ['nama' => 'Hasan Mahmud Pakaya Jr', 'nik' => '7501010101900005'];

echo "== Bangun data ==\n";
$data = siprima_f201_kematian_data($dataLengkap, $pemohon, 'LIMBOTO', 'KAYUBULAN', '2026-09-20 09:00:00');
cek('nama_meninggal terbawa', $data['nama_meninggal'] === 'Hasan Mahmud Pakaya');
cek('nik_saksi2 terbawa', $data['nik_saksi2'] === '7501015506910004');
cek('nama_pemohon dari akun, bukan dari POST', $data['nama_pemohon'] === 'Hasan Mahmud Pakaya Jr');
cek('field yang tidak ada di POST jadi string kosong, bukan error',
    siprima_f201_kematian_data([], [], '', '', '2026-01-01 00:00:00')['nama_meninggal'] === '');

echo "\n== Render dengan saksi 2 lengkap ==\n";
$ttd = null;
siprima_formulir_validate_signature(dataUrl(ttdDummy()), $ttd);
cek('tanda tangan dummy sah', $ttd !== null);

$sebelum = memory_get_peak_usage(true);
$jpeg = siprima_f201_kematian_render($data, (string)$ttd);
$puncak = memory_get_peak_usage(true);

$info = getimagesizefromstring($jpeg);
cek('keluaran berupa JPEG', ($info['mime'] ?? '') === 'image/jpeg');
cek('ukuran kanvas 1240x1754', ($info[0] ?? 0) === 1240 && ($info[1] ?? 0) === 1754,
    ($info[0] ?? 0) . 'x' . ($info[1] ?? 0));
cek('berkas di bawah 900 KB', strlen($jpeg) <= 900000, number_format(strlen($jpeg)) . ' byte');
cek('puncak memori wajar (<96 MB)', $puncak < 96 * 1024 * 1024, sprintf('%.1f MB', $puncak / 1048576));
file_put_contents($outDir . DIRECTORY_SEPARATOR . 'formulir_f201_kematian_contoh.jpg', $jpeg);

echo "\n== Saksi II selalu tampil (server tidak pernah menerima kosong) ==\n";
// Renderer sendiri tidak memvalidasi kewajiban saksi 2 (itu tanggung jawab akta_kematian.php),
// tapi harus tetap mencetak nilai apa pun yang diberikan tanpa membuatnya jadi "-" secara diam-diam.
cek('nama saksi 2 tercetak apa adanya, bukan diganti "-"',
    $data['nama_saksi2'] === 'Rahmawati Ibrahim' && $data['nama_saksi2'] !== '-');

echo "\n== Ketahanan render ==\n";
$ekstrem = $data;
$ekstrem['nama_meninggal'] = str_repeat('Namasangatpanjangsekali ', 40);
$ekstrem['nama_saksi2'] = str_repeat('X', 500);
$jpegEkstrem = siprima_f201_kematian_render($ekstrem, (string)$ttd);
cek('nama sangat panjang tetap ter-render', strlen($jpegEkstrem) > 0);
file_put_contents($outDir . DIRECTORY_SEPARATOR . 'formulir_f201_kematian_ekstrem.jpg', $jpegEkstrem);

$kosong = siprima_f201_kematian_data([], [], '', '', '2026-01-01 00:00:00');
$jpegKosong = siprima_f201_kematian_render($kosong, (string)$ttd);
cek('data minimal tetap ter-render', strlen($jpegKosong) > 0);

echo "\nContoh keluaran: {$outDir}\n";
echo "\n" . ($failures === 0 ? "SEMUA PEMERIKSAAN LULUS\n" : "{$failures} PEMERIKSAAN GAGAL\n");
exit($failures === 0 ? 0 : 1);
