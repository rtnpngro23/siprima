-- Additive mobile API schema. Apply after website/security migrations.
-- Existing tables must use InnoDB; checked by mobile_api_migrate.php.
CREATE TABLE IF NOT EXISTS tb_mobile_sessions (
 id CHAR(32) PRIMARY KEY,
 user_id INT NOT NULL,
 device_name VARCHAR(100) NOT NULL,
 access_hash CHAR(64) NOT NULL UNIQUE,
 refresh_hash CHAR(64) NOT NULL UNIQUE,
 fingerprint CHAR(64) NOT NULL,
 access_expires_at BIGINT NOT NULL,
 expires_at BIGINT NOT NULL,
 last_used_at BIGINT NOT NULL,
 created_at BIGINT NOT NULL,
 revoked_at BIGINT NULL,
 KEY idx_mobile_user (user_id), KEY idx_mobile_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tb_mobile_used_refresh (
 token_hash CHAR(64) PRIMARY KEY,
 session_id CHAR(32) NOT NULL,
 expires_at BIGINT NOT NULL,
 KEY idx_mobile_refresh_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tb_mobile_uploads (
 id CHAR(32) PRIMARY KEY,
 user_id INT NOT NULL,
 service_code VARCHAR(32) NOT NULL,
 doc_type VARCHAR(64) NOT NULL,
 original_name VARCHAR(200) NOT NULL,
 stored_name VARCHAR(80) NOT NULL,
 mime_type VARCHAR(100) NOT NULL,
 file_size INT NOT NULL,
 created_at BIGINT NOT NULL,
 expires_at BIGINT NOT NULL,
 application_ref VARCHAR(80) NULL,
 KEY idx_mobile_upload_owner (user_id,application_ref,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tb_mobile_idempotency (
 user_id INT NOT NULL,
 key_hash CHAR(64) NOT NULL,
 scope VARCHAR(160) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 response_json LONGTEXT NULL,
 created_at BIGINT NOT NULL,
 PRIMARY KEY (user_id,key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tb_mobile_rate_limits (
 bucket CHAR(64) PRIMARY KEY,
 hits INT NOT NULL,
 expires_at BIGINT NOT NULL,
 KEY idx_mobile_rate_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tb_mobile_migrations (
 version VARCHAR(80) PRIMARY KEY,
 applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tb_mobile_outbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_table VARCHAR(64) NOT NULL,
 permohonan_id INT NOT NULL,
 event_type VARCHAR(32) NOT NULL,
 created_at BIGINT NOT NULL,
 delivered_at BIGINT NULL,
 KEY idx_mobile_outbox_pending (delivered_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
