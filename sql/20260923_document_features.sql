-- Migration: 20260923_document_features.sql
-- Idempotent migration for Admin -> Manajemen Formulir Digital (`tb_document_features` & `tb_document_features_audit`)
-- Safe to run on databases where the tables and seed row already exist.

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

INSERT IGNORE INTO tb_document_features (id, payload) VALUES (1, '{"dokumen_hasil":false,"formulir_f101":true,"formulir_f201_kelahiran":true,"formulir_f201_kematian":true}');
