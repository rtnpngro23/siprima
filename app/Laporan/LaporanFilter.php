<?php

final class LaporanFilter
{
    public const LAYANAN = [
        'Kartu Keluarga',
        'Cetak Biodata',
        'KK KUA (PASUTRI)',
        'Akta Kelahiran',
        'Akta Kelahiran (AHLAK)',
        'Akta Kelahiran RS (ST SEHATI)',
        'Akta Kematian',
        'Akta Kematian RS (ST SEHATI)',
        'Pindah Alamat',
        'Pindah Masuk',
        'SIP PKK',
        'KIA',
    ];

    public const STATUS = ['diajukan', 'diproses', 'selesai', 'ditolak'];

    public string $periode = '';
    public int $tahun;
    public int $bulan;
    public string $layanan = '';
    public string $status = '';
    public string $q = '';
    public string $tanggalDari = '';
    public string $tanggalSampai = '';
    public int $page = 1;
    public int $perPage = 10;

    public static function fromArray(array $input, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar'));
        $filter = new self();

        $periode = trim((string)($input['periode'] ?? ''));
        $filter->periode = in_array($periode, ['', 'hari_ini', 'bulan_ini', 'tahun_ini'], true)
            ? $periode
            : '';

        $currentYear = (int)$now->format('Y');
        $tahun = filter_var($input['tahun'] ?? $currentYear, FILTER_VALIDATE_INT);
        $filter->tahun = ($tahun !== false && $tahun >= 2000 && $tahun <= $currentYear + 1)
            ? (int)$tahun
            : $currentYear;

        $bulanRaw = trim((string)($input['bulan'] ?? $now->format('n')));
        $bulan = ctype_digit($bulanRaw) ? (int)$bulanRaw : 0;
        $filter->bulan = ($bulan >= 1 && $bulan <= 12)
            ? $bulan
            : (int)$now->format('n');

        $layanan = trim((string)($input['layanan'] ?? ''));
        $filter->layanan = in_array($layanan, self::LAYANAN, true) ? $layanan : '';

        $status = strtolower(trim((string)($input['status_filter'] ?? '')));
        $filter->status = in_array($status, self::STATUS, true) ? $status : '';

        $q = trim((string)($input['q'] ?? ''));
        $filter->q = function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);

        $filter->tanggalDari = self::validDate((string)($input['tanggal_dari'] ?? ''));
        $filter->tanggalSampai = self::validDate((string)($input['tanggal_sampai'] ?? ''));
        if ($filter->tanggalDari !== '' && $filter->tanggalSampai !== ''
            && $filter->tanggalDari > $filter->tanggalSampai) {
            [$filter->tanggalDari, $filter->tanggalSampai] = [$filter->tanggalSampai, $filter->tanggalDari];
        }

        // Batasi satu permintaan laporan ke maksimal 366 hari.
        if ($filter->tanggalDari !== '' && $filter->tanggalSampai !== '') {
            $from = new DateTimeImmutable($filter->tanggalDari);
            $to = new DateTimeImmutable($filter->tanggalSampai);
            if ($from->diff($to)->days > 365) {
                $filter->tanggalSampai = $from->modify('+365 days')->format('Y-m-d');
            }
        }

        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT);
        $filter->page = ($page !== false && $page >= 1) ? min((int)$page, 1000000) : 1;

        return $filter;
    }

    public function toQueryArray(bool $includePage = true): array
    {
        $result = [
            'periode' => $this->periode,
            'bulan' => sprintf('%02d', $this->bulan),
            'tahun' => (string)$this->tahun,
            'layanan' => $this->layanan,
            'status_filter' => $this->status,
            'tanggal_dari' => $this->tanggalDari,
            'tanggal_sampai' => $this->tanggalSampai,
            'q' => $this->q,
        ];
        if ($includePage) {
            $result['page'] = (string)$this->page;
        }
        return $result;
    }

    private static function validDate(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
