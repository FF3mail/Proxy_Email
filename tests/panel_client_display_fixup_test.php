<?php
declare(strict_types=1);

/**
 * Issue #35 PR #169 fixup: validation, ambiguity, N+1, journal filter SQL (sqlite).
 * Run: php tests/panel_client_display_fixup_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/web/includes/client_display.php';
require_once $root . '/web/includes/helpers.php';
require_once $root . '/web/includes/relationship_status.php';

$failures = 0;

function ok(bool $cond, string $msg): void
{
    global $failures;
    if ($cond) {
        fwrite(STDOUT, "OK: $msg\n");
        return;
    }
    fwrite(STDERR, "FAIL: $msg\n");
    $failures++;
}

// --- (a) Control characters ---
ok(!clientDisplayNameInvalid(''), 'empty display name not invalid');
ok(!clientDisplayNameInvalid(null), 'null display name not invalid');
ok(!clientDisplayNameInvalid("O'Brien <Test> & Co"), 'apostrophe ampersand angle brackets allowed');
ok(!clientDisplayNameInvalid('Proton GmbH'), 'ordinary letters allowed');
ok(!clientDisplayNameInvalid('Naïve Café'), 'Unicode letters allowed');
ok(clientDisplayNameInvalid("\tTabbed"), 'tab rejected');
ok(clientDisplayNameInvalid("Line\nBreak"), 'newline rejected');
ok(clientDisplayNameInvalid("\x00"), 'NUL rejected');
ok(clientDisplayNameInvalid("\x7F"), 'DEL rejected');
ok(clientDisplayNameInvalid("\u{2028}"), 'U+2028 rejected');
ok(clientDisplayNameInvalid("\u{2029}"), 'U+2029 rejected');

$indexSrc = file_get_contents($root . '/web/index.php') ?: '';
ok(str_contains($indexSrc, 'clientDisplayNameInvalid'), 'handleRelationshipSave uses clientDisplayNameInvalid');
ok(str_contains($indexSrc, 'relationship.error.display_name_invalid'), 'handleRelationshipSave flash invalid key');

ob_start();
renderClientDisplayCell('<Evil>', 'a@b.com');
$html = (string) ob_get_clean();
ok(str_contains($html, '&lt;Evil&gt;'), 'render escapes angle brackets in display name (M2 target)');

// --- (b) Ambiguity in enrichJournalRowsClientDisplay ---
final class ClientCatalogPdo
{
    /** @var list<array<string,mixed>> */
    private array $rows;

  public int $prepareCount = 0;
  public int $executeCount = 0;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function prepare(string $query)
    {
        if (stripos($query, 'FROM clients') === false) {
            return new class {
                public function execute(?array $p = null): bool { return true; }
                public function fetchAll($m = null): array { return []; }
            };
        }
        $this->prepareCount++;
        $catalog = $this->rows;
        $outer = $this;
        return new class ($catalog, $outer) {
            private array $catalog;
            private ClientCatalogPdo $outer;
            /** @var list<mixed> */
            private array $params = [];

            public function __construct(array $catalog, ClientCatalogPdo $outer)
            {
                $this->catalog = $catalog;
                $this->outer = $outer;
            }

            public function execute(?array $params = null): bool
            {
                $this->outer->executeCount++;
                $this->params = $params ?? [];
                return true;
            }

            public function fetchAll($mode = null): array
            {
                $emails = [];
                $n = count($this->params) / 2;
                for ($i = 0; $i < $n; $i++) {
                    $emails[(string) $this->params[$i]] = true;
                }
                $out = [];
                foreach ($this->catalog as $row) {
                    $ext = (string) ($row['external_client_email'] ?? '');
                    $loc = (string) ($row['local_client_email'] ?? '');
                    if (($ext !== '' && isset($emails[$ext])) || ($loc !== '' && isset($emails[$loc]))) {
                        $out[] = $row;
                    }
                }
                return $out;
            }
        };
    }
}

$shared = 'shared@x.test';
$pdoAmb = new ClientCatalogPdo([
    [
        'id' => 1,
        'external_client_email' => $shared,
        'local_client_email' => 'loc-a@test.loc',
        'display_name' => 'Client A',
    ],
    [
        'id' => 2,
        'external_client_email' => 'ext-b@test.loc',
        'local_client_email' => $shared,
        'display_name' => 'Client B',
    ],
]);
$rowAmb = [['client_name' => $shared]];
$outAmb = enrichJournalRowsClientDisplay($rowAmb, $pdoAmb);
ok(($outAmb[0]['client_display_primary'] ?? '') === $shared, 'ambiguity (i) shows email only');

$pdoAmb2 = new ClientCatalogPdo([
    [
        'id' => 10,
        'external_client_email' => 'dup@collision.test',
        'local_client_email' => 'loc10@test.loc',
        'display_name' => 'First Name',
    ],
    [
        'id' => 11,
        'external_client_email' => 'dup@collision.test',
        'local_client_email' => 'loc11@test.loc',
        'display_name' => 'Second Name',
    ],
]);
$outAmb2 = enrichJournalRowsClientDisplay([['client_name' => 'dup@collision.test']], $pdoAmb2);
ok(($outAmb2[0]['client_display_primary'] ?? '') === 'dup@collision.test', 'ambiguity (ii) shows email only');

$pdoSingle = new ClientCatalogPdo([
    [
        'id' => 3,
        'external_client_email' => $shared,
        'local_client_email' => $shared,
        'display_name' => 'Solo Co',
    ],
]);
$outSingle = enrichJournalRowsClientDisplay([['client_name' => $shared]], $pdoSingle);
ok(($outSingle[0]['client_display_primary'] ?? '') === 'Solo Co', 'one client ext=local=X shows name (iii)');

$outNone = enrichJournalRowsClientDisplay([['client_name' => 'unknown@nowhere.test']], $pdoSingle);
ok(($outNone[0]['client_display_primary'] ?? '') === 'unknown@nowhere.test', 'no match shows email only (iv)');

// --- (c) No N+1: one clients lookup per enrich call ---
function makeJournalRows(int $n): array
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = ['client_name' => "client{$i}@partner.test"];
    }
    return $rows;
}

/** @return list<array<string,mixed>> */
function catalogForRows(int $n): array
{
    $catalog = [];
    for ($i = 0; $i < $n; $i++) {
        $catalog[] = [
            'id' => $i + 1,
            'external_client_email' => "client{$i}@partner.test",
            'local_client_email' => "loc{$i}@test.loc",
            'display_name' => "Partner {$i}",
        ];
    }
    return $catalog;
}

$pdo4 = new ClientCatalogPdo(catalogForRows(4));
enrichJournalRowsClientDisplay(makeJournalRows(4), $pdo4);
ok($pdo4->prepareCount === 1 && $pdo4->executeCount === 1, 'N+1: 4 journal rows -> 1 clients prepare/execute');

$pdo40 = new ClientCatalogPdo(catalogForRows(40));
enrichJournalRowsClientDisplay(makeJournalRows(40), $pdo40);
ok($pdo40->prepareCount === 1 && $pdo40->executeCount === 1, 'N+1: 40 journal rows -> 1 clients prepare/execute');

// --- (d) Journal client filter against sqlite (real SQL, not fake PDO) ---
$sqlite = new PDO('sqlite::memory:');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlite->exec(
    'CREATE TABLE clients (
        id INTEGER PRIMARY KEY,
        external_client_email TEXT,
        local_client_email TEXT,
        display_name TEXT
    )'
);
$sqlite->exec('CREATE TABLE mail_passage_journal (client_name TEXT)');
$sqlite->exec(
    "INSERT INTO clients VALUES
    (1, 'alpha@ext.com', 'alpha-loc@test.loc', 'Alpha GmbH'),
    (2, 'beta@ext.com', 'beta-loc@test.loc', NULL),
    (3, 'pct@ext.com', 'pct-loc@test.loc', '100%_sure Co')"
);
$sqlite->exec(
    "INSERT INTO mail_passage_journal (client_name) VALUES
    ('alpha@ext.com'),
    ('beta@ext.com'),
    ('pct@ext.com'),
    ('gamma@ext.com')"
);

/**
 * @return list<string>
 */
function journalFilterMatches(PDO $pdo, string $clientNeedle): array
{
    $where = [];
    $binds = [];
    appendPartyFilters($where, $binds, '', $clientNeedle);
    $sql = 'SELECT client_name FROM mail_passage_journal';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    // SQLite harness only: MariaDB ESCAPE '\\' is one backslash; SQLite needs a single-character ESCAPE.
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $sql = str_replace("ESCAPE '\\\\'", "ESCAPE '\\'", $sql);
    }
    $stmt = $pdo->prepare($sql);
    $i = 1;
    foreach ($binds as $bind) {
        $stmt->bindValue($i++, $bind[0], (int) $bind[1]);
    }
    $stmt->execute();
    $names = [];
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $names[] = (string) $row['client_name'];
    }
    sort($names);
    return $names;
}

ok(journalFilterMatches($sqlite, 'alpha') === ['alpha@ext.com'], 'filter matches email substring');
ok(journalFilterMatches($sqlite, 'GmbH') === ['alpha@ext.com'], 'filter matches display name substring');
$pctMatches = journalFilterMatches($sqlite, '100%_sure');
ok($pctMatches === ['pct@ext.com'], 'filter literal % and _ in pattern');
ok(!in_array('beta@ext.com', journalFilterMatches($sqlite, 'GmbH'), true), 'beta without display_name not matched by GmbH pattern');
ok(journalFilterMatches($sqlite, 'Alpha') === ['alpha@ext.com'], 'display name search returns journal rows for that client only');

if ($failures > 0) {
    fwrite(STDERR, "panel_client_display_fixup_test: $failures failure(s)\n");
    exit(1);
}
fwrite(STDOUT, "panel_client_display_fixup_test: OK\n");
exit(0);
