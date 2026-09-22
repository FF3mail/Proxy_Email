# PROMPT-79.1 — Legacy shadow removal (closure)

**Date:** 2026-09-22  
**Branch:** `prompt-79-1-legacy-shadow-removal`  
**Inventory:** [PROMPT-79-0-inventory.md](PROMPT-79-0-inventory.md)

## Summary

Removed shadow/legacy/dual routing paths, deleted `relationship_shadow.py`, simplified `relationship_routing.py` to live-only, refactored daemon to relationship-only watches without per-referent override cache. Panel referent mode UI and shadow observability replaced with PROMPT-79.2 stub. Production routing defaults ship via `mail-proxy.service.d/routing.conf`.

## Tests

| Phase | Command | Result |
|-------|---------|--------|
| **Before** (mid-79.1, broken imports) | `py -3 -m unittest discover -s tests -p "test_*.py"` | **46** run, **6** errors, **11** skipped |
| **After** | same | **67** run, **0** failures, **11** skipped |

Removed suites: `test_relationship_shadow`, `test_referent_mode_overrides`.  
PHP panel tests removed: `panel_referent_modes_test.php`, `panel_relationship_status_test.php`.  
`scale_log_coverage_bench.php` exits 0 with skip message (PROMPT-79.2).

## VPS (lab `192.168.125.116`)

| Step | Result |
|------|--------|
| SSH via `.keys/Test_vps openSSH` | **OK** |
| Installed `/etc/systemd/system/mail-proxy.service.d/routing.conf` | **OK** |
| `systemctl daemon-reload && restart mail-proxy` | **active** |
| Log evidence | `INBOUND_ROUTING_MODE=relationship_live OUTBOUND_ROUTING_MODE=relationship_live OUTBOUND_WATCH_MODE=relationship_only` |

**Note:** VPS daemon binary not fully redeployed in this prompt — only routing drop-in. Full code parity deploy is a separate step if shadow log markers must disappear entirely.

## Verdict

**ACCEPTED** — local test suite green; lab VPS routing drop-in applied with expected startup log lines.

## Out of scope (deferred)

- `schema.sql` / `migrations/003` — unchanged  
- PROMPT-79.2 live observability UI  
- PROMPT-79.4 DB column cleanup  
