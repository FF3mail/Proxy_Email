#!/usr/bin/env php
<?php
declare(strict_types=1);

$failures = 0;
$root = dirname(__DIR__);

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
        return;
    }
    echo "OK: {$msg}\n";
}

require_once $root . '/web/includes/i18n.php';
setPanelLang('en');
require_once $root . '/web/includes/system_info.php';
require_once $root . '/web/includes/panel_service_status.php';
require_once $root . '/web/includes/helpers.php';

$checks = 0;
$check = static function (bool $c, string $m) use (&$checks): void {
    $checks++;
    assert_true($c, $m);
};

$check(systemInfoParseOsRelease("PRETTY_NAME=\"Ubuntu 22.04.3 LTS\"\n")['pretty'] === 'Ubuntu 22.04.3 LTS', 'os-release pretty');
$check(systemInfoParseIredmailRelease("1.6.8\n") === '1.6.8', 'iredmail release');
$check(systemInfoParseIpv4Addrs("2: eth0 inet 192.168.1.10/24\n") === ['192.168.1.10'], 'ipv4 parse');
$check(systemInfoParseDpkgVersion('1.0', 'install ok installed') === '1.0', 'dpkg version');
$check(systemInfoParseDpkgVersion('x', 'no packages found') === null, 'dpkg not installed');

$tcp443 = "  sl  local_address rem_address   st\n   0: 00000000:01BB 00000000:0000 0A\n";
$check(!empty(systemInfoParseListeningPorts($tcp443, '')[443]), 'tcp listen 443');

$now = 1700000000;
$check(systemInfoClassifyTlsPeer(['validTo_time_t' => $now + 86400 * 30], $now)['chip'] === 'pm-chip-ok', 'tls ok');
$check(systemInfoClassifyTlsPeer(['validTo_time_t' => $now + 86400 * 10], $now)['chip'] === 'pm-chip-warn', 'tls warn 10d');
$check(systemInfoClassifyTlsPeer(['validTo_time_t' => $now - 86400], $now)['chip'] === 'pm-chip-bad', 'tls expired');

$check(systemInfoDnsHasSpf([['txt' => 'v=spf1 mx']]) === 'yes', 'spf yes');
$check(systemInfoDnsHasSpf([]) === 'no', 'spf no');
$check(systemInfoDnsHasSpf(false) === 'na', 'spf na');
$check(systemInfoDnsHasDmarc([['txt' => 'v=DMARC1;']]) === 'yes', 'dmarc yes');
$check(systemInfoDnsHasDkim([['txt' => 'k=rsa; p=abc']]) === 'yes', 'dkim yes');

$check(systemInfoRoleLabel('production') === __('system_info.role_production'), 'role production');
$check(systemInfoOptionalString('PANEL_SERVER_ROLE') === '', 'role fallback empty');

$runner = static fn(array $a): ?string => $a === ['hostname', '-f'] ? 'host.example.test' : null;
$passport = systemInfoCollectPassport($runner);
$check($passport['fqdn'] === 'host.example.test', 'passport inject runner');
$check(str_contains(h('<script>'), '&lt;'), 'h escapes contact-like input');

$resolved = panelServiceResolveUnit(['mariadb.service', 'mysql.service'], static fn($a) => "loaded\nactive\n");
$check($resolved['display'] === 'active', 'alias mariadb resolves');
$notFound = panelServiceResolveUnit(['missing.service'], static fn($a) => null);
$check($notFound['display'] === 'not_installed', 'not installed');

$check(panelOptionalServiceChip('not_installed', 'pm-chip-bad') === 'pm-chip-warn', 'optional not installed warn');

$sys = (string) file_get_contents($root . '/web/includes/system_info.php');
$check(str_contains($sys, 'if (!isPanelMasterDisplay())'), 'master-only render gate');

$dash = (string) file_get_contents($root . '/web/includes/dashboard_ui.php');
$check(str_contains($dash, 'DASHBOARD_MAIL_ACTIVITY_HOURS'), '24h constant');
$check(!str_contains($dash, 'dashboard.quick_nav'), 'quick nav removed');
$check(!preg_match('/pm-head[\s\S]*open_logs/', $dash), 'no logs link in dashboard head');

echo "CHECK_COUNT {$checks}\n";
if ($failures > 0) {
    exit(1);
}
echo "OK: panel system info tests passed\n";
