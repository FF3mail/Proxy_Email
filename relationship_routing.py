#!/usr/bin/env python3
"""
Relationship-centric routing (inbound/outbound) — PROMPT-79.1 live-only.

Process-global modes from systemd Environment= (see mail-proxy.service.d/routing.conf).
Per-referent DB override columns are ignored until PROMPT-79.4 schema cleanup.
"""

from __future__ import annotations

import email.utils
import logging
import os
from dataclasses import dataclass
from enum import Enum
from typing import Any, Dict, List, Optional, Protocol

from relationship_lookup import ClientRelationshipDTO, normalize_email

_LIVE_ALIASES = ('relationship_live', 'live', 'relationship')
_WATCH_ALIASES = ('relationship_only', 'relationship')


class InboundRoutingMode(str, Enum):
    RELATIONSHIP_LIVE = 'relationship_live'


class OutboundRoutingMode(str, Enum):
    RELATIONSHIP_LIVE = 'relationship_live'


class OutboundWatchMode(str, Enum):
    RELATIONSHIP_ONLY = 'relationship_only'


def parse_inbound_routing_mode(
    environ: Optional[dict] = None,
) -> InboundRoutingMode:
    env = environ if environ is not None else os.environ
    raw = (env.get('INBOUND_ROUTING_MODE') or 'relationship_live').strip().lower()
    if raw in _LIVE_ALIASES:
        return InboundRoutingMode.RELATIONSHIP_LIVE
    logging.getLogger(__name__).warning(
        'Unsupported INBOUND_ROUTING_MODE=%r — using relationship_live', raw
    )
    return InboundRoutingMode.RELATIONSHIP_LIVE


def parse_outbound_routing_mode(
    environ: Optional[dict] = None,
) -> OutboundRoutingMode:
    env = environ if environ is not None else os.environ
    raw = (env.get('OUTBOUND_ROUTING_MODE') or 'relationship_live').strip().lower()
    if raw in _LIVE_ALIASES:
        return OutboundRoutingMode.RELATIONSHIP_LIVE
    logging.getLogger(__name__).warning(
        'Unsupported OUTBOUND_ROUTING_MODE=%r — using relationship_live', raw
    )
    return OutboundRoutingMode.RELATIONSHIP_LIVE


def parse_outbound_watch_mode(
    environ: Optional[dict] = None,
) -> OutboundWatchMode:
    env = environ if environ is not None else os.environ
    raw = (env.get('OUTBOUND_WATCH_MODE') or 'relationship_only').strip().lower()
    if raw in _WATCH_ALIASES:
        return OutboundWatchMode.RELATIONSHIP_ONLY
    logging.getLogger(__name__).warning(
        'Unsupported OUTBOUND_WATCH_MODE=%r — using relationship_only', raw
    )
    return OutboundWatchMode.RELATIONSHIP_ONLY


class _ResolveInbound(Protocol):
    def __call__(
        self, external_account_id: int, external_sender_email: str
    ) -> Optional[ClientRelationshipDTO]:
        ...


@dataclass(frozen=True)
class InboundDeliveryPlan:
    mode: InboundRoutingMode
    local_rcpts: List[str]
    mail_from: str
    relationship_id: Optional[int] = None
    local_target_email: Optional[str] = None
    local_client_maildir: Optional[str] = None
    lookup_error: Optional[str] = None
    skip_reason: Optional[str] = None


@dataclass(frozen=True)
class InboundProcessResult:
    mark_imap_seen: bool
    local_delivered: bool
    plan: InboundDeliveryPlan
    smtp_error: Optional[str] = None


def extract_message_from_address(msg: Any) -> str:
    """Parse RFC From header only (not To/Cc)."""
    try:
        from_header = msg.get('From', '') or ''
    except Exception:
        from_header = ''
    _name, addr = email.utils.parseaddr(from_header)
    return (addr or '').strip()


def relationship_target_email(dto: ClientRelationshipDTO) -> Optional[str]:
    target = normalize_email(dto.local_referent_email or '')
    return target or None


def validate_relationship_for_live(dto: ClientRelationshipDTO) -> Optional[str]:
    target = relationship_target_email(dto)
    if not target:
        return 'missing local_referent_email'
    maildir = (dto.local_client_maildir or '').strip()
    if not maildir:
        return 'missing local_client_maildir'
    if not os.path.isdir(maildir):
        return f'local_client_maildir not found: {maildir}'
    return None


def plan_inbound_delivery(
    *,
    resolve_inbound: _ResolveInbound,
    account_id: int,
    account_email: str,
    from_address: str,
    referent_local_inbox: str,
) -> InboundDeliveryPlan:
    mode = InboundRoutingMode.RELATIONSHIP_LIVE
    mail_from = referent_local_inbox
    sender = (from_address or '').strip()
    try:
        dto = resolve_inbound(int(account_id), sender)
    except Exception as exc:
        return InboundDeliveryPlan(
            mode=mode,
            local_rcpts=[],
            mail_from=mail_from,
            lookup_error=str(exc),
        )
    if dto is None:
        return InboundDeliveryPlan(
            mode=mode,
            local_rcpts=[],
            mail_from=mail_from,
            skip_reason='no_relationship_match',
        )
    validation_err = validate_relationship_for_live(dto)
    if validation_err:
        return InboundDeliveryPlan(
            mode=mode,
            local_rcpts=[],
            mail_from=mail_from,
            relationship_id=int(dto.relationship_id),
            lookup_error=validation_err,
        )
    target = relationship_target_email(dto)
    assert target is not None
    return InboundDeliveryPlan(
        mode=mode,
        local_rcpts=[target],
        mail_from=target,
        relationship_id=int(dto.relationship_id),
        local_target_email=target,
        local_client_maildir=dto.local_client_maildir,
    )


def finalize_inbound_process_result(
    plan: InboundDeliveryPlan,
    smtp_delivered: bool,
    smtp_error: Optional[str] = None,
) -> InboundProcessResult:
    if plan.lookup_error:
        return InboundProcessResult(
            mark_imap_seen=False,
            local_delivered=False,
            plan=plan,
            smtp_error=plan.lookup_error,
        )
    if plan.skip_reason == 'no_relationship_match':
        return InboundProcessResult(
            mark_imap_seen=True,
            local_delivered=False,
            plan=plan,
            smtp_error='unknown_sender_skipped',
        )
    return InboundProcessResult(
        mark_imap_seen=smtp_delivered,
        local_delivered=smtp_delivered,
        plan=plan,
        smtp_error=smtp_error,
    )


class _ResolveOutbound(Protocol):
    def __call__(self, local_client_email: str) -> Optional[ClientRelationshipDTO]:
        ...


@dataclass(frozen=True)
class OutboundDeliveryPlan:
    mode: OutboundRoutingMode
    account: Optional[Dict[str, Any]]
    outbound_identity: Optional[str] = None
    relationship_id: Optional[int] = None
    external_account_id: Optional[int] = None
    lookup_error: Optional[str] = None
    skip_reason: Optional[str] = None


def extract_outbound_identity_from_message(msg: Any) -> str:
    return extract_message_from_address(msg)


def account_dict_from_relationship(dto: ClientRelationshipDTO) -> Dict[str, Any]:
    return dict(dto.account)


def plan_outbound_delivery(
    *,
    resolve_outbound: _ResolveOutbound,
    from_address: str,
) -> OutboundDeliveryPlan:
    mode = OutboundRoutingMode.RELATIONSHIP_LIVE
    identity = (from_address or '').strip()
    try:
        dto = resolve_outbound(identity)
    except Exception as exc:
        return OutboundDeliveryPlan(
            mode=mode,
            account=None,
            outbound_identity=identity or None,
            lookup_error=str(exc),
        )
    if dto is None:
        return OutboundDeliveryPlan(
            mode=mode,
            account=None,
            outbound_identity=identity or None,
            skip_reason='no_relationship_match',
        )
    account = account_dict_from_relationship(dto)
    return OutboundDeliveryPlan(
        mode=mode,
        account=account,
        outbound_identity=identity or None,
        relationship_id=int(dto.relationship_id),
        external_account_id=int(dto.external_account_id),
    )
