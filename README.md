# SI PRIMA — Sistem Informasi Pelayanan Terintegrasi Identitas Masyarakat

**SI PRIMA** adalah aplikasi layanan administrasi kependudukan berbasis web (PHP/MySQL) untuk **Dinas Kependudukan dan Pencatatan Sipil (Disdukcapil) Kabupaten Gorontalo**, mencakup layanan Pemohon mandiri, Petugas Desa (ADD), Operator Dukcapil, Admin, serta portal mitra KUA (PASUTRI), Puskesmas (AHLAK), Rumah Sakit (ST SEHATI), dan REST API Mobile.

---

## Fitur Utama

- **Layanan Kependudukan Online:** Kartu Keluarga (KK), Kartu Identitas Anak (KIA — multi-anak dalam 1 KK, crop pasfoto 480×600 px rasio 4:5, unggah multi-PDF hasil per anak, laporan kesalahan pasca-selesai, percakapan Pemohon–Operator, pengajuan cetak fisik, persediaan Blangko & Tinta), Akta Kelahiran, Akta Kematian, Pindah Alamat, Pindah Masuk, dan Laporan Peristiwa (SIP PKK).
- **Portal Mitra Terintegrasi:** ADD (Petugas Desa), PASUTRI (KUA), AHLAK (Puskesmas), dan ST SEHATI (Rumah Sakit).
- **Monitoring & Pelaporan Admin:** Dashboard Admin, Laporan & Analisis, Kinerja Operator (termasuk kontribusi layanan KIA berbasis riwayat status tanpa double counting), Hasil Survei SKM/SPKP/SPAK + ekspor Excel multi-sheet (`.xlsx`), Pengaturan Jam Layanan & Formulir Digital, Whistleblowing System (WBS).
- **Notifikasi Internal & Integrasi WhatsApp Pemohon:** Notifikasi internal aplikasi untuk seluruh role serta integrasi WhatsApp khusus Pemohon (`role = 'user'`, non-blocking, dengan validasi persetujuan `wa_consent`, sanitasi NIK/No. KK, verifikasi respons provider, log riwayat, dan tombol *Coba Kirim Ulang* pada subhalaman Admin).
- **Asisten Virtual ASPRIMA & Kamus Nama Bayi:** Panduan layanan otomatis (fallback lokal aman tanpa API key eksternal) dan katalog referensi nama bayi.
- **Keamanan Aplikasi:** Kebijakan password kuat, token reset password sekali pakai (SHA-256 + URL fragment), session fingerprint & strict cookie, rate limiting login berbasis `flock()`, proteksi akses dokumen berbasis token, serta proteksi CSRF.

---

## Teknologi & Persyaratan Sistem

- **PHP:** `8.1+` (direkomendasikan `8.2` / `8.3`) dengan ekstensi `mysqli`, `gd` (untuk pengolahan pasfoto KIA & render formulir F-1.01/F-2.01), `curl`, `mbstring`, `fileinfo`, `json`, `zip`/`zlib`.
- **Database:** MySQL `8.0+` atau MariaDB `10.6+` (engine `InnoDB`, charset `utf8mb4`).
- **Web Server:** Apache `2.4+` (dengan `mod_rewrite` aktif untuk `public/api/v1/.htaccess`) atau Nginx + PHP-FPM.
- **Lingkungan Lokal:** Laragon (Windows) atau LAMP/LEMP stack.

---

## Instalasi Lokal (Laragon)

1. **Clone repository** ke direktori web Laragon (misal `C:\laragon\www\siprima`).
2. **Arahkan Document Root** virtual host (misal `http://siprima.test`) ke folder `public/`.
3. **Konfigurasi Environment:**
   Salin template `app/config/.env.example` menjadi `app/config/.env`:
   ```bash
   cp app/config/.env.example app/config/.env
   ```
   Sesuaikan minimal variabel database dan URL lokal pada `app/config/.env`:
   ```env
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=nama_database_anda
   DB_USER=root
   DB_PASS=
   APP_ENV=local
   APP_PUBLIC_URL=http://siprima.test
   ```
   *(Jangan pernah commit file `app/config/.env` ke Git.)*
4. **Buat Database & Jalankan Migrasi:**
   Buat database baru ber-charset `utf8mb4_unicode_ci`, lalu impor skema utama dan migrasi sesuai panduan pada [`docs/DATABASE_AND_MIGRATION.md`](docs/DATABASE_AND_MIGRATION.md):
   ```bash
   mysql -u root nama_database_anda < sql/schema_siprima_lengkap.sql
   mysql -u root nama_database_anda < sql/20260923_document_features.sql
   mysql -u root nama_database_anda < sql/20261122_create_whatsapp_log.sql
   php sql/security_20260913.php --apply
   ```
5. **Siapkan Direktori Tulis (Writable):**
   Pastikan web server memiliki izin tulis pada `app/cache/`, `logs/`, `public/uploads/`, `public/user/uploads/`, `public/user_ahlak/uploads/`, `public/user_kua/uploads/`, `public/user_rs/uploads/`, dan `storage/whistleblowing/`.
6. **Akses Aplikasi:** Buka `http://siprima.test/index.php`.

---

## Struktur Direktori

```text
siprima/
├── .env.example                 # Petunjuk konfigurasi environment
├── .gitignore                   # Pengecualian file sensitif, upload, log, cache, backup
├── mobile_api_maintenance.php   # Worker CLI outbox & cleanup Mobile API
├── app/
│   ├── Api/                     # REST API Mobile
│   ├── Controllers/             # Controller KIA (KiaController.php)
│   ├── Laporan/                 # KinerjaOperator, LaporanFilter, LaporanRepository
│   ├── Models/                  # KiaModel.php
│   ├── Routes/                  # KiaRouter.php
│   ├── Services/                # WhatsAppService.php
│   ├── config/                  # koneksi.php, env_loader.php, service_hours.php, .env.example
│   ├── data/                    # Font LiberationSans & data pendukung
│   ├── middleware/              # Guard otorisasi per role
│   ├── security/                # Auth, login throttling, password policy & reset
│   └── views/                   # Partial view bersama
├── docs/                        # Dokumentasi teknis, deployment, database, API, & WhatsApp
├── public/                      # Web Document Root
│   ├── admin/                   # Portal Admin
│   ├── api/v1/                  # Endpoint JSON Mobile API
│   ├── assets/                  # CSS, JS, gambar, font/ikon
│   ├── functions/               # Helper notifikasi, formulir, dokumen, email
│   ├── user/                    # Portal Pemohon, Petugas Desa, Operator Dukcapil
│   ├── user_ahlak/              # Portal Puskesmas (AHLAK)
│   ├── user_kua/                # Portal KUA (PASUTRI)
│   └── user_rs/                 # Portal Rumah Sakit (ST SEHATI)
├── sql/                         # Skema DDL lengkap & file migrasi idempoten
├── storage/                     # Proteksi direktori storage (.htaccess)
├── tests/                       # Suite pengujian otomatis unit & integrasi
└── tools/                       # Generator OpenAPI & dev router
```

---

## Peran Pengguna (Role)

Akun pengguna disimpan di tabel `tb_users` dengan autentikasi 16 digit NIK dan hash password (`password_hash`). Tidak ada akun atau kredensial default yang disertakan dalam repository; buat akun Admin awal secara aman pada database saat deployment pertama melalui prosedur administrasi server.

- `admin` — Pengelolaan pengguna, pengaturan layanan, pemantauan WhatsApp, kinerja operator, laporan, dan hasil survei.
- `operator` — Verifikasi pengajuan, unggah PDF hasil KIA per anak, tanggapan laporan kesalahan, dan cetak kartu.
- `user` — Pemohon layanan kependudukan (termasuk pengajuan KIA, unduh PDF hasil, laporan kesalahan, dan survei).
- `petugas_desa` — Operator/Petugas Desa pada layanan ADD & Laporan Peristiwa.
- Pengguna/Operator unit mitra (`id_kua`, `id_puskes`, `id_rs`) — Portal PASUTRI, AHLAK, dan ST SEHATI.

---

## Dokumentasi Teknis Lanjutan

- **Database & Migrasi:** [`docs/DATABASE_AND_MIGRATION.md`](docs/DATABASE_AND_MIGRATION.md)
- **Panduan Deployment & Aktivasi WhatsApp Gateway:** [`docs/WHATSAPP_ACTIVATION_AND_DEPLOYMENT.md`](docs/WHATSAPP_ACTIVATION_AND_DEPLOYMENT.md)
- **Keamanan & Deployment Hardening:** [`docs/security_deployment_20260913.md`](docs/security_deployment_20260913.md)
- **Mobile REST API & OpenAPI:** [`docs/mobile-api.md`](docs/mobile-api.md) | [`docs/mobile-api.openapi.json`](docs/mobile-api.openapi.json)
- **ASPRIMA AI (Opsional):** [`docs/asprima_claude.md`](docs/asprima_claude.md)
