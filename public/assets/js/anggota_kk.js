// Kartu anggota keluarga untuk formulir F-1.01 yang dibuat sistem.
// Dipakai bersama halaman pengajuan dan halaman edit Kartu Keluarga.
// Semua pemeriksaan di sini hanya petunjuk untuk pemohon; yang mengikat ada di server.
(function () {
  'use strict';

  const MAKS = 50;
  const PERINGATAN = 30;

  function mulai() {
    const wadah = document.getElementById('anggotaWadah');
    const template = document.getElementById('anggotaTemplate');
    const btnTambah = document.getElementById('btnTambahAnggota');
    if (!wadah || !template || !btnTambah) return;

    const pesan = document.getElementById('pesanAnggota');

    function kartuSemua() {
      return wadah.querySelectorAll('.anggota-kartu');
    }

    function perbarui() {
      const kartu = kartuSemua();
      kartu.forEach(function (k, i) {
        const nomor = k.querySelector('.anggota-nomor');
        if (nomor) nomor.textContent = String(i + 1);
        // Minimal satu anggota: tombol hapus disembunyikan saat hanya tersisa satu.
        const hapus = k.querySelector('.btn-hapus-anggota');
        if (hapus) hapus.style.visibility = kartu.length > 1 ? 'visible' : 'hidden';
      });

      btnTambah.disabled = kartu.length >= MAKS;
      if (pesan) {
        if (kartu.length >= MAKS) {
          pesan.textContent = 'Batas maksimal ' + MAKS + ' anggota dalam satu permohonan sudah tercapai.';
          pesan.style.display = 'block';
        } else if (kartu.length >= PERINGATAN) {
          pesan.textContent = 'Sudah ada ' + kartu.length + ' anggota. Maksimal ' + MAKS + ' anggota per permohonan.';
          pesan.style.display = 'block';
        } else {
          pesan.style.display = 'none';
        }
      }
    }

    function pasangKartu(kartu) {
      const toggle = kartu.querySelector('.btn-toggle-ubah');
      const panel = kartu.querySelector('.anggota-ubah');
      if (toggle && panel) {
        toggle.addEventListener('click', function () {
          const tertutup = panel.style.display === 'none';
          panel.style.display = tertutup ? 'block' : 'none';
          toggle.textContent = tertutup ? 'Sembunyikan data lainnya' : 'Ubah data lainnya';
        });
      }
      const hapus = kartu.querySelector('.btn-hapus-anggota');
      if (hapus) {
        hapus.addEventListener('click', function () {
          if (kartuSemua().length <= 1) return;
          kartu.remove();
          perbarui();
        });
      }
    }

    kartuSemua().forEach(pasangKartu);

    btnTambah.addEventListener('click', function () {
      if (kartuSemua().length >= MAKS) return;
      const salinan = template.content.cloneNode(true);
      const kartu = salinan.querySelector('.anggota-kartu');
      // Template dinonaktifkan agar tidak ikut terkirim; aktifkan setelah digandakan.
      kartu.querySelectorAll('[disabled]').forEach(function (el) { el.disabled = false; });
      const nama = kartu.querySelector('input[name="anggota_nama[]"]');
      if (nama) nama.required = true;
      wadah.appendChild(kartu);
      pasangKartu(kartu);
      perbarui();
      if (nama) nama.focus();
    });

    perbarui();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mulai);
  } else {
    mulai();
  }
})();
