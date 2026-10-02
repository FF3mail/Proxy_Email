# PROMPT-61 — Test Mailbox Credentials Readiness

**Date:** 2026-09-10  
**Host:** `192.0.2.10` (checks run from VPS shell)  
**Method:** IMAP4_SSL login + INBOX SELECT; panel HTTP session for master admin

---

## Summary

**9/9 credentials ready** for Cursor automation (PROMPT-60 shadow, mail injection, panel HTTP). No plaintext passwords in this document.

---

## Readiness list

| # | Role | Address | Host:port | Check | Status | Env / file |
|---|------|---------|-----------|-------|--------|------------|
| 0 | Panel master | `admin` | `panel.testvps.loc:443` HTTP | session + logout | **OK** | `PANEL_PASS` (env only) |
| 1 | External referent (daemon acc 1) | `referent-a@lab-a.example.test` | `lab-a.example.test:993` ssl | IMAP login | **OK** (INBOX 18) | `REFERENT_A_PASS` |
| 2 | External referent (daemon acc 2) | `referent-b@lab-b.example.test` | `lab-b.example.test:993` ssl | IMAP login | **OK** (INBOX 0) | `REFERENT_B_PASS` |
| 3 | External client (tests) | `client-a@lab-a.example.test` | `lab-a.example.test:993` ssl | IMAP login | **OK** | `CLIENT1_EXT_PASS` in `/etc/mail-proxy/<test-credentials-env>` |
| 4 | External client (tests) | `client-b@lab-b.example.test` | `lab-b.example.test:993` ssl | IMAP login | **OK** | `CLIENT2_EXT_PASS` in same file |
| 5 | Local referent | `refloc1@testvps.loc` | `mail.testvps.loc:993` ssl | IMAP login | **OK** | `REFLOC1_PASS` |
| 6 | Local referent | `refloc2@testvps.loc` | `mail.testvps.loc:993` ssl | IMAP login | **OK** | `REFLOC2_PASS` |
| 7 | Local client | `clientloc1@testvps.loc` | `mail.testvps.loc:993` ssl | IMAP login | **OK** (INBOX 2) | `CLIENTLOC1_PASS` |
| 8 | Local client | `clientloc2@testvps.loc` | `mail.testvps.loc:993` ssl | IMAP login | **OK** | `CLIENTLOC2_PASS` |

---

## Daemon DB encryption (verified)

```text
referent-a@lab-a.example.test   imap=ssl  smtp=ssl
referent-b@lab-b.example.test imap=ssl  smtp=ssl
```

Matches implicit TLS on ports 993/465 (PROMPT-60 fix applied during PROMPT-61 setup).

---

## Credential storage on VPS (for Cursor sessions)

| Path | Mode | Notes |
|------|------|-------|
| `/etc/mail-proxy/<test-credentials-env>` | 600 | External clients + local referents; **no panel password** |
| `<temp-secrets-file>` | 600 | Extended set if present (panel, referent-a, clientloc) |

Load pattern (on VPS only, never commit):

```bash
source /etc/mail-proxy/<test-credentials-env>
export PANEL_PASS='…' REFERENT_A_PASS='…' REFERENT_B_PASS='…' CLIENTLOC1_PASS='…' CLIENTLOC2_PASS='…'
```

Verification helper (untracked): `.keys/<helper-script>`

---

## Not verified in this pass

- SMTP AUTH outbound (IMAP sufficient for inbound poll / read-side automation)
- OAuth paths (accounts are `auth_type=plain`)
