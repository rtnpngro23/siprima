-- Migration: 20261102_kia_features_final.sql
-- Fitur Final Modul KIA: Laporan Kesalahan Pasca-Selesai & Survei Terintegrasi (SKM, SPKP, SPAK)
-- Sesuai Blueprint blueprint_KIA.xml (Diagram 7 ERD, Use Case, Sequence Flow)

CREATE TABLE IF NOT EXISTS `tb_laporan_kesalahan_kia` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `id_pengajuan` INT NOT NULL,
  `id_user` INT NOT NULL,
  `keterangan_kesalahan` TEXT NOT NULL,
  `status` ENUM('menunggu_koreksi', 'selesai_dikoreksi') NOT NULL DEFAULT 'menunggu_koreksi',
  `id_operator` INT NULL,
  `waktu_lapor` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `waktu_koreksi` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lapor_kia_pengajuan` (`id_pengajuan`),
  KEY `idx_lapor_kia_user` (`id_user`),
  KEY `idx_lapor_kia_status` (`status`),
  CONSTRAINT `fk_lapor_kia_pengajuan` FOREIGN KEY (`id_pengajuan`) REFERENCES `tb_pengajuan_kia` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lapor_kia_user` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lapor_kia_operator` FOREIGN KEY (`id_operator`) REFERENCES `tb_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_survei_kia` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `id_pengajuan` INT NOT NULL,
  `id_user` INT NOT NULL,
  `tanggal_survei` DATE NOT NULL,
  `jenis_survei` ENUM('skm', 'spkp', 'spak') NOT NULL,
  `status` ENUM('draft', 'selesai') NOT NULL DEFAULT 'selesai',
  `nama_lengkap` VARCHAR(255) NOT NULL,
  `no_hp` VARCHAR(30) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `jenis_kelamin` ENUM('L', 'P') NOT NULL,
  `usia` INT NOT NULL,
  `pendidikan` VARCHAR(100) NOT NULL,
  `pekerjaan` VARCHAR(100) NOT NULL,
  `alamat` TEXT NULL,
  `waktu_pengisian` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_survei_harian_jenis` (`id_pengajuan`, `tanggal_survei`, `jenis_survei`),
  KEY `idx_survei_kia_pengajuan` (`id_pengajuan`),
  KEY `idx_survei_kia_user` (`id_user`),
  KEY `idx_survei_kia_tanggal` (`tanggal_survei`),
  KEY `idx_survei_kia_jenis` (`jenis_survei`),
  CONSTRAINT `fk_survei_kia_pengajuan` FOREIGN KEY (`id_pengajuan`) REFERENCES `tb_pengajuan_kia` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_survei_kia_user` FOREIGN KEY (`id_user`) REFERENCES `tb_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tb_jawaban_survei_kia` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `id_survei` INT NOT NULL,
  `bagian_survei` ENUM('SKM', 'SPKP', 'SPAK') NOT NULL,
  `kode_pertanyaan` VARCHAR(50) NOT NULL,
  `nilai_jawaban` TINYINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jawaban_survei_tanya` (`id_survei`, `kode_pertanyaan`),
  KEY `idx_jawaban_survei_parent` (`id_survei`),
  KEY `idx_jawaban_survei_kode` (`kode_pertanyaan`),
  CONSTRAINT `fk_jawaban_survei_parent` FOREIGN KEY (`id_survei`) REFERENCES `tb_survei_kia` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
