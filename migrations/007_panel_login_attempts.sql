-- Issue #136 / SEC-10: panel login attempts keyed by login + IP (DAT-18).
-- Idempotent: CREATE TABLE IF NOT EXISTS only. No DROP / destructive ALTER.

USE mail_proxy;

CREATE TABLE IF NOT EXISTS panel_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    login VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME(0) NOT NULL COMMENT 'UTC, set by application',
    result ENUM('failure', 'success', 'blocked') NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pla_login_ip_ts (login, ip, attempted_at),
    KEY idx_pla_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
