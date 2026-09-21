# PROMPT-78 — Backfill UI wording & nav visibility

**Branch:** `prompt-78-backfill-ui-wording`  
**Date:** 2026-09-21  
**Scope:** Panel PHP/i18n only (`web/lang/*.php`, `web/index.php` nav conditional)

## Summary

Removed internal PROMPT-number citations and untranslated legacy/backfill jargon from operator-facing panel strings. The main-nav backfill link is now omitted when the legacy backlog count is zero; when work remains, the link shows a count badge via `nav.backfill_pending`.

## Nav approach

**Hide when empty** (not disabled/greyed). A greyed permanent nav item would still suggest a standing feature area; hiding matches the semantics of a temporary operational queue that appears only while unfinished relationships exist.

## Tests

```
php tests/panel_legacy_backfill_test.php   # OK
python tests/panel_legacy_backfill_test.py # OK
```

Predicate/workflow unchanged — `relationshipIsLegacyOnly()` and `fetchLegacyRelationshipBacklog()` untouched.

## Deployment safety

**No daemon restart required.** Changes are limited to `web/` PHP and locale files. Deploy via `rsync web/` (or equivalent) to PHP-FPM only. Does not touch `mail-proxy-daemon.py`, schema, routing config, or referent overrides. Safe to deploy during an active daemon observation window (e.g. PROMPT-77.4) without interrupting observation — **not deployed in this PROMPT**; deploy when triaged.
