<?php
declare(strict_types=1);

require_once __DIR__ . '/panel_whitelist_runner.php';

if (!defined('PANEL_SERVICE_STATUS_CACHE')) {
    define('PANEL_SERVICE_STATUS_CACHE', sys_get_temp_dir() . '/mail-proxy-service-status.json');
}
if (!defined('PANEL_SERVICE_STATUS_CACHE_TTL')) {
    define('PANEL_SERVICE_STATUS_CACHE_TTL', 60);
}

/**
 * @return list<array{key: string, label_key: string, critical: bool, units: list<string>}>
 */
function panelServiceDefinitions(): array
{
    return [
        ['key' => 'postfix', 'label_key' => 'dashboard.svc_postfix', 'critical' => true, 'units' => ['postfix.service']],
        ['key' => 'dovecot', 'label_key' => 'dashboard.svc_dovecot', 'critical' => true, 'units' => ['dovecot.service']],
        ['key' => 'nginx', 'label_key' => 'dashboard.svc_nginx', 'critical' => true, 'units' => ['nginx.service']],
        ['key' => 'database', 'label_key' => 'dashboard.svc_database', 'critical' => true, 'units' => ['mariadb.service', 'mysql.service', 'mysqld.service']],
        ['key' => 'amavis', 'label_key' => 'dashboard.svc_amavis', 'critical' => false, 'units' => ['amavis.service', 'amavisd.service']],
        ['key' => 'clamav', 'label_key' => 'dashboard.svc_clamav', 'critical' => false, 'units' => ['clamav-daemon.service']],
        ['key' => 'spamassassin', 'label_key' => 'dashboard.svc_spamassassin', 'critical' => false, 'units' => ['spamassassin.service', 'spamd.service']],
    ];
}

/**
 * @param callable(list<string>): ?string $runner
 * @return array{load: string, active: string, display: string, chip: string}
 */
function panelServiceResolveUnit(array $unitNames, callable $runner): array
{
    foreach ($unitNames as $unit) {
        $show = $runner(['systemctl', 'show', '-p', 'LoadState,ActiveState', '--value', $unit]);
        if ($show === null || trim($show) === '') {
            continue;
        }
        $lines = preg_split('/\r?\n/', trim($show)) ?: [];
        $load = trim((string) ($lines[0] ?? ''));
        $active = trim((string) ($lines[1] ?? ''));
        if ($load === 'not-found') {
            continue;
        }
        return panelServiceStateToDisplay($load, $active);
    }

    return [
        'load' => 'not-found',
        'active' => '',
        'display' => 'not_installed',
        'chip' => 'pm-chip-warn',
    ];
}

/**
 * @return array{load: string, active: string, display: string, chip: string}
 */
function panelServiceStateToDisplay(string $load, string $active): array
{
    if ($load === 'not-found') {
        return ['load' => $load, 'active' => $active, 'display' => 'not_installed', 'chip' => 'pm-chip-warn'];
    }
    if ($active === 'active') {
        return ['load' => $load, 'active' => $active, 'display' => 'active', 'chip' => 'pm-chip-ok'];
    }
    if ($active === 'failed' || $active === 'inactive') {
        return ['load' => $load, 'active' => $active, 'display' => $active, 'chip' => 'pm-chip-bad'];
    }

    return ['load' => $load, 'active' => $active, 'display' => 'unknown', 'chip' => 'pm-chip-warn'];
}

/**
 * @param callable(list<string>): ?string|null $runner
 * @return list<array{key: string, label_key: string, critical: bool, display: string, chip: string}>
 */
function panelServiceStatuses(?callable $runner = null): array
{
    $runner ??= 'panelWhitelistRun';
    $cachePath = PANEL_SERVICE_STATUS_CACHE;
    $ttl = (int) PANEL_SERVICE_STATUS_CACHE_TTL;
    if (is_readable($cachePath)) {
        $raw = @file_get_contents($cachePath);
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['ts'], $data['rows']) && (time() - (int) $data['ts']) < $ttl) {
                return $data['rows'];
            }
        }
    }

    $rows = [];
    foreach (panelServiceDefinitions() as $def) {
        $state = panelServiceResolveUnit($def['units'], $runner);
        $rows[] = [
            'key' => $def['key'],
            'label_key' => $def['label_key'],
            'critical' => $def['critical'],
            'display' => $state['display'],
            'chip' => $state['chip'],
        ];
    }

    @file_put_contents($cachePath, json_encode(['ts' => time(), 'rows' => $rows], JSON_UNESCAPED_UNICODE));

    return $rows;
}

/**
 * @param list<array{critical: bool, display: string}> $services
 * @param array{status: string} $daemon
 */
function panelHealthOverallChip(array $services, array $daemon): array
{
    $daemonBad = in_array($daemon['status'], ['inactive', 'unknown'], true);
    $criticalBad = false;
    foreach ($services as $svc) {
        if (empty($svc['critical'])) {
            continue;
        }
        if (in_array($svc['display'], ['inactive', 'failed', 'unknown'], true)) {
            $criticalBad = true;
            break;
        }
    }
    if ($daemonBad || $criticalBad) {
        return ['class' => 'pm-chip-bad', 'label_key' => 'dashboard.health_overall_bad'];
    }

    return ['class' => 'pm-chip-ok', 'label_key' => 'dashboard.health_overall_ok'];
}

function panelServiceDisplayLabel(string $display): string
{
    return match ($display) {
        'active' => __('dashboard.svc_active'),
        'inactive' => __('dashboard.svc_inactive'),
        'failed' => __('dashboard.svc_failed'),
        'not_installed' => __('dashboard.svc_not_installed'),
        default => __('dashboard.svc_unknown'),
    };
}

function panelOptionalServiceChip(string $display, string $defaultChip): string
{
    if ($display === 'not_installed') {
        return 'pm-chip-warn';
    }
    if (in_array($display, ['inactive', 'failed'], true)) {
        return 'pm-chip-warn';
    }

    return $defaultChip;
}
