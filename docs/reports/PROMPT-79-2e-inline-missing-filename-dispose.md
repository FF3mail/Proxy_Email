# PROMPT-79.2e — Inline policy, missing_filename, Seen→delete, shared enumerator

**Branch / PR:** `prompt-79-2c-inbound-multi-attach-split` (PR #33)  
**Date:** 2026-09-24  
**Follow-up to:** PROMPT-79.2d (`8473a3c`)

## Changes

| ID | Summary |
|----|---------|
| E1 | Attachment = Disposition contains `attachment` only; inline ignored (DEBUG); inline-only → `zero_attachments` with `detail` |
| E2 | `normalize_for_compare` / `normalize_for_display` (NFC) for Subject and archive names |
| E3 | Nameless attachment → skip `missing_filename` (not UNSEEN poison loop) |
| E4 | Inbound dispose: journal → Seen → Deleted+EXPUNGE (one IMAP helper) |
| E5 | `message_rebuild` imports `attachment_policy.enumerate_attachable_parts` |
| E6 | Docs: decisions, ops deploy note for migration 005, anchor v4.4 |

## Deploy note

Apply `migrations/005_mail_passage_journal_skipped.sql` **before** deploying or
restarting the daemon: INSERTs always include `detail`. Without 005, journal
writes fail → fail-closed stall (messages stay UNSEEN). Rollback: an older
daemon still works against the 005 schema.

## Known poison-message case (unchanged)

`message/rfc822` nested parts and malformed multipart still return
`status=error` (fail-closed): message stays UNSEEN and is retried each poll.
Out of scope for 79.2e.

## Tests

| Suite | Before | After |
|-------|--------|-------|
| `python -m unittest discover -s tests` | 132 (11 skipped) | 152 (11 skipped, 0 failed) |

## Out of scope

SMTP-retry skipped-row noise, legacy alias cleanup, nested rfc822 handling,
content inspection, outbound path, lab deploy.

**PROMPT-79.2f (2026-09-24):** attachment detection uses `get_content_disposition() == 'attachment'` instead of substring on the raw header (fixes inline parts whose filename contains “attachment”).
