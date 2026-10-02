<?php
declare(strict_types=1);

/**
 * Доверенный базовый URL веб-панели.
 * Используется для OAuth redirect_uri — НЕ строится из HTTP_HOST.
 *
 * Перед production-деплоем задайте реальный адрес панели, например:
 * define('APP_BASE_URL', 'https://mail-proxy.example.local');
 */
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', 'https://mail-proxy.local');
}

// Проверка конфигурации для production.
// Значение APP_BASE_URL обязательно должно быть заменено администратором
// на реальный публичный URL веб-панели до production-запуска,
// иначе OAuth redirect_uri будет работать некорректно.
if (
    strpos(APP_BASE_URL, 'mail-proxy.local') !== false ||
    strpos(APP_BASE_URL, 'localhost') !== false ||
    strpos(APP_BASE_URL, '127.0.0.1') !== false
) {
    error_log('[DELTA-transit] ПРЕДУПРЕЖДЕНИЕ: APP_BASE_URL содержит заглушку или локальный адрес. '
        . 'Укажите реальный публичный URL перед production-запуском.');
}

// Обратная совместимость с существующим кодом
if (!defined('PUBLIC_BASE_URL')) {
    define('PUBLIC_BASE_URL', APP_BASE_URL);
}

// Optional: TCP probe targets for the panel internet-availability chip (issue #39).
// Uncomment to replace the defaults (Cloudflare 1.1.1.1:443, Google 8.8.8.8:443, imap.gmail.com:993).
// define('PANEL_INTERNET_PROBE_TARGETS', [
//     ['host' => '1.1.1.1', 'port' => 443],
//     ['host' => '8.8.8.8', 'port' => 443],
//     ['host' => 'imap.gmail.com', 'port' => 993],
// ]);

// ---------------------------------------------------------------------------
// Mailbox verification on Referent-card save (PROMPT-80 / issue #40)
// ---------------------------------------------------------------------------
// PANEL_MAILBOX_PROBE_ALLOW_PRIVATE (bool, default false):
//   Allow IMAP/SMTP login probes to private LAN addresses (10/8, 172.16/12,
//   192.168/16, fc00::/7, 100.64/10). RCPT/MX probes never use private targets.
// define('PANEL_MAILBOX_PROBE_ALLOW_PRIVATE', true);
//
// PANEL_MAILBOX_PROBE_ALLOWED_TARGETS (list of hostnames and/or CIDRs):
//   Extra allow-list for IMAP/SMTP private targets when ALLOW_PRIVATE is false.
// define('PANEL_MAILBOX_PROBE_ALLOWED_TARGETS', ['mail.lan.example', '10.0.0.0/8']);
//
// PANEL_MAILBOX_PROBE_ALLOWED_PORTS (list of ints, default 25,143,465,587,993,2525):
// define('PANEL_MAILBOX_PROBE_ALLOWED_PORTS', [143, 465, 587, 993]);
//
// PANEL_MAILBOX_PROBE_ALLOW_PLAINTEXT_AUTH (bool, default false):
//   Permit AUTH over encryption=none toward non-loopback hosts.
// define('PANEL_MAILBOX_PROBE_ALLOW_PLAINTEXT_AUTH', true);
//
// PANEL_MAILBOX_PROBE_MAIL_FROM (string, default empty → null sender <>):
// define('PANEL_MAILBOX_PROBE_MAIL_FROM', 'probe@example.com');
//
// PANEL_MAILBOX_PROBE_EHLO_HOST (FQDN used in EHLO/HELO; default gethostname()
//   when it contains a dot, else mail-proxy.invalid):
// define('PANEL_MAILBOX_PROBE_EHLO_HOST', 'panel.example.com');
//
// PANEL_MAILBOX_PROBE_DNS_CANARY (default iana.org) — used only when MX/A/AAAA
//   all fail, to distinguish NXDOMAIN from resolver outage:
// define('PANEL_MAILBOX_PROBE_DNS_CANARY', 'iana.org');
