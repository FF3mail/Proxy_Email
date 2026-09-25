#!/usr/bin/env python3
"""
PROMPT-79.1 — relationship-centric routing unit tests (live-only).
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path
from typing import Optional
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from relationship_lookup import ClientRelationshipDTO
from relationship_routing import (
    InboundRoutingMode,
    OutboundRoutingMode,
    OutboundWatchMode,
    finalize_inbound_process_result,
    parse_inbound_routing_mode,
    parse_outbound_routing_mode,
    parse_outbound_watch_mode,
    plan_inbound_delivery,
    plan_outbound_delivery,
    validate_relationship_for_live,
)


def _dto(
    relationship_id: int = 1,
    external_account_id: int = 1,
    external_client: str = 'clientint1@frona.ru',
    local_client: str = 'clientloc1@testvps.loc',
    local_referent: str = 'refloc1@testvps.loc',
    maildir: str = '/var/vmail/clientloc1/Maildir',
) -> ClientRelationshipDTO:
    return ClientRelationshipDTO(
        relationship_id=relationship_id,
        referent_id=1,
        external_client_email=external_client,
        local_client_email=local_client,
        local_referent_email=local_referent,
        external_account_id=external_account_id,
        external_referent_email='refint1@frona.ru',
        local_client_maildir=maildir,
        account={'id': external_account_id, 'email': 'refint1@frona.ru'},
        referent={'id': 1, 'local_inbox': 'refloc1@testvps.loc'},
    )


class ParseModeTest(unittest.TestCase):
    def test_inbound_default_live(self) -> None:
        self.assertEqual(
            parse_inbound_routing_mode({}),
            InboundRoutingMode.RELATIONSHIP_LIVE,
        )

    def test_inbound_unsupported_falls_back_live(self) -> None:
        self.assertEqual(
            parse_inbound_routing_mode({'INBOUND_ROUTING_MODE': 'shadow'}),
            InboundRoutingMode.RELATIONSHIP_LIVE,
        )

    def test_inbound_relationship_live(self) -> None:
        self.assertEqual(
            parse_inbound_routing_mode(
                {'INBOUND_ROUTING_MODE': 'relationship_live'}
            ),
            InboundRoutingMode.RELATIONSHIP_LIVE,
        )

    def test_outbound_default_live(self) -> None:
        self.assertEqual(
            parse_outbound_routing_mode({}),
            OutboundRoutingMode.RELATIONSHIP_LIVE,
        )

    def test_outbound_relationship_live(self) -> None:
        self.assertEqual(
            parse_outbound_routing_mode(
                {'OUTBOUND_ROUTING_MODE': 'relationship_live'}
            ),
            OutboundRoutingMode.RELATIONSHIP_LIVE,
        )

    def test_watch_default_relationship_only(self) -> None:
        self.assertEqual(
            parse_outbound_watch_mode({}),
            OutboundWatchMode.RELATIONSHIP_ONLY,
        )

    def test_watch_relationship_only(self) -> None:
        self.assertEqual(
            parse_outbound_watch_mode(
                {'OUTBOUND_WATCH_MODE': 'relationship_only'}
            ),
            OutboundWatchMode.RELATIONSHIP_ONLY,
        )

    def test_watch_unsupported_falls_back_relationship_only(self) -> None:
        self.assertEqual(
            parse_outbound_watch_mode({'OUTBOUND_WATCH_MODE': 'dual'}),
            OutboundWatchMode.RELATIONSHIP_ONLY,
        )


class RelationshipLivePlanTest(unittest.TestCase):
    @patch('relationship_routing.os.path.isdir', return_value=True)
    def test_exact_match_relationship_a(self, _isdir: MagicMock) -> None:
        dto = _dto(relationship_id=1, local_referent='refloc1@testvps.loc')
        plan = plan_inbound_delivery(
            resolve_inbound=lambda _a, _s: dto,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='clientint1@frona.ru',
            referent_local_inbox='refloc1@testvps.loc',
        )
        self.assertEqual(plan.mode, InboundRoutingMode.RELATIONSHIP_LIVE)
        self.assertEqual(plan.local_rcpts, ['refloc1@testvps.loc'])
        self.assertEqual(plan.relationship_id, 1)

    def test_unknown_sender_no_legacy_fallback(self) -> None:
        plan = plan_inbound_delivery(
            resolve_inbound=lambda _a, _s: None,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='unknown@frona.ru',
            referent_local_inbox='refloc1@testvps.loc',
        )
        self.assertEqual(plan.local_rcpts, [])
        self.assertEqual(plan.skip_reason, 'no_relationship_match')

    @patch('relationship_routing.os.path.isdir', return_value=False)
    def test_invalid_maildir_fail_closed(self, _isdir: MagicMock) -> None:
        dto = _dto()
        plan = plan_inbound_delivery(
            resolve_inbound=lambda _a, _s: dto,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='clientint1@frona.ru',
            referent_local_inbox='refloc1@testvps.loc',
        )
        self.assertEqual(plan.local_rcpts, [])
        self.assertIsNotNone(plan.lookup_error)


class ProcessResultTest(unittest.TestCase):
    def test_live_miss_does_not_mark_seen_without_dispose_flag(self) -> None:
        from relationship_routing import InboundDeliveryPlan

        plan = InboundDeliveryPlan(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            local_rcpts=[],
            mail_from='refloc1@testvps.loc',
            skip_reason='no_relationship_match',
        )
        result = finalize_inbound_process_result(plan, False)
        self.assertFalse(result.mark_imap_seen)
        self.assertFalse(result.dispose_imap)
        self.assertFalse(result.local_delivered)

    def test_dispose_flag_sets_dispose_imap(self) -> None:
        from relationship_routing import InboundDeliveryPlan

        plan = InboundDeliveryPlan(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            local_rcpts=[],
            mail_from='refloc1@testvps.loc',
            skip_reason='no_relationship_match',
        )
        result = finalize_inbound_process_result(plan, False, dispose_imap=True)
        self.assertTrue(result.dispose_imap)
        self.assertFalse(result.mark_imap_seen)

    def test_live_error_no_seen(self) -> None:
        from relationship_routing import InboundDeliveryPlan

        plan = InboundDeliveryPlan(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            local_rcpts=[],
            mail_from='refloc1@testvps.loc',
            lookup_error='db down',
        )
        result = finalize_inbound_process_result(plan, False)
        self.assertFalse(result.mark_imap_seen)
        self.assertFalse(result.dispose_imap)
        self.assertFalse(result.local_delivered)


class OutboundRoutingPlanTest(unittest.TestCase):
    def test_relationship_resolves_account(self) -> None:
        dto = _dto(relationship_id=1, external_account_id=1)
        plan = plan_outbound_delivery(
            resolve_outbound=lambda _e: dto,
            from_address='clientloc1@testvps.loc',
        )
        self.assertEqual(plan.external_account_id, 1)
        self.assertEqual(plan.account['id'], 1)

    def test_no_relationship_no_account(self) -> None:
        plan = plan_outbound_delivery(
            resolve_outbound=lambda _e: None,
            from_address='unknown@testvps.loc',
        )
        self.assertIsNone(plan.account)
        self.assertEqual(plan.skip_reason, 'no_relationship_match')

    def test_lookup_error_fail_closed(self) -> None:
        def boom(_e: str) -> Optional[ClientRelationshipDTO]:
            raise ConnectionError('db down')

        plan = plan_outbound_delivery(
            resolve_outbound=boom,
            from_address='clientloc1@testvps.loc',
        )
        self.assertIsNone(plan.account)
        self.assertIn('db down', plan.lookup_error or '')


class ValidateRelationshipTest(unittest.TestCase):
    @patch('relationship_routing.os.path.isdir', return_value=True)
    def test_valid(self, _isdir: MagicMock) -> None:
        self.assertIsNone(validate_relationship_for_live(_dto()))

    def test_missing_maildir(self) -> None:
        dto = _dto(maildir='')
        self.assertIn('maildir', validate_relationship_for_live(dto) or '')


if __name__ == '__main__':
    unittest.main(verbosity=2)
