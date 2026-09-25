# PROMPT-79.2o — Consolidated panel re-verification and G3 status rollup

**Date:** 2026-09-25 (UTC)  
**Branch:** `prompt-79-2c-inbound-multi-attach-split`  
**Commit under test:** `a472e903994f3ad8da223f855177795fd9d1e5a5` (PROMPT-79.2n tip)  
**Lab:** disposable copy `192.168.125.116` (`hostname=mail`) — confirmed available before O1  
**Mode:** VERIFY ONLY — no product code changes, no merge  
**Choice of document:** **new** `PROMPT-79-2o-consolidated-status.md` (this file). The existing `PROMPT-79-2g-lab-verify.md` is already a long historical log (79.2g matrix + 79.2h/i/j/k addenda). A short rollup file keeps that history readable and gives operators one current non-contradictory table. A one-line pointer to this file was added at the top of `PROMPT-79-2g-lab-verify.md`.

---

## O1 — Full regression (single pass)

### Deploy / identity

| Item | Value |
|------|--------|
| Pre-O1 VPS repo | `5597a48` (79.2j daemon); panel already had 79.2n tabs from prior deploy |
| O1 action | `git reset --hard a472e90`; rsync `web/` → `/var/www/mail-proxy` (preserved `config.php`) |
| Panel backup | `/root/prompt79-2o-panel-backup-20260925T114036Z` |
| Daemon restart | **Not required / not performed** — `git diff 5597a48..a472e90` is panel/tests only; ActiveEnterTimestamp remained `08:20:51 UTC` through O1 |
| `mail-proxy` / `nginx` | `active` |
| Attempt #1 note | Failed unittest due to **local worktree overlay corruption** from Windows `tar` — see [Incident log](#incident-log--o1-first-attempt-worktree-corruption-not-a-product-defect). Attempt #2 (clean reset) is the authoritative O1 result. |

### Automated suites @ `a472e90`

| Suite | Result |
|-------|--------|
| `python -m unittest discover -s tests` | **171 ran / 11 skipped / 0 fail** |
| `php tests/panel_mail_passage_journal_test.php` | **OK** — 110 `OK:` lines / 0 `FAIL:` |

### Live authenticated HTML (a–g) — all PASS

Evidence: `/tmp/prompt79_2o_html.txt`, `/tmp/prompt79_2o_unittest.txt`, `/tmp/prompt79_2o_panel_test.txt`

| Check | Result | Notes |
|-------|--------|-------|
| **a** Tab exclusivity | **PASS** | `tab=passage` / default: no `#nonstandard-*`; `tab=nonstandard`: no `#passage-*` |
| **b** Bilingual ru/en | **PASS** | Tab headers localized; ≥3 reason/event spot-hits per lang; `No relationship` ≠ `Нет связи` |
| **c** Passage filters | **PASS** | referent `fanout`→2; client `a`→47; literal `%`→0 (no PHP error); date same-day→9; inbound 46 / outbound 5; combo AND → 0 (valid empty intersection) |
| **d** Nonstandard combo | **PASS** | `ns_*` five-way AND → 0 (valid); `ns_event=skipped` alone all-skipped |
| **e** Malformed dates | **PASS** | `2026-13-99` ignored: passage 51=51, ns 39=39 |
| **f** Legacy `event_filter` | **PASS** | Alias into `ns_event`: `event_filter=skipped` → 10 skipped rows |
| **g** PHP/nginx errors | **PASS** | nginx error.log delta **0 bytes**; `ERR_CLEAN True` |

---

## Consolidated G3 status table (current)

Statuses below supersede scattered “ready / blocked / K4 NOT VERIFIED” wording from earlier reports where this rollup conflicts. Detail evidence remains in the cited reports.

| Area | Item | Status | Last confirmed |
|------|------|--------|----------------|
| **Backend / inbound** | L01–L17, L19 matrix (79.2g) | **PASS** | 79.2g @ `5cd6c23` |
| | L18 (RFC2231 / missing filename harness) | **PASS** (product; early FAIL was harness — 79.2h) | 79.2h addendum in 79.2g doc |
| | L21 / nested `message/rfc822` | **PASS** after 79.2j | 79.2k K2 @ `5597a48` |
| | K5 quick inbound (L01/L09/L14) | **PASS** | 79.2k @ `5597a48` |
| **Backend / outbound** | K1 clean single outbound (closes V2/D4) | **PASS** | 79.2k @ `5597a48` |
| | O01–O03 early 79.2g duplicates | **Superseded** by K1 harness fix | 79.2k |
| **Backend / partial SMTP** | K3 / L20 partial fan-out failure + recovery | **PASS** | 79.2k-2 disposable copy @ `5597a48` (G2 + `source_patch`) |
| **Unit tests** | `unittest discover -s tests` | **PASS** 171/11sk/0fail | **79.2o** @ `a472e90` |
| **Panel — i18n** | Reason/event/direction bilingual (79.2l I1) | **PASS** | 79.2l; **reconfirmed 79.2o** |
| **Panel — event filter** | skipped/disposed (`ns_event`, was `event_filter`) | **PASS** | 79.2l I2; **reconfirmed 79.2o** (incl. legacy alias) |
| **Panel — party filters** | referent dropdown + client LIKE escape (79.2m) | **PASS** | 79.2m; **reconfirmed 79.2o** under `passage_*` / `ns_*` |
| **Panel — layout** | 79.2m stacked two-card | **REJECTED** (UX) → replaced by 79.2n | human feedback; 79.2n |
| **Panel — tabs + columns** | Real tabs; date/direction/event column filters (79.2n) | **PASS** | 79.2n; **full O1 reconfirm 79.2o** |
| **Panel — PHP suite** | `panel_mail_passage_journal_test.php` | **PASS** 110 OK | **79.2o** @ `a472e90` |
| **Ops / docs** | Issues #34 (`message/*`), #35 (client display name) | **Out of scope** for PR #33 | tracked separately |

### Still NOT VERIFIED (overall)

| Item | Why |
|------|-----|
| **Playwright / human browser screenshots** of the panel | Agent used authenticated HTTPS HTML fetch (cookie + CSRF), not a headed browser. Functional checks a–g passed; visual polish not screenshot-certified. |
| **K3 without `source_patch`** | Env/systemd cannot override `LOCAL_SMTP_PORT` (hardcoded). 79.2k-2 PASS used documented one-line patch on the disposable copy only. |
| **Production (non-lab) burn-in** | All live mail proofs are on the disposable lab copy / prior lab VPS — not a production mailbox campaign. |

Nothing else from the K1–K5 / panel i18n / filter / tabs checklist remains NOT VERIFIED after O1.

---

## Incident log — O1 first-attempt worktree corruption (not a product defect)

**Why recorded:** the first O1 unittest run failed, then a `git reset --hard a472e90` made the suite green. That must not read as “it fixed itself” before merge — root cause and blast radius are below.

### What happened (timeline, UTC)

| Time | Event |
|------|--------|
| **08:20:51** | `mail-proxy` last entered active (`ActiveEnterTimestamp`); MainPID=4483. |
| **11:39:10** | O1 attempt #1 starts. Script checks out `a472e90`, then overlays `/tmp/panel79_2o` into `/root/Proxy_Email`. |
| **11:39** | Agent workstation used **Windows `tar -cf - … \| ssh tar -xf`** to stream `tests/` and daemon `*.py` into `/tmp/panel79_2o`. Extract printed `tar: Skipping to next header` / non-zero exit — **corrupt/incomplete members**. |
| **11:39** | Overlay `cp -a /tmp/panel79_2o/tests/.` → `/root/Proxy_Email/tests/` overwrote the clean checkout. Unittest: **169 ran, 1 fail, 1 error, 11 skipped** (`SyntaxError` in `test_purge_mail_passage_journal.py`; Cyrillic `AssertionError` in notify wording test). |
| **11:40:35** | O1 attempt #2: `git reset --hard a472e90` (no Windows tar overlay of Python). Unittest **171/11sk/0fail**; panel + HTML PASS. |
| O1 window 11:30–11:50 | **`journalctl -u mail-proxy`:** no start/stop/restart. Daemon **not** restarted. |

### Blast radius — what was / was not damaged

| Asset | Status |
|-------|--------|
| **Git commit / GitHub push (`a472e90` and later `3763e66`)** | **Untouched.** Failures were not in origin blobs. |
| **VPS git objects** | **Intact.** |
| **VPS working tree `/root/Proxy_Email` during attempt #1** | **Temporarily corrupted** (tests overlaid from bad tar). Restored by `git reset --hard`. |
| **Leftover `/tmp/panel79_2o/`** | Still present as evidence (not used by running services). |
| **`/usr/local/bin/*.py` (running daemon)** | **Never overwritten by O1.** Still byte-match repo at `a472e90` (see checksums). |
| **Product runtime / journal / panel config** | No incident impact beyond aborted unittest #1. |

### Smoking gun still on disk (`/tmp/panel79_2o`)

`tests/test_purge_mail_passage_journal.py` in the overlay:

- md5 `d5438631…` vs clean repo `b5b075bd…` (same length 3048 bytes, **different content**)
- **86 CRLF** line endings (Windows tar) vs repo **LF-only**
- Line 87 truncated mid-token: `self.assertEqual(section.ge` → `SyntaxError: '(' was never closed`  
  (matches attempt #1 error exactly)

Root cause: **agent transfer hygiene** (Windows `tar` over SSH), not DELTA-transit code and not a bad push.

### Checksums after `git reset --hard a472e90` vs origin blob

Re-verified on lab 2026-09-25: worktree at `a472e90`, each file md5 == `git cat-file -p a472e90:<path> | md5sum`. `git diff a472e90 -- <files>` empty. `ALL_MATCH_A472E90=1`.

| File | md5 |
|------|-----|
| `mail-proxy-daemon.py` | `acc80285b27ceffcc04c16b57911cdf9` |
| `relationship_lookup.py` | `2e068e8366b9cb95270a70b5b6861f85` |
| `relationship_routing.py` | `a733fd787a0bf624280f5ebe81d17c91` |
| `message_rebuild.py` | `1fe06361ce40c0a6c5b53991b3939ee8` |
| `attachment_policy.py` | `6ae4eaeecf4efadab8e4a752d284a54f` |
| `mail_passage_journal.py` | `7b208307c2612551a38accb55b671bac` |
| `mail_disposal.py` | `7d5e626f8b0b4b14e255898ae0b323b8` |
| `referent_notify.py` | `61c43e30bb201b5ed052e9b67c99b2f5` |
| `tests/test_purge_mail_passage_journal.py` | `b5b075bd3dbc2f87aab5ce52a094b27c` |
| `tests/test_mail_passage_journal.py` | `b0e0af3baad2850a9c29afca50266387` |

`/usr/local/bin/{same eight daemon modules}` → **BIN_MATCH** each vs that worktree (daemon codepath not part of the tar incident).

### Lesson for future lab ops

Do **not** stream project trees with Windows `tar` into the VPS overlay. Prefer `git fetch` + `reset --hard` on the host, or `scp` of individual text files. Never `cp` a failed tar extract over a clean checkout.

---

## G3 recommendation (rollup)

**PR #33 @ `a472e90` is functionally ready for operator merge decision**, with documentation caveats only:

1. Backend lab matrix + K1/K2/K3/K5 closed (K3 via disposable-copy `source_patch`, disclosed).  
2. Panel path 79.2l → 79.2n complete; 79.2m layout abandoned; **79.2o** reconfirmed tabs, bilingual labels, party + column filters, legacy `event_filter` alias, clean error log.  
3. Out-of-scope: Issue #34, Issue #35.  
4. No merge in this prompt — operator G3 confirmation still required.

---

## Files touched this prompt

- `docs/reports/PROMPT-79-2o-consolidated-status.md` (this report)  
- `docs/reports/PROMPT-79-2g-lab-verify.md` — pointer to this rollup at top  

No application code changes.
