# PROMPT-79.2m — Panel filter placement and passage scoping

**Date:** 2026-09-25  
**Branch:** `prompt-79-2c-inbound-multi-attach-split` (PR #33, no merge)  
**Lab:** disposable copy `192.168.125.116` (available; backup `/root/prompt79-2m-panel-backup-20260925T095347Z`)

---

## Before / after layout

**Before (79.2l):** one page-level toolbar above both cards with `limit` + `event_filter`. Unclear that `event_filter` only affects Nonstandard events; Message passage had no party filters.

**After:**

| Card | Controls |
|------|----------|
| **Message passage** (`#passage-card` / `#passage-toolbar`) | Shared `limit` (note: applies to both tables), referent dropdown, client text search |
| **Nonstandard events** (`#nonstandard-card` / `#nonstandard-filter-form`) | `event_filter` (all / skipped / disposed), plus the same referent + client filters |

`event_filter` (`id="event_filter"`) appears only inside the nonstandard card — verified by source placement tests and live HTML.

### Shared limit choice

Kept **one shared `limit`** in the passage toolbar with caption `observability.limit_shared_note` (“Applies to both tables on this page.”). Avoids splitting into two params with little UX gain.

### Referent options source

`SELECT DISTINCT referent_name FROM mail_passage_journal …` — matches names actually shown in rows; avoids an extra join to `referents` and stays cheap (small staff set).

---

## Query changes

- Passage: `WHERE event_type = 'delivered'` + optional `referent_name = ?` + optional `client_name LIKE ? ESCAPE '\\'` + `LIMIT ?`.
- Nonstandard: same party filters on top of the existing event_type clause.
- Client substring: `escapeSqlLikeWildcards()` escapes `\`, `%`, `_` before wrapping with `%…%`; bound parameter only (no string concat of user input into SQL). Case-insensitive match via MySQL/MariaDB default collation (`LIKE` on utf8 strings).

Party filters apply to **both** tables (low extra cost, consistent UX).

---

## Tests

`tests/panel_mail_passage_journal_test.php` — **OK** on lab (includes 79.2l regressions + M1/M2):

- Markup: `event_filter` / `nonstandard-filter-form` not inside `#passage-card` section.
- `passage_referent` narrows to matching rows.
- Client `Proton` / `proton` → both Proton rows, not Acme; literal `%`/`_` escaping; bare `%` does not match everything.
- Combined referent+client = intersection.
- Skipped/disposed filters unchanged.

---

## Lab verification (authenticated)

| Check | Result |
|-------|--------|
| `event_filter` under nonstandard card | **True** |
| Not in passage section | **True** |
| Passage has referent + client controls | **True** |
| Referent filter (e.g. `fanout`) | 2 rows, all matching |
| Client filter `a` | 47 rows, all contain `a` (case-insensitive) |

---

## Follow-ups (out of scope)

- **Issue #35** (client display name): when it lands, extend the client text filter to search display name as well as `client_name`.
- **Issue #34** (`message/*` subtypes): unchanged.

Folds into existing PR #33 G3 recommendation (still pending operator confirmation of this UX fix). No merge.
