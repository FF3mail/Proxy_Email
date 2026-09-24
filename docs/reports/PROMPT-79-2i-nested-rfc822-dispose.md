# PROMPT-79.2i — Inbound nested `message/rfc822` disposed (not re-polled)

**Date:** 2026-09-24  
**Branch:** `prompt-79-2c-inbound-multi-attach-split` (PR #33)  
**Scope:** Inbound classification + journal + panel labels only (no outbound change, no lab deploy)

---

## Customer decision

Nested forward-as-attachment (`Content-Type: message/rfc822` with
`Content-Disposition: attachment`) is **not** accepted. Lifecycle:
journal (`skipped` + `disposed` with `nested_message`) → `\Seen` → delete.
No endless UNSEEN re-poll without journal.

## Implementation summary

| Area | Change |
|------|--------|
| `attachment_policy.py` | `nested_policy` on enumerator (`opaque` inbound, `error` outbound default); `DISPOSAL_NESTED_MESSAGE`; skip/dispose reason helpers |
| `mail-proxy-daemon.py` | Shared skip journal detail/reason for `nested_message` |
| Panel | `nested_message` label in `relationship_status.php` |
| Docs | Decisions log §7, ops guide §79.2i, anchor §21, 79.2e poison note trimmed |

## Tests

`python -m unittest discover -s tests` — see commit (baseline 157 + new nested cases).

Outbound `validate_single_archive_attachment` / `message_rebuild` F9 unchanged (`nested_rfc822` error).

## Out of scope

Lab VPS deploy, outbound path changes, content inspection inside nested bodies.
