#!/usr/bin/env python3
"""ACC-102: SIGTERM during simulated DB I/O that ignores stop event."""

from __future__ import annotations

import importlib.util
import os
import signal
import sys
import threading
import time
import types
from pathlib import Path
from unittest.mock import MagicMock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

watchdog_mod = types.ModuleType('watchdog')
watchdog_events = types.ModuleType('watchdog.events')
watchdog_events.FileSystemEventHandler = object
watchdog_observers = types.ModuleType('watchdog.observers')
watchdog_observers.Observer = MagicMock
sys.modules.setdefault('watchdog', watchdog_mod)
sys.modules.setdefault('watchdog.events', watchdog_events)
sys.modules.setdefault('watchdog.observers', watchdog_observers)

spec = importlib.util.spec_from_file_location(
    'mail_proxy_daemon_lifecycle_child',
    str(ROOT / 'mail-proxy-daemon.py'),
)
mod = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(mod)

DB_INSIDE = threading.Event()
BLOCK_SEC = float(os.environ.get('ACC102_DB_BLOCK_SEC', '0.35'))
MODE = os.environ.get('ACC102_DB_MODE', 'complete')
SLEEP_CHUNK = 0.02


class _SimulatedDatabase:
    """Blocks inside fetchall without observing daemon stop (socket-read stall)."""

    def __init__(self, block_sec: float, mode: str) -> None:
        self._block_sec = block_sec
        self._mode = mode

    def get_connection(self):
        outer = self

        class _Conn:
            def cursor(self, dictionary=False):
                return _SimulatedCursor(outer)

            def close(self) -> None:
                pass

        return _Conn()


class _SimulatedCursor:
    def __init__(self, db: _SimulatedDatabase) -> None:
        self._db = db

    def execute(self, *args, **kwargs) -> None:
        pass

    def fetchall(self):
        DB_INSIDE.set()
        print('IN_DB', flush=True)
        time.sleep(self._db._block_sec)
        if self._db._mode == 'timeout':
            import mysql.connector.errors as mysql_errors

            print('DB_EXIT=timeout', flush=True)
            raise mysql_errors.OperationalError(
                errno=3024,
                msg='Simulated read timeout waiting for server response',
            )
        print('DB_EXIT=complete', flush=True)
        return []

    def close(self) -> None:
        pass


def main() -> int:
    daemon = mod.ProxyDaemon.__new__(mod.ProxyDaemon)
    daemon._shutdown_lock = threading.Lock()
    daemon._is_shutdown = False
    daemon._stop_event = threading.Event()
    daemon._shutdown_signaled_mono = None
    daemon._shutdown_deadline_mono = None
    daemon._db = _SimulatedDatabase(BLOCK_SEC, MODE)
    daemon._observer = None
    daemon._observer_started = False
    daemon._imap_poller = MagicMock()
    daemon._imap_poller.is_alive = MagicMock(return_value=False)
    daemon._imap_pool = MagicMock()
    daemon._imap_pool._workers = []
    daemon._smtp_pool = MagicMock()
    daemon._smtp_pool._workers = []
    daemon._watch_registered_referent_ids = set()
    daemon._mail_handler = MagicMock()
    daemon._mail_handler._relationship_lookup.list_watch_targets_for_referent = (
        MagicMock(return_value=[])
    )
    daemon._watched_lock = threading.Lock()
    daemon._watched_relationship_ids = set()
    daemon._unschedule_watches_for_referent = MagicMock()
    daemon._register_watches_for_referent = MagicMock()
    daemon._load_referents = lambda: mod.ProxyDaemon._load_referents(daemon)

    signal.signal(signal.SIGTERM, daemon._handle_signal)
    signal.signal(signal.SIGINT, daemon._handle_signal)

    terminator = threading.Thread(
        target=lambda: (
            DB_INSIDE.wait(timeout=5.0)
            and os.kill(os.getpid(), signal.SIGTERM)
        ),
        name='sigterm-injector',
        daemon=True,
    )
    terminator.start()

    try:
        while not daemon._stop_event.is_set():
            if daemon._past_shutdown_deadline():
                break
            mod.ProxyDaemon._sync_database_state(daemon)
            if daemon._stop_event.is_set():
                break
            for _ in range(mod.SUPERVISOR_SYNC_INTERVAL_SEC):
                if daemon._stop_event.is_set() or daemon._past_shutdown_deadline():
                    break
                time.sleep(SLEEP_CHUNK)
    finally:
        mod.ProxyDaemon._shutdown(daemon)

    assert daemon._shutdown_signaled_mono is not None
    elapsed = time.monotonic() - daemon._shutdown_signaled_mono
    print(f'ELAPSED={elapsed:.6f}', flush=True)
    print(f'MODE={MODE}', flush=True)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())