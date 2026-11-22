<?php
declare(strict_types=1);

const SIPRIMA_PASSWORD_HELP = 'Password minimal 8 karakter, mengandung huruf besar, huruf kecil, angka, dan simbol.';

const SIPRIMA_PASSWORD_MAX_BYTES = 72;

/**
 * Satu sumber aturan password: dipakai pemeriksaan server di siprima_password_error()
 * dan dikirim ke ceklis langsung di browser lewat siprima_password_rules_json(),
 * supaya aturan yang tampil di layar tidak pernah melenceng dari yang ditegakkan
 * server. Pola ditulis agar sah di PCRE maupun RegExp JavaScript.
 */
function siprima_password_rules(): array
{
    return [
        ['kode' => 'panjang', 'teks' => 'Minimal 8 karakter', 'pola' => '^[\s\S]{8,}$'],
        ['kode' => 'besar', 'teks' => 'Ada huruf besar (A-Z)', 'pola' => '[A-Z]'],
        ['kode' => 'kecil', 'teks' => 'Ada huruf kecil (a-z)', 'pola' => '[a-z]'],
        ['kode' => 'angka', 'teks' => 'Ada angka (0-9)', 'pola' => '[0-9]'],
        ['kode' => 'simbol', 'teks' => 'Ada simbol, contoh ! @ # $ %', 'pola' => '[^a-zA-Z0-9\s]'],
    ];
}

function siprima_password_common(): array
{
    return ['password1!', 'password123!', 'qwerty123!', 'admin123!', 'welcome1!', 'siprima123!'];
}

function siprima_password_rules_json(): string
{
    return json_encode([
        'aturan' => siprima_password_rules(),
        'maksByte' => SIPRIMA_PASSWORD_MAX_BYTES,
        'umum' => siprima_password_common(),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function siprima_password_error(string $password): ?string
{
    // PASSWORD_DEFAULT currently uses bcrypt; reject silent truncation beyond 72 bytes.
    if (strlen($password) > SIPRIMA_PASSWORD_MAX_BYTES || str_contains($password, "\0")) {
        return SIPRIMA_PASSWORD_HELP;
    }
    foreach (siprima_password_rules() as $aturan) {
        if (preg_match('/' . $aturan['pola'] . '/u', $password) !== 1) return SIPRIMA_PASSWORD_HELP;
    }
    if (in_array(strtolower($password), siprima_password_common(), true)) {
        return 'Password terlalu umum. Pilih kombinasi lain.';
    }
    return null;
}

function siprima_generate_password(int $length = 16): string
{
    $length = max(8, min(72, $length));
    $groups = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghjkmnpqrstuvwxyz', '23456789', '!@#$%&*'];
    $chars = implode('', $groups);
    do {
        $password = '';
        foreach ($groups as $group) $password .= $group[random_int(0, strlen($group) - 1)];
        while (strlen($password) < $length) $password .= $chars[random_int(0, strlen($chars) - 1)];
        for ($i = strlen($password) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }
    } while (siprima_password_error($password) !== null);
    return $password;
}
