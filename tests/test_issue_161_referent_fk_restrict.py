#!/usr/bin/env python3
"""Issue #161 — static checks and optional MariaDB RESTRICT integration test."""

from __future__ import annotations

import os
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


class StaticReferentFkTests(unittest.TestCase):
    def test_schema_clients_referent_restrict(self) -> None:
        schema = (ROOT / "schema.sql").read_text(encoding="utf-8")
        self.assertIn("fk_clients_referent", schema)
        self.assertRegex(
            schema,
            r"CONSTRAINT fk_clients_referent FOREIGN KEY \(referent_id\) REFERENCES referents\(id\) ON DELETE RESTRICT",
        )
        clients_block = schema.split("CREATE TABLE IF NOT EXISTS external_accounts", 1)[0]
        self.assertNotIn(
            "FOREIGN KEY (referent_id) REFERENCES referents(id) ON DELETE CASCADE",
            clients_block,
        )

    def test_external_accounts_referent_still_cascade(self) -> None:
        schema = (ROOT / "schema.sql").read_text(encoding="utf-8")
        idx = schema.index("CREATE TABLE IF NOT EXISTS external_accounts")
        fragment = schema[idx : idx + 1200]
        self.assertIn("ON DELETE CASCADE", fragment)

    def test_verify_script_checks_restrict(self) -> None:
        text = (ROOT / "scripts" / "verify-install-regression.sh").read_text(
            encoding="utf-8"
        )
        self.assertIn("clients.referent_id FK ON DELETE RESTRICT", text)
        self.assertIn("REFERENTIAL_CONSTRAINTS", text)

    def test_index_handles_referent_delete_exception(self) -> None:
        text = (ROOT / "web" / "index.php").read_text(encoding="utf-8")
        self.assertIn("referentDeleteBlockedByRelationships", text)
        self.assertIn("referent.error.delete_has_relationships", text)


def _mysql_available() -> bool:
    if os.environ.get("REFERENT_FK_TEST_SKIP", "").strip() == "1":
        return False
    host = os.environ.get("REFERENT_FK_TEST_DB_HOST", os.environ.get("RELATIONSHIP_TEST_DB_HOST", ""))
    user = os.environ.get("REFERENT_FK_TEST_DB_USER", os.environ.get("RELATIONSHIP_TEST_DB_USER", ""))
    password = os.environ.get(
        "REFERENT_FK_TEST_DB_PASS", os.environ.get("RELATIONSHIP_TEST_DB_PASS", "")
    )
    if not host or not user:
        return False
    try:
        import mysql.connector  # noqa: F401
    except ImportError:
        return False
    _ = password
    return True


@unittest.skipUnless(_mysql_available(), "MariaDB env not configured (REFERENT_FK_TEST_* / RELATIONSHIP_TEST_*)")
class MariaDbReferentFkIntegration(unittest.TestCase):
    def test_migration_009_idempotent_restrict(self) -> None:
        import mysql.connector

        conn = mysql.connector.connect(
            host=os.environ.get("REFERENT_FK_TEST_DB_HOST", os.environ["RELATIONSHIP_TEST_DB_HOST"]),
            user=os.environ.get("REFERENT_FK_TEST_DB_USER", os.environ["RELATIONSHIP_TEST_DB_USER"]),
            password=os.environ.get(
                "REFERENT_FK_TEST_DB_PASS", os.environ.get("RELATIONSHIP_TEST_DB_PASS", "")
            ),
            autocommit=True,
        )
        cur = conn.cursor()
        db_name = "mail_proxy_referent_fk_161_test"
        cur.execute(f"DROP DATABASE IF EXISTS `{db_name}`")
        cur.execute(f"CREATE DATABASE `{db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        cur.execute(f"USE `{db_name}`")
        schema = (ROOT / "schema.sql").read_text(encoding="utf-8")
        # Force CASCADE like legacy installs, then apply 009.
        legacy = schema.replace("ON DELETE RESTRICT", "ON DELETE CASCADE", 1)
        for stmt in legacy.split(";"):
            chunk = stmt.strip()
            if not chunk or chunk.upper().startswith("CREATE DATABASE") or chunk.upper().startswith("USE "):
                continue
            cur.execute(chunk)
        migration = (ROOT / "migrations/009_clients_referent_id_restrict.sql").read_text(encoding="utf-8")
        for stmt in migration.split(";"):
            chunk = stmt.strip()
            if chunk:
                cur.execute(chunk)
        cur.execute(
            """
            SELECT rc.DELETE_RULE
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_NAME = rc.TABLE_NAME
            WHERE rc.CONSTRAINT_SCHEMA = %s
              AND rc.TABLE_NAME = 'clients'
              AND rc.REFERENCED_TABLE_NAME = 'referents'
              AND kcu.COLUMN_NAME = 'referent_id'
            LIMIT 1
            """,
            (db_name,),
        )
        rule = cur.fetchone()[0]
        self.assertEqual(rule, "RESTRICT")
        cur.execute(f"DROP DATABASE IF EXISTS `{db_name}`")
        cur.close()
        conn.close()


if __name__ == "__main__":
    unittest.main()
