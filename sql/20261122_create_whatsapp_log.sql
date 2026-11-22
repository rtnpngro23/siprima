-- Migration: 20261122_create_whatsapp_log.sql
-- Idempotent migration for Pemohon WhatsApp notification audit log (`tb_whatsapp_log`).
-- `WhatsAppService::ensureTable()` remains active as a non-blocking compatibility fallback.

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
