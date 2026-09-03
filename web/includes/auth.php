<?php
declare(strict_types=1);

/**
 * Panel authentication helpers (PROMPT 24).
 *
 * Application-level rule: the web UI must never INSERT or UPDATE a row to
 * role='master'. Exactly one master is seeded by the installer. Preventing a
 * second master via raw SQL is an accepted limitation (no DB trigger).
 */

const PANEL_LOGIN_MAX_FAILURES = 5;
const PANEL_LOGIN_WINDOW_SECONDS = 900; // 15 minutes

/**
 * Require any active panel operator (master or admin).
 * Re-queries active=1 so a deactivated account loses access on the next request.
 */
function requirePanelAdmin(): void
{
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        redirectToLogin();
    }

    $row = fetchPanelAdminById($adminId);
    if ($row === null || !(int)$row['active']) {
        unset($_SESSION['admin_id'], $_SESSION['admin_role_display'], $_SESSION['admin_username_display']);
        writeLog('Panel session rejected: admin_id=' . $adminId . ' inactive or missing ip=' . getClientIp());
        redirectToLogin();
    }

    // Display-only cache for nav chrome; never trusted by requireMasterAdmin().
    $_SESSION['admin_role_display'] = (string)$row['role'];
    $_SESSION['admin_username_display'] = (string)$row['username'];
}

/**
 * Require an active master. Always re-queries role and active from DB.
 * Do not trust $_SESSION['admin_role_display'] for this gate.
 */
function requireMasterAdmin(): void
{
    requirePanelAdmin();

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $row = fetchPanelAdminById($adminId);
    if (
        $row === null
        || !(int)$row['active']
        || (string)$row['role'] !== 'master'
    ) {
        writeLog(
            'Panel master gate denied for admin_id=' . $adminId
            . ' user=' . (string)($row['username'] ?? '')
            . ' ip=' . getClientIp()
        );
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>403 Forbidden</title></head>'
            . '<body><h1>403 Forbidden</h1><p>Master privileges required.</p></body></html>';
        exit();
    }
}

/**
 * @return array{id:int|string,username:string,password_hash:string,role:string,active:int|string}|null
 */
function fetchPanelAdminById(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = getPdo()->prepare(
        'SELECT id, username, password_hash, role, active FROM panel_admins WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * @return array{id:int|string,username:string,password_hash:string,role:string,active:int|string}|null
 */
function fetchPanelAdminByUsername(string $username): ?array
{
    $stmt = getPdo()->prepare(
        'SELECT id, username, password_hash, role, active FROM panel_admins WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function redirectToLogin(): void
{
    header('Location: /index.php?action=login');
    exit();
}

function panelLoginThrottleAllow(): bool
{
    $now = time();
    $windowStart = (int)($_SESSION['login_fail_window_start'] ?? 0);
    $count = (int)($_SESSION['login_fail_count'] ?? 0);

    if ($windowStart <= 0 || ($now - $windowStart) >= PANEL_LOGIN_WINDOW_SECONDS) {
        $_SESSION['login_fail_window_start'] = $now;
        $_SESSION['login_fail_count'] = 0;
        return true;
    }

    return $count < PANEL_LOGIN_MAX_FAILURES;
}

function panelLoginThrottleRegisterFailure(): void
{
    $now = time();
    $windowStart = (int)($_SESSION['login_fail_window_start'] ?? 0);
    if ($windowStart <= 0 || ($now - $windowStart) >= PANEL_LOGIN_WINDOW_SECONDS) {
        $_SESSION['login_fail_window_start'] = $now;
        $_SESSION['login_fail_count'] = 1;
        return;
    }
    $_SESSION['login_fail_count'] = (int)($_SESSION['login_fail_count'] ?? 0) + 1;
}

function panelLoginThrottleClear(): void
{
    unset($_SESSION['login_fail_window_start'], $_SESSION['login_fail_count']);
}

/**
 * Establish panel session after successful password_verify().
 */
function establishPanelSession(array $adminRow): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$adminRow['id'];
    $_SESSION['admin_role_display'] = (string)$adminRow['role'];
    $_SESSION['admin_username_display'] = (string)$adminRow['username'];
    panelLoginThrottleClear();
}

function clearPanelSession(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_role_display'], $_SESSION['admin_username_display']);
}

function isPanelMasterDisplay(): bool
{
    return ($_SESSION['admin_role_display'] ?? '') === 'master';
}
