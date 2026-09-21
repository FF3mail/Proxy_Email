# PROMPT-78 — Durable daemon log permissions (Issue #23)

**Date:** 2026-09-21  
**Host (diagnostics):** Lab VPS `192.168.125.116` (`mail.testvps.loc`)  
**Branch:** `prompt-78-daemon-log-permissions`  
**Issue:** [#23](https://github.com/FF3mail/Proxy_Email/issues/23)

---

## 1. Symptom

Panel monitoring page (`web/monitor.php`) shows `/var/log/mail-proxy/mail-proxy-daemon.log (недоступен)` when `is_readable()` is false for the PHP-FPM user (`www-data`). Recurred after daemon restart and daily log rotation (PROMPT-74, PROMPT-77.1, Issue #23).

---

## 2. Step 1 diagnostics (read-only, VPS)

### 2.1 Directory and file state

| Path | Mode | Owner:Group | www-data readable |
|------|------|-------------|-------------------|
| `/var/log/mail-proxy/` | `0770` (no setgid) | `vmail:mail-proxy-logs` | — |
| `mail-proxy-daemon.log` | `0640` | **`vmail:vmail`** | **NO** |
| `web_admin.log` | `0640` | `vmail:vmail` | NO |

File birth time: `2026-09-21 00:00:01 UTC` — matches daily logrotate window.

### 2.2 Web user and group

```
uid=33(www-data) gid=33(www-data) groups=33(www-data),2004(mail-proxy-logs),2005(mail-proxy-crypto)
mail-proxy-logs:x:2004:www-data
```

`vmail` is **not** in `mail-proxy-logs`.

### 2.3 systemd unit (`mail-proxy.service`)

| Directive | Value |
|-----------|-------|
| `User` / `Group` | `vmail` / `vmail` |
| `UMask` | *(not set)* |
| `ExecStartPre` | `touch` + `chmod 0640` + `chown vmail:mail-proxy-logs` *(runs as vmail)* |
| `ProtectSystem` | `strict` |
| `ReadWritePaths` | `/var/log/mail-proxy` … |

Journal evidence (crash-loop class):

```
chown: changing ownership of '/var/log/mail-proxy/mail-proxy-daemon.log': Operation not permitted
```

### 2.4 logrotate (`/etc/logrotate.d/mail-proxy`)

```
create 0640 vmail vmail
su vmail vmail
```

### 2.5 Daemon logging (code — unchanged)

`mail-proxy-daemon.py` `setup_logging()`:

- `os.makedirs(log_dir, mode=0o750)` if missing
- `RotatingFileHandler(LOG_FILE)` append mode, default umask
- New files: `vmail:vmail` (primary group)

### 2.6 Deterministic reproduction

| Mechanism | Repro | Result |
|-----------|-------|--------|
| **(b) logrotate `create`** | `chown vmail:vmail` + `chmod 0640` in `0770` dir | www-data **cannot** read |
| **(a) daemon create** | Same group semantics without setgid | www-data **cannot** read |
| **(c) ExecStartPre chown** | Journal on 2026-09-17 | **Fails** when file is `vmail:vmail` |
| **setgid fix proof** | `install -d -m 2750 vmail mail-proxy-logs`; `runuser -u vmail touch` | `vmail:mail-proxy-logs`; www-data **can** read |

**Primary root cause:** logrotate recreates `mail-proxy-daemon.log` as `vmail:vmail` nightly.  
**Secondary:** daemon creates `vmail:vmail` when file absent; `ExecStartPre chown` cannot repair as unprivileged `vmail`.

---

## 3. Step 2 — chosen fix

| Layer | Change |
|-------|--------|
| **tmpfiles.d** | New `tmpfiles.d-mail-proxy.conf` → `/etc/tmpfiles.d/mail-proxy.conf`: dir `2750`, daemon log `0640`, web log `0660`, all `vmail:mail-proxy-logs` |
| **systemd** | `UMask=0027`; `ExecStartPre=+/usr/bin/systemd-tmpfiles --create …` (root, idempotent); removed fragile bash `chown` |
| **logrotate** | Split stanzas; `create 0640/0660 vmail mail-proxy-logs`; `su vmail mail-proxy-logs` |
| **installers** | `delta-transit-install.sh` + `mail-proxy-setup.sh`: setgid dir, install tmpfiles, `systemd-tmpfiles --create` migration |

No changes to `mail-proxy-daemon.py`, routing/watch, DB, PHP.

### Proposal (not implemented)

Distinguish in `monitor.php:603` between “file missing” and “permission denied” instead of a single `"(недоступен)"` string.

---

## 4. Step 3 — tests

| Check | Result |
|-------|--------|
| `bash -n delta-transit-install.sh` | PASS |
| `bash -n mail-proxy-setup.sh` | PASS |
| `tests/validate_log_permissions_infra.sh` | PASS (where tools available) |
| `tests/test_log_permissions_infra.py` | PASS (5 tests) |
| Existing suites (`test_imap_fetch_seen`, `test_message_rebuild`, `test_outbound_watch`, `test_relationship_routing`, `test_referent_mode_overrides`, `test_relationship_lookup`, `test_relationship_shadow`) | PASS |

---

## 5. Step 4 — live verification (lab VPS)

**Status:** **BLOCKED** — operator confirmation required before daemon restart on live referent #1 pilot.

Planned scenarios after deploy:

| ID | Action | Pass criterion |
|----|--------|----------------|
| V1 | `systemctl restart mail-proxy` | `0640 vmail:mail-proxy-logs`; `sudo -u www-data test -r` |
| V2 | `logrotate -f /etc/logrotate.d/mail-proxy` | same |
| V3 | `rm` log + restart | same |
| V4 | host reboot | same (if scheduled) |

Pre/post: snapshot referent #1 effective modes, UNSEEN counts, daemon health.

---

## 6. Changed files

```
tmpfiles.d-mail-proxy.conf          (new)
mail-proxy.service
logrotate-mail-proxy
delta-transit-install.sh
mail-proxy-setup.sh
tests/test_log_permissions_infra.py (new)
tests/validate_log_permissions_infra.sh (new)
docs/DELTA-transit_anchor.md
docs/reports/PROMPT-78-daemon-log-permissions.md (new)
```

---

## 7. Verdict

| Area | Status |
|------|--------|
| Root cause identified + reproduced | **ACCEPTED** |
| Infra fix + static tests | **ACCEPTED** |
| Live V1–V4 on lab VPS | **BLOCKED** (awaiting operator restart approval) |
| **Overall PROMPT-78 closure** | **BLOCKED** until Step 4 live evidence attached |
