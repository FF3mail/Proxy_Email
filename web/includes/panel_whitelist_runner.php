<?php
declare(strict_types=1);

/**
 * Fixed whitelist command runner for panel diagnostics (no shell, no interpolation).
 * All proc_open usage in the panel must go through panelWhitelistRun().
 */

/**
 * @param list<string> $argv executable + fixed args only
 */
function panelWhitelistRun(array $argv, float $timeoutSeconds = 2.0): ?string
{
    if ($argv === []) {
        return null;
    }

    static $allowed = null;
    if ($allowed === null) {
        $allowed = panelWhitelistAllowedCommands();
    }

    $key = implode("\0", $argv);
    if (!isset($allowed[$key])) {
        return null;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($argv, $descriptors, $pipes, null, null);
    if (!is_resource($proc)) {
        return null;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $deadline = microtime(true) + max(0.1, $timeoutSeconds);
    while (microtime(true) < $deadline) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            break;
        }
        usleep(20000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_terminate($proc);
    proc_close($proc);

    return $stdout !== '' ? rtrim($stdout, "\r\n") : '';
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
