siprima_risetsiprima_risetsiprima_risetsiprima_riset-- =============================================================================
-- SIPRIMA - Schema database lengkap & idempoten
-- File   : sql/schema_siprima_lengkap.sql
-- Dibuat : 2026-10-22
-- =============================================================================
--
-- TUJUAN
--   Satu file untuk membentuk struktur database SIPRIMA versi terbaru:
--   - database KOSONG (instalasi baru),
--   - database yang hanya berisi dump dasar lama (sipr_db_dukcapil.sql),
--   - database yang sudah dimigrasi sebagian atau seluruhnya.
--   File aman dijalankan berulang kali. Tabel yang sudah ada TIDAK dihapus/dibuat ulang;
--   hanya kolom, indeks, CHECK, dan foreign key yang belum ada yang ditambahkan.
--   Tidak ada DROP TABLE, DROP COLUMN, TRUNCATE, DELETE, atau UPDATE data.
--
-- VERSI MINIMAL
--   MySQL 8.0.19+ (diuji pada MySQL 8.4.3). MariaDB 10.3.10+ secara sintaks didukung
--   (information_schema.CHECK_CONSTRAINTS, ALTER TABLE ... DROP CONSTRAINT), tetapi BELUM diuji.
--   Hak akses yang dibutuhkan: CREATE, ALTER, INDEX, REFERENCES, INSERT, CREATE ROUTINE,
--   ALTER ROUTINE, EXECUTE (prosedur helper sementara dibuat lalu dihapus di akhir file).
--
-- CARA PAKAI
--   1. BACKUP database terlebih dahulu (mysqldump / Export phpMyAdmin). DDL MySQL melakukan
--      implicit commit, jadi file ini tidak bisa di-rollback.
--   2. Jalankan pada database aplikasi yang dipilih, contoh CLI:
--        mysql -u USER -p NAMA_DATABASE < sql/schema_siprima_lengkap.sql
--      atau phpMyAdmin: pilih database dulu -> tab Import -> pilih file ini.
--      File memakai DELIMITER untuk prosedur helper; jangan ditempel per baris.
--   3. Periksa output: baris berlabel "peringatan" berarti ada langkah yang sengaja dilewati
--      (kolom yang lebih lebar/berbeda dari target, atau FK yang terhalang baris yatim).
--   4. Instalasi baru TIDAK berisi akun pengguna. Buat akun admin secara terpisah.
--
-- CAKUPAN (struktur akhir = dump dasar + semua migrasi berikut, dijalankan berurutan)
--   sipr_db_dukcapil.sql (struktur saja)
--   2026-04-07_add_resubmitted_at_other_tables.sql, 2026-04-07_add_resubmitted_at_tb_akta_kelahiran.sql
--   2026-07-16_create_permohonan_dokumen_hasil.sql, 2026-07-16_create_permohonan_komentar.sql
--   2026-07-20_create_email_delivery_confirmation.sql, 2026-07-20_add_catatan_konfirmasi_email.sql
--   2026-07-20_add_notifikasi_permohonan_target.sql, 2026-07-21_add_tindak_lanjut_dokumen_email.sql
--   2026-08-21_create_permohonan_feedback.sql, 2026-09-02_create_whistleblowing.sql
--   20260906_service_settings.sql (+ migrate_service_settings.php), 20260907_service_tracking.sql
--   20260910_feedback_service_rating.sql, security_20260913.sql (= security_20260913.php)
--   20260913_mobile_api.sql (+ mobile_api_migrate.php), 20260918_notifikasi_dilihat.sql
--   20260919_formulir_f201_akta_kelahiran.sql, 20260920_formulir_f101_kartu_keluarga.sql
--   20260920_formulir_f201_akta_kematian.sql, 20260921_kategori_permohonan_akta_kelahiran.sql
--   20260922_keterangan_akta_kelahiran_500.sql, 20260922_keterangan_500_semua_modul.sql
--   20260923_document_features.sql (+ migrate_document_features.php)
--   20260927_kategori_layanan_kartu_keluarga.sql, 20261005_formulir_digital_instansi.sql
--   20261015_sip_pkk_pemakaman_tautan.sql, 20261016_cetak_ktp_el.sql
--   20261017_cetak_ktp_daftar_pemilik.sql, 20261021_konsultasi_data.sql
--   20261022_konsultasi_data_dicek.sql, 20261023_cetak_ktp_pengambilan.sql, 20261024_layanan_aktif.sql
--   File gabungan 20260913_migrasi_produksi_mobile_api.sql, 20260920_migrasi_produksi_formulir.sql,
--   20260927_migrasi_produksi_20260919_sd_20260927.sql, dan
--   20261005_migrasi_produksi_formulir_digital_instansi.sql isinya sudah tercakup di atas
--   (tidak menambah perubahan struktur apa pun).
--   DDL saat runtime yang ikut tercakup: tb_permohonan_status_history
--   (public/user/status_history_helper.php) dan tb_akta_kematian.nama_folder
--   (public/user/akta_kematian.php).
--
-- DATA
--   Hanya data referensi: tb_kecamatan, tb_kua, tb_desa, tb_puskes, tb_rumahsakit, dan baris
--   pengaturan awal id=1 tb_service_settings, tb_document_features, tb_layanan_aktif, serta
--   penanda versi tb_mobile_migrations. Semua memakai INSERT IGNORE (data yang ada tidak ditimpa).
--   File ini TIDAK berisi data pribadi/transaksi (pengguna, NIK, nama penduduk, password, token,
--   permohonan, notifikasi, log, komentar).
-- =============================================================================

SET NAMES utf8mb4;
SET @siprima_old_fk_checks = @@FOREIGN_KEY_CHECKS;

-- ---------------------------------------------------------------------------
-- 0. Prosedur helper sementara (dihapus lagi di akhir file).
--    Bila tidak ada database yang dipilih, perintah pertama di bawah gagal
--    ("No database selected") dan eksekusi berhenti sebelum ada perubahan.
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS siprima_tmp_exec;
DROP PROCEDURE IF EXISTS siprima_tmp_add_column;
DROP PROCEDURE IF EXISTS siprima_tmp_widen_varchar;
DROP PROCEDURE IF EXISTS siprima_tmp_widen_enum;
DROP PROCEDURE IF EXISTS siprima_tmp_add_index;
DROP PROCEDURE IF EXISTS siprima_tmp_add_check;
DROP PROCEDURE IF EXISTS siprima_tmp_replace_check;
DROP PROCEDURE IF EXISTS siprima_tmp_add_fk;

DELIMITER $$

CREATE PROCEDURE siprima_tmp_exec(IN p_sql LONGTEXT)
BEGIN
    SET @siprima_tmp_sql = p_sql;
    PREPARE siprima_tmp_stmt FROM @siprima_tmp_sql;
    EXECUTE siprima_tmp_stmt;
    DEALLOCATE PREPARE siprima_tmp_stmt;
    SET @siprima_tmp_sql = NULL;
END$$

-- Tambah kolom bila tabel ada dan kolom belum ada. Posisi mengikuti kolom sebelumnya
-- (p_after NULL = kolom pertama); bila kolom sebelumnya tidak ada, kolom ditaruh di akhir.
CREATE PROCEDURE siprima_tmp_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT, IN p_after VARCHAR(64))
BEGIN
    DECLARE v_position VARCHAR(80) DEFAULT '';
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
       AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column) THEN
        IF p_after IS NULL THEN
            SET v_position = ' FIRST';
        ELSEIF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_after) THEN
            SET v_position = CONCAT(' AFTER `', p_after, '`');
        END IF;
        CALL siprima_tmp_exec(CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_definition, v_position));
    END IF;
END$$

-- Perlebar VARCHAR hanya bila panjang sekarang lebih kecil dari target. Tipe lain / lebih lebar
-- tidak disentuh (dilaporkan sebagai PERINGATAN), sehingga data tidak pernah terpotong.
CREATE PROCEDURE siprima_tmp_widen_varchar(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_length INT, IN p_definition TEXT)
BEGIN
    DECLARE v_type VARCHAR(64) DEFAULT NULL;
    DECLARE v_length BIGINT DEFAULT NULL;
    SET v_type = (SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column);
    SET v_length = (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column);
    IF v_type IS NOT NULL THEN
        IF LOWER(v_type) = 'varchar' AND v_length < p_length THEN
            CALL siprima_tmp_exec(CONCAT('ALTER TABLE `', p_table, '` MODIFY COLUMN ', p_definition));
        ELSEIF NOT (LOWER(v_type) = 'varchar' AND v_length = p_length) THEN
            SELECT CONCAT('PERINGATAN: ', p_table, '.', p_column, ' bertipe ', v_type, '(', IFNULL(v_length, '-'), '), target varchar(', p_length, '). Dilewati: bukan pelebaran.') AS peringatan;
        END IF;
    END IF;
END$$

-- Ubah ENUM hanya bila tipe sekarang persis salah satu tipe lama yang dikenal (subset nilai target).
CREATE PROCEDURE siprima_tmp_widen_enum(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_target TEXT, IN p_known_old TEXT, IN p_definition TEXT)
BEGIN
    DECLARE v_type LONGTEXT DEFAULT NULL;
    SET v_type = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column);
    IF v_type IS NOT NULL AND v_type <> p_target THEN
        IF LOCATE(CONCAT('|', v_type, '|'), CONCAT('|', p_known_old, '|')) > 0 THEN
            CALL siprima_tmp_exec(CONCAT('ALTER TABLE `', p_table, '` MODIFY COLUMN ', p_definition));
        ELSE
            SELECT CONCAT('PERINGATAN: ', p_table, '.', p_column, ' bertipe ', v_type, ', target ', p_target, '. Dilewati: bukan penambahan nilai yang dikenal.') AS peringatan;
        END IF;
    END IF;
END$$

CREATE PROCEDURE siprima_tmp_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
       AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index) THEN
        CALL siprima_tmp_exec(CONCAT('ALTER TABLE `', p_table, '` ADD ', p_definition));
    END IF;
END$$

CREATE PROCEDURE siprima_tmp_add_check(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_name AND CONSTRAINT_TYPE = 'CHECK') THEN
        CALL siprima_tmp_exec(CONCAT('ALTER TABLE `', p_table, '` ADD ', p_definition));
    END IF;
END$$

-- Ganti CHECK lama dengan versi yang lebih longgar (menambah nilai yang diizinkan), hanya bila
-- klausa lama belum memuat p_must_contain. Sama dengan langkah 2 migrasi 20261016_cetak_ktp_el.sql.
CREATE PROCEDURE siprima_tmp_replace_check(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_must_contain VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_name AND CONSTRAINT_TYPE = 'CHECK')
       AND EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = p_name AND CHECK_CLAUSE NOT LIKE CONCAT('%', p_must_contain, '%')) THEN
        CALL siprima_tmp_exec(CONCAT('ALTER TABLE `', p_table, '` DROP CONSTRAINT `', p_name, '`'));
        CALL siprima_tmp_exec(CONCAT('ALTER TABLE `', p_table, '` ADD ', p_definition));
    END IF;
END$$

-- Tambah FOREIGN KEY bila belum ada FK dengan nama yang sama atau FK setara (kolom & referensi sama).
-- Bila ada baris yatim (nilai tanpa pasangan di tabel induk), FK TIDAK ditambahkan dan dilaporkan.
CREATE PROCEDURE siprima_tmp_add_fk(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_column VARCHAR(64), IN p_ref_table VARCHAR(64), IN p_ref_column VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
       AND EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_ref_table)
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_name AND CONSTRAINT_TYPE = 'FOREIGN KEY')
       AND NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column AND REFERENCED_TABLE_NAME = p_ref_table AND REFERENCED_COLUMN_NAME = p_ref_column) THEN
        SET @siprima_tmp_orphans = 0;
        CALL siprima_tmp_exec(CONCAT('SELECT COUNT(*) INTO @siprima_tmp_orphans FROM `', p_table, '` c LEFT JOIN `', p_ref_table, '` p ON p.`', p_ref_column, '` = c.`', p_column, '` WHERE c.`', p_column, '` IS NOT NULL AND p.`', p_ref_column, '` IS NULL'));
        IF @siprima_tmp_orphans > 0 THEN
            SELECT CONCAT('PERINGATAN: FK ', p_name, ' tidak ditambahkan; ', @siprima_tmp_orphans, ' baris ', p_table, '.', p_column, ' tidak punya pasangan di ', p_ref_table, '.', p_ref_column, '.') AS peringatan;
        ELSE
            CALL siprima_tmp_exec(CONCAT('ALTER TABLE `', p_table, '` ADD ', p_definition));
        END IF;
    END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------------
-- 1. Buat tabel yang belum ada (struktur akhir lengkap: kolom, indeks, FK, CHECK).
--    FOREIGN_KEY_CHECKS dimatikan sementara hanya agar urutan pembuatan tidak bergantung
--    pada tabel induk; tabel baru masih kosong sehingga tidak ada data yang dilewati validasi.
-- ---------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `tb_kecamatan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_puskes` (
  `id_puskes` int NOT NULL AUTO_INCREMENT,
  `id_kecamatan` int DEFAULT NULL,
  `nama_puskes` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  PRIMARY KEY (`id_puskes`) USING BTREE,
  KEY `FK1_id_kec_tb_kua` (`id_kecamatan`) USING BTREE,
  CONSTRAINT `tb_puskes_ibfk_1` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_kua` int DEFAULT NULL,
  `id_rs` int DEFAULT NULL,
  `id_puskes` int DEFAULT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `nama` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('user','operator','admin','petugas_desa') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `alamat` text COLLATE utf8mb4_unicode_ci,
  `no_hp` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `id_petugas` int DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `temporary_password_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK1_id_kua_user` (`id_kua`),
  KEY `FK2_id_rs_tb_rs` (`id_rs`),
  KEY `FK3_id_puskes_tb_puskes` (`id_puskes`),
  KEY `FK4_id_petugas_tb_pdftaran` (`id_petugas`),
  KEY `FK5_id_kec_tb_pndftaran` (`id_kecamatan`),
  KEY `FK6_id_desa_tb_pndftaran` (`id_desa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_kua` (
  `id_kua` int NOT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `nama_kua` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  PRIMARY KEY (`id_kua`) USING BTREE,
  KEY `FK1_id_kec_tb_kua` (`id_kecamatan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_desa` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kecamatan_id` int DEFAULT NULL,
  `id_kua` int DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK2_id_kua` (`id_kua`),
  KEY `FK_tb_desa_tb_kecamatan` (`kecamatan_id`),
  CONSTRAINT `FK2_id_kua` FOREIGN KEY (`id_kua`) REFERENCES `tb_kua` (`id_kua`),
  CONSTRAINT `FK_tb_desa_tb_kecamatan` FOREIGN KEY (`kecamatan_id`) REFERENCES `tb_kecamatan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_rumahsakit` (
  `id_rs` int NOT NULL AUTO_INCREMENT,
  `nama_rs` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_rs`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_akta_kelahiran` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int DEFAULT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `id_puskes` int DEFAULT NULL,
  `id_rs` int DEFAULT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_bayi` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tempat_lahir` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_kelamin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `urutan_kelahiran` int DEFAULT NULL,
  `Waktu` time DEFAULT NULL,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_kelahiran` tinyint unsigned DEFAULT NULL COMMENT '1=Tunggal 2=Kembar 2 3=Kembar 3 4=Kembar 4 5=Lainnya',
  `penolong_kelahiran` tinyint unsigned DEFAULT NULL COMMENT '1=Dokter 2=Bidan/Perawat 3=Dukun 4=Lainnya',
  `berat_bayi_gram` smallint unsigned DEFAULT NULL COMMENT 'Berat bayi dalam gram',
  `panjang_bayi_mm` smallint unsigned DEFAULT NULL COMMENT 'Panjang bayi dalam milimeter',
  `formulir_dibuat_at` datetime DEFAULT NULL COMMENT 'Saat pemohon menyetujui pernyataan dan menandatangani formulir',
  `jenis_permohonan` enum('baru_belum_nik','baru_sudah_nik','hilang','pembaruan') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'baru_belum_nik' COMMENT 'Kategori permohonan akta kelahiran',
  `nik_subjek` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NIK orang yang aktanya diajukan; wajib untuk kategori selain baru_belum_nik',
  `no_suket_kehilangan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nomor surat keterangan kehilangan dari kepolisian (kategori hilang)',
  `nomor_akta_lama` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nomor akta lahir lama; wajib untuk kategori pembaruan, opsional untuk kategori hilang',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK3_KK_user` (`id_user`) USING BTREE,
  KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE,
  KEY `FK2_KK_desa` (`id_desa`) USING BTREE,
  KEY `FK4_tb_akta_lahir_tb_puskes` (`id_puskes`),
  KEY `tb_akta_lahir_id_rs_tb_rumahsakit` (`id_rs`),
  CONSTRAINT `FK4_tb_akta_lahir_tb_puskes` FOREIGN KEY (`id_puskes`) REFERENCES `tb_puskes` (`id_puskes`),
  CONSTRAINT `FK_tb_akta_kelahiran_tb_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`),
  CONSTRAINT `FK_tb_akta_kelahiran_tb_users` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`),
  CONSTRAINT `tb_akta_kelahiran_ibfk_2` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`),
  CONSTRAINT `tb_akta_lahir_id_rs_tb_rumahsakit` FOREIGN KEY (`id_rs`) REFERENCES `tb_rumahsakit` (`id_rs`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_akta_kelahiran_ahlak` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int DEFAULT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `id_puskes` int DEFAULT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_bayi` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tempat_lahir` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_kelamin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `urutan_kelahiran` int DEFAULT NULL,
  `Waktu` time DEFAULT NULL,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_kelahiran` tinyint unsigned DEFAULT NULL COMMENT '1=Tunggal 2=Kembar 2 3=Kembar 3 4=Kembar 4 5=Lainnya',
  `penolong_kelahiran` tinyint unsigned DEFAULT NULL COMMENT '1=Dokter 2=Bidan/Perawat 3=Dukun 4=Lainnya',
  `berat_bayi_gram` smallint unsigned DEFAULT NULL COMMENT 'Berat bayi dalam gram',
  `panjang_bayi_mm` smallint unsigned DEFAULT NULL COMMENT 'Panjang bayi dalam milimeter',
  `formulir_dibuat_at` datetime DEFAULT NULL COMMENT 'Saat petugas menyetujui pernyataan dan menandatangani formulir',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK3_KK_user` (`id_user`) USING BTREE,
  KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE,
  KEY `FK2_KK_desa` (`id_desa`) USING BTREE,
  KEY `FK4_tb_akta_lahir_tb_puskes` (`id_puskes`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_akta_kelahiran_rs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int DEFAULT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `id_rs` int DEFAULT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_bayi` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tempat_lahir` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_kelamin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `urutan_kelahiran` int DEFAULT NULL,
  `Waktu` time DEFAULT NULL,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_kelahiran` tinyint unsigned DEFAULT NULL COMMENT '1=Tunggal 2=Kembar 2 3=Kembar 3 4=Kembar 4 5=Lainnya',
  `penolong_kelahiran` tinyint unsigned DEFAULT NULL COMMENT '1=Dokter 2=Bidan/Perawat 3=Dukun 4=Lainnya',
  `berat_bayi_gram` smallint unsigned DEFAULT NULL COMMENT 'Berat bayi dalam gram',
  `panjang_bayi_mm` smallint unsigned DEFAULT NULL COMMENT 'Panjang bayi dalam milimeter',
  `formulir_dibuat_at` datetime DEFAULT NULL COMMENT 'Saat petugas menyetujui pernyataan dan menandatangani formulir',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK3_KK_user` (`id_user`) USING BTREE,
  KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE,
  KEY `FK2_KK_desa` (`id_desa`) USING BTREE,
  KEY `tb_akta_lahir_id_rs_tb_rumahsakit` (`id_rs`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_akta_kematian` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int DEFAULT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `id_puskes` int DEFAULT NULL,
  `id_rs` int DEFAULT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_meninggal` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_meninggal` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tanggal_kematian` date DEFAULT NULL,
  `tempat` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `formulir_dibuat_at` datetime DEFAULT NULL COMMENT 'Saat pemohon menyetujui pernyataan dan menandatangani formulir',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK3_KK_user` (`id_user`) USING BTREE,
  KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE,
  KEY `FK2_KK_desa` (`id_desa`) USING BTREE,
  KEY `id_puskes_tb_puskes` (`id_puskes`),
  KEY `id_rs_tb_rs` (`id_rs`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_akta_kematian_rs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int DEFAULT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `id_rs` int DEFAULT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_meninggal` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_meninggal` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tanggal_kematian` date DEFAULT NULL,
  `tempat` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `formulir_dibuat_at` datetime DEFAULT NULL COMMENT 'Saat petugas menyetujui pernyataan dan menandatangani formulir',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK3_KK_user` (`id_user`) USING BTREE,
  KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE,
  KEY `FK2_KK_desa` (`id_desa`) USING BTREE,
  KEY `id_rs_tb_rs` (`id_rs`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_cetak_ktp` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL,
  `id_kecamatan` int NOT NULL,
  `id_desa` int NOT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_permohonan` enum('milik_sendiri','milik_orang_lain') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'milik_sendiri' COMMENT 'KTP-el milik sendiri / milik orang lain (dengan surat kuasa F-1.07)',
  `satu_kk` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = milik sendiri + anggota satu KK',
  `nama_pemilik` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nama pemilik KTP-el',
  `nik_pemilik` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NIK pemilik KTP-el',
  `daftar_pemilik` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON daftar pemilik KTP-el (nama, nik)',
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT 'diajukan',
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metode_pengambilan` enum('ambil_kantor','cod') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Cara pengambilan pilihan pemohon; NULL = belum memilih',
  `alamat_pengiriman` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Alamat pengiriman COD',
  `no_hp_penerima` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'No HP penerima COD',
  `tahap_pengambilan` enum('sudah_diambil','dikirim','diterima') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tahap ditandai operator; NULL = belum ditandai',
  `pengambilan_updated_at` datetime DEFAULT NULL COMMENT 'Waktu perubahan pilihan/tahap pengambilan terakhir',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cetak_ktp_user` (`id_user`),
  KEY `idx_cetak_ktp_kecamatan` (`id_kecamatan`),
  KEY `idx_cetak_ktp_desa` (`id_desa`),
  KEY `idx_cetak_ktp_status` (`status`),
  CONSTRAINT `fk_cetak_ktp_desa` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`),
  CONSTRAINT `fk_cetak_ktp_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`),
  CONSTRAINT `fk_cetak_ktp_user` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_document_features` (
  `id` tinyint unsigned NOT NULL,
  `payload` longtext NOT NULL,
  `revision` int unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_document_features_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int NOT NULL,
  `changed_at` datetime NOT NULL,
  `action` varchar(100) NOT NULL,
  `before_payload` longtext NOT NULL,
  `after_payload` longtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_formulir` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nama_formulir` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `file_formulir` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_kartukeluarga` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int DEFAULT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kepalaKeluarga` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anggotaKeluarga` text COLLATE utf8mb4_unicode_ci,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_permohonan` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Perubahan Data / Penambahan Anggota Keluarga / KK Hilang-Rusak / Pisah KK / Lainnya',
  `formulir_dibuat_at` datetime DEFAULT NULL COMMENT 'Saat pemohon menyetujui pernyataan dan menandatangani formulir',
  `kategori_layanan` enum('kk_baru','kk_hilang','cetak_biodata','perubahan_data') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kk_baru' COMMENT 'Kategori layanan Kartu Keluarga',
  `nik_subjek` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NIK yang dilayani; wajib untuk kategori selain kk_baru',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK3_KK_user` (`id_user`) USING BTREE,
  KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE,
  KEY `FK2_KK_desa` (`id_desa`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_kartukeluarga_kua` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int DEFAULT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `id_kua` int DEFAULT NULL,
  `alamat_catin` enum('alamat sama','alamat beda') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_kk_lama_suami` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_kk_lama_istri` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kepalaKeluarga` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anggotaKeluarga` text COLLATE utf8mb4_unicode_ci,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_permohonan` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Jenis permohonan F-1.01 (whitelist di PHP)',
  `formulir_dibuat_at` datetime DEFAULT NULL COMMENT 'Saat petugas menyetujui pernyataan dan menandatangani formulir',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK3_KK_user` (`id_user`) USING BTREE,
  KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE,
  KEY `FK2_KK_desa` (`id_desa`) USING BTREE,
  KEY `id_kua_kk` (`id_kua`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_konfirmasi_dokumen_email` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permohonan_id` int NOT NULL,
  `id_user_pemohon` int NOT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `jenis_layanan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_pemohon` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_subjek` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `selesai_at` datetime NOT NULL,
  `eligible_at` datetime NOT NULL,
  `status_konfirmasi` enum('sudah_masuk','belum_masuk','diunggah_operator','sebagian_diunggah_perlu_pengajuan_baru','perlu_pengajuan_baru','dibatalkan') COLLATE utf8mb4_unicode_ci NOT NULL,
  `catatan_pemohon` text COLLATE utf8mb4_unicode_ci,
  `catatan_operator` text COLLATE utf8mb4_unicode_ci,
  `confirmed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `operator_id` int DEFAULT NULL,
  `tindak_lanjut_operator_id` int DEFAULT NULL,
  `operator_confirmed_at` datetime DEFAULT NULL,
  `tindak_lanjut_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_konfirmasi_email_permohonan` (`source_table`,`permohonan_id`),
  KEY `idx_konfirmasi_email_queue` (`status_konfirmasi`,`id_kecamatan`,`confirmed_at`),
  KEY `idx_konfirmasi_email_user` (`id_user_pemohon`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_konfirmasi_dokumen_email_file` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `konfirmasi_id` bigint unsigned NOT NULL,
  `uploaded_by` int NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'application/pdf',
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_konfirmasi_email_file_parent` (`konfirmasi_id`,`uploaded_at`),
  CONSTRAINT `fk_konfirmasi_email_file_parent` FOREIGN KEY (`konfirmasi_id`) REFERENCES `tb_konfirmasi_dokumen_email` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_konsultasi_data` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kode_konsultasi` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_user` int NOT NULL COMMENT 'Pemohon umum atau petugas desa yang mengajukan',
  `id_kecamatan` int NOT NULL,
  `id_desa` int NOT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_dicek` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nama orang yang datanya dicek',
  `nik_dicek` char(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'NIK orang yang datanya dicek',
  `keterangan` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('diajukan','proses_cek','selesai') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'diajukan',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `selesai_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_konsultasi_kode` (`kode_konsultasi`),
  KEY `idx_konsultasi_user` (`id_user`),
  KEY `idx_konsultasi_kecamatan_status` (`id_kecamatan`,`status`),
  KEY `fk_konsultasi_desa` (`id_desa`),
  CONSTRAINT `fk_konsultasi_desa` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`),
  CONSTRAINT `fk_konsultasi_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`),
  CONSTRAINT `fk_konsultasi_user` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_konsultasi_data_dicek` (
  `id` int NOT NULL AUTO_INCREMENT,
  `konsultasi_id` int NOT NULL,
  `urutan` tinyint unsigned NOT NULL DEFAULT '1',
  `nama_dicek` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nik_dicek` char(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_konsultasi_dicek_konsultasi` (`konsultasi_id`,`urutan`),
  KEY `idx_konsultasi_dicek_nik` (`nik_dicek`),
  CONSTRAINT `fk_konsultasi_dicek_konsultasi` FOREIGN KEY (`konsultasi_id`) REFERENCES `tb_konsultasi_data` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_konsultasi_jawaban` (
  `id` int NOT NULL AUTO_INCREMENT,
  `konsultasi_id` int NOT NULL,
  `operator_id` int NOT NULL,
  `pesan` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_konsultasi_jawaban_konsultasi` (`konsultasi_id`),
  KEY `fk_konsultasi_jawaban_operator` (`operator_id`),
  CONSTRAINT `fk_konsultasi_jawaban_konsultasi` FOREIGN KEY (`konsultasi_id`) REFERENCES `tb_konsultasi_data` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_konsultasi_jawaban_operator` FOREIGN KEY (`operator_id`) REFERENCES `tb_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_konsultasi_lampiran` (
  `id` int NOT NULL AUTO_INCREMENT,
  `konsultasi_id` int NOT NULL,
  `jawaban_id` int DEFAULT NULL,
  `jenis` enum('foto_ktp','dokumen','bukti_operator') COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int NOT NULL DEFAULT '0',
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_konsultasi_lampiran_konsultasi` (`konsultasi_id`),
  KEY `idx_konsultasi_lampiran_jawaban` (`jawaban_id`),
  CONSTRAINT `fk_konsultasi_lampiran_jawaban` FOREIGN KEY (`jawaban_id`) REFERENCES `tb_konsultasi_jawaban` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_konsultasi_lampiran_konsultasi` FOREIGN KEY (`konsultasi_id`) REFERENCES `tb_konsultasi_data` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_laporan_peristiwa` (
  `id_lapor` int NOT NULL AUTO_INCREMENT,
  `id_user` int DEFAULT NULL,
  `id_kecamatan` int DEFAULT NULL,
  `id_desa` int DEFAULT NULL,
  `nama_pelapor` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pelapor` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_peristiwa` enum('lahir','mati','rentan_adminduk') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_subjek` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_subjek` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tempat_lahir_mati` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tanggal_lahir_mati` date DEFAULT NULL,
  `nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_kelamin` enum('L','P') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Jenis kelamin yang meninggal',
  `umur` smallint unsigned DEFAULT NULL COMMENT 'Umur saat meninggal (tahun)',
  `dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Dusun/lingkungan alamat subjek',
  `rt` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rw` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sebab_kematian` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tanggal_dimakamkan` date DEFAULT NULL,
  `lokasi_makam` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nama TPU/lokasi pemakaman',
  `nomor_makam` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Blok/nomor makam',
  `nama_ahli_waris` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp_ahli_waris` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nomor ahli waris yang bisa dihubungi',
  `permohonan_tabel` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'tb_akta_kelahiran / tb_akta_kematian / tb_kartukeluarga',
  `permohonan_id` int DEFAULT NULL COMMENT 'id permohonan pada permohonan_tabel',
  PRIMARY KEY (`id_lapor`),
  KEY `FK1_id_user_tb_user` (`id_user`),
  KEY `FK2_id_kec_tb_kecamatan` (`id_kecamatan`),
  KEY `FK3_id_desa_tb_desa` (`id_desa`),
  KEY `idx_laporan_permohonan` (`permohonan_tabel`,`permohonan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_layanan_aktif` (
  `id` tinyint unsigned NOT NULL,
  `payload` longtext NOT NULL,
  `revision` int unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_layanan_aktif_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int NOT NULL,
  `changed_at` datetime NOT NULL,
  `action` varchar(100) NOT NULL,
  `before_payload` longtext NOT NULL,
  `after_payload` longtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_mobile_idempotency` (
  `user_id` int NOT NULL,
  `key_hash` char(64) NOT NULL,
  `scope` varchar(160) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `response_json` longtext,
  `created_at` bigint NOT NULL,
  PRIMARY KEY (`user_id`,`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_mobile_migrations` (
  `version` varchar(80) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_mobile_outbox` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_table` varchar(64) NOT NULL,
  `permohonan_id` int NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `created_at` bigint NOT NULL,
  `delivered_at` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mobile_outbox_pending` (`delivered_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_mobile_rate_limits` (
  `bucket` char(64) NOT NULL,
  `hits` int NOT NULL,
  `expires_at` bigint NOT NULL,
  PRIMARY KEY (`bucket`),
  KEY `idx_mobile_rate_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_mobile_sessions` (
  `id` char(32) NOT NULL,
  `user_id` int NOT NULL,
  `device_name` varchar(100) NOT NULL,
  `access_hash` char(64) NOT NULL,
  `refresh_hash` char(64) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `access_expires_at` bigint NOT NULL,
  `expires_at` bigint NOT NULL,
  `last_used_at` bigint NOT NULL,
  `created_at` bigint NOT NULL,
  `revoked_at` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `access_hash` (`access_hash`),
  UNIQUE KEY `refresh_hash` (`refresh_hash`),
  KEY `idx_mobile_user` (`user_id`),
  KEY `idx_mobile_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_mobile_uploads` (
  `id` char(32) NOT NULL,
  `user_id` int NOT NULL,
  `service_code` varchar(32) NOT NULL,
  `doc_type` varchar(64) NOT NULL,
  `original_name` varchar(200) NOT NULL,
  `stored_name` varchar(80) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int NOT NULL,
  `created_at` bigint NOT NULL,
  `expires_at` bigint NOT NULL,
  `application_ref` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mobile_upload_owner` (`user_id`,`application_ref`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_mobile_used_refresh` (
  `token_hash` char(64) NOT NULL,
  `session_id` char(32) NOT NULL,
  `expires_at` bigint NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `idx_mobile_refresh_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_notifikasi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `judul` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pesan` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipe` enum('info','success','warning','danger') COLLATE utf8mb4_unicode_ci DEFAULT 'info',
  `target_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_table` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permohonan_id` int DEFAULT NULL,
  `dibaca` tinyint(1) DEFAULT '0',
  `dilihat` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_user_dibaca` (`user_id`,`dibaca`) USING BTREE,
  KEY `idx_created` (`created_at`) USING BTREE,
  KEY `idx_notifikasi_permohonan` (`source_table`,`permohonan_id`),
  KEY `idx_notifikasi_user_dilihat` (`user_id`,`dilihat`),
  CONSTRAINT `FK_tb_notifikasi_tb_users` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_operator_kecamatan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operator_id` int DEFAULT NULL,
  `kecamatan_id` int DEFAULT NULL,
  `kua_id` int DEFAULT NULL,
  `puskes_id` int DEFAULT NULL,
  `rs_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK_tb_operator_kecamatan_tb_kecamatan` (`kecamatan_id`) USING BTREE,
  KEY `FK3_tb_opr_kec_tb_kua` (`kua_id`) USING BTREE,
  KEY `operator_id` (`operator_id`) USING BTREE,
  KEY `FK4_id_puskes_tb_puskes` (`puskes_id`) USING BTREE,
  KEY `FK5_tb_rumahsakit_rs_id` (`rs_id`) USING BTREE,
  CONSTRAINT `FK3_tb_opr_kec_tb_kua` FOREIGN KEY (`kua_id`) REFERENCES `tb_kua` (`id_kua`),
  CONSTRAINT `FK4_id_puskes_tb_puskes` FOREIGN KEY (`puskes_id`) REFERENCES `tb_puskes` (`id_puskes`),
  CONSTRAINT `FK5_tb_rumahsakit_rs_id` FOREIGN KEY (`rs_id`) REFERENCES `tb_rumahsakit` (`id_rs`),
  CONSTRAINT `FK_tb_operator_kecamatan_tb_kecamatan` FOREIGN KEY (`kecamatan_id`) REFERENCES `tb_kecamatan` (`id`),
  CONSTRAINT `FK_tb_operator_kecamatan_tb_users` FOREIGN KEY (`operator_id`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_password_reset` (
  `user_id` int NOT NULL,
  `requested_by` int NOT NULL,
  `request_id` char(32) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `password_snapshot` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `email_status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `telegram_status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `request_id` (`request_id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_pendaftaran_petugas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nik` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `no_hp` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alamat` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `kecamatan_id` int NOT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `desa_id` int NOT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jabatan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `surat_tugas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kode_verifikasi` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','diverifikasi','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_nik` (`nik`) USING BTREE,
  KEY `idx_email` (`email`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_permohonan_dokumen_hasil` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permohonan_id` int NOT NULL,
  `id_user_pemohon` int NOT NULL,
  `uploaded_by` int DEFAULT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL DEFAULT '0',
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'application/pdf',
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_permohonan_hasil` (`source_table`,`permohonan_id`),
  KEY `idx_pemohon_hasil` (`id_user_pemohon`),
  KEY `idx_uploaded_by_hasil` (`uploaded_by`),
  KEY `idx_uploaded_at_hasil` (`uploaded_at`),
  CONSTRAINT `fk_dokumen_hasil_pemohon` FOREIGN KEY (`id_user_pemohon`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dokumen_hasil_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `tb_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_dokumen_hasil_no_script_extension` CHECK (((not((lower(`original_name`) like _utf8mb4'%.php%'))) and (not((lower(`original_name`) like _utf8mb4'%.phtml%'))) and (not((lower(`original_name`) like _utf8mb4'%.phar%'))) and (not((lower(`original_name`) like _utf8mb4'%.phps%'))) and (not((lower(`stored_name`) like _utf8mb4'%.php%'))) and (not((lower(`stored_name`) like _utf8mb4'%.phtml%'))) and (not((lower(`stored_name`) like _utf8mb4'%.phar%'))) and (not((lower(`stored_name`) like _utf8mb4'%.phps%'))) and (not((lower(`file_path`) like _utf8mb4'%.php%'))) and (not((lower(`file_path`) like _utf8mb4'%.phtml%'))) and (not((lower(`file_path`) like _utf8mb4'%.phar%'))) and (not((lower(`file_path`) like _utf8mb4'%.phps%'))))),
  CONSTRAINT `chk_dokumen_hasil_pdf_extension` CHECK (((lower(`stored_name`) like _utf8mb4'%.pdf') and (lower(`file_path`) like _utf8mb4'%.pdf'))),
  CONSTRAINT `chk_dokumen_hasil_pdf_mime` CHECK ((`mime_type` = _utf8mb4'application/pdf'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_permohonan_feedback` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permohonan_id` int NOT NULL,
  `respondent_user_id` int NOT NULL,
  `respondent_role` enum('pemohon','petugas_registrasi') COLLATE utf8mb4_unicode_ci NOT NULL,
  `jenis_layanan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_pemohon` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_subjek` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trigger_type` enum('konfirmasi','download') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','submitted','skipped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `jenis_feedback` enum('tanggapan','aduan') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` tinyint unsigned DEFAULT NULL,
  `service_rating` tinyint unsigned DEFAULT NULL,
  `pesan` text COLLATE utf8mb4_unicode_ci,
  `triggered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` datetime DEFAULT NULL,
  `skipped_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_feedback_permohonan_role` (`source_table`,`permohonan_id`,`respondent_role`),
  KEY `idx_feedback_admin` (`status`,`jenis_feedback`,`submitted_at`),
  KEY `idx_feedback_user` (`respondent_user_id`,`updated_at`),
  CONSTRAINT `chk_feedback_rating` CHECK (((`rating` is null) or (`rating` between 1 and 5))),
  CONSTRAINT `chk_feedback_service_rating` CHECK (((`service_rating` is null) or (`service_rating` between 1 and 5)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_permohonan_komentar` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permohonan_id` int NOT NULL,
  `id_user` int NOT NULL,
  `user_role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `komentar` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_by_operator_at` datetime DEFAULT NULL,
  `read_by_pemohon_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_permohonan_komentar` (`source_table`,`permohonan_id`,`created_at`),
  KEY `idx_user_komentar` (`id_user`),
  KEY `idx_parent_komentar` (`parent_id`),
  KEY `idx_operator_unread_komentar` (`source_table`,`permohonan_id`,`read_by_operator_at`),
  KEY `idx_pemohon_unread_komentar` (`source_table`,`permohonan_id`,`read_by_pemohon_at`),
  CONSTRAINT `fk_permohonan_komentar_parent` FOREIGN KEY (`parent_id`) REFERENCES `tb_permohonan_komentar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_permohonan_komentar_user` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_permohonan_komentar_role` CHECK ((`user_role` in (_utf8mb4'admin',_utf8mb4'operator',_utf8mb4'user',_utf8mb4'petugas_desa',_utf8mb4'rs',_utf8mb4'kua',_utf8mb4'ahlak'))),
  CONSTRAINT `chk_permohonan_komentar_source_table` CHECK ((`source_table` in (_utf8mb4'tb_kartukeluarga',_utf8mb4'tb_akta_kelahiran',_utf8mb4'tb_akta_kematian',_utf8mb4'tb_pindah_alamat',_utf8mb4'tb_pindah_online',_utf8mb4'tb_laporan_peristiwa',_utf8mb4'tb_akta_kelahiran_rs',_utf8mb4'tb_akta_kematian_rs',_utf8mb4'tb_kartukeluarga_kua',_utf8mb4'tb_akta_kelahiran_ahlak',_utf8mb4'tb_cetak_ktp'))),
  CONSTRAINT `chk_permohonan_komentar_text` CHECK ((char_length(trim(`komentar`)) between 1 and 1000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_permohonan_status_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permohonan_id` bigint unsigned NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alasan` text COLLATE utf8mb4_unicode_ci,
  `actor_id` int DEFAULT NULL,
  `actor_role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source_permohonan_created` (`source_table`,`permohonan_id`,`created_at`),
  KEY `idx_status_created` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_pindah_alamat` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL,
  `id_kecamatan` int NOT NULL,
  `id_desa` int NOT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_kk` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kepalaKeluarga` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alamat_asal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alamat_tujuan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan_pindah` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anggotaKeluarga` text COLLATE utf8mb4_unicode_ci,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK3_KK_user` (`id_user`) USING BTREE,
  KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE,
  KEY `FK2_KK_desa` (`id_desa`) USING BTREE,
  CONSTRAINT `FK_tb_pindah_alamat_tb_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`),
  CONSTRAINT `FK_tb_pindah_alamat_tb_users` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`),
  CONSTRAINT `tb_pindah_alamat_ibfk_2` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_pindah_online` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL,
  `id_kecamatan` int NOT NULL,
  `id_desa` int NOT NULL,
  `nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alamat_asal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alamat_tujuan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_kk` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_kepalaKeluarga` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan_pindah` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anggotaKeluarga` text COLLATE utf8mb4_unicode_ci,
  `dokumen` text COLLATE utf8mb4_unicode_ci,
  `status` enum('diajukan','diproses','selesai','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `resubmitted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK3_KK_user` (`id_user`) USING BTREE,
  KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE,
  KEY `FK2_KK_desa` (`id_desa`) USING BTREE,
  CONSTRAINT `FK_tb_pindah_online_tb_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`),
  CONSTRAINT `FK_tb_pindah_online_tb_users` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`),
  CONSTRAINT `tb_pindah_online_ibfk_2` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `tb_service_settings` (
  `id` tinyint unsigned NOT NULL,
  `payload` longtext NOT NULL,
  `revision` int unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_service_settings_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int NOT NULL,
  `changed_at` datetime NOT NULL,
  `action` varchar(100) NOT NULL,
  `before_payload` longtext NOT NULL,
  `after_payload` longtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tb_whistleblowing_aduan` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kode_aduan` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pemohon_id` int NOT NULL,
  `kategori` enum('pungli_gratifikasi','penyalahgunaan_wewenang','diskriminasi','pelanggaran_prosedur','pelanggaran_data','lainnya') COLLATE utf8mb4_unicode_ci NOT NULL,
  `judul` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uraian` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `tanggal_kejadian` date DEFAULT NULL,
  `lokasi_kejadian` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pihak_terlapor` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `layanan_terkait` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('baru','diverifikasi','diproses','menunggu_pemohon','selesai','ditolak') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'baru',
  `admin_penanggung_jawab_id` int DEFAULT NULL,
  `selesai_at` datetime DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT '0',
  `ringkasan_publik` text COLLATE utf8mb4_unicode_ci,
  `hasil_tindak_lanjut_publik` text COLLATE utf8mb4_unicode_ci,
  `published_by` int DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_whistleblowing_kode` (`kode_aduan`),
  KEY `idx_whistleblowing_pemohon` (`pemohon_id`,`updated_at`),
  KEY `idx_whistleblowing_admin` (`status`,`updated_at`),
  KEY `idx_whistleblowing_publik` (`is_published`,`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_whistleblowing_tindak_lanjut` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `aduan_id` bigint unsigned NOT NULL,
  `actor_user_id` int NOT NULL,
  `actor_role` enum('pemohon','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_from` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_to` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pesan` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `visibility` enum('pemohon','internal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pemohon',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_whistleblowing_timeline` (`aduan_id`,`created_at`,`id`),
  CONSTRAINT `fk_whistleblowing_tindak_lanjut_aduan` FOREIGN KEY (`aduan_id`) REFERENCES `tb_whistleblowing_aduan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_whistleblowing_lampiran` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `aduan_id` bigint unsigned NOT NULL,
  `tindak_lanjut_id` bigint unsigned DEFAULT NULL,
  `uploaded_by` int NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_whistleblowing_lampiran_aduan` (`aduan_id`,`uploaded_at`),
  KEY `idx_whistleblowing_lampiran_tindak_lanjut` (`tindak_lanjut_id`),
  CONSTRAINT `fk_whistleblowing_lampiran_aduan` FOREIGN KEY (`aduan_id`) REFERENCES `tb_whistleblowing_aduan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_whistleblowing_lampiran_tindak_lanjut` FOREIGN KEY (`tindak_lanjut_id`) REFERENCES `tb_whistleblowing_tindak_lanjut` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = @siprima_old_fk_checks;

-- ---------------------------------------------------------------------------
-- 2. Lengkapi tabel yang SUDAH ada (dump lama / migrasi sebagian):
--    kolom yang belum ada, pelebaran kolom dari migrasi, indeks, dan CHECK.
--    Semua langkah dilewati bila sudah sesuai.
-- ---------------------------------------------------------------------------

-- tb_kecamatan
CALL siprima_tmp_add_column('tb_kecamatan', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT ''''', 'id');

-- tb_puskes
CALL siprima_tmp_add_column('tb_puskes', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_puskes');
CALL siprima_tmp_add_column('tb_puskes', 'nama_puskes', '`nama_puskes` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT ''''', 'id_kecamatan');
CALL siprima_tmp_add_index('tb_puskes', 'FK1_id_kec_tb_kua', 'KEY `FK1_id_kec_tb_kua` (`id_kecamatan`) USING BTREE');

-- tb_users
CALL siprima_tmp_add_column('tb_users', 'id_kua', '`id_kua` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_users', 'id_rs', '`id_rs` int DEFAULT NULL', 'id_kua');
CALL siprima_tmp_add_column('tb_users', 'id_puskes', '`id_puskes` int DEFAULT NULL', 'id_rs');
CALL siprima_tmp_add_column('tb_users', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_puskes');
CALL siprima_tmp_add_column('tb_users', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_users', 'nama', '`nama` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_users', 'nik', '`nik` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama');
CALL siprima_tmp_add_column('tb_users', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik');
CALL siprima_tmp_add_column('tb_users', 'password', '`password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_users', 'role', '`role` enum(''user'',''operator'',''admin'',''petugas_desa'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''user''', 'password');
CALL siprima_tmp_add_column('tb_users', 'alamat', '`alamat` text COLLATE utf8mb4_unicode_ci', 'role');
CALL siprima_tmp_add_column('tb_users', 'no_hp', '`no_hp` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alamat');
CALL siprima_tmp_add_column('tb_users', 'is_active', '`is_active` tinyint(1) DEFAULT ''1''', 'no_hp');
CALL siprima_tmp_add_column('tb_users', 'created_at', '`created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP', 'is_active');
CALL siprima_tmp_add_column('tb_users', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL siprima_tmp_add_column('tb_users', 'id_petugas', '`id_petugas` int DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_column('tb_users', 'must_change_password', '`must_change_password` tinyint(1) NOT NULL DEFAULT ''0''', 'id_petugas');
CALL siprima_tmp_add_column('tb_users', 'temporary_password_expires_at', '`temporary_password_expires_at` datetime DEFAULT NULL', 'must_change_password');
CALL siprima_tmp_add_index('tb_users', 'FK1_id_kua_user', 'KEY `FK1_id_kua_user` (`id_kua`)');
CALL siprima_tmp_add_index('tb_users', 'FK2_id_rs_tb_rs', 'KEY `FK2_id_rs_tb_rs` (`id_rs`)');
CALL siprima_tmp_add_index('tb_users', 'FK3_id_puskes_tb_puskes', 'KEY `FK3_id_puskes_tb_puskes` (`id_puskes`)');
CALL siprima_tmp_add_index('tb_users', 'FK4_id_petugas_tb_pdftaran', 'KEY `FK4_id_petugas_tb_pdftaran` (`id_petugas`)');
CALL siprima_tmp_add_index('tb_users', 'FK5_id_kec_tb_pndftaran', 'KEY `FK5_id_kec_tb_pndftaran` (`id_kecamatan`)');
CALL siprima_tmp_add_index('tb_users', 'FK6_id_desa_tb_pndftaran', 'KEY `FK6_id_desa_tb_pndftaran` (`id_desa`)');

-- tb_kua
CALL siprima_tmp_add_column('tb_kua', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_kua');
CALL siprima_tmp_add_column('tb_kua', 'nama_kua', '`nama_kua` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT ''''', 'id_kecamatan');
CALL siprima_tmp_add_index('tb_kua', 'FK1_id_kec_tb_kua', 'KEY `FK1_id_kec_tb_kua` (`id_kecamatan`)');

-- tb_desa
CALL siprima_tmp_add_column('tb_desa', 'kecamatan_id', '`kecamatan_id` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_desa', 'id_kua', '`id_kua` int DEFAULT NULL', 'kecamatan_id');
CALL siprima_tmp_add_column('tb_desa', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_kua');
CALL siprima_tmp_add_index('tb_desa', 'FK2_id_kua', 'KEY `FK2_id_kua` (`id_kua`)');
CALL siprima_tmp_add_index('tb_desa', 'FK_tb_desa_tb_kecamatan', 'KEY `FK_tb_desa_tb_kecamatan` (`kecamatan_id`)');

-- tb_rumahsakit
CALL siprima_tmp_add_column('tb_rumahsakit', 'nama_rs', '`nama_rs` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_rs');

-- tb_akta_kelahiran
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'id_user', '`id_user` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'id_puskes', '`id_puskes` int DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'id_rs', '`id_rs` int DEFAULT NULL', 'id_puskes');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_rs');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nama_ayah', '`nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nik_ayah', '`nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ayah');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nama_ibu', '`nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ayah');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nik_ibu', '`nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ibu');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nama_saksi1', '`nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ibu');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nik_saksi1', '`nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi1');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nama_saksi2', '`nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_saksi1');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nik_saksi2', '`nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi2');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nama_bayi', '`nama_bayi` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_saksi2');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'tempat_lahir', '`tempat_lahir` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_bayi');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'jenis_kelamin', '`jenis_kelamin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tempat_lahir');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'tanggal_lahir', '`tanggal_lahir` date DEFAULT NULL', 'jenis_kelamin');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'urutan_kelahiran', '`urutan_kelahiran` int DEFAULT NULL', 'tanggal_lahir');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'Waktu', '`Waktu` time DEFAULT NULL', 'urutan_kelahiran');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'Waktu');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'keterangan', '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'keterangan');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'jenis_kelahiran', '`jenis_kelahiran` tinyint unsigned DEFAULT NULL COMMENT ''1=Tunggal 2=Kembar 2 3=Kembar 3 4=Kembar 4 5=Lainnya''', 'nama_folder');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'penolong_kelahiran', '`penolong_kelahiran` tinyint unsigned DEFAULT NULL COMMENT ''1=Dokter 2=Bidan/Perawat 3=Dukun 4=Lainnya''', 'jenis_kelahiran');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'berat_bayi_gram', '`berat_bayi_gram` smallint unsigned DEFAULT NULL COMMENT ''Berat bayi dalam gram''', 'penolong_kelahiran');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'panjang_bayi_mm', '`panjang_bayi_mm` smallint unsigned DEFAULT NULL COMMENT ''Panjang bayi dalam milimeter''', 'berat_bayi_gram');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'formulir_dibuat_at', '`formulir_dibuat_at` datetime DEFAULT NULL COMMENT ''Saat pemohon menyetujui pernyataan dan menandatangani formulir''', 'panjang_bayi_mm');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'jenis_permohonan', '`jenis_permohonan` enum(''baru_belum_nik'',''baru_sudah_nik'',''hilang'',''pembaruan'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''baru_belum_nik'' COMMENT ''Kategori permohonan akta kelahiran''', 'formulir_dibuat_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nik_subjek', '`nik_subjek` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''NIK orang yang aktanya diajukan; wajib untuk kategori selain baru_belum_nik''', 'jenis_permohonan');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'no_suket_kehilangan', '`no_suket_kehilangan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Nomor surat keterangan kehilangan dari kepolisian (kategori hilang)''', 'nik_subjek');
CALL siprima_tmp_add_column('tb_akta_kelahiran', 'nomor_akta_lama', '`nomor_akta_lama` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Nomor akta lahir lama; wajib untuk kategori pembaruan, opsional untuk kategori hilang''', 'no_suket_kehilangan');
CALL siprima_tmp_widen_varchar('tb_akta_kelahiran', 'keterangan', 500, '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
CALL siprima_tmp_add_index('tb_akta_kelahiran', 'FK3_KK_user', 'KEY `FK3_KK_user` (`id_user`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kelahiran', 'FK1_KK_kecamatan', 'KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kelahiran', 'FK2_KK_desa', 'KEY `FK2_KK_desa` (`id_desa`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kelahiran', 'FK4_tb_akta_lahir_tb_puskes', 'KEY `FK4_tb_akta_lahir_tb_puskes` (`id_puskes`)');
CALL siprima_tmp_add_index('tb_akta_kelahiran', 'tb_akta_lahir_id_rs_tb_rumahsakit', 'KEY `tb_akta_lahir_id_rs_tb_rumahsakit` (`id_rs`)');

-- tb_akta_kelahiran_ahlak
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'id_user', '`id_user` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'id_puskes', '`id_puskes` int DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_puskes');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nama_ayah', '`nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nik_ayah', '`nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ayah');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nama_ibu', '`nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ayah');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nik_ibu', '`nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ibu');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nama_saksi1', '`nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ibu');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nik_saksi1', '`nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi1');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nama_saksi2', '`nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_saksi1');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nik_saksi2', '`nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi2');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nama_bayi', '`nama_bayi` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_saksi2');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'tempat_lahir', '`tempat_lahir` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_bayi');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'jenis_kelamin', '`jenis_kelamin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tempat_lahir');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'tanggal_lahir', '`tanggal_lahir` date DEFAULT NULL', 'jenis_kelamin');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'urutan_kelahiran', '`urutan_kelahiran` int DEFAULT NULL', 'tanggal_lahir');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'Waktu', '`Waktu` time DEFAULT NULL', 'urutan_kelahiran');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'Waktu');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'keterangan', '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'keterangan');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'jenis_kelahiran', '`jenis_kelahiran` tinyint unsigned DEFAULT NULL COMMENT ''1=Tunggal 2=Kembar 2 3=Kembar 3 4=Kembar 4 5=Lainnya''', 'nama_folder');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'penolong_kelahiran', '`penolong_kelahiran` tinyint unsigned DEFAULT NULL COMMENT ''1=Dokter 2=Bidan/Perawat 3=Dukun 4=Lainnya''', 'jenis_kelahiran');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'berat_bayi_gram', '`berat_bayi_gram` smallint unsigned DEFAULT NULL COMMENT ''Berat bayi dalam gram''', 'penolong_kelahiran');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'panjang_bayi_mm', '`panjang_bayi_mm` smallint unsigned DEFAULT NULL COMMENT ''Panjang bayi dalam milimeter''', 'berat_bayi_gram');
CALL siprima_tmp_add_column('tb_akta_kelahiran_ahlak', 'formulir_dibuat_at', '`formulir_dibuat_at` datetime DEFAULT NULL COMMENT ''Saat petugas menyetujui pernyataan dan menandatangani formulir''', 'panjang_bayi_mm');
CALL siprima_tmp_widen_varchar('tb_akta_kelahiran_ahlak', 'keterangan', 500, '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
CALL siprima_tmp_add_index('tb_akta_kelahiran_ahlak', 'FK3_KK_user', 'KEY `FK3_KK_user` (`id_user`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kelahiran_ahlak', 'FK1_KK_kecamatan', 'KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kelahiran_ahlak', 'FK2_KK_desa', 'KEY `FK2_KK_desa` (`id_desa`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kelahiran_ahlak', 'FK4_tb_akta_lahir_tb_puskes', 'KEY `FK4_tb_akta_lahir_tb_puskes` (`id_puskes`) USING BTREE');

-- tb_akta_kelahiran_rs
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'id_user', '`id_user` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'id_rs', '`id_rs` int DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_rs');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nama_ayah', '`nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nik_ayah', '`nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ayah');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nama_ibu', '`nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ayah');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nik_ibu', '`nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ibu');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nama_saksi1', '`nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ibu');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nik_saksi1', '`nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi1');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nama_saksi2', '`nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_saksi1');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nik_saksi2', '`nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi2');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nama_bayi', '`nama_bayi` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_saksi2');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'tempat_lahir', '`tempat_lahir` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_bayi');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'jenis_kelamin', '`jenis_kelamin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tempat_lahir');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'tanggal_lahir', '`tanggal_lahir` date DEFAULT NULL', 'jenis_kelamin');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'urutan_kelahiran', '`urutan_kelahiran` int DEFAULT NULL', 'tanggal_lahir');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'Waktu', '`Waktu` time DEFAULT NULL', 'urutan_kelahiran');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'Waktu');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'keterangan', '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'keterangan');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'jenis_kelahiran', '`jenis_kelahiran` tinyint unsigned DEFAULT NULL COMMENT ''1=Tunggal 2=Kembar 2 3=Kembar 3 4=Kembar 4 5=Lainnya''', 'nama_folder');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'penolong_kelahiran', '`penolong_kelahiran` tinyint unsigned DEFAULT NULL COMMENT ''1=Dokter 2=Bidan/Perawat 3=Dukun 4=Lainnya''', 'jenis_kelahiran');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'berat_bayi_gram', '`berat_bayi_gram` smallint unsigned DEFAULT NULL COMMENT ''Berat bayi dalam gram''', 'penolong_kelahiran');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'panjang_bayi_mm', '`panjang_bayi_mm` smallint unsigned DEFAULT NULL COMMENT ''Panjang bayi dalam milimeter''', 'berat_bayi_gram');
CALL siprima_tmp_add_column('tb_akta_kelahiran_rs', 'formulir_dibuat_at', '`formulir_dibuat_at` datetime DEFAULT NULL COMMENT ''Saat petugas menyetujui pernyataan dan menandatangani formulir''', 'panjang_bayi_mm');
CALL siprima_tmp_widen_varchar('tb_akta_kelahiran_rs', 'keterangan', 500, '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
CALL siprima_tmp_add_index('tb_akta_kelahiran_rs', 'FK3_KK_user', 'KEY `FK3_KK_user` (`id_user`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kelahiran_rs', 'FK1_KK_kecamatan', 'KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kelahiran_rs', 'FK2_KK_desa', 'KEY `FK2_KK_desa` (`id_desa`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kelahiran_rs', 'tb_akta_lahir_id_rs_tb_rumahsakit', 'KEY `tb_akta_lahir_id_rs_tb_rumahsakit` (`id_rs`) USING BTREE');

-- tb_akta_kematian
CALL siprima_tmp_add_column('tb_akta_kematian', 'id_user', '`id_user` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_akta_kematian', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_akta_kematian', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kematian', 'id_puskes', '`id_puskes` int DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_akta_kematian', 'id_rs', '`id_rs` int DEFAULT NULL', 'id_puskes');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_rs');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_akta_kematian', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_akta_kematian', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kematian', 'dusun', '`dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nama_meninggal', '`nama_meninggal` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dusun');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nik_meninggal', '`nik_meninggal` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_meninggal');
CALL siprima_tmp_add_column('tb_akta_kematian', 'tanggal_kematian', '`tanggal_kematian` date DEFAULT NULL', 'nik_meninggal');
CALL siprima_tmp_add_column('tb_akta_kematian', 'tempat', '`tempat` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tanggal_kematian');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nama_ayah', '`nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tempat');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nik_ayah', '`nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ayah');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nama_ibu', '`nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ayah');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nik_ibu', '`nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ibu');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nama_saksi1', '`nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ibu');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nik_saksi1', '`nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi1');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nama_saksi2', '`nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_saksi1');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nik_saksi2', '`nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi2');
CALL siprima_tmp_add_column('tb_akta_kematian', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'nik_saksi2');
CALL siprima_tmp_add_column('tb_akta_kematian', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_akta_kematian', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_akta_kematian', 'keterangan', '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_akta_kematian', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'keterangan');
CALL siprima_tmp_add_column('tb_akta_kematian', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_akta_kematian', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_akta_kematian', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_column('tb_akta_kematian', 'formulir_dibuat_at', '`formulir_dibuat_at` datetime DEFAULT NULL COMMENT ''Saat pemohon menyetujui pernyataan dan menandatangani formulir''', 'nama_folder');
CALL siprima_tmp_widen_varchar('tb_akta_kematian', 'keterangan', 500, '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
CALL siprima_tmp_add_index('tb_akta_kematian', 'FK3_KK_user', 'KEY `FK3_KK_user` (`id_user`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kematian', 'FK1_KK_kecamatan', 'KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kematian', 'FK2_KK_desa', 'KEY `FK2_KK_desa` (`id_desa`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kematian', 'id_puskes_tb_puskes', 'KEY `id_puskes_tb_puskes` (`id_puskes`)');
CALL siprima_tmp_add_index('tb_akta_kematian', 'id_rs_tb_rs', 'KEY `id_rs_tb_rs` (`id_rs`)');

-- tb_akta_kematian_rs
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'id_user', '`id_user` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'id_rs', '`id_rs` int DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_rs');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'dusun', '`dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nama_meninggal', '`nama_meninggal` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dusun');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nik_meninggal', '`nik_meninggal` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_meninggal');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'tanggal_kematian', '`tanggal_kematian` date DEFAULT NULL', 'nik_meninggal');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'tempat', '`tempat` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tanggal_kematian');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nama_ayah', '`nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tempat');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nik_ayah', '`nik_ayah` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ayah');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nama_ibu', '`nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ayah');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nik_ibu', '`nik_ibu` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ibu');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nama_saksi1', '`nama_saksi1` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_ibu');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nik_saksi1', '`nik_saksi1` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi1');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nama_saksi2', '`nama_saksi2` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_saksi1');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nik_saksi2', '`nik_saksi2` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_saksi2');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'nik_saksi2');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'keterangan', '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'keterangan');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_column('tb_akta_kematian_rs', 'formulir_dibuat_at', '`formulir_dibuat_at` datetime DEFAULT NULL COMMENT ''Saat petugas menyetujui pernyataan dan menandatangani formulir''', 'nama_folder');
CALL siprima_tmp_widen_varchar('tb_akta_kematian_rs', 'keterangan', 500, '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
CALL siprima_tmp_add_index('tb_akta_kematian_rs', 'FK3_KK_user', 'KEY `FK3_KK_user` (`id_user`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kematian_rs', 'FK1_KK_kecamatan', 'KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kematian_rs', 'FK2_KK_desa', 'KEY `FK2_KK_desa` (`id_desa`) USING BTREE');
CALL siprima_tmp_add_index('tb_akta_kematian_rs', 'id_rs_tb_rs', 'KEY `id_rs_tb_rs` (`id_rs`) USING BTREE');

-- tb_cetak_ktp
CALL siprima_tmp_add_column('tb_cetak_ktp', 'id_user', '`id_user` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'id_kecamatan', '`id_kecamatan` int NOT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'id_desa', '`id_desa` int NOT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'jenis_permohonan', '`jenis_permohonan` enum(''milik_sendiri'',''milik_orang_lain'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''milik_sendiri'' COMMENT ''KTP-el milik sendiri / milik orang lain (dengan surat kuasa F-1.07)''', 'nama_desa');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'satu_kk', '`satu_kk` tinyint(1) NOT NULL DEFAULT ''0'' COMMENT ''1 = milik sendiri + anggota satu KK''', 'jenis_permohonan');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'nama_pemilik', '`nama_pemilik` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Nama pemilik KTP-el''', 'satu_kk');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'nik_pemilik', '`nik_pemilik` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''NIK pemilik KTP-el''', 'nama_pemilik');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'daftar_pemilik', '`daftar_pemilik` text COLLATE utf8mb4_unicode_ci COMMENT ''JSON daftar pemilik KTP-el (nama, nik)''', 'nik_pemilik');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'daftar_pemilik');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT ''diajukan''', 'dokumen');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'keterangan', '`keterangan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'metode_pengambilan', '`metode_pengambilan` enum(''ambil_kantor'',''cod'') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Cara pengambilan pilihan pemohon; NULL = belum memilih''', 'keterangan');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'alamat_pengiriman', '`alamat_pengiriman` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Alamat pengiriman COD''', 'metode_pengambilan');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'no_hp_penerima', '`no_hp_penerima` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''No HP penerima COD''', 'alamat_pengiriman');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'tahap_pengambilan', '`tahap_pengambilan` enum(''sudah_diambil'',''dikirim'',''diterima'') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Tahap ditandai operator; NULL = belum ditandai''', 'no_hp_penerima');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'pengambilan_updated_at', '`pengambilan_updated_at` datetime DEFAULT NULL COMMENT ''Waktu perubahan pilihan/tahap pengambilan terakhir''', 'tahap_pengambilan');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'pengambilan_updated_at');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_cetak_ktp', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_index('tb_cetak_ktp', 'idx_cetak_ktp_user', 'KEY `idx_cetak_ktp_user` (`id_user`)');
CALL siprima_tmp_add_index('tb_cetak_ktp', 'idx_cetak_ktp_kecamatan', 'KEY `idx_cetak_ktp_kecamatan` (`id_kecamatan`)');
CALL siprima_tmp_add_index('tb_cetak_ktp', 'idx_cetak_ktp_desa', 'KEY `idx_cetak_ktp_desa` (`id_desa`)');
CALL siprima_tmp_add_index('tb_cetak_ktp', 'idx_cetak_ktp_status', 'KEY `idx_cetak_ktp_status` (`status`)');

-- tb_document_features
CALL siprima_tmp_add_column('tb_document_features', 'payload', '`payload` longtext NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_document_features', 'revision', '`revision` int unsigned NOT NULL DEFAULT ''1''', 'payload');

-- tb_document_features_audit
CALL siprima_tmp_add_column('tb_document_features_audit', 'admin_id', '`admin_id` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_document_features_audit', 'changed_at', '`changed_at` datetime NOT NULL', 'admin_id');
CALL siprima_tmp_add_column('tb_document_features_audit', 'action', '`action` varchar(100) NOT NULL', 'changed_at');
CALL siprima_tmp_add_column('tb_document_features_audit', 'before_payload', '`before_payload` longtext NOT NULL', 'action');
CALL siprima_tmp_add_column('tb_document_features_audit', 'after_payload', '`after_payload` longtext NOT NULL', 'before_payload');

-- tb_formulir
CALL siprima_tmp_add_column('tb_formulir', 'nama_formulir', '`nama_formulir` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT ''0''', 'id');
CALL siprima_tmp_add_column('tb_formulir', 'file_formulir', '`file_formulir` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT ''0''', 'nama_formulir');

-- tb_kartukeluarga
CALL siprima_tmp_add_column('tb_kartukeluarga', 'id_user', '`id_user` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'dusun', '`dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'nama_kepalaKeluarga', '`nama_kepalaKeluarga` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dusun');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'anggotaKeluarga', '`anggotaKeluarga` text COLLATE utf8mb4_unicode_ci', 'nama_kepalaKeluarga');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'anggotaKeluarga');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'keterangan', '`keterangan` text COLLATE utf8mb4_unicode_ci', 'alasan');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'keterangan');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'jenis_permohonan', '`jenis_permohonan` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Perubahan Data / Penambahan Anggota Keluarga / KK Hilang-Rusak / Pisah KK / Lainnya''', 'nama_folder');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'formulir_dibuat_at', '`formulir_dibuat_at` datetime DEFAULT NULL COMMENT ''Saat pemohon menyetujui pernyataan dan menandatangani formulir''', 'jenis_permohonan');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'kategori_layanan', '`kategori_layanan` enum(''kk_baru'',''kk_hilang'',''cetak_biodata'',''perubahan_data'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''kk_baru'' COMMENT ''Kategori layanan Kartu Keluarga''', 'formulir_dibuat_at');
CALL siprima_tmp_add_column('tb_kartukeluarga', 'nik_subjek', '`nik_subjek` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''NIK yang dilayani; wajib untuk kategori selain kk_baru''', 'kategori_layanan');
CALL siprima_tmp_add_index('tb_kartukeluarga', 'FK3_KK_user', 'KEY `FK3_KK_user` (`id_user`) USING BTREE');
CALL siprima_tmp_add_index('tb_kartukeluarga', 'FK1_KK_kecamatan', 'KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE');
CALL siprima_tmp_add_index('tb_kartukeluarga', 'FK2_KK_desa', 'KEY `FK2_KK_desa` (`id_desa`) USING BTREE');

-- tb_kartukeluarga_kua
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'id_user', '`id_user` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'id_kua', '`id_kua` int DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'alamat_catin', '`alamat_catin` enum(''alamat sama'',''alamat beda'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_kua');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alamat_catin');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'dusun', '`dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'no_kk_lama_suami', '`no_kk_lama_suami` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dusun');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'no_kk_lama_istri', '`no_kk_lama_istri` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_kk_lama_suami');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'nama_kepalaKeluarga', '`nama_kepalaKeluarga` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_kk_lama_istri');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'anggotaKeluarga', '`anggotaKeluarga` text COLLATE utf8mb4_unicode_ci', 'nama_kepalaKeluarga');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'anggotaKeluarga');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'keterangan', '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'keterangan');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'jenis_permohonan', '`jenis_permohonan` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Jenis permohonan F-1.01 (whitelist di PHP)''', 'nama_folder');
CALL siprima_tmp_add_column('tb_kartukeluarga_kua', 'formulir_dibuat_at', '`formulir_dibuat_at` datetime DEFAULT NULL COMMENT ''Saat petugas menyetujui pernyataan dan menandatangani formulir''', 'jenis_permohonan');
CALL siprima_tmp_widen_varchar('tb_kartukeluarga_kua', 'keterangan', 500, '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
CALL siprima_tmp_add_index('tb_kartukeluarga_kua', 'FK3_KK_user', 'KEY `FK3_KK_user` (`id_user`) USING BTREE');
CALL siprima_tmp_add_index('tb_kartukeluarga_kua', 'FK1_KK_kecamatan', 'KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE');
CALL siprima_tmp_add_index('tb_kartukeluarga_kua', 'FK2_KK_desa', 'KEY `FK2_KK_desa` (`id_desa`) USING BTREE');
CALL siprima_tmp_add_index('tb_kartukeluarga_kua', 'id_kua_kk', 'KEY `id_kua_kk` (`id_kua`)');

-- tb_konfirmasi_dokumen_email
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'source_table', '`source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'permohonan_id', '`permohonan_id` int NOT NULL', 'source_table');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'id_user_pemohon', '`id_user_pemohon` int NOT NULL', 'permohonan_id');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_user_pemohon');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'jenis_layanan', '`jenis_layanan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'nama_pemohon', '`nama_pemohon` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'jenis_layanan');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'nama_subjek', '`nama_subjek` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'selesai_at', '`selesai_at` datetime NOT NULL', 'nama_subjek');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'eligible_at', '`eligible_at` datetime NOT NULL', 'selesai_at');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'status_konfirmasi', '`status_konfirmasi` enum(''sudah_masuk'',''belum_masuk'',''diunggah_operator'',''sebagian_diunggah_perlu_pengajuan_baru'',''perlu_pengajuan_baru'',''dibatalkan'') COLLATE utf8mb4_unicode_ci NOT NULL', 'eligible_at');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'catatan_pemohon', '`catatan_pemohon` text COLLATE utf8mb4_unicode_ci', 'status_konfirmasi');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'catatan_operator', '`catatan_operator` text COLLATE utf8mb4_unicode_ci', 'catatan_pemohon');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'confirmed_at', '`confirmed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'catatan_operator');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'operator_id', '`operator_id` int DEFAULT NULL', 'confirmed_at');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'tindak_lanjut_operator_id', '`tindak_lanjut_operator_id` int DEFAULT NULL', 'operator_id');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'operator_confirmed_at', '`operator_confirmed_at` datetime DEFAULT NULL', 'tindak_lanjut_operator_id');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'tindak_lanjut_at', '`tindak_lanjut_at` datetime DEFAULT NULL', 'operator_confirmed_at');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'created_at', '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'tindak_lanjut_at');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email', 'updated_at', '`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL siprima_tmp_widen_enum('tb_konfirmasi_dokumen_email', 'status_konfirmasi', 'enum(''sudah_masuk'',''belum_masuk'',''diunggah_operator'',''sebagian_diunggah_perlu_pengajuan_baru'',''perlu_pengajuan_baru'',''dibatalkan'')', 'enum(''sudah_masuk'',''belum_masuk'',''diunggah_operator'',''dibatalkan'')', '`status_konfirmasi` enum(''sudah_masuk'',''belum_masuk'',''diunggah_operator'',''sebagian_diunggah_perlu_pengajuan_baru'',''perlu_pengajuan_baru'',''dibatalkan'') COLLATE utf8mb4_unicode_ci NOT NULL');
CALL siprima_tmp_add_index('tb_konfirmasi_dokumen_email', 'uq_konfirmasi_email_permohonan', 'UNIQUE KEY `uq_konfirmasi_email_permohonan` (`source_table`,`permohonan_id`)');
CALL siprima_tmp_add_index('tb_konfirmasi_dokumen_email', 'idx_konfirmasi_email_queue', 'KEY `idx_konfirmasi_email_queue` (`status_konfirmasi`,`id_kecamatan`,`confirmed_at`)');
CALL siprima_tmp_add_index('tb_konfirmasi_dokumen_email', 'idx_konfirmasi_email_user', 'KEY `idx_konfirmasi_email_user` (`id_user_pemohon`,`updated_at`)');

-- tb_konfirmasi_dokumen_email_file
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email_file', 'konfirmasi_id', '`konfirmasi_id` bigint unsigned NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email_file', 'uploaded_by', '`uploaded_by` int NOT NULL', 'konfirmasi_id');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email_file', 'original_name', '`original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'uploaded_by');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email_file', 'stored_name', '`stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'original_name');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email_file', 'file_path', '`file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL', 'stored_name');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email_file', 'file_size', '`file_size` int unsigned NOT NULL', 'file_path');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email_file', 'mime_type', '`mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''application/pdf''', 'file_size');
CALL siprima_tmp_add_column('tb_konfirmasi_dokumen_email_file', 'uploaded_at', '`uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'mime_type');
CALL siprima_tmp_add_index('tb_konfirmasi_dokumen_email_file', 'idx_konfirmasi_email_file_parent', 'KEY `idx_konfirmasi_email_file_parent` (`konfirmasi_id`,`uploaded_at`)');

-- tb_konsultasi_data
CALL siprima_tmp_add_column('tb_konsultasi_data', 'kode_konsultasi', '`kode_konsultasi` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'id_user', '`id_user` int NOT NULL COMMENT ''Pemohon umum atau petugas desa yang mengajukan''', 'kode_konsultasi');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'id_kecamatan', '`id_kecamatan` int NOT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'id_desa', '`id_desa` int NOT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'nama_dicek', '`nama_dicek` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT ''Nama orang yang datanya dicek''', 'nama_desa');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'nik_dicek', '`nik_dicek` char(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT ''NIK orang yang datanya dicek''', 'nama_dicek');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'keterangan', '`keterangan` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL', 'nik_dicek');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'status', '`status` enum(''diajukan'',''proses_cek'',''selesai'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''diajukan''', 'keterangan');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'status');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL siprima_tmp_add_column('tb_konsultasi_data', 'selesai_at', '`selesai_at` datetime DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_index('tb_konsultasi_data', 'uk_konsultasi_kode', 'UNIQUE KEY `uk_konsultasi_kode` (`kode_konsultasi`)');
CALL siprima_tmp_add_index('tb_konsultasi_data', 'idx_konsultasi_user', 'KEY `idx_konsultasi_user` (`id_user`)');
CALL siprima_tmp_add_index('tb_konsultasi_data', 'idx_konsultasi_kecamatan_status', 'KEY `idx_konsultasi_kecamatan_status` (`id_kecamatan`,`status`)');
CALL siprima_tmp_add_index('tb_konsultasi_data', 'fk_konsultasi_desa', 'KEY `fk_konsultasi_desa` (`id_desa`)');

-- tb_konsultasi_data_dicek
CALL siprima_tmp_add_column('tb_konsultasi_data_dicek', 'konsultasi_id', '`konsultasi_id` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_konsultasi_data_dicek', 'urutan', '`urutan` tinyint unsigned NOT NULL DEFAULT ''1''', 'konsultasi_id');
CALL siprima_tmp_add_column('tb_konsultasi_data_dicek', 'nama_dicek', '`nama_dicek` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'urutan');
CALL siprima_tmp_add_column('tb_konsultasi_data_dicek', 'nik_dicek', '`nik_dicek` char(16) COLLATE utf8mb4_unicode_ci NOT NULL', 'nama_dicek');
CALL siprima_tmp_add_index('tb_konsultasi_data_dicek', 'idx_konsultasi_dicek_konsultasi', 'KEY `idx_konsultasi_dicek_konsultasi` (`konsultasi_id`,`urutan`)');
CALL siprima_tmp_add_index('tb_konsultasi_data_dicek', 'idx_konsultasi_dicek_nik', 'KEY `idx_konsultasi_dicek_nik` (`nik_dicek`)');

-- tb_konsultasi_jawaban
CALL siprima_tmp_add_column('tb_konsultasi_jawaban', 'konsultasi_id', '`konsultasi_id` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_konsultasi_jawaban', 'operator_id', '`operator_id` int NOT NULL', 'konsultasi_id');
CALL siprima_tmp_add_column('tb_konsultasi_jawaban', 'pesan', '`pesan` text COLLATE utf8mb4_unicode_ci NOT NULL', 'operator_id');
CALL siprima_tmp_add_column('tb_konsultasi_jawaban', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'pesan');
CALL siprima_tmp_add_index('tb_konsultasi_jawaban', 'idx_konsultasi_jawaban_konsultasi', 'KEY `idx_konsultasi_jawaban_konsultasi` (`konsultasi_id`)');
CALL siprima_tmp_add_index('tb_konsultasi_jawaban', 'fk_konsultasi_jawaban_operator', 'KEY `fk_konsultasi_jawaban_operator` (`operator_id`)');

-- tb_konsultasi_lampiran
CALL siprima_tmp_add_column('tb_konsultasi_lampiran', 'konsultasi_id', '`konsultasi_id` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_konsultasi_lampiran', 'jawaban_id', '`jawaban_id` int DEFAULT NULL', 'konsultasi_id');
CALL siprima_tmp_add_column('tb_konsultasi_lampiran', 'jenis', '`jenis` enum(''foto_ktp'',''dokumen'',''bukti_operator'') COLLATE utf8mb4_unicode_ci NOT NULL', 'jawaban_id');
CALL siprima_tmp_add_column('tb_konsultasi_lampiran', 'original_name', '`original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'jenis');
CALL siprima_tmp_add_column('tb_konsultasi_lampiran', 'stored_name', '`stored_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL', 'original_name');
CALL siprima_tmp_add_column('tb_konsultasi_lampiran', 'file_path', '`file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'stored_name');
CALL siprima_tmp_add_column('tb_konsultasi_lampiran', 'file_size', '`file_size` int NOT NULL DEFAULT ''0''', 'file_path');
CALL siprima_tmp_add_column('tb_konsultasi_lampiran', 'mime_type', '`mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'file_size');
CALL siprima_tmp_add_column('tb_konsultasi_lampiran', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'mime_type');
CALL siprima_tmp_add_index('tb_konsultasi_lampiran', 'idx_konsultasi_lampiran_konsultasi', 'KEY `idx_konsultasi_lampiran_konsultasi` (`konsultasi_id`)');
CALL siprima_tmp_add_index('tb_konsultasi_lampiran', 'idx_konsultasi_lampiran_jawaban', 'KEY `idx_konsultasi_lampiran_jawaban` (`jawaban_id`)');

-- tb_laporan_peristiwa
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'id_user', '`id_user` int DEFAULT NULL', 'id_lapor');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'id_kecamatan', '`id_kecamatan` int DEFAULT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'id_desa', '`id_desa` int DEFAULT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nama_pelapor', '`nama_pelapor` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nik_pelapor', '`nik_pelapor` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pelapor');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pelapor');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'jenis_peristiwa', '`jenis_peristiwa` enum(''lahir'',''mati'',''rentan_adminduk'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nama_subjek', '`nama_subjek` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'jenis_peristiwa');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nik_subjek', '`nik_subjek` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_subjek');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'tempat_lahir_mati', '`tempat_lahir_mati` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_subjek');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'tanggal_lahir_mati', '`tanggal_lahir_mati` date DEFAULT NULL', 'tempat_lahir_mati');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nama_ayah', '`nama_ayah` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tanggal_lahir_mati');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nama_ibu', '`nama_ibu` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ayah');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'keterangan', '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_ibu');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'keterangan');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'alasan');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nama_folder', '`nama_folder` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'jenis_kelamin', '`jenis_kelamin` enum(''L'',''P'') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Jenis kelamin yang meninggal''', 'nama_folder');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'umur', '`umur` smallint unsigned DEFAULT NULL COMMENT ''Umur saat meninggal (tahun)''', 'jenis_kelamin');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'dusun', '`dusun` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Dusun/lingkungan alamat subjek''', 'umur');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'rt', '`rt` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dusun');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'rw', '`rw` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'rt');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'sebab_kematian', '`sebab_kematian` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'rw');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'tanggal_dimakamkan', '`tanggal_dimakamkan` date DEFAULT NULL', 'sebab_kematian');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'lokasi_makam', '`lokasi_makam` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Nama TPU/lokasi pemakaman''', 'tanggal_dimakamkan');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nomor_makam', '`nomor_makam` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Blok/nomor makam''', 'lokasi_makam');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'nama_ahli_waris', '`nama_ahli_waris` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nomor_makam');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'no_hp_ahli_waris', '`no_hp_ahli_waris` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Nomor ahli waris yang bisa dihubungi''', 'nama_ahli_waris');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'permohonan_tabel', '`permohonan_tabel` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''tb_akta_kelahiran / tb_akta_kematian / tb_kartukeluarga''', 'no_hp_ahli_waris');
CALL siprima_tmp_add_column('tb_laporan_peristiwa', 'permohonan_id', '`permohonan_id` int DEFAULT NULL COMMENT ''id permohonan pada permohonan_tabel''', 'permohonan_tabel');
CALL siprima_tmp_widen_varchar('tb_laporan_peristiwa', 'keterangan', 500, '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
CALL siprima_tmp_add_index('tb_laporan_peristiwa', 'FK1_id_user_tb_user', 'KEY `FK1_id_user_tb_user` (`id_user`)');
CALL siprima_tmp_add_index('tb_laporan_peristiwa', 'FK2_id_kec_tb_kecamatan', 'KEY `FK2_id_kec_tb_kecamatan` (`id_kecamatan`)');
CALL siprima_tmp_add_index('tb_laporan_peristiwa', 'FK3_id_desa_tb_desa', 'KEY `FK3_id_desa_tb_desa` (`id_desa`)');
CALL siprima_tmp_add_index('tb_laporan_peristiwa', 'idx_laporan_permohonan', 'KEY `idx_laporan_permohonan` (`permohonan_tabel`,`permohonan_id`)');

-- tb_layanan_aktif
CALL siprima_tmp_add_column('tb_layanan_aktif', 'payload', '`payload` longtext NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_layanan_aktif', 'revision', '`revision` int unsigned NOT NULL DEFAULT ''1''', 'payload');

-- tb_layanan_aktif_audit
CALL siprima_tmp_add_column('tb_layanan_aktif_audit', 'admin_id', '`admin_id` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_layanan_aktif_audit', 'changed_at', '`changed_at` datetime NOT NULL', 'admin_id');
CALL siprima_tmp_add_column('tb_layanan_aktif_audit', 'action', '`action` varchar(100) NOT NULL', 'changed_at');
CALL siprima_tmp_add_column('tb_layanan_aktif_audit', 'before_payload', '`before_payload` longtext NOT NULL', 'action');
CALL siprima_tmp_add_column('tb_layanan_aktif_audit', 'after_payload', '`after_payload` longtext NOT NULL', 'before_payload');

-- tb_mobile_idempotency
CALL siprima_tmp_add_column('tb_mobile_idempotency', 'scope', '`scope` varchar(160) NOT NULL', 'key_hash');
CALL siprima_tmp_add_column('tb_mobile_idempotency', 'payload_hash', '`payload_hash` char(64) NOT NULL', 'scope');
CALL siprima_tmp_add_column('tb_mobile_idempotency', 'response_json', '`response_json` longtext', 'payload_hash');
CALL siprima_tmp_add_column('tb_mobile_idempotency', 'created_at', '`created_at` bigint NOT NULL', 'response_json');

-- tb_mobile_migrations
CALL siprima_tmp_add_column('tb_mobile_migrations', 'applied_at', '`applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP', 'version');

-- tb_mobile_outbox
CALL siprima_tmp_add_column('tb_mobile_outbox', 'source_table', '`source_table` varchar(64) NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_mobile_outbox', 'permohonan_id', '`permohonan_id` int NOT NULL', 'source_table');
CALL siprima_tmp_add_column('tb_mobile_outbox', 'event_type', '`event_type` varchar(32) NOT NULL', 'permohonan_id');
CALL siprima_tmp_add_column('tb_mobile_outbox', 'created_at', '`created_at` bigint NOT NULL', 'event_type');
CALL siprima_tmp_add_column('tb_mobile_outbox', 'delivered_at', '`delivered_at` bigint DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_index('tb_mobile_outbox', 'idx_mobile_outbox_pending', 'KEY `idx_mobile_outbox_pending` (`delivered_at`,`id`)');

-- tb_mobile_rate_limits
CALL siprima_tmp_add_column('tb_mobile_rate_limits', 'hits', '`hits` int NOT NULL', 'bucket');
CALL siprima_tmp_add_column('tb_mobile_rate_limits', 'expires_at', '`expires_at` bigint NOT NULL', 'hits');
CALL siprima_tmp_add_index('tb_mobile_rate_limits', 'idx_mobile_rate_expiry', 'KEY `idx_mobile_rate_expiry` (`expires_at`)');

-- tb_mobile_sessions
CALL siprima_tmp_add_column('tb_mobile_sessions', 'user_id', '`user_id` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_mobile_sessions', 'device_name', '`device_name` varchar(100) NOT NULL', 'user_id');
CALL siprima_tmp_add_column('tb_mobile_sessions', 'access_hash', '`access_hash` char(64) NOT NULL', 'device_name');
CALL siprima_tmp_add_column('tb_mobile_sessions', 'refresh_hash', '`refresh_hash` char(64) NOT NULL', 'access_hash');
CALL siprima_tmp_add_column('tb_mobile_sessions', 'fingerprint', '`fingerprint` char(64) NOT NULL', 'refresh_hash');
CALL siprima_tmp_add_column('tb_mobile_sessions', 'access_expires_at', '`access_expires_at` bigint NOT NULL', 'fingerprint');
CALL siprima_tmp_add_column('tb_mobile_sessions', 'expires_at', '`expires_at` bigint NOT NULL', 'access_expires_at');
CALL siprima_tmp_add_column('tb_mobile_sessions', 'last_used_at', '`last_used_at` bigint NOT NULL', 'expires_at');
CALL siprima_tmp_add_column('tb_mobile_sessions', 'created_at', '`created_at` bigint NOT NULL', 'last_used_at');
CALL siprima_tmp_add_column('tb_mobile_sessions', 'revoked_at', '`revoked_at` bigint DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_index('tb_mobile_sessions', 'access_hash', 'UNIQUE KEY `access_hash` (`access_hash`)');
CALL siprima_tmp_add_index('tb_mobile_sessions', 'refresh_hash', 'UNIQUE KEY `refresh_hash` (`refresh_hash`)');
CALL siprima_tmp_add_index('tb_mobile_sessions', 'idx_mobile_user', 'KEY `idx_mobile_user` (`user_id`)');
CALL siprima_tmp_add_index('tb_mobile_sessions', 'idx_mobile_expiry', 'KEY `idx_mobile_expiry` (`expires_at`)');

-- tb_mobile_uploads
CALL siprima_tmp_add_column('tb_mobile_uploads', 'user_id', '`user_id` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_mobile_uploads', 'service_code', '`service_code` varchar(32) NOT NULL', 'user_id');
CALL siprima_tmp_add_column('tb_mobile_uploads', 'doc_type', '`doc_type` varchar(64) NOT NULL', 'service_code');
CALL siprima_tmp_add_column('tb_mobile_uploads', 'original_name', '`original_name` varchar(200) NOT NULL', 'doc_type');
CALL siprima_tmp_add_column('tb_mobile_uploads', 'stored_name', '`stored_name` varchar(80) NOT NULL', 'original_name');
CALL siprima_tmp_add_column('tb_mobile_uploads', 'mime_type', '`mime_type` varchar(100) NOT NULL', 'stored_name');
CALL siprima_tmp_add_column('tb_mobile_uploads', 'file_size', '`file_size` int NOT NULL', 'mime_type');
CALL siprima_tmp_add_column('tb_mobile_uploads', 'created_at', '`created_at` bigint NOT NULL', 'file_size');
CALL siprima_tmp_add_column('tb_mobile_uploads', 'expires_at', '`expires_at` bigint NOT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_mobile_uploads', 'application_ref', '`application_ref` varchar(80) DEFAULT NULL', 'expires_at');
CALL siprima_tmp_add_index('tb_mobile_uploads', 'idx_mobile_upload_owner', 'KEY `idx_mobile_upload_owner` (`user_id`,`application_ref`,`expires_at`)');

-- tb_mobile_used_refresh
CALL siprima_tmp_add_column('tb_mobile_used_refresh', 'session_id', '`session_id` char(32) NOT NULL', 'token_hash');
CALL siprima_tmp_add_column('tb_mobile_used_refresh', 'expires_at', '`expires_at` bigint NOT NULL', 'session_id');
CALL siprima_tmp_add_index('tb_mobile_used_refresh', 'idx_mobile_refresh_expiry', 'KEY `idx_mobile_refresh_expiry` (`expires_at`)');

-- tb_notifikasi
CALL siprima_tmp_add_column('tb_notifikasi', 'user_id', '`user_id` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_notifikasi', 'judul', '`judul` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'user_id');
CALL siprima_tmp_add_column('tb_notifikasi', 'pesan', '`pesan` text COLLATE utf8mb4_unicode_ci NOT NULL', 'judul');
CALL siprima_tmp_add_column('tb_notifikasi', 'tipe', '`tipe` enum(''info'',''success'',''warning'',''danger'') COLLATE utf8mb4_unicode_ci DEFAULT ''info''', 'pesan');
CALL siprima_tmp_add_column('tb_notifikasi', 'target_url', '`target_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tipe');
CALL siprima_tmp_add_column('tb_notifikasi', 'source_table', '`source_table` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'target_url');
CALL siprima_tmp_add_column('tb_notifikasi', 'permohonan_id', '`permohonan_id` int DEFAULT NULL', 'source_table');
CALL siprima_tmp_add_column('tb_notifikasi', 'dibaca', '`dibaca` tinyint(1) DEFAULT ''0''', 'permohonan_id');
CALL siprima_tmp_add_column('tb_notifikasi', 'dilihat', '`dilihat` tinyint(1) NOT NULL DEFAULT ''0''', 'dibaca');
CALL siprima_tmp_add_column('tb_notifikasi', 'created_at', '`created_at` datetime DEFAULT CURRENT_TIMESTAMP', 'dilihat');
CALL siprima_tmp_add_index('tb_notifikasi', 'idx_user_dibaca', 'KEY `idx_user_dibaca` (`user_id`,`dibaca`) USING BTREE');
CALL siprima_tmp_add_index('tb_notifikasi', 'idx_created', 'KEY `idx_created` (`created_at`) USING BTREE');
CALL siprima_tmp_add_index('tb_notifikasi', 'idx_notifikasi_permohonan', 'KEY `idx_notifikasi_permohonan` (`source_table`,`permohonan_id`)');
CALL siprima_tmp_add_index('tb_notifikasi', 'idx_notifikasi_user_dilihat', 'KEY `idx_notifikasi_user_dilihat` (`user_id`,`dilihat`)');

-- tb_operator_kecamatan
CALL siprima_tmp_add_column('tb_operator_kecamatan', 'operator_id', '`operator_id` int DEFAULT NULL', 'id');
CALL siprima_tmp_add_column('tb_operator_kecamatan', 'kecamatan_id', '`kecamatan_id` int DEFAULT NULL', 'operator_id');
CALL siprima_tmp_add_column('tb_operator_kecamatan', 'kua_id', '`kua_id` int DEFAULT NULL', 'kecamatan_id');
CALL siprima_tmp_add_column('tb_operator_kecamatan', 'puskes_id', '`puskes_id` int DEFAULT NULL', 'kua_id');
CALL siprima_tmp_add_column('tb_operator_kecamatan', 'rs_id', '`rs_id` int DEFAULT NULL', 'puskes_id');
CALL siprima_tmp_add_column('tb_operator_kecamatan', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'rs_id');
CALL siprima_tmp_add_index('tb_operator_kecamatan', 'FK_tb_operator_kecamatan_tb_kecamatan', 'KEY `FK_tb_operator_kecamatan_tb_kecamatan` (`kecamatan_id`) USING BTREE');
CALL siprima_tmp_add_index('tb_operator_kecamatan', 'FK3_tb_opr_kec_tb_kua', 'KEY `FK3_tb_opr_kec_tb_kua` (`kua_id`) USING BTREE');
CALL siprima_tmp_add_index('tb_operator_kecamatan', 'operator_id', 'KEY `operator_id` (`operator_id`) USING BTREE');
CALL siprima_tmp_add_index('tb_operator_kecamatan', 'FK4_id_puskes_tb_puskes', 'KEY `FK4_id_puskes_tb_puskes` (`puskes_id`) USING BTREE');
CALL siprima_tmp_add_index('tb_operator_kecamatan', 'FK5_tb_rumahsakit_rs_id', 'KEY `FK5_tb_rumahsakit_rs_id` (`rs_id`) USING BTREE');

-- tb_password_reset
CALL siprima_tmp_add_column('tb_password_reset', 'requested_by', '`requested_by` int NOT NULL', 'user_id');
CALL siprima_tmp_add_column('tb_password_reset', 'request_id', '`request_id` char(32) NOT NULL', 'requested_by');
CALL siprima_tmp_add_column('tb_password_reset', 'token_hash', '`token_hash` char(64) NOT NULL', 'request_id');
CALL siprima_tmp_add_column('tb_password_reset', 'password_snapshot', '`password_snapshot` varchar(255) NOT NULL', 'token_hash');
CALL siprima_tmp_add_column('tb_password_reset', 'expires_at', '`expires_at` datetime NOT NULL', 'password_snapshot');
CALL siprima_tmp_add_column('tb_password_reset', 'email_status', '`email_status` enum(''pending'',''sent'',''failed'') NOT NULL DEFAULT ''pending''', 'expires_at');
CALL siprima_tmp_add_column('tb_password_reset', 'telegram_status', '`telegram_status` enum(''pending'',''sent'',''failed'') NOT NULL DEFAULT ''pending''', 'email_status');
CALL siprima_tmp_add_column('tb_password_reset', 'consumed_at', '`consumed_at` datetime DEFAULT NULL', 'telegram_status');
CALL siprima_tmp_add_column('tb_password_reset', 'created_at', '`created_at` datetime NOT NULL', 'consumed_at');
CALL siprima_tmp_add_index('tb_password_reset', 'request_id', 'UNIQUE KEY `request_id` (`request_id`)');
CALL siprima_tmp_add_index('tb_password_reset', 'token_hash', 'UNIQUE KEY `token_hash` (`token_hash`)');
CALL siprima_tmp_add_index('tb_password_reset', 'expires_at', 'KEY `expires_at` (`expires_at`)');

-- tb_pendaftaran_petugas
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'nama', '`nama` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'nik', '`nik` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL', 'nama');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'nik');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'no_hp', '`no_hp` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL', 'email');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'alamat', '`alamat` text COLLATE utf8mb4_unicode_ci NOT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'kecamatan_id', '`kecamatan_id` int NOT NULL', 'alamat');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'kecamatan_id');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'desa_id', '`desa_id` int NOT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'desa_id');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'jabatan', '`jabatan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'surat_tugas', '`surat_tugas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'jabatan');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'kode_verifikasi', '`kode_verifikasi` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'surat_tugas');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'status', '`status` enum(''pending'',''diverifikasi'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT ''pending''', 'kode_verifikasi');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'alasan');
CALL siprima_tmp_add_column('tb_pendaftaran_petugas', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL siprima_tmp_add_index('tb_pendaftaran_petugas', 'idx_nik', 'KEY `idx_nik` (`nik`) USING BTREE');
CALL siprima_tmp_add_index('tb_pendaftaran_petugas', 'idx_email', 'KEY `idx_email` (`email`) USING BTREE');
CALL siprima_tmp_add_index('tb_pendaftaran_petugas', 'idx_status', 'KEY `idx_status` (`status`) USING BTREE');

-- tb_permohonan_dokumen_hasil
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'source_table', '`source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'permohonan_id', '`permohonan_id` int NOT NULL', 'source_table');
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'id_user_pemohon', '`id_user_pemohon` int NOT NULL', 'permohonan_id');
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'uploaded_by', '`uploaded_by` int DEFAULT NULL', 'id_user_pemohon');
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'original_name', '`original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'uploaded_by');
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'stored_name', '`stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'original_name');
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'file_path', '`file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL', 'stored_name');
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'file_size', '`file_size` int unsigned NOT NULL DEFAULT ''0''', 'file_path');
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'mime_type', '`mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''application/pdf''', 'file_size');
CALL siprima_tmp_add_column('tb_permohonan_dokumen_hasil', 'uploaded_at', '`uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'mime_type');
CALL siprima_tmp_add_index('tb_permohonan_dokumen_hasil', 'idx_permohonan_hasil', 'KEY `idx_permohonan_hasil` (`source_table`,`permohonan_id`)');
CALL siprima_tmp_add_index('tb_permohonan_dokumen_hasil', 'idx_pemohon_hasil', 'KEY `idx_pemohon_hasil` (`id_user_pemohon`)');
CALL siprima_tmp_add_index('tb_permohonan_dokumen_hasil', 'idx_uploaded_by_hasil', 'KEY `idx_uploaded_by_hasil` (`uploaded_by`)');
CALL siprima_tmp_add_index('tb_permohonan_dokumen_hasil', 'idx_uploaded_at_hasil', 'KEY `idx_uploaded_at_hasil` (`uploaded_at`)');
CALL siprima_tmp_add_check('tb_permohonan_dokumen_hasil', 'chk_dokumen_hasil_no_script_extension', 'CONSTRAINT `chk_dokumen_hasil_no_script_extension` CHECK (((not((lower(`original_name`) like _utf8mb4''%.php%''))) and (not((lower(`original_name`) like _utf8mb4''%.phtml%''))) and (not((lower(`original_name`) like _utf8mb4''%.phar%''))) and (not((lower(`original_name`) like _utf8mb4''%.phps%''))) and (not((lower(`stored_name`) like _utf8mb4''%.php%''))) and (not((lower(`stored_name`) like _utf8mb4''%.phtml%''))) and (not((lower(`stored_name`) like _utf8mb4''%.phar%''))) and (not((lower(`stored_name`) like _utf8mb4''%.phps%''))) and (not((lower(`file_path`) like _utf8mb4''%.php%''))) and (not((lower(`file_path`) like _utf8mb4''%.phtml%''))) and (not((lower(`file_path`) like _utf8mb4''%.phar%''))) and (not((lower(`file_path`) like _utf8mb4''%.phps%'')))))');
CALL siprima_tmp_add_check('tb_permohonan_dokumen_hasil', 'chk_dokumen_hasil_pdf_extension', 'CONSTRAINT `chk_dokumen_hasil_pdf_extension` CHECK (((lower(`stored_name`) like _utf8mb4''%.pdf'') and (lower(`file_path`) like _utf8mb4''%.pdf'')))');
CALL siprima_tmp_add_check('tb_permohonan_dokumen_hasil', 'chk_dokumen_hasil_pdf_mime', 'CONSTRAINT `chk_dokumen_hasil_pdf_mime` CHECK ((`mime_type` = _utf8mb4''application/pdf''))');

-- tb_permohonan_feedback
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'source_table', '`source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'permohonan_id', '`permohonan_id` int NOT NULL', 'source_table');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'respondent_user_id', '`respondent_user_id` int NOT NULL', 'permohonan_id');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'respondent_role', '`respondent_role` enum(''pemohon'',''petugas_registrasi'') COLLATE utf8mb4_unicode_ci NOT NULL', 'respondent_user_id');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'jenis_layanan', '`jenis_layanan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'respondent_role');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'nama_pemohon', '`nama_pemohon` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'jenis_layanan');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'nama_subjek', '`nama_subjek` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'trigger_type', '`trigger_type` enum(''konfirmasi'',''download'') COLLATE utf8mb4_unicode_ci NOT NULL', 'nama_subjek');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'status', '`status` enum(''pending'',''submitted'',''skipped'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''pending''', 'trigger_type');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'jenis_feedback', '`jenis_feedback` enum(''tanggapan'',''aduan'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'rating', '`rating` tinyint unsigned DEFAULT NULL', 'jenis_feedback');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'service_rating', '`service_rating` tinyint unsigned DEFAULT NULL', 'rating');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'pesan', '`pesan` text COLLATE utf8mb4_unicode_ci', 'service_rating');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'triggered_at', '`triggered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'pesan');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'submitted_at', '`submitted_at` datetime DEFAULT NULL', 'triggered_at');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'skipped_at', '`skipped_at` datetime DEFAULT NULL', 'submitted_at');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'created_at', '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'skipped_at');
CALL siprima_tmp_add_column('tb_permohonan_feedback', 'updated_at', '`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL siprima_tmp_add_index('tb_permohonan_feedback', 'uq_feedback_permohonan_role', 'UNIQUE KEY `uq_feedback_permohonan_role` (`source_table`,`permohonan_id`,`respondent_role`)');
CALL siprima_tmp_add_index('tb_permohonan_feedback', 'idx_feedback_admin', 'KEY `idx_feedback_admin` (`status`,`jenis_feedback`,`submitted_at`)');
CALL siprima_tmp_add_index('tb_permohonan_feedback', 'idx_feedback_user', 'KEY `idx_feedback_user` (`respondent_user_id`,`updated_at`)');
CALL siprima_tmp_add_check('tb_permohonan_feedback', 'chk_feedback_rating', 'CONSTRAINT `chk_feedback_rating` CHECK (((`rating` is null) or (`rating` between 1 and 5)))');
CALL siprima_tmp_add_check('tb_permohonan_feedback', 'chk_feedback_service_rating', 'CONSTRAINT `chk_feedback_service_rating` CHECK (((`service_rating` is null) or (`service_rating` between 1 and 5)))');

-- tb_permohonan_komentar
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'source_table', '`source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'permohonan_id', '`permohonan_id` int NOT NULL', 'source_table');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'id_user', '`id_user` int NOT NULL', 'permohonan_id');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'user_role', '`user_role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'parent_id', '`parent_id` bigint unsigned DEFAULT NULL', 'user_role');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'komentar', '`komentar` text COLLATE utf8mb4_unicode_ci NOT NULL', 'parent_id');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'read_by_operator_at', '`read_by_operator_at` datetime DEFAULT NULL', 'komentar');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'read_by_pemohon_at', '`read_by_pemohon_at` datetime DEFAULT NULL', 'read_by_operator_at');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'created_at', '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'read_by_pemohon_at');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'updated_at', '`updated_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_permohonan_komentar', 'deleted_at', '`deleted_at` datetime DEFAULT NULL', 'updated_at');
CALL siprima_tmp_add_index('tb_permohonan_komentar', 'idx_permohonan_komentar', 'KEY `idx_permohonan_komentar` (`source_table`,`permohonan_id`,`created_at`)');
CALL siprima_tmp_add_index('tb_permohonan_komentar', 'idx_user_komentar', 'KEY `idx_user_komentar` (`id_user`)');
CALL siprima_tmp_add_index('tb_permohonan_komentar', 'idx_parent_komentar', 'KEY `idx_parent_komentar` (`parent_id`)');
CALL siprima_tmp_add_index('tb_permohonan_komentar', 'idx_operator_unread_komentar', 'KEY `idx_operator_unread_komentar` (`source_table`,`permohonan_id`,`read_by_operator_at`)');
CALL siprima_tmp_add_index('tb_permohonan_komentar', 'idx_pemohon_unread_komentar', 'KEY `idx_pemohon_unread_komentar` (`source_table`,`permohonan_id`,`read_by_pemohon_at`)');
CALL siprima_tmp_add_check('tb_permohonan_komentar', 'chk_permohonan_komentar_role', 'CONSTRAINT `chk_permohonan_komentar_role` CHECK ((`user_role` in (_utf8mb4''admin'',_utf8mb4''operator'',_utf8mb4''user'',_utf8mb4''petugas_desa'',_utf8mb4''rs'',_utf8mb4''kua'',_utf8mb4''ahlak'')))');
CALL siprima_tmp_replace_check('tb_permohonan_komentar', 'chk_permohonan_komentar_source_table', 'tb_cetak_ktp', 'CONSTRAINT `chk_permohonan_komentar_source_table` CHECK ((`source_table` in (_utf8mb4''tb_kartukeluarga'',_utf8mb4''tb_akta_kelahiran'',_utf8mb4''tb_akta_kematian'',_utf8mb4''tb_pindah_alamat'',_utf8mb4''tb_pindah_online'',_utf8mb4''tb_laporan_peristiwa'',_utf8mb4''tb_akta_kelahiran_rs'',_utf8mb4''tb_akta_kematian_rs'',_utf8mb4''tb_kartukeluarga_kua'',_utf8mb4''tb_akta_kelahiran_ahlak'',_utf8mb4''tb_cetak_ktp'')))');
CALL siprima_tmp_add_check('tb_permohonan_komentar', 'chk_permohonan_komentar_source_table', 'CONSTRAINT `chk_permohonan_komentar_source_table` CHECK ((`source_table` in (_utf8mb4''tb_kartukeluarga'',_utf8mb4''tb_akta_kelahiran'',_utf8mb4''tb_akta_kematian'',_utf8mb4''tb_pindah_alamat'',_utf8mb4''tb_pindah_online'',_utf8mb4''tb_laporan_peristiwa'',_utf8mb4''tb_akta_kelahiran_rs'',_utf8mb4''tb_akta_kematian_rs'',_utf8mb4''tb_kartukeluarga_kua'',_utf8mb4''tb_akta_kelahiran_ahlak'',_utf8mb4''tb_cetak_ktp'')))');
CALL siprima_tmp_add_check('tb_permohonan_komentar', 'chk_permohonan_komentar_text', 'CONSTRAINT `chk_permohonan_komentar_text` CHECK ((char_length(trim(`komentar`)) between 1 and 1000))');

-- tb_permohonan_status_history
CALL siprima_tmp_add_column('tb_permohonan_status_history', 'source_table', '`source_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_permohonan_status_history', 'permohonan_id', '`permohonan_id` bigint unsigned NOT NULL', 'source_table');
CALL siprima_tmp_add_column('tb_permohonan_status_history', 'status', '`status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL', 'permohonan_id');
CALL siprima_tmp_add_column('tb_permohonan_status_history', 'alasan', '`alasan` text COLLATE utf8mb4_unicode_ci', 'status');
CALL siprima_tmp_add_column('tb_permohonan_status_history', 'actor_id', '`actor_id` int DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_permohonan_status_history', 'actor_role', '`actor_role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'actor_id');
CALL siprima_tmp_add_column('tb_permohonan_status_history', 'event_type', '`event_type` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'actor_role');
CALL siprima_tmp_add_column('tb_permohonan_status_history', 'meta_json', '`meta_json` longtext COLLATE utf8mb4_unicode_ci', 'event_type');
CALL siprima_tmp_add_column('tb_permohonan_status_history', 'created_at', '`created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP', 'meta_json');
CALL siprima_tmp_add_index('tb_permohonan_status_history', 'idx_source_permohonan_created', 'KEY `idx_source_permohonan_created` (`source_table`,`permohonan_id`,`created_at`)');
CALL siprima_tmp_add_index('tb_permohonan_status_history', 'idx_status_created', 'KEY `idx_status_created` (`status`,`created_at`)');

-- tb_pindah_alamat
CALL siprima_tmp_add_column('tb_pindah_alamat', 'id_user', '`id_user` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'id_kecamatan', '`id_kecamatan` int NOT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'id_desa', '`id_desa` int NOT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'no_kk', '`no_kk` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'nama_kepalaKeluarga', '`nama_kepalaKeluarga` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_kk');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'alamat_asal', '`alamat_asal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kepalaKeluarga');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'alamat_tujuan', '`alamat_tujuan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alamat_asal');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'alasan_pindah', '`alasan_pindah` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alamat_tujuan');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'anggotaKeluarga', '`anggotaKeluarga` text COLLATE utf8mb4_unicode_ci', 'alasan_pindah');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'anggotaKeluarga');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'keterangan', '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'keterangan');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_pindah_alamat', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_widen_varchar('tb_pindah_alamat', 'keterangan', 500, '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
CALL siprima_tmp_add_index('tb_pindah_alamat', 'FK3_KK_user', 'KEY `FK3_KK_user` (`id_user`) USING BTREE');
CALL siprima_tmp_add_index('tb_pindah_alamat', 'FK1_KK_kecamatan', 'KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE');
CALL siprima_tmp_add_index('tb_pindah_alamat', 'FK2_KK_desa', 'KEY `FK2_KK_desa` (`id_desa`) USING BTREE');

-- tb_pindah_online
CALL siprima_tmp_add_column('tb_pindah_online', 'id_user', '`id_user` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_pindah_online', 'id_kecamatan', '`id_kecamatan` int NOT NULL', 'id_user');
CALL siprima_tmp_add_column('tb_pindah_online', 'id_desa', '`id_desa` int NOT NULL', 'id_kecamatan');
CALL siprima_tmp_add_column('tb_pindah_online', 'nama_pemohon', '`nama_pemohon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id_desa');
CALL siprima_tmp_add_column('tb_pindah_online', 'nik_pemohon', '`nik_pemohon` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_pemohon');
CALL siprima_tmp_add_column('tb_pindah_online', 'no_hp', '`no_hp` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nik_pemohon');
CALL siprima_tmp_add_column('tb_pindah_online', 'email', '`email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_hp');
CALL siprima_tmp_add_column('tb_pindah_online', 'alamat_asal', '`alamat_asal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL siprima_tmp_add_column('tb_pindah_online', 'nama_kecamatan', '`nama_kecamatan` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alamat_asal');
CALL siprima_tmp_add_column('tb_pindah_online', 'nama_desa', '`nama_desa` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kecamatan');
CALL siprima_tmp_add_column('tb_pindah_online', 'alamat_tujuan', '`alamat_tujuan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_desa');
CALL siprima_tmp_add_column('tb_pindah_online', 'no_kk', '`no_kk` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alamat_tujuan');
CALL siprima_tmp_add_column('tb_pindah_online', 'nama_kepalaKeluarga', '`nama_kepalaKeluarga` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'no_kk');
CALL siprima_tmp_add_column('tb_pindah_online', 'alasan_pindah', '`alasan_pindah` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'nama_kepalaKeluarga');
CALL siprima_tmp_add_column('tb_pindah_online', 'anggotaKeluarga', '`anggotaKeluarga` text COLLATE utf8mb4_unicode_ci', 'alasan_pindah');
CALL siprima_tmp_add_column('tb_pindah_online', 'dokumen', '`dokumen` text COLLATE utf8mb4_unicode_ci', 'anggotaKeluarga');
CALL siprima_tmp_add_column('tb_pindah_online', 'status', '`status` enum(''diajukan'',''diproses'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'dokumen');
CALL siprima_tmp_add_column('tb_pindah_online', 'alasan', '`alasan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_pindah_online', 'keterangan', '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'alasan');
CALL siprima_tmp_add_column('tb_pindah_online', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'keterangan');
CALL siprima_tmp_add_column('tb_pindah_online', 'resubmitted_at', '`resubmitted_at` datetime DEFAULT NULL', 'created_at');
CALL siprima_tmp_add_column('tb_pindah_online', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'resubmitted_at');
CALL siprima_tmp_add_column('tb_pindah_online', 'nama_folder', '`nama_folder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'updated_at');
CALL siprima_tmp_widen_varchar('tb_pindah_online', 'keterangan', 500, '`keterangan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
CALL siprima_tmp_add_index('tb_pindah_online', 'FK3_KK_user', 'KEY `FK3_KK_user` (`id_user`) USING BTREE');
CALL siprima_tmp_add_index('tb_pindah_online', 'FK1_KK_kecamatan', 'KEY `FK1_KK_kecamatan` (`id_kecamatan`) USING BTREE');
CALL siprima_tmp_add_index('tb_pindah_online', 'FK2_KK_desa', 'KEY `FK2_KK_desa` (`id_desa`) USING BTREE');

-- tb_service_settings
CALL siprima_tmp_add_column('tb_service_settings', 'payload', '`payload` longtext NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_service_settings', 'revision', '`revision` int unsigned NOT NULL DEFAULT ''1''', 'payload');

-- tb_service_settings_audit
CALL siprima_tmp_add_column('tb_service_settings_audit', 'admin_id', '`admin_id` int NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_service_settings_audit', 'changed_at', '`changed_at` datetime NOT NULL', 'admin_id');
CALL siprima_tmp_add_column('tb_service_settings_audit', 'action', '`action` varchar(100) NOT NULL', 'changed_at');
CALL siprima_tmp_add_column('tb_service_settings_audit', 'before_payload', '`before_payload` longtext NOT NULL', 'action');
CALL siprima_tmp_add_column('tb_service_settings_audit', 'after_payload', '`after_payload` longtext NOT NULL', 'before_payload');

-- tb_whistleblowing_aduan
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'kode_aduan', '`kode_aduan` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'pemohon_id', '`pemohon_id` int NOT NULL', 'kode_aduan');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'kategori', '`kategori` enum(''pungli_gratifikasi'',''penyalahgunaan_wewenang'',''diskriminasi'',''pelanggaran_prosedur'',''pelanggaran_data'',''lainnya'') COLLATE utf8mb4_unicode_ci NOT NULL', 'pemohon_id');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'judul', '`judul` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL', 'kategori');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'uraian', '`uraian` text COLLATE utf8mb4_unicode_ci NOT NULL', 'judul');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'tanggal_kejadian', '`tanggal_kejadian` date DEFAULT NULL', 'uraian');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'lokasi_kejadian', '`lokasi_kejadian` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'tanggal_kejadian');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'pihak_terlapor', '`pihak_terlapor` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'lokasi_kejadian');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'layanan_terkait', '`layanan_terkait` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'pihak_terlapor');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'status', '`status` enum(''baru'',''diverifikasi'',''diproses'',''menunggu_pemohon'',''selesai'',''ditolak'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''baru''', 'layanan_terkait');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'admin_penanggung_jawab_id', '`admin_penanggung_jawab_id` int DEFAULT NULL', 'status');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'selesai_at', '`selesai_at` datetime DEFAULT NULL', 'admin_penanggung_jawab_id');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'is_published', '`is_published` tinyint(1) NOT NULL DEFAULT ''0''', 'selesai_at');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'ringkasan_publik', '`ringkasan_publik` text COLLATE utf8mb4_unicode_ci', 'is_published');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'hasil_tindak_lanjut_publik', '`hasil_tindak_lanjut_publik` text COLLATE utf8mb4_unicode_ci', 'ringkasan_publik');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'published_by', '`published_by` int DEFAULT NULL', 'hasil_tindak_lanjut_publik');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'published_at', '`published_at` datetime DEFAULT NULL', 'published_by');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'created_at', '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'published_at');
CALL siprima_tmp_add_column('tb_whistleblowing_aduan', 'updated_at', '`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL siprima_tmp_add_index('tb_whistleblowing_aduan', 'uq_whistleblowing_kode', 'UNIQUE KEY `uq_whistleblowing_kode` (`kode_aduan`)');
CALL siprima_tmp_add_index('tb_whistleblowing_aduan', 'idx_whistleblowing_pemohon', 'KEY `idx_whistleblowing_pemohon` (`pemohon_id`,`updated_at`)');
CALL siprima_tmp_add_index('tb_whistleblowing_aduan', 'idx_whistleblowing_admin', 'KEY `idx_whistleblowing_admin` (`status`,`updated_at`)');
CALL siprima_tmp_add_index('tb_whistleblowing_aduan', 'idx_whistleblowing_publik', 'KEY `idx_whistleblowing_publik` (`is_published`,`published_at`)');

-- tb_whistleblowing_tindak_lanjut
CALL siprima_tmp_add_column('tb_whistleblowing_tindak_lanjut', 'aduan_id', '`aduan_id` bigint unsigned NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_whistleblowing_tindak_lanjut', 'actor_user_id', '`actor_user_id` int NOT NULL', 'aduan_id');
CALL siprima_tmp_add_column('tb_whistleblowing_tindak_lanjut', 'actor_role', '`actor_role` enum(''pemohon'',''admin'') COLLATE utf8mb4_unicode_ci NOT NULL', 'actor_user_id');
CALL siprima_tmp_add_column('tb_whistleblowing_tindak_lanjut', 'status_from', '`status_from` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'actor_role');
CALL siprima_tmp_add_column('tb_whistleblowing_tindak_lanjut', 'status_to', '`status_to` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'status_from');
CALL siprima_tmp_add_column('tb_whistleblowing_tindak_lanjut', 'pesan', '`pesan` text COLLATE utf8mb4_unicode_ci NOT NULL', 'status_to');
CALL siprima_tmp_add_column('tb_whistleblowing_tindak_lanjut', 'visibility', '`visibility` enum(''pemohon'',''internal'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''pemohon''', 'pesan');
CALL siprima_tmp_add_column('tb_whistleblowing_tindak_lanjut', 'created_at', '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'visibility');
CALL siprima_tmp_add_index('tb_whistleblowing_tindak_lanjut', 'idx_whistleblowing_timeline', 'KEY `idx_whistleblowing_timeline` (`aduan_id`,`created_at`,`id`)');

-- tb_whistleblowing_lampiran
CALL siprima_tmp_add_column('tb_whistleblowing_lampiran', 'aduan_id', '`aduan_id` bigint unsigned NOT NULL', 'id');
CALL siprima_tmp_add_column('tb_whistleblowing_lampiran', 'tindak_lanjut_id', '`tindak_lanjut_id` bigint unsigned DEFAULT NULL', 'aduan_id');
CALL siprima_tmp_add_column('tb_whistleblowing_lampiran', 'uploaded_by', '`uploaded_by` int NOT NULL', 'tindak_lanjut_id');
CALL siprima_tmp_add_column('tb_whistleblowing_lampiran', 'original_name', '`original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'uploaded_by');
CALL siprima_tmp_add_column('tb_whistleblowing_lampiran', 'stored_name', '`stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'original_name');
CALL siprima_tmp_add_column('tb_whistleblowing_lampiran', 'file_path', '`file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL', 'stored_name');
CALL siprima_tmp_add_column('tb_whistleblowing_lampiran', 'file_size', '`file_size` int unsigned NOT NULL', 'file_path');
CALL siprima_tmp_add_column('tb_whistleblowing_lampiran', 'mime_type', '`mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'file_size');
CALL siprima_tmp_add_column('tb_whistleblowing_lampiran', 'uploaded_at', '`uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'mime_type');
CALL siprima_tmp_add_index('tb_whistleblowing_lampiran', 'idx_whistleblowing_lampiran_aduan', 'KEY `idx_whistleblowing_lampiran_aduan` (`aduan_id`,`uploaded_at`)');
CALL siprima_tmp_add_index('tb_whistleblowing_lampiran', 'idx_whistleblowing_lampiran_tindak_lanjut', 'KEY `idx_whistleblowing_lampiran_tindak_lanjut` (`tindak_lanjut_id`)');

-- ---------------------------------------------------------------------------
-- 3. Foreign key yang belum ada (FK dengan baris yatim dilewati dan dilaporkan).
-- ---------------------------------------------------------------------------
CALL siprima_tmp_add_fk('tb_puskes', 'tb_puskes_ibfk_1', 'id_kecamatan', 'tb_kecamatan', 'id', 'CONSTRAINT `tb_puskes_ibfk_1` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`)');
CALL siprima_tmp_add_fk('tb_desa', 'FK2_id_kua', 'id_kua', 'tb_kua', 'id_kua', 'CONSTRAINT `FK2_id_kua` FOREIGN KEY (`id_kua`) REFERENCES `tb_kua` (`id_kua`)');
CALL siprima_tmp_add_fk('tb_desa', 'FK_tb_desa_tb_kecamatan', 'kecamatan_id', 'tb_kecamatan', 'id', 'CONSTRAINT `FK_tb_desa_tb_kecamatan` FOREIGN KEY (`kecamatan_id`) REFERENCES `tb_kecamatan` (`id`)');
CALL siprima_tmp_add_fk('tb_akta_kelahiran', 'FK4_tb_akta_lahir_tb_puskes', 'id_puskes', 'tb_puskes', 'id_puskes', 'CONSTRAINT `FK4_tb_akta_lahir_tb_puskes` FOREIGN KEY (`id_puskes`) REFERENCES `tb_puskes` (`id_puskes`)');
CALL siprima_tmp_add_fk('tb_akta_kelahiran', 'FK_tb_akta_kelahiran_tb_kecamatan', 'id_kecamatan', 'tb_kecamatan', 'id', 'CONSTRAINT `FK_tb_akta_kelahiran_tb_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`)');
CALL siprima_tmp_add_fk('tb_akta_kelahiran', 'FK_tb_akta_kelahiran_tb_users', 'id_user', 'tb_users', 'id', 'CONSTRAINT `FK_tb_akta_kelahiran_tb_users` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`)');
CALL siprima_tmp_add_fk('tb_akta_kelahiran', 'tb_akta_kelahiran_ibfk_2', 'id_desa', 'tb_desa', 'id', 'CONSTRAINT `tb_akta_kelahiran_ibfk_2` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`)');
CALL siprima_tmp_add_fk('tb_akta_kelahiran', 'tb_akta_lahir_id_rs_tb_rumahsakit', 'id_rs', 'tb_rumahsakit', 'id_rs', 'CONSTRAINT `tb_akta_lahir_id_rs_tb_rumahsakit` FOREIGN KEY (`id_rs`) REFERENCES `tb_rumahsakit` (`id_rs`)');
CALL siprima_tmp_add_fk('tb_cetak_ktp', 'fk_cetak_ktp_desa', 'id_desa', 'tb_desa', 'id', 'CONSTRAINT `fk_cetak_ktp_desa` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`)');
CALL siprima_tmp_add_fk('tb_cetak_ktp', 'fk_cetak_ktp_kecamatan', 'id_kecamatan', 'tb_kecamatan', 'id', 'CONSTRAINT `fk_cetak_ktp_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`)');
CALL siprima_tmp_add_fk('tb_cetak_ktp', 'fk_cetak_ktp_user', 'id_user', 'tb_users', 'id', 'CONSTRAINT `fk_cetak_ktp_user` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`)');
CALL siprima_tmp_add_fk('tb_konfirmasi_dokumen_email_file', 'fk_konfirmasi_email_file_parent', 'konfirmasi_id', 'tb_konfirmasi_dokumen_email', 'id', 'CONSTRAINT `fk_konfirmasi_email_file_parent` FOREIGN KEY (`konfirmasi_id`) REFERENCES `tb_konfirmasi_dokumen_email` (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL siprima_tmp_add_fk('tb_konsultasi_data', 'fk_konsultasi_desa', 'id_desa', 'tb_desa', 'id', 'CONSTRAINT `fk_konsultasi_desa` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`)');
CALL siprima_tmp_add_fk('tb_konsultasi_data', 'fk_konsultasi_kecamatan', 'id_kecamatan', 'tb_kecamatan', 'id', 'CONSTRAINT `fk_konsultasi_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`)');
CALL siprima_tmp_add_fk('tb_konsultasi_data', 'fk_konsultasi_user', 'id_user', 'tb_users', 'id', 'CONSTRAINT `fk_konsultasi_user` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`)');
CALL siprima_tmp_add_fk('tb_konsultasi_data_dicek', 'fk_konsultasi_dicek_konsultasi', 'konsultasi_id', 'tb_konsultasi_data', 'id', 'CONSTRAINT `fk_konsultasi_dicek_konsultasi` FOREIGN KEY (`konsultasi_id`) REFERENCES `tb_konsultasi_data` (`id`) ON DELETE CASCADE');
CALL siprima_tmp_add_fk('tb_konsultasi_jawaban', 'fk_konsultasi_jawaban_konsultasi', 'konsultasi_id', 'tb_konsultasi_data', 'id', 'CONSTRAINT `fk_konsultasi_jawaban_konsultasi` FOREIGN KEY (`konsultasi_id`) REFERENCES `tb_konsultasi_data` (`id`) ON DELETE CASCADE');
CALL siprima_tmp_add_fk('tb_konsultasi_jawaban', 'fk_konsultasi_jawaban_operator', 'operator_id', 'tb_users', 'id', 'CONSTRAINT `fk_konsultasi_jawaban_operator` FOREIGN KEY (`operator_id`) REFERENCES `tb_users` (`id`)');
CALL siprima_tmp_add_fk('tb_konsultasi_lampiran', 'fk_konsultasi_lampiran_jawaban', 'jawaban_id', 'tb_konsultasi_jawaban', 'id', 'CONSTRAINT `fk_konsultasi_lampiran_jawaban` FOREIGN KEY (`jawaban_id`) REFERENCES `tb_konsultasi_jawaban` (`id`) ON DELETE CASCADE');
CALL siprima_tmp_add_fk('tb_konsultasi_lampiran', 'fk_konsultasi_lampiran_konsultasi', 'konsultasi_id', 'tb_konsultasi_data', 'id', 'CONSTRAINT `fk_konsultasi_lampiran_konsultasi` FOREIGN KEY (`konsultasi_id`) REFERENCES `tb_konsultasi_data` (`id`) ON DELETE CASCADE');
CALL siprima_tmp_add_fk('tb_notifikasi', 'FK_tb_notifikasi_tb_users', 'user_id', 'tb_users', 'id', 'CONSTRAINT `FK_tb_notifikasi_tb_users` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE');
CALL siprima_tmp_add_fk('tb_operator_kecamatan', 'FK3_tb_opr_kec_tb_kua', 'kua_id', 'tb_kua', 'id_kua', 'CONSTRAINT `FK3_tb_opr_kec_tb_kua` FOREIGN KEY (`kua_id`) REFERENCES `tb_kua` (`id_kua`)');
CALL siprima_tmp_add_fk('tb_operator_kecamatan', 'FK4_id_puskes_tb_puskes', 'puskes_id', 'tb_puskes', 'id_puskes', 'CONSTRAINT `FK4_id_puskes_tb_puskes` FOREIGN KEY (`puskes_id`) REFERENCES `tb_puskes` (`id_puskes`)');
CALL siprima_tmp_add_fk('tb_operator_kecamatan', 'FK5_tb_rumahsakit_rs_id', 'rs_id', 'tb_rumahsakit', 'id_rs', 'CONSTRAINT `FK5_tb_rumahsakit_rs_id` FOREIGN KEY (`rs_id`) REFERENCES `tb_rumahsakit` (`id_rs`)');
CALL siprima_tmp_add_fk('tb_operator_kecamatan', 'FK_tb_operator_kecamatan_tb_kecamatan', 'kecamatan_id', 'tb_kecamatan', 'id', 'CONSTRAINT `FK_tb_operator_kecamatan_tb_kecamatan` FOREIGN KEY (`kecamatan_id`) REFERENCES `tb_kecamatan` (`id`)');
CALL siprima_tmp_add_fk('tb_operator_kecamatan', 'FK_tb_operator_kecamatan_tb_users', 'operator_id', 'tb_users', 'id', 'CONSTRAINT `FK_tb_operator_kecamatan_tb_users` FOREIGN KEY (`operator_id`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE');
CALL siprima_tmp_add_fk('tb_permohonan_dokumen_hasil', 'fk_dokumen_hasil_pemohon', 'id_user_pemohon', 'tb_users', 'id', 'CONSTRAINT `fk_dokumen_hasil_pemohon` FOREIGN KEY (`id_user_pemohon`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL siprima_tmp_add_fk('tb_permohonan_dokumen_hasil', 'fk_dokumen_hasil_uploaded_by', 'uploaded_by', 'tb_users', 'id', 'CONSTRAINT `fk_dokumen_hasil_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `tb_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
CALL siprima_tmp_add_fk('tb_permohonan_komentar', 'fk_permohonan_komentar_parent', 'parent_id', 'tb_permohonan_komentar', 'id', 'CONSTRAINT `fk_permohonan_komentar_parent` FOREIGN KEY (`parent_id`) REFERENCES `tb_permohonan_komentar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL siprima_tmp_add_fk('tb_permohonan_komentar', 'fk_permohonan_komentar_user', 'id_user', 'tb_users', 'id', 'CONSTRAINT `fk_permohonan_komentar_user` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL siprima_tmp_add_fk('tb_pindah_alamat', 'FK_tb_pindah_alamat_tb_kecamatan', 'id_kecamatan', 'tb_kecamatan', 'id', 'CONSTRAINT `FK_tb_pindah_alamat_tb_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`)');
CALL siprima_tmp_add_fk('tb_pindah_alamat', 'FK_tb_pindah_alamat_tb_users', 'id_user', 'tb_users', 'id', 'CONSTRAINT `FK_tb_pindah_alamat_tb_users` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`)');
CALL siprima_tmp_add_fk('tb_pindah_alamat', 'tb_pindah_alamat_ibfk_2', 'id_desa', 'tb_desa', 'id', 'CONSTRAINT `tb_pindah_alamat_ibfk_2` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`)');
CALL siprima_tmp_add_fk('tb_pindah_online', 'FK_tb_pindah_online_tb_kecamatan', 'id_kecamatan', 'tb_kecamatan', 'id', 'CONSTRAINT `FK_tb_pindah_online_tb_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`)');
CALL siprima_tmp_add_fk('tb_pindah_online', 'FK_tb_pindah_online_tb_users', 'id_user', 'tb_users', 'id', 'CONSTRAINT `FK_tb_pindah_online_tb_users` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`)');
CALL siprima_tmp_add_fk('tb_pindah_online', 'tb_pindah_online_ibfk_2', 'id_desa', 'tb_desa', 'id', 'CONSTRAINT `tb_pindah_online_ibfk_2` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`)');
CALL siprima_tmp_add_fk('tb_whistleblowing_tindak_lanjut', 'fk_whistleblowing_tindak_lanjut_aduan', 'aduan_id', 'tb_whistleblowing_aduan', 'id', 'CONSTRAINT `fk_whistleblowing_tindak_lanjut_aduan` FOREIGN KEY (`aduan_id`) REFERENCES `tb_whistleblowing_aduan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL siprima_tmp_add_fk('tb_whistleblowing_lampiran', 'fk_whistleblowing_lampiran_aduan', 'aduan_id', 'tb_whistleblowing_aduan', 'id', 'CONSTRAINT `fk_whistleblowing_lampiran_aduan` FOREIGN KEY (`aduan_id`) REFERENCES `tb_whistleblowing_aduan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL siprima_tmp_add_fk('tb_whistleblowing_lampiran', 'fk_whistleblowing_lampiran_tindak_lanjut', 'tindak_lanjut_id', 'tb_whistleblowing_tindak_lanjut', 'id', 'CONSTRAINT `fk_whistleblowing_lampiran_tindak_lanjut` FOREIGN KEY (`tindak_lanjut_id`) REFERENCES `tb_whistleblowing_tindak_lanjut` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');

-- ---------------------------------------------------------------------------
-- 4. Data referensi wilayah & instansi (bukan data pribadi). INSERT IGNORE: baris dengan
--    id yang sudah ada TIDAK ditimpa.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `tb_kecamatan` (`id`, `nama_kecamatan`) VALUES
(1, 'LIMBOTO'),
(2, 'TELAGA'),
(3, 'BATUDAA'),
(4, 'TIBAWA'),
(5, 'BATUDAA PANTAI'),
(6, 'BOLIYOHUTO'),
(7, 'TELAGA BIRU'),
(8, 'BONGOMEME'),
(9, 'TOLANGOHULA'),
(10, 'MOOTILANGO'),
(11, 'PULUBALA'),
(12, 'LIMBOTO BARAT'),
(13, 'TILANGO'),
(14, 'TABONGO'),
(15, 'BILUHU'),
(16, 'ASPARAGA'),
(17, 'TALAGA JAYA'),
(18, 'BILATO'),
(19, 'DUNGALIYO');
INSERT IGNORE INTO `tb_kua` (`id_kua`, `id_kecamatan`, `nama_kua`) VALUES
(1, 1, 'KUA LIMBOTO'),
(2, 2, 'KUA TELAGA'),
(3, 3, 'KUA BATUDAA'),
(4, 4, 'KUA TIBAWA'),
(5, 5, 'KUA BATUDAA PANTAI'),
(6, 6, 'KUA BOLIYOHUTO'),
(7, 7, 'KUA TELAGA BIRU'),
(8, 8, 'KUA BONGOMEME'),
(9, 9, 'KUA TOLANGOHULA'),
(10, 10, 'KUA MOOTILANGO'),
(11, 11, 'KUA PULUBALA'),
(12, 12, 'KUA LIMBOTO BARAT'),
(13, 13, 'KUA TILANGO'),
(14, 14, 'KUA TABONGO'),
(15, 15, 'KUA BILUHU'),
(16, 16, 'KUA ASPARAGA'),
(17, 17, 'KUA TALAGA JAYA'),
(18, 18, 'KUA BILATO'),
(19, 19, 'KUA DUNGALIYO');
INSERT IGNORE INTO `tb_desa` (`id`, `kecamatan_id`, `id_kua`, `nama_desa`) VALUES
(7, 1, 1, 'KAYUBULAN'),
(8, 1, 1, 'KAYUMERAH'),
(9, 1, 1, 'HUNGGALUWA'),
(10, 1, 1, 'BOLIHUANGGA'),
(11, 1, 1, 'HEPUHULAWA'),
(12, 1, 1, 'HUTUO'),
(13, 1, 1, 'DUTULANAA'),
(14, 1, 1, 'TENILO'),
(15, 1, 1, 'BIYONGA'),
(16, 1, 1, 'BONGOHULAWA'),
(17, 1, 1, 'BULOTA'),
(18, 1, 1, 'MALAHU'),
(19, 1, 1, 'TILIHUWA'),
(20, 1, 1, 'POLOHUNGO'),
(21, 2, 2, 'BULILA'),
(22, 2, 2, 'MONGOLATO'),
(23, 2, 2, 'LUHU'),
(24, 2, 2, 'HULAWA'),
(25, 2, 2, 'PILOHAYANGA'),
(26, 2, 2, 'DULAMAYO SELATAN'),
(27, 2, 2, 'DULAMAYO BARAT'),
(28, 2, 2, 'DULOHUPA'),
(29, 2, 2, 'PILOHAYANGA BARAT'),
(30, 3, 3, 'ILUTA'),
(31, 3, 3, 'BUA'),
(32, 3, 3, 'HUNTU'),
(33, 3, 3, 'PAYUNGA'),
(34, 3, 3, 'BARAKATI'),
(35, 3, 3, 'ILOHUNGAYO'),
(36, 3, 3, 'DUNGGALA'),
(37, 3, 3, 'PILOBUHUTA'),
(38, 4, 4, 'ISIMU UTARA'),
(39, 4, 4, 'ISIMU SELATAN'),
(40, 4, 4, 'DATAHU'),
(41, 4, 4, 'TOLOTIO'),
(42, 4, 4, 'LABANU'),
(43, 4, 4, 'REKSONEGORO'),
(44, 4, 4, 'BUHU'),
(45, 4, 4, 'DUNGGALA'),
(46, 4, 4, 'MOLOWAHU'),
(47, 4, 4, 'ILOPONU'),
(48, 4, 4, 'ILOMATA'),
(49, 4, 4, 'MOTILANGO'),
(50, 4, 4, 'ISIMU RAYA'),
(51, 4, 4, 'BALAHU'),
(52, 4, 4, 'BOTUMOPUTI'),
(53, 4, 4, 'ULOBUA'),
(54, 5, 5, 'TONTAYUO'),
(55, 5, 5, 'BILUHU TIMUR'),
(56, 5, 5, 'KAYUBULAN'),
(57, 5, 5, 'BONGO'),
(58, 5, 5, 'LOPO'),
(59, 5, 5, 'LAMU'),
(60, 5, 5, 'OLIMOO''O'),
(61, 5, 5, 'BUHUDAA'),
(62, 5, 5, 'LANGGULA'),
(63, 6, 6, 'SIDOMULYO'),
(64, 6, 6, 'PARUNGI'),
(65, 6, 6, 'DILONIYOHU'),
(66, 6, 6, 'SIDODADI'),
(67, 6, 6, 'POTANGA'),
(68, 6, 6, 'MOTODUTO'),
(69, 6, 6, 'ILOHELUMA'),
(70, 6, 6, 'MONGGOLITO'),
(71, 6, 6, 'BANDUNG REJO'),
(72, 6, 6, 'DULOHUPA'),
(73, 6, 6, 'SIDOMULYO SELATAN'),
(74, 6, 6, 'BONGONGOAYU'),
(75, 6, 6, 'TOLITE'),
(76, 7, 7, 'DULAMAYO UTARA'),
(77, 7, 7, 'ULAPATO A'),
(78, 7, 7, 'ULAPATO B'),
(79, 7, 7, 'TALUMELITO'),
(80, 7, 7, 'TULADENGGI'),
(81, 7, 7, 'PANTUNGO'),
(82, 7, 7, 'LUPOYO'),
(83, 7, 7, 'PENTADIO TIMUR'),
(84, 7, 7, 'PENTADIO BARAT'),
(85, 7, 7, 'DUMATI'),
(86, 7, 7, 'MODELIDU'),
(87, 7, 7, 'TINELO'),
(88, 7, 7, 'TIMUATO'),
(89, 7, 7, 'TAPALULUO'),
(90, 7, 7, 'TONALA'),
(91, 8, 8, 'DULAMAYO'),
(92, 8, 8, 'UPOMELA'),
(93, 8, 8, 'TOHUPO'),
(94, 8, 8, 'MOLANIHU'),
(95, 8, 8, 'MOLOPATODU'),
(96, 8, 8, 'MOLAS'),
(97, 8, 8, 'BATULAYAR'),
(98, 8, 8, 'BATULORENG'),
(99, 8, 8, 'BONGOHULAWA'),
(100, 8, 8, 'OTOPADE'),
(101, 8, 8, 'HUNTU LO HULAWA'),
(102, 8, 8, 'OWALANGA'),
(103, 8, 8, 'LIYODU'),
(104, 8, 8, 'LIYOTO'),
(105, 8, 8, 'KAYUMERAH'),
(106, 9, 9, 'SUKAMAKMUR'),
(107, 9, 9, 'LAKEYA'),
(108, 9, 9, 'GANDASARI'),
(109, 9, 9, 'MOLOHU'),
(110, 9, 9, 'POLOHUNGO'),
(111, 9, 9, 'BINA JAYA'),
(112, 9, 9, 'TAMAILA'),
(113, 9, 9, 'SIDOARJO'),
(114, 9, 9, 'SUKAMAKMUR UTARA'),
(115, 9, 9, 'MARGOMULYA'),
(116, 9, 9, 'MAKMUR ABADI'),
(117, 9, 9, 'GANDARIA'),
(118, 9, 9, 'OMBULO TANGO'),
(119, 9, 9, 'TAMAILA UTARA'),
(120, 9, 9, 'HIMALAYA'),
(121, 10, 10, 'PARIS'),
(122, 10, 10, 'HELUMO'),
(123, 10, 10, 'PILOMONU'),
(124, 10, 10, 'SATRIA'),
(125, 10, 10, 'KARYAMUKTI'),
(126, 10, 10, 'TALUMOPATU'),
(127, 10, 10, 'SIDOMUKTI'),
(128, 10, 10, 'PAYU'),
(129, 10, 10, 'SUKAMAJU'),
(130, 10, 10, 'HUYULA'),
(131, 11, 11, 'PONGONGAILA'),
(132, 11, 11, 'PULUBALA'),
(133, 11, 11, 'MOLAMAHU'),
(134, 11, 11, 'BAKTI'),
(135, 11, 11, 'TRIDHARMA'),
(136, 11, 11, 'MOLALAHU'),
(137, 11, 11, 'MULYONEGORO'),
(138, 11, 11, 'PUNCAK'),
(139, 11, 11, 'TOYIDITO'),
(140, 11, 11, 'AYUMOLINGO'),
(141, 11, 11, 'BUKIT AREN'),
(142, 12, 12, 'PONE'),
(143, 12, 12, 'HUIDU'),
(144, 12, 12, 'OMBULO'),
(145, 12, 12, 'DAENAA'),
(146, 12, 12, 'YOSONEGORO'),
(147, 12, 12, 'TUNGGULO'),
(148, 12, 12, 'HUTABOHU'),
(149, 12, 12, 'PADENGO'),
(150, 12, 12, 'HAYA-HAYA'),
(151, 12, 12, 'HUIDU UTARA'),
(152, 13, 13, 'TUALANGO'),
(153, 13, 13, 'DULOMO'),
(154, 13, 13, 'TILOTE'),
(155, 13, 13, 'TABUMELA'),
(156, 13, 13, 'ILOTIDEA'),
(157, 13, 13, 'LAUWONU'),
(158, 13, 13, 'TENGGELA'),
(159, 13, 13, 'TINELO'),
(160, 14, 14, 'TABONGO TIMUR'),
(161, 14, 14, 'TABONGO BARAT'),
(162, 14, 14, 'LIMEHE BARAT'),
(163, 14, 14, 'LIMEHE TIMUR'),
(164, 14, 14, 'ILOMANGGA'),
(165, 14, 14, 'MOTINELO'),
(166, 14, 14, 'MOAHUDU'),
(167, 14, 14, 'TERATAI'),
(168, 14, 14, 'LIMEHU'),
(169, 15, 15, 'BILUHU BARAT'),
(170, 15, 15, 'LOBUTO'),
(171, 15, 15, 'BILUHU TENGAH'),
(172, 15, 15, 'LULUO'),
(173, 15, 15, 'HUWONGO'),
(174, 15, 15, 'LOBUTO TIMUR'),
(175, 15, 15, 'BOTUBOLU''O'),
(176, 15, 15, 'OLIMEYALA'),
(177, 16, 16, 'BULULI'),
(178, 16, 16, 'MOHIYOLO'),
(179, 16, 16, 'PANGAHU'),
(180, 16, 16, 'KARYA INDAH'),
(181, 16, 16, 'PRIMA'),
(182, 16, 16, 'TIOHU'),
(183, 16, 16, 'OLIMOHULO'),
(184, 16, 16, 'KARYA BARU'),
(185, 16, 16, 'BONTULA'),
(186, 16, 16, 'BIHE'),
(187, 17, 17, 'HUTADAA'),
(188, 17, 17, 'BUHU'),
(189, 17, 17, 'LUWOO'),
(190, 17, 17, 'BUNGGALO'),
(191, 17, 17, 'BULOTA'),
(192, 18, 18, 'TOTOPO'),
(193, 18, 18, 'BILATO'),
(194, 18, 18, 'ILOMATA'),
(195, 18, 18, 'TAULA''A'),
(196, 18, 18, 'JURIYA'),
(197, 18, 18, 'PELEHU'),
(198, 18, 18, 'BUMELA'),
(199, 18, 18, 'LAMAHU'),
(200, 18, 18, 'MUSYAWARAH'),
(201, 18, 18, 'SUKA DAMAI'),
(202, 19, 19, 'PILOLALENGA'),
(203, 19, 19, 'KALIYOSO'),
(204, 19, 19, 'DUNGALIYO'),
(205, 19, 19, 'AMBARA'),
(206, 19, 19, 'BONGOMEME'),
(207, 19, 19, 'DUWANGA'),
(208, 19, 19, 'AYUHULA'),
(209, 19, 19, 'PANGADAA'),
(210, 19, 19, 'BOTUBULOWE'),
(211, 19, 19, 'MOMALA');
INSERT IGNORE INTO `tb_puskes` (`id_puskes`, `id_kecamatan`, `nama_puskes`) VALUES
(1, 1, 'PUSKESMAS LIMBOTO'),
(2, 2, 'PUSKESMAS TELAGA'),
(3, 3, 'PUSKESMAS BATUDAA'),
(4, 4, 'PUSKESMAS TIBAWA'),
(5, 5, 'PUSKESMAS BATUDAA PANTAI'),
(6, 6, 'PUSKESMAS BOLIYOHUTO'),
(7, 7, 'PUSKESMAS TELAGA BIRU'),
(8, 8, 'PUSKESMAS BONGOMEME'),
(9, 9, 'PUSKESMAS TOLANGOHULA'),
(10, 10, 'PUSKESMAS MOOTILANGO'),
(11, 11, 'PUSKESMAS PULUBALA'),
(12, 12, 'PUSKESMAS LIMBOTO BARAT'),
(13, 13, 'PUSKESMAS TILANGO'),
(14, 14, 'PUSKESMAS TABONGO'),
(15, 15, 'PUSKESMAS BILUHU'),
(16, 16, 'PUSKESMAS ASPARAGA'),
(17, 17, 'PUSKESMAS TALAGA JAYA'),
(18, 18, 'PUSKESMAS BILATO'),
(19, 19, 'PUSKESMAS DUNGALIYO');
INSERT IGNORE INTO `tb_rumahsakit` (`id_rs`, `nama_rs`) VALUES
(1, 'RS Umum Daerah dr. M. Mohammad Dunda'),
(2, 'RS Umum Daerah Boliyohuto'),
(3, 'RS Bhayangkara Gorontalo'),
(4, 'RS Umum Daerah dr. Hasri Ainun Habibie'),
(5, 'Rumkit Tk. IV dr. Eddy Kounang Gorontalo');

-- ---------------------------------------------------------------------------
-- 5. Baris pengaturan awal yang dibuat migrasi (nilai awal = nilai di file migrasi).
-- ---------------------------------------------------------------------------
-- 20260906_service_settings.sql: pembatasan jam layanan nonaktif, Senin-Jumat 08:00-15:30, tanpa event.
INSERT IGNORE INTO `tb_service_settings` (`id`, `payload`) VALUES
(1, '{"enabled":false,"days":[1,2,3,4,5],"open":"08:00","close":"15:30","events":[]}');
-- 20260923_document_features.sql: sakelar formulir digital (salinan konstanta PHP lama).
INSERT IGNORE INTO `tb_document_features` (`id`, `payload`) VALUES
(1, '{"dokumen_hasil":false,"formulir_f101":true,"formulir_f201_kelahiran":true,"formulir_f201_kematian":true,"formulir_f101_add":false,"formulir_f201_kelahiran_add":false,"formulir_f201_kematian_add":false,"formulir_f101_kua":false,"formulir_f201_kelahiran_ahlak":false,"formulir_f201_kelahiran_rs":false,"formulir_f201_kematian_rs":false}');
-- 20261024_layanan_aktif.sql: '{}' = semua layanan aktif.
INSERT IGNORE INTO `tb_layanan_aktif` (`id`, `payload`) VALUES (1, '{}');
-- mobile_api_migrate.php: penanda versi skema API mobile.
INSERT IGNORE INTO `tb_mobile_migrations` (`version`) VALUES ('20260913_mobile_api');

-- 20261122_create_whatsapp_log.sql: tabel riwayat & audit pengiriman notifikasi WhatsApp Pemohon.
CREATE TABLE IF NOT EXISTS `tb_whatsapp_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `id_user` INT NULL,
  `no_hp` VARCHAR(30) NOT NULL,
  `permohonan_id` INT NULL,
  `source_table` VARCHAR(50) NOT NULL DEFAULT 'tb_pengajuan_kia',
  `event_type` VARCHAR(50) NOT NULL,
  `pesan` TEXT NOT NULL,
  `status` ENUM('sent', 'failed', 'skipped', 'pending') NOT NULL DEFAULT 'pending',
  `response_raw` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wa_user` (`id_user`),
  KEY `idx_wa_event` (`permohonan_id`, `event_type`),
  KEY `idx_wa_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. Bersihkan prosedur helper sementara.
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS siprima_tmp_exec;
DROP PROCEDURE IF EXISTS siprima_tmp_add_column;
DROP PROCEDURE IF EXISTS siprima_tmp_widen_varchar;
DROP PROCEDURE IF EXISTS siprima_tmp_widen_enum;
DROP PROCEDURE IF EXISTS siprima_tmp_add_index;
DROP PROCEDURE IF EXISTS siprima_tmp_add_check;
DROP PROCEDURE IF EXISTS siprima_tmp_replace_check;
DROP PROCEDURE IF EXISTS siprima_tmp_add_fk;
SET FOREIGN_KEY_CHECKS = @siprima_old_fk_checks;
SET @siprima_old_fk_checks = NULL;
SET @siprima_tmp_orphans = NULL;

-- Selesai.
