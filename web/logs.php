<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
checkLocalNetworkAccess();
startPanelSession();

require_once __DIR__ . '/includes/i18n.php';
initPanelI18n();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/panel_migration.php';
require_once __DIR__ . '/includes/log_viewer.php';
require_once __DIR__ . '/includes/panel_modals.php';
require_once __DIR__ . '/includes/panel_nav.php';

sanitizeLegacyPanelSession();
bootstrapPanelAuth();
requirePanelAdmin();

/**
 * Highlight common log level tokens with shared panel chips (markup only).
 */
function formatPanelLogLineHtml(string $line): string
{
    $escaped = h($line);
    $patterns = [
        '/\b(ERROR|CRITICAL|FATAL)\b/i' => '<span class="pm-chip pm-chip-bad">$1</span>',
        '/\b(WARN|WARNING)\b/i' => '<span class="pm-chip pm-chip-warn">$1</span>',
        '/\b(INFO)\b/i' => '<span class="pm-chip pm-chip-ok">$1</span>',
        '/\b(DEBUG|TRACE)\b/i' => '<span class="pm-chip pm-chip-off">$1</span>',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $escaped = (string) preg_replace($pattern, $replacement, $escaped);
    }
    return $escaped;
}

$source = (string)($_GET['source'] ?? 'daemon');
if (!isset(PANEL_ALLOWED_LOGS[$source])) {
    $source = 'daemon';
}

$lines = normalizePanelLogLines((int)($_GET['lines'] ?? PANEL_LOG_LINES_DEFAULT));
$result = readPanelLogTail($source, $lines);

$sourceLabel = match ($source) {
    'daemon' => __('logs.source_daemon_long'),
    'web' => __('logs.source_web_long'),
    default => $source,
};
$meta = __('logs.meta', [
    'source' => $sourceLabel,
    'path' => $result['path'] !== '' ? $result['path'] : '—',
    'lines' => (string) $lines,
]);
$linesLabel = __('logs.lines_range', [
    'min' => (string) PANEL_LOG_LINES_MIN,
    'max' => (string) PANEL_LOG_LINES_MAX,
]);
?>
<!DOCTYPE html>
<html lang="<?= h(panelHtmlLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(__('logs.title')) ?> — <?= h(__('app.title_suffix')) ?></title>
<?php require_once __DIR__ . '/includes/panel_brand.php'; renderPanelFaviconLinks(); ?>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/panel-modal.css">
</head>
<body class="bg-gray-100 min-h-screen">
<div class="app-shell">
<?php renderPanelSidebar(); ?>
<main class="app-main">
    <div class="pm-head">
        <h1><?= h(__('logs.title')) ?></h1>
    </div>

    <div class="pm-card">
        <form method="get" action="/logs.php" class="pm-filter-bar">
            <div class="pm-f">
                <label for="log-source"><?= h(__('logs.source_label')) ?></label>
                <select id="log-source" name="source">
                    <option value="daemon" <?= $source === 'daemon' ? 'selected' : '' ?>><?= h(__('logs.source_daemon')) ?></option>
                    <option value="web" <?= $source === 'web' ? 'selected' : '' ?>><?= h(__('logs.source_web')) ?></option>
                </select>
            </div>
            <div class="pm-f">
                <label for="log-lines"><?= h($linesLabel) ?></label>
                <input id="log-lines" type="number" name="lines" value="<?= (int) $lines ?>"
                       min="<?= PANEL_LOG_LINES_MIN ?>" max="<?= PANEL_LOG_LINES_MAX ?>">
            </div>
            <button type="submit" class="pm-btn pm-btn-primary"><?= h(__('logs.refresh')) ?></button>
            <a href="/logs.php?source=<?= h(urlencode($source)) ?>&amp;lines=<?= (int) $lines ?>"
               class="pm-btn"><?= h(__('logs.reload')) ?></a>
        </form>

        <p class="pm-log-meta"><?= h($meta) ?></p>

        <?php if (!$result['readable']): ?>
            <div class="pm-notice-warn" role="alert"><?= h($result['error']) ?></div>
        <?php elseif ($result['lines'] === []): ?>
            <div class="pm-log-view"><?= h(__('logs.empty')) ?></div>
        <?php else: ?>
            <div class="pm-log-view"><?php
                foreach ($result['lines'] as $line) {
                    echo '<span class="pm-log-line">' . formatPanelLogLineHtml($line) . "</span>\n";
                }
            ?></div>
        <?php endif; ?>
    </div>
</main>
</div>
</body>
</html>
