#!/usr/bin/env python3
"""DEV-56 / NFR-9 / ACC-102 — graceful shutdown within systemd stop budget."""

from __future__ import annotations

import importlib.util
import queue
import sys
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent


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
        """Parallel slow threads: wall time ≤ budget, not per-thread timeout sum."""
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
        self.assertLess(elapsed, 0.8, 'join must not exceed shutdown deadline by much')
        hold.set()
        for t in threads:
            t.join(timeout=2.0)

    def test_join_completes_when_threads_exit_early(self) -> None:
        done = threading.Event()

        def worker() -> None:
            done.wait(timeout=2.0)

        t = threading.Thread(target=worker, daemon=True)
        t.start()
        done.set()
        deadline = time.monotonic() + 2.0
        self.mpd._join_threads_until_deadline([t], deadline, 'fast exit')
        self.assertFalse(t.is_alive())


class WorkerPoolShutdownTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def test_worker_stops_dequeuing_after_stop_event(self) -> None:
        task_queue: queue.Queue = queue.Queue()
        stop = threading.Event()
        processed = []

        class Handler:
            def poll_external_imap(self, task) -> None:
                processed.append(1)
                time.sleep(0.05)

        pool = self.mpd.ImapWorkerPool(task_queue, Handler(), stop)
        with patch.object(self.mpd, 'IMAP_WORKER_COUNT', 2):
            pool.start()

        task_queue.put(self.mpd.ImapTask(referent_data={}, account={'email': 'a@b.c'}))
        time.sleep(0.15)
        stop.set()
        deadline = time.monotonic() + 2.0
        pool.join_until_deadline(deadline)

        task_queue.put(self.mpd.ImapTask(referent_data={}, account={'email': 'x@y.z'}))
        time.sleep(0.2)
        self.assertLessEqual(len(processed), 2)


class ProxyDaemonShutdownIntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.mpd = _load_daemon()

    def _build_minimal_daemon(self):
        daemon = self.mpd.ProxyDaemon.__new__(self.mpd.ProxyDaemon)
        daemon._shutdown_lock = threading.Lock()
        daemon._is_shutdown = False
        daemon._stop_event = threading.Event()
        daemon._observer = None
        daemon._observer_started = False
        daemon._imap_poller = MagicMock()
        daemon._imap_poller.is_alive = MagicMock(return_value=False)

        task_q = queue.Queue()
        smtp_q = queue.Queue()
        stop = daemon._stop_event
        slow_ready = threading.Event()

        class Handler:
            _daemon = daemon

            def poll_external_imap(self, _task) -> None:
                slow_ready.set()
                time.sleep(0.2)

            def send_via_external_smtp(self, _task):
                return False

        handler = Handler()

        with patch.object(self.mpd, 'IMAP_WORKER_COUNT', 1), patch.object(
            self.mpd, 'SMTP_WORKER_COUNT', 1
        ):
            daemon._imap_pool = self.mpd.ImapWorkerPool(task_q, handler, stop)
            daemon._smtp_pool = self.mpd.SmtpWorkerPool(smtp_q, handler, stop)
            daemon._imap_pool.start()
            daemon._smtp_pool.start()

        task_q.put(self.mpd.ImapTask(referent_data={}, account={'email': 'a@b.c'}))
        self.assertTrue(slow_ready.wait(timeout=2.0))
        daemon._stop_event.set()
        return daemon

    def test_shutdown_returns_within_budget(self) -> None:
        daemon = self._build_minimal_daemon()
        start = time.monotonic()
        daemon._shutdown()
        elapsed = time.monotonic() - start
        budget = self.mpd.SHUTDOWN_BUDGET_SEC
        self.assertLess(
            elapsed,
            budget + 0.5,
            f'_shutdown exceeded SHUTDOWN_BUDGET_SEC ({budget}s)',
        )

    def test_double_shutdown_is_idempotent(self) -> None:
        daemon = self._build_minimal_daemon()
        daemon._shutdown()
        start = time.monotonic()
        daemon._shutdown()
        self.assertLess(time.monotonic() - start, 0.2)

    def test_repeated_stop_signal_safe(self) -> None:
        daemon = self._build_minimal_daemon()
        daemon._handle_signal(15, None)
        daemon._handle_signal(15, None)
        daemon._shutdown()


if __name__ == '__main__':
    unittest.main()