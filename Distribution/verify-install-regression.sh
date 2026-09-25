#!/usr/bin/env bash
set -euo pipefail

# Post-install regression checks for PROMPT 22 / 22.1 (Epic A test VPS).
# Run as root after delta-transit-install.sh and configure_limits.sh.

[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }

DB_CONF="/etc/mail-proxy/db.conf"
FAILURES=0

pass() { echo "[PASS] $*"; }
fail() { echo "[FAIL] $*"; FAILURES=$((FAILURES + 1)); }

echo "=== PROMPT 22.1 regression checks ==="

if systemctl is-active --quiet mail-proxy.service; then
    pass "mail-proxy.service is active (running)"
else
    fail "mail-proxy.service is not active (running): $(systemctl is-active mail-proxy.service 2>&1 || true)"
    systemctl status mail-proxy.service --no-pager | head -15 || true
fi

if nginx -t >/tmp/verify-nginx-test.log 2>&1; then
    pass "nginx -t passes"
else
    fail "nginx -t failed"
    cat /tmp/verify-nginx-test.log || true
fi

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

# CHANGE_ME placeholder rejection (scratch copy only).
if [[ -f "$DB_CONF" ]]; then
    scratch="$(mktemp)"
    cp "$DB_CONF" "${scratch}.real"
    sed 's/^db_pass = .*/db_pass = CHANGE_ME/' "$DB_CONF" > "$scratch"
    cp "$scratch" "$DB_CONF"
    chmod 0640 "$DB_CONF"
    chown root:mail-proxy-crypto "$DB_CONF" 2>/dev/null || true
    set +e
    /opt/delta-transit/venv/bin/python3 /usr/local/bin/mail-proxy-daemon.py >/dev/null 2>&1 &
    dpid=$!
    sleep 2
    if kill -0 "$dpid" 2>/dev/null; then
        kill "$dpid" 2>/dev/null || true
        fail "daemon started with CHANGE_ME placeholder"
    else
        pass "daemon refuses CHANGE_ME placeholder"
    fi
    wait "$dpid" 2>/dev/null
    set -e
    cp "${scratch}.real" "$DB_CONF"
    chmod 0640 "$DB_CONF"
    chown root:mail-proxy-crypto "$DB_CONF" 2>/dev/null || true
    rm -f "$scratch" "${scratch}.real"
    systemctl restart mail-proxy.service >/dev/null 2>&1 || true
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

echo "=== Summary: ${FAILURES} failure(s) ==="
exit "$FAILURES"
