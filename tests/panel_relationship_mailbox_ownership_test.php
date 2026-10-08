#!/usr/bin/env php
<?php
/**
 * Issue #88 — static checks: relationship-local mailbox ownership in panel UI.
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

$card = (string) file_get_contents($root . '/web/includes/referent_card_ui.php');
$index = (string) file_get_contents($root . '/web/index.php');
$en = (string) file_get_contents($root . '/web/lang/en.php');
$ru = (string) file_get_contents($root . '/web/lang/ru.php');
$activation = (string) file_get_contents($root . '/web/includes/referent_activation.php');

assert_true(
    str_contains($card, 'never seed from referents.local_inbox'),
    'suggestions must not seed local_referent from referent local_inbox'
);
assert_true(
    !str_contains($card, "This referent's own local_inbox is a strong suggestion"),
    'old shared-prefill comment removed'
);
assert_true(
    str_contains($card, 'legacy_local_hint'),
    'Local tab demoted with legacy hint'
);
assert_true(
    str_contains($card, 'readiness_hint'),
    'overview readiness no longer treats referent local as routing mailbox'
);
assert_true(
    !str_contains($index, 'name="client_email"'),
    'create form no longer posts legacy client_email'
);
assert_true(
    str_contains($index, 'Legacy single client_email create path removed'),
    'save handler documents removal of incomplete client create'
);
assert_true(
    str_contains($en, 'Mailbox ownership is relationship-local')
        || str_contains($en, 'relationship-local'),
    'EN copy states relationship-local ownership'
);
assert_true(
    str_contains($ru, 'Владение ящиками — на уровне связи'),
    'RU copy states relationship-local ownership'
);
assert_true(
    str_contains($activation, 'must NOT imply shared mailbox ownership'),
    'activation docs clarify kill-switch vs shared mailbox'
);

echo $failures === 0 ? "RESULT: all OK\n" : "RESULT: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
