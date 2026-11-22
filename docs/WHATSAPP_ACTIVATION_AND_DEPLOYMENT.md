# Panduan Aktivasi WhatsApp Gateway & Handover Deployment SIPRIMA

Dokumen ini menjelaskan konfigurasi, arsitektur, aturan privasi, dan prosedur pengujian integrasi notifikasi **WhatsApp Pemohon** pada modul Kartu Identitas Anak (KIA) serta kesiapan deployment aplikasi **SIPRIMA**.

---

## 1. Arsitektur Integrasi WhatsApp

Komponen utama integrasi WhatsApp pada SIPRIMA:

| Komponen | Lokasi File | Tanggung Jawab |
|---|---|---|
| Service Pengiriman & Audit Log | `app/Services/WhatsAppService.php` | Validasi penerima Pemohon, normalisasi nomor HP (`62...`), cek idempotensi event, pengiriman HTTP ke gateway (`fonnte`, `cloud_api`/`meta`, `generic`), verifikasi konfirmasi respons provider, sanitasi log kegagalan, dan mekanisme retry (`retryNotifikasi`, `retryAllFailedOrSkipped`). |
| Pemicu Event Layanan KIA | `app/Models/KiaModel.php` | Memanggil `sendWhatsAppToPemohon()` secara non-blocking pada setiap peristiwa siklus hidup permohonan KIA. |
| Subhalaman Pemantauan Admin | `public/admin/pengaturan_notifikasi_whatsapp.php` | Menampilkan status integrasi/API, checklist kebutuhan `.env`, statistik (`sent`, `failed`, `skipped`, `pending`), filter riwayat, serta tombol **Coba Kirim Ulang**. |
| Penyimpanan Riwayat | Tabel `tb_whatsapp_log` | Dibuat otomatis secara idempoten oleh `WhatsAppService::ensureTable()`. |
| Loader Environment | `app/config/koneksi.php`, `app/config/service_hours.php`, `app/config/env_loader.php` | Membaca konfigurasi dari `app/config/.env` (didukung pula override melalui environment variabel OS/server web). |

---

## 2. Variabel Konfigurasi (`.env`)

Salin `app/config/.env.example` menjadi `app/config/.env`, lalu isi variabel berikut sesuai penyedia gateway:

| Variabel | Wajib | Contoh Nilai | Keterangan |
|---|---|---|---|
| `WA_GATEWAY_ENABLED` | Ya | `true` | Atur `true` untuk mengaktifkan pengiriman ke server provider. Bila `false`/kosong, pesan tetap dicatat ke `tb_whatsapp_log` berstatus `skipped` (mode aman/non-blokir). |
| `WA_GATEWAY_PROVIDER` | Ya | `fonnte` / `cloud_api` / `generic` | Menentukan format payload HTTP POST dan aturan verifikasi respons sukses provider. |
| `WA_API_KEY` | Ya | *(token rahasia provider)* | Header `Authorization` / `Bearer` menuju gateway. Tidak pernah ditampilkan pada antarmuka admin maupun disimpan mentah di log error. |
| `WA_GATEWAY_URL` | Ya (kecuali Meta dengan `WA_PHONE_NUMBER_ID`) | `https://api.fonnte.com/send` | Endpoint HTTP POST provider gateway. |
| `WA_PHONE_NUMBER_ID` | Khusus Meta Cloud API | `123456789012345` | Jika `WA_GATEWAY_URL` kosong dan provider `cloud_api` / `meta` / `whatsapp_cloud`, endpoint dibangun ke `https://graph.facebook.com/v19.0/{WA_PHONE_NUMBER_ID}/messages`. |
| `WA_SENDER` | Opsional | `62811xxxx` | Catatan administratif nomor pengirim resmi instansi. |

### Contoh Konfigurasi Berdasarkan Provider

#### Opsi A: Fonnte (`WA_GATEWAY_PROVIDER=fonnte`)
```env
WA_GATEWAY_ENABLED=true
WA_GATEWAY_PROVIDER=fonnte
WA_GATEWAY_URL=https://api.fonnte.com/send
WA_API_KEY=isi_token_device_fonnte_anda
```
`WhatsAppService::verifyProviderAcceptance()` hanya menetapkan status `sent` apabila HTTP status `2xx` **dan** JSON respons Fonnte mengembalikan `"status": true`.

#### Opsi B: Meta WhatsApp Cloud API (`WA_GATEWAY_PROVIDER=cloud_api`)
```env
WA_GATEWAY_ENABLED=true
WA_GATEWAY_PROVIDER=cloud_api
WA_PHONE_NUMBER_ID=isi_phone_number_id_meta
WA_API_KEY=isi_permanent_system_user_token_meta
```
`WhatsAppService::verifyProviderAcceptance()` hanya menetapkan status `sent` apabila HTTP status `2xx`, tidak terdapat objek `"error"`, dan JSON respons mengandung `messages[0].id`.

#### Opsi C: Generic JSON Webhook (`WA_GATEWAY_PROVIDER=generic`)
Mengirim JSON `{"phone": "628...", "message": "..."}` dengan header `Authorization: Bearer {WA_API_KEY}` ke `WA_GATEWAY_URL`. Respons ditolak apabila HTTP non-2xx, body kosong, atau JSON mengandung `"error"`, `"status": false`, atau `"success": false`.

---

## 3. Aturan Penerima, Persetujuan, & Privasi Data

1. **Hanya Pemohon (`role = 'user'`):** `validatePemohonRecipient()` memblokir pengiriman apabila `id_user` ber-role `operator`, `admin`, atau role internal lainnya (`status = 'skipped'`). Operator dan Admin hanya menerima notifikasi internal SIPRIMA.
2. **Persetujuan Pemohon (`wa_consent`):** Jika kolom `tb_users.wa_consent = 0`, pengiriman dilewati (`status = 'skipped'`). Registrasi dan pembaruan profil Pemohon mengatur kolom persetujuan ini.
3. **Validasi & Normalisasi Nomor HP:** `WhatsAppService::formatNomorHp()` mengonversi awalan `08...` / `8...` / `+62...` menjadi `62...` dan menolak nomor di luar panjang `10–15` digit (`status = 'failed'`).
4. **Sanitasi Kebocoran PII & Token:**
   - Deretan 16 digit (NIK / No. KK) pada isi pesan dan respons provider disamarkan menjadi `[DATA TERLINDUNGI]`.
   - Tidak ada file pasfoto, dokumen PDF, NIK, atau Nomor KK yang dikirimkan melalui WhatsApp; pesan hanya mengarahkan Pemohon masuk ke akun SIPRIMA.
   - Header `Authorization: Bearer ...` dan nilai `WA_API_KEY` disamarkan sebelum disimpan ke `tb_whatsapp_log.response_raw` via `sanitizeResponseNote()`.
5. **Idempotensi:** `isAlreadySent($permohonanId, $eventType)` mencegah pengiriman ulang ganda apabila kombinasi permohonan dan `event_type` sudah berstatus `sent`.

---

## 4. Enam Peristiwa Pemicu KIA (`event_type`)

1. **`kia_diajukan` / `kia_diajukan_ulang_v{versi}`** — Pengajuan KIA baru atau pengajuan perbaikan revisi berhasil dikirim Pemohon (`KiaModel::createPengajuan`, `KiaModel::resubmitPengajuan`).
2. **`kia_ditolak_v{versi}`** — Pengajuan membutuhkan perbaikan / ditolak oleh Operator (`KiaModel::updateStatus`).
3. **`kia_selesai`** — Verifikasi pengajuan KIA disetujui / selesai (`KiaModel::updateStatus`).
4. **`kia_pdf_tersedia` / `kia_pdf_tersedia_{idAnak}_v{versiPdf}`** — Berkas PDF hasil KIA sudah tersedia untuk diunduh Pemohon (`KiaModel::updateStatus`, `KiaModel::uploadDokumenHasilPdf`).
5. **`kia_balasan_laporan_{idLaporan}_m{idPesan}` / `..._selesai`** — Operator membalas atau menyelesaikan laporan kesalahan dokumen Pemohon (`KiaModel::tambahPesanLaporanKesalahan`, `KiaModel::selesaikanLaporanKesalahan`).
6. **`kia_pdf_pengganti_{idAnak}_v{versiPdf}`** — Dokumen PDF pengganti pasca-koreksi laporan kesalahan tersedia (`KiaModel::uploadDokumenHasilPdf`).

---

## 5. Langkah Aktivasi & Pengujian di Server

1. Pastikan migrasi skema telah dijalankan (`sql/schema_siprima_lengkap.sql` serta migrasi tambahan pada `sql/` termasuk `sql/20261101_pengajuan_kia.sql`, `sql/20261102_kia_features_final.sql`, dan `php sql/security_20260913.php --apply`).
2. Salin `app/config/.env.example` ke `app/config/.env`, isi kredensial database produksi, `APP_ENV=production`, `APP_PUBLIC_URL=https://...`, dan kredensial WhatsApp (`WA_GATEWAY_ENABLED=true`, `WA_GATEWAY_PROVIDER`, `WA_GATEWAY_URL`/`WA_PHONE_NUMBER_ID`, `WA_API_KEY`).
3. Pastikan direktori runtime (`private_logs`, `app/cache`, serta folder `public/uploads` & `storage/whistleblowing`) memiliki izin tulis yang tepat untuk user web server PHP dan berada di luar akses publik bila terkait log/throttling (`SIPRIMA_LOG_DIR`, `LOGIN_THROTTLE_DIR`).
4. Login sebagai Admin lalu buka menu **Pengaturan Layanan -> Notifikasi WhatsApp** (`public/admin/pengaturan_notifikasi_whatsapp.php`):
   - Pastikan seluruh indikator checklist (`WA_GATEWAY_ENABLED`, `WA_GATEWAY_PROVIDER`, `WA_API_KEY`, Endpoint/Phone ID) menunjukkan badge **Siap**.
   - Lakukan satu pengajuan uji menggunakan akun Pemohon bernomor WhatsApp aktif, lalu verifikasi entri berstatus **BERHASIL DIKIRIM** muncul di tabel riwayat.
   - Jika terdapat antrean lama yang berstatus `skipped` (ketika gateway sebelumnya belum aktif) atau `failed` (gangguan sementara provider), gunakan tombol **Coba Kirim Ulang** pada baris terkait atau tombol **Coba Kirim Ulang Antrean Gagal / Tertunda**.

---

## 6. Status Kesiapan Fitur vs Kebutuhan Kredensial Eksternal

| Fitur / Modul | Status Source Code | Kebutuhan Akun / Kredensial Saat Deployment |
|---|---|---|
| Pengajuan KIA Multi-Anak, Pasfoto 480×600, Verifikasi Operator, Revisi, Multi-PDF Hasil per Anak | **Selesai (Siap Produksi)** | Tidak ada kredensial pihak ketiga; butuh schema DB + direktori upload writable. |
| Laporan Kesalahan Pasca-Selesai, Percakapan Pemohon–Operator, Persediaan Blangko & Tinta, Survei SKM/SPKP/SPAK | **Selesai (Siap Produksi)** | Tidak ada kredensial pihak ketiga. |
| Integrasi Kinerja Operator & Laporan Admin (termasuk KIA tanpa double counting) | **Selesai (Siap Produksi)** | Tabel `tb_permohonan_status_history` aktif. |
| Notifikasi WhatsApp Pemohon (6 event, privasi, retry, checklist UI Admin) | **Selesai pada Source Code (Mode Non-Blokir)** | **Memerlukan akun & token WhatsApp Gateway (`WA_API_KEY` + `WA_GATEWAY_ENABLED=true`)** pada `app/config/.env`. |
| Pengiriman Tautan Reset Password / Email Konfirmasi Dokumen | **Selesai pada Source Code** | **Memerlukan kredensial SMTP produksi** (`SMTP_HOST`, `SMTP_USERNAME`, `SMTP_PASSWORD`) & opsional bot Telegram (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`). |
| Asisten Virtual ASPRIMA | **Selesai pada Source Code** | **Opsional `ANTHROPIC_API_KEY`** di `app/config/.env.local`; jika kosong, otomatis menggunakan panduan lokal SIPRIMA tanpa error. |
