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
    body { font-family: 'Segoe UI', Tahoma, sans-serif; font-size: 14px; background: #f4f6f8; color: #333; }
    .nav { background: #2c3e50; padding: 10px 20px; display: flex; gap: 20px; align-items: center; }
    .nav a { color: #ecf0f1; text-decoration: none; }
    .nav a:hover { color: #3498db; }
    .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    h1 { font-size: 22px; margin-bottom: 16px; }
    .controls { background: #fff; padding: 16px; border-radius: 6px; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    .controls label { font-size: 13px; }
    .controls select, .controls input { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; }
    .btn { display: inline-block; padding: 6px 14px; background: #3498db; color: #fff; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 13px; }
    .log-box { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 6px; font-family: Consolas, monospace; font-size: 12px; max-height: 70vh; overflow: auto; white-space: pre-wrap; word-break: break-word; }
    .error { background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 4px; color: #856404; margin-bottom: 16px; }
    .meta { color: #666; font-size: 12px; margin-bottom: 12px; }
</style>
</head>
<body>
<nav class="nav">
    <a href="/index.php">Панель управления</a>
    <a href="/monitor.php">Мониторинг</a>
    <a href="/logs.php" style="color:#3498db;font-weight:600;">Логи</a>
</nav>
<div class="container">
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
</body>
</html>
