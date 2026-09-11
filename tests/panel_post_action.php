#!/usr/bin/env php
<?php
/**
 * POST one authenticated panel action (helper for CRUD smoke tests).
 * Usage: php tests/panel_post_action.php toggle_active entity=referent id=2 return_action=referent_list
 */
declare(strict_types=1);

$webRoot = getenv('PANEL_WEB_ROOT') ?: '/var/www/mail-proxy';
chdir($webRoot);

$action = $argv[1] ?? '';
if ($action === '') {
    fwrite(STDERR, "action required\n");
    exit(2);
}

$post = ['action' => $action];
for ($i = 2; $i < $argc; $i++) {
    if (!str_contains($argv[$i], '=')) {
        continue;
    }
    [$k, $v] = explode('=', $argv[$i], 2);
    $post[$k] = $v;
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
$csrf = bin2hex(random_bytes(16));
$_SESSION['csrf_token'] = $csrf;
$post['csrf_token'] = $csrf;

$_GET = ['action' => $action];
$_POST = $post;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

ob_start();
try {
    require $webRoot . '/index.php';
} catch (Throwable $e) {
    ob_end_clean();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
ob_end_clean();
