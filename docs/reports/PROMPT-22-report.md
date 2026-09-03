# PROMPT 22 — Installer dry-run report (clean continuous pass)

**Project:** DELTA-transit (mail-proxy)  
**Host:** `192.168.125.116` (`mail.testvps.loc`)  
**Date:** 2026-09-03  
**Branch / commit tested:** `prompt-22.1-nginx-conflict-check` @ `d12945649b4d1a3422d18d6ebee5f699fde5768d`  
**Panel URL:** `https://panel.mail.testvps.loc`  
**Full transcript on host:** `/root/prompt22_clean_run.log`

---

## 1. Installer transcript — phase PASS/WARN/FAIL

### `delta-transit-install.sh` — exit **0**

| Phase | Status | Notes / verbatim warnings |
|-------|--------|---------------------------|
| Preflight | **PASS** | Panel URL accepted; MariaDB root via socket (no password prompt); pip mirror Enter (default) |
| Packages/OS | **PASS** | All packages already installed |
| MariaDB/Schema/Secrets | **PASS** with **WARN** | `[WARN] Database already contains tables` — schema not re-imported; `db.conf` rewritten |
| Users/Groups | **PASS** | |
| Python | **PASS** | Existing venv reused; deps already satisfied |
| Web deployment | **PASS** with **WARN** | PHP lint warnings (see §2) |
| Postfix | **PASS** | Limits configured |
| Dovecot | **PASS** | |
| PHP | **PASS** | Detected PHP-FPM 8.3; `pm.max_children=10` |
| Nginx | **PASS** | TCP endpoint `127.0.0.1:9999`; vhost created; SSL_MODE=existing |
| Systemd | **PASS** with **WARN** | Unit installed; see ProtectFirmware warn |
| Validation | **PASS** | `No conflicting server_name found`; nginx -t OK |
| Audit | **PASS** | |

Final phase table (verbatim):

```
Preflight                 OK
Packages                  OK
Database                  OK
UsersGroups               OK
Python                    OK
Web                       OK
Postfix                   OK
Dovecot                   OK
PHP                       OK
Nginx                     OK
Systemd                   OK
Validation                OK
Audit                     OK
```

### `configure_limits.sh` — exit **0**

Piped input: empty MySQL password (socket auth), `--all-referents`, domain `testvps.loc`.

| Block | Result | Verbatim |
|-------|--------|----------|
| PRE-CHECKS | PASS | PHP-FPM 8.3 ini `/etc/php/8.3/fpm/php.ini` detected (no silent 8.1 miss) |
| Postfix | UPDATED / OK | `postfix check OK` |
| Dovecot | UPDATED / OK | `Dovecot syntax OK` |
| MariaDB | UPDATED / OK | `Updated mailbox rows: 0` |
| Nginx | UPDATED / OK | `client_max_body_size` updated in `mail-proxy.conf` (sites-available + conf.d) |
| PHP-FPM | UPDATED / OK | `pm.max_children=10` |

### `mail-proxy.service`

- `systemctl is-active`: **active**
- Status: `Active: active (running)` — Main PID python3 under `/opt/delta-transit/venv`
- Journal (representative):

```
[WARNING] (MainThread) No active referents found in database.
[INFO] ProxyDaemon operational: 20 IMAP workers, 20 SMTP workers, 0 referents watched
```

Repeated across restarts during validation/configure_limits.

### `verify-install-regression.sh` — exit **0**

```
[PASS] mail-proxy.service is active (running)
[PASS] nginx -t passes
[PASS] nginx service is active
[PASS] db.conf round-trip / quoting check
[PASS] db.conf is readable via configparser
[PASS] daemon refuses CHANGE_ME placeholder
[PASS] client_max_body_size 210M present in nginx config
=== Summary: 0 failure(s) ===
```

### CHANGE_ME regression (PROMPT 22 response format §4)

Scratch copy only (`/tmp/db.conf.changeme.scratch`); live `/etc/mail-proxy/db.conf` left intact.

```
db_pass = CHANGE_ME
PASS: [db.conf] Параметр 'password' содержит значение-заглушку 'CHANGE_ME'. ...
live conf not equal to CHANGE_ME rewrite (good)
systemctl is-active mail-proxy → active
```

---

## 2. Discrepancies (installer assumption vs host reality)

Do not fix here (feeds Epic F / follow-ups):

| # | Discrepancy | Evidence |
|---|-------------|----------|
| D1 | **`harden_maildir_permissions()` does `chmod o-rwx /var/vmail`** without ensuring ownership is `vmail:vmail`. On stock iRedMail (`root:root` + traversable 0755), this breaks `vmail` mailbox access. | PROMPT-20.1 finding; after intentional `chown vmail:vmail` pre-run, post-install: `vmail` can traverse, `www-data` cannot |
| D2 | **systemd unit key `ProtectFirmware` unknown** on systemd 255 | `/etc/systemd/system/mail-proxy.service:75: Unknown key name 'ProtectFirmware' in section 'Service', ignoring.` |
| D3 | **PHP `use PDO` / `use RuntimeException` lint warnings** | `PHP Warning: The use statement with non-compound name 'PDO' has no effect` in `providers_ui.php:4`, `helpers.php:41-42` |
| D4 | **`configure_limits.sh` requires interactive stdin** (MySQL password + mailbox list) | Automated via pipe; empty password works with socket auth; undocumented for CI |
| D5 | **`Updated mailbox rows: 0`** on `--all-referents` for `testvps.loc` | Quota update no-op (already applied or query matched 0 rows) |
| D6 | **Database already contains tables** on re-run | Idempotent WARN; new `db.conf` password generated each install run |
| D7 | **No active referents** after install | Expected on bare schema; daemon still runs |
| D8 | **Duplicate limit configuration** | Both installer phases and `configure_limits.sh` touch Postfix/Dovecot/PHP/Nginx |
| D9 | **iRedMail nginx `client_max_body_size 12m`** remains elsewhere until DELTA vhost/conf.d profile | DELTA files set 210M; regression check PASSed on DELTA paths |

### Pipefail-class audit (Part 3 step 2)

Instances of `$( … | … )` reviewed in `delta-transit-install.sh`:

| Location | Verdict |
|----------|---------|
| `check_nginx_server_name_conflict` `grep \| while` | **Fixed** (`\|\| scan_rc=$?`, fatal only if `scan_rc > 1`) — 5e44bb4 |
| `validate_nginx_conflicts` `grep \| while` | **Fixed** — 081e8be |
| `detect_php_fpm_socket` `grep … listen \| head \| sed \| tr` | **Fixed** (`\|\| true`) — 7659c21 |
| `getent group vmail \| cut` | Not same class — preceded by successful `getent` check; leave alone |
| `find … \| head` | find exits 0 on empty; leave alone |
| `printf \| sort -V \| tail` inside `if [[` | Not fragile to “no matches”; leave alone |
| Nested `realpath … \|\| echo ""` | Already guarded; leave alone |

**No further unguarded grep-exit-1 pipelines remain.**

---

## 3. `mail-proxy.service` reaches `active (running)`?

**Yes.** Confirmed by `systemctl status`, `verify-install-regression.sh`, and journal startup lines.

---

## 4. CHANGE_ME regression

**PASS** on scratch config; live `db.conf` never left on placeholder; service remained **active**.

---

## Go / no-go (PROMPT 22 itself)

### **GO** for treating this install as a successful Epic A installer observation pass

Installer completed Preflight→Audit with Validation OK; limits applied; regression script 0 failures; CHANGE_ME guard works.

**Caveats for Epic B:** resolve D1 (`/var/vmail` ownership vs harden) before assuming Maildir usability after every fresh iRedMail + installer combo; track D2–D3 as non-blocking hygiene.
