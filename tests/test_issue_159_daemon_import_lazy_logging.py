#!/usr/bin/env python3
"""Issue #159 — importing mail-proxy-daemon must not run setup_logging."""

from __future__ import annotations

import importlib.util
import logging
import logging.handlers
import os
from pathlib import Path
from unittest import mock
import unittest

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


def _import_with_setup_logging_side_effect_guards():
    """Import daemon while mocking every setup_logging filesystem/handler touch."""
    root = logging.getLogger()
    prior_handlers = list(root.handlers)
    prior_level = root.level

    makedirs_mock = mock.Mock()
    rotating_mock = mock.Mock()
    stream_mock = mock.Mock()
    _orig_add_handler = logging.Logger.addHandler
    add_handler_mock = mock.Mock()

    def _add_handler(self, hdlr):
        add_handler_mock(self, hdlr)
        return _orig_add_handler(self, hdlr)

    with mock.patch('os.makedirs', makedirs_mock):
        with mock.patch('logging.handlers.RotatingFileHandler', rotating_mock):
            with mock.patch('logging.StreamHandler', stream_mock):
                with mock.patch.object(logging.Logger, 'addHandler', _add_handler):
                    mod = _import_fresh_daemon_module()

    return {
        'mod': mod,
        'prior_handlers': prior_handlers,
        'prior_level': prior_level,
        'makedirs_mock': makedirs_mock,
        'rotating_mock': rotating_mock,
        'stream_mock': stream_mock,
        'add_handler_mock': add_handler_mock,
    }


class Issue159DaemonImportLazyLoggingTests(unittest.TestCase):
    def test_import_does_not_touch_log_dir_or_handlers(self) -> None:
        """Import must not invoke setup_logging side effects (host-agnostic)."""
        result = _import_with_setup_logging_side_effect_guards()
        mod = result['mod']
        root = logging.getLogger()

        result['makedirs_mock'].assert_not_called()
        result['rotating_mock'].assert_not_called()
        result['stream_mock'].assert_not_called()
        result['add_handler_mock'].assert_not_called()

        self.assertEqual(root.handlers, result['prior_handlers'])
        self.assertEqual(root.level, result['prior_level'])
        self.assertIs(mod.logger, logging.getLogger('mail-proxy'))
        self.assertEqual(mod.LOG_FILE, '/var/log/mail-proxy/mail-proxy-daemon.log')

    def test_import_leaves_root_handlers_unchanged_regardless_of_log_dir(self) -> None:
        """Root logger handlers unchanged after import (CI or clean host)."""
        root = logging.getLogger()
        prior_handlers = list(root.handlers)
        try:
            snapshot = _import_with_setup_logging_side_effect_guards()
            mod = snapshot['mod']
            self.assertEqual(root.handlers, prior_handlers)
            self.assertIs(mod.logger, logging.getLogger('mail-proxy'))
            snapshot['makedirs_mock'].assert_not_called()
        finally:
            root.handlers[:] = prior_handlers

    def test_main_calls_setup_logging_before_acquire_lock(self) -> None:
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

        self.assertEqual(call_order, ['setup_logging', 'acquire_lock'])


if __name__ == '__main__':
    unittest.main()
