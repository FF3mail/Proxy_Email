#!/usr/bin/env python3
"""
Mail disposal orchestration (PROMPT-79.2).

Hard invariant: journal INSERT must succeed before any destructive action.
Fail-closed callers must not invoke dispose helpers on lookup/parse errors.
"""

from __future__ import annotations

import logging
from datetime import datetime
from pathlib import Path
from typing import Optional

from mail_passage_journal import (
    EVENT_DELIVERED,
    EVENT_DISPOSED,
    MailPassageJournal,
    PassageJournalRecord,
    utc_now_naive,
)
from referent_notify import build_disposal_notification, send_local_notification

logger = logging.getLogger(__name__)


def journal_disposed(
    journal: MailPassageJournal,
    *,
    direction: str,
    disposal_reason: str,
    referent_name: Optional[str] = None,
    client_name: Optional[str] = None,
    local_mailbox: Optional[str] = None,
    external_mailbox: Optional[str] = None,
    received_at: Optional[datetime] = None,
    source_message_id: Optional[str] = None,
) -> int:
    """INSERT disposed row with notified=0. Raises on failure."""
    now = utc_now_naive()
    return journal.write(
        PassageJournalRecord(
            event_type=EVENT_DISPOSED,
            direction=direction,
            referent_name=referent_name,
            client_name=client_name,
            local_mailbox=local_mailbox,
            external_mailbox=external_mailbox,
            received_at=received_at or now,
            action_at=now,
            disposal_reason=disposal_reason,
            notified=False,
            source_message_id=source_message_id,
            event_ts=now,
        )
    )


def journal_delivered(
    journal: MailPassageJournal,
    *,
    direction: str,
    referent_name: Optional[str] = None,
    client_name: Optional[str] = None,
    local_mailbox: Optional[str] = None,
    external_mailbox: Optional[str] = None,
    received_at: Optional[datetime] = None,
    source_message_id: Optional[str] = None,
) -> int:
    """INSERT delivered row. Raises on failure."""
    now = utc_now_naive()
    return journal.write(
        PassageJournalRecord(
            event_type=EVENT_DELIVERED,
            direction=direction,
            referent_name=referent_name,
            client_name=client_name,
            local_mailbox=local_mailbox,
            external_mailbox=external_mailbox,
            received_at=received_at or now,
            action_at=now,
            disposal_reason=None,
            notified=False,
            source_message_id=source_message_id,
            event_ts=now,
        )
    )


def maybe_notify_outbound_disposal(
    journal: MailPassageJournal,
    journal_id: int,
    *,
    direction: str,
    referent_name: Optional[str],
    client_name: Optional[str],
    notify_to: Optional[str],
    mail_from: Optional[str],
    received_at: Optional[datetime],
    smtp_host: str = '127.0.0.1',
    smtp_port: int = 25,
) -> bool:
    """
    Outbound only: send §4 template, then UPDATE notified=1 on success.
    Inbound: never notify. Returns whether notified flag was set.
    """
    if direction != 'outbound':
        return False
    if not notify_to or not mail_from:
        logger.error(
            '[MAIL_DISPOSAL] outbound notify skipped missing addresses '
            'journal_id=%s to=%r from=%r',
            journal_id,
            notify_to,
            mail_from,
        )
        return False
    msg = build_disposal_notification(
        referent_display=referent_name or notify_to,
        client_display=client_name or '(клиент)',
        received_at=received_at or utc_now_naive(),
        mail_from=mail_from,
        mail_to=notify_to,
    )
    if not send_local_notification(msg, smtp_host=smtp_host, smtp_port=smtp_port):
        return False
    return journal.mark_notified(journal_id)


def unlink_maildir_file(file_path: Path) -> bool:
    try:
        if file_path.exists():
            file_path.unlink()
            logger.info('[MAIL_DISPOSAL] deleted maildir file=%s', file_path.name)
        return True
    except Exception as exc:
        logger.error(
            '[MAIL_DISPOSAL] failed to delete maildir file=%s err=%s',
            file_path,
            exc,
        )
        return False
