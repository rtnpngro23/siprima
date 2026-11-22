'use strict';
/**
 * Ceklis aturan password langsung saat pengguna mengetik.
 *
 * Aturannya TIDAK ditulis di sini: halaman menyuntikkan window.SiprimaPasswordRules
 * dari app/security/password_policy.php, sehingga ceklis di layar selalu sama
 * dengan aturan yang ditegakkan server.
 *
 * Cara pakai di HTML:
 *   <span data-password-rules-config="<?= htmlspecialchars(siprima_password_rules_json(), ENT_QUOTES) ?>" hidden></span>
 *   <input type="password" id="password" data-password-rules>
 *   <input type="password" id="confirm_password" data-password-match="password">
 *
 * Konfigurasi dibaca dari atribut data, bukan <script> inline, supaya tetap
 * jalan di halaman ber-CSP ketat (reset_password.php memakai script-src 'self').
 *
 * Tanpa JavaScript, halaman tetap berfungsi seperti sebelumnya: pemeriksaan
 * server tetap satu-satunya penentu.
 */
(function () {
    function ambilKonfigurasi() {
        const el = document.querySelector('[data-password-rules-config]');
        if (el) {
            try {
                return JSON.parse(el.dataset.passwordRulesConfig);
            } catch (e) {
                return null;
            }
        }
        return window.SiprimaPasswordRules || null;
    }

    const konfigurasi = ambilKonfigurasi();
    if (!konfigurasi || !Array.isArray(konfigurasi.aturan)) return;

    const aturan = konfigurasi.aturan
        .map(function (a) {
            try {
                return { kode: a.kode, teks: a.teks, uji: new RegExp(a.pola, 'u') };
            } catch (e) {
                return null; // pola yang tidak dikenal peramban ini dilewati, bukan menggagalkan semuanya
            }
        })
        .filter(Boolean);

    const maksByte = Number(konfigurasi.maksByte) || 72;
    const umum = (konfigurasi.umum || []).map(function (u) { return String(u).toLowerCase(); });
    const encoder = typeof TextEncoder === 'function' ? new TextEncoder() : null;

    function jumlahByte(teks) {
        if (encoder) return encoder.encode(teks).length;
        return unescape(encodeURIComponent(teks)).length;
    }

    function buatDaftar(input, judul) {
        const kotak = document.createElement('div');
        kotak.className = 'aturan-password';
        kotak.setAttribute('aria-live', 'polite');

        const kepala = document.createElement('p');
        kepala.className = 'aturan-password__judul';
        kepala.textContent = judul;
        kotak.appendChild(kepala);

        const daftar = document.createElement('ul');
        daftar.className = 'aturan-password__daftar';
        kotak.appendChild(daftar);

        const induk = input.closest('.password-wrapper, .password-input, .field, p, div') || input.parentElement;
        induk.insertAdjacentElement('afterend', kotak);
        return daftar;
    }

    function buatItem(daftar, teks) {
        const item = document.createElement('li');
        item.className = 'aturan-password__item';

        const tanda = document.createElement('span');
        tanda.className = 'aturan-password__tanda';
        tanda.setAttribute('aria-hidden', 'true');

        const label = document.createElement('span');
        label.textContent = teks;

        item.appendChild(tanda);
        item.appendChild(label);
        daftar.appendChild(item);
        return item;
    }

    function setStatus(item, terpenuhi, tersentuh) {
        item.classList.toggle('aturan-password__item--ok', terpenuhi);
        item.classList.toggle('aturan-password__item--kurang', tersentuh && !terpenuhi);
        const tanda = item.querySelector('.aturan-password__tanda');
        if (tanda) tanda.textContent = terpenuhi ? '✓' : '•';
    }

    document.querySelectorAll('input[data-password-rules]').forEach(function (input) {
        const daftar = buatDaftar(input, 'Aturan password:');
        const item = aturan.map(function (a) { return { a: a, el: buatItem(daftar, a.teks) }; });
        const itemUmum = buatItem(daftar, 'Bukan password yang terlalu umum');
        let tersentuh = false;

        // Batas panjang tetap dijaga karena server menolaknya, tetapi tidak
        // ditampilkan sebagai aturan: istilah "byte" tidak dimengerti semua orang.
        // Pesan ini hanya muncul kalau batasnya benar-benar terlampaui.
        const peringatanPanjang = document.createElement('p');
        peringatanPanjang.className = 'aturan-password__peringatan';
        peringatanPanjang.hidden = true;
        peringatanPanjang.textContent = 'Password terlalu panjang. Kurangi beberapa karakter.';
        daftar.parentElement.appendChild(peringatanPanjang);

        function perbarui() {
            const nilai = input.value;
            let semua = true;

            item.forEach(function (x) {
                const ok = x.a.uji.test(nilai);
                setStatus(x.el, ok, tersentuh);
                if (!ok) semua = false;
            });

            const okByte = nilai.length > 0 && jumlahByte(nilai) <= maksByte;
            peringatanPanjang.hidden = okByte || nilai.length === 0;
            if (!okByte) semua = false;

            const okUmum = nilai.length > 0 && umum.indexOf(nilai.toLowerCase()) === -1;
            setStatus(itemUmum, okUmum, tersentuh);
            if (!okUmum) semua = false;

            daftar.parentElement.classList.toggle('aturan-password--lengkap', semua && nilai.length > 0);
            input.dataset.passwordValid = semua ? '1' : '0';
        }

        input.addEventListener('input', function () { tersentuh = true; perbarui(); });
        input.addEventListener('blur', function () { tersentuh = true; perbarui(); });
        perbarui();

        // Konfirmasi password: satu baris tambahan "sama dengan password".
        const konfirmasi = document.querySelector('input[data-password-match="' + input.id + '"]');
        if (konfirmasi) {
            const daftarKonf = buatDaftar(konfirmasi, 'Konfirmasi:');
            const itemSama = buatItem(daftarKonf, 'Sama dengan password di atas');
            let konfTersentuh = false;

            function perbaruiKonfirmasi() {
                const sama = konfirmasi.value.length > 0 && konfirmasi.value === input.value;
                setStatus(itemSama, sama, konfTersentuh);
                daftarKonf.parentElement.classList.toggle('aturan-password--lengkap', sama);
            }

            konfirmasi.addEventListener('input', function () { konfTersentuh = true; perbaruiKonfirmasi(); });
            konfirmasi.addEventListener('blur', function () { konfTersentuh = true; perbaruiKonfirmasi(); });
            input.addEventListener('input', perbaruiKonfirmasi);
            perbaruiKonfirmasi();
        }
    });
})();
