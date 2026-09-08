#!/usr/bin/env php
<?php
/**
 * Render one authenticated panel action (helper for panel_list_render_test.php).
 * Usage: php tests/panel_render_action.php referent_list [query=value ...]
 */
declare(strict_types=1);

$webRoot = getenv('PANEL_WEB_ROOT') ?: '/var/www/mail-proxy';
chdir($webRoot);

$action = $argv[1] ?? '';
if ($action === '') {
    fwrite(STDERR, "action required\n");
    exit(2);
}

$query = [];
for ($i = 2; $i < $argc; $i++) {
    if (!str_contains($argv[$i], '=')) {
        continue;
    }
    [$k, $v] = explode('=', $argv[$i], 2);
    $query[$k] = $v;
}

require_once $webRoot . '/includes/helpers.php';
$pdo = getPdo();
$admin = $pdo->query(
    "SELECT id, username, role FROM panel_admins WHERE active = 1 ORDER BY role = 'master' DESC, id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($admin)) {
    fwrite(STDERR, "no active panel admin\n");
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
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

ob_start();
require $webRoot . '/index.php';
echo ob_get_clean();
