<?php
declare(strict_types=1);

/**
 * Offline markup checks: Journal and Logs pages use the shared panel design system.
 */

$root = dirname(__DIR__);
require_once $root . '/web/includes/i18n.php';

$failures = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        $failures++;
    } else {
        fwrite(STDOUT, "OK: $msg\n");
    }
}

function assert_no_cr(string $path): void
{
    $raw = file_get_contents($path);
    assert_true($raw !== false && !str_contains($raw, "\r"), 'LF only: ' . basename($path));
}

$journal = $root . '/web/relationship-status.php';
$logs = $root . '/web/logs.php';
$journalSrc = file_get_contents($journal) ?: '';
$logsSrc = file_get_contents($logs) ?: '';

assert_no_cr($journal);
assert_no_cr($logs);

foreach ([$journalSrc => 'journal', $logsSrc => 'logs'] as $src => $label) {
    assert_true(str_contains($src, 'class="app-shell"'), "$label uses app-shell");
    assert_true(str_contains($src, 'class="app-main"'), "$label uses app-main");
    assert_true(str_contains($src, 'class="pm-head"'), "$label uses pm-head");
    assert_true(str_contains($src, 'panel-modal.css'), "$label loads panel-modal.css");
    assert_true(!preg_match('/<style[\s>]/i', $src), "$label has no inline style block");
    assert_true(!preg_match('/style\s*=\s*["\'][^"\']*#/i', $src), "$label has no inline colour styles");
    assert_true(!preg_match('/#[0-9a-fA-F]{3,8}\b/', $src), "$label markup has no hex colours");
}

assert_true(str_contains($journalSrc, 'class="pm-tabs"'), 'journal uses pm-tabs');
assert_true(str_contains($journalSrc, 'class="pm-table"'), 'journal uses pm-table');
assert_true(str_contains($journalSrc, 'data-readonly="1"'), 'journal table readonly scroll wrapper');
assert_true(str_contains($journalSrc, 'passageEventTypeChipClass'), 'journal event chips use shared mapper');
assert_true(str_contains($journalSrc, 'pm-chip'), 'journal uses pm-chip classes');

assert_true(str_contains($logsSrc, 'class="pm-log-view"'), 'logs uses pm-log-view');
assert_true(str_contains($logsSrc, 'pm-chip'), 'logs level highlighting uses pm-chip');
assert_true(str_contains($logsSrc, "__('logs.title')"), 'logs title i18n');

$keysUsed = [];
preg_match_all("/__\('([^']+)'\)/", $journalSrc . $logsSrc, $m);
foreach ($m[1] as $key) {
    $keysUsed[$key] = true;
}
initPanelI18n('en');
foreach (array_keys($keysUsed) as $key) {
    $en = __($key);
    assert_true($en !== $key, "EN key exists: $key");
}
initPanelI18n('ru');
foreach (array_keys($keysUsed) as $key) {
    $ru = __($key);
    assert_true($ru !== $key, "RU key exists: $key");
}

if ($failures > 0) {
    fwrite(STDERR, "panel_journal_logs_style_test: $failures failure(s)\n");
    exit(1);
}
fwrite(STDOUT, "panel_journal_logs_style_test: OK\n");
exit(0);
