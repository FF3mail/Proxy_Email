<?php
declare(strict_types=1);

/**
 * Control panel dashboard — entity summary, mail activity, host/daemon health, quick nav.
 * UI-only; reuses getDaemonStatus() and mail_passage_journal when present.
 */

require_once __DIR__ . '/panel_daemon_status.php';
require_once __DIR__ . '/panel_service_status.php';
require_once __DIR__ . '/log_tail.php';
require_once __DIR__ . '/internet_status.php';
require_once __DIR__ . '/system_info.php';
require_once __DIR__ . '/dashboard_world_clocks.php';

if (!defined('DASHBOARD_MAIL_ACTIVITY_HOURS')) {
    define('DASHBOARD_MAIL_ACTIVITY_HOURS', 24);
}

function dashboardTruncate(string $text, int $max): string
{
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, max(0, $max - 3)) . '...';
}

/**
 * @return array{
 *   referents: array{total: int, active: int, inactive: int},
 *   clients: array{total: int, active: int},
 *   accounts: array{total: int, active: int}
 * }
 */
function dashboardEntityCounts(PDO $pdo): array
{
    $counts = [
        'referents' => ['total' => 0, 'active' => 0, 'inactive' => 0],
        'clients' => ['total' => 0, 'active' => 0],
        'accounts' => ['total' => 0, 'active' => 0],
    ];

    try {
        $row = $pdo->query(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_n
             FROM referents'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        $active = (int) ($row['active_n'] ?? 0);
        $counts['referents'] = [
            'total' => $total,
            'active' => $active,
            'inactive' => max(0, $total - $active),
        ];
    } catch (Throwable $e) {
        // leave zeros
    }

    try {
        $row = $pdo->query(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_n
             FROM clients'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts['clients'] = [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active_n'] ?? 0),
        ];
    } catch (Throwable $e) {
        // leave zeros
    }

    try {
        $row = $pdo->query(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_n
             FROM external_accounts'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts['accounts'] = [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active_n'] ?? 0),
        ];
    } catch (Throwable $e) {
        // leave zeros
    }

    return $counts;
}

/**
 * Best-effort mail activity from mail_passage_journal (rolling window).
 * Returns null fields when the table is missing or unreadable (honest n/a).
 *
 * @return array{
 *   available: bool,
 *   delivered: int|null,
 *   disposed: int|null,
 *   skipped: int|null,
 *   note: string
 * }
 */
function dashboardMailActivityRecent(PDO $pdo): array
{
    $hours = (int) DASHBOARD_MAIL_ACTIVITY_HOURS;
    if ($hours < 1) {
        $hours = 24;
    }
    $result = [
        'available' => false,
        'delivered' => null,
        'disposed' => null,
        'skipped' => null,
        'note' => '',
    ];

    try {
        $check = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_passage_journal'"
        );
        if ((int) $check->fetchColumn() < 1) {
            $result['note'] = __('dashboard.mail_na_no_journal');
            return $result;
        }

        $interval = (int) $hours;
        $stmt = $pdo->query(
            "SELECT event_type, COUNT(*) AS cnt
             FROM mail_passage_journal
             WHERE event_ts >= (UTC_TIMESTAMP() - INTERVAL {$interval} HOUR)
             GROUP BY event_type"
        );
        $delivered = 0;
        $disposed = 0;
        $skipped = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $type = (string) ($row['event_type'] ?? '');
            $cnt = (int) ($row['cnt'] ?? 0);
            if ($type === 'delivered') {
                $delivered = $cnt;
            } elseif ($type === 'disposed') {
                $disposed = $cnt;
            } elseif ($type === 'skipped') {
                $skipped = $cnt;
            }
        }

        $result['available'] = true;
        $result['delivered'] = $delivered;
        $result['disposed'] = $disposed;
        $result['skipped'] = $skipped;
        $result['note'] = __('dashboard.mail_window_hint');
    } catch (Throwable $e) {
        $result['note'] = __('dashboard.mail_na_error');
    }

    return $result;
}

/**
 * Host resources — fail soft with null (= n/a in UI).
 *
 * @return array{ram_free_human: ?string, ram_total_human: ?string, disk_free_human: ?string, disk_total_human: ?string}
 */
function dashboardHostResources(): array
{
    $out = [
        'ram_free_human' => null,
        'ram_total_human' => null,
        'disk_free_human' => null,
        'disk_total_human' => null,
    ];

    $fmt = static function (float $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $i >= 2 ? 1 : 0) . ' ' . $units[$i];
    };

    $free = @disk_free_space('/');
    $total = @disk_total_space('/');
    if (is_float($free) || is_int($free)) {
        $out['disk_free_human'] = $fmt((float) $free);
    }
    if (is_float($total) || is_int($total)) {
        $out['disk_total_human'] = $fmt((float) $total);
    }

    $meminfo = @file_get_contents('/proc/meminfo');
    if (is_string($meminfo) && $meminfo !== '') {
        $memTotalKb = null;
        $memAvailKb = null;
        if (preg_match('/^MemTotal:\s+(\d+)\s+kB/mi', $meminfo, $m)) {
            $memTotalKb = (int) $m[1];
        }
        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/mi', $meminfo, $m)) {
            $memAvailKb = (int) $m[1];
        } elseif (preg_match('/^MemFree:\s+(\d+)\s+kB/mi', $meminfo, $m)) {
            $memAvailKb = (int) $m[1];
        }
        if ($memTotalKb !== null) {
            $out['ram_total_human'] = $fmt($memTotalKb * 1024.0);
        }
        if ($memAvailKb !== null) {
            $out['ram_free_human'] = $fmt($memAvailKb * 1024.0);
        }
    }

    return $out;
}

/**
 * Recent WARNING/ERROR lines from daemon log (best-effort).
 *
 * @return list<array{ts: string, level: string, message: string}>
 */
function dashboardRecentLogIssues(int $limit = 5): array
{
    $path = PANEL_LOG_DIR_DAEMON . '/mail-proxy-daemon.log';
    $lines = tailFile($path, 120);
    $events = [];

    foreach (array_reverse($lines) as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $parsed = null;
        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),\d+\s+(DEBUG|INFO|WARNING|ERROR|CRITICAL)\s+\S+:\s+(.+)$/s',
            $line,
            $m
        )) {
            $parsed = ['ts' => $m[1], 'level' => strtoupper($m[2]), 'message' => $m[3]];
        } elseif (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(.+)$/s', $line, $m)) {
            $msg = $m[2];
            $upper = strtoupper($msg);
            $level = 'INFO';
            if (str_contains($upper, 'ERROR') || str_contains($upper, 'CRITICAL')) {
                $level = 'ERROR';
            } elseif (str_contains($upper, 'WARN') || str_contains($upper, 'FAIL')) {
                $level = 'WARNING';
            }
            $parsed = ['ts' => $m[1], 'level' => $level, 'message' => $msg];
        }
        if ($parsed === null) {
            continue;
        }
        if (!in_array($parsed['level'], ['WARNING', 'ERROR', 'CRITICAL'], true)) {
            continue;
        }
        $events[] = $parsed;
        if (count($events) >= $limit) {
            break;
        }
    }

    return $events;
}

function renderDashboardUi(): void
{
    $pdo = getPdo();
    $entities = dashboardEntityCounts($pdo);
    $mail = dashboardMailActivityRecent($pdo);
    $daemon = getDaemonStatus();
    $services = panelServiceStatuses();
    $healthChip = panelHealthOverallChip($services, $daemon);
    $host = dashboardHostResources();
    $issues = dashboardRecentLogIssues(5);
    $internet = internetStatusView(internetStatusGet(false));

    $flash = $GLOBALS['flash'] ?? null;
    // Keep banner flash on dashboard (not toast-only).

    renderHeader(__('dashboard.title'));
    ?>
    <div class="pm-head">
        <h1><?= h(__('dashboard.title')) ?></h1>
    </div>
    <?php renderDashboardWorldClocksWidget(); ?>
    <p class="pm-hint"><?= h(__('dashboard.intro')) ?></p>

    <!-- 1) Entity summary -->
    <div class="pm-stats" style="margin-bottom:20px">
        <div class="pm-stat">
            <b><?= (int) $entities['referents']['total'] ?></b>
            <span><?= h(__('dashboard.stat_referents')) ?></span>
            <div style="margin-top:6px;font-size:12px;color:var(--pm-muted)">
                <?= h(__('dashboard.stat_active_inactive', [
                    'active' => (string) $entities['referents']['active'],
                    'inactive' => (string) $entities['referents']['inactive'],
                ])) ?>
            </div>
        </div>
        <div class="pm-stat">
            <b><?= (int) $entities['clients']['total'] ?></b>
            <span><?= h(__('dashboard.stat_clients')) ?></span>
            <div style="margin-top:6px;font-size:12px;color:var(--pm-muted)">
                <?= h(__('dashboard.stat_active_only', [
                    'active' => (string) $entities['clients']['active'],
                ])) ?>
            </div>
        </div>
        <div class="pm-stat">
            <b><?= (int) $entities['accounts']['total'] ?></b>
            <span><?= h(__('dashboard.stat_accounts')) ?></span>
            <div style="margin-top:6px;font-size:12px;color:var(--pm-muted)">
                <?= h(__('dashboard.stat_active_only', [
                    'active' => (string) $entities['accounts']['active'],
                ])) ?>
            </div>
        </div>
    </div>

    <div class="pm-dash-grid">
        <!-- 2) Mail activity -->
        <div class="pm-card">
            <div class="pm-ch"><h3><?= h(__('dashboard.mail_title')) ?></h3></div>
            <?php if (!$mail['available']): ?>
                <p class="pm-hint" style="margin:0"><?= h($mail['note'] !== '' ? $mail['note'] : __('dashboard.mail_na')) ?></p>
                <dl class="pm-dl" style="margin-top:12px">
                    <dt><?= h(__('dashboard.mail_delivered')) ?></dt><dd>n/a</dd>
                    <dt><?= h(__('dashboard.mail_rejected')) ?></dt><dd>n/a</dd>
                </dl>
            <?php else: ?>
                <p class="pm-hint"><?= h($mail['note']) ?></p>
                <dl class="pm-dl">
                    <dt><?= h(__('dashboard.mail_delivered')) ?></dt>
                    <dd><strong><?= (int) $mail['delivered'] ?></strong></dd>
                    <dt><?= h(__('dashboard.mail_disposed')) ?></dt>
                    <dd><?= (int) $mail['disposed'] ?></dd>
                    <dt><?= h(__('dashboard.mail_skipped')) ?></dt>
                    <dd><?= (int) $mail['skipped'] ?></dd>
                    <dt><?= h(__('dashboard.mail_rejected')) ?></dt>
                    <dd><strong><?= (int) $mail['disposed'] + (int) $mail['skipped'] ?></strong>
                        <span style="color:var(--pm-muted);font-size:12px"> (disposed + skipped)</span>
                    </dd>
                </dl>
                <p style="margin:12px 0 0">
                    <a class="pm-btn pm-btn-sm" href="/relationship-status.php"><?= h(__('nav.journal')) ?> →</a>
                </p>
            <?php endif; ?>
        </div>

        <!-- 3) Host & service health -->
        <div class="pm-card">
            <div class="pm-ch"><h3><?= h(__('dashboard.health_title')) ?></h3></div>
            <?php
            $st = (string) $daemon['status'];
            $chip = match ($st) {
                'active' => 'pm-chip-ok',
                'inactive' => 'pm-chip-bad',
                default => 'pm-chip-warn',
            };
            $stLabel = match ($st) {
                'active' => __('dashboard.daemon_active'),
                'inactive' => __('dashboard.daemon_inactive'),
                default => __('dashboard.daemon_unknown'),
            };
            ?>
            <p style="margin:0 0 10px"><?= h(__('dashboard.health_overall')) ?>
                <span class="pm-chip <?= h($healthChip['class']) ?>"><?= h(__($healthChip['label_key'])) ?></span>
            </p>
            <dl class="pm-dl">
                <dt><?= h(__('dashboard.daemon_status')) ?></dt>
                <dd><span class="pm-chip <?= h($chip) ?>"><?= h($stLabel) ?></span></dd>
                <dt><?= h(__('dashboard.internet')) ?></dt>
                <dd><?php renderInternetStatusChip($internet, 'dashboard'); ?></dd>
                <?php foreach ($services as $svc):
                    $svcChip = $svc['chip'];
                    if (!$svc['critical']) {
                        $svcChip = panelOptionalServiceChip($svc['display'], $svcChip);
                    } elseif (in_array($svc['display'], ['inactive', 'failed', 'unknown'], true)) {
                        $svcChip = 'pm-chip-bad';
                    }
                    ?>
                    <dt><?= h(__($svc['label_key'])) ?></dt>
                    <dd><span class="pm-chip <?= h($svcChip) ?>"><?= h(panelServiceDisplayLabel($svc['display'])) ?></span></dd>
                <?php endforeach; ?>
                <dt><?= h(__('dashboard.daemon_pid')) ?></dt>
                <dd class="pm-mono"><?= $daemon['pid'] !== null ? (int) $daemon['pid'] : 'n/a' ?></dd>
                <dt><?= h(__('dashboard.daemon_uptime')) ?></dt>
                <dd><?= $daemon['uptime'] !== '' ? h($daemon['uptime']) : 'n/a' ?></dd>
                <dt><?= h(__('dashboard.ram_free')) ?></dt>
                <dd><?= $host['ram_free_human'] !== null
                    ? h($host['ram_free_human']) . ($host['ram_total_human'] ? ' / ' . h($host['ram_total_human']) : '')
                    : 'n/a' ?></dd>
                <dt><?= h(__('dashboard.disk_free')) ?></dt>
                <dd><?= $host['disk_free_human'] !== null
                    ? h($host['disk_free_human']) . ($host['disk_total_human'] ? ' / ' . h($host['disk_total_human']) : '')
                    : 'n/a' ?></dd>
            </dl>
            <p class="pm-hint" data-internet-warning
               style="color:var(--pm-danger);margin:10px 0 0"
               <?= $internet['status'] === 'offline' ? '' : 'hidden' ?>><?= h((string) $internet['warning']) ?></p>

            <h3 style="font-size:14px;margin:16px 0 8px"><?= h(__('dashboard.recent_issues')) ?></h3>
            <?php if ($issues === []): ?>
                <p class="pm-hint" style="margin:0"><?= h(__('dashboard.no_recent_issues')) ?></p>
            <?php else: ?>
                <ul class="pm-steps">
                    <?php foreach ($issues as $ev): ?>
                        <li>
                            <span class="pm-dot <?= $ev['level'] === 'WARNING' ? 'pm-dot-todo' : 'pm-dot-todo' ?>"
                                  style="<?= in_array($ev['level'], ['ERROR', 'CRITICAL'], true) ? 'background:var(--pm-danger-bg);color:var(--pm-danger)' : '' ?>">!</span>
                            <div class="pm-t">
                                <span class="pm-mono" style="font-size:11px"><?= h($ev['ts']) ?> · <?= h($ev['level']) ?></span>
                                <small style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%">
                                    <?= h(dashboardTruncate($ev['message'], 120)) ?>
                                </small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin:10px 0 0">
                    <a class="pm-btn pm-btn-sm" href="/logs.php?source=daemon"><?= h(__('dashboard.open_daemon_log')) ?></a>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php renderDashboardSystemInfoSection($pdo); ?>

    <style>
    .pm-dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:16px}
    </style>
    <?php
    renderInternetStatusPollScript();
    renderFooter();
}
