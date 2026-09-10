# PROMPT-60 — IMAP Timeout Diagnosis + Full Panel Relationship Testing

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Target host:** `192.168.125.116` (`mail.testvps.loc`, panel `https://panel.testvps.loc`)  
**Starting point:** PROMPT-59 tip (`9772d8c`), Stage 1 shadow mode left running  
**Mode:** Diagnostic + panel HTTP testing (no daemon code changes)

---

## Executive summary

| Task | Status | Conclusion |
|------|--------|------------|
| 1 — IMAP timeout diagnosis | **Complete** | **Cause: wrong stored `imap_encryption` (`tls` / STARTTLS on port 993).** Server expects implicit SSL (`ssl` / `IMAP4_SSL`), matching Thunderbird on 993. Not network, not Cryptor, not stale password (login not yet reached). |
| 2 — Fix stored/config problem | **Ready** | Panel HTTP login confirmed (see §Task 3). Fix via `account_save`: `imap_encryption=ssl`, `smtp_encryption=ssl`. Not yet applied in this follow-up. |
| 3 — Real HTTP panel + two relationships | **Partial** | **HTTP login OK** for master `admin` (curl + cookie jar). Two relationships / negative test / CLI retirement **not done yet**. |
| 4 — Shadow observation window | **Blocked** | Depends on Task 2 IMAP fix + inbound test mail. Shadow stats file still absent. |

**Credential hygiene:** No plaintext passwords were printed in this report, committed to git, or written to VPS scripts. Diagnostic helpers live only under `.keys/` (untracked).

---

## Task 1 — IMAP timeout diagnosis

### Conclusion

**The cause is a stored encryption-mode mismatch: `external_accounts.imap_encryption='tls'` makes the daemon open a cleartext IMAP session on port 993 and call `STARTTLS`, but `frona.ru:993` only speaks implicit TLS (`IMAP4_SSL`).** The `starttls()` handshake never completes; after `IMAP_TIMEOUT=60` the daemon logs `IMAP session exception for refint1@frona.ru: timed out`. Thunderbird works because it uses SSL/TLS on port 993 (implicit TLS), not STARTTLS on an already-TLS port.

This is a **configuration/data problem**, not a VPS egress failure, not “IMAP server down”, and not a Cryptor bug.

### Step 1 — Network path from VPS (not the problem)

Stored target (from DB, secrets redacted):

```text
id=1  email=refint1@frona.ru  imap_host=frona.ru  imap_port=993  imap_encryption=tls
auth_type=plain  password_enc present (len=68)  active=1  referent_id=3
```

DNS + TCP from VPS:

```text
$ getent hosts frona.ru
176.122.23.13   frona.ru

$ python3 TCP connect frona.ru:993 (10s timeout)
TCP OK

$ openssl s_client -connect frona.ru:993 -quiet (10s)
depth=0 CN = www.frona.ru
( TLS handshake completes — implicit TLS on 993 )
```

**Stop condition for Step 1 not met:** path is open; not a firewall/egress blocker.

### Step 2 — Stored host/port/encryption vs Thunderbird expectation

| Field | Stored in DB | Thunderbird-equivalent on 993 |
|-------|--------------|----------------------------------|
| Host | `frona.ru` | Same (resolves `176.122.23.13`) |
| Port | `993` | Same |
| Encryption | **`tls` (STARTTLS after plain greeting)** | **`ssl` (implicit TLS / “SSL/TLS” in account settings)** |

Panel default for new accounts is `imap_encryption=ssl` (`web/index.php` account form defaults). Account id=1 was saved with `tls`, which selects the wrong daemon code path.

Daemon mapping (`mail-proxy-daemon.py`):

```613:622:mail-proxy-daemon.py
            if imap_encryption == 'ssl':
                mail = imaplib.IMAP4_SSL(
                    acc['imap_host'], int(acc['imap_port']), timeout=IMAP_TIMEOUT
                )
            elif imap_encryption == 'tls':
                mail = imaplib.IMAP4(
                    acc['imap_host'], int(acc['imap_port']), timeout=IMAP_TIMEOUT
                )
                tls_context = ssl.create_default_context()
                mail.starttls(ssl_context=tls_context)
```

### Step 3 — Manual imaplib reproduction + Cryptor (confirms Step 2, not credential)

Run on VPS (`/tmp/prompt60_imap_diagnose.sh`, `/tmp/prompt60_imap_modes.sh`):

```text
IMAP4_SSL on frona.ru:993 → connected, greeting ok
IMAP4 + STARTTLS on frona.ru:993 → TimeoutError: timed out   ← matches daemon
```

Cryptor decrypt of stored `password_enc` via daemon module:

```text
decrypt OK len=20 (password not printed)
```

**Credential login with owner-provided password not yet run** (awaiting `REFINT1_IMAP_PASS` via env). Given STARTTLS fails before `login()`, a successful manual login with `imap_encryption=ssl` would further confirm the encryption flag is the sole blocker; decrypt already works.

### Step 4 — Daemon log correlation (not needed beyond Step 2/3)

Recent daemon tail (verbatim pattern, continues every ~60s):

```text
2026-09-10 09:58:02 [INFO] (ImapWorker-17) Polling external IMAP account: refint1@frona.ru
2026-09-10 09:58:02 [ERROR] (ImapWorker-3) IMAP session exception for refint1@frona.ru: timed out
```

Same poll cycle also shows SMTP misconfiguration on port 465:

```text
2026-09-10 09:58:01 [ERROR] (SmtpWorker-7) SMTP delivery error for refint1@frona.ru: Connection unexpectedly closed: timed out
```

Stored `smtp_encryption=tls` on port `465` uses plain SMTP + STARTTLS in the daemon; port 465 typically expects `SMTP_SSL` (`smtp_encryption=ssl`). **Recommend fixing both IMAP and SMTP encryption flags in the same panel save.**

### Task 1 verdict (plain statement)

**The cause is `imap_encryption='tls'` on port 993 because the daemon performs STARTTLS on a port that only accepts implicit SSL, producing a hang until timeout — while Thunderbird uses implicit SSL and connects immediately.**

---

## Task 2 — Fix (stored/config) or infra blocker

### Required fix (in scope, not yet applied)

Via **panel HTTP** `account_save` for account id=1 / referent id=3 (empty password field → `COALESCE(?, password_enc)` preserves existing secret):

| Field | Current | Correct |
|-------|---------|---------|
| `imap_encryption` | `tls` | **`ssl`** |
| `smtp_encryption` | `tls` | **`ssl`** (port 465) |
| Other fields | unchanged | `frona.ru:993`, `frona.ru:465`, `auth_type=plain` |

Prepared script (env-only, **not committed**): `.keys/prompt60_panel_fix_account.sh` — requires `PANEL_PASS`.

### Not a code bug

No change to `mail-proxy-daemon.py`, `relationship_lookup.py`, or `relationship_shadow.py` is indicated. Enum values `ssl` vs `tls` are intentional; the wrong value was stored.

### Blocker (resolved for panel login)

Master panel password was provided out-of-band by the operator on 2026-09-10. HTTP login verified (§Task 3). **Task 2 fix (account encryption) still pending execution** via authenticated panel session.

| | |
|---|---|
| **Command** | `PANEL_PASS='…' bash /tmp/prompt60_panel_fix_account.sh` |
| **Output** | Not run in this follow-up (login only) |
| **Expected** | DB row shows `imap_encryption=ssl`, daemon poll succeeds on next cycle |
| **Tried (PROMPT-59)** | `StagingTest123!`, `p32amaster` → `password_verify` false |
| **Tried (PROMPT-60 follow-up)** | Operator-provided master password → **HTTP login OK** |

---

## Task 3 — Real HTTP panel login + two-relationship scenario

### Panel HTTP login verification (2026-09-10 follow-up)

**Result: SUCCESS** — real browser-equivalent HTTP session works with master account `admin` (id=1, role=`master`, active=1, created 2026-09-08).

Method: curl with `--resolve panel.testvps.loc:443:127.0.0.1`, cookie jar, CSRF from GET login form, POST `action=login_submit`. Password passed via **`PANEL_PASS` env var only** (not in scripts, report, or git). Helper: `.keys/prompt60_panel_login_test.sh` (untracked).

| Step | HTTP | Outcome |
|------|------|---------|
| GET `index.php?action=login` | 200 | CSRF token present |
| POST `login_submit` (`username=admin`) | 200 | **Authenticated** — logout link present, no red error flash |
| GET `index.php?action=dashboard` | 200 | Authenticated (not login form) |
| GET `index.php?action=relationship_backfill` | 200 | Authenticated; page title *Legacy backlog — миграция связей*; testids `backfill-count`, `backfill-table`, `backfill-migrate` present |

Verbatim markers from login response check:

```text
RESULT=OK
logout_link=yes
authenticated_content=yes
dashboard=authenticated
backfill=authenticated
login_has_logout=yes
login_has_error_flash=no
```

**Conclusion:** PROMPT-59 panel login blocker is **cleared**. The earlier failure was wrong/stale test passwords, not a broken auth stack. Authenticated panel automation (Task 2 fix, two relationships, negative mailbox form) can proceed via the same curl session pattern.

**Credential note:** The operator supplied the master password in chat for this test only. It is **not** recorded in this report, in committed files, or in VPS scripts.

### Remaining Task 3 work (not done in this follow-up)

| Item | State |
|------|-------|
| Fix account encryption via panel | Pending |
| Second external account + second relationship | Pending |
| Negative mailbox HTTP form (`nobody@testvps.loc`) | Pending |
| Retire `tests/prompt59_panel_cli_save.php` | Pending — retire after remaining panel tasks complete |

### Current DB relationship state (read-only)

**Clients:**

```text
id  referent_id  email                      external_client_email      local_client_email         local_referent_email    external_account_id  active
2   3            external-sender@frona.ru   external-sender@frona.ru   clientloc1@testvps.loc     refloc1@testvps.loc     1                    1
3   4            clientloc2@testvps.loc     NULL                       NULL                       NULL                    NULL                 1
```

**External accounts:** only id=1 (`refint1@frona.ru`, referent 3).

**Planned once login works:**

1. Fix account 1 encryption (Task 2).
2. Create **second external account** for referent 3 (panel `account_form` → `account_save`; second real mailbox credentials via env at use time).
3. Complete **second relationship** — candidate: migrate client id=3 under referent 4 **or** add second client under referent 3 with distinct `external_account_id` (satisfies UNIQUE on `external_account_id` and main_prompt two-client shape for one referent).
4. **Negative mailbox test** via HTTP form POST to `relationship_save` with a provisioned referent + account but `local_client_email=nobody@testvps.loc`; capture rendered flash:

   Expected message key: `relationship.error.mailbox_not_provisioned` →  
   *"Mailbox not provisioned in iRedMail — create it first, then retry ({email})"*

   (PROMPT-59 CLI POST with empty `external_account_id` did not reach this check.)

---

## Task 4 — Shadow observation window

### Status: blocked (IMAP still failing at report time)

```text
$ cat /run/mail-proxy/relationship_shadow_stats.json
(missing — file created only after at least one shadow evaluation)
```

No `[RELATIONSHIP_SHADOW]` lines in `/var/log/mail-proxy/mail-proxy-daemon.log` while IMAP polls time out.

### Planned after Task 2

1. Confirm daemon log shows successful IMAP login/select for `refint1@frona.ru`.
2. Run ~3-minute shadow window (or until UNSEEN processed); capture `relationship_shadow_stats.json` and log lines.
3. **Cross-client isolation** (requires two relationships from Task 3): deliver message to client A’s `local_client_email` and verify shadow lookup does not match client B’s relationship.

---

## Credential hygiene confirmation

| Check | Result |
|-------|--------|
| Passwords in this report | **None** (redacted / lengths only) |
| Passwords committed to git | **None** |
| VPS helper scripts | Use `PANEL_PASS` / `REFINT1_IMAP_PASS` env vars only (`.keys/`, untracked) |
| `prompt59_panel_cli_save.php` | No embedded secrets |

---

## Escalation — remaining items to finish PROMPT-60

1. ~~**`PANEL_PASS`**~~ — **resolved** (HTTP login verified 2026-09-10).
2. **`REFINT1_IMAP_PASS`** — real IMAP password for `refint1@frona.ru` (optional confirm after ssl fix; decrypt already OK).
3. **(Task 3)** Second external mailbox credentials if a second referent account must be real (or confirm a test-only second account is acceptable).
4. **(Task 4)** Send one test inbound message to `refint1@frona.ru` from a known `external_client_email` after IMAP connects.

Next steps (env on VPS session only; password not echoed):

```bash
export PANEL_PASS='…'   # operator-provided; not stored in repo
bash /tmp/prompt60_panel_fix_account.sh
export REFINT1_IMAP_PASS='…'   # optional verification
bash /tmp/prompt60_imap_login_test.sh   # after DB shows imap_encryption=ssl
# … panel two-relationship + negative test scripts …
# … shadow window + stats capture …
```

---

## Stage 2 gate

**Still not ready.** Fix account encryption via panel, complete two-relationship panel testing, and collect non-zero shadow stats with cross-client isolation evidence before Stage 2 live routing cutover.
