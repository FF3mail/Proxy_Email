#!/usr/bin/env python3
"""Unit tests for MariaDB integration destructive-database guard (no live DB)."""

from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from tests.test_supervisor_db_mariadb_integration import (  # noqa: E402
    DESTRUCTIVE_ALLOW_ENV,
    destructive_mariadb_allowed,
    live_mariadb_skip_reason,
)


class MariaDbDestructiveGuardTests(unittest.TestCase):
    def test_throwaway_name_accepted(self) -> None:
        self.assertTrue(
            destructive_mariadb_allowed(
                'mysql://u:p@127.0.0.1:3306/mail_proxy_dev56_it'
            )
        )
        self.assertTrue(
            destructive_mariadb_allowed('mysql://u:p@127.0.0.1:3306/my_test_db')
        )

    def test_production_like_name_refused(self) -> None:
        self.assertFalse(
            destructive_mariadb_allowed(
                'mysql://u:p@127.0.0.1:3306/mail_proxy_prod_demo'
            )
        )

    def test_env_override_allows_production_like_name(self) -> None:
        prev = os.environ.get(DESTRUCTIVE_ALLOW_ENV)
        try:
            os.environ[DESTRUCTIVE_ALLOW_ENV] = '1'
            self.assertTrue(
                destructive_mariadb_allowed(
                    'mysql://u:p@127.0.0.1:3306/mail_proxy_prod_demo'
                )
            )
        finally:
            if prev is None:
                os.environ.pop(DESTRUCTIVE_ALLOW_ENV, None)
            else:
                os.environ[DESTRUCTIVE_ALLOW_ENV] = prev

    def test_skip_reason_includes_refusal_message(self) -> None:
        prev_dsn = os.environ.get('DELTA_TEST_MARIADB_DSN')
        prev_allow = os.environ.get(DESTRUCTIVE_ALLOW_ENV)
        try:
            os.environ['DELTA_TEST_MARIADB_DSN'] = (
                'mysql://u:p@127.0.0.1:3306/mail_proxy_prod_demo'
            )
            os.environ.pop(DESTRUCTIVE_ALLOW_ENV, None)
            reason = live_mariadb_skip_reason()
            self.assertIsNotNone(reason)
            assert reason is not None
            self.assertIn('refused', reason)
            self.assertIn('mail_proxy_prod_demo', reason)
        finally:
            if prev_dsn is None:
                os.environ.pop('DELTA_TEST_MARIADB_DSN', None)
            else:
                os.environ['DELTA_TEST_MARIADB_DSN'] = prev_dsn
            if prev_allow is None:
                os.environ.pop(DESTRUCTIVE_ALLOW_ENV, None)
            else:
                os.environ[DESTRUCTIVE_ALLOW_ENV] = prev_allow


if __name__ == '__main__':
    unittest.main(verbosity=2)