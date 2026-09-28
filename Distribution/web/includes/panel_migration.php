<?php
declare(strict_types=1);

/**
 * Panel auth schema migration and startup validation (PROMPT 25).
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS only — no DROP, TRUNCATE, or ALTER
 * that could destroy data. Safe to call on every web request (guarded).
 */

const PANEL_ADMINS_DDL = <<<'SQL'
CREATE TABLE IF NOT EXISTS panel_admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('master','admin') NOT NULL DEFAULT 'admin',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_panel_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

/**
 * Bootstrap migration + startup validation (once per request).
 */
function bootstrapPanelAuth(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensurePanelAdminsSchema();
    validatePanelAuthStartup();
}

function panelAdminsTableExists(): bool
{
    try {
        $stmt = getPdo()->query("SHOW TABLES LIKE 'panel_admins'");
        if ($stmt === false) {
            return false;
        }
        return $stmt->fetch() !== false;
    } catch (Throwable) {
        return false;
    }
}

function panelActiveMasterExists(): bool
{
    if (!panelAdminsTableExists()) {
        return false;
    }

    try {
        $count = getPdo()->query(
            "SELECT COUNT(*) FROM panel_admins WHERE role = 'master' AND active = 1"
        )->fetchColumn();
        return (int)$count > 0;
    } catch (Throwable) {
        return false;
    }
}

function panelInactiveMasterExists(): bool
{
    if (!panelAdminsTableExists()) {
        return false;
    }

    try {
        $count = getPdo()->query(
            "SELECT COUNT(*) FROM panel_admins WHERE role = 'master' AND active = 0"
        )->fetchColumn();
        return (int)$count > 0;
    } catch (Throwable) {
        return false;
    }
}

/**
 * True when login may be attempted (table present and at least one active master).
 */
function panelAuthLoginAllowed(): bool
{
    return panelAdminsTableExists() && panelActiveMasterExists();
}

/**
 * Idempotent schema ensure — preserves existing rows when table already exists.
 */
function ensurePanelAdminsSchema(): void
{
    static $schemaDone = false;
    if ($schemaDone) {
        return;
    }
    $schemaDone = true;

    if (panelAdminsTableExists()) {
        return;
    }

    try {
        getPdo()->exec(PANEL_ADMINS_DDL);
        writeLog('Panel auth migration: created panel_admins table (idempotent CREATE IF NOT EXISTS)');
    } catch (Throwable $e) {
        writeLog(
            'Panel auth migration failed: could not create panel_admins — '
            . $e->getMessage()
        );
    }
}

function validatePanelAuthStartup(): void
{
    if (!panelAdminsTableExists()) {
        writeLog(
            'Panel auth startup: panel_admins table missing after migration attempt — '
            . 'run installer Database phase or apply schema.sql migration'
        );
        return;
    }

    if (panelActiveMasterExists()) {
        return;
    }

    if (panelInactiveMasterExists()) {
        writeLog(
            'Panel auth startup: master account exists but active=0 — '
            . 'reactivate via SQL (UPDATE panel_admins SET active=1 WHERE role=\'master\' LIMIT 1) '
            . 'or run installer interactively'
        );
        return;
    }

    writeLog(
        'Panel auth startup: no active master operator — '
        . 'seed master via interactive installer (sudo ./delta-transit-install.sh); '
        . 'non-interactive runs abort without auto-generating credentials'
    );
}
