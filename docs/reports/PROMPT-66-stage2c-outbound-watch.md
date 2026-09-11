# PROMPT-66 — Stage 2c: per-relationship outbound Maildir watch

**Branch:** `prompt-66-outbound-watch-cutover`  
**Base:** `prompt-47-panel-authorization-audit` @ `8c7c84e` (PROMPT-65 HEAD)  
**Date:** 2026-09-11

---

## 1. Design note (Task 1)

### Must referent-level and relationship-level watches run concurrently?

**Yes — but only in `OUTBOUND_WATCH_MODE=dual`, and only for the observation/cutover window.**

| Mode | Referent `local_outbox/new` | Relationship `local_client_maildir/new` | Duration |
|------|----------------------------|----------------------------------------|----------|
| `referent_only` (default) | **Yes** | **No** | Indefinite production default until PROMPT-67+ cutover |
| `dual` | **Yes** | **Yes** (deduped by path) | **Transitional** — run until dual logs show stable parity; then PROMPT-67 promotes `relationship_only` + `relationship_live` |
| `relationship_only` | **No** | **Yes** | Target end state (requires `OUTBOUND_ROUTING_MODE=relationship_live`) |

**Rationale:** Stage 2b (`OUTBOUND_ROUTING_MODE=shadow` / `legacy`) still depends on files landing in `referent.local_outbox/new`. Removing the referent watch before relationship paths are proven would stop all outbound pickup. `dual` keeps legacy pickup intact while exercising `list_watch_targets()` paths.

### File in `local_client_maildir/new` when `OUTBOUND_ROUTING_MODE=shadow`

| `OUTBOUND_WATCH_MODE` | Is `local_client_maildir/new` watched? | Pickup? | Account selection |
|-----------------------|----------------------------------------|---------|-------------------|
| `referent_only` | **No** | No (unless file also in referent outbox) | N/A |
| `dual` | **Yes** | **Yes** — enqueued via relationship handler | Unchanged: shadow → legacy `referent_id LIMIT 1` + `[OUTBOUND_RELATIONSHIP_SHADOW]` |
| `relationship_only` | Yes (if routing is `relationship_live`) | Yes | `relationship_live` account |

In **`dual` + `shadow`**, a relationship-maildir file is picked up and processed; `[OUTBOUND_WATCH_DUAL]` logs that the path was not visible via referent outbox. SMTP account choice remains legacy (PROMPT-65 behaviour).

### Fail-closed rule

`OUTBOUND_WATCH_MODE=relationship_only` with `OUTBOUND_ROUTING_MODE` ∈ {`shadow`, `legacy`} → startup **error** + effective mode **`referent_only`** (referent watch restored).

---

## 2. Diff summary

| Area | Change |
|------|--------|
| `relationship_routing.py` | `OutboundWatchMode`, `parse_outbound_watch_mode()`, `resolve_effective_outbound_watch_mode()` |
| `mail-proxy-daemon.py` | Relationship watch registries (`_watched_relationship_ids`, `_relationship_id_to_watch_path`, `_watch_path_relationship_ids`); `_setup_watchdog_for_relationship()`, `_sync_relationship_watches()`, `_validate_relationship_maildir_for_watch()` (no auto-create); `MaildirHandler` extended with `watch_source` / `relationship_id`; `[OUTBOUND_WATCH_DUAL]` logging; `OUTBOUND_WATCH_MODE` resolved at startup |
| `relationship_lookup.py` | Read-only — `list_watch_targets()` wired from daemon |
| `tests/test_outbound_watch.py` | Mode parsing, fail-closed, path dedup, unschedule refcount, dual log |
| `docs/DELTA-transit_anchor.md` | §3.2 watch modes; §12 narrowed open item |
| `.keys/prompt66_vps_deploy_test.sh` | VPS verification harness |

**Not changed:** worker pools, `OUTBOUND_ROUTING_MODE` account-selection logic, `_validate_account_settings()`, www-data filesystem access.

---

## 3. Verification log

### 3.1 Automated unit tests (local)

```text
$ python -m unittest tests.test_outbound_watch tests.test_relationship_routing -v
...
Ran 38 tests in 0.4s
OK
```

Coverage highlights:

- Default `referent_only`; `dual` / `relationship_only` parsing
- `relationship_only` + `shadow` → fail-closed to `referent_only`
- Two relationships sharing one `local_client_maildir` → single inotify watch, two IDs in registry
- Unschedule retains shared path while another relationship uses it
- `[OUTBOUND_WATCH_DUAL]` fires for relationship-only path

### 3.2 VPS live tests (192.168.125.116)

**Status:** SSH from the development workspace failed (`Permission denied (publickey,password)`). Harness ready at `.keys/prompt66_vps_deploy_test.sh`.

**Run on VPS (as root, after `git pull` / copy to `/root/Proxy_Email`):**

```bash
chmod +x /root/Proxy_Email/.keys/prompt66_vps_deploy_test.sh
/root/Proxy_Email/.keys/prompt66_vps_deploy_test.sh
```

**Expected evidence (per test section):**

| # | Test | Commands / checks | Expected |
|---|------|-------------------|----------|
| 1 | Dual pickup | `OUTBOUND_WATCH_MODE=dual`, inject into `clientloc1` Maildir `new/` | Log: `watchdog_relationship`, `[OUTBOUND_WATCH_DUAL] ... not_visible_via_referent_outbox`, SMTP follows shadow (legacy account) |
| 2 | Negative | `relationship_only` + `shadow` | Log: `failing closed to referent_only`; effective `OUTBOUND_WATCH_MODE=referent_only` |
| 3 | Rollback | `referent_only` + inject into refloc1 outbox | Pre-PROMPT-66 referent-only pickup; no relationship watches in startup line |

**Rollback (single drop-in):**

```ini
# /etc/systemd/system/mail-proxy.service.d/inbound-routing.conf
Environment=OUTBOUND_WATCH_MODE=referent_only
```

```bash
systemctl daemon-reload && systemctl restart mail-proxy
```

---

## 4. Remaining open (PROMPT-67+)

| Item | Notes |
|------|-------|
| Production cutover | `OUTBOUND_WATCH_MODE=relationship_only` + `OUTBOUND_ROUTING_MODE=relationship_live` on lab/staging after dual soak |
| Referent watch removal | Disable `referent.local_outbox` watch once all clients write to per-relationship maildirs |
| Panel UI | Watch-mode / dual divergence observability in monitor |
| MTA / client provisioning | Ensure local clients deliver to `local_client_maildir` (not shared referent outbox) before cutover |

---

## 5. Acceptance

```text
PROMPT-66: IMPLEMENTED (code + unit tests + anchor doc)
VPS live evidence: PENDING — run prompt66_vps_deploy_test.sh on 192.168.125.116
```
