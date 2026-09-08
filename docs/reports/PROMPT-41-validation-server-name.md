# PROMPT-41 — fix `server_name mismatch` in validation-only mode

**Date:** 2026-09-08  
**Branch:** `prompt-41-validation-server-name` (from `master`, independent of PR #4/#5/#6)  
**Scope:** `delta-transit-install.sh` only (validation hostname / PHP-FPM hydration)

---

## Summary

`DELTA_VALIDATION_ONLY=1 ./delta-transit-install.sh` on an already-installed host failed with:

```text
[INFO] Nginx virtual host exists
[ERROR] Validation failed: server_name mismatch
```

**Verdict:** false-positive validation failure (validation logic bug). Nginx and `config.php` were consistent (`panel.testvps.loc`); the installer compared against an **empty** expected `server_name` because validation-only mode skips the Nginx phase where `NGINX_SERVER_NAME` is normally set.

---

## Reproduction steps

### Host

- **VPS:** `192.168.125.116` (`mail.testvps.loc`)
- **Panel URL in config:** `https://panel.testvps.loc`
- **Nginx vhost:** `/etc/nginx/sites-available/mail-proxy.conf`

### Host evidence (correct configuration)

```text
$ hostname -f
mail.testvps.loc

$ grep server_name /etc/nginx/sites-available/mail-proxy.conf | head -1
    server_name panel.testvps.loc;

$ grep APP_BASE_URL /var/www/mail-proxy/config.php
 * define('APP_BASE_URL', 'https://panel.testvps.loc');
```

### Reproduce `server_name mismatch` (requires PR #7 panel-auth fix to reach this check)

On `master`, validation-only fails earlier at panel auth (`PARAM_DB_PASS` empty). Using the PR #7 script (`prompt-40-validation-only-dbpass`) reproduces the reported error:

```bash
cd /root/Proxy_Email
DELTA_VALIDATION_ONLY=1 ./delta-transit-install-pr40.sh </dev/null
```

```text
[INFO] Phase: Validation
[OK] Panel auth: active master present
[INFO] Nginx virtual host exists
[ERROR] Validation failed: server_name mismatch
```

---

## Code path

| Step | Location | Function |
|------|----------|----------|
| Entry | `main()` ~3199 | `DELTA_VALIDATION_ONLY=1` → `phase_validation()` only |
| Nginx checks | ~3037 | `validate_nginx_server_name()` |
| Error | ~2900 | `fatal "Validation failed: server_name mismatch …"` |

Before fix, `validate_nginx_server_name()` grepped for:

```bash
grep -q "server_name ${NGINX_SERVER_NAME};" "$NGINX_SITE_AVAILABLE"
```

With `NGINX_SERVER_NAME=""` (never set — Nginx phase skipped), the pattern became `server_name ;`, which never matches the real vhost line `server_name panel.testvps.loc;`.

---

## Compared values

| Source | Variable / field | Value on test VPS |
|--------|------------------|-------------------|
| Installer state (broken) | `PARAM_APP_URL` | `""` (Preflight skipped) |
| Installer state (broken) | `NGINX_SERVER_NAME` | `""` (set only in `phase_nginx()` → `extract_app_hostname()`) |
| **Expected after fix** | `NGINX_SERVER_NAME` | `panel.testvps.loc` (from `APP_BASE_URL` in `config.php`) |
| **Actual nginx** | `server_name` in `mail-proxy.conf` | `panel.testvps.loc` |
| Deployed app config | `APP_BASE_URL` | `https://panel.testvps.loc` |

**Conclusion:** expected and actual hostnames match; validation failed because the installer never loaded the expected value in validation-only mode.

---

## Root-cause analysis

| Hypothesis | Result |
|------------|--------|
| Incorrect installer state | **Yes** — `NGINX_SERVER_NAME` unset when validation-only skips Nginx phase |
| Stale configuration | No — nginx and `config.php` agree |
| Hostname/domain mismatch on host | No — both use `panel.testvps.loc` |
| Nginx parsing issue | No — grep pattern is correct when `NGINX_SERVER_NAME` is set |
| Validation logic bug | **Yes** — validation assumed in-memory install state without hydrating from deployed config |
| Another cause | Secondary: `PHP_FPM_SOCKET_DETECTED` would be empty next (same skip-Nginx pattern) |

**Classification:** validation logic bug (false positive), not a host misconfiguration.

---

## Implemented fix

Hydrate install-time variables from **deployed** configuration when validation-only skips earlier phases:

1. **`read_app_base_url_from_config()`** — parse `APP_BASE_URL` from `${WEB_ROOT}/config.php`
2. **`resolve_param_app_url()`** — use `PARAM_APP_URL` if set (full install), else read `config.php`
3. **`ensure_validation_app_hostname()`** — set `PARAM_APP_URL` + `NGINX_SERVER_NAME` via `extract_app_hostname()` when empty
4. **`detect_php_fpm_socket_from_vhost()` / `ensure_php_fpm_socket_detected()`** — read `fastcgi_pass` from deployed vhost when `PHP_FPM_SOCKET_DETECTED` is empty

**Call sites updated:**

- `validate_nginx_server_name()` — calls `ensure_validation_app_hostname()`; logs expected value; improved mismatch message shows actual nginx `server_name`
- `validate_nginx_conflicts()` — calls `ensure_validation_app_hostname()`
- `validate_php_fpm_socket()` — calls `ensure_php_fpm_socket_detected()`

**Safety:**

- Helpers no-op when variables are already set (fresh install / full re-run unchanged)
- Validation strictness unchanged — still requires exact `server_name ${NGINX_SERVER_NAME};` match
- Idempotent — reads existing files only; no config mutation

---

## Validation evidence

### AFTER fix (PR #41 + PR #7 combined on lab VPS)

Full validation-only run (PR #7 needed for panel auth; PR #41 for server_name):

```text
[INFO] Phase: Validation
[OK] Panel auth: active master present
[INFO] Resolved panel hostname for validation: panel.testvps.loc (from APP URL)
[INFO] server_name validation: expected 'panel.testvps.loc' from APP URL
[INFO] server_name matches APP_URL
[INFO] PHP-FPM endpoint resolved from nginx vhost: 127.0.0.1:9999
[INFO] PHP-FPM endpoint validated
[INFO] No conflicting server_name found
[OK] Validation phase completed
Validation                OK
[OK] Validation-only run completed
```

### Syntax

```bash
bash -n delta-transit-install.sh   # PASS
```

---

## Risk assessment

| Risk | Level | Notes |
|------|-------|-------|
| Fresh install regression | **Low** | `NGINX_SERVER_NAME` already set in Nginx phase; helpers return immediately |
| Upgrade / re-run | **Low** | Reads same `config.php` / vhost the installer wrote |
| Weakened validation | **None** | Same grep match; only fixes empty expected value |
| `config.php` missing / malformed | **Low** | Fatal with clear message (same as other validation prerequisites) |
| Dependency on PR #7 | **Info** | `master` alone still fails panel auth before server_name; merge PR #7 for end-to-end validation-only |

---

## Recommendation

**Merge PR #41** into `master`. The change is minimal, targeted, and verified on the lab VPS. It corrects a false-positive that blocks legitimate `DELTA_VALIDATION_ONLY=1` health checks on correctly configured hosts.

**Also merge PR #7** if not already merged — separate issue, but required for validation-only to pass panel auth on existing installs.

---

## PR

Independent PR into `master`: **PROMPT-41: fix server_name validation in validation-only mode** (`prompt-41-validation-server-name`).
