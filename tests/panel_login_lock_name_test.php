#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Issue #141 — GET_LOCK name canonical login (utf8mb4_unicode_ci approximation).
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
$GLOBALS['test_logs'] = [];
$GLOBALS['panel_login_lock_canonical_force_mb_fallback'] = false;

function getPdo(): PDO
{
    return $GLOBALS['test_pdo'];
}

function writeLog(string $message): void
{
    $GLOBALS['test_logs'][] = $message;
}

require_once $root . '/web/includes/panel_login_lockout.php';

function lockNameReset(): void
{
    $GLOBALS['panel_login_lock_canonical_force_mb_fallback'] = false;
}

lockNameReset();

$ip = '10.0.0.42';
$variants = ['admin', 'ADMIN', 'Admin', 'admín', 'ａｄｍｉｎ', 'Admín'];
$expected = null;
foreach ($variants as $variant) {
    $name = panelLoginThrottleLockName($variant, $ip);
    assert_true(strlen($name) === 64, "lock name length 64 for variant {$variant}");
    assert_true(str_starts_with($name, 'pla_'), 'lock name pla_ prefix');
    if (panelLoginIntlAvailableForLockCanonical()) {
        if ($expected === null) {
            $expected = $name;
        }
        assert_true($name === $expected, "intl: collation-equivalent variant shares lock name ({$variant})");
    } else {
        echo "SKIP: intl missing — accent/fullwidth equivalence not asserted\n";
        break;
    }
}

assert_true(
    panelLoginThrottleLockName('master', $ip) !== panelLoginThrottleLockName('other', $ip),
    'different logins produce different lock names'
);
assert_true(
    panelLoginThrottleLockName('master', '10.0.0.1') !== panelLoginThrottleLockName('master', '10.0.0.2'),
    'different IPs produce different lock names'
);

$pdo = getPdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS panel_login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        login TEXT NOT NULL,
        ip TEXT NOT NULL,
        attempted_at TEXT NOT NULL,
        result TEXT NOT NULL
    )'
);
$pdo->exec('DELETE FROM panel_login_attempts');
$now = 1_700_000_100;
assert_true(panelLoginThrottleRegisterFailure('ADMIN', $ip, $now), 'register failure stores row');
$row = $pdo->query('SELECT login FROM panel_login_attempts ORDER BY id DESC LIMIT 1')->fetchColumn();
assert_true($row === 'ADMIN', 'SQL counter stores login unchanged (not lock canonical form)');

lockNameReset();
$GLOBALS['panel_login_lock_canonical_force_mb_fallback'] = true;
$nameLower = panelLoginThrottleLockName('admin', $ip);
$nameAccent = panelLoginThrottleLockName('admín', $ip);
assert_true(
    $nameLower !== $nameAccent,
    'mb fallback: admín does not share lock name with admin (no accent folding)'
);
lockNameReset();

$GLOBALS['panel_login_lock_canonical_force_mb_fallback'] = true;
panelLoginCanonicalLoginForThrottleLock('admin');
$intlLogs = array_filter(
    $GLOBALS['test_logs'],
    static fn (string $line): bool => str_contains($line, 'Normalizer unavailable (accent folding disabled)')
);
assert_true(count($intlLogs) === 1, 'missing intl logs once per process when canonical runs');
panelLoginCanonicalLoginForThrottleLock('admin');
assert_true(
    count(array_filter(
        $GLOBALS['test_logs'],
        static fn (string $line): bool => str_contains($line, 'Normalizer unavailable (accent folding disabled)')
    )) === 1,
    'second canonical call does not duplicate intl missing log'
);
lockNameReset();

echo "\nSummary: failures={$failures}\n";
exit($failures > 0 ? 1 : 0);
