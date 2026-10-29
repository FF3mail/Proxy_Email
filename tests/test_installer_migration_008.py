#!/usr/bin/env python3
"""Issue #138 — installer applies migrations/008 on Database phase upgrade path."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"

_FUNC_008_BODY = re.compile(
    r"(?m)^ensure_panel_admins_must_change_password_column\(\) \{\n(?P<body>.*?)\n\}",
    re.S,
)


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _ensure_008_body(text: str) -> str:
    found = _FUNC_008_BODY.search(text)
    if not found:
        raise AssertionError("function ensure_panel_admins_must_change_password_column not found")
    return found.group("body")


def _named_function_body(text: str, name: str) -> str:
    pattern = rf"(?m)^{re.escape(name)}\(\) \{{\n(?P<body>.*?)\n\}}"
    found = re.search(pattern, text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


class InstallerMigration008Tests(unittest.TestCase):
    def test_required_files_includes_migration_sql(self) -> None:
        text = _read_installer()
        self.assertIn('"./migrations/008_panel_admins_must_change_password.sql"', text)

    def test_migrate_panel_auth_calls_008_helper(self) -> None:
        body = _named_function_body(_read_installer(), "migrate_panel_auth")
        self.assertIn("ensure_panel_admins_must_change_password_column", body)
        self.assertLess(
            body.find("ensure_panel_login_attempts_table"),
            body.find("ensure_panel_admins_must_change_password_column"),
        )
        self.assertLess(
            body.find("ensure_panel_admins_must_change_password_column"),
            body.find("seed_panel_master"),
        )

    def test_008_applied_as_root_from_sql_file(self) -> None:
        body = _ensure_008_body(_read_installer())
        self.assertIn("./migrations/008_panel_admins_must_change_password.sql", body)
        self.assertIn("-u root", body)
        self.assertIn("MYSQL_ROOT_PASSWORD", body)
        self.assertNotIn('-u "${DB_USER}"', body)

    def test_validate_panel_auth_checks_column(self) -> None:
        body = _named_function_body(_read_installer(), "validate_panel_auth")
        self.assertIn("panel_admins_must_change_password_column_exists", body)

    def test_migration_file_is_idempotent_add_column_only(self) -> None:
        migration = (ROOT / "migrations/008_panel_admins_must_change_password.sql").read_text(
            encoding="utf-8"
        )
        self.assertIn("ADD COLUMN IF NOT EXISTS must_change_password", migration)
        self.assertNotIn("DROP TABLE", migration.upper())
        self.assertNotIn("DROP COLUMN", migration.upper())


if __name__ == "__main__":
    unittest.main()
