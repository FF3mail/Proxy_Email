<?php
declare(strict_types=1);

/**
 * PROMPT-79.2 / 79.2l — panel mail-passage journal views (fixture PDO + i18n).
 */

$root = dirname(__DIR__);
require_once $root . '/web/includes/i18n.php';
require_once $root . '/web/includes/relationship_status.php';

$failures = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        $failures++;
    } else {
        fwrite(STDOUT, "OK: $msg\n");
    }
}

$delivered = [[
    'id' => 1,
    'event_ts' => '2026-09-20 10:00:00',
    'event_type' => 'delivered',
    'direction' => 'outbound',
    'referent_name' => 'Иван',
    'client_name' => 'Клиент А',
    'local_mailbox' => 'clientloc@test.loc',
    'external_mailbox' => 'client@ext.com',
    'received_at' => '2026-09-20 09:59:00',
    'action_at' => '2026-09-20 10:00:00',
    'disposal_reason' => null,
    'notified' => 0,
    'source_message_id' => '<a@b>',
]];
$disposed = [[
    'id' => 2,
    'event_ts' => '2026-09-21 11:00:00',
    'event_type' => 'disposed',
    'direction' => 'inbound',
    'referent_name' => 'Иван',
    'client_name' => 'Клиент Б',
    'local_mailbox' => 'ref@test.loc',
    'external_mailbox' => 'unknown@ext.com',
    'received_at' => '2026-09-21 10:59:00',
    'action_at' => '2026-09-21 11:00:00',
    'disposal_reason' => 'no_relationship',
    'notified' => 0,
    'source_message_id' => null,
], [
    'id' => 3,
    'event_ts' => '2026-09-21 12:00:00',
    'event_type' => 'disposed',
    'direction' => 'outbound',
    'referent_name' => 'Иван',
    'client_name' => 'Клиент В',
    'local_mailbox' => 'clientloc@test.loc',
    'external_mailbox' => 'clientv@ext.com',
    'received_at' => '2026-09-21 11:59:00',
    'action_at' => '2026-09-21 12:00:00',
    'disposal_reason' => 'subject_mismatch',
    'notified' => 1,
    'source_message_id' => null,
], [
    'id' => 4,
    'event_ts' => '2026-09-21 13:00:00',
    'event_type' => 'skipped',
    'direction' => 'inbound',
    'referent_name' => 'Иван',
    'client_name' => 'Клиент Г',
    'local_mailbox' => 'ref@test.loc',
    'external_mailbox' => 'c@ext.com',
    'received_at' => '2026-09-21 12:59:00',
    'action_at' => '2026-09-21 13:00:00',
    'disposal_reason' => 'nested_message',
    'notified' => 0,
    'source_message_id' => null,
    'detail' => 'nested message/rfc822 filename=fwd.eml',
]];

$pdo = new class ($delivered, $disposed) {
    private array $delivered;
    /** @var list<array<string,mixed>> */
    private array $nonstandard;
    public string $lastNonstandardSql = '';

    public function __construct(array $delivered, array $nonstandard)
    {
        $this->delivered = $delivered;
        $this->nonstandard = $nonstandard;
    }

    public function query(string $query)
    {
        return new class {
            public function fetchColumn(): int
            {
                return 1;
            }
        };
    }

    public function prepare(string $query)
    {
        if (stripos($query, "event_type = 'delivered'") !== false) {
            $rows = $this->delivered;
        } else {
            $this->lastNonstandardSql = $query;
            $rows = $this->nonstandard;
            if (stripos($query, "event_type = 'skipped'") !== false
                && stripos($query, 'IN') === false
            ) {
                $rows = array_values(array_filter(
                    $this->nonstandard,
                    static fn(array $r): bool => ($r['event_type'] ?? '') === 'skipped'
                ));
            } elseif (stripos($query, "event_type = 'disposed'") !== false
                && stripos($query, 'IN') === false
            ) {
                $rows = array_values(array_filter(
                    $this->nonstandard,
                    static fn(array $r): bool => ($r['event_type'] ?? '') === 'disposed'
                ));
            }
        }
        return new class ($rows) {
            private array $rows;
            private int $limit = 100;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function bindValue($param, $value, $type = null): bool
            {
                $this->limit = (int)$value;
                return true;
            }

            public function execute($params = null): bool
            {
                return true;
            }

            public function fetchAll($mode = null): array
            {
                return array_slice($this->rows, 0, $this->limit);
            }
        };
    }
};

setPanelLang('ru');
$page = buildRelationshipStatusPageData($pdo, 50);

assert_true(($page['error'] ?? null) === null, 'no error from fixture journal');
assert_true(count($page['passage_rows']) === 1, 'one delivered row');
assert_true(count($page['passage_lines']) === 1, 'one passage line');
assert_true(
    str_contains($page['passage_lines'][0], 'Иван')
    && str_contains($page['passage_lines'][0], 'Клиент А'),
    'passage line mentions referent and client'
);
assert_true(count($page['nonstandard_rows']) === 3, 'three nonstandard rows (default filter)');
assert_true(
    ($page['nonstandard_rows'][0]['reason_label'] ?? '') === 'Нет связи (relationship)',
    'no_relationship label (ru)'
);
assert_true(
    disposalReasonLabel('nested_message') === 'Вложенное письмо (message/rfc822) не принимается',
    'nested_message label (ru)'
);
assert_true(
    ($page['nonstandard_rows'][0]['notified_label'] ?? '') === 'референт не уведомлён',
    'inbound disposed not notified (ru)'
);
assert_true(
    ($page['nonstandard_rows'][1]['notified_label'] ?? '') === 'референт уведомлён',
    'outbound disposed notified (ru)'
);

$line = formatPassageJournalLine([
    'direction' => 'inbound',
    'event_type' => 'delivered',
    'referent_name' => 'Ref',
    'client_name' => 'Client',
    'local_mailbox' => 'local@x',
    'external_mailbox' => 'ext@y',
    'received_at' => '2026-01-01 00:00:00',
    'action_at' => '2026-01-01 00:01:00',
]);
assert_true(str_contains($line, 'доставлено на local@x'), 'inbound phrasing (ru)');

// --- I1: every known reason/event has distinct ru vs en labels ---
$reasonCodes = PANEL_DISPOSAL_REASON_CODES;
$eventCodes = ['delivered', 'disposed', 'skipped'];
setPanelLang('ru');
$ruReasons = [];
$ruEvents = [];
foreach ($reasonCodes as $code) {
    $ruReasons[$code] = disposalReasonLabel($code);
    assert_true($ruReasons[$code] !== '' && $ruReasons[$code] !== $code, "ru reason for $code");
}
foreach ($eventCodes as $code) {
    $ruEvents[$code] = passageEventTypeLabel($code);
    assert_true($ruEvents[$code] !== '' && $ruEvents[$code] !== $code, "ru event for $code");
}
setPanelLang('en');
foreach ($reasonCodes as $code) {
    $en = disposalReasonLabel($code);
    assert_true($en !== '' && $en !== $code, "en reason for $code");
    assert_true($en !== $ruReasons[$code], "ru/en reason differ for $code");
}
foreach ($eventCodes as $code) {
    $en = passageEventTypeLabel($code);
    assert_true($en !== '' && $en !== $code, "en event for $code");
    assert_true($en !== $ruEvents[$code], "ru/en event differ for $code");
}
assert_true(
    disposalReasonLabel('nested_message') === 'Nested message (message/rfc822) not accepted',
    'nested_message label (en)'
);

// --- I2: skipped filter narrows row set ---
setPanelLang('ru');
$pageSkipped = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_SKIPPED);
assert_true($pageSkipped['event_filter'] === 'skipped', 'event_filter=skipped stored');
assert_true(count($pageSkipped['nonstandard_rows']) === 1, 'skipped filter returns one row');
assert_true(
    ($pageSkipped['nonstandard_rows'][0]['event_type'] ?? '') === 'skipped',
    'skipped filter row is skipped'
);
assert_true(
    str_contains($pdo->lastNonstandardSql, "event_type = 'skipped'"),
    'skipped filter SQL targets skipped only'
);

$pageDisposed = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_DISPOSED);
assert_true(count($pageDisposed['nonstandard_rows']) === 2, 'disposed filter returns two rows');
foreach ($pageDisposed['nonstandard_rows'] as $row) {
    assert_true(($row['event_type'] ?? '') === 'disposed', 'disposed filter row is disposed');
}

$pageSrc = file_get_contents($root . '/web/relationship-status.php') ?: '';
assert_true(!str_contains($pageSrc, 'observability.stub_'), 'stub keys removed from page');
assert_true(str_contains($pageSrc, 'passage_title'), 'passage view present');
assert_true(str_contains($pageSrc, 'nonstandard_title'), 'nonstandard view present');
assert_true(str_contains($pageSrc, 'event_filter'), 'skipped filter control present');
assert_true(str_contains($pageSrc, 'observability.filter_skipped'), 'skipped filter label key present');
assert_true(str_contains($pageSrc, 'event_label'), 'translated event label rendered');

if ($failures > 0) {
    fwrite(STDERR, "panel_mail_passage_journal_test: $failures failure(s)\n");
    exit(1);
}
fwrite(STDOUT, "panel_mail_passage_journal_test: OK\n");
exit(0);
