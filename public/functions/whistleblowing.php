<?php

declare(strict_types=1);

function wbs_categories(): array
{
    return [
        'pungli_gratifikasi' => 'Pungli atau Gratifikasi',
        'penyalahgunaan_wewenang' => 'Penyalahgunaan Wewenang',
        'diskriminasi' => 'Diskriminasi Pelayanan',
        'pelanggaran_prosedur' => 'Pelanggaran Prosedur',
        'pelanggaran_data' => 'Pelanggaran Data atau Privasi',
        'lainnya' => 'Pelanggaran Lainnya',
    ];
}

function wbs_statuses(): array
{
    return [
        'baru' => 'Baru',
        'diverifikasi' => 'Diverifikasi',
        'diproses' => 'Diproses',
        'menunggu_pemohon' => 'Menunggu Pemohon',
        'selesai' => 'Selesai',
        'ditolak' => 'Ditolak',
    ];
}

function wbs_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function wbs_status_label(string $status): string
{
    return wbs_statuses()[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function wbs_category_label(string $category): string
{
    return wbs_categories()[$category] ?? 'Lainnya';
}

function wbs_publication_id(string $caseCode): string
{
    return 'WBP-'.strtoupper(substr(hash('sha256', $caseCode), 0, 24));
}

function wbs_public_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Makassar')))->format(DateTimeInterface::ATOM);
    } catch (Throwable $exception) {
        return null;
    }
}

function wbs_publications_snapshot(mysqli $db): array
{
    $stmt = $db->prepare(
        "SELECT kode_aduan,kategori,ringkasan_publik,hasil_tindak_lanjut_publik,published_at,updated_at
         FROM tb_whistleblowing_aduan
         WHERE status='selesai'
           AND is_published=1
           AND ringkasan_publik IS NOT NULL
           AND hasil_tindak_lanjut_publik IS NOT NULL
         ORDER BY published_at DESC,id DESC"
    );
    if (! $stmt || ! $stmt->execute()) {
        if ($stmt) {
            $stmt->close();
        }
        throw new RuntimeException('Data publik whistleblowing belum dapat dimuat.');
    }

    $items = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $publishedAt = wbs_public_datetime((string) ($row['published_at'] ?? ''));
        $updatedAt = wbs_public_datetime((string) ($row['updated_at'] ?? ''));
        if ($publishedAt === null || $updatedAt === null) {
            $stmt->close();
            throw new RuntimeException('Waktu publikasi whistleblowing tidak valid.');
        }

        $category = (string) $row['kategori'];
        $items[] = [
            'publication_id' => wbs_publication_id((string) $row['kode_aduan']),
            'category_code' => $category,
            'category_label' => wbs_category_label($category),
            'summary' => (string) $row['ringkasan_publik'],
            'follow_up_result' => (string) $row['hasil_tindak_lanjut_publik'],
            'published_at' => $publishedAt,
            'updated_at' => $updatedAt,
        ];
    }
    $stmt->close();

    return $items;
}

function wbs_require_regular_applicant(mysqli $db): int
{
    $userId = (int) ($_SESSION['id_user'] ?? 0);
    if ($userId <= 0 || (string) ($_SESSION['role'] ?? '') !== 'user') {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    $stmt = $db->prepare('SELECT id_kua,id_puskes,id_rs,id_petugas FROM tb_users WHERE id=? AND role=\'user\' AND is_active=1 LIMIT 1');
    if (! $stmt) {
        http_response_code(503);
        exit('Layanan whistleblowing belum tersedia.');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (! $row || ! empty($row['id_kua']) || ! empty($row['id_puskes']) || ! empty($row['id_rs']) || ! empty($row['id_petugas'])) {
        http_response_code(403);
        exit('Fitur ini khusus pemohon umum.');
    }

    return $userId;
}

function wbs_case_for_applicant(mysqli $db, string $code, int $userId): ?array
{
    $stmt = $db->prepare('SELECT * FROM tb_whistleblowing_aduan WHERE kode_aduan=? AND pemohon_id=? LIMIT 1');
    if (! $stmt) {
        return null;
    }
    $stmt->bind_param('si', $code, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function wbs_case_by_code(mysqli $db, string $code): ?array
{
    $stmt = $db->prepare('SELECT a.*,u.nama AS nama_pemohon,u.nik,u.email,u.no_hp FROM tb_whistleblowing_aduan a LEFT JOIN tb_users u ON u.id=a.pemohon_id WHERE a.kode_aduan=? LIMIT 1');
    if (! $stmt) {
        return null;
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function wbs_timeline(mysqli $db, int $caseId, bool $includeInternal): array
{
    $sql = 'SELECT t.*,u.nama AS actor_name FROM tb_whistleblowing_tindak_lanjut t LEFT JOIN tb_users u ON u.id=t.actor_user_id WHERE t.aduan_id=?';
    if (! $includeInternal) {
        $sql .= " AND t.visibility='pemohon'";
    }
    $sql .= ' ORDER BY t.created_at ASC,t.id ASC';
    $stmt = $db->prepare($sql);
    if (! $stmt) {
        return [];
    }
    $stmt->bind_param('i', $caseId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $items;
}

function wbs_attachments(mysqli $db, int $caseId): array
{
    $stmt = $db->prepare('SELECT l.*,t.visibility,t.actor_role FROM tb_whistleblowing_lampiran l LEFT JOIN tb_whistleblowing_tindak_lanjut t ON t.id=l.tindak_lanjut_id WHERE l.aduan_id=? ORDER BY l.uploaded_at,l.id');
    if (! $stmt) {
        return [];
    }
    $stmt->bind_param('i', $caseId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $items;
}

function wbs_generate_code(mysqli $db): string
{
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $code = 'WBS-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
        $stmt = $db->prepare('SELECT 1 FROM tb_whistleblowing_aduan WHERE kode_aduan=? LIMIT 1');
        if (! $stmt) {
            throw new RuntimeException('Gagal memeriksa nomor aduan.');
        }
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (! $exists) {
            return $code;
        }
    }
    throw new RuntimeException('Gagal membuat nomor aduan unik.');
}

function wbs_allowed_transition(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }
    $map = [
        'baru' => ['diverifikasi', 'ditolak'],
        'diverifikasi' => ['diproses', 'menunggu_pemohon', 'ditolak'],
        'diproses' => ['menunggu_pemohon', 'selesai', 'ditolak'],
        'menunggu_pemohon' => ['diproses', 'selesai', 'ditolak'],
        'selesai' => [],
        'ditolak' => [],
    ];

    return in_array($to, $map[$from] ?? [], true);
}

function wbs_public_text_contains_sensitive_data(string $text, array $case): bool
{
    if (preg_match('/\b\d{16}\b/u', $text) || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $text)
        || preg_match('/(?<!\d)(?:\+62|62|0)8\d{8,12}(?!\d)/u', preg_replace('/[\s().-]+/', '', $text))) {
        return true;
    }
    foreach (['nama_pemohon', 'nik', 'email', 'no_hp', 'pihak_terlapor'] as $field) {
        $value = trim((string) ($case[$field] ?? ''));
        if (mb_strlen($value) >= 4 && mb_stripos($text, $value) !== false) {
            return true;
        }
    }

    return false;
}

function wbs_uploaded_files(string $field = 'lampiran'): array
{
    if (empty($_FILES[$field]) || ! is_array($_FILES[$field]['name'] ?? null)) {
        return [];
    }
    $files = [];
    foreach ($_FILES[$field]['name'] as $index => $name) {
        $error = (int) ($_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name' => (string) $name,
            'type' => (string) ($_FILES[$field]['type'][$index] ?? ''),
            'tmp_name' => (string) ($_FILES[$field]['tmp_name'][$index] ?? ''),
            'error' => $error,
            'size' => (int) ($_FILES[$field]['size'][$index] ?? 0),
        ];
    }

    return $files;
}

function wbs_validate_files(array $files): ?string
{
    if (count($files) > 3) {
        return 'Maksimal tiga lampiran untuk setiap kiriman.';
    }
    $allowedExtensions = ['jpg', 'jpeg', 'png'];
    foreach ($files as $file) {
        $message = null;
        if (! upload_security_validate_uploaded_file($file, $message)) {
            return $message ?: 'Lampiran tidak valid.';
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (! in_array($extension, $allowedExtensions, true)) {
            return 'Lampiran hanya boleh berupa gambar JPG atau PNG.';
        }
        $actualSize = @filesize((string) $file['tmp_name']);
        if (! is_int($actualSize) || $actualSize <= 0 || $actualSize > 5 * 1024 * 1024) {
            return 'Ukuran setiap lampiran maksimal 5 MB.';
        }
        $details = wbs_image_upload_details((string) $file['tmp_name']);
        if ($details === null) {
            return 'Lampiran bukan gambar JPG atau PNG yang valid.';
        }
        $expectedExtensions = $details['type'] === IMAGETYPE_JPEG ? ['jpg', 'jpeg'] : ['png'];
        if (! in_array($extension, $expectedExtensions, true)) {
            return 'Ekstensi lampiran tidak sesuai dengan isi gambar.';
        }
        $detectedMime = upload_security_detect_mime((string) $file['tmp_name']);
        if ($detectedMime !== $details['mime']) {
            return 'Tipe MIME lampiran tidak sesuai dengan isi gambar.';
        }
        if ($details['width'] > 6000 || $details['height'] > 6000 || ($details['width'] * $details['height']) > 16000000) {
            return 'Resolusi gambar terlalu besar. Maksimal 16 megapiksel dan 6000 piksel per sisi.';
        }
        if (! wbs_image_processing_available($details['type'])) {
            return 'Layanan pemeriksaan keamanan gambar belum tersedia.';
        }
    }

    return null;
}

function wbs_image_upload_details(string $path): ?array
{
    $image = @getimagesize($path);
    if (! is_array($image) || ! isset($image[0], $image[1], $image[2])) {
        return null;
    }

    $type = (int) $image[2];
    $formats = [
        IMAGETYPE_JPEG => ['extension' => 'jpg', 'mime' => 'image/jpeg'],
        IMAGETYPE_PNG => ['extension' => 'png', 'mime' => 'image/png'],
    ];
    if (! isset($formats[$type]) || (int) $image[0] <= 0 || (int) $image[1] <= 0) {
        return null;
    }

    return [
        'type' => $type,
        'extension' => $formats[$type]['extension'],
        'mime' => $formats[$type]['mime'],
        'width' => (int) $image[0],
        'height' => (int) $image[1],
    ];
}

function wbs_image_processing_available(int $type): bool
{
    return match ($type) {
        IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
        IMAGETYPE_PNG => function_exists('imagecreatefrompng') && function_exists('imagepng'),
        default => false,
    };
}

function wbs_reencode_image(string $source, string $destination, int $type): bool
{
    if (! wbs_image_processing_available($type)) {
        return false;
    }

    $image = $type === IMAGETYPE_JPEG
        ? @imagecreatefromjpeg($source)
        : @imagecreatefrompng($source);
    if ($image === false) {
        return false;
    }

    try {
        if ($type === IMAGETYPE_JPEG) {
            @imageinterlace($image, true);

            return @imagejpeg($image, $destination, 90);
        }

        @imagealphablending($image, false);
        @imagesavealpha($image, true);

        return @imagepng($image, $destination, 6);
    } finally {
        imagedestroy($image);
    }
}

function wbs_store_files(mysqli $db, int $caseId, ?int $followUpId, int $userId, string $code, array $files): array
{
    if (! $files) {
        return [];
    }
    if ($validationError = wbs_validate_files($files)) {
        throw new InvalidArgumentException($validationError);
    }
    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'whistleblowing';
    $caseDirectory = $root.DIRECTORY_SEPARATOR.preg_replace('/[^A-Z0-9-]/', '', strtoupper($code));
    if (! is_dir($caseDirectory) && ! mkdir($caseDirectory, 0750, true) && ! is_dir($caseDirectory)) {
        throw new RuntimeException('Folder penyimpanan bukti tidak dapat dibuat.');
    }

    $storedPaths = [];
    try {
        foreach ($files as $file) {
            $details = wbs_image_upload_details((string) $file['tmp_name']);
            if ($details === null) {
                throw new RuntimeException('Salah satu lampiran bukan gambar yang valid.');
            }
            $storedName = bin2hex(random_bytes(16)).'.'.$details['extension'];
            $absolutePath = $caseDirectory.DIRECTORY_SEPARATOR.$storedName;
            $storedPaths[] = $absolutePath;
            if (! wbs_reencode_image((string) $file['tmp_name'], $absolutePath, (int) $details['type'])) {
                throw new RuntimeException('Salah satu lampiran gagal melewati pemeriksaan keamanan gambar.');
            }
            @chmod($absolutePath, 0640);
            clearstatcache(true, $absolutePath);
            $storedDetails = wbs_image_upload_details($absolutePath);
            $mime = upload_security_detect_mime($absolutePath);
            if ($storedDetails === null || $mime !== $details['mime']) {
                throw new RuntimeException('Hasil pemrosesan keamanan gambar tidak valid.');
            }
            $relativePath = 'storage/whistleblowing/'.basename($caseDirectory).'/'.$storedName;
            $originalName = mb_substr(basename(str_replace(["\0", "\r", "\n"], '', (string) $file['name'])), 0, 255);
            $size = (int) filesize($absolutePath);
            $stmt = $db->prepare('INSERT INTO tb_whistleblowing_lampiran (aduan_id,tindak_lanjut_id,uploaded_by,original_name,stored_name,file_path,file_size,mime_type) VALUES (?,?,?,?,?,?,?,?)');
            if (! $stmt) {
                throw new RuntimeException('Metadata lampiran gagal disiapkan.');
            }
            $stmt->bind_param('iiisssis', $caseId, $followUpId, $userId, $originalName, $storedName, $relativePath, $size, $mime);
            if (! $stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Metadata lampiran gagal disimpan.');
            }
            $stmt->close();
        }
    } catch (Throwable $exception) {
        wbs_cleanup_files($storedPaths);
        throw $exception;
    }

    return $storedPaths;
}

function wbs_cleanup_files(array $paths): void
{
    foreach ($paths as $path) {
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
    }
}

function wbs_set_flash(string $type, string $message): void
{
    $_SESSION['wbs_flash'] = ['type' => $type, 'message' => $message];
}

function wbs_take_flash(): ?array
{
    $flash = $_SESSION['wbs_flash'] ?? null;
    unset($_SESSION['wbs_flash']);

    return is_array($flash) ? $flash : null;
}
