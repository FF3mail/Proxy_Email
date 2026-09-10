#!/usr/bin/env python3
"""
PROMPT-63 Stage 2a — inbound relationship routing unit tests.
"""

from __future__ import annotations

import logging
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
    finalize_inbound_process_result,
    parse_inbound_routing_mode,
    plan_inbound_delivery,
    shadow_marker_for_divergence_analysis,
    validate_relationship_for_live,
)
from relationship_shadow import (
    MARKER_AGREE,
    MARKER_DIVERGE_LOOKUP_ONLY,
    ShadowCounters,
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
    def test_default_shadow(self) -> None:
        self.assertEqual(
            parse_inbound_routing_mode({}),
            InboundRoutingMode.SHADOW,
        )

    def test_legacy(self) -> None:
        self.assertEqual(
            parse_inbound_routing_mode({'INBOUND_ROUTING_MODE': 'legacy'}),
            InboundRoutingMode.LEGACY,
        )

    def test_relationship_live(self) -> None:
        self.assertEqual(
            parse_inbound_routing_mode(
                {'INBOUND_ROUTING_MODE': 'relationship_live'}
            ),
            InboundRoutingMode.RELATIONSHIP_LIVE,
        )


class DivergenceAnalysisTest(unittest.TestCase):
    """PROMPT-62 divergence cases — legacy To/Cc miss, lookup From hit."""

    def test_case_a_clientint1_on_account1(self) -> None:
        marker = shadow_marker_for_divergence_analysis([], 1)
        self.assertEqual(marker, MARKER_DIVERGE_LOOKUP_ONLY)

    def test_case_b_clientint2_on_account2(self) -> None:
        marker = shadow_marker_for_divergence_analysis([], 2)
        self.assertEqual(marker, MARKER_DIVERGE_LOOKUP_ONLY)

    def test_unknown_sender_agree(self) -> None:
        marker = shadow_marker_for_divergence_analysis([], None)
        self.assertEqual(marker, MARKER_AGREE)


class RelationshipLivePlanTest(unittest.TestCase):
    def setUp(self) -> None:
        self.counters = ShadowCounters()
        self.log = logging.getLogger('test-routing')
        self.log.handlers.clear()
        self.log.addHandler(logging.NullHandler())
        self.log.propagate = False

    @patch('relationship_routing.os.path.isdir', return_value=True)
    def test_exact_match_relationship_a(self, _isdir: MagicMock) -> None:
        dto = _dto(relationship_id=1, local_referent='refloc1@testvps.loc')
        plan = plan_inbound_delivery(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_inbound=lambda _a, _s: dto,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='clientint1@frona.ru',
            legacy_resolved=[],
            referent_local_inbox='refloc1@testvps.loc',
            shadow_enabled=False,
        )
        self.assertEqual(plan.local_rcpts, ['refloc1@testvps.loc'])
        self.assertEqual(plan.relationship_id, 1)
        self.assertEqual(plan.local_target_email, 'refloc1@testvps.loc')

    @patch('relationship_routing.os.path.isdir', return_value=True)
    def test_second_relationship_match_b(self, _isdir: MagicMock) -> None:
        dto = _dto(
            relationship_id=2,
            external_account_id=2,
            external_client='clientint2@bofoma.net',
            local_referent='refloc2@testvps.loc',
            maildir='/var/vmail/clientloc2/Maildir',
        )
        plan = plan_inbound_delivery(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_inbound=lambda _a, _s: dto,
            account_id=2,
            account_email='refint2@bofoma.net',
            from_address='clientint2@bofoma.net',
            legacy_resolved=[],
            referent_local_inbox='refloc1@testvps.loc',
            shadow_enabled=False,
        )
        self.assertEqual(plan.local_rcpts, ['refloc2@testvps.loc'])
        self.assertNotIn('refloc1@testvps.loc', plan.local_rcpts)

    def test_unknown_sender_no_legacy_fallback(self) -> None:
        plan = plan_inbound_delivery(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_inbound=lambda _a, _s: None,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='unknown@frona.ru',
            legacy_resolved=[],
            referent_local_inbox='refloc1@testvps.loc',
            shadow_enabled=False,
        )
        self.assertEqual(plan.local_rcpts, [])
        self.assertEqual(plan.skip_reason, 'no_relationship_match')

    def test_cross_account_miss_no_fallback(self) -> None:
        plan = plan_inbound_delivery(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_inbound=lambda _a, _s: None,
            account_id=2,
            account_email='refint2@bofoma.net',
            from_address='clientint1@frona.ru',
            legacy_resolved=[],
            referent_local_inbox='refloc1@testvps.loc',
            shadow_enabled=False,
        )
        self.assertEqual(plan.local_rcpts, [])
        self.assertEqual(plan.skip_reason, 'no_relationship_match')

    def test_lookup_exception_fail_closed(self) -> None:
        def boom(_a: int, _s: str) -> Optional[ClientRelationshipDTO]:
            raise ConnectionError('db down')

        plan = plan_inbound_delivery(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_inbound=boom,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='clientint1@frona.ru',
            legacy_resolved=[],
            referent_local_inbox='refloc1@testvps.loc',
            shadow_enabled=False,
        )
        self.assertEqual(plan.local_rcpts, [])
        self.assertIn('db down', plan.lookup_error or '')

    @patch('relationship_routing.os.path.isdir', return_value=False)
    def test_invalid_maildir_fail_closed(self, _isdir: MagicMock) -> None:
        dto = _dto()
        plan = plan_inbound_delivery(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_inbound=lambda _a, _s: dto,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='clientint1@frona.ru',
            legacy_resolved=[],
            referent_local_inbox='refloc1@testvps.loc',
            shadow_enabled=False,
        )
        self.assertEqual(plan.local_rcpts, [])
        self.assertIsNotNone(plan.lookup_error)


class ShadowLegacyModeTest(unittest.TestCase):
    def test_shadow_uses_legacy_rcpts(self) -> None:
        plan = plan_inbound_delivery(
            mode=InboundRoutingMode.SHADOW,
            resolve_inbound=lambda _a, _s: None,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='x@y.z',
            legacy_resolved=[],
            referent_local_inbox='refloc1@testvps.loc',
            shadow_enabled=False,
        )
        self.assertEqual(plan.local_rcpts, ['refloc1@testvps.loc'])

    def test_legacy_uses_inbox_fallback(self) -> None:
        plan = plan_inbound_delivery(
            mode=InboundRoutingMode.LEGACY,
            resolve_inbound=lambda _a, _s: None,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='x@y.z',
            legacy_resolved=[],
            referent_local_inbox='refloc1@testvps.loc',
            shadow_enabled=False,
        )
        self.assertEqual(plan.local_rcpts, ['refloc1@testvps.loc'])


class ProcessResultTest(unittest.TestCase):
    def test_live_miss_marks_seen_without_delivery(self) -> None:
        from relationship_routing import InboundDeliveryPlan

        plan = InboundDeliveryPlan(
            mode=InboundRoutingMode.RELATIONSHIP_LIVE,
            local_rcpts=[],
            mail_from='refloc1@testvps.loc',
            skip_reason='no_relationship_match',
        )
        result = finalize_inbound_process_result(plan, False)
        self.assertTrue(result.mark_imap_seen)
        self.assertFalse(result.local_delivered)

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
        self.assertFalse(result.local_delivered)


if __name__ == '__main__':
    unittest.main(verbosity=2)
