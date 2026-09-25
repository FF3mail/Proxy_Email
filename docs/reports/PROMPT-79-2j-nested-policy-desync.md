# PROMPT-79.2j — Fix inbound/rebuild `nested_policy` desync

**Date:** 2026-09-25  
**Branch:** `prompt-79-2c-inbound-multi-attach-split` (PR #33, same branch, no merge)  
**Scope:** Align inbound rebuild enumeration with `classify_inbound_attachments`; outbound unchanged. No lab deploy.

---

## Defect (verified)

`message_rebuild._enumerate_attachable_parts_shared` called
`enumerate_attachable_parts(msg)` with the default `nested_policy='error'`, while
`classify_inbound_attachments` uses `nested_policy='opaque'` (PROMPT-79.2i).

For an inbound message with one deliverable attachment (e.g. `a.zip`) plus a
`message/rfc822` attachment part (Subject matches the zip name → classify
`status=deliver`):

1. Classify writes a `skipped` / `nested_message` journal row (write-before-SMTP).
2. `rebuild_inbound_fanout` raised / returned `success=False` (`nested_rfc822` →
   `malformed_mime`).
3. Daemon fail-closed: source stayed **UNSEEN**, retried every poll.
4. The skipped row was re-written on every retry (message never left the mailbox).

This defeated the 79.2i goal (no silent infinite loop) and duplicated journal rows.

### Reduced repro

```
multipart/mixed [
  text/plain,
  application/zip attachment filename=a.zip,
  message/rfc822 attachment filename=fwd.eml
], Subject: a.zip

classify_inbound_attachments(raw).status == 'deliver', deliver_indexes == (0,)
rebuild_inbound_fanout(..., approved_part_indexes=(0,)).success == False  # before fix
```

---

## Fix

| Call site | `nested_policy` |
|-----------|-----------------|
| `rebuild_inbound_fanout` → `_enumerate_attachable_parts_shared` | **`opaque`** (explicit) |
| `rebuild_outbound_message` → `_enumerate_attachable_parts_shared` | **`error`** (default, unchanged) |

Default of `_enumerate_attachable_parts_shared` remains `'error'` so outbound stays fail-closed on nested messages.

---

## Tests

`python -m unittest discover -s tests` → **171 ran, 11 skipped, OK**
(baseline before this prompt: 166 / 11 skipped; +5 coverage cases).

- Classify deliver + rebuild success for zip+nested (repro); exactly one child `a.zip`.
- Zip + nested + png (Subject = zip only): deliver indexes `(0, 2)`, rebuild 2 children.
- Property: any message with classify `status=deliver` rebuilds without nested/malformed failure; child count matches `deliver_indexes`.
- Daemon e2e: one `skipped` + one `delivered` journal row; `mark_imap_seen=True` (no UNSEEN retry loop).
- Outbound: `message/rfc822` still fails validation/rebuild (`nested_policy='error'`).
- F9 updated: inbound opaque treats bare rfc822 without attachment disposition as
  zero attachments (no longer `malformed_mime`).

---

## Secondary finding (known gap — no code change)

`_is_nested_rfc822` matches only `Content-Type == 'message/rfc822'`. Other
`message/*` subtypes (`message/global`, `message/partial`, `message/external-body`)
are still walked; inner attachments can surface as top-level deliverable parts.

**Proposed follow-up (ask operator):** broaden the check to
`ctype.startswith('message/')`. Not changed in this step.

---

## Docs / decisions

- This report; `docs/decisions/PROMPT-79-decisions-log.md` §7 amendment;
  anchor inbound-attach-gate row notes 79.2j.
- Cross-ref from `PROMPT-79-2i-nested-rfc822-dispose.md`.

## Out of scope

Lab VPS deploy; broadening `message/*` opaque match without operator confirmation.
