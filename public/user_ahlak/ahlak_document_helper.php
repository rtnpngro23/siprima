<?php
declare(strict_types=1);
require_once __DIR__ . '/../functions/document_token.php';

function ahlak_document_url(string $relativePath): ?string
{
    return siprima_document_token_url(__DIR__, $relativePath);
}

function ahlak_mask_document_json(?string $json): string
{
    $documents = json_decode((string)$json, true);
    if (!is_array($documents)) {
        return '[]';
    }

    return siprima_mask_document_json(__DIR__, $json);
}
