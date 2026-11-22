<?php

require_once __DIR__ . '/LaporanFilter.php';

final class LaporanRepository
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function count(LaporanFilter $filter): int
    {
        [$where, $types, $values] = $this->where($filter);
        $row = $this->one("SELECT COUNT(*) AS total FROM ({$this->unionSql()}) laporan {$where}", $types, $values);
        return (int)($row['total'] ?? 0);
    }

    public function page(LaporanFilter $filter): array
    {
        [$where, $types, $values] = $this->where($filter);
        $offset = ($filter->page - 1) * $filter->perPage;
        $sql = "SELECT id, jenis_dokumen, status, created_at, updated_at, judul, nik,
                       nama_kecamatan, nama_desa, operator_nama
                FROM ({$this->unionSql()}) laporan
                {$where}
                ORDER BY created_at DESC, jenis_dokumen ASC, id DESC
                LIMIT {$filter->perPage} OFFSET {$offset}";
        return $this->allRows($sql, $types, $values);
    }

    public function all(LaporanFilter $filter): array
    {
        [$where, $types, $values] = $this->where($filter);
        $sql = "SELECT id, jenis_dokumen, status, created_at, updated_at, judul, nik,
                       nama_kecamatan, nama_desa, operator_nama
                FROM ({$this->unionSql()}) laporan
                {$where}
                ORDER BY created_at DESC, jenis_dokumen ASC, id DESC";
        return $this->allRows($sql, $types, $values);
    }

    public function statistics(LaporanFilter $filter): array
    {
        [$where, $types, $values] = $this->where($filter);
        return $this->allRows(
            "SELECT jenis_dokumen,
                    COUNT(*) AS total,
                    COALESCE(SUM(status = 'diajukan'), 0) AS diajukan,
                    COALESCE(SUM(status = 'diproses'), 0) AS diproses,
                    COALESCE(SUM(status = 'selesai'), 0) AS selesai,
                    COALESCE(SUM(status = 'ditolak'), 0) AS ditolak
             FROM ({$this->unionSql()}) laporan
             {$where}
             GROUP BY jenis_dokumen
             ORDER BY jenis_dokumen",
            $types,
            $values
        );
    }

    public function locationAndRoleRows(LaporanFilter $filter): array
    {
        [$where, $types, $values] = $this->where($filter);
        return $this->allRows(
            "SELECT nama_kecamatan, nama_desa, status, jenis_dokumen, requester_role,
                    operator_nama, nama_rs, requester_id_kua
             FROM ({$this->unionSql()}) laporan {$where}",
            $types,
            $values
        );
    }

    public function chart(LaporanFilter $filter): array
    {
        [$where, $types, $values] = $this->where($filter);
        if ($filter->periode === 'hari_ini') {
            $bucket = "DATE_FORMAT(created_at, '%H')";
        } elseif ($filter->periode === 'bulan_ini') {
            $bucket = "DATE_FORMAT(created_at, '%d')";
        } elseif ($filter->periode === 'tahun_ini') {
            $bucket = "DATE_FORMAT(created_at, '%m')";
        } else {
            $bucket = "DATE_FORMAT(created_at, '%Y-%m')";
        }

        return $this->allRows(
            "SELECT {$bucket} AS bucket, COUNT(*) AS total
             FROM ({$this->unionSql()}) laporan
             {$where}
             GROUP BY bucket
             ORDER BY bucket",
            $types,
            $values
        );
    }

    public function years(): array
    {
        return $this->allRows(
            "SELECT DISTINCT YEAR(created_at) AS tahun
             FROM ({$this->unionSql()}) laporan
             WHERE created_at IS NOT NULL
             ORDER BY tahun DESC",
            '',
            []
        );
    }

    public function masterKecamatan(): array
    {
        return $this->allRows(
            "SELECT id, nama_kecamatan FROM tb_kecamatan ORDER BY nama_kecamatan",
            '',
            []
        );
    }

    public function masterDesa(): array
    {
        return $this->allRows(
            "SELECT d.id, d.nama_desa, k.nama_kecamatan
             FROM tb_desa d
             LEFT JOIN tb_kecamatan k ON k.id = d.kecamatan_id
             ORDER BY k.nama_kecamatan, d.nama_desa",
            '',
            []
        );
    }

    private function where(LaporanFilter $filter): array
    {
        $parts = [];
        $types = '';
        $values = [];
        $tz = new DateTimeZone('Asia/Makassar');

        if ($filter->periode === 'hari_ini') {
            $start = new DateTimeImmutable('today', $tz);
            $parts[] = 'created_at >= ? AND created_at < ?';
            $types .= 'ss';
            $values[] = $start->format('Y-m-d H:i:s');
            $values[] = $start->modify('+1 day')->format('Y-m-d H:i:s');
        } elseif ($filter->periode === 'bulan_ini') {
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $filter->tahun, $filter->bulan), $tz);
            $parts[] = 'created_at >= ? AND created_at < ?';
            $types .= 'ss';
            $values[] = $start->format('Y-m-d H:i:s');
            $values[] = $start->modify('+1 month')->format('Y-m-d H:i:s');
        } elseif ($filter->periode === 'tahun_ini') {
            $start = new DateTimeImmutable(sprintf('%04d-01-01', $filter->tahun), $tz);
            $parts[] = 'created_at >= ? AND created_at < ?';
            $types .= 'ss';
            $values[] = $start->format('Y-m-d H:i:s');
            $values[] = $start->modify('+1 year')->format('Y-m-d H:i:s');
        }

        if ($filter->tanggalDari !== '') {
            $parts[] = 'created_at >= ?';
            $types .= 's';
            $values[] = $filter->tanggalDari . ' 00:00:00';
        }
        if ($filter->tanggalSampai !== '') {
            $parts[] = 'created_at < ?';
            $types .= 's';
            $values[] = (new DateTimeImmutable($filter->tanggalSampai, $tz))->modify('+1 day')->format('Y-m-d H:i:s');
        }
        if ($filter->layanan !== '') {
            $parts[] = 'jenis_dokumen = ?';
            $types .= 's';
            $values[] = $filter->layanan;
        }
        if ($filter->status !== '') {
            $parts[] = 'LOWER(status) = ?';
            $types .= 's';
            $values[] = $filter->status;
        }
        if ($filter->q !== '') {
            $parts[] = '(judul LIKE ? OR nik LIKE ?)';
            $types .= 'ss';
            $needle = '%' . $this->escapeLike($filter->q) . '%';
            $values[] = $needle;
            $values[] = $needle;
        }

        return [$parts ? 'WHERE ' . implode(' AND ', $parts) : '', $types, $values];
    }

    private function unionSql(): string
    {
        return "
            SELECT kk.id, 'Kartu Keluarga' AS jenis_dokumen, kk.status, kk.created_at, kk.updated_at,
                   kk.nama_kepalaKeluarga AS judul, kk.nik_pemohon AS nik, kk.nama_kecamatan, kk.nama_desa,
                   u.nama AS operator_nama, LOWER(TRIM(COALESCE(u.role, ''))) AS requester_role,
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1) AS nama_rs,
                   u.id_kua AS requester_id_kua
            FROM tb_kartukeluarga kk LEFT JOIN tb_users u ON kk.id_user = u.id
            WHERE kk.kategori_layanan <> 'cetak_biodata'
            UNION ALL
            SELECT kk.id, 'Cetak Biodata', kk.status, kk.created_at, kk.updated_at,
                   kk.nama_kepalaKeluarga, kk.nik_pemohon, kk.nama_kecamatan, kk.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_kartukeluarga kk LEFT JOIN tb_users u ON kk.id_user = u.id
            WHERE kk.kategori_layanan = 'cetak_biodata'
            UNION ALL
            SELECT kk.id, 'KK KUA (PASUTRI)', kk.status, kk.created_at, kk.updated_at,
                   kk.nama_kepalaKeluarga, kk.nik_pemohon, kk.nama_kecamatan, kk.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_kartukeluarga_kua kk LEFT JOIN tb_users u ON kk.id_user = u.id
            UNION ALL
            SELECT ak.id, 'Akta Kelahiran', ak.status, ak.created_at, ak.updated_at,
                   ak.nama_bayi, ak.nik_pemohon, ak.nama_kecamatan, ak.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_akta_kelahiran ak LEFT JOIN tb_users u ON ak.id_user = u.id
            UNION ALL
            SELECT ak.id, 'Akta Kelahiran (AHLAK)', ak.status, ak.created_at, ak.updated_at,
                   ak.nama_bayi, ak.nik_pemohon, ak.nama_kecamatan, ak.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_akta_kelahiran_ahlak ak LEFT JOIN tb_users u ON ak.id_user = u.id
            UNION ALL
            SELECT ak.id, 'Akta Kelahiran RS (ST SEHATI)', ak.status, ak.created_at, ak.updated_at,
                   ak.nama_bayi, ak.nik_pemohon, ak.nama_kecamatan, ak.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_akta_kelahiran_rs ak LEFT JOIN tb_users u ON ak.id_user = u.id
            UNION ALL
            SELECT am.id, 'Akta Kematian', am.status, am.created_at, am.updated_at,
                   am.Nama_meninggal, am.nik_pemohon, am.nama_kecamatan, am.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_akta_kematian am LEFT JOIN tb_users u ON am.id_user = u.id
            UNION ALL
            SELECT am.id, 'Akta Kematian RS (ST SEHATI)', am.status, am.created_at, am.updated_at,
                   am.nama_meninggal, am.nik_pemohon, am.nama_kecamatan, am.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_akta_kematian_rs am LEFT JOIN tb_users u ON am.id_user = u.id
            UNION ALL
            SELECT pa.id, 'Pindah Alamat', pa.status, pa.created_at, pa.updated_at,
                   pa.nama_kepalaKeluarga, pa.nik_pemohon, pa.nama_kecamatan, pa.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_pindah_alamat pa LEFT JOIN tb_users u ON pa.id_user = u.id
            UNION ALL
            SELECT po.id, 'Pindah Masuk', po.status, po.created_at, po.updated_at,
                   po.nama_kepalaKeluarga, po.nik_pemohon, po.nama_kecamatan, po.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_pindah_online po LEFT JOIN tb_users u ON po.id_user = u.id
            UNION ALL
            SELECT lp.id_lapor, 'SIP PKK', lp.status, lp.created_at, lp.updated_at,
                   lp.nama_subjek, lp.nik_pelapor, lp.nama_kecamatan, lp.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   (SELECT rs.nama_rs FROM tb_rumahsakit rs WHERE rs.id_rs = u.id_rs LIMIT 1), u.id_kua
            FROM tb_laporan_peristiwa lp LEFT JOIN tb_users u ON lp.id_user = u.id
            UNION ALL
            SELECT k.id, 'KIA', LOWER(TRIM(COALESCE(k.status, 'diajukan'))), k.created_at, k.updated_at,
                   COALESCE((SELECT GROUP_CONCAT(a.nama_anak SEPARATOR ', ') FROM tb_anak_kia a WHERE a.id_pengajuan = k.id), k.no_kk),
                   COALESCE((SELECT a.nik_anak FROM tb_anak_kia a WHERE a.id_pengajuan = k.id ORDER BY a.id ASC LIMIT 1), u.nik, k.no_kk),
                   kc.nama_kecamatan, ds.nama_desa,
                   u.nama, LOWER(TRIM(COALESCE(u.role, ''))),
                   NULL, NULL
            FROM tb_pengajuan_kia k
            LEFT JOIN tb_users u ON k.id_user = u.id
            LEFT JOIN tb_kecamatan kc ON k.id_kecamatan = kc.id
            LEFT JOIN tb_desa ds ON k.id_desa = ds.id
        ";
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function one(string $sql, string $types, array $values): ?array
    {
        $rows = $this->allRows($sql, $types, $values);
        return $rows[0] ?? null;
    }

    private function allRows(string $sql, string $types, array $values): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log('Laporan query prepare gagal: ' . $this->db->error);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$values);
        }
        if (!$stmt->execute()) {
            error_log('Laporan query execute gagal: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}
