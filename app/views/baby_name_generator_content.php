<?php
if (!isset($babyNameApply, $babyNameBack, $_SESSION['id_user'])) {
    http_response_code(403);
    exit;
}
?>
    <section class="generator-content" id="baby-generator" data-catalog="../assets/data/baby-names.json?v=20260909-2" data-account="<?= (int)$_SESSION['id_user'] ?>" data-apply="<?= htmlspecialchars($babyNameApply, ENT_QUOTES, 'UTF-8') ?>">
      <a class="back-link" href="<?= htmlspecialchars($babyNameBack, ENT_QUOTES, 'UTF-8') ?>">← Kembali ke <?= htmlspecialchars($babyNameBackLabel ?? 'Ajukan Permohonan', ENT_QUOTES, 'UTF-8') ?></a>
      <div class="generator-hero"><span class="eyebrow">SEBUAH NAMA, SEBUAH HARAPAN</span><h1>Temukan nama untuk si kecil</h1><p>Pilih asal nama dan harapan Anda. Simpan yang disukai, bicarakan bersama keluarga, lalu lanjutkan mengurus akta kelahiran.</p></div>
      <div class="generator-panel">
        <form class="generator-form" id="namePreferences">
          <div class="field"><label for="gender">Nama untuk</label><select id="gender"><option value="L">Anak laki-laki</option><option value="P">Anak perempuan</option></select></div>
          <div class="field"><label for="nameOrigin">Asal nama</label><select id="nameOrigin"><option value="Arab">Arab</option><option value="Eropa">Eropa</option><option value="Indonesia/Nusantara">Indonesia/Nusantara</option><option value="Sanskerta">Sanskerta</option><option value="campuran">Campuran pilihan sendiri</option></select></div>
          <div class="field"><label for="nameTheme">Harapan Anda</label><select id="nameTheme"><option value="all">Semua makna baik</option><option value="kebaikan">Kebaikan</option><option value="kasih">Kasih sayang</option><option value="kedamaian">Kedamaian</option><option value="bahagia">Kebahagiaan</option><option value="keberanian">Keberanian</option><option value="bijaksana">Kebijaksanaan</option><option value="cahaya">Cahaya</option></select></div>
          <div class="field"><label for="wordCount">Jumlah kata</label><select id="wordCount"><option value="2">2 kata</option><option value="3">3 kata</option><option value="4">4 kata</option></select></div>
          <fieldset class="mixed-origins" id="mixedOrigins" hidden>
            <legend>Gabungkan dua asal nama</legend>
            <div class="field"><label for="mixOriginA">Pilihan pertama</label><select id="mixOriginA"><option value="Arab">Arab</option><option value="Eropa">Eropa</option><option value="Indonesia/Nusantara">Indonesia/Nusantara</option><option value="Sanskerta">Sanskerta</option></select></div>
            <div class="field"><label for="mixOriginB">Pilihan kedua</label><select id="mixOriginB"><option value="Arab">Arab</option><option value="Eropa">Eropa</option><option value="Indonesia/Nusantara">Indonesia/Nusantara</option><option value="Sanskerta" selected>Sanskerta</option></select></div>
            <p class="form-note">Pilih dua asal yang berbeda. Setiap rangkaian akan memakai nama dari keduanya.</p>
          </fieldset>
          <button type="submit" class="generate-btn" id="generateButton" disabled>Cari inspirasi nama</button>
          <p class="form-note">Tidak perlu tanggal lahir. Jika memilih harapan tertentu, setidaknya satu kata akan sesuai dengan tema itu. Setiap arti memiliki rujukan yang bisa Anda baca.</p>
        </form>
        <p id="catalogInfo" class="catalog-info">Sedang membuka daftar nama…</p>
        <div class="results-head"><h2>Pilihan untuk si kecil</h2><button type="button" class="copy-btn" id="favoritesToggle" aria-pressed="false">Lihat favorit (0)</button></div>
        <p id="resultInfo" role="status" aria-live="polite"></p>
        <div id="nameResults" class="empty-state">Sebentar, ya…</div>
        <button type="button" class="copy-btn" id="retryCatalog" hidden>Coba buka lagi</button>
      </div>
      <dialog id="confirmBabyName" aria-labelledby="confirmNameTitle"><h2 id="confirmNameTitle">Sudah cocok dengan nama ini?</h2><p id="confirmedName" class="name-title"></p><p>Periksa ejaannya bersama keluarga. Setelah dilanjutkan, nama akan masuk ke formulir akta dan masih bisa Anda ubah sebelum dikirim.</p><div class="name-actions"><button type="button" class="copy-btn" id="cancelBabyName">Pilih lagi</button><button type="button" class="generate-btn" id="useBabyName">Lanjut ke formulir akta</button></div><p id="handoffNotice" role="status"></p></dialog>
    </section>
