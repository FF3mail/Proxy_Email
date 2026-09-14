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
$data = buildRelationshipStatusPageData(getPdo(), $tailLines);
$obs = $data['observability'];
$stats = $data['shadow_stats'];
$log = $data['log'];

$self = basename($_SERVER['SCRIPT_NAME'] ?? 'relationship-status.php');
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
    .container { max-width: 1500px; margin: 0 auto; padding: 20px; }
    h1 { font-size: 22px; margin-bottom: 8px; }
    .hint { color: #666; font-size: 13px; margin-bottom: 16px; max-width: 900px; }
    .controls { background: #fff; padding: 16px; border-radius: 6px; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    .btn { display: inline-block; padding: 6px 14px; background: #3498db; color: #fff; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 13px; }
    .card { background: #fff; border-radius: 6px; padding: 16px; margin-bottom: 16px; }
    .card h2 { font-size: 16px; margin-bottom: 10px; }
    .modes-line { font-family: Consolas, monospace; font-size: 12px; background: #f8f9fa; padding: 10px; border-radius: 4px; word-break: break-word; }
    .stats-grid { display: flex; flex-wrap: wrap; gap: 12px; }
    .stat-pill { background: #eef2f7; padding: 8px 12px; border-radius: 4px; font-size: 13px; }
    .stat-pill strong { display: block; font-size: 11px; color: #666; text-transform: uppercase; }
    .warn { background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: 10px 12px; border-radius: 4px; margin-bottom: 12px; font-size: 13px; }
    .meta { color: #666; font-size: 12px; margin-bottom: 12px; }
    .referent-block { margin-bottom: 24px; }
    .referent-title { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { border: 1px solid #e2e8f0; padding: 8px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; font-weight: 600; }
    .mono { font-family: Consolas, monospace; font-size: 12px; word-break: break-all; }
    .obs-marker-agree { color: #15803d; font-weight: 600; }
    .obs-marker-diverge { color: #b45309; font-weight: 600; }
    .obs-marker-error { color: #b91c1c; font-weight: 600; }
    .badge-warn { display: inline-block; background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin: 2px 0; }
    .badge-valid { color: #15803d; }
    .badge-legacy { color: #64748b; }
    .badge-inactive { color: #475569; }
    .badge-incomplete { color: #b45309; }
    .empty { color: #94a3b8; font-style: italic; }
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

    <form method="get" action="/relationship-status.php" class="controls">
        <label>
            <?= h(__('observability.tail_lines_label', [
                'min' => (string)PANEL_RELATIONSHIP_STATUS_LOG_LINES_MIN,
                'max' => (string)PANEL_RELATIONSHIP_STATUS_LOG_LINES_MAX,
            ])) ?>
            <input type="number" name="lines" value="<?= $tailLines ?>"
                   min="<?= PANEL_RELATIONSHIP_STATUS_LOG_LINES_MIN ?>"
                   max="<?= PANEL_RELATIONSHIP_STATUS_LOG_LINES_MAX ?>">
        </label>
        <button type="submit" class="btn"><?= h(__('observability.refresh')) ?></button>
    </form>

    <?php if (!$log['readable']): ?>
        <div class="warn">
            <?= h(__('observability.log_unavailable', ['error' => $log['error']])) ?>
        </div>
    <?php else: ?>
        <p class="meta">
            <?= h(__('observability.log_tail', [
                'lines' => (string)$tailLines,
                'path' => $log['path'],
            ])) ?>
        </p>
    <?php endif; ?>

    <div class="card">
        <h2><?= h(__('observability.modes_title')) ?></h2>
        <?php if (($obs['startup_modes_line'] ?? null) === null): ?>
            <p class="empty"><?= h(__('observability.modes_missing')) ?></p>
        <?php else: ?>
            <div class="modes-line"><?= h((string)$obs['startup_modes_line']) ?></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2><?= h(__('observability.stats_title')) ?></h2>
        <p class="meta"><?= h(__('observability.stats_note')) ?></p>
        <?php if (!$stats['readable'] || $stats['stats'] === null): ?>
            <p class="empty">
                <?= h(__('observability.stats_unavailable', [
                    'path' => $stats['path'],
                    'error' => $stats['error'],
                ])) ?>
            </p>
        <?php else: ?>
            <div class="stats-grid">
                <?php foreach ($stats['stats'] as $key => $value): ?>
                    <div class="stat-pill">
                        <strong><?= h((string)$key) ?></strong>
                        <?= h((string)$value) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($data['referents'] === []): ?>
        <div class="card empty"><?= h(__('observability.no_referents')) ?></div>
    <?php else: ?>
        <?php foreach ($data['referents'] as $block):
            $referent = $block['referent'];
            $relationships = $block['relationships'];
            ?>
            <div class="card referent-block">
                <div class="referent-title">
                    <?= h((string)$referent['username']) ?>
                    <span class="mono">#<?= (int)$referent['id'] ?></span>
                    <?php if ((int)$referent['active'] !== 1): ?>
                        <span class="badge-warn">referent inactive</span>
                    <?php endif; ?>
                    <?php if (!empty($block['pending_restart'])): ?>
                        <span class="badge-warn"><?= h(__('observability.mode_pending_restart')) ?></span>
                    <?php endif; ?>
                </div>
                <div class="meta" style="margin-bottom: 10px;">
                    <?php
                    $overrides = $block['mode_overrides'] ?? [];
                    $computed = $block['computed_effective'] ?? [];
                    $startup = $block['daemon_startup_effective'] ?? null;
                    ?>
                    <strong><?= h(__('observability.referent_mode_overrides')) ?>:</strong>
                    inbound=<?= h($overrides['inbound_routing_mode'] ?? __('referent.mode.inherit_global')) ?>,
                    outbound=<?= h($overrides['outbound_routing_mode'] ?? __('referent.mode.inherit_global')) ?>,
                    watch=<?= h($overrides['outbound_watch_mode'] ?? __('referent.mode.inherit_global')) ?>
                    <br>
                    <strong><?= h(__('observability.referent_mode_computed')) ?>:</strong>
                    inbound=<?= h((string)($computed['inbound'] ?? '—')) ?>,
                    outbound=<?= h((string)($computed['outbound'] ?? '—')) ?>,
                    watch=<?= h((string)($computed['watch'] ?? '—')) ?>
                    <?php if ($startup !== null): ?>
                        <br>
                        <strong><?= h(__('observability.referent_mode_daemon_startup')) ?>:</strong>
                        inbound=<?= h((string)$startup['inbound']) ?>,
                        outbound=<?= h((string)$startup['outbound']) ?>,
                        watch=<?= h((string)$startup['watch']) ?>
                    <?php else: ?>
                        <br><span class="empty"><?= h(__('observability.referent_mode_daemon_missing')) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($relationships === []): ?>
                    <p class="empty"><?= h(__('observability.no_relationships')) ?></p>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th><?= h(__('observability.col_relationship')) ?></th>
                            <th><?= h(__('observability.col_validity')) ?></th>
                            <th><?= h(__('observability.col_inbound')) ?></th>
                            <th><?= h(__('observability.col_outbound')) ?></th>
                            <th><?= h(__('observability.col_watch')) ?></th>
                            <th><?= h(__('observability.col_last_file')) ?></th>
                            <th><?= h(__('observability.col_warnings')) ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($relationships as $rel):
                            $row = $rel['row'];
                            $relId = (int)$row['id'];
                            $bucket = (string)$rel['readiness_bucket'];
                            $bucketClass = match ($bucket) {
                                'valid' => 'badge-valid',
                                'legacy-only-pending-backfill' => 'badge-legacy',
                                'inactive' => 'badge-inactive',
                                default => 'badge-incomplete',
                            };
                            $label = $rel['status']['label'];
                            $inbound = $rel['inbound_shadow'];
                            $outbound = $rel['outbound_shadow'];
                            $collisions = $rel['collisions'];
                            $dual = $rel['dual_divergence'];
                            $watchPath = $rel['watch_path'];
                            $lastFile = $rel['last_file'];
                            $displayEmail = trim((string)($row['external_client_email'] ?? ''));
                            if ($displayEmail === '' && relationshipIsLegacyOnly($row)) {
                                $displayEmail = (string)($row['email'] ?? '');
                            }
                            ?>
                            <tr data-relationship-id="<?= $relId ?>">
                                <td class="mono">
                                    #<?= $relId ?>
                                    <?php if ($displayEmail !== ''): ?>
                                        <br><?= h($displayEmail) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="<?= h($bucketClass) ?>">
                                        <?= h(__('observability.readiness.' . $bucket)) ?>
                                    </span>
                                    <br><span class="meta"><?= h($label) ?></span>
                                </td>
                                <td>
                                    <?php if ($inbound === null): ?>
                                        <span class="empty"><?= h(__('observability.shadow_none')) ?></span>
                                    <?php else: ?>
                                        <span class="<?= h(observabilityMarkerCssClass((string)$inbound['marker'])) ?>">
                                            <?= h((string)$inbound['marker']) ?>
                                        </span>
                                        <?php if ($inbound['ts'] !== null): ?>
                                            <br><span class="meta"><?= h((string)$inbound['ts']) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($outbound === null): ?>
                                        <span class="empty"><?= h(__('observability.shadow_none')) ?></span>
                                    <?php else: ?>
                                        <span class="<?= h(observabilityMarkerCssClass((string)$outbound['marker'])) ?>">
                                            <?= h((string)$outbound['marker']) ?>
                                        </span>
                                        <?php if ($outbound['ts'] !== null): ?>
                                            <br><span class="meta"><?= h((string)$outbound['ts']) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="mono">
                                    <?php if ($watchPath !== null && $watchPath !== ''): ?>
                                        <?= h((string)$watchPath) ?>
                                    <?php else: ?>
                                        <span class="empty">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono">
                                    <?php if ($lastFile === null): ?>
                                        <span class="empty">—</span>
                                    <?php else: ?>
                                        <?= h((string)($lastFile['file'] ?? '')) ?>
                                        <?php if ($lastFile['ts'] !== null): ?>
                                            <br><span class="meta"><?= h((string)$lastFile['ts']) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($collisions as $collision): ?>
                                        <?php if (($collision['source'] ?? '') === 'log'): ?>
                                            <div class="badge-warn">
                                                <?= h(__('observability.collision_log')) ?>
                                                <?php if ($collision['ts'] !== null): ?>
                                                    (<?= h((string)$collision['ts']) ?>)
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="badge-warn"><?= h(__('observability.collision_db')) ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if ($dual !== []): ?>
                                        <div class="badge-warn">
                                            <?= h(__('observability.dual_warning', ['count' => (string)count($dual)])) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($collisions === [] && $dual === []): ?>
                                        <span class="empty">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
