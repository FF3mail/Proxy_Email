#!/usr/bin/env php
<?php
/**
 * Referent enable/disable round-trip smoke test (PROMPT-46A).
 * Run on VPS: php tests/panel_toggle_smoke_test.php
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

function postAction(string $action, array $fields): void
{
    global $webRoot;
    $script = escapeshellarg(__DIR__ . '/panel_post_action.php');
    $args = escapeshellarg($action);
    foreach ($fields as $k => $v) {
        $args .= ' ' . escapeshellarg($k . '=' . $v);
    }
    $env = 'PANEL_WEB_ROOT=' . escapeshellarg($webRoot);
    $cmd = "{$env} php {$script} {$args} 2>/dev/null";
    $code = 0;
    passthru($cmd, $code);
    assert_true($code === 0, "POST {$action} succeeds");
}

require_once $webRoot . '/includes/helpers.php';
$pdo = getPdo();

$row = $pdo->query('SELECT id, active FROM referents ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($row), 'referent exists for toggle test');
if (!is_array($row)) {
    exit(1);
}

$id = (int)$row['id'];
$original = (int)$row['active'];
$expected = $original === 1 ? 0 : 1;

postAction('toggle_active', [
    'entity' => 'referent',
    'id' => (string)$id,
    'return_action' => 'referent_list',
]);

$stmt = $pdo->prepare('SELECT active FROM referents WHERE id = ?');
$stmt->execute([$id]);
$after = (int)$stmt->fetchColumn();
assert_true($after === $expected, 'toggle changed referent active in database');

postAction('toggle_active', [
    'entity' => 'referent',
    'id' => (string)$id,
    'return_action' => 'referent_list',
]);

$stmt->execute([$id]);
$restored = (int)$stmt->fetchColumn();
assert_true($restored === $original, 'second toggle restored original referent active state');

exit($failures > 0 ? 1 : 0);
