#!/usr/bin/env python3
"""PROMPT-79.2-deploy-verify-fixups — regression for live notify bug.

Live failure: _referent_handler_data returned only {'id': N}, so outbound
disposal used notify_to=None / from=None and left notified=0 despite a
successful dispose (log: "outbound notify skipped missing addresses").
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
    """Fails against pre-c13069f stub; passes when DB columns are loaded."""

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

    def test_handler_data_includes_local_inbox_used_for_notify(self) -> None:
        """Reproduce live gap: notify reads referent_data['local_inbox']."""
        row = {
            'id': 1,
            'username': 'Test Referent',
            'local_inbox': 'refloc1@testvps.loc',
            'local_outbox': '/var/vmail/refloc1/Maildir',
        }
        daemon, cursor = self._daemon_with_referent_row(row)
        data = self.mpd.ProxyDaemon._referent_handler_data(daemon, 1)

        self.assertEqual(data['id'], 1)
        self.assertEqual(data.get('local_inbox'), 'refloc1@testvps.loc')
        self.assertEqual(data.get('username'), 'Test Referent')
        self.assertEqual(data.get('local_outbox'), '/var/vmail/refloc1/Maildir')
        self.assertIsNotNone(data.get('local_inbox'))
        cursor.execute.assert_called_once()
        sql = cursor.execute.call_args[0][0]
        self.assertIn('local_inbox', sql)
        self.assertIn('username', sql)

    def test_pre_fix_id_only_dict_cannot_supply_notify_address(self) -> None:
        """Document the pre-c13069f failure mode the live deploy hit."""
        pre_fix = {'id': 1}
        self.assertIsNone(pre_fix.get('local_inbox'))
        self.assertIsNone(pre_fix.get('username'))


if __name__ == '__main__':
    unittest.main(verbosity=2)

