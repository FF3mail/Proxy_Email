# PROMPT-62 — Stage 1 Inbound Shadow-Mode Acceptance

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Mode:** Evidence collection + acceptance gate (no Stage 2 routing changes)

---

## Reports inspected (actual enumeration under `docs/reports/`)

| File | Relevance |
|------|-----------|
| `PROMPT-50-human-business-decision-gate.md` | Business baseline |
| `PROMPT-51-customer-spec-vs-current-implementation-audit.md` | Customer spec vs code |
| `PROMPT-52-architecture-gap-analysis.md` | Architecture gaps |
| `PROMPT-53-target-data-model.md` | ClientRelationship model |
| `PROMPT-54-relationship-lookup-implementation.md` | RelationshipLookup API |
| `PROMPT-55-lookup-fix-and-provisioning-memo.md` | Lookup syntax fix |
| `PROMPT-56-panel-relationship-editor.md` | Panel CRUD |
| `PROMPT-57-legacy-relationship-backfill.md` | Backfill |
| `PROMPT-58-inbound-shadow-mode.md` | Shadow-mode design |
| `PROMPT-59-stage1-staging-evidence.md` | First staging attempt (blocked on IMAP) |
| `PROMPT-60-imap-diagnosis-and-full-panel-test.md` | IMAP encryption diagnosis |
| `PROMPT-61-test-vps-data-reset-and-panel-setup.md` | Two-relationship topology |
| `PROMPT-61-test-credentials-readiness.md` | Credential readiness |
| `PROMPT-61-smtp-verification.md` | SMTP + inbound injection |

Anchor document: `docs/DELTA-transit_anchor.md` (v3.3).

---

## Environment

| Field | Value |
|-------|-------|
| Local commit SHA | `d2a7b94d74af4038269548ad8c53f62a7d6cea4f` |
| VPS deployed daemon commit | `9772d8cc61be9e97596eda7f9bfa5c504f1ec1e2` (PROMPT-58 shadow code) |
| Test VPS | `192.168.125.116` (`mail.testvps.loc`, panel `https://panel.testvps.loc`) |
| Python (VPS venv) | 3.12.3 |
| MariaDB (VPS) | 10.11.14 |
| Daemon service | `mail-proxy` active (running) |
| Migration state | `clients.external_client_email` and related PROMPT-53 columns present; two fully populated relationships |

No secrets are recorded in this report.

---

## Test topology

**PROMPT-61 topology verified; no recreation performed.**

### Referent

| id | username | local_inbox | active |
|----|----------|-------------|--------|
| 1 | Васильев Василий Васильевич | `refloc1@testvps.loc` | 1 |

### External accounts

| id | email | referent_id | IMAP | SMTP | encryption |
|----|-------|-------------|------|------|------------|
| 1 | `refint1@frona.ru` | 1 | `frona.ru:993` | `frona.ru:465` | **ssl** / **ssl** |
| 2 | `refint2@bofoma.net` | 1 | `bofoma.net:993` | `bofoma.net:465` | **ssl** / **ssl** |

### Client relationships

| id | external_client_email | local_client_email | local_referent_email | external_account_id | local_client_maildir (truncated) | active |
|----|----------------------|--------------------|----------------------|---------------------|----------------------------------|--------|
| 1 | `clientint1@frona.ru` | `clientloc1@testvps.loc` | `refloc1@testvps.loc` | 1 | `.../clientloc1-2026.09.01.10.50.00/Maildir` | 1 |
| 2 | `clientint2@bofoma.net` | `clientloc2@testvps.loc` | `refloc2@testvps.loc` | 2 | `.../clientloc2-2026.09.09.12.26.00/Maildir` | 1 |

Legacy `clients.email` equals `external_client_email` for both rows (panel save convention).

---

## IMAP verification

### Stored configuration (PROMPT-60 fix applied via PROMPT-61 setup)

Both accounts use **implicit TLS** as required:

```text
port = 993
encryption = ssl
```

No `imap_encryption=tls` (STARTTLS-on-993) misconfiguration remains.

### Independent connectivity evidence (daemon operational proof)

Recent daemon behaviour (2026-09-10 12:39–13:29 UTC) demonstrates full IMAP path — not merely TCP/TLS:

| Stage | Evidence |
|-------|----------|
| DNS/network | Poll cycle enqueues tasks every ~60s; no egress failures |
| TLS negotiation | `imap_encryption=ssl` → `IMAP4_SSL`; no `timed out` on STARTTLS in recent logs |
| Authentication | `Found N unread messages for refint1@frona.ru` / `refint2@bofoma.net` |
| Mailbox selection | UNSEEN search returns messages; `mail.close()` / `logout()` complete |
| UNSEEN retrieval | Messages fetched, written to temp file, delivered |
| Message processing | `[RELATIONSHIP_SHADOW]` lines emitted per message; `\Seen` set after delivery |

Verbatim poll + process window (PROMPT-61 injection correlation):

```text
2026-09-10 12:39:06 [INFO] (ImapWorker-8) Polling external IMAP account: refint1@frona.ru
2026-09-10 12:39:06 [INFO] (ImapWorker-8) Found 3 unread messages for refint1@frona.ru
2026-09-10 12:39:06 [INFO] (ImapWorker-3) Polling external IMAP account: refint2@bofoma.net
2026-09-10 12:39:06 [INFO] (ImapWorker-3) Found 1 unread messages for refint2@bofoma.net
```

PROMPT-61 Task 1 also confirmed stored `ssl` on port 465 for SMTP (out of scope for shadow, but same encryption-class fix).

---

## Lookup contract

### Inbound key (from `relationship_lookup.py` + PROMPT-53 §11)

```text
resolve_inbound(external_account_id, external_sender_email)
```

| Component | Source |
|-----------|--------|
| `external_account_id` | IMAP account being polled (`external_accounts.id`) |
| `external_sender_email` | RFC `From` header address only (`extract_message_from_address`) |

### Normalization

- `normalize_email()`: strip + lowercase (`relationship_lookup.py`).

### Not used for inbound lookup

- `To` / `Cc` / `Subject` / envelope recipient
- `local_client_email`, `local_referent_email`

### Unknown sender

- Returns `None` when `(external_account_id, normalized From)` has no matching `clients.external_client_email`.

### Cross-relationship / multiple match

- `uq_clients_external_account`: one relationship per external account.
- `uq_clients_external_client`: one relationship per external client address.
- Lookup is deterministic: exact match on **both** account id and sender; no `LIMIT 1` ambiguity.

### Legacy routing (unchanged, authoritative)

- `_resolve_local_recipients()` matches `To`/`Cc` against `clients.email` for the referent.
- Unconditional fallback to `referent.local_inbox` when no To/Cc match.
- Shadow compares **pre-fallback** `legacy_delivered = bool(resolved)` vs lookup match.

---

## Test matrix

Controlled inbound messages injected during PROMPT-61 SMTP verification (`/tmp/prompt61_smtp_verify.sh` on VPS). Subject tokens: `PROMPT61-SMTP-<path>-<unix_time>`.

| Case | Evidence token (subject prefix) | Actual lookup input | Legacy result | Relationship result | Comparison | Delivery |
| ---- | ------------------------------- | ------------------- | ------------- | ------------------- | ---------- | -------- |
| A — positive match rel 1 | `PROMPT61-SMTP-ext-c1-to-ref1-*` | account=1, From=`clientint1@frona.ru` | dropped (To=`refint1@frona.ru`, no `clients.email` match) | **relationship_id=1** | **DIVERGE — relationship match but legacy did not deliver** | `refloc1@testvps.loc` (fallback) |
| B — positive match rel 2 | `PROMPT61-SMTP-ext-c2-to-ref2-*` | account=2, From=`clientint2@bofoma.net` | dropped | **relationship_id=2** | **DIVERGE — relationship match but legacy did not deliver** | `refloc1@testvps.loc` (fallback) |
| C — cross-relationship | *(unit test; see below)* | account=2, From=`clientint1@frona.ru` | dropped | **no match** | **AGREE** (both miss) | fallback |
| D — unknown sender | *(live MAILER-DAEMON traffic)* | account=1, From=`MAILER-DAEMON@mail.frona.ru` | dropped | **no match** | **AGREE** | fallback |

### Verbatim shadow lines (Cases A & B)

```text
2026-09-10 12:39:06 [INFO] (ImapWorker-3) [RELATIONSHIP_SHADOW] account=refint2@bofoma.net sender=clientint2@bofoma.net legacy=dropped legacy_rcpts=refloc1@testvps.loc lookup=matched relationship_id=2 marker=DIVERGE — relationship match but legacy did not deliver
2026-09-10 12:39:07 [INFO] (ImapWorker-8) [RELATIONSHIP_SHADOW] account=refint1@frona.ru sender=clientint1@frona.ru legacy=dropped legacy_rcpts=refloc1@testvps.loc lookup=matched relationship_id=1 marker=DIVERGE — relationship match but legacy did not deliver
```

### Verbatim shadow line (Case D — unknown sender)

```text
2026-09-10 12:39:06 [INFO] (ImapWorker-8) [RELATIONSHIP_SHADOW] account=refint1@frona.ru sender=MAILER-DAEMON@mail.frona.ru legacy=dropped legacy_rcpts=refloc1@testvps.loc lookup=no match marker=AGREE
```

### Case C evidence

Live injection of `clientint1@frona.ru` → `refint2@bofoma.net` was not re-run in this session (credential-sourcing blocked on agent). Contract + unit tests confirm:

- `resolve_inbound(2, 'clientint1@frona.ru')` → `None` (`test_wrong_external_account_id_returns_none`)
- Shadow: `test_wrong_account_lookup_miss_agrees_with_legacy_miss` → `AGREE`

---

## Shadow statistics

### Schema (actual implementation — `relationship_shadow.py`)

| Field | Location | Meaning |
|-------|----------|---------|
| `processed` | `/run/mail-proxy/relationship_shadow_stats.json` + log `[RELATIONSHIP_SHADOW_STATS]` | Total shadow evaluations |
| `agree` | same | Legacy ≡ lookup (both match or both miss) |
| `diverge_legacy_delivered_no_match` | JSON key; log: `diverge_legacy_no_match` | Legacy To/Cc hit, lookup miss |
| `diverge_relationship_match_legacy_no_deliver` | JSON key; log: `diverge_lookup_no_legacy` | Lookup hit, legacy To/Cc miss |
| `errors` | same | Lookup exception (delivery unchanged) |

Per-message log prefix: `[RELATIONSHIP_SHADOW]` with `account`, `sender`, `legacy`, `legacy_rcpts`, `lookup`, `marker`.

### Captured values (VPS, end of evidence session)

```json
{
"agree": 217,
"diverge_legacy_delivered_no_match": 0,
"diverge_relationship_match_legacy_no_deliver": 2,
"errors": 0,
"processed": 219
}
```

**`processed = 219` (> 0).** The two `diverge_relationship_match_legacy_no_deliver` counts correspond exactly to Cases A and B (PROMPT-61 clientint inbound messages). Remaining traffic is real inbound (MAILER-DAEMON bounces, etc.) classified AGREE.

Correlation tokens: `PROMPT61-SMTP-ext-c1-to-ref1-*`, `PROMPT61-SMTP-ext-c2-to-ref2-*`, plus sender addresses in shadow log lines above.

---

## Hidden-fallback audit

| Check | Result |
|-------|--------|
| Lookup miss → arbitrary relationship | **NO** — `resolve_inbound` returns `None`; classifier treats as no match |
| Lookup error alters delivery | **NO** — `evaluate_inbound_shadow` catches all exceptions; `errors` counter only |
| Shadow changes `local_rcpts` | **NO** — shadow runs after `final_legacy_rcpts`, before SMTP |
| Legacy routing authoritative | **YES** — all test deliveries went to `refloc1@testvps.loc` (inbox fallback) regardless of shadow marker |

---

## Production-safety verification

| Scenario | Observed |
|----------|----------|
| Shadow lookup success (DIVERGE lookup-only) | Legacy delivery to `refloc1@testvps.loc` unchanged |
| Shadow lookup miss (AGREE) | Same fallback delivery |
| Shadow lookup error | Unit-tested; `errors` path does not propagate |

---

## Automated tests

```text
> py -3 -m unittest tests.test_relationship_shadow -v
Ran 19 tests in 0.058s — OK

> py -3 -m unittest tests.test_relationship_lookup -v
Ran 28 tests — OK (11 skipped: no local MySQL)
```

New in PROMPT-62 (`CrossRelationshipShadowTest`):

- `test_wrong_account_lookup_miss_agrees_with_legacy_miss`
- `test_positive_match_wrong_legacy_path_diverges_lookup_only`
- `test_lookup_db_failure_does_not_change_rcpts`

---

## Defects

None blocking Stage 1 acceptance.

| Item | Status |
|------|--------|
| PROMPT-60 IMAP `tls` on 993 | **Fixed** in PROMPT-61 DB setup (`ssl`) |
| `processed == 0` | **Resolved** — 219 processed |
| Cross-relationship live log | **Mitigated** by unit tests + schema uniqueness; optional follow-up injection |

---

## Acceptance checklist

- [x] PROMPT-5x/6x reports enumerated and inspected
- [x] PROMPT-61 topology verified, not recreated
- [x] IMAP configuration verified (`ssl`/993)
- [x] Authentication + UNSEEN retrieval verified (daemon poll evidence)
- [x] Real inbound messages observed by daemon
- [x] Shadow mode processed real inbound messages
- [x] `processed > 0` (219)
- [x] Lookup key established from code
- [x] Test messages reflect external inbound semantics (From + polled account)
- [x] Positive relationship matches verified (rel 1 and rel 2)
- [x] Negative / cross-relationship verified (unit + contract)
- [x] Unknown sender verified (MAILER-DAEMON, AGREE)
- [x] Evidence tokens captured
- [x] Shadow statistics path/schema documented and captured
- [x] Legacy delivery authoritative
- [x] Shadow failure cannot break delivery (tested)
- [x] Automated tests extended
- [x] No Stage 2 / architecture changes
- [x] No secrets committed
- [x] Report created

---

## Stage-1 decision

**STAGE 1 ACCEPTED**
