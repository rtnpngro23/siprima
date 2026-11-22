// Kanvas tanda tangan + pratinjau formulir F-2.01.
// Dipakai bersama oleh halaman ajuan dan halaman edit akta kelahiran.
// Semua hasil di sini hanya petunjuk untuk pemohon; pemeriksaan yang mengikat ada di server.
(function () {
  'use strict';

  const skrip = document.currentScript;
  const idForm = (skrip && skrip.dataset.form) || '';
  const urlPratinjau = (skrip && skrip.dataset.pratinjau) || 'pratinjau_formulir.php';

  function mulai() {
    const kanvas = document.getElementById('kanvasTtd');
    const hidden = document.getElementById('ttdPemohon');
    const cek = document.getElementById('pernyataanBenar');
    const area = document.getElementById('areaTtd');
    if (!kanvas || !hidden || !cek || !area) return;

    const btnHapus = document.getElementById('btnHapusTtd');
    const btnPratinjau = document.getElementById('btnPratinjauFormulir');
    const kotak = document.getElementById('kotakPratinjau');
    const gambar = document.getElementById('gambarPratinjau');
    const pesan = document.getElementById('pesanPratinjau');

    const ctx = kanvas.getContext('2d');
    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111827';
    let menggambar = false;
    let adaGoresan = false;

    // Kanvas dipakai pada ukuran internal 600x200 walau tampil melar mengikuti lebar layar.
    function titik(e) {
      const r = kanvas.getBoundingClientRect();
      return {
        x: (e.clientX - r.left) * (kanvas.width / r.width),
        y: (e.clientY - r.top) * (kanvas.height / r.height)
      };
    }

    function simpan() {
      hidden.value = adaGoresan ? kanvas.toDataURL('image/png') : '';
    }

    kanvas.addEventListener('pointerdown', function (e) {
      if (area.style.pointerEvents === 'none') return;
      menggambar = true;
      adaGoresan = true;
      try { kanvas.setPointerCapture(e.pointerId); } catch (err) { /* peramban lama */ }
      const p = titik(e);
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      e.preventDefault();
    });

    kanvas.addEventListener('pointermove', function (e) {
      if (!menggambar) return;
      const p = titik(e);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      e.preventDefault();
    });

    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (nama) {
      kanvas.addEventListener(nama, function () {
        if (!menggambar) return;
        menggambar = false;
        simpan();
      });
    });

    function setAktif(aktif) {
      area.style.opacity = aktif ? '1' : '.5';
      area.style.pointerEvents = aktif ? 'auto' : 'none';
    }
    cek.addEventListener('change', function () { setAktif(cek.checked); });
    setAktif(cek.checked);

    if (btnHapus) {
      btnHapus.addEventListener('click', function () {
        ctx.clearRect(0, 0, kanvas.width, kanvas.height);
        adaGoresan = false;
        hidden.value = '';
        if (kotak) kotak.style.display = 'none';
      });
    }

    // Memulihkan tanda tangan setelah submit gagal agar pemohon tidak perlu mengulang.
    if (hidden.value) {
      const pulih = new Image();
      pulih.onload = function () {
        ctx.drawImage(pulih, 0, 0, kanvas.width, kanvas.height);
        adaGoresan = true;
      };
      pulih.src = hidden.value;
    }

    if (btnPratinjau) {
      btnPratinjau.addEventListener('click', async function () {
        simpan();
        if (pesan) pesan.style.display = 'none';
        const labelAwal = btnPratinjau.textContent;
        btnPratinjau.disabled = true;
        btnPratinjau.textContent = 'Menyiapkan pratinjau...';
        try {
          const form = (idForm && document.getElementById(idForm)) || kanvas.closest('form');
          if (!form) throw new Error('form tidak ditemukan');
          const data = new FormData(form);
          data.delete('dokumen[]'); // pratinjau tidak membutuhkan berkas unggahan
          const res = await fetch(urlPratinjau, { method: 'POST', body: data, credentials: 'same-origin' });
          const tipe = res.headers.get('Content-Type') || '';
          if (res.ok && tipe.indexOf('image/') === 0) {
            const blob = await res.blob();
            if (gambar.dataset.objectUrl) URL.revokeObjectURL(gambar.dataset.objectUrl);
            const url = URL.createObjectURL(blob);
            gambar.dataset.objectUrl = url;
            gambar.src = url;
            kotak.style.display = 'block';
            kotak.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          } else {
            let daftar = ['Pratinjau belum dapat dibuat.'];
            if (tipe.indexOf('application/json') !== -1) {
              const body = await res.json();
              if (Array.isArray(body.errors) && body.errors.length) daftar = body.errors;
            }
            if (pesan) {
              pesan.textContent = 'Perbaiki dulu: ' + daftar.join(' ');
              pesan.style.display = 'block';
            }
            if (kotak) kotak.style.display = 'none';
          }
        } catch (err) {
          if (pesan) {
            pesan.textContent = 'Pratinjau gagal dimuat. Periksa koneksi Anda lalu coba lagi.';
            pesan.style.display = 'block';
          }
        } finally {
          btnPratinjau.disabled = false;
          btnPratinjau.textContent = labelAwal;
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mulai);
  } else {
    mulai();
  }
})();
