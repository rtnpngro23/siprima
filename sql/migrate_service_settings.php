<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/config/service_hours.php';
require_once __DIR__ . '/../app/config/env_loader.php';
loadEnv(__DIR__ . '/../app/config/.env');
if (!in_array((string)env('DB_HOST', 'localhost'), ['localhost','127.0.0.1','::1'], true)) {
    fwrite(STDERR, "Migration not run: configured database is not local.\n"); exit(1);
}
$db = service_hours_db();
$sql = file_get_contents(__DIR__ . '/20260906_service_settings.sql');
if (!$db->multi_query($sql)) throw new RuntimeException('Migration failed.');
do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
if ($db->errno) throw new RuntimeException('Migration failed.');
$settings = service_hours_settings();
if (!empty($settings['unavailable'])) throw new RuntimeException('Settings verification failed.');
echo "Local migration complete; settings loaded; routine restriction " . ($settings['enabled'] ? 'enabled' : 'disabled') . ".\n";