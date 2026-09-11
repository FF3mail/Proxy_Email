<?php
declare(strict_types=1);

/**
 * ClientRelationship panel helpers (PROMPT-56).
 *
 * Validity checks mirror relationship_lookup.py _VALID_RELATIONSHIP_WHERE
 * (PROMPT-53 §9) for the relationship/account fields the panel controls.
 * Referent.active is shown in context but completeness of a row is about
 * the relationship's own four-address + account + maildir fields.
 */

/**
 * Normalize email the same way as RelationshipLookup / maildir resolver.
 */
function normalizeRelationshipEmail(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Canonical Maildir path for panel save + collision checks (PROMPT-70).
 * Mirrors maildir_resolver path hygiene: trim, collapse slashes, no trailing slash.
 */
function normalizeRelationshipMaildirPath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $path = (string)preg_replace('#/+#', '/', $path);

    return rtrim($path, '/');
}

/**
 * @param array<string, mixed> $row clients row (+ optional ea_active, ea_email, ea_referent_id)
 * @return list<string> missing field keys (empty ⇒ complete for §9 relationship fields)
 */
function relationshipMissingFields(array $row): array
{
    $missing = [];

    $extClient = trim((string)($row['external_client_email'] ?? ''));
    $localClient = trim((string)($row['local_client_email'] ?? ''));
    $localReferent = trim((string)($row['local_referent_email'] ?? ''));
    $maildir = trim((string)($row['local_client_maildir'] ?? ''));
    $accountId = $row['external_account_id'] ?? null;

    if ($extClient === '') {
        $missing[] = 'external_client_email';
    }
    if ($localClient === '') {
        $missing[] = 'local_client_email';
    }
    if ($localReferent === '') {
        $missing[] = 'local_referent_email';
    }
    if ($accountId === null || $accountId === '' || (int)$accountId <= 0) {
        $missing[] = 'external_account_id';
    }
    if ($maildir === '') {
        $missing[] = 'local_client_maildir';
    }

    // Account must be active and owned by the same referent when join data present.
    if (array_key_exists('ea_active', $row) && (int)$row['ea_active'] !== 1) {
        $missing[] = 'external_account_active';
    }
    if (
        array_key_exists('ea_referent_id', $row)
        && (int)$row['ea_referent_id'] > 0
        && (int)$row['referent_id'] !== (int)$row['ea_referent_id']
    ) {
        $missing[] = 'external_account_referent_mismatch';
    }

    return $missing;
}

/**
 * True when the row has any of the four-address / account / maildir fields set.
 */
function relationshipHasFourAddressData(array $row): bool
{
    foreach (
        [
            'external_client_email',
            'local_client_email',
            'local_referent_email',
            'local_client_maildir',
        ] as $field
    ) {
        if (trim((string)($row[$field] ?? '')) !== '') {
            return true;
        }
    }
    if (!empty($row['external_account_id'])) {
        return true;
    }
    return false;
}

/**
 * Legacy-only: no four-address columns filled (PROMPT-56 Task 1).
 */
function relationshipIsLegacyOnly(array $row): bool
{
    return !relationshipHasFourAddressData($row)
        && trim((string)($row['email'] ?? '')) !== '';
}

/**
 * Prefill value for external_client_email on the relationship form (GET-only).
 * For legacy-only rows, carry forward clients.email (PROMPT-57 Task 2).
 * Does not write to the database.
 */
function relationshipExternalClientFormValue(array $row): string
{
    $ext = trim((string)($row['external_client_email'] ?? ''));
    if ($ext !== '') {
        return normalizeRelationshipEmail($ext);
    }
    if (relationshipIsLegacyOnly($row)) {
        return normalizeRelationshipEmail((string)($row['email'] ?? ''));
    }
    return '';
}

/**
 * Load all clients joined to referents; filter with relationshipIsLegacyOnly().
 *
 * @return array{legacy: list<array<string, mixed>>, total: int, legacy_count: int}
 */
function fetchLegacyRelationshipBacklog(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT c.*,
                r.username AS referent_username,
                r.active AS referent_active,
                ea.email AS ea_email,
                ea.active AS ea_active,
                ea.referent_id AS ea_referent_id
         FROM clients c
         INNER JOIN referents r ON r.id = c.referent_id
         LEFT JOIN external_accounts ea ON ea.id = c.external_account_id
         ORDER BY r.username, c.id'
    );
    $all = $stmt->fetchAll() ?: [];
    $legacy = [];
    foreach ($all as $row) {
        if (relationshipIsLegacyOnly($row)) {
            $legacy[] = $row;
        }
    }
    return [
        'legacy' => $legacy,
        'total' => count($all),
        'legacy_count' => count($legacy),
    ];
}

/**
 * Human status label aligned with _VALID_RELATIONSHIP_WHERE field checks.
 *
 * @return array{code: string, label: string}
 */
function relationshipStatusLabel(array $row): array
{
    if (relationshipIsLegacyOnly($row)) {
        return [
            'code' => 'legacy',
            'label' => __('relationship.status_legacy'),
        ];
    }

    $missing = relationshipMissingFields($row);
    if ($missing === []) {
        if ((int)($row['active'] ?? 0) !== 1) {
            return [
                'code' => 'inactive',
                'label' => __('relationship.status_inactive_complete'),
            ];
        }
        return [
            'code' => 'complete',
            'label' => __('relationship.status_complete'),
        ];
    }

    $labels = [];
    foreach ($missing as $key) {
        $labels[] = __('relationship.field.' . $key);
    }

    return [
        'code' => 'incomplete',
        'label' => __('relationship.status_incomplete', [
            'fields' => implode(', ', $labels),
        ]),
    ];
}

/**
 * Read-only check: active physical iRedMail mailbox exists (PROMPT-55 / PROMPT-56 Task 3).
 * Reuses vmail-lookup.conf via getVmailLookupPdo() — same path as maildir_resolver.php.
 */
function activePhysicalMailboxExists(string $email): bool
{
    $email = normalizeRelationshipEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);

    $pdo = getVmailLookupPdo();
    $stmt = $pdo->prepare(
        'SELECT m.username
         FROM mailbox m
         INNER JOIN domain d ON d.domain = m.domain AND d.active = 1
         WHERE m.active = 1
           AND m.enabledeliver = 1
           AND (
                m.username = ?
                OR (m.username = ? AND m.domain = ?)
           )
         LIMIT 1'
    );
    $stmt->execute([$email, $local, $domain]);
    return (bool)$stmt->fetch();
}

/**
 * @return list<array<string, mixed>>
 */
function fetchRelationshipsForReferent(PDO $pdo, int $referentId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.*,
                ea.email AS ea_email,
                ea.active AS ea_active,
                ea.referent_id AS ea_referent_id
         FROM clients c
         LEFT JOIN external_accounts ea ON ea.id = c.external_account_id
         WHERE c.referent_id = ?
         ORDER BY c.id'
    );
    $stmt->execute([$referentId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * External accounts for dropdown: active accounts of this referent.
 * Already-linked to another relationship are included but flagged.
 *
 * @return list<array<string, mixed>>
 */
function fetchExternalAccountsForRelationshipForm(
    PDO $pdo,
    int $referentId,
    ?int $currentRelationshipId
): array {
    $stmt = $pdo->prepare(
        'SELECT ea.id, ea.email, ea.active,
                c.id AS linked_client_id,
                c.email AS linked_legacy_email,
                c.external_client_email AS linked_external_client
         FROM external_accounts ea
         LEFT JOIN clients c
                ON c.external_account_id = ea.id
               AND (? IS NULL OR c.id <> ?)
         WHERE ea.referent_id = ?
         ORDER BY ea.id'
    );
    $stmt->execute([
        $currentRelationshipId,
        $currentRelationshipId ?? 0,
        $referentId,
    ]);
    return $stmt->fetchAll() ?: [];
}

/**
 * @return array{
 *   external_client_email: string,
 *   local_client_email: string,
 *   local_referent_email: string,
 *   external_account_id: int|null,
 *   local_client_maildir: string,
 *   active: int,
 *   any_filled: bool,
 *   all_filled: bool
 * }
 */
function parseRelationshipFormPost(array $post): array
{
    $externalClient = normalizeRelationshipEmail((string)($post['external_client_email'] ?? ''));
    $localClient = normalizeRelationshipEmail((string)($post['local_client_email'] ?? ''));
    $localReferent = normalizeRelationshipEmail((string)($post['local_referent_email'] ?? ''));
    $maildir = normalizeRelationshipMaildirPath((string)($post['local_client_maildir'] ?? ''));
    $accountRaw = trim((string)($post['external_account_id'] ?? ''));
    $accountId = $accountRaw === '' ? null : (int)$accountRaw;
    if ($accountId !== null && $accountId <= 0) {
        $accountId = null;
    }
    $active = isset($post['active']) ? 1 : 0;

    $parts = [$externalClient, $localClient, $localReferent, $maildir];
    $filledCount = 0;
    foreach ($parts as $p) {
        if ($p !== '') {
            $filledCount++;
        }
    }
    if ($accountId !== null) {
        $filledCount++;
    }
    $requiredSlots = 5; // four addresses/maildir + account
    $anyFilled = $filledCount > 0;
    $allFilled = $externalClient !== ''
        && $localClient !== ''
        && $localReferent !== ''
        && $maildir !== ''
        && $accountId !== null;

    return [
        'external_client_email' => $externalClient,
        'local_client_email' => $localClient,
        'local_referent_email' => $localReferent,
        'external_account_id' => $accountId,
        'local_client_maildir' => $maildir,
        'active' => $active,
        'any_filled' => $anyFilled,
        'all_filled' => $allFilled,
    ];
}

/**
 * Application-layer UNIQUE collision check before INSERT/UPDATE.
 *
 * @return string|null operator-facing error message
 */
function findRelationshipUniqueCollision(
    PDO $pdo,
    int $referentId,
    ?int $excludeId,
    array $data
): ?string {
    $checks = [
        'external_client_email' => $data['external_client_email'],
        'local_client_email' => $data['local_client_email'],
        'local_referent_email' => $data['local_referent_email'],
    ];

    foreach ($checks as $column => $value) {
        $sql = "SELECT id, referent_id, email, external_client_email
                FROM clients
                WHERE {$column} = ?
                  AND (? IS NULL OR id <> ?)
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$value, $excludeId, $excludeId ?? 0]);
        $hit = $stmt->fetch();
        if ($hit) {
            $label = __('relationship.field.' . $column);
            return __('relationship.error.unique', [
                'field' => $label,
                'value' => $value,
                'id' => (string)(int)$hit['id'],
                'referent_id' => (string)(int)$hit['referent_id'],
            ]);
        }
    }

    $accountId = (int)$data['external_account_id'];
    $stmt = $pdo->prepare(
        'SELECT id, referent_id, email, external_client_email
         FROM clients
         WHERE external_account_id = ?
           AND (? IS NULL OR id <> ?)
         LIMIT 1'
    );
    $stmt->execute([$accountId, $excludeId, $excludeId ?? 0]);
    $hit = $stmt->fetch();
    if ($hit) {
        return __('relationship.error.unique_account', [
            'account_id' => (string)$accountId,
            'id' => (string)(int)$hit['id'],
            'referent_id' => (string)(int)$hit['referent_id'],
        ]);
    }

    // Legacy email UNIQUE — we store external_client as clients.email
    $stmt = $pdo->prepare(
        'SELECT id, referent_id, email
         FROM clients
         WHERE email = ?
           AND (? IS NULL OR id <> ?)
         LIMIT 1'
    );
    $stmt->execute([$data['external_client_email'], $excludeId, $excludeId ?? 0]);
    $hit = $stmt->fetch();
    if ($hit) {
        return __('relationship.error.unique', [
            'field' => __('relationship.field.external_client_email') . ' / email',
            'value' => $data['external_client_email'],
            'id' => (string)(int)$hit['id'],
            'referent_id' => (string)(int)$hit['referent_id'],
        ]);
    }

    return findRelationshipMaildirPathCollision(
        $pdo,
        $referentId,
        $excludeId,
        (string)$data['local_client_maildir']
    );
}

/**
 * Pure maildir path collision evaluation (PROMPT-70).
 *
 * @param list<array{id: int|string, referent_id: int|string, local_client_maildir: string}> $clientRows
 * @param list<array{id: int|string, local_outbox: string}> $referentRows
 * @return string|null operator-facing error message
 */
function evaluateRelationshipMaildirPathCollision(
    int $referentId,
    ?int $excludeId,
    string $maildir,
    array $clientRows,
    array $referentRows
): ?string {
    $normalized = normalizeRelationshipMaildirPath($maildir);
    if ($normalized === '') {
        return null;
    }

    foreach ($clientRows as $row) {
        $rowId = (int)$row['id'];
        if ($excludeId !== null && $rowId === $excludeId) {
            continue;
        }
        if (
            normalizeRelationshipMaildirPath((string)$row['local_client_maildir']) === $normalized
        ) {
            return __('relationship.error.maildir_path_duplicate', [
                'path' => $maildir,
                'id' => (string)$rowId,
                'referent_id' => (string)(int)$row['referent_id'],
            ]);
        }
    }

    foreach ($referentRows as $ref) {
        $refId = (int)$ref['id'];
        $outboxNorm = normalizeRelationshipMaildirPath((string)$ref['local_outbox']);
        if ($outboxNorm === '' || $outboxNorm !== $normalized) {
            continue;
        }

        if ($refId !== $referentId) {
            return __('relationship.error.maildir_referent_outbox_collision', [
                'path' => $maildir,
                'referent_id' => (string)$refId,
            ]);
        }

        $otherClaimants = 0;
        foreach ($clientRows as $row) {
            $rowId = (int)$row['id'];
            if ($excludeId !== null && $rowId === $excludeId) {
                continue;
            }
            if ((int)$row['referent_id'] !== $referentId) {
                continue;
            }
            if (
                normalizeRelationshipMaildirPath((string)$row['local_client_maildir']) === $normalized
            ) {
                $otherClaimants++;
            }
        }
        if ($otherClaimants > 0) {
            return __('relationship.error.maildir_referent_outbox_symmetric', [
                'path' => $maildir,
                'referent_id' => (string)$referentId,
            ]);
        }
    }

    return null;
}

/**
 * Application-layer local_client_maildir collision checks (PROMPT-70).
 *
 * @return string|null operator-facing error message
 */
function findRelationshipMaildirPathCollision(
    PDO $pdo,
    int $referentId,
    ?int $excludeId,
    string $maildir
): ?string {
    $stmt = $pdo->query(
        'SELECT id, referent_id, local_client_maildir
         FROM clients
         WHERE local_client_maildir IS NOT NULL
           AND TRIM(local_client_maildir) <> \'\''
    );
    $clientRows = $stmt->fetchAll() ?: [];

    $stmt = $pdo->query(
        'SELECT id, local_outbox
         FROM referents
         WHERE local_outbox IS NOT NULL
           AND TRIM(local_outbox) <> \'\''
    );
    $referentRows = $stmt->fetchAll() ?: [];

    return evaluateRelationshipMaildirPathCollision(
        $referentId,
        $excludeId,
        $maildir,
        $clientRows,
        $referentRows
    );
}

/**
 * Validate external account belongs to referent and is active.
 *
 * @return string|null error message
 */
function validateRelationshipExternalAccount(
    PDO $pdo,
    int $referentId,
    int $accountId
): ?string {
    $stmt = $pdo->prepare(
        'SELECT id, referent_id, email, active
         FROM external_accounts
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$accountId]);
    $acc = $stmt->fetch();
    if (!$acc) {
        return __('relationship.error.account_missing');
    }
    if ((int)$acc['referent_id'] !== $referentId) {
        return __('relationship.error.account_wrong_referent');
    }
    if ((int)$acc['active'] !== 1) {
        return __('relationship.error.account_inactive', [
            'email' => (string)$acc['email'],
        ]);
    }
    return null;
}

/**
 * Render relationship list HTML for a referent (edit or view).
 */
function renderRelationshipListSection(int $referentId, string $context = 'form'): void
{
    $pdo = getPdo();
    $rows = fetchRelationshipsForReferent($pdo, $referentId);
    ?>
    <div class="bg-white rounded shadow p-6 mt-6" id="relationship-list">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h3 class="text-lg font-semibold"><?= h(__('relationship.list_title')) ?></h3>
            <a href="index.php?action=relationship_form&referent_id=<?= $referentId ?>"
               class="bg-blue-600 text-white px-4 py-2 rounded text-sm">
                <?= h(__('relationship.add')) ?>
            </a>
        </div>
        <p class="text-sm text-slate-600 mb-4"><?= h(__('relationship.list_hint')) ?></p>

        <?php if ($rows === []): ?>
            <div class="border border-dashed border-slate-300 rounded p-6 text-center text-slate-600"
                 data-testid="relationship-empty">
                <?= h(__('relationship.empty')) ?>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100">
                    <tr>
                        <th class="px-3 py-2 text-left"><?= h(__('relationship.col_external_client')) ?></th>
                        <th class="px-3 py-2 text-left"><?= h(__('relationship.col_local_client')) ?></th>
                        <th class="px-3 py-2 text-left"><?= h(__('relationship.col_local_referent')) ?></th>
                        <th class="px-3 py-2 text-left"><?= h(__('relationship.col_external_account')) ?></th>
                        <th class="px-3 py-2 text-left"><?= h(__('relationship.col_active')) ?></th>
                        <th class="px-3 py-2 text-left"><?= h(__('relationship.col_status')) ?></th>
                        <th class="px-3 py-2 text-left"><?= h(__('common.actions')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $status = relationshipStatusLabel($row);
                        $legacy = relationshipIsLegacyOnly($row);
                        $statusClass = match ($status['code']) {
                            'complete' => 'text-green-700',
                            'incomplete' => 'text-amber-700',
                            'legacy' => 'text-slate-600',
                            default => 'text-slate-700',
                        };
                        ?>
                        <tr class="border-t" data-relationship-id="<?= (int)$row['id'] ?>"
                            data-status="<?= h($status['code']) ?>">
                            <td class="px-3 py-2 font-mono">
                                <?php if ($legacy): ?>
                                    <span class="text-slate-500"><?= h(__('relationship.legacy_email')) ?>:</span>
                                    <?= h((string)$row['email']) ?>
                                <?php else: ?>
                                    <?= h((string)($row['external_client_email'] ?: '—')) ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2 font-mono">
                                <?= $legacy ? '—' : h((string)($row['local_client_email'] ?: '—')) ?>
                            </td>
                            <td class="px-3 py-2 font-mono">
                                <?= $legacy ? '—' : h((string)($row['local_referent_email'] ?: '—')) ?>
                            </td>
                            <td class="px-3 py-2 font-mono">
                                <?= h((string)($row['ea_email'] ?: '—')) ?>
                            </td>
                            <td class="px-3 py-2">
                                <?= (int)$row['active'] === 1
                                    ? h(__('relationship.active_yes'))
                                    : h(__('relationship.active_no')) ?>
                            </td>
                            <td class="px-3 py-2 <?= $statusClass ?>" data-testid="relationship-status">
                                <?= h($status['label']) ?>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <a class="bg-amber-500 text-white px-2 py-1 rounded text-xs"
                                       href="index.php?action=relationship_form&referent_id=<?= $referentId ?>&id=<?= (int)$row['id'] ?>">
                                        <?= h(__('common.edit')) ?>
                                    </a>
                                    <?php
                                    renderEntityToggleButton(
                                        'client',
                                        (int)$row['id'],
                                        (int)$row['active'],
                                        __('relationship.toggle_label'),
                                        $context === 'view' ? 'referent_view' : 'referent_form',
                                        $referentId
                                    );
                                    ?>
                                    <form method="post" action="index.php?action=relationship_delete" class="inline"
                                          onsubmit="return confirm('<?= h(__('relationship.delete_confirm')) ?>');">
                                        <input type="hidden" name="action" value="relationship_delete">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="referent_id" value="<?= $referentId ?>">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                                        <button type="submit" class="bg-red-600 text-white px-2 py-1 rounded text-xs">
                                            <?= h(__('common.delete')) ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Cross-referent legacy backlog (PROMPT-57). Discovery only — Migrate is GET to relationship_form.
 */
function renderLegacyRelationshipBackfill(): void
{
    $pdo = getPdo();
    $backlog = fetchLegacyRelationshipBacklog($pdo);
    $legacy = $backlog['legacy'];
    $legacyCount = $backlog['legacy_count'];
    $total = $backlog['total'];

    renderHeader(__('backfill.title'));
    ?>
    <h2 class="text-2xl font-bold mb-2"><?= h(__('backfill.title')) ?></h2>
    <p class="text-slate-600 mb-2"><?= h(__('backfill.hint')) ?></p>
    <p class="mb-6 text-sm font-medium" data-testid="backfill-count">
        <?= h(__('backfill.count', [
            'legacy' => (string)$legacyCount,
            'total' => (string)$total,
        ])) ?>
    </p>

    <?php if ($legacy === []): ?>
        <div class="bg-white rounded shadow p-8 text-center text-slate-600" data-testid="backfill-empty">
            <?= h(__('backfill.empty')) ?>
        </div>
    <?php else: ?>
        <div class="bg-white rounded shadow overflow-x-auto" data-testid="backfill-table">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100">
                <tr>
                    <th class="px-4 py-2 text-left"><?= h(__('backfill.col_referent')) ?></th>
                    <th class="px-4 py-2 text-left"><?= h(__('backfill.col_legacy_email')) ?></th>
                    <th class="px-4 py-2 text-left"><?= h(__('backfill.col_active')) ?></th>
                    <th class="px-4 py-2 text-left"><?= h(__('common.actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($legacy as $row): ?>
                    <tr class="border-t" data-legacy-client-id="<?= (int)$row['id'] ?>">
                        <td class="px-4 py-2">
                            <?= h((string)$row['referent_username']) ?>
                            <div class="text-xs text-slate-500">referent_id=<?= (int)$row['referent_id'] ?></div>
                        </td>
                        <td class="px-4 py-2 font-mono"><?= h((string)$row['email']) ?></td>
                        <td class="px-4 py-2">
                            <?= (int)$row['active'] === 1
                                ? h(__('relationship.active_yes'))
                                : h(__('relationship.active_no')) ?>
                        </td>
                        <td class="px-4 py-2">
                            <a class="bg-blue-600 text-white px-3 py-1 rounded text-sm"
                               href="index.php?action=relationship_form&referent_id=<?= (int)$row['referent_id'] ?>&id=<?= (int)$row['id'] ?>&from=backfill"
                               data-testid="backfill-migrate">
                                <?= h(__('backfill.migrate')) ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif;
    renderFooter();
}
