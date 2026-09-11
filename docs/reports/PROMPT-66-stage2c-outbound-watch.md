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

**Status (PROMPT-67):** EXECUTED on lab VPS `192.168.125.116` via `tests/prompt67_vps_verify.sh`. Full log excerpts in `docs/reports/PROMPT-67-outbound-watch-vps-evidence.md`.

**2a — dual mode (captured 2026-09-11 06:40:08 UTC):**

```text
2026-09-11 06:40:08 [INFO] (Thread-1) Watchdog: new email file for relationship 1 (referent 1): PROMPT67-1789108797-DUAL.eml
2026-09-11 06:40:08 [INFO] (Thread-1) [OUTBOUND_WATCH_DUAL] relationship_watch path=/var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir/new file=PROMPT67-1789108797-DUAL.eml not_visible_via_referent_outbox
2026-09-11 06:40:08 [INFO] (Thread-1) [OUTBOUND_RELATIONSHIP_SHADOW] referent_id=1 from=clientloc1@testvps.loc legacy_account_id=1 lookup_account_id=1 relationship_id=1 marker=AGREE
2026-09-11 06:40:08 [INFO] (SmtpWorker-0) Email PROMPT67-1789108797-DUAL.eml sent via external SMTP (238 bytes)
```

**2b — relationship_only + shadow fail-closed (2026-09-11 06:43:06 UTC):**

```text
2026-09-11 06:43:06 [ERROR] (MainThread) OUTBOUND_WATCH_MODE=relationship_only requires OUTBOUND_ROUTING_MODE=relationship_live; got OUTBOUND_ROUTING_MODE=shadow — failing closed to referent_only
2026-09-11 06:43:06 [INFO] (MainThread) INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=referent_only (requested=relationship_only) RELATIONSHIP_LOOKUP_SHADOW=on
2026-09-11 06:43:06 [INFO] (MainThread) ProxyDaemon operational: ... OUTBOUND_WATCH_MODE=referent_only, 1 referent watches, 0 relationship maildir paths
```

**2c — rollback referent_only (2026-09-11 07:01:07–07:01:12 UTC):**

```text
2026-09-11 07:01:07 [INFO] (MainThread) ProxyDaemon operational: ... OUTBOUND_WATCH_MODE=referent_only, 1 referent watches, 0 relationship maildir paths
2026-09-11 07:01:11 [INFO] (Thread-1) Watchdog: new email file for referent 1: PROMPT67-ROLLBACK-TEST.eml
2026-09-11 07:01:12 [INFO] (SmtpWorker-0) Email PROMPT67-ROLLBACK-TEST.eml sent via external SMTP (138 bytes)
```

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
PROMPT-66: ACCEPTED (code + unit tests + anchor doc + VPS live evidence via PROMPT-67)
```

VPS evidence closure: PROMPT-67 §2 (2026-09-11).
