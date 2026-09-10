<?php
/**
 * Static checks for PROMPT-56 relationship editor wiring.
 * Run: php tests/panel_relationship_routing_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$indexPath = $root . '/web/index.php';
$helperPath = $root . '/web/includes/relationship_editor.php';
$ruPath = $root . '/web/lang/ru.php';
$enPath = $root . '/web/lang/en.php';

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

$index = file_get_contents($indexPath);
$helper = file_get_contents($helperPath);
$ru = file_get_contents($ruPath);
$en = file_get_contents($enPath);

assert_true($index !== false && $helper !== false, 'read index + helper');
assert_contains($index, "require_once __DIR__ . '/includes/relationship_editor.php';", 'index loads relationship_editor');
assert_contains($index, "case 'relationship_form':", 'relationship_form route');
assert_contains($index, "case 'relationship_save':", 'relationship_save route');
assert_contains($index, "case 'relationship_delete':", 'relationship_delete route');
assert_contains($index, "'relationship_save'", 'CSRF list includes relationship_save');
assert_contains($index, "'relationship_delete'", 'CSRF list includes relationship_delete');
assert_contains($index, 'function renderRelationshipForm():', 'renderRelationshipForm defined');
assert_contains($index, 'function handleRelationshipSave():', 'handleRelationshipSave defined');
assert_contains($index, 'renderRelationshipListSection', 'list section wired');
assert_contains($helper, 'function relationshipMissingFields', '§9 missing-fields helper');
assert_contains($helper, 'function activePhysicalMailboxExists', 'mailbox precondition helper');
assert_contains($helper, 'getVmailLookupPdo', 'reuses vmail lookup PDO');
assert_contains($helper, 'function findRelationshipUniqueCollision', 'app-layer unique check');
assert_contains($ru, 'relationship.error.mailbox_not_provisioned', 'RU i18n mailbox error');
assert_contains($en, 'relationship.error.mailbox_not_provisioned', 'EN i18n mailbox error');

// Legacy create path still posts client_email
assert_contains($index, 'name="client_email"', 'legacy client_email field retained on create');
assert_contains($index, 'Client created for referent', 'legacy client INSERT path retained');
assert_contains($index, 'Client updated for referent', 'legacy client UPDATE path retained');

$daemon = file_get_contents($root . '/mail-proxy-daemon.py');
assert_true($daemon !== false && !str_contains($daemon, 'relationship_lookup'), 'daemon still ignores relationship_lookup');

echo "OK: panel relationship routing static checks passed\n";
