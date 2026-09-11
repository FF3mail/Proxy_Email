<?php
declare(strict_types=1);

/**
 * Relationship routing observability helpers (PROMPT-69).
 *
 * Read-only: daemon log tail (allowlisted via log_viewer.php) + optional
 * relationship_shadow_stats.json (scoped runtime file, same dir as PID).
 */

require_once __DIR__ . '/log_viewer.php';
require_once __DIR__ . '/relationship_editor.php';

/** Same path as relationship_shadow.SHADOW_STATS_FILE — deliberate scoped read (PROMPT-69). */
const PANEL_SHADOW_STATS_FILE = '/run/mail-proxy/relationship_shadow_stats.json';

const PANEL_RELATIONSHIP_STATUS_LOG_LINES_DEFAULT = 2000;
const PANEL_RELATIONSHIP_STATUS_LOG_LINES_MIN = 500;
const PANEL_RELATIONSHIP_STATUS_LOG_LINES_MAX = 5000;

function normalizePanelRelationshipStatusLogLines(int $lines): int
{
    if ($lines < PANEL_RELATIONSHIP_STATUS_LOG_LINES_MIN) {
        return PANEL_RELATIONSHIP_STATUS_LOG_LINES_MIN;
    }
    if ($lines > PANEL_RELATIONSHIP_STATUS_LOG_LINES_MAX) {
        return PANEL_RELATIONSHIP_STATUS_LOG_LINES_MAX;
    }
    return $lines;
}

/**
 * Tail the allowlisted daemon log for observability parsing (chronological order).
 *
 * @return array{path: string, lines: string[], readable: bool, error: string}
 */
function readRelationshipStatusLogTail(int $lines): array
{
    $path = resolvePanelLogPath('daemon');
    if ($path === null) {
        return ['path' => '', 'lines' => [], 'readable' => false, 'error' => 'invalid_source'];
    }

    if (!is_readable($path)) {
        return [
            'path' => $path,
            'lines' => [],
            'readable' => false,
            'error' => 'unreadable',
        ];
    }

    return [
        'path' => $path,
        'lines' => tailFile($path, $lines),
        'readable' => true,
        'error' => '',
    ];
}

/**
 * @return array{readable: bool, path: string, stats: ?array<string, int>, error: string}
 */
function readRelationshipShadowStats(): array
{
    $path = PANEL_SHADOW_STATS_FILE;
    if (!is_readable($path)) {
        return [
            'readable' => false,
            'path' => $path,
            'stats' => null,
            'error' => 'unreadable',
        ];
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [
            'readable' => false,
            'path' => $path,
            'stats' => null,
            'error' => 'read_failed',
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'readable' => true,
            'path' => $path,
            'stats' => null,
            'error' => 'invalid_json',
        ];
    }

    return [
        'readable' => true,
        'path' => $path,
        'stats' => $decoded,
        'error' => '',
    ];
}

function parseDaemonLogTimestamp(string $line): ?string
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
        return $m[1];
    }
    return null;
}

function relationshipMaildirNewPath(string $maildir): string
{
    $maildir = rtrim(trim($maildir), '/');
    if ($maildir === '') {
        return '';
    }
    return $maildir . '/new';
}

/**
 * DB path comparison for PROMPT-67 equal-path collision (no filesystem access).
 *
 * @return array{relationship_path: string, referent_path: string, source: string}|null
 */
function detectDbPathCollision(array $referent, array $relationship): ?array
{
    $relPath = relationshipMaildirNewPath((string)($relationship['local_client_maildir'] ?? ''));
    $refPath = relationshipMaildirNewPath((string)($referent['local_outbox'] ?? ''));
    if ($relPath === '' || $refPath === '') {
        return null;
    }
    if ($relPath !== $refPath) {
        return null;
    }
    return [
        'relationship_path' => $relPath,
        'referent_path' => $refPath,
        'source' => 'db',
    ];
}

/**
 * Parse daemon log lines for relationship routing observability.
 *
 * Per-relationship shadow markers are authoritative from log lines (stats JSON is
 * process-wide only). Lines are processed oldest→newest; last match wins.
 *
 * @param list<string> $lines
 * @return array{
 *   startup_modes_line: ?string,
 *   inbound_shadow: array<int, array{marker: string, ts: ?string, line: string}>,
 *   outbound_shadow: array<int, array{marker: string, ts: ?string, line: string}>,
 *   collisions: array<int, list<array{referent_id?: int, ts: ?string, line: string, source: string}>>,
 *   dual_divergence: array<int, list<array{ts: ?string, line: string}>>,
 *   dual_divergence_unmapped: list<array{ts: ?string, line: string}>,
 *   watch_paths: array<int, string>,
 *   last_file: array<int, array{ts: ?string, file: ?string, line: string}>,
 *   log_lines_parsed: int
 * }
 */
function parseRelationshipObservabilityFromLog(array $lines): array
{
    $result = [
        'startup_modes_line' => null,
        'inbound_shadow' => [],
        'outbound_shadow' => [],
        'collisions' => [],
        'dual_divergence' => [],
        'dual_divergence_unmapped' => [],
        'watch_paths' => [],
        'last_file' => [],
        'log_lines_parsed' => count($lines),
    ];

    /** @var array<string, int> */
    $pathToRelId = [];

    foreach ($lines as $line) {
        $ts = parseDaemonLogTimestamp($line);

        if (
            str_contains($line, 'INBOUND_ROUTING_MODE=')
            && str_contains($line, 'OUTBOUND_ROUTING_MODE=')
            && str_contains($line, 'OUTBOUND_WATCH_MODE=')
        ) {
            $result['startup_modes_line'] = trim($line);
        }

        if (preg_match('/Watchdog configured for relationship (\d+): (.+)/', $line, $wm)) {
            $relId = (int)$wm[1];
            $path = rtrim(trim($wm[2]), '/');
            $result['watch_paths'][$relId] = $path;
            $pathToRelId[$path] = $relId;
        }

        if (str_contains($line, '[RELATIONSHIP_SHADOW]')) {
            $relId = null;
            $marker = null;
            if (preg_match('/lookup=matched relationship_id=(\d+)/', $line, $rm)) {
                $relId = (int)$rm[1];
            }
            if (preg_match('/marker=(.+)$/', $line, $mm)) {
                $marker = trim($mm[1]);
            }
            if ($relId !== null && $marker !== null) {
                $result['inbound_shadow'][$relId] = [
                    'marker' => $marker,
                    'ts' => $ts,
                    'line' => $line,
                ];
            }
        }

        if (
            str_contains($line, '[OUTBOUND_RELATIONSHIP_SHADOW]')
            && preg_match('/relationship_id=(\d+) marker=(.+)$/', $line, $om)
        ) {
            $relId = (int)$om[1];
            $result['outbound_shadow'][$relId] = [
                'marker' => trim($om[2]),
                'ts' => $ts,
                'line' => $line,
            ];
        }

        if (
            preg_match(
                '/OUTBOUND_WATCH: relationship (\d+) local_client_maildir\/new equals referent (\d+)/',
                $line,
                $cm
            )
        ) {
            $relId = (int)$cm[1];
            $result['collisions'][$relId][] = [
                'referent_id' => (int)$cm[2],
                'ts' => $ts,
                'line' => $line,
                'source' => 'log',
            ];
        }

        if (str_contains($line, '[OUTBOUND_WATCH_DUAL]')) {
            $relId = null;
            if (preg_match('/relationship_watch path=([^\s]+)/', $line, $pm)) {
                $norm = rtrim($pm[1], '/');
                $relId = $pathToRelId[$norm] ?? null;
            }
            $entry = ['ts' => $ts, 'line' => $line];
            if ($relId !== null) {
                $result['dual_divergence'][$relId][] = $entry;
            } else {
                $result['dual_divergence_unmapped'][] = $entry;
            }
        }

        if (
            preg_match(
                '/Watchdog: new email file for relationship (\d+) \(referent \d+\): (.+)$/',
                $line,
                $fm
            )
        ) {
            $relId = (int)$fm[1];
            $result['last_file'][$relId] = [
                'ts' => $ts,
                'file' => trim($fm[2]),
                'line' => $line,
            ];
        }
    }

    return $result;
}

/**
 * Map relationshipStatusLabel() code to operator-facing readiness bucket.
 */
function observabilityReadinessBucket(string $statusCode): string
{
    return match ($statusCode) {
        'complete' => 'valid',
        'legacy' => 'legacy-only-pending-backfill',
        'inactive' => 'inactive',
        default => $statusCode,
    };
}

/**
 * @return array{
 *   log: array{path: string, lines: string[], readable: bool, error: string},
 *   observability: array<string, mixed>,
 *   shadow_stats: array{readable: bool, path: string, stats: ?array<string, int>, error: string},
 *   referents: list<array{referent: array<string, mixed>, relationships: list<array<string, mixed>>}>,
 *   log_tail_lines: int
 * }
 */
function buildRelationshipStatusPageData(PDO $pdo, int $logTailLines): array
{
    $logTailLines = normalizePanelRelationshipStatusLogLines($logTailLines);
    $log = readRelationshipStatusLogTail($logTailLines);
    $observability = parseRelationshipObservabilityFromLog($log['lines']);
    $shadowStats = readRelationshipShadowStats();

    $stmt = $pdo->query(
        'SELECT id, username, local_inbox, local_outbox, active
         FROM referents
         ORDER BY username, id'
    );
    $referentRows = $stmt->fetchAll() ?: [];

    $pageReferents = [];
    foreach ($referentRows as $referent) {
        $relationshipRows = fetchRelationshipsForReferent($pdo, (int)$referent['id']);
        $rels = [];
        foreach ($relationshipRows as $row) {
            $relId = (int)$row['id'];
            $status = relationshipStatusLabel($row);

            $collisions = $observability['collisions'][$relId] ?? [];
            $dbCollision = detectDbPathCollision($referent, $row);
            if ($dbCollision !== null) {
                $collisions[] = array_merge($dbCollision, [
                    'ts' => null,
                    'line' => '',
                ]);
            }

            $watchPath = $observability['watch_paths'][$relId] ?? null;
            if ($watchPath === null) {
                $maildir = trim((string)($row['local_client_maildir'] ?? ''));
                if ($maildir !== '') {
                    $watchPath = relationshipMaildirNewPath($maildir);
                }
            }

            $rels[] = [
                'row' => $row,
                'status' => $status,
                'readiness_bucket' => observabilityReadinessBucket($status['code']),
                'inbound_shadow' => $observability['inbound_shadow'][$relId] ?? null,
                'outbound_shadow' => $observability['outbound_shadow'][$relId] ?? null,
                'collisions' => $collisions,
                'dual_divergence' => $observability['dual_divergence'][$relId] ?? [],
                'watch_path' => $watchPath,
                'last_file' => $observability['last_file'][$relId] ?? null,
            ];
        }

        $pageReferents[] = [
            'referent' => $referent,
            'relationships' => $rels,
        ];
    }

    return [
        'log' => $log,
        'observability' => $observability,
        'shadow_stats' => $shadowStats,
        'referents' => $pageReferents,
        'log_tail_lines' => $logTailLines,
    ];
}

function observabilityMarkerCssClass(string $marker): string
{
    if ($marker === 'AGREE') {
        return 'obs-marker-agree';
    }
    if (str_starts_with($marker, 'DIVERGE')) {
        return 'obs-marker-diverge';
    }
    if ($marker === 'ERROR') {
        return 'obs-marker-error';
    }
    return 'obs-marker-other';
}
