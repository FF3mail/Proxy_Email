#!/usr/bin/env php
<?php
/**
 * Issue #39 — panel internet reachability: majority logic, cache, i18n, routing.
 * Run: php tests/panel_internet_status_test.php
 */
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

$cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mail-proxy-inet-test-' . getmypid() . '.json';
@unlink($cache);
if (!defined('PANEL_INTERNET_STATUS_CACHE')) {
    define('PANEL_INTERNET_STATUS_CACHE', $cache);
}

require_once $root . '/web/includes/i18n.php';
setPanelLang('en');
require_once $root . '/web/includes/internet_status.php';

$dec = internetStatusDecide(2, 1, 5);
assert_true($dec['status'] === 'online' && $dec['fail_streak'] === 0, 'majority ok → online, streak reset');

$dec = internetStatusDecide(1, 2, 0);
assert_true($dec['status'] === 'unknown' && $dec['fail_streak'] === 1, 'majority fail first cycle → unknown');

$dec = internetStatusDecide(0, 3, 1);
assert_true($dec['status'] === 'offline' && $dec['fail_streak'] === 2, 'consecutive majority fail → offline');

$dec = internetStatusDecide(0, 0, 0);
assert_true($dec['status'] === 'unknown', 'zero probes → unknown');

$alwaysOk = static function (string $host, int $port, float $timeout): bool {
    return true;
};
$alwaysFail = static function (string $host, int $port, float $timeout): bool {
    return false;
};
$probeCalls = 0;
$countingOk = static function (string $host, int $port, float $timeout) use (&$probeCalls): bool {
    $probeCalls++;
    return true;
};

$state = internetStatusRefresh($alwaysOk);
assert_true($state['status'] === 'online', 'refresh all-ok → online');
assert_true($state['fail_streak'] === 0, 'refresh all-ok streak 0');
assert_true($state['ok'] >= 1, 'refresh records ok count');

$cached = internetStatusReadCache();
assert_true(is_array($cached) && $cached['status'] === 'online', 'cache written after refresh');

$fresh = internetStatusGet(false, $alwaysFail);
assert_true($fresh['status'] === 'online', 'get(false) uses cache and does not probe');

$probeCalls = 0;
internetStatusGet(false, $countingOk);
assert_true($probeCalls === 0, 'page-render path never calls probe');

@unlink($cache);
$empty = internetStatusGet(false, $alwaysFail);
assert_true($empty['status'] === 'unknown' && $empty['checked_at'] === 0, 'missing cache → unknown without probe');

$firstFail = internetStatusRefresh($alwaysFail);
assert_true($firstFail['status'] === 'unknown', 'first all-fail cycle stays unknown');
$secondFail = internetStatusRefresh($alwaysFail);
assert_true($secondFail['status'] === 'offline', 'second all-fail cycle is offline');

$view = internetStatusView($secondFail);
assert_true($view['chip_class'] === 'pm-chip-bad', 'offline chip class');
assert_true($view['label'] === 'Offline', 'EN offline label');
assert_true($view['warning'] !== '', 'offline warning populated');
assert_true(str_contains($view['tooltip'], $view['warning']), 'tooltip includes warning');

$viewOn = internetStatusView(['status' => 'online', 'checked_at' => 1700000000]);
assert_true($viewOn['chip_class'] === 'pm-chip-ok' && $viewOn['warning'] === '', 'online chip, no warning');
assert_true($viewOn['dot_class'] === 'pm-inet-dot-ok', 'online dot class');

$viewUn = internetStatusView(['status' => 'unknown', 'checked_at' => 0]);
assert_true($viewUn['chip_class'] === 'pm-chip-warn', 'unknown chip class');
assert_true(str_contains($viewUn['tooltip'], 'Not checked yet'), 'never-checked tooltip');

setPanelLang('ru');
$GLOBALS['_panel_translations'] = null;
$viewRu = internetStatusView(['status' => 'offline', 'checked_at' => 1700000000]);
assert_true($viewRu['label'] === 'Нет связи', 'RU offline label');
assert_true($viewRu['warning'] !== '', 'RU offline warning');

$index = (string) file_get_contents($root . '/web/index.php');
$dash = (string) file_get_contents($root . '/web/includes/dashboard_ui.php');
$nav = (string) file_get_contents($root . '/web/includes/panel_nav.php');
$helper = (string) file_get_contents($root . '/web/includes/internet_status.php');
$jsCss = (string) file_get_contents($root . '/web/assets/panel-modal.css');
$en = (string) file_get_contents($root . '/web/lang/en.php');
$ru = (string) file_get_contents($root . '/web/lang/ru.php');

assert_true(str_contains($index, "case 'internet_status':"), 'internet_status route');
assert_true(str_contains($index, 'handleInternetStatusJson'), 'JSON handler wired');
assert_true(str_contains($index, "require_once __DIR__ . '/includes/internet_status.php';"), 'index loads helper');
assert_true(!str_contains($index, "'internet_status'") || !preg_match("/\\\$postActionsRequiringCsrf\s*=\s*\[[^\]]*internet_status/s", $index), 'GET JSON is not in CSRF POST list');

assert_true(str_contains($dash, "h(__('dashboard.internet'))"), 'dashboard Internet row');
assert_true(str_contains($dash, 'internetStatusGet(false)'), 'dashboard reads cache only');
assert_true(str_contains($dash, 'data-internet-warning'), 'dashboard offline warning slot');
assert_true(str_contains($nav, "renderInternetStatusChip"), 'sidebar chip');
assert_true(str_contains($nav, 'renderInternetStatusPollScript'), 'sidebar poll script');

assert_true(str_contains($helper, 'stream_socket_client'), 'TCP probe');
assert_true(!str_contains($helper, 'navigator.'), 'poll JS does not use browser network APIs');
assert_true(!str_contains($nav, 'navigator.'), 'nav JS does not use browser network APIs');
assert_true(str_contains($helper, 'imap.gmail.com'), 'default includes mail-provider host');
assert_true(str_contains($helper, '1.1.1.1'), 'default includes Cloudflare');
assert_true(str_contains($helper, "action=internet_status"), 'AJAX hits panel action');
assert_true(str_contains($helper, 'credentials: \'same-origin\''), 'AJAX sends session cookie');

assert_true(str_contains($jsCss, 'pm-inet-dot-ok'), 'sidebar dot styles');
assert_true(str_contains($jsCss, 'pm-chip-ok'), 'reuses chip classes');

$keys = [
    'dashboard.internet',
    'dashboard.internet_online',
    'dashboard.internet_offline',
    'dashboard.internet_unknown',
    'dashboard.internet_checked',
    'dashboard.internet_never',
    'dashboard.internet_offline_warning',
];
foreach ($keys as $key) {
    assert_true(str_contains($en, "'" . $key . "'"), "EN key {$key}");
    assert_true(str_contains($ru, "'" . $key . "'"), "RU key {$key}");
}

@unlink($cache);

if ($failures > 0) {
    echo "FAILED: {$failures} check(s)\n";
    exit(1);
}
echo "OK: panel internet status checks passed\n";
exit(0);
