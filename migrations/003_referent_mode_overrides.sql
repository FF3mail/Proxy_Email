-- PROMPT-73 — per-referent routing/watch mode overrides (Option A).
-- Additive nullable ENUM columns on referents; NULL = inherit process-global env default.
-- Idempotent for re-run (MariaDB 10.x+).

USE mail_proxy;

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'referents'
       AND COLUMN_NAME = 'inbound_routing_mode') = 0,
    "ALTER TABLE referents ADD COLUMN inbound_routing_mode
        ENUM('legacy','shadow','relationship_live') NULL DEFAULT NULL",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'referents'
       AND COLUMN_NAME = 'outbound_routing_mode') = 0,
    "ALTER TABLE referents ADD COLUMN outbound_routing_mode
        ENUM('legacy','shadow','relationship_live') NULL DEFAULT NULL",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'referents'
       AND COLUMN_NAME = 'outbound_watch_mode') = 0,
    "ALTER TABLE referents ADD COLUMN outbound_watch_mode
        ENUM('referent_only','dual','relationship_only') NULL DEFAULT NULL",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
