<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once __DIR__.'/app/Api/Maintenance.php';
try {
    $db=Siprima\Api\database();
    $delivered=Siprima\Api\Maintenance::outbox($db);
    $removed=Siprima\Api\Maintenance::cleanup($db);
    echo "Outbox delivered: $delivered; expired temporary uploads removed: $removed\n";
} catch(Throwable $e) { fwrite(STDERR,"Mobile API maintenance failed; pending items retained for retry.\n");exit(1); }
