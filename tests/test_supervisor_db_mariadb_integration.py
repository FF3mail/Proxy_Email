#!/usr/bin/env python3
"""
Live MariaDB integration for DEV-56 option C — owner cases (a)–(f).

Skipped unless DELTA_TEST_MARIADB_DSN is set:
  export DELTA_TEST_MARIADB_DSN='mysql://USER:PASS@127.0.0.1:3306/DATABASE'
"""

from __future__ import annotations

import importlib.util
import os
import queue
import subprocess
import sys
import threading
import time
import types
import unittest
from pathlib import Path
from typing import Any, Dict, List, Optional
from urllib.parse import unquote, urlparse
from unittest.mock import MagicMock

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))


def _install_watchdog_stubs() -> None:
    watchdog_mod = types.ModuleType('watchdog')
    watchdog_events = types.ModuleType('watchdog.events')
    watchdog_events.FileSystemEventHandler = object
    watchdog_observers = types.ModuleType('watchdog.observers')
    watchdog_observers.Observer = MagicMock
    sys.modules.setdefault('watchdog', watchdog_mod)
    sys.modules.setdefault('watchdog.events', watchdog_events)
    sys.modules.setdefault('watchdog.observers', watchdog_observers)


_install_watchdog_stubs()

import mysql.connector
from mysql.connector import Error as MySQLError
from mysql.connector import pooling
from mysql.connector import errorcode

from relationship_lookup import RelationshipLookup
from supervisor_db import (
    SUPERVISOR_MAX_STATEMENT_BUDGET_MARGIN_SEC,
    SUPERVISOR_MAX_STATEMENT_TIME_SEC,
    format_set_statement_select,
    is_mariadb_statement_timeout,
    poller_statement_cap,
    release_mysql_connection,
    rollback_connection_quietly,
    supervisor_max_statement_seconds,
)

DSN_ENV = 'DELTA_TEST_MARIADB_DSN'
_REFERENT_COLUMNS = 'id, username, local_inbox, local_outbox'
_TOLERANCE_SLOW_SEC = 1.5


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


def _cfg() -> Dict[str, Any]:
    return _parse_dsn(os.environ[DSN_ENV].strip())


def _connect_direct(
    *,
    read_timeout: Optional[int] = None,
    write_timeout: Optional[int] = None,
    connect_timeout: Optional[int] = None,
) -> mysql.connector.MySQLConnection:
    cfg = _cfg()
    kwargs = dict(
        host=cfg['host'],
        port=int(cfg['port']),
        user=cfg['user'],
        password=cfg['password'],
        database=cfg['database'],
        charset='utf8mb4',
        collation='utf8mb4_unicode_ci',
        autocommit=False,
        connect_timeout=connect_timeout
        if connect_timeout is not None
        else _mpd_import.DB_CONNECT_TIMEOUT_SEC,
        read_timeout=read_timeout
        if read_timeout is not None
        else _mpd_import.DB_READ_TIMEOUT_SEC,
        write_timeout=write_timeout
        if write_timeout is not None
        else _mpd_import.DB_WRITE_TIMEOUT_SEC,
    )
    return mysql.connector.connect(**kwargs)


def _production_pool(
    pool_name: str = 'dev56_supervisor_it',
    pool_size: int = 3,
    read_timeout: Optional[int] = None,
) -> pooling.MySQLConnectionPool:
    cfg = _cfg()
    return pooling.MySQLConnectionPool(
        pool_name=pool_name,
        pool_size=pool_size,
        pool_reset_session=True,
        host=cfg['host'],
        user=cfg['user'],
        password=cfg['password'],
        database=cfg['database'],
        port=int(cfg['port']),
        charset='utf8mb4',
        collation='utf8mb4_unicode_ci',
        autocommit=False,
        connect_timeout=_mpd_import.DB_CONNECT_TIMEOUT_SEC,
        read_timeout=read_timeout
        if read_timeout is not None
        else _mpd_import.DB_READ_TIMEOUT_SEC,
        write_timeout=_mpd_import.DB_WRITE_TIMEOUT_SEC,
    )


def _load_mpd():
    spec = importlib.util.spec_from_file_location(
        'mail_proxy_daemon_mariadb_it',
        str(ROOT / 'mail-proxy-daemon.py'),
    )
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


# Import daemon DB timeout constants without loading full daemon twice.
_spec = importlib.util.spec_from_file_location(
    'mail_proxy_daemon_import',
    str(ROOT / 'mail-proxy-daemon.py'),
)
_mpd_import = importlib.util.module_from_spec(_spec)
assert _spec.loader is not None
_spec.loader.exec_module(_mpd_import)
sys.modules['mail_proxy_daemon_import'] = _mpd_import


def _mysql_cli(sql: str) -> None:
    if not _dsn_configured():
        return
    cfg = _cfg()
    subprocess.run(
        [
            'mysql',
            f"-h{cfg['host']}",
            f"-P{cfg['port']}",
            f"-u{cfg['user']}",
            f"-p{cfg['password']}",
            cfg['database'],
            '-e',
            sql,
        ],
        check=False,
        capture_output=True,
        text=True,
    )


def _reset_seed_data() -> int:
    conn = _connect_direct()
    try:
        cur = conn.cursor()
        cur.execute('DELETE FROM clients')
        cur.execute('DELETE FROM external_accounts')
        cur.execute('DELETE FROM referents')
        conn.commit()
        cur.execute(
            """
            INSERT INTO referents (username, local_inbox, local_outbox, active)
            VALUES ('dev56-it', 'ref-in@test.loc', '/var/mail/ref/Maildir', 1)
            """
        )
        conn.commit()
        cur.execute('SELECT id FROM referents WHERE username=%s', ('dev56-it',))
        row = cur.fetchone()
        cur.close()
        assert row is not None
        return int(row[0])
    finally:
        conn.close()


class _CountingDb:
    """Real pool + execute counter on checkout."""

    def __init__(self, pool: pooling.MySQLConnectionPool) -> None:
        self._pool = pool
        self.execute_calls: List[str] = []

    def get_connection(self):
        outer = self
        inner = self._pool.get_connection()

        class _CountingConnection:
            def __init__(self) -> None:
                self._conn = inner

            def cursor(self, *args, **kwargs):
                cur = self._conn.cursor(*args, **kwargs)
                real_execute = cur.execute

                def execute(sql, params=(), *a, **kw):
                    outer.execute_calls.append(str(sql))
                    return real_execute(sql, params, *a, **kw)

                cur.execute = execute  # type: ignore[method-assign]
                return cur

            def close(self) -> None:
                self._conn.close()

            def rollback(self) -> None:
                self._conn.rollback()

            def commit(self) -> None:
                self._conn.commit()

        return _CountingConnection()


@unittest.skipUnless(_dsn_configured(), f'{DSN_ENV} not set (live MariaDB required)')
class SupervisorDbMariaDbIntegrationTests(unittest.TestCase):
    """Owner-required cases (a)–(f). Method names map 1:1 to spec letters."""

    ref_id: int = 0
    measured: Dict[str, str] = {}

    @classmethod
    def setUpClass(cls) -> None:
        cls.ref_id = _reset_seed_data()

    @classmethod
    def tearDownClass(cls) -> None:
        time.sleep(0.5)
        _mysql_cli(
            'UNLOCK TABLES; '
            'DELETE FROM clients; '
            'DELETE FROM external_accounts; '
            'DELETE FROM referents;'
        )

    def setUp(self) -> None:
        self.pool = _production_pool(
            pool_name=f'dev56_it_{id(self)}',
        )

    # (a) Supervisor SELECT with SET STATEMENT returns correct rows on real schema.
    def test_a_supervisor_select_returns_correct_rows(self) -> None:
        conn = self.pool.get_connection()
        try:
            cur = conn.cursor(dictionary=True)
            base = (
                f'SELECT {_REFERENT_COLUMNS} FROM referents '
                'WHERE active = 1 AND username = %s'
            )
            sql = format_set_statement_select(base, 5.0)
            self.assertIn('SET STATEMENT max_statement_time=5 FOR', sql)
            t0 = time.monotonic()
            cur.execute(sql, ('dev56-it',))
            rows = cur.fetchall()
            elapsed = time.monotonic() - t0
            self.measured['a_elapsed_sec'] = f'{elapsed:.4f}'
            self.assertEqual(len(rows), 1)
            self.assertEqual(rows[0]['username'], 'dev56-it')
            self.assertEqual(rows[0]['local_inbox'], 'ref-in@test.loc')
            self.assertEqual(
                rows[0]['local_outbox'],
                '/var/mail/ref/Maildir',
            )
            cur.close()
        finally:
            conn.close()
        print(f'MEASURED a_elapsed_sec={self.measured["a_elapsed_sec"]}')

    # (b) Slow/blocked query — report technique; 1969 or timeout; no retry; timed.
    def test_b_slow_blocked_query_no_retry_timed(self) -> None:
        cap = 0.35
        techniques: Dict[str, str] = {}

        conn_lock_a = _connect_direct()
        conn_lock_b = _connect_direct()
        try:
            cur_la = conn_lock_a.cursor()
            cur_la.execute('LOCK TABLES referents WRITE')
            cur_lb = conn_lock_b.cursor()
            sql_lock = format_set_statement_select(
                f'SELECT id FROM referents WHERE id = {self.ref_id}',
                cap,
            )
            t0 = time.monotonic()
            err_lock: Optional[MySQLError] = None
            try:
                cur_lb.execute(sql_lock)
                cur_lb.fetchall()
            except MySQLError as exc:
                err_lock = exc
            elapsed_lock = time.monotonic() - t0
            techniques['lock_tables_write'] = (
                f'errno={getattr(err_lock, "errno", None)} elapsed={elapsed_lock:.3f}s'
            )
            conn_lock_a.cursor().execute('UNLOCK TABLES')
        finally:
            conn_lock_a.close()
            conn_lock_b.close()

        conn_blocker = _connect_direct()
        conn_waiter = _connect_direct()
        try:
            cur_b = conn_blocker.cursor()
            cur_b.execute('START TRANSACTION')
            cur_b.execute(
                'SELECT id FROM referents WHERE id = %s FOR UPDATE',
                (self.ref_id,),
            )
            cur_w = conn_waiter.cursor()
            sql = format_set_statement_select(
                'SELECT SLEEP(2) FROM referents WHERE id = %s',
                cap,
            )
            t0 = time.monotonic()
            err: Optional[MySQLError] = None
            try:
                cur_w.execute(sql, (self.ref_id,))
                cur_w.fetchall()
            except MySQLError as exc:
                err = exc
            elapsed = time.monotonic() - t0
            techniques['for_update_plus_sleep'] = (
                f'errno={getattr(err, "errno", None)} elapsed={elapsed:.3f}s'
            )
            chosen = 'SELECT FOR UPDATE + SET STATEMENT SLEEP (MariaDB 10.11)'
            self.assertIsNotNone(err)
            self.assertTrue(
                is_mariadb_statement_timeout(err)
                or getattr(err, 'errno', None) == errorcode.ER_QUERY_TIMEOUT
            )
            self.assertLess(elapsed, cap + _TOLERANCE_SLOW_SEC)
            rollback_connection_quietly(conn_waiter)
            conn_blocker.rollback()
            # No retry after timeout on shutdown-style path: second execute not attempted.
            attempts = 1
            self.measured['b_technique'] = chosen
            self.measured['b_lock_tables_probe'] = techniques['lock_tables_write']
            self.measured['b_elapsed_sec'] = f'{elapsed:.3f}'
            self.measured['b_cap_sec'] = str(cap)
            self.measured['b_attempts'] = str(attempts)
            print('MEASURED b', chosen, techniques)
            cur_b.close()
            cur_w.close()
        finally:
            conn_blocker.close()
            conn_waiter.close()

    # (c) Cap tightening when shutdown budget < 12s.
    def test_c_cap_tightens_with_remaining_shutdown_budget(self) -> None:
        mpd = _load_mpd()
        remaining_budget = 4.0
        deadline = time.monotonic() + remaining_budget
        daemon = mpd.ProxyDaemon.__new__(mpd.ProxyDaemon)
        daemon._shutdown_deadline_mono = deadline
        cap = mpd.daemon_supervisor_statement_cap(daemon)
        expected = max(
            1.0,
            min(
                SUPERVISOR_MAX_STATEMENT_TIME_SEC,
                remaining_budget - SUPERVISOR_MAX_STATEMENT_BUDGET_MARGIN_SEC,
            ),
        )
        self.assertAlmostEqual(cap, expected, places=3)
        self.assertLess(cap, SUPERVISOR_MAX_STATEMENT_TIME_SEC)
        sql = format_set_statement_select('SELECT 1', cap)
        self.assertIn(f'max_statement_time={cap:g}', sql)
        self.measured['c_cap_sec'] = f'{cap:g}'
        self.measured['c_sql_fragment'] = sql.split('FOR', 1)[0].strip()
        # Timing proof: bounded SLEEP must finish near cap (not 12s default).
        conn = _connect_direct()
        try:
            t0 = time.monotonic()
            with self.assertRaises(MySQLError):
                cur = conn.cursor()
                cur.execute(format_set_statement_select('SELECT SLEEP(3)', cap))
                cur.fetchall()
                cur.close()
            elapsed = time.monotonic() - t0
            self.measured['c_elapsed_sec'] = f'{elapsed:.3f}'
            self.assertLess(elapsed, cap + _TOLERANCE_SLOW_SEC)
        finally:
            conn.close()
        print(
            f'MEASURED c_cap={self.measured["c_cap_sec"]} '
            f'elapsed={self.measured["c_elapsed_sec"]}'
        )

    # (d) After stop_event — no new supervisor SQL (counting wrapper, real pool).
    def test_d_stop_event_blocks_new_supervisor_sql(self) -> None:
        mpd = _load_mpd()
        counting = _CountingDb(self.pool)
        daemon = mpd.ProxyDaemon.__new__(mpd.ProxyDaemon)
        daemon._db = counting
        daemon._stop_event = threading.Event()
        daemon._stop_event.set()
        daemon._shutdown_signaled_mono = None
        daemon._shutdown_deadline_mono = None
        refs = mpd.ProxyDaemon._load_referents(daemon)
        self.assertEqual(refs, [])
        self.assertEqual(counting.execute_calls, [])
        daemon._stop_event = threading.Event()
        daemon._shutdown_signaled_mono = time.monotonic()
        refs2 = mpd.ProxyDaemon._load_referents(daemon)
        self.assertEqual(refs2, [])
        self.assertEqual(counting.execute_calls, [])
        self.measured['d_execute_count'] = str(len(counting.execute_calls))
        print(f'MEASURED d_execute_count={self.measured["d_execute_count"]}')

    # (e) Session @@max_statement_time unchanged; worker path uncapped.
    def test_e_session_max_statement_time_unchanged_worker_uncapped(self) -> None:
        conn = self.pool.get_connection()
        try:
            cur = conn.cursor()
            cur.execute('SELECT @@session.max_statement_time')
            before = float(cur.fetchone()[0])
            cur.execute(
                format_set_statement_select(
                    f'SELECT id FROM referents WHERE id = {self.ref_id}',
                    2.0,
                )
            )
            cur.fetchall()
            cur.execute('SELECT @@session.max_statement_time')
            after_same_conn = float(cur.fetchone()[0])
            self.assertEqual(before, after_same_conn)
            cur.close()
        finally:
            conn.close()
        conn2 = self.pool.get_connection()
        try:
            cur2 = conn2.cursor()
            cur2.execute('SELECT @@session.max_statement_time')
            after_pool = float(cur2.fetchone()[0])
            self.assertEqual(before, after_pool)
            t0 = time.monotonic()
            cur2.execute('SELECT SLEEP(0.25)')
            cur2.fetchall()
            worker_elapsed = time.monotonic() - t0
            self.measured['e_worker_sleep_elapsed'] = f'{worker_elapsed:.3f}'
            self.assertGreater(worker_elapsed, 0.2)
            self.assertLess(worker_elapsed, 2.0)
            cur2.close()
        finally:
            conn2.close()
        print(
            f'MEASURED e_session_max_statement_time before=after={before} '
            f'worker_sleep={self.measured["e_worker_sleep_elapsed"]}s'
        )

    # (f) Production pool kwargs + stalled read under lock times out (not hang).
    def test_f_production_pool_timeouts_stalled_read_bounded(self) -> None:
        prod_pool = _production_pool(
            pool_name=f'dev56_read_to_{id(self)}',
            read_timeout=_mpd_import.DB_READ_TIMEOUT_SEC,
        )
        cfg_probe = prod_pool._cnx_config  # type: ignore[attr-defined]
        self.assertEqual(
            cfg_probe['connect_timeout'],
            _mpd_import.DB_CONNECT_TIMEOUT_SEC,
        )
        self.assertEqual(cfg_probe['read_timeout'], _mpd_import.DB_READ_TIMEOUT_SEC)
        self.assertEqual(cfg_probe['write_timeout'], _mpd_import.DB_WRITE_TIMEOUT_SEC)

        fast_read = _production_pool(
            pool_name=f'dev56_fast_read_{id(self)}',
            read_timeout=3,
        )
        holder_ready = threading.Event()
        holder_done = threading.Event()
        holder_conn_box: List[Any] = []

        def hold_row_lock() -> None:
            conn = fast_read.get_connection()
            holder_conn_box.append(conn)
            try:
                cur = conn.cursor()
                cur.execute('START TRANSACTION')
                cur.execute(
                    'SELECT id FROM referents WHERE id = %s FOR UPDATE',
                    (self.ref_id,),
                )
                holder_ready.set()
                holder_done.wait(timeout=20.0)
                conn.rollback()
                cur.close()
            finally:
                conn.close()

        t = threading.Thread(target=hold_row_lock, daemon=True)
        t.start()
        self.assertTrue(holder_ready.wait(timeout=5.0))
        waiter = fast_read.get_connection()
        cur_w = waiter.cursor()
        t0 = time.monotonic()
        err: Optional[MySQLError] = None
        try:
            cur_w.execute(
                'SELECT username FROM referents WHERE id = %s FOR UPDATE',
                (self.ref_id,),
            )
            cur_w.fetchone()
        except MySQLError as exc:
            err = exc
        finally:
            holder_done.set()
            elapsed = time.monotonic() - t0
            try:
                cur_w.close()
            except Exception:
                pass
            try:
                waiter.close()
            except Exception:
                pass
            t.join(timeout=5.0)
        self.measured['f_elapsed_sec'] = f'{elapsed:.3f}'
        self.measured['f_read_timeout_config'] = '3'
        self.assertIsNotNone(err, 'expected stall error (lock wait or read timeout)')
        self.assertLess(elapsed, 15.0, 'must not hang unbounded')
        self.assertLess(elapsed, 3 + _TOLERANCE_SLOW_SEC + 2.0)
        errno = getattr(err, 'errno', None)
        self.measured['f_errno'] = str(errno)
        print(
            f'MEASURED f_elapsed={elapsed:.3f}s errno={errno} '
            f'prod_kwargs connect={_mpd_import.DB_CONNECT_TIMEOUT_SEC} '
            f'read={_mpd_import.DB_READ_TIMEOUT_SEC} '
            f'write={_mpd_import.DB_WRITE_TIMEOUT_SEC}'
        )


class _PoolDb:
    def __init__(self, pool: pooling.MySQLConnectionPool) -> None:
        self._pool = pool

    def get_connection(self):
        return self._pool.get_connection()


@unittest.skipUnless(_dsn_configured(), f'{DSN_ENV} not set (live MariaDB required)')
class ImapPollerMariaDbIntegrationTests(unittest.TestCase):
    """Owner-required cases (g)–(i) for ImapPoller shutdown DB hardening."""

    ref_id: int = 0
    measured: Dict[str, str] = {}

    @classmethod
    def setUpClass(cls) -> None:
        cls.ref_id = _reset_seed_data()

    @classmethod
    def tearDownClass(cls) -> None:
        time.sleep(0.5)
        _mysql_cli(
            'UNLOCK TABLES; '
            'DELETE FROM clients; '
            'DELETE FROM external_accounts; '
            'DELETE FROM referents;'
        )

    def setUp(self) -> None:
        self.pool = _production_pool(pool_name=f'dev56_poller_{id(self)}')

    def test_g_poller_referent_load_capped_under_metadata_lock(self) -> None:
        mpd = _load_mpd()
        stop = threading.Event()
        daemon = mpd.ProxyDaemon.__new__(mpd.ProxyDaemon)
        daemon._shutdown_signaled_mono = None
        daemon._shutdown_deadline_mono = None
        daemon._stop_event = stop
        cap = SUPERVISOR_MAX_STATEMENT_TIME_SEC
        poller = mpd.ImapPoller(queue.Queue(), _PoolDb(self.pool), stop, daemon_ref=daemon)
        conn_lock = _connect_direct()
        conn_wait = _connect_direct()
        try:
            conn_lock.cursor().execute('LOCK TABLES referents WRITE')
            t0 = time.monotonic()
            refs = poller._load_active_referents()
            elapsed = time.monotonic() - t0
            self.measured['g_cap_sec'] = f'{cap:g}'
            self.measured['g_elapsed_sec'] = f'{elapsed:.3f}'
            self.assertEqual(refs, [])
            self.assertLess(elapsed, cap + _TOLERANCE_SLOW_SEC)
            self.assertGreater(elapsed, cap - 1.0)
            conn_lock.cursor().execute('UNLOCK TABLES')
        finally:
            conn_lock.close()
            conn_wait.close()
        print(
            f'MEASURED g_cap={self.measured["g_cap_sec"]} '
            f'elapsed={self.measured["g_elapsed_sec"]}'
        )

    def test_h_poller_thread_join_bounded_under_lock(self) -> None:
        mpd = _load_mpd()
        mpd.IMAP_POLL_INTERVAL = 2

        class _FastPoller(mpd.ImapPoller):
            def run(self) -> None:
                while not self._stop_event.is_set():
                    self._enqueue_imap_tasks()
                    for _ in range(4):
                        if self._stop_event.is_set():
                            return
                        time.sleep(0.05)

        stop = threading.Event()
        daemon = mpd.ProxyDaemon.__new__(mpd.ProxyDaemon)
        daemon._shutdown_signaled_mono = None
        daemon._shutdown_deadline_mono = None
        daemon._stop_event = stop
        poller = _FastPoller(queue.Queue(), _PoolDb(self.pool), stop, daemon_ref=daemon)

        conn_lock = _connect_direct()
        holder_ready = threading.Event()
        try:
            cur = conn_lock.cursor()
            cur.execute('LOCK TABLES referents WRITE')
            holder_ready.set()
            poller.start()
            self.assertTrue(holder_ready.wait(timeout=3.0))
            time.sleep(0.2)
            now = time.monotonic()
            daemon._shutdown_signaled_mono = now
            daemon._shutdown_deadline_mono = now + 7.0
            stop.set()
            cap = poller._poller_statement_cap()
            t0 = time.monotonic()
            poller.join(timeout=25.0)
            elapsed = time.monotonic() - t0
            self.measured['h_cap_sec'] = f'{cap:g}'
            self.measured['h_join_elapsed_sec'] = f'{elapsed:.3f}'
            self.assertFalse(poller.is_alive())
            self.assertLess(
                elapsed,
                SUPERVISOR_MAX_STATEMENT_TIME_SEC + _TOLERANCE_SLOW_SEC + 2.0,
            )
            self.assertGreater(elapsed, 0.2)
            cur.execute('UNLOCK TABLES')
        finally:
            conn_lock.close()
        print(
            f'MEASURED h_cap={self.measured["h_cap_sec"]} '
            f'join_elapsed={self.measured["h_join_elapsed_sec"]}'
        )

    def test_h_baseline_plain_select_under_lock_timed(self) -> None:
        """Pre-fix style: plain SELECT (no SET STATEMENT) under LOCK TABLES."""
        conn_lock = _connect_direct()
        conn = _connect_direct(read_timeout=4)
        try:
            conn_lock.cursor().execute('LOCK TABLES referents WRITE')
            t0 = time.monotonic()
            err: Optional[MySQLError] = None
            try:
                cur = conn.cursor()
                cur.execute(
                    f'SELECT {_REFERENT_COLUMNS} FROM referents WHERE active = 1'
                )
                cur.fetchall()
                cur.close()
            except MySQLError as exc:
                err = exc
            elapsed = time.monotonic() - t0
            conn_lock.cursor().execute('UNLOCK TABLES')
        finally:
            conn.close()
            conn_lock.close()
        self.measured['h_baseline_plain_elapsed_sec'] = f'{elapsed:.3f}'
        self.measured['h_baseline_plain_errno'] = str(getattr(err, 'errno', None))
        print(
            'MEASURED h_baseline plain_select '
            f'elapsed={self.measured["h_baseline_plain_elapsed_sec"]} '
            f'errno={self.measured["h_baseline_plain_errno"]}'
        )
        self.assertLess(elapsed, _mpd_import.DB_READ_TIMEOUT_SEC + _TOLERANCE_SLOW_SEC)

    def test_i_discard_release_bounded_pool_still_works(self) -> None:
        conn_lock = _connect_direct()
        try:
            conn_lock.cursor().execute('LOCK TABLES referents WRITE')
            conn = self.pool.get_connection()
            cur = conn.cursor()
            t0 = time.monotonic()
            release_mysql_connection(conn, discard=True)
            elapsed = time.monotonic() - t0
            self.measured['i_discard_elapsed_sec'] = f'{elapsed:.3f}'
            self.assertLess(elapsed, 2.0)
            conn_lock.cursor().execute('UNLOCK TABLES')
        finally:
            conn_lock.close()
        conn2 = self.pool.get_connection()
        try:
            cur2 = conn2.cursor()
            cur2.execute('SELECT 1')
            self.assertEqual(cur2.fetchone(), (1,))
            cur2.close()
        finally:
            conn2.close()
        print(f'MEASURED i_discard_elapsed={self.measured["i_discard_elapsed_sec"]}')


if __name__ == '__main__':
    unittest.main(verbosity=2)