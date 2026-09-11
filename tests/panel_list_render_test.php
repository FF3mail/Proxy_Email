#!/usr/bin/env php
<?php
/**
 * Authenticated panel list render smoke test (PROMPT-46A).
 * Run on VPS: php tests/panel_list_render_test.php
 */
declare(strict_types=1);

$webRoot = getenv('PANEL_WEB_ROOT') ?: '/var/www/mail-proxy';
$failures = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
    } else {
        echo "OK: {$msg}\n";
    }
}

function renderAction(string $action, array $query = []): string
{
    global $webRoot;
    $script = escapeshellarg(__DIR__ . '/panel_render_action.php');
    $args = escapeshellarg($action);
    foreach ($query as $k => $v) {
        $args .= ' ' . escapeshellarg($k . '=' . $v);
    }
    $env = 'PANEL_WEB_ROOT=' . escapeshellarg($webRoot);
    $cmd = "{$env} php {$script} {$args}";
    $output = shell_exec($cmd);
    return is_string($output) ? $output : '';
}

require_once $webRoot . '/includes/helpers.php';
$pdo = getPdo();

$referentCount = (int)$pdo->query('SELECT COUNT(*) FROM referents')->fetchColumn();
$accountCount = (int)$pdo->query('SELECT COUNT(*) FROM external_accounts')->fetchColumn();

$referentHtml = renderAction('referent_list');
assert_true($referentHtml !== '', 'referent_list renders output');
assert_true(
    str_contains($referentHtml, 'Референтов пока нет') || str_contains($referentHtml, 'local_inbox'),
    'referent_list shows empty state or referent table'
);

if ($referentCount > 0) {
    $row = $pdo->query('SELECT id, local_inbox FROM referents ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($row), 'sample referent row exists');
    if (is_array($row)) {
        assert_true(
            str_contains($referentHtml, (string)$row['local_inbox']),
            'referent_list includes persisted local_inbox'
        );
        assert_true(
            str_contains($referentHtml, 'action=referent_form&id=' . (int)$row['id']),
            'referent_list propagates edit link id'
        );

        $viewHtml = renderAction('referent_view', ['id' => (string)(int)$row['id']]);
        assert_true($viewHtml !== '', 'referent_view renders output');
        assert_true(
            str_contains($viewHtml, (string)$row['local_inbox']),
            'referent_view shows persisted referent'
        );
    }
}

$accountHtml = renderAction('account_list');
assert_true($accountHtml !== '', 'account_list renders output');
assert_true(
    $accountCount === 0
        ? str_contains($accountHtml, 'Внешних аккаунтов нет')
        : str_contains($accountHtml, 'action=account_form'),
    $accountCount === 0 ? 'account_list empty state when no records' : 'account_list shows account rows'
);

echo "referents={$referentCount} external_accounts={$accountCount}\n";
exit($failures > 0 ? 1 : 0);
