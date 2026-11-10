#!/usr/bin/env php
<?php
/**
 * Issue #161 — DAT-11 referent delete FK violation mapping (offline).
 */
declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$webRoot = getenv('PANEL_WEB_ROOT') ?: ($repoRoot . '/web');
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

require_once $webRoot . '/includes/referent_delete.php';

$fkDelete = new PDOException('Cannot delete or update a parent row', 23000);
$fkDelete->errorInfo = ['23000', 1451, 'Cannot delete or update a parent row: a foreign key constraint fails'];

assert_true(
    referentDeleteBlockedByRelationships($fkDelete),
    'MariaDB 1451 + SQLSTATE 23000 is relationship block'
);

$other = new PDOException('Duplicate', 23000);
$other->errorInfo = ['23000', 1062, 'Duplicate entry'];

assert_true(
    !referentDeleteBlockedByRelationships($other),
    '1062 uniqueness is not relationship block'
);

$generic = new PDOException('fail', 0);
$generic->errorInfo = [null, 0, null];

assert_true(
    !referentDeleteBlockedByRelationships($generic),
    'non-integrity errors are not relationship block'
);

require_once $webRoot . '/includes/i18n.php';
if (function_exists('initPanelI18n')) {
    initPanelI18n();
}

foreach (['ru', 'en'] as $locale) {
    setPanelLang($locale);
    $msg = __('referent.error.delete_has_relationships');
    assert_true($msg !== 'referent.error.delete_has_relationships', "i18n {$locale} delete_has_relationships");
    assert_true(
        stripos($msg, 'SQL') === false && stripos($msg, 'PDO') === false,
        "i18n {$locale} message has no SQL jargon"
    );
}

exit($failures > 0 ? 1 : 0);
