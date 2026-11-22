<?php
declare(strict_types=1);

// Kategori layanan Kartu Keluarga. Semua kategori memakai tabel tb_kartukeluarga yang sama dan
// dibedakan lewat kolom `kategori_layanan`; berkas lama tanpa kolom ini bernilai default 'kk_baru'
// sehingga perilaku permohonan lama tidak berubah.

const KK_KATEGORI_DEFAULT = 'kk_baru';

function siprima_kk_kategori_options(): array
{
    return [
        'kk_baru'        => 'Permohonan Kartu Keluarga Baru',
        'kk_hilang'      => 'Kartu Keluarga Hilang (Tanpa Perubahan Data)',
        'cetak_biodata'  => 'Cetak Biodata (Pengganti KTP Sementara)',
        'perubahan_data' => 'Perubahan Data Kartu Keluarga',
    ];
}

function siprima_kk_kategori_label(?string $kategori): string
{
    return siprima_kk_kategori_options()[siprima_kk_kategori_normalize($kategori)];
}

// Nilai dari URL/POST/DB tidak pernah dipercaya: apa pun di luar daftar (termasuk array dari
// ?jenis[]=...) dikembalikan ke default.
function siprima_kk_kategori_normalize($raw): string
{
    $raw = is_string($raw) ? trim($raw) : '';
    return isset(siprima_kk_kategori_options()[$raw]) ? $raw : KK_KATEGORI_DEFAULT;
}

/**
 * Isian dan dokumen wajib untuk form ringkas (kk_layanan.php). Kategori kk_baru tidak punya
 * konfigurasi di sini karena memakai form F-1.01 di kk.php.
 * Kunci 'wajib' berurutan sesuai tampilan; tiap tipe dokumen wajib ada minimal satu file.
 */
function siprima_kk_layanan_config(string $kategori): ?array
{
    $config = [
        'kk_hilang' => [
            'label_nama' => 'Nama Kepala Keluarga',
            'label_nik' => 'NIK Kepala Keluarga',
            'keterangan_wajib' => false,
            'placeholder_keterangan' => 'Contoh: KK hilang saat pindah rumah',
            'wajib' => [
                'suket_kehilangan_kk' => 'Surat Keterangan Hilang KK',
                'foto_selfie' => 'Foto Selfie Memegang Surat Keterangan Hilang KK',
            ],
        ],
        'cetak_biodata' => [
            'label_nama' => 'Nama Lengkap',
            'label_nik' => 'NIK',
            'keterangan_wajib' => false,
            'placeholder_keterangan' => 'Contoh: KTP hilang, biodata dipakai sementara untuk keperluan bank',
            'wajib' => [
                'kk_ktp_hilang' => 'KK / KTP / Surat Keterangan Hilang KTP',
                'foto_selfie' => 'Foto Selfie Memegang KK / KTP / Surat Keterangan Hilang KTP',
            ],
        ],
        'perubahan_data' => [
            'label_nama' => 'Nama Kepala Keluarga',
            'label_nik' => 'NIK Anggota yang Datanya Diubah',
            'keterangan_wajib' => true,
            'placeholder_keterangan' => 'Sebutkan data yang diubah, contoh: Pendidikan terakhir SMA menjadi S1',
            'wajib' => [
                'formulir_f106' => 'Formulir F-1.06 SPTJM Perubahan Data',
                'kk_lama_atau_surat_kehilangan' => 'KK / Surat Keterangan Hilang KK',
                'lainnya' => 'Dokumen Pendukung Perubahan (Ijazah, Buku Nikah, dll.)',
            ],
        ],
    ];
    return $config[$kategori] ?? null;
}

// Validasi isian teks form ringkas; dipakai bersama oleh form pengajuan dan form edit.
function siprima_kk_layanan_validasi(array $config, string $nama, string $nik, string $keterangan): array
{
    $errors = [];
    if ($nama === '') {
        $errors[] = $config['label_nama'] . ' wajib diisi.';
    } elseif (mb_strlen($nama, 'UTF-8') > 100) {
        $errors[] = $config['label_nama'] . ' maksimal 100 karakter.';
    }
    if (!preg_match('/^[0-9]{16}$/', $nik)) $errors[] = $config['label_nik'] . ' harus 16 digit angka.';
    if ($config['keterangan_wajib'] && $keterangan === '') $errors[] = 'Keterangan data yang diubah wajib diisi.';
    if (mb_strlen($keterangan, 'UTF-8') > 500) $errors[] = 'Keterangan maksimal 500 karakter.';
    return $errors;
}

/**
 * Menyimpan unggahan $_FILES['dokumen'] ke folder permohonan. Tipe dokumen berasal dari klien,
 * jadi dibatasi pada dokumen wajib kategori + 'lainnya'; tipe lain dicatat sebagai 'lainnya'.
 * Membutuhkan upload_compat.php. Pesan gagal ditambahkan ke $errors.
 */
function siprima_kk_layanan_simpan_unggahan(string $kategori, array $docTypes, string $folderAbs, string $folderRel, array &$errors): array
{
    $files = $_FILES['dokumen'] ?? null;
    if (!is_array($files) || !is_array($files['name'] ?? null)) return [];

    if (!is_dir($folderAbs) && !mkdir($folderAbs, 0755, true)) {
        $errors[] = 'Gagal membuat folder penyimpanan dokumen.';
        return [];
    }
    if (!file_exists($folderAbs . '.htaccess')) {
        file_put_contents($folderAbs . '.htaccess',
            "Order Deny,Allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|gif)$\">\nAllow from all\n</Files>");
    }

    $izin = array_keys(siprima_kk_layanan_config($kategori)['wajib'] ?? []);
    $izin[] = 'lainnya';
    $hasil = [];
    foreach ($files['name'] as $i => $nama) {
        $kode = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($kode === UPLOAD_ERR_NO_FILE) continue;
        $fileName = basename((string)$nama);
        if ($kode !== UPLOAD_ERR_OK) {
            $errors[] = "File {$fileName}: " . siprima_upload_error_message($kode);
            continue;
        }

        $docType = (string)($docTypes[$i] ?? 'lainnya');
        if (!in_array($docType, $izin, true)) $docType = 'lainnya';
        $fileSize = (int)$files['size'][$i];
        $fileType = '';
        $ext = '';
        $pesan = siprima_validate_uploaded_image($files['tmp_name'][$i], $fileName, $fileSize, 1024 * 1024, $fileType, $ext);
        if ($pesan !== '') {
            $errors[] = $pesan;
            continue;
        }

        $newName = $docType . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$i], $folderAbs . $newName)) {
            $errors[] = "File {$fileName}: Gagal menyimpan file";
            continue;
        }
        $hasil[] = [
            'path' => $folderRel . $newName,
            'type' => $docType,
            'original_name' => $fileName,
            'uploaded_name' => $newName,
            'size' => $fileSize,
            'uploaded_at' => date('Y-m-d H:i:s'),
        ];
    }
    return $hasil;
}

// Label dokumen wajib yang belum ada di daftar dokumen final (lama yang dipertahankan + baru).
function siprima_kk_layanan_dokumen_kurang(string $kategori, array $dokumen): array
{
    $ada = array_column($dokumen, 'type');
    $kurang = [];
    foreach (siprima_kk_layanan_config($kategori)['wajib'] ?? [] as $type => $label) {
        if (!in_array($type, $ada, true)) $kurang[] = $label;
    }
    return $kurang;
}

// Label per kategori untuk modal detail di halaman status (dikirim ke JavaScript sebagai JSON).
function siprima_kk_kategori_untuk_js(): array
{
    $hasil = [];
    foreach (siprima_kk_kategori_options() as $kode => $label) {
        $config = siprima_kk_layanan_config($kode);
        $hasil[$kode] = [
            'label' => $label,
            'label_nama' => $config['label_nama'] ?? 'Nama Kepala Keluarga',
            'label_nik' => $config['label_nik'] ?? '',
        ];
    }
    return $hasil;
}

function siprima_kk_dokumen_label(string $kategori, string $type): string
{
    $wajib = siprima_kk_layanan_config($kategori)['wajib'] ?? [];
    return $wajib[$type] ?? ($type === 'lainnya' ? 'Dokumen Pendukung' : ucwords(str_replace('_', ' ', $type)));
}

// Tautan unduh F-1.06 diambil dari daftar formulir admin agar tetap benar saat file diganti;
// path cadangan adalah file yang terdaftar saat fitur ini dibuat.
function siprima_kk_link_f106(mysqli $koneksi): string
{
    $path = 'uploads/formulir/1766758427_694e981b40966.docx';
    $pola = 'F-1.06%';
    $stmt = $koneksi->prepare('SELECT file_formulir FROM tb_formulir WHERE nama_formulir LIKE ? ORDER BY id DESC LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $pola);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['file_formulir'])) $path = (string)$row['file_formulir'];
    }
    return '../' . ltrim($path, '/');
}
