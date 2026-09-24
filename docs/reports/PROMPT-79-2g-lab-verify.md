# PROMPT-79.2g — Live lab verification (PR #33 @ 5cd6c23)

**Date:** 2026-09-24 (UTC)  
**Lab host:** `192.168.125.116` (`mail.testvps.loc`, panel `https://panel.testvps.loc`)  
**Branch under test:** `prompt-79-2c-inbound-multi-attach-split` @ `5cd6c23c6d0e597d260045103a7ca6fc30ead8c5`  
**RUNID:** `20260924T093629` — header `X-Lab-Test: 79-2g-20260924T093629`  
**Mode:** VERIFY ONLY (no code changes, no merge)

---

## Operator gates

| Gate | Status | Notes |
|------|--------|-------|
| **G1** (restart daemon for deploy) | **Confirmed** | Operator authorized full PROMPT-79.2g work order in chat; deploy + `systemctl restart mail-proxy` executed. |
| **G2** (L20 partial SMTP failure) | **Not executed** | No temporary LOCAL_SMTP sink configured; case marked NOT VERIFIED (unit tests cited). |
| **G3** (final verdict) | **See §Verdict** | Operator review requested. |

---

## Step 0 — Baseline

| Item | Value |
|------|--------|
| UTC at start | `2026-09-24T09:33:44Z` |
| Pre-deploy daemon MD5 | `2036fe6fd90ea0ce92298d4f203f7c9c` (`mail-proxy-daemon.py`) |
| Repo on VPS (pre-deploy) | `2ab6f79` on `master`/prior deploy |
| `systemctl is-active mail-proxy` | `active` (20h uptime before restart) |
| Effective routing (`systemctl show`) | `INBOUND_ROUTING_MODE=relationship_live`, `OUTBOUND_ROUTING_MODE=relationship_live`, `OUTBOUND_WATCH_MODE=relationship_only` |
| Poll interval (code constants) | `IMAP_POLL_INTERVAL = 60` |
| Unit tests @ `5cd6c23` on VPS | **157 run, 11 skipped, 0 failures** |
| Polled accounts | `refint1@frona.ru`, `refint2@bofoma.net` (both active) |
| Relationships | `clientint1@frona.ru` → `clientloc1@testvps.loc` / `refloc1@testvps.loc`; `clientint2@bofoma.net` → `clientloc2` / `refloc2` |
| IMAP INBOX inventory (BODY.PEEK probe, read-only) | `refint1`: TOTAL=44 UNSEEN=0; `refint2`: TOTAL=6 UNSEEN=0; `clientint1`: TOTAL=6 UNSEEN=5; `clientint2`: TOTAL=5 UNSEEN=4 — **lab test domains only** |
| Journal pre-test rows | 13 |
| Journal backup | `/root/prompt79-2g-backup-20260924T093441Z/mail_passage_journal.sql` (6039 bytes) |
| Pre-migration `SHOW CREATE` | `event_type` ENUM(`delivered`,`disposed`); no `detail` column |
| Pre-migration row checksum | `JOURNAL_CRC32_SAMPLE=20752887818` |

---

## Step 1 — Migration 005

| Check | Result |
|-------|--------|
| First apply `migrations/005_mail_passage_journal_skipped.sql` | OK |
| Second apply (idempotent) | OK (no error) |
| `event_type` | `ENUM('delivered','disposed','skipped') NOT NULL` |
| `detail` | `VARCHAR(1024) NULL` after `source_message_id` |
| Row count / checksum after | 13 rows; CRC unchanged |

---

## Step 2 — Deploy @ 5cd6c23 (post-G1)

| Item | Value |
|------|--------|
| Rollback dir | `/root/prompt79-2g-rollback-20260924T093629Z` |
| Post-deploy daemon MD5 | `627974b1b5ce947f1f76057113081b7b` |
| Startup | `ProxyDaemon operational` — no `[PROMPT-79.2-INTERIM]` markers |
| Journal write errors on startup | None observed in log window |

---

## Step 3 — Panel checks

| Check | Result |
|-------|--------|
| `php -l` `web/includes/relationship_status.php`, `web/relationship-status.php` | OK |
| `php tests/panel_mail_passage_journal_test.php` | **OK** (skipped/disposed labels, passage lines, views) |
| Browser ru/en UI, skipped filter, `detail` truncation | **NOT VERIFIED** (no browser session; CLI harness only) |

---

## Step 4 — Inbound matrix

**Primary path:** SMTP from `clientint1@frona.ru` → `refint1@frona.ru`.  
**Fallback path B:** IMAP APPEND to referent INBOX (no `\Seen`).  
**Evidence logs:** `/tmp/prompt79_2g_matrix_run.log`, `/tmp/prompt79_2g_l18.log`, daemon window in `/tmp/prompt79_2g_log_window.sh` output.

**Maildir note:** Daemon logs `maildir=` under `clientloc1` path but SMTP RCPT is `refloc1@testvps.loc`. Fan-out children appeared under **referent** `Maildir/new` (`refloc1-…`), not `clientloc1/cur` (3 legacy files). Subjects below are from `refloc1/.../Maildir/new`.

| Case | Path | Journal / daemon | Maildir children | Verdict |
|------|------|------------------|------------------|---------|
| **L01** | A | 1× `delivered`; `inbound_attach=deliver count=1`; source Seen, present | `Subject: Report.ZIP` in refloc1/new | **PASS** |
| **L02** | A | `disposed` `subject_mismatch`; detail `expected=a.zip \| actual=wrong`; source gone | none | **PASS** |
| **L03** | A | 2× `delivered`; count=2 | `a.zip`, `b.zip` subjects (2 files) | **PASS** |
| **L04** | A | 1× `delivered` | NFC/NFD zip subject present (base64 Subject in new) | **PASS** (subject decode not manually re-checked byte-for-byte) |
| **L05** | A | 1× `delivered` | `x.png` subject | **PASS** |
| **L06** | A | `disposed` `zero_attachments`; daemon `detail=None` | none | **PASS** (journal detail empty — see defect D2) |
| **L07** | A | `disposed` `zero_attachments`; detail `inline_parts=1 names=attachment.png` | none | **PASS** |
| **L08** | A | 1× `delivered`; skipped=() | `pack.zip` only | **PASS** |
| **L09** | B | 2× `skipped` disallowed + 1× `delivered`; skipped `evil.exe`,`icon.svg` | zip delivered | **PASS** |
| **L10** | B | `skipped` + `disposed` disallowed | source gone | **PASS** |
| **L11** | B | `skipped` + `disposed` missing_filename | source gone | **PASS** |
| **L12** | B | `skipped` + 1× `delivered` | `z.zip` | **PASS** |
| **L13** | A | 20× `delivered`; `count=20 indexes=0..19` | `p0.png`…`p19.png` subjects | **PASS** |
| **L14** | A | `disposed` `too_many_attachments`; detail lists 21 names | none | **PASS** |
| **L15** | A | `disposed` `zero_attachments` | none | **PASS** |
| **L16** | A | 2× `delivered` | two `a.zip` subjects | **PASS** |
| **L17** | A | 3× `delivered` | three zip name subjects | **PASS** |
| **L18** | B (raw MIME) | `skipped`/`disposed` `disallowed_extension` `filename=safe ext=` — no delivery | no child; no injection test | **FAIL** — see D1 |
| **L19** | A | `disposed` `no_relationship`; source gone | none | **PASS** |
| **L20** | — | — | — | **NOT VERIFIED** (G2 not used) |
| **L21** | B (`message/rfc822`) | 2× journal (`skipped`+`disposed` disallowed `fwd.eml`); source removed | not UNSEEN re-poll | **FAIL** — see D3 |

---

## Step 5 — Outbound regression

Injected to client watch `Maildir/new` (`clientloc1` path). Journal:

| Case | Expected | Observed | Verdict |
|------|----------|----------|---------|
| **O01** | 1× outbound `delivered` + receipt at `clientint1@frona.ru` | **2×** `delivered` rows (10:04:07 and 10:08:59 UTC); internet receipt not re-checked via IMAP | **FAIL** duplicate journal — D4 |
| **O02** | `disallowed_extension` + notify referent | **2×** `disposed` rows; `notified=1` | **FAIL** duplicate — D4 |
| **O03** | `multiple_attachments` + notify | **2×** `disposed` rows; `notified=1` | **FAIL** duplicate — D4 |

---

## Step 6 — Log and journal audit

| Check | Result |
|-------|--------|
| ERROR/CRITICAL/Traceback since `PROMPT79-2g_RESTART` | **None** |
| `detail` length > 1024 | None observed |
| Journal timestamps | UTC (`action_at`/`event_ts` style consistent with prior prompts) |
| Duplicate rows (completed cases) | **Yes** — outbound O01–O03 doubled (~90s apart); inbound otherwise 1:1 per case |
| Test row prefix | `79-2g-20260924T093629` — **not deleted** (audit retained) |

Post-run journal event counts (this RUNID only): see VPS query in appendix; inbound delivered/skipped/disposed align with table above.

---

## Step 7 — Cleanup

| Action | Result |
|--------|--------|
| Remove Sent messages with `X-Lab-Test: 79-2g-20260924T093629` | **0 removed** — client `Sent` folder name not matched (IMAP SEARCH in AUTH failed for some folders); **manual Sent cleanup may remain** |
| L20 temp config | N/A |
| Daemon version | Left on **5cd6c23** |
| Fan-out children in `refloc1/.../Maildir/new` | **Left in place** (43 files in `new` after run) |

---

## Defects (minimal repro)

| ID | Severity | Summary | Repro |
|----|----------|---------|-------|
| **D1** | High (L18) | Header-injection case did not reach deliver path; CRLF filename parsed as `safe` without `.zip` → `disallowed_extension`. | APPEND raw `Content-Disposition: attachment; filename="safe\r\nBcc: x@example.com.zip"` → journal skipped+disposed, no child. Need RFC2231/2047 filename per spec. |
| **D2** | Low | L06 `zero_attachments` journal/log `detail` NULL while L07 includes `inline_parts=1`. | L06 inline PNG without filename → disposed with empty detail. |
| **D3** | High (L21) | `message/rfc822` attachment not on documented poison hold; disposed as disallowed `.eml` with journal rows. | APPEND MIME with `MIMEApplication(..., _subtype='rfc822')` filename `fwd.eml` → 2 journal rows, message expunged. |
| **D4** | High | Duplicate outbound journal rows for single injected file per O01–O03. | One `.eml` in `clientloc1/.../new` → two identical disposal/delivery journal entries ~90s apart. |

---

## Script appendix (VPS `/tmp`, repo `.keys/`)

| Script | Purpose |
|--------|---------|
| `prompt79_2g_step0.sh` | Baseline + journal backup |
| `prompt79_2g_migration005.sh` | Migration 005 idempotency |
| `prompt79_2g_deploy.sh` | Deploy 5cd6c23 + rollback snapshot |
| `prompt79_2g_imap_inventory.sh` | INBOX TOTAL/UNSEEN |
| `prompt79_2g_matrix.py` | Inbound L01–L17 (+ partial L18 crash) |
| `prompt79_2g_l18_l19_l21.py` / `prompt79_2g_l21_only.py` | Tail cases |
| `prompt79_2g_outbound.sh` | O01–O03 |
| `prompt79_2g_audit.sh` / `prompt79_2g_final_audit.sh` | Journal aggregates |
| `prompt79_2g_cleanup.py` | Sent cleanup (partial) |

---

## Rollback status

- Migration **005** applied (additive); rollback = daemon/binaries from `/root/prompt79-2g-rollback-20260924T093629Z` only — **do not** revert ENUM/column.
- Current production on lab: **5cd6c23** deployed.

---

## Verdict (G3)

**Blocked — not ready to merge** until D1, D3, and D4 are understood/fixed (and L18/L21 re-verified). L20 remains NOT VERIFIED by design. Panel browser i18n/filter NOT VERIFIED.

**Recommended next steps:** fix or clarify poison (`message/rfc822`) path; investigate outbound double-journal; re-run L18 with RFC2231 filename lab MIME; optional G2 for L20; operator Sent-folder cleanup for RUNID tag.
