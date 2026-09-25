<?php
declare(strict_types=1);

/**
 * PROMPT-79.2 / 79.2l / 79.2m — panel mail-passage journal views (fixture PDO + i18n).
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

$delivered = [
    [
        'id' => 1,
        'event_ts' => '2026-09-20 10:00:00',
        'event_type' => 'delivered',
        'direction' => 'outbound',
        'referent_name' => 'Иван',
        'client_name' => 'Proton GmbH',
        'local_mailbox' => 'clientloc@test.loc',
        'external_mailbox' => 'client@ext.com',
        'received_at' => '2026-09-20 09:59:00',
        'action_at' => '2026-09-20 10:00:00',
        'disposal_reason' => null,
        'notified' => 0,
        'source_message_id' => '<a@b>',
    ],
    [
        'id' => 10,
        'event_ts' => '2026-09-20 10:05:00',
        'event_type' => 'delivered',
        'direction' => 'inbound',
        'referent_name' => 'Иван',
        'client_name' => 'Proton Labs',
        'local_mailbox' => 'refloc@test.loc',
        'external_mailbox' => 'p@ext.com',
        'received_at' => '2026-09-20 10:04:00',
        'action_at' => '2026-09-20 10:05:00',
        'disposal_reason' => null,
        'notified' => 0,
        'source_message_id' => '<b@b>',
    ],
    [
        'id' => 11,
        'event_ts' => '2026-09-20 10:10:00',
        'event_type' => 'delivered',
        'direction' => 'outbound',
        'referent_name' => 'Мария',
        'client_name' => 'Acme Inc',
        'local_mailbox' => 'clientloc2@test.loc',
        'external_mailbox' => 'acme@ext.com',
        'received_at' => '2026-09-20 10:09:00',
        'action_at' => '2026-09-20 10:10:00',
        'disposal_reason' => null,
        'notified' => 0,
        'source_message_id' => '<c@b>',
    ],
    [
        'id' => 12,
        'event_ts' => '2026-09-20 10:15:00',
        'event_type' => 'delivered',
        'direction' => 'outbound',
        'referent_name' => 'Мария',
        'client_name' => '100%_sure Co',
        'local_mailbox' => 'clientloc3@test.loc',
        'external_mailbox' => 'sure@ext.com',
        'received_at' => '2026-09-20 10:14:00',
        'action_at' => '2026-09-20 10:15:00',
        'disposal_reason' => null,
        'notified' => 0,
        'source_message_id' => '<d@b>',
    ],
];
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

/**
 * Fixture PDO: filters delivered/nonstandard in PHP to mirror SQL semantics.
 */
$pdo = new class ($delivered, $disposed) {
    /** @var list<array<string,mixed>> */
    private array $delivered;
    /** @var list<array<string,mixed>> */
    private array $nonstandard;
    public string $lastPassageSql = '';
    public string $lastNonstandardSql = '';
    /** @var list<mixed> */
    public array $lastPassageBinds = [];
    /** @var list<mixed> */
    public array $lastNonstandardBinds = [];

    public function __construct(array $delivered, array $nonstandard)
    {
        $this->delivered = $delivered;
        $this->nonstandard = $nonstandard;
    }

    public function query(string $query)
    {
        if (stripos($query, 'information_schema.TABLES') !== false) {
            return new class {
                public function fetchColumn(): int
                {
                    return 1;
                }
            };
        }
        if (stripos($query, 'SELECT DISTINCT referent_name') !== false) {
            $names = [];
            foreach (array_merge($this->delivered, $this->nonstandard) as $row) {
                $n = trim((string)($row['referent_name'] ?? ''));
                if ($n !== '') {
                    $names[$n] = true;
                }
            }
            $rows = [];
            foreach (array_keys($names) as $n) {
                $rows[] = ['referent_name' => $n];
            }
            sort($rows);
            return new class ($rows) {
                private array $rows;
                public function __construct(array $rows)
                {
                    $this->rows = $rows;
                }
                public function fetchAll($mode = null): array
                {
                    return $this->rows;
                }
            };
        }
        return new class {
            public function fetchColumn(): int
            {
                return 0;
            }
            public function fetchAll($mode = null): array
            {
                return [];
            }
        };
    }

    public function prepare(string $query)
    {
        $isPassage = stripos($query, "event_type = 'delivered'") !== false;
        if ($isPassage) {
            $this->lastPassageSql = $query;
            $source = $this->delivered;
        } else {
            $this->lastNonstandardSql = $query;
            $source = $this->nonstandard;
            if (stripos($query, "event_type = 'skipped'") !== false
                && stripos($query, 'IN') === false
            ) {
                $source = array_values(array_filter(
                    $this->nonstandard,
                    static fn(array $r): bool => ($r['event_type'] ?? '') === 'skipped'
                ));
            } elseif (stripos($query, "event_type = 'disposed'") !== false
                && stripos($query, 'IN') === false
            ) {
                $source = array_values(array_filter(
                    $this->nonstandard,
                    static fn(array $r): bool => ($r['event_type'] ?? '') === 'disposed'
                ));
            }
        }

        $outer = $this;
        return new class ($source, $isPassage, $outer, $query) {
            private array $rows;
            private bool $isPassage;
            private object $outer;
            private string $query;
            /** @var array<int, mixed> */
            private array $binds = [];
            private int $limit = 100;

            public function __construct(array $rows, bool $isPassage, object $outer, string $query)
            {
                $this->rows = $rows;
                $this->isPassage = $isPassage;
                $this->outer = $outer;
                $this->query = $query;
            }

            public function bindValue($param, $value, $type = null): bool
            {
                $this->binds[(int)$param] = $value;
                return true;
            }

            public function execute($params = null): bool
            {
                return true;
            }

            public function fetchAll($mode = null): array
            {
                $binds = $this->binds;
                ksort($binds);
                $values = array_values($binds);
                if ($this->isPassage) {
                    $this->outer->lastPassageBinds = $values;
                } else {
                    $this->outer->lastNonstandardBinds = $values;
                }

                $limit = 100;
                if ($values !== []) {
                    $limit = (int)array_pop($values);
                }

                $referent = null;
                $clientLike = null;
                // Bound order: optional referent, optional client LIKE, then limit.
                if (stripos($this->query, 'referent_name = ?') !== false && $values !== []) {
                    $referent = (string)array_shift($values);
                }
                if (stripos($this->query, 'client_name LIKE ?') !== false && $values !== []) {
                    $clientLike = (string)array_shift($values);
                }

                $out = [];
                foreach ($this->rows as $row) {
                    if ($referent !== null && (string)($row['referent_name'] ?? '') !== $referent) {
                        continue;
                    }
                    if ($clientLike !== null) {
                        // Pattern is %escaped% — reverse escape for literal match simulation.
                        $needle = $clientLike;
                        if (str_starts_with($needle, '%') && str_ends_with($needle, '%')) {
                            $needle = substr($needle, 1, -1);
                        }
                        $needle = str_replace(['\\%', '\\_', '\\\\'], ['%', '_', '\\'], $needle);
                        $hay = (string)($row['client_name'] ?? '');
                        if (mb_stripos($hay, $needle) === false) {
                            continue;
                        }
                    }
                    $out[] = $row;
                }
                return array_slice($out, 0, $limit);
            }
        };
    }
};

setPanelLang('ru');
$page = buildRelationshipStatusPageData($pdo, 50);

assert_true(($page['error'] ?? null) === null, 'no error from fixture journal');
assert_true(count($page['passage_rows']) === 4, 'four delivered rows unfiltered');
assert_true(count($page['passage_lines']) === 4, 'four passage lines');
assert_true(count($page['nonstandard_rows']) === 3, 'three nonstandard rows (default filter)');
assert_true(
    ($page['nonstandard_rows'][0]['reason_label'] ?? '') === 'Нет связи (relationship)',
    'no_relationship label (ru)'
);
assert_true(
    disposalReasonLabel('nested_message') === 'Вложенное письмо (message/rfc822) не принимается',
    'nested_message label (ru)'
);

// --- I1 regression: every known reason/event has distinct ru vs en ---
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

// --- I2 regression: skipped/disposed filters ---
setPanelLang('ru');
$pageSkipped = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_SKIPPED);
assert_true(count($pageSkipped['nonstandard_rows']) === 1, 'skipped filter returns one row');
assert_true(
    ($pageSkipped['nonstandard_rows'][0]['event_type'] ?? '') === 'skipped',
    'skipped filter row is skipped'
);
$pageDisposed = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_DISPOSED);
assert_true(count($pageDisposed['nonstandard_rows']) === 2, 'disposed filter returns two rows');

// --- M2: passage_referent ---
$pageRef = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_ALL, 'Иван', '');
assert_true(count($pageRef['passage_rows']) === 2, 'referent Иван → 2 passage rows');
foreach ($pageRef['passage_rows'] as $row) {
    assert_true(($row['referent_name'] ?? '') === 'Иван', 'passage row referent Иван');
}
assert_true(in_array('Иван', $page['referent_options'], true), 'referent_options includes Иван');
assert_true(in_array('Мария', $page['referent_options'], true), 'referent_options includes Мария');

// --- M2: passage_client substring (case-insensitive) ---
$pageClient = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_ALL, '', 'Proton');
assert_true(count($pageClient['passage_rows']) === 2, 'client Proton → 2 rows');
$names = array_map(static fn($r) => (string)$r['client_name'], $pageClient['passage_rows']);
assert_true(in_array('Proton GmbH', $names, true) && in_array('Proton Labs', $names, true), 'both Proton clients');
assert_true(!in_array('Acme Inc', $names, true), 'Acme excluded from Proton search');

$pageClientLc = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_ALL, '', 'proton');
assert_true(count($pageClientLc['passage_rows']) === 2, 'lowercase proton matches');

// Literal % / _ in stored name + search input must not act as SQL wildcards
assert_true(escapeSqlLikeWildcards('100%_sure') === '100\\%\\_sure', 'escapeSqlLikeWildcards escapes % and _');
$pagePct = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_ALL, '', '100%_sure');
assert_true(count($pagePct['passage_rows']) === 1, 'literal percent/underscore client match');
assert_true(
    ($pagePct['passage_rows'][0]['client_name'] ?? '') === '100%_sure Co',
    'matched literal 100%_sure Co'
);
// Searching bare "%" must not match every row (would if unescaped)
$pageBarePct = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_ALL, '', '%');
assert_true(
    count($pageBarePct['passage_rows']) === 1,
    'bare % search matches only names containing literal %'
);

// Combined referent + client
$pageCombo = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_ALL, 'Иван', 'Proton');
assert_true(count($pageCombo['passage_rows']) === 2, 'Иван+Proton → 2');
$pageCombo2 = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_ALL, 'Мария', 'Proton');
assert_true(count($pageCombo2['passage_rows']) === 0, 'Мария+Proton → 0');
$pageCombo3 = buildRelationshipStatusPageData($pdo, 50, PANEL_EVENT_FILTER_ALL, 'Мария', 'Acme');
assert_true(count($pageCombo3['passage_rows']) === 1, 'Мария+Acme → 1');

// SQL uses bound LIKE with ESCAPE
assert_true(
    str_contains($pdo->lastPassageSql, 'LIKE ? ESCAPE'),
    'passage SQL uses LIKE ESCAPE for client filter'
);

// --- M1: markup placement ---
$pageSrc = file_get_contents($root . '/web/relationship-status.php') ?: '';
$passageCardPos = strpos($pageSrc, 'id="passage-card"');
$nsCardPos = strpos($pageSrc, 'id="nonstandard-card"');
$nsFilterPos = strpos($pageSrc, 'id="nonstandard-filter-form"');
$eventFilterPos = strpos($pageSrc, 'id="event_filter"');
$passageToolbarPos = strpos($pageSrc, 'id="passage-toolbar"');
assert_true($passageCardPos !== false && $nsCardPos !== false, 'both cards present');
assert_true($passageCardPos < $nsCardPos, 'passage card before nonstandard card');
assert_true(
    $nsFilterPos !== false && $nsFilterPos > $nsCardPos,
    'nonstandard-filter-form inside/after nonstandard card open'
);
assert_true(
    $eventFilterPos !== false && $eventFilterPos > $nsCardPos,
    'event_filter control lives under nonstandard card'
);
assert_true(
    $passageToolbarPos !== false && $passageToolbarPos < $nsCardPos,
    'passage-toolbar before nonstandard card'
);
$passageSection = substr($pageSrc, $passageCardPos, $nsCardPos - $passageCardPos);
assert_true(
    !str_contains($passageSection, 'id="event_filter"'),
    'event_filter id not inside passage-card section'
);
assert_true(
    !str_contains($passageSection, 'id="nonstandard-filter-form"'),
    'nonstandard-filter-form not inside passage-card section'
);
assert_true(str_contains($pageSrc, 'passage_referent'), 'passage_referent control present');
assert_true(str_contains($pageSrc, 'passage_client'), 'passage_client control present');
assert_true(str_contains($pageSrc, 'observability.filter_skipped'), 'skipped filter label key present');
assert_true(str_contains($pageSrc, 'observability.limit_shared_note'), 'shared limit note present');

if ($failures > 0) {
    fwrite(STDERR, "panel_mail_passage_journal_test: $failures failure(s)\n");
    exit(1);
}
fwrite(STDOUT, "panel_mail_passage_journal_test: OK\n");
exit(0);
