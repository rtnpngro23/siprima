# Panduan Aktivasi & Deployment Integrasi WhatsApp Pemohon (Meta Cloud API & Fonnte) — SIPRIMA

Dokumen ini menjelaskan arsitektur multi-provider notifikasi WhatsApp SIPRIMA untuk layanan KIA, konfigurasi `.env`, panduan aktivasi **Meta WhatsApp Business Platform (Cloud API)** resmi, daftar template pesan yang wajib disetujui Meta, serta opsi fallback **Fonnte**.

---

## 1. Arsitektur Multi-Provider

SIPRIMA memisahkan logika bisnis KIA dari detail API penyedia WhatsApp:

- **Orchestrator:** `app/Services/WhatsAppService.php`
  - Validasi penerima khusus Pemohon (`role = 'user'`) dan persetujuan eksplisit (`tb_users.wa_consent = 1`).
  - Normalisasi nomor ke format internasional E.164 tanpa `+` (`628xxxxxxxxxx`, 10–15 digit).
  - Sanitasi anti-PII (memblokir deretan 16 digit NIK/KK) dan penyamaran token/secret di log.
  - Idempotensi (`isAlreadySent`), pencatatan log ke `tb_whatsapp_log`, dan retry satuan/massal dari Admin.
  - Non-blocking: seluruh panggilan dari `app/Models/KiaModel.php` dijalankan **setelah `$this->db->commit()`** sehingga kegagalan jaringan/provider tidak pernah menggagalkan transaksi KIA.
- **Kontrak Provider:** `app/Services/WhatsApp/WhatsAppProviderInterface.php`
- **Provider Meta Resmi:** `app/Services/WhatsApp/Providers/MetaCloudProvider.php`
  - Endpoint dinamis: `https://graph.facebook.com/{WA_META_API_VERSION}/{WA_PHONE_NUMBER_ID}/messages`
  - Autentikasi: `Authorization: Bearer {WA_META_ACCESS_TOKEN}`
  - Payload: `type: "template"` (Meta Approved Message Template, kategori `UTILITY`, bahasa `id`)
  - Keamanan TLS: `CURLOPT_SSL_VERIFYPEER = true`, `CURLOPT_SSL_VERIFYHOST = 2`
- **Provider Alternatif (Tetap Dipertahankan):** `app/Services/WhatsApp/Providers/FonnteProvider.php`
  - Endpoint: `WA_GATEWAY_URL` (default `https://api.fonnte.com/send`), header `Authorization: {WA_API_KEY}`

Penggantian provider dilakukan cukup melalui `WA_GATEWAY_PROVIDER` di `app/config/.env` (`meta_cloud` atau `fonnte`) tanpa mengubah kode KIA.

---

## 2. Konfigurasi `app/config/.env` (Placeholder — Tanpa Kredensial Asli)

Salin `app/config/.env.example` menjadi `app/config/.env` di server (jangan pernah commit `app/config/.env` ke Git).

### Opsi A — Meta WhatsApp Cloud API Resmi (Direkomendasikan)
```dotenv
WA_GATEWAY_ENABLED=true
WA_GATEWAY_PROVIDER=meta_cloud
WA_SSL_VERIFY=true

WA_META_API_VERSION=v22.0
WA_PHONE_NUMBER_ID=replace_with_meta_phone_number_id
WA_META_WABA_ID=replace_with_meta_waba_id
WA_META_ACCESS_TOKEN=replace_with_permanent_system_user_token
WA_META_TEMPLATE_LANG=id
```

### Opsi B — Fonnte (Provider Alternatif / Rollback)
```dotenv
WA_GATEWAY_ENABLED=true
WA_GATEWAY_PROVIDER=fonnte
WA_SSL_VERIFY=true

WA_GATEWAY_URL=https://api.fonnte.com/send
WA_API_KEY=replace_with_fonnte_api_key
```

---

## 3. Daftar 6+ Template Pesan Wajib Didaftarkan & Disetujui Meta

Karena notifikasi KIA bersifat proaktif (di luar 24-hour customer service window), seluruh pesan Meta Cloud API dikirim menggunakan **Approved Message Template** kategori **`UTILITY`** bahasa **`id`**.

Setiap template menggunakan **2 parameter body** yang bebas NIK, Nomor KK, dan lampiran dokumen:
- `{{1}}` = Nama Pemohon
- `{{2}}` = Nomor Referensi Permohonan (mis. `#102`)

| # | Peristiwa KIA | `event_type` SIPRIMA | Nama Template Default (`.env` override) | Usulan Teks Body Template (Bahasa `id`) |
|---:|---|---|---|---|
| 1 | Permohonan KIA berhasil diajukan / dikirim ulang | `kia_diajukan`, `kia_diajukan_ulang_v*` | `siprima_kia_diajukan` (`WA_META_TPL_KIA_DIAJUKAN`) | `Halo {{1}}, permohonan Kartu Identitas Anak (KIA) Anda ({{2}}) berhasil diajukan dan sedang menunggu pemeriksaan petugas. Silakan pantau status permohonan melalui akun SIPRIMA Anda.` |
| 2 | Permohonan memerlukan perbaikan | `kia_ditolak_v*` | `siprima_kia_perbaikan` (`WA_META_TPL_KIA_PERBAIKAN`) | `Halo {{1}}, permohonan KIA Anda ({{2}}) memerlukan perbaikan data atau dokumen. Silakan masuk ke akun SIPRIMA untuk membaca catatan petugas dan mengirimkan perbaikan.` |
| 3 | Permohonan selesai diproses | `kia_selesai` | `siprima_kia_selesai` (`WA_META_TPL_KIA_SELESAI`) | `Halo {{1}}, permohonan Kartu Identitas Anak (KIA) Anda ({{2}}) telah selesai diproses. Silakan masuk ke akun SIPRIMA untuk informasi lebih lanjut.` |
| 4 | Dokumen PDF KIA tersedia | `kia_pdf_tersedia*` | `siprima_kia_pdf_tersedia` (`WA_META_TPL_KIA_PDF_TERSEDIA`) | `Halo {{1}}, dokumen PDF Kartu Identitas Anak (KIA) pada permohonan {{2}} sudah tersedia dan dapat diunduh melalui akun SIPRIMA Anda.` |
| 5 | Operator menanggapi / menyelesaikan laporan kesalahan | `kia_balasan_laporan_*` | `siprima_kia_tanggapan_laporan` (`WA_META_TPL_KIA_BALASAN_LAPORAN`) | `Halo {{1}}, petugas telah memberikan tanggapan atas laporan kesalahan dokumen KIA pada permohonan {{2}}. Silakan masuk ke akun SIPRIMA untuk melihat balasan.` |
| 6 | Dokumen PDF KIA pengganti tersedia | `kia_pdf_pengganti_*` | `siprima_kia_pdf_pengganti` (`WA_META_TPL_KIA_PDF_PENGGANTI`) | `Halo {{1}}, dokumen PDF KIA pengganti versi terbaru pada permohonan {{2}} sudah tersedia. Silakan masuk ke akun SIPRIMA untuk memeriksa dan mengunduh dokumen terbaru.` |
| 7 | *(Opsional)* Kartu fisik KIA selesai dicetak | `kia_selesai_dicetak` | `siprima_kia_siap_diambil` (`WA_META_TPL_KIA_SELESAI_DICETAK`) | `Halo {{1}}, kartu fisik KIA pada permohonan {{2}} telah selesai dicetak dan siap diambil di kantor Dukcapil Kabupaten Gorontalo.` |

---

## 4. Persetujuan Pemohon (`wa_consent`) & Privasi

- **Penerima terbatas:** Hanya akun Pemohon (`role = 'user'`) yang secara eksplisit memilih **Setuju** (`tb_users.wa_consent = 1`). Operator dan Admin tidak pernah dikirimi WhatsApp oleh sistem.
- **UI Opt-In Sukarela:**
  - Saat pendaftaran akun baru di `public/register.php` (pilihan **Setuju** / **Tidak Setuju**, default **Tidak Setuju**).
  - Di halaman profil Pemohon `public/user/profil.php` pada bagian khusus **Preferensi Notifikasi WhatsApp** (form terpisah sehingga perubahan email, nomor HP, atau password tidak pernah mengubah status persetujuan secara otomatis).
- **Perlindungan Data:** Pesan tidak pernah memuat NIK, Nomor KK, atau file dokumen; log error menyamarkan token/secret dan deretan 16 digit.

---

## 5. Langkah Aktivasi Meta Cloud API (Untuk Developer & Instansi)

1. Verifikasi **Meta Business Portfolio** resmi milik instansi.
2. Buat **Meta Developer App** (tipe *Business*) dan tambahkan produk **WhatsApp**.
3. Daftarkan nomor khusus API pada **WhatsApp Manager** -> catat `WA_PHONE_NUMBER_ID` dan `WA_META_WABA_ID`.
4. Buat **System User (Admin)** dengan izin `whatsapp_business_messaging` & `whatsapp_business_management`, lalu buat **Permanent Access Token** -> simpan sebagai `WA_META_ACCESS_TOKEN` di `app/config/.env`.
5. Daftarkan 6–7 template pesan pada tabel Bagian 3 dengan kategori `UTILITY` dan bahasa `id` hingga berstatus **Approved**.
6. Aktifkan metode pembayaran (billing) pada WABA di Meta Business Manager.
7. Set `WA_GATEWAY_PROVIDER=meta_cloud` dan `WA_GATEWAY_ENABLED=true` di `app/config/.env`, lalu verifikasi checklist hijau pada halaman **Admin → Pengaturan Layanan → Notifikasi WhatsApp** (`public/admin/pengaturan_notifikasi_whatsapp.php`).
8. **Catatan Webhook:** Webhook status pengiriman asinkron (`delivered`/`read`/`failed`) sengaja ditunda pada tahap ini; status `sent` di `tb_whatsapp_log` menunjukkan pesan telah diterima Meta Cloud API dengan konfirmasi `messages[0].id` (`wamid`).
