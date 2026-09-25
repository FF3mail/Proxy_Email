<?php
declare(strict_types=1);

/**
 * Mail-passage journal observability (PROMPT-79.2 / ADR-001 / PROMPT-79.2l / 79.2m).
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

/**
 * Trim passage_referent; empty / sentinel means no filter.
 */
function normalizePassageReferentFilter(?string $referent): string
{
    return trim((string)$referent);
}

/**
 * Trim passage_client; empty after trim means no filter.
 */
function normalizePassageClientFilter(?string $client): string
{
    return trim((string)$client);
}

/**
 * Escape LIKE wildcards so user-typed % and _ are literals.
 * Caller wraps with %…% for substring match.
 */
function escapeSqlLikeWildcards(string $value): string
{
    return str_replace(
        ['\\', '%', '_'],
        ['\\\\', '\\%', '\\_'],
        $value
    );
}

function disposalReasonLabel(?string $code): string
{
    if ($code === null || $code === '') {
        return '—';
    }
    $key = 'observability.reason.' . $code;
    $label = __($key);
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
 * Append party filters to a WHERE builder (bound params).
 *
 * @param list<string> $where
 * @param list<array{0:mixed,1:int}> $binds  [value, PDO::PARAM_*]
 */
function appendPassagePartyFilters(
    array &$where,
    array &$binds,
    string $referent,
    string $client
): void {
    if ($referent !== '') {
        $where[] = 'referent_name = ?';
        $binds[] = [$referent, PDO::PARAM_STR];
    }
    if ($client !== '') {
        // ESCAPE '\\' so \% and \_ are literals (MySQL/MariaDB default ESCAPE is \).
        $where[] = 'client_name LIKE ? ESCAPE \'\\\\\'';
        $pattern = '%' . escapeSqlLikeWildcards($client) . '%';
        $binds[] = [$pattern, PDO::PARAM_STR];
    }
}

/**
 * @param list<array{0:mixed,1:int}> $binds
 */
function bindAll(object $stmt, array $binds): void
{
    $i = 1;
    foreach ($binds as [$value, $type]) {
        $stmt->bindValue($i, $value, $type);
        $i++;
    }
}

/**
 * @return array{
 *   limit: int,
 *   event_filter: string,
 *   passage_referent: string,
 *   passage_client: string,
 *   referent_options: list<string>,
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
    string $eventFilter = PANEL_EVENT_FILTER_ALL,
    string $passageReferent = '',
    string $passageClient = ''
): array {
    $limit = normalizePanelPassageJournalLimit($limit);
    $eventFilter = normalizePanelEventFilter($eventFilter);
    $passageReferent = normalizePassageReferentFilter($passageReferent);
    $passageClient = normalizePassageClientFilter($passageClient);
    $result = [
        'limit' => $limit,
        'event_filter' => $eventFilter,
        'passage_referent' => $passageReferent,
        'passage_client' => $passageClient,
        'referent_options' => [],
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

        // Distinct referent names from journal (bounded staff set; avoids joining referents).
        $refStmt = $pdo->query(
            "SELECT DISTINCT referent_name FROM mail_passage_journal
             WHERE referent_name IS NOT NULL AND referent_name <> ''
             ORDER BY referent_name ASC"
        );
        $refRows = $refStmt ? ($refStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($refRows as $rr) {
            $name = trim((string)($rr['referent_name'] ?? ''));
            if ($name !== '') {
                $result['referent_options'][] = $name;
            }
        }

        $passageWhere = ["event_type = 'delivered'"];
        $passageBinds = [];
        appendPassagePartyFilters($passageWhere, $passageBinds, $passageReferent, $passageClient);
        $passageBinds[] = [$limit, PDO::PARAM_INT];
        $passageSql =
            "SELECT id, event_ts, event_type, direction, referent_name, client_name,
                    local_mailbox, external_mailbox, received_at, action_at,
                    disposal_reason, notified, source_message_id, detail
             FROM mail_passage_journal
             WHERE " . implode(' AND ', $passageWhere) . "
             ORDER BY event_ts DESC, id DESC
             LIMIT ?";
        $stmt = $pdo->prepare($passageSql);
        bindAll($stmt, $passageBinds);
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

        $nsWhere = [$eventClause];
        $nsBinds = [];
        appendPassagePartyFilters($nsWhere, $nsBinds, $passageReferent, $passageClient);
        $nsBinds[] = [$limit, PDO::PARAM_INT];
        $nsSql =
            "SELECT id, event_ts, event_type, direction, referent_name, client_name,
                    local_mailbox, external_mailbox, received_at, action_at,
                    disposal_reason, notified, source_message_id, detail
             FROM mail_passage_journal
             WHERE " . implode(' AND ', $nsWhere) . "
             ORDER BY event_ts DESC, id DESC
             LIMIT ?";
        $stmt2 = $pdo->prepare($nsSql);
        bindAll($stmt2, $nsBinds);
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
