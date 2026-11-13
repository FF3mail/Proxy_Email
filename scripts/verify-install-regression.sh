#!/usr/bin/env bash
set -euo pipefail

# Post-install regression checks for PROMPT 22 / 22.1 (Epic A test VPS).
# Run as root after delta-transit-install.sh and configure_limits.sh.
# Reads live /etc/mail-proxy/db.conf only; CHANGE_ME probe uses a private temp copy.

[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }

DB_CONF="/etc/mail-proxy/db.conf"
FAILURES=0

pass() { echo "[PASS] $*"; }
fail() { echo "[FAIL] $*"; FAILURES=$((FAILURES + 1)); }

# shellcheck source=php_fpm_intl_verify.sh disable=SC1091
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/php_fpm_intl_verify.sh"

_resolve_mail_proxy_daemon_py() {
    local candidates=(
        "${MAIL_PROXY_DAEMON_PY:-}"
        "/usr/local/bin/mail-proxy-daemon.py"
        "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/mail-proxy-daemon.py"
    )
    local c
    for c in "${candidates[@]}"; do
        [[ -n "$c" && -f "$c" ]] || continue
        echo "$c"
        return 0
    done
    return 1
}

echo "=== PROMPT 22.1 regression checks ==="

if php -m 2>/dev/null | grep -qx intl; then
    pass "PHP CLI intl extension loaded"
else
    fail "PHP CLI intl extension missing (install php-intl)"
fi

if php_fpm_intl_detect_panel_environment; then
    if php_fpm_intl_wait_fpm_binary_ready; then
        if php_fpm_intl_fpm_sapi_has_intl; then
            pass "PHP FPM SAPI intl extension loaded (php-fpm${PHP_FPM_VERSION} -m)"
        else
            fail "PHP FPM SAPI intl extension missing — install php-intl for PHP-FPM ${PHP_FPM_VERSION} (php-fpm binary -m, not CLI php -m)"
        fi
    else
        fail "PHP-FPM binary php-fpm${PHP_FPM_VERSION} not detectable within ${PHP_FPM_INTL_READY_MAX_WAIT}s (upgrade/restart in progress — not an intl check failure)"
    fi
else
    fail "PHP-FPM panel pool not detected — cannot verify FPM SAPI intl (expected /etc/php/*/fpm/php.ini and pool.d/www.conf)"
fi

if systemctl is-active --quiet mail-proxy.service; then
    pass "mail-proxy.service is active (running)"
else
    fail "mail-proxy.service is not active (running): $(systemctl is-active mail-proxy.service 2>&1 || true)"
    systemctl status mail-proxy.service --no-pager | head -15 || true
fi

NGINX_TEST_LOG="$(mktemp)"
_nginx_test_log_cleanup() {
    [[ -n "${NGINX_TEST_LOG:-}" && -f "$NGINX_TEST_LOG" ]] && rm -f "$NGINX_TEST_LOG"
}
trap '_nginx_test_log_cleanup' EXIT

if nginx -t >"$NGINX_TEST_LOG" 2>&1; then
    pass "nginx -t passes"
else
    fail "nginx -t failed"
    cat "$NGINX_TEST_LOG" || true
fi
_nginx_test_log_cleanup
trap - EXIT

if systemctl is-active --quiet nginx; then
    pass "nginx service is active"
else
    fail "nginx service is not active"
fi

python3 - "$DB_CONF" <<'PY'
import configparser
import re
import sys

path = sys.argv[1]
text = open(path, encoding="utf-8").read()
if re.search(r'^db_pass\s*=\s*".*"$', text, re.M):
    raise SystemExit("db_pass is still wrapped in literal double quotes")

cfg = configparser.ConfigParser()
cfg.read(path)
password = cfg.get("db", "db_pass")
if not password:
    raise SystemExit("empty db_pass")

probe = configparser.ConfigParser()
probe["db"] = {"db_pass": "p#ass=word with spaces"}
import tempfile, os
fd, tmp = tempfile.mkstemp()
os.close(fd)
with open(tmp, "w", encoding="utf-8") as f:
    probe.write(f)
read_back = configparser.ConfigParser()
read_back.read(tmp)
os.remove(tmp)
if read_back.get("db", "db_pass") != "p#ass=word with spaces":
    raise SystemExit("special-character round-trip failed")
PY
if [[ $? -eq 0 ]]; then
    pass "db.conf round-trip / quoting check"
else
    fail "db.conf round-trip / quoting check"
fi

if python3 - "$DB_CONF" <<'PY' >/dev/null
import configparser
import sys
cfg = configparser.ConfigParser()
cfg.read(sys.argv[1])
cfg.get("db", "db_pass")
PY
then
    pass "db.conf is readable via configparser"
else
    fail "db.conf configparser read failed"
fi

# CHANGE_ME placeholder rejection (scratch db.conf only; live db.conf is never written).
if [[ -f "$DB_CONF" ]]; then
    umask 077
    HARN_DIR="$(mktemp -d)"
    _harness_cleanup() {
        [[ -n "${HARN_DIR:-}" && -d "$HARN_DIR" ]] && rm -rf "$HARN_DIR"
    }
    trap '_harness_cleanup' EXIT INT TERM
    scratch="${HARN_DIR}/db_change_me.conf"
    cp "$DB_CONF" "${HARN_DIR}/db_good.conf"
    sed 's/^db_pass = .*/db_pass = CHANGE_ME/' "${HARN_DIR}/db_good.conf" > "$scratch"
    chmod 0640 "$scratch"

    if ! daemon_py="$(_resolve_mail_proxy_daemon_py)"; then
        fail "mail-proxy-daemon.py not found for CHANGE_ME probe"
    else
        py_bin="${MAIL_PROXY_VENV_PYTHON:-/opt/delta-transit/venv/bin/python3}"
        if [[ ! -x "$py_bin" ]]; then
            py_bin="$(command -v python3 || true)"
        fi
        if [[ -z "$py_bin" ]]; then
            fail "python3 not found for CHANGE_ME probe"
        else
            set +e
            "$py_bin" - "$scratch" "$daemon_py" <<'PY'
import importlib.util
import sys

conf_path, daemon_py = sys.argv[1], sys.argv[2]
spec = importlib.util.spec_from_file_location("mail_proxy_daemon", daemon_py)
if spec is None or spec.loader is None:
    sys.exit(2)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
try:
    mod.Database(conf_file=conf_path)
except ValueError:
    sys.exit(0)
sys.exit(1)
PY
            probe_rc=$?
            set -e
            if [[ $probe_rc -eq 0 ]]; then
                pass "daemon refuses CHANGE_ME placeholder"
            elif [[ $probe_rc -eq 2 ]]; then
                fail "CHANGE_ME probe could not load mail-proxy-daemon.py"
            else
                fail "daemon did not refuse CHANGE_ME placeholder"
            fi
        fi
    fi
    trap - EXIT INT TERM
    _harness_cleanup
    unset HARN_DIR
fi

if grep -rq "client_max_body_size[[:space:]]\+${NGINX_BODY_LIMIT:-210M}" /etc/nginx/conf-enabled /etc/nginx/conf.d /etc/nginx/sites-available 2>/dev/null \
    || grep -q "client_max_body_size 210M" /etc/nginx/sites-available/mail-proxy.conf 2>/dev/null; then
    pass "client_max_body_size 210M present in nginx config"
else
    fail "client_max_body_size 210M not found in expected nginx locations"
fi

# PROMPT-34 (D1): /var/vmail must be owned by Dovecot mail user before hardening.
if [[ -d /var/vmail ]]; then
    _vmail_expected=""
    _vmail_actual="$(stat -c '%U:%G' /var/vmail)"
    if command -v doveconf >/dev/null 2>&1; then
        _uid="$(doveconf -h mail_uid 2>/dev/null || true)"
        _gid="$(doveconf -h mail_gid 2>/dev/null || true)"
        if [[ -n "$_uid" ]]; then
            if [[ "$_uid" =~ ^[0-9]+$ ]]; then
                _u="$(id -nu "$_uid" 2>/dev/null || echo "$_uid")"
            else
                _u="$_uid"
            fi
        else
            _u="vmail"
        fi
        if [[ -n "$_gid" ]]; then
            if [[ "$_gid" =~ ^[0-9]+$ ]]; then
                _g="$(id -ng "$_gid" 2>/dev/null || echo "$_gid")"
            else
                _g="$_gid"
            fi
        else
            _g="vmail"
        fi
        _vmail_expected="${_u}:${_g}"
    else
        _vmail_expected="vmail:vmail"
    fi
    if [[ "$_vmail_actual" == "$_vmail_expected" ]]; then
        pass "/var/vmail ownership ${_vmail_actual} matches Dovecot mail user (${_vmail_expected})"
    else
        fail "/var/vmail ownership ${_vmail_actual} does not match Dovecot mail user (${_vmail_expected})"
    fi
    if runuser -u "${_u:-vmail}" -- test -x /var/vmail 2>/dev/null; then
        pass "mail user can traverse /var/vmail"
    else
        fail "mail user (${_u:-vmail}) cannot traverse /var/vmail"
    fi
fi

if [[ -f "$DB_CONF" ]]; then
    if python3 - "$DB_CONF" <<'PY'
import configparser
import os
import re
import subprocess
import sys
import tempfile

cfg = configparser.ConfigParser()
cfg.read(sys.argv[1])
user = cfg.get("db", "db_user")
password = cfg.get("db", "db_pass")
name = cfg.get("db", "db_name")
host = cfg.get("db", "db_host", fallback="127.0.0.1")
port = cfg.get("db", "db_port", fallback="3306")


def _cnf_value(value: str) -> str:
    escaped = value.replace("\\", "\\\\").replace('"', '\\"')
    return f'"{escaped}"'


fd, cnf_path = tempfile.mkstemp(prefix="verify-mysql-", suffix=".cnf")
os.close(fd)
os.chmod(cnf_path, 0o600)
try:
    with open(cnf_path, "w", encoding="utf-8") as f:
        f.write("[client]\n")
        f.write(f"user={_cnf_value(user)}\n")
        f.write(f"password={_cnf_value(password)}\n")
        f.write(f"host={_cnf_value(host)}\n")
        f.write(f"port={_cnf_value(port)}\n")
    cmd = [
        "mysql",
        f"--defaults-file={cnf_path}",
        name,
        "-N",
        "-e",
        "SHOW TABLES LIKE 'panel_login_attempts';",
    ]
    try:
        out = subprocess.run(
            cmd,
            check=False,
            capture_output=True,
            text=True,
            timeout=15,
        )
    except FileNotFoundError:
        raise SystemExit("mysql client missing")
    if out.returncode != 0:
        raise SystemExit(out.stderr.strip() or "mysql failed")
    if not out.stdout.strip():
        raise SystemExit("panel_login_attempts table missing")
finally:
    os.remove(cnf_path)
PY
    then
        pass "panel_login_attempts table present (DAT-18 / SEC-10 lockout)"
    else
        fail "panel_login_attempts table missing — apply migrations/007 or re-run installer Database phase"
    fi

    if python3 - "$DB_CONF" <<'PY'
import configparser
import os
import re
import subprocess
import sys
import tempfile

cfg = configparser.ConfigParser()
cfg.read(sys.argv[1])
user = cfg.get("db", "db_user")
password = cfg.get("db", "db_pass")
name = cfg.get("db", "db_name")
host = cfg.get("db", "db_host", fallback="127.0.0.1")
port = cfg.get("db", "db_port", fallback="3306")


def _cnf_value(value: str) -> str:
    escaped = value.replace("\\", "\\\\").replace('"', '\\"')
    return f'"{escaped}"'


fd, cnf_path = tempfile.mkstemp(prefix="verify-mysql-", suffix=".cnf")
os.close(fd)
os.chmod(cnf_path, 0o600)
try:
    with open(cnf_path, "w", encoding="utf-8") as f:
        f.write("[client]\n")
        f.write(f"user={_cnf_value(user)}\n")
        f.write(f"password={_cnf_value(password)}\n")
        f.write(f"host={_cnf_value(host)}\n")
        f.write(f"port={_cnf_value(port)}\n")
    cmd = [
        "mysql",
        f"--defaults-file={cnf_path}",
        name,
        "-N",
        "-e",
        "SHOW COLUMNS FROM panel_admins LIKE 'must_change_password';",
    ]
    try:
        out = subprocess.run(
            cmd,
            check=False,
            capture_output=True,
            text=True,
            timeout=15,
        )
    except FileNotFoundError:
        raise SystemExit("mysql client missing")
    if out.returncode != 0:
        raise SystemExit(out.stderr.strip() or "mysql failed")
    if not out.stdout.strip():
        raise SystemExit("panel_admins.must_change_password column missing")
finally:
    os.remove(cnf_path)
PY
    then
        pass "panel_admins.must_change_password column present (DAT-9 / SEC-11)"
    else
        fail "panel_admins.must_change_password missing — apply migrations/008 or re-run installer Database phase"
    fi

    if python3 - "$DB_CONF" <<'PY'
import configparser
import os
import subprocess
import sys
import tempfile

cfg = configparser.ConfigParser()
cfg.read(sys.argv[1])
user = cfg.get("db", "db_user")
password = cfg.get("db", "db_pass")
name = cfg.get("db", "db_name")
host = cfg.get("db", "db_host", fallback="127.0.0.1")
port = cfg.get("db", "db_port", fallback="3306")


def _cnf_value(value: str) -> str:
    escaped = value.replace("\\", "\\\\").replace('"', '\\"')
    return f'"{escaped}"'


fd, cnf_path = tempfile.mkstemp(prefix="verify-mysql-", suffix=".cnf")
os.close(fd)
os.chmod(cnf_path, 0o600)
try:
    with open(cnf_path, "w", encoding="utf-8") as f:
        f.write("[client]\n")
        f.write(f"user={_cnf_value(user)}\n")
        f.write(f"password={_cnf_value(password)}\n")
        f.write(f"host={_cnf_value(host)}\n")
        f.write(f"port={_cnf_value(port)}\n")
    sql = """
        SELECT rc.DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS rc
        INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
            ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            AND kcu.TABLE_NAME = rc.TABLE_NAME
        WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
          AND rc.TABLE_NAME = 'clients'
          AND rc.REFERENCED_TABLE_NAME = 'referents'
          AND kcu.COLUMN_NAME = 'referent_id'
        LIMIT 1;
    """
    cmd = [
        "mysql",
        f"--defaults-file={cnf_path}",
        name,
        "-N",
        "-e",
        sql,
    ]
    try:
        out = subprocess.run(
            cmd,
            check=False,
            capture_output=True,
            text=True,
            timeout=15,
        )
    except FileNotFoundError:
        raise SystemExit("mysql client missing")
    if out.returncode != 0:
        raise SystemExit(out.stderr.strip() or "mysql failed")
    rule = (out.stdout or "").strip()
    if rule != "RESTRICT":
        raise SystemExit(f"clients.referent_id delete rule is {rule!r}, expected RESTRICT")
finally:
    os.remove(cnf_path)
PY
    then
        pass "clients.referent_id FK ON DELETE RESTRICT (DAT-11)"
    else
        fail "clients.referent_id FK not RESTRICT — apply migrations/009 or re-run installer Database phase"
    fi

    if python3 - "$DB_CONF" <<'PY'
import configparser
import os
import subprocess
import sys
import tempfile

cfg = configparser.ConfigParser()
cfg.read(sys.argv[1])
user = cfg.get("db", "db_user")
password = cfg.get("db", "db_pass")
name = cfg.get("db", "db_name")
host = cfg.get("db", "db_host", fallback="127.0.0.1")
port = cfg.get("db", "db_port", fallback="3306")


def _cnf_value(value: str) -> str:
    escaped = value.replace("\\", "\\\\").replace('"', '\\"')
    return f'"{escaped}"'


fd, cnf_path = tempfile.mkstemp(prefix="verify-mysql-", suffix=".cnf")
os.close(fd)
os.chmod(cnf_path, 0o600)
try:
    with open(cnf_path, "w", encoding="utf-8") as f:
        f.write("[client]\n")
        f.write(f"user={_cnf_value(user)}\n")
        f.write(f"password={_cnf_value(password)}\n")
        f.write(f"host={_cnf_value(host)}\n")
        f.write(f"port={_cnf_value(port)}\n")
    cmd = [
        "mysql",
        f"--defaults-file={cnf_path}",
        name,
        "-N",
        "-e",
        "SHOW COLUMNS FROM clients LIKE 'display_name';",
    ]
    try:
        out = subprocess.run(
            cmd,
            check=False,
            capture_output=True,
            text=True,
            timeout=15,
        )
    except FileNotFoundError:
        raise SystemExit("mysql client missing")
    if out.returncode != 0:
        raise SystemExit(out.stderr.strip() or "mysql failed")
    if not out.stdout.strip():
        raise SystemExit("clients.display_name column missing")
finally:
    os.remove(cnf_path)
PY
    then
        pass "clients.display_name column present (DAT-24)"
    else
        fail "clients.display_name missing — apply migrations/010 or re-run installer Database phase"
    fi
fi

echo "=== Summary: ${FAILURES} failure(s) ==="
exit "$FAILURES"
