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
require_once __DIR__ . '/includes/panel_modals.php';
require_once __DIR__ . '/includes/panel_nav.php';

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
<title><?= h(__('observability.title')) ?> — <?= h(__('app.title_suffix')) ?></title>
<?php require_once __DIR__ . '/includes/panel_brand.php'; renderPanelFaviconLinks(); ?>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/panel-modal.css">
</head>
<body class="bg-gray-100 min-h-screen">
<div class="app-shell">
<?php renderPanelSidebar(); ?>
<main class="app-main">
    <div class="pm-head">
        <h1><?= h(__('observability.title')) ?></h1>
    </div>
    <p class="pm-hint"><?= h(__('observability.hint')) ?></p>

    <?php if (!empty($page['error'])): ?>
        <div class="pm-notice-warn" role="alert"><?= h((string)$page['error']) ?></div>
    <?php endif; ?>

    <nav class="pm-tabs" role="tablist" id="journal-tabs">
        <a role="tab"
           class="pm-tab"
           aria-selected="<?= $isPassage ? 'true' : 'false' ?>"
           href="/relationship-status.php?tab=passage&amp;lang=<?= h(urlencode($lang)) ?>&amp;limit=<?= (int)$page['limit'] ?>"
           id="tab-passage"><?= h(__('observability.tab_passage')) ?></a>
        <a role="tab"
           class="pm-tab"
           aria-selected="<?= !$isPassage ? 'true' : 'false' ?>"
           href="/relationship-status.php?tab=nonstandard&amp;lang=<?= h(urlencode($lang)) ?>&amp;limit=<?= (int)$page['limit'] ?>"
           id="tab-nonstandard"><?= h(__('observability.tab_nonstandard')) ?></a>
    </nav>

<?php if ($isPassage): ?>
    <div class="pm-card" id="passage-card">
        <div class="pm-ch"><h3><?= h(__('observability.passage_title')) ?></h3></div>
        <form method="get" action="/relationship-status.php" id="passage-form">
            <input type="hidden" name="tab" value="passage">
            <input type="hidden" name="lang" value="<?= h($lang) ?>">

            <div class="pm-filter-bar" id="passage-global-filter">
                <div class="pm-f">
                    <label for="limit"><?= h(__('observability.limit_label')) ?></label>
                    <input id="limit" type="number" name="limit" min="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MIN ?>"
                           max="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MAX ?>" value="<?= (int)$page['limit'] ?>">
                </div>
                <div class="pm-f">
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
                <div class="pm-f">
                    <label for="passage_client"><?= h(__('observability.filter_client_label')) ?></label>
                    <input id="passage_client" type="text" name="passage_client"
                           value="<?= h((string)$page['passage_client']) ?>"
                           placeholder="<?= h(__('observability.filter_client_placeholder')) ?>">
                </div>
            </div>

            <fieldset class="pm-filter-fieldset" id="passage-column-filters">
                <legend><?= h(__('observability.column_filters_legend')) ?></legend>
                <div class="pm-filter-bar">
                    <div class="pm-f">
                        <label for="passage_date_from"><?= h(__('observability.filter_date_from')) ?></label>
                        <input id="passage_date_from" type="date" name="passage_date_from"
                               value="<?= h((string)$page['passage_date_from']) ?>">
                    </div>
                    <div class="pm-f">
                        <label for="passage_date_to"><?= h(__('observability.filter_date_to')) ?></label>
                        <input id="passage_date_to" type="date" name="passage_date_to"
                               value="<?= h((string)$page['passage_date_to']) ?>">
                    </div>
                    <div class="pm-f">
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
                    <button type="submit" class="pm-btn pm-btn-primary"><?= h(__('observability.refresh')) ?></button>
                </div>
            </fieldset>
        </form>

        <?php if (empty($page['passage_lines'])): ?>
            <div class="pm-empty"><?= h(__('observability.passage_empty')) ?></div>
        <?php else: ?>
            <div class="pm-table-wrap">
                <table class="pm-table" id="passage-table" data-readonly="1">
                    <thead>
                    <tr>
                        <th><?= h(__('observability.col_when')) ?></th>
                        <th><?= h(__('observability.col_direction')) ?></th>
                        <th><?= h(__('observability.col_passage')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($page['passage_rows'] as $i => $row): ?>
                        <tr data-orphan="1"
                            data-referent="<?= h((string)($row['referent_name'] ?? '')) ?>"
                            data-client="<?= h((string)($row['client_name'] ?? '')) ?>"
                            data-direction="<?= h((string)($row['direction'] ?? '')) ?>"
                            data-event-ts="<?= h((string)($row['event_ts'] ?? '')) ?>">
                            <td class="pm-mono"><?= h((string)($row['event_ts'] ?? '')) ?></td>
                            <td><span class="pm-chip pm-chip-off"><?= h(passageDirectionLabel((string)($row['direction'] ?? ''))) ?></span></td>
                            <td class="pm-cell-wrap"><?= h($page['passage_lines'][$i] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="pm-card" id="nonstandard-card">
        <div class="pm-ch"><h3><?= h(__('observability.nonstandard_title')) ?></h3></div>
        <form method="get" action="/relationship-status.php" id="nonstandard-form">
            <input type="hidden" name="tab" value="nonstandard">
            <input type="hidden" name="lang" value="<?= h($lang) ?>">

            <div class="pm-filter-bar" id="ns-global-filter">
                <div class="pm-f">
                    <label for="ns_limit"><?= h(__('observability.limit_label')) ?></label>
                    <input id="ns_limit" type="number" name="limit" min="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MIN ?>"
                           max="<?= (int)PANEL_PASSAGE_JOURNAL_LIMIT_MAX ?>" value="<?= (int)$page['limit'] ?>">
                </div>
                <div class="pm-f">
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
                <div class="pm-f">
                    <label for="ns_client"><?= h(__('observability.filter_client_label')) ?></label>
                    <input id="ns_client" type="text" name="ns_client"
                           value="<?= h((string)$page['ns_client']) ?>"
                           placeholder="<?= h(__('observability.filter_client_placeholder')) ?>">
                </div>
            </div>

            <fieldset class="pm-filter-fieldset" id="ns-column-filters">
                <legend><?= h(__('observability.column_filters_legend')) ?></legend>
                <div class="pm-filter-bar">
                    <div class="pm-f">
                        <label for="ns_date_from"><?= h(__('observability.filter_date_from')) ?></label>
                        <input id="ns_date_from" type="date" name="ns_date_from"
                               value="<?= h((string)$page['ns_date_from']) ?>">
                    </div>
                    <div class="pm-f">
                        <label for="ns_date_to"><?= h(__('observability.filter_date_to')) ?></label>
                        <input id="ns_date_to" type="date" name="ns_date_to"
                               value="<?= h((string)$page['ns_date_to']) ?>">
                    </div>
                    <div class="pm-f">
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
                    <div class="pm-f">
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
                    <button type="submit" class="pm-btn pm-btn-primary"><?= h(__('observability.refresh')) ?></button>
                </div>
            </fieldset>
        </form>

        <?php if (empty($page['nonstandard_rows'])): ?>
            <div class="pm-empty"><?= h(__('observability.nonstandard_empty')) ?></div>
        <?php else: ?>
            <div class="pm-table-wrap">
                <table class="pm-table" id="nonstandard-table" data-readonly="1">
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
                    <?php foreach ($page['nonstandard_rows'] as $row):
                        $eventType = (string)($row['event_type'] ?? '');
                        ?>
                        <tr data-orphan="1"
                            data-event-type="<?= h($eventType) ?>"
                            data-referent="<?= h((string)($row['referent_name'] ?? '')) ?>"
                            data-client="<?= h((string)($row['client_name'] ?? '')) ?>"
                            data-direction="<?= h((string)($row['direction'] ?? '')) ?>"
                            data-event-ts="<?= h((string)($row['event_ts'] ?? '')) ?>">
                            <td class="pm-mono"><?= h((string)($row['event_ts'] ?? '')) ?></td>
                            <td>
                                <span class="pm-chip <?= h(passageEventTypeChipClass($eventType)) ?>">
                                    <?= h((string)($row['event_label'] ?? '')) ?>
                                </span>
                            </td>
                            <td><span class="pm-chip pm-chip-off"><?= h((string)($row['direction_label'] ?? '')) ?></span></td>
                            <td><?= h((string)($row['reason_label'] ?? '')) ?></td>
                            <td class="pm-mono pm-cell-wrap" title="<?= h((string)($row['detail'] ?? '')) ?>"><?= h((string)($row['detail'] ?? '')) ?></td>
                            <td><?= h((string)($row['notified_label'] ?? '')) ?></td>
                            <td class="pm-cell-wrap">
                                <?= h((string)($row['referent_name'] ?? '—')) ?>
                                /
                                <?= h((string)($row['client_name'] ?? '—')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</main>
</div>
</body>
</html>
