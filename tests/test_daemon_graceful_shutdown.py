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