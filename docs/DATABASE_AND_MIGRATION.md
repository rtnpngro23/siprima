# Panduan Database & Migrasi SIPRIMA

Dokumen ini menjelaskan struktur skema database, urutan impor/migrasi idempoten, serta pemetaan variabel environment pada `app/config/.env.example`.

---

## 1. Prasyarat Database

- MySQL `8.0+` atau MariaDB `10.6+`
- Engine: `InnoDB` (wajib untuk transaksi dan foreign key, termasuk `tb_users` dan modul KIA)
- Charset / Collation: `utf8mb4` / `utf8mb4_unicode_ci`

Buat database baru:
```sql
CREATE DATABASE siprima_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## 2. Urutan Impor Skema & Migrasi

Seluruh skrip pada folder `sql/` telah dirancang **idempoten** (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, atau pengecekan kolom/indeks sebelum `ALTER`), sehingga aman dijalankan pada database baru maupun database yang sudah memiliki sebagian tabel.

### A. Instalasi Database Baru (Dari Awal)
1. **Skema Utama Lengkap (DDL murni, tanpa data pribadi warga):**
   ```bash
   mysql -u <user> -p <nama_db> < sql/schema_siprima_lengkap.sql
   ```
   Mencakup tabel inti pengguna, wilayah (`tb_kecamatan`, `tb_desa`), seluruh tabel layanan kependudukan (KK, KIA, Akta Kelahiran, Akta Kematian, Pindah, AHLAK, KUA, RS, Laporan Peristiwa), `tb_permohonan_status_history`, `tb_notifikasi`, `tb_document_features`, `tb_document_features_audit`, `tb_service_settings`, `tb_whatsapp_log`, dan lainnya.
2. **Migrasi Tambahan & Keamanan:**
   ```bash
   mysql -u <user> -p <nama_db> < sql/20260923_document_features.sql
   mysql -u <user> -p <nama_db> < sql/20261122_create_whatsapp_log.sql
   php sql/security_20260913.php --apply
   php sql/migrate_service_settings.php
   php sql/mobile_api_migrate.php
   ```
3. **Data Master Wilayah & Akun Admin Awal:**
   Isi data master `tb_kecamatan`, `tb_desa` (dan `tb_kua`, `tb_puskesmas`, `tb_rumahsakit` bila portal mitra digunakan) dari master data resmi instansi, lalu buat akun Admin awal dengan hash `password_hash(..., PASSWORD_DEFAULT)`.

### B. Daftar Lengkap File di `sql/`

| File | Tujuan | Sifat |
|---|---|---|
| `sql/schema_siprima_lengkap.sql` | DDL lengkap seluruh tabel, primary key, index, foreign key, dan seed awal sakelar sistem | Idempoten (`CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`) |
| `sql/2026-08-21_create_permohonan_feedback.sql` | Tabel rating & feedback pemohon | Idempoten |
| `sql/2026-09-02_create_whistleblowing.sql` | Tabel modul Whistleblowing System (WBS) | Idempoten |
| `sql/20260906_service_settings.sql` | Tabel jadwal jam layanan & penutupan khusus | Idempoten |
| `sql/20260907_service_tracking.sql` | Tabel pelacakan riwayat status permohonan (`tb_permohonan_status_history`) | Idempoten |
| `sql/20260913_mobile_api.sql` | Tabel dukungan REST API Mobile & outbox | Idempoten |
| `sql/20260923_document_features.sql` | Tabel `tb_document_features` & `tb_document_features_audit` + seed default | Idempoten |
| `sql/20261101_pengajuan_kia.sql` | Tabel inti modul KIA (`tb_pengajuan_kia`, `tb_anak_kia`, `tb_dokumen_kia`) | Idempoten |
| `sql/20261102_kia_features_final.sql` | Tabel laporan kesalahan pasca-selesai & survei KIA (`tb_laporan_kesalahan_kia`, `tb_survei_kia`, `tb_jawaban_survei_kia`) | Idempoten |
| `sql/20261122_create_whatsapp_log.sql` | Tabel riwayat & audit pengiriman WhatsApp (`tb_whatsapp_log`) | Idempoten (juga dicadangkan `WhatsAppService::ensureTable()`) |
| `sql/security_20260913.php` | Migrasi keamanan password reset (`tb_password_reset`, kolom `must_change_password`, `temporary_password_expires_at`) | Idempoten (`--apply`) |
| `sql/migrate_service_settings.php` | Migrator PHP pengaturan jam layanan | Idempoten |
| `sql/mobile_api_migrate.php` | Migrator PHP Mobile API | Idempoten |

---

## 3. Pemetaan Variabel Konfigurasi (`app/config/.env.example`)

Seluruh **27 variabel environment** yang digunakan pada `app/` dan `public/` sudah terdokumentasi di `app/config/.env.example`:
- **Database:** `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- **Aplikasi & Keamanan:** `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_TIMEZONE`, `APP_PUBLIC_URL`, `APP_URL`, `APP_SESSION_SECRET`, `SIPRIMA_LOG_DIR`, `LOGIN_THROTTLE_DIR`, `LOGIN_TRUSTED_PROXIES`
- **WhatsApp Gateway Pemohon:** `WA_GATEWAY_ENABLED`, `WA_GATEWAY_PROVIDER`, `WA_GATEWAY_URL`, `WA_PHONE_NUMBER_ID`, `WA_API_KEY`, `WA_SENDER`
- **Email SMTP:** `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SENDER_NAME`, `ADMIN_EMAIL`, `SUPPORT_EMAIL`
- **Telegram & WBS:** `TELEGRAM_ENABLED`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `WBS_PUBLIC_API_TOKEN`
- **ASPRIMA AI (Opsional):** `ASPRIMA_AI_ENABLED`, `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`
- **Mobile API:** `MOBILE_API_ENABLED`, `MOBILE_API_ALLOW_HTTP`
