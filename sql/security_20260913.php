<?php
declare(strict_types=1);
// Idempotent migration. CLI only; run against the intended database after backup.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/config/koneksi.php';
if (!in_array('--apply', $argv, true)) { echo "Usage: php sql/security_20260913.php --apply\n"; exit(1); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$engine = $koneksi->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_users'")->fetch_assoc();
if (!$engine || strtoupper((string)$engine['ENGINE']) !== 'INNODB') throw new RuntimeException('tb_users must use InnoDB for atomic reset operations.');
foreach ([
    'must_change_password' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'temporary_password_expires_at' => 'DATETIME NULL',
] as $column => $definition) {
    $result = $koneksi->query("SHOW COLUMNS FROM tb_users LIKE '$column'");
    if (!$result->num_rows) $koneksi->query("ALTER TABLE tb_users ADD COLUMN `$column` $definition");
}
$koneksi->query("CREATE TABLE IF NOT EXISTS tb_password_reset (
    user_id INT NOT NULL PRIMARY KEY,
    requested_by INT NOT NULL,
    request_id CHAR(32) NOT NULL UNIQUE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    password_snapshot VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    email_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    telegram_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Security migration completed.\n";
