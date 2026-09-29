<?php
declare(strict_types=1);

/**
 * Referent-card mailbox verification on save (PROMPT-80 / issue #40).
 *
 * Live probes (IMAP/SMTP login, MX + RCPT TO) use short timeouts and never
 * echo passwords or OAuth tokens in operator messages or logs.
 *
 * Tests inject socket/DNS fakes via mailboxVerifySetHooks() so CI never
 * contacts real providers.
 */

if (!defined('MAILBOX_VERIFY_CONNECT_TIMEOUT')) {
    define('MAILBOX_VERIFY_CONNECT_TIMEOUT', 5.0);
}
if (!defined('MAILBOX_VERIFY_IO_TIMEOUT')) {
    define('MAILBOX_VERIFY_IO_TIMEOUT', 5.0);
}
if (!defined('MAILBOX_VERIFY_TOTAL_BUDGET')) {
    define('MAILBOX_VERIFY_TOTAL_BUDGET', 8.0);
}

/** @var callable|null fn(string $host, int $port, string $encryption, float $timeout): resource|object */
$GLOBALS['mailbox_verify_connect_hook'] = $GLOBALS['mailbox_verify_connect_hook'] ?? null;

/** @var callable|null fn(string $domain): list<array{host:string,pri:int}>|false */
$GLOBALS['mailbox_verify_dns_hook'] = $GLOBALS['mailbox_verify_dns_hook'] ?? null;

/** @var callable|null fn(string $email): bool */
$GLOBALS['mailbox_verify_local_hook'] = $GLOBALS['mailbox_verify_local_hook'] ?? null;

/**
 * @param callable|null $connect fn(string $host, int $port, string $encryption, float $timeout): resource|MailboxVerifyStream
 * @param callable|null $dns     fn(string $domain): list<array{host:string,pri:int}>|false
 * @param callable|null $local   fn(string $email): bool
 */
function mailboxVerifySetHooks(?callable $connect, ?callable $dns = null, ?callable $local = null): void
{
    $GLOBALS['mailbox_verify_connect_hook'] = $connect;
    $GLOBALS['mailbox_verify_dns_hook'] = $dns;
    $GLOBALS['mailbox_verify_local_hook'] = $local;
}

function mailboxVerifyResetHooks(): void
{
    $GLOBALS['mailbox_verify_connect_hook'] = null;
    $GLOBALS['mailbox_verify_dns_hook'] = null;
    $GLOBALS['mailbox_verify_local_hook'] = null;
}

/**
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function mailboxVerifyOk(): array
{
    return ['ok' => true, 'code' => '', 'params' => [], 'hop' => ''];
}

/**
 * @param array<string,string> $params
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function mailboxVerifyFail(string $code, string $hop = '', array $params = []): array
{
    if ($code === 'mailbox_verify.network_unavailable') {
        $detail = trim((string) ($params['detail'] ?? ''));
        $params['detail'] = $detail === '' ? '' : ' (' . $detail . ')';
    }
    return ['ok' => false, 'code' => $code, 'params' => $params, 'hop' => $hop];
}

/**
 * Operator-facing message for a verify result (never includes secrets).
 *
 * @param array{ok:bool, code:string, params:array<string,string>, hop:string} $result
 */
function mailboxVerifyMessage(array $result): string
{
    if (!empty($result['ok'])) {
        return '';
    }
    $code = (string) ($result['code'] ?? 'mailbox_verify.failed');
    $params = is_array($result['params'] ?? null) ? $result['params'] : [];
    if (function_exists('__')) {
        return __($code, $params);
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

/**
 * Whether account create/edit needs a live IMAP+SMTP probe.
 *
 * @param array<string,mixed>|null $existing
 */
function mailboxVerifyAccountNeedsProbe(
    int $accountId,
    array $incoming,
    ?array $existing
): bool {
    if ($accountId <= 0 || $existing === null) {
        return true;
    }

    $fields = [
        'email',
        'username',
        'auth_type',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
    ];
    foreach ($fields as $field) {
        $left = strtolower(trim((string) ($incoming[$field] ?? '')));
        $right = strtolower(trim((string) ($existing[$field] ?? '')));
        if ($left !== $right) {
            return true;
        }
    }

    // New password / client secret typed on form.
    if (trim((string) ($incoming['password'] ?? '')) !== '') {
        return true;
    }
    if (trim((string) ($incoming['client_secret'] ?? '')) !== '') {
        return true;
    }

    return false;
}

/**
 * Resolve password/token for probe. Never returns secrets in error payloads.
 *
 * OAuth2 without a stored access token skips the live login probe so operators
 * can save client_id/secret first, then click Authorize OAuth2 (issue #40).
 *
 * @param array<string,mixed> $incoming
 * @param array<string,mixed>|null $existing
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string, secret?:string, auth_mode?:string, skip_probe?:bool}
 */
function mailboxVerifyResolveAuthSecret(
    array $incoming,
    ?array $existing,
    ?\MailProxy\Cryptor $cryptor = null
): array {
    $authType = strtolower(trim((string) ($incoming['auth_type'] ?? 'plain')));
    if ($authType === 'oauth2') {
        $token = trim((string) ($incoming['oauth_access_token'] ?? ''));
        if ($token === '' && $existing !== null) {
            $token = trim((string) ($existing['oauth_access_token'] ?? ''));
        }
        if ($token === '') {
            return [
                'ok' => true,
                'code' => '',
                'params' => [],
                'hop' => '',
                'secret' => '',
                'auth_mode' => 'oauth2',
                'skip_probe' => true,
            ];
        }
        return [
            'ok' => true,
            'code' => '',
            'params' => [],
            'hop' => '',
            'secret' => $token,
            'auth_mode' => 'oauth2',
            'skip_probe' => false,
        ];
    }

    $password = trim((string) ($incoming['password'] ?? ''));
    if ($password === '' && $existing !== null && !empty($existing['password_enc']) && $cryptor !== null) {
        try {
            $password = $cryptor->decrypt((string) $existing['password_enc']);
        } catch (Throwable $e) {
            mailboxVerifyLog('mailbox_verify decrypt password_enc failed: ' . $e->getMessage());
            return mailboxVerifyFail('mailbox_verify.decrypt_failed', 'auth');
        }
    }
    if ($password === '') {
        return mailboxVerifyFail('mailbox_verify.password_required', 'auth');
    }

    return [
        'ok' => true,
        'code' => '',
        'params' => [],
        'hop' => '',
        'secret' => $password,
        'auth_mode' => 'plain',
        'skip_probe' => false,
    ];
}

/**
 * Load stored account row + decrypted OAuth access token (if any).
 *
 * @return array<string,mixed>|null
 */
function mailboxVerifyLoadExistingAccount(PDO $pdo, int $accountId, int $referentId, ?\MailProxy\Cryptor $cryptor = null): ?array
{
    $stmt = $pdo->prepare(
        'SELECT ea.*,
                ot.access_token_enc
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
            mailboxVerifyLog('mailbox_verify decrypt access_token_enc failed: ' . $e->getMessage());
            $row['oauth_access_token'] = '';
        }
    }
    unset($row['access_token_enc']);

    return $row;
}

/**
 * Verify External Referent mailbox (IMAP + SMTP authenticated probes).
 *
 * @param array<string,mixed> $account keys: email, username, auth_type, imap_*, smtp_*, password|oauth_access_token
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function verifyExternalReferentMailbox(array $account): array
{
    $email = trim((string) ($account['email'] ?? ''));
    $login = trim((string) ($account['username'] ?? ''));
    if ($login === '') {
        $login = $email;
    }
    $authMode = strtolower(trim((string) ($account['auth_mode'] ?? $account['auth_type'] ?? 'plain')));
    $secret = (string) ($account['secret'] ?? $account['password'] ?? $account['oauth_access_token'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return mailboxVerifyFail('mailbox_verify.invalid_email', 'auth');
    }
    if ($secret === '') {
        if ($authMode === 'oauth2') {
            return mailboxVerifyFail('mailbox_verify.oauth_token_missing', 'oauth');
        }
        return mailboxVerifyFail('mailbox_verify.password_required', 'auth');
    }

    $imapHost = trim((string) ($account['imap_host'] ?? ''));
    $imapPort = (int) ($account['imap_port'] ?? 0);
    $imapEnc = strtolower(trim((string) ($account['imap_encryption'] ?? 'ssl')));
    $smtpHost = trim((string) ($account['smtp_host'] ?? ''));
    $smtpPort = (int) ($account['smtp_port'] ?? 0);
    $smtpEnc = strtolower(trim((string) ($account['smtp_encryption'] ?? 'tls')));

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

    $deadline = microtime(true) + (float) MAILBOX_VERIFY_TOTAL_BUDGET;

    $imap = mailboxVerifyImapLogin($imapHost, $imapPort, $imapEnc, $login, $secret, $authMode, $deadline);
    if (empty($imap['ok'])) {
        return $imap;
    }

    $smtp = mailboxVerifySmtpLogin($smtpHost, $smtpPort, $smtpEnc, $login, $secret, $authMode, $deadline);
    if (empty($smtp['ok'])) {
        return $smtp;
    }

    return mailboxVerifyOk();
}

/**
 * Verify External Client address via MX (or A/AAAA fallback) + SMTP RCPT TO.
 *
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function verifyExternalClientMailbox(string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return mailboxVerifyFail('mailbox_verify.invalid_email', 'auth');
    }

    $at = strrpos($email, '@');
    if ($at === false) {
        return mailboxVerifyFail('mailbox_verify.invalid_email', 'auth');
    }
    $domain = substr($email, $at + 1);
    if ($domain === '') {
        return mailboxVerifyFail('mailbox_verify.invalid_email', 'auth');
    }

    $mx = mailboxVerifyResolveMx($domain);
    if ($mx === false) {
        return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', [
            'detail' => 'DNS',
        ]);
    }
    if ($mx === []) {
        return mailboxVerifyFail('mailbox_verify.mx_missing', 'dns', [
            'domain' => $domain,
        ]);
    }

    $deadline = microtime(true) + (float) MAILBOX_VERIFY_TOTAL_BUDGET;
    $lastFail = mailboxVerifyFail('mailbox_verify.rcpt_unreachable', 'rcpt', [
        'email' => $email,
    ]);

    foreach ($mx as $row) {
        if (microtime(true) >= $deadline) {
            return mailboxVerifyFail('mailbox_verify.timeout', 'network');
        }
        $host = $row['host'];
        $result = mailboxVerifySmtpRcptProbe($host, 25, $email, $deadline);
        if (!empty($result['ok'])) {
            return mailboxVerifyOk();
        }
        $lastFail = $result;
        // Soft fail on greylist/temp — try next MX; permanent reject on primary is enough.
        if (($result['code'] ?? '') === 'mailbox_verify.rcpt_rejected') {
            return $result;
        }
        if (($result['code'] ?? '') === 'mailbox_verify.network_unavailable') {
            // Keep trying other MX; if all fail network, surface network error.
            continue;
        }
    }

    return $lastFail;
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
        if (isset($GLOBALS['mailbox_verify_local_hook']) && is_callable($GLOBALS['mailbox_verify_local_hook'])) {
            $exists = (bool) ($GLOBALS['mailbox_verify_local_hook'])($email);
        } elseif (function_exists('activePhysicalMailboxExists')) {
            $exists = activePhysicalMailboxExists($email);
        } else {
            return mailboxVerifyFail('mailbox_verify.vmail_unavailable', 'local');
        }
    } catch (Throwable $e) {
        mailboxVerifyLog('local mailbox check failed: ' . $e->getMessage());
        return mailboxVerifyFail('mailbox_verify.vmail_unavailable', 'local');
    }

    if (!$exists) {
        return mailboxVerifyFail('mailbox_verify.local_missing', 'local', [
            'email' => $email,
        ]);
    }

    return mailboxVerifyOk();
}

/**
 * @return list<array{host:string,pri:int}>|false false = DNS subsystem unavailable
 */
function mailboxVerifyResolveMx(string $domain)
{
    if (isset($GLOBALS['mailbox_verify_dns_hook']) && is_callable($GLOBALS['mailbox_verify_dns_hook'])) {
        $raw = ($GLOBALS['mailbox_verify_dns_hook'])($domain);
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
    try {
        $ok = @getmxrr($domain, $hosts, $weights);
    } catch (Throwable $e) {
        mailboxVerifyLog('getmxrr failed: ' . $e->getMessage());
        return false;
    }

    if ($ok && $hosts !== []) {
        $list = [];
        foreach ($hosts as $i => $host) {
            $list[] = [
                'host' => rtrim(strtolower((string) $host), '.'),
                'pri' => (int) ($weights[$i] ?? 0),
            ];
        }
        return mailboxVerifyNormalizeMxList($list);
    }

    // RFC 5321 §5.1: if no MX, treat domain A/AAAA as implicit MX.
    $a = @dns_get_record($domain, DNS_A);
    $aaaa = @dns_get_record($domain, DNS_AAAA);
    if ($a === false && $aaaa === false) {
        // dns_get_record false often means resolver failure — fail closed.
        return false;
    }
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
    if ($fallback === []) {
        // Domain resolves to nothing usable.
        return [];
    }
    return mailboxVerifyNormalizeMxList($fallback);
}

/**
 * @param list<array{host?:string,pri?:int}> $list
 * @return list<array{host:string,pri:int}>
 */
function mailboxVerifyNormalizeMxList(array $list): array
{
    $out = [];
    foreach ($list as $row) {
        $host = rtrim(strtolower(trim((string) ($row['host'] ?? ''))), '.');
        if ($host === '' || $host === 'localhost') {
            continue;
        }
        $out[] = ['host' => $host, 'pri' => (int) ($row['pri'] ?? 0)];
    }
    usort($out, static fn(array $a, array $b): int => $a['pri'] <=> $b['pri']);
    return $out;
}

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
 * @return resource|MailboxVerifyStream
 */
function mailboxVerifyConnect(string $host, int $port, string $encryption, float $timeout)
{
    if (isset($GLOBALS['mailbox_verify_connect_hook']) && is_callable($GLOBALS['mailbox_verify_connect_hook'])) {
        return ($GLOBALS['mailbox_verify_connect_hook'])($host, $port, $encryption, $timeout);
    }

    $remote = $host;
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $remote = '[' . $host . ']';
    }

    $target = ($encryption === 'ssl')
        ? 'ssl://' . $remote . ':' . $port
        : 'tcp://' . $remote . ':' . $port;

    $errno = 0;
    $errstr = '';
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'capture_peer_cert' => false,
        ],
    ]);

    $fp = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if ($fp === false) {
        throw new MailboxVerifyTransportException(
            'connect',
            'connection failed'
        );
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
    if (microtime(true) >= $deadline) {
        throw new MailboxVerifyTransportException('timeout', 'read timeout');
    }
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
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function mailboxVerifyImapLogin(
    string $host,
    int $port,
    string $encryption,
    string $login,
    string $secret,
    string $authMode,
    float $deadline
): array {
    $timeout = min(mailboxVerifyConnectTimeout(), max(0.5, $deadline - microtime(true)));
    $stream = null;
    try {
        $stream = mailboxVerifyConnect($host, $port, $encryption, $timeout);
        if ($encryption === 'tls') {
            mailboxVerifyExpectImapGreeting($stream, $deadline);
            mailboxVerifyWrite($stream, "a000 STARTTLS\r\n");
            $line = mailboxVerifyReadLine($stream, $deadline);
            if (!preg_match('/^a000\s+OK/i', $line)) {
                return mailboxVerifyFail('mailbox_verify.imap_tls_failed', 'imap');
            }
            if ($stream instanceof MailboxVerifyStream) {
                $stream->enableCrypto();
            } elseif (is_resource($stream)) {
                $crypto = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    return mailboxVerifyFail('mailbox_verify.imap_tls_failed', 'imap');
                }
            }
        } else {
            mailboxVerifyExpectImapGreeting($stream, $deadline);
        }

        if ($authMode === 'oauth2') {
            $tag = 'a001';
            mailboxVerifyWrite($stream, $tag . " AUTHENTICATE XOAUTH2\r\n");
            $cont = mailboxVerifyReadLine($stream, $deadline);
            if ($cont === '' || $cont[0] !== '+') {
                return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
            }
            $payload = base64_encode("user={$login}\x01auth=Bearer {$secret}\x01\x01");
            mailboxVerifyWrite($stream, $payload . "\r\n");
            $resp = mailboxVerifyReadLine($stream, $deadline);
            if (!preg_match('/^' . preg_quote($tag, '/') . '\s+OK/i', $resp)) {
                return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
            }
        } else {
            $tag = 'a001';
            // Quoted-string LOGIN; escape quotes/backslashes in credentials for wire form only.
            $qLogin = mailboxVerifyImapQuote($login);
            $qPass = mailboxVerifyImapQuote($secret);
            mailboxVerifyWrite($stream, $tag . ' LOGIN ' . $qLogin . ' ' . $qPass . "\r\n");
            $resp = mailboxVerifyReadLine($stream, $deadline);
            // Skip untagged lines
            while ($resp !== '' && ($resp[0] === '*' || $resp[0] === '+')) {
                $resp = mailboxVerifyReadLine($stream, $deadline);
            }
            if (!preg_match('/^' . preg_quote($tag, '/') . '\s+OK/i', $resp)) {
                return mailboxVerifyFail('mailbox_verify.imap_auth_rejected', 'imap');
            }
        }

        mailboxVerifyWrite($stream, "a002 LOGOUT\r\n");
        return mailboxVerifyOk();
    } catch (MailboxVerifyTransportException $e) {
        return mailboxVerifyMapTransportFail('imap', $e);
    } catch (Throwable $e) {
        mailboxVerifyLog('IMAP probe error: ' . $e->getMessage());
        return mailboxVerifyFail('mailbox_verify.imap_connect_failed', 'imap');
    } finally {
        if ($stream !== null) {
            mailboxVerifyClose($stream);
        }
    }
}

/**
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifyExpectImapGreeting($stream, float $deadline): void
{
    $line = mailboxVerifyReadLine($stream, $deadline);
    if (!preg_match('/^\*\s+(OK|PREAUTH)/i', $line)) {
        throw new MailboxVerifyTransportException('greeting', 'bad IMAP greeting');
    }
}

function mailboxVerifyImapQuote(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

/**
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function mailboxVerifySmtpLogin(
    string $host,
    int $port,
    string $encryption,
    string $login,
    string $secret,
    string $authMode,
    float $deadline
): array {
    $timeout = min(mailboxVerifyConnectTimeout(), max(0.5, $deadline - microtime(true)));
    $stream = null;
    try {
        $stream = mailboxVerifyConnect($host, $port, $encryption === 'ssl' ? 'ssl' : 'none', $timeout);
        mailboxVerifyExpectSmtpGreeting($stream, $deadline);
        mailboxVerifySmtpEhlo($stream, $deadline);

        if ($encryption === 'tls') {
            mailboxVerifyWrite($stream, "STARTTLS\r\n");
            $code = mailboxVerifySmtpReadCode($stream, $deadline);
            if ($code !== 220) {
                return mailboxVerifyFail('mailbox_verify.smtp_tls_failed', 'smtp');
            }
            if ($stream instanceof MailboxVerifyStream) {
                $stream->enableCrypto();
            } elseif (is_resource($stream)) {
                $crypto = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    return mailboxVerifyFail('mailbox_verify.smtp_tls_failed', 'smtp');
                }
            }
            mailboxVerifySmtpEhlo($stream, $deadline);
        }

        if ($authMode === 'oauth2') {
            $authStr = base64_encode("user={$login}\x01auth=Bearer {$secret}\x01\x01");
            mailboxVerifyWrite($stream, 'AUTH XOAUTH2 ' . $authStr . "\r\n");
            $code = mailboxVerifySmtpReadCode($stream, $deadline);
            if ($code !== 235) {
                return mailboxVerifyFail('mailbox_verify.smtp_auth_rejected', 'smtp');
            }
        } else {
            // AUTH PLAIN
            $plain = base64_encode("\0{$login}\0{$secret}");
            mailboxVerifyWrite($stream, 'AUTH PLAIN ' . $plain . "\r\n");
            $code = mailboxVerifySmtpReadCode($stream, $deadline);
            if ($code !== 235) {
                return mailboxVerifyFail('mailbox_verify.smtp_auth_rejected', 'smtp');
            }
        }

        mailboxVerifyWrite($stream, "QUIT\r\n");
        return mailboxVerifyOk();
    } catch (MailboxVerifyTransportException $e) {
        return mailboxVerifyMapTransportFail('smtp', $e);
    } catch (Throwable $e) {
        mailboxVerifyLog('SMTP login probe error: ' . $e->getMessage());
        return mailboxVerifyFail('mailbox_verify.smtp_connect_failed', 'smtp');
    } finally {
        if ($stream !== null) {
            mailboxVerifyClose($stream);
        }
    }
}

/**
 * SMTP recipient probe: EHLO → MAIL FROM → RCPT TO → QUIT (no DATA).
 *
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function mailboxVerifySmtpRcptProbe(string $mxHost, int $port, string $recipient, float $deadline): array
{
    $timeout = min(mailboxVerifyConnectTimeout(), max(0.5, $deadline - microtime(true)));
    $stream = null;
    try {
        $stream = mailboxVerifyConnect($mxHost, $port, 'none', $timeout);
        mailboxVerifyExpectSmtpGreeting($stream, $deadline);
        mailboxVerifySmtpEhlo($stream, $deadline);

        $mailFrom = mailboxVerifyProbeMailFrom();
        $fromCmd = $mailFrom === ''
            ? "MAIL FROM:<>\r\n"
            : 'MAIL FROM:<' . $mailFrom . ">\r\n";
        mailboxVerifyWrite($stream, $fromCmd);
        $fromCode = mailboxVerifySmtpReadCode($stream, $deadline);
        if ($fromCode !== 250) {
            return mailboxVerifyFail('mailbox_verify.rcpt_mailfrom_rejected', 'rcpt');
        }

        mailboxVerifyWrite($stream, 'RCPT TO:<' . $recipient . ">\r\n");
        $rcptCode = mailboxVerifySmtpReadCode($stream, $deadline);
        mailboxVerifyWrite($stream, "QUIT\r\n");

        if (in_array($rcptCode, [250, 251], true)) {
            return mailboxVerifyOk();
        }
        // 450/451/452 greylist / temp — treat as inconclusive but not "exists".
        if ($rcptCode >= 400 && $rcptCode < 500) {
            return mailboxVerifyFail('mailbox_verify.rcpt_deferred', 'rcpt', [
                'email' => $recipient,
                'code' => (string) $rcptCode,
            ]);
        }
        return mailboxVerifyFail('mailbox_verify.rcpt_rejected', 'rcpt', [
            'email' => $recipient,
            'code' => (string) $rcptCode,
        ]);
    } catch (MailboxVerifyTransportException $e) {
        return mailboxVerifyMapTransportFail('rcpt', $e);
    } catch (Throwable $e) {
        mailboxVerifyLog('SMTP RCPT probe error: ' . $e->getMessage());
        return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', [
            'detail' => 'SMTP',
        ]);
    } finally {
        if ($stream !== null) {
            mailboxVerifyClose($stream);
        }
    }
}

/**
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifyExpectSmtpGreeting($stream, float $deadline): void
{
    $code = mailboxVerifySmtpReadCode($stream, $deadline);
    if ($code !== 220) {
        throw new MailboxVerifyTransportException('greeting', 'bad SMTP greeting');
    }
}

/**
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifySmtpEhlo($stream, float $deadline): void
{
    mailboxVerifyWrite($stream, "EHLO mail-proxy-panel\r\n");
    $code = mailboxVerifySmtpReadCode($stream, $deadline);
    if ($code !== 250) {
        mailboxVerifyWrite($stream, "HELO mail-proxy-panel\r\n");
        $code = mailboxVerifySmtpReadCode($stream, $deadline);
        if ($code !== 250) {
            throw new MailboxVerifyTransportException('ehlo', 'EHLO/HELO rejected');
        }
    }
}

/**
 * Read SMTP reply, consuming multi-line 250-… responses; return numeric code.
 *
 * @param resource|MailboxVerifyStream $stream
 */
function mailboxVerifySmtpReadCode($stream, float $deadline): int
{
    $lastCode = 0;
    while (true) {
        $line = mailboxVerifyReadLine($stream, $deadline);
        if (!preg_match('/^(\d{3})([ \-])/', $line, $m)) {
            throw new MailboxVerifyTransportException('protocol', 'bad SMTP reply');
        }
        $lastCode = (int) $m[1];
        if ($m[2] === ' ') {
            return $lastCode;
        }
        // continuation "-" — keep reading
    }
}

/**
 * @return array{ok:bool, code:string, params:array<string,string>, hop:string}
 */
function mailboxVerifyMapTransportFail(string $hop, MailboxVerifyTransportException $e): array
{
    $kind = $e->getKind();
    if ($kind === 'timeout') {
        return mailboxVerifyFail('mailbox_verify.timeout', $hop === 'rcpt' ? 'network' : $hop);
    }
    if ($kind === 'connect') {
        if ($hop === 'rcpt') {
            return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', [
                'detail' => 'SMTP port 25',
            ]);
        }
        $code = $hop === 'imap'
            ? 'mailbox_verify.imap_connect_failed'
            : 'mailbox_verify.smtp_connect_failed';
        return mailboxVerifyFail($code, $hop);
    }
    if ($hop === 'imap') {
        return mailboxVerifyFail('mailbox_verify.imap_connect_failed', 'imap');
    }
    if ($hop === 'smtp') {
        return mailboxVerifyFail('mailbox_verify.smtp_connect_failed', 'smtp');
    }
    return mailboxVerifyFail('mailbox_verify.network_unavailable', 'network', [
        'detail' => 'SMTP',
    ]);
}

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
