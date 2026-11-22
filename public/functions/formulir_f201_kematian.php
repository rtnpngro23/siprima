<?php
declare(strict_types=1);

require_once __DIR__ . '/formulir_render.php';

// Lembar data setara Formulir F-2.01 (Akta Kematian) yang digambar sendiri oleh sistem dengan GD.
// Berkas saudara dari formulir_f201.php (Akta Kelahiran) — sama-sama memakai primitif generik di
// formulir_render.php, tapi tidak saling bergantung. Tidak ada field bisnis baru di sini: struktur
// data kematian sudah lengkap (nama/NIK meninggal, tanggal, tempat, data ayah/ibu, data saksi 1 & 2).
// Berbeda dari kelahiran, Saksi II di sini SELALU wajib diisi — tidak pernah dicetak sebagai "-".

// Satu sumber kebenaran susunan data dokumen, dipakai halaman ajuan, edit, dan pratinjau.
function siprima_f201_kematian_data(
    array $post,
    array $pemohon,
    string $namaKecamatan,
    string $namaDesa,
    string $dibuatAt
): array {
    return [
        'nama_kecamatan' => $namaKecamatan,
        'nama_desa' => $namaDesa,
        'nama_meninggal' => trim((string)($post['nama_meninggal'] ?? '')),
        'nik_meninggal' => trim((string)($post['nik_meninggal'] ?? '')),
        'tempat' => trim((string)($post['tempat'] ?? '')),
        'tanggal_kematian' => trim((string)($post['tanggal_kematian'] ?? '')),
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
 * Menggambar lembar data setara F-2.01 Kematian dan mengembalikan byte JPEG.
 * Satu halaman saja — tidak ada daftar berulang seperti KK, jadi tidak perlu paginasi.
 * @throws RuntimeException bila GD/font tidak tersedia atau encoding gagal.
 */
function siprima_f201_kematian_render(array $data, string $binaryPng): string
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
        siprima_formulir_text_center($im, 14, (int)($pageW / 2), $y, $ink, $bold, 'LAMPIRAN DATA PERMOHONAN AKTA KEMATIAN');
        $y += 24;
        siprima_formulir_text_center($im, 10, (int)($pageW / 2), $y, $muted, $regular, 'Setara Formulir F-2.01 — Pelaporan Pencatatan Sipil');
        $y += 24;

        $wilayah = 'Kode Wilayah 7501  ·  Kecamatan ' . siprima_formulir_fit((string)($data['nama_kecamatan'] ?? ''), $regular, 10, 320)
            . '  ·  Desa/Kelurahan ' . siprima_formulir_fit((string)($data['nama_desa'] ?? ''), $regular, 10, 320);
        siprima_formulir_text_center($im, 10, (int)($pageW / 2), $y, $muted, $regular, $wilayah);
        $y += 34;

        // ---- Bagian data ----
        $tanggalKematian = (string)($data['tanggal_kematian'] ?? '');
        $hariTanggal = trim(siprima_formulir_hari($tanggalKematian) . ', ' . siprima_formulir_tanggal_panjang($tanggalKematian), ', ');

        $sections = [
            'DATA YANG MENINGGAL' => [
                ['Nama', (string)($data['nama_meninggal'] ?? '')],
                ['NIK', (string)($data['nik_meninggal'] ?? '')],
                ['Tempat Kematian', (string)($data['tempat'] ?? '')],
                ['Hari dan Tanggal Kematian', $hariTanggal],
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
            // Saksi II wajib (berbeda dari kelahiran): server tidak pernah menerima nilai kosong
            // di sini, jadi baris ini selalu berisi data nyata, tidak pernah "-".
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
