# PROMPT-72 — Scale reassessment and synthetic verification

**Branch:** `prompt-72-scale-reassessment` (from `origin/master` @ `b1fb42a`)  
**Type:** Design/audit + synthetic load verification — no daemon routing/watch logic changes.  
**Date:** 2026-09-11

**Harness artifacts:** `tests/scale_verification_harness.py`, `tests/scale_verification_harness.sh`, `tests/scale_collision_bench.php`, `tests/scale_log_coverage_bench.php`

**Measurement host:** lab VPS `192.168.125.116` (MariaDB via unix socket, PHP 8.3 PDO, Python 3.12 + `mysql-connector-python`). Log-coverage bench run locally (PHP 8.5.10, in-memory only).

---

## 1. Option A vs. B — reopened with real numbers

### 1.1 New facts (supersede PROMPT-71 scale assumption)

| Dimension | PROMPT-71 assumption | PROMPT-72 reality |
|-----------|---------------------|-------------------|
| Referent count | Undocumented; lab = 1 | **25 provisioned now**, plan **50+** |
| Relationships per referent | Not modeled | **5–10 each** → **~125–250 rows today**, **~500 at full growth** |
| Stage 1–2c verification | Implicitly lab-scale | **Only 1 referent / 2 relationships** on lab VPS until this PROMPT |

PROMPT-71 §5 trigger table: *"We expect dozens of referents and need crash isolation between groups → prefer Option B."*

**Scale clause:** **Now met on its own terms.** Twenty-five referents growing to fifty is unambiguously "dozens." This clause no longer depends on assumption.

**Isolation-need clause:** **Not met automatically and not disproven.** PROMPT-71 required explicit operator confirmation of crash-isolation need; the customer has not stated it. This PROMPT reasons about it on merits rather than inferring it from scale.

### 1.2 Isolation-cost reasoning (not assumed)

**What Option A's single-process blast radius means operationally**

If `mail-proxy-daemon.py` crashes, is OOM-killed, wedges its IMAP worker pool, or requires a supervisor restart, **every** referent's inbound IMAP polling and outbound Maildir watching stops until `systemd` brings the process back. There is no partial failure domain.

For this customer — office workstations depending on live mail flow across 25–50 referents:

| Factor | Assessment |
|--------|------------|
| **Duration of outage** | systemd `Restart=` typically recovers in seconds to low tens of seconds. Not "hours" unless the crash is recurring or startup fails. |
| **Breadth** | All 25–50 desks lose proxy routing simultaneously — not one office pod. |
| **Business impact** | Inbound: external mail stops arriving at local Maildirs until recovery. Outbound: queued Maildir files are not picked up. Staff may still read already-delivered mail locally; **live flow pauses**. |
| **Frequency** | No production incident history at this fleet size. Lab daemon has been stable at N=1. Crash frequency at N=50 is **unknown**. |
| **Mitigation without Option B** | systemd auto-restart, monitoring on `monitor.php` / alerts, staged cutover (Option A per-referent modes) limits *policy* blast radius but not *process* blast radius. |

**Conclusion on isolation:** The cost of a single-process failure is **real and fleet-wide**, but its **expected rate is unquantified**. Scale alone does not prove isolation is worth Option B's partition + panel + ops surface. **Operator should confirm** whether "one bad deploy takes 50 desks offline for 30 seconds" is acceptable or disqualifying. Until then, isolation need remains **open**.

### 1.3 Option A blast radius at N=25→50 (PROMPT-71 §2.6)

PROMPT-71 §2.6: *"One process remains the fate-sharing domain."*

At N=25→50 this limitation **strengthens in impact** (more desks per incident) but does **not** by itself become disqualifying without isolation-need confirmation:

- **Policy granularity** (staggered cutover) is still only addressable via Option A or per-instance globals — Option B does not remove the need for per-referent mode columns if staggered pilot is required inside one instance.
- **Measured DB/sync load** (§2 below) stays **orders of magnitude below** the 60-second sync period — Option A is not failing on capacity grounds in this harness.
- **IMAP worker scheduling** (§2a) is the first **potential** runtime pressure point at N=50, but depends on per-account network latency **not measured here** — see §3.

**Verdict on §2.6:** Acceptable **for now** if operator accepts fleet-wide restart risk at low frequency. Becomes **disqualifying for Option A** only if operator states isolation is mandatory — not because scale alone crossed a line.

### 1.4 Updated recommendation

**Staged approach (revises PROMPT-71 verdict in light of scale, not isolation):**

| Phase | Choice | When |
|-------|--------|------|
| **Now (PROMPT-73)** | **Option A** — per-referent DB mode overrides, single process, restart-only actuation | Staggered cutover policy is the immediate gap (PROMPT-68/71). Measured DB/sync/collision paths are comfortable at N=50. |
| **Later** | **Option B** — partitioned multi-instance | When **either** (a) operator confirms crash isolation between referent groups is mandatory, **or** (b) production measurement shows IMAP poll cadence misses (§2a arithmetic breached with real latency), **or** (c) filesystem watch scan cost in `_sync_database_state` exceeds budget (not measured here — maildirs absent on harness host). |

**What changed vs. PROMPT-71:** Scale clause for Option B is **satisfied**; recommendation does **not** flip to Option B because isolation need is **unconfirmed** and measured subsystems (except unmeasured IMAP network latency) are **not yet bottlenecks**.

**Threshold for Option B trigger (explicit):**

1. Operator sign-off: *"independent failure domains required"* → start Option B design regardless of metrics.
2. **IMAP cadence miss:** sustained evidence that `IMAP_POLL_INTERVAL=60` is missed for tail accounts (e.g. p95 poll age > 90s at N≥50). Threshold: **2× poll interval** sustained over 1 hour.
3. **`_sync_database_state` wall time > 45s** (75% of period) including filesystem scans on production maildirs — not triggered by this harness.

---

## 2. Scale verification results (measured)

Fixture pattern: `relationships_per_referent = 5 + (referent_index % 6)` → **186** relationships at N=25, **373** at N=50.  
Schema note: `uq_clients_external_account` forces **one external account per relationship**, so IMAP task count equals relationship count (not "2 accounts × referents").

### 2a. IMAP poller load — `_load_active_referents()` + `_load_accounts_for_referent()`

**Status: MEASURED (DB query time + task count). IMAP network poll duration: NOT MEASURED.**

| Metric | N=25 | N=50 |
|--------|------|------|
| Relationships / IMAP tasks | 186 | 373 |
| Combined DB time (Python harness) | **41.0 ms** | **93.8 ms** |
| Combined DB time (bash/mysql CLI, includes shell overhead) | **519 ms** | **1253 ms** |
| Worker batches (`ceil(tasks / 20)`) | **10** | **19** |
| `IMAP_WORKER_COUNT` | 20 | 20 |
| `IMAP_POLL_INTERVAL` | 60 s | 60 s |

**DB scheduling headroom:** Query planning is **negligible** vs. 60 s interval (≪1% even via bash timing).

**Worker pool arithmetic (requires assumption — not measured):**

```
wall_time_per_cycle ≈ ceil(tasks / IMAP_WORKER_COUNT) × avg_imap_poll_seconds
```

| N | Tasks | Batches | Max avg poll @ 60s budget |
|---|-------|---------|---------------------------|
| 25 | 186 | 10 | **6.0 s** per account |
| 50 | 373 | 19 | **3.2 s** per account |
| 50 @ 500 rels (projected) | 500 | 25 | **2.4 s** per account |

**Finding:** DB load is fine. **Whether accounts miss the 60-second cadence depends entirely on real IMAP round-trip time**, which this PROMPT could not measure (no synthetic IMAP server in harness). At N=50, if average poll exceeds **~3.2 s**, tail accounts slip past one interval.

**Could-not-measure:** End-to-end IMAP queue drain time with mocked or real IMAP. **Needed:** dry-run harness attaching to lab IMAP fixtures or recording production `ImapWorker` phase timings from daemon logs.

### 2b. `_sync_database_state()` — DB portion

**Status: MEASURED (DB + set reconcile). Filesystem scans EXCLUDED.**

| Component | N=25 | N=50 |
|-----------|------|------|
| `load_referents` | 3.4 ms | 5.6 ms |
| `list_watch_targets()` | 20.6 ms | 115.2 ms |
| Set reconcile (simulated) | 0.01 ms | 0.09 ms |
| **Total DB portion** | **24.0 ms** | **120.9 ms** |
| Within 60 s period? | **Yes** (0.04%) | **Yes** (0.2%) |

**Finding:** DB/sync portion **comfortably scales** at N=50. No change needed for DB queries in sync loop.

**Could-not-measure:** `_scan_existing_outgoing*` filesystem walks per relationship maildir on production paths. At 373–500 maildirs, this may dominate — **needs production or maildir-populated lab run**.

### 2c. `relationship-status.php` log-tail coverage (PROMPT-69)

**Status: MEASURED (synthetic log, `parseRelationshipObservabilityFromLog`, local PHP).**

| Relationships | Tail lines | Shadow fraction | Coverage |
|---------------|------------|-----------------|----------|
| 187 | 2000 | 35% | **100%** |
| 187 | 5000 | 35% | **100%** |
| 375 | 2000 | 35% | **100%** |
| 375 | 5000 | 35% | **100%** |
| 375 | 2000 | **10%** | **53.33%** (200/375) |

**Finding:** At realistic shadow traffic (35% of tail lines are shadow markers), **2000-line default tail is sufficient** for 375 relationships. Coverage **degrades materially** when shadow markers are sparse (10% of tail): only **53%** of relationships appear in the observability page.

**Could-not-measure:** Real production log interleaving rate (IMAP noise vs. shadow lines per hour). Synthetic model assumes uniform distribution.

### 2d. `findRelationshipUniqueCollision()` / `findRelationshipMaildirPathCollision()` (PROMPT-70)

**Status: MEASURED (PHP bench, 200 iterations, 373 `clients` rows, lab VPS).**

| Function | Total ms (200×) | Avg ms/call | Interactive OK (<50 ms)? |
|----------|-----------------|-------------|--------------------------|
| `findRelationshipMaildirPathCollision` | 613.1 | **3.07** | **Yes** |
| `findRelationshipUniqueCollision` | 552.1 | **2.76** | **Yes** |

**Finding:** Collision checks remain **fast enough for interactive panel save** at N=50 scale. `findRelationshipMaildirPathCollision` loads **all** client maildirs + referent outboxes (O(n) full scan) — acceptable at 373 rows; revisit if fleet grows past ~2000 relationships.

### 2e. `DB_POOL_SIZE=12` sufficiency

**Status: REASONED from worker counts + measured query times (not concurrent-load stress test).**

| Constant | Value |
|----------|-------|
| `DB_POOL_SIZE` | 12 |
| `IMAP_WORKER_COUNT` | 20 |
| `SMTP_WORKER_COUNT` | 20 |

Workers spend most time on network I/O (IMAP/SMTP), not holding pool connections. Measured DB operations are sub-millisecond to low milliseconds. Sync loop uses one connection at a time for `list_watch_targets`.

**Contention scenario:** If ≥13 workers simultaneously need DB connections during routing lookup, one worker blocks until a connection is returned. At measured query durations, wait time is small unless workers hold connections across long IMAP fetches.

**Finding:** **12 is sufficient at N=25–50** given measured query times. **Not proven** under concurrent burst — a connection-pool wait metric in production would close this gap.

**Could-not-measure:** Concurrent pool exhaustion under live IMAP+SMTP load.

---

## 3. Bottlenecks — scoped to future PROMPTs (not fixed here)

| ID | Component | Evidence | Likely fix (future PROMPT) |
|----|-----------|----------|----------------------------|
| **B1** | IMAP worker cadence at N≥50 | 373 tasks / 20 workers = 19 serial batches; budget **3.2 s** avg poll @ 60s interval | Measure real poll latency; if breached → raise `IMAP_WORKER_COUNT`, shard via Option B, or lengthen `IMAP_POLL_INTERVAL` with ops sign-off |
| **B2** | Panel log-tail observability | 53% relationship coverage at 375 rels, 2000 tail, 10% shadow line density | Per-relationship index, larger default tail, or time-filtered log query — panel-only PROMPT |
| **B3** | `_sync_database_state` filesystem scans | Excluded from harness (no maildirs) | Profile on production; if >45s → incremental scan or partition watches (may intersect Option B) |
| **B4** | `findRelationshipMaildirPathCollision` O(n) scan | 3 ms @ 373 rows — OK now | SQL-side path lookup with index if n > ~2000 |

---

## 4. Explicit "scales fine" findings

| Area | N=50 result |
|------|-------------|
| IMAP poller DB queries | 93.8 ms — **no change needed** |
| Sync DB portion | 121 ms — **no change needed** |
| Collision guards | ~3 ms/save — **no change needed** |
| DB pool size | Reasoning — **no change needed** at current metrics |
| Log tail (35% shadow density) | 100% coverage @ 375 rels — **no change needed** |

---

## 5. PROMPT-73 scope statement

Given staged recommendation (**Option A now**, Option B when isolation confirmed or B1/B3 proven in production):

**PROMPT-73 SHALL:**

1. Implement **Option A** per PROMPT-71: nullable per-referent mode columns on `referents`, startup-resolved effective modes, inbound/outbound `plan_*` wiring from `referent_data`, watch-loop inversion with filtered `list_watch_targets`, **restart-only** mode actuation.
2. Include schema migration + panel display/edit of override columns (read/write as designed in PROMPT-71 §2).
3. Add unit/integration tests for effective-mode resolution and watch registration heterogeneity.

**PROMPT-73 SHALL NOT:**

1. Implement Option B (multi-instance, systemd template, partition filters on IMAP/watch).
2. Execute production `relationship_live` cutover.
3. Patch B1–B4 unless a measurement in PROMPT-73's own test environment proves a blocker for Option A implementation (unlikely).

**Defer to post-PROMPT-73 / separate PROMPTs:**

- B1 IMAP cadence validation on real IMAP
- B2 panel log-tail coverage under sparse shadow
- B3 filesystem sync profiling with real maildirs
- Option B partition design (triggered by operator isolation sign-off or B1/B3)

---

## 6. Harness reproduction

```bash
# On lab VPS (MariaDB local socket):
cd /path/to/Proxy_email
bash tests/scale_verification_harness.sh
python3 tests/scale_verification_harness.py   # requires mysql-connector-python

# Log coverage (any host with PHP):
php tests/scale_log_coverage_bench.php
```

Environment: `SCALE_TEST_DB_NAME=mail_proxy_scale_test`, `SCALE_TEST_DB_UNIX_SOCKET=/var/run/mysqld/mysqld.sock` (auto-detected on Linux).
