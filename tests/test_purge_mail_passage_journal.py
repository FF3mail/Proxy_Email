#!/usr/bin/env python3
"""PROMPT-79.2-deploy-verify-fixups — regression for purge db.conf key layout.

Live failure: load_db_config looked for generic host/user/password keys and
missed the daemon's [db] section with db_host/db_user/db_pass/db_name.
"""

from __future__ import annotations

import configparser
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))


def _load_purge_module():
    path = ROOT / 'scripts' / 'purge_mail_passage_journal.py'
    spec = importlib.util.spec_from_file_location('purge_mail_passage_journal', path)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class PurgeDbConfRegressionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.purge = _load_purge_module()

    def test_load_db_config_reads_daemon_db_section_keys(self) -> None:
        text = (
            "[db]\n"
            "db_host = 127.0.0.1\n"
            "db_port = 3306\n"
            "db_user = mailproxy\n"
            "db_pass = s3cret\n"
            "db_name = mail_proxy\n"
        )
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'db.conf'
            path.write_text(text, encoding='utf-8')
            cfg = self.purge.load_db_config(str(path))
        self.assertEqual(cfg['host'], '127.0.0.1')
        self.assertEqual(cfg['port'], 3306)
        self.assertEqual(cfg['user'], 'mailproxy')
        self.assertEqual(cfg['password'], 's3cret')
        self.assertEqual(cfg['database'], 'mail_proxy')

    def test_load_db_config_fallback_generic_keys(self) -> None:
        text = (
            "[database]\n"
            "host = localhost\n"
            "port = 3307\n"
            "user = u\n"
            "password = p\n"
            "database = mail_proxy\n"
        )
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'db.conf'
            path.write_text(text, encoding='utf-8')
            cfg = self.purge.load_db_config(str(path))
        self.assertEqual(cfg['host'], 'localhost')
        self.assertEqual(cfg['port'], 3307)
        self.assertEqual(cfg['user'], 'u')
        self.assertEqual(cfg['password'], 'p')

    def test_pre_fix_generic_keys_miss_daemon_layout(self) -> None:
        """Show why the old reader returned None credentials on live conf."""
        text = (
            "[db]\n"
            "db_host = 127.0.0.1\n"
            "db_user = mailproxy\n"
            "db_pass = s3cret\n"
            "db_name = mail_proxy\n"
        )
        parser = configparser.ConfigParser()
        parser.read_string(text)
        section = parser['db']
        self.assertIsNone(section.get('host'))
        self.assertIsNone(section.get('user'))
        self.assertIsNone(section.get('password'))
        self.assertEqual(section.get('db_host'), '127.0.0.1')


if __name__ == '__main__':
    unittest.main(verbosity=2)