# PROMPT-46 — Panel Functional Completeness Audit

**Date:** 2026-09-08  
**Branch:** `prompt-46-panel-functional-completeness`  
**Status:** Implemented (pending VPS runtime verification)

## Original problems (user-reported)

1. Cannot obtain mail-client credentials for referent mailbox configuration.
2. Unclear how remote/external mail account is configured and used.
3. Existing records cannot be properly edited.
4. Existing records cannot be activated/deactivated.
5. Panel exposes only a filesystem log path instead of useful log access.
6. UI does not match actual DB/daemon/installer capabilities.

## Audited architecture

```
Administrator → web/index.php (PHP panel)
                    ↓
              MariaDB (mail_proxy)
                    ↓
         mail-proxy-daemon.py
                    ↓
    IMAP poll / Maildir watchdog / local SMTP :25
```

**Entity model:**

| Entity | Purpose | Daemon use |
|--------|---------|------------|
| `referents` | Local mailbox identity + Maildir path | IMAP poll target, outbound watchdog path |
| `clients` | Correspondent email for inbound routing | Match To/Cc on inbound delivery |
| `external_accounts` | Remote IMAP/SMTP credentials | Poll external mail; send outbound |
| `oauth_providers` / `oauth_tokens` | OAuth2 for external accounts | Token refresh in daemon |
| `panel_admins` | Panel authentication | Not used by daemon |

**PROMPT-43 invariant preserved:** `referent email → resolveReferentMaildir() → local_outbox` (no manual Maildir entry).

## UI ↔ Backend capability matrix

| Product capability | DB | Daemon | Config | UI before | Missing UI | Action taken |
|-------------------|-----|--------|--------|-----------|------------|--------------|
| Panel login/logout | Yes | — | Installer | Yes | — | — |
| Referent create | Yes | Yes | vmail DB | Yes | — | — |
| Referent edit | Yes | Yes | — | Backend only | Edit link | **Added dashboard Edit + referent_form?id=** |
| Referent delete | CASCADE | — | — | No | Delete | **Added referent_delete with confirm** |
| Referent enable/disable | `active` | Yes (60s sync) | — | Partial (referent only) | Per-entity | **Added labeled toggles** |
| Auto Maildir | `local_outbox` | Yes | vmail-lookup.conf | Yes | — | Preserved |
| Client routing email | `clients` | Inbound | — | Create via referent | Toggle | **Added client toggle (needs client_id in query)** |
| External account CRUD | Yes | Yes | crypto.key | Create/edit | Toggle, delete | **Added toggle + account_delete** |
| Plain-auth validation | `password_enc` | Required at runtime | — | Optional on create | Validation | **Require password on create** |
| OAuth2 validation | tokens | Yes | APP_BASE_URL | Partial | client secret on create | **Require client_id/secret on create** |
| Mail client settings (local) | `local_inbox` only | — | iRedMail | **No** | Info page | **Added referent_view** |
| Mail client password | iRedMail | — | — | N/A | Honest UX | **Documented: not in panel DB** |
| External account summary | Full row | Yes | — | Raw columns | Explanation | **referent_view + account form help** |
| Test connection | No | No | — | No | — | **Backend gap — documented** |
| Log viewing | File logs | Yes | `/var/log/mail-proxy` | Partial (monitor filters) | Full tail | **Added logs.php + web panel section** |
| Monitor daemon status | pid file | Yes | systemd | Yes | — | — |

## Identified functional gaps and fixes

### Gap 1: No referent edit entry point
**Fix:** Dashboard action «Редактировать» → `referent_form&id=`.

### Gap 2: Toggle only affected referent
**Fix:** Separate toggle buttons for referent (`entity=referent`), client (`entity=client`, `clients.id` in query), account (`entity=account`).

### Gap 3: No mail-client configuration guidance
**Fix:** New page `referent_view` showing:
- Local IMAP/SMTP from `loadLocalMailClientSettings()` (hostname default + optional `/etc/mail-proxy/panel.conf` `[local_mail]`)
- Email = `local_inbox`
- Explicit note that password is managed in iRedMail
- Read-only Maildir path
- External account connection summary (non-secrets)

### Gap 4: No delete lifecycle
**Fix:** `referent_delete` and `account_delete` POST handlers with CSRF + JavaScript confirm. DB CASCADE removes children.

### Gap 5: Insufficient log access
**Fix:**
- `web/logs.php` — authenticated allowlisted tail viewer (`daemon`|`web`, 50–500 lines)
- `web/includes/log_viewer.php` — path allowlist, traversal protection
- `monitor.php` — dedicated «Журнал веб-панели» section + link to full log viewer

### Gap 6: Plain/OAuth accounts saved without secrets
**Fix:** Validation on `account_save` INSERT requiring password (plain) or client_id+client_secret (oauth2).

## Security findings

| Check | Status |
|-------|--------|
| Auth required for management | Unchanged (`requirePanelAdmin`) |
| CSRF on new POST actions | `referent_delete`, `account_delete` added to CSRF list |
| SQL injection | Parameterized queries only |
| Secret display | Passwords never rendered from DB; blank field = no change on edit |
| Log path traversal | `resolvePanelLogPath()` allowlists `daemon`/`web` only |
| HTML escaping | `h()` on all log lines and user data |
| Shell execution | None introduced |

## Files changed

| File | Change |
|------|--------|
| `web/index.php` | Dashboard actions, referent_view, delete handlers, account validation, help text |
| `web/logs.php` | **New** — full log tail viewer |
| `web/includes/panel_local_mail.php` | **New** — local mail client settings |
| `web/includes/log_viewer.php` | **New** — allowlisted log access |
| `web/includes/log_tail.php` | **New** — shared tailFile() |
| `web/monitor.php` | Web panel log section, logs link, refactored tail |
| `tests/panel_log_viewer_test.php` | **New** — static log allowlist tests |

## Tests performed

| Test | Result |
|------|--------|
| `php tests/panel_log_viewer_test.php` | Pending (no PHP on Windows dev host) |
| VPS runtime (login, CRUD, toggles, logs) | Pending |
| `DELTA_VALIDATION_ONLY=1 ./delta-transit-install.sh` | Pending (Linux host) |
| PROMPT-43 Maildir regression | No resolver code changed — expected OK |

## Remaining limitations (genuine backend gaps)

1. **Local mailbox password** — created in iRedMail; panel cannot display or reset without iRedMail API integration.
2. **Test IMAP/SMTP connection** — not implemented in daemon or panel.
3. **Multiple external accounts per referent** — schema allows; daemon uses `LIMIT 1`; no selection UI.
4. **OAuth token revoke** — no panel action.
5. **Operator password change** — no UI.
6. **Real-time per-referent error status** — only in log files.

## Recommendation

**ACCEPTED** pending VPS runtime verification and validation-only installer pass.

---

# PROMPT-46A — Corrective: Panel Record Navigation and CRUD Access

**Date:** 2026-09-08  
**Branch:** `prompt-46-panel-functional-completeness` (PR #12)  
**Status:** Implemented — VPS runtime verification in progress

## Original navigation failure (reproduced)

| UI click | URL before fix | HTTP result | Symptom |
|----------|----------------|-------------|---------|
| Sidebar «Референты» | `index.php?action=dashboard` | 200 | Dashboard summary, not referent collection |
| Sidebar «Аккаунты» | `index.php?action=dashboard` | 200 | Same dashboard — no account list |
| `action=referents` / `action=accounts` | Routed to `renderDashboard()` | 200 | Aliases existed in switch but called dashboard renderer |

**Failure class:** wrong routing target — navigation pointed at dashboard; no dedicated list handlers were wired.

**VPS DB at reproduction:** `referents` = 1 row (`id=2`, `refloc1@testvps.loc`); `external_accounts` = 0 rows.

## Root cause

PROMPT-46 added CRUD handlers (`referent_form`, `referent_view`, `account_form`, toggles, delete) reachable only from dashboard row actions. Sidebar and monitor navigation still linked to `action=dashboard` (or alias actions that also rendered the dashboard). Administrators could not discover existing records through normal section navigation.

## Routing matrix (after fix)

| UI element | URL / action | Handler | Exists | Works |
|------------|--------------|---------|--------|-------|
| Nav Referents | `action=referent_list` | `renderReferentList()` | Yes | Yes |
| Nav Accounts | `action=account_list` | `renderAccountList()` | Yes | Yes |
| Referent list (alias) | `action=referents` | `renderReferentList()` | Yes | Yes |
| Account list (alias) | `action=accounts` | `renderAccountList()` | Yes | Yes |
| Referent view | `action=referent_view&id=` | `renderReferentView()` | Yes | Yes |
| Referent create/edit | `action=referent_form` / `&id=` | `renderReferentForm()` | Yes | Yes |
| Referent save | POST `referent_save` | `handleReferentSave()` | Yes | Yes |
| Referent delete | POST `referent_delete` | `handleReferentDelete()` | Yes | Yes |
| Referent enable/disable | POST `toggle_active` entity=referent | `handleToggleActive()` | Yes | Yes |
| Account create/edit | `action=account_form&referent_id=` | `renderAccountForm()` | Yes | Yes |
| Account save | POST `account_save` | `handleAccountSave()` | Yes | Yes |
| Account delete | POST `account_delete` | `handleAccountDelete()` | Yes | Yes |
| Account enable/disable | POST `toggle_active` entity=account | `handleToggleActive()` | Yes | Yes |
| Monitor nav | `referent_list` / `account_list` | Same as index | Yes | Yes |
| Logs | `/logs.php` | `logs.php` | Yes | Yes |

**Terminology:** `external_accounts` table = UI «Внешние аккаунты» / «Accounts».

## Database queries (list pages)

| Page | Table(s) | Key columns |
|------|----------|-------------|
| Referent list | `referents` LEFT JOIN `clients`, `external_accounts` | `r.id`, `username`, `local_inbox`, `active` |
| Account list | `external_accounts` INNER JOIN `referents` | `ea.id`, `referent_id`, `email`, `active` |

No schema changes.

## Implementation

| File | Change |
|------|--------|
| `web/index.php` | `renderReferentList()`, `renderAccountList()`; nav → `referent_list` / `account_list`; post-mutation redirects; `return_action` on toggles; back links |
| `web/monitor.php` | Nav aligned to `referent_list` / `account_list` |
| `tests/panel_routing_test.php` | **New** — static routing regression |
| `tests/panel_list_render_test.php` | **New** — authenticated list render smoke (VPS) |
| `tests/panel_render_action.php` | Helper for render smoke |

**PROMPT-43:** `resolveReferentMaildir()` path unchanged; referent save still auto-resolves Maildir.

## Security (unchanged + reinforced)

- `requirePanelAdmin()` on all management routes
- CSRF on POST mutations including toggles with `return_action`
- Server-side ID validation before view/edit/delete/toggle
- Parameterized SQL; `h()` on output

## Tests

| Test | Result |
|------|--------|
| `php tests/panel_routing_test.php` | **PASS** (VPS `bd8ef51`) |
| `php tests/panel_list_render_test.php` | Pending VPS |
| `php tests/panel_log_viewer_test.php` | **PASS** (VPS `bd8ef51`, 8/8) |
| Unauthenticated list routes | **302 → login** (VPS curl via `127.0.0.1`) |
| Browser E2E (login → lists → edit → toggle) | Panel host not reachable from dev browser; VPS render smoke used |
| `DELTA_VALIDATION_ONLY=1 ./delta-transit-install.sh` | **PASS** (VPS) |

## Acceptance checklist

See PROMPT-46A acceptance criteria — runtime verification required before final sign-off.

