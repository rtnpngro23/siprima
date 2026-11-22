<?php
declare(strict_types=1);

require_once __DIR__ . '/formulir_render.php';

// Lembar data setara Formulir F-1.01 (Biodata Keluarga) yang digambar sendiri oleh sistem.
//
// Berbeda dari F-2.01, dokumen ini adalah lembar PERUBAHAN: pemohon hanya mengisi field yang ingin
// diubah, dan hanya field terisi itu yang dicetak. Karena jumlah anggota tidak dibatasi bentuknya,
// keluarannya adalah ARRAY berisi byte JPEG — satu per halaman.

const SIPRIMA_F101_MAX_ANGGOTA = 50;

// Batas aman kolom `anggotaKeluarga` bertipe TEXT (65.535 byte). JSON yang terpotong membuat
// SELURUH daftar anggota hilang di semua layar, jadi lebih baik ditolak lebih awal.
const SIPRIMA_F101_MAX_JSON = 60000;

// Ruang yang disediakan di bagian bawah halaman terakhir untuk blok pernyataan + tanda tangan.
const SIPRIMA_F101_TINGGI_TTD = 320;

function siprima_f101_jenis_permohonan_options(): array
{
    return ['Perubahan Data', 'Penambahan Anggota Keluarga', 'KK Hilang/Rusak', 'Pisah KK', 'Lainnya'];
}

// Pilihan yang tampil di dropdown. "Perubahan Data" dan "KK Hilang/Rusak" kini punya kategori layanan
// sendiri (kk_kategori.php) sehingga disembunyikan, kecuali sudah tersimpan di permohonan yang diedit.
// Validator tetap memakai daftar lengkap agar permohonan lama masih bisa diajukan ulang.
function siprima_f101_jenis_permohonan_pilihan(string $terpilih = ''): array
{
    return array_values(array_filter(
        siprima_f101_jenis_permohonan_options(),
        static fn(string $opsi): bool => $opsi === $terpilih || !in_array($opsi, ['Perubahan Data', 'KK Hilang/Rusak'], true)
    ));
}

function siprima_f101_jenis_kelamin_options(): array
{
    return ['Laki-laki', 'Perempuan'];
}

function siprima_f101_gol_darah_options(): array
{
    return ['A', 'B', 'AB', 'O', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Tidak Tahu'];
}

function siprima_f101_agama_options(): array
{
    return ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu',
        'Kepercayaan Terhadap Tuhan YME'];
}

function siprima_f101_status_kawin_options(): array
{
    return ['Belum Kawin', 'Kawin Tercatat', 'Kawin Belum Tercatat',
        'Cerai Hidup Tercatat', 'Cerai Hidup Belum Tercatat',
        'Cerai Mati Tercatat', 'Cerai Mati Belum Tercatat'];
}

function siprima_f101_pendidikan_options(): array
{
    return ['Tidak/Belum Sekolah', 'Belum Tamat SD/Sederajat', 'Tamat SD/Sederajat',
        'SLTP/Sederajat', 'SLTA/Sederajat', 'Diploma I/II', 'Akademi/Diploma III/S. Muda',
        'Diploma IV/Strata I', 'Strata II', 'Strata III'];
}

/**
 * Delapan field opsional per anggota: key JSON => label cetak.
 * Urutan di sini menentukan urutan tampil di dokumen dan di form.
 */
function siprima_f101_field_opsional(): array
{
    return [
        'jenis_kelamin' => 'Jenis Kelamin',
        'tempat_lahir' => 'Tempat Lahir',
        'tanggal_lahir' => 'Tanggal Lahir',
        'agama' => 'Agama',
        'gol_darah' => 'Golongan Darah',
        'pendidikan' => 'Pendidikan Terakhir',
        'pekerjaan' => 'Jenis Pekerjaan',
        'status_kawin' => 'Status Perkawinan',
    ];
}

// Field enum mana memakai daftar pilihan yang mana.
function siprima_f101_enum_sources(): array
{
    return [
        'jenis_kelamin' => 'siprima_f101_jenis_kelamin_options',
        'agama' => 'siprima_f101_agama_options',
        'gol_darah' => 'siprima_f101_gol_darah_options',
        'pendidikan' => 'siprima_f101_pendidikan_options',
        'status_kawin' => 'siprima_f101_status_kawin_options',
    ];
}

/**
 * '' berarti tidak diisi = tidak ada perubahan. Nilai di luar daftar DITOLAK dengan error,
 * tidak pernah dipaksa menjadi nilai default — memaksa diam-diam akan mengubah data warga.
 */
function siprima_f101_enum(string $nilai, array $daftar, string $label, array &$errors): string
{
    $nilai = trim($nilai);
    if ($nilai === '') return '';
    if (!in_array($nilai, $daftar, true)) {
        $errors[] = "Pilihan {$label} tidak dikenali.";
        return '';
    }
    return $nilai;
}

function siprima_f101_bersihkan(string $teks, int $maks): string
{
    $teks = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $teks) ?? $teks);
    return mb_substr($teks, 0, $maks, 'UTF-8');
}

/**
 * Memvalidasi Jenis Permohonan. Mengembalikan daftar pesan error (kosong bila sah).
 */
function siprima_f101_validate_fields(array $post, ?array &$fields): array
{
    $errors = [];
    $fields = ['jenis_permohonan' => null];
    $nilai = trim((string)($post['jenis_permohonan'] ?? ''));
    if ($nilai === '') {
        $errors[] = 'Jenis permohonan wajib dipilih.';
    } else {
        $sah = siprima_f101_enum($nilai, siprima_f101_jenis_permohonan_options(), 'jenis permohonan', $errors);
        if ($sah !== '') $fields['jenis_permohonan'] = $sah;
    }
    return $errors;
}

/**
 * Membangun daftar anggota dari $_POST. Hanya key yang benar-benar terisi yang ditulis, sehingga
 * ada-tidaknya key menjadi penanda "field ini yang ingin diubah".
 * Mengembalikan daftar pesan error; hasilnya ditulis ke $anggota.
 */
function siprima_f101_validate_anggota(array $post, ?array &$anggota): array
{
    $errors = [];
    $anggota = [];

    $nama = $post['anggota_nama'] ?? null;
    if (!is_array($nama)) {
        $errors[] = 'Minimal harus ada satu anggota keluarga.';
        return $errors;
    }

    $kolom = array_merge(['nama', 'nik', 'hubungan'], array_keys(siprima_f101_field_opsional()));
    $jumlah = count($nama);

    // Deteksi pemotongan max_input_vars: PHP memotong per-variabel tanpa pesan apa pun, sehingga
    // array yang panjangnya berbeda akan menempelkan data satu orang ke orang lain.
    foreach ($kolom as $k) {
        $arr = $post['anggota_' . $k] ?? [];
        if (!is_array($arr)) $arr = [];
        if ($arr !== [] && count($arr) !== $jumlah) {
            $errors[] = 'Data anggota keluarga tidak terkirim lengkap. Kurangi jumlah anggota lalu coba lagi.';
            return $errors;
        }
    }

    if ($jumlah > SIPRIMA_F101_MAX_ANGGOTA) {
        $errors[] = 'Anggota keluarga maksimal ' . SIPRIMA_F101_MAX_ANGGOTA . ' orang dalam satu permohonan.';
        return $errors;
    }

    $enumSources = siprima_f101_enum_sources();
    $labels = siprima_f101_field_opsional();

    for ($i = 0; $i < $jumlah; $i++) {
        $namaAnggota = siprima_f101_bersihkan((string)($nama[$i] ?? ''), 100);
        // Baris tanpa nama diabaikan, sama seperti perilaku kk.php sebelumnya.
        if ($namaAnggota === '') continue;

        $nomor = $i + 1;
        $nik = trim((string)($post['anggota_nik'][$i] ?? ''));
        if ($nik !== '' && preg_match('/^[0-9]{16}$/D', $nik) !== 1) {
            $errors[] = "NIK anggota ke-{$nomor} harus 16 digit angka.";
            continue;
        }

        $item = [
            'nama' => $namaAnggota,
            'nik' => $nik,
            'hubungan' => siprima_f101_bersihkan((string)($post['anggota_hubungan'][$i] ?? ''), 50),
        ];

        foreach ($labels as $key => $label) {
            $mentah = (string)($post['anggota_' . $key][$i] ?? '');
            if (trim($mentah) === '') continue;

            if (isset($enumSources[$key])) {
                $nilai = siprima_f101_enum($mentah, ($enumSources[$key])(), "{$label} anggota ke-{$nomor}", $errors);
            } elseif ($key === 'tanggal_lahir') {
                $nilai = trim($mentah);
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $nilai);
                if (!$d || $d->format('Y-m-d') !== $nilai || $nilai > date('Y-m-d') || $nilai < '1900-01-01') {
                    $errors[] = "Tanggal lahir anggota ke-{$nomor} tidak valid.";
                    $nilai = '';
                }
            } else {
                $nilai = siprima_f101_bersihkan($mentah, $key === 'pekerjaan' ? 60 : 50);
            }

            if ($nilai !== '') $item[$key] = $nilai;
        }

        $anggota[] = $item;
    }

    if (!$errors && !$anggota) {
        $errors[] = 'Minimal harus ada satu anggota keluarga.';
    }
    return $errors;
}

// Satu sumber kebenaran susunan data dokumen, dipakai halaman ajuan, edit, dan pratinjau.
function siprima_f101_data_kartu_keluarga(
    array $post,
    array $fields,
    array $anggota,
    array $pemohon,
    string $namaKecamatan,
    string $namaDesa,
    string $dibuatAt
): array {
    return [
        'nama_kecamatan' => $namaKecamatan,
        'nama_desa' => $namaDesa,
        'jenis_permohonan' => (string)($fields['jenis_permohonan'] ?? ''),
        'dusun' => trim((string)($post['dusun'] ?? '')),
        'nama_kepala_keluarga' => trim((string)($post['nama_kepalaKeluarga'] ?? '')),
        'keterangan' => trim((string)($post['keterangan'] ?? '')),
        'nama_pemohon' => (string)($pemohon['nama'] ?? ''),
        'nik_pemohon' => (string)($pemohon['nik'] ?? ''),
        'anggota' => $anggota,
        'dibuat_at' => $dibuatAt,
    ];
}

// Tinggi satu blok anggota, dihitung tanpa GD supaya paginasi murah.
function siprima_f101_tinggi_blok(array $anggota): int
{
    $terisi = 0;
    foreach (array_keys(siprima_f101_field_opsional()) as $key) {
        if (($anggota[$key] ?? '') !== '') $terisi++;
    }
    return 34 + (int)ceil($terisi / 2) * 26 + 18;
}

/**
 * Membagi anggota ke halaman. Mengembalikan array berisi array indeks anggota per halaman.
 * Lintasan pertama ini tidak menyentuh GD sama sekali.
 */
function siprima_f101_paginate(array $daftar): array
{
    $mulaiHal1 = 545;   // setelah kop lengkap + blok DATA PERMOHONAN
    $mulaiHalN = 290;   // setelah kop ringkas
    $batas = 1754 - 70 - 60;
    // Blok pernyataan + tanda tangan: 28+22+46+24+6+118+22 = 266 px dari awal blok sampai baris NIK,
    // ditambah jarak agar baris NIK tetap di atas garis footer (pageH - margin - 42).
    $tinggiTtd = SIPRIMA_F101_TINGGI_TTD;

    $halaman = [];
    $kini = [];
    $y = $mulaiHal1;

    foreach ($daftar as $i => $anggota) {
        $h = siprima_f101_tinggi_blok($anggota);
        if ($kini && $y + $h > $batas) {
            $halaman[] = $kini;
            $kini = [];
            $y = $mulaiHalN;
        }
        $kini[] = $i;
        $y += $h;
    }
    $halaman[] = $kini;

    // Blok pernyataan + tanda tangan harus muat di halaman terakhir; kalau tidak, tambah halaman.
    if ($y + $tinggiTtd > $batas) $halaman[] = [];

    return $halaman;
}

/**
 * Menggambar seluruh halaman dan mengembalikan array byte JPEG (satu elemen per halaman).
 * Satu kanvas dialokasikan pada satu waktu, jadi puncak memori datar berapa pun jumlah anggota.
 *
 * @throws RuntimeException bila GD/font tidak tersedia atau encoding gagal.
 */
function siprima_f101_render(array $data, string $binaryPng): array
{
    $regular = siprima_formulir_font(false);
    $bold = siprima_formulir_font(true);
    if (!is_readable($regular) || !is_readable($bold)) {
        throw new RuntimeException('Berkas font formulir tidak ditemukan.');
    }

    $daftar = is_array($data['anggota'] ?? null) ? array_values($data['anggota']) : [];
    $halaman = siprima_f101_paginate($daftar);
    $total = count($halaman);
    $labels = siprima_f101_field_opsional();
    $dibuatAt = (string)($data['dibuat_at'] ?? date('Y-m-d H:i:s'));

    $pageW = 1240;
    $pageH = 1754;
    $margin = 70;
    $labelX = $margin + 24;

    $keluaran = [];

    foreach ($halaman as $nomorHalaman => $indeks) {
        $im = imagecreatetruecolor($pageW, $pageH);
        if (!$im) throw new RuntimeException('Kanvas formulir gagal dibuat.');

        try {
            $white = imagecolorallocate($im, 255, 255, 255);
            $ink = imagecolorallocate($im, 17, 17, 17);
            $muted = imagecolorallocate($im, 90, 90, 90);
            $line = imagecolorallocate($im, 170, 170, 170);
            $bandBg = imagecolorallocate($im, 232, 238, 245);
            imagefill($im, 0, 0, $white);
            imagealphablending($im, true);

            if ($nomorHalaman === 0) {
                $y = siprima_f101_gambar_kop($im, $data, $pageW, $margin, $labelX,
                    $ink, $muted, $bandBg, $regular, $bold);
            } else {
                $y = siprima_f101_gambar_kop_lanjutan($im, $data, $pageW, $margin,
                    $ink, $muted, $regular, $bold);
            }

            // ---- Daftar anggota ----
            if ($nomorHalaman === 0) {
                imagefilledrectangle($im, $margin, $y - 20, $pageW - $margin, $y + 8, $bandBg);
                siprima_formulir_text($im, 11, $labelX, $y, $ink, $bold, 'DATA ANGGOTA KELUARGA');
                $y += 26;
                siprima_formulir_text($im, 9, $labelX, $y, $muted, $regular,
                    'Field yang tidak dicantumkan berarti TIDAK ADA PERUBAHAN pada data tersebut.');
                $y += 26;
            }

            foreach ($indeks as $i) {
                $y = siprima_f101_gambar_anggota($im, $daftar[$i], $i + 1, $y,
                    $pageW, $margin, $labelX, $ink, $muted, $line, $regular, $bold, $labels);
            }

            // ---- Pernyataan + tanda tangan, hanya di halaman terakhir ----
            if ($nomorHalaman === $total - 1) {
                $y = max($y, 1754 - 70 - SIPRIMA_F101_TINGGI_TTD);
                siprima_f101_gambar_pernyataan($im, $data, $y, $pageW, $margin, $labelX,
                    $ink, $line, $regular, $bold, $binaryPng, $dibuatAt);
            }

            siprima_f101_gambar_footer($im, $pageW, $pageH, $margin, $muted, $line, $regular,
                $nomorHalaman + 1, $total, $dibuatAt);

            foreach ([88, 80, 72, 64] as $quality) {
                ob_start();
                $ok = imagejpeg($im, null, $quality);
                $jpeg = (string)ob_get_clean();
                if (!$ok || $jpeg === '') throw new RuntimeException('Formulir gagal disimpan sebagai gambar.');
                if (strlen($jpeg) <= 900000) break;
            }
            $keluaran[] = $jpeg;
        } finally {
            unset($im);
        }
    }

    return $keluaran;
}

function siprima_f101_gambar_kop(
    \GdImage $im, array $data, int $pageW, int $margin, int $labelX,
    int $ink, int $muted, int $bandBg, string $regular, string $bold
): int {
    $y = $margin + 30;
    siprima_formulir_text_center($im, 15, (int)($pageW / 2), $y, $ink, $bold, 'PEMERINTAH KABUPATEN GORONTALO');
    $y += 26;
    siprima_formulir_text_center($im, 13, (int)($pageW / 2), $y, $ink, $bold, 'DINAS KEPENDUDUKAN DAN PENCATATAN SIPIL');
    $y += 18;
    imagefilledrectangle($im, $margin, $y, $pageW - $margin, $y + 2, $ink);
    $y += 42;
    siprima_formulir_text_center($im, 14, (int)($pageW / 2), $y, $ink, $bold, 'LAMPIRAN DATA PERMOHONAN KARTU KELUARGA');
    $y += 24;
    siprima_formulir_text_center($im, 10, (int)($pageW / 2), $y, $muted, $regular, 'Setara Formulir F-1.01 — Biodata Keluarga');
    $y += 24;

    $wilayah = 'Kode Wilayah 7501  ·  Kecamatan ' . siprima_formulir_fit((string)($data['nama_kecamatan'] ?? ''), $regular, 10, 320)
        . '  ·  Desa/Kelurahan ' . siprima_formulir_fit((string)($data['nama_desa'] ?? ''), $regular, 10, 320);
    siprima_formulir_text_center($im, 10, (int)($pageW / 2), $y, $muted, $regular, $wilayah);
    $y += 40;

    $valueX = $margin + 360;
    $valueW = $pageW - $margin - 24 - $valueX;
    imagefilledrectangle($im, $margin, $y - 20, $pageW - $margin, $y + 8, $bandBg);
    siprima_formulir_text($im, 11, $labelX, $y, $ink, $bold, 'DATA PERMOHONAN');
    $y += 34;

    $baris = [
        ['Jenis Permohonan', (string)($data['jenis_permohonan'] ?? '')],
        ['Alamat / Dusun', (string)($data['dusun'] ?? '')],
        ['Nama Kepala Keluarga', (string)($data['nama_kepala_keluarga'] ?? '')],
        ['Nama Pemohon', (string)($data['nama_pemohon'] ?? '')],
        ['NIK Pemohon', (string)($data['nik_pemohon'] ?? '')],
    ];
    foreach ($baris as [$label, $value]) {
        siprima_formulir_text($im, 10.5, $labelX, $y, $muted, $regular, $label);
        siprima_formulir_text($im, 10.5, $valueX - 14, $y, $muted, $regular, ':');
        siprima_formulir_text($im, 10.5, $valueX, $y, $ink, $bold, siprima_formulir_fit($value, $bold, 10.5, $valueW));
        $y += 29;
    }
    return $y + 24;
}

// Tanpa kop lanjutan, satu halaman yang lepas dari berkas menjadi anonim.
function siprima_f101_gambar_kop_lanjutan(
    \GdImage $im, array $data, int $pageW, int $margin,
    int $ink, int $muted, string $regular, string $bold
): int {
    $y = $margin + 30;
    siprima_formulir_text_center($im, 12, (int)($pageW / 2), $y, $ink, $bold,
        'LAMPIRAN DATA PERMOHONAN KARTU KELUARGA (Lanjutan)');
    $y += 26;
    $identitas = 'Kepala Keluarga: ' . siprima_formulir_fit((string)($data['nama_kepala_keluarga'] ?? ''), $regular, 10, 380)
        . '  ·  Pemohon: ' . siprima_formulir_fit((string)($data['nama_pemohon'] ?? ''), $regular, 10, 320)
        . ' (NIK ' . (string)($data['nik_pemohon'] ?? '') . ')';
    siprima_formulir_text_center($im, 10, (int)($pageW / 2), $y, $muted, $regular, $identitas);
    $y += 16;
    imagefilledrectangle($im, $margin, $y, $pageW - $margin, $y + 1, $ink);
    return $y + 40;
}

function siprima_f101_gambar_anggota(
    \GdImage $im, array $anggota, int $nomor, int $y,
    int $pageW, int $margin, int $labelX,
    int $ink, int $muted, int $line, string $regular, string $bold, array $labels
): int {
    $nama = siprima_formulir_fit((string)($anggota['nama'] ?? ''), $bold, 10.5, 420);
    siprima_formulir_text($im, 10.5, $labelX, $y, $ink, $bold, $nomor . '. ' . $nama);

    $nik = trim((string)($anggota['nik'] ?? ''));
    siprima_formulir_text($im, 10, 640, $y, $muted, $regular, 'NIK ' . ($nik === '' ? '-' : $nik));

    $hubungan = trim((string)($anggota['hubungan'] ?? ''));
    siprima_formulir_text($im, 10, 920, $y, $muted, $regular,
        siprima_formulir_fit($hubungan === '' ? '-' : $hubungan, $regular, 10, 230));
    $y += 34;

    // Dua kolom; hanya field terisi yang dicetak.
    $kolomX = [$labelX + 16, 660];
    $nilaiX = [$labelX + 206, 850];
    $kolom = 0;
    foreach ($labels as $key => $label) {
        $nilai = trim((string)($anggota[$key] ?? ''));
        if ($nilai === '') continue;
        siprima_formulir_text($im, 9.5, $kolomX[$kolom], $y, $muted, $regular, $label);
        siprima_formulir_text($im, 9.5, $nilaiX[$kolom] - 12, $y, $muted, $regular, ':');
        siprima_formulir_text($im, 9.5, $nilaiX[$kolom], $y, $ink, $bold,
            siprima_formulir_fit($nilai, $bold, 9.5, $kolom === 0 ? 240 : 300));
        $kolom++;
        if ($kolom === 2) {
            $kolom = 0;
            $y += 26;
        }
    }
    if ($kolom === 1) $y += 26;

    imagefilledrectangle($im, $margin, $y, $pageW - $margin, $y, $line);
    return $y + 18;
}

function siprima_f101_gambar_pernyataan(
    \GdImage $im, array $data, int $y, int $pageW, int $margin, int $labelX,
    int $ink, int $line, string $regular, string $bold, string $binaryPng, string $dibuatAt
): void {
    imagerectangle($im, $margin, $y - 22, $pageW - $margin, $y + 62, $line);
    siprima_formulir_text($im, 10.5, $labelX, $y, $ink, $bold, 'PERNYATAAN PEMOHON');
    $y += 28;
    // Kalimat resmi dari blanko F-1.01.
    siprima_formulir_text($im, 9.5, $labelX, $y, $ink, $regular,
        'Demikian formulir ini saya/kami isi dengan sesungguhnya, apabila keterangan tersebut tidak sesuai');
    $y += 22;
    siprima_formulir_text($im, 9.5, $labelX, $y, $ink, $regular,
        'dengan keadaan sebenarnya saya bersedia dikenakan sanksi sesuai ketentuan peraturan perundang-undangan.');
    $y += 46;

    $signX = $pageW - $margin - 400;
    $signCenter = $signX + 200;
    siprima_formulir_text_center($im, 10.5, $signCenter, $y, $ink, $regular,
        'Kabupaten Gorontalo, ' . siprima_formulir_tanggal_panjang($dibuatAt));
    $y += 24;
    siprima_formulir_text_center($im, 10.5, $signCenter, $y, $ink, $regular, 'Pemohon,');
    $y += 6;

    siprima_formulir_draw_signature($im, $binaryPng, $signX, $y, 400, 100);
    $y += 118;

    siprima_formulir_text_center($im, 11, $signCenter, $y, $ink, $bold,
        siprima_formulir_fit((string)($data['nama_pemohon'] ?? ''), $bold, 11, 390));
    $y += 22;
    $nik = trim((string)($data['nik_pemohon'] ?? ''));
    if ($nik !== '') {
        siprima_formulir_text_center($im, 10, $signCenter, $y, $ink, $regular, 'NIK ' . $nik);
    }
}

function siprima_f101_gambar_footer(
    \GdImage $im, int $pageW, int $pageH, int $margin,
    int $muted, int $line, string $regular, int $halaman, int $total, string $dibuatAt
): void {
    $footerY = $pageH - $margin;
    imagefilledrectangle($im, $margin, $footerY - 42, $pageW - $margin, $footerY - 41, $line);
    siprima_formulir_text($im, 9, $margin, $footerY - 20, $muted, $regular,
        "Halaman {$halaman} dari {$total}  ·  Dibuat otomatis oleh SIPRIMA pada "
        . date('d-m-Y H:i', strtotime($dibuatAt) ?: time()) . ' Wita.');
    siprima_formulir_text($im, 9, $margin, $footerY, $muted, $regular,
        'Tanda tangan pemohon diverifikasi oleh petugas Dinas Kependudukan dan Pencatatan Sipil.');
}
