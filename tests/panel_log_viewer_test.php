#!/usr/bin/env php
<?php
/**
 * Static unit checks for panel log viewer allowlist (PROMPT-46).
 * Run: php tests/panel_log_viewer_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../web/includes/log_tail.php';
require_once __DIR__ . '/../web/includes/log_viewer.php';

$failures = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
    } else {
        echo "OK: {$msg}\n";
    }
}

// Allowed sources resolve under LOG_DIR
$daemon = resolvePanelLogPath('daemon');
assert_true($daemon !== null && str_ends_with($daemon, 'mail-proxy-daemon.log'), 'daemon log path');

$web = resolvePanelLogPath('web');
assert_true($web !== null && str_ends_with($web, 'web_admin.log'), 'web log path');

// Traversal / arbitrary source rejected
assert_true(resolvePanelLogPath('../../../etc/passwd') === null, 'reject traversal source key');
assert_true(resolvePanelLogPath('') === null, 'reject empty source');
assert_true(resolvePanelLogPath('syslog') === null, 'reject unknown source');

// Line count bounds
assert_true(normalizePanelLogLines(10) === PANEL_LOG_LINES_MIN, 'clamp min lines');
assert_true(normalizePanelLogLines(9999) === PANEL_LOG_LINES_MAX, 'clamp max lines');
assert_true(normalizePanelLogLines(200) === 200, 'pass through valid lines');

exit($failures > 0 ? 1 : 0);
