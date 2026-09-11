# PROMPT-47 — Panel Authorization and Role Model Audit

**Date:** 2026-09-09  
**Branch:** `prompt-47-panel-authorization-audit`  
**VPS:** `192.168.125.116` (`panel.testvps.loc`)  
**Status:** Complete

## Executive summary

Proxy_Email implements **authentication plus a minimal two-tier role model** (`master` / `admin`). Roles are **real and server-enforced**, but **only for operator (panel admin) management**. All other panel capabilities (referents, external accounts, OAuth providers, logs, monitor) require **any active panel operator** — there is **no further privilege separation** and **no ownership restrictions**.

This matches the intended design documented in PROMPT-23 (Model B: Master → Admin), not a full multi-level RBAC or per-resource ownership model.

---

## 1. Authentication architecture

### Layers (in order)

| Layer | Mechanism | Enforced where | Bypass |
|-------|-----------|----------------|--------|
| Network | `checkLocalNetworkAccess()` — RFC1918 + loopback | `index.php` (except `oauth_callback`), `monitor.php`, `logs.php` | `oauth_callback` skips IP gate by design |
| Session | `PHPSESSID` with `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS | `startPanelSession()` in `helpers.php` | None observed |
| Login | `password_verify()` against `panel_admins.password_hash` | `handleLoginSubmit()` | Throttled after 5 failures / 15 min |
| Active account | `active=1` re-queried on every request | `requirePanelAdmin()` | Deactivated admin loses access on next request |
| CSRF | `hash_equals()` on POST mutations | `requireValidCsrfToken()` in `index.php` | OAuth callback is GET-only |
| Bootstrap gate | At least one active `role='master'` must exist | `panelAuthLoginAllowed()` | Login form disabled if no active master |

### Session model

| Session key | Purpose | Trusted for authorization? |
|-------------|---------|---------------------------|
| `admin_id` | Primary identity | Yes — looked up in DB each request |
| `admin_role_display` | Nav chrome / `isPanelMasterDisplay()` | **No** — display only; documented in `auth.php` |
| `admin_username_display` | Nav chrome / logging | No |
| `csrf_token` | POST protection | Yes |
| `login_fail_*` | Rate limiting | N/A |

### Entry points

| File | Auth gate |
|------|-----------|
| `web/index.php` | `requirePanelAdmin()` for all actions except `login`, `login_submit` |
| `web/monitor.php` | `requirePanelAdmin()` |
| `web/logs.php` | `requirePanelAdmin()` |

### Account creation

- **Master:** seeded once by interactive installer (`delta-transit-install.sh` → `seed_panel_master()`). Plaintext password never stored on disk.
- **Admin operators:** created by master via UI (`operator_create`) — always `role='admin'`.
- **Login availability:** blocked when `panel_admins` missing, no active master, or master exists but `active=0`.

---

## 2. Authorization architecture

### What exists

```
┌─────────────────────────────────────────────────────────┐
│  IP allow-list (RFC1918 / localhost)                    │
└───────────────────────────┬─────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────┐
│  requirePanelAdmin() — any active master OR admin       │
│  (referents, accounts, providers, logs, monitor, …)   │
└───────────────────────────┬─────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────┐
│  requireMasterAdmin() — active role='master' only       │
│  (operator_list, operator_create, operator_deactivate)    │
└─────────────────────────────────────────────────────────┘
```

### What does NOT exist

- No `isAdmin()`, `requireRole()`, `currentUserRole()` helpers beyond `isPanelMasterDisplay()` (UI-only).
- No per-referent / per-account ownership (`created_by`, `owner_id`, etc.).
- No operator/viewer/read-only tiers.
- No panel settings page with separate ACL.
- No password-change or admin-edit UI.

### Role enforcement functions

| Function | File | Behavior |
|----------|------|----------|
| `requirePanelAdmin()` | `auth.php` | Redirect to login if `admin_id` missing, row missing, or `active=0` |
| `requireMasterAdmin()` | `auth.php` | HTTP 403 if not active master; **re-queries DB** (does not trust session role cache) |
| `isPanelMasterDisplay()` | `auth.php` | Reads `$_SESSION['admin_role_display']` — **UI only** (nav link) |
| `panelAuthLoginAllowed()` | `panel_migration.php` | Global login gate |
| `handleOperatorCreate()` | `panel_auth_ui.php` | Rejects POST `role` field; INSERT hardcoded `role='admin'` |

---

## 3. Database role model

### `panel_admins` (only auth/admin table)

| Field | Type | Purpose | Used in code? | Enforced? |
|-------|------|---------|---------------|-----------|
| `id` | INT PK | Session identity (`admin_id`) | Yes | Yes |
| `username` | VARCHAR(100) UNIQUE | Login name | Yes | Yes (unique) |
| `password_hash` | VARCHAR(255) | `password_hash()` / `password_verify()` | Yes | Yes |
| `role` | ENUM(`master`,`admin`) | Privilege tier | Yes | **Partially** — only operator management |
| `active` | TINYINT(1) | Account enabled | Yes | Yes — login + every request |
| `created_at` | DATETIME | Audit / display | Yes (operator list) | No |
| `updated_at` | DATETIME | Deactivation timestamp | Yes (UPDATE) | No |

### Other tables — no panel authorization fields

| Table | Auth-related columns | Panel ownership? |
|-------|---------------------|------------------|
| `referents` | `active` (entity status, not operator ACL) | No |
| `clients` | `active` | No |
| `external_accounts` | `active` | No |
| `oauth_providers` | `active` | No |
| `oauth_tokens` | — | No |

**VPS snapshot (2026-09-09):**

```
id=1  username=admin  role=master  active=1
id=2  username=user   role=admin   active=0  (deactivated by master)
```

---

## 4. Runtime enforcement matrix

Legend: **Auth** = `requirePanelAdmin()`; **Master** = `requireMasterAdmin()`; **—** = no role check beyond auth.

| Action | Endpoint | Auth | Role | Master only? | Server enforced? |
|--------|----------|------|------|--------------|----------------|
| Login form | `login` | — | — | — | Public (behind IP) |
| Login submit | `login_submit` | — | — | — | `active=1` + password |
| Logout | `logout` | Auth | — | No | Yes |
| Dashboard | `dashboard` | Auth | — | No | Yes |
| Referent list | `referent_list` / `referents` | Auth | — | No | Yes |
| Referent view | `referent_view` | Auth | — | No | Yes |
| Referent form | `referent_form` | Auth | — | No | Yes |
| Referent save | `referent_save` | Auth + CSRF | — | No | Yes |
| Referent delete | `referent_delete` | Auth + CSRF | — | No | Yes |
| Referent toggle | `toggle_active` entity=referent | Auth + CSRF | — | No | Yes |
| Account list | `account_list` / `accounts` | Auth | — | No | Yes |
| Account form/save | `account_form` / `account_save` | Auth + CSRF | — | No | Yes |
| Account delete | `account_delete` | Auth + CSRF | — | No | Yes |
| Account toggle | `toggle_active` entity=account | Auth + CSRF | — | No | Yes |
| Client toggle | `toggle_active` entity=client | Auth + CSRF | — | No | Yes |
| Provider list/form/save | `provider_*` | Auth + CSRF | — | No | Yes |
| Provider toggle | `provider_toggle` | Auth + CSRF | — | No | Yes |
| OAuth initiate | `oauth_initiate` | Auth + CSRF | — | No | Yes |
| OAuth callback | `oauth_callback` | Auth (no IP) | — | No | Yes |
| Monitor | `monitor.php` | Auth | — | No | Yes |
| Log viewer | `logs.php` | Auth | — | No | Yes |
| Operator list | `operator_list` | Auth | **master** | **Yes** | **Yes** (`requireMasterAdmin`) |
| Operator create | `operator_create` | Auth + CSRF | **master** | **Yes** | **Yes** |
| Operator deactivate | `operator_deactivate` | Auth + CSRF | **master** | **Yes** | **Yes** |

---

## 5. Master / admin capabilities matrix

Verified by static code trace and VPS runtime tests (`tests/panel_render_as_role.php`, `tests/panel_auth_role_test.php`).

| Capability | Master | Admin | Notes |
|------------|--------|-------|-------|
| View dashboard | Yes | Yes | VPS: admin `referent_list` PASS |
| List/create/edit/delete referents | Yes | Yes | No role check in handlers |
| Enable/disable referent/client/account | Yes | Yes | `handleToggleActive()` |
| Manage external accounts | Yes | Yes | Includes OAuth secrets |
| View monitor / logs | Yes | Yes | VPS: unauthenticated → 302 |
| Manage OAuth providers | Yes | Yes | VPS: admin `provider_list` PASS |
| List operators | Yes | **No** | VPS: admin → HTTP 403 body |
| Create admin operator | Yes | **No** | INSERT forced `role='admin'`; rejects `role` POST |
| Deactivate admin operator | Yes | **No** | Cannot deactivate self or master |
| Deactivate master | **No** | **No** | UI + SQL `WHERE role='admin'` |
| Delete master | **No** | **No** | No delete action exists |
| Delete admin | **No** | **No** | Only deactivate |
| Change own password | **No** | **No** | No UI |
| Change other passwords | **No** | **No** | No UI |
| Promote admin → master | **No** | **No** | UI cannot; SQL can (limitation) |

**Conclusion:** Admin is a **full panel operator** except for **panel user administration**. Master adds **only** operator lifecycle management.

---

## 6. Security findings

### Server-side enforcement (good)

| Finding | Severity | Detail |
|---------|----------|--------|
| Operator actions master-gated | OK | `index.php` lines 71–73 call `requireMasterAdmin()` before switch; re-queries DB |
| Deactivated account revocation | OK | `requirePanelAdmin()` checks `active` every request |
| CSRF on mutations | OK | All POST handlers listed in `$postActionsRequiringCsrf` |
| Master cannot be deactivated via UI | OK | `handleOperatorDeactivate()` blocks `role='master'` and self-id |
| Role escalation via create form | OK | `role` POST rejected; INSERT hardcoded `'admin'` |
| Unauthenticated access | OK | VPS: dashboard/monitor/logs → HTTP 302 to login |
| IP gate before session cookie | OK | PROMPT-29 — no `Set-Cookie` on 403 |

### UI-only vs server-side

| Control | UI only? | Server backup? |
|---------|----------|----------------|
| "Operators" nav link | Yes (`isPanelMasterDisplay()`) | Yes — direct URL returns 403 for admin |
| Operator deactivate button | Hidden for master rows | Yes — server rejects master target |
| All referent/account actions | Visible to all admins | Same access server-side (by design) |

### Gaps and risks

| Finding | Severity | Detail |
|---------|----------|--------|
| **Flat admin privileges** | Medium (design) | Any `admin` can delete referents, view logs, edit OAuth providers, manage all mail config |
| **No password rotation UI** | Low | Operators cannot change passwords; requires SQL or new operator |
| **Second master via SQL** | Low (accepted) | No DB constraint; documented in `auth.php`, `schema.sql`, PROMPT-23 |
| **No admin audit trail per user** | Low | `writeLog()` includes username but no structured audit table |
| **`isPanelMasterDisplay()` session cache** | Info | Could show stale nav if role changed in DB mid-session; **does not grant access** to operator endpoints |
| **oauth_callback skips IP check** | Info | By design (PROMPT-23); still requires valid session |

### Privilege escalation paths reviewed

| Vector | Result |
|--------|--------|
| Admin → `operator_list` URL | **Blocked** (403) — VPS verified |
| Admin → POST `operator_create` | **Blocked** (403) |
| Admin → POST `role=master` on create | **Rejected** before INSERT |
| Admin → deactivate master via POST | **Blocked** (master row check + SQL `role='admin'`) |
| Session `admin_role_display=master` forgery | **Ineffective** — `requireMasterAdmin()` re-queries DB |
| Direct SQL `INSERT role='master'` | **Possible** — out of band; accepted limitation |
| CSRF bypass | Redirect to index with flash error |

**No critical security defect requiring emergency code change was identified.**

---

## 7. Intended vs actual design

### Intended (PROMPT-23 design, installer, schema)

**Model B — Two-tier administration:**

```
Master (installer-seeded, exactly one intended)
 └── Admin (UI-created operators)
```

Design requirements from `docs/reports/PROMPT-23-design.md`:

- Multiple operators with distinct logins ✓
- `role ENUM('master','admin')` ✓
- UI never creates/promotes master ✓
- Master-only operator management ✓
- Server re-query of role (not session cache) for master gate ✓
- `oauth_callback` requires any active operator ✓

### Actual behavior

**Matches intended Model B.** Implementation is **narrow RBAC**: roles affect **only** `panel_admins` lifecycle. Mail-proxy data plane (`referents`, `external_accounts`, etc.) uses **shared admin access** with authentication as the sole gate.

### Not implemented (and not intended in PROMPT-23)

- Model C (operator/viewer tiers)
- Model D (ownership per referent)
- Per-feature ACL matrix beyond master/admin split

---

## 8. Gap analysis and recommended next steps

### What works

- Session authentication with active-account revalidation
- Login throttling and CSRF protection
- Real master/admin distinction for operator management
- Server-side 403 on unauthorized operator actions (not just hidden buttons)
- Installer master bootstrap with non-interactive abort path
- Idempotent `panel_admins` migration

### What is missing / inconsistent

| Gap | Impact |
|-----|--------|
| Admin has full data-plane access | Operational risk in multi-person teams |
| No password change flow | Ops friction; password rotation requires SQL |
| No operator edit/reactivate UI | Deactivated admins need SQL `active=1` |
| `isPanelMasterDisplay()` can be stale | Minor UX confusion only |
| Role names `master`/`admin` imply broader RBAC | Documentation/onboarding confusion |

### Recommended next steps (analysis only — not implemented)

1. **Document explicitly** in operator onboarding that `admin` = full panel access except user management (reduces PROMPT-47-style confusion).
2. **If tighter RBAC is needed later:** define tiers (e.g. `viewer`, `operator`, `master`) and map actions before coding; current schema supports only two values.
3. **Add password change** for self-service (master + admin) — low effort, high ops value.
4. **Add operator reactivate** in master UI (UPDATE `active=1`) — complements existing deactivate.
5. **Optional:** DB trigger or application check logging warning if `COUNT(role='master') > 1`.
6. **Optional:** structured audit log table keyed by `panel_admins.id` for compliance.

---

## VPS runtime verification

**Host:** `192.168.125.116`  
**Script:** `.keys/prompt47_vps_verify.sh`  
**Date:** 2026-09-09

| Test | Result |
|------|--------|
| `panel_admins` schema | PASS — `role` ENUM, `active` present |
| Unauthenticated `dashboard` / `monitor.php` / `logs.php` | PASS — HTTP 302 |
| `php tests/panel_auth_role_test.php` | PASS — 6/6 |
| Master `operator_list` render | PASS |
| Admin `operator_list` | PASS — 403 / master-required message |
| Admin `referent_list` | PASS |
| Admin `provider_list` | PASS |
| `panelAuthLoginAllowed()` | PASS — true (active master present) |

**Note:** Full HTTP cookie login flow was not re-tested (master password not in repo); session-bootstrap CLI tests exercise the same deployed `index.php` authorization gates as browser requests.

---

## Files audited

| Area | Files |
|------|-------|
| Auth core | `web/includes/auth.php`, `web/includes/panel_auth_ui.php`, `web/includes/panel_migration.php` |
| Entry points | `web/index.php`, `web/monitor.php`, `web/logs.php` |
| Helpers | `web/includes/helpers.php` (session, CSRF, IP) |
| OAuth | `web/includes/oauth2.php` |
| Providers | `web/includes/providers_ui.php` |
| Schema / installer | `schema.sql`, `delta-transit-install.sh` |
| Design / prior reports | `docs/reports/PROMPT-23-design.md`, PROMPT-25, PROMPT-29, PROMPT-32, PROMPT-46 |
| Tests added | `tests/panel_auth_role_test.php`, `tests/panel_render_as_role.php` |

---

## Acceptance criteria

| Criterion | Status |
|-----------|--------|
| All role-related code paths audited | ✓ |
| All admin-related tables documented | ✓ (`panel_admins` only) |
| Master/admin behavior verified | ✓ (code + VPS) |
| Authorization matrix produced | ✓ |
| Privilege escalation paths reviewed | ✓ |
| VPS runtime verification completed | ✓ |
| Findings documented in this report | ✓ |

---

## Answer to the audit question

> Does Proxy_Email currently implement real role-based authorization, and if so, what privileges does each role actually have?

**Yes — but minimally.** Roles are stored in the database, checked at runtime, and enforced server-side for **panel operator management only**. An `admin` can perform **all mail-proxy panel operations** (referents, external accounts, providers, logs, monitor). A `master` can do **everything an admin can**, plus **create and deactivate `admin` operators**. There is **no finer-grained RBAC** and **no resource ownership model** beyond this split.
