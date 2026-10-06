<?php
declare(strict_types=1);

/**
 * Referent-card mailbox verification on save (PROMPT-80 / issue #40).
 *
 * Hardened per PR #42 audit:
 *   - Live probes (IMAP/SMTP login, MX + RCPT TO) use per-hop budgets and never
 *     echo passwords or OAuth tokens in operator messages or logs.
 *   - SSRF guard (mailboxVerifyAssertTargetAllowed) resolves once, refuses if any
 *     resolved IP is blocked/private, and pins the connection to a vetted IP with
 *     the original hostname used for TLS peer verification (no DNS rebinding).
 *   - Pure policy helpers (plan/identity/relationship/soft-confirm/rate-limit) are
 *     unit tested offline via injectable hooks.
 *
 * Test/CI hooks (mailboxVerifySetHooks / mailboxVerifyResetHooks) are HARD DISABLED
 * unless MAILBOX_VERIFY_ALLOW_HOOKS is defined. The web panel MUST never define it.
 */

/* -------------------------------------------------------------------------- */
/* Defaults                                                                    */
/* -------------------------------------------------------------------------- */

if (!defined('MAILBOX_VERIFY_CONNECT_TIMEOUT')) {
    define('MAILBOX_VERIFY_CONNECT_TIMEOUT', 5.0);
}
if (!defined('MAILBOX_VERIFY_IO_TIMEOUT')) {
    define('MAILBOX_VERIFY_IO_TIMEOUT', 5.0);
}
if (!defined('MAILBOX_VERIFY_HOP_BUDGET')) {
    define('MAILBOX_VERIFY_HOP_BUDGET', 8.0);
}
// Kept for backward compatibility with earlier callers; no longer used to bound a
// single combined probe (IMAP and SMTP now get independent hop budgets).
if (!defined('MAILBOX_VERIFY_TOTAL_BUDGET')) {
    define('MAILBOX_VERIFY_TOTAL_BUDGET', 16.0);
}

/* -------------------------------------------------------------------------- */
/* Hook storage + guarded setters                                              */
/* -------------------------------------------------------------------------- */

/** @var callable|null fn(string $host,int $port,string $encryption,float $timeout,?string $connectIp): resource|MailboxVerifyStream */
$GLOBALS['mailbox_verify_connect_hook'] = $GLOBALS['mailbox_verify_connect_hook'] ?? null;
/** @var callable|null fn(string $domain): list<array{host:string,pri:int}>|false */
$GLOBALS['mailbox_verify_dns_hook'] = $GLOBALS['mailbox_verify_dns_hook'] ?? null;
/** @var callable|null fn(string $email): bool */
$GLOBALS['mailbox_verify_local_hook'] = $GLOBALS['mailbox_verify_local_hook'] ?? null;
/** @var callable|null fn(string $host): list<string>|false */
$GLOBALS['mailbox_verify_resolve_hook'] = $GLOBALS['mailbox_verify_resolve_hook'] ?? null;
/** @var callable|null fn(): float */
$GLOBALS['mailbox_verify_clock_hook'] = $GLOBALS['mailbox_verify_clock_hook'] ?? null;

function mailboxVerifyHooksAllowed(): bool
{
    return defined('MAILBOX_VERIFY_ALLOW_HOOKS');
}

/**
 * Install test doubles. Refuses to run outside an allow-listed test harness.
 *
 * @param callable|null $connect fn(string $host,int $port,string $encryption,float $timeout,?string $connectIp): resource|MailboxVerifyStream
 * @param callable|null $dns     fn(string $domain): list<array{host:string,pri:int}>|false
 * @param callable|null $local   fn(string $email): bool
 * @param callable|null $resolve fn(string $host): list<string>|false
 */
function mailboxVerifySetHooks(
    ?callable $connect,
    ?callable $dns = null,
    ?callable $local = null,
    ?callable $resolve = null
): void {
    if (!mailboxVerifyHooksAllowed()) {
        throw new RuntimeException('mailbox_verify hooks are disabled (MAILBOX_VERIFY_ALLOW_HOOKS not defined)');
    }
    $GLOBALS['mailbox_verify_connect_hook'] = $connect;
    $GLOBALS['mailbox_verify_dns_hook'] = $dns;
    $GLOBALS['mailbox_verify_local_hook'] = $local;
    $GLOBALS['mailbox_verify_resolve_hook'] = $resolve;
}

function mailboxVerifyResetHooks(): void
{
    if (!mailboxVerifyHooksAllowed()) {
        throw new RuntimeException('mailbox_verify hooks are disabled (MAILBOX_VERIFY_ALLOW_HOOKS not defined)');
    }
    $GLOBALS['mailbox_verify_connect_hook'] = null;
    $GLOBALS['mailbox_verify_dns_hook'] = null;
    $GLOBALS['mailbox_verify_local_hook'] = null;
    $GLOBALS['mailbox_verify_resolve_hook'] = null;
    $GLOBALS['mailbox_verify_clock_hook'] = null;
}

/* -------------------------------------------------------------------------- */
/* Clock                                                                       */
/* -------------------------------------------------------------------------- */

function mailboxVerifyNow(): float
{
    $hook = $GLOBALS['mailbox_verify_clock_hook'] ?? null;
    if (is_callable($hook)) {
        return (float) $hook();
    }
    return microtime(true);
}

function mailboxVerifyHopDeadline(?float $now = null): float
{
    $now = $now ?? mailboxVerifyNow();
    return $now + (float) MAILBOX_VERIFY_HOP_BUDGET;
}

/**
 * Fractional seconds of budget left before $deadline, floored at 0.5s and capped
 * at the configured connect timeout.
 */
function mailboxVerifyClampTimeout(float $deadline, ?float $now = null): float
{
    $now = $now ?? mailboxVerifyNow();
    $remaining = $deadline - $now;
    $cap = mailboxVerifyConnectTimeout();
    if ($remaining <= 0.5) {
        return 0.5;
    }
    return min($cap, $remaining);
}

/* -------------------------------------------------------------------------- */
/* Result helpers                                                              */
/* -------------------------------------------------------------------------- */

/**
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function mailboxVerifyOk(): array
{
    return ['ok' => true, 'code' => '', 'params' => [], 'hop' => ''];
}

/**
 * @param array<string,string> $params
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, severity?:string}
 */
function mailboxVerifyFail(string $code, string $hop = '', array $params = [], ?string $severity = null): array
{
    if ($code === 'mailbox_verify.network_unavailable') {
        $detail = trim((string) ($params['detail'] ?? ''));
        $params['detail'] = $detail === '' ? '' : ' (' . $detail . ')';
    }
    $result = ['ok' => false, 'code' => $code, 'params' => $params, 'hop' => $hop];
    if ($severity !== null) {
        $result['severity'] = $severity;
    }
    return $result;
}

/**
 * A non-fatal result: probe was skipped but the save may proceed. Callers may
 * surface it as a soft warning.
 *
 * @param array<string,string> $params
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, warning:bool}
 */
function mailboxVerifyWarn(string $code, string $hop = '', array $params = []): array
{
    return ['ok' => true, 'code' => $code, 'params' => $params, 'hop' => $hop, 'warning' => true];
}

/**
 * Operator-facing message for a verify result (never includes secrets).
 *
 * @param array{ok:bool, code:string, params:array<string,string>, hop:string} $result
 */
function mailboxVerifyMessage(array $result): string
{
    if (!empty($result['ok']) && empty($result['warning'])) {
        return '';
    }
    $code = (string) ($result['code'] ?? 'mailbox_verify.failed');
    if ($code === '') {
        return '';
    }
    $params = is_array($result['params'] ?? null) ? $result['params'] : [];
    if (function_exists('__')) {
        $msg = __($code, $params);
        if (!empty($params['mode_hint']) && function_exists('__')) {
            $suffix = __('mailbox_verify.mode_hint_suffix');
            if ($suffix !== '') {
                $msg .= ' ' . $suffix;
            }
        }
        return $msg;
    }
    return $code;
}

/**
 * Strip credential material before logging.
 */
function mailboxVerifySanitizeLog(string $message): string
{
    $message = preg_replace('/\b(pass|password|passwd|pwd|secret|token|bearer|oauth)[=:\s]+\S+/i', '$1=***', $message) ?? $message;
    $message = preg_replace('/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', '[redacted-jwt]', $message) ?? $message;
    return $message;
}

function mailboxVerifyLog(string $message): void
{
    $safe = mailboxVerifySanitizeLog($message);
    if (function_exists('writeLog')) {
        writeLog($safe);
        return;
    }
    error_log('[mailbox_verify] ' . $safe);
}

function mailboxVerifyConnectTimeout(): float
{
    $n = (float) MAILBOX_VERIFY_CONNECT_TIMEOUT;
    return ($n > 0.5 && $n <= 15) ? $n : 5.0;
}

function mailboxVerifyIoTimeout(): float
{
    $n = (float) MAILBOX_VERIFY_IO_TIMEOUT;
    return ($n > 0.5 && $n <= 15) ? $n : 5.0;
}

function mailboxVerifyProbeMailFrom(): string
{
    if (defined('PANEL_MAILBOX_PROBE_MAIL_FROM') && PANEL_MAILBOX_PROBE_MAIL_FROM !== '') {
        return (string) PANEL_MAILBOX_PROBE_MAIL_FROM;
    }
    return '';
}

/* -------------------------------------------------------------------------- */
/* Pure policy: connection identity                                            */
/* -------------------------------------------------------------------------- */

/**
 * Normalize the 6 connection-identity fields (NOT username/email).
 *
 * @param array<string,mixed> $row
 * @return array{imap_host:string,imap_port:int,imap_encryption:string,smtp_host:string,smtp_port:int,smtp_encryption:string}
 */
function mailboxVerifyNormalizeConnectionIdentity(array $row): array
{
    $host = static fn(string $key): string => rtrim(strtolower(trim((string) ($row[$key] ?? ''))), '.');
    $port = static fn(string $key): int => (int) ($row[$key] ?? 0);

    $imapEnc = strtolower(trim((string) ($row['imap_encryption'] ?? '')));
    if ($imapEnc === '') {
        $imapEnc = 'ssl';
    }
    $smtpEnc = strtolower(trim((string) ($row['smtp_encryption'] ?? '')));
    if ($smtpEnc === '') {
        $smtpEnc = 'tls';
    }

    return [
        'imap_host' => $host('imap_host'),
        'imap_port' => $port('imap_port'),
        'imap_encryption' => $imapEnc,
        'smtp_host' => $host('smtp_host'),
        'smtp_port' => $port('smtp_port'),
        'smtp_encryption' => $smtpEnc,
    ];
}

/**
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 */
function mailboxVerifyConnectionIdentityEquals(array $a, array $b): bool
{
    return mailboxVerifyNormalizeConnectionIdentity($a) === mailboxVerifyNormalizeConnectionIdentity($b);
}

/**
 * Case-insensitive trimmed comparison of a scalar field between two rows.
 *
 * @param array<string,mixed> $incoming
 * @param array<string,mixed>|null $existing
 */
function mailboxVerifyFieldChanged(array $incoming, ?array $existing, string $field): bool
{
    if ($existing === null) {
        return true;
    }
    $left = strtolower(trim((string) ($incoming[$field] ?? '')));
    $right = strtolower(trim((string) ($existing[$field] ?? '')));
    return $left !== $right;
}

/**
 * Decide what to do with credentials when saving an external account.
 *
 * @param array<string,mixed> $incoming
 * @param array<string,mixed>|null $existing
 * @return array{
 *   action:string, code:string, use_stored_password:bool, use_stored_oauth:bool,
 *   clear_oauth_tokens:bool, auth_mode:string, probe_needed:bool
 * }
 */
function mailboxVerifyPlanAccountSave(array $incoming, ?array $existing): array
{
    $authMode = strtolower(trim((string) ($incoming['auth_type'] ?? 'plain')));
    if ($authMode !== 'oauth2') {
        $authMode = 'plain';
    }

    $isCreate = $existing === null;
    $identityChanged = $isCreate
        ? true
        : !mailboxVerifyConnectionIdentityEquals($incoming, $existing);

    $newPasswordTyped = trim((string) ($incoming['password'] ?? '')) !== '';
    $newClientSecretTyped = trim((string) ($incoming['client_secret'] ?? '')) !== '';

    // Rule 8: probe triggers (informational).
    $probeTriggers = $isCreate
        || $identityChanged
        || mailboxVerifyFieldChanged($incoming, $existing, 'username')
        || mailboxVerifyFieldChanged($incoming, $existing, 'email')
        || mailboxVerifyFieldChanged($incoming, $existing, 'auth_type')
        || ($authMode === 'oauth2' && mailboxVerifyFieldChanged($incoming, $existing, 'client_id'))
        || $newPasswordTyped
        || $newClientSecretTyped;

    $plan = [
        'action' => 'skip',
        'code' => '',
        'use_stored_password' => false,
        'use_stored_oauth' => false,
        'clear_oauth_tokens' => false,
        'auth_mode' => $authMode,
        'probe_needed' => $probeTriggers,
    ];

    if ($authMode === 'oauth2') {
        $tokenPresent = !$isCreate
            && (
                trim((string) ($existing['oauth_access_token'] ?? '')) !== ''
                || trim((string) ($existing['access_token_enc'] ?? '')) !== ''
            );

        // Rule 5: token present + identity changed → force re-authorization.
        if ($tokenPresent && $identityChanged) {
            $plan['action'] = 'oauth_reauth_required';
            $plan['code'] = 'mailbox_verify.oauth_reauth_required';
            $plan['clear_oauth_tokens'] = true;
            $plan['probe_needed'] = false;
            return $plan;
        }

        // Rule 7: no token → create-before-authorize, skip probe.
        if (!$tokenPresent) {
            $plan['action'] = 'skip';
            $plan['probe_needed'] = false;
            return $plan;
        }

        // Rule 6: identity unchanged + token → probe with stored token when needed.
        if ($probeTriggers) {
            $plan['action'] = 'probe';
            $plan['use_stored_oauth'] = true;
        } else {
            $plan['action'] = 'skip';
        }
        return $plan;
    }

    // Plain auth.
    if ($isCreate) {
        // Rule 9: create without password → reject.
        if (!$newPasswordTyped) {
            $plan['action'] = 'reject_reauth';
            $plan['code'] = 'mailbox_verify.password_required';
            $plan['probe_needed'] = false;
            return $plan;
        }
        // Rule 4: typed password only.
        $plan['action'] = 'probe';
        $plan['probe_needed'] = true;
        return $plan;
    }

    if ($identityChanged) {
        // Rule 2: identity changed + blank password → password re-entry required.
        if (!$newPasswordTyped) {
            $plan['action'] = 'reject_reauth';
            $plan['code'] = 'mailbox_verify.password_reentry_required';
            $plan['probe_needed'] = false;
            return $plan;
        }
        // Rule 4: typed password only.
        $plan['action'] = 'probe';
        $plan['probe_needed'] = true;
        return $plan;
    }

    // Identity unchanged.
    if ($newPasswordTyped) {
        // Rule 4: typed password only.
        $plan['action'] = 'probe';
        $plan['probe_needed'] = true;
        return $plan;
    }

    // Rule 3: blank password + unchanged identity → use stored password iff a probe
    // is otherwise needed (username/email/auth_type changed).
    if ($probeTriggers) {
        $plan['action'] = 'probe';
        $plan['use_stored_password'] = true;
    } else {
        $plan['action'] = 'skip';
    }
    return $plan;
}

/**
 * Whether the referent's local inbox needs a physical-mailbox existence check.
 *
 * @param array<string,mixed>|null $existing
 */
function mailboxVerifyReferentNeedsLocalCheck(?array $existing, string $normalizedInbox, int $active): bool
{
    if ($active === 0) {
        return false;
    }
    $inbox = strtolower(trim($normalizedInbox));
    if ($inbox === '') {
        return false;
    }
    if ($existing !== null) {
        $prev = strtolower(trim((string) ($existing['local_inbox'] ?? '')));
        if ($prev === $inbox) {
            return false;
        }
    }
    return true;
}

/**
 * Which relationship addresses need a mailbox check on save.
 *
 * @param array<string,mixed>|null $existing
 * @param array<string,mixed> $data keys: local_client, local_referent, external_client
 * @return array{local_client:bool, local_referent:bool, external_client:bool}
 */
function mailboxVerifyRelationshipNeedsChecks(?array $existing, array $data, int $active): array
{
    $map = [
        'local_client' => 'local_client_email',
        'local_referent' => 'local_referent_email',
        'external_client' => 'external_client_email',
    ];

    $out = ['local_client' => false, 'local_referent' => false, 'external_client' => false];
    if ($active !== 1) {
        return $out;
    }

    foreach ($map as $key => $existingKey) {
        $value = strtolower(trim((string) ($data[$key] ?? '')));
        if ($value === '') {
            continue;
        }
        if ($existing === null) {
            $out[$key] = true;
            continue;
        }
        $prev = strtolower(trim((string) ($existing[$existingKey] ?? '')));
        $out[$key] = ($prev !== $value);
    }

    return $out;
}

/* -------------------------------------------------------------------------- */
/* Soft-confirm helpers                                                        */
/* -------------------------------------------------------------------------- */

function mailboxVerifySoftClientHash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

/**
 * Remember that a soft (inconclusive) failure occurred for $email so the operator
 * can confirm-and-save on the next submit.
 *
 * @param array<string,mixed>|null $session When null, uses $_SESSION.
 */
function mailboxVerifyRememberSoftFailure(string $email, ?array &$session = null): void
{
    $hash = mailboxVerifySoftClientHash($email);
    if ($session === null) {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['mailbox_verify_soft_client'] = $hash;
        return;
    }
    $session['mailbox_verify_soft_client'] = $hash;
}

/**
 * True only when the confirm flag is set AND it matches the remembered soft
 * failure for this exact email. Consumes (clears) the stored hash on success.
 *
 * @param array<string,mixed>|null $session When null, uses $_SESSION.
 */
function mailboxVerifyConsumeSoftOverride(string $email, bool $confirmFlag, ?array &$session = null): bool
{
    $expected = mailboxVerifySoftClientHash($email);

    if ($session === null) {
        $stored = (string) ($_SESSION['mailbox_verify_soft_client'] ?? '');
    } else {
        $stored = (string) ($session['mailbox_verify_soft_client'] ?? '');
    }

    $match = $confirmFlag && $stored !== '' && hash_equals($stored, $expected);

    if ($match) {
        if ($session === null) {
            unset($_SESSION['mailbox_verify_soft_client']);
        } else {
            unset($session['mailbox_verify_soft_client']);
        }
    }

    return $match;
}

/* -------------------------------------------------------------------------- */
/* Rate limiting                                                               */
/* -------------------------------------------------------------------------- */

/**
 * Consume one live-probe slot. Max 10 probes per rolling 60s window. Local
 * (non-network) checks are NOT rate limited and must not call this.
 *
 * @param array<string,mixed>|null $session When null, uses $_SESSION.
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function mailboxVerifyConsumeProbeSlot(?array &$session = null, ?float $now = null): array
{
    $now = $now ?? mailboxVerifyNow();
    $window = 60.0;
    $limit = 10;

    if ($session === null) {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $times = $_SESSION['mailbox_verify_probe_times'] ?? [];
    } else {
        $times = $session['mailbox_verify_probe_times'] ?? [];
    }
    if (!is_array($times)) {
        $times = [];
    }

    $kept = [];
    foreach ($times as $t) {
        $t = (float) $t;
        if ($t > $now - $window) {
            $kept[] = $t;
        }
    }

    if (count($kept) >= $limit) {
        // Persist the pruned list so the window keeps sliding.
        if ($session === null) {
            $_SESSION['mailbox_verify_probe_times'] = $kept;
        } else {
            $session['mailbox_verify_probe_times'] = $kept;
        }
        return mailboxVerifyFail('mailbox_verify.rate_limited', 'network');
    }

    $kept[] = $now;
    if ($session === null) {
        $_SESSION['mailbox_verify_probe_times'] = $kept;
    } else {
        $session['mailbox_verify_probe_times'] = $kept;
    }

    return mailboxVerifyOk();
}

/* -------------------------------------------------------------------------- */
/* SSRF: IP classification + target allow-list                                 */
/* -------------------------------------------------------------------------- */

/**
 * If $ip is an IPv4-mapped IPv6 address (::ffff:a.b.c.d) return the embedded IPv4,
 * otherwise return the address unchanged.
 */
function mailboxVerifyCanonicalizeIp(string $ip): string
{
    $ip = trim($ip, "[]");
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return $ip;
    }
    if (strlen($bin) === 16
        && substr($bin, 0, 10) === str_repeat("\0", 10)
        && substr($bin, 10, 2) === "\xff\xff"
    ) {
        $v4 = @inet_ntop(substr($bin, 12, 4));
        if (is_string($v4)) {
            return $v4;
        }
    }
    return $ip;
}

/**
 * Custom CIDR matcher (no FILTER_FLAG_NO_* reliance). Handles both families.
 */
function mailboxVerifyIpInCidr(string $ip, string $cidr): bool
{
    $slash = strpos($cidr, '/');
    if ($slash === false) {
        // Bare address: exact match.
        $a = @inet_pton($ip);
        $b = @inet_pton($cidr);
        return $a !== false && $b !== false && $a === $b;
    }

    $network = substr($cidr, 0, $slash);
    $prefix = (int) substr($cidr, $slash + 1);

    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($network);
    if ($ipBin === false || $netBin === false) {
        return false;
    }
    if (strlen($ipBin) !== strlen($netBin)) {
        return false; // different address family
    }

    $bytes = intdiv($prefix, 8);
    $remBits = $prefix % 8;

    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    if ($remBits === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $remBits)) & 0xFF);
    return (ord($ipBin[$bytes]) & ord($mask)) === (ord($netBin[$bytes]) & ord($mask));
}

/**
 * @param list<string> $cidrs
 */
function mailboxVerifyIpInAnyCidr(string $ip, array $cidrs): bool
{
    foreach ($cidrs as $cidr) {
        if (mailboxVerifyIpInCidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

function mailboxVerifyIpAlwaysBlocked(string $ip): bool
{
    $ip = mailboxVerifyCanonicalizeIp($ip);
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return true; // unparseable → refuse
    }

    if (strlen($bin) === 4) {
        return mailboxVerifyIpInAnyCidr($ip, [
            '127.0.0.0/8',   // loopback
            '169.254.0.0/16',// link-local
            '0.0.0.0/8',     // "this" network
            '224.0.0.0/4',   // multicast
            '240.0.0.0/4',   // reserved + 255.255.255.255 broadcast
        ]);
    }

    // IPv6 (non IPv4-mapped; those were canonicalized to IPv4 above).
    return mailboxVerifyIpInAnyCidr($ip, [
        '::1/128',   // loopback
        '::/128',    // unspecified
        'fe80::/10', // link-local
        'ff00::/8',  // multicast
    ]);
}

function mailboxVerifyIpIsPrivate(string $ip): bool
{
    $ip = mailboxVerifyCanonicalizeIp($ip);
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }

    if (strlen($bin) === 4) {
        return mailboxVerifyIpInAnyCidr($ip, [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '100.64.0.0/10', // CGNAT
        ]);
    }

    return mailboxVerifyIpInAnyCidr($ip, ['fc00::/7']); // unique-local
}

function mailboxVerifyIpIsLoopback(string $ip): bool
{
    $ip = mailboxVerifyCanonicalizeIp($ip);
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    if (strlen($bin) === 4) {
        return mailboxVerifyIpInCidr($ip, '127.0.0.0/8');
    }
    return mailboxVerifyIpInCidr($ip, '::1/128');
}

/**
 * @return list<int>
 */
function mailboxVerifyAllowedPorts(): array
{
    $default = [25, 143, 465, 587, 993, 2525];
    if (!defined('PANEL_MAILBOX_PROBE_ALLOWED_PORTS')) {
        return $default;
    }
    $raw = PANEL_MAILBOX_PROBE_ALLOWED_PORTS;
    $list = is_array($raw) ? $raw : preg_split('/[,\s]+/', (string) $raw);
    $ports = [];
    foreach ($list as $p) {
        $p = (int) $p;
        if ($p > 0 && $p <= 65535) {
            $ports[] = $p;
        }
    }
    return $ports === [] ? $default : $ports;
}

function mailboxVerifyAllowPrivateProbe(): bool
{
    return defined('PANEL_MAILBOX_PROBE_ALLOW_PRIVATE') && (bool) PANEL_MAILBOX_PROBE_ALLOW_PRIVATE;
}

/**
 * @return list<string>
 */
function mailboxVerifyAllowedTargets(): array
{
    if (!defined('PANEL_MAILBOX_PROBE_ALLOWED_TARGETS')) {
        return [];
    }
    $raw = PANEL_MAILBOX_PROBE_ALLOWED_TARGETS;
    $list = is_array($raw) ? $raw : preg_split('/[,\s]+/', (string) $raw);
    $out = [];
    foreach ($list as $entry) {
        $entry = trim((string) $entry);
        if ($entry !== '') {
            $out[] = $entry;
        }
    }
    return $out;
}

/**
 * Whether a private-range target is explicitly permitted for imap/smtp probes.
 */
function mailboxVerifyPrivateAllowedForProbe(string $host, string $ip): bool
{
    if (mailboxVerifyAllowPrivateProbe()) {
        return true;
    }
    $host = strtolower(rtrim(trim($host, "[]"), '.'));
    foreach (mailboxVerifyAllowedTargets() as $entry) {
        if (str_contains($entry, '/')) {
            if (mailboxVerifyIpInCidr($ip, $entry)) {
                return true;
            }
            continue;
        }
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            if (mailboxVerifyCanonicalizeIp($entry) === mailboxVerifyCanonicalizeIp($ip)) {
                return true;
            }
            continue;
        }
        if (strtolower(rtrim($entry, '.')) === $host) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve a host to its IP set. IP literals resolve to themselves.
 *
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, ips?:list<string>, severity?:string}
 */
function mailboxVerifyResolveTarget(string $host): array
{
    $host = trim($host);
    $bare = trim($host, "[]");
    if ($bare === '') {
        return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', ['detail' => 'DNS'], 'soft');
    }

    if (filter_var($bare, FILTER_VALIDATE_IP)) {
        return ['ok' => true, 'code' => '', 'params' => [], 'hop' => '', 'ips' => [$bare]];
    }

    $hook = $GLOBALS['mailbox_verify_resolve_hook'] ?? null;
    if (is_callable($hook)) {
        $res = $hook($host);
        if ($res === false || !is_array($res)) {
            return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', ['detail' => 'DNS'], 'soft');
        }
        $ips = [];
        foreach ($res as $ip) {
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', ['detail' => 'DNS'], 'soft');
        }
        return ['ok' => true, 'code' => '', 'params' => [], 'hop' => '', 'ips' => $ips];
    }

    $ips = [];
    $a = @dns_get_record($host, DNS_A);
    if (is_array($a)) {
        foreach ($a as $r) {
            if (!empty($r['ip'])) {
                $ips[] = (string) $r['ip'];
            }
        }
    }
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
    }
    if ($ips === []) {
        return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', ['detail' => 'DNS'], 'soft');
    }
    return ['ok' => true, 'code' => '', 'params' => [], 'hop' => '', 'ips' => $ips];
}

/**
 * Assert a probe target is permitted, and (on success) pin a specific IP.
 *
 * Resolves once. If ANY resolved IP is blocked, refuses (anti-rebinding). On
 * success returns connect_ip (first allowed IP) and peer_name (original host for
 * TLS). Rejections are logged with code/hop/purpose only — never the host.
 *
 * @param string $purpose imap|smtp|rcpt
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, ips?:list<string>, connect_ip?:string, peer_name?:string, severity?:string}
 */
function mailboxVerifyAssertTargetAllowed(string $host, int $port, string $purpose): array
{
    $hop = $purpose;
    $reject = static function () use ($hop, $purpose): array {
        mailboxVerifyLog('mailbox_verify target_not_allowed hop=' . $hop . ' purpose=' . $purpose);
        return mailboxVerifyFail('mailbox_verify.target_not_allowed', $hop, ['purpose' => $purpose], 'soft');
    };

    // Port policy.
    if ($purpose === 'rcpt') {
        if ($port !== 25) {
            return $reject();
        }
    } else {
        if (!in_array($port, mailboxVerifyAllowedPorts(), true)) {
            return $reject();
        }
    }

    $resolved = mailboxVerifyResolveTarget($host);
    if (empty($resolved['ok'])) {
        $resolved['hop'] = $hop;
        return $resolved;
    }

    /** @var list<string> $ips */
    $ips = $resolved['ips'] ?? [];
    if ($ips === []) {
        return $reject();
    }

    foreach ($ips as $ip) {
        if (mailboxVerifyIpAlwaysBlocked($ip)) {
            return $reject();
        }
        if (mailboxVerifyIpIsPrivate($ip)) {
            if ($purpose === 'rcpt') {
                return $reject();
            }
            if (!mailboxVerifyPrivateAllowedForProbe($host, $ip)) {
                return $reject();
            }
        }
    }

    return [
        'ok' => true,
        'code' => '',
        'params' => [],
        'hop' => $hop,
        'ips' => $ips,
        'connect_ip' => $ips[0],
        'peer_name' => trim($host, "[]"),
    ];
}

/* -------------------------------------------------------------------------- */
/* Transport                                                                   */
/* -------------------------------------------------------------------------- */

/**
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifyClose($stream): void
{
    if ($stream instanceof MailboxVerifyStream) {
        $stream->close();
        return;
    }
    if (is_resource($stream)) {
        @fclose($stream);
    }
}

/**
 * Open a probe connection. When $connectIp is given the socket dials that pinned
 * IP while TLS peer verification uses $tlsPeerName (or the original host).
 *
 * @return resource|MailboxVerifyStream
 */
/**
 * Prefer IPv4 when multiple addresses are returned (stable egress for implicit TLS).
 *
 * @param list<string> $ips
 * @return list<string>
 */
function mailboxVerifyOrderConnectIps(array $ips): array
{
    $v4 = [];
    $v6 = [];
    foreach ($ips as $ip) {
        $ip = trim((string) $ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $v4[] = $ip;
        } else {
            $v6[] = $ip;
        }
    }

    return array_merge($v4, $v6);
}

/**
 * Dial using SSRF-pinned IPs; try each allowed address (IPv4 first).
 *
 * @param list<string> $ips
 * @return resource|MailboxVerifyStream
 */
function mailboxVerifyConnectPinned(
    string $host,
    int $port,
    string $transportEncryption,
    float $timeout,
    array $ips,
    ?string $peerName = null
) {
    $ordered = mailboxVerifyOrderConnectIps($ips);
    if ($ordered === []) {
        throw new MailboxVerifyTransportException('connect', 'connection failed');
    }

    $last = null;
    foreach ($ordered as $ip) {
        try {
            return mailboxVerifyConnect($host, $port, $transportEncryption, $timeout, $ip, $peerName);
        } catch (MailboxVerifyTransportException $e) {
            $last = $e;
            if (!in_array($e->getKind(), ['connect', 'tls'], true)) {
                throw $e;
            }
        }
    }

    throw $last ?? new MailboxVerifyTransportException('connect', 'connection failed');
}

/**
 * Normalize encryption tokens from forms / legacy rows.
 */
function mailboxVerifyNormalizeEncryption(string $encryption, string $default = 'ssl'): string
{
    $enc = strtolower(trim($encryption));
    if ($enc === '') {
        return $default;
    }
    if (in_array($enc, ['starttls', 'tls'], true)) {
        return 'tls';
    }
    if (in_array($enc, ['ssl', 'smtps', 'imaps', 'implicit'], true)) {
        return 'ssl';
    }
    if ($enc === 'none') {
        return 'none';
    }

    return $enc;
}

/**
 * @return array{mode_hint?: string}
 */
function mailboxVerifyModeHintParams(int $port, string $encryption): array
{
    $enc = mailboxVerifyNormalizeEncryption($encryption, 'ssl');
    $standard = ($port === 465 && $enc === 'ssl')
        || ($port === 587 && $enc === 'tls')
        || ($port === 993 && $enc === 'ssl')
        || ($port === 143 && $enc === 'tls')
        || ($port === 25 && $enc === 'none');
    if ($standard) {
        return [];
    }

    return ['mode_hint' => '1'];
}

function mailboxVerifyConnect(
    string $host,
    int $port,
    string $encryption,
    float $timeout,
    ?string $connectIp = null,
    ?string $tlsPeerName = null
) {
    $hook = $GLOBALS['mailbox_verify_connect_hook'] ?? null;
    if (is_callable($hook)) {
        return $hook($host, $port, $encryption, $timeout, $connectIp);
    }

    $dialHost = $connectIp !== null && $connectIp !== '' ? $connectIp : $host;
    $remote = $dialHost;
    if (filter_var($dialHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $remote = '[' . $dialHost . ']';
    }

    $peer = $tlsPeerName !== null && $tlsPeerName !== '' ? $tlsPeerName : $host;
    $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
    $target = $scheme . '://' . $remote . ':' . $port;

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $peer,
            'SNI_enabled' => true,
            'capture_peer_cert' => false,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if ($fp === false) {
        $kind = ($encryption === 'ssl') ? 'tls' : 'connect';
        throw new MailboxVerifyTransportException($kind, 'connection failed');
    }

    stream_set_timeout($fp, (int) ceil(mailboxVerifyIoTimeout()));
    return $fp;
}

/**
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifyReadLine($stream, float $deadline): string
{
    if ($stream instanceof MailboxVerifyStream) {
        return $stream->readLine();
    }

    $now = mailboxVerifyNow();
    $remaining = $deadline - $now;
    if ($remaining <= 0) {
        throw new MailboxVerifyTransportException('timeout', 'read timeout');
    }
    $sec = (int) floor($remaining);
    $usec = (int) (($remaining - $sec) * 1_000_000);
    @stream_set_timeout($stream, $sec, $usec);

    $line = @fgets($stream, 8192);
    if ($line === false) {
        $meta = stream_get_meta_data($stream);
        if (!empty($meta['timed_out'])) {
            throw new MailboxVerifyTransportException('timeout', 'read timeout');
        }
        throw new MailboxVerifyTransportException('read', 'connection closed');
    }
    return rtrim($line, "\r\n");
}

/**
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifyWrite($stream, string $data): void
{
    if ($stream instanceof MailboxVerifyStream) {
        $stream->write($data);
        return;
    }
    $written = @fwrite($stream, $data);
    if ($written === false) {
        throw new MailboxVerifyTransportException('write', 'write failed');
    }
}

/**
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifyEnableCrypto($stream): bool
{
    if ($stream instanceof MailboxVerifyStream) {
        $stream->enableCrypto();
        return true;
    }
    if (is_resource($stream)) {
        return @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true;
    }
    return false;
}

/* -------------------------------------------------------------------------- */
/* Control-character guard                                                     */
/* -------------------------------------------------------------------------- */

function mailboxVerifyHasControlChars(string $value): bool
{
    return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
}

function mailboxVerifyIsAscii(string $value): bool
{
    return preg_match('/[\x80-\xFF]/', $value) !== 1;
}

/* -------------------------------------------------------------------------- */
/* IMAP                                                                        */
/* -------------------------------------------------------------------------- */

/**
 * Parse capability tokens from a greeting/CAPABILITY line (uppercased).
 *
 * @return list<string>
 */
function mailboxVerifyParseImapCapabilities(string $line): array
{
    $pos = stripos($line, 'CAPABILITY');
    if ($pos === false) {
        return [];
    }
    $after = substr($line, $pos + strlen('CAPABILITY'));
    $bracket = strpos($after, ']');
    if ($bracket !== false) {
        $after = substr($after, 0, $bracket);
    }
    $parts = preg_split('/\s+/', trim($after)) ?: [];
    $caps = [];
    foreach ($parts as $p) {
        if ($p !== '') {
            $caps[] = strtoupper($p);
        }
    }
    return $caps;
}

/**
 * Read a tagged IMAP response, skipping untagged "*" lines. May return a "+"
 * continuation line for the caller to handle.
 *
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifyImapReadTagged($stream, string $tag, float $deadline): string
{
    while (true) {
        $line = mailboxVerifyReadLine($stream, $deadline);
        if ($line !== '' && $line[0] === '*') {
            continue;
        }
        return $line;
    }
}

/**
 * Fetch capabilities via an explicit CAPABILITY command.
 *
 * @param resource|MailboxVerifyStream $stream
 * @return list<string>
 */
function mailboxVerifyImapFetchCaps($stream, float $deadline): array
{
    $tag = 'c001';
    mailboxVerifyWrite($stream, $tag . " CAPABILITY\r\n");
    $caps = [];
    while (true) {
        $line = mailboxVerifyReadLine($stream, $deadline);
        if ($line !== '' && $line[0] === '*') {
            $caps = array_merge($caps, mailboxVerifyParseImapCapabilities($line));
            continue;
        }
        // tagged line ends the command
        break;
    }
    return $caps;
}

/**
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function mailboxVerifyImapLogin(
    string $host,
    int $port,
    string $encryption,
    string $login,
    string $secret,
    string $authMode,
    float $deadline,
    ?string $connectIp = null,
    ?string $peerName = null,
    ?array $pinIps = null
): array {
    $timeout = mailboxVerifyClampTimeout($deadline);
    $stream = null;
    try {
        $connectEnc = $encryption === 'ssl' ? 'ssl' : 'none';
        $pinList = is_array($pinIps) && $pinIps !== []
            ? $pinIps
            : ($connectIp !== null && $connectIp !== '' ? [$connectIp] : []);
        $stream = $pinList !== []
            ? mailboxVerifyConnectPinned($host, $port, $connectEnc, $timeout, $pinList, $peerName)
            : mailboxVerifyConnect($host, $port, $connectEnc, $timeout, null, $peerName);

        // Greeting.
        $greeting = mailboxVerifyReadLine($stream, $deadline);
        if (!preg_match('/^\*\s+(OK|PREAUTH)/i', $greeting)) {
            throw new MailboxVerifyTransportException('greeting', 'bad IMAP greeting');
        }
        $caps = mailboxVerifyParseImapCapabilities($greeting);
        if ($caps === []) {
            $caps = mailboxVerifyImapFetchCaps($stream, $deadline);
        }

        // STARTTLS upgrade.
        if ($encryption === 'tls') {
            mailboxVerifyWrite($stream, "a000 STARTTLS\r\n");
            $line = mailboxVerifyImapReadTagged($stream, 'a000', $deadline);
            if (!preg_match('/^a000\s+OK/i', $line)) {
                throw new MailboxVerifyTransportException('starttls', 'STARTTLS refused');
            }
            if (!mailboxVerifyEnableCrypto($stream)) {
                throw new MailboxVerifyTransportException('starttls', 'STARTTLS crypto failed');
            }
            // Capabilities MUST be re-read after STARTTLS.
            $caps = mailboxVerifyImapFetchCaps($stream, $deadline);
        }

        $tag = 'a001';

        if ($authMode === 'oauth2') {
            $auth = base64_encode("user={$login}\x01auth=Bearer {$secret}\x01\x01");
            $saslIr = in_array('SASL-IR', $caps, true);
            if ($saslIr) {
                mailboxVerifyWrite($stream, $tag . ' AUTHENTICATE XOAUTH2 ' . $auth . "\r\n");
                $resp = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
            } else {
                mailboxVerifyWrite($stream, $tag . " AUTHENTICATE XOAUTH2\r\n");
                $cont = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
                if ($cont === '' || $cont[0] !== '+') {
                    return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
                }
                mailboxVerifyWrite($stream, $auth . "\r\n");
                $resp = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
            }
            // XOAUTH2 error continuation: server sends "+ <base64 error>", client
            // must send an empty line, then the tagged NO arrives.
            if ($resp !== '' && $resp[0] === '+') {
                mailboxVerifyWrite($stream, "\r\n");
                $resp = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
            }
            if (!preg_match('/^' . preg_quote($tag, '/') . '\s+OK/i', $resp)) {
                return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
            }
        } else {
            $hasAuthPlain = in_array('AUTH=PLAIN', $caps, true);
            $loginDisabled = in_array('LOGINDISABLED', $caps, true);

            if ($loginDisabled && !$hasAuthPlain) {
                return mailboxVerifyFail('mailbox_verify.imap_login_disabled', 'imap');
            }

            if ($hasAuthPlain) {
                $auth = base64_encode("\x00{$login}\x00{$secret}");
                if (in_array('SASL-IR', $caps, true)) {
                    mailboxVerifyWrite($stream, $tag . ' AUTHENTICATE PLAIN ' . $auth . "\r\n");
                    $resp = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
                } else {
                    mailboxVerifyWrite($stream, $tag . " AUTHENTICATE PLAIN\r\n");
                    $cont = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
                    if ($cont === '' || $cont[0] !== '+') {
                        return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
                    }
                    mailboxVerifyWrite($stream, $auth . "\r\n");
                    $resp = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
                }
                if (!preg_match('/^' . preg_quote($tag, '/') . '\s+OK/i', $resp)) {
                    return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
                }
            } elseif (!mailboxVerifyIsAscii($login) || !mailboxVerifyIsAscii($secret)) {
                // Non-ASCII credentials require synchronizing literals.
                mailboxVerifyWrite($stream, $tag . ' LOGIN {' . strlen($login) . "}\r\n");
                $c1 = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
                if ($c1 === '' || $c1[0] !== '+') {
                    return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
                }
                mailboxVerifyWrite($stream, $login . ' {' . strlen($secret) . "}\r\n");
                $c2 = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
                if ($c2 === '' || $c2[0] !== '+') {
                    return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
                }
                mailboxVerifyWrite($stream, $secret . "\r\n");
                $resp = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
                if (!preg_match('/^' . preg_quote($tag, '/') . '\s+OK/i', $resp)) {
                    return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
                }
            } else {
                $qLogin = mailboxVerifyImapQuote($login);
                $qPass = mailboxVerifyImapQuote($secret);
                mailboxVerifyWrite($stream, $tag . ' LOGIN ' . $qLogin . ' ' . $qPass . "\r\n");
                $resp = mailboxVerifyImapReadTagged($stream, $tag, $deadline);
                if (!preg_match('/^' . preg_quote($tag, '/') . '\s+OK/i', $resp)) {
                    return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
                }
            }
        }

        mailboxVerifyWrite($stream, "a002 LOGOUT\r\n");
        return mailboxVerifyOk();
    } catch (MailboxVerifyTransportException $e) {
        mailboxVerifyLog('mailbox_verify probe hop=imap phase=' . $e->getKind());
        return mailboxVerifyMapTransportFail('imap', $e, $port, $encryption);
    } catch (Throwable $e) {
        mailboxVerifyLog('mailbox_verify probe hop=imap phase=internal');
        return mailboxVerifyFail('mailbox_verify.imap_connect_failed', 'imap', mailboxVerifyModeHintParams($port, $encryption));
    } finally {
        if ($stream !== null) {
            mailboxVerifyClose($stream);
        }
    }
}

function mailboxVerifyImapQuote(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

/* -------------------------------------------------------------------------- */
/* SMTP                                                                        */
/* -------------------------------------------------------------------------- */

function mailboxVerifyEhloHost(): string
{
    if (defined('PANEL_MAILBOX_PROBE_EHLO_HOST') && PANEL_MAILBOX_PROBE_EHLO_HOST !== '') {
        return (string) PANEL_MAILBOX_PROBE_EHLO_HOST;
    }
    $host = gethostname();
    if (is_string($host) && str_contains($host, '.')) {
        return $host;
    }
    static $warned = false;
    if (!$warned) {
        $warned = true;
        mailboxVerifyLog('mailbox_verify ehlo_host fallback to mail-proxy.invalid');
    }
    return 'mail-proxy.invalid';
}

/**
 * Read a (possibly multi-line) SMTP reply.
 *
 * @param resource|MailboxVerifyStream $stream
 * @return array{0:int,1:list<string>}
 */
function mailboxVerifySmtpReadReply($stream, float $deadline): array
{
    $lines = [];
    $code = 0;
    while (true) {
        $line = mailboxVerifyReadLine($stream, $deadline);
        if (!preg_match('/^(\d{3})([ \-]?)(.*)$/', $line, $m)) {
            throw new MailboxVerifyTransportException('protocol', 'bad SMTP reply');
        }
        $code = (int) $m[1];
        $lines[] = $m[3];
        if ($m[2] === ' ' || $m[2] === '') {
            return [$code, $lines];
        }
        // '-' continuation; keep reading.
    }
}

/**
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifySmtpReadCode($stream, float $deadline): int
{
    [$code] = mailboxVerifySmtpReadReply($stream, $deadline);
    return $code;
}

/**
 * EHLO (falling back to HELO) and return parsed capabilities.
 *
 * @param resource|MailboxVerifyStream $stream
 * @return array{auth:list<string>, starttls:bool}
 */
function mailboxVerifySmtpEhlo($stream, float $deadline): array
{
    $ehloHost = mailboxVerifyEhloHost();
    mailboxVerifyWrite($stream, 'EHLO ' . $ehloHost . "\r\n");
    [$code, $lines] = mailboxVerifySmtpReadReply($stream, $deadline);
    if ($code !== 250) {
        mailboxVerifyWrite($stream, 'HELO ' . $ehloHost . "\r\n");
        $helo = mailboxVerifySmtpReadCode($stream, $deadline);
        if ($helo !== 250) {
            throw new MailboxVerifyTransportException('ehlo', 'EHLO/HELO rejected');
        }
        return ['auth' => [], 'starttls' => false];
    }

    $auth = [];
    $starttls = false;
    foreach ($lines as $l) {
        $l = trim($l);
        if (stripos($l, 'AUTH ') === 0 || strcasecmp($l, 'AUTH') === 0) {
            $mechs = preg_split('/\s+/', trim(substr($l, 4))) ?: [];
            foreach ($mechs as $m) {
                if ($m !== '') {
                    $auth[] = strtoupper($m);
                }
            }
        } elseif (strcasecmp($l, 'STARTTLS') === 0) {
            $starttls = true;
        }
    }
    return ['auth' => $auth, 'starttls' => $starttls];
}

/**
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, warning?:bool}
 */
function mailboxVerifySmtpLogin(
    string $host,
    int $port,
    string $encryption,
    string $login,
    string $secret,
    string $authMode,
    float $deadline,
    ?string $connectIp = null,
    ?string $peerName = null,
    ?array $pinIps = null
): array {
    $timeout = mailboxVerifyClampTimeout($deadline);
    $stream = null;
    try {
        $connectEnc = $encryption === 'ssl' ? 'ssl' : 'none';
        $pinList = is_array($pinIps) && $pinIps !== []
            ? $pinIps
            : ($connectIp !== null && $connectIp !== '' ? [$connectIp] : []);
        $stream = $pinList !== []
            ? mailboxVerifyConnectPinned($host, $port, $connectEnc, $timeout, $pinList, $peerName)
            : mailboxVerifyConnect($host, $port, $connectEnc, $timeout, null, $peerName);

        if (mailboxVerifySmtpReadCode($stream, $deadline) !== 220) {
            throw new MailboxVerifyTransportException('greeting', 'bad SMTP greeting');
        }
        $caps = mailboxVerifySmtpEhlo($stream, $deadline);

        if ($encryption === 'tls') {
            mailboxVerifyWrite($stream, "STARTTLS\r\n");
            if (mailboxVerifySmtpReadCode($stream, $deadline) !== 220) {
                throw new MailboxVerifyTransportException('starttls', 'STARTTLS refused');
            }
            if (!mailboxVerifyEnableCrypto($stream)) {
                throw new MailboxVerifyTransportException('starttls', 'STARTTLS crypto failed');
            }
            $caps = mailboxVerifySmtpEhlo($stream, $deadline);
        }

        // Plaintext AUTH guard: never send credentials in the clear to a
        // non-loopback peer unless explicitly permitted.
        if ($encryption === 'none') {
            $peerForLoop = $connectIp ?? $host;
            $isLoopback = mailboxVerifyIpIsLoopback($peerForLoop);
            $allowPlain = defined('PANEL_MAILBOX_PROBE_ALLOW_PLAINTEXT_AUTH')
                && (bool) PANEL_MAILBOX_PROBE_ALLOW_PLAINTEXT_AUTH;
            if (!$isLoopback && !$allowPlain) {
                mailboxVerifyWrite($stream, "QUIT\r\n");
                return mailboxVerifyWarn('mailbox_verify.plaintext_probe_skipped', 'smtp');
            }
        }

        if ($authMode === 'oauth2') {
            $auth = base64_encode("user={$login}\x01auth=Bearer {$secret}\x01\x01");
            mailboxVerifyWrite($stream, 'AUTH XOAUTH2 ' . $auth . "\r\n");
            $code = mailboxVerifySmtpReadCode($stream, $deadline);
            if ($code === 334) {
                // Error challenge: send empty line, expect 5xx rejection.
                mailboxVerifyWrite($stream, "\r\n");
                $code = mailboxVerifySmtpReadCode($stream, $deadline);
                return mailboxVerifyFail('mailbox_verify.smtp_auth_rejected', 'smtp');
            }
            if ($code !== 235) {
                return mailboxVerifyFail('mailbox_verify.smtp_auth_rejected', 'smtp');
            }
        } else {
            $mechs = $caps['auth'];
            if (in_array('PLAIN', $mechs, true)) {
                $plain = base64_encode("\x00{$login}\x00{$secret}");
                mailboxVerifyWrite($stream, 'AUTH PLAIN ' . $plain . "\r\n");
                if (mailboxVerifySmtpReadCode($stream, $deadline) !== 235) {
                    return mailboxVerifyFail('mailbox_verify.smtp_auth_rejected', 'smtp');
                }
            } elseif (in_array('LOGIN', $mechs, true)) {
                mailboxVerifyWrite($stream, "AUTH LOGIN\r\n");
                if (mailboxVerifySmtpReadCode($stream, $deadline) !== 334) {
                    return mailboxVerifyFail('mailbox_verify.smtp_auth_rejected', 'smtp');
                }
                mailboxVerifyWrite($stream, base64_encode($login) . "\r\n");
                if (mailboxVerifySmtpReadCode($stream, $deadline) !== 334) {
                    return mailboxVerifyFail('mailbox_verify.smtp_auth_rejected', 'smtp');
                }
                mailboxVerifyWrite($stream, base64_encode($secret) . "\r\n");
                if (mailboxVerifySmtpReadCode($stream, $deadline) !== 235) {
                    return mailboxVerifyFail('mailbox_verify.smtp_auth_rejected', 'smtp');
                }
            } else {
                return mailboxVerifyFail('mailbox_verify.smtp_auth_unsupported', 'smtp');
            }
        }

        mailboxVerifyWrite($stream, "QUIT\r\n");
        return mailboxVerifyOk();
    } catch (MailboxVerifyTransportException $e) {
        mailboxVerifyLog('mailbox_verify probe hop=smtp phase=' . $e->getKind());
        return mailboxVerifyMapTransportFail('smtp', $e, $port, $encryption);
    } catch (Throwable $e) {
        mailboxVerifyLog('mailbox_verify probe hop=smtp phase=internal');
        return mailboxVerifyFail('mailbox_verify.smtp_connect_failed', 'smtp', mailboxVerifyModeHintParams($port, $encryption));
    } finally {
        if ($stream !== null) {
            mailboxVerifyClose($stream);
        }
    }
}

/**
 * SMTP recipient probe: EHLO → MAIL FROM → RCPT TO → QUIT (no DATA).
 *
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, severity?:string}
 */
function mailboxVerifySmtpRcptProbe(
    string $mxHost,
    int $port,
    string $recipient,
    float $deadline,
    ?string $connectIp = null,
    ?string $peerName = null
): array {
    $timeout = mailboxVerifyClampTimeout($deadline);
    $stream = null;
    try {
        $stream = mailboxVerifyConnect($mxHost, $port, 'none', $timeout, $connectIp, $peerName);

        if (mailboxVerifySmtpReadCode($stream, $deadline) !== 220) {
            throw new MailboxVerifyTransportException('greeting', 'bad SMTP greeting');
        }
        mailboxVerifySmtpEhlo($stream, $deadline);

        $mailFrom = mailboxVerifyProbeMailFrom();
        $fromCmd = $mailFrom === '' ? "MAIL FROM:<>\r\n" : 'MAIL FROM:<' . $mailFrom . ">\r\n";
        mailboxVerifyWrite($stream, $fromCmd);
        if (mailboxVerifySmtpReadCode($stream, $deadline) !== 250) {
            mailboxVerifyWrite($stream, "QUIT\r\n");
            return mailboxVerifyFail('mailbox_verify.rcpt_mailfrom_rejected', 'rcpt', ['email' => $recipient], 'soft');
        }

        mailboxVerifyWrite($stream, 'RCPT TO:<' . $recipient . ">\r\n");
        $rcptCode = mailboxVerifySmtpReadCode($stream, $deadline);
        mailboxVerifyWrite($stream, "QUIT\r\n");

        if (in_array($rcptCode, [250, 251], true)) {
            return mailboxVerifyOk();
        }
        // Definitive user-unknown → hard.
        if (in_array($rcptCode, [550, 551, 553], true)) {
            return mailboxVerifyFail('mailbox_verify.rcpt_rejected', 'rcpt', [
                'email' => $recipient,
                'code' => (string) $rcptCode,
            ], 'hard');
        }
        // Temporary failure → soft.
        if ($rcptCode >= 400 && $rcptCode < 500) {
            return mailboxVerifyFail('mailbox_verify.rcpt_deferred', 'rcpt', [
                'email' => $recipient,
                'code' => (string) $rcptCode,
            ], 'soft');
        }
        // Other 5xx → soft (not a definitive user-unknown).
        return mailboxVerifyFail('mailbox_verify.rcpt_rejected', 'rcpt', [
            'email' => $recipient,
            'code' => (string) $rcptCode,
        ], 'soft');
    } catch (MailboxVerifyTransportException $e) {
        return mailboxVerifyMapTransportFail('rcpt', $e);
    } catch (Throwable $e) {
        mailboxVerifyLog('SMTP RCPT probe error: ' . get_class($e));
        return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', ['detail' => 'SMTP'], 'soft');
    } finally {
        if ($stream !== null) {
            mailboxVerifyClose($stream);
        }
    }
}

/**
 * Map a transport-level exception to a result, per hop.
 *
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, severity?:string}
 */
function mailboxVerifyMapTransportFail(
    string $hop,
    MailboxVerifyTransportException $e,
    int $port = 0,
    string $encryption = ''
): array {
    $kind = $e->getKind();
    $hint = $port > 0 ? mailboxVerifyModeHintParams($port, $encryption) : [];

    if ($hop === 'rcpt') {
        switch ($kind) {
            case 'timeout':
                return mailboxVerifyFail('mailbox_verify.timeout', 'network', [], 'soft');
            case 'connect':
                return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', ['detail' => 'SMTP port 25'], 'soft');
            case 'ehlo':
                return mailboxVerifyFail('mailbox_verify.smtp_ehlo_rejected', 'rcpt', [], 'soft');
            case 'greeting':
            case 'protocol':
            case 'read':
            case 'write':
            default:
                return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', ['detail' => 'SMTP'], 'soft');
        }
    }

    if ($hop === 'imap') {
        if ($kind === 'timeout') {
            return mailboxVerifyFail('mailbox_verify.timeout', 'imap', $hint);
        }
        if ($kind === 'tls') {
            return mailboxVerifyFail('mailbox_verify.imap_tls_failed', 'imap', $hint);
        }
        if ($kind === 'starttls') {
            return mailboxVerifyFail('mailbox_verify.imap_starttls_failed', 'imap', $hint);
        }
        if ($kind === 'greeting') {
            return mailboxVerifyFail('mailbox_verify.imap_greeting_failed', 'imap', $hint);
        }
        if ($kind === 'connect') {
            return mailboxVerifyFail('mailbox_verify.imap_connect_failed', 'imap', $hint);
        }

        return mailboxVerifyFail('mailbox_verify.imap_connect_failed', 'imap', $hint);
    }

    // smtp (referent login hop)
    switch ($kind) {
        case 'timeout':
            return mailboxVerifyFail('mailbox_verify.timeout', 'smtp', $hint);
        case 'tls':
            return mailboxVerifyFail('mailbox_verify.smtp_tls_failed', 'smtp', $hint);
        case 'starttls':
            return mailboxVerifyFail('mailbox_verify.smtp_starttls_failed', 'smtp', $hint);
        case 'ehlo':
            return mailboxVerifyFail('mailbox_verify.smtp_ehlo_rejected', 'smtp', $hint);
        case 'greeting':
            return mailboxVerifyFail('mailbox_verify.smtp_greeting_failed', 'smtp', $hint);
        case 'connect':
            return mailboxVerifyFail('mailbox_verify.smtp_connect_failed', 'smtp', $hint);
        case 'protocol':
        case 'read':
        case 'write':
        default:
            return mailboxVerifyFail('mailbox_verify.smtp_connect_failed', 'smtp', $hint);
    }
}

/* -------------------------------------------------------------------------- */
/* DNS / MX                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * @param list<array{host?:string,pri?:int}> $list
 * @return list<array{host:string,pri:int}>
 */
function mailboxVerifyNormalizeMxList(array $list): array
{
    $out = [];
    foreach ($list as $row) {
        $host = rtrim(strtolower(trim((string) ($row['host'] ?? ''))), '.');
        // RFC 7505 null MX ("."), plus empty/localhost are unusable.
        if ($host === '' || $host === '.' || $host === 'localhost') {
            continue;
        }
        $out[] = ['host' => $host, 'pri' => (int) ($row['pri'] ?? 0)];
    }
    usort($out, static fn(array $a, array $b): int => $a['pri'] <=> $b['pri']);
    return $out;
}

/**
 * Pure classifier for a real (non-hook) MX lookup failure.
 *
 * @param mixed $aResult    dns_get_record(DNS_A) result (array|false)
 * @param mixed $aaaaResult dns_get_record(DNS_AAAA) result (array|false)
 * @return list<array{host:string,pri:int}>|false
 *   false   → DNS/network is unreachable (canary also failed) → fail closed.
 *   []      → DNS works but domain has no mail route → mx_missing.
 */
function mailboxVerifyClassifyDnsFailure(bool $mxLookupFailed, $aResult, $aaaaResult, bool $canaryOk)
{
    // If A/AAAA yielded usable records we would not be here; callers only invoke
    // this when there is no MX and no A/AAAA. The canary distinguishes a broken
    // resolver (network) from a domain that simply has no mail route.
    return $canaryOk ? [] : false;
}

/**
 * @return list<array{host:string,pri:int}>|false false = DNS subsystem unavailable
 */
function mailboxVerifyResolveMx(string $domain)
{
    $hook = $GLOBALS['mailbox_verify_dns_hook'] ?? null;
    if (is_callable($hook)) {
        $raw = $hook($domain);
        if ($raw === false) {
            return false;
        }
        if (!is_array($raw)) {
            return [];
        }
        return mailboxVerifyNormalizeMxList($raw);
    }

    $hosts = [];
    $weights = [];
    $mxOk = false;
    try {
        $mxOk = @getmxrr($domain, $hosts, $weights);
    } catch (Throwable $e) {
        mailboxVerifyLog('getmxrr failed: ' . get_class($e));
        $mxOk = false;
    }

    if ($mxOk && $hosts !== []) {
        $list = [];
        foreach ($hosts as $i => $host) {
            $list[] = [
                'host' => rtrim(strtolower((string) $host), '.'),
                'pri' => (int) ($weights[$i] ?? 0),
            ];
        }
        return mailboxVerifyNormalizeMxList($list);
    }

    // RFC 5321 §5.1: no MX → implicit MX from A/AAAA.
    $a = @dns_get_record($domain, DNS_A);
    $aaaa = @dns_get_record($domain, DNS_AAAA);
    $fallback = [];
    if (is_array($a)) {
        foreach ($a as $row) {
            if (!empty($row['ip'])) {
                $fallback[] = ['host' => (string) $row['ip'], 'pri' => 0];
            }
        }
    }
    if (is_array($aaaa)) {
        foreach ($aaaa as $row) {
            if (!empty($row['ipv6'])) {
                $fallback[] = ['host' => (string) $row['ipv6'], 'pri' => 0];
            }
        }
    }
    if ($fallback !== []) {
        return mailboxVerifyNormalizeMxList($fallback);
    }

    // Nothing resolved: probe a canary to tell "broken resolver" from "no mail".
    $canary = defined('PANEL_MAILBOX_PROBE_DNS_CANARY') && PANEL_MAILBOX_PROBE_DNS_CANARY !== ''
        ? (string) PANEL_MAILBOX_PROBE_DNS_CANARY
        : 'iana.org';
    $canaryRec = @dns_get_record($canary, DNS_A);
    $canaryOk = is_array($canaryRec) && $canaryRec !== [];

    return mailboxVerifyClassifyDnsFailure(true, $a, $aaaa, $canaryOk);
}

/* -------------------------------------------------------------------------- */
/* High-level probes                                                           */
/* -------------------------------------------------------------------------- */

/**
 * Verify External Referent mailbox (IMAP + SMTP authenticated probes).
 *
 * @param array<string,mixed> $account keys: email, username, auth_type/auth_mode,
 *   imap_*, smtp_*, secret|password|oauth_access_token, oauth_expires_at
 * @param array<string,mixed>|null $session Rate-limit store (null → $_SESSION).
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, warning?:bool}
 */
function verifyExternalReferentMailbox(array $account, ?array &$session = null): array
{
    $email = trim((string) ($account['email'] ?? ''));
    $login = trim((string) ($account['username'] ?? ''));
    if ($login === '') {
        $login = $email;
    }
    $authMode = strtolower(trim((string) ($account['auth_mode'] ?? $account['auth_type'] ?? 'plain')));
    if ($authMode !== 'oauth2') {
        $authMode = 'plain';
    }
    $secret = (string) ($account['secret'] ?? $account['password'] ?? $account['oauth_access_token'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return mailboxVerifyFail('mailbox_verify.invalid_email', 'auth');
    }

    // Control-character guard on all wire-bound credential material.
    if (mailboxVerifyHasControlChars($login)
        || mailboxVerifyHasControlChars($secret)
        || mailboxVerifyHasControlChars($email)
    ) {
        return mailboxVerifyFail('mailbox_verify.invalid_credentials_chars', 'auth');
    }

    if ($secret === '') {
        if ($authMode === 'oauth2') {
            return mailboxVerifyFail('mailbox_verify.oauth_token_missing', 'oauth');
        }
        return mailboxVerifyFail('mailbox_verify.password_required', 'auth');
    }

    // OAuth token expiry: no refresh helper exists, so skip with a warning.
    if ($authMode === 'oauth2') {
        $expiresAt = trim((string) ($account['oauth_expires_at'] ?? ''));
        if ($expiresAt !== '') {
            $ts = is_numeric($expiresAt) ? (float) $expiresAt : (float) strtotime($expiresAt);
            if ($ts > 0 && $ts <= mailboxVerifyNow()) {
                return mailboxVerifyWarn('mailbox_verify.oauth_token_expired', 'oauth');
            }
        }
    }

    $imapHost = trim((string) ($account['imap_host'] ?? ''));
    $imapPort = (int) ($account['imap_port'] ?? 0);
    $imapEnc = mailboxVerifyNormalizeEncryption((string) ($account['imap_encryption'] ?? 'ssl'), 'ssl');
    $smtpHost = trim((string) ($account['smtp_host'] ?? ''));
    $smtpPort = (int) ($account['smtp_port'] ?? 0);
    $smtpEnc = mailboxVerifyNormalizeEncryption((string) ($account['smtp_encryption'] ?? 'tls'), 'tls');

    if ($imapHost === '' || $imapPort < 1 || $imapPort > 65535) {
        return mailboxVerifyFail('mailbox_verify.imap_host_invalid', 'imap');
    }
    if ($smtpHost === '' || $smtpPort < 1 || $smtpPort > 65535) {
        return mailboxVerifyFail('mailbox_verify.smtp_host_invalid', 'smtp');
    }
    if (!in_array($imapEnc, ['ssl', 'tls', 'none'], true)) {
        return mailboxVerifyFail('mailbox_verify.imap_encryption_invalid', 'imap');
    }
    if (!in_array($smtpEnc, ['ssl', 'tls', 'none'], true)) {
        return mailboxVerifyFail('mailbox_verify.smtp_encryption_invalid', 'smtp');
    }

    // Rate limit live probes.
    $slot = mailboxVerifyConsumeProbeSlot($session);
    if (empty($slot['ok'])) {
        return $slot;
    }

    // IMAP hop (independent budget).
    $imapAllowed = mailboxVerifyAssertTargetAllowed($imapHost, $imapPort, 'imap');
    if (empty($imapAllowed['ok'])) {
        return $imapAllowed;
    }
    $imapDeadline = mailboxVerifyHopDeadline();
    $imap = mailboxVerifyImapLogin(
        $imapHost,
        $imapPort,
        $imapEnc,
        $login,
        $secret,
        $authMode,
        $imapDeadline,
        $imapAllowed['connect_ip'] ?? null,
        $imapAllowed['peer_name'] ?? null,
        $imapAllowed['ips'] ?? null
    );
    if (empty($imap['ok'])) {
        return $imap;
    }

    // SMTP hop (independent budget).
    $smtpAllowed = mailboxVerifyAssertTargetAllowed($smtpHost, $smtpPort, 'smtp');
    if (empty($smtpAllowed['ok'])) {
        return $smtpAllowed;
    }
    $smtpDeadline = mailboxVerifyHopDeadline();
    $smtp = mailboxVerifySmtpLogin(
        $smtpHost,
        $smtpPort,
        $smtpEnc,
        $login,
        $secret,
        $authMode,
        $smtpDeadline,
        $smtpAllowed['connect_ip'] ?? null,
        $smtpAllowed['peer_name'] ?? null,
        $smtpAllowed['ips'] ?? null
    );
    if (empty($smtp['ok'])) {
        return $smtp;
    }
    // Propagate a plaintext-skip warning from the SMTP hop.
    if (!empty($smtp['warning'])) {
        return $smtp;
    }

    return mailboxVerifyOk();
}

/**
 * Verify External Client address via MX (or A/AAAA fallback) + SMTP RCPT TO.
 *
 * @param array<string,mixed>|null $session Rate-limit store (null → $_SESSION).
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, severity?:string}
 */
function verifyExternalClientMailbox(string $email, ?array &$session = null): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return mailboxVerifyFail('mailbox_verify.invalid_email', 'auth');
    }
    if (mailboxVerifyHasControlChars($email)) {
        return mailboxVerifyFail('mailbox_verify.invalid_credentials_chars', 'auth');
    }

    $at = strrpos($email, '@');
    if ($at === false) {
        return mailboxVerifyFail('mailbox_verify.invalid_email', 'auth');
    }
    $domain = substr($email, $at + 1);
    if ($domain === '') {
        return mailboxVerifyFail('mailbox_verify.invalid_email', 'auth');
    }

    // Rate limit live probes.
    $slot = mailboxVerifyConsumeProbeSlot($session);
    if (empty($slot['ok'])) {
        return $slot;
    }

    $mx = mailboxVerifyResolveMx($domain);
    if ($mx === false) {
        return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', ['detail' => 'DNS'], 'soft');
    }
    if ($mx === []) {
        return mailboxVerifyFail('mailbox_verify.mx_missing', 'dns', ['domain' => $domain], 'hard');
    }

    $deadline = mailboxVerifyHopDeadline();
    $lastSoft = mailboxVerifyFail('mailbox_verify.rcpt_unreachable', 'rcpt', ['email' => $email], 'soft');
    $sawAllowed = false;

    foreach ($mx as $row) {
        if (mailboxVerifyNow() >= $deadline) {
            return mailboxVerifyFail('mailbox_verify.timeout', 'network', [], 'soft');
        }
        $host = $row['host'];

        $allowed = mailboxVerifyAssertTargetAllowed($host, 25, 'rcpt');
        if (empty($allowed['ok'])) {
            // Blocked MX: skip (do not fail open); remember for the all-blocked case.
            continue;
        }
        $sawAllowed = true;

        $result = mailboxVerifySmtpRcptProbe(
            $host,
            25,
            $email,
            $deadline,
            $allowed['connect_ip'] ?? null,
            $allowed['peer_name'] ?? null
        );
        if (!empty($result['ok'])) {
            return mailboxVerifyOk();
        }
        // Definitive user-unknown → return immediately.
        if (($result['severity'] ?? '') === 'hard') {
            return $result;
        }
        $lastSoft = $result;
    }

    if (!$sawAllowed) {
        // Every MX was blocked by the SSRF guard.
        return mailboxVerifyFail('mailbox_verify.target_not_allowed', 'rcpt', ['purpose' => 'rcpt'], 'soft');
    }

    return $lastSoft;
}

/**
 * Local iRedMail physical mailbox must exist (aliases are not enough).
 *
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function verifyLocalPhysicalMailbox(string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return mailboxVerifyFail('mailbox_verify.invalid_email', 'local');
    }

    try {
        $hook = $GLOBALS['mailbox_verify_local_hook'] ?? null;
        if (is_callable($hook)) {
            $exists = (bool) $hook($email);
        } elseif (function_exists('activePhysicalMailboxExists')) {
            $exists = activePhysicalMailboxExists($email);
        } else {
            return mailboxVerifyFail('mailbox_verify.vmail_unavailable', 'local');
        }
    } catch (Throwable $e) {
        mailboxVerifyLog('local mailbox check failed: ' . get_class($e));
        return mailboxVerifyFail('mailbox_verify.vmail_unavailable', 'local');
    }

    if (!$exists) {
        return mailboxVerifyFail('mailbox_verify.local_missing', 'local', ['email' => $email]);
    }

    return mailboxVerifyOk();
}

/* -------------------------------------------------------------------------- */
/* DB helpers                                                                   */
/* -------------------------------------------------------------------------- */

/**
 * Load stored account row + decrypted OAuth access token and expiry (if any).
 *
 * @return array<string,mixed>|null
 */
function mailboxVerifyLoadExistingAccount(PDO $pdo, int $accountId, int $referentId, ?\MailProxy\Cryptor $cryptor = null): ?array
{
    $stmt = $pdo->prepare(
        'SELECT ea.*,
                ot.access_token_enc,
                ot.refresh_token_enc,
                ot.expires_at AS oauth_expires_at
         FROM external_accounts ea
         LEFT JOIN oauth_tokens ot ON ot.account_id = ea.id
         WHERE ea.id = ? AND ea.referent_id = ?
         LIMIT 1'
    );
    $stmt->execute([$accountId, $referentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['oauth_access_token'] = '';
    if ($cryptor !== null && !empty($row['access_token_enc'])) {
        try {
            $row['oauth_access_token'] = $cryptor->decrypt((string) $row['access_token_enc']);
        } catch (Throwable $e) {
            mailboxVerifyLog('mailbox_verify decrypt access_token_enc failed: ' . get_class($e));
            $row['oauth_access_token'] = '';
        }
    }
    unset($row['access_token_enc']);

    return $row;
}

function mailboxVerifyDeleteOauthTokens(PDO $pdo, int $accountId): void
{
    $stmt = $pdo->prepare('DELETE FROM oauth_tokens WHERE account_id = ?');
    $stmt->execute([$accountId]);
}

/* -------------------------------------------------------------------------- */
/* Classes                                                                      */
/* -------------------------------------------------------------------------- */

/**
 * Scriptable in-memory stream for offline tests.
 */
class MailboxVerifyStream
{
    /** @var list<string> */
    private array $inbox;
    /** @var list<string> */
    private array $outbox = [];
    private bool $open = true;
    private bool $crypto = false;

    /** @param list<string> $inbox */
    public function __construct(array $inbox)
    {
        $this->inbox = $inbox;
    }

    public function readLine(): string
    {
        if (!$this->open) {
            throw new MailboxVerifyTransportException('read', 'closed');
        }
        if ($this->inbox === []) {
            throw new MailboxVerifyTransportException('read', 'no more data');
        }
        return array_shift($this->inbox);
    }

    public function write(string $data): void
    {
        if (!$this->open) {
            throw new MailboxVerifyTransportException('write', 'closed');
        }
        $this->outbox[] = $data;
    }

    public function enableCrypto(): void
    {
        $this->crypto = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /** @return list<string> */
    public function written(): array
    {
        return $this->outbox;
    }

    public function cryptoEnabled(): bool
    {
        return $this->crypto;
    }
}

class MailboxVerifyTransportException extends RuntimeException
{
    private string $kind;

    public function __construct(string $kind, string $message)
    {
        parent::__construct($message);
        $this->kind = $kind;
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}
