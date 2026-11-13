-- Issue #35 — optional human-readable client label in panel (DAT-24).
-- Idempotent: skips ADD COLUMN if already present (MariaDB 10.x+).

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND COLUMN_NAME = 'display_name') = 0,
    'ALTER TABLE clients ADD COLUMN display_name VARCHAR(100) NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
