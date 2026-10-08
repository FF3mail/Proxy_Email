#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../web/includes/i18n.php';
initPanelI18n();
require_once __DIR__ . '/../web/includes/helpers.php';
require_once __DIR__ . '/../web/includes/mailbox_verify.php';
require_once __DIR__ . '/../web/includes/mail_provider_presets.php';
require_once __DIR__ . '/../web/includes/panel_mail_account_fields.php';

$failures = 0;
$checks = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failures, $checks;
    $checks++;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
    } else {
        echo "OK: {$msg}\n";
    }
}

$knownHosts = [];
foreach (mailProviderPresets() as $code => $preset) {
    assert_true($code !== '' && isset($preset['label_key'], $preset['doc_url']), 'preset ' . $code . ' has label_key and doc_url');
    foreach (['imap_port', 'smtp_port'] as $pk) {
        $p = (int) ($preset[$pk] ?? 0);
        assert_true($p >= 1 && $p <= 65535, $code . ' ' . $pk . ' in range');
    }
    foreach (['imap_encryption', 'smtp_encryption'] as $ek) {
        $enc = mailboxVerifyNormalizeEncryption((string) ($preset[$ek] ?? ''), 'ssl');
        assert_true(in_array($enc, ['ssl', 'tls', 'none'], true), $code . ' ' . $ek . ' valid mode');
    }
    foreach (['imap_host', 'smtp_host'] as $hk) {
        $host = (string) ($preset[$hk] ?? '');
        assert_true($host !== '' && preg_match('/^[a-z0-9.-]+$/i', $host) === 1, $code . ' ' . $hk . ' plain hostname');
        assert_true(!isset($knownHosts[$host]), $code . ' host not duplicated');
        $knownHosts[$host] = true;
    }
}

assert_true(mailStandardPortFor('smtp', 'ssl') === 465, 'smtp ssl standard port');
assert_true(mailStandardPortFor('smtp', 'tls') === 587, 'smtp tls standard port');
assert_true(mailStandardPortFor('imap', 'ssl') === 993, 'imap ssl standard port');
assert_true(mailStandardPortFor('imap', 'none') === 143, 'imap none standard port');

$opts = mailEncryptionSelectOptions('ssl');
assert_true(isset($opts['ssl'], $opts['tls'], $opts['none'], $opts['custom']), 'encryption select has four options');
assert_true(mailEncryptionSelectUiValue('starttls') === 'custom', 'legacy starttls shows custom UI');
assert_true(mailEncryptionSubmittedValue('starttls', 'ssl') === 'tls', 'legacy starttls submits tls');

ob_start();
renderExternalAccountMailFields(['imap_encryption' => 'ssl', 'smtp_encryption' => 'tls', 'imap_host' => 'i', 'smtp_host' => 's', 'imap_port' => 993, 'smtp_port' => 587], 't');
$html = (string) ob_get_clean();
assert_true(str_contains($html, 'name="imap_port"') && str_contains($html, 'type="number"'), 'form renders editable imap port');
assert_true(str_contains($html, 'value="ssl"') && str_contains($html, 'data-mail-imap-encryption'), 'form preserves imap ssl selection');

echo "\nRan {$checks} checks.\n";
if ($failures > 0) {
    echo "RESULT: {$failures} FAIL\n";
    exit(1);
}
echo "RESULT: all OK\n";
exit(0);
