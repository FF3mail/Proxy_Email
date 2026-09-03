# PROMPT 21 — Infrastructure verification report

**Project:** DELTA-transit (mail-proxy)  
**Host:** `192.168.125.116` (`mail.testvps.loc`)  
**Date:** 2026-09-03  
**Mode:** Read-only verification (no installs/modifications in this PROMPT’s verification pass)  
**Tooling:** SSH to designated DELTA-transit test VPS (no MCP SSH namespace available in session)

---

## 1. Per-requirement PASS/FAIL table

| # | Requirement | Verification | Result | Raw output (quoted) |
|---|-------------|--------------|--------|---------------------|
| 1 | OS: Debian 12 or Ubuntu 22.04/24.04 | `cat /etc/os-release` | **PASS** | `PRETTY_NAME="Ubuntu 24.04.4 LTS"`; `ID=ubuntu`; `ID_LIKE=debian`; `VERSION_CODENAME=noble` |
| 2 | Python ≥ 3.10 | `python3 --version` | **PASS** | `Python 3.12.3` |
| 3 | MariaDB on 127.0.0.1:3306 | `mysqladmin ping` | **PASS** | `mysqld is alive` |
| 4 | PHP-FPM present (8.1+) | `php -v`; `ls /etc/php/*/fpm/php.ini` | **PASS** | `PHP 8.3.6 (cli)`; ini `/etc/php/8.3/fpm/php.ini`; `memory_limit=512M`; `pm.max_children = 10` |
| 5 | Nginx present | `nginx -v` | **PASS** | `nginx version: nginx/1.24.0 (Ubuntu)` |
| 6 | Postfix + Dovecot | `postconf mail_version`; `doveconf -n \| head -1` | **PASS** | `mail_version = 3.8.6`; `# 2.3.21 (47349e2482): /etc/dovecot/dovecot.conf` |
| 7 | RAM for worst-case concurrency | `free -m` + §2 formula | **FAIL** (conditional) | See §2 — physical RAM 7941 MiB &lt; theoretical peak ~9475 MiB; fits RAM+swap |
| 8 | Disk for Maildir + logs | `df -h /var/vmail /var/log` | **PASS** | Both on `/`: Size 19G, Used 8.3G, **Avail 9.4G** (47%) |
| 9 | Outbound HTTPS to OAuth providers | `curl -sI` | **PASS** | Google `HTTP/2 404` (TLS OK); Yandex `HTTP/1.1 405` (TLS OK); Microsoft `HTTP/1.1 200 OK` |
| 10 | systemd ≥ 247 | `systemd-analyze --version` | **PASS** | `systemd 255 (255.4-1ubuntu8.17)` |

---

## 2. Explicit RAM headroom calculation

### Host memory (`free -m`)

```
               total        used        free      shared  buff/cache   available
Mem:            7941        1218        5863           5        1129        6723
Swap:           4095           0        4095
```

`/proc/meminfo`: MemTotal **8132556 kB (~7941 MiB)**; MemAvailable **6885212 kB**; SwapTotal **4194300 kB**.

### A. PHP-FPM pool (values found on this host)

| Parameter | Value found |
|-----------|-------------|
| `pm.max_children` | **10** (`/etc/php/8.3/fpm/pool.d/www.conf`) |
| `memory_limit` | **512M** (`php -r` / fpm ini; configure_limits / installer already applied) |

```
10 × 512 MiB = 5,120 MiB
```

### B. Daemon worst case (PROMPT 21 / Epic E formula — not rounded down)

```
IMAP_WORKER_COUNT = 20
Per-worker peak   ≈ 200 MiB  (150 MiB attachment × ~1.33 base64)
Daemon ceiling    = 20 × 200 MiB = 4,000 MiB
```

### C. MariaDB (config values found)

| Variable | Bytes | MiB |
|----------|------:|----:|
| `innodb_buffer_pool_size` | 134217728 | **128** |
| `sort_buffer_size` | 2097152 | 2.0 |
| `read_buffer_size` | 131072 | 0.125 |
| `read_rnd_buffer_size` | 262144 | 0.25 |
| `join_buffer_size` | 262144 | 0.25 |
| `thread_stack` | 299008 | ~0.285 |
| `tmp_table_size` | 16777216 | 16 |
| `max_heap_table_size` | 16777216 | 16 |

Per-connection worst-case sum (sort+read+rnd+join+thread_stack+tmp) ≈ **18.9 MiB × DB_POOL_SIZE(12) ≈ 226.9 MiB**  
MariaDB total ≈ **128 + 227 ≈ 355 MiB**

### D. Aggregate

| Component | MiB |
|-----------|----:|
| PHP-FPM (10 × 512M) | 5,120 |
| Daemon IMAP peak (20 × 200M) | 4,000 |
| MariaDB | ~355 |
| **Theoretical simultaneous peak** | **~9,475** |
| Host RAM | 7,941 |
| Host RAM + swap | 12,036 |

Peak exceeds physical RAM by ~1,534 MiB; fits within RAM+swap. Matches documented ~8 GB staging profile. Epic E (IMAP memory) remains a tracked conditional risk.

---

## 3. Go / no-go for PROMPT 22

### **CONDITIONAL GO** — proceed to PROMPT 22 on this host

| Verdict | Detail |
|---------|--------|
| 9/10 baseline rows PASS | OS, Python, MariaDB, PHP-FPM 8.3, Nginx, Postfix, Dovecot, disk, HTTPS, systemd |
| 1 row FAIL (RAM) | Worst-case peak ~9.5 GiB vs 7.9 GiB RAM — acceptable for staging with swap; not a production capacity claim |
| Epic 0 | PROMPT 20.1 **GO** on this host (iRedMail + usable Maildirs) |

Do **not** treat this as production sizing approval. For a strict PASS on the RAM row: resize to ≥12 GiB, or lower `PHP_FPM_MAX_CHILDREN` before install (infrastructure only).

---

## 4. If FAIL — infrastructure actions only

| Failure | Infrastructure action |
|---------|----------------------|
| RAM headroom | Resize VPS to ≥12 GiB RAM, **or** set `PHP_FPM_MAX_CHILDREN=6` before install (6×512=3072 → total peak ~7427 MiB within RAM), **or** accept swap-backed staging and keep Epic E open |
