#!/usr/bin/env python3
"""
Attachment format gate for mail-passage disposal (PROMPT-79.2 / Issue #26).

Fail-closed: MIME parse / inspection errors return status='error' and must NOT
trigger disposal. Only a definitive invalid determination may dispose.
"""

from __future__ import annotations

import email
import logging
from dataclasses import dataclass
from email import policy
from email.message import Message
from typing import List, Optional, Sequence

logger = logging.getLogger(__name__)

APPROVED_ARCHIVE_EXTENSIONS = frozenset(
    {
        'zip',
        'rar',
        '7z',
        'tar',
        'gz',
        'bz2',
        'xz',
        'zst',
        'lz4',
        'lzh',
        'lha',
        'cab',
        'arj',
        'ace',
        'iso',
    }
)

DISPOSAL_ZERO = 'zero_attachments'
DISPOSAL_MULTIPLE = 'multiple_attachments'
DISPOSAL_EXTENSION = 'disallowed_extension'
DISPOSAL_SUBJECT = 'subject_mismatch'


@dataclass(frozen=True)
class AttachmentPolicyResult:
    """status: ok | invalid | error."""

    status: str
    reason: Optional[str] = None
    filename: Optional[str] = None
    error: Optional[str] = None

    @property
    def is_ok(self) -> bool:
        return self.status == 'ok'

    @property
    def is_invalid(self) -> bool:
        return self.status == 'invalid'

    @property
    def is_error(self) -> bool:
        return self.status == 'error'


def _attachment_filename(part: Message) -> str:
    filename = part.get_filename()
    if not filename or not str(filename).strip():
        raise ValueError('missing_attachment_filename')
    return str(filename).strip()


def _walk_attachments(part: Message, found: List[Message]) -> None:
    if (part.get_content_type() or '').lower() == 'message/rfc822':
        raise ValueError('nested_rfc822')
    if part.is_multipart():
        payload = part.get_payload()
        if not isinstance(payload, list):
            raise ValueError('malformed_multipart')
        for subpart in payload:
            if isinstance(subpart, str):
                raise ValueError('malformed_multipart')
            _walk_attachments(subpart, found)
        return
    disposition = (part.get('Content-Disposition') or '').lower()
    if 'attachment' in disposition:
        found.append(part)


def enumerate_attachable_parts(msg: Message) -> List[Message]:
    found: List[Message] = []
    _walk_attachments(msg, found)
    return found


def extension_of_filename(filename: str) -> str:
    name = (filename or '').strip()
    if '.' not in name:
        return ''
    return name.rsplit('.', 1)[-1].lower()


def subject_matches_filename(subject: str, filename: str) -> bool:
    """Case-insensitive whole-string match (Issue #26)."""
    return (subject or '').strip().lower() == (filename or '').strip().lower()


def validate_single_archive_attachment(
    raw_bytes: bytes,
    *,
    approved_extensions: Optional[Sequence[str]] = None,
) -> AttachmentPolicyResult:
    """
    Require exactly one attachment with approved archive extension and
    Subject equal to filename (case-insensitive whole-string).
    """
    allowed = frozenset(
        e.lower().lstrip('.')
        for e in (approved_extensions or APPROVED_ARCHIVE_EXTENSIONS)
    )
    try:
        msg = email.message_from_bytes(raw_bytes, policy=policy.default)
        attachments = enumerate_attachable_parts(msg)
    except Exception as exc:
        logger.error('[ATTACHMENT_POLICY] inspection_error err=%s', exc)
        return AttachmentPolicyResult(status='error', error=str(exc))

    if not attachments:
        return AttachmentPolicyResult(status='invalid', reason=DISPOSAL_ZERO)
    if len(attachments) > 1:
        return AttachmentPolicyResult(status='invalid', reason=DISPOSAL_MULTIPLE)

    try:
        filename = _attachment_filename(attachments[0])
    except Exception as exc:
        logger.error('[ATTACHMENT_POLICY] filename_error err=%s', exc)
        return AttachmentPolicyResult(status='error', error=str(exc))

    ext = extension_of_filename(filename)
    if ext not in allowed:
        return AttachmentPolicyResult(
            status='invalid',
            reason=DISPOSAL_EXTENSION,
            filename=filename,
        )

    try:
        subject_text = str(msg.get('Subject', '') or '')
    except Exception as exc:
        logger.error('[ATTACHMENT_POLICY] subject_error err=%s', exc)
        return AttachmentPolicyResult(status='error', error=str(exc))

    if not subject_matches_filename(subject_text, filename):
        return AttachmentPolicyResult(
            status='invalid',
            reason=DISPOSAL_SUBJECT,
            filename=filename,
        )

    return AttachmentPolicyResult(status='ok', filename=filename)

