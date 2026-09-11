#!/usr/bin/env php
<?php
/**
 * Unit checks for PROMPT-70 maildir path collision guard.
 * Run: php tests/panel_relationship_path_guard_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../web/includes/i18n.php';
initPanelI18n();
require_once __DIR__ . '/../web/includes/relationship_editor.php';

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

function assert_null(?string $value, string $msg): void
{
    assert_true($value === null, $msg);
}

function assert_not_null(?string $value, string $msg): void
{
    assert_true($value !== null, $msg);
}

$sharedPath = '/var/vmail/vmail1/testvps.loc/c/l/i/clientloc1/Maildir';
$referentOutbox = '/var/vmail/vmail1/testvps.loc/r/e/f/refloc1/Maildir';

// (a) two different relationships submitting the same local_client_maildir → rejected
$errA = evaluateRelationshipMaildirPathCollision(
    2,
    null,
    $sharedPath,
    [
        ['id' => 1, 'referent_id' => 1, 'local_client_maildir' => $sharedPath],
    ],
    [
        ['id' => 1, 'local_outbox' => $referentOutbox],
    ]
);
assert_not_null($errA, '(a) duplicate maildir across relationships rejected');

// (b) own referent outbox, only relationship claiming it → accepted (PROMPT-67 case)
$errB = evaluateRelationshipMaildirPathCollision(
    1,
    null,
    $referentOutbox,
    [],
    [
        ['id' => 1, 'local_outbox' => $referentOutbox],
    ]
);
assert_null($errB, '(b) single relationship may claim own referent local_outbox');

// (c) second relationship on same referent also claiming referent outbox → rejected
$errC = evaluateRelationshipMaildirPathCollision(
    1,
    null,
    $referentOutbox,
    [
        ['id' => 1, 'referent_id' => 1, 'local_client_maildir' => $referentOutbox],
    ],
    [
        ['id' => 1, 'local_outbox' => $referentOutbox],
    ]
);
assert_not_null($errC, '(c) second relationship claiming same referent outbox rejected');

// (d) trailing-slash normalization detects collision
$errD = evaluateRelationshipMaildirPathCollision(
    2,
    null,
    $sharedPath,
    [
        ['id' => 1, 'referent_id' => 1, 'local_client_maildir' => $sharedPath . '/'],
    ],
    [
        ['id' => 1, 'local_outbox' => $referentOutbox],
    ]
);
assert_not_null($errD, '(d) trailing-slash equivalence treated as collision');

// Lab VPS shape (PROMPT-61 referent_id=1, relationships id=1 and id=2) — re-save must pass
$labRefOutbox = '/var/vmail/vmail1/testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/Maildir';
$labRel1Maildir = '/var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir';
$labRel2Maildir = '/var/vmail/vmail1/testvps.loc/c/l/i/clientloc2-2026.09.09.12.26.00/Maildir';
$labClients = [
    ['id' => 1, 'referent_id' => 1, 'local_client_maildir' => $labRel1Maildir],
    ['id' => 2, 'referent_id' => 1, 'local_client_maildir' => $labRel2Maildir],
];
$labReferents = [
    ['id' => 1, 'local_outbox' => $labRefOutbox],
];
assert_null(
    evaluateRelationshipMaildirPathCollision(1, 1, $labRel1Maildir, $labClients, $labReferents),
    'lab relationship #1 re-save accepted'
);
assert_null(
    evaluateRelationshipMaildirPathCollision(1, 2, $labRel2Maildir, $labClients, $labReferents),
    'lab relationship #2 re-save accepted'
);

assert_true(
    normalizeRelationshipMaildirPath('/var/vmail//x/Maildir/') === '/var/vmail/x/Maildir',
    'normalizeRelationshipMaildirPath collapses slashes and trailing slash'
);

exit($failures > 0 ? 1 : 0);
