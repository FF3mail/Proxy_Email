#!/usr/bin/env php
<?php
declare(strict_types=1);

$failures = 0;
$root = dirname(__DIR__);
$web = $root . '/web';

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

$disablePath = getenv('PANEL_FPM_DISABLE_FUNCTIONS_FILE');
if (!is_string($disablePath) || $disablePath === '' || !is_readable($disablePath)) {
    $tmp = getenv('TEMP') ?: getenv('TMP') ?: sys_get_temp_dir();
    $disablePath = rtrim($tmp, '\\/') . DIRECTORY_SEPARATOR . 'fpm-disable-functions.txt';
}
if (!is_readable($disablePath)) {
    echo "SKIP: disable_functions list file not found\n";
    exit(0);
}

$disableRaw = trim((string) file_get_contents($disablePath));
$disabled = array_filter(array_map('trim', explode(',', $disableRaw)));
$alwaysBanned = ['proc_terminate', 'exec', 'shell_exec', 'system', 'passthru', 'popen'];
$scanNames = array_unique(array_merge($alwaysBanned, $disabled));

$checks = 0;
$check = static function (bool $c, string $m) use (&$checks): void {
    $checks++;
    assert_true($c, $m);
};

$hits = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web));
foreach ($rii as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    if (!str_ends_with($path, '.php')) {
        continue;
    }
    if (str_contains($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        continue;
    }
    foreach ($lines as $num => $line) {
        $trim = ltrim($line);
        if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '#')) {
            continue;
        }
        foreach ($scanNames as $fn) {
            if ($fn === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $fn)) {
                continue;
            }
            if (preg_match('/(?<!->)\b' . preg_quote($fn, '/') . '\s*\(/', $line)) {
                if ($fn === 'proc_open' && str_contains($path, 'panel_whitelist_runner.php')) {
                    continue;
                }
                $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
                $hits[] = $rel . ':' . ($num + 1) . ':' . $fn;
            }
        }
    }
}

$check($hits === [], 'no disabled/banned function calls in web/ (found: ' . implode(', ', $hits) . ')');

$runner = (string) file_get_contents($web . '/includes/panel_whitelist_runner.php');
$check(!str_contains($runner, 'proc_terminate'), 'runner has no proc_terminate');

echo "CHECK_COUNT {$checks}\n";
if ($failures > 0) {
    exit(1);
}
echo "OK: panel web FPM static tests passed\n";
