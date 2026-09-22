# PROMPT-79.1 — Legacy shadow removal (closure)

**Date:** 2026-09-22  
**Branch:** `prompt-79-1-legacy-shadow-removal`  
**Inventory:** [PROMPT-79-0-inventory.md](PROMPT-79-0-inventory.md)

## Summary

Removed shadow/legacy/dual routing paths, deleted `relationship_shadow.py`, simplified `relationship_routing.py` to live-only, refactored daemon to relationship-only watches without per-referent override cache. Panel referent mode UI and shadow observability replaced with PROMPT-79.2 stub. Production routing defaults ship via `mail-proxy.service.d/routing.conf`.

## Diff scope (merged PR #28)

**Final file count:** **29** paths changed between pre-PR master `a313c53` and merge commit `1ddbf7d` (`git diff --name-only a313c53 1ddbf7d`).

An earlier in-progress figure of **26** files came from a mid-branch `master...branch` stat before **`6ed0dca` (79.1a)** added the routing drop-in, inventory report, and installer hooks, and before **`5148c50`** test fixes were on the branch tip — not from a different functional scope.

`delta-transit-install.sh`, `docs/DELTA-transit_anchor.md`, `docs/guide/05-web-panel.md`, `docs/reports/PROMPT-79-0-inventory.md`, `docs/reports/PROMPT-79-1-legacy-shadow-removal.md`, `mail-proxy-daemon.py`, `mail-proxy-setup.sh`, `mail-proxy.service.d/routing.conf`, `relationship_routing.py`, `relationship_shadow.py` (deleted), `tests/panel_legacy_backfill_test.php`, `tests/panel_legacy_backfill_test.py`, `tests/panel_referent_modes_test.php` (deleted), `tests/panel_relationship_routing_test.php`, `tests/panel_relationship_status_test.php` (deleted), `tests/prompt67_vps_verify.sh`, `tests/scale_log_coverage_bench.php`, `tests/test_imap_fetch_seen.py`, `tests/test_message_rebuild.py`, `tests/test_outbound_watch.py`, `tests/test_referent_mode_overrides.py` (deleted), `tests/test_relationship_routing.py`, `tests/test_relationship_shadow.py` (deleted), `web/includes/referent_modes.php` (deleted), `web/includes/relationship_status.php`, `web/index.php`, `web/lang/en.php`, `web/lang/ru.php`, `web/relationship-status.php`.

## Anchor version (post-merge note)

Header/footer **v4.0** already landed in commit **`9c3f8c0`** together with **§20** (pre-PR master was **v3.9** at `a313c53`). No additional semver bump required for §20 alone; merge-hygiene “still v4.0” referred to the footer matching the header after §20 was added, not a missed increment.

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
