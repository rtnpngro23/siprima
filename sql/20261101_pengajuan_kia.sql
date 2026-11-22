-- Migration: 20261101_pengajuan_kia.sql
-- Modul KIA (Kartu Identitas Anak) SIPRIMA
-- Sesuai Blueprint blueprint_KIA.xml (Diagram 7 ERD & Diagram 8 Page-8)

CREATE TABLE IF NOT EXISTS `tb_pengajuan_kia` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `id_user` INT NOT NULL,
  `id_kecamatan` INT NOT NULL,
  `id_desa` INT NOT NULL,
  `no_kk` VARCHAR(16) NOT NULL,
  `keterangan_permohonan` TEXT NOT NULL,
  `status` ENUM('diajukan', 'ditolak', 'selesai') NOT NULL DEFAULT 'diajukan',
  `kategori_antrian` ENUM('Antrian Pengajuan Baru', 'Antrian Perbaikan/Koreksi') NOT NULL DEFAULT 'Antrian Pengajuan Baru',
  `versi_aktif` INT NOT NULL DEFAULT 1,
  `alasan_penolakan` TEXT NULL,
  `dokumen_kia_pdf` VARCHAR(255) NULL,
  `resubmitted_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kia_user` (`id_user`),
  KEY `idx_kia_kecamatan` (`id_kecamatan`),
  KEY `idx_kia_desa` (`id_desa`),
  KEY `idx_kia_status` (`status`),
  KEY `idx_kia_kategori_antrian` (`kategori_antrian`),
  CONSTRAINT `fk_kia_user` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kia_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES `tb_kecamatan` (`id`),
  CONSTRAINT `fk_kia_desa` FOREIGN KEY (`id_desa`) REFERENCES `tb_desa` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_anak_kia` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `id_pengajuan` INT NOT NULL,
  `nama_anak` VARCHAR(100) NOT NULL,
  `nik_anak` VARCHAR(16) NOT NULL,
  `tanggal_lahir` DATE NOT NULL,
  `urutan_anak` INT NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_anak_kia_pengajuan` (`id_pengajuan`),
  KEY `idx_anak_kia_nik` (`nik_anak`),
  CONSTRAINT `fk_anak_kia_pengajuan` FOREIGN KEY (`id_pengajuan`) REFERENCES `tb_pengajuan_kia` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_dokumen_kia` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `id_pengajuan` INT NOT NULL,
  `id_anak` INT NULL,
  `jenis_dokumen` VARCHAR(50) NOT NULL COMMENT 'fc_kk, akta_kelahiran, pasfoto_anak, foto_pemohon, dokumen_tambahan',
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT NOT NULL,
  `versi` INT NOT NULL DEFAULT 1,
  `status_dokumen` VARCHAR(32) NOT NULL DEFAULT 'valid' COMMENT 'valid, ditolak, perlu_perbaikan',
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dokumen_kia_pengajuan` (`id_pengajuan`),
  KEY `idx_dokumen_kia_anak` (`id_anak`),
  KEY `idx_dokumen_kia_jenis` (`jenis_dokumen`),
  CONSTRAINT `fk_dokumen_kia_pengajuan` FOREIGN KEY (`id_pengajuan`) REFERENCES `tb_pengajuan_kia` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dokumen_kia_anak` FOREIGN KEY (`id_anak`) REFERENCES `tb_anak_kia` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
