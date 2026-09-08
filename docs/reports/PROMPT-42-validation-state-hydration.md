# PROMPT-42 — Validation State Hydration Audit

**Date:** 2026-09-08  
**Branch:** `prompt-42-validation-state-hydration` (from `master`)  
**Scope:** `phase_validation()` and all functions called transitively during `DELTA_VALIDATION_ONLY=1`

---

## 1. Executive summary

A full audit of the Validation phase was performed to find dependencies on install-time variables that are empty when validation runs standalone:

```bash
DELTA_VALIDATION_ONLY=1 ./delta-transit-install.sh
```

**Findings:**

| Category | Count |
|----------|------:|
| Dependencies audited | 22 |
| Hydration bugs found (new) | **0** |
| Already fixed (PR #7 / PR #8) | 3 |
| No hydration needed (constants / deployed reads) | 19 |

PR #7 (`resolve_db_pass`) and PR #8 (`ensure_validation_app_hostname`, `ensure_php_fpm_socket_detected`) already address every genuine validation-only false-positive identified by this audit.

**No additional installer code changes are required.** Validation-only passes on the lab VPS; negative and idempotency tests confirm strictness is preserved.

**Recommendation:** merge this audit report PR for documentation; no functional delta-transit-install.sh change is needed beyond what is already on `master`.

---

## 2. Validation execution-path map

### Entry point

```
main()
  └─ DELTA_VALIDATION_ONLY=1
       ├─ require_root()
       ├─ initialize_phase_status()
       ├─ phase_validation()
       └─ render_report()
```

### `phase_validation()` call tree

```
phase_validation()
├─ start_mail_proxy()                    → systemctl restart mail-proxy.service
├─ verify_service_active()
│    └─ wait_for_service()               → systemctl is-active mail-proxy.service
├─ capture_validation_log_boundary()     → systemctl show ActiveEnterTimestamp
├─ verify_process_running()              → pgrep mail-proxy-daemon.py
├─ verify_log_exists()                   → $DAEMON_LOG (constant path)
├─ verify_database_login()
│    └─ validate_db_connectivity()       → read_db_pass_from_conf() + mysql
├─ validate_panel_auth()
│    ├─ ensure_panel_admins_table()      → resolve_db_pass() + mysql
│    ├─ panel_active_master_exists()     → resolve_db_pass() + mysql
│    └─ panel_master_exists()            → resolve_db_pass() + mysql
├─ verify_no_change_me()                 → grep CONFIG_DIR
├─ verify_no_placeholder_url()           → grep WEB_ROOT/config.php
├─ verify_runtime_log()                  → $DAEMON_LOG + $VALIDATION_LOG_SINCE_EPOCH
├─ validate_nginx_virtual_host()         → $NGINX_SITE_AVAILABLE / $NGINX_SITE_ENABLED
├─ validate_nginx_server_name()
│    └─ ensure_validation_app_hostname() → config.php → PARAM_APP_URL → NGINX_SERVER_NAME
├─ validate_php_fpm_socket()
│    └─ ensure_php_fpm_socket_detected() → nginx vhost fastcgi_pass
├─ validate_upload_limit()               → grep nginx conf files (constants)
├─ validate_nginx_conflicts()
│    └─ ensure_validation_app_hostname()
├─ validate_nginx_ssl()                  → $NGINX_SSL_CERT / $NGINX_SSL_KEY (constants)
└─ validate_nginx_test()                 → nginx -t
```

### Not in Validation scope (other phases only)

These `validate_*` functions exist but are **not** called by `phase_validation()`:

| Function | Called from |
|----------|-------------|
| `validate_web_distribution()` | `phase_web()` |
| `validate_public_url()` | `phase_web()` |
| `validate_postfix_configuration()` | `phase_postfix()` |
| `validate_dovecot_configuration()` | `phase_dovecot()` |
| `validate_nginx_configuration()` | `phase_nginx()` |
| `validate_service_source()` | `phase_systemd()` |
| `validate_change_me()` | `phase_database()` |

Skipped phases do not affect standalone Validation because those checks are not executed.

---

## 3. Complete dependency audit

| Validation dependency | Initialized during | Available in validation-only? | Runtime source of truth | Action |
|-----------------------|-------------------|------------------------------:|-------------------------|--------|
| `PARAM_DB_PASS` | Database (`generate_db_password`) | No (empty) | `/etc/mail-proxy/db.conf` | **hydrate** — PR #7 `resolve_db_pass()` |
| `NGINX_SERVER_NAME` | Nginx (`extract_app_hostname`) | No (empty) | `config.php` `APP_BASE_URL` | **hydrate** — PR #8 `ensure_validation_app_hostname()` |
| `PHP_FPM_SOCKET_DETECTED` | PHP/Nginx phases | No (empty) | nginx vhost `fastcgi_pass` | **hydrate** — PR #8 `ensure_php_fpm_socket_detected()` |
| `PARAM_APP_URL` | Preflight (`ask_public_url`) | No (empty) | `config.php` `APP_BASE_URL` | **hydrate** (side effect of hostname helper) — PR #8 |
| DB password (connectivity) | Database | Yes via `read_db_pass_from_conf()` | `db.conf` | **no change** — already reads deployed config |
| `DB_USER` / `DB_NAME` | Script constants | Yes | hardcoded `mail_proxy` | **no change** |
| `DAEMON_LOG` | Script constant | Yes | `/var/log/mail-proxy/mail-proxy-daemon.log` | **no change** |
| `CONFIG_DIR` | Script constant | Yes | `/etc/mail-proxy` | **no change** |
| `WEB_ROOT` / `config.php` | Script constant / Web phase | Yes (file on disk) | `/var/www/mail-proxy` | **no change** — checks read file directly |
| `NGINX_SITE_*` paths | Script constants | Yes | `/etc/nginx/sites-*` | **no change** |
| `NGINX_INCLUDE_CONF` | Script constant | Yes | `/etc/nginx/conf.d/mail-proxy.conf` | **no change** |
| `NGINX_SSL_CERT` / `NGINX_SSL_KEY` | Script constants | Yes (files on disk) | `/etc/ssl/certs|private/...` | **no change** |
| `VALIDATION_LOG_SINCE_EPOCH` | Set inside Validation | Yes (self-contained) | `systemctl show` timestamp | **no change** |
| `mail-proxy.service` | Systemd phase | Yes (deployed unit) | systemd | **no change** |
| Daemon process | Python/Systemd phases | Yes (running process) | `pgrep` | **no change** |
| `SSL_MODE` | Nginx TLS selection | No (stays `none`) | N/A for Validation | **no change** — not read by Validation |
| `PARAM_PIP_MIRROR` | Preflight | No | N/A | **no change** — not read by Validation |
| `PANEL_MASTER_*` | Preflight/Database | No | N/A | **no change** — Validation only queries DB |
| `NGINX_VHOST_CREATED` etc. | Nginx phase | No | N/A | **no change** — not read by Validation |
| Postfix/Dovecot settings | Postfix/Dovecot phases | N/A | N/A | **out of scope** — not in Validation |
| `DAEMON_TARGET` / `SYSTEMD_TARGET` | Python/Systemd | N/A | Audit phase only | **out of scope** |

---

## 4. Install-time vs validation-only availability

When `DELTA_VALIDATION_ONLY=1`, `main()` skips `run_all_phases()` and jumps directly to `phase_validation()`. Therefore:

- **Preflight** variables (`PARAM_APP_URL`, `PARAM_PIP_MIRROR`, `PANEL_MASTER_*`) are never set.
- **Database** variable `PARAM_DB_PASS` is never set (but `db.conf` exists on a deployed host).
- **Nginx** variables `NGINX_SERVER_NAME`, `PHP_FPM_SOCKET_DETECTED`, `SSL_MODE` are never set (but nginx/config files exist).

Validation must either:

1. Read deployed configuration directly (preferred for static paths and secrets on disk), or
2. Hydrate in-memory install state from deployed configuration before checks that compare against it (hostname, PHP-FPM endpoint, DB password for panel-auth mysql calls).

Before PR #7/#8, cases (2) failed with empty strings → false positives. After PR #7/#8, all case-(2) dependencies in Validation are covered.

---

## 5. Runtime source of truth

| Value | Canonical deployed source |
|-------|---------------------------|
| Database password | `/etc/mail-proxy/db.conf` `[db] db_pass` |
| Panel URL / hostname | `/var/www/mail-proxy/config.php` `APP_BASE_URL` |
| Nginx `server_name` | `/etc/nginx/sites-available/mail-proxy.conf` |
| PHP-FPM endpoint | same vhost, `fastcgi_pass` directive |
| Upload limit | `/etc/nginx/conf.d/mail-proxy.conf` + vhost |
| TLS cert/key paths | fixed paths under `/etc/ssl/` (written at install) |
| Daemon log | `/var/log/mail-proxy/mail-proxy-daemon.log` |
| Service state | `mail-proxy.service` via systemd |

---

## 6. Identified bugs / false-positive risks

### Fixed before this audit (on `master`)

| Bug | Symptom | Fix | PR |
|-----|---------|-----|-----|
| Empty `PARAM_DB_PASS` in panel-auth mysql | `Enter password:` / 1045 Access denied | `resolve_db_pass()` | #7 |
| Empty `NGINX_SERVER_NAME` | `server_name mismatch` | `ensure_validation_app_hostname()` | #8 |
| Empty `PHP_FPM_SOCKET_DETECTED` | Would fail next with empty socket check | `ensure_php_fpm_socket_detected()` | #8 |

### Reviewed — not bugs

| Pattern | Why safe in validation-only |
|---------|----------------------------|
| `validate_db_connectivity()` → `read_db_pass_from_conf()` | Always reads `db.conf`; does not use empty `PARAM_DB_PASS` |
| `verify_no_placeholder_url()` | Greps `config.php` on disk, not `PARAM_APP_URL` |
| `validate_upload_limit()` | Greps fixed nginx paths |
| `validate_nginx_ssl()` | Checks fixed cert paths on disk |
| `validate_nginx_conflicts()` | Calls `ensure_validation_app_hostname()` before using `NGINX_SERVER_NAME` |
| Empty `SSL_MODE` | Not referenced in Validation |
| `render_report()` with empty `PARAM_APP_URL` | Cosmetic only; Validation status still correct |

### Minor consistency note (no change made)

`validate_db_connectivity()` uses `read_db_pass_from_conf()` while panel-auth uses `resolve_db_pass()`. Behaviour is identical when `PARAM_DB_PASS` is empty (validation-only). Unifying would be cosmetic only; not required for correctness.

---

## 7. Implemented changes

**None.** The audit found no additional hydration bugs beyond PR #7 and PR #8, which are already merged to `master`.

---

## 8. Tests performed

### A. Validation-only on deployed VPS (`192.168.125.116`)

```bash
DELTA_VALIDATION_ONLY=1 ./delta-transit-install.sh
```

```text
[OK] Panel auth: active master present
[INFO] Resolved panel hostname for validation: panel.testvps.loc (from APP URL)
[INFO] server_name matches APP_URL
[INFO] PHP-FPM endpoint resolved from nginx vhost: 127.0.0.1:9999
[INFO] PHP-FPM endpoint validated
[OK] Validation phase completed
[OK] Validation-only run completed
```

Exit code: **0**

### B. Normal installation path

Code review: hydration helpers return immediately when install-time variables are already set (`NGINX_SERVER_NAME`, `PHP_FPM_SOCKET_DETECTED` non-empty; `resolve_db_pass()` prefers `PARAM_DB_PASS` during Database phase). Fresh-install behaviour unchanged.

### C. Negative validation

Temporarily changed nginx vhost:

```diff
- server_name panel.testvps.loc;
+ server_name wrong.example.com;
```

Result:

```text
[ERROR] Validation failed: server_name mismatch (expected 'panel.testvps.loc', nginx has 'wrong.example.com')
```

Validation correctly failed; config restored; subsequent validation passed.

### D. Idempotency (repeat validation)

Two consecutive `DELTA_VALIDATION_ONLY=1` runs both exited **0** with identical successful hydration logs. No configuration mutation observed.

---

## 9. Negative-test results

| Test | Expected | Result |
|------|----------|--------|
| Wrong nginx `server_name` | Fail with expected vs actual | **PASS** — detected mismatch |
| Restore correct config | Pass validation | **PASS** |

---

## 10. Idempotency results

| Run | Exit | Outcome |
|-----|------|---------|
| 1 | 0 | Validation completed |
| 2 | 0 | Validation completed (identical hydration log) |

Hydration re-reads deployed config on each invocation; no persistent installer state is written.

---

## 11. Risk assessment

| Risk | Level | Notes |
|------|-------|-------|
| Missed hydration bug | **Low** — full call-tree audit completed | All globals read in Validation traced |
| Over-hydration / weakened checks | **None** — no new code | Existing helpers preserve strict grep/mysql checks |
| Regression on fresh install | **N/A** | No code changes |
| Documentation drift | **Low** | This report records intentional non-changes |

---

## 12. Recommendation for merge

**Merge PR (report only)** to document the audit and confirm Validation hydration is complete on `master`.

**Do not** add further hydration machinery without new evidence of a false positive — the current implementation is sufficient for standalone Validation on a deployed DELTA-transit host.
