<?php
declare(strict_types=1);

function siprima_document_actor_signature(): string
{
    $identity = [
        'session_id' => session_id(),
        'id_user' => (string)($_SESSION['id_user'] ?? ''),
        'role' => (string)($_SESSION['role'] ?? ''),
        'user_type' => (string)($_SESSION['user_type'] ?? ''),
        'id_desa' => (string)($_SESSION['id_desa'] ?? ''),
        'id_rs' => (string)($_SESSION['id_rs'] ?? ''),
        'id_kua' => (string)($_SESSION['id_kua'] ?? ''),
        'id_puskes' => (string)($_SESSION['id_puskes'] ?? ''),
    ];

    return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES) ?: '');
}

function siprima_document_resolve_path(string $baseDirectory, string $storedPath): ?array
{
    $root = realpath(rtrim($baseDirectory, '/\\') . '/uploads');
    if ($root === false) return null;

    $path = trim(str_replace('\\', '/', str_replace("\0", '', $storedPath)));
    if ($path === '') return null;
    if (preg_match('#^https?://#i', $path) === 1) {
        $path = (string)(parse_url($path, PHP_URL_PATH) ?? '');
    } else {
        $path = (string)(parse_url($path, PHP_URL_PATH) ?? $path);
    }
    $path = rawurldecode(ltrim($path, '/'));
    $path = preg_replace('#^(?:\./)+#', '', $path) ?? $path;

    $module = preg_quote(basename(str_replace('\\', '/', rtrim($baseDirectory, '/\\'))), '#');
    $path = preg_replace('#^(?:siprima/)?(?:public/)?(?:' . $module . '/)?uploads/#i', '', $path, 1, $matched) ?? $path;
    if (($matched ?? 0) !== 1 || $path === '' || str_contains($path, '../')) return null;

    $file = realpath(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $extension = strtolower(pathinfo((string)$file, PATHINFO_EXTENSION));
    if ($file === false || !is_file($file)
        || strncmp($file, $prefix, strlen($prefix)) !== 0
        || !in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'jfif', 'jpe', 'png', 'gif'], true)) {
        return null;
    }

    return ['file' => $file, 'root' => $root, 'extension' => $extension];
}

// Preserve the application's mount path both at the domain root and in subfolders.
function siprima_document_endpoint(): string
{
    $publicRoot = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $scriptFile = str_replace('\\', '/', (string)realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($publicRoot !== '' && str_starts_with($scriptFile, $publicRoot . '/')) {
        $relativeScript = substr($scriptFile, strlen($publicRoot));
        if (str_ends_with($scriptName, $relativeScript)) {
            return substr($scriptName, 0, -strlen($relativeScript)) . '/document.php';
        }
    }
    return '/document.php';
}
function siprima_document_token_url(string $baseDirectory, string $relativePath): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) return null;
    $resolved = siprima_document_resolve_path($baseDirectory, $relativePath);
    if ($resolved === null) return null;

    $now = time();
    $storedTokens = $_SESSION['document_tokens'] ?? [];
    if (!is_array($storedTokens)) $storedTokens = [];
    $tokens = array_filter($storedTokens, static fn($item) => is_array($item) && ($item['expires'] ?? 0) >= $now);
    if (count($tokens) >= 100) $tokens = array_slice($tokens, -80, null, true);
    $token = bin2hex(random_bytes(18));
    $tokens[$token] = [
        'path' => $resolved['file'],
        'root' => $resolved['root'],
        'name' => basename($resolved['file']),
        'actor' => siprima_document_actor_signature(),
        'expires' => $now + 900,
    ];
    $_SESSION['document_tokens'] = $tokens;
    return siprima_document_endpoint() . '?t=' . $token . '&f=document.' . $resolved['extension'];
}

function siprima_mask_document_json(string $baseDirectory, ?string $json): string
{
    $documents = json_decode((string)$json, true);
    if (!is_array($documents)) {
        $storedPath = trim((string)$json);
        if ($storedPath === '' || in_array(strtolower($storedPath), ['null', '[]'], true)) return '[]';
        $documents = [[
            'path' => $storedPath,
            'type' => 'dokumen',
            'original_name' => basename((string)(parse_url($storedPath, PHP_URL_PATH) ?? $storedPath)),
        ]];
    } elseif (isset($documents['path'])) {
        $documents = [$documents];
    }
    foreach ($documents as &$document) {
        if (!is_array($document)) continue;
        $url = siprima_document_token_url($baseDirectory, (string)($document['path'] ?? '')) ?? '';
        $document['path'] = $url;
        $document['full_url'] = $url;
        $document['download_url'] = $url === '' ? '' : $url . '&download=1';
    }
    unset($document);
    return json_encode($documents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
}
