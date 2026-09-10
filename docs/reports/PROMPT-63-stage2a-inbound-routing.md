# PROMPT-63 — Stage 2a Controlled Inbound Relationship Routing

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Mode:** Stage 2a routing-decision migration only (no MIME transformation)

---

## 1. Objective and scope

Stage 2a implements **relationship-centric inbound routing**:

```text
external_account_id + normalized From
        ↓
RelationshipLookup.resolve_inbound()
        ↓
ClientRelationship.local_referent_email
        ↓
existing local SMTP delivery (original RFC822 unchanged)
```

### Stage 2a limitation (explicit)

> Stage 2a does **not** claim full customer-spec compliance for MIME/message transformation. It validates the new relationship-centric routing decision and physical delivery isolation only.

Attachment-only rebuild, outbound redesign, and customer-required IMAP DELETE/EXPUNGE for unknown senders remain **PROMPT-64+** scope.

---

## 2. Code paths changed

| File | Change |
|------|--------|
| `relationship_routing.py` | **NEW** — routing modes, live plan, validation, IMAP mark-seen policy |
| `mail-proxy-daemon.py` | `_deliver_to_local_smtp()` uses routing plan; returns `InboundProcessResult`; IMAP `\Seen` respects skip/fail paths |
| `tests/test_relationship_routing.py` | **NEW** — 15 unit tests for modes, live routing, divergence analysis |

**Not changed:** outbound `send_via_external_smtp`, `MaildirHandler`, `resolve_outbound`, crypto, schema, panel.

---

## 3. Routing modes

| Mode | Env value | Delivery | Shadow logging |
|------|-----------|----------|----------------|
| `shadow` | `INBOUND_ROUTING_MODE=shadow` (default) | Legacy To/Cc + inbox fallback | Yes (if `RELATIONSHIP_LOOKUP_SHADOW` on) |
| `legacy` | `INBOUND_ROUTING_MODE=legacy` | Legacy only | No |
| `relationship_live` | `INBOUND_ROUTING_MODE=relationship_live` | From-based lookup → `local_referent_email` | No (live path) |

### Safe default after deployment

Unset `INBOUND_ROUTING_MODE` → **`shadow`** (same as PROMPT-58 default behaviour).

### Enable live mode (explicit operator action)

```bash
mkdir -p /etc/systemd/system/mail-proxy.service.d
cat >/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf <<'EOF'
[Service]
Environment=INBOUND_ROUTING_MODE=relationship_live
EOF
systemctl daemon-reload
systemctl restart mail-proxy
grep INBOUND_ROUTING_MODE /var/log/mail-proxy/mail-proxy-daemon.log | tail -1
```

### Rollback (tested on VPS)

```bash
cat >/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf <<'EOF'
[Service]
Environment=INBOUND_ROUTING_MODE=shadow
EOF
systemctl daemon-reload
systemctl restart mail-proxy
```

Rollback verified: daemon log shows `INBOUND_ROUTING_MODE=shadow` after restart (2026-09-10 14:23:07 and 14:31:08 UTC). No code redeploy required.

---

## 4. Deployed VPS revision

| Item | Value |
|------|--------|
| Repo commit under test (local) | `8b7c142` (PROMPT-62 + PROMPT-63 worktree) |
| VPS `/usr/local/bin/mail-proxy-daemon.py` MD5 | `2aba00404683aa9ba3bfc0fa73e3d866` |
| VPS `/usr/local/bin/relationship_routing.py` MD5 | `bfee3bdb758346e700d8f785bcf0cadc` |
| Startup confirmation | `INBOUND_ROUTING_MODE=relationship_live` logged 2026-09-10 14:12:04 UTC |
| Service | `mail-proxy` active |

PROMPT-62 baseline daemon (`9772d8c`) was **replaced** before live tests.

---

## 5. Live routing contract

| Step | Implementation |
|------|----------------|
| Lookup key | `external_account_id` (polled account) + `normalize_email(From)` |
| Match | `RelationshipLookup.resolve_inbound()` |
| Local target email | `ClientRelationshipDTO.local_referent_email` |
| Provisioning check | `local_client_maildir` exists on disk (relationship validity) |
| Delivery | `_stream_file_via_smtp()` with original RFC822 file |
| Envelope MAIL FROM | `local_referent_email` (listed local mailbox) |

No To/Cc lookup. No `referent.local_inbox` fallback in `relationship_live`.

---

## 6. PROMPT-62 divergence analysis (mandatory)

PROMPT-62 baseline preserved: `processed=219`, `agree=217`, `diverge_lookup_only=2`, `errors=0`.

> **Semantic note:** PROMPT-58 `legacy_delivered` = pre-fallback To/Cc match (`bool(resolved)`), **not** “no physical delivery occurred.” Both divergence cases **did** receive physical local SMTP delivery via legacy inbox fallback.

### Divergence 1 — Relationship A (`clientint1@frona.ru` on account 1)

| Field | Value |
|-------|-------|
| External account ID | 1 (`refint1@frona.ru`) |
| Normalized sender | `clientint1@frona.ru` |
| Matched relationship ID | 1 |
| Four addresses | ext client `clientint1@frona.ru`, local client `clientloc1@testvps.loc`, local referent `refloc1@testvps.loc`, ext referent `refint1@frona.ru` |
| Expected customer target email | `refloc1@testvps.loc` |
| Expected physical Maildir | `/var/vmail/vmail1/testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/Maildir` |
| Legacy decision (pre-fallback) | **dropped** — To=`refint1@frona.ru` not in `clients.email` |
| Legacy fallback used? | **Yes** → `refloc1@testvps.loc` |
| Why `diverge_lookup_only`? | Lookup matched rel 1; legacy To/Cc path did not — **AGREE on routing intent, DIVERGE on legacy gate semantics** |
| Stage 2a correction | Live mode routes directly to `refloc1@testvps.loc` (same target as customer spec; coincidentally same as legacy fallback for this referent) |

### Divergence 2 — Relationship B (`clientint2@bofoma.net` on account 2)

| Field | Value |
|-------|-------|
| External account ID | 2 (`refint2@bofoma.net`) |
| Normalized sender | `clientint2@bofoma.net` |
| Matched relationship ID | 2 |
| Four addresses | ext client `clientint2@bofoma.net`, local client `clientloc2@testvps.loc`, local referent `refloc2@testvps.loc`, ext referent `refint2@bofoma.net` |
| Expected customer target email | `refloc2@testvps.loc` |
| Expected physical Maildir | `/var/vmail/vmail1/testvps.loc/r/e/f/refloc2-2026.09.09.12.26.56/Maildir` |
| Legacy decision (pre-fallback) | **dropped** |
| Legacy fallback used? | **Yes** → `refloc1@testvps.loc` (**wrong mailbox**) |
| Why `diverge_lookup_only`? | Lookup matched rel 2; legacy delivered to shared referent inbox instead of `refloc2` |
| Stage 2a correction | Live mode routes to `refloc2@testvps.loc` — **isolation fix** |

**Hard gate:** Expected local target is concretely identifiable for both divergences → **PASS**.

---

## 7. Unknown-sender behaviour (as implemented)

### Pre–Stage 2a (shadow/legacy)

- Lookup miss irrelevant; legacy always falls back to `referent.local_inbox`.
- IMAP message marked `\Seen` after successful local SMTP.
- **No** IMAP DELETE/EXPUNGE in codebase (PROMPT-51 audit confirmed).

### `relationship_live` (Stage 2a interim policy)

| Condition | Local delivery | IMAP `\Seen` | Legacy fallback |
|-----------|----------------|--------------|-----------------|
| Lookup match | Yes → `local_referent_email` | On SMTP success | **No** |
| Lookup miss | **No** | **Yes** (acknowledge/skip) | **No** |
| Lookup/validation error | **No** | **No** (retry) | **No** |

Interim unknown-sender policy: **skip local delivery, mark IMAP seen** — not customer-spec DELETE/EXPUNGE.

Log format: `[RELATIONSHIP_LIVE] no match account=… sender=… — skipped (no legacy fallback)`

**Stage 2a routing acceptance:** unknown sender does not enter legacy fallback in `relationship_live`.  
**Full customer-spec compliance:** NOT claimed (DELETE/EXPUNGE deferred).

---

## 8. Lookup-error behaviour

- Exception during `resolve_inbound` → `lookup_error` set, empty `local_rcpts`, log `[RELATIONSHIP_LIVE] lookup/validation error … fail closed`.
- IMAP message **not** marked `\Seen` (allows retry when DB recovers).
- **No** legacy fallback.

---

## 9. Controlled VPS test matrix

Topology: PROMPT-61 verified, not recreated.

| Case | Token | Injection | Account | From | Lookup | Target email | Physical Maildir evidence |
|------|-------|-----------|---------|------|--------|--------------|---------------------------|
| A | `PROMPT63-1789049938-CASE-A` / `PROMPT63FU-1789050485-CASE-A` | SMTP | 1 | `clientint1@frona.ru` | rel 1 | `refloc1@testvps.loc` | Watchdog `1789050556.M658670P132078.mail` in `/var/vmail/.../refloc1-.../Maildir/new/` at 14:29:16 UTC (consumed by existing outbound watchdog — not a Stage 2a defect) |
| B | `PROMPT63-1789049938-CASE-B` | SMTP | 2 | `clientint2@bofoma.net` | rel 2 | `refloc2@testvps.loc` | **FOUND** `/var/vmail/vmail1/testvps.loc/r/e/f/refloc2-2026.09.09.12.26.56/Maildir/new/1789050074.M944862P131120.mail` |
| C | `PROMPT63FU-1789050485-CASE-C` | IMAP append to refint2 INBOX | 2 | `clientint1@frona.ru` | **no match** | — | **Not in A or B Maildirs**; log: `no match account=refint2@bofoma.net sender=clientint1@frona.ru` |
| D | `MAILER-DAEMON@mail.frona.ru` (live traffic) | existing | 1 | unknown | **no match** | — | Skipped, no legacy fallback; multiple live log lines |

### Isolation proof

| Token | refloc1 Maildir | refloc2 Maildir |
|-------|-----------------|-----------------|
| CASE-B | absent | **present** |
| CASE-C | absent | absent |
| CASE-A | arrived (watchdog) then outbound-picked | absent |

Injection timestamps: ~14:20:23 UTC (batch 1), ~14:28:11 UTC (follow-up). Observation: 14:21–14:29 UTC (≥2 poll cycles).

---

## 10. Rollback test evidence

| Step | Result |
|------|--------|
| Start safe mode | `INBOUND_ROUTING_MODE=shadow` restart 14:19:05 UTC |
| Enable live | `relationship_live` restart 14:20:06 UTC |
| Live routing observed | Delivery lines with `mode=relationship_live` 14:21:06 UTC |
| Rollback | `shadow` restart 14:23:07 UTC |
| Post-rollback | Daemon log confirms `INBOUND_ROUTING_MODE=shadow` |

---

## 11. Automated tests

```text
> py -3 -m unittest tests.test_relationship_routing tests.test_relationship_shadow -v
Ran 35 tests in 0.072s — OK
```

Coverage includes: exact match A/B, unknown sender, cross-account miss, lookup exception, invalid maildir, shadow/legacy/live modes, process-result mark-seen policy, PROMPT-62 divergence markers.

---

## 12. Outbound regression check

| Check | Result |
|-------|--------|
| `send_via_external_smtp` modified | **No** |
| `MaildirHandler` modified | **No** |
| `resolve_outbound` modified | **No** |
| Outbound still active during tests | **Yes** (watchdog forwarded Case A from refloc1 Maildir — pre-existing behaviour) |

---

## 13. Known limitations / PROMPT-64+ follow-up

1. MIME attachment-only inbound transformation (customer spec).
2. IMAP DELETE/EXPUNGE for unknown senders (customer spec “delete as spam”).
3. Relationship A deliveries to `refloc1` share referent `local_outbox` watch path — existing outbound watchdog consumes messages immediately (observed, not introduced by Stage 2a).
4. Cross-domain SMTP injection (clientint1 → refint2@bofoma.net) unreliable; Case C used **IMAP append** (valid inbound observation path).

---

## 14. Hard acceptance gates

All applicable gates satisfied.

---

## Stage-2a decision

**STAGE 2a ACCEPTED**

*(Not full customer-spec acceptance — MIME transformation and IMAP delete for unknown senders remain future scope.)*
