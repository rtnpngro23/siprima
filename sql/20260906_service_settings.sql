CREATE TABLE IF NOT EXISTS tb_service_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    payload LONGTEXT NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO tb_service_settings (id, payload) VALUES
(1, '{"enabled":false,"days":[1,2,3,4,5],"open":"08:00","close":"15:30","events":[]}');
CREATE TABLE IF NOT EXISTS tb_service_settings_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    changed_at DATETIME NOT NULL,
    action VARCHAR(100) NOT NULL,
    before_payload LONGTEXT NOT NULL,
    after_payload LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Preserve dated rules from the previous PHP configuration as completed events.
UPDATE tb_service_settings SET payload='{"enabled":false,"days":[1,2,3,4,5],"open":"08:00","close":"15:30","events":[{"id":"0000000000000001","enabled":true,"kind":"closure","title":"Hari Buruh Internasional (May Day)","message":"Hari Buruh Internasional (May Day)","announce":"2026-05-01T00:00","start":"2026-05-01T00:00","end":"2026-05-02T00:00"},{"id":"0000000000000002","enabled":true,"kind":"closure","title":"Libur Nasional Kenaikan Yesus Kristus","message":"Libur Nasional Kenaikan Yesus Kristus","announce":"2026-05-14T00:00","start":"2026-05-14T00:00","end":"2026-05-15T00:00"},{"id":"0000000000000003","enabled":true,"kind":"closure","title":"Cuti Bersama Kenaikan Yesus Kristus","message":"Cuti Bersama Kenaikan Yesus Kristus","announce":"2026-05-15T00:00","start":"2026-05-15T00:00","end":"2026-05-16T00:00"},{"id":"0000000000000004","enabled":true,"kind":"closure","title":"Penyesuaian jam layanan","message":"Penyesuaian jam layanan","announce":"2026-05-26T00:00","start":"2026-05-26T14:00","end":"2026-05-27T00:00"},{"id":"0000000000000005","enabled":true,"kind":"closure","title":"Hari Raya Idul Adha","message":"Hari Raya Idul Adha","announce":"2026-05-27T00:00","start":"2026-05-27T00:00","end":"2026-05-28T00:00"},{"id":"0000000000000006","enabled":true,"kind":"closure","title":"Cuti Bersama Idul Adha","message":"Cuti Bersama Idul Adha","announce":"2026-05-28T00:00","start":"2026-05-28T00:00","end":"2026-05-29T00:00"},{"id":"0000000000000007","enabled":true,"kind":"closure","title":"Libur Nasional Hari Lahir Pancasila","message":"Libur Nasional Hari Lahir Pancasila","announce":"2026-06-01T00:00","start":"2026-06-01T00:00","end":"2026-06-02T00:00"},{"id":"0000000000000008","enabled":true,"kind":"closure","title":"Tahun Baru Islam 1448 Hijriah","message":"Tahun Baru Islam 1448 Hijriah","announce":"2026-06-16T00:00","start":"2026-06-16T00:00","end":"2026-06-17T00:00"},{"id":"0000000000000009","enabled":true,"kind":"closure","title":"Perbaikan jaringan","message":"Perbaikan jaringan","announce":"2026-06-24T00:00","start":"2026-06-24T00:00","end":"2026-06-25T00:00"},{"id":"000000000000000a","enabled":true,"kind":"closure","title":"Pemberitahuan Perbaikan Jaringan","message":"Pemberitahuan Perbaikan Jaringan","announce":"2026-09-02T08:00","start":"2026-09-02T13:00","end":"2026-09-03T00:00"}]}' WHERE id=1 AND revision=1 AND payload='{"enabled":false,"days":[1,2,3,4,5],"open":"08:00","close":"15:30","events":[]}';
