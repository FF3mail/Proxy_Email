# PROMPT-61 — Test VPS Database Reset and Panel Setup

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Target host:** `192.168.125.116` (`https://panel.testvps.loc`)  
**Mode:** Operational reset + HTTP panel configuration (test VPS only)

---

## Executive summary

| Step | Status |
|------|--------|
| 1 — Clear application DB | **Complete** |
| 2 — Master admin `admin` | **Complete** (installer-equivalent seed; panel has no master-create HTTP form) |
| 3 — HTTP login as master | **Complete** |
| 4 — Referent «Васильев Василий Васильевич» | **Complete** (id=1) |
| 5 — Two clients + external accounts + relationships | **Complete** |
| 6 — Automation credentials on VPS | **Complete** (`/etc/mail-proxy/test-automation-credentials.env`, mode 600) |

**Credential hygiene:** No plaintext passwords in this report or in git. VPS helper scripts live under `.keys/` (untracked).

---

## 1. Database reset

Truncated (FK checks disabled briefly):

- `oauth_tokens`
- `clients`
- `external_accounts`
- `referents`
- `panel_admins`

Preserved: `oauth_providers` seed data.

---

## 2. Master admin bootstrap

The web panel **cannot** create a `role=master` account via HTTP (by design — installer only). After wipe, master was seeded using the same method as `delta-transit-install.sh` (`password_hash` + INSERT), then verified with an **HTTP** `login_submit` session (curl + CSRF + cookie jar).

| Field | Value |
|-------|-------|
| Username | `admin` |
| Role | `master` |
| HTTP login | **OK** |

---

## 3. Referent

| id | username | local_inbox | local_outbox (resolved) |
|----|----------|-------------|-------------------------|
| 1 | Васильев Василий Васильевич | `refloc1@testvps.loc` | `/var/vmail/vmail1/testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/Maildir` |

---

## 4. External accounts (referent id=1)

| id | email | IMAP | SMTP | encryption |
|----|-------|------|------|------------|
| 1 | `refint1@frona.ru` | `frona.ru:993` | `frona.ru:465` | **ssl** / **ssl** |
| 2 | `refint2@bofoma.net` | `bofoma.net:993` | `bofoma.net:465` | **ssl** / **ssl** |

(`ssl` on 993/465 — avoids PROMPT-60 STARTTLS timeout on implicit-TLS ports.)

---

## 5. Client relationships (two-client shape)

| Client label* | id | external_client | local_client | local_referent | external_account_id |
|---------------|-----|-----------------|--------------|----------------|---------------------|
| ООО «Альфа» | 1 | `clientint1@frona.ru` | `clientloc1@testvps.loc` | `refloc1@testvps.loc` | 1 |
| ООО «Браво» | 2 | `clientint2@bofoma.net` | `clientloc2@testvps.loc` | `refloc2@testvps.loc` | 2 |

\*Legal names are operational labels only; the schema stores email addresses, not company titles.

All relationship saves passed iRedMail mailbox preconditions (physical `vmail.mailbox` rows).

---

## 6. Test automation credentials (VPS only)

File: `/etc/mail-proxy/test-automation-credentials.env` (root, mode `600`).

Contains non-panel mail credentials for automated tests (external client inboxes, local referent inboxes, refint addresses). **Panel password is intentionally not stored in this file** — pass via env at script runtime.

---

## 7. Follow-on

This clean two-relationship layout unblocks remaining PROMPT-60 work: IMAP poll verification, shadow observation window, and cross-client isolation checks.
