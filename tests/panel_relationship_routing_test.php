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

// Legacy single client_email create path must not create incomplete relationships
assert_true(!str_contains($index, 'name="client_email"'), 'legacy client_email field removed from create');
assert_true(!str_contains($index, 'Client created for referent'), 'legacy client INSERT path removed');
assert_contains($index, 'create_no_legacy_client', 'create form points operators to relationship editor');
assert_contains($index, 'Legacy single client_email create path removed', 'save handler documents removal');

$daemon = file_get_contents($root . '/mail-proxy-daemon.py');
assert_true($daemon !== false && str_contains($daemon, 'relationship_lookup'), 'daemon uses relationship_lookup for live routing');
assert_contains(
    $daemon,
    'Quarantined _resolve_local_recipients',
    'daemon quarantines shared local_inbox recipient helper'
);
assert_contains(
    $daemon,
    'Quarantined _scan_existing_outgoing',
    'daemon quarantines referent local_outbox backlog scan'
);
assert_true(
    !preg_match('/notify_to\s*=\s*.*referent_data\.get\(\s*[\'"]local_inbox[\'"]/', $daemon),
    'disposal notify does not fall back to referents.local_inbox'
);

$card = file_get_contents($root . '/web/includes/referent_card_ui.php');
assert_true($card !== false, 'read referent_card_ui');
assert_contains($card, 'never seed from referents.local_inbox', 'suggestions do not prefill from referent local_inbox');
assert_contains($card, 'legacy_local_hint', 'Local tab demoted to legacy non-routing');

echo "OK: panel relationship routing static checks passed\n";
