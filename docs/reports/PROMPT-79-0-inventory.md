# PROMPT-79.0 — Legacy shadow / routing inventory

**Date:** 2026-09-22  
**Branch:** `prompt-79-1-legacy-shadow-removal`

## Purpose

Inventory of shadow-era artifacts before PROMPT-79.1 removal (live-only relationship routing).

## Python daemon

| Artifact | Role pre-79.1 | Action (79.1) |
|----------|---------------|---------------|
| `relationship_shadow.py` | Inbound/outbound shadow classify, stats JSON, legacy rcpts helpers | **Delete** |
| `relationship_routing.py` | `legacy` / `shadow` / `relationship_live` modes, per-referent effective modes | **Rewrite** live-only |
| `mail-proxy-daemon.py` | Shadow eval hooks, referent watches, override cache, dual watch | **Refactor** relationship-only |
| `relationship_lookup.py` | Unchanged | Keep |

## Systemd / deploy

| Artifact | Notes |
|----------|-------|
| Ad-hoc `inbound-routing.conf` on VPS | Replaced by repo `mail-proxy.service.d/routing.conf` |
| `mail-proxy-setup.sh` / `delta-transit-install.sh` | Install drop-in + `daemon-reload` |

## Web panel

| Artifact | Action (79.1) |
|----------|---------------|
| `web/includes/referent_modes.php` | **Delete** |
| `web/index.php` referent mode UI + UPDATE columns | **Remove** (username, maildirs, active only) |
| `web/includes/relationship_status.php` | Shadow log parse + stats file | **Stub** → PROMPT-79.2 |
| `web/relationship-status.php` | Full observability UI | **Stub** (nav retained) |

## Tests removed or rewritten

| File | Action |
|------|--------|
| `tests/test_relationship_shadow.py` | Delete |
| `tests/test_referent_mode_overrides.py` | Delete |
| `tests/panel_referent_modes_test.php` | Delete |
| `tests/panel_relationship_status_test.php` | Delete |
| `tests/test_relationship_routing.py` | Rewrite live-only |
| `tests/test_outbound_watch.py` | Rewrite relationship_only |
| `tests/test_message_rebuild.py` | Remove F12 shadow skip test |
| `tests/test_imap_fetch_seen.py` | `plan_outbound_delivery` API |
| `tests/scale_log_coverage_bench.php` | Skip stub (79.2) |
| `tests/prompt67_vps_verify.sh` | Note + drop `relationship_shadow` deploy |

## DB schema (out of scope 79.1)

`referents.inbound_routing_mode`, `outbound_routing_mode`, `outbound_watch_mode` — **retained** until PROMPT-79.4.

## Runtime files (no longer written)

- `/run/mail-proxy/relationship_shadow_stats.json`
- Log markers `[RELATIONSHIP_SHADOW]`, `[OUTBOUND_RELATIONSHIP_SHADOW]`, `[OUTBOUND_WATCH_DUAL]`

## Target production modes

```
INBOUND_ROUTING_MODE=relationship_live
OUTBOUND_ROUTING_MODE=relationship_live
OUTBOUND_WATCH_MODE=relationship_only
```
