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

function extract_ids(string $html): array
{
    preg_match_all('/\bid="([^"]+)"/', $html, $m);

    return $m[1] ?? [];
}

function extract_names_in_forms(string $html): array
{
    $forms = [];
    if (preg_match_all('/<form\b[^>]*>(.*?)<\/form>/is', $html, $blocks)) {
        foreach ($blocks[1] as $body) {
            preg_match_all('/\bname="([^"]+)"/', $body, $nm);
            $forms[] = $nm[1] ?? [];
        }
    }

    return $forms;
}

ob_start();
renderExternalAccountMailFields(null, 'create');
$create = (string) ob_get_clean();
ob_start();
renderExternalAccountMailFields([
    'imap_host' => 'i.example.test',
    'smtp_host' => 's.example.test',
    'imap_port' => 993,
    'smtp_port' => 465,
    'imap_encryption' => 'ssl',
    'smtp_encryption' => 'ssl',
], 'edit');
$edit = (string) ob_get_clean();

$createIds = extract_ids($create);
$editIds = extract_ids($edit);
assert_true(count($createIds) === count(array_unique($createIds)), 'create block has unique id attributes');
assert_true(count($editIds) === count(array_unique($editIds)), 'edit block has unique id attributes');
assert_true(!in_array('imap_enc_create', $editIds, true), 'create/edit encryption ids differ');

$wrapped = '<form id="x">' . $create . '</form>';
$formNames = extract_names_in_forms($wrapped);
assert_true($formNames !== [] && count($formNames[0]) === count(array_unique($formNames[0])), 'no duplicate name within one form');

assert_true(str_contains($create, 'autocomplete="off"') && str_contains($create, 'data-mail-imap-host'), 'host/port fields use autocomplete off');
assert_true(str_contains($create, 'name="imap_encryption"') && str_contains($create, 'data-mail-imap-enc-submit'), 'encryption posted via hidden input');

echo "\nRan {$checks} checks.\n";
exit($failures > 0 ? 1 : 0);
