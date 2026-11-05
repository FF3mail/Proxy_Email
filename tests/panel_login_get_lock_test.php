#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Issue #141 — GET_LOCK fail-closed paths via panelLoginThrottleMysqlLockQuery test hook.
 * Does not require live MySQL (PDO driver name is mocked as mysql).
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

final class PanelGetLockTestPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getAttribute($attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'mysql';
        }

        return parent::getAttribute($attribute);
    }
}

$GLOBALS['test_pdo'] = new PanelGetLockTestPdo();
$GLOBALS['test_logs'] = [];
$GLOBALS['panel_login_mysql_lock_query_hook'] = null;
$GLOBALS['panel_login_release_calls'] = [];

function getPdo(): PDO
{
    return $GLOBALS['test_pdo'];
}

function writeLog(string $message): void
{
    $GLOBALS['test_logs'][] = $message;
}

require_once $root . '/web/includes/panel_login_lockout.php';

function plaResetHook(): void
{
    $GLOBALS['panel_login_mysql_lock_query_hook'] = null;
    $GLOBALS['panel_login_release_calls'] = [];
    $GLOBALS['test_logs'] = [];
    $pdo = getPdo();
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS panel_login_attempts (id INTEGER PRIMARY KEY)');
    } catch (Throwable) {
        // already exists
    }
}

plaResetHook();

$GLOBALS['panel_login_mysql_lock_query_hook'] = static function (PDO $pdo, string $sql, array $params): int {
    if (str_contains($sql, 'GET_LOCK')) {
        return 0;
    }
    $GLOBALS['panel_login_release_calls'][] = $params[0] ?? '';

    return 1;
};
assert_true(!panelLoginThrottleBegin('user', '10.0.0.1'), 'GET_LOCK timeout (0) fails closed');
assert_true($GLOBALS['panel_login_release_calls'] === [], 'no RELEASE_LOCK before End');

plaResetHook();
$GLOBALS['panel_login_mysql_lock_query_hook'] = static function (PDO $pdo, string $sql, array $params): mixed {
    if (str_contains($sql, 'GET_LOCK')) {
        return false;
    }
    $GLOBALS['panel_login_release_calls'][] = $params[0] ?? '';

    return 1;
};
assert_true(!panelLoginThrottleBegin('user', '10.0.0.1'), 'GET_LOCK NULL/false fails closed');

plaResetHook();
$GLOBALS['panel_login_mysql_lock_query_hook'] = static function (PDO $pdo, string $sql, array $params): never {
    throw new RuntimeException('lock query failed');
};
assert_true(!panelLoginThrottleBegin('user', '10.0.0.1'), 'GET_LOCK exception fails closed');
assert_true(
    in_array('Panel login throttle lock failed: lock query failed', $GLOBALS['test_logs'], true),
    'GET_LOCK exception is logged'
);

plaResetHook();
$lockName = panelLoginThrottleLockName('user', '10.0.0.1');
$GLOBALS['panel_login_mysql_lock_query_hook'] = static function (PDO $pdo, string $sql, array $params) use ($lockName): mixed {
    if (str_contains($sql, 'GET_LOCK')) {
        return 1;
    }
    if (str_contains($sql, 'RELEASE_LOCK')) {
        $GLOBALS['panel_login_release_calls'][] = (string) ($params[0] ?? '');

        return 1;
    }

    return 0;
};
assert_true(panelLoginThrottleBegin('user', '10.0.0.1'), 'GET_LOCK success begins');
try {
    throw new RuntimeException('simulated handler failure');
} catch (RuntimeException) {
    panelLoginThrottleEnd('user', '10.0.0.1');
}
assert_true($GLOBALS['panel_login_release_calls'] === [$lockName], 'RELEASE_LOCK after exception in handler');

plaResetHook();
unset($GLOBALS['panel_login_mysql_lock_query_hook']);
assert_true(panelLoginAttemptsTableExists(), 'hook unset uses real PDO path on sqlite (skips lock)');

echo "\nSummary: failures={$failures}\n";
exit($failures > 0 ? 1 : 0);
