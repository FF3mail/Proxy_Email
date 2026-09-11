#!/usr/bin/env php
<?php
/**
 * PROMPT-72: synthetic log-tail coverage for relationship-status observability.
 * Pure in-memory — no DB or log file required.
 */
declare(strict_types=1);

require_once __DIR__ . '/../web/includes/relationship_status.php';

function build_synthetic_log(int $relationshipCount, int $totalLines, float $shadowFraction): array
{
    $lines = [];
    $shadowRelIds = [];
    $tsBase = strtotime('2026-09-11 08:00:00');

    // Startup mode line (always present).
    $lines[] = '2026-09-11 08:00:00 [INFO] (MainThread) INBOUND_ROUTING_MODE=shadow '
        . 'OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=dual (requested=dual) '
        . 'RELATIONSHIP_LOOKUP_SHADOW=on';

    $shadowLinesTarget = (int)round(($totalLines - 1) * $shadowFraction);
    $noiseLines = $totalLines - 1 - $shadowLinesTarget;

    for ($i = 0; $i < $noiseLines; $i++) {
        $ts = date('Y-m-d H:i:s', $tsBase + $i);
        $lines[] = "{$ts} [INFO] (ImapWorker-1) IMAP poll noise line {$i}";
    }

    for ($i = 0; $i < $shadowLinesTarget; $i++) {
        $relId = ($i % $relationshipCount) + 1;
        $ts = date('Y-m-d H:i:s', $tsBase + $noiseLines + $i);
        if ($i % 2 === 0) {
            $lines[] = "{$ts} [INFO] (Thread-1) [RELATIONSHIP_SHADOW] account=a@x "
                . "sender=b@y legacy=delivered legacy_rcpts=c@z "
                . "lookup=matched relationship_id={$relId} marker=AGREE";
        } else {
            $lines[] = "{$ts} [INFO] (Thread-1) [OUTBOUND_RELATIONSHIP_SHADOW] "
                . "referent_id=1 from=c@z legacy_account_id=1 lookup_account_id=1 "
                . "relationship_id={$relId} marker=AGREE";
        }
        $shadowRelIds[$relId] = true;
    }

    // Chronological order (oldest first) as tailFile returns.
    return $lines;
}

function coverage_report(array $lines, int $relationshipCount): array
{
    $parsed = parseRelationshipObservabilityFromLog($lines);
    $inbound = $parsed['inbound_shadow'] ?? [];
    $outbound = $parsed['outbound_shadow'] ?? [];
    $withMarker = 0;
    for ($relId = 1; $relId <= $relationshipCount; $relId++) {
        if (isset($inbound[$relId]) || isset($outbound[$relId])) {
            $withMarker++;
        }
    }
    return [
        'log_lines' => count($lines),
        'relationships' => $relationshipCount,
        'relationships_with_marker' => $withMarker,
        'coverage_pct' => round(100 * $withMarker / max(1, $relationshipCount), 2),
    ];
}

$scenarios = [
    ['relationships' => 187, 'tail' => 2000, 'shadow_fraction' => 0.35],
    ['relationships' => 187, 'tail' => 5000, 'shadow_fraction' => 0.35],
    ['relationships' => 375, 'tail' => 2000, 'shadow_fraction' => 0.35],
    ['relationships' => 375, 'tail' => 5000, 'shadow_fraction' => 0.35],
    // Stress: high noise, sparse shadow (10% of tail is shadow markers).
    ['relationships' => 375, 'tail' => 2000, 'shadow_fraction' => 0.10],
];

$results = [];
foreach ($scenarios as $s) {
    $lines = build_synthetic_log($s['relationships'], $s['tail'], $s['shadow_fraction']);
    $results[] = array_merge($s, coverage_report($lines, $s['relationships']));
}

echo json_encode([
    'benchmark' => 'relationship_status log tail coverage',
    'method' => 'synthetic in-memory log, parseRelationshipObservabilityFromLog',
    'shadow_fraction_note' => 'fraction of tail lines that are inbound/outbound shadow',
    'scenarios' => $results,
], JSON_PRETTY_PRINT) . "\n";
