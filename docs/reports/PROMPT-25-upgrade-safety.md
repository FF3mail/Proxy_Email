# PROMPT 25 — Panel authentication upgrade and migration safety

**Project:** DELTA-transit (mail-proxy)  
**Branch:** `prompt-24-panel-auth` (PROMPT-25 changes)  
**Date:** 2026-09-03  
**Scope:** Implementation + design audit — **no VPS deploy, no live tests executed**

---

## 1. Installation / update flow audit (repository state)

### Fresh install

| Step | Component | Behavior |
|------|-----------|----------|
| 1 | `import_schema` | Full `schema.sql` when DB empty (includes `panel_admins`) |
| 2 | `write_db_config` | Writes `/etc/mail-proxy/db.conf` |
| 3 | `migrate_panel_auth` | `ensure_panel_admins_table` + `seed_panel_master` (interactive) |
| 4 | `deploy_web_files` | `rsync` web tree including `auth.php`, `panel_migration.php`, `panel_auth_ui.php` |
| 5 | `validate_panel_auth` | WARN if no active master; OK if master present |

### Upgrade from pre-PROMPT-24 VPS (OS + iRedMail only, mail_proxy tables without `panel_admins`)

| Step | What happens | Data preserved |
|------|----------------|----------------|
| A | `import_schema` | **Skipped** — DB already has tables (`schema_already_installed`) |
| B | `migrate_panel_auth` | `CREATE TABLE IF NOT EXISTS panel_admins` — **additive only** |
| C | `seed_panel_master` | Interactive master seed if no active master; **aborts** if no TTY |
| D | Web deploy | New PHP auth gates active; legacy sessions without `admin_id` → login |
| E | PHP bootstrap | `bootstrapPanelAuth()` creates table if installer not yet run (idempotent) |

### Gaps intentionally unchanged (documented)

- `import_schema` still does not apply incremental ALTERs for future columns — only `panel_admins` CREATE IF NOT EXISTS is handled via migration path.
- Full installer re-run still prompts for URL / MariaDB / pip mirror (not scoped to PROMPT-25).
- `create_database_user` / `ALTER USER` on re-run still rotates DB password — **pre-existing installer behavior**; operators should use `db.conf` after re-run.

---

## 2. Threat / risk analysis

| Risk | Severity | Mitigation in PROMPT-25 |
|------|----------|-------------------------|
| Upgrade drops mail data | **Critical** | No DROP/TRUNCATE/ALTER destructive ops; `CREATE IF NOT EXISTS` only |
| Non-interactive auto-generated master password | **Critical** | `installer_has_tty` check; `fatal` with remediation text; no env-var password injection |
| Auth lockout after deploy | **High** | Login page shows setup message; `panelAuthLoginAllowed()` blocks login until master seeded; installer validates and warns |
| Legacy session fatal / redirect loop | **High** | `sanitizeLegacyPanelSession()`; PDO exceptions caught in fetch helpers; `clearPanelSession()` on reject |
| Stale `admin_id` after upgrade | **Medium** | `requirePanelAdmin()` re-queries DB; missing row → safe redirect to login |
| Inactive master blocks all login | **Medium** | Clear log + UI message; SQL remediation documented; installer `fatal` with instructions |
| Second master via SQL | **Low** (accepted) | Documented limitation from PROMPT-23/24; unchanged |
| Master seed skipped silently on re-run | **Low** | `panel_active_master_exists` skip is logged as OK |
| Plaintext password in logs/secrets | **Critical** | Unchanged policy: hash only; passwords cleared from shell vars after hash |

---

## 3. Upgrade test plan (manual — not executed in PROMPT-25)

**Prerequisites:** VPS reset to OS + iRedMail; existing `mail_proxy` DB from prior Epic A install **without** `panel_admins`; branch with PROMPT-25 commits.

### T1 — Schema migration idempotency

1. Confirm `SHOW TABLES LIKE 'panel_admins'` → empty before upgrade.
2. Run installer Database phase (or full installer) interactively; seed master.
3. Re-run installer Database phase → expect `Active panel master present — skipping seed`.
4. Run migration twice → table row count unchanged.

### T2 — Web-only deploy before installer

1. `rsync` web/ only.
2. Hit `/index.php?action=login` from allowlisted IP → yellow banner (no master).
3. Check `/var/log/mail-proxy/web_admin.log` for startup migration/validation lines.
4. Run installer interactively → login succeeds.

### T3 — Non-interactive abort

1. Run installer without TTY when no master exists.
2. Expect exit 1 and remediation text on stderr; **no** auto-generated credentials.

### T4 — Legacy session handling

1. Session with only `csrf_token` / `oauth_state` → `dashboard` redirects to login (no 500, no loop).
2. Invalid `admin_id` in session → cleared, redirect login.

### T5 — Operator continuity

1. Master creates admin; admin uses dashboard + monitor.
2. Master deactivates admin → admin next request → login redirect.

### T6 — OAuth regression

1. Master initiates OAuth → callback with same session → token stored.
2. Without session, `oauth_callback` → login redirect.

---

## 4. Rollback considerations

| Scenario | Rollback action | Data impact |
|----------|-----------------|-------------|
| Web deploy only | Restore previous `/var/www/mail-proxy/` backup | `panel_admins` rows remain (harmless if old code ignores table) |
| Schema migration applied | **Do not DROP** `panel_admins` unless intentional | Existing mail tables untouched |
| Master seeded incorrectly | Deactivate or update password hash via SQL | No mail data loss |
| Installer re-run rotated DB password | Restore previous `/etc/mail-proxy/db.conf` | Daemon/web must match |
| Full revert to pre-PROMPT-24 code | Redeploy old web tree | Old code had no auth gate — not recommended |

---

## 5. Verification status (this session)

| Check | Result |
|-------|--------|
| VPS deploy | **Not performed** |
| `php -l` | **Not available** on Windows agent host |
| `bash -n` | **Not re-run** this session |
| Live upgrade on reset VPS | **Pending** — deliverable is repo implementation + plan |
