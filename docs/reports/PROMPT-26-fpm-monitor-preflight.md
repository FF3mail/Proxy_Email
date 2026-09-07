# PROMPT 26 — monitor.php FPM compatibility and installer preflight safety

**Project:** DELTA-transit (mail-proxy)  
**Branch:** `prompt-24-panel-auth`  
**Date:** 2026-09-04  
**Based on live validation of:** `1b1f0a395a1d704e60bd421cac906c5003328795`

---

## Root cause

1. **monitor.php HTTP 500:** `getDaemonStatus()` called `shell_exec()` for `systemctl`. On Ubuntu 24.04 + iRedMail, PHP-FPM `disable_functions` includes `shell_exec` (and `system`, `passthru`, `proc_open`, `posix_kill`). The call fatals under FPM → empty HTTP 500. CLI PHP is not subject to the same disable list, which is why CLI smoke tests could still render the page.

2. **Installer partial DB write:** `seed_panel_master()` aborted on `!installer_has_tty` only inside the Database phase — after `import_schema` / `write_db_config`. Fresh non-TTY runs left schema + empty `panel_admins`.

---

## Fix summary

| Area | Change |
|------|--------|
| `web/monitor.php` | Replace shell-based status with pid file + `/proc` reads; errors → `unknown` |
| `mail-proxy-daemon.py` | Write/remove `/run/mail-proxy/mail-proxy.pid` |
| `mail-proxy.service` | `RuntimeDirectory=mail-proxy` mode `0755` so www-data can read the pid |
| `delta-transit-install.sh` | `preflight_panel_master_readiness()` — abort or collect master creds **before** Database phase |

---

## Files changed

- `web/monitor.php`
- `mail-proxy-daemon.py`
- `mail-proxy.service`
- `delta-transit-install.sh`
- `docs/reports/PROMPT-26-fpm-monitor-preflight.md` (this file)

---

## Security impact

- **Positive:** Removes reliance on shell execution from the web panel under FPM.
- **Unchanged:** Auth gates, CSRF, IP allow-list, OAuth, Cryptor.
- **Pid file:** Mode `0644` under `/run/mail-proxy` (world-readable PID only — no secrets). Matches common practice for service status files.
- **Installer:** Still never auto-generates master passwords; non-TTY without existing active master fails early with remediation text.

---

## Validation plan (manual / VPS — not executed in this coding pass)

1. Deploy branch; `systemctl daemon-reload && systemctl restart mail-proxy`.
2. Confirm `/run/mail-proxy/mail-proxy.pid` exists and matches `systemctl show -p MainPID`.
3. Master login → `GET /monitor.php` → HTTP **200**, status badge not a 500.
4. `systemctl stop mail-proxy` → monitor shows inactive/unknown without 500.
5. Fresh piped install **without** `script`/TTY → abort in **Preflight**, no new `db.conf` / schema when starting from empty host.
6. Re-run with existing active master and no TTY → Preflight OK (master detected), install proceeds.
7. `bash -n delta-transit-install.sh`; `php -l web/monitor.php` on host.

---

## Commit message (proposed)

```
[PROMPT-26] Fix monitor.php under PHP-FPM and fail master seed in preflight.

Replace shell_exec daemon status with pid-file+/proc checks; abort non-TTY
master bootstrap before schema or credential changes.
```
