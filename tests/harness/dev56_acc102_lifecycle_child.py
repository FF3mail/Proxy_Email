#!/usr/bin/env python3
"""Isolated ACC-102: SIGTERM → supervisor → _shutdown (subprocess child)."""

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
SLEEP_CHUNK = 0.05


def main() -> int:
    daemon = mod.ProxyDaemon.__new__(mod.ProxyDaemon)
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
    daemon._watch_registered_referent_ids = set()
    daemon._mail_handler = MagicMock()
    daemon._mail_handler._relationship_lookup.list_watch_targets_for_referent = (
        MagicMock(return_value=[])
    )
    daemon._watched_lock = threading.Lock()
    daemon._watched_relationship_ids = set()
    daemon._unschedule_watches_for_referent = MagicMock()
    daemon._register_watches_for_referent = MagicMock()

    def blocking_load_referents():
        DB_INSIDE.set()
        print('IN_DB', flush=True)
        while not daemon._stop_event.is_set():
            time.sleep(SLEEP_CHUNK)
        return []

    daemon._load_referents = blocking_load_referents
    daemon._sync_relationship_watches = MagicMock()

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
            daemon._sync_database_state()
            if daemon._stop_event.is_set():
                break
            for _ in range(mod.SUPERVISOR_SYNC_INTERVAL_SEC):
                if daemon._stop_event.is_set() or daemon._past_shutdown_deadline():
                    break
                time.sleep(SLEEP_CHUNK)
    finally:
        daemon._shutdown()

    assert daemon._shutdown_signaled_mono is not None
    elapsed = time.monotonic() - daemon._shutdown_signaled_mono
    print(f'ELAPSED={elapsed:.6f}', flush=True)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())