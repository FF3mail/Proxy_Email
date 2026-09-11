#!/usr/bin/env php
<?php
/**
 * Static unit checks for relationship observability log parsing (PROMPT-69).
 * Run: php tests/panel_relationship_status_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../web/includes/relationship_status.php';

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

$sampleLines = [
    '2026-09-11 06:40:03 [INFO] (MainThread) INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=dual (requested=dual) RELATIONSHIP_LOOKUP_SHADOW=on',
    '2026-09-11 06:40:04 [INFO] (MainThread) Watchdog configured for relationship 1: /var/vmail/client1/Maildir/new',
    '2026-09-11 06:40:05 [INFO] (Thread-1) [RELATIONSHIP_SHADOW] account=a@x sender=b@y legacy=delivered legacy_rcpts=c@z lookup=matched relationship_id=1 marker=AGREE',
    '2026-09-11 06:40:06 [INFO] (Thread-1) [OUTBOUND_RELATIONSHIP_SHADOW] referent_id=1 from=c@z legacy_account_id=1 lookup_account_id=1 relationship_id=1 marker=AGREE',
    '2026-09-11 06:40:07 [WARNING] (MainThread) OUTBOUND_WATCH: relationship 2 local_client_maildir/new equals referent 1 local_outbox/new (/shared/new) — not registering duplicate Observer watch',
    '2026-09-11 06:40:08 [INFO] (Thread-1) [OUTBOUND_WATCH_DUAL] relationship_watch path=/var/vmail/client1/Maildir/new file=test.eml not_visible_via_referent_outbox',
    '2026-09-11 06:40:09 [INFO] (Thread-1) Watchdog: new email file for relationship 1 (referent 1): test.eml',
];

$parsed = parseRelationshipObservabilityFromLog($sampleLines);

assert_true(
    $parsed['startup_modes_line'] !== null
    && str_contains((string)$parsed['startup_modes_line'], 'OUTBOUND_WATCH_MODE=dual'),
    'startup modes line parsed'
);
assert_true(
    isset($parsed['inbound_shadow'][1]) && $parsed['inbound_shadow'][1]['marker'] === 'AGREE',
    'inbound shadow per relationship_id'
);
assert_true(
    isset($parsed['outbound_shadow'][1]) && $parsed['outbound_shadow'][1]['marker'] === 'AGREE',
    'outbound shadow per relationship_id'
);
assert_true(
    isset($parsed['collisions'][2]) && count($parsed['collisions'][2]) === 1,
    'collision warning from log'
);
assert_true(
    isset($parsed['dual_divergence'][1]) && count($parsed['dual_divergence'][1]) === 1,
    'dual divergence mapped via watch path'
);
assert_true(
    isset($parsed['last_file'][1]) && $parsed['last_file'][1]['file'] === 'test.eml',
    'last file seen for relationship'
);

assert_true(
    relationshipMaildirNewPath('/var/vmail/x/Maildir') === '/var/vmail/x/Maildir/new',
    'maildir new path helper'
);

$dbCollision = detectDbPathCollision(
    ['local_outbox' => '/var/vmail/ref/Maildir'],
    ['local_client_maildir' => '/var/vmail/ref/Maildir']
);
assert_true($dbCollision !== null, 'db path collision detection');

assert_true(
    normalizePanelRelationshipStatusLogLines(100) === PANEL_RELATIONSHIP_STATUS_LOG_LINES_MIN,
    'clamp min tail lines'
);
assert_true(
    normalizePanelRelationshipStatusLogLines(99999) === PANEL_RELATIONSHIP_STATUS_LOG_LINES_MAX,
    'clamp max tail lines'
);

exit($failures > 0 ? 1 : 0);
