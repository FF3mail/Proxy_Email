#!/usr/bin/env python3
"""Issue #35 — installer applies migrations/010 on Database phase upgrade path."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"

_FUNC_010_BODY = re.compile(
    r"(?m)^ensure_clients_display_name_column\(\) \{\n(?P<body>.*?)\n\}",
    re.S,
)


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _ensure_010_body(text: str) -> str:
    found = _FUNC_010_BODY.search(text)
    if not found:
        raise AssertionError("function ensure_clients_display_name_column not found")
    return found.group("body")


def _named_function_body(text: str, name: str) -> str:
    pattern = rf"(?m)^{re.escape(name)}\(\) \{{\n(?P<body>.*?)\n\}}"
    found = re.search(pattern, text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


class InstallerMigration010Tests(unittest.TestCase):
    def test_required_files_includes_migration_sql(self) -> None:
        text = _read_installer()
        self.assertIn('"./migrations/010_clients_display_name.sql"', text)

    def test_migrate_panel_auth_calls_010_helper(self) -> None:
        body = _named_function_body(_read_installer(), "migrate_panel_auth")
        self.assertIn("ensure_clients_display_name_column", body)
        self.assertLess(
            body.find("ensure_clients_referent_fk_restrict"),
            body.find("ensure_clients_display_name_column"),
        )
        self.assertLess(
            body.find("ensure_clients_display_name_column"),
            body.find("seed_panel_master"),
        )

    def test_010_applied_via_mysql_root_client(self) -> None:
        body = _ensure_010_body(_read_installer())
        self.assertIn("./migrations/010_clients_display_name.sql", body)
        self.assertIn("mysql_root_client", body)
        self.assertIn('"${DB_NAME}"', body)
        self.assertNotIn('-u "${DB_USER}"', body)
        self.assertNotIn("-p${MYSQL_ROOT_PASSWORD}", body)
        self.assertNotIn("-p${", body)

    def test_migration_sql_targets_display_name_only(self) -> None:
        migration = (ROOT / "migrations/010_clients_display_name.sql").read_text(
            encoding="utf-8"
        )
        upper = migration.upper()
        self.assertIn("DISPLAY_NAME", upper)
        self.assertIn("CLIENTS", upper)
        self.assertIn("INFORMATION_SCHEMA", upper)
        self.assertNotIn("USE MAIL_PROXY", upper)
        self.assertNotIn("USE `MAIL_PROXY`", upper)

    def test_validate_panel_auth_checks_display_name_column(self) -> None:
        body = _named_function_body(_read_installer(), "validate_panel_auth")
        self.assertIn("clients_display_name_column_exists", body)

    def test_schema_sql_has_display_name_column(self) -> None:
        schema = (ROOT / "schema.sql").read_text(encoding="utf-8")
        self.assertRegex(schema, r"(?i)display_name\s+VARCHAR\(100\)")


if __name__ == "__main__":
    unittest.main()
