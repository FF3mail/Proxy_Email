# PROMPT-34 — Fix D1: `/var/vmail` ownership check in `harden_maildir_permissions()`

**Project:** DELTA-transit (mail-proxy)  
**Branch:** `prompt-34-vmail-ownership`  
**Implementation commit:** `ffaa86c`  
**Date:** 2026-09-07  
**Host:** `192.168.125.116` (`mail.testvps.loc`)

---

## Scope

D1 (first flagged in `docs/reports/PROMPT-20.1-report.md`, deferred through PROMPT-23–33): `harden_maildir_permissions()` applied `chmod o-rwx /var/vmail` without verifying ownership. On iRedMail hosts where `/var/vmail` is `root:root` with mode `0755`, that chmod strips world-execute and breaks Dovecot/Postfix (`vmail` user) mailbox traversal — silent mail-delivery failure after a green installer run.

**Fix:** Before any permission change on `/var/vmail`, detect Dovecot mail user via `doveconf mail_uid` / `mail_gid` (numeric or name; fallback `vmail:vmail`), compare to `stat -c '%U:%G' /var/vmail`, and **abort with actionable guidance** if they differ. No auto-`chown`. `chmod` runs only when ownership matches.

**Files changed:**
- `delta-transit-install.sh` — `detect_dovecot_mail_store_owner()`, `verify_vmail_ownership_before_harden()`, called from `harden_maildir_permissions()` before `chmod`
- `verify-install-regression.sh` — ownership + traverse checks for `/var/vmail`

**Out of scope (unchanged):** `web/`, panel auth, TLS, `/var/mail` chmod logic (no ownership check added there).

---

## Implementation

| Step | Behavior |
|------|----------|
| 1 | `doveconf -h mail_uid` / `mail_gid` → resolve to `user:group` (e.g. `2000:2000` → `vmail:vmail`) |
| 2 | `stat -c '%U:%G' /var/vmail` |
| 3 | Mismatch → stderr remediation block + `fatal` (installer stops in Users/Groups phase, **before** Dovecot restart / delivery tests later) |
| 4 | Match → `chmod o-rwx /var/vmail` as before |

Check runs in `phase_users_groups()` via `harden_maildir_permissions()` — before `validate_www_data_has_no_maildir_access()` and before any later-phase Dovecot work.

---

## Live validation

**Method:** Deployed `ffaa86c` files to `/root/Proxy_Email` on Epic A test VPS; invoked `harden_maildir_permissions()` via sourced installer functions (subshell). Log: `/root/prompt34_validation.log` (partial) + `/tmp/p34n.out`, `/tmp/p34p.out`.

| Test | Setup | Expected | Result |
|------|-------|----------|--------|
| **Negative — bug reproduction** | `chown root:root /var/vmail; chmod 0755` | Abort with explicit ownership error; mode stays `755` | **PASS** — exit 1; message: `Refusing to harden /var/vmail until ownership is vmail:vmail (found root:root)`; mode unchanged |
| **Positive — happy path** | `chown vmail:vmail /var/vmail; chmod 0750` | Harden succeeds; `vmail` can traverse | **PASS** — exit 0; mode `750`; `runuser -u vmail test -x /var/vmail` OK |
| **Regression — wrong owner** | `root:root` before `verify-install-regression.sh` | `[FAIL]` on ownership | **PASS** |
| **Regression — correct owner** | `vmail:vmail` before `verify-install-regression.sh` | `[PASS]` ownership + traverse | **PASS** — 0 failures |

---

## Verdict

**GO** — D1 closed. Installer no longer silently breaks mail delivery by hardening a `root:root` `/var/vmail`. Operators must align ownership with Dovecot's mail user (or fix per iRedMail docs) before hardening proceeds.

**Operator note:** Standard iRedMail layout often keeps `/var/vmail` as `root:root` `0755`. This installer now **refuses** to chmod that layout; operators who want DELTA-transit hardening must `chown` to the Dovecot mail user (`vmail:vmail` on this host) deliberately before re-running the installer.

---

## PR

Independent of PR #2 (`prompt-24-panel-auth`). Merge target: `master`.
