-- PROMPT-83: referent-level local_inbox / local_outbox optional (relationship-centric routing).
-- Idempotent on fresh installs that already use schema.sql with NULL columns.
USE mail_proxy;

ALTER TABLE referents
    MODIFY COLUMN local_inbox VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN local_outbox VARCHAR(255) NULL DEFAULT NULL;
