<?php
declare(strict_types=1);

require_once __DIR__ . '/../error_handler.php';

const DOKUMEN_HASIL_UPLOAD_FIELD = 'dokumen_hasil';
const DOKUMEN_HASIL_MAX_BYTES = 1048576; // 1MB
// Dinonaktifkan sementara sampai pengujian fitur dokumen hasil dilanjutkan.
require_once __DIR__ . '/../../app/config/document_features.php';

function dokumen_hasil_is_enabled(): bool
{
    return DOKUMEN_HASIL_ENABLED;
}

function dokumen_hasil_allowed_source_tables(): array
{
    return [
        'tb_kartukeluarga',
        'tb_akta_kelahiran',
        'tb_akta_kematian',
        'tb_pindah_alamat',
        'tb_pindah_online',
        'tb_akta_kelahiran_rs',
        'tb_akta_kematian_rs',
        'tb_kartukeluarga_kua',
        'tb_akta_kelahiran_ahlak',
    ];
}

function dokumen_hasil_upload_root(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'hasil_permohonan';
}

function dokumen_hasil_relative_root(): string
{
    return 'uploads/hasil_permohonan';
}

function dokumen_hasil_is_allowed_source_table(string $sourceTable): bool
{
    return in_array($sourceTable, dokumen_hasil_allowed_source_tables(), true);
}

function dokumen_hasil_has_uploaded_files(string $fieldName = DOKUMEN_HASIL_UPLOAD_FIELD): bool
{
    if (!dokumen_hasil_is_enabled()) {
        return false;
    }

    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return false;
    }

    $files = upload_security_flatten_files([$fieldName => $_FILES[$fieldName]]);
    foreach ($files as $file) {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }

    return false;
}

function dokumen_hasil_sanitize_original_name(string $name): string
{
    $name = str_replace("\0", '', basename($name));
    $name = preg_replace('/[^\w\s.\-()]/u', '_', $name);
    $name = preg_replace('/\s+/', ' ', (string)$name);
    $name = trim((string)$name);

    return $name !== '' ? $name : 'dokumen_hasil.pdf';
}

function dokumen_hasil_slug_value(string $value, string $fallback = 'unknown'): string
{
    $value = trim($value);
    if ($value === '') {
        $value = $fallback;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');

    return $value !== '' ? substr($value, 0, 50) : $fallback;
}

function dokumen_hasil_subject_from_row(string $sourceTable, array $row): string
{
    return match ($sourceTable) {
        'tb_akta_kelahiran',
        'tb_akta_kelahiran_rs',
        'tb_akta_kelahiran_ahlak' => (string)($row['nama_bayi'] ?? ''),
        'tb_akta_kematian',
        'tb_akta_kematian_rs' => (string)($row['nama_meninggal'] ?? ''),
        'tb_kartukeluarga',
        'tb_kartukeluarga_kua',
        'tb_pindah_alamat',
        'tb_pindah_online' => (string)($row['nama_kepalaKeluarga'] ?? ''),
        default => '',
    };
}

function dokumen_hasil_timestamp_from_row(array $row): string
{
    $raw = (string)($row['created_at'] ?? '');
    $timestamp = $raw !== '' ? strtotime($raw) : false;
    if ($timestamp === false) {
        $timestamp = time();
    }

    return date('Ymd_His', $timestamp);
}

function dokumen_hasil_slug_folder_segment(array $row, string $sourceTable, int $permohonanId): string
{
    if (empty($row)) {
        return 'permohonan_' . $permohonanId . '_' . date('Ymd_His');
    }

    $parts = [
        dokumen_hasil_slug_value((string)($row['nama_kecamatan'] ?? ''), 'kecamatan'),
        dokumen_hasil_slug_value((string)($row['nama_desa'] ?? ''), 'desa'),
        dokumen_hasil_slug_value((string)($row['nik_pemohon'] ?? ''), 'nik'),
        dokumen_hasil_slug_value((string)($row['nama_pemohon'] ?? ''), 'pemohon'),
        dokumen_hasil_slug_value(dokumen_hasil_subject_from_row($sourceTable, $row), 'subjek'),
        dokumen_hasil_timestamp_from_row($row),
    ];

    return implode('_', array_filter($parts));
}

function dokumen_hasil_folder_segment(mysqli $koneksi, string $sourceTable, int $permohonanId): string
{
    if (!dokumen_hasil_is_allowed_source_table($sourceTable) || $permohonanId <= 0) {
        return dokumen_hasil_slug_folder_segment([], $sourceTable, $permohonanId);
    }

    $stmt = $koneksi->prepare("SELECT * FROM `$sourceTable` WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return dokumen_hasil_slug_folder_segment([], $sourceTable, $permohonanId);
    }

    $stmt->bind_param('i', $permohonanId);
    if (!$stmt->execute()) {
        return dokumen_hasil_slug_folder_segment([], $sourceTable, $permohonanId);
    }

    $row = $stmt->get_result()->fetch_assoc();
    return dokumen_hasil_slug_folder_segment(is_array($row) ? $row : [], $sourceTable, $permohonanId);
}

function dokumen_hasil_ensure_storage_dir(string $sourceTable, string $folderSegment, ?string &$message = null): ?string
{
    if (!dokumen_hasil_is_allowed_source_table($sourceTable) || $folderSegment === '') {
        $message = 'Target dokumen hasil tidak valid.';
        return null;
    }

    $baseDir = dokumen_hasil_upload_root();
    $targetDir = $baseDir . DIRECTORY_SEPARATOR . $sourceTable . DIRECTORY_SEPARATOR . $folderSegment;

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        $message = 'Gagal membuat folder dokumen hasil.';
        return null;
    }

    $baseReal = realpath($baseDir);
    $targetReal = realpath($targetDir);
    if ($baseReal === false || $targetReal === false) {
        $message = 'Folder dokumen hasil tidak valid.';
        return null;
    }

    $basePrefix = rtrim(str_replace('\\', '/', $baseReal), '/') . '/';
    $targetNormalized = str_replace('\\', '/', $targetReal);
    if (strpos($targetNormalized, $basePrefix) !== 0) {
        $message = 'Folder dokumen hasil berada di luar direktori yang diizinkan.';
        return null;
    }

    return $targetReal;
}

function dokumen_hasil_build_relative_path(string $sourceTable, string $folderSegment, string $storedName): string
{
    return dokumen_hasil_relative_root() . '/' . $sourceTable . '/' . $folderSegment . '/' . $storedName;
}

function dokumen_hasil_upload_from_request(
    mysqli $koneksi,
    string $sourceTable,
    int $permohonanId,
    int $idUserPemohon,
    ?int $uploadedBy,
    ?string &$message = null,
    string $fieldName = DOKUMEN_HASIL_UPLOAD_FIELD
) {
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return [];
    }

    return dokumen_hasil_upload_files(
        $koneksi,
        $sourceTable,
        $permohonanId,
        $idUserPemohon,
        $uploadedBy,
        $_FILES[$fieldName],
        $message,
        $fieldName
    );
}

function dokumen_hasil_upload_files(
    mysqli $koneksi,
    string $sourceTable,
    int $permohonanId,
    int $idUserPemohon,
    ?int $uploadedBy,
    array $fileBag,
    ?string &$message = null,
    string $fieldName = DOKUMEN_HASIL_UPLOAD_FIELD
) {
    $sourceTable = trim($sourceTable);
    if (!dokumen_hasil_is_allowed_source_table($sourceTable)) {
        $message = 'Jenis permohonan tidak valid.';
        return false;
    }
    if ($permohonanId <= 0 || $idUserPemohon <= 0) {
        $message = 'Data permohonan tidak valid.';
        return false;
    }

    $folderSegment = dokumen_hasil_folder_segment($koneksi, $sourceTable, $permohonanId);
    $targetDir = dokumen_hasil_ensure_storage_dir($sourceTable, $folderSegment, $message);
    if ($targetDir === null) {
        return false;
    }

    $uploaded = [];
    $files = upload_security_flatten_files([$fieldName => $fileBag]);
    foreach ($files as $file) {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if (!upload_security_validate_pdf_only_file($file, $message)) {
            return false;
        }

        $originalName = dokumen_hasil_sanitize_original_name((string)$file['name']);
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $destination = $targetDir . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
            $message = 'Gagal menyimpan file PDF dokumen hasil.';
            return false;
        }
        @chmod($destination, 0644);

        $relativePath = dokumen_hasil_build_relative_path($sourceTable, $folderSegment, $storedName);
        $fileSize = (int)filesize($destination);
        $mimeType = 'application/pdf';
        $uploadedByParam = ($uploadedBy !== null && $uploadedBy > 0) ? $uploadedBy : null;

        $stmt = $koneksi->prepare(
            "INSERT INTO tb_permohonan_dokumen_hasil
             (source_table, permohonan_id, id_user_pemohon, uploaded_by, original_name, stored_name, file_path, file_size, mime_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            @unlink($destination);
            $message = 'Gagal menyiapkan penyimpanan dokumen hasil.';
            return false;
        }

        $stmt->bind_param(
            'siiisssis',
            $sourceTable,
            $permohonanId,
            $idUserPemohon,
            $uploadedByParam,
            $originalName,
            $storedName,
            $relativePath,
            $fileSize,
            $mimeType
        );

        if (!$stmt->execute()) {
            @unlink($destination);
            $message = 'Gagal menyimpan data dokumen hasil.';
            return false;
        }

        $uploaded[] = [
            'id' => (int)$stmt->insert_id,
            'source_table' => $sourceTable,
            'permohonan_id' => $permohonanId,
            'id_user_pemohon' => $idUserPemohon,
            'uploaded_by' => $uploadedByParam,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'file_path' => $relativePath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
        ];
    }

    return $uploaded;
}

function dokumen_hasil_list_for_permohonan(mysqli $koneksi, string $sourceTable, int $permohonanId): array
{
    if (!dokumen_hasil_is_enabled()) {
        return [];
    }

    if (!dokumen_hasil_is_allowed_source_table($sourceTable) || $permohonanId <= 0) {
        return [];
    }

    $stmt = $koneksi->prepare(
        "SELECT id, source_table, permohonan_id, id_user_pemohon, uploaded_by, original_name,
                stored_name, file_path, file_size, mime_type, uploaded_at
         FROM tb_permohonan_dokumen_hasil
         WHERE source_table = ? AND permohonan_id = ?
         ORDER BY uploaded_at DESC, id DESC"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('si', $sourceTable, $permohonanId);
    if (!$stmt->execute()) {
        return [];
    }

    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $row['download_url'] = dokumen_hasil_download_url((int)$row['id']);
        $items[] = $row;
    }

    return $items;
}

function dokumen_hasil_get_by_id(mysqli $koneksi, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $koneksi->prepare(
        "SELECT id, source_table, permohonan_id, id_user_pemohon, uploaded_by, original_name,
                stored_name, file_path, file_size, mime_type, uploaded_at
         FROM tb_permohonan_dokumen_hasil
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        return null;
    }

    $row = $stmt->get_result()->fetch_assoc();
    return is_array($row) ? $row : null;
}

function dokumen_hasil_download_url(int $id, string $basePath = '../download_dokumen_hasil.php'): string
{
    return $basePath . '?id=' . rawurlencode((string)$id);
}

function dokumen_hasil_delete_by_id(mysqli $koneksi, int $id, ?string &$message = null): bool
{
    $document = dokumen_hasil_get_by_id($koneksi, $id);
    if ($document === null) {
        $message = 'Dokumen hasil tidak ditemukan.';
        return false;
    }

    $targetPath = dokumen_hasil_resolve_file_path($document);

    $stmt = $koneksi->prepare("DELETE FROM tb_permohonan_dokumen_hasil WHERE id = ? LIMIT 1");
    if (!$stmt) {
        $message = 'Gagal menyiapkan penghapusan dokumen hasil.';
        return false;
    }

    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $message = 'Gagal menghapus data dokumen hasil.';
        return false;
    }

    if ($stmt->affected_rows < 1) {
        $message = 'Dokumen hasil tidak ditemukan.';
        return false;
    }

    if ($targetPath !== false && is_file($targetPath) && !@unlink($targetPath)) {
        $message = 'Data dokumen berhasil dihapus, tetapi file fisik gagal dihapus.';
        return false;
    }

    return true;
}

function dokumen_hasil_current_user_can_access(array $document): bool
{
    $currentUserId = (int)($_SESSION['id_user'] ?? 0);
    $role = (string)($_SESSION['role'] ?? '');
    if ($currentUserId <= 0) {
        return false;
    }
    if ($role === 'admin') {
        return true;
    }
    if ((int)($document['id_user_pemohon'] ?? 0) === $currentUserId) {
        return true;
    }
    if ((int)($document['uploaded_by'] ?? 0) === $currentUserId) {
        return true;
    }

    return false;
}

function dokumen_hasil_resolve_file_path(array $document)
{
    $relativePath = str_replace("\0", '', (string)($document['file_path'] ?? ''));
    $relativePath = str_replace('\\', '/', $relativePath);
    $relativePath = ltrim($relativePath, '/');

    $allowedPrefix = dokumen_hasil_relative_root() . '/';
    if (strpos($relativePath, $allowedPrefix) !== 0 || strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) !== 'pdf') {
        return false;
    }

    $root = realpath(dokumen_hasil_upload_root());
    $candidate = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath);
    if ($root === false || $candidate === false || !is_file($candidate)) {
        return false;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $candidateNormalized = str_replace('\\', '/', $candidate);
    if (strpos($candidateNormalized, $rootPrefix) !== 0) {
        return false;
    }

    return $candidate;
}

function dokumen_hasil_send_download(array $document): void
{
    $targetPath = dokumen_hasil_resolve_file_path($document);
    if ($targetPath === false) {
        http_response_code(404);
        exit('Dokumen hasil tidak ditemukan.');
    }

    $downloadName = dokumen_hasil_sanitize_original_name((string)($document['original_name'] ?? 'dokumen_hasil.pdf'));
    if (strtolower(pathinfo($downloadName, PATHINFO_EXTENSION)) !== 'pdf') {
        $downloadName .= '.pdf';
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Content-Length: ' . filesize($targetPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    if (ob_get_level()) {
        ob_end_clean();
    }
    readfile($targetPath);
    exit;
}

function dokumen_hasil_download_by_id(mysqli $koneksi, int $id, ?callable $accessCallback = null): void
{
    if (!dokumen_hasil_is_enabled()) {
        http_response_code(404);
        exit('Fitur dokumen hasil sedang dinonaktifkan.');
    }

    $document = dokumen_hasil_get_by_id($koneksi, $id);
    if ($document === null) {
        http_response_code(404);
        exit('Dokumen hasil tidak ditemukan.');
    }

    $allowed = dokumen_hasil_current_user_can_access($document);
    if (!$allowed && $accessCallback !== null) {
        $allowed = (bool)$accessCallback($document);
    }

    if (!$allowed) {
        http_response_code(403);
        exit('Akses dokumen hasil ditolak.');
    }

    dokumen_hasil_send_download($document);
}
