# Pengaturan layanan SIPRIMA

Pedoman: skills/siprima-web-maintainer/SKILL.md.

## Operasional
Buka **Admin → Pengaturan Layanan**.
- Jadwal rutin: hari, jam buka/tutup WITA, dan sakelar pembatasan.
- Event: pemberitahuan saja atau penutupan sementara, waktu mulai tampil,
  mulai event, selesai event, judul, pesan, pratinjau, edit dan nonaktifkan.
- Libur satu hari: mulai 00.00 sampai 00.00 hari berikutnya.
- Penutupan event mengungguli jadwal rutin, termasuk ketika jadwal rutin nonaktif.
- Setelah event selesai, kembali mengikuti jadwal rutin. Event berakhir tepat
  pada jam selesai; jam buka inklusif, jam tutup eksklusif.
- Event penutupan yang bertabrakan ditolak. Jika pengumuman bersamaan,
  penutupan aktif diprioritaskan, lalu event dengan waktu mulai terdekat.
- Riwayat menampilkan 30 perubahan terakhir; seluruh riwayat disimpan.

## Cakupan
LINE/Umum dan ADD tetap dapat login, melihat status, mengunduh dokumen,
serta menggunakan fitur lain. Hanya pengajuan baru, edit permohonan, dan
pengajuan kembali yang ditolak ketika pengajuan dibatasi. Penjagaan ada
di server sebelum pembacaan data form/unggah/penyimpanan. Pengajuan ulang
laporan peristiwa oleh ADD juga diperiksa.
KUA/PASUTRI, AHLAK, ST SEHATI, admin dan operator tidak dibatasi.
Tidak ada logout atau reload paksa. Halaman terbuka memperbarui status
setiap 30 detik dan saat tab aktif kembali; server tetap memeriksa setiap
permintaan. Peringatan muncul dalam lima menit menjelang tutup.
Formulir yang belum disimpan tidak disimpan sebagai draf otomatis.

## Database dan pemasangan
Jalankan sql/20260906_service_settings.sql pada database target sebelum
menggunakan kode baru. Untuk database lokal dapat memakai:
    php sql/migrate_service_settings.php

Migrasi idempoten membuat:
- tb_service_settings: satu dokumen jadwal/event dan nomor revisi.
- tb_service_settings_audit: perubahan sebelum/sesudah, admin, waktu WITA.

Jadwal dan event disimpan bersama karena cakupannya tetap LINE/ADD dan
semua perubahan harus atomik. Nomor revisi dan penguncian baris mencegah
saling menimpa perubahan admin. Penyimpanan dan audit dalam satu transaksi.
Migrasi mempertahankan pembatasan nonaktif, 08.00–15.30 Senin–Jumat,
dan mengarsipkan sepuluh tanggal khusus dari konfigurasi PHP sebelumnya.
Pengaturan yang telah disimpan admin tidak ditimpa migrasi ulang.

Jika pengaturan/database tidak dapat dibaca, aksi pengajuan ditolak dengan
pesan yang jelas. Pemeriksaan jam tidak mengeluarkan pengguna; ketersediaan
fitur baca tetap bergantung pada database aplikasi.
Tidak ada perubahan tabel permohonan atau akun.

## Verifikasi
- php tests/service_hours_test.php — aturan waktu, peran, dan validasi.
- php tests/service_hours_test.php --integration — database lokal uji
  bernama acak siprima_service_test_*, dibuat/dihapus oleh tes. Memerlukan
  izin CREATE/DROP DATABASE. Tidak mengubah data akun/permohonan.
- node tests/service_availability_test.js — perilaku status di sisi klien.
- Lint PHP untuk semua file berubah dan node --check untuk JavaScript.

Uji browser dengan akun nyata tetap diperlukan pada lingkungan tujuan:
desktop/HP, simpan/pratinjau event, login LINE/ADD saat tutup, baca status,
unduh dokumen milik sendiri, penolakan pengajuan/edit/ajukan kembali,
serta akses normal KUA/AHLAK/ST SEHATI. Pengujian integrasi memakai sesi
sintetis dan database terisolasi, bukan login akun nyata.
## Tampilan pengumuman
- Beranda: popup otomatis hanya untuk event penutupan, satu kali setelah
  ditutup per versi pengumuman dan per browser. Perubahan isi/jadwal
  menghasilkan versi baru. Fase akan ditutup menjadi sedang ditutup tidak
  memunculkan ulang popup yang telah dibaca.
- Tombol Saya Mengerti, tombol tutup, Escape, dan klik latar menutup popup.
  Tombol Buka pengumuman pada banner dapat membukanya kembali.
- Pemberitahuan saja tampil sebagai banner tanpa popup otomatis.
- Di dalam akun dan formulir hanya banner: judul, ringkasan, waktu buka
  kembali, detail yang dapat diperluas, dan sisa waktu pada bagian terpisah.
  Memperbarui status tidak menutup detail atau memuat ulang formulir.
- Browser tanpa dukungan dialog tetap menampilkan banner. Jika penyimpanan
  browser diblokir, popup tetap dapat ditutup, tetapi pilihan tersebut
  mungkin tidak bertahan setelah halaman dimuat ulang.