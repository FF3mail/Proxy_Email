# PROMPT-61 — SMTP Verification via Real Send

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Host:** `192.168.125.116` (checks from VPS shell)  
**Mode:** Verification only — no panel/relationship changes, no daemon code changes

---

## Executive summary

| Task | Result |
|------|--------|
| 1 — SMTP login (refint accounts) | **Stored `ssl` on port 465 works** for both providers; **`tls`/STARTTLS on 465 fails** (same class as PROMPT-60 IMAP, but **already fixed in DB**) |
| 2 — Real send/receive (6 paths) | **6/6 sent and confirmed via IMAP** (~8–9 s to arrival) |
| 3 — Cleanup | Test messages **left in place**; **no new secrets file** created by this prompt |

**Panel fix:** **Not required** — `external_accounts.smtp_encryption` is already `ssl` for both refint rows (set during PROMPT-61 data reset).

---

## Task 1 — Direct SMTP login (daemon accounts)

Method: `smtplib` on VPS — try **stored** setting first (`SMTP_SSL` for `ssl`, plain + `starttls()` for `tls` on port 465), then alternate mode. Passwords from existing env files only (not printed).

| # | Account | Stored setting | Stored connects? | Actually works | STARTTLS on 465 | Fix needed? |
|---|---------|----------------|------------------|----------------|-----------------|-------------|
| 1 | `refint1@frona.ru` | `frona.ru:465` **ssl** | **Yes** | **465 / ssl** (`SMTP_SSL`) | **No** — `SMTPServerDisconnected` / timeout | **No** (already `ssl`) |
| 2 | `refint2@bofoma.net` | `bofoma.net:465` **ssl** | **Yes** | **465 / ssl** (`SMTP_SSL`) | **No** — same failure mode | **No** (already `ssl`) |

**Conclusion:** PROMPT-60’s *inference* about `smtp_encryption=tls` on 465 is **confirmed empirically** — port 465 on both providers expects **implicit SSL**, not STARTTLS. The database already holds `smtp_encryption=ssl` (applied during PROMPT-61 panel setup), so **no panel `account_save` was required** in this prompt.

Verbatim Task 1 log markers:

```text
refint1 stored_ok=True works=ssl port=465 alt=tls alt_ok=False alt_err=SMTPServerDisconnected: Connection unexpectedly closed: timed out
refint2 stored_ok=True works=ssl port=465 alt=tls alt_ok=False alt_err=SMTPServerDisconnected: Connection unexpectedly closed: timed out
```

---

## Task 2 — End-to-end send/receive

Unique subject token per message: `PROMPT61-SMTP-<path>-<unix_time>`. Confirmation: IMAP search on destination INBOX (first hit within ~90 s poll window).

| # | Path | SMTP used | Sent | Arrived | Time to arrival |
|---|------|-----------|------|---------|-----------------|
| 1 | `clientint1@frona.ru` → `refint1@frona.ru` | `frona.ru:465` ssl | Yes | Yes | **9.1 s** |
| 2 | `clientint2@bofoma.net` → `refint2@bofoma.net` | `bofoma.net:465` ssl | Yes | Yes | **8.8 s** |
| 3 | `refloc1@testvps.loc` → `clientloc1@testvps.loc` | `mail.testvps.loc:465` ssl | Yes | Yes | **8.5 s** |
| 4 | `refloc2@testvps.loc` → `clientloc2@testvps.loc` | `mail.testvps.loc:465` ssl | Yes | Yes | **8.5 s** |
| 5 | `refint1@frona.ru` → `clientint1@frona.ru` | `frona.ru:465` ssl | Yes | Yes | **9.3 s** |
| 6 | `refint2@bofoma.net` → `clientint2@bofoma.net` | `bofoma.net:465` ssl | Yes | Yes | **8.5 s** |

**Notes:**

- External client senders (`clientint*`) used **465/ssl** successfully (after env export fix in the verification script — first attempt failed on missing `REFINT*_PASS` export and unverified local cert, not on provider policy).
- Local paths (`refloc* → clientloc*`) required **relaxed TLS verify** for `mail.testvps.loc` self-signed cert in the test script only; auth and delivery succeeded on **465/ssl**.
- Outbound daemon direction (`refint* → clientint*`) confirmed — the path the daemon will use for external SMTP delivery.

---

## Task 3 — Cleanup

| Item | Action |
|------|--------|
| Test messages (6×) | **Left in INBOX** — identifiable by `PROMPT61-SMTP-` subject prefix; useful as real traffic for shadow observation; do not interfere with relationship matching (distinct From/To vs relationship addresses) |
| New secrets file from this prompt | **None created** — verification used existing `/etc/mail-proxy/test-automation-credentials.env` and `/tmp/prompt61_secrets.env` only |
| Post-run check | `prompt61_smtp_secrets.env` **does not exist** on VPS (`no_new_secrets_file=confirmed`) |
| Transient artifacts | `/tmp/prompt61_smtp_verify.log` — log only, no credentials |

---

## Credential hygiene

- No passwords appear in this report or in committed files.
- Verification helper: `.keys/prompt61_smtp_verify.sh` (untracked, env-driven).

---

## Stage 2 / follow-on

SMTP path for both refint accounts is **operationally confirmed** with stored `ssl` settings. Safe to proceed to the next prompt (panel/relationship work and shadow window with real inbound mail) without an SMTP encryption panel fix.
