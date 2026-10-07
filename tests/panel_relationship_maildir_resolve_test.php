<?php
declare(strict_types=1);

/**
 * Relationship maildir optional form + resolve helper (PROMPT-55 / panel UX).
 */

require_once __DIR__ . '/../web/includes/i18n.php';
initPanelI18n();
require_once __DIR__ . '/../web/includes/relationship_editor.php';

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$post = [
    'external_client_email' => 'ext@partner.test',
    'local_client_email' => 'client@local.test',
    'local_referent_email' => 'ref@local.test',
    'external_account_id' => '7',
    'local_client_maildir' => '',
    'active' => '1',
];
$data = parseRelationshipFormPost($post);
assert_true($data['all_filled'], 'all_filled without posted maildir');
assert_true($data['local_client_maildir'] === '', 'parsed maildir still empty before resolve');

$existing = [
    'local_client_email' => 'client@local.test',
    'local_client_maildir' => '/var/vmail/keep/Maildir',
];
$kept = resolveRelationshipClientMaildirForSave('', 'client@local.test', $existing);
assert_true($kept === '/var/vmail/keep/Maildir', 'reuse maildir when local client unchanged');

$override = resolveRelationshipClientMaildirForSave('/var/vmail/manual/Maildir', 'client@local.test', $existing);
assert_true($override === '/var/vmail/manual/Maildir', 'posted maildir wins');

echo "OK: panel relationship maildir resolve checks passed\n";
