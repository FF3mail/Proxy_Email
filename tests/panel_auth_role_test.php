#!/usr/bin/env php
<?php
/**
 * PROMPT-47: CLI role gate tests (run on VPS).
 */
declare(strict_types=1);

$webRoot = getenv('PANEL_WEB_ROOT') ?: '/var/www/mail-proxy';
require_once $webRoot . '/includes/helpers.php';
require_once $webRoot . '/includes/auth.php';

$pdo = getPdo();
$master = $pdo->query("SELECT id FROM panel_admins WHERE role='master' AND active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$master) {
    fwrite(STDERR, "FAIL: no active master\n");
    exit(1);
}

$testUser = 'p47roletest';
$hash = password_hash('P47RoleTest99!', PASSWORD_DEFAULT);
$pdo->prepare('DELETE FROM panel_admins WHERE username = ?')->execute([$testUser]);
$pdo->prepare("INSERT INTO panel_admins (username, password_hash, role, active) VALUES (?, ?, 'admin', 1)")
    ->execute([$testUser, $hash]);
$adminId = (int)$pdo->lastInsertId();

$ok = 0;
$fail = 0;

function assertTrue(bool $cond, string $label): void
{
    global $ok, $fail;
    if ($cond) {
        echo "PASS: {$label}\n";
        $ok++;
    } else {
        echo "FAIL: {$label}\n";
        $fail++;
    }
}

$row = fetchPanelAdminById((int)$master['id']);
assertTrue($row !== null && (string)$row['role'] === 'master', 'master row has role=master');

$adminRow = fetchPanelAdminById($adminId);
assertTrue($adminRow !== null && (string)$adminRow['role'] === 'admin', 'test admin has role=admin');

startPanelSession();
$_SESSION = ['admin_id' => $adminId, 'admin_role_display' => 'admin'];
assertTrue(isPanelMasterDisplay() === false, 'isPanelMasterDisplay false for admin cache');

$_SESSION['admin_role_display'] = 'master';
assertTrue(isPanelMasterDisplay() === true, 'isPanelMasterDisplay true for master cache (UI only)');

$pdo->prepare('UPDATE panel_admins SET active = 0 WHERE id = ?')->execute([$adminId]);
$inactive = fetchPanelAdminById($adminId);
assertTrue($inactive !== null && (int)$inactive['active'] === 0, 'inactive flag persisted');

$activeMaster = $pdo->query("SELECT COUNT(*) FROM panel_admins WHERE role='master' AND active=1")->fetchColumn();
assertTrue((int)$activeMaster >= 1, 'active master exists for login gate');

$pdo->prepare('DELETE FROM panel_admins WHERE id = ?')->execute([$adminId]);

echo "\nSummary: {$ok} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
