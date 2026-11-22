<?php
// Shared by browser and mobile. Nilai diatur dari Admin -> Manajemen Formulir Digital
// (tabel tb_document_features). Daftar di bawah dipakai sebagai cadangan ketika tabel
// pengaturan belum dimigrasi atau tidak dapat dibaca, sehingga perilaku aplikasi tidak berubah.
function document_features_defaults(): array
{
    return [
        // Fitur dokumen hasil operator dibiarkan nonaktif seperti konfigurasi sebelumnya.
        'dokumen_hasil' => false,
        // Pilot browser Kartu Keluarga. Catatan konfigurasi lama: app/Api/Catalog.php only()
        // menolak kunci tak dikenal dengan 422, sehingga permohonan KK yang dibuat lewat web
        // bisa tidak dapat diperbaiki dari aplikasi Android sampai field per-anggota diterima.
        'formulir_f101' => true,
        // Pilot browser F-2.01 kelahiran. Klien Android tetap mengunggah `formulir` manual.
        'formulir_f201_kelahiran' => true,
        // Pilot browser F-2.01 kematian. Catalog.php sudah mewajibkan nama/nik saksi 1 dan 2
        // untuk layanan 'kematian' di mobile, sehingga aturan web selaras dengan mobile.
        'formulir_f201_kematian' => true,
    ];
}

// Metadata untuk halaman admin: label, cakupan, dan peringatan yang perlu dibaca sebelum diubah.
function document_features_catalog(): array
{
    return [
        'dokumen_hasil' => [
            'constant' => 'DOKUMEN_HASIL_ENABLED',
            'label' => 'Dokumen hasil dari operator',
            'scope' => 'Web dan aplikasi Android',
            'description' => 'Operator dapat mengunggah dokumen hasil, dan pemohon dapat mengunduhnya dari riwayat permohonan.',
            'caution' => 'Memengaruhi aplikasi Android: endpoint dokumen hasil pada API menolak akses dengan 404 selama fitur nonaktif.',
        ],
        'formulir_f101' => [
            'constant' => 'FORMULIR_F101_AUTO_ENABLED',
            'label' => 'Formulir F-1.01 otomatis (Kartu Keluarga)',
            'scope' => 'Web saja',
            'description' => 'SIPRIMA menyusun sendiri formulir F-1.01 dari data yang diisi pemohon, menggantikan unggahan formulir tulis tangan.',
            'caution' => 'Jika dinonaktifkan, pemohon web kembali diwajibkan mengunggah berkas formulir. Pemohon aplikasi Android memang selalu mengunggah manual.',
        ],
        'formulir_f201_kelahiran' => [
            'constant' => 'FORMULIR_F201_AUTO_ENABLED',
            'label' => 'Formulir F-2.01 otomatis (Akta Kelahiran)',
            'scope' => 'Web saja',
            'description' => 'SIPRIMA menyusun sendiri formulir F-2.01 kelahiran beserta pratinjaunya, menggantikan unggahan formulir tulis tangan.',
            'caution' => 'Jika dinonaktifkan, halaman pratinjau formulir ditutup dan unggahan formulir kembali menjadi dokumen wajib.',
        ],
        'formulir_f201_kematian' => [
            'constant' => 'FORMULIR_F201_KEMATIAN_AUTO_ENABLED',
            'label' => 'Formulir F-2.01 otomatis (Akta Kematian)',
            'scope' => 'Web saja',
            'description' => 'SIPRIMA menyusun sendiri formulir F-2.01 kematian, termasuk data dua saksi, menggantikan unggahan formulir tulis tangan.',
            'caution' => 'Jika dinonaktifkan, halaman pratinjau formulir kematian ditutup dan unggahan formulir kembali menjadi dokumen wajib.',
        ],
    ];
}

function document_features_db(): mysqli
{
    static $db;
    if ($db instanceof mysqli) return $db;
    require_once __DIR__ . '/env_loader.php';
    if (!loadEnv(__DIR__ . '/.env')) throw new RuntimeException('Konfigurasi database tidak tersedia.');
    $db = new mysqli((string)env('DB_HOST', 'localhost'), (string)env('DB_USER', 'root'), (string)env('DB_PASS', ''), (string)env('DB_NAME'), (int)env('DB_PORT', 3306));
    if ($db->connect_errno || !$db->set_charset('utf8mb4')) throw new RuntimeException('Database pengaturan tidak tersedia.');
    return $db;
}

function document_features_validate(array $settings): void
{
    foreach (array_keys(document_features_defaults()) as $key) {
        if (!array_key_exists($key, $settings) || !is_bool($settings[$key])) {
            throw new InvalidArgumentException('Pengaturan fitur tidak valid: ' . $key . '.');
        }
    }
    if (array_diff(array_keys($settings), array_keys(document_features_defaults()))) {
        throw new InvalidArgumentException('Pengaturan fitur memuat kunci yang tidak dikenal.');
    }
}

function document_features_settings(): array
{
    static $settings;
    if ($settings !== null) return $settings;
    try {
        $result = document_features_db()->query('SELECT payload FROM tb_document_features WHERE id=1');
        if (!$result || !($row = $result->fetch_assoc())) throw new RuntimeException('Pengaturan belum tersedia.');
        $settings = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        document_features_validate($settings);
    } catch (Throwable $e) {
        error_log('Document feature settings could not be loaded.');
        $settings = document_features_defaults();
        $settings['unavailable'] = true;
    }
    return $settings;
}

// Nama konstanta dipertahankan agar seluruh halaman pemohon dan app/Api tidak perlu diubah.
foreach (document_features_catalog() as $documentFeatureKey => $documentFeatureMeta) {
    if (!defined($documentFeatureMeta['constant'])) {
        define($documentFeatureMeta['constant'], (bool)document_features_settings()[$documentFeatureKey]);
    }
}
unset($documentFeatureKey, $documentFeatureMeta);
