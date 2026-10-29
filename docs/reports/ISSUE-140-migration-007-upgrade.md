# Issue #140 — Migration 007 upgrade path for `panel_login_attempts`

**Date:** 2026-10-29  
**Branch:** `fix/issue-140-migration-007-upgrade-path`  
**Base:** `origin/master` `@ 87ec86e`  
**SPEC:** DAT-18, SEC-10, ACC-15, INS-8; DEV-23 **not** closed in `docs/SPEC.md`  
**SPEC.md:** not edited  

## Problem

After PR #137, production hosts may lack `panel_login_attempts` unless an operator ran manual SQL. The installer imported `schema.sql` only on empty DB and never applied `migrations/007_panel_login_attempts.sql`. Runtime `ensurePanelLoginAttemptsSchema()` requires `CREATE TABLE` on the panel DB user; without it, login is fail-closed.

## Change

| Area | Path |
|------|------|
| Installer | `delta-transit-install.sh` — `ensure_panel_login_attempts_table()` in `migrate_panel_auth()` (root mysql, idempotent `007` SQL) |
| Validation | `validate_panel_auth()` warns if table missing; `scripts/verify-install-regression.sh` checks table |
| Panel fallback | `web/includes/panel_login_lockout.php` — keep CREATE fallback; distinct fail-closed log on login path |
| Docs | `docs/guide/03-installation.md` §3.8, `docs/guide/06-operations.md` §6.5 |
| Tests | `tests/test_installer_migration_007.py`, `tests/test_login_lockout.py` |

## Fresh install vs upgrade

| Path | `panel_login_attempts` |
|------|------------------------|
| Empty DB | `schema.sql` during `import_schema` |
| Existing DB (re-run installer Database phase) | `migrations/007_panel_login_attempts.sql` via **mysql root** in `migrate_panel_auth` |
| Web-only rsync | No DDL — operator must run installer Database phase or manual `mysql < 007` |

Migrations `002`–`006` are **not** applied by the installer (historical operator SQL, unchanged). Only `panel_admins` (app user CREATE) and `007` (root SQL file) are automated in the installer migration path.

## GRANT / privileges

- Installer `create_database_user()` grants `ALL PRIVILEGES ON mail_proxy.*` to `mail_proxy`@localhost (**DEV-39**: single app user, not DAT-20 split accounts).
- `ensure_panel_admins_table()` and PHP `ensurePanelAdminsSchema()` use the app user.
- `ensure_panel_login_attempts_table()` uses **root** (same pattern as `import_schema` on empty DB) so DAT-18 DDL succeeds even when the panel user is later restricted to DML-only.
- PHP `ensurePanelLoginAttemptsSchema()` remains a fallback when the app user retains `CREATE`.

## Manual apply (still supported)

```bash
mysql mail_proxy < migrations/007_panel_login_attempts.sql
```

## Verification

```bash
mysql -u mail_proxy -p mail_proxy -e "SHOW TABLES LIKE 'panel_login_attempts';"
./scripts/verify-install-regression.sh
```

## Tests (this branch)

- `bash -n delta-transit-install.sh`
- `php -l` on `web/includes/panel_login_lockout.php`
- `php tests/panel_login_lockout_test.php`
- `python3 -m unittest tests/test_installer_migration_007.py tests/test_login_lockout.py`

Compare full-suite failures to master baseline in PR body.

## Out of scope

Issues #141–#143, PR/issue #139, lockout thresholds, `getClientIp()`, closing DEV-23 in SPEC.
