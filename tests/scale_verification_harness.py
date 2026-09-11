#!/usr/bin/env python3
"""
PROMPT-72 scale verification harness.

Creates synthetic referent/relationship fixtures in a disposable MariaDB database
and measures daemon-relevant query/scheduling behaviour at N=25 and N=50.

Run on a host with MariaDB (lab VPS). Configure via environment:
  SCALE_TEST_DB_HOST (default 127.0.0.1)
  SCALE_TEST_DB_PORT (default 3306)
  SCALE_TEST_DB_USER (default root)
  SCALE_TEST_DB_PASS (default empty)
  SCALE_TEST_DB_NAME (default mail_proxy_scale_test)

Outputs JSON to stdout.
"""

from __future__ import annotations

import json
import os
import sys
import time
from pathlib import Path
from typing import Any, Dict, List, Tuple

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

import mysql.connector
from mysql.connector import Error as MySQLError

from relationship_lookup import RelationshipLookup

TEST_DB_HOST = os.environ.get('SCALE_TEST_DB_HOST', '127.0.0.1')
TEST_DB_PORT = int(os.environ.get('SCALE_TEST_DB_PORT', '3306'))
TEST_DB_USER = os.environ.get('SCALE_TEST_DB_USER', 'root')
TEST_DB_PASS = os.environ.get('SCALE_TEST_DB_PASS', '')
TEST_DB_NAME = os.environ.get('SCALE_TEST_DB_NAME', 'mail_proxy_scale_test')
TEST_DB_UNIX_SOCKET = os.environ.get(
    'SCALE_TEST_DB_UNIX_SOCKET',
    '/var/run/mysqld/mysqld.sock' if os.path.exists('/var/run/mysqld/mysqld.sock') else '',
)

IMAP_WORKER_COUNT = 20
IMAP_POLL_INTERVAL = 60
DB_POOL_SIZE = 12


def _connect_kwargs(database: str | None = None) -> Dict[str, Any]:
    kwargs: Dict[str, Any] = {
        'user': TEST_DB_USER,
        'password': TEST_DB_PASS,
        'charset': 'utf8mb4',
        'collation': 'utf8mb4_unicode_ci',
        'autocommit': True,
    }
    if TEST_DB_UNIX_SOCKET:
        kwargs['unix_socket'] = TEST_DB_UNIX_SOCKET
    else:
        kwargs['host'] = TEST_DB_HOST
        kwargs['port'] = TEST_DB_PORT
    if database:
        kwargs['database'] = database
    return kwargs


def _admin_connect(database: str | None = None):
    return mysql.connector.connect(**_connect_kwargs(database))


def _execute_script(conn, script: str) -> None:
    cursor = conn.cursor(buffered=True)
    statement = ''
    for line in script.splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith('--'):
            continue
        statement += line + '\n'
        if stripped.endswith(';'):
            cursor.execute(statement)
            try:
                while cursor.nextset():
                    pass
            except mysql.connector.Error:
                pass
            statement = ''
    cursor.close()


def bootstrap_schema() -> None:
    conn = _admin_connect()
    cursor = conn.cursor()
    cursor.execute(f'DROP DATABASE IF EXISTS `{TEST_DB_NAME}`')
    cursor.execute(
        f'CREATE DATABASE `{TEST_DB_NAME}` '
        'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    )
    cursor.close()
    conn.close()

    conn = _admin_connect(TEST_DB_NAME)
    base_schema = (ROOT / 'schema.sql').read_text(encoding='utf-8')
    base_schema = base_schema.replace('CREATE DATABASE IF NOT EXISTS mail_proxy', '-- skipped')
    base_schema = base_schema.replace('USE mail_proxy;', '-- skipped')
    _execute_script(conn, base_schema)
    migration = (ROOT / 'migrations' / '002_client_relationship_columns.sql').read_text(
        encoding='utf-8'
    )
    migration = migration.replace('USE mail_proxy;', '-- skipped')
    _execute_script(conn, migration)
    conn.close()


class _Db:
    def __init__(self, **kwargs: Any) -> None:
        self._kwargs = kwargs

    def get_connection(self):
        return mysql.connector.connect(**self._kwargs)


def relationships_per_referent(referent_index: int) -> int:
    """5–10 relationships, varying by referent (deterministic)."""
    return 5 + (referent_index % 6)


def load_fixture(referent_count: int) -> Dict[str, int]:
    conn = _admin_connect(TEST_DB_NAME)
    cursor = conn.cursor()
    cursor.execute('SET FOREIGN_KEY_CHECKS=0')
    cursor.execute('TRUNCATE clients')
    cursor.execute('TRUNCATE external_accounts')
    cursor.execute('TRUNCATE referents')
    cursor.execute('SET FOREIGN_KEY_CHECKS=1')
    cursor.close()

    total_relationships = 0
    total_accounts = 0
    rel_id = 0

    for r in range(1, referent_count + 1):
        inbox = f'ref{r:03d}@scale.test'
        outbox = f'/var/vmail/scale/r{r:03d}/Maildir'
        cursor = conn.cursor()
        cursor.execute(
            'INSERT INTO referents (username, local_inbox, local_outbox, active) '
            'VALUES (%s, %s, %s, 1)',
            (f'Referent {r:03d}', inbox, outbox),
        )
        referent_id = int(cursor.lastrowid)
        cursor.close()

        rel_count = relationships_per_referent(r)
        for c in range(rel_count):
            rel_id += 1
            cursor = conn.cursor()
            cursor.execute(
                """
                INSERT INTO external_accounts (
                    referent_id, email, imap_host, smtp_host, active
                ) VALUES (%s, %s, 'imap.scale.test', 'smtp.scale.test', 1)
                """,
                (referent_id, f'acct{r:03d}_{c + 1:02d}@ext.scale.test'),
            )
            account_id = int(cursor.lastrowid)
            cursor.close()
            total_accounts += 1

            maildir = f'/var/vmail/scale/r{r:03d}/c{c:02d}/Maildir'
            local_referent = f'ref{r:03d}-rel{c + 1:02d}@scale.test'
            cursor = conn.cursor()
            cursor.execute(
                """
                INSERT INTO clients (
                    email, referent_id,
                    external_client_email, local_client_email, local_referent_email,
                    external_account_id, local_client_maildir, active
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, 1)
                """,
                (
                    f'legacy{rel_id}@scale.test',
                    referent_id,
                    f'extclient{rel_id}@partner.scale.test',
                    f'localclient{rel_id}@scale.test',
                    local_referent,
                    account_id,
                    maildir,
                ),
            )
            cursor.close()
            total_relationships += 1

    conn.commit()
    conn.close()
    return {
        'referents': referent_count,
        'relationships': total_relationships,
        'accounts': total_accounts,
    }


def measure_imap_poller_load() -> Dict[str, Any]:
    conn = _admin_connect(TEST_DB_NAME)
    cursor = conn.cursor(dictionary=True)

    t0 = time.perf_counter()
    cursor.execute(
        'SELECT id, username, local_inbox, local_outbox '
        'FROM referents WHERE active = 1'
    )
    referents = cursor.fetchall()
    referent_ms = (time.perf_counter() - t0) * 1000

    account_queries_ms = 0.0
    task_count = 0
    for ref in referents:
        t1 = time.perf_counter()
        cursor.execute(
            """
            SELECT id, email, imap_host
            FROM external_accounts
            WHERE referent_id = %s AND active = 1
            """,
            (ref['id'],),
        )
        accounts = cursor.fetchall()
        account_queries_ms += (time.perf_counter() - t1) * 1000
        for acc in accounts:
            if acc.get('imap_host'):
                task_count += 1

    cursor.close()
    conn.close()

    total_ms = referent_ms + account_queries_ms
    batches = (task_count + IMAP_WORKER_COUNT - 1) // IMAP_WORKER_COUNT
    # Scheduling model: workers drain queue in parallel; worst-case serial work
    # if each task takes avg_task_seconds (measured separately as dry-run = 0).
    return {
        'referent_query_ms': round(referent_ms, 3),
        'account_queries_total_ms': round(account_queries_ms, 3),
        'combined_db_ms': round(total_ms, 3),
        'imap_task_count': task_count,
        'imap_worker_count': IMAP_WORKER_COUNT,
        'imap_poll_interval_s': IMAP_POLL_INTERVAL,
        'worker_batches_if_saturated': batches,
        'headroom_note': (
            f'{task_count} tasks / {IMAP_WORKER_COUNT} workers = '
            f'{batches} batch(es); DB load {total_ms:.2f}ms << {IMAP_POLL_INTERVAL}s interval'
        ),
    }


def measure_sync_db_portion(lookup: RelationshipLookup) -> Dict[str, Any]:
    """DB + set-diff portion of _sync_database_state (no filesystem scans)."""
    conn = _admin_connect(TEST_DB_NAME)
    cursor = conn.cursor(dictionary=True)

    t0 = time.perf_counter()
    cursor.execute(
        'SELECT id, username, local_inbox, local_outbox '
        'FROM referents WHERE active = 1'
    )
    referents = cursor.fetchall()
    load_refs_ms = (time.perf_counter() - t0) * 1000

    t1 = time.perf_counter()
    targets = lookup.list_watch_targets()
    list_targets_ms = (time.perf_counter() - t1) * 1000

    # Simulate set reconciliation (referent + relationship registries).
    watched_referent_ids = {r['id'] for r in referents}
    current_ids = {int(t['relationship']['relationship_id']) for t in targets}
    watched_relationship_ids = set(current_ids)

    t2 = time.perf_counter()
    for _ in range(100):
        new_ref = set()
        removed_ref = set()
        current_ref_ids = {r['id'] for r in referents}
        new_ref = current_ref_ids - watched_referent_ids
        removed_ref = watched_referent_ids - current_ref_ids
        new_rel = current_ids - watched_relationship_ids
        removed_rel = watched_relationship_ids - current_ids
        stable = current_ids & watched_relationship_ids
        _ = (new_ref, removed_ref, new_rel, removed_rel, stable)
    set_logic_ms = (time.perf_counter() - t2) * 1000 / 100

    cursor.close()
    conn.close()

    total_ms = load_refs_ms + list_targets_ms + set_logic_ms
    return {
        'load_referents_ms': round(load_refs_ms, 3),
        'list_watch_targets_ms': round(list_targets_ms, 3),
        'set_reconcile_ms': round(set_logic_ms, 3),
        'total_db_sync_portion_ms': round(total_ms, 3),
        'relationship_targets': len(targets),
        'within_60s_period': total_ms < 60000,
        'excludes_filesystem': (
            '_scan_existing_outgoing* not run (maildirs absent on harness host)'
        ),
    }


def run_scenario(referent_count: int) -> Dict[str, Any]:
    counts = load_fixture(referent_count)
    db = _Db(**_connect_kwargs(TEST_DB_NAME))
    lookup = RelationshipLookup(db)
    return {
        'fixture': counts,
        'relationships_per_referent_pattern': '5 + (referent_index % 6)',
        'imap_poller': measure_imap_poller_load(),
        'sync_db_portion': measure_sync_db_portion(lookup),
        'db_pool_reasoning': {
            'db_pool_size': DB_POOL_SIZE,
            'imap_workers': IMAP_WORKER_COUNT,
            'smtp_workers': 20,
            'note': (
                'Pool caps concurrent DB connections per process; workers hold '
                'connections only during task handling. At measured query times, '
                'contention risk is low unless tasks block on long IMAP/SMTP I/O '
                'while holding connections (not measured here).'
            ),
        },
    }


def main() -> int:
    try:
        _admin_connect().close()
    except MySQLError as exc:
        print(json.dumps({
            'error': 'database_unavailable',
            'message': str(exc),
            'hint': 'Run on lab VPS or set SCALE_TEST_DB_* env vars',
        }, indent=2))
        return 1

    bootstrap_schema()
    results = {
        'harness': 'PROMPT-72 scale_verification_harness.py',
        'database': TEST_DB_NAME,
        'host': TEST_DB_HOST,
        'scenarios': {
            'n25': run_scenario(25),
            'n50': run_scenario(50),
        },
    }
    print(json.dumps(results, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
