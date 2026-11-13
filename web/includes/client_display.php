<?php
declare(strict_types=1);

/**
 * Panel-only client display name helpers (issue #35).
 */

const CLIENT_DISPLAY_NAME_MAX_CHARS = 100;

function normalizeClientDisplayName(?string $raw): ?string
{
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }

    return $s;
}

function clientDisplayNameTooLong(?string $raw): bool
{
    $s = trim((string) $raw);
    if ($s === '') {
        return false;
    }

    return mb_strlen($s) > CLIENT_DISPLAY_NAME_MAX_CHARS;
}

function relationshipClientPrimaryLabel(?string $displayName, string $email): string
{
    $dn = normalizeClientDisplayName($displayName);
    if ($dn !== null) {
        return $dn;
    }

    return $email !== '' ? $email : '—';
}

/**
 * Secondary line in lists/journal when a display name is set; otherwise null.
 */
function relationshipClientSecondaryEmail(?string $displayName, string $email): ?string
{
    $dn = normalizeClientDisplayName($displayName);
    if ($dn === null || $email === '') {
        return null;
    }

    return $email;
}

/** Single-line selector label: "Name (email)" or email alone. */
function relationshipClientSelectorLabel(?string $displayName, string $email): string
{
    $dn = normalizeClientDisplayName($displayName);
    if ($dn !== null && $email !== '') {
        return $dn . ' (' . $email . ')';
    }

    return $email;
}

/**
 * @param list<array<string,mixed>> $rows Journal rows with client_name (stored email).
 * @return list<array<string,mixed>>
 */
function enrichJournalRowsClientDisplay(array $rows, object $pdo): array
{
    if ($rows === []) {
        return [];
    }

    $emails = [];
    foreach ($rows as $row) {
        $e = trim((string) ($row['client_name'] ?? ''));
        if ($e !== '') {
            $emails[$e] = true;
        }
    }

    $nameByEmail = [];
    $ambiguousEmails = [];

    if ($emails !== []) {
        $emailList = array_keys($emails);
        $placeholders = implode(',', array_fill(0, count($emailList), '?'));
        $params = array_merge($emailList, $emailList);
        try {
            $stmt = $pdo->prepare(
                "SELECT id, external_client_email, local_client_email, display_name
                 FROM clients
                 WHERE external_client_email IN ($placeholders)
                    OR local_client_email IN ($placeholders)"
            );
            $stmt->execute($params);
            $clientRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $clientRows = [];
        }

        foreach ($clientRows as $cr) {
            $display = normalizeClientDisplayName(
                isset($cr['display_name']) ? (string) $cr['display_name'] : null
            );
            $clientId = (int) ($cr['id'] ?? 0);
            foreach (['external_client_email', 'local_client_email'] as $col) {
                $em = trim((string) ($cr[$col] ?? ''));
                if ($em === '') {
                    continue;
                }
                if (!isset($nameByEmail[$em])) {
                    $nameByEmail[$em] = ['display' => $display, 'client_ids' => []];
                } else {
                    $nameByEmail[$em]['client_ids'][$clientId] = true;
                    if (count($nameByEmail[$em]['client_ids']) > 1) {
                        $ambiguousEmails[$em] = true;
                    }
                    $prev = $nameByEmail[$em]['display'];
                    if ($prev !== $display) {
                        $ambiguousEmails[$em] = true;
                    }
                    continue;
                }
                $nameByEmail[$em]['client_ids'][$clientId] = true;
            }
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $email = trim((string) ($row['client_name'] ?? ''));
        $resolvedDisplay = null;
        if ($email !== '' && isset($nameByEmail[$email]) && !isset($ambiguousEmails[$email])) {
            $ids = $nameByEmail[$email]['client_ids'];
            if (count($ids) === 1) {
                $resolvedDisplay = $nameByEmail[$email]['display'];
            }
        }
        $row['client_display_primary'] = relationshipClientPrimaryLabel($resolvedDisplay, $email);
        $row['client_display_secondary'] = relationshipClientSecondaryEmail($resolvedDisplay, $email);
        $row['client_display_substitute'] = $row['client_display_primary'];
        $out[] = $row;
    }

    return $out;
}

/**
 * Render primary + optional secondary email for HTML tables.
 */
function renderClientDisplayCell(?string $displayName, string $email): void
{
    $primary = relationshipClientPrimaryLabel($displayName, $email);
    $secondary = relationshipClientSecondaryEmail($displayName, $email);
    echo '<div>', h($primary), '</div>';
    if ($secondary !== null) {
        echo '<div class="pm-help pm-mono text-xs">', h($secondary), '</div>';
    }
}
