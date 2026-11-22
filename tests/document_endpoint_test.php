<?php
declare(strict_types=1);
require __DIR__.'/../public/functions/document_token.php';
$public = realpath(__DIR__.'/../public');
foreach (['', '/public', '/public_html/public', '/nested/siprima/public'] as $mount) {
    foreach (['user/proses_pengajuan.php', 'user_kua/kua_proses_pengajuan.php', 'document.php'] as $script) {
        $_SERVER['SCRIPT_FILENAME'] = $public.'/'.$script;
        $_SERVER['SCRIPT_NAME'] = $mount.'/'.$script;
        if (siprima_document_endpoint() !== $mount.'/document.php') {
            throw new RuntimeException('Document endpoint does not preserve mount path');
        }
    }
}
echo "PASS 12 document endpoint root/subfolder cases\n";