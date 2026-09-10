<?php
/**
 * Static + predicate checks for PROMPT-57 legacy relationship backfill.
 * Run: php tests/panel_legacy_backfill_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$indexPath = $root . '/web/index.php';
$helperPath = $root . '/web/includes/relationship_editor.php';
$ruPath = $root . '/web/lang/ru.php';
$enPath = $root . '/web/lang/en.php';
$daemonPath = $root . '/mail-proxy-daemon.py';

function assert_true(bool $cond, string $msg): void
{
    if ($cond) {
        echo "PASS: {$msg}\n";
        return;
    }
    echo "FAIL: {$msg}\n";
    exit(1);
}

function assert_contains(string $haystack, string $needle, string $msg): void
{
    assert_true(str_contains($haystack, $needle), $msg);
}

function assert_not_contains(string $haystack, string $needle, string $msg): void
{
    assert_true(!str_contains($haystack, $needle), $msg);
}

$index = file_get_contents($indexPath);
$helper = file_get_contents($helperPath);
$ru = file_get_contents($ruPath);
$en = file_get_contents($enPath);
$daemon = file_get_contents($daemonPath);

assert_true($index !== false && $helper !== false, 'read index + helper');
assert_true($ru !== false && $en !== false, 'read i18n');
assert_true($daemon !== false, 'read daemon');

// Task 1 — backlog route + helpers
assert_contains($index, "case 'relationship_backfill':", 'relationship_backfill route');
assert_contains($index, 'renderLegacyRelationshipBackfill()', 'backfill render wired');
assert_contains($index, "action=relationship_backfill", 'nav/link to backfill');
assert_contains($helper, 'function fetchLegacyRelationshipBacklog', 'fetchLegacyRelationshipBacklog');
assert_contains($helper, 'function renderLegacyRelationshipBackfill', 'renderLegacyRelationshipBackfill');
assert_contains($helper, 'relationshipIsLegacyOnly($row)', 'backlog reuses relationshipIsLegacyOnly');
assert_contains($helper, 'function relationshipIsLegacyOnly', 'relationshipIsLegacyOnly still defined');
assert_contains($helper, 'function relationshipMissingFields', 'relationshipMissingFields still defined');

// Task 2 — GET-only prefill into existing form (no second save path)
assert_contains($helper, 'function relationshipExternalClientFormValue', 'prefill helper');
assert_contains($index, 'relationshipExternalClientFormValue($row)', 'form uses prefill helper');
assert_contains($helper, "from=backfill", 'Migrate link uses from=backfill');
assert_contains($index, "(\$_GET['from'] ?? '') === 'backfill'", 'form reads from=backfill GET flag');
assert_contains($index, "data-prefill-source=", 'GET prefill marker on form');
assert_contains($index, "name=\"return_to\"", 'optional return_to after migrate save');
assert_contains($index, "function handleRelationshipSave():", 'single save handler retained');
assert_not_contains($index, 'function handleRelationshipMigrate', 'no separate migrate save handler');
assert_not_contains($helper, '$_SESSION[\'relationship_draft\']', 'no session draft state');
assert_not_contains($index, '$_SESSION[\'relationship_draft\']', 'no session draft in index');

// Task 3 — no bulk migrate / no naming heuristics
assert_not_contains($index . $helper, 'migrate_all', 'no migrate_all endpoint');
assert_not_contains($index . $helper, 'Migrate all', 'no Migrate all UI');
assert_not_contains($helper, 'guessLocal', 'no local-address guess helper');
assert_not_contains($helper, 'inferLocal', 'no local-address infer helper');

// i18n
assert_contains($ru, "'nav.backfill'", 'RU nav.backfill');
assert_contains($en, "'nav.backfill'", 'EN nav.backfill');
assert_contains($ru, "'backfill.count'", 'RU backfill.count');
assert_contains($en, "'backfill.count'", 'EN backfill.count');
assert_contains($en, 'still on the legacy model', 'EN progress sentence');

// Daemon unchanged for this feature
assert_not_contains($daemon, 'relationship_lookup', 'daemon still ignores relationship_lookup');
assert_not_contains($daemon, 'relationship_backfill', 'daemon has no backfill wiring');

// Predicate before/after (Task 4) — load helper functions directly
require_once $helperPath;

$legacyRow = [
    'id' => 101,
    'email' => 'legacy.client@partner.com',
    'external_client_email' => null,
    'local_client_email' => null,
    'local_referent_email' => null,
    'external_account_id' => null,
    'local_client_maildir' => null,
    'active' => 1,
];
$otherLegacy = [
    'id' => 102,
    'email' => 'other@partner.com',
    'external_client_email' => '',
    'local_client_email' => '',
    'local_referent_email' => '',
    'external_account_id' => null,
    'local_client_maildir' => '',
    'active' => 1,
];
$completeRow = [
    'id' => 200,
    'email' => 'done@partner.com',
    'external_client_email' => 'done@partner.com',
    'local_client_email' => 'done@local.loc',
    'local_referent_email' => 'ref@local.loc',
    'external_account_id' => 7,
    'local_client_maildir' => '/var/vmail/local.loc/done/',
    'active' => 1,
];

assert_true(relationshipIsLegacyOnly($legacyRow), 'legacy row is legacy-only');
assert_true(relationshipIsLegacyOnly($otherLegacy), 'second legacy row is legacy-only');
assert_true(!relationshipIsLegacyOnly($completeRow), 'complete row is not legacy-only');

$prefill = relationshipExternalClientFormValue($legacyRow);
assert_true($prefill === 'legacy.client@partner.com', 'prefill carries legacy email');

$blankLocals = relationshipExternalClientFormValue([
    'email' => '',
    'external_client_email' => '',
    'local_client_email' => '',
    'local_referent_email' => '',
    'external_account_id' => null,
    'local_client_maildir' => '',
]);
assert_true($blankLocals === '', 'empty row has empty prefill');

// Simulate backlog filter before migration
$before = [$legacyRow, $otherLegacy, $completeRow];
$beforeLegacy = array_values(array_filter($before, 'relationshipIsLegacyOnly'));
assert_true(count($beforeLegacy) === 2, 'before: 2 of 3 still legacy');

// After operator saves #101 via handleRelationshipSave (four-address filled)
$migrated = $legacyRow;
$migrated['external_client_email'] = 'legacy.client@partner.com';
$migrated['local_client_email'] = 'legacy.client@local.loc';
$migrated['local_referent_email'] = 'ref1@local.loc';
$migrated['external_account_id'] = 3;
$migrated['local_client_maildir'] = '/var/vmail/local.loc/legacy.client/';
$migrated['email'] = 'legacy.client@partner.com';

assert_true(!relationshipIsLegacyOnly($migrated), 'after save: migrated row leaves backlog predicate');

$after = [$migrated, $otherLegacy, $completeRow];
$afterLegacy = array_values(array_filter($after, 'relationshipIsLegacyOnly'));
assert_true(count($afterLegacy) === 1, 'after: 1 of 3 still legacy (N-1)');
assert_true((int)$afterLegacy[0]['id'] === 102, 'after: remaining legacy is the unsaved row');

echo "OK: panel legacy backfill checks passed\n";
