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

$os = systemInfoParseOsRelease("PRETTY_NAME=\"Ubuntu 22.04.3 LTS\"\nVERSION_ID=\"22.04\"\n");
assert_true($os['pretty'] === 'Ubuntu 22.04.3 LTS', 'os-release pretty');
assert_true($os['version_id'] === '22.04', 'os-release version');

$ired = systemInfoParseIredmailRelease("1.6.8\n");
assert_true($ired === '1.6.8', 'iredmail release line');

$ips = systemInfoParseIpv4Addrs("2: eth0    inet 192.168.1.10/24 scope global\n");
assert_true($ips === ['192.168.1.10'], 'ipv4 parse skips loopback');

$ver = systemInfoParseDpkgVersion('3.6.4', 'install ok installed');
assert_true($ver === '3.6.4', 'dpkg version parse');

$tcp = "  sl  local_address rem_address   st\n   0: 00000000:001B 00000000:0000 0A\n";
$ports = systemInfoParseListeningPorts($tcp, '');
assert_true(!empty($ports[443]) || !empty($ports[27]), 'proc net tcp listen parse');

$class = systemInfoClassifyTlsPeer(['validTo_time_t' => time() + 86400 * 30, 'subject' => ['CN' => 'a'], 'issuer' => ['O' => 'CA']], time());
assert_true($class['chip'] === 'pm-chip-ok', 'tls ok chip');

assert_true(systemInfoDnsHasSpf([['txt' => 'v=spf1 mx -all']]) === 'yes', 'spf detect');
assert_true(systemInfoDnsHasDmarc([['txt' => 'v=DMARC1; p=none']]) === 'yes', 'dmarc detect');

assert_true(systemInfoRoleLabel('test') === __('system_info.role_test'), 'role map');

$runner = static function (array $argv): ?string {
    if ($argv === ['hostname', '-f']) {
        return 'mail.example.test';
    }
    return null;
};
$passport = systemInfoCollectPassport($runner);
assert_true($passport['fqdn'] === 'mail.example.test', 'passport fqdn from runner');

$state = panelServiceStateToDisplay('loaded', 'active');
assert_true($state['display'] === 'active', 'service active');

$bad = panelHealthOverallChip(
    [['critical' => true, 'display' => 'inactive']],
    ['status' => 'active']
);
assert_true($bad['class'] === 'pm-chip-bad', 'overall bad on critical inactive');

$dash = (string) file_get_contents($root . '/web/includes/dashboard_ui.php');
assert_true(str_contains($dash, 'isPanelMasterDisplay') || str_contains($dash, 'renderDashboardSystemInfoSection'), 'system section gated');
assert_true(str_contains($dash, 'DASHBOARD_MAIL_ACTIVITY_HOURS'), 'mail window constant');
assert_true(!str_contains($dash, 'dashboard.quick_nav'), 'quick nav removed');

if ($failures > 0) {
    exit(1);
}
echo "OK: panel system info tests passed\n";
