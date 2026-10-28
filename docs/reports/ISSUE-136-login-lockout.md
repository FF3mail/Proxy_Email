# Issue #136 — Bind panel login lockout to login+IP in the database

**Date:** 2026-10-28  
**Branch:** `fix/issue-136-login-lockout-db`  
**Base:** `origin/master` `@ 2ccef2f`  
**SPEC:** SEC-10, DAT-18, ACC-15, D-52; recorded gap DEV-23 is **not** closed in `docs/SPEC.md` (operator wording later).  
**SPEC.md:** not edited.

## Change

Failed panel logins are stored in `panel_login_attempts` (login, IP, UTC time, result). Lockout is counted for the pair **login + IP**, not the PHP session. After 5 failures in 900 seconds that pair is blocked for 900 seconds; a correct password is rejected while locked. A new `PHPSESSID` does not reset the counter. Rows older than 24 hours are deleted in batches of 500. Client IP still comes from unchanged `getClientIp()` (forwarded headers only when `REMOTE_ADDR` is localhost).

## Files

| Area | Path |
|------|------|
| Migration | `migrations/007_panel_login_attempts.sql` |
| Schema | `schema.sql` (`CREATE TABLE IF NOT EXISTS panel_login_attempts`) |
| Lockout | `web/includes/panel_login_lockout.php` |
| Auth | `web/includes/auth.php`, `web/includes/panel_auth_ui.php`, `web/includes/panel_migration.php` |
| i18n | `web/lang/en.php`, `web/lang/ru.php` (`auth.login_locked`) |
| Tests | `tests/test_login_lockout.py` |

## Apply migration

```bash
mysql mail_proxy < migrations/007_panel_login_attempts.sql
```

Idempotent. No DROP. Fresh installs also get the table from `schema.sql`. Bootstrap `ensurePanelLoginAttemptsSchema()` creates the table if missing.

## Out of scope

HSTS/nginx (DEV-25), operator password change (DEV-24), OAuth IP skip (DEV-26), closing DEV-23 in SPEC.

## Tests

Offline Python `unittest` (no live services). PHP syntax/`php` tests only if `php` is on PATH.
