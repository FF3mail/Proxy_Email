# PROMPT-58 — Inbound Daemon Cutover, Stage 1: Shadow-Mode RelationshipLookup

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Mode:** Daemon additive/parallel only — **no live routing change**  
**Business baseline:** PROMPT-51–57 frozen

---

## 0. Process evidence (actual commands)

```text
> git rev-parse HEAD
dee2f060fd8a43a4cdc2f6b152bc1048a53d8004

> git log -1 --oneline
dee2f06 PROMPT-57 operator-assisted legacy relationship backfill

> git status
On branch prompt-47-panel-authorization-audit
Your branch is up to date with 'origin/prompt-47-panel-authorization-audit'.

Changes not staged for commit:
	modified:   docs/DELTA_transit_admin_guide.pdf
	modified:   mail-proxy-daemon.py
	modified:   relationship_lookup.py

Untracked files:
	relationship_shadow.py
	tests/test_relationship_shadow.py
	docs/reports/PROMPT-58-inbound-shadow-mode.md   (this file, once written)
	... (unrelated docs/.keys/__pycache__/ omitted)
```

**Starting commit:** `dee2f060fd8a43a4cdc2f6b152bc1048a53d8004` (PROMPT-57 tip).  
PROMPT-58 work is **uncommitted** at report time.

---

## 1. Executive summary

`RelationshipLookup` is imported into `mail-proxy-daemon.py` for the first time and called **after** the unchanged legacy recipient decision, using the message **From** address. Results are classified `AGREE` / `DIVERGE …` and counted. Exceptions from lookup are swallowed. SMTP recipients, `\Seen` timing, and IMAP search are unchanged. There is **no** live-mode flag.

---

## 2. Files touched

| File | Change |
|------|--------|
| `mail-proxy-daemon.py` | Import lookup + shadow helpers; `RELATIONSHIP_LOOKUP_SHADOW` (default on); shadow call in `_deliver_to_local_smtp`; pass `account` into deliver |
| `relationship_shadow.py` | **NEW** — pure classifier, exception-isolated eval, counters, stats file writer |
| `relationship_lookup.py` | Docstring only (shadow Stage 1 note; still no top-level side effects) |
| `tests/test_relationship_shadow.py` | **NEW** — unit tests (real Python modules) |
| `docs/reports/PROMPT-58-inbound-shadow-mode.md` | **NEW** — this report |

### `mail-proxy-daemon.py` change summary

- Import `RelationshipLookup` + `relationship_shadow` helpers
- Env flag `RELATIONSHIP_LOOKUP_SHADOW` (default **enabled**)
- `MailHandler._relationship_lookup = RelationshipLookup(db)`
- After `_resolve_local_recipients` + identical inbox fallback → optional shadow eval
- Startup log line stating shadow on/off
- **Unchanged:** `mail.search(None, 'UNSEEN')`, `mail.store(… '\\Seen')` timing, `_stream_file_via_smtp`, `_resolve_local_recipients` body

---

## 3. Import side-effect confirmation

`relationship_lookup.py` defines classes/functions/constants only. No DB connect, no logging setup, no daemon hooks at import time. Confirmed by test `RelationshipLookupImportSurfaceTest` and by reading the module.

---

## 4. Shadow semantics

### Legacy decision used for AGREE/DIVERGE

Existing `_deliver_to_local_smtp` always SMTP-delivers after an **unconditional fallback** to `referent.local_inbox` when `_resolve_local_recipients` returns empty. For Stage 1 comparison:

- **`legacy_delivered`** = `_resolve_local_recipients` returned a non-empty list (To/Cc matched `clients.email` for this referent) — **before** the fallback.
- **`legacy_rcpts` logged** = final list after fallback (what SMTP still uses).
- **Lookup** = `RelationshipLookup.resolve_inbound(account_id, From)`.

This lets both DIVERGE counters fire. If we had treated post-fallback SMTP as always “delivered”, `DIVERGE — relationship match but legacy did not deliver` would be impossible under current code.

### Markers

| Marker | Meaning |
|--------|---------|
| `AGREE` | To/Cc client-match ≡ lookup match (both yes or both no) |
| `DIVERGE — legacy delivered but no relationship match` | To/Cc matched; From lookup miss |
| `DIVERGE — relationship match but legacy did not deliver` | From lookup hit; To/Cc miss |

Structured log prefix: `[RELATIONSHIP_SHADOW]` / `[RELATIONSHIP_SHADOW_STATS]`.

### Isolation

`evaluate_inbound_shadow()` catches all exceptions, increments `errors`, logs a warning, returns; delivery continues with the same `local_rcpts`.

---

## 5. Counter exposure (PROMPT-26 mechanism)

PROMPT-26’s panel monitor reads **PID file** + **daemon log** under `/var/log/mail-proxy/` — there were **no** numeric daemon counters before this prompt, and **panel changes are out of scope**.

Stage 1 therefore reuses the same channels:

1. **Daemon log** — per-message `[RELATIONSHIP_SHADOW]` and rolling `[RELATIONSHIP_SHADOW_STATS]` lines (visible wherever operators already read the daemon log via monitor).
2. **Runtime directory file** (same pattern as `PID_FILE`): `/run/mail-proxy/relationship_shadow_stats.json` with `processed`, `agree`, both diverge keys, `errors`. Best-effort; write failures never affect delivery.

In-process counters reset on daemon restart.

---

## 6. Explicit callout — To/Cc vs From gap (on record for Stage 2)

Today’s `_resolve_local_recipients()` checks whether **To/Cc** contains a `clients.email` for the referent; it does **not** inspect **From**. Main-prompt intent is: route/accept by **who the message is FROM**; unknown sender → spam/delete. Stage 1 only **observes** From via `RelationshipLookup`; Stage 2 must switch delivery to that From-based decision. Do not treat the To/Cc check as intentional prior art to preserve.

---

## 7. Delivery unchanged — test evidence (not assertion alone)

`DeliveryDecisionUnchangedTest.test_rcpts_identical_with_and_without_shadow` computes `final_legacy_rcpts(resolved, inbox)` with:

- shadow off  
- shadow on (lookup returns None)  
- shadow on (lookup raises)

and asserts the three recipient lists are **equal** for each fixture (`[]→inbox`, single match, multi).  
`test_exception_does_not_alter_delivery_return` shows a raising lookup leaves `local_rcpts == ['ref1@local.loc']` (fallback) unchanged.

`final_legacy_rcpts` is the extracted form of the previous `if not local_rcpts: local_rcpts = [inbox]` block — same semantics.

---

## 8. Staging IMAP / live AGREE–DIVERGE counts

**Not available** on this Windows agent (no production/staging IMAP mailbox in this session). No hypothetical production counts are reported.

Unit-test counter exercise (synthetic): one AGREE, one legacy-only DIVERGE, one lookup-only DIVERGE — see `test_agree_and_diverge_counters`.

---

## 9. Tests executed (real Python modules)

MySQL was **not** required for Stage 1 shadow unit tests (pure classifier + mocked `resolve_inbound`). No PHP/language mirror substitution.

```text
> py -3 -m unittest tests.test_relationship_shadow -v

test_agree_both_deliver ... ok
test_agree_both_drop ... ok
test_diverge_legacy_only ... ok
test_diverge_lookup_only ... ok
test_rcpts_identical_with_and_without_shadow ... ok
test_agree_and_diverge_counters ... ok
test_exception_does_not_alter_delivery_return ... ok
test_exception_does_not_propagate ... ok
test_stats_file_written ... ok
test_parses_from_not_to ... ok
test_empty_falls_back_to_inbox ... ok
test_resolved_unchanged ... ok
test_module_has_no_side_effect_beyond_defs ... ok
test_default_enabled ... ok
test_explicit_off ... ok
test_explicit_on ... ok

----------------------------------------------------------------------
Ran 16 tests in 0.035s

OK
```

---

## 10. Out of scope (not done)

- Live routing switch / any “live mode” flag  
- Changing IMAP `\Seen`, search, or SMTP delivery targets  
- Outbound Maildir cutover  
- Fixing To/Cc-vs-From as the live gate (Stage 2)  
- Panel UI for the new stats JSON  

---

## 11. Integrity

| Check | Result |
|-------|--------|
| Live delivery recipients changed | **NO** |
| Live mode flag added | **NO** |
| Shadow default | **ON** (`RELATIONSHIP_LOOKUP_SHADOW`) |
| Lookup exceptions can abort delivery | **NO** |
| Panel modified | **NO** |
