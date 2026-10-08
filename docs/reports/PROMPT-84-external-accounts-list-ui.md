# PROMPT-84 — External accounts tab (list + add another) + modal dirty fix

**Branch:** `prompt-84-external-accounts-list-ui`  
**Date:** 2026-10-07

## 1. False “unsaved changes” modal (precondition)

### Root cause

`web/assets/panel-modal.js` marked dialogs dirty on bubbling `input` and `change` events on the `<dialog>` element. Any benign focus move that triggered `change` (e.g. email normalization on blur, toggling related controls, or spurious events while tabbing) set a **global** `PanelModal.dirty` flag. The next `close()` (including backdrop/Esc handling) showed `window.confirm` even though the operator had not edited the form.

### Fix

- Removed `input`/`change` listeners that flipped dirty on every event.
- On `open()`, capture a **serialized baseline** of all named form controls (`_serializeForm`).
- On `close()`, compare current serialization to baseline (`isDialogFormDirty`) and confirm only when values actually differ.
- Baseline is captured after `showModal()` + `requestAnimationFrame` so initial focus/layout does not race the snapshot.

**Test:** `php tests/panel_modal_dirty_test.php`

### Manual verification (modals)

1. Open each modal: referent edit, account create/edit, relationship create/edit.
2. Tab/click across fields without changing values → **no** confirm.
3. Change a field, then Esc / Cancel / backdrop → **yes** confirm.
4. Submit or force-close (`data-pm-nodirty` delete dialogs) unchanged.

## 2. External accounts tab

### Before

- Referent card SQL `LEFT JOIN external_accounts … LIMIT 1` — at most one account in UI.
- Tab showed a single account card or empty state.

### After

- `fetchExternalAccountsForReferentCard()` loads **all** `external_accounts` for the referent (with optional `oauth_tokens.expires_at`).
- Tab **«Внешние аккаунты»**: table of accounts (email/login, auth, IMAP/SMTP summary, active, OAuth expiry).
- **«+ Добавить ещё»** always visible in the tab header; empty state still offers create.
- Row **Изменить** / **Удалить**; dblclick/Enter opens edit (same as relationships table).
- Single **dlg-account-edit** / **dlg-account-delete** filled via `ReferentCardAccounts` + `data-account` JSON (by account id).
- Overview readiness: ✓ when `count(accounts) >= 1`; link **Открыть** → `tab=external` when accounts exist.
- Relationship dropdown unchanged (`fetchExternalAccountsForRelationshipForm`).
- Saves/deletes still use `panelReturnFields(..., 'external')` → return to `referent_view&tab=external`.

**Test:** `php tests/panel_external_accounts_list_test.php`

### Manual verification (external tab)

1. Referent with 0 accounts → empty state + create CTA + «Добавить ещё» in header.
2. Create two accounts → both rows listed; edit second account → correct email in modal.
3. Delete one → remaining row correct; overview shows ≥1 account checkmark.
4. Relationship modal → external account dropdown lists all accounts.

## 3. Deploy (lab)

```bash
cd /root/Proxy_Email
git fetch origin prompt-84-external-accounts-list-ui
git reset --hard FETCH_HEAD
cp -a /var/www/mail-proxy/config.php /tmp/mail-proxy-config.php.bak
rsync -a --delete --exclude config.php web/ /var/www/mail-proxy/
cp -a /tmp/mail-proxy-config.php.bak /var/www/mail-proxy/config.php
chown -R www-data:www-data /var/www/mail-proxy
php tests/panel_modal_dirty_test.php
php tests/panel_external_accounts_list_test.php
```
