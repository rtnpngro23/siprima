<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../app/Api/Core.php';
require_once __DIR__.'/../app/Api/ImageCompression.php';

use Siprima\Api\ApiError;
use Siprima\Api\ImageCompression;

if (!extension_loaded('gd')) { fwrite(STDERR, "Enable GD for the test process.\n"); exit(1); }
$paths = [];
function fixture(string $bytes): string {
    global $paths;
    $path = tempnam(sys_get_temp_dir(), 'siprima-image-');
    $paths[] = $path;
    file_put_contents($path, $bytes);
    return $path;
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $call, string $code): void {
    try { $call(); } catch (ApiError $error) { check($error->errorCode === $code, 'Unexpected rejection code'); return; }
    throw new RuntimeException('Expected rejection: '.$code);
}
function encoded(GdImage $image, bool $png): string {
    ob_start();
    try { $png ? imagepng($image, null, 0) : imagejpeg($image, null, 100); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
function classify(GdImage $image, int $x, int $y): string {
    $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
    if ($c['red'] > 180 && $c['green'] > 180) return 'Y';
    if ($c['red'] > 180) return 'R';
    if ($c['green'] > 180) return 'G';
    if ($c['blue'] > 180) return 'B';
    return '?';
}

try {
    $image = imagecreatetruecolor(1200, 900);
    imagealphablending($image, false); imagesavealpha($image, true);
    mt_srand(32026);
    for ($y = 0; $y < 900; $y++) for ($x = 0; $x < 1200; $x++) {
        imagesetpixel($image, $x, $y, mt_rand(0, 0xffffff));
    }
    imagefilledrectangle($image, 0, 0, 100, 100, imagecolorallocatealpha($image, 220, 20, 60, 0));
    imagefilledrectangle($image, 1090, 0, 1199, 100, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 0, 790, 100, 899, imagecolorallocatealpha($image, 30, 120, 200, 64));
    $png = encoded($image, true);
    check(strlen($png) > 1048576 && strlen($png) < ImageCompression::MAX_INPUT_BYTES, 'Large PNG fixture bounds');
    $result = ImageCompression::process(fixture($png), 'image/png');
    check($result['file_size'] < 500000 && $result['file_size'] === strlen($result['bytes']), 'PNG target bytes');
    check($result['original_file_size'] === strlen($png), 'Original PNG size preserved as metadata');
    $decoded = imagecreatefromstring($result['bytes']);
    check(getimagesizefromstring($result['bytes'])['mime'] === 'image/png', 'PNG format retained');
    check(abs(imagesx($decoded) / imagesy($decoded) - 1200 / 900) < 0.01, 'Aspect ratio retained');
    $topLeft = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
    $topRight = imagecolorsforindex($decoded, imagecolorat($decoded, imagesx($decoded)-1, 0));
    $bottomLeft = imagecolorsforindex($decoded, imagecolorat($decoded, 0, imagesy($decoded)-1));
    check($topLeft['red'] === 220 && $topLeft['green'] === 20 && $topLeft['blue'] === 60, 'Opaque background and corners not removed');
    check($topRight['alpha'] === 127, 'Transparent PNG corner retained');
    check(abs($bottomLeft['alpha'] - 64) <= 1, 'Partial transparency retained');
    imagedestroy($decoded);
    echo "PASS large PNG below 500000 bytes, full frame, aspect ratio, colors and transparency\n";

    $jpeg = encoded($image, false);
    $result = ImageCompression::process(fixture($jpeg), 'image/jpeg');
    check($result['file_size'] < 500000 && getimagesizefromstring($result['bytes'])['mime'] === 'image/jpeg', 'JPEG target and format');
    imagedestroy($image);
    echo "PASS large JPEG below 500000 bytes without format conversion\n";

    $palette = imagecreate(10, 10);
    $transparent = imagecolorallocate($palette, 9, 8, 7);
    imagecolortransparent($palette, $transparent);
    imagefilledrectangle($palette, 5, 5, 9, 9, imagecolorallocate($palette, 250, 10, 20));
    $small = encoded($palette, true);
    $processed = ImageCompression::process(fixture($small), 'image/png');
    $decoded = imagecreatefromstring($processed['bytes']);
    check(imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha'] === 127, 'Indexed transparency retained');
    check(imagesx($decoded) === 10 && imagesy($decoded) === 10, 'Small image not resized');
    imagedestroy($decoded); imagedestroy($palette);

    $atLimit = str_pad($small, ImageCompression::MAX_INPUT_BYTES, "\0");
    check(ImageCompression::process(fixture($atLimit), 'image/png')['file_size'] < 500000, 'Exactly 5 MiB accepted');
    rejects(fn() => ImageCompression::process(fixture($atLimit."\0"), 'image/png'), 'INVALID_UPLOAD');
    rejects(fn() => ImageCompression::process(fixture('<?php echo 1;'), 'image/png'), 'INVALID_UPLOAD');
    rejects(fn() => ImageCompression::process(fixture(substr($small, 0, 33)), 'image/png'), 'INVALID_UPLOAD');
    $huge = substr_replace($small, pack('N', 50000), 16, 4);
    rejects(fn() => ImageCompression::process(fixture($huge), 'image/png'), 'IMAGE_DIMENSIONS_TOO_LARGE');
    rejects(fn() => ImageCompression::process(fixture($small), 'application/pdf'), 'INVALID_UPLOAD');
    echo "PASS indexed PNG, 5 MiB boundary, corrupt/spoofed/oversize images and PDF exclusion\n";

    $quads = imagecreatetruecolor(80, 40);
    foreach ([[0,0,39,19,255,0,0],[40,0,79,19,0,255,0],[0,20,39,39,0,0,255],[40,20,79,39,255,255,0]] as $q) {
        imagefilledrectangle($quads, $q[0], $q[1], $q[2], $q[3], imagecolorallocate($quads, $q[4], $q[5], $q[6]));
    }
    $plain = encoded($quads, false); imagedestroy($quads);
    $expected = [1=>'RGBY',2=>'GRYB',3=>'YBGR',4=>'BYRG',5=>'RBGY',6=>'BRYG',7=>'YGBR',8=>'GYRB'];
    foreach ($expected as $orientation => $colors) {
        $tiff = "II".pack('vV',42,8).pack('v',1).pack('vvV',0x112,3,1).pack('v',$orientation)."\0\0".pack('V',0);
        $exif = "Exif\0\0".$tiff;
        $bytes = substr($plain,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($plain,2);
        $result = ImageCompression::process(fixture($bytes), 'image/jpeg');
        $decoded = imagecreatefromstring($result['bytes']);
        $w = imagesx($decoded); $h = imagesy($decoded);
        check($w === ($orientation >= 5 ? 40 : 80) && $h === ($orientation >= 5 ? 80 : 40), 'Oriented JPEG dimensions');
        $actual = classify($decoded, 5, 5).classify($decoded, $w-6, 5).classify($decoded, 5, $h-6).classify($decoded, $w-6, $h-6);
        check($actual === $colors, 'EXIF orientation '.$orientation.' must preserve all quadrants');
        imagedestroy($decoded);
    }
    echo "PASS all eight EXIF orientations, no crop or lost image quadrants\n";
    echo "PASS mobile image compression\n";
} finally {
    foreach ($paths as $path) if (is_file($path)) unlink($path);
}
