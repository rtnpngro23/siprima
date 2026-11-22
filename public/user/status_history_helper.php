<?php

if (!function_exists('ensurePermohonanStatusHistoryTable')) {
    function ensurePermohonanStatusHistoryTable(mysqli $koneksi): bool
    {
        static $checked = false;
        static $ready = false;

        if ($checked) {
            return $ready;
        }

        $checked = true;
        $sql = "CREATE TABLE IF NOT EXISTS tb_permohonan_status_history (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ready = (bool)$koneksi->query($sql);
        if (!$ready) {
            error_log('Gagal memastikan tabel tb_permohonan_status_history: ' . $koneksi->error);
        }

        return $ready;
    }
}

if (!function_exists('logPermohonanStatusHistory')) {
    function logPermohonanStatusHistory(
        mysqli $koneksi,
        string $sourceTable,
        int $permohonanId,
        string $status,
        ?string $alasan,
        ?int $actorId = null,
        ?string $actorRole = null,
        ?string $eventType = null,
        ?array $meta = null
    ): bool {
        if ($permohonanId <= 0 || $sourceTable === '' || $status === '') {
            return false;
        }

        if (!ensurePermohonanStatusHistoryTable($koneksi)) {
            return false;
        }

        $metaJson = null;
        if (is_array($meta) && !empty($meta)) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $koneksi->prepare(
            "INSERT INTO tb_permohonan_status_history
            (source_table, permohonan_id, status, alasan, actor_id, actor_role, event_type, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            error_log('Gagal prepare log status history: ' . $koneksi->error);
            return false;
        }

        $stmt->bind_param(
            'sississs',
            $sourceTable,
            $permohonanId,
            $status,
            $alasan,
            $actorId,
            $actorRole,
            $eventType,
            $metaJson
        );

        $ok = $stmt->execute();
        if (!$ok) {
            error_log('Gagal insert status history: ' . $stmt->error);
        }
        $stmt->close();

        return (bool)$ok;
    }
}

if (!function_exists('getPermohonanRejectionHistory')) {
    function getPermohonanRejectionHistory(mysqli $koneksi, string $sourceTable, int $permohonanId, int $limit = 10): array
    {
        if ($permohonanId <= 0 || $sourceTable === '') {
            return [];
        }

        if (!ensurePermohonanStatusHistoryTable($koneksi)) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $query = "SELECT status, alasan, actor_id, actor_role, event_type,
                         DATE_FORMAT(created_at, '%d-%m-%Y %H:%i') AS created_at_formatted,
                         created_at
                  FROM tb_permohonan_status_history
                  WHERE source_table = ?
                    AND permohonan_id = ?
                    AND status = 'ditolak'
                    AND alasan IS NOT NULL
                    AND TRIM(alasan) <> ''
                  ORDER BY created_at DESC
                  LIMIT " . (int)$limit;

        $stmt = $koneksi->prepare($query);
        if (!$stmt) {
            error_log('Gagal prepare ambil history penolakan: ' . $koneksi->error);
            return [];
        }

        $stmt->bind_param('si', $sourceTable, $permohonanId);
        if (!$stmt->execute()) {
            error_log('Gagal execute ambil history penolakan: ' . $stmt->error);
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
