<?php
declare(strict_types=1);

/**
 * Per-referent routing/watch mode overrides (PROMPT-73).
 */

const REFERENT_ROUTING_MODE_VALUES = ['legacy', 'shadow', 'relationship_live'];
const REFERENT_WATCH_MODE_VALUES = ['referent_only', 'dual', 'relationship_only'];

function normalizeReferentModeOverride(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

function parseReferentInboundRoutingOverride(?string $value): ?string
{
    $value = normalizeReferentModeOverride($value);
    if ($value === null) {
        return null;
    }
    return in_array($value, REFERENT_ROUTING_MODE_VALUES, true) ? $value : null;
}

function parseReferentOutboundRoutingOverride(?string $value): ?string
{
    $value = normalizeReferentModeOverride($value);
    if ($value === null) {
        return null;
    }
    return in_array($value, REFERENT_ROUTING_MODE_VALUES, true) ? $value : null;
}

function parseReferentOutboundWatchOverride(?string $value): ?string
{
    $value = normalizeReferentModeOverride($value);
    if ($value === null) {
        return null;
    }
    return in_array($value, REFERENT_WATCH_MODE_VALUES, true) ? $value : null;
}

/**
 * Mirror relationship_routing.resolve_referent_effective_modes for panel display.
 *
 * @return array{inbound: string, outbound: string, watch: string}
 */
function computeReferentEffectiveModesFromGlobals(
    ?string $inboundOverride,
    ?string $outboundOverride,
    ?string $watchOverride,
    string $globalInbound,
    string $globalOutbound,
    string $globalWatch
): array {
    $inbound = $inboundOverride ?? $globalInbound;
    $outbound = $outboundOverride ?? $globalOutbound;
    $watch = $watchOverride ?? $globalWatch;
    if ($watch === 'relationship_only' && $outbound !== 'relationship_live') {
        $watch = 'referent_only';
    }
    return [
        'inbound' => $inbound,
        'outbound' => $outbound,
        'watch' => $watch,
    ];
}

/**
 * Parse daemon startup globals from observability log line.
 *
 * @return array{inbound: ?string, outbound: ?string, watch: ?string}
 */
function parseDaemonGlobalModesFromLogLine(?string $line): array
{
    if ($line === null || trim($line) === '') {
        return ['inbound' => null, 'outbound' => null, 'watch' => null];
    }
    $inbound = null;
    $outbound = null;
    $watch = null;
    if (preg_match('/INBOUND_ROUTING_MODE=(\S+)/', $line, $m)) {
        $inbound = $m[1];
    }
    if (preg_match('/OUTBOUND_ROUTING_MODE=(\S+)/', $line, $m)) {
        $outbound = $m[1];
    }
    if (preg_match('/OUTBOUND_WATCH_MODE=(\S+)/', $line, $m)) {
        $watch = $m[1];
    }
    return ['inbound' => $inbound, 'outbound' => $outbound, 'watch' => $watch];
}

/**
 * @return array<int, array{inbound: string, outbound: string, watch: string}>
 */
function parseReferentEffectiveModesFromLog(array $lines): array
{
    $result = [];
    foreach ($lines as $line) {
        if (!str_contains($line, '[REFERENT_EFFECTIVE_MODES]')) {
            continue;
        }
        if (
            !preg_match(
                '/referent_id=(\d+) inbound=(\S+) outbound=(\S+) watch=(\S+)/',
                $line,
                $m
            )
        ) {
            continue;
        }
        $result[(int)$m[1]] = [
            'inbound' => $m[2],
            'outbound' => $m[3],
            'watch' => $m[4],
        ];
    }
    return $result;
}

function referentModeLabel(string $modeKey, string $value): string
{
    $key = 'referent.mode.' . $modeKey . '.' . $value;
    $label = __($key);
    return $label !== $key ? $label : $value;
}
