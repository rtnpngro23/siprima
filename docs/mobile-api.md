# API Android SIPRIMA

Versi kontrak: 1.0.0. Implementasi lokal diselesaikan 14 September 2026 berdasarkan [brief Android](../../build_app_android_SIPRIMA.md).

API menyediakan **30 operasi** untuk masyarakat dan petugas desa/ADD. Backend tetap menulis tabel layanan website. Tidak ada scraping HTML atau koneksi Android langsung ke MySQL.

Artefak utama:

- [OpenAPI JSON](mobile-api.openapi.json): kontrak untuk Retrofit, Postman, Swagger Editor, atau generator klien.
- [Router](../public/api/v1/index.php) dan [modul API](../app/Api/).
- [Migrasi SQL](../sql/20260913_mobile_api.sql) dan [pemeriksa/pemasang migrasi](../sql/mobile_api_migrate.php).
- [Contoh environment tanpa rahasia](../app/config/mobile_api.env.example).
- [Uji integrasi](../tests/mobile_api_test.js) dan [pemeriksaan kontrak](../tests/mobile_api_contract_test.js).

Kode tersedia dan diuji lokal dengan database sintetis terisolasi. Database aplikasi sebenarnya, cPanel, SMTP nyata, dan aplikasi Android tidak diubah atau dinyatakan telah terintegrasi. `MOBILE_API_ENABLED` tetap nonaktif secara default sampai konfigurasi dan migrasi lingkungan tujuan disiapkan.

## 1. Cakupan endpoint

Base URL: `{public_url}/api/v1`. Nilai `public_url` bisa berada di root domain atau subfolder sesuai hosting.

| Metode | Rute | Perilaku |
|---|---|---|
| POST | `/auth/login` | Login NIK atau email, hanya masyarakat/ADD aktif tanpa penugasan instansi |
| POST | `/auth/refresh` | Rotasi access dan refresh token; replay mencabut sesi perangkat |
| POST | `/auth/logout` | Mencabut sesi perangkat saat ini |
| POST | `/auth/register` | Pendaftaran masyarakat dengan kebijakan password website |
| POST | `/auth/register-village-officer` | Multipart data pendaftaran dan surat tugas PDF; status pending, ditangani admin website |
| POST | `/auth/password/forgot` | Meminta email reset, respons generik tanpa membocorkan keberadaan akun |
| POST | `/auth/password/reset` | Memakai token reset sekali pakai yang sama dengan website |
| POST | `/auth/password/change` | Mengganti password, termasuk password sementara; sesi dicabut dan harus login ulang |
| GET, PATCH | `/me` | Profil dan kemampuan akun; perubahan hanya nama, email, no_hp, alamat |
| GET | `/services` | Katalog layanan menurut persona |
| GET | `/services/{service_code}` | Isian, tipe, field wajib, persyaratan, batas unggahan |
| GET | `/service-availability` | Jam/event WITA dan waktu server; publik, dibatasi rate limit |
| GET | `/references/{type}` | `kecamatan` atau `desa`; untuk desa wajib query `kecamatan_id` |
| GET, POST | `/applications` | Daftar/pencarian/filter dan pembuatan permohonan |
| GET, PATCH | `/applications/{application_ref}` | Detail dan perbaikan sesuai izin serta revision |
| POST | `/applications/{application_ref}/resubmit` | Mengajukan kembali permohonan ditolak tanpa mengganti data |
| PATCH | `/applications/{application_ref}/status` | Khusus ADD untuk laporan peristiwa dalam wilayah tugas |
| POST | `/uploads` | Multipart satu berkas sementara milik akun dan layanan |
| GET | `/applications/{application_ref}/history` | Riwayat yang benar-benar tersedia, tanpa tanggal buatan |
| GET, POST | `/applications/{application_ref}/comments` | Komentar teks maksimal 1.000 karakter; balasan memakai parent_id |
| GET | `/applications/{application_ref}/documents` | Daftar hasil dan status penerimaan dokumen email |
| GET | `/documents/{document_ref}/download` | Berkas berotorisasi, respons biner |
| POST | `/applications/{application_ref}/receipt` | Konfirmasi sudah/belum masuk setelah masa cek email |
| POST | `/applications/{application_ref}/feedback` | Rating aplikasi, rating pelayanan, dan pesan |
| GET | `/notifications` | Notifikasi akun dengan pagination |
| PATCH | `/notifications/{notification_ref}` | Menandai dibaca dengan `{"read":true}` |

Endpoint `whistleblowing-publications.php` yang sudah ada tidak diubah dan tetap memakai kontraknya sendiri. Kanal RS/KUA/AHLAK, operator, admin mobile, push notification, ASPRIMA, dan nama bayi di luar rilis API ini.

## 2. Pemetaan layanan dan akses

| service_code | Tabel sumber | Masyarakat | ADD |
|---|---|---|---|
| `kk` | tb_kartukeluarga | Buat dan kelola milik sendiri | Buat dan kelola milik petugas pada wilayah tugas |
| `kelahiran` | tb_akta_kelahiran | Sama | Sama |
| `kematian` | tb_akta_kematian | Sama | Sama |
| `pindah` | tb_pindah_alamat | Sama; kecamatan/desa adalah asal | Sama; wilayah sesuai penugasan |
| `pindah_online` | tb_pindah_online | Sama; kecamatan/desa adalah tujuan | Sama; wilayah sesuai penugasan |
| `peristiwa` | tb_laporan_peristiwa | Buat dan kelola laporan milik sendiri | Baca/proses laporan warga berdasarkan ID kecamatan dan desa tugas |

Temuan kode website: halaman ADD laporan peristiwa merupakan halaman penanganan laporan warga, bukan formulir pembuatan laporan baru. Karena itu katalog ADD mengembalikan `can_create=false` untuk peristiwa; endpoint `/status` menyediakan tindakan tersebut. ADD tidak mendapat akses semua permohonan warga untuk lima layanan lainnya.

API menggunakan ID wilayah sebagai dasar otorisasi. Rekaman legacy yang hanya mempunyai nama wilayah tanpa ID konsisten perlu diperiksa/dibenahi oleh pengelola sebelum pilot; API tidak menyamakan nama bebas dengan bukti penugasan.

`application_ref` berbentuk `kk-123`, `kelahiran-123`, dan seterusnya. Angka yang sama pada dua tabel menghasilkan referensi berbeda. Referensi adalah pengenal, bukan token izin. Nama tabel dan path berkas tidak diterima sebagai input bebas.

## 3. Kontrak respons dan input

Respons sukses:

```json
{
  "data": {"application_ref": "kk-123", "status": "diajukan"},
  "meta": {"request_id": "000000000000000000000001", "server_time": "2026-09-14T08:00:00+08:00"}
}
```

Respons gagal:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Periksa isian Anda.",
    "fields": {"nama_kepalaKeluarga": "Isian wajib diisi."}
  },
  "meta": {"request_id": "000000000000000000000002", "server_time": "2026-09-14T08:00:00+08:00"}
}
```

- Gunakan `Content-Type: application/json`, kecuali dua endpoint multipart. Aksi tanpa isian tetap mengirim `{}`.
- `Authorization: Bearer <access_token>` wajib untuk endpoint privat. Cookie website tidak menjadi autentikasi API dan tidak menggantikan token.
- Body JSON maksimal 256 KB; request multipart maksimal 6 MB pada `POST /uploads` dan 2 MB pada pendaftaran ADD. Server hosting dapat mempunyai batas lebih kecil, sehingga sesuaikan `post_max_size` dan `upload_max_filesize`.
- `POST /uploads` membutuhkan ekstensi PHP **GD** dan **EXIF** pada runtime web. Tanpa keduanya endpoint menjawab 503 `IMAGE_PROCESSOR_UNAVAILABLE`, sehingga verifikasi ketersediaannya sebelum mengaktifkan API pada lingkungan tujuan.
- Daftar berisi `data.items` dan `data.pagination` (`page`, `per_page`, `total`). Default 20 item, maksimal 100; page maksimal 10.000.
- Daftar permohonan menerima `service_code`, `status`, dan `q` (pencarian nama subjek, maksimal 100 karakter). Filter selalu berada dalam lingkup akun.
- Field bebas seperti role, id_user, nama tabel, status operator untuk layanan umum, atau path ditolak. Validasi final selalu di server.
- Field NIK/nomor KK adalah string 16 digit agar angka nol depan tidak hilang.
- Field tanggal formulir memakai `YYYY-MM-DD`; waktu kelahiran memakai `HH:MM` atau `HH:MM:SS`. Timestamp kejadian/metadata memakai ISO 8601 dengan offset WITA.
- Error utama: 401 autentikasi, 403 larangan aksi/jam, 404 objek di luar lingkup/tidak tersedia, 405 metode, 409 konflik, 413 body besar, 415 tipe body, 422 validasi, 429 rate limit, 503 dependensi/migrasi belum tersedia.
- Detail error internal SQL, kredensial, dan token tidak dikirim kepada klien. Gunakan `X-Request-ID` untuk korelasi log.
- Rate limit: 180 request per menit per akun untuk rute terautentikasi; 120 request per menit per IP untuk rute publik. POST publik dibatasi per IP — `/auth/login` 120 per 15 menit, sisanya (registrasi, lupa/reset password) 20 per 15 menit. Batas login dibuat longgar karena pengguna seluler berbagi IP melalui carrier NAT dan satu kantor desa berbagi satu sambungan; tebakan kredensial tetap dibatasi per akun oleh throttle login website, bukan oleh batas IP ini.

## 4. Autentikasi Android

Contoh login:

```http
POST /api/v1/auth/login
Content-Type: application/json

{"username":"NIK_ATAU_EMAIL","password":"PASSWORD_AKUN","device_name":"Android pribadi"}
```

Respons memuat `access_token`, `refresh_token`, `session_id`, `expires_in`, `access_expires_at`, `session_expires_at`, `idle_timeout_seconds`, dan user.

Kebijakan implementasi awal:

- Access token: 15 menit.
- Batas tidak aktif: 14 hari sejak request autentikasi/refresh terakhir.
- Batas absolut sesi perangkat: 30 hari sejak login; refresh tidak memperpanjangnya.
- Kedua batas di atas sengaja lebih panjang daripada sesi browser website (30 menit / 8 jam). Aplikasi terpasang diharapkan tetap masuk antar kunjungan; pengamanan sebenarnya adalah access token 15 menit, rotasi refresh, dan pencabutan berbasis fingerprint identitas di bawah ini. Nilainya dipusatkan pada konstanta `Siprima\Api\Auth`; ubah di sana lalu jalankan ulang generator OpenAPI.
- Refresh merotasi kedua token. Refresh lama hanya dapat dipakai sekali; replay mencabut seluruh sesi perangkat tersebut.
- Token acak 32 byte dikirim sebagai hex; database hanya menyimpan hash SHA-256. Password tetap mengikuti helper keamanan website.
- Perubahan password, role, status aktif, atau penugasan membatalkan akses pada request berikutnya melalui fingerprint identitas.
- Akun dengan password sementara hanya mendapat GET `/me`, ganti password, logout, serta refresh terbatas umur sesi/password. Perubahan password mencabut semua sesi akun.

Klien Android harus menyerialkan refresh per perangkat (misalnya mutex pada authenticator), menyimpan pasangan token baru secara atomik, dan mengulang request hanya sekali setelah refresh. Jangan melakukan dua refresh dengan token sama. Jika respons refresh hilang saat koneksi putus, login ulang lebih aman; token lama tidak dapat direplay untuk mengambil token baru.

Simpan token dengan perlindungan Android Keystore. Jangan mencetak token, password, NIK, multipart, atau respons data pribadi pada logging interceptor. Draf/cache lokal, Room, Compose, dan implementasi penyimpanan Android tetap menjadi pekerjaan proyek Android.

## 5. Unggahan dan pengajuan pertama

1. Login dan ambil `/me`, `/service-availability`, lalu `/services/kk`.
2. Ambil kecamatan/desa dari endpoint referensi. Untuk ADD, pakai wilayah akun.
3. Unggah setiap persyaratan melalui multipart `/uploads` dengan `service_code`, `doc_type`, dan satu `file`.
4. Simpan `upload_ref` dari tiap respons. Unggahan berlaku 24 jam, milik satu akun dan satu layanan.
5. Kirim POST `/applications` dengan `service_code`, objek `fields`, dan array `uploads` berisi referensi.

Contoh permohonan KK:

```http
POST /api/v1/applications
Authorization: Bearer ACCESS_TOKEN
Content-Type: application/json
Idempotency-Key: android-kk-unik-00000001

{
  "service_code": "kk",
  "fields": {
    "id_kecamatan": 1,
    "id_desa": 1,
    "dusun": "Contoh dusun",
    "nama_kepalaKeluarga": "Nama contoh",
    "anggotaKeluarga": [{"nama":"Anggota contoh","nik":"0000000000000001","hubungan":"anak"}],
    "keterangan": "Permohonan contoh"
  },
  "uploads": ["REF_FORMULIR", "REF_KK", "REF_SELFIE"]
}
```

Nilai contoh bukan data untuk dikirim ke produksi. Ganti ID wilayah dan semua referensi unggahan dengan respons server. Katalog merupakan acuan final field dan `doc_type`, terutama karena syarat masyarakat/ADD berbeda.

| Layanan | Dokumen wajib masyarakat | Dokumen wajib ADD |
|---|---|---|
| KK | formulir_f101, kk_lama_atau_surat_kehilangan, foto_selfie | formulir_f101, kk_lama |
| Kelahiran | formulir, surat_keterangan, buku_nikah, foto_selfie | formulir_kelahiran, surat_keterangan_lahir, buku_nikah |
| Kematian | formulir_f201, surat_kematian, identitas_meninggal, foto_selfie | formulir_kematian, surat_keterangan_kematian, kk_ktp_meninggal |
| Pindah | formulir_f103, kk_ktp_hilang, foto_pemohon | formulir_pindah, foto_pemohon, kk_ktp |
| Pindah masuk | formulir_pindah, kk_ktp, foto_pemohon | formulir_pindah, kk_ktp, foto_pemohon |
| Peristiwa | foto_selfie | Tidak membuat laporan baru |

Kelahiran juga mewajibkan salah satu `kk` atau `suket_kehilangan_kk`; hal ini tersedia sebagai `required_document_groups`. Berkas persyaratan hanya JPG/PNG valid maksimal 5 MB per berkas, dengan pemeriksaan MIME aktual, isi gambar, ukuran, dan ekstensi. Surat tugas pendaftaran ADD memakai PDF valid maksimal 1 MB melalui endpoint pendaftaran, bukan `/uploads`.

Batas ukuran dan daftar MIME bukan angka tetap di klien: bacalah `max_file_bytes` dan `mime_types` dari `GET /services/{service_code}`. Server memakai konstanta yang sama (`Siprima\Api\Uploads::MAX_IMAGE_BYTES` dan `::MIME_TYPES`) untuk mengumumkan dan menegakkan batas, sehingga keduanya tidak dapat berbeda.

**Kompresi gambar di server.** Gambar persyaratan diproses ulang sebelum disimpan: apa pun yang diterima, hasil simpan selalu di bawah 500.000 byte. JPG tetap JPG dengan orientasi EXIF diterapkan, PNG tetap PNG dengan transparansi dipertahankan. Tidak ada pemotongan atau penghapusan latar; ukuran diprioritaskan, sehingga kualitas JPEG dan resolusi dapat turun secara proporsional. Katalog mengumumkan `max_file_bytes` (batas kiriman), `max_stored_file_bytes` (batas hasil simpan), `max_image_pixels`, dan `max_image_dimension`. Respons `POST /uploads` mengembalikan `file_size` hasil simpan beserta `original_file_size`, `width`, dan `height`.

**Catatan bagi klien Android:** kamera ponsel menghasilkan foto 3–8 MB. Foto sampai 5 MB dapat dikirim apa adanya dan server yang mengecilkannya; di atas itu tetap ditolak 422 `INVALID_UPLOAD`. Mengompres di perangkat masih dianjurkan untuk menghemat kuota dan waktu unggah pengguna, tetapi bukan lagi syarat agar pengajuan berhasil. Gambar melebihi 24 megapiksel atau sisi 16.000 piksel ditolak dengan `IMAGE_DIMENSIONS_TOO_LARGE`; runtime tanpa GD/EXIF menjawab 503 `IMAGE_PROCESSOR_UNAVAILABLE`.

Envelope multipart `POST /uploads` dibatasi 6 MB agar muat berkas 5 MB beserta overhead; endpoint pendaftaran ADD tetap 2 MB.

Terdapat batas 50 unggahan sementara per akun dan maksimal 30 referensi per kiriman. Unggahan milik akun lain, kedaluwarsa, atau sudah dipakai ditolak. Penyimpanan berkas berada di `public/user/uploads/mobile` dan ditolak akses HTTP langsung; metadata berkas memakai format JSON yang dapat dibaca endpoint dokumen website. Cron juga menangani file mobile yatim tanpa metadata yang berumur lebih dari 48 jam, untuk memulihkan kasus proses terhenti setelah pemindahan berkas tetapi sebelum pencatatan SQL.

## 6. Idempotensi, perbaikan, dan waktu layanan

POST pembuatan serta resubmit memerlukan `Idempotency-Key` unik 16–128 karakter. Simpan kunci bersama operasi/draf di Android sebelum mengirim. Retry harus memakai kunci dan payload yang sama; kunci sama dengan payload berbeda menghasilkan 409. Enam pengiriman paralel diuji menghasilkan satu permohonan. Rekaman idempotensi tidak dibersihkan otomatis agar retry lama tidak membuat duplikasi.

GET detail memberi `revision` berupa hash seluruh baris dan header ETag. PATCH dan resubmit mengirimnya dalam `If-Match`. Pemeriksaan hash mendeteksi perubahan website meskipun timestamp detiknya sama.

**PATCH mengikuti perilaku edit website: perbaikan yang berhasil sekaligus mengubah status ke `diajukan` dan mencatat pengajuan kembali.** Jangan memanggil resubmit lagi sesudah PATCH sukses. Endpoint resubmit dipakai untuk permohonan `ditolak` yang diajukan ulang tanpa perubahan data. PATCH memakai revisi; bila respons PATCH hilang, muat ulang detail sebelum memutuskan tindakan berikutnya.

Pada PATCH, `fields` berisi subset isian yang diubah. `uploads` berisi berkas pengganti; seluruh berkas lama dengan `doc_type` yang sama diganti, sementara jenis lainnya dipertahankan. Berkas lama tidak dihapus otomatis karena dapat terkait riwayat/alur website.

Jam/event menggunakan helper website. Penutupan menolak pembuatan, unggahan, edit, serta resubmit LINE/ADD. Login, pembacaan, komentar, dan unduhan tetap tersedia. Jika pengaturan tidak dapat dibaca, aksi pengajuan ditolak 503 tanpa logout. Status peristiwa ADD `diajukan` juga diperiksa terhadap jadwal.

## 7. Hasil, penerimaan, dan feedback

- Detail permohonan berisi lampiran persyaratan dengan `document_ref` dan `download_path`; tidak ada path filesystem publik.
- Daftar hasil menggabungkan jalur hasil yang diaktifkan dan dokumen tindak lanjut email. Flag hasil operator umum tetap `false`, sama dengan kode website; flag dipusatkan pada `app/config/document_features.php`.
- Unduhan menerima bearer token dan memeriksa ulang permohonan, pemilik, serta root filesystem. Respons sukses adalah biner, bukan envelope JSON.
- Konfirmasi tersedia setelah status selesai dan masa cek email 30 menit. Pilihan: `sudah_masuk` atau `belum_masuk`; pilihan kedua membutuhkan note.
- Tindak lanjut `diunggah_operator`, `sebagian_diunggah_perlu_pengajuan_baru`, dan `perlu_pengajuan_baru` tidak ditimpa oleh konfirmasi pemohon.
- Konfirmasi atau akses unduhan hasil memicu record feedback. Tanggapan memakai dua rating 1–5; pesan wajib untuk aduan atau salah satu rating ≤3. Maksimal 1.000 karakter. Feedback yang telah submitted/skipped tidak ditimpa.

Notifikasi mobile memakai tabel `tb_notifikasi` website. Pembuatan/perubahan permohonan menyimpan pekerjaan notifikasi pada **outbox dalam transaksi yang sama**, lalu cron memprosesnya. Gangguan notifikasi tidak mengubah respons permohonan yang sudah berhasil disimpan menjadi gagal. Cron dapat mengulang pekerjaan tanpa menggandakan notifikasi yang sudah berhasil.

## 8. Setup lokal

Prasyarat: PHP 8.1+ dengan mysqli/mysqlnd, mbstring, fileinfo, JSON dan session; MySQL yang kompatibel dengan skema website, seluruh tabel terkait menggunakan InnoDB. PHP 8.2.4 dan MySQL 8.4.3 dipakai pada pengujian lokal ini. PHPMailer yang dibundel diperlukan untuk email reset nyata.

1. Gunakan database pengembangan berisi data sintetis. Siapkan skema dasar website dan migrasi fitur/keamanan yang diperlukan.
2. Isi konfigurasi DB/environment privat berdasarkan contoh. Jangan mengimpor data penduduk ke lingkungan tes otomatis.
3. Jalankan dari direktori `public_html`:

   ```text
   php sql/mobile_api_migrate.php --check
   php sql/mobile_api_migrate.php --apply
   ```

   Pemeriksa berhenti jika tabel, kolom, atau engine prasyarat website belum sesuai. `--apply` menambahkan tabel mobile dan catatan versi; menjalankannya ulang tidak menghapus data.

4. Contoh PowerShell untuk server API lokal (set DB_* ke database pengembangan Anda terlebih dahulu):

   ```powershell
   $env:APP_ENV = 'local'
   $env:MOBILE_API_ENABLED = 'true'
   $env:MOBILE_API_ALLOW_HTTP = 'true'
   php -S 127.0.0.1:8080 -t public tools/mobile_dev_router.php
   ```

   Router ini hanya untuk API lokal dan tidak menyajikan folder unggahan. Base URL desktop `http://127.0.0.1:8080/api/v1/`; emulator Android standar menggunakan `http://10.0.2.2:8080/api/v1/`. HP fisik memerlukan binding LAN serta aturan firewall yang sesuai. HTTP hanya untuk build debug Android.

5. Jalankan pekerjaan notifikasi/pembersihan dari shell terpisah:

   ```text
   php mobile_api_maintenance.php
   ```

6. Gunakan data akun sintetis untuk registrasi/login dan pengajuan. Endpoint forgot membutuhkan SMTP aktif dan `APP_PUBLIC_URL` tepercaya; tanpa SMTP, respons tetap generik dan pengiriman tidak terjadi.

## 9. Deployment cPanel/staging

1. Backup database dan berkas, lalu pastikan versi skema website sebelum migrasi mobile. Dokumentasi keamanan sebelumnya menyatakan hosting belum dimigrasikan; jangan menganggap prasyarat sudah terpenuhi.
2. Siapkan staging HTTPS dengan database dan penyimpanan terpisah. Document root yang disarankan adalah `public_html/public`.
3. Pasang `app/Api`, konfigurasi fitur dokumen bersama, helper website yang diperlukan, router `/public/api/v1/index.php`, serta `.htaccess` API. Pertahankan endpoint publikasi lama.
4. Jalankan pemeriksa dan migrasi CLI. Jika cPanel tidak menyediakan terminal, minta pengelola menjalankan pemeriksa melalui akses CLI yang tersedia; jangan membuat endpoint migrasi publik.
5. Pasang konfigurasi environment. Cetakan produksi lengkap tersedia di `app/config/.env_prod`; **unggah isinya dan simpan sebagai `app/config/.env` di server** — tidak ada kode yang membaca nama berkas `.env_prod`, baik `koneksi.php` maupun `app/Api/Core.php` selalu memuat `app/config/.env`. Jangan mengunggah `.env` milik mesin lokal. Pastikan nilai berikut benar di server: `APP_ENV=production`, `APP_DEBUG=false` (bila `true`, `env_loader.php` menulis setiap nilai environment ke error log), `MOBILE_API_ALLOW_HTTP=false`, DB dan SMTP produksi, `APP_PUBLIC_URL` sesuai docroot, serta `LOGIN_THROTTLE_DIR` pada direktori privat yang sama dengan website. Setel `MOBILE_API_ENABLED=true` hanya setelah langkah 4 selesai dan staging terverifikasi.
6. Pastikan header Authorization sampai ke PHP, rewrite bekerja di mount path, HTTPS aktif, direktori SQL/tests/tools/config/log tidak bisa diunduh, serta direktori unggahan mendapat 403/404. Untuk Nginx, aturan deny dan rewrite harus dikonfigurasi di server.
7. Jadwalkan cron `php /path/ke/public_html/mobile_api_maintenance.php` tiap menit untuk notifikasi dan pembersihan unggahan sementara. Satu run memproses maksimal 100 outbox serta 500 unggahan kedaluwarsa. Pantau exit code cron dan pertumbuhan antrean.
8. Uji akun masyarakat/ADD, akses silang, batas jadwal, penerimaan, email nyata, serta unduhan dari aplikasi Android sebelum mengganti base URL produksi.

Endpoint dapat dipanggil melalui `index.php/path` bila server mendukung PATH_INFO, tetapi rewrite `/api/v1/path` adalah kontrak utama. Router lokal menguji path standar; aturan Apache/LiteSpeed/cPanel dan penerusan Authorization tetap perlu diverifikasi di staging.

Rollback operasional: nonaktifkan `MOBILE_API_ENABLED`, kembalikan paket kode yang sesuai, dan pertahankan tabel mobile sampai dipastikan tidak dibutuhkan. Tabel layanan website berisi permohonan Android yang sah; jangan menghapusnya saat rollback kode. DDL tidak diasumsikan transaksional.

## 10. Pengujian dan pemeliharaan kontrak

Perintah dari direktori `public_html`:

```powershell
php tools/generate_mobile_openapi.php --check
node tests/mobile_api_contract_test.js
$env:MYSQLD_BINARY = 'D:\laragon\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
node tests/mobile_api_test.js
php tests/document_endpoint_test.php
php tests/feedback_rating_test.php
php tests/service_hours_test.php
```

Sesuaikan path mysqld/PHP dengan instalasi. Suite mobile membuat instance MySQL, salinan kode, berkas, dan akun sintetis pada direktori temporer, mengekstrak **DDL saja** dari dump, serta menghentikan proses uji setelah selesai. Isi dump dibaca untuk mengambil struktur tabel; statement data penduduk tidak diimpor atau digunakan sebagai data uji. Database aplikasi tidak dimodifikasi. Diagnostik sementara dipertahankan untuk pemeriksaan kegagalan.

Cakupan meliputi bearer/cookie, role/instansi, password sementara, rotasi/replay paralel, idle/absolute expiry, perubahan identitas, semua layanan, unggahan, akses silang, enam pengiriman bersamaan, revisi perubahan website, jam/event WITA, rollback kegagalan SQL, notifikasi tertunda, komentar, penerimaan, feedback, pendaftaran, dan reset. Respons sukses diuji terhadap subset aturan schema OpenAPI oleh validator lokal; ini bukan klaim sertifikasi seluruh spesifikasi OpenAPI oleh validator pihak ketiga.

Jika mengubah router/katalog, jalankan `php tools/generate_mobile_openapi.php`, tinjau perubahan kontrak, lalu ulangi tes terkait. Daftar persyaratan API dipetakan dari formulir website; perubahan formulir selanjutnya perlu memperbarui katalog dan tes agar kedua kanal tetap konsisten.

## 11. Batas implementasi dan tindak lanjut

- Deployment satu host; sesi mobile tersimpan di MySQL, tetapi throttle login website masih memakai file dan perlu strategi bersama sebelum multi-node.
- Email reset nyata dan antarmuka Android belum diuji oleh suite sintetis; tidak ada push notification pada rilis ini.
- Draf/offline, penyimpanan Keystore, rendering Compose, aksesibilitas perangkat, serta retry UI berada pada proyek Android.
- Skema akun lama tidak mempunyai unique index email/NIK pada dump yang ditinjau. Pendaftaran dan perubahan profil mobile diserialkan untuk mencegah duplikasi antar request API; penulisan website lama tetap membutuhkan audit keunikan lintas kanal. Login/reset mobile menolak identitas email ambigu, bukan memilih akun secara sembarang.
- Unggahan terikat permohonan dan berkas lama hasil penggantian tidak dibersihkan otomatis. Tetapkan kebijakan retensi bersama pengelola.
- Record idempotensi dipertahankan untuk mencegah duplikasi retry lama; rencanakan arsip/tombstone jika volume meningkat.
- Tidak ada migrasi atau aktivasi pada database/hosting sebenarnya dalam pekerjaan ini.
