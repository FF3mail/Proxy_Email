-- DAT-11: clients.referent_id → referents ON DELETE RESTRICT (idempotent upgrade).
-- Does not change external_accounts.referent_id (DAT-4 CASCADE).

SET @db := DATABASE();

SET @delete_rule := (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS rc
    INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
        ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
        AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
        AND kcu.TABLE_NAME = rc.TABLE_NAME
    WHERE rc.CONSTRAINT_SCHEMA = @db
      AND rc.TABLE_NAME = 'clients'
      AND rc.REFERENCED_TABLE_NAME = 'referents'
      AND kcu.COLUMN_NAME = 'referent_id'
    LIMIT 1
);

SET @fk_name := (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE kcu
    WHERE kcu.TABLE_SCHEMA = @db
      AND kcu.TABLE_NAME = 'clients'
      AND kcu.COLUMN_NAME = 'referent_id'
      AND kcu.REFERENCED_TABLE_NAME = 'referents'
    LIMIT 1
);

SET @sql := IF(
    @delete_rule = 'RESTRICT',
    'SELECT 1',
    IF(
        @fk_name IS NOT NULL,
        CONCAT('ALTER TABLE clients DROP FOREIGN KEY `', REPLACE(@fk_name, '`', ''), '`'),
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_restrict := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS rc
    INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
        ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
        AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
        AND kcu.TABLE_NAME = rc.TABLE_NAME
    WHERE rc.CONSTRAINT_SCHEMA = @db
      AND rc.TABLE_NAME = 'clients'
      AND rc.REFERENCED_TABLE_NAME = 'referents'
      AND kcu.COLUMN_NAME = 'referent_id'
      AND rc.DELETE_RULE = 'RESTRICT'
);

SET @sql := IF(
    @has_restrict = 0,
    'ALTER TABLE clients ADD CONSTRAINT fk_clients_referent
        FOREIGN KEY (referent_id) REFERENCES referents(id)
        ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
