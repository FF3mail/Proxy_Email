#!/usr/bin/env bash
# PROMPT-72 scale harness — uses mysql CLI + PHP benches (no Python mysql.connector).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB="${SCALE_TEST_DB_NAME:-mail_proxy_scale_test}"
export SCALE_TEST_DB_NAME="$DB"

mysql_cli() {
  mysql -N "$@"
}

bootstrap_schema() {
  mysql_cli -e "DROP DATABASE IF EXISTS \`${DB}\`;"
  mysql_cli -e "CREATE DATABASE \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  sed 's/CREATE DATABASE IF NOT EXISTS mail_proxy/-- skip/; s/USE mail_proxy;//' \
    "$ROOT/schema.sql" | mysql_cli "$DB"
  sed 's/USE mail_proxy;//' "$ROOT/migrations/002_client_relationship_columns.sql" | mysql_cli "$DB"
}

load_fixture() {
  local referent_count="$1"
  mysql_cli "$DB" -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE clients; TRUNCATE external_accounts; TRUNCATE referents; SET FOREIGN_KEY_CHECKS=1;"
  local total_rel=0 total_acct=0
  for r in $(seq 1 "$referent_count"); do
    local rel_count=$((5 + (r % 6)))
    local inbox="ref$(printf '%03d' "$r")@scale.test"
    local outbox="/var/vmail/scale/r${r}/Maildir"
    mysql_cli "$DB" -e "INSERT INTO referents (username, local_inbox, local_outbox, active) VALUES ('Referent $(printf '%03d' "$r")', '${inbox}', '${outbox}', 1);"
    local referent_id
    referent_id=$(mysql_cli "$DB" -e "SELECT id FROM referents WHERE local_inbox='${inbox}' LIMIT 1;")
    local c=0
    while [ "$c" -lt "$rel_count" ]; do
      c=$((c + 1))
      total_rel=$((total_rel + 1))
      mysql_cli "$DB" -e "INSERT INTO external_accounts (referent_id, email, imap_host, smtp_host, active) VALUES (${referent_id}, 'acct${r}_$(printf '%02d' "$c")@ext.scale.test', 'imap.scale.test', 'smtp.scale.test', 1);"
      total_acct=$((total_acct + 1))
      local aid
      aid=$(mysql_cli "$DB" -e "SELECT id FROM external_accounts WHERE referent_id=${referent_id} ORDER BY id DESC LIMIT 1;")
      local maildir="/var/vmail/scale/r${r}/c$(printf '%02d' "$c")/Maildir"
      local local_ref="ref$(printf '%03d' "$r")-rel$(printf '%02d' "$c")@scale.test"
      mysql_cli "$DB" -e "INSERT INTO clients (email, referent_id, external_client_email, local_client_email, local_referent_email, external_account_id, local_client_maildir, active) VALUES ('legacy${total_rel}@scale.test', ${referent_id}, 'extclient${total_rel}@partner.scale.test', 'localclient${total_rel}@scale.test', '${local_ref}', ${aid}, '${maildir}', 1);"
    done
  done
  echo "${total_rel} ${total_acct}"
}

measure_imap() {
  local start end
  start=$(date +%s%N)
  local ref_count
  ref_count=$(mysql_cli "$DB" -e "SELECT COUNT(*) FROM referents WHERE active=1;")
  local task_count=0
  while read -r rid; do
    local acct_n
    acct_n=$(mysql_cli "$DB" -e "SELECT COUNT(*) FROM external_accounts WHERE referent_id=${rid} AND active=1 AND imap_host IS NOT NULL AND imap_host<>'';")
    task_count=$((task_count + acct_n))
  done < <(mysql_cli "$DB" -e "SELECT id FROM referents WHERE active=1;")
  end=$(date +%s%N)
  local ms=$(( (end - start) / 1000000 ))
  local batches=$(( (task_count + 19) / 20 ))
  echo "imap_ms=${ms} referents=${ref_count} tasks=${task_count} batches=${batches}"
}

measure_list_targets() {
  local start end
  start=$(date +%s%N)
  local cnt
  cnt=$(mysql_cli "$DB" -e "
    SELECT COUNT(*)
    FROM clients c
    JOIN external_accounts ea ON ea.id = c.external_account_id
    JOIN referents r ON r.id = c.referent_id
    WHERE c.active=1 AND ea.active=1 AND r.active=1
      AND c.external_account_id IS NOT NULL
      AND c.external_client_email IS NOT NULL AND c.external_client_email<>''
      AND c.local_client_email IS NOT NULL AND c.local_client_email<>''
      AND c.local_referent_email IS NOT NULL AND c.local_referent_email<>''
      AND c.local_client_maildir IS NOT NULL AND c.local_client_maildir<>''
      AND ea.referent_id = c.referent_id;")
  end=$(date +%s%N)
  local ms=$(( (end - start) / 1000000 ))
  echo "list_watch_targets_ms=${ms} relationships=${cnt}"
}

run_scenario() {
  local n="$1"
  echo "=== scenario n=${n} ==="
  read -r total_rel total_acct < <(load_fixture "$n")
  echo "fixture relationships=${total_rel} accounts=${total_acct}"
  measure_imap
  measure_list_targets
}

bootstrap_schema
run_scenario 25
run_scenario 50

echo "=== collision bench ==="
SCALE_TEST_DB_UNIX_SOCKET="${SCALE_TEST_DB_UNIX_SOCKET:-/var/run/mysqld/mysqld.sock}" \
  php "$ROOT/tests/scale_collision_bench.php"
