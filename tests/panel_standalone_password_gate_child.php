#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Subprocess worker: replay standalone panel bootstrap through password gate (issue #138).
 * Invoked by tests/panel_standalone_password_gate_test.php — not for direct CI entry.
 */

$page = $argv[1] ?? '';
$mode = $argv[2] ?? '';

$allowedPages = ['logs', 'monitor', 'relationship'];
if (!in_array($page, $allowedPages, true)) {
    fwrite(STDERR, "FAIL:bad_page\n");
    exit(1);
}
if ($mode !== 'flagged' && $mode !== 'normal') {
    fwrite(STDERR, "FAIL:bad_mode\n");
    exit(1);
}

$root = dirname(__DIR__);
$web = $root . '/web';

$GLOBALS['standalone_gate_pdo'] = new PDO('sqlite::memory:');
$GLOBALS['standalone_gate_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function getPdo(): PDO
{
    return $GLOBALS['standalone_gate_pdo'];
}

function writeLog(string $message): void
{
}

function getClientIp(): string
{
    return '127.0.0.1';
}

function checkLocalNetworkAccess(): void
{
}

function startPanelSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_start();
}

$pdo = getPdo();
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
$mustChange = $mode === 'flagged' ? 1 : 0;
$hash = password_hash('TempPass1!', PASSWORD_DEFAULT);
$pdo->prepare(
    'INSERT INTO panel_admins (username, password_hash, role, active, must_change_password)
     VALUES (?, ?, ?, 1, ?)'
)->execute(['admin1', $hash, 'admin', $mustChange]);
$adminId = (int) $pdo->lastInsertId();

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
startPanelSession();
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_role_display'] = 'admin';
$_SESSION['admin_username_display'] = 'admin1';

require_once $web . '/includes/i18n.php';
initPanelI18n();
require_once $web . '/includes/auth.php';
require_once $web . '/includes/panel_migration.php';

if ($page === 'logs') {
    require_once $web . '/includes/log_viewer.php';
    require_once $web . '/includes/panel_modals.php';
    require_once $web . '/includes/panel_nav.php';
} elseif ($page === 'monitor') {
    require_once $web . '/includes/log_viewer.php';
    require_once $web . '/includes/log_tail.php';
    require_once $web . '/includes/panel_daemon_status.php';
} else {
    require_once $web . '/includes/relationship_status.php';
    require_once $web . '/includes/panel_modals.php';
    require_once $web . '/includes/panel_nav.php';
}

if (!function_exists('setFlash')) {
    fwrite(STDERR, "FAIL:setFlash_missing\n");
    exit(3);
}

sanitizeLegacyPanelSession();

try {
    requirePanelAdminWithPasswordGate();
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL:gate_throw:' . $e->getMessage() . "\n");
    exit(5);
}

if ($mode === 'flagged') {
    fwrite(STDERR, "FAIL:expected_redirect\n");
    exit(4);
}

echo "OK:gate_passed\n";
exit(0);
