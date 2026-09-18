#!/usr/bin/env python3
"""
PROMPT-77.2 — IMAP fetch must not set \\Seen; STORE is the sole Seen gate.

Regression: FETCH (RFC822) marks \\Seen on the server regardless of
mark_imap_seen=False. BODY.PEEK[] leaves the message UNSEEN until an
explicit STORE +FLAGS \\Seen.

Also records Defect 2 mechanism evidence: rebuilt inbound From matches
resolve_outbound; raw external From does not.
"""

from __future__ import annotations

import email
import sys
import tempfile
import unittest
from email import encoders, policy
from email.mime.base import MIMEBase
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from pathlib import Path
from typing import Any, List, Optional, Set

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from message_rebuild import rebuild_inbound_fanout
from relationship_lookup import ClientRelationshipDTO, normalize_email
from relationship_routing import (
    OutboundRoutingMode,
    extract_outbound_identity_from_message,
    plan_outbound_delivery,
)

DAEMON_SOURCE = ROOT / 'mail-proxy-daemon.py'


class FakeImapMailbox:
    """
    Minimal IMAP stand-in encoding the protocol Seen side-effect:
    non-PEEK full-body fetch items set \\Seen; BODY.PEEK[] does not.
    Response shape mirrors imaplib: [(header, literal), b')'].
    """

    def __init__(self, body: bytes = b'From: ext@partner.com\r\n\r\nbody') -> None:
        self.body = body
        self.flags: Set[str] = set()
        self.fetch_parts_history: List[str] = []
        self.store_history: List[tuple] = []

    def fetch(self, num: Any, parts: str):
        self.fetch_parts_history.append(parts)
        parts_u = parts.upper()
        is_size_probe = 'RFC822.SIZE' in parts_u
        is_bodystructure = 'BODYSTRUCTURE' in parts_u
        is_full_body = (
            not is_size_probe
            and not is_bodystructure
            and (
                ('RFC822' in parts_u and 'PEEK' not in parts_u)
                or ('BODY[]' in parts_u.replace(' ', '') and 'PEEK' not in parts_u)
            )
        )
        if is_full_body:
            self.flags.add('\\Seen')

        if 'PEEK' in parts_u:
            label = b'BODY[]'
        elif 'RFC822' in parts_u and not is_size_probe:
            label = b'RFC822'
        else:
            label = b'META'
        seq = num if isinstance(num, bytes) else str(num).encode()
        header = b'%s (%s {%d}' % (seq, label, len(self.body))
        return 'OK', [(header, self.body), b')']

    def store(self, num: Any, command: str, flags: str):
        self.store_history.append((num, command, flags))
        if command == '+FLAGS' and '\\Seen' in flags:
            self.flags.add('\\Seen')
        return 'OK', [b'']


class TestImapFetchSeen(unittest.TestCase):
    def test_peek_fetch_leaves_unseen(self) -> None:
        """Production fetch item must not mark Seen (fail-closed remains retryable)."""
        mail = FakeImapMailbox()
        status, data = mail.fetch(b'1', '(BODY.PEEK[])')
        self.assertEqual(status, 'OK')
        self.assertIsInstance(data[0], tuple)
        self.assertEqual(data[0][1], mail.body)
        self.assertNotIn('\\Seen', mail.flags)

    def test_rfc822_fetch_marks_seen(self) -> None:
        """Pre-fix path: RFC822 full fetch sets Seen — this is the defect model."""
        mail = FakeImapMailbox()
        status, data = mail.fetch(b'1', '(RFC822)')
        self.assertEqual(status, 'OK')
        self.assertEqual(data[0][1], mail.body)
        self.assertIn('\\Seen', mail.flags)

    def test_daemon_poll_fetch_uses_body_peek(self) -> None:
        """
        Source-level contract: poll_external_imap must fetch with BODY.PEEK[].
        Revert the fetch string to (RFC822) and this test fails (PROMPT-73.1 style).
        """
        source = DAEMON_SOURCE.read_text(encoding='utf-8')
        self.assertIn("mail.fetch(num, '(BODY.PEEK[])')", source)
        for line in source.splitlines():
            stripped = line.strip()
            if 'mail.fetch(' not in stripped:
                continue
            if 'RFC822.SIZE' in stripped or 'BODYSTRUCTURE' in stripped:
                continue
            if "'(RFC822)'" in stripped or '"(RFC822)"' in stripped:
                self.fail(f'full-body RFC822 fetch must not remain: {stripped}')

    def test_fail_closed_gate_store_only_marks_seen(self) -> None:
        """
        End-to-end gate: PEEK fetch + mark_imap_seen=False → UNSEEN;
        only STORE +FLAGS \\Seen sets the flag.
        """
        mail = FakeImapMailbox()
        status, data = mail.fetch(b'1', '(BODY.PEEK[])')
        self.assertEqual(status, 'OK')
        raw_email = data[0][1]
        self.assertEqual(raw_email, mail.body)
        self.assertNotIn('\\Seen', mail.flags)

        mark_imap_seen = False  # zero_attachments / rebuild failure
        if mark_imap_seen:
            mail.store(b'1', '+FLAGS', '\\Seen')
        self.assertNotIn('\\Seen', mail.flags)

        mark_imap_seen = True  # successful delivery
        if mark_imap_seen:
            mail.store(b'1', '+FLAGS', '\\Seen')
        self.assertIn('\\Seen', mail.flags)
        self.assertEqual(len(mail.store_history), 1)

    def test_peek_response_tuple_shape_matches_rfc822(self) -> None:
        """data[0][1] literal extraction works for both PEEK and RFC822 shapes."""
        peek_mail = FakeImapMailbox(b'peek-body')
        rfc_mail = FakeImapMailbox(b'rfc-body')
        _, peek_data = peek_mail.fetch(b'1', '(BODY.PEEK[])')
        _, rfc_data = rfc_mail.fetch(b'1', '(RFC822)')
        self.assertIsInstance(peek_data[0], tuple)
        self.assertIsInstance(rfc_data[0], tuple)
        self.assertEqual(len(peek_data[0]), 2)
        self.assertEqual(len(rfc_data[0]), 2)
        self.assertIsInstance(peek_data[0][1], bytes)
        self.assertIsInstance(rfc_data[0][1], bytes)
        self.assertEqual(peek_data[1], b')')
        self.assertEqual(rfc_data[1], b')')


class TestRebuildOutboundEchoMechanism(unittest.TestCase):
    """
    Defect 2 investigation evidence (PROMPT-77.2): rebuilt inbound From matches
    resolve_outbound; raw external From does not — explaining the echo under
    referent_only watch of the referent Maildir.
    """

    def test_rebuilt_from_matches_outbound_identity_external_does_not(self) -> None:
        dto = ClientRelationshipDTO(
            relationship_id=1,
            referent_id=1,
            external_client_email='clientint1@frona.ru',
            local_client_email='clientloc1@testvps.loc',
            local_referent_email='refloc1@testvps.loc',
            external_account_id=1,
            external_referent_email='refint1@frona.ru',
            local_client_maildir='/var/vmail/clientloc1/Maildir',
            account={'id': 1, 'email': 'refint1@frona.ru'},
            referent={'id': 1, 'local_inbox': 'refloc1@testvps.loc'},
        )

        raw_external = (
            b'From: clientint1@frona.ru\r\n'
            b'To: refint1@frona.ru\r\n'
            b'Subject: raw\r\n'
            b'MIME-Version: 1.0\r\n'
            b'Content-Type: text/plain\r\n'
            b'\r\n'
            b'hi\r\n'
        )
        raw_msg = email.message_from_bytes(raw_external)
        raw_from = extract_outbound_identity_from_message(raw_msg)
        self.assertEqual(normalize_email(raw_from), 'clientint1@frona.ru')

        def resolve_outbound(identity: str) -> Optional[ClientRelationshipDTO]:
            if normalize_email(identity) == normalize_email(dto.local_client_email):
                return dto
            return None

        raw_plan = plan_outbound_delivery(
            mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_outbound=resolve_outbound,
            load_legacy_account=lambda _r: None,
            from_address=raw_from,
            referent_id=1,
            shadow_enabled=False,
        )
        self.assertEqual(raw_plan.skip_reason, 'no_relationship_match')

        mixed = MIMEMultipart('mixed', policy=policy.SMTP)
        mixed['From'] = 'clientint1@frona.ru'
        mixed['To'] = 'refint1@frona.ru'
        mixed.attach(MIMEText('x', 'plain', 'utf-8', policy=policy.SMTP))
        part = MIMEBase('application', 'octet-stream', policy=policy.SMTP)
        part.set_payload(b'payload')
        encoders.encode_base64(part)
        part.add_header('Content-Disposition', 'attachment', filename='a.bin')
        mixed.attach(part)

        with tempfile.TemporaryDirectory() as tmp:
            fanout = rebuild_inbound_fanout(
                mixed.as_bytes(policy=policy.SMTP),
                dto,
                temp_dir=tmp,
            )
            self.assertTrue(fanout.success)
            child = email.message_from_bytes(fanout.temp_paths[0].read_bytes())
            child_from = extract_outbound_identity_from_message(child)
            self.assertEqual(
                normalize_email(child_from),
                normalize_email(dto.local_client_email),
            )
            rebuild_plan = plan_outbound_delivery(
                mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
                resolve_outbound=resolve_outbound,
                load_legacy_account=lambda _r: None,
                from_address=child_from,
                referent_id=1,
                shadow_enabled=False,
            )
            self.assertIsNone(rebuild_plan.skip_reason)
            self.assertEqual(rebuild_plan.relationship_id, 1)


if __name__ == '__main__':
    unittest.main()
