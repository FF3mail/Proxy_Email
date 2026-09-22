#!/usr/bin/env bash
# PROMPT-67 VPS live verification (no credentials; run as root on lab VPS).
#
# NOTE (PROMPT-79.1): shadow/dual/referent_only mode matrix tests below are
# historical. Post-79.1 production defaults live in routing.conf
# (relationship_live / relationship_only). Update this script in a follow-up
# live-test prompt if re-running end-to-end evidence on the lab VPS.
set -euo pipefail

LOG="/tmp/prompt67_live_test.log"
: >"$LOG"
TOKEN_BASE="PROMPT67-$(date +%s)"

log() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $*" | tee -a "$LOG"; }

CLIENTLOC1_NEW="/var/vmail/vmail1/testvps.loc/c/l/i/clientloc1-2026.09.01.10.50.00/Maildir/new"
REFLOC1_OUT="/var/vmail/vmail1/testvps.loc/r/e/f/refloc1-2026.09.01.10.49.35/Maildir/new"
DAEMON_LOG="/var/log/mail-proxy/mail-proxy-daemon.log"
DROPIN="/etc/systemd/system/mail-proxy.service.d/routing.conf"

deploy_files() {
  log "=== DEPLOY ==="
  for f in mail-proxy-daemon.py relationship_routing.py relationship_lookup.py; do
    cp -a "/root/Proxy_Email/${f}" "/usr/local/bin/${f}"
    md5sum "/usr/local/bin/${f}"
  done
  if [[ -f /root/Proxy_Email/mail-proxy.service.d/routing.conf ]]; then
    install -d -m 0755 /etc/systemd/system/mail-proxy.service.d
    install -m 0644 /root/Proxy_Email/mail-proxy.service.d/routing.conf "$DROPIN"
  fi
  /opt/delta-transit/venv/bin/python3 -m py_compile /usr/local/bin/mail-proxy-daemon.py
}

set_modes() {
  local inbound="$1"
  local outbound="$2"
  local watch="$3"
  mkdir -p /etc/systemd/system/mail-proxy.service.d
  cat >"$DROPIN" <<EOF
[Service]
Environment=INBOUND_ROUTING_MODE=${inbound}
Environment=OUTBOUND_ROUTING_MODE=${outbound}
Environment=OUTBOUND_WATCH_MODE=${watch}
EOF
  systemctl daemon-reload
  systemctl restart mail-proxy
  sleep 5
  grep -E 'INBOUND_ROUTING_MODE|OUTBOUND_ROUTING_MODE|OUTBOUND_WATCH_MODE' "$DAEMON_LOG" | tail -1 | tee -a "$LOG"
}

inject_to_path() {
  local from_a="$1"
  local to_a="$2"
  local token="$3"
  local dest_dir="$4"
  local tmp
  tmp="$(mktemp)"
  cat >"$tmp" <<EOF
From: ${from_a}
To: ${to_a}
Subject: ${token}
Date: $(date -R)
Message-ID: <${token}@prompt67.test>

PROMPT-67 outbound watch evidence ${token}
EOF
  chown vmail:vmail "$tmp"
  chmod 600 "$tmp"
  mv "$tmp" "${dest_dir}/${token}.eml"
  log "INJECTED ${token} from=${from_a} to=${to_a} dest=${dest_dir}"
}

wait_log() {
  local pattern="$1"
  local timeout="${2:-45}"
  local i=0
  while (( i < timeout )); do
    if grep -q "$pattern" "$DAEMON_LOG"; then
      grep "$pattern" "$DAEMON_LOG" | tail -3 | tee -a "$LOG"
      return 0
    fi
    sleep 1
    ((i++)) || true
  done
  log "TIMEOUT waiting for: $pattern"
  return 1
}

log "=== PROMPT-67 VPS verification start (legacy mode matrix — see PROMPT-79.1 note) ==="
deploy_files

log "=== TEST 2a: dual mode relationship maildir pickup ==="
set_modes shadow shadow dual
TOKEN_A="${TOKEN_BASE}-DUAL"
inject_to_path "clientloc1@testvps.loc" "clientint1@frona.ru" "$TOKEN_A" "$CLIENTLOC1_NEW"
wait_log "$TOKEN_A"
wait_log 'Watchdog: new email file for relationship'
wait_log '\[OUTBOUND_WATCH_DUAL\].*not_visible_via_referent_outbox' || true
wait_log '\[OUTBOUND_RELATIONSHIP_SHADOW\]' || true

log "=== TEST 2b: relationship_only + shadow fail-closed ==="
set_modes shadow shadow relationship_only
grep -E 'failing closed to referent_only|OUTBOUND_WATCH_MODE=referent_only' "$DAEMON_LOG" | tail -5 | tee -a "$LOG"

log "=== TEST 2c: rollback referent_only ==="
set_modes shadow shadow referent_only
grep 'OUTBOUND_WATCH_MODE=referent_only' "$DAEMON_LOG" | tail -1 | tee -a "$LOG"
TOKEN_B="${TOKEN_BASE}-ROLLBACK"
inject_to_path "clientloc1@testvps.loc" "clientint1@frona.ru" "$TOKEN_B" "$REFLOC1_OUT"
wait_log "$TOKEN_B"
wait_log 'Watchdog: new email file for referent' || true

log "=== DONE log at $LOG ==="
