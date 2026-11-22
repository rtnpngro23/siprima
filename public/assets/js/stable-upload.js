(function () {
  'use strict';

  const FORM_SELECTOR = 'form[enctype="multipart/form-data"]';
  const INACTIVITY_TIMEOUT_MS = 90000;
  const COMPRESSION_TARGET_BYTES = 480 * 1024;
  const MAX_SOURCE_BYTES = 5 * 1024 * 1024;
  const COMPRESSIBLE_IMAGE_TYPES = new Set(['image/jpeg', 'image/png']);
  const activeCompressions = new WeakMap();
  const stableFileCopies = new WeakMap();

  function compressionEnabledOnCurrentPage() {
    return true;
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      const image = new Image();
      const objectUrl = URL.createObjectURL(file);

      image.onload = function () {
        URL.revokeObjectURL(objectUrl);
        resolve(image);
      };
      image.onerror = function () {
        URL.revokeObjectURL(objectUrl);
        reject(new Error('Gambar tidak dapat dibaca.'));
      };
      image.src = objectUrl;
    });
  }

  function canvasToBlob(canvas, type, quality) {
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (blob) resolve(blob);
        else reject(new Error('Gambar tidak dapat dikompres.'));
      }, type, quality);
    });
  }

  async function renderCompressedBlob(image, type, scale, quality) {
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
    canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));

    const context = canvas.getContext('2d', { alpha: type === 'image/png' });
    if (!context) throw new Error('Perangkat tidak mendukung kompresi gambar.');

    if (type === 'image/jpeg') {
      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, canvas.width, canvas.height);
    }
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    return canvasToBlob(canvas, type, quality);
  }

  async function compressImage(file) {
    if (file.size <= COMPRESSION_TARGET_BYTES || !COMPRESSIBLE_IMAGE_TYPES.has(file.type)) {
      return file;
    }
    if (file.size > MAX_SOURCE_BYTES) {
      throw new Error('Ukuran gambar asli maksimal 5 MB.');
    }

    const image = await loadImage(file);
    let scale = Math.min(1, 1600 / Math.max(image.naturalWidth, image.naturalHeight));
    let quality = 0.88;
    let result = null;

    for (let attempt = 0; attempt < 10; attempt += 1) {
      result = await renderCompressedBlob(image, file.type, scale, quality);
      if (result.size <= COMPRESSION_TARGET_BYTES) break;

      if (file.type === 'image/jpeg' && quality > 0.58) {
        quality -= 0.08;
      } else {
        scale *= 0.82;
      }
    }

    if (!result || result.size > COMPRESSION_TARGET_BYTES) {
      throw new Error('Gambar tidak dapat diperkecil hingga di bawah 500 KB tanpa mengurangi keterbacaan secara berlebihan.');
    }

    return new File([result], file.name, {
      type: file.type,
      lastModified: file.lastModified || Date.now()
    });
  }

  function replaceInputFile(input, file) {
    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
  }

  function compressionStatusElement(input) {
    const wrapper = input.closest('.file-wrapper') || input.parentElement;
    return wrapper ? wrapper.querySelector('.file-name') : null;
  }

  async function prepareSelectedImage(input, originalFile) {
    const status = compressionStatusElement(input);
    const form = input.form;
    const submitButtons = form ? form.querySelectorAll('button[type="submit"], input[type="submit"]') : [];
    if (status) status.textContent = 'Mengompres gambar...';
    for (const button of submitButtons) {
      rememberButton(button);
      button.disabled = true;
      setButtonLabel(button, 'Mengompres gambar...');
    }

    try {
      const preparedFile = await compressImage(originalFile);
      replaceInputFile(input, preparedFile);
      input.dataset.compressionReady = '1';
      input.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (error) {
      input.value = '';
      if (status) status.textContent = 'Belum ada file dipilih';
      alert(error.message || 'Gambar gagal dikompres. Silakan pilih gambar lain.');
    } finally {
      activeCompressions.delete(input);
      const stillCompressing = form && Array.from(form.querySelectorAll('input[type="file"]'))
        .some(function (fileInput) { return activeCompressions.has(fileInput); });
      if (!stillCompressing) {
        for (const button of submitButtons) restoreButton(button);
      }
    }
  }

  function readFile(file) {
    if (typeof file.arrayBuffer === 'function') {
      return file.arrayBuffer();
    }

    return new Promise(function (resolve, reject) {
      const reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { reject(reader.error || new Error('File tidak dapat dibaca.')); };
      reader.onabort = function () { reject(new Error('Pembacaan file dibatalkan.')); };
      reader.readAsArrayBuffer(file);
    });
  }

  function cacheFileCopy(file) {
    if (!(file instanceof File) || file.size === 0) return Promise.resolve(null);

    let copy = stableFileCopies.get(file);
    if (!copy) {
      copy = readFile(file).then(function (bytes) {
        const uploadName = file.type === 'image/jpeg' && /\.(jfif|jpe)$/i.test(file.name)
          ? file.name.replace(/\.(jfif|jpe)$/i, '.jpg')
          : file.name;
        return {
          blob: new Blob([bytes], { type: file.type || 'application/octet-stream' }),
          name: uploadName || 'dokumen'
        };
      });
      // File dari content provider Android dapat gagal lebih cepat daripada
      // submit. Pasang handler sekarang agar rejection tetap tertangani.
      copy.catch(function () {});
      stableFileCopies.set(file, copy);
    }
    return copy;
  }

  window.SiprimaStableUpload = window.SiprimaStableUpload || {};
  window.SiprimaStableUpload.copyFile = cacheFileCopy;

  function uploadFormData(url, method, body, onProgress) {
    return new Promise(function (resolve, reject) {
      const xhr = new XMLHttpRequest();
      let inactivityTimer = null;
      let inactivityAbort = false;

      function clearInactivityTimer() {
        if (inactivityTimer !== null) {
          window.clearTimeout(inactivityTimer);
          inactivityTimer = null;
        }
      }

      function resetInactivityTimer() {
        clearInactivityTimer();
        inactivityTimer = window.setTimeout(function () {
          inactivityAbort = true;
          xhr.abort();
        }, INACTIVITY_TIMEOUT_MS);
      }

      xhr.open(method || 'POST', url, true);
      xhr.withCredentials = true;

      xhr.upload.addEventListener('progress', function (event) {
        resetInactivityTimer();
        if (!event.lengthComputable || typeof onProgress !== 'function') return;
        onProgress(Math.min(100, Math.round((event.loaded / event.total) * 100)), event.loaded, event.total);
      });
      xhr.upload.addEventListener('load', resetInactivityTimer);
      xhr.addEventListener('progress', resetInactivityTimer);
      xhr.addEventListener('load', function () {
        clearInactivityTimer();
        resolve({
          ok: xhr.status >= 200 && xhr.status < 400,
          status: xhr.status,
          text: xhr.responseText,
          url: xhr.responseURL || url,
          contentType: xhr.getResponseHeader('content-type') || ''
        });
      });
      xhr.addEventListener('error', function () {
        clearInactivityTimer();
        reject(new Error('Koneksi upload terputus.'));
      });
      xhr.addEventListener('abort', function () {
        clearInactivityTimer();
        const error = new Error(inactivityAbort
          ? 'Tidak ada aktivitas upload selama 90 detik.'
          : 'Upload dibatalkan.');
        error.name = 'AbortError';
        reject(error);
      });
      resetInactivityTimer();
      xhr.send(body);
    });
  }

  window.SiprimaStableUpload.uploadFormData = uploadFormData;

  async function createStableFormData(form, submitter) {
    const source = new FormData(form);
    const stable = new FormData();

    for (const entry of source.entries()) {
      const name = entry[0];
      const value = entry[1];

      if (!(value instanceof File) || value.size === 0) {
        stable.append(name, value);
        continue;
      }

      try {
        const copy = await cacheFileCopy(value);
        stable.append(name, copy.blob, copy.name);
      } catch (error) {
        const fileError = new Error('Dokumen "' + (value.name || 'tanpa nama') + '" tidak dapat dibaca oleh perangkat.');
        fileError.cause = error;
        throw fileError;
      }
    }

    // FormData(form) tidak menyertakan tombol submit yang diklik, sedangkan
    // submit HTML native menyertakannya. Pertahankan perilaku tersebut.
    // Handler halaman mungkin sudah menonaktifkan tombol untuk mencegah klik
    // ganda sebelum listener ini berjalan. Tombol yang benar-benar diklik tetap
    // harus ikut dikirim (beberapa form memakai name/value sebagai action).
    if (submitter && submitter.name) {
      stable.append(submitter.name, submitter.value || '');
    }

    return stable;
  }

  function selectedFileCount(form) {
    let count = 0;
    const inputs = form.querySelectorAll('input[type="file"]');
    for (const input of inputs) {
      if (input.files && input.files.length) count += input.files.length;
    }
    return count;
  }

  function getSubmitButton(form, submitter) {
    if (submitter && (submitter.type === 'submit' || submitter.tagName === 'BUTTON')) {
      return submitter;
    }
    return form.querySelector('button[type="submit"], input[type="submit"]');
  }

  function rememberButton(button) {
    if (!button || button.dataset.stableOriginalLabel) return;
    button.dataset.stableOriginalLabel = button.tagName === 'INPUT' ? button.value : button.textContent;
  }

  function setButtonLabel(button, label) {
    if (!button) return;
    if (button.tagName === 'INPUT') button.value = label;
    else button.textContent = label;
  }

  function restoreButton(button) {
    if (!button) return;
    button.disabled = false;
    if (button.dataset.stableOriginalLabel) {
      setButtonLabel(button, button.dataset.stableOriginalLabel);
    }
  }

  function getFormAction(form) {
    const action = form.getAttribute('action');
    return action && action.trim() !== '' ? action : window.location.href;
  }

  function getFormMethod(form) {
    const method = form.getAttribute('method');
    return (method || 'GET').toUpperCase();
  }

  async function sendStableUpload(form, event) {
    const button = getSubmitButton(form, event.submitter);
    rememberButton(button);
    form.dataset.stableUploading = '1';
    if (button) {
      button.disabled = true;
      setButtonLabel(button, 'Menyiapkan berkas...');
    }

    try {
      const body = await createStableFormData(form, event.submitter);
      const actionUrl = getFormAction(form);
      const response = await uploadFormData(actionUrl, getFormMethod(form), body, function (percent) {
        if (button) setButtonLabel(button, percent < 100 ? 'Mengunggah ' + percent + '%' : 'Menyimpan permohonan...');
      });

      if (!response.ok) {
        throw new Error('Server merespons dengan status ' + response.status + '.');
      }

      const contentType = response.contentType;
      if (contentType.indexOf('text/html') === -1) {
        window.location.assign(response.url || actionUrl);
        return;
      }

      const html = response.text;
      if (response.url) {
        window.history.replaceState(null, '', response.url);
      }
      document.open();
      document.write(html);
      document.close();
    } catch (error) {
      console.error('Stable upload gagal:', error);
      form.dataset.stableUploading = '0';
      restoreButton(button);

      if (error && error.name === 'AbortError') {
        alert('Upload melewati batas waktu. Periksa koneksi internet lalu coba lagi. Data formulir Anda tidak hilang.');
      } else if (error && /^Dokumen /.test(error.message || '')) {
        alert(error.message + '\n\nPilih ulang file tersebut dari folder Download, lalu segera kirim kembali. Data formulir Anda tidak hilang.');
      } else {
        alert('Dokumen belum berhasil dikirim. Periksa koneksi internet lalu coba lagi. Data formulir Anda tidak hilang.');
      }
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    const forms = document.querySelectorAll(FORM_SELECTOR);
    for (const form of forms) {
      const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
      for (const button of buttons) rememberButton(button);
    }
  });

  // Jalankan sebelum handler validasi halaman agar gambar besar sudah diganti
  // dengan hasil kompresi ketika pemeriksaan batas 1 MB dilakukan.
  document.addEventListener('change', function (event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;

    const selectedFile = input.files && input.files[0];
    if (selectedFile && selectedFile.type.startsWith('image/') && !COMPRESSIBLE_IMAGE_TYPES.has(selectedFile.type)) {
      input.value = '';
      alert('Format gambar tidak didukung. Gunakan JPG, JPEG, JFIF, JPE, atau PNG.');
      return;
    }
    if (!compressionEnabledOnCurrentPage()) {
      if (selectedFile) cacheFileCopy(selectedFile);
      return;
    }
    if (input.dataset.compressionReady === '1') {
      delete input.dataset.compressionReady;
      if (selectedFile) cacheFileCopy(selectedFile);
      return;
    }

    const file = selectedFile;
    if (!file || file.size <= COMPRESSION_TARGET_BYTES || !COMPRESSIBLE_IMAGE_TYPES.has(file.type)) {
      if (file) cacheFileCopy(file);
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    if (activeCompressions.has(input)) return;

    const compression = prepareSelectedImage(input, file);
    activeCompressions.set(input, compression);
  }, true);

  // Listener document dipasang setelah script validasi halaman. Jika validasi
  // halaman memanggil preventDefault(), upload tidak diteruskan.
  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches(FORM_SELECTOR)) return;
    if (form.dataset.stableUpload === 'off') return;
    if (event.defaultPrevented || form.dataset.stableUploading === '1') return;
    if (selectedFileCount(form) === 0) return;
    if (getFormMethod(form) !== 'POST') return;

    event.preventDefault();
    sendStableUpload(form, event);
  });
})();
