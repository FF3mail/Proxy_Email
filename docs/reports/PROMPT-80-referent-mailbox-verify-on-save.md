# PROMPT-80 — Referent card: verify external and local mailboxes on save

**Issue:** [#40](https://github.com/FF3mail/Proxy_Email/issues/40)  
**Branch:** `prompt-80-referent-mailbox-verify-on-save` (from `origin/master`)  
**Scope:** Panel only (`web/`). No daemon poll/send changes.

---

## 1. Context

Operators configure four mailbox types on the Referent card. Before this work:

| Mailbox | Save path | Prior check |
|---------|-----------|-------------|
| External Referent | `account_save` / `handleAccountSave` | Format only — no IMAP/SMTP login |
| External Client | `relationship_save` | `FILTER_VALIDATE_EMAIL` only |
| Local Referent | `referent_save` + `resolveReferentMaildir` | Maildir resolve (partial) |
| Local Client / local referent on relationship | `relationship_save` | `activePhysicalMailboxExists()` |

Misconfigured external credentials surfaced only later in daemon logs (see PROMPT-60 encryption/port mismatches).

---

## 2. Architecture

### Central module

`web/includes/mailbox_verify.php` — single entry point used by save handlers:

| Function | Role |
|----------|------|
| `verifyExternalReferentMailbox()` | IMAP + SMTP authenticated probe (plain or XOAUTH2) |
| `verifyExternalClientMailbox()` | DNS MX (A/AAAA fallback) + SMTP `RCPT TO` (no DATA) |
| `verifyLocalPhysicalMailbox()` | Active physical row in iRedMail `vmail.mailbox` |
| `mailboxVerifyAccountNeedsProbe()` | Skip probe when edit does not change connection/auth fields |
| `mailboxVerifyResolveAuthSecret()` | Blank password on edit → decrypt stored `password_enc`; OAuth uses stored access token when present |
| `mailboxVerifySetHooks()` | Inject connect/DNS/local fakes for offline tests |

### Handler wiring (`web/index.php`)

1. **`handleAccountSave`** — before INSERT/UPDATE, if probe needed: resolve secret → IMAP login → SMTP login. Fail → flash + keep form/modal (via `panelRedirectPreferReferentCard` / `return_to=referent_view`). OAuth2 **without** access token skips the live login probe so create → Authorize OAuth2 still works; probe runs once a token exists and settings change.
2. **`handleRelationshipSave`** — local client/referent via `verifyLocalPhysicalMailbox()`; external client via `verifyExternalClientMailbox()` (fail closed on DNS/port-25 unavailability).
3. **`handleReferentSave`** — local inbox via `verifyLocalPhysicalMailbox()` before maildir resolve (reuses `referent.mailbox_not_found` copy for missing rows).

### Timeouts

- Connect / I/O: ~5s each (`MAILBOX_VERIFY_CONNECT_TIMEOUT`, `MAILBOX_VERIFY_IO_TIMEOUT`)
- Total budget per multi-hop probe: ~8s (`MAILBOX_VERIFY_TOTAL_BUDGET`)

Optional envelope for RCPT probes: `PANEL_MAILBOX_PROBE_MAIL_FROM` (default empty → `MAIL FROM:<>`).

### Security

- Operator flash messages use i18n codes only — never passwords/tokens.
- `mailboxVerifySanitizeLog()` redacts credential-like substrings before `writeLog`.
- Probe secrets are unset after use in the account save path.

---

## 3. i18n

New keys in `web/lang/en.php` and `web/lang/ru.php` under `mailbox_verify.*` covering:

- IMAP/SMTP connect, TLS, auth rejected, timeout
- MX missing, RCPT rejected / deferred, MAIL FROM rejected
- Network/DNS unavailable (port 25 / resolver)
- Local mailbox missing / vmail unavailable
- Password required / decrypt failed / OAuth token messaging

---

## 4. Test matrix

Command: `php tests/panel_mailbox_verify_test.php`  
All I/O is scripted via `mailboxVerifySetHooks` — **no live Gmail/Yandex/Outlook**.

| # | Case | Expected |
|---|------|----------|
| 1 | Plain IMAP+SMTP login success | OK |
| 2 | IMAP auth rejected | `imap_auth_rejected`, hop=imap |
| 3 | SMTP auth rejected after IMAP OK | `smtp_auth_rejected`, hop=smtp |
| 4 | IMAP connect timeout | `timeout` |
| 5 | OAuth2 XOAUTH2 IMAP+SMTP | OK |
| 6 | Client MX + RCPT 250 | OK |
| 7 | No MX / A/AAAA | `mx_missing` |
| 8 | RCPT 550 | `rcpt_rejected` |
| 9 | Port 25 connection refused | `network_unavailable` (fail closed) |
| 10 | DNS hook returns false | `network_unavailable` |
| 11 | Local mailbox present / missing | OK / `local_missing` |
| 12 | Needs-probe heuristics | create/change/password vs unchanged |
| 13 | OAuth without token | `skip_probe` |
| 14 | Log/message secret scrubbing | no plaintext secrets |
| 15 | RCPT 451 greylist | `rcpt_deferred` (distinct) |

**Result (local run):** `RESULT: all OK` (23 assertions).

---

## 5. Acceptance mapping

| Criterion | Status |
|-----------|--------|
| Branch from master | Done |
| Invalid external Referent credentials rejected with IMAP vs SMTP message | Done |
| External Client without MX / rejected RCPT fails explicitly | Done |
| Non-existent local mailboxes rejected on referent + relationship paths | Done |
| Passwords/secrets stripped from errors/logs | Done |
| Automated tests offline / CI-safe | Done |
| Report at `docs/reports/PROMPT-80-…` | Done |

---

## 6. Residual risks

1. **Outbound port 25** — many VPS providers block it. Client existence checks then fail closed with `mailbox_verify.network_unavailable`. Operators must allow outbound 25 from the panel host, or accept that external-client saves cannot complete until network policy allows the probe.
2. **Greylisting** — temporary 4xx on `RCPT TO` yields `rcpt_deferred` (save refused). Retry later; not treated as proof of existence.
3. **OAuth create-before-authorize** — first save without access token skips login probe. A later edit that changes connection fields (or any probe trigger once a token exists) re-runs IMAP/SMTP auth.
4. **TLS certificate validation** — production connects verify peer certificates; broken chains fail as connect/TLS errors (intentional).
5. **Catch-all MX** — some domains accept any RCPT; probe can false-positive “exists”.
6. **No auto-provisioning** — panel still never creates iRedMail mailboxes.

---

## 7. Files touched

| File | Change |
|------|--------|
| `web/includes/mailbox_verify.php` | **New** verification module |
| `web/index.php` | Require module; probe in account / relationship / referent save |
| `web/lang/en.php`, `web/lang/ru.php` | `mailbox_verify.*` strings |
| `tests/panel_mailbox_verify_test.php` | Offline unit suite |
| `docs/reports/PROMPT-80-referent-mailbox-verify-on-save.md` | This report |

---

## Out of scope (unchanged)

- Daemon IMAP/SMTP poll/send logic
- Creating iRedMail mailboxes from the panel
- Dashboard internet LED (issue #39)
