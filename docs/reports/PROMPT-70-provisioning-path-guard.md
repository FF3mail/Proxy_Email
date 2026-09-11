# PROMPT-70 — Provisioning-time maildir path collision guard

**Branch:** `prompt-70-provisioning-path-guard` (from `origin/master` @ `39058d4`)  
**Scope:** Application-layer panel save validation only — no daemon, schema, or migration changes.

---

## 1. Diff summary

| File | Change |
|------|--------|
| `web/includes/relationship_editor.php` | `normalizeRelationshipMaildirPath()` (trim, collapse `//`, strip trailing `/`); applied in `parseRelationshipFormPost()`; `evaluateRelationshipMaildirPathCollision()` + `findRelationshipMaildirPathCollision()` called from `findRelationshipUniqueCollision()` |
| `web/lang/en.php`, `web/lang/ru.php` | `relationship.error.maildir_path_duplicate`, `maildir_referent_outbox_collision`, `maildir_referent_outbox_symmetric` |
| `tests/panel_relationship_path_guard_test.php` | Unit tests for cases (a)–(d) + lab-shaped re-save fixtures |

### Collision logic

1. **Cross-relationship maildir duplicate** — normalized `local_client_maildir` must not match any other `clients.local_client_maildir` (any `referent_id`), excluding the row being edited.
2. **Other referent outbox** — reject if normalized maildir equals another referent's `local_outbox`.
3. **Own referent outbox (PROMPT-67 accepted case)** — allow when normalized maildir equals own referent's `local_outbox` **and** no other relationship on that referent already claims the same normalized path; otherwise reject (symmetric collision / second claimant).

Path normalization reuses the same hygiene as `maildir_resolver.php` (`preg_replace('#/+#', '/')` + `rtrim(..., '/')`), extended into form parsing so stored values and comparisons share one scheme.

---

## 2. PROMPT-67-accepted case / lab re-save (Task 3)

**Live VPS query** (`192.168.125.116`, `referent_id=1`):

```text
1  /var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir
2  /var/vmail/vmail1/testvps.loc/c/l/i/clientloc2-2026.09.09.12.26.00/Maildir
```

Neither path equals referent #1 `local_outbox` (`.../refloc1-2026.09.01.10.49.35/Maildir`). Paths are pairwise distinct.

**Validation re-save simulation** (`evaluateRelationshipMaildirPathCollision` with `excludeId` = row id):

| Row | Result |
|-----|--------|
| Relationship #1 | **Accepted** (null) |
| Relationship #2 | **Accepted** (null) |

The single-relationship-equals-own-referent-outbox case is explicitly tested as case **(b)** and remains saveable.

---

## 3. Unit test results

Command: `php tests/panel_relationship_path_guard_test.php`

| Case | Description | Result |
|------|-------------|--------|
| (a) | Two relationships, same `local_client_maildir` | **PASS** — rejected |
| (b) | Own referent `local_outbox`, no other claimant | **PASS** — accepted |
| (c) | Second relationship also claims same referent outbox | **PASS** — rejected |
| (d) | Trailing-slash normalization | **PASS** — collision detected |
| Lab #1 / #2 re-save | PROMPT-61-shaped paths | **PASS** — accepted |
| Normalization helper | `//` + trailing `/` | **PASS** |

**7/7 OK, 0 FAIL**

---

## 4. Future DB-level UNIQUE constraint

**Not implemented in this PROMPT** (per Task 2).

A future schema-level `UNIQUE` on `clients.local_client_maildir` would still need:

- Normalized-path enforcement at the DB layer (functional index or trigger), or risk missing `path/` vs `path` duplicates.
- An explicit exception for the PROMPT-67 accepted case (one relationship per referent may equal that referent's `local_outbox`).
- A data audit / cleanup pass on any existing lab or production rows that violate strict uniqueness.

**Recommendation:** keep application-layer guard (this PROMPT) as the operational contract; if a DB constraint is desired, scope it as a **separate PROMPT** after a production data inventory — not bundled here.

---

## Out of scope (unchanged)

- `mail-proxy-daemon.py` PROMPT-67 runtime guard
- DB migrations / `schema.sql`
- Per-referent mode selection (PROMPT-71)
