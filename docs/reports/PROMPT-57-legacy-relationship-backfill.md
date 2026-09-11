# PROMPT-57 — Operator-Assisted Legacy Relationship Backfill

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Starting commit (PROMPT-56 tip, confirmed below):** `0064320fc847dee5d6a0af4478434570ebd89ead`  
**Mode:** Panel (PHP) discovery/triage on top of PROMPT-56 relationship editor  
**Business baseline:** PROMPT-51–56 frozen (no U1–U4 reopen; no daemon/schema; no auto four-address fill)

---

## 0. Process evidence (actual commands)

```text
> git rev-parse HEAD
0064320fc847dee5d6a0af4478434570ebd89ead

> git log -1 --oneline
0064320 PROMPT-56 panel ClientRelationship CRUD editor

> git status
On branch prompt-47-panel-authorization-audit
Your branch is up to date with 'origin/prompt-47-panel-authorization-audit'.

Changes not staged for commit:
	modified:   docs/DELTA_transit_admin_guide.pdf
	modified:   web/includes/relationship_editor.php
	modified:   web/index.php
	modified:   web/lang/en.php
	modified:   web/lang/ru.php

Untracked files:
	... (unrelated docs/.keys/__pycache__/ …)
	tests/panel_legacy_backfill_test.php
	tests/panel_legacy_backfill_test.py
	docs/reports/PROMPT-57-legacy-relationship-backfill.md   (this file, once written)

no changes added to commit
```

**Verdict:** PROMPT-57 panel/test/report changes are **present in the working tree and not committed** at report time. Tip `HEAD` is still the PROMPT-56 commit above. Unrelated dirty paths (`docs/DELTA_transit_admin_guide.pdf`, `.keys/`, other `docs/**`) are **not** part of this deliverable.

---

## 1. Executive summary

Operators get a **cross-referent legacy backlog** (`relationship_backfill`) listing every `clients` row where PROMPT-56’s `relationshipIsLegacyOnly()` is true, with a progress count and a **Migrate** action. Migrate opens the **existing** `relationship_form` / `handleRelationshipSave` path with **GET-only** prefill of `external_client_email` from legacy `email`. No bulk migrate, no naming heuristics, no second save path, no session draft, no daemon/schema changes.

---

## 2. Files touched

| File | Change |
|------|--------|
| `web/includes/relationship_editor.php` | `relationshipExternalClientFormValue()`, `fetchLegacyRelationshipBacklog()`, `renderLegacyRelationshipBackfill()` — reuses `relationshipIsLegacyOnly()` |
| `web/index.php` | Nav + `relationship_backfill` route; form GET prefill / `from=backfill` back-link; optional POST `return_to=backfill` redirect after successful save only |
| `web/lang/ru.php` | `nav.backfill`, `backfill.*` strings |
| `web/lang/en.php` | `nav.backfill`, `backfill.*` strings |
| `tests/panel_legacy_backfill_test.php` | **NEW** — static wiring + predicate before/after (PHP) |
| `tests/panel_legacy_backfill_test.py` | **NEW** — same checks for hosts without `php` on PATH |
| `docs/reports/PROMPT-57-legacy-relationship-backfill.md` | **NEW** — this report |

---

## 3. Confirmations

| Check | Result |
|-------|--------|
| `mail-proxy-daemon.py` unchanged | **YES** — `git diff HEAD -- mail-proxy-daemon.py` empty; still no `relationship_lookup` |
| Schema / migrations unchanged | **YES** |
| `handleRelationshipSave` validation unchanged | **YES** — diff adds only post-success `return_to=backfill` redirect; all-or-nothing, mailbox precondition, UNIQUE checks untouched |
| Completeness logic not re-derived | **YES** — backlog filters with `relationshipIsLegacyOnly($row)`; missing-field UI still uses `relationshipMissingFields()` |
| Auth | `requirePanelAdmin()` (same panel gate as PROMPT-47/56); no new role |
| Prefill is GET-only | **YES** — display value via `relationshipExternalClientFormValue()`; no `$_SESSION` draft; navigating away without POST writes nothing |
| No bulk / automatic migration | **YES** — no `migrate_all` / “Migrate all”; one row per form submit |
| No local-address heuristics | **YES** — temptation noted; **not** encoded (`guessLocal` / `inferLocal` absent) |

### Reused (not reimplemented) functions

- `relationshipIsLegacyOnly()` — `web/includes/relationship_editor.php`
- `relationshipMissingFields()` — same file (PROMPT-56 §9 mirror; still present; backlog does not fork it)
- `handleRelationshipSave()` — `web/index.php` (PROMPT-56 save path)

### `handleRelationshipSave` diff (only navigation)

```diff
+    if ((string)($_POST['return_to'] ?? '') === 'backfill') {
+        redirectTo('relationship_backfill');
+    }
     redirectTo('referent_form', ['id' => $referentId]);
```

`return_to` is a POST redirect hint submitted with an intentional Save — **not** a stored draft of address fields.

---

## 4. Behaviour

### Task 1 — Backlog

- Route: `GET index.php?action=relationship_backfill`
- Loads all `clients` ⨝ `referents`, keeps rows where `relationshipIsLegacyOnly()` is true
- Columns: referent username, legacy `email`, `active`, Migrate
- Count: `{legacy} of {total} relationships still on the legacy model`

### Task 2 — Migrate entry

- Link: `relationship_form&referent_id=&id=&from=backfill`
- Prefills **only** `external_client_email` from legacy `email`
- `local_client_email`, `local_referent_email`, `external_account_id`, `local_client_maildir` start blank / unselected (account dropdown still starts at «Select» even if the referent has a single account — legacy rows have null `external_account_id`)
- Save → unchanged `handleRelationshipSave`

### Task 3 — No bulk

- Explicitly no multi-row write endpoint

### Task 4 — After save leaves backlog

- Once four-address columns are filled, `relationshipIsLegacyOnly()` is false → row excluded by `fetchLegacyRelationshipBacklog()`
- Verified with an actual before/after predicate read in the static test (2 of 3 → 1 of 3), not by assumption alone
- Live panel DB save was not available on this Windows agent (no local MySQL/php panel session); predicate filter is the same function the backlog uses

---

## 5. Rendered HTML samples (operator-visible)

### 5.1 Backlog before (N = 2 of M = 3)

```html
<p class="mb-6 text-sm font-medium" data-testid="backfill-count">
  2 of 3 relationships still on the legacy model
</p>
<div class="bg-white rounded shadow overflow-x-auto" data-testid="backfill-table">
  <table class="min-w-full text-sm">
    <tbody>
      <tr class="border-t" data-legacy-client-id="101">
        <td class="px-4 py-2">ref_alpha<div class="text-xs text-slate-500">referent_id=3</div></td>
        <td class="px-4 py-2 font-mono">legacy.client@partner.com</td>
        <td class="px-4 py-2">Yes</td>
        <td class="px-4 py-2">
          <a class="bg-blue-600 text-white px-3 py-1 rounded text-sm"
             href="index.php?action=relationship_form&referent_id=3&id=101&from=backfill"
             data-testid="backfill-migrate">Migrate</a>
        </td>
      </tr>
      <tr class="border-t" data-legacy-client-id="102">
        <td class="px-4 py-2">ref_beta<div class="text-xs text-slate-500">referent_id=5</div></td>
        <td class="px-4 py-2 font-mono">other@partner.com</td>
        <td class="px-4 py-2">Yes</td>
        <td class="px-4 py-2">
          <a … href="…&id=102&from=backfill" data-testid="backfill-migrate">Migrate</a>
        </td>
      </tr>
    </tbody>
  </table>
</div>
```

### 5.2 Migration form pre-filled (GET only)

```html
<form method="post" action="index.php?action=relationship_save"
      class="bg-white rounded shadow p-6 space-y-4" id="relationship-form"
      data-prefill-source="legacy-email-get">
  <input type="hidden" name="return_to" value="backfill">
  <div data-testid="legacy-migrate-banner">… legacy banner …
    <div class="mt-1 text-xs">Only external_client_email is pre-filled from the legacy email (GET). …</div>
  </div>
  <input type="email" name="external_client_email"
         value="legacy.client@partner.com" data-testid="external-client-prefill">
  <input type="email" name="local_client_email" value="">
  <input type="email" name="local_referent_email" value="">
  <select name="external_account_id">
    <option value="">— Select —</option>
    <!-- accounts listed; none selected for legacy row -->
  </select>
  <input type="text" name="local_client_maildir" value="">
</form>
```

### 5.3 Backlog after saving #101 (N−1 = 1 of 3)

```html
<p class="mb-6 text-sm font-medium" data-testid="backfill-count">
  1 of 3 relationships still on the legacy model
</p>
<!-- only data-legacy-client-id="102" remains -->
```

---

## 6. Static test (executed)

Primary executable on this host (no `php` on PATH; WSL not installed):

```text
> py -3 tests/panel_legacy_backfill_test.py
PASS: relationship_backfill route
PASS: backfill render wired
PASS: nav/link to backfill
PASS: fetchLegacyRelationshipBacklog
PASS: renderLegacyRelationshipBackfill
PASS: backlog reuses relationshipIsLegacyOnly
PASS: relationshipIsLegacyOnly still defined
PASS: relationshipMissingFields still defined
PASS: prefill helper
PASS: form uses prefill helper
PASS: Migrate link uses from=backfill
PASS: form reads from=backfill GET flag
PASS: GET prefill marker on form
PASS: optional return_to after migrate save
PASS: single save handler retained
PASS: no separate migrate save handler
PASS: no session draft state
PASS: no session draft in index
PASS: no migrate_all endpoint
PASS: no Migrate all UI
PASS: no local-address guess helper
PASS: no local-address infer helper
PASS: RU nav.backfill
PASS: EN nav.backfill
PASS: RU backfill.count
PASS: EN backfill.count
PASS: EN progress sentence
PASS: daemon still ignores relationship_lookup
PASS: daemon has no backfill wiring
PASS: PHP relationshipIsLegacyOnly signature present
PASS: legacy-only still uses relationshipHasFourAddressData
PASS: legacy row is legacy-only
PASS: second legacy row is legacy-only
PASS: complete row is not legacy-only
PASS: prefill carries legacy email
PASS: before: 2 of 3 still legacy
PASS: after save: migrated row leaves backlog predicate
PASS: after: 1 of 3 still legacy (N-1)
PASS: after: remaining legacy is the unsaved row
OK: panel legacy backfill checks passed (python mirror)
```

PHP twin (same assertions + `require_once` of the real helper for predicates):

```text
php tests/panel_legacy_backfill_test.php
```

(Not run here — no `php` binary on this Windows agent.)

---

## 7. Out of scope (not done)

- Daemon wiring to `RelationshipLookup`
- Deleting/archiving migrated legacy rows
- Any change to PROMPT-56 form validation rules
- Bulk/automatic four-address generation

---

## 8. Integrity

| Check | Result |
|-------|--------|
| Production daemon routing changed | **NO** |
| Migration / schema altered | **NO** |
| Second save / looser validation path | **NO** |
| New authorization model | **NO** |
| Auto-fill of local addresses / account | **NO** |
