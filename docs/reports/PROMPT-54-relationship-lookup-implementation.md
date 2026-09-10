# PROMPT-54 — Additive Schema + RelationshipLookup Implementation

**Date:** 2026-09-09  
**Branch:** `prompt-47-panel-authorization-audit`  
**Starting commit:** `b23522197295cf83d0264cad9e6ba9b5b87fa4a2` (tip after PROMPT-53 lineage)  
**Mode:** Implementation + tests (additive only)  
**Business baseline:** PROMPT-51/52/53 (frozen; U1–U4 not reopened)

---

## 1. Executive summary

PROMPT-54 delivers:

1. **Additive migration SQL** — `migrations/002_client_relationship_columns.sql` (PROMPT-53 §19, idempotent).
2. **Fresh-install schema update** — `schema.sql` `clients` table extended (legacy columns retained).
3. **Standalone lookup module** — `relationship_lookup.py` implementing PROMPT-53 §10–§13.
4. **Unit tests** — `tests/test_relationship_lookup.py` (PROMPT-53 §22 matrix).

**Not changed:** `mail-proxy-daemon.py` routing (verified: no import of `relationship_lookup`).  
**Not changed:** production VPS `mail_proxy` schema, mailboxes, MTA, or live routing.

---

## 2. TASK 1 — Additive DDL

### 2.1 Deliverable

| Artifact | Purpose |
|----------|---------|
| `migrations/002_client_relationship_columns.sql` | Upgrade path for existing databases |
| `schema.sql` (clients section) | Fresh installs include new columns + FK |

### 2.2 Columns added to `clients` (all NULLable)

| Column | Type |
|--------|------|
| `external_client_email` | VARCHAR(255) NULL |
| `local_client_email` | VARCHAR(255) NULL |
| `local_referent_email` | VARCHAR(255) NULL |
| `external_account_id` | INT UNSIGNED NULL |
| `local_client_maildir` | VARCHAR(512) NULL |

### 2.3 Constraints added (PROMPT-53 §19)

| Name | Type |
|------|------|
| `uq_clients_external_client` | UNIQUE (`external_client_email`) |
| `uq_clients_local_client` | UNIQUE (`local_client_email`) |
| `uq_clients_local_referent` | UNIQUE (`local_referent_email`) |
| `uq_clients_external_account` | UNIQUE (`external_account_id`) |
| `fk_clients_external_account` | FK → `external_accounts(id)` **ON DELETE RESTRICT** |

### 2.4 Legacy columns retained

- `clients.email` — unchanged, not used by new lookup code
- `referents.local_inbox` / `local_outbox` — unchanged, not used by new lookup code

### 2.5 Application status

| Target | Status |
|--------|--------|
| Production VPS `mail_proxy` | **NOT modified** (per prompt boundary) |
| Local MySQL/MariaDB | **Not available** on dev workstation (port 3306 closed) |
| Reviewable SQL | **Ready** — apply manually on dev/staging: `mysql mail_proxy < migrations/002_client_relationship_columns.sql` |

### 2.6 Constraint verification (expected after apply)

Run on dev DB after migration:

```sql
DESCRIBE clients;
SHOW INDEX FROM clients;
SHOW CREATE TABLE clients\G
```

**Expected `DESCRIBE clients` new columns** (NULL = YES until backfill):

```text
external_client_email | varchar(255) | YES | UNI | NULL |
local_client_email    | varchar(255) | YES | UNI | NULL |
local_referent_email  | varchar(255) | YES | UNI | NULL |
external_account_id   | int unsigned | YES | UNI | NULL |
local_client_maildir  | varchar(512) | YES |     | NULL |
```

**Expected new indexes on `clients`:**

```text
uq_clients_external_client  (external_client_email)
uq_clients_local_client     (local_client_email)
uq_clients_local_referent   (local_referent_email)
uq_clients_external_account (external_account_id)
```

**Expected FK:** `fk_clients_external_account` → `external_accounts(id)` RESTRICT.

> **Note:** Actual `DESCRIBE`/`SHOW INDEX` output was not captured in this session because no safe local/dev MySQL instance was reachable and production VPS schema was intentionally not altered. Re-run the commands above on staging after applying the migration file.

---

## 3. TASK 2 — RelationshipLookup module

### 3.1 File

`relationship_lookup.py` (repo root, alongside `mail-proxy-daemon.py`)

### 3.2 API (PROMPT-53 §10)

| Method | Contract |
|--------|----------|
| `resolve_inbound(external_account_id, external_sender_email)` | §11 query; returns `ClientRelationshipDTO` or `None` |
| `resolve_outbound(local_recipient_email)` | §12 query; returns DTO or `None` |
| `list_poll_targets()` | Valid relationships → `[{account, relationship, referent}]` |
| `list_watch_targets()` | Valid relationships → `[{local_client_maildir, relationship}]` |

### 3.3 Valid relationship predicate (§9)

Included in all routing queries:

- `c.active = 1`, `ea.active = 1`, `r.active = 1`
- All four address fields non-NULL and non-empty
- `external_account_id` NOT NULL
- `local_client_maildir` non-empty
- `ea.referent_id = c.referent_id`

### 3.4 Normalization

`normalize_email()` — lowercase + trim on all lookup inputs (§8/§10).

### 3.5 Daemon isolation

```bash
grep relationship_lookup mail-proxy-daemon.py
# (no matches)
```

---

## 4. TASK 3 — Unit tests

### 4.1 File

`tests/test_relationship_lookup.py`

### 4.2 Coverage matrix

| Area | Test |
|------|------|
| Email normalization | `NormalizeEmailTestCase` (no DB) |
| Referent 0 relationships | `test_referent_zero_relationships_no_poll_targets` |
| Inactive referent | `test_inactive_referent_not_routable` |
| Ivan 2 clients independent | `test_ivan_two_clients_independent_inbound` |
| Unknown sender | `test_unknown_sender_returns_none` |
| Wrong account id | `test_wrong_external_account_id_returns_none` |
| Outbound To resolve | `test_outbound_local_to_resolves` |
| Unknown local To | `test_unknown_local_recipient_returns_none` |
| Poll/watch discovery | `test_list_poll_and_watch_targets` |
| Incomplete relationship | `test_incomplete_relationship_excluded` |
| Duplicate external_client (DB) | `test_duplicate_external_client_rejected_by_db` |
| Duplicate external_account_id (DB) | `test_duplicate_external_account_id_rejected_by_db` |

### 4.3 Test execution (this session)

```text
python -m unittest tests.test_relationship_lookup.NormalizeEmailTestCase -v
# OK (1 test)

python -m unittest tests.test_relationship_lookup -v
# OK (skipped=11) — no local MySQL; integration tests require disposable DB
```

Configure integration tests:

```bash
export RELATIONSHIP_TEST_DB_HOST=127.0.0.1
export RELATIONSHIP_TEST_DB_USER=root
export RELATIONSHIP_TEST_DB_PASS=...
python -m unittest tests.test_relationship_lookup -v
```

Uses disposable database `mail_proxy_relationship_test` (DROP/CREATE each run).

---

## 5. Boundaries respected

| Check | Result |
|-------|--------|
| Destructive schema (DROP/RENAME legacy columns) | **NO** |
| `mail-proxy-daemon.py` routing changed | **NO** |
| Production VPS `mail_proxy` schema | **NOT touched** |
| Mailboxes / Maildir / MTA | **NOT touched** |
| OAuth / crypto structures | **Unchanged** |

---

## 6. Files changed

| File | Change |
|------|--------|
| `migrations/002_client_relationship_columns.sql` | **NEW** — idempotent additive migration |
| `relationship_lookup.py` | **NEW** — lookup module |
| `tests/test_relationship_lookup.py` | **NEW** — unit/integration tests |
| `schema.sql` | **UPDATED** — fresh-install clients columns + FK |
| `docs/reports/PROMPT-54-relationship-lookup-implementation.md` | **NEW** — this report |

---

## 7. Recommended next step

1. Apply `migrations/002_client_relationship_columns.sql` on **staging/dev** `mail_proxy`.
2. Paste actual `DESCRIBE`/`SHOW INDEX` output into this report (or PROMPT-54 follow-up).
3. Run full `tests/test_relationship_lookup.py` against staging disposable DB.
4. **PROMPT-55+:** panel relationship editor + operator backfill of four addresses (no daemon cutover until data exists).

---

## 8. Report integrity

| Check | Result |
|-------|--------|
| Production code routing changed | **NO** |
| Production DB schema changed | **NO** |
| VPS config/services changed | **NO** |
