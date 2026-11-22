-- Compatible with the existing status_history_helper.php schema. No historical dates are fabricated.
CREATE TABLE IF NOT EXISTS tb_permohonan_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_table VARCHAR(64) NOT NULL,
    permohonan_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    alasan TEXT NULL,
    actor_id INT NULL,
    actor_role VARCHAR(50) NULL,
    event_type VARCHAR(32) NULL,
    meta_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_source_permohonan_created (source_table, permohonan_id, created_at),
    KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
