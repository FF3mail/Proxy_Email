#!/usr/bin/env php
<?php
/**
 * PROMPT-83 lab smoke (run on VPS: PANEL_WEB_ROOT=/var/www/mail-proxy php prompt83_lab_smoke.php)
 */
declare(strict_types=1);

$webRoot = getenv('PANEL_WEB_ROOT') ?: '/var/www/mail-proxy';

$failures = 0;
function ok(bool $c, string $m): void
{
    global $failures;
    if (!$c) {
        echo "FAIL: $m\n";
        $failures++;
    } else {
        echo "OK: $m\n";
    }
}

require_once $webRoot . '/includes/helpers.php';
require_once $webRoot . '/includes/relationship_editor.php';
require_once $webRoot . '/includes/referent_activation.php';

$pdo = getPdo();
$tag = 'PROMPT83-' . gmdate('YmdHis');
$name = 'Lab smoke ' . $tag;

// C.1 — create referent without local addresses (mirrors handler: NULL, active 0)
$stmt = $pdo->prepare(
    'INSERT INTO referents (username, local_inbox, local_outbox, active) VALUES (?, NULL, NULL, 0)'
);
$stmt->execute([$name]);
$rid = (int) $pdo->lastInsertId();
ok($rid > 0, 'C.1 created referent');

$row = $pdo->prepare('SELECT local_inbox, local_outbox, active FROM referents WHERE id = ?');
$row->execute([$rid]);
$ref = $row->fetch(PDO::FETCH_ASSOC);
ok($ref['local_inbox'] === null && $ref['local_outbox'] === null, 'C.1 NULL local addresses');
ok((int) $ref['active'] === 0, 'C.1 inactive without relationship');

// C.2 — activation gate
$activation = referentResolveActiveOnSave($pdo, $rid, 1);
ok($activation['blocked'] && $activation['active'] === 0, 'C.2 cannot activate without relationship');

// C.3–C.4 — complete relationship, activate, remove, auto-deactivate
$suffix = substr(md5($tag), 0, 8);
$eaEmail = "ea{$suffix}@example.test";
$pdo->prepare(
    'INSERT INTO external_accounts (referent_id, email, imap_host, smtp_host, active)
     VALUES (?, ?, ?, ?, 1)'
)->execute([$rid, $eaEmail, 'imap.example.test', 'smtp.example.test']);
$aid = (int) $pdo->lastInsertId();
$ext = "ext{$suffix}@example.test";
$lc = "lc{$suffix}@example.test";
$lr = "lr{$suffix}@example.test";
$maildir = '/var/vmail/vmail1/example.test/p/r/' . $suffix . '/Maildir';
$pdo->prepare(
    'INSERT INTO clients (email, referent_id, external_client_email, local_client_email,
     local_referent_email, external_account_id, local_client_maildir, active)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
)->execute([$ext, $rid, $ext, $lc, $lr, $aid, $maildir]);
$pdo->prepare('UPDATE referents SET active = 1 WHERE id = ?')->execute([$rid]);
ok(referentHasActivatableRelationship($pdo, $rid), 'C.3 complete relationship');
$act = referentResolveActiveOnSave($pdo, $rid, 1);
ok($act['active'] === 1 && !$act['blocked'], 'C.3 activation allowed');
$cid = (int) $pdo->query("SELECT id FROM clients WHERE referent_id = {$rid} LIMIT 1")->fetchColumn();
$pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$cid]);
$pdo->prepare('DELETE FROM external_accounts WHERE id = ?')->execute([$aid]);
ok(referentSyncActiveAfterRelationshipChange($pdo, $rid), 'C.4 auto-deactivate after last relationship');
ok((int) $pdo->query("SELECT active FROM referents WHERE id = {$rid}")->fetchColumn() === 0, 'C.4 active=0');

ok(referentDisplayLocalInbox(null) === '—', 'C.5 display empty local_inbox');
ok(referentDisplayLocalInbox('') === '—', 'C.6 collision/display helpers tolerate empty');

$pdo->prepare('DELETE FROM referents WHERE id = ?')->execute([$rid]); // cascades clients if any left

echo $failures === 0 ? "RESULT: smoke OK\n" : "RESULT: {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
