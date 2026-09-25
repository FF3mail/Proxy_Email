<?php
declare(strict_types=1);

/**
 * PROMPT-79.2 / 79.2l / 79.2m / 79.2n — panel mail-passage journal (tabs + column filters).
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
        'event_ts' => '2026-09-18 10:00:00',
        'event_type' => 'delivered',
        'direction' => 'outbound',
        'referent_name' => 'Иван',
        'client_name' => 'Proton GmbH',
        'local_mailbox' => 'clientloc@test.loc',
        'external_mailbox' => 'client@ext.com',
        'received_at' => '2026-09-18 09:59:00',
        'action_at' => '2026-09-18 10:00:00',
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
        'event_ts' => '2026-09-20 23:59:59',
        'event_type' => 'delivered',
        'direction' => 'outbound',
        'referent_name' => 'Мария',
        'client_name' => 'Acme Inc',
        'local_mailbox' => 'clientloc2@test.loc',
        'external_mailbox' => 'acme@ext.com',
        'received_at' => '2026-09-20 23:58:00',
        'action_at' => '2026-09-20 23:59:59',
        'disposal_reason' => null,
        'notified' => 0,
        'source_message_id' => '<c@b>',
    ],
    [
        'id' => 12,
        'event_ts' => '2026-09-21 00:00:00',
        'event_type' => 'delivered',
        'direction' => 'outbound',
        'referent_name' => 'Мария',
        'client_name' => '100%_sure Co',
        'local_mailbox' => 'clientloc3@test.loc',
        'external_mailbox' => 'sure@ext.com',
        'received_at' => '2026-09-20 23:59:00',
        'action_at' => '2026-09-21 00:00:00',
        'disposal_reason' => null,
        'notified' => 0,
        'source_message_id' => '<d@b>',
    ],
];
$disposed = [[
    'id' => 2,
    'event_ts' => '2026-09-19 11:00:00',
    'event_type' => 'disposed',
    'direction' => 'inbound',
    'referent_name' => 'Иван',
    'client_name' => 'Клиент Б',
    'local_mailbox' => 'ref@test.loc',
    'external_mailbox' => 'unknown@ext.com',
    'received_at' => '2026-09-19 10:59:00',
    'action_at' => '2026-09-19 11:00:00',
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
], [
    'id' => 5,
    'event_ts' => '2026-09-20 15:00:00',
    'event_type' => 'skipped',
    'direction' => 'outbound',
    'referent_name' => 'Мария',
    'client_name' => 'Proton Skip Co',
    'local_mailbox' => 'ref2@test.loc',
    'external_mailbox' => 's@ext.com',
    'received_at' => '2026-09-20 14:59:00',
    'action_at' => '2026-09-20 15:00:00',
    'disposal_reason' => 'nested_message',
    'notified' => 0,
    'source_message_id' => null,
    'detail' => 'nested message/rfc822 filename=x.eml',
]];

/**
 * Fixture PDO: mirrors SQL filter semantics (party / date / direction / event).
 * Bound order matches appendPartyFilters + appendEventTsDateRange + appendDirectionFilter + limit.
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
    public int $prepareCount = 0;

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
        $this->prepareCount++;
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
                $dateFrom = null;
                $dateToExclusive = null;
                $direction = null;

                if (stripos($this->query, 'referent_name = ?') !== false && $values !== []) {
                    $referent = (string)array_shift($values);
                }
                if (stripos($this->query, 'client_name LIKE ?') !== false && $values !== []) {
                    $clientLike = (string)array_shift($values);
                }
                if (stripos($this->query, 'event_ts >= ?') !== false && $values !== []) {
                    $dateFrom = (string)array_shift($values);
                }
                if (stripos($this->query, 'DATE_ADD') !== false && $values !== []) {
                    // Bound value is Y-m-d; SQL is event_ts < DATE_ADD(?, INTERVAL 1 DAY)
                    $dateToDay = (string)array_shift($values);
                    $dateToExclusive = $dateToDay . ' 00:00:00';
                    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateToExclusive);
                    if ($dt !== false) {
                        $dateToExclusive = $dt->modify('+1 day')->format('Y-m-d H:i:s');
                    }
                }
                if (stripos($this->query, 'direction = ?') !== false && $values !== []) {
                    $direction = (string)array_shift($values);
                }

                $out = [];
                foreach ($this->rows as $row) {
                    if ($referent !== null && (string)($row['referent_name'] ?? '') !== $referent) {
                        continue;
                    }
                    if ($clientLike !== null) {
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
                    $ts = (string)($row['event_ts'] ?? '');
                    if ($dateFrom !== null && $ts < $dateFrom) {
                        continue;
                    }
                    if ($dateToExclusive !== null && !($ts < $dateToExclusive)) {
                        continue;
                    }
                    if ($direction !== null && (string)($row['direction'] ?? '') !== $direction) {
                        continue;
                    }
                    $out[] = $row;
                }
                return array_slice($out, 0, $limit);
            }
        };
    }
};

setPanelLang('ru');

// Default tab = passage; nonstandard query must not run.
$pdo->prepareCount = 0;
$pdo->lastNonstandardSql = '';
$page = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
]);
assert_true(($page['error'] ?? null) === null, 'no error from fixture journal');
assert_true(($page['tab'] ?? '') === PANEL_TAB_PASSAGE, 'default/explicit tab is passage');
assert_true(count($page['passage_rows']) === 4, 'four delivered rows unfiltered');
assert_true(count($page['passage_lines']) === 4, 'four passage lines');
assert_true($page['nonstandard_rows'] === [], 'passage tab does not load nonstandard rows');
assert_true($pdo->lastNonstandardSql === '', 'passage tab does not prepare nonstandard SQL');

$pageNsDefault = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_NONSTANDARD,
]);
assert_true(count($pageNsDefault['nonstandard_rows']) === 4, 'nonstandard all → 4 rows');
assert_true($pageNsDefault['passage_rows'] === [], 'nonstandard tab does not load passage rows');
assert_true(
    ($pageNsDefault['nonstandard_rows'][0]['reason_label'] ?? '') !== '',
    'nonstandard rows get reason_label'
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

// --- I2 / N4: ns_event replaces event_filter ---
setPanelLang('ru');
$pageSkipped = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_NONSTANDARD,
    'ns_event' => PANEL_EVENT_FILTER_SKIPPED,
]);
assert_true(count($pageSkipped['nonstandard_rows']) === 2, 'ns_event=skipped → 2 rows');
foreach ($pageSkipped['nonstandard_rows'] as $row) {
    assert_true(($row['event_type'] ?? '') === 'skipped', 'ns_event skipped row type');
}
$pageDisposed = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_NONSTANDARD,
    'ns_event' => PANEL_EVENT_FILTER_DISPOSED,
]);
assert_true(count($pageDisposed['nonstandard_rows']) === 2, 'ns_event=disposed → 2 rows');

// --- N3: passage date range (inclusive date_to same-day) ---
$pageDate = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_date_from' => '2026-09-20',
    'passage_date_to' => '2026-09-20',
]);
assert_true(count($pageDate['passage_rows']) === 2, 'passage date 2026-09-20 → 2 rows (incl. 23:59:59)');
foreach ($pageDate['passage_rows'] as $row) {
    $ts = (string)($row['event_ts'] ?? '');
    assert_true(str_starts_with($ts, '2026-09-20'), "passage date row on 2026-09-20: $ts");
}
$pageDateFrom = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_date_from' => '2026-09-21',
]);
assert_true(count($pageDateFrom['passage_rows']) === 1, 'passage_date_from 2026-09-21 → 1');
assert_true(
    ($pageDateFrom['passage_rows'][0]['client_name'] ?? '') === '100%_sure Co',
    'date_from boundary is midnight-inclusive'
);

// --- N3: passage_direction ---
$pageIn = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_direction' => 'inbound',
]);
assert_true(count($pageIn['passage_rows']) === 1, 'passage_direction=inbound → 1');
assert_true(($pageIn['passage_rows'][0]['direction'] ?? '') === 'inbound', 'inbound direction');
$pageOut = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_direction' => 'outbound',
]);
assert_true(count($pageOut['passage_rows']) === 3, 'passage_direction=outbound → 3');

// --- N3 malformed dates ignored ---
$pdo->lastPassageBinds = [];
$pageBadDate = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_date_from' => '2026-13-99',
    'passage_date_to' => 'not-a-date',
]);
assert_true(count($pageBadDate['passage_rows']) === 4, 'malformed passage dates ignored → all 4');
assert_true(($pageBadDate['passage_date_from'] ?? 'x') === '', 'malformed passage_date_from cleared');
assert_true(($pageBadDate['passage_date_to'] ?? 'x') === '', 'malformed passage_date_to cleared');
assert_true(
    !str_contains($pdo->lastPassageSql, 'event_ts >='),
    'malformed dates not applied in SQL'
);

// --- M2 / N2: passage_referent + passage_client ---
$pageRef = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_referent' => 'Иван',
]);
assert_true(count($pageRef['passage_rows']) === 2, 'referent Иван → 2 passage rows');
foreach ($pageRef['passage_rows'] as $row) {
    assert_true(($row['referent_name'] ?? '') === 'Иван', 'passage row referent Иван');
}
assert_true(in_array('Иван', $page['referent_options'], true), 'referent_options includes Иван');
assert_true(in_array('Мария', $page['referent_options'], true), 'referent_options includes Мария');

$pageClient = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_client' => 'Proton',
]);
assert_true(count($pageClient['passage_rows']) === 2, 'client Proton → 2 rows');
$names = array_map(static fn($r) => (string)$r['client_name'], $pageClient['passage_rows']);
assert_true(in_array('Proton GmbH', $names, true) && in_array('Proton Labs', $names, true), 'both Proton clients');
assert_true(!in_array('Acme Inc', $names, true), 'Acme excluded from Proton search');

$pageClientLc = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_client' => 'proton',
]);
assert_true(count($pageClientLc['passage_rows']) === 2, 'lowercase proton matches');

assert_true(escapeSqlLikeWildcards('100%_sure') === '100\\%\\_sure', 'escapeSqlLikeWildcards escapes % and _');
$pagePct = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_client' => '100%_sure',
]);
assert_true(count($pagePct['passage_rows']) === 1, 'literal percent/underscore client match');
assert_true(
    ($pagePct['passage_rows'][0]['client_name'] ?? '') === '100%_sure Co',
    'matched literal 100%_sure Co'
);
$pageBarePct = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_client' => '%',
]);
assert_true(
    count($pageBarePct['passage_rows']) === 1,
    'bare % search matches only names containing literal %'
);

$pageCombo = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_referent' => 'Иван',
    'passage_client' => 'Proton',
]);
assert_true(count($pageCombo['passage_rows']) === 2, 'Иван+Proton → 2');
$pageCombo2 = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_referent' => 'Мария',
    'passage_client' => 'Proton',
]);
assert_true(count($pageCombo2['passage_rows']) === 0, 'Мария+Proton → 0');
$pageCombo3 = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_PASSAGE,
    'passage_referent' => 'Мария',
    'passage_client' => 'Acme',
]);
assert_true(count($pageCombo3['passage_rows']) === 1, 'Мария+Acme → 1');

assert_true(
    str_contains($pdo->lastPassageSql, 'LIKE ? ESCAPE'),
    'passage SQL uses LIKE ESCAPE for client filter'
);

// --- N4: ns date / direction / party combined ---
$pageNsDate = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_NONSTANDARD,
    'ns_date_from' => '2026-09-20',
    'ns_date_to' => '2026-09-20',
]);
assert_true(count($pageNsDate['nonstandard_rows']) === 1, 'ns date 2026-09-20 → 1');
assert_true(
    ($pageNsDate['nonstandard_rows'][0]['client_name'] ?? '') === 'Proton Skip Co',
    'ns date row is Proton Skip Co'
);

$pageNsDir = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_NONSTANDARD,
    'ns_direction' => 'outbound',
]);
assert_true(count($pageNsDir['nonstandard_rows']) === 2, 'ns_direction=outbound → 2');

$pageNsCombo = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_NONSTANDARD,
    'ns_referent' => 'Мария',
    'ns_client' => 'Proton',
    'ns_event' => PANEL_EVENT_FILTER_SKIPPED,
    'ns_direction' => 'outbound',
    'ns_date_from' => '2026-09-20',
    'ns_date_to' => '2026-09-21',
]);
assert_true(count($pageNsCombo['nonstandard_rows']) === 1, 'ns combined filters → 1');
assert_true(
    ($pageNsCombo['nonstandard_rows'][0]['client_name'] ?? '') === 'Proton Skip Co',
    'ns combined matches Proton Skip Co'
);

// Malformed ns dates ignored
$pageNsBad = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_NONSTANDARD,
    'ns_date_from' => '2026-13-99',
    'ns_date_to' => 'nope',
]);
assert_true(count($pageNsBad['nonstandard_rows']) === 4, 'malformed ns dates ignored → all 4');
assert_true(($pageNsBad['ns_date_from'] ?? 'x') === '', 'malformed ns_date_from cleared');
assert_true(
    !str_contains($pdo->lastNonstandardSql, 'event_ts >='),
    'malformed ns dates not in SQL'
);

// Tab namespaces do not leak: passage filters ignored on nonstandard tab
$pageNoLeak = buildRelationshipStatusPageData($pdo, [
    'limit' => 50,
    'tab' => PANEL_TAB_NONSTANDARD,
    'passage_referent' => 'Иван',
    'passage_client' => 'does-not-exist',
    'passage_direction' => 'inbound',
]);
assert_true(
    count($pageNoLeak['nonstandard_rows']) === 4,
    'passage_* params do not filter nonstandard tab'
);

// --- N1: tab markup structure (live exclusivity verified on lab HTML) ---
$pageSrc = file_get_contents($root . '/web/relationship-status.php') ?: '';
assert_true(str_contains($pageSrc, 'id="journal-tabs"'), 'tab bar present');
assert_true(str_contains($pageSrc, 'id="tab-passage"'), 'passage tab header');
assert_true(str_contains($pageSrc, 'id="tab-nonstandard"'), 'nonstandard tab header');
assert_true(str_contains($pageSrc, 'name="tab"'), 'tab hidden field in forms');
assert_true(str_contains($pageSrc, 'if ($isPassage)'), 'tab gated by $isPassage');

$ifPos = strpos($pageSrc, 'if ($isPassage)');
$passageCardPos = strpos($pageSrc, 'id="passage-card"');
$nsCardPos = strpos($pageSrc, 'id="nonstandard-card"');
assert_true(
    $ifPos !== false && $passageCardPos !== false && $nsCardPos !== false
    && $ifPos < $passageCardPos && $passageCardPos < $nsCardPos,
    'passage-card before nonstandard-card under isPassage gate'
);
// Between the two cards there must be an else: so they are mutually exclusive, not stacked.
$between = substr($pageSrc, $passageCardPos, $nsCardPos - $passageCardPos);
assert_true(str_contains($between, 'else:'), 'else: separates passage and nonstandard cards');
assert_true(!str_contains($between, 'id="nonstandard-card"'), 'cards not nested');

assert_true(str_contains($pageSrc, 'id="passage-column-filters"'), 'passage column filters present');
assert_true(str_contains($pageSrc, 'passage_date_from'), 'passage_date_from control');
assert_true(str_contains($pageSrc, 'passage_direction'), 'passage_direction control');
assert_true(str_contains($pageSrc, 'id="ns-column-filters"'), 'ns column filters present');
assert_true(str_contains($pageSrc, 'ns_date_from'), 'ns_date_from control');
assert_true(str_contains($pageSrc, 'ns_event'), 'ns_event control');
assert_true(str_contains($pageSrc, 'ns_direction'), 'ns_direction control');
assert_true(str_contains($pageSrc, 'ns_referent'), 'ns_referent control');
assert_true(str_contains($pageSrc, 'ns_client'), 'ns_client control');
assert_true(str_contains($pageSrc, 'passage_referent'), 'passage_referent control');
assert_true(str_contains($pageSrc, 'passage_client'), 'passage_client control');
assert_true(!str_contains($pageSrc, 'id="event_filter"'), 'old event_filter id removed');
assert_true(str_contains($pageSrc, 'observability.tab_passage'), 'tab_passage i18n key');
assert_true(str_contains($pageSrc, 'observability.tab_nonstandard'), 'tab_nonstandard i18n key');

// Shared limit param name on both tabs (documented choice: same `limit` GET param).
assert_true(
    substr_count($pageSrc, 'name="limit"') === 2,
    'limit control duplicated per tab with shared param name'
);

if ($failures > 0) {
    fwrite(STDERR, "panel_mail_passage_journal_test: $failures failure(s)\n");
    exit(1);
}
fwrite(STDOUT, "panel_mail_passage_journal_test: OK\n");
exit(0);
