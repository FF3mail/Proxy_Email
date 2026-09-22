<?php
declare(strict_types=1);

/**
 * Relationship routing observability (PROMPT-69).
 *
 * PROMPT-79.1: legacy shadow parsing removed; full live observability in PROMPT-79.2.
 */

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
 * @return array{stub: true, log_tail_lines: int}
 */
function buildRelationshipStatusPageData(PDO $pdo, int $logTailLines): array
{
    return [
        'stub' => true,
        'log_tail_lines' => normalizePanelRelationshipStatusLogLines($logTailLines),
    ];
}
