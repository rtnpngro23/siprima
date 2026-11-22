<?php
declare(strict_types=1);

namespace Siprima\Controllers;

use Siprima\Models\KiaModel;
use mysqli;
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;
use DateTimeZone;

final class KiaController
{
    private const MAX_FILE_SIZE = 5242880; // 5 MB (5 * 1024 * 1024 bytes)
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'jfif', 'jpe', 'png'];
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/pjpeg', 'image/x-png'];

    public function __construct(
        private KiaModel $model,
        private mysqli $db,
        private string $uploadBaseDir = __DIR__ . '/../../public/uploads/kia/'
    ) {}

    // request utama untuk API/Web Controller
    public function handleRequest(string $method, string $action, array $input, array $files, array $actor): array
    {
        return match ($action) {
            'create'                 => $this->handleCreate($method, $input, $files, $actor),
            'detail'                 => $this->handleDetail($method, $input, $actor),
            'list'                   => $this->handleList($method, $input, $actor),
            'resubmit'               => $this->handleResubmit($method, $input, $files, $actor),
            'update_status'          => $this->handleUpdateStatus($method, $input, $actor),
            'upload', 'upload_dokumen' => $this->handleUploadDokumen($method, $input, $files, $actor),
            'koreksi_anak'           => $this->handleKoreksiAnak($method, $input, $actor),
            'koreksi_mapping'        => $this->handleKoreksiMapping($method, $input, $actor),
            'lapor_kesalahan'        => $this->handleLaporKesalahan($method, $input, $actor),
            'list_laporan_kesalahan' => $this->handleListLaporanKesalahan($method, $input, $actor),
            'selesaikan_laporan'     => $this->handleSelesaikanLaporan($method, $input, $actor),
            'balas_laporan_kesalahan'=> $this->handleBalasLaporanKesalahan($method, $input, $actor),
            'submit_survei'          => $this->handleSubmitSurvei($method, $input, $actor),
            'survei_progress'        => $this->handleSurveiProgress($method, $input, $actor),
            'survei_admin_summary'   => $this->handleSurveiAdminSummary($method, $input, $actor),
            'export_survei_excel'    => $this->handleExportSurveiExcel($method, $input, $actor),
            'upload_pdf'             => $this->handleUploadPdfHasil($method, $input, $files, $actor),
            'download_pdf'           => $this->handleDownloadPdfHasil($method, $input, $actor),
            'view_dokumen'           => $this->handleViewDokumen($method, $input, $actor),
            'ajukan_cetak_kia'       => $this->handleAjukanCetakKia($method, $input, $actor),
            'update_status_cetak'    => $this->handleUpdateStatusCetak($method, $input, $actor),
            'list_cetak_kia'         => $this->handleListCetakKia($method, $input, $actor),
            'pengaturan_cetak_kia'   => $this->handlePengaturanCetakKia($method, $input, $actor),
            default => [
                'status' => 404,
                'data' => [
                    'success' => false,
                    'error' => 'Aksi tidak ditemukan: ' . htmlspecialchars($action)
                ]
            ]
        };
    }

    // submit pengajuan baru
    public function handleCreate(string $method, array $input, array $files, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        if (empty($actor['id'])) {
            return ['status' => 401, 'data' => ['success' => false, 'error' => 'Silakan login terlebih dahulu.']];
        }

        $userId = (int)$actor['id'];

        // cek jika request melebihi batas upload server
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0 && empty($input) && empty($files)) {
            $postMax = (string)ini_get('post_max_size');
            return [
                'status' => 422,
                'data' => [
                    'success' => false,
                    'error' => "Total ukuran file terlalu besar untuk diunggah.",
                    'fields' => [
                        'upload' => "Total ukuran file melebihi batas upload server."
                    ]
                ]
            ];
        }

        // 1. Validasi field utama permohonan
        $errors = [];
        $noKk = trim((string)($input['no_kk'] ?? ''));
        if ($noKk === '' && !empty($input['nik_pemohon'])) {
            $noKk = trim((string)$input['nik_pemohon']);
        }
        if ($noKk === '' && !empty($actor['nik'])) {
            $noKk = trim((string)$actor['nik']);
        }
        if ($noKk === '' && !empty($actor['id'])) {
            $stmtUserNik = $this->db->prepare("SELECT nik FROM tb_users WHERE id = ?");
            if ($stmtUserNik) {
                $stmtUserNik->bind_param('i', $actor['id']);
                $stmtUserNik->execute();
                $resUserNik = $stmtUserNik->get_result();
                if ($resUserNik && ($rowUserNik = $resUserNik->fetch_assoc())) {
                    $noKk = trim((string)($rowUserNik['nik'] ?? ''));
                }
            }
        }
        if ($noKk === '' || !preg_match('/^[0-9]{16}$/D', $noKk)) {
            $noKk = !empty($actor['id']) ? sprintf('%016d', (int)$actor['id']) : '0000000000000000';
        }

        $idKecamatan = (int)($input['id_kecamatan'] ?? 0);
        if ($idKecamatan <= 0) {
            $errors['id_kecamatan'] = 'Kecamatan wajib dipilih.';
        }

        $idDesa = (int)($input['id_desa'] ?? 0);
        if ($idDesa <= 0) {
            $errors['id_desa'] = 'Desa wajib dipilih.';
        } elseif ($idKecamatan > 0 && !$this->model->isDesaInKecamatan($idDesa, $idKecamatan)) {
            $errors['id_desa'] = 'Desa yang dipilih tidak berada pada Kecamatan yang dipilih. Silakan pilih Desa yang sesuai.';
        }

        $keterangan = trim((string)($input['keterangan_permohonan'] ?? ''));
        if ($keterangan === '') {
            $keterangan = 'Permohonan Kartu Identitas Anak (KIA)';
        }

        // 2. Validasi data anak (minimal 1 anak, mendukung multiple anak dalam KK)
        $rawAnak = $input['anak'] ?? [];
        if (is_string($rawAnak)) {
            $decoded = json_decode($rawAnak, true);
            if (is_array($decoded)) {
                $rawAnak = $decoded;
            }
        }

        if (!is_array($rawAnak) || empty($rawAnak)) {
            $errors['anak'] = 'Data anak wajib diisi minimal satu anak.';
        }

        $anakList = [];
        $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar'));
        $seenChildNiks = [];

        if (is_array($rawAnak)) {
            foreach ($rawAnak as $idx => $anak) {
                $namaAnak = trim((string)($anak['nama_anak'] ?? ''));
                $nikAnak = trim((string)($anak['nik_anak'] ?? ''));
                $tglLahirStr = trim((string)($anak['tanggal_lahir'] ?? ''));

                if ($namaAnak === '') {
                    $errors["anak_{$idx}_nama"] = "Nama anak ke-" . ($idx + 1) . " wajib diisi.";
                }

                if (!preg_match('/^[0-9]{16}$/D', $nikAnak)) {
                    $errors["anak_{$idx}_nik"] = "NIK anak ke-" . ($idx + 1) . " harus 16 digit angka.";
                } elseif (isset($seenChildNiks[$nikAnak])) {
                    $prevIdx = $seenChildNiks[$nikAnak] + 1;
                    $errors["anak_{$idx}_nik"] = "NIK anak ke-" . ($idx + 1) . " tidak boleh sama dengan NIK anak ke-{$prevIdx}. Setiap anak harus memiliki NIK yang berbeda.";
                } else {
                    $seenChildNiks[$nikAnak] = $idx;
                }

                $tglLahir = DateTimeImmutable::createFromFormat('Y-m-d', $tglLahirStr, new DateTimeZone('Asia/Makassar'));
                if (!$tglLahir || $tglLahir->format('Y-m-d') !== $tglLahirStr) {
                    $errors["anak_{$idx}_tanggal_lahir"] = "Tanggal lahir anak ke-" . ($idx + 1) . " harus berformat YYYY-MM-DD valid.";
                } elseif ($tglLahir > $today) {
                    $errors["anak_{$idx}_tanggal_lahir"] = "Tanggal lahir anak ke-" . ($idx + 1) . " tidak boleh di masa depan.";
                }

                $umurTahun = 0;
                if ($tglLahir) {
                    $diff = $today->diff($tglLahir);
                    $umurTahun = $diff->y;
                }

                $anakList[] = [
                    'nama_anak' => $namaAnak,
                    'nik_anak' => $nikAnak,
                    'tanggal_lahir' => $tglLahirStr,
                    'umur_tahun' => $umurTahun
                ];
            }
        }

        // 3. Validasi & Proses Dokumen Upload
        // Dokumen wajib menurut blueprint:
        // 1. FC KK terbaru (Wajib)
        // 2. FC Akta Kelahiran terbaru (Wajib per anak atau shared KK)
        // 3. Foto Pemohon memegang KK atau Akta (Wajib)
        // 4. Pasfoto Anak (Wajib jika usia anak >= 5 tahun, opsional jika < 5 tahun)
        // 5. Dokumen Lainnya (Opsional)
        $dokumenList = [];
        $uploadedDocTypes = [];

        // Siapkan direktori penyimpanan
        $folderName = 'kia_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $targetDir = rtrim($this->uploadBaseDir, '/\\') . '/' . $folderName . '/';

        // Helper memproses file upload
        $processUpload = function (array $fileInfo, string $jenisDokumen, ?int $anakIdx = null, ?string $errorKeyOverride = null) use (&$dokumenList, &$uploadedDocTypes, &$errors, $targetDir, $folderName) {
            $errKey = $errorKeyOverride ?? ($jenisDokumen . ($anakIdx !== null ? "_{$anakIdx}" : ''));
            $valResult = $this->validateAndSaveFile($fileInfo, $targetDir, $jenisDokumen, $folderName);
            if (!$valResult['success']) {
                $errors[$errKey] = $valResult['error'];
                return;
            }

            $saved = $valResult['data'];
            $saved['jenis_dokumen'] = $jenisDokumen;
            $saved['anak_index'] = $anakIdx;
            $dokumenList[] = $saved;
            $uploadedDocTypes[$jenisDokumen] = true;
            if ($anakIdx !== null) {
                $uploadedDocTypes["{$jenisDokumen}_{$anakIdx}"] = true;
            }
        };

        // Periksa dokumen dari $_FILES (abaikan slot file yang kosong / UPLOAD_ERR_NO_FILE)
        if (!$this->isFileSlotEmpty($files['fc_kk'] ?? null)) {
            $processUpload($files['fc_kk'], 'fc_kk', null, 'fc_kk');
        }

        // Akta kelahiran anak (proses slot spesifik per anak terlebih dahulu agar tidak terjadi duplikasi move_uploaded_file)
        $hasPerChildAktaSlot = false;
        foreach ($anakList as $idx => $anak) {
            $fileKey = "akta_kelahiran_{$idx}";
            if (array_key_exists($fileKey, $files) && !$this->isFileSlotEmpty($files[$fileKey])) {
                $hasPerChildAktaSlot = true;
                $processUpload($files[$fileKey], 'akta_kelahiran', $idx, $fileKey);
            }
        }

        // Jika tidak ada slot akta per anak, baru gunakan slot umum akta_kelahiran
        if (!$hasPerChildAktaSlot && !$this->isFileSlotEmpty($files['akta_kelahiran'] ?? null)) {
            $processUpload($files['akta_kelahiran'], 'akta_kelahiran', count($anakList) === 1 ? 0 : null, 'akta_kelahiran');
        }

        if (!$this->isFileSlotEmpty($files['foto_pemohon'] ?? null)) {
            $processUpload($files['foto_pemohon'], 'foto_pemohon', null, 'foto_pemohon');
        }

        // Pasfoto anak (proses slot spesifik per anak; hindari pemrosesan ganda alias pasfoto_anak dan pasfoto_anak_0)
        foreach ($anakList as $idx => $anak) {
            $fileKey = "pasfoto_anak_{$idx}";
            if (!$this->isFileSlotEmpty($files[$fileKey] ?? null)) {
                $processUpload($files[$fileKey], 'pasfoto_anak', $idx, $fileKey);
            } elseif ($idx === 0 && !$this->isFileSlotEmpty($files['pasfoto_anak'] ?? null) && empty($files['pasfoto_anak_0']['name'])) {
                $processUpload($files['pasfoto_anak'], 'pasfoto_anak', 0, 'pasfoto_anak_0');
            }
        }

        // Dokumen lainnya / Tambahan (opsional) - mendukung single slot atau multiple slot
        if (!$this->isFileSlotEmpty($files['dokumen_tambahan'] ?? null)) {
            $processUpload($files['dokumen_tambahan'], 'dokumen_tambahan', null, 'dokumen_tambahan');
        }
        foreach ($files as $key => $fileInfo) {
            if (preg_match('/^dokumen_tambahan_[0-9]+$/', (string)$key) && !$this->isFileSlotEmpty($fileInfo)) {
                $processUpload($fileInfo, 'dokumen_tambahan', null, (string)$key);
            }
        }

        // Validasi kelengkapan dokumen wajib
        if (empty($uploadedDocTypes['fc_kk']) && !isset($errors['fc_kk'])) {
            $errors['fc_kk'] = 'FC KK terbaru wajib diunggah.';
        }

        if (count($anakList) === 1) {
            $hasSingleAkta = !empty($uploadedDocTypes['akta_kelahiran']) || !empty($uploadedDocTypes['akta_kelahiran_0']);
            if (!$hasSingleAkta && !isset($errors['akta_kelahiran']) && !isset($errors['akta_kelahiran_0'])) {
                $errors['akta_kelahiran'] = 'FC Akta Kelahiran terbaru wajib diunggah.';
            }
        } else {
            // Untuk multiple anak: setiap anak memiliki slot akta kelahiran tersendiri
            foreach ($anakList as $idx => $anak) {
                $fileKey = "akta_kelahiran_{$idx}";
                if (empty($uploadedDocTypes[$fileKey]) && empty($uploadedDocTypes['akta_kelahiran']) && !isset($errors[$fileKey])) {
                    $errors[$fileKey] = 'FC Akta Kelahiran terbaru untuk anak ke-' . ($idx + 1) . ' (' . htmlspecialchars($anak['nama_anak']) . ') wajib diunggah.';
                }
            }
        }

        if (empty($uploadedDocTypes['foto_pemohon']) && !isset($errors['foto_pemohon'])) {
            $errors['foto_pemohon'] = 'Foto pemohon memegang KK atau Akta wajib diunggah.';
        }

        // Validasi aturan pasfoto anak berdasarkan usia:
        // Pasfoto Anak wajib hanya jika usia anak >= 5 tahun.
        foreach ($anakList as $idx => $anak) {
            if ($anak['umur_tahun'] >= 5) {
                $hasFoto = false;
                foreach ($dokumenList as $d) {
                    if ($d['jenis_dokumen'] === 'pasfoto_anak' && $d['anak_index'] === $idx) {
                        $hasFoto = true;
                        break;
                    }
                }
                if (!$hasFoto && !isset($errors["pasfoto_anak_{$idx}"])) {
                    $errors["pasfoto_anak_{$idx}"] = "Pasfoto anak wajib diunggah untuk anak usia >= 5 tahun (" . htmlspecialchars($anak['nama_anak']) . ", {$anak['umur_tahun']} tahun).";
                }
            }
        }

        if (!empty($errors)) {
            // Hapus file yang sempat tersimpan jika validasi gagal
            $this->cleanDirectory($targetDir);
            $detailMsg = implode('; ', $errors);
            return [
                'status' => 422,
                'data' => [
                    'success' => false,
                    'error' => "Mohon periksa kembali formulir: {$detailMsg}",
                    'fields' => $errors
                ]
            ];
        }

        try {
            $dataPengajuan = [
                'id_user' => $userId,
                'id_kecamatan' => $idKecamatan,
                'id_desa' => $idDesa,
                'no_kk' => $noKk,
                'keterangan_permohonan' => $keterangan
            ];

            $result = $this->model->createPengajuan($dataPengajuan, $anakList, $dokumenList, $actor);

            return [
                'status' => 201,
                'data' => [
                    'success' => true,
                    'message' => 'Pengajuan KIA berhasil dikirim.',
                    'data' => $result
                ]
            ];
        } catch (\Throwable $e) {
            $this->cleanDirectory($targetDir);
            return [
                'status' => 500,
                'data' => [
                    'success' => false,
                    'error' => 'Gagal menyimpan pengajuan KIA: ' . $e->getMessage()
                ]
            ];
        }
    }

    // detail pengajuan
    public function handleDetail(string $method, array $input, array $actor): array
    {
        if ($method !== 'GET') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus GET.']];
        }

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $userId = ($actor['role'] === 'user') ? (int)$actor['id'] : null;
        $detail = $this->model->getPengajuanById($id, $userId);

        if (!$detail) {
            return ['status' => 404, 'data' => ['success' => false, 'error' => 'Data pengajuan tidak ditemukan.']];
        }

        if ($actor['role'] === 'user' && ($detail['pengajuan']['status'] ?? '') !== 'selesai') {
            $detail['pengajuan']['dokumen_kia_pdf'] = null;
            if (!empty($detail['dokumen'])) {
                $detail['dokumen'] = array_values(array_filter($detail['dokumen'], function ($d) {
                    return ($d['jenis_dokumen'] ?? '') !== 'hasil_kia_pdf';
                }));
            }
        }

        return [
            'status' => 200,
            'data' => [
                'success' => true,
                'data' => $detail
            ]
        ];
    }

    // daftar pengajuan
    public function handleList(string $method, array $input, array $actor): array
    {
        if ($method !== 'GET') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus GET.']];
        }

        $filter = [];
        if ($actor['role'] === 'user') {
            $filter['id_user'] = (int)$actor['id'];
        }

        if (!empty($input['status'])) {
            $filter['status'] = (string)$input['status'];
        }

        if (!empty($input['kategori_antrian'])) {
            $filter['kategori_antrian'] = (string)$input['kategori_antrian'];
        }

        if (!empty($input['id_kecamatan'])) {
            $filter['id_kecamatan'] = (int)$input['id_kecamatan'];
        }

        if (!empty($input['id_desa'])) {
            $filter['id_desa'] = (int)$input['id_desa'];
        }

        if (!empty($input['q'])) {
            $filter['q'] = trim((string)$input['q']);
        }

        $limit = isset($input['limit']) ? (int)$input['limit'] : 20;
        $offset = isset($input['offset']) ? (int)$input['offset'] : 0;

        $result = $this->model->getPengajuanList($filter, $limit, $offset);

        return [
            'status' => 200,
            'data' => [
                'success' => true,
                'data' => $result
            ]
        ];
    }

    // submit ulang (resubmission) permohonan yang ditolak
    public function handleResubmit(string $method, array $input, array $files, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $userId = (int)($actor['id'] ?? 0);
        $existing = $this->model->getPengajuanById($id, $userId);
        if (!$existing) {
            return ['status' => 404, 'data' => ['success' => false, 'error' => 'Pengajuan KIA tidak ditemukan.']];
        }

        $currStatus = strtolower((string)($existing['pengajuan']['status'] ?? ''));
        if ($currStatus === 'selesai') {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Permohonan yang sudah selesai tidak dapat diedit kembali.']];
        }

        if (!in_array($currStatus, ['diajukan', 'diproses', 'ditolak'], true)) {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Permohonan tidak dapat diedit pada status ini.']];
        }

        // Siapkan dokumen perbaikan jika ada yang diunggah ulang
        $newDokumenList = [];
        $errors = [];
        $folderName = 'kia_revisi_' . $id . '_' . date('Ymd_His');
        $targetDir = rtrim($this->uploadBaseDir, '/\\') . '/' . $folderName . '/';

        foreach ($files as $fileKey => $fileInfo) {
            if ($this->isFileSlotEmpty($fileInfo)) {
                continue;
            }

            // Hindari duplikasi unggahan jika form mengirim alias pasfoto_anak dan pasfoto_anak_0 sekaligus
            if ($fileKey === 'pasfoto_anak' && !empty($files['pasfoto_anak_0']) && !$this->isFileSlotEmpty($files['pasfoto_anak_0'])) {
                continue;
            }
            if ($fileKey === 'akta_kelahiran' && !empty($files['akta_kelahiran_0']) && !$this->isFileSlotEmpty($files['akta_kelahiran_0'])) {
                continue;
            }

            // Ekstrak tipe dokumen dari fileKey (e.g. fc_kk, akta_kelahiran_0, pasfoto_anak_0, dokumen_tambahan_0)
            $jenisDokumen = $fileKey;
            $anakIdx = null;
            if (preg_match('/^pasfoto_anak_([0-9]+)$/', $fileKey, $m)) {
                $jenisDokumen = 'pasfoto_anak';
                $anakIdx = (int)$m[1];
            } elseif (preg_match('/^akta_kelahiran_([0-9]+)$/', $fileKey, $m)) {
                $jenisDokumen = 'akta_kelahiran';
                $anakIdx = (int)$m[1];
            } elseif (preg_match('/^dokumen_tambahan_([0-9]+)$/', $fileKey, $m)) {
                $jenisDokumen = 'dokumen_tambahan';
                $anakIdx = (int)$m[1];
            } elseif (preg_match('/^dokumen_tambahan$/', $fileKey)) {
                $jenisDokumen = 'dokumen_tambahan';
            }

            $valResult = $this->validateAndSaveFile($fileInfo, $targetDir, $jenisDokumen, $folderName);
            if (!$valResult['success']) {
                $errors[$fileKey] = $valResult['error'];
            } else {
                $saved = $valResult['data'];
                $saved['jenis_dokumen'] = $jenisDokumen;
                $saved['id_anak'] = $anakIdx !== null ? ($existing['anak'][$anakIdx]['id'] ?? null) : null;
                $newDokumenList[] = $saved;
            }
        }

        if (!empty($errors)) {
            $this->cleanDirectory($targetDir);
            $detailMsg = implode('; ', $errors);
            return [
                'status' => 422,
                'data' => [
                    'success' => false,
                    'error' => "Mohon periksa kembali berkas: {$detailMsg}",
                    'fields' => $errors
                ]
            ];
        }

        try {
            $updatedFields = [];
            if (!empty($input['keterangan_permohonan'])) {
                $updatedFields['keterangan_permohonan'] = trim((string)$input['keterangan_permohonan']);
            }
            if (!empty($input['no_kk']) && preg_match('/^[0-9]{16}$/D', trim((string)$input['no_kk']))) {
                $updatedFields['no_kk'] = trim((string)$input['no_kk']);
            }
            if (!empty($input['id_kecamatan'])) {
                $updatedFields['id_kecamatan'] = (int)$input['id_kecamatan'];
            }
            if (!empty($input['id_desa'])) {
                $updatedFields['id_desa'] = (int)$input['id_desa'];
            }
            $checkKec = (int)($updatedFields['id_kecamatan'] ?? $existing['pengajuan']['id_kecamatan'] ?? 0);
            $checkDesa = (int)($updatedFields['id_desa'] ?? $existing['pengajuan']['id_desa'] ?? 0);
            if ($checkKec > 0 && $checkDesa > 0 && !$this->model->isDesaInKecamatan($checkDesa, $checkKec)) {
                $this->cleanDirectory($targetDir);
                return [
                    'status' => 422,
                    'data' => [
                        'success' => false,
                        'error' => 'Desa yang dipilih tidak berada pada Kecamatan yang dipilih.',
                        'fields' => ['id_desa' => 'Desa yang dipilih tidak berada pada Kecamatan yang dipilih.']
                    ]
                ];
            }
            if (!empty($input['anak']) && is_array($input['anak'])) {
                $updatedFields['anak'] = $input['anak'];
            }

            $res = $this->model->resubmitPengajuan($id, $userId, $updatedFields, $newDokumenList, $actor);

            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Pengajuan berhasil diajukan kembali ke Antrian Perbaikan/Koreksi.',
                    'data' => $res
                ]
            ];
        } catch (\Throwable $e) {
            $this->cleanDirectory($targetDir);
            return [
                'status' => 500,
                'data' => ['success' => false, 'error' => 'Gagal memproses pengajuan ulang: ' . $e->getMessage()]
            ];
        }
    }

    // perubahan status oleh Operator / Petugas
    public function handleUpdateStatus(string $method, array $input, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        // Pemohon sama sekali tidak boleh mengubah status menjadi Ditolak atau Selesai
        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Anda tidak memiliki akses untuk mengubah status pengajuan.']];
        }

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $newStatus = trim((string)($input['status'] ?? ''));
        $alasan = trim((string)($input['alasan'] ?? ''));
        $requirePdf = !empty($input['require_pdf']);

        if (!in_array($newStatus, ['ditolak', 'selesai', 'diajukan', 'diproses'], true)) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'Status baru tidak valid.']];
        }

        if ($newStatus === 'ditolak' && $alasan === '') {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'Alasan/catatan penolakan wajib diisi jika status Ditolak.']];
        }

        try {
            $res = $this->model->updateStatus($id, $newStatus, $alasan, $actor, $requirePdf);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Status pengajuan KIA berhasil diperbarui.',
                    'data' => $res
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 422,
                'data' => ['success' => false, 'error' => $e->getMessage()]
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 500,
                'data' => ['success' => false, 'error' => 'Gagal mengubah status: ' . $e->getMessage()]
            ];
        }
    }

    // upload dokumen individual untuk pengajuan KIA
    public function handleUploadDokumen(string $method, array $input, array $files, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        if (empty($actor['id'])) {
            return ['status' => 401, 'data' => ['success' => false, 'error' => 'Silakan login terlebih dahulu.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid atau belum ditentukan.']];
        }

        // Cek pengajuan di database
        $pengajuanData = $this->model->getPengajuanById($idPengajuan);
        if (!$pengajuanData) {
            return ['status' => 404, 'data' => ['success' => false, 'error' => 'Pengajuan KIA tidak ditemukan.']];
        }

        $pengajuan = $pengajuanData['pengajuan'];

        // Guard kepemilikan: user biasa hanya boleh upload ke pengajuan miliknya sendiri
        if (($actor['role'] ?? 'user') === 'user' && (int)$pengajuan['id_user'] !== (int)$actor['id']) {
            return [
                'status' => 403,
                'data' => [
                    'success' => false,
                    'error' => 'Anda tidak memiliki akses ke pengajuan ini.'
                ]
            ];
        }

        // Guard status: pengajuan yang sudah selesai tidak boleh diubah oleh pemohon
        if ($pengajuan['status'] === 'selesai' && ($actor['role'] ?? 'user') === 'user') {
            return [
                'status' => 403,
                'data' => [
                    'success' => false,
                    'error' => 'Pengajuan yang sudah selesai tidak dapat menerima unggahan berkas baru.'
                ]
            ];
        }

        // Validasi jenis dokumen
        $jenisDokumen = trim((string)($input['jenis_dokumen'] ?? ''));
        $validJenis = ['fc_kk', 'akta_kelahiran', 'foto_pemohon', 'pasfoto_anak', 'dokumen_tambahan'];
        if (!in_array($jenisDokumen, $validJenis, true)) {
            return [
                'status' => 422,
                'data' => [
                    'success' => false,
                    'error' => 'Jenis dokumen tidak valid. Jenis yang diperbolehkan: fc_kk, akta_kelahiran, foto_pemohon, pasfoto_anak, dokumen_tambahan.'
                ]
            ];
        }

        // Ambil file dari $_FILES
        $fileInfo = $files['file'] ?? $files['dokumen'] ?? $files[$jenisDokumen] ?? null;
        if (!$fileInfo && !empty($files)) {
            $fileInfo = reset($files);
        }

        if ($this->isFileSlotEmpty($fileInfo)) {
            return [
                'status' => 422,
                'data' => [
                    'success' => false,
                    'error' => 'Berkas dokumen wajib diunggah.'
                ]
            ];
        }

        $idAnak = !empty($input['id_anak']) ? (int)$input['id_anak'] : null;

        $folderName = 'kia_pengajuan_' . $idPengajuan;
        $targetDir = rtrim($this->uploadBaseDir, '/\\') . '/' . $folderName . '/';

        $valResult = $this->validateAndSaveFile($fileInfo, $targetDir, $jenisDokumen, $folderName);
        if (!$valResult['success']) {
            return [
                'status' => 422,
                'data' => [
                    'success' => false,
                    'error' => $valResult['error']
                ]
            ];
        }

        try {
            $versiAktif = (int)($pengajuan['versi_aktif'] ?? 1);
            $savedDoc = $this->model->addDokumen(
                idPengajuan: $idPengajuan,
                idAnak: $idAnak,
                jenisDokumen: $jenisDokumen,
                fileData: $valResult['data'],
                versi: $versiAktif
            );

            return [
                'status' => 201,
                'data' => [
                    'success' => true,
                    'message' => "Dokumen {$jenisDokumen} berhasil diunggah.",
                    'data' => $savedDoc
                ]
            ];
        } catch (\Throwable $e) {
            // Hapus file fisik jika terjadi exception saat menyimpan record ke database
            if (!empty($valResult['data']['stored_name'])) {
                $filePath = $targetDir . $valResult['data']['stored_name'];
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }
            return [
                'status' => 500,
                'data' => [
                    'success' => false,
                    'error' => 'Gagal menyimpan record dokumen: ' . $e->getMessage()
                ]
            ];
        }
    }

    // apakah slot file pada $_FILES kosong / tidak dipilih oleh pemohon (UPLOAD_ERR_NO_FILE)
    private function isFileSlotEmpty(mixed $fileInfo): bool
    {
        if (!is_array($fileInfo)) {
            return true;
        }
        $err = $fileInfo['error'] ?? UPLOAD_ERR_NO_FILE;
        if (is_array($err)) {
            foreach ($err as $e) {
                if ((int)$e !== UPLOAD_ERR_NO_FILE) {
                    return false;
                }
            }
            return true;
        }
        if ((int)$err === UPLOAD_ERR_NO_FILE) {
            return true;
        }
        $name = trim((string)($fileInfo['name'] ?? ''));
        $tmpName = trim((string)($fileInfo['tmp_name'] ?? ''));
        if ($name === '' && $tmpName === '' && (int)$err === UPLOAD_ERR_OK) {
            return true;
        }
        return false;
    }

    // validasi format file dan penyimpanan ke storage lokal
    
    /**
     * Memotong proporsional (center crop 4:5) dan me-resize ke ukuran standar 480 x 600 piksel
     */
    public function resizeAndCropPasfotoTo480x600(string $srcPath, string $mime, string $destPath): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        $srcImg = match ($mime) {
            'image/jpeg', 'image/pjpeg', 'image/jpg' => @imagecreatefromjpeg($srcPath),
            'image/png', 'image/x-png' => @imagecreatefrompng($srcPath),
            default => null
        };

        if (!$srcImg) {
            $data = @file_get_contents($srcPath);
            if ($data !== false) {
                $srcImg = @imagecreatefromstring($data);
            }
        }

        if (!$srcImg) {
            return false;
        }

        // Normalisasi orientasi EXIF bila tersedia agar foto HP portrait tidak terputar
        if (function_exists('exif_read_data') && in_array($mime, ['image/jpeg', 'image/pjpeg', 'image/jpg'], true)) {
            $exif = @exif_read_data($srcPath);
            $orientation = (int)($exif['Orientation'] ?? 1);
            if ($orientation === 3) {
                $rot = @imagerotate($srcImg, 180, 0);
                if ($rot) { imagedestroy($srcImg); $srcImg = $rot; }
            } elseif ($orientation === 6) {
                $rot = @imagerotate($srcImg, -90, 0);
                if ($rot) { imagedestroy($srcImg); $srcImg = $rot; }
            } elseif ($orientation === 8) {
                $rot = @imagerotate($srcImg, 90, 0);
                if ($rot) { imagedestroy($srcImg); $srcImg = $rot; }
            }
        }

        $origW = imagesx($srcImg);
        $origH = imagesy($srcImg);
        if ($origW <= 0 || $origH <= 0) {
            imagedestroy($srcImg);
            return false;
        }

        $targetW = 480;
        $targetH = 600;
        $targetAspect = 0.8; // 4:5 portrait (480x600)
        $srcAspect = $origW / $origH;

        if ($srcAspect > $targetAspect) {
            // Gambar lebih lebar dari 4:5 -> crop horizontal terpusat tanpa meregangkan wajah
            $cropH = $origH;
            $cropW = (int)round($origH * $targetAspect);
            $cropX = (int)round(($origW - $cropW) / 2);
            $cropY = 0;
        } else {
            // Gambar lebih tinggi dari 4:5 -> bias 20% ke atas agar kepala & bahu tetap proporsional
            $cropW = $origW;
            $cropH = (int)round($origW / $targetAspect);
            $cropX = 0;
            $cropY = (int)round(($origH - $cropH) * 0.20);
        }

        $targetImg = imagecreatetruecolor($targetW, $targetH);
        if (!$targetImg) {
            imagedestroy($srcImg);
            return false;
        }

        $white = imagecolorallocate($targetImg, 255, 255, 255);
        imagefilledrectangle($targetImg, 0, 0, $targetW, $targetH, $white);

        imagecopyresampled(
            $targetImg,
            $srcImg,
            0, 0,
            $cropX, $cropY,
            $targetW, $targetH,
            $cropW, $cropH
        );

        $saved = false;
        if ($mime === 'image/png' || $mime === 'image/x-png') {
            $saved = @imagepng($targetImg, $destPath, 8);
        } else {
            $saved = @imagejpeg($targetImg, $destPath, 92);
        }

        imagedestroy($targetImg);
        imagedestroy($srcImg);

        return $saved;
    }

    private function validateAndSaveFile(array $fileInfo, string $targetDir, string $docType, string $folderName): array
    {
        $isCli = (PHP_SAPI === 'cli');
        $origName = basename((string)($fileInfo['name'] ?? ''));
        if ($origName === '' || $origName === '.' || $origName === '..') {
            $origName = $docType;
        }

        // 1. Periksa kode error upload PHP terlebih dahulu sebelum memeriksa tmp_name
        $uploadError = $fileInfo['error'] ?? UPLOAD_ERR_OK;
        if (is_array($uploadError)) {
            $uploadError = $uploadError[0] ?? UPLOAD_ERR_NO_FILE;
        }
        $uploadError = (int)$uploadError;

        if ($uploadError !== UPLOAD_ERR_OK) {
            switch ($uploadError) {
                case UPLOAD_ERR_INI_SIZE:
                    $iniMax = (string)ini_get('upload_max_filesize');
                    return [
                        'success' => false,
                        'error' => "Ukuran file {$origName} melebihi batas maksimal (5 MB).",
                    ];
                case UPLOAD_ERR_FORM_SIZE:
                    return [
                        'success' => false,
                        'error' => "Ukuran file {$origName} melebihi batas maksimal formulir.",
                    ];
                case UPLOAD_ERR_PARTIAL:
                    return [
                        'success' => false,
                        'error' => "File {$origName} hanya terunggah sebagian. Silakan coba lagi.",
                    ];
                case UPLOAD_ERR_NO_FILE:
                    return [
                        'success' => false,
                        'error' => "File {$origName} belum dipilih.",
                    ];
                case UPLOAD_ERR_NO_TMP_DIR:
                    return [
                        'success' => false,
                        'error' => "Gagal mengunggah file {$origName}, server bermasalah.",
                    ];
                case UPLOAD_ERR_CANT_WRITE:
                    return [
                        'success' => false,
                        'error' => "Gagal menyimpan file {$origName} ke server.",
                    ];
                case UPLOAD_ERR_EXTENSION:
                    return [
                        'success' => false,
                        'error' => "Unggahan file {$origName} dibatalkan sistem.",
                    ];
                default:
                    return [
                        'success' => false,
                        'error' => "Gagal mengunggah file {$origName}. Silakan coba lagi.",
                    ];
            }
        }

        $tmpName = $fileInfo['tmp_name'] ?? '';

        if (!is_string($tmpName) || $tmpName === '' || !file_exists($tmpName)) {
            return ['success' => false, 'error' => "File {$origName} tidak valid atau file sementara tidak ditemukan."];
        }

        if (!$isCli && !is_uploaded_file($tmpName)) {
            return ['success' => false, 'error' => "File {$origName} tidak valid atau bukan hasil unggahan HTTP yang sah."];
        }

        $fileSize = (int)($fileInfo['size'] ?? 0);
        if ($fileSize <= 0 && file_exists($tmpName)) {
            $fileSize = (int)filesize($tmpName);
        }

        if ($fileSize <= 0) {
            return ['success' => false, 'error' => "File {$origName} kosong atau berukuran 0 byte."];
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            return ['success' => false, 'error' => "Ukuran file {$origName} melebihi batas maksimal 5 MB ({$fileSize} bytes)."];
        }

        // Ekstrak ekstensi asli
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return ['success' => false, 'error' => "Format file {$origName} tidak didukung. Ekstensi .{$ext} tidak diizinkan. Gunakan format JPG, JPEG, JFIF, JPE, atau PNG."];
        }

        // Verifikasi MIME type melalui finfo
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $mime = $finfo ? (string)finfo_file($finfo, $tmpName) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return ['success' => false, 'error' => "MIME type aktual file {$origName} ({$mime}) tidak didukung. Hanya gambar JPEG/PNG yang diizinkan."];
        }

        // Verifikasi gambar nyata menggunakan getimagesize
        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo === false) {
            return ['success' => false, 'error' => "Isi file {$origName} rusak atau bukan gambar valid."];
        }

        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);

        // Aturan dimensi file:
        // 1. Pasfoto Anak (jenis_dokumen = 'pasfoto_anak'):
        //    Menerima dimensi pasfoto proporsional yang valid (tanpa mengunci ke 480x600 px),
        //    namun menolak gambar dengan resolusi terlalu rendah (< 150x150 px) atau rasio ekstrem.
        // 2. Foto Pemohon / Selfie, FC KK, FC Akta Kelahiran:
        //    Dimensi bebas asalkan gambar valid & ukuran <= 5 MB.
        if ($docType === 'pasfoto_anak') {
            if ($width < 150 || $height < 150) {
                return [
                    'success' => false,
                    'error' => "Resolusi pasfoto {$origName} terlalu rendah. Gunakan foto minimal 150x150 piksel agar jelas."
                ];
            }
            $ratio = $width / max(1, $height);
            if ($ratio < 0.25 || $ratio > 3.5) {
                return [
                    'success' => false,
                    'error' => "Proporsi pasfoto {$origName} kurang proporsional. Silakan crop kembali foto anak."
                ];
            }
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            return ['success' => false, 'error' => 'Gagal menyiapkan folder penyimpanan server.'];
        }

        // Letakkan .htaccess di subdirektori pengajuan demi keamanan
        $htaccessPath = rtrim($targetDir, '/\\') . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            @file_put_contents($htaccessPath, "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps)$\">\nOrder Deny,Allow\nDeny from all\n</FilesMatch>\n");
        }

        $safeExt = ($mime === 'image/png') ? 'png' : 'jpg';
        $safeDocType = preg_replace('/[^a-z0-9_]/', '', strtolower($docType));
        $storedName = $safeDocType . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
        $destination = rtrim($targetDir, '/\\') . '/' . $storedName;

        $moved = false;
        if ($docType === 'pasfoto_anak' && ($width !== 480 || $height !== 600)) {
            $moved = $this->resizeAndCropPasfotoTo480x600($tmpName, $mime, $destination);
            if ($moved && is_file($destination)) {
                $fileSize = (int)filesize($destination);
            }
        }
        if (!$moved) {
            if ($isCli) {
                $moved = @copy($tmpName, $destination);
            } else {
                $moved = @move_uploaded_file($tmpName, $destination);
            }
        }

        if (!$moved) {
            return ['success' => false, 'error' => "Gagal menyimpan file {$origName} ke disk."];
        }

        $relativePath = 'uploads/kia/' . $folderName . '/' . $storedName;

        return [
            'success' => true,
            'data' => [
                'original_name' => $origName,
                'stored_name' => $storedName,
                'file_path' => $relativePath,
                'mime_type' => $mime,
                'file_size' => $fileSize
            ]
        ];
    }

    // membersihkan direktori temporary jika submit gagal di tengah jalan
    private function cleanDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_file($path)) @unlink($path);
        }
        @rmdir($dir);
    }

    // koreksi data anak oleh Operator / Admin
    public function handleKoreksiAnak(string $method, array $input, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Hanya operator atau admin yang dapat mengoreksi data anak.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        $idAnak = (int)($input['id_anak'] ?? 0);

        if ($idPengajuan <= 0 || $idAnak <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan dan ID anak wajib ditentukan.']];
        }

        try {
            $updated = $this->model->koreksiDataAnak($idPengajuan, $idAnak, $input, $actor);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Data anak berhasil dikoreksi.',
                    'data' => $updated
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal mengoreksi data anak: ' . $e->getMessage()]];
        }
    }

    // koreksi / penukaran pemetaan dokumen anak oleh Operator / Admin
    public function handleKoreksiMapping(string $method, array $input, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Hanya operator atau admin yang dapat mengoreksi pemetaan berkas.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $mappings = $input['mappings'] ?? [];
        if (is_string($mappings)) {
            $decoded = json_decode($mappings, true);
            if (is_array($decoded)) {
                $mappings = $decoded;
            }
        }

        if (!is_array($mappings) || empty($mappings)) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'Daftar mapping dokumen wajib diisi.']];
        }

        try {
            $res = $this->model->koreksiDokumenMapping($idPengajuan, $mappings, $actor);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Pemetaan dokumen anak berhasil diperbarui.',
                    'data' => $res
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal mengoreksi pemetaan dokumen: ' . $e->getMessage()]];
        }
    }

    // pemohon melapor kesalahan pasca-selesai
    public function handleLaporKesalahan(string $method, array $input, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        if (empty($actor['id'])) {
            return ['status' => 401, 'data' => ['success' => false, 'error' => 'Silakan login terlebih dahulu.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $userId = (int)$actor['id'];
        $pengajuanData = $this->model->getPengajuanById($idPengajuan);
        if (!$pengajuanData) {
            return ['status' => 404, 'data' => ['success' => false, 'error' => 'Pengajuan KIA tidak ditemukan.']];
        }

        if (($actor['role'] ?? 'user') === 'user' && (int)$pengajuanData['pengajuan']['id_user'] !== $userId) {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Anda tidak memiliki akses ke pengajuan ini.']];
        }

        if ($pengajuanData['pengajuan']['status'] !== 'selesai') {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'Lapor kesalahan hanya dapat dilakukan untuk pengajuan yang berstatus Selesai.']];
        }

        $keterangan = trim((string)($input['keterangan_kesalahan'] ?? ''));
        if ($keterangan === '') {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'Keterangan kesalahan wajib diisi.']];
        }

        try {
            $res = $this->model->laporKesalahan($idPengajuan, $userId, $keterangan, (isset($input['id_anak']) && (int)$input['id_anak'] > 0 ? (int)$input['id_anak'] : null), (isset($input['id_dokumen']) && (int)$input['id_dokumen'] > 0 ? (int)$input['id_dokumen'] : null), trim((string)($input['kategori_kesalahan'] ?? 'kesalahan_lainnya')));
            return [
                'status' => 201,
                'data' => [
                    'success' => true,
                    'message' => 'Laporan kesalahan berhasil dikirimkan.',
                    'data' => $res
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal memproses laporan kesalahan: ' . $e->getMessage()]];
        }
    }

    // melihat daftar laporan kesalahan
    public function handleListLaporanKesalahan(string $method, array $input, array $actor): array
    {
        if ($method !== 'GET') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus GET.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $userId = ($actor['role'] === 'user') ? (int)$actor['id'] : null;

        try {
            $list = $this->model->getLaporanKesalahan($idPengajuan, $userId);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'data' => $list
                ]
            ];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal mengambil data laporan: ' . $e->getMessage()]];
        }
    }

    // operator menyelesaikan laporan kesalahan
    public function handleSelesaikanLaporan(string $method, array $input, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Hanya operator atau admin yang dapat menyelesaikan laporan.']];
        }

        $idLaporan = (int)($input['id_laporan'] ?? $input['id'] ?? 0);
        if ($idLaporan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID laporan tidak valid.']];
        }

        $catatan = trim((string)($input['catatan'] ?? ''));

        try {
            $res = $this->model->selesaikanLaporanKesalahan($idLaporan, $catatan, $actor);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Laporan kesalahan telah ditandai selesai dikoreksi.',
                    'data' => $res
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal menyelesaikan laporan: ' . $e->getMessage()]];
        }
    }

    // mengirim pesan percakapan Pemohon-Operator & mengubah status laporan kesalahan
    public function handleBalasLaporanKesalahan(string $method, array $input, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }
        if (empty($actor['id'])) {
            return ['status' => 401, 'data' => ['success' => false, 'error' => 'Silakan login terlebih dahulu.']];
        }
        $idLaporan = (int)($input['id_laporan'] ?? $input['id'] ?? 0);
        if ($idLaporan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID laporan tidak valid.']];
        }
        $isiPesan = trim((string)($input['isi_pesan'] ?? $input['pesan'] ?? ''));
        $statusLaporan = isset($input['status_laporan']) ? trim((string)$input['status_laporan']) : null;

        try {
            $res = $this->model->tambahPesanLaporanKesalahan($idLaporan, $isiPesan, $statusLaporan, $actor);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Tanggapan laporan kesalahan berhasil disimpan.',
                    'data' => $res,
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal menyimpan tanggapan: ' . $e->getMessage()]];
        }
    }

    // submit survei terintegrasi (SKM, SPKP, SPAK)
    public function handleSubmitSurvei(string $method, array $input, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        if (empty($actor['id'])) {
            return ['status' => 401, 'data' => ['success' => false, 'error' => 'Silakan login terlebih dahulu.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $jenisSurvei = trim((string)($input['jenis_survei'] ?? ''));

        $responden = $input['responden'] ?? [];
        if (is_string($responden)) {
            $decoded = json_decode($responden, true);
            if (is_array($decoded)) {
                $responden = $decoded;
            }
        }

        $jawaban = $input['jawaban'] ?? [];
        if (is_string($jawaban)) {
            $decodedJ = json_decode($jawaban, true);
            if (is_array($decodedJ)) {
                $jawaban = $decodedJ;
            }
        }

        $tanggalSurvei = !empty($input['tanggal_survei']) ? trim((string)$input['tanggal_survei']) : null;
        $saran = isset($input['saran'])
            ? trim((string)$input['saran'])
            : (isset($responden['saran']) ? trim((string)$responden['saran']) : null);

        try {
            $userId = (int)$actor['id'];
            $res = $this->model->submitSurvei($idPengajuan, $userId, $jenisSurvei, $responden, $jawaban, $tanggalSurvei, $saran);
            return [
                'status' => 201,
                'data' => [
                    'success' => true,
                    'message' => 'Survei ' . strtoupper($jenisSurvei) . ' berhasil disimpan.',
                    'data' => $res
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal menyimpan survei: ' . $e->getMessage()]];
        }
    }

    // memeriksa progres survei harian
    public function handleSurveiProgress(string $method, array $input, array $actor): array
    {
        if ($method !== 'GET') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus GET.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $userId = ($actor['role'] === 'user') ? (int)$actor['id'] : (int)($input['id_user'] ?? $actor['id']);
        $tanggal = !empty($input['tanggal']) ? trim((string)$input['tanggal']) : null;

        try {
            $progress = $this->model->getSurveiProgress($idPengajuan, $userId, $tanggal);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'data' => $progress
                ]
            ];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal mengambil progres survei: ' . $e->getMessage()]];
        }
    }

    // rekapitulasi & analisis hasil survei untuk Admin/Operator
    public function handleSurveiAdminSummary(string $method, array $input, array $actor): array
    {
        if ($method !== 'GET') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus GET.']];
        }

        $allowedRoles = ['operator', 'admin'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            return [
                'status' => 403,
                'data' => [
                    'success' => false,
                    'error' => 'Hanya admin dan operator yang dapat melihat rekap survei.'
                ]
            ];
        }

        $periode = (string)($input['periode'] ?? 'hari_ini');
        $customStart = !empty($input['custom_start']) ? trim((string)$input['custom_start']) : null;
        $customEnd = !empty($input['custom_end']) ? trim((string)$input['custom_end']) : null;

        try {
            $summary = $this->model->getSurveiAdminSummary($periode, $customStart, $customEnd);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'data' => $summary
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal menghitung rekap survei: ' . $e->getMessage()]];
        }
    }

    // operator mengunggah berkas PDF hasil KIA
    public function handleUploadPdfHasil(string $method, array $input, array $files, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            return [
                'status' => 403,
                'data' => [
                    'success' => false,
                    'error' => 'Hanya operator atau admin yang dapat mengunggah PDF hasil.'
                ]
            ];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        // Cari file PDF di dalam $_FILES
        $filePdf = null;
        if (!empty($files['dokumen_kia_pdf'])) {
            $filePdf = $files['dokumen_kia_pdf'];
        } elseif (!empty($files['file_pdf'])) {
            $filePdf = $files['file_pdf'];
        } elseif (!empty($files['pdf_hasil'])) {
            $filePdf = $files['pdf_hasil'];
        } else {
            foreach ($files as $f) {
                if (is_array($f) && isset($f['tmp_name'])) {
                    $filePdf = $f;
                    break;
                }
            }
        }

        if (!$filePdf || !is_array($filePdf) || empty($filePdf['name'])) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'Berkas PDF hasil KIA wajib diunggah.']];
        }

        $errCode = (int)($filePdf['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => "Gagal mengunggah berkas PDF (kode error: {$errCode})."]];
        }

        $origName = basename((string)$filePdf['name']);
        $tmpName = (string)$filePdf['tmp_name'];
        $fileSize = (int)($filePdf['size'] ?? 0);
        $isCli = (PHP_SAPI === 'cli');

        if (!$isCli && !is_uploaded_file($tmpName)) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'File bukan hasil upload HTTP yang sah.']];
        }

        if (!file_exists($tmpName) || !is_readable($tmpName)) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'File temporer tidak ditemukan atau tidak dapat dibaca.']];
        }

        // 1. Validasi 0 byte
        if ($fileSize <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => "File PDF {$origName} kosong (0 byte) tidak diterima."]];
        }

        // 2. Validasi batas maksimal 5 MB
        if ($fileSize > self::MAX_FILE_SIZE) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => "Ukuran file PDF {$origName} melebihi batas maksimal 5 MB."]];
        }

        // 3. Validasi ekstensi hanya .pdf
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            return ['status' => 422, 'data' => ['success' => false, 'error' => "Format file {$origName} tidak diizinkan. Hanya berkas PDF (.pdf) yang diperbolehkan."]];
        }

        // 4. Validasi keamanan nama file
        if (preg_match('/\.(php|phtml|phps|phar|pl|py|jsp|asp|aspx|sh|cgi|exe|bat|cmd|html|htm|svg)(\.|$)/i', $origName)) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'Nama atau ekstensi file terindikasi berbahaya.']];
        }

        // 5. Validasi MIME type aktual
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $mime = $finfo ? (string)finfo_file($finfo, $tmpName) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => "MIME type aktual file {$origName} ({$mime}) tidak valid. Berkas wajib berupa dokumen PDF resmi."]];
        }

        // 6. Validasi magic bytes header %PDF-
        $header = @file_get_contents($tmpName, false, null, 0, 5);
        if ($header !== '%PDF-') {
            return ['status' => 422, 'data' => ['success' => false, 'error' => "Struktur berkas PDF {$origName} rusak atau bukan dokumen PDF valid."]];
        }

        // Siapkan direktori penyimpanan uploads/kia_hasil/
        $hasilBaseDir = rtrim($this->uploadBaseDir, '/\\') . '/../kia_hasil/';
        if (!is_dir($hasilBaseDir) && !mkdir($hasilBaseDir, 0755, true)) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal menyiapkan folder penyimpanan PDF hasil di server.']];
        }

        // Pasang .htaccess di direktori hasil demi keamanan
        $htaccessPath = $hasilBaseDir . '.htaccess';
        if (!file_exists($htaccessPath)) {
            @file_put_contents($htaccessPath, "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps)$\">\nOrder Deny,Allow\nDeny from all\n</FilesMatch>\n");
        }

        $storedName = 'kia_hasil_' . $idPengajuan . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.pdf';
        $destination = $hasilBaseDir . $storedName;
        $relativeFilePath = 'uploads/kia_hasil/' . $storedName;

        $moved = false;
        if ($isCli) {
            $moved = @copy($tmpName, $destination);
        } else {
            $moved = @move_uploaded_file($tmpName, $destination);
        }

        if (!$moved) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => "Gagal memindahkan file PDF {$origName} ke folder server."]];
        }

        try {
            $result = $this->model->uploadDokumenHasilPdf($idPengajuan, [
                'original_name' => $origName,
                'stored_name' => $storedName,
                'file_path' => $relativeFilePath,
                'mime_type' => 'application/pdf',
                'file_size' => $fileSize
            ], $actor, (isset($input['id_anak']) && (int)$input['id_anak'] > 0 ? (int)$input['id_anak'] : null));

            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Berkas PDF hasil KIA berhasil diunggah oleh petugas.',
                    'data' => $result
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            @unlink($destination);
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            @unlink($destination);
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal menyimpan data PDF hasil KIA: ' . $e->getMessage()]];
        }
    }

    // akses / unduh berkas PDF hasil KIA
    public function handleDownloadPdfHasil(string $method, array $input, array $actor): array
    {
        if ($method !== 'GET') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus GET.']];
        }

        if (empty($actor['id'])) {
            return ['status' => 401, 'data' => ['success' => false, 'error' => 'Silakan login terlebih dahulu.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        try {
            $pdfInfo = $this->model->getDokumenHasilPdf($idPengajuan, $actor);

            if ($pdfInfo === null) {
                return [
                    'status' => 404,
                    'data' => [
                        'success' => false,
                        'error' => 'Berkas PDF hasil KIA belum diunggah untuk permohonan ini.'
                    ]
                ];
            }

            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'data' => $pdfInfo
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            // Pelanggaran hak akses atau status guard -> 403 Forbidden
            return [
                'status' => 403,
                'data' => [
                    'success' => false,
                    'error' => $e->getMessage()
                ]
            ];
        } catch (\RuntimeException $e) {
            return [
                'status' => 404,
                'data' => [
                    'success' => false,
                    'error' => $e->getMessage()
                ]
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 500,
                'data' => [
                    'success' => false,
                    'error' => 'Gagal memproses permintaan dokumen hasil: ' . $e->getMessage()
                ]
            ];
        }
    }

    // akses / pratinjau / unduh berkas dokumen KIA (foto_pemohon, pasfoto_anak, fc_kk, akta_kelahiran, dokumen_tambahan, hasil_kia_pdf)
    public function handleViewDokumen(string $method, array $input, array $actor): array
    {
        if ($method !== 'GET') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus GET.']];
        }

        if (empty($actor['id'])) {
            return ['status' => 401, 'data' => ['success' => false, 'error' => 'Silakan login terlebih dahulu.']];
        }

        $idDokumen = (int)($input['id_dokumen'] ?? $input['id'] ?? 0);
        if ($idDokumen <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID dokumen tidak valid.']];
        }

        $stmt = $this->db->prepare("
            SELECT d.*, p.id_user, p.id_kecamatan, LOWER(p.status) AS status_pengajuan, a.nama_anak, a.urutan_anak
            FROM tb_dokumen_kia d
            INNER JOIN tb_pengajuan_kia p ON p.id = d.id_pengajuan
            LEFT JOIN tb_anak_kia a ON a.id = d.id_anak
            WHERE d.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal menyiapkan query dokumen KIA.']];
        }
        $stmt->bind_param('i', $idDokumen);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$doc) {
            return ['status' => 404, 'data' => ['success' => false, 'error' => 'Dokumen tidak ditemukan.']];
        }

        $role = strtolower((string)($actor['role'] ?? 'user'));
        $isStaff = in_array($role, ['operator', 'admin', 'petugas_desa'], true);
        $isOwner = ((int)$actor['id'] === (int)($doc['id_user'] ?? 0));

        if (!$isStaff && !$isOwner) {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Anda tidak memiliki akses ke dokumen ini.']];
        }

        if (!$isStaff && ($doc['jenis_dokumen'] ?? '') === 'hasil_kia_pdf' && ($doc['status_pengajuan'] ?? '') !== 'selesai') {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Berkas PDF hasil KIA hanya dapat diakses setelah permohonan berstatus Selesai.']];
        }

        $relPath = str_replace('\\', '/', ltrim((string)($doc['file_path'] ?? ''), '/\\'));
        if ($relPath === '' || (!str_starts_with($relPath, 'uploads/kia/') && !str_starts_with($relPath, 'uploads/kia_hasil/'))) {
            error_log("KIA Invalid document path for id_dokumen={$idDokumen}: {$relPath}");
            return ['status' => 404, 'data' => ['success' => false, 'error' => 'Lokasi penyimpanan berkas tidak valid.']];
        }

        $publicRoot = realpath(__DIR__ . '/../../public');
        $absCandidate = ($publicRoot !== false ? $publicRoot : (__DIR__ . '/../../public')) . '/' . $relPath;
        $realAbs = realpath($absCandidate);
        $uploadsRoot = realpath(($publicRoot !== false ? $publicRoot : (__DIR__ . '/../../public')) . '/uploads');

        if ($realAbs === false || !is_file($realAbs) || !is_readable($realAbs) || ($uploadsRoot !== false && !str_starts_with(strtolower($realAbs), strtolower($uploadsRoot)))) {
            error_log("KIA Missing physical document file for id_dokumen={$idDokumen}: {$absCandidate}");
            return [
                'status' => 404,
                'data' => [
                    'success' => false,
                    'error' => 'File dokumen tidak ditemukan di server.'
                ]
            ];
        }

        $mime = trim((string)($doc['mime_type'] ?? ''));
        if ($mime === '' && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detected = finfo_file($fi, $realAbs);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
                finfo_close($fi);
            }
        }
        if ($mime === '') {
            $ext = strtolower(pathinfo($realAbs, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'pdf' => 'application/pdf',
                'gif' => 'image/gif',
                default => 'image/jpeg'
            };
        }

        return [
            'status' => 200,
            'data' => [
                'success' => true,
                'data' => [
                    'id_dokumen' => (int)$doc['id'],
                    'id_pengajuan' => (int)$doc['id_pengajuan'],
                    'id_anak' => $doc['id_anak'] ? (int)$doc['id_anak'] : null,
                    'nama_anak' => $doc['nama_anak'] ?? null,
                    'jenis_dokumen' => (string)($doc['jenis_dokumen'] ?? ''),
                    'original_name' => (string)($doc['original_name'] ?? basename($realAbs)),
                    'file_path' => $relPath,
                    'abs_path' => $realAbs,
                    'mime_type' => $mime,
                    'file_size' => (int)filesize($realAbs),
                    'versi' => (int)($doc['versi'] ?? 1),
                    'status_dokumen' => (string)($doc['status_dokumen'] ?? 'valid')
                ]
            ]
        ];
    }

    // ekspor data survei (SKM, SPKP, atau SPAK secara terpisah) ke Excel (.xlsx) khusus Admin
    public function handleExportSurveiExcel(string $method, array $input, array $actor): array
    {
        if ($method !== 'GET') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus GET.']];
        }

        if (($actor['role'] ?? '') !== 'admin') {
            return [
                'status' => 403,
                'data' => [
                    'success' => false,
                    'error' => 'Hanya admin yang dapat mengekspor data survei.'
                ]
            ];
        }

        $jenisSurvei = strtolower(trim((string)($input['jenis_survei'] ?? '')));
        $periode = (string)($input['periode'] ?? 'semua');
        $customStart = !empty($input['custom_start']) ? trim((string)$input['custom_start']) : null;
        $customEnd = !empty($input['custom_end']) ? trim((string)$input['custom_end']) : null;

        try {
            $exportData = $this->model->getSurveiExportData($jenisSurvei, $periode, $customStart, $customEnd);
            $xlsxBinary = $this->model->generateSurveiExcelXlsx($jenisSurvei, $periode, $customStart, $customEnd);
            $filename = 'Laporan_Survei_' . strtoupper($jenisSurvei) . '_KIA_' . date('Ymd_His') . '.xlsx';

            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'filename' => $filename,
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'size_bytes' => strlen($xlsxBinary),
                    'xlsx_base64' => base64_encode($xlsxBinary),
                    'meta' => $exportData
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal mengekspor Excel survei: ' . $e->getMessage()]];
        }
    }

    // pemohon mengajukan permintaan cetak fisik KIA
    public function handleAjukanCetakKia(string $method, array $input, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        if (empty($actor['id'])) {
            return ['status' => 401, 'data' => ['success' => false, 'error' => 'Silakan login terlebih dahulu.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $catatanPemohon = trim((string)($input['catatan_pemohon'] ?? ''));

        try {
            $res = $this->model->ajukanCetakKia($idPengajuan, (int)$actor['id'], $catatanPemohon, $actor);
            return [
                'status' => 201,
                'data' => [
                    'success' => true,
                    'message' => 'Permintaan cetak kartu fisik KIA berhasil diajukan dan masuk ke antrean cetak petugas.',
                    'data' => $res
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\RuntimeException $e) {
            return ['status' => 404, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal mengajukan cetak KIA: ' . $e->getMessage()]];
        }
    }

    // operator / Admin memperbarui status cetak fisik KIA ('menunggu_cetak', 'sedang_dicetak', 'selesai_dicetak')
    public function handleUpdateStatusCetak(string $method, array $input, array $actor): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus POST.']];
        }

        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Hanya operator atau admin yang dapat mengubah status cetak fisik.']];
        }

        $idPengajuan = (int)($input['id_pengajuan'] ?? $input['id'] ?? 0);
        if ($idPengajuan <= 0) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => 'ID pengajuan tidak valid.']];
        }

        $statusCetak = trim((string)($input['status_cetak'] ?? ''));
        $catatanOperator = trim((string)($input['catatan_operator'] ?? ''));

        try {
            $res = $this->model->updateStatusCetakKia($idPengajuan, $statusCetak, $catatanOperator, $actor);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Status cetak fisik KIA berhasil diperbarui menjadi ' . ($res['label_status_cetak'] ?? $statusCetak) . '.',
                    'data' => $res
                ]
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\RuntimeException $e) {
            return ['status' => 404, 'data' => ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal memperbarui status cetak KIA: ' . $e->getMessage()]];
        }
    }

    // daftar antrean cetak fisik KIA untuk Operator / Admin
    public function handleListCetakKia(string $method, array $input, array $actor): array
    {
        if ($method !== 'GET') {
            return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP harus GET.']];
        }

        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            return ['status' => 403, 'data' => ['success' => false, 'error' => 'Hanya operator atau admin yang dapat melihat antrean cetak.']];
        }

        try {
            $list = $this->model->getDaftarCetakKia($input);
            $pengaturan = $this->model->getPengaturanCetakKia();
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'data' => [
                        'items' => $list,
                        'total' => count($list),
                        'pengaturan_cetak' => $pengaturan
                    ]
                ]
            ];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal memuat antrean cetak KIA: ' . $e->getMessage()]];
        }
    }

    // melihat (GET) atau memperbarui (POST - khusus Admin) pengaturan ketersediaan bahan cetak KIA
    public function handlePengaturanCetakKia(string $method, array $input, array $actor): array
    {
        if ($method === 'GET') {
            $includeLog = in_array($actor['role'] ?? '', ['admin', 'operator'], true);
            $data = $this->model->getPengaturanCetakKia($includeLog);
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'data' => $data
                ]
            ];
        }

        if ($method === 'POST') {
            if (($actor['role'] ?? '') !== 'admin') {
                return [
                    'status' => 403,
                    'data' => [
                        'success' => false,
                        'error' => 'Hanya admin yang dapat mengubah pengaturan bahan cetak.'
                    ]
                ];
            }

            try {
                $updated = $this->model->updatePengaturanCetakKia($input, $actor);
                return [
                    'status' => 200,
                    'data' => [
                        'success' => true,
                        'message' => 'Pengaturan ketersediaan bahan cetak KIA berhasil disimpan.',
                        'data' => $updated
                    ]
                ];
            } catch (\InvalidArgumentException $e) {
                return ['status' => 422, 'data' => ['success' => false, 'error' => $e->getMessage()]];
            } catch (\Throwable $e) {
                return ['status' => 500, 'data' => ['success' => false, 'error' => 'Gagal menyimpan pengaturan cetak KIA: ' . $e->getMessage()]];
            }
        }

        return ['status' => 405, 'data' => ['success' => false, 'error' => 'Metode HTTP tidak didukung.']];
    }
}
