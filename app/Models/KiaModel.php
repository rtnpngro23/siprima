<?php
declare(strict_types=1);

namespace Siprima\Models;

use mysqli;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

final class KiaModel
{
    private bool $pengajuanSchemaChecked = true;

    public function __construct(private mysqli $db)
    {
    }

    // inisialisasi skema (tabel sudah siap)
    public function ensurePengajuanSchema(): void
    {
    }

    // bahwa id_desa benar-benar berada pada id_kecamatan yang dipilih
    public function isDesaInKecamatan(int $idDesa, int $idKecamatan): bool
    {
        if ($idDesa <= 0 || $idKecamatan <= 0) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT id FROM tb_desa WHERE id = ? AND kecamatan_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $idDesa, $idKecamatan);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }

    // pengajuan KIA baru beserta data anak, dokumen, dan riwayat status awal
    public function createPengajuan(array $dataPengajuan, array $dataAnakList, array $dokumenList, array $actor): array
    {
        if (empty($dataAnakList)) {
            throw new InvalidArgumentException('Data anak wajib diisi minimal 1 anak.');
        }

        $this->db->begin_transaction();
        try {
            // simpan data pengajuan
            $sqlPengajuan = "INSERT INTO tb_pengajuan_kia 
                (id_user, id_kecamatan, id_desa, no_kk, keterangan_permohonan, status, kategori_antrian, versi_aktif, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, 'diajukan', 'Antrian Pengajuan Baru', 1, NOW(), NOW())";
            
            $stmt = $this->db->prepare($sqlPengajuan);
            if (!$stmt) {
                throw new RuntimeException('Gagal menyiapkan query pengajuan: ' . $this->db->error);
            }

            $userId = (int)$dataPengajuan['id_user'];
            $idKecamatan = (int)$dataPengajuan['id_kecamatan'];
            $idDesa = (int)$dataPengajuan['id_desa'];
            $noKk = (string)$dataPengajuan['no_kk'];
            $keterangan = (string)$dataPengajuan['keterangan_permohonan'];

            $stmt->bind_param('iiiss', $userId, $idKecamatan, $idDesa, $noKk, $keterangan);
            if (!$stmt->execute()) {
                throw new RuntimeException('Gagal menyimpan pengajuan KIA: ' . $stmt->error);
            }
            $idPengajuan = (int)$this->db->insert_id;
            $stmt->close();

            // simpan data anak
            $anakIdMap = [];
            $sqlAnak = "INSERT INTO tb_anak_kia (id_pengajuan, nama_anak, nik_anak, tanggal_lahir, urutan_anak, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())";
            $stmtAnak = $this->db->prepare($sqlAnak);
            if (!$stmtAnak) {
                throw new RuntimeException('Gagal menyiapkan query data anak: ' . $this->db->error);
            }

            foreach ($dataAnakList as $index => $anak) {
                $urutan = $index + 1;
                $namaAnak = (string)$anak['nama_anak'];
                $nikAnak = (string)$anak['nik_anak'];
                $tglLahir = (string)$anak['tanggal_lahir'];

                $stmtAnak->bind_param('isssi', $idPengajuan, $namaAnak, $nikAnak, $tglLahir, $urutan);
                if (!$stmtAnak->execute()) {
                    throw new RuntimeException('Gagal menyimpan data anak: ' . $stmtAnak->error);
                }
                $anakIdMap[$index] = (int)$this->db->insert_id;
            }
            $stmtAnak->close();

            // 3. Simpan dokumen persyaratan
            $sqlDokumen = "INSERT INTO tb_dokumen_kia 
                (id_pengajuan, id_anak, jenis_dokumen, original_name, stored_name, file_path, mime_type, file_size, versi, status_dokumen, uploaded_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'valid', NOW())";
            $stmtDok = $this->db->prepare($sqlDokumen);
            if (!$stmtDok) {
                throw new RuntimeException('Gagal menyiapkan query dokumen: ' . $this->db->error);
            }

            foreach ($dokumenList as $dok) {
                $idAnak = isset($dok['anak_index']) && isset($anakIdMap[$dok['anak_index']])
                    ? $anakIdMap[$dok['anak_index']]
                    : ($dok['id_anak'] ?? null);

                $jenisDok = (string)$dok['jenis_dokumen'];
                $origName = (string)$dok['original_name'];
                $storedName = (string)$dok['stored_name'];
                $filePath = (string)$dok['file_path'];
                $mimeType = (string)$dok['mime_type'];
                $fileSize = (int)$dok['file_size'];

                $stmtDok->bind_param('iisssssi', $idPengajuan, $idAnak, $jenisDok, $origName, $storedName, $filePath, $mimeType, $fileSize);
                if (!$stmtDok->execute()) {
                    throw new RuntimeException('Gagal menyimpan dokumen KIA: ' . $stmtDok->error);
                }
            }
            $stmtDok->close();

            // 4. Catat riwayat status / milestone ke tb_permohonan_status_history
            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $idPengajuan,
                status: 'diajukan',
                alasan: '',
                actorId: (int)($actor['id'] ?? $userId),
                actorRole: (string)($actor['role'] ?? 'user'),
                eventType: 'created',
                meta: ['versi' => 1, 'kategori_antrian' => 'Antrian Pengajuan Baru']
            );

            // 5. Kirim notifikasi internal ke pemohon
            $this->kirimNotifikasi(
                userId: $userId,
                judul: 'Pengajuan KIA Berhasil Diajukan',
                pesan: "Permohonan KIA nomor KK {$noKk} telah berhasil diajukan dan masuk ke Antrian Pengajuan Baru.",
                tipe: 'info',
                permohonanId: $idPengajuan,
                targetUrl: "user/status_permohonan.php"
            );

            // 6. Kirim notifikasi ke operator kecamatan terkait
            $this->notifyOperatorsByKecamatan(
                idKecamatan: $idKecamatan,
                permohonanId: $idPengajuan,
                judul: 'Permohonan KIA Baru',
                pesan: "Permohonan KIA baru (No. KK: {$noKk}) telah diajukan dan menunggu verifikasi.",
                tipe: 'info'
            );

            $this->db->commit();

            // 7. Notifikasi WhatsApp KHUSUS Pemohon bahwa pengajuan KIA berhasil dikirim
            $this->sendWhatsAppToPemohon(
                userId: $userId,
                permohonanId: $idPengajuan,
                eventType: 'kia_diajukan',
                pesan: "Halo {NAMA}, permohonan Kartu Identitas Anak (KIA) Anda (#{$idPengajuan}) berhasil diajukan dan sedang menunggu pemeriksaan petugas. Silakan pantau perkembangan permohonan melalui akun SIPRIMA Anda."
            );

            return [
                'id_pengajuan' => $idPengajuan,
                'status' => 'diajukan',
                'kategori_antrian' => 'Antrian Pengajuan Baru',
                'versi_aktif' => 1
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // detail lengkap pengajuan KIA beserta data anak, dokumen, dan riwayat status
    public function getPengajuanById(int $id, ?int $userId = null): ?array
    {
        $sql = "SELECT p.*, 
                       u.nama AS nama_pemohon, u.nik AS nik_pemohon, u.email, u.no_hp,
                       k.nama_kecamatan, d.nama_desa
                FROM tb_pengajuan_kia p
                JOIN tb_users u ON p.id_user = u.id
                LEFT JOIN tb_kecamatan k ON p.id_kecamatan = k.id
                LEFT JOIN tb_desa d ON p.id_desa = d.id
                WHERE p.id = ?";
        
        $params = [$id];
        $types = 'i';

        if ($userId !== null) {
            $sql .= " AND p.id_user = ?";
            $params[] = $userId;
            $types .= 'i';
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan query detail: ' . $this->db->error);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $pengajuan = $result->fetch_assoc();
        $stmt->close();

        if (!$pengajuan) {
            return null;
        }

        // Ambil data anak
        $stmtAnak = $this->db->prepare("SELECT * FROM tb_anak_kia WHERE id_pengajuan = ? ORDER BY urutan_anak ASC, id ASC");
        $stmtAnak->bind_param('i', $id);
        $stmtAnak->execute();
        $anakList = $stmtAnak->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtAnak->close();

        // Hitung umur anak secara dinamis berdasarkan tanggal saat data ditampilkan
        $todayObj = new \DateTime('today');
        foreach ($anakList as &$ak) {
            $tglStr = trim((string)($ak['tanggal_lahir'] ?? ''));
            $ak['umur_tahun'] = 0;
            $ak['umur_bulan'] = 0;
            $ak['umur_teks'] = '-';
            if ($tglStr !== '') {
                try {
                    $tglObj = new \DateTime($tglStr);
                    if ($tglObj <= $todayObj) {
                        $diff = $tglObj->diff($todayObj);
                        $ak['umur_tahun'] = $diff->y;
                        $ak['umur_bulan'] = $diff->m;
                        if ($diff->y > 0) {
                            $ak['umur_teks'] = $diff->y . ' Tahun' . ($diff->m > 0 ? ' ' . $diff->m . ' Bulan' : '');
                        } elseif ($diff->m > 0) {
                            $ak['umur_teks'] = $diff->m . ' Bulan' . ($diff->d > 0 ? ' ' . $diff->d . ' Hari' : '');
                        } else {
                            $ak['umur_teks'] = max(0, $diff->d) . ' Hari';
                        }
                    } else {
                        $ak['umur_teks'] = 'Tanggal lahir di masa depan';
                    }
                } catch (\Throwable $e) {
                    $ak['umur_teks'] = '-';
                }
            }
        }
        unset($ak);

        // Ambil dokumen
        $stmtDok = $this->db->prepare("SELECT * FROM tb_dokumen_kia WHERE id_pengajuan = ? ORDER BY id ASC");
        $stmtDok->bind_param('i', $id);
        $stmtDok->execute();
        $dokumenList = $stmtDok->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtDok->close();

        // Status guard untuk pemohon: berkas PDF hasil KIA belum boleh terlihat jika belum berstatus 'selesai'
        if ($userId !== null && ($pengajuan['status'] ?? '') !== 'selesai') {
            $pengajuan['dokumen_kia_pdf'] = null;
            $dokumenList = array_values(array_filter($dokumenList, function ($d) {
                return ($d['jenis_dokumen'] ?? '') !== 'hasil_kia_pdf';
            }));
        }

        // Ambil riwayat milestone
        $stmtHist = $this->db->prepare("SELECT id, status, alasan, actor_id, actor_role, event_type, meta_json, created_at 
            FROM tb_permohonan_status_history 
            WHERE source_table = 'tb_pengajuan_kia' AND permohonan_id = ? 
            ORDER BY created_at ASC, id ASC");
        $stmtHist->bind_param('i', $id);
        $stmtHist->execute();
        $riwayatList = $stmtHist->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtHist->close();

        // Ambil laporan kesalahan jika ada
        $stmtLap = $this->db->prepare("SELECT l.*, u.nama AS nama_pelapor, op.nama AS nama_operator 
            FROM tb_laporan_kesalahan_kia l
            JOIN tb_users u ON l.id_user = u.id
            LEFT JOIN tb_users op ON l.id_operator = op.id
            WHERE l.id_pengajuan = ? 
            ORDER BY l.waktu_lapor ASC, l.id ASC");
        $stmtLap->bind_param('i', $id);
        $stmtLap->execute();
        $laporanList = $stmtLap->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtLap->close();

        // Ambil informasi cetak fisik KIA dan ketersediaan bahan cetak
        $cetakKia = $this->getCetakKiaByPengajuan($id);
        $pengaturanCetak = $this->getPengaturanCetakKia();

        return [
            'pengajuan' => $pengajuan,
            'anak' => $anakList,
            'dokumen' => $dokumenList,
            'riwayat' => $riwayatList,
            'laporan_kesalahan' => $laporanList,
            'cetak_kia' => $cetakKia,
            'pengaturan_cetak' => $pengaturanCetak
        ];
    }

    // daftar pengajuan KIA dengan filter dan paginasi
    public function getPengajuanList(array $filter = [], int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where = ['1=1'];
        $params = [];
        $types = '';

        if (!empty($filter['id_user'])) {
            $where[] = 'p.id_user = ?';
            $params[] = (int)$filter['id_user'];
            $types .= 'i';
        }

        if (!empty($filter['status'])) {
            $where[] = 'p.status = ?';
            $params[] = (string)$filter['status'];
            $types .= 's';
        }

        if (!empty($filter['kategori_antrian'])) {
            $where[] = 'p.kategori_antrian = ?';
            $params[] = (string)$filter['kategori_antrian'];
            $types .= 's';
        }

        if (!empty($filter['id_kecamatan'])) {
            $where[] = 'p.id_kecamatan = ?';
            $params[] = (int)$filter['id_kecamatan'];
            $types .= 'i';
        }

        if (!empty($filter['id_desa'])) {
            $where[] = 'p.id_desa = ?';
            $params[] = (int)$filter['id_desa'];
            $types .= 'i';
        }

        if (!empty($filter['q'])) {
            $where[] = '(p.no_kk LIKE ? OR u.nama LIKE ? OR u.nik LIKE ?)';
            $search = '%' . trim((string)$filter['q']) . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= 'sss';
        }

        $whereSql = implode(' AND ', $where);

        // Hitung total
        $countSql = "SELECT COUNT(*) as total 
                     FROM tb_pengajuan_kia p 
                     JOIN tb_users u ON p.id_user = u.id 
                     WHERE {$whereSql}";
        $stmtCount = $this->db->prepare($countSql);
        if ($types !== '') {
            $stmtCount->bind_param($types, ...$params);
        }
        $stmtCount->execute();
        $total = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtCount->close();

        // Ambil data items
        $dataSql = "SELECT p.*, u.nama AS nama_pemohon, u.nik AS nik_pemohon,
                           k.nama_kecamatan, d.nama_desa,
                           (SELECT COUNT(*) FROM tb_anak_kia a WHERE a.id_pengajuan = p.id) AS jumlah_anak
                    FROM tb_pengajuan_kia p
                    JOIN tb_users u ON p.id_user = u.id
                    LEFT JOIN tb_kecamatan k ON p.id_kecamatan = k.id
                    LEFT JOIN tb_desa d ON p.id_desa = d.id
                    WHERE {$whereSql}
                    ORDER BY p.updated_at DESC, p.id DESC
                    LIMIT ? OFFSET ?";
        
        $dataParams = array_merge($params, [$limit, $offset]);
        $dataTypes = $types . 'ii';

        $stmtData = $this->db->prepare($dataSql);
        $stmtData->bind_param($dataTypes, ...$dataParams);
        $stmtData->execute();
        $items = $stmtData->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtData->close();

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    // resubmission pengajuan KIA yang ditolak
    public function resubmitPengajuan(int $id, int $userId, array $updatedFields, array $newDokumenList, array $actor): array
    {
        $existing = $this->getPengajuanById($id, $userId);
        if (!$existing) {
            throw new RuntimeException('Pengajuan KIA tidak ditemukan.');
        }

        $pengajuan = $existing['pengajuan'];
        $currentStatus = strtolower((string)($pengajuan['status'] ?? ''));
        if ($currentStatus === 'selesai') {
            throw new InvalidArgumentException('Permohonan yang sudah Selesai tidak dapat diedit kembali.');
        }
        if (!in_array($currentStatus, ['diajukan', 'diproses', 'ditolak'], true)) {
            throw new InvalidArgumentException('Status permohonan tidak mengizinkan pengeditan.');
        }

        $this->db->begin_transaction();
        try {
            $versiBaru = (int)$pengajuan['versi_aktif'] + 1;
            $keterangan = $updatedFields['keterangan_permohonan'] ?? $pengajuan['keterangan_permohonan'];
            $noKk = $updatedFields['no_kk'] ?? $pengajuan['no_kk'];
            $idKec = (int)($updatedFields['id_kecamatan'] ?? $pengajuan['id_kecamatan']);
            $idDesa = (int)($updatedFields['id_desa'] ?? $pengajuan['id_desa']);

            $newStatus = ($currentStatus === 'ditolak') ? 'diajukan' : $currentStatus;
            $alasanPenolakan = ($currentStatus === 'ditolak') ? null : ($pengajuan['alasan_penolakan'] ?? null);

            $sqlUpdate = "UPDATE tb_pengajuan_kia 
                          SET status = ?,
                              kategori_antrian = 'Antrian Perbaikan/Koreksi',
                              versi_aktif = ?,
                              alasan_penolakan = ?,
                              resubmitted_at = NOW(),
                              keterangan_permohonan = ?,
                              no_kk = ?,
                              id_kecamatan = ?,
                              id_desa = ?,
                              updated_at = NOW()
                          WHERE id = ? AND id_user = ?";
            
            $stmtUpdate = $this->db->prepare($sqlUpdate);
            if (!$stmtUpdate) {
                throw new RuntimeException('Gagal menyiapkan query resubmit: ' . $this->db->error);
            }
            $stmtUpdate->bind_param('sisssiiii', $newStatus, $versiBaru, $alasanPenolakan, $keterangan, $noKk, $idKec, $idDesa, $id, $userId);
            if (!$stmtUpdate->execute()) {
                throw new RuntimeException('Gagal memperbarui status resubmit: ' . $stmtUpdate->error);
            }
            $stmtUpdate->close();

            if (!empty($updatedFields['anak']) && is_array($updatedFields['anak'])) {
                foreach ($updatedFields['anak'] as $idx => $aData) {
                    $existingAnak = $existing['anak'][$idx] ?? null;
                    if ($existingAnak && !empty($existingAnak['id'])) {
                        $idAnakRow = (int)$existingAnak['id'];
                        $nikA = trim((string)($aData['nik_anak'] ?? $existingAnak['nik_anak']));
                        $namaA = trim((string)($aData['nama_anak'] ?? $existingAnak['nama_anak']));
                        $tglA = trim((string)($aData['tanggal_lahir'] ?? $existingAnak['tanggal_lahir']));
                        $stmtUpdAnak = $this->db->prepare("UPDATE tb_anak_kia SET nik_anak = ?, nama_anak = ?, tanggal_lahir = ? WHERE id = ? AND id_pengajuan = ?");
                        if ($stmtUpdAnak) {
                            $stmtUpdAnak->bind_param('sssii', $nikA, $namaA, $tglA, $idAnakRow, $id);
                            $stmtUpdAnak->execute();
                            $stmtUpdAnak->close();
                        }
                    }
                }
            }

            // Simpan dokumen baru hasil perbaikan (versi bertambah) dan tandai dokumen lama pada slot yang sama sebagai superseded
            if (!empty($newDokumenList)) {
                $sqlDokumen = "INSERT INTO tb_dokumen_kia 
                    (id_pengajuan, id_anak, jenis_dokumen, original_name, stored_name, file_path, mime_type, file_size, versi, status_dokumen, uploaded_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'valid', NOW())";
                $stmtDok = $this->db->prepare($sqlDokumen);
                if (!$stmtDok) {
                    throw new RuntimeException('Gagal menyiapkan query dokumen perbaikan: ' . $this->db->error);
                }

                foreach ($newDokumenList as $dok) {
                    $idAnak = $dok['id_anak'] ?? null;
                    $jenisDok = (string)$dok['jenis_dokumen'];
                    $origName = (string)$dok['original_name'];
                    $storedName = (string)$dok['stored_name'];
                    $filePath = (string)$dok['file_path'];
                    $mimeType = (string)$dok['mime_type'];
                    $fileSize = (int)$dok['file_size'];

                    if ($jenisDok !== 'dokumen_tambahan') {
                        if ($idAnak !== null) {
                            $stmtSup = $this->db->prepare("UPDATE tb_dokumen_kia SET status_dokumen = 'superseded' WHERE id_pengajuan = ? AND jenis_dokumen = ? AND id_anak = ? AND status_dokumen = 'valid'");
                            if ($stmtSup) {
                                $stmtSup->bind_param('isi', $id, $jenisDok, $idAnak);
                                $stmtSup->execute();
                                $stmtSup->close();
                            }
                        } else {
                            $stmtSup = $this->db->prepare("UPDATE tb_dokumen_kia SET status_dokumen = 'superseded' WHERE id_pengajuan = ? AND jenis_dokumen = ? AND id_anak IS NULL AND status_dokumen = 'valid'");
                            if ($stmtSup) {
                                $stmtSup->bind_param('is', $id, $jenisDok);
                                $stmtSup->execute();
                                $stmtSup->close();
                            }
                        }
                    }

                    $stmtDok->bind_param('iisssssii', $id, $idAnak, $jenisDok, $origName, $storedName, $filePath, $mimeType, $fileSize, $versiBaru);
                    if (!$stmtDok->execute()) {
                        throw new RuntimeException('Gagal menyimpan dokumen perbaikan: ' . $stmtDok->error);
                    }
                }
                $stmtDok->close();
            }

            // Catat milestone resubmitted
            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $id,
                status: $newStatus,
                alasan: '',
                actorId: (int)($actor['id'] ?? $userId),
                actorRole: (string)($actor['role'] ?? 'user'),
                eventType: 'resubmitted',
                meta: ['versi' => $versiBaru, 'kategori_antrian' => 'Antrian Perbaikan/Koreksi', 'status_sebelumnya' => $currentStatus]
            );

            // Kirim notifikasi internal ke pemohon
            $this->kirimNotifikasi(
                userId: $userId,
                judul: 'Pengajuan Perbaikan KIA Dikirim',
                pesan: "Perbaikan pengajuan KIA (versi {$versiBaru}) telah diajukan kembali ke Antrian Perbaikan/Koreksi.",
                tipe: 'info',
                permohonanId: $id,
                targetUrl: "user/status_permohonan.php"
            );

            // Kirim notifikasi ke operator kecamatan terkait
            $this->notifyOperatorsByKecamatan(
                idKecamatan: $idKec,
                permohonanId: $id,
                judul: 'Pengajuan Ulang Perbaikan KIA',
                pesan: "Pemohon telah mengirim perbaikan berkas KIA (versi {$versiBaru}, No. KK: {$noKk}).",
                tipe: 'info'
            );

            $this->db->commit();

            // Notifikasi WhatsApp KHUSUS Pemohon bahwa perbaikan pengajuan KIA berhasil dikirim ulang
            $this->sendWhatsAppToPemohon(
                userId: $userId,
                permohonanId: $id,
                eventType: 'kia_diajukan_ulang_v' . $versiBaru,
                pesan: "Halo {NAMA}, perbaikan pengajuan Kartu Identitas Anak (KIA) Anda (#{$id}) berhasil dikirim ulang dan masuk antrean verifikasi revisi. Silakan pantau status permohonan melalui SIPRIMA."
            );

            return [
                'id_pengajuan' => $id,
                'status' => 'diajukan',
                'kategori_antrian' => 'Antrian Perbaikan/Koreksi',
                'versi_aktif' => $versiBaru
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // update status pengajuan oleh operator/petugas (Diproses, Tolak dengan alasan, atau Selesai)
    
    // mengambil nama dan nomor WhatsApp pemohon dari tb_users
    public function getUserPhoneAndName(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT nama, no_hp FROM tb_users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return ['nama' => 'Pemohon', 'no_hp' => ''];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $nama = trim((string)($row['nama'] ?? ''));
        return [
            'nama' => $nama !== '' ? $nama : 'Pemohon',
            'no_hp' => trim((string)($row['no_hp'] ?? ''))
        ];
    }

    /**
     * Helper terpusat untuk mengirim notifikasi WhatsApp KHUSUS Pemohon (non-blocking)
     */
    private function sendWhatsAppToPemohon(int $userId, int $permohonanId, string $eventType, string $pesan): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $userInfo = $this->getUserPhoneAndName($userId);
            $namaPemohon = trim((string)($userInfo['nama'] ?? '')) !== '' ? trim((string)$userInfo['nama']) : 'Pemohon';
            $finalPesan = str_replace('{NAMA}', $namaPemohon, $pesan);
            require_once __DIR__ . '/../Services/WhatsAppService.php';
            $waService = new \Siprima\Services\WhatsAppService($this->db);
            $waService->kirimNotifikasi(
                $userId,
                (string)($userInfo['no_hp'] ?? ''),
                $permohonanId,
                $eventType,
                $finalPesan,
                'tb_pengajuan_kia',
                [
                    'nama' => $namaPemohon,
                    'permohonan_id' => $permohonanId,
                ]
            );
        } catch (Throwable $eWa) {
            error_log("[WA_NOTIF] Gagal kirim WA ({$eventType}) pengajuan #{$permohonanId}: " . $eWa->getMessage());
        }
    }

    // mengambil daftar nama anak dalam permohonan KIA
    public function getNamaAnakString(int $idPengajuan): string
    {
        $stmt = $this->db->prepare("SELECT GROUP_CONCAT(nama_anak SEPARATOR ', ') as daftar_anak FROM tb_anak_kia WHERE id_pengajuan = ?");
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $idPengajuan);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return trim((string)($row['daftar_anak'] ?? ''));
    }

    public function updateStatus(int $id, string $newStatus, string $alasan, array $actor, bool $requirePdfForSelesai = false): array
    {
        $allowedStatuses = ['diajukan', 'diproses', 'ditolak', 'selesai'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            throw new InvalidArgumentException('Status tidak valid: ' . $newStatus);
        }

        if ($newStatus === 'ditolak' && trim($alasan) === '') {
            throw new InvalidArgumentException('Alasan penolakan wajib diisi jika status Ditolak.');
        }

        $stmtCekRow = $this->db->prepare("SELECT id, id_user, no_kk, versi_aktif, dokumen_kia_pdf FROM tb_pengajuan_kia WHERE id = ?");
        $stmtCekRow->bind_param('i', $id);
        $stmtCekRow->execute();
        $ownerRow = $stmtCekRow->get_result()->fetch_assoc();
        $stmtCekRow->close();

        if (!$ownerRow) {
            throw new RuntimeException("Pengajuan KIA #{$id} tidak ditemukan.");
        }

        if ($newStatus === 'selesai') {
            $hasPdf = !empty(trim((string)($ownerRow['dokumen_kia_pdf'] ?? '')));
            if (!$hasPdf) {
                $stmtCekPdf = $this->db->prepare("SELECT id, file_path FROM tb_dokumen_kia WHERE id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf' AND status_dokumen = 'valid' LIMIT 1");
                $stmtCekPdf->bind_param('i', $id);
                $stmtCekPdf->execute();
                $pdfRow = $stmtCekPdf->get_result()->fetch_assoc();
                $stmtCekPdf->close();
                if ($pdfRow) {
                    $hasPdf = true;
                    if (!empty($pdfRow['file_path'])) {
                        $fSync = (string)$pdfRow['file_path'];
                        $stmtSync = $this->db->prepare("UPDATE tb_pengajuan_kia SET dokumen_kia_pdf = ?, updated_at = NOW() WHERE id = ?");
                        $stmtSync->bind_param('si', $fSync, $id);
                        $stmtSync->execute();
                        $stmtSync->close();
                    }
                }
            }
            if (!$hasPdf) {
                throw new InvalidArgumentException('Dokumen hasil PDF KIA wajib diunggah sebelum menyelesaikan permohonan.');
            }
        }

        $this->db->begin_transaction();
        try {
            $alasanVal = ($newStatus === 'ditolak') ? trim($alasan) : null;
            $sqlUpdate = "UPDATE tb_pengajuan_kia 
                          SET status = ?, 
                              alasan_penolakan = ?,
                              updated_at = NOW() 
                          WHERE id = ?";
            
            $stmt = $this->db->prepare($sqlUpdate);
            if (!$stmt) {
                throw new RuntimeException('Gagal menyiapkan query update status: ' . $this->db->error);
            }
            $stmt->bind_param('ssi', $newStatus, $alasanVal, $id);
            if (!$stmt->execute()) {
                throw new RuntimeException('Gagal memperbarui status: ' . $stmt->error);
            }
            $stmt->close();

            $eventType = match ($newStatus) {
                'diproses' => 'processing',
                'ditolak' => 'rejected',
                'selesai' => 'completed',
                default => 'status_updated'
            };

            $currVersi = (int)($ownerRow['versi_aktif'] ?? 1);
            $historyNote = ($newStatus === 'ditolak') ? ($alasanVal ?? '') : trim($alasan);

            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $id,
                status: $newStatus,
                alasan: $historyNote,
                actorId: (int)($actor['id'] ?? 0),
                actorRole: (string)($actor['role'] ?? 'operator'),
                eventType: $eventType,
                meta: ['versi' => $currVersi]
            );

            $pendingWaNotifications = [];
            if (!empty($ownerRow['id_user'])) {
                $ownerId = (int)$ownerRow['id_user'];
                if ($newStatus === 'diproses') {
                    $this->kirimNotifikasi(
                        userId: $ownerId,
                        judul: 'Pengajuan KIA Sedang Diproses',
                        pesan: "Permohonan KIA Anda (No. KK: {$ownerRow['no_kk']}) sedang dalam proses verifikasi oleh petugas.",
                        tipe: 'info',
                        permohonanId: $id,
                        targetUrl: "user/status_permohonan.php"
                    );
                } elseif ($newStatus === 'ditolak') {
                    $this->kirimNotifikasi(
                        userId: $ownerId,
                        judul: 'Pengajuan KIA Ditolak',
                        pesan: "Pengajuan KIA Anda (No. KK: {$ownerRow['no_kk']}) ditolak oleh petugas dengan catatan: " . ($alasanVal ?? ''),
                        tipe: 'warning',
                        permohonanId: $id,
                        targetUrl: "user/status_permohonan.php"
                    );

                    // Notifikasi WhatsApp KHUSUS Pemohon (dikirim setelah commit transaksi DB)
                    $pendingWaNotifications[] = [
                        'userId' => $ownerId,
                        'permohonanId' => $id,
                        'eventType' => 'kia_ditolak_v' . $currVersi,
                        'pesan' => "Halo {NAMA}, permohonan KIA Anda (#{$id}) memerlukan perbaikan data atau dokumen. Silakan masuk ke SIPRIMA untuk membaca catatan petugas dan mengirimkan perbaikan.",
                    ];
                } elseif ($newStatus === 'selesai') {
                    $this->kirimNotifikasi(
                        userId: $ownerId,
                        judul: 'Pengajuan KIA Selesai',
                        pesan: "Selamat, permohonan KIA Anda (No. KK: {$ownerRow['no_kk']}) telah selesai diproses oleh petugas.",
                        tipe: 'success',
                        permohonanId: $id,
                        targetUrl: "user/status_permohonan.php"
                    );

                    // Notifikasi WhatsApp KHUSUS Pemohon (dikirim setelah commit transaksi DB)
                    $pendingWaNotifications[] = [
                        'userId' => $ownerId,
                        'permohonanId' => $id,
                        'eventType' => 'kia_selesai',
                        'pesan' => "Halo {NAMA}, permohonan Kartu Identitas Anak (KIA) Anda (#{$id}) telah selesai diproses. Silakan masuk ke SIPRIMA untuk informasi lebih lanjut dan mengakses dokumen Anda.",
                    ];

                    $pdfInfoNow = $this->getHasilPdfStatusByPengajuan($id);
                    if (!empty($pdfInfoNow['uploaded_count'])) {
                        $pendingWaNotifications[] = [
                            'userId' => $ownerId,
                            'permohonanId' => $id,
                            'eventType' => 'kia_pdf_tersedia',
                            'pesan' => "Halo {NAMA}, dokumen PDF KIA pada permohonan #{$id} sudah tersedia dan dapat dilihat atau diunduh melalui akun SIPRIMA Anda.",
                        ];
                    }
                }
            }

            $this->db->commit();

            // Kirim notifikasi WhatsApp secara non-blocking setelah transaksi DB berhasil commit
            foreach ($pendingWaNotifications as $waJob) {
                $this->sendWhatsAppToPemohon(
                    userId: $waJob['userId'],
                    permohonanId: $waJob['permohonanId'],
                    eventType: $waJob['eventType'],
                    pesan: $waJob['pesan']
                );
            }

            return [
                'id_pengajuan' => $id,
                'status' => $newStatus,
                'alasan' => $alasanVal
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // record dokumen tunggal ke tb_dokumen_kia
    public function addDokumen(int $idPengajuan, ?int $idAnak, string $jenisDokumen, array $fileData, int $versi = 1): array
    {
        $sql = "INSERT INTO tb_dokumen_kia 
            (id_pengajuan, id_anak, jenis_dokumen, original_name, stored_name, file_path, mime_type, file_size, versi, status_dokumen, uploaded_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'valid', NOW())";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan query dokumen: ' . $this->db->error);
        }

        $origName = (string)$fileData['original_name'];
        $storedName = (string)$fileData['stored_name'];
        $filePath = (string)$fileData['file_path'];
        $mimeType = (string)$fileData['mime_type'];
        $fileSize = (int)$fileData['file_size'];

        $stmt->bind_param('iisssssii', $idPengajuan, $idAnak, $jenisDokumen, $origName, $storedName, $filePath, $mimeType, $fileSize, $versi);
        if (!$stmt->execute()) {
            throw new RuntimeException('Gagal menyimpan record dokumen: ' . $stmt->error);
        }
        $docId = (int)$this->db->insert_id;
        $stmt->close();

        return array_merge([
            'id' => $docId,
            'id_pengajuan' => $idPengajuan,
            'id_anak' => $idAnak,
            'jenis_dokumen' => $jenisDokumen,
            'versi' => $versi,
            'status_dokumen' => 'valid'
        ], $fileData);
    }

    // daftar dokumen pada pengajuan tertentu
    public function getDokumenList(int $idPengajuan, ?string $jenisDokumen = null): array
    {
        $sql = "SELECT * FROM tb_dokumen_kia WHERE id_pengajuan = ?";
        $params = [$idPengajuan];
        $types = 'i';

        if ($jenisDokumen !== null) {
            $sql .= " AND jenis_dokumen = ?";
            $params[] = $jenisDokumen;
            $types .= 's';
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan query getDokumenList: ' . $this->db->error);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $result;
    }

    // helper mencatat status ke tb_permohonan_status_history
    private function logStatusHistory(string $sourceTable, int $permohonanId, string $status, string $alasan, int $actorId, string $actorRole, string $eventType, ?array $meta = null): void
    {
        $metaJson = ($meta !== null) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
        $sql = "INSERT INTO tb_permohonan_status_history 
                (source_table, permohonan_id, status, alasan, actor_id, actor_role, event_type, meta_json, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sississs', $sourceTable, $permohonanId, $status, $alasan, $actorId, $actorRole, $eventType, $metaJson);
            $stmt->execute();
            $stmt->close();
        }
    }

    // helper mengirim notifikasi internal ke tb_notifikasi
    public function kirimNotifikasi(int $userId, string $judul, string $pesan, string $tipe = 'info', ?int $permohonanId = null, ?string $targetUrl = null): bool
    {
        $allowedTypes = ['info', 'success', 'warning', 'danger'];
        if (!in_array($tipe, $allowedTypes, true)) {
            $tipe = 'info';
        }

        $sourceTable = 'tb_pengajuan_kia';
        $sql = "INSERT INTO tb_notifikasi 
                (user_id, judul, pesan, tipe, target_url, source_table, permohonan_id, dibaca, dilihat, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('isssssi', $userId, $judul, $pesan, $tipe, $targetUrl, $sourceTable, $permohonanId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    // helper mengirim notifikasi ke operator yang bertugas di kecamatan terkait
    private function notifyOperatorsByKecamatan(int $idKecamatan, int $permohonanId, string $judul, string $pesan, string $tipe = 'info'): void
    {
        if ($idKecamatan <= 0) {
            return;
        }
        $targetUrl = "user/proses_pengajuan.php?table=tb_pengajuan_kia&id={$permohonanId}";
        $sql = "SELECT DISTINCT u.id
                FROM tb_operator_kecamatan ok
                INNER JOIN tb_users u ON u.id = ok.operator_id
                WHERE ok.kecamatan_id = ?
                  AND u.role = 'operator'
                  AND COALESCE(u.is_active, 1) = 1";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $idKecamatan);
        $stmt->execute();
        $res = $stmt->get_result();
        $operatorIds = [];
        while ($row = $res->fetch_assoc()) {
            $opId = (int)($row['id'] ?? 0);
            if ($opId > 0) {
                $operatorIds[$opId] = $opId;
            }
        }
        $stmt->close();

        foreach ($operatorIds as $opId) {
            $this->kirimNotifikasi($opId, $judul, $pesan, $tipe, $permohonanId, $targetUrl);
        }
    }

    // mengoreksi data anak (nama, NIK, tanggal lahir)
    public function koreksiDataAnak(int $idPengajuan, int $idAnak, array $dataAnak, array $actor): array
    {
        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            throw new InvalidArgumentException('Hanya operator/admin yang berhak melakukan koreksi data anak.');
        }

        // Pastikan anak dan pengajuan valid
        $stmtCek = $this->db->prepare("SELECT * FROM tb_anak_kia WHERE id = ? AND id_pengajuan = ?");
        $stmtCek->bind_param('ii', $idAnak, $idPengajuan);
        $stmtCek->execute();
        $oldData = $stmtCek->get_result()->fetch_assoc();
        $stmtCek->close();

        if (!$oldData) {
            throw new RuntimeException("Data anak dengan ID {$idAnak} tidak ditemukan pada pengajuan KIA #{$idPengajuan}.");
        }

        $namaAnak = trim((string)($dataAnak['nama_anak'] ?? $oldData['nama_anak']));
        $nikAnak = trim((string)($dataAnak['nik_anak'] ?? $oldData['nik_anak']));
        $tglLahir = trim((string)($dataAnak['tanggal_lahir'] ?? $oldData['tanggal_lahir']));

        if ($namaAnak === '') {
            throw new InvalidArgumentException('Nama anak tidak boleh kosong.');
        }
        if (!preg_match('/^[0-9]{16}$/D', $nikAnak)) {
            throw new InvalidArgumentException('NIK anak harus 16 digit angka.');
        }

        $this->db->begin_transaction();
        try {
            $stmtUp = $this->db->prepare("UPDATE tb_anak_kia SET nama_anak = ?, nik_anak = ?, tanggal_lahir = ? WHERE id = ? AND id_pengajuan = ?");
            $stmtUp->bind_param('sssii', $namaAnak, $nikAnak, $tglLahir, $idAnak, $idPengajuan);
            if (!$stmtUp->execute()) {
                throw new RuntimeException("Gagal mengupdate data anak: " . $stmtUp->error);
            }
            $stmtUp->close();

            // Ambil status pengajuan saat ini
            $stmtP = $this->db->prepare("SELECT status, id_user FROM tb_pengajuan_kia WHERE id = ?");
            $stmtP->bind_param('i', $idPengajuan);
            $stmtP->execute();
            $pRow = $stmtP->get_result()->fetch_assoc();
            $stmtP->close();

            $statusCurr = $pRow['status'] ?? 'diajukan';
            $idUserPemohon = (int)($pRow['id_user'] ?? 0);

            // Catat ke riwayat milestone
            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $idPengajuan,
                status: $statusCurr,
                alasan: 'Koreksi data anak oleh petugas',
                actorId: (int)($actor['id'] ?? 0),
                actorRole: (string)($actor['role'] ?? 'operator'),
                eventType: 'koreksi_data_anak',
                meta: [
                    'id_anak' => $idAnak,
                    'sebelum' => [
                        'nama_anak' => $oldData['nama_anak'],
                        'nik_anak' => $oldData['nik_anak'],
                        'tanggal_lahir' => $oldData['tanggal_lahir']
                    ],
                    'sesudah' => [
                        'nama_anak' => $namaAnak,
                        'nik_anak' => $nikAnak,
                        'tanggal_lahir' => $tglLahir
                    ]
                ]
            );

            // Notifikasi ke pemohon
            if ($idUserPemohon > 0) {
                $this->kirimNotifikasi(
                    userId: $idUserPemohon,
                    judul: 'Data Anak Pengajuan KIA Dikoreksi',
                    pesan: "Petugas telah memperbarui data anak ({$namaAnak}) pada pengajuan KIA Anda.",
                    tipe: 'info',
                    permohonanId: $idPengajuan,
                    targetUrl: "user/kia.php?action=detail&id={$idPengajuan}"
                );
            }

            $this->db->commit();

            return [
                'id' => $idAnak,
                'id_pengajuan' => $idPengajuan,
                'nama_anak' => $namaAnak,
                'nik_anak' => $nikAnak,
                'tanggal_lahir' => $tglLahir
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // mengoreksi / menukar pemetaan dokumen terhadap anak (misal pasfoto tertukar antar anak)
    public function koreksiDokumenMapping(int $idPengajuan, array $mappings, array $actor): array
    {
        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            throw new InvalidArgumentException('Hanya operator/admin yang berhak melakukan koreksi pemetaan dokumen.');
        }

        if (empty($mappings)) {
            throw new InvalidArgumentException('Daftar mapping dokumen tidak boleh kosong.');
        }

        // Ambil data pengajuan dan anak yang sah untuk pengajuan ini
        $stmtP = $this->db->prepare("SELECT status, id_user FROM tb_pengajuan_kia WHERE id = ?");
        $stmtP->bind_param('i', $idPengajuan);
        $stmtP->execute();
        $pRow = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();

        if (!$pRow) {
            throw new RuntimeException("Pengajuan KIA #{$idPengajuan} tidak ditemukan.");
        }

        $statusCurr = $pRow['status'] ?? 'diajukan';
        $idUserPemohon = (int)($pRow['id_user'] ?? 0);

        // Ambil daftar id_anak yang valid untuk pengajuan ini
        $stmtAnakList = $this->db->prepare("SELECT id FROM tb_anak_kia WHERE id_pengajuan = ?");
        $stmtAnakList->bind_param('i', $idPengajuan);
        $stmtAnakList->execute();
        $validAnakIds = array_column($stmtAnakList->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stmtAnakList->close();

        $this->db->begin_transaction();
        try {
            $updatedList = [];
            $historyMeta = [];

            $stmtDok = $this->db->prepare("SELECT id, id_anak, jenis_dokumen, original_name FROM tb_dokumen_kia WHERE id = ? AND id_pengajuan = ?");
            $stmtUpdateDok = $this->db->prepare("UPDATE tb_dokumen_kia SET id_anak = ? WHERE id = ? AND id_pengajuan = ?");

            foreach ($mappings as $map) {
                $idDokumen = (int)($map['id_dokumen'] ?? 0);
                $targetAnak = isset($map['id_anak']) && $map['id_anak'] !== null ? (int)$map['id_anak'] : null;

                if ($targetAnak !== null && !in_array($targetAnak, $validAnakIds, true)) {
                    throw new InvalidArgumentException("ID Anak {$targetAnak} tidak terdaftar pada pengajuan KIA #{$idPengajuan}.");
                }

                $stmtDok->bind_param('ii', $idDokumen, $idPengajuan);
                $stmtDok->execute();
                $dokRow = $stmtDok->get_result()->fetch_assoc();

                if (!$dokRow) {
                    throw new RuntimeException("Dokumen ID {$idDokumen} tidak ditemukan pada pengajuan KIA #{$idPengajuan}.");
                }

                $oldAnak = $dokRow['id_anak'] !== null ? (int)$dokRow['id_anak'] : null;

                if ($targetAnak !== null) {
                    $stmtUpdateDok->bind_param('iii', $targetAnak, $idDokumen, $idPengajuan);
                } else {
                    $nullVal = null;
                    $stmtUpdateDok->bind_param('sii', $nullVal, $idDokumen, $idPengajuan);
                }
                if (!$stmtUpdateDok->execute()) {
                    throw new RuntimeException("Gagal mengupdate mapping dokumen #{$idDokumen}: " . $stmtUpdateDok->error);
                }

                $updatedList[] = [
                    'id_dokumen' => $idDokumen,
                    'jenis_dokumen' => $dokRow['jenis_dokumen'],
                    'id_anak_sebelum' => $oldAnak,
                    'id_anak_sesudah' => $targetAnak
                ];

                $historyMeta[] = [
                    'id_dokumen' => $idDokumen,
                    'jenis_dokumen' => $dokRow['jenis_dokumen'],
                    'old_id_anak' => $oldAnak,
                    'new_id_anak' => $targetAnak
                ];
            }

            $stmtDok->close();
            $stmtUpdateDok->close();

            // Catat riwayat audit
            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $idPengajuan,
                status: $statusCurr,
                alasan: 'Koreksi pemetaan dokumen anak oleh petugas',
                actorId: (int)($actor['id'] ?? 0),
                actorRole: (string)($actor['role'] ?? 'operator'),
                eventType: 'koreksi_mapping_dokumen',
                meta: ['perubahan' => $historyMeta]
            );

            // Notifikasi ke pemohon
            if ($idUserPemohon > 0) {
                $this->kirimNotifikasi(
                    userId: $idUserPemohon,
                    judul: 'Dokumen Pengajuan KIA Disesuaikan',
                    pesan: 'Petugas telah menyesuaikan pemetaan dokumen anak pada permohonan KIA Anda.',
                    tipe: 'info',
                    permohonanId: $idPengajuan,
                    targetUrl: "user/kia.php?action=detail&id={$idPengajuan}"
                );
            }

            $this->db->commit();

            return [
                'id_pengajuan' => $idPengajuan,
                'updated_count' => count($updatedList),
                'mappings' => $updatedList
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // melaporkan kesalahan pasca-selesai (terkait dengan anak & dokumen PDF spesifik)
    public function laporKesalahan(
        int $idPengajuan,
        int $userId,
        string $keterangan,
        ?int $idAnak = null,
        ?int $idDokumen = null,
        string $kategori = 'kesalahan_lainnya'
    ): array {
        $keterangan = trim($keterangan);
        if ($keterangan === '') {
            throw new InvalidArgumentException('Keterangan kesalahan wajib diisi.');
        }

        $allowedKategori = [
            'foto_anak_tertukar',
            'identitas_anak_tertukar',
            'data_tidak_sesuai',
            'kesalahan_lainnya',
            'identitas_tidak_sesuai',
            'foto_tertukar',
            'dokumen_salah_anak',
            'kualitas_cetak',
        ];
        $kategori = trim($kategori);
        if (!in_array($kategori, $allowedKategori, true)) {
            $kategori = 'kesalahan_lainnya';
        }

        $pengajuanData = $this->getPengajuanById($idPengajuan, $userId);
        if (!$pengajuanData) {
            throw new RuntimeException('Pengajuan KIA tidak ditemukan atau Anda tidak memiliki akses.');
        }

        $pengajuan = $pengajuanData['pengajuan'];
        if ($pengajuan['status'] !== 'selesai') {
            throw new InvalidArgumentException('Lapor kesalahan hanya dapat dilakukan untuk pengajuan yang berstatus Selesai.');
        }

        $anakNama = null;
        if ($idAnak !== null && $idAnak > 0) {
            $stmtA = $this->db->prepare("SELECT id, nama_anak FROM tb_anak_kia WHERE id = ? AND id_pengajuan = ? LIMIT 1");
            $stmtA->bind_param('ii', $idAnak, $idPengajuan);
            $stmtA->execute();
            $rowA = $stmtA->get_result()->fetch_assoc();
            $stmtA->close();
            if (!$rowA) {
                throw new InvalidArgumentException('Data anak tidak sesuai dengan permohonan ini.');
            }
            $anakNama = (string)$rowA['nama_anak'];
        } else {
            $idAnak = null;
        }

        if ($idDokumen !== null && $idDokumen > 0) {
            $stmtD = $this->db->prepare("SELECT id, id_anak FROM tb_dokumen_kia WHERE id = ? AND id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf' LIMIT 1");
            $stmtD->bind_param('ii', $idDokumen, $idPengajuan);
            $stmtD->execute();
            $rowD = $stmtD->get_result()->fetch_assoc();
            $stmtD->close();
            if (!$rowD) {
                throw new InvalidArgumentException('Dokumen PDF KIA tidak ditemukan pada permohonan ini.');
            }
            if ($idAnak !== null && !empty($rowD['id_anak']) && (int)$rowD['id_anak'] !== $idAnak) {
                throw new InvalidArgumentException('Dokumen PDF KIA yang dipilih tidak sesuai dengan data anak.');
            }
            if ($idAnak === null && !empty($rowD['id_anak'])) {
                $idAnak = (int)$rowD['id_anak'];
            }
        } else {
            $idDokumen = null;
        }

        $anakFilterId = $idAnak ?? 0;
        $stmtDupLap = $this->db->prepare(
            "SELECT id FROM tb_laporan_kesalahan_kia
             WHERE id_pengajuan = ? AND id_user = ? AND COALESCE(id_anak, 0) = ? AND keterangan_kesalahan = ?
               AND waktu_lapor >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
             LIMIT 1"
        );
        if ($stmtDupLap) {
            $stmtDupLap->bind_param('iiis', $idPengajuan, $userId, $anakFilterId, $keterangan);
            $stmtDupLap->execute();
            $dupLapRow = $stmtDupLap->get_result()->fetch_assoc();
            $stmtDupLap->close();
            if ($dupLapRow) {
                throw new InvalidArgumentException('Laporan kesalahan yang sama baru saja dikirim. Silakan tunggu beberapa saat.');
            }
        }

        $userInfo = $this->getUserPhoneAndName($userId);
        $namaPelapor = (string)($userInfo['nama'] ?? 'Pemohon');

        $this->db->begin_transaction();
        try {
            $sql = "INSERT INTO tb_laporan_kesalahan_kia 
                (id_pengajuan, id_anak, id_dokumen, id_user, kategori_kesalahan, keterangan_kesalahan, status, waktu_lapor) 
                VALUES (?, ?, ?, ?, ?, ?, 'dilaporkan', NOW())";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Gagal menyiapkan query lapor kesalahan: ' . $this->db->error);
            }
            $stmt->bind_param('iiiiss', $idPengajuan, $idAnak, $idDokumen, $userId, $kategori, $keterangan);
            if (!$stmt->execute()) {
                throw new RuntimeException('Gagal menyimpan laporan kesalahan: ' . $stmt->error);
            }
            $laporanId = (int)$this->db->insert_id;
            $stmt->close();

            // Simpan pesan awal ke sistem percakapan laporan kesalahan
            $stmtMsg = $this->db->prepare(
                "INSERT INTO tb_laporan_kesalahan_kia_pesan (id_laporan, id_pengajuan, id_pengirim, role_pengirim, nama_pengirim, isi_pesan, created_at)
                 VALUES (?, ?, ?, 'pemohon', ?, ?, NOW())"
            );
            if ($stmtMsg) {
                $stmtMsg->bind_param('iiiss', $laporanId, $idPengajuan, $userId, $namaPelapor, $keterangan);
                $stmtMsg->execute();
                $stmtMsg->close();
            }

            // Catat riwayat audit
            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $idPengajuan,
                status: 'selesai',
                alasan: ($anakNama ? "[{$anakNama}] " : '') . $keterangan,
                actorId: $userId,
                actorRole: 'user',
                eventType: 'lapor_kesalahan',
                meta: ['id_laporan' => $laporanId, 'id_anak' => $idAnak, 'id_dokumen' => $idDokumen, 'kategori' => $kategori]
            );

            // Notifikasi ke pemohon bahwa laporan terkirim
            $this->kirimNotifikasi(
                userId: $userId,
                judul: 'Laporan Kesalahan KIA Terkirim',
                pesan: 'Laporan kesalahan Anda' . ($anakNama ? " untuk {$anakNama}" : '') . ' telah diterima dan sedang menunggu peninjauan oleh petugas.',
                tipe: 'info',
                permohonanId: $idPengajuan,
                targetUrl: "user/status_permohonan.php"
            );

            // Notifikasi internal ke operator kecamatan terkait
            $this->notifyOperatorsByKecamatan(
                idKecamatan: (int)($pengajuan['id_kecamatan'] ?? 0),
                permohonanId: $idPengajuan,
                judul: 'Laporan Kesalahan Pengajuan KIA',
                pesan: "Pemohon melaporkan kesalahan pada pengajuan KIA #{$idPengajuan}" . ($anakNama ? " ({$anakNama})" : '') . ": {$keterangan}",
                tipe: 'warning'
            );

            $this->db->commit();

            return [
                'id_laporan' => $laporanId,
                'id_pengajuan' => $idPengajuan,
                'id_anak' => $idAnak,
                'id_dokumen' => $idDokumen,
                'kategori_kesalahan' => $kategori,
                'keterangan_kesalahan' => $keterangan,
                'status' => 'dilaporkan',
                'waktu_lapor' => date('Y-m-d H:i:s')
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // daftar laporan kesalahan untuk suatu pengajuan beserta detail anak, dokumen, dan riwayat percakapan
    public function getLaporanKesalahan(int $idPengajuan, ?int $userId = null): array
    {
        $sql = "SELECT l.*, u.nama AS nama_pelapor, op.nama AS nama_operator,
                       a.nama_anak, d.original_name AS nama_file_dokumen, d.versi AS versi_dokumen,
                       d.file_path AS file_path_dokumen, d.status_dokumen AS status_file_dokumen
                FROM tb_laporan_kesalahan_kia l
                JOIN tb_users u ON l.id_user = u.id
                LEFT JOIN tb_users op ON l.id_operator = op.id
                LEFT JOIN tb_anak_kia a ON l.id_anak = a.id
                LEFT JOIN tb_dokumen_kia d ON l.id_dokumen = d.id
                WHERE l.id_pengajuan = ?";
        $params = [$idPengajuan];
        $types = 'i';

        if ($userId !== null) {
            $sql .= " AND l.id_user = ?";
            $params[] = $userId;
            $types .= 'i';
        }

        $sql .= " ORDER BY l.waktu_lapor DESC, l.id DESC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan query getLaporanKesalahan: ' . $this->db->error);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!empty($rows)) {
            $lapIds = array_map(fn($r) => (int)$r['id'], $rows);
            $idList = implode(',', $lapIds);
            $pesanMap = [];
            $resMsg = @$this->db->query("SELECT * FROM tb_laporan_kesalahan_kia_pesan WHERE id_laporan IN ({$idList}) ORDER BY id ASC");
            if ($resMsg) {
                while ($m = $resMsg->fetch_assoc()) {
                    $lid = (int)$m['id_laporan'];
                    $pesanMap[$lid][] = $m;
                }
            }

            $activePdfByAnak = [];
            $fallbackActivePdf = null;
            $stmtAct = $this->db->prepare("SELECT id, id_anak, original_name, file_path, versi, uploaded_at FROM tb_dokumen_kia WHERE id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf' AND status_dokumen = 'valid' ORDER BY id DESC");
            if ($stmtAct) {
                $stmtAct->bind_param('i', $idPengajuan);
                $stmtAct->execute();
                $resAct = $stmtAct->get_result();
                while ($ar = $resAct->fetch_assoc()) {
                    $aidAct = (int)($ar['id_anak'] ?? 0);
                    if ($aidAct > 0 && !isset($activePdfByAnak[$aidAct])) {
                        $activePdfByAnak[$aidAct] = $ar;
                    }
                    if ($fallbackActivePdf === null) {
                        $fallbackActivePdf = $ar;
                    }
                }
                $stmtAct->close();
            }

            foreach ($rows as &$r) {
                $r['pesan_list'] = $pesanMap[(int)$r['id']] ?? [];
                $aidLap = (int)($r['id_anak'] ?? 0);
                $r['active_pdf'] = $aidLap > 0
                    ? ($activePdfByAnak[$aidLap] ?? $fallbackActivePdf)
                    : $fallbackActivePdf;
            }
            unset($r);
        }

        return $rows;
    }

    // menambahkan pesan percakapan Pemohon-Operator dan opsional mengubah status laporan (khusus Operator/Admin)
    public function tambahPesanLaporanKesalahan(
        int $idLaporan,
        string $isiPesan,
        ?string $newStatus,
        array $actor
    ): array {
        $actorId = (int)($actor['id'] ?? 0);
        $actorRole = strtolower(trim((string)($actor['role'] ?? 'user')));
        $isStaff = in_array($actorRole, ['operator', 'admin', 'petugas_desa'], true);

        $stmtCek = $this->db->prepare(
            "SELECT l.*, p.id_user AS owner_id, p.id_kecamatan, a.nama_anak
             FROM tb_laporan_kesalahan_kia l
             JOIN tb_pengajuan_kia p ON l.id_pengajuan = p.id
             LEFT JOIN tb_anak_kia a ON l.id_anak = a.id
             WHERE l.id = ? LIMIT 1"
        );
        $stmtCek->bind_param('i', $idLaporan);
        $stmtCek->execute();
        $lap = $stmtCek->get_result()->fetch_assoc();
        $stmtCek->close();

        if (!$lap) {
            throw new RuntimeException("Laporan kesalahan ID #{$idLaporan} tidak ditemukan.");
        }

        $ownerId = (int)$lap['owner_id'];
        $idPengajuan = (int)$lap['id_pengajuan'];
        if (!$isStaff && $ownerId !== $actorId) {
            throw new InvalidArgumentException('Anda tidak memiliki akses ke laporan kesalahan ini.');
        }

        $isiPesan = trim($isiPesan);
        $allowedStatuses = ['dilaporkan', 'sedang_diperiksa', 'dalam_perbaikan', 'selesai', 'selesai_dikoreksi'];
        $statusToSave = null;
        if ($isStaff && $newStatus !== null && trim($newStatus) !== '') {
            $cand = strtolower(trim($newStatus));
            if (!in_array($cand, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Status laporan kesalahan tidak valid.');
            }
            $statusToSave = $cand;
        }

        if ($isiPesan === '' && $statusToSave === null) {
            throw new InvalidArgumentException('Isi pesan atau perubahan status wajib diisi.');
        }

        $namaPengirim = trim((string)($actor['nama'] ?? ''));
        if ($namaPengirim === '' && $actorId > 0) {
            $namaPengirim = $this->getUserPhoneAndName($actorId)['nama'];
        }
        $rolePesan = $isStaff ? ($actorRole === 'admin' ? 'admin' : 'operator') : 'pemohon';

        if ($isiPesan !== '' && ($statusToSave === null || $statusToSave === strtolower((string)($lap['status'] ?? '')))) {
            $stmtDupMsg = $this->db->prepare(
                "SELECT id FROM tb_laporan_kesalahan_kia_pesan
                 WHERE id_laporan = ? AND id_pengirim = ? AND isi_pesan = ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
                 LIMIT 1"
            );
            if ($stmtDupMsg) {
                $stmtDupMsg->bind_param('iis', $idLaporan, $actorId, $isiPesan);
                $stmtDupMsg->execute();
                $dupMsgRow = $stmtDupMsg->get_result()->fetch_assoc();
                $stmtDupMsg->close();
                if ($dupMsgRow) {
                    throw new InvalidArgumentException('Pesan yang sama baru saja dikirim.');
                }
            }
        }

        $this->db->begin_transaction();
        try {
            $pesanId = 0;
            if ($isiPesan !== '') {
                $stmtMsg = $this->db->prepare(
                    "INSERT INTO tb_laporan_kesalahan_kia_pesan (id_laporan, id_pengajuan, id_pengirim, role_pengirim, nama_pengirim, isi_pesan, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())"
                );
                if (!$stmtMsg) {
                    throw new RuntimeException('Gagal menyiapkan query pesan laporan: ' . $this->db->error);
                }
                $stmtMsg->bind_param('iiisss', $idLaporan, $idPengajuan, $actorId, $rolePesan, $namaPengirim, $isiPesan);
                $stmtMsg->execute();
                $pesanId = (int)$this->db->insert_id;
                $stmtMsg->close();
            }

            if ($statusToSave !== null) {
                $isDone = in_array($statusToSave, ['selesai', 'selesai_dikoreksi'], true);
                if ($isDone) {
                    $stmtUp = $this->db->prepare("UPDATE tb_laporan_kesalahan_kia SET status = ?, id_operator = ?, waktu_koreksi = NOW() WHERE id = ?");
                } else {
                    $stmtUp = $this->db->prepare("UPDATE tb_laporan_kesalahan_kia SET status = ?, id_operator = ? WHERE id = ?");
                }
                $opVal = $actorId > 0 ? $actorId : null;
                $stmtUp->bind_param('sii', $statusToSave, $opVal, $idLaporan);
                $stmtUp->execute();
                $stmtUp->close();
            }

            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $idPengajuan,
                status: 'selesai',
                alasan: $isiPesan !== '' ? $isiPesan : ('Status laporan kesalahan diubah ke: ' . $statusToSave),
                actorId: $actorId,
                actorRole: $actorRole,
                eventType: $isStaff ? 'tanggapan_laporan_operator' : 'balasan_laporan_pemohon',
                meta: ['id_laporan' => $idLaporan, 'id_pesan' => $pesanId, 'status_laporan' => $statusToSave ?? $lap['status']]
            );

            if ($isStaff) {
                $stLbl = ucwords(str_replace('_', ' ', (string)($statusToSave ?? $lap['status'])));
                $anakTag = !empty($lap['nama_anak']) ? " ({$lap['nama_anak']})" : '';
                $this->kirimNotifikasi(
                    userId: $ownerId,
                    judul: 'Tanggapan Laporan Kesalahan KIA',
                    pesan: "Operator menanggapi laporan kesalahan KIA{$anakTag} [Status: {$stLbl}]" . ($isiPesan !== '' ? ": {$isiPesan}" : '.'),
                    tipe: in_array($statusToSave, ['selesai', 'selesai_dikoreksi'], true) ? 'success' : 'info',
                    permohonanId: $idPengajuan,
                    targetUrl: "user/status_permohonan.php"
                );
            } else {
                $this->notifyOperatorsByKecamatan(
                    idKecamatan: (int)($lap['id_kecamatan'] ?? 0),
                    permohonanId: $idPengajuan,
                    judul: 'Balasan Pemohon pada Laporan Kesalahan KIA',
                    pesan: "Pemohon membalas laporan kesalahan KIA #{$idPengajuan}: {$isiPesan}",
                    tipe: 'info'
                );
            }

            $this->db->commit();

            // Notifikasi WhatsApp KHUSUS Pemohon saat Operator menanggapi laporan kesalahan
            if ($isStaff && $ownerId > 0) {
                $evSuffix = $pesanId > 0 ? ('m' . $pesanId) : ('s_' . ($statusToSave ?? 'upd'));
                $this->sendWhatsAppToPemohon(
                    userId: $ownerId,
                    permohonanId: $idPengajuan,
                    eventType: "kia_balasan_laporan_{$idLaporan}_{$evSuffix}",
                    pesan: "Halo {NAMA}, Operator telah memberikan tanggapan atas laporan kesalahan dokumen KIA pada permohonan #{$idPengajuan}. Silakan masuk ke SIPRIMA untuk membaca balasan dan status penanganannya."
                );
            }

            return [
                'id_laporan' => $idLaporan,
                'id_pengajuan' => $idPengajuan,
                'id_pesan' => $pesanId,
                'status' => $statusToSave ?? $lap['status'],
                'isi_pesan' => $isiPesan,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // menandai laporan kesalahan telah selesai dikoreksi
    public function selesaikanLaporanKesalahan(int $idLaporan, string $catatan, array $actor): array
    {
        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            throw new InvalidArgumentException('Hanya operator/admin yang berhak menyelesaikan laporan kesalahan.');
        }

        $stmtCek = $this->db->prepare("SELECT l.*, p.id_user AS owner_id FROM tb_laporan_kesalahan_kia l JOIN tb_pengajuan_kia p ON l.id_pengajuan = p.id WHERE l.id = ?");
        $stmtCek->bind_param('i', $idLaporan);
        $stmtCek->execute();
        $lap = $stmtCek->get_result()->fetch_assoc();
        $stmtCek->close();

        if (!$lap) {
            throw new RuntimeException("Laporan kesalahan ID #{$idLaporan} tidak ditemukan.");
        }

        $operatorId = (int)$actor['id'];
        $catatan = trim($catatan);

        // Pastikan operatorId ada di tb_users jika ingin mengikat foreign key
        $stmtCekOp = $this->db->prepare("SELECT id FROM tb_users WHERE id = ?");
        $stmtCekOp->bind_param('i', $operatorId);
        $stmtCekOp->execute();
        $opRow = $stmtCekOp->get_result()->fetch_assoc();
        $stmtCekOp->close();
        $validOpId = $opRow ? $operatorId : null;

        $this->db->begin_transaction();
        try {
            if ($validOpId !== null) {
                $stmtUp = $this->db->prepare("UPDATE tb_laporan_kesalahan_kia SET status = 'selesai_dikoreksi', id_operator = ?, waktu_koreksi = NOW() WHERE id = ?");
                $stmtUp->bind_param('ii', $validOpId, $idLaporan);
            } else {
                $stmtUp = $this->db->prepare("UPDATE tb_laporan_kesalahan_kia SET status = 'selesai_dikoreksi', id_operator = NULL, waktu_koreksi = NOW() WHERE id = ?");
                $stmtUp->bind_param('i', $idLaporan);
            }
            $stmtUp->execute();
            $stmtUp->close();

            $idPengajuan = (int)$lap['id_pengajuan'];
            $ownerId = (int)$lap['owner_id'];

            // Catat ke riwayat milestone
            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $idPengajuan,
                status: 'selesai',
                alasan: $catatan !== '' ? $catatan : 'Koreksi laporan kesalahan selesai ditindaklanjuti',
                actorId: $operatorId,
                actorRole: (string)$actor['role'],
                eventType: 'koreksi_laporan_selesai',
                meta: ['id_laporan' => $idLaporan, 'catatan' => $catatan]
            );

            // Notifikasi ke pemohon
            if ($ownerId > 0) {
                $pesan = "Petugas telah menyelesaikan perbaikan atas laporan kesalahan pengajuan KIA Anda." . ($catatan !== '' ? " Catatan: {$catatan}" : '');
                $this->kirimNotifikasi(
                    userId: $ownerId,
                    judul: 'Laporan Kesalahan KIA Telah Selesai Dikoreksi',
                    pesan: $pesan,
                    tipe: 'success',
                    permohonanId: $idPengajuan,
                    targetUrl: "user/kia.php?action=detail&id={$idPengajuan}"
                );
            }

            $this->db->commit();

            if ($ownerId > 0) {
                $this->sendWhatsAppToPemohon(
                    userId: $ownerId,
                    permohonanId: $idPengajuan,
                    eventType: "kia_balasan_laporan_{$idLaporan}_selesai",
                    pesan: "Halo {NAMA}, laporan kesalahan dokumen KIA pada permohonan #{$idPengajuan} telah selesai ditindaklanjuti oleh Operator. Silakan masuk ke SIPRIMA untuk melihat tanggapan dan dokumen terbaru."
                );
            }

            return [
                'id_laporan' => $idLaporan,
                'id_pengajuan' => $idPengajuan,
                'status' => 'selesai_dikoreksi',
                'id_operator' => $operatorId,
                'waktu_koreksi' => date('Y-m-d H:i:s')
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private bool $surveiSaranColumnChecked = false;

    private function ensureSurveiSaranColumn(): void
    {
        if ($this->surveiSaranColumnChecked) {
            return;
        }
        $this->surveiSaranColumnChecked = true;
        $res = $this->db->query("SHOW COLUMNS FROM tb_survei_kia LIKE 'saran'");
        if ($res && $res->num_rows === 0) {
            $this->db->query("ALTER TABLE tb_survei_kia ADD COLUMN saran TEXT NULL AFTER alamat");
        }
    }

    // submit survei terintegrasi (SKM, SPKP, atau SPAK)
    public function submitSurvei(int $idPengajuan, int $userId, string $jenisSurvei, array $responden, array $jawaban, ?string $tanggalSurvei = null, ?string $saran = null): array
    {
        $this->ensureSurveiSaranColumn();
        $this->ensurePengajuanSchema();

        $jenisSurvei = strtolower(trim($jenisSurvei));
        if (!in_array($jenisSurvei, ['skm', 'spkp', 'spak'], true)) {
            throw new InvalidArgumentException("Jenis survei tidak valid: {$jenisSurvei}. Pilihan: skm, spkp, spak.");
        }

        // Cek pengajuan dan kepemilikan
        $stmtP = $this->db->prepare("SELECT id, id_user FROM tb_pengajuan_kia WHERE id = ?");
        $stmtP->bind_param('i', $idPengajuan);
        $stmtP->execute();
        $pRow = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();

        if (!$pRow) {
            throw new RuntimeException("Pengajuan KIA #{$idPengajuan} tidak ditemukan.");
        }

        if ((int)$pRow['id_user'] !== $userId) {
            throw new InvalidArgumentException("Anda tidak berhak mengisi survei untuk pengajuan KIA milik pengguna lain.");
        }

        $tanggal = $tanggalSurvei ?? date('Y-m-d');

        // Validasi data responden terlebih dahulu
        $namaLengkap = trim((string)($responden['nama_lengkap'] ?? ''));
        $noHp = trim((string)($responden['no_hp'] ?? ''));
        $email = trim((string)($responden['email'] ?? ''));
        $jk = strtoupper(trim((string)($responden['jenis_kelamin'] ?? '')));
        $usia = (int)($responden['usia'] ?? 0);
        $pendidikan = trim((string)($responden['pendidikan'] ?? ''));
        $pekerjaan = trim((string)($responden['pekerjaan'] ?? ''));
        $alamat = trim((string)($responden['alamat'] ?? ''));
        $saranText = $saran !== null ? trim($saran) : trim((string)($responden['saran'] ?? ''));
        $saranVal = $saranText !== '' ? $saranText : null;

        if ($namaLengkap === '') throw new InvalidArgumentException("Nama lengkap responden wajib diisi.");
        if ($noHp === '') throw new InvalidArgumentException("Nomor HP/WA responden wajib diisi.");
        if ($email === '') throw new InvalidArgumentException("Email responden wajib diisi.");
        if (!in_array($jk, ['L', 'P'], true)) throw new InvalidArgumentException("Jenis kelamin responden harus 'L' atau 'P'.");
        if ($usia <= 0 || $usia > 120) throw new InvalidArgumentException("Usia responden tidak valid (harus 1-120 tahun).");
        if ($pendidikan === '') throw new InvalidArgumentException("Pendidikan responden wajib diisi.");
        if ($pekerjaan === '') throw new InvalidArgumentException("Pekerjaan responden wajib diisi.");

        // Alamat wajib untuk SPKP dan SPAK
        if (in_array($jenisSurvei, ['spkp', 'spak'], true) && $alamat === '') {
            throw new InvalidArgumentException("Alamat responden wajib diisi untuk survei " . strtoupper($jenisSurvei) . ".");
        }

        // Normalisasi format jawaban (bisa berupa associative array ['q1' => 4] atau list of objects)
        $normalizedJawaban = [];
        if (empty($jawaban)) {
            throw new InvalidArgumentException("Jawaban survei tidak boleh kosong.");
        }

        foreach ($jawaban as $key => $val) {
            if (is_array($val) && isset($val['kode_pertanyaan']) && isset($val['nilai_jawaban'])) {
                $kode = strtoupper(trim((string)$val['kode_pertanyaan']));
                $nilai = (int)$val['nilai_jawaban'];
            } else {
                $kode = strtoupper(trim((string)$key));
                $nilai = (int)$val;
            }

            if ($kode === '') {
                throw new InvalidArgumentException("Kode pertanyaan survei tidak valid.");
            }

            if ($jenisSurvei === 'spak' && preg_match('/^K([1-5])$/', $kode, $m)) {
                $kode = 'A' . $m[1];
            }

            // Validasi skala Likert sesuai jenis survei
            if ($jenisSurvei === 'skm') {
                // Skala Likert 1 - 4
                if ($nilai < 1 || $nilai > 4) {
                    throw new InvalidArgumentException("Nilai jawaban survei SKM ({$kode}) harus berada dalam skala 1 sampai 4.");
                }
            } else {
                // SPKP & SPAK: Skala Likert 1 - 6
                if ($nilai < 1 || $nilai > 6) {
                    throw new InvalidArgumentException("Nilai jawaban survei " . strtoupper($jenisSurvei) . " ({$kode}) harus berada dalam skala 1 sampai 6.");
                }
            }

            $normalizedJawaban[$kode] = $nilai;
        }

        // Cek apakah survei jenis ini sudah pernah diisi untuk pengajuan ini (mencegah duplikasi jawaban per pengajuan)
        $stmtCek = $this->db->prepare("SELECT id FROM tb_survei_kia WHERE id_pengajuan = ? AND jenis_survei = ? LIMIT 1");
        $stmtCek->bind_param('is', $idPengajuan, $jenisSurvei);
        $stmtCek->execute();
        $existingSurvei = $stmtCek->get_result()->fetch_assoc();
        $stmtCek->close();

        if ($existingSurvei) {
            throw new InvalidArgumentException("Survei " . strtoupper($jenisSurvei) . " untuk pengajuan ini sudah pernah diisi.");
        }

        $this->db->begin_transaction();
        try {
            // 1. Simpan header survei
            $sqlSurvei = "INSERT INTO tb_survei_kia 
                (id_pengajuan, id_user, tanggal_survei, jenis_survei, status, nama_lengkap, no_hp, email, jenis_kelamin, usia, pendidikan, pekerjaan, alamat, saran, waktu_pengisian) 
                VALUES (?, ?, ?, ?, 'selesai', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmtSurvei = $this->db->prepare($sqlSurvei);
            if (!$stmtSurvei) {
                throw new RuntimeException("Gagal menyiapkan query simpan survei: " . $this->db->error);
            }

            $stmtSurvei->bind_param('iissssssissss', 
                $idPengajuan, $userId, $tanggal, $jenisSurvei, 
                $namaLengkap, $noHp, $email, $jk, $usia, $pendidikan, $pekerjaan, $alamat, $saranVal
            );

            if (!$stmtSurvei->execute()) {
                throw new RuntimeException("Gagal menyimpan survei: " . $stmtSurvei->error);
            }
            $idSurvei = (int)$this->db->insert_id;
            $stmtSurvei->close();

            // 2. Simpan rincian jawaban
            $sqlJawab = "INSERT INTO tb_jawaban_survei_kia (id_survei, bagian_survei, kode_pertanyaan, nilai_jawaban, created_at) VALUES (?, ?, ?, ?, NOW())";
            $stmtJawab = $this->db->prepare($sqlJawab);
            if (!$stmtJawab) {
                throw new RuntimeException("Gagal menyiapkan query jawaban survei: " . $this->db->error);
            }

            $bagianSurvei = strtoupper($jenisSurvei);
            foreach ($normalizedJawaban as $kodeTanya => $nilaiJawab) {
                $stmtJawab->bind_param('issi', $idSurvei, $bagianSurvei, $kodeTanya, $nilaiJawab);
                if (!$stmtJawab->execute()) {
                    throw new RuntimeException("Gagal menyimpan jawaban survei: " . $stmtJawab->error);
                }
            }
            $stmtJawab->close();

            // 3. Jika ketiga survei (SKM, SPKP, SPAK) untuk pengajuan ini sudah lengkap, tandai survei_wajib_selesai = 1
            $stmtCnt = $this->db->prepare("SELECT COUNT(DISTINCT jenis_survei) AS cnt FROM tb_survei_kia WHERE id_pengajuan = ?");
            if ($stmtCnt) {
                $stmtCnt->bind_param('i', $idPengajuan);
                $stmtCnt->execute();
                $cntSelesai = (int)($stmtCnt->get_result()->fetch_assoc()['cnt'] ?? 0);
                $stmtCnt->close();
                if ($cntSelesai >= 3) {
                    $stmtUpWajib = $this->db->prepare("UPDATE tb_pengajuan_kia SET survei_wajib_selesai = 1 WHERE id = ?");
                    if ($stmtUpWajib) {
                        $stmtUpWajib->bind_param('i', $idPengajuan);
                        $stmtUpWajib->execute();
                        $stmtUpWajib->close();
                    }
                }
            }

            $this->db->commit();

            return [
                'id_survei' => $idSurvei,
                'id_pengajuan' => $idPengajuan,
                'jenis_survei' => $jenisSurvei,
                'tanggal_survei' => $tanggal,
                'status' => 'selesai',
                'jumlah_jawaban' => count($normalizedJawaban),
                'saran' => $saranVal
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // progres pengisian survei untuk pengajuan KIA tertentu
    public function getSurveiProgress(int $idPengajuan, int $userId, ?string $tanggal = null): array
    {
        $tanggal = $tanggal ?? date('Y-m-d');
        $stmt = $this->db->prepare("SELECT jenis_survei, waktu_pengisian, tanggal_survei FROM tb_survei_kia WHERE id_pengajuan = ? AND id_user = ? ORDER BY id ASC");
        $stmt->bind_param('ii', $idPengajuan, $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $completed = [];
        $detail = [
            'skm' => ['selesai' => false, 'waktu_pengisian' => null],
            'spkp' => ['selesai' => false, 'waktu_pengisian' => null],
            'spak' => ['selesai' => false, 'waktu_pengisian' => null]
        ];

        foreach ($rows as $r) {
            $t = strtolower((string)$r['jenis_survei']);
            if (isset($detail[$t]) && !$detail[$t]['selesai']) {
                $detail[$t]['selesai'] = true;
                $detail[$t]['waktu_pengisian'] = $r['waktu_pengisian'];
                $completed[] = $t;
            }
        }

        $selesaiCount = count($completed);
        $isLengkap = ($selesaiCount === 3);

        if ($isLengkap) {
            $stmtUpWajib = $this->db->prepare("UPDATE tb_pengajuan_kia SET survei_wajib_selesai = 1 WHERE id = ? AND survei_wajib_selesai = 0");
            if ($stmtUpWajib) {
                $stmtUpWajib->bind_param('i', $idPengajuan);
                $stmtUpWajib->execute();
                $stmtUpWajib->close();
            }
        }

        return [
            'id_pengajuan' => $idPengajuan,
            'tanggal' => $tanggal,
            'total_survei' => 3,
            'jumlah_selesai' => $selesaiCount,
            'survei_selesai' => $completed,
            'is_lengkap' => $isLengkap,
            'label_progress' => "{$selesaiCount} dari 3 survei selesai",
            'detail' => $detail
        ];
    }

    // mendeteksi apakah pemohon memiliki pengajuan KIA yang belum menyelesaikan kewajiban survei (SKM, SPKP, SPAK)
    public function getPendingMandatorySurvei(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $this->ensurePengajuanSchema();

        $sql = "SELECT p.id, p.no_kk, p.status, p.created_at,
                       COUNT(DISTINCT s.jenis_survei) AS cnt_survei
                FROM tb_pengajuan_kia p
                LEFT JOIN tb_survei_kia s ON s.id_pengajuan = p.id AND s.id_user = p.id_user
                WHERE p.id_user = ? AND p.survei_wajib_selesai = 0
                GROUP BY p.id
                HAVING cnt_survei < 3
                ORDER BY p.id DESC
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        $idPengajuan = (int)$row['id'];
        return [
            'id_pengajuan' => $idPengajuan,
            'no_kk' => (string)$row['no_kk'],
            'status' => (string)$row['status'],
            'created_at' => (string)$row['created_at'],
            'progress' => $this->getSurveiProgress($idPengajuan, $userId)
        ];
    }

    // rekapitulasi & analisis hasil survei khusus untuk Admin/Operator
    public function getSurveiAdminSummary(string $periode = 'hari_ini', ?string $customStart = null, ?string $customEnd = null): array
    {
        $this->ensureSurveiSaranColumn();

        $whereSql = '';
        $params = [];
        $types = '';

        switch ($periode) {
            case 'semua':
                $whereSql = '1=1';
                break;
            case 'kemarin':
                $whereSql = "s.tanggal_survei = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                break;
            case '7_hari':
                $whereSql = "s.tanggal_survei >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'bulan_ini':
                $whereSql = "YEAR(s.tanggal_survei) = YEAR(CURDATE()) AND MONTH(s.tanggal_survei) = MONTH(CURDATE())";
                break;
            case 'bulan_sebelumnya':
                $whereSql = "s.tanggal_survei >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND s.tanggal_survei < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
                break;
            case 'custom':
                if (empty($customStart) || empty($customEnd)) {
                    throw new InvalidArgumentException("Periode custom membutuhkan customStart dan customEnd (format YYYY-MM-DD).");
                }
                $whereSql = "s.tanggal_survei BETWEEN ? AND ?";
                $params = [$customStart, $customEnd];
                $types = 'ss';
                break;
            case 'hari_ini':
            default:
                $periode = 'hari_ini';
                $whereSql = "s.tanggal_survei = CURDATE()";
                break;
        }

        // Helper perhitungan per jenis survei
        $calcSurvey = function (string $jenis) use ($whereSql, $params, $types) {
            $prefix = $whereSql ? "AND {$whereSql}" : "";
            
            // 1. Responden count
            $sqlResp = "SELECT COUNT(DISTINCT s.id) AS total_responden FROM tb_survei_kia s WHERE s.jenis_survei = ? {$prefix}";
            $stmtResp = $this->db->prepare($sqlResp);
            $pResp = array_merge([$jenis], $params);
            $tResp = 's' . $types;
            $stmtResp->bind_param($tResp, ...$pResp);
            $stmtResp->execute();
            $totalResponden = (int)($stmtResp->get_result()->fetch_assoc()['total_responden'] ?? 0);
            $stmtResp->close();

            // 2. Average score overall
            $sqlAvg = "SELECT AVG(j.nilai_jawaban) AS rata_rata, COUNT(j.id) as total_jawaban 
                       FROM tb_jawaban_survei_kia j 
                       JOIN tb_survei_kia s ON j.id_survei = s.id 
                       WHERE s.jenis_survei = ? {$prefix}";
            $stmtAvg = $this->db->prepare($sqlAvg);
            $stmtAvg->bind_param($tResp, ...$pResp);
            $stmtAvg->execute();
            $avgRow = $stmtAvg->get_result()->fetch_assoc();
            $rawRata = $avgRow['rata_rata'] !== null ? (float)$avgRow['rata_rata'] : 0.0;
            $totalJawaban = (int)($avgRow['total_jawaban'] ?? 0);
            $stmtAvg->close();

            // 3. Breakdown per pertanyaan (normalisasi K1..K5 menjadi A1..A5 untuk SPAK)
            $sqlTanya = "SELECT CASE WHEN UPPER(j.kode_pertanyaan) REGEXP '^K[1-5]$'
                                     THEN CONCAT('A', SUBSTRING(UPPER(j.kode_pertanyaan), 2))
                                     ELSE UPPER(j.kode_pertanyaan)
                                END AS kode_norm,
                                AVG(j.nilai_jawaban) AS rata_rata,
                                COUNT(j.id) as jumlah_respons 
                         FROM tb_jawaban_survei_kia j 
                         JOIN tb_survei_kia s ON j.id_survei = s.id 
                         WHERE s.jenis_survei = ? {$prefix} 
                         GROUP BY kode_norm 
                         ORDER BY kode_norm ASC";
            $stmtTanya = $this->db->prepare($sqlTanya);
            $stmtTanya->bind_param($tResp, ...$pResp);
            $stmtTanya->execute();
            $pertanyaanRows = $stmtTanya->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtTanya->close();

            $pertanyaan = [];
            foreach ($pertanyaanRows as $pr) {
                $qRata = round((float)$pr['rata_rata'], 2);
                $qData = [
                    'kode_pertanyaan' => $pr['kode_norm'],
                    'jumlah_respons' => (int)$pr['jumlah_respons'],
                    'rata_rata' => $qRata
                ];
                if ($jenis === 'skm') {
                    $qData['nilai_konversi'] = round($qRata * 25, 2);
                } else {
                    $qData['skor_persen'] = round(($qRata / 6) * 100, 2);
                }
                $pertanyaan[] = $qData;
            }

            // 4. Distribusi skor jawaban riil
            $maxScale = ($jenis === 'skm') ? 4 : 6;
            $distribusiSkor = [];
            for ($sc = 1; $sc <= $maxScale; $sc++) {
                $distribusiSkor[$sc] = ['jumlah' => 0, 'persen' => 0.0];
            }
            $sqlDist = "SELECT j.nilai_jawaban, COUNT(j.id) AS cnt
                        FROM tb_jawaban_survei_kia j
                        JOIN tb_survei_kia s ON j.id_survei = s.id
                        WHERE s.jenis_survei = ? {$prefix}
                        GROUP BY j.nilai_jawaban";
            $stmtDist = $this->db->prepare($sqlDist);
            $stmtDist->bind_param($tResp, ...$pResp);
            $stmtDist->execute();
            $distRows = $stmtDist->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtDist->close();
            foreach ($distRows as $dr) {
                $scVal = (int)$dr['nilai_jawaban'];
                $cntVal = (int)$dr['cnt'];
                if (isset($distribusiSkor[$scVal])) {
                    $distribusiSkor[$scVal]['jumlah'] = $cntVal;
                    $distribusiSkor[$scVal]['persen'] = $totalJawaban > 0 ? round(($cntVal / $totalJawaban) * 100, 1) : 0.0;
                }
            }

            $rataRata = round($rawRata, 2);

            $result = [
                'total_responden' => $totalResponden,
                'total_jawaban' => $totalJawaban,
                'rata_rata' => $rataRata,
                'pertanyaan' => $pertanyaan,
                'distribusi_skor' => $distribusiSkor
            ];

            if ($jenis === 'skm') {
                $skmScore = round($rataRata * 25, 2);
                $mutu = null;
                $kinerja = null;
                if ($totalResponden > 0) {
                    if ($skmScore >= 88.31) {
                        $mutu = 'A';
                        $kinerja = 'Sangat Baik';
                    } elseif ($skmScore >= 76.61) {
                        $mutu = 'B';
                        $kinerja = 'Baik';
                    } elseif ($skmScore >= 65.00) {
                        $mutu = 'C';
                        $kinerja = 'Kurang Baik';
                    } else {
                        $mutu = 'D';
                        $kinerja = 'Tidak Baik';
                    }
                }
                $result['nilai_skm'] = $skmScore;
                $result['mutu_pelayanan'] = $mutu;
                $result['kinerja_pelayanan'] = $kinerja;
            } else {
                $persen = ($totalResponden > 0) ? round(($rataRata / 6) * 100, 2) : 0.0;
                $result['skor_persen'] = $persen;
            }

            return $result;
        };

        $skmData = $calcSurvey('skm');
        $spkpData = $calcSurvey('spkp');
        $spakData = $calcSurvey('spak');

        // Total responden unik (kombinasi id_pengajuan + tanggal_survei) pada periode terpilih
        $sqlUnik = "SELECT COUNT(DISTINCT CONCAT(s.id_pengajuan, '_', s.tanggal_survei)) AS total_unik
                    FROM tb_survei_kia s
                    WHERE {$whereSql}";
        $stmtUnik = $this->db->prepare($sqlUnik);
        if ($types !== '') {
            $stmtUnik->bind_param($types, ...$params);
        }
        $stmtUnik->execute();
        $totalRespondenUnik = (int)($stmtUnik->get_result()->fetch_assoc()['total_unik'] ?? 0);
        $stmtUnik->close();

        // Daftar responden & saran riil pada periode terpilih
        $sqlList = "SELECT s.id, s.id_pengajuan, s.id_user, s.tanggal_survei, s.jenis_survei,
                           s.nama_lengkap, s.no_hp, s.email, s.jenis_kelamin, s.usia,
                           s.pendidikan, s.pekerjaan, s.alamat, s.saran, s.waktu_pengisian,
                           MAX(p.no_kk) AS no_kk, MAX(k.nama_kecamatan) AS nama_kecamatan, MAX(d.nama_desa) AS nama_desa,
                           ROUND(AVG(j.nilai_jawaban), 2) AS rata_skor,
                           COUNT(j.id) AS jumlah_jawaban
                    FROM tb_survei_kia s
                    LEFT JOIN tb_pengajuan_kia p ON s.id_pengajuan = p.id
                    LEFT JOIN tb_kecamatan k ON p.id_kecamatan = k.id
                    LEFT JOIN tb_desa d ON p.id_desa = d.id
                    LEFT JOIN tb_jawaban_survei_kia j ON j.id_survei = s.id
                    WHERE {$whereSql}
                    GROUP BY s.id
                    ORDER BY s.waktu_pengisian DESC, s.id DESC
                    LIMIT 100";
        $stmtList = $this->db->prepare($sqlList);
        if ($types !== '') {
            $stmtList->bind_param($types, ...$params);
        }
        $stmtList->execute();
        $respondenTerbaru = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtList->close();

        $jawabanBySurveiRingkasan = [];
        if (!empty($respondenTerbaru)) {
            $sIds = array_map(fn($r) => (int)$r['id'], $respondenTerbaru);
            $sIdSql = implode(',', $sIds);
            $resJR = $this->db->query("SELECT id_survei, bagian_survei, kode_pertanyaan, nilai_jawaban FROM tb_jawaban_survei_kia WHERE id_survei IN ({$sIdSql}) ORDER BY id ASC");
            if ($resJR) {
                while ($jr = $resJR->fetch_assoc()) {
                    $sid = (int)$jr['id_survei'];
                    $kode = strtoupper(trim((string)$jr['kode_pertanyaan']));
                    if (strtoupper((string)$jr['bagian_survei']) === 'SPAK' && preg_match('/^K([1-5])$/', $kode, $m)) {
                        $kode = 'A' . $m[1];
                    }
                    $jawabanBySurveiRingkasan[$sid][$kode] = (int)$jr['nilai_jawaban'];
                }
            }
        }

        $saranList = [];
        foreach ($respondenTerbaru as &$rowResp) {
            $rowResp['id'] = (int)$rowResp['id'];
            $rowResp['id_pengajuan'] = (int)$rowResp['id_pengajuan'];
            $rowResp['usia'] = (int)$rowResp['usia'];
            $rowResp['rata_skor'] = (float)($rowResp['rata_skor'] ?? 0.0);
            $rowResp['jumlah_jawaban'] = (int)($rowResp['jumlah_jawaban'] ?? 0);
            $rowResp['jawaban'] = $jawabanBySurveiRingkasan[$rowResp['id']] ?? [];
            if (!empty($rowResp['saran']) && trim((string)$rowResp['saran']) !== '') {
                $saranList[] = [
                    'id_survei' => $rowResp['id'],
                    'id_pengajuan' => $rowResp['id_pengajuan'],
                    'jenis_survei' => strtoupper((string)$rowResp['jenis_survei']),
                    'nama_lengkap' => $rowResp['nama_lengkap'],
                    'no_hp' => $rowResp['no_hp'] ?? '-',
                    'email' => $rowResp['email'] ?? '-',
                    'jenis_kelamin' => $rowResp['jenis_kelamin'] ?? '-',
                    'usia' => $rowResp['usia'],
                    'pendidikan' => $rowResp['pendidikan'] ?? '-',
                    'pekerjaan' => $rowResp['pekerjaan'] ?? '-',
                    'alamat' => $rowResp['alamat'] ?? '-',
                    'nama_kecamatan' => $rowResp['nama_kecamatan'] ?? '',
                    'nama_desa' => $rowResp['nama_desa'] ?? '',
                    'tanggal_survei' => $rowResp['tanggal_survei'],
                    'waktu_pengisian' => $rowResp['waktu_pengisian'],
                    'rata_skor' => $rowResp['rata_skor'],
                    'saran' => trim((string)$rowResp['saran']),
                ];
            }
        }
        unset($rowResp);

        // Tren bulanan riil dari database (6 bulan terakhir yang memiliki data)
        $sqlTrend = "SELECT DATE_FORMAT(s.tanggal_survei, '%Y-%m') AS ym,
                            DATE_FORMAT(s.tanggal_survei, '%b %Y') AS label_bulan,
                            s.jenis_survei,
                            COUNT(DISTINCT s.id) AS total_responden,
                            ROUND(AVG(j.nilai_jawaban), 2) AS rata_rata
                     FROM tb_survei_kia s
                     JOIN tb_jawaban_survei_kia j ON j.id_survei = s.id
                     GROUP BY ym, label_bulan, s.jenis_survei
                     ORDER BY ym ASC";
        $resTrend = $this->db->query($sqlTrend);
        $trendMap = [];
        if ($resTrend) {
            while ($tr = $resTrend->fetch_assoc()) {
                $ym = (string)$tr['ym'];
                if (!isset($trendMap[$ym])) {
                    $trendMap[$ym] = [
                        'ym' => $ym,
                        'label_bulan' => (string)$tr['label_bulan'],
                        'skm_rata' => 0.0,
                        'skm_nilai' => 0.0,
                        'spkp_rata' => 0.0,
                        'spkp_persen' => 0.0,
                        'spak_rata' => 0.0,
                        'spak_persen' => 0.0,
                        'total_isian' => 0
                    ];
                }
                $jns = strtolower((string)$tr['jenis_survei']);
                $avgVal = (float)$tr['rata_rata'];
                $cntResp = (int)$tr['total_responden'];
                $trendMap[$ym]['total_isian'] += $cntResp;
                if ($jns === 'skm') {
                    $trendMap[$ym]['skm_rata'] = $avgVal;
                    $trendMap[$ym]['skm_nilai'] = round($avgVal * 25, 2);
                } elseif ($jns === 'spkp') {
                    $trendMap[$ym]['spkp_rata'] = $avgVal;
                    $trendMap[$ym]['spkp_persen'] = round(($avgVal / 6) * 100, 2);
                } elseif ($jns === 'spak') {
                    $trendMap[$ym]['spak_rata'] = $avgVal;
                    $trendMap[$ym]['spak_persen'] = round(($avgVal / 6) * 100, 2);
                }
            }
        }
        $trenBulanan = array_slice(array_values($trendMap), -6);

        return [
            'periode' => $periode,
            'custom_start' => $customStart,
            'custom_end' => $customEnd,
            'total_responden_unik' => $totalRespondenUnik,
            'skm' => $skmData,
            'spkp' => $spkpData,
            'spak' => $spakData,
            'responden_terbaru' => $respondenTerbaru,
            'saran_list' => $saranList,
            'tren_bulanan' => $trenBulanan
        ];
    }

    // mengunggah berkas PDF hasil KIA (per anak jika idAnak disediakan, atau fallback tunggal untuk data lama)
    public function uploadDokumenHasilPdf(int $idPengajuan, array $fileData, array $actor, ?int $idAnak = null): array
    {
        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            throw new InvalidArgumentException('Hanya operator/admin yang berhak mengunggah berkas PDF hasil KIA.');
        }

        $stmtP = $this->db->prepare("SELECT id, id_user, status, versi_aktif, dokumen_kia_pdf FROM tb_pengajuan_kia WHERE id = ?");
        $stmtP->bind_param('i', $idPengajuan);
        $stmtP->execute();
        $pRow = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();

        if (!$pRow) {
            throw new RuntimeException("Pengajuan KIA #{$idPengajuan} tidak ditemukan.");
        }

        $anakNama = null;
        if ($idAnak !== null && $idAnak > 0) {
            $stmtAnak = $this->db->prepare("SELECT id, nama_anak FROM tb_anak_kia WHERE id = ? AND id_pengajuan = ? LIMIT 1");
            $stmtAnak->bind_param('ii', $idAnak, $idPengajuan);
            $stmtAnak->execute();
            $anakRow = $stmtAnak->get_result()->fetch_assoc();
            $stmtAnak->close();
            if (!$anakRow) {
                throw new InvalidArgumentException("Data anak #{$idAnak} tidak ditemukan pada pengajuan KIA #{$idPengajuan}.");
            }
            $anakNama = (string)($anakRow['nama_anak'] ?? '');
        } else {
            $idAnak = null;
        }

        $this->db->begin_transaction();
        try {
            // Hitung versi PDF hasil yang diunggah (per anak jika idAnak ada)
            if ($idAnak !== null) {
                $stmtCek = $this->db->prepare("SELECT COUNT(*) AS total FROM tb_dokumen_kia WHERE id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf' AND id_anak = ?");
                $stmtCek->bind_param('ii', $idPengajuan, $idAnak);
            } else {
                $stmtCek = $this->db->prepare("SELECT COUNT(*) AS total FROM tb_dokumen_kia WHERE id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf'");
                $stmtCek->bind_param('i', $idPengajuan);
            }
            $stmtCek->execute();
            $totalPrev = (int)($stmtCek->get_result()->fetch_assoc()['total'] ?? 0);
            $stmtCek->close();
            $versiPdf = $totalPrev + 1;

            // Tandai dokumen hasil PDF sebelumnya untuk anak ini (atau seluruh pengajuan jika legacy) sebagai 'superseded'
            if ($idAnak !== null) {
                $stmtSup = $this->db->prepare("UPDATE tb_dokumen_kia SET status_dokumen = 'superseded' WHERE id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf' AND id_anak = ?");
                $stmtSup->bind_param('ii', $idPengajuan, $idAnak);
            } else {
                $stmtSup = $this->db->prepare("UPDATE tb_dokumen_kia SET status_dokumen = 'superseded' WHERE id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf'");
                $stmtSup->bind_param('i', $idPengajuan);
            }
            $stmtSup->execute();
            $stmtSup->close();

            // Simpan record PDF baru di tb_dokumen_kia
            $sqlDok = "INSERT INTO tb_dokumen_kia 
                (id_pengajuan, id_anak, jenis_dokumen, original_name, stored_name, file_path, mime_type, file_size, versi, status_dokumen, uploaded_at) 
                VALUES (?, ?, 'hasil_kia_pdf', ?, ?, ?, ?, ?, ?, 'valid', NOW())";
            $stmtDok = $this->db->prepare($sqlDok);
            if (!$stmtDok) {
                throw new RuntimeException('Gagal menyiapkan query dokumen hasil: ' . $this->db->error);
            }

            $origName = (string)$fileData['original_name'];
            $storedName = (string)$fileData['stored_name'];
            $filePath = (string)$fileData['file_path'];
            $mimeType = (string)($fileData['mime_type'] ?? 'application/pdf');
            $fileSize = (int)$fileData['file_size'];

            $stmtDok->bind_param('iissssii', $idPengajuan, $idAnak, $origName, $storedName, $filePath, $mimeType, $fileSize, $versiPdf);
            if (!$stmtDok->execute()) {
                throw new RuntimeException('Gagal menyimpan dokumen hasil KIA: ' . $stmtDok->error);
            }
            $dokId = (int)$this->db->insert_id;
            $stmtDok->close();

            // Update tb_pengajuan_kia.dokumen_kia_pdf sebagai referensi ringkas/kompatibilitas
            $stmtUp = $this->db->prepare("UPDATE tb_pengajuan_kia SET dokumen_kia_pdf = ?, updated_at = NOW() WHERE id = ?");
            $stmtUp->bind_param('si', $filePath, $idPengajuan);
            if (!$stmtUp->execute()) {
                throw new RuntimeException('Gagal mengupdate referensi PDF hasil KIA: ' . $stmtUp->error);
            }
            $stmtUp->close();

            $statusCurr = (string)$pRow['status'];
            $idUserPemohon = (int)$pRow['id_user'];
            $isReplacement = ($totalPrev > 0) || ($idAnak === null && $pRow['dokumen_kia_pdf'] !== null && $pRow['dokumen_kia_pdf'] !== '');

            // Log milestone ke status history
            $eventType = $isReplacement ? 'penggantian_pdf_hasil' : 'upload_pdf_hasil';
            $anakNote = $anakNama !== null && $anakNama !== '' ? (' untuk anak ' . $anakNama) : '';
            $alasan = $isReplacement
                ? 'Penggantian/pembaruan berkas PDF hasil KIA' . $anakNote . ' oleh petugas (versi ' . $versiPdf . ')'
                : 'Unggah berkas PDF hasil KIA' . $anakNote . ' oleh petugas';

            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $idPengajuan,
                status: $statusCurr,
                alasan: $alasan,
                actorId: (int)($actor['id'] ?? 0),
                actorRole: (string)($actor['role'] ?? 'operator'),
                eventType: $eventType,
                meta: [
                    'id_dokumen' => $dokId,
                    'id_anak' => $idAnak,
                    'nama_anak' => $anakNama,
                    'versi_pdf' => $versiPdf,
                    'is_replacement' => $isReplacement,
                    'file_name' => $origName,
                    'stored_name' => $storedName,
                    'file_size' => $fileSize,
                    'file_path' => $filePath
                ]
            );

            // Jika status sudah selesai dan ada penggantian PDF (misal pasca lapor kesalahan), kirim notifikasi update
            if ($statusCurr === 'selesai' && $idUserPemohon > 0) {
                $this->kirimNotifikasi(
                    userId: $idUserPemohon,
                    judul: 'Berkas PDF Hasil KIA Diperbarui',
                    pesan: 'Petugas telah memperbarui berkas PDF hasil KIA Anda. Silakan unduh berkas versi terbaru.',
                    tipe: 'success',
                    permohonanId: $idPengajuan,
                    targetUrl: "user/kia.php?action=detail&id={$idPengajuan}"
                );
            }

            $this->db->commit();

            // Notifikasi WhatsApp KHUSUS Pemohon bahwa PDF KIA baru / pengganti sudah tersedia
            if ($idUserPemohon > 0 && ($isReplacement || $statusCurr === 'selesai')) {
                $anakTag = $anakNama !== null && $anakNama !== '' ? " untuk {$anakNama}" : '';
                if ($isReplacement) {
                    $this->sendWhatsAppToPemohon(
                        userId: $idUserPemohon,
                        permohonanId: $idPengajuan,
                        eventType: 'kia_pdf_pengganti_' . ($idAnak ?? 'all') . '_v' . $versiPdf,
                        pesan: "Halo {NAMA}, dokumen PDF KIA pengganti versi terbaru{$anakTag} pada permohonan #{$idPengajuan} sudah tersedia. Silakan masuk ke SIPRIMA untuk memeriksa dan mengunduh dokumen terbaru."
                    );
                } else {
                    $this->sendWhatsAppToPemohon(
                        userId: $idUserPemohon,
                        permohonanId: $idPengajuan,
                        eventType: 'kia_pdf_tersedia_' . ($idAnak ?? 'all') . '_v' . $versiPdf,
                        pesan: "Halo {NAMA}, dokumen PDF Kartu Identitas Anak (KIA){$anakTag} pada permohonan #{$idPengajuan} sudah tersedia dan dapat dilihat atau diunduh melalui akun SIPRIMA Anda."
                    );
                }
            }

            return [
                'id_dokumen' => $dokId,
                'id_pengajuan' => $idPengajuan,
                'id_anak' => $idAnak,
                'dokumen_kia_pdf' => $filePath,
                'versi' => $versiPdf,
                'original_name' => $origName,
                'stored_name' => $storedName,
                'file_size' => $fileSize,
                'status_pengajuan' => $statusCurr,
                'is_replacement' => $isReplacement
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Memeriksa status kelengkapan PDF hasil KIA berdasarkan seluruh anak dalam permohonan.
     * Kompatibel dengan data lama (1 anak dengan id_anak NULL atau tb_pengajuan_kia.dokumen_kia_pdf).
     */
    public function getHasilPdfStatusByPengajuan(int $idPengajuan): array
    {
        $stmtP = $this->db->prepare("SELECT id, dokumen_kia_pdf, updated_at, created_at FROM tb_pengajuan_kia WHERE id = ? LIMIT 1");
        $stmtP->bind_param('i', $idPengajuan);
        $stmtP->execute();
        $pRow = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();

        $anakRows = [];
        $stmtA = $this->db->prepare("SELECT id, nama_anak, nik_anak, tanggal_lahir, urutan_anak FROM tb_anak_kia WHERE id_pengajuan = ? ORDER BY urutan_anak ASC, id ASC");
        if ($stmtA) {
            $stmtA->bind_param('i', $idPengajuan);
            $stmtA->execute();
            $anakRows = $stmtA->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtA->close();
        }

        $pdfByAnak = [];
        $riwayatPdfByAnak = [];
        $legacyNullPdf = null;
        $legacyRiwayatPdfs = [];
        $allValidPdfs = [];
        $stmtD = $this->db->prepare("SELECT id, id_anak, original_name, stored_name, file_path, file_size, versi, status_dokumen, uploaded_at FROM tb_dokumen_kia WHERE id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf' ORDER BY versi DESC, id DESC");
        if ($stmtD) {
            $stmtD->bind_param('i', $idPengajuan);
            $stmtD->execute();
            $resD = $stmtD->get_result();
            while ($dr = $resD->fetch_assoc()) {
                $docSt = strtolower((string)($dr['status_dokumen'] ?? 'valid'));
                $aid = (int)($dr['id_anak'] ?? 0);
                if ($docSt === 'superseded') {
                    if ($aid > 0) {
                        $riwayatPdfByAnak[$aid][] = $dr;
                    } else {
                        $legacyRiwayatPdfs[] = $dr;
                    }
                    continue;
                }
                $allValidPdfs[] = $dr;
                if ($aid > 0) {
                    if (!isset($pdfByAnak[$aid])) {
                        $pdfByAnak[$aid] = $dr;
                    }
                } elseif ($legacyNullPdf === null) {
                    $legacyNullPdf = $dr;
                }
            }
            $stmtD->close();
        }

        $legacyColPath = trim((string)($pRow['dokumen_kia_pdf'] ?? ''));
        $legacyColEntry = null;
        if ($legacyColPath !== '') {
            $legacyColEntry = [
                'id' => 0,
                'id_anak' => null,
                'original_name' => basename(str_replace('\\', '/', $legacyColPath)),
                'stored_name' => basename(str_replace('\\', '/', $legacyColPath)),
                'file_path' => $legacyColPath,
                'file_size' => 0,
                'versi' => 1,
                'uploaded_at' => $pRow['updated_at'] ?? $pRow['created_at'] ?? '',
            ];
        }

        $totalAnak = count($anakRows);
        $anakSlots = [];
        $uploadedCount = 0;
        $missingNames = [];

        foreach ($anakRows as $idx => $anak) {
            $aid = (int)$anak['id'];
            $pdf = $pdfByAnak[$aid] ?? null;
            // Kompatibilitas data lama jika hanya ada 1 anak dan PDF tersimpan tanpa id_anak
            if ($pdf === null && $totalAnak === 1) {
                $pdf = $legacyNullPdf ?? $legacyColEntry;
            }
            if ($pdf !== null) {
                $uploadedCount++;
            } else {
                $missingNames[] = (string)($anak['nama_anak'] ?: ('Anak ' . ($idx + 1)));
            }
            $riwayatAnak = $riwayatPdfByAnak[$aid] ?? [];
            if ($totalAnak === 1 && !empty($legacyRiwayatPdfs)) {
                $riwayatAnak = array_merge($riwayatAnak, $legacyRiwayatPdfs);
            }
            $anakSlots[] = [
                'id' => $aid,
                'nama_anak' => (string)($anak['nama_anak'] ?? ''),
                'nik_anak' => (string)($anak['nik_anak'] ?? ''),
                'urutan_anak' => (int)($anak['urutan_anak'] ?? ($idx + 1)),
                'pdf' => $pdf,
                'riwayat_pdf' => $riwayatAnak,
            ];
        }

        $complete = $totalAnak > 0
            ? ($uploadedCount >= $totalAnak)
            : ($legacyNullPdf !== null || $legacyColEntry !== null || !empty($allValidPdfs));

        return [
            'total_anak' => $totalAnak,
            'uploaded_count' => $uploadedCount,
            'complete' => $complete,
            'anak_slots' => $anakSlots,
            'missing_names' => $missingNames,
            'all_valid_pdfs' => $allValidPdfs,
            'legacy_pdf' => $legacyNullPdf ?? $legacyColEntry,
        ];
    }

    // informasi berkas PDF hasil KIA dengan pemeriksaan hak akses dan status guard:
    public function getDokumenHasilPdf(int $idPengajuan, array $actor): ?array
    {
        $stmtP = $this->db->prepare("SELECT id, id_user, status, dokumen_kia_pdf, updated_at FROM tb_pengajuan_kia WHERE id = ?");
        $stmtP->bind_param('i', $idPengajuan);
        $stmtP->execute();
        $pRow = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();

        if (!$pRow) {
            throw new RuntimeException("Pengajuan KIA #{$idPengajuan} tidak ditemukan.");
        }

        if (empty($pRow['dokumen_kia_pdf'])) {
            return null;
        }

        $actorRole = (string)($actor['role'] ?? 'user');
        $actorId = (int)($actor['id'] ?? 0);
        $ownerId = (int)$pRow['id_user'];
        $statusPengajuan = (string)$pRow['status'];

        if ($actorRole === 'user') {
            // Guard 1: Ownership
            if ($actorId !== $ownerId) {
                throw new InvalidArgumentException("Akses ditolak. Anda tidak memiliki hak akses terhadap dokumen pengajuan KIA ini.");
            }

            // Guard 2: Status Guard
            if ($statusPengajuan !== 'selesai') {
                throw new InvalidArgumentException("Berkas PDF hasil KIA belum dapat diakses oleh pemohon sebelum permohonan berstatus Selesai.");
            }
        } elseif (!in_array($actorRole, ['operator', 'admin', 'petugas_desa'], true)) {
            throw new InvalidArgumentException("Akses ditolak. Role Anda ({$actorRole}) tidak memiliki izin mengakses berkas ini.");
        }

        // Ambil info dokumen dari tb_dokumen_kia (versi aktif)
        $stmtDok = $this->db->prepare("SELECT * FROM tb_dokumen_kia WHERE id_pengajuan = ? AND jenis_dokumen = 'hasil_kia_pdf' AND status_dokumen = 'valid' ORDER BY id DESC LIMIT 1");
        $stmtDok->bind_param('i', $idPengajuan);
        $stmtDok->execute();
        $dokRow = $stmtDok->get_result()->fetch_assoc();
        $stmtDok->close();

        return [
            'id_pengajuan' => $idPengajuan,
            'status_pengajuan' => $statusPengajuan,
            'file_path' => $pRow['dokumen_kia_pdf'],
            'original_name' => $dokRow['original_name'] ?? basename($pRow['dokumen_kia_pdf']),
            'stored_name' => $dokRow['stored_name'] ?? basename($pRow['dokumen_kia_pdf']),
            'file_size' => (int)($dokRow['file_size'] ?? 0),
            'mime_type' => 'application/pdf',
            'versi' => (int)($dokRow['versi'] ?? 1),
            'uploaded_at' => $dokRow['uploaded_at'] ?? $pRow['updated_at']
        ];
    }

    private bool $cetakKiaTablesChecked = true;

    // inisialisasi tabel cetak
    public function ensureCetakKiaTables(): void
    {
    }

    // status ketersediaan bahan cetak KIA (Blangko KIA, Tinta, Ribbon) beserta riwayat perubahan
    public function getPengaturanCetakKia(bool $includeLog = false): array
    {
        $this->ensureCetakKiaTables();

        $res = $this->db->query("SELECT * FROM tb_pengaturan_cetak_kia WHERE id = 1 LIMIT 1");
        $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : [
            'id' => 1,
            'blangko_tersedia' => 1,
            'tinta_tersedia' => 1,
            'ribbon_tersedia' => 1,
            'catatan' => null,
            'updated_by' => null,
            'updated_by_nama' => 'Sistem',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $blangko = ((int)($row['blangko_tersedia'] ?? 1)) === 1;
        $tinta = ((int)($row['tinta_tersedia'] ?? 1)) === 1;
        $ribbon = $tinta;

        $bahanKosong = [];
        if (!$blangko) {
            $bahanKosong[] = 'Blangko';
        }
        if (!$tinta) {
            $bahanKosong[] = 'Tinta';
        }

        $layananAktif = ($blangko && $tinta);
        $catatan = trim((string)($row['catatan'] ?? ''));

        if ($layananAktif) {
            $pesanStatus = 'Layanan cetak kartu fisik KIA tersedia.';
        } else {
            $daftarKosong = implode(', ', $bahanKosong);
            $pesanStatus = "Layanan cetak kartu fisik KIA sedang tidak tersedia karena keterbatasan bahan cetak ({$daftarKosong}).";
            if ($catatan !== '' && !preg_match('/uji|test/i', $catatan)) {
                $pesanStatus .= " Catatan: {$catatan}";
            }
        }

        $result = [
            'blangko_tersedia' => $blangko,
            'tinta_tersedia' => $tinta,
            'ribbon_tersedia' => $ribbon,
            'layanan_cetak_aktif' => $layananAktif,
            'bahan_kosong' => $bahanKosong,
            'catatan' => $catatan,
            'pesan_status' => $pesanStatus,
            'updated_by' => $row['updated_by'] !== null ? (int)$row['updated_by'] : null,
            'updated_by_nama' => $row['updated_by_nama'] ?? 'Admin',
            'updated_at' => $row['updated_at'] ?? null
        ];

        if ($includeLog) {
            $resLog = $this->db->query("SELECT * FROM tb_pengaturan_cetak_kia_log ORDER BY id DESC LIMIT 25");
            $result['riwayat_log'] = $resLog ? $resLog->fetch_all(MYSQLI_ASSOC) : [];
        }

        return $result;
    }

    // memperbarui pengaturan ketersediaan bahan cetak KIA (Blangko, Tinta, Ribbon) dan mencatat log audit
    public function updatePengaturanCetakKia(array $data, array $actor): array
    {
        if (($actor['role'] ?? '') !== 'admin') {
            throw new InvalidArgumentException('Akses ditolak. Hanya Admin yang berhak mengubah pengaturan ketersediaan bahan cetak KIA.');
        }

        $this->ensureCetakKiaTables();

        $blangko = !empty($data['blangko_tersedia']) ? 1 : 0;
        $tinta = !empty($data['tinta_tersedia']) ? 1 : 0;
        $ribbon = $tinta;
        $catatan = isset($data['catatan']) ? trim((string)$data['catatan']) : '';
        $catatanVal = $catatan !== '' ? $catatan : null;

        $actorId = !empty($actor['id']) ? (int)$actor['id'] : null;
        $actorNama = trim((string)($actor['nama'] ?? 'Admin'));
        if ($actorNama === '') {
            $actorNama = 'Admin';
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("UPDATE tb_pengaturan_cetak_kia
                                        SET blangko_tersedia = ?,
                                            tinta_tersedia = ?,
                                            ribbon_tersedia = ?,
                                            catatan = ?,
                                            updated_by = ?,
                                            updated_by_nama = ?,
                                            updated_at = NOW()
                                        WHERE id = 1");
            if (!$stmt) {
                throw new RuntimeException('Gagal menyiapkan query pengaturan cetak KIA: ' . $this->db->error);
            }
            $stmt->bind_param('iiisis', $blangko, $tinta, $ribbon, $catatanVal, $actorId, $actorNama);
            $stmt->execute();
            $stmt->close();

            $stmtLog = $this->db->prepare("INSERT INTO tb_pengaturan_cetak_kia_log
                                           (blangko_tersedia, tinta_tersedia, ribbon_tersedia, catatan, actor_id, actor_nama, created_at)
                                           VALUES (?, ?, ?, ?, ?, ?, NOW())");
            if ($stmtLog) {
                $stmtLog->bind_param('iiisis', $blangko, $tinta, $ribbon, $catatanVal, $actorId, $actorNama);
                $stmtLog->execute();
                $stmtLog->close();
            }

            $this->db->commit();
            return $this->getPengaturanCetakKia(true);
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // data permintaan cetak fisik KIA berdasarkan ID pengajuan
    public function getCetakKiaByPengajuan(int $idPengajuan): ?array
    {
        $this->ensureCetakKiaTables();

        $stmt = $this->db->prepare("SELECT * FROM tb_cetak_kia WHERE id_pengajuan = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $idPengajuan);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        $statusCetak = (string)$row['status_cetak'];
        $row['label_status_cetak'] = match ($statusCetak) {
            'menunggu_cetak' => 'Menunggu Cetak',
            'sedang_dicetak' => 'Sedang Dicetak',
            'selesai_dicetak' => 'Selesai Dicetak (Siap Diambil)',
            default => ucfirst(str_replace('_', ' ', $statusCetak))
        };

        return $row;
    }

    // mengajukan permintaan cetak fisik KIA (hanya untuk permohonan yang berstatus 'selesai' dan bahan cetak tersedia)
    public function ajukanCetakKia(int $idPengajuan, int $userId, string $catatanPemohon, array $actor): array
    {
        $this->ensureCetakKiaTables();

        $stmtP = $this->db->prepare("SELECT id, id_user, id_kecamatan, no_kk, status, dokumen_kia_pdf FROM tb_pengajuan_kia WHERE id = ?");
        $stmtP->bind_param('i', $idPengajuan);
        $stmtP->execute();
        $pRow = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();

        if (!$pRow) {
            throw new RuntimeException("Pengajuan KIA #{$idPengajuan} tidak ditemukan.");
        }

        if ((int)$pRow['id_user'] !== $userId) {
            throw new InvalidArgumentException('Akses ditolak. Anda hanya dapat mengajukan cetak fisik KIA untuk permohonan milik Anda sendiri.');
        }

        if ((string)$pRow['status'] !== 'selesai') {
            throw new InvalidArgumentException('Permintaan cetak fisik KIA hanya dapat diajukan setelah permohonan KIA berstatus Selesai.');
        }

        // Periksa ketersediaan bahan cetak (Blangko KIA, Tinta, Ribbon)
        $pengaturan = $this->getPengaturanCetakKia();
        if (!$pengaturan['layanan_cetak_aktif']) {
            throw new InvalidArgumentException($pengaturan['pesan_status']);
        }

        // Cek apakah sudah pernah diajukan
        $existing = $this->getCetakKiaByPengajuan($idPengajuan);
        if ($existing) {
            throw new InvalidArgumentException('Permintaan cetak fisik KIA untuk permohonan ini sudah terdaftar dengan status: ' . $existing['label_status_cetak'] . '.');
        }

        $catatanPemohon = trim($catatanPemohon);
        $catatanVal = $catatanPemohon !== '' ? $catatanPemohon : null;

        $this->db->begin_transaction();
        try {
            $stmtIns = $this->db->prepare("INSERT INTO tb_cetak_kia
                (id_pengajuan, id_user, status_cetak, catatan_pemohon, requested_at, updated_at)
                VALUES (?, ?, 'menunggu_cetak', ?, NOW(), NOW())");
            if (!$stmtIns) {
                throw new RuntimeException('Gagal menyiapkan query permintaan cetak KIA: ' . $this->db->error);
            }
            $stmtIns->bind_param('iis', $idPengajuan, $userId, $catatanVal);
            if (!$stmtIns->execute()) {
                throw new RuntimeException('Gagal menyimpan permintaan cetak KIA: ' . $stmtIns->error);
            }
            $cetakId = (int)$this->db->insert_id;
            $stmtIns->close();

            // Catat riwayat di tb_permohonan_status_history tanpa mengubah status utama pengajuan ('selesai')
            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $idPengajuan,
                status: 'selesai',
                alasan: $catatanVal !== null ? "Permintaan cetak fisik KIA: {$catatanVal}" : 'Pemohon mengajukan pencetakan kartu fisik KIA',
                actorId: (int)($actor['id'] ?? $userId),
                actorRole: (string)($actor['role'] ?? 'user'),
                eventType: 'cetak_kia_diajukan',
                meta: ['id_cetak' => $cetakId, 'status_cetak' => 'menunggu_cetak']
            );

            // Notifikasi ke pemohon
            $this->kirimNotifikasi(
                userId: $userId,
                judul: 'Permintaan Cetak KIA Terkirim',
                pesan: "Permintaan cetak fisik KIA (No. KK: {$pRow['no_kk']}) telah masuk ke antrean cetak petugas.",
                tipe: 'info',
                permohonanId: $idPengajuan,
                targetUrl: "user/status_permohonan.php"
            );

            // Notifikasi ke operator kecamatan terkait
            $this->notifyOperatorsByKecamatan(
                idKecamatan: (int)($pRow['id_kecamatan'] ?? 0),
                permohonanId: $idPengajuan,
                judul: 'Permintaan Cetak Fisik KIA Baru',
                pesan: "Pemohon mengajukan pencetakan kartu fisik KIA untuk permohonan #{$idPengajuan} (No. KK: {$pRow['no_kk']}).",
                tipe: 'info'
            );

            $this->db->commit();

            return $this->getCetakKiaByPengajuan($idPengajuan) ?? [
                'id' => $cetakId,
                'id_pengajuan' => $idPengajuan,
                'status_cetak' => 'menunggu_cetak',
                'label_status_cetak' => 'Menunggu Cetak'
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // operator/Admin memproses dan memperbarui status cetak fisik KIA ('menunggu_cetak', 'sedang_dicetak', 'selesai_dicetak')
    public function updateStatusCetakKia(int $idPengajuan, string $statusCetakBaru, string $catatanOperator, array $actor): array
    {
        $allowedRoles = ['operator', 'admin', 'petugas_desa'];
        if (!in_array($actor['role'] ?? '', $allowedRoles, true)) {
            throw new InvalidArgumentException('Akses ditolak. Hanya petugas/operator atau admin yang dapat memproses pencetakan KIA.');
        }

        $statusCetakBaru = strtolower(trim($statusCetakBaru));
        if (!in_array($statusCetakBaru, ['menunggu_cetak', 'sedang_dicetak', 'selesai_dicetak'], true)) {
            throw new InvalidArgumentException("Status cetak tidak valid: {$statusCetakBaru}.");
        }

        $this->ensureCetakKiaTables();

        // Cek ketersediaan bahan cetak sebelum memproses / menyelesaikan cetak
        if (in_array($statusCetakBaru, ['sedang_dicetak', 'selesai_dicetak'], true)) {
            $pengaturan = $this->getPengaturanCetakKia();
            if (!$pengaturan['layanan_cetak_aktif']) {
                $bahanStr = implode(', ', $pengaturan['bahan_kosong']);
                throw new InvalidArgumentException("Tidak dapat memproses atau menyelesaikan pencetakan fisik KIA karena bahan cetak sedang tidak tersedia ({$bahanStr}).");
            }
        }

        $stmtP = $this->db->prepare("SELECT id, id_user, no_kk, status FROM tb_pengajuan_kia WHERE id = ?");
        $stmtP->bind_param('i', $idPengajuan);
        $stmtP->execute();
        $pRow = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();

        if (!$pRow) {
            throw new RuntimeException("Pengajuan KIA #{$idPengajuan} tidak ditemukan.");
        }

        $existing = $this->getCetakKiaByPengajuan($idPengajuan);
        if (!$existing) {
            throw new RuntimeException("Belum ada permintaan cetak fisik KIA untuk pengajuan #{$idPengajuan}.");
        }

        $catatanOperator = trim($catatanOperator);
        $catatanVal = $catatanOperator !== '' ? $catatanOperator : $existing['catatan_operator'];
        $actorId = !empty($actor['id']) ? (int)$actor['id'] : null;
        $actorNama = trim((string)($actor['nama'] ?? 'Petugas Operator'));

        $this->db->begin_transaction();
        try {
            if ($statusCetakBaru === 'sedang_dicetak') {
                $stmtUp = $this->db->prepare("UPDATE tb_cetak_kia
                    SET status_cetak = 'sedang_dicetak',
                        catatan_operator = ?,
                        processed_by = ?,
                        processed_by_nama = ?,
                        processed_at = COALESCE(processed_at, NOW()),
                        updated_at = NOW()
                    WHERE id_pengajuan = ?");
                $stmtUp->bind_param('sisi', $catatanVal, $actorId, $actorNama, $idPengajuan);
            } elseif ($statusCetakBaru === 'selesai_dicetak') {
                $stmtUp = $this->db->prepare("UPDATE tb_cetak_kia
                    SET status_cetak = 'selesai_dicetak',
                        catatan_operator = ?,
                        processed_by = ?,
                        processed_by_nama = ?,
                        processed_at = COALESCE(processed_at, NOW()),
                        completed_at = NOW(),
                        updated_at = NOW()
                    WHERE id_pengajuan = ?");
                $stmtUp->bind_param('sisi', $catatanVal, $actorId, $actorNama, $idPengajuan);
            } else {
                $stmtUp = $this->db->prepare("UPDATE tb_cetak_kia
                    SET status_cetak = 'menunggu_cetak',
                        catatan_operator = ?,
                        processed_by = ?,
                        processed_by_nama = ?,
                        updated_at = NOW()
                    WHERE id_pengajuan = ?");
                $stmtUp->bind_param('sisi', $catatanVal, $actorId, $actorNama, $idPengajuan);
            }

            if (!$stmtUp->execute()) {
                throw new RuntimeException('Gagal memperbarui status cetak KIA: ' . $stmtUp->error);
            }
            $stmtUp->close();

            // Log ke history
            $histAlasan = match ($statusCetakBaru) {
                'sedang_dicetak' => 'Kartu fisik KIA sedang dicetak oleh petugas' . ($catatanOperator !== '' ? ": {$catatanOperator}" : ''),
                'selesai_dicetak' => 'Kartu fisik KIA selesai dicetak dan siap diambil' . ($catatanOperator !== '' ? ": {$catatanOperator}" : ''),
                default => 'Status cetak fisik KIA dikembalikan ke Menunggu Cetak'
            };

            $this->logStatusHistory(
                sourceTable: 'tb_pengajuan_kia',
                permohonanId: $idPengajuan,
                status: (string)$pRow['status'],
                alasan: $histAlasan,
                actorId: (int)($actorId ?? 0),
                actorRole: (string)($actor['role'] ?? 'operator'),
                eventType: 'cetak_kia_' . $statusCetakBaru,
                meta: ['status_cetak' => $statusCetakBaru, 'catatan_operator' => $catatanVal]
            );

            // Kirim notifikasi ke pemohon
            $ownerId = (int)$pRow['id_user'];
            if ($ownerId > 0) {
                if ($statusCetakBaru === 'sedang_dicetak') {
                    $this->kirimNotifikasi(
                        userId: $ownerId,
                        judul: 'Kartu KIA Sedang Dicetak',
                        pesan: "Kartu fisik KIA Anda (No. KK: {$pRow['no_kk']}) sedang dalam proses pencetakan oleh petugas.",
                        tipe: 'info',
                        permohonanId: $idPengajuan,
                        targetUrl: "user/status_permohonan.php"
                    );
                } elseif ($statusCetakBaru === 'selesai_dicetak') {
                    $pesanSelesai = "Kartu fisik KIA Anda (No. KK: {$pRow['no_kk']}) telah selesai dicetak dan siap diambil di kantor pelayanan.";
                    if ($catatanOperator !== '') {
                        $pesanSelesai .= " Catatan petugas: {$catatanOperator}";
                    }
                    $this->kirimNotifikasi(
                        userId: $ownerId,
                        judul: 'Kartu Fisik KIA Selesai Dicetak',
                        pesan: $pesanSelesai,
                        tipe: 'success',
                        permohonanId: $idPengajuan,
                        targetUrl: "user/status_permohonan.php"
                    );
                }
            }

            $this->db->commit();

            // Notifikasi WhatsApp KHUSUS Pemohon setelah transaksi DB berhasil commit
            if ($ownerId > 0 && $statusCetakBaru === 'selesai_dicetak') {
                $this->sendWhatsAppToPemohon(
                    userId: $ownerId,
                    permohonanId: $idPengajuan,
                    eventType: 'kia_selesai_dicetak',
                    pesan: "Halo {NAMA}, hasil cetak KIA Anda (#{$idPengajuan}) sudah siap diambil. Silakan datang ke kantor Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo untuk melakukan pengambilan."
                );
            }

            return $this->getCetakKiaByPengajuan($idPengajuan) ?? [
                'id_pengajuan' => $idPengajuan,
                'status_cetak' => $statusCetakBaru
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // daftar antrean & riwayat pencetakan fisik KIA untuk Operator/Admin
    public function getDaftarCetakKia(array $filter = []): array
    {
        $this->ensureCetakKiaTables();

        $where = ['1=1'];
        $params = [];
        $types = '';

        if (!empty($filter['status_cetak'])) {
            $where[] = 'c.status_cetak = ?';
            $params[] = (string)$filter['status_cetak'];
            $types .= 's';
        }

        if (!empty($filter['id_kecamatan'])) {
            $where[] = 'p.id_kecamatan = ?';
            $params[] = (int)$filter['id_kecamatan'];
            $types .= 'i';
        }

        if (!empty($filter['kecamatan_ids']) && is_array($filter['kecamatan_ids'])) {
            $cleanIds = array_values(array_filter(array_map('intval', $filter['kecamatan_ids']), fn($v) => $v > 0));
            if (!empty($cleanIds)) {
                $where[] = 'p.id_kecamatan IN (' . implode(',', $cleanIds) . ')';
            }
        }

        $whereSql = implode(' AND ', $where);
        $sql = "SELECT c.*,
                       p.no_kk, p.status AS status_pengajuan, p.dokumen_kia_pdf, p.id_kecamatan, p.id_desa,
                       u.nama AS nama_pemohon, u.nik AS nik_pemohon, u.no_hp,
                       k.nama_kecamatan, d.nama_desa,
                       (SELECT GROUP_CONCAT(CONCAT(a.nama_anak, ' (', a.nik_anak, ')') SEPARATOR ', ')
                        FROM tb_anak_kia a WHERE a.id_pengajuan = p.id) AS daftar_anak
                FROM tb_cetak_kia c
                JOIN tb_pengajuan_kia p ON c.id_pengajuan = p.id
                JOIN tb_users u ON c.id_user = u.id
                LEFT JOIN tb_kecamatan k ON p.id_kecamatan = k.id
                LEFT JOIN tb_desa d ON p.id_desa = d.id
                WHERE {$whereSql}
                ORDER BY FIELD(c.status_cetak, 'menunggu_cetak', 'sedang_dicetak', 'selesai_dicetak'), c.requested_at ASC, c.id DESC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$r) {
            $sc = (string)$r['status_cetak'];
            $r['label_status_cetak'] = match ($sc) {
                'menunggu_cetak' => 'Menunggu Cetak',
                'sedang_dicetak' => 'Sedang Dicetak',
                'selesai_dicetak' => 'Selesai Dicetak (Siap Diambil)',
                default => ucfirst(str_replace('_', ' ', $sc))
            };
        }
        unset($r);

        return $rows;
    }

    // data detail responden dan jawaban per jenis survei (SKM, SPKP, atau SPAK secara TERPISAH)
    public function getSurveiExportData(string $jenisSurvei, string $periode = 'semua', ?string $customStart = null, ?string $customEnd = null): array
    {
        $this->ensureSurveiSaranColumn();

        $jenis = strtolower(trim($jenisSurvei));
        if (!in_array($jenis, ['skm', 'spkp', 'spak'], true)) {
            throw new InvalidArgumentException("Jenis survei untuk ekspor Excel tidak valid: {$jenisSurvei}. Pilihan: skm, spkp, spak.");
        }

        $whereSql = '';
        $params = [$jenis];
        $types = 's';

        switch ($periode) {
            case 'semua':
                $whereSql = '1=1';
                break;
            case 'kemarin':
                $whereSql = "s.tanggal_survei = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                break;
            case '7_hari':
                $whereSql = "s.tanggal_survei >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'bulan_ini':
                $whereSql = "YEAR(s.tanggal_survei) = YEAR(CURDATE()) AND MONTH(s.tanggal_survei) = MONTH(CURDATE())";
                break;
            case 'bulan_sebelumnya':
                $whereSql = "s.tanggal_survei >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND s.tanggal_survei < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
                break;
            case 'custom':
                if (empty($customStart) || empty($customEnd)) {
                    throw new InvalidArgumentException("Periode custom membutuhkan customStart dan customEnd (format YYYY-MM-DD).");
                }
                $whereSql = "s.tanggal_survei BETWEEN ? AND ?";
                $params[] = $customStart;
                $params[] = $customEnd;
                $types .= 'ss';
                break;
            case 'hari_ini':
            default:
                $periode = 'hari_ini';
                $whereSql = "s.tanggal_survei = CURDATE()";
                break;
        }

        $questionCodes = match ($jenis) {
            'skm' => ['U1', 'U2', 'U3', 'U4', 'U5', 'U6', 'U7', 'U8', 'U9'],
            'spkp' => ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8'],
            'spak' => ['A1', 'A2', 'A3', 'A4', 'A5']
        };

        $sql = "SELECT s.*, p.no_kk, k.nama_kecamatan, d.nama_desa
                FROM tb_survei_kia s
                LEFT JOIN tb_pengajuan_kia p ON s.id_pengajuan = p.id
                LEFT JOIN tb_kecamatan k ON p.id_kecamatan = k.id
                LEFT JOIN tb_desa d ON p.id_desa = d.id
                WHERE s.jenis_survei = ? AND {$whereSql}
                ORDER BY s.tanggal_survei DESC, s.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $surveiRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Ambil seluruh jawaban untuk id_survei yang ditemukan
        $jawabanBySurvei = [];
        if (!empty($surveiRows)) {
            $ids = array_map(fn($r) => (int)$r['id'], $surveiRows);
            $idListSql = implode(',', $ids);
            $resJ = $this->db->query("SELECT id_survei, kode_pertanyaan, nilai_jawaban FROM tb_jawaban_survei_kia WHERE id_survei IN ({$idListSql})");
            if ($resJ) {
                while ($jr = $resJ->fetch_assoc()) {
                    $sid = (int)$jr['id_survei'];
                    $kode = strtoupper(trim((string)$jr['kode_pertanyaan']));
                    // Normalisasi kode K1..K5 menjadi A1..A5 jika jenis survei SPAK
                    if ($jenis === 'spak' && preg_match('/^K([1-5])$/', $kode, $m)) {
                        $kode = 'A' . $m[1];
                    }
                    $jawabanBySurvei[$sid][$kode] = (int)$jr['nilai_jawaban'];
                }
            }
        }

        $respondenList = [];
        $sumPerCode = array_fill_keys($questionCodes, 0);
        $countPerCode = array_fill_keys($questionCodes, 0);
        $totalNilaiAll = 0;
        $totalCountAll = 0;

        foreach ($surveiRows as $sr) {
            $sid = (int)$sr['id'];
            $ansMap = $jawabanBySurvei[$sid] ?? [];
            $rowScores = [];
            $rowSum = 0;
            $rowCnt = 0;
            foreach ($questionCodes as $qCode) {
                $val = $ansMap[$qCode] ?? null;
                $rowScores[$qCode] = $val;
                if ($val !== null) {
                    $sumPerCode[$qCode] += $val;
                    $countPerCode[$qCode]++;
                    $rowSum += $val;
                    $rowCnt++;
                    $totalNilaiAll += $val;
                    $totalCountAll++;
                }
            }
            $rataResponden = $rowCnt > 0 ? round($rowSum / $rowCnt, 2) : 0.0;
            $nilaiKonversiResponden = ($jenis === 'skm')
                ? round($rataResponden * 25, 2)
                : round(($rataResponden / 6) * 100, 2);

            $respondenList[] = [
                'id_survei' => $sid,
                'id_pengajuan' => (int)$sr['id_pengajuan'],
                'no_kk' => (string)($sr['no_kk'] ?? '-'),
                'tanggal_survei' => (string)$sr['tanggal_survei'],
                'waktu_pengisian' => (string)$sr['waktu_pengisian'],
                'nama_lengkap' => (string)$sr['nama_lengkap'],
                'no_hp' => (string)$sr['no_hp'],
                'email' => (string)$sr['email'],
                'jenis_kelamin' => (string)$sr['jenis_kelamin'],
                'usia' => (int)$sr['usia'],
                'pendidikan' => (string)$sr['pendidikan'],
                'pekerjaan' => (string)$sr['pekerjaan'],
                'alamat' => (string)($sr['alamat'] ?? '-'),
                'nama_kecamatan' => (string)($sr['nama_kecamatan'] ?? ''),
                'nama_desa' => (string)($sr['nama_desa'] ?? ''),
                'saran' => (string)($sr['saran'] ?? ''),
                'jawaban' => $rowScores,
                'rata_rata' => $rataResponden,
                'nilai_konversi' => $nilaiKonversiResponden
            ];
        }

        $rataPerPertanyaan = [];
        $konversiPerPertanyaan = [];
        foreach ($questionCodes as $qCode) {
            $avgQ = $countPerCode[$qCode] > 0 ? round($sumPerCode[$qCode] / $countPerCode[$qCode], 2) : 0.0;
            $rataPerPertanyaan[$qCode] = $avgQ;
            $konversiPerPertanyaan[$qCode] = ($jenis === 'skm')
                ? round($avgQ * 25, 2)
                : round(($avgQ / 6) * 100, 2);
        }

        $totalResponden = count($respondenList);
        $rataKeseluruhan = $totalCountAll > 0 ? round($totalNilaiAll / $totalCountAll, 2) : 0.0;

        if ($jenis === 'skm') {
            $nilaiAkhir = $totalResponden > 0 ? round($rataKeseluruhan * 25, 2) : 0.0;
            $mutu = '-';
            $kategori = 'Belum Ada Data';
            if ($totalResponden > 0) {
                if ($nilaiAkhir >= 88.31) {
                    $mutu = 'A';
                    $kategori = 'Sangat Baik';
                } elseif ($nilaiAkhir >= 76.61) {
                    $mutu = 'B';
                    $kategori = 'Baik';
                } elseif ($nilaiAkhir >= 65.00) {
                    $mutu = 'C';
                    $kategori = 'Kurang Baik';
                } else {
                    $mutu = 'D';
                    $kategori = 'Tidak Baik';
                }
            }
        } else {
            $nilaiAkhir = $totalResponden > 0 ? round(($rataKeseluruhan / 6) * 100, 2) : 0.0;
            $mutu = '-';
            if ($totalResponden === 0) {
                $kategori = 'Belum Ada Data';
            } elseif ($nilaiAkhir >= 85.0) {
                $kategori = 'Sangat Baik';
            } elseif ($nilaiAkhir >= 70.0) {
                $kategori = 'Baik';
            } elseif ($nilaiAkhir >= 55.0) {
                $kategori = 'Cukup';
            } else {
                $kategori = 'Kurang';
            }
        }

        return [
            'jenis_survei' => $jenis,
            'judul_survei' => match ($jenis) {
                'skm' => 'Survei Kepuasan Masyarakat (SKM)',
                'spkp' => 'Survei Persepsi Kualitas Pelayanan (SPKP)',
                'spak' => 'Survei Persepsi Anti Korupsi (SPAK)'
            },
            'periode' => $periode,
            'custom_start' => $customStart,
            'custom_end' => $customEnd,
            'question_codes' => $questionCodes,
            'total_responden' => $totalResponden,
            'responden' => $respondenList,
            'rata_per_pertanyaan' => $rataPerPertanyaan,
            'konversi_per_pertanyaan' => $konversiPerPertanyaan,
            'rata_keseluruhan' => $rataKeseluruhan,
            'nilai_akhir' => $nilaiAkhir,
            'mutu_pelayanan' => $mutu,
            'kategori_kinerja' => $kategori
        ];
    }

    // menghasilkan binary file Excel (.xlsx OpenXML Spreadsheet) murni untuk 1 jenis survei (SKM, SPKP, atau SPAK)
    public function generateSurveiExcelXlsx(string $jenisSurvei, string $periode = 'semua', ?string $customStart = null, ?string $customEnd = null): string
    {
        $export = $this->getSurveiExportData($jenisSurvei, $periode, $customStart, $customEnd);
        $jenis = $export['jenis_survei'];
        $qCodes = $export['question_codes'];

        $periodeLabels = [
            'hari_ini' => 'Hari Ini (' . date('d M Y') . ')',
            'kemarin' => 'Kemarin',
            '7_hari' => '7 Hari Terakhir',
            'bulan_ini' => 'Bulan Ini (' . date('F Y') . ')',
            'bulan_sebelumnya' => 'Bulan Sebelumnya',
            'semua' => 'Semua Waktu',
            'custom' => 'Rentang Tanggal (' . ($customStart ?? '-') . ' s/d ' . ($customEnd ?? '-') . ')'
        ];
        $labelPeriode = $periodeLabels[$export['periode']] ?? $export['periode'];

        $rows = [];
        // Judul Laporan
        $rows[] = ['value' => ['LAPORAN HASIL ' . strtoupper($export['judul_survei']) . ' - LAYANAN KIA SIPRIMA'], 'style' => 1];
        $rows[] = ['value' => ['Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo'], 'style' => 2];
        $rows[] = ['value' => ['Periode Filter: ' . $labelPeriode . ' | Dicetak: ' . date('d/m/Y H:i:s')], 'style' => 0];
        $rows[] = ['value' => [], 'style' => 0];

        // Ringkasan Eksekutif
        $rows[] = ['value' => ['RINGKASAN PERHITUNGAN ' . strtoupper($jenis)], 'style' => 2];
        $rows[] = ['value' => ['Total Responden ' . strtoupper($jenis), $export['total_responden']], 'style' => 0];
        if ($jenis === 'skm') {
            $rows[] = ['value' => ['Rata-Rata Skor (Skala 1 - 4)', $export['rata_keseluruhan']], 'style' => 0];
            $rows[] = ['value' => ['Nilai Indeks Kepuasan Masyarakat (IKM = Rata-Rata x 25)', $export['nilai_akhir']], 'style' => 0];
            $rows[] = ['value' => ['Mutu Pelayanan', $export['mutu_pelayanan']], 'style' => 0];
            $rows[] = ['value' => ['Kinerja Unit Pelayanan', $export['kategori_kinerja']], 'style' => 0];
        } else {
            $rows[] = ['value' => ['Rata-Rata Skor (Skala 1 - 6)', $export['rata_keseluruhan']], 'style' => 0];
            $rows[] = ['value' => ['Nilai Indeks Persentase ((Rata-Rata / 6) x 100%)', $export['nilai_akhir'] . '%'], 'style' => 0];
            $rows[] = ['value' => ['Kategori / Predikat', $export['kategori_kinerja']], 'style' => 0];
        }
        $rows[] = ['value' => [], 'style' => 0];

        // Header Tabel Responden & Jawaban Per Pertanyaan
        $headerCols = [
            'No',
            'Tanggal Survei',
            'Waktu Pengisian',
            'ID Permohonan',
            'Nomor KK',
            'Nama Responden',
            'No. HP',
            'Email',
            'Jenis Kelamin',
            'Usia (Thn)',
            'Pendidikan Terakhir',
            'Pekerjaan',
            'Alamat'
        ];
        foreach ($qCodes as $qc) {
            $headerCols[] = $qc;
        }
        $headerCols[] = ($jenis === 'skm') ? 'Rata-Rata (1-4)' : 'Rata-Rata (1-6)';
        $headerCols[] = ($jenis === 'skm') ? 'Nilai IKM (x25)' : 'Skor Persen (%)';
        $headerCols[] = 'Saran / Masukan';

        $rows[] = ['value' => $headerCols, 'style' => 3];

        if (empty($export['responden'])) {
            $rows[] = ['value' => ['Belum ada data responden ' . strtoupper($jenis) . ' pada periode ini.'], 'style' => 0];
        } else {
            foreach ($export['responden'] as $idx => $resp) {
                $rLine = [
                    $idx + 1,
                    $resp['tanggal_survei'],
                    $resp['waktu_pengisian'],
                    '#' . $resp['id_pengajuan'],
                    $resp['no_kk'],
                    $resp['nama_lengkap'],
                    $resp['no_hp'],
                    $resp['email'],
                    $resp['jenis_kelamin'] === 'L' ? 'Laki-laki' : ($resp['jenis_kelamin'] === 'P' ? 'Perempuan' : $resp['jenis_kelamin']),
                    $resp['usia'],
                    $resp['pendidikan'],
                    $resp['pekerjaan'],
                    $resp['alamat']
                ];
                foreach ($qCodes as $qc) {
                    $rLine[] = $resp['jawaban'][$qc] ?? '-';
                }
                $rLine[] = $resp['rata_rata'];
                $rLine[] = $resp['nilai_konversi'];
                $rLine[] = $resp['saran'] !== '' ? $resp['saran'] : '-';
                $rows[] = ['value' => $rLine, 'style' => 4];
            }

            // Footer Baris Rata-Rata Per Pertanyaan
            $avgLine = array_fill(0, 12, '');
            $avgLine[0] = 'RATA-RATA PER PERTANYAAN';
            $avgLine[12] = ($jenis === 'skm') ? 'NRR per Unsur:' : 'Rata-Rata Unsur:';
            foreach ($qCodes as $qc) {
                $avgLine[] = $export['rata_per_pertanyaan'][$qc] ?? 0.0;
            }
            $avgLine[] = $export['rata_keseluruhan'];
            $avgLine[] = $export['nilai_akhir'];
            $avgLine[] = $export['kategori_kinerja'];
            $rows[] = ['value' => $avgLine, 'style' => 5];

            // Footer Baris Konversi Per Pertanyaan
            $convLine = array_fill(0, 12, '');
            $convLine[0] = ($jenis === 'skm') ? 'NILAI KONVERSI (x25)' : 'SKOR PERSENTASE (/6 x 100)';
            $convLine[12] = ($jenis === 'skm') ? 'IKM per Unsur:' : 'Persen per Unsur:';
            foreach ($qCodes as $qc) {
                $convLine[] = $export['konversi_per_pertanyaan'][$qc] ?? 0.0;
            }
            $convLine[] = '';
            $convLine[] = $export['nilai_akhir'];
            $convLine[] = ($jenis === 'skm') ? ('Mutu: ' . $export['mutu_pelayanan'] . ' (' . $export['kategori_kinerja'] . ')') : $export['kategori_kinerja'];
            $rows[] = ['value' => $convLine, 'style' => 5];
        }

        $sheetName = 'Hasil_' . strtoupper($jenis);
        return $this->buildXlsxFromRows($sheetName, $rows);
    }

    /**
     * Mengambil data analitik lengkap survei (daftar responden, demografi unik, perbandingan skor per demografi, dan rincian jawaban).
     */
    public function getSurveiAnalyticsData(
        string $periode = 'semua',
        ?string $customStart = null,
        ?string $customEnd = null,
        string $jenisSurveiFilter = 'semua',
        string $jenisLayananFilter = 'semua'
    ): array {
        $jenisSurveiFilter = strtolower(trim($jenisSurveiFilter));
        if (!in_array($jenisSurveiFilter, ['semua', 'skm', 'spkp', 'spak'], true)) {
            $jenisSurveiFilter = 'semua';
        }
        $jenisLayananFilter = strtoupper(trim($jenisLayananFilter));
        if ($jenisLayananFilter === '' || $jenisLayananFilter === 'SEMUA') {
            $jenisLayananFilter = 'semua';
        }

        $whereParts = ["s.status = 'selesai'"];
        $params = [];
        $types = '';

        switch ($periode) {
            case 'semua':
                break;
            case '7_hari':
                $whereParts[] = "s.tanggal_survei >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
                break;
            case 'bulan_ini':
                $whereParts[] = "YEAR(s.tanggal_survei) = YEAR(CURDATE()) AND MONTH(s.tanggal_survei) = MONTH(CURDATE())";
                break;
            case 'bulan_sebelumnya':
                $whereParts[] = "YEAR(s.tanggal_survei) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(s.tanggal_survei) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
                break;
            case 'custom':
                if (!empty($customStart) && !empty($customEnd)) {
                    $whereParts[] = "s.tanggal_survei BETWEEN ? AND ?";
                    $params[] = $customStart;
                    $params[] = $customEnd;
                    $types .= 'ss';
                }
                break;
            case 'hari_ini':
            default:
                $whereParts[] = "s.tanggal_survei = CURDATE()";
                break;
        }

        if ($jenisSurveiFilter !== 'semua') {
            $whereParts[] = "s.jenis_survei = ?";
            $params[] = $jenisSurveiFilter;
            $types .= 's';
        }

        // Saat ini tabel tb_survei_kia khusus menyimpan survei layanan KIA
        if ($jenisLayananFilter !== 'semua' && $jenisLayananFilter !== 'KIA') {
            $whereParts[] = "1 = 0";
        }

        $whereSql = implode(' AND ', $whereParts);
        $sql = "SELECT s.*, p.no_kk, k.nama_kecamatan, d.nama_desa
                FROM tb_survei_kia s
                LEFT JOIN tb_pengajuan_kia p ON s.id_pengajuan = p.id
                LEFT JOIN tb_kecamatan k ON p.id_kecamatan = k.id
                LEFT JOIN tb_desa d ON p.id_desa = d.id
                WHERE {$whereSql}
                ORDER BY s.waktu_pengisian DESC, s.id DESC";

        $stmt = $this->db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $surveiRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $questionMeta = [
            'skm' => [
                'U1' => 'Persyaratan Pelayanan',
                'U2' => 'Sistem, Mekanisme, dan Prosedur',
                'U3' => 'Waktu Penyelesaian Pelayanan',
                'U4' => 'Biaya / Tarif Pelayanan (Gratis)',
                'U5' => 'Produk Spesifikasi Jenis Pelayanan',
                'U6' => 'Kompetensi Pelaksana',
                'U7' => 'Perilaku Pelaksana',
                'U8' => 'Penanganan Pengaduan, Saran, dan Masukan',
                'U9' => 'Kualitas Sarana dan Prasarana',
            ],
            'spkp' => [
                'P1' => 'Informasi Layanan',
                'P2' => 'Kemudahan Persyaratan',
                'P3' => 'Kejelasan Prosedur',
                'P4' => 'Ketepatan Waktu',
                'P5' => 'Kesesuaian Biaya (Gratis)',
                'P6' => 'Kualitas Hasil KIA',
                'P7' => 'Sikap & Keramahan Petugas',
                'P8' => 'Fasilitas & Sistem Online',
            ],
            'spak' => [
                'A1' => 'Tidak Adanya Praktik Pungutan Liar (Pungli)',
                'A2' => 'Tidak Adanya Praktik Calo / Perantara Liar',
                'A3' => 'Petugas Tidak Meminta / Mengharapkan Imbalan',
                'A4' => 'Tidak Adanya Perlakuan Khusus / Diskriminatif',
                'A5' => 'Kemudahan Akses Tanpa Biaya Tambahan',
            ],
        ];

        $jawabanBySurvei = [];
        if (!empty($surveiRows)) {
            $ids = array_map(fn($r) => (int)$r['id'], $surveiRows);
            $idListSql = implode(',', $ids);
            $resJ = $this->db->query("SELECT id_survei, kode_pertanyaan, nilai_jawaban FROM tb_jawaban_survei_kia WHERE id_survei IN ({$idListSql}) ORDER BY id ASC");
            if ($resJ) {
                while ($jr = $resJ->fetch_assoc()) {
                    $sid = (int)$jr['id_survei'];
                    $kode = strtoupper(trim((string)$jr['kode_pertanyaan']));
                    $jawabanBySurvei[$sid][$kode] = (int)$jr['nilai_jawaban'];
                }
            }
        }

        $classifyUsia = static function (int $usia): string {
            if ($usia <= 0) {
                return 'Tidak Diisi';
            }
            if ($usia < 20) {
                return '< 20 Tahun';
            }
            if ($usia <= 29) {
                return '20 - 29 Tahun';
            }
            if ($usia <= 39) {
                return '30 - 39 Tahun';
            }
            if ($usia <= 49) {
                return '40 - 49 Tahun';
            }
            return '50+ Tahun';
        };

        $formatJk = static function (?string $jk): string {
            $v = strtoupper(trim((string)$jk));
            if ($v === 'L') {
                return 'Laki-Laki';
            }
            if ($v === 'P') {
                return 'Perempuan';
            }
            return 'Tidak Diisi';
        };

        $uniqueRespMap = [];
        $respondenByJenisKeys = ['skm' => [], 'spkp' => [], 'spak' => []];
        $daftarResponden = [];
        $rincianJawaban = [];

        // Struktur agregasi perbandingan skor per demografi & per jenis survei (skala tidak dicampur)
        $dimKeys = ['jenis_kelamin', 'pendidikan', 'kelompok_usia', 'pekerjaan'];
        $perbandinganRaw = [];
        foreach (['skm', 'spkp', 'spak'] as $jType) {
            foreach ($dimKeys as $dim) {
                $perbandinganRaw[$jType][$dim] = [];
            }
        }

        foreach ($surveiRows as $sr) {
            $sid = (int)$sr['id'];
            $idPengajuan = (int)($sr['id_pengajuan'] ?? 0);
            $respKey = $idPengajuan > 0 ? ('p_' . $idPengajuan) : ('s_' . $sid);
            $jType = strtolower(trim((string)$sr['jenis_survei']));
            if (!isset($questionMeta[$jType])) {
                continue;
            }

            $ansRaw = $jawabanBySurvei[$sid] ?? [];
            $ansNormalized = [];
            $sumScore = 0;
            $cntScore = 0;
            foreach ($questionMeta[$jType] as $qCode => $qLabel) {
                $val = $ansRaw[$qCode] ?? null;
                if ($val === null && $jType === 'spak' && preg_match('/^A([1-5])$/', $qCode, $m)) {
                    $val = $ansRaw['K' . $m[1]] ?? null;
                }
                if ($val !== null) {
                    $valInt = (int)$val;
                    $ansNormalized[$qCode] = $valInt;
                    $sumScore += $valInt;
                    $cntScore++;

                    $rincianJawaban[] = [
                        'id_survei' => $sid,
                        'id_pengajuan' => $idPengajuan,
                        'nama_lengkap' => (string)($sr['nama_lengkap'] ?? '-'),
                        'jenis_survei' => strtoupper($jType),
                        'kode_pertanyaan' => $qCode,
                        'pertanyaan' => $qLabel,
                        'nilai_jawaban' => $valInt,
                        'tanggal_survei' => (string)($sr['tanggal_survei'] ?? '-'),
                    ];
                }
            }

            $rataSkor = $cntScore > 0 ? round($sumScore / $cntScore, 2) : 0.0;
            $indeksPersen = ($jType === 'skm')
                ? round($rataSkor * 25, 2)
                : round(($rataSkor / 6) * 100, 2);

            $kategori = '-';
            if ($cntScore > 0) {
                if ($jType === 'skm') {
                    if ($indeksPersen >= 88.31) $kategori = 'A (Sangat Baik)';
                    elseif ($indeksPersen >= 76.61) $kategori = 'B (Baik)';
                    elseif ($indeksPersen >= 65.00) $kategori = 'C (Kurang Baik)';
                    else $kategori = 'D (Tidak Baik)';
                } else {
                    if ($indeksPersen >= 85.0) $kategori = 'Sangat Baik';
                    elseif ($indeksPersen >= 70.0) $kategori = 'Baik';
                    elseif ($indeksPersen >= 55.0) $kategori = 'Cukup';
                    else $kategori = 'Perlu Perbaikan';
                }
            }

            $usiaInt = (int)($sr['usia'] ?? 0);
            $jkLabel = $formatJk($sr['jenis_kelamin'] ?? '');
            $pendLabel = trim((string)($sr['pendidikan'] ?? ''));
            if ($pendLabel === '') $pendLabel = 'Tidak Diisi';
            $pekLabel = trim((string)($sr['pekerjaan'] ?? ''));
            if ($pekLabel === '') $pekLabel = 'Tidak Diisi';
            $usiaGroup = $classifyUsia($usiaInt);

            if (!isset($uniqueRespMap[$respKey])) {
                $uniqueRespMap[$respKey] = [
                    'resp_key' => $respKey,
                    'id_pengajuan' => $idPengajuan,
                    'nama_lengkap' => (string)($sr['nama_lengkap'] ?? '-'),
                    'jenis_kelamin' => $jkLabel,
                    'usia' => $usiaInt,
                    'kelompok_usia' => $usiaGroup,
                    'pendidikan' => $pendLabel,
                    'pekerjaan' => $pekLabel,
                    'nama_kecamatan' => (string)($sr['nama_kecamatan'] ?? '-'),
                    'nama_desa' => (string)($sr['nama_desa'] ?? '-'),
                ];
            }
            $respondenByJenisKeys[$jType][$respKey] = true;

            $dimValues = [
                'jenis_kelamin' => $jkLabel,
                'pendidikan' => $pendLabel,
                'kelompok_usia' => $usiaGroup,
                'pekerjaan' => $pekLabel,
            ];
            foreach ($dimValues as $dim => $grpLabel) {
                if (!isset($perbandinganRaw[$jType][$dim][$grpLabel])) {
                    $perbandinganRaw[$jType][$dim][$grpLabel] = [
                        'resp_keys' => [],
                        'sum_skor' => 0,
                        'cnt_skor' => 0,
                    ];
                }
                $perbandinganRaw[$jType][$dim][$grpLabel]['resp_keys'][$respKey] = true;
                $perbandinganRaw[$jType][$dim][$grpLabel]['sum_skor'] += $sumScore;
                $perbandinganRaw[$jType][$dim][$grpLabel]['cnt_skor'] += $cntScore;
            }

            $daftarResponden[] = [
                'id_survei' => $sid,
                'id_pengajuan' => $idPengajuan,
                'no_kk' => (string)($sr['no_kk'] ?? '-'),
                'nama_lengkap' => (string)($sr['nama_lengkap'] ?? '-'),
                'no_hp' => (string)($sr['no_hp'] ?? '-'),
                'email' => (string)($sr['email'] ?? '-'),
                'jenis_kelamin' => $jkLabel,
                'jenis_kelamin_kode' => strtoupper(trim((string)($sr['jenis_kelamin'] ?? ''))),
                'usia' => $usiaInt,
                'kelompok_usia' => $usiaGroup,
                'pendidikan' => $pendLabel,
                'pekerjaan' => $pekLabel,
                'alamat' => trim((string)($sr['alamat'] ?? '')) !== '' ? (string)$sr['alamat'] : '-',
                'nama_kecamatan' => trim((string)($sr['nama_kecamatan'] ?? '')) !== '' ? (string)$sr['nama_kecamatan'] : '-',
                'nama_desa' => trim((string)($sr['nama_desa'] ?? '')) !== '' ? (string)$sr['nama_desa'] : '-',
                'jenis_survei' => strtoupper($jType),
                'jenis_survei_kode' => $jType,
                'tanggal_survei' => (string)($sr['tanggal_survei'] ?? '-'),
                'waktu_pengisian' => (string)($sr['waktu_pengisian'] ?? '-'),
                'rata_skor' => $rataSkor,
                'indeks_persen' => $indeksPersen,
                'kategori' => $kategori,
                'saran' => trim((string)($sr['saran'] ?? '')) !== '' ? (string)$sr['saran'] : '-',
                'jawaban' => $ansNormalized,
                'pertanyaan_meta' => $questionMeta[$jType],
            ];
        }

        // Hitung demografi dari responden unik
        $totalUnik = count($uniqueRespMap);
        $demografi = [
            'jenis_kelamin' => [],
            'pendidikan' => [],
            'kelompok_usia' => [],
            'pekerjaan' => [],
        ];
        $ageOrder = ['< 20 Tahun', '20 - 29 Tahun', '30 - 39 Tahun', '40 - 49 Tahun', '50+ Tahun', 'Tidak Diisi'];

        $counts = ['jenis_kelamin' => [], 'pendidikan' => [], 'kelompok_usia' => [], 'pekerjaan' => []];
        foreach ($uniqueRespMap as $u) {
            foreach ($dimKeys as $dim) {
                $val = $u[$dim];
                $counts[$dim][$val] = ($counts[$dim][$val] ?? 0) + 1;
            }
        }
        foreach ($dimKeys as $dim) {
            if ($dim === 'kelompok_usia') {
                $ordered = [];
                foreach ($ageOrder as $ak) {
                    if (isset($counts[$dim][$ak])) {
                        $ordered[$ak] = $counts[$dim][$ak];
                    }
                }
                $counts[$dim] = $ordered;
            } else {
                arsort($counts[$dim]);
            }
            foreach ($counts[$dim] as $lbl => $cnt) {
                $demografi[$dim][] = [
                    'kategori' => $lbl,
                    'jumlah' => $cnt,
                    'persen' => $totalUnik > 0 ? round(($cnt / $totalUnik) * 100, 1) : 0.0,
                ];
            }
        }

        // Format hasil perbandingan skor per demografi & jenis survei
        $perbandingan = [];
        foreach (['skm', 'spkp', 'spak'] as $jType) {
            $perbandingan[$jType] = [];
            foreach ($dimKeys as $dim) {
                $rowsOut = [];
                $groups = $perbandinganRaw[$jType][$dim];
                if ($dim === 'kelompok_usia') {
                    $orderedGroups = [];
                    foreach ($ageOrder as $ak) {
                        if (isset($groups[$ak])) {
                            $orderedGroups[$ak] = $groups[$ak];
                        }
                    }
                    $groups = $orderedGroups;
                }
                $maxIndeks = -1.0;
                $tertinggi = '-';
                $minSampleGroup = PHP_INT_MAX;
                foreach ($groups as $grpLabel => $gData) {
                    $jmlResp = count($gData['resp_keys']);
                    if ($jmlResp < $minSampleGroup) {
                        $minSampleGroup = $jmlResp;
                    }
                    $avg = $gData['cnt_skor'] > 0 ? round($gData['sum_skor'] / $gData['cnt_skor'], 2) : 0.0;
                    $indeks = $gData['cnt_skor'] > 0
                        ? ($jType === 'skm' ? round($avg * 25, 2) : round(($avg / 6) * 100, 2))
                        : 0.0;
                    if ($indeks > $maxIndeks && $jmlResp > 0) {
                        $maxIndeks = $indeks;
                        $tertinggi = $grpLabel;
                    }
                    $rowsOut[] = [
                        'kelompok' => $grpLabel,
                        'jumlah_responden' => $jmlResp,
                        'rata_rata' => $avg,
                        'indeks' => $indeks,
                    ];
                }
                $totalRespJenis = count($respondenByJenisKeys[$jType]);
                $perbandingan[$jType][$dim] = [
                    'items' => $rowsOut,
                    'kelompok_tertinggi' => $tertinggi,
                    'indeks_tertinggi' => $maxIndeks >= 0 ? $maxIndeks : 0.0,
                    'total_responden' => $totalRespJenis,
                    'kurang_representatif' => ($totalRespJenis < 30 || $minSampleGroup < 5),
                ];
            }
        }

        return [
            'total_responden_unik' => $totalUnik,
            'total_pengisian' => count($daftarResponden),
            'responden_per_jenis' => [
                'skm' => count($respondenByJenisKeys['skm']),
                'spkp' => count($respondenByJenisKeys['spkp']),
                'spak' => count($respondenByJenisKeys['spak']),
            ],
            'demografi' => $demografi,
            'perbandingan' => $perbandingan,
            'daftar_responden' => $daftarResponden,
            'rincian_jawaban' => $rincianJawaban,
        ];
    }

    /**
     * Menghasilkan satu workbook Excel (.xlsx) multi-worksheet berisi seluruh hasil survei (7 sheet).
     */
    public function generateAllSurveiExcelXlsx(
        string $periode = 'semua',
        ?string $customStart = null,
        ?string $customEnd = null,
        string $jenisSurveiFilter = 'semua',
        string $jenisLayananFilter = 'semua'
    ): string {
        $summary = $this->getSurveiAdminSummary($periode, $customStart, $customEnd);
        $analytics = $this->getSurveiAnalyticsData($periode, $customStart, $customEnd, $jenisSurveiFilter, $jenisLayananFilter);

        $periodeLabels = [
            'semua' => 'Semua Waktu',
            'hari_ini' => 'Hari Ini (' . date('d/m/Y') . ')',
            '7_hari' => '7 Hari Terakhir',
            'bulan_ini' => 'Bulan Ini (' . date('F Y') . ')',
            'bulan_sebelumnya' => 'Bulan Sebelumnya (' . date('F Y', strtotime('first day of last month')) . ')',
            'custom' => 'Periode ' . ($customStart ?: '-') . ' s/d ' . ($customEnd ?: '-')
        ];
        $periodeText = $periodeLabels[$periode] ?? 'Semua Waktu';
        $printedAt = date('d/m/Y H:i:s');

        $skmData = $summary['skm'] ?? [];
        $spkpData = $summary['spkp'] ?? [];
        $spakData = $summary['spak'] ?? [];

        // 1. Sheet: Ringkasan Survei
        $sheet1 = [
            ['value' => ['LAPORAN GABUNGAN HASIL SURVEI MASYARAKAT - SIPRIMA'], 'style' => 1],
            ['value' => ['Dinas Kependudukan dan Pencatatan Sipil Kabupaten Gorontalo'], 'style' => 2],
            ['value' => ['Periode Filter: ' . $periodeText . ' | Jenis Survei: ' . strtoupper($jenisSurveiFilter) . ' | Layanan: ' . strtoupper($jenisLayananFilter) . ' | Dicetak: ' . $printedAt], 'style' => 0],
            ['value' => [], 'style' => 0],
            ['value' => ['RINGKASAN KESELURUHAN RESPONDEN'], 'style' => 2],
            ['value' => ['Metrik', 'Nilai', 'Keterangan'], 'style' => 3],
            ['value' => ['Total Responden Unik', (int)$analytics['total_responden_unik'], 'Dihitung unik per permohonan/sesi survei agar tidak terhitung ganda'], 'style' => 4],
            ['value' => ['Total Pengisian Survei', (int)$analytics['total_pengisian'], 'Total form survei (SKM, SPKP, SPAK) yang selesai diisi'], 'style' => 4],
            ['value' => ['Responden SKM', (int)($analytics['responden_per_jenis']['skm'] ?? 0), 'Skala 1 - 4'], 'style' => 4],
            ['value' => ['Responden SPKP', (int)($analytics['responden_per_jenis']['spkp'] ?? 0), 'Skala 1 - 6'], 'style' => 4],
            ['value' => ['Responden SPAK', (int)($analytics['responden_per_jenis']['spak'] ?? 0), 'Skala 1 - 6'], 'style' => 4],
            ['value' => [], 'style' => 0],
            ['value' => ['RINGKASAN INDEKS SETIAP JENIS SURVEI'], 'style' => 2],
            ['value' => ['Jenis Survei', 'Responden', 'Rata-Rata Skor', 'Indeks / Persentase', 'Mutu / Kategori'], 'style' => 3],
            ['value' => [
                'SKM (Kepuasan Masyarakat)',
                (int)($skmData['total_responden'] ?? 0),
                (float)($skmData['rata_rata'] ?? 0),
                (float)($skmData['nilai_skm'] ?? 0) . ' (IKM)',
                (string)($skmData['mutu_pelayanan'] ?? '-') . ' - ' . (string)($skmData['kinerja_pelayanan'] ?? '-'),
            ], 'style' => 4],
            ['value' => [
                'SPKP (Persepsi Kualitas Pelayanan)',
                (int)($spkpData['total_responden'] ?? 0),
                (float)($spkpData['rata_rata'] ?? 0),
                (float)($spkpData['skor_persen'] ?? 0) . '%',
                (float)($spkpData['skor_persen'] ?? 0) >= 85 ? 'Sangat Baik' : ((float)($spkpData['skor_persen'] ?? 0) >= 70 ? 'Baik' : ((float)($spkpData['skor_persen'] ?? 0) >= 55 ? 'Cukup' : 'Perlu Perbaikan')),
            ], 'style' => 4],
            ['value' => [
                'SPAK (Persepsi Anti Korupsi)',
                (int)($spakData['total_responden'] ?? 0),
                (float)($spakData['rata_rata'] ?? 0),
                (float)($spakData['skor_persen'] ?? 0) . '%',
                (float)($spakData['skor_persen'] ?? 0) >= 85 ? 'Sangat Baik' : ((float)($spakData['skor_persen'] ?? 0) >= 70 ? 'Baik' : ((float)($spakData['skor_persen'] ?? 0) >= 55 ? 'Cukup' : 'Perlu Perbaikan')),
            ], 'style' => 4],
        ];

        // 2. Sheet: Data Responden
        $sheet2 = [
            ['value' => ['DATA RESPONDEN SURVEI MASYARAKAT'], 'style' => 1],
            ['value' => ['No', 'Nama Responden', 'Nomor HP', 'Email', 'Jenis Kelamin', 'Usia', 'Pendidikan', 'Pekerjaan', 'Alamat', 'Kecamatan', 'Desa', 'Jenis Survei', 'Tanggal Pengisian', 'Rata-Rata Skor', 'Indeks / Persen'], 'style' => 3],
        ];
        $no = 1;
        foreach ($analytics['daftar_responden'] as $r) {
            $sheet2[] = [
                'value' => [
                    $no++,
                    $r['nama_lengkap'],
                    $r['no_hp'],
                    $r['email'],
                    $r['jenis_kelamin'],
                    $r['usia'] > 0 ? $r['usia'] : '-',
                    $r['pendidikan'],
                    $r['pekerjaan'],
                    $r['alamat'],
                    $r['nama_kecamatan'],
                    $r['nama_desa'],
                    $r['jenis_survei'],
                    $r['waktu_pengisian'],
                    $r['rata_skor'],
                    $r['indeks_persen'],
                ],
                'style' => 4,
            ];
        }
        if ($no === 1) {
            $sheet2[] = ['value' => ['-', 'Tidak ada data responden pada filter terpilih'], 'style' => 4];
        }

        // Helper map pertanyaan
        $mapPertanyaan = static function (array $list): array {
            $m = [];
            foreach ($list as $it) {
                $k = $it['kode_pertanyaan'] ?? '';
                if ($k !== '') $m[$k] = $it;
            }
            return $m;
        };

        // 3. Sheet: Hasil SKM
        $skmMeta = [
            'U1' => 'Persyaratan Pelayanan', 'U2' => 'Sistem, Mekanisme, dan Prosedur', 'U3' => 'Waktu Penyelesaian Pelayanan',
            'U4' => 'Biaya / Tarif Pelayanan (Gratis)', 'U5' => 'Produk Spesifikasi Jenis Pelayanan', 'U6' => 'Kompetensi Pelaksana',
            'U7' => 'Perilaku Pelaksana', 'U8' => 'Penanganan Pengaduan, Saran, dan Masukan', 'U9' => 'Kualitas Sarana dan Prasarana',
        ];
        $skmMap = $mapPertanyaan($skmData['pertanyaan'] ?? []);
        $sheet3 = [
            ['value' => ['HASIL SURVEI KEPUASAN MASYARAKAT (SKM) - 9 UNSUR'], 'style' => 1],
            ['value' => ['Total Responden SKM: ' . (int)($skmData['total_responden'] ?? 0) . ' | Rata-Rata: ' . (float)($skmData['rata_rata'] ?? 0) . ' | Nilai IKM: ' . (float)($skmData['nilai_skm'] ?? 0) . ' (' . ($skmData['mutu_pelayanan'] ?? '-') . ')'], 'style' => 2],
            ['value' => ['Kode Unsur', 'Unsur Pelayanan', 'Jumlah Responden', 'Rata-Rata (Skala 1-4)', 'Nilai Konversi (IKM)'], 'style' => 3],
        ];
        foreach ($skmMeta as $code => $label) {
            $it = $skmMap[$code] ?? ['jumlah_respons' => 0, 'rata_rata' => 0, 'nilai_konversi' => 0];
            $sheet3[] = ['value' => [$code, $label, (int)$it['jumlah_respons'], (float)$it['rata_rata'], (float)$it['nilai_konversi']], 'style' => 4];
        }

        // 4. Sheet: Hasil SPKP
        $spkpMeta = [
            'P1' => 'Informasi Layanan', 'P2' => 'Kemudahan Persyaratan', 'P3' => 'Kejelasan Prosedur',
            'P4' => 'Ketepatan Waktu', 'P5' => 'Kesesuaian Biaya (Gratis)', 'P6' => 'Kualitas Hasil KIA',
            'P7' => 'Sikap & Keramahan Petugas', 'P8' => 'Fasilitas & Sistem Online',
        ];
        $spkpMap = $mapPertanyaan($spkpData['pertanyaan'] ?? []);
        $sheet4 = [
            ['value' => ['HASIL SURVEI PERSEPSI KUALITAS PELAYANAN (SPKP) - 8 UNSUR'], 'style' => 1],
            ['value' => ['Total Responden SPKP: ' . (int)($spkpData['total_responden'] ?? 0) . ' | Rata-Rata: ' . (float)($spkpData['rata_rata'] ?? 0) . ' | Persentase: ' . (float)($spkpData['skor_persen'] ?? 0) . '%'], 'style' => 2],
            ['value' => ['Kode', 'Pernyataan Kualitas Pelayanan', 'Jumlah Responden', 'Rata-Rata (Skala 1-6)', 'Capaian Persentase (%)'], 'style' => 3],
        ];
        foreach ($spkpMeta as $code => $label) {
            $it = $spkpMap[$code] ?? ['jumlah_respons' => 0, 'rata_rata' => 0, 'skor_persen' => 0];
            $sheet4[] = ['value' => [$code, $label, (int)$it['jumlah_respons'], (float)$it['rata_rata'], (float)$it['skor_persen']], 'style' => 4];
        }

        // 5. Sheet: Hasil SPAK
        $spakMeta = [
            'A1' => 'Tidak Adanya Praktik Pungutan Liar (Pungli)', 'A2' => 'Tidak Adanya Praktik Calo / Perantara Liar',
            'A3' => 'Petugas Tidak Meminta / Mengharapkan Imbalan', 'A4' => 'Tidak Adanya Perlakuan Khusus / Diskriminatif',
            'A5' => 'Kemudahan Akses Tanpa Biaya Tambahan',
        ];
        $spakMap = $mapPertanyaan($spakData['pertanyaan'] ?? []);
        $sheet5 = [
            ['value' => ['HASIL SURVEI PERSEPSI ANTI KORUPSI (SPAK) - 5 INDIKATOR'], 'style' => 1],
            ['value' => ['Total Responden SPAK: ' . (int)($spakData['total_responden'] ?? 0) . ' | Rata-Rata: ' . (float)($spakData['rata_rata'] ?? 0) . ' | Persentase: ' . (float)($spakData['skor_persen'] ?? 0) . '%'], 'style' => 2],
            ['value' => ['Kode', 'Indikator Anti Korupsi', 'Jumlah Responden', 'Rata-Rata (Skala 1-6)', 'Capaian Persentase (%)'], 'style' => 3],
        ];
        foreach ($spakMeta as $code => $label) {
            $it = $spakMap[$code] ?? ['jumlah_respons' => 0, 'rata_rata' => 0, 'skor_persen' => 0];
            $sheet5[] = ['value' => [$code, $label, (int)$it['jumlah_respons'], (float)$it['rata_rata'], (float)$it['skor_persen']], 'style' => 4];
        }

        // 6. Sheet: Rincian Jawaban
        $sheet6 = [
            ['value' => ['RINCIAN JAWABAN PER PERTANYAAN'], 'style' => 1],
            ['value' => ['No', 'ID Survei', 'ID Pengajuan', 'Nama Responden', 'Jenis Survei', 'Kode Pertanyaan', 'Pertanyaan / Indikator', 'Nilai Jawaban', 'Tanggal Survei'], 'style' => 3],
        ];
        $noJ = 1;
        foreach ($analytics['rincian_jawaban'] as $rj) {
            $sheet6[] = [
                'value' => [
                    $noJ++,
                    (int)$rj['id_survei'],
                    (int)$rj['id_pengajuan'],
                    $rj['nama_lengkap'],
                    $rj['jenis_survei'],
                    $rj['kode_pertanyaan'],
                    $rj['pertanyaan'],
                    (int)$rj['nilai_jawaban'],
                    $rj['tanggal_survei'],
                ],
                'style' => 4,
            ];
        }
        if ($noJ === 1) {
            $sheet6[] = ['value' => ['-', '-', '-', 'Tidak ada rincian jawaban pada filter terpilih'], 'style' => 4];
        }

        // 7. Sheet: Rekapitulasi Demografi
        $sheet7 = [
            ['value' => ['REKAPITULASI DEMOGRAFI RESPONDEN UNIK (TOTAL: ' . (int)$analytics['total_responden_unik'] . ' RESPONDEN)'], 'style' => 1],
            ['value' => ['Dimensi Demografi', 'Kategori', 'Jumlah Responden Unik', 'Persentase (%)'], 'style' => 3],
        ];
        $dimTitles = [
            'jenis_kelamin' => 'Jenis Kelamin',
            'pendidikan' => 'Pendidikan Terakhir',
            'kelompok_usia' => 'Kelompok Usia',
            'pekerjaan' => 'Pekerjaan',
        ];
        foreach ($dimTitles as $dimKey => $dimLabel) {
            $items = $analytics['demografi'][$dimKey] ?? [];
            if (empty($items)) {
                $sheet7[] = ['value' => [$dimLabel, 'Belum ada data', 0, 0.0], 'style' => 4];
            } else {
                foreach ($items as $it) {
                    $sheet7[] = ['value' => [$dimLabel, $it['kategori'], (int)$it['jumlah'], (float)$it['persen']], 'style' => 4];
                }
            }
        }

        return $this->buildMultiSheetXlsx([
            ['name' => 'Ringkasan Survei', 'rows' => $sheet1],
            ['name' => 'Data Responden', 'rows' => $sheet2],
            ['name' => 'Hasil SKM', 'rows' => $sheet3],
            ['name' => 'Hasil SPKP', 'rows' => $sheet4],
            ['name' => 'Hasil SPAK', 'rows' => $sheet5],
            ['name' => 'Rincian Jawaban', 'rows' => $sheet6],
            ['name' => 'Rekapitulasi Demografi', 'rows' => $sheet7],
        ]);
    }

    // membangun file OpenXML .xlsx valid dari array baris menggunakan ZIP builder native PHP
    private function buildXlsxFromRows(string $sheetName, array $rows): string
    {
        return $this->buildMultiSheetXlsx([
            ['name' => $sheetName, 'rows' => $rows],
        ]);
    }

    // membangun file OpenXML .xlsx multi-worksheet valid menggunakan ZIP builder native PHP
    private function buildMultiSheetXlsx(array $sheets): string
    {
        if (empty($sheets)) {
            $sheets = [['name' => 'Sheet1', 'rows' => []]];
        }
        $colLetter = function (int $colIndexZeroBased): string {
            $n = $colIndexZeroBased + 1;
            $str = '';
            while ($n > 0) {
                $rem = ($n - 1) % 26;
                $str = chr(65 + $rem) . $str;
                $n = intdiv($n - 1, 26);
            }
            return $str;
        };

        $xmlEscape = function (string $s): string {
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
            if ($clean === null) {
                $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
            }
            return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        $sharedStringsMap = [];
        $sharedStringsList = [];
        $totalStringOccurrences = 0;

        $getSharedStringIndex = function (string $val) use (&$sharedStringsMap, &$sharedStringsList, &$totalStringOccurrences): int {
            $totalStringOccurrences++;
            if (!array_key_exists($val, $sharedStringsMap)) {
                $idx = count($sharedStringsList);
                $sharedStringsMap[$val] = $idx;
                $sharedStringsList[] = $val;
                return $idx;
            }
            return $sharedStringsMap[$val];
        };

        $sheetFiles = [];
        $sheetOverridesXml = '';
        $workbookSheetsXml = '';
        $workbookRelsSheetsXml = '';
        $usedSheetNames = [];

        foreach (array_values($sheets) as $idx => $sheetDef) {
            $sheetNo = $idx + 1;
            $rawName = (string)($sheetDef['name'] ?? ('Sheet' . $sheetNo));
            $cleanName = substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '_', $rawName) ?? ('Sheet' . $sheetNo), 0, 31);
            if ($cleanName === '') {
                $cleanName = 'Sheet' . $sheetNo;
            }
            if (isset($usedSheetNames[strtolower($cleanName)])) {
                $suffix = '_' . $sheetNo;
                $cleanName = substr($cleanName, 0, 31 - strlen($suffix)) . $suffix;
            }
            $usedSheetNames[strtolower($cleanName)] = true;
            $safeSheetName = $xmlEscape($cleanName);

            $rows = $sheetDef['rows'] ?? [];
            $sheetRowsXml = '';
            $rowNum = 1;
            $maxColIdx = 0;

            foreach ($rows as $rObj) {
                $vals = $rObj['value'] ?? [];
                $styleId = (int)($rObj['style'] ?? 0);
                if (empty($vals)) {
                    $sheetRowsXml .= "<row r=\"{$rowNum}\"/>";
                    $rowNum++;
                    continue;
                }
                $cellsXml = '';
                $colArr = array_values($vals);
                if (count($colArr) - 1 > $maxColIdx) {
                    $maxColIdx = count($colArr) - 1;
                }
                foreach ($colArr as $cIdx => $cellVal) {
                    $ref = $colLetter($cIdx) . $rowNum;
                    $sAttr = $styleId > 0 ? " s=\"{$styleId}\"" : '';
                    if ((is_int($cellVal) || is_float($cellVal)) && !is_nan((float)$cellVal) && !is_infinite((float)$cellVal)) {
                        $cellsXml .= "<c r=\"{$ref}\"{$sAttr}><v>{$cellVal}</v></c>";
                    } else {
                        $strVal = (string)$cellVal;
                        $sstIdx = $getSharedStringIndex($strVal);
                        $cellsXml .= "<c r=\"{$ref}\"{$sAttr} t=\"s\"><v>{$sstIdx}</v></c>";
                    }
                }
                $lastColOneBased = max(1, count($colArr));
                $sheetRowsXml .= "<row r=\"{$rowNum}\" spans=\"1:{$lastColOneBased}\">{$cellsXml}</row>";
                $rowNum++;
            }

            $totalRows = max(1, $rowNum - 1);
            $lastColLetter = $colLetter($maxColIdx);
            $dimensionRef = "A1:{$lastColLetter}{$totalRows}";

            $colsXml = '<cols>'
                . '<col min="1" max="1" width="6" customWidth="1"/>'
                . '<col min="2" max="5" width="20" customWidth="1"/>'
                . '<col min="6" max="8" width="24" customWidth="1"/>'
                . '<col min="9" max="13" width="18" customWidth="1"/>'
                . '<col min="14" max="' . max(14, $maxColIdx + 1) . '" width="16" customWidth="1"/>'
                . '</cols>';

            $tabSelectedAttr = $sheetNo === 1 ? ' tabSelected="1"' : '';
            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<dimension ref="' . $dimensionRef . '"/>'
                . '<sheetViews><sheetView' . $tabSelectedAttr . ' workbookViewId="0"/></sheetViews>'
                . '<sheetFormatPr defaultRowHeight="15"/>'
                . $colsXml
                . '<sheetData>' . $sheetRowsXml . '</sheetData>'
                . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
                . '</worksheet>';

            $sheetPath = "xl/worksheets/sheet{$sheetNo}.xml";
            $sheetFiles[$sheetPath] = $sheetXml;
            $sheetOverridesXml .= "<Override PartName=\"/{$sheetPath}\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $workbookSheetsXml .= "<sheet name=\"{$safeSheetName}\" sheetId=\"{$sheetNo}\" r:id=\"rId{$sheetNo}\"/>";
            $workbookRelsSheetsXml .= "<Relationship Id=\"rId{$sheetNo}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$sheetNo}.xml\"/>";
        }

        $nextRelId = count($sheets) + 1;
        $isoNow = gmdate('Y-m-d\TH:i:s\Z');

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $sheetOverridesXml
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';

        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>SIPRIMA Dukcapil Kab. Gorontalo</dc:creator>'
            . '<cp:lastModifiedBy>SIPRIMA Dukcapil Kab. Gorontalo</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $isoNow . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $isoNow . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Microsoft Excel</Application>'
            . '<DocSecurity>0</DocSecurity>'
            . '<ScaleCrop>false</ScaleCrop>'
            . '<Company>Dukcapil Kabupaten Gorontalo</Company>'
            . '<LinksUpToDate>false</LinksUpToDate>'
            . '<SharedDoc>false</SharedDoc>'
            . '<HyperlinksChanged>false</HyperlinksChanged>'
            . '<AppVersion>16.0300</AppVersion>'
            . '</Properties>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
            . '<sheets>' . $workbookSheetsXml . '</sheets>'
            . '</workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $workbookRelsSheetsXml
            . '<Relationship Id="rId' . $nextRelId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId' . ($nextRelId + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4">'
            . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="13"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color indexed="64"/></left><right style="thin"><color indexed="64"/></right><top style="thin"><color indexed="64"/></top><bottom style="thin"><color indexed="64"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $sstItemsXml = '';
        foreach ($sharedStringsList as $sItem) {
            $escapedItem = $xmlEscape($sItem);
            $preserveAttr = (preg_match('/^\s|\s$|\n|\r|\t/', $sItem) === 1) ? ' xml:space="preserve"' : '';
            $sstItemsXml .= '<si><t' . $preserveAttr . '>' . $escapedItem . '</t></si>';
        }
        $uniqueCount = count($sharedStringsList);
        $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $totalStringOccurrences . '" uniqueCount="' . $uniqueCount . '">'
            . $sstItemsXml
            . '</sst>';

        $entries = [
            '[Content_Types].xml' => $contentTypesXml,
            '_rels/.rels' => $relsXml,
            'docProps/core.xml' => $coreXml,
            'docProps/app.xml' => $appXml,
            'xl/workbook.xml' => $workbookXml,
            'xl/_rels/workbook.xml.rels' => $workbookRelsXml,
            'xl/styles.xml' => $stylesXml,
            'xl/sharedStrings.xml' => $sharedStringsXml,
        ];
        foreach ($sheetFiles as $path => $xml) {
            $entries[$path] = $xml;
        }

        return $this->buildZipArchive($entries);
    }

    // binary ZIP standar (PK\x03\x04 ... PK\x05\x06) dengan MS-DOS timestamp valid
    private function buildZipArchive(array $entries): string
    {
        $localParts = '';
        $centralParts = '';
        $offset = 0;
        $count = 0;

        // Hitung MS-DOS Time & Date yang valid (tidak boleh 0 karena bulan=0/hari=0 dianggap korup oleh Excel)
        $now = getdate();
        $dosTime = (($now['hours'] & 0x1F) << 11) | (($now['minutes'] & 0x3F) << 5) | (($now['seconds'] >> 1) & 0x1F);
        $year = max(1980, (int)$now['year']);
        $dosDate = ((($year - 1980) & 0x7F) << 9) | (($now['mon'] & 0x0F) << 5) | ($now['mday'] & 0x1F);

        foreach ($entries as $filename => $content) {
            $filename = str_replace('\\', '/', $filename);
            $uncompressedSize = strlen($content);
            // Gunakan hexdec(hash('crc32b', ...)) agar selalu unsigned 32-bit integer di semua platform
            $crc = (int)hexdec(hash('crc32b', $content));
            $compressed = gzdeflate($content, 6);
            $compressedSize = strlen($compressed);

            // Local file header (PK\x03\x04)
            $localHeader = "\x50\x4b\x03\x04"
                . pack('v', 20)       // version needed to extract (2.0)
                . pack('v', 0)        // general purpose bit flag
                . pack('v', 8)        // compression method: 8 = Deflate
                . pack('v', $dosTime) // valid MS-DOS time
                . pack('v', $dosDate) // valid MS-DOS date
                . pack('V', $crc)
                . pack('V', $compressedSize)
                . pack('V', $uncompressedSize)
                . pack('v', strlen($filename))
                . pack('v', 0)        // extra field length
                . $filename;

            $localBlock = $localHeader . $compressed;
            $localParts .= $localBlock;

            // Central directory file header (PK\x01\x02)
            $centralParts .= "\x50\x4b\x01\x02"
                . pack('v', 20)       // version made by (2.0)
                . pack('v', 20)       // version needed to extract (2.0)
                . pack('v', 0)        // general purpose bit flag
                . pack('v', 8)        // compression method: 8 = Deflate
                . pack('v', $dosTime) // valid MS-DOS time
                . pack('v', $dosDate) // valid MS-DOS date
                . pack('V', $crc)
                . pack('V', $compressedSize)
                . pack('V', $uncompressedSize)
                . pack('v', strlen($filename))
                . pack('v', 0)        // extra field length
                . pack('v', 0)        // file comment length
                . pack('v', 0)        // disk number start
                . pack('v', 0)        // internal file attributes
                . pack('V', 32)       // external file attributes (ARCHIVE)
                . pack('V', $offset)
                . $filename;

            $offset += strlen($localBlock);
            $count++;
        }

        // End of central directory record (PK\x05\x06)
        $eocd = "\x50\x4b\x05\x06"
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', $count)
            . pack('v', $count)
            . pack('V', strlen($centralParts))
            . pack('V', strlen($localParts))
            . pack('v', 0);

        return $localParts . $centralParts . $eocd;
    }
}