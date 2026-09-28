<?php
declare(strict_types=1);

/**
 * Resolve iRedMail mailbox Maildir paths for panel referents.
 *
 * Layout (from deployed iRedMail / vmail.mailbox):
 *   {storagebasedirectory}/{storagenode}/{maildir}{mailboxfolder}
 * Example:
 *   /var/vmail/vmail1/testvps.loc/p/o/s/postmaster/Maildir
 *
 * www-data cannot traverse mailbox directories (mode 0700); lookup uses
 * read-only vmail DB credentials in /etc/mail-proxy/vmail-lookup.conf.
 */
class ReferentMaildirException extends LocalizedUserException
{
}

/**
 * Normalize and validate a referent mailbox email address.
 */
function normalizeReferentEmail(string $email): string
{
    $email = strtolower(trim($email));

    if (
        $email === ''
        || str_contains($email, '..')
        || str_contains($email, '/')
        || str_contains($email, '\\')
        || str_contains($email, "\0")
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        throw new ReferentMaildirException('referent.invalid_email');
    }

    return $email;
}

/**
 * @return array{local: string, domain: string}
 */
function splitReferentMailboxEmail(string $email): array
{
    $email = normalizeReferentEmail($email);
    $at = strrpos($email, '@');

    if ($at === false || $at === 0 || $at === strlen($email) - 1) {
        throw new ReferentMaildirException('referent.invalid_email');
    }

    return [
        'local' => substr($email, 0, $at),
        'domain' => substr($email, $at + 1),
    ];
}

/**
 * @return array<string, string>
 */
function loadVmailLookupConfig(string $configFile = '/etc/mail-proxy/vmail-lookup.conf'): array
{
    if (!is_readable($configFile)) {
        throw new ReferentMaildirException('referent.storage_unavailable');
    }

    $content = file_get_contents($configFile);
    if ($content === false) {
        throw new ReferentMaildirException('referent.storage_unavailable');
    }

    $vmail = parseIniSection($content, 'vmail');

    if (
        $vmail === []
        || empty($vmail['db_user'])
        || !array_key_exists('db_pass', $vmail)
        || empty($vmail['db_name'])
    ) {
        throw new ReferentMaildirException('referent.storage_unavailable');
    }

    return $vmail;
}

function getVmailLookupPdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = loadVmailLookupConfig();
    $host = (string)($cfg['db_host'] ?? '127.0.0.1');
    $port = (int)($cfg['db_port'] ?? 3306);
    $name = (string)$cfg['db_name'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $name
    );

    $pdo = new PDO($dsn, (string)$cfg['db_user'], (string)$cfg['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

/**
 * @param array<string, mixed> $row
 */
function buildMailboxPathFromRow(array $row): string
{
    $base = rtrim((string)($row['storagebasedirectory'] ?? ''), '/');
    $node = trim((string)($row['storagenode'] ?? ''), '/');
    $maildir = trim((string)($row['maildir'] ?? ''), '/');
    $folder = trim((string)($row['mailboxfolder'] ?? 'Maildir'), '/');

    if ($base === '' || $node === '' || $maildir === '' || $folder === '') {
        throw new ReferentMaildirException('referent.storage_unavailable');
    }

    $storageRoot = $base . '/' . $node;
    $path = $storageRoot . '/' . $maildir . '/' . $folder;
    $path = (string)preg_replace('#/+#', '/', $path);

    assertResolvedMaildirPath($path, $storageRoot);

    return $path;
}

function assertResolvedMaildirPath(string $path, string $storageRoot): void
{
    if ($path === '' || $path[0] !== '/') {
        throw new ReferentMaildirException('referent.maildir_invalid');
    }

    if (str_contains($path, '..') || str_contains($path, "\0")) {
        throw new ReferentMaildirException('referent.maildir_invalid');
    }

    $root = rtrim($storageRoot, '/') . '/';
    $normalized = rtrim($path, '/') . '/';

    if (!str_starts_with($normalized, $root)) {
        throw new ReferentMaildirException('referent.maildir_invalid');
    }
}

/**
 * Resolve the Maildir root path for a referent mailbox email.
 *
 * @throws ReferentMaildirException when email is invalid, storage config is missing,
 *                                  or the mailbox does not exist in iRedMail.
 */
function resolveReferentMaildir(string $email): string
{
    $email = normalizeReferentEmail($email);
    $parts = splitReferentMailboxEmail($email);

    $pdo = getVmailLookupPdo();
    $stmt = $pdo->prepare(
        'SELECT m.storagebasedirectory, m.storagenode, m.maildir, m.mailboxfolder
         FROM mailbox m
         INNER JOIN domain d ON d.domain = m.domain AND d.active = 1
         WHERE m.active = 1
           AND m.enabledeliver = 1
           AND (
                m.username = ?
                OR (m.username = ? AND m.domain = ?)
           )
         LIMIT 1'
    );
    $stmt->execute([$email, $parts['local'], $parts['domain']]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new ReferentMaildirException('referent.mailbox_not_found', ['email' => $email]);
    }

    return buildMailboxPathFromRow($row);
}

/**
 * Resolve a user-visible message from any exception (localized or plain).
 */
function exceptionUserMessage(Throwable $e): string
{
    if ($e instanceof LocalizedUserException) {
        return $e->getUserMessage();
    }
    return $e->getMessage();
}
