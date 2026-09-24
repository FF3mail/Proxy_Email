#!/usr/bin/env python3
"""
Attachment format gate (PROMPT-79.2 / 79.2c / 79.2d / 79.2e / Issue #26).

Outbound: validate_single_archive_attachment unchanged (archives only + subject).
Inbound: classify_inbound_attachments — extension-based acceptance, archive
subject check, image allow-list, MAX_INBOUND_ATTACHMENTS, per-part skip,
inline policy (E1), missing_filename (E3), symmetric subject normalise (E2).
"""

from __future__ import annotations

import email
import logging
import re
import unicodedata
from collections import Counter
from dataclasses import dataclass, field
from email import header as email_header
from email import policy
from email.message import Message
from typing import List, Optional, Sequence, Tuple

logger = logging.getLogger(__name__)

APPROVED_ARCHIVE_EXTENSIONS = frozenset(
    {
        'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'zst', 'lz4',
        'lzh', 'lha', 'cab', 'arj', 'ace', 'iso',
    }
)

IMAGE_EXTENSIONS = frozenset(
    {
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff',
        'heic', 'avif', 'ico',
    }
)

# SVG deliberately excluded (D2/D3).
APPROVED_INBOUND_EXTENSIONS = frozenset(APPROVED_ARCHIVE_EXTENSIONS | IMAGE_EXTENSIONS)

MAX_INBOUND_ATTACHMENTS = 20
DETAIL_MAX_LEN = 1024

DISPOSAL_ZERO = 'zero_attachments'
DISPOSAL_MULTIPLE = 'multiple_attachments'
DISPOSAL_EXTENSION = 'disallowed_extension'
DISPOSAL_SUBJECT = 'subject_mismatch'
DISPOSAL_TOO_MANY = 'too_many_attachments'
DISPOSAL_MISSING_FILENAME = 'missing_filename'

CATEGORY_ARCHIVE = 'archive'
CATEGORY_IMAGE = 'image'
CATEGORY_OTHER = 'other'
VERDICT_DELIVER = 'deliver'
VERDICT_SKIP = 'skip'

MISSING_FILENAME_LABEL = '(no filename)'


@dataclass(frozen=True)
class AttachmentPolicyResult:
    """Outbound / legacy single-archive gate: status ok | invalid | error."""

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


@dataclass(frozen=True)
class PartClassification:
    index: int
    filename: str
    ext: str
    category: str
    verdict: str
    missing_filename: bool = False
    content_type: str = ''


@dataclass(frozen=True)
class SubjectCheckResult:
    ok: bool
    expected: str = ''
    actual: str = ''
    required: bool = False


@dataclass(frozen=True)
class InboundAttachmentClassification:
    """PROMPT-79.2d/e single decision point for inbound attachments."""

    status: str  # deliver | invalid | error
    reason: Optional[str] = None
    parts: Tuple[PartClassification, ...] = ()
    total_count: int = 0
    deliver_indexes: Tuple[int, ...] = ()
    subject: SubjectCheckResult = field(default_factory=lambda: SubjectCheckResult(ok=True))
    detail: Optional[str] = None
    error: Optional[str] = None
    # Back-compat aliases used by older call sites / tests
    filename: Optional[str] = None
    approved_filenames: Optional[tuple] = None
    skipped_filenames: Optional[tuple] = None

    @property
    def is_error(self) -> bool:
        return self.status == 'error'

    @property
    def is_invalid(self) -> bool:
        return self.status == 'invalid'

    @property
    def may_deliver(self) -> bool:
        return self.status == 'deliver'

    @property
    def is_ok_single(self) -> bool:
        return self.status == 'deliver' and self.total_count == 1

    @property
    def is_split(self) -> bool:
        return self.status == 'deliver' and self.total_count >= 1


def truncate_detail(text: str, max_len: int = DETAIL_MAX_LEN) -> str:
    s = text or ''
    if len(s) <= max_len:
        return s
    if max_len <= 3:
        return s[:max_len]
    return s[: max_len - 3] + '...'


def decode_mime_filename(raw: str) -> str:
    """Decode RFC 2047 / RFC 2231-ish filename values; preserve original case."""
    if not raw:
        return ''
    try:
        parts = email_header.decode_header(raw)
    except Exception:
        return str(raw).strip()
    chunks: List[str] = []
    for data, charset in parts:
        if isinstance(data, bytes):
            enc = charset or 'utf-8'
            try:
                chunks.append(data.decode(enc, errors='replace'))
            except Exception:
                chunks.append(data.decode('utf-8', errors='replace'))
        else:
            chunks.append(str(data))
    return ''.join(chunks).strip()


def sanitize_header_filename(filename: str) -> str:
    """Strip CR/LF and other controls (header-injection protection); keep case."""
    return ''.join(ch for ch in (filename or '') if ord(ch) >= 32 and ch != '\x7f')


def _decode_rfc2047_text(raw: str) -> str:
    text = raw or ''
    try:
        parts = email_header.decode_header(text)
    except Exception:
        return text
    chunks: List[str] = []
    for data, charset in parts:
        if isinstance(data, bytes):
            enc = charset or 'utf-8'
            try:
                chunks.append(data.decode(enc, errors='replace'))
            except Exception:
                chunks.append(data.decode('utf-8', errors='replace'))
        else:
            chunks.append(str(data))
    return ''.join(chunks)


def normalize_for_display(text: str) -> str:
    """
    Human-readable normalisation (E2): RFC 2047 decode, unicode NFC, unfold,
    collapse whitespace runs to one space, strip. NOT casefolded.
    """
    raw = text or ''
    raw = re.sub(r'\r?\n[ \t]+', ' ', raw)
    raw = raw.replace('\r', ' ').replace('\n', ' ')
    decoded = _decode_rfc2047_text(raw)
    nfc = unicodedata.normalize('NFC', decoded)
    return re.sub(r'\s+', ' ', nfc).strip()


def normalize_for_compare(text: str) -> str:
    """Symmetric compare form (E2): normalize_for_display + casefold."""
    return normalize_for_display(text).casefold()


def normalize_subject_text(subject: str) -> str:
    """Back-compat alias: compare form of Subject."""
    return normalize_for_compare(subject)


def _attachment_filename(part: Message) -> str:
    """Outbound helper: missing filename is an error."""
    filename = part.get_filename()
    if not filename or not str(filename).strip():
        raise ValueError('missing_attachment_filename')
    return decode_mime_filename(str(filename))


def try_attachment_filename(part: Message) -> Optional[str]:
    """Inbound helper: None when disposition-attachment lacks a usable filename."""
    filename = part.get_filename()
    if not filename or not str(filename).strip():
        return None
    decoded = decode_mime_filename(str(filename))
    if not decoded.strip():
        return None
    return decoded


def _content_disposition_is_attachment(part: Message) -> bool:
    """Disposition value only (PROMPT-79.2f) — not filename substring match."""
    disp = part.get_content_disposition()
    return (disp or '').lower() == 'attachment'


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
    if _content_disposition_is_attachment(part):
        found.append(part)


def enumerate_attachable_parts(msg: Message) -> List[Message]:
    """
    Single shared enumerator (E5 / E1 / 79.2f): get_content_disposition() == attachment.
    Inline / cid / no-disposition parts are NOT attachments (filename ignored).
    """
    found: List[Message] = []
    _walk_attachments(msg, found)
    return found


def _walk_non_attachment_named(
    part: Message, found: List[Tuple[str, str]]
) -> None:
    """Collect leaf non-attachment parts that have a filename (for detail/DEBUG)."""
    if (part.get_content_type() or '').lower() == 'message/rfc822':
        raise ValueError('nested_rfc822')
    if part.is_multipart():
        payload = part.get_payload()
        if not isinstance(payload, list):
            raise ValueError('malformed_multipart')
        for subpart in payload:
            if isinstance(subpart, str):
                raise ValueError('malformed_multipart')
            _walk_non_attachment_named(subpart, found)
        return
    if _content_disposition_is_attachment(part):
        return
    disposition = (part.get('Content-Disposition') or '').lower()
    raw_name = part.get_filename()
    if not raw_name or not str(raw_name).strip():
        return
    name = decode_mime_filename(str(raw_name))
    if name:
        found.append((name, disposition or '(none)'))


def collect_non_attachment_named_parts(msg: Message) -> List[Tuple[str, str]]:
    found: List[Tuple[str, str]] = []
    _walk_non_attachment_named(msg, found)
    return found


def extension_of_filename(filename: str) -> str:
    name = (filename or '').strip()
    if name == MISSING_FILENAME_LABEL or '.' not in name:
        return ''
    return name.rsplit('.', 1)[-1].lower()


def category_for_extension(ext: str) -> str:
    e = (ext or '').lower().lstrip('.')
    if e in APPROVED_ARCHIVE_EXTENSIONS:
        return CATEGORY_ARCHIVE
    if e in IMAGE_EXTENSIONS:
        return CATEGORY_IMAGE
    return CATEGORY_OTHER


def subject_matches_filename(subject: str, filename: str) -> bool:
    """Case-insensitive whole-string match (Issue #26 / outbound)."""
    return (subject or '').strip().lower() == (filename or '').strip().lower()


def check_inbound_subject(
    subject: str, archive_names: Sequence[str]
) -> Tuple[bool, str, str]:
    """
    Archive-based parent Subject check (D7 / E2).

    Returns (ok, expected, actual) where expected/actual are human-readable
    (NFC + collapsed spaces, not casefolded). Comparison uses normalize_for_compare
    on BOTH subject and each archive filename.
    """
    names = [str(n) for n in archive_names]
    display_names = [normalize_for_display(n) for n in names]
    expected = ' '.join(display_names)
    actual_display = normalize_for_display(subject)
    if not names:
        return True, '', actual_display

    compare_names = [normalize_for_compare(n) for n in names]
    actual_compare = normalize_for_compare(subject)

    if len(compare_names) == 1:
        ok = actual_compare == compare_names[0]
        return ok, expected, actual_display

    counts: Counter = Counter(compare_names)
    ok = _decompose_subject(actual_compare, counts)
    return ok, expected, actual_display


def _decompose_subject(remaining: str, counts: Counter) -> bool:
    remaining = remaining.strip()
    if not remaining:
        return all(v == 0 for v in counts.values())
    for name, left in list(counts.items()):
        if left <= 0:
            continue
        if remaining == name:
            counts[name] -= 1
            ok = _decompose_subject('', counts)
            counts[name] += 1
            if ok:
                return True
        elif remaining.startswith(name + ' '):
            counts[name] -= 1
            ok = _decompose_subject(remaining[len(name) + 1 :], counts)
            counts[name] += 1
            if ok:
                return True
    return False


def validate_single_archive_attachment(
    raw_bytes: bytes,
    *,
    approved_extensions: Optional[Sequence[str]] = None,
) -> AttachmentPolicyResult:
    """
    Outbound gate: exactly one attachment, approved ARCHIVE extension, Subject match.
    Unchanged by PROMPT-79.2d/e (images still rejected; missing filename = error).
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


def classify_inbound_attachments(
    raw_bytes: bytes,
    *,
    approved_inbound_extensions: Optional[Sequence[str]] = None,
) -> InboundAttachmentClassification:
    """
    PROMPT-79.2d/e inbound single decision point.

    Check order: N=0 → zero; N>20 → too_many; subject (D7/E2); extension /
    missing_filename filter; nothing deliverable → missing_filename or
    disallowed_extension.
    """
    allowed = frozenset(
        e.lower().lstrip('.')
        for e in (approved_inbound_extensions or APPROVED_INBOUND_EXTENSIONS)
    )
    try:
        msg = email.message_from_bytes(raw_bytes, policy=policy.default)
        attachments = enumerate_attachable_parts(msg)
        non_attach = collect_non_attachment_named_parts(msg)
    except Exception as exc:
        logger.error('[ATTACHMENT_POLICY] inbound_inspection_error err=%s', exc)
        return InboundAttachmentClassification(status='error', error=str(exc))

    for name, disp in non_attach:
        logger.debug(
            '[ATTACHMENT_POLICY] ignore non-attachment part filename=%s '
            'disposition=%s',
            name,
            disp,
        )

    total = len(attachments)
    if total == 0:
        detail = None
        if non_attach:
            names = [n for n, _ in non_attach]
            detail = truncate_detail(
                'inline_parts=%s names=%s' % (len(names), ' | '.join(names))
            )
        return InboundAttachmentClassification(
            status='invalid',
            reason=DISPOSAL_ZERO,
            total_count=0,
            detail=detail,
            subject=SubjectCheckResult(ok=True, required=False),
        )

    parts: List[PartClassification] = []
    for idx, part in enumerate(attachments):
        ctype = (part.get_content_type() or '').lower()
        filename = try_attachment_filename(part)
        if filename is None:
            parts.append(
                PartClassification(
                    index=idx,
                    filename=MISSING_FILENAME_LABEL,
                    ext='',
                    category=CATEGORY_OTHER,
                    verdict=VERDICT_SKIP,
                    missing_filename=True,
                    content_type=ctype,
                )
            )
            logger.warning(
                '[ATTACHMENT_POLICY] inbound skip missing_filename '
                'content_type=%s index=%s',
                ctype or '(none)',
                idx,
            )
            continue
        ext = extension_of_filename(filename)
        cat = category_for_extension(ext)
        verdict = VERDICT_DELIVER if ext in allowed else VERDICT_SKIP
        parts.append(
            PartClassification(
                index=idx,
                filename=filename,
                ext=ext,
                category=cat,
                verdict=verdict,
                missing_filename=False,
                content_type=ctype,
            )
        )

    all_names = [p.filename for p in parts]
    names_detail = truncate_detail(
        'count=%s names=%s' % (total, ' | '.join(all_names))
    )

    if total > MAX_INBOUND_ATTACHMENTS:
        return InboundAttachmentClassification(
            status='invalid',
            reason=DISPOSAL_TOO_MANY,
            parts=tuple(parts),
            total_count=total,
            detail=names_detail,
            subject=SubjectCheckResult(ok=False, required=False),
            skipped_filenames=tuple(all_names),
        )

    try:
        subject_raw = str(msg.get('Subject', '') or '')
    except Exception as exc:
        logger.error('[ATTACHMENT_POLICY] inbound_subject_error err=%s', exc)
        return InboundAttachmentClassification(status='error', error=str(exc))

    # Subject check uses archives with real filenames only (E3).
    archive_names = [
        p.filename
        for p in parts
        if p.category == CATEGORY_ARCHIVE and not p.missing_filename
    ]
    subject_required = False
    if total == 1:
        subject_required = (
            parts[0].category == CATEGORY_ARCHIVE and not parts[0].missing_filename
        )
    else:
        subject_required = len(archive_names) > 0

    if subject_required:
        ok, expected, actual = check_inbound_subject(subject_raw, archive_names)
        subj = SubjectCheckResult(
            ok=ok, expected=expected, actual=actual, required=True
        )
        if not ok:
            detail = truncate_detail(
                'expected=%s | actual=%s | attachments=%s'
                % (expected, actual, ' | '.join(all_names))
            )
            return InboundAttachmentClassification(
                status='invalid',
                reason=DISPOSAL_SUBJECT,
                parts=tuple(parts),
                total_count=total,
                detail=detail,
                subject=subj,
                skipped_filenames=tuple(all_names),
            )
    else:
        subj = SubjectCheckResult(
            ok=True,
            expected='',
            actual=normalize_for_display(subject_raw),
            required=False,
        )

    for p in parts:
        if p.verdict == VERDICT_SKIP and not p.missing_filename:
            logger.warning(
                '[ATTACHMENT_POLICY] inbound skip disallowed filename=%s ext=%s',
                p.filename,
                p.ext or '(none)',
            )

    deliver_indexes = tuple(p.index for p in parts if p.verdict == VERDICT_DELIVER)
    skipped_names = tuple(p.filename for p in parts if p.verdict == VERDICT_SKIP)
    approved_names = tuple(p.filename for p in parts if p.verdict == VERDICT_DELIVER)

    if not deliver_indexes:
        skipped_parts = [p for p in parts if p.verdict == VERDICT_SKIP]
        if skipped_parts and all(p.missing_filename for p in skipped_parts):
            reason = DISPOSAL_MISSING_FILENAME
        else:
            reason = DISPOSAL_EXTENSION
        return InboundAttachmentClassification(
            status='invalid',
            reason=reason,
            parts=tuple(parts),
            total_count=total,
            deliver_indexes=(),
            subject=subj,
            detail=truncate_detail('skipped=%s' % (' | '.join(skipped_names),)),
            skipped_filenames=skipped_names,
            approved_filenames=(),
        )

    return InboundAttachmentClassification(
        status='deliver',
        parts=tuple(parts),
        total_count=total,
        deliver_indexes=deliver_indexes,
        subject=subj,
        filename=approved_names[0] if len(approved_names) == 1 else None,
        approved_filenames=approved_names,
        skipped_filenames=skipped_names,
    )
