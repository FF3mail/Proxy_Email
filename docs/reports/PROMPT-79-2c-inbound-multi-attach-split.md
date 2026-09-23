# PROMPT-79.2c — Inbound multi-attachment splitting

**Date:** 2026-09-23  
**Branch:** `prompt-79-2c-inbound-multi-attach-split`  
**Base:** `prompt-79-2-mail-passage-journal` (includes interim hold `2ab6f79`)  
**Replaces:** PROMPT-79.2-incident-check interim hold

## Design choices

| Topic | Choice |
|-------|--------|
| Mechanism | Reuse `rebuild_inbound_fanout` (PROMPT-77); add `archive_extensions_only` filter |
| N=0 | `zero_attachments` dispose (unchanged) |
| N=1 | Single-archive rules via `classify_inbound_attachments` (ext + subject) |
| N>=2 | Split: no parent subject check; approved-archive parts only; child Subject=filename |
| Mixed valid/invalid | Skip disallowed parts (WARNING log); deliver remaining; if zero remain -> dispose `disallowed_extension` |
| Empty after filter | `disallowed_extension` (all parts failed extension) |
| Journal | One `delivered` row per successful child SMTP (existing fan-out path) |
| Partial SMTP | RD-13 unchanged: leave UNSEEN, no source dispose |
| Interim | Removed: no `interim_multi_attachment_hold`, no `[PROMPT-79.2-INTERIM]` |
| Outbound | Unchanged: N>1 -> `multiple_attachments` dispose + notify |

## Diff scope

- `attachment_policy.py` — `classify_inbound_attachments` / `InboundAttachmentClassification`
- `message_rebuild.py` — `archive_extensions_only` on `rebuild_inbound_fanout`
- `mail-proxy-daemon.py` — inbound gate uses classify; interim hold deleted
- `tests/test_inbound_multi_attach_split.py` — replaces `test_interim_multi_attachment_hold.py`
- Docs: decisions log section 3, anchor section 21 / v4.2, this report

## Tests

| Phase | Result |
|-------|--------|
| Before (journal branch tip) | 97 run / 0 fail / 11 skipped |
| After | **107** run / 0 fail / 11 skipped |
| Reconciliation | -2 interim hold tests +12 split/classify tests (+10 net) |

## Non-goals

- Lab VPS deploy (separate follow-up)
- Step 4.3 N-recipient journal E2E
- Magic-byte verification (F6)
- Panel UI / schema changes

## Verdict

**Ready for operator review** — do not merge without confirmation.
