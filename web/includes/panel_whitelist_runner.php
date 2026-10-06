<?php
declare(strict_types=1);

/**
 * Fixed whitelist command runner for panel diagnostics (no shell, no interpolation).
 * All proc_open usage in the panel must go through panelWhitelistRun().
 */

if (!defined('PANEL_WHITELIST_PAGE_BUDGET_SECONDS')) {
    define('PANEL_WHITELIST_PAGE_BUDGET_SECONDS', 4.0);
}

if (!defined('PANEL_WHITELIST_MAX_OUTPUT_BYTES')) {
    define('PANEL_WHITELIST_MAX_OUTPUT_BYTES', 65536);
}

/** @var float|null */
$GLOBALS['_panel_whitelist_page_deadline'] = null;

/** @var callable|null */
$GLOBALS['_panel_whitelist_proc_open_factory'] = null;

function panelWhitelistResetPageBudget(): void
{
    $GLOBALS['_panel_whitelist_page_deadline'] = null;
}

/**
 * Test hook: inject proc_open replacement.
 *
 * @param callable(array, array, array|null, array|null, array|null): resource|false|null $factory
 */
function panelWhitelistSetProcOpenFactory(?callable $factory): void
{
    $GLOBALS['_panel_whitelist_proc_open_factory'] = $factory;
}

/**
 * @return array{ok: bool, exit_code: int|null, stdout: string, stderr: string, timed_out: bool, unavailable: bool}
 */
function panelWhitelistEmptyResult(bool $unavailable = false, bool $timedOut = false): array
{
    return [
        'ok' => false,
        'exit_code' => null,
        'stdout' => '',
        'stderr' => '',
        'timed_out' => $timedOut,
        'unavailable' => $unavailable,
    ];
}

function panelWhitelistRemainingBudgetSeconds(): float
{
    $deadline = $GLOBALS['_panel_whitelist_page_deadline'];
    if ($deadline === null) {
        $deadline = microtime(true) + (float) PANEL_WHITELIST_PAGE_BUDGET_SECONDS;
        $GLOBALS['_panel_whitelist_page_deadline'] = $deadline;
    }
    return max(0.0, $deadline - microtime(true));
}

function panelWhitelistUseCoreutilsTimeout(): bool
{
    return is_executable('/usr/bin/timeout');
}

/**
 * @param list<string> $argv
 * @return array{ok: bool, exit_code: int|null, stdout: string, stderr: string, timed_out: bool, unavailable: bool}
 */
function panelWhitelistRunEx(array $argv, float $timeoutSeconds = 2.0): array
{
    if ($argv === []) {
        return panelWhitelistEmptyResult(true);
    }

    if (!function_exists('proc_open') || !function_exists('proc_get_status')) {
        return panelWhitelistEmptyResult(true);
    }

    $remainingBudget = panelWhitelistRemainingBudgetSeconds();
    if ($remainingBudget <= 0.0) {
        return panelWhitelistEmptyResult(true);
    }

    $timeoutSeconds = min(max(0.1, $timeoutSeconds), $remainingBudget);

    static $allowed = null;
    if ($allowed === null) {
        $allowed = panelWhitelistAllowedCommands();
    }

    $key = implode("\0", $argv);
    if (!isset($allowed[$key])) {
        return panelWhitelistEmptyResult(true);
    }

    $execArgv = $argv;
    if (panelWhitelistUseCoreutilsTimeout()) {
        $sec = (string) max(1, (int) ceil($timeoutSeconds));
        $execArgv = array_merge(['/usr/bin/timeout', '--kill-after=1', $sec], $argv);
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $pipes = [];
    $factory = $GLOBALS['_panel_whitelist_proc_open_factory'];
    if (is_callable($factory)) {
        $built = $factory($execArgv, $descriptors);
        if (!is_array($built) || count($built) < 2) {
            return panelWhitelistEmptyResult(true);
        }
        $proc = $built[0];
        $pipes = $built[1];
    } else {
        $proc = @proc_open($execArgv, $descriptors, $pipes, null, null);
    }

    if (!is_resource($proc)) {
        return panelWhitelistEmptyResult(true);
    }

    if (!is_array($pipes)) {
        return panelWhitelistEmptyResult(true);
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    if (isset($pipes[1]) && is_resource($pipes[1])) {
        stream_set_blocking($pipes[1], false);
    }
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        stream_set_blocking($pipes[2], false);
    }

    $stdout = '';
    $stderr = '';
    $maxOut = (int) PANEL_WHITELIST_MAX_OUTPUT_BYTES;
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;

    while (microtime(true) < $deadline) {
        $read = [];
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $read[] = $pipes[2];
        }

        if ($read !== [] && function_exists('stream_select')) {
            $write = null;
            $except = null;
            $tvSec = 0;
            $tvUsec = 200000;
            @stream_select($read, $write, $except, $tvSec, $tvUsec);
            foreach ($read as $stream) {
                if (strlen($stdout) + strlen($stderr) >= $maxOut) {
                    break 2;
                }
                $chunk = (string) @stream_get_contents($stream, min(8192, $maxOut - strlen($stdout) - strlen($stderr)));
                if ($stream === ($pipes[1] ?? null)) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        } else {
            if (isset($pipes[1]) && is_resource($pipes[1]) && strlen($stdout) < $maxOut) {
                $stdout .= (string) @stream_get_contents($pipes[1], min(8192, $maxOut - strlen($stdout)));
            }
            if (isset($pipes[2]) && is_resource($pipes[2]) && strlen($stderr) < $maxOut) {
                $stderr .= (string) @stream_get_contents($pipes[2], min(8192, $maxOut - strlen($stderr)));
            }
            usleep(20000);
        }

        $status = proc_get_status($proc);
        if (!$status['running']) {
            if (isset($pipes[1]) && is_resource($pipes[1]) && strlen($stdout) < $maxOut) {
                $stdout .= (string) @stream_get_contents($pipes[1], $maxOut - strlen($stdout));
            }
            if (isset($pipes[2]) && is_resource($pipes[2]) && strlen($stderr) < $maxOut) {
                $stderr .= (string) @stream_get_contents($pipes[2], $maxOut - strlen($stderr));
            }
            $exitCode = (int) ($status['exitcode'] ?? -1);
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
            if (function_exists('proc_close')) {
                proc_close($proc);
            }
            return [
                'ok' => $exitCode === 0,
                'exit_code' => $exitCode,
                'stdout' => rtrim($stdout, "\r\n"),
                'stderr' => rtrim($stderr, "\r\n"),
                'timed_out' => false,
                'unavailable' => false,
            ];
        }
    }

    $timedOut = true;
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        fclose($pipes[1]);
    }
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        fclose($pipes[2]);
    }

    // Without a process-kill API (disabled on hardened FPM): if coreutils timeout was used, the child
    // should exit soon; if still running, do not block in proc_close — return timed_out and let the
    // child finish in the background (short-lived whitelist commands only).
    $status = proc_get_status($proc);
    if (!$status['running'] && function_exists('proc_close')) {
        proc_close($proc);
    }

    return [
        'ok' => false,
        'exit_code' => null,
        'stdout' => rtrim($stdout, "\r\n"),
        'stderr' => rtrim($stderr, "\r\n"),
        'timed_out' => $timedOut,
        'unavailable' => false,
    ];
}

/**
 * @param list<string> $argv executable + fixed args only
 */
function panelWhitelistRun(array $argv, float $timeoutSeconds = 2.0): ?string
{
    $result = panelWhitelistRunEx($argv, $timeoutSeconds);
    if ($result['unavailable'] || !$result['ok']) {
        return null;
    }
    return $result['stdout'] !== '' ? $result['stdout'] : '';
}

/**
 * @return array<string, true>
 */
function panelWhitelistAllowedCommands(): array
{
    $cmds = [];
    $add = static function (array $argv) use (&$cmds): void {
        $cmds[implode("\0", $argv)] = true;
    };

    $add(['hostname', '-f']);
    $add(['hostname']);
    $add(['ip', '-o', '-4', 'addr']);

    $packages = [
        'postfix', 'dovecot-core', 'nginx', 'mariadb-server', 'mysql-server',
        'amavisd-new', 'clamav-daemon', 'spamassassin',
    ];
    foreach ($packages as $pkg) {
        $add(['dpkg-query', '-W', '-f=${Version}', $pkg]);
        $add(['dpkg-query', '-W', '-f=${Status}', $pkg]);
    }

    $units = [
        'postfix', 'dovecot', 'nginx', 'mariadb', 'mysql', 'mysqld',
        'amavis', 'amavisd', 'clamav-daemon', 'spamassassin', 'spamd',
        'fail2ban', 'mail-proxy', 'ufw', 'nftables',
    ];
    foreach ($units as $unit) {
        $name = str_ends_with($unit, '.service') ? $unit : $unit . '.service';
        $add(['systemctl', 'is-active', $name]);
        $add(['systemctl', 'show', '-p', 'LoadState,ActiveState', '--value', $name]);
    }

    return $cmds;
}
