<?php

if (!function_exists('render_petugas_desa_header_brand')) {
    function render_petugas_desa_header_brand(string $title, ?string $subtitle = null): void
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeSubtitle = $subtitle !== null
            ? htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8')
            : null;
        ?>
        <div class="left-header petugas-header-brand">
            <img
                class="petugas-header-brand__logo"
                src="../assets/add.png"
                alt=""
                width="34"
                height="34"
                aria-hidden="true"
            >
            <div class="petugas-header-brand__copy">
                <div class="title"><?= $safeTitle ?></div>
                <?php if ($safeSubtitle !== null && $safeSubtitle !== ''): ?>
                    <div class="subtitle"><?= $safeSubtitle ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
