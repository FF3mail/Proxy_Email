<?php
declare(strict_types=1);

/**
 * Mail-passage journal observability
 * (PROMPT-79.2 / ADR-001 / 79.2l / 79.2m / 79.2n).
 */

const PANEL_PASSAGE_JOURNAL_LIMIT_DEFAULT = 100;
const PANEL_PASSAGE_JOURNAL_LIMIT_MIN = 20;
const PANEL_PASSAGE_JOURNAL_LIMIT_MAX = 500;

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

const PANEL_TAB_PASSAGE = 'passage';
const PANEL_TAB_NONSTANDARD = 'nonstandard';

/** Nonstandard event column filter (replaces event_filter). */
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

function normalizePanelTab(?string $tab): string
{
    $t = strtolower(trim((string)$tab));
    if ($t === PANEL_TAB_NONSTANDARD) {
        return PANEL_TAB_NONSTANDARD;
    }
    return PANEL_TAB_PASSAGE;
}

function normalizePanelEventFilter(?string $filter): string
{
    $f = strtolower(trim((string)$filter));
    if ($f === PANEL_EVENT_FILTER_SKIPPED || $f === PANEL_EVENT_FILTER_DISPOSED) {
        return $f;
    }
    return PANEL_EVENT_FILTER_ALL;
}

function normalizePassageReferentFilter(?string $referent): string
{
    return trim((string)$referent);
}

function normalizePassageClientFilter(?string $client): string
{
    return trim((string)$client);
}

/**
 * Accept only well-formed Y-m-d; otherwise return empty (filter ignored).
 */
function normalizePanelDateFilter(?string $raw): string
{
    $s = trim((string)$raw);
    if ($s === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $s);
    if ($dt === false || $dt->format('Y-m-d') !== $s) {
        return '';
    }
    return $s;
}

/**
 * Direction filter: empty = all; inbound|outbound only.
 */
function normalizePanelDirectionFilter(?string $raw): string
{
    $d = strtolower(trim((string)$raw));
    if ($d === 'inbound' || $d === 'outbound') {
        return $d;
    }
    return '';
}

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
 * @param list<string> $where
 * @param list<array{0:mixed,1:int}> $binds
 */
function appendPartyFilters(
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
        $where[] = 'client_name LIKE ? ESCAPE \'\\\\\'';
        $binds[] = ['%' . escapeSqlLikeWildcards($client) . '%', PDO::PARAM_STR];
    }
}

/**
 * Inclusive date range on event_ts. date_to includes the whole calendar day.
 *
 * @param list<string> $where
 * @param list<array{0:mixed,1:int}> $binds
 */
function appendEventTsDateRange(
    array &$where,
    array &$binds,
    string $dateFrom,
    string $dateTo
): void {
    if ($dateFrom !== '') {
        $where[] = 'event_ts >= ?';
        $binds[] = [$dateFrom . ' 00:00:00', PDO::PARAM_STR];
    }
    if ($dateTo !== '') {
        $where[] = 'event_ts < DATE_ADD(?, INTERVAL 1 DAY)';
        $binds[] = [$dateTo, PDO::PARAM_STR];
    }
}

/**
 * @param list<string> $where
 * @param list<array{0:mixed,1:int}> $binds
 */
function appendDirectionFilter(
    array &$where,
    array &$binds,
    string $direction
): void {
    if ($direction !== '') {
        $where[] = 'direction = ?';
        $binds[] = [$direction, PDO::PARAM_STR];
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
 * @param array{
 *   limit?: int,
 *   tab?: string,
 *   passage_referent?: string,
 *   passage_client?: string,
 *   passage_date_from?: string,
 *   passage_date_to?: string,
 *   passage_direction?: string,
 *   ns_referent?: string,
 *   ns_client?: string,
 *   ns_date_from?: string,
 *   ns_date_to?: string,
 *   ns_event?: string,
 *   ns_direction?: string
 * } $opts
 * @return array<string,mixed>
 */
function buildRelationshipStatusPageData(object $pdo, array $opts = []): array
{
    $limit = normalizePanelPassageJournalLimit((int)($opts['limit'] ?? PANEL_PASSAGE_JOURNAL_LIMIT_DEFAULT));
    $tab = normalizePanelTab($opts['tab'] ?? PANEL_TAB_PASSAGE);

    $passageReferent = normalizePassageReferentFilter($opts['passage_referent'] ?? '');
    $passageClient = normalizePassageClientFilter($opts['passage_client'] ?? '');
    $passageDateFrom = normalizePanelDateFilter($opts['passage_date_from'] ?? '');
    $passageDateTo = normalizePanelDateFilter($opts['passage_date_to'] ?? '');
    $passageDirection = normalizePanelDirectionFilter($opts['passage_direction'] ?? '');

    $nsReferent = normalizePassageReferentFilter($opts['ns_referent'] ?? '');
    $nsClient = normalizePassageClientFilter($opts['ns_client'] ?? '');
    $nsDateFrom = normalizePanelDateFilter($opts['ns_date_from'] ?? '');
    $nsDateTo = normalizePanelDateFilter($opts['ns_date_to'] ?? '');
    $nsEvent = normalizePanelEventFilter($opts['ns_event'] ?? PANEL_EVENT_FILTER_ALL);
    $nsDirection = normalizePanelDirectionFilter($opts['ns_direction'] ?? '');

    $result = [
        'limit' => $limit,
        'tab' => $tab,
        'passage_referent' => $passageReferent,
        'passage_client' => $passageClient,
        'passage_date_from' => $passageDateFrom,
        'passage_date_to' => $passageDateTo,
        'passage_direction' => $passageDirection,
        'ns_referent' => $nsReferent,
        'ns_client' => $nsClient,
        'ns_date_from' => $nsDateFrom,
        'ns_date_to' => $nsDateTo,
        'ns_event' => $nsEvent,
        'ns_direction' => $nsDirection,
        // Back-compat alias used by older assertions / templates if any.
        'event_filter' => $nsEvent,
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

        if ($tab === PANEL_TAB_PASSAGE) {
            $where = ["event_type = 'delivered'"];
            $binds = [];
            appendPartyFilters($where, $binds, $passageReferent, $passageClient);
            appendEventTsDateRange($where, $binds, $passageDateFrom, $passageDateTo);
            appendDirectionFilter($where, $binds, $passageDirection);
            $binds[] = [$limit, PDO::PARAM_INT];
            $sql =
                "SELECT id, event_ts, event_type, direction, referent_name, client_name,
                        local_mailbox, external_mailbox, received_at, action_at,
                        disposal_reason, notified, source_message_id, detail
                 FROM mail_passage_journal
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY event_ts DESC, id DESC
                 LIMIT ?";
            $stmt = $pdo->prepare($sql);
            bindAll($stmt, $binds);
            $stmt->execute();
            $passage = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $result['passage_rows'] = $passage;
            foreach ($passage as $row) {
                $result['passage_lines'][] = formatPassageJournalLine($row);
            }
            return $result;
        }

        // Nonstandard tab only.
        if ($nsEvent === PANEL_EVENT_FILTER_SKIPPED) {
            $eventClause = "event_type = 'skipped'";
        } elseif ($nsEvent === PANEL_EVENT_FILTER_DISPOSED) {
            $eventClause = "event_type = 'disposed'";
        } else {
            $eventClause = "event_type IN ('disposed', 'skipped')";
        }
        $where = [$eventClause];
        $binds = [];
        appendPartyFilters($where, $binds, $nsReferent, $nsClient);
        appendEventTsDateRange($where, $binds, $nsDateFrom, $nsDateTo);
        appendDirectionFilter($where, $binds, $nsDirection);
        $binds[] = [$limit, PDO::PARAM_INT];
        $sql =
            "SELECT id, event_ts, event_type, direction, referent_name, client_name,
                    local_mailbox, external_mailbox, received_at, action_at,
                    disposal_reason, notified, source_message_id, detail
             FROM mail_passage_journal
             WHERE " . implode(' AND ', $where) . "
             ORDER BY event_ts DESC, id DESC
             LIMIT ?";
        $stmt = $pdo->prepare($sql);
        bindAll($stmt, $binds);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
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
        $result['nonstandard_rows'] = $rows;
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}
