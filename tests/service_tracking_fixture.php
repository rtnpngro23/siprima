<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/^siprima_tracking_test_[a-f0-9]{8}$/D', $input['database'] ?? '')) exit(2);
require_once __DIR__ . '/../app/config/env_loader.php';
loadEnv(__DIR__ . '/../app/config/.env');
if (!in_array((string)env('DB_HOST', 'localhost'), ['localhost', '127.0.0.1', '::1'], true)) exit(2);
$db = new mysqli((string)env('DB_HOST', 'localhost'), (string)env('DB_USER', 'root'), (string)env('DB_PASS', ''), $input['database']);
$db->set_charset('utf8mb4');
$_SESSION = ['id_user' => 7, 'role' => $input['role'] ?? 'operator', 'nama' => 'Petugas <uji>'];
$_SERVER['SCRIPT_NAME'] = '/user/proses_pengajuan.php';
$_POST = $input['post'];
function dokumen_hasil_has_uploaded_files(): bool { global $input; return !empty($input['upload']); }
function dokumen_hasil_upload_from_request($db, $table, $id, $user, $operator, &$error) {
    global $input;
    if ($input['upload'] === 'fail') { $error = 'Berkas uji gagal.'; return false; }
    return [['id' => 1]];
}
ob_start();
register_shutdown_function(function () {
    $body = ob_get_contents(); while (ob_get_level()) ob_end_clean();
    echo json_encode(['code' => http_response_code() ?: 200, 'body' => json_decode($body, true)]);
});
require_once __DIR__ . '/../public/user/service_tracking.php';
serviceTrackingUpdate($db, serviceTrackingTables(), static fn(array $record): bool => $record['nama_kecamatan'] === 'Wilayah Uji');
