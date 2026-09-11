# PROMPT-69 — Panel observability MVP for relationship routing status

**Branch:** `prompt-69-panel-observability-mvp` (from `origin/master` @ `ae6dc9d`)  
**Scope:** Read-only panel page; no daemon routing changes; no new DB tables.

---

## 1. Data-source confirmation

### `relationship_shadow_stats.json` (actual schema)

Written by `relationship_shadow.write_shadow_stats_file()` (`relationship_shadow.py`):

| Field | Type | Meaning |
|-------|------|---------|
| `processed` | int | Total shadow evaluations |
| `agree` | int | AGREE markers |
| `diverge_legacy_delivered_no_match` | int | Legacy delivered, no relationship match |
| `diverge_relationship_match_legacy_no_deliver` | int | Relationship match, legacy did not deliver |
| `errors` | int | Shadow evaluation exceptions |

**Match vs PROMPT-68 assumption:** **Partial match.** PROMPT-68 correctly assumed this file holds **process-wide** inbound shadow counters only. It does **not** contain per-relationship rows, timestamps, outbound shadow markers, watch paths, or collision flags.

**Gap (explicit):** Per-relationship last inbound/outbound shadow marker + timestamp **cannot** come from this file. MVP sources them from the **daemon log tail** (`[RELATIONSHIP_SHADOW]` / `[OUTBOUND_RELATIONSHIP_SHADOW]`).

**Authoritative source in practice:**

| Data | Authoritative source | Notes |
|------|---------------------|-------|
| Effective `INBOUND_*` / `OUTBOUND_*` / `OUTBOUND_WATCH_*` | Last matching startup line in daemon log | Verbatim display; no PHP re-parse of mode logic |
| Per-relationship inbound shadow | Daemon log | `lookup=matched relationship_id=N marker=…` |
| Per-relationship outbound shadow | Daemon log | `relationship_id=N marker=…` |
| Process-wide counters | `relationship_shadow_stats.json` | Updated on each shadow eval; resets on restart |
| Collision warnings | Daemon log + DB path compare | Log: `OUTBOUND_WATCH: relationship … equals referent …`; DB: string equality of maildir/new paths |
| Dual divergence | Daemon log | `[OUTBOUND_WATCH_DUAL]` mapped to relationship via `Watchdog configured for relationship` path |
| Last file seen | Daemon log | `Watchdog: new email file for relationship N …` |

**No new DB migration required** for this PROMPT.

---

## 2. New page diff summary

| File | Change |
|------|--------|
| `web/relationship-status.php` | New read-only page (`requirePanelAdmin`, same auth chain as `logs.php` / `monitor.php`) |
| `web/includes/relationship_status.php` | Log tail (2000 lines default, 500–5000), stats JSON read, parsers, `buildRelationshipStatusPageData()` |
| `web/index.php`, `web/monitor.php`, `web/logs.php` | Nav link |
| `web/lang/en.php`, `web/lang/ru.php` | i18n strings |
| `tests/panel_relationship_status_test.php` | Static parser unit checks |

### Per referent / per relationship columns

- **Validity:** `relationshipStatusLabel()` / `relationshipIsLegacyOnly()` from `relationship_editor.php` (no duplicate SQL predicate).
- **Inbound / outbound shadow:** last marker + timestamp from log tail (or `—` if absent).
- **Watch path:** from log `Watchdog configured for relationship` or DB `local_client_maildir/new` fallback.
- **Last file:** from relationship watchdog log line.
- **Warnings:** log collision, DB path collision, dual-watch event count.

### Log tail scope

- **File:** `/var/log/mail-proxy/mail-proxy-daemon.log` (existing `PANEL_ALLOWED_LOGS['daemon']` allowlist).
- **Default tail:** 2000 lines (configurable 500–5000 via `?lines=`). Chronological parse; last match wins per relationship.

### Rendered excerpt (structure)

```html
<h1>Relationship routing status</h1>
<div class="modes-line">… INBOUND_ROUTING_MODE=shadow OUTBOUND_ROUTING_MODE=shadow OUTBOUND_WATCH_MODE=dual …</div>
<div class="stats-grid">processed / agree / diverge_* / errors</div>
<table> <!-- per referent -->
  <tr data-relationship-id="1">
    <td>#1 client@example</td>
    <td>valid / legacy-only / inactive</td>
    <td>AGREE 2026-09-11 06:40:05</td>
    <td>AGREE 2026-09-11 06:40:06</td>
    <td>/var/vmail/.../Maildir/new</td>
    <td>test.eml</td>
    <td>collision / dual warnings</td>
  </tr>
</table>
```

Screenshots: not captured on dev host (no PHP runtime); verify on VPS after deploy.

---

## 3. Daemon-side / allowlist changes

| Change | Justified? |
|--------|------------|
| **New allowlisted log path** | **No** — reuses existing `daemon` log via `log_viewer.php`. |
| **`mail-proxy-daemon.py` changes** | **None** — routing/watch logic untouched. |
| **New scoped runtime file read** | **Yes — deliberate addition:** `PANEL_SHADOW_STATS_FILE = '/run/mail-proxy/relationship_shadow_stats.json'` in `relationship_status.php`. Same directory as existing PID read (`monitor.php` → `/run/mail-proxy/mail-proxy.pid`). File is `0644` per daemon writer. **Deploy note:** ensure `www-data` can read `/run/mail-proxy/` (typically world-readable `0755` runtime dir). If unreadable, page shows stats unavailable; per-relationship data still works from log. |

No expansion to Maildir/vmail paths. No arbitrary log path reads.

---

## 4. Explicitly out of scope (follow-ups)

| Item | Follow-up |
|------|-----------|
| Control actions (mode toggle, restart) | Not on this page — read-only observability |
| Historical trends / graphs | Current-state snapshot only |
| Symmetric collision fix | **PROMPT-70** |
| Per-referent mode selection | **PROMPT-71** |
| Production cutover execution | **PROMPT-72+** per PROMPT-68 |
| Per-relationship shadow rollup DB table | Optional future; MVP = log parse |
| Outbound shadow stats file (separate from inbound counters) | Not written today; outbound markers log-only |

---

## Verification

```bash
php tests/panel_relationship_status_test.php
```

Manual: log in as panel admin → `/relationship-status.php` → confirm startup line, stats pills, relationship rows, collision badges on lab VPS with dual mode.
