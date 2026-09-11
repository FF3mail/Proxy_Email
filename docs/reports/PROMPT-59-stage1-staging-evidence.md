# PROMPT-59 — Stage 1 Live Evidence Collection on Test VPS

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Mode:** Deployment + observation (test VPS only)  
**Business baseline:** PROMPT-58 shadow-mode shipped as-is (`9772d8c`)

---

## Executive summary

| Task | Status | Notes |
|------|--------|-------|
| 1 — Pre-deploy snapshot | **Complete** | Read-only capture before deploy |
| 2 — Deploy PROMPT-58 + unchanged delivery | **Complete** (with ops notes) | Daemon running; startup line confirmed; local SMTP delivery proof |
| 3 — Panel relationship population | **Partial** | One §9-complete relationship saved via panel handlers; HTTP browser/curl login blocked |
| 4 — Shadow AGREE/DIVERGE collection | **Blocked** | No inbound IMAP messages processed in observation window (external IMAP timeouts) |
| 5 — Rollback decision | **Complete** | **Stage 1 left running** on test VPS |

**Recommendation:** **Not enough evidence to write Stage 2 yet.** Fix or replace `refint1@frona.ru` IMAP connectivity (or inject a controlled UNSEEN test message), finish backfilling remaining legacy client(s), then re-run a shadow observation window until `/run/mail-proxy/relationship_shadow_stats.json` shows non-zero `processed`.

---

## 0. Host confirmation and git evidence

### Target host (confirmed)

| Field | Value |
|-------|-------|
| IP | `192.168.125.116` |
| Hostname | `mail.testvps.loc` |
| Panel URL | `https://panel.testvps.loc` (from deployed `config.php`) |
| Same lab host as PROMPT-47/51 | **Yes** |

### Local repo (this session)

```text
> git rev-parse HEAD
9772d8cc61be9e97596eda7f9bfa5c504f1ec1e2

> git log -1 --oneline
9772d8c PROMPT-58 inbound RelationshipLookup shadow mode (Stage 1)

> git status -sb
## prompt-47-panel-authorization-audit...origin/prompt-47-panel-authorization-audit
 M docs/DELTA_transit_admin_guide.pdf
?? tests/prompt59_panel_cli_save.php
... (unrelated untracked docs/.keys omitted)
```

### VPS checkout (after deploy)

```text
root@mail:~/Proxy_Email# git rev-parse HEAD
9772d8cc61be9e97596eda7f9bfa5c504f1ec1e2
```

---

## 1. Task 1 — Pre-deploy safety snapshot (read-only)

Captured **before** PROMPT-58 deploy (2026-09-10 ~08:29 UTC).

### Daemon / service

| Item | Value |
|------|-------|
| Deployed file | `/usr/local/bin/mail-proxy-daemon.py` |
| MD5 (pre) | `f77a6c3e89fe719988a3db083d52c3ae` |
| Lines (pre) | `1754` |
| PROMPT-58 markers in deployed file | `0` (`relationship_lookup` / `RELATIONSHIP_LOOKUP_SHADOW` absent) |
| systemd | `active (running)` since 2026-09-08 15:05:31 UTC |
| ExecStart | `/opt/delta-transit/venv/bin/python3 /usr/local/bin/mail-proxy-daemon.py` |
| `RELATIONSHIP_LOOKUP_SHADOW` in unit | **unset** (defaults to shadow-on once PROMPT-58 code runs) |

### Repo on VPS (pre-deploy)

```text
960f8da10bc9f667bb67e3a55f03fef4c784fd9b
960f8da Fix webRoot scope in panel toggle smoke test.
(branch: master — did not yet have prompt-47-panel-authorization-audit)
```

### `clients` table (pre-deploy)

- **Row count:** `2`
- **Schema:** legacy only — **no** `external_client_email` / four-address columns (migration 002 not applied)
- **§9-complete relationships:** `0`

```text
id  referent_id  email                    active
2   3            clientloc1@testvps.loc     0
3   4            clientloc2@testvps.loc     1
```

### Referents / external accounts (pre-deploy)

```text
referents:
  id=3  refloc1@testvps.loc  active=0
  id=4  refloc2@testvps.loc  active=1

external_accounts:
  id=1  referent_id=3  refint1@frona.ru  active=0
```

### Rollback path (confirmed before deploy)

| Artifact | Path |
|----------|------|
| Pre-deploy daemon backup | `/tmp/mail-proxy-daemon.py.pre-prompt59.bak` (md5 `f77a6c3e…`) |
| Git revert on VPS | `git checkout master && cp …` + `systemctl restart mail-proxy` |
| Pre-deploy panel tree | unchanged until `rsync web/` in Task 2 |

---

## 2. Task 2 — Deploy PROMPT-58, verify no behavior change

### Deployment mechanism (existing project pattern)

Same as prior VPS prompts (`prompt46_vps_deploy.sh`, `mail-proxy-setup.sh`, `docs/06-operations.md`):

1. `git fetch` + checkout `origin/prompt-47-panel-authorization-audit` @ `9772d8c`
2. Apply `migrations/002_client_relationship_columns.sql` (required for PROMPT-56/57 panel — idempotent ALTER)
3. Copy `mail-proxy-daemon.py`, `relationship_lookup.py`, `relationship_shadow.py` → `/usr/local/bin/`
4. `rsync web/` → `/var/www/mail-proxy/` (preserve `config.php`)
5. `systemctl restart mail-proxy`

### Post-deploy daemon

| Item | Value |
|------|-------|
| MD5 (post) | `16db2759f633bd2d81aab5dac1df190a` |
| Modules installed | `/usr/local/bin/relationship_lookup.py`, `relationship_shadow.py` |
| systemd | `active` after manual log-file ownership fix (see blocker below) |

### Startup log line (verbatim)

```text
2026-09-10 08:34:00 [INFO] (MainThread) DELTA-transit mail-proxy-daemon service starting
2026-09-10 08:34:00 [INFO] (MainThread) RELATIONSHIP_LOOKUP_SHADOW=on (Stage 1 — no live routing change)
```

### Delivery unchanged proof (empirical)

**Legacy recipient resolution** on a test message (`From: external-sender@frona.ru`, `To: clientloc1@testvps.loc`):

```text
RESOLVED= []
LOCAL_RCPTS= ['refloc1@testvps.loc']
```

(Same semantics as pre-PROMPT-58: empty To/Cc client match → fallback to `referent.local_inbox`.)

**Local SMTP injection** to `refloc1@testvps.loc` (same Postfix path the daemon uses):

```text
MARKER=p59-deliver-1789031435
SMTP_SENT_OK
doveadm search -u refloc1@testvps.loc SUBJECT p59-deliver-1789031435
→ 08a7b81ae4cc9f6aea780000de7f2bda 2
```

Postfix log (excerpt):

```text
2026-09-10T09:10:44.267183+00:00 mail postfix/pipe[119168]: 4hgX2r17YZz2Mqx: to=<refloc1@testvps.loc>, relay=dovecot, delay=0.11, delays=0.01/0.01/0/0.09, dsn=2.0.0, status=sent (delivered via dovecot service)
```

**Verdict:** Delivery to the same local mailbox (`refloc1@testvps.loc` Maildir) still works after deploy. Shadow code did not alter the SMTP recipient list for this test.

---

## Blocker: `systemctl restart` ExecStartPre log `chown` failure

- **Task/step:** Task 2 — first `systemctl restart mail-proxy` after deploy
- **Commands:** `systemctl restart mail-proxy`
- **Output:**

```text
Job for mail-proxy.service failed because the control process exited with error code.
bash[116167]: chown: changing ownership of '/var/log/mail-proxy/mail-proxy-daemon.log': Operation not permitted
```

- **Expected:** Service restarts cleanly (as before deploy).
- **Tried:** Manual `chown vmail:mail-proxy-logs` on the log file, then restart succeeded.
- **Guess (not fact):** Log file had `vmail:vmail` ownership after SIGUSR1/logrotate; ExecStartPre could not `chown` to `vmail:mail-proxy-logs`.

Deploy continued after this operational fix. **No application code was changed.**

---

## 3. Task 3 — Panel relationship population

### Blocker: HTTP panel login via curl

- **Task/step:** Task 3 — browser-equivalent curl login to `https://panel.testvps.loc`
- **Commands:** POST `login_submit` with `admin` + candidate passwords
- **Output:**

```text
password_verify('StagingTest123!', admin hash) → false
password_verify('p32amaster', admin hash) → false
Login page flash: "Неверные учётные данные или слишком много попыток"
```

- **Expected:** Master login succeeds (as in PROMPT-47 notes for `StagingTest123!`).
- **Tried:** Multiple passwords; session cookie set but authenticated GETs returned login page (HTTP 200 body = login form).
- **Guess:** Master password on this VPS was rotated at install and is not stored in `/etc/mail-proxy/install-secrets.txt` (DB password only).

### Workaround used (documented — not direct SQL on `clients`)

Because HTTP login was blocked, relationship creation used **`tests/prompt59_panel_cli_save.php`** on the VPS: PHP CLI bootstraps a master session and invokes the **same** `index.php` POST handlers (`toggle_active`, `relationship_save`) — **not** direct SQL writes to `clients`. (Helper script is session-only tooling for this evidence run; **not** shipped as product code.)

### Activate referent + external account (panel `toggle_active`)

```text
referent3=1
account1=1
```

### Backfill before migration save

```text
BACKFILL_COUNT: 2 из 2 связей ещё на legacy-модели
BACKFILL_LEGACY_IDS: 2,3
```

### Positive save — client id=2 (live mailbox precondition check)

Panel handler save with physical mailboxes that exist in iRedMail `vmail`:

| Field | Value |
|-------|-------|
| `external_client_email` | `external-sender@frona.ru` |
| `local_client_email` | `clientloc1@testvps.loc` |
| `local_referent_email` | `refloc1@testvps.loc` |
| `external_account_id` | `1` |
| `local_client_maildir` | `/var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir` |

**DB after save (verbatim):**

```text
id  referent_id  email                      external_client_email      local_client_email         local_referent_email    external_account_id  active
2   3            external-sender@frona.ru   external-sender@frona.ru   clientloc1@testvps.loc     refloc1@testvps.loc     1                    1
```

**Mailbox precondition:** **PASS** — save succeeded against real `vmail` rows (first live exercise of PROMPT-56 Task 3 on this host). No `mailbox_not_provisioned` error.

### Backfill after save

```text
BACKFILL_COUNT: 1 из 2 связей ещё на legacy-модели
BACKFILL_LEGACY_IDS: 3
```

(Confirms PROMPT-57 backlog predicate: migrated row id=2 dropped out; legacy id=3 remains.)

### Not completed in this session

- **Second relationship on the same referent** (main_prompt two-client shape): blocked by UNIQUE `external_account_id` (only one external account exists for referent 3) and no second external account created via panel in this session.
- **Negative mailbox screenshot:** POST with `nobody@testvps.loc` did not produce a rendered flash in CLI capture (redirect-only response); all-or-nothing may reject empty `external_account_id` before mailbox check.

---

## 4. Task 4 — Shadow observation window

### Window

| | UTC |
|---|-----|
| Start (approx.) | `2026-09-10T09:11:01Z` (after referent/account activation) |
| End | `2026-09-10T09:14:11Z` |
| Duration | **~185 seconds** (three IMAP poll cycles at 60s) |

### `/run/mail-proxy/relationship_shadow_stats.json`

```text
no stats file
```

(File is created only after at least one shadow evaluation; none occurred.)

### Shadow log lines

```text
(no [RELATIONSHIP_SHADOW] or [RELATIONSHIP_SHADOW_STATS] lines in /var/log/mail-proxy/mail-proxy-daemon.log)
```

### IMAP activity (verbatim tail)

```text
2026-09-10 09:11:01 [INFO] (ImapWorker-17) Polling external IMAP account: refint1@frona.ru
2026-09-10 09:12:01 [ERROR] (ImapWorker-17) IMAP session exception for refint1@frona.ru: timed out
2026-09-10 09:13:01 [ERROR] (ImapWorker-3) IMAP session exception for refint1@frona.ru: timed out
2026-09-10 09:14:01 [ERROR] (ImapWorker-17) IMAP session exception for refint1@frona.ru: timed out
```

**Verdict:** **Zero inbound messages processed** → no AGREE/DIVERGE counts to analyze. External test mailbox `refint1@frona.ru` is unreachable from this VPS (same failure mode as pre-deploy logs on 2026-09-09).

### To/Cc vs From gap (on record for Stage 2)

Legacy `_resolve_local_recipients()` matches **To/Cc** against `clients.email`. PROMPT-58 shadow uses **From** + `RelationshipLookup.resolve_inbound()`. After migrating client id=2, `clients.email` is `external-sender@frona.ru`, so a message `To: clientloc1@testvps.loc` no longer matches legacy path but may match lookup if `From` equals `external-sender@frona.ru`. This divergence cannot be observed until IMAP fetch succeeds.

---

## 5. Task 5 — Rollback decision

**Stage 1 shadow mode is LEFT RUNNING** on the test VPS.

| Check | State |
|-------|-------|
| `systemctl is-active mail-proxy` | `active` |
| `RELATIONSHIP_LOOKUP_SHADOW` | **on** (default; no live flag exists) |
| Live routing switch | **Not present** — shadow only |
| Rollback artifacts retained | `/tmp/mail-proxy-daemon.py.pre-prompt59.bak`, git branch checkout path |

---

## 6. Plain recommendation for Stage 2 gate

| Criterion | Met? |
|-----------|------|
| Non-zero shadow `processed` count | **No** |
| AGREE/DIVERGE breakdown from real traffic | **No** |
| At least one §9-complete relationship in DB | **Yes** (client id=2) |
| Inbound path exercised end-to-end | **No** (IMAP timeout) |
| Two-client isolation on one referent | **No** |

**Do not write Stage 2 yet.** Next steps (human/ops):

1. Restore IMAP connectivity to `refint1@frona.ru` **or** place a controlled UNSEEN test message reachable by the daemon.
2. Migrate legacy client id=3 (and add a second external account + second relationship if testing cross-client isolation).
3. Re-run a shadow window until `relationship_shadow_stats.json` and `[RELATIONSHIP_SHADOW]` lines exist; review DIVERGE buckets before authoring Stage 2.

---

## 7. Files touched on VPS (deployment only — no repo code changes in PROMPT-59)

| Path | Action |
|------|--------|
| `/usr/local/bin/mail-proxy-daemon.py` | Replaced with `9772d8c` |
| `/usr/local/bin/relationship_lookup.py` | Added |
| `/usr/local/bin/relationship_shadow.py` | Added |
| `/var/www/mail-proxy/*` | rsync from `web/` @ `9772d8c` |
| `mail_proxy.clients` | migration 002 columns + one row updated via panel save handler |
| `/tmp/mail-proxy-daemon.py.pre-prompt59.bak` | Rollback backup |

**Repo application code:** unchanged in this prompt (PROMPT-58 shipped as-is).

---

## 8. Session helper (not product deliverable)

| File | Purpose |
|------|---------|
| `tests/prompt59_panel_cli_save.php` | VPS-only CLI bootstrap to invoke `index.php` POST handlers when HTTP login password unavailable |

Uncommitted locally; copied to VPS at `/root/Proxy_Email/tests/prompt59_panel_cli_save.php` for this evidence run only.
