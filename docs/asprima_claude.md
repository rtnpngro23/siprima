# Claude di ASPRIMA

Isi ANTHROPIC_API_KEY di app/config/.env.local pada server. File ini diabaikan Git. Environment proses mengalahkan file ini. ANTHROPIC_MODEL di .env.local mengalahkan default di asprima.php; mengganti default PHP saja tidak mengganti model aktif. Gunakan ASPRIMA_AI_ENABLED=false untuk menonaktifkan.

Alur: validasi akun dan hak akses -> interpretasi pertanyaan Claude -> ambil panduan SIPRIMA -> Claude menyusun jawaban -> lampirkan data dan tombol resmi secara lokal. Respons sukses generatif memiliki engine=claude dan sumber "Claude berdasarkan panduan SIPRIMA". Key kosong, kegagalan API, atau keluaran tidak valid menghasilkan engine=local dengan pemberitahuan cadangan yang terlihat pada widget.

Data permohonan, nama pemohon dari database, catatan petugas, riwayat, dan berkas tidak dikirim. Untuk status pribadi, teks AI hanya pengantar; rincian resmi ditambahkan utuh oleh backend. Panduan persyaratan yang sesuai layanan dikirim tanpa identitas permohonan. Teks pertanyaan pengguna dikirim setelah penyamaran pola email dan nomor panjang; ini tidak menjamin penghapusan semua data pribadi. Tidak ada riwayat percakapan yang dikirim. Anthropic tidak menyediakan parameter per-request setara `store=false` OpenAI; retensi data diatur di tingkat organisasi/perjanjian Anthropic, bukan per panggilan — periksa kebijakan retensi akun Anthropic yang dipakai sebelum mengaktifkan fitur ini di produksi.

Maksimal dua panggilan API per pertanyaan, masing-masing dibatasi 2048 token keluaran, timeout 20 detik per panggilan, tanpa retry otomatis. Batas tunggu widget 55 detik. Endpoint tetap membatasi 30 request per menit per sesi. Panggilan intent memakai `output_config.format` (structured output, strict `additionalProperties:false`) agar keluaran selalu JSON valid sesuai skema; hanya respons dengan `stop_reason=end_turn` yang diterima, selain itu jatuh ke panduan lokal.

Model default `claude-haiku-4-5` (lihat komentar di `app/config/asprima.php`) karena tugasnya klasifikasi ke topik tetap dan parafrase pendek dari panduan yang sudah jadi — bukan penalaran panjang. Naikkan `ANTHROPIC_MODEL` ke `claude-sonnet-5` atau `claude-opus-5` bila kualitas jawaban perlu ditingkatkan; tidak perlu perubahan kode. Thinking/effort sengaja tidak diset di payload agar payload tetap valid untuk model apa pun yang dipilih di `ANTHROPIC_MODEL` (parameter `effort` ditolak oleh Claude Haiku 4.5).

Pastikan billing/kredit API aktif, model dapat diakses akun, PHP cURL tersedia, CA valid, dan server dapat mengakses https://api.anthropic.com:443. Jangan menonaktifkan verifikasi TLS. HTTP 429 berarti dibatasi laju/kuota; lihat `error.type` pada respons (dicatat sebagai kode aman di log, bukan pesan lengkap) untuk diagnosis. Nilai key tidak boleh dikirim melalui chat, browser, atau log.

Konfigurasi app diblokir .htaccess proyek. Pada Nginx/server tanpa AllowOverride, gunakan document root public_html/public atau blokir akses HTTP ke app, tests, docs, dan file environment.

Tes tanpa biaya API:
php tests/asprima_test.php
php tests/asprima_claude_test.php
node tests/asprima_handoff_test.js

Dokumentasi: https://platform.claude.com/docs/en/build-with-claude/structured-outputs
