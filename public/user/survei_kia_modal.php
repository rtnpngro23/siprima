<?php
declare(strict_types=1);

/**
 * Komponen Pop-up (Modal) Survei Kepuasan Pelayanan KIA Terintegrasi (SKM, SPKP, SPAK)
 * Konsep: Sukarela / Persuasif (Bukan Kewajiban yang Mengunci Pemohon).
 * Ketentuan Identitas Responden:
 * - HANYA 3 field yang diambil otomatis dari akun pemohon: Nama, Email, dan Alamat.
 * - Field lainnya (No HP, Jenis Kelamin, Usia, Pendidikan Terakhir, Pekerjaan) WAJIB kosong
 *   saat awal dibuka dan diisi secara manual oleh pemohon.
 */

if (!isset($koneksi) || empty($_SESSION['id_user'])) {
    return;
}

$modalUserId = (int)$_SESSION['id_user'];
$stmtModalUser = $koneksi->prepare("
    SELECT u.nama, u.email, u.alamat, d.nama_desa, k.nama_kecamatan
    FROM tb_users u
    LEFT JOIN tb_desa d ON u.id_desa = d.id
    LEFT JOIN tb_kecamatan k ON u.id_kecamatan = k.id
    WHERE u.id = ?
    LIMIT 1
");
$stmtModalUser->bind_param('i', $modalUserId);
$stmtModalUser->execute();
$modalUserRow = $stmtModalUser->get_result()->fetch_assoc() ?: [];
$stmtModalUser->close();

$autoNama = trim((string)($modalUserRow['nama'] ?? $_SESSION['nama'] ?? ''));
$autoEmail = trim((string)($modalUserRow['email'] ?? ''));
$autoAlamat = trim((string)($modalUserRow['alamat'] ?? ''));
if ($autoAlamat === '') {
    $partsAlamat = array_filter([
        !empty($modalUserRow['nama_desa']) ? 'Desa ' . trim((string)$modalUserRow['nama_desa']) : '',
        !empty($modalUserRow['nama_kecamatan']) ? 'Kec. ' . trim((string)$modalUserRow['nama_kecamatan']) : '',
    ]);
    $autoAlamat = !empty($partsAlamat) ? implode(', ', $partsAlamat) : '-';
}

$modalSkmQuestions = [
    [
        'code' => 'U1',
        'badge' => 'Unsur 1: Persyaratan',
        'question' => 'Bagaimana pendapat Saudara tentang kesesuaian persyaratan pelayanan pengajuan KIA dengan jenis pelayanannya?',
        'options' => [1 => 'Tidak Sesuai', 2 => 'Kurang Sesuai', 3 => 'Sesuai', 4 => 'Sangat Sesuai'],
    ],
    [
        'code' => 'U2',
        'badge' => 'Unsur 2: Sistem, Mekanisme, dan Prosedur',
        'question' => 'Bagaimana pemahaman Saudara tentang kemudahan prosedur pelayanan pengajuan KIA di aplikasi SIPRIMA?',
        'options' => [1 => 'Tidak Mudah', 2 => 'Kurang Mudah', 3 => 'Mudah', 4 => 'Sangat Mudah'],
    ],
    [
        'code' => 'U3',
        'badge' => 'Unsur 3: Waktu Penyelesaian',
        'question' => 'Bagaimana pendapat Saudara tentang kecepatan waktu dalam memberikan pelayanan penerbitan KIA?',
        'options' => [1 => 'Tidak Cepat', 2 => 'Kurang Cepat', 3 => 'Cepat', 4 => 'Sangat Cepat'],
    ],
    [
        'code' => 'U4',
        'badge' => 'Unsur 4: Biaya / Tarif',
        'question' => 'Bagaimana pendapat Saudara tentang kewajaran biaya/tarif dalam pelayanan KIA (Pelayanan Gratis / Rp 0)?',
        'options' => [1 => 'Sangat Mahal', 2 => 'Cukup Mahal', 3 => 'Murah', 4 => 'Gratis / Sesuai Ketentuan'],
    ],
    [
        'code' => 'U5',
        'badge' => 'Unsur 5: Produk Spesifikasi Jenis Pelayanan',
        'question' => 'Bagaimana pendapat Saudara tentang kesesuaian produk pelayanan (dokumen/kartu KIA) antara yang tercantum dalam standar pelayanan dengan hasil yang diberikan?',
        'options' => [1 => 'Tidak Sesuai', 2 => 'Kurang Sesuai', 3 => 'Sesuai', 4 => 'Sangat Sesuai'],
    ],
    [
        'code' => 'U6',
        'badge' => 'Unsur 6: Kompetensi Pelaksana',
        'question' => 'Bagaimana pendapat Saudara tentang kompetensi/kemampuan petugas dalam memberikan pelayanan KIA?',
        'options' => [1 => 'Tidak Kompeten', 2 => 'Kurang Kompeten', 3 => 'Kompeten', 4 => 'Sangat Kompeten'],
    ],
    [
        'code' => 'U7',
        'badge' => 'Unsur 7: Perilaku Pelaksana',
        'question' => 'Bagaimana pendapat Saudara tentang perilaku petugas dalam pelayanan terkait kesopanan dan keramahan?',
        'options' => [1 => 'Tidak Sopan & Ramah', 2 => 'Kurang Sopan & Ramah', 3 => 'Sopan & Ramah', 4 => 'Sangat Sopan & Ramah'],
    ],
    [
        'code' => 'U8',
        'badge' => 'Unsur 8: Penanganan Pengaduan, Saran & Masukan',
        'question' => 'Bagaimana pendapat Saudara tentang kualitas penanganan pengaduan, koreksi data, saran dan masukan pengguna layanan?',
        'options' => [1 => 'Tidak Ada', 2 => 'Ada Tetapi Tidak Berfungsi', 3 => 'Berfungsi Kurang Maksimal', 4 => 'Dikelola dengan Baik'],
    ],
    [
        'code' => 'U9',
        'badge' => 'Unsur 9: Sarana dan Prasarana',
        'question' => 'Bagaimana pendapat Saudara tentang kualitas sarana dan prasarana (termasuk kemudahan fitur digital SIPRIMA)?',
        'options' => [1 => 'Buruk', 2 => 'Cukup', 3 => 'Baik', 4 => 'Sangat Baik'],
    ],
];

$modalSpkpQuestions = [
    ['code' => 'P1', 'badge' => 'Informasi Layanan', 'question' => 'Informasi persyaratan dan alur pengajuan KIA melalui SIPRIMA tersedia dengan jelas dan mudah dipahami.'],
    ['code' => 'P2', 'badge' => 'Kemudahan Persyaratan', 'question' => 'Persyaratan dokumen pengajuan KIA mudah dipenuhi dan sesuai dengan ketentuan yang berlaku.'],
    ['code' => 'P3', 'badge' => 'Kejelasan Prosedur', 'question' => 'Tahapan pengajuan dari pengisian data anak hingga unduh dokumen KIA berjalan sistematis dan transparan.'],
    ['code' => 'P4', 'badge' => 'Ketepatan Waktu', 'question' => 'Waktu verifikasi dan penyelesaian permohonan KIA sesuai dengan standar waktu pelayanan yang dijanjikan.'],
    ['code' => 'P5', 'badge' => 'Kesesuaian Biaya (Gratis)', 'question' => 'Seluruh proses pelayanan KIA tidak dipungut biaya apa pun (100% Gratis).'],
    ['code' => 'P6', 'badge' => 'Kualitas Hasil KIA', 'question' => 'Dokumen/Kartu Identitas Anak (KIA) yang diterbitkan memiliki data yang akurat dan kualitas cetak/PDF yang jelas.'],
    ['code' => 'P7', 'badge' => 'Sikap & Keramahan Petugas', 'question' => 'Petugas/Operator memberikan pelayanan serta tanggapan dengan cepat, ramah, dan solutif.'],
    ['code' => 'P8', 'badge' => 'Fasilitas & Sistem Online', 'question' => 'Fitur pelacakan status, notifikasi, dan unduh dokumen pada sistem SIPRIMA berfungsi dengan sangat baik.'],
];

$modalSpakQuestions = [
    ['code' => 'A1', 'badge' => 'Non-Diskriminasi', 'question' => 'Petugas memberikan pelayanan pengajuan KIA secara adil tanpa membedakan status sosial, agama, suku, atau hubungan tertentu.'],
    ['code' => 'A2', 'badge' => 'Sesuai Prosedur Resmi', 'question' => 'Tidak ada petugas yang memberikan pelayanan di luar prosedur resmi atau memanipulasi antrean pelayanan.'],
    ['code' => 'A3', 'badge' => 'Bebas Gratifikasi', 'question' => 'Petugas tidak meminta atau menerima imbalan uang, barang, maupun fasilitas apa pun di luar ketentuan.'],
    ['code' => 'A4', 'badge' => 'Bebas Pungutan Liar (Pungli)', 'question' => 'Tidak terdapat pungutan liar (pungli) dalam seluruh rangkaian proses penerbitan Kartu Identitas Anak (KIA).'],
    ['code' => 'A5', 'badge' => 'Bebas Percaloan', 'question' => 'Pelayanan KIA bebas dari praktik percaloan atau perantara tidak resmi yang menjanjikan percepatan layanan.'],
];

$modalLikert6Labels = [
    1 => 'Sangat Tidak Setuju',
    2 => 'Tidak Setuju',
    3 => 'Kurang Setuju',
    4 => 'Cukup Setuju',
    5 => 'Setuju',
    6 => 'Sangat Setuju',
];
?>
<style>
  .kia-survei-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(3px);
    z-index: 10050;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  .kia-survei-modal-dialog {
    background: #ffffff;
    width: 100%;
    max-width: 760px;
    max-height: 88vh;
    border-radius: 16px;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.28);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #e2e8f0;
  }
  .kia-survei-modal-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: #ffffff;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .kia-survei-modal-header h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    color: #ffffff;
  }
  .kia-survei-modal-header p {
    margin: 3px 0 0;
    font-size: 12px;
    color: #dbeafe;
    line-height: 1.4;
  }
  .kia-survei-close-btn {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
    border: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background 0.15s ease;
  }
  .kia-survei-close-btn:hover {
    background: rgba(255, 255, 255, 0.35);
  }
  .kia-survei-tabs {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 18px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    white-space: nowrap;
  }
  .kia-survei-tab-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 38px;
    min-height: 38px;
    padding: 0 16px;
    border-radius: 9999px;
    border: 1.5px solid #cbd5e1;
    background: #ffffff;
    color: #475569;
    font-size: 13px;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    box-sizing: border-box;
    transition: all 0.15s ease;
    vertical-align: middle;
  }
  .kia-survei-tab-btn:hover {
    background: #f1f5f9;
    color: #1e293b;
    border-color: #94a3b8;
  }
  .kia-survei-tab-btn.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
  }
  .kia-survei-tab-btn.done {
    background: #dcfce7;
    color: #166534;
    border-color: #86efac;
  }
  .kia-survei-modal-body {
    padding: 18px 22px;
    overflow-y: auto;
    flex: 1;
  }
  .kia-survei-modal-footer {
    padding: 12px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }
  .ks-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }
  .ks-field {
    margin-bottom: 12px;
  }
  .ks-field.full {
    grid-column: 1 / -1;
  }
  .ks-label {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 5px;
  }
  .ks-input, .ks-select, .ks-textarea {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13.5px;
    color: #0f172a;
    background: #ffffff;
    box-sizing: border-box;
    font-family: inherit;
    transition: border-color 0.15s;
  }
  .ks-input:focus, .ks-select:focus, .ks-textarea:focus {
    outline: none;
    border-color: #2563eb;
  }
  .ks-input[readonly], .ks-textarea[readonly] {
    background: #f1f5f9;
    color: #475569;
    border-color: #e2e8f0;
    cursor: not-allowed;
  }
  .ks-gender-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }
  .ks-gender-btn {
    padding: 9px 12px;
    border-radius: 8px;
    border: 1.5px solid #cbd5e1;
    background: #ffffff;
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    cursor: pointer;
    transition: all 0.15s;
  }
  .ks-gender-btn:hover {
    border-color: #94a3b8;
  }
  .ks-gender-btn.selected {
    background: #eff6ff;
    border-color: #2563eb;
    color: #1e40af;
  }
  .ks-q-card {
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 12px;
    background: #ffffff;
    transition: all 0.15s;
  }
  .ks-q-card.answered {
    border-color: #86efac;
    background: #f0fdf4;
  }
  .ks-q-title {
    font-size: 13.5px;
    font-weight: 600;
    color: #1e293b;
    margin: 6px 0 10px;
    line-height: 1.45;
  }
  .ks-skm-opts {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }
  .ks-skm-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid #cbd5e1;
    background: #f8fafc;
    font-size: 12.5px;
    font-weight: 600;
    color: #334155;
    cursor: pointer;
    text-align: left;
    transition: all 0.15s;
  }
  .ks-skm-btn:hover {
    background: #f1f5f9;
  }
  .ks-skm-btn.selected {
    background: #dbeafe;
    border-color: #2563eb;
    color: #1e40af;
    font-weight: 700;
  }
  .ks-likert6-opts {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 6px;
  }
  .ks-likert6-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 8px 4px;
    border-radius: 8px;
    border: 1.5px solid #cbd5e1;
    background: #f8fafc;
    cursor: pointer;
    font-size: 11px;
    line-height: 1.25;
    color: #334155;
    min-height: 60px;
    text-align: center;
    transition: all 0.15s;
  }
  .ks-likert6-btn:hover {
    background: #f1f5f9;
  }
  .ks-likert6-btn strong {
    font-size: 14px;
    margin-bottom: 2px;
    color: #0f172a;
  }
  .ks-likert6-btn.selected {
    background: #ecfdf5;
    border-color: #10b981;
    color: #065f46;
    font-weight: 700;
    box-shadow: 0 0 0 1px #10b981;
  }
  @media (max-width: 768px) {
    .ks-grid-2 {
      grid-template-columns: 1fr;
    }
    .ks-likert6-opts {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 8px;
    }
  }
  @media (max-width: 540px) {
    .ks-skm-opts {
      grid-template-columns: 1fr;
    }
    .ks-likert6-opts {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 6px;
    }
  }
</style>

<div id="kiaSurveiPopupOverlay" class="kia-survei-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="kiaSurveiPopupTitle">
  <div class="kia-survei-modal-dialog">
    <div class="kia-survei-modal-header">
      <div>
        <h3 id="kiaSurveiPopupTitle">🌟 Survei Kepuasan Pelayanan KIA</h3>
        <p id="kiaSurveiPopupSubtitle">Partisipasi Anda sangat berarti untuk evaluasi dan peningkatan kualitas pelayanan KIA (SKM, SPKP, SPAK)</p>
      </div>
      <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
        <span style="background:rgba(255,255,255,0.2); color:#ffffff; padding:4px 10px; border-radius:999px; font-size:11.5px; font-weight:600; white-space:nowrap;">Evaluasi Pelayanan</span>
        <button type="button" class="kia-survei-close-btn" onclick="closeKiaSurveiModal()" aria-label="Tutup Pop-up Survei" title="Tutup Survei">&times;</button>
      </div>
    </div>

    <div class="kia-survei-tabs" id="ksModalTabs">
      <button type="button" class="kia-survei-tab-btn active" data-kstep="1" onclick="handleTabClick(1)">1. Identitas Responden</button>
      <button type="button" class="kia-survei-tab-btn" data-kstep="2" onclick="handleTabClick(2)">2. SKM (9 Unsur)</button>
      <button type="button" class="kia-survei-tab-btn" data-kstep="3" onclick="handleTabClick(3)">3. SPKP (8 Item)</button>
      <button type="button" class="kia-survei-tab-btn" data-kstep="4" onclick="handleTabClick(4)">4. SPAK (5 Item)</button>
      <button type="button" class="kia-survei-tab-btn" data-kstep="5" onclick="handleTabClick(5)">5. Saran &amp; Kirim</button>
    </div>

    <div class="kia-survei-modal-body" id="ksModalBody">
      <div id="ksModalAlert" style="display:none; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:13px; font-weight:600;"></div>

      <input type="hidden" id="ks_id_pengajuan" value="<?= isset($idPengajuan) ? (int)$idPengajuan : 0 ?>">
      <input type="hidden" id="ks_csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

      <!-- STEP 1: IDENTITAS RESPONDEN -->
      <div class="ks-step-pane" id="ksStepPane1">
        <div class="ks-grid-2">
          <div class="ks-field">
            <label class="ks-label" for="ks_resp_nama">Nama Responden <span style="color:#dc2626">*</span></label>
            <input type="text" id="ks_resp_nama" class="ks-input" value="<?= htmlspecialchars($autoNama) ?>" readonly>
          </div>
          <div class="ks-field">
            <label class="ks-label" for="ks_resp_email">Email Responden <span style="color:#dc2626">*</span></label>
            <input type="email" id="ks_resp_email" class="ks-input" value="<?= htmlspecialchars($autoEmail) ?>" readonly>
          </div>
          <div class="ks-field full">
            <label class="ks-label" for="ks_resp_alamat">Alamat Responden <span style="color:#dc2626">*</span></label>
            <input type="text" id="ks_resp_alamat" class="ks-input" value="<?= htmlspecialchars($autoAlamat) ?>" readonly>
          </div>

          <div class="ks-field">
            <label class="ks-label" for="ks_resp_hp">No. HP / WhatsApp <span style="color:#dc2626">*</span></label>
            <input type="tel" id="ks_resp_hp" class="ks-input" value="" placeholder="Isi nomor HP aktif (contoh: 081234567890)" required>
          </div>

          <div class="ks-field">
            <label class="ks-label">Jenis Kelamin <span style="color:#dc2626">*</span></label>
            <input type="hidden" id="ks_resp_jk" value="">
            <div class="ks-gender-group">
              <button type="button" class="ks-gender-btn" id="ksBtnJkL" onclick="selectKsGender('L')">♂ Laki-Laki</button>
              <button type="button" class="ks-gender-btn" id="ksBtnJkP" onclick="selectKsGender('P')">♀ Perempuan</button>
            </div>
          </div>

          <div class="ks-field">
            <label class="ks-label" for="ks_resp_usia">Usia (Tahun) <span style="color:#dc2626">*</span></label>
            <input type="number" id="ks_resp_usia" class="ks-input" min="15" max="120" value="" placeholder="Isi usia Anda (contoh: 29)" required>
          </div>

          <div class="ks-field">
            <label class="ks-label" for="ks_resp_pendidikan">Pendidikan Terakhir <span style="color:#dc2626">*</span></label>
            <select id="ks_resp_pendidikan" class="ks-select" required>
              <option value="" selected>-- Pilih Pendidikan Terakhir --</option>
              <option value="SD">SD</option>
              <option value="SMP">SMP</option>
              <option value="SMA/SMK">SMA/SMK</option>
              <option value="Diploma">Diploma</option>
              <option value="S1">S1</option>
              <option value="S2">S2</option>
              <option value="S3">S3</option>
              <option value="Lainnya">Lainnya</option>
            </select>
          </div>

          <div class="ks-field full">
            <label class="ks-label" for="ks_resp_pekerjaan">Pekerjaan <span style="color:#dc2626">*</span></label>
            <select id="ks_resp_pekerjaan" class="ks-select" onchange="toggleKsPekerjaanLainnya(this.value)" required>
              <option value="" selected>-- Pilih Pekerjaan --</option>
              <option value="PNS/TNI/Polri">PNS / TNI / Polri</option>
              <option value="Pegawai Swasta">Pegawai Swasta</option>
              <option value="Wiraswasta">Wiraswasta / Usahawan</option>
              <option value="Ibu Rumah Tangga">Ibu Rumah Tangga</option>
              <option value="Pelajar/Mahasiswa">Pelajar / Mahasiswa</option>
              <option value="Petani/Nelayan">Petani / Nelayan</option>
              <option value="Lainnya">Lainnya</option>
            </select>
            <input type="text" id="ks_resp_pekerjaan_lainnya" class="ks-input" style="display:none; margin-top:8px;" placeholder="Tuliskan pekerjaan Anda">
          </div>
        </div>
      </div>

      <!-- STEP 2: SKM (9 UNSUR) -->
      <div class="ks-step-pane" id="ksStepPane2" style="display:none;">
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:12px 16px; border-radius:10px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
          <div>
            <strong style="font-size:14px; color:#166534;">Survei Kepuasan Masyarakat (SKM)</strong>
            <div style="font-size:11.5px; color:#15803d;">Skala 1 - 4 (PermenpanRB No. 14 Tahun 2017)</div>
          </div>
          <span id="ksBadgeCountSkm" style="background:#dcfce7; color:#166534; border:1px solid #86efac; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700;">0 / 9</span>
        </div>
        <?php foreach ($modalSkmQuestions as $idx => $q): ?>
          <div class="ks-q-card" id="ksCard_skm_<?= $q['code'] ?>">
            <div style="font-size:11.5px; font-weight:700; color:#1d4ed8;"><?= ($idx + 1) ?>. <?= htmlspecialchars($q['badge']) ?> (<?= $q['code'] ?>)</div>
            <div class="ks-q-title"><?= htmlspecialchars($q['question']) ?></div>
            <div class="ks-skm-opts">
              <?php foreach ($q['options'] as $score => $label): ?>
                <button type="button" class="ks-skm-btn" onclick="selectKsAnswer('skm', '<?= $q['code'] ?>', <?= $score ?>, this)">
                  <strong><?= $score ?>.</strong> <span><?= htmlspecialchars($label) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- STEP 3: SPKP (8 PERNYATAAN) -->
      <div class="ks-step-pane" id="ksStepPane3" style="display:none;">
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:12px 16px; border-radius:10px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
          <div>
            <strong style="font-size:14px; color:#166534;">Survei Persepsi Kualitas Pelayanan (SPKP)</strong>
            <div style="font-size:11.5px; color:#15803d;">Skala 1 - 6 (1 = Sangat Tidak Setuju s/d 6 = Sangat Setuju)</div>
          </div>
          <span id="ksBadgeCountSpkp" style="background:#dcfce7; color:#166534; border:1px solid #86efac; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700;">0 / 8</span>
        </div>
        <?php foreach ($modalSpkpQuestions as $idx => $q): ?>
          <div class="ks-q-card" id="ksCard_spkp_<?= $q['code'] ?>">
            <div style="font-size:11.5px; font-weight:700; color:#0f766e;"><?= ($idx + 1) ?>. <?= htmlspecialchars($q['badge']) ?> (<?= $q['code'] ?>)</div>
            <div class="ks-q-title"><?= htmlspecialchars($q['question']) ?></div>
            <div class="ks-likert6-opts">
              <?php foreach ($modalLikert6Labels as $score => $lbl): ?>
                <button type="button" class="ks-likert6-btn" onclick="selectKsAnswer('spkp', '<?= $q['code'] ?>', <?= $score ?>, this)">
                  <strong><?= $score ?></strong>
                  <span><?= htmlspecialchars($lbl) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- STEP 4: SPAK (5 PERNYATAAN) -->
      <div class="ks-step-pane" id="ksStepPane4" style="display:none;">
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:12px 16px; border-radius:10px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
          <div>
            <strong style="font-size:14px; color:#166534;">Survei Persepsi Anti Korupsi (SPAK)</strong>
            <div style="font-size:11.5px; color:#15803d;">Skala 1 - 6 • Pelayanan Bersih &amp; Bebas Pungli</div>
          </div>
          <span id="ksBadgeCountSpak" style="background:#dcfce7; color:#166534; border:1px solid #86efac; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700;">0 / 5</span>
        </div>
        <?php foreach ($modalSpakQuestions as $idx => $q): ?>
          <div class="ks-q-card" id="ksCard_spak_<?= $q['code'] ?>">
            <div style="font-size:11.5px; font-weight:700; color:#15803d;"><?= ($idx + 1) ?>. <?= htmlspecialchars($q['badge']) ?> (<?= $q['code'] ?>)</div>
            <div class="ks-q-title"><?= htmlspecialchars($q['question']) ?></div>
            <div class="ks-likert6-opts">
              <?php foreach ($modalLikert6Labels as $score => $lbl): ?>
                <button type="button" class="ks-likert6-btn" onclick="selectKsAnswer('spak', '<?= $q['code'] ?>', <?= $score ?>, this)">
                  <strong><?= $score ?></strong>
                  <span><?= htmlspecialchars($lbl) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- STEP 5: SARAN & KONFIRMASI PENGIRIMAN -->
      <div class="ks-step-pane" id="ksStepPane5" style="display:none;">
        <div class="ks-field">
          <label class="ks-label" for="ks_saran_skm">Saran / Masukan untuk SKM (Kepuasan Masyarakat)</label>
          <textarea id="ks_saran_skm" class="ks-textarea" rows="2" placeholder="Tuliskan saran atau masukan Anda terkait prosedur dan fasilitas layanan (opsional)..."></textarea>
        </div>
        <div class="ks-field">
          <label class="ks-label" for="ks_saran_spkp">Saran / Masukan untuk SPKP (Kualitas Pelayanan)</label>
          <textarea id="ks_saran_spkp" class="ks-textarea" rows="2" placeholder="Tuliskan masukan terkait kecepatan dan kualitas pelayanan KIA (opsional)..."></textarea>
        </div>
        <div class="ks-field">
          <label class="ks-label" for="ks_saran_spak">Saran / Masukan untuk SPAK (Anti Korupsi)</label>
          <textarea id="ks_saran_spak" class="ks-textarea" rows="2" placeholder="Tuliskan masukan terkait transparansi dan integritas pelayanan (opsional)..."></textarea>
        </div>

        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px 16px; font-size:13px; color:#166534; margin-top:8px;">
          <strong>Ringkasan Jawaban Anda:</strong>
          <div style="display:flex; gap:16px; margin-top:6px; flex-wrap:wrap;">
            <span id="ksSummarySkm" style="font-weight:600; color:#166534;">• SKM: 0 / 9 terjawab</span>
            <span id="ksSummarySpkp" style="font-weight:600; color:#166534;">• SPKP: 0 / 8 terjawab</span>
            <span id="ksSummarySpak" style="font-weight:600; color:#166534;">• SPAK: 0 / 5 terjawab</span>
          </div>
        </div>
      </div>

      <!-- STEP SUKSES: LAYAR KONFIRMASI SURVEI BERHASIL DISIMPAN -->
      <div class="ks-step-pane" id="ksStepPaneSuccess" style="display:none; text-align:center; padding:28px 14px;">
        <div style="width:68px; height:68px; border-radius:50%; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:32px; font-weight:700;">&#10003;</div>
        <h3 style="margin:0 0 8px; font-size:20px; font-weight:700; color:#0f172a;">Survei Berhasil Disimpan!</h3>
        <p style="margin:0 auto 24px; max-width:480px; font-size:13.5px; color:#475569; line-height:1.6;">
          Terima kasih atas partisipasi Anda dalam mengisi Survei Kepuasan Pelayanan KIA (SKM, SPKP, dan SPAK). Penilaian dan saran Anda sangat berharga bagi peningkatan mutu layanan Dukcapil.
        </p>
        <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
          <button type="button" onclick="finishKiaSurveiModal(true)" style="padding:11px 24px; border-radius:10px; border:0; background:#16a34a; color:#fff; font-weight:700; font-size:14px; cursor:pointer;">
            Lihat Status Permohonan &rarr;
          </button>
          <button type="button" onclick="goHomeFromKiaSurveiModal()" style="padding:11px 20px; border-radius:10px; border:1.5px solid #cbd5e1; background:#ffffff; color:#334155; font-weight:700; font-size:14px; cursor:pointer;">
            Kembali ke Beranda
          </button>
        </div>
      </div>
    </div>

    <div class="kia-survei-modal-footer" id="ksModalFooter">
      <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
        <button type="button" id="ksBtnHome" onclick="goHomeFromKiaSurveiModal()" style="padding:9px 14px; border-radius:8px; border:1.5px solid #cbd5e1; background:#ffffff; color:#334155; font-weight:600; font-size:13px; cursor:pointer;">
          Kembali ke Beranda
        </button>
        <button type="button" id="ksBtnCloseAlt" onclick="closeKiaSurveiModal()" style="padding:9px 14px; border-radius:8px; border:1.5px solid #e2e8f0; background:#f8fafc; color:#64748b; font-weight:600; font-size:13px; cursor:pointer;">
          Tutup / Nanti Saja
        </button>
        <button type="button" id="ksBtnPrev" onclick="prevKsModalStep()" style="padding:9px 16px; border-radius:8px; border:1.5px solid #cbd5e1; background:#fff; color:#334155; font-weight:600; font-size:13px; cursor:pointer; display:none;">
          &larr; Sebelumnya
        </button>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" id="ksBtnNext" onclick="nextKsModalStep()" style="padding:9px 22px; border-radius:8px; border:0; background:#2563eb; color:#fff; font-weight:700; font-size:13px; cursor:pointer;">
          Lanjut ke SKM &rarr;
        </button>
        <button type="button" id="ksBtnSubmit" onclick="submitKsModalSurvei()" style="display:none; padding:9px 24px; border-radius:8px; border:0; background:#16a34a; color:#fff; font-weight:700; font-size:13px; cursor:pointer;">
          Kirim Survei &#10003;
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  let ksCurrentStep = 1;
  const ksAnswers = { skm: {}, spkp: {}, spak: {} };
  let ksRespondenCache = null;
  let ksReloadAfterFinish = false;
  let ksIsCompleted = false;

  function showKsAlert(msg, isError = true) {
    const el = document.getElementById('ksModalAlert');
    if (!el) return;
    el.style.display = 'block';
    el.style.background = isError ? '#fef2f2' : '#dcfce7';
    el.style.border = isError ? '1px solid #fecaca' : '1px solid #86efac';
    el.style.color = isError ? '#b91c1c' : '#166534';
    el.textContent = msg;
    const body = document.getElementById('ksModalBody');
    if (body) body.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function hideKsAlert() {
    const el = document.getElementById('ksModalAlert');
    if (el) el.style.display = 'none';
  }

  async function openKiaSurveiModal(idPengajuan, reloadOnComplete = false) {
    const targetId = idPengajuan ? parseInt(idPengajuan, 10) : parseInt(document.getElementById('ks_id_pengajuan').value, 10);
    if (!targetId || targetId <= 0) {
      return;
    }
    document.getElementById('ks_id_pengajuan').value = String(targetId);
    ksReloadAfterFinish = Boolean(reloadOnComplete);

    // Cek apakah survei pengajuan ini sudah selesai diisi sebelumnya
    try {
      const resp = await fetch('kia.php?action=survei_progress&id_pengajuan=' + encodeURIComponent(String(targetId)), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (resp.ok) {
        const pJson = await resp.json();
        if (pJson && pJson.success && pJson.data && pJson.data.is_lengkap) {
          ksIsCompleted = true;
          // Sudah pernah diisi, tidak perlu membuka modal ajakan lagi
          return;
        }
      }
    } catch (e) {
      // Abaikan kegagalan jaringan saat cek awal
    }

    ksIsCompleted = false;
    hideKsAlert();
    document.getElementById('ksModalTabs').style.display = 'flex';
    document.getElementById('ksModalFooter').style.display = 'flex';
    document.getElementById('ksStepPaneSuccess').style.display = 'none';
    
    // Tampilkan Step 1
    switchKsModalStep(1, true);

    const overlay = document.getElementById('kiaSurveiPopupOverlay');
    if (overlay) overlay.style.display = 'flex';
  }

  function closeKiaSurveiModal() {
    const overlay = document.getElementById('kiaSurveiPopupOverlay');
    if (overlay) overlay.style.display = 'none';
  }

  function goHomeFromKiaSurveiModal() {
    closeKiaSurveiModal();
    window.location.href = 'dashboard_user.php';
  }

  function finishKiaSurveiModal(toStatus = false) {
    closeKiaSurveiModal();
    if (toStatus || ksReloadAfterFinish) {
      window.location.href = 'status_permohonan.php';
    }
  }

  function selectKsGender(jk) {
    document.getElementById('ks_resp_jk').value = jk;
    document.getElementById('ksBtnJkL').classList.toggle('selected', jk === 'L');
    document.getElementById('ksBtnJkP').classList.toggle('selected', jk === 'P');
  }

  function toggleKsPekerjaanLainnya(val) {
    const inp = document.getElementById('ks_resp_pekerjaan_lainnya');
    if (inp) {
      inp.style.display = (val === 'Lainnya') ? 'block' : 'none';
    }
  }

  function selectKsAnswer(jenis, code, score, btnEl) {
    ksAnswers[jenis][code] = score;
    const card = document.getElementById('ksCard_' + jenis + '_' + code);
    if (card) {
      card.classList.add('answered');
      card.querySelectorAll('button').forEach(b => b.classList.remove('selected'));
    }
    btnEl.classList.add('selected');
    updateKsCounters();
  }

  function updateKsCounters() {
    const cSkm = Object.keys(ksAnswers.skm).length;
    const cSpkp = Object.keys(ksAnswers.spkp).length;
    const cSpak = Object.keys(ksAnswers.spak).length;
    document.getElementById('ksBadgeCountSkm').textContent = cSkm + ' / 9';
    document.getElementById('ksBadgeCountSpkp').textContent = cSpkp + ' / 8';
    document.getElementById('ksBadgeCountSpak').textContent = cSpak + ' / 5';
    document.getElementById('ksSummarySkm').textContent = '• SKM: ' + cSkm + ' / 9 terjawab';
    document.getElementById('ksSummarySpkp').textContent = '• SPKP: ' + cSpkp + ' / 8 terjawab';
    document.getElementById('ksSummarySpak').textContent = '• SPAK: ' + cSpak + ' / 5 terjawab';
  }

  function validateKsIdentity() {
    const nama = (document.getElementById('ks_resp_nama').value || '').trim();
    const email = (document.getElementById('ks_resp_email').value || '').trim();
    const alamat = (document.getElementById('ks_resp_alamat').value || '').trim();
    const hp = (document.getElementById('ks_resp_hp').value || '').trim();
    const jk = (document.getElementById('ks_resp_jk').value || '').trim();
    const usia = parseInt(document.getElementById('ks_resp_usia').value, 10);
    const pendidikan = (document.getElementById('ks_resp_pendidikan').value || '').trim();
    let pekerjaan = (document.getElementById('ks_resp_pekerjaan').value || '').trim();
    if (pekerjaan === 'Lainnya') {
      const pekLain = (document.getElementById('ks_resp_pekerjaan_lainnya').value || '').trim();
      pekerjaan = pekLain !== '' ? pekLain : '';
    }

    if (!nama || !email || !alamat) {
      showKsAlert('Data Nama, Email, dan Alamat akun harus tersedia.');
      return null;
    }
    if (!hp || hp.length < 8) {
      showKsAlert('Nomor HP / WhatsApp wajib diisi secara manual dengan benar.');
      return null;
    }
    if (jk !== 'L' && jk !== 'P') {
      showKsAlert('Jenis Kelamin wajib dipilih (Laki-Laki atau Perempuan).');
      return null;
    }
    if (isNaN(usia) || usia < 15 || usia > 120) {
      showKsAlert('Usia responden wajib diisi secara manual (15 - 120 tahun).');
      return null;
    }
    if (!pendidikan) {
      showKsAlert('Pendidikan Terakhir wajib dipilih.');
      return null;
    }
    if (!pekerjaan) {
      showKsAlert('Pekerjaan responden wajib dipilih/diisi.');
      return null;
    }

    ksRespondenCache = {
      nama_lengkap: nama,
      no_hp: hp,
      email: email,
      jenis_kelamin: jk,
      usia: usia,
      pendidikan: pendidikan,
      pekerjaan: pekerjaan,
      alamat: alamat
    };

    return ksRespondenCache;
  }

  function handleTabClick(targetStep) {
    if (targetStep === ksCurrentStep) return;
    if (targetStep < ksCurrentStep) {
      // Boleh kembali kapan saja
      switchKsModalStep(targetStep, true);
      return;
    }
    // Jika melompat ke depan, validasi step saat ini terlebih dahulu
    if (targetStep > ksCurrentStep) {
      if (ksCurrentStep === 1 && !validateKsIdentity()) return;
      if (ksCurrentStep === 2 && Object.keys(ksAnswers.skm).length < 9) {
        showKsAlert('Mohon jawab seluruh 9 pertanyaan pada bagian SKM terlebih dahulu.');
        return;
      }
      if (ksCurrentStep === 3 && Object.keys(ksAnswers.spkp).length < 8) {
        showKsAlert('Mohon jawab seluruh 8 pernyataan pada bagian SPKP terlebih dahulu.');
        return;
      }
      if (ksCurrentStep === 4 && Object.keys(ksAnswers.spak).length < 5) {
        showKsAlert('Mohon jawab seluruh 5 pernyataan pada bagian SPAK terlebih dahulu.');
        return;
      }
      switchKsModalStep(targetStep, false);
    }
  }

  function switchKsModalStep(targetStep, skipValidation = false) {
    hideKsAlert();
    if (!skipValidation && targetStep > ksCurrentStep) {
      if (ksCurrentStep === 1 && !validateKsIdentity()) return;
      if (ksCurrentStep === 2 && Object.keys(ksAnswers.skm).length < 9) {
        showKsAlert('Mohon jawab seluruh 9 pertanyaan pada bagian SKM terlebih dahulu.');
        return;
      }
      if (ksCurrentStep === 3 && Object.keys(ksAnswers.spkp).length < 8) {
        showKsAlert('Mohon jawab seluruh 8 pernyataan pada bagian SPKP terlebih dahulu.');
        return;
      }
      if (ksCurrentStep === 4 && Object.keys(ksAnswers.spak).length < 5) {
        showKsAlert('Mohon jawab seluruh 5 pernyataan pada bagian SPAK terlebih dahulu.');
        return;
      }
    }

    ksCurrentStep = targetStep;
    for (let i = 1; i <= 5; i++) {
      const pane = document.getElementById('ksStepPane' + i);
      if (pane) pane.style.display = (i === targetStep) ? 'block' : 'none';
    }

    document.querySelectorAll('#ksModalTabs .kia-survei-tab-btn').forEach(btn => {
      const st = parseInt(btn.getAttribute('data-kstep'), 10);
      btn.classList.remove('active', 'done');
      if (st === targetStep) btn.classList.add('active');
      else if (st < targetStep) btn.classList.add('done');
    });

    const btnHome = document.getElementById('ksBtnHome');
    const btnCloseAlt = document.getElementById('ksBtnCloseAlt');
    const btnPrev = document.getElementById('ksBtnPrev');
    const btnNext = document.getElementById('ksBtnNext');
    const btnSubmit = document.getElementById('ksBtnSubmit');

    if (btnHome) btnHome.style.display = (targetStep === 1) ? 'inline-block' : 'none';
    if (btnCloseAlt) btnCloseAlt.style.display = (targetStep === 1) ? 'inline-block' : 'none';
    if (btnPrev) {
      btnPrev.style.display = (targetStep > 1) ? 'inline-block' : 'none';
      if (targetStep === 2) btnPrev.innerHTML = '&larr; Kembali ke Identitas';
      else if (targetStep === 3) btnPrev.innerHTML = '&larr; Kembali ke SKM';
      else if (targetStep === 4) btnPrev.innerHTML = '&larr; Kembali ke SPKP';
      else if (targetStep === 5) btnPrev.innerHTML = '&larr; Kembali ke SPAK';
    }

    if (btnNext) {
      btnNext.style.display = (targetStep < 5) ? 'inline-block' : 'none';
      if (targetStep === 1) btnNext.innerHTML = 'Lanjut ke SKM &rarr;';
      else if (targetStep === 2) btnNext.innerHTML = 'Lanjut ke SPKP &rarr;';
      else if (targetStep === 3) btnNext.innerHTML = 'Lanjut ke SPAK &rarr;';
      else if (targetStep === 4) btnNext.innerHTML = 'Lanjut ke Saran & Kirim &rarr;';
    }

    if (btnSubmit) btnSubmit.style.display = (targetStep === 5) ? 'inline-block' : 'none';

    const body = document.getElementById('ksModalBody');
    if (body) body.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function prevKsModalStep() {
    if (ksCurrentStep > 1) {
      switchKsModalStep(ksCurrentStep - 1, true);
    }
  }

  function nextKsModalStep() {
    if (ksCurrentStep < 5) {
      switchKsModalStep(ksCurrentStep + 1, false);
    }
  }

  async function submitKsModalSurvei() {
    hideKsAlert();
    const idPengajuan = parseInt(document.getElementById('ks_id_pengajuan').value, 10);
    if (!idPengajuan || idPengajuan <= 0) {
      showKsAlert('ID Permohonan KIA tidak valid.');
      return;
    }

    const responden = validateKsIdentity();
    if (!responden) {
      switchKsModalStep(1, true);
      return;
    }

    if (Object.keys(ksAnswers.skm).length < 9) {
      switchKsModalStep(2, true);
      showKsAlert('Mohon lengkapi seluruh 9 pertanyaan SKM.');
      return;
    }
    if (Object.keys(ksAnswers.spkp).length < 8) {
      switchKsModalStep(3, true);
      showKsAlert('Mohon lengkapi seluruh 8 pernyataan SPKP.');
      return;
    }
    if (Object.keys(ksAnswers.spak).length < 5) {
      switchKsModalStep(4, true);
      showKsAlert('Mohon lengkapi seluruh 5 pernyataan SPAK.');
      return;
    }

    const csrfToken = document.getElementById('ks_csrf_token').value;
    const btnSubmit = document.getElementById('ksBtnSubmit');
    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Menyimpan...';

    const payloads = [
      { jenis: 'skm', jawaban: ksAnswers.skm, saran: (document.getElementById('ks_saran_skm').value || '').trim() },
      { jenis: 'spkp', jawaban: ksAnswers.spkp, saran: (document.getElementById('ks_saran_spkp').value || '').trim() },
      { jenis: 'spak', jawaban: ksAnswers.spak, saran: (document.getElementById('ks_saran_spak').value || '').trim() },
    ];

    try {
      for (const item of payloads) {
        const fd = new FormData();
        fd.append('action', 'submit_survei');
        fd.append('csrf_token', csrfToken);
        fd.append('id_pengajuan', String(idPengajuan));
        fd.append('jenis_survei', item.jenis);
        fd.append('responden', JSON.stringify(responden));
        fd.append('jawaban', JSON.stringify(item.jawaban));
        fd.append('saran', item.saran);

        const resp = await fetch('kia.php?action=submit_survei', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd
        });
        const resJson = await resp.json();
        if (!resJson.success && !(resJson.error && resJson.error.includes('sudah pernah diisi'))) {
          throw new Error(resJson.error || ('Gagal menyimpan survei ' + item.jenis.toUpperCase()));
        }
      }

      ksIsCompleted = true;

      // Sembunyikan bagian step dan footer, tampilkan layar sukses
      for (let i = 1; i <= 5; i++) {
        const pane = document.getElementById('ksStepPane' + i);
        if (pane) pane.style.display = 'none';
      }
      document.getElementById('ksModalTabs').style.display = 'none';
      document.getElementById('ksModalFooter').style.display = 'none';
      document.getElementById('ksStepPaneSuccess').style.display = 'block';
    } catch (err) {
      showKsAlert(err.message || 'Gagal mengirim survei. Silakan coba lagi.');
    } finally {
      btnSubmit.disabled = false;
      btnSubmit.textContent = 'Kirim Survei ✓';
    }
  }
</script>
