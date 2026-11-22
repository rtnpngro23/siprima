CREATE TABLE IF NOT EXISTS `tb_whistleblowing_aduan` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode_aduan` VARCHAR(32) NOT NULL,
  `pemohon_id` INT NOT NULL,
  `kategori` ENUM('pungli_gratifikasi','penyalahgunaan_wewenang','diskriminasi','pelanggaran_prosedur','pelanggaran_data','lainnya') NOT NULL,
  `judul` VARCHAR(150) NOT NULL,
  `uraian` TEXT NOT NULL,
  `tanggal_kejadian` DATE DEFAULT NULL,
  `lokasi_kejadian` VARCHAR(255) DEFAULT NULL,
  `pihak_terlapor` VARCHAR(255) DEFAULT NULL,
  `layanan_terkait` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('baru','diverifikasi','diproses','menunggu_pemohon','selesai','ditolak') NOT NULL DEFAULT 'baru',
  `admin_penanggung_jawab_id` INT DEFAULT NULL,
  `selesai_at` DATETIME DEFAULT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `ringkasan_publik` TEXT DEFAULT NULL,
  `hasil_tindak_lanjut_publik` TEXT DEFAULT NULL,
  `published_by` INT DEFAULT NULL,
  `published_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_whistleblowing_kode` (`kode_aduan`),
  KEY `idx_whistleblowing_pemohon` (`pemohon_id`,`updated_at`),
  KEY `idx_whistleblowing_admin` (`status`,`updated_at`),
  KEY `idx_whistleblowing_publik` (`is_published`,`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_whistleblowing_tindak_lanjut` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `aduan_id` BIGINT UNSIGNED NOT NULL,
  `actor_user_id` INT NOT NULL,
  `actor_role` ENUM('pemohon','admin') NOT NULL,
  `status_from` VARCHAR(32) DEFAULT NULL,
  `status_to` VARCHAR(32) DEFAULT NULL,
  `pesan` TEXT NOT NULL,
  `visibility` ENUM('pemohon','internal') NOT NULL DEFAULT 'pemohon',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_whistleblowing_timeline` (`aduan_id`,`created_at`,`id`),
  CONSTRAINT `fk_whistleblowing_tindak_lanjut_aduan` FOREIGN KEY (`aduan_id`)
    REFERENCES `tb_whistleblowing_aduan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_whistleblowing_lampiran` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `aduan_id` BIGINT UNSIGNED NOT NULL,
  `tindak_lanjut_id` BIGINT UNSIGNED DEFAULT NULL,
  `uploaded_by` INT NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_whistleblowing_lampiran_aduan` (`aduan_id`,`uploaded_at`),
  KEY `idx_whistleblowing_lampiran_tindak_lanjut` (`tindak_lanjut_id`),
  CONSTRAINT `fk_whistleblowing_lampiran_aduan` FOREIGN KEY (`aduan_id`)
    REFERENCES `tb_whistleblowing_aduan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_whistleblowing_lampiran_tindak_lanjut` FOREIGN KEY (`tindak_lanjut_id`)
    REFERENCES `tb_whistleblowing_tindak_lanjut` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
