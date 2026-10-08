# PROMPT-83 — Operator merge notes

**Branch tip:** `origin/prompt-83-referent-local-optional-activation` (`768ab1f`)  
**Base:** `master` `@ 2e9201a` (already up to date; no rebase needed)  
**Source audit:** A1 merge readiness (2026-10-08)

## Merge order

1. **PROMPT-83** (this branch) into `master`
2. **PROMPT-84** (`prompt-84-external-accounts-list-ui`) — stacked on 83
3. **Mail presets** (`feature/referent-mail-presets` / PR #80) — expect conflicts in `panel-modal.js` and `referent_card_ui.php`

## Migration 006 (required)

Apply on **every** environment (lab + VPS) **before** or **as part of** the panel deploy that enables nullable `local_inbox` / `local_outbox`:

```bash
mysql mail_proxy < migrations/006_referent_local_nullable.sql
```

- Makes `referents.local_inbox` and `referents.local_outbox` nullable only — **does not drop columns**.
- Existing non-NULL rows are unchanged.

## Residual daemon risks (accept or keep locals populated)

Until a PROMPT-83+ follow-up, these paths still assume non-NULL referent locals:

1. `resolved.append(referent_data['local_inbox'])` — KeyError / appends `None` if NULL
2. `notify_to = task.referent_data.get('local_inbox')` (disposal notify) — no fallback to relationship `local_referent_email`
3. `mail_from = referent_data.get('local_inbox') or notify_to` — weak when both NULL
4. `maildir_new = Path(ref['local_outbox']) / 'new'` — fails if `local_outbox` is NULL
5. `# TODO(PROMPT-83+)` — graceful NULL fallback for outbound disposal still deferred

Inbound plan paths already use `.get('local_inbox') or ''`.

## After merge — tests

```bash
php tests/panel_referent_activation_test.php
php tests/panel_toggle_smoke_test.php
php tests/panel_relationship_maildir_resolve_test.php
python tests/test_referent_handler_data.py
# Lab/VPS only (mutates DB):
PANEL_WEB_ROOT=/var/www/mail-proxy php tests/prompt83_lab_smoke.php
```

## Daemon restart

Required only for the small `.get('local_inbox')` changes in `mail-proxy-daemon.py`. Can be done **separately** from migration 006 and panel rsync.
