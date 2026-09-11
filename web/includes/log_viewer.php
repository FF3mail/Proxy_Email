<?php
declare(strict_types=1);

require_once __DIR__ . '/log_tail.php';

const PANEL_LOG_DIR = '/var/log/mail-proxy';

/** @var array<string, string> */
const PANEL_ALLOWED_LOGS = [
    'daemon' => 'mail-proxy-daemon.log',
    'web' => 'web_admin.log',
];

const PANEL_LOG_LINES_MIN = 50;
const PANEL_LOG_LINES_MAX = 500;
const PANEL_LOG_LINES_DEFAULT = 200;

/**
 * Resolve allowlisted log file path. Returns null if invalid or traversal attempt.
 */
function resolvePanelLogPath(string $source): ?string
{
    if (!isset(PANEL_ALLOWED_LOGS[$source])) {
        return null;
    }

    $basename = PANEL_ALLOWED_LOGS[$source];
    $dir = realpath(PANEL_LOG_DIR);
    if ($dir === false) {
        return null;
    }

    $candidate = $dir . DIRECTORY_SEPARATOR . $basename;
    $resolved = realpath($candidate);

    // File may not exist yet; validate canonical path without requiring file
    if ($resolved !== false) {
        if (!str_starts_with($resolved, $dir . DIRECTORY_SEPARATOR) && $resolved !== $dir) {
            return null;
        }
        return $resolved;
    }

    // Construct expected path when file missing
    $expected = $dir . DIRECTORY_SEPARATOR . $basename;
    if (str_contains($basename, '..') || str_contains($basename, '/') || str_contains($basename, '\\')) {
        return null;
    }

    return $expected;
}

function normalizePanelLogLines(int $lines): int
{
    if ($lines < PANEL_LOG_LINES_MIN) {
        return PANEL_LOG_LINES_MIN;
    }
    if ($lines > PANEL_LOG_LINES_MAX) {
        return PANEL_LOG_LINES_MAX;
    }
    return $lines;
}

/**
 * Read last N lines from an allowlisted log (reuses monitor tail logic).
 *
 * @return array{path: string, lines: string[], readable: bool, error: string}
 */
function readPanelLogTail(string $source, int $lines): array
{
    $path = resolvePanelLogPath($source);
    if ($path === null) {
        return ['path' => '', 'lines' => [], 'readable' => false, 'error' => 'Недопустимый источник лога'];
    }

    if (!is_readable($path)) {
        return [
            'path' => $path,
            'lines' => [],
            'readable' => false,
            'error' => 'Файл лога недоступен для чтения',
        ];
    }

    $rawLines = tailFile($path, $lines);
    // Newest first for admin view
    return [
        'path' => $path,
        'lines' => array_reverse($rawLines),
        'readable' => true,
        'error' => '',
    ];
}
