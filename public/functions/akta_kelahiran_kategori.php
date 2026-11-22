<?php
declare(strict_types=1);

// Kategori permohonan akta kelahiran. Semua kategori memakai tabel tb_akta_kelahiran yang sama
// dan dibedakan lewat kolom `jenis_permohonan`; berkas lama tanpa kolom ini bernilai default
// 'baru_belum_nik' sehingga perilaku permohonan lama tidak berubah.

const AKTA_KELAHIRAN_JENIS_DEFAULT = 'baru_belum_nik';

function siprima_akta_kelahiran_jenis_options(): array
{
    return [
        'baru_belum_nik' => 'Bayi Baru Lahir (Belum Memiliki NIK)',
        'baru_sudah_nik' => 'Sudah Memiliki NIK (Belum Memiliki Akta)',
        'hilang'         => 'Akta Kelahiran Hilang',
        'pembaruan'      => 'Pembaruan Akta Kelahiran (TTE Barcode)',
    ];
}

function siprima_akta_kelahiran_jenis_label(string $jenis): string
{
    return siprima_akta_kelahiran_jenis_options()[$jenis]
        ?? siprima_akta_kelahiran_jenis_options()[AKTA_KELAHIRAN_JENIS_DEFAULT];
}

// Nilai dari URL/POST tidak pernah dipercaya: apa pun di luar daftar dikembalikan ke default.
function siprima_akta_kelahiran_jenis_normalize(?string $raw): string
{
    $raw = trim((string)$raw);
    return isset(siprima_akta_kelahiran_jenis_options()[$raw]) ? $raw : AKTA_KELAHIRAN_JENIS_DEFAULT;
}

// Kategori yang ditangani form pencatatan kelahiran (akta_kelahiran.php). Kategori 'hilang'
// punya form sendiri karena tidak mencatat peristiwa kelahiran sama sekali.
function siprima_akta_kelahiran_jenis_form_kelahiran(): array
{
    return ['baru_belum_nik', 'baru_sudah_nik', 'pembaruan'];
}
