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
| **After implement** | same | **90** run, **0** failures, **11** skipped |
| **After deploy-verify-fixups** | same | **95** run, **0** failures, **11** skipped |

**Reconciliation:** +23 from implement (`test_attachment_policy` + `test_mail_passage_journal` + routing). +5 from deploy-verify-fixups (`test_referent_handler_data` x2 + `test_purge_mail_passage_journal` x3).

**PHP:** `tests/panel_mail_passage_journal_test.php` — **OK** on VPS PHP 8.3.6 (PROMPT-79.2-deploy-verify), together with routing + path-guard panel scripts.

## Fail-closed / write-before-delete (code review)

- Lookup/MIME/`attachment_policy` **error** → no dispose, no disposed journal row.
- Definitive invalid / no_match / inactive → `journal_disposed` **then** IMAP EXPUNGE or Maildir unlink; INSERT failure aborts delete.
- Inbound disposal never calls notify; outbound notify only after journal row with `notified=0`, then UPDATE on send success.

## VPS / deploy (PROMPT-79.2-deploy-verify — 2026-09-23)

**Host:** lab VPS `192.168.125.116` · MariaDB **10.11.14** · PHP **8.3.6** · rollback `/root/prompt792_rollback_20260923T083046Z`

### Step 0–1 preconditions / snapshot

| Check | Result |
|-------|--------|
| MySQL vs dry-run | VPS 10.11.14; local dry-run was 10.11.x portable — compatible; 004 applied live |
| PHP CLI | 8.3.6 present |
| Cron convention | root crontab already has `30 3 * * * …/backup_mysql.sh`; purge uses same root-crontab style |
| Modes (referent #1) | `relationship_live` / `relationship_live` / `relationship_only` |
| PRE_UNSEEN | refloc1_new=**6**, clientloc1_new=**0** |
| postqueue | empty |
| `mail_passage_journal` before migrate | **does not exist** (`JOURNAL_TABLE_EXISTS=0`) |
| Deployed rev (initial) | `d2423c2` |

### Step 2 migration 004

`SHOW CREATE TABLE mail_passage_journal` matches dry-run (ENUM delivered/disposed, direction inbound/outbound, indexes `idx_mpj_event_ts`, `idx_mpj_event_type_ts`, `idx_mpj_disposal_reason_ts`). Re-apply idempotent OK. Daemon not restarted until Step 3.

### Step 3 deploy + ERROR window

| Metric | Value |
|--------|-------|
| RESTART_EPOCH | 1790152248 |
| Modes post-restart | unchanged (`relationship_live` ×2 / `relationship_only`) |
| POST_UNSEEN (immediate) | refloc1_new=**6**, clientloc1_new=**0** (identical to pre) |
| ERROR 10 min before restart | **30** |
| ERROR 10 min after restart | **0** |

Pre-restart ERROR burst explained by prior `[MESSAGE_REBUILD] zero_attachments` on UNSEEN mail that was previously left Seen-only; post-deploy disposal deletes those messages (intended gap closure). Material difference: ERROR rate dropped after disposal path went live.

### Step 4 functional (synthetic only)

| # | Case | Evidence |
|---|------|----------|
| 4.1 | Outbound zero attachments | journal id **5**: `disposed`/`outbound`/`zero_attachments`/`notified=1`; maildir file deleted; §4 notify delivered to `refloc1` (dovecot hdr.subject «Ошибка доставки — clientint1@frona.ru») |
| 4.2 | Inbound silent dispose | ids **1–4**: inbound `disposed` with `zero_attachments`/`multiple_attachments`, **`notified=0`** (auto-dispose of prior UNSEEN after restart). Synthetic external SMTP for crafted `no_relationship` sender rejected (`550 User unknown` on frona.ru) — silent inbound dispose still proven; reason `no_relationship` not exercised live |
| 4.2b | Outbound inactive | id **6** first attempt `notified=0` due to `_referent_handler_data` returning only `{id}` → notify skipped. Fix deployed; id **10** `relationship_inactive`/`notified=1`. Clients.id=1 restored `active=1` |
| 4.3 | Fan-out N journal rows | **Deferred (tracked)** — not verified on real daemon path. See deploy-verify-fixups Task 1. Helper/writer contract only (ids 8-9, 12-13). |
| 4.4 | Valid `.ZIP` vs subject `.zip` | id **11** (and earlier id **7**): `delivered`/`outbound`, no disposal; file removed from `new/` |
| 4.5 | Lookup DB fault → no dispose | **Deferred** — unsafe on live pilot (would risk mid-flight mail). Fail-closed covered by unit tests |

### Live fixups applied during verify (committed with this report)

1. `mail-proxy-daemon.py` — `_referent_handler_data` loads `id,username,local_inbox,local_outbox` so outbound notify has addresses.
2. `scripts/purge_mail_passage_journal.py` — read `[db]` `db_*` keys from `/etc/mail-proxy/db.conf` (daemon conf layout).

### Step 5 panel + PHP

- `relationship-status.php` renders real passage + nonstandard views (stub keys gone).
- PHP on VPS: `panel_mail_passage_journal_test.php` **OK**; `panel_relationship_routing_test.php` **OK**; `panel_relationship_path_guard_test.php` **OK**.

### Step 6 purge

- Dry-run: `would delete 0 rows` (cutoff ~1y ago); live run: `deleted 0 rows`; count **13→13**.
- Cron: `15 3 * * * /opt/delta-transit/venv/bin/python3 /usr/local/bin/purge_mail_passage_journal.py >> /var/log/mail-proxy/journal-purge.log 2>&1` (alongside mysqldump at 03:30).

### Combined test tally

| Suite | Result |
|-------|--------|
| Python (local, post-fixups) | **95** run / **0** fail / **11** skipped |
| PHP panel (VPS 8.3.6) | **3** scripts / **0** fail |

### Final snapshot (after cleanup)

- Modes: unchanged. clients.id=1 `active=1`. daemon **active**.
- UNSEEN_R restored to **6** (pre-deploy baseline): removed 4 PROMPT-79.2 synthetics (2 notify + 2 helper FANOUT). Remaining 6 are prior PROMPT-77.3/77.4 probe children — left as historical probes, not this pass.
- Journal rows retained (panel evidence).
- UNSEEN_C = **0**.

## Deploy-verify-fixups (PROMPT-79.2-deploy-verify-fixups — 2026-09-23)

### Task 1 — Fan-out E2E: deferred with plan

**Not safely feasible on this lab VPS without changing product/pilot semantics:**

1. Only one referent (`id=1`); relationships 1 and 2 each have a single `local_referent_email`.
2. `plan_inbound_delivery` always builds `local_rcpts=[target]` (length 1).
3. Attachment policy requires exactly one archive attachment before delivery; multi-attachment inbound is disposed and never reaches the `journal_delivered` fan-out loop.
4. A disposable second referent would not create multi-rcpt plans without schema/planner changes.

**Close on:** dedicated non-production test host / pre-production test-bed (PROMPT-79 roadmap follow-on). Track as **Step 4.3 deferred to test-bed**. Unit helper contract remains covered (`FanoutJournalRowsTest`).

### Task 2 — Regression tests for live bugs

| Bug | Why suite missed it | New tests |
|-----|---------------------|-----------|
| `_referent_handler_data` returned `{id}` only | Fixtures never asserted `local_inbox` on the real loader; notify path not covered | `tests/test_referent_handler_data.py` (2) |
| Purge ignored `[db]` `db_*` keys | Purge had no unit tests | `tests/test_purge_mail_passage_journal.py` (3) |

**Count:** 90 → **95** (+5), all green.

### Task 3 — Test-data cleanup

Deleted 4 PROMPT792 synthetics from `refloc1/.../new` (UNSEEN 10→6). Retained PROMPT-77.3/77.4 probes. Journal table left intact for panel.

## Verdict

**ACCEPTED with tracked gap** — live verify otherwise clean; bugs fixed and regression-tested; UNSEEN restored; **Step 4.3 fan-out E2E deferred to test-bed** (explicit in PR). Do not merge without operator confirmation.

## Out of scope (deferred)

- Step 4.3 daemon-path N-recipient journal rows (test-bed)
- Step 4.5 live DB-fault injection (non-pilot env)
- Live exercise of disposal_reason=`no_relationship` via external SMTP (provider 550)
- PROMPT-79.4 schema cleanup of override columns
- Magic-byte archive verification (F6 candidate)
