# PROMPT-68 — Production cutover readiness plan for `relationship_live` routing

**Branch:** `prompt-68-cutover-readiness-plan`  
**Base:** `origin/master` @ `ae6dc9d` (verified at branch creation)  
**Date:** 2026-09-11  
**Type:** Design / audit only — no code, schema, or config changes in this PROMPT.

---

## Executive finding (read first)

**Routing and watch modes are global-only today.** `relationship_routing.py` exposes `parse_inbound_routing_mode()`, `parse_outbound_routing_mode()`, and `parse_outbound_watch_mode()` — each reads a single process-wide environment variable. `mail-proxy-daemon.py` assigns module-level `INBOUND_ROUTING_MODE`, `OUTBOUND_ROUTING_MODE`, and `OUTBOUND_WATCH_MODE` once at startup (`main()` resolves watch mode via `resolve_effective_outbound_watch_mode()` against the global outbound routing mode).

There is **no** `resolve_effective_*_mode(referent_id=…)` and no per-referent override in the database or panel.

**Implication:** A systemd drop-in that sets `INBOUND_ROUTING_MODE=relationship_live` affects **every** referent and **every** ClientRelationship on that daemon instance. The runbook below is written at *referent-scoped readiness* granularity (what must be true for referent *R* before an operator would trust going live), but the **actuation step is necessarily instance-global** until a future PROMPT adds per-referent mode selection.

On the lab VPS (192.168.125.116) this collapses to a single referent (`referent_id=1`, two relationships) — referent-scoped readiness and global flip are equivalent there. Multi-referent production hosts must either (a) accept simultaneous cutover for all referents whose preconditions pass, or (b) defer live cutover until per-referent mode exists.

---

## 1. Preconditions inventory

For referent **R** to be *ready* for the target state:

```text
INBOUND_ROUTING_MODE=relationship_live
OUTBOUND_ROUTING_MODE=relationship_live
OUTBOUND_WATCH_MODE=relationship_only
```

…every item below must hold for **all** ClientRelationship rows where `clients.referent_id = R` (and for referent **R** itself). Until per-referent modes exist, the operator must also confirm that **no other referent** on the same daemon is attached to this cutover unless intentional.

### 1.1 Data model / panel completeness (per relationship on R)

| # | Precondition | Verification | Code / doc basis |
|---|--------------|--------------|------------------|
| P1 | Row passes `RelationshipLookup` valid-relationship predicate | SQL join matches `_VALID_RELATIONSHIP_WHERE` in `relationship_lookup.py` | `active=1`, all four emails non-empty, `external_account_id` set, linked account + referent active |
| P2 | PROMPT-57 backfill completed — row is **not** legacy-only | Panel `relationshipIsLegacyOnly()` false; not on `relationship_backfill` backlog | PROMPT-57; incomplete rows excluded from `list_poll_targets()` / `list_watch_targets()` |
| P3 | `external_account_id` points to an `external_accounts` row with `referent_id = R` | SQL: `ea.referent_id = c.referent_id` | `_VALID_RELATIONSHIP_WHERE` |
| P4 | `local_client_email`, `local_referent_email`, `external_client_email` are the operational addresses (no placeholder / typo) | Panel review + optional test message | PROMPT-61 shape |
| P5 | Linked external account passes `_validate_account_settings()` (FIX P7) | Daemon startup / account load; panel account editor | `mail-proxy-daemon.py` |

### 1.2 Filesystem / Maildir (per relationship on R)

| # | Precondition | Verification |
|---|--------------|--------------|
| P6 | `local_client_maildir` exists on disk, is a directory | `test -d "$maildir"` as root |
| P7 | `local_client_maildir/new` exists (daemon does **not** auto-create) | `test -d "$maildir/new"` |
| P8 | `local_client_maildir` and `local_client_maildir/new` are readable/writable by `vmail` | `namei -l` / `sudo -u vmail test -rw` | `_validate_relationship_maildir_for_watch()` |
| P9 | Inbound live validation: `validate_relationship_for_live()` would pass | `os.path.isdir(local_client_maildir)` | `relationship_routing.py` |
| P10 | Referent `local_outbox/new` exists (still required until watch retired) | Only while not yet on `relationship_only` | `_setup_watchdog_for_referent()` |

### 1.3 Path collision (per relationship on R — explicit, not generalized)

| Relationship path vs referent `local_outbox/new` | Lab VPS referent 1 | Required disposition before `relationship_only` |
|--------------------------------------------------|--------------------|--------------------------------------------------|
| **Distinct paths** (normal) | **Yes** — e.g. `clientloc1` Maildir ≠ `refloc1` outbox (PROMPT-67 dual test) | Standard: relationship watch registers separate Observer path |
| **Equal paths** (relationship `local_client_maildir/new` == referent `local_outbox/new`) | **Not observed** on lab VPS | **Accepted behavior:** PROMPT-67 collision guard — no second Observer; relationship covered by referent-level handler; warning `OUTBOUND_WATCH: relationship … equals referent …` in log. Operator must acknowledge single-watch semantics before `relationship_only` (outbound pickup still works via referent handler; `_enqueue_outbound_file()` uses From-based lookup in `relationship_live`) |
| **Two relationships share one `local_client_maildir`** | **Not observed** on lab VPS (clientloc1 ≠ clientloc2 paths) | Existing dedup: one Observer, multiple IDs in registry — acceptable |
| **Symmetric: two relationships + referent all on one path** | **Not on lab VPS** | See §3 — **prevent at provisioning**; do not rely on code |

### 1.4 Traffic / MTA provisioning (per relationship on R)

| # | Precondition | Verification |
|---|--------------|--------------|
| P11 | Local clients send outbound mail into **`local_client_maildir/new`**, not only into `referent.local_outbox/new` | Inject test from client mailbox path; confirm pickup in `dual` before `relationship_only` |
| P12 | Inbound external mail for each relationship has been observed in shadow with **AGREE** or acceptable DIVERGE analysis | Log grep `[RELATIONSHIP_SHADOW]` / `[OUTBOUND_RELATIONSHIP_SHADOW]` per `relationship_id` |
| P13 | Dual-watch soak completed for R's relationships (recommended) | `OUTBOUND_WATCH_MODE=dual`; zero unexpected `[OUTBOUND_WATCH_DUAL]` + missed deliveries | PROMPT-66/67 |

### 1.5 Operational / instance-level

| # | Precondition | Verification |
|---|--------------|--------------|
| P14 | If multiple referents on host: operator accepts **global** mode flip or defers cutover | Explicit sign-off (see §6) |
| P15 | Rollback drop-in tested on lab or staging | PROMPT-63/66/67 rollback evidence |
| P16 | `mail-proxy.service` restarts cleanly (`ExecStartPre` log `chown` — PROMPT-67 ops note) | `systemctl restart mail-proxy` |

---

## 2. Per-referent cutover runbook (readiness scoped; actuation global)

### 2.1 Granularity finding (code-verified)

| Mode variable | Parser | Per-referent? | Resolved where |
|---------------|--------|---------------|----------------|
| `INBOUND_ROUTING_MODE` | `parse_inbound_routing_mode()` | **No** | Module import in `mail-proxy-daemon.py` |
| `OUTBOUND_ROUTING_MODE` | `parse_outbound_routing_mode()` | **No** | Module import |
| `OUTBOUND_WATCH_MODE` | `parse_outbound_watch_mode()` + `resolve_effective_outbound_watch_mode()` | **No** | `main()` once at startup |

**Gap for multi-referent production:** per-referent cutover requires a future feature (e.g. DB `referents.routing_mode` or env allowlist). **Not in scope to implement here.**

### 2.2 Runbook — referent R on a single-referent host (e.g. lab VPS)

**Phase A — Readiness audit (no mode change)**

1. List relationships: `SELECT id, local_client_email, local_client_maildir FROM clients WHERE referent_id = R AND active = 1`.
2. Confirm P1–P10 for each row (SQL + filesystem + panel backfill clear).
3. Record path-collision matrix (§1.3) for each relationship on R.
4. Confirm P11: inject outbound test into each `local_client_maildir/new` with `OUTBOUND_WATCH_MODE=dual`, `OUTBOUND_ROUTING_MODE=shadow`.

**Phase B — Dual soak (observation)**

5. Set drop-in (`/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf`):

   ```ini
   [Service]
   Environment=INBOUND_ROUTING_MODE=shadow
   Environment=OUTBOUND_ROUTING_MODE=shadow
   Environment=OUTBOUND_WATCH_MODE=dual
   ```

6. `systemctl daemon-reload && systemctl restart mail-proxy`
7. Confirm startup line: `OUTBOUND_WATCH_MODE=dual`, relationship maildir paths registered.
8. Run representative inbound + outbound traffic for **each** relationship on R; collect shadow markers (§4).
9. **Soak window:** operator-defined (suggest ≥ 48–72 h production; lab may shorten). **Rollback trigger:** any DIVERGE marker not explained, or outbound from client maildir not picked up.

**Phase C — Go live (global env on this host)**

10. Update drop-in:

    ```ini
    [Service]
    Environment=INBOUND_ROUTING_MODE=relationship_live
    Environment=OUTBOUND_ROUTING_MODE=relationship_live
    Environment=OUTBOUND_WATCH_MODE=relationship_only
    ```

11. `systemctl daemon-reload && systemctl restart mail-proxy`
12. Confirm startup:

    - `INBOUND_ROUTING_MODE=relationship_live`
    - `OUTBOUND_ROUTING_MODE=relationship_live`
    - `OUTBOUND_WATCH_MODE=relationship_only` (not `requested=relationship_only` with effective `referent_only`)

13. **Verification (per relationship on R):**
    - **Inbound:** external From = `external_client_email` → local delivery to `local_referent_email` (IMAP SUBJECT search on local referent mailbox).
    - **Outbound:** inject into `local_client_maildir/new` with From = `local_client_email` → SMTP via correct `external_account_id` (IMAP proof on external client inbox).
    - **Watch:** startup shows `0 referent watches` (or referent watch absent) and relationship paths watched; no duplicate enqueue on file create.

**Phase D — Rollback triggers**

Execute §5 rollback matrix if:

- Unknown inbound sender leaves mail stuck without acceptable interim policy
- Outbound miss / wrong external account
- Watch not registered for any relationship on R
- Operator loss of confidence

Restore safe defaults: all three modes to `shadow` / `referent_only`.

### 2.3 Multi-referent host (deferred)

Do **not** execute Phase C for one referent while others remain on shadow unless **all** referents on the instance pass §1 preconditions and the operator accepts simultaneous live routing for all. Otherwise wait for per-referent mode (future PROMPT).

---

## 3. Symmetric collision scoping

**Definition:** Two (or more) ClientRelationship rows for referent R **and** referent R itself share the **same** Maildir `new/` path (i.e. `local_client_maildir/new` for multiple rows **and** `referents.local_outbox/new` all resolve to one directory).

### Is this valid iRedMail configuration?

**No — not a supported target shape.** In iRedMail each mailbox has its own hashed Maildir. Two distinct `local_client_email` addresses should map to **distinct** `vmail.mailbox.maildir` paths. Sharing one physical Maildir across two client relationships is a **data-model / provisioning error**, not a normal multi-tenant layout.

Referent `local_outbox` equalling one client's `local_client_maildir` can occur during migration (shared outbox interim); PROMPT-67 collision guard handles **one** relationship path equalling referent outbox.

The **symmetric** case (referent outbox == shared client maildir used by **multiple** relationships) implies multiple logical clients writing one mailbox — operationally invalid.

### Recommendation: **prevent at provisioning time** (not a code fix in PROMPT-69)

| Approach | Verdict |
|----------|---------|
| Handle in code (triple-shared-path dedup) | **Defer / reject** — masks provisioning errors; PROMPT-67 already covers referent↔single-relationship equality |
| **Prevent at provisioning** | **Recommended** |

**Enforcement (future PROMPT-70 scope):**

- Panel save (`handleRelationshipSave`): reject or block activate when `local_client_maildir` normalizes to the same `…/new` path as another active relationship on the same referent, or equals `referent.local_outbox/new`.
- Pre-cutover audit script (operator-run): SQL + path compare listing collisions before go-live.

---

## 4. Panel UI observability — minimum data contract

Full UI design is **out of scope** (PROMPT-69+). Before enabling `relationship_live` for referent R, an operator needs:

| Data element | Source today | DB aggregation needed? |
|--------------|--------------|------------------------|
| Effective daemon modes (`INBOUND_*`, `OUTBOUND_*`, `OUTBOUND_WATCH_*`) | Last startup lines in `/var/log/mail-proxy/mail-proxy-daemon.log` | **No** — log tail (panel `log_viewer.php` exists) |
| Per-relationship: `relationship_id`, emails, `local_client_maildir`, `external_account_id`, `active` | `clients` + joins | **No** — already in DB / panel editor |
| Per-relationship: legacy-only / missing fields | `relationshipIsLegacyOnly()`, `relationshipMissingFields()` | **No** |
| Per-relationship: maildir exists / `new/` exists | Panel precondition checks (PROMPT-56) | **No** — live filesystem check from panel PHP (already partial) |
| Per-relationship inbound shadow: last marker + timestamp | `[RELATIONSHIP_SHADOW] … relationship_id=… marker=…` log lines | **No** for MVP — parse log; optional future DB rollup |
| Per-relationship outbound shadow: last marker + accounts | `[OUTBOUND_RELATIONSHIP_SHADOW] referent_id=… relationship_id=… marker=…` | **No** for MVP — parse log |
| Process-wide shadow counters | `/run/mail-proxy/relationship_shadow_stats.json` + `[RELATIONSHIP_SHADOW_STATS]` | **No** — file is process-wide, not per-referent |
| Watch mode / registered paths per relationship | Daemon startup: `Watchdog configured for relationship N: <path>`; collision warnings | **No** — log parse; no DB registry exposed to panel |
| `[OUTBOUND_WATCH_DUAL]` event count (dual soak) | Log grep | **No** |
| Last outbound file seen per relationship | Latest log line matching relationship id + filename; or `stat` on newest file in `maildir/new` | **No** — log or filesystem |
| Path collision status (referent outbox vs client maildir) | Compare paths in DB + collision warning lines | **No** |

**MVP contract:** read-only panel page combining DB relationship rows for referent R + parsed last-N shadow/watch lines from daemon log + optional stats JSON. No new tables required for first version.

---

## 5. Rollback matrix

Drop-in file (all modes): `/etc/systemd/system/mail-proxy.service.d/inbound-routing.conf`  
After any change: `systemctl daemon-reload && systemctl restart mail-proxy`

| Variable | Safe rollback value | Drop-in line | Independent? | Code note |
|----------|---------------------|--------------|--------------|-----------|
| `INBOUND_ROUTING_MODE` | `shadow` | `Environment=INBOUND_ROUTING_MODE=shadow` | **Yes** | Inbound planning uses `INBOUND_ROUTING_MODE` only; outbound/watch unaffected |
| `OUTBOUND_ROUTING_MODE` | `shadow` | `Environment=OUTBOUND_ROUTING_MODE=shadow` | **Yes** | Outbound account selection; if `OUTBOUND_WATCH_MODE=relationship_only` while routing reverted to `shadow`, daemon **fail-closed** to `referent_only` at startup (`resolve_effective_outbound_watch_mode()`) — safe, not stuck half-live |
| `OUTBOUND_WATCH_MODE` | `referent_only` | `Environment=OUTBOUND_WATCH_MODE=referent_only` | **Yes** | Restores referent outbox watch; does not change routing mode |

### Hidden dependencies (ordering)

| Scenario | Behavior |
|----------|----------|
| `relationship_only` + `OUTBOUND_ROUTING_MODE=shadow` | Watch effective → `referent_only` (error logged). **No manual lockstep required** — safe fail-closed |
| `relationship_only` + `relationship_live` outbound | Intended live combo |
| Revert **only** inbound to `shadow` while outbound stays `relationship_live` | **Allowed** — asymmetric; inbound legacy/shadow, outbound relationship account selection |
| Revert **only** watch to `referent_only` while routing stays `relationship_live` | **Allowed** — pickup returns to referent outbox; routing still relationship-aware when files arrive |

**Recommended rollback order (operator clarity, not code requirement):**  
(1) `OUTBOUND_WATCH_MODE=referent_only` → (2) `OUTBOUND_ROUTING_MODE=shadow` → (3) `INBOUND_ROUTING_MODE=shadow`

---

## 6. Draft go/no-go checklist (for later insertion into `docs/Ckeck-list_00.md`)

Proposed new section — **not applied to `Ckeck-list_00.md` in this PROMPT**:

```markdown
## N. Relationship-live cutover (per referent readiness — global daemon actuation)

> Applies when switching one mail-proxy instance toward
> `INBOUND_ROUTING_MODE=relationship_live`,
> `OUTBOUND_ROUTING_MODE=relationship_live`,
> `OUTBOUND_WATCH_MODE=relationship_only`.
> See `docs/reports/PROMPT-68-cutover-readiness-plan.md`.

- [ ] Every active ClientRelationship for this referent passes panel completeness (not legacy-only; PROMPT-57 backfill done)
- [ ] Every relationship `local_client_maildir` and `local_client_maildir/new` exists and is owned/readable by `vmail`
- [ ] Path audit recorded: no unresolved symmetric collision (two relationships + referent on one path); referent-outbox collisions explicitly accepted if present
- [ ] Dual-watch soak (`OUTBOUND_WATCH_MODE=dual`) completed; outbound from each client maildir picked up; shadow markers reviewed
- [ ] If multiple referents on this host: operator signed off on global mode flip OR per-referent mode feature deployed
- [ ] Rollback drop-in verified (`shadow` / `shadow` / `referent_only`) on this host
- [ ] Post-flip inbound test: external client → correct `local_referent_email` per relationship
- [ ] Post-flip outbound test: each `local_client_email` → correct external account via `local_client_maildir/new`
- [ ] Daemon startup log confirms effective modes (not fail-closed watch)
```

---

## 7. Scope for PROMPT-69+

| Priority | PROMPT | Scope |
|----------|--------|-------|
| **First** | **PROMPT-69** | Panel observability MVP per §4 data contract (read-only; log + DB; no routing mode toggles from panel) |
| **Second** | **PROMPT-70** | Provisioning-time path uniqueness guard (§3) in panel save + operator audit helper |
| **Third** | **PROMPT-71** | Per-referent routing/watch mode **or** documented multi-instance split — required before multi-referent production cutover without global flip |
| Deferred | PROMPT-72+ | Actual production cutover execution on first customer referent; symmetric collision code path (only if provisioning prevention proves insufficient) |

**This PROMPT does not schedule cutover.** It produces the plan only.

---

## Acceptance

```text
PROMPT-68: COMPLETE (design document + anchor cross-reference)
Implementation: NONE (by design)
```
