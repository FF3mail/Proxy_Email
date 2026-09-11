#!/usr/bin/env php
<?php
/**
 * Render one panel action as master or admin.
 * Usage: php tests/panel_render_as_role.php <master|admin> <action> [query=value ...]
 */
declare(strict_types=1);

$webRoot = getenv('PANEL_WEB_ROOT') ?: '/var/www/mail-proxy';
chdir($webRoot);

$role = $argv[1] ?? '';
$action = $argv[2] ?? '';
if (!in_array($role, ['master', 'admin'], true) || $action === '') {
    fwrite(STDERR, "usage: panel_render_as_role.php <master|admin> <action>\n");
    exit(2);
}

$query = [];
for ($i = 3; $i < $argc; $i++) {
    if (!str_contains($argv[$i], '=')) {
        continue;
    }
    [$k, $v] = explode('=', $argv[$i], 2);
    $query[$k] = $v;
}

require_once $webRoot . '/includes/helpers.php';
$pdo = getPdo();

if ($role === 'master') {
    $admin = $pdo->query(
        "SELECT id, username, role FROM panel_admins WHERE role = 'master' AND active = 1 LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
} else {
    $admin = $pdo->query(
        "SELECT id, username, role FROM panel_admins WHERE role = 'admin' AND active = 1 ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        $testUser = 'p47renderadmin';
        $hash = password_hash('P47RenderAdmin99!', PASSWORD_DEFAULT);
        $pdo->prepare('DELETE FROM panel_admins WHERE username = ?')->execute([$testUser]);
        $pdo->prepare("INSERT INTO panel_admins (username, password_hash, role, active) VALUES (?, ?, 'admin', 1)")
            ->execute([$testUser, $hash]);
        $stmt = $pdo->prepare('SELECT id, username, role FROM panel_admins WHERE username = ?');
        $stmt->execute([$testUser]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
if (!is_array($admin)) {
    fwrite(STDERR, "no panel admin for role={$role}\n");
    exit(2);
}

startPanelSession();
$_SESSION['admin_id'] = (int)$admin['id'];
$_SESSION['admin_role_display'] = (string)$admin['role'];
$_SESSION['admin_username_display'] = (string)$admin['username'];
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$_GET = $query;
$_GET['action'] = $action;
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

ob_start();
require $webRoot . '/index.php';
echo ob_get_clean();
