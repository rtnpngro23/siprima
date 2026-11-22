<?php
/**
 * Environment Variable Loader
 * 
 * @param string $path Path ke file .env
 * @return bool True jika berhasil, false jika gagal
 */
function loadEnv($path)
{
    // Cek apakah file .env ada
    if (!file_exists($path)) {
        error_log("[ENV] File .env tidak ditemukan di: " . $path);
        return false;
    }

    // Baca file .env
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        error_log("[ENV] Gagal membaca file .env");
        return false;
    }

    foreach ($lines as $line) {
        // Abaikan komentar yang dimulai dengan #
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Abaikan baris yang tidak mengandung '='
        if (strpos($line, '=') === false) {
            continue;
        }

        // Pisahkan key dan value berdasarkan tanda =
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name = trim($parts[0]);
        $value = trim($parts[1]);

        // Validasi nama variabel (hanya huruf, angka, dan underscore)
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) {
            error_log("[ENV] Nama varienv tidak valid: " . $name);
            continue;
        }

        // Hapus tanda kutip jika ada (misal: APP_NAME="SI PRIMA")
        // Dukungan untuk single quotes, double quotes, dan backticks
        if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
            (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1) ||
            (strpos($value, "`") === 0 && strrpos($value, "`") === strlen($value) - 1)) {
            $value = substr($value, 1, -1);
        }

        // Dekode escaped characters
        $value = str_replace(['\"', "\'"], ['"', "'"], $value);

        // Hapus komentar inline (setelah #)
        if (($pos = strpos($value, ' #')) !== false) {
            $value = substr($value, 0, $pos);
            $value = trim($value);
        }

        // Special handling untuk password kosong
        if ($value === '' && in_array($name, ['DB_PASS', 'SMTP_PASSWORD'])) {
            // Biarkan kosong untuk development
            $value = '';
        }

        // Set hanya jika belum ada di environment
        if (getenv($name) === false && !array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            
            // Debug logging (disable di production)
            if (getenv('APP_DEBUG') === 'true') {
                $isSensitive = preg_match('/(?:PASS|PASSWORD|TOKEN|SECRET|KEY|CREDENTIAL)/i', $name) === 1;
                error_log("[ENV] Loaded: " . $name . " = " . ($isSensitive ? '***HIDDEN***' : $value));
            }
        }
    }
    
    // Set default timezone jika ada
    if (getenv('APP_TIMEZONE')) {
        date_default_timezone_set(getenv('APP_TIMEZONE'));
    } else {
        date_default_timezone_set('Asia/Makassar'); // Default untuk Gorontalo
    }
    
    return true;
}

/**
 * Helper function untuk mendapatkan env variable dengan default value
 * 
 * @param string $key Kunci environment variable
 * @param mixed $default Default value jika tidak ditemukan
 * @return mixed
 */
function env($key, $default = null)
{
    $value = getenv($key);
    
    if ($value === false) {
        return $default;
    }
    
    // Konversi tipe data berdasarkan value
    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'null':
        case '(null)':
            return null;
        case 'empty':
        case '(empty)':
            return '';
    }
    
    // Coba parse sebagai numeric
    if (is_numeric($value)) {
        if (strpos($value, '.') !== false) {
            return (float)$value;
        }
        return (int)$value;
    }
    
    return $value;
}
?>
