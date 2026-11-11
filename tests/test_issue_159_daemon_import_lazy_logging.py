#!/usr/bin/env python3
"""Issue #159 — importing mail-proxy-daemon must not run setup_logging."""

from __future__ import annotations

import importlib.util
import logging
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parent.parent
DAEMON_PY = ROOT / 'mail-proxy-daemon.py'


def _import_fresh_daemon_module():
    """Load mail-proxy-daemon.py under a unique module name (no sys.modules cache)."""
    name = f'_issue159_daemon_{os.getpid()}_{id(object())}'
    spec = importlib.util.spec_from_file_location(name, DAEMON_PY)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class Issue159DaemonImportLazyLoggingTests(unittest.TestCase):
    def test_import_without_log_dir_does_not_configure_handlers(self) -> None:
        log_dir = Path('/var/log/mail-proxy')
        if log_dir.is_dir() and os.access(log_dir, os.W_OK):
            self.skipTest('/var/log/mail-proxy exists and is writable on this host')

        root = logging.getLogger()
        prior_handlers = list(root.handlers)
        try:
            mod = _import_fresh_daemon_module()
            self.assertEqual(mod.LOG_FILE, '/var/log/mail-proxy/mail-proxy-daemon.log')
            self.assertEqual(root.handlers, prior_handlers)
            file_handlers = [
                h
                for h in root.handlers
                if isinstance(h, logging.FileHandler)
                and getattr(h, 'baseFilename', '').endswith('mail-proxy-daemon.log')
            ]
            self.assertEqual(file_handlers, [])
        finally:
            root.handlers[:] = prior_handlers

    def test_import_does_not_create_log_directory(self) -> None:
        log_dir = Path('/var/log/mail-proxy')
        if log_dir.is_dir():
            self.skipTest('/var/log/mail-proxy already exists')

        _import_fresh_daemon_module()
        self.assertFalse(log_dir.exists())

    def test_main_calls_setup_logging_before_startup_banner(self) -> None:
        mod = _import_fresh_daemon_module()
        call_order: list[str] = []

        def fake_setup() -> logging.Logger:
            call_order.append('setup_logging')
            return logging.getLogger('mail-proxy')

        def fake_acquire() -> int:
            call_order.append('acquire_lock')
            raise SystemExit(0)

        with mock.patch.object(mod, 'setup_logging', side_effect=fake_setup):
            with mock.patch.object(mod, 'acquire_single_instance_lock', side_effect=fake_acquire):
                with mock.patch.object(mod, 'write_pid_file'):
                    with self.assertRaises(SystemExit):
                        mod.main()

        self.assertEqual(call_order[0], 'setup_logging')
        self.assertIn('acquire_lock', call_order)


if __name__ == '__main__':
    unittest.main()
