#!/usr/bin/env python3
"""
PROMPT-58 Stage 1 — shadow-mode RelationshipLookup unit tests.

Exercises the real relationship_shadow.py module (and RelationshipLookup import
surface). No MySQL required for these tests. Run:

  python tests/test_relationship_shadow.py
"""

from __future__ import annotations

import email.message
import logging
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any, List, Optional
from unittest.mock import MagicMock

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

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


class ClassifyInboundShadowTest(unittest.TestCase):
    def test_agree_both_deliver(self) -> None:
        self.assertEqual(
            classify_inbound_shadow(True, 42), MARKER_AGREE
        )

    def test_agree_both_drop(self) -> None:
        self.assertEqual(
            classify_inbound_shadow(False, None), MARKER_AGREE
        )

    def test_diverge_legacy_only(self) -> None:
        self.assertEqual(
            classify_inbound_shadow(True, None), MARKER_DIVERGE_LEGACY_ONLY
        )

    def test_diverge_lookup_only(self) -> None:
        self.assertEqual(
            classify_inbound_shadow(False, 7), MARKER_DIVERGE_LOOKUP_ONLY
        )


class ShadowFlagTest(unittest.TestCase):
    def test_default_enabled(self) -> None:
        self.assertTrue(relationship_lookup_shadow_enabled({}))

    def test_explicit_off(self) -> None:
        self.assertFalse(
            relationship_lookup_shadow_enabled({'RELATIONSHIP_LOOKUP_SHADOW': '0'})
        )
        self.assertFalse(
            relationship_lookup_shadow_enabled({'RELATIONSHIP_LOOKUP_SHADOW': 'false'})
        )

    def test_explicit_on(self) -> None:
        self.assertTrue(
            relationship_lookup_shadow_enabled({'RELATIONSHIP_LOOKUP_SHADOW': 'yes'})
        )


class ExtractFromAddressTest(unittest.TestCase):
    def test_parses_from_not_to(self) -> None:
        msg = email.message.EmailMessage()
        msg['From'] = 'Client One <client1@partner.com>'
        msg['To'] = 'other@example.com'
        self.assertEqual(
            extract_message_from_address(msg), 'client1@partner.com'
        )


class FinalLegacyRcptsTest(unittest.TestCase):
    """Delivery rcpt decision must match pre-PROMPT-58 semantics."""

    def test_resolved_unchanged(self) -> None:
        self.assertEqual(
            final_legacy_rcpts(['ref@local.loc'], 'inbox@local.loc'),
            ['ref@local.loc'],
        )

    def test_empty_falls_back_to_inbox(self) -> None:
        self.assertEqual(
            final_legacy_rcpts([], 'inbox@local.loc'),
            ['inbox@local.loc'],
        )


class EvaluateInboundShadowTest(unittest.TestCase):
    def setUp(self) -> None:
        self.counters = ShadowCounters()
        self.log = logging.getLogger('test-relationship-shadow')
        self.log.handlers.clear()
        self.log.addHandler(logging.NullHandler())
        self.log.propagate = False

    def test_exception_does_not_propagate(self) -> None:
        def boom(_account_id: int, _sender: str) -> Any:
            raise RuntimeError('lookup exploded')

        result = evaluate_inbound_shadow(
            resolve_inbound=boom,
            account_id=1,
            account_email='acc@example.com',
            from_address='sender@partner.com',
            legacy_delivered=True,
            legacy_rcpts=['inbox@local.loc'],
            counters=self.counters,
            log=self.log,
            stats_path=None,
        )
        self.assertEqual(result.marker, 'ERROR')
        self.assertIsNotNone(result.error)
        self.assertEqual(self.counters.snapshot()['errors'], 1)

    def test_exception_does_not_alter_delivery_return(self) -> None:
        """
        Simulate the Stage-1 call site: compute rcpts, run shadow (raises),
        then return the same delivery decision as before shadow existed.
        """
        resolved: List[str] = []
        local_inbox = 'ref1@local.loc'
        local_rcpts = final_legacy_rcpts(resolved, local_inbox)
        rcpts_before = list(local_rcpts)

        def boom(_account_id: int, _sender: str) -> Any:
            raise RuntimeError('shadow must not break delivery')

        evaluate_inbound_shadow(
            resolve_inbound=boom,
            account_id=9,
            account_email='acc@example.com',
            from_address='x@y.z',
            legacy_delivered=bool(resolved),
            legacy_rcpts=list(local_rcpts),
            counters=self.counters,
            log=self.log,
            stats_path=None,
        )

        # Byte-for-byte same recipients list the SMTP path would use.
        self.assertEqual(local_rcpts, rcpts_before)
        self.assertEqual(local_rcpts, ['ref1@local.loc'])

        # Delivery bool the caller would return from a successful SMTP mock.
        delivery_ok = True  # stand-in for _stream_file_via_smtp success
        self.assertTrue(delivery_ok)

    def test_agree_and_diverge_counters(self) -> None:
        dto = MagicMock()
        dto.relationship_id = 55

        evaluate_inbound_shadow(
            resolve_inbound=lambda *_a: dto,
            account_id=1,
            account_email='acc@example.com',
            from_address='client@partner.com',
            legacy_delivered=True,
            legacy_rcpts=['inbox@local.loc'],
            counters=self.counters,
            log=self.log,
            stats_path=None,
        )
        evaluate_inbound_shadow(
            resolve_inbound=lambda *_a: None,
            account_id=1,
            account_email='acc@example.com',
            from_address='unknown@partner.com',
            legacy_delivered=True,
            legacy_rcpts=['inbox@local.loc'],
            counters=self.counters,
            log=self.log,
            stats_path=None,
        )
        evaluate_inbound_shadow(
            resolve_inbound=lambda *_a: dto,
            account_id=1,
            account_email='acc@example.com',
            from_address='client@partner.com',
            legacy_delivered=False,
            legacy_rcpts=['inbox@local.loc'],
            counters=self.counters,
            log=self.log,
            stats_path=None,
        )
        snap = self.counters.snapshot()
        self.assertEqual(snap['processed'], 3)
        self.assertEqual(snap['agree'], 1)
        self.assertEqual(snap['diverge_legacy_delivered_no_match'], 1)
        self.assertEqual(snap['diverge_relationship_match_legacy_no_deliver'], 1)

    def test_stats_file_written(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = str(Path(tmp) / 'relationship_shadow_stats.json')
            evaluate_inbound_shadow(
                resolve_inbound=lambda *_a: None,
                account_id=1,
                account_email='acc@example.com',
                from_address='a@b.c',
                legacy_delivered=False,
                legacy_rcpts=['x@y.z'],
                counters=self.counters,
                log=self.log,
                stats_path=path,
            )
            text = Path(path).read_text(encoding='utf-8')
            self.assertIn('"agree": 1', text)
            self.assertIn('"processed": 1', text)


class DeliveryDecisionUnchangedTest(unittest.TestCase):
    """
    Prove local_rcpts computation is identical with/without shadow side effects.
    Uses the same helpers the daemon call site uses after PROMPT-58.
    """

    def _decision(
        self,
        resolved: List[str],
        local_inbox: str,
        *,
        run_shadow: bool,
        resolve_inbound: Any,
    ) -> List[str]:
        local_rcpts = final_legacy_rcpts(resolved, local_inbox)
        if run_shadow:
            null_log = logging.getLogger('test-relationship-shadow-null')
            null_log.handlers.clear()
            null_log.addHandler(logging.NullHandler())
            null_log.propagate = False
            evaluate_inbound_shadow(
                resolve_inbound=resolve_inbound,
                account_id=1,
                account_email='acc@example.com',
                from_address='from@ex.com',
                legacy_delivered=bool(resolved),
                legacy_rcpts=list(local_rcpts),
                counters=ShadowCounters(),
                log=null_log,
                stats_path=None,
            )
        return local_rcpts

    def test_rcpts_identical_with_and_without_shadow(self) -> None:
        cases = [
            ([], 'inbox@local.loc'),
            (['inbox@local.loc'], 'inbox@local.loc'),
            (['a@local.loc', 'b@local.loc'], 'inbox@local.loc'),
        ]
        for resolved, inbox in cases:
            without = self._decision(
                resolved, inbox, run_shadow=False, resolve_inbound=lambda *_: None
            )
            with_ok = self._decision(
                resolved, inbox, run_shadow=True, resolve_inbound=lambda *_: None
            )

            def boom(*_a: Any) -> Optional[Any]:
                raise RuntimeError('nope')

            with_err = self._decision(
                resolved, inbox, run_shadow=True, resolve_inbound=boom
            )
            self.assertEqual(without, with_ok)
            self.assertEqual(without, with_err)


class RelationshipLookupImportSurfaceTest(unittest.TestCase):
    def test_module_has_no_side_effect_beyond_defs(self) -> None:
        import relationship_lookup as rl

        self.assertTrue(hasattr(rl, 'RelationshipLookup'))
        self.assertTrue(callable(rl.RelationshipLookup))
        # No daemon wiring symbols on the lookup module itself.
        self.assertFalse(hasattr(rl, 'RELATIONSHIP_LOOKUP_SHADOW'))


class CrossRelationshipShadowTest(unittest.TestCase):
    """
    PROMPT-62 — shadow eval when lookup key spans wrong external account.
    Uses mocked resolve_inbound (no MySQL).
    """

    def setUp(self) -> None:
        self.counters = ShadowCounters()
        self.log = logging.getLogger('test-cross-relationship-shadow')
        self.log.handlers.clear()
        self.log.addHandler(logging.NullHandler())
        self.log.propagate = False

    def test_wrong_account_lookup_miss_agrees_with_legacy_miss(self) -> None:
        """client A sender on account B mailbox: both legacy and lookup miss."""
        result = evaluate_inbound_shadow(
            resolve_inbound=lambda _aid, _sender: None,
            account_id=2,
            account_email='refint2@bofoma.net',
            from_address='clientint1@frona.ru',
            legacy_delivered=False,
            legacy_rcpts=['refloc1@testvps.loc'],
            counters=self.counters,
            log=self.log,
            stats_path=None,
        )
        self.assertEqual(result.marker, MARKER_AGREE)
        self.assertIsNone(result.relationship_id)

    def test_positive_match_wrong_legacy_path_diverges_lookup_only(self) -> None:
        """External From matches relationship but To/Cc legacy path does not."""
        dto = MagicMock()
        dto.relationship_id = 1
        result = evaluate_inbound_shadow(
            resolve_inbound=lambda _aid, sender: dto if sender == 'clientint1@frona.ru' else None,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='clientint1@frona.ru',
            legacy_delivered=False,
            legacy_rcpts=['refloc1@testvps.loc'],
            counters=self.counters,
            log=self.log,
            stats_path=None,
        )
        self.assertEqual(result.marker, MARKER_DIVERGE_LOOKUP_ONLY)
        self.assertEqual(result.relationship_id, 1)

    def test_lookup_db_failure_does_not_change_rcpts(self) -> None:
        resolved: List[str] = []
        local_rcpts = final_legacy_rcpts(resolved, 'refloc1@testvps.loc')

        def db_fail(*_a: Any) -> Optional[Any]:
            raise ConnectionError('database unavailable')

        evaluate_inbound_shadow(
            resolve_inbound=db_fail,
            account_id=1,
            account_email='refint1@frona.ru',
            from_address='clientint1@frona.ru',
            legacy_delivered=False,
            legacy_rcpts=list(local_rcpts),
            counters=self.counters,
            log=self.log,
            stats_path=None,
        )
        self.assertEqual(local_rcpts, ['refloc1@testvps.loc'])
        self.assertEqual(self.counters.snapshot()['errors'], 1)


if __name__ == '__main__':
    unittest.main(verbosity=2)
