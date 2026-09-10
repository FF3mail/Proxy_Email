#!/usr/bin/env php
<?php
declare(strict_types=1);
$mode = $argv[1] ?? '';
$webRoot = '/var/www/mail-proxy';
chdir($webRoot);
require_once $webRoot . '/includes/helpers.php';
require_once $webRoot . '/includes/i18n.php';
require_once $webRoot . '/config.php';
require_once $webRoot . '/includes/panel_migration.php';
require_once $webRoot . '/includes/auth.php';
require_once $webRoot . '/includes/relationship_editor.php';

startPanelSession();
initPanelI18n();
$admin = getPdo()->query("SELECT id, username, role FROM panel_admins WHERE role='master' AND active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$admin) { fwrite(STDERR, "NO_MASTER\n"); exit(2);} 
$_SESSION['admin_id'] = (int)$admin['id'];
$_SESSION['admin_role_display'] = (string)$admin['role'];
$_SESSION['admin_username_display'] = (string)$admin['username'];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'panel.testvps.loc';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';

function flash_msg(string $html): string {
    if (preg_match('/bg-green-100[^>]*>(.*?)<\//s', $html, $m)) return 'SUCCESS: '.trim(strip_tags($m[1]));
    if (preg_match('/bg-red-100[^>]*>(.*?)<\//s', $html, $m)) return 'ERROR: '.trim(strip_tags($m[1]));
    return 'NO_FLASH';
}

if ($mode === 'toggle') {
    $_POST = [
        'action' => 'toggle_active',
        'csrf_token' => $_SESSION['csrf_token'],
        'entity' => $argv[2] ?? '',
        'id' => $argv[3] ?? '0',
        'return_action' => 'dashboard',
    ];
    ob_start();
    require $webRoot . '/index.php';
    echo flash_msg((string)ob_get_clean()), "\n";
    exit(0);
}

if ($mode === 'save_client2') {
    $_POST = [
        'action' => 'relationship_save',
        'csrf_token' => $_SESSION['csrf_token'],
        'id' => '2',
        'referent_id' => '3',
        'return_to' => 'backfill',
        'external_client_email' => 'external-sender@frona.ru',
        'local_client_email' => 'clientloc1@testvps.loc',
        'local_referent_email' => 'refloc1@testvps.loc',
        'external_account_id' => '1',
        'local_client_maildir' => '/var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir',
        'active' => '1',
    ];
    ob_start();
    require $webRoot . '/index.php';
    echo flash_msg((string)ob_get_clean()), "\n";
    exit(0);
}

if ($mode === 'save_bad_mailbox') {
    $_POST = [
        'action' => 'relationship_save',
        'csrf_token' => $_SESSION['csrf_token'],
        'id' => '0',
        'referent_id' => '4',
        'external_client_email' => 'badclient@partner.com',
        'local_client_email' => 'nobody@testvps.loc',
        'local_referent_email' => 'refloc2@testvps.loc',
        'external_account_id' => '',
        'local_client_maildir' => '/tmp/nowhere',
        'active' => '1',
    ];
    ob_start();
    require $webRoot . '/index.php';
    echo flash_msg((string)ob_get_clean()), "\n";
    exit(0);
}

if ($mode === 'backfill') {
    $_GET = ['action' => 'relationship_backfill'];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require $webRoot . '/index.php';
    $html = (string)ob_get_clean();
    if (preg_match('/data-testid="backfill-count"[^>]*>(.*?)<\//s', $html, $m)) {
        echo 'BACKFILL_COUNT: ', trim(strip_tags($m[1])), "\n";
    }
    preg_match_all('/data-legacy-client-id="(\d+)"/', $html, $ids);
    echo 'BACKFILL_LEGACY_IDS: ', implode(',', $ids[1] ?? []), "\n";
    exit(0);
}

fwrite(STDERR, "usage: prompt59_panel_cli_save.php toggle|save_client2|save_bad_mailbox|backfill\n");
exit(2);
