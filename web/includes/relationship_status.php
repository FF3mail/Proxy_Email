<?php
declare(strict_types=1);

/**
 * Mail-passage journal observability (PROMPT-79.2 / ADR-001 / PROMPT-79.2l).
 */

const PANEL_PASSAGE_JOURNAL_LIMIT_DEFAULT = 100;
const PANEL_PASSAGE_JOURNAL_LIMIT_MIN = 20;
const PANEL_PASSAGE_JOURNAL_LIMIT_MAX = 500;

/** Known disposal_reason codes written by daemon / attachment_policy. */
const PANEL_DISPOSAL_REASON_CODES = [
    'no_relationship',
    'relationship_inactive',
    'zero_attachments',
    'multiple_attachments',
    'disallowed_extension',
    'subject_mismatch',
    'too_many_attachments',
    'missing_filename',
    'nested_message',
];

/** Nonstandard event filter values (GET event_filter). */
const PANEL_EVENT_FILTER_ALL = 'all';
const PANEL_EVENT_FILTER_SKIPPED = 'skipped';
const PANEL_EVENT_FILTER_DISPOSED = 'disposed';

function normalizePanelPassageJournalLimit(int $limit): int
{
    if ($limit < PANEL_PASSAGE_JOURNAL_LIMIT_MIN) {
        return PANEL_PASSAGE_JOURNAL_LIMIT_MIN;
    }
    if ($limit > PANEL_PASSAGE_JOURNAL_LIMIT_MAX) {
        return PANEL_PASSAGE_JOURNAL_LIMIT_MAX;
    }
    return $limit;
}

function normalizePanelEventFilter(?string $filter): string
{
    $f = strtolower(trim((string)$filter));
    if ($f === PANEL_EVENT_FILTER_SKIPPED || $f === PANEL_EVENT_FILTER_DISPOSED) {
        return $f;
    }
    return PANEL_EVENT_FILTER_ALL;
}

function disposalReasonLabel(?string $code): string
{
    if ($code === null || $code === '') {
        return '—';
    }
    $key = 'observability.reason.' . $code;
    $label = __($key);
    // Missing translation returns the key itself — fall back to raw code.
    if ($label === $key) {
        return $code;
    }
    return $label;
}

function passageEventTypeLabel(string $eventType): string
{
    $key = 'observability.event.' . $eventType;
    $label = __($key);
    return $label === $key ? $eventType : $label;
}

function passageDirectionLabel(string $direction): string
{
    $key = 'observability.direction.' . $direction;
    $label = __($key);
    return $label === $key ? $direction : $label;
}

/**
 * Human-readable passage line (decisions log §5) — follows current panel lang.
 *
 * @param array<string,mixed> $row
 */
function formatPassageJournalLine(array $row): string
{
    $direction = (string)($row['direction'] ?? '');
    $eventType = (string)($row['event_type'] ?? '');
    $referent = (string)($row['referent_name'] ?? '—');
    $client = (string)($row['client_name'] ?? '—');
    $local = (string)($row['local_mailbox'] ?? '—');
    $external = (string)($row['external_mailbox'] ?? '—');
    $received = (string)($row['received_at'] ?? '—');
    $action = (string)($row['action_at'] ?? '—');
    $detail = (string)($row['detail'] ?? '');
    $reason = disposalReasonLabel(
        isset($row['disposal_reason']) ? (string)$row['disposal_reason'] : null
    );

    if ($eventType === 'skipped') {
        $fname = $detail !== '' ? $detail : $local;
        return __(
            'observability.passage_skipped',
            ['reason' => $reason, 'detail' => $fname, 'client' => $client]
        );
    }

    $params = [
        'referent' => $referent,
        'client' => $client,
        'local' => $local,
        'external' => $external,
        'received' => $received,
        'action' => $action,
    ];
    if ($direction === 'outbound') {
        return __('observability.passage_outbound', $params);
    }
    return __('observability.passage_inbound', $params);
}

/**
 * @return array{
 *   limit: int,
 *   event_filter: string,
 *   passage_rows: list<array<string,mixed>>,
 *   passage_lines: list<string>,
 *   nonstandard_rows: list<array<string,mixed>>,
 *   table_missing: bool,
 *   error: ?string
 * }
 */
function buildRelationshipStatusPageData(
    object $pdo,
    int $limit = PANEL_PASSAGE_JOURNAL_LIMIT_DEFAULT,
    string $eventFilter = PANEL_EVENT_FILTER_ALL
): array {
    $limit = normalizePanelPassageJournalLimit($limit);
    $eventFilter = normalizePanelEventFilter($eventFilter);
    $result = [
        'limit' => $limit,
        'event_filter' => $eventFilter,
        'passage_rows' => [],
        'passage_lines' => [],
        'nonstandard_rows' => [],
        'table_missing' => false,
        'error' => null,
    ];

    try {
        $check = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_passage_journal'"
        );
        $exists = (int)$check->fetchColumn() > 0;
        if (!$exists) {
            $result['table_missing'] = true;
            $result['error'] = 'Таблица mail_passage_journal отсутствует — примените migrations/004_mail_passage_journal.sql';
            return $result;
        }

        $stmt = $pdo->prepare(
            "SELECT id, event_ts, event_type, direction, referent_name, client_name,
                    local_mailbox, external_mailbox, received_at, action_at,
                    disposal_reason, notified, source_message_id, detail
             FROM mail_passage_journal
             WHERE event_type = 'delivered'
             ORDER BY event_ts DESC, id DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $passage = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result['passage_rows'] = $passage;
        foreach ($passage as $row) {
            $result['passage_lines'][] = formatPassageJournalLine($row);
        }

        if ($eventFilter === PANEL_EVENT_FILTER_SKIPPED) {
            $eventClause = "event_type = 'skipped'";
        } elseif ($eventFilter === PANEL_EVENT_FILTER_DISPOSED) {
            $eventClause = "event_type = 'disposed'";
        } else {
            $eventClause = "event_type IN ('disposed', 'skipped')";
        }

        $stmt2 = $pdo->prepare(
            "SELECT id, event_ts, event_type, direction, referent_name, client_name,
                    local_mailbox, external_mailbox, received_at, action_at,
                    disposal_reason, notified, source_message_id, detail
             FROM mail_passage_journal
             WHERE {$eventClause}
             ORDER BY event_ts DESC, id DESC
             LIMIT ?"
        );
        $stmt2->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt2->execute();
        $disposed = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($disposed as &$row) {
            $row['reason_label'] = disposalReasonLabel(
                isset($row['disposal_reason']) ? (string)$row['disposal_reason'] : null
            );
            $row['event_label'] = passageEventTypeLabel((string)($row['event_type'] ?? ''));
            $row['direction_label'] = passageDirectionLabel((string)($row['direction'] ?? ''));
            $row['notified_label'] = !empty($row['notified'])
                ? __('observability.notified_yes')
                : __('observability.notified_no');
        }
        unset($row);
        $result['nonstandard_rows'] = $disposed;
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}
