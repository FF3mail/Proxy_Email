#!/usr/bin/env python3
"""
Live MariaDB integration for DEV-56 option C (SET STATEMENT max_statement_time).

Skipped unless DELTA_TEST_MARIADB_DSN is set, e.g.:
  export DELTA_TEST_MARIADB_DSN='mysql://USER:PASS@127.0.0.1:3306/DATABASE'

Uses production supervisor_db helpers and mysql.connector pooling (pool_reset_session).
"""

from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from typing import Any, Dict
from urllib.parse import unquote, urlparse

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

import mysql.connector
from mysql.connector import Error as MySQLError
from mysql.connector import pooling

from relationship_lookup import RelationshipLookup
from supervisor_db import (
    format_set_statement_select,
    is_mariadb_statement_timeout,
    rollback_connection_quietly,
)

DSN_ENV = 'DELTA_TEST_MARIADB_DSN'
_REFERENT_COLUMNS = 'id, username, local_inbox, local_outbox'


def _parse_dsn(dsn: str) -> Dict[str, Any]:
    parsed = urlparse(dsn)
    if parsed.scheme not in ('mysql', 'mariadb'):
        raise ValueError(f'unsupported DSN scheme: {parsed.scheme!r}')
    database = (parsed.path or '').lstrip('/')
    if not database:
        raise ValueError('DSN missing database path')
    return {
        'host': parsed.hostname or '127.0.0.1',
        'port': parsed.port or 3306,
        'user': unquote(parsed.username or ''),
        'password': unquote(parsed.password or ''),
        'database': database,
    }


def _dsn_configured() -> bool:
    return bool(os.environ.get(DSN_ENV, '').strip())


def _pool_from_env() -> pooling.MySQLConnectionPool:
    cfg = _parse_dsn(os.environ[DSN_ENV].strip())
    return pooling.MySQLConnectionPool(
        pool_name='dev56_supervisor_it',
        pool_size=3,
        pool_reset_session=True,
        host=cfg['host'],
        user=cfg['user'],
        password=cfg['password'],
        database=cfg['database'],
        port=int(cfg['port']),
        charset='utf8mb4',
        collation='utf8mb4_unicode_ci',
        autocommit=False,
        connect_timeout=10,
        read_timeout=30,
        write_timeout=30,
    )


class _PoolDb:
    def __init__(self, pool: pooling.MySQLConnectionPool) -> None:
        self._pool = pool

    def get_connection(self):
        return self._pool.get_connection()


def _connect_direct() -> mysql.connector.MySQLConnection:
    cfg = _parse_dsn(os.environ[DSN_ENV].strip())
    return mysql.connector.connect(
        host=cfg['host'],
        port=int(cfg['port']),
        user=cfg['user'],
        password=cfg['password'],
        database=cfg['database'],
        charset='utf8mb4',
        collation='utf8mb4_unicode_ci',
        autocommit=False,
        connect_timeout=10,
        read_timeout=30,
        write_timeout=30,
    )


def _reset_seed_data() -> None:
    conn = _connect_direct()
    try:
        cur = conn.cursor()
        cur.execute('DELETE FROM clients')
        cur.execute('DELETE FROM external_accounts')
        cur.execute('DELETE FROM referents')
        conn.commit()
        cur.execute(
            "INSERT INTO referents (username, active) VALUES ('dev56-it', 1)"
        )
        conn.commit()
        cur.close()
    finally:
        conn.close()


def _exec_expect_timeout(conn, sleep_sec: float, cap_sec: float) -> MySQLError:
    cur = conn.cursor()
    sql = format_set_statement_select(f'SELECT SLEEP({sleep_sec:g})', cap_sec)
    try:
        cur.execute(sql)
        cur.fetchall()
        raise AssertionError('expected MariaDB error 1969')
    except MySQLError as exc:
        if not is_mariadb_statement_timeout(exc):
            raise
        return exc
    finally:
        cur.close()


@unittest.skipUnless(_dsn_configured(), f'{DSN_ENV} not set (live MariaDB required)')
class SupervisorDbMariaDbIntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        _reset_seed_data()

    @classmethod
    def tearDownClass(cls) -> None:
        conn = _connect_direct()
        try:
            cur = conn.cursor()
            cur.execute('DELETE FROM clients')
            cur.execute('DELETE FROM external_accounts')
            cur.execute('DELETE FROM referents')
            conn.commit()
            cur.close()
        finally:
            conn.close()

    def setUp(self) -> None:
        self.pool = _pool_from_env()
        self.db = _PoolDb(self.pool)
        self.lookup = RelationshipLookup(self.db)

    def test_a_set_statement_sleep_raises_1969(self) -> None:
        conn = _connect_direct()
        try:
            exc = _exec_expect_timeout(conn, sleep_sec=2.0, cap_sec=0.15)
            self.assertTrue(is_mariadb_statement_timeout(exc))
        finally:
            conn.close()

    def test_b_same_connection_works_after_1969_rollback(self) -> None:
        conn = _connect_direct()
        try:
            _exec_expect_timeout(conn, sleep_sec=2.0, cap_sec=0.15)
            rollback_connection_quietly(conn)
            cur = conn.cursor()
            cur.execute('SELECT 1')
            self.assertEqual(cur.fetchone(), (1,))
            cur.close()
        finally:
            conn.close()

    def test_c_pool_reuse_after_1969_and_close(self) -> None:
        conn = self.pool.get_connection()
        try:
            _exec_expect_timeout(conn, sleep_sec=2.0, cap_sec=0.15)
            rollback_connection_quietly(conn)
        finally:
            conn.close()
        conn2 = self.pool.get_connection()
        try:
            cur2 = conn2.cursor()
            cur2.execute(
                format_set_statement_select('SELECT COUNT(*) FROM referents', 5.0)
            )
            count = cur2.fetchone()[0]
            self.assertEqual(int(count), 1)
            cur2.close()
        finally:
            conn2.close()

    def test_d_bounded_referent_select_returns_seed_row(self) -> None:
        conn = self.pool.get_connection()
        try:
            cur = conn.cursor(dictionary=True)
            base = f'SELECT {_REFERENT_COLUMNS} FROM referents WHERE active = 1'
            cur.execute(format_set_statement_select(base, 5.0))
            rows = cur.fetchall()
            self.assertEqual(len(rows), 1)
            self.assertEqual(rows[0]['username'], 'dev56-it')
            cur.close()
        finally:
            conn.close()

    def test_e_lookup_list_watch_targets_with_cap_empty(self) -> None:
        rows = self.lookup.list_watch_targets_for_referent(
            1,
            max_statement_time=5.0,
        )
        self.assertEqual(rows, [])

    def test_f_worker_style_query_unbounded_after_pool_set_statement(self) -> None:
        """Supervisor SET STATEMENT must not leave a session cap on pooled connections."""
        conn = self.pool.get_connection()
        try:
            cur = conn.cursor()
            cur.execute(
                format_set_statement_select('SELECT SLEEP(0.05)', 5.0)
            )
            cur.fetchall()
            cur.close()
        finally:
            conn.close()
        conn2 = self.pool.get_connection()
        try:
            cur2 = conn2.cursor()
            cur2.execute('SELECT SLEEP(0.5)')
            cur2.fetchall()
            cur2.close()
        finally:
            conn2.close()


if __name__ == '__main__':
    unittest.main(verbosity=2)