# PROMPT-61 — Test Mailbox Credentials Readiness

**Date:** 2026-09-10  
**Host:** `192.168.125.116` (checks run from VPS shell)  
**Method:** IMAP4_SSL login + INBOX SELECT; panel HTTP session for master admin

---

## Summary

**9/9 credentials ready** for Cursor automation (PROMPT-60 shadow, mail injection, panel HTTP). No plaintext passwords in this document.

---

## Readiness list

| # | Role | Address | Host:port | Check | Status | Env / file |
|---|------|---------|-----------|-------|--------|------------|
| 0 | Panel master | `admin` | `panel.testvps.loc:443` HTTP | session + logout | **OK** | `PANEL_PASS` (env only) |
| 1 | External referent (daemon acc 1) | `refint1@frona.ru` | `frona.ru:993` ssl | IMAP login | **OK** (INBOX 18) | `REFINT1_PASS` |
| 2 | External referent (daemon acc 2) | `refint2@bofoma.net` | `bofoma.net:993` ssl | IMAP login | **OK** (INBOX 0) | `REFINT2_PASS` |
| 3 | External client (tests) | `clientint1@frona.ru` | `frona.ru:993` ssl | IMAP login | **OK** | `CLIENT1_EXT_PASS` in `/etc/mail-proxy/test-automation-credentials.env` |
| 4 | External client (tests) | `clientint2@bofoma.net` | `bofoma.net:993` ssl | IMAP login | **OK** | `CLIENT2_EXT_PASS` in same file |
| 5 | Local referent | `refloc1@testvps.loc` | `mail.testvps.loc:993` ssl | IMAP login | **OK** | `REFLOC1_PASS` |
| 6 | Local referent | `refloc2@testvps.loc` | `mail.testvps.loc:993` ssl | IMAP login | **OK** | `REFLOC2_PASS` |
| 7 | Local client | `clientloc1@testvps.loc` | `mail.testvps.loc:993` ssl | IMAP login | **OK** (INBOX 2) | `CLIENTLOC1_PASS` |
| 8 | Local client | `clientloc2@testvps.loc` | `mail.testvps.loc:993` ssl | IMAP login | **OK** | `CLIENTLOC2_PASS` |

---

## Daemon DB encryption (verified)

```text
refint1@frona.ru   imap=ssl  smtp=ssl
refint2@bofoma.net imap=ssl  smtp=ssl
```

Matches implicit TLS on ports 993/465 (PROMPT-60 fix applied during PROMPT-61 setup).

---

## Credential storage on VPS (for Cursor sessions)

| Path | Mode | Notes |
|------|------|-------|
| `/etc/mail-proxy/test-automation-credentials.env` | 600 | External clients + local referents; **no panel password** |
| `/tmp/prompt61_secrets.env` | 600 | Extended set if present (panel, refint, clientloc) |

Load pattern (on VPS only, never commit):

```bash
source /etc/mail-proxy/test-automation-credentials.env
export PANEL_PASS='…' REFINT1_PASS='…' REFINT2_PASS='…' CLIENTLOC1_PASS='…' CLIENTLOC2_PASS='…'
```

Verification helper (untracked): `.keys/prompt61_verify_credentials.sh`

---

## Not verified in this pass

- SMTP AUTH outbound (IMAP sufficient for inbound poll / read-side automation)
- OAuth paths (accounts are `auth_type=plain`)
