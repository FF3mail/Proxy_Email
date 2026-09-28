<?php
declare(strict_types=1);

/**
 * Daemon status helpers (no shell_exec). Shared by monitor.php and dashboard.
 */

if (!defined('PANEL_DAEMON_PID_FILE')) {
    define('PANEL_DAEMON_PID_FILE', '/run/mail-proxy/mail-proxy.pid');
}

if (!defined('PANEL_LOG_DIR_DAEMON')) {
    define('PANEL_LOG_DIR_DAEMON', '/var/log/mail-proxy');
}

/**
 * Read PID from daemon pid file (no shell). Returns null if unavailable.
 */
function readDaemonPidFile(string $pidFile = PANEL_DAEMON_PID_FILE): ?int
{
    if (!is_readable($pidFile)) {
        return null;
    }

    $raw = @file_get_contents($pidFile);
    if ($raw === false) {
        return null;
    }

    $pid = (int) trim($raw);
    return $pid > 1 ? $pid : null;
}

/**
 * True if /proc/$pid exists (process table entry).
 */
function isProcPidAlive(int $pid): bool
{
    if ($pid <= 1) {
        return false;
    }

    return @is_dir('/proc/' . $pid);
}

/**
 * Best-effort check that a PID is the mail-proxy daemon.
 * Returns true if evidence matches, false if contradicts, null if unknown.
 */
function procLooksLikeMailProxy(int $pid): ?bool
{
    $cmdlinePath = '/proc/' . $pid . '/cmdline';
    if (is_readable($cmdlinePath)) {
        $cmdline = @file_get_contents($cmdlinePath);
        if ($cmdline === false || $cmdline === '') {
            return null;
        }
        $flat = str_replace("\0", ' ', $cmdline);
        if (stripos($flat, 'mail-proxy-daemon') !== false) {
            return true;
        }
        return false;
    }

    $exe = @readlink('/proc/' . $pid . '/exe');
    if (is_string($exe) && $exe !== '') {
        if (stripos($exe, 'python') !== false) {
            return null;
        }
        return false;
    }

    return null;
}

/**
 * Uptime seconds from /proc/$pid.
 */
function procUptimeSeconds(int $pid): ?int
{
    $statPath = '/proc/' . $pid . '/stat';
    if (is_readable($statPath)) {
        $stat = @file_get_contents($statPath);
        if (is_string($stat) && $stat !== '') {
            $rparen = strrpos($stat, ')');
            if ($rparen !== false) {
                $rest = trim(substr($stat, $rparen + 1));
                $fields = preg_split('/\s+/', $rest) ?: [];
                if (isset($fields[19]) && ctype_digit((string) $fields[19])) {
                    $startTicks = (int) $fields[19];
                    $hz = 100;
                    $uptimeFile = @file_get_contents('/proc/uptime');
                    if (is_string($uptimeFile)) {
                        $parts = explode(' ', trim($uptimeFile), 2);
                        $systemUptime = (float) ($parts[0] ?? 0);
                        if ($systemUptime > 0) {
                            $startSeconds = $startTicks / $hz;
                            return (int) max(0, (int) floor($systemUptime - $startSeconds));
                        }
                    }
                }
            }
        }
    }

    $ctime = @filectime('/proc/' . $pid);
    if ($ctime !== false && $ctime > 0) {
        return max(0, time() - $ctime);
    }

    return null;
}

/**
 * Format seconds as readable uptime.
 */
function formatUptime(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    $parts = [];
    if ($days > 0) {
        $parts[] = __('monitor.uptime_days', ['n' => (string) $days]);
    }
    if ($hours > 0) {
        $parts[] = __('monitor.uptime_hours', ['n' => (string) $hours]);
    }
    $parts[] = __('monitor.uptime_minutes', ['n' => (string) $minutes]);

    return implode(' ', $parts);
}

/**
 * Daemon status without shell_exec.
 *
 * @return array{status: string, uptime: string, pid: int|null}
 */
function getDaemonStatus(): array
{
    $status = 'unknown';
    $uptime = '';
    $pid = null;

    try {
        $filePid = readDaemonPidFile(PANEL_DAEMON_PID_FILE);

        if ($filePid !== null && isProcPidAlive($filePid)) {
            $looks = procLooksLikeMailProxy($filePid);
            if ($looks === false) {
                $status = 'inactive';
            } else {
                $status = 'active';
                $pid = $filePid;
                $seconds = procUptimeSeconds($filePid);
                if ($seconds !== null) {
                    $uptime = formatUptime($seconds);
                }
            }
        } elseif (is_readable(PANEL_DAEMON_PID_FILE)) {
            $status = 'inactive';
        }
    } catch (Throwable) {
        $status = 'unknown';
        $uptime = '';
        $pid = null;
    }

    return ['status' => $status, 'uptime' => $uptime, 'pid' => $pid];
}
