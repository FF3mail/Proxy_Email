#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Issue #141 — failure INSERT must complete before RELEASE_LOCK on login path.
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

final class PanelLockOrderTestPdo extends PDO
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

final class PanelLockOrderRedirect extends RuntimeException
{
}

$GLOBALS['test_pdo'] = new PanelLockOrderTestPdo();
$GLOBALS['test_ip'] = '10.0.0.55';
$GLOBALS['test_flash'] = null;
$GLOBALS['test_logs'] = [];
$GLOBALS['panel_login_mysql_lock_query_hook'] = null;
$GLOBALS['panel_login_lock_events'] = [];

function getPdo(): PDO
{
    return $GLOBALS['test_pdo'];
}

function getClientIp(): string
{
    return (string) $GLOBALS['test_ip'];
}

function writeLog(string $message): void
{
    $GLOBALS['test_logs'][] = $message;
}

function __(string $key, array $params = []): string
{
    return $key;
}

function setFlash(string $type, string $message): void
{
    $GLOBALS['test_flash'] = ['type' => $type, 'message' => $message];
}

function redirectTo(string $action, array $params = []): void
{
    throw new PanelLockOrderRedirect($action);
}

function panelAuthLoginAllowed(): bool
{
    return true;
}

require_once $root . '/web/includes/panel_login_lockout.php';
require_once $root . '/web/includes/auth.php';
require_once $root . '/web/includes/panel_auth_ui.php';

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
$pdo->exec(
    'CREATE TABLE panel_admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL,
        active INTEGER NOT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 0
    )'
);
$hash = password_hash('CorrectHorse1!', PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO panel_admins (username, password_hash, role, active) VALUES (?, ?, ?, 1)')
    ->execute(['admin', $hash, 'master']);

$lockName = panelLoginThrottleLockName('admin', $GLOBALS['test_ip']);
$GLOBALS['panel_login_mysql_lock_query_hook'] = static function (PDO $pdo, string $sql, array $params) use ($lockName): mixed {
    if (str_contains($sql, 'GET_LOCK')) {
        $GLOBALS['panel_login_lock_events'][] = 'GET_LOCK';

        return 1;
    }
    if (str_contains($sql, 'RELEASE_LOCK')) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM panel_login_attempts')->fetchColumn();
        assert_true($count >= 1, 'failure INSERT recorded before RELEASE_LOCK');
        $GLOBALS['panel_login_lock_events'][] = 'RELEASE_LOCK';

        return 1;
    }

    return 0;
};

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['username'] = 'admin';
$_POST['password'] = 'wrong-password';

try {
    handleLoginSubmit();
} catch (PanelLockOrderRedirect) {
    // expected
}

$events = $GLOBALS['panel_login_lock_events'];
assert_true($events !== [] && $events[0] === 'GET_LOCK', 'GET_LOCK before handler work');
assert_true(in_array('RELEASE_LOCK', $events, true), 'RELEASE_LOCK observed');
$firstRelease = array_search('RELEASE_LOCK', $events, true);
assert_true($firstRelease !== false && $firstRelease > 0, 'RELEASE_LOCK after GET_LOCK');

echo "\nSummary: failures={$failures}\n";
exit($failures > 0 ? 1 : 0);
