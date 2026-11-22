<?php
// Kartu anggota keluarga untuk formulir F-1.01 yang dibuat sistem.
// Dipakai bersama oleh kk.php (pengajuan) dan edit_kk.php (ajukan ulang).
//
// Nama/NIK/hubungan selalu tampak; delapan field lainnya disembunyikan di balik tombol
// "Ubah data lainnya". Kosong berarti TIDAK ADA PERUBAHAN pada data tersebut.

require_once __DIR__ . '/../functions/formulir_f101.php';

if (!function_exists('siprima_f101_kartu_anggota')) {
    /**
     * Menggambar satu kartu anggota.
     * $nomor null berarti kartu ini dipakai sebagai <template> untuk JS (input dinonaktifkan
     * agar tidak ikut terkirim sebelum digandakan).
     */
    function siprima_f101_kartu_anggota(array $a = [], ?int $nomor = null): void
    {
        $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $template = $nomor === null;
        $mati = $template ? ' disabled' : '';
        $wajib = $template ? '' : ' required';

        $selects = [
            'jenis_kelamin' => siprima_f101_jenis_kelamin_options(),
            'agama' => siprima_f101_agama_options(),
            'gol_darah' => siprima_f101_gol_darah_options(),
            'pendidikan' => siprima_f101_pendidikan_options(),
            'status_kawin' => siprima_f101_status_kawin_options(),
        ];
        $labels = siprima_f101_field_opsional();
        ?>
        <div class="anggota-kartu" style="border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:12px; background:#fff;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <strong style="color:#1f2937;">Anggota <span class="anggota-nomor"><?= $template ? '' : (int)$nomor ?></span></strong>
            <button type="button" class="btn-hapus-anggota" style="background:#fee2e2; color:#991b1b; border:none; border-radius:6px; padding:4px 10px; cursor:pointer;">Hapus</button>
          </div>

          <div class="form-grid-2">
            <div>
              <label class="field-label">Nama Lengkap <span style="color:red">*</span></label>
              <input type="text" name="anggota_nama[]" class="input" value="<?= $esc($a['nama'] ?? '') ?>"
                     placeholder="Nama lengkap"<?= $wajib . $mati ?>>
            </div>
            <div>
              <label class="field-label">NIK</label>
              <input type="text" name="anggota_nik[]" class="input" value="<?= $esc($a['nik'] ?? '') ?>"
                     placeholder="16 digit NIK (boleh dikosongkan)" maxlength="16" inputmode="numeric"
                     pattern="[0-9]{16}" title="NIK harus 16 digit angka"
                     oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 16)"<?= $mati ?>>
            </div>
          </div>

          <div>
            <label class="field-label">Hubungan Keluarga</label>
            <input type="text" name="anggota_hubungan[]" class="input" value="<?= $esc($a['hubungan'] ?? '') ?>"
                   placeholder="Contoh: Kepala Keluarga, Istri, Anak" maxlength="50"<?= $mati ?>>
          </div>

          <?php
          // Panel dibuka otomatis bila sudah ada perubahan yang terisi, supaya isian tidak tersembunyi
          // setelah submit gagal.
          $adaIsi = false;
          foreach (array_keys($labels) as $k) {
              if (trim((string)($a[$k] ?? '')) !== '') { $adaIsi = true; break; }
          }
          ?>
          <button type="button" class="btn-toggle-ubah btn-secondary" style="margin-top:10px;">
            <?= $adaIsi ? 'Sembunyikan data lainnya' : 'Ubah data lainnya' ?>
          </button>

          <div class="anggota-ubah" style="margin-top:12px;<?= $adaIsi ? '' : 'display:none;' ?>">
            <p class="helper" style="margin-top:0;">
              <strong>Kosongkan yang tidak ingin diubah.</strong> Field yang dibiarkan kosong berarti
              tidak ada perubahan pada data tersebut.
            </p>
            <div class="form-grid-2">
              <?php foreach ($labels as $key => $label): ?>
                <div>
                  <label class="field-label"><?= $esc($label) ?></label>
                  <?php if (isset($selects[$key])): ?>
                    <select name="anggota_<?= $esc($key) ?>[]" class="input"<?= $mati ?>>
                      <option value="">— tidak diubah —</option>
                      <?php foreach ($selects[$key] as $opsi): ?>
                        <option value="<?= $esc($opsi) ?>" <?= ((string)($a[$key] ?? '') === $opsi) ? 'selected' : '' ?>><?= $esc($opsi) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($key === 'tanggal_lahir'): ?>
                    <input type="date" name="anggota_tanggal_lahir[]" class="input"
                           value="<?= $esc($a[$key] ?? '') ?>" max="<?= date('Y-m-d') ?>" min="1900-01-01"<?= $mati ?>>
                  <?php else: ?>
                    <input type="text" name="anggota_<?= $esc($key) ?>[]" class="input"
                           value="<?= $esc($a[$key] ?? '') ?>" maxlength="<?= $key === 'pekerjaan' ? 60 : 50 ?>"
                           placeholder="Kosongkan bila tidak diubah"<?= $mati ?>>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php
    }
}

if (!function_exists('siprima_f101_anggota_dari_post')) {
    /**
     * Menyusun ulang daftar anggota dari $_POST mentah untuk mengisi kembali form setelah submit
     * gagal. Sengaja TIDAK memvalidasi: tujuannya mengembalikan apa yang tadi diketik pemohon.
     */
    function siprima_f101_anggota_dari_post(array $post): array
    {
        $nama = $post['anggota_nama'] ?? null;
        if (!is_array($nama) || !$nama) return [];
        $kolom = array_merge(['nama', 'nik', 'hubungan'], array_keys(siprima_f101_field_opsional()));
        $out = [];
        foreach (array_keys($nama) as $i) {
            $item = [];
            foreach ($kolom as $k) {
                $item[$k] = (string)($post['anggota_' . $k][$i] ?? '');
            }
            $out[] = $item;
        }
        return $out;
    }
}
