# PROMPT-40 — fix validate_panel_auth password in validation-only mode

**Date:** 2026-09-08  
**Branch:** `prompt-40-validation-only-dbpass` (from `master`, independent of PR #4/#5/#6)  
**Scope:** `delta-transit-install.sh` only

---

## Root cause (confirmed)

`DELTA_VALIDATION_ONLY=1 ./delta-transit-install.sh` jumps straight to `phase_validation()` and skips Preflight/Database. `PARAM_DB_PASS` is initialized to `""` at script start and is only set during a **fresh** install’s Database phase (`generate_db_password()`).

`phase_validation()` calls `verify_database_login()` then `validate_panel_auth()`:

| Step | Function | Password source | Result on existing host |
|------|----------|-----------------|-------------------------|
| 1 | `validate_db_connectivity()` | `read_db_pass_from_conf()` | **OK** |
| 2 | `ensure_panel_admins_table()` | global `PARAM_DB_PASS` (empty) | **FAIL** — bare `-p` → interactive `Enter password:` → 1045 Access denied |

With empty `PARAM_DB_PASS`, `mysql -p"${PARAM_DB_PASS}"` becomes bare `-p`, so the CLI prompts instead of using `/etc/mail-proxy/db.conf`.

---

## Fix

Added `resolve_db_pass()` next to `read_db_pass_from_conf()`:

- If `PARAM_DB_PASS` is non-empty → use it (fresh Database phase / seed path, unchanged).
- Else → `read_db_pass_from_conf()` (validation-only, upgrades, preflight `try_detect_active_panel_master`).

Updated all panel-auth `mysql` callers that used `PARAM_DB_PASS` directly:

- `ensure_panel_admins_table()`
- `panel_master_exists()`
- `panel_active_master_exists()`
- `seed_panel_master()` (same pattern; still uses `PARAM_DB_PASS` when set during new install)
- `try_detect_active_panel_master()` — simplified to call `resolve_db_pass()`

**Not changed** (fresh-install-only, `PARAM_DB_PASS` always set when called):

- `generate_db_password()`, `create_database_user()`, `schema_already_installed()`, `write_db_config()`

**Reference left untouched:** `audit_database_contents()` (already uses `read_db_pass_from_conf()`).

### Diff summary

```diff
+resolve_db_pass() {
+    if [[ -n "${PARAM_DB_PASS}" ]]; then
+        printf '%s\n' "${PARAM_DB_PASS}"
+        return 0
+    fi
+    read_db_pass_from_conf
+}

 ensure_panel_admins_table() {
+    local db_pass
+    db_pass="$(resolve_db_pass)"
     mysql ... -p"${db_pass}" ...
 }
 # (same pattern for panel_master_exists, panel_active_master_exists, seed_panel_master)
```

---

## Verification

### Host

`192.168.125.116` (`mail.testvps.loc`) — fully installed Epic A lab VPS with existing `/etc/mail-proxy/db.conf` and active `panel_admins` master.

### BEFORE (master script)

```text
$ DELTA_VALIDATION_ONLY=1 ./delta-transit-install-before.sh </dev/null

[INFO] Phase: Validation
[INFO] Validating panel authentication (upgrade safety)
[INFO] Ensuring panel_admins table exists
Enter password: ERROR 1045 (28000): Access denied for user 'mail_proxy'@'localhost' (using password: YES)
[ERROR] Unhandled error at line 413 (exit code 1)
```

### AFTER (fixed script)

```text
$ DELTA_VALIDATION_ONLY=1 ./delta-transit-install-after.sh </dev/null

[INFO] Phase: Validation
[INFO] Validating panel authentication (upgrade safety)
[INFO] Ensuring panel_admins table exists
[OK] Panel auth: active master present
```

No password prompt; `validate_panel_auth()` completed successfully. (Validation later failed on an unrelated `server_name mismatch` on this host — outside this prompt’s scope.)

### Fresh-install path (code review)

During a full install, `generate_db_password()` sets `PARAM_DB_PASS` **before** `migrate_panel_auth()` → `ensure_panel_admins_table()` / `seed_panel_master()`. `resolve_db_pass()` returns `PARAM_DB_PASS` first, so behavior is identical to the previous direct global use. No regression expected.

### Syntax

```bash
bash -n delta-transit-install.sh   # PASS
```

---

## PR

Independent PR into `master`: **PROMPT-40: fix validate_panel_auth password resolution in validation-only mode** (`prompt-40-validation-only-dbpass`).
