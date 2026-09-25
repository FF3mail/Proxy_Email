#!/usr/bin/env python3
"""
Relationship-centric routing (inbound/outbound) — PROMPT-79.1 live-only.
PROMPT-79.2: dispose skip reasons for no_match / relationship_inactive.
"""

from __future__ import annotations

import email.utils
import logging
import os
from dataclasses import dataclass
from enum import Enum
from typing import Any, Dict, List, Optional, Protocol

from relationship_lookup import (
    STATUS_INACTIVE,
    STATUS_MATCHED,
    STATUS_NO_MATCH,
    ClientRelationshipDTO,
    RelationshipClassifyResult,
    normalize_email,
)

_LIVE_ALIASES = ('relationship_live', 'live', 'relationship')
_WATCH_ALIASES = ('relationship_only', 'relationship')

SKIP_NO_RELATIONSHIP = 'no_relationship_match'
SKIP_RELATIONSHIP_INACTIVE = 'relationship_inactive'


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


class _ClassifyInbound(Protocol):
    def __call__(
        self, external_account_id: int, external_sender_email: str
    ) -> RelationshipClassifyResult:
        ...


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
    referent_name: Optional[str] = None
    client_name: Optional[str] = None
    local_mailbox: Optional[str] = None
    external_mailbox: Optional[str] = None
    classify_status: Optional[str] = None


@dataclass(frozen=True)
class InboundProcessResult:
    mark_imap_seen: bool
    local_delivered: bool
    plan: InboundDeliveryPlan
    smtp_error: Optional[str] = None
    dispose_imap: bool = False
    source_message_id: Optional[str] = None


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


def disposal_reason_for_skip(skip_reason: Optional[str]) -> Optional[str]:
    if skip_reason == SKIP_NO_RELATIONSHIP:
        return 'no_relationship'
    if skip_reason == SKIP_RELATIONSHIP_INACTIVE:
        return 'relationship_inactive'
    return None


def plan_inbound_delivery(
    *,
    resolve_inbound: Optional[_ResolveInbound] = None,
    classify_inbound: Optional[_ClassifyInbound] = None,
    account_id: int,
    account_email: str,
    from_address: str,
    referent_local_inbox: str,
) -> InboundDeliveryPlan:
    mode = InboundRoutingMode.RELATIONSHIP_LIVE
    mail_from = referent_local_inbox
    sender = (from_address or '').strip()
    try:
        if classify_inbound is not None:
            classified = classify_inbound(int(account_id), sender)
        elif resolve_inbound is not None:
            dto = resolve_inbound(int(account_id), sender)
            if dto is None:
                classified = RelationshipClassifyResult(status=STATUS_NO_MATCH)
            else:
                classified = RelationshipClassifyResult(
                    status=STATUS_MATCHED,
                    dto=dto,
                    relationship_id=dto.relationship_id,
                    referent_name=(dto.referent or {}).get('username'),
                    client_name=dto.external_client_email or dto.local_client_email,
                    local_mailbox=dto.local_referent_email,
                    external_mailbox=dto.external_client_email,
                )
        else:
            raise TypeError('classify_inbound or resolve_inbound required')
    except Exception as exc:
        return InboundDeliveryPlan(
            mode=mode,
            local_rcpts=[],
            mail_from=mail_from,
            lookup_error=str(exc),
        )

    if classified.status == STATUS_NO_MATCH:
        return InboundDeliveryPlan(
            mode=mode,
            local_rcpts=[],
            mail_from=mail_from,
            skip_reason=SKIP_NO_RELATIONSHIP,
            classify_status=STATUS_NO_MATCH,
            referent_name=classified.referent_name,
            client_name=classified.client_name,
            local_mailbox=classified.local_mailbox,
            external_mailbox=classified.external_mailbox,
        )
    if classified.status == STATUS_INACTIVE:
        return InboundDeliveryPlan(
            mode=mode,
            local_rcpts=[],
            mail_from=mail_from,
            skip_reason=SKIP_RELATIONSHIP_INACTIVE,
            classify_status=STATUS_INACTIVE,
            relationship_id=classified.relationship_id,
            referent_name=classified.referent_name,
            client_name=classified.client_name,
            local_mailbox=classified.local_mailbox,
            external_mailbox=classified.external_mailbox,
        )

    dto = classified.dto
    assert dto is not None
    validation_err = validate_relationship_for_live(dto)
    if validation_err:
        return InboundDeliveryPlan(
            mode=mode,
            local_rcpts=[],
            mail_from=mail_from,
            relationship_id=int(dto.relationship_id),
            lookup_error=validation_err,
            classify_status=STATUS_MATCHED,
            referent_name=classified.referent_name,
            client_name=classified.client_name,
            local_mailbox=classified.local_mailbox,
            external_mailbox=classified.external_mailbox,
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
        classify_status=STATUS_MATCHED,
        referent_name=classified.referent_name,
        client_name=classified.client_name,
        local_mailbox=dto.local_referent_email,
        external_mailbox=dto.external_client_email,
    )


def finalize_inbound_process_result(
    plan: InboundDeliveryPlan,
    smtp_delivered: bool,
    smtp_error: Optional[str] = None,
    *,
    dispose_imap: bool = False,
    source_message_id: Optional[str] = None,
) -> InboundProcessResult:
    if plan.lookup_error:
        return InboundProcessResult(
            mark_imap_seen=False,
            local_delivered=False,
            plan=plan,
            smtp_error=plan.lookup_error,
            dispose_imap=False,
            source_message_id=source_message_id,
        )
    if dispose_imap:
        return InboundProcessResult(
            mark_imap_seen=False,
            local_delivered=False,
            plan=plan,
            smtp_error=smtp_error or plan.skip_reason,
            dispose_imap=True,
            source_message_id=source_message_id,
        )
    if plan.skip_reason in (SKIP_NO_RELATIONSHIP, SKIP_RELATIONSHIP_INACTIVE):
        # Caller should journal+dispose; if finalize reached without dispose flag,
        # fail closed (do not mark Seen alone — that was pre-79.2 behaviour).
        return InboundProcessResult(
            mark_imap_seen=False,
            local_delivered=False,
            plan=plan,
            smtp_error=plan.skip_reason,
            dispose_imap=False,
            source_message_id=source_message_id,
        )
    return InboundProcessResult(
        mark_imap_seen=smtp_delivered,
        local_delivered=smtp_delivered,
        plan=plan,
        smtp_error=smtp_error,
        dispose_imap=False,
        source_message_id=source_message_id,
    )


class _ClassifyOutbound(Protocol):
    def __call__(self, local_client_email: str) -> RelationshipClassifyResult:
        ...


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
    referent_name: Optional[str] = None
    client_name: Optional[str] = None
    local_mailbox: Optional[str] = None
    external_mailbox: Optional[str] = None
    classify_status: Optional[str] = None
    dto: Optional[ClientRelationshipDTO] = None


def extract_outbound_identity_from_message(msg: Any) -> str:
    return extract_message_from_address(msg)


def account_dict_from_relationship(dto: ClientRelationshipDTO) -> Dict[str, Any]:
    return dict(dto.account)


def plan_outbound_delivery(
    *,
    resolve_outbound: Optional[_ResolveOutbound] = None,
    classify_outbound: Optional[_ClassifyOutbound] = None,
    from_address: str,
) -> OutboundDeliveryPlan:
    mode = OutboundRoutingMode.RELATIONSHIP_LIVE
    identity = (from_address or '').strip()
    try:
        if classify_outbound is not None:
            classified = classify_outbound(identity)
        elif resolve_outbound is not None:
            dto = resolve_outbound(identity)
            if dto is None:
                classified = RelationshipClassifyResult(status=STATUS_NO_MATCH)
            else:
                classified = RelationshipClassifyResult(
                    status=STATUS_MATCHED,
                    dto=dto,
                    relationship_id=dto.relationship_id,
                    referent_name=(dto.referent or {}).get('username'),
                    client_name=dto.external_client_email or dto.local_client_email,
                    local_mailbox=dto.local_client_email,
                    external_mailbox=dto.external_client_email,
                )
        else:
            raise TypeError('classify_outbound or resolve_outbound required')
    except Exception as exc:
        return OutboundDeliveryPlan(
            mode=mode,
            account=None,
            outbound_identity=identity or None,
            lookup_error=str(exc),
        )

    if classified.status == STATUS_NO_MATCH:
        return OutboundDeliveryPlan(
            mode=mode,
            account=None,
            outbound_identity=identity or None,
            skip_reason=SKIP_NO_RELATIONSHIP,
            classify_status=STATUS_NO_MATCH,
            referent_name=classified.referent_name,
            client_name=classified.client_name,
            local_mailbox=classified.local_mailbox or identity or None,
            external_mailbox=classified.external_mailbox,
        )
    if classified.status == STATUS_INACTIVE:
        return OutboundDeliveryPlan(
            mode=mode,
            account=None,
            outbound_identity=identity or None,
            skip_reason=SKIP_RELATIONSHIP_INACTIVE,
            classify_status=STATUS_INACTIVE,
            relationship_id=classified.relationship_id,
            referent_name=classified.referent_name,
            client_name=classified.client_name,
            local_mailbox=classified.local_mailbox or identity or None,
            external_mailbox=classified.external_mailbox,
        )

    dto = classified.dto
    assert dto is not None
    account = account_dict_from_relationship(dto)
    return OutboundDeliveryPlan(
        mode=mode,
        account=account,
        outbound_identity=identity or None,
        relationship_id=int(dto.relationship_id),
        external_account_id=int(dto.external_account_id),
        classify_status=STATUS_MATCHED,
        referent_name=classified.referent_name,
        client_name=classified.client_name,
        local_mailbox=dto.local_client_email,
        external_mailbox=dto.external_client_email,
        dto=dto,
    )
