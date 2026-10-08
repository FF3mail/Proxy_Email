#!/usr/bin/env python3
"""Regression: referent handler data must not own relationship notify addresses.

Issue #88: disposal notify uses relationship local_referent_email / classify
local_mailbox — never referents.local_inbox as a shared fallback.
"""

from __future__ import annotations

import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))


class ReferentHandlerDataNotifyRegressionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        import importlib.util

        watchdog_mod = types.ModuleType('watchdog')
        watchdog_events = types.ModuleType('watchdog.events')
        watchdog_events.FileSystemEventHandler = object
        watchdog_observers = types.ModuleType('watchdog.observers')
        watchdog_observers.Observer = MagicMock
        sys.modules.setdefault('watchdog', watchdog_mod)
        sys.modules.setdefault('watchdog.events', watchdog_events)
        sys.modules.setdefault('watchdog.observers', watchdog_observers)

        spec = importlib.util.spec_from_file_location(
            'mail_proxy_daemon_handler_data_test',
            str(ROOT / 'mail-proxy-daemon.py'),
        )
        cls.mpd = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(cls.mpd)

    def _daemon_with_referent_row(self, row):
        class DaemonStub:
            pass

        daemon = DaemonStub()
        cursor = MagicMock()
        cursor.fetchone.return_value = row
        conn = MagicMock()
        conn.cursor.return_value = cursor
        db = MagicMock()
        db.get_connection.return_value = conn
        daemon._db = db
        return daemon, cursor

    def test_handler_data_may_load_legacy_locals_but_they_are_not_required(self) -> None:
        row = {
            'id': 1,
            'username': 'Test Referent',
            'local_inbox': 'refloc1@testvps.loc',
            'local_outbox': '/var/vmail/refloc1/Maildir',
        }
        daemon, cursor = self._daemon_with_referent_row(row)
        data = self.mpd.ProxyDaemon._referent_handler_data(daemon, 1)

        self.assertEqual(data['id'], 1)
        self.assertEqual(data.get('username'), 'Test Referent')
        # Legacy columns may still be present for diagnostics — not for routing.
        self.assertEqual(data.get('local_inbox'), 'refloc1@testvps.loc')
        cursor.execute.assert_called_once()

    def test_handler_data_nullable_local_inbox_from_db(self) -> None:
        """PROMPT-83 / #88: referent row may have NULL local_inbox."""
        row = {
            'id': 2,
            'username': 'No Referent Inbox',
            'local_inbox': None,
            'local_outbox': None,
        }
        daemon, _cursor = self._daemon_with_referent_row(row)
        data = self.mpd.ProxyDaemon._referent_handler_data(daemon, 2)
        self.assertEqual(data['id'], 2)
        self.assertIsNone(data.get('local_inbox'))
        self.assertIsNone(data.get('local_outbox'))

    def test_daemon_source_has_no_local_inbox_notify_fallback(self) -> None:
        src = (ROOT / 'mail-proxy-daemon.py').read_text(encoding='utf-8')
        self.assertNotRegex(
            src,
            r'notify_to\s*=\s*.*referent_data\.get\(\s*[\'"]local_inbox[\'"]',
        )
        self.assertIn('classified.local_mailbox', src)
        self.assertIn('dto.local_referent_email', src)
        self.assertIn('plan.local_mailbox', src)


if __name__ == '__main__':
    unittest.main(verbosity=2)
