#!/usr/bin/env python3
"""Issue #138 — db.conf docs use canonical [db] db_name key."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

DOC_PATHS = (
    ROOT / "docs" / "guide" / "03-installation.md",
    ROOT / "docs" / "reports" / "ISSUE-138-operator-password.md",
)

DB_NAME_SNIPPET = re.compile(
    r"python3 -c \"import configparser;.*?print\(c\['db'\]\['(?P<key>[^']+)'\]\)\""
)


class Issue138DbConfDocsTests(unittest.TestCase):
    def test_manual_upgrade_snippets_use_db_name_key(self) -> None:
        for path in DOC_PATHS:
            text = path.read_text(encoding="utf-8")
            with self.subTest(path=path.relative_to(ROOT)):
                self.assertIn("db_name", text)
                self.assertNotIn("c['db']['name']", text)
                match = DB_NAME_SNIPPET.search(text)
                self.assertIsNotNone(match, "expected DB_NAME python one-liner")
                assert match is not None
                self.assertEqual(match.group("key"), "db_name")


if __name__ == "__main__":
    unittest.main()
