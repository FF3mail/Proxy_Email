# PROMPT-79.2 — Mail-passage journal (closure)

**Date:** 2026-09-23  
**Branch:** `prompt-79-2-mail-passage-journal`  
**Design:** approved in conversation (DB table + three amendments)  
**Issue:** [#26](https://github.com/FF3mail/Proxy_Email/issues/26) — case-insensitive subject/extension rules **implemented and cited**

## Design-amendment confirmation

| # | Amendment | Implemented |
|---|-----------|-------------|
| 1 | Inactive relationship → dispose like no-match with `disposal_reason=relationship_inactive` (not UNSEEN); decisions log §2 updated 2026-09-23 | Yes |
| 2 | Fan-out / multi-recipient → one journal row per recipient/child | Yes (`_deliver_inbound_fanout_via_smtp`) |
| 3 | UTC timestamps in application code; naive DATETIME; never SQL `NOW()` | Yes (`utc_now_naive` / purge cutoff) |

Storage choice (approved): MySQL `mail_passage_journal` via **new** `migrations/004_mail_passage_journal.sql` only. `schema.sql` and `migrations/003_referent_mode_overrides.sql` **untouched**.

## Summary (Steps 2–6)

- **Daemon:** attachment policy gate; classified relationship lookup; journal INSERT before IMAP dispose / Maildir unlink; outbound §4 notify with `notified` UPDATE after confirmed send; inbound silent.
- **Panel:** stub replaced; passage + nonstandard views from journal only; URL preserved.
- **Retention:** `scripts/purge_mail_passage_journal.py` + cron docs (same style as mysqldump cron).
- **Tests:** new Python suites + PHP panel fixture test; existing suite green.
- **Docs:** anchor §21 / v4.1; decisions log §2 amendment; guide 05/06/09; this report.

## Diff scope (expected)

New: `attachment_policy.py`, `mail_passage_journal.py`, `mail_disposal.py`, `referent_notify.py`, `migrations/004_mail_passage_journal.sql`, `scripts/purge_mail_passage_journal.py`, `tests/test_attachment_policy.py`, `tests/test_mail_passage_journal.py`, `tests/panel_mail_passage_journal_test.php`, `docs/reports/PROMPT-79-2-mail-passage-journal.md`.

Modified: `mail-proxy-daemon.py`, `relationship_lookup.py`, `relationship_routing.py`, `web/relationship-status.php`, `web/includes/relationship_status.php`, `web/lang/en.php`, `web/lang/ru.php`, `tests/test_relationship_routing.py`, `tests/scale_log_coverage_bench.php`, `docs/DELTA-transit_anchor.md`, `docs/decisions/PROMPT-79-decisions-log.md`, `docs/guide/05-web-panel.md`, `docs/guide/06-operations.md`, `docs/guide/09-backup-restore.md`.

## Tests

| Phase | Command | Result |
|-------|---------|--------|
| **Before** (origin/master) | `py -3 -m unittest discover -s tests -p "test_*.py"` | **67** run, **0** failures, **11** skipped |
| **After** | same | **90** run, **0** failures, **11** skipped |

**Reconciliation:** +23 tests from `test_attachment_policy` + `test_mail_passage_journal` (+ one extra dispose-flag case in routing). No removals.

**PHP:** `tests/panel_mail_passage_journal_test.php` added. Local Windows agent has no `php` binary — run on PHP-capable host in **PROMPT-79.2-deploy-verify** (same discipline as 79.1).

## Fail-closed / write-before-delete (code review)

- Lookup/MIME/`attachment_policy` **error** → no dispose, no disposed journal row.
- Definitive invalid / no_match / inactive → `journal_disposed` **then** IMAP EXPUNGE or Maildir unlink; INSERT failure aborts delete.
- Inbound disposal never calls notify; outbound notify only after journal row with `notified=0`, then UPDATE on send success.

## VPS / deploy

**Not performed** in this prompt. Ready for follow-up **PROMPT-79.2-deploy-verify** (apply migration 004, deploy modules, restart, pre/post snapshots, ERROR window, panel check, PHP tests on PHP host).

## Verdict

**ACCEPTED for merge-readiness locally** — Python suite green; design amendments reflected in code and decisions log; no live pilot deploy.

## Out of scope (deferred)

- Live VPS deploy / soak  
- PROMPT-79.4 schema cleanup of override columns  
- Magic-byte archive verification (F6 candidate)
