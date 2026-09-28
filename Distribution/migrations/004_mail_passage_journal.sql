-- PROMPT-79.2 — durable mail-passage journal (ADR-001).
-- Idempotent: creates table only if missing. Does not modify schema.sql or 003.

USE mail_proxy;

CREATE TABLE IF NOT EXISTS mail_passage_journal (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_ts DATETIME(0) NOT NULL COMMENT 'UTC, set by application',
    event_type ENUM('delivered', 'disposed') NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    referent_name VARCHAR(255) NULL,
    client_name VARCHAR(255) NULL,
    local_mailbox VARCHAR(255) NULL,
    external_mailbox VARCHAR(255) NULL,
    received_at DATETIME(0) NULL COMMENT 'UTC, set by application',
    action_at DATETIME(0) NULL COMMENT 'UTC send or delete time, set by application',
    disposal_reason VARCHAR(64) NULL,
    notified TINYINT(1) NOT NULL DEFAULT 0,
    source_message_id VARCHAR(998) NULL,
    PRIMARY KEY (id),
    KEY idx_mpj_event_ts (event_ts),
    KEY idx_mpj_event_type_ts (event_type, event_ts),
    KEY idx_mpj_disposal_reason_ts (disposal_reason, event_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
