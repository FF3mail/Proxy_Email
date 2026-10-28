#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Issue #138 — operator password change and master reset (SEC-11 / DAT-9).
 * In-memory SQLite; no live MySQL.
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

function assert_no_password_in_logs(array $logs): void
{
    foreach ($logs as $line) {
        assert_true(
            !preg_match('/TempPass|SecretNew|WrongOld|short/i', (string) $line),
            'log line must not contain password material'
        );
    }
}

final class PanelPasswordTestRedirect extends RuntimeException
{
    public function __construct(public string $dest)
    {
        parent::__construct($dest);
    }
}

$GLOBALS['test_pdo'] = new PDO('sqlite::memory:');
$GLOBALS['test_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['test_ip'] = '10.0.0.44';
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
    throw new PanelPasswordTestRedirect($action);
}

function panelAdminsTableExists(): bool
{
    try {
        $stmt = getPdo()->query("SHOW TABLES LIKE 'panel_admins'");
        if ($stmt !== false && $stmt->fetch() !== false) {
            return true;
        }
    } catch (Throwable) {
        // SQLite: fall through
    }
    try {
        $stmt = getPdo()->query('SELECT 1 FROM panel_admins LIMIT 1');
        return $stmt !== false;
    } catch (Throwable) {
        return false;
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function panelHtmlLang(): string
{
    return 'en';
}

function renderHeader(string $title): void
{
}

function renderFooter(): void
{
}

function renderPanelModalStyles(): void
{
}

function renderPanelSidebar(): void
{
}

require_once $root . '/web/includes/panel_operator_password.php';
require_once $root . '/web/includes/auth.php';

function pwInitSchema(PDO $pdo): void
{
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

function pwReset(PDO $pdo): void
{
    $pdo->exec('DELETE FROM panel_admins');
    $GLOBALS['test_logs'] = [];
    $GLOBALS['test_flash'] = null;
}

function pwInsertAdmin(PDO $pdo, string $user, string $pass, string $role, int $mustChange = 0): int
{
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare(
        'INSERT INTO panel_admins (username, password_hash, role, active, must_change_password)
         VALUES (?, ?, ?, 1, ?)'
    )->execute([$user, $hash, $role, $mustChange]);
    return (int) $pdo->lastInsertId();
}

function pwRunChange(string $current, string $new): string
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'current_password' => $current,
        'new_password' => $new,
    ];
    try {
        handlePasswordChangeSubmit();
        return 'no-redirect';
    } catch (PanelPasswordTestRedirect $e) {
        return $e->dest;
    }
}

function pwRunReset(int $id, string $password): string
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['id' => (string) $id, 'password' => $password];
    try {
        handleOperatorResetPassword();
        return 'no-redirect';
    } catch (PanelPasswordTestRedirect $e) {
        return $e->dest;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = getPdo();
pwInitSchema($pdo);

assert_true(panelOperatorPasswordLengthValid('1234567') === false, '7 chars rejected');
assert_true(panelOperatorPasswordLengthValid('12345678'), '8 chars accepted');

pwReset($pdo);
$adminId = pwInsertAdmin($pdo, 'admin1', 'StartPass1!', 'admin');
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_username_display'] = 'admin1';
$_SESSION['admin_role_display'] = 'admin';

$dest = pwRunChange('WrongOld1!', 'SecretNew9!');
assert_true($dest === 'password_change', 'bad current stays on password form');
assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'password.bad_current', 'bad current flash key');

$dest = pwRunChange('StartPass1!', 'short');
assert_true($dest === 'password_change', 'short new rejected');
assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'password.invalid', 'short new flash');

$dest = pwRunChange('StartPass1!', 'SecretNew9!');
assert_true($dest === 'dashboard', 'good change redirects to dashboard');
$row = fetchPanelAdminById($adminId);
assert_true($row !== null && password_verify('SecretNew9!', (string) $row['password_hash']), 'hash updated');
assert_true((int) ($row['must_change_password'] ?? 1) === 0, 'must_change cleared after self change');
assert_no_password_in_logs($GLOBALS['test_logs']);

pwReset($pdo);
$masterId = pwInsertAdmin($pdo, 'master', 'MasterPass1!', 'master');
$adminId = pwInsertAdmin($pdo, 'admin1', 'TempPass1!', 'admin');
$_SESSION['admin_id'] = $masterId;
$_SESSION['admin_username_display'] = 'master';
$_SESSION['admin_role_display'] = 'master';

$dest = pwRunReset($adminId, 'short');
assert_true($dest === 'operator_list', 'reset short password rejected');

$dest = pwRunReset($adminId, 'TempPass2!');
assert_true($dest === 'operator_list', 'reset success redirects to operator_list');
$target = fetchPanelAdminById($adminId);
assert_true($target !== null && password_verify('TempPass2!', (string) $target['password_hash']), 'reset hash stored');
assert_true((int) ($target['must_change_password'] ?? 0) === 1, 'must_change set after master reset');

$dest = pwRunReset($masterId, 'NewMaster1!');
assert_true($dest === 'operator_list', 'cannot reset master account');
assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'operator.cannot_reset', 'master reset blocked flash');

pwReset($pdo);
$adminId = pwInsertAdmin($pdo, 'admin1', 'TempPass1!', 'admin', 1);
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_username_display'] = 'admin1';
$_SESSION['admin_role_display'] = 'admin';

$GLOBALS['action'] = 'dashboard';
try {
    enforcePanelPasswordChangeRequired();
    assert_true(false, 'enforce should redirect');
} catch (PanelPasswordTestRedirect $e) {
    assert_true($e->dest === 'password_change', 'must_change gate redirects to password_change');
    assert_true(($GLOBALS['test_flash']['message'] ?? '') === 'password.must_change_required', 'gate flash');
}

assert_no_password_in_logs($GLOBALS['test_logs']);

echo "\nSummary: failures={$failures}\n";
exit($failures > 0 ? 1 : 0);
