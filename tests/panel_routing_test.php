#!/usr/bin/env php
<?php
/**
 * Static regression checks for panel list routing (PROMPT-46A).
 * Run: php tests/panel_routing_test.php
 */
declare(strict_types=1);

$indexPath = __DIR__ . '/../web/index.php';
$monitorPath = __DIR__ . '/../web/monitor.php';

if (!is_readable($indexPath)) {
    fwrite(STDERR, "FAIL: cannot read {$indexPath}\n");
    exit(1);
}
if (!is_readable($monitorPath)) {
    fwrite(STDERR, "FAIL: cannot read {$monitorPath}\n");
    exit(1);
}

$index = file_get_contents($indexPath);
$monitor = file_get_contents($monitorPath);
$failures = 0;

function assert_contains(string $haystack, string $needle, string $msg): void
{
    global $failures;
    if (!str_contains($haystack, $needle)) {
        echo "FAIL: {$msg} (missing: {$needle})\n";
        $failures++;
    } else {
        echo "OK: {$msg}\n";
    }
}

function assert_not_contains(string $haystack, string $needle, string $msg): void
{
    global $failures;
    if (str_contains($haystack, $needle)) {
        echo "FAIL: {$msg} (still contains: {$needle})\n";
        $failures++;
    } else {
        echo "OK: {$msg}\n";
    }
}

// List handlers and routing cases
assert_contains($index, "function renderReferentList()", 'renderReferentList() exists');
assert_contains($index, "function renderAccountList()", 'renderAccountList() exists');
assert_contains($index, "case 'referent_list':", 'referent_list action case');
assert_contains($index, "case 'account_list':", 'account_list action case');
assert_contains($index, "renderReferentList();", 'referent_list dispatches to renderReferentList');
assert_contains($index, "renderAccountList();", 'account_list dispatches to renderAccountList');

// Navigation must target collection pages, not dashboard
assert_contains($index, 'action=referent_list', 'index nav links to referent_list');
assert_contains($index, 'action=account_list', 'index nav links to account_list');
assert_not_contains($index, "action=referents\"><?= h(__('nav.referents')) ?></a>", 'nav referents no longer points to referents alias only');
assert_not_contains($index, "action=accounts\"><?= h(__('nav.accounts')) ?></a>", 'nav accounts no longer points to accounts alias only');

// Monitor nav aligned with index
assert_contains($monitor, 'action=referent_list', 'monitor nav links to referent_list');
assert_contains($monitor, 'action=account_list', 'monitor nav links to account_list');
assert_not_contains($monitor, 'action=referents', 'monitor nav no longer uses referents action');

// CRUD row actions and return routing
assert_contains($index, 'function renderReferentRowActions(', 'referent row actions helper');
assert_contains($index, 'function renderAccountRowActions(', 'account row actions helper');
assert_contains($index, "name=\"return_action\"", 'toggle forms include return_action');
assert_contains($index, "'referent_list'", 'referent_list in allowed return actions');
assert_contains($index, "'account_list'", 'account_list in allowed return actions');

// Record ID propagation for view/edit
assert_contains($index, 'action=referent_form&id=', 'referent edit link uses id');
assert_contains($index, 'action=referent_view&id=', 'referent view link uses id');
assert_contains($index, 'action=account_form', 'account form action exists');
assert_contains($index, 'account_id', 'account_id parameter used');

// List queries hit database tables
assert_contains($index, 'FROM referents r', 'referent list queries referents table');
assert_contains($index, 'FROM external_accounts', 'account list queries external_accounts table');

exit($failures > 0 ? 1 : 0);
