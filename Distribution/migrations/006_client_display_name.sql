-- PROMPT UX: optional human-readable client name on relationships.
-- Idempotent: skip if column already exists.

SET @dbname = DATABASE();
SET @tablename = 'clients';
SET @columnname = 'display_name';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE clients ADD COLUMN display_name VARCHAR(255) NULL AFTER email'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
