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

## 5. Step 4 — live verification (lab VPS, operator-approved 2026-09-21)

**Deploy commit:** `ac242137c98f48d2e3b25cc0e3b36765d33624e7`  
**Artifact checksums (sha256):**

| File | SHA256 |
|------|--------|
| `mail-proxy.service` | `979e6bea79cfaecaaa545089fef4e0b704762c1e6e7d77621da397e0ea99ac6c` |
| `logrotate-mail-proxy` | `bd985e025608d8659f6479bd39baef89ff74231b3e606c6aaceadb17138fd75c` |
| `tmpfiles.d-mail-proxy.conf` | `9e22feb55dd1ad8a09a1c631bc49acb69427151c30c9f8ba1507667c6e74d901` |

**Backups:** `/root/prompt78-backup-20260921121259`, `/root/prompt78-backup-complete-20260921122148`

### 5.1 Pre-restart checks (condition 1)

| Check | Result |
|-------|--------|
| `id vmail` | `uid=2000(vmail) gid=2000(vmail) groups=vmail,mail-proxy-crypto` — **not** in `mail-proxy-logs` |
| logrotate copy test (`create 0640 vmail mail-proxy-logs`) | `vmail:vmail` → rotate → `vmail:mail-proxy-logs`; www-data **YES** |
| PHP `writeLog` on `2750` dir (tmpfiles-seeded `web_admin.log`) | append OK; www-data read/write **YES** |
| `grep LOG_DIR web/` | reads only; `writeLog()` writes `web_admin.log` via `FILE_APPEND` — no daemon log creation from PHP |
| Daemon log open/reopen | `RotatingFileHandler` append; `SIGUSR1` → `_handle_log_reopen()` reopens `FileHandler` after logrotate |
| 11 skipped tests | All in `tests/test_relationship_lookup.py` — `skipped 'MySQL test database not available'` (no local MariaDB); unrelated to PROMPT-78 |
| Changes outside `/var/log/mail-proxy` required? | **NO** — proceed |

### 5.2 Snapshot BEFORE

| Field | Value |
|-------|-------|
| Effective modes referent #1 | *(not in log tail at snapshot time; restored after restart)* |
| UNSEEN refloc1 / clientloc1 | **6** / **0** |
| `postqueue -p` | empty |
| Daemon log | `0640 vmail:vmail` — www-data **NO** |

### 5.3 Live scenarios

| ID | Before | After | www-data `-r` | Notes |
|----|--------|-------|---------------|-------|
| **V1** restart | `0640 vmail:mail-proxy-logs` | `0640 vmail:mail-proxy-logs` | **YES** / **YES** | `systemctl is-active` = `active` |
| **V2** logrotate `-f` | `0640 vmail:mail-proxy-logs` (1 029 422 B) | `0640 vmail:mail-proxy-logs` (320 B → 2 131 B) | **YES** / **YES** | `SIGUSR1 received` in log; file grew after reopen |
| **V3** rm + restart | file removed | `0640 vmail:mail-proxy-logs` (3 665 B) | **YES** / **YES** | `active` |
| **V4** reboot | — | — | — | **SKIPPED** — `systemd-tmpfiles --cat-config` shows `/etc/tmpfiles.d/mail-proxy.conf` registered for boot |

**Deviation:** first V2 attempt hit logrotate `destination …-20260921 already exists` (midnight rotation earlier today); harness rolled back, then V2 re-run after removing dated archive — **not a fix defect**.

### 5.4 Snapshot AFTER + 10 min observe

| Field | Before → After |
|-------|----------------|
| Effective modes referent #1 | → `relationship_live` / `relationship_live` / `relationship_only` (**unchanged**) |
| UNSEEN refloc1 / clientloc1 | **6** / **0** → **6** / **0** |
| Daemon | `active` throughout; no new `[ERROR]` attributable to deploy |
| Final log perms | `0640 vmail:mail-proxy-logs`; www-data **YES** |

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
| Live V1–V3 on lab VPS | **ACCEPTED** |
| Live V4 (reboot) | **DEFERRED** — tmpfiles boot registration confirmed via `--cat-config` |
| Pilot modes / UNSEEN | **UNCHANGED** |
| **Overall PROMPT-78 closure** | **ACCEPTED** |
