<?php
$root = dirname(__DIR__);
function checkBabyRole(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
foreach (['ahlak', 'rs'] as $babyNameModule) {
    $_SESSION = ['id_user' => 7, 'nama' => '<script>alert(1)</script>', 'nama_puskes' => 'Puskes <uji>', 'nama_rs' => 'RS <uji>'];
    ob_start();
    include $root . '/app/views/baby_name_institution.php';
    $html = ob_get_clean();
    checkBabyRole(strpos($html, 'data-apply="' . $babyNameModule . '_akta_kelahiran.php"') !== false, 'Wrong application target');
    checkBabyRole(strpos($html, 'href="' . $babyNameModule . '_ajukan_permohonan.php"') !== false, 'Wrong return target');
    checkBabyRole(strpos($html, 'data-account="7"') !== false, 'Missing account');
    checkBabyRole(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;') !== false, 'Unsafe account display');
    checkBabyRole(strpos($html, 'kamus-nama-bayi.html') === false, 'Removed dictionary link returned');
    checkBabyRole(substr_count($html, 'id="baby-generator"') === 1, 'Duplicate generator');
    preg_match_all('/(?:href|src)="([^"?]+)(?:\?[^\"]*)?"/', $html, $matches);
    foreach ($matches[1] as $relative) {
        checkBabyRole(is_file($root . '/public/user_' . $babyNameModule . '/' . $relative), 'Missing asset or navigation: ' . $relative);
    }
    $wrapper = file_get_contents($root . '/public/user_' . $babyNameModule . '/' . $babyNameModule . '_generate_nama_bayi.php');
    $guardPosition = strpos($wrapper, 'middleware/user_' . $babyNameModule . '_only.php');
    $viewPosition = strpos($wrapper, 'views/baby_name_institution.php');
    checkBabyRole($guardPosition !== false && $viewPosition !== false && $guardPosition < $viewPosition, 'Middleware must run before view');
    $form = file_get_contents($root . '/public/user_' . $babyNameModule . '/' . $babyNameModule . '_akta_kelahiran.php');
    checkBabyRole(strpos($form, 'name="nama_bayi"') !== false && substr_count($form, 'baby-name-handoff.js') === 1, 'Missing or duplicate handoff');
    $menu = file_get_contents($root . '/public/user_' . $babyNameModule . '/' . $babyNameModule . '_ajukan_permohonan.php');
    checkBabyRole(strpos($menu, 'href="' . $babyNameModule . '_generate_nama_bayi.php"') !== false, 'Missing menu entry');
}
echo "Baby name roles: AHLAK/RS template rendering, escaping, navigation, assets, middleware order and integration passed.\n";
