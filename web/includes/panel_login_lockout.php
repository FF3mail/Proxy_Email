<?php
declare(strict_types=1);

/**
 * Panel login lockout (SEC-10 / DAT-18 / issue #136).
 * Source of truth: table panel_login_attempts keyed by login + client IP.
 * Not bound to the PHP session.
 */

const PANEL_LOGIN_MAX_FAILURES = 5;
const PANEL_LOGIN_WINDOW_SECONDS = 900; // 15 minutes
const PANEL_LOGIN_LOCK_SECONDS = 900; // 15 minutes
const PANEL_LOGIN_ATTEMPT_MAX_AGE_SECONDS = 86400; // DAT-18: rows older than one day
const PANEL_LOGIN_PURGE_BATCH = 500;

const PANEL_LOGIN_ATTEMPTS_DDL = <<<'SQL'
CREATE TABLE IF NOT EXISTS panel_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    login VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME(0) NOT NULL COMMENT 'UTC, set by application',
    result ENUM('failure', 'success', 'blocked') NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pla_login_ip_ts (login, ip, attempted_at),
    KEY idx_pla_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

function panelLoginAttemptUtc(int $now): string
{
    return gmdate('Y-m-d H:i:s', $now);
}

function panelLoginAttemptsTableExists(): bool
{
    try {
        $stmt = getPdo()->query('SELECT 1 FROM panel_login_attempts LIMIT 1');
        return $stmt !== false;
    } catch (Throwable) {
        return false;
    }
}

function panelLoginThrottleLockName(string $login, string $ip): string
{
    return 'pla_' . substr(hash('sha256', $login . "\0" . $ip), 0, 56);
}

function panelLoginThrottleBegin(string $login, string $ip): bool
{
    if (!panelLoginAttemptsTableExists()) {
        return false;
    }
    try {
        $pdo = getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return true;
        }
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->execute([panelLoginThrottleLockName($login, $ip)]);
        $got = $stmt->fetchColumn();
        return $got !== false && (int) $got === 1;
    } catch (Throwable $e) {
        writeLog('Panel login throttle lock failed: ' . $e->getMessage());
        return false;
    }
}

function panelLoginThrottleEnd(string $login, string $ip): void
{
    try {
        $pdo = getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([panelLoginThrottleLockName($login, $ip)]);
    } catch (Throwable $e) {
        writeLog('Panel login throttle unlock failed: ' . $e->getMessage());
    }
}

function ensurePanelLoginAttemptsSchema(): void
{
    static $schemaDone = false;
    if ($schemaDone) {
        return;
    }
    $schemaDone = true;

    if (panelLoginAttemptsTableExists()) {
        return;
    }

    try {
        getPdo()->exec(PANEL_LOGIN_ATTEMPTS_DDL);
        writeLog('Panel auth migration: created panel_login_attempts table (idempotent CREATE IF NOT EXISTS)');
    } catch (Throwable $e) {
        writeLog(
            'Panel auth migration failed: could not create panel_login_attempts — '
            . $e->getMessage()
        );
    }
}

function purgePanelLoginAttempts(?int $now = null): void
{
    $now = $now ?? time();
    if (!panelLoginAttemptsTableExists()) {
        return;
    }

    $cutoff = panelLoginAttemptUtc($now - PANEL_LOGIN_ATTEMPT_MAX_AGE_SECONDS);
    $limit = (int) PANEL_LOGIN_PURGE_BATCH;
    try {
        $stmt = getPdo()->prepare(
            "DELETE FROM panel_login_attempts WHERE attempted_at < ? LIMIT {$limit}"
        );
        $stmt->execute([$cutoff]);
    } catch (Throwable $e) {
        writeLog('Panel login attempt purge failed: ' . $e->getMessage());
    }
}

/**
 * @return array{count:int,last_failure_ts:int}
 */
function panelLoginFailureStats(PDO $pdo, string $login, string $ip, int $now): array
{
    $afterSuccessSql = <<<'SQL'
SELECT MAX(attempted_at) AS last_failure
FROM panel_login_attempts
WHERE login = ?
  AND ip = ?
  AND result = 'failure'
  AND id > COALESCE(
        (SELECT MAX(id) FROM panel_login_attempts
         WHERE login = ? AND ip = ? AND result = 'success'),
        0
      )
SQL;
    $stmt = $pdo->prepare($afterSuccessSql);
    $stmt->execute([$login, $ip, $login, $ip]);
    $last = (string) ($stmt->fetchColumn() ?: '');
    if ($last === '') {
        return ['count' => 0, 'last_failure_ts' => 0];
    }
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $last, new DateTimeZone('UTC'));
    if (!$parsed instanceof DateTimeImmutable) {
        return ['count' => 0, 'last_failure_ts' => 0];
    }
    $lastTs = $parsed->getTimestamp();
    $clusterStart = panelLoginAttemptUtc($lastTs - PANEL_LOGIN_WINDOW_SECONDS);
    $countSql = <<<'SQL'
SELECT COUNT(*) FROM panel_login_attempts
WHERE login = ?
  AND ip = ?
  AND result = 'failure'
  AND attempted_at >= ?
  AND attempted_at <= ?
  AND id > COALESCE(
        (SELECT MAX(id) FROM panel_login_attempts
         WHERE login = ? AND ip = ? AND result = 'success'),
        0
      )
SQL;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute([$login, $ip, $clusterStart, $last, $login, $ip]);
    return [
        'count' => (int) $countStmt->fetchColumn(),
        'last_failure_ts' => $lastTs,
    ];
}

function panelLoginPairLockedFor(PDO $pdo, string $login, string $ip, int $now): bool
{
    $stats = panelLoginFailureStats($pdo, $login, $ip, $now);
    if ($stats['count'] < PANEL_LOGIN_MAX_FAILURES) {
        return false;
    }
    if ($stats['last_failure_ts'] <= 0) {
        return false;
    }
    return ($now - $stats['last_failure_ts']) < PANEL_LOGIN_LOCK_SECONDS;
}

function recordPanelLoginAttempt(PDO $pdo, string $login, string $ip, string $result, int $now): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO panel_login_attempts (login, ip, attempted_at, result) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$login, $ip, panelLoginAttemptUtc($now), $result]);
}

function panelLoginThrottleAllow(string $login, string $ip, ?int $now = null): bool
{
    $now = $now ?? time();
    purgePanelLoginAttempts($now);

    if (!panelLoginAttemptsTableExists()) {
        writeLog('Panel login throttle denied: panel_login_attempts table missing');
        return false;
    }

    try {
        return !panelLoginPairLockedFor(getPdo(), $login, $ip, $now);
    } catch (Throwable $e) {
        writeLog('Panel login throttle lookup failed: ' . $e->getMessage());
        return false;
    }
}

function panelLoginThrottleRegisterFailure(string $login, string $ip, ?int $now = null): void
{
    $now = $now ?? time();
    if (!panelLoginAttemptsTableExists()) {
        return;
    }
    try {
        recordPanelLoginAttempt(getPdo(), $login, $ip, 'failure', $now);
    } catch (Throwable $e) {
        writeLog('Panel login failure record failed: ' . $e->getMessage());
    }
}

function panelLoginThrottleRegisterBlocked(string $login, string $ip, ?int $now = null): void
{
    $now = $now ?? time();
    if (!panelLoginAttemptsTableExists()) {
        return;
    }
    try {
        recordPanelLoginAttempt(getPdo(), $login, $ip, 'blocked', $now);
    } catch (Throwable $e) {
        writeLog('Panel login blocked record failed: ' . $e->getMessage());
    }
}

function panelLoginThrottleClear(string $login, string $ip, ?int $now = null): void
{
    $now = $now ?? time();
    if (!panelLoginAttemptsTableExists()) {
        return;
    }
    try {
        recordPanelLoginAttempt(getPdo(), $login, $ip, 'success', $now);
    } catch (Throwable $e) {
        writeLog('Panel login success record failed: ' . $e->getMessage());
    }
}
