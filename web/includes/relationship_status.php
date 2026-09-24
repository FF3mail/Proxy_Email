<?php
declare(strict_types=1);

/**
 * Mail-passage journal observability (PROMPT-79.2 / ADR-001).
 */

const PANEL_PASSAGE_JOURNAL_LIMIT_DEFAULT = 100;
const PANEL_PASSAGE_JOURNAL_LIMIT_MIN = 20;
const PANEL_PASSAGE_JOURNAL_LIMIT_MAX = 500;

const DISPOSAL_REASON_LABELS = [
    'no_relationship' => 'Нет связи (relationship)',
    'relationship_inactive' => 'Связь деактивирована',
    'zero_attachments' => 'Нет вложений',
    'multiple_attachments' => 'Более одного вложения (исходящие)',
    'disallowed_extension' => 'Недопустимое расширение вложения',
    'subject_mismatch' => 'Тема не совпадает с именами архивов',
    'too_many_attachments' => 'Слишком много вложений (>20)',
    'missing_filename' => 'Вложение без имени файла',
];

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

function disposalReasonLabel(?string $code): string
{
    if ($code === null || $code === '') {
        return '—';
    }
    return DISPOSAL_REASON_LABELS[$code] ?? $code;
}

/**
 * Human-readable passage line (decisions log §5).
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
    $reason = disposalReasonLabel(isset($row['disposal_reason']) ? (string)$row['disposal_reason'] : null);

    if ($eventType === 'skipped') {
        $fname = $detail !== '' ? $detail : $local;
        return sprintf(
            'Пропущено вложение (%s): %s — %s',
            $reason,
            $fname,
            $client
        );
    }

    if ($direction === 'outbound') {
        return sprintf(
            'Письмо от «%s» для «%s» получено на %s в %s, отправлено на %s в %s',
            $referent,
            $client,
            $local,
            $received,
            $external,
            $action
        );
    }

    return sprintf(
        'Письмо от «%s» для «%s» получено на %s в %s, доставлено на %s в %s',
        $client,
        $referent,
        $external,
        $received,
        $local,
        $action
    );
}

/**
 * @return array{
 *   limit: int,
 *   passage_rows: list<array<string,mixed>>,
 *   passage_lines: list<string>,
 *   nonstandard_rows: list<array<string,mixed>>,
 *   table_missing: bool,
 *   error: ?string
 * }
 */
function buildRelationshipStatusPageData(object $pdo, int $limit = PANEL_PASSAGE_JOURNAL_LIMIT_DEFAULT): array
{
    $limit = normalizePanelPassageJournalLimit($limit);
    $result = [
        'limit' => $limit,
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

        $stmt2 = $pdo->prepare(
            "SELECT id, event_ts, event_type, direction, referent_name, client_name,
                    local_mailbox, external_mailbox, received_at, action_at,
                    disposal_reason, notified, source_message_id, detail
             FROM mail_passage_journal
             WHERE event_type IN ('disposed', 'skipped')
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
            $row['notified_label'] = !empty($row['notified'])
                ? 'референт уведомлён'
                : 'референт не уведомлён';
        }
        unset($row);
        $result['nonstandard_rows'] = $disposed;
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}
