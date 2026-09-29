# PROMPT-80 — Referent card: verify external and local mailboxes on save

**Issue:** [#40](https://github.com/FF3mail/Proxy_Email/issues/40)  
**PR:** [#42](https://github.com/FF3mail/Proxy_Email/pull/42)  
**Branch:** `prompt-80-referent-mailbox-verify-on-save`  
**Scope:** Panel only (`web/`). No daemon, schema, or iRedMail provisioning changes.

---

## 1. Context

Operators configure four mailbox types on the Referent card. Before PROMPT-80, external Referent credentials were stored without a live IMAP/SMTP login, external Client addresses were format-only, and local checks were inconsistent across save paths.

**Pre-hardening credential-binding bug (closed in the hardening series):**  
`password_enc = COALESCE(?, password_enc)` meant that changing IMAP/SMTP host/port/encryption with a blank password kept the old secret bound to the **new** host — both for the login probe and for later daemon use. Section 2 closes that path at save time (reject / re-auth / revoke) before any network I/O or persist.

---

## 2. Architecture

### Module

`web/includes/mailbox_verify.php` — policy + probes:

| Area | Functions |
|------|-----------|
| Account policy | `mailboxVerifyPlanAccountSave`, `mailboxVerifyNormalizeConnectionIdentity`, `mailboxVerifyConnectionIdentityEquals` |
| Skip-when-idle | `mailboxVerifyReferentNeedsLocalCheck`, `mailboxVerifyRelationshipNeedsChecks` |
| Soft confirm | `mailboxVerifyRememberSoftFailure`, `mailboxVerifyConsumeSoftOverride` |
| Rate limit | `mailboxVerifyConsumeProbeSlot` (10 / 60s, session) |
| SSRF | `mailboxVerifyResolveTarget`, `mailboxVerifyAssertTargetAllowed`, CIDR matcher |
| Probes | `verifyExternalReferentMailbox`, `verifyExternalClientMailbox`, `verifyLocalPhysicalMailbox` |
| OAuth | `mailboxVerifyLoadExistingAccount`, `mailboxVerifyDeleteOauthTokens` |

Hooks (`mailboxVerifySetHooks`) throw unless `MAILBOX_VERIFY_ALLOW_HOOKS` is defined (tests only — never under `web/`).

### Handler wiring (`web/index.php`)

1. **`handleAccountSave`** — `mailboxVerifyPlanAccountSave` **before** any network/DB write:
   - Plain + identity changed + blank password → `reject_reauth` (nothing probed, nothing persisted).
   - Plain + unchanged identity + blank password → may decrypt stored secret for probe only.
   - OAuth + identity changed + stored token → save hosts, **delete** `oauth_tokens` in the same transaction, warn `oauth_reauth_required` (no token sent to new hosts).
   - Probe uses independent hop budgets; warnings (`plaintext_probe_skipped`, `oauth_token_expired`) allow save.
2. **`handleRelationshipSave`** — format → external-account + uniqueness (cheap DB) → conditional local/external checks → soft override for inconclusive client probes (`confirm_unverified_client=1` + session hash, single use).
3. **`handleReferentSave`** — local inbox verify only when `mailboxVerifyReferentNeedsLocalCheck` is true (skips when inactive or inbox unchanged).

### SSRF / connect pinning

- Resolve once (hook or `dns_get_record`); if **any** returned IP is blocked → `target_not_allowed`.
- Connect to the pinned IP; TLS `peer_name` / SNI remain the **original hostname**.
- Always blocked: loopback, link-local (incl. 169.254.169.254), unspecified, multicast, IPv4-mapped forms, etc.
- Private ranges blocked for `rcpt`; for `imap`/`smtp` blocked unless `PANEL_MAILBOX_PROBE_ALLOW_PRIVATE` or `PANEL_MAILBOX_PROBE_ALLOWED_TARGETS`.
- Ports: default `25,143,465,587,993,2525` for login; RCPT fixed to 25.

### Client probe severity

| Severity | Examples | Save behaviour |
|----------|----------|----------------|
| **hard** | RCPT 550/551/553, `mx_missing` | Refuse |
| **soft** | 4xx, other 5xx, MAIL FROM rejected, EHLO rejected, timeout, network, target_not_allowed | Refuse once; offer “Save anyway” for the same address |

### Timeouts

- Per-hop budget: `MAILBOX_VERIFY_HOP_BUDGET` (default **8s**). IMAP and SMTP do **not** share one deadline (worst case ≈ 16s for referent login + separate budget for client RCPT).
- `stream_set_timeout` uses remaining time to the hop deadline before each read.
- **Hostname resolution by PHP is not covered by these timeouts**; we resolve once ourselves (SSRF section) before connect.

---

## 3. Config constants (`web/config.php` comments)

| Constant | Default | Role |
|----------|---------|------|
| `PANEL_MAILBOX_PROBE_ALLOW_PRIVATE` | `false` | Allow private IPs for IMAP/SMTP |
| `PANEL_MAILBOX_PROBE_ALLOWED_TARGETS` | `[]` | Host/CIDR allow-list for private IMAP/SMTP |
| `PANEL_MAILBOX_PROBE_ALLOWED_PORTS` | `25,143,465,587,993,2525` | Login probe ports |
| `PANEL_MAILBOX_PROBE_ALLOW_PLAINTEXT_AUTH` | `false` | AUTH when `encryption=none` to non-loopback |
| `PANEL_MAILBOX_PROBE_MAIL_FROM` | `''` (`<>`) | Envelope for RCPT probe |
| `PANEL_MAILBOX_PROBE_EHLO_HOST` | FQDN / `mail-proxy.invalid` | EHLO/HELO name |
| `PANEL_MAILBOX_PROBE_DNS_CANARY` | `iana.org` | Distinguish NXDOMAIN vs resolver down |

---

## 4. Test matrix

Command: `php tests/panel_mailbox_verify_test.php`  
All I/O scripted via hooks — **no live sockets/DNS**.

Coverage includes: secret-binding (reject_reauth / stored / typed / OAuth revoke), SSRF (loopback, link-local, IPv4-mapped, private MX, mixed A records, bad ports, allow-list), control characters, IMAP AUTH PLAIN / literals / LOGINDISABLED / untagged / XOAUTH2 failure, SMTP LOGIN / unsupported / plaintext skip / STARTTLS-no-AUTH, client hard/soft + null MX + DNS canary helper, policy skip-when-inactive, independent hop budgets, rate limit, hooks guard (subprocess).

**Latest local run:** `RESULT: all OK` (123 checks).

---

## 5. Acceptance mapping

| Criterion | Status |
|-----------|--------|
| Blank password + any of 6 identity fields changed → reject, no connect, no DB write | Done |
| No probe to loopback/link-local | Done |
| Inactive / unchanged addresses skip network and vmail lookup | Done |
| Logs/messages never contain secrets or blocked target hosts | Done |
| Offline tests / `php -l` clean | Done |

---

## 6. Residual risks

1. **Outbound port 25** — soft-fail + optional “Save anyway” when blocked.
2. **Greylisting / catch-all MX** — soft or false-positive existence.
3. **Default null MAIL FROM** — some MXs reject `<>` (soft: `rcpt_mailfrom_rejected`).
4. **Opportunistic STARTTLS on RCPT probe** — **not** implemented; residual risk for cleartext RCPT on port 25.
5. **PHP DNS resolution latency** — outside hop I/O timeouts (mitigated by resolve-once + SSRF gate).
6. **OAuth create-before-authorize** — first save without token skips login probe; identity change revokes tokens and requires re-authorize.
7. **No auto-provisioning** of iRedMail mailboxes.

---

## 7. Files touched (hardening series)

| File | Change |
|------|--------|
| `web/includes/mailbox_verify.php` | Policy, SSRF, protocol hardening |
| `web/index.php` | Plan-before-probe, reorder, soft confirm, skip inactive |
| `web/includes/referent_card_ui.php` | Password hint; Save-anyway checkbox |
| `web/config.php` | Document probe constants |
| `web/lang/en.php`, `web/lang/ru.php` | New `mailbox_verify.*` keys |
| `tests/panel_mailbox_verify_test.php` | Expanded offline suite |
| `docs/reports/PROMPT-80-referent-mailbox-verify-on-save.md` | This report |
