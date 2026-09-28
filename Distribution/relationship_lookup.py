#!/usr/bin/env python3
"""
ClientRelationship lookup layer (PROMPT-53 / PROMPT-54).

Standalone query module used by mail-proxy-daemon for live relationship
resolution. Implements PROMPT-53 §10–§13 query contracts against additive
`clients` columns. PROMPT-79.2 adds classified resolve (matched / no_match /
inactive) for disposal journaling.
Importing this module has no side effects beyond class/function definitions.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, List, Mapping, Optional, Protocol

# Shared SELECT list: relationship + account + referent context for transport.
_RELATIONSHIP_SELECT = """
    SELECT
        c.id AS relationship_id,
        c.referent_id,
        c.external_client_email,
        c.local_client_email,
        c.local_referent_email,
        c.external_account_id,
        c.local_client_maildir,
        c.active AS relationship_active,
        ea.email AS external_referent_email,
        ea.id AS account_id,
        ea.referent_id AS account_referent_id,
        ea.email AS account_email,
        ea.username,
        ea.auth_type,
        ea.provider,
        ea.password_enc,
        ea.imap_host,
        ea.imap_port,
        ea.imap_encryption,
        ea.smtp_host,
        ea.smtp_port,
        ea.smtp_encryption,
        ea.client_id AS oauth_client_id,
        ea.client_secret_enc,
        ea.active AS account_active,
        r.username AS referent_username,
        r.local_inbox AS referent_local_inbox,
        r.local_outbox AS referent_local_outbox,
        r.active AS referent_active
"""

# PROMPT-53 §9 — valid ClientRelationship predicate (routing-eligible rows only).
_VALID_RELATIONSHIP_WHERE = """
    c.active = 1
    AND ea.active = 1
    AND r.active = 1
    AND c.external_account_id IS NOT NULL
    AND c.external_client_email IS NOT NULL AND c.external_client_email <> ''
    AND c.local_client_email IS NOT NULL AND c.local_client_email <> ''
    AND c.local_referent_email IS NOT NULL AND c.local_referent_email <> ''
    AND c.local_client_maildir IS NOT NULL AND c.local_client_maildir <> ''
    AND ea.referent_id = c.referent_id
"""


class DatabaseConnectionProvider(Protocol):
    """Minimal interface shared with mail-proxy-daemon.Database."""

    def get_connection(self):
        ...


def normalize_email(address: str) -> str:
    """Lowercase + trim per PROMPT-53 §8/§10."""
    return address.strip().lower()


# PROMPT-79.2 — classified lookup outcomes for disposal (distinct from errors).
STATUS_MATCHED = 'matched'
STATUS_NO_MATCH = 'no_match'
STATUS_INACTIVE = 'relationship_inactive'


@dataclass(frozen=True)
class RelationshipClassifyResult:
    """Definitive classify result (never used for DB/lookup exceptions)."""

    status: str
    dto: Optional['ClientRelationshipDTO'] = None
    relationship_id: Optional[int] = None
    referent_name: Optional[str] = None
    client_name: Optional[str] = None
    local_mailbox: Optional[str] = None
    external_mailbox: Optional[str] = None


@dataclass(frozen=True)
class ClientRelationshipDTO:
    relationship_id: int
    referent_id: int
    external_client_email: str
    local_client_email: str
    local_referent_email: str
    external_account_id: int
    external_referent_email: str
    local_client_maildir: str
    account: Dict[str, Any]
    referent: Dict[str, Any]

    @classmethod
    def from_row(cls, row: Mapping[str, Any]) -> 'ClientRelationshipDTO':
        account = {
            'id': row['account_id'],
            'email': row['account_email'],
            'username': row.get('username'),
            'auth_type': row['auth_type'],
            'provider': row.get('provider'),
            'password_enc': row.get('password_enc'),
            'imap_host': row['imap_host'],
            'imap_port': row['imap_port'],
            'imap_encryption': row['imap_encryption'],
            'smtp_host': row['smtp_host'],
            'smtp_port': row['smtp_port'],
            'smtp_encryption': row['smtp_encryption'],
            'client_id': row.get('oauth_client_id'),
            'client_secret_enc': row.get('client_secret_enc'),
            'active': row['account_active'],
            'referent_id': row['account_referent_id'],
        }
        referent = {
            'id': row['referent_id'],
            'username': row['referent_username'],
            'local_inbox': row['referent_local_inbox'],
            'local_outbox': row['referent_local_outbox'],
            'active': row['referent_active'],
        }
        return cls(
            relationship_id=int(row['relationship_id']),
            referent_id=int(row['referent_id']),
            external_client_email=row['external_client_email'],
            local_client_email=row['local_client_email'],
            local_referent_email=row['local_referent_email'],
            external_account_id=int(row['external_account_id']),
            external_referent_email=row['external_referent_email'],
            local_client_maildir=row['local_client_maildir'],
            account=account,
            referent=referent,
        )


class RelationshipLookup:
    """PROMPT-53 §10 conceptual API — Python implementation."""

    def __init__(self, db: DatabaseConnectionProvider) -> None:
        self._db = db

    def resolve_inbound(
        self,
        external_account_id: int,
        external_sender_email: str,
    ) -> Optional[ClientRelationshipDTO]:
        """
        PROMPT-53 §11: external mailbox context + external From.
        Returns DTO only for a fully valid+active relationship; else None.
        Prefer classify_inbound() when inactive must be distinguished.
        """
        classified = self.classify_inbound(external_account_id, external_sender_email)
        return classified.dto if classified.status == STATUS_MATCHED else None

    def resolve_outbound(
        self,
        local_recipient_email: str,
    ) -> Optional[ClientRelationshipDTO]:
        """
        PROMPT-53 §12: local message From/identity identifies Client relationship.
        Prefer classify_outbound() when inactive must be distinguished.
        """
        classified = self.classify_outbound(local_recipient_email)
        return classified.dto if classified.status == STATUS_MATCHED else None

    def classify_inbound(
        self,
        external_account_id: int,
        external_sender_email: str,
    ) -> RelationshipClassifyResult:
        """
        PROMPT-79.2: matched | no_match | relationship_inactive.
        Raises on DB errors (callers treat exceptions as fail-closed).
        """
        sender = normalize_email(external_sender_email)
        if not sender:
            return RelationshipClassifyResult(status=STATUS_NO_MATCH)

        matched = self._fetch_one(
            f"""
            {_RELATIONSHIP_SELECT}
            FROM clients c
            JOIN external_accounts ea ON ea.id = c.external_account_id
            JOIN referents r ON r.id = c.referent_id
            WHERE c.external_account_id = %s
              AND c.external_client_email = %s
              AND {_VALID_RELATIONSHIP_WHERE}
            """,
            (int(external_account_id), sender),
        )
        if matched is not None:
            return self._matched_result(matched)

        inactive = self._fetch_one_any_active(
            """
            SELECT
                c.id AS relationship_id,
                c.external_client_email,
                c.local_client_email,
                c.local_referent_email,
                c.active AS relationship_active,
                ea.email AS external_referent_email,
                ea.active AS account_active,
                r.username AS referent_username,
                r.active AS referent_active
            FROM clients c
            LEFT JOIN external_accounts ea ON ea.id = c.external_account_id
            LEFT JOIN referents r ON r.id = c.referent_id
            WHERE c.external_account_id = %s
              AND LOWER(TRIM(c.external_client_email)) = %s
            LIMIT 1
            """,
            (int(external_account_id), sender),
        )
        if inactive is None:
            return RelationshipClassifyResult(status=STATUS_NO_MATCH)
        return self._inactive_or_incomplete(inactive, direction='inbound')

    def classify_outbound(
        self,
        local_recipient_email: str,
    ) -> RelationshipClassifyResult:
        """
        PROMPT-79.2: matched | no_match | relationship_inactive.
        Raises on DB errors (callers treat exceptions as fail-closed).
        """
        recipient = normalize_email(local_recipient_email)
        if not recipient:
            return RelationshipClassifyResult(status=STATUS_NO_MATCH)

        matched = self._fetch_one(
            f"""
            {_RELATIONSHIP_SELECT}
            FROM clients c
            JOIN external_accounts ea ON ea.id = c.external_account_id
            JOIN referents r ON r.id = c.referent_id
            WHERE c.local_client_email = %s
              AND {_VALID_RELATIONSHIP_WHERE}
            """,
            (recipient,),
        )
        if matched is not None:
            return self._matched_result(matched)

        inactive = self._fetch_one_any_active(
            """
            SELECT
                c.id AS relationship_id,
                c.external_client_email,
                c.local_client_email,
                c.local_referent_email,
                c.active AS relationship_active,
                ea.email AS external_referent_email,
                ea.active AS account_active,
                r.username AS referent_username,
                r.active AS referent_active
            FROM clients c
            LEFT JOIN external_accounts ea ON ea.id = c.external_account_id
            LEFT JOIN referents r ON r.id = c.referent_id
            WHERE LOWER(TRIM(c.local_client_email)) = %s
            LIMIT 1
            """,
            (recipient,),
        )
        if inactive is None:
            return RelationshipClassifyResult(status=STATUS_NO_MATCH)
        return self._inactive_or_incomplete(inactive, direction='outbound')

    @staticmethod
    def _matched_result(dto: ClientRelationshipDTO) -> RelationshipClassifyResult:
        return RelationshipClassifyResult(
            status=STATUS_MATCHED,
            dto=dto,
            relationship_id=dto.relationship_id,
            referent_name=(dto.referent or {}).get('username'),
            client_name=dto.external_client_email or dto.local_client_email,
            local_mailbox=dto.local_referent_email or dto.local_client_email,
            external_mailbox=dto.external_client_email or dto.external_referent_email,
        )

    @staticmethod
    def _inactive_or_incomplete(
        row: Mapping[str, Any],
        *,
        direction: str,
    ) -> RelationshipClassifyResult:
        rel_active = int(row.get('relationship_active') or 0) == 1
        acct_active = int(row.get('account_active') or 0) == 1
        ref_active = int(row.get('referent_active') or 0) == 1
        if rel_active and acct_active and ref_active:
            # Row exists but failed completeness predicates → treat as no match.
            return RelationshipClassifyResult(status=STATUS_NO_MATCH)

        local_mailbox = row.get('local_referent_email') or row.get('local_client_email')
        external_mailbox = row.get('external_client_email') or row.get(
            'external_referent_email'
        )
        client_name = row.get('external_client_email') or row.get('local_client_email')
        if direction == 'outbound':
            local_mailbox = row.get('local_client_email') or local_mailbox
        return RelationshipClassifyResult(
            status=STATUS_INACTIVE,
            relationship_id=int(row['relationship_id']) if row.get('relationship_id') else None,
            referent_name=row.get('referent_username'),
            client_name=client_name,
            local_mailbox=local_mailbox,
            external_mailbox=external_mailbox,
        )

    def _fetch_one_any_active(
        self, sql: str, params: tuple
    ) -> Optional[Mapping[str, Any]]:
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            cursor.execute(sql, params)
            row = cursor.fetchone()
            return row
        finally:
            if cursor is not None:
                cursor.close()
            if conn is not None:
                conn.close()

    def list_poll_targets(self) -> List[Dict[str, Any]]:
        """
        PROMPT-53 §10: active+valid relationships for IMAP polling.
        One entry per relationship (each with its external account).
        """
        sql = f"""
            {_RELATIONSHIP_SELECT}
            FROM clients c
            JOIN external_accounts ea ON ea.id = c.external_account_id
            JOIN referents r ON r.id = c.referent_id
            WHERE {_VALID_RELATIONSHIP_WHERE}
            ORDER BY c.id
        """
        rows = self._fetch_all(sql)
        return [
            {
                'account': dto.account,
                'relationship': self._relationship_dict(dto),
                'referent': dto.referent,
            }
            for dto in rows
        ]

    def list_watch_targets(
        self, referent_id: Optional[int] = None
    ) -> List[Dict[str, Any]]:
        """
        PROMPT-53 §10: Maildir paths to watch for outbound pickup.
        Optional referent_id filter (PROMPT-73 per-referent watch modes).
        """
        sql = f"""
            {_RELATIONSHIP_SELECT}
            FROM clients c
            JOIN external_accounts ea ON ea.id = c.external_account_id
            JOIN referents r ON r.id = c.referent_id
            WHERE {_VALID_RELATIONSHIP_WHERE}
        """
        params: tuple = ()
        if referent_id is not None:
            sql += ' AND c.referent_id = %s'
            params = (int(referent_id),)
        sql += ' ORDER BY c.id'
        rows = self._fetch_all(sql, params)
        return [
            {
                'local_client_maildir': dto.local_client_maildir,
                'relationship': self._relationship_dict(dto),
            }
            for dto in rows
        ]

    def list_watch_targets_for_referent(
        self, referent_id: int
    ) -> List[Dict[str, Any]]:
        return self.list_watch_targets(referent_id=int(referent_id))

    def _fetch_one(
        self, sql: str, params: tuple
    ) -> Optional[ClientRelationshipDTO]:
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            cursor.execute(sql, params)
            row = cursor.fetchone()
            if not row:
                return None
            return ClientRelationshipDTO.from_row(row)
        finally:
            if cursor is not None:
                cursor.close()
            if conn is not None:
                conn.close()

    def _fetch_all(self, sql: str, params: tuple = ()) -> List[ClientRelationshipDTO]:
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor(dictionary=True)
            cursor.execute(sql, params)
            rows = cursor.fetchall() or []
            return [ClientRelationshipDTO.from_row(row) for row in rows]
        finally:
            if cursor is not None:
                cursor.close()
            if conn is not None:
                conn.close()

    @staticmethod
    def _relationship_dict(dto: ClientRelationshipDTO) -> Dict[str, Any]:
        return {
            'relationship_id': dto.relationship_id,
            'referent_id': dto.referent_id,
            'external_client_email': dto.external_client_email,
            'local_client_email': dto.local_client_email,
            'local_referent_email': dto.local_referent_email,
            'external_account_id': dto.external_account_id,
            'external_referent_email': dto.external_referent_email,
            'local_client_maildir': dto.local_client_maildir,
        }
