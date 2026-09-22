<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
checkLocalNetworkAccess();
startPanelSession();

require_once __DIR__ . '/includes/i18n.php';
initPanelI18n();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/panel_migration.php';
require_once __DIR__ . '/includes/relationship_status.php';

sanitizeLegacyPanelSession();
bootstrapPanelAuth();
requirePanelAdmin();

$tailLines = normalizePanelRelationshipStatusLogLines(
    (int)($_GET['lines'] ?? PANEL_RELATIONSHIP_STATUS_LOG_LINES_DEFAULT)
);
buildRelationshipStatusPageData(getPdo(), $tailLines);
?>
<!DOCTYPE html>
<html lang="<?= h(panelHtmlLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(__('observability.title')) ?> — DELTA-transit</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', Tahoma, sans-serif; font-size: 14px; background: #f4f6f8; color: #333; }
    .nav { background: #2c3e50; padding: 10px 20px; display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
    .nav a { color: #ecf0f1; text-decoration: none; }
    .nav a:hover, .nav a.active { color: #3498db; font-weight: 600; }
    .container { max-width: 900px; margin: 0 auto; padding: 20px; }
    h1 { font-size: 22px; margin-bottom: 12px; }
    .stub { background: #fff; border-radius: 6px; padding: 20px; border: 1px solid #e2e8f0; }
    .stub p { margin-bottom: 8px; line-height: 1.5; color: #475569; }
</style>
</head>
<body>
<nav class="nav">
    <a href="/index.php"><?= h(__('nav.control_panel')) ?></a>
    <a href="/monitor.php"><?= h(__('nav.monitor')) ?></a>
    <a href="/relationship-status.php" class="active"><?= h(__('nav.relationship_status')) ?></a>
    <a href="/logs.php">Логи</a>
</nav>
<div class="container">
    <h1><?= h(__('observability.title')) ?></h1>
    <div class="stub">
        <p><strong><?= h(__('observability.stub_title')) ?></strong></p>
        <p><?= h(__('observability.stub_body')) ?></p>
    </div>
</div>
</body>
</html>
