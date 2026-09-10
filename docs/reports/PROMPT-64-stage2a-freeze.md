# PROMPT-64 — Stage 2a Baseline Freeze

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Pre-freeze parent SHA:** `8b7c1421974a422efefb1e1fe203e9c1b416b888`  
**Mode:** Freeze and verification only (no new routing behaviour)

---

## Reports inspected (actual filenames)

| File |
|------|
| `docs/reports/PROMPT-58-inbound-shadow-mode.md` |
| `docs/reports/PROMPT-59-stage1-staging-evidence.md` |
| `docs/reports/PROMPT-60-imap-diagnosis-and-full-panel-test.md` |
| `docs/reports/PROMPT-61-test-vps-data-reset-and-panel-setup.md` |
| `docs/reports/PROMPT-61-smtp-verification.md` |
| `docs/reports/PROMPT-62-stage1-inbound-shadow-acceptance.md` |
| `docs/reports/PROMPT-63-stage2a-inbound-routing.md` |

---

## Repository state

| Item | Value |
|------|-------|
| Branch | `prompt-47-panel-authorization-audit` |
| Pre-freeze HEAD | `8b7c142` (PROMPT-62) |
| Freeze commit | This commit (PROMPT-63 + PROMPT-64 deliverables) |
| Working tree at freeze | PROMPT-63 code + anchor sync + this report |

### Files in freeze commit (audited)

| File | Role |
|------|------|
| `relationship_routing.py` | Stage 2a routing modes |
| `mail-proxy-daemon.py` | Inbound routing integration |
| `tests/test_relationship_routing.py` | Unit tests |
| `docs/reports/PROMPT-63-stage2a-inbound-routing.md` | Stage 2a acceptance |
| `docs/reports/PROMPT-64-stage2a-freeze.md` | This report |
| `docs/DELTA-transit_anchor.md` | Anchor v3.4 sync |

### Excluded from commit

| Path | Reason |
|------|--------|
| `docs/DELTA_transit_admin_guide.pdf` | Unrelated PDF delta |
| `.keys/` | Secrets / VPS scripts |
| `__pycache__/` | Generated |
| Other untracked `docs/` | Out of PROMPT-63/64 scope |

---

## Deployment verification

| Item | Value |
|------|-------|
| **Repository SHA** | Freeze commit on `prompt-47-panel-authorization-audit` (parent `8b7c142`) |
| **Deployment model** | Direct copy to `/usr/local/bin/` (not git checkout on VPS) |
| **VPS host** | `192.168.125.116` (`mail.testvps.loc`) |

### Verified file hashes (local worktree = deployed)

| File | MD5 |
|------|-----|
| `mail-proxy-daemon.py` | `2aba00404683aa9ba3bfc0fa73e3d866` |
| `relationship_routing.py` | `bfee3bdb758346e700d8f785bcf0cadc` |
| `relationship_lookup.py` | `63dafdb3365c9378e825cc13f9be711b` |
| `relationship_shadow.py` | `b544a1811e0ccfd328f645a97f94f915` |

**Match: YES** — all four routing-stack files on VPS match repository worktree at freeze time.

---

## Runtime verification

| Check | Result |
|-------|--------|
| systemd drop-in | `/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf` |
| **Issue found** | Drop-in had `relationship_live` from PROMPT-63 testing |
| **Corrective action** | Reset to `INBOUND_ROUTING_MODE=shadow`, `daemon-reload`, `restart` |
| **Final observed mode** | `INBOUND_ROUTING_MODE=shadow` (2026-09-10 15:11:10 UTC) |
| Default (unset env) | `shadow` (`parse_inbound_routing_mode()` in code) |
| Service state | `mail-proxy` active |

---

## Stage 2a baseline

| Topic | Current state |
|-------|---------------|
| **Routing modes** | `shadow` (default), `legacy`, `relationship_live` |
| **Lookup contract** | `external_account_id` + normalized `From` |
| **Routing destination** | `ClientRelationship.local_referent_email` |
| **Delivery format** | Original RFC822 via local SMTP :25 |
| **Unknown sender** (`relationship_live`) | No local delivery; IMAP `\Seen`; no legacy fallback |
| **Lookup error** (`relationship_live`) | Fail closed; no delivery; no `\Seen`; no legacy fallback |
| **Rollback** | systemd drop-in env change + service restart (tested PROMPT-63) |
| **Current deployment mode** | `shadow` (post-freeze) |

### Known limitations

- No MIME attachment-only transformation
- No IMAP DELETE/EXPUNGE for unknown senders
- Outbound still uses legacy `referent.local_outbox` watch
- Relationship A deliveries to `refloc1` may be consumed by existing outbound watchdog

---

## PROMPT-63 deliverables audit

| Check | Result |
|-------|--------|
| Implementation matches report | **Yes** |
| Default mode safe | **Yes** (`shadow`) |
| Outbound unchanged | **Yes** (`send_via_external_smtp`, `MaildirHandler` untouched) |
| No secrets in committed files | **Yes** |
| Rollback documented | **Yes** (PROMPT-63 §3, anchor §3.1) |

---

## Anchor synchronization

`docs/DELTA-transit_anchor.md` updated to **v3.4**:

- Added `relationship_lookup.py`, `relationship_shadow.py`, `relationship_routing.py` to file map
- Added §3.1 inbound routing (modes, lookup contract, live behaviour, not-implemented list)
- Updated `clients` table description for ClientRelationship columns
- Added env constants `INBOUND_ROUTING_MODE`, `RELATIONSHIP_LOOKUP_SHADOW`
- Added §12 open-gap register

---

## Test results

```text
> py -3 -m unittest tests.test_relationship_routing tests.test_relationship_shadow -v
Ran 35 tests in 0.063s — OK
```

| Suite | Passed | Failed | Skipped |
|-------|--------|--------|---------|
| `tests.test_relationship_routing` | 15 | 0 | 0 |
| `tests.test_relationship_shadow` | 20 | 0 | 0 |

`tests.test_relationship_lookup` — 11 skipped (no local MySQL); not required for freeze gate.

---

## Open gaps

| Group | Items |
|-------|-------|
| **Routing** | Outbound `resolve_outbound`; retire legacy inbound To/Cc gate |
| **Message Transformation** | Attachment-only rebuild; per-relationship From/To |
| **Spam Handling** | IMAP DELETE/EXPUNGE for unknown sender |
| **Outbound** | Per-relationship `local_client_maildir` watchdog |
| **Operational Hardening** | Panel routing/shadow stats UI; deployment SHA pinning in installer |

---

## Final baseline status

**STAGE 2a BASELINE FROZEN**
