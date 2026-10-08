# PROMPT-83 — Referent local addresses optional + activation on complete relationships

**Date:** 2026-10-07  
**Branch:** `prompt-83-referent-local-optional-activation`

## Locked decisions (2026-10-07)

1. Relationship-centric target: `local_referent_email` and `local_client_email` on `clients`. `referents.local_inbox` / `local_outbox` optional (nullable); not required for create or routing.
2. `referents.active = 1` only when ≥1 complete active relationship (four addresses, `external_account_id`, resolved maildir, active relationship + account).
3. Panel blocks `active=1` without that condition; removing the last complete relationship auto-sets `referents.active = 0`.
4. Full external-accounts list UI deferred; no external-tab changes in this prompt.
5. Keep create → redirect to card; remove referent-level local address fields from the create form.

## Files changed

| Area | Files |
|------|--------|
| Schema | `migrations/006_referent_local_nullable.sql`, `schema.sql` |
| Activation | `web/includes/referent_activation.php` |
| Panel | `web/index.php`, `web/includes/referent_card_ui.php`, `web/lang/ru.php`, `web/lang/en.php` |
| Daemon | `mail-proxy-daemon.py` (TODO comment only) |
| Tests | `tests/panel_referent_activation_test.php`, `tests/panel_toggle_smoke_test.php` |

## Follow-ups (out of scope here)

- Daemon notification / journal fallback when `referents.local_inbox` is NULL.
- External tab: list all accounts + “add another”.
- Admin guide §5.3 update (manual `local_outbox`).
- UNIQUE-on-NULL policy review for multiple empty referents.
- PROMPT-79.4 schema cleanup (mode columns, etc.).

## Apply migration

```bash
mysql mail_proxy < migrations/006_referent_local_nullable.sql
```

Existing non-NULL referent rows are unchanged.
