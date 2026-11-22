<?php
require_once '../error_handler.php';
// get_desa.php
include '../../app/config/koneksi.php';

// Tangkap parameter - dukung kedua variasi nama query parameter
$kecamatan_id = 0;
if (isset($_GET['kecamatan_id'])) {
    $kecamatan_id = (int)$_GET['kecamatan_id'];
} elseif (isset($_GET['id_kecamatan'])) {
    $kecamatan_id = (int)$_GET['id_kecamatan'];
}

if ($kecamatan_id <= 0) {
    echo '<option value="">Pilih Kecamatan terlebih dahulu</option>';
    exit;
}

// Query database
$query = $koneksi->prepare("SELECT id, nama_desa FROM tb_desa WHERE kecamatan_id = ? ORDER BY nama_desa");
if (!$query) {
    error_log('get_desa prepare gagal: ' . $koneksi->error);
    echo '<option value="">Gagal memuat data desa</option>';
    exit;
}

$query->bind_param('i', $kecamatan_id);

if (!$query->execute()) {
    error_log('get_desa execute gagal: ' . $query->error);
    $query->close();
    echo '<option value="">Gagal memuat data desa</option>';
    exit;
}

$result = $query->get_result();
$row_count = $result->num_rows;

if ($row_count === 0) {
    echo '<option value="">Tidak ada desa ditemukan untuk kecamatan ini</option>';
    $query->close();
    exit;
}

// Output options
while ($row = $result->fetch_assoc()) {
    $id = $row['id'];
    $nama_desa = htmlspecialchars($row['nama_desa'], ENT_QUOTES);
    echo "<option value=\"$id\">$nama_desa</option>";
}

$query->close();
?>