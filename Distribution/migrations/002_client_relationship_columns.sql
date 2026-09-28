-- PROMPT-54 / PROMPT-53 §19 — additive ClientRelationship columns on `clients`.
-- Idempotent for re-run: skips ADD COLUMN / ADD KEY if already present (MariaDB 10.x+).
-- Does NOT drop or rename legacy columns (clients.email, referents.local_inbox/outbox).

USE mail_proxy;

-- Columns (NULLable during backfill)
SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND COLUMN_NAME = 'external_client_email') = 0,
    'ALTER TABLE clients ADD COLUMN external_client_email VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND COLUMN_NAME = 'local_client_email') = 0,
    'ALTER TABLE clients ADD COLUMN local_client_email VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND COLUMN_NAME = 'local_referent_email') = 0,
    'ALTER TABLE clients ADD COLUMN local_referent_email VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND COLUMN_NAME = 'external_account_id') = 0,
    'ALTER TABLE clients ADD COLUMN external_account_id INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND COLUMN_NAME = 'local_client_maildir') = 0,
    'ALTER TABLE clients ADD COLUMN local_client_maildir VARCHAR(512) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unique keys
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND INDEX_NAME = 'uq_clients_external_client') = 0,
    'ALTER TABLE clients ADD UNIQUE KEY uq_clients_external_client (external_client_email)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND INDEX_NAME = 'uq_clients_local_client') = 0,
    'ALTER TABLE clients ADD UNIQUE KEY uq_clients_local_client (local_client_email)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND INDEX_NAME = 'uq_clients_local_referent') = 0,
    'ALTER TABLE clients ADD UNIQUE KEY uq_clients_local_referent (local_referent_email)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND INDEX_NAME = 'uq_clients_external_account') = 0,
    'ALTER TABLE clients ADD UNIQUE KEY uq_clients_external_account (external_account_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Foreign key (ON DELETE RESTRICT per PROMPT-53 §19)
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients'
       AND CONSTRAINT_NAME = 'fk_clients_external_account'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE clients ADD CONSTRAINT fk_clients_external_account
        FOREIGN KEY (external_account_id) REFERENCES external_accounts(id)
        ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
