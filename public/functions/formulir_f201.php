<?php
declare(strict_types=1);

require_once __DIR__ . '/formulir_render.php';

// Lembar data setara Formulir F-2.01 yang digambar sendiri oleh sistem dengan GD.
// Keluarannya JPEG agar masuk ke pipeline dokumen yang sudah ada (kolom `dokumen` JSON + document.php).
// Tanda tangan pemohon dibakar ke dalam gambar dan tidak pernah disimpan terpisah.

function siprima_f201_jenis_kelahiran_options(): array
{
    return [1 => 'Tunggal', 2 => 'Kembar 2', 3 => 'Kembar 3', 4 => 'Kembar 4', 5 => 'Lainnya'];
}

function siprima_f201_penolong_options(): array
{
    return [1 => 'Dokter', 2 => 'Bidan/Perawat', 3 => 'Dukun', 4 => 'Lainnya'];
}

// "3,2" dan "3.2" sama-sama sah; apa pun di luar pola angka ditolak, bukan dipaksa jadi 0.
function siprima_f201_decimal_to_int(string $raw, int $factor): ?int
{
    $raw = trim(str_replace(',', '.', $raw));
    if ($raw === '' || preg_match('/^\d{1,3}(?:\.\d{1,3})?$/D', $raw) !== 1) return null;
    return (int)round(((float)$raw) * $factor);
}

/**
 * Memvalidasi empat isian baru F-2.01 dari $_POST.
 * Nilai yang lolos ditulis ke $fields siap di-bind sebagai integer.
 * Mengembalikan daftar pesan error (kosong bila semua sah).
 */
function siprima_f201_validate_fields(array $post, ?array &$fields): array
{
    $errors = [];
    $fields = [
        'jenis_kelahiran' => null,
        'penolong_kelahiran' => null,
        'berat_bayi_gram' => null,
        'panjang_bayi_mm' => null,
    ];

    $jenis = (int)($post['jenis_kelahiran'] ?? 0);
    if (!array_key_exists($jenis, siprima_f201_jenis_kelahiran_options())) {
        $errors[] = 'Jenis kelahiran wajib dipilih.';
    } else {
        $fields['jenis_kelahiran'] = $jenis;
    }

    $penolong = (int)($post['penolong_kelahiran'] ?? 0);
    if (!array_key_exists($penolong, siprima_f201_penolong_options())) {
        $errors[] = 'Penolong kelahiran wajib dipilih.';
    } else {
        $fields['penolong_kelahiran'] = $penolong;
    }

    $gram = siprima_f201_decimal_to_int((string)($post['berat_bayi'] ?? ''), 1000);
    if ($gram === null || $gram < 500 || $gram > 9999) {
        $errors[] = 'Berat bayi wajib diisi antara 0,5 kg sampai 9,99 kg.';
    } else {
        $fields['berat_bayi_gram'] = $gram;
    }

    $mm = siprima_f201_decimal_to_int((string)($post['panjang_bayi'] ?? ''), 10);
    if ($mm === null || $mm < 200 || $mm > 999) {
        $errors[] = 'Panjang bayi wajib diisi antara 20 cm sampai 99,9 cm.';
    } else {
        $fields['panjang_bayi_mm'] = $mm;
    }

    return $errors;
}

// Satu sumber kebenaran susunan data dokumen, dipakai halaman ajuan maupun endpoint pratinjau.
function siprima_f201_data_akta_kelahiran(
    array $post,
    array $fields,
    array $pemohon,
    string $namaKecamatan,
    string $namaDesa,
    string $dibuatAt
): array {
    return [
        'nama_kecamatan' => $namaKecamatan,
        'nama_desa' => $namaDesa,
        'nama_bayi' => trim((string)($post['nama_bayi'] ?? '')),
        'tempat_lahir' => trim((string)($post['tempat_lahir'] ?? '')),
        'tanggal_lahir' => trim((string)($post['tanggal_lahir'] ?? '')),
        'waktu' => trim((string)($post['waktu'] ?? '')),
        'jenis_kelamin' => trim((string)($post['jenis_kelamin'] ?? '')),
        'urutan_kelahiran' => (int)($post['urutan_kelahiran'] ?? 0),
        'jenis_kelahiran' => $fields['jenis_kelahiran'] ?? null,
        'penolong_kelahiran' => $fields['penolong_kelahiran'] ?? null,
        'berat_bayi_gram' => $fields['berat_bayi_gram'] ?? null,
        'panjang_bayi_mm' => $fields['panjang_bayi_mm'] ?? null,
        'nama_ayah' => trim((string)($post['nama_ayah'] ?? '')),
        'nik_ayah' => trim((string)($post['nik_ayah'] ?? '')),
        'nama_ibu' => trim((string)($post['nama_ibu'] ?? '')),
        'nik_ibu' => trim((string)($post['nik_ibu'] ?? '')),
        'nama_saksi1' => trim((string)($post['nama_saksi1'] ?? '')),
        'nik_saksi1' => trim((string)($post['nik_saksi1'] ?? '')),
        'nama_saksi2' => trim((string)($post['nama_saksi2'] ?? '')),
        'nik_saksi2' => trim((string)($post['nik_saksi2'] ?? '')),
        'nama_pemohon' => (string)($pemohon['nama'] ?? ''),
        'nik_pemohon' => (string)($pemohon['nik'] ?? ''),
        'dibuat_at' => $dibuatAt,
    ];
}

/**
 * Menggambar lembar data setara F-2.01 dan mengembalikan byte JPEG.
 * @throws RuntimeException bila GD/font tidak tersedia atau encoding gagal.
 */
function siprima_f201_render(array $data, string $binaryPng): string
{
    $regular = siprima_formulir_font(false);
    $bold = siprima_formulir_font(true);
    if (!is_readable($regular) || !is_readable($bold)) {
        throw new RuntimeException('Berkas font formulir tidak ditemukan.');
    }

    $pageW = 1240;
    $pageH = 1754;
    $margin = 70;
    $labelX = $margin + 24;
    $valueX = $margin + 360;
    $valueW = $pageW - $margin - 24 - $valueX;

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

        // ---- Kop ----
        $y = $margin + 30;
        siprima_formulir_text_center($im, 15, (int)($pageW / 2), $y, $ink, $bold, 'PEMERINTAH KABUPATEN GORONTALO');
        $y += 26;
        siprima_formulir_text_center($im, 13, (int)($pageW / 2), $y, $ink, $bold, 'DINAS KEPENDUDUKAN DAN PENCATATAN SIPIL');
        $y += 18;
        imagefilledrectangle($im, $margin, $y, $pageW - $margin, $y + 2, $ink);
        $y += 42;
        siprima_formulir_text_center($im, 14, (int)($pageW / 2), $y, $ink, $bold, 'LAMPIRAN DATA PERMOHONAN AKTA KELAHIRAN');
        $y += 24;
        siprima_formulir_text_center($im, 10, (int)($pageW / 2), $y, $muted, $regular, 'Setara Formulir F-2.01 — Pelaporan Pencatatan Sipil');
        $y += 24;

        $wilayah = 'Kode Wilayah 7501  ·  Kecamatan ' . siprima_formulir_fit((string)($data['nama_kecamatan'] ?? ''), $regular, 10, 320)
            . '  ·  Desa/Kelurahan ' . siprima_formulir_fit((string)($data['nama_desa'] ?? ''), $regular, 10, 320);
        siprima_formulir_text_center($im, 10, (int)($pageW / 2), $y, $muted, $regular, $wilayah);
        $y += 34;

        // ---- Bagian data ----
        $jenisKelahiran = siprima_f201_jenis_kelahiran_options()[(int)($data['jenis_kelahiran'] ?? 0)] ?? '';
        $penolong = siprima_f201_penolong_options()[(int)($data['penolong_kelahiran'] ?? 0)] ?? '';
        $kelamin = ((string)($data['jenis_kelamin'] ?? '') === 'L') ? 'Laki-laki'
            : (((string)($data['jenis_kelamin'] ?? '') === 'P') ? 'Perempuan' : '');

        $tanggalLahir = (string)($data['tanggal_lahir'] ?? '');
        $hariTanggal = trim(siprima_formulir_hari($tanggalLahir) . ', ' . siprima_formulir_tanggal_panjang($tanggalLahir), ', ');

        $waktu = (string)($data['waktu'] ?? '');
        $pukul = $waktu === '' ? '' : substr($waktu, 0, 5) . ' Wita';

        $gram = (int)($data['berat_bayi_gram'] ?? 0);
        $mm = (int)($data['panjang_bayi_mm'] ?? 0);
        $berat = $gram > 0 ? number_format($gram / 1000, 2, ',', '.') . ' kg' : '';
        $panjang = $mm > 0 ? number_format($mm / 10, 1, ',', '.') . ' cm' : '';

        $urutan = (int)($data['urutan_kelahiran'] ?? 0);

        $sections = [
            'DATA ANAK' => [
                ['Nama Anak', (string)($data['nama_bayi'] ?? '')],
                ['Tempat Kelahiran', (string)($data['tempat_lahir'] ?? '')],
                ['Hari dan Tanggal Lahir', $hariTanggal],
                ['Pukul', $pukul],
                ['Jenis Kelamin', $kelamin],
                ['Jenis Kelahiran', $jenisKelahiran],
                ['Kelahiran Ke-', $urutan > 0 ? (string)$urutan : ''],
                ['Penolong Kelahiran', $penolong],
                ['Berat Bayi', $berat],
                ['Panjang Bayi', $panjang],
            ],
            'DATA ORANG TUA' => [
                ['Nama Ayah', (string)($data['nama_ayah'] ?? '')],
                ['NIK Ayah', (string)($data['nik_ayah'] ?? '')],
                ['Nama Ibu', (string)($data['nama_ibu'] ?? '')],
                ['NIK Ibu', (string)($data['nik_ibu'] ?? '')],
            ],
            'DATA PELAPOR' => [
                ['Nama Pelapor', (string)($data['nama_pemohon'] ?? '')],
                ['NIK Pelapor', (string)($data['nik_pemohon'] ?? '')],
            ],
            'DATA SAKSI I' => [
                ['Nama Saksi I', (string)($data['nama_saksi1'] ?? '')],
                ['NIK Saksi I', (string)($data['nik_saksi1'] ?? '')],
            ],
            'DATA SAKSI II' => [
                ['Nama Saksi II', (string)($data['nama_saksi2'] ?? '')],
                ['NIK Saksi II', (string)($data['nik_saksi2'] ?? '')],
            ],
        ];

        foreach ($sections as $title => $rows) {
            imagefilledrectangle($im, $margin, $y - 20, $pageW - $margin, $y + 8, $bandBg);
            siprima_formulir_text($im, 11, $labelX, $y, $ink, $bold, $title);
            $y += 34;
            foreach ($rows as [$label, $value]) {
                siprima_formulir_text($im, 10.5, $labelX, $y, $muted, $regular, $label);
                siprima_formulir_text($im, 10.5, $valueX - 14, $y, $muted, $regular, ':');
                siprima_formulir_text($im, 10.5, $valueX, $y, $ink, $bold, siprima_formulir_fit($value, $bold, 10.5, $valueW));
                $y += 29;
            }
            $y += 12;
        }

        // ---- Pernyataan ----
        $y += 6;
        imagerectangle($im, $margin, $y - 22, $pageW - $margin, $y + 62, $line);
        siprima_formulir_text($im, 10.5, $labelX, $y, $ink, $bold, 'PERNYATAAN PEMOHON');
        $y += 28;
        siprima_formulir_text($im, 10, $labelX, $y, $ink, $regular,
            'Saya menyatakan bahwa seluruh data yang saya isi di atas adalah benar dan saya');
        $y += 24;
        siprima_formulir_text($im, 10, $labelX, $y, $ink, $regular,
            'bertanggung jawab sepenuhnya atas kebenaran data tersebut.');
        $y += 60;

        // ---- Blok tanda tangan (hanya pemohon) ----
        $signX = $pageW - $margin - 400;
        $signCenter = $signX + 200;
        $dibuatAt = (string)($data['dibuat_at'] ?? date('Y-m-d H:i:s'));
        siprima_formulir_text_center($im, 10.5, $signCenter, $y, $ink, $regular,
            'Kabupaten Gorontalo, ' . siprima_formulir_tanggal_panjang($dibuatAt));
        $y += 26;
        siprima_formulir_text_center($im, 10.5, $signCenter, $y, $ink, $regular, 'Pemohon,');
        $y += 10;

        siprima_formulir_draw_signature($im, $binaryPng, $signX, $y, 400, 130);
        $y += 150;

        $namaPemohon = siprima_formulir_fit((string)($data['nama_pemohon'] ?? ''), $bold, 11, 390);
        siprima_formulir_text_center($im, 11, $signCenter, $y, $ink, $bold, $namaPemohon);
        $y += 22;
        $nik = trim((string)($data['nik_pemohon'] ?? ''));
        siprima_formulir_text_center($im, 10, $signCenter, $y, $muted, $regular, $nik === '' ? '' : 'NIK ' . $nik);

        // ---- Footer ----
        $footerY = $pageH - $margin;
        imagefilledrectangle($im, $margin, $footerY - 42, $pageW - $margin, $footerY - 41, $line);
        siprima_formulir_text($im, 9, $margin, $footerY - 20, $muted, $regular,
            'Dibuat otomatis oleh SIPRIMA pada ' . date('d-m-Y H:i', strtotime($dibuatAt) ?: time()) . ' Wita.');
        siprima_formulir_text($im, 9, $margin, $footerY, $muted, $regular,
            'Tanda tangan pemohon diverifikasi oleh petugas Dinas Kependudukan dan Pencatatan Sipil.');

        // ---- Encode, turunkan mutu sampai di bawah batas ----
        foreach ([88, 80, 72, 64] as $quality) {
            ob_start();
            $ok = imagejpeg($im, null, $quality);
            $jpeg = (string)ob_get_clean();
            if (!$ok || $jpeg === '') throw new RuntimeException('Formulir gagal disimpan sebagai gambar.');
            if (strlen($jpeg) <= 900000) return $jpeg;
        }
        return $jpeg;
    } finally {
        unset($im);
    }
}
