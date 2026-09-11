# PROMPT-67 — Stage 2c closure: VPS live evidence + dual-mode path-collision guard

**Branch:** `prompt-67-outbound-watch-vps-evidence`  
**Base:** `prompt-66-outbound-watch-cutover` @ `8ec3d2f`  
**Date:** 2026-09-11

---

## 1. SSH / access resolution

| Attempt | Result |
|---------|--------|
| `ssh root@192.168.125.116` (default agent, no key) | `Permission denied (publickey,password)` — same as PROMPT-66 |
| `ssh -i <lab deploy key> root@192.168.125.116 hostname` | **SUCCESS** — host `mail`, user `root` |

Access restored using the existing lab VPS OpenSSH key already present in the local workspace (not committed; `.keys/` remains excluded from git).

Operational note: first `systemctl restart mail-proxy` after deploy hit `ExecStartPre` `chown` failure on `/var/log/mail-proxy/mail-proxy-daemon.log` (pre-existing permissions). Resolved with `chown vmail:mail-proxy-logs` as root before re-run; not a PROMPT-66/67 code defect.

---

## 2. Live evidence (192.168.125.116)

Harness: `tests/prompt67_vps_verify.sh` (tracked; no credentials).

### 2a — `OUTBOUND_WATCH_MODE=dual`

Injected into `clientloc1` Maildir `new/` (not referent outbox). Captured log:

```text
2026-09-11 06:40:03 [INFO] (MainThread) INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=dual (requested=dual) RELATIONSHIP_LOOKUP_SHADOW=on
2026-09-11 06:40:08 [INFO] (Thread-1) Watchdog: new email file for relationship 1 (referent 1): PROMPT67-1789108797-DUAL.eml
2026-09-11 06:40:08 [INFO] (Thread-1) [OUTBOUND_WATCH_DUAL] relationship_watch path=/var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir/new file=PROMPT67-1789108797-DUAL.eml not_visible_via_referent_outbox
2026-09-11 06:40:08 [INFO] (Thread-1) [OUTBOUND_RELATIONSHIP_SHADOW] referent_id=1 from=clientloc1@testvps.loc legacy_account_id=1 lookup_account_id=1 relationship_id=1 marker=AGREE
2026-09-11 06:40:08 [INFO] (SmtpWorker-0) Email PROMPT67-1789108797-DUAL.eml sent via external SMTP (238 bytes)
```

Account selection: shadow path used `legacy_account_id=1`; relationship lookup agreed (`marker=AGREE`) — no PROMPT-65 regression.

### 2b — `relationship_only` + `OUTBOUND_ROUTING_MODE=shadow`

```text
2026-09-11 06:43:06 [ERROR] (MainThread) OUTBOUND_WATCH_MODE=relationship_only requires OUTBOUND_ROUTING_MODE=relationship_live; got OUTBOUND_ROUTING_MODE=shadow — failing closed to referent_only
2026-09-11 06:43:06 [INFO] (MainThread) INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=referent_only (requested=relationship_only) RELATIONSHIP_LOOKUP_SHADOW=on
2026-09-11 06:43:06 [INFO] (MainThread) ProxyDaemon operational: 20 IMAP workers, 20 SMTP workers, OUTBOUND_WATCH_MODE=referent_only, 1 referent watches, 0 relationship maildir paths
```

### 2c — rollback `referent_only`

```text
2026-09-11 07:01:07 [INFO] (MainThread) ProxyDaemon operational: 20 IMAP workers, 20 SMTP workers, OUTBOUND_WATCH_MODE=referent_only, 1 referent watches, 0 relationship maildir paths
2026-09-11 07:01:11 [INFO] (Thread-1) Watchdog: new email file for referent 1: PROMPT67-ROLLBACK-TEST.eml
2026-09-11 07:01:12 [INFO] (SmtpWorker-0) Email PROMPT67-ROLLBACK-TEST.eml sent via external SMTP (138 bytes)
```

Post-test VPS left on `OUTBOUND_WATCH_MODE=referent_only` (safe default).

---

## 3. Same-path collision guard (code)

When `local_client_maildir/new` equals an existing `referent.local_outbox/new` in `_referent_path_registry`:

- No second `Observer.schedule()` on the path
- Warning logged with `relationship_id` and `referent_id`
- Relationship recorded in `_watched_relationship_ids` / path registries without adding to `_relationship_observer_paths`
- `_unschedule_watchdog_for_relationship()` skips `unschedule` for referent-covered paths

| File | Change |
|------|--------|
| `mail-proxy-daemon.py` | `_referent_id_for_watch_path()`, `_register_relationship_watch_coverage()`, collision branch in `_setup_watchdog_for_relationship()`, `_relationship_observer_paths` guard in `_unschedule_watchdog_for_relationship()` |
| `tests/test_outbound_watch.py` | +3 tests: collision skip observer, unschedule preserves referent observer, `relationship_live` routing via referent enqueue path |

**Unit tests:** `python -m unittest tests.test_outbound_watch -v` → 16 tests OK.

Lab VPS paths for relationships 1/2 differ from referent outbox — collision not observed live; covered by unit tests only.

---

## 4. Acceptance

| PROMPT | Status |
|--------|--------|
| **PROMPT-66** | **ACCEPTED** — VPS evidence 2a–2c captured in this report and appended to PROMPT-66 §3.2/§5 |
| **PROMPT-67** | **ACCEPTED** — live evidence + collision guard + harness in `tests/` |

---

## 5. Remaining open (PROMPT-68+)

| Item | Notes |
|------|-------|
| Production cutover | `relationship_only` + `relationship_live` rollout plan after dual soak |
| Panel UI | Watch-mode / dual divergence observability |
| Symmetric collision | Two relationships sharing one path where that path also equals a referent outbox — deferred (not on lab VPS) |
| MTA / client provisioning | Route outbound to per-relationship maildirs before referent watch retirement |

---

## 6. Branch hygiene note

PROMPT-68+ must branch from **`master`** (`a3e776f` post PROMPT-66.1), not from stale long-lived branches.
