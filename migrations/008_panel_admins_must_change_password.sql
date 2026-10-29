-- Issue #138 / DAT-9: operator must change password after master reset (SEC-11).
-- Idempotent: ADD COLUMN IF NOT EXISTS only. No DROP / destructive ALTER.

USE mail_proxy;

ALTER TABLE panel_admins
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
