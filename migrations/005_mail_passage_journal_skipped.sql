-- PROMPT-79.2d — journal event_type=skipped + detail column.
-- Does not modify schema.sql or 003/004 create statements.

USE mail_proxy;

ALTER TABLE mail_passage_journal
    MODIFY COLUMN event_type ENUM('delivered', 'disposed', 'skipped') NOT NULL;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'mail_passage_journal'
      AND COLUMN_NAME = 'detail'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE mail_passage_journal ADD COLUMN detail VARCHAR(1024) NULL COMMENT ''Filenames / expected-actual subject / counts (app-truncated)'' AFTER source_message_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;