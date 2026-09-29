<?php
declare(strict_types=1);

function panelBrandUrl(string $filename): string
{
    return '/assets/brand/' . ltrim($filename, '/');
}

function renderPanelFaviconLinks(): void
{
    ?>
    <link rel="icon" href="<?= h(panelBrandUrl('favicon.ico')) ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= h(panelBrandUrl('favicon-32.png')) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= h(panelBrandUrl('apple-touch-icon.png')) ?>">
    <?php
}
