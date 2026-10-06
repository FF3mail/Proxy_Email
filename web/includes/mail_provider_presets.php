<?php
declare(strict_types=1);

require_once __DIR__ . '/mailbox_verify.php';

/**
 * Public provider presets — values verified against vendor documentation (2026-10-06).
 *
 * @return array<string, array{
 *   label_key: string,
 *   auth_hint_key?: string,
 *   doc_url: string,
 *   imap_host: string,
 *   imap_port: int,
 *   imap_encryption: string,
 *   smtp_host: string,
 *   smtp_port: int,
 *   smtp_encryption: string
 * }>
 */
function mailProviderPresets(): array
{
    return [
        'gmail' => [
            'label_key' => 'mail_preset.gmail',
            'auth_hint_key' => 'mail_preset.gmail_auth_hint',
            'doc_url' => 'https://support.google.com/mail/answer/7126229',
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ],
        'yandex' => [
            'label_key' => 'mail_preset.yandex',
            'doc_url' => 'https://yandex.com/support/yandex-360/customers/mail/en/mail-clients/others',
            'imap_host' => 'imap.yandex.ru',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.yandex.ru',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ],
        'mailru' => [
            'label_key' => 'mail_preset.mailru',
            'doc_url' => 'https://help.mail.ru/mail/mailbox/settings/imap-smtp/',
            'imap_host' => 'imap.mail.ru',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.mail.ru',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
        ],
        'microsoft365' => [
            'label_key' => 'mail_preset.microsoft365',
            'auth_hint_key' => 'mail_preset.microsoft365_auth_hint',
            'doc_url' => 'https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission',
            'imap_host' => 'outlook.office365.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ],
    ];
}

function mailProviderAuthHintKey(string $providerCode): ?string
{
    $presets = mailProviderPresets();
    if (!isset($presets[$providerCode])) {
        return null;
    }
    $key = $presets[$providerCode]['auth_hint_key'] ?? null;

    return is_string($key) && $key !== '' ? $key : null;
}

/**
 * @return array<string, string> value => i18n label key
 */
function mailEncryptionOptionKeys(): array
{
    return [
        'ssl' => 'mail_encryption.ssl_implicit',
        'tls' => 'mail_encryption.starttls',
        'none' => 'mail_encryption.none',
    ];
}

function mailStandardPortFor(string $protocol, string $encryption): int
{
    $enc = mailboxVerifyNormalizeEncryption($encryption, $protocol === 'imap' ? 'ssl' : 'tls');
    if ($protocol === 'imap') {
        return $enc === 'ssl' ? 993 : 143;
    }

    return match ($enc) {
        'ssl' => 465,
        'none' => 25,
        default => 587,
    };
}

function mailEncryptionSelectOptions(string $stored): array
{
    $options = mailEncryptionOptionKeys();
    $options['custom'] = 'mail_encryption.custom';

    return $options;
}

function mailEncryptionSelectUiValue(string $stored): string
{
    $stored = strtolower(trim($stored));
    $known = array_keys(mailEncryptionOptionKeys());
    if ($stored === '' || in_array($stored, $known, true)) {
        return $stored !== '' ? $stored : 'ssl';
    }

    return 'custom';
}

function mailEncryptionSubmittedValue(string $stored, string $protocolDefault): string
{
    $stored = strtolower(trim($stored));
    $known = array_keys(mailEncryptionOptionKeys());
    if ($stored === '' || !in_array($stored, $known, true)) {
        return mailboxVerifyNormalizeEncryption($stored, $protocolDefault);
    }

    return $stored;
}
