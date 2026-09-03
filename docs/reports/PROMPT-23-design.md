# PROMPT 23 — Panel authentication design (no code)

**Project:** DELTA-transit (mail-proxy)  
**Branch:** `prompt-23-panel-auth-design`  
**Date:** 2026-09-03  
**Status:** Design only — not implemented  
**PHP baseline (Epic A):** PHP 8.3.6 on test VPS (`docs/reports/PROMPT-21-report.md`)  
**Composer:** confirmed absent (no `composer.json` in repo)

---

## 1. Schema addition (DDL proposal — not applied)

**Choice: new table `panel_admins`.** Do not fold into `referents` / `clients` / `external_accounts`.

Justification:

- Referents/clients model mail routing identities, not panel operators.
- Mixing admin login hashes into those tables would couple unrelated lifecycles and risk confusing “mailbox user” with “panel admin”.
- A dedicated table matches the single-tenant admin tool shape and keeps PROMPT 24’s installer seed path simple.

```sql
CREATE TABLE IF NOT EXISTS panel_admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_panel_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes for PROMPT 24:

- `password_hash` length 255 matches PHP `password_hash()` output headroom (bcrypt ~60 chars; argon2id longer if ever pinned later).
- Store **only** the hash — never plaintext. Installer prompts interactively (same pattern as `ask_mysql_root_password()`), writes hash via `password_hash()`, never into `install-secrets.txt`.
- Pilot assumption (open question below): one active admin row is enough; schema still allows more rows without a migration.

---

## 2. Password hashing

| Item | Decision |
|------|----------|
| API | `password_hash($password, PASSWORD_DEFAULT)` / `password_verify()` |
| PHP on host | **8.3.6** (PROMPT 21) |
| `PASSWORD_DEFAULT` on 8.3 | **bcrypt** (`PASSWORD_BCRYPT`) |
| Explicit argon2? | **Not required** for pilot — keep `PASSWORD_DEFAULT` so PHP upgrades can move the default without schema change; `password_verify()` already handles algorithm id in the hash string |

Do **not** use Cryptor / AES for panel passwords (different concern from `password_enc` / `client_secret_enc`).

---

## 3. Session handling

Reuse existing `$_SESSION` (already started in `index.php` for `csrf_token`, flash, OAuth state).

| Session key | Meaning |
|-------------|---------|
| `$_SESSION['admin_id']` | Set only after successful `password_verify()`; unset on logout |
| Existing keys | `csrf_token`, `flash`, `oauth_state`, `oauth_account_id` — **unchanged**; do not overload them for auth |

`monitor.php` today does **not** call `session_start()`. PROMPT 24 must add `session_start()` (or a tiny shared bootstrap) **before** reading `$_SESSION['admin_id']`, so it shares the same PHP session cookie as `index.php` (default session name/`PHPSESSID`, same cookie path/domain).

No second session store, no Redis, no custom cookie auth jar.

---

## 4. Login throttling / lockout

Appropriate for a single-tenant internal tool behind the IP allow-list:

| Parameter | Proposal |
|-----------|----------|
| Mechanism | Fixed-window counter in `$_SESSION` (and optionally a short-lived file under `/tmp` keyed by `getClientIp()` if we want cross-session throttle — **prefer session-first** for zero new deps) |
| Window | 15 minutes |
| Max failures | 5 failed `login_submit` attempts per window per client IP (via `getClientIp()`, not raw `REMOTE_ADDR`) |
| On lockout | Reject with generic error (“Invalid credentials or too many attempts”); HTTP 429 optional; do not reveal whether username exists |
| Logging | `writeLog()` on each failure: `[YYYY-MM-DD HH:MM:SS] Panel login failed for user='…' ip=…` (no password, no hash) — same format `monitor.php` already parses |

No Composer rate-limit library. No DB lockout table required for pilot (session + allow-list is enough); escalate to DB-backed lockout only if open question asks for multi-admin / multi-browser hardness.

---

## 5. Gate placement relative to `checkLocalNetworkAccess()`

**Order is mandatory: network first, then login.**

```
request
  → checkLocalNetworkAccess()     # existing 403 HTML if outside allow-list
  → if action ∈ {login, login_submit}: allow without admin session
  → else: require $_SESSION['admin_id'] (redirect to login if missing)
  → existing CSRF checks for POST state-changing actions
  → existing $action switch
```

Rationale: an external client must still see only the opaque **403**, never a login form that advertises the panel.

Special cases:

- Keep today’s exception: `oauth_callback` already skips `checkLocalNetworkAccess()` in `index.php` — **do not change that exception** in PROMPT 23/24 unless a later prompt revisits OAuth callback threat model; login gate still applies to all other actions after network check where network check runs.
- Wait — re-read index.php: `oauth_callback` skips network check entirely. Login gate for oauth_callback: after OAuth redirect the browser may be same session; requiring admin session on callback is correct if admin initiated OAuth while logged in. If callback skips network check, login gate should still require `admin_id` so a forged callback from outside cannot mutate tokens without a session. **Proposal for PROMPT 24:** for `oauth_callback`, require authenticated session (`admin_id`) even though network check is skipped; if not logged in → 403/redirect login without rendering the full dashboard chrome. Flag as open question if product wants callback reachable without panel login (not recommended).

---

## 6. `monitor.php` vs `index.php` session

**Share the same session cookie** — do not invent a second auth mechanism.

| Entry point | Today | PROMPT 24 |
|-------------|-------|-----------|
| `index.php` | `session_start()` + `checkLocalNetworkAccess()` (except oauth_callback) | + require `admin_id` after network check |
| `monitor.php` | `checkLocalNetworkAccess()` only; **no** `session_start()` | Add `session_start()` then same `requirePanelAdmin()` helper after network check |

Shared helper (e.g. `web/includes/auth.php`): `requirePanelAdmin(): void` — if empty `admin_id`, redirect to `index.php?action=login` (for HTML) or 403 for non-browser if ever needed. Both entry points call it.

Logout: `action=logout` clears `admin_id` (and regenerates session id), keeps CSRF rotation style already used.

---

## Response format items

### A. Schema diff proposal

See §1 DDL (`panel_admins`) — not applied.

### B. Sequence

1. Client hits `index.php` or `monitor.php`.
2. `checkLocalNetworkAccess()` — fail → existing 403, stop.
3. `session_start()` (already on index; add on monitor).
4. If action is `login` / `login_submit` → render form or process POST (CSRF on submit); on success set `$_SESSION['admin_id']`.
5. Else if no `admin_id` → redirect to login (do not run dashboard/monitor body).
6. Else continue into existing `$action` switch / monitor page as today (CSRF unchanged for mutating POSTs).

### C. Open questions for human decision before PROMPT 24

1. **Single shared admin vs multiple operators?** Proposal default: **one** installer-seeded admin for pilot; schema allows more later.
2. **Username policy?** Free-form vs email-shaped? Proposal: simple `VARCHAR(100)` unique username (e.g. `admin`), not tied to referent email.
3. **`oauth_callback` without `admin_id`?** Proposal: **require** logged-in admin session even when network check is skipped.
4. **Session cookie flags** (`Secure`, `HttpOnly`, `SameSite`)? Out of scope for PROMPT 23 code; PROMPT 25 will observe host defaults — decide whether PROMPT 24 may set `session.cookie_httponly` / `secure` via `ini_set` only when HTTPS panel URL is configured.
5. **Cross-session lockout** (file/DB) vs session-only throttle? Proposal: session-only for pilot.
6. **Password reset path?** None for pilot (re-seed via installer/CLI); confirm acceptable.

---

## HARD CONSTRAINTS checklist (design)

| # | Constraint | Honored how |
|---|------------|-------------|
| 1 | Keep `checkLocalNetworkAccess()` | Additive gate after it |
| 2 | No Composer / third-party auth | `password_*` + `$_SESSION` only |
| 3 | Do not change Cryptor for panel passwords | Separate `password_hash` column |
| 4 | Do not weaken CSRF | `login_submit` goes through `requireValidCsrfToken()` |
| 5 | Minimal routing change | Only login/logout actions + gate before switch |

---

## Go / no-go to PROMPT 24

**GO to implement** once open questions **1** and **3** are answered (defaults above are safe if approved as-is).

---

## Addendum — resolved open questions (2026-09-03)

Human review of this design (commit `45d73ea` on `prompt-23-panel-auth-design`) resolved the open questions as follows. These **replace** the “single shared admin” default proposed in §C item 1; everything else in the original sections above still stands unless superseded here.

- **Multiple operators, not a single shared admin:** the panel will be used by several distinct people on an ongoing basis; each needs their own login for per-operator traceability in `writeLog()`.
- **Role model:** `panel_admins` gets an additional column `role ENUM('master','admin') NOT NULL DEFAULT 'admin'`.
- **Exactly one master account ever exists,** seeded only by the installer at first run (same interactive pattern as `ask_mysql_root_password()` — prompt for master username **and** password, confirm, store only the hash, never write plaintext to `install-secrets.txt` or anywhere else).
- **The web UI must NEVER be able to create or promote an account to `role='master'`** — any operator created through the panel is always `role='admin'`. This is an application-level rule (there is no DB constraint preventing a second master via direct SQL — document this in code comments as an accepted limitation; do not attempt to enforce it with a trigger).
- **Master-only feature “Manage operators”:** list existing `panel_admins` (username, role, active, created_at); form to add a new operator (username + password, entered directly by the master, no email/invite flow); action to deactivate (`active=0`) an existing non-master operator. No delete. No role change via UI.
- **Visibility:** management UI visible/reachable **only** when the currently logged-in session’s admin is `role='master'`. A regular admin hitting the action URL directly must be rejected — and this check must **re-query** `panel_admins.role` (and `active`) from the database at request time, not rely solely on a cached role value in `$_SESSION`, so that a master who gets deactivated mid-session loses the privilege immediately on the next request.
- **`oauth_callback` still requires an active `$_SESSION['admin_id']`** regardless of role (master or admin) — unchanged from the earlier design.
- **Username policy:** free-form `VARCHAR(100)`, unique, not tied to email or any external identity — the master chooses it when creating an operator; the installer prompts for the master’s own username the same way.
