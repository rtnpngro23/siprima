(function () {
    'use strict';

    function initialScale() {
        return 2;
    }

    let rotation = 0;
    let scale = initialScale();
    let panX = 0;
    let panY = 0;
    let dragStartX = 0;
    let dragStartY = 0;
    let activePointer = null;
    const minScale = 0.5;
    const maxScale = 5;

    function toolbarMarkup() {
        return `
            <button type="button" class="preview-tool-btn" onclick="rotatePreviewImage(-90)" title="Putar ke kiri" aria-label="Putar gambar ke kiri"><span aria-hidden="true">↶</span></button>
            <button type="button" class="preview-tool-btn" onclick="rotatePreviewImage(90)" title="Putar ke kanan" aria-label="Putar gambar ke kanan"><span aria-hidden="true">↷</span></button>
            <span class="preview-toolbar-separator" aria-hidden="true"></span>
            <button type="button" class="preview-tool-btn" id="previewZoomOutBtn" onclick="zoomPreviewImage(-0.25)" title="Perkecil" aria-label="Perkecil gambar"><span aria-hidden="true">−</span></button>
            <span class="preview-zoom-label" id="previewZoomLabel" aria-live="polite">100%</span>
            <button type="button" class="preview-tool-btn" id="previewZoomInBtn" onclick="zoomPreviewImage(0.25)" title="Perbesar" aria-label="Perbesar gambar"><span aria-hidden="true">+</span></button>`;
    }

    function ensureToolbar() {
        const modal = document.getElementById('previewModal');
        if (!modal) return null;

        let toolbar = document.getElementById('imagePreviewToolbar');
        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.id = 'imagePreviewToolbar';
            toolbar.className = 'preview-toolbar';
            toolbar.setAttribute('aria-label', 'Kontrol tampilan gambar');
            const header = modal.querySelector('.modal-header');
            if (header) header.insertAdjacentElement('afterend', toolbar);
        }
        if (toolbar.dataset.sharedControls !== 'true') {
            toolbar.innerHTML = toolbarMarkup();
            toolbar.dataset.sharedControls = 'true';
        }
        return toolbar;
    }

    function setToolbarVisible(visible) {
        const toolbar = ensureToolbar();
        if (toolbar) toolbar.hidden = !visible;
    }

    function updateTransform() {
        const image = document.getElementById('previewImage');
        const zoomLabel = document.getElementById('previewZoomLabel');
        const zoomOutButton = document.getElementById('previewZoomOutBtn');
        const zoomInButton = document.getElementById('previewZoomInBtn');

        if (image) {
            image.style.transform = `translate3d(${panX}px, ${panY}px, 0) rotate(${rotation}deg) scale(${scale})`;
        }
        if (zoomLabel) zoomLabel.textContent = `${Math.round(scale * 100)}%`;
        if (zoomOutButton) zoomOutButton.disabled = scale <= minScale;
        if (zoomInButton) zoomInButton.disabled = scale >= maxScale;
    }

    function resetTransform() {
        rotation = 0;
        scale = initialScale();
        panX = 0;
        panY = 0;
        activePointer = null;
        updateTransform();
    }

    function startDrag(event) {
        if (event.button !== 0 || !document.getElementById('previewImage')) return;
        event.preventDefault();
        activePointer = event.pointerId;
        dragStartX = event.clientX - panX;
        dragStartY = event.clientY - panY;
        event.currentTarget.classList.add('is-dragging');
        if (event.currentTarget.setPointerCapture) event.currentTarget.setPointerCapture(event.pointerId);
    }

    function moveDrag(event) {
        if (activePointer !== event.pointerId) return;
        event.preventDefault();
        panX = event.clientX - dragStartX;
        panY = event.clientY - dragStartY;
        updateTransform();
    }

    function endDrag(event) {
        if (activePointer !== event.pointerId) return;
        activePointer = null;
        event.currentTarget.classList.remove('is-dragging');
        if (event.currentTarget.hasPointerCapture && event.currentTarget.hasPointerCapture(event.pointerId)) {
            event.currentTarget.releasePointerCapture(event.pointerId);
        }
    }

    function bindDragging() {
        const stage = document.getElementById('previewImageStage');
        if (!stage || stage.dataset.dragReady === 'true') return;
        stage.dataset.dragReady = 'true';
        stage.addEventListener('pointerdown', startDrag);
        stage.addEventListener('pointermove', moveDrag);
        stage.addEventListener('pointerup', endDrag);
        stage.addEventListener('pointercancel', endDrag);
    }

    function rotateImage(degrees) {
        if (!document.getElementById('previewImage')) return;
        rotation = (rotation + degrees) % 360;
        updateTransform();
    }

    function zoomImage(delta) {
        if (!document.getElementById('previewImage')) return;
        scale = Math.min(maxScale, Math.max(minScale, Number((scale + delta).toFixed(2))));
        updateTransform();
    }

    function closeModal() {
        const modal = document.getElementById('previewModal');
        const content = document.getElementById('previewContent');
        if (modal) modal.classList.remove('show');
        if (content) content.innerHTML = 'Memuat preview...';
        setToolbarVisible(false);
        resetTransform();
    }

    function previewDocument(url, extension) {
        const modal = document.getElementById('previewModal');
        const content = document.getElementById('previewContent');
        if (!modal || !content) return;

        const ext = String(extension || '').toLowerCase();
        content.innerHTML = '<p class="preview-loading">Memuat preview dokumen...</p>';
        setToolbarVisible(false);
        resetTransform();

        if (['jpg', 'jpeg', 'jfif', 'jpe', 'png', 'gif'].includes(ext)) {
            setToolbarVisible(true);
            content.innerHTML = `<div class="preview-image-stage" id="previewImageStage" title="Tahan klik kiri lalu geser untuk memindahkan gambar"><img id="previewImage" src="${url}" alt="Preview dokumen" draggable="false" onerror="this.parentElement.innerHTML='<div style=&quot;padding:30px;text-align:center;color:#dc2626;font-weight:600;&quot;>⚠️ File gambar tidak ditemukan atau gagal dimuat dari server.</div>';"></div>`;
            bindDragging();
            requestAnimationFrame(updateTransform);
        } else if (ext === 'pdf') {
            // Use the operator's current session; private documents cannot be read by an external viewer.
            const frame = document.createElement('iframe');
            frame.src = new URL(url, window.location.href).href;
            frame.title = 'Preview dokumen PDF';
            frame.setAttribute('frameborder', '0');
            const link = document.createElement('a');
            link.href = frame.src;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = 'PDF tidak tampil? Buka dokumen di tab baru';
            content.replaceChildren(link, frame);
        } else {
            window.open(url, '_blank');
            return;
        }

        modal.classList.add('show');
    }

    function setup() {
        const modal = document.getElementById('previewModal');
        if (!modal || modal.dataset.sharedPreviewReady === 'true') return;
        modal.dataset.sharedPreviewReady = 'true';
        setToolbarVisible(false);

        const closeButton = modal.querySelector('.modal-header button');
        if (closeButton) closeButton.addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
    }

    window.previewDokumen = previewDocument;
    window.closePreviewModal = closeModal;
    window.rotatePreviewImage = rotateImage;
    window.zoomPreviewImage = zoomImage;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
