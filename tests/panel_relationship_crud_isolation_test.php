#!/usr/bin/env php
<?php
/**
 * Issue #88 — CRUD-level isolation for two relationships under one referent.
 *
 * Exercises the real relationship persist/delete/toggle helpers (used by
 * relationship_save / relationship_delete / toggle_active entity=client) and
 * referentSyncActiveAfterRelationshipChange against an in-memory fake PDO
 * (same style as panel_mail_passage_journal_test.php / object $pdo helpers).
 *
 * Proven here (deterministic, no MariaDB):
 * - updateClientRelationshipRow scopes UPDATE by id+referent_id; C2 untouched
 * - deleteClientRelationshipRow deletes only the targeted row; C2 untouched
 * - toggleClientRelationshipActive flips only the targeted clients.active
 * - referentSyncActiveAfterRelationshipChange after deleting C1 does not mutate C2
 *
 * Not proven here (requires live MariaDB + panel HTTP POST harness):
 * - full handleRelationshipSave() including mailbox verify / redirects / flash
 * - engine-level UNIQUE / FK enforcement identical to MariaDB
 *
 * Run: php tests/panel_relationship_crud_isolation_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/web/includes/i18n.php';
initPanelI18n();
require_once $root . '/web/includes/relationship_editor.php';
require_once $root . '/web/includes/referent_activation.php';

if (!function_exists('writeLog')) {
    function writeLog(string $message): void
    {
    }
}

$failures = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
    } else {
        echo "OK: {$msg}\n";
    }
}

/**
 * In-memory PDO stand-in for clients/referents/external_accounts CRUD helpers.
 */
final class FakeRelationshipCrudPdo
{
    /** @var array<int, array<string, mixed>> */
    public array $clients = [];
    /** @var array<int, array<string, mixed>> */
    public array $accounts = [];
    /** @var array<int, array<string, mixed>> */
    public array $referents = [];

    public function __construct(array $referents, array $accounts, array $clients)
    {
        foreach ($referents as $row) {
            $this->referents[(int) $row['id']] = $row;
        }
        foreach ($accounts as $row) {
            $this->accounts[(int) $row['id']] = $row;
        }
        foreach ($clients as $row) {
            $this->clients[(int) $row['id']] = $row;
        }
    }

    public function prepare(string $query): FakeRelationshipCrudStatement
    {
        return new FakeRelationshipCrudStatement($this, $query);
    }

    public function query(string $query): FakeRelationshipCrudStatement
    {
        $stmt = $this->prepare($query);
        $stmt->execute([]);

        return $stmt;
    }
}

final class FakeRelationshipCrudStatement
{
    private FakeRelationshipCrudPdo $pdo;
    private string $query;
    /** @var list<mixed> */
    private array $params = [];
    /** @var mixed */
    private $result = null;
    private int $rowCount = 0;

    public function __construct(FakeRelationshipCrudPdo $pdo, string $query)
    {
        $this->pdo = $pdo;
        $this->query = $query;
    }

    public function execute($params = null): bool
    {
        $this->params = array_values($params ?? []);
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($this->query)) ?? $this->query);

        if (str_starts_with($sql, 'select c.*,')) {
            $referentId = (int) ($this->params[0] ?? 0);
            $rows = [];
            foreach ($this->pdo->clients as $c) {
                if ((int) $c['referent_id'] !== $referentId) {
                    continue;
                }
                $row = $c;
                $eaId = (int) ($c['external_account_id'] ?? 0);
                $ea = $this->pdo->accounts[$eaId] ?? null;
                $row['ea_email'] = $ea['email'] ?? null;
                $row['ea_active'] = $ea['active'] ?? null;
                $row['ea_referent_id'] = $ea['referent_id'] ?? null;
                $rows[] = $row;
            }
            usort($rows, static fn($a, $b) => ((int) $a['id']) <=> ((int) $b['id']));
            $this->result = $rows;
            $this->rowCount = count($rows);

            return true;
        }

        if ($sql === 'select id, email from clients where id = ? and referent_id = ?') {
            $id = (int) ($this->params[0] ?? 0);
            $referentId = (int) ($this->params[1] ?? 0);
            $row = $this->pdo->clients[$id] ?? null;
            $this->result = ($row !== null && (int) $row['referent_id'] === $referentId)
                ? ['id' => (int) $row['id'], 'email' => (string) $row['email']]
                : false;

            return true;
        }

        if ($sql === 'select * from clients where id = ?') {
            $id = (int) ($this->params[0] ?? 0);
            $this->result = $this->pdo->clients[$id] ?? false;

            return true;
        }

        if ($sql === 'select active from clients where id = ?') {
            $id = (int) ($this->params[0] ?? 0);
            $row = $this->pdo->clients[$id] ?? null;
            $this->result = $row === null ? false : ['active' => (int) $row['active']];

            return true;
        }

        if ($sql === 'select active from referents where id = ?'
            || $sql === 'select active from referents where id = 1'
        ) {
            $id = $this->params !== []
                ? (int) $this->params[0]
                : 1;
            $row = $this->pdo->referents[$id] ?? null;
            $this->result = $row === null ? false : ['active' => (int) $row['active']];

            return true;
        }

        if (str_contains($sql, 'update clients')
            && str_contains($sql, 'external_client_email')
            && str_contains($sql, 'where id = ? and referent_id = ?')
        ) {
            [
                $email,
                $extClient,
                $localClient,
                $localReferent,
                $accountId,
                $maildir,
                $active,
                $id,
                $referentId,
            ] = $this->params;
            $id = (int) $id;
            $referentId = (int) $referentId;
            if (!isset($this->pdo->clients[$id])
                || (int) $this->pdo->clients[$id]['referent_id'] !== $referentId
            ) {
                $this->rowCount = 0;

                return true;
            }
            $this->pdo->clients[$id]['email'] = (string) $email;
            $this->pdo->clients[$id]['external_client_email'] = (string) $extClient;
            $this->pdo->clients[$id]['local_client_email'] = (string) $localClient;
            $this->pdo->clients[$id]['local_referent_email'] = (string) $localReferent;
            $this->pdo->clients[$id]['external_account_id'] = (int) $accountId;
            $this->pdo->clients[$id]['local_client_maildir'] = (string) $maildir;
            $this->pdo->clients[$id]['active'] = (int) $active === 1 ? 1 : 0;
            $this->rowCount = 1;

            return true;
        }

        if ($sql === 'update clients set active = ?, updated_at = now() where id = ?') {
            $active = (int) ($this->params[0] ?? 0) === 1 ? 1 : 0;
            $id = (int) ($this->params[1] ?? 0);
            if (!isset($this->pdo->clients[$id])) {
                $this->rowCount = 0;

                return true;
            }
            $this->pdo->clients[$id]['active'] = $active;
            $this->rowCount = 1;

            return true;
        }

        if ($sql === 'delete from clients where id = ? and referent_id = ?') {
            $id = (int) ($this->params[0] ?? 0);
            $referentId = (int) ($this->params[1] ?? 0);
            if (isset($this->pdo->clients[$id])
                && (int) $this->pdo->clients[$id]['referent_id'] === $referentId
            ) {
                unset($this->pdo->clients[$id]);
                $this->rowCount = 1;
            } else {
                $this->rowCount = 0;
            }

            return true;
        }

        if ($sql === 'update referents set active = 0, updated_at = now() where id = ? and active = 1') {
            $id = (int) ($this->params[0] ?? 0);
            if (isset($this->pdo->referents[$id]) && (int) $this->pdo->referents[$id]['active'] === 1) {
                $this->pdo->referents[$id]['active'] = 0;
                $this->rowCount = 1;
            } else {
                $this->rowCount = 0;
            }

            return true;
        }

        throw new RuntimeException('Unhandled SQL in FakeRelationshipCrudPdo: ' . $this->query);
    }

    public function fetch($mode = null)
    {
        if (is_array($this->result) && array_is_list($this->result)) {
            return false;
        }

        return $this->result;
    }

    public function fetchAll($mode = null): array
    {
        if (is_array($this->result) && array_is_list($this->result)) {
            return $this->result;
        }

        return [];
    }

    public function fetchColumn($column = 0)
    {
        if ($this->result === false || $this->result === null) {
            return false;
        }
        if (!is_array($this->result) || array_is_list($this->result)) {
            return false;
        }
        $values = array_values($this->result);

        return $values[(int) $column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }
}

/**
 * @return array{pdo: FakeRelationshipCrudPdo, c1: array<string,mixed>, c2: array<string,mixed>}
 */
function buildTwoRelationshipFixture(): array
{
    $c1 = [
        'id' => 101,
        'email' => 'e-c1@partner.test',
        'referent_id' => 1,
        'external_client_email' => 'e-c1@partner.test',
        'local_client_email' => 'l-c1@local.test',
        'local_referent_email' => 'l-r1@local.test',
        'external_account_id' => 11,
        'local_client_maildir' => '/var/vmail/c1/Maildir',
        'active' => 1,
    ];
    $c2 = [
        'id' => 102,
        'email' => 'e-c2@partner.test',
        'referent_id' => 1,
        'external_client_email' => 'e-c2@partner.test',
        'local_client_email' => 'l-c2@local.test',
        'local_referent_email' => 'l-r2@local.test',
        'external_account_id' => 12,
        'local_client_maildir' => '/var/vmail/c2/Maildir',
        'active' => 1,
    ];
    $pdo = new FakeRelationshipCrudPdo(
        [
            ['id' => 1, 'username' => 'R', 'active' => 1, 'local_inbox' => null, 'local_outbox' => null],
        ],
        [
            ['id' => 11, 'referent_id' => 1, 'email' => 'e-r1@partner.test', 'active' => 1],
            ['id' => 12, 'referent_id' => 1, 'email' => 'e-r2@partner.test', 'active' => 1],
        ],
        [$c1, $c2]
    );

    return ['pdo' => $pdo, 'c1' => $c1, 'c2' => $c2];
}

// --- 1) Save/update C1 must not mutate C2 ownership fields ---
$fx = buildTwoRelationshipFixture();
$pdo = $fx['pdo'];
$c2Before = relationshipOwnershipSnapshot($pdo->clients[102]);

$updated = updateClientRelationshipRow($pdo, 101, 1, [
    'external_client_email' => 'e-c1-new@partner.test',
    'local_client_email' => 'l-c1-new@local.test',
    'local_referent_email' => 'l-r1-new@local.test',
    'external_account_id' => 11,
    'local_client_maildir' => '/var/vmail/c1-new/Maildir',
    'active' => 1,
]);
assert_true($updated === 1, 'updateClientRelationshipRow updates C1');
assert_true(
    (string) $pdo->clients[101]['local_referent_email'] === 'l-r1-new@local.test',
    'C1 local_referent_email changed via helper'
);
assert_true(
    (string) $pdo->clients[101]['local_client_maildir'] === '/var/vmail/c1-new/Maildir',
    'C1 local_client_maildir changed via helper'
);
assert_true(
    relationshipOwnershipSnapshot($pdo->clients[102]) === $c2Before,
    'saving C1 does not modify C2 four-mailbox chain / account / maildir / active'
);

$fxGuard = buildTwoRelationshipFixture();
$c1GuardBefore = relationshipOwnershipSnapshot($fxGuard['pdo']->clients[101]);
$c2GuardBefore = relationshipOwnershipSnapshot($fxGuard['pdo']->clients[102]);
$zero = updateClientRelationshipRow($fxGuard['pdo'], 101, 999, [
    'external_client_email' => 'evil@partner.test',
    'local_client_email' => 'evil@local.test',
    'local_referent_email' => 'evil-ref@local.test',
    'external_account_id' => 11,
    'local_client_maildir' => '/evil/Maildir',
    'active' => 0,
]);
assert_true($zero === 0, 'update with wrong referent_id affects 0 rows');
assert_true(
    relationshipOwnershipSnapshot($fxGuard['pdo']->clients[101]) === $c1GuardBefore
        && relationshipOwnershipSnapshot($fxGuard['pdo']->clients[102]) === $c2GuardBefore,
    'mismatched referent_id leaves both relationships untouched'
);

// --- 2) Delete C1 must not delete/rewrite/deactivate C2 ---
$fxDel = buildTwoRelationshipFixture();
$c2DelBefore = relationshipOwnershipSnapshot($fxDel['pdo']->clients[102]);
$found = findClientRelationshipOwnedByReferent($fxDel['pdo'], 101, 1);
assert_true($found !== null && (int) $found['id'] === 101, 'find C1 owned by referent');
$deleted = deleteClientRelationshipRow($fxDel['pdo'], 101, 1);
assert_true($deleted === 1, 'deleteClientRelationshipRow removes C1');
assert_true(!isset($fxDel['pdo']->clients[101]), 'C1 row gone');
assert_true(isset($fxDel['pdo']->clients[102]), 'C2 row still present');
assert_true(
    relationshipOwnershipSnapshot($fxDel['pdo']->clients[102]) === $c2DelBefore,
    'deleting C1 does not mutate C2 ownership snapshot'
);

$synced = referentSyncActiveAfterRelationshipChange($fxDel['pdo'], 1);
assert_true($synced === false, 'referent stays active while C2 remains activatable');
assert_true((int) $fxDel['pdo']->referents[1]['active'] === 1, 'referent.active unchanged');
assert_true(
    relationshipOwnershipSnapshot($fxDel['pdo']->clients[102]) === $c2DelBefore,
    'activation sync after C1 delete does not mutate C2'
);

// --- 3) Activate/deactivate C1 must not mutate C2 clients.active ---
$fxToggle = buildTwoRelationshipFixture();
$c2ToggleBefore = relationshipOwnershipSnapshot($fxToggle['pdo']->clients[102]);
$newActive = toggleClientRelationshipActive($fxToggle['pdo'], 101);
assert_true($newActive === 0, 'toggle C1 active 1→0');
assert_true((int) $fxToggle['pdo']->clients[101]['active'] === 0, 'C1 deactivated');
assert_true(
    relationshipOwnershipSnapshot($fxToggle['pdo']->clients[102]) === $c2ToggleBefore,
    'deactivating C1 does not mutate C2'
);
$newActive2 = toggleClientRelationshipActive($fxToggle['pdo'], 101);
assert_true($newActive2 === 1, 'toggle C1 active 0→1');
assert_true((int) $fxToggle['pdo']->clients[101]['active'] === 1, 'C1 reactivated');
assert_true(
    relationshipOwnershipSnapshot($fxToggle['pdo']->clients[102]) === $c2ToggleBefore,
    'activating C1 does not mutate C2'
);

$fxMix = buildTwoRelationshipFixture();
updateClientRelationshipRow($fxMix['pdo'], 101, 1, [
    'external_client_email' => 'e-c1-final@partner.test',
    'local_client_email' => 'l-c1-final@local.test',
    'local_referent_email' => 'l-r1-final@local.test',
    'external_account_id' => 11,
    'local_client_maildir' => '/var/vmail/c1-final/Maildir',
    'active' => 0,
]);
toggleClientRelationshipActive($fxMix['pdo'], 102);
$snap1 = relationshipOwnershipSnapshot($fxMix['pdo']->clients[101]);
$snap2 = relationshipOwnershipSnapshot($fxMix['pdo']->clients[102]);
assert_true($snap1['active'] === 0 && $snap2['active'] === 0, 'both can be independently inactive');
assert_true(
    $snap1['local_referent_email'] === 'l-r1-final@local.test'
        && $snap2['local_referent_email'] === 'l-r2@local.test',
    'C1/C2 remain independently represented after mixed CRUD'
);
assert_true(
    $snap1['external_account_id'] === 11 && $snap2['external_account_id'] === 12,
    'external_account_id remains distinct per relationship'
);

$index = (string) file_get_contents($root . '/web/index.php');
assert_true(str_contains($index, 'updateClientRelationshipRow('), 'save UPDATE uses helper');
assert_true(str_contains($index, 'deleteClientRelationshipRow('), 'delete uses helper');
assert_true(str_contains($index, 'toggleClientRelationshipActive('), 'client toggle uses helper');

$editor = (string) file_get_contents($root . '/web/includes/relationship_editor.php');
assert_true(
    str_contains($editor, 'function updateClientRelationshipRow')
        && str_contains($editor, 'WHERE id = ? AND referent_id = ?'),
    'update helper scopes by relationship id and referent_id'
);
assert_true(
    !str_contains($editor, 'local_inbox =') && !str_contains($editor, 'local_outbox ='),
    'CRUD helpers do not write referent local_inbox/outbox'
);

echo $failures === 0
    ? "RESULT: CRUD isolation OK (fake-PDO helpers; see file header for unverified E2E)\n"
    : "RESULT: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
