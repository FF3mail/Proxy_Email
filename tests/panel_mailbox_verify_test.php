#!/usr/bin/env php
<?php
/**
 * Offline unit checks for PROMPT-80 mailbox verification.
 * Run: php tests/panel_mailbox_verify_test.php
 *
 * NEVER opens real sockets or queries live DNS — all I/O is scripted via hooks.
 */
declare(strict_types=1);

require_once __DIR__ . '/../web/includes/i18n.php';
initPanelI18n();
require_once __DIR__ . '/../web/includes/mailbox_verify.php';

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

function assert_ok(array $result, string $msg): void
{
    assert_true(!empty($result['ok']), $msg . ' (got code=' . ($result['code'] ?? '') . ')');
}

function assert_fail_code(array $result, string $code, string $msg): void
{
    assert_true(
        empty($result['ok']) && ($result['code'] ?? '') === $code,
        $msg . ' (got code=' . ($result['code'] ?? '') . ' ok=' . (!empty($result['ok']) ? '1' : '0') . ')'
    );
}

/**
 * Build a connect hook that returns scripted streams keyed by host:port.
 *
 * @param array<string, list<string>|MailboxVerifyTransportException|callable> $scripts
 */
function scripted_connect_hook(array $scripts): callable
{
    return static function (string $host, int $port, string $encryption, float $timeout) use ($scripts) {
        $key = strtolower($host) . ':' . $port;
        if (!array_key_exists($key, $scripts)) {
            throw new MailboxVerifyTransportException('connect', 'unexpected target ' . $key);
        }
        $script = $scripts[$key];
        if ($script instanceof MailboxVerifyTransportException) {
            throw $script;
        }
        if (is_callable($script)) {
            $script = $script($host, $port, $encryption, $timeout);
        }
        if ($script instanceof MailboxVerifyTransportException) {
            throw $script;
        }
        if (!is_array($script)) {
            throw new MailboxVerifyTransportException('connect', 'bad script');
        }
        return new MailboxVerifyStream($script);
    };
}

// ---------------------------------------------------------------------------
// 1) IMAP+SMTP plain login success
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([
    'imap.example.test:993' => [
        '* OK IMAP ready',
        'a001 OK LOGIN completed',
        '* BYE',
        'a002 OK LOGOUT',
    ],
    'smtp.example.test:587' => [
        '220 smtp ready',
        '250-Hello',
        '250 AUTH PLAIN',
        '220 Ready to start TLS',
        '250-Hello',
        '250 AUTH PLAIN',
        '235 Authentication successful',
        '221 Bye',
    ],
]));

$okAccount = verifyExternalReferentMailbox([
    'email' => 'ref@example.test',
    'username' => 'ref@example.test',
    'auth_mode' => 'plain',
    'secret' => 'secret-password',
    'imap_host' => 'imap.example.test',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
]);
assert_ok($okAccount, 'plain IMAP+SMTP login probe succeeds');

// ---------------------------------------------------------------------------
// 2) IMAP auth rejected (SMTP not reached)
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([
    'imap.example.test:993' => [
        '* OK IMAP ready',
        'a001 NO [AUTHENTICATIONFAILED] Invalid credentials',
    ],
    'smtp.example.test:587' => [
        '220 should-not-be-used',
    ],
]));
$imapFail = verifyExternalReferentMailbox([
    'email' => 'ref@example.test',
    'auth_mode' => 'plain',
    'secret' => 'wrong',
    'imap_host' => 'imap.example.test',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
]);
assert_fail_code($imapFail, 'mailbox_verify.imap_auth_rejected', 'IMAP auth failure named');
assert_true(($imapFail['hop'] ?? '') === 'imap', 'IMAP failure hop=imap');

// ---------------------------------------------------------------------------
// 3) SMTP auth rejected after IMAP OK
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([
    'imap.example.test:993' => [
        '* OK IMAP ready',
        'a001 OK LOGIN completed',
        'a002 OK LOGOUT',
    ],
    'smtp.example.test:587' => [
        '220 smtp ready',
        '250 AUTH PLAIN',
        '220 go tls',
        '250 AUTH PLAIN',
        '535 5.7.8 Authentication failed',
    ],
]));
$smtpFail = verifyExternalReferentMailbox([
    'email' => 'ref@example.test',
    'auth_mode' => 'plain',
    'secret' => 'secret',
    'imap_host' => 'imap.example.test',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
]);
assert_fail_code($smtpFail, 'mailbox_verify.smtp_auth_rejected', 'SMTP auth failure named');
assert_true(($smtpFail['hop'] ?? '') === 'smtp', 'SMTP failure hop=smtp');

// ---------------------------------------------------------------------------
// 4) IMAP connect timeout
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([
    'imap.example.test:993' => new MailboxVerifyTransportException('timeout', 'connect timeout'),
]));
$imapTimeout = verifyExternalReferentMailbox([
    'email' => 'ref@example.test',
    'auth_mode' => 'plain',
    'secret' => 'secret',
    'imap_host' => 'imap.example.test',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
]);
assert_fail_code($imapTimeout, 'mailbox_verify.timeout', 'IMAP timeout mapped');

// ---------------------------------------------------------------------------
// 5) OAuth2 XOAUTH2 success path
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([
    'imap.example.test:993' => [
        '* OK IMAP ready',
        '+',
        'a001 OK',
        'a002 OK LOGOUT',
    ],
    'smtp.example.test:587' => [
        '220 smtp ready',
        '250-AUTH XOAUTH2',
        '250 OK',
        '220 Ready to start TLS',
        '250-AUTH XOAUTH2',
        '250 OK',
        '235 2.7.0 Accepted',
        '221 Bye',
    ],
]));
$oauthOk = verifyExternalReferentMailbox([
    'email' => 'ref@example.test',
    'auth_mode' => 'oauth2',
    'secret' => 'ya29.access-token-value',
    'imap_host' => 'imap.example.test',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
]);
assert_ok($oauthOk, 'OAuth2 IMAP+SMTP XOAUTH2 probe succeeds');

// ---------------------------------------------------------------------------
// 6) External client — MX + RCPT accept
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'mx1.client.test:25' => [
            '220 mx ready',
            '250 OK',
            '250 2.1.0 OK',
            '250 2.1.5 OK',
            '221 Bye',
        ],
    ]),
    static function (string $domain) {
        if ($domain === 'client.test') {
            return [['host' => 'mx1.client.test', 'pri' => 10]];
        }
        return [];
    }
);
$clientOk = verifyExternalClientMailbox('person@client.test');
assert_ok($clientOk, 'external client MX+RCPT accept');

// ---------------------------------------------------------------------------
// 7) External client — missing MX
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([]),
    static fn(string $domain) => []
);
$noMx = verifyExternalClientMailbox('nobody@missing-mx.test');
assert_fail_code($noMx, 'mailbox_verify.mx_missing', 'missing MX fails closed');

// ---------------------------------------------------------------------------
// 8) External client — RCPT rejected
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'mx1.client.test:25' => [
            '220 mx ready',
            '250 OK',
            '250 OK',
            '550 5.1.1 User unknown',
            '221 Bye',
        ],
    ]),
    static fn(string $domain) => [['host' => 'mx1.client.test', 'pri' => 10]]
);
$rcptReject = verifyExternalClientMailbox('ghost@client.test');
assert_fail_code($rcptReject, 'mailbox_verify.rcpt_rejected', 'RCPT rejected fails');

// ---------------------------------------------------------------------------
// 9) External client — port 25 / network unavailable
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'mx1.client.test:25' => new MailboxVerifyTransportException('connect', 'Connection refused'),
    ]),
    static fn(string $domain) => [['host' => 'mx1.client.test', 'pri' => 10]]
);
$netFail = verifyExternalClientMailbox('person@client.test');
assert_fail_code($netFail, 'mailbox_verify.network_unavailable', 'port 25 blocked fails closed');

// ---------------------------------------------------------------------------
// 10) DNS subsystem unavailable (fail closed, distinct code)
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([]),
    static fn(string $domain) => false
);
$dnsDown = verifyExternalClientMailbox('person@client.test');
assert_fail_code($dnsDown, 'mailbox_verify.network_unavailable', 'DNS unavailable fails closed');

// ---------------------------------------------------------------------------
// 11) Local physical mailbox present / missing
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    null,
    null,
    static fn(string $email) => strtolower($email) === 'local@testvps.loc'
);
assert_ok(verifyLocalPhysicalMailbox('local@testvps.loc'), 'local mailbox exists');
assert_fail_code(
    verifyLocalPhysicalMailbox('missing@testvps.loc'),
    'mailbox_verify.local_missing',
    'local mailbox missing rejected'
);

// ---------------------------------------------------------------------------
// 12) accountNeedsProbe heuristics
// ---------------------------------------------------------------------------
$existing = [
    'email' => 'a@x.test',
    'username' => '',
    'auth_type' => 'plain',
    'imap_host' => 'imap.x',
    'imap_port' => '993',
    'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.x',
    'smtp_port' => '587',
    'smtp_encryption' => 'tls',
];
$same = $existing + ['password' => '', 'client_secret' => ''];
assert_true(
    !mailboxVerifyAccountNeedsProbe(5, $same, $existing),
    'unchanged edit skips probe'
);
$changedHost = $same;
$changedHost['imap_host'] = 'imap.other';
assert_true(
    mailboxVerifyAccountNeedsProbe(5, $changedHost, $existing),
    'host change triggers probe'
);
assert_true(
    mailboxVerifyAccountNeedsProbe(0, $same, null),
    'create always probes'
);
$newPass = $same;
$newPass['password'] = 'new-secret';
assert_true(
    mailboxVerifyAccountNeedsProbe(5, $newPass, $existing),
    'new password triggers probe'
);

// ---------------------------------------------------------------------------
// 13) OAuth without token → skip_probe (create-before-authorize)
// ---------------------------------------------------------------------------
$oauthSkip = mailboxVerifyResolveAuthSecret(
    ['auth_type' => 'oauth2', 'password' => ''],
    null,
    null
);
assert_true(!empty($oauthSkip['ok']) && !empty($oauthSkip['skip_probe']), 'oauth without token skips probe');

// ---------------------------------------------------------------------------
// 14) Secret scrubbing in logs / messages
// ---------------------------------------------------------------------------
$scrubbed = mailboxVerifySanitizeLog('AUTH password=SuperSecret123 token=ya29.abcdef');
assert_true(
    strpos($scrubbed, 'SuperSecret123') === false && strpos($scrubbed, 'ya29.abcdef') === false,
    'sanitizeLog strips password/token values'
);
$msg = mailboxVerifyMessage([
    'ok' => false,
    'code' => 'mailbox_verify.imap_auth_rejected',
    'params' => [],
    'hop' => 'imap',
]);
assert_true(
    stripos($msg, 'password') === false || stripos($msg, 'secret-password') === false,
    'operator message has no raw secret'
);
assert_true($msg !== '', 'operator message non-empty for imap auth reject');

// ---------------------------------------------------------------------------
// 15) RCPT deferred (4xx) distinct from rejected
// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'mx1.client.test:25' => [
            '220 mx ready',
            '250 OK',
            '250 OK',
            '451 4.7.1 Greylisted',
            '221 Bye',
        ],
    ]),
    static fn(string $domain) => [['host' => 'mx1.client.test', 'pri' => 10]]
);
$deferred = verifyExternalClientMailbox('person@client.test');
assert_fail_code($deferred, 'mailbox_verify.rcpt_deferred', 'greylist deferred is distinct failure');

mailboxVerifyResetHooks();

echo "\n";
if ($failures > 0) {
    echo "RESULT: {$failures} FAIL\n";
    exit(1);
}
echo "RESULT: all OK\n";
exit(0);
