<?php
// Pratinjau lembar data setara F-2.01 (Akta Kematian) sebelum permohonan dikirim.
// POST-only agar validasi CSRF global di error_handler.php ikut berlaku.
// Tidak pernah menulis ke disk dan tidak pernah menyentuh database permohonan.
require_once '../error_handler.php';
include '../../app/middleware/user_only.php';
service_hours_guard_submission();
require_once __DIR__ . '/../functions/formulir_f201_kematian.php';
require_once __DIR__ . '/../../app/config/document_features.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

function pratinjau_kematian_gagal(int $status, array $pesan): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['errors' => array_values($pesan)], JSON_UNESCAPED_UNICODE) ?: '{"errors":[]}';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pratinjau_kematian_gagal(405, ['Metode tidak diizinkan.']);
}
if (!FORMULIR_F201_KEMATIAN_AUTO_ENABLED) {
    pratinjau_kematian_gagal(404, ['Pratinjau formulir belum tersedia.']);
}
if (!isset($_SESSION['id_user']) || empty($_SESSION['id_user'])) {
    pratinjau_kematian_gagal(401, ['Sesi Anda telah berakhir. Silakan masuk kembali.']);
}

// Pembatasan laju per sesi: render memakai ~26 MB, jangan sampai jadi alat pemborosan memori.
$now = time();
$kuota = $_SESSION['f201_kematian_preview'] ?? ['terakhir' => 0, 'mulai_jendela' => $now, 'jumlah' => 0];
if (!is_array($kuota)) $kuota = ['terakhir' => 0, 'mulai_jendela' => $now, 'jumlah' => 0];
if ($now - (int)($kuota['mulai_jendela'] ?? 0) >= 3600) {
    $kuota = ['terakhir' => (int)($kuota['terakhir'] ?? 0), 'mulai_jendela' => $now, 'jumlah' => 0];
}
if ($now - (int)($kuota['terakhir'] ?? 0) < 2) {
    pratinjau_kematian_gagal(429, ['Mohon tunggu sebentar sebelum meminta pratinjau lagi.']);
}
if ((int)($kuota['jumlah'] ?? 0) >= 60) {
    pratinjau_kematian_gagal(429, ['Batas pratinjau per jam tercapai. Silakan coba lagi nanti.']);
}
$kuota['terakhir'] = $now;
$kuota['jumlah'] = (int)($kuota['jumlah'] ?? 0) + 1;
$_SESSION['f201_kematian_preview'] = $kuota;

$user_id = (int)$_SESSION['id_user'];
$stmt_user = $koneksi->prepare("SELECT nama, nik FROM tb_users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$data_user = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();
if (!$data_user) {
    pratinjau_kematian_gagal(401, ['Data akun tidak ditemukan. Silakan masuk kembali.']);
}

$errors = [];

// Halaman ajuan memakai field `tempat`, halaman edit memakai `tempat_kematian`.
$postKematian = $_POST;
if (!isset($postKematian['tempat']) && isset($_POST['tempat_kematian'])) {
    $postKematian['tempat'] = $_POST['tempat_kematian'];
}

// Isian inti disyaratkan sama seperti di akta_kematian.php/edit_permohonan_akta_kematian.php agar
// pratinjau tidak memperlihatkan dokumen yang nantinya ditolak saat dikirim.
$nama_meninggal = trim($_POST['nama_meninggal'] ?? '');
$tanggal_kematian = trim($_POST['tanggal_kematian'] ?? '');
$nama_ayah = trim($_POST['nama_ayah'] ?? '');
$nik_ayah = trim($_POST['nik_ayah'] ?? '');
$nama_ibu = trim($_POST['nama_ibu'] ?? '');
$nik_ibu = trim($_POST['nik_ibu'] ?? '');
$nama_saksi1 = trim($_POST['nama_saksi1'] ?? '');
$nik_saksi1 = trim($_POST['nik_saksi1'] ?? '');
$nama_saksi2 = trim($_POST['nama_saksi2'] ?? '');
$nik_saksi2 = trim($_POST['nik_saksi2'] ?? '');
$id_kecamatan = isset($_POST['id_kecamatan']) ? (int)$_POST['id_kecamatan'] : 0;
$id_desa = isset($_POST['id_desa']) ? (int)$_POST['id_desa'] : 0;

if ($nama_meninggal === '') $errors[] = 'Nama yang meninggal wajib diisi.';
if ($tanggal_kematian === '') $errors[] = 'Tanggal kematian wajib diisi.';
if ($nama_ayah === '') $errors[] = 'Nama ayah wajib diisi.';
if (strlen($nik_ayah) !== 16) $errors[] = 'NIK ayah harus 16 digit.';
if ($nama_ibu === '') $errors[] = 'Nama ibu wajib diisi.';
if (strlen($nik_ibu) !== 16) $errors[] = 'NIK ibu harus 16 digit.';
if ($nama_saksi1 === '') $errors[] = 'Nama saksi 1 wajib diisi.';
if (strlen($nik_saksi1) !== 16) $errors[] = 'NIK saksi 1 harus 16 digit.';
// Berbeda dari F-2.01 kelahiran: saksi 2 wajib diisi (nama + NIK), bukan opsional.
if ($nama_saksi2 === '') $errors[] = 'Nama saksi 2 wajib diisi.';
if (strlen($nik_saksi2) !== 16) $errors[] = 'NIK saksi 2 harus 16 digit.';
if ($id_kecamatan <= 0) $errors[] = 'Kecamatan wajib dipilih.';
if ($id_desa <= 0) $errors[] = 'Desa wajib dipilih.';
if (empty($_POST['pernyataan_benar'])) {
    $errors[] = 'Centang pernyataan kebenaran data terlebih dahulu.';
}

$ttd = null;
$pesanTtd = siprima_formulir_validate_signature((string)($_POST['ttd_pemohon'] ?? ''), $ttd);
if ($pesanTtd !== '') $errors[] = $pesanTtd;

if (!empty($errors)) {
    pratinjau_kematian_gagal(422, $errors);
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

$jpeg = '';
try {
    $jpeg = siprima_f201_kematian_render(
        siprima_f201_kematian_data($postKematian, $data_user, $nama_kecamatan, $nama_desa, date('Y-m-d H:i:s')),
        (string)$ttd
    );
} catch (Throwable $e) {
    error_log('Pratinjau formulir F-2.01 Kematian gagal: ' . $e->getMessage());
    pratinjau_kematian_gagal(500, ['Pratinjau formulir belum dapat dibuat. Silakan coba lagi.']);
}

header('Content-Type: image/jpeg');
header('Content-Disposition: inline; filename="pratinjau_formulir_kematian.jpg"');
header('Content-Length: ' . strlen($jpeg));
echo $jpeg;
