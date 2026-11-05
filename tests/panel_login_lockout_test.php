#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Issue #136 — PHP control-flow tests for panel login lockout.
 * Uses in-memory SQLite as the PDO seam (no live MySQL).
 * SQLite does not exercise MySQL GET_LOCK / RELEASE_LOCK.
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

final class PanelLoginTestRedirect extends RuntimeException
{
}

$GLOBALS['test_pdo'] = new PDO('sqlite::memory:');
$GLOBALS['test_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['test_ip'] = '10.0.0.8';
$GLOBALS['test_flash'] = null;
$GLOBALS['test_logs'] = [];

function getPdo(): PDO
{
    return $GLOBALS['test_pdo'];
}

function writeLog(string $message): void
{
    $GLOBALS['test_logs'][] = $message;
}

function getClientIp(): string
{
    return (string) $GLOBALS['test_ip'];
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
    throw new PanelLoginTestRedirect($action);
}

function panelAuthLoginAllowed(): bool
{
    return true;
}

require_once $root . '/web/includes/panel_login_lockout.php';
require_once $root . '/web/includes/auth.php';
require_once $root . '/web/includes/panel_auth_ui.php';

function plaInitSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS panel_login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            login TEXT NOT NULL,
            ip TEXT NOT NULL,
            attempted_at TEXT NOT NULL,
            result TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS panel_admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            active INTEGER NOT NULL,
            must_change_password INTEGER NOT NULL DEFAULT 0
        )'
    );
}

function plaReset(PDO $pdo): void
{
    $pdo->exec('DELETE FROM panel_login_attempts');
    $pdo->exec('DELETE FROM panel_admins');
}

function plaResults(): array
{
    return getPdo()->query('SELECT login, ip, attempted_at, result FROM panel_login_attempts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function runLoginSubmit(string $user, string $password): string
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['username'] = $user;
    $_POST['password'] = $password;
    try {
        handleLoginSubmit();
        return 'no-redirect';
    } catch (PanelLoginTestRedirect $e) {
        return $e->getMessage();
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = getPdo();
plaInitSchema($pdo);

assert_true(panelLoginAttemptsTableExists(), 'sqlite table is visible via SELECT 1');

$now = 1_700_000_000;

$storedMax = PANEL_LOGIN_STORED_LOGIN_MAX_LENGTH;
$longLogin = str_repeat('x', $storedMax + 20);
$normalizedLong = panelLoginNormalizeLogin($longLogin);
assert_true(
    function_exists('mb_strlen')
        ? mb_strlen($normalizedLong, 'UTF-8') === $storedMax
        : strlen($normalizedLong) === $storedMax,
    'panelLoginNormalizeLogin truncates to stored login max'
);
assert_true(
    panelLoginNormalizeLogin($longLogin) === panelLoginNormalizeLogin(str_repeat('x', $storedMax) . 'suffix'),
    'overlong login variants normalize to the same key'
);

$lockName = panelLoginThrottleLockName('master', '10.0.0.8');
assert_true(strlen($lockName) <= 64, 'GET_LOCK name length is at most 64');
assert_true(str_starts_with($lockName, 'pla_'), 'GET_LOCK name uses pla_ prefix');

for ($i = 0; $i < 4; $i++) {
    assert_true(panelLoginThrottleAllow('master', '10.0.0.8', $now + $i), "allow before fail {$i}");
    panelLoginThrottleRegisterFailure('master', '10.0.0.8', $now + $i);
}
assert_true(panelLoginThrottleAllow('master', '10.0.0.8', $now + 4), 'fifth attempt still allowed');
panelLoginThrottleRegisterFailure('master', '10.0.0.8', $now + 4);
assert_true(!panelLoginThrottleAllow('master', '10.0.0.8', $now + 5), 'locked after 5 failures');
assert_true(panelLoginThrottleAllow('master', '10.0.0.9', $now + 5), 'other IP not locked');
assert_true(panelLoginThrottleAllow('other', '10.0.0.8', $now + 5), 'other login not locked');
assert_true(!panelLoginThrottleAllow('master', '10.0.0.8', $now + 4 + 900 - 1), 'still locked before 900s');
assert_true(panelLoginThrottleAllow('master', '10.0.0.8', $now + 4 + 900), 'lock expires at 900s');

plaReset($pdo);
$t = $now;
for ($i = 0; $i < 5; $i++) {
    panelLoginThrottleRegisterFailure('master', '10.0.0.8', $t);
}
assert_true(!panelLoginThrottleAllow('master', '10.0.0.8', $t), 'five same-second failures lock');
panelLoginThrottleClear('master', '10.0.0.8', $t);
assert_true(panelLoginThrottleAllow('master', '10.0.0.8', $t), 'same-second success clears lock');

plaReset($pdo);
panelLoginThrottleClear('master', '10.0.0.8', $t);
for ($i = 0; $i < 5; $i++) {
    panelLoginThrottleRegisterFailure('master', '10.0.0.8', $t);
}
assert_true(!panelLoginThrottleAllow('master', '10.0.0.8', $t), 'same-second failures after success still count via id');

plaReset($pdo);
$hash = password_hash('CorrectHorse1!', PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO panel_admins (username, password_hash, role, active) VALUES (?, ?, ?, 1)')
    ->execute(['master', $hash, 'master']);

for ($i = 0; $i < 3; $i++) {
    $dest = runLoginSubmit('master', 'wrong-password');
    assert_true($dest === 'login', "failed login redirects to login ({$i})");
    assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'auth.invalid_credentials', 'generic invalid credentials');
}

$dest = runLoginSubmit('master', 'CorrectHorse1!');
assert_true($dest === 'dashboard', 'handleLoginSubmit success redirects to dashboard');
$results = plaResults();
$successRows = array_values(array_filter($results, static fn (array $r): bool => $r['result'] === 'success'));
assert_true(count($successRows) === 1, 'handleLoginSubmit records exactly one success row');
assert_true($successRows[0]['login'] === 'master' && $successRows[0]['ip'] === '10.0.0.8', 'success row uses login+IP pair');
assert_true(isset($_SESSION['admin_id']), 'establishPanelSession ran after clear');

$dest = runLoginSubmit('master', 'wrong-password');
assert_true($dest === 'login', 'failure after success redirects to login');
assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'auth.invalid_credentials', 'failure after success is generic');
$afterSuccess = plaResults();
$successAfterFail = array_values(array_filter($afterSuccess, static fn (array $r): bool => $r['result'] === 'success'));
assert_true(count($successAfterFail) === 1, 'later failure does not add a second success');
assert_true(panelLoginThrottleAllow('master', '10.0.0.8'), 'one failure after success does not lock');
for ($i = 0; $i < 4; $i++) {
    runLoginSubmit('master', 'wrong-password');
}
assert_true(!panelLoginThrottleAllow('master', '10.0.0.8'), 'five failures after success lock the pair');
$dest = runLoginSubmit('master', 'CorrectHorse1!');
assert_true($dest === 'login', 'correct password rejected while locked after post-success failures');
assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'auth.login_locked', 'locked uses login_locked flash');

plaReset($pdo);
$pdo->prepare('INSERT INTO panel_admins (username, password_hash, role, active) VALUES (?, ?, ?, 1)')
    ->execute(['master', $hash, 'master']);
for ($i = 0; $i < 5; $i++) {
    $dest = runLoginSubmit('master', 'wrong-password');
    assert_true($dest === 'login', "lockout path failed login ({$i})");
    assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'auth.invalid_credentials', "lockout path generic error ({$i})");
}
$dest = runLoginSubmit('master', 'CorrectHorse1!');
assert_true($dest === 'login', 'correct password rejected while locked');
assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'auth.login_locked', 'fresh lockout uses login_locked flash');
$blocked = array_values(array_filter(plaResults(), static fn (array $r): bool => $r['result'] === 'blocked'));
assert_true($blocked !== [], 'locked attempt records blocked');
assert_true(panelLoginThrottleBegin('master', '10.0.0.8'), 'sqlite skips GET_LOCK and still begins');
panelLoginThrottleEnd('master', '10.0.0.8');

plaReset($pdo);
$login100 = str_repeat('a', $storedMax);
$overlongLogin = $login100 . 'overflow-suffix';
$pdo->prepare('INSERT INTO panel_admins (username, password_hash, role, active) VALUES (?, ?, ?, 1)')
    ->execute([$login100, $hash, 'master']);
for ($i = 0; $i < 5; $i++) {
    $dest = runLoginSubmit($overlongLogin, 'wrong-password');
    assert_true($dest === 'login', "overlong username failed login ({$i})");
}
$dest = runLoginSubmit($overlongLogin, 'CorrectHorse1!');
assert_true($dest === 'login', 'correct password rejected for overlong username while locked');
assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'auth.login_locked', 'overlong username lockout uses login_locked');

$pdo->exec('DROP TABLE panel_login_attempts');
assert_true(!panelLoginAttemptsTableExists(), 'missing table is not visible');
assert_true(!panelLoginThrottleBegin('master', '10.0.0.8'), 'begin fail-closed when table missing');
assert_true(!panelLoginThrottleAllow('master', '10.0.0.8'), 'allow fail-closed when table missing');
plaInitSchema($pdo);
$pdo->prepare('INSERT INTO panel_admins (username, password_hash, role, active) VALUES (?, ?, ?, 1)')
    ->execute(['master', $hash, 'master']);
$dest = runLoginSubmit('master', 'CorrectHorse1!');
assert_true($dest === 'dashboard', 'login works after table restored');
assert_true(count(array_filter(plaResults(), static fn (array $r): bool => $r['result'] === 'success')) === 1, 'restored table still one success');

plaReset($pdo);
plaInitSchema($pdo);
$pdo->exec(
    'CREATE TRIGGER pla_insert_fail BEFORE INSERT ON panel_login_attempts
     WHEN NEW.login = \'fail-insert\' BEGIN SELECT RAISE(ABORT, \'deny\'); END'
);
assert_true(!panelLoginThrottleRegisterFailure('fail-insert', '10.0.0.8', $now), 'INSERT failure returns false');
$recordLogs = array_filter(
    $GLOBALS['test_logs'],
    static fn (string $line): bool => str_contains($line, 'Panel login attempt record failed:')
);
assert_true($recordLogs !== [], 'INSERT failure is logged');
$pdo->exec('DROP TRIGGER IF EXISTS pla_insert_fail');
plaReset($pdo);

echo "\nSummary: failures={$failures}\n";
exit($failures > 0 ? 1 : 0);
