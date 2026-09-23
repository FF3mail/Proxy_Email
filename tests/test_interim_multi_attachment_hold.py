#!/usr/bin/env python3
"""PROMPT-79.2-incident-check — interim multi-attachment hold regression."""

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
from unittest.mock import MagicMock

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from attachment_policy import DISPOSAL_MULTIPLE, validate_single_archive_attachment
from relationship_lookup import (
    STATUS_MATCHED,
    ClientRelationshipDTO,
    RelationshipClassifyResult,
)


def _two_archives() -> bytes:
    mixed = MIMEMultipart('mixed', policy=policy.SMTP)
    mixed['From'] = 'clientint1@frona.ru'
    mixed['To'] = 'refint1@frona.ru'
    mixed['Subject'] = 'two archives'
    mixed['Message-ID'] = '<PROMPT792-INTERIM-MULTI@testvps.loc>'
    mixed.attach(MIMEText('', 'plain', 'utf-8', policy=policy.SMTP))
    for name in ('a.zip', 'b.zip'):
        part = MIMEBase('application', 'zip', policy=policy.SMTP)
        part.set_payload(b'PK\x03\x04data')
        encoders.encode_base64(part)
        part.add_header('Content-Disposition', 'attachment', filename=name)
        mixed.attach(part)
    return mixed.as_bytes(policy=policy.SMTP)


class InterimMultiAttachmentHoldTest(unittest.TestCase):
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
            'mail_proxy_daemon_interim_hold_test',
            str(ROOT / 'mail-proxy-daemon.py'),
        )
        cls.mpd = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(cls.mpd)

    def test_policy_still_flags_multiple(self) -> None:
        result = validate_single_archive_attachment(_two_archives())
        self.assertTrue(result.is_invalid)
        self.assertEqual(result.reason, DISPOSAL_MULTIPLE)

    def test_inbound_multi_held_not_disposed(self) -> None:
        handler = self.mpd.MailHandler(MagicMock(), MagicMock())
        journal = MagicMock()
        handler._passage_journal = journal

        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'multi.eml'
            path.write_bytes(_two_archives())
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
        self.assertFalse(result.dispose_imap)
        self.assertFalse(result.local_delivered)
        self.assertEqual(result.smtp_error, 'interim_multi_attachment_hold')
        journal.write.assert_not_called()
        self.assertEqual(journal.method_calls, [])


if __name__ == '__main__':
    unittest.main(verbosity=2)