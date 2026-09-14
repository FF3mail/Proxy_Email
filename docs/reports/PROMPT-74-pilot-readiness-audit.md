# PROMPT-74 — Lab VPS pilot readiness audit

**Branch:** `prompt-74-pilot-readiness-audit` (from `origin/master` @ `7e627a4`)  
**Type:** Audit + controlled deploy-to-parity (no data wipe, no pilot activation)  
**Date:** 2026-09-14  
**Host:** `192.168.125.116` (`mail.testvps.loc`, panel `https://panel.testvps.loc`)

---

## 1. Pre-audit inventory (read-only, before changes)

### 1.1 Code commit vs `origin/master`

| Item | Value |
|------|-------|
| `origin/master` tip (pre-merge baseline for this PROMPT) | `7e627a4e5158aa8c94536e4d45da69f2fe4f0f74` |
| VPS `git -C /root/Proxy_Email rev-parse HEAD` | `9772d8cc61be9e97596eda7f9bfa5c504f1ec1e2` |
| VPS `git log -1 --oneline` | `9772d8c PROMPT-58 inbound RelationshipLookup shadow mode (Stage 1)` |

**Finding (critical):** The VPS repo was **not** at parity with `origin/master`. It predated PROMPT-63–73 entirely at the git level. Deployed daemon files in `/usr/local/bin/` were hand-copied intermediates (MD5s did not match master). **PROMPT-70 (provisioning path guard) and PROMPT-73/73.1 (per-referent mode overrides) had never been deployed to this host.**

Local uncommitted/stashed VPS repo state: modified `mail-proxy-daemon.py`, `relationship_shadow.py`, untracked `relationship_routing.py` and test scripts from prior live-test rounds (stashed as `prompt74-pre-parity-backup-*` before checkout).

### 1.2 Systemd drop-ins (verbatim)

File: `/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf`

```ini
[Service]
Environment=INBOUND_ROUTING_MODE=shadow
Environment=OUTBOUND_ROUTING_MODE=shadow
Environment=OUTBOUND_WATCH_MODE=referent_only
```

**Assessment:** Values match the documented safe global baseline (`shadow` / `shadow` / `referent_only`) and match PROMPT-67 §2c rollback endpoint ([PROMPT-67 report](PROMPT-67-outbound-watch-vps-evidence.md)). No stale `dual` or `relationship_only` leftover from PROMPT-63–66 test rounds. **Already clean — no drop-in edit performed.**

### 1.3 `systemctl status mail-proxy` (pre-audit)

```text
● mail-proxy.service - DELTA-transit Mail Proxy Daemon
     Loaded: loaded (/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf)
     Active: activating (auto-restart) (Result: exit-code) since Mon 2026-09-14 06:40:51 UTC
    Process: 286655 ExecStartPre=... chown vmail:mail-proxy-logs ... (code=exited, status=1/FAILURE)
```

**Finding:** Daemon was **not running**. Pre-existing log ownership (`vmail:vmail` on `/var/log/mail-proxy/mail-proxy-daemon.log`) blocked `ExecStartPre` — same class of issue documented in PROMPT-67 §1. Long uptime was not a concern; the service had been crash-looping.

### 1.4 Daemon startup log line (pre-audit)

No `INBOUND_ROUTING_MODE=...` startup line present — log file was empty (0 bytes) because the daemon could not start.

### 1.5 Database — `SHOW COLUMNS FROM referents` (pre-audit)

PROMPT-73 columns **absent**:

| Column | Present |
|--------|---------|
| `inbound_routing_mode` | **No** |
| `outbound_routing_mode` | **No** |
| `outbound_watch_mode` | **No** |

Migration `003_referent_mode_overrides.sql` had **never** been applied on this host.

### 1.6 Referent #1 overrides (pre-audit)

Could not query — columns did not exist. `SELECT` failed with `Unknown column 'inbound_routing_mode'`.

### 1.7 Lab relationships (pre-audit)

```text
id  referent_id  external_client_email   local_client_email        local_referent_email   active
1   1            clientint1@frona.ru     clientloc1@testvps.loc    refloc1@testvps.loc    1
2   1            clientint2@bofoma.net   clientloc2@testvps.loc    refloc2@testvps.loc    1
```

Both PROMPT-61 relationships **present and active**. Data intact.

### 1.8 Web panel (pre-audit)

`/var/www/mail-proxy/includes/referent_modes.php` — **MISSING** (panel predated PROMPT-73).

---

## 2. Deploy-to-parity actions (item 2)

**Not already at parity.** Actions taken (single coordinated sequence):

| Step | Action |
|------|--------|
| Git | `git stash -u`, `git checkout 7e627a4` (detached HEAD at merge commit) |
| Panel | `rsync -a web/ /var/www/mail-proxy/` preserving `config.php` |
| Daemon | Copied `mail-proxy-daemon.py`, `relationship_routing.py`, `relationship_shadow.py`, `relationship_lookup.py` → `/usr/local/bin/` with `root:vmail` `0750` |
| Compile | `/opt/delta-transit/venv/bin/python3 -m py_compile /usr/local/bin/mail-proxy-daemon.py` |
| Pip | `/opt/delta-transit/venv/bin/pip install -r requirements.txt` (no new packages required) |

**Post-deploy MD5 (deployed on VPS):**

| File | MD5 |
|------|-----|
| `mail-proxy-daemon.py` | `1d215083be6211fa8962b22b0b031fc2` |
| `relationship_routing.py` | `d029fd55836f7e2b043904e220356d28` |
| `relationship_shadow.py` | `be56baa762e15dbb9823f70269e0ca37` |
| `relationship_lookup.py` | `d585f7d1c7a7d40b31982df1f6ccbcd4` |

VPS now includes PROMPT-70 provisioning guard (panel) and PROMPT-73/73.1 daemon + panel mechanism.

---

## 3. Migration application (item 3)

**Not already applied.** Ran:

```bash
mysql mail_proxy < /root/Proxy_Email/migrations/003_referent_mode_overrides.sql
```

**Result:** All three columns added successfully:

```text
inbound_routing_mode   ENUM('legacy','shadow','relationship_live') NULL
outbound_routing_mode  ENUM('legacy','shadow','relationship_live') NULL
outbound_watch_mode    ENUM('referent_only','dual','relationship_only') NULL
```

**Idempotency:** Second run emitted `SELECT 1` for each guarded `ALTER` (no errors). Schema matches `schema.sql` `referents` definition.

**Post-migration referent rows (existing data preserved):**

```text
id  username                      active  inbound  outbound  watch
1   Васильев Василий Васильевич   1       NULL     NULL      NULL
```

No overrides set — correct for readiness-only scope.

---

## 4. Systemd drop-in cleanup (item 4)

**Already clean.** Drop-in contents unchanged from pre-audit (§1.2). Explicit `Environment=` lines match the safe baseline; removing the drop-in would be equivalent but unnecessary. No leftover `dual`/`relationship_live`/`relationship_only` test modes from PROMPT-63–67 rounds.

| Prior test round | Setting used | Still present? |
|------------------|--------------|----------------|
| PROMPT-63–65 inbound/outbound shadow | `shadow`/`shadow` | Yes (baseline) |
| PROMPT-66 dual watch test | `OUTBOUND_WATCH_MODE=dual` | **No** — rolled back |
| PROMPT-67 relationship_only fail-closed test | `relationship_only` (failed closed) | **No** — rolled back |
| PROMPT-67 §2c rollback endpoint | `referent_only` | **Yes** (current) |

---

## 5. Restart performed? (item 5)

**Yes — once**, after deploy + migration + log-perms fix (steps 2–3). Rationale:

- New code and panel files required daemon reload.
- Migration 003 required before daemon could use override columns.
- Pre-existing `chown` failure had to be fixed (`chown vmail:mail-proxy-logs`).

```bash
chown vmail:mail-proxy-logs /var/log/mail-proxy/mail-proxy-daemon.log
systemctl daemon-reload && systemctl restart mail-proxy
```

**Post-restart:** `systemctl is-active mail-proxy` → `active` (since `2026-09-14 06:45:31 UTC`).

---

## 6. Post-verification (item 6)

### 6.1 Daemon startup (verbatim)

```text
2026-09-14 06:45:32 [INFO] (MainThread) INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=referent_only (requested=referent_only) RELATIONSHIP_LOOKUP_SHADOW=on
2026-09-14 06:45:32 [INFO] (MainThread) [REFERENT_EFFECTIVE_MODES] referent_id=1 inbound=shadow outbound=shadow watch=referent_only
2026-09-14 06:45:32 [INFO] (MainThread) ProxyDaemon operational: 20 IMAP workers, 20 SMTP workers, global OUTBOUND_WATCH_MODE=referent_only, 1 referent watches, 0 relationship maildir paths
```

Shadow mode confirmed. No `relationship_live` enabled. Per-referent effective modes match globals (NULL overrides → inherit).

### 6.2 Lab relationships

```text
id  referent_id  local_client_email        active
1   1            clientloc1@testvps.loc    1
2   1            clientloc2@testvps.loc    1
```

Unchanged. `php tests/panel_relationship_path_guard_test.php` on VPS:

```text
OK: lab relationship #1 re-save accepted
OK: lab relationship #2 re-save accepted
```

### 6.3 Panel PHP suites (on VPS)

| Suite | Result |
|-------|--------|
| `panel_referent_modes_test.php` | **OK** |
| `panel_relationship_status_test.php` | **10/10 OK** |
| `panel_relationship_path_guard_test.php` | **7/7 OK** |

Referent #1: all three override columns **NULL** in DB; computed effective modes = global defaults (`shadow`/`shadow`/`referent_only`); daemon startup effective matches computed → `pending_restart=false`.

### 6.4 Pilot activation

**Not performed.** No override set to `relationship_live`. PROMPT-75 scope explicitly deferred.

---

## 7. Known baseline snapshot (for PROMPT-75)

> **Dated:** 2026-09-14T06:45:32Z (post single restart)  
> **Host:** `192.168.125.116` (`mail.testvps.loc`)

| Field | Value |
|-------|-------|
| **Code commit** | `7e627a4e5158aa8c94536e4d45da69f2fe4f0f74` (`Merge pull request #18` — PROMPT-73 + PROMPT-73.1) |
| **Migration 003** | Applied; idempotent re-run confirmed |
| **Systemd drop-in** | `/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf` → `INBOUND_ROUTING_MODE=shadow`, `OUTBOUND_ROUTING_MODE=shadow`, `OUTBOUND_WATCH_MODE=referent_only` |
| **Daemon startup log** | `INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=referent_only (requested=referent_only) RELATIONSHIP_LOOKUP_SHADOW=on` |
| **Referent #1 overrides** | `inbound_routing_mode=NULL`, `outbound_routing_mode=NULL`, `outbound_watch_mode=NULL` |
| **Referent #1 effective modes** | `inbound=shadow`, `outbound=shadow`, `watch=referent_only` (daemon log: `[REFERENT_EFFECTIVE_MODES] referent_id=1 ...`) |
| **Relationship A** | `id=1`, `clientloc1@testvps.loc` ↔ `clientint1@frona.ru`, `active=1` |
| **Relationship B** | `id=2`, `clientloc2@testvps.loc` ↔ `clientint2@bofoma.net`, `active=1` |
| **Routing mode** | Global shadow (inbound + outbound); referent_only watch |
| **Pilot cutover** | **Not started** — baseline only |

PROMPT-75 may cite this table as its starting point without re-deriving VPS state.

---

## Acceptance

| Criterion | Status |
|-----------|--------|
| No data dropped/truncated/reset | **PASS** |
| No `relationship_live` or non-default overrides set | **PASS** |
| Single restart only (after required changes) | **PASS** |
| Systemd drop-in documented; no silent removal | **PASS** (no removal needed) |
| Baseline snapshot produced | **PASS** |
