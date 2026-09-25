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

$limit = normalizePanelPassageJournalLimit((int)($_GET['limit'] ?? PANEL_PASSAGE_JOURNAL_LIMIT_DEFAULT));
$eventFilter = normalizePanelEventFilter(
    isset($_GET['event_filter']) ? (string)$_GET['event_filter'] : PANEL_EVENT_FILTER_ALL
);
$passageReferent = normalizePassageReferentFilter(
    isset($_GET['passage_referent']) ? (string)$_GET['passage_referent'] : ''
);
$passageClient = normalizePassageClientFilter(
    isset($_GET['passage_client']) ? (string)$_GET['passage_client'] : ''
);
$page = buildRelationshipStatusPageData(
    getPdo(),
    $limit,
    $eventFilter,
    $passageReferent,
    $passageClient
);
$lang = currentPanelLang();
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
    .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
    h1 { font-size: 22px; margin-bottom: 8px; }
    h2 { font-size: 16px; margin: 0 0 10px; }
    .hint { color: #64748b; margin-bottom: 16px; line-height: 1.45; }
    .card { background: #fff; border-radius: 6px; padding: 16px; border: 1px solid #e2e8f0; margin-bottom: 16px; }
    .error { color: #b91c1c; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    th { background: #f8fafc; font-weight: 600; font-size: 12px; text-transform: uppercase; color: #64748b; }
    .mono { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: #475569; }
    .empty { color: #94a3b8; padding: 12px 0; }
    .card-toolbar { margin: 0 0 12px; display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
    .card-toolbar label { display: block; margin-bottom: 4px; font-size: 12px; color: #64748b; }
    .card-toolbar .field { display: inline-block; }
    .card-toolbar select, .card-toolbar input[type=number], .card-toolbar input[type=text] { padding: 4px 8px; min-width: 10rem; }
    .card-toolbar .note { font-size: 11px; color: #94a3b8; max-width: 18rem; line-height: 1.3; }
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
    <p class="hint"><?= h(__('observability.hint')) ?></p>

    <?php if (!empty($page['error'])): ?>
        <div class="card error"><?= h((string)$page['error']) ?></div>
    <?php endif; ?>

    <div class="card" id="passage-card">
        <h2><?= h(__('observability.passage_title')) ?></h2>
        <div class="card-toolbar" id="passage-toolbar">
            <form method="get" action="/relationship-status.php">
                <input type="hidden" name="lang" value="<?= h($lang) ?>">
                <input type="hidden" name="event_filter" value="<?= h((string)$page['event_filter']) ?>">
                <div class="field">
                    <label for="limit"><?= h(__('observability.limit_label')) ?></label>
                    <input id="limit" type="number" name="limit" min="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MIN ?>"
                           max="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MAX ?>" value="<?= (int)$page['limit'] ?>">
                    <div class="note"><?= h(__('observability.limit_shared_note')) ?></div>
                </div>
                <div class="field">
                    <label for="passage_referent"><?= h(__('observability.filter_referent_label')) ?></label>
                    <select id="passage_referent" name="passage_referent">
                        <option value=""><?= h(__('observability.filter_all_referents')) ?></option>
                        <?php foreach ($page['referent_options'] as $refName): ?>
                            <option value="<?= h($refName) ?>"<?= $page['passage_referent'] === $refName ? ' selected' : '' ?>>
                                <?= h($refName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="passage_client"><?= h(__('observability.filter_client_label')) ?></label>
                    <input id="passage_client" type="text" name="passage_client"
                           value="<?= h((string)$page['passage_client']) ?>"
                           placeholder="<?= h(__('observability.filter_client_placeholder')) ?>">
                </div>
                <button type="submit"><?= h(__('observability.refresh')) ?></button>
            </form>
        </div>
        <?php if (empty($page['passage_lines'])): ?>
            <p class="empty"><?= h(__('observability.passage_empty')) ?></p>
        <?php else: ?>
            <table id="passage-table">
                <thead>
                <tr>
                    <th><?= h(__('observability.col_when')) ?></th>
                    <th><?= h(__('observability.col_direction')) ?></th>
                    <th><?= h(__('observability.col_passage')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($page['passage_rows'] as $i => $row): ?>
                    <tr data-referent="<?= h((string)($row['referent_name'] ?? '')) ?>"
                        data-client="<?= h((string)($row['client_name'] ?? '')) ?>">
                        <td class="mono"><?= h((string)($row['event_ts'] ?? '')) ?></td>
                        <td><?= h(passageDirectionLabel((string)($row['direction'] ?? ''))) ?></td>
                        <td><?= h($page['passage_lines'][$i] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card" id="nonstandard-card">
        <h2><?= h(__('observability.nonstandard_title')) ?></h2>
        <div class="card-toolbar" id="nonstandard-toolbar">
            <form method="get" action="/relationship-status.php" id="nonstandard-filter-form">
                <input type="hidden" name="lang" value="<?= h($lang) ?>">
                <input type="hidden" name="limit" value="<?= (int)$page['limit'] ?>">
                <div class="field">
                    <label for="event_filter"><?= h(__('observability.filter_label')) ?></label>
                    <select id="event_filter" name="event_filter">
                        <option value="<?= h(PANEL_EVENT_FILTER_ALL) ?>"<?= $page['event_filter'] === PANEL_EVENT_FILTER_ALL ? ' selected' : '' ?>>
                            <?= h(__('observability.filter_all')) ?>
                        </option>
                        <option value="<?= h(PANEL_EVENT_FILTER_SKIPPED) ?>"<?= $page['event_filter'] === PANEL_EVENT_FILTER_SKIPPED ? ' selected' : '' ?>>
                            <?= h(__('observability.filter_skipped')) ?>
                        </option>
                        <option value="<?= h(PANEL_EVENT_FILTER_DISPOSED) ?>"<?= $page['event_filter'] === PANEL_EVENT_FILTER_DISPOSED ? ' selected' : '' ?>>
                            <?= h(__('observability.filter_disposed')) ?>
                        </option>
                    </select>
                </div>
                <div class="field">
                    <label for="ns_passage_referent"><?= h(__('observability.filter_referent_label')) ?></label>
                    <select id="ns_passage_referent" name="passage_referent">
                        <option value=""><?= h(__('observability.filter_all_referents')) ?></option>
                        <?php foreach ($page['referent_options'] as $refName): ?>
                            <option value="<?= h($refName) ?>"<?= $page['passage_referent'] === $refName ? ' selected' : '' ?>>
                                <?= h($refName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="ns_passage_client"><?= h(__('observability.filter_client_label')) ?></label>
                    <input id="ns_passage_client" type="text" name="passage_client"
                           value="<?= h((string)$page['passage_client']) ?>"
                           placeholder="<?= h(__('observability.filter_client_placeholder')) ?>">
                </div>
                <button type="submit"><?= h(__('observability.refresh')) ?></button>
            </form>
        </div>
        <?php if (empty($page['nonstandard_rows'])): ?>
            <p class="empty"><?= h(__('observability.nonstandard_empty')) ?></p>
        <?php else: ?>
            <table id="nonstandard-table">
                <thead>
                <tr>
                    <th><?= h(__('observability.col_when')) ?></th>
                    <th><?= h(__('observability.col_event')) ?></th>
                    <th><?= h(__('observability.col_direction')) ?></th>
                    <th><?= h(__('observability.col_reason')) ?></th>
                    <th><?= h(__('observability.col_detail')) ?></th>
                    <th><?= h(__('observability.col_notified')) ?></th>
                    <th><?= h(__('observability.col_parties')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($page['nonstandard_rows'] as $row): ?>
                    <tr data-event-type="<?= h((string)($row['event_type'] ?? '')) ?>"
                        data-referent="<?= h((string)($row['referent_name'] ?? '')) ?>"
                        data-client="<?= h((string)($row['client_name'] ?? '')) ?>">
                        <td class="mono"><?= h((string)($row['event_ts'] ?? '')) ?></td>
                        <td><?= h((string)($row['event_label'] ?? passageEventTypeLabel((string)($row['event_type'] ?? '')))) ?></td>
                        <td><?= h((string)($row['direction_label'] ?? passageDirectionLabel((string)($row['direction'] ?? '')))) ?></td>
                        <td><?= h((string)($row['reason_label'] ?? '')) ?></td>
                        <td class="mono"><?= h((string)($row['detail'] ?? '')) ?></td>
                        <td><?= h((string)($row['notified_label'] ?? '')) ?></td>
                        <td>
                            <?= h((string)($row['referent_name'] ?? '—')) ?>
                            /
                            <?= h((string)($row['client_name'] ?? '—')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
