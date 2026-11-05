#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Issue #142 — coalesce blocked rows to at most one per login+IP per 60s.
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

$GLOBALS['test_pdo'] = new PDO('sqlite::memory:');
$GLOBALS['test_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function getPdo(): PDO
{
    return $GLOBALS['test_pdo'];
}

function writeLog(string $message): void
{
}

require_once $root . '/web/includes/panel_login_lockout.php';

$pdo = getPdo();
$pdo->exec(
    'CREATE TABLE panel_login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        login TEXT NOT NULL,
        ip TEXT NOT NULL,
        attempted_at TEXT NOT NULL,
        result TEXT NOT NULL
    )'
);

$now = 1_700_000_000;
$login = 'master';
$ip = '10.0.0.8';

assert_true(
    panelLoginThrottleRegisterBlocked($login, $ip, $now),
    'first blocked row inserts'
);
$count = (int) $pdo->query("SELECT COUNT(*) FROM panel_login_attempts WHERE result='blocked'")->fetchColumn();
assert_true($count === 1, 'one blocked row after first register');

assert_true(
    panelLoginThrottleRegisterBlocked($login, $ip, $now + 10),
    'second blocked within 60s returns true without error'
);
$count = (int) $pdo->query("SELECT COUNT(*) FROM panel_login_attempts WHERE result='blocked'")->fetchColumn();
assert_true($count === 1, 'still one blocked row within coalesce window');

assert_true(
    panelLoginThrottleRegisterBlocked($login, $ip, $now + PANEL_LOGIN_BLOCKED_COALESCE_SECONDS),
    'blocked at coalesce boundary inserts again'
);
$count = (int) $pdo->query("SELECT COUNT(*) FROM panel_login_attempts WHERE result='blocked'")->fetchColumn();
assert_true($count === 2, 'second blocked row after 60s window');

assert_true(
    panelLoginThrottleRegisterBlocked('other', $ip, $now),
    'different login gets its own blocked row'
);
$count = (int) $pdo->query("SELECT COUNT(*) FROM panel_login_attempts WHERE result='blocked'")->fetchColumn();
assert_true($count === 3, 'blocked rows are per login+IP pair');

echo "\nSummary: failures={$failures}\n";
exit($failures > 0 ? 1 : 0);
