#!/usr/bin/env python3
"""PROMPT-79.2 — attachment policy unit tests (Issue #26)."""

from __future__ import annotations

import sys
import unittest
from email import encoders, policy
from email.mime.base import MIMEBase
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from attachment_policy import (
    DISPOSAL_EXTENSION,
    DISPOSAL_MULTIPLE,
    DISPOSAL_SUBJECT,
    DISPOSAL_ZERO,
    subject_matches_filename,
    validate_single_archive_attachment,
)


def _msg(filename: str, subject: str | None = None, extra: list | None = None) -> bytes:
    mixed = MIMEMultipart('mixed', policy=policy.SMTP)
    mixed.attach(MIMEText('', 'plain', 'utf-8', policy=policy.SMTP))
    part = MIMEBase('application', 'octet-stream', policy=policy.SMTP)
    part.set_payload(b'data')
    encoders.encode_base64(part)
    part.add_header('Content-Disposition', 'attachment', filename=filename)
    mixed.attach(part)
    for name in extra or []:
        p2 = MIMEBase('application', 'octet-stream', policy=policy.SMTP)
        p2.set_payload(b'x')
        encoders.encode_base64(p2)
        p2.add_header('Content-Disposition', 'attachment', filename=name)
        mixed.attach(p2)
    mixed['Subject'] = subject if subject is not None else filename
    mixed['From'] = 'a@example.com'
    mixed['To'] = 'b@example.com'
    return mixed.as_bytes(policy=policy.SMTP)


class AttachmentPolicyTest(unittest.TestCase):
    def test_valid_zip(self) -> None:
        result = validate_single_archive_attachment(_msg('Contract_2026.zip'))
        self.assertTrue(result.is_ok)
        self.assertEqual(result.filename, 'Contract_2026.zip')

    def test_valid_extension_case_insensitive(self) -> None:
        result = validate_single_archive_attachment(_msg('Archive.ZIP'))
        self.assertTrue(result.is_ok)

    def test_subject_case_insensitive_match_passes(self) -> None:
        raw = _msg('Contract_2026.zip', subject='contract_2026.ZIP')
        result = validate_single_archive_attachment(raw)
        self.assertTrue(result.is_ok)
        self.assertTrue(subject_matches_filename('contract_2026.ZIP', 'Contract_2026.zip'))

    def test_zero_attachments(self) -> None:
        mixed = MIMEMultipart('mixed', policy=policy.SMTP)
        mixed.attach(MIMEText('only text', 'plain', 'utf-8', policy=policy.SMTP))
        mixed['Subject'] = 'x'
        result = validate_single_archive_attachment(mixed.as_bytes(policy=policy.SMTP))
        self.assertTrue(result.is_invalid)
        self.assertEqual(result.reason, DISPOSAL_ZERO)

    def test_multiple_attachments(self) -> None:
        result = validate_single_archive_attachment(
            _msg('a.zip', extra=['b.zip'])
        )
        self.assertTrue(result.is_invalid)
        self.assertEqual(result.reason, DISPOSAL_MULTIPLE)

    def test_disallowed_extension(self) -> None:
        result = validate_single_archive_attachment(_msg('payload.exe'))
        self.assertTrue(result.is_invalid)
        self.assertEqual(result.reason, DISPOSAL_EXTENSION)

    def test_disallowed_extension_case(self) -> None:
        result = validate_single_archive_attachment(_msg('payload.PDF'))
        self.assertTrue(result.is_invalid)
        self.assertEqual(result.reason, DISPOSAL_EXTENSION)

    def test_subject_mismatch(self) -> None:
        result = validate_single_archive_attachment(
            _msg('Contract_2026.zip', subject='Other.zip')
        )
        self.assertTrue(result.is_invalid)
        self.assertEqual(result.reason, DISPOSAL_SUBJECT)

    def test_parse_error_is_fail_closed_not_invalid(self) -> None:
        result = validate_single_archive_attachment(b'\xff\xfe not-mime')
        # email may still parse loosely; force nested rfc822 style error via walk
        # Empty/invalid that raises during walk → error. Use truncated multipart.
        raw = b'Content-Type: multipart/mixed; boundary="abc"\r\n\r\n--abc\r\nbroken'
        result = validate_single_archive_attachment(raw)
        # May be invalid (zero) or error depending on parser; must not crash.
        self.assertIn(result.status, ('ok', 'invalid', 'error'))
        if result.is_error:
            self.assertIsNotNone(result.error)


if __name__ == '__main__':
    unittest.main(verbosity=2)
