<?php
declare(strict_types=1);

/**
 * Local iRedMail / Dovecot settings shown to administrators for mail-client setup.
 * Optional override: /etc/mail-proxy/panel.conf section [local_mail].
 *
 * @return array{imap_host: string, imap_port: int, imap_encryption: string, smtp_host: string, smtp_port: int, smtp_encryption: string}
 */
function loadLocalMailClientSettings(): array
{
    $host = (string)(gethostname() ?: 'localhost');

    $settings = [
        'imap_host' => $host,
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'smtp_host' => $host,
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
    ];

    $confFile = '/etc/mail-proxy/panel.conf';
    if (!is_readable($confFile)) {
        return $settings;
    }

    $content = @file_get_contents($confFile);
    if ($content === false) {
        return $settings;
    }

    $local = parseIniSection($content, 'local_mail');
    if ($local === []) {
        return $settings;
    }

    if (!empty($local['imap_host'])) {
        $settings['imap_host'] = trim((string)$local['imap_host']);
    }
    if (!empty($local['smtp_host'])) {
        $settings['smtp_host'] = trim((string)$local['smtp_host']);
    }
    if (isset($local['imap_port']) && ctype_digit((string)$local['imap_port'])) {
        $settings['imap_port'] = (int)$local['imap_port'];
    }
    if (isset($local['smtp_port']) && ctype_digit((string)$local['smtp_port'])) {
        $settings['smtp_port'] = (int)$local['smtp_port'];
    }
    if (!empty($local['imap_encryption']) && in_array($local['imap_encryption'], ['none', 'ssl', 'tls'], true)) {
        $settings['imap_encryption'] = $local['imap_encryption'];
    }
    if (!empty($local['smtp_encryption']) && in_array($local['smtp_encryption'], ['none', 'ssl', 'tls'], true)) {
        $settings['smtp_encryption'] = $local['smtp_encryption'];
    }

    return $settings;
}

/**
 * Human-readable encryption label for mail clients.
 */
function formatMailEncryption(string $mode): string
{
    return match ($mode) {
        'ssl' => 'SSL/TLS',
        'tls' => 'STARTTLS',
        'none' => 'None',
        default => $mode,
    };
}
