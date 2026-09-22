#!/usr/bin/env python3
"""
PROMPT-77 — message rebuild unit tests (spec §6.1 F1–F13, §6.2 O1–O7).
"""

from __future__ import annotations

import email
import os
import sys
import tempfile
import unittest
from email import encoders, policy
from email.mime.base import MIMEBase
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from pathlib import Path
from typing import List, Optional
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from message_rebuild import (
    FanoutRebuildResult,
    rebuild_inbound_fanout,
    rebuild_outbound_message,
)
from relationship_lookup import ClientRelationshipDTO
from relationship_routing import InboundDeliveryPlan, InboundRoutingMode


def _dto(
    relationship_id: int = 1,
    external_account_id: int = 10,
    external_client: str = 'clientext@partner.com',
    local_client: str = 'clientloc@testvps.loc',
    local_referent: str = 'refloc@testvps.loc',
    external_referent: str = 'refext@partner.com',
) -> ClientRelationshipDTO:
    return ClientRelationshipDTO(
        relationship_id=relationship_id,
        referent_id=1,
        external_client_email=external_client,
        local_client_email=local_client,
        local_referent_email=local_referent,
        external_account_id=external_account_id,
        external_referent_email=external_referent,
        local_client_maildir='/var/vmail/clientloc/Maildir',
        account={'id': external_account_id, 'email': external_referent},
        referent={'id': 1, 'local_inbox': local_referent},
    )


def _normalize_rfc822(raw: bytes) -> str:
    return raw.replace(b'\r\n', b'\n').decode('utf-8', errors='replace')


def _attachment_count(msg: email.message.Message) -> int:
    count = 0
    if msg.is_multipart():
        for part in msg.walk():
            disp = (part.get('Content-Disposition') or '').lower()
            if 'attachment' in disp:
                count += 1
    return count


def _build_mixed_message(
    *,
    attachments: List[dict],
    inline_parts: Optional[List[dict]] = None,
    body_text: str = 'Body text',
) -> bytes:
    mixed = MIMEMultipart('mixed', policy=policy.SMTP)
    mixed.attach(MIMEText(body_text, 'plain', 'utf-8', policy=policy.SMTP))
    for inline in inline_parts or []:
        part = MIMEBase('image', 'png', policy=policy.SMTP)
        part.set_payload(inline.get('content', b'png-bytes'))
        encoders.encode_base64(part)
        part.add_header(
            'Content-Disposition',
            'inline',
            filename=inline.get('filename', 'sig.png'),
        )
        mixed.attach(part)
    for att in attachments:
        part = MIMEBase('application', 'octet-stream', policy=policy.SMTP)
        part.set_payload(att.get('content', b'data'))
        encoders.encode_base64(part)
        part.add_header(
            'Content-Disposition',
            'attachment',
            filename=att['filename'],
        )
        mixed.attach(part)
    mixed['Subject'] = ' '.join(a['filename'] for a in attachments)
    mixed['From'] = 'sender@internet.example'
    mixed['To'] = 'recipient@internet.example'
    return mixed.as_bytes(policy=policy.SMTP)


class MessageRebuildFixtureTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.mkdtemp(prefix='rebuild_test_')

    def tearDown(self) -> None:
        for root, _dirs, files in os.walk(self.temp_dir, topdown=False):
            for name in files:
                try:
                    os.unlink(os.path.join(root, name))
                except OSError:
                    pass
        try:
            os.rmdir(self.temp_dir)
        except OSError:
            pass

    def _fanout(self, raw: bytes, dto: Optional[ClientRelationshipDTO] = None) -> FanoutRebuildResult:
        return rebuild_inbound_fanout(
            raw,
            dto or _dto(),
            temp_dir=self.temp_dir,
        )

    def _outbound(self, raw: bytes, dto: Optional[ClientRelationshipDTO] = None):
        return rebuild_outbound_message(
            raw,
            dto or _dto(),
            temp_dir=self.temp_dir,
        )

    def _read_child(self, path: Path) -> email.message.Message:
        with open(path, 'rb') as handle:
            return email.message_from_binary_file(handle, policy=policy.SMTP)

    # --- Inbound F1–F13 ---

    def test_F1_single_attachment_inbound(self) -> None:
        raw = _build_mixed_message(attachments=[{'filename': 'doc.pdf', 'content': b'%PDF'}])
        result = self._fanout(raw)
        self.assertTrue(result.success)
        self.assertEqual(len(result.temp_paths), 1)
        msg = self._read_child(result.temp_paths[0])
        self.assertEqual(msg['From'], 'clientloc@testvps.loc')
        self.assertEqual(msg['To'], 'refloc@testvps.loc')
        self.assertEqual(msg['Subject'], 'doc.pdf')
        self.assertEqual(_attachment_count(msg), 1)

    def test_F2_F3_three_attachment_fanout(self) -> None:
        raw = _build_mixed_message(
            attachments=[
                {'filename': 'a.pdf', 'content': b'a'},
                {'filename': 'b.docx', 'content': b'b'},
                {'filename': 'c.png', 'content': b'c'},
            ]
        )
        result = self._fanout(raw)
        self.assertTrue(result.success)
        self.assertEqual(len(result.temp_paths), 3)
        subjects = []
        for path in result.temp_paths:
            msg = self._read_child(path)
            subjects.append(msg['Subject'])
            self.assertEqual(_attachment_count(msg), 1)
            self.assertEqual(msg['From'], 'clientloc@testvps.loc')
            self.assertEqual(msg['To'], 'refloc@testvps.loc')
        self.assertEqual(subjects, ['a.pdf', 'b.docx', 'c.png'])
        for path in result.temp_paths:
            msg = self._read_child(path)
            self.assertNotIn('a.pdf b.docx c.png', msg['Subject'])

    def test_F4_zero_attachments_fail_closed(self) -> None:
        raw = _build_mixed_message(attachments=[], body_text='text only')
        result = self._fanout(raw)
        self.assertFalse(result.success)
        self.assertEqual(result.reason, 'zero_attachments')
        self.assertEqual(result.temp_paths, [])

    def test_F5_rebuild_failure_all_or_nothing(self) -> None:
        raw = _build_mixed_message(
            attachments=[
                {'filename': 'one.pdf', 'content': b'1'},
                {'filename': 'two.pdf', 'content': b'2'},
                {'filename': 'three.pdf', 'content': b'3'},
            ]
        )
        calls = {'n': 0}

        def flaky_write(data: bytes, temp_dir: str, *, prefix: str) -> Path:
            calls['n'] += 1
            if calls['n'] == 2:
                raise OSError('simulated write failure')
            from message_rebuild import _write_temp_file as real_write

            return real_write(data, temp_dir, prefix=prefix)

        with patch('message_rebuild._write_temp_file', side_effect=flaky_write):
            result = self._fanout(raw)
        self.assertFalse(result.success)
        self.assertEqual(result.temp_paths, [])

    def test_F8_inline_discarded_one_attachment(self) -> None:
        raw = _build_mixed_message(
            attachments=[{'filename': 'real.pdf', 'content': b'pdf'}],
            inline_parts=[{'filename': 'sig.png', 'content': b'x'}],
        )
        result = self._fanout(raw)
        self.assertTrue(result.success)
        self.assertEqual(len(result.temp_paths), 1)
        msg = self._read_child(result.temp_paths[0])
        self.assertEqual(msg['Subject'], 'real.pdf')

    def test_F9_nested_rfc822_fail_closed(self) -> None:
        inner = MIMEText('nested', 'plain', 'utf-8', policy=policy.SMTP)
        wrapper = MIMEBase('message', 'rfc822', policy=policy.SMTP)
        wrapper.set_payload(inner.as_bytes(policy=policy.SMTP))
        outer = MIMEMultipart('mixed', policy=policy.SMTP)
        outer.attach(wrapper)
        result = self._fanout(outer.as_bytes(policy=policy.SMTP))
        self.assertFalse(result.success)
        self.assertEqual(result.reason, 'malformed_mime')

    def test_F10_smime_fail_closed(self) -> None:
        signed = MIMEMultipart('signed', policy=policy.SMTP)
        signed.attach(MIMEText('body', 'plain', 'utf-8', policy=policy.SMTP))
        part = MIMEBase('application', 'pgp-signature', policy=policy.SMTP)
        part.set_payload(b'-----BEGIN PGP SIGNATURE-----')
        signed.attach(part)
        result = self._fanout(signed.as_bytes(policy=policy.SMTP))
        self.assertFalse(result.success)
        self.assertEqual(result.reason, 'signed_or_encrypted')

    def test_F11_truncated_mime_fail_closed(self) -> None:
        raw = b'Content-Type: multipart/mixed; boundary="abc"\n\n--abc\nbroken'
        result = self._fanout(raw)
        self.assertFalse(result.success)

    def test_F13_cross_relationship_isolation(self) -> None:
        raw = _build_mixed_message(attachments=[{'filename': 'x.pdf', 'content': b'x'}])
        dto_a = _dto(
            relationship_id=1,
            local_client='client_a@local.loc',
            local_referent='referent_a@local.loc',
        )
        dto_b = _dto(
            relationship_id=2,
            local_client='client_b@local.loc',
            local_referent='referent_b@local.loc',
        )
        result_a = self._fanout(raw, dto_a)
        result_b = self._fanout(raw, dto_b)
        msg_a = self._read_child(result_a.temp_paths[0])
        msg_b = self._read_child(result_b.temp_paths[0])
        self.assertEqual(msg_a['From'], 'client_a@local.loc')
        self.assertNotIn('client_b@local.loc', msg_a['From'])
        self.assertEqual(msg_b['From'], 'client_b@local.loc')
        self.assertNotIn('client_a@local.loc', msg_b['From'])

    # --- Outbound O1–O7 ---

    def test_O1_single_attachment_outbound(self) -> None:
        raw = _build_mixed_message(attachments=[{'filename': 'out.pdf', 'content': b'pdf'}])
        result = self._outbound(raw)
        self.assertTrue(result.success)
        self.assertIsNotNone(result.temp_path)
        msg = self._read_child(result.temp_path)
        self.assertEqual(msg['From'], 'refext@partner.com')
        self.assertEqual(msg['To'], 'clientext@partner.com')
        self.assertEqual(msg['Subject'], 'out.pdf')
        self.assertEqual(_attachment_count(msg), 1)

    def test_O2_zero_attachments_outbound_fail_closed(self) -> None:
        raw = _build_mixed_message(attachments=[], body_text='only text')
        result = self._outbound(raw)
        self.assertFalse(result.success)
        self.assertEqual(result.reason, 'zero_attachments')

    def test_O3_two_attachments_outbound_fail_closed(self) -> None:
        raw = _build_mixed_message(
            attachments=[
                {'filename': 'one.pdf', 'content': b'1'},
                {'filename': 'two.pdf', 'content': b'2'},
            ]
        )
        result = self._outbound(raw)
        self.assertFalse(result.success)
        self.assertEqual(result.reason, 'unexpected_multi_attachment')

    def test_O4_inline_discarded_outbound(self) -> None:
        raw = _build_mixed_message(
            attachments=[{'filename': 'only.pdf', 'content': b'x'}],
            inline_parts=[{'filename': 'inline.png', 'content': b'y'}],
        )
        result = self._outbound(raw)
        self.assertTrue(result.success)
        msg = self._read_child(result.temp_path)
        self.assertEqual(msg['Subject'], 'only.pdf')

    def test_O5_smime_outbound_fail_closed(self) -> None:
        signed = MIMEMultipart('signed', policy=policy.SMTP)
        signed.attach(MIMEText('body', 'plain', 'utf-8', policy=policy.SMTP))
        result = self._outbound(signed.as_bytes(policy=policy.SMTP))
        self.assertFalse(result.success)
        self.assertEqual(result.reason, 'signed_or_encrypted')

    def test_O6_outbound_headers_regenerated(self) -> None:
        raw = _build_mixed_message(attachments=[{'filename': 'hdr.dat', 'content': b'z'}])
        result = self._outbound(raw)
        msg = self._read_child(result.temp_path)
        self.assertEqual(msg['Subject'], 'hdr.dat')
        self.assertTrue(msg['Message-ID'])
        self.assertTrue(msg['Date'])
        self.assertIsNone(msg.get('Cc'))
        self.assertIsNone(msg.get('References'))

    def test_O7_outbound_cross_relationship_isolation(self) -> None:
        raw = _build_mixed_message(attachments=[{'filename': 'z.bin', 'content': b'z'}])
        dto_a = _dto(
            external_client='ext_a@partner.com',
            external_referent='ref_a@partner.com',
        )
        dto_b = _dto(
            external_client='ext_b@partner.com',
            external_referent='ref_b@partner.com',
        )
        msg_a = self._read_child(self._outbound(raw, dto_a).temp_path)
        msg_b = self._read_child(self._outbound(raw, dto_b).temp_path)
        self.assertEqual(msg_a['To'], 'ext_a@partner.com')
        self.assertEqual(msg_b['To'], 'ext_b@partner.com')


def _load_daemon_module():
    import importlib.util
    import types

    watchdog_mod = types.ModuleType('watchdog')
    watchdog_events = types.ModuleType('watchdog.events')
    watchdog_events.FileSystemEventHandler = object
    watchdog_observers = types.ModuleType('watchdog.observers')
    watchdog_observers.Observer = MagicMock
    sys.modules.setdefault('watchdog', watchdog_mod)
    sys.modules.setdefault('watchdog.events', watchdog_events)
    sys.modules.setdefault('watchdog.observers', watchdog_observers)

    spec = importlib.util.spec_from_file_location(
        'mail_proxy_daemon',
        ROOT / 'mail-proxy-daemon.py',
    )
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


DAEMON = None


def _daemon():
    global DAEMON
    if DAEMON is None:
        DAEMON = _load_daemon_module()
    return DAEMON


class MessageRebuildDaemonIntegrationTest(unittest.TestCase):
    """F6, F7 — daemon wiring and RD-13 SMTP orchestration."""

    def setUp(self) -> None:
        self.temp_dir = tempfile.mkdtemp(prefix='rebuild_daemon_')

    def tearDown(self) -> None:
        for root, _dirs, files in os.walk(self.temp_dir, topdown=False):
            for name in files:
                try:
                    os.unlink(os.path.join(root, name))
                except OSError:
                    pass
        try:
            os.rmdir(self.temp_dir)
        except OSError:
            pass

    def _handler(self):
        return _daemon().MailHandler(MagicMock(), MagicMock())

    def _write_mail_file(self, raw: bytes) -> Path:
        path = Path(self.temp_dir) / 'inbound.eml'
        path.write_bytes(raw)
        return path

    def test_F6_partial_smtp_failure_leaves_fanout_incomplete(self) -> None:
        handler = self._handler()
        raw = _build_mixed_message(
            attachments=[
                {'filename': 'a.pdf', 'content': b'a'},
                {'filename': 'b.pdf', 'content': b'b'},
                {'filename': 'c.pdf', 'content': b'c'},
            ]
        )
        mail_file = self._write_mail_file(raw)
        plan = InboundDeliveryPlan(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            local_rcpts=['refloc@testvps.loc'],
            mail_from='refloc@testvps.loc',
            relationship_id=1,
        )
        dto = _dto()
        handler._relationship_lookup.resolve_inbound = MagicMock(return_value=dto)
        smtp = MagicMock()
        stream_results = [True, False]

        def stream_side_effect(_smtp, _mail_from, _rcpts, _path) -> bool:
            return stream_results.pop(0)

        handler._stream_file_via_smtp = MagicMock(side_effect=stream_side_effect)
        delivered, error = handler._deliver_inbound_fanout_via_smtp(
            smtp=smtp,
            mail_file=mail_file,
            plan=plan,
            account_id=10,
            from_addr='clientext@partner.com',
        )
        self.assertFalse(delivered)
        self.assertEqual(error, 'fanout_incomplete')
        self.assertEqual(handler._stream_file_via_smtp.call_count, 2)

    def test_F7_retry_after_partial_delivery_attempts_fanout_again(self) -> None:
        handler = self._handler()
        raw = _build_mixed_message(
            attachments=[
                {'filename': 'a.pdf', 'content': b'a'},
                {'filename': 'b.pdf', 'content': b'b'},
            ]
        )
        mail_file = self._write_mail_file(raw)
        plan = InboundDeliveryPlan(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            local_rcpts=['refloc@testvps.loc'],
            mail_from='refloc@testvps.loc',
            relationship_id=1,
        )
        dto = _dto()
        handler._relationship_lookup.resolve_inbound = MagicMock(return_value=dto)
        smtp = MagicMock()
        attempts = {'n': 0}

        def stream_side_effect(_smtp, _mail_from, _rcpts, _path) -> bool:
            attempts['n'] += 1
            return attempts['n'] != 2

        handler._stream_file_via_smtp = MagicMock(side_effect=stream_side_effect)
        first = handler._deliver_inbound_fanout_via_smtp(
            smtp=smtp,
            mail_file=mail_file,
            plan=plan,
            account_id=10,
            from_addr='clientext@partner.com',
        )
        second = handler._deliver_inbound_fanout_via_smtp(
            smtp=smtp,
            mail_file=mail_file,
            plan=plan,
            account_id=10,
            from_addr='clientext@partner.com',
        )
        self.assertEqual(first, (False, 'fanout_incomplete'))
        self.assertEqual(second, (True, None))
        self.assertEqual(handler._stream_file_via_smtp.call_count, 4)

if __name__ == '__main__':
    unittest.main()
