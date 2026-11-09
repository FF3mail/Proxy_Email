#!/usr/bin/env python3
"""Issue #161 — installer applies migrations/009 on Database phase upgrade path."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "delta-transit-install.sh"

_FUNC_009_BODY = re.compile(
    r"(?m)^ensure_clients_referent_fk_restrict\(\) \{\n(?P<body>.*?)\n\}",
    re.S,
)


def _read_installer() -> str:
    return INSTALLER.read_text(encoding="utf-8")


def _ensure_009_body(text: str) -> str:
    found = _FUNC_009_BODY.search(text)
    if not found:
        raise AssertionError("function ensure_clients_referent_fk_restrict not found")
    return found.group("body")


def _named_function_body(text: str, name: str) -> str:
    pattern = rf"(?m)^{re.escape(name)}\(\) \{{\n(?P<body>.*?)\n\}}"
    found = re.search(pattern, text, re.S)
    if not found:
        raise AssertionError(f"function {name} not found")
    return found.group("body")


class InstallerMigration009Tests(unittest.TestCase):
    def test_required_files_includes_migration_sql(self) -> None:
        text = _read_installer()
        self.assertIn('"./migrations/009_clients_referent_id_restrict.sql"', text)

    def test_migrate_panel_auth_calls_009_helper(self) -> None:
        body = _named_function_body(_read_installer(), "migrate_panel_auth")
        self.assertIn("ensure_clients_referent_fk_restrict", body)
        self.assertLess(
            body.find("ensure_panel_admins_must_change_password_column"),
            body.find("ensure_clients_referent_fk_restrict"),
        )
        self.assertLess(
            body.find("ensure_clients_referent_fk_restrict"),
            body.find("seed_panel_master"),
        )

    def test_009_applied_via_mysql_root_client(self) -> None:
        body = _ensure_009_body(_read_installer())
        self.assertIn("./migrations/009_clients_referent_id_restrict.sql", body)
        self.assertIn("mysql_root_client", body)
        self.assertIn('"${DB_NAME}"', body)
        self.assertNotIn('-u "${DB_USER}"', body)
        self.assertNotIn("-p${MYSQL_ROOT_PASSWORD}", body)
        self.assertNotIn("-p${", body)

    def test_migration_sql_has_no_hardcoded_database(self) -> None:
        migration = (ROOT / "migrations/009_clients_referent_id_restrict.sql").read_text(
            encoding="utf-8"
        )
        upper = migration.upper()
        self.assertNotIn("USE MAIL_PROXY", upper)
        self.assertNotIn("USE `MAIL_PROXY`", upper)
        self.assertNotRegex(migration, r"(?i)\bmail_proxy\b")

    def test_validate_panel_auth_checks_referent_fk(self) -> None:
        body = _named_function_body(_read_installer(), "validate_panel_auth")
        self.assertIn("clients_referent_fk_delete_rule_is_restrict", body)

    def test_fk_probe_avoids_mysql_password_argv(self) -> None:
        body = _named_function_body(_read_installer(), "clients_referent_fk_delete_rule_is_restrict")
        self.assertIn("REFERENTIAL_CONSTRAINTS", body)
        self.assertIn("mysql_mail_proxy_client", body)
        self.assertNotIn('-p"${db_pass}"', body)
        self.assertNotIn("-p${", body)

    def test_migration_mentions_restrict_not_cascade_on_clients(self) -> None:
        migration = (ROOT / "migrations/009_clients_referent_id_restrict.sql").read_text(
            encoding="utf-8"
        )
        self.assertIn("ON DELETE RESTRICT", migration)
        upper = migration.upper()
        self.assertNotIn("ALTER TABLE EXTERNAL_ACCOUNTS", upper)


if __name__ == "__main__":
    unittest.main()
