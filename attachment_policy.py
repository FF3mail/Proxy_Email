#!/usr/bin/env python3
"""
Attachment format gate (PROMPT-79.2 / 79.2c / 79.2d / Issue #26).

Outbound: validate_single_archive_attachment unchanged (archives only + subject).
Inbound: classify_inbound_attachments — extension-based acceptance, archive
subject check, image allow-list, MAX_INBOUND_ATTACHMENTS, per-part skip.
"""

from __future__ import annotations

import email
import logging
import re
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

CATEGORY_ARCHIVE = 'archive'
CATEGORY_IMAGE = 'image'
CATEGORY_OTHER = 'other'
VERDICT_DELIVER = 'deliver'
VERDICT_SKIP = 'skip'


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


@dataclass(frozen=True)
class SubjectCheckResult:
    ok: bool
    expected: str = ''
    actual: str = ''
    required: bool = False


@dataclass(frozen=True)
class InboundAttachmentClassification:
    """PROMPT-79.2d single decision point for inbound attachments."""

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

    # Legacy 79.2c names
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


def _attachment_filename(part: Message) -> str:
    filename = part.get_filename()
    if not filename or not str(filename).strip():
        raise ValueError('missing_attachment_filename')
    return decode_mime_filename(str(filename))


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
    """Content-Disposition: attachment only — inline/cid parts are NOT counted (R6)."""
    found: List[Message] = []
    _walk_attachments(msg, found)
    return found


def extension_of_filename(filename: str) -> str:
    name = (filename or '').strip()
    if '.' not in name:
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


def normalize_subject_text(subject: str) -> str:
    """RFC 2047 decode, unfold, collapse whitespace, strip, casefold."""
    raw = subject or ''
    # Unfold header continuations
    raw = re.sub(r'\r?\n[ \t]+', ' ', raw)
    raw = raw.replace('\r', ' ').replace('\n', ' ')
    try:
        parts = email_header.decode_header(raw)
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
        decoded = ''.join(chunks)
    except Exception:
        decoded = raw
    collapsed = re.sub(r'\s+', ' ', decoded).strip()
    return collapsed.casefold()


def check_inbound_subject(
    subject: str, archive_names: Sequence[str]
) -> Tuple[bool, str, str]:
    """
    Archive-based parent Subject check (D7).

    Returns (ok, expected, actual) where expected/actual are human-readable
    (space-joined archive names / normalised subject display).
    Filenames may contain spaces — Subject.split() is forbidden; decompose by
    backtracking over the multiset of archive names.
    """
    names = [str(n) for n in archive_names]
    expected = ' '.join(names)
    actual_display = normalize_subject_text(subject)
    # For display of actual, keep a readable form (already normalised/casefold)
    if not names:
        return True, '', actual_display

    if len(names) == 1:
        ok = actual_display == names[0].casefold()
        return ok, expected, actual_display

    counts: Counter = Counter(n.casefold() for n in names)
    ok = _decompose_subject(actual_display, counts)
    return ok, expected, actual_display


def _decompose_subject(remaining: str, counts: Counter) -> bool:
    remaining = remaining.strip()
    if not remaining:
        return all(v == 0 for v in counts.values())
    # Try each distinct name that still has remaining count as a prefix match
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
    Unchanged by PROMPT-79.2d (images still rejected).
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
    PROMPT-79.2d inbound single decision point.

    Check order: N=0 → zero; N>20 → too_many; subject (D7); extension filter;
    nothing deliverable → disallowed_extension.
    """
    allowed = frozenset(
        e.lower().lstrip('.')
        for e in (approved_inbound_extensions or APPROVED_INBOUND_EXTENSIONS)
    )
    try:
        msg = email.message_from_bytes(raw_bytes, policy=policy.default)
        attachments = enumerate_attachable_parts(msg)
    except Exception as exc:
        logger.error('[ATTACHMENT_POLICY] inbound_inspection_error err=%s', exc)
        return InboundAttachmentClassification(status='error', error=str(exc))

    total = len(attachments)
    if total == 0:
        return InboundAttachmentClassification(
            status='invalid',
            reason=DISPOSAL_ZERO,
            total_count=0,
            subject=SubjectCheckResult(ok=True, required=False),
        )

    # Build part list (need filenames even for too_many)
    parts: List[PartClassification] = []
    for idx, part in enumerate(attachments):
        try:
            filename = _attachment_filename(part)
        except Exception as exc:
            logger.error('[ATTACHMENT_POLICY] inbound_filename_error err=%s', exc)
            return InboundAttachmentClassification(status='error', error=str(exc))
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

    archive_names = [p.filename for p in parts if p.category == CATEGORY_ARCHIVE]
    # D7: subject check applicability
    subject_required = False
    if total == 1:
        subject_required = parts[0].category == CATEGORY_ARCHIVE
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
            actual=normalize_subject_text(subject_raw),
            required=False,
        )

    # Extension filter (after subject) — update verdicts already set
    for p in parts:
        if p.verdict == VERDICT_SKIP:
            logger.warning(
                '[ATTACHMENT_POLICY] inbound skip disallowed filename=%s ext=%s',
                p.filename,
                p.ext or '(none)',
            )

    deliver_indexes = tuple(p.index for p in parts if p.verdict == VERDICT_DELIVER)
    skipped_names = tuple(p.filename for p in parts if p.verdict == VERDICT_SKIP)
    approved_names = tuple(p.filename for p in parts if p.verdict == VERDICT_DELIVER)

    if not deliver_indexes:
        return InboundAttachmentClassification(
            status='invalid',
            reason=DISPOSAL_EXTENSION,
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