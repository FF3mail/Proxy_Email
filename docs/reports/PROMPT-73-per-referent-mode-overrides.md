# PROMPT-73 — Per-referent routing/watch mode overrides (Option A)

**Branch:** `prompt-73-per-referent-mode-overrides` (from `origin/master` @ `0673f25`)  
**Type:** Implementation (Option A) — schema, daemon, panel, tests.  
**Date:** 2026-09-11

---

## 1. Migration + schema + resolution helper

**Migration:** `migrations/003_referent_mode_overrides.sql`  
**Schema sync:** `schema.sql` `referents` table — three nullable ENUM columns:

| Column | Type |
|--------|------|
| `inbound_routing_mode` | `ENUM('legacy','shadow','relationship_live') NULL` |
| `outbound_routing_mode` | `ENUM('legacy','shadow','relationship_live') NULL` |
| `outbound_watch_mode` | `ENUM('referent_only','dual','relationship_only') NULL` |

**Resolution helper signature** (`relationship_routing.py`):

```python
def resolve_referent_effective_modes(
    *,
    referent_id: int,
    inbound_override: Optional[InboundRoutingMode],
    outbound_override: Optional[OutboundRoutingMode],
    watch_override: Optional[OutboundWatchMode],
    global_inbound: InboundRoutingMode,
    global_outbound: OutboundRoutingMode,
    global_watch: OutboundWatchMode,
    log: Optional[logging.Logger] = None,
) -> ReferentEffectiveModes
```

`ReferentEffectiveModes` is a frozen dataclass with `referent_id`, `inbound_routing`, `outbound_routing`, `outbound_watch`.

---

## 2. Wired call sites (final grep pass)

Final `grep` on `mail-proxy-daemon.py` for bare `INBOUND_ROUTING_MODE`, `OUTBOUND_ROUTING_MODE`, `OUTBOUND_WATCH_MODE`:

| Site | Line(s) | Change |
|------|---------|--------|
| Module-level globals / `main()` startup log | ~106–112, ~2400–2413 | **Unchanged** — process defaults + startup banner |
| `_deliver_to_local_smtp` primary `plan_inbound_delivery` | ~776 | **Wired** → `referent_data['effective_inbound_routing_mode']` |
| `_deliver_to_local_smtp` exception-path fallback | ~839 | **Wired** → same effective inbound mode |
| `_enqueue_outbound_file` `plan_outbound_delivery` | ~1789 | **Wired** → `effective_outbound_routing_mode` |
| `_enqueue_outbound_file` relationship_live log gate | ~1834 | **Wired** → local `outbound_mode` variable |
| `_log_outbound_watch_dual` | ~1707 | **Wired** → per-referent cache; signature now includes `referent_id` |
| `_watch_referent_level_enabled` / `_watch_relationship_level_enabled` | removed | **Replaced** by per-referent cache checks in inverted loops |
| `start()` watch registration | ~1464–1479 | **Replaced** by `_register_watches_for_referent` per referent |
| `_sync_database_state` global gates | ~2000, ~2038 | **Replaced** — membership sync only; no live override refresh |

**No additional bare global reads** remain in per-message or per-referent watch paths.

---

## 3. Watch-loop inversion + heterogeneous registries

**Before:** global `OUTBOUND_WATCH_MODE` chose one loop shape for all referents.

**After:** for each active referent at startup (and for newly-active referents in sync):

1. `_ensure_referent_modes_cached(ref)` — resolve once, store in `_referent_effective_modes[referent_id]`
2. `_register_watches_for_referent(ref)`:
   - `referent_only` or `dual` → `_setup_watchdog_for_referent` + backlog scan
   - `dual` or `relationship_only` → `list_watch_targets_for_referent(ref_id)` + relationship watches

**`relationship_lookup.list_watch_targets(referent_id=None)`** — optional filter; `list_watch_targets_for_referent()` convenience wrapper.

**`_sync_relationship_watches()`** — only considers referents whose **cached** effective watch mode includes relationship tier.

**Registries (heterogeneous by design):**

- `_watched_referent_ids` / `_referent_path_registry` — subset of referents in `referent_only` or `dual`
- `_watched_relationship_ids` / path maps — subset whose referents are in `dual` or `relationship_only`

**Restart-only:** `_ensure_referent_modes_cached` returns existing cache without re-reading DB override columns. Test: `test_override_change_does_not_alter_cached_modes`.

**Startup observability:** `[REFERENT_EFFECTIVE_MODES] referent_id=… inbound=… outbound=… watch=…` log line per referent at first cache.

---

## 4. Panel diff

| File | Change |
|------|--------|
| `web/includes/referent_modes.php` | **New** — parse/validate overrides, compute effective, parse daemon log lines |
| `web/index.php` | Referent edit form: three override dropdowns + inherit-global option; `referent_save` persists columns |
| `web/includes/relationship_status.php` | Per-referent DB overrides, computed effective, daemon startup effective, `pending_restart` flag |
| `web/relationship-status.php` | Display override vs computed vs last-startup effective under each referent |
| `web/lang/en.php`, `ru.php` | i18n for mode fields and observability labels |

No daemon restart button (per constraint).

---

## 5. Unit test results (item 8)

| Case | Test | Result |
|------|------|--------|
| NULL inherits global | `test_null_override_inherits_global` | **PASS** |
| Non-NULL override wins | `test_non_null_override_wins` | **PASS** |
| `referent_only` → no relationship watches | `test_referent_only_effective_skips_relationship_watches` | **PASS** |
| `relationship_only` → no referent watch | `test_relationship_only_effective_skips_referent_watch` | **PASS** |
| `relationship_only` watch fails closed per referent | `test_relationship_only_watch_fails_closed_per_referent` | **PASS** |
| Fail-closed isolated per referent (live routing override allows) | `test_relationship_only_watch_allowed_with_live_routing_override` | **PASS** |
| Override change while watched does not alter cache | `test_override_change_does_not_alter_cached_modes` | **PASS** |
| PROMPT-67 collision guard under custom watch mode | `test_collision_guard_still_applies_under_custom_watch_mode` | **PASS** |

**Full suite (local):**

- `python -m unittest tests.test_referent_mode_overrides tests.test_outbound_watch` → **24 tests OK**
- `php tests/panel_referent_modes_test.php` → **OK**
- `php tests/panel_relationship_path_guard_test.php` → **OK** (PROMPT-70)
- `php tests/panel_relationship_status_test.php` → **OK** (PROMPT-69)

---

## 6. PROMPT-67 / PROMPT-70 guard confirmation

- **PROMPT-67** collision guard (`_setup_watchdog_for_relationship` path equality skip): unchanged logic; verified under `relationship_only` effective watch in `test_collision_guard_still_applies_under_custom_watch_mode` and existing `test_outbound_watch` collision tests (**PASS** after stub update).
- **PROMPT-70** provisioning path guard: `panel_relationship_path_guard_test.php` run unchanged → **PASS**.

---

## 7. PROMPT-74+ scope

| Item | Scope |
|------|-------|
| **Pilot cutover** | Select one production referent; set overrides to `relationship_live` / appropriate watch mode; restart daemon; verify via relationship-status page — **operator-driven, not automated in PROMPT-74** |
| **B1–B4** (PROMPT-72) | IMAP cadence measurement, log-tail coverage under sparse shadow, filesystem sync profiling, O(n) maildir collision at scale — **only if production proves need** |
| **Option B** | Deferred until explicit isolation requirement or measured bottleneck |
| **Panel restart control** | Separate PROMPT if desired |

This PROMPT ships the **mechanism**; it does **not** execute production cutover.
