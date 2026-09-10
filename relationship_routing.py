#!/usr/bin/env python3
"""
Inbound relationship-centric routing (PROMPT-63 Stage 2a).

Pure decision helpers. Import has no side effects beyond definitions.
"""

from __future__ import annotations

import logging
import os
from dataclasses import dataclass
from enum import Enum
from typing import Any, Callable, List, Optional, Protocol

from relationship_lookup import ClientRelationshipDTO, normalize_email
from relationship_shadow import (
    MARKER_AGREE,
    MARKER_DIVERGE_LEGACY_ONLY,
    MARKER_DIVERGE_LOOKUP_ONLY,
    ShadowCounters,
    classify_inbound_shadow,
    evaluate_inbound_shadow,
    extract_message_from_address,
    final_legacy_rcpts,
    relationship_lookup_shadow_enabled,
)


class InboundRoutingMode(str, Enum):
    SHADOW = 'shadow'
    LEGACY = 'legacy'
    RELATIONSHIP_LIVE = 'relationship_live'


def parse_inbound_routing_mode(
    environ: Optional[dict] = None,
) -> InboundRoutingMode:
    """
    INBOUND_ROUTING_MODE env: shadow (default) | legacy | relationship_live.
    Safe default after deploy: shadow.
    """
    env = environ if environ is not None else os.environ
    raw = (env.get('INBOUND_ROUTING_MODE') or 'shadow').strip().lower()
    if raw in ('legacy',):
        return InboundRoutingMode.LEGACY
    if raw in ('relationship_live', 'live', 'relationship'):
        return InboundRoutingMode.RELATIONSHIP_LIVE
    return InboundRoutingMode.SHADOW


class _ResolveInbound(Protocol):
    def __call__(
        self, external_account_id: int, external_sender_email: str
    ) -> Optional[ClientRelationshipDTO]:
        ...


@dataclass(frozen=True)
class InboundDeliveryPlan:
    """Resolved inbound routing decision (before SMTP attempt)."""

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
    """Outcome after attempting local delivery (or explicit skip)."""

    mark_imap_seen: bool
    local_delivered: bool
    plan: InboundDeliveryPlan
    smtp_error: Optional[str] = None


def relationship_target_email(dto: ClientRelationshipDTO) -> Optional[str]:
    """PROMPT-53 inbound local target: local_referent_email."""
    target = normalize_email(dto.local_referent_email or '')
    return target or None


def validate_relationship_for_live(dto: ClientRelationshipDTO) -> Optional[str]:
    """
    Return error string if relationship is not valid for live routing; else None.
    """
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
    mode: InboundRoutingMode,
    resolve_inbound: _ResolveInbound,
    account_id: int,
    account_email: str,
    from_address: str,
    legacy_resolved: List[str],
    referent_local_inbox: str,
    shadow_enabled: bool,
    shadow_counters: Optional[ShadowCounters] = None,
    shadow_log: Optional[logging.Logger] = None,
) -> InboundDeliveryPlan:
    """
    Compute local_rcpts and mail_from without performing SMTP.
    relationship_live: no legacy fallback on miss/error.
    """
    legacy_rcpts = final_legacy_rcpts(legacy_resolved, referent_local_inbox)
    mail_from = referent_local_inbox
    sender = (from_address or '').strip()

    if mode == InboundRoutingMode.RELATIONSHIP_LIVE:
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

    # shadow or legacy: legacy routing for delivery
    if mode == InboundRoutingMode.SHADOW and shadow_enabled:
        evaluate_inbound_shadow(
            resolve_inbound=resolve_inbound,
            account_id=account_id,
            account_email=account_email,
            from_address=sender,
            legacy_delivered=bool(legacy_resolved),
            legacy_rcpts=list(legacy_rcpts),
            counters=shadow_counters,
            log=shadow_log,
        )

    return InboundDeliveryPlan(
        mode=mode,
        local_rcpts=list(legacy_rcpts),
        mail_from=mail_from,
    )


def finalize_inbound_process_result(
    plan: InboundDeliveryPlan,
    smtp_delivered: bool,
    smtp_error: Optional[str] = None,
) -> InboundProcessResult:
    """Map SMTP outcome + plan to IMAP mark-seen policy."""
    if plan.mode == InboundRoutingMode.RELATIONSHIP_LIVE:
        if plan.lookup_error:
            return InboundProcessResult(
                mark_imap_seen=False,
                local_delivered=False,
                plan=plan,
                smtp_error=plan.lookup_error,
            )
        if plan.skip_reason == 'no_relationship_match':
            # Interim policy: no local route, acknowledge on IMAP (DELETE not implemented).
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

    return InboundProcessResult(
        mark_imap_seen=smtp_delivered,
        local_delivered=smtp_delivered,
        plan=plan,
        smtp_error=smtp_error,
    )


def shadow_marker_for_divergence_analysis(
    legacy_resolved: List[str],
    relationship_id: Optional[int],
) -> str:
    """Expose PROMPT-58 classifier for reports/tests."""
    return classify_inbound_shadow(bool(legacy_resolved), relationship_id)
