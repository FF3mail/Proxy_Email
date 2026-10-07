#!/usr/bin/env php
<?php
/**
 * PROMPT-83 — referent activation + optional local_inbox/outbox (offline).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
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

require_once $root . '/web/includes/relationship_editor.php';
require_once $root . '/web/includes/referent_activation.php';

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

$migration = $root . '/migrations/006_referent_local_nullable.sql';
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

$schema = (string) file_get_contents($root . '/schema.sql');
assert_true(
    preg_match('/local_inbox\s+VARCHAR\(255\)\s+UNIQUE\s+NULL/i', $schema) === 1,
    'schema.sql local_inbox nullable'
);
assert_true(
    preg_match('/local_outbox\s+VARCHAR\(255\)\s+UNIQUE\s+NULL/i', $schema) === 1,
    'schema.sql local_outbox nullable'
);

echo $failures === 0 ? "RESULT: all OK\n" : "RESULT: {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);
