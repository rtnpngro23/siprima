<?php
// Pratinjau lembar data setara F-1.01 (Kartu Keluarga) sebelum permohonan dikirim.
// POST-only agar validasi CSRF global di error_handler.php ikut berlaku.
// Tidak pernah menulis ke disk dan tidak pernah menyentuh database permohonan.
// Hanya HALAMAN PERTAMA yang dikembalikan; jumlah halaman dikirim lewat header X-Total-Halaman.
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
require_once __DIR__ . '/../functions/formulir_f101.php';
require_once __DIR__ . '/../../app/config/document_features.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

function pratinjau_kk_gagal(int $status, array $pesan): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['errors' => array_values($pesan)], JSON_UNESCAPED_UNICODE) ?: '{"errors":[]}';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pratinjau_kk_gagal(405, ['Metode tidak diizinkan.']);
}
if (!FORMULIR_F101_AUTO_ENABLED) {
    pratinjau_kk_gagal(404, ['Pratinjau formulir belum tersedia.']);
}
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    pratinjau_kk_gagal(401, ['Sesi Anda telah berakhir. Silakan masuk kembali.']);
}

// Pembatasan laju per sesi: render memakai ~28 MB per halaman.
$now = time();
$kuota = $_SESSION['f101_preview'] ?? ['terakhir' => 0, 'mulai_jendela' => $now, 'jumlah' => 0];
if (!is_array($kuota)) $kuota = ['terakhir' => 0, 'mulai_jendela' => $now, 'jumlah' => 0];
if ($now - (int)($kuota['mulai_jendela'] ?? 0) >= 3600) {
    $kuota = ['terakhir' => (int)($kuota['terakhir'] ?? 0), 'mulai_jendela' => $now, 'jumlah' => 0];
}
if ($now - (int)($kuota['terakhir'] ?? 0) < 2) {
    pratinjau_kk_gagal(429, ['Mohon tunggu sebentar sebelum meminta pratinjau lagi.']);
}
if ((int)($kuota['jumlah'] ?? 0) >= 60) {
    pratinjau_kk_gagal(429, ['Batas pratinjau per jam tercapai. Silakan coba lagi nanti.']);
}
$kuota['terakhir'] = $now;
$kuota['jumlah'] = (int)($kuota['jumlah'] ?? 0) + 1;
$_SESSION['f101_preview'] = $kuota;

$user_id = (int)$_SESSION['id_user'];
$stmt_user = $koneksi->prepare("SELECT nama, nik FROM tb_users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$data_user = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();
if (!$data_user) {
    pratinjau_kk_gagal(401, ['Data akun tidak ditemukan. Silakan masuk kembali.']);
}

$errors = [];

// Isian inti disyaratkan sama seperti di kk.php agar pratinjau tidak memperlihatkan
// dokumen yang nantinya ditolak saat dikirim.
$dusun = trim($_POST['dusun'] ?? '');
$nama_kepalaKeluarga = trim($_POST['nama_kepalaKeluarga'] ?? '');
$id_kecamatan = isset($_POST['id_kecamatan']) ? (int)$_POST['id_kecamatan'] : 0;
$id_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : 0;

if ($dusun === '') $errors[] = 'Alamat/Dusun wajib diisi.';
if ($nama_kepalaKeluarga === '') $errors[] = 'Nama Kepala Keluarga wajib diisi.';
if ($id_kecamatan <= 0) $errors[] = 'Kecamatan wajib dipilih.';
if ($id_desa <= 0) $errors[] = 'Desa wajib dipilih.';
if (empty($_POST['pernyataan_benar'])) {
    $errors[] = 'Centang pernyataan kebenaran data terlebih dahulu.';
}

$fields = null;
$errors = array_merge($errors, siprima_f101_validate_fields($_POST, $fields));

$anggota = null;
$errors = array_merge($errors, siprima_f101_validate_anggota($_POST, $anggota));

$ttd = null;
$pesanTtd = siprima_formulir_validate_signature((string)($_POST['ttd_pemohon'] ?? ''), $ttd);
if ($pesanTtd !== '') $errors[] = $pesanTtd;

if (!empty($errors)) {
    pratinjau_kk_gagal(422, $errors);
}

$nama_kecamatan = '';
$nama_desa = '';
$qk = $koneksi->prepare("SELECT nama_kecamatan FROM tb_kecamatan WHERE id = ?");
$qk->bind_param("i", $id_kecamatan);
$qk->execute();
$rk = $qk->get_result()->fetch_assoc();
if ($rk) $nama_kecamatan = (string)$rk['nama_kecamatan'];
$qk->close();

$qd = $koneksi->prepare("SELECT nama_desa FROM tb_desa WHERE id = ?");
$qd->bind_param("i", $id_desa);
$qd->execute();
$rd = $qd->get_result()->fetch_assoc();
if ($rd) $nama_desa = (string)$rd['nama_desa'];
$qd->close();

$halaman = [];
try {
    $halaman = siprima_f101_render(
        siprima_f101_data_kartu_keluarga($_POST, (array)$fields, (array)$anggota, $data_user,
            $nama_kecamatan, $nama_desa, date('Y-m-d H:i:s')),
        (string)$ttd
    );
} catch (Throwable $e) {
    error_log('Pratinjau formulir F-1.01 gagal: ' . $e->getMessage());
    pratinjau_kk_gagal(500, ['Pratinjau formulir belum dapat dibuat. Silakan coba lagi.']);
}

if (!$halaman) {
    pratinjau_kk_gagal(500, ['Pratinjau formulir belum dapat dibuat. Silakan coba lagi.']);
}

header('Content-Type: image/jpeg');
header('Content-Disposition: inline; filename="pratinjau_formulir_kk.jpg"');
header('X-Total-Halaman: ' . count($halaman));
header('Content-Length: ' . strlen($halaman[0]));
echo $halaman[0];
