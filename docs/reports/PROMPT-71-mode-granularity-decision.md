# PROMPT-71 — Per-referent routing/watch mode granularity: decision document

**Branch:** `prompt-71-mode-granularity-decision` (from `origin/master` @ `b1fb42a`)  
**Type:** Architectural decision only — no code, schema, migration, config, or systemd changes.  
**Date:** 2026-09-11

**Citation note:** All line numbers below were re-verified against `mail-proxy-daemon.py` at `b1fb42a`. They match the inventory given in the PROMPT text (no drift found for the cited sites). Where this document adds sites the prompt inventory omitted, those are marked **(gap filled)**.

**Related context:** PROMPT-68 cutover readiness plan lives on branch `prompt-68-cutover-readiness-plan` (`9e92083`) and is **not yet merged into master**. This decision still treats its problem statement (global mode actuation vs. referent-scoped readiness) as the motivating requirement.

---

## 1. Completed / corrected inventory

### 1.1 Prompt-cited sites (verified)

| Assumption | File:line @ `b1fb42a` | Single-instance-breaking if two daemons share one DB? | What multi-instance would require |
|------------|------------------------|------------------------------------------------------|-----------------------------------|
| `INBOUND_ROUTING_MODE` global at import | `mail-proxy-daemon.py:100` | No (each process has own env) | Per-instance env or DB override — not a coexistence blocker by itself |
| `OUTBOUND_ROUTING_MODE` global at import | `:102` | No | Same |
| `_REQUESTED_OUTBOUND_WATCH_MODE` / `OUTBOUND_WATCH_MODE` | `:104–106`, resolved `:2276` in `main()` | No | Same |
| Fixed `PID_FILE` | `:62`, `write_pid_file()` `:2241` | **Yes** — last writer wins; `monitor.php` reads one path | Parameterize path (`%i` / env); panel must know which PID to read |
| Fixed `LOG_FILE` | `:60` | **Yes** — interleaved logs; rotation races | Per-instance log path; panel log allowlist / selector |
| Fixed shadow stats path | `relationship_shadow.py` `SHADOW_STATS_FILE`; panel `PANEL_SHADOW_STATS_FILE` | **Yes** — counters overwrite each other | Per-instance stats file or namespaced filename |
| Plain (non-templated) `mail-proxy.service` | `mail-proxy.service` | **Yes** — one unit, one `RuntimeDirectory=mail-proxy` | `mail-proxy@.service` with `%i`, separate RuntimeDirectory / drop-ins |
| `DB_POOL_SIZE = 12` | `:87`, pool created `:252–254` | **Partial** — not correctness-breaking alone, but N×12 connections against one MariaDB | Re-budget `max_connections` and per-instance pool size (see §3) |
| `_load_referents()` unscoped `WHERE active = 1` | `:1968–1978` | **Yes — correctness** — every instance watches every active referent | Partition filter (allowlist / `instance_name`) on every load path |
| Watch gates are global booleans | `_watch_referent_level_enabled` `:1540–1544`, `_watch_relationship_level_enabled` `:1546–1550` | No for mode semantics; **Yes** when combined with unscoped loops | Either partition membership **or** per-referent gate (Option A) |
| Watch registration loops treat all referents identically | `start()` `:1464–1479`; `_sync_database_state()` `:2000–2039` | **Yes** with unscoped load — duplicate inotify + dual enqueue risk | Partitioned `_load_referents()` / `list_watch_targets()` |
| Inbound decision already has `referent_data` | `_deliver_to_local_smtp` `:759`; `plan_inbound_delivery(mode=INBOUND_ROUTING_MODE)` `:776` and fallback `:839` | No | N/A for coexistence; relevant to Option A wiring |
| Outbound decision already has `referent_data` | `_enqueue_outbound_file` `:1761`; `plan_outbound_delivery(mode=OUTBOUND_ROUTING_MODE, … referent_id=…)` `:1789–1794` | No | N/A for coexistence; relevant to Option A wiring |
| Panel assumes one daemon | `web/monitor.php` `DAEMON_PID_FILE`; `web/logs.php` / `relationship_status.php` fixed log + stats | **Yes** (ops/UI) | Selector, per-instance pages, or aggregate view (sketch §3) |

### 1.2 Gaps filled (prompt inventory was incomplete)

| Assumption | File:line @ `b1fb42a` | Single-instance-breaking? | Fix under multi-instance |
|------------|------------------------|---------------------------|--------------------------|
| **IMAP poller loads ALL active referents** | `ImapPoller._load_active_referents` `:1276–1287` — same unscoped `SELECT … FROM referents WHERE active = 1` as `_load_referents()` | **Yes — correctness** — two instances would both poll the same external IMAP accounts → duplicate fetch / Seen races / double local delivery | Same partition filter as `_load_referents()`; apply before account expansion |
| **Accounts are scoped only after referent load** | `_load_accounts_for_referent` `:1294–1309` — `WHERE referent_id = %s AND active = 1` (no LIMIT on inbound poll) | **Yes if parent referent set is unscoped**; no if referents are partitioned | Partition at referent load; account query itself is already per-referent |
| **`list_watch_targets()` is global** | `relationship_lookup.py` `:204–223` — all valid relationships, no instance/referent filter beyond validity | **Yes** under multi-instance without partition | Filter by assigned referent IDs (or instance column join) |
| **Fixed worker pool sizes** | `IMAP_WORKER_COUNT = 20` `:82`, `SMTP_WORKER_COUNT = 20` `:83`; pools `:1397–1401` | Resource / MariaDB / FD pressure under N instances (LimitNOFILE sized for one process in unit file) | Per-instance sizing; re-evaluate `LimitNOFILE` / `LimitNPROC` on template unit |
| **Fixed queue depths** | `IMAP_QUEUE_MAXSIZE = 5000` `:85`, `SMTP_QUEUE_MAXSIZE = 1000` `:86` | Resource only | Tune per instance or leave if partition keeps load similar |
| **Shared temp dir** | `TEMP_DIR = '/var/spool/mail-proxy/tmp'` `:89` | **Yes** — tempfile name collisions / cleanup races across processes | Per-instance temp subdir |
| **Process-local `_queued_outgoing_files`** | `:1436–1440` | **Yes without partition** — each process thinks a file is new → duplicate SMTP enqueue; **No with disjoint paths** | Partition so two instances never watch the same Maildir |
| **MySQL pool name constant** | `pool_name='mail_proxy_pool'` `:253` | No across separate processes (separate address spaces) | Optional unique name per instance for clarity; not a blocker |
| **No PID flock / leader election** | `write_pid_file()` best-effort write only `:2241–2255` | **Yes** — nothing prevents two processes from starting | Advisory lock **or** systemd template + partition discipline |
| **Legacy outbound `LIMIT 1` load** | `_load_legacy_outbound_account` `:1728–1748` | No by itself (keyed by `referent_id`) | Fine under partition |
| **Cryptor / `/etc/mail-proxy` key material** | shared read-only paths in unit | No (shared secret is intentional) | Keep shared; do not duplicate keys per instance |

### 1.3 IMAP conclusion (explicit)

Yes: IMAP has the **same unscoped-referent problem** as `_load_referents()`.  
`ImapPoller` expands every active referent, then every active account for that referent. Under two unpartitioned instances this is not “extra CPU” — it is **duplicate remote IMAP sessions and duplicate inbound delivery attempts**. Account loading is not independently unscoped; the bug is entirely at the referent enumeration layer (plus global `list_watch_targets()` for outbound watches).

---

## 2. Option A — per-referent mode, single process, DB-driven override

### 2.1 Schema sketch (not a migration)

Additive nullable columns on `referents` (preferred over a new table for MVP clarity):

| Column | Type (sketch) | Semantics |
|--------|---------------|-----------|
| `inbound_routing_mode` | `ENUM('legacy','shadow','relationship_live') NULL` | `NULL` → inherit process global `INBOUND_ROUTING_MODE` |
| `outbound_routing_mode` | `ENUM('legacy','shadow','relationship_live') NULL` | `NULL` → inherit `OUTBOUND_ROUTING_MODE` |
| `outbound_watch_mode` | `ENUM('referent_only','dual','relationship_only') NULL` | `NULL` → inherit effective global `OUTBOUND_WATCH_MODE` |

Effective resolution (sketch):

```
effective(mode_col, global) = mode_col if mode_col is not NULL else global
```

Fail-closed rule for watch (preserve today’s invariant): if effective outbound watch is `relationship_only` but effective outbound routing is not `relationship_live` for that referent → force that referent’s watch to `referent_only` and log (mirror `resolve_effective_outbound_watch_mode()`).

No UNIQUE / CHECK games with global env — application resolves at load time.

### 2.2 Routing decision points — what actually changes

**Inbound (`_deliver_to_local_smtp`, `:776` and `:839`)**

Today:

```python
plan = plan_inbound_delivery(mode=INBOUND_ROUTING_MODE, …)
```

Required change:

1. Ensure `referent_data` carries the effective inbound mode (loaded with the referent row, or looked up from an in-process map filled at startup).
2. Replace the global constant with `mode=referent_data['effective_inbound_routing_mode']` at **both** call sites (`:776` and the exception-path fallback `:839` — missing the second site would silently diverge).

Ripple: **small and local** for inbound. `plan_inbound_delivery()` already takes `mode` as a parameter; no signature change required in `relationship_routing.py`. Shadow counters remain process-wide (acceptable; PROMPT-69 already documents that).

**Outbound (`_enqueue_outbound_file`, `:1789`)**

Today:

```python
plan = plan_outbound_delivery(mode=OUTBOUND_ROUTING_MODE, …, referent_id=int(referent_data['id']), …)
```

Same pattern: pass effective outbound mode from `referent_data`.  
Additional call site using the global today: `:1834` (`if OUTBOUND_ROUTING_MODE == OutboundRoutingMode.RELATIONSHIP_LIVE`) — must use the same effective value or fail-closed behaviour drifts per message.

Ripple: **still local**, but must audit every `OUTBOUND_ROUTING_MODE` / `INBOUND_ROUTING_MODE` read inside per-message paths (not only the two `plan_*` calls). Module-level globals remain the **fallback default**, not the live decision.

### 2.3 Watch-mode for-loop restructuring (the real cost)

Today the architecture is:

```
if _watch_referent_level_enabled():          # global boolean
    for ref in ALL_active_referents:
        setup_referent_watch(ref)
if _watch_relationship_level_enabled():      # global boolean
    for target in ALL_valid_relationships:
        setup_relationship_watch(target)
```

`_watch_*_enabled()` cannot grow a `referent_id` parameter and keep the same outer structure — the gate decides the **shape of the loop**, not a property of one iteration.

**Structural redesign (required for Option A):**

```
for ref in ALL_active_referents:
    wmode = effective_watch_mode(ref)
    if wmode in (referent_only, dual):
        setup_referent_watch(ref)
    if wmode in (dual, relationship_only):
        for target in list_watch_targets_for_referent(ref.id):
            setup_relationship_watch(target)
```

Consequences that are **not** “add a parameter”:

1. **`list_watch_targets()` must become filterable by `referent_id`** (or the daemon must post-filter). Today’s global list is wrong under mixed modes: a `referent_only` referent must not get relationship-level observers.
2. **`_sync_database_state()` must reconcile per referent**, not “all referent watches on/off”. Today’s branch `if self._watch_referent_level_enabled():` (`:2000`) would incorrectly skip referent-watch maintenance entirely when the *global* default is `relationship_only` even if one referent still inherits/overrides to `dual`/`referent_only` — or the inverse.
3. **Registry bookkeeping becomes heterogeneous:**
   - `_watched_referent_ids` / `_referent_path_registry` — only referents whose effective mode includes referent-level watch.
   - `_watched_relationship_ids` / path maps — only relationships whose parent’s effective mode includes relationship-level watch.
4. **PROMPT-67 path-collision guard stays relevant and gets sharper:** if referent A is `dual` and relationship path equals A’s outbox, skip duplicate Observer (accepted case). If a second relationship on A also claims that path, PROMPT-70 already blocks at save time; daemon must still fail closed if legacy bad data exists.
5. **`[OUTBOUND_WATCH_DUAL]` logging** (`:1707`) today checks the global `OUTBOUND_WATCH_MODE`. Under Option A it must check the **file’s referent effective mode**, or dual-divergence noise appears for referents not in dual.

### 2.4 Mid-operation mode transition (confronted)

Suppose referent R flips `outbound_watch_mode` from `referent_only` → `relationship_only` while the daemon runs:

| Step | Risk if applied live |
|------|----------------------|
| Unschedule referent Observer for R | Window where neither tier watches → missed outbound files |
| Schedule relationship Observers for R’s relationships | If done before unschedule completes, brief dual pickup on shared/collision paths |
| `_queued_outgoing_files` / backlog scan | Backlog scan on the newly watched path may re-enqueue files already handled via the old path (or miss files deleted after first pickup) |
| Mode flip `relationship_only` → `referent_only` | Inverse race; relationship emitters must be fully unscheduled before referent watch resumes |

`_sync_database_state()` already safely handles **membership** changes (active referent added/removed; relationship validity add/remove/path change). That is **not** the same problem as **mode shape** changes. Membership reconciliation mutates which IDs are watched under a fixed global shape. Mode reconciliation changes the shape per ID.

### 2.5 Sub-decision (resolved): restart-only for override application

**Decision: per-referent overrides take effect only after a full daemon restart.**

Reasoning:

1. Matches today’s operational model exactly (env modes already require restart via `main()` resolution at `:2276`).
2. Avoids inventing a transactional watch-shape migration inside the 60s sync loop.
3. `_sync_database_state()` continues to handle **active/validity membership** under the **startup-resolved** effective modes (including reading mode columns when a **new** referent appears — new referents may adopt their DB override immediately for *membership setup*, but **changes** to mode columns on already-watched referents wait for restart). Document that split in PROMPT-72 so operators are not surprised.
4. Panel / observability (PROMPT-69) already surfaces the last startup mode line; extend later to show per-referent overrides vs. effective-at-startup values.

**Rejected alternative:** live apply via `_sync_database_state()`. Higher correctness risk for a cutover feature that operators will change infrequently and deliberately.

### 2.6 Blast radius (limitation Option A does not fix)

One process remains the fate-sharing domain: a segfault, runaway FD use, wedged IMAP worker pool, or bad global dependency still takes **every** referent offline together. Option A gives **routing/watch policy granularity**, not **failure isolation**.

---

## 3. Option B — multi-instance, one process per disjoint referent-group

### 3.1 What must be parameterized before two instances are safe

| Resource | Today | Multi-instance requirement |
|----------|-------|----------------------------|
| PID file | `/run/mail-proxy/mail-proxy.pid` | `/run/mail-proxy/<instance>/mail-proxy.pid` or `mail-proxy@%i.pid` |
| Log file | `/var/log/mail-proxy/mail-proxy-daemon.log` | Per-instance log; rotate independently |
| Shadow stats | `/run/mail-proxy/relationship_shadow_stats.json` | Per-instance filename |
| Temp dir | `/var/spool/mail-proxy/tmp` | Per-instance subdirectory |
| systemd unit | `mail-proxy.service` | `mail-proxy@.service` with `Environment=INSTANCE_NAME=%i`, separate `RuntimeDirectory` |
| **Referent/account partition** | None | **Mandatory** — without it, IMAP + watch double-processing is guaranteed |

**Partition mechanism sketch (prefer one; do not implement here):**

1. **Env allowlist (fastest ops experiment):** `REFERENT_IDS=1,2,5` parsed at startup; every `_load_referents` / `_load_active_referents` / `list_watch_targets` filters to that set. Empty/unset = “all” (preserve single-instance behaviour).
2. **DB column (cleaner long-term):** `referents.instance_name VARCHAR(64) NULL` — instance loads `WHERE active=1 AND (instance_name = :me OR (:me = 'default' AND instance_name IS NULL))`.

Recommendation if Option B is ever chosen: start with env allowlist for lab proof, then DB column once panel assignment UX exists. **Either way, partition must hit IMAP and watch paths in the same commit** — watch-only partition still double-polls IMAP.

### 3.2 Panel impact (sketch)

| Surface | Today | Multi-instance change |
|---------|-------|------------------------|
| `monitor.php` | One PID file | Instance selector, or cards per instance |
| `logs.php` | One allowlisted daemon log | Allowlist expands to `daemon:<instance>` sources |
| `relationship-status.php` | One log tail + one stats file | Aggregate view (harder) **or** forced instance selector (simpler, recommended first) |

Do **not** pretend an aggregate “merged” shadow-stats view is free — counters are process-local and would double-count if naïvely summed across overlapping partitions (which must not overlap).

### 3.3 DB_POOL_SIZE / MariaDB budget

Do not assert “raise `max_connections`.” Approach:

1. Measure peak connections for **one** instance under soak (pool=12 is an upper bound per process, not average in-use).
2. Budget: `N_instances × DB_POOL_SIZE + panel/php-fpm + admin + margin ≤ max_connections`.
3. Prefer **lowering per-instance `DB_POOL_SIZE`** when partitioning reduces concurrent referents/accounts per process, rather than only raising server max.
4. Validate with `SHOW STATUS LIKE 'Threads_connected'` / `Max_used_connections` during dual-instance dual-poll soak — specifically prove partitions are disjoint by zero duplicate IMAP login storms.

Worker/`LimitNOFILE` math in `mail-proxy.service` comments assumes one process of 20+20 workers — template units need the same audit per instance.

### 3.4 Blast radius (Option B’s real advantage)

Genuine process isolation: crash, restart, or bad env on instance A does not stop instance B’s poll/watch loops. That is the advantage worth the integration cost — **not** “cleaner architecture” in the abstract.

Cost: partition correctness is a permanent invariant; a misassigned referent (in two allowlists, or in none) is a silent production incident class the codebase has never had to detect.

---

## 4. Scale context

| Source | Finding |
|--------|---------|
| `README.md` | Describes a corporate mail proxy; **no referent-count expectation** |
| `docs/DELTA-transit_anchor.md` | Schema/ops oriented; **no production N** for referents |
| Lab VPS evidence (PROMPT-61+) | **1 active referent**, 2 relationships — a functional shape, not a capacity claim |
| Roadmap / PROMPT-52–55 reports | Discuss cardinality of accounts/relationships per referent; **not fleet size** |

**Explicit open question for the operator (must answer before treating this decision as final for capacity planning):**

> How many active referents should one production deployment serve in the next 12–24 months — a handful (≤5), a small fleet (≈10–30), or more?

This document does **not** invent a number to favour either option.

---

## 5. Recommendation

### Verdict: **Option A** (per-referent DB overrides, single process), with **restart-only** application of mode overrides.

### Evidence-based justification (not preference)

1. **Problem that created this PROMPT:** PROMPT-68’s cutover plan needs *referent-scoped readiness* while today’s actuation is *instance-global*. That is a **policy granularity** problem. Option A addresses it directly at the two live `plan_*` call sites that already carry `referent_data`.
2. **Option B’s mandatory prerequisite is larger than Option A’s watch rewrite:** unscoped IMAP + unscoped watches mean multi-instance is incorrect on day one without partition plumbing across every load path, plus PID/log/stats/temp/systemd/panel. That cost buys **failure isolation**, which is valuable — but it is **not** what blocks staggered `relationship_live` cutover.
3. **Watch complexity under Option A is real and was confronted:** it is not “add a parameter.” It is loop inversion + filtered `list_watch_targets` + heterogeneous registries. It remains bounded inside one process and is manageable **if** live mode flips are refused (restart-only sub-decision).
4. **Scale is unknown**, so Option B cannot be justified on capacity grounds yet. Lab evidence is N=1. Choosing B “just in case” would front-load the highest-risk coexistence bugs without a measured need.
5. **Blast-radius limitation is accepted explicitly:** if the operator later states that independent failure domains are mandatory at fleet scale, Option B becomes the right *additional* track — it is not invalidated by choosing A now (see §6).

### Confidence and what would change the verdict

| If operator says… | Then |
|-------------------|------|
| “We will cut over the whole host at once; N stays tiny” | Option A can be **deferred**; next work is global cutover execution with PROMPT-69 observability — revisit A only if staggered pilot is required |
| “We need pilot one referent live while others stay shadow” | **Proceed with Option A** as scoped in §7 |
| “We expect dozens of referents and need crash isolation between groups” | Prefer **Option B (partitioned instances with still-global modes per instance)** for isolation; per-referent columns become optional sugar, not the first lever |

Absent that operator input on staggered cutover need vs. isolation need, the roadmap-shaped evidence (PROMPT-68 → 71) supports **Option A** over B.

---

## 6. Reversibility

| Path | Reversibility |
|------|----------------|
| **Option A first** | High. Nullable override columns are additive; setting them all back to `NULL` restores pure global-env behaviour. Daemon code paths can keep reading globals as fallback forever. **Does not foreclose Option B:** later instances can still partition referents while each instance continues to honour per-referent overrides *or* ignore them and use only env. |
| **Option B first** | Medium–low to reverse. Once ops depends on `mail-proxy@.service`, per-instance logs/PIDs, and partition assignment, collapsing back to one process is an ops migration. Panel selectors remain. Choosing B first does **not** make Option A impossible, but the project would then carry two complexity axes. |

**Conclusion:** Option A is the more reversible first move. Committing to A does **not** permanently block B; committing to B first creates longer-lived operational surface area.

---

## 7. Explicit scope for PROMPT-72 (only if Option A proceeds)

### PROMPT-72 SHOULD

1. Add nullable mode override columns (migration) + daemon load into referent records.
2. Resolve effective inbound/outbound routing modes per referent at **startup** (and for newly activated referents during sync membership setup).
3. Wire effective modes into `_deliver_to_local_smtp` (`:776` and `:839`) and `_enqueue_outbound_file` (`:1789`, plus `:1834` and any other per-message global reads found by audit).
4. Invert watch registration / sync loops to per-referent effective watch mode; filter relationship watch targets by referent.
5. Preserve fail-closed watch vs. routing invariant per referent.
6. Extend panel observability to show override vs. effective-at-startup values (read-only).
7. Unit tests for: NULL inherits global; override wins; watch gate per referent; relationship_only without relationship_live fail-closed per referent; restart required for mode *changes* on already-watched referents.

### PROMPT-72 MUST NOT

1. Live-apply mode column changes for already-watched referents inside `_sync_database_state()` (restart-only).
2. Introduce multi-instance / systemd templates / PID-log partitioning (Option B track).
3. Add control actions that flip modes from the observability page without an explicit later PROMPT.
4. Add DB UNIQUE constraints unrelated to this decision.
5. Change PROMPT-67 runtime collision semantics or PROMPT-70 provisioning guards except to remain compatible.
6. Execute production `relationship_live` cutover (that remains a later cutover PROMPT after observability proves the pilot referent).

---

## Appendix — Citation checklist vs prompt text

| Prompt claim | Verified @ `b1fb42a` |
|--------------|----------------------|
| L100 / L102 / L104–106 mode globals | Exact match |
| L2276 `resolve_effective_outbound_watch_mode` in `main()` | Exact match (`:2275–2278`) |
| L759 `_deliver_to_local_smtp`; L776 / L839 `plan_inbound_delivery` | Exact match |
| L1761 `_enqueue_outbound_file`; L1789 `plan_outbound_delivery` | Exact match |
| L1540–1550 watch gates | Exact match |
| L1464–1479 start loops; L2000 sync | Exact match |
| L1968 `_load_referents` unscoped | Exact match |
| L62 PID / L60 LOG / L87 DB_POOL_SIZE | Exact match |
| No mode columns on `referents` | Still true (`SELECT id, username, local_inbox, local_outbox` only) |
