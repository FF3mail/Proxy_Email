<?php
declare(strict_types=1);

/**
 * Referent activation rules (PROMPT-83 / 2026-10-07 locked decisions).
 *
 * A referent may be active only when it has at least one relationship that is
 * complete (PROMPT-53 §9 field set) and active, with an active external account.
 */

/**
 * Display helper for nullable referent local_inbox in lists/cards.
 */
function referentDisplayLocalInbox(?string $localInbox): string
{
    $trimmed = trim((string) $localInbox);
    return $trimmed === '' ? '—' : $trimmed;
}

/**
 * Whether a clients row is complete enough to allow referent.active=1.
 *
 * @param array<string, mixed> $row Row from fetchRelationshipsForReferent (incl. ea_active).
 */
function relationshipIsActivatableComplete(array $row): bool
{
    if (relationshipIsLegacyOnly($row)) {
        return false;
    }
    if (relationshipMissingFields($row) !== []) {
        return false;
    }
    if ((int) ($row['active'] ?? 0) !== 1) {
        return false;
    }
    if (array_key_exists('ea_active', $row) && (int) $row['ea_active'] !== 1) {
        return false;
    }

    return true;
}

function referentHasActivatableRelationship(PDO $pdo, int $referentId): bool
{
    if ($referentId <= 0) {
        return false;
    }
    foreach (fetchRelationshipsForReferent($pdo, $referentId) as $row) {
        if (relationshipIsActivatableComplete($row)) {
            return true;
        }
    }

    return false;
}

/**
 * Apply activation gate on save/toggle. Returns active flag to persist (0 or 1).
 *
 * @return array{active: int, blocked: bool}
 */
function referentResolveActiveOnSave(PDO $pdo, int $referentId, int $requestedActive): array
{
    if ($requestedActive !== 1) {
        return ['active' => 0, 'blocked' => false];
    }
    if ($referentId > 0 && referentHasActivatableRelationship($pdo, $referentId)) {
        return ['active' => 1, 'blocked' => false];
    }

    return ['active' => 0, 'blocked' => true];
}

/**
 * After relationship/account changes: deactivate referent when no activatable relationship remains.
 *
 * @return bool true when referent was auto-deactivated
 */
function referentSyncActiveAfterRelationshipChange(PDO $pdo, int $referentId): bool
{
    if ($referentId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT active FROM referents WHERE id = ?');
    $stmt->execute([$referentId]);
    $current = $stmt->fetchColumn();
    if ($current === false || (int) $current !== 1) {
        return false;
    }

    if (referentHasActivatableRelationship($pdo, $referentId)) {
        return false;
    }

    $upd = $pdo->prepare(
        'UPDATE referents SET active = 0, updated_at = NOW() WHERE id = ? AND active = 1'
    );
    $upd->execute([$referentId]);
    if ($upd->rowCount() > 0) {
        writeLog(
            "Referent auto-deactivated: ID {$referentId} (no complete active relationship)"
        );

        return true;
    }

    return false;
}
