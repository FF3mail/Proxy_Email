# Issue #138 — operator password change and master reset (SEC-11 / DAT-9)

## Scope

Implements DEV-24 closure items for operator self-service password change, master reset with forced change on next sign-in, and panel-wide gate when `must_change_password = 1`.

## Migration

| Artifact | Purpose |
|----------|---------|
| `migrations/008_panel_admins_must_change_password.sql` | Idempotent `ADD COLUMN IF NOT EXISTS must_change_password` on `panel_admins` |
| Installer `ensure_panel_admins_must_change_password_column()` | Applies `008` as MariaDB **root** to **`DB_NAME`** in `migrate_panel_auth()` (same pattern as `007`) |
| PHP `ensurePanelAdminsMustChangePasswordColumn()` | Runtime fallback when DB user has `ALTER`; otherwise fail-closed |

Manual upgrade (panel-only rsync). Use the database name from `/etc/mail-proxy/db.conf` (`[db]` → `db_name`); the installer applies the file to **`DB_NAME`** (default `mail_proxy`):

```bash
DB_NAME="$(python3 -c "import configparser; c=configparser.ConfigParser(); c.read('/etc/mail-proxy/db.conf'); print(c['db']['db_name'])")"
mysql -u root -p "${DB_NAME}" < migrations/008_panel_admins_must_change_password.sql
```

## Panel entry points

| Route | Gate |
|-------|------|
| `index.php` | `requirePanelAdmin()` + `enforcePanelPasswordChangeRequired()` except `password_change`, `password_change_submit`, `logout` |
| `logs.php`, `monitor.php`, `relationship-status.php` | `requirePanelAdminWithPasswordGate()` |

## Security notes

- Wrong **current** password on change form uses the same `panel_login_attempts` throttle as login (login + IP).
- Successful self-service change calls `session_regenerate_id(true)` for the current browser session only.
- **Other sessions:** existing PHP sessions that already hold a valid `admin_id` cookie are **not** invalidated when the password changes or when `must_change_password` is cleared. Those sessions remain authenticated until logout, expiry, or deactivation. Only the browser that completes the change gets a new session id. Operators who need immediate revocation should deactivate the account or wait for session expiry.

## Verification

```bash
php tests/panel_operator_password_test.php
python3 -m unittest tests/test_panel_password_page_gate.py tests/test_installer_migration_008.py
./scripts/verify-install-regression.sh   # on installed host with db.conf
```

ACC-17 manual steps: see `docs/SPEC.md` section 12.3 (`ACC-17`).
