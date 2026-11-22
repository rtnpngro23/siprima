<?php
declare(strict_types=1);

// Primitif bersama untuk formulir yang digambar sistem dengan GD (F-2.01, F-1.01, ...).
// Berkas ini tidak tahu apa pun tentang jenis layanan; ia hanya menggambar dan memvalidasi.
// Seluruh isinya dipindahkan apa adanya dari formulir_f201.php — nol perubahan logika.

function siprima_formulir_font(bool $bold = false): string
{
    return __DIR__ . '/../../app/data/fonts/LiberationSans-' . ($bold ? 'Bold' : 'Regular') . '.ttf';
}

function siprima_formulir_hari(string $tanggal): string
{
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $ts = strtotime($tanggal);
    return $ts === false ? '' : $hari[(int)date('w', $ts)];
}

function siprima_formulir_tanggal_panjang(string $tanggal): string
{
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($tanggal);
    if ($ts === false) return '';
    return date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/**
 * Memvalidasi tanda tangan dari kanvas browser (data URL PNG).
 * Mengembalikan '' bila valid; selain itu pesan error siap tampil.
 * Byte PNG yang lolos seluruh pemeriksaan ditulis ke $binaryPng.
 */
function siprima_formulir_validate_signature(string $dataUrl, ?string &$binaryPng): string
{
    $binaryPng = null;

    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        return 'Pembuatan formulir belum tersedia di server. Silakan hubungi pengelola layanan.';
    }
    if ($dataUrl === '') {
        return 'Tanda tangan belum diisi. Silakan tanda tangani formulir terlebih dahulu.';
    }
    // Batasi sebelum decode agar payload raksasa tidak pernah dialokasikan.
    if (strlen($dataUrl) > 400000) {
        return 'Tanda tangan terlalu besar. Silakan ulangi tanda tangan Anda.';
    }
    if (preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/]+={0,2}$/D', $dataUrl) !== 1) {
        return 'Format tanda tangan tidak dikenali. Silakan ulangi tanda tangan Anda.';
    }

    $binary = base64_decode(substr($dataUrl, 22), true);
    if ($binary === false || $binary === '') {
        return 'Tanda tangan tidak dapat dibaca. Silakan ulangi tanda tangan Anda.';
    }

    // Cek dimensi SEBELUM decode agar PNG bomb tidak menghabiskan memori.
    $info = @getimagesizefromstring($binary);
    if (!$info || ($info['mime'] ?? '') !== 'image/png') {
        return 'Tanda tangan harus berupa gambar PNG. Silakan ulangi tanda tangan Anda.';
    }
    [$width, $height] = $info;
    if ($width < 50 || $height < 20 || $width > 2000 || $height > 1000 || $width * $height > 2000000) {
        return 'Ukuran tanda tangan tidak wajar. Silakan ulangi tanda tangan Anda.';
    }

    $image = @imagecreatefromstring($binary);
    if (!$image) {
        return 'Tanda tangan rusak atau tidak dapat dibaca. Silakan ulangi tanda tangan Anda.';
    }
    $hasInk = siprima_formulir_signature_has_ink($image);
    unset($image); // GdImage dibebaskan oleh refcount; imagedestroy() deprecated sejak PHP 8.5
    if (!$hasInk) {
        return 'Tanda tangan masih kosong. Silakan tanda tangani formulir terlebih dahulu.';
    }

    $binaryPng = $binary;
    return '';
}

// Menolak kanvas kosong: JS bisa dilewati dengan POST langsung.
function siprima_formulir_signature_has_ink(\GdImage $image): bool
{
    $width = imagesx($image);
    $height = imagesy($image);
    // Sampling berjenjang agar kanvas besar tidak discan piksel per piksel.
    $step = max(1, (int)floor(sqrt(($width * $height) / 40000)));
    $inked = 0;
    for ($y = 0; $y < $height; $y += $step) {
        for ($x = 0; $x < $width; $x += $step) {
            $rgba = imagecolorat($image, $x, $y);
            if ((($rgba >> 24) & 0x7F) >= 64) continue; // sebagian besar transparan
            $luminance = (($rgba >> 16) & 0xFF) * 0.299 + (($rgba >> 8) & 0xFF) * 0.587 + ($rgba & 0xFF) * 0.114;
            if ($luminance < 200 && ++$inked >= 60) return true;
        }
    }
    return false;
}

function siprima_formulir_fit(string $text, string $font, float $size, int $maxWidth): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') return '-';
    $box = imagettfbbox($size, 0, $font, $text);
    if ($box === false || ($box[2] - $box[0]) <= $maxWidth) return $text;

    $length = mb_strlen($text, 'UTF-8');
    while ($length > 1) {
        $length--;
        $candidate = rtrim(mb_substr($text, 0, $length, 'UTF-8')) . '…';
        $box = imagettfbbox($size, 0, $font, $candidate);
        if ($box !== false && ($box[2] - $box[0]) <= $maxWidth) return $candidate;
    }
    return '…';
}

function siprima_formulir_text(\GdImage $im, float $size, int $x, int $y, int $color, string $font, string $text): void
{
    imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
}

function siprima_formulir_text_center(\GdImage $im, float $size, int $centerX, int $y, int $color, string $font, string $text): void
{
    $box = imagettfbbox($size, 0, $font, $text);
    $width = $box === false ? 0 : $box[2] - $box[0];
    imagettftext($im, $size, 0, (int)($centerX - $width / 2), $y, $color, $font, $text);
}

// Menempelkan tanda tangan, menjaga rasio, terpusat di dalam kotaknya.
// Byte PNG mentah tidak pernah menyentuh disk: decode -> resample -> ikut ter-encode sebagai JPEG.
function siprima_formulir_draw_signature(\GdImage $page, string $binaryPng, int $boxX, int $boxY, int $boxW, int $boxH): void
{
    $sign = @imagecreatefromstring($binaryPng);
    if (!$sign) throw new RuntimeException('Tanda tangan tidak dapat diproses.');

    try {
        $srcW = imagesx($sign);
        $srcH = imagesy($sign);
        if ($srcW < 1 || $srcH < 1) throw new RuntimeException('Tanda tangan tidak dapat diproses.');

        $scale = min($boxW / $srcW, $boxH / $srcH, 1.0);
        $dstW = max(1, (int)round($srcW * $scale));
        $dstH = max(1, (int)round($srcH * $scale));
        $dstX = $boxX + (int)(($boxW - $dstW) / 2);
        $dstY = $boxY + (int)(($boxH - $dstH) / 2);

        imagealphablending($sign, false);
        imagesavealpha($sign, true);
        imagealphablending($page, true);
        imagecopyresampled($page, $sign, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
    } finally {
        unset($sign);
    }
}
