# A1 — Merge readiness: PROMPT-83 / PROMPT-84 / PR #80 (mail presets)

**Audit date:** 2026-10-08  
**Auditor branch:** `audit/a1-merge-readiness-83-84-80`  
**Master HEAD (confirmed):** `2e9201aebc93da6097214e224945bc3f76faa33b` (`2e9201a` — Merge PR #78 dashboard whitelist runner)  
**Method:** Read-only `git fetch`, `git log`/`diff`/`merge-tree --write-tree`; no merges, migrations, or VPS/daemon changes.

---

## Executive summary

| Verdict | Detail |
|--------|--------|
| **Overall** | **Not ready** for a single no-touch deploy of all three lines without operator steps. |
| **Branch 83** | **Conditionally ready** to merge into `master` after planning **migration 006** on every DB (lab + VPS). Panel activation logic matches **2026-10-07 locked decisions**. Daemon still has **documented NULL `local_inbox` gaps**. |
| **Branch 84** | **Ready after 83** (84 is stacked on 83; fast-forward on top of 83). No extra conflicts vs `master` beyond 83. |
| **PR #80 (`feature/referent-mail-presets`)** | **Ready after 83 + 84**, with **manual conflict resolution** in two files (`panel-modal.js`, `referent_card_ui.php`). No GitHub CI checks on the PR. |

**Recommended merge order:** `master` → **`prompt-83-referent-local-optional-activation`** → **`prompt-84-external-accounts-list-ui`** → **`feature/referent-mail-presets` (PR #80)**.

**Why not presets before 84:** `git merge-tree --write-tree` shows **83 + presets merges cleanly** (exit 0), but **presets + 84 (merge-base 83)** still yields the same **content conflicts** in `panel-modal.js` and `referent_card_ui.php`, plus touches `relationship_editor.php` in the merge path. Stacking **83 → 84 → presets** matches dependency order (activation + external tab before preset refactors of account dialogs) and aligns with the **2026-10-07 architecture lock** in `referent_activation.php`.

---

## Per-branch status

### 1. `prompt-83-referent-local-optional-activation`

| Field | Value |
|-------|--------|
| **Tip** | `768ab1f` — Fix relationship save when client Maildir is left empty. |
| **Range vs `master`** | `2e9201a..768ab1f` |
| **Commits ahead** | **3** |
| **Merge-base with `master`** | `2e9201a` |
| **Simulated merge into `master`** | **Clean** (no `changed in both` / no conflict markers in `git merge-tree`). |

**Commits (oldest → newest):**

1. `ebae54f` — PROMPT-83: optional referent local addresses and activation gate  
2. `2891a70` — PROMPT-83 lab verification: VPS-safe tests and nullable local_inbox in daemon  
3. `768ab1f` — Fix relationship save when client Maildir is left empty.

**Key files changed (15 files, +682 / −100):**

| Area | Paths |
|------|--------|
| Activation / 2026-10-07 lock | `web/includes/referent_activation.php` (new) |
| Referent card / create flow | `web/includes/referent_card_ui.php`, `web/index.php` |
| Relationships | `web/includes/relationship_editor.php` |
| Schema | `migrations/006_referent_local_nullable.sql`, `schema.sql` |
| Daemon | `mail-proxy-daemon.py` (`.get('local_inbox')` + TODO) |
| i18n | `web/lang/en.php`, `web/lang/ru.php` |
| Tests / docs | `tests/panel_referent_activation_test.php`, `tests/panel_toggle_smoke_test.php`, `tests/panel_relationship_maildir_resolve_test.php`, `tests/prompt83_lab_smoke.php`, `tests/test_referent_handler_data.py`, `docs/reports/PROMPT-83-referent-local-optional-activation.md` |

**Activation logic (confirmed on branch):** `referent_activation.php` implements: `active=1` only with ≥1 **complete, active** relationship (PROMPT-53 field set + active external account); `referentResolveActiveOnSave` blocks otherwise; `referentSyncActiveAfterRelationshipChange` auto-deactivates when the last activatable relationship is removed.

---

### 2. `prompt-84-external-accounts-list-ui`

| Field | Value |
|-------|--------|
| **Tip** | `c4101f6` — test(panel): fix external accounts list static test |
| **Range vs `master`** | `2e9201a..c4101f6` |
| **Commits ahead of `master`** | **7** (includes all 3 PROMPT-83 commits) |
| **Range vs `prompt-83` tip** | `768ab1f..c4101f6` (**4** commits unique to 84) |
| **Merge-base with `master`** | `2e9201a` |
| **Simulated merge into `master`** | Same as 83 + 84 stack; **no additional conflicts vs 83-only** when merging 84 onto 83 (84 descends from 83). |

**84-only commits:**

1. `637f3b0` — fix(panel): stop false unsaved changes modal on field focus change  
2. `b36e74c` — feat(panel): list all external accounts on referent card tab  
3. `6e65588` — test(panel): fix modal dirty static test assertion  
4. `c4101f6` — test(panel): fix external accounts list static test  

**Additional key files (beyond 83):**

| Area | Paths |
|------|--------|
| External accounts UI | `web/includes/referent_card_ui.php` (major), `web/includes/relationship_editor.php` |
| Modal dirty state | `web/assets/panel-modal.js` |
| Tests / docs | `tests/panel_external_accounts_list_test.php`, `tests/panel_modal_dirty_test.php`, `docs/reports/PROMPT-84-external-accounts-list-ui.md` |

---

### 3. `feature/referent-mail-presets` (PR #80)

| Field | Value |
|-------|--------|
| **Tip** | `9a8e25c` — Harden Referent account form UX against spurious dirty state and port/mode drift |
| **Range vs `master`** | `2e9201a..9a8e25c` |
| **Commits ahead** | **7** (parallel to 83/84; **does not** contain PROMPT-83/84) |
| **PR state** | Open; **no checks reported** on `gh pr checks 80` |

**Key files changed (11 files, +977 / −81):**

| Area | Paths |
|------|--------|
| Presets | `web/includes/mail_provider_presets.php`, `web/assets/referent-mail-presets.js`, `web/includes/panel_mail_account_fields.php` |
| Verify / host normalize | `web/includes/mailbox_verify.php` |
| Card / modal | `web/includes/referent_card_ui.php`, `web/assets/panel-modal.js` (minimal) |
| Tests | `tests/panel_mail_presets_test.php`, `tests/panel_referent_account_form_test.php`, `tests/panel_mailbox_verify_test.php` |
| i18n | `web/lang/en.php`, `web/lang/ru.php` |
| Daemon (IPv4-first TLS) | `mail-proxy-daemon.py` (implicit TLS probe fix in `9a427da`) |

**Note:** PR #80’s number is **not** the same as `docs/reports/PROMPT-80-referent-mailbox-verify-on-save.md` on `master` (naming collision only).

---

## Migration notes

| Question | Answer |
|----------|--------|
| Does **prompt-83** introduce `migrations/006_referent_local_nullable.sql`? | **Yes.** |
| Is **006** on **`master`**? | **No** (`migrations/` on master stops at `005_mail_passage_journal_skipped.sql`). |
| VPS history in this audit? | **Not queried** (read-only; no VPS access). Operator must confirm whether `006` was ever applied manually on VPS. |
| What does **006** do? | `ALTER TABLE referents MODIFY local_inbox / local_outbox VARCHAR(255) NULL DEFAULT NULL` only — **no column drops**. |
| `schema.sql` on branch 83 | `local_inbox` / `local_outbox` **UNIQUE NULL** (nullable for fresh installs). **`master` `schema.sql` still NOT NULL** until 83 merges. |

**Operator rule:** Apply `006` on each environment **before** or **as part of** the 83 deploy window; existing non-NULL rows are unchanged per PROMPT-83 report.

---

## Residual daemon risks (branches 83 / 84)

Daemon changes on **83** (84 does not further edit `mail-proxy-daemon.py`):

- **Fixed:** `referent_data['local_inbox']` → `referent_data.get('local_inbox') or ''` in inbound plan paths (2 sites).
- **Test added:** `test_handler_data_nullable_local_inbox_from_db` in `tests/test_referent_handler_data.py`.

**Remaining non-null / risky access (still on 83/84 tip):**

| Location / pattern | Risk |
|--------------------|------|
| `resolved.append(referent_data['local_inbox'])` | **KeyError or appends `None`** if NULL and code path runs. |
| `notify_to = task.referent_data.get('local_inbox')` (+ similar disposal notify paths) | **No fallback** to relationship `local_referent_email`; disposal notify may skip when NULL. |
| `mail_from = referent_data.get('local_inbox') or notify_to` | Weak when both NULL. |
| `maildir_new = Path(ref['local_outbox']) / 'new'` | **Fails** if `local_outbox` is NULL (referent-level outbound watch). |
| `or referent_data.get('local_inbox')` in journal/plan metadata | Empty string / NULL in logs, not crash — lower severity. |
| `# TODO(PROMPT-83+): graceful fallback when referents.local_inbox is NULL` | Explicit deferred work in outbound disposal. |

**Python TODO/FIXME on daemon (83/84):** only the **PROMPT-83+** comment above; no other new TODOs from these branches.

**Conclusion:** Panel can create NULL referent locals per architecture lock; **daemon notification and legacy referent-level Maildir watch** still assume populated locals in several paths. Acceptable for merge only if operators accept **known follow-up** or keep referent locals populated on VPS until daemon follow-up ships.

---

## Conflict matrix and resolution advice

### Simulated sequence: `master` ← 83 ← 84 ← presets

| Step | Result |
|------|--------|
| `master` + **83** | **No conflicts** |
| + **84** | **Fast-forward / clean** (84 contains 83) |
| + **presets** (`git merge-tree --write-tree` **84** vs **presets**) | **CONFLICT:** `web/assets/panel-modal.js`, `web/includes/referent_card_ui.php`. **`en.php` / `ru.php` auto-merge.** |

### Alternate: `master` ← 83 ← presets ← 84

| Step | Result |
|------|--------|
| `master` + **83** | Clean |
| + **presets** (`83` vs `presets`) | **Clean** (exit 0; langs + card auto-merge) |
| + **84** (`merge-base` **83**, `presets` vs **84**) | **CONFLICT:** same two files **`panel-modal.js`**, **`referent_card_ui.php`**; **`relationship_editor.php`** in merge path (auto-merge, review carefully) |

### Conflict resolution (prefer **2026-10-07 architecture lock**)

| File | Prefer | Integrate from other branch |
|------|--------|-----------------------------|
| `web/assets/panel-modal.js` | **84** — `isDialogFormDirty()` baseline serialization (PROMPT-84 false-dirty fix) | **Presets** — tab hotkey guard while modal dirty, port sync on open (`9a8e25c`); **do not** restore global `dataset.pmDirty`-only close path from presets |
| `web/includes/referent_card_ui.php` | **84** — activation UI, external accounts tab, `fetchExternalAccountsForReferentCard()`, overview readiness | **Presets** — `panel_mail_account_fields.php` / preset JS hooks / provider shortcuts in account dialogs; keep 84’s multi-account table and PROMPT-83 nullable local display |
| `web/lang/en.php`, `web/lang/ru.php` | Merge both string sets; run offline PHP tests after resolve | |
| `web/includes/relationship_editor.php` | **84** if conflict appears | Presets generally did not own this file |

---

## Test readiness

### Commands (from branch docs / test headers)

**PROMPT-83 (offline + optional VPS):**

```bash
php tests/panel_referent_activation_test.php
php tests/panel_toggle_smoke_test.php
php tests/panel_relationship_maildir_resolve_test.php
python tests/test_referent_handler_data.py
# VPS DB smoke (mutates DB):
PANEL_WEB_ROOT=/var/www/mail-proxy php tests/prompt83_lab_smoke.php
```

**PROMPT-84:**

```bash
php tests/panel_modal_dirty_test.php
php tests/panel_external_accounts_list_test.php
```

**PR #80 presets:**

```bash
php tests/panel_mail_presets_test.php
php tests/panel_referent_account_form_test.php
php tests/panel_mailbox_verify_test.php
```

**Related on `master` (unchanged by these branches but adjacent):** `tests/panel_whitelist_runner_test.php`, `tests/panel_fpm_emulation_test.php`, Python routing/journal tests under `tests/test_*.py`.

### VPS-safe claims and CI

| Branch | Claim / evidence |
|--------|------------------|
| **83** | Commit `2891a70` explicitly labels **VPS-safe tests**; `prompt83_lab_smoke.php` is **DB-mutating** (lab/VPS only). Static tests run offline with `PANEL_WEB_ROOT` or repo `web/`. |
| **84** | PROMPT-84 report documents lab deploy + static tests; no CI. |
| **Presets** | Offline static tests; mailbox verify tests include proc_open sections per commit messages; **no GitHub Actions checks** on PR #80. |

**This audit did not re-run tests** (read-only scope).

---

## Recommended next actions (operator)

1. **Confirm VPS DB:** whether `referents.local_inbox` / `local_outbox` are already nullable; if not, schedule `mysql mail_proxy < migrations/006_referent_local_nullable.sql` before enabling 83 panel behavior in production.
2. **Merge PRs in order:** 83 → 84 → rebase or merge **PR #80** onto updated `master`; resolve **`panel-modal.js`** and **`referent_card_ui.php`** per table above.
3. **After merge:** run the PHP/Python test commands listed for all three areas; on lab/VPS run `prompt83_lab_smoke.php` once.
4. **Deploy split:** panel/rsync per PROMPT-84 report; **daemon** only needed for 83’s small `.get()` change and PR #80’s TLS probe fix — plan daemon restart separately from migration.
5. **Track daemon follow-up:** implement PROMPT-83+ NULL fallbacks (relationship-centric notify + `local_outbox` watch) before relying on NULL referent locals in production routing.
6. **Optional:** add CI workflow to run panel static tests on PRs (currently none for #80).

---

## Audit metadata

- **Origin fetched:** 2026-10-08  
- **Branches inspected:** `origin/prompt-83-referent-local-optional-activation`, `origin/prompt-84-external-accounts-list-ui`, `origin/feature/referent-mail-presets`  
- **Tools:** `git log`, `git diff --stat`, `git merge-tree` (including `--write-tree --messages`)
