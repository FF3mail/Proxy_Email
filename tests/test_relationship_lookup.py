#!/usr/bin/env python3
"""
Unit tests for relationship_lookup.py (PROMPT-54).

Requires a disposable MySQL/MariaDB database. Configure via environment:
  RELATIONSHIP_TEST_DB_HOST (default 127.0.0.1)
  RELATIONSHIP_TEST_DB_PORT (default 3306)
  RELATIONSHIP_TEST_DB_USER (default root)
  RELATIONSHIP_TEST_DB_PASS (default empty)
  RELATIONSHIP_TEST_DB_NAME (default mail_proxy_relationship_test)

Set RELATIONSHIP_TEST_SKIP=1 to skip when no database is available.
"""

from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from typing import Any, Dict, Optional

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

import mysql.connector
from mysql.connector import Error as MySQLError
from mysql.connector import errorcode

from relationship_lookup import RelationshipLookup, normalize_email

TEST_DB_HOST = os.environ.get('RELATIONSHIP_TEST_DB_HOST', '127.0.0.1')
TEST_DB_PORT = int(os.environ.get('RELATIONSHIP_TEST_DB_PORT', '3306'))
TEST_DB_USER = os.environ.get('RELATIONSHIP_TEST_DB_USER', 'root')
TEST_DB_PASS = os.environ.get('RELATIONSHIP_TEST_DB_PASS', '')
TEST_DB_NAME = os.environ.get(
    'RELATIONSHIP_TEST_DB_NAME', 'mail_proxy_relationship_test'
)


class _TestDatabase:
    """Minimal connection provider for RelationshipLookup tests."""

    def __init__(self, **conn_kwargs: Any) -> None:
        self._conn_kwargs = conn_kwargs

    def get_connection(self):
        return mysql.connector.connect(**self._conn_kwargs)


def _admin_connect(database: Optional[str] = None):
    kwargs = {
        'host': TEST_DB_HOST,
        'port': TEST_DB_PORT,
        'user': TEST_DB_USER,
        'password': TEST_DB_PASS,
        'charset': 'utf8mb4',
        'collation': 'utf8mb4_unicode_ci',
        'autocommit': True,
    }
    if database:
        kwargs['database'] = database
    return mysql.connector.connect(**kwargs)


def _db_available() -> bool:
    if os.environ.get('RELATIONSHIP_TEST_SKIP') == '1':
        return False
    try:
        conn = _admin_connect()
        conn.close()
        return True
    except MySQLError:
        return False


class NormalizeEmailTestCase(unittest.TestCase):
    def test_normalize_email(self) -> None:
        self.assertEqual(
            normalize_email('  Client1@Partner.COM '), 'client1@partner.com'
        )
        self.assertEqual(normalize_email(''), '')


def _execute_script(conn, script: str) -> None:
    cursor = conn.cursor()
    statement = ''
    for line in script.splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith('--'):
            continue
        statement += line + '\n'
        if stripped.endswith(';'):
            cursor.execute(statement)
            statement = ''
    cursor.close()


def _bootstrap_schema() -> None:
    conn = _admin_connect()
    cursor = conn.cursor()
    cursor.execute(f'DROP DATABASE IF EXISTS `{TEST_DB_NAME}`')
    cursor.execute(
        f'CREATE DATABASE `{TEST_DB_NAME}` '
        'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    )
    cursor.close()
    conn.close()

    conn = _admin_connect(TEST_DB_NAME)
    base_schema = (ROOT / 'schema.sql').read_text(encoding='utf-8')
    base_schema = base_schema.replace('CREATE DATABASE IF NOT EXISTS mail_proxy', '-- skipped')
    base_schema = base_schema.replace('USE mail_proxy;', '-- skipped')
    _execute_script(conn, base_schema)
    migration = (ROOT / 'migrations' / '002_client_relationship_columns.sql').read_text(
        encoding='utf-8'
    )
    migration = migration.replace('USE mail_proxy;', '-- skipped')
    _execute_script(conn, migration)
    conn.close()


def _insert_referent(
    conn,
    username: str,
    local_inbox: str,
    local_outbox: str,
    active: int = 1,
) -> int:
    cursor = conn.cursor()
    cursor.execute(
        """
        INSERT INTO referents (username, local_inbox, local_outbox, active)
        VALUES (%s, %s, %s, %s)
        """,
        (username, local_inbox, local_outbox, active),
    )
    rid = cursor.lastrowid
    conn.commit()
    cursor.close()
    return int(rid)


def _insert_account(
    conn,
    referent_id: int,
    email: str,
    active: int = 1,
) -> int:
    cursor = conn.cursor()
    cursor.execute(
        """
        INSERT INTO external_accounts (
            referent_id, email, imap_host, smtp_host, active
        ) VALUES (%s, %s, 'imap.example.com', 'smtp.example.com', %s)
        """,
        (referent_id, email, active),
    )
    aid = cursor.lastrowid
    conn.commit()
    cursor.close()
    return int(aid)


def _insert_relationship(
    conn,
    referent_id: int,
    legacy_email: str,
    external_client: str,
    local_client: str,
    local_referent: str,
    account_id: int,
    maildir: str,
    active: int = 1,
) -> int:
    cursor = conn.cursor()
    cursor.execute(
        """
        INSERT INTO clients (
            email, referent_id,
            external_client_email, local_client_email, local_referent_email,
            external_account_id, local_client_maildir, active
        ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """,
        (
            legacy_email,
            referent_id,
            external_client,
            local_client,
            local_referent,
            account_id,
            maildir,
            active,
        ),
    )
    cid = cursor.lastrowid
    conn.commit()
    cursor.close()
    return int(cid)


@unittest.skipUnless(_db_available(), 'MySQL test database not available')
class RelationshipLookupTestCase(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        _bootstrap_schema()
        cls.db = _TestDatabase(
            host=TEST_DB_HOST,
            port=TEST_DB_PORT,
            user=TEST_DB_USER,
            password=TEST_DB_PASS,
            database=TEST_DB_NAME,
            charset='utf8mb4',
            collation='utf8mb4_unicode_ci',
            autocommit=True,
        )
        cls.lookup = RelationshipLookup(cls.db)

    def setUp(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        cursor = conn.cursor()
        cursor.execute('SET FOREIGN_KEY_CHECKS=0')
        cursor.execute('DELETE FROM clients')
        cursor.execute('DELETE FROM external_accounts')
        cursor.execute('DELETE FROM referents')
        cursor.execute('SET FOREIGN_KEY_CHECKS=1')
        conn.commit()
        cursor.close()
        conn.close()

    def test_referent_zero_relationships_no_poll_targets(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        _insert_referent(
            conn,
            'Empty Referent',
            'empty-in@test.loc',
            '/var/vmail/empty/Maildir',
            active=1,
        )
        conn.close()
        self.assertEqual(self.lookup.list_poll_targets(), [])
        self.assertEqual(self.lookup.list_watch_targets(), [])

    def test_inactive_referent_not_routable(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir', active=0
        )
        acc = _insert_account(conn, rid, 'ref1@hmail.de')
        _insert_relationship(
            conn,
            rid,
            'legacy1@test.loc',
            'client1@partner.com',
            'client1@local.loc',
            'ref1@local.loc',
            acc,
            '/var/vmail/client1/Maildir',
        )
        conn.close()
        self.assertIsNone(
            self.lookup.resolve_inbound(acc, 'client1@partner.com')
        )

    def test_ivan_two_clients_independent_inbound(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir'
        )
        acc1 = _insert_account(conn, rid, 'ref1@hmail.de')
        acc2 = _insert_account(conn, rid, 'ivan-client7@hmail.de')
        _insert_relationship(
            conn,
            rid,
            'legacy-c1@test.loc',
            'client1@partner.com',
            'client1@local.loc',
            'ref1@local.loc',
            acc1,
            '/var/vmail/c1/Maildir',
        )
        _insert_relationship(
            conn,
            rid,
            'legacy-c2@test.loc',
            'customer-007@partner.com',
            'c007@local.loc',
            'sales17@local.loc',
            acc2,
            '/var/vmail/c007/Maildir',
        )
        conn.close()

        r1 = self.lookup.resolve_inbound(acc1, 'client1@partner.com')
        r2 = self.lookup.resolve_inbound(acc2, 'customer-007@partner.com')
        self.assertIsNotNone(r1)
        self.assertIsNotNone(r2)
        assert r1 is not None and r2 is not None
        self.assertEqual(r1.local_client_email, 'client1@local.loc')
        self.assertEqual(r1.local_referent_email, 'ref1@local.loc')
        self.assertEqual(r2.local_client_email, 'c007@local.loc')
        self.assertEqual(r2.local_referent_email, 'sales17@local.loc')
        # Cross-mailbox must not resolve
        self.assertIsNone(
            self.lookup.resolve_inbound(acc1, 'customer-007@partner.com')
        )
        self.assertIsNone(
            self.lookup.resolve_inbound(acc2, 'client1@partner.com')
        )

    def test_unknown_sender_returns_none(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir'
        )
        acc = _insert_account(conn, rid, 'ref1@hmail.de')
        _insert_relationship(
            conn,
            rid,
            'legacy1@test.loc',
            'client1@partner.com',
            'client1@local.loc',
            'ref1@local.loc',
            acc,
            '/var/vmail/c1/Maildir',
        )
        conn.close()
        self.assertIsNone(
            self.lookup.resolve_inbound(acc, 'unknown@partner.com')
        )

    def test_wrong_external_account_id_returns_none(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir'
        )
        acc1 = _insert_account(conn, rid, 'ref1@hmail.de')
        acc2 = _insert_account(conn, rid, 'ref2@hmail.de')
        _insert_relationship(
            conn,
            rid,
            'legacy1@test.loc',
            'client1@partner.com',
            'client1@local.loc',
            'ref1@local.loc',
            acc1,
            '/var/vmail/c1/Maildir',
        )
        conn.close()
        self.assertIsNone(
            self.lookup.resolve_inbound(acc2, 'client1@partner.com')
        )

    def test_outbound_local_to_resolves(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir'
        )
        acc = _insert_account(conn, rid, 'ref1@hmail.de')
        _insert_relationship(
            conn,
            rid,
            'legacy1@test.loc',
            'client1@partner.com',
            'client1@local.loc',
            'ref1@local.loc',
            acc,
            '/var/vmail/c1/Maildir',
        )
        conn.close()
        dto = self.lookup.resolve_outbound('client1@local.loc')
        self.assertIsNotNone(dto)
        assert dto is not None
        self.assertEqual(dto.external_client_email, 'client1@partner.com')
        self.assertEqual(dto.external_referent_email, 'ref1@hmail.de')

    def test_unknown_local_recipient_returns_none(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir'
        )
        acc = _insert_account(conn, rid, 'ref1@hmail.de')
        _insert_relationship(
            conn,
            rid,
            'legacy1@test.loc',
            'client1@partner.com',
            'client1@local.loc',
            'ref1@local.loc',
            acc,
            '/var/vmail/c1/Maildir',
        )
        conn.close()
        self.assertIsNone(
            self.lookup.resolve_outbound('nobody@local.loc')
        )

    def test_list_poll_and_watch_targets(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir'
        )
        acc1 = _insert_account(conn, rid, 'ref1@hmail.de')
        acc2 = _insert_account(conn, rid, 'ivan-client7@hmail.de')
        _insert_relationship(
            conn,
            rid,
            'legacy-c1@test.loc',
            'client1@partner.com',
            'client1@local.loc',
            'ref1@local.loc',
            acc1,
            '/var/vmail/c1/Maildir',
        )
        _insert_relationship(
            conn,
            rid,
            'legacy-c2@test.loc',
            'customer-007@partner.com',
            'c007@local.loc',
            'sales17@local.loc',
            acc2,
            '/var/vmail/c007/Maildir',
        )
        conn.close()
        poll = self.lookup.list_poll_targets()
        watch = self.lookup.list_watch_targets()
        self.assertEqual(len(poll), 2)
        self.assertEqual(len(watch), 2)
        poll_account_ids = sorted(t['account']['id'] for t in poll)
        self.assertEqual(poll_account_ids, sorted([acc1, acc2]))
        watch_paths = sorted(t['local_client_maildir'] for t in watch)
        self.assertEqual(
            watch_paths,
            sorted(['/var/vmail/c1/Maildir', '/var/vmail/c007/Maildir']),
        )

    def test_incomplete_relationship_excluded(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir'
        )
        acc = _insert_account(conn, rid, 'ref1@hmail.de')
        cursor = conn.cursor()
        cursor.execute(
            """
            INSERT INTO clients (email, referent_id, external_client_email, active)
            VALUES ('partial@test.loc', %s, 'partial@partner.com', 1)
            """,
            (rid,),
        )
        conn.commit()
        cursor.close()
        conn.close()
        self.assertEqual(self.lookup.list_poll_targets(), [])
        self.assertIsNone(
            self.lookup.resolve_inbound(acc, 'partial@partner.com')
        )

    def test_duplicate_external_client_rejected_by_db(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir'
        )
        acc1 = _insert_account(conn, rid, 'ref1@hmail.de')
        acc2 = _insert_account(conn, rid, 'ref2@hmail.de')
        _insert_relationship(
            conn,
            rid,
            'legacy-c1@test.loc',
            'dup@partner.com',
            'client1@local.loc',
            'ref1@local.loc',
            acc1,
            '/var/vmail/c1/Maildir',
        )
        cursor = conn.cursor()
        with self.assertRaises(MySQLError) as ctx:
            cursor.execute(
                """
                INSERT INTO clients (
                    email, referent_id,
                    external_client_email, local_client_email,
                    local_referent_email, external_account_id,
                    local_client_maildir, active
                ) VALUES (
                    'legacy-c2@test.loc', %s,
                    'dup@partner.com', 'client2@local.loc',
                    'ref2@local.loc', %s,
                    '/var/vmail/c2/Maildir', 1
                )
                """,
                (rid, acc2),
            )
        self.assertEqual(ctx.exception.errno, errorcode.ER_DUP_ENTRY)
        cursor.close()
        conn.close()

    def test_duplicate_external_account_id_rejected_by_db(self) -> None:
        conn = _admin_connect(TEST_DB_NAME)
        rid = _insert_referent(
            conn, 'Ivan', 'ivan-in@test.loc', '/var/vmail/ivan/Maildir'
        )
        acc = _insert_account(conn, rid, 'ref1@hmail.de')
        _insert_relationship(
            conn,
            rid,
            'legacy-c1@test.loc',
            'client1@partner.com',
            'client1@local.loc',
            'ref1@local.loc',
            acc,
            '/var/vmail/c1/Maildir',
        )
        cursor = conn.cursor()
        with self.assertRaises(MySQLError) as ctx:
            cursor.execute(
                """
                INSERT INTO clients (
                    email, referent_id,
                    external_client_email, local_client_email,
                    local_referent_email, external_account_id,
                    local_client_maildir, active
                ) VALUES (
                    'legacy-c2@test.loc', %s,
                    'client2@partner.com', 'client2@local.loc',
                    'ref2@local.loc', %s,
                    '/var/vmail/c2/Maildir', 1
                )
                """,
                (rid, acc),
            )
        self.assertEqual(ctx.exception.errno, errorcode.ER_DUP_ENTRY)
        cursor.close()
        conn.close()


if __name__ == '__main__':
    unittest.main()
