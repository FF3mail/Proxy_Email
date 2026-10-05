#!/usr/bin/env php
<?php
declare(strict_types=1);

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

$dash = (string) file_get_contents($root . '/web/includes/dashboard_ui.php');
$nav = (string) file_get_contents($root . '/web/includes/panel_nav.php');
$clocks = (string) file_get_contents($root . '/web/includes/dashboard_world_clocks.php');
$en = (string) file_get_contents($root . '/web/lang/en.php');
$ru = (string) file_get_contents($root . '/web/lang/ru.php');

assert_true(!preg_match('/pm-head[\s\S]{0,200}open_logs/', $dash), 'dashboard head has no Logs button');
assert_true(str_contains($nav, '/logs.php'), 'sidebar logs link remains');
assert_true(str_contains($dash, 'renderDashboardWorldClocksWidget'), 'dashboard renders clocks');
assert_true(str_contains($clocks, 'America/New_York'), 'zone NY');
assert_true(str_contains($clocks, 'Europe/Moscow'), 'zone Moscow');
assert_true(str_contains($clocks, 'Asia/Shanghai'), 'zone Beijing');
assert_true(str_contains($clocks, 'data-server-epoch'), 'server epoch embedded');
assert_true(substr_count($clocks, 'pm-world-clock-card') >= 4, 'four clock cards');
assert_true(!str_contains($clocks, 'fetch(') && !str_contains($clocks, 'XMLHttpRequest'), 'no network in widget');
assert_true(str_contains($en, 'dashboard.clocks_title') && str_contains($ru, 'dashboard.clocks_title'), 'i18n clocks title');

if ($failures > 0) {
    exit(1);
}
echo "OK: panel dashboard world clocks tests passed\n";
