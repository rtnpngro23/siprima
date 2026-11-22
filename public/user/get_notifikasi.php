<?php
require_once '../error_handler.php';
include '../../app/config/koneksi.php';
require_once __DIR__ . '/../functions/notifikasi.php';

date_default_timezone_set('Asia/Makassar');

// Endpoint dipanggil lewat fetch, sehingga harus membuka sesi sendiri.
// error_handler hanya mengatur keamanan cookie dan tidak otomatis memulai sesi pada GET.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Pastikan user sudah login
if (empty($_SESSION['id_user'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Sesi login tidak valid.']);
    exit;
}

$user_id = (int)$_SESSION['id_user'];

// CSRF untuk POST divalidasi otomatis oleh error_handler.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'mark_seen') {
        $success = tandaiNotifikasiDilihat($koneksi, $user_id, (int)($_POST['max_id'] ?? 0));
    } elseif ($action === 'mark_read') {
        $success = tandaiNotifikasiDibaca($koneksi, $user_id, (int)($_POST['id'] ?? 0));
    } else {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Aksi tidak valid.']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'unread_count' => jumlahNotifikasiBelumDilihat($koneksi, $user_id)]);
    exit;
}

$scope = (string)($_GET['scope'] ?? 'user');
if (!in_array($scope, ['user', 'user_kua', 'user_rs', 'user_ahlak'], true)) $scope = 'user';
$halaman = daftarNotifikasi($koneksi, $user_id, 10, (int)($_GET['before_id'] ?? 0), (string)($_SESSION['role'] ?? ''), $scope);
foreach ($halaman['items'] as &$row) {
    $row['waktu_relatif'] = formatWaktuRelatif($row['created_at']);
}
unset($row);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode([
    'success' => true,
    'unread_count' => jumlahNotifikasiBelumDilihat($koneksi, $user_id),
    'items' => $halaman['items'],
    'has_more' => $halaman['has_more'],
]);
exit;

// Fungsi bantu untuk waktu relatif (baru saja, 5 menit lalu, dll)
function formatWaktuRelatif($datetime) {
    $timezone = new DateTimeZone('Asia/Makassar');
    $now = new DateTime('now', $timezone);
    $past = new DateTime($datetime, $timezone);
    if ($past > $now) {
        return 'Baru saja';
    }
    $interval = $now->diff($past);

    if ($interval->y > 0) return $interval->y . ' tahun lalu';
    if ($interval->m > 0) return $interval->m . ' bulan lalu';
    if ($interval->d > 0) return $interval->d . ' hari lalu';
    if ($interval->h > 0) return $interval->h . ' jam lalu';
    if ($interval->i > 0) return $interval->i . ' menit lalu';
    return 'Baru saja';
}
?>
