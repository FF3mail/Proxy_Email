# PROMPT-55 — Lookup Fix + Provisioning Memo

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Starting commit:** `c53a4ddf3e462e30d20506df8a409e65d62578b4`  
**Mode:** Bugfix + missing PROMPT-54 Task 4 deliverable  
**Scope:** `relationship_lookup.py` syntax fix; Task 4 iRedMail provisioning memo; this report

---

## 1. Executive summary

PROMPT-54 shipped `relationship_lookup.py` with a missing `class DatabaseConnectionProvider(Protocol):` line. The module failed to parse; every unit test failed at import with `IndentationError` before any DB check. The PROMPT-54 “Verification gap / skipped MySQL” narrative was wrong for the delivered tree.

This prompt:

1. Restores the class declaration.
2. Records **real** `ast.parse` / unittest terminal output.
3. Delivers the missing PROMPT-54 Task 4 provisioning memo.
4. Confirms `mail-proxy-daemon.py` remains untouched.

---

## 2. TASK 1 — Syntax fix

### 2.1 Root cause

During a late PROMPT-54 edit, unused `_ACCOUNT_FIELDS` was removed with a search/replace that also deleted:

```python
class DatabaseConnectionProvider(Protocol):
```

leaving the class docstring and `get_connection` method at module indent, which is a syntax error.

### 2.2 Exact diff

```diff
diff --git a/relationship_lookup.py b/relationship_lookup.py
index bb6078c..f19211e 100644
--- a/relationship_lookup.py
+++ b/relationship_lookup.py
@@ -58,6 +58,8 @@ _VALID_RELATIONSHIP_WHERE = """
     AND ea.referent_id = c.referent_id
 """
 
+
+class DatabaseConnectionProvider(Protocol):
     """Minimal interface shared with mail-proxy-daemon.Database."""
 
     def get_connection(self):
```

### 2.3 `ast.parse` verification (actual)

Command:

```text
python -c "import ast; ast.parse(open('relationship_lookup.py', encoding='utf-8').read()); print('ast.parse: OK')"
```

Actual stdout:

```text
ast.parse: OK
```

Import check (actual):

```text
import: OK <class 'relationship_lookup.RelationshipLookup'>
```

### 2.4 Full unittest run (actual terminal output)

Command:

```text
python -m unittest tests.test_relationship_lookup -v
```

Actual output (captured 2026-09-10 on this workstation):

```text
test_normalize_email (tests.test_relationship_lookup.NormalizeEmailTestCase.test_normalize_email) ... ok
test_duplicate_external_account_id_rejected_by_db (tests.test_relationship_lookup.RelationshipLookupTestCase.test_duplicate_external_account_id_rejected_by_db) ... skipped 'MySQL test database not available'
test_duplicate_external_client_rejected_by_db (tests.test_relationship_lookup.RelationshipLookupTestCase.test_duplicate_external_client_rejected_by_db) ... skipped 'MySQL test database not available'
test_inactive_referent_not_routable (tests.test_relationship_lookup.RelationshipLookupTestCase.test_inactive_referent_not_routable) ... skipped 'MySQL test database not available'
test_incomplete_relationship_excluded (tests.test_relationship_lookup.RelationshipLookupTestCase.test_incomplete_relationship_excluded) ... skipped 'MySQL test database not available'
test_ivan_two_clients_independent_inbound (tests.test_relationship_lookup.RelationshipLookupTestCase.test_ivan_two_clients_independent_inbound) ... skipped 'MySQL test database not available'
test_list_poll_and_watch_targets (tests.test_relationship_lookup.RelationshipLookupTestCase.test_list_poll_and_watch_targets) ... skipped 'MySQL test database not available'
test_outbound_local_to_resolves (tests.test_relationship_lookup.RelationshipLookupTestCase.test_outbound_local_to_resolves) ... skipped 'MySQL test database not available'
test_referent_zero_relationships_no_poll_targets (tests.test_relationship_lookup.RelationshipLookupTestCase.test_referent_zero_relationships_no_poll_targets) ... skipped 'MySQL test database not available'
test_unknown_local_recipient_returns_none (tests.test_relationship_lookup.RelationshipLookupTestCase.test_unknown_local_recipient_returns_none) ... skipped 'MySQL test database not available'
test_unknown_sender_returns_none (tests.test_relationship_lookup.RelationshipLookupTestCase.test_unknown_sender_returns_none) ... skipped 'MySQL test database not available'
test_wrong_external_account_id_returns_none (tests.test_relationship_lookup.RelationshipLookupTestCase.test_wrong_external_account_id_returns_none) ... skipped 'MySQL test database not available'
----------------------------------------------------------------------
Ran 12 tests in 0.002s
OK (skipped=11)
```

Interpretation of **this** run:

| Result | Count | Meaning |
|--------|-------|---------|
| PASS | 1 | `NormalizeEmailTestCase.test_normalize_email` (no DB) |
| skipped | 11 | DB-dependent cases; MySQL genuinely unavailable locally |
| ERROR / FAIL | 0 | Import/syntax path is clean after the fix |

### 2.5 Pre-fix failure (actual, before this commit’s working-tree fix)

```text
File "C:\...\Proxy_email\relationship_lookup.py", line 61
    """Minimal interface shared with mail-proxy-daemon.Database."""
IndentationError: unexpected indent
```

and:

```text
File "...\tests\test_relationship_lookup.py", line 30, in <module>
    from relationship_lookup import RelationshipLookup, normalize_email
...
IndentationError: unexpected indent
```

### 2.6 How PROMPT-54 shipped this without catching it

Confirmed from the PROMPT-54 session evidence:

1. After removing `_ACCOUNT_FIELDS`, the class header was deleted; the file no longer imported.
2. The PROMPT-54 report claimed a “DB-free normalize test OK” and “11 skipped for MySQL.” That **would** be true for the **post-fix** tree, but was **not** true for the broken file at `c53a4dd` — at that tip, unittest never reached skip logic because import failed first.
3. A later background check of local MySQL (no service / no port 3306) was treated as explaining skips, and the broken import was not re-verified against `c53a4dd` before the report’s “skipped=11” claim was written.
4. Conclusion: the full suite was **not** successfully executed against the committed `relationship_lookup.py` at `c53a4dd`. The report documented an **expected/desired** outcome after an earlier incomplete run state, not the actual state of the committed module.

---

## 3. TASK 2 — PROMPT-54 Task 4 memo (local mailbox provisioning)

### 3.1 Question

Do `local_client_email` and `local_referent_email` each need a **distinct physical iRedMail mailbox**, or can iRedMail aliases (`virtual_alias_maps` / `vmail.forwardings`) map many local addresses into one physical mailbox?

### 3.2 Alias behavior vs daemon watch model

iRedMail can alias many recipient addresses onto one physical `vmail.mailbox` row. Delivery then lands in **one** Maildir tree.

`RelationshipLookup.list_watch_targets()` (PROMPT-53 §10) returns one watch entry per valid relationship keyed by **`local_client_maildir`**. Outbound pickup is filesystem-based (`Maildir/new`), not address-based at the MTA layer.

If several `local_client_email` values alias to the same physical mailbox:

```text
client1@local.loc  ─┐
c007@local.loc     ─┼──▶  same Maildir/new
sales-client3@…    ─┘
```

then `list_watch_targets()` either:

- stores the **same path** on multiple relationships → one Observer watch / shared queue of files with no per-relationship Maildir isolation, or
- falsely claims distinct paths while delivery is shared.

Either outcome **reintroduces the shared-inbox anti-pattern (PROMPT-53 §21 #1)** on the **local_client / outbound** side: one Maildir mixes outbound traffic for multiple Client relationships; account selection and `To`-based isolation become unreliable or require re-parsing that the approved model forbids as a substitute for distinct paths.

The same collapse on `local_referent_email` (inbound RCPT) would dump multiple relationships’ reconstructed local mail into one human-facing Maildir, defeating per-relationship local Referent addresses even if SMTP accepted distinct envelope recipients via alias.

### 3.3 Recommendation (single)

**Provision a distinct physical iRedMail mailbox for every `local_client_email` and every `local_referent_email`.**

| Mechanism | Detail |
|-----------|--------|
| How | Create real `vmail.mailbox` rows (iRedMail admin / `create_mail_user`-class tools), not forwardings/aliases for these two roles |
| Path binding | Resolve each mailbox to its own Maildir via the existing panel resolver pattern (`maildir_resolver.php` / `vmail.mailbox` storage fields); store that path in `clients.local_client_maildir` for the **local client** mailbox only (watch target). Local referent is a separate physical mailbox used as inbound delivery target |
| Aliases | **Do not** use aliases to share one Maildir among multiple Client relationships for either address role |

**Per-relationship cost:**

| Resource | Count per Client relationship |
|----------|-------------------------------|
| `vmail.mailbox` rows | **2** (`local_client` + `local_referent`) |
| Maildir directory trees | **2** |
| `mail_proxy.clients` row | **1** (already in data model) |
| `external_accounts` row | **1** (already required 1:1) |

Example (Ivan + two clients): **4** local mailboxes + **4** Maildirs for the two relationships’ local pairs, plus existing external accounts.

### 3.4 DNS

**No DNS changes** are required for purely local delivery on an already-provisioned local mail domain (e.g. `testvps.loc` / production local zone). New addresses are local MTA/DB objects only; MX/public DNS is unchanged for on-box Postfix → Dovecot delivery.

### 3.5 Out of scope (unchanged)

Panel editor, backfill tool, daemon wiring — not part of this memo’s implementation.

---

## 4. Daemon boundary

```text
mail-proxy-daemon.py: no relationship_lookup references
```

Confirmed by search on this working tree after the fix. No daemon edits in PROMPT-55.

---

## 5. Files changed

| File | Change |
|------|--------|
| `relationship_lookup.py` | Restore `class DatabaseConnectionProvider(Protocol):` |
| `docs/reports/PROMPT-55-lookup-fix-and-provisioning-memo.md` | This report (includes Task 4 memo) |

---

## 6. Integrity

| Check | Result |
|-------|--------|
| Daemon routing changed | **NO** |
| Schema / production DB changed | **NO** |
| Panel / backfill / daemon wiring | **NO** |
| Test output in this report | **Captured from live runs**, not restated expectations |
