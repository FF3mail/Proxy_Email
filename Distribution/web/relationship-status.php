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

$tab = normalizePanelTab(isset($_GET['tab']) ? (string)$_GET['tab'] : PANEL_TAB_PASSAGE);
$limit = normalizePanelPassageJournalLimit((int)($_GET['limit'] ?? PANEL_PASSAGE_JOURNAL_LIMIT_DEFAULT));

$page = buildRelationshipStatusPageData(getPdo(), [
    'limit' => $limit,
    'tab' => $tab,
    'passage_referent' => $_GET['passage_referent'] ?? '',
    'passage_client' => $_GET['passage_client'] ?? '',
    'passage_date_from' => $_GET['passage_date_from'] ?? '',
    'passage_date_to' => $_GET['passage_date_to'] ?? '',
    'passage_direction' => $_GET['passage_direction'] ?? '',
    'ns_referent' => $_GET['ns_referent'] ?? '',
    'ns_client' => $_GET['ns_client'] ?? '',
    'ns_date_from' => $_GET['ns_date_from'] ?? '',
    'ns_date_to' => $_GET['ns_date_to'] ?? '',
    'ns_event' => $_GET['ns_event'] ?? ($_GET['event_filter'] ?? PANEL_EVENT_FILTER_ALL),
    'ns_direction' => $_GET['ns_direction'] ?? '',
]);
$lang = currentPanelLang();
$isPassage = $page['tab'] === PANEL_TAB_PASSAGE;
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
    .tabs { display: flex; gap: 0; margin-bottom: 16px; border-bottom: 2px solid #e2e8f0; }
    .tabs a {
        display: inline-block; padding: 10px 18px; text-decoration: none; color: #64748b;
        border: 1px solid transparent; border-bottom: none; margin-bottom: -2px; border-radius: 6px 6px 0 0;
    }
    .tabs a:hover { color: #1e293b; background: #f8fafc; }
    .tabs a.active {
        color: #0f172a; font-weight: 600; background: #fff;
        border-color: #e2e8f0 #e2e8f0 #fff;
    }
    .global-filter { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; margin-bottom: 14px; }
    .global-filter label, .col-filters label { display: block; margin-bottom: 4px; font-size: 12px; color: #64748b; }
    .global-filter .field, .col-filters .field { display: inline-block; }
    .global-filter select, .global-filter input,
    .col-filters select, .col-filters input { padding: 4px 8px; min-width: 9rem; }
    .col-filters { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 10px; margin-bottom: 12px; }
    .col-filters legend { font-size: 12px; font-weight: 600; color: #64748b; padding: 0 4px; }
    .col-filters .row { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
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

    <div class="tabs" role="tablist" id="journal-tabs">
        <a role="tab"
           class="<?= $isPassage ? 'active' : '' ?>"
           aria-selected="<?= $isPassage ? 'true' : 'false' ?>"
           href="/relationship-status.php?tab=passage&amp;lang=<?= h(urlencode($lang)) ?>&amp;limit=<?= (int)$page['limit'] ?>"
           id="tab-passage"><?= h(__('observability.tab_passage')) ?></a>
        <a role="tab"
           class="<?= !$isPassage ? 'active' : '' ?>"
           aria-selected="<?= !$isPassage ? 'true' : 'false' ?>"
           href="/relationship-status.php?tab=nonstandard&amp;lang=<?= h(urlencode($lang)) ?>&amp;limit=<?= (int)$page['limit'] ?>"
           id="tab-nonstandard"><?= h(__('observability.tab_nonstandard')) ?></a>
    </div>

<?php if ($isPassage): ?>
    <div class="card" id="passage-card">
        <h2><?= h(__('observability.passage_title')) ?></h2>
        <form method="get" action="/relationship-status.php" id="passage-form">
            <input type="hidden" name="tab" value="passage">
            <input type="hidden" name="lang" value="<?= h($lang) ?>">

            <div class="global-filter" id="passage-global-filter">
                <div class="field">
                    <label for="limit"><?= h(__('observability.limit_label')) ?></label>
                    <input id="limit" type="number" name="limit" min="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MIN ?>"
                           max="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MAX ?>" value="<?= (int)$page['limit'] ?>">
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
            </div>

            <fieldset class="col-filters" id="passage-column-filters">
                <legend><?= h(__('observability.column_filters_legend')) ?></legend>
                <div class="row">
                    <div class="field">
                        <label for="passage_date_from"><?= h(__('observability.filter_date_from')) ?></label>
                        <input id="passage_date_from" type="date" name="passage_date_from"
                               value="<?= h((string)$page['passage_date_from']) ?>">
                    </div>
                    <div class="field">
                        <label for="passage_date_to"><?= h(__('observability.filter_date_to')) ?></label>
                        <input id="passage_date_to" type="date" name="passage_date_to"
                               value="<?= h((string)$page['passage_date_to']) ?>">
                    </div>
                    <div class="field">
                        <label for="passage_direction"><?= h(__('observability.filter_direction_label')) ?></label>
                        <select id="passage_direction" name="passage_direction">
                            <option value=""><?= h(__('observability.filter_direction_all')) ?></option>
                            <option value="inbound"<?= $page['passage_direction'] === 'inbound' ? ' selected' : '' ?>>
                                <?= h(__('observability.direction.inbound')) ?>
                            </option>
                            <option value="outbound"<?= $page['passage_direction'] === 'outbound' ? ' selected' : '' ?>>
                                <?= h(__('observability.direction.outbound')) ?>
                            </option>
                        </select>
                    </div>
                    <button type="submit"><?= h(__('observability.refresh')) ?></button>
                </div>
            </fieldset>
        </form>

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
                        data-client="<?= h((string)($row['client_name'] ?? '')) ?>"
                        data-direction="<?= h((string)($row['direction'] ?? '')) ?>"
                        data-event-ts="<?= h((string)($row['event_ts'] ?? '')) ?>">
                        <td class="mono"><?= h((string)($row['event_ts'] ?? '')) ?></td>
                        <td><?= h(passageDirectionLabel((string)($row['direction'] ?? ''))) ?></td>
                        <td><?= h($page['passage_lines'][$i] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card" id="nonstandard-card">
        <h2><?= h(__('observability.nonstandard_title')) ?></h2>
        <form method="get" action="/relationship-status.php" id="nonstandard-form">
            <input type="hidden" name="tab" value="nonstandard">
            <input type="hidden" name="lang" value="<?= h($lang) ?>">

            <div class="global-filter" id="ns-global-filter">
                <div class="field">
                    <label for="ns_limit"><?= h(__('observability.limit_label')) ?></label>
                    <input id="ns_limit" type="number" name="limit" min="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MIN ?>"
                           max="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MAX ?>" value="<?= (int)$page['limit'] ?>">
                </div>
                <div class="field">
                    <label for="ns_referent"><?= h(__('observability.filter_referent_label')) ?></label>
                    <select id="ns_referent" name="ns_referent">
                        <option value=""><?= h(__('observability.filter_all_referents')) ?></option>
                        <?php foreach ($page['referent_options'] as $refName): ?>
                            <option value="<?= h($refName) ?>"<?= $page['ns_referent'] === $refName ? ' selected' : '' ?>>
                                <?= h($refName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="ns_client"><?= h(__('observability.filter_client_label')) ?></label>
                    <input id="ns_client" type="text" name="ns_client"
                           value="<?= h((string)$page['ns_client']) ?>"
                           placeholder="<?= h(__('observability.filter_client_placeholder')) ?>">
                </div>
            </div>

            <fieldset class="col-filters" id="ns-column-filters">
                <legend><?= h(__('observability.column_filters_legend')) ?></legend>
                <div class="row">
                    <div class="field">
                        <label for="ns_date_from"><?= h(__('observability.filter_date_from')) ?></label>
                        <input id="ns_date_from" type="date" name="ns_date_from"
                               value="<?= h((string)$page['ns_date_from']) ?>">
                    </div>
                    <div class="field">
                        <label for="ns_date_to"><?= h(__('observability.filter_date_to')) ?></label>
                        <input id="ns_date_to" type="date" name="ns_date_to"
                               value="<?= h((string)$page['ns_date_to']) ?>">
                    </div>
                    <div class="field">
                        <label for="ns_event"><?= h(__('observability.filter_label')) ?></label>
                        <select id="ns_event" name="ns_event">
                            <option value="<?= h(PANEL_EVENT_FILTER_ALL) ?>"<?= $page['ns_event'] === PANEL_EVENT_FILTER_ALL ? ' selected' : '' ?>>
                                <?= h(__('observability.filter_all')) ?>
                            </option>
                            <option value="<?= h(PANEL_EVENT_FILTER_SKIPPED) ?>"<?= $page['ns_event'] === PANEL_EVENT_FILTER_SKIPPED ? ' selected' : '' ?>>
                                <?= h(__('observability.filter_skipped')) ?>
                            </option>
                            <option value="<?= h(PANEL_EVENT_FILTER_DISPOSED) ?>"<?= $page['ns_event'] === PANEL_EVENT_FILTER_DISPOSED ? ' selected' : '' ?>>
                                <?= h(__('observability.filter_disposed')) ?>
                            </option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="ns_direction"><?= h(__('observability.filter_direction_label')) ?></label>
                        <select id="ns_direction" name="ns_direction">
                            <option value=""><?= h(__('observability.filter_direction_all')) ?></option>
                            <option value="inbound"<?= $page['ns_direction'] === 'inbound' ? ' selected' : '' ?>>
                                <?= h(__('observability.direction.inbound')) ?>
                            </option>
                            <option value="outbound"<?= $page['ns_direction'] === 'outbound' ? ' selected' : '' ?>>
                                <?= h(__('observability.direction.outbound')) ?>
                            </option>
                        </select>
                    </div>
                    <button type="submit"><?= h(__('observability.refresh')) ?></button>
                </div>
            </fieldset>
        </form>

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
                        data-client="<?= h((string)($row['client_name'] ?? '')) ?>"
                        data-direction="<?= h((string)($row['direction'] ?? '')) ?>"
                        data-event-ts="<?= h((string)($row['event_ts'] ?? '')) ?>">
                        <td class="mono"><?= h((string)($row['event_ts'] ?? '')) ?></td>
                        <td><?= h((string)($row['event_label'] ?? '')) ?></td>
                        <td><?= h((string)($row['direction_label'] ?? '')) ?></td>
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
<?php endif; ?>
</div>
</body>
</html>
