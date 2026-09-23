#!/usr/bin/env python3
"""PROMPT-79.2 — journal write-before-delete, notify direction, fan-out rows."""

from __future__ import annotations

import sys
import unittest
from datetime import datetime
from pathlib import Path
from typing import Any, List, Optional
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from mail_disposal import (
    journal_delivered,
    journal_disposed,
    maybe_notify_outbound_disposal,
)
from mail_passage_journal import (
    DIRECTION_INBOUND,
    DIRECTION_OUTBOUND,
    EVENT_DELIVERED,
    EVENT_DISPOSED,
    MailPassageJournal,
    PassageJournalRecord,
)
from relationship_lookup import (
    STATUS_INACTIVE,
    STATUS_MATCHED,
    STATUS_NO_MATCH,
    RelationshipClassifyResult,
)
from relationship_routing import (
    SKIP_NO_RELATIONSHIP,
    SKIP_RELATIONSHIP_INACTIVE,
    disposal_reason_for_skip,
    plan_inbound_delivery,
    plan_outbound_delivery,
)
from referent_notify import build_disposal_notification


class FakeCursor:
    def __init__(self, *, fail: bool = False, lastrowid: int = 42) -> None:
        self.fail = fail
        self.lastrowid = lastrowid
        self.rowcount = 1
        self.executed: List[Any] = []

    def execute(self, sql, params=None):
        if self.fail:
            raise RuntimeError('insert failed')
        self.executed.append((sql, params))

    def close(self):
        pass


class FakeConn:
    def __init__(self, cursor: FakeCursor) -> None:
        self._cursor = cursor
        self.committed = False
        self.rolled_back = False

    def cursor(self, **kwargs):
        return self._cursor

    def commit(self):
        self.committed = True

    def rollback(self):
        self.rolled_back = True

    def close(self):
        pass


class FakeDB:
    def __init__(self, cursor: FakeCursor) -> None:
        self._cursor = cursor
        self.conn = FakeConn(cursor)

    def get_connection(self):
        return self.conn


class JournalWriterTest(unittest.TestCase):
    def test_write_commits_and_returns_id(self) -> None:
        cur = FakeCursor(lastrowid=7)
        db = FakeDB(cur)
        journal = MailPassageJournal(db)
        row_id = journal.write(
            PassageJournalRecord(
                event_type=EVENT_DISPOSED,
                direction=DIRECTION_INBOUND,
                disposal_reason='no_relationship',
            )
        )
        self.assertEqual(row_id, 7)
        self.assertTrue(db.conn.committed)
        self.assertEqual(cur.executed[0][1][10], 0)  # notified=0

    def test_write_failure_raises(self) -> None:
        cur = FakeCursor(fail=True)
        journal = MailPassageJournal(FakeDB(cur))
        with self.assertRaises(RuntimeError):
            journal.write(
                PassageJournalRecord(
                    event_type=EVENT_DISPOSED,
                    direction=DIRECTION_OUTBOUND,
                    disposal_reason='zero_attachments',
                )
            )


class DisposalOrderingTest(unittest.TestCase):
    def test_journal_failure_prevents_delete_callback(self) -> None:
        cur = FakeCursor(fail=True)
        journal = MailPassageJournal(FakeDB(cur))
        deleted = {'v': False}

        def do_delete():
            deleted['v'] = True

        try:
            journal_disposed(
                journal,
                direction=DIRECTION_INBOUND,
                disposal_reason='no_relationship',
            )
            do_delete()
        except RuntimeError:
            pass
        self.assertFalse(deleted['v'])

    def test_journal_success_then_delete(self) -> None:
        cur = FakeCursor(lastrowid=3)
        journal = MailPassageJournal(FakeDB(cur))
        deleted = {'v': False}
        journal_disposed(
            journal,
            direction=DIRECTION_INBOUND,
            disposal_reason='no_relationship',
        )
        deleted['v'] = True
        self.assertTrue(deleted['v'])


class NotifyDirectionTest(unittest.TestCase):
    def test_inbound_never_notifies(self) -> None:
        journal = MagicMock()
        ok = maybe_notify_outbound_disposal(
            journal,
            1,
            direction=DIRECTION_INBOUND,
            referent_name='Ref',
            client_name='Client',
            notify_to='ref@local',
            mail_from='ref@local',
            received_at=datetime(2026, 1, 2, 12, 0, 0),
        )
        self.assertFalse(ok)
        journal.mark_notified.assert_not_called()

    @patch('mail_disposal.send_local_notification', return_value=True)
    def test_outbound_marks_notified_after_send(self, _send) -> None:
        journal = MagicMock()
        journal.mark_notified.return_value = True
        ok = maybe_notify_outbound_disposal(
            journal,
            9,
            direction=DIRECTION_OUTBOUND,
            referent_name='Ref',
            client_name='Client',
            notify_to='ref@local',
            mail_from='ref@local',
            received_at=datetime(2026, 1, 2, 12, 0, 0),
        )
        self.assertTrue(ok)
        journal.mark_notified.assert_called_once_with(9)

    @patch('mail_disposal.send_local_notification', return_value=False)
    def test_outbound_send_fail_keeps_notified_zero(self, _send) -> None:
        journal = MagicMock()
        ok = maybe_notify_outbound_disposal(
            journal,
            9,
            direction=DIRECTION_OUTBOUND,
            referent_name='Ref',
            client_name='Client',
            notify_to='ref@local',
            mail_from='ref@local',
            received_at=datetime(2026, 1, 2, 12, 0, 0),
        )
        self.assertFalse(ok)
        journal.mark_notified.assert_not_called()

    def test_notification_template_wording(self) -> None:
        msg = build_disposal_notification(
            referent_display='Иван',
            client_display='ООО Ромашка',
            received_at=datetime(2026, 3, 15, 14, 30, 0),
            mail_from='ref@local',
            mail_to='ref@local',
        )
        self.assertEqual(msg['Subject'], 'Ошибка доставки — ООО Ромашка')
        body = msg.get_content()
        self.assertIn('ровно один файл', body)
        self.assertIn('Contract_2026.zip', body)
        self.assertNotIn('размер', body.lower())


class RelationshipDisposePlanTest(unittest.TestCase):
    def test_no_relationship_and_inactive_distinct_reasons(self) -> None:
        self.assertEqual(
            disposal_reason_for_skip(SKIP_NO_RELATIONSHIP), 'no_relationship'
        )
        self.assertEqual(
            disposal_reason_for_skip(SKIP_RELATIONSHIP_INACTIVE),
            'relationship_inactive',
        )

    def test_inbound_inactive_skip(self) -> None:
        def classify(_a, _s):
            return RelationshipClassifyResult(
                status=STATUS_INACTIVE,
                relationship_id=5,
                referent_name='Ref',
                client_name='client@ext',
            )

        plan = plan_inbound_delivery(
            classify_inbound=classify,
            account_id=1,
            account_email='acc@ext',
            from_address='client@ext',
            referent_local_inbox='ref@local',
        )
        self.assertEqual(plan.skip_reason, SKIP_RELATIONSHIP_INACTIVE)
        self.assertEqual(disposal_reason_for_skip(plan.skip_reason), 'relationship_inactive')

    def test_outbound_no_match_skip(self) -> None:
        plan = plan_outbound_delivery(
            classify_outbound=lambda _e: RelationshipClassifyResult(
                status=STATUS_NO_MATCH
            ),
            from_address='unknown@local',
        )
        self.assertEqual(plan.skip_reason, SKIP_NO_RELATIONSHIP)

    def test_lookup_exception_fail_closed(self) -> None:
        def boom(_a, _s):
            raise ConnectionError('db down')

        plan = plan_inbound_delivery(
            classify_inbound=boom,
            account_id=1,
            account_email='acc@ext',
            from_address='client@ext',
            referent_local_inbox='ref@local',
        )
        self.assertIsNotNone(plan.lookup_error)
        self.assertIsNone(plan.skip_reason)


class FanoutJournalRowsTest(unittest.TestCase):
    def test_n_recipients_n_journal_rows(self) -> None:
        writes: List[str] = []

        class CountingJournal:
            def write(self, record: PassageJournalRecord) -> int:
                writes.append(record.local_mailbox or '')
                return len(writes)

        journal = CountingJournal()
        recipients = ['a@local', 'b@local', 'c@local']
        for rcpt in recipients:
            journal_delivered(
                journal,  # type: ignore[arg-type]
                direction=DIRECTION_INBOUND,
                local_mailbox=rcpt,
                client_name='Client',
                referent_name='Ref',
            )
        self.assertEqual(len(writes), 3)
        self.assertEqual(writes, recipients)


if __name__ == '__main__':
    unittest.main(verbosity=2)
