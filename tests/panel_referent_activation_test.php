#!/usr/bin/env php
<?php
/**
 * PROMPT-83 — referent activation + optional local_inbox/outbox (offline).
 */
declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$webRoot = getenv('PANEL_WEB_ROOT') ?: ($repoRoot . '/web');
if (!is_file($webRoot . '/includes/relationship_editor.php')) {
    $webRoot = $repoRoot;
}
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

require_once $webRoot . '/includes/i18n.php';
if (function_exists('initPanelI18n')) {
    initPanelI18n();
}
require_once $webRoot . '/includes/relationship_editor.php';
require_once $webRoot . '/includes/referent_activation.php';

assert_true(
    referentDisplayLocalInbox(null) === '—',
    'display null local_inbox as em dash'
);
assert_true(
    referentDisplayLocalInbox('  ') === '—',
    'display blank local_inbox as em dash'
);
assert_true(
    referentDisplayLocalInbox('a@example.test') === 'a@example.test',
    'display non-empty local_inbox'
);

$completeRow = [
    'email' => 'c@example.test',
    'external_client_email' => 'ext@example.test',
    'local_client_email' => 'loc@example.test',
    'local_referent_email' => 'ref@example.test',
    'external_account_id' => 1,
    'local_client_maildir' => '/var/vmail/x/Maildir',
    'active' => 1,
    'ea_active' => 1,
    'referent_id' => 1,
    'ea_referent_id' => 1,
];
assert_true(
    relationshipIsActivatableComplete($completeRow),
    'complete active relationship is activatable'
);

$incomplete = $completeRow;
$incomplete['local_referent_email'] = '';
assert_true(
    !relationshipIsActivatableComplete($incomplete),
    'incomplete relationship is not activatable'
);

$inactiveRel = $completeRow;
$inactiveRel['active'] = 0;
assert_true(
    !relationshipIsActivatableComplete($inactiveRel),
    'inactive relationship does not count toward referent activation'
);

// Issue #88 — R-C1 / R-C2 independence at relationship completeness level.
$c1 = $completeRow;
$c1['external_client_email'] = 'e-c1@example.test';
$c1['local_client_email'] = 'l-c1@example.test';
$c1['local_referent_email'] = 'l-r1@example.test';
$c1['local_client_maildir'] = '/var/vmail/c1/Maildir';
$c1['external_account_id'] = 11;
$c1['active'] = 1;

$c2 = $completeRow;
$c2['external_client_email'] = 'e-c2@example.test';
$c2['local_client_email'] = 'l-c2@example.test';
$c2['local_referent_email'] = 'l-r2@example.test';
$c2['local_client_maildir'] = '/var/vmail/c2/Maildir';
$c2['external_account_id'] = 12;
$c2['active'] = 0;

assert_true(relationshipIsActivatableComplete($c1), 'R-C1 complete+active is activatable');
assert_true(!relationshipIsActivatableComplete($c2), 'R-C2 inactive is not activatable');
assert_true(
    relationshipIsActivatableComplete($c1) && !relationshipIsActivatableComplete($c2),
    'activating/completeness of C1 does not imply C2 activatable'
);
$c2Incomplete = $c2;
$c2Incomplete['active'] = 1;
$c2Incomplete['local_referent_email'] = '';
assert_true(
    relationshipIsActivatableComplete($c1) && !relationshipIsActivatableComplete($c2Incomplete),
    'incomplete C2 does not invalidate complete C1'
);

$legacy = [
    'email' => 'legacy@example.test',
    'external_client_email' => '',
    'local_client_email' => '',
    'local_referent_email' => '',
    'external_account_id' => null,
    'local_client_maildir' => '',
    'active' => 1,
];
assert_true(
    !relationshipIsActivatableComplete($legacy),
    'legacy-only row is not activatable'
);

$migration = $repoRoot . '/migrations/006_referent_local_nullable.sql';
if (!is_file($migration)) {
    $migration = '/tmp/006_referent_local_nullable.sql';
}
assert_true(is_file($migration), 'migration 006 exists');
$sql = (string) file_get_contents($migration);
assert_true(
    str_contains($sql, 'local_inbox') && str_contains($sql, 'NULL'),
    'migration makes local_inbox nullable'
);
assert_true(
    str_contains($sql, 'local_outbox') && str_contains($sql, 'NULL'),
    'migration makes local_outbox nullable'
);

$schemaPath = $repoRoot . '/schema.sql';
if (!is_file($schemaPath)) {
    $schemaPath = dirname($webRoot) . '/schema.sql';
}
$schema = is_file($schemaPath) ? (string) file_get_contents($schemaPath) : '';
if ($schema !== '') {
    assert_true(
        preg_match('/local_inbox\s+VARCHAR\(255\)\s+UNIQUE\s+NULL/i', $schema) === 1,
        'schema.sql local_inbox nullable'
    );
    assert_true(
        preg_match('/local_outbox\s+VARCHAR\(255\)\s+UNIQUE\s+NULL/i', $schema) === 1,
        'schema.sql local_outbox nullable'
    );
} else {
    echo "OK: skip schema.sql checks (not on panel-only path)\n";
}

echo $failures === 0 ? "RESULT: all OK\n" : "RESULT: {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);
