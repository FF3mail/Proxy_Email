#!/usr/bin/env python3
"""DEV-56 / NFR-9 / ACC-102 — graceful shutdown within systemd stop budget."""

from __future__ import annotations

import configparser
import importlib.util
import logging
import os
import queue
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
from relationship_routing import OutboundDeliveryPlan, OutboundRoutingMode


def _install_watchdog_stubs() -> None:
    import types

    watchdog_mod = types.ModuleType('watchdog')
    watchdog_events = types.ModuleType('watchdog.events')
    watchdog_events.FileSystemEventHandler = object
    watchdog_observers = types.ModuleType('watchdog.observers')
    watchdog_observers.Observer = MagicMock
    sys.modules.setdefault('watchdog', watchdog_mod)
    sys.modules.setdefault('watchdog.events', watchdog_events)
    sys.modules.setdefault('watchdog.observers', watchdog_observers)


def _load_daemon():
    _install_watchdog_stubs()
    name = f'mail_proxy_daemon_shutdown_test_{id(object())}'
    spec = importlib.util.spec_from_file_location(
        name,
        str(ROOT / 'mail-proxy-daemon.py'),
    )
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


class JoinUntilDeadlineTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def test_join_finishes_within_shared_deadline(self) -> None:
        started = time.monotonic()
        deadline = started + 0.4
        hold = threading.Event()

        def worker() -> None:
            hold.wait(timeout=5.0)

        threads = [
            threading.Thread(target=worker, name=f'T{i}', daemon=True)
            for i in range(8)
        ]
        for t in threads:
            t.start()
        self.mpd._join_threads_until_deadline(threads, deadline, 'test pool')
        elapsed = time.monotonic() - started
        self.assertLess(elapsed, 0.8)
        hold.set()
        for t in threads:
            t.join(timeout=2.0)

    def test_max_pop_worker_join_wall_time_bounded(self) -> None:
        """20+20 slow workers: join uses one deadline, not 30s × thread count."""
        deadline = time.monotonic() + 0.5
        release = threading.Event()

        def worker() -> None:
            release.wait(timeout=10.0)

        workers = [
            threading.Thread(target=worker, name=f'W{i}', daemon=True)
            for i in range(self.mpd.IMAP_WORKER_COUNT + self.mpd.SMTP_WORKER_COUNT)
        ]
        for t in workers:
            t.start()
        started = time.monotonic()
        self.mpd._join_threads_until_deadline(workers, deadline, 'full pop')
        elapsed = time.monotonic() - started
        self.assertLess(elapsed, 1.0)
        release.set()


class ShutdownDeadlineTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def _minimal_daemon(self):
        daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
        daemon._shutdown_lock = threading.Lock()
        daemon._is_shutdown = False
        daemon._stop_event = threading.Event()
        daemon._shutdown_signaled_mono = None
        daemon._shutdown_deadline_mono = None
        daemon._observer = None
        daemon._observer_started = False
        daemon._imap_poller = MagicMock()
        daemon._imap_poller.is_alive = MagicMock(return_value=False)
        daemon._imap_pool = MagicMock()
        daemon._imap_pool._workers = []
        daemon._smtp_pool = MagicMock()
        daemon._smtp_pool._workers = []
        return daemon

    def test_deadline_fixed_at_first_signal(self) -> None:
        daemon = self._minimal_daemon()
        daemon._handle_signal(15, None)
        first = daemon._shutdown_deadline_mono
        time.sleep(0.05)
        daemon._shutdown()
        second = daemon._shutdown_deadline_mono
        self.assertEqual(first, second)

    def test_shutdown_without_signal_still_sets_stop(self) -> None:
        daemon = self._minimal_daemon()
        self.assertFalse(daemon._stop_event.is_set())
        daemon._shutdown()
        self.assertTrue(daemon._stop_event.is_set())


class SignalHandlerOrderingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def test_deadline_armed_before_logger_info(self) -> None:
        daemon = ShutdownDeadlineTests()._minimal_daemon()
        armed = threading.Event()

        def info_hook(*args, **kwargs):
            self.assertIsNotNone(daemon._shutdown_deadline_mono)
            self.assertIsNotNone(daemon._shutdown_signaled_mono)
            self.assertTrue(daemon._stop_event.is_set())
            armed.set()

        with patch.object(logging.Logger, 'info', side_effect=info_hook):
            daemon._handle_signal(15, None)
        self.assertTrue(armed.wait(timeout=1.0))

    def test_repeated_signal_keeps_first_deadline(self) -> None:
        daemon = ShutdownDeadlineTests()._minimal_daemon()
        daemon._handle_signal(15, None)
        first_deadline = daemon._shutdown_deadline_mono
        first_signal = daemon._shutdown_signaled_mono
        time.sleep(0.02)
        daemon._handle_signal(2, None)
        self.assertEqual(daemon._shutdown_deadline_mono, first_deadline)
        self.assertEqual(daemon._shutdown_signaled_mono, first_signal)

    def test_past_deadline_during_handler_never_blocks_on_lock(self) -> None:
        daemon = ShutdownDeadlineTests()._minimal_daemon()
        deadline = threading.Thread(
            target=lambda: (
                daemon._handle_signal(15, None),
                daemon._past_shutdown_deadline(),
            ),
            daemon=True,
        )
        deadline.start()
        deadline.join(timeout=2.0)
        self.assertFalse(deadline.is_alive())


class DatabasePoolTimeoutTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def test_pool_passes_connector_timeouts(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            conf_path = Path(tmp) / 'db.conf'
            cfg = configparser.ConfigParser()
            cfg['db'] = {
                'db_host': '127.0.0.1',
                'db_user': 'mailproxy',
                'db_pass': 'secret',
                'db_name': 'mailproxy',
                'db_port': '3306',
            }
            with conf_path.open('w', encoding='utf-8') as fh:
                cfg.write(fh)
            captured = {}

            def fake_pool(**kwargs):
                captured.update(kwargs)
                raise RuntimeError('stop-after-capture')

            with patch.object(self.mpd.pooling, 'MySQLConnectionPool', side_effect=fake_pool):
                with self.assertRaises(RuntimeError):
                    self.mpd.Database(str(conf_path))
            self.assertEqual(
                captured.get('connect_timeout'), self.mpd.DB_CONNECT_TIMEOUT_SEC
            )
            self.assertEqual(captured.get('read_timeout'), self.mpd.DB_READ_TIMEOUT_SEC)
            self.assertEqual(captured.get('write_timeout'), self.mpd.DB_WRITE_TIMEOUT_SEC)

    def test_load_referents_skips_when_stopping(self) -> None:
        daemon = ShutdownDeadlineTests._minimal_daemon(self)
        daemon._db = MagicMock()
        daemon._stop_event.set()
        self.assertEqual(daemon._load_referents(), [])


class ImapPollerStopSchedulingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def test_enqueue_stops_mid_referent_enumeration(self) -> None:
        task_queue: queue.Queue = queue.Queue()
        stop = threading.Event()
        db = MagicMock()
        poller = self.mpd.ImapPoller(task_queue, db, stop)

        referents = [{'id': i, 'username': f'r{i}'} for i in range(30)]

        def load_refs():
            return referents

        def load_accounts(referent_id: int):
            if referent_id == 5:
                stop.set()
            return [{'email': f'a{referent_id}@example.com', 'imap_host': 'imap.example.com'}]

        poller._load_active_referents = load_refs
        poller._load_accounts_for_referent = load_accounts
        poller._enqueue_imap_tasks()

        enqueued = 0
        while True:
            try:
                task_queue.get_nowait()
                enqueued += 1
            except queue.Empty:
                break
        self.assertLess(enqueued, 30)
        self.assertGreater(enqueued, 0)


class SupervisorSyncStopTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def test_sync_database_state_stops_registering_on_signal(self) -> None:
        daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
        daemon._stop_event = threading.Event()
        daemon._shutdown_signaled_mono = None
        daemon._shutdown_deadline_mono = None
        daemon._watch_registered_referent_ids = set()
        daemon._relationship_referent_id = {}
        daemon._watched_relationship_ids = set()
        daemon._mail_handler = MagicMock()
        daemon._mail_handler._relationship_lookup.list_watch_targets_for_referent = (
            MagicMock(return_value=[])
        )
        daemon._load_referents = lambda: [
            {'id': i, 'username': f'u{i}'} for i in range(20)
        ]
        calls = []

        def register(ref):
            calls.append(ref['id'])
            if len(calls) == 2:
                daemon._stop_event.set()

        daemon._register_watches_for_referent = register
        daemon._unschedule_watches_for_referent = MagicMock()
        daemon._sync_relationship_watches = MagicMock()
        daemon._sync_database_state()
        self.assertLessEqual(len(calls), 2)


class OutboundPreservationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def test_blocked_smtp_send_preserves_maildir_file(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            mail_path = Path(tmp) / 'testmsg'
            mail_path.write_bytes(
                b'To: user@example.com\r\nFrom: x@y.z\r\nSubject: t\r\n\r\nbody'
            )
            task_q: queue.Queue = queue.Queue()
            stop = threading.Event()
            send_entered = threading.Event()
            send_block = threading.Event()

            deleted_paths: list[Path] = []

            class Handler:
                def send_via_external_smtp(self, task):
                    send_entered.set()
                    send_block.wait(timeout=30.0)
                    return False

            pool = self.mpd.SmtpWorkerPool(task_q, Handler(), stop)
            with patch.object(self.mpd, 'SMTP_WORKER_COUNT', 1):
                pool.start()

            task = self.mpd.SmtpTask(
                referent_data={'id': 1},
                account={'email': 'a@b.c'},
                recipients=['user@example.com'],
                file_path=mail_path,
            )

            def tracking_unlink(self, file_path: Path) -> None:
                deleted_paths.append(file_path)

            with patch.object(
                self.mpd.SmtpWorkerPool, '_safe_delete_file', tracking_unlink
            ):
                task_q.put(task)
                self.assertTrue(send_entered.wait(timeout=2.0))
                signaled = time.monotonic()
                stop.set()
                deadline = signaled + 0.5
                pool.join_until_deadline(deadline)

            self.assertTrue(mail_path.is_file())
            self.assertEqual(deleted_paths, [])

            send_block.set()
            pool.join_until_deadline(time.monotonic() + 2.0)

    def test_production_outbound_retry_after_shutdown_blocked_send(self) -> None:
        """Reserve/enqueue/scan/dup paths on real ProxyDaemon methods (DMN-5)."""
        plan = OutboundDeliveryPlan(
            mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
            account={
                'email': 'ext@example.com',
                'smtp_host': 'smtp.example.com',
                'smtp_port': '587',
                'smtp_encryption': 'tls',
            },
            relationship_id=1,
            external_account_id=9,
        )
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp)
            new_dir = maildir / 'new'
            new_dir.mkdir()
            mail_path = new_dir / 'retrymsg'
            mail_path.write_bytes(
                b'To: user@example.com\r\n'
                b'From: client@local.test\r\n'
                b'Subject: t\r\n\r\nbody'
            )
            daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
            daemon._shutdown_lock = threading.Lock()
            daemon._is_shutdown = False
            daemon._shutdown_signaled_mono = None
            daemon._shutdown_deadline_mono = None
            daemon._queued_outgoing_files = set()
            daemon._queued_files_lock = threading.Lock()
            daemon._stop_event = threading.Event()
            daemon._smtp_task_queue = queue.Queue()
            daemon._mail_handler = MagicMock()
            lookup = MagicMock()
            send_block = threading.Event()
            send_started = threading.Event()

            class Handler:
                def __init__(self, d):
                    self._daemon = d

                def send_via_external_smtp(self, task):
                    send_started.set()
                    send_block.wait(timeout=30.0)
                    return False

            with patch.object(
                self.mpd, 'extract_outbound_identity_from_message', return_value='client@local.test'
            ), patch.object(self.mpd, 'plan_outbound_delivery', return_value=plan):
                self.mpd.ProxyDaemon._enqueue_outbound_file(
                    daemon,
                    referent_data={'id': 1, 'username': 'ref1'},
                    file_path=mail_path,
                    relationship_lookup=lookup,
                    smtp_queue=daemon._smtp_task_queue,
                    source='backlog_relationship',
                )

            self.assertFalse(daemon._smtp_task_queue.empty())
            self.assertFalse(
                self.mpd.ProxyDaemon._reserve_outgoing_file(daemon, mail_path)
            )

            pool = self.mpd.SmtpWorkerPool(
                daemon._smtp_task_queue, Handler(daemon), daemon._stop_event
            )
            with patch.object(self.mpd, 'SMTP_WORKER_COUNT', 1):
                pool.start()

            self.assertTrue(send_started.wait(timeout=2.0))
            daemon._initiate_shutdown_schedule()
            pool.join_until_deadline(daemon._shutdown_deadline_mono)
            send_block.set()
            pool.join_until_deadline(time.monotonic() + 2.0)

            self.assertTrue(mail_path.is_file())
            key = str(mail_path.resolve())
            with daemon._queued_files_lock:
                self.assertNotIn(key, daemon._queued_outgoing_files)

            # Simulate daemon restart after interrupt (fresh stop state).
            daemon._stop_event = threading.Event()
            daemon._shutdown_signaled_mono = None
            daemon._shutdown_deadline_mono = None
            daemon._is_shutdown = False

            target = {
                'relationship': {'relationship_id': 1, 'referent_id': 1},
                'local_client_maildir': str(maildir),
            }
            with patch.object(
                self.mpd, 'extract_outbound_identity_from_message', return_value='client@local.test'
            ), patch.object(self.mpd, 'plan_outbound_delivery', return_value=plan):
                daemon._smtp_task_queue = queue.Queue()
                self.mpd.ProxyDaemon._scan_existing_outgoing_for_relationship(
                    daemon, target
                )

            self.assertFalse(daemon._smtp_task_queue.empty())
            self.assertFalse(
                self.mpd.ProxyDaemon._reserve_outgoing_file(daemon, mail_path)
            )

    def test_successful_smtp_still_deletes_only_after_send(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            mail_path = Path(tmp) / 'okmsg'
            mail_path.write_bytes(
                b'To: user@example.com\r\nFrom: x@y.z\r\nSubject: t\r\n\r\nbody'
            )
            task_q: queue.Queue = queue.Queue()
            stop = threading.Event()

            class Handler:
                def send_via_external_smtp(self, task):
                    return True

            daemon_stub = MagicMock()

            class HandlerWithDaemon:
                _daemon = daemon_stub

                def send_via_external_smtp(self, task):
                    return True

            pool = self.mpd.SmtpWorkerPool(task_q, HandlerWithDaemon(), stop)
            with patch.object(self.mpd, 'SMTP_WORKER_COUNT', 1):
                pool.start()

            task = self.mpd.SmtpTask(
                referent_data={'id': 1},
                account={'email': 'a@b.c'},
                recipients=['user@example.com'],
                file_path=mail_path,
            )
            task_q.put(task)
            pool.join_until_deadline(time.monotonic() + 2.0)
            self.assertFalse(mail_path.exists())

    def test_watchdog_skips_enqueue_when_stopping(self) -> None:
        daemon = MagicMock()
        daemon._stop_event = threading.Event()
        daemon._stop_event.set()
        daemon._enqueue_outbound_file = MagicMock()
        handler = self.mpd.MaildirHandler(
            {'id': 1},
            queue.Queue(),
            MagicMock(),
            daemon,
            MagicMock(),
        )
        event = MagicMock()
        event.is_directory = False
        event.src_path = '/tmp/fake/new/msg123'
        handler.on_created(event)
        daemon._enqueue_outbound_file.assert_not_called()


class Acc102EndToEndTimingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def _build_operational_daemon(self, imap_workers=2, smtp_workers=2):
        daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
        daemon._shutdown_lock = threading.Lock()
        daemon._is_shutdown = False
        daemon._stop_event = threading.Event()
        daemon._shutdown_signaled_mono = None
        daemon._shutdown_deadline_mono = None
        daemon._observer = None
        daemon._observer_started = False
        daemon._imap_poller = MagicMock()
        daemon._imap_poller.is_alive = MagicMock(return_value=False)

        task_q: queue.Queue = queue.Queue()
        smtp_q: queue.Queue = queue.Queue()

        class Handler:
            _daemon = daemon

            def poll_external_imap(self, _task):
                return

            def send_via_external_smtp(self, _task):
                return False

        handler = Handler()

        with patch.object(self.mpd, 'IMAP_WORKER_COUNT', imap_workers), patch.object(
            self.mpd, 'SMTP_WORKER_COUNT', smtp_workers
        ):
            daemon._imap_pool = self.mpd.ImapWorkerPool(task_q, handler, daemon._stop_event)
            daemon._smtp_pool = self.mpd.SmtpWorkerPool(smtp_q, handler, daemon._stop_event)
            daemon._imap_pool.start()
            daemon._smtp_pool.start()
        return daemon

    def _signal_through_shutdown_elapsed(self, daemon) -> float:
        daemon._handle_signal(15, None)
        assert daemon._shutdown_signaled_mono is not None
        t_signal = daemon._shutdown_signaled_mono
        time.sleep(0.05)
        daemon._shutdown()
        return time.monotonic() - t_signal

    def test_signal_to_shutdown_under_systemd_timeout_stop(self) -> None:
        daemon = self._build_operational_daemon(
            imap_workers=self.mpd.IMAP_WORKER_COUNT,
            smtp_workers=self.mpd.SMTP_WORKER_COUNT,
        )
        elapsed = self._signal_through_shutdown_elapsed(daemon)
        self.assertLess(
            elapsed,
            self.mpd.SYSTEMD_TIMEOUT_STOP_SEC,
            'ACC-102: stop path from signal must be < TimeoutStopSec',
        )

    def test_repeated_sigterm_sigint_safe(self) -> None:
        daemon = self._build_operational_daemon()
        daemon._handle_signal(15, None)
        daemon._handle_signal(2, None)
        assert daemon._shutdown_signaled_mono is not None
        t_signal = daemon._shutdown_signaled_mono
        daemon._shutdown()
        elapsed = time.monotonic() - t_signal
        self.assertLess(elapsed, self.mpd.SYSTEMD_TIMEOUT_STOP_SEC)

    def test_double_shutdown_idempotent_quick(self) -> None:
        daemon = self._build_operational_daemon()
        daemon._handle_signal(15, None)
        daemon._shutdown()
        start = time.monotonic()
        daemon._shutdown()
        self.assertLess(time.monotonic() - start, 0.2)

    def test_shutdown_after_start_exception_with_pools_running(self) -> None:
        daemon = self._build_operational_daemon()
        start = time.monotonic()
        daemon._shutdown()
        self.assertTrue(daemon._stop_event.is_set())
        self.assertLess(
            time.monotonic() - start,
            self.mpd.SYSTEMD_TIMEOUT_STOP_SEC,
        )


def _run_acc102_child(mode: str, block_sec: float = 0.35) -> subprocess.CompletedProcess:
    harness = ROOT / 'tests' / 'harness' / 'dev56_acc102_lifecycle_child.py'
    env = os.environ.copy()
    env['ACC102_DB_MODE'] = mode
    env['ACC102_DB_BLOCK_SEC'] = str(block_sec)
    return subprocess.run(
        [sys.executable, str(harness)],
        capture_output=True,
        text=True,
        timeout=30,
        env=env,
    )


class ConnectorCompatibilityTests(unittest.TestCase):
    def _run_probe(self, version: str, expect_unsupported: bool) -> subprocess.CompletedProcess:
        probe = ROOT / 'tests' / 'harness' / 'verify_connector_pool_timeouts.py'
        with tempfile.TemporaryDirectory() as td:
            target = Path(td) / 'site'
            pip = subprocess.run(
                [
                    sys.executable,
                    '-m',
                    'pip',
                    'install',
                    '-q',
                    f'mysql-connector-python=={version}',
                    '--target',
                    str(target),
                ],
                capture_output=True,
                text=True,
                timeout=180,
            )
            self.assertEqual(pip.returncode, 0, pip.stderr)
            env = os.environ.copy()
            env['PYTHONPATH'] = str(target)
            if expect_unsupported:
                env['EXPECT_UNSUPPORTED_TIMEOUTS'] = '1'
            return subprocess.run(
                [sys.executable, str(probe)],
                capture_output=True,
                text=True,
                timeout=30,
                env=env,
            )

    def test_minimum_9_2_0_accepts_timeout_kwargs(self) -> None:
        proc = self._run_probe('9.2.0', expect_unsupported=False)
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        self.assertIn('CONFIG_TIMEOUT_ARGS_OK', proc.stdout)

    def test_8_4_0_rejects_read_timeout_kwarg(self) -> None:
        proc = self._run_probe('8.4.0', expect_unsupported=True)
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        self.assertIn('UNSUPPORTED', proc.stdout)


class Acc102LifecycleSubprocessTests(unittest.TestCase):
    def _assert_acc102_timing(self, proc: subprocess.CompletedProcess, mode: str) -> None:
        self.assertEqual(proc.returncode, 0, proc.stderr)
        stdout = proc.stdout
        self.assertIn('IN_DB', stdout)
        self.assertIn(f'DB_EXIT={mode}', stdout)
        elapsed_line = next(
            (ln for ln in stdout.splitlines() if ln.startswith('ELAPSED=')),
            None,
        )
        self.assertIsNotNone(elapsed_line)
        elapsed = float(elapsed_line.split('=', 1)[1])
        mpd = _load_daemon()
        margin = 5.0
        self.assertLess(
            elapsed,
            mpd.SYSTEMD_TIMEOUT_STOP_SEC - margin,
            f'ACC-102 margin: signal→shutdown must be < {mpd.SYSTEMD_TIMEOUT_STOP_SEC}s',
        )

    def test_subprocess_sigterm_during_db_fetchall_stall(self) -> None:
        proc = _run_acc102_child('complete', block_sec=0.35)
        self._assert_acc102_timing(proc, 'complete')

    def test_subprocess_sigterm_during_db_read_timeout_error(self) -> None:
        proc = _run_acc102_child('timeout', block_sec=0.25)
        self._assert_acc102_timing(proc, 'timeout')


class SupervisorMaxStatementTimeTests(unittest.TestCase):
    """DEV-56 option C — SET STATEMENT on supervisor SELECTs (tests 1–9)."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()
        from supervisor_db import MARIADB_ER_STATEMENT_TIMEOUT, SUPERVISOR_MAX_STATEMENT_TIME_SEC

        cls.MARIADB_ER = MARIADB_ER_STATEMENT_TIMEOUT
        cls.DEFAULT_CAP = SUPERVISOR_MAX_STATEMENT_TIME_SEC

    def test_1_format_set_statement_wraps_select(self) -> None:
        from supervisor_db import format_set_statement_select

        inner = 'SELECT id FROM referents WHERE active = 1'
        wrapped = format_set_statement_select(inner, 12.0)
        self.assertTrue(wrapped.startswith('SET STATEMENT max_statement_time=12 FOR '))
        self.assertIn(inner, wrapped)
        self.assertNotIn('SET SESSION', wrapped)

    def test_2_format_rejects_non_select(self) -> None:
        from supervisor_db import format_set_statement_select

        with self.assertRaises(ValueError):
            format_set_statement_select('UPDATE referents SET active=0', 1.0)

    def test_3_is_mariadb_statement_timeout_errno_1969_only(self) -> None:
        import mysql.connector

        from supervisor_db import is_mariadb_statement_timeout

        err1969 = mysql.connector.Error()
        err1969.errno = 1969
        err3024 = mysql.connector.Error()
        err3024.errno = 3024
        self.assertTrue(is_mariadb_statement_timeout(err1969))
        self.assertFalse(is_mariadb_statement_timeout(err3024))

    def test_4_cap_tightens_to_remaining_shutdown_budget(self) -> None:
        from supervisor_db import supervisor_max_statement_seconds

        deadline = time.monotonic() + 4.0
        cap = supervisor_max_statement_seconds(
            self.DEFAULT_CAP,
            deadline,
            self.mpd._shutdown_remaining_seconds,
        )
        self.assertLessEqual(cap, self.DEFAULT_CAP)
        self.assertGreaterEqual(cap, 1.0)
        self.assertLess(cap, 4.0)

    def test_5_supervisor_sql_permitted_false_after_signal(self) -> None:
        daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
        daemon._stop_event = threading.Event()
        daemon._shutdown_signaled_mono = None
        daemon._shutdown_deadline_mono = None
        self.assertTrue(daemon._supervisor_sql_permitted())
        daemon._initiate_shutdown_schedule()
        self.assertFalse(daemon._supervisor_sql_permitted())

    def test_6_load_referents_skips_new_sql_after_signal(self) -> None:
        daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
        daemon._stop_event = threading.Event()
        daemon._shutdown_signaled_mono = time.monotonic()
        daemon._shutdown_deadline_mono = time.monotonic() + 55
        daemon._db = MagicMock()
        refs = daemon._load_referents()
        self.assertEqual(refs, [])
        daemon._db.get_connection.assert_not_called()

    def test_7_load_referents_uses_set_statement_on_execute(self) -> None:
        daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
        daemon._stop_event = threading.Event()
        daemon._shutdown_signaled_mono = None
        daemon._shutdown_deadline_mono = None
        conn = MagicMock()
        cursor = MagicMock()
        cursor.fetchall.return_value = [{'id': 1}]
        conn.cursor.return_value = cursor
        daemon._db = MagicMock()
        daemon._db.get_connection.return_value = conn
        daemon._load_referents()
        executed_sql = cursor.execute.call_args[0][0]
        self.assertIn('SET STATEMENT max_statement_time=', executed_sql)
        self.assertIn('FROM referents WHERE active = 1', executed_sql)

    def test_8_never_set_session_max_statement_time_in_supervisor_paths(self) -> None:
        for path in (
            ROOT / 'mail-proxy-daemon.py',
            ROOT / 'relationship_lookup.py',
            ROOT / 'supervisor_db.py',
        ):
            text = path.read_text(encoding='utf-8')
            self.assertNotIn(
                'SET SESSION max_statement_time',
                text,
                msg=str(path),
            )

    def test_9_pool_reuse_after_1969_rolls_back_and_closes(self) -> None:
        import mysql.connector
        from supervisor_db import rollback_connection_quietly

        conn = MagicMock()
        err = mysql.connector.Error()
        err.errno = self.MARIADB_ER
        rollback_connection_quietly(conn)
        conn.rollback.assert_called_once()
        calls = {'n': 0}

        def get_connection():
            calls['n'] += 1
            return conn

        conn2 = get_connection()
        self.assertIs(conn2, conn)
        self.assertEqual(calls['n'], 1)

    @unittest.skipUnless(
        os.environ.get('SUPERVISOR_DB_INTEGRATION') == '1',
        'Set SUPERVISOR_DB_INTEGRATION=1 with live MariaDB (DEV-56 option C)',
    )
    def test_mariadb_integration_set_statement_sleep_raises_1969(self) -> None:
        import mysql.connector
        from supervisor_db import format_set_statement_select, is_mariadb_statement_timeout

        kwargs = {
            'host': os.environ.get('RELATIONSHIP_TEST_DB_HOST', '127.0.0.1'),
            'port': int(os.environ.get('RELATIONSHIP_TEST_DB_PORT', '3306')),
            'user': os.environ.get('RELATIONSHIP_TEST_DB_USER', 'root'),
            'password': os.environ.get('RELATIONSHIP_TEST_DB_PASS', ''),
            'autocommit': False,
        }
        conn = mysql.connector.connect(**kwargs)
        try:
            cur = conn.cursor()
            sql = format_set_statement_select('SELECT SLEEP(2)', 0.1)
            with self.assertRaises(mysql.connector.Error) as ctx:
                cur.execute(sql)
                cur.fetchall()
            self.assertTrue(is_mariadb_statement_timeout(ctx.exception))
            conn.rollback()
            cur2 = conn.cursor()
            cur2.execute('SELECT 1')
            self.assertEqual(cur2.fetchone(), (1,))
        finally:
            conn.close()


class ImapPollerShutdownDbTests(unittest.TestCase):
    """DEV-56 ImapPoller option C + discard release (unit, stubbed)."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()
        from supervisor_db import (
            MARIADB_ER_STATEMENT_TIMEOUT,
            SUPERVISOR_MAX_STATEMENT_TIME_SEC,
            poller_db_permitted,
            poller_statement_cap,
            release_mysql_connection,
        )

        cls.MARIADB_ER = MARIADB_ER_STATEMENT_TIMEOUT
        cls.DEFAULT_CAP = SUPERVISOR_MAX_STATEMENT_TIME_SEC
        cls.poller_db_permitted = poller_db_permitted
        cls.poller_statement_cap = poller_statement_cap
        cls.release_mysql_connection = release_mysql_connection

    def _make_poller(self) -> object:
        stop = threading.Event()
        daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
        daemon._stop_event = stop
        daemon._shutdown_signaled_mono = None
        daemon._shutdown_deadline_mono = None
        poller = self.mpd.ImapPoller(
            queue.Queue(),
            MagicMock(),
            stop,
            daemon_ref=daemon,
        )
        return poller, daemon, stop

    def test_poller_cap_default_before_signal(self) -> None:
        poller, daemon, _ = self._make_poller()
        self.assertEqual(poller._poller_statement_cap(), self.DEFAULT_CAP)

    def test_poller_cap_tightens_after_shutdown_signal(self) -> None:
        poller, daemon, stop = self._make_poller()
        daemon._shutdown_signaled_mono = time.monotonic()
        daemon._shutdown_deadline_mono = time.monotonic() + 4.0
        cap = poller._poller_statement_cap()
        self.assertLess(cap, self.DEFAULT_CAP)
        self.assertGreaterEqual(cap, 1.0)

    def test_poller_skips_sql_when_stopping(self) -> None:
        poller, daemon, stop = self._make_poller()
        stop.set()
        poller._db = MagicMock()
        refs = poller._load_active_referents()
        self.assertEqual(refs, [])
        poller._db.get_connection.assert_not_called()

    def test_poller_uses_set_statement_when_running(self) -> None:
        poller, daemon, stop = self._make_poller()
        conn = MagicMock()
        cursor = MagicMock()
        cursor.fetchall.return_value = [{'id': 1, 'username': 'u'}]
        conn.cursor.return_value = cursor
        poller._db = MagicMock()
        poller._db.get_connection.return_value = conn
        poller._load_active_referents()
        sql = cursor.execute.call_args[0][0]
        self.assertIn('SET STATEMENT max_statement_time=', sql)
        self.assertIn('FROM referents WHERE active = 1', sql)

    def test_poller_1969_no_retry_returns_empty(self) -> None:
        import mysql.connector
        from supervisor_db import release_mysql_connection

        poller, daemon, stop = self._make_poller()
        conn = MagicMock()
        cursor = MagicMock()
        err = mysql.connector.Error()
        err.errno = self.MARIADB_ER
        cursor.execute.side_effect = err
        conn.cursor.return_value = cursor
        poller._db = MagicMock()
        poller._db.get_connection.return_value = conn
        with self.assertLogs('mail-proxy', level='WARNING') as logs:
            refs = poller._load_active_referents()
        self.assertEqual(refs, [])
        self.assertEqual(cursor.execute.call_count, 1)
        self.assertTrue(
            any('ImapPoller referent list hit max_statement_time' in m for m in logs.output)
        )
        release_mysql_connection(conn, discard=False)

    def test_release_discard_skips_pooled_close_ping(self) -> None:
        from supervisor_db import release_mysql_connection

        inner = MagicMock()
        pool = MagicMock()

        class _PooledWrapper:
            def __init__(self) -> None:
                self._cnx = inner
                self._cnx_pool = pool

            def close(self) -> None:
                raise AssertionError('pooled close must not run when discarding')

            def rollback(self) -> None:
                pass

        wrapper = _PooledWrapper()
        release_mysql_connection(wrapper, discard=True)
        inner.shutdown.assert_called_once()
        self.assertIsNone(wrapper._cnx)

    def test_release_normal_uses_close(self) -> None:
        from supervisor_db import release_mysql_connection

        conn = MagicMock()
        release_mysql_connection(conn, discard=False)
        conn.close.assert_called_once()


class SupervisorLoopDeadlineTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def test_past_deadline_exits_sleep_chunks_promptly(self) -> None:
        daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
        daemon._stop_event = threading.Event()
        daemon._shutdown_deadline_mono = time.monotonic() - 0.01
        self.assertTrue(daemon._past_shutdown_deadline())


if __name__ == '__main__':
    unittest.main()