#!/usr/bin/env python3
"""
Inbound RelationshipLookup shadow-mode helpers (PROMPT-58 Stage 1).

Pure comparison + exception-isolated evaluation. Does not change delivery.
Importing this module has no side effects beyond definitions.
"""

from __future__ import annotations

import json
import logging
import os
import threading
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Protocol

# Same runtime directory pattern as PID_FILE (PROMPT-26).
SHADOW_STATS_FILE = '/run/mail-proxy/relationship_shadow_stats.json'

MARKER_AGREE = 'AGREE'
MARKER_DIVERGE_LEGACY_ONLY = 'DIVERGE — legacy delivered but no relationship match'
MARKER_DIVERGE_LOOKUP_ONLY = 'DIVERGE — relationship match but legacy did not deliver'


class _ResolveInbound(Protocol):
    def __call__(
        self, external_account_id: int, external_sender_email: str
    ) -> Any:
        ...


def relationship_lookup_shadow_enabled(
    environ: Optional[Dict[str, str]] = None,
) -> bool:
    """
    RELATIONSHIP_LOOKUP_SHADOW env flag. Default: enabled (shadow is safe).
    Accepts 1/true/yes/on (case-insensitive). Explicit 0/false/no/off disables.
    """
    env = environ if environ is not None else os.environ
    raw = env.get('RELATIONSHIP_LOOKUP_SHADOW')
    if raw is None or str(raw).strip() == '':
        return True
    return str(raw).strip().lower() in ('1', 'true', 'yes', 'on')


def extract_message_from_address(msg: Any) -> str:
    """Parse RFC From header only (not To/Cc)."""
    from_header = ''
    try:
        from_header = msg.get('From', '') or ''
    except Exception:
        from_header = ''
    _name, addr = email_parseaddr(from_header)
    return (addr or '').strip()


def email_parseaddr(header_value: str) -> tuple:
    """Thin wrapper so tests can patch without importing email.utils at call sites."""
    import email.utils

    return email.utils.parseaddr(header_value)


def classify_inbound_shadow(
    legacy_delivered: bool,
    matched_relationship_id: Optional[int],
) -> str:
    """
    Pure AGREE/DIVERGE classifier.

    legacy_delivered: whether the existing path matched a client via To/Cc
    (_resolve_local_recipients non-empty) before the unconditional inbox fallback.
    matched_relationship_id: relationship id from RelationshipLookup, or None.
    """
    lookup_matched = matched_relationship_id is not None
    if legacy_delivered == lookup_matched:
        return MARKER_AGREE
    if legacy_delivered and not lookup_matched:
        return MARKER_DIVERGE_LEGACY_ONLY
    return MARKER_DIVERGE_LOOKUP_ONLY


@dataclass
class ShadowEvalResult:
    """Outcome of a shadow evaluation (never used for delivery)."""

    marker: str
    from_address: str
    relationship_id: Optional[int]
    error: Optional[str] = None


class ShadowCounters:
    """In-process counters; reset on daemon restart. Thread-safe."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self.processed = 0
        self.agree = 0
        self.diverge_legacy_only = 0
        self.diverge_lookup_only = 0
        self.errors = 0

    def record(self, marker: str) -> None:
        with self._lock:
            self.processed += 1
            if marker == MARKER_AGREE:
                self.agree += 1
            elif marker == MARKER_DIVERGE_LEGACY_ONLY:
                self.diverge_legacy_only += 1
            elif marker == MARKER_DIVERGE_LOOKUP_ONLY:
                self.diverge_lookup_only += 1

    def record_error(self) -> None:
        with self._lock:
            self.processed += 1
            self.errors += 1

    def snapshot(self) -> Dict[str, int]:
        with self._lock:
            return {
                'processed': self.processed,
                'agree': self.agree,
                'diverge_legacy_delivered_no_match': self.diverge_legacy_only,
                'diverge_relationship_match_legacy_no_deliver': self.diverge_lookup_only,
                'errors': self.errors,
            }


# Process-wide counters for the daemon (tests may construct their own).
SHADOW_COUNTERS = ShadowCounters()


def write_shadow_stats_file(
    counters: ShadowCounters,
    path: str = SHADOW_STATS_FILE,
) -> None:
    """
    Best-effort JSON next to the PID file (PROMPT-26 runtime dir pattern).
    Failures are swallowed by the caller / logged; never raise to delivery.
    """
    payload = counters.snapshot()
    directory = os.path.dirname(path)
    if directory:
        os.makedirs(directory, mode=0o755, exist_ok=True)
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(payload, fh, indent=0, sort_keys=True)
        fh.write('\n')
    os.replace(tmp, path)
    try:
        os.chmod(path, 0o644)
    except OSError:
        pass


def evaluate_inbound_shadow(
    *,
    resolve_inbound: _ResolveInbound,
    account_id: int,
    account_email: str,
    from_address: str,
    legacy_delivered: bool,
    legacy_rcpts: List[str],
    counters: Optional[ShadowCounters] = None,
    log: Optional[logging.Logger] = None,
    stats_path: Optional[str] = SHADOW_STATS_FILE,
) -> ShadowEvalResult:
    """
    Call RelationshipLookup.resolve_inbound in isolation.

    Never raises. Never mutates legacy_rcpts. Return value is for tests/logging
    only — callers must not use it to change delivery.
    """
    ctr = counters if counters is not None else SHADOW_COUNTERS
    logger = log if log is not None else logging.getLogger('mail-proxy')
    sender = (from_address or '').strip()

    try:
        dto = resolve_inbound(int(account_id), sender)
        relationship_id: Optional[int] = None
        if dto is not None:
            relationship_id = int(getattr(dto, 'relationship_id'))
        marker = classify_inbound_shadow(legacy_delivered, relationship_id)
        ctr.record(marker)
        logger.info(
            '[RELATIONSHIP_SHADOW] account=%s sender=%s '
            'legacy=%s legacy_rcpts=%s lookup=%s marker=%s',
            account_email,
            sender or '(empty)',
            'delivered' if legacy_delivered else 'dropped',
            ','.join(legacy_rcpts) if legacy_rcpts else '(none)',
            (
                f'matched relationship_id={relationship_id}'
                if relationship_id is not None
                else 'no match'
            ),
            marker,
        )
        snap = ctr.snapshot()
        logger.info(
            '[RELATIONSHIP_SHADOW_STATS] processed=%s agree=%s '
            'diverge_legacy_no_match=%s diverge_lookup_no_legacy=%s errors=%s',
            snap['processed'],
            snap['agree'],
            snap['diverge_legacy_delivered_no_match'],
            snap['diverge_relationship_match_legacy_no_deliver'],
            snap['errors'],
        )
        if stats_path:
            try:
                write_shadow_stats_file(ctr, stats_path)
            except OSError as write_err:
                logger.warning(
                    '[RELATIONSHIP_SHADOW] stats file write failed: %s', write_err
                )
        return ShadowEvalResult(
            marker=marker,
            from_address=sender,
            relationship_id=relationship_id,
        )
    except Exception as exc:
        ctr.record_error()
        logger.warning(
            '[RELATIONSHIP_SHADOW] exception (ignored; delivery unchanged): '
            'account=%s sender=%s error=%s',
            account_email,
            sender or '(empty)',
            exc,
        )
        if stats_path:
            try:
                write_shadow_stats_file(ctr, stats_path)
            except OSError:
                pass
        return ShadowEvalResult(
            marker='ERROR',
            from_address=sender,
            relationship_id=None,
            error=str(exc),
        )


def final_legacy_rcpts(
    resolved: List[str],
    local_inbox: str,
) -> List[str]:
    """Mirror existing _deliver_to_local_smtp fallback (unchanged semantics)."""
    if not resolved:
        return [local_inbox]
    return list(resolved)


class _ResolveOutbound(Protocol):
    def __call__(self, local_client_email: str) -> Any:
        ...


def classify_outbound_shadow(
    legacy_account_id: Optional[int],
    relationship_account_id: Optional[int],
) -> str:
    """
    Pure outbound AGREE/DIVERGE classifier.

    legacy_account_id: external_accounts.id from referent LIMIT 1 selection.
    relationship_account_id: ClientRelationship.external_account_id or None.
    """
    if legacy_account_id == relationship_account_id:
        return MARKER_AGREE
    if legacy_account_id is not None and relationship_account_id is None:
        return MARKER_DIVERGE_LEGACY_ONLY
    return MARKER_DIVERGE_LOOKUP_ONLY


def evaluate_outbound_shadow(
    *,
    resolve_outbound: _ResolveOutbound,
    from_address: str,
    legacy_account_id: Optional[int],
    referent_id: int,
    counters: Optional[ShadowCounters] = None,
    log: Optional[logging.Logger] = None,
    stats_path: Optional[str] = SHADOW_STATS_FILE,
) -> ShadowEvalResult:
    """
    Call RelationshipLookup.resolve_outbound in isolation.

    Never raises. Never mutates delivery account. Return value is for logging only.
    """
    ctr = counters if counters is not None else SHADOW_COUNTERS
    logger = log if log is not None else logging.getLogger('mail-proxy')
    sender = (from_address or '').strip()
    relationship_account_id: Optional[int] = None
    relationship_id: Optional[int] = None

    try:
        dto = resolve_outbound(sender)
        if dto is not None:
            relationship_id = int(getattr(dto, 'relationship_id'))
            relationship_account_id = int(getattr(dto, 'external_account_id'))
        marker = classify_outbound_shadow(legacy_account_id, relationship_account_id)
        ctr.record(marker)
        logger.info(
            '[OUTBOUND_RELATIONSHIP_SHADOW] referent_id=%s from=%s '
            'legacy_account_id=%s lookup_account_id=%s relationship_id=%s marker=%s',
            referent_id,
            sender or '(empty)',
            legacy_account_id if legacy_account_id is not None else '(none)',
            (
                relationship_account_id
                if relationship_account_id is not None
                else 'no match'
            ),
            relationship_id if relationship_id is not None else '(none)',
            marker,
        )
        snap = ctr.snapshot()
        logger.info(
            '[RELATIONSHIP_SHADOW_STATS] processed=%s agree=%s '
            'diverge_legacy_no_match=%s diverge_lookup_no_legacy=%s errors=%s',
            snap['processed'],
            snap['agree'],
            snap['diverge_legacy_delivered_no_match'],
            snap['diverge_relationship_match_legacy_no_deliver'],
            snap['errors'],
        )
        if stats_path:
            try:
                write_shadow_stats_file(ctr, stats_path)
            except OSError as write_err:
                logger.warning(
                    '[OUTBOUND_RELATIONSHIP_SHADOW] stats file write failed: %s',
                    write_err,
                )
        return ShadowEvalResult(
            marker=marker,
            from_address=sender,
            relationship_id=relationship_id,
        )
    except Exception as exc:
        ctr.record_error()
        logger.warning(
            '[OUTBOUND_RELATIONSHIP_SHADOW] exception (ignored; delivery unchanged): '
            'referent_id=%s from=%s error=%s',
            referent_id,
            sender or '(empty)',
            exc,
        )
        if stats_path:
            try:
                write_shadow_stats_file(ctr, stats_path)
            except OSError:
                pass
        return ShadowEvalResult(
            marker='ERROR',
            from_address=sender,
            relationship_id=None,
            error=str(exc),
        )
