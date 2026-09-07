# PROMPT-32 — Live validation of `prompt-24-panel-auth`

**Project:** DELTA-transit (mail-proxy)  
**Host:** `192.168.125.116` (`mail.testvps.loc`)  
**Panel:** `https://panel.mail.testvps.loc`  
**Branch:** `prompt-24-panel-auth`  
**Commit tested:** `95d1b2edb6b758abf4c68c27bfe2ff428a47bda0` (`95d1b2e`)  
**Date:** 2026-09-07  
**Mode:** Validation only (no repo edits during PROMPT-32)  
**Log:** `/root/prompt32_validation.log`

Manual test plan: `docs/reports/PROMPT-25-upgrade-safety.md` §3 (T1–T6).

---

## Baseline

Hypervisor snapshot revert is external to the guest (no `virsh`/`qm`). Scenario A used a manual DELTA-transit teardown (iRedMail untouched): removed `/etc/mail-proxy`, `/var/www/mail-proxy`, systemd unit, nginx vhost, certs; `DROP DATABASE mail_proxy`.

Scenario B simulated Epic A upgrade state: `DROP TABLE panel_admins` with `mail_proxy` and all other tables retained.

---

## Results matrix

| Test | Scenario A (fresh) | Scenario B (upgrade) | Notes |
|------|-------------------|---------------------|-------|
| **T1** Schema migration idempotency | **PASS** | **PASS** | Re-run shows `Active panel master present — skipping seed`; row count unchanged after second re-run |
| **T2** Web-only deploy before installer | n/a | **PASS** | `rsync web/` → yellow setup banner; `web_admin.log` auth lines; login after installer |
| **T3** Non-interactive abort | **PASS** | **PASS** | See T3 detail below |
| **T4** Legacy session handling | **PASS** | **PASS** | Legacy session → login redirect, no 500; inactive master → login redirect |
| **T5** Operator continuity | **PASS** | **PASS** | Create admin, login, deactivate → login redirect |
| **T6** OAuth regression | **PASS** | **PASS** | With session → `/index.php` (handler); without session → login |
| **monitor.php** (PHP-FPM) | **PASS** | **PASS** | HTTP 200; `shell_exec` disabled on host |

### T3 detail (non-interactive, no master)

| Invocation | Exit | Remediation text | Schema / credentials |
|------------|------|------------------|----------------------|
| `./delta-transit-install.sh </dev/null` (clean host) | 1 | **PARTIAL at 95d1b2e** — generic `Unhandled error at line 266` (fixed in PROMPT-33 Option A) | No `mail_proxy` DB created |
| `printf '%s\n\n' URL \| ./delta-transit-install.sh` (no TTY, no master) | 1 | **PASS** — full `abort_panel_master_no_tty` text on stderr | No `panel_admins` created |

After PROMPT-33 Option A, closed stdin shows an explicit preflight error instead of the generic ERR trap.

---

## Scenario A — Fresh install

| Check | Result |
|-------|--------|
| Checkout `95d1b2e` | **PASS** |
| `preflight_panel_master_readiness()` in Preflight | **PASS** |
| PROMPT-31 TLS → self-signed (`1`) | **PASS** — `SSL_MODE=self-signed` |
| Full install | **PASS** — all phases OK |
| Master seeded interactively (`p32amaster`) | **PASS** |

---

## Scenario B — Upgrade over Epic A

| Check | Result |
|-------|--------|
| `panel_admins` absent before upgrade | **PASS** |
| `mail_proxy` tables preserved | **PASS** |
| Interactive upgrade + master seed (`p32bmaster`) | **PASS** |
| Login after upgrade | **PASS** |

---

## Evidence (selected)

**Preflight + TLS (Scenario A):**
```
Checking panel master bootstrap path (before schema/credential changes)
Panel master credentials collected for Database phase seed
Choose TLS source [1/2] (default 1): 1
SSL_MODE=self-signed
```

**T2 yellow banner (Scenario B, web-only):**
- HTTP 200, `bg-yellow-100` setup message on login page
- `web_admin.log`: panel auth / migration lines

**T6 OAuth with session:**
```
HTTP/2 302
location: /index.php
```

**T3 piped (no TTY, no master) — stderr excerpt:**
```
[ERROR] No active panel master and no interactive TTY available.
Aborting in preflight before schema or credential changes.
Remediation (choose one):
  1. Run from a console session (or wrap with a pseudo-TTY):
  ...
```

---

## Verdict

**GO** — Live validation passed for fresh install and upgrade scenarios on commit `95d1b2e` for T1, T2, T4, T5, T6, and `monitor.php` under PHP-FPM. T3 is **PASS** for piped non-interactive runs; closed-stdin (`</dev/null`) messaging was closed in **PROMPT-33 Option A** (explicit preflight error instead of generic ERR trap). Safety outcomes were correct in all cases (exit 1, no auto-generated credentials, no unintended schema writes).

**Recommendation:** Merge `prompt-24-panel-auth` into `master` after human PR review.
