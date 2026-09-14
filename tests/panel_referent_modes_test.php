#!/usr/bin/env php
<?php
/**
 * PROMPT-73 panel referent mode helper tests.
 */
declare(strict_types=1);

require_once __DIR__ . '/../web/includes/i18n.php';
initPanelI18n();
require_once __DIR__ . '/../web/includes/referent_modes.php';

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$effective = computeReferentEffectiveModesFromGlobals(
    null,
    null,
    'relationship_only',
    'shadow',
    'shadow',
    'dual'
);
assert_true($effective['watch'] === 'referent_only', 'fail-closed watch');

$parsed = parseReferentEffectiveModesFromLog([
    '2026-09-11 10:00:00 [INFO] [REFERENT_EFFECTIVE_MODES] referent_id=3 inbound=shadow outbound=relationship_live watch=dual',
]);
assert_true(
    $parsed[3]['outbound'] === 'relationship_live',
    'log parse referent effective modes'
);

echo "panel_referent_modes_test: OK\n";
