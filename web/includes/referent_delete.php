<?php
declare(strict_types=1);

/**
 * DAT-11 — referent delete blocked when client relationships still exist (FK RESTRICT).
 */
function referentDeleteBlockedByRelationships(PDOException $e): bool
{
    $sqlState = $e->errorInfo[0] ?? '';
    if ($sqlState !== '23000') {
        return false;
    }
    $driverCode = (int)($e->errorInfo[1] ?? 0);

    return $driverCode === 1451 || $driverCode === 1217;
}
