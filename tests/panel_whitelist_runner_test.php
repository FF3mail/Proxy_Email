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

require_once $root . '/web/includes/panel_whitelist_runner.php';

$checks = 0;
$check = static function (bool $c, string $m) use (&$checks): void {
    $checks++;
    assert_true($c, $m);
};

panelWhitelistResetPageBudget();
panelWhitelistSetProcOpenFactory(null);

$empty = panelWhitelistRunEx([]);
$check($empty['unavailable'] === true, 'empty argv unavailable');

$bad = panelWhitelistRunEx(['not-a-whitelisted-cmd']);
$check($bad['unavailable'] === true, 'non-whitelist unavailable');

panelWhitelistSetProcOpenFactory(static function (array $execArgv, array $descriptors): array {
    $php = PHP_BINARY;
    $cmd = [$php, '-r', 'echo "whitelisted-out";'];
    $pipes = [];
    $proc = @proc_open($cmd, $descriptors, $pipes, null, null);
    return [$proc, $pipes];
});

panelWhitelistResetPageBudget();
$mocked = panelWhitelistRunEx(['hostname'], 2.0);
$check($mocked['ok'] === true && $mocked['stdout'] === 'whitelisted-out', 'factory injects stdout');

panelWhitelistSetProcOpenFactory(static function (array $execArgv, array $descriptors): array {
    $php = PHP_BINARY;
    $cmd = [$php, '-r', 'sleep(3); echo "late";'];
    $pipes = [];
    $proc = @proc_open($cmd, $descriptors, $pipes, null, null);
    return [$proc, $pipes];
});

panelWhitelistResetPageBudget();
$t0 = microtime(true);
$timed = panelWhitelistRunEx(['hostname'], 0.35);
$elapsed = microtime(true) - $t0;
$check($timed['timed_out'] === true, 'short deadline marks timed_out');
$check($elapsed < 2.5, 'timeout path does not block for full sleep');

panelWhitelistSetProcOpenFactory(static function (array $execArgv, array $descriptors): array {
    $php = PHP_BINARY;
    $payload = str_repeat('x', 70000);
    $cmd = [$php, '-r', 'echo str_repeat("x", 70000);'];
    $pipes = [];
    $proc = @proc_open($cmd, $descriptors, $pipes, null, null);
    return [$proc, $pipes];
});

panelWhitelistResetPageBudget();
$big = panelWhitelistRunEx(['hostname'], 2.0);
$check(strlen($big['stdout']) <= (int) PANEL_WHITELIST_MAX_OUTPUT_BYTES, 'stdout size capped');

panelWhitelistSetProcOpenFactory(static function (): array {
    return [false, []];
});
panelWhitelistResetPageBudget();
$unavail = panelWhitelistRunEx(['hostname'], 1.0);
$check($unavail['unavailable'] === true, 'failed proc_open unavailable');

panelWhitelistSetProcOpenFactory(null);
panelWhitelistResetPageBudget();
$legacy = panelWhitelistRun(['hostname']);
$check($legacy === null || is_string($legacy), 'panelWhitelistRun adapter returns null or string');

echo "CHECK_COUNT {$checks}\n";
if ($failures > 0) {
    exit(1);
}
echo "OK: panel whitelist runner tests passed\n";
