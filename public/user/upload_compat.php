<?php
/**
 * Utilitas upload kompatibel browser lama.
 * Validasi tetap dilakukan di server; JavaScript hanya menjadi bantuan UI.
 */

if (!function_exists('siprima_upload_error_message')) {
    function siprima_upload_error_message($errorCode)
    {
        switch ((int)$errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'ukuran file melebihi batas server';
            case UPLOAD_ERR_PARTIAL:
                return 'upload terputus dan file hanya diterima sebagian; pilih ulang file lalu coba lagi';
            case UPLOAD_ERR_NO_FILE:
                return 'file tidak diterima; pilih ulang file';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'penyimpanan sementara server tidak tersedia';
            case UPLOAD_ERR_CANT_WRITE:
                return 'server gagal menulis file sementara';
            case UPLOAD_ERR_EXTENSION:
                return 'upload dihentikan oleh konfigurasi server';
            default:
                return 'upload gagal dengan kode ' . (int)$errorCode;
        }
    }
}

if (!function_exists('siprima_validate_uploaded_image')) {
    function siprima_validate_uploaded_image($tmpName, $originalName, $reportedSize, $maxSize, &$detectedMime, &$safeExtension)
    {
        $detectedMime = '';
        $safeExtension = '';
        $displayName = basename((string)$originalName);

        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName) || !is_readable($tmpName)) {
            return "File {$displayName}: file sementara tidak dapat diakses. Pilih ulang hanya file ini lalu ajukan kembali.";
        }

        $actualSize = filesize($tmpName);
        if ($actualSize === false || $actualSize <= 0) {
            return "File {$displayName}: file kosong atau tidak selesai dibaca. Pilih ulang hanya file ini.";
        }
        if ((int)$reportedSize !== (int)$actualSize) {
            return "File {$displayName}: ukuran file yang diterima tidak lengkap. Pilih ulang hanya file ini.";
        }
        if ($actualSize > (int)$maxSize) {
            return "File {$displayName}: ukuran maksimal 1MB.";
        }

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $detectedMime = $finfo ? (string)finfo_file($finfo, $tmpName) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $mimeExtensions = array('image/jpeg' => 'jpg', 'image/png' => 'png');
        if (!isset($mimeExtensions[$detectedMime])) {
            return "File {$displayName}: format tidak didukung. Gunakan JPG atau PNG.";
        }

        // getimagesize memverifikasi isi gambar, bukan hanya nama atau MIME dari browser.
        if (@getimagesize($tmpName) === false) {
            return "File {$displayName}: isi file bukan gambar JPG/PNG yang valid.";
        }

        $safeExtension = $mimeExtensions[$detectedMime];
        return '';
    }
}

if (!function_exists('siprima_remove_upload_tree')) {
    function siprima_remove_upload_tree($directory, $allowedRoot)
    {
        if (!is_string($directory) || !is_string($allowedRoot) || !is_dir($directory)) {
            return;
        }
        $rootReal = realpath($allowedRoot);
        $dirReal = realpath($directory);
        if ($rootReal === false || $dirReal === false || $dirReal === $rootReal) {
            return;
        }
        $prefix = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        if (strpos(str_replace('\\', '/', $dirReal) . '/', $prefix) !== 0) {
            return;
        }

        $items = scandir($dirReal);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dirReal . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                siprima_remove_upload_tree($path, $rootReal);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dirReal);
    }
}

if (!function_exists('siprima_validate_request_images')) {
    /** Validasi awal seluruh file pada request, termasuk field tunggal dan array. */
    function siprima_validate_request_images(&$errors, $maxSize = 1048576)
    {
        if (!is_array($errors) || empty($_FILES)) return;
        $initialErrorCount = count($errors);
        foreach ($_FILES as $upload) {
            if (!is_array($upload) || !isset($upload['error'])) continue;
            $names = is_array($upload['name']) ? $upload['name'] : array($upload['name']);
            $tmps = is_array($upload['tmp_name']) ? $upload['tmp_name'] : array($upload['tmp_name']);
            $sizes = is_array($upload['size']) ? $upload['size'] : array($upload['size']);
            $codes = is_array($upload['error']) ? $upload['error'] : array($upload['error']);
            foreach ($codes as $index => $code) {
                $name = basename((string)($names[$index] ?? 'dokumen'));
                if ((int)$code === UPLOAD_ERR_NO_FILE) continue;
                if ((int)$code !== UPLOAD_ERR_OK) {
                    $errors[] = "File {$name}: " . siprima_upload_error_message($code);
                    continue;
                }
                $mime = '';
                $safeExtension = '';
                $message = siprima_validate_uploaded_image(
                    (string)($tmps[$index] ?? ''),
                    $name,
                    (int)($sizes[$index] ?? 0),
                    (int)$maxSize,
                    $mime,
                    $safeExtension
                );
                if ($message !== '') {
                    $errors[] = $message;
                    continue;
                }
                $originalExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (($safeExtension === 'jpg' && !in_array($originalExtension, array('jpg', 'jpeg', 'jfif', 'jpe'), true))
                    || ($safeExtension === 'png' && $originalExtension !== 'png')) {
                    $errors[] = "File {$name}: ekstensi tidak sesuai dengan isi file. Simpan ulang sebagai JPG atau PNG.";
                }
            }
        }

        // Jika satu file tidak valid, cegah handler lama memindahkan file lain.
        // Dengan demikian edit tetap atomik pada tahap validasi upload.
        if (count($errors) > $initialErrorCount) {
            foreach ($_FILES as $field => $upload) {
                if (!isset($upload['error'])) continue;
                if (is_array($upload['error'])) {
                    foreach ($upload['error'] as $index => $code) {
                        if ((int)$code === UPLOAD_ERR_OK) {
                            $_FILES[$field]['error'][$index] = UPLOAD_ERR_EXTENSION;
                        }
                    }
                } elseif ((int)$upload['error'] === UPLOAD_ERR_OK) {
                    $_FILES[$field]['error'] = UPLOAD_ERR_EXTENSION;
                }
            }
        }
    }
}
