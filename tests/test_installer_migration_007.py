#!/usr/bin/env python3
"""Issue #140 — installer applies migrations/007 on Database phase upgrade path."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"

_FUNC_BODY = re.compile(
    r"(?m)^ensure_panel_login_attempts_table\(\) \{\n(?P<body>.*?)\n\}",
    re.S,
)


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _ensure_007_body(text: str) -> str:
    found = _FUNC_BODY.search(text)
    if not found:
        raise AssertionError("function ensure_panel_login_attempts_table not found")
    return found.group("body")


def _named_function_body(text: str, name: str) -> str:
    pattern = rf"(?m)^{re.escape(name)}\(\) \{{\n(?P<body>.*?)\n\}}"
    found = re.search(pattern, text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


class InstallerMigration007Tests(unittest.TestCase):
    def test_required_files_includes_migration_sql(self) -> None:
        text = _read_installer()
        self.assertIn('"./migrations/007_panel_login_attempts.sql"', text)

    def test_migrate_panel_auth_calls_007_helper(self) -> None:
        body = _named_function_body(_read_installer(), "migrate_panel_auth")
        self.assertIn("ensure_panel_admins_table", body)
        self.assertIn("ensure_panel_login_attempts_table", body)
        self.assertLess(
            body.find("ensure_panel_admins_table"),
            body.find("ensure_panel_login_attempts_table"),
        )
        self.assertLess(
            body.find("ensure_panel_login_attempts_table"),
            body.find("seed_panel_master"),
        )

    def test_007_applied_as_root_from_sql_file(self) -> None:
        body = _ensure_007_body(_read_installer())
        self.assertIn("./migrations/007_panel_login_attempts.sql", body)
        self.assertIn("-u root", body)
        self.assertIn("MYSQL_ROOT_PASSWORD", body)
        self.assertNotIn("-u \"${DB_USER}\"", body)

    def test_validate_panel_auth_checks_table(self) -> None:
        body = _named_function_body(_read_installer(), "validate_panel_auth")
        self.assertIn("panel_login_attempts_table_exists", body)

    def test_migration_file_is_idempotent_create_only(self) -> None:
        migration = (ROOT / "migrations/007_panel_login_attempts.sql").read_text(
            encoding="utf-8"
        )
        self.assertIn("CREATE TABLE IF NOT EXISTS panel_login_attempts", migration)
        self.assertNotIn("DROP TABLE", migration.upper())


if __name__ == "__main__":
    unittest.main()
