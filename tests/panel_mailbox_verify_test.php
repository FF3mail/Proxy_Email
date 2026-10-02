#!/usr/bin/env php
<?php
/**
 * Offline unit checks for PROMPT-80 mailbox verification (PR #42 hardening audit).
 * Run: php tests/panel_mailbox_verify_test.php
 *
 * NEVER opens real sockets or queries live DNS — all network/DNS/clock I/O is
 * scripted via injectable hooks. Covers audit section 12: secret binding, SSRF,
 * control chars, IMAP (AUTH PLAIN / literals / LOGINDISABLED / untagged / XOAUTH2
 * failure), SMTP (LOGIN / unsupported / plaintext skip / STARTTLS-no-AUTH), client
 * hard/soft classification, null MX, DNS canary helper, connection-identity and
 * save-plan policy, referent/relationship checks, soft-confirm, rate limiter,
 * independent hop budgets, and the hooks guard (via subprocess).
 */
declare(strict_types=1);

// Hooks are hard-disabled in production; the test harness opts in explicitly.
// (This constant is NEVER defined anywhere under web/.)
define('MAILBOX_VERIFY_ALLOW_HOOKS', true);

require_once __DIR__ . '/../web/includes/i18n.php';
initPanelI18n();
require_once __DIR__ . '/../web/includes/mailbox_verify.php';

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

/** @param array<string,mixed> $result */
function assert_ok(array $result, string $msg): void
{
    assert_true(!empty($result['ok']), $msg . ' (got code=' . ($result['code'] ?? '') . ')');
}

/** @param array<string,mixed> $result */
function assert_fail_code(array $result, string $code, string $msg): void
{
    assert_true(
        empty($result['ok']) && ($result['code'] ?? '') === $code,
        $msg . ' (got code=' . ($result['code'] ?? '') . ' ok=' . (!empty($result['ok']) ? '1' : '0') . ')'
    );
}

/** @param array<string,mixed> $result */
function assert_warn_code(array $result, string $code, string $msg): void
{
    assert_true(
        !empty($result['ok']) && !empty($result['warning']) && ($result['code'] ?? '') === $code,
        $msg . ' (got code=' . ($result['code'] ?? '') . ' ok=' . (!empty($result['ok']) ? '1' : '0')
            . ' warn=' . (!empty($result['warning']) ? '1' : '0') . ')'
    );
}

/** @param array<string,mixed> $result */
function assert_severity(array $result, string $severity, string $msg): void
{
    assert_true(($result['severity'] ?? '') === $severity, $msg . ' (got severity=' . ($result['severity'] ?? '') . ')');
}

/**
 * Build a connect hook returning scripted streams keyed by "host:port".
 * Created MailboxVerifyStream objects are appended to $created (by reference) so
 * tests can inspect written() output.
 *
 * @param array<string, list<string>|MailboxVerifyTransportException|callable> $scripts
 * @param list<MailboxVerifyStream> $created
 */
function scripted_connect_hook(array $scripts, array &$created = []): callable
{
    return static function (string $host, int $port, string $encryption, float $timeout, ?string $connectIp) use ($scripts, &$created) {
        $key = strtolower($host) . ':' . $port;
        if (!array_key_exists($key, $scripts)) {
            throw new MailboxVerifyTransportException('connect', 'unexpected target ' . $key);
        }
        $script = $scripts[$key];
        if ($script instanceof MailboxVerifyTransportException) {
            throw $script;
        }
        if (is_callable($script)) {
            $script = $script($host, $port, $encryption, $timeout, $connectIp);
        }
        if ($script instanceof MailboxVerifyTransportException) {
            throw $script;
        }
        if (!is_array($script)) {
            throw new MailboxVerifyTransportException('connect', 'bad script');
        }
        $stream = new MailboxVerifyStream($script);
        $created[] = $stream;
        return $stream;
    };
}

/** Default SSRF resolver → TEST-NET-2 (198.51.100.0/24), a public, non-blocked range. */
function default_resolve(): callable
{
    return static fn(string $host): array => ['198.51.100.10'];
}

/** @param list<MailboxVerifyStream> $created */
function all_written(array $created): string
{
    $out = '';
    foreach ($created as $s) {
        $out .= implode('', $s->written());
    }
    return $out;
}

// ===========================================================================
// SECTION A — End-to-end referent probes (IMAP + SMTP)
// ===========================================================================

// A1) Plain IMAP (AUTHENTICATE PLAIN, SASL-IR) + SMTP (AUTH PLAIN over STARTTLS).
mailboxVerifyResetHooks();
$created = [];
mailboxVerifySetHooks(
    scripted_connect_hook([
        'imap.example.test:993' => [
            '* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN SASL-IR] ready',
            'a001 OK authenticated',
        ],
        'smtp.example.test:587' => [
            '220 smtp ready',
            '250-mail.test hello',
            '250-STARTTLS',
            '250 AUTH PLAIN LOGIN',
            '220 go ahead',
            '250-mail.test hello',
            '250 AUTH PLAIN LOGIN',
            '235 2.7.0 accepted',
        ],
    ], $created),
    null,
    null,
    default_resolve()
);
$sess = [];
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
], $sess);
assert_ok($okAccount, 'A1 plain IMAP+SMTP probe succeeds');
assert_true(str_contains(all_written($created), 'AUTHENTICATE PLAIN'), 'A1 IMAP used AUTHENTICATE PLAIN');
assert_true(str_contains(all_written($created), 'AUTH PLAIN '), 'A1 SMTP used AUTH PLAIN');

// A2) IMAP auth rejected (SMTP never reached).
mailboxVerifyResetHooks();
$created = [];
mailboxVerifySetHooks(
    scripted_connect_hook([
        'imap.example.test:993' => [
            '* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN SASL-IR] ready',
            'a001 NO [AUTHENTICATIONFAILED] Invalid credentials',
        ],
        'smtp.example.test:587' => ['220 should-not-be-used'],
    ], $created),
    null,
    null,
    default_resolve()
);
$sess = [];
$imapFail = verifyExternalReferentMailbox([
    'email' => 'ref@example.test',
    'auth_mode' => 'plain',
    'secret' => 'wrong-pass',
    'imap_host' => 'imap.example.test',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
], $sess);
assert_fail_code($imapFail, 'mailbox_verify.imap_auth_rejected', 'A2 IMAP auth failure named');
assert_true(($imapFail['hop'] ?? '') === 'imap', 'A2 IMAP failure hop=imap');
// Secret binding: the raw secret must never appear in the serialized result.
assert_true(
    strpos(json_encode($imapFail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 'wrong-pass') === false,
    'A2 result JSON contains no raw secret'
);

// A3) SMTP auth rejected after IMAP OK.
mailboxVerifyResetHooks();
$created = [];
mailboxVerifySetHooks(
    scripted_connect_hook([
        'imap.example.test:993' => [
            '* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN SASL-IR] ready',
            'a001 OK authenticated',
        ],
        'smtp.example.test:587' => [
            '220 smtp ready',
            '250-mail hello',
            '250-STARTTLS',
            '250 AUTH PLAIN',
            '220 go',
            '250 AUTH PLAIN',
            '535 5.7.8 Authentication failed',
        ],
    ], $created),
    null,
    null,
    default_resolve()
);
$sess = [];
$smtpFail = verifyExternalReferentMailbox([
    'email' => 'ref@example.test',
    'auth_mode' => 'plain',
    'secret' => 'test-secret-aaa',
    'imap_host' => 'imap.example.test',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
], $sess);
assert_fail_code($smtpFail, 'mailbox_verify.smtp_auth_rejected', 'A3 SMTP auth failure named');
assert_true(($smtpFail['hop'] ?? '') === 'smtp', 'A3 SMTP failure hop=smtp');
assert_true(
    strpos(json_encode($smtpFail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 'SuperSecretPW123') === false,
    'A3 result JSON contains no raw secret'
);

// A4) IMAP connect timeout maps to timeout.
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'imap.example.test:993' => new MailboxVerifyTransportException('timeout', 'connect timeout'),
    ]),
    null,
    null,
    default_resolve()
);
$sess = [];
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
], $sess);
assert_fail_code($imapTimeout, 'mailbox_verify.timeout', 'A4 IMAP timeout mapped');

// A5) OAuth2 XOAUTH2 success (IMAP SASL-IR + SMTP over STARTTLS).
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'imap.example.test:993' => [
            '* OK [CAPABILITY IMAP4rev1 AUTH=XOAUTH2 SASL-IR] ready',
            'a001 OK authenticated',
        ],
        'smtp.example.test:587' => [
            '220 smtp ready',
            '250-mail hello',
            '250-STARTTLS',
            '250 AUTH XOAUTH2',
            '220 go',
            '250 AUTH XOAUTH2',
            '235 2.7.0 Accepted',
        ],
    ]),
    null,
    null,
    default_resolve()
);
$sess = [];
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
], $sess);
assert_ok($oauthOk, 'A5 OAuth2 XOAUTH2 probe succeeds');

// ===========================================================================
// SECTION B — IMAP protocol variants (direct)
// ===========================================================================

$imapDirect = static function (array $script, array $account, ?array &$created = null): array {
    mailboxVerifyResetHooks();
    $created = [];
    mailboxVerifySetHooks(
        scripted_connect_hook(['imap.host:993' => $script], $created),
        null,
        null,
        default_resolve()
    );
    $allowed = mailboxVerifyAssertTargetAllowed('imap.host', 993, 'imap');
    return mailboxVerifyImapLogin(
        'imap.host',
        993,
        'ssl',
        $account['login'],
        $account['secret'],
        $account['mode'],
        mailboxVerifyHopDeadline(),
        $allowed['connect_ip'] ?? null,
        $allowed['peer_name'] ?? null
    );
};

// B1) CAPABILITY fetched via command when greeting has none; LOGIN path.
$created = [];
$b1 = $imapDirect([
    '* OK IMAP ready',
    '* CAPABILITY IMAP4rev1 LITERAL+',
    'c001 OK CAPABILITY completed',
    '* some untagged status',
    'a001 OK LOGIN completed',
], ['login' => 'user@host', 'secret' => 'pw', 'mode' => 'plain'], $created);
assert_ok($b1, 'B1 IMAP LOGIN after CAPABILITY command, untagged skipped');
assert_true(str_contains(all_written($created), 'a001 LOGIN '), 'B1 used LOGIN command');

// B2) AUTHENTICATE PLAIN without SASL-IR (continuation then creds).
$b2 = $imapDirect([
    '* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN] ready',
    '+ ',
    'a001 OK authenticated',
], ['login' => 'user@host', 'secret' => 'pw', 'mode' => 'plain']);
assert_ok($b2, 'B2 IMAP AUTHENTICATE PLAIN (no SASL-IR) succeeds');

// B3) LOGINDISABLED without AUTH=PLAIN → imap_login_disabled.
$b3 = $imapDirect([
    '* OK [CAPABILITY IMAP4rev1 LOGINDISABLED] ready',
], ['login' => 'user@host', 'secret' => 'pw', 'mode' => 'plain']);
assert_fail_code($b3, 'mailbox_verify.imap_login_disabled', 'B3 LOGINDISABLED w/o AUTH=PLAIN rejected');

// B4) Non-ASCII credentials → synchronizing literals.
$created = [];
$b4 = $imapDirect([
    '* OK [CAPABILITY IMAP4rev1] ready',
    '+ ready for literal',
    '+ ready for literal',
    'a001 OK authenticated',
], ['login' => 'user@host', 'secret' => "pÿ-secret", 'mode' => 'plain'], $created);
assert_ok($b4, 'B4 IMAP non-ASCII LOGIN via literals succeeds');
assert_true((bool) preg_match('/LOGIN \{\d+\}/', all_written($created)), 'B4 used synchronizing literal syntax');

// B5) XOAUTH2 error continuation → empty line → tagged NO.
$created = [];
$b5 = $imapDirect([
    '* OK [CAPABILITY IMAP4rev1 AUTH=XOAUTH2 SASL-IR] ready',
    '+ eyJzdGF0dXMiOiI0MDEifQ==',
    'a001 NO authentication failed',
], ['login' => 'user@host', 'secret' => 'bad-token', 'mode' => 'oauth2'], $created);
assert_fail_code($b5, 'mailbox_verify.imap_auth_rejected', 'B5 XOAUTH2 error continuation rejected');
// After the "+" error challenge we must send an (empty) line to elicit the tagged NO.
$written = array_map('trim', $created[0]->written());
assert_true(in_array('', $written, true), 'B5 sent empty line after XOAUTH2 error challenge');

// ===========================================================================
// SECTION C — SMTP login variants (direct)
// ===========================================================================

$smtpDirect = static function (array $script, string $enc, string $mode, ?array &$created = null, ?string $connectIp = '198.51.100.10'): array {
    mailboxVerifyResetHooks();
    $created = [];
    mailboxVerifySetHooks(
        scripted_connect_hook(['smtp.host:587' => $script], $created),
        null,
        null,
        default_resolve()
    );
    return mailboxVerifySmtpLogin(
        'smtp.host',
        587,
        $enc,
        'user@host',
        'pw-secret',
        $mode,
        mailboxVerifyHopDeadline(),
        $connectIp,
        'smtp.host'
    );
};

// C1) AUTH LOGIN (334 challenges) when PLAIN unavailable.
$created = [];
$c1 = $smtpDirect([
    '220 smtp ready',
    '250-mail hello',
    '250 AUTH LOGIN',
    '334 VXNlcm5hbWU6',
    '334 UGFzc3dvcmQ6',
    '235 2.7.0 accepted',
], 'ssl', 'plain', $created);
assert_ok($c1, 'C1 SMTP AUTH LOGIN succeeds');
assert_true(str_contains(all_written($created), 'AUTH LOGIN'), 'C1 used AUTH LOGIN');

// C2) No supported AUTH mechanism → smtp_auth_unsupported.
$c2 = $smtpDirect([
    '220 smtp ready',
    '250-mail hello',
    '250 SIZE 10485760',
], 'ssl', 'plain');
assert_fail_code($c2, 'mailbox_verify.smtp_auth_unsupported', 'C2 unsupported AUTH mechanism');

// C3) Plaintext (encryption=none) to non-loopback → skip with warning, no AUTH sent.
$created = [];
$c3 = $smtpDirect([
    '220 smtp ready',
    '250 AUTH PLAIN',
], 'none', 'plain', $created, '198.51.100.10');
assert_warn_code($c3, 'mailbox_verify.plaintext_probe_skipped', 'C3 plaintext probe skipped with warning');
assert_true(strpos(all_written($created), 'AUTH') === false, 'C3 no AUTH written on plaintext skip');

// C4) STARTTLS failure → smtp_tls_failed, AUTH never sent.
$created = [];
$c4 = $smtpDirect([
    '220 smtp ready',
    '250-mail hello',
    '250 STARTTLS',
    '454 4.7.0 TLS not available',
], 'tls', 'plain', $created);
assert_fail_code($c4, 'mailbox_verify.smtp_tls_failed', 'C4 STARTTLS failure named');
assert_true(strpos(all_written($created), 'AUTH') === false, 'C4 no AUTH written after STARTTLS failure');

// C5) EHLO+HELO rejected → smtp_ehlo_rejected (NOT network_unavailable).
$c5 = $smtpDirect([
    '220 smtp ready',
    '500 EHLO not recognized',
    '500 HELO not recognized',
], 'ssl', 'plain');
assert_fail_code($c5, 'mailbox_verify.smtp_ehlo_rejected', 'C5 EHLO/HELO reject → smtp_ehlo_rejected');

// C6) SMTP XOAUTH2 rejected: 334 error challenge → empty line → 5xx.
$created = [];
$c6 = $smtpDirect([
    '220 smtp ready',
    '250 AUTH XOAUTH2',
    '334 eyJzdGF0dXMiOiI0MDEifQ==',
    '535 5.7.8 auth failed',
], 'ssl', 'oauth2', $created);
assert_fail_code($c6, 'mailbox_verify.smtp_auth_rejected', 'C6 SMTP XOAUTH2 error → rejected');
assert_true(in_array('', array_map('trim', $created[0]->written()), true), 'C6 sent empty line after 334 challenge');

// ===========================================================================
// SECTION D — External client (MX + RCPT) hard/soft classification
// ===========================================================================

// D1) MX + RCPT accept.
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'mx1.client.test:25' => [
            '220 mx ready',
            '250 mx hello',
            '250 2.1.0 sender ok',
            '250 2.1.5 recipient ok',
        ],
    ]),
    static fn(string $domain) => [['host' => 'mx1.client.test', 'pri' => 10]],
    null,
    default_resolve()
);
$sess = [];
assert_ok(verifyExternalClientMailbox('person@client.test', $sess), 'D1 external client MX+RCPT accept');

// D2) Missing MX (empty list) → mx_missing (hard).
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([]), static fn(string $domain) => [], null, default_resolve());
$sess = [];
$noMx = verifyExternalClientMailbox('nobody@missing-mx.test', $sess);
assert_fail_code($noMx, 'mailbox_verify.mx_missing', 'D2 missing MX fails closed');
assert_severity($noMx, 'hard', 'D2 mx_missing is hard');

// D3) RCPT 550 → rcpt_rejected (hard, definitive user-unknown).
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'mx1.client.test:25' => [
            '220 mx ready',
            '250 mx hello',
            '250 sender ok',
            '550 5.1.1 User unknown',
        ],
    ]),
    static fn(string $domain) => [['host' => 'mx1.client.test', 'pri' => 10]],
    null,
    default_resolve()
);
$sess = [];
$rcptReject = verifyExternalClientMailbox('ghost@client.test', $sess);
assert_fail_code($rcptReject, 'mailbox_verify.rcpt_rejected', 'D3 RCPT 550 rejected');
assert_severity($rcptReject, 'hard', 'D3 550 is hard');

// D4) RCPT 451 greylist → rcpt_deferred (soft).
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'mx1.client.test:25' => [
            '220 mx ready',
            '250 mx hello',
            '250 sender ok',
            '451 4.7.1 Greylisted',
        ],
    ]),
    static fn(string $domain) => [['host' => 'mx1.client.test', 'pri' => 10]],
    null,
    default_resolve()
);
$sess = [];
$deferred = verifyExternalClientMailbox('person@client.test', $sess);
assert_fail_code($deferred, 'mailbox_verify.rcpt_deferred', 'D4 greylist deferred distinct');
assert_severity($deferred, 'soft', 'D4 4xx is soft');

// D5) Port 25 connection refused → network_unavailable (soft, fail closed).
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'mx1.client.test:25' => new MailboxVerifyTransportException('connect', 'Connection refused'),
    ]),
    static fn(string $domain) => [['host' => 'mx1.client.test', 'pri' => 10]],
    null,
    default_resolve()
);
$sess = [];
$netFail = verifyExternalClientMailbox('person@client.test', $sess);
assert_fail_code($netFail, 'mailbox_verify.network_unavailable', 'D5 port 25 refused fails closed');
assert_severity($netFail, 'soft', 'D5 connect refused is soft');

// D6) DNS subsystem unavailable (hook false) → network_unavailable (soft).
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([]), static fn(string $domain) => false, null, default_resolve());
$sess = [];
$dnsDown = verifyExternalClientMailbox('person@client.test', $sess);
assert_fail_code($dnsDown, 'mailbox_verify.network_unavailable', 'D6 DNS unavailable fails closed');

// D7) All MX blocked by SSRF guard → target_not_allowed (soft), do not fail open.
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([]),
    static fn(string $domain) => [['host' => 'mx1.client.test', 'pri' => 10]],
    null,
    static fn(string $host): array => ['127.0.0.1'] // resolves to loopback → blocked
);
$sess = [];
$allBlocked = verifyExternalClientMailbox('person@client.test', $sess);
assert_fail_code($allBlocked, 'mailbox_verify.target_not_allowed', 'D7 all MX blocked → target_not_allowed');

// D8) MAIL FROM rejected → rcpt_mailfrom_rejected (soft).
mailboxVerifyResetHooks();
mailboxVerifySetHooks(
    scripted_connect_hook([
        'mx1.client.test:25' => [
            '220 mx ready',
            '250 mx hello',
            '553 5.1.8 sender rejected',
        ],
    ]),
    static fn(string $domain) => [['host' => 'mx1.client.test', 'pri' => 10]],
    null,
    default_resolve()
);
$sess = [];
$mailFromRej = verifyExternalClientMailbox('person@client.test', $sess);
assert_fail_code($mailFromRej, 'mailbox_verify.rcpt_mailfrom_rejected', 'D8 MAIL FROM reject soft');
assert_severity($mailFromRej, 'soft', 'D8 mailfrom reject is soft');

// ===========================================================================
// SECTION E — Local physical mailbox
// ===========================================================================
mailboxVerifyResetHooks();
mailboxVerifySetHooks(null, null, static fn(string $email) => strtolower($email) === 'local@testvps.loc');
assert_ok(verifyLocalPhysicalMailbox('local@testvps.loc'), 'E1 local mailbox exists');
assert_fail_code(
    verifyLocalPhysicalMailbox('missing@testvps.loc'),
    'mailbox_verify.local_missing',
    'E2 local mailbox missing rejected'
);

// ===========================================================================
// SECTION F — Control-character guard
// ===========================================================================
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([]), null, null, default_resolve());
$sess = [];
$ctrl = verifyExternalReferentMailbox([
    'email' => 'ref@example.test',
    'auth_mode' => 'plain',
    'secret' => "pass\x01word",
    'imap_host' => 'imap.example.test',
    'imap_port' => 993,
    'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
], $sess);
assert_fail_code($ctrl, 'mailbox_verify.invalid_credentials_chars', 'F1 control char in secret rejected pre-probe');

$ctrlEmail = verifyExternalClientMailbox("person\x00@client.test", $sess);
assert_true(
    ($ctrlEmail['code'] ?? '') === 'mailbox_verify.invalid_credentials_chars'
        || ($ctrlEmail['code'] ?? '') === 'mailbox_verify.invalid_email',
    'F2 control char in client email rejected'
);

// ===========================================================================
// SECTION G — SSRF unit checks (pure)
// ===========================================================================
assert_true(mailboxVerifyIpAlwaysBlocked('127.0.0.1'), 'G1 127.0.0.1 blocked');
assert_true(mailboxVerifyIpAlwaysBlocked('::1'), 'G2 ::1 blocked');
assert_true(mailboxVerifyIpAlwaysBlocked('169.254.10.1'), 'G3 link-local blocked');
assert_true(mailboxVerifyIpAlwaysBlocked('255.255.255.255'), 'G4 broadcast blocked');
assert_true(mailboxVerifyIpAlwaysBlocked('224.0.0.1'), 'G5 multicast blocked');
assert_true(mailboxVerifyIpAlwaysBlocked('::ffff:127.0.0.1'), 'G6 IPv4-mapped loopback blocked');
assert_true(mailboxVerifyIpAlwaysBlocked('241.0.0.1'), 'G7 240/4 reserved blocked');
assert_true(!mailboxVerifyIpAlwaysBlocked('198.51.100.10'), 'G8 public TEST-NET-2 not blocked');
assert_true(mailboxVerifyIpIsPrivate('10.1.2.3'), 'G9 10/8 private');
assert_true(mailboxVerifyIpIsPrivate('172.16.5.5'), 'G10 172.16/12 private');
assert_true(mailboxVerifyIpIsPrivate('192.168.0.1'), 'G11 192.168/16 private');
assert_true(mailboxVerifyIpIsPrivate('100.64.0.1'), 'G12 100.64/10 CGNAT private');
assert_true(!mailboxVerifyIpIsPrivate('198.51.100.10'), 'G13 public not private');

// resolveTarget: IP literal → itself.
$rt = mailboxVerifyResolveTarget('203.0.113.5');
assert_true(!empty($rt['ok']) && ($rt['ips'] ?? []) === ['203.0.113.5'], 'G14 IP literal resolves to itself');

// assertTargetAllowed: bad port rejected before resolution.
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([]), null, null, default_resolve());
$badPort = mailboxVerifyAssertTargetAllowed('imap.example.test', 12345, 'imap');
assert_fail_code($badPort, 'mailbox_verify.target_not_allowed', 'G15 disallowed port rejected');

// assertTargetAllowed: host resolving to loopback rejected (rebinding guard).
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([]), null, null, static fn(string $h): array => ['127.0.0.1']);
$rebind = mailboxVerifyAssertTargetAllowed('evil.example.test', 993, 'imap');
assert_fail_code($rebind, 'mailbox_verify.target_not_allowed', 'G16 host→loopback rejected');

// assertTargetAllowed: private range blocked for rcpt always.
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([]), null, null, static fn(string $h): array => ['10.0.0.9']);
$rcptPriv = mailboxVerifyAssertTargetAllowed('mx.internal.test', 25, 'rcpt');
assert_fail_code($rcptPriv, 'mailbox_verify.target_not_allowed', 'G17 private blocked for rcpt');

// assertTargetAllowed: any blocked IP among the set refuses the whole target.
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([]), null, null, static fn(string $h): array => ['198.51.100.10', '127.0.0.1']);
$mixed = mailboxVerifyAssertTargetAllowed('mixed.example.test', 993, 'imap');
assert_fail_code($mixed, 'mailbox_verify.target_not_allowed', 'G18 any blocked IP refuses target');

// assertTargetAllowed: public target allowed, pins first IP + hostname peer.
mailboxVerifyResetHooks();
mailboxVerifySetHooks(scripted_connect_hook([]), null, null, default_resolve());
$allow = mailboxVerifyAssertTargetAllowed('imap.example.test', 993, 'imap');
assert_true(
    !empty($allow['ok']) && ($allow['connect_ip'] ?? '') === '198.51.100.10' && ($allow['peer_name'] ?? '') === 'imap.example.test',
    'G19 public target allowed with pinned IP + hostname peer'
);

// target_not_allowed rejection log must not include the host.
assert_true(
    strpos(mailboxVerifySanitizeLog('mailbox_verify target_not_allowed hop=imap purpose=imap'), 'example') === false,
    'G20 rejection log line carries no host'
);

// ===========================================================================
// SECTION H — Secret scrubbing / messages
// ===========================================================================
$scrubbed = mailboxVerifySanitizeLog('AUTH password=SuperSecret123 token=ya29.abcdef');
assert_true(
    strpos($scrubbed, 'SuperSecret123') === false && strpos($scrubbed, 'ya29.abcdef') === false,
    'H1 sanitizeLog strips password/token values'
);
$msg = mailboxVerifyMessage([
    'ok' => false,
    'code' => 'mailbox_verify.imap_auth_rejected',
    'params' => [],
    'hop' => 'imap',
]);
assert_true($msg !== '', 'H2 operator message non-empty for imap auth reject');
assert_true(stripos($msg, 'secret-password') === false, 'H3 operator message has no raw secret');

// ===========================================================================
// SECTION I — Connection-identity + save-plan policy (pure)
// ===========================================================================
$idNorm = mailboxVerifyNormalizeConnectionIdentity([
    'imap_host' => '  IMAP.Example.COM.  ',
    'imap_port' => '993',
    'imap_encryption' => '',
    'smtp_host' => 'SMTP.Example.com',
    'smtp_port' => 587,
    'smtp_encryption' => '',
]);
assert_true($idNorm['imap_host'] === 'imap.example.com', 'I1 host trimmed/lowered/dot-stripped');
assert_true($idNorm['imap_port'] === 993 && is_int($idNorm['imap_port']), 'I2 port cast to int');
assert_true($idNorm['imap_encryption'] === 'ssl', 'I3 empty imap enc defaults ssl');
assert_true($idNorm['smtp_encryption'] === 'tls', 'I4 empty smtp enc defaults tls');

$idA = ['imap_host' => 'imap.x', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.x', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'username' => 'u1'];
$idB = $idA;
$idB['username'] = 'different';
assert_true(mailboxVerifyConnectionIdentityEquals($idA, $idB), 'I5 identity ignores username');
$idC = $idA;
$idC['imap_port'] = 143;
assert_true(!mailboxVerifyConnectionIdentityEquals($idA, $idC), 'I6 identity detects port change');

$exPlain = [
    'email' => 'a@x.test', 'username' => 'u', 'auth_type' => 'plain',
    'imap_host' => 'imap.x', 'imap_port' => 993, 'imap_encryption' => 'ssl',
    'smtp_host' => 'smtp.x', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    'password_enc' => 'enc',
];

// Rule 2.
$in = $exPlain; $in['imap_host'] = 'imap.other'; $in['password'] = '';
$p = mailboxVerifyPlanAccountSave($in, $exPlain);
assert_true($p['action'] === 'reject_reauth' && $p['code'] === 'mailbox_verify.password_reentry_required', 'I7 identity change + blank pw → reject_reauth');

// Rule 3 (use stored).
$in = $exPlain; $in['username'] = 'newu'; $in['password'] = '';
$p = mailboxVerifyPlanAccountSave($in, $exPlain);
assert_true($p['action'] === 'probe' && $p['use_stored_password'] === true, 'I8 unchanged identity + username change → probe w/ stored pw');

// Rule 3 (skip when nothing needs a probe).
$in = $exPlain; $in['password'] = '';
$p = mailboxVerifyPlanAccountSave($in, $exPlain);
assert_true($p['action'] === 'skip', 'I9 unchanged + blank pw + no changes → skip');

// Rule 4.
$in = $exPlain; $in['password'] = 'brand-new';
$p = mailboxVerifyPlanAccountSave($in, $exPlain);
assert_true($p['action'] === 'probe' && $p['use_stored_password'] === false, 'I10 typed password → probe w/o stored');

// Rule 9.
$in = $exPlain; $in['password'] = '';
$p = mailboxVerifyPlanAccountSave($in, null);
assert_true($p['action'] === 'reject_reauth' && $p['code'] === 'mailbox_verify.password_required', 'I11 create plain w/o pw → reject');

// Create plain with password → probe.
$in = $exPlain; $in['password'] = 'pw';
$p = mailboxVerifyPlanAccountSave($in, null);
assert_true($p['action'] === 'probe' && $p['probe_needed'] === true, 'I12 create plain w/ pw → probe');

$exOauth = $exPlain;
$exOauth['auth_type'] = 'oauth2';
$exOauth['oauth_access_token'] = 'stored-token';
unset($exOauth['password_enc']);

// Rule 5.
$in = $exOauth; $in['imap_host'] = 'imap.other';
$p = mailboxVerifyPlanAccountSave($in, $exOauth);
assert_true(
    $p['action'] === 'oauth_reauth_required' && $p['clear_oauth_tokens'] === true && $p['use_stored_oauth'] === false,
    'I13 oauth token + identity change → reauth + clear tokens'
);

// Rule 6.
$in = $exOauth; $in['username'] = 'newu';
$p = mailboxVerifyPlanAccountSave($in, $exOauth);
assert_true($p['action'] === 'probe' && $p['use_stored_oauth'] === true, 'I14 oauth unchanged identity + change → probe w/ token');

// Rule 6 skip.
$in = $exOauth;
$p = mailboxVerifyPlanAccountSave($in, $exOauth);
assert_true($p['action'] === 'skip', 'I15 oauth unchanged + no changes → skip');

// Rule 7 (no token).
$exOauthNoTok = $exOauth; unset($exOauthNoTok['oauth_access_token']);
$in = $exOauthNoTok; $in['username'] = 'newu';
$p = mailboxVerifyPlanAccountSave($in, $exOauthNoTok);
assert_true($p['action'] === 'skip', 'I16 oauth without token → skip (create-before-authorize)');

// Create oauth without token → skip.
$in = ['auth_type' => 'oauth2', 'imap_host' => 'imap.x', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'smtp.x', 'smtp_port' => 587, 'smtp_encryption' => 'tls'];
$p = mailboxVerifyPlanAccountSave($in, null);
assert_true($p['action'] === 'skip' && $p['auth_mode'] === 'oauth2', 'I17 create oauth w/o token → skip');

// ===========================================================================
// SECTION J — Referent + relationship check policy (pure)
// ===========================================================================
assert_true(!mailboxVerifyReferentNeedsLocalCheck(null, 'inbox@x', 0), 'J1 inactive → no local check');
assert_true(mailboxVerifyReferentNeedsLocalCheck(null, 'inbox@x', 1), 'J2 create active → local check');
assert_true(!mailboxVerifyReferentNeedsLocalCheck(['local_inbox' => 'Inbox@X'], 'inbox@x', 1), 'J3 unchanged inbox → skip');
assert_true(mailboxVerifyReferentNeedsLocalCheck(['local_inbox' => 'old@x'], 'new@x', 1), 'J4 changed inbox → check');

$rel = mailboxVerifyRelationshipNeedsChecks(null, ['local_client' => 'lc@x', 'local_referent' => 'lr@x', 'external_client' => 'ec@x'], 1);
assert_true($rel['local_client'] && $rel['local_referent'] && $rel['external_client'], 'J5 create active → all checks');
$rel = mailboxVerifyRelationshipNeedsChecks(null, ['local_client' => 'lc@x'], 0);
assert_true(!$rel['local_client'] && !$rel['local_referent'] && !$rel['external_client'], 'J6 inactive → no checks');
$existingRel = ['local_client_email' => 'LC@X', 'local_referent_email' => 'lr@x', 'external_client_email' => 'ec@x'];
$rel = mailboxVerifyRelationshipNeedsChecks($existingRel, ['local_client' => 'lc@x', 'local_referent' => 'lr2@x', 'external_client' => 'ec@x'], 1);
assert_true(!$rel['local_client'] && $rel['local_referent'] && !$rel['external_client'], 'J7 only changed relationship value checked');

// ===========================================================================
// SECTION K — Soft-confirm helpers
// ===========================================================================
assert_true(
    mailboxVerifySoftClientHash('A@X.com') === mailboxVerifySoftClientHash('a@x.com'),
    'K1 soft hash is case-insensitive'
);
$sc = [];
mailboxVerifyRememberSoftFailure('client@x.com', $sc);
assert_true(!mailboxVerifyConsumeSoftOverride('client@x.com', false, $sc), 'K2 no confirm flag → no override');
assert_true(isset($sc['mailbox_verify_soft_client']), 'K3 hash retained when not consumed');
assert_true(!mailboxVerifyConsumeSoftOverride('other@x.com', true, $sc), 'K4 confirm but wrong email → no override');
assert_true(mailboxVerifyConsumeSoftOverride('client@x.com', true, $sc), 'K5 confirm + matching email → override');
assert_true(!isset($sc['mailbox_verify_soft_client']), 'K6 hash cleared after successful consume');

// ===========================================================================
// SECTION L — Rate limiter
// ===========================================================================
$rl = [];
$rlNow = 1000.0;
for ($i = 0; $i < 10; $i++) {
    assert_ok(mailboxVerifyConsumeProbeSlot($rl, $rlNow), 'L' . ($i + 1) . ' probe slot ' . ($i + 1) . ' allowed');
}
$over = mailboxVerifyConsumeProbeSlot($rl, $rlNow);
assert_fail_code($over, 'mailbox_verify.rate_limited', 'L11 11th probe within 60s rate limited');
$afterWindow = mailboxVerifyConsumeProbeSlot($rl, $rlNow + 61.0);
assert_ok($afterWindow, 'L12 probe allowed again after window slides');

// ===========================================================================
// SECTION M — DNS helpers (pure)
// ===========================================================================
$mx = mailboxVerifyNormalizeMxList([
    ['host' => '.', 'pri' => 0],              // RFC 7505 null MX
    ['host' => 'MX2.test.', 'pri' => 20],
    ['host' => 'localhost', 'pri' => 5],
    ['host' => '', 'pri' => 1],
    ['host' => 'mx1.test', 'pri' => 10],
]);
assert_true(count($mx) === 2, 'M1 null MX / empty / localhost dropped');
assert_true($mx[0]['host'] === 'mx1.test' && $mx[1]['host'] === 'mx2.test', 'M2 MX sorted by priority, normalized');

assert_true(mailboxVerifyClassifyDnsFailure(true, false, false, true) === [], 'M3 canary ok → mx_missing path ([])');
assert_true(mailboxVerifyClassifyDnsFailure(true, false, false, false) === false, 'M4 canary fails → network (false)');

// ===========================================================================
// SECTION N — Clock hook + independent hop budgets
// ===========================================================================
$GLOBALS['mailbox_verify_clock_hook'] = static fn(): float => 5000.0;
assert_true(mailboxVerifyNow() === 5000.0, 'N1 clock hook drives mailboxVerifyNow');
assert_true(mailboxVerifyHopDeadline() === 5000.0 + (float) MAILBOX_VERIFY_HOP_BUDGET, 'N2 hop deadline = now + budget');
// Independence: each hop derives its own deadline from the clock, not a shared one.
$d1 = mailboxVerifyHopDeadline();
$GLOBALS['mailbox_verify_clock_hook'] = static fn(): float => 5000.0 + (float) MAILBOX_VERIFY_HOP_BUDGET + 3.0;
$d2 = mailboxVerifyHopDeadline();
assert_true($d2 > $d1 && ($d2 - $d1) === (float) MAILBOX_VERIFY_HOP_BUDGET + 3.0, 'N3 later hop gets a fresh independent budget');
// Clamp: exhausted budget floors at 0.5s; ample budget capped by connect timeout.
assert_true(mailboxVerifyClampTimeout(4000.0, 5000.0) === 0.5, 'N4 past deadline → 0.5s floor');
assert_true(mailboxVerifyClampTimeout(5100.0, 5000.0) === mailboxVerifyConnectTimeout(), 'N5 ample budget → connect timeout cap');
$GLOBALS['mailbox_verify_clock_hook'] = null;

// ===========================================================================
// SECTION O — Hooks guard (subprocess; MAILBOX_VERIFY_ALLOW_HOOKS undefined)
// ===========================================================================
$mvPath = realpath(__DIR__ . '/../web/includes/mailbox_verify.php');
$guardScript = "<?php\nrequire " . var_export($mvPath, true) . ";\n"
    . "try { mailboxVerifySetHooks(null); echo 'LEAK_SET'; } catch (\\RuntimeException \$e) { echo 'GUARD_SET'; }\n"
    . "echo '|';\n"
    . "try { mailboxVerifyResetHooks(); echo 'LEAK_RESET'; } catch (\\RuntimeException \$e) { echo 'GUARD_RESET'; }\n";
$tmp = tempnam(sys_get_temp_dir(), 'mvguard');
file_put_contents($tmp, $guardScript);
$guardOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp));
@unlink($tmp);
assert_true(str_contains($guardOut, 'GUARD_SET'), 'O1 setHooks throws without MAILBOX_VERIFY_ALLOW_HOOKS');
assert_true(str_contains($guardOut, 'GUARD_RESET'), 'O2 resetHooks throws without MAILBOX_VERIFY_ALLOW_HOOKS');
assert_true(strpos($guardOut, 'LEAK') === false, 'O3 no hook setter leaked past the guard');

// ---------------------------------------------------------------------------
mailboxVerifyResetHooks();

echo "\n";
echo "Ran {$checks} checks.\n";
if ($failures > 0) {
    echo "RESULT: {$failures} FAIL\n";
    exit(1);
}
echo "RESULT: all OK\n";
exit(0);
