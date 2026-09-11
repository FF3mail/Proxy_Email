# PROMPT-56 — Panel Relationship Editor (ClientRelationship CRUD)

**Date:** 2026-09-10  
**Branch:** `prompt-47-panel-authorization-audit`  
**Starting commit:** `d7985d90b0d243ea9c755952e3898905925acbe7`  
**Mode:** Panel (PHP) implementation — additive schema already from PROMPT-54/55  
**Business baseline:** PROMPT-51–55 frozen (no U1–U4 reopen; 2 physical mailboxes per relationship)

---

## 1. Executive summary

The panel now manages **many ClientRelationship rows per referent** using the PROMPT-54 columns. Operators can list, add, edit, toggle, and delete relationships. Saves enforce all-or-nothing four-address + account + maildir, iRedMail physical-mailbox preconditions (read-only `vmail` via existing `vmail-lookup.conf`), application-layer UNIQUE messages, and the same `requirePanelAdmin()` / CSRF gates as other screens.

`mail-proxy-daemon.py` and `migrations/002_client_relationship_columns.sql` are **unchanged**.

---

## 2. Files touched

| File | Change |
|------|--------|
| `web/includes/relationship_editor.php` | **NEW** — validity (§9 mirror), mailbox check, list render, UNIQUE helpers |
| `web/index.php` | Routes, CSRF list, referent form/view list, relationship form/save/delete, toggle return to referent |
| `web/lang/ru.php` | Relationship i18n |
| `web/lang/en.php` | Relationship i18n |
| `tests/panel_relationship_routing_test.php` | **NEW** — static wiring checks |
| `docs/reports/PROMPT-56-panel-relationship-editor.md` | **NEW** — this report |

---

## 3. New / changed routes and POST actions

| Action | Method | Auth | CSRF | Purpose |
|--------|--------|------|------|---------|
| `relationship_form` | GET | `requirePanelAdmin()` | — | Add/edit form |
| `relationship_save` | POST | `requirePanelAdmin()` | **yes** | Create/update relationship |
| `relationship_delete` | POST | `requirePanelAdmin()` | **yes** | Delete relationship |
| `referent_form` (edit) | GET | same | — | Client block replaced by relationship list |
| `referent_view` | GET | same | — | Relationship list appended; routing blurb updated |
| `referent_save` | POST | same | yes | **Unchanged** legacy client upsert when `client_email` posted (create form still has fields; edit form omits them so existing clients are left alone) |
| `toggle_active` (entity=`client`) | POST | same | yes | May return to `referent_form` / `referent_view` with `referent_id` |

No new roles or ownership model.

---

## 4. Behaviour summary

### List (Task 1)

- Per-referent table: external client, local client, local referent, linked `external_accounts.email`, active, routing status.
- Status from `relationshipMissingFields()` / `relationshipStatusLabel()` aligned with `_VALID_RELATIONSHIP_WHERE` field checks.
- Legacy-only rows (`email` set, four-address columns empty) show **legacy (email only)** and still display the legacy email.

### Form (Task 2)

- Fields: `external_client_email`, `local_client_email`, `local_referent_email`, `external_account_id`, `local_client_maildir`, `active`.
- None auto-derived from another address.
- Account dropdown: this referent’s accounts; already linked to another relationship appear **disabled** with relationship label (not hidden).
- All-or-nothing enforced server-side.

### Mailbox check (Task 3)

- `activePhysicalMailboxExists()` uses `getVmailLookupPdo()` + `mailbox`/`domain` query (same credential path as `maildir_resolver.php`).
- Missing mailbox → flash: `Mailbox not provisioned in iRedMail — create it first, then retry ({email})`.
- Panel does **not** provision mailboxes.

### UNIQUE / FK (Task 4)

- App-layer collision messages name field, value, conflicting relationship id / referent_id.
- PDO 1062 / FK failures mapped to operator messages (no raw PDOException to UI).

---

## 5. Confirmations

| Check | Result |
|-------|--------|
| `mail-proxy-daemon.py` unchanged | **YES** (no `relationship_lookup` reference; not modified in this prompt) |
| `migrations/002_…sql` not re-run/altered | **YES** |
| Schema DDL not changed here | **YES** |
| Authorization = existing panel admin + CSRF | **YES** |

### Legacy before / after (code-path check, not live UI)

**Before (create referent):** form posts `client_email` / `client_active` → `handleReferentSave` INSERT/UPDATE `clients.email` + `active` only.

**After:**
- **Create referent:** same legacy fields still on the form; same INSERT/UPDATE strings remain in `handleReferentSave` (`Client created for referent` / `Client updated for referent`).
- **Edit referent:** relationship list replaces the embedded client block; `client_email` is **not** posted → save leaves `clients` rows untouched (empty-email branch unchanged: «Если email пустой — ничего не делать»).
- Legacy rows without four-address data render in the list with status `legacy (email only)` and the legacy `email` value.

---

## 6. Literal rendered HTML samples

These are the operator-visible fragments produced by the new helpers/templates (Tailwind classes as in panel).

### 6.1 Empty state (0 relationships)

```html
<div class="border border-dashed border-slate-300 rounded p-6 text-center text-slate-600"
     data-testid="relationship-empty">
    Связей пока нет. Добавьте первую связь с клиентом.
</div>
```

### 6.2 One complete relationship

```html
<tr class="border-t" data-relationship-id="12" data-status="complete">
  <td class="px-3 py-2 font-mono">client1@partner.com</td>
  <td class="px-3 py-2 font-mono">client1@local.loc</td>
  <td class="px-3 py-2 font-mono">ref1@local.loc</td>
  <td class="px-3 py-2 font-mono">ref1@hmail.de</td>
  <td class="px-3 py-2">Да</td>
  <td class="px-3 py-2 text-green-700" data-testid="relationship-status">complete</td>
  <!-- actions: edit / toggle / delete -->
</tr>
```

### 6.3 One incomplete relationship

```html
<tr class="border-t" data-relationship-id="13" data-status="incomplete">
  <td class="px-3 py-2 font-mono">customer-007@partner.com</td>
  <td class="px-3 py-2 font-mono">—</td>
  <td class="px-3 py-2 font-mono">—</td>
  <td class="px-3 py-2 font-mono">—</td>
  <td class="px-3 py-2">Да</td>
  <td class="px-3 py-2 text-amber-700" data-testid="relationship-status">
    incomplete — missing local_client_email, local_referent_email, external_account_id, local_client_maildir
  </td>
</tr>
```

### 6.4 Task 3 blocked-save flash

```html
<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
  Mailbox not provisioned in iRedMail — create it first, then retry (c007@local.loc)
</div>
```

### 6.5 Task 4 UNIQUE violation flash

```html
<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
  Значение external_client_email=client1@partner.com уже используется связью #12 (referent_id=3)
</div>
```

---

## 7. Static test

```text
php tests/panel_relationship_routing_test.php
```

Checks routes, CSRF registration, helper presence, legacy save strings, daemon isolation. (Local Windows agent has no `php` on PATH; run on VPS/staging PHP host.)

---

## 8. Out of scope (not done)

- Backfill of legacy rows into four-address model  
- Daemon wiring to `RelationshipLookup`  
- Provisioning iRedMail mailboxes from the panel  
- Changes to `panel_admins` roles  

---

## 9. Integrity

| Check | Result |
|-------|--------|
| Production daemon routing changed | **NO** |
| Migration file altered | **NO** |
| New authorization model | **NO** |
