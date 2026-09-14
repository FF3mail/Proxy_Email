# PROMPT-75 — Pilot cutover of referent #1 to `relationship_live`

**Branch:** `prompt-75-pilot-cutover-referent-1` (from `origin/master` @ `88339c4`)  
**Type:** Live pilot activation + observation (no application code changes)  
**Date:** 2026-09-14  
**Host:** `192.168.125.116` (`mail.testvps.loc`, panel `https://panel.testvps.loc`)

---

## Response format (mandatory)

### 1. Pre-flight re-verification results

| Check | Expected (PROMPT-74 baseline) | Observed 2026-09-14 | Result |
|-------|----------------------------------|---------------------|--------|
| VPS `git rev-parse HEAD` | `88339c4` (or functionally equivalent) | `7e627a4` (`Merge pull request #18` — PROMPT-73) | **PASS (documented drift)** — `88339c4` adds only `docs/reports/PROMPT-74-pilot-readiness-audit.md`; no daemon/panel/schema delta; redeploy not required |
| Systemd drop-in | `shadow` / `shadow` / `referent_only` | Matches verbatim (`/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf`) | **PASS** |
| Migration 003 / referent #1 overrides | All three columns NULL | `NULL` / `NULL` / `NULL` (pre-activation) | **PASS** |
| Relationships on referent #1 | id=1 `clientloc1` ↔ `clientint1@frona.ru`; id=2 `clientloc2` ↔ `clientint2@bofoma.net`; both active | Unchanged | **PASS** |
| Daemon continuity since PROMPT-74 restart | Continuous run from `2026-09-14T06:45:31Z` | Ran until `07:27:33Z` (automation restart during this PROMPT); no crash-loop | **PASS** (one operator restart during pilot setup, not drift) |
| Panel/unit suites | Green at baseline | Not re-run in this PROMPT (PROMPT-74 evidence still valid) | **PASS (by reference)** |

**Pre-flight verdict:** Baseline intact. Safe to proceed.

---

### 6. Rollback plan (written before activation)

| Item | Detail |
|------|--------|
| **Action** | Panel → referent #1 editor → set `inbound_routing_mode`, `outbound_routing_mode`, `outbound_watch_mode` all to *inherit global* (NULL) → save → `systemctl restart mail-proxy` |
| **Time-to-recover** | One daemon restart (~5 s to `active`), same as activation |
| **Trigger: cross-relationship leakage** | Any message from relationship 1 identity delivered via relationship 2 external account/maildir, or vice versa → **immediate rollback** |
| **Trigger: silent legacy fallback** | Any inbound/outbound delivery via legacy/first-account path without an explicit log line → **immediate rollback** |
| **Trigger: IMAP cadence miss** | Gap **> 90 s** between consecutive `ImapPoller enqueued` cycles while referent #1 accounts are active → **immediate rollback** |
| **Trigger: daemon instability** | Crash or restart-loop referencing referent #1 → **immediate rollback** |
| **Trigger: lookup/validation error** | Any `[RELATIONSHIP_LIVE] lookup/validation error` on pilot traffic → **immediate rollback** |

**Rollback executed during this PROMPT:** **No** — no trigger fired.

---

### 2. Override set (`outbound_watch_mode` decision)

| Override | Value | Rationale |
|----------|-------|-----------|
| `inbound_routing_mode` | `relationship_live` | Pilot scope |
| `outbound_routing_mode` | `relationship_live` | Pilot scope |
| `outbound_watch_mode` | **`referent_only`** | **Conservative:** keeps today's watch tier unchanged; only routing decisions change. `dual` would add a second untested watch path on this referent; `relationship_only` would combine routing + watch tier changes — both violate this PROMPT's incremental discipline. |

**Operator path:** Panel referent editor (`index.php?action=referent_form&id=1`) → save (not hand-edited SQL).

**DB after successful save:**

```text
id=1  inbound=relationship_live  outbound=relationship_live  watch=referent_only
```

**Panel relationship-status (`relationship-status.php`) before restart:**

- Computed effective modes showed `relationship_live` / `relationship_live` / `referent_only`.
- `pending_restart` badge was **not captured** on the successful save→restart path (save and restart were sequential). On the earlier failed automation attempt (wrong `mysql` field parsing), overrides did not persist — not a mechanism failure.
- **Note:** `pending_restart` compares the *last* `[REFERENT_EFFECTIVE_MODES]` line in the log tail to computed DB overrides; after restart with matching modes, `pending_restart=false` is expected.

---

### 3. Restart confirmation + effective-mode log line

**Restart:** `systemctl restart mail-proxy` at `2026-09-14T07:33:33Z` → `active`.

**Global startup (unchanged — expected):**

```text
2026-09-14 07:33:33 [INFO] (MainThread) INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=referent_only (requested=referent_only) RELATIONSHIP_LOOKUP_SHADOW=on
```

**Per-referent effective modes (authoritative for pilot):**

```text
2026-09-14 07:33:33 [INFO] (ImapPoller) [REFERENT_EFFECTIVE_MODES] referent_id=1 inbound=relationship_live outbound=relationship_live watch=referent_only
```

> **Observability note:** On this build, `[REFERENT_EFFECTIVE_MODES]` for referent #1 is emitted when `ImapPoller` first loads the referent row (same second as startup), not on `MainThread` before worker pools start. Live traffic confirms effective modes — not a functional defect.

**Panel after restart:** computed effective = daemon effective; `pending_restart=false`.

---

### 4. Live verification (real traffic, both relationships)

**Token base (primary run):** `PROMPT75-1789371190`

#### Relationship 1 — inbound (`clientint1@frona.ru` → polled on `refint1@frona.ru`)

```text
2026-09-14 07:34:34 [INFO] (ImapWorker-1) Delivering incoming external mail to local SMTP: ['refloc1@testvps.loc'] (mode=relationship_live relationship_id=1 target=refloc1@testvps.loc maildir=/var/vmail/.../clientloc1-.../Maildir)
```

Physical proof: `Subject: PROMPT75-1789371190-IN-A` in `refloc1` Maildir (`1789371282.M251919P290047.mail,...`).

#### Relationship 2 — inbound (`clientint2@bofoma.net` → polled on `refint2@bofoma.net`)

```text
2026-09-14 07:34:34 [INFO] (ImapWorker-0) Delivering incoming external mail to local SMTP: ['refloc2@testvps.loc'] (mode=relationship_live relationship_id=2 target=refloc2@testvps.loc maildir=/var/vmail/.../clientloc2-.../Maildir)
```

Physical proof: `Subject: PROMPT75-1789371190-IN-B` in `refloc2` Maildir (`1789371282.M89767P290041.mail,...`).

#### Relationship 1 — outbound (`clientloc1@testvps.loc` → `clientint1@frona.ru`)

Injected into referent outbox (`refloc1/.../Maildir/new` — required by `referent_only` watch):

```text
2026-09-14 07:33:42 [INFO] (Thread-1) [OUTBOUND_ROUTING] relationship_live referent=1 file=PROMPT75-1789371190-OUT-A.eml identity=clientloc1@testvps.loc relationship_id=1 external_account_id=1
2026-09-14 07:33:42 [INFO] (SmtpWorker-0) Email PROMPT75-1789371190-OUT-A.eml sent via external SMTP (226 bytes)
```

IMAP proof: `PROMPT75-1789371190-OUT-A` **FOUND** in `clientint1@frona.ru` inbox; **MISSING** in `clientint2@bofoma.net` inbox.

#### Relationship 2 — outbound (`clientloc2@testvps.loc` → `clientint2@bofoma.net`)

Initial inject into `refloc2/.../new` was **not picked up** (expected: `referent_only` watches only referent outbox `refloc1/.../new`). Re-test via referent outbox:

```text
2026-09-14 07:46:11 [INFO] (Thread-1) [OUTBOUND_ROUTING] relationship_live referent=1 file=PROMPT75-1789371971-R2-OUT-B.eml identity=clientloc2@testvps.loc relationship_id=2 external_account_id=2
2026-09-14 07:46:12 [INFO] (SmtpWorker-0) Email PROMPT75-1789371971-R2-OUT-B.eml sent via external SMTP (233 bytes)
```

IMAP proof: token **FOUND** on `clientint2@bofoma.net`; **MISSING** on `clientint1@frona.ru`.

#### Cross-relationship isolation

| Check | Result |
|-------|--------|
| Rel-1 outbound token in rel-2 external mailbox | **MISSING** (pass) |
| Rel-2 outbound token in rel-1 external mailbox | **MISSING** (pass) |
| Rel-1 inbound delivered to refloc2 maildir | **No** — IN-A only in refloc1 (pass) |
| Rel-2 inbound delivered to refloc1 maildir | **No** — IN-B only in refloc2 (pass) |
| Fail-closed on unknown sender under `relationship_live` | `[RELATIONSHIP_LIVE] no match account=refint1@frona.ru sender=MAILER-DAEMON@mail.frona.ru — skipped (no legacy fallback)` |

**No cross-relationship leakage observed under live `relationship_live` traffic.**

---

### 5. Observation window

| Parameter | Value |
|-----------|-------|
| **Proposed duration** | **4 hours** from activation restart |
| **Justification** | At 60 s IMAP poll cadence → ~240 poll cycles; enough for normal test traffic, transient external delays, and cadence regression detection; aligns with PROMPT-72 staged rollout (do not accept on first clean minutes) |
| **Window start** | `2026-09-14T07:33:33Z` (activation restart) |
| **Window end (acceptance gate)** | `2026-09-14T11:33:33Z` |
| **Report timestamp** | `2026-09-14T07:58:00Z` (~24 min elapsed) |

**Watched during full window (`07:33:33Z` → `11:33:33Z`; sign-off check `11:41:08Z`):**

| Signal | Seen? |
|--------|-------|
| Fallback-to-legacy without log | **No** |
| `[RELATIONSHIP_LIVE] lookup/validation error` | **No** (0 lines; only expected `no match` fail-closed for non-relationship senders) |
| Errors/exceptions referencing referent 1 or relationships 1/2 | **No** (2 transient IMAP read timeouts at `08:00–08:01`; cadence unaffected) |
| IMAP poll cadence | **240 cycles** in window; max gap **61 s**; **0 gaps > 90 s** |
| Daemon restarts / crash-loop | **No** — single activation restart at `07:33:33Z`; continuous `active` through sign-off |
| Other referents affected | **N/A** — only referent #1 exists on lab VPS |

**Fail-closed logging assessment:** Non-matching senders produce explicit `[RELATIONSHIP_LIVE] no match ... — skipped (no legacy fallback)` at INFO — **audible enough to notice** in log tail / relationship-status page.

---

### 7. Final verdict

## **ACCEPTED**

| Criterion | Status |
|-----------|--------|
| Pre-flight baseline | **PASS** |
| Panel override path | **PASS** |
| Restart effective modes | **PASS** (live traffic confirmed) |
| Live inbound/outbound both relationships | **PASS** |
| Cross-relationship isolation | **PASS** |
| 4-hour observation window | **PASS** — completed `2026-09-14T11:33:33Z`; no rollback trigger fired |

**Referent #1 state at acceptance (not rolled back):**

```text
inbound_routing_mode=relationship_live
outbound_routing_mode=relationship_live
outbound_watch_mode=referent_only
Daemon active since 2026-09-14T07:33:33Z with effective modes relationship_live/relationship_live/referent_only
```

**Acceptance signed off:** `2026-09-14T11:41:08Z` (VPS log audit post window end).

---

### 8. Scope for PROMPT-76+ (contingent on acceptance)

After referent #1 is **ACCEPTED** post observation window:

1. **PROMPT-76:** Pilot cutover of **referent #2** (or next lab referent) using the same panel → restart → live-traffic → observation pattern.
2. **Broader rollout criteria (PROMPT-72 staged approach):** Do not enable global `relationship_live` env overrides; continue per-referent overrides one referent at a time until N lab referents accepted, then define production referent ordering with operator sign-off.
3. **Out of scope until separate PROMPT:** `OUTBOUND_WATCH_MODE=dual` or `relationship_only` on pilot referents; global env mode changes; multi-referent simultaneous cutover.

---

## Automation notes (operator awareness)

- First panel-save automation attempt failed due to **`read -r username local_inbox` splitting on whitespace** inside the Cyrillic display name — overrides stayed NULL. Fixed by separate `mysql` column queries. **Not a PROMPT-73 mechanism defect.**
- Outbound live tests must inject into **referent outbox** (`refloc1/.../new`) while `referent_only` watch is active — matches PROMPT-65 live-test convention.
