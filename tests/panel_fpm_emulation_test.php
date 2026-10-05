#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Runs inside a subprocess with disable_functions set (see panel_fpm_emulation_driver_test.php).
 */

$failures = 0;
$root = dirname(__DIR__);

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
        return;
    }
    echo "OK: {$msg}\n";
}

require_once $root . '/web/includes/i18n.php';
setPanelLang('ru');
require_once $root . '/web/includes/helpers.php';
require_once $root . '/web/includes/panel_safe.php';
require_once $root . '/web/includes/panel_whitelist_runner.php';
require_once $root . '/web/includes/panel_service_status.php';
require_once $root . '/web/includes/system_info.php';

$checks = 0;
$check = static function (bool $c, string $m) use (&$checks): void {
    $checks++;
    assert_true($c, $m);
};

panelWhitelistResetPageBudget();
$runnerResult = panelWhitelistRunEx(['hostname'], 1.0);
$check(!$runnerResult['unavailable'] || $runnerResult['timed_out'] || is_string($runnerResult['stdout']), 'runner completes under emulation');

$svc = panelServiceStatuses(static fn (array $a): ?string => "loaded\nactive\n");
$check($svc !== [] && ($svc[0]['display'] ?? '') === 'active', 'service status with inject runner');

$throwBlock = panelSafeBlock(
    static function (): string {
        throw new RuntimeException('simulated collector failure');
    },
    'test.throw',
    null
);
$check($throwBlock === null, 'panelSafeBlock catches RuntimeException');

$errorBlock = panelSafeBlock(
    static function (): string {
        /** @phpstan-ignore-next-line */
        return not_a_real_function_for_panel_test();
    },
    'test.error',
    'fallback'
);
$check($errorBlock === 'fallback', 'panelSafeBlock catches Error');

ob_start();
panelSafeRenderBlock('test.render', static function (): void {
    echo '<span id="ok-block">ok</span>';
});
$okHtml = (string) ob_get_clean();
$check(str_contains($okHtml, 'ok-block'), 'panelSafeRenderBlock success path');

ob_start();
panelSafeRenderBlock('test.render_fail', static function (): void {
    throw new RuntimeException('render boom');
});
$failHtml = (string) ob_get_clean();
$check(str_contains($failHtml, 'н/д') || str_contains($failHtml, 'n/a'), 'panelSafeRenderBlock fail-soft hint');

$view = panelSafeBlock(
    static fn (): array => systemInfoBuildView(null, static fn (array $a): ?string => null, 1700000000),
    'test.sysinfo',
    null
);
$check(is_array($view), 'systemInfoBuildView under emulation');

echo "CHECK_COUNT {$checks}\n";
if ($failures > 0) {
    exit(1);
}
echo "OK: panel FPM emulation tests passed\n";
