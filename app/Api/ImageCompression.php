<?php
declare(strict_types=1);
namespace Siprima\Api;

/** Only mobile JPG/PNG requirements use this processor; PDF and browser uploads do not. */
final class ImageCompression
{
    public const MAX_INPUT_BYTES = 5 * 1024 * 1024;
    // Strictly below 500 KB, including when KB is interpreted as 1,000 bytes.
    public const TARGET_BYTES = 500000;
    public const MAX_PIXELS = 24000000;
    public const MAX_DIMENSION = 16000;

    public static function process(string $path, string $mime): array
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatefromjpeg') || !function_exists('imagecreatefrompng')) {
            fail(503, 'IMAGE_PROCESSOR_UNAVAILABLE', 'Pengolahan gambar belum tersedia. Silakan hubungi pengelola layanan.');
        }
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) fail(422, 'INVALID_UPLOAD', 'Gunakan gambar JPG atau PNG.');
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > self::MAX_INPUT_BYTES) fail(422, 'INVALID_UPLOAD', 'Gambar maksimal 5 MB.');
        $info = @getimagesize($path);
        if (!$info || ($info['mime'] ?? '') !== $mime) fail(422, 'INVALID_UPLOAD', 'Isi gambar tidak valid.');
        [$width, $height] = $info;
        // Check dimensions BEFORE decoding compressed input to bound decompression memory use.
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $width * $height > self::MAX_PIXELS) {
            fail(422, 'IMAGE_DIMENSIONS_TOO_LARGE', 'Resolusi gambar terlalu besar. Gunakan gambar maksimal 24 megapiksel dan sisi maksimal 16.000 piksel.');
        }
        $limit = self::memoryLimitBytes();
        $required = $width * $height * 16 + 32 * 1024 * 1024;
        if ($limit !== null && memory_get_usage(true) + $required > $limit) {
            fail(422, 'IMAGE_DIMENSIONS_TOO_LARGE', 'Resolusi gambar melebihi kapasitas pemrosesan server. Pilih gambar beresolusi lebih kecil.');
        }
        if ($mime === 'image/jpeg' && !function_exists('exif_read_data')) {
            fail(503, 'IMAGE_PROCESSOR_UNAVAILABLE', 'Pembacaan orientasi foto belum tersedia. Silakan hubungi pengelola layanan.');
        }

        $source = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$source) fail(422, 'INVALID_UPLOAD', 'Gambar rusak atau tidak dapat dibaca. Pilih ulang gambar.');
        $output = null;
        $scaled = null;
        try {
            if ($mime === 'image/jpeg') {
                $exif = @exif_read_data($path, 'IFD0', true, false);
                $orientation = (int)($exif['IFD0']['Orientation'] ?? 1);
                $source = self::orient($source, $orientation);
            }
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $width = $sourceWidth;
            $height = $sourceHeight;
            $output = tmpfile();
            if (!$output) throw new \RuntimeException('Image temporary storage unavailable');

            for ($attempt = 0; $attempt < 32; $attempt++) {
                $image = $source;
                if ($width !== $sourceWidth || $height !== $sourceHeight) {
                    $scaled = imagecreatetruecolor($width, $height);
                    if (!$scaled) throw new \RuntimeException('Image allocation failed');
                    // Preserve transparent background, including indexed PNG with transparency.
                    imagealphablending($scaled, false);
                    imagesavealpha($scaled, true);
                    imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
                    if (!imagecopyresampled($scaled, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight)) {
                        throw new \RuntimeException('Image resize failed');
                    }
                    $image = $scaled;
                }
                imagesavealpha($image, true);
                // PNG remains PNG: no JPEG conversion, flattening, palette reduction, or crop.
                $qualities = $mime === 'image/jpeg' ? [90, 75, 60, 45, 30] : [9];
                $encodedSize = 0;
                foreach ($qualities as $quality) {
                    if (!ftruncate($output, 0) || !rewind($output)) throw new \RuntimeException('Image stream unavailable');
                    $ok = $mime === 'image/png' ? @imagepng($image, $output, 9) : @imagejpeg($image, $output, $quality);
                    if (!$ok || !fflush($output)) throw new \RuntimeException('Image encoding failed');
                    $encodedSize = (int)(fstat($output)['size'] ?? 0);
                    if ($encodedSize > 0 && $encodedSize < self::TARGET_BYTES) {
                        rewind($output);
                        $bytes = stream_get_contents($output);
                        if ($bytes === false || strlen($bytes) !== $encodedSize) throw new \RuntimeException('Image read failed');
                        return ['bytes' => $bytes, 'original_file_size' => (int)$size,
                            'file_size' => $encodedSize, 'mime_type' => $mime, 'width' => $width, 'height' => $height];
                    }
                }
                if ($scaled) { imagedestroy($scaled); $scaled = null; }
                if ($width === 1 && $height === 1) break;
                // Resample from the source on every attempt, keeping the complete image and aspect ratio.
                $ratio = min(0.8, max(0.25, sqrt((self::TARGET_BYTES - 1) / max(1, $encodedSize)) * 0.9));
                $newScale = min($width / $sourceWidth, $height / $sourceHeight) * $ratio;
                $width = max(1, (int)round($sourceWidth * $newScale));
                $height = max(1, (int)round($sourceHeight * $newScale));
            }
            fail(422, 'IMAGE_COMPRESSION_FAILED', 'Gambar belum berhasil diperkecil di bawah 500 KB. Pilih gambar lain.');
        } finally {
            if ($scaled) imagedestroy($scaled);
            imagedestroy($source);
            if (is_resource($output)) fclose($output);
        }
    }

    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
        elseif ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
        $angle = match ($orientation) { 3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
        if ($angle === 0) return $image;
        $rotated = imagerotate($image, $angle, 0);
        if (!$rotated) throw new \RuntimeException('Image orientation failed');
        imagedestroy($image);
        return $rotated;
    }

    private static function memoryLimitBytes(): ?int
    {
        $value = trim((string)ini_get('memory_limit'));
        if ($value === '-1') return null;
        $factor = match (strtolower(substr($value, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        return (int)$value * $factor;
    }
}
