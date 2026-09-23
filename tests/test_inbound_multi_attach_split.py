#!/usr/bin/env python3
"""PROMPT-79.2c — inbound multi-attachment split (replaces interim hold)."""

from __future__ import annotations

import sys
import tempfile
import types
import unittest
from email import encoders, policy
from email.mime.base import MIMEBase
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from attachment_policy import (
    DISPOSAL_EXTENSION,
    DISPOSAL_MULTIPLE,
    DISPOSAL_ZERO,
    classify_inbound_attachments,
    validate_single_archive_attachment,
)
from relationship_lookup import (
    STATUS_MATCHED,
    ClientRelationshipDTO,
    RelationshipClassifyResult,
)


def _archives(*names: str, subject: str | None = None) -> bytes:
    mixed = MIMEMultipart('mixed', policy=policy.SMTP)
    mixed['From'] = 'clientint1@frona.ru'
    mixed['To'] = 'refint1@frona.ru'
    mixed['Subject'] = subject if subject is not None else ' '.join(names)
    mixed['Message-ID'] = '<PROMPT792C-MULTI@testvps.loc>'
    mixed.attach(MIMEText('', 'plain', 'utf-8', policy=policy.SMTP))
    for name in names:
        part = MIMEBase('application', 'octet-stream', policy=policy.SMTP)
        part.set_payload(b'data')
        encoders.encode_base64(part)
        part.add_header('Content-Disposition', 'attachment', filename=name)
        mixed.attach(part)
    return mixed.as_bytes(policy=policy.SMTP)


class ClassifyInboundAttachmentsTest(unittest.TestCase):
    def test_zero(self) -> None:
        mixed = MIMEMultipart('mixed', policy=policy.SMTP)
        mixed.attach(MIMEText('hi', 'plain', 'utf-8', policy=policy.SMTP))
        c = classify_inbound_attachments(mixed.as_bytes(policy=policy.SMTP))
        self.assertTrue(c.is_invalid)
        self.assertEqual(c.reason, DISPOSAL_ZERO)

    def test_single_ok(self) -> None:
        c = classify_inbound_attachments(
            _archives('Contract.zip', subject='Contract.zip')
        )
        self.assertTrue(c.is_ok_single)
        self.assertTrue(c.may_deliver)

    def test_single_subject_mismatch(self) -> None:
        c = classify_inbound_attachments(
            _archives('Contract.zip', subject='wrong.zip')
        )
        self.assertTrue(c.is_invalid)
        self.assertEqual(c.reason, 'subject_mismatch')

    def test_multi_all_approved_is_split(self) -> None:
        c = classify_inbound_attachments(_archives('a.zip', 'b.zip'))
        self.assertTrue(c.is_split)
        self.assertEqual(c.approved_filenames, ('a.zip', 'b.zip'))
        self.assertEqual(c.skipped_filenames, ())

    def test_multi_parent_subject_ignored(self) -> None:
        c = classify_inbound_attachments(
            _archives('a.zip', 'b.zip', subject='totally-unrelated')
        )
        self.assertTrue(c.is_split)

    def test_multi_mixed_skips_disallowed(self) -> None:
        c = classify_inbound_attachments(_archives('a.zip', 'evil.exe', 'b.zip'))
        self.assertTrue(c.is_split)
        self.assertEqual(c.approved_filenames, ('a.zip', 'b.zip'))
        self.assertEqual(c.skipped_filenames, ('evil.exe',))

    def test_multi_all_disallowed(self) -> None:
        c = classify_inbound_attachments(_archives('a.exe', 'b.pdf'))
        self.assertTrue(c.is_invalid)
        self.assertEqual(c.reason, DISPOSAL_EXTENSION)

    def test_validate_single_still_flags_multiple(self) -> None:
        # Outbound gate unchanged
        r = validate_single_archive_attachment(_archives('a.zip', 'b.zip'))
        self.assertEqual(r.reason, DISPOSAL_MULTIPLE)


class InboundMultiAttachSplitDaemonTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        import importlib.util

        watchdog_mod = types.ModuleType('watchdog')
        watchdog_events = types.ModuleType('watchdog.events')
        watchdog_events.FileSystemEventHandler = object
        watchdog_observers = types.ModuleType('watchdog.observers')
        watchdog_observers.Observer = MagicMock
        sys.modules.setdefault('watchdog', watchdog_mod)
        sys.modules.setdefault('watchdog.events', watchdog_events)
        sys.modules.setdefault('watchdog.observers', watchdog_observers)

        spec = importlib.util.spec_from_file_location(
            'mail_proxy_daemon_792c_split_test',
            str(ROOT / 'mail-proxy-daemon.py'),
        )
        cls.mpd = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(cls.mpd)

    def _handler_with_dto(self, maildir: Path):
        handler = self.mpd.MailHandler(MagicMock(), MagicMock())
        journal = MagicMock()
        journal.write = MagicMock(side_effect=lambda *a, **k: 1)
        handler._passage_journal = journal
        dto = ClientRelationshipDTO(
            relationship_id=1,
            referent_id=1,
            external_client_email='clientint1@frona.ru',
            local_client_email='clientloc1@testvps.loc',
            local_referent_email='refloc1@testvps.loc',
            external_account_id=1,
            external_referent_email='refint1@frona.ru',
            local_client_maildir=str(maildir),
            account={'id': 1, 'email': 'refint1@frona.ru'},
            referent={
                'id': 1,
                'username': 'Test',
                'local_inbox': 'refloc1@testvps.loc',
            },
        )
        classified = RelationshipClassifyResult(
            status=STATUS_MATCHED,
            dto=dto,
            relationship_id=1,
            referent_name='Test',
            client_name='clientint1@frona.ru',
            local_mailbox='refloc1@testvps.loc',
            external_mailbox='clientint1@frona.ru',
        )
        handler._relationship_lookup = MagicMock()
        handler._relationship_lookup.classify_inbound = MagicMock(
            return_value=classified
        )
        handler._relationship_lookup.resolve_inbound = MagicMock(return_value=dto)
        return handler, journal, dto

    def test_multi_split_delivers_and_disposes_on_full_success(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'multi.eml'
            path.write_bytes(_archives('a.zip', 'b.zip'))
            handler, journal, dto = self._handler_with_dto(maildir)

            smtp = MagicMock()
            with patch('smtplib.SMTP', return_value=smtp):
                handler._stream_file_via_smtp = MagicMock(return_value=True)
                result = handler._deliver_to_local_smtp(
                    path,
                    {
                        'id': 1,
                        'username': 'Test',
                        'local_inbox': 'refloc1@testvps.loc',
                    },
                    {'id': 1, 'email': 'refint1@frona.ru'},
                )

            self.assertTrue(result.mark_imap_seen)
            self.assertFalse(result.dispose_imap)  # dispose via mark_imap_seen path for success
            # finalize: smtp_delivered True → mark_imap_seen True, dispose_imap False
            # Wait - looking at finalize: dispose_imap only for dispose path;
            # successful delivery uses mark_imap_seen=True, dispose_imap=False.
            # Actually IMAP dispose for inbound success is mark Seen only for delivered?
            # Looking at original: finalize with smtp_delivered True → mark_imap_seen=True, dispose_imap=False
            # And the IMAP loop: dispose_imap → EXPUNGE; elif mark_imap_seen → STORE Seen
            # So successful delivery marks Seen, does NOT EXPUNGE? That seems to be existing behavior.
            self.assertTrue(result.local_delivered)
            self.assertIsNone(result.smtp_error)
            self.assertEqual(handler._stream_file_via_smtp.call_count, 2)
            # journal_delivered uses journal.write via MailPassageJournal - we mocked write on MagicMock
            # journal_delivered calls journal.write(PassageJournalRecord...)
            self.assertGreaterEqual(journal.write.call_count, 2)
            self.assertNotEqual(result.smtp_error, 'interim_multi_attachment_hold')

    def test_multi_partial_smtp_failure_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'multi.eml'
            path.write_bytes(_archives('a.zip', 'b.zip'))
            handler, journal, _dto = self._handler_with_dto(maildir)
            smtp = MagicMock()
            with patch('smtplib.SMTP', return_value=smtp):
                handler._stream_file_via_smtp = MagicMock(side_effect=[True, False])
                result = handler._deliver_to_local_smtp(
                    path,
                    {
                        'id': 1,
                        'username': 'Test',
                        'local_inbox': 'refloc1@testvps.loc',
                    },
                    {'id': 1, 'email': 'refint1@frona.ru'},
                )
            self.assertFalse(result.mark_imap_seen)
            self.assertFalse(result.dispose_imap)
            self.assertFalse(result.local_delivered)
            self.assertEqual(result.smtp_error, 'fanout_incomplete')

    def test_multi_all_disallowed_disposes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'bad.eml'
            path.write_bytes(_archives('a.exe', 'b.pdf'))
            handler, journal, _dto = self._handler_with_dto(maildir)
            # journal_disposed uses MailPassageJournal.write
            from mail_passage_journal import MailPassageJournal

            real_journal = MailPassageJournal(MagicMock())
            # Use MagicMock that supports write returning id
            j = MagicMock()
            j.write = MagicMock(return_value=99)
            handler._passage_journal = j
            result = handler._deliver_to_local_smtp(
                path,
                {
                    'id': 1,
                    'username': 'Test',
                    'local_inbox': 'refloc1@testvps.loc',
                },
                {'id': 1, 'email': 'refint1@frona.ru'},
            )
            self.assertTrue(result.dispose_imap)
            self.assertFalse(result.mark_imap_seen)
            j.write.assert_called()
            # disposed record
            rec = j.write.call_args[0][0]
            self.assertEqual(rec.disposal_reason, DISPOSAL_EXTENSION)

    def test_no_interim_hold_marker(self) -> None:
        src = (ROOT / 'mail-proxy-daemon.py').read_text(encoding='utf-8')
        self.assertNotIn('PROMPT-79.2-INTERIM', src)
        self.assertNotIn('interim_multi_attachment_hold', src)


if __name__ == '__main__':
    unittest.main(verbosity=2)