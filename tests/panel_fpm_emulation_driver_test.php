#!/usr/bin/env php
<?php
declare(strict_types=1);

$failures = 0;
$root = dirname(__DIR__);
$child = $root . '/tests/panel_fpm_emulation_test.php';

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

$listPath = getenv('PANEL_FPM_DISABLE_FUNCTIONS_FILE');
if (!is_string($listPath) || $listPath === '' || !is_readable($listPath)) {
    $tmp = getenv('TEMP') ?: getenv('TMP') ?: sys_get_temp_dir();
    $listPath = rtrim($tmp, '\\/') . DIRECTORY_SEPARATOR . 'fpm-disable-functions.txt';
}
if (!is_readable($listPath)) {
    echo "SKIP: disable_functions list not available\n";
    exit(0);
}

$disable = trim(preg_replace('/\s+/', '', (string) file_get_contents($listPath)) ?? '');
$php = PHP_BINARY;
$cmd = [$php, '-d', 'disable_functions=' . $disable, $child];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null);
if (!is_resource($proc)) {
    echo "FAIL: could not spawn FPM emulation subprocess\n";
    exit(1);
}
$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);

echo $stdout;
if ($stderr !== '') {
    echo $stderr;
}

assert_true($code === 0, 'FPM emulation subprocess exit 0');
if ($failures > 0 || $code !== 0) {
    exit(1);
}
echo "OK: FPM emulation driver passed\n";
