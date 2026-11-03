# Tests layout and environment coupling

## Python (`test_*.py`)

From the repository root:

```bash
python3 -m unittest discover -s tests -p "test_*.py"
```

CI and local runs expect:

- Python packages from `requirements.txt` (including `mysql-connector-python`).
- Writable directories `/var/spool/mail-proxy/tmp` and `/var/log/mail-proxy` (daemon import and spool tests).

### Skipped without a test database

`tests/test_relationship_lookup.py` — set `RELATIONSHIP_TEST_SKIP=1` or provide MySQL via `RELATIONSHIP_TEST_DB_*` (11 cases skipped when no DB).

### Errors without spool/log directories

Tests that import `mail-proxy-daemon.py` need `/var/log/mail-proxy` and `/var/spool/mail-proxy` to exist and be writable by the test user.

## PHP panel harness (`panel_*_test.php`, helpers)

Default web root is `web/` in the repository (`tests/panel_bootstrap.php` / `PANEL_WEB_ROOT` override). Override for a deployed panel:

```bash
export PANEL_WEB_ROOT=/var/www/mail-proxy
```

### Portable from a clean clone (CI job `php-tests`, blocking)

The exact file list is in `.github/workflows/ci.yml` (19 `tests/panel_*_test.php` files, including `panel_login_get_lock_test.php` and `panel_login_lock_name_test.php`). They run in CI with `php-cli`, `php-sqlite3`, `php-intl`, and `php-mbstring`.

Examples:

### Requires live MySQL / installed panel

Examples: `panel_auth_role_test.php`, `panel_list_render_test.php`, `panel_toggle_smoke_test.php`, `prompt59_panel_cli_save.php`, `prompt83_lab_smoke.php` — need `getPdo()` against a configured panel database and often a deployed `config.php`.

### VPS / lab only

`prompt67_vps_verify.sh`, `scale_verification_harness.sh`, `scale_collision_bench.php`, `prompt83_lab_smoke.php` — not run in CI.
