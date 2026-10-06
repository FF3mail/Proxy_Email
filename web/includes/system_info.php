<?php
declare(strict_types=1);

require_once __DIR__ . '/panel_whitelist_runner.php';

if (!defined('PANEL_SYSTEM_INFO_CACHE')) {
    define('PANEL_SYSTEM_INFO_CACHE', sys_get_temp_dir() . '/mail-proxy-system-info.json');
}
if (!defined('PANEL_SYSTEM_INFO_CACHE_TTL')) {
    define('PANEL_SYSTEM_INFO_CACHE_TTL', 600);
}
if (!defined('PANEL_DKIM_SELECTOR')) {
    define('PANEL_DKIM_SELECTOR', 'dkim');
}

/** @return array{pretty: string, version_id: string, codename: string} */
function systemInfoParseOsRelease(string $content): array
{
    $out = ['pretty' => '', 'version_id' => '', 'codename' => ''];
    foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
        if (preg_match('/^PRETTY_NAME="(.*)"\s*$/', $line, $m)) {
            $out['pretty'] = $m[1];
        } elseif (preg_match('/^VERSION_ID="?(.*?)"?\s*$/', $line, $m)) {
            $out['version_id'] = $m[1];
        } elseif (preg_match('/^VERSION_CODENAME=(.*)$/', $line, $m)) {
            $out['codename'] = trim($m[1], " \t\"");
        }
    }
    return $out;
}

function systemInfoParseIredmailRelease(string $content): ?string
{
    $line = trim(preg_split('/\r?\n/', trim($content))[0] ?? '');
    return $line !== '' ? $line : null;
}

/** @return list<string> */
function systemInfoParseIpv4Addrs(string $ipOutput): array
{
    $addrs = [];
    foreach (preg_split('/\r?\n/', trim($ipOutput)) ?: [] as $line) {
        if (!preg_match('/\sinet\s+([\d.]+)/', $line, $m)) {
            continue;
        }
        if (str_starts_with($m[1], '127.')) {
            continue;
        }
        $addrs[] = $m[1];
    }
    return array_values(array_unique($addrs));
}

function systemInfoParseDpkgVersion(?string $versionLine, ?string $statusLine): ?string
{
    if ($statusLine !== null && str_contains($statusLine, 'install ok installed')) {
        $ver = trim((string) $versionLine);
        return $ver !== '' ? $ver : __('system_info.installed');
    }
    return null;
}

/** @return array<int, true> */
function systemInfoParseListeningPorts(string $tcp4, string $tcp6): array
{
    $listen = [];
    $scan = static function (string $blob) use (&$listen): void {
        foreach (preg_split('/\r?\n/', $blob) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 4 || ($parts[3] ?? '') !== '0A') {
                continue;
            }
            $local = $parts[1] ?? '';
            if (!str_contains($local, ':')) {
                continue;
            }
            $port = hexdec(explode(':', $local, 2)[1]);
            if ($port > 0 && $port <= 65535) {
                $listen[$port] = true;
            }
        }
    };
    $scan($tcp4);
    $scan($tcp6);
    return $listen;
}

/** @return array{type: string, expiry: string, days_left: int, chip: string} */
function systemInfoClassifyTlsPeer(array $certInfo, int $now): array
{
    $expiryTs = (int) ($certInfo['validTo_time_t'] ?? 0);
    $days = $expiryTs > 0 ? (int) floor(($expiryTs - $now) / 86400) : 0;
    $subject = $certInfo['subject'] ?? [];
    $issuer = $certInfo['issuer'] ?? [];
    $selfSigned = ($subject === $issuer);
    $type = $selfSigned ? __('system_info.tls_self_signed') : (string) ($issuer['O'] ?? __('system_info.tls_issuer_unknown'));
    $expiry = $expiryTs > 0 ? gmdate('Y-m-d', $expiryTs) : __('system_info.na');
    $chip = $days < 0 ? 'pm-chip-bad' : ($days < 14 ? 'pm-chip-warn' : 'pm-chip-ok');
    return ['type' => $type, 'expiry' => $expiry, 'days_left' => $days, 'chip' => $chip];
}

function systemInfoDnsHasSpf($records): string
{
    if (!is_array($records)) {
        return 'na';
    }
    foreach ($records as $rec) {
        $txt = (string) ($rec['txt'] ?? '');
        if (stripos($txt, 'v=spf1') === 0) {
            return 'yes';
        }
    }
    return 'no';
}

function systemInfoDnsHasDmarc($records): string
{
    if (!is_array($records)) {
        return 'na';
    }
    foreach ($records as $rec) {
        $txt = (string) ($rec['txt'] ?? '');
        if (stripos($txt, 'v=DMARC1') === 0) {
            return 'yes';
        }
    }
    return 'no';
}

function systemInfoDnsHasDkim($records): string
{
    if (!is_array($records)) {
        return 'na';
    }
    foreach ($records as $rec) {
        $txt = (string) ($rec['txt'] ?? '');
        if ($txt !== '' && !str_starts_with(strtolower($txt), 'v=spf1')) {
            return 'yes';
        }
    }
    return 'no';
}

function systemInfoRoleLabel(?string $role): string
{
    return match ($role) {
        'production' => __('system_info.role_production'),
        'test' => __('system_info.role_test'),
        'backup_mx' => __('system_info.role_backup_mx'),
        default => __('system_info.role_unset'),
    };
}

function systemInfoOptionalString(string $const): string
{
    if (defined($const)) {
        $v = constant($const);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    return '';
}

function systemInfoDnsLabel(string $state): string
{
    return match ($state) {
        'yes' => __('system_info.dns_found'),
        'no' => __('system_info.dns_absent'),
        default => __('system_info.na'),
    };
}

function systemInfoProbeTls(int $port, int $now): array
{
    if (!function_exists('stream_socket_client')) {
        return ['port' => $port, 'type' => __('system_info.na'), 'expiry' => __('system_info.na'), 'days_left' => 0, 'chip' => 'pm-chip-warn'];
    }
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
    $client = @stream_socket_client('ssl://127.0.0.1:' . $port, $errno, $errstr, 2.0, STREAM_CLIENT_CONNECT, $ctx);
    if ($client === false) {
        return ['port' => $port, 'type' => __('system_info.na'), 'expiry' => __('system_info.na'), 'days_left' => 0, 'chip' => 'pm-chip-warn'];
    }
    $params = stream_context_get_params($client);
    fclose($client);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert || !is_array($info = openssl_x509_parse($cert))) {
        return ['port' => $port, 'type' => __('system_info.na'), 'expiry' => __('system_info.na'), 'days_left' => 0, 'chip' => 'pm-chip-warn'];
    }
    return ['port' => $port] + systemInfoClassifyTlsPeer($info, $now);
}

/** @param callable(list<string>): ?string $runner */
function systemInfoCollectPassport(callable $runner): array
{
    $fqdn = trim((string) ($runner(['hostname', '-f']) ?? $runner(['hostname']) ?? ''));
    $os = systemInfoParseOsRelease(is_readable('/etc/os-release') ? (string) @file_get_contents('/etc/os-release') : '');
    $ired = __('system_info.na');
    if (is_readable('/etc/iredmail-release')) {
        $parsed = systemInfoParseIredmailRelease((string) @file_get_contents('/etc/iredmail-release'));
        if ($parsed !== null) {
            $ired = $parsed;
        }
    }
    $internal = systemInfoParseIpv4Addrs($runner(['ip', '-o', '-4', 'addr']) ?? '');
    return [
        'fqdn' => $fqdn !== '' ? $fqdn : __('system_info.na'),
        'internal_ips' => $internal !== [] ? implode(', ', $internal) : __('system_info.na'),
        'external_ip' => systemInfoOptionalString('PANEL_SERVER_EXTERNAL_IP') ?: __('system_info.external_unset'),
        'os_pretty' => $os['pretty'] !== '' ? $os['pretty'] : __('system_info.na'),
        'iredmail' => $ired,
        'role' => systemInfoRoleLabel(systemInfoOptionalString('PANEL_SERVER_ROLE') ?: null),
        'installed_at' => systemInfoOptionalString('PANEL_SERVER_INSTALLED_AT') ?: __('system_info.unset'),
        'installed_by' => systemInfoOptionalString('PANEL_SERVER_INSTALLED_BY') ?: __('system_info.unset'),
        'owner_contact' => systemInfoOptionalString('PANEL_SERVER_OWNER_CONTACT') ?: __('system_info.unset'),
    ];
}

/** @param callable(list<string>): ?string $runner */
function systemInfoCollectSoftware(callable $runner): array
{
    $packages = [
        ['postfix', 'system_info.sw_postfix', 'system_info.purpose_mta'],
        ['dovecot-core', 'system_info.sw_dovecot', 'system_info.purpose_imap'],
        ['nginx', 'system_info.sw_nginx', 'system_info.purpose_web'],
        ['mariadb-server', 'system_info.sw_mariadb', 'system_info.purpose_db'],
        ['mysql-server', 'system_info.sw_mysql', 'system_info.purpose_db'],
        ['amavisd-new', 'system_info.sw_amavis', 'system_info.purpose_filter'],
        ['clamav-daemon', 'system_info.sw_clamav', 'system_info.purpose_av'],
        ['spamassassin', 'system_info.sw_spamassassin', 'system_info.purpose_spam'],
    ];
    $rows = [];
    $dbDone = false;
    foreach ($packages as [$pkg, $compKey, $purposeKey]) {
        if ($purposeKey === 'system_info.purpose_db' && $dbDone) {
            continue;
        }
        $ver = $runner(['dpkg-query', '-W', '-f=${Version}', $pkg]);
        $st = $runner(['dpkg-query', '-W', '-f=${Status}', $pkg]);
        $parsed = systemInfoParseDpkgVersion($ver, $st);
        if ($parsed === null) {
            if ($purposeKey === 'system_info.purpose_db') {
                continue;
            }
            $rows[] = ['component' => __($compKey), 'purpose' => __($purposeKey), 'version' => __('system_info.not_installed'), 'state' => 'neutral'];
            continue;
        }
        if ($purposeKey === 'system_info.purpose_db') {
            $dbDone = true;
        }
        $rows[] = ['component' => __($compKey), 'purpose' => __($purposeKey), 'version' => $parsed, 'state' => 'ok'];
    }
    return $rows;
}

/** @return array{rows: list<array>, extra: int} */
function systemInfoCollectDomainDns(?PDO $pdo, callable $dnsFn): array
{
    try {
        $stmt = $pdo?->query('SELECT domain FROM domain WHERE active = 1 ORDER BY domain LIMIT 21');
        $all = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    } catch (Throwable) {
        return ['rows' => [], 'extra' => 0];
    }
    $extra = max(0, count($all) - 20);
    $selector = (string) PANEL_DKIM_SELECTOR;
    $rows = [];
    foreach (array_slice($all, 0, 20) as $domain) {
        $domain = (string) $domain;
        $rows[] = [
            'domain' => $domain,
            'spf' => systemInfoDnsHasSpf($dnsFn($domain, DNS_TXT)),
            'dmarc' => systemInfoDnsHasDmarc($dnsFn('_dmarc.' . $domain, DNS_TXT)),
            'dkim' => systemInfoDnsHasDkim($dnsFn($selector . '._domainkey.' . $domain, DNS_TXT)),
        ];
    }
    return ['rows' => $rows, 'extra' => $extra];
}

/** @param callable(list<string>): ?string $runner */
function systemInfoCollectSecurity(callable $runner, int $now): array
{
    $tls = [systemInfoProbeTls(443, $now), systemInfoProbeTls(993, $now)];
    $listening = systemInfoParseListeningPorts(
        is_readable('/proc/net/tcp') ? (string) @file_get_contents('/proc/net/tcp') : '',
        is_readable('/proc/net/tcp6') ? (string) @file_get_contents('/proc/net/tcp6') : ''
    );
    $portRows = [];
    foreach ([25, 465, 587, 143, 993, 110, 995, 80, 443] as $p) {
        $portRows[] = ['port' => $p, 'listening' => !empty($listening[$p])];
    }
    $ufw = trim((string) ($runner(['systemctl', 'is-active', 'ufw.service']) ?? ''));
    $nft = trim((string) ($runner(['systemctl', 'is-active', 'nftables.service']) ?? ''));
    return [
        'tls' => $tls,
        'ports' => $portRows,
        'firewall_active' => ($ufw === 'active' || $nft === 'active'),
        'fail2ban' => trim((string) ($runner(['systemctl', 'is-active', 'fail2ban.service']) ?? '')) ?: __('system_info.na'),
        'fail2ban_log' => is_readable('/var/log/fail2ban.log') ? '/var/log/fail2ban.log' : '',
    ];
}

/** @return array<string, mixed> */
function systemInfoBuildView(?PDO $pdo = null, ?callable $runner = null, ?int $now = null): array
{
    $runner ??= 'panelWhitelistRun';
    $now ??= time();
    $cachePath = PANEL_SYSTEM_INFO_CACHE;
    if (is_readable($cachePath)) {
        $cached = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($cached) && ($now - (int) ($cached['ts'] ?? 0)) < (int) PANEL_SYSTEM_INFO_CACHE_TTL) {
            return $cached['view'];
        }
    }
    $dnsFn = static function (string $name, int $type) {
        return function_exists('dns_get_record') ? @dns_get_record($name, $type) : false;
    };
    $dnsBlock = systemInfoCollectDomainDns($pdo, $dnsFn);
    $view = [
        'passport' => systemInfoCollectPassport($runner),
        'software' => systemInfoCollectSoftware($runner),
        'security' => systemInfoCollectSecurity($runner, $now) + [
            'domains' => $dnsBlock['rows'],
            'domain_extra' => $dnsBlock['extra'],
        ],
    ];
    @file_put_contents($cachePath, json_encode(['ts' => $now, 'view' => $view], JSON_UNESCAPED_UNICODE));
    return $view;
}

function renderDashboardSystemInfoSection(?PDO $pdo = null): void
{
    if (!isPanelMasterDisplay()) {
        return;
    }
    $view = systemInfoBuildView($pdo);
    $passport = $view['passport'];
    $software = $view['software'];
    $security = $view['security'];
    ?>
    <div class="pm-card" style="margin-top:4px">
        <div class="pm-ch"><h3><?= h(__('system_info.title')) ?></h3></div>
        <details open><summary><?= h(__('system_info.passport_title')) ?></summary>
            <dl class="pm-dl">
                <?php foreach ([
                    'fqdn' => 'system_info.fqdn',
                    'internal_ips' => 'system_info.internal_ip',
                    'external_ip' => 'system_info.external_ip',
                    'os_pretty' => 'system_info.os',
                    'iredmail' => 'system_info.iredmail',
                    'role' => 'system_info.role',
                    'installed_at' => 'system_info.installed_at',
                    'installed_by' => 'system_info.installed_by',
                    'owner_contact' => 'system_info.owner_contact',
                ] as $key => $labelKey): ?>
                    <dt><?= h(__($labelKey)) ?></dt><dd><?= h((string) $passport[$key]) ?></dd>
                <?php endforeach; ?>
            </dl>
        </details>
        <details><summary><?= h(__('system_info.software_title')) ?></summary>
            <table class="pm-table"><thead><tr>
                <th><?= h(__('system_info.col_component')) ?></th>
                <th><?= h(__('system_info.col_purpose')) ?></th>
                <th><?= h(__('system_info.col_version')) ?></th>
            </tr></thead><tbody>
            <?php foreach ($software as $row): ?>
                <tr><td><?= h((string) $row['component']) ?></td><td><?= h((string) $row['purpose']) ?></td><td><?= h((string) $row['version']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </details>
        <details><summary><?= h(__('system_info.security_title')) ?></summary>
            <?php foreach ($security['tls'] as $tls): ?>
                <p class="pm-hint"><?= h(__('system_info.tls_port', ['port' => (string) $tls['port']])) ?>:
                    <span class="pm-chip <?= h((string) $tls['chip']) ?>"><?= h((string) $tls['type']) ?></span>
                    <?= h(__('system_info.tls_expiry', ['date' => (string) $tls['expiry'], 'days' => (string) $tls['days_left']])) ?></p>
            <?php endforeach; ?>
            <?php if (($security['domains'] ?? []) !== []): ?>
                <table class="pm-table"><thead><tr><th><?= h(__('system_info.col_domain')) ?></th><th>SPF</th><th>DMARC</th><th>DKIM</th></tr></thead><tbody>
                <?php foreach ($security['domains'] as $drow): ?>
                    <tr><td><?= h((string) $drow['domain']) ?></td>
                        <td><?= h(systemInfoDnsLabel((string) $drow['spf'])) ?></td>
                        <td><?= h(systemInfoDnsLabel((string) $drow['dmarc'])) ?></td>
                        <td><?= h(systemInfoDnsLabel((string) $drow['dkim'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php if ((int) ($security['domain_extra'] ?? 0) > 0): ?>
                    <p class="pm-hint"><?= h(__('system_info.domains_more', ['n' => (string) $security['domain_extra']])) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p class="pm-hint"><?= h(__('system_info.domains_na')) ?></p>
            <?php endif; ?>
            <p class="pm-hint"><?= h(__('system_info.firewall')) ?>:
                <?= !empty($security['firewall_active']) ? h(__('system_info.firewall_on')) : h(__('system_info.firewall_off')) ?></p>
            <ul class="pm-steps"><?php foreach ($security['ports'] as $prow): ?>
                <li><span class="pm-mono"><?= (int) $prow['port'] ?></span>
                    <?= !empty($prow['listening']) ? h(__('system_info.port_listen_yes')) : h(__('system_info.port_listen_no')) ?></li>
            <?php endforeach; ?></ul>
            <p class="pm-hint"><?= h(__('system_info.fail2ban')) ?>: <?= h((string) $security['fail2ban']) ?></p>
            <?php if (($security['fail2ban_log'] ?? '') !== ''): ?>
                <p class="pm-hint"><?= h(__('system_info.fail2ban_log')) ?>: <?= h((string) $security['fail2ban_log']) ?></p>
            <?php endif; ?>
        </details>
    </div>
    <?php
}
