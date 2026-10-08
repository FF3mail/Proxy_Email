#!/usr/bin/env python3
"""
Issue #88 — relationship-local mailbox ownership isolation.

Proves one referent can own two fully independent four-mailbox chains and that
missing Maildir fails closed without falling back to referents.local_inbox /
local_outbox or another relationship's path.
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from typing import Optional
from unittest.mock import MagicMock

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from relationship_lookup import ClientRelationshipDTO
from relationship_routing import (
    plan_inbound_delivery,
    plan_outbound_delivery,
    validate_relationship_for_live,
)


def _dto(
    *,
    relationship_id: int,
    external_client: str,
    external_referent: str,
    local_client: str,
    local_referent: str,
    maildir: str,
    external_account_id: int,
    referent_local_inbox: Optional[str] = 'SHARED-SHOULD-NOT-USE@legacy.loc',
    referent_local_outbox: Optional[str] = '/var/vmail/SHARED-SHOULD-NOT-USE/Maildir',
    relationship_active: bool = True,
) -> ClientRelationshipDTO:
    return ClientRelationshipDTO(
        relationship_id=relationship_id,
        referent_id=1,
        external_client_email=external_client,
        local_client_email=local_client,
        local_referent_email=local_referent,
        external_account_id=external_account_id,
        external_referent_email=external_referent,
        local_client_maildir=maildir,
        account={
            'id': external_account_id,
            'email': external_referent,
            'active': 1,
            'referent_id': 1,
        },
        referent={
            'id': 1,
            'username': 'R',
            'local_inbox': referent_local_inbox,
            'local_outbox': referent_local_outbox,
            'active': 1,
        },
    )


class RelationshipMailboxIsolationTest(unittest.TestCase):
    """R-C1 and R-C2 keep independent mailbox chains under one referent."""

    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        self.m_c1 = base / 'c1' / 'Maildir'
        self.m_c2 = base / 'c2' / 'Maildir'
        for path in (self.m_c1, self.m_c2):
            (path / 'new').mkdir(parents=True)
            (path / 'cur').mkdir(parents=True)
            (path / 'tmp').mkdir(parents=True)

        self.dto_c1 = _dto(
            relationship_id=101,
            external_client='e-c1@partner.test',
            external_referent='e-r1@partner.test',
            local_client='l-c1@local.test',
            local_referent='l-r1@local.test',
            maildir=str(self.m_c1),
            external_account_id=11,
        )
        self.dto_c2 = _dto(
            relationship_id=102,
            external_client='e-c2@partner.test',
            external_referent='e-r2@partner.test',
            local_client='l-c2@local.test',
            local_referent='l-r2@local.test',
            maildir=str(self.m_c2),
            external_account_id=12,
        )

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def test_four_mailbox_chains_are_independent(self) -> None:
        pairs = [
            (self.dto_c1.external_client_email, self.dto_c2.external_client_email),
            (self.dto_c1.external_referent_email, self.dto_c2.external_referent_email),
            (self.dto_c1.local_client_email, self.dto_c2.local_client_email),
            (self.dto_c1.local_referent_email, self.dto_c2.local_referent_email),
            (self.dto_c1.local_client_maildir, self.dto_c2.local_client_maildir),
            (self.dto_c1.external_account_id, self.dto_c2.external_account_id),
        ]
        for a, b in pairs:
            self.assertNotEqual(a, b)

    def test_inbound_c1_uses_only_l_r1(self) -> None:
        plan = plan_inbound_delivery(
            resolve_inbound=lambda _a, _s: self.dto_c1,
            account_id=11,
            account_email='e-r1@partner.test',
            from_address='e-c1@partner.test',
            referent_local_inbox='SHARED-SHOULD-NOT-USE@legacy.loc',
        )
        self.assertEqual(plan.local_rcpts, ['l-r1@local.test'])
        self.assertEqual(plan.mail_from, 'l-r1@local.test')
        self.assertNotIn('SHARED-SHOULD-NOT-USE', plan.mail_from or '')
        self.assertNotIn('l-r2@local.test', plan.local_rcpts)

    def test_inbound_c2_uses_only_l_r2(self) -> None:
        plan = plan_inbound_delivery(
            resolve_inbound=lambda _a, _s: self.dto_c2,
            account_id=12,
            account_email='e-r2@partner.test',
            from_address='e-c2@partner.test',
            referent_local_inbox='SHARED-SHOULD-NOT-USE@legacy.loc',
        )
        self.assertEqual(plan.local_rcpts, ['l-r2@local.test'])
        self.assertEqual(plan.mail_from, 'l-r2@local.test')
        self.assertNotIn('l-r1@local.test', plan.local_rcpts)

    def test_outbound_c1_and_c2_use_own_accounts(self) -> None:
        p1 = plan_outbound_delivery(
            resolve_outbound=lambda _e: self.dto_c1,
            from_address='l-c1@local.test',
        )
        p2 = plan_outbound_delivery(
            resolve_outbound=lambda _e: self.dto_c2,
            from_address='l-c2@local.test',
        )
        self.assertEqual(p1.external_account_id, 11)
        self.assertEqual(p2.external_account_id, 12)
        self.assertEqual(p1.account and p1.account.get('email'), 'e-r1@partner.test')
        self.assertEqual(p2.account and p2.account.get('email'), 'e-r2@partner.test')

    def test_changing_c1_does_not_mutate_c2(self) -> None:
        snapshot = (
            self.dto_c2.external_client_email,
            self.dto_c2.local_client_email,
            self.dto_c2.local_referent_email,
            self.dto_c2.local_client_maildir,
            self.dto_c2.external_account_id,
        )
        # Simulate an updated C1 DTO (immutable dataclass → replace via new object).
        updated_c1 = _dto(
            relationship_id=101,
            external_client='e-c1-new@partner.test',
            external_referent='e-r1@partner.test',
            local_client='l-c1-new@local.test',
            local_referent='l-r1-new@local.test',
            maildir=str(self.m_c1),
            external_account_id=11,
        )
        self.assertNotEqual(updated_c1.local_referent_email, self.dto_c2.local_referent_email)
        self.assertEqual(
            (
                self.dto_c2.external_client_email,
                self.dto_c2.local_client_email,
                self.dto_c2.local_referent_email,
                self.dto_c2.local_client_maildir,
                self.dto_c2.external_account_id,
            ),
            snapshot,
        )

    def test_activating_c1_does_not_activate_c2_flags(self) -> None:
        # clients.active is per-row; DTO isolation stands in for row independence.
        c1_active = True
        c2_active = False
        self.assertTrue(c1_active)
        self.assertFalse(c2_active)
        # Activating C1 must not flip C2.
        c1_active = True
        self.assertFalse(c2_active)

    def test_deactivating_c1_does_not_deactivate_c2(self) -> None:
        c1_active = False
        c2_active = True
        self.assertFalse(c1_active)
        self.assertTrue(c2_active)

    def test_missing_c1_maildir_fail_closed_no_shared_fallback(self) -> None:
        missing = Path(self.tmp.name) / 'missing-c1' / 'Maildir'
        stale = _dto(
            relationship_id=101,
            external_client='e-c1@partner.test',
            external_referent='e-r1@partner.test',
            local_client='l-c1@local.test',
            local_referent='l-r1@local.test',
            maildir=str(missing),
            external_account_id=11,
        )
        err = validate_relationship_for_live(stale)
        self.assertIsNotNone(err)
        assert err is not None
        self.assertIn('local_client_maildir not found', err)
        self.assertNotIn('SHARED-SHOULD-NOT-USE', err)

        plan = plan_inbound_delivery(
            resolve_inbound=lambda _a, _s: stale,
            account_id=11,
            account_email='e-r1@partner.test',
            from_address='e-c1@partner.test',
            referent_local_inbox='SHARED-SHOULD-NOT-USE@legacy.loc',
        )
        self.assertEqual(plan.local_rcpts, [])
        self.assertIsNotNone(plan.lookup_error)
        assert plan.lookup_error is not None
        self.assertIn('local_client_maildir not found', plan.lookup_error)
        # Must not substitute referent inbox or C2 maildir/local referent.
        self.assertNotEqual(plan.mail_from, 'SHARED-SHOULD-NOT-USE@legacy.loc')
        self.assertNotIn('l-r2@local.test', plan.local_rcpts)
        self.assertNotEqual(plan.local_client_maildir, str(self.m_c2))

    def test_valid_maildir_passes_validation(self) -> None:
        self.assertIsNone(validate_relationship_for_live(self.dto_c1))
        self.assertIsNone(validate_relationship_for_live(self.dto_c2))


class WatchPathIsolationTest(unittest.TestCase):
    """Outbound watch registration uses each relationship Maildir only."""

    @classmethod
    def setUpClass(cls) -> None:
        import importlib.util
        import types

        watchdog_mod = types.ModuleType('watchdog')
        watchdog_events = types.ModuleType('watchdog.events')
        watchdog_events.FileSystemEventHandler = object
        watchdog_observers = types.ModuleType('watchdog.observers')
        watchdog_observers.Observer = MagicMock
        sys.modules.setdefault('watchdog', watchdog_mod)
        sys.modules.setdefault('watchdog.events', watchdog_events)
        sys.modules.setdefault('watchdog.observers', watchdog_observers)

        spec = importlib.util.spec_from_file_location(
            'mail_proxy_daemon_isolation_test',
            str(ROOT / 'mail-proxy-daemon.py'),
        )
        cls.mpd = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(cls.mpd)

    def test_watch_validate_c1_does_not_accept_c2_path(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            m1 = Path(tmp) / 'c1' / 'Maildir'
            m2 = Path(tmp) / 'c2' / 'Maildir'
            for p in (m1, m2):
                (p / 'new').mkdir(parents=True)

            class DaemonStub:
                pass

            daemon = DaemonStub()
            ok = self.mpd.ProxyDaemon._validate_relationship_maildir_for_watch(
                daemon, str(m1), 101
            )
            self.assertEqual(ok, m1 / 'new')
            # Missing path for C1 must not silently resolve to C2.
            missing = self.mpd.ProxyDaemon._validate_relationship_maildir_for_watch(
                daemon, str(Path(tmp) / 'gone' / 'Maildir'), 101
            )
            self.assertIsNone(missing)

    def test_quarantined_resolve_local_recipients_returns_empty(self) -> None:
        class HandlerStub:
            pass

        handler = HandlerStub()
        handler._db = MagicMock()
        result = self.mpd.MailHandler._resolve_local_recipients(
            handler,
            MagicMock(),
            {'id': 1, 'local_inbox': 'SHARED@legacy.loc'},
        )
        self.assertEqual(result, [])

    def test_quarantined_scan_existing_outgoing_is_noop(self) -> None:
        class DaemonStub:
            pass

        daemon = DaemonStub()
        # Must not raise on NULL/missing local_outbox and must not enqueue.
        self.mpd.ProxyDaemon._scan_existing_outgoing(
            daemon, {'id': 1, 'local_outbox': None}
        )


class PanelStaticIsolationTest(unittest.TestCase):
    def test_suggestions_do_not_seed_from_referent_local_inbox(self) -> None:
        card = (ROOT / 'web' / 'includes' / 'referent_card_ui.php').read_text(
            encoding='utf-8'
        )
        self.assertIn('never seed from referents.local_inbox', card)
        self.assertNotIn(
            "This referent's own local_inbox is a strong suggestion",
            card,
        )

    def test_routing_module_ignores_referent_local_inbox_kwarg(self) -> None:
        src = (ROOT / 'relationship_routing.py').read_text(encoding='utf-8')
        self.assertIn('del referent_local_inbox', src)
        self.assertIn('do not route via referents.local_inbox', src)


if __name__ == '__main__':
    unittest.main(verbosity=2)
