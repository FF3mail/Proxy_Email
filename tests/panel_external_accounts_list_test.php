<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ui = (string) file_get_contents($root . '/web/includes/referent_card_ui.php');
$editor = (string) file_get_contents($root . '/web/includes/relationship_editor.php');

function assert_contains(string $haystack, string $needle, string $msg): void
{
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

assert_contains($editor, 'function fetchExternalAccountsForReferentCard', 'fetch all accounts helper');
assert_contains($ui, 'fetchExternalAccountsForReferentCard', 'card uses fetch helper');
assert_contains($ui, 'id="ext-accounts-table"', 'external accounts table');
assert_contains($ui, 'Добавить ещё', 'add another button');
assert_contains($ui, 'data-account=', 'per-row account payload');
assert_contains($ui, 'ReferentCardAccounts', 'account modal JS API');
assert_contains($ui, 'LEFT JOIN external_accounts ea ON ea.referent_id = r.id', 'single-account JOIN removed', false);
if (str_contains($ui, 'LEFT JOIN external_accounts ea ON ea.referent_id = r.id')) {
    fwrite(STDERR, "FAIL: referent card still uses single-row external_accounts JOIN\n");
    exit(1);
}

echo "OK: panel external accounts list checks passed\n";
