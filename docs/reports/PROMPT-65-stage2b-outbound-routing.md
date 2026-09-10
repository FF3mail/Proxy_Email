# PROMPT-65 — Stage 2b Controlled Outbound Relationship Routing

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Starting baseline:** `4f6ec8f` (PROMPT-64 canonical HEAD)  
**Freeze commit verified:** `c75a7f5bf0d5b98049a1ef664362a78dca3f7d54`  
**Implementation commit:** `9b47703`

---

## 1. Objective

Implement and validate **Stage 2b: controlled outbound relationship routing** — relationship-aware outbound account selection symmetrical with Stage 2a inbound, scoped strictly to the routing decision layer.

---

## 2. Verified starting commit

```text
git rev-parse HEAD     → 4f6ec8f6ecb12ef520448dabc30d4cb4b2a0ca16
git rev-parse c75a7f5  → c75a7f5bf0d5b98049a1ef664362a78dca3f7d54
git branch --current   → prompt-47-panel-authorization-audit
```

Pre-existing unrelated changes (untracked docs, `.keys/`, PDF) were not incorporated.

---

## 3. Files changed

| File | Change |
|------|--------|
| `relationship_routing.py` | Outbound modes, `plan_outbound_delivery()`, identity helpers |
| `relationship_shadow.py` | Outbound shadow classify/evaluate (`[OUTBOUND_RELATIONSHIP_SHADOW]`) |
| `mail-proxy-daemon.py` | `_enqueue_outbound_file()`, integration in watchdog/backlog |
| `tests/test_relationship_routing.py` | +9 outbound unit tests |
| `tests/test_relationship_shadow.py` | +6 outbound shadow tests |
| `docs/DELTA-transit_anchor.md` | v3.5 — §3.2 outbound routing |
| `docs/reports/PROMPT-65-stage2b-outbound-routing.md` | This report |

**Not changed:** inbound routing, SMTP transport (`send_via_external_smtp`), crypto, schema, panel, installer.

---

## 4. Existing outbound architecture (pre-PROMPT-65)

```text
referents.local_outbox/new
        ↓
MaildirHandler (watchdog per active referent)
        ↓
Parse To/Cc recipients
        ↓
_load_first_account(): external_accounts WHERE referent_id=? LIMIT 1
        ↓
SmtpTask → SmtpWorkerPool → send_via_external_smtp()
```

| Topic | Pre-change behaviour |
|-------|---------------------|
| Watch target | `referent.local_outbox/new` |
| Referent-centric | Yes — one watchdog per active referent |
| Client identity | Not used for account selection |
| Account selection | `LIMIT 1` per referent (arbitrary first row) |
| SMTP transport | `send_via_external_smtp()` unchanged |

---

## 5. Watch-target decision

**Chosen: Option A (interim) — referent outbox remains the watch target.**

| Item | Value |
|------|-------|
| Watched path | `/var/vmail/vmail1/testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/Maildir/new` |
| Referent | id=1 (`refloc1@testvps.loc`) |

**Not** 1:1 referent→relationship on test VPS: one referent, two ClientRelationship rows (PROMPT-61). Both relationships share the same `local_outbox`.

---

## 6. Proof of deterministic watch-target → relationship mapping

Determinism via **message-level identity**, not referent isolation:

| Relationship | `local_client_email` (From) | `external_account_id` |
|--------------|------------------------------|------------------------|
| A (id=1) | `clientloc1@testvps.loc` | 1 |
| B (id=2) | `clientloc2@testvps.loc` | 2 |

Schema constraints: `UNIQUE(local_client_email)`, `UNIQUE(external_account_id)` per relationship row.

Two different client relationships can share the referent outbox **because** `resolve_outbound(From)` is deterministic and never guesses.

---

## 7. Outbound routing identity

```text
Outbound routing identity:
RFC822 From header → normalize_email() → local_client_email

Relationship lookup:
RelationshipLookup.resolve_outbound(local_client_email)

Selected account:
ClientRelationship.external_account_id → dto.account (SMTP transport payload)
```

Extractor: `extract_outbound_identity_from_message()` → `extract_message_from_address()` (From only, not To/Cc).

---

## 8. Relationship lookup implementation

Existing `relationship_lookup.py` `resolve_outbound()` — unchanged query contract:

```sql
WHERE c.local_client_email = %s AND <valid relationship predicates>
```

No `LIMIT 1` fallback; returns single row or `None`.

---

## 9. Routing modes

Independent env var: `OUTBOUND_ROUTING_MODE` (not overloaded with `INBOUND_ROUTING_MODE`).

| Mode | Actual delivery account | Shadow |
|------|------------------------|--------|
| `shadow` (default) | Legacy `referent_id LIMIT 1` | Yes |
| `legacy` | Legacy only | No |
| `relationship_live` | `ClientRelationship.external_account_id` | No |

---

## 10. Safe defaults

| Variable | Default |
|----------|---------|
| `OUTBOUND_ROUTING_MODE` (unset) | `shadow` |
| `INBOUND_ROUTING_MODE` (unchanged) | `shadow` |

---

## 11. Shadow semantics

When `OUTBOUND_ROUTING_MODE=shadow`:

- Computes relationship account via `resolve_outbound(From)`
- Compares to legacy `LIMIT 1` account id
- Logs `[OUTBOUND_RELATIONSHIP_SHADOW]` + shared stats file
- **Does not** change the account used for `send_via_external_smtp()`

---

## 12. No-match behaviour

`relationship_live` + unknown From:

- `plan_outbound_delivery()` → `skip_reason=no_relationship_match`, `account=None`
- File **not** enqueued; remains in `local_outbox/new` (retryable)
- No `LIMIT 1` / first-account fallback

Verified live: `PROMPT65-1789054236-NOMATCH` with `From: unknown-norel@testvps.loc`.

---

## 13. Lookup-error behaviour

`relationship_live` + DB exception:

- `lookup_error` set, `account=None`
- File not enqueued (retryable)
- No legacy fallback

Unit test: `test_lookup_error_fail_closed_live` in `tests/test_relationship_routing.py`.

---

## 14. Arbitrary-account / fallback audit

| Location | Classification |
|----------|----------------|
| `_load_legacy_outbound_account()` `LIMIT 1` | **Legitimate** — legacy path only; used in `shadow`/`legacy` modes, never after live miss/error |
| `plan_outbound_delivery()` relationship_live | **No fallback** — returns `account=None` on miss/error |
| `relationship_lookup.resolve_outbound()` | **Deterministic** — equality on `local_client_email` |
| Inbound `_resolve_local_recipients` | Unrelated to outbound account routing |

---

## 15. Automated test results

```text
> py -3 -m unittest tests.test_relationship_routing tests.test_relationship_shadow -v
Ran 50 tests in 0.045s — OK
```

| Suite | Passed | Failed | Skipped |
|-------|--------|--------|---------|
| `tests.test_relationship_routing` | 24 | 0 | 0 |
| `tests.test_relationship_shadow` | 26 | 0 | 0 |

`tests.test_relationship_lookup` — skipped without local MySQL (expected).

---

## 16. VPS deployment model

| Item | Value |
|------|-------|
| Host | `192.168.125.116` (`mail.testvps.loc`) |
| Model | Direct copy to `/usr/local/bin/` (not git checkout) |
| Deploy script | `.keys/prompt65_vps_deploy_test.sh` (untracked) |

---

## 17. Deployed file hashes (local = deployed)

| File | MD5 |
|------|-----|
| `mail-proxy-daemon.py` | `f717441966eae5525269cc71952e9547` |
| `relationship_routing.py` | `2d2f24b7e795469cdce894093f23b326` |
| `relationship_shadow.py` | `be56baa762e15dbb9823f70269e0ca37` |
| `relationship_lookup.py` | `63dafdb3365c9378e825cc13f9be711b` |

---

## 18. Test A — Relationship A outbound

| Check | Result |
|-------|--------|
| Token | `PROMPT65-1789054236-REL-A` |
| Injected | `From: clientloc1@testvps.loc` → `To: clientint1@frona.ru` into refloc1 outbox |
| Daemon routing | `relationship_id=1 external_account_id=1` |
| SMTP | `Connecting to external SMTP frona.ru:465 for refint1@frona.ru` |
| External IMAP proof | **FOUND** in `clientint1@frona.ru` INBOX (`frona.ru:993`) |

---

## 19. Test B — Relationship B outbound

| Check | Result |
|-------|--------|
| Token | `PROMPT65-1789054236-REL-B` |
| Injected | `From: clientloc2@testvps.loc` → `To: clientint2@bofoma.net` |
| Daemon routing | `relationship_id=2 external_account_id=2` |
| SMTP | `Connecting to external SMTP bofoma.net:465 for refint2@bofoma.net` |
| External IMAP proof | **FOUND** in `clientint2@bofoma.net` INBOX (`bofoma.net:993`) |

---

## 20. Cross-relationship isolation

| Token | Expected mailbox | IMAP result |
|-------|------------------|-------------|
| `PROMPT65-1789054236-ISO-A` (client A) | `clientint2@bofoma.net` | **MISSING** |
| `PROMPT65-1789054236-ISO-B` (client B) | `clientint1@frona.ru` | **MISSING** |

```text
A → account 1 (refint1@frona.ru) ✓
B → account 2 (refint2@bofoma.net) ✓
A ≠ account 2 ✓
B ≠ account 1 ✓
```

---

## 21. External IMAP delivery evidence

SMTP `250` alone was **not** used as acceptance proof. All positive cases confirmed by IMAP SUBJECT search on external client mailboxes.

---

## 22. No-match / error evidence

| Test | Evidence |
|------|----------|
| No-match (live) | `no_relationship_match — not enqueued`; IMAP **MISSING** for `PROMPT65-1789054236-NOMATCH` |
| Lookup error | Unit test `test_lookup_error_fail_closed_live` (safe live DB fault injection not performed) |

---

## 23. Rollback evidence

| Phase | `INBOUND_ROUTING_MODE` | `OUTBOUND_ROUTING_MODE` |
|-------|------------------------|-------------------------|
| Before testing | `shadow` | `shadow` (new default) |
| Live routing tests | `shadow` | `relationship_live` |
| Rollback action | `shadow` | `shadow` |
| Final (2026-09-10 15:33:13 UTC) | `shadow` | `shadow` |

```text
INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow RELATIONSHIP_LOOKUP_SHADOW=on
```

No database changes required for rollback.

---

## 24. Final inbound runtime mode

```text
INBOUND_ROUTING_MODE=shadow
```

Stage 2a inbound behaviour unchanged.

---

## 25. Final outbound runtime mode

```text
OUTBOUND_ROUTING_MODE=shadow
```

---

## 26. Known limitations

- Watch target still `referent.local_outbox` (not per-relationship `local_client_maildir`)
- Shared referent outbox requires reliable `From` = `local_client_email`
- No-match leaves file in `new` (no quarantine/delete policy)
- Lookup-error live simulation limited to unit tests

---

## 27. Remaining open gaps

- Cutover watch to `local_client_maildir/new` per relationship
- MIME transformation / header rewrite (out of PROMPT-65 scope)
- Panel UI for outbound shadow stats

---

## 28. Final acceptance status

```text
STAGE 2b ACCEPTED
```

All mandatory gates passed: baseline verified, relationship-aware routing, deterministic identity, live A/B routing with external IMAP proof, cross-isolation, no-match fail-closed, rollback to safe modes, automated tests green.
