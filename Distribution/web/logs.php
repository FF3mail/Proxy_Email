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

$source = (string)($_GET['source'] ?? 'daemon');
if (!isset(PANEL_ALLOWED_LOGS[$source])) {
    $source = 'daemon';
}

$lines = normalizePanelLogLines((int)($_GET['lines'] ?? PANEL_LOG_LINES_DEFAULT));
$result = readPanelLogTail($source, $lines);

$sourceLabel = match ($source) {
    'daemon' => 'Демон (mail-proxy-daemon.log)',
    'web' => 'Веб-панель (web_admin.log)',
    default => $source,
};
?>
<!DOCTYPE html>
<html lang="<?= h(panelHtmlLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Просмотр логов — DELTA-транзит</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
      font-size: 14px; line-height: 1.45;
      background: var(--pm-bg, #f1f5f9); color: var(--pm-text, #0f172a);
    }
    h1 { font-size: 22px; margin: 0 0 14px; font-weight: 700; color: var(--pm-text, #0f172a); }
    .controls {
      background: var(--pm-surface, #fff); padding: 16px; border-radius: 8px;
      border: 1px solid var(--pm-border, #e2e8f0); margin-bottom: 16px;
      display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
    }
    .controls label { font-size: 13px; color: var(--pm-muted, #64748b); font-weight: 600; }
    .controls select, .controls input {
      padding: 8px 10px; border: 1px solid var(--pm-border, #e2e8f0);
      border-radius: 6px; font: inherit; color: inherit; background: #fff; margin-left: 6px;
    }
    .btn {
      display: inline-block; padding: 7px 14px; background: var(--pm-accent, #3f6d9e);
      color: #fff; border: none; border-radius: 6px; cursor: pointer;
      text-decoration: none; font-size: 14px;
    }
    .log-box {
      background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 8px;
      font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px;
      max-height: 70vh; overflow: auto; white-space: pre-wrap; word-break: break-word;
      border: 1px solid var(--pm-border, #e2e8f0);
    }
    .error {
      background: var(--pm-warn-bg, #f7edd6); border: 1px solid #e8d7a8;
      padding: 12px; border-radius: 6px; color: var(--pm-warn, #9a6b12); margin-bottom: 16px;
    }
    .meta { color: var(--pm-muted, #64748b); font-size: 13px; margin-bottom: 12px; }
</style>
<link rel="stylesheet" href="/assets/panel-modal.css">
</head>
<body>
<div class="app-shell">
<?php renderPanelSidebar(); ?>
<main class="app-main">
<div class="container" style="max-width:none;margin:0;padding:0">
    <h1>Просмотр логов</h1>

    <form method="get" action="/logs.php" class="controls">
        <label>Источник
            <select name="source">
                <option value="daemon" <?= $source === 'daemon' ? 'selected' : '' ?>>Демон</option>
                <option value="web" <?= $source === 'web' ? 'selected' : '' ?>>Веб-панель</option>
            </select>
        </label>
        <label>Строк (<?= PANEL_LOG_LINES_MIN ?>–<?= PANEL_LOG_LINES_MAX ?>)
            <input type="number" name="lines" value="<?= $lines ?>" min="<?= PANEL_LOG_LINES_MIN ?>" max="<?= PANEL_LOG_LINES_MAX ?>">
        </label>
        <button type="submit" class="btn">Обновить</button>
        <a href="/logs.php?source=<?= h($source) ?>&lines=<?= $lines ?>" class="btn">↻ Перезагрузить</a>
    </form>

    <p class="meta">
        <?= h($sourceLabel) ?> · <?= h($result['path']) ?> · показано до <?= $lines ?> строк (новые сверху)
    </p>

    <?php if (!$result['readable']): ?>
        <div class="error"><?= h($result['error']) ?></div>
    <?php elseif ($result['lines'] === []): ?>
        <div class="log-box">(пусто)</div>
    <?php else: ?>
        <div class="log-box"><?php
            foreach ($result['lines'] as $line) {
                echo h($line) . "\n";
            }
        ?></div>
    <?php endif; ?>
</div>
</main>
</div>
</body>
</html>
