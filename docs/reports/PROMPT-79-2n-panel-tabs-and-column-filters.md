# PROMPT-79.2n — Panel tabs and column filters

**Date:** 2026-09-25  
**Branch:** `prompt-79-2c-inbound-multi-attach-split` (PR #33, no merge)  
**Lab:** disposable copy `192.168.125.116` (hostname=`mail`; available; panel backup `/root/prompt79-2n-panel-backup-20260925T102434Z`)

---

## Why 79.2m was rejected

Human UI/UX feedback rejected the **two stacked cards on one scroll**. Having Message passage and Nonstandard events visible at once (even with per-card toolbars) felt like two windows fighting for attention. Required instead: **real tabs** — only one table’s markup in the page body at a time.

---

## Tab mechanism

| Item | Choice |
|------|--------|
| Param | `tab=passage` (default) / `tab=nonstandard` |
| UI | `#journal-tabs` bar with `#tab-passage` / `#tab-nonstandard`; active class + `aria-selected` |
| Body | PHP `if ($isPassage): … else: … endif;` — inactive tab’s card/table is **not emitted** (not `display:none`) |
| `lang` | Hidden field on each form; tab links also carry `lang` + shared `limit` |
| Filter namespaces | Passage: `passage_*`. Nonstandard: `ns_*`. No shared state across tabs |

### Shared `limit` choice

**Same GET param `limit`**, duplicated as a control on whichever tab is active (two `name="limit"` fields in source, one per branch). Avoids a second limit namespace while keeping the control on the visible form.

---

## Query changes (`buildRelationshipStatusPageData`)

Builder now takes an **options array** and loads **only the active tab’s rows**.

| Tab | Filters (all optional, AND, bound params) |
|-----|-------------------------------------------|
| Passage | `passage_referent`, `passage_client` (LIKE + `escapeSqlLikeWildcards`), `passage_date_from` / `passage_date_to`, `passage_direction` |
| Nonstandard | `ns_referent`, `ns_client`, `ns_date_from` / `ns_date_to`, `ns_event` (replaces `event_filter`: all/skipped/disposed), `ns_direction` |

Date validation: `normalizePanelDateFilter()` accepts only real `Y-m-d`; malformed values cleared and **not** bound. Inclusive `date_to` via `event_ts < DATE_ADD(?, INTERVAL 1 DAY)`.

Page still accepts legacy `event_filter` as a fallback alias into `ns_event` for one release.

---

## i18n

Added to `web/lang/en.php` and `web/lang/ru.php`:

- `observability.tab_passage` / `observability.tab_nonstandard`
- `observability.column_filters_legend`
- `observability.filter_date_from` / `filter_date_to` / `filter_direction_label` / `filter_direction_all`
- Reused `filter_all` / `filter_skipped` / `filter_disposed` for `ns_event`

---

## Tests

`tests/panel_mail_passage_journal_test.php` — **OK** on lab PHP 8.3.

Covers: tab-only query load; `ns_event` (79.2l I2); passage/ns date + direction; malformed dates ignored; party filters + LIKE escaping (79.2m); bilingual labels (79.2l I1); source structure (`else:` between cards); no `id="event_filter"`.

---

## Lab verification (authenticated HTML)

| Check | Result |
|-------|--------|
| `?tab=passage` — no `#nonstandard-card` / `#nonstandard-table` / `#ns_event` | **True** |
| `?tab=nonstandard` — no `#passage-card` / `#passage-table` / `#passage_referent` | **True** |
| Default (no tab) → passage only | **True** |
| Passage direction inbound/outbound narrows (46 / 5 of 51) | **True** |
| Passage date same-day inclusive | **True** (9 rows on sample day) |
| Malformed `passage_date_from=2026-13-99` ignored | **True** (51 = default) |
| `ns_event=skipped` → 10 all skipped; `disposed` → 29 | **True** |
| `ns_direction=outbound` all match | **True** |
| Old `event_filter` id absent | **True** |

---

## Files

- `web/includes/relationship_status.php` — tab constants, date/direction normalizers, options-array builder
- `web/relationship-status.php` — tab bar + exclusive card branches + column filter fieldsets
- `web/lang/{en,ru}.php` — tab + column-filter strings
- `tests/panel_mail_passage_journal_test.php` — rewrite for 79.2n

---

## Follow-ups (out of scope)

- **Issue #35** — client display name  
- **Issue #34** — `message/*` subtypes  

Folds into pending PR #33 G3 recommendation. **No merge.**
