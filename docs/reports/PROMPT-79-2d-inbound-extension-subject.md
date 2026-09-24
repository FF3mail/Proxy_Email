# PROMPT-79.2d — Inbound extension acceptance, archive subject check, skipped journaling, attachment limit

**Branch / PR:** `prompt-79-2c-inbound-multi-attach-split` (PR #33)  
**Date:** 2026-09-24  
**Replaces (inbound rules):** PROMPT-79.2c «archives only» + «no parent subject for N≥2»

## Design choices

| ID | Decision |
|----|----------|
| D1 | Single inbound path for N≥1 (split/fan-out); archive not required |
| D2 | `APPROVED_INBOUND_EXTENSIONS` = archives ∪ images; SVG excluded; outbound unchanged |
| D3–D4 | Disallowed parts skipped+journaled; nothing left → `disallowed_extension` |
| D5 | `MAX_INBOUND_ATTACHMENTS=20`; 21+ → whole-message `too_many_attachments` |
| D6 | At-least-once; no deduplication |
| D7 | Archive-only parent Subject via `check_inbound_subject` (backtracking) |
| D8 | Child Subject = attachment filename (archives and images) |

**Single decision point:** `classify_inbound_attachments` → indexes to
`rebuild_inbound_fanout` (no `archive_extensions_only`).

**Check order:** zero → too_many → subject → extension filter → fan-out.

**Journal:** migration `005` adds `event_type=skipped` and `detail`
VARCHAR(1024). Application truncates with `...` when longer (`DETAIL_MAX_LEN`).

**Write-before-delete:** skipped/disposed INSERT before IMAP dispose;
journal failure → UNSEEN, no delete. Partial SMTP → RD-13 (UNSEEN),
delivered rows for successful children kept.

## R6 — inline / cid images (facts, unchanged)

`enumerate_attachable_parts` / rebuild walk only parts whose
`Content-Disposition` contains `attachment`. Parts with `inline` (including
cid-referenced signature images) are **not** counted toward N, the 20 limit,
the Subject multiset, or fan-out. Behaviour left as-is pending operator
agreement to change.

## Accepted risks (ADR)

- Images-only inbound messages pass with any Subject.
- Extension-only validation (no magic bytes) — F6 candidate.
- Duplicate local deliveries after partial SMTP retry are acceptable.

## Tests

| Suite | Before (session baseline) | After |
|-------|---------------------------|-------|
| `python -m unittest discover -s tests` | 107 | 132 (11 skipped, 0 failed) |

Focused module: `tests/test_inbound_multi_attach_split.py` (subject, child
Subject, extensions, limit/order, journal/fail-closed, outbound regression,
no interim markers).

## Out of scope

Content inspection, outbound path changes, lab VPS deploy, N-recipient
journal E2E.

## Deploy note

Apply `migrations/005_mail_passage_journal_skipped.sql` before relying on
skipped rows in the panel. Lab VPS deploy-verify is a separate operator step.
Do not merge PR #33 without operator confirmation.
