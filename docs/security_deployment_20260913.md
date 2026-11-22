# Implementasi dan Deployment Keamanan SIPRIMA

Tanggal: 13 September 2026. Implementasi dilakukan pada salinan project lokal.

## Status

- Kode inti dan pengujian otomatis sudah tersedia.
- Migrasi diuji dua kali pada database MySQL 8.4 terisolasi dengan data sintetis untuk memastikan dapat dijalankan ulang.
- Database aplikasi sebenarnya dan hosting belum dimigrasikan. Jangan mengaktifkan kode baru pada hosting sebelum langkah migrasi dan konfigurasi di bawah selesai.
- MFA tidak diterapkan. Skrip cleanup bulanan tidak diubah.

## Perubahan perilaku

### Password dan reset

- Password baru minimal 8 karakter dengan huruf besar, huruf kecil, angka, dan simbol; maksimal 72 byte untuk mencegah pemotongan diam-diam oleh bcrypt.
- Password umum pada denylist lokal ditolak. Ini bukan layanan pemeriksaan seluruh database password bocor.
- Password akun lama tetap dapat dipakai untuk login. Kebijakan baru berlaku saat password dibuat/diubah; hash lama ditingkatkan ketika `password_needs_rehash()` mengharuskannya.
- Permintaan reset admin membuat tautan acak sekali pakai, berlaku 15 menit. Database hanya menyimpan hash token. Password lama belum berubah ketika tautan baru dibuat.
- Token ditempatkan dalam fragmen URL (`#token=...`), kemudian dikirim melalui POST dengan CSRF. Halaman reset memakai `no-store` dan `no-referrer`.
- Email/Telegram reset mengirim tautan, bukan password. Kanal Telegram memakai tujuan yang sudah dikonfigurasi kantor; penerima kanal tersebut harus berwenang menyampaikan tautan kepada pemilik akun.
- Status `sent` berarti pengiriman diterima oleh SMTP/API pada saat request, bukan bukti pesan sudah dibaca pengguna.
- Jika kedua kanal gagal/tidak dikonfigurasi, admin pembuat permintaan dapat menekan tombol **Buat dan tampilkan password sementara**.
- Fallback hanya dapat dipakai satu kali, oleh admin pembuatnya, selama permintaan masih berlaku. Refresh/resubmit tidak membuat password baru.
- Password sementara hanya ditampilkan pada respons aksi tersebut, tidak disimpan dalam riwayat/URL/session. Password disimpan sebagai hash, berlaku 24 jam, dan wajib diganti sebelum pengguna mengakses layanan.
- Menggunakan fallback membatalkan tautan reset sebelumnya. Permintaan baru, penggunaan token, atau perubahan password juga membuat token lama tidak dapat dipakai.
- Batas permintaan: satu permintaan per akun per menit dan 20 permintaan per sesi admin per jam; submit token dilindungi pembatasan per token dan per IP.

### Sesi dan hak akses

- Middleware dan koneksi database memeriksa fingerprint identitas dari data akun dan penugasan terkini: password, role, status aktif, serta ID wilayah/instansi.
- Perubahan data autentikasi/penugasan membatalkan sesi lama pada request berikutnya. Request yang sudah berjalan sebelum perubahan tidak dihentikan secara retroaktif.
- Session sebelum deployment wajib login ulang; session lama tidak diadopsi otomatis.
- Batas tidak aktif: 30 menit sejak request terautentikasi terakhir. Umur sesi absolut: 8 jam.
- Pemeriksaan dipasang juga pada endpoint dokumen umum dan AHLAK. Akses dokumen melalui modul lain mengikuti middleware/koneksi yang sama.
- Fingerprint dibandingkan dengan data yang diverifikasi ketika login, sehingga perubahan identitas di tengah proses login tidak menghasilkan sesi dengan hak lama.

### Pembatasan login

- Bucket per akun sekarang lintas IP, bukan gabungan akun-IP.
- Setelah 5 percobaan, diterapkan penundaan progresif mulai 5 detik, maksimal 5 menit. Jendela penghitung 15 menit.
- Batas per IP: 20 percobaan; batas agregat: 1.000 percobaan dalam jendela yang sama. Request dibatasi sebelum verifikasi password, termasuk login berhasil pada penghitung IP/agregat.
- Reservasi percobaan dikunci dengan `flock()` agar request paralel tidak melewati batas.
- Request yang sudah diblokir tidak memperpanjang masa blokir. Login berhasil menghapus penghitung akun.
- Deployment multi-node memerlukan storage penghitung bersama yang mendukung locking, atau pemindahan implementasi penghitung ke Redis/database. Implementasi saat ini diuji pada satu host.
- Header `X-Forwarded-For` hanya dipercaya jika `REMOTE_ADDR` masuk daftar IP proxy tepercaya.
- CAPTCHA hitung masih menjadi kontrol tambahan. Penggantian CAPTCHA dan penyesuaian ambang dilakukan berdasarkan pola penggunaan/penyalahgunaan aktual.

### Log dan CSP

- Log baru secara default disimpan di `<project>/private_logs`, di luar `public_html`.
- Log historis lokal `public/error_log.txt` dipindahkan utuh ke `<project>/private_logs/legacy_error_log.txt`; tidak dihapus permanen dan tidak disertakan dalam deployment.
- Folder privat ditambahkan ke `.gitignore` pada root project. File log historis yang sudah ada dalam riwayat Git tidak otomatis hilang dari riwayat tersebut.
- Pencatatan session ID mentah serta dump lengkap session/POST pada lokasi yang ditemukan diganti ID korelasi. Logger terpusat juga menyamarkan nomor identitas/kontak dan email; peninjauan log seluruh modul tetap masuk audit lanjutan.
- Header CSP aktif membatasi script eksternal ke tiga host yang ditemukan pada template: `cdn.tailwindcss.com`, `cdn.jsdelivr.net`, dan `cdnjs.cloudflare.com`.
- `unsafe-inline` dan `unsafe-eval` masih dipertahankan untuk kompatibilitas halaman lama. Kebijakan ketat `script-src 'self'` dipasang sebagai **Report-Only**, belum sebagai penegakan penuh.
- Pengumpulan pelanggaran sementara melalui konsol browser; tidak ada endpoint laporan CSP publik baru.

## Urutan deployment

1. Siapkan backup database dan paket aplikasi yang dapat dipulihkan. Pastikan target database dan document root benar.
2. Jadwalkan jendela maintenance singkat karena sesi lama akan diminta login ulang.
3. Jalankan migrasi di direktori `public_html` pada database target:

   ```text
   php sql/security_20260913.php --apply
   ```

   Migrasi menambahkan:
   - `tb_users.must_change_password`
   - `tb_users.temporary_password_expires_at`
   - tabel `tb_password_reset`

   `tb_users` harus menggunakan InnoDB. Migrasi berhenti jika prasyarat ini tidak terpenuhi. DDL MySQL tidak diasumsikan dapat di-rollback; migrasi idempoten memungkinkan pengulangan setelah kegagalan parsial.

4. Konfigurasikan URL publik yang benar pada environment atau `.env` aplikasi:

   ```dotenv
   APP_PUBLIC_URL=https://domain-siprima-anda
   ```

   Jika aplikasi benar-benar disajikan pada subpath `/public`, sertakan subpath tersebut. URL harus HTTPS, tanpa username/password, query, atau fragmen. Jangan menyalin domain contoh sebagai nilai produksi.

   Untuk pengembangan lokal Laragon, gunakan konfigurasi eksplisit berikut di `app/config/.env`:

   ```dotenv
   APP_ENV=local
   APP_PUBLIC_URL=http://siprima_gorontalokab_riset.test:8080/public_html/public
   ```

   HTTP hanya diterima pada `APP_ENV=local` untuk localhost, loopback, atau domain `.test`. Jangan gunakan mode lokal di produksi. URL selalu berasal dari konfigurasi, bukan header Host request.

5. Untuk pengaturan yang diperlukan sebelum koneksi database dimuat, gunakan **environment proses PHP/web server** agar tersedia konsisten di halaman login dan handler:

   ```dotenv
   SIPRIMA_LOG_DIR=/lokasi/privat/siprima/logs
   LOGIN_THROTTLE_DIR=/lokasi/privat/siprima/login_throttle
   LOGIN_TRUSTED_PROXIES=127.0.0.1
   ```

   Nilai proxy hanya contoh; gunakan IP proxy yang benar-benar dimiliki deployment. Kosongkan jika tidak memakai proxy. Directory log/throttle harus writable oleh proses PHP dan tidak bisa dibaca publik. Pada Windows, terapkan ACL setara; `chmod()` tidak menggantikan pemeriksaan ACL NTFS.

6. Deploy seluruh file implementasi, termasuk berkas yang diabaikan oleh `.gitignore` repository internal:
   - `public_html/.htaccess`
   - `public_html/public/.htaccess`
   - `public_html/app/config/koneksi.php`

   **`git diff`/daftar file tracked saja tidak cukup untuk membuat paket deployment**, karena ketiga file tersebut diabaikan oleh aturan repository. Jangan menyertakan `.env`, backup, log, atau fixture pengujian dalam paket publik.

7. Pastikan server memakai Apache/LiteSpeed dengan aturan `.htaccess` efektif. Untuk Nginx, terjemahkan aturan deny, header, dan HTTPS ke konfigurasi server sebelum aktivasi. Document root yang disarankan adalah `public_html/public`.
8. Verifikasi direktori privat/log mendapat 403/404, login berfungsi, dan request tanpa CSRF ditolak.
9. Uji reset dengan akun sintetis: satu kanal berhasil, kedua kanal gagal, fallback sekali pakai, login password sementara, dan penggantian wajib. Pengiriman SMTP/Telegram sebenarnya belum diuji oleh suite otomatis.
10. Uji matriks akses di bawah dan jalankan pengamatan CSP melalui browser desktop/mobile sebelum menghapus `unsafe-inline`/`unsafe-eval`.

## Matriks uji penerimaan deployment

| Aktor | Akses yang harus berhasil | Akses yang harus ditolak |
|---|---|---|
| Masyarakat | Permohonan dan dokumen miliknya | Permohonan/dokumen milik akun lain |
| Petugas desa | Permohonan milik akun/wilayah sesuai aturan layanan | Desa lain dan tindakan operator |
| User KUA | Permohonan yang dimiliki sesuai penugasan KUA | KUA/akun lain di luar kewenangan |
| User AHLAK | Permohonan yang dimiliki sesuai penugasan Puskesmas | Puskesmas/akun lain di luar kewenangan |
| User RS | Permohonan yang dimiliki sesuai penugasan RS | RS/akun lain di luar kewenangan |
| Operator | Proses, status, dan dokumen dalam penugasan terkini | Penugasan lain dan penugasan yang sudah dicabut |
| Admin | Manajemen akun dan reset sesuai kewenangan | Fallback reset yang dibuat admin lain |
| Akun dinonaktifkan/sesi dicabut | Halaman publik dan login | Seluruh data privat, termasuk URL dokumen yang sudah diperoleh |
| Akun dengan password sementara | Ganti password sementara dan keluar | Dashboard, API privat, dan unduh dokumen sebelum penggantian |

Untuk setiap aktor, uji baca/detail, edit, perubahan status, pengiriman dokumen tindak lanjut, dan unduh dokumen. Periksa aturan kepemilikan yang memang berlaku pada setiap modul; perubahan ini tidak memperluas kewenangan peran.

## Verifikasi otomatis yang tersedia

Jalankan dari `public_html`:

```text
node tests/security_lint.js
node tests/security_test.js
node tests/security_apache_test.js
node tests/login_session_test.js
php tests/service_hours_test.php
node tests/service_availability_test.js
```

- `security_test.js`: membutuhkan `MYSQLD_BINARY`, opsional `PHP_BINARY`; membuat MySQL sementara pada port acak, memakai data sintetis, dan mematikan instance tersebut setelah selesai.
- `security_apache_test.js`: membutuhkan `HTTPD_BINARY`; menjalankan Apache sementara dengan fixture statis, bukan melayani berkas aplikasi asli.
- Keduanya memakai direktori temp OS. Pada sesi implementasi ini digunakan `C:\Users\ASUS\AppData\Local\Temp\opencode`.
- Suite mencakup migrasi berulang, kebijakan password, login asli dan rehash, token kedaluwarsa/replay, dua konsumsi token bersamaan, fallback milik admin lain, pembatalan sesi, batas waktu sesi, CSRF termasuk token sesi lain, 12 IP paralel, proxy header palsu, serta pemeriksaan rahasia pada log.
- Pengujian Apache memverifikasi delapan path privat/log, redirect HTTPS, allowlist script, Report-Only, dan `nosniff`. Ini tidak menggantikan verifikasi konfigurasi hosting yang sebenarnya.

## Pekerjaan penerimaan yang masih terbuka

- Migrasi dan konfigurasi pada database aplikasi/hosting sebenarnya.
- Uji pengiriman SMTP/Telegram serta UI admin end-to-end pada staging.
- Uji upload dan seluruh matriks akses dengan browser desktop/mobile.
- Analisis pelanggaran CSP, pemindahan script inline, verifikasi dependensi CDN, dan penegakan penuh CSP.
- Peninjauan dependensi, pemeriksaan log seluruh modul, kebijakan retensi operasional, dan uji restore backup.

Rollback aplikasi harus menggunakan paket yang konsisten. Jika kembali ke versi aplikasi sebelum pemeriksaan password sementara, nonaktifkan sementara akun yang masih `must_change_password=1` atau selesaikan penggantiannya terlebih dahulu agar kewajiban ganti password tidak terlewati. Jangan menghapus tabel/kolom migrasi sebagai langkah rollback otomatis.
