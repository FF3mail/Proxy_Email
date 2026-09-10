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
    OutboundRoutingMode,
    finalize_inbound_process_result,
    parse_inbound_routing_mode,
    parse_outbound_routing_mode,
    plan_inbound_delivery,
    plan_outbound_delivery,
    shadow_marker_for_divergence_analysis,
    shadow_marker_for_outbound_divergence,
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

    def test_outbound_default_shadow(self) -> None:
        self.assertEqual(
            parse_outbound_routing_mode({}),
            OutboundRoutingMode.SHADOW,
        )

    def test_outbound_relationship_live(self) -> None:
        self.assertEqual(
            parse_outbound_routing_mode(
                {'OUTBOUND_ROUTING_MODE': 'relationship_live'}
            ),
            OutboundRoutingMode.RELATIONSHIP_LIVE,
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


class OutboundRoutingPlanTest(unittest.TestCase):
    def _legacy_loader(self, account_id: int):
        def load(_referent_id: int) -> Optional[dict]:
            return {
                'id': account_id,
                'email': f'refint{account_id}@example.com',
                'auth_type': 'plain',
                'smtp_host': 'smtp.example.com',
                'smtp_port': 465,
                'smtp_encryption': 'ssl',
            }

        return load

    def test_relationship_a_resolves_account_1(self) -> None:
        dto = _dto(relationship_id=1, external_account_id=1)
        plan = plan_outbound_delivery(
            mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_outbound=lambda _e: dto,
            load_legacy_account=self._legacy_loader(2),
            from_address='clientloc1@testvps.loc',
            referent_id=1,
            shadow_enabled=False,
        )
        self.assertEqual(plan.external_account_id, 1)
        self.assertEqual(plan.account['id'], 1)

    def test_relationship_b_resolves_account_2(self) -> None:
        dto = _dto(
            relationship_id=2,
            external_account_id=2,
            external_client='clientint2@bofoma.net',
            local_client='clientloc2@testvps.loc',
        )
        plan = plan_outbound_delivery(
            mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_outbound=lambda _e: dto,
            load_legacy_account=self._legacy_loader(1),
            from_address='clientloc2@testvps.loc',
            referent_id=1,
            shadow_enabled=False,
        )
        self.assertEqual(plan.external_account_id, 2)
        self.assertNotEqual(plan.account['id'], 1)

    def test_no_relationship_no_account_live(self) -> None:
        plan = plan_outbound_delivery(
            mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_outbound=lambda _e: None,
            load_legacy_account=self._legacy_loader(1),
            from_address='unknown@testvps.loc',
            referent_id=1,
            shadow_enabled=False,
        )
        self.assertIsNone(plan.account)
        self.assertEqual(plan.skip_reason, 'no_relationship_match')

    def test_lookup_error_fail_closed_live(self) -> None:
        def boom(_e: str) -> Optional[ClientRelationshipDTO]:
            raise ConnectionError('db down')

        plan = plan_outbound_delivery(
            mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_outbound=boom,
            load_legacy_account=self._legacy_loader(1),
            from_address='clientloc1@testvps.loc',
            referent_id=1,
            shadow_enabled=False,
        )
        self.assertIsNone(plan.account)
        self.assertIn('db down', plan.lookup_error or '')

    def test_shadow_uses_legacy_account(self) -> None:
        dto = _dto(relationship_id=2, external_account_id=2)
        plan = plan_outbound_delivery(
            mode=OutboundRoutingMode.SHADOW,
            resolve_outbound=lambda _e: dto,
            load_legacy_account=self._legacy_loader(1),
            from_address='clientloc2@testvps.loc',
            referent_id=1,
            shadow_enabled=False,
        )
        self.assertEqual(plan.account['id'], 1)

    def test_cross_relationship_impossible_in_live(self) -> None:
        dto_b = _dto(
            relationship_id=2,
            external_account_id=2,
            local_client='clientloc2@testvps.loc',
        )
        plan = plan_outbound_delivery(
            mode=OutboundRoutingMode.RELATIONSHIP_LIVE,
            resolve_outbound=lambda _e: dto_b,
            load_legacy_account=self._legacy_loader(1),
            from_address='clientloc2@testvps.loc',
            referent_id=1,
            shadow_enabled=False,
        )
        self.assertEqual(plan.external_account_id, 2)
        self.assertNotEqual(plan.external_account_id, 1)

    def test_outbound_divergence_marker(self) -> None:
        self.assertEqual(
            shadow_marker_for_outbound_divergence(1, 2),
            MARKER_DIVERGE_LOOKUP_ONLY,
        )
        self.assertEqual(
            shadow_marker_for_outbound_divergence(1, 1),
            MARKER_AGREE,
        )


if __name__ == '__main__':
    unittest.main(verbosity=2)
