Penjelasan singkat:

- Semua file CSS spesifik halaman dipindahkan ke folder `user/css/`.
- File-file di root `user/*.css` sekarang adalah shim yang hanya melakukan `@import` ke `css/global.css` dan file yang relevan.
- `css/global.css` berisi rules yang digunakan oleh semua halaman (variabel, reset, utilitas).

Jika Anda ingin menambahkan style bersama, taruh di `user/css/global.css`.
