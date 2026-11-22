<?php

if (!function_exists('render_user_header_brand')) {
    /**
     * Render the shared LINE identity used in general-user page headers.
     */
    function render_user_header_brand(string $title, ?string $subtitle = null): void
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeSubtitle = $subtitle !== null
            ? htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8')
            : null;
        ?>
        <div class="left-header user-header-brand">
            <img
                class="user-header-brand__logo"
                src="../assets/line.png"
                alt=""
                width="32"
                height="32"
                aria-hidden="true"
            >
            <div class="user-header-brand__copy">
                <div class="title"><?= $safeTitle ?></div>
                <?php if ($safeSubtitle !== null && $safeSubtitle !== ''): ?>
                    <div class="subtitle"><?= $safeSubtitle ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
