#!/usr/bin/env python3
"""Issue #34 — any message/* with attachment disposition → nested_message (inbound)."""

from __future__ import annotations

import base64
import sys
import tempfile
import unittest
from email import policy
from email.mime.base import MIMEBase
from email.mime.message import MIMEMessage
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from attachment_policy import (
    DISPOSAL_NESTED_MESSAGE,
    DISPOSAL_ZERO,
    classify_inbound_attachments,
)

# Reuse helpers from PROMPT-79.2d tests.
from tests.test_inbound_multi_attach_split import (  # noqa: E402
    _nested_rfc822_attachment,
    _mixed,
    _part,
)


def _zip_part_bytes(name: str = 'inner.zip', payload: bytes = b'PK\x03\x04data') -> bytes:
    b64 = base64.b64encode(payload).decode('ascii')
    return (
        'Content-Type: application/zip; name="%s"\r\n'
        'Content-Disposition: attachment; filename="%s"\r\n'
        'Content-Transfer-Encoding: base64\r\n'
        '\r\n'
        '%s\r\n'
    ) % (name, name, b64)


def _mixed_headers(*, subject: str, boundary: str = 'outer') -> str:
    return (
        'From: client-a@lab-a.example.test\r\n'
        'To: referent-a@lab-a.example.test\r\n'
        'Subject: %s\r\n'
        'MIME-Version: 1.0\r\n'
        'Content-Type: multipart/mixed; boundary="%s"\r\n'
        '\r\n'
    ) % (subject, boundary)


def _raw_nested_attachment(
    msg_subtype: str,
    *,
    filename: str,
    subject: str,
    ctype_case: str | None = None,
) -> bytes:
    """RAW MIME: multipart/mixed + message/<subtype> attachment wrapping inner .zip."""
    boundary = 'outer'
    inner_b = 'inner'
    ctype = ctype_case or ('message/%s' % msg_subtype)
    hdr = _mixed_headers(subject=subject, boundary=boundary)
    body = (
        '--%s\r\n'
        'Content-Type: text/plain; charset="utf-8"\r\n'
        '\r\n'
        '\r\n'
        '--%s\r\n'
        'Content-Type: %s\r\n'
        'Content-Disposition: attachment; filename="%s"\r\n'
        '\r\n'
        'Content-Type: multipart/mixed; boundary="%s"\r\n'
        '\r\n'
        '--%s\r\n'
        'Content-Type: text/plain; charset="utf-8"\r\n'
        '\r\n'
        '\r\n'
        '--%s\r\n'
        '%s'
        '--%s--\r\n'
        '--%s--\r\n'
    ) % (
        boundary,
        boundary,
        ctype,
        filename,
        inner_b,
        inner_b,
        inner_b,
        _zip_part_bytes(),
        inner_b,
        boundary,
    )
    return (hdr + body).encode('utf-8')


_RAW_DSN = b"""From: mailer-daemon@example.test
To: client-a@lab-a.example.test
Subject: Delivery Status Notification
MIME-Version: 1.0
Content-Type: multipart/report; report-type=delivery-status; boundary="dsn"

--dsn
Content-Type: text/plain; charset="utf-8"

Your message could not be delivered.

--dsn
Content-Type: message/delivery-status

Reporting-MTA: dns; example.test
Action: failed

--dsn--
"""

_RAW_MDN = b"""From: client-a@lab-a.example.test
To: referent-a@lab-a.example.test
Subject: Read: Test
MIME-Version: 1.0
Content-Type: multipart/report; report-type=disposition-notification; boundary="mdn"

--mdn
Content-Type: text/plain; charset="utf-8"

Your message was read.

--mdn
Content-Type: message/disposition-notification

Reporting-UA: Example; 1.0
Disposition: manual-action/MDN-sent-manually; displayed

--mdn--
"""

_RAW_PLAIN = b"""From: client-a@lab-a.example.test
To: referent-a@lab-a.example.test
Subject: Test
MIME-Version: 1.0
Content-Type: text/plain; charset="utf-8"

Hello
"""

_RAW_DELIVERY_STATUS_ATTACHMENT = b"""From: client-a@lab-a.example.test
To: referent-a@lab-a.example.test
Subject: edge-status.eml
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="x"

--x
Content-Type: text/plain; charset="utf-8"

--x
Content-Type: message/delivery-status
Content-Disposition: attachment; filename="status.eml"

Reporting-MTA: dns; example.test

--x--
"""


def _nested_message_attachment(maintype_subtype: tuple[str, str], inner_parts, **kwargs):
    """Builder fallback for daemon tests (message/global)."""
    inner = MIMEMultipart('mixed', policy=policy.SMTP)
    inner.attach(MIMEText('', 'plain', 'utf-8', policy=policy.SMTP))
    for p in inner_parts:
        inner.attach(p)
    maintype, subtype = maintype_subtype
    if maintype == 'message' and subtype == 'rfc822':
        wrapper = MIMEMessage(inner, policy=policy.SMTP)
    else:
        wrapper = MIMEBase(maintype, subtype, policy=policy.SMTP)
        wrapper.set_payload(inner.as_bytes(policy=policy.SMTP))
    disposition = kwargs.get('disposition', 'attachment')
    filename = kwargs.get('filename', 'fwd.eml')
    if disposition:
        if filename:
            wrapper.add_header('Content-Disposition', disposition, filename=filename)
        else:
            wrapper.add_header('Content-Disposition', disposition)
    return wrapper


class Issue34ClassifyCases(unittest.TestCase):
    """Cases a–l: classify_inbound_attachments status and reason."""

    def _assert_invalid_reason(self, raw: bytes, reason: str) -> None:
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.status, 'invalid', c)
        self.assertEqual(c.reason, reason, c)

    def test_a_rfc822_attachment_nested_message(self) -> None:
        raw = _raw_nested_attachment('rfc822', filename='fwd.eml', subject='fwd.eml')
        self._assert_invalid_reason(raw, DISPOSAL_NESTED_MESSAGE)

    def test_b_global_attachment_nested_message(self) -> None:
        raw = _raw_nested_attachment('global', filename='fwd.eml', subject='inner.zip')
        self._assert_invalid_reason(raw, DISPOSAL_NESTED_MESSAGE)
        c = classify_inbound_attachments(raw)
        self.assertNotIn('inner.zip', tuple(c.approved_filenames or ()))

    def test_c_partial_attachment_nested_message(self) -> None:
        raw = _raw_nested_attachment('partial', filename='partial.eml', subject='inner.zip')
        self._assert_invalid_reason(raw, DISPOSAL_NESTED_MESSAGE)

    def test_d_external_body_attachment_nested_message(self) -> None:
        raw = _raw_nested_attachment(
            'external-body', filename='ext.eml', subject='inner.zip'
        )
        self._assert_invalid_reason(raw, DISPOSAL_NESTED_MESSAGE)

    def test_e_multipart_report_dsn_zero_attachments(self) -> None:
        self._assert_invalid_reason(_RAW_DSN, DISPOSAL_ZERO)

    def test_f_multipart_report_mdn_zero_attachments(self) -> None:
        self._assert_invalid_reason(_RAW_MDN, DISPOSAL_ZERO)

    def test_g_text_plain_only_zero_attachments(self) -> None:
        self._assert_invalid_reason(_RAW_PLAIN, DISPOSAL_ZERO)

    def test_h_message_rfc822_inline_zero_attachments(self) -> None:
        raw = _mixed([_nested_rfc822_attachment([], disposition='inline')], subject='Re: x')
        self._assert_invalid_reason(raw, DISPOSAL_ZERO)

    def test_i_zip_plus_global_deliver_zip_only(self) -> None:
        raw = _mixed(
            [
                _part('pack.zip'),
                _nested_message_attachment(
                    ('message', 'global'), [], filename='fwd.eml'
                ),
            ],
            subject='pack.zip',
        )
        c = classify_inbound_attachments(raw)
        self.assertEqual(c.status, 'deliver')
        self.assertEqual(c.deliver_indexes, (0,))
        self.assertEqual(c.parts[1].skip_kind, 'nested_message')

    def test_j_delivery_status_attachment_disposition(self) -> None:
        c = classify_inbound_attachments(_RAW_DELIVERY_STATUS_ATTACHMENT)
        self.assertEqual(c.status, 'invalid')
        self.assertEqual(c.reason, DISPOSAL_NESTED_MESSAGE)

    def test_k_message_rfc822_case_insensitive_ctype(self) -> None:
        raw = _raw_nested_attachment(
            'rfc822',
            filename='fwd.eml',
            subject='fwd.eml',
            ctype_case='Message/RFC822',
        )
        self._assert_invalid_reason(raw, DISPOSAL_NESTED_MESSAGE)

    def test_l_re_subject_global_attachment_not_template_bypass(self) -> None:
        """REB-6: nested attachment counts as attachment — same as rfc822 today."""
        raw = _mixed(
            [_nested_message_attachment(('message', 'global'), [], filename='fwd.eml')],
            subject='Re: 123.rar',
        )
        c_rfc = classify_inbound_attachments(
            _mixed(
                [_nested_rfc822_attachment([], filename='fwd.eml')],
                subject='Re: 123.rar',
            )
        )
        c_glob = classify_inbound_attachments(raw)
        self.assertEqual(c_rfc.status, c_glob.status)
        self.assertEqual(c_rfc.reason, c_glob.reason)
        self.assertEqual(c_glob.reason, DISPOSAL_NESTED_MESSAGE)


class Issue34DaemonGlobalNestedTest(unittest.TestCase):
    """Daemon-level: message/global attachment mirrors rfc822 INB-10 partial path."""

    @classmethod
    def setUpClass(cls) -> None:
        from tests.test_inbound_multi_attach_split import DaemonInbound792dTest

        DaemonInbound792dTest.setUpClass()
        cls._daemon = DaemonInbound792dTest

    def _handler(self, maildir: Path):
        case = self._daemon()
        return case._handler(maildir)

    def _deliver(self, handler, path: Path):
        case = self._daemon()
        return case._deliver(handler, path)

    def test_zip_plus_global_nested_e2e_no_retry_loop(self) -> None:
        from attachment_policy import DISPOSAL_NESTED_MESSAGE as DISPOSAL_NM

        with tempfile.TemporaryDirectory() as tmp:
            maildir = Path(tmp) / 'Maildir'
            maildir.mkdir()
            path = Path(tmp) / 'm.eml'
            path.write_bytes(
                _mixed(
                    [
                        _part('a.zip'),
                        _nested_message_attachment(
                            ('message', 'global'), [], filename='fwd.eml'
                        ),
                    ],
                    subject='a.zip',
                )
            )
            handler, journal = self._handler(maildir)
            with patch('smtplib.SMTP', return_value=MagicMock()):
                handler._stream_file_via_smtp = MagicMock(return_value=True)
                result = self._deliver(handler, path)
            self.assertTrue(result.local_delivered, result.smtp_error)
            self.assertTrue(result.mark_imap_seen)
            self.assertFalse(result.dispose_imap)
            events = [c[0][0].event_type for c in journal.write.call_args_list]
            self.assertEqual(events.count('skipped'), 1)
            self.assertEqual(events.count('delivered'), 1)
            skipped = [
                c[0][0]
                for c in journal.write.call_args_list
                if c[0][0].event_type == 'skipped'
            ]
            self.assertEqual(skipped[0].disposal_reason, DISPOSAL_NM)


if __name__ == '__main__':
    unittest.main()
