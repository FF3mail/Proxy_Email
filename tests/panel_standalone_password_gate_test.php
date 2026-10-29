#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Issue #138 — standalone panel pages must redirect flagged operators, not HTTP 500.
 * Spawns panel_standalone_password_gate_child.php per page (logs, monitor, relationship).
 */

$failures = 0;
$root = dirname(__DIR__);
$child = $root . '/tests/panel_standalone_password_gate_child.php';
$php = PHP_BINARY;

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

function runStandaloneGateChild(string $php, string $child, string $page, string $mode): array
{
    $cmd = [$php, $child, $page, $mode];
    $proc = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root = dirname(__DIR__)
    );
    if (!is_resource($proc)) {
        return ['code' => 1, 'stdout' => '', 'stderr' => 'proc_open_failed'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

foreach (['logs', 'monitor', 'relationship'] as $page) {
    $flagged = runStandaloneGateChild($php, $child, $page, 'flagged');
    assert_true(
        !str_contains($flagged['stderr'], 'setFlash_missing'),
        "{$page} flagged: setFlash available (no undefined-function crash)"
    );
    assert_true(
        !str_contains($flagged['stderr'], 'Call to undefined function'),
        "{$page} flagged: no undefined function in stderr"
    );
    assert_true(
        !str_contains($flagged['stdout'], 'OK:gate_passed'),
        "{$page} flagged: gate redirects (does not pass through)"
    );
    assert_true(
        !str_contains($flagged['stderr'], 'FAIL:expected_redirect'),
        "{$page} flagged: redirectToPanelAction exits before fall-through"
    );
    assert_true(
        $flagged['code'] === 0,
        "{$page} flagged: subprocess exit 0 after redirect"
    );

    $normal = runStandaloneGateChild($php, $child, $page, 'normal');
    assert_true(
        str_contains($normal['stdout'], 'OK:gate_passed'),
        "{$page} normal: gate allows access"
    );
    assert_true(
        !str_contains($normal['stderr'], 'Call to undefined function'),
        "{$page} normal: no fatal in stderr"
    );
    assert_true(
        $normal['code'] === 0,
        "{$page} normal: subprocess exit 0"
    );
}

echo "\nSummary: failures={$failures}\n";
exit($failures > 0 ? 1 : 0);
