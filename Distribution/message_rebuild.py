#!/usr/bin/env python3
"""
Message rebuild for relationship_live routing (PROMPT-77).

Implements PROMPT-76.1 spec: inbound 1:N fan-out, outbound 1:1 rebuild.
Pure MIME transformation — no I/O beyond temp file writes.
"""

from __future__ import annotations

import email
import logging
import os
import tempfile
from dataclasses import dataclass, field
from email import encoders, policy
from email.message import Message
from email.mime.base import MIMEBase
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.utils import formatdate, make_msgid
from pathlib import Path
from typing import List, Optional, Sequence, Tuple

from relationship_lookup import ClientRelationshipDTO

logger = logging.getLogger(__name__)

DEFAULT_TEMP_DIR = '/var/spool/mail-proxy/tmp'

_SIGNED_ENCRYPTED_TYPES = frozenset(
    {
        'multipart/signed',
        'multipart/encrypted',
        'application/pkcs7-mime',
        'application/pkcs7-signature',
        'application/pgp-encrypted',
        'application/pgp-signature',
    }
)


class MessageRebuildError(Exception):
    """Rebuild failed — fail closed per spec §4.8/§4.9."""


@dataclass(frozen=True)
class FanoutChildMetadata:
    index: int
    filename: str
    temp_path: Optional[Path] = None
    success: bool = False
    error: Optional[str] = None


@dataclass
class FanoutRebuildResult:
    success: bool
    children: List[FanoutChildMetadata] = field(default_factory=list)
    error: Optional[str] = None
    reason: Optional[str] = None

    @property
    def temp_paths(self) -> List[Path]:
        return [
            child.temp_path
            for child in self.children
            if child.success and child.temp_path is not None
        ]


@dataclass
class RebuildResult:
    success: bool
    temp_path: Optional[Path] = None
    error: Optional[str] = None
    reason: Optional[str] = None
    filename: Optional[str] = None


def rebuild_inbound_fanout(
    raw_bytes: bytes,
    dto: ClientRelationshipDTO,
    *,
    temp_dir: str = DEFAULT_TEMP_DIR,
    approved_part_indexes: Optional[Sequence[int]] = None,
) -> FanoutRebuildResult:
    """
    Inbound 1:N fan-out rebuild (spec §1.3, §4.2 / PROMPT-79.2d).

    RD-13: all N children rebuild into temp files before returning success.
    Any failure deletes all temps and returns success=False with zero paths.

    approved_part_indexes: 0-based indexes into enumerate_attachable_parts result.
    Classification/extension filtering is the caller's job (single decision point).

    PROMPT-79.2j: inbound enumeration uses nested_policy=opaque so indexes
    match classify_inbound_attachments (outbound keeps the default 'error').
    """
    from attachment_policy import NESTED_POLICY_OPAQUE

    temp_paths: List[Path] = []
    try:
        msg = _parse_message(raw_bytes)
        _reject_signed_or_encrypted(msg)
        attachments = _enumerate_attachable_parts_shared(
            msg, nested_policy=NESTED_POLICY_OPAQUE
        )
        if not attachments:
            logger.error(
                '[MESSAGE_REBUILD] zero_attachments relationship_id=%s',
                dto.relationship_id,
            )
            return FanoutRebuildResult(
                success=False,
                error='zero_attachments',
                reason='zero_attachments',
            )

        if approved_part_indexes is not None:
            selected = []
            for i in approved_part_indexes:
                if i < 0 or i >= len(attachments):
                    raise MessageRebuildError('malformed_mime')
                selected.append(attachments[i])
            attachments = selected
            if not attachments:
                logger.error(
                    '[MESSAGE_REBUILD] zero_attachments empty index list '
                    'relationship_id=%s',
                    dto.relationship_id,
                )
                return FanoutRebuildResult(
                    success=False,
                    error='zero_attachments',
                    reason='zero_attachments',
                )

        children: List[FanoutChildMetadata] = []
        for index, part in enumerate(attachments, start=1):
            filename = _attachment_filename(part)
            rebuilt = _build_rebuilt_message(
                from_addr=dto.local_client_email,
                to_addr=dto.local_referent_email,
                attachment_part=part,
                filename=filename,
            )
            temp_path = _write_temp_file(rebuilt, temp_dir, prefix='rebuild_in_')
            temp_paths.append(temp_path)
            children.append(
                FanoutChildMetadata(
                    index=index,
                    filename=filename,
                    temp_path=temp_path,
                    success=True,
                )
            )

        return FanoutRebuildResult(success=True, children=children)
    except MessageRebuildError as exc:
        _cleanup_temp_paths(temp_paths)
        reason = str(exc)
        logger.error(
            '[MESSAGE_REBUILD] inbound_fanout_failed relationship_id=%s reason=%s',
            dto.relationship_id,
            reason,
        )
        return FanoutRebuildResult(success=False, error=reason, reason=reason)
    except Exception as exc:
        _cleanup_temp_paths(temp_paths)
        reason = 'malformed_mime'
        logger.error(
            '[MESSAGE_REBUILD] inbound_fanout_failed relationship_id=%s reason=%s err=%s',
            dto.relationship_id,
            reason,
            exc,
        )
        return FanoutRebuildResult(success=False, error=reason, reason=reason)


def rebuild_outbound_message(
    raw_bytes: bytes,
    dto: ClientRelationshipDTO,
    *,
    temp_dir: str = DEFAULT_TEMP_DIR,
) -> RebuildResult:
    """Outbound 1:1 rebuild (spec §1.4). No fan-out — N must be exactly 1."""
    temp_path: Optional[Path] = None
    try:
        msg = _parse_message(raw_bytes)
        _reject_signed_or_encrypted(msg)
        attachments = _enumerate_attachable_parts_shared(msg)
        if not attachments:
            logger.error(
                '[MESSAGE_REBUILD] zero_attachments relationship_id=%s',
                dto.relationship_id,
            )
            return RebuildResult(
                success=False,
                error='zero_attachments',
                reason='zero_attachments',
            )
        if len(attachments) > 1:
            logger.error(
                '[MESSAGE_REBUILD] unexpected_multi_attachment relationship_id=%s count=%s',
                dto.relationship_id,
                len(attachments),
            )
            return RebuildResult(
                success=False,
                error='unexpected_multi_attachment',
                reason='unexpected_multi_attachment',
            )

        part = attachments[0]
        filename = _attachment_filename(part)
        rebuilt = _build_rebuilt_message(
            from_addr=dto.external_referent_email,
            to_addr=dto.external_client_email,
            attachment_part=part,
            filename=filename,
        )
        temp_path = _write_temp_file(rebuilt, temp_dir, prefix='rebuild_out_')
        return RebuildResult(
            success=True,
            temp_path=temp_path,
            filename=filename,
        )
    except MessageRebuildError as exc:
        if temp_path is not None:
            _cleanup_temp_paths([temp_path])
        reason = str(exc)
        logger.error(
            '[MESSAGE_REBUILD] outbound_failed relationship_id=%s reason=%s',
            dto.relationship_id,
            reason,
        )
        return RebuildResult(success=False, error=reason, reason=reason)
    except Exception as exc:
        if temp_path is not None:
            _cleanup_temp_paths([temp_path])
        reason = 'malformed_mime'
        logger.error(
            '[MESSAGE_REBUILD] outbound_failed relationship_id=%s reason=%s err=%s',
            dto.relationship_id,
            reason,
            exc,
        )
        return RebuildResult(success=False, error=reason, reason=reason)


def _parse_message(raw_bytes: bytes) -> Message:
    try:
        return email.message_from_bytes(raw_bytes, policy=policy.default)
    except Exception as exc:
        raise MessageRebuildError('malformed_mime') from exc


def _reject_signed_or_encrypted(msg: Message) -> None:
    content_type = (msg.get_content_type() or '').lower()
    if content_type in _SIGNED_ENCRYPTED_TYPES:
        raise MessageRebuildError('signed_or_encrypted')
    if msg.is_multipart():
        for part in msg.get_payload():
            if isinstance(part, Message):
                part_type = (part.get_content_type() or '').lower()
                if part_type in _SIGNED_ENCRYPTED_TYPES:
                    raise MessageRebuildError('signed_or_encrypted')


def _enumerate_attachable_parts_shared(
    msg: Message,
    *,
    nested_policy: str = 'error',
) -> List[Message]:
    """
    PROMPT-79.2e E5: one enumerator shared with attachment_policy so classify
    indexes always match rebuild. Maps policy ValueError → MessageRebuildError.

    Default nested_policy='error' (outbound). Inbound must pass
    NESTED_POLICY_OPAQUE explicitly (PROMPT-79.2j).
    """
    from attachment_policy import enumerate_attachable_parts

    try:
        return enumerate_attachable_parts(msg, nested_policy=nested_policy)
    except ValueError as exc:
        reason = str(exc)
        if reason in ('nested_rfc822', 'malformed_multipart'):
            raise MessageRebuildError('malformed_mime') from exc
        raise MessageRebuildError('malformed_mime') from exc


def _attachment_filename(part: Message) -> str:
    filename = part.get_filename()
    if not filename or not str(filename).strip():
        raise MessageRebuildError('malformed_mime')
    from attachment_policy import decode_mime_filename, sanitize_header_filename
    return sanitize_header_filename(decode_mime_filename(str(filename)))


def _build_rebuilt_message(
    *,
    from_addr: str,
    to_addr: str,
    attachment_part: Message,
    filename: str,
) -> bytes:
    """
    Build rebuilt RFC822 (spec §4.4–§4.7 / PROMPT-79.2d D8):
    From/To only (no Cc), Subject = sanitized attachment filename (case preserved),
    regenerated Date/Message-ID, empty text/plain + one attachment.
    """
    from attachment_policy import sanitize_header_filename
    safe_name = sanitize_header_filename(filename)
    mixed = MIMEMultipart('mixed', policy=policy.SMTP)
    mixed['From'] = from_addr
    mixed['To'] = to_addr
    # Unicode Subject: policy.SMTP encodes non-ASCII as RFC 2047 on serialize.
    mixed['Subject'] = safe_name
    mixed['Date'] = formatdate(localtime=True)
    mixed['Message-ID'] = make_msgid(domain='mail-proxy.local')

    mixed.attach(MIMEText('', 'plain', 'utf-8', policy=policy.SMTP))
    mixed.attach(_clone_attachment_part(attachment_part, safe_name))

    return mixed.as_bytes(policy=policy.SMTP)


def _clone_attachment_part(part: Message, filename: str) -> MIMEBase:
    payload = part.get_payload(decode=True)
    if payload is None:
        raise MessageRebuildError('malformed_mime')

    maintype, subtype = _split_content_type(part.get_content_type())
    attachment = MIMEBase(maintype, subtype, policy=policy.SMTP)
    attachment.set_payload(_payload_bytes(payload))
    encoders.encode_base64(attachment)
    attachment.add_header('Content-Disposition', 'attachment', filename=filename)
    return attachment


def _split_content_type(content_type: str) -> Tuple[str, str]:
    maintype, _, subtype = (content_type or 'application/octet-stream').partition('/')
    if not subtype:
        return 'application', 'octet-stream'
    return maintype.lower(), subtype.lower()


def _payload_bytes(payload) -> bytes:
    if isinstance(payload, bytes):
        return payload
    if isinstance(payload, str):
        return payload.encode('utf-8', errors='surrogateescape')
    raise MessageRebuildError('malformed_mime')


def _write_temp_file(data: bytes, temp_dir: str, *, prefix: str) -> Path:
    os.makedirs(temp_dir, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(dir=temp_dir, prefix=prefix)
    path = Path(temp_name)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
    except Exception:
        _cleanup_temp_paths([path])
        raise
    return path


def _cleanup_temp_paths(paths: Sequence[Path]) -> None:
    for path in paths:
        try:
            path.unlink(missing_ok=True)
        except OSError:
            pass
