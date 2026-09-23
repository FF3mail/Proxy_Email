<?php
declare(strict_types=1);

/**
 * PROMPT-79.2 — panel mail-passage journal views (fixture PDO).
 */

$root = dirname(__DIR__);
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

final class FixturePdoStatement
{
    /** @var list<array<string,mixed>> */
    private array $rows;
    private int $bindLimit = 100;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function bindValue($param, $value, $type = null): bool
    {
        $this->bindLimit = (int)$value;
        return true;
    }

    public function execute($params = null): bool
    {
        return true;
    }

    public function fetchAll($mode = null): array
    {
        return array_slice($this->rows, 0, $this->bindLimit);
    }

    public function fetchColumn(): mixed
    {
        return 1;
    }
}

final class FixturePdo extends PDO
{
    /** @var array<string, list<array<string,mixed>>> */
    private array $bySql;

    /** @param array<string, list<array<string,mixed>>> $bySql */
    public function __construct(array $bySql)
    {
        // Skip parent ctor — we only implement query/prepare used by the helper.
        $this->bySql = $bySql;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (stripos($query, 'information_schema.TABLES') !== false) {
            return new FixturePdoStatement([['c' => 1]]);
        }
        return new FixturePdoStatement([]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (stripos($query, "event_type = 'delivered'") !== false) {
            return new FixturePdoStatement($this->bySql['delivered'] ?? []);
        }
        if (stripos($query, "event_type = 'disposed'") !== false) {
            return new FixturePdoStatement($this->bySql['disposed'] ?? []);
        }
        return new FixturePdoStatement([]);
    }
}

// FixturePdo extends PDO but cannot call parent::__construct easily on all PHP builds.
// Use a lightweight stand-in via anonymous class implementing the needed surface.
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
]];

$pdo = new class ($delivered, $disposed) {
    private array $delivered;
    private array $disposed;

    public function __construct(array $delivered, array $disposed)
    {
        $this->delivered = $delivered;
        $this->disposed = $disposed;
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
        $rows = stripos($query, "event_type = 'delivered'") !== false
            ? $this->delivered
            : $this->disposed;
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

/** @var PDO $pdo */
$page = buildRelationshipStatusPageData($pdo, 50);

assert_true(($page['error'] ?? null) === null, 'no error from fixture journal');
assert_true(count($page['passage_rows']) === 1, 'one delivered row');
assert_true(count($page['passage_lines']) === 1, 'one passage line');
assert_true(
    str_contains($page['passage_lines'][0], 'Иван')
    && str_contains($page['passage_lines'][0], 'Клиент А'),
    'passage line mentions referent and client'
);
assert_true(count($page['nonstandard_rows']) === 2, 'two disposed rows');
assert_true(
    ($page['nonstandard_rows'][0]['reason_label'] ?? '') === 'Нет связи (relationship)',
    'no_relationship label'
);
assert_true(
    ($page['nonstandard_rows'][0]['notified_label'] ?? '') === 'референт не уведомлён',
    'inbound disposed not notified'
);
assert_true(
    ($page['nonstandard_rows'][1]['notified_label'] ?? '') === 'референт уведомлён',
    'outbound disposed notified'
);

$line = formatPassageJournalLine([
    'direction' => 'inbound',
    'referent_name' => 'Ref',
    'client_name' => 'Client',
    'local_mailbox' => 'local@x',
    'external_mailbox' => 'ext@y',
    'received_at' => '2026-01-01 00:00:00',
    'action_at' => '2026-01-01 00:01:00',
]);
assert_true(str_contains($line, 'доставлено на local@x'), 'inbound phrasing');

$pageSrc = file_get_contents($root . '/web/relationship-status.php') ?: '';
assert_true(!str_contains($pageSrc, 'observability.stub_'), 'stub keys removed from page');
assert_true(str_contains($pageSrc, 'passage_title'), 'passage view present');
assert_true(str_contains($pageSrc, 'nonstandard_title'), 'nonstandard view present');

if ($failures > 0) {
    fwrite(STDERR, "panel_mail_passage_journal_test: $failures failure(s)\n");
    exit(1);
}
fwrite(STDOUT, "panel_mail_passage_journal_test: OK\n");
exit(0);
