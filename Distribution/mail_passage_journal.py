#!/usr/bin/env python3
"""
Durable mail-passage journal writer (PROMPT-79.2 / ADR-001).

Timestamps are always computed as UTC in application code and stored as naive
DATETIME(0) values (documented UTC convention). Never use SQL NOW().
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Optional, Protocol

logger = logging.getLogger(__name__)

EVENT_DELIVERED = 'delivered'
EVENT_DISPOSED = 'disposed'
EVENT_SKIPPED = 'skipped'
DIRECTION_INBOUND = 'inbound'
DIRECTION_OUTBOUND = 'outbound'

DISPOSAL_NO_RELATIONSHIP = 'no_relationship'
DISPOSAL_RELATIONSHIP_INACTIVE = 'relationship_inactive'
DISPOSAL_ZERO_ATTACHMENTS = 'zero_attachments'
DISPOSAL_MULTIPLE_ATTACHMENTS = 'multiple_attachments'
DISPOSAL_DISALLOWED_EXTENSION = 'disallowed_extension'
DISPOSAL_SUBJECT_MISMATCH = 'subject_mismatch'
DISPOSAL_TOO_MANY_ATTACHMENTS = 'too_many_attachments'
DISPOSAL_MISSING_FILENAME = 'missing_filename'


def utc_now_naive() -> datetime:
    """UTC wall-clock as naive datetime for DATETIME(0) storage."""
    return datetime.now(timezone.utc).replace(tzinfo=None, microsecond=0)


def format_utc_naive(dt: datetime) -> str:
    if dt.tzinfo is not None:
        dt = dt.astimezone(timezone.utc).replace(tzinfo=None)
    return dt.replace(microsecond=0).strftime('%Y-%m-%d %H:%M:%S')


class DatabaseConnectionProvider(Protocol):
    def get_connection(self):
        ...


@dataclass
class PassageJournalRecord:
    event_type: str
    direction: str
    referent_name: Optional[str] = None
    client_name: Optional[str] = None
    local_mailbox: Optional[str] = None
    external_mailbox: Optional[str] = None
    received_at: Optional[datetime] = None
    action_at: Optional[datetime] = None
    disposal_reason: Optional[str] = None
    notified: bool = False
    source_message_id: Optional[str] = None
    detail: Optional[str] = None
    event_ts: Optional[datetime] = None


class MailPassageJournal:
    """INSERT-must-succeed writer used before irreversible message disposal."""

    def __init__(self, db: DatabaseConnectionProvider) -> None:
        self._db = db

    def write(self, record: PassageJournalRecord) -> int:
        """
        Persist one journal row. Returns inserted id.
        Raises on failure — callers must not dispose/delete on failure.
        """
        event_ts = record.event_ts or utc_now_naive()
        action_at = record.action_at or event_ts
        sql = """
            INSERT INTO mail_passage_journal (
                event_ts, event_type, direction,
                referent_name, client_name,
                local_mailbox, external_mailbox,
                received_at, action_at,
                disposal_reason, notified, source_message_id, detail
            ) VALUES (
                %s, %s, %s,
                %s, %s,
                %s, %s,
                %s, %s,
                %s, %s, %s, %s
            )
        """
        params = (
            format_utc_naive(event_ts),
            record.event_type,
            record.direction,
            record.referent_name,
            record.client_name,
            record.local_mailbox,
            record.external_mailbox,
            format_utc_naive(record.received_at) if record.received_at else None,
            format_utc_naive(action_at) if action_at else None,
            record.disposal_reason,
            1 if record.notified else 0,
            record.source_message_id,
            record.detail,
        )
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor()
            cursor.execute(sql, params)
            conn.commit()
            row_id = int(cursor.lastrowid)
            if row_id <= 0:
                raise RuntimeError('mail_passage_journal INSERT returned no id')
            return row_id
        except Exception:
            if conn is not None:
                try:
                    conn.rollback()
                except Exception:
                    pass
            raise
        finally:
            if cursor is not None:
                cursor.close()
            if conn is not None:
                conn.close()

    def mark_notified(self, journal_id: int) -> bool:
        """Flip notified=1 after confirmed local notification send. Returns True on OK."""
        conn = None
        cursor = None
        try:
            conn = self._db.get_connection()
            cursor = conn.cursor()
            cursor.execute(
                'UPDATE mail_passage_journal SET notified = 1 WHERE id = %s',
                (int(journal_id),),
            )
            conn.commit()
            return cursor.rowcount >= 1
        except Exception as exc:
            logger.error(
                '[MAIL_PASSAGE_JOURNAL] mark_notified failed id=%s err=%s',
                journal_id,
                exc,
            )
            if conn is not None:
                try:
                    conn.rollback()
                except Exception:
                    pass
            return False
        finally:
            if cursor is not None:
                cursor.close()
            if conn is not None:
                conn.close()
